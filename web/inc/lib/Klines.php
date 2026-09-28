<?php
/**
 * inc/lib/Klines.php — K线数据层 (DB 为主, OKX 拉取为辅, 自动入库)
 * 表: kline_{inst小写'-'→'_'}_{tf}  列: candle_time(ms PK), o,h,l,c,vol
 * 供: 网页K线接口 / 引擎喂DLL内存库 / 回填
 */
class Klines {
    const IVS = ['1m' => 60, '3m' => 180, '5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600, '4h' => 14400, '1H' => 3600, '4H' => 14400];

    /** 从DB读最近 limit 根升序 [[ts_ms,o,h,l,c,vol]...] */
    public static function readRecent(string $inst, string $tf, int $limit = 300): array {
        $t = Db::ktable($inst, $tf);
        $rows = Db::q("SELECT candle_time,o,h,l,c,vol FROM {$t} WHERE c>0 ORDER BY candle_time DESC LIMIT " . (int)$limit);
        $rows = array_reverse($rows);
        return array_map(fn($x) => [(int)$x['candle_time'], (float)$x['o'], (float)$x['h'], (float)$x['l'], (float)$x['c'], (float)$x['vol']], $rows);
    }

    /** OKX 拉 → REPLACE 入库, 返回根数; dir=after取refMs更旧 / before取refMs更新 */
    public static function fetchStore(string $inst, string $tf, int $limit = 300, ?int $refMs = null, bool $history = false, string $dir = 'after'): int {
        $bars = OkxClient::candles($inst, $tf, $limit, $refMs, $history, $dir);
        return self::store($inst, $tf, $bars);
    }

    /** 入库(K线行 [[ts_ms,o,h,l,c,vol,confirm]...]) — 分块批量REPLACE(500根/块) */
    public static function store(string $inst, string $tf, array $bars): int {
        if (!$bars) return 0;
        $t = Db::ktable($inst, $tf);
        $n = 0;
        $chunk = [];
        foreach ($bars as $b) {
            $chunk[] = [(int)$b[0], (float)$b[1], (float)$b[2], (float)$b[3], (float)$b[4], (float)($b[5] ?? 0)];
            if (count($chunk) >= 500) { $n += self::storeChunk($t, $chunk); $chunk = []; }
        }
        if ($chunk) $n += self::storeChunk($t, $chunk);
        return $n;
    }

    private static function storeChunk(string $t, array $chunk): int {
        try {
            $ph = rtrim(str_repeat('(?,?,?,?,?,?),', count($chunk)), ',');
            $p = [];
            foreach ($chunk as $r) $p = array_merge($p, $r);
            Db::ex("REPLACE INTO {$t} (candle_time,o,h,l,c,vol) VALUES {$ph}", $p);
            return count($chunk);
        } catch (\Throwable $e) {
            return 0; // 表不存在等: 由 fillForward/ensureTable 建表后重试
        }
    }

    /** 确保表存在(引擎/回填首次写入前调用) */
    public static function ensureTable(string $inst, string $tf): bool {
        $t = Db::ktable($inst, $tf);
        try {
            Db::ex("CREATE TABLE IF NOT EXISTS {$t} (
                candle_time bigint NOT NULL,
                o double DEFAULT NULL, h double DEFAULT NULL, l double DEFAULT NULL,
                c double DEFAULT NULL, vol double DEFAULT NULL,
                vol_ccy double DEFAULT NULL, vol_quote double DEFAULT NULL,
                confirm tinyint DEFAULT 1,
                PRIMARY KEY (candle_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return true;
        } catch (\Throwable $e) { return false; }
    }

    /** 引擎取数: DB最近300根, 不足/太旧自动从OKX补(喂DLL内存库用); 缺表自动建表拉取 */
    public static function engineRows(string $inst, string $tf): array {
        $t = Db::ktable($inst, $tf);
        $ex = (int)Db::scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='trading' AND table_name=?", [$t]);
        if (!$ex) {
            self::ensureTable($inst, $tf);
            self::fetchStore($inst, $tf, 300);
            return self::readRecent($inst, $tf, 300);
        }
        $rows = self::readRecent($inst, $tf, 300);
        $barMs = (self::IVS[$tf] ?? 300) * 1000;
        $now = (int)(floor(time() * 1000 / $barMs) * $barMs);
        $stale = !$rows || (end($rows)[0] < $now - 3 * $barMs);
        if (count($rows) < 100 || $stale) {
            self::fetchStore($inst, $tf, 300);
            $rows = self::readRecent($inst, $tf, 300);
        }
        return $rows;
    }

    /** 正向补最新(回填主循环): 从 max(candle_time)+bar 逐页补到当前(before=取更新) */
    public static function fillForward(string $inst, string $tf): int {
        $t = Db::ktable($inst, $tf);
        $max = Db::scalar("SELECT MAX(candle_time) FROM {$t}");
        $barMs = (self::IVS[$tf] ?? 300) * 1000;
        $now = (int)(floor(time() * 1000 / $barMs) * $barMs);
        if ($max === null) { // 空表: 从OKX拉300根起步
            self::ensureTable($inst, $tf);
            return self::fetchStore($inst, $tf, 300);
        }
        if ($max >= $now - 2 * $barMs) return 0; // 已追平
        $total = 0;
        $ref = (int)$max;
        while ($ref < $now && $total < 1200) { // 单轮上限1200根, 防限频
            $bars = OkxClient::candles($inst, $tf, 300, $ref, false, 'before');
            if (!$bars) break;
            $total += self::store($inst, $tf, $bars);
            $newRef = end($bars)[0];
            if ($newRef <= $ref) break; // 防倒退死循环
            $ref = $newRef;
            if (count($bars) < 300) break;
            usleep(120000); // OKX 限频保护
        }
        return $total;
    }
}
