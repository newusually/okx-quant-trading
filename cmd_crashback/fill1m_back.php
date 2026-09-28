<?php
/**
 * fill1m_back.php — 从OKX补 ETH-USDT-SWAP 1m 更早历史K线 (当前最早 2025-09-26 06:08 → 目标再往前366天)
 * 接口: /api/v5/market/history-candles (100根/次, 限速20次/2s) · INSERT IGNORE 幂等
 */
ini_set('memory_limit', '1024M');
set_time_limit(0);
$TARGET = null; // 运行时按当前最早时间-366天
$m = new mysqli('127.0.0.1', 'root', '', 'trading');
$m->set_charset('utf8mb4');
$r = $m->query("SELECT MIN(candle_time) FROM kline_eth_usdt_swap_1m");
$min = (int)$r->fetch_row()[0];
$TARGET = $min - 366 * 86400 * 1000;
echo "current_min=$min (" . gmdate('Y-m-d H:i', $min / 1000 + 8 * 3600) . ") target=" . gmdate('Y-m-d H:i', $TARGET / 1000 + 8 * 3600) . "\n";
$after = $min; $req = 0; $ins = 0; $buf = []; $stmt = $m->prepare("INSERT IGNORE INTO kline_eth_usdt_swap_1m (candle_time,`o`,`h`,`l`,`c`,vol,vol_ccy,vol_quote,confirm) VALUES (?,?,?,?,?,?,?,?,1)");
while (true) {
    $url = "https://www.okx.com/api/v5/market/history-candles?instId=ETH-USDT-SWAP&bar=1m&after=$after&limit=100";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true]);
    $body = curl_exec($ch); curl_close($ch);
    if ($body === false) { echo "curl fail, retry\n"; sleep(2); continue; }
    $j = json_decode($body, true);
    if (($j['code'] ?? '1') !== '0' || empty($j['data'])) { echo "stop: code=" . ($j['code'] ?? '-') . " msg=" . ($j['msg'] ?? 'empty') . " req=$req ins=$ins\n"; break; }
    $batchMin = PHP_INT_MAX;
    foreach ($j['data'] as $row) {
        $ts = (int)$row[0]; if ($ts < $batchMin) $batchMin = $ts;
        if ($ts < $TARGET) continue;
        $o = (float)$row[1]; $h = (float)$row[2]; $l = (float)$row[3]; $c = (float)$row[4];
        $v = (float)$row[5]; $vc = (float)$row[6]; $vq = (float)$row[7];
        $stmt->bind_param('iddddddd', $ts, $o, $h, $l, $c, $v, $vc, $vq); $stmt->execute(); $ins += $stmt->affected_rows;
    }
    $after = $batchMin; $req++;
    if ($req % 200 === 0) echo "req=$req ins=$ins at=" . gmdate('Y-m-d H:i', $after / 1000 + 8 * 3600) . "\n";
    if ($after <= $TARGET) { echo "reached target\n"; break; }
    usleep(110000); // ~9 req/s, 限速20/2s
}
$stmt->close();
$r = $m->query("SELECT MIN(candle_time), MAX(candle_time), COUNT(*) FROM kline_eth_usdt_swap_1m");
$x = $r->fetch_row();
echo "final: min=" . gmdate('Y-m-d H:i', $x[0] / 1000 + 8 * 3600) . " max=" . gmdate('Y-m-d H:i', $x[1] / 1000 + 8 * 3600) . " count=$x[2]\n";
