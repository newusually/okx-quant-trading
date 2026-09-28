<?php
/**
 * eth_addcmp.php — 加仓策略对比回测(CLI): 现行A方案框架下, 8个加仓信号逐个替换
 * 策略 = 现行实盘同参(2026-09-25 build 0925J口径):
 *   买入: 六重共振全命中 ①全市场1D MA20宽度>50% ②1h>MA20 ③1h>MA10 ④4h>MA5
 *         ⑤非熔断(BTC 1h最近4根累计≤-3%) ⑥taker买比≥50%(1h方向代理)
 *   加仓: 信号X(8候选: macd_hist_rise/fib_618/cci_oversold/macd_golden/triple_ma_bull/momentum_burst/td_nine/adx_trend)
 *         + 仅上行(现价>均价) ≤5轮 每轮=开仓保证金; 下跌不减仓 —— 公式与 strategy/signals.go 逐字同参
 *   止盈: 价格+2%(自均价) 市价全平; 不设止损·下跌不减仓
 *   兜底: 50%兜底强平已取消(2026-09-25用户指令) → 亏损由交易所爆仓线兜底(LIQ=均价×(1-1/LEV+MMR))
 *   保证金: 每笔=1U+3U×已过天数 封顶 500/6; 100x
 *   对照组: noAdd(完全不加仓) —— 各加仓策略与它对比得出「加仓贡献」
 * 输出: web/addcmp_data.json
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$BAL0 = 500.0; $CAP = $BAL0 / 6.0; $LBASE = 1.0;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }
function ema($a, $n) { $out = []; $k = 2.0 / ($n + 1); $e = null; for ($i = 0; $i < count($a); $i++) { $e = ($e === null) ? $a[$i] : ($a[$i] * $k + $e * (1 - $k)); $out[$i] = ($i >= $n - 1) ? $e : null; } return $out; }

// ================= 8个加仓信号(与 strategy/signals.go 逐字同参) =================
function sig_fib618($c) { // sigFibGolden: 近30根区间 0.618±3% 且收阳
    $n = count($c); $out = array_fill(0, $n, false);
    for ($i = 31; $i < $n; $i++) {
        $lo = INF; $hi = -INF;
        for ($j = $i - 29; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }
        if ($hi <= $lo) continue;
        $fib = $hi - ($hi - $lo) * 0.618;
        $out[$i] = ($c[$i] >= $fib * 0.97 && $c[$i] <= $fib * 1.03);
    }
    return $out;
}
function sig_cci($h, $l, $c) { // sigCCI: CCI(14) < -100
    $n = count($c); $out = array_fill(0, $n, false); $tp = []; $sma = []; $s = 0.0; $dev = [];
    for ($i = 0; $i < $n; $i++) { $tp[$i] = ($h[$i] + $l[$i] + $c[$i]) / 3; $s += $tp[$i]; if ($i >= 14) $s -= $tp[$i - 14]; $sma[$i] = ($i >= 13) ? $s / 14 : null; }
    for ($i = 13; $i < $n; $i++) {
        $md = 0.0;
        for ($j = $i - 13; $j <= $i; $j++) $md += abs($tp[$j] - $sma[$i]);
        $md /= 14;
        $out[$i] = ($md > 0) && (($tp[$i] - $sma[$i]) / (0.015 * $md) < -100);
    }
    return $out;
}
function macd_hist($c) { // MACD(12,26,9) 柱: EMA12-EMA26 再EMA9
    $n = count($c); $e12 = ema($c, 12); $e26 = ema($c, 26);
    $dif = []; for ($i = 0; $i < $n; $i++) $dif[$i] = ($e12[$i] !== null && $e26[$i] !== null) ? $e12[$i] - $e26[$i] : 0;
    // dea: 对 dif 从第一个有效点起算 EMA9
    $dea = []; $e = null; $k = 2.0 / 10;
    for ($i = 0; $i < $n; $i++) {
        if ($e12[$i] === null || $e26[$i] === null) { $dea[$i] = null; continue; }
        $e = ($e === null) ? $dif[$i] : ($dif[$i] * $k + $e * (1 - $k));
        $dea[$i] = $e;
    }
    $h = []; for ($i = 0; $i < $n; $i++) $h[$i] = ($dea[$i] !== null) ? 2 * ($dif[$i] - $dea[$i]) : null; // 中国习惯MACD柱=2*(DIF-DEA); talib.Macd的hist=DIF-DEA
    // 注意: talib.Macd histogram = dif - dea(不带×2)。引擎用 talib → 保持不带×2
    for ($i = 0; $i < $n; $i++) if ($dea[$i] !== null) $h[$i] = $dif[$i] - $dea[$i];
    return [$dif, $dea, $h];
}
function sig_macd_golden($c) { // sigMACDCross: hist 上穿0 且最新hist>0
    [, , $h] = macd_hist($c); $n = count($c); $out = array_fill(0, $n, false);
    for ($i = 1; $i < $n; $i++) { if ($h[$i] === null || $h[$i - 1] === null) continue; $out[$i] = ($h[$i - 1] <= 0 && $h[$i] > 0); }
    return $out;
}
function sig_macd_hist_rise($c) { // sigMACDHistRise: hist>0 且连续3根放大
    [, , $h] = macd_hist($c); $n = count($c); $out = array_fill(0, $n, false);
    for ($i = 3; $i < $n; $i++) { if ($h[$i] === null || $h[$i - 1] === null || $h[$i - 2] === null || $h[$i - 3] === null) continue; $out[$i] = ($h[$i] > 0 && $h[$i] > $h[$i - 1] && $h[$i - 1] > $h[$i - 2]); }
    return $out;
}
function sig_triple_ma($c) { // sigTripleMABull: MA7>MA25>MA99 且 收>MA7
    $n = count($c); $m7 = ma($c, 7); $m25 = ma($c, 25); $m99 = ma($c, 99); $out = array_fill(0, $n, false);
    for ($i = 98; $i < $n; $i++) $out[$i] = ($m7[$i] > $m25[$i] && $m25[$i] > $m99[$i] && $c[$i] > $m7[$i]);
    return $out;
}
function sig_momentum($c) { // sigMomentumBurst: ROC(12)>2 且 收>EMA30
    $n = count($c); $e = ema($c, 30); $out = array_fill(0, $n, false);
    for ($i = 30; $i < $n; $i++) { if ($c[$i - 12] <= 0) continue; $roc = ($c[$i] / $c[$i - 12] - 1) * 100; $out[$i] = ($roc > 2 && $c[$i] > $e[$i]); }
    return $out;
}
function sig_adx($h, $l, $c) { // sigADXTrendUp: ADX(14)>25 且 +DI>-DI
    $n = count($c); $out = array_fill(0, $n, false);
    if ($n < 30) return $out;
    $trS = 0.0; $pS = 0.0; $mS = 0.0; $adxPrev = null; $trE = null; $pE = null; $mE = null;
    $pdm = []; $mdm = []; $tr = [];
    for ($i = 1; $i < $n; $i++) {
        $up = $h[$i] - $h[$i - 1]; $dn = $l[$i - 1] - $l[$i];
        $pdm[$i] = ($up > $dn && $up > 0) ? $up : 0;
        $mdm[$i] = ($dn > $up && $dn > 0) ? $dn : 0;
        $a = abs($h[$i] - $l[$i]); $b = abs($h[$i] - $c[$i - 1]); $cc = abs($l[$i] - $c[$i - 1]);
        $tr[$i] = max($a, max($b, $cc));
    }
    $dxs = [];
    for ($i = 14; $i < $n; $i++) {
        if ($i == 14) { $trS = 0; $pS = 0; $mS = 0; for ($j = 1; $j <= 14; $j++) { $trS += $tr[$j]; $pS += $pdm[$j]; $mS += $mdm[$j]; } }
        else { $trS = $trS - $trS / 14 + $tr[$i]; $pS = $pS - $pS / 14 + $pdm[$i]; $mS = $mS - $mS / 14 + $mdm[$i]; }
        if ($trS <= 0) { $dxs[$i] = 0; continue; }
        $pdi = 100 * $pS / $trS; $mdi = 100 * $mS / $trS;
        $dx = ($pdi + $mdi > 0) ? 100 * abs($pdi - $mdi) / ($pdi + $mdi) : 0;
        $dxs[$i] = $dx;
        // ADX = DX 的14期均值(Wilder平滑)
        if ($i >= 27) {
            $adxCnt = 0; $adxSum = 0;
            // 简化: 用最近14个DX均值
            for ($j = $i - 13; $j <= $i; $j++) $adxSum += $dxs[$j];
            $adx = $adxSum / 14;
            $out[$i] = ($adx > 25 && $pdi > $mdi);
        }
    }
    return $out;
}
function tdDownCnt($c, $i) { $n = 0; for ($j = $i; $j >= 4; $j--) { if ($c[$j] < $c[$j - 4]) $n++; else break; } return $n; }
function sig_tdnine($c, $h) { // sigTDNine: 前一根完成下跌9结构 且 当前根收盘>前一根最高
    $n = count($c); $out = array_fill(0, $n, false);
    for ($i = 1; $i < $n; $i++) { if (tdDownCnt($c, $i - 1) >= 9 && $c[$i] > $h[$i - 1]) $out[$i] = true; }
    return $out;
}

// ================= 数据准备 =================
$up = []; $tot = [];
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");
$cur = null; $win = []; $s = 0.0;
foreach ($rr as $x) {
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; }
    $win[] = (float)$x[2]; $s += (float)$x[2];
    if (count($win) > 20) $s -= array_shift($win);
    if (count($win) == 20) { $t = (int)$x[1]; $up[$t] = ($up[$t] ?? 0) + ((float)$x[2] >= $s / 20 ? 1 : 0); $tot[$t] = ($tot[$t] ?? 0) + 1; }
}
$brSrc = [];
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k;
$sorted = array_keys($brSrc); sort($sorted);
function ffillBr($t) { global $brSrc, $sorted; $lo = 0; $hi = count($sorted) - 1; $res = null;
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }
    return $res !== null ? $brSrc[$res] : null; }

$rb = rows("SELECT candle_time,`c` FROM kline_btc_usdt_swap_1h ORDER BY candle_time");
$tsB = []; $cB = []; foreach ($rb as $x) { $tsB[] = (int)$x[0]; $cB[] = (float)$x[1]; }
$meltSet = [];
for ($i = 4; $i < count($cB); $i++) if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) { for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true; }

$tbls = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline_%_usdt_swap_1h' ORDER BY table_name");
$insts = [];
foreach ($tbls as $x) { if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $x[0], $m)) $insts[] = $m[1]; }
echo "insts=" . count($insts) . " breadth_days=" . count($brSrc) . "\n"; flush();

$VARIANTS = ['noAdd', 'macd_hist_rise', 'fib_618', 'cci_oversold', 'macd_golden', 'triple_ma_bull', 'momentum_burst', 'td_nine', 'adx_trend'];

// ================= sim(现行口径: 无50%兜底, LIQ=交易所爆仓线) =================
function sim($o, $h, $l, $c, $ts, $addSig, $entry) {
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP, $LBASE;
    $liqDrop = 1.0 / $LEV - $MMR;
    $startT = $ts[30];
    $trades = []; $n = count($ts); $i = 30;
    while ($i < $n - 1) {
        if (empty($entry[$ts[$i]])) { $i++; continue; }
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400);
        $M = round(min($LBASE + 3.0 * $days, $CAP), 2);
        if ($M < 1) $M = 1.0;
        $M0 = $M;
        $e = $o[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0; $addEv = 0; $addWinEv = 0;
        $fee = $notional * $FEE_MAKER; $funding = 0.0;
        $liqPx = $avg * (1 - $liqDrop);
        $tgtPx = $avg * (1 + $TPR);
        $outcome = null; $exitPx = null; $j = $i + 1;
        while ($j < $n) {
            $barsHeld = $j - $i;
            $funding += $notional * $FUND8H / 8;
            $hitLiq = $l[$j] <= $liqPx;
            $hitTp  = $h[$j] >= $tgtPx;
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            if ($adds < 5 && $j + 1 < $n && !empty($addSig[$j]) && $c[$j] > $avg) { // 信号X 仅上行 ≤5轮
                $ap = $o[$j + 1];
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);
                $notional += $M0 * $LEV; $adds++; $addEv++;
                $fee += $M0 * $LEV * $FEE_TAKER;
                $liqPx = $avg * (1 - $liqDrop);
                $tgtPx = $avg * (1 + $TPR);
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }
        if ($addEv > 0 && $outcome === 'WIN') $addWinEv++;
        $gross = ($exitPx - $avg) * $notional / $avg;
        $pnl = $gross - $fee - $funding;
        $mg = $M0 * (1 + $adds);
        if ($pnl < -$mg) $pnl = -$mg;
        $trades[] = ['out' => $outcome, 'adds' => $adds, 'pnl' => round($pnl, 3)];
        $i = $j + 1;
    }
    return [$trades, $addEv, $addWinEv];
}

$summary = []; $contractStats = []; $dailyPnl = [];
$done = 0;
foreach ($insts as $inst) {
    $t1 = "kline_{$inst}_usdt_swap_1h"; $t4 = "kline_{$inst}_usdt_swap_4h";
    $chk = rows("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='trading' AND table_name IN ('$t1','$t4')");
    if ((int)$chk[0][0] < 2) continue;
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM $t1 ORDER BY candle_time ASC");
    $ts = []; $o = []; $h = []; $l = []; $c = [];
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }
    $n = count($ts);
    if ($n < 200) { $done++; continue; }
    [$ts4h, $c4h] = [[], []];
    $r4 = rows("SELECT candle_time,`c` FROM $t4 ORDER BY candle_time ASC");
    foreach ($r4 as $x) { $ts4h[] = (int)$x[0]; $c4h[] = (float)$x[1]; }
    $has4 = count($ts4h) >= 10;
    $ma20 = ma($c, 20); $ma10 = ma($c, 10);
    $ma5_4h = $has4 ? ma($c4h, 5) : [];
    $tr4hUpF = []; $p4 = 0; $cur4 = null; $n4 = count($ts4h);
    foreach ($ts as $tt) { while ($p4 < $n4 && $ts4h[$p4] <= $tt) { if ($ma5_4h[$p4] !== null) $cur4 = $c4h[$p4] > $ma5_4h[$p4]; $p4++; } $tr4hUpF[$tt] = $cur4; }
    // ⑥ taker买比(1h方向代理)
    $br1h = [];
    for ($i = 0; $i < $n; $i++) { $rng = $h[$i] - $l[$i]; $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0; if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1; $br1h[$i] = 0.5 + $dir / 2; }
    // 8个信号序列(1h, 与全市场回测同参口径)
    $sigO = [];
    $sigO['fib_618'] = sig_fib618($c);
    $sigO['cci_oversold'] = sig_cci($h, $l, $c);
    $sigO['macd_golden'] = sig_macd_golden($c);
    $sigO['macd_hist_rise'] = sig_macd_hist_rise($c);
    $sigO['triple_ma_bull'] = sig_triple_ma($c);
    $sigO['momentum_burst'] = sig_momentum($c);
    $sigO['td_nine'] = sig_tdnine($c, $h);
    $sigO['adx_trend'] = sig_adx($h, $l, $c);
    // 入场集
    $entryA = [];
    for ($i = 30; $i < $n; $i++) {
        $t = $ts[$i];
        if (!empty($meltSet[$t])) continue;
        $b = ffillBr($t);
        if ($b === null || $b <= 0.5) continue;
        if ($ma20[$i] === null || $c[$i] <= $ma20[$i]) continue;
        if ($ma10[$i] === null || $c[$i] <= $ma10[$i]) continue;
        if (empty($tr4hUpF[$t])) continue;
        if ($br1h[$i] < 0.5) continue;
        $entryA[$t] = true;
    }
    if (!count($entryA)) { $done++; continue; }
    foreach ($VARIANTS as $vk) {
        $addSig = ($vk === 'noAdd') ? [] : $sigO[$vk];
        [$tr, $aev, $awev] = sim($o, $h, $l, $c, $ts, $addSig, $entryA);
        $pl = 0.0; $w = 0; $liq = 0; $adds = 0;
        foreach ($tr as $x) { $pl += $x['pnl']; if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'LIQ') $liq++; $adds += $x['adds']; }
        $cnt = count($tr);
        $summary[$vk]['n'] = ($summary[$vk]['n'] ?? 0) + $cnt;
        $summary[$vk]['win'] = ($summary[$vk]['win'] ?? 0) + $w;
        $summary[$vk]['liq'] = ($summary[$vk]['liq'] ?? 0) + $liq;
        $summary[$vk]['adds'] = ($summary[$vk]['adds'] ?? 0) + $adds;
        $summary[$vk]['total'] = ($summary[$vk]['total'] ?? 0) + $pl;
        $summary[$vk]['add_evs'] = ($summary[$vk]['add_evs'] ?? 0) + $aev;
        $summary[$vk]['add_win_evs'] = ($summary[$vk]['add_win_evs'] ?? 0) + $awev;
        if ($cnt > 0) $contractStats[$vk][] = ['inst' => strtoupper($inst), 'n' => $cnt, 'pnl' => round($pl, 2), 'wr' => round(100 * $w / $cnt, 1)];
    }
    $done++;
    if ($done % 60 == 0) { echo "progress $done/" . count($insts) . "\n"; flush(); }
    unset($ts, $o, $h, $l, $c, $ts4h, $c4h, $ma20, $ma10, $ma5_4h, $tr4hUpF, $br1h, $sigO, $entryA);
}

// 日盈亏曲线: 从 contractStats 无法还原, 改用逐日重算太贵 —— 用每变体盈亏排序即可; 曲线省略, 用 top/bottom 合约
$rank = [];
foreach ($VARIANTS as $vk) {
    $arr = $contractStats[$vk] ?? [];
    usort($arr, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; });
    $rank[$vk] = ['top' => array_slice($arr, 0, 10), 'bottom' => array_slice(array_reverse($arr), 0, 10)];
}
$out = [
    'params' => ['lev' => 100, 'bal0' => $BAL0, 'cap_per_trade' => round($CAP, 2), 'tp' => '价格+2%(自均价)',
        'backstop' => '50%兜底强平已取消(2026-09-25用户指令) → 亏损由交易所爆仓线兜底(LIQ≈均价×-0.6%)',
        'entry' => '六重共振全命中(1h口径): ①全市场1D MA20宽度>50% ②1h>MA20 ③1h>MA10 ④4h>MA5 ⑤非熔断 ⑥taker买比≥50%',
        'add' => '信号X + 仅上行(现价>均价) ≤5轮 每轮=开仓保证金; 下跌不减仓; 信号公式与 strategy/signals.go 逐字同参(1h口径)',
        'note' => '每合约同时只1仓; 保证金=1U+3U×已过天数 封顶500/6; noAdd=对照组(完全不加仓)'],
    'variants' => $VARIANTS,
    'summary' => $summary, 'rank' => $rank,
];
file_put_contents('E:/finally-main/web/addcmp_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK\n";
foreach ($VARIANTS as $vk) {
    $s = $summary[$vk];
    $n = max(1, $s['n']);
    printf("%-16s n=%-6d win%%=%5.1f liq=%-5d adds=%-6d addEvs=%-6d addWin%%=%5.1f total=%10.1f ev=%6.2f\n",
        $vk, $s['n'], 100 * $s['win'] / $n, $s['liq'], $s['adds'], $s['add_evs'],
        $s['add_evs'] ? 100 * $s['add_win_evs'] / $s['add_evs'] : 0, $s['total'], $s['total'] / $n);
}
