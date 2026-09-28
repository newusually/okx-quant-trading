<?php
/**
 * fill_seg2.php — OKX历史K线分段回填v2: php fill_seg2.php <bar> <after> <stop> [after stop ...]
 * 多段顺序执行; 限速/网络错误无限重试(30分钟无进展才放弃); 自写日志 seg_{bar}.log
 */
ini_set('memory_limit', '512M');
set_time_limit(0);
$bar = $argv[1];
$log = __DIR__ . "/seg_$bar.log";
function L($bar, $s) { file_put_contents(__DIR__ . "/seg_{$bar}.log", date('H:i:s ') . $s . "\n", FILE_APPEND); }
$m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4');
$stmt = $m->prepare("INSERT IGNORE INTO kline_eth_usdt_swap_$bar (candle_time,`o`,`h`,`l`,`c`,vol,vol_ccy,vol_quote,confirm) VALUES (?,?,?,?,?,?,?,?,1)");
L($bar, "start args: " . implode(' ', array_slice($argv, 2)));
for ($a = 2; $a < $argc; $a += 2) {
    $after = (int)$argv[$a]; $stop = (int)$argv[$a + 1];
    L($bar, "=== segment: " . gmdate('Y-m-d H:i', $after / 1000 + 8 * 3600) . " -> " . gmdate('Y-m-d H:i', $stop / 1000 + 8 * 3600));
    $req = 0; $ins = 0; $lastProgress = time();
    while (true) {
        $url = "https://www.okx.com/api/v5/market/history-candles?instId=ETH-USDT-SWAP&bar=" . preg_replace('/^(\d+)h$/', '${1}H', $bar) . "&after=$after&limit=100";
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $body = curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
        $j = $body !== false ? json_decode($body, true) : null;
        if ($j === null || ($j['code'] ?? '1') !== '0') {
            if (time() - $lastProgress > 1800) { L($bar, "ABORT: no progress 30min. last=$body"); break; }
            usleep(2000000); continue;   // 限速/网络错误: 无限重试
        }
        $fail = 0;
        if (empty($j['data'])) { L($bar, "no data returned. req=$req ins=$ins"); break; }
        $batchMin = PHP_INT_MAX;
        foreach ($j['data'] as $row) {
            $ts = (int)$row[0]; if ($ts < $batchMin) $batchMin = $ts;
            if ($ts <= $stop) continue;
            $o=(float)$row[1]; $h=(float)$row[2]; $l=(float)$row[3]; $c=(float)$row[4];
            $v=(float)$row[5]; $vc=(float)$row[6]; $vq=(float)$row[7];
            $stmt->bind_param('iddddddd', $ts, $o, $h, $l, $c, $v, $vc, $vq); $stmt->execute(); $ins += $stmt->affected_rows;
        }
        $after = $batchMin; $req++; $lastProgress = time();
        if ($req % 500 === 0) L($bar, "req=$req ins=$ins at=" . gmdate('Y-m-d H:i', $after / 1000 + 8 * 3600));
        if ($after <= $stop) { L($bar, "segment done. req=$req ins=$ins"); break; }
        usleep(110000);
    }
}
$stmt->close();
L($bar, "ALL DONE");
