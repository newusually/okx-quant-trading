<?php
/**
 * eth_strong.php — 每天找强势币 + 现行策略 一年回测(CLI)
 * 选币: 每天00:00(UTC)按 1h K线 24h 涨幅排名, 取涨幅>0 的前 TOP N 个强势币, 当天只交易这些币
 * 策略(与现行A方案同参): 买入=fib618回调信号+上涨结构(窗口30内高点在低点之后);
 *   加仓=fib618+现价>均价≤5轮每轮=开仓保证金; 下跌不减仓; 止盈=价格+2%自均价;
 *   50%兜底强平=浮亏达该笔总保证金(含加仓)×50%市价全平(=价格-0.5%/100x);
 *   保证金=每品种1U+1U×已过天数, 封顶500/6; 同合约同时1仓; 100x逐仓; maker进taker加仓。
 * 输出: web/eth_strong_data.json  (TOP10 / TOP5 / TOP20 三档对比)
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$BAL0 = 500.0; $CAP = $BAL0 / 6.0; $BASE = 1.0; $STEP = 1.0;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
function fibsigUp($c, $w = 30) {
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

function sim($o, $h, $l, $c, $ts, $fibE, $fA, $allowDays, $inst, $barHours) {
    // $allowDays: day => [inst => true] 只允许这些天这些币开仓
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP, $BASE, $STEP;
    $liqDrop = 1.0 / $LEV - $MMR;
    $startT = $ts[30];
    $maxHold = (int)(7 * 24 / $barHours);
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($fibE[$ts[$i]])) { $i++; continue; }
        $day = gmdate('Y-m-d', (int)($ts[$i] / 1000));
        if (empty($allowDays[$day][$inst])) { $i++; continue; }
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
        $stopPx = $avg * (1 - 0.5 / $LEV); // 50%兜底强平: 亏0.5%价格 = 保证金×50%
        $outcome = null; $exitPx = null; $j = $i + 1;
        while ($j < $n) {
            $barsHeld = $j - $i;
            $funding += $notional * $FUND8H / 8 * $barHours;
            $hitCut = $l[$j] <= $stopPx;
            $hitLiq = $l[$j] <= $liqPx;
            $hitTp  = $h[$j] >= $tgtPx;
            if ($hitCut) { $outcome = 'CUT50'; $exitPx = $stopPx; break; }
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= $maxHold) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < 5 && $j + 1 < $n && !empty($fA[$ts[$j]]) && $c[$j] > $avg) {
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
    $n = 0; $w = 0; $cut = 0; $liq = 0; $adds = 0; $pl = 0.0;
    $daily = []; $monthly = [];
    foreach ($trades as $x) {
        $n++; $pl += $x['pnl']; $adds += $x['adds'];
        if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'CUT50') $cut++; if ($x['out'] == 'LIQ') $liq++;
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
    arsort($daily);
    $topDays = [];
    foreach (array_slice($daily, 0, 8, true) as $d => $v) $topDays[] = ['d' => $d, 'pnl' => round($v, 2)];
    $store[$tag] = ['n' => $n, 'win' => $w, 'cut50' => $cut, 'liq' => $liq, 'adds' => $adds,
        'total' => round($pl, 2), 'max_dd' => round($maxDD, 2),
        'days' => count($dailyArr), 'avg_daily' => count($dailyArr) ? round($pl / count($dailyArr), 2) : 0,
        'ev' => $n ? round($pl / $n, 3) : 0,
        'range' => $n ? (date('Y-m-d', (int)($trades[0]['tin'] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($trades)['tout'] / 1000))) : '',
        'top_days' => $topDays,
        'monthly' => $monthlyArr, 'daily' => $dailyArr];
}

// ===== 合约清单 =====
$tbls = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline_%_usdt_swap_1h' ORDER BY table_name");
$insts = [];
foreach ($tbls as $x) { if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $x[0], $m)) $insts[] = $m[1]; }
echo "insts=" . count($insts) . "\n"; flush();

// ===== Pass1: 每天强势币排名 — 用 kline1d 日线(469币全年), mom = 日涨幅>0 =====
$dayMom = [];
$dr = rows("SELECT inst_id, candle_time, `c` FROM kline1d ORDER BY inst_id, candle_time ASC");
$prevC = []; $prevInst = '';
foreach ($dr as $x) {
    $iid = strtolower(str_replace('-USDT-SWAP', '', $x[0])); // 'ETH-USDT-SWAP' -> 'eth' (与1h表名一致)
    $t = (int)$x[1]; $cv = (float)$x[2];
    if ($iid !== $prevInst) { $prevC[$iid] = null; $prevInst = $iid; }
    $d = gmdate('Y-m-d', (int)($t / 1000));
    if ($prevC[$iid] !== null && $prevC[$iid] > 0) {
        $mom = $cv / $prevC[$iid] - 1;
        if ($mom > 0) $dayMom[$d][$iid] = $mom;
    }
    $prevC[$iid] = $cv;
}
// 每天 Top N 名单
$topSets = ['10' => [], '5' => [], '20' => []];
foreach ($dayMom as $d => $arr) {
    arsort($arr);
    $keys = array_keys($arr);
    foreach (['10' => 10, '5' => 5, '20' => 20] as $n => $lim) {
        foreach (array_slice($keys, 0, $lim) as $inst) $topSets[$n][$d][$inst] = true;
    }
}
echo "days=" . count($dayMom) . " top10days=" . count($topSets['10']) . " top5days=" . count($topSets['5']) . " top20days=" . count($topSets['20']) . "\n"; flush();
// 诊断: 抽样3天看候选数与三档集合大小
$si = 0;
foreach ($dayMom as $d => $arr) {
    if ($si++ % 30 != 0) continue;
    $cand = count($arr);
    $s5 = isset($topSets['5'][$d]) ? count($topSets['5'][$d]) : 0;
    $s10 = isset($topSets['10'][$d]) ? count($topSets['10'][$d]) : 0;
    $s20 = isset($topSets['20'][$d]) ? count($topSets['20'][$d]) : 0;
    echo "diag $d cand=$cand top5=$s5 top10=$s10 top20=$s20\n"; flush();
}

// ===== Pass2: 逐合约回测(三个榜单共用信号, 只差 allowDays) =====
$fibCache = [];
$tradesBy = ['10' => [], '5' => [], '20' => []];
foreach ($insts as $k => $inst) {
    [$ts, $o, $h, $l, $c] = load_k($inst, '1h');
    if (count($ts) < 300) continue;
    $fibE = fibsigUp($c); $fibA = fibsig($c);
    $fE = []; foreach ($ts as $i2 => $t) if ($fibE[$i2]) $fE[$t] = true;
    $fA = []; foreach ($ts as $i2 => $t) if ($fibA[$i2]) $fA[$t] = true;
    foreach (['10', '5', '20'] as $n) {
        $tr = sim($o, $h, $l, $c, $ts, $fE, $fA, $topSets[$n], $inst, 1.0);
        foreach ($tr as $x) { $x['inst'] = $inst; $tradesBy[$n][] = $x; }
    }
    if (($k + 1) % 80 == 0) { echo "P2 " . ($k + 1) . "/" . count($insts) . "\n"; flush(); }
    unset($ts, $o, $h, $l, $c, $fibE, $fibA, $fE, $fA);
}

$store = [];
foreach (['10' => 'top10', '5' => 'top5', '20' => 'top20'] as $n => $tag) {
    aggregate($tradesBy[$n], $tag, $store);
    echo "$tag: n={$store[$tag]['n']} total={$store[$tag]['total']} dd={$store[$tag]['max_dd']} ev={$store[$tag]['ev']}\n"; flush();
}

// 合约排行(TOP10档)
$per = []; $tmp = [];
foreach ($tradesBy['10'] as $x) { $k2 = strtoupper($x['inst']); $tmp[$k2]['pnl'] = ($tmp[$k2]['pnl'] ?? 0) + $x['pnl']; $tmp[$k2]['n'] = ($tmp[$k2]['n'] ?? 0) + 1; }
foreach ($tmp as $k2 => $v) $per[] = ['inst' => $k2, 'n' => $v['n'], 'pnl' => round($v['pnl'], 2)];
usort($per, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; });

$out = [
    'params' => ['strategy' => '每天(UTC)按前一日日线涨幅(kline1d, 469币全年)选强势币(涨幅>0), 当天只交易TopN; 买入=fib618回调信号+上涨结构(窗口30内高点在低点之后, 1h K线); 加仓=fib618+现价>均价≤5轮(每轮=开仓保证金); 下跌不减仓; 止盈=价格+2%自均价; 50%兜底强平=浮亏达该笔总保证金(含加仓)×50%市价全平; 100x逐仓',
        'margin' => '每品种每笔=1U+1U×已过天数, 封顶500/6', 'bal0' => $BAL0, 'lev' => 100,
        'note' => '1h K线完整一年 2025-09-25 ~ 2026-09-25, 476合约'],
    'top10' => $store['top10'], 'top5' => $store['top5'], 'top20' => $store['top20'],
    'rank_top10' => ['top' => array_slice($per, 0, 12), 'bottom' => array_slice(array_reverse($per), 0, 12)],
];
file_put_contents('E:/finally-main/web/eth_strong_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK\n";
