<?php
/**
 * eth_sixchip2.php — ETH 单合约 六重共振×筹码峰双线合一×布林轨道共振 一年回测(2026-09-26 用户指令)
 * 用户: "六重共振((上轨做空/下轨做多, 赚均值回归)或改固定小止盈(+0.3%)) 用这个条件共振
 *        + 六重共振(双线合一, 同步筹码峰: 滚动150根K线/成交额摊60格直方图/峰>1.3×均值/相邻3格合并/量能前2名
 *        → 高价峰=拉升峰/低价峰=洗盘峰/最早期主峰=建仓峰; 买入=双线合一: 两峰价差≤0.4%(此前24根内曾分离≥1%,先分后合)
 *        且现价在峰区±1%内 → 下一根开盘买)" + 修正: "只单独算ETH"
 * 三组对照(同一数据/信号, 仅入场过滤与平仓不同):
 *   A/B组入场 = 六重共振新鲜触发 AND 双线合一 AND 布林(20,2σ)下轨触→做多/上轨触→做空
 *     [严格同刻 AND 实测一年仅1笔 → 按 chippeak4 先例改"状态+容差窗": 触轨≤6根(1.5h)/24根(6h)内, 合一≤8根(2h)/24根(6h)内]
 *   A 均值回归: 平仓=回归中轨 | B 固定止盈: 平仓=+0.3%价格(maker) | C 对照=sixchipall原口径(同刻合一·无轨道·±2%)
 *   加仓=同方向六重共振再触发+顺向+1U不限轮 | 每笔固定1U | 100x爆仓线照模拟 | 超时7天 | 净口径含手续费+资金费
 * 输出: web/sixchip2_data.json
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $TP03 = 0.003; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$EQ0 = 500.0; $ADDU = 1.0;
$W = 150; $NB = 60; $SEP_THR = 0.004; $SEP_HIST = 0.01; $SEP_LOOK = 24;
$INST = 'eth';
$P = 20; $K = 2.0; // 布林(20,2σ) @15m

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }

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

/** 增量筹码分布器(与eth_chippeak/eth_sixchipall同参) */
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
        $old = $this->ring[$this->ri];
        if ($old === null) return;
        if ($this->bw > 0) {
            $a = $this->adds($old[0], $old[1], $old[2]);
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0 && $this->hist[$i] >= $a[$i]) $this->hist[$i] -= $a[$i];
        }
        $this->ring[$this->ri] = null;
        $this->ri = ($this->ri + 1) % $this->cap;
    }
    /** 返回 [ [低价峰,量], [高价峰,量] ] 或 null */
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
$inst = $INST;
$gF = load_k($inst, '15m', true);
$tsF = $gF['ts']; $oF = $gF['o']; $hF = $gF['h']; $lF = $gF['l']; $cF = $gF['c']; $vF = $gF['v'];
$nF = count($tsF);
echo "ETH 15m bars=$nF span=" . bj($tsF[0]) . " ~ " . bj($tsF[$nF - 1]) . "\n"; flush();
$g1 = load_k($inst, '1h'); $g4 = load_k($inst, '4h');
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

// ===== 筹码峰 双线合一(逐15m K线) =====
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
echo "mergedSig=$mergeCnt\n"; flush();

// ===== 布林(20,2σ) @15m =====
$blMid = array_fill(0, $nF, null); $blUp = array_fill(0, $nF, null); $blLo = array_fill(0, $nF, null);
$q = []; $s = 0.0; $s2 = 0.0;
for ($i = 0; $i < $nF; $i++) {
    if ($cF[$i] <= 0) continue;
    $q[] = $cF[$i]; $s += $cF[$i]; $s2 += $cF[$i] * $cF[$i];
    if (count($q) > $P) { $old = array_shift($q); $s -= $old; $s2 -= $old * $old; }
    if (count($q) == $P) {
        $m = $s / $P; $v = $s2 / $P - $m * $m; if ($v < 0) $v = 0; $sd = sqrt($v);
        $blMid[$i] = $m; $blUp[$i] = $m + $K * $sd; $blLo[$i] = $m - $K * $sd;
    }
}
// 轨道共振(状态+容差窗): 信号根收盘触下轨→做多 / 触上轨→做空 (同刻 AND 实测一年仅1笔 → 改容差窗)
$bandL = []; $bandS = [];
$bandLn = 0; $bandSn = 0;
for ($i = 0; $i < $nF; $i++) {
    if ($blLo[$i] !== null && $cF[$i] <= $blLo[$i]) { $bandL[$i] = true; $bandLn++; }
    if ($blUp[$i] !== null && $cF[$i] >= $blUp[$i]) { $bandS[$i] = true; $bandSn++; }
}
/** 窗口状态: 数组事件在过去 $look 根内(含当前)出现过 → true */
function winState($events, $n, $look) {
    $out = array_fill(0, $n, false); $cnt = 0;
    for ($i = 0; $i < $n; $i++) {
        if (isset($events[$i])) $cnt++;
        if ($i - $look >= 0 && isset($events[$i - $look])) $cnt--;
        $out[$i] = $cnt > 0;
    }
    return $out;
}
echo "band touch: lower=$bandLn upper=$bandSn\n"; flush();

// ===== 模拟(多组) =====
$liqDrop = 1.0 / $LEV - $MMR;
/** $mode: MID=回归中轨 | TP03=固定止盈+0.3%(maker) | TP2=止盈±2%(taker)
 *  $bandLook/$mergeLook: 轨道触碰/双线合一 容差窗根数(0=关, merge同刻用原mergedSig) */
function sim($mode, $bandLook, $mergeLook, $tsF, $oF, $hF, $lF, $cF, $blMid, $bandWinL, $bandWinS, $mergeWin, $mergedSig, $sigL, $sigS, $nF) {
    global $LEV, $TPR, $TP03, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $EQ0, $ADDU, $liqDrop;
    $eq = $EQ0; $trades = []; $i = 0;
    while ($i < $nF - 1) {
        $isL = isset($sigL[$i]); $isS = !$isL && isset($sigS[$i]);
        if ($isL || $isS) { // 双线合一: 同刻 或 容差窗状态
            $mgOk = ($mergeLook == 0) ? isset($mergedSig[$i]) : $mergeWin[$i];
            if (!$mgOk) { $isL = false; $isS = false; }
        }
        if (($isL || $isS) && $bandLook > 0) { // 轨道共振容差窗
            if ($isL && !$bandWinL[$i]) $isL = false;
            if ($isS && !$bandWinS[$i]) $isS = false;
        }
        if (!$isL && !$isS) { $i++; continue; }
        if ($oF[$i + 1] <= 0) { $i++; continue; }
        $M0 = 1.0;
        $e = $oF[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER; $funding = 0.0;
        if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + ($mode == 'TP03' ? $TP03 : $TPR)); }
        else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - ($mode == 'TP03' ? $TP03 : $TPR)); }
        $outcome = null; $exitPx = 0.0; $exitMaker = false; $j = $i + 1;
        while ($j < $nF) {
            if ($hF[$j] <= 0 || $lF[$j] <= 0) { $j++; continue; }
            $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 32;
            $hitLiq = $isL ? ($lF[$j] <= $liqPx) : ($hF[$j] >= $liqPx);
            $hitTp  = ($mode != 'MID') ? ($isL ? ($hF[$j] >= $tgtPx) : ($lF[$j] <= $tgtPx)) : false;
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($hitTp) { $outcome = 'WIN'; $exitPx = $tgtPx; $exitMaker = ($mode == 'TP03'); break; }
            if ($mode == 'MID' && $blMid[$j] !== null) {
                if ($isL ? ($cF[$j] >= $blMid[$j]) : ($cF[$j] <= $blMid[$j])) { $outcome = 'XREV'; $exitPx = $cF[$j]; break; }
            }
            if ($j - $i >= 7 * 24 * 4) { $outcome = 'TO'; $exitPx = $cF[$j]; break; }
            // 加仓: 同方向六重共振再触发 + 顺向
            $sigOk = $isL ? (isset($sigL[$j]) && $cF[$j] > $avg) : (isset($sigS[$j]) && $cF[$j] < $avg);
            if ($sigOk && $j + 1 < $nF) {
                $ap = $oF[$j + 1];
                if ($ap > 0) {
                    $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);
                    $notional += $ADDU * $LEV; $adds++;
                    $fee += $ADDU * $LEV * $FEE_TAKER;
                    if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + ($mode == 'TP03' ? $TP03 : $TPR)); }
                    else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - ($mode == 'TP03' ? $TP03 : $TPR)); }
                }
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $nF - 1; $exitPx = $cF[$j]; }
        $fee += $notional * ($exitMaker ? $FEE_MAKER : $FEE_TAKER);
        $gross = $isL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);
        $pnl = $gross - $fee - $funding;
        $mg = $M0 + $adds * $ADDU;
        if ($pnl < -$mg) $pnl = -$mg;
        $eq += $pnl;
        $trades[] = ['side' => $isL ? 'LONG' : 'SHORT', 'tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds,
                     'm0' => $M0, 'mg' => $mg, 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),
                     'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];
        $i = $j + 1;
    }
    return [$trades, $eq];
}

$W6 = 6; $W8 = 8; $W24 = 24;   // 容差窗: 6根=1.5h / 8根=2h / 24根=6h
$bandWinL6 = winState($bandL, $nF, $W6);  $bandWinS6 = winState($bandS, $nF, $W6);
$bandWinL24 = winState($bandL, $nF, $W24); $bandWinS24 = winState($bandS, $nF, $W24);
$mergeWin8 = winState($mergedSig, $nF, $W8);
$mergeWin24 = winState($mergedSig, $nF, $W24);

$VARIANTS = [
    'A1' => ['name' => '轨道共振(6根窗)+合一(8根窗)+均值回归·中轨平仓', 'mode' => 'MID',  'bandLook' => 6,  'mergeLook' => 8,
             'bwL' => $bandWinL6, 'bwS' => $bandWinS6, 'mw' => $mergeWin8],
    'A2' => ['name' => '轨道共振(24根窗)+合一(24根窗)+均值回归·中轨平仓', 'mode' => 'MID',  'bandLook' => 24, 'mergeLook' => 24,
             'bwL' => $bandWinL24, 'bwS' => $bandWinS24, 'mw' => $mergeWin24],
    'B1' => ['name' => '轨道共振(6根窗)+合一(8根窗)+固定止盈+0.3%(maker)', 'mode' => 'TP03', 'bandLook' => 6,  'mergeLook' => 8,
             'bwL' => $bandWinL6, 'bwS' => $bandWinS6, 'mw' => $mergeWin8],
    'B2' => ['name' => '轨道共振(24根窗)+合一(24根窗)+固定止盈+0.3%(maker)', 'mode' => 'TP03', 'bandLook' => 24, 'mergeLook' => 24,
             'bwL' => $bandWinL24, 'bwS' => $bandWinS24, 'mw' => $mergeWin24],
    'C'  => ['name' => '原sixchipall口径(同刻合一·无轨道过滤·止盈±2%)对照', 'mode' => 'TP2', 'bandLook' => 0, 'mergeLook' => 0,
             'bwL' => [], 'bwS' => [], 'mw' => []],
];

$results = [];
foreach ($VARIANTS as $vk => $vc) {
    [$trades, $eq] = sim($vc['mode'], $vc['bandLook'], $vc['mergeLook'], $tsF, $oF, $hF, $lF, $cF, $blMid,
                         $vc['bwL'], $vc['bwS'], $vc['mw'], $mergedSig, $sigL, $sigS, $nF);
    $tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'xrev' => 0, 'adds' => 0, 'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0];
    $holdSum = 0.0;
    foreach ($trades as $x) {
        if ($x['out'] == 'LIQ') $tot['liq']++;
        if ($x['out'] == 'TO') $tot['to']++;
        if ($x['out'] == 'XREV') $tot['xrev']++;
        if ($x['pnl'] > 0) $tot['win']++;   // 胜率=实际盈利笔(含止盈/回归中轨盈利平仓)
        $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl']; $holdSum += $x['holdh'];
        if ($x['side'] == 'LONG') { $tot['l_n']++; $tot['l_pnl'] += $x['pnl']; }
        else { $tot['s_n']++; $tot['s_pnl'] += $x['pnl']; }
    }
    $tot['pnl'] = round($tot['pnl'], 2); $tot['l_pnl'] = round($tot['l_pnl'], 2); $tot['s_pnl'] = round($tot['s_pnl'], 2);
    $tot['hold_avg'] = $tot['n'] ? round($holdSum / $tot['n'], 1) : 0;
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
    $results[$vk] = ['summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),
                     'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF,
                     'sigL' => $cntL, 'sigS' => $cntS, 'merge' => $mergeCnt, 'bandLn' => $bandLn, 'bandSn' => $bandSn,
                     'months' => $months, 'trades' => $trades];
    echo sprintf("[%s] %s => n=%d win=%d liq=%d to=%d xrev=%d adds=%d pnl=%.2f (L=%.1f S=%.1f) eqEnd=%.1f maxdd=%.1f\n",
        $vk, $vc['name'], $tot['n'], $tot['win'], $tot['liq'], $tot['to'], $tot['xrev'], $tot['adds'], $tot['pnl'], $tot['l_pnl'], $tot['s_pnl'], $eq, $maxdd);
    flush();
}

$out = [
    'meta' => [
        'inst' => $INST, 'lev' => $LEV, 'eq0' => $EQ0, 'tp' => $TPR, 'tp03' => $TP03,
        'params' => 'ETH单合约(用户指令"只单独算ETH") | 15m K线一年 | 入场共三层: ①六重共振新鲜触发(多头=全市场1D MA20宽度>50%/1h>MA20/1h>MA10/4h>MA5/taker买比≥50%/BTC熔断禁入 从假→真; 空头=全部反向) ②筹码峰双线合一(滚动150根/60格vol_quote直方图增量更新, 峰=局部极大且>1.3×均值·相邻3格合并·量能前2名; 两峰价差≤0.4% 且此前24根内曾分离≥1% 先分后合; 现价峰区±1%) ③布林(20,2σ)轨道共振: 收盘触下轨→做多/触上轨→做空 [严格同刻一年仅1笔 → 状态+容差窗: A1/B1轨≤6根窗+合一≤8根窗; A2/B2各≤24根窗] | 平仓: A=回归中轨(均值回归) B=固定止盈+0.3%价格(maker挂单) C=止盈±2%(同刻合一·无轨道·对照) | 加仓=同方向六重共振再触发+顺向 不限轮 每轮+1U | 每笔固定1U·独立500U·100x·爆仓线±0.6%照模拟·超时7天·净口径含手续费+资金费',
    ],
    'variants' => array_map(fn($vk, $vc) => ['key' => $vk, 'name' => $vc['name']], array_keys($VARIANTS), $VARIANTS),
    'results' => $results,
];
file_put_contents('E:/finally-main/web/sixchip2_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "DATA OK\n";
