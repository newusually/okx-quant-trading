<?php
/**
 * fill_seg.php — 通用OKX历史K线分段回填: php fill_seg.php <表名后缀> <bar> <after_ms> <stop_ms>
 * 从 after 时间向更老翻页, 直到 <= stop 或接口无数据。INSERT IGNORE 幂等, 429/限速自动重试。
 */
ini_set('memory_limit', '512M');
set_time_limit(0);
[$tf, $bar, $after, $stop] = [$argv[1], $argv[2], (int)$argv[3], (int)$argv[4]];
$m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4');
$stmt = $m->prepare("INSERT IGNORE INTO kline_eth_usdt_swap_$tf (candle_time,`o`,`h`,`l`,`c`,vol,vol_ccy,vol_quote,confirm) VALUES (?,?,?,?,?,?,?,?,1)");
$req = 0; $ins = 0; $fail = 0;
echo "[$tf] start after=" . gmdate('Y-m-d H:i', $after / 1000 + 8 * 3600) . " stop=" . gmdate('Y-m-d H:i', $stop / 1000 + 8 * 3600) . "\n";
while (true) {
    $url = "https://www.okx.com/api/v5/market/history-candles?instId=ETH-USDT-SWAP&bar=$bar&after=$after&limit=100";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $body = curl_exec($ch); curl_close($ch);
    if ($body === false) { if (++$fail > 30) { echo "[$tf] curl dead\n"; break; } usleep(500000); continue; }
    $j = json_decode($body, true);
    if (($j['code'] ?? '1') !== '0') { // 限速等重试
        if (++$fail > 30) { echo "[$tf] stop: code={$j['code']} msg={$j['msg']}\n"; break; }
        usleep(800000); continue;
    }
    $fail = 0;
    if (empty($j['data'])) { echo "[$tf] no data. req=$req ins=$ins\n"; break; }
    $batchMin = PHP_INT_MAX;
    foreach ($j['data'] as $row) {
        $ts = (int)$row[0]; if ($ts < $batchMin) $batchMin = $ts;
        if ($ts <= $stop) continue;
        $o=(float)$row[1]; $h=(float)$row[2]; $l=(float)$row[3]; $c=(float)$row[4];
        $v=(float)$row[5]; $vc=(float)$row[6]; $vq=(float)$row[7];
        $stmt->bind_param('iddddddd', $ts, $o, $h, $l, $c, $v, $vc, $vq); $stmt->execute(); $ins += $stmt->affected_rows;
    }
    $after = $batchMin; $req++;
    if ($req % 200 === 0) echo "[$tf] req=$req ins=$ins at=" . gmdate('Y-m-d H:i', $after / 1000 + 8 * 3600) . "\n";
    if ($after <= $stop) { echo "[$tf] reached stop. req=$req ins=$ins\n"; break; }
    usleep(110000);
}
$stmt->close();
echo "[$tf] DONE req=$req ins=$ins\n";
