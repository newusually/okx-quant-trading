<?php
/**
 * eth_fill35.php — 补全 ETH-USDT-SWAP 3m/5m/15m 一年历史K线(OKX history-candles, curl复用连接)
 * 用法: php eth_fill35.php [3m|5m|15m|all]
 */
set_time_limit(0);
$ONLY = $argv[1] ?? 'all';
function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
$ch = curl_init();
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['Connection: keep-alive']]);

$yearAgo = (time() - 366 * 86400) * 1000;
$bars = $ONLY === 'all' ? ['3m' => 180000, '5m' => 300000, '15m' => 900000] : [$ONLY => ['3m' => 180000, '5m' => 300000, '15m' => 900000][$ONLY]];
foreach ($bars as $bar => $ms) {
    $tbl = "kline_eth_usdt_swap_$bar";
    $r = $DB->query("SELECT MIN(candle_time) FROM $tbl");
    $min = (int)($r->fetch_row()[0] ?? 0);
    if ($min <= $yearAgo + $ms) { echo "$bar already full (min=$min)\n"; continue; }
    $after = $min; $ins = 0; $req = 0; $err = 0; $t0 = time();
    echo "$bar filling back to " . date('Y-m-d', (int)($yearAgo / 1000)) . "\n"; flush();
    while ($after > $yearAgo) {
        curl_setopt($ch, CURLOPT_URL, "https://www.okx.com/api/v5/market/history-candles?instId=ETH-USDT-SWAP&bar=$bar&limit=100&after=$after");
        $j = json_decode(curl_exec($ch), true);
        $req++;
        if (!$j || ($j['code'] ?? '1') !== '0' || empty($j['data'])) {
            usleep(500000); if (++$err > 8) { echo "$bar ERR break at " . date('Y-m-d H:i', (int)($after / 1000)) . " msg=" . ($j['msg'] ?? 'null') . "\n"; break; }
            continue;
        }
        $err = 0;
        $stmt = $DB->prepare("INSERT IGNORE INTO $tbl (candle_time,`o`,`h`,`l`,`c`,vol,vol_ccy,vol_quote,confirm) VALUES (?,?,?,?,?,?,?,?,?)");
        $earliest = PHP_INT_MAX;
        foreach ($j['data'] as $k) {
            $t = (int)$k[0];
            if ($t < $earliest) $earliest = $t;
            $o1 = (float)$k[1]; $h1 = (float)$k[2]; $l1 = (float)$k[3]; $c1 = (float)$k[4];
            $v1 = (float)$k[5]; $v2 = (float)$k[6]; $v3 = (float)$k[7]; $cf = (int)$k[8];
            $stmt->bind_param('idddddddi', $t, $o1, $h1, $l1, $c1, $v1, $v2, $v3, $cf);
            $stmt->execute(); $ins += $stmt->affected_rows;
        }
        $stmt->close();
        $after = $earliest;
        if ($req % 100 == 0) { echo "  $bar req=$req ins=$ins at " . date('Y-m-d H:i', (int)($after / 1000)) . " (" . (time() - $t0) . "s)\n"; flush(); }
        usleep(120000);
    }
    $r2 = $DB->query("SELECT COUNT(*), FROM_UNIXTIME(MIN(candle_time)/1000,'%Y-%m-%d') FROM $tbl");
    [$cnt, $mn] = $r2->fetch_row();
    echo "$bar DONE ins=$ins req=$req total=$cnt min=$mn (" . (time() - $t0) . "s)\n"; flush();
}
echo "ALL DONE\n";
