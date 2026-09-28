<?php
/**
 * eth_dots2.php — 全市场(剔除美股/ETF/商品代币化) 量化做T·均值回归/固定小止盈 对照回测(2026-09-26 用户指令)
 * 上一版 eth_dots.php(突破方向追波动) 全市场 -1,661.8U 全日亏损, 报告改法建议:
 *   "反向做(上轨做空/下轨做多, 赚均值回归) 或把止盈改成固定小目标(+0.3%)快进快出"
 * 用户指令: "上轨做空/下轨做多，赚均值回归）或改固定小止盈（+0.3%）快进快出 给我试一下回测一下"
 * 三个对照组合(信号完全相同 = 1m收盘价穿越布林(20,2σ)轨道 + CCI(20)极端 + 敞口放大, 仅方向/平仓不同):
 *   A 均值回归     : 上轨触发→做空 / 下轨触发→做多 ; 平仓=回归中轨
 *   B 均值回归+TP  : 同A方向 ; 平仓=固定止盈+0.3%价(挂单maker) / 回归中轨 先到为准
 *   C 突破方向+TP  : 上轨触发→做多 / 下轨触发→做空(上一版原方向) ; 平仓=固定止盈+0.3% / 回归中轨
 *   爆仓线±0.6%@100x照模拟 / 超时240根(4小时) ; 净口径含手续费+资金费
 *   每笔固定1U | 每合约独立500U | 无加仓 | 1m数据窗口 = 09-21 07:14 起约5.1天
 * 输出: web/dots2_data.json
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$EQ0 = 500.0;
$P = 20; $K = 2.0; $CCI_N = 20; $CCI_THR = 200.0; $BW_LOOK = 20; $BW_EXP = 1.05; $TO_BARS = 240;
$TP = 0.003; // 固定小止盈 +0.3% 价格(100x 下 = +30% 收益率)

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }

$EXCL = explode(' ', 'aaoi aapl adbe amd alab amat amc amzn anthropic app asml avgo axti bill brkb bx cien coin cost crcl crdo crm crwv csco cxmt ddog dell dgai dkng gme glw gpro gps gtlb hanmi hims hood hpe hut hyundai ibm intc ionq iren isrg jnj kioxia klac lgelectronics lly lrcx lunr mara meta mrvl mrk mrna msft mstr mstu mu naver nbis net nflx nok now nvda nvdl oklo okta onds on openai orcl oscr oura oust poet pypl qcom rddt rdw riot rivn rklb rok samsung shein shell simo skdd skhynix skhy smci sndk snow softbank sony spcx strc tsem tsla tsll tsm ttmi ttwo twlo unh vrt wdc wmt xiaom xiaomi xom zhipu zhongji zm ewj ewt ewy ewz iwm jp225 kr200 qqq smh soxl soxs spy sqqq tqqq tmf us100 us500 uvxy xbi xle uso urnm xau xag xpd xpt xcu cl bz');

function load_1m($inst) {
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_1m ORDER BY candle_time ASC");
    $ts = []; $o = []; $h = []; $l = []; $c = [];
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }
    return [$ts, $o, $h, $l, $c];
}

$insts = []; $exclN = 0;
foreach (rows("SHOW TABLES LIKE 'kline\\_%\\_usdt\\_swap\\_1m'") as $x) {
    if (preg_match('/^kline_(.+)_usdt_swap_1m$/', $x[0], $m)) {
        if (in_array($m[1], $EXCL, true)) { $exclN++; continue; }
        $insts[] = $m[1];
    }
}
sort($insts);
echo "insts=" . count($insts) . " excluded=$exclN\n"; flush();

$liqDrop = 1.0 / $LEV - $MMR;

/** 单组合模拟: $flip=true→上轨做空/下轨做多(均值回归) ; $tp>0→固定止盈+tp(挂单maker平仓) */
function sim($flip, $tp, $ts, $o, $h, $l, $c, $mid, $sigUp, $sigDn, $n) {
    global $LEV, $FEE_MAKER, $FEE_TAKER, $FUND8H, $EQ0, $TO_BARS, $liqDrop;
    $eq = $EQ0; $trades = []; $i = 0;
    while ($i < $n - 1) {
        if (isset($sigUp[$i]))      $isS = $flip;          // 上轨: flip→做空 / 突破→做多
        elseif (isset($sigDn[$i]))  $isS = !$flip;         // 下轨: flip→做多 / 突破→做空
        else { $i++; continue; }
        if ($o[$i + 1] <= 0) { $i++; continue; }
        $isL = !$isS;
        $M0 = 1.0; $e = $o[$i + 1];
        $notional = $M0 * $LEV; $fee = $notional * $FEE_MAKER; $funding = 0.0;
        $liqPx = $isL ? $e * (1 - $liqDrop) : $e * (1 + $liqDrop);
        $tpPx = $tp > 0 ? ($isL ? $e * (1 + $tp) : $e * (1 - $tp)) : 0.0;
        $outcome = null; $exitPx = 0.0; $exitMaker = false; $j = $i + 1;
        while ($j < $n) {
            if ($h[$j] <= 0 || $l[$j] <= 0) { $j++; continue; }
            $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 480;
            $hitLiq = $isL ? ($l[$j] <= $liqPx) : ($h[$j] >= $liqPx);
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($tpPx > 0) {
                $hitTp = $isL ? ($h[$j] >= $tpPx) : ($l[$j] <= $tpPx);
                if ($hitTp) { $outcome = 'XTP'; $exitPx = $tpPx; $exitMaker = true; break; } // 挂止盈单=maker
            }
            $rev = false;
            if ($mid[$j] !== null) {
                if ($isL) $rev = ($c[$j] <= $mid[$j]);
                else      $rev = ($c[$j] >= $mid[$j]);
            }
            if ($rev) { $outcome = 'XREV'; $exitPx = $c[$j]; break; }
            if ($j - $i >= $TO_BARS) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }
        $fee += $notional * ($exitMaker ? $FEE_MAKER : $FEE_TAKER);
        $gross = $isL ? (($exitPx - $e) * $notional / $e) : (($e - $exitPx) * $notional / $e);
        $pnl = $gross - $fee - $funding;
        if ($pnl < -$M0) $pnl = -$M0;
        $eq += $pnl;
        $trades[] = ['side' => $isL ? 'LONG' : 'SHORT', 'tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome,
                     'm0' => $M0, 'pnl' => round($pnl, 4), 'eq' => round($eq, 2),
                     'holdm' => (int)(($ts[$j] - $ts[$i]) / 60000)];
        $i = $j + 1;
    }
    return [$trades, $eq];
}

function summarize($trades, $eq, $ts, $n) {
    $tot = ['n' => count($trades), 'xtp' => 0, 'xrev' => 0, 'liq' => 0, 'to' => 0,
            'win' => 0, 'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0];
    $holdSum = 0;
    foreach ($trades as $x) {
        if ($x['out'] == 'XTP') $tot['xtp']++;
        if ($x['out'] == 'XREV') $tot['xrev']++;
        if ($x['out'] == 'LIQ') $tot['liq']++;
        if ($x['out'] == 'TO') $tot['to']++;
        if ($x['pnl'] > 0) $tot['win']++;
        $tot['pnl'] += $x['pnl']; $holdSum += $x['holdm'];
        if ($x['side'] == 'LONG') { $tot['l_n']++; $tot['l_pnl'] += $x['pnl']; }
        else { $tot['s_n']++; $tot['s_pnl'] += $x['pnl']; }
    }
    $tot['pnl'] = round($tot['pnl'], 2); $tot['l_pnl'] = round($tot['l_pnl'], 2); $tot['s_pnl'] = round($tot['s_pnl'], 2);
    $tot['hold_avg'] = $tot['n'] ? round($holdSum / $tot['n'], 1) : 0;
    $peak = -INF; $maxdd = 0;
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }
    $days = [];
    foreach ($trades as $x) {
        $d = gmdate('m-d', (int)($x['tout'] / 1000) + 8 * 3600);
        $days[$d] = round(($days[$d] ?? 0) + $x['pnl'], 2);
    }
    return ['summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),
            'span' => [bj($ts[0]), bj($ts[$n - 1])], 'days' => $days, 'trades' => $trades];
}

$VARIANTS = [
    'A' => ['name' => '均值回归(上轨空/下轨多)·回归中轨平仓', 'flip' => true,  'tp' => 0.0],
    'B' => ['name' => '均值回归(上轨空/下轨多)+固定止盈+0.3%快进快出', 'flip' => true,  'tp' => $TP],
    'C' => ['name' => '原突破方向(上轨多/下轨空)+固定止盈+0.3%', 'flip' => false, 'tp' => $TP],
];

$results = [];   // [variant][inst]
$done = 0; $g0 = null; $g1 = null; $gSigL = 0; $gSigS = 0;
foreach ($insts as $inst) {
    [$ts, $o, $h, $l, $c] = load_1m($inst);
    $n = count($ts);
    if ($n < $P + $BW_LOOK + 50) { foreach ($VARIANTS as $vk => $vc) $results[$vk][$inst] = ['skip' => '1m数据不足']; continue; }
    if ($g0 === null || $ts[0] < $g0) $g0 = $ts[0];
    if ($g1 === null || $ts[$n - 1] > $g1) $g1 = $ts[$n - 1];

    // ---- 增量指标: CCI(20) / 布林(20,2σ) ----
    $cci = array_fill(0, $n, null);
    $up = array_fill(0, $n, null); $lo = array_fill(0, $n, null); $mid = array_fill(0, $n, null);
    $tpQ = []; $sTP = 0.0;
    $q = []; $s = 0.0; $s2 = 0.0;
    for ($i = 0; $i < $n; $i++) {
        if ($c[$i] <= 0) continue;
        $tpv = ($h[$i] + $l[$i] + $c[$i]) / 3;
        $tpQ[] = $tpv; $sTP += $tpv;
        if (count($tpQ) > $CCI_N) $sTP -= array_shift($tpQ);
        if (count($tpQ) == $CCI_N) {
            $maTP = $sTP / $CCI_N; $md = 0.0;
            foreach ($tpQ as $v) $md += abs($v - $maTP);
            $md /= $CCI_N;
            if ($md > 0) $cci[$i] = ($tpv - $maTP) / (0.015 * $md);
        }
        $q[] = $c[$i]; $s += $c[$i]; $s2 += $c[$i] * $c[$i];
        if (count($q) > $P) { $old = array_shift($q); $s -= $old; $s2 -= $old * $old; }
        if (count($q) == $P) {
            $m = $s / $P; $v = $s2 / $P - $m * $m; if ($v < 0) $v = 0; $sd = sqrt($v);
            $mid[$i] = $m; $up[$i] = $m + $K * $sd; $lo[$i] = $m - $K * $sd;
        }
    }

    // ---- 信号(与上一版完全相同, 仅方向/平仓组合不同) ----
    $sigUp = []; $sigDn = []; $cntU = 0; $cntD = 0;
    for ($i = $P + $BW_LOOK; $i < $n; $i++) {
        if ($up[$i] === null || $up[$i - 1] === null || $up[$i - $BW_LOOK] === null) continue;
        $bw = $up[$i] - $lo[$i]; $bwPrev = $up[$i - $BW_LOOK] - $lo[$i - $BW_LOOK];
        if ($bwPrev <= 0 || $bw <= $bwPrev * $BW_EXP) continue;
        $upCross = ($c[$i - 1] <= $up[$i - 1] && $c[$i] > $up[$i]);
        $dnCross = ($c[$i - 1] >= $lo[$i - 1] && $c[$i] < $lo[$i]);
        if ($upCross && $cci[$i] !== null && $cci[$i] > $CCI_THR) { $sigUp[$i] = true; $cntU++; }
        if ($dnCross && $cci[$i] !== null && $cci[$i] < -$CCI_THR) { $sigDn[$i] = true; $cntD++; }
    }
    $gSigL += $cntU; $gSigS += $cntD;

    foreach ($VARIANTS as $vk => $vc) {
        [$trades, $eq] = sim($vc['flip'], $vc['tp'], $ts, $o, $h, $l, $c, $mid, $sigUp, $sigDn, $n);
        $r = summarize($trades, $eq, $ts, $n);
        $r['bars'] = $n; $r['sigU'] = $cntU; $r['sigD'] = $cntD;
        $results[$vk][$inst] = $r;
    }
    $done++;
    if ($done % 60 == 0) { echo "progress $done/" . count($insts) . " ($inst)\n"; flush(); }
    unset($ts, $o, $h, $l, $c, $cci, $up, $lo, $mid, $trades);
    gc_collect_cycles();
}

// ===== 汇总 =====
$totals = []; $ranks = []; $allDaysV = [];
foreach ($VARIANTS as $vk => $vc) {
    $totN = 0; $totPnl = 0.0; $tX = 0; $tLiq = 0; $tTo = 0; $tWin = 0; $lp = 0.0; $sp = 0.0;
    $rank = []; $days = [];
    foreach ($results[$vk] as $inst => $r) {
        if (isset($r['skip'])) continue;
        $st = $r['summary'];
        $totN += $st['n']; $totPnl += $st['pnl']; $tX += $st['xrev']; $tLiq += $st['liq'];
        $tTo += $st['to']; $tWin += $st['win']; $lp += $st['l_pnl']; $sp += $st['s_pnl'];
        $rank[] = ['inst' => $inst, 'n' => $st['n'], 'pnl' => $st['pnl'], 'eq_end' => $r['eq_end'],
                   'win' => $st['win'], 'xtp' => $st['xtp'], 'xrev' => $st['xrev'], 'liq' => $st['liq'], 'to' => $st['to'],
                   'maxdd' => $r['maxdd'], 'hold' => $st['hold_avg'],
                   'l_pnl' => $st['l_pnl'], 's_pnl' => $st['s_pnl']];
        foreach ($r['days'] as $d => $v) $days[$d] = round(($days[$d] ?? 0) + $v, 2);
    }
    usort($rank, fn($a, $b) => $b['pnl'] <=> $a['pnl']);
    $posCnt = count(array_filter($rank, fn($x) => $x['pnl'] > 0));
    $pnlList = array_column($rank, 'pnl'); sort($pnlList);
    $med = count($pnlList) ? $pnlList[intdiv(count($pnlList), 2)] : 0;
    ksort($days);
    $totals[$vk] = ['name' => $vc['name'], 'insts' => count($rank), 'skipped' => count($insts) - count($rank),
                    'n' => $totN, 'win' => $tWin, 'xrev' => $tX, 'liq' => $tLiq, 'to' => $tTo,
                    'pnl' => round($totPnl, 2), 'l_pnl' => round($lp, 2), 's_pnl' => round($sp, 2),
                    'pos_insts' => $posCnt, 'med_inst' => round($med, 2), 'days' => $days];
    $ranks[$vk] = $rank;
    echo sprintf("[%s] %s => n=%d win=%d xrev=%d liq=%d to=%d pnl=%.1f (L=%.1f S=%.1f) posInsts=%d med=%.2f\n",
        $vk, $vc['name'], $totN, $tWin, $tX, $tLiq, $tTo, $totPnl, $lp, $sp, $posCnt, $med);
    flush();
}

$out = [
    'meta' => [
        'lev' => $LEV, 'eq0' => $EQ0, 'insts' => count($insts), 'excluded' => $exclN,
        'window' => [bj($g0), bj($g1)], 'tp' => $TP, 'to_bars' => $TO_BARS,
        'params' => '上一版做T(突破方向追波动)全市场-1,661.8U全日亏损, 本轮按报告改法做三组对照。信号完全相同=1m收盘价穿越布林(20,2σ)轨道+CCI(20)极端(上轨CCI>200/下轨CCI<-200)+敞口放大(带宽>20根前×1.05), 仅方向与平仓不同: A=均值回归(上轨触发做空/下轨触发做多)·平仓=回归中轨; B=均值回归同A方向·平仓=固定止盈+0.3%价格(挂单maker)+回归中轨先到为准; C=原突破方向(上轨做多/下轨做空)+固定止盈+0.3%对照。爆仓线±0.6%@100x照模拟·超时240根(4小时)·每笔固定1U·每合约独立500U·净口径含手续费+资金费。1m数据窗口=OKX库仅存09-21 07:14起约5.1天',
    ],
    'variants' => array_map(fn($vk, $vc) => ['key' => $vk, 'name' => $vc['name'], 'flip' => $vc['flip'], 'tp' => $vc['tp']], array_keys($VARIANTS), $VARIANTS),
    'total' => $totals,
    'rank' => $ranks,
    'results' => $results,
];
file_put_contents('E:/finally-main/web/dots2_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "DATA OK\n";
