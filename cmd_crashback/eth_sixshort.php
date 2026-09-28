<?php
/**
 * eth_sixshort.php — ETH 牛市买多+熊市卖空 双向回测(CLI, 2026-09-26 用户指令)
 * 用户: "能不能判断一下 熊市卖空 牛市买多 这样能不能盈利 回测一下"
 *   多头: 六重共振(牛市环境)全命中 AND 15m三均线多头排列(MA7>MA25>MA99且收>MA7) → 做多
 *   空头: 熊市共振(六条件全部反向: 全市场1D MA20宽度<50% / ETH 1h<MA20 / 1h<MA10 / 4h<MA5 /
 *         taker卖压(买比<50%) / BTC熔断双向禁入) AND 15m三均线空头排列(MA7<MA25<MA99且收<MA7) → 做空
 *   加仓(双向同): 对应方向三均线排列 + 顺向(多:现价>均价 / 空:现价<均价), 不限轮数, 每轮+1U, 无仓位上限
 *   保证金: 1U+3U×已过天数 封顶50U | 止盈: 价格±2%(自均价) | 不设止损 | 爆仓线±0.6%@100x 照模拟 | 超时7天
 *   资金费: 多头支付 0.01%/8h, 空头收取(ETH资金费常态为正的简化假设)
 *   净口径: 开仓maker费/加仓taker费/平仓taker费 ± 资金费; 起始500U 单账户顺序交易
 * 输出: web/sixshort_data.json
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$EQ0 = 500.0; $ADDU = 1.0; $CAPM = 50.0;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }

function load_k($inst, $bar) {
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");
    $ts = []; $o = []; $h = []; $l = []; $c = [];
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }
    return [$ts, $o, $h, $l, $c];
}

// ===== ① 全市场 1D MA20 宽度 =====
$up = []; $tot = [];
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");
$cur = null; $win = []; $s = 0.0;
foreach ($rr as $x) {
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; }
    $win[] = (float)$x[2]; $s += (float)$x[2];
    if (count($win) > 20) $s -= array_shift($win);
    if (count($win) == 20) {
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2];
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0);
        $tot[$t] = ($tot[$t] ?? 0) + 1;
    }
}
$brSrc = [];
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k;
$brDays = array_keys($brSrc); sort($brDays);
function ffillBr($t) {
    global $brSrc, $brDays;
    $lo = 0; $hi = count($brDays) - 1; $res = null;
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($brDays[$mid] <= $t) { $res = $brDays[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }
    return $res !== null ? $brSrc[$res] : null;
}
echo "breadth days=" . count($brSrc) . "\n"; flush();

// ===== ⑤ BTC 熔断时刻集合(双向禁入) =====
[$tsB, , , , $cB] = load_k('btc', '1h');
$meltSet = [];
for ($i = 4; $i < count($cB); $i++) {
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;
    }
}

// ===== ETH K线 =====
[$tsF, $oF, $hF, $lF, $cF] = load_k('eth', '15m');
[$ts1, , , , $c1] = load_k('eth', '1h');
[$ts4, , , , $c4] = load_k('eth', '4h');
$ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5);
$ma7 = ma($cF, 7); $ma25 = ma($cF, 25); $ma99 = ma($cF, 99);

function buyratio($o, $h, $l, $c) {
    $n = count($c); $br = [];
    for ($i = 0; $i < $n; $i++) {
        $rng = $h[$i] - $l[$i];
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;
        $br[$i] = 0.5 + $dir / 2;
    }
    return $br;
}
[$ts1b, $o1b, $h1b, $l1b, $c1b] = load_k('eth', '1h');
$br1h = buyratio($o1b, $h1b, $l1b, $c1b);

$nF = count($tsF);
echo "eth15m bars=$nF span=" . bj($tsF[0]) . " ~ " . bj($tsF[$nF - 1]) . "\n"; flush();

// ===== 逐15m判定 多头六重共振 / 空头熊市共振 =====
$cnt = ['sixL' => 0, 'sixS' => 0, 'triL' => 0, 'triS' => 0, 'eL' => 0, 'eS' => 0];
$p1 = 0; $p4 = 0; $n1 = count($ts1); $n4 = count($ts4);
$cur20 = null; $cur10 = null; $cur4 = null;
$sixL = array_fill(0, $nF, false); $sixS = array_fill(0, $nF, false);
for ($i = 30; $i < $nF; $i++) {
    $t = $tsF[$i];
    while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; }
    while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; }
    $hb = (int)floor($t / 3600000) * 3600000;
    if (isset($meltSet[$hb])) continue;                 // 熔断双向禁入
    $b = ffillBr($t);
    if ($b === null) continue;
    $lo = 0; $hi = $n1 - 1; $ib = -1;
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }
    if ($ib < 0) continue;
    $br = $br1h[$ib];
    if ($b > 0.5 && $cur20 && $cur10 && $cur4 && $br >= 0.5) { $sixL[$i] = true; $cnt['sixL']++; }
    if ($b < 0.5 && $cur20 === false && $cur10 === false && $cur4 === false && $br < 0.5) { $sixS[$i] = true; $cnt['sixS']++; }
}
// ===== 三均线排列(15m) =====
$triL = array_fill(0, $nF, false); $triS = array_fill(0, $nF, false);
for ($i = 0; $i < $nF; $i++) {
    if ($ma7[$i] === null || $ma25[$i] === null || $ma99[$i] === null) continue;
    if ($ma7[$i] > $ma25[$i] && $ma25[$i] > $ma99[$i] && $cF[$i] > $ma7[$i]) { $triL[$i] = true; $cnt['triL']++; }
    if ($ma7[$i] < $ma25[$i] && $ma25[$i] < $ma99[$i] && $cF[$i] < $ma7[$i]) { $triS[$i] = true; $cnt['triS']++; }
}
$eL = array_fill(0, $nF, false); $eS = array_fill(0, $nF, false);
for ($i = 30; $i < $nF; $i++) {
    if ($sixL[$i] && $triL[$i]) { $eL[$i] = true; $cnt['eL']++; }
    if ($sixS[$i] && $triS[$i]) { $eS[$i] = true; $cnt['eS']++; }
}
echo "sixL={$cnt['sixL']} sixS={$cnt['sixS']} triL={$cnt['triL']} triS={$cnt['triS']} entryL={$cnt['eL']} entryS={$cnt['eS']}\n"; flush();

// ===== sim: 单账户顺序双向回测 =====
$liqDrop = 1.0 / $LEV - $MMR;
$eq = $EQ0; $startT = $tsF[30];
$trades = []; $i = 30;
while ($i < $nF - 1) {
    $isL = !empty($eL[$i]); $isS = !$isL && !empty($eS[$i]);
    if (!$isL && !$isS) { $i++; continue; }
    $side = $isL ? 'LONG' : 'SHORT';
    $days = (int)floor((($tsF[$i] - $startT) / 1000) / 86400);
    $M0 = round(min(1.0 + 3.0 * $days, $CAPM), 2);
    if ($M0 < 1) $M0 = 1.0;
    $e = $oF[$i + 1];
    $notional = $M0 * $LEV; $avg = $e; $adds = 0;
    $fee = $notional * $FEE_MAKER; $funding = 0.0;
    if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); }
    else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - $TPR); }
    $outcome = null; $j = $i + 1;
    while ($j < $nF) {
        $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 32;
        $hitLiq = $isL ? ($lF[$j] <= $liqPx) : ($hF[$j] >= $liqPx);
        $hitTp  = $isL ? ($hF[$j] >= $tgtPx) : ($lF[$j] <= $tgtPx);
        if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
        if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
        if ($j - $i >= 7 * 24 * 4) { $outcome = 'TO'; $exitPx = $cF[$j]; break; }
        // 加仓: 对应方向三均线排列 + 顺向, 不限轮数, 每轮+1U
        $triOk = $isL ? ($triL[$j] && $cF[$j] > $avg) : ($triS[$j] && $cF[$j] < $avg);
        if ($triOk && $j + 1 < $nF) {
            $ap = $oF[$j + 1];
            if ($ap > 0) {
                $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);
                $notional += $ADDU * $LEV; $adds++;
                $fee += $ADDU * $LEV * $FEE_TAKER;
                if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); }
                else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - $TPR); }
            }
        }
        $j++;
    }
    if ($outcome === null) { $outcome = 'TO'; $j = $nF - 1; $exitPx = $cF[$j]; }
    $gross = $isL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);
    $fee += $notional * $FEE_TAKER;
    $pnl = $gross - $fee - $funding;
    $mg = $M0 + $adds * $ADDU;
    if ($pnl < -$mg) $pnl = -$mg;
    $eq += $pnl;
    $trades[] = ['side' => $side, 'tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds,
                 'm0' => $M0, 'mg' => $mg, 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),
                 'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];
    $i = $j + 1;
}

function summarize($trades, $eqEnd) {
    $tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'adds' => 0, 'pnl' => 0.0];
    foreach ($trades as $x) {
        if ($x['out'] == 'WIN') $tot['win']++;
        if ($x['out'] == 'LIQ') $tot['liq']++;
        if ($x['out'] == 'TO') $tot['to']++;
        $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl'];
    }
    $months = [];
    foreach ($trades as $x) {
        $m = gmdate('Y-m', (int)($x['tout'] / 1000) + 8 * 3600);
        $mo = &$months[$m];
        $mo['n'] = ($mo['n'] ?? 0) + 1;
        $mo['win'] = ($mo['win'] ?? 0) + ($x['out'] == 'WIN' ? 1 : 0);
        $mo['liq'] = ($mo['liq'] ?? 0) + ($x['out'] == 'LIQ' ? 1 : 0);
        $mo['adds'] = ($mo['adds'] ?? 0) + $x['adds'];
        $mo['pnl'] = round(($mo['pnl'] ?? 0) + $x['pnl'], 2);
        $mo['eq_end'] = $x['eq'];
        unset($mo);
    }
    $peak = -INF; $maxdd = 0;
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }
    return ['summary' => $tot, 'eq_end' => round($eqEnd, 2), 'maxdd' => round($maxdd, 2), 'months' => $months, 'trades' => $trades];
}

$trL = array_values(array_filter($trades, fn($x) => $x['side'] == 'LONG'));
$trS = array_values(array_filter($trades, fn($x) => $x['side'] == 'SHORT'));
$all = summarize($trades, $eq);
$L = summarize($trL, 0); $S = summarize($trS, 0);
printf("ALL n=%d win=%d liq=%d pnl=%.1f eqEnd=%.1f maxdd=%.1f\n", $all['summary']['n'], $all['summary']['win'], $all['summary']['liq'], $all['summary']['pnl'], $all['eq_end'], $all['maxdd']);
printf("LONG n=%d win=%d liq=%d pnl=%.1f | SHORT n=%d win=%d liq=%d pnl=%.1f\n",
    $L['summary']['n'], $L['summary']['win'], $L['summary']['liq'], $L['summary']['pnl'],
    $S['summary']['n'], $S['summary']['win'], $S['summary']['liq'], $S['summary']['pnl']);

$out = [
    'meta' => [
        'inst' => 'ETH-USDT-SWAP', 'lev' => $LEV, 'tp' => '±2%', 'eq0' => $EQ0,
        'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF, 'cond' => $cnt,
        'params' => '牛市买多=六重共振(全市场1D MA20宽度>50%/ETH 1h>MA20/1h>MA10/4h>MA5/taker买比≥50%/BTC熔断禁入) AND 15m三均线多头排列(MA7>MA25>MA99且收>MA7); 熊市卖空=六条件全部反向 AND 15m三均线空头排列(MA7<MA25<MA99且收<MA7); 加仓双向同=对应方向三均线排列+顺向(多:现价>均价/空:现价<均价) 不限轮数 每轮+1U 无仓位上限 | 保证金=1U+3U×已过天数 封顶50U | 止盈=价格±2%(自均价) | 不设止损·爆仓线±0.6%@100x照模拟 | 超时7天 | 资金费=多头付/空头收 0.01%/8h(简化假设) | 净口径含手续费+资金费 | 起始500U 单账户顺序',
    ],
    'ALL' => $all, 'LONG' => $L, 'SHORT' => $S,
];
file_put_contents('E:/finally-main/web/sixshort_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "DATA OK\n";
