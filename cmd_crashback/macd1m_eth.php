<?php
/**
 * macd1m_eth.php — ETH 1m MACD(12,26,60) 两策略 一年回测生成器 (2026-09-27 用户指令)
 * 用户原话: "...1分钟数据...使用1分钟计算macd 参数是12,26,60 计算1分钟K线 30根 macd 0轴以下 有macd<-5
 *   然后后面macd>0买入 上涨10个点 卖出 100X 还有 macd<0 有超过50根都是这样 后面macd>0的时候买入
 *   再跌再加仓 直到不跌 也是 macd>0买入 上涨10个点 卖出 50美金是本金 帮我算一下"
 *
 * MACD 口径: DIF = EMA12-EMA26 · DEA = EMA60(DIF) (用户参数把标准9换成60) · MACD柱 = 2×(DIF-DEA) (中国软件口径)
 * 策略A(30根深柱反转): 最近30根内存在 DIF<0 且 MACD柱<-5 → 柱上穿>0 时买入 · 100x · 止盈卖出
 * 策略B(50根超卖+回调加仓): DIF<0 连续≥50根 → 柱>0 买入 · 再跌每-0.2%加仓一次(直到不跌/资金用完) · 均价止盈
 * 资金 50U/轮 · 100x(爆仓=1/100-0.4%=0.6%价格) · taker 0.05% · 止盈口径: 价格+10% / 价格+1% / 价格+0.1%(=ROI10%) / 价格+0.2%(ROI20%)
 * 输出: web/macd1meth_data.json
 */
ini_set('memory_limit', '6144M');
set_time_limit(0);
$LEV = 100; $MMR = 0.004; $FEE = 0.0005; $CAP = 50.0; $TMO_MS = 48 * 3600 * 1000;
$LIQ = 1.0 / $LEV - $MMR;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }

// ---- 1) 加载 1m ----
$gT0 = (time() - 368 * 86400) * 1000;
$r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_eth_usdt_swap_1m WHERE candle_time >= $gT0 ORDER BY candle_time ASC");
$ts = []; $o = []; $h = []; $l = []; $c = [];
while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0) continue; $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }
$r->free();
$n = count($c);
echo "bars=$n span=" . bj($ts[0]) . " ~ " . bj($ts[$n - 1]) . "\n";
if ($n < 100000) { echo "DATA INCOMPLETE - wait fill\n"; }

// ---- 2) MACD(12,26,60) ----
function emaAll($a, $p) {
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = $a[0];
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; }
    return $out;
}
$e12 = emaAll($c, 12); $e26 = emaAll($c, 26);
$dif = []; for ($i = 0; $i < $n; $i++) $dif[] = $e12[$i] - $e26[$i];
$dea = emaAll($dif, 60);
$macd = []; for ($i = 0; $i < $n; $i++) $macd[] = 2.0 * ($dif[$i] - $dea[$i]);
echo "macd computed\n";

// ---- 3) 信号 ----
// A: 最近30根内存在 DIF<0 && MACD<-5, 之后柱上穿>0
$sigA = [];
$lastDeepIdx = -999;
for ($i = 0; $i < $n; $i++) {
    if ($dif[$i] < 0 && $macd[$i] < -5) $lastDeepIdx = $i;
    if ($i - $lastDeepIdx <= 30 && $lastDeepIdx >= 0 && $macd[$i] > 0 && $macd[$i - 1] <= 0) {
        $sigA[] = $i; $lastDeepIdx = -999; // 触发后需重新出现深柱
    }
}
// B: DIF<0 连续>=50根 后, 柱上穿>0
$sigB = [];
$negRun = 0;
for ($i = 0; $i < $n; $i++) {
    if ($dif[$i] < 0) $negRun++;
    else $negRun = 0;
    if ($negRun >= 50 && $macd[$i] > 0 && $macd[$i - 1] <= 0) { $sigB[] = $i; }
}
echo "sigA=" . count($sigA) . " sigB=" . count($sigB) . "\n";

// ---- 4) 回测 ----
// 单轮: 本金50U(保证金), 100x。A: 全仓一次买入。B: 初始10U, 每较上次加仓价再跌0.2%加10U(总≤50U, 价格创新低才加=直到不跌)。
function simRound($ts, $o, $h, $l, $c, $i0, $TPPX, $mode, $LEV, $FEE, $LIQ, $CAP, $TMO_MS) {
    $n = count($c);
    $adds = [];
    if ($mode == 'A') { $adds[] = ['i' => $i0, 'u' => $CAP, 'px' => $c[$i0]]; $lastAddPx = $c[$i0]; $left = 0.0; }
    else {
        $u1 = 10.0; $adds[] = ['i' => $i0, 'u' => $u1, 'px' => $c[$i0]]; $lastAddPx = $c[$i0]; $left = $CAP - $u1;
    }
    $q = 0.0; $net = 0.0; $margin = 0.0;
    foreach ($adds as $a) { $netX = $a['u'] * $LEV * (1 - $FEE); $q += $netX / $a['px']; $net += $netX; $margin += $a['u']; }
    $avg = $net / $q;
    $tpPx = $avg * (1 + $TPPX); $liqPx = $avg * (1 - $LIQ);
    $why = ''; $exit = 0; $tExit = 0; $liqN = 0;
    for ($i = $i0 + 1; $i < $n; $i++) {
        // B: 回调加仓 (先于爆仓判定同根: 用当根低点判断加仓, 加仓价 = min(open, trigger))
        if ($mode == 'B' && $left >= 9.999 && $l[$i] <= $lastAddPx * (1 - 0.002)) {
            $addPx = min($o[$i], $lastAddPx * (1 - 0.002));
            $u2 = min(10.0, $left);
            $netX = $u2 * $LEV * (1 - $FEE);
            $q += $netX / $addPx; $net += $netX; $margin += $u2; $left -= $u2;
            $avg = $net / $q; $tpPx = $avg * (1 + $TPPX); $liqPx = $avg * (1 - $LIQ);
            $adds[] = ['i' => $i, 'u' => $u2, 'px' => $addPx]; $lastAddPx = $addPx;
        }
        if ($l[$i] <= $liqPx) { $why = 'LIQ'; $exit = $liqPx; $tExit = $ts[$i]; $liqN = 1; break; }
        if ($h[$i] >= $tpPx) { $exit = max($o[$i], $tpPx); $why = 'TP'; $tExit = $ts[$i]; break; }
        if ($ts[$i] - $ts[$i0] >= $TMO_MS) { $exit = $c[$i]; $why = 'TO'; $tExit = $ts[$i]; break; }
    }
    if ($why == '') { $exit = $c[$n - 1]; $why = 'END'; $tExit = $ts[$n - 1]; }
    $gross = $q * $exit - $net; $feeOut = $q * $exit * $FEE;
    $pnl = ($why == 'LIQ') ? -$margin : $gross - $feeOut;   // 爆仓=亏光全部已投入保证金
    return ['t0' => $ts[$i0], 't1' => $tExit, 'adds' => count($adds) - 1, 'margin' => $margin,
            'avg' => $avg, 'exit' => $exit, 'pnl' => $pnl, 'why' => $why];
}

$TPS = ['p10' => 0.10, 'p1' => 0.01, 'p01' => 0.001, 'p02' => 0.002];
$res = [];
foreach (['A' => $sigA, 'B' => $sigB] as $sk => $sigs) {
    foreach ($TPS as $tk => $tp) {
        $rounds = []; $pnlTotal = 0; $wins = 0; $liqN = 0; $toN = 0; $addN = 0; $marginUsed = 0.0;
        foreach ($sigs as $i0) {
            $rt = simRound($ts, $o, $h, $l, $c, $i0, $tp, $sk, $LEV, $FEE, $LIQ, $CAP, $TMO_MS);
            $pnlTotal += $rt['pnl']; if ($rt['pnl'] > 0) $wins++; if ($rt['why'] == 'LIQ') $liqN++; if ($rt['why'] == 'TO') $toN++;
            $addN += $rt['adds']; $marginUsed += $rt['margin'];
            $rounds[] = [$rt['t0'], $rt['t1'], $rt['adds'], round($rt['avg'], 2), round($rt['exit'], 2), round($rt['pnl'], 2), $rt['why']];
        }
        $res[$sk][$tk] = ['n' => count($sigs), 'pnl' => round($pnlTotal, 2), 'win' => $wins,
            'wr' => count($sigs) ? round($wins / count($sigs) * 100, 1) : 0, 'liq' => $liqN, 'to' => $toN,
            'adds' => $addN, 'marginUsed' => round($marginUsed, 1),
            'trades' => count($rounds) > 400 ? array_slice($rounds, 0, 400) : $rounds];
        printf("%s %-4s n=%-5d wr=%5.1f%% liq=%-5d adds=%-6d pnl=%+12.2f\n", $sk, $tk, count($sigs), $res[$sk][$tk]['wr'], $liqN, $addN, $pnlTotal);
    }
}

// 保存信号样本(画图/核对用): 每策略前200个
$sigSave = [];
foreach (['A' => $sigA, 'B' => $sigB] as $sk => $sigs) {
    $arr = [];
    foreach (array_slice($sigs, 0, 200) as $i0) $arr[] = ['t' => $ts[$i0], 'px' => $c[$i0], 'macd' => round($macd[$i0], 3)];
    $sigSave[$sk] = $arr;
}
$out = ['generated' => date('Y-m-d H:i:s'),
    'params' => ['macd' => '12,26,60', 'lev' => $LEV, 'liq' => round($LIQ, 4), 'cap' => $CAP, 'fee' => $FEE, 'timeout_h' => 48],
    'span' => [bj($ts[0]), bj($ts[$n - 1])], 'bars' => $n,
    'sigCounts' => ['A' => count($sigA), 'B' => count($sigB)],
    'results' => $res, 'sigSamples' => $sigSave];
file_put_contents(__DIR__ . '/../web/macd1meth_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "saved size=" . round(filesize(__DIR__ . '/../web/macd1meth_data.json') / 1048576, 2) . "MB\n";
