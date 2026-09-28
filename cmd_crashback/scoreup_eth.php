<?php
/**
 * scoreup_eth.php — ETH 15m Score递增买入 · 一年回测 (2026-09-27)
 * 规则同 scoreup_all.php: Score>=2事件 当前分>前一事件分→买入, TP+0.5%, 无止损,
 * 爆仓-4.6%(20x), 超时7天, taker 0.05%, 每笔1U×20x, 同票一仓. 窗口=最近365天.
 * 输出: web/scoreup_eth_data.json → 报告页 web/scoreupeth.php
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$DB = new mysqli('127.0.0.1', 'root', '', 'trading');
$DB->set_charset('utf8mb4');

$LEV = 20; $FEE = 0.0005; $U = 1.0; $TP = 0.005; $MMR = 0.004;
$TMO_MS = 7 * 86400 * 1000;
$NOW_MS = (int)round(microtime(true) * 1000);
$WIN_MS = $NOW_MS - 365 * 86400 * 1000;

function emaRaw($a, $p) {
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = 0.0;
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; }
    return $out;
}

$r = $DB->query("SELECT candle_time,`h`,`l`,`c`,vol FROM kline_eth_usdt_swap_15m ORDER BY candle_time ASC");
$t = []; $h = []; $l = []; $c = []; $v = [];
while ($x = $r->fetch_row()) { if ((float)$x[3] <= 0) continue; $t[] = (int)($x[0] / 1000); $h[] = (float)$x[1]; $l[] = (float)$x[2]; $c[] = (float)$x[3]; $v[] = (float)$x[4]; }
$r->free();
$n = count($c);
echo "bars=$n\n";

$e12 = emaRaw($c, 12); $e26 = emaRaw($c, 26);
$difR = []; for ($i = 0; $i < $n; $i++) $difR[] = $e12[$i] - $e26[$i];
$deaR = emaRaw($difR, 60);
$macdR = []; for ($i = 0; $i < $n; $i++) $macdR[] = 2.0 * ($difR[$i] - $deaR[$i]);
$atr = []; $atr[0] = $h[0] - $l[0]; $ka = 2.0 / 15;
for ($i = 1; $i < $n; $i++) { $tr = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1])); $atr[$i] = $tr * $ka + $atr[$i - 1] * (1 - $ka); }
$vma = array_fill(0, $n, 0.0); $sv = 0.0;
for ($i = 0; $i < $n; $i++) { $sv += $v[$i]; if ($i >= 20) $sv -= $v[$i - 20]; $vma[$i] = $i >= 19 ? $sv / 20 : $sv / ($i + 1); }

$trades = []; $nSig = 0; $negRun = 0; $prevScore = 0.0; $pos = null; $nominal = $U * $LEV;
for ($i = 1; $i < $n; $i++) {
    if ($difR[$i] < 0) $negRun++; else $negRun = 0;
    if ($i < 35) continue;
    if ($pos !== null) {
        $liqPx = $pos['px'] * (1 - 1 / $LEV + $MMR);
        if ($l[$i] <= $liqPx) {
            $trades[] = ['t0' => $pos['t0'], 'px0' => $pos['px'], 't1' => $t[$i], 'px1' => $liqPx, 'why' => 'LIQ', 'pnl' => -$U, 's' => $pos['s']];
            $pos = null; continue;
        } elseif ($h[$i] >= $pos['tp']) {
            $gross = $nominal * $TP; $fee = ($nominal + $nominal * (1 + $TP)) * $FEE;
            $trades[] = ['t0' => $pos['t0'], 'px0' => $pos['px'], 't1' => $t[$i], 'px1' => $pos['tp'], 'why' => 'TP', 'pnl' => $gross - $fee, 's' => $pos['s']];
            $pos = null; continue;
        } elseif ($t[$i] - $pos['t0'] >= $TMO_MS) {
            $exit = $c[$i]; $gross = $nominal * ($exit - $pos['px']) / $pos['px']; $fee = ($nominal + $nominal * $exit / $pos['px']) * $FEE;
            $trades[] = ['t0' => $pos['t0'], 'px0' => $pos['px'], 't1' => $t[$i], 'px1' => $exit, 'why' => 'TO', 'pnl' => $gross - $fee, 's' => $pos['s']];
            $pos = null; continue;
        }
        continue;
    }
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
    if ($s < 2.0) continue;
    $nSig++;
    if ($s > $prevScore && $t[$i] >= $WIN_MS / 1000) {
        $pos = ['t0' => $t[$i], 'px' => $c[$i], 'tp' => $c[$i] * (1 + $TP), 's' => round($s, 1)];
    }
    $prevScore = $s;
}

$tot = 0.0; $w = 0; $liq = 0; $to = 0; $tp = 0; $byMonth = [];
foreach ($trades as $tr) {
    $tot += $tr['pnl'];
    if ($tr['pnl'] > 0) $w++;
    if ($tr['why'] == 'LIQ') $liq++; elseif ($tr['why'] == 'TO') $to++; else $tp++;
    $mk = gmdate('Y-m', $tr['t1'] + 8 * 3600);
    if (!isset($byMonth[$mk])) $byMonth[$mk] = ['n' => 0, 'pnl' => 0.0, 'w' => 0];
    $byMonth[$mk]['n']++; $byMonth[$mk]['pnl'] += $tr['pnl']; if ($tr['pnl'] > 0) $byMonth[$mk]['w']++;
}
$sum = ['bars' => $n, 'nSig' => $nSig, 'nTrade' => count($trades), 'pnl' => round($tot, 2),
    'wr' => count($trades) ? round($w / count($trades) * 100, 1) : 0,
    'tp' => $tp, 'liq' => $liq, 'to' => $to,
    'winStart' => gmdate('Y-m-d', $WIN_MS / 1000 + 8 * 3600), 'winEnd' => gmdate('Y-m-d', $NOW_MS / 1000 + 8 * 3600)];
file_put_contents(__DIR__ . '/../web/scoreup_eth_data.json', json_encode([
    'sum' => $sum, 'byMonth' => $byMonth, 'trades' => $trades]));
printf("sigs=%d trades=%d pnl=%+.2f wr=%.1f%% tp=%d liq=%d to=%d\n", $nSig, count($trades), $tot, $sum['wr'], $tp, $liq, $to);
