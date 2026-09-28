<?php
/**
 * eth_top10.php — 排行榜前10盈利合约 × 保证金1U起步每天+1U 阶梯 回测(CLI)
 * 策略 = A方案同参: 六重共振全命中买入 / fib618仅上行加仓≤5轮(每轮=开仓保证金) / 下跌不减仓
 *         止盈价格+2% / 50%兜底强平(浮亏达该笔总保证金(含加仓)×50%市价全平)
 * 保证金: M = min(1U + 1U×已过天数, 500/6)
 * 前10合约来源: eth_allmarket_data.json 排行(1U+3U/天 档 top10)
 * 输出: web/eth_top10_data.json
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$BAL0 = 500.0; $CAP = $BAL0 / 6.0; $BASE = 1.0; $STEP = 1.0;

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
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");
    $ts = []; $o = []; $h = []; $l = []; $c = [];
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }
    return [$ts, $o, $h, $l, $c];
}
function buy_ratio_series($o, $h, $l, $c) {
    $n = count($c); $br = [];
    for ($i = 0; $i < $n; $i++) {
        $rng = $h[$i] - $l[$i];
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;
        $br[$i] = 0.5 + $dir / 2;
    }
    return $br;
}

// 前10合约
$prev = json_decode(file_get_contents('E:/finally-main/web/eth_allmarket_data.json'), true);
$top10 = array_slice(array_column($prev['rank']['1U+3U/天']['top'], 'inst'), 0, 10);
$top10 = array_map('strtolower', $top10);
echo "top10=" . implode(',', $top10) . "\n"; flush();

// ① 宽度
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
// ⑤ 熔断
[$tsB, , , , $cB] = load_k('btc', '1h');
$meltSet = [];
for ($i = 4; $i < count($cB); $i++) {
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;
    }
}

function sim($o, $h, $l, $c, $ts, $fib, $entry) {
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP, $BASE, $STEP;
    $liqDrop = 1.0 / $LEV - $MMR;
    $startT = $ts[30];
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400);
        $M = round(min($BASE + $STEP * $days, $CAP), 2);
        if ($M < 1) $M = 1.0;
        $M0 = $M;
        $e = $o[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $funding = 0.0;
        $liqPx = $avg * (1 - $liqDrop);
        $tgtPx = $avg * (1 + $TPR);
        $stopPx = $avg * (1 - 0.5 / $LEV);
        $outcome = null; $exitPx = null; $j = $i + 1;
        while ($j < $n) {
            $barsHeld = $j - $i;
            $funding += $notional * $FUND8H / 8;
            $hitCut = $l[$j] <= $stopPx;
            $hitLiq = $l[$j] <= $liqPx;
            $hitTp  = $h[$j] >= $tgtPx;
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; }
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < 5 && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);
                $notional += $M0 * $LEV; $adds++;
                $fee += $M0 * $LEV * $FEE_TAKER;
                $liqPx = $avg * (1 - $liqDrop);
                $tgtPx = $avg * (1 + $TPR);
                $stopPx = $avg * (1 - 0.5 / $LEV);
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }
        $gross = ($exitPx - $avg) * $notional / $avg;
        $pnl = $gross - $fee - $funding;
        $mg = $M0 * (1 + $adds);
        if ($pnl < -$mg) $pnl = -$mg;
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,
                     'margin' => $mg, 'pnl' => round($pnl, 3)];
        $i = $j + 1;
    }
    return $trades;
}

$perContract = []; $daily = []; $allTrades = [];
foreach ($top10 as $inst) {
    [$ts1h, $o1h, $h1h, $l1h, $c1h] = load_k($inst, '1h');
    $n = count($ts1h);
    if ($n < 200) continue;
    [$ts4h, , , , $c4h] = load_k($inst, '4h');
    $ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);
    $ma5_4h = ma($c4h, 5);
    $tr4hUpF = []; $p4 = 0; $cur4 = null; $n4 = count($ts4h);
    foreach ($ts1h as $tt) {
        while ($p4 < $n4 && $ts4h[$p4] <= $tt) { if ($ma5_4h[$p4] !== null) $cur4 = $c4h[$p4] > $ma5_4h[$p4]; $p4++; }
        $tr4hUpF[$tt] = $cur4;
    }
    $br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h);
    $fib = fibsig($c1h);
    $entryA = [];
    for ($i = 30; $i < $n; $i++) {
        $t = $ts1h[$i];
        if (!empty($meltSet[$t])) continue;
        $b = ffillBr($t);
        if ($b === null || $b <= 0.5) continue;
        if ($ma20[$i] === null || $c1h[$i] <= $ma20[$i]) continue;
        if ($ma10[$i] === null || $c1h[$i] <= $ma10[$i]) continue;
        if (empty($tr4hUpF[$t])) continue;
        if ($br1h[$i] < 0.5) continue;
        $entryA[$t] = true;
    }
    $tr = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA);
    $pl = 0.0; $w = 0; $cut = 0; $adds = 0; $mg = 0.0;
    foreach ($tr as $x) {
        $pl += $x['pnl']; $mg += $x['margin']; $adds += $x['adds'];
        if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'CUT') $cut++;
        $d = date('Y-m-d', (int)($x['tout'] / 1000));
        $daily[$d] = ($daily[$d] ?? 0) + $x['pnl'];
    }
    $cnt = count($tr);
    $perContract[] = ['inst' => strtoupper($inst), 'n' => $cnt, 'win' => $w, 'cut' => $cut,
                      'wr' => $cnt ? round(100 * $w / $cnt, 1) : 0, 'adds' => $adds,
                      'avg_margin' => $cnt ? round($mg / $cnt, 2) : 0, 'pnl' => round($pl, 2),
                      'ev' => $cnt ? round($pl / $cnt, 2) : 0];
    foreach ($tr as $x) { $x['inst'] = strtoupper($inst); $allTrades[] = $x; }
    echo "$inst done n=$cnt pnl=" . round($pl, 2) . "\n"; flush();
    unset($ts1h, $o1h, $h1h, $l1h, $c1h, $ts4h, $c4h, $ma20, $ma10, $ma5_4h, $tr4hUpF, $br1h, $fib, $entryA);
}

// 日收益序列 + 权益曲线
$days = array_keys($daily); sort($days);
$eq = $BAL0; $peak = $BAL0; $maxDD = 0.0; $dailyArr = [];
foreach ($days as $d) {
    $eq += $daily[$d]; if ($eq > $peak) $peak = $eq; if ($peak - $eq > $maxDD) $maxDD = $peak - $eq;
    $dailyArr[] = ['d' => $d, 'pnl' => round($daily[$d], 2), 'eq' => round($eq, 2)];
}
// 月度汇总
$monthly = []; $monthDays = [];
foreach ($dailyArr as $r) {
    $m = substr($r['d'], 0, 7);
    $monthly[$m] = ($monthly[$m] ?? 0) + $r['pnl'];
    $monthDays[$m] = ($monthDays[$m] ?? 0) + 1;
}
$monthlyArr = [];
foreach ($monthly as $m => $v) $monthlyArr[] = ['m' => $m, 'days' => $monthDays[$m], 'pnl' => round($v, 2),
    'avg' => round($v / max(1, $monthDays[$m]), 2)];
usort($monthlyArr, function ($a, $b) { return strcmp($a['m'], $b['m']); });

$total = array_sum(array_column($perContract, 'pnl'));
$nAll = array_sum(array_column($perContract, 'n'));
$out = [
    'params' => ['lev' => 100, 'bal0' => $BAL0, 'margin' => '每笔=1U+1U×已过天数, 封顶500/6; 加仓每轮=开仓时保证金',
        'backstop' => '50%兜底强平: 浮亏达到该笔总保证金(含加仓)×50% → 立即市价全平',
        'top10' => array_map('strtoupper', $top10), 'note' => '各合约独立持仓(同合约同时1仓), 收益按日聚合'],
    'perContract' => $perContract,
    'total' => ['pnl' => round($total, 2), 'n' => $nAll,
        'span_days' => count($dailyArr), 'avg_daily' => round($total / max(1, count($dailyArr)), 2),
        'max_dd' => round($maxDD, 2), 'final_eq' => round($eq, 2)],
    'monthly' => $monthlyArr, 'daily' => $dailyArr,
];
file_put_contents('E:/finally-main/web/eth_top10_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK total=" . round($total, 2) . " days=" . count($dailyArr) . " avg/day=" . round($total / max(1, count($dailyArr)), 2) . " maxDD=" . round($maxDD, 2) . "\n";
