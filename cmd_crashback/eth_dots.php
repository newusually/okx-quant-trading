<?php
/**
 * eth_dots.php — 全市场(剔除美股/ETF/商品代币化) 量化做T·1分钟轨道  回测(2026-09-26 用户指令)
 * 用户四步骤(口径校准说明见下):
 *   1. 确认周期 = 1分钟K线
 *   2. 预判波动大 = CCI(20)>200(买多侧) / CCI(20)<-200(卖空侧) —— 用户: "CCI<-200 或者 cci>200 这种就是波动大"
 *   3. 轨道敞口变大(波动放大·信号准备) = 布林带宽 > 20根前的带宽×1.05
 *   4. 交叉点动手 = 波动曲线(1m收盘价)与布林轨(20,2σ)的交叉:
 *        曲线上穿上轨 = 先买多后卖(回落中轨平仓)   [字面"下轨上穿上轨"经探针证实同尺度布林物理不可能(330合约0信号), 按做T标准口径=价格上穿轨道]
 *        曲线下穿下轨 = 先卖空后买(回升中轨平仓)
 *   平仓 = 回归中轨(做T的卖/买回) / 爆仓线±0.6%@100x照模拟 / 超时240根(4小时)
 *   范围 = 全市场剔除美股/ETF/指数/商品/外汇代币化(与 eth_sixchipall.php 同清单)
 *   1m 数据窗口 = 09-21 07:14 ~ 09-26 09:25 约5.1天(OKX库中全市场1m仅此范围)
 *   每笔固定1U | 每合约独立500U | 无加仓 | 净口径含手续费+资金费
 * 输出: web/dots_data.json
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$EQ0 = 500.0;
$P = 20; $K = 2.0; $CCI_N = 20; $CCI_THR = 200.0; $BW_LOOK = 20; $BW_EXP = 1.05; $TO_BARS = 240;

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
$results = []; $done = 0; $g0 = null; $g1 = null;
foreach ($insts as $inst) {
    [$ts, $o, $h, $l, $c] = load_1m($inst);
    $n = count($ts);
    if ($n < $P + $BW_LOOK + 50) { $results[$inst] = ['skip' => '1m数据不足']; continue; }
    if ($g0 === null || $ts[0] < $g0) $g0 = $ts[0];
    if ($g1 === null || $ts[$n - 1] > $g1) $g1 = $ts[$n - 1];

    // ---- 增量指标: CCI(20) / 布林(20,2σ) ----
    $cci = array_fill(0, $n, null);
    $up = array_fill(0, $n, null); $lo = array_fill(0, $n, null); $mid = array_fill(0, $n, null);
    $tpQ = []; $sTP = 0.0;
    $q = []; $s = 0.0; $s2 = 0.0;
    for ($i = 0; $i < $n; $i++) {
        if ($c[$i] <= 0) continue;
        $tp = ($h[$i] + $l[$i] + $c[$i]) / 3;
        $tpQ[] = $tp; $sTP += $tp;
        if (count($tpQ) > $CCI_N) $sTP -= array_shift($tpQ);
        if (count($tpQ) == $CCI_N) {
            $maTP = $sTP / $CCI_N; $md = 0.0;
            foreach ($tpQ as $v) $md += abs($v - $maTP);
            $md /= $CCI_N;
            if ($md > 0) $cci[$i] = ($tp - $maTP) / (0.015 * $md);
        }
        $q[] = $c[$i]; $s += $c[$i]; $s2 += $c[$i] * $c[$i];
        if (count($q) > $P) { $old = array_shift($q); $s -= $old; $s2 -= $old * $old; }
        if (count($q) == $P) {
            $m = $s / $P; $v = $s2 / $P - $m * $m; if ($v < 0) $v = 0; $sd = sqrt($v);
            $mid[$i] = $m; $up[$i] = $m + $K * $sd; $lo[$i] = $m - $K * $sd;
        }
    }

    // ---- 信号: 价格穿越轨道 + CCI方向 + 敞口放大 ----
    $sigL = []; $sigS = []; $cntL = 0; $cntS = 0;
    for ($i = $P + $BW_LOOK; $i < $n; $i++) {
        if ($up[$i] === null || $up[$i - 1] === null || $up[$i - $BW_LOOK] === null) continue;
        $bw = $up[$i] - $lo[$i]; $bwPrev = $up[$i - $BW_LOOK] - $lo[$i - $BW_LOOK];
        if ($bwPrev <= 0 || $bw <= $bwPrev * $BW_EXP) continue;
        $upCross = ($c[$i - 1] <= $up[$i - 1] && $c[$i] > $up[$i]);     // 曲线上穿上轨 → 买多
        $dnCross = ($c[$i - 1] >= $lo[$i - 1] && $c[$i] < $lo[$i]);     // 曲线下穿下轨 → 卖空
        if ($upCross && $cci[$i] !== null && $cci[$i] > $CCI_THR) { $sigL[$i] = true; $cntL++; }
        if ($dnCross && $cci[$i] !== null && $cci[$i] < -$CCI_THR) { $sigS[$i] = true; $cntS++; }
    }

    // ---- 模拟 ----
    $eq = $EQ0; $trades = []; $i = 0;
    while ($i < $n - 1) {
        $isL = isset($sigL[$i]); $isS = !$isL && isset($sigS[$i]);
        if (!$isL && !$isS) { $i++; continue; }
        if ($o[$i + 1] <= 0) { $i++; continue; }
        $side = $isL ? 'LONG' : 'SHORT';
        $M0 = 1.0; $e = $o[$i + 1];
        $notional = $M0 * $LEV; $fee = $notional * $FEE_MAKER; $funding = 0.0;
        $liqPx = $isL ? $e * (1 - $liqDrop) : $e * (1 + $liqDrop);
        $outcome = null; $j = $i + 1;
        while ($j < $n) {
            if ($h[$j] <= 0 || $l[$j] <= 0) { $j++; continue; }
            $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 480;
            $hitLiq = $isL ? ($l[$j] <= $liqPx) : ($h[$j] >= $liqPx);
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            // 回归中轨 = 做T的卖/买回
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
        $gross = $isL ? (($exitPx - $e) * $notional / $e) : (($e - $exitPx) * $notional / $e);
        $fee += $notional * $FEE_TAKER;
        $pnl = $gross - $fee - $funding;
        if ($pnl < -$M0) $pnl = -$M0;
        $eq += $pnl;
        $trades[] = ['side' => $side, 'tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome,
                     'm0' => $M0, 'pnl' => round($pnl, 4), 'eq' => round($eq, 2),
                     'holdm' => (int)(($ts[$j] - $ts[$i]) / 60000)];
        $i = $j + 1;
    }
    // 汇总
    $tot = ['n' => count($trades), 'xrev' => 0, 'liq' => 0, 'to' => 0, 'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0];
    $holdSum = 0;
    foreach ($trades as $x) {
        if ($x['out'] == 'XREV') $tot['xrev']++;
        if ($x['out'] == 'LIQ') $tot['liq']++;
        if ($x['out'] == 'TO') $tot['to']++;
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
    $results[$inst] = [
        'summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),
        'span' => [bj($ts[0]), bj($ts[$n - 1])], 'bars' => $n, 'sigL' => $cntL, 'sigS' => $cntS,
        'days' => $days, 'trades' => $trades,
    ];
    $done++;
    if ($done % 60 == 0) { echo "progress $done/" . count($insts) . " ($inst pnl={$tot['pnl']})\n"; flush(); }
    unset($ts, $o, $h, $l, $c, $cci, $up, $lo, $mid, $trades);
}

// ===== 汇总 =====
$totN = 0; $totPnl = 0.0; $totX = 0; $totLiq = 0; $lp = 0.0; $sp = 0.0;
$rank = [];
foreach ($results as $inst => $r) {
    if (isset($r['skip'])) continue;
    $totN += $r['summary']['n']; $totPnl += $r['summary']['pnl'];
    $totX += $r['summary']['xrev']; $totLiq += $r['summary']['liq'];
    $lp += $r['summary']['l_pnl']; $sp += $r['summary']['s_pnl'];
    $rank[] = ['inst' => $inst, 'n' => $r['summary']['n'], 'pnl' => $r['summary']['pnl'], 'eq_end' => $r['eq_end'],
               'xrev' => $r['summary']['xrev'], 'liq' => $r['summary']['liq'], 'maxdd' => $r['maxdd'],
               'hold' => $r['summary']['hold_avg'], 'l_pnl' => $r['summary']['l_pnl'], 's_pnl' => $r['summary']['s_pnl']];
}
usort($rank, fn($a, $b) => $b['pnl'] <=> $a['pnl']);
$posCnt = count(array_filter($rank, fn($x) => $x['pnl'] > 0));
echo sprintf("TOTAL insts=%d excluded=%d traded=%d n=%d xrev=%d liq=%d pnl=%.1f (L=%.1f S=%.1f) posInsts=%d\n",
    count($insts), $exclN, count($rank), $totN, $totX, $totLiq, $totPnl, $lp, $sp, $posCnt);

$allDays = [];
foreach ($results as $inst => $r) {
    if (isset($r['skip'])) continue;
    foreach ($r['days'] as $d => $v) $allDays[$d] = round(($allDays[$d] ?? 0) + $v, 2);
}
ksort($allDays);

$out = [
    'meta' => [
        'lev' => $LEV, 'eq0' => $EQ0, 'insts' => count($insts), 'excluded' => $exclN,
        'window' => [bj($g0), bj($g1)],
        'params' => '量化做T四步骤: ①周期=1分钟K线 ②波动大=CCI(20)方向对齐(买多CCI>200/卖空CCI<-200) ③轨道敞口放大=布林带宽>20根前×1.05(波动变大·信号准备) ④交叉点动手=波动曲线(1m收盘价)与布林轨(20,2σ)交叉: 曲线上穿上轨=先买多后卖, 曲线下穿下轨=先卖空后买 [注: 用户原话"下轨上穿上轨"经探针证实同尺度快慢布林物理不可能(全市场0信号), 按做T标准口径=价格穿越轨道] | 平仓=回归中轨(做T的卖/买回)·爆仓线±0.6%@100x照模拟·超时240根(4小时) | 不加仓·每笔固定1U | 每合约独立500U | 净口径含手续费(maker进/taker出)+资金费 | 1m数据窗口=OKX库仅存09-21 07:14起约5.1天(全市场一致, 一个月1m未入库)',
    ],
    'total' => ['insts' => count($rank), 'skipped' => count($insts) - count($rank), 'n' => $totN, 'xrev' => $totX, 'liq' => $totLiq,
                'pnl' => round($totPnl, 2), 'l_pnl' => round($lp, 2), 's_pnl' => round($sp, 2),
                'pos_insts' => $posCnt, 'days' => $allDays],
    'rank' => $rank, 'results' => $results,
];
file_put_contents('E:/finally-main/web/dots_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "DATA OK\n";
