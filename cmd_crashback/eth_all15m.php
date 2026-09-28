<?php
/**
 * eth_all15m.php — 无共振版: 所有合约 fib618上涨信号 + fib618仅上行加仓≤5轮 + 50%兜底强平(CLI)
 * A/B 两部分:
 *   A: 15m K线, 476合约, 数据只有 2026-08-23~09-25(约34天) → 真实"一个月"答案
 *   B: 1h  K线, 476合约, 完整一年(2025-09-25~2026-09-25) → 真实"一年"答案(同策略, 周期1h)
 * 策略: 买入=fib618回调信号+上涨结构(窗口30内高点出现在低点之后); 加仓=fib618+现价>均价≤5轮每轮=开仓保证金;
 *       下跌不减仓; 止盈=价格+2%自均价; 50%兜底强平=浮亏达该笔总保证金(含加仓)×50%市价全平;
 *       保证金=1U+1U×已过天数, 封顶500/6; 同合约同时1仓。
 * 输出: web/eth_all15m_data.json
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$BAL0 = 500.0; $CAP = $BAL0 / 6.0; $BASE = 1.0; $STEP = 1.0;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
function fibsigUp($c, $w = 30) {
    // fib618回调信号 + 上涨结构: 窗口内 swing high 出现在 swing low 之后
    $n = count($c); $out = array_fill(0, $n, false);
    for ($i = $w - 1; $i < $n; $i++) {
        $lo = INF; $hi = -INF; $loIdx = 0; $hiIdx = 0;
        for ($j = $i - $w + 1; $j <= $i; $j++) {
            if ($c[$j] < $lo) { $lo = $c[$j]; $loIdx = $j; }
            if ($c[$j] > $hi) { $hi = $c[$j]; $hiIdx = $j; }
        }
        if ($hi <= $lo || $hiIdx <= $loIdx) continue;
        $fib = $hi - ($hi - $lo) * 0.618;
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true;
    }
    return $out;
}
function fibsig($c, $w = 30) { // 加仓用(与之前回测同参)
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

function sim($o, $h, $l, $c, $ts, $fibE, $fibA, $barHours) {
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP, $BASE, $STEP;
    $liqDrop = 1.0 / $LEV - $MMR;
    $startT = $ts[30];
    $maxHold = (int)(7 * 24 / $barHours);
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($fibE[$ts[$i]])) { $i++; continue; }
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
            $funding += $notional * $FUND8H / 8 * $barHours;
            $hitCut = $l[$j] <= $stopPx;
            $hitLiq = $l[$j] <= $liqPx;
            $hitTp  = $h[$j] >= $tgtPx;
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; }
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= $maxHold) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < 5 && $j + 1 < $n && !empty($fibA[$ts[$j]]) && $c[$j] > $avg) {
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

function aggregate($trades, $tag, &$store) {
    $n = 0; $w = 0; $cut = 0; $adds = 0; $pl = 0.0;
    $daily = []; $monthly = [];
    foreach ($trades as $x) {
        $n++; $pl += $x['pnl']; $adds += $x['adds'];
        if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'CUT') $cut++;
        $d = date('Y-m-d', (int)($x['tout'] / 1000));
        $daily[$d] = ($daily[$d] ?? 0) + $x['pnl'];
        $m = substr($d, 0, 7);
        $monthly[$m] = ($monthly[$m] ?? 0) + $x['pnl'];
    }
    $days = array_keys($daily); sort($days);
    $eq = 500.0; $peak = 500.0; $maxDD = 0.0; $dailyArr = [];
    foreach ($days as $d) { $eq += $daily[$d]; if ($eq > $peak) $peak = $eq; if ($peak - $eq > $maxDD) $maxDD = $peak - $eq; $dailyArr[] = ['d' => $d, 'pnl' => round($daily[$d], 2), 'eq' => round($eq, 2)]; }
    $monthlyArr = [];
    foreach ($monthly as $m => $v) { $md = 0; foreach ($dailyArr as $r) if (substr($r['d'], 0, 7) === $m) $md++; $monthlyArr[] = ['m' => $m, 'pnl' => round($v, 2), 'days' => $md, 'avg' => $md ? round($v / $md, 2) : 0]; }
    $store[$tag] = ['n' => $n, 'win' => $w, 'cut' => $cut, 'adds' => $adds,
        'total' => round($pl, 2), 'max_dd' => round($maxDD, 2),
        'days' => count($dailyArr), 'avg_daily' => count($dailyArr) ? round($pl / count($dailyArr), 2) : 0,
        'ev' => $n ? round($pl / $n, 3) : 0,
        'range' => $n ? (date('Y-m-d', (int)($trades[0]['tin'] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($trades)['tout'] / 1000))) : '',
        'monthly' => $monthlyArr, 'daily' => $dailyArr];
}

// ===== 合约清单 =====
$tbls = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline_%_usdt_swap_1h' ORDER BY table_name");
$insts = [];
foreach ($tbls as $x) { if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $x[0], $m)) $insts[] = $m[1]; }
echo "insts=" . count($insts) . "\n"; flush();

// ===== A: 15m(约34天真实数据) =====
$tradesA = []; $done = 0;
foreach ($insts as $inst) {
    $chk = rows("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='trading' AND table_name='kline_{$inst}_usdt_swap_15m'");
    if (!(int)$chk[0][0]) continue;
    [$ts, $o, $h, $l, $c] = load_k($inst, '15m');
    if (count($ts) < 200) { $done++; continue; }
    $fibE = fibsigUp($c); $fibA = fibsig($c);
    $fE = []; foreach ($ts as $k => $t) if ($fibE[$k]) $fE[$t] = true;
    $fA = []; foreach ($ts as $k => $t) if ($fibA[$k]) $fA[$t] = true;
    foreach (sim($o, $h, $l, $c, $ts, $fE, $fA, 0.25) as $x) { $x['inst'] = $inst; $tradesA[] = $x; }
    $done++;
    if ($done % 80 == 0) { echo "A $done/476\n"; flush(); }
    unset($ts, $o, $h, $l, $c, $fibE, $fibA, $fE, $fA);
}
aggregate($tradesA, 'm15', $store);
echo "A15m: " . json_encode(array_intersect_key($store['m15'], array_flip(['n','win','cut','total','max_dd','days','avg_daily'])), JSON_UNESCAPED_UNICODE) . "\n"; flush();

// ===== B: 1h(完整一年) =====
$tradesB = []; $done = 0;
foreach ($insts as $inst) {
    [$ts, $o, $h, $l, $c] = load_k($inst, '1h');
    if (count($ts) < 300) { $done++; continue; }
    $fibE = fibsigUp($c); $fibA = fibsig($c);
    $fE = []; foreach ($ts as $k => $t) if ($fibE[$k]) $fE[$t] = true;
    $fA = []; foreach ($ts as $k => $t) if ($fibA[$k]) $fA[$t] = true;
    foreach (sim($o, $h, $l, $c, $ts, $fE, $fA, 1.0) as $x) { $x['inst'] = $inst; $tradesB[] = $x; }
    $done++;
    if ($done % 80 == 0) { echo "B $done/476\n"; flush(); }
    unset($ts, $o, $h, $l, $c, $fibE, $fibA, $fE, $fA);
}
aggregate($tradesB, 'h1', $store);
echo "B1h: " . json_encode(array_intersect_key($store['h1'], array_intersect_key($store['h1'], array_flip(['n','win','cut','total','max_dd','days','avg_daily']))), JSON_UNESCAPED_UNICODE) . "\n"; flush();

// 合约排行(1h档)
$per = [];
$tmp = [];
foreach ($tradesB as $x) { $k = strtoupper($x['inst'] ?? ''); $tmp[$k]['pnl'] = ($tmp[$k]['pnl'] ?? 0) + $x['pnl']; $tmp[$k]['n'] = ($tmp[$k]['n'] ?? 0) + 1; }
foreach ($tmp as $k => $v) $per[] = ['inst' => $k, 'n' => $v['n'], 'pnl' => round($v['pnl'], 2)];
usort($per, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; });

$out = [
    'params' => ['strategy' => '无共振版: fib618上涨信号买入(窗口30内高点在低点之后+回调至61.8位±3%) + fib618仅上行加仓≤5轮(每轮=开仓保证金) + 下跌不减仓 + 止盈价格+2%自均价 + 50%兜底强平(浮亏达该笔总保证金(含加仓)×50%市价全平)',
        'margin' => '每笔=1U+1U×已过天数, 封顶500/6', 'bal0' => $BAL0, 'lev' => 100,
        'note' => '15m全市场数据库只有约34天(8/23~9/25), "一个月"用真实15m数据; "一年"用同策略跑1h完整一年(2025-09-25~2026-09-25)'],
    'm15' => $store['m15'], 'h1' => $store['h1'],
    'rank_1h' => ['top' => array_slice($per, 0, 12), 'bottom' => array_slice(array_reverse($per), 0, 12)],
];
file_put_contents('E:/finally-main/web/eth_all15m_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK\n";
