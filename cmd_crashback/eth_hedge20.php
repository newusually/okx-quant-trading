<?php
/**
 * eth_hedge20.php — ETH模式 保证金20U起步+3U/天 × 对冲方案对比(CLI)
 * 方案:
 *   A0 纯多头(现行): 六重共振开多 + fib618仅上行加仓≤5轮 + TP+2% + 爆仓兜底
 *   B1 双向镜像: 多头=六重共振; 空头=镜像六重共振(1h<MA20/MA10 + 4h<MA5 + taker卖≥50%), 两腿独立
 *   B2 动态对冲(加仓减仓): 持多期间收盘跌破均价×(1-trig) → 开等额空单对冲;
 *      空头fib618顺势(下行)加仓≤5轮; 空头止盈=再跌1%(ROI+100%)落袋→可再开; 回升+0.2%小亏平空;
 *      对冲期间两腿合并计算组合爆仓(浮亏>总保证金才爆)
 * 共同: 保证金=20U+3U×已过天数 封顶余额/6, 每轮加仓=开仓时保证金, 100x逐仓, TP价格+2%
 * 输出: web/eth_hedge20_data.json
 */
ini_set('memory_limit', '1024M');
set_time_limit(0);
$BAL0 = 500.0;
$LEV = 100; $TPR = 0.02; $MAXADD = 5;
$MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$LADDER_BASE = 20.0; $LADDER_STEP = 3.0;

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
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h);
$fib = fibsig($c1h);

// ===== 信号: 多=六重共振(5关), 空=镜像 =====
$entryL = []; $entryS = [];
for ($i = 30; $i < $n; $i++) {
    $t = $ts1h[$i];
    $b = ffillBr($t);
    if ($b === null || $ma20[$i] === null || $ma10[$i] === null || !isset($tr4hUpF[$t])) continue;
    $brAvg = 0; $cnt = 0;
    for ($k = max(0, $i - 11); $k <= $i; $k++) { $brAvg += $br1h[$k]; $cnt++; }
    $brAvg /= $cnt;
    if ($b > 0.5 && $c1h[$i] > $ma20[$i] && $c1h[$i] > $ma10[$i] && $tr4hUpF[$t] && $brAvg >= 0.5) $entryL[$t] = true;
    if ($b > 0.5 && $c1h[$i] < $ma20[$i] && $c1h[$i] < $ma10[$i] && !$tr4hUpF[$t] && $brAvg < 0.5) $entryS[$t] = true;
}
echo "entryL=" . count($entryL) . " entryS=" . count($entryS) . "\n"; flush();

// ===== 信号频率: 每天信号根数(1h口径) =====
$daySig = [];
foreach ($entryL as $t => $_) { $d = date('Y-m-d', (int)($t / 1000)); $daySig[$d]['L'] = ($daySig[$d]['L'] ?? 0) + 1; }
foreach ($entryS as $t => $_) { $d = date('Y-m-d', (int)($t / 1000)); $daySig[$d]['S'] = ($daySig[$d]['S'] ?? 0) + 1; }
ksort($daySig);
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;
$last30 = array_slice($daySig, -30, null, true);
$last30Rows = [];
foreach ($last30 as $d => $v) $last30Rows[] = ['d' => $d, 'L' => $v['L'] ?? 0, 'S' => $v['S'] ?? 0];
$l30sum = 0; $l30max = 0;
foreach ($last30Rows as $r) { $l30sum += $r['L']; if ($r['L'] > $l30max) $l30max = $r['L']; }

// margin ladder
$startT = $ts1h[30];
function marginAt($t, $bal) {
    global $startT, $LADDER_BASE, $LADDER_STEP;
    $days = (int)floor((($t - $startT) / 1000) / 86400);
    $m = min($LADDER_BASE + $LADDER_STEP * $days, $bal / 6.0);
    return max(1.0, round($m, 2));
}

// ===== A0/B2: 多头(可带动态对冲) =====
function simLong($o, $h, $l, $c, $ts, $fib, $entry, $hedgeCfg) {
    global $LEV, $TPR, $MAXADD, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H;
    $liqDrop = 1.0 / $LEV - $MMR;
    $bal = 500.0;
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        $M0 = marginAt($ts[$i], $bal);
        $e = $o[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $liqPx = $avg * (1 - $liqDrop);
        $tgtPx = $avg * (1 + $TPR);
        // 对冲空头腿
        $hgOn = false; $hgAvg = 0; $hgNot = 0; $hgAdds = 0; $hgM0 = 0; $hgFee = 0;
        $hgTgt = 0; $hgExit = 0; $hgBars = 0; $hgOpens = 0;
        $trig = $hedgeCfg['trig'] ?? 0;
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;
        while ($j < $n) {
            $barsHeld = $j - $i;
            if ($hgOn) $hgBars++;
            $hitLiq = $l[$j] <= $liqPx;
            $hitTp  = $h[$j] >= $tgtPx;
            // 对冲管理(先于爆仓判定: 对冲激活=组合账户)
            if ($trig > 0) {
                if ($hgOn) {
                    // 空头止盈: 再跌1% → 落袋(可再开)
                    if ($l[$j] <= $hgTgt) {
                        $fee += $hgNot * $FEE_TAKER;
                        $bal += ($hgAvg - $hgTgt) * $hgNot / $hgAvg;   // 空头盈利
                        $bal -= $hgNot * $FUND8H * 0;                   // 空头收funding, 记到平仓统一算
                        $hgOn = false;
                    } elseif ($h[$j] >= $hgExit) {                     // 回升+0.2% 小亏平空
                        $fee += $hgNot * $FEE_TAKER;
                        $bal += ($hgAvg - $hgExit) * $hgNot / $hgAvg;  // 空头小亏
                        $hgOn = false;
                    } elseif (!empty($fib[$j]) && $c[$j] < $hgAvg && $hgAdds < 5 && $j + 1 < $n) {
                        $ap = $o[$j + 1];
                        $hgAvg = ($hgAvg * $hgNot + $ap * $hgM0 * $LEV) / ($hgNot + $hgM0 * $LEV);
                        $hgNot += $hgM0 * $LEV; $hgAdds++;
                        $fee += $hgM0 * $LEV * $FEE_TAKER;
                        $hgTgt = $hgAvg * (1 - 0.01);
                    }
                } elseif ($c[$j] < $avg * (1 - $trig) && $j + 1 < $n) {
                    $hgM0 = marginAt($ts[$j], $bal);
                    $hgAvg = $o[$j + 1]; $hgNot = $hgM0 * $LEV; $hgAdds = 0; $hgOpens++;
                    $fee += $hgNot * $FEE_TAKER;
                    $hgTgt = $hgAvg * (1 - 0.01); $hgExit = $hgAvg * (1 + 0.002);
                    $hgOn = true;
                }
            }
            if ($hgOn) {
                // 组合清算: 两腿浮亏 > 两腿总保证金×0.996 → 全爆
                $longU = ($c[$j] - $avg) * $notional / $avg;
                $shortU = ($hgAvg - $c[$j]) * $hgNot / $hgAvg;
                $mgTotal = $M0 * (1 + $adds) + $hgM0 * (1 + $hgAdds);
                if ($longU + $shortU <= -$mgTotal * 0.996) { $outcome = 'LIQ'; $exitPx = $c[$j]; break; }
            } elseif ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitTp) { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);
                $notional += $M0 * $LEV; $adds++;
                $fee += $M0 * $LEV * $FEE_TAKER;
                $liqPx = $avg * (1 - $liqDrop);
                $tgtPx = $avg * (1 + $TPR);
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }
        // 多头结算
        $gross = ($exitPx - $avg) * $notional / $avg;
        $funding = $notional * $FUND8H * ($barsHeld / 8);
        $pnl = $gross - $fee - $funding;
        $mg = $M0 * (1 + $adds);
        if ($pnl < -$mg) $pnl = -$mg;
        // 对冲腿随多头结束一并平掉(若有)
        $hgPnl = 0;
        if ($hgOn) {
            $hgGross = ($hgAvg - $exitPx) * $hgNot / $hgAvg;
            $hgPnl = $hgGross + $hgNot * $FUND8H * ($hgBars / 8);   // 空头收funding
            $bal += $hgPnl;
        }
        $bal = round($bal + $pnl, 2);
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,
                     'hg' => $hgOpens, 'margin' => round($mg + ($hgOn ? $hgM0 * (1 + $hgAdds) : 0), 2),
                     'pnl' => round($pnl + $hgPnl, 3), 'bal' => $bal];
        if ($bal <= 0) break;
        $i = $j + 1;
    }
    return [$trades, $bal];
}

// ===== B1: 空头独立(镜像) =====
function simShort($o, $h, $l, $c, $ts, $fib, $entry) {
    global $LEV, $TPR, $MAXADD, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H;
    $liqRise = 1.0 / $LEV - $MMR;
    $bal = 500.0;
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        $M0 = marginAt($ts[$i], $bal);
        $e = $o[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $liqPx = $avg * (1 + $liqRise);
        $tgtPx = $avg * (1 - $TPR);
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;
        while ($j < $n) {
            $barsHeld = $j - $i;
            $hitLiq = $h[$j] >= $liqPx;
            $hitTp  = $l[$j] <= $tgtPx;
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitTp) { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] < $avg) {
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);
                $notional += $M0 * $LEV; $adds++;
                $fee += $M0 * $LEV * $FEE_TAKER;
                $liqPx = $avg * (1 + $liqRise);
                $tgtPx = $avg * (1 - $TPR);
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }
        $gross = ($avg - $exitPx) * $notional / $avg;
        $funding = $notional * $FUND8H * ($barsHeld / 8);   // 空头收 funding
        $pnl = $gross + $funding - $fee;
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
    $w = 0; $lq = 0; $to = 0; $pl = 0.0; $cum = 0.0; $peak = 0.0; $maxDD = 0.0; $adds = 0; $hg = 0;
    foreach ($trades as $t) {
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'LIQ') $lq++; else $to++;
        $pl += $t['pnl']; $cum += $t['pnl']; $adds += $t['adds'];
        $hg += $t['hg'] ?? 0;
        if ($cum > $peak) $peak = $cum;
        if ($peak - $cum > $maxDD) $maxDD = $peak - $cum;
    }
    $n = count($trades);
    return ['n' => $n, 'win' => $w, 'liq' => $lq, 'to' => $to, 'adds' => $adds, 'hg' => $hg,
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2),
            'final_bal' => round($bal, 2), 'max_dd' => round($maxDD, 2),
            'ev' => $n ? round($pl / $n, 3) : 0];
}
function windowSum($trades, $days) {
    $cut = end($trades)['tout'] - $days * 86400000;
    $pl = 0; $w = 0; $nn = 0;
    foreach ($trades as $t) if ($t['tout'] >= $cut) { $nn++; $pl += $t['pnl']; if ($t['out'] == 'WIN') $w++; }
    return ['n' => $nn, 'win' => $w, 'win_r' => $nn ? round(100 * $w / $nn, 1) : 0, 'total' => round($pl, 2)];
}

echo "== sims ==\n"; flush();
$res = []; $detail = [];
// A0 纯多头
[$trA, $balA] = simLong($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryL, ['trig' => 0]);
$res[] = ['key' => 'A0', 'name' => 'A0 纯多头(现行)·20U+3U/天'] + summ($trA, $balA) + ['last30' => windowSum($trA, 30)];
$detail['A0'] = $trA;
echo "A0: " . json_encode($res[0], JSON_UNESCAPED_UNICODE) . "\n"; flush();
// B2 动态对冲 trig 档
foreach ([0.003, 0.005, 0.008] as $tg) {
    [$trB, $balB] = simLong($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryL, ['trig' => $tg]);
    $sm = summ($trB, $balB);
    $res[] = ['key' => 'B2_' . $tg, 'name' => "B2 动态对冲·跌破{$tg}%开空对冲"] + $sm + ['last30' => windowSum($trB, 30)];
    $detail['B2_' . $tg] = $trB;
    echo "B2_$tg: " . json_encode($sm, JSON_UNESCAPED_UNICODE) . "\n"; flush();
}
// B1 双向镜像
[$trL, $balL] = simLong($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryL, ['trig' => 0]);
[$trS, $balS] = simShort($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryS);
$merged = array_merge($trL, $trS);
usort($merged, fn($a, $b) => $a['tin'] <=> $b['tin']);
$smB1 = summ($merged, $balL + $balS - 500);
$res[] = ['key' => 'B1', 'name' => 'B1 双向镜像(多+空独立)'] + $smB1 + ['last30' => windowSum($merged, 30)];
$detail['B1'] = $merged;
echo "B1: " . json_encode($smB1, JSON_UNESCAPED_UNICODE) . "\n"; flush();

// 逐笔明细(A0 + 最优对冲档, 各取最后80笔)
function detailOut($tr, $k = 80) {
    $tr = array_slice($tr, -$k);
    $out = [];
    foreach ($tr as $t) $out[] = ['tin' => date('m-d H:i', (int)($t['tin'] / 1000)),
        'tout' => date('m-d H:i', (int)($t['tout'] / 1000)), 'out' => $t['out'], 'adds' => $t['adds'],
        'hg' => $t['hg'] ?? 0, 'margin' => $t['margin'], 'pnl' => $t['pnl'], 'bal' => $t['bal']];
    return $out;
}

$out = [
    'params' => ['lev' => 100, 'bal0' => 500, 'margin' => '20U+3U×已过天数, 封顶余额/6',
                 'add' => 'fib618顺势加仓≤5轮, 每轮=开仓时保证金', 'tp' => '价格+2%(ROI+200%)',
                 'hedge' => 'B2: 收盘跌破均价×(1-trig)→开等额空单; 空头fib618下行加仓≤5轮; 再跌1%止盈落袋; 回升+0.2%小亏平空; 对冲期间合并计算组合爆仓',
                 'span' => round($spanDays) . '天',
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000))],
    'signals' => ['total_L' => count($entryL), 'total_S' => count($entryS),
                  'per_day_L' => round(count($entryL) / $spanDays, 2),
                  'per_day_S' => round(count($entryS) / $spanDays, 2),
                  'last30_sum_L' => $l30sum, 'last30_max_L' => $l30max,
                  'last30_per_day_L' => round($l30sum / max(1, count($last30Rows)), 2),
                  'last30' => $last30Rows],
    'results' => $res,
    'detail_A0' => detailOut($detail['A0']),
    'detail_best' => detailOut($detail['B2_0.005']),
];
file_put_contents('E:/finally-main/web/eth_hedge20_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK\n";
