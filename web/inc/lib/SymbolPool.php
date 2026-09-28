<?php
/**
 * inc/lib/SymbolPool.php — 权威合约池 (数据库表 symbol_pool, 2026-09-28 起所有json不保存)
 * 职责: 维护"买得起"的 USDT 永续合约权威池, 供引擎扫描与回填取全量合约
 * 口径与 Go mvc.Getsymbollist 一致: OKX SWAP 中 *-USDT-SWAP 且 20x 下 1 张初始保证金 < 0.7 USDT
 * 进程内缓存 30 分钟; refresh() 全量重拉并写库(引擎每日/网页手动)
 * 方法清单:
 *   cached()  — 只读缓存(优先读内存→DB symbol_pool 表, 绝不联网)
 *   refresh() — 全量刷新: 拉 OKX instruments+tickers 过滤后 DELETE+REPLACE 写库
 *   allInsts() — 全量合约(information_schema 反推 kline 表名 + 权威池兜底), 回填用
 * 被谁调用: 交易引擎扫描循环(cached) / 回填任务(allInsts) / 设置页手动刷新(refresh)
 */
class SymbolPool {                                   // 合约池管理类(纯静态)
    const TTL = 1800; // 30分钟
    private static ?array $cache = null;             // 内存缓存: 当前合约 ID 列表
    private static int $at = 0;                      // 缓存写入时间戳(用于 TTL 判定)

    /** 只读缓存(不联网): 引擎每轮扫描用, 绝不阻塞 */
    public static function cached(): array {         // 获取当前权威池(不访问 OKX)
        if (self::$cache !== null && time() - self::$at < self::TTL) return self::$cache; // 内存缓存未过期: 直接返回
        try {                                        // DB 可能表未建/连接断
            $rows = Db::q("SELECT inst_id FROM symbol_pool ORDER BY inst_id"); // 从权威表读全部合约 ID(按名称排序)
            if ($rows) {                             // 表中有数据
                self::$cache = array_column($rows, 'inst_id'); // 提取为一维数组写入缓存
                self::$at = time();                  // 记录缓存时间
                return self::$cache;                 // 返回缓存
            }
        } catch (Throwable $e) { /* 表未建时返回空 */ } // 异常静默, 返回空数组兜底
        return [];                                   // 无数据/异常: 返回空池
    }

    /** 全量刷新: 拉 instruments+tickers 过滤 1张保证金<0.7U@20x, 写入 symbol_pool 表 */
    public static function refresh(): array {        // 全量刷新合约池(联网)
        $j = OkxClient::public('/api/v5/public/instruments?instType=SWAP'); // 拉全部永续合约定义
        if ($j['code'] !== '0') return self::cached(); // 拉取失败: 回退只读缓存
        $ctVal = [];                                 // instId → ctVal(每张面值)映射
        foreach ($j['data'] as $d) {                 // 遍历合约定义
            if (substr($d['instId'], -10) === '-USDT-SWAP') $ctVal[$d['instId']] = (float)$d['ctVal']; // 只要 USDT 本位永续, 记录面值
        }
        $list = [];                                  // 通过筛选的合约列表
        foreach (OkxClient::tickers() as $t) {       // 遍历全市场实时 ticker
            $inst = $t['instId'];                    // 合约 ID
            if (!isset($ctVal[$inst])) continue;     // 不在 USDT 永续集合内: 跳过
            $last = (float)$t['last'];               // 最新价
            if ($last <= 0) continue;                // 无效价格: 跳过
            // 1张初始保证金 = ctVal*last/20, < 0.7U 才买得起
            if ($ctVal[$inst] * $last / LOCK_LEVER < 0.7) $list[] = $inst; // 20x 下一张保证金 < 0.7U 才准入
        }
        sort($list);                                 // 按名称排序(保证写库顺序稳定)
        if ($list) {                                 // 筛出非空才写库(防清空)
            Db::ex("DELETE FROM symbol_pool");       // 清空旧池
            foreach ($list as $inst) Db::ex("REPLACE INTO symbol_pool (inst_id,updated_at) VALUES (?,NOW())", [$inst]); // 逐条写入新池(REPLACE 防重复)
            self::$cache = $list;                    // 更新内存缓存
            self::$at = time();                      // 刷新缓存时间
        }
        return self::$cache ?? [];                   // 返回当前池(异常时兜底空数组)
    }

    /** 全量合约(回填用): 从 DB kline 表名反向推导 + 权威池兜底 */
    public static function allInsts(): array {       // 取系统见过的全部合约(不止权威池)
        // 从 information_schema 反推: kline_btc_usdt_swap_1m → BTC-USDT-SWAP
        static $derived = null;                      // 进程内静态缓存(每次请求只反推一次)
        if ($derived === null) {                     // 尚未反推过
            $rows = Db::q("SELECT DISTINCT table_name FROM information_schema.tables
                           WHERE table_schema='trading' AND table_name LIKE 'kline\\_%'"); // 查 trading 库全部 kline_ 开头的表名
            $set = [];                               // 合约去重集合
            foreach ($rows as $r) {                  // 遍历表名
                $t = substr($r['table_name'], 6); // 去 kline_
                foreach (['1m','3m','5m','15m','30m','1h','4h'] as $tf) { // 逐个尝试已知周期后缀
                    $sfx = '_' . $tf;                // 周期后缀
                    if (substr($t, -strlen($sfx)) === $sfx) { // 表名以该后缀结尾
                        $inst = strtoupper(str_replace('_', '-', substr($t, 0, -strlen($sfx)))); // 去后缀后还原合约名: 小写下划线→大写横杠
                        if (substr($inst, -10) === '-USDT-SWAP') $set[$inst] = true; // 只要 USDT 永续, 写入去重集合
                        break;                       // 命中一个周期即可, 停止尝试
                    }
                }
            }
            $derived = array_keys($set);             // 缓存反推结果(合约名数组)
        }
        $pool = self::cached();                      // 取权威池兜底
        return array_values(array_unique(array_merge($derived, $pool))); // 两来源合并去重后返回
    }
}
