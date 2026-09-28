<?php
/**
 * eth_sixchipall.php — 全市场(剔除美股/ETF/商品代币化永续) 六重共振×筹码峰双线合一 一年回测
 * 用户(2026-09-26): "美股/ETF代币化永续全部取消, 信号全部换成六重共振(不再用三均线):
 *   多头信号=牛市六条件从假→真新鲜触发15m; 空头=六条件全部反向新鲜触发;
 *   另外要求同步筹码峰: 滚动150根/60格直方图/峰>1.3×均值/相邻3格合并/量能前2名;
 *   买入=双线合一(价差≤0.4%, 此前24根内曾分离≥1%, 现价峰区±1%) 下一根开盘买;
 *   加仓=持仓中同方向六重共振再触发+顺向 +1U不限轮数; 每笔固定1U; 每合约独立500U;
 *   止盈±2% | 100x爆仓线照模拟 | 超时7天 | 净口径含手续费"
 * 即: 入场 = 六重共振新鲜触发 AND 该根K线筹码峰双线合一(双重过滤)
 * 输出: web/sixchipall_data.json
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$EQ0 = 500.0; $ADDU = 1.0;
$W = 150; $NB = 60; $SEP_THR = 0.004; $SEP_HIST = 0.01; $SEP_LOOK = 24;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }

// ===== 剔除清单: 美股/全球股票/pre-IPO/ETF/指数/商品/外汇 代币化永续 =====
$EXCL = explode(' ', 'aaoi aapl adbe amd alab amat amc amzn anthropic app asml avgo axti bill brkb bx cien coin cost crcl crdo crm crwv csco cxmt ddog dell dgai dkng gme glw gpro gps gtlb hanmi hims hood hpe hut hyundai ibm intc ionq iren isrg jnj kioxia klac lgelectronics lly lrcx lunr mara meta mrvl mrk mrna msft mstr mstu mu naver nbis net nflx nok now nvda nvdl oklo okta onds on openai orcl oscr oura oust poet pypl qcom rddt rdw riot rivn rklb rok samsung shein shell simo skdd skhynix skhy smci sndk snow softbank sony spcx strc tsem tsla tsll tsm ttmi ttwo twlo unh vrt wdc wmt xiaom xiaomi xom zhipu zhongji zm ewj ewt ewy ewz iwm jp225 kr200 qqq smh soxl soxs spy sqqq tqqq tmf us100 us500 uvxy xbi xle uso urnm xau xag xpd xpt xcu cl bz');

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

/** 增量筹码分布器(与eth_chippeak.php同参): 滚动窗口极值定区间, 漂移>0.5%或出界重建 */
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
        return [[$two[0][0], $two[0][1]], [$two[1][0], $two[1][1]]]; // [洗盘峰(低), 拉升峰(高)]
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

// ===== 合约清单(剔除美股/ETF/商品) =====
$insts = []; $exclN = 0;
foreach (rows("SHOW TABLES LIKE 'kline\\_%\\_usdt\\_swap\\_15m'") as $x) {
    if (preg_match('/^kline_(.+)_usdt_swap_15m$/', $x[0], $m)) {
        if (in_array($m[1], $EXCL, true)) { $exclN++; continue; }
        $insts[] = $m[1];
    }
}
sort($insts);
echo "insts=" . count($insts) . " (excluded US/ETF/commodity=$exclN)\n"; flush();

// ===== 逐合约回测 =====
$liqDrop = 1.0 / $LEV - $MMR;
$results = []; $done = 0;
foreach ($insts as $inst) {
    $gF = load_k($inst, '15m', true);
    $tsF = $gF['ts']; $oF = $gF['o']; $hF = $gF['h']; $lF = $gF['l']; $cF = $gF['c']; $vF = $gF['v'];
    $nF = count($tsF);
    if ($nF < 3000) { $results[$inst] = ['skip' => '15m数据不足(' . $nF . '根)']; continue; }
    $g1 = load_k($inst, '1h'); $g4 = load_k($inst, '4h');
    $ts1 = $g1['ts']; $c1 = $g1['c']; $ts4 = $g4['ts']; $c4 = $g4['c'];
    $n1 = count($ts1); $n4 = count($ts4);
    if ($n1 < 300 || $n4 < 80) { $results[$inst] = ['skip' => '1h/4h数据不足']; continue; }
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
                    $mid = ($pl + $ph) / 2;
                    $mg = ($sep <= $SEP_THR) && ($cF[$i] >= $mid * 0.99) && ($cF[$i] <= $mid * 1.01);
                    if ($mg) {
                        $everSep = false;
                        for ($k = 1; $k <= $SEP_LOOK; $k++) {
                            $idx = ((($sepIdx - $k) % $SEP_LOOK) + $SEP_LOOK) % $SEP_LOOK;   // 修复PHP负取模
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

    // ===== 模拟: 入场=六重共振新鲜触发 AND 双线合一 =====
    $eq = $EQ0;
    $trades = []; $i = 0;
    while ($i < $nF - 1) {
        $isL = isset($sigL[$i]) && isset($mergedSig[$i]);
        $isS = !$isL && isset($sigS[$i]) && isset($mergedSig[$i]);
        if (!$isL && !$isS) { $i++; continue; }
        if ($i + 1 >= $nF || $oF[$i + 1] <= 0) { $i++; continue; }
        $side = $isL ? 'LONG' : 'SHORT';
        $M0 = 1.0;
        $e = $oF[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;
        $fee = $notional * $FEE_MAKER; $funding = 0.0;
        if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); }
        else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - $TPR); }
        $outcome = null; $j = $i + 1;
        while ($j < $nF) {
            if ($hF[$j] <= 0 || $lF[$j] <= 0) { $j++; continue; }
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
        'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF, 'sigL' => $cntL, 'sigS' => $cntS, 'merge' => $mergeCnt,
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
echo sprintf("TOTAL insts=%d excluded=%d traded=%d n=%d win=%d liq=%d adds=%d pnl=%.1f (L=%.1f S=%.1f) posInsts=%d\n",
    count($insts), $exclN, count($rank), $totN, $totWin, $totLiq, $totAdds, $totPnl, $lp, $sp, $posCnt);

$allMon = [];
foreach ($results as $inst => $r) {
    if (isset($r['skip'])) continue;
    foreach ($r['months'] as $m => $v) $allMon[$m] = round(($allMon[$m] ?? 0) + $v['pnl'], 2);
}
ksort($allMon);

$out = [
    'meta' => [
        'lev' => $LEV, 'tp' => '±2%', 'eq0' => $EQ0, 'insts' => count($insts), 'excluded' => $exclN,
        'params' => '范围=全市场剔除美股/ETF/指数/商品/外汇/全球股票代币化永续(剔除' . $exclN . '个) | 信号=六重共振(不再用三均线): 多头=牛市六条件(全市场1D MA20宽度>50%/1h>MA20/1h>MA10/4h>MA5/taker买比≥50%/BTC熔断禁入)从假→真新鲜触发15m; 空头=六条件全部反向新鲜触发 | 入场=六重共振新鲜触发 AND 筹码峰双线合一(滚动150根/60格vol_quote直方图, 峰>1.3×均值·相邻3格合并·量能前2名, 两峰价差≤0.4% 且此前24根内曾分离≥1%, 现价峰区±1%) → 下一根开盘买 | 加仓=持仓中同方向六重共振再触发+顺向(多:现价>均价/空:现价<均价) 不限轮数 每轮+1U | 保证金=每笔固定1U | 止盈=价格±2% | 不设止损·爆仓线±0.6%@100x照模拟 | 超时7天 | 资金费=多头付/空头收 0.01%/8h | 每合约独立起始500U·每笔1U | 净口径含手续费',
    ],
    'total' => ['insts' => count($rank), 'skipped' => count($insts) - count($rank), 'n' => $totN, 'win' => $totWin, 'liq' => $totLiq,
                'adds' => $totAdds, 'pnl' => round($totPnl, 2), 'l_pnl' => round($lp, 2), 's_pnl' => round($sp, 2),
                'pos_insts' => $posCnt, 'months' => $allMon],
    'rank' => $rank, 'results' => $results,
];
file_put_contents('E:/finally-main/web/sixchipall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "DATA OK\n";
