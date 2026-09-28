<?php
/**
 * eth_fill_year.php — 从OKX补齐最近一年历史K线 (CLI)
 * ① ETH/BTC 1h + ETH 4h → 现有 kline_* 表  ② 全市场476合约 1D 收盘 → 新表 kline1d
 */
ini_set('memory_limit', '512M');
set_time_limit(0);
$START = (int)((time() - 365 * 86400) * 1000);

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
$DB->query("CREATE TABLE IF NOT EXISTS kline1d (inst_id VARCHAR(64) NOT NULL, candle_time BIGINT NOT NULL, c DOUBLE NOT NULL, PRIMARY KEY(inst_id, candle_time)) ENGINE=InnoDB");

function okx($url) {
    for ($try = 0; $try < 3; $try++) {
        $j = json_decode(@file_get_contents($url), true);
        if ($j && ($j['code'] ?? '1') === '0') return $j['data'];
        usleep(300000);
    }
    return [];
}
function upsert_k($tb, $rows, $start) {
    global $DB;
    $vals = []; $n = 0;
    foreach ($rows as $r) {
        $ts = (int)$r[0];
        if ($ts < $start) continue;
        $vals[] = "($ts," . (float)$r[1] . "," . (float)$r[2] . "," . (float)$r[3] . "," . (float)$r[4] . "," . (float)$r[5] . "," . (float)$r[6] . "," . (float)$r[7] . "," . (int)$r[8] . ")";
        $n++;
    }
    if (!$vals) return 0;
    $sql = "INSERT INTO `$tb` (candle_time,o,h,l,c,vol,vol_ccy,vol_quote,confirm) VALUES " . implode(',', $vals) .
           " ON DUPLICATE KEY UPDATE o=VALUES(o),h=VALUES(h),l=VALUES(l),c=VALUES(c),vol=VALUES(vol),confirm=VALUES(confirm)";
    $DB->query($sql);
    return $n;
}
function fetch_bar($instId, $bar, $start, $tb, $label) {
    $after = ''; $total = 0; $reqs = 0; $oldest = PHP_INT_MAX;
    while (true) {
        $url = "https://www.okx.com/api/v5/market/history-candles?instId=$instId&bar=$bar&limit=100$after";
        $data = okx($url);
        $reqs++;
        if (!$data) break;
        $oldest = PHP_INT_MAX;
        foreach ($data as $r) $oldest = min($oldest, (int)$r[0]);
        $total += upsert_k($tb, $data, $start);
        if ($oldest <= $start || count($data) < 100) break;
        $after = '&after=' . $oldest;
        usleep(120000);
        if ($reqs > 200) break;
    }
    echo "  $label [$instId $bar] req=$reqs ins=$total\n"; flush();
    return $total;
}

echo "== ① ETH/BTC 1h + ETH 4h (一年) ==\n"; flush();
fetch_bar('ETH-USDT-SWAP', '1H', $START, 'kline_eth_usdt_swap_1h', 'main');
fetch_bar('BTC-USDT-SWAP', '1H', $START, 'kline_btc_usdt_swap_1h', 'main');
fetch_bar('ETH-USDT-SWAP', '4H', $START, 'kline_eth_usdt_swap_4h', 'main');

echo "== ② 全市场 1D → kline1d ==\n"; flush();
$tabs = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline%_1h'");
$insts = [];
foreach ($tabs as $t) {
    if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $t[0], $m)) $insts[] = $m[1] . '-USDT-SWAP';
}
echo "insts=" . count($insts) . "\n"; flush();
$i = 0;
foreach ($insts as $inst) {
    $after = ''; $total = 0;
    while (true) {
        $url = "https://www.okx.com/api/v5/market/history-candles?instId=$inst&bar=1D&limit=100$after";
        $data = okx($url);
        if (!$data) break;
        $oldest = PHP_INT_MAX;
        foreach ($data as $r) $oldest = min($oldest, (int)$r[0]);
        $vals = [];
        foreach ($data as $r) {
            $ts = (int)$r[0];
            if ($ts < $START) continue;
            $vals[] = "('" . $DB->real_escape_string($inst) . "',$ts," . (float)$r[4] . ")";
        }
        if ($vals) {
            $sql = "INSERT INTO kline1d (inst_id,candle_time,c) VALUES " . implode(',', $vals) . " ON DUPLICATE KEY UPDATE c=VALUES(c)";
            $DB->query($sql);
            $total += count($vals);
        }
        if ($oldest <= $START || count($data) < 100) break;
        $after = '&after=' . $oldest;
        usleep(120000);
        if ($total > 5000) break;
    }
    if ((++$i % 60) == 0) { echo "  1D $i/" . count($insts) . "\n"; flush(); }
}
echo "FILL OK\n";
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
