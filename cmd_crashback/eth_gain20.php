<?php
/**
 * eth_gain20.php — 全市场回测(CLI): 只买「日涨幅≥20%」的币 × 六重共振 A方案
 * 选币: 两种口径对比
 *   A 昨涨口径(无未来函数): 前一日日线(UTC)涨幅≥20% → 当天允许买
 *   B 盘中口径: 当日盘中涨幅(1h收盘/前一日日线收盘-1)≥20% → 该根之后允许买
 * 策略 = A方案(与实盘/eth_allmarket同参):
 *   买入: 六重共振全命中 ①全市场1D MA20宽度>50% ②1h>MA20 ③1h>MA10 ④4h>MA5
 *         ⑤非熔断(BTC 1h最近4根累计≤-3%) ⑥taker买比≥50%(1h方向代理)
 *   加仓: fib618黄金回调+仅上行(现价>均价) ≤5轮 每轮=开仓保证金; 下跌不减仓
 *   止盈: 价格+2%(自均价) 市价全平
 *   50%兜底强平: 浮亏达该笔总保证金(含加仓)×50% → 立即市价全平
 *   保证金: 每笔=base+3U×已过天数, 封顶 500/6
 * 输出: web/eth_gain20_data.json
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$BAL0 = 500.0; $CAP = $BAL0 / 6.0; $G20 = 0.20;

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

// ===== kline1d: 每币每日收盘 + 日涨幅 + 前一日收盘(盘中口径用) =====
$dayClose = [];   // inst => day => close
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");
$cur = null;
foreach ($rr as $x) {
    $iid = strtolower(str_replace('-USDT-SWAP', '', $x[0]));
    if ($iid !== $cur) { $cur = $iid; }
    $dayClose[$cur][gmdate('Y-m-d', (int)($x[1] / 1000))] = (float)$x[2];
}
echo "kline1d insts=" . count($dayClose) . "\n"; flush();

// A口径: 前一日涨幅≥20% → 当天允许;  B口径: 前一日收盘价(盘中涨幅分母)
$allowA = []; $prevClose = []; $g20Count = []; // day => 币数
foreach ($dayClose as $iid => $dc) {
    ksort($dc);
    $prevDay = null; $prevC = null;
    foreach ($dc as $d => $cv) {
        $prevClose[$iid][$d] = $prevC;
        if ($prevC !== null && $prevC > 0) {
            $g = $cv / $prevC - 1;
            if ($g >= $G20) {
                $g20Count[$d] = ($g20Count[$d] ?? 0) + 1;
                $nextDay = gmdate('Y-m-d', strtotime($d . ' +1 day'));
                $allowA[$iid][$nextDay] = true; // 前一日涨≥20% → 次日可买
            }
        }
        $prevDay = $d; $prevC = $cv;
    }
}
$g20Days = count($g20Count);
$g20CoinsAvg = $g20Days ? array_sum($g20Count) / $g20Days : 0;
arsort($g20Count);
echo "days(有≥20%涨幅币)=$g20Days avgCoins=" . round($g20CoinsAvg, 2) . " maxDay=" . key($g20Count) . "=" . current($g20Count) . "\n"; flush();

// ===== ① 全市场1D MA20 宽度 =====
$up = []; $tot = [];
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

// ===== ⑤ 熔断: BTC 1h 最近4根累计≤-3% =====
[$tsB, , , , $cB] = load_k('btc', '1h');
$meltSet = [];
for ($i = 4; $i < count($cB); $i++) {
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;
    }
}
echo "melt hours=" . count($meltSet) . "\n"; flush();

// ===== 合约清单 =====
$tbls = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline_%_usdt_swap_1h' ORDER BY table_name");
$insts = [];
foreach ($tbls as $x) { if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $x[0], $m)) $insts[] = $m[1]; }
echo "insts=" . count($insts) . "\n"; flush();

// ===== A方案 sim(与 eth_allmarket 完全同参) =====
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
            if ($adds < 5 && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {
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
$variants = ['A' => '昨涨≥20%', 'B' => '盘中≥20%'];
$summary = []; $contractStats = []; $dailyPnl = []; $sample = [];
$entryCnt = ['A' => 0, 'B' => 0];
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
    [$ts4h, , , , $c4h] = load_k($inst, '4h');
    $has4 = count($ts4h) >= 10;
    $ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);
    $ma5_4h = $has4 ? ma($c4h, 5) : [];
    $tr4hUpF = []; $p4 = 0; $cur4 = null; $n4 = count($ts4h);
    foreach ($ts1h as $tt) {
        while ($p4 < $n4 && $ts4h[$p4] <= $tt) { if ($ma5_4h[$p4] !== null) $cur4 = $c4h[$p4] > $ma5_4h[$p4]; $p4++; }
        $tr4hUpF[$tt] = $cur4;
    }
    $br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h);
    $fib = fibsig($c1h);
    // 六重共振基础命中
    $base = [];
    for ($i = 30; $i < $n; $i++) {
        $t = $ts1h[$i];
        if (!empty($meltSet[$t])) continue;
        $b = ffillBr($t);
        if ($b === null || $b <= 0.5) continue;
        if ($ma20[$i] === null || $c1h[$i] <= $ma20[$i]) continue;
        if ($ma10[$i] === null || $c1h[$i] <= $ma10[$i]) continue;
        if (empty($tr4hUpF[$t])) continue;
        if ($br1h[$i] < 0.5) continue;
        $base[$t] = $i;
    }
    // A口径: 当天在前一日涨≥20%名单
    $entryA = [];
    foreach ($base as $t => $i) {
        $d = gmdate('Y-m-d', (int)($t / 1000));
        if (!empty($allowA[$inst][$d])) $entryA[$t] = true;
    }
    // B口径: 盘中涨幅 = 1h收盘/前一日日线收盘 - 1 ≥ 20%
    $entryB = [];
    if (!empty($prevClose[$inst])) {
        $pc = $prevClose[$inst];
        $pcDays = array_keys($pc);
        foreach ($base as $t => $i) {
            $d = gmdate('Y-m-d', (int)($t / 1000));
            if (!isset($pc[$d])) continue;
            $p0 = $pc[$d];
            if ($p0 === null || $p0 <= 0) continue;
            if ($c1h[$i] / $p0 - 1 >= $G20) $entryB[$t] = true;
        }
    }
    $entryCnt['A'] += count($entryA); $entryCnt['B'] += count($entryB);
    foreach ($variants as $vk => $vn) {
        $entry = ($vk == 'A') ? $entryA : $entryB;
        foreach ($ladders as $ld) {
            $tr = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entry, $ld['base']);
            $pl = 0.0; $w = 0; $cut = 0; $adds = 0;
            foreach ($tr as $x) {
                $pl += $x['pnl']; if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'CUT') $cut++; $adds += $x['adds'];
                $d = gmdate('Y-m-d', (int)($x['tout'] / 1000));
                $dailyPnl[$vk][$ld['name']][$d] = ($dailyPnl[$vk][$ld['name']][$d] ?? 0) + $x['pnl'];
            }
            $cnt = count($tr);
            $summary[$vk][$ld['name']]['n'] = ($summary[$vk][$ld['name']]['n'] ?? 0) + $cnt;
            $summary[$vk][$ld['name']]['win'] = ($summary[$vk][$ld['name']]['win'] ?? 0) + $w;
            $summary[$vk][$ld['name']]['cut'] = ($summary[$vk][$ld['name']]['cut'] ?? 0) + $cut;
            $summary[$vk][$ld['name']]['liq'] = ($summary[$vk][$ld['name']]['liq'] ?? 0);
            $summary[$vk][$ld['name']]['to'] = ($summary[$vk][$ld['name']]['to'] ?? 0);
            $summary[$vk][$ld['name']]['adds'] = ($summary[$vk][$ld['name']]['adds'] ?? 0) + $adds;
            $summary[$vk][$ld['name']]['total'] = ($summary[$vk][$ld['name']]['total'] ?? 0) + $pl;
            if ($cnt > 0) $contractStats[$vk][$ld['name']][] = ['inst' => strtoupper($inst), 'n' => $cnt, 'pnl' => round($pl, 2), 'wr' => round(100 * $w / $cnt, 1)];
            if ($vk == 'A' && $inst === 'eth') $sample[$ld['name']] = array_slice($tr, -40);
        }
    }
    $done++;
    if ($done % 40 == 0) { echo "progress $done/" . count($insts) . "\n"; flush(); }
    unset($ts1h, $o1h, $h1h, $l1h, $c1h, $ts4h, $c4h, $ma20, $ma10, $ma5_4h, $tr4hUpF, $br1h, $fib, $base, $entryA, $entryB);
}
echo "entryBars A={$entryCnt['A']} B={$entryCnt['B']}\n"; flush();

// 组合曲线/排行
$curve = [];
foreach ($variants as $vk => $vn) {
    foreach ($ladders as $ld) {
        $nm = $ld['name'];
        $eq = $BAL0; $peak = $BAL0; $maxDD = 0.0;
        $days = array_keys($dailyPnl[$vk][$nm] ?? []); sort($days);
        $c2 = [];
        foreach ($days as $d) { $eq += $dailyPnl[$vk][$nm][$d]; if ($eq > $peak) $peak = $eq; if ($peak - $eq > $maxDD) $maxDD = $peak - $eq; $c2[] = ['d' => $d, 'eq' => round($eq, 2)]; }
        $curve[$vk][$nm] = ['days' => $c2, 'max_dd' => round($maxDD, 2)];
        $worst = []; foreach ($dailyPnl[$vk][$nm] ?? [] as $d => $v) $worst[] = ['d' => $d, 'pnl' => round($v, 2)];
        usort($worst, function ($a, $b) { return $a['pnl'] <=> $b['pnl']; });
        $curve[$vk][$nm]['worst_days'] = array_slice($worst, 0, 8);
        $curve[$vk][$nm]['best_days'] = array_slice(array_reverse($worst), 0, 8);
    }
}
$rank = [];
foreach ($variants as $vk => $vn) foreach ($ladders as $ld) {
    $arr = $contractStats[$vk][$ld['name']] ?? [];
    usort($arr, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; });
    $rank[$vk][$ld['name']] = ['top' => array_slice($arr, 0, 10), 'bottom' => array_slice(array_reverse($arr), 0, 10)];
}
$spanDays = ($gMax / 1000 - $gMin / 1000) / 86400;
$g20Top = [];
foreach (array_slice($g20Count, 0, 10, true) as $d => $k) $g20Top[] = ['d' => $d, 'coins' => $k];
$out = [
    'params' => ['lev' => 100, 'bal0' => $BAL0, 'cap_per_trade' => round($CAP, 2), 'tp' => '价格+2%(自均价)',
        'backstop' => '50%兜底强平: 浮亏达到该笔总保证金(含加仓)×50% → 立即市价全平',
        'entry' => '六重共振全命中: ①全市场1D MA20宽度>50% ②1h>MA20 ③1h>MA10 ④4h>MA5 ⑤非熔断(BTC 1h最近4根累计≤-3%) ⑥taker买比≥50% + 前置过滤「日涨幅≥20%」',
        'filterA' => 'A昨涨口径(无未来函数): 前一日日线涨幅≥20% → 当天可买',
        'filterB' => 'B盘中口径: 当日盘中涨幅(1h收盘/前一日日线收盘-1)≥20% → 该根之后可买',
        'add' => 'fib_618黄金回调+仅上行(现价>均价) ≤5轮 每轮=开仓保证金; 下跌不减仓',
        'note' => '加仓/兜底口径与A方案完全同参; 每合约同时只1仓; 保证金封顶=500/6(组合不复利)',
        'span' => round($spanDays) . '天',
        'range' => date('Y-m-d', (int)($gMin / 1000)) . ' ~ ' . date('Y-m-d', (int)($gMax / 1000)),
        'insts' => count($insts)],
    'g20_stats' => ['days_with_g20' => $g20Days, 'avg_coins' => round($g20CoinsAvg, 2), 'top_days' => $g20Top],
    'entry_bars' => $entryCnt,
    'summary' => $summary, 'curve' => $curve, 'rank' => $rank,
    'sample' => array_map(function ($t) {
        return ['tin' => date('m-d H:i', (int)($t['tin'] / 1000)), 'tout' => date('m-d H:i', (int)($t['tout'] / 1000)),
                'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'pnl' => $t['pnl']];
    }, $sample['1U+3U/天'] ?? []),
];
file_put_contents('E:/finally-main/web/eth_gain20_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK\n";
foreach ($variants as $vk => $vn) foreach ($ladders as $ld) { $nm = $ld['name'];
    echo "$vn $nm: " . json_encode($summary[$vk][$nm] ?? [], JSON_UNESCAPED_UNICODE) . " maxDD=" . ($curve[$vk][$nm]['max_dd'] ?? '-') . "\n"; }
