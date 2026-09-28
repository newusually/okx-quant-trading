<?php
/**
 * nq_agg.php — 从 kline_nq_1m 本地聚合 3m/5m/15m/1h (不访问外网)
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$m = new mysqli('127.0.0.1', 'root', '', 'trading');
$m->set_charset('utf8mb4');

foreach (['3m' => 180, '5m' => 300, '15m' => 900, '1h' => 3600] as $tf => $sec) {
    $t0 = microtime(true);
    $m->query("DELETE FROM kline_nq_$tf");
    $ms = $sec * 1000;
    $r = $m->query("SELECT candle_time,o,h,l,c,vol FROM kline_nq_1m ORDER BY candle_time ASC");
    $buf = null; $out = []; $cnt = 0;
    $st = $m->prepare("REPLACE INTO kline_nq_$tf (candle_time,o,h,l,c,vol) VALUES (?,?,?,?,?,?)");
    $flush = function () use (&$out, &$cnt, $st, &$buf) {
        if ($buf !== null) { $out[] = $buf; $buf = null; }
        foreach ($out as $x) {
            $tms = $x[0]; $o = $x[1]; $h = $x[2]; $l = $x[3]; $c = $x[4]; $v = $x[5];
            $st->bind_param('iddddi', $tms, $o, $h, $l, $c, $v);
            $st->execute();
        }
        $cnt += count($out); $out = [];
    };
    while ($x = $r->fetch_row()) {
        $tms = (int)$x[0]; $o = (float)$x[1]; $h = (float)$x[2]; $l = (float)$x[3]; $c = (float)$x[4]; $v = (float)$x[5];
        if ($o <= 0) continue;
        $b = intdiv($tms, $ms) * $ms;
        if ($buf !== null && $buf[0] !== $b) {
            $out[] = $buf; $buf = null;
            if (count($out) >= 5000) $flush();
        }
        if ($buf === null) $buf = [$b, $o, $h, $l, $c, $v];
        else {
            if ($h > $buf[2]) $buf[2] = $h;
            if ($l < $buf[3]) $buf[3] = $l;
            $buf[4] = $c; $buf[5] += $v;
        }
    }
    $flush();
    $st->close(); $r->free();
    printf("%s: rows=%d %.1fs\n", $tf, $cnt, microtime(true) - $t0);
}
// 汇总
foreach (['1m', '3m', '5m', '15m', '1h'] as $tf) {
    $q = $m->query("SELECT COUNT(*), FROM_UNIXTIME(MIN(candle_time)/1000), FROM_UNIXTIME(MAX(candle_time)/1000) FROM kline_nq_$tf")->fetch_row();
    echo "$tf: $q[0]  $q[1] ~ $q[2]\n";
}
echo "done\n";
