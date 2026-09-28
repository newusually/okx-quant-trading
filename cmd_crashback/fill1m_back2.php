<?php
/**
 * fill1m_back2.php — 反向补充进程: 从 2024-09-25 目标端用 before 参数向 newer 方向拉,
 * 与 fill1m_back.php(向 older 拉) 两头对拼, 在 2025-03-01 22:08 附近会合。INSERT IGNORE 幂等。
 */
ini_set('memory_limit', '512M');
set_time_limit(0);
$STOP_TS = 1740835680000; // 2025-03-01 22:08 (UTC+8) — 主进程已覆盖到此, 追上即停
$before = 1747215680000;  // 2024-09-25 目标起点略后
$m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4');
$stmt = $m->prepare("INSERT IGNORE INTO kline_eth_usdt_swap_1m (candle_time,`o`,`h`,`l`,`c`,vol,vol_ccy,vol_quote,confirm) VALUES (?,?,?,?,?,?,?,?,1)");
$req = 0; $ins = 0;
while (true) {
    $url = "https://www.okx.com/api/v5/market/history-candles?instId=ETH-USDT-SWAP&bar=1m&before=$before&limit=100";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $body = curl_exec($ch); curl_close($ch);
    if ($body === false) { sleep(2); continue; }
    $j = json_decode($body, true);
    if (($j['code'] ?? '1') !== '0' || empty($j['data'])) { echo "stop: code=" . ($j['code'] ?? '-') . " req=$req ins=$ins\n"; break; }
    $batchMax = 0;
    foreach ($j['data'] as $row) {
        $ts = (int)$row[0]; if ($ts > $batchMax) $batchMax = $ts;
        if ($ts >= $STOP_TS) continue;
        $o=(float)$row[1]; $h=(float)$row[2]; $l=(float)$row[3]; $c=(float)$row[4];
        $v=(float)$row[5]; $vc=(float)$row[6]; $vq=(float)$row[7];
        $stmt->bind_param('iddddddd', $ts, $o, $h, $l, $c, $v, $vc, $vq); $stmt->execute(); $ins += $stmt->affected_rows;
    }
    $before = $batchMax; $req++;
    if ($req % 200 === 0) echo "req=$req ins=$ins at=" . gmdate('Y-m-d H:i', $before / 1000 + 8 * 3600) . "\n";
    if ($before >= $STOP_TS) { echo "met main stream\n"; break; }
    usleep(110000);
}
$stmt->close();
echo "done req=$req ins=$ins\n";
