<?php
/**
 * eth_100k.php — 一年赚10万U的保证金配比核算(CLI)
 * 方案A: 100x 每笔620U (20U版×31, 线性缩放)
 * 方案B: 10x 每笔5440U (500U版×10.24)
 * 共同: 六重共振+TP价格+2%+fib618加仓5轮+无美金强平 只买涨
 * 输出: web/eth_100k_data.json (含两方案账户安全核算)
 */
ini_set('memory_limit', '1024M');
set_time_limit(0);
$MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$TPR = 0.02; $MAXADD = 5;

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
function sim($o, $h, $l, $c, $ts, $fib, $entry, $br, $lev, $margin, $addU) {
    global $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $TPR, $MAXADD;
    $liqDrop = 1.0 / $lev - $MMR;
    $slP = ($lev <= 10) ? 0.03 : 10.0;   // 10x保留SL-3%; 100x下SL永不触发
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        $s = 0; $cnt = 0;
        for ($k = $i - 11; $k <= $i; $k++) if ($k >= 0) { $s += $br[$k]; $cnt++; }
        if ($cnt == 0 || $s / $cnt < 0.5) { $i++; continue; }
        $e = $o[$i + 1];
        $notional = $margin * $lev; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $liqPx = $avg * (1 - $liqDrop);
        $slPx = $avg * (1 - $slP);
        $tgtPx = $avg * (1 + $TPR);
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;
        while ($j < $n) {
            $barsHeld = $j - $i;
            $hitLiq = $l[$j] <= $liqPx;
            $hitSl  = $l[$j] <= $slPx;
            $hitTp  = $h[$j] >= $tgtPx;
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitSl)  { $outcome = 'SL';  $exitPx = $slPx;  break; }
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $addU * $lev) / ($notional + $addU * $lev);
                $notional += $addU * $lev; $adds++;
                $fee += $addU * $lev * $FEE_TAKER;
                $liqPx = $avg * (1 - $liqDrop);
                $slPx = $avg * (1 - $slP);
                $tgtPx = $avg * (1 + $TPR);
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }
        $gross = ($exitPx - $avg) * $notional / $avg;
        $funding = $notional * $FUND8H * ($barsHeld / 8);
        $pnl = $gross - $fee - $funding;
        $mg = $margin + $adds * $addU;
        if ($pnl < -$mg) $pnl = -$mg;
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,
                     'margin' => $mg, 'pnl' => round($pnl, 2)];
        $i = $j + 1;
    }
    return $trades;
}
function analyze($trades, $bal) {
    $cum = 0; $minCum = 0; $maxStreak = 0; $streak = 0; $streakLoss = 0; $maxStreakLoss = 0;
    $gw = 0; $gl = 0;
    foreach ($trades as $t) {
        $cum += $t['pnl']; if ($cum < $minCum) $minCum = $cum;
        if ($t['pnl'] < 0) { $streak++; $streakLoss += $t['pnl']; if ($streak > $maxStreak) { $maxStreak = $streak; $maxStreakLoss = $streakLoss; } }
        else { $streak = 0; $streakLoss = 0; }
        if ($t['pnl'] >= 0) $gw += $t['pnl']; else $gl += $t['pnl'];
    }
    $maxMargin = max(array_column($trades, 'margin'));
    $worst = min(array_column($trades, 'pnl'));
    $minEq = $bal + $minCum;
    // 最低安全余额: 最低权益点仍够开下一笔满轮保证金
    $minSafeBal = -$minCum + $maxMargin;
    return ['total' => round($cum, 2), 'gross_win' => round($gw, 2), 'gross_lose' => round($gl, 2),
            'min_cum' => round($minCum, 2), 'max_drawdown' => round(-$minCum, 2),
            'max_lose_streak' => $maxStreak, 'max_streak_loss' => round($maxStreakLoss, 2),
            'worst_single' => round($worst, 2), 'max_margin' => $maxMargin,
            'min_equity' => round($minEq, 2), 'min_safe_balance' => round($minSafeBal, 2),
            'safe' => ($minEq >= $maxMargin) ? '不爆仓' : '会爆仓(权益低于满轮保证金)'];
}

echo "== load ==\n"; flush();
[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h');
[$ts4h, , , , $c4h, ] = load_k('eth', '4h');
$n = count($ts1h);
echo "== breadth ==\n"; flush();
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
$sorted = array_keys($brSrc); sort($sorted);
function ffillBr($t) {
    global $brSrc, $sorted;
    $lo = 0; $hi = count($sorted) - 1; $res = null;
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }
    return $res !== null ? $brSrc[$res] : null;
}
echo "== features ==\n"; flush();
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);
$ma5_4h = ma($c4h, 5);
$tr4hUp = [];
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hUp[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i];
$tr4hUpF = [];
$p4 = 0; $cur4 = null; $n4 = count($ts4h);
foreach ($ts1h as $tt) { while ($p4 < $n4 && $ts4h[$p4] <= $tt) { $cur4 = $tr4hUp[$ts4h[$p4]] ?? $cur4; $p4++; } $tr4hUpF[$tt] = $cur4; }
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h, $v1h);
$fib = fibsig($c1h);
$entryA = [];
for ($i = 30; $i < $n; $i++) {
    $t = $ts1h[$i];
    $b = ffillBr($t);
    if ($b !== null && $b > 0.5 && $ma20[$i] !== null && $c1h[$i] > $ma20[$i] &&
        $ma10[$i] !== null && $c1h[$i] > $ma10[$i] && !empty($tr4hUpF[$t])) $entryA[$t] = true;
}
echo "entryA=" . count($entryA) . "\n"; flush();

// ===== 方案A: 100x 每笔620U =====
echo "== plan A: 100x 620U ==\n"; flush();
$trA = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $br1h, 100, 620, 620);
$sA = analyze($trA, 10000);
echo "A: total={$sA['total']} safe={$sA['safe']} minEq={$sA['min_equity']} minSafeBal={$sA['min_safe_balance']}\n"; flush();

// ===== 方案B: 10x 每笔5440U =====
echo "== plan B: 10x 5440U ==\n"; flush();
$trB = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $br1h, 10, 5440, 5440);
$sB = analyze($trB, 42000);
echo "B: total={$sB['total']} safe={$sB['safe']} minEq={$sB['min_equity']} minSafeBal={$sB['min_safe_balance']}\n"; flush();

// 明细构建(两方案)
function build($trades, $bal) {
    $cum = 0; $trOut = []; $daily = []; $no = 0;
    foreach ($trades as $t) {
        $no++; $cum += $t['pnl'];
        $d = date('Y-m-d', (int)($t['tout'] / 1000));
        $daily[$d] = ($daily[$d] ?? 0) + $t['pnl'];
        $trOut[] = ['no' => $no,
            'tin' => date('Y-m-d H:i', (int)($t['tin'] / 1000)), 'tout' => date('Y-m-d H:i', (int)($t['tout'] / 1000)),
            'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'pnl' => $t['pnl'],
            'cum' => round($cum, 2)];
    }
    ksort($daily);
    $dayRows = []; $c2 = 0;
    foreach ($daily as $d => $v) { $c2 += $v; $dayRows[] = ['d' => $d, 'pnl' => round($v, 2), 'cum' => round($c2, 2)]; }
    return [$trOut, $dayRows];
}
[$trAo, $dayA] = build($trA, 10000);
[$trBo, $dayB] = build($trB, 42000);
function summ($trades) {
    $w = 0; $lq = 0; $to = 0; $n = count($trades);
    foreach ($trades as $t) { if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'LIQ') $lq++; elseif ($t['out'] == 'TO') $to++; }
    return ['n' => $n, 'win' => $w, 'liq' => $lq, 'to' => $to, 'win_r' => $n ? round(100 * $w / $n, 1) : 0];
}
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;
$out = [
    'params' => ['entry' => '六重共振', 'tp' => '价格+2%', 'addon' => 'fib618加仓5轮(与本金同额)', 'span' => round($spanDays) . '天',
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000)), 'dir' => '只买涨'],
    'A' => ['lev' => 100, 'margin' => 620, 'balance' => 10000, 'sl' => '无(爆仓线-0.6%兜底)'] + $sA + summ($trA) + ['daily' => $dayA, 'trades' => $trAo],
    'B' => ['lev' => 10, 'margin' => 5440, 'balance' => 42000, 'sl' => '-3%(相对均价)'] + $sB + summ($trB) + ['daily' => $dayB, 'trades' => $trBo],
];
file_put_contents('E:/finally-main/web/eth_100k_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK A_trades=" . count($trAo) . " B_trades=" . count($trBo) . "\n";
