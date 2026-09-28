<?php
/**
 * eth_sweep.php — 一年参数矩阵扫描(CLI): 找更高利润/更多交易/胜率20%即可的配置
 * 维度: 亏X美金强平(0=无,10,30,50,100,150,200,250,300) × 止盈价格(0.5%,1%,1.5%,2%,3%)
 *       × fib618加仓(开/关) × 入场(六重共振/仅多周期/多周期+多策略)
 * 固定: 10x逐仓 500U/笔 只做多 SL-3%(相对均价) 资金费/手续费全算
 * 输出: web/eth_sweep_data.json
 */
ini_set('memory_limit', '1024M');
set_time_limit(0);
$PRINCIPAL = 5000.0; $MARGIN = 500.0; $ADD = 500.0; $MAXADD = 5;
$LEV = 10; $SLP = 0.03;
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
/**
 * sim — 只做多
 * $entry: [ts=>true]; $takerTh: null=不过滤; $lossCut: 0=无美金强平, >0=浮亏达X美金立即平
 * $tpR: 止盈价格涨幅; $addon: fib618加仓开关
 */
function sim($o, $h, $l, $c, $ts, $fib, $entry, $br, $takerTh, $lossCut, $tpR, $addon) {
    global $MARGIN, $ADD, $MAXADD, $LEV, $SLP, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H;
    $liqDrop = 1.0 / $LEV - $MMR;
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        if ($takerTh !== null) {
            $s = 0; $cnt = 0;
            for ($k = $i - 11; $k <= $i; $k++) if ($k >= 0) { $s += $br[$k]; $cnt++; }
            if ($cnt == 0 || $s / $cnt < $takerTh) { $i++; continue; }
        }
        $e = $o[$i + 1];
        $notional = $MARGIN * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $liqPx = $avg * (1 - $liqDrop);
        $slPx = $avg * (1 - $SLP);
        $tgtPx = $avg * (1 + $tpR);
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;
        while ($j < $n) {
            $barsHeld = $j - $i;
            $stopPx = ($lossCut > 0) ? $avg * (1 - $lossCut / $notional) : 0;
            $hitLiq = $l[$j] <= $liqPx;
            $hitCut = ($lossCut > 0) && ($l[$j] <= $stopPx);
            $hitSl  = $l[$j] <= $slPx;
            $hitTp  = $h[$j] >= $tgtPx;
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; }   // 亏X美金强平
            if ($hitSl)  { $outcome = 'SL';  $exitPx = $slPx;  break; }
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($addon && $adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $ADD * $LEV) / ($notional + $ADD * $LEV);
                $notional += $ADD * $LEV; $adds++;
                $fee += $ADD * $LEV * $FEE_TAKER;
                $liqPx = $avg * (1 - $liqDrop);
                $slPx = $avg * (1 - $SLP);
                $tgtPx = $avg * (1 + $tpR);
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
                     'margin' => $mg, 'pnl' => round($pnl, 2)];
        $i = $j + 1;
    }
    return $trades;
}
function summ($trades) {
    $w = 0; $cut = 0; $sl = 0; $lq = 0; $to = 0; $pl = 0.0;
    foreach ($trades as $t) {
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'CUT') $cut++;
        elseif ($t['out'] == 'SL') $sl++; elseif ($t['out'] == 'LIQ') $lq++; else $to++;
        $pl += $t['pnl'];
    }
    $n = count($trades);
    return ['n' => $n, 'win' => $w, 'cut' => $cut, 'sl' => $sl, 'liq' => $lq, 'to' => $to,
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2),
            'ev' => $n ? round($pl / $n, 2) : 0];
}

echo "== load data ==\n"; flush();
[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h');
[$ts4h, , , , $c4h, ] = load_k('eth', '4h');
$n = count($ts1h);
echo "1h bars=$n (" . date('Y-m-d', (int)($ts1h[0] / 1000)) . " ~ " . date('Y-m-d', (int)(end($ts1h) / 1000)) . ")\n"; flush();

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

// 三种入场口径
$entryA = []; $entryB = []; $entryC = [];   // A六重共振 B仅多周期 C多周期+多策略(fib618并入)
for ($i = 30; $i < $n; $i++) {
    $t = $ts1h[$i];
    $b = ffillBr($t);
    $u20 = ($ma20[$i] !== null && $c1h[$i] > $ma20[$i]);
    $u10 = ($ma10[$i] !== null && $c1h[$i] > $ma10[$i]);
    $tr4 = !empty($tr4hUpF[$t]);
    // A 六重共振: 宽度>0.5 + 1h MA10/MA20多头 + 4h趋势多 + 非熔断
    if ($b !== null && $b > 0.5 && $u20 && $u10 && $tr4) $entryA[$t] = true;
    // B 仅多周期: 1h MA10/MA20 多头 + 4h趋势多 (无宽度/无taker)
    if ($u20 && $u10 && $tr4) $entryB[$t] = true;
    // C 多周期+多策略: B 或 fib618信号(非熔断时)
    if (isset($entryB[$t]) || (!empty($fib[$i]) && $u20)) $entryC[$t] = true;
}
echo "entryA=" . count($entryA) . " B=" . count($entryB) . " C=" . count($entryC) . "\n"; flush();

// ===== 矩阵扫描 =====
$cuts = [0, 10, 30, 50, 100, 150, 200, 250, 300];
$tps  = [0.005, 0.01, 0.015, 0.02, 0.03];
$ents = ['A' => [$entryA, 0.5, '六重共振(宽度+趋势+taker)'],
         'B' => [$entryB, null, '仅多周期(1h双均线+4h趋势)'],
         'C' => [$entryC, null, '多周期+多策略(多周期∪fib618)']];
$results = [];
$bestTrades = null; $bestKey = null;
foreach ($ents as $ek => [$emap, $tth, $ename]) {
    foreach ($tps as $tpR) {
        foreach ([1, 0] as $adOn) {
            foreach ($cuts as $cut) {
                $tr = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $emap, $br1h, $tth, $cut, $tpR, $adOn);
                $sm = summ($tr);
                $key = "{$ek}|tp{$tpR}|ad{$adOn}|cut{$cut}";
                $results[$key] = ['ent' => $ek, 'entName' => $ename, 'tp' => round($tpR * 100, 1),
                                  'addon' => $adOn, 'cut' => $cut] + $sm;
                if ($bestKey === null || $sm['total'] > $results[$bestKey]['total']) { $bestKey = $key; $bestTrades = $tr; }
            }
        }
        echo "done ent=$ek tp=" . ($tpR * 100) . "% (" . count($results) . " 组合)\n"; flush();
    }
}
uasort($results, fn($a, $b) => $b['total'] <=> $a['total']);
$results = array_values($results);
echo "BEST: " . json_encode($results[0]) . "\n"; flush();

// 最优配置的明细(每日+逐笔)
$cum = 0; $trOut = []; $daily = []; $no = 0;
foreach ($bestTrades as $t) {
    $no++; $cum += $t['pnl'];
    $d = date('Y-m-d', (int)($t['tout'] / 1000));
    $daily[$d] = ($daily[$d] ?? 0) + $t['pnl'];
    $trOut[] = ['no' => $no,
        'tin' => date('Y-m-d H:i', (int)($t['tin'] / 1000)), 'tout' => date('Y-m-d H:i', (int)($t['tout'] / 1000)),
        'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'pnl' => $t['pnl'],
        'pnl_r' => round(100 * $t['pnl'] / $t['margin'], 1), 'cum' => round($cum, 2)];
}
ksort($daily);
$dayRows = []; $c2 = 0;
foreach ($daily as $d => $v) { $c2 += $v; $dayRows[] = ['d' => $d, 'pnl' => round($v, 2), 'cum' => round($c2, 2)]; }

$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;
$out = [
    'params' => ['lev' => 10, 'margin' => 500, 'sl' => '±3%(相对均价)', 'span' => round($spanDays) . '天',
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000)),
                 'dir' => '只做多(空头已证负期望)'],
    'best' => ['key' => $bestKey] + $results[0],
    'results' => $results,
    'best_daily' => $dayRows,
    'best_trades' => $trOut,
];
file_put_contents('E:/finally-main/web/eth_sweep_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK combos=" . count($results) . " bestTrades=" . count($trOut) . " days=" . count($dayRows) . "\n";
