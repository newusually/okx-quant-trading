<?php
/**
 * score2x100_eth.php — ETH 15m Score买入/加仓 · 100x · 500U本金 · 首仓30U · 加仓1U (2026-09-27)
 * 规则: Score>=2事件(火箭同公式) → 无仓则开首仓30U保证金(名义3000U), 有仓则加仓1U(名义100U)
 *       止盈 价格+2%(跟随均价上移, 市价全平) · 无止损 · 爆仓线=均价×(1-1/100+0.004)=-0.6% · 无超时
 *       同根K线爆仓优先(保守) · taker 0.05%双边 · 现金流记账(起始500U)
 * 输出: web/score2100eth_data.json → 报告页 web/score2100eth.php
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$DB = new mysqli('127.0.0.1', 'root', '', 'trading');
$DB->set_charset('utf8mb4');

$LEV = 100; $FEE = 0.0005; $MMR = 0.004;
$FIRST = 30.0; $ADD = 1.0; $TPP = 0.02;
$CASH0 = 500.0;
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

$cash = $CASH0; $rounds = []; $negRun = 0; $pos = null; $rid = 0;
$nSig = 0; $nAdd = 0;
for ($i = 1; $i < $n; $i++) {
    if ($difR[$i] < 0) $negRun++; else $negRun = 0;
    if ($i < 35) continue;
    // ---- 持仓: 先走线判爆仓/止盈 ----
    if ($pos !== null) {
        $avg = $pos['costN'] / $pos['q'];
        $liqPx = $avg * (1 - 1 / $LEV + $MMR);
        $tpPx = $avg * (1 + $TPP);
        if ($l[$i] <= $liqPx) {                                     // 爆仓: 保证金全损
            $cash += 0;
            $rounds[$rid]['t1'] = $t[$i]; $rounds[$rid]['px1'] = $liqPx; $rounds[$rid]['why'] = 'LIQ';
            $rounds[$rid]['pnl'] = -($pos['m'] + $pos['fee']);
            $rounds[$rid]['delta'] = round($cash - $rounds[$rid]['cb'], 2);
            $rounds[$rid]['ca'] = round($cash, 2);
            $pos = null;
        } elseif ($h[$i] >= $tpPx) {                                // 止盈+2%: 市价全平
            $gross = $pos['costN'] * $TPP;
            $exitFee = $pos['costN'] * (1 + $TPP) * $FEE;
            $cash += $pos['m'] + $gross - $exitFee;
            $rounds[$rid]['t1'] = $t[$i]; $rounds[$rid]['px1'] = $tpPx; $rounds[$rid]['why'] = 'TP';
            $rounds[$rid]['pnl'] = $gross - $exitFee - $pos['fee'];
            $rounds[$rid]['delta'] = round($cash - $rounds[$rid]['cb'], 2);
            $rounds[$rid]['ca'] = round($cash, 2);
            $pos = null;
        }
    }
    // ---- Score事件 ----
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
    if ($t[$i] < $WIN_MS / 1000) continue;                          // 只在回测窗口内交易
    $nSig++;
    if ($pos !== null) {                                            // 加仓1U
        if ($cash >= $ADD + $LEV * $ADD * $FEE) {
            $p = $c[$i];
            $cash -= $ADD + $LEV * $ADD * $FEE;
            $pos['m'] += $ADD; $pos['q'] += $LEV * $ADD / $p; $pos['costN'] += $LEV * $ADD; $pos['fee'] += $LEV * $ADD * $FEE;
            $pos['adds'][] = ['t' => $t[$i], 'p' => $p];
            $rounds[$rid]['adds'][] = ['t' => $t[$i], 'p' => $p];
            $nAdd++;
        }
    } elseif ($cash >= $FIRST + $LEV * $FIRST * $FEE) {             // 开首仓30U(保证金1.5U费)
        $p = $c[$i]; $rid++;
        $cb0 = $cash;
        $pos = ['m' => $FIRST, 'q' => $LEV * $FIRST / $p, 'costN' => $LEV * $FIRST, 'fee' => $LEV * $FIRST * $FEE, 'adds' => []];
        $cash -= $FIRST + $LEV * $FIRST * $FEE;
        $rounds[$rid] = ['t0' => $t[$i], 'px0' => $p, 's' => round($s, 1), 'adds' => [], 'cb' => $cb0];
    }
}
// 期末未平仓 → 按最后收盘价标记
$open = null;
if ($pos !== null) {
    $avg = $pos['costN'] / $pos['q'];
    $unreal = $pos['q'] * ($c[$n - 1] - $avg) - $pos['costN'] * (1 + ($c[$n - 1] / $avg)) * $FEE - $pos['fee'];
    $open = ['t0' => $rounds[$rid]['t0'], 'px0' => $rounds[$rid]['px0'], 'm' => $pos['m'], 'nAdd' => count($pos['adds']), 'avg' => $avg, 'last' => $c[$n - 1], 'unreal' => round($unreal, 2)];
}
// 汇总

$tot = 0.0; $w = 0; $tpN = 0; $liqN = 0; $byMonth = [];
foreach ($rounds as $k => $rd) {
    if (!isset($rd['why'])) continue;
    $tot += $rd['pnl'];
    if ($rd['pnl'] > 0) $w++;
    if ($rd['why'] == 'TP') $tpN++; else $liqN++;
    $mk = gmdate('Y-m', $rd['t1'] + 8 * 3600);
    if (!isset($byMonth[$mk])) $byMonth[$mk] = ['n' => 0, 'pnl' => 0.0, 'w' => 0];
    $byMonth[$mk]['n']++; $byMonth[$mk]['pnl'] += $rd['pnl']; if ($rd['pnl'] > 0) $byMonth[$mk]['w']++;
}
// 输出轮次(含每轮加仓时间序列重新走一遍记录)
$sum = ['bars' => $n, 'nSig' => $nSig, 'nAdd' => $nAdd, 'nRound' => count($rounds), 'pnl' => round($tot, 2),
    'cashEnd' => round($CASH0 + $tot, 2), 'wr' => ($tpN + $liqN) ? round($w / ($tpN + $liqN) * 100, 1) : 0,
    'tp' => $tpN, 'liq' => $liqN, 'open' => $open,
    'winStart' => gmdate('Y-m-d', $WIN_MS / 1000 + 8 * 3600), 'winEnd' => gmdate('Y-m-d', $NOW_MS / 1000 + 8 * 3600)];
file_put_contents(__DIR__ . '/../web/score2100eth_data.json', json_encode([
    'sum' => $sum, 'byMonth' => $byMonth, 'rounds' => array_values($rounds)]));
printf("sigs=%d rounds=%d adds=%d tp=%d liq=%d pnl=%+.2f cashEnd=%.2f\n", $nSig, count($rounds), $nAdd, $tpN, $liqN, $tot, $CASH0 + $tot);
printf("REAL_CASH=%.2f openM=%.2f\n", $cash, $pos !== null ? $pos['m'] : 0);
