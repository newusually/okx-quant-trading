<?php
/**
 * eth_final1y.php — ETH 终版回测(CLI, 2026-09-25 用户指令)
 * 策略 = 与实盘 build 0925N 完全同参:
 *   买入: 六重共振全命中(逐15m K线判定, 1h/4h/d 条件向前填充)
 *     ① 全市场1D MA20宽度>50%(kline1d 全部合约) ②ETH 1h>MA20 ③1h>MA10
 *     ④4h>MA5 ⑤非熔断(BTC 1h最近4根累计跌幅≤-3%禁买) ⑥taker买比≥50%(1h K线方向代理)
 *   保证金: 每笔 = 1U + 3U×已过天数, 封顶【当前总余额/10】(动态权益, 用户硬锁)
 *   加仓: 15m K线 triple_ma_bull 三均线多头排列(MA7>MA25>MA99且价>MA7) + 仅上行(现价>均价)
 *         ≤5轮, 每轮固定+1U, 且 加仓后总保证金(含本轮) ≤ 总余额/10
 *   止盈: 价格+2%(自均价) 市价全平; 不设止损; 无兜底强平(交易所爆仓线=价格-0.6%@100x)
 *   净口径: 含开仓maker费/加仓taker费/平仓taker费 + 资金费率0.01%/8h
 *   起始权益 500U, 逐笔结转(动态复利)
 * 输出: web/final1y_data.json
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$EQ0 = 500.0; $MAXADDS = 5; $ADDU = 1.0;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 北京时间

function load_k($inst, $bar) {
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");
    $ts = []; $o = []; $h = []; $l = []; $c = [];
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }
    return [$ts, $o, $h, $l, $c];
}

// ===== ① 全市场 1D MA20 宽度 =====
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

// ===== ⑤ BTC 熔断时刻集合 =====
[$tsB, , , , $cB] = load_k('btc', '1h');
$meltSet = [];
for ($i = 4; $i < count($cB); $i++) {
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;
    }
}
echo "melt hours=" . count($meltSet) . "\n"; flush();

// ===== ETH K线: 15m 主时间轴 + 1h/4h 条件向前填充 =====
[$tsF, $oF, $hF, $lF, $cF] = load_k('eth', '15m');
[$ts1, , , , $c1] = load_k('eth', '1h');
[$ts4, , , , $c4] = load_k('eth', '4h');
$ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5);
$ma7 = ma($cF, 7); $ma25 = ma($cF, 25); $ma99 = ma($cF, 99);

// taker买比代理(1h K线方向)
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
[$ts1b, $o1b, $h1b, $l1b, $c1b] = load_k('eth', '1h');
$br1h = buyratio($o1b, $h1b, $l1b, $c1b);

$nF = count($tsF);
echo "eth15m bars=$nF span=" . bj($tsF[0]) . " ~ " . bj($tsF[$nF - 1]) . "\n"; flush();

// ===== 逐15m判定六重共振 + 统计各条件命中 =====
$cnt = ['c1' => 0, 'c2' => 0, 'c3' => 0, 'c4' => 0, 'c5' => 0, 'c6' => 0, 'entry' => 0, 'melt_block' => 0];
$p1 = 0; $p4 = 0; $n1 = count($ts1); $n4 = count($ts4);
$cur20 = null; $cur10 = null; $cur4 = null; $curBr = null;
$entry = [];
$startT = $tsF[30];
for ($i = 30; $i < $nF; $i++) {
    $t = $tsF[$i];
    while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; }
    while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; }
    $hb = (int)floor($t / 3600000) * 3600000;
    // ⑤ 熔断(该15m所在小时被熔断标记 → 禁买)
    if (isset($meltSet[$hb])) { $cnt['melt_block']++; continue; }
    $b = ffillBr($t);
    if ($b === null || $b <= 0.5) continue; $cnt['c1']++;
    if (!$cur20) continue; $cnt['c2']++;
    if (!$cur10) continue; $cnt['c3']++;
    if (!$cur4) continue; $cnt['c4']++;
    // ⑥ taker买比: 当前1h K线方向代理
    $ib = -1; // 用二分找 1h index
    $lo = 0; $hi = $n1 - 1;
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }
    if ($ib < 0 || $br1h[$ib] < 0.5) continue; $cnt['c6']++;
    $entry[$i] = true; $cnt['entry']++;
}
echo "entry15m=" . count($entry) . "\n"; flush();

// ===== 三均线多头排列信号序列(15m) =====
$tri = [];
for ($i = 0; $i < $nF; $i++) {
    $tri[$i] = ($ma7[$i] !== null && $ma25[$i] !== null && $ma99[$i] !== null)
        && ($ma7[$i] > $ma25[$i] && $ma25[$i] > $ma99[$i] && $cF[$i] > $ma7[$i]);
}

// ===== sim: 单仓位顺序回测, 动态权益 =====
$liqDrop = 1.0 / $LEV - $MMR;   // -0.6%
$eq = $EQ0;
$trades = [];
$i = 30;
while ($i < $nF - 1) {
    if (empty($entry[$i])) { $i++; continue; }
    $days = (int)floor((($tsF[$i] - $startT) / 1000) / 86400);
    $cap = $eq / 10.0;
    $M0 = round(min(1.0 + 3.0 * $days, $cap), 2);
    if ($M0 < 1) $M0 = 1.0;
    $e = $oF[$i + 1];
    $notional = $M0 * $LEV; $avg = $e; $adds = 0;
    $fee = $notional * $FEE_MAKER; $funding = 0.0;
    $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR);
    $outcome = null; $exitPx = null; $j = $i + 1;
    $skipAddBlocks = 0;
    while ($j < $nF) {
        $funding += $notional * $FUND8H / 32;   // 每15m 1/32个8h资金费
        $hitLiq = $lF[$j] <= $liqPx;
        $hitTp  = $hF[$j] >= $tgtPx;
        if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
        if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
        if ($j - $i >= 7 * 24 * 4) { $outcome = 'TO'; $exitPx = $cF[$j]; break; }
        // 加仓: 15m三均线多头排列 + 仅上行 + ≤5轮 + 仓位≤余额/10
        if ($adds < $MAXADDS && $tri[$j] && $cF[$j] > $avg) {
            $cap2 = $eq / 10.0;
            if ($M0 + $adds * $ADDU + $ADDU <= $cap2 && $j + 1 < $nF) {
                $ap = $oF[$j + 1];
                if ($ap > 0) {
                    $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);
                    $notional += $ADDU * $LEV; $adds++;
                    $fee += $ADDU * $LEV * $FEE_TAKER;
                    $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR);
                }
            } else $skipAddBlocks++;
        }
        $j++;
    }
    if ($outcome === null) { $outcome = 'TO'; $j = $nF - 1; $exitPx = $cF[$j]; }
    $gross = ($exitPx - $avg) * $notional / $avg;
    $fee += $notional * $FEE_TAKER;   // 平仓 taker
    $pnl = $gross - $fee - $funding;
    $mg = $M0 + $adds * $ADDU;
    if ($pnl < -$mg) $pnl = -$mg;
    $eq += $pnl;
    $trades[] = ['tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds,
                 'm0' => $M0, 'mg' => $mg, 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),
                 'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];
    $i = $j + 1;
}

// ===== 汇总 =====
$tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'adds' => 0, 'pnl' => 0.0, 'fee' => 0.0];
foreach ($trades as $x) {
    if ($x['out'] == 'WIN') $tot['win']++;
    if ($x['out'] == 'LIQ') $tot['liq']++;
    if ($x['out'] == 'TO') $tot['to']++;
    $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl'];
}
$months = [];
foreach ($trades as $x) {
    $m = gmdate('Y-m', (int)($x['tout'] / 1000) + 8 * 3600);
    $mo = &$months[$m];
    $mo['n'] = ($mo['n'] ?? 0) + 1;
    $mo['win'] = ($mo['win'] ?? 0) + ($x['out'] == 'WIN' ? 1 : 0);
    $mo['liq'] = ($mo['liq'] ?? 0) + ($x['out'] == 'LIQ' ? 1 : 0);
    $mo['to'] = ($mo['to'] ?? 0) + ($x['out'] == 'TO' ? 1 : 0);
    $mo['adds'] = ($mo['adds'] ?? 0) + $x['adds'];
    $mo['pnl'] = round(($mo['pnl'] ?? 0) + $x['pnl'], 2);
    $mo['eq_end'] = $x['eq'];
    unset($mo);
}
// 最大回撤(按逐笔权益)
$peak = -INF; $maxdd = 0;
foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }

echo sprintf("TOTAL n=%d win=%d liq=%d to=%d adds=%d pnl=%.1f eqEnd=%.1f maxdd=%.1f\n",
    $tot['n'], $tot['win'], $tot['liq'], $tot['to'], $tot['adds'], $tot['pnl'], $eq, $maxdd);

$out = [
    'meta' => [
        'inst' => 'ETH-USDT-SWAP', 'lev' => $LEV, 'tp' => '+2%', 'eq0' => $EQ0,
        'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF,
        'params' => '买入=六重共振全命中 | 保证金=1U+3U/天 封顶总余额/10 | 加仓=15m三均线多头排列(MA7>MA25>MA99)+仅上行 ≤5轮 每轮+1U 仓位≤总余额/10 | 止盈=价格+2% | 不设止损·无兜底(交易所爆仓线-0.6%) | 净口径含手续费+资金费',
    ],
    'summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),
    'cond' => $cnt, 'months' => $months, 'trades' => $trades,
];
file_put_contents('E:/finally-main/web/final1y_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "DATA OK\n";
