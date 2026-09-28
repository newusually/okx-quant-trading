<?php
/**
 * eth_sl10_page.php — 生成「10x SL-3% TP+0.5% + taker」三个月全明细数据 (CLI, 纯PHP)
 * 输出: E:/finally-main/web/eth_sl10_data.json
 * 本金假设: 5000U(单笔最大保证金500+5轮加仓=3000U内, 留余量); 10x逐仓; 初始保证金率10%
 */
ini_set('memory_limit', '1024M');
set_time_limit(0);
$PRINCIPAL = 5000.0;
$MARGIN = 500.0; $ADD = 500.0; $MAXADD = 5;
$LEV = 10; $TPR = 0.005; $SLP = 0.03;
$TAKER_TH = 0.5;
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
function breadth($gridTs) {
    global $DB;
    $idx = array_flip($gridTs); $up = []; $tot = [];
    $tabs = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline%_1h'");
    foreach ($tabs as $t) {
        $r = $DB->query("SELECT candle_time, `c` FROM `{$t[0]}` ORDER BY candle_time ASC");
        if (!$r) continue;
        $win = []; $s = 0.0;
        while ($x = $r->fetch_row()) {
            $tt = (int)$x[0]; $cc = (float)$x[1];
            if (!isset($idx[$tt])) { $win = []; $s = 0.0; continue; }
            $win[] = $cc; $s += $cc;
            if (count($win) > 20) $s -= array_shift($win);
            if (count($win) == 20) {
                $ma = $s / 20;
                $up[$tt] = ($up[$tt] ?? 0) + ($cc >= $ma ? 1 : 0);
                $tot[$tt] = ($tot[$tt] ?? 0) + 1;
            }
        }
        $r->free();
    }
    $b = [];
    foreach ($gridTs as $tt) $b[$tt] = (($tot[$tt] ?? 0) > 10) ? ($up[$tt] ?? 0) / $tot[$tt] : null;
    return $b;
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
function sim($o, $h, $l, $c, $ts, $fib, $entry, $br, $takerTh, $margin, $lev, $tpr, $slp) {
    global $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $ADD, $MAXADD;
    $liqDrop = 1.0 / $lev - $MMR;
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        if ($takerTh !== null) {
            $s = 0; $cnt = 0;
            for ($k = $i - 11; $k <= $i; $k++) if ($k >= 0) { $s += $br[$k]; $cnt++; }
            if ($cnt == 0 || $s / $cnt < $takerTh) { $i++; continue; }
        }
        $e = $o[$i + 1];
        $notional = $margin * $lev; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $liqPx = $avg * (1 - $liqDrop);
        $slPx = $slp !== null ? $avg * (1 - $slp) : null;
        $tgtPx = $avg * (1 + $tpr);
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;
        while ($j < $n) {
            $barsHeld = $j - $i;
            if ($l[$j] <= $liqPx) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($slPx !== null && $l[$j] <= $slPx) { $outcome = 'SL'; $exitPx = $slPx; break; }
            if ($h[$j] >= $tgtPx) { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $ADD * $lev) / ($notional + $ADD * $lev);
                $notional += $ADD * $lev; $adds++;
                $fee += $ADD * $lev * $FEE_TAKER;
                $liqPx = $avg * (1 - $liqDrop);
                $slPx = $slp !== null ? $avg * (1 - $slp) : null;
                $tgtPx = $avg * (1 + $tpr);
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }
        $gross = ($exitPx - $avg) * $notional / $avg;
        $funding = $notional * $FUND8H * ($barsHeld / 8);
        $pnl = $gross - $fee - $funding;
        $mg = $margin + $adds * $ADD;
        if ($pnl < -$mg) $pnl = -$mg;
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,
                     'margin' => $mg, 'pnl' => round($pnl, 2), 'epx' => round($e, 2), 'xpx' => round($exitPx, 2),
                     'avg' => round($avg, 2), 'notional' => round($notional, 0), 'liqpx' => round($liqPx, 2), 'bars' => $barsHeld];
        $i = $j + 1;
    }
    return $trades;
}

echo "== load ==\n"; flush();
[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h');
[$ts4h, , , , $c4h, ] = load_k('eth', '4h');
[, , , , $cbtc1h, ] = load_k('btc', '1h');
echo "== breadth ==\n"; flush();
$brMkt = breadth($ts1h);
echo "== features ==\n"; flush();
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);
$ma5_4h = ma($c4h, 5);
$tr4hSrc = []; for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hSrc[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i];
$tr4h = ffill_map($ts1h, $ts4h, $tr4hSrc);
$pc = []; for ($i = 0; $i < count($cbtc1h); $i++) $pc[$i] = ($i >= 4 && $cbtc1h[$i - 4] != 0) ? $cbtc1h[$i] / $cbtc1h[$i - 4] - 1 : null;
$crashSrc = []; for ($i = 0; $i < count($cbtc1h); $i++) if ($pc[$i] !== null) $crashSrc[$ts1h[$i]] = $pc[$i] < -0.03;
$crash = ffill_map($ts1h, $ts1h, $crashSrc);
$entry = []; $n = count($ts1h);
for ($i = 0; $i < $n; $i++) {
    $t = $ts1h[$i]; $b = $brMkt[$t];
    $entry[$t] = ($b !== null && $b > 0.5 && $ma20[$i] !== null && $c1h[$i] > $ma20[$i]
        && $ma10[$i] !== null && $c1h[$i] > $ma10[$i] && !empty($tr4h[$t]) && empty($crash[$t]));
}
$fib = fibsig($c1h);
$br = buy_ratio_series($o1h, $h1h, $l1h, $c1h, $v1h);
echo "== sim ==\n"; flush();
$trades = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entry, $br, $TAKER_TH, $MARGIN, $LEV, $TPR, $SLP);
echo "trades=" . count($trades) . "\n";

// ===== 明细组装: 逐笔(本金/可用资金/保证金率) + 每日/每月 =====
$cum = 0.0;
$daily = []; $monthly = [];
$trOut = [];
$no = 0;
foreach ($trades as $t) {
    $no++;
    $cum += $t['pnl'];
    $equity = $PRINCIPAL + $cum;                      // 平仓后权益
    $avail = $equity;                                  // 平仓瞬间空仓, 可用=权益
    $d = date('Y-m-d', (int)($t['tout'] / 1000));
    $m = substr($d, 0, 7);
    $daily[$d] = ($daily[$d] ?? 0) + $t['pnl'];
    $monthly[$m] = ($monthly[$m] ?? 0) + $t['pnl'];
    $trOut[] = [
        'no' => $no,
        'tin' => date('Y-m-d H:i', (int)($t['tin'] / 1000)),
        'epx' => $t['epx'],
        'tout' => date('Y-m-d H:i', (int)($t['tout'] / 1000)),
        'xpx' => $t['xpx'],
        'out' => $t['out'], 'adds' => $t['adds'],
        'margin' => $t['margin'], 'notional' => $t['notional'],
        'mrate' => round(100 * $t['margin'] / $t['notional'], 1),
        'liqpx' => $t['liqpx'],
        'pnl' => $t['pnl'],
        'pnl_r' => round(100 * $t['pnl'] / $t['margin'], 1),
        'avail' => round($avail, 2),
        'cum' => round($cum, 2),
        'hold' => round($t['bars'] / 24 * 10) / 10,   // 天(1位小数)
    ];
}
ksort($daily); ksort($monthly);
$dayRows = []; $cum2 = 0; $wd = 0; $ld = 0;
foreach ($daily as $d => $v) {
    $cum2 += $v;
    $cnt = 0;
    foreach ($trades as $t) if (date('Y-m-d', (int)($t['tout'] / 1000)) == $d) $cnt++;
    if ($v >= 0) $wd++; else $ld++;
    $dayRows[] = ['d' => $d, 'n' => $cnt, 'pnl' => round($v, 2), 'cum' => round($cum2, 2)];
}
$totPnl = round($cum, 2);
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;
// K线数据(近30天) + 买卖标记
$kst = $ts1h[count($ts1h) - 1] - 30 * 86400000;
$kline = []; $marks = [];
for ($i = 0; $i < $n; $i++) {
    if ($ts1h[$i] < $kst) continue;
    $kline[] = [$ts1h[$i], $o1h[$i], $h1h[$i], $l1h[$i], $c1h[$i]];
}
foreach ($trades as $t) {
    if ($t['tin'] >= $kst) $marks[] = ['t' => $t['tin'], 'k' => 'buy'];
    if ($t['tout'] >= $kst) $marks[] = ['t' => $t['tout'], 'k' => $t['out'], 'pnl' => $t['pnl']];
}
$out = [
    'params' => ['principal' => $PRINCIPAL, 'margin' => $MARGIN, 'add' => $ADD, 'maxadd' => $MAXADD,
                 'lev' => $LEV, 'tp' => '价格+0.5%(收益率+5%)', 'sl' => '-3%(相对均价)', 'taker' => '前12根买比均值>0.5',
                 'mrate' => '10%(10x逐仓)', 'mmr' => '0.4%', 'liqdrop' => '-9.6%', 'span' => round($spanDays) . '天',
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000))],
    'summary' => ['n' => count($trades), 'total' => $totPnl,
                  'day_avg' => round($totPnl / max(1, $spanDays), 2),
                  'win_days' => $wd, 'lose_days' => $ld,
                  'final_eq' => round($PRINCIPAL + $cum, 2)],
    'trades' => $trOut, 'daily' => $dayRows, 'monthly' => $monthly,
    'kline' => $kline, 'marks' => $marks,
];
file_put_contents('E:/finally-main/web/eth_sl10_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK: trades=" . count($trOut) . " days=" . count($dayRows) . " total=$totPnl\n";
