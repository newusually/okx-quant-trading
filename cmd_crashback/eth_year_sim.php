<?php
/**
 * eth_year_sim.php — 最近一年 · 买多+卖空 双向 · 10x/SL-3%/TP+0.5%/taker/fib618加仓 (CLI)
 * 宽度特征=全市场1D(kline1d, 一年)前向填充到1h网格; 模拟网格=ETH 1h(一年)
 * 输出: E:/finally-main/web/eth_year_data.json
 */
ini_set('memory_limit', '1024M');
set_time_limit(0);
$PRINCIPAL = 5000.0; $MARGIN = 500.0; $ADD = 500.0; $MAXADD = 5;
$LEV = 10; $TPR = 0.005; $SLP = 0.03; $TAKER_TH = 0.5;
$STOP50 = 50.0;   // 用户指令: 浮亏超过50U立即平仓(按当前名义仓位, 加仓后自动收紧)
$MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }
function fibsig($c, $w = 30) {
    $n = count($c); $out = array_fill(0, $n, false);
    for ($i = $w - 1; $i < $n; $i++) {
        $lo = INF; $hi = -INF;
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }
        if ($hi <= $lo) continue;
        $fib = $hi - ($hi - $lo) * 0.618;
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true;
    }
    return $out;
}
function ffill_map($gridTs, $srcTs, $srcVal) {
    $out = []; $p = 0; $cur = null; $n = count($srcTs);
    foreach ($gridTs as $tt) {
        while ($p < $n && $srcTs[$p] <= $tt) { $cur = $srcVal[$srcTs[$p]] ?? $cur; $p++; }
        $out[$tt] = $cur;
    }
    return $out;
}
function load_k($inst, $bar) {
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");
    $ts = []; $o = []; $h = []; $l = []; $c = []; $v = [];
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; }
    return [$ts, $o, $h, $l, $c, $v];
}
function buy_ratio_series($o, $h, $l, $c, $v) {
    $n = count($c); $br = [];
    for ($i = 0; $i < $n; $i++) {
        $rng = $h[$i] - $l[$i];
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;
        $br[$i] = 0.5 + $dir / 2;
    }
    return $br;
}
// dir=+1 多 / -1 空
function sim($o, $h, $l, $c, $ts, $fib, $entry, $br, $takerTh, $d) {
    global $MARGIN, $ADD, $MAXADD, $LEV, $TPR, $SLP, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $STOP50, $DBG;
    $liqDrop = 1.0 / $LEV - $MMR;
    $trades = []; $n = count($ts); $i = 30; $DBG['entry'] = 0; $DBG['passed'] = 0;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        $DBG['entry']++;
        if ($takerTh !== null) {
            $s = 0; $cnt = 0;
            for ($k = $i - 11; $k <= $i; $k++) if ($k >= 0) { $s += ($d > 0 ? $br[$k] : 1 - $br[$k]); $cnt++; }
            if ($cnt == 0 || $s / $cnt < $takerTh) { $i++; continue; }
        }
        $DBG['passed']++;
        $e = $o[$i + 1];
        $notional = $MARGIN * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $liqPx = $avg * (1 - $d * $liqDrop);
        $slPx = $avg * (1 - $d * $SLP);
        $tgtPx = $avg * (1 + $d * $TPR);
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;
        while ($j < $n) {
            $barsHeld = $j - $i;
            $hitLiq = ($d > 0) ? ($l[$j] <= $liqPx) : ($h[$j] >= $liqPx);
            // 用户铁律: 浮亏>50U 立即平仓(先于SL/TP, 同根K线保守优先)
            $stopPx = $avg * (1 - $d * ($STOP50 / $notional));
            $hit50 = ($d > 0) ? ($l[$j] <= $stopPx) : ($h[$j] >= $stopPx);
            $hitSl  = ($d > 0) ? ($l[$j] <= $slPx)  : ($h[$j] >= $slPx);
            $hitTp  = ($d > 0) ? ($h[$j] >= $tgtPx) : ($l[$j] <= $tgtPx);
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hit50)  { $outcome = 'SL50'; $exitPx = $stopPx; break; }
            if ($hitSl)  { $outcome = 'SL';  $exitPx = $slPx;  break; }
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            $addOk = ($d > 0) ? ($c[$j] > $avg) : ($c[$j] < $avg);   // 加仓: 多=上行, 空=下行
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $addOk) {
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $ADD * $LEV) / ($notional + $ADD * $LEV);
                $notional += $ADD * $LEV; $adds++;
                $fee += $ADD * $LEV * $FEE_TAKER;
                $liqPx = $avg * (1 - $d * $liqDrop);
                $slPx = $avg * (1 - $d * $SLP);
                $tgtPx = $avg * (1 + $d * $TPR);
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }
        $gross = ($exitPx - $avg) * $notional / $avg * $d;
        $funding = $notional * $FUND8H * ($barsHeld / 8) * $d;   // 多付空收
        $pnl = $gross - $fee - $funding;
        $mg = $MARGIN + $adds * $ADD;
        if ($pnl < -$mg) $pnl = -$mg;
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,
                     'margin' => $mg, 'pnl' => round($pnl, 2), 'epx' => round($e, 2), 'xpx' => round($exitPx, 2),
                     'notional' => round($notional, 0), 'liqpx' => round($liqPx, 2), 'bars' => $barsHeld];
        $i = $j + 1;
    }
    return $trades;
}

echo "== load ETH/BTC 1h(一年) ==\n"; flush();
[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h');
[, , , , $cbtc1h, ] = load_k('btc', '1h');
[$ts4h, , , , $c4h, ] = load_k('eth', '4h');
$n = count($ts1h);
echo "1h bars=$n (" . date('Y-m-d', (int)($ts1h[0] / 1000)) . " ~ " . date('Y-m-d', (int)(end($ts1h) / 1000)) . ")\n"; flush();

echo "== breadth from kline1d ==\n"; flush();
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
$brDays = count($brSrc);
echo "breadth days=$brDays\n"; flush();

echo "== features ==\n"; flush();
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);
$ma5_4h = ma($c4h, 5);
$tr4hUp = []; $tr4hDn = [];
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) { $tr4hUp[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i]; $tr4hDn[$ts4h[$i]] = $c4h[$i] < $ma5_4h[$i]; }
$tr4hUpF = ffill_map($ts1h, $ts4h, $tr4hUp);
$tr4hDnF = ffill_map($ts1h, $ts4h, $tr4hDn);
$pc = []; for ($i = 0; $i < count($cbtc1h); $i++) $pc[$i] = ($i >= 4 && $cbtc1h[$i - 4] != 0) ? $cbtc1h[$i] / $cbtc1h[$i - 4] - 1 : null;
$crashSrc = []; $pumpSrc = [];
for ($i = 0; $i < count($cbtc1h); $i++) {
    if ($pc[$i] === null) continue;
    if ($pc[$i] < -0.03) $crashSrc[$ts1h[$i]] = true;
    if ($pc[$i] > 0.03) $pumpSrc[$ts1h[$i]] = true;
}
$crash = ffill_map($ts1h, $ts1h, $crashSrc);
$pump = ffill_map($ts1h, $ts1h, $pumpSrc);
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h, $v1h);
$fib = fibsig($c1h);

$entryL = []; $entryS = [];
$CN = ['b' => 0, 'u20' => 0, 'u10' => 0, 'tr' => 0, 'nc' => 0, 'all' => 0, 'bnull' => 0,
       'sb' => 0, 'd20' => 0, 'd10' => 0, 'str' => 0, 'np' => 0, 'sall' => 0];
for ($i = 0; $i < $n; $i++) {
    $t = $ts1h[$i];
    $b = ffillBr($brSrc, $t);
    if ($b === null) { $CN['bnull']++; continue; }
    // 多头逐关
    if ($b > 0.5) { $CN['b']++; } else goto SCHK;
    if ($ma20[$i] !== null && $c1h[$i] > $ma20[$i]) { $CN['u20']++; } else goto SCHK;
    if ($ma10[$i] !== null && $c1h[$i] > $ma10[$i]) { $CN['u10']++; } else goto SCHK;
    if (!empty($tr4hUpF[$t])) { $CN['tr']++; } else goto SCHK;
    if (empty($crashSrc[$t])) { $CN['nc']++; $entryL[$t] = true; $CN['all']++; }
    SCHK:
    // 空头逐关
    if ($b < 0.5) { $CN['sb']++; } else continue;
    if ($ma20[$i] !== null && $c1h[$i] < $ma20[$i]) { $CN['d20']++; } else continue;
    if ($ma10[$i] !== null && $c1h[$i] < $ma10[$i]) { $CN['d10']++; } else continue;
    if (!empty($tr4hDnF[$t])) { $CN['str']++; } else continue;
    if (empty($pumpSrc[$t])) { $CN['np']++; $entryS[$t] = true; $CN['sall']++; }
}
echo "DBG CN: " . json_encode($CN) . "\n"; flush();
function ffillBr($src, $t) {
    static $last = null; static $k = null;
    // 简单实现: 每次 O(n) 会太慢 — 用二分
    $keys = array_keys($src);
    static $sorted = null;
    if ($sorted === null) { $sorted = $keys; sort($sorted); }
    $lo = 0; $hi = count($sorted) - 1; $res = null;
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }
    return $res !== null ? $src[$res] : null;
}
echo "== sim long/short ==\n"; flush();
$trL = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryL, $br1h, $TAKER_TH, 1);
$dbgL = $DBG;
$trS = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryS, $br1h, $TAKER_TH, -1);
echo "DBG entryL=" . count(array_filter($entryL)) . " seenL={$dbgL['entry']} passL={$dbgL['passed']} tradesL=" . count($trL) . "\n"; flush();
echo "long=" . count($trL) . " short=" . count($trS) . "\n"; flush();

$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;
function build($trades, $dir, $principal) {
    $cum = 0.0; $trOut = []; $daily = []; $monthly = []; $no = 0;
    foreach ($trades as $t) {
        $no++; $cum += $t['pnl'];
        $d = date('Y-m-d', (int)($t['tout'] / 1000)); $m = substr($d, 0, 7);
        $daily[$d] = ($daily[$d] ?? 0) + $t['pnl'];
        $monthly[$m] = ($monthly[$m] ?? 0) + $t['pnl'];
        $trOut[] = ['no' => $no, 'dir' => $dir,
            'tin' => date('Y-m-d H:i', (int)($t['tin'] / 1000)), 'epx' => $t['epx'],
            'tout' => date('Y-m-d H:i', (int)($t['tout'] / 1000)), 'xpx' => $t['xpx'],
            'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'notional' => $t['notional'],
            'mrate' => 10.0, 'liqpx' => $t['liqpx'], 'pnl' => $t['pnl'],
            'pnl_r' => round(100 * $t['pnl'] / $t['margin'], 1),
            'avail' => round($principal + $cum, 2), 'cum' => round($cum, 2)];
    }
    ksort($daily); ksort($monthly);
    return [$trOut, $daily, $monthly];
}
[$trLo, $dayL, $monL] = build($trL, '多', $PRINCIPAL);
[$trSo, $dayS, $monS] = build($trS, '空', $PRINCIPAL);
// 每日合并(多/空/合计)
$allDays = array_unique(array_merge(array_keys($dayL), array_keys($dayS))); sort($allDays);
$dayRows = []; $c1 = 0; $c2 = 0; $wd = 0; $ld = 0;
foreach ($allDays as $d) {
    $v1 = round($dayL[$d] ?? 0, 2); $v2 = round($dayS[$d] ?? 0, 2); $vt = round($v1 + $v2, 2);
    $c1 += $v1; $c2 += $vt;
    $nL = 0; $nS = 0;
    foreach ($trL as $t) if (date('Y-m-d', (int)($t['tout'] / 1000)) == $d) $nL++;
    foreach ($trS as $t) if (date('Y-m-d', (int)($t['tout'] / 1000)) == $d) $nS++;
    if ($vt >= 0) $wd++; else $ld++;
    $dayRows[] = ['d' => $d, 'nL' => $nL, 'nS' => $nS, 'pL' => $v1, 'pS' => $v2, 'pT' => $vt, 'cum' => round($c2, 2)];
}
function summ($trades) {
    $w = 0; $sl = 0; $lq = 0; $to = 0; $pl = 0;
    foreach ($trades as $t) { if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'SL' || $t['out'] == 'SL50') $sl++; elseif ($t['out'] == 'LIQ') $lq++; else $to++; $pl += $t['pnl']; }
    return ['n' => count($trades), 'win' => $w, 'sl' => $sl, 'liq' => $lq, 'to' => $to,
            'win_r' => round(100 * $w / max(1, count($trades)), 1), 'total' => round($pl, 2),
            'ev' => round($pl / max(1, count($trades)), 2)];
}
$out = [
    'params' => ['principal' => $PRINCIPAL, 'margin' => $MARGIN, 'lev' => $LEV, 'tp' => '价格±0.5%', 'sl' => '±3%(相对均价)',
                 'taker' => '前12根买比均值>0.5(多)/<0.5(空)', 'span' => round($spanDays) . '天',
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000)),
                 'breadth' => "全市场476合约 1D MA20宽度({$brDays}天)填充到1h网格"],
    'sumL' => summ($trL), 'sumS' => summ($trS),
    'tradesL' => $trLo, 'tradesS' => $trSo, 'daily' => $dayRows,
    'monthly' => ['L' => $monL, 'S' => $monS],
];
file_put_contents('E:/finally-main/web/eth_year_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK L=" . count($trLo) . " S=" . count($trSo) . " days=" . count($dayRows) . "\n";
