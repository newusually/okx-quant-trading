<?php
/**
 * eth_tp10.php — ETH 3m/5m/15m 斐波那契+黄金分割买入 100x 一年回测(CLI)
 * 买入: 上涨结构(窗口30内高点在低点之后) + 回调至黄金分割位:
 *   A=仅0.618经典回调;  B=0.382/0.5/0.618 三档任一(±1.5%容差)
 * 加仓: fib618信号+现价>均价 ≤5轮, 每轮=开仓保证金; 下跌不减仓
 * 50%兜底强平: 浮亏达该笔总保证金(含加仓)×50% (=价格-0.5%@100x) → 市价全平
 * 止盈矩阵: tp01=价格+0.1%(100x ROI+10%) / tp2=价格+2%(现行) / tp10=价格+10%
 * 保证金: 每笔=1U+1U×已过天数, 封顶500/6; 同周期同时1仓, 可多次重复进出; 100x逐仓
 * 输出: web/eth_tp10_data.json
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$CAP = 500.0 / 6.0; $BASE = 1.0; $STEP = 1.0;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function load_k($bar) {
    $r = $GLOBALS['DB']->query("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_eth_usdt_swap_$bar ORDER BY candle_time ASC");
    $ts = []; $o = []; $h = []; $l = []; $c = [];
    while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }
    return [$ts, $o, $h, $l, $c];
}
// 黄金分割回调信号: 返回每根bar命中的档位(0=无, 382/500/618)
function goldenSig($c, $w = 30, $tol = 0.015) {
    $n = count($c); $out = [];
    for ($i = $w - 1; $i < $n; $i++) {
        $lo = INF; $hi = -INF; $loIdx = 0; $hiIdx = 0;
        for ($j = $i - $w + 1; $j <= $i; $j++) {
            if ($c[$j] < $lo) { $lo = $c[$j]; $loIdx = $j; }
            if ($c[$j] > $hi) { $hi = $c[$j]; $hiIdx = $j; }
        }
        if ($hi <= $lo || $hiIdx <= $loIdx) continue;
        $rng = $hi - $lo;
        foreach ([618 => 0.618, 500 => 0.5, 382 => 0.382] as $lv => $f) {
            $px = $hi - $rng * $f;
            if ($px * (1 - $tol) <= $c[$i] && $c[$i] <= $px * (1 + $tol)) { $out[$i] = $lv; break; }
        }
    }
    return $out;
}
function fibAddSig($c, $w = 30) { // 加仓: 0.618位±3%
    $n = count($c); $out = [];
    for ($i = $w - 1; $i < $n; $i++) {
        $lo = INF; $hi = -INF;
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }
        if ($hi <= $lo) continue;
        $fib = $hi - ($hi - $lo) * 0.618;
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true;
    }
    return $out;
}

function sim($o, $h, $l, $c, $ts, $entries, $fAdd, $tpRate, $barHours, $startDay) {
    global $LEV, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP, $BASE, $STEP;
    $liqDrop = 1.0 / $LEV - $MMR;
    $startT = $ts[30];
    $maxHold = (int)(24 * 7 / $barHours); // 最长7天
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($entries[$i])) { $i++; continue; }
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400);
        $M = round(min($BASE + $STEP * $days, $CAP), 2);
        if ($M < 1) $M = 1.0;
        $M0 = $M;
        $e = $o[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $funding = 0.0;
        $liqPx = $avg * (1 - $liqDrop);
        $tgtPx = $avg * (1 + $tpRate);
        $stopPx = $avg * (1 - 0.5 / $LEV); // 50%保证金兜底
        $outcome = null; $exitPx = null; $j = $i + 1;
        while ($j < $n) {
            $barsHeld = $j - $i;
            $funding += $notional * $FUND8H / 8 * $barHours;
            if ($l[$j] <= $stopPx) { $outcome = 'CUT50'; $exitPx = $stopPx; break; }
            if ($l[$j] <= $liqPx) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($h[$j] >= $tgtPx) { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= $maxHold) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < 5 && $j + 1 < $n && !empty($fAdd[$j]) && $c[$j] > $avg) {
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);
                $notional += $M0 * $LEV; $adds++;
                $fee += $M0 * $LEV * $FEE_TAKER;
                $liqPx = $avg * (1 - $liqDrop);
                $tgtPx = $avg * (1 + $tpRate);
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

function aggregate($trades, &$store, $tag) {
    $n = 0; $w = 0; $cut = 0; $liq = 0; $to = 0; $adds = 0; $pl = 0.0; $holdSum = 0;
    $daily = []; $monthly = [];
    foreach ($trades as $x) {
        $n++; $pl += $x['pnl']; $adds += $x['adds']; $holdSum += ($x['tout'] - $x['tin']);
        if ($x['out'] == 'WIN') $w++; elseif ($x['out'] == 'CUT50') $cut++; elseif ($x['out'] == 'LIQ') $liq++; elseif ($x['out'] == 'TO') $to++;
        $d = gmdate('Y-m-d', (int)($x['tout'] / 1000));
        $daily[$d] = ($daily[$d] ?? 0) + $x['pnl'];
        $m = substr($d, 0, 7);
        $monthly[$m] = ($monthly[$m] ?? 0) + $x['pnl'];
    }
    $days = array_keys($daily); sort($days);
    $eq = 500.0; $peak = 500.0; $maxDD = 0.0; $dailyArr = [];
    foreach ($days as $d) { $eq += $daily[$d]; if ($eq > $peak) $peak = $eq; if ($peak - $eq > $maxDD) $maxDD = $peak - $eq; $dailyArr[] = ['d' => $d, 'pnl' => round($daily[$d], 2), 'eq' => round($eq, 2)]; }
    $monthlyArr = [];
    foreach ($monthly as $m => $v) { $md = 0; foreach ($dailyArr as $r) if (substr($r['d'], 0, 7) === $m) $md++; $monthlyArr[] = ['m' => $m, 'pnl' => round($v, 2), 'days' => $md, 'avg' => $md ? round($v / $md, 2) : 0]; }
    $store[$tag] = ['n' => $n, 'win' => $w, 'cut50' => $cut, 'liq' => $liq, 'to' => $to, 'adds' => $adds,
        'total' => round($pl, 2), 'max_dd' => round($maxDD, 2), 'final_eq' => round(500 + $pl, 2),
        'days' => count($dailyArr), 'avg_daily' => count($dailyArr) ? round($pl / count($dailyArr), 2) : 0,
        'ev' => $n ? round($pl / $n, 3) : 0,
        'avg_hold_h' => $n ? round($holdSum / $n / 3600000, 1) : 0,
        'winrate' => $n ? round($w / $n * 100, 1) : 0,
        'range' => $n ? (gmdate('Y-m-d', (int)($trades[0]['tin'] / 1000)) . ' ~ ' . gmdate('Y-m-d', (int)(end($trades)['tout'] / 1000))) : '',
        'monthly' => $monthlyArr, 'daily' => $dailyArr];
}

// ===== 数据准备: 加载三周期 + 信号 =====
$DATA = []; // bar => ['ts','o','h','l','c','gA'(618 only),'gB'(382/500/618),'fAdd','barHours']
foreach (['3m' => 0.05, '5m' => 1 / 12, '15m' => 0.25] as $bar => $bh) {
    [$ts, $o, $h, $l, $c] = load_k($bar);
    if (count($ts) < 100) { echo "$bar no data\n"; continue; }
    $gAll = goldenSig($c);
    $gA = []; $gB = [];
    foreach ($gAll as $i => $lv) { if ($lv == 618) $gA[$i] = true; $gB[$i] = true; }
    $fRaw = fibAddSig($c);
    $fAdd = [];
    foreach ($fRaw as $i => $v) $fAdd[$i] = true;
    $DATA[$bar] = ['ts' => $ts, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c, 'gA' => $gA, 'gB' => $gB, 'fAdd' => $fAdd, 'bh' => $bh];
    echo "$bar loaded bars=" . count($ts) . " entryA=" . count($gA) . " entryB=" . count($gB) . "\n"; flush();
}

$store = [];
// 18 组合: 3周期 × 2买入法 × 3止盈
foreach ($DATA as $bar => $D) {
    foreach (['A' => 'fib618经典', 'B' => '黄金分割三档'] as $ek => $ename) {
        foreach (['tp01' => 0.001, 'tp2' => 0.02, 'tp10' => 0.10] as $tpk => $tpRate) {
            $tag = "{$bar}_{$ek}_{$tpk}";
            $tr = sim($D['o'], $D['h'], $D['l'], $D['c'], $D['ts'], $D['g' . $ek], $D['fAdd'], $tpRate, $D['bh'], 0);
            aggregate($tr, $store, $tag);
            echo "$tag: n={$store[$tag]['n']} win%={$store[$tag]['winrate']} total={$store[$tag]['total']} dd={$store[$tag]['max_dd']}\n"; flush();
        }
    }
}

$out = [
    'params' => ['strategy' => 'ETH-USDT-SWAP 100x逐仓只买涨; 买入=上涨结构(窗口30内高点在低点之后)+黄金分割回调(±1.5%容差): A=仅0.618, B=0.382/0.5/0.618任一; 加仓=fib618+现价>均价≤5轮每轮=开仓保证金; 下跌不减仓; 50%兜底强平=浮亏达该笔总保证金(含加仓)×50%市价全平(价格-0.5%@100x)',
        'tp' => 'tp01=价格+0.1%(100x ROI+10%) | tp2=价格+2%(ROI+200%) | tp10=价格+10%(ROI+1000%)',
        'margin' => '每笔=1U+1U×已过天数, 封顶500/6; 最长持仓7天超时平', 'lev' => 100],
    'results' => $store,
];
file_put_contents('E:/finally-main/web/eth_tp10_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK\n";
