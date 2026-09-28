<?php
/**
 * inc/lib/Klines.php — K线数据层 (DB 为主, OKX 拉取为辅, 自动入库)
 * 职责: K线的 DB 读取 / OKX 拉取入库 / 建表 / 引擎取数 / 正向回填补洞
 * 表: kline_{inst小写'-'→'_'}_{tf}  列: candle_time(ms PK), o,h,l,c,vol
 * 供: 网页K线接口 / 引擎喂DLL内存库 / 回填
 * 方法清单:
 *   readRecent()  — 从 DB 读最近 N 根升序K线
 *   fetchStore()  — 从 OKX 拉取并入库
 *   store()/storeChunk() — 批量 REPLACE 入库(500根/块, 表不存在静默失败)
 *   ensureTable() — 确保K线表存在(IF NOT EXISTS 建表)
 *   engineRows()  — 引擎取数: DB 300根, 不足/过旧自动补; 缺表自愈(建表+拉取)
 *   fillForward() — 正向回填: 从 max(candle_time) 逐页补到当前(before=取更新)
 * 关键坑(必须体现): fillForward 方向用 before=取更新 / after=取更旧,
 *   方向用反会"回填永远不动"(ref 不前进或拉不到新数据)
 * 被谁调用: 交易引擎(engineRows 喂内存库) / 回填任务(fillForward) / 各K线接口页
 */
class Klines {                                       // K线数据层类(纯静态)
    const IVS = ['1m' => 60, '3m' => 180, '5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600, '4h' => 14400, '1H' => 3600, '4H' => 14400]; // 周期名 → 秒数映射(含大小写别名)

    /** 从DB读最近 limit 根升序 [[ts_ms,o,h,l,c,vol]...] */
    public static function readRecent(string $inst, string $tf, int $limit = 300): array { // 读最近N根K线
        $t = Db::ktable($inst, $tf);                 // 由合约+周期得到表名
        $rows = Db::q("SELECT candle_time,o,h,l,c,vol FROM {$t} WHERE c>0 ORDER BY candle_time DESC LIMIT " . (int)$limit); // 倒序取最新N根(过滤无效收盘=0的行)
        $rows = array_reverse($rows);                // 反转 → 升序(旧→新)
        return array_map(fn($x) => [(int)$x['candle_time'], (float)$x['o'], (float)$x['h'], (float)$x['l'], (float)$x['c'], (float)$x['vol']], $rows); // 每行转为数值型六元组
    }

    /** OKX 拉 → REPLACE 入库, 返回根数; dir=after取refMs更旧 / before取refMs更新 */
    public static function fetchStore(string $inst, string $tf, int $limit = 300, ?int $refMs = null, bool $history = false, string $dir = 'after'): int { // 拉取+入库一步到位
        $bars = OkxClient::candles($inst, $tf, $limit, $refMs, $history, $dir); // 经 OkxClient 拉K线(翻页参数透传)
        return self::store($inst, $tf, $bars);       // 入库并返回根数
    }

    /** 入库(K线行 [[ts_ms,o,h,l,c,vol,confirm]...]) — 分块批量REPLACE(500根/块) */
    public static function store(string $inst, string $tf, array $bars): int { // 批量入库入口
        if (!$bars) return 0;                        // 空数据直接返回0
        $t = Db::ktable($inst, $tf);                 // 表名
        $n = 0;                                      // 成功入库计数
        $chunk = [];                                 // 当前块缓冲
        foreach ($bars as $b) {                      // 遍历K线
            $chunk[] = [(int)$b[0], (float)$b[1], (float)$b[2], (float)$b[3], (float)$b[4], (float)($b[5] ?? 0)]; // 裁剪为六元组(整型时间+5浮点)
            if (count($chunk) >= 500) { $n += self::storeChunk($t, $chunk); $chunk = []; } // 满500根立即写库并清空缓冲
        }
        if ($chunk) $n += self::storeChunk($t, $chunk); // 写入剩余不足500根的尾块
        return $n;                                   // 返回总入库根数
    }

    private static function storeChunk(string $t, array $chunk): int { // 私有: 写入单个块
        try {                                        // 表不存在等错误需捕获(不中断调用方)
            $ph = rtrim(str_repeat('(?,?,?,?,?,?),', count($chunk)), ','); // 按 根数×6 生成 VALUES 占位符串
            $p = [];                                 // 一维参数数组
            foreach ($chunk as $r) $p = array_merge($p, $r); // 二维行摊平为一维(与占位符顺序一致)
            Db::ex("REPLACE INTO {$t} (candle_time,o,h,l,c,vol) VALUES {$ph}", $p); // REPLACE 批量插入(同时间戳覆盖, 天然幂等)
            return count($chunk);                    // 成功: 返回块内根数
        } catch (\Throwable $e) {
            return 0; // 表不存在等: 由 fillForward/ensureTable 建表后重试
        }
    }

    /** 确保表存在(引擎/回填首次写入前调用) */
    public static function ensureTable(string $inst, string $tf): bool { // 建表(若不存在)
        $t = Db::ktable($inst, $tf);                 // 表名
        try {                                        // 建表可能因权限等问题失败
            Db::ex("CREATE TABLE IF NOT EXISTS {$t} (  // 幂等建表语句
                candle_time bigint NOT NULL,           // K线起始毫秒时间戳(主键)
                o double DEFAULT NULL, h double DEFAULT NULL, l double DEFAULT NULL, // 开高低价
                c double DEFAULT NULL, vol double DEFAULT NULL,                       // 收盘价与成交量
                vol_ccy double DEFAULT NULL, vol_quote double DEFAULT NULL,           // 币量/计价量(预留列)
                confirm tinyint DEFAULT 1,             // K线是否收线确认(1=已确认)
                PRIMARY KEY (candle_time)              // 时间戳唯一, REPLACE 依赖此键
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); // InnoDB 引擎 + utf8mb4
            return true;                               // 建表成功
        } catch (\Throwable $e) { return false; }      // 失败: 返回 false 不抛出
    }

    /** 引擎取数: DB最近300根, 不足/太旧自动从OKX补(喂DLL内存库用); 缺表自动建表拉取 */
    public static function engineRows(string $inst, string $tf): array { // 引擎专用取数(含自愈)
        $t = Db::ktable($inst, $tf);                 // 表名
        $ex = (int)Db::scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='trading' AND table_name=?", [$t]); // 查 information_schema 判断表是否存在
        if (!$ex) {                                  // 表不存在(缺表自愈路径)
            self::ensureTable($inst, $tf);           // 先建表
            self::fetchStore($inst, $tf, 300);       // 再从 OKX 拉300根
            return self::readRecent($inst, $tf, 300); // 返回刚入库的300根
        }
        $rows = self::readRecent($inst, $tf, 300);   // 表存在: 正常读300根
        $barMs = (self::IVS[$tf] ?? 300) * 1000;     // 周期毫秒数(未知周期兜底5分钟)
        $now = (int)(floor(time() * 1000 / $barMs) * $barMs); // 当前K线桶起始时间(向下取整对齐)
        $stale = !$rows || (end($rows)[0] < $now - 3 * $barMs); // 判定陈旧: 无数据 或 最新一根落后3个周期以上
        if (count($rows) < 100 || $stale) {          // 根数不足100 或 数据陈旧
            self::fetchStore($inst, $tf, 300);       // 从 OKX 拉新补齐入库
            $rows = self::readRecent($inst, $tf, 300); // 重读返回
        }
        return $rows;                                // 返回(可能已补齐的)300根
    }

    /** 正向补最新(回填主循环): 从 max(candle_time)+bar 逐页补到当前(before=取更新) */
    public static function fillForward(string $inst, string $tf): int { // 正向回填补洞
        $t = Db::ktable($inst, $tf);                 // 表名
        $max = Db::scalar("SELECT MAX(candle_time) FROM {$t}"); // 取表中最新时间戳
        $barMs = (self::IVS[$tf] ?? 300) * 1000;     // 周期毫秒数
        $now = (int)(floor(time() * 1000 / $barMs) * $barMs);   // 当前桶起始时间
        if ($max === null) { // 空表: 从OKX拉300根起步
            self::ensureTable($inst, $tf);           // 先建表
            return self::fetchStore($inst, $tf, 300); // 拉300根起步数据
        }
        if ($max >= $now - 2 * $barMs) return 0; // 已追平(落后不足2个周期, 无需回填)
        $total = 0;                                  // 本次回填总根数
        $ref = (int)$max;                            // 翻页参考锚点: 从最新时间戳开始
        while ($ref < $now && $total < 1200) { // 单轮上限1200根, 防限频
            $bars = OkxClient::candles($inst, $tf, 300, $ref, false, 'before'); // before=取 ref 之后(更新)的K线 — 方向用反会永远拉旧数据
            if (!$bars) break;                       // 拉空: 到顶了, 结束
            $total += self::store($inst, $tf, $bars); // 入库并累计根数
            $newRef = end($bars)[0];                 // 新锚点: 本批最新一根的时间戳
            if ($newRef <= $ref) break; // 防倒退死循环(锚点没前进就停)
            $ref = $newRef;                          // 前进锚点
            if (count($bars) < 300) break;           // 返回不足一整页: 说明已拉到最新
            usleep(120000); // OKX 限频保护(每页间隔120ms)
        }
        return $total;                               // 返回总回填根数
    }
}
