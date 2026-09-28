<?php
/**
 * eth_a2fib.php — ETH 单合约 A2(轨道共振24根窗+合一24根窗+均值回归中轨平仓) × 斐波那契加仓 一年回测(2026-09-26 用户指令)
 * 用户口径:
 *   入场 = 六重共振新鲜触发 AND 双线合一(≤24根窗) AND 布林触轨(≤24根窗, 下轨→做多/上轨→做空) [A2原口径]
 *   平仓 = 回归中轨(均值回归) | 100x爆仓线±0.6%照模拟 | 不设止损 | 超时7天兜底
 *   资金 = 每天1U新仓(每日最多开1笔1U) + 每天额外3U加仓额度(每天最多加3轮) 叠加每次1U加仓
 *   加仓 = 斐波那契0.618黄金回调 15m线(与实盘引擎 sigFibGolden 同口径: 近30根高低点, fib=hi-(hi-lo)*0.618,
 *          现价在fib±3%内; 多头=阳线确认, 空头=镜像阴线) + 顺向(多:现价>均价/空:现价<均价)
 *   仓位 = 总保证金封顶50U, 加仓不限轮数但每次仅1U
 * 输出: web/a2fib_data.json
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$EQ0 = 500.0; $ADDU = 1.0; $CAPM = 50.0; $DAILY_ADD = 3; $TO_DAYS = 7;
$W = 150; $NB = 60; $SEP_THR = 0.004; $SEP_HIST = 0.01; $SEP_LOOK = 24;
$P = 20; $K = 2.0; $W24 = 24; $FIB_N = 30; $FIB_TOL = 0.03;
$INST = 'eth';

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }
function bjday($ms) { return gmdate('Y-m-d', (int)($ms / 1000) + 8 * 3600); }
function winState($events, $n, $look) {
    $out = array_fill(0, $n, false); $cnt = 0;
    for ($i = 0; $i < $n; $i++) {
        if (isset($events[$i])) $cnt++;
        if ($i - $look >= 0 && isset($events[$i - $look])) $cnt--;
        $out[$i] = $cnt > 0;
    }
    return $out;
}
function load_k($inst, $bar, $withVol = false) {
    $v = $withVol ? ',vol_quote' : '';
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`{$v} FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");
    $out = ['ts' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
    foreach ($r as $x) { $out['ts'][] = (int)$x[0]; $out['o'][] = (float)$x[1]; $out['h'][] = (float)$x[2];
        $out['l'][] = (float)$x[3]; $out['c'][] = (float)$x[4]; if ($withVol) $out['v'][] = (float)$x[5]; }
    return $out;
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

class Chip {
    public $hist = []; public $lo = null; public $hi = null; public $bw = 0.0;
    private $nb; private $ring = []; private $ri = 0; private $cnt = 0; private $cap;
    function __construct($nb, $cap) { $this->nb = $nb; $this->cap = $cap; $this->hist = array_fill(0, $nb, 0.0); }
    private function adds($l, $h, $v) {
        $nb = $this->nb; $a = array_fill(0, $nb, 0.0);
        if ($h <= $l || $v <= 0 || $this->bw <= 0) return $a;
        $bw = $this->bw;
        $i0 = (int)floor(($l - $this->lo) / $bw); $i1 = (int)floor(($h - $this->lo) / $bw);
        $i0 = max(0, min($nb - 1, $i0)); $i1 = max(0, min($nb - 1, $i1));
        if ($i0 == $i1) { $a[$i0] = $v; return $a; }
        $per = $v / ($i1 - $i0 + 1);
        for ($i = $i0; $i <= $i1; $i++) $a[$i] = $per;
        return $a;
    }
    private function rebuild() {
        $lo = INF; $hi = -INF;
        foreach ($this->ring as $b) { if ($b === null) continue; if ($b[0] < $lo) $lo = $b[0]; if ($b[1] > $hi) $hi = $b[1]; }
        if ($hi <= $lo) $hi = $lo * 1.001 + 1e-9;
        $this->lo = $lo; $this->hi = $hi; $this->bw = ($hi - $lo) / $this->nb;
        $this->hist = array_fill(0, $this->nb, 0.0);
        foreach ($this->ring as $b) {
            if ($b === null) continue;
            $a = $this->adds($b[0], $b[1], $b[2]);
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i];
        }
    }
    function push($l, $h, $v) {
        if (!isset($this->ring[$this->ri])) $this->ring[$this->ri] = null;
        $this->ring[$this->ri] = [$l, $h, $v];
        $this->ri = ($this->ri + 1) % $this->cap;
        if ($this->cnt < $this->cap) { $this->cnt++; $this->rebuild(); return; }
        $lo = INF; $hi = -INF;
        foreach ($this->ring as $b) { if ($b === null) continue; if ($b[0] < $lo) $lo = $b[0]; if ($b[1] > $hi) $hi = $b[1]; }
        $rng = $hi - $lo; if ($rng <= 0) $rng = $hi * 0.001 + 1e-9;
        $need = ($this->bw <= 0) || ($l < $this->lo) || ($h > $this->hi)
             || (abs($lo - $this->lo) > 0.005 * $rng) || (abs($hi - $this->hi) > 0.005 * $rng);
        if ($need) { $this->lo = $lo; $this->hi = $hi; $this->bw = $rng / $this->nb;
            $this->hist = array_fill(0, $this->nb, 0.0);
            foreach ($this->ring as $b) { if ($b === null) continue;
                $a = $this->adds($b[0], $b[1], $b[2]);
                for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i]; }
        } else {
            $a = $this->adds($l, $h, $v);
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i];
        }
    }
    function evictOldest() {
        $old = $this->ring[$this->ri] ?? null;
        if ($old === null) { $this->ring[$this->ri] = null; $this->ri = ($this->ri + 1) % $this->cap; return; }
        if ($this->bw > 0) {
            $a = $this->adds($old[0], $old[1], $old[2]);
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0 && $this->hist[$i] >= $a[$i]) $this->hist[$i] -= $a[$i];
        }
        $this->ring[$this->ri] = null;
        $this->ri = ($this->ri + 1) % $this->cap;
    }
    function peaks() {
        $nb = $this->nb; $h = $this->hist;
        $tot = 0.0; for ($i = 0; $i < $nb; $i++) $tot += $h[$i];
        if ($tot <= 0) return null;
        $avg = $tot / $nb;
        $pk = [];
        for ($i = 1; $i < $nb - 1; $i++) {
            if ($h[$i] > $h[$i - 1] && $h[$i] >= $h[$i + 1] && $h[$i] > $avg * 1.3)
                $pk[] = [$this->lo + ($i + 0.5) * $this->bw, $h[$i], $i];
        }
        if (count($pk) == 0) return null;
        $mg = []; $cur = $pk[0];
        for ($k = 1; $k < count($pk); $k++) {
            if ($pk[$k][2] - $cur[2] <= 3) {
                $tv = $cur[1] + $pk[$k][1];
                $cur = [($cur[0] * $cur[1] + $pk[$k][0] * $pk[$k][1]) / $tv, $tv, $cur[2]];
            } else { $mg[] = $cur; $cur = $pk[$k]; }
        }
        $mg[] = $cur;
        if (count($mg) < 2) return null;
        usort($mg, function ($a, $b) { return $b[1] <=> $a[1]; });
        $two = [$mg[0], $mg[1]];
        usort($two, function ($a, $b) { return $a[0] <=> $b[0]; });
        return [[$two[0][0], $two[0][1]], [$two[1][0], $two[1][1]]];
    }
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
$gB = load_k('btc', '1h');
$tsB = $gB['ts']; $cB = $gB['c'];
$meltSet = [];
for ($i = 4; $i < count($cB); $i++) {
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;
    }
}
unset($rr, $gB, $tsB, $cB);

// ===== ETH 数据 =====
$gF = load_k($INST, '15m', true);
$tsF = $gF['ts']; $oF = $gF['o']; $hF = $gF['h']; $lF = $gF['l']; $cF = $gF['c']; $vF = $gF['v'];
$nF = count($tsF);
echo "ETH 15m bars=$nF span=" . bj($tsF[0]) . " ~ " . bj($tsF[$nF - 1]) . "\n"; flush();
$g1 = load_k($INST, '1h'); $g4 = load_k($INST, '4h');
$ts1 = $g1['ts']; $c1 = $g1['c']; $ts4 = $g4['ts']; $c4 = $g4['c'];
$n1 = count($ts1); $n4 = count($ts4);
$ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5);
$br1h = buyratio($g1['o'], $g1['h'], $g1['l'], $g1['c']);

// 六条件状态 + 新鲜信号
$sixL = false; $sixS = false; $sigL = []; $sigS = [];
$p1 = 0; $p4 = 0; $cur20 = null; $cur10 = null; $cur4 = null;
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
unset($g1, $g4, $ts1, $ts4, $c1, $c4, $ma20, $ma10, $ma5_4, $br1h);
echo "six fresh: L=$cntL S=$cntS\n"; flush();

// ===== 筹码峰 双线合一 =====
$chip = new Chip($NB, $W);
for ($i = 0; $i < $W; $i++) if ($hF[$i] > 0 && $lF[$i] > 0) $chip->push($lF[$i], $hF[$i], $vF[$i]);
$sepPrev = array_fill(0, $SEP_LOOK, 0.0); $sepIdx = 0;
$mergedSig = []; $mergeCnt = 0;
for ($i = $W; $i < $nF - 1; $i++) {
    if ($hF[$i] > 0 && $lF[$i] > 0 && $vF[$i] > 0) {
        $chip->evictOldest();
        $chip->push($lF[$i], $hF[$i], $vF[$i]);
        $pk = $chip->peaks();
        $sep = 0.0; $mg = false;
        if ($pk !== null) {
            $pl = $pk[0][0]; $ph = $pk[1][0];
            if ($pl > 0) {
                $sep = ($ph - $pl) / $pl;
                $midP = ($pl + $ph) / 2;
                $mg = ($sep <= $SEP_THR) && ($cF[$i] >= $midP * 0.99) && ($cF[$i] <= $midP * 1.01);
                if ($mg) {
                    $everSep = false;
                    for ($k = 1; $k <= $SEP_LOOK; $k++) {
                        $idx = ((($sepIdx - $k) % $SEP_LOOK) + $SEP_LOOK) % $SEP_LOOK;
                        if ($sepPrev[$idx] >= $SEP_HIST) { $everSep = true; break; }
                    }
                    if ($everSep) { $mergedSig[$i] = true; $mergeCnt++; }
                }
            }
        }
        $sepPrev[$sepIdx] = $sep; $sepIdx = ($sepIdx + 1) % $SEP_LOOK;
    }
}
unset($vF, $chip);
$mergeWin24 = winState($mergedSig, $nF, $W24);
echo "mergedSig=$mergeCnt\n"; flush();

// ===== 布林(20,2σ) 中轨 + 触轨事件 + 24根窗 =====
$blMid = array_fill(0, $nF, null);
$q = []; $s = 0.0; $s2 = 0.0;
$bandL = []; $bandS = [];
for ($i = 0; $i < $nF; $i++) {
    if ($cF[$i] > 0) {
        $q[] = $cF[$i]; $s += $cF[$i]; $s2 += $cF[$i] * $cF[$i];
        if (count($q) > $P) { $old = array_shift($q); $s -= $old; $s2 -= $old * $old; }
        if (count($q) == $P) {
            $m = $s / $P; $v = $s2 / $P - $m * $m; if ($v < 0) $v = 0; $sd = sqrt($v);
            $blMid[$i] = $m;
            if ($cF[$i] <= $m - $K * $sd) $bandL[$i] = true;
            if ($cF[$i] >= $m + $K * $sd) $bandS[$i] = true;
        }
    }
}
$bandWin24L = winState($bandL, $nF, $W24);
$bandWin24S = winState($bandS, $nF, $W24);

// ===== 斐波那契0.618 (15m, 与实盘引擎 sigFibGolden 同口径) =====
// 多头: 近30根 lo/hi, fib=hi-(hi-lo)*0.618, 现价在fib±3%内 且 阳线; 空头: 镜像(阴线)
$fibL = []; $fibS = []; $fibLn = 0; $fibSn = 0;
for ($i = $FIB_N - 1; $i < $nF; $i++) {
    $lo = INF; $hi = -INF;
    for ($k = $i - $FIB_N + 1; $k <= $i; $k++) { if ($cF[$k] < $lo) $lo = $cF[$k]; if ($cF[$k] > $hi) $hi = $cF[$k]; }
    if ($hi <= $lo || $lo <= 0) continue;
    $fib = $hi - ($hi - $lo) * 0.618;
    $last = $cF[$i];
    if ($last >= $fib * (1 - $FIB_TOL) && $last <= $fib * (1 + $FIB_TOL)) {
        if ($last > $oF[$i]) { $fibL[$i] = true; $fibLn++; }
        elseif ($last < $oF[$i]) { $fibS[$i] = true; $fibSn++; }
    }
}
echo "fib618: L=$fibLn S=$fibSn\n"; flush();

// ===== 模拟: A2入场 + fib618加仓 + 中轨平仓 =====
$liqDrop = 1.0 / $LEV - $MMR;
$eq = $EQ0; $trades = []; $bankrupt = false;
$i = $W + 1;
$openedToday = false; $addsToday = 0; $curDay = bjday($tsF[$i]);
$entryBlocked = 0;
while ($i < $nF - 1 && !$bankrupt) {
    $d = bjday($tsF[$i]);
    if ($d !== $curDay) { $curDay = $d; $openedToday = false; $addsToday = 0; }
    $isL = isset($sigL[$i]); $isS = !$isL && isset($sigS[$i]);
    if ($isL || $isS) {
        $mgOk = $mergeWin24[$i];
        if (!$mgOk) { $isL = false; $isS = false; }
    }
    if (($isL || $isS)) {
        if ($isL && !$bandWin24L[$i]) $isL = false;
        if ($isS && !$bandWin24S[$i]) $isS = false;
    }
    if (!$isL && !$isS) { $i++; continue; }
    if ($openedToday) { $entryBlocked++; $i++; continue; }   // 每天最多1笔1U新仓
    if ($oF[$i + 1] <= 0) { $i++; continue; }
    $M0 = 1.0;
    if ($M0 > $eq) { if ($eq < 1) { $bankrupt = true; break; } }
    $e = $oF[$i + 1];
    $notional = $M0 * $LEV; $avg = $e; $adds = 0; $mg = $M0;
    $fee = $notional * $FEE_MAKER; $funding = 0.0;
    $liqPx = $isL ? $avg * (1 - $liqDrop) : $avg * (1 + $liqDrop);
    $outcome = null; $exitPx = 0.0; $j = $i + 1;
    $openedToday = true;
    $walkDay = $curDay;
    while ($j < $nF) {
        $jd = bjday($tsF[$j]);
        if ($jd !== $walkDay) { $walkDay = $jd; $addsToday = 0; }
        if ($hF[$j] <= 0 || $lF[$j] <= 0) { $j++; continue; }
        $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 32;
        $hitLiq = $isL ? ($lF[$j] <= $liqPx) : ($hF[$j] >= $liqPx);
        if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
        if ($blMid[$j] !== null && ($isL ? ($cF[$j] >= $blMid[$j]) : ($cF[$j] <= $blMid[$j]))) { $outcome = 'XREV'; $exitPx = $cF[$j]; break; }
        if ($j - $i >= $TO_DAYS * 24 * 4) { $outcome = 'TO'; $exitPx = $cF[$j]; break; }
        // 加仓: fib618(15m)同向信号 + 顺向 + 每日加仓额度3U + 仓位保证金≤50U
        $fibOk = $isL ? (isset($fibL[$j]) && $cF[$j] > $avg) : (isset($fibS[$j]) && $cF[$j] < $avg);
        if ($fibOk && $addsToday < $DAILY_ADD && $mg + $ADDU <= $CAPM && $j + 1 < $nF) {
            $ap = $oF[$j + 1];
            if ($ap > 0) {
                $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);
                $notional += $ADDU * $LEV; $adds++; $mg += $ADDU; $addsToday++;
                $fee += $ADDU * $LEV * $FEE_TAKER;
                $liqPx = $isL ? $avg * (1 - $liqDrop) : $avg * (1 + $liqDrop);
            }
        }
        $j++;
    }
    if ($outcome === null) { $outcome = 'TO'; $j = $nF - 1; $exitPx = $cF[$j]; }
    $fee += $notional * $FEE_TAKER;
    $gross = $isL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);
    $pnl = $gross - $fee - $funding;
    if ($pnl < -$mg) $pnl = -$mg;
    if ($pnl < -$eq) { $pnl = -$eq; $bankrupt = true; }
    $eq += $pnl;
    if ($eq <= 0.01) $bankrupt = true;
    $trades[] = ['side' => $isL ? 'LONG' : 'SHORT', 'tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome,
                 'adds' => $adds, 'mg' => round($mg, 2), 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),
                 'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];
    $i = $j + 1;
}

// ===== 汇总 =====
$tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'xrev' => 0, 'adds' => 0,
        'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0];
$holdSum = 0.0; $mgSum = 0.0;
$daily = []; $months = [];
foreach ($trades as $x) {
    if ($x['out'] == 'LIQ') $tot['liq']++;
    if ($x['out'] == 'TO') $tot['to']++;
    if ($x['out'] == 'XREV') $tot['xrev']++;
    if ($x['pnl'] > 0) $tot['win']++;
    $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl']; $holdSum += $x['holdh']; $mgSum += $x['mg'];
    if ($x['side'] == 'LONG') { $tot['l_n']++; $tot['l_pnl'] += $x['pnl']; }
    else { $tot['s_n']++; $tot['s_pnl'] += $x['pnl']; }
    $dd = bjday($x['tout']);
    $daily[$dd] = round(($daily[$dd] ?? 0) + $x['pnl'], 2);
    $m = substr($dd, 0, 7);
    $months[$m] = round(($months[$m] ?? 0) + $x['pnl'], 2);
}
ksort($daily); ksort($months);
$tot['pnl'] = round($tot['pnl'], 2); $tot['l_pnl'] = round($tot['l_pnl'], 2); $tot['s_pnl'] = round($tot['s_pnl'], 2);
$tot['hold_avg'] = $tot['n'] ? round($holdSum / $tot['n'], 1) : 0;
$tot['mg_avg'] = $tot['n'] ? round($mgSum / $tot['n'], 2) : 0;
$peak = -INF; $maxdd = 0;
foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd2 = $peak - $x['eq']; if ($dd2 > $maxdd) $maxdd = $dd2; }
// 日级权益曲线(每日收盘后权益 = 前值 + 当日已实现)
$eqCurve = []; $run = $EQ0; $ti = 0; $tn = count($trades);
$dayList = array_keys($daily);
$eqByDay = [];
$perDay = [];
foreach ($trades as $x) { $dd = bjday($x['tout']); $perDay[$dd] = round(($perDay[$dd] ?? 0) + $x['pnl'], 2); }
foreach ($perDay as $dd => $p) { $run = round($run + $p, 2); $eqByDay[$dd] = $run; }
echo sprintf("[A2FIB] n=%d win=%d(%.1f%%) liq=%d to=%d xrev=%d adds=%d pnl=%.2f (L=%.1f S=%.1f) eqEnd=%.2f maxdd=%.2f entryBlocked=%d bankrupt=%s\n",
    $tot['n'], $tot['win'], $tot['n'] ? $tot['win'] / $tot['n'] * 100 : 0, $tot['liq'], $tot['to'], $tot['xrev'],
    $tot['adds'], $tot['pnl'], $tot['l_pnl'], $tot['s_pnl'], $eq, $maxdd, $entryBlocked, $bankrupt ? 'YES' : 'no');

$out = [
    'meta' => [
        'inst' => $INST, 'lev' => $LEV, 'eq0' => $EQ0, 'capm' => $CAPM, 'daily_add' => $DAILY_ADD, 'to_days' => $TO_DAYS,
        'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF, 'bankrupt' => $bankrupt, 'entry_blocked' => $entryBlocked, 'maxdd' => round($maxdd, 2),
        'params' => 'ETH单合约 15m 一年 100x | 入场=A2口径: 六重共振新鲜触发(全市场1D MA20宽度>50%/1h>MA20/1h>MA10/4h>MA5/taker买比≥50%/BTC熔断禁入, 空头全反向) AND 筹码峰双线合一(滚动150根/60格vol_quote直方图·峰>1.3×均值·相邻3格合并·量能前2名; 价差≤0.4% 且此前24根内曾分离≥1% 先分后合·现价峰区±1%)≤24根(6h)状态窗 AND 布林(20,2σ)触轨(下轨→多/上轨→空)≤24根状态窗 | 平仓=回归中轨(均值回归)·100x爆仓线±0.6%照模拟·不设止损·超时7天兜底 | 资金: 每天1U新仓(每日最多开1笔) + 每天额外3U加仓额度 + 每次加仓1U | 加仓=斐波那契0.618黄金回调15m(与实盘引擎sigFibGolden同口径: 近30根高低点 fib=hi-(hi-lo)*0.618 现价±3%内; 多头阳线/空头镜像阴线) + 顺向 | 仓位总保证金封顶50U 加仓不限轮数 | 净口径含手续费(maker进/taker出+加仓)+资金费',
    ],
    'total' => $tot,
    'daily' => $daily, 'months' => $months, 'eq_curve' => $eqByDay,
    'counts' => ['sigL' => $cntL, 'sigS' => $cntS, 'merge' => $mergeCnt, 'fibL' => $fibLn, 'fibS' => $fibSn],
    'trades' => $trades,
];
file_put_contents('E:/finally-main/web/a2fib_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "DATA OK\n";
