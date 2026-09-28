<?php
/**
 * eth_sixflip.php — 六重共振+三均线 牛熊双向 · 50%保证金亏损反转型 回测(CLI, 2026-09-26)
 * 用户: "爆仓=保证金的50%亏 自己设置这个条件让他不爆仓 直接换成反向做多或者做空, 然后还是止盈±2% 还是加仓 止盈 试一下是否可以赚更多"
 *   入场: 牛市=六重共振 AND 15m三均线多头排列→做多; 熊市=六条件全部反向 AND 三均线空头排列→做空 (与 eth_sixshort 完全同参)
 *   加仓: 对应方向三均线排列+顺向, 不限轮数, 每轮+1U, 无仓位上限
 *   保证金: 链首笔=1U+3U×已过天数 封顶50U; 翻转腿继承当前总保证金(含加仓)
 *   风控: 不爆仓 → 浮亏达当前总保证金的50%(100x=价格逆向0.5%)立即市价平掉, 原地反向开仓(同保证金), 新腿继续止盈±2%+加仓, 可连续翻转
 *   止盈: 价格±2%(自当腿均价) | 超时: 链首笔起7天平仓 | 资金费: 多付/空收 0.01%/8h | 翻转开平均taker
 *   同根K线先判翻转(悲观序); 翻转后跳到下一根K线再判新腿(避免同根反复翻转死循环)
 * 输出: web/sixflip_data.json (对照基线 = eth_sixshort 爆仓版 +6,442.8U)
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $FLIP = 0.005; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
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

// ===== ETH K线 =====
[$tsF, $oF, $hF, $lF, $cF] = load_k('eth', '15m');
[$ts1, , , , $c1] = load_k('eth', '1h');
[$ts4, , , , $c4] = load_k('eth', '4h');
$ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5);
$ma7 = ma($cF, 7); $ma25 = ma($cF, 25); $ma99 = ma($cF, 99);
[$ts1b, $o1b, $h1b, $l1b, $c1b] = load_k('eth', '1h');
$br1h = buyratio($o1b, $h1b, $l1b, $c1b);

$nF = count($tsF);
echo "eth15m bars=$nF span=" . bj($tsF[0]) . " ~ " . bj($tsF[$nF - 1]) . "\n"; flush();

// ===== 六条件状态 + 三均线 =====
$p1 = 0; $p4 = 0; $n1 = count($ts1); $n4 = count($ts4);
$cur20 = null; $cur10 = null; $cur4 = null;
$sixL = array_fill(0, $nF, false); $sixS = array_fill(0, $nF, false);
for ($i = 30; $i < $nF; $i++) {
    $t = $tsF[$i];
    while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; }
    while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; }
    $hb = (int)floor($t / 3600000) * 3600000;
    if (isset($meltSet[$hb])) continue;
    $b = ffillBr($t);
    if ($b === null) continue;
    $lo = 0; $hi = $n1 - 1; $ib = -1;
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }
    if ($ib < 0) continue;
    $br = $br1h[$ib];
    if ($b > 0.5 && $cur20 === true && $cur10 === true && $cur4 === true && $br >= 0.5) $sixL[$i] = true;
    if ($b < 0.5 && $cur20 === false && $cur10 === false && $cur4 === false && $br < 0.5) $sixS[$i] = true;
}
$triL = array_fill(0, $nF, false); $triS = array_fill(0, $nF, false);
for ($i = 0; $i < $nF; $i++) {
    if ($ma7[$i] === null || $ma25[$i] === null || $ma99[$i] === null) continue;
    if ($ma7[$i] > $ma25[$i] && $ma25[$i] > $ma99[$i] && $cF[$i] > $ma7[$i]) $triL[$i] = true;
    if ($ma7[$i] < $ma25[$i] && $ma25[$i] < $ma99[$i] && $cF[$i] < $ma7[$i]) $triS[$i] = true;
}
$eL = array_fill(0, $nF, false); $eS = array_fill(0, $nF, false);
$cnt = ['eL' => 0, 'eS' => 0];
for ($i = 30; $i < $nF; $i++) {
    if ($sixL[$i] && $triL[$i]) { $eL[$i] = true; $cnt['eL']++; }
    if ($sixS[$i] && $triS[$i]) { $eS[$i] = true; $cnt['eS']++; }
}
echo "entryL={$cnt['eL']} entryS={$cnt['eS']}\n"; flush();

// ===== sim: 单账户顺序双向, 50%保证金亏损反转 =====
$eq = $EQ0; $startT = $tsF[30];
$trades = []; $i = 30; $dead = false;
while ($i < $nF - 1 && !$dead) {
    $isL = !empty($eL[$i]); $isS = !$isL && !empty($eS[$i]);
    if (!$isL && !$isS) { $i++; continue; }
    if ($i + 1 >= $nF || $oF[$i + 1] <= 0) { $i++; continue; }
    $days = (int)floor((($tsF[$i] - $startT) / 1000) / 86400);
    $M0 = round(min(1.0 + 3.0 * $days, $CAPM), 2);
    if ($M0 < 1) $M0 = 1.0;
    $legL = $isL;                       // 当腿方向
    $mg = $M0;                          // 链内继承总保证金(含加仓)
    $avg = $oF[$i + 1];
    $notional = $mg * $LEV;
    $legFee = $notional * $FEE_MAKER;   // 首笔开仓 maker
    $legFunding = 0.0;
    $chainStart = $i;
    $j = $i + 1;
    while ($j < $nF) {
        if ($hF[$j] <= 0 || $lF[$j] <= 0) { $j++; continue; }
        $legFunding += ($legL ? 1 : -1) * $notional * $FUND8H / 32;
        if ($legL) { $hitFlip = $lF[$j] <= $avg * (1 - $FLIP); $hitTp = $hF[$j] >= $avg * (1 + $TPR); }
        else       { $hitFlip = $hF[$j] >= $avg * (1 + $FLIP); $hitTp = $lF[$j] <= $avg * (1 - $TPR); }
        // 同根K线先判翻转(悲观序)
        if ($hitFlip) {
            $exitPx = $legL ? $avg * (1 - $FLIP) : $avg * (1 + $FLIP);
            $gross = $legL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);
            $legFee += $notional * $FEE_TAKER;   // 翻转平仓 taker
            $pnl = $gross - $legFee - $legFunding;
            if ($pnl < -$mg) $pnl = -$mg;
            $eq += $pnl;
            if ($eq <= 0) { $dead = true; }
            $trades[] = ['side' => $legL ? 'LONG' : 'SHORT', 'tin' => $tsF[$chainStart], 'tout' => $tsF[$j],
                         'out' => 'FLIP', 'adds' => 0, 'm0' => $M0, 'mg' => $mg,
                         'pnl' => round($pnl, 3), 'eq' => round($eq, 2),
                         'holdh' => round(($tsF[$j] - $tsF[max($chainStart, $j - 96 * 7)]) / 3600000.0, 1)];
            if ($dead) { $j++; break; }
            // 原地反向开仓(继承保证金)
            $legL = !$legL;
            $avg = $exitPx;
            $notional = $mg * $LEV;
            $legFee = $notional * $FEE_TAKER;    // 反向开仓 taker
            $legFunding = 0.0;
            $j++;                                 // 新腿从下一根K线再判
            continue;
        }
        if ($hitTp) {
            $exitPx = $legL ? $avg * (1 + $TPR) : $avg * (1 - $TPR);
            $gross = $legL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);
            $legFee += $notional * $FEE_TAKER;
            $pnl = $gross - $legFee - $legFunding;
            if ($pnl < -$mg) $pnl = -$mg;
            $eq += $pnl;
            $trades[] = ['side' => $legL ? 'LONG' : 'SHORT', 'tin' => $tsF[$chainStart], 'tout' => $tsF[$j],
                         'out' => 'WIN', 'adds' => 0, 'm0' => $M0, 'mg' => $mg,
                         'pnl' => round($pnl, 3), 'eq' => round($eq, 2),
                         'holdh' => round(($tsF[$j] - $tsF[$chainStart]) / 3600000.0, 1)];
            break;
        }
        if ($j - $chainStart >= 7 * 24 * 4) {
            $exitPx = $cF[$j];
            $gross = $legL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);
            $legFee += $notional * $FEE_TAKER;
            $pnl = $gross - $legFee - $legFunding;
            if ($pnl < -$mg) $pnl = -$mg;
            $eq += $pnl;
            $trades[] = ['side' => $legL ? 'LONG' : 'SHORT', 'tin' => $tsF[$chainStart], 'tout' => $tsF[$j],
                         'out' => 'TO', 'adds' => 0, 'm0' => $M0, 'mg' => $mg,
                         'pnl' => round($pnl, 3), 'eq' => round($eq, 2),
                         'holdh' => round(($tsF[$j] - $tsF[$chainStart]) / 3600000.0, 1)];
            break;
        }
        // 加仓: 当腿方向三均线排列 + 顺向, 不限轮数, 每轮+1U
        $triOk = $legL ? ($triL[$j] && $cF[$j] > $avg) : ($triS[$j] && $cF[$j] < $avg);
        if ($triOk && $j + 1 < $nF && $oF[$j + 1] > 0) {
            $ap = $oF[$j + 1];
            $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);
            $notional += $ADDU * $LEV; $mg += $ADDU;
            $legFee += $ADDU * $LEV * $FEE_TAKER;
        }
        $j++;
    }
    if ($j >= $nF && empty($trades) || (end($trades)['tin'] == $tsF[$chainStart] && end($trades)['tout'] < $tsF[$j])) {
        // 链未闭合(K线尽头)——按超时收尾
    }
    $i = max($j, $chainStart + 1);
}

// ===== 汇总 =====
function summarize($trades, $eqEnd) {
    $tot = ['n' => count($trades), 'win' => 0, 'flip' => 0, 'to' => 0, 'adds' => 0, 'pnl' => 0.0];
    foreach ($trades as $x) {
        if ($x['out'] == 'WIN') $tot['win']++;
        if ($x['out'] == 'FLIP') $tot['flip']++;
        if ($x['out'] == 'TO') $tot['to']++;
        $tot['pnl'] += $x['pnl'];
    }
    $months = [];
    foreach ($trades as $x) {
        $m = gmdate('Y-m', (int)($x['tout'] / 1000) + 8 * 3600);
        $mo = &$months[$m];
        $mo['n'] = ($mo['n'] ?? 0) + 1;
        $mo['win'] = ($mo['win'] ?? 0) + ($x['out'] == 'WIN' ? 1 : 0);
        $mo['flip'] = ($mo['flip'] ?? 0) + ($x['out'] == 'FLIP' ? 1 : 0);
        $mo['pnl'] = round(($mo['pnl'] ?? 0) + $x['pnl'], 2);
        $mo['eq_end'] = $x['eq'];
        unset($mo);
    }
    $peak = -INF; $maxdd = 0;
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }
    return ['summary' => $tot, 'eq_end' => round($eqEnd, 2), 'maxdd' => round($maxdd, 2), 'months' => $months, 'trades' => $trades];
}
$trL = array_values(array_filter($trades, fn($x) => $x['side'] == 'LONG'));
$trS = array_values(array_filter($trades, fn($x) => $x['side'] == 'SHORT'));
$all = summarize($trades, $eq);
$L = summarize($trL, 0); $S = summarize($trS, 0);
printf("ALL n=%d win=%d flip=%d to=%d pnl=%.1f eqEnd=%.1f maxdd=%.1f dead=%d\n",
    $all['summary']['n'], $all['summary']['win'], $all['summary']['flip'], $all['summary']['to'], $all['summary']['pnl'], $all['eq_end'], $all['maxdd'], $dead ? 1 : 0);
printf("LONG n=%d win=%d flip=%d pnl=%.1f | SHORT n=%d win=%d flip=%d pnl=%.1f\n",
    $L['summary']['n'], $L['summary']['win'], $L['summary']['flip'], $L['summary']['pnl'],
    $S['summary']['n'], $S['summary']['win'], $S['summary']['flip'], $S['summary']['pnl']);

$out = [
    'meta' => [
        'inst' => 'ETH-USDT-SWAP', 'lev' => $LEV, 'tp' => '±2%', 'eq0' => $EQ0, 'flip_at' => '保证金50%(价格0.5%)',
        'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF, 'cond' => $cnt,
        'params' => '入场: 牛市=六重共振 AND 15m三均线多头排列→做多; 熊市=六条件全部反向 AND 三均线空头排列→做空(与爆仓版基线同参) | 加仓=当腿方向三均线排列+顺向 不限轮数 每轮+1U | 保证金=链首笔1U+3U×已过天数封顶50U, 翻转腿继承当前总保证金(含加仓) | 风控=不爆仓: 浮亏达总保证金50%(价格逆向0.5%)立即平掉并原地反向开仓, 新腿继续止盈±2%+加仓, 可连续翻转 | 止盈=价格±2%(自当腿均价) | 超时=链首笔起7天 | 资金费=多付/空收0.01%/8h | 同根K线先判翻转(悲观序), 翻转后下一根K线起判新腿 | 净口径含手续费+资金费 | 起始500U 单账户顺序',
    ],
    'ALL' => $all, 'LONG' => $L, 'SHORT' => $S, 'dead' => $dead,
];
file_put_contents('E:/finally-main/web/sixflip_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "DATA OK\n";
