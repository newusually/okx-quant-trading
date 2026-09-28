<?php
/**
 * kline_gen_all.php — 为纳指 1m/3m/5m/15m/1h 生成K线+MACD+打分 JSON → web/kline_nq_{tf}.json
 * MACD(12,26,60) · Score>=2 的信号存入 sc (火箭标注, 同 score_eth.php 公式, 满分8)
 */
ini_set('memory_limit', '8192M');
set_time_limit(0);
function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function emaRaw($a, $p) {
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = $a[0];
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; }
    return $out;
}
foreach (['1m', '3m', '5m', '15m', '1h'] as $tf) {
    $t0 = microtime(true);
    $r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_nq_$tf ORDER BY candle_time ASC");
    $t = []; $o = []; $h = []; $l = []; $c = []; $v = [];
    while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0) continue; $t[] = (int)($x[0] / 1000); $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; }
    $r->free();
    $n = count($c);
    if ($n < 100) { echo "$tf: only $n bars, skip\n"; continue; }
    $O = []; $H = []; $L = []; $C = [];
    for ($i = 0; $i < $n; $i++) { $O[] = round($o[$i], 2); $H[] = round($h[$i], 2); $L[] = round($l[$i], 2); $C[] = round($c[$i], 2); }
    $e12 = emaRaw($c, 12); $e26 = emaRaw($c, 26);
    $difR = []; for ($i = 0; $i < $n; $i++) $difR[] = $e12[$i] - $e26[$i];
    $deaR = emaRaw($difR, 60);
    $d = []; $e = []; $m = []; $macdR = [];
    for ($i = 0; $i < $n; $i++) { $macdR[] = 2.0 * ($difR[$i] - $deaR[$i]); $d[] = round($difR[$i], 4); $e[] = round($deaR[$i], 4); $m[] = round($macdR[$i], 4); }
    // ATR14 / volMA20
    $atr = []; $atr[0] = $h[0] - $l[0]; $ka = 2.0 / 15;
    for ($i = 1; $i < $n; $i++) { $tr = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1])); $atr[$i] = $tr * $ka + $atr[$i - 1] * (1 - $ka); }
    $vma = array_fill(0, $n, 0.0); $sv = 0.0;
    for ($i = 0; $i < $n; $i++) { $sv += $v[$i]; if ($i >= 20) $sv -= $v[$i - 20]; $vma[$i] = $i >= 19 ? $sv / 20 : $sv / ($i + 1); }
    // 打分
    $sc = []; $negRun = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($difR[$i] < 0) $negRun++; else $negRun = 0;
        if ($i < 35) continue;
        if (!($macdR[$i] > 0 && $macdR[$i - 1] <= 0 && $difR[$i] < 0)) continue;
        $trough = 0.0;
        for ($j = max(0, $i - 30); $j < $i; $j++) if ($macdR[$j] < $trough) $trough = $macdR[$j];
        $a = max($atr[$i - 1], 1e-9);
        $s = 2.0 * min(abs($trough) / $a, 3.0) / 3.0 + 1.5 * min($negRun, 100) / 100.0;
        if ($negRun >= 40) {
            $ss = $i - $negRun + 1; $mm = $ss + intdiv($negRun - 1, 2);
            $t1 = $ss; for ($j = $ss; $j <= $mm; $j++) if ($l[$j] < $l[$t1]) $t1 = $j;
            $t2 = $mm + 1; for ($j = $mm + 1; $j < $i; $j++) if ($l[$j] < $l[$t2]) $t2 = $j;
            if ($l[$t2] < $l[$t1] && $macdR[$t2] > $macdR[$t1]) $s += 2.5;
        }
        $s += 1.0;
        if ($v[$i] > 1.5 * $vma[$i - 1] && $vma[$i - 1] > 0) $s += 1.0;
        if ($s >= 2.0) $sc[] = [$i, round($s, 1)];
    }
    $stT = [];
    for ($i = max(0, $n - 10); $i < $n; $i++) $stT[] = [$difR[$i], $deaR[$i], $e12[$i], $e26[$i]];
    file_put_contents(__DIR__ . "/../web/kline_nq_$tf.json",
        json_encode(['tf' => $tf, 'n' => $n, 't' => $t, 'o' => $O, 'h' => $H, 'l' => $L, 'c' => $C, 'd' => $d, 'e' => $e, 'm' => $m, 'sc' => $sc, 'stT' => $stT]));
    printf("%s: bars=%d rockets=%d span=%s~%s size=%.1fMB %.0fs\n", $tf, $n, count($sc),
        gmdate('Y-m-d H:i', $t[0] + 8 * 3600), gmdate('Y-m-d H:i', $t[$n - 1] + 8 * 3600),
        filesize(__DIR__ . "/../web/kline_nq_$tf.json") / 1048576, microtime(true) - $t0);
    unset($t, $o, $h, $l, $c, $v, $O, $H, $L, $C, $e12, $e26, $difR, $deaR, $d, $e, $m, $macdR, $atr, $vma, $sc);
}
echo "all done\n";
