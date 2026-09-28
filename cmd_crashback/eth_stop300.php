<?php
/**
 * eth_stop300.php — ETH模式 动态保证金阶梯 + 浮亏止损X档 一年对比(CLI)
 * 配置(与实盘 ethmode.go 1:1):
 *   六重共振入场(宽度>50% + 1h>MA20/MA10 + 4h>MA5 + taker买比>=50%) 只买涨
 *   保证金 = 1U + 3U×已过天数, 封顶 余额/6 (每笔开仓时计算)
 *   100x 逐仓, TP 价格+2%(ROI+200%), 不挂条件单
 *   加仓 = 15m fib_618 信号 + 仅上行(现价>均价), 最多5轮, 每轮加仓 = 开仓时保证金
 *   止损对比: 浮亏达到 X U(整仓含加仓) → 市价全平, X = 0(不设)/100/200/300/400/500
 * 输出: web/eth_stop300_data.json
 */
ini_set('memory_limit', '1024M');
set_time_limit(0);
$BALANCE = 500.0;
$LEV = 100; $TPR = 0.02; $MAXADD = 5;
$MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$LADDER_BASE = 1.0; $LADDER_STEP = 3.0;

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

// sim: 动态保证金阶梯 + 每轮加仓=开仓时保证金 + 浮亏止损 cut(U)
function sim($o, $h, $l, $c, $ts, $fib, $entry, $br, $cut) {
    global $LEV, $TPR, $MAXADD, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $LADDER_BASE, $LADDER_STEP;
    $liqDrop = 1.0 / $LEV - $MMR;
    $bal = 500.0;                       // 余额(复利承接)
    $startT = $ts[30];                  // 阶梯起算 = 第一笔信号
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        $s = 0; $cnt = 0;
        for ($k = $i - 11; $k <= $i; $k++) if ($k >= 0) { $s += $br[$k]; $cnt++; }
        if ($cnt == 0 || $s / $cnt < 0.5) { $i++; continue; }
        // ===== 保证金阶梯(开仓时) =====
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400);
        $M = round(min($LADDER_BASE + $LADDER_STEP * $days, $bal / 6.0), 2);
        if ($M < 1) $M = 1.0;
        $M0 = $M;                        // 开仓时保证金(每轮加仓都用它)
        $e = $o[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $liqPx = $avg * (1 - $liqDrop);
        $tgtPx = $avg * (1 + $TPR);
        $stopPx = ($cut > 0) ? $avg * (1 - $cut / $notional) : 0;
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;
        while ($j < $n) {
            $barsHeld = $j - $i;
            $hitLiq = $l[$j] <= $liqPx;
            $hitCut = ($cut > 0) && ($l[$j] <= $stopPx);
            $hitTp  = $h[$j] >= $tgtPx;
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; }
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);
                $notional += $M0 * $LEV; $adds++;
                $fee += $M0 * $LEV * $FEE_TAKER;
                $liqPx = $avg * (1 - $liqDrop);
                $tgtPx = $avg * (1 + $TPR);
                if ($cut > 0) $stopPx = $avg * (1 - $cut / $notional);   // 加仓后均价上移, 止损线重算
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }
        $gross = ($exitPx - $avg) * $notional / $avg;
        $funding = $notional * $FUND8H * ($barsHeld / 8);
        $pnl = $gross - $fee - $funding;
        $mg = $M0 * (1 + $adds);
        if ($pnl < -$mg) $pnl = -$mg;    // 逐仓: 最多亏掉全部保证金
        $bal = round($bal + $pnl, 2);
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,
                     'margin' => $mg, 'pnl' => round($pnl, 3), 'bal' => $bal];
        if ($bal <= 0) break;            // 账户打穿
        $i = $j + 1;
    }
    return [$trades, $bal];
}
function summ($trades) {
    $w = 0; $cut = 0; $lq = 0; $to = 0; $pl = 0.0; $gw = 0.0; $gl = 0.0; $maxDD = 0.0; $peak = 0.0; $cum = 0.0;
    foreach ($trades as $t) {
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'CUT') $cut++;
        elseif ($t['out'] == 'LIQ') $lq++; else $to++;
        $pl += $t['pnl']; $cum += $t['pnl'];
        if ($cum > $peak) $peak = $cum;
        if ($peak - $cum > $maxDD) $maxDD = $peak - $cum;
        if ($t['pnl'] >= 0) $gw += $t['pnl']; else $gl += $t['pnl'];
    }
    $n = count($trades);
    return ['n' => $n, 'win' => $w, 'cut' => $cut, 'liq' => $lq, 'to' => $to,
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2),
            'gross_win' => round($gw, 2), 'gross_lose' => round($gl, 2),
            'max_dd' => round($maxDD, 2), 'final_bal' => round(500 + $pl, 2),
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

// ===== 止损档对比(0=不设止损[现行] / 100 / 200 / 300 / 400 / 500) =====
$cuts = [0, 100, 200, 300, 400, 500];
$results = []; $allTrades = [];
foreach ($cuts as $cut) {
    [$tr, $fin] = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $br1h, $cut);
    $sm = summ($tr);
    $results[] = ['cut' => $cut] + $sm;
    $allTrades[$cut] = $tr;
    echo "cut={$cut}U: " . json_encode($sm, JSON_UNESCAPED_UNICODE) . "\n"; flush();
}
usort($results, fn($a, $b) => $b['total'] <=> $a['total']);

// 300U 与 0 的逐笔明细(对比)
function detailOut($tr) {
    $cum = 0; $out = [];
    foreach ($tr as $t) {
        $cum += $t['pnl'];
        $out[] = ['tin' => date('Y-m-d H:i', (int)($t['tin'] / 1000)),
                  'tout' => date('Y-m-d H:i', (int)($t['tout'] / 1000)),
                  'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'],
                  'pnl' => $t['pnl'], 'bal' => $t['bal'], 'cum' => round($cum, 2)];
    }
    return $out;
}
$d0 = detailOut($allTrades[0]);
$d300 = detailOut($allTrades[300]);
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;

// 月度(300U版)
$mon = [];
foreach ($allTrades[300] as $t) { $k = date('Y-m', (int)($t['tout'] / 1000)); $mon[$k] = round(($mon[$k] ?? 0) + $t['pnl'], 2); }

$out = [
    'params' => ['lev' => 100, 'balance' => 500, 'ladder' => '1U+3U×已过天数, 封顶余额/6',
                 'add' => 'fib618+仅上行, ≤5轮, 每轮=开仓时保证金', 'tp' => '价格+2%(ROI+200%)',
                 'rule' => '浮亏达到X U(整仓含加仓) → 市价全平; X=0 即现行不设止损',
                 'span' => round($spanDays) . '天',
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000)),
                 'dir' => '只买涨'],
    'results' => $results,
    'trades_0' => $d0, 'trades_300' => $d300, 'monthly_300' => $mon,
];
file_put_contents('E:/finally-main/web/eth_stop300_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK t0=" . count($d0) . " t300=" . count($d300) . "\n";
