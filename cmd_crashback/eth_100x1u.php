<?php
/**
 * eth_100x1u.php — 同款最优配置换100x·1U保证金·只买涨(CLI)
 * 配置: 六重共振入场 + TP价格+2% + fib618加仓(每次1U, 最多5轮) + 余额500U
 * 美金强平: 250U在1U保证金下无意义, 对比档 0/0.5/1/2/3U
 * 输出: web/eth_100x1u_data.json
 */
ini_set('memory_limit', '1024M');
set_time_limit(0);
$BALANCE = 500.0; $MARGIN = 1.0; $ADD = 1.0; $MAXADD = 5;
$LEV = 100; $TPR = 0.02; $SLP = 0.03;    // SL-3%在100x下永远晚于爆仓, 保留仅为完整
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
function sim($o, $h, $l, $c, $ts, $fib, $entry, $br, $lossCut) {
    global $MARGIN, $ADD, $MAXADD, $LEV, $TPR, $SLP, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H;
    $liqDrop = 1.0 / $LEV - $MMR;
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        $s = 0; $cnt = 0;
        for ($k = $i - 11; $k <= $i; $k++) if ($k >= 0) { $s += $br[$k]; $cnt++; }
        if ($cnt == 0 || $s / $cnt < 0.5) { $i++; continue; }
        $e = $o[$i + 1];
        $notional = $MARGIN * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $liqPx = $avg * (1 - $liqDrop);
        $slPx = $avg * (1 - $SLP);
        $tgtPx = $avg * (1 + $TPR);
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;
        while ($j < $n) {
            $barsHeld = $j - $i;
            $stopPx = ($lossCut > 0) ? $avg * (1 - $lossCut / $notional) : 0;
            $hitLiq = $l[$j] <= $liqPx;
            $hitCut = ($lossCut > 0) && ($l[$j] <= $stopPx);
            $hitSl  = $l[$j] <= $slPx;
            $hitTp  = $h[$j] >= $tgtPx;
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; }
            if ($hitSl)  { $outcome = 'SL';  $exitPx = $slPx;  break; }
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $ADD * $LEV) / ($notional + $ADD * $LEV);
                $notional += $ADD * $LEV; $adds++;
                $fee += $ADD * $LEV * $FEE_TAKER;
                $liqPx = $avg * (1 - $liqDrop);
                $slPx = $avg * (1 - $SLP);
                $tgtPx = $avg * (1 + $TPR);
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }
        $gross = ($exitPx - $avg) * $notional / $avg;
        $funding = $notional * $FUND8H * ($barsHeld / 8);
        $pnl = $gross - $fee - $funding;
        $mg = $MARGIN + $adds * $ADD;
        if ($pnl < -$mg) $pnl = -$mg;
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,
                     'margin' => $mg, 'pnl' => round($pnl, 3)];
        $i = $j + 1;
    }
    return $trades;
}
function summ($trades) {
    $w = 0; $cut = 0; $sl = 0; $lq = 0; $to = 0; $pl = 0.0; $gross_win = 0.0; $gross_lose = 0.0;
    foreach ($trades as $t) {
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'CUT') $cut++;
        elseif ($t['out'] == 'SL') $sl++; elseif ($t['out'] == 'LIQ') $lq++; else $to++;
        $pl += $t['pnl'];
        if ($t['pnl'] >= 0) $gross_win += $t['pnl']; else $gross_lose += $t['pnl'];
    }
    $n = count($trades);
    return ['n' => $n, 'win' => $w, 'cut' => $cut, 'sl' => $sl, 'liq' => $lq, 'to' => $to,
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2),
            'gross_win' => round($gross_win, 2), 'gross_lose' => round($gross_lose, 2),
            'ev' => $n ? round($pl / $n, 3) : 0];
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

// ===== 强平档对比 + 主配置明细 =====
$cuts = [0, 0.5, 1, 2, 3];
$results = []; $bestTrades = null; $bestCut = null;
foreach ($cuts as $cut) {
    $tr = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $br1h, $cut);
    $sm = summ($tr);
    $results[] = ['cut' => $cut] + $sm;
    if ($bestCut === null || $sm['total'] > end($results)['total']) { $bestCut = $cut; $bestTrades = $tr; }
    echo "cut={$cut}U: " . json_encode($sm) . "\n"; flush();
}
usort($results, fn($a, $b) => $b['total'] <=> $a['total']);

// 主配置(用户原话: 同款配置100x1U) = cut=0 逐笔/每日
$main = null;
foreach ($results as $r) if ($r['cut'] == 0) $main = $r;

$cum = 0; $trOut = []; $daily = []; $no = 0;
foreach ($bestTrades as $t) {
    $no++; $cum += $t['pnl'];
    $d = date('Y-m-d', (int)($t['tout'] / 1000));
    $daily[$d] = ($daily[$d] ?? 0) + $t['pnl'];
    $trOut[] = ['no' => $no,
        'tin' => date('Y-m-d H:i', (int)($t['tin'] / 1000)), 'tout' => date('Y-m-d H:i', (int)($t['tout'] / 1000)),
        'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'pnl' => $t['pnl'],
        'cum' => round($cum, 2)];
}
ksort($daily);
$dayRows = []; $c2 = 0; $wd = 0; $ld = 0;
foreach ($daily as $d => $v) { $c2 += $v; if ($v >= 0) $wd++; else $ld++; $dayRows[] = ['d' => $d, 'pnl' => round($v, 2), 'cum' => round($c2, 2)]; }

$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;
$out = [
    'params' => ['lev' => 100, 'balance' => 500, 'margin' => 1, 'add' => 1, 'tp' => '价格+2%(ROI+200%)',
                 'liq' => '价格-0.6%爆仓归零', 'span' => round($spanDays) . '天',
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000)),
                 'dir' => '只买涨'],
    'main' => ['cut' => 0] + $main,
    'results' => $results,
    'best_cut' => $bestCut,
    'trades' => $trOut, 'daily' => $dayRows, 'win_days' => $wd, 'lose_days' => $ld,
];
file_put_contents('E:/finally-main/web/eth_100x1u_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK trades=" . count($trOut) . " days=" . count($dayRows) . "\n";
