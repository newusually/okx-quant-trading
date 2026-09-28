<?php
/**
 * inc/lib/SymbolPool.php — 权威合约池 (数据库表 symbol_pool, 2026-09-28 起所有json不保存)
 * 口径与 Go mvc.Getsymbollist 一致: OKX SWAP 中 *-USDT-SWAP 且 20x 下 1 张初始保证金 < 0.7 USDT
 * 进程内缓存 30 分钟; refresh() 全量重拉并写库(引擎每日/网页手动)
 */
class SymbolPool {
    const TTL = 1800; // 30分钟
    private static ?array $cache = null;
    private static int $at = 0;

    /** 只读缓存(不联网): 引擎每轮扫描用, 绝不阻塞 */
    public static function cached(): array {
        if (self::$cache !== null && time() - self::$at < self::TTL) return self::$cache;
        try {
            $rows = Db::q("SELECT inst_id FROM symbol_pool ORDER BY inst_id");
            if ($rows) {
                self::$cache = array_column($rows, 'inst_id');
                self::$at = time();
                return self::$cache;
            }
        } catch (Throwable $e) { /* 表未建时返回空 */ }
        return [];
    }

    /** 全量刷新: 拉 instruments+tickers 过滤 1张保证金<0.7U@20x, 写入 symbol_pool 表 */
    public static function refresh(): array {
        $j = OkxClient::public('/api/v5/public/instruments?instType=SWAP');
        if ($j['code'] !== '0') return self::cached();
        $ctVal = [];
        foreach ($j['data'] as $d) {
            if (substr($d['instId'], -10) === '-USDT-SWAP') $ctVal[$d['instId']] = (float)$d['ctVal'];
        }
        $list = [];
        foreach (OkxClient::tickers() as $t) {
            $inst = $t['instId'];
            if (!isset($ctVal[$inst])) continue;
            $last = (float)$t['last'];
            if ($last <= 0) continue;
            // 1张初始保证金 = ctVal*last/20, < 0.7U 才买得起
            if ($ctVal[$inst] * $last / LOCK_LEVER < 0.7) $list[] = $inst;
        }
        sort($list);
        if ($list) {
            Db::ex("DELETE FROM symbol_pool");
            foreach ($list as $inst) Db::ex("REPLACE INTO symbol_pool (inst_id,updated_at) VALUES (?,NOW())", [$inst]);
            self::$cache = $list;
            self::$at = time();
        }
        return self::$cache ?? [];
    }

    /** 全量合约(回填用): 从 DB kline 表名反向推导 + 权威池兜底 */
    public static function allInsts(): array {
        // 从 information_schema 反推: kline_btc_usdt_swap_1m → BTC-USDT-SWAP
        static $derived = null;
        if ($derived === null) {
            $rows = Db::q("SELECT DISTINCT table_name FROM information_schema.tables
                           WHERE table_schema='trading' AND table_name LIKE 'kline\\_%'");
            $set = [];
            foreach ($rows as $r) {
                $t = substr($r['table_name'], 6); // 去 kline_
                foreach (['1m','3m','5m','15m','30m','1h','4h'] as $tf) {
                    $sfx = '_' . $tf;
                    if (substr($t, -strlen($sfx)) === $sfx) {
                        $inst = strtoupper(str_replace('_', '-', substr($t, 0, -strlen($sfx))));
                        if (substr($inst, -10) === '-USDT-SWAP') $set[$inst] = true;
                        break;
                    }
                }
            }
            $derived = array_keys($set);
        }
        $pool = self::cached();
        return array_values(array_unique(array_merge($derived, $pool)));
    }
}
