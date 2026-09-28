<?php
/**
 * nq_live.php — 纳指实时数据端点 (nqall.php 每15秒轮询)
 * 只实时获取 1m: 拉 Dukascopy jetta API 实时1mK线 → REPLACE 入库 kline_nq_1m
 * (其他周期 3m/5m/15m/1h 不碰远端, 由 nq_agg.php 从 1m 本地聚合)
 * 返回: {ok, price, ts, candles:[[ts_s,o,h,l,c],...x20]}
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('default_socket_timeout', 15);

$CODE = 'USATECH.IDX-USD';
$FROM = (time() - 1800) * 1000;
$URL  = "https://jetta.dukascopy.com/v1/candles/minute/{$CODE}/BID?from={$FROM}";

function jfetch($url) {
    $ctx = stream_context_create(['http' => ['timeout' => 12, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
    $d = @file_get_contents($url, false, $ctx);
    if ($d === false) return null;
    return json_decode($d, true);
}

function decode($d) {
    if (!$d || empty($d['times']) || !isset($d['multiplier'])) return [];
    $mult = $d['multiplier']; $shift = $d['shift']; $ts = $d['timestamp'];
    $ou = round($d['open'] / $mult); $hu = round($d['high'] / $mult);
    $lu = round($d['low'] / $mult);  $cu = round($d['close'] / $mult);
    $out = [];
    $n = count($d['times']);
    for ($i = 0; $i < $n; $i++) {
        $ts += $d['times'][$i] * $shift;
        $ou += $d['opens'][$i]; $hu += $d['highs'][$i]; $lu += $d['lows'][$i]; $cu += $d['closes'][$i];
        if ($ou > 0 && $cu > 0) $out[] = [$ts, $ou * $mult, $hu * $mult, $lu * $mult, $cu * $mult, (float)$d['volumes'][$i]];
    }
    return $out;
}

$resp = ['ok' => 0, 'price' => null, 'ts' => null, 'candles' => []];
$data = jfetch($URL);
if ($data === null) { echo json_encode($resp); exit; }
$rows = decode($data);

$m = new mysqli('127.0.0.1', 'root', '', 'trading');
$m->set_charset('utf8mb4');

if ($rows) {
    $st = $m->prepare("REPLACE INTO kline_nq_1m (candle_time,o,h,l,c,vol) VALUES (?,?,?,?,?,?)");
    foreach ($rows as $r) {
        $tms = $r[0]; $o = $r[1]; $h = $r[2]; $l = $r[3]; $c = $r[4]; $v = $r[5];
        $st->bind_param('iddddi', $tms, $o, $h, $l, $c, $v);
        $st->execute();
    }
    $st->close();

    $last = end($rows);
    $resp['price'] = round($last[4], 2);
    $resp['ts'] = (int)($last[0] / 1000);
    $tail = array_slice($rows, -20);
    foreach ($tail as $r) $resp['candles'][] = [(int)($r[0] / 1000), round($r[1], 2), round($r[2], 2), round($r[3], 2), round($r[4], 2)];
    $resp['ok'] = 1;
} else {
    // 实时接口失败: 回退DB最新
    $q = $m->query("SELECT candle_time,o,h,l,c FROM kline_nq_1m ORDER BY candle_time DESC LIMIT 20");
    if ($q && $q->num_rows) {
        $rr = []; while ($x = $q->fetch_row()) $rr[] = $x;
        $rr = array_reverse($rr);
        $resp['price'] = round((float)$rr[count($rr)-1][4], 2);
        $resp['ts'] = (int)($rr[count($rr)-1][0] / 1000);
        foreach ($rr as $x) $resp['candles'][] = [(int)($x[0] / 1000), round((float)$x[1], 2), round((float)$x[2], 2), round((float)$x[3], 2), round((float)$x[4], 2)];
        $resp['ok'] = 2;
    }
}
$m->close();
echo json_encode($resp);
