<?php
/**
 * eth_sixall.php — 全市场合约 六重共振牛熊双向回测(CLI, 2026-09-26 用户指令)
 * 用户: "给我全部更换买入卖空和加仓信号 改成全部六重共振信号 回测一年试一下 还是牛熊判定 然后所有合约搞一下 一年"
 *   信号定义(全部=六重共振, 不再用三均线):
 *     多头信号 = 牛市环境六条件从false→true的【新鲜触发】15m K线(逐根判定, 1h/4h/d条件向前填充)
 *       ① 全市场1D MA20宽度>50% ②本合约1h>MA20 ③1h>MA10 ④4h>MA5 ⑤非熔断(BTC 1h近4根跌≤-3%双向禁入) ⑥taker买比≥50%(本合约1h方向代理)
 *     空头信号 = 六条件全部反向 从false→true的新鲜触发15m K线
 *     加仓 = 持仓中同方向六重共振再次新鲜触发 + 顺向(多:现价>均价/空:现价<均价), 不限轮数, 每轮+1U, 无仓位上限
 *   保证金: 每笔固定1U(用户指令2026-09-26: 独立每个1U, 无阶梯不封顶) | 止盈: 价格±2% | 不设止损 | 爆仓线±0.6%@100x照模拟 | 超时7天
 *   资金费: 多头付/空头收 0.01%/8h | 净口径含手续费 | 每合约独立起始500U, 每笔固定1U
 *   范围: kline库全部 476 个 *-USDT-SWAP 合约, 15m主时间轴一年
 * 输出: web/sixall_data.json
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$EQ0 = 500.0; $ADDU = 1.0; $CAPM = 50.0;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }

function load_k($inst, $bar) {
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");
    $ts = []; $o = []; $h = []; $l = []; $c = [];
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }
    return [$ts, $o, $h, $l, $c];
}
function buyratio($o, $h, $l, $c) {
    $n = count($c); $br = [];
    for ($i = 0; $i < $n; $i++) {
        $rng = $h[$i] - $l[$i];
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;
        $br[$i] = 0.5 + $dir / 2;
    }
    return $br;
}

// ===== 全局① 全市场 1D MA20 宽度 =====
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
$brDays = array_keys($brSrc); sort($brDays);
function ffillBr($t) {
    global $brSrc, $brDays;
    $lo = 0; $hi = count($brDays) - 1; $res = null;
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($brDays[$mid] <= $t) { $res = $brDays[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }
    return $res !== null ? $brSrc[$res] : null;
}
echo "breadth days=" . count($brSrc) . "\n"; flush();

// ===== 全局⑤ BTC 熔断 =====
[$tsB, , , , $cB] = load_k('btc', '1h');
$meltSet = [];
for ($i = 4; $i < count($cB); $i++) {
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;
    }
}
unset($rr, $tsB, $cB);

// ===== 合约清单 =====
$insts = [];
foreach (rows("SHOW TABLES LIKE 'kline\\_%\\_usdt\\_swap\\_15m'") as $x) {
    if (preg_match('/^kline_(.+)_usdt_swap_15m$/', $x[0], $m)) $insts[] = $m[1];
}
sort($insts);
echo "insts=" . count($insts) . "\n"; flush();

// ===== 逐合约回测 =====
$liqDrop = 1.0 / $LEV - $MMR;
$results = []; $done = 0;
foreach ($insts as $inst) {
    [$tsF, $oF, $hF, $lF, $cF] = load_k($inst, '15m');
    $nF = count($tsF);
    if ($nF < 3000) { $results[$inst] = ['skip' => '15m数据不足(' . $nF . '根)']; continue; }  // <~31天跳过
    [$ts1, , , , $c1] = load_k($inst, '1h');
    [$ts4, , , , $c4] = load_k($inst, '4h');
    $n1 = count($ts1); $n4 = count($ts4);
    if ($n1 < 300 || $n4 < 80) { $results[$inst] = ['skip' => '1h/4h数据不足']; continue; }
    $ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5);
    $br1h = buyratio($oF, $hF, $lF, $cF); // placeholder overwritten below
    [$ts1b, $o1b, $h1b, $l1b, $c1b] = load_k($inst, '1h');
    $br1h = buyratio($o1b, $h1b, $l1b, $c1b);

    // 六条件状态 + 新鲜信号
    $sixL = false; $sixS = false; $sigL = []; $sigS = [];
    $p1 = 0; $p4 = 0; $cur20 = null; $cur10 = null; $cur4 = null;
    $span0 = $tsF[0]; $span1 = $tsF[$nF - 1];
    for ($i = 0; $i < $nF; $i++) {
        $t = $tsF[$i];
        while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; }
        while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; }
        $hb = (int)floor($t / 3600000) * 3600000;
        $b = ffillBr($t);
        $nl = false; $ns = false;
        if ($b !== null && !isset($meltSet[$hb])) {
            $lo = 0; $hi = $n1 - 1; $ib = -1;
            while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }
            if ($ib >= 0) {
                $br = $br1h[$ib];
                if ($b > 0.5 && $cur20 === true && $cur10 === true && $cur4 === true && $br >= 0.5) $nl = true;
                if ($b < 0.5 && $cur20 === false && $cur10 === false && $cur4 === false && $br < 0.5) $ns = true;
            }
        }
        if ($nl && !$sixL) $sigL[$i] = true;
        if ($ns && !$sixS) $sigS[$i] = true;
        $sixL = $nl; $sixS = $ns;
    }
    $cntL = count($sigL); $cntS = count($sigS);
    unset($ts1, $ts4, $c1, $c4, $ma20, $ma10, $ma5_4, $ts1b, $o1b, $h1b, $l1b, $c1b, $br1h);

    // 单合约双向顺序模拟(独立500U)
    $eq = $EQ0; $startT = $tsF[0];
    $trades = []; $i = 0;
    while ($i < $nF - 1) {
        $isL = isset($sigL[$i]); $isS = !$isL && isset($sigS[$i]);
        if (!$isL && !$isS) { $i++; continue; }
        if ($i + 1 >= $nF || $oF[$i + 1] <= 0) { $i++; continue; }   // 脏数据: 0价格开盘跳过
        $side = $isL ? 'LONG' : 'SHORT';
        $M0 = 1.0;   // 用户指令(2026-09-26): 每笔固定1U保证金, 无阶梯不封顶
        $e = $oF[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER; $funding = 0.0;
        if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); }
        else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - $TPR); }
        $outcome = null; $j = $i + 1;
        while ($j < $nF) {
            if ($hF[$j] <= 0 || $lF[$j] <= 0) { $j++; continue; }   // 脏数据: 0高低价K线跳过
            $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 32;
            $hitLiq = $isL ? ($lF[$j] <= $liqPx) : ($hF[$j] >= $liqPx);
            $hitTp  = $isL ? ($hF[$j] >= $tgtPx) : ($lF[$j] <= $tgtPx);
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($j - $i >= 7 * 24 * 4) { $outcome = 'TO'; $exitPx = $cF[$j]; break; }
            // 加仓: 同方向六重共振再次新鲜触发 + 顺向
            $sigOk = $isL ? (isset($sigL[$j]) && $cF[$j] > $avg) : (isset($sigS[$j]) && $cF[$j] < $avg);
            if ($sigOk && $j + 1 < $nF) {
                $ap = $oF[$j + 1];
                if ($ap > 0) {
                    $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);
                    $notional += $ADDU * $LEV; $adds++;
                    $fee += $ADDU * $LEV * $FEE_TAKER;
                    if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); }
                    else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - $TPR); }
                }
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $nF - 1; $exitPx = $cF[$j]; }
        $gross = $isL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);
        $fee += $notional * $FEE_TAKER;
        $pnl = $gross - $fee - $funding;
        $mg = $M0 + $adds * $ADDU;
        if ($pnl < -$mg) $pnl = -$mg;
        $eq += $pnl;
        $trades[] = ['side' => $side, 'tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds,
                     'm0' => $M0, 'mg' => $mg, 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),
                     'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];
        $i = $j + 1;
    }
    // 汇总
    $tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'adds' => 0, 'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0];
    foreach ($trades as $x) {
        if ($x['out'] == 'WIN') $tot['win']++;
        if ($x['out'] == 'LIQ') $tot['liq']++;
        if ($x['out'] == 'TO') $tot['to']++;
        $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl'];
        if ($x['side'] == 'LONG') { $tot['l_n']++; $tot['l_pnl'] += $x['pnl']; }
        else { $tot['s_n']++; $tot['s_pnl'] += $x['pnl']; }
    }
    $tot['pnl'] = round($tot['pnl'], 2); $tot['l_pnl'] = round($tot['l_pnl'], 2); $tot['s_pnl'] = round($tot['s_pnl'], 2);
    $peak = -INF; $maxdd = 0;
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }
    $months = [];
    foreach ($trades as $x) {
        $m = gmdate('Y-m', (int)($x['tout'] / 1000) + 8 * 3600);
        $mo = &$months[$m];
        $mo['n'] = ($mo['n'] ?? 0) + 1;
        $mo['pnl'] = round(($mo['pnl'] ?? 0) + $x['pnl'], 2);
        $mo['eq_end'] = $x['eq'];
        unset($mo);
    }
    $results[$inst] = [
        'summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),
        'span' => [bj($span0), bj($span1)], 'bars' => $nF, 'sigL' => $cntL, 'sigS' => $cntS,
        'months' => $months, 'trades' => $trades,
    ];
    $done++;
    if ($done % 40 == 0) { echo "progress $done/" . count($insts) . " ($inst pnl={$tot['pnl']})\n"; flush(); }
    unset($tsF, $oF, $hF, $lF, $cF, $trades);
}

// ===== 汇总 =====
$totN = 0; $totPnl = 0.0; $totWin = 0; $totLiq = 0; $totAdds = 0; $lp = 0.0; $sp = 0.0;
$rank = [];
foreach ($results as $inst => $r) {
    if (isset($r['skip'])) continue;
    $totN += $r['summary']['n']; $totPnl += $r['summary']['pnl'];
    $totWin += $r['summary']['win']; $totLiq += $r['summary']['liq']; $totAdds += $r['summary']['adds'];
    $lp += $r['summary']['l_pnl']; $sp += $r['summary']['s_pnl'];
    $rank[] = ['inst' => $inst, 'n' => $r['summary']['n'], 'pnl' => $r['summary']['pnl'], 'eq_end' => $r['eq_end'],
               'win' => $r['summary']['win'], 'liq' => $r['summary']['liq'], 'maxdd' => $r['maxdd'],
               'l_pnl' => $r['summary']['l_pnl'], 's_pnl' => $r['summary']['s_pnl']];
}
usort($rank, fn($a, $b) => $b['pnl'] <=> $a['pnl']);
$posCnt = count(array_filter($rank, fn($x) => $x['pnl'] > 0));
echo sprintf("TOTAL insts=%d traded=%d n=%d win=%d liq=%d adds=%d pnl=%.1f (L=%.1f S=%.1f) posInsts=%d\n",
    count($insts), count($rank), $totN, $totWin, $totLiq, $totAdds, $totPnl, $lp, $sp, $posCnt);

// 全市场月度合并
$allMon = [];
foreach ($results as $inst => $r) {
    if (isset($r['skip'])) continue;
    foreach ($r['months'] as $m => $v) {
        $allMon[$m] = round(($allMon[$m] ?? 0) + $v['pnl'], 2);
    }
}
ksort($allMon);

$out = [
    'meta' => [
        'lev' => $LEV, 'tp' => '±2%', 'eq0' => $EQ0, 'insts' => count($insts),
        'params' => '信号全部=六重共振(不再用三均线): 多头信号=牛市六条件(全市场1D MA20宽度>50%/本合约1h>MA20/1h>MA10/4h>MA5/taker买比≥50%/BTC熔断禁入)从false→true的新鲜触发15m K线; 空头信号=六条件全部反向的新鲜触发; 加仓=持仓中同方向六重共振再次新鲜触发+顺向(多:现价>均价/空:现价<均价) 不限轮数 每轮+1U 无仓位上限 | 保证金=每笔固定1U(无阶梯不封顶, 用户指令) | 止盈=价格±2% | 不设止损·爆仓线±0.6%@100x照模拟 | 超时7天 | 资金费=多头付/空头收 0.01%/8h | 每合约独立起始500U·每笔1U | 净口径含手续费',
    ],
    'total' => ['insts' => count($rank), 'skipped' => count($insts) - count($rank), 'n' => $totN, 'win' => $totWin, 'liq' => $totLiq,
                'adds' => $totAdds, 'pnl' => round($totPnl, 2), 'l_pnl' => round($lp, 2), 's_pnl' => round($sp, 2),
                'pos_insts' => $posCnt, 'months' => $allMon],
    'rank' => $rank, 'results' => $results,
];
file_put_contents('E:/finally-main/web/sixall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "DATA OK\n";
