<?php
/**
 * eth_allmarket.php — 全市场回测(CLI): 不限ETH, 全部476个 *-USDT-SWAP 合约
 * 策略 = A方案(与实盘同口径):
 *   买入: 六重共振全命中 ①全市场1D MA20宽度>50% ②本合约 1h>MA20 ③1h>MA10 ④4h>MA5
 *         ⑤非熔断(BTC 1h 最近4根累计跌幅≤-3% 熔断期禁买) ⑥本合约 taker买比≥50%(1h K线方向代理)
 *   加仓: 15m口径在本回测用1h替代(与ETH回测同参) fib_618 黄金回调信号 + 仅上行(现价>均价), ≤5轮, 每轮=开仓保证金
 *   减仓: 不减仓(下跌扛住)
 *   止盈: 不挂单, 实时监控价格+2%(自均价) → 市价全平
 *   50%兜底强平: 浮亏达到该笔总保证金(含加仓)×50% → 立即市价全平 (价格≤均价×(1-0.5/100))
 *   保证金: 每笔=base+3U×已过天数, 封顶 500/6(不跨合约复利, 组合口径)
 * 输出: web/eth_allmarket_data.json
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$BAL0 = 500.0; $CAP = $BAL0 / 6.0;

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

// ===== ① 全市场1D MA20 宽度(kline1d, 476合约) =====
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
echo "breadth days=" . count($brSrc) . "\n"; flush();

// ===== ⑤ 熔断序列: BTC 1h 最近4根累计跌幅≤-3% =====
[$tsB, , , , $cB] = load_k('btc', '1h');
$meltSrc = [];
for ($i = 4; $i < count($cB); $i++) {
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) $meltSrc[$tsB[$i]] = true;
}
$meltKeys = array_keys($meltSrc); // 有熔断标记的时刻(稀疏), 判定: 该时刻之后4小时内视为熔断
$meltSet = [];
foreach ($meltKeys as $mt) { for ($k = 0; $k < 4; $k++) $meltSet[$mt + $k * 3600000] = true; }
echo "melt hours=" . count($meltSet) . "\n"; flush();

// ===== 合约清单(有1h表的) =====
$tbls = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline_%_usdt_swap_1h' ORDER BY table_name");
$insts = [];
foreach ($tbls as $x) { if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $x[0], $m)) $insts[] = $m[1]; }
echo "insts=" . count($insts) . "\n"; flush();

// ===== A方案 sim(与 eth_cycle A 完全同参) =====
function sim($o, $h, $l, $c, $ts, $fib, $entry, $lbase) {
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP;
    $liqDrop = 1.0 / $LEV - $MMR;
    $startT = $ts[30];
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400);
        $M = round(min($lbase + 3.0 * $days, $CAP), 2);
        if ($M < 1) $M = 1.0;
        $M0 = $M;
        $e = $o[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER;
        $funding = 0.0;
        $liqPx = $avg * (1 - $liqDrop);
        $tgtPx = $avg * (1 + $TPR);
        $stopPx = $avg * (1 - 0.5 / $LEV);   // 50%兜底: 浮亏=总保证金(含加仓)×50%
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
            if ($adds < 5 && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) { // fib618 仅上行加仓, ≤5轮
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

$ladders = [['base' => 1.0, 'name' => '1U+3U/天'], ['base' => 20.0, 'name' => '20U+3U/天']];
$summary = []; $contractStats = []; $dailyPnl = []; $sample = [];
$gMin = PHP_INT_MAX; $gMax = 0;
foreach ($tsB as $t) { if ($t < $gMin) $gMin = $t; if ($t > $gMax) $gMax = $t; }

$done = 0;
foreach ($insts as $inst) {
    $t1 = "kline_{$inst}_usdt_swap_1h"; $t4 = "kline_{$inst}_usdt_swap_4h";
    $chk = rows("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='trading' AND table_name IN ('$t1','$t4')");
    if ((int)$chk[0][0] < 2) continue;
    [$ts1h, $o1h, $h1h, $l1h, $c1h] = load_k($inst, '1h');
    $n = count($ts1h);
    if ($n < 200) { $done++; continue; }
    $has4 = true;
    [$ts4h, , , , $c4h] = load_k($inst, '4h');
    if (count($ts4h) < 10) $has4 = false;
    $ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);
    $ma5_4h = $has4 ? ma($c4h, 5) : [];
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
        if (!empty($meltSet[$t])) continue;                       // ⑤ 非熔断
        $b = ffillBr($t);
        if ($b === null || $b <= 0.5) continue;                   // ① 宽度
        if ($ma20[$i] === null || $c1h[$i] <= $ma20[$i]) continue;// ②
        if ($ma10[$i] === null || $c1h[$i] <= $ma10[$i]) continue;// ③
        if (empty($tr4hUpF[$t])) continue;                        // ④
        if ($br1h[$i] < 0.5) continue;                            // ⑥
        $entryA[$t] = true;
    }
    foreach ($ladders as $ld) {
        $tr = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $ld['base']);
        $pl = 0.0; $w = 0; $cut = 0; $adds = 0;
        foreach ($tr as $x) {
            $pl += $x['pnl']; if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'CUT') $cut++; $adds += $x['adds'];
            $d = date('Y-m-d', (int)($x['tout'] / 1000));
            $dailyPnl[$ld['name']][$d] = ($dailyPnl[$ld['name']][$d] ?? 0) + $x['pnl'];
        }
        $cnt = count($tr);
        $summary[$ld['name']]['n'] = ($summary[$ld['name']]['n'] ?? 0) + $cnt;
        $summary[$ld['name']]['win'] = ($summary[$ld['name']]['win'] ?? 0) + $w;
        $summary[$ld['name']]['cut'] = ($summary[$ld['name']]['cut'] ?? 0) + $cut;
        $summary[$ld['name']]['adds'] = ($summary[$ld['name']]['adds'] ?? 0) + $adds;
        $summary[$ld['name']]['total'] = ($summary[$ld['name']]['total'] ?? 0) + $pl;
        if ($cnt > 0) $contractStats[$ld['name']][] = ['inst' => strtoupper($inst), 'n' => $cnt, 'pnl' => round($pl, 2), 'wr' => round(100 * $w / $cnt, 1)];
    }
    if ($inst === 'eth') $sample['eth'] = array_slice($tr, -40); // 保留ETH末40笔示例(20U阶梯)
    $done++;
    if ($done % 40 == 0) { echo "progress $done/" . count($insts) . "\n"; flush(); }
    unset($ts1h, $o1h, $h1h, $l1h, $c1h, $ts4h, $c4h, $ma20, $ma10, $ma5_4h, $tr4hUpF, $br1h, $fib, $entryA);
}

// 组合日权益曲线(从500起)
$curve = [];
foreach ($ladders as $ld) {
    $nm = $ld['name']; $eq = $BAL0; $peak = $BAL0; $maxDD = 0.0;
    $days = array_keys($dailyPnl[$nm] ?? []); sort($days);
    $c2 = [];
    foreach ($days as $d) { $eq += $dailyPnl[$nm][$d]; if ($eq > $peak) $peak = $eq; if ($peak - $eq > $maxDD) $maxDD = $peak - $eq; $c2[] = ['d' => $d, 'eq' => round($eq, 2)]; }
    $curve[$nm] = ['days' => $c2, 'max_dd' => round($maxDD, 2)];
    // 最亏日
    $worst = []; foreach ($dailyPnl[$nm] ?? [] as $d => $v) $worst[] = ['d' => $d, 'pnl' => round($v, 2)];
    usort($worst, function ($a, $b) { return $a['pnl'] <=> $b['pnl']; });
    $curve[$nm]['worst_days'] = array_slice($worst, 0, 8);
    $curve[$nm]['best_days'] = array_slice(array_reverse($worst), 0, 8);
}
// 合约排行
$rank = [];
foreach ($ladders as $ld) {
    $arr = $contractStats[$ld['name']] ?? [];
    usort($arr, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; });
    $rank[$ld['name']] = ['top' => array_slice($arr, 0, 15), 'bottom' => array_slice(array_reverse($arr), 0, 15)];
}
$spanDays = ($gMax / 1000 - $gMin / 1000) / 86400;
$out = [
    'params' => ['lev' => 100, 'bal0' => $BAL0, 'cap_per_trade' => round($CAP, 2), 'tp' => '价格+2%(自均价)',
        'backstop' => '50%兜底强平: 浮亏达到该笔总保证金(含加仓)×50% → 立即市价全平',
        'entry' => '六重共振全命中: ①全市场1D MA20宽度>50% ②本合约1h>MA20 ③1h>MA10 ④4h>MA5 ⑤非熔断(BTC 1h最近4根累计≤-3%) ⑥本合约taker买比≥50%',
        'add' => 'fib_618黄金回调+仅上行(现价>均价) ≤5轮 每轮=开仓保证金; 下跌不减仓',
        'note' => '加仓/兜底口径与ETH回测同参; 每合约独立持仓(同合约同时只1仓); 保证金封顶=500/6(组合不复利)',
        'span' => round($spanDays) . '天',
        'range' => date('Y-m-d', (int)($gMin / 1000)) . ' ~ ' . date('Y-m-d', (int)($gMax / 1000)),
        'insts' => count($insts)],
    'summary' => $summary, 'curve' => $curve, 'rank' => $rank,
    'sample_eth' => array_map(function ($t) {
        return ['tin' => date('m-d H:i', (int)($t['tin'] / 1000)), 'tout' => date('m-d H:i', (int)($t['tout'] / 1000)),
                'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'pnl' => $t['pnl']];
    }, $sample['eth'] ?? []),
];
file_put_contents('E:/finally-main/web/eth_allmarket_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK\n";
foreach ($ladders as $ld) { $nm = $ld['name']; echo "$nm: " . json_encode($summary[$nm], JSON_UNESCAPED_UNICODE) . " maxDD=" . $curve[$nm]['max_dd'] . "\n"; }
