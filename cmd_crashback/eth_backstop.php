<?php
/**
 * eth_backstop.php — 自设强平兜底比例 回测(CLI)
 * 机制: 浮亏达 该笔总保证金(含加仓)×frac → 市价全平(抢在交易所强平线~60%之前)
 *   frac=0    现行(只靠交易所爆仓兜底, 亏~60%保证金被强平)
 *   frac=0.5  引擎新兜底(保证单笔最终亏损 ≤ 该笔保证金)
 * 价格口径: 亏损/总保证金 = 跌幅×杠杆 → 强平价 = 均价×(1 - frac/LEV), 与加仓轮数无关(比例恒定)
 * 阶梯两套: 1U+3U/天(引擎现行) 与 20U+3U/天, 均封顶余额/6, 每轮加仓=开仓时保证金
 * 输出: web/eth_backstop_data.json
 */
ini_set('memory_limit', '1024M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $MAXADD = 5;
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

[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h');
[$ts4h, , , , $c4h, ] = load_k('eth', '4h');
$n = count($ts1h);

// breadth
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
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);
$ma5_4h = ma($c4h, 5);
$tr4hUp = [];
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hUp[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i];
$tr4hUpF = []; $p4 = 0; $cur4 = null; $n4 = count($ts4h);
foreach ($ts1h as $tt) { while ($p4 < $n4 && $ts4h[$p4] <= $tt) { $cur4 = $tr4hUp[$ts4h[$p4]] ?? $cur4; $p4++; } $tr4hUpF[$tt] = $cur4; }
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h);
$fib = fibsig($c1h);
$entryA = [];
for ($i = 30; $i < $n; $i++) {
    $t = $ts1h[$i];
    $b = ffillBr($t);
    if ($b !== null && $b > 0.5 && $ma20[$i] !== null && $c1h[$i] > $ma20[$i] &&
        $ma10[$i] !== null && $c1h[$i] > $ma10[$i] && !empty($tr4hUpF[$t])) $entryA[$t] = true;
}
echo "entry=" . count($entryA) . "\n"; flush();

// ===== sim: frac=自设强平比例(0=不启用) =====
function sim($o, $h, $l, $c, $ts, $fib, $entry, $frac, $lbase) {
    global $LEV, $TPR, $MAXADD, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H;
    $liqDrop = 1.0 / $LEV - $MMR;
    $bal = 500.0;
    $startT = $ts[30];
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400);
        $M = round(min($lbase + 3.0 * $days, $bal / 6.0), 2);
        if ($M < 1) $M = 1.0;
        $M0 = $M;
        $e = $o[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $liqPx = $avg * (1 - $liqDrop);
        $tgtPx = $avg * (1 + $TPR);
        $stopPx = ($frac > 0) ? $avg * (1 - $frac / $LEV) : 0;
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;
        while ($j < $n) {
            $barsHeld = $j - $i;
            $hitCut = ($frac > 0) && ($l[$j] <= $stopPx);
            $hitLiq = $l[$j] <= $liqPx;
            $hitTp  = $h[$j] >= $tgtPx;
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; }   // 自设强平先判(抢在交易所线前)
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);
                $notional += $M0 * $LEV; $adds++;
                $fee += $M0 * $LEV * $FEE_TAKER;
                $liqPx = $avg * (1 - $liqDrop);
                $tgtPx = $avg * (1 + $TPR);
                if ($frac > 0) $stopPx = $avg * (1 - $frac / $LEV);
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }
        $gross = ($exitPx - $avg) * $notional / $avg;
        $funding = $notional * $FUND8H * ($barsHeld / 8);
        $pnl = $gross - $fee - $funding;
        $mg = $M0 * (1 + $adds);
        if ($pnl < -$mg) $pnl = -$mg;
        $bal = round($bal + $pnl, 2);
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,
                     'margin' => $mg, 'pnl' => round($pnl, 3), 'bal' => $bal];
        if ($bal <= 0) break;
        $i = $j + 1;
    }
    return [$trades, $bal];
}
function summ($trades, $bal) {
    $w = 0; $cut = 0; $lq = 0; $pl = 0.0; $cum = 0.0; $peak = 0.0; $maxDD = 0.0; $maxLoss = 0.0;
    foreach ($trades as $t) {
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'CUT') $cut++; elseif ($t['out'] == 'LIQ') $lq++;
        $pl += $t['pnl']; $cum += $t['pnl'];
        if (-$t['pnl'] > $maxLoss) $maxLoss = -$t['pnl'];
        if ($cum > $peak) $peak = $cum;
        if ($peak - $cum > $maxDD) $maxDD = $peak - $cum;
    }
    $n = count($trades);
    return ['n' => $n, 'win' => $w, 'cut' => $cut, 'liq' => $lq,
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2),
            'final_bal' => round($bal, 2), 'max_dd' => round($maxDD, 2),
            'max_loss' => round($maxLoss, 2), 'ev' => $n ? round($pl / $n, 3) : 0];
}

// ===== 跑矩阵: 阶梯(1U/20U) × frac 档 =====
$fracs = [0, 0.4, 0.5, 0.55, 0.7];
$ladders = [['base' => 1.0, 'name' => '1U+3U/天(引擎现行)'], ['base' => 20.0, 'name' => '20U+3U/天']];
$results = []; $detail = [];
foreach ($ladders as $ld) {
    foreach ($fracs as $fr) {
        [$tr, $bal] = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $fr, $ld['base']);
        $sm = summ($tr, $bal);
        $key = $ld['base'] . '_' . $fr;
        $results[] = ['key' => $key, 'ladder' => $ld['name'], 'frac' => $fr] + $sm;
        $detail[$key] = array_slice($tr, -60);
        echo "{$ld['name']} frac=$fr: " . json_encode($sm, JSON_UNESCAPED_UNICODE) . "\n"; flush();
    }
}
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;
function detOut($tr) {
    $out = [];
    foreach ($tr as $t) $out[] = ['tin' => date('m-d H:i', (int)($t['tin'] / 1000)),
        'tout' => date('m-d H:i', (int)($t['tout'] / 1000)), 'out' => $t['out'], 'adds' => $t['adds'],
        'margin' => $t['margin'], 'pnl' => $t['pnl'], 'bal' => $t['bal']];
    return $out;
}
$out = [
    'params' => ['lev' => 100, 'bal0' => 500, 'tp' => '价格+2%', 'add' => 'fib618仅上行≤5轮每轮=开仓保证金',
                 'rule' => '自设强平兜底: 浮亏达该笔总保证金×frac → 市价全平; 交易所强平线≈亏60%保证金(价格-0.6%)',
                 'span' => round($spanDays) . '天',
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000))],
    'results' => $results,
    'detail_1_0' => detOut($detail['1_0']), 'detail_1_0.5' => detOut($detail['1_0.5']),
];
file_put_contents('E:/finally-main/web/eth_backstop_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK\n";
