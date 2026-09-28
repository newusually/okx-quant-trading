<?php
/**
 * eth_fibreduce.php — 下跌按斐波那契回撤位减仓 对比回测(CLI)
 * 变体(均含50%兜底强平 + 六重共振只买涨 + TP+2% + 100x):
 *   A 现行: fib618仅上行加仓≤5轮, 下跌不减仓
 *   B 不加仓: 0轮加仓, 下跌也不减
 *   C 不加仓+fib减仓: 跌破回撤38.2%/50%/61.8% 各减当前持仓1/3(自开仓均价至入场后最高点的回撤)
 *   D 加仓+fib减仓: 上行fib618加仓 与 下跌fib减仓 同时启用(动态)
 *   E 不加仓+只在61.8减半: 单档减仓
 * fib减仓口径: hi=入场后最高价, hi≥均价×1.005 才有效; 档位=hi-(hi-均价)×r; 跌破→下一根开盘减仓
 * 输出: web/eth_fibreduce_data.json
 */
ini_set('memory_limit', '1024M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;

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

// ===== sim: addsOn(加仓轮数) redMode(0=不减 1=三档 2=单档61.8减半) =====
function sim($o, $h, $l, $c, $ts, $fib, $entry, $addsMax, $redMode, $lbase) {
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H;
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
        $notional = $M0 * $LEV; $notional0 = $notional; $avg = $e; $adds = 0; $reds = 0;
        $fee = $notional * $FEE_MAKER;
        $realized = 0.0; $funding = 0.0;
        $liqPx = $avg * (1 - $liqDrop);
        $tgtPx = $avg * (1 + $TPR);
        $stopPx = $avg * (1 - 0.5 / $LEV);              // 50%兜底(自设强平)
        $hi = $e; $usedLvl = [false, false, false];
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;
        while ($j < $n) {
            $barsHeld = $j - $i;
            $funding += $notional * $FUND8H / 8;         // 每1h根计提资金费
            $hitCut = $l[$j] <= $stopPx;
            $hitLiq = $l[$j] <= $liqPx;
            $hitTp  = $h[$j] >= $tgtPx;
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; }
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            // ---- 下跌 fib 减仓 ----
            if ($redMode > 0 && $notional > 0) {
                if ($h[$j] > $hi) $hi = $h[$j];
                if ($hi >= $avg * 1.005) {
                    $rng = $hi - $avg;
                    $levels = $redMode == 1 ? [0.382, 0.5, 0.618] : [0.618];
                    foreach ($levels as $k => $r) {
                        if ($usedLvl[$k]) continue;
                        $lvl = $hi - $rng * $r;
                        if ($l[$j] <= $lvl) {
                            $usedLvl[$k] = true;
                            $sellPx = $o[$j + 1] ?? $c[$j];
                            $sellNot = ($redMode == 1) ? $notional / 3.0 : $notional / 2.0;
                            $realized += ($sellPx - $avg) * $sellNot / $avg;
                            $fee += $sellNot * $FEE_TAKER;
                            $notional -= $sellNot; $reds++;
                            if ($notional < $notional0 * 0.02) { $outcome = 'FIB'; $exitPx = $sellPx; $notional = 0; break 2; }
                        }
                    }
                }
            }
            // ---- 上行 fib618 加仓 ----
            if ($addsMax > 0 && $adds < $addsMax && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {
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
        $pnl = $realized + $gross - $fee - $funding;
        $mg = $M0 * (1 + $adds);
        if ($pnl < -$mg) $pnl = -$mg;
        $bal = round($bal + $pnl, 2);
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds, 'reds' => $reds,
                     'margin' => $mg, 'pnl' => round($pnl, 3), 'bal' => $bal];
        if ($bal <= 0) break;
        $i = $j + 1;
    }
    return [$trades, $bal];
}
function summ($trades, $bal) {
    $w = 0; $cut = 0; $lq = 0; $fb = 0; $to = 0; $pl = 0.0; $cum = 0.0; $peak = 0.0; $maxDD = 0.0; $maxLoss = 0.0; $reds = 0;
    foreach ($trades as $t) {
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'CUT') $cut++; elseif ($t['out'] == 'LIQ') $lq++;
        elseif ($t['out'] == 'FIB') $fb++; else $to++;
        $reds += $t['reds'];
        $pl += $t['pnl']; $cum += $t['pnl'];
        if (-$t['pnl'] > $maxLoss) $maxLoss = -$t['pnl'];
        if ($cum > $peak) $peak = $cum;
        if ($peak - $cum > $maxDD) $maxDD = $peak - $cum;
    }
    $n = count($trades);
    return ['n' => $n, 'win' => $w, 'fibout' => $fb, 'cut' => $cut, 'liq' => $lq, 'reds' => $reds,
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2),
            'final_bal' => round($bal, 2), 'max_dd' => round($maxDD, 2),
            'max_loss' => round($maxLoss, 2), 'ev' => $n ? round($pl / $n, 3) : 0];
}

$variants = [
    ['a' => 5, 'r' => 0, 'name' => 'A 现行: 加仓≤5轮·不减仓'],
    ['a' => 0, 'r' => 0, 'name' => 'B 不加仓·不减仓'],
    ['a' => 0, 'r' => 1, 'name' => 'C 不加仓+fib三档减仓(38.2/50/61.8各减1/3)'],
    ['a' => 5, 'r' => 1, 'name' => 'D 加仓≤5轮+fib三档减仓(动态)'],
    ['a' => 0, 'r' => 2, 'name' => 'E 不加仓+只在61.8减半'],
];
$ladders = [['base' => 1.0, 'name' => '1U+3U/天'], ['base' => 20.0, 'name' => '20U+3U/天']];
$results = []; $detail = [];
foreach ($ladders as $ld) {
    foreach ($variants as $v) {
        [$tr, $bal] = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $v['a'], $v['r'], $ld['base']);
        $sm = summ($tr, $bal);
        $key = $ld['base'] . '_' . $v['a'] . '_' . $v['r'];
        $results[] = ['key' => $key, 'ladder' => $ld['name'], 'name' => $v['name']] + $sm;
        $detail[$key] = array_slice($tr, -50);
        echo "{$ld['name']} {$v['name']}: " . json_encode($sm, JSON_UNESCAPED_UNICODE) . "\n"; flush();
    }
}
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;
function detOut($tr) {
    $out = [];
    foreach ($tr as $t) $out[] = ['tin' => date('m-d H:i', (int)($t['tin'] / 1000)),
        'tout' => date('m-d H:i', (int)($t['tout'] / 1000)), 'out' => $t['out'], 'adds' => $t['adds'],
        'reds' => $t['reds'], 'margin' => $t['margin'], 'pnl' => $t['pnl'], 'bal' => $t['bal']];
    return $out;
}
$out = [
    'params' => ['lev' => 100, 'bal0' => 500, 'tp' => '价格+2%', 'backstop' => '自设强平兜底50%保证金',
                 'fibReduce' => 'hi=入场后最高价, hi≥均价1.005%生效; 档位=hi-(hi-均价)×回撤比; 跌破→下一根开盘减当前量1/3(或61.8减半)',
                 'span' => round($spanDays) . '天',
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000))],
    'results' => $results,
    'detail_C' => detOut($detail['1_0_1']), 'detail_A' => detOut($detail['1_5_0']),
];
file_put_contents('E:/finally-main/web/eth_fibreduce_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK\n";
