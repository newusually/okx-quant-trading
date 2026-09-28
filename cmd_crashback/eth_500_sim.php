<?php
/**
 * eth_500_sim.php — 500U 仓位 ETH 100x/20x 爆仓测算 + 数月历史回测 + SVG 图表 (纯PHP)
 * 用户方案: 六重共振入场 / 每笔500U / 无止损 / ROI+20%止盈(价格+0.2%从均价) / fib618加仓5轮每次500U
 * 输出: E:/datas/log/eth_500_report.html
 */
ini_set('memory_limit', '1024M');
set_time_limit(0);

$MARGIN = 500.0; $ADD = 500.0; $MAXADD = 5;
$TPR = 0.002;                 // 止盈: 价格+0.2% => ROI+20%(100x)
$MMR = 0.004;                 // 维持保证金率
$FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$TIMEOUT = 7 * 24;            // 7天兜底(1h根数)

function db() {
    $m = new mysqli('127.0.0.1', 'root', '', 'trading');
    $m->set_charset('utf8mb4');
    return $m;
}
$DB = db();

function rows($sql) {
    global $DB;
    $r = $DB->query($sql);
    $out = [];
    while ($x = $r->fetch_row()) $out[] = $x;
    $r->free();
    return $out;
}
function col1($sql) {
    global $DB;
    $r = $DB->query($sql);
    $x = $r->fetch_row();
    $r->free();
    return $x ? $x[0] : null;
}

// ---------- 工具 ----------
function ma($a, $n) { // 简单均线数组(前n-1为null)
    $out = []; $s = 0.0;
    for ($i = 0; $i < count($a); $i++) {
        $s += $a[$i];
        if ($i >= $n) $s -= $a[$i - $n];
        $out[$i] = ($i >= $n - 1) ? $s / $n : null;
    }
    return $out;
}
function fibsig($c, $w = 30) { // fib_618: 30根窗口 0.618回撤位±3%, 信号在"该根收盘"判定
    $n = count($c); $out = array_fill(0, $n, false);
    for ($i = $w - 1; $i < $n; $i++) {
        $lo = INF; $hi = -INF;
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }
        if ($hi <= $lo) continue;
        $fib = $hi - ($hi - $lo) * 0.618;
        $last = $c[$i];
        if ($fib * 0.97 <= $last && $last <= $fib * 1.03) $out[$i] = true;
    }
    return $out;
}
// 全市场宽度(流式): suffix='1h'/'15m', 主网格=ETH对应周期ts
function breadth($gridTs, $suffix) {
    global $DB;
    $idx = array_flip($gridTs);
    $up = []; $tot = [];
    $tabs = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline%_$suffix'");
    $k = 0;
    foreach ($tabs as $t) {
        $tb = $t[0];
        $r = $DB->query("SELECT candle_time, `c` FROM `$tb` ORDER BY candle_time ASC");
        if (!$r) continue;
        $win = []; $s = 0.0;
        while ($x = $r->fetch_row()) {
            $tt = (int)$x[0]; $cc = (float)$x[1];
            if (!isset($idx[$tt])) { $win = []; $s = 0.0; continue; } // 跳格重置窗口
            $win[] = $cc; $s += $cc;
            if (count($win) > 20) { $s -= array_shift($win); }
            if (count($win) == 20) {
                $ma = $s / 20;
                $up[$tt] = ($up[$tt] ?? 0) + ($cc >= $ma ? 1 : 0);
                $tot[$tt] = ($tot[$tt] ?? 0) + 1;
            }
        }
        $r->free();
        if ((++$k % 150) == 0) { echo "  breadth[$suffix] $k/" . count($tabs) . "\n"; flush(); }
    }
    $b = [];
    foreach ($gridTs as $tt) $b[$tt] = (($tot[$tt] ?? 0) > 10) ? ($up[$tt] ?? 0) / $tot[$tt] : null;
    return $b;
}
// 低周期标志 → 主网格前值填充
function ffill_map($gridTs, $srcTs, $srcVal) {
    $out = []; $p = 0; $cur = null; $n = count($srcTs);
    foreach ($gridTs as $tt) {
        while ($p < $n && $srcTs[$p] <= $tt) { $cur = $srcVal[$srcTs[$p]] ?? $cur; $p++; }
        $out[$tt] = $cur;
    }
    return $out;
}
function pctchg($a, $n) {
    $out = [];
    for ($i = 0; $i < count($a); $i++) $out[$i] = ($i >= $n && $a[$i - $n] != 0) ? $a[$i] / $a[$i - $n] - 1 : null;
    return $out;
}
function corr($x, $y) {
    $n = count($x); if ($n < 3) return 0;
    $mx = array_sum($x) / $n; $my = array_sum($y) / $n;
    $sxy = $sxx = $syy = 0;
    for ($i = 0; $i < $n; $i++) { $dx = $x[$i] - $mx; $dy = $y[$i] - $my; $sxy += $dx * $dy; $sxx += $dx * $dx; $syy += $dy * $dy; }
    return ($sxx > 0 && $syy > 0) ? $sxy / sqrt($sxx * $syy) : 0;
}

// ---------- 加载 ETH 多周期 ----------
function load_k($inst, $bar) {
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");
    $ts = []; $o = []; $h = []; $l = []; $c = [];
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }
    return [$ts, $o, $h, $l, $c];
}
echo "== load ETH/BTC klines ==\n"; flush();
[$ts15, $o15, $h15, $l15, $c15] = load_k('eth', '15m');
[$ts1h, $o1h, $h1h, $l1h, $c1h] = load_k('eth', '1h');
[$ts4h, , , , $c4h] = load_k('eth', '4h');
[$ts5m, , , , $c5m] = load_k('eth', '5m');
[$ts3m, , , , $c3m] = load_k('eth', '3m');
[, , , , $cbtc15] = load_k('btc', '15m');
[, , , , $cbtc1h] = load_k('btc', '1h');
$lastPx = $c15[count($c15) - 1];
echo "ETH 15m: " . count($c15) . " bars, last=$lastPx ; ETH 1h: " . count($c1h) . " bars\n"; flush();

// ---------- 500U 爆仓表 ----------
$levs = [100, 50, 20, 10, 8];
$liqRows = [];
foreach ($levs as $lv) {
    $drop = 1.0 / $lv - $MMR;
    $notional = $MARGIN * $lv;
    $liqRows[] = [$lv, $notional, $drop * 100, $lastPx * $drop, $lastPx * (1 - $drop), $notional * $drop];
}

// ---------- 共振特征 ----------
echo "== features ==\n"; flush();
$ma20_15 = ma($c15, 20);
$ethUp15 = [];
for ($i = 0; $i < count($c15); $i++) $ethUp15[$ts15[$i]] = ($ma20_15[$i] !== null && $c15[$i] > $ma20_15[$i]);
$pcBTC15 = pctchg($cbtc15, 4);
$crash15 = []; for ($i = 0; $i < count($cbtc15); $i++) $crash15[$ts15[$i]] = ($pcBTC15[$i] !== null && $pcBTC15[$i] < -0.01);
// 5m/3m 动量 → 15m网格
$ma24_5 = ma($c5m, 24); $mo5src = []; for ($i = 0; $i < count($c5m); $i++) if ($ma24_5[$i] !== null) $mo5src[$ts5m[$i]] = $c5m[$i] > $ma24_5[$i];
$ma40_3 = ma($c3m, 40); $mo3src = []; for ($i = 0; $i < count($c3m); $i++) if ($ma40_3[$i] !== null) $mo3src[$ts3m[$i]] = $c3m[$i] > $ma40_3[$i];

echo "== breadth 15m (32天) ==\n"; flush();
$br15 = breadth($ts15, '15m');
echo "== breadth 1h (3个月) ==\n"; flush();
$br1h = breadth($ts1h, '1h');

// 1h 网格特征
$ma20_1h = ma($c1h, 20); $ma10_1h = ma($c1h, 10);
$ethUp1h = []; $tr1h = [];
for ($i = 0; $i < count($c1h); $i++) { $ethUp1h[$ts1h[$i]] = ($ma20_1h[$i] !== null && $c1h[$i] > $ma20_1h[$i]); $tr1h[$ts1h[$i]] = ($ma10_1h[$i] !== null && $c1h[$i] > $ma10_1h[$i]); }
$ma5_4h = ma($c4h, 5); $tr4hSrc = [];
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hSrc[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i];
$tr4h = ffill_map($ts1h, $ts4h, $tr4hSrc);
$pcBTC1h = pctchg($cbtc1h, 4);
$crash1hSrc = []; for ($i = 0; $i < count($cbtc1h); $i++) $crash1hSrc[$ts1h[$i]] = ($pcBTC1h[$i] !== null && $pcBTC1h[$i] < -0.03);

// 入场标志(15m网格: 共振项从1h/4h/5m/3m前向填充)
$tr1hSrc = []; for ($i = 0; $i < count($c1h); $i++) if ($ma10_1h[$i] !== null) $tr1hSrc[$ts1h[$i]] = $c1h[$i] > $ma10_1h[$i];
$tr1hFor15 = ffill_map($ts15, $ts1h, $tr1hSrc);
$tr4hFor15 = ffill_map($ts15, $ts4h, $tr4hSrc);
$crashFor15 = ffill_map($ts15, $ts15, $crash15);
$entry15 = []; $n15 = count($ts15);
for ($i = 0; $i < $n15; $i++) {
    $t = $ts15[$i]; $b = $br15[$t];
    $entry15[$t] = ($b !== null && $b > 0.5 && !empty($ethUp15[$t]) && !empty($tr1hFor15[$t]) && !empty($tr4hFor15[$t])
        && !empty($mo5src[$t] ?? false) && !empty($mo3src[$t] ?? false) && empty($crashFor15[$t]));
}
$entry1h = []; $n1h = count($ts1h);
$crashFor1h = ffill_map($ts1h, $ts1h, $crash1hSrc);
for ($i = 0; $i < $n1h; $i++) {
    $t = $ts1h[$i]; $b = $br1h[$t];
    $entry1h[$t] = ($b !== null && $b > 0.5 && !empty($ethUp1h[$t]) && !empty($tr1h[$t]) && !empty($tr4h[$t]) && empty($crashFor1h[$t]));
}

// ---------- 模拟器 ----------
// margin 每笔保证金, lev 杠杆, tpr 止盈价格比, addm 加仓保证金, bars8h 每8h根数
function sim($o, $h, $l, $c, $ts, $fib, $entry, $margin, $lev, $tpr, $addm, $bars8h, $timeoutBars) {
    global $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $ADD, $MAXADD;
    $liqDrop = 1.0 / $lev - $MMR;
    $trades = [];
    $n = count($ts);
    $i = 30;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        $e = $o[$i + 1];
        $notional = $margin * $lev; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $tpr);
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;
        while ($j < $n) {
            $barsHeld = $j - $i;
            if ($l[$j] <= $liqPx) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($h[$j] >= $tgtPx) { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= $timeoutBars) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $addm * $lev) / ($notional + $addm * $lev);
                $notional += $addm * $lev; $adds++;
                $fee += $addm * $lev * $FEE_TAKER;
                $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $tpr);
            }
            $j++;
        }
        $gross = ($exitPx - $avg) * $notional / $avg;
        $funding = $notional * $FUND8H * ($barsHeld / $bars8h);
        $pnl = ($outcome === 'LIQ') ? -$margin : ($gross - $fee - $funding);
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,
                     'margin' => $margin + $adds * $addm, 'pnl' => $pnl, 'bars' => $barsHeld];
        $i = $j + 1;
    }
    return $trades;
}
function agg($trades, $spanDays) {
    $daily = []; $mon = []; $liqs = [];
    foreach ($trades as $t) {
        $d = date('Y-m-d', (int)($t['tout'] / 1000));
        $m = substr($d, 0, 7);
        $daily[$d] = ($daily[$d] ?? 0) + $t['pnl'];
        $mon[$m] = ($mon[$m] ?? 0) + $t['pnl'];
        if ($t['out'] === 'LIQ') $liqs[] = $d;
    }
    ksort($daily); ksort($mon);
    $w = 0; $lq = 0; $to = 0; $pl = 0;
    foreach ($trades as $t) { if ($t['out'] === 'WIN') $w++; elseif ($t['out'] === 'LIQ') $lq++; else $to++; $pl += $t['pnl']; }
    $n = max(1, count($trades));
    return ['n' => count($trades), 'win' => $w, 'liq' => $lq, 'to' => $to,
            'win_r' => round(100 * $w / $n, 1), 'liq_r' => round(100 * $lq / $n, 1),
            'pnl' => round($pl, 2), 'ev' => round($pl / $n, 2),
            'day_avg' => round($pl / max(1, $spanDays), 2),
            'daily' => $daily, 'mon' => $mon, 'liqs' => $liqs, 'trades' => $trades];
}

echo "== sim 15m (32天) ==\n"; flush();
$fib15 = fibsig($c15);
$t15_100 = sim($o15, $h15, $l15, $c15, $ts15, $fib15, $entry15, $MARGIN, 100, $TPR, $ADD, 32, 7 * 96);
$t15_20  = sim($o15, $h15, $l15, $c15, $ts15, $fib15, $entry15, $MARGIN, 20, $TPR, $ADD, 32, 7 * 96);
$span15 = (end($ts15) - $ts15[0]) / 86400000;
$a15_100 = agg($t15_100, $span15); $a15_20 = agg($t15_20, $span15);
echo "15m 100x: n={$a15_100['n']} liq={$a15_100['liq']} pnl={$a15_100['pnl']}\n"; flush();

echo "== sim 1h (3个月) ==\n"; flush();
$fib1h = fibsig($c1h);
$t1h_100 = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib1h, $entry1h, $MARGIN, 100, $TPR, $ADD, 8, $TIMEOUT);
$t1h_20  = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib1h, $entry1h, $MARGIN, 20, $TPR, $ADD, 8, $TIMEOUT);
$span1h = (end($ts1h) - $ts1h[0]) / 86400000;
$a1h_100 = agg($t1h_100, $span1h); $a1h_20 = agg($t1h_20, $span1h);
echo "1h 100x: n={$a1h_100['n']} liq={$a1h_100['liq']} pnl={$a1h_100['pnl']}\n"; flush();

// ---------- 共振评分 vs ETH 价格 (1h, 3个月) ----------
$score = [];
for ($i = 0; $i < $n1h; $i++) {
    $t = $ts1h[$i]; $s = 0; $b = $br1h[$t];
    if ($b !== null && $b > 0.5) $s++;
    if (!empty($ethUp1h[$t])) $s++;
    if (!empty($tr1h[$t])) $s++;
    if (!empty($tr4h[$t])) $s++;
    if (empty($crashFor1h[$t])) $s++;
    $score[] = $s;
}
$scoreS = []; // 24根平滑
for ($i = 0; $i < $n1h; $i++) {
    $sl = array_slice($score, max(0, $i - 23), 24);
    $scoreS[] = array_sum($sl) / count($sl);
}
// 相关性: 评分 vs 价格; 评分 vs 未来24h涨幅
$xs = []; $ys = []; $xf = []; $yf = [];
for ($i = 40; $i < $n1h - 24; $i++) {
    $xs[] = $scoreS[$i]; $ys[] = $c1h[$i];
    $xf[] = $scoreS[$i]; $yf[] = ($c1h[$i + 24] / $c1h[$i] - 1) * 100;
}
$rLevel = round(corr($xs, $ys), 3);
$rFwd = round(corr($xf, $yf), 3);

// ---------- SVG 图表 ----------
function svg_head($w, $h, $bg = '#ffffff') {
    return "<svg xmlns='http://www.w3.org/2000/svg' width='$w' height='$h' viewBox='0 0 $w $h' style='background:$bg;border:1px solid #dde3ee;border-radius:8px'>";
}
function svg_line($pts, $color, $wpx = 1.4, $dash = '') {
    $d = '';
    foreach ($pts as $k => $p) $d .= ($k ? 'L' : 'M') . round($p[0], 1) . ',' . round($p[1], 1);
    return "<path d='$d' fill='none' stroke='$color' stroke-width='$wpx'" . ($dash ? " stroke-dasharray='$dash'" : '') . "/>";
}
function svg_text($x, $y, $s, $size = 11, $color = '#445', $anchor = 'start') {
    return "<text x='$x' y='$y' font-size='$size' fill='$color' text-anchor='$anchor' font-family='Microsoft YaHei'>" . htmlspecialchars($s) . "</text>";
}
// 图1: 共振评分 vs ETH 价格 (双线同图, 各自归一)
function chart_score_price($ts1h, $c1h, $scoreS, $W = 940, $H = 300) {
    $n = count($c1h); $padL = 52; $padR = 52; $padT = 26; $padB = 40;
    $x0 = $padL; $x1 = $W - $padR; $y0 = $H - $padB; $y1 = $padT;
    $pmin = min($c1h); $pmax = max($c1h); $smin = 0; $smax = 5;
    $X = function ($i) use ($x0, $x1, $n) { return $x0 + ($x1 - $x0) * $i / ($n - 1); };
    $Yp = function ($v) use ($y0, $y1, $pmin, $pmax) { return $y0 - ($y0 - $y1) * ($v - $pmin) / max(0.001, $pmax - $pmin); };
    $Ys = function ($v) use ($y0, $y1, $smin, $smax) { return $y0 - ($y0 - $y1) * ($v - $smin) / $smax; };
    $s = svg_head($W, $H);
    // 网格与Y轴
    for ($g = 0; $g <= 4; $g++) {
        $v = $pmin + ($pmax - $pmin) * $g / 4;
        $yy = $Yp($v);
        $s .= "<line x1='$x0' y1='$yy' x2='$x1' y2='$yy' stroke='#eef1f7'/>";
        $s .= svg_text(6, $yy + 4, round($v), 10, '#889');
    }
    // 入场区着色(score>=5)
    for ($i = 0; $i < $n; $i++) {
        if ($scoreS[$i] >= 4.9) $s .= "<rect x='" . $X($i) . "' y='$y1' width='2' height='" . ($y0 - $y1) . "' fill='#ffe9ec'/>";
    }
    $pp = []; $sp = [];
    for ($i = 0; $i < $n; $i++) { $pp[] = [$X($i), $Yp($c1h[$i])]; $sp[] = [$X($i), $Ys($scoreS[$i])]; }
    $s .= svg_line($pp, '#4a7bd4', 1.6);            // ETH价格(蓝)
    $s .= svg_line($sp, '#d33', 1.6);               // 共振评分(红)
    // X轴月份
    $lastM = '';
    for ($i = 0; $i < $n; $i += 168) {
        $m = date('m-d', (int)($ts1h[$i] / 1000));
        $s .= svg_text($X($i), $H - 14, $m, 10, '#889', 'middle');
    }
    $s .= svg_text($x0, 16, 'ETH价格(左轴, 蓝)', 11, '#4a7bd4');
    $s .= svg_text($x1, 16, '共振评分0-5(右轴, 红, 24h平滑)', 11, '#d33', 'end');
    $s .= svg_text($W / 2, $H - 2, '深红底色 = 共振满分窗口(入场条件全满足)', 10, '#a66', 'middle');
    return $s . '</svg>';
}
// 图2: ETH 15m K线 最近7天 + 入场/爆仓标记
function chart_kline($ts15, $o15, $h15, $l15, $c15, $trades, $bars = 672, $W = 940, $H = 320) {
    $n = count($ts15); $st = max(0, $n - $bars);
    $padL = 52; $padR = 20; $padT = 26; $padB = 34;
    $x0 = $padL; $x1 = $W - $padR; $y0 = $H - $padB; $y1 = $padT;
    $pmin = INF; $pmax = -INF;
    for ($i = $st; $i < $n; $i++) { $pmin = min($pmin, $l15[$i]); $pmax = max($pmax, $h15[$i]); }
    $cw = ($x1 - $x0) / $bars;
    $X = function ($i) use ($x0, $cw, $st) { return $x0 + ($i - $st) * $cw; };
    $Y = function ($v) use ($y0, $y1, $pmin, $pmax) { return $y0 - ($y0 - $y1) * ($v - $pmin) / max(0.001, $pmax - $pmin); };
    $s = svg_head($W, $H);
    $tmap = [];
    foreach ($trades as $t) { $tmap[$t['tin']] = $t['out']; }
    for ($i = $st; $i < $n; $i++) {
        $up = $c15[$i] >= $o15[$i];
        $col = $up ? '#d33' : '#0a6';   // 红涨绿跌
        $x = $X($i); $yo = $Y($o15[$i]); $yc = $Y($c15[$i]);
        $s .= "<line x1='" . round($x + $cw / 2) . "' y1='" . round($Y($h15[$i])) . "' x2='" . round($x + $cw / 2) . "' y2='" . round($Y($l15[$i])) . "' stroke='$col' stroke-width='0.6'/>";
        $top = min($yo, $yc); $hh = max(0.6, abs($yc - $yo));
        $s .= "<rect x='" . round($x + $cw * 0.15) . "' y='" . round($top) . "' width='" . round(max(1, $cw * 0.7)) . "' height='" . round($hh) . "' fill='$col'/>";
        if (isset($tmap[$ts15[$i]])) {
            $out = $tmap[$ts15[$i]];
            $col2 = $out === 'LIQ' ? '#7a00cc' : '#e6890a';
            $yy = $out === 'LIQ' ? $Y($h15[$i]) - 8 : $Y($l15[$i]) + 12;
            $s .= "<circle cx='" . round($x + $cw / 2) . "' cy='" . round($yy) . "' r='4' fill='$col2'/>";
        }
    }
    for ($g = 0; $g <= 4; $g++) {
        $v = $pmin + ($pmax - $pmin) * $g / 4; $yy = $Y($v);
        $s .= "<line x1='$x0' y1='$yy' x2='$x1' y2='$yy' stroke='#eef1f7'/>" . svg_text(6, $yy + 4, round($v, 1), 10, '#889');
    }
    $s .= svg_text($x0, 16, 'ETH 15m K线(最近7天) · 橙点=开仓 · 紫点=爆仓', 11, '#445');
    return $s . '</svg>';
}
// 图3: 累计盈亏曲线
function chart_equity($a100, $a20, $W = 940, $H = 280) {
    $pts = [];
    foreach (['100x' => ['#d33', $a100], '20x' => ['#0a6', $a20]] as $lab => $cfg) {
        [$col, $a] = $cfg;
        $cum = 0; $p = [[0, 0]];
        $times = array_keys($a['daily'] ?: [0]);
        if (count($times) < 2) continue;
        $t0 = strtotime($times[0]); $tN = strtotime(end($times));
        $span = max(1, $tN - $t0);
        foreach ($a['daily'] as $d => $v) {
            $cum += $v;
            $p[] = [(strtotime($d) - $t0) / $span, $cum];
        }
        $pts[$lab] = [$col, $p, $cum];
    }
    if (!$pts) return '';
    $minv = 0; $maxv = 0;
    foreach ($pts as $cfg) foreach ($cfg[1] as $q) { $minv = min($minv, $q[1]); $maxv = max($maxv, $q[1]); }
    $padL = 56; $padR = 20; $padT = 26; $padB = 34;
    $x0 = $padL; $x1 = $W - $padR; $y0 = $H - $padB; $y1 = $padT;
    $X = function ($u) use ($x0, $x1) { return $x0 + ($x1 - $x0) * $u; };
    $Y = function ($v) use ($y0, $y1, $minv, $maxv) { return $y0 - ($y0 - $y1) * ($v - $minv) / max(0.001, $maxv - $minv); };
    $s = svg_head($W, $H);
    $s .= "<line x1='$x0' y1='" . $Y(0) . "' x2='$x1' y2='" . $Y(0) . "' stroke='#bbb' stroke-dasharray='4 3'/>";
    foreach ($pts as $lab => $cfg) {
        $p = [];
        foreach ($cfg[1] as $q) $p[] = [$X($q[0]), $Y($q[1])];
        $s .= svg_line($p, $cfg[0], 1.8);
        $s .= svg_text($x1 - 4, $Y($cfg[2]) - 6, "$lab 累计 {$cfg[2]}U", 11, $cfg[0], 'end');
    }
    for ($g = 0; $g <= 4; $g++) {
        $v = $minv + ($maxv - $minv) * $g / 4; $yy = $Y($v);
        $s .= "<line x1='$x0' y1='$yy' x2='$x1' y2='$yy' stroke='#eef1f7'/>" . svg_text(4, $yy + 4, round($v), 10, '#889');
    }
    $s .= svg_text($x0, 16, '累计盈亏曲线(USD) · 红=100x · 绿=20x · 500U/笔', 11, '#445');
    return $s . '</svg>';
}
// 图4: 每日盈亏柱状
function chart_daily($a, $W = 940, $H = 240) {
    $d = $a['daily'];
    if (!$d) return '';
    $keys = array_keys($d);
    $minv = min(0, min($d)); $maxv = max(0, max($d));
    $padL = 56; $padR = 20; $padT = 26; $padB = 44;
    $x0 = $padL; $x1 = $W - $padR; $y0 = $H - $padB; $y1 = $padT;
    $n = count($keys); $bw = ($x1 - $x0) / max(1, $n);
    $Y = function ($v) use ($y0, $y1, $minv, $maxv) { return $y0 - ($y0 - $y1) * ($v - $minv) / max(0.001, $maxv - $minv); };
    $s = svg_head($W, $H);
    $s .= "<line x1='$x0' y1='" . $Y(0) . "' x2='$x1' y2='" . $Y(0) . "' stroke='#bbb'/>";
    $k = 0;
    foreach ($d as $day => $v) {
        $x = $x0 + $k * $bw;
        $col = $v >= 0 ? '#d33' : '#0a6';
        $ya = $Y($v); $yb = $Y(0);
        $s .= "<rect x='" . round($x + 1) . "' y='" . round(min($ya, $yb)) . "' width='" . round(max(1, $bw - 2)) . "' height='" . round(abs($ya - $yb)) . "' fill='$col'/>";
        if ($k % max(1, (int)($n / 10)) == 0) $s .= svg_text($x + $bw / 2, $H - 24, substr($day, 5), 9, '#889', 'middle');
        $k++;
    }
    for ($g = 0; $g <= 4; $g++) {
        $v = $minv + ($maxv - $minv) * $g / 4; $yy = $Y($v);
        $s .= "<line x1='$x0' y1='$yy' x2='$x1' y2='$yy' stroke='#eef1f7'/>" . svg_text(4, $yy + 4, round($v), 10, '#889');
    }
    $s .= svg_text($x0, 16, '每日盈亏(USD) · 红=赚 绿=亏 · 100x/500U (1h网格3个月)', 11, '#445');
    return $s . '</svg>';
}
echo "== charts ==\n"; flush();
$g1 = chart_score_price($ts1h, $c1h, $scoreS);
$g2 = chart_kline($ts15, $o15, $h15, $l15, $c15, $t15_100);
$g3 = chart_equity($a1h_100, $a1h_20);
$g4 = chart_daily($a1h_100);

// ---------- HTML 报告 ----------
function trow($cells, $tags = []) {
    $h = '<tr>';
    foreach ($cells as $i => $v) { $cls = $tags[$i] ?? ''; $h .= "<td class='$cls'>$v</td>"; }
    return $h . '</tr>';
}
function sign($v) { return $v >= 0 ? "+$v" : "$v"; }
$html = ['<html><head><meta charset="utf-8"><style>
body{font-family:Microsoft YaHei;background:#f7f9fc;margin:20px} h2,h3{color:#1c3f77}
table{border-collapse:collapse;margin:10px 0;background:#fff}td,th{border:1px solid #ccd8ee;padding:6px 12px;text-align:center;font-size:13px}
th{background:#dbe7ff}.up{color:#d33;font-weight:bold}.dn{color:#0a6;font-weight:bold}.bad{background:#ffe8e8}.good{background:#e8ffe8}.muted{color:#889}
svg{margin:10px 0}
</style></head><body>',
'<h2>ETH 500U 仓位 · 100x/20x 爆仓测算 + 3个月历史回测 + 图表</h2>',
"<p class='muted'>ETH最新价 $lastPx · 数据: 1h网格 3个月(06-23起476合约) · 15m网格 32天 · 方案: 六重共振入场 / 每笔{$MARGIN}U / 无止损 / ROI+20%止盈 / fib618加仓5轮每次{$ADD}U / 资金费0.01%/8h</p>",

'<h3>① 500U 仓位爆仓表(逐仓, 维持保证金0.4%)</h3>',
'<table><tr><th>杠杆</th><th>名义仓位</th><th>爆仓跌幅</th><th>爆仓价距(USD)</th><th>爆仓价</th><th>爆仓损失</th></tr>'];
foreach ($liqRows as $r) {
    $cls = $r[0] == 100 ? ' class="bad"' : '';
    $html[] = "<tr$cls><td>{$r[0]}x</td><td>" . round($r[1]) . "U</td><td>-{$r[2]}%</td><td>-$" . round($r[3], 2) . "</td><td>$" . round($r[4], 2) . "</td><td>-" . round($r[5]) . "U</td></tr>";
}
$html[] = '</table>';
$html[] = "<p>· 100x: ETH 跌 <b>" . round($liqRows[0][3], 2) . "</b> 美元(才 0.6%)就爆, 一根15分钟阴线的事; 爆仓损失 " . round($liqRows[0][5]) . "U(500U保证金被吃掉" . round($liqRows[0][5]) . ", 维持保证金部分退回)<br>
· 资金费: 100x 下 50,000U 名义 × 0.01%/8h = <b>5U/8小时</b> = 收益率 -1%/8h, 持仓越久扣越多</p>";

function block($title, $a, $span) {
    $h = "<h3>$title</h3><table><tr><th>笔数</th><th>胜</th><th>爆仓</th><th>超时</th><th>胜率</th><th>爆仓率</th><th>总盈亏</th><th>EV/笔</th><th>日均</th></tr>";
    $h .= trow([$a['n'], $a['win'], $a['liq'], $a['to'], $a['win_r'] . '%', $a['liq_r'] . '%',
                sign($a['pnl']) . 'U', sign($a['ev']) . 'U', sign($a['day_avg']) . 'U'],
               ['', '', $a['liq'] ? 'bad' : '', '', '', $a['liq_r'] > 5 ? 'bad' : '', $a['pnl'] >= 0 ? 'up' : 'dn', $a['pnl'] >= 0 ? 'up' : 'dn', $a['pnl'] >= 0 ? 'up' : 'dn']);
    $h .= "</table><p class='muted'>回测跨度 $span 天</p>";
    return $h;
}
$html[] = block('② 15m 网格 · 32天 · 500U 100x', $a15_100, round($span15));
$html[] = block('③ 15m 网格 · 32天 · 500U 20x(对照)', $a15_20, round($span15));
$html[] = block('④ 1h 网格 · 3个月 · 500U 100x', $a1h_100, round($span1h));
$html[] = block('⑤ 1h 网格 · 3个月 · 500U 20x(对照)', $a1h_20, round($span1h));

$html[] = '<h3>⑥ 每月盈亏(1h 网格 3个月)</h3><table><tr><th>月份</th><th>100x 盈亏</th><th>20x 盈亏</th></tr>';
$mons = array_unique(array_merge(array_keys($a1h_100['mon']), array_keys($a1h_20['mon']))); sort($mons);
foreach ($mons as $m) {
    $v1 = $a1h_100['mon'][$m] ?? 0; $v2 = $a1h_20['mon'][$m] ?? 0;
    $html[] = trow([$m, sign(round($v1, 2)) . 'U', sign(round($v2, 2)) . 'U'], ['', $v1 >= 0 ? 'up' : 'dn', $v2 >= 0 ? 'up' : 'dn']);
}
$html[] = '</table>';

if ($a1h_100['liqs']) {
    $html[] = '<h3>⑦ 爆仓历史(1h网格 100x): ' . count($a1h_100['liqs']) . ' 次</h3><p class="bad">' . htmlspecialchars(implode(', ', $a1h_100['liqs'])) . '</p>';
} else {
    $html[] = '<h3>⑦ 爆仓历史(1h网格 100x): 0 次</h3>';
}
if ($a1h_20['liqs']) {
    $html[] = '<p>20x 爆仓 ' . count($a1h_20['liqs']) . ' 次: ' . htmlspecialchars(implode(', ', array_slice($a1h_20['liqs'], 0, 20))) . '</p>';
} else {
    $html[] = '<p class="good">20x 整个 3 个月爆仓 0 次</p>';
}

$html[] = '<h3>⑧ 共振信号 vs ETH 实际曲线(交叉检查)</h3>';
$html[] = $g1;
$html[] = "<p>共振评分与 ETH 价格水平相关系数 r=<b>$rLevel</b> · 评分与<b>未来24h涨幅</b>相关系数 r=<b>$rFwd</b>" .
    ($rFwd > 0.05 ? ' (正相关: 评分高时后续略偏涨, 交叉形态正常)' : ($rFwd < -0.05 ? ' (负相关: 评分高反而后续偏跌, 信号滞后/追高风险)' : ' (几乎不相关: 信号对短期涨幅无预测力)')) . '</p>';
$html[] = '<h3>⑨ ETH 15m K线(近7天)与开仓/爆仓点</h3>' . $g2;
$html[] = '<h3>⑩ 累计盈亏曲线</h3>' . $g3;
$html[] = '<h3>⑪ 每日盈亏</h3>' . $g4;

$html[] = '</body></html>';
file_put_contents('E:/datas/log/eth_500_report.html', implode("\n", $html));
echo "REPORT OK\n";
