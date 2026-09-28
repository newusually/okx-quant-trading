<?php
/**
 * eth_chippeak4.php — ETH 筹码峰战法·四周期共振 一年回测(CLI, 2026-09-25 用户指令)
 * 共振定义(以 3m 为入场时间轴):
 *   触发 = 3m 当根出现新鲜「双线合一」信号(价差≤0.4%, 此前24根内曾分离≥1%, 现价在峰区±1%, 2根去抖);
 *   共振 = 5m / 15m / 1h 各自【最新已收盘K线】的筹码峰双双线合一状态成立(价差≤0.4%);
 *   买入 = 触发×共振 → 下一根 3m 开盘。四周期筹码峰算法与单周期版完全一致
 *   (150根K线 vol_quote 60格增量直方图, 峰>1.3×均值, 相邻3格合并, 量能前2名)。
 * 加仓/平仓与单周期版相同:
 *   加仓 = 持仓中再触发(3m新信号×共振) 且 现价站上开仓峰区上方 → +1U(下一根开盘), 总保证金≤50U 停止;
 *   平仓 = 出货峰(收盘曾上穿开仓锁仓峰区上方后跌破峰区下沿, 下一根开盘全平) 或 超时14天;
 *   保证金 = 1U+3U×已过天数 封顶50U; 100x逐仓; 500U逐笔结转; 净口径含费。
 * 双配置: user = 无止盈·无止损·不模拟爆仓; ctrl = 止盈价格+2% + 真实爆仓线(-0.6%)。
 * 输出: web/chippeak4_data.json
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$EQ0 = 500.0; $CAPM = 50.0; $ADDU = 1.0; $TPR = 0.02;
$W = 150; $NB = 60; $SEP_THR = 0.004; $SEP_HIST = 0.01; $SEP_LOOK = 24; $TO_BARS_DAYS = 14;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }
function bjday($ms) { return gmdate('Y-m-d', (int)($ms / 1000) + 8 * 3600); }

function load_k($bar) {
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`,vol_quote FROM kline_eth_usdt_swap_$bar ORDER BY candle_time ASC");
    $out = ['ts' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
    foreach ($r as $x) { $out['ts'][] = (int)$x[0]; $out['o'][] = (float)$x[1]; $out['h'][] = (float)$x[2];
        $out['l'][] = (float)$x[3]; $out['c'][] = (float)$x[4]; $out['v'][] = (float)$x[5]; }
    return $out;
}

/** 增量筹码分布器: 滚动窗口极值定区间, 区间漂移>0.5%或出界时全量重建, 否则增量加减 */
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
    /** 返回 [洗盘峰价, 拉升峰价] 或 null */
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
        return [$two[0][0], $two[1][0]];
    }
}

/** 单周期全量扫描: 输出逐根 merged/sig/双峰价 */
function scan($bar, $g) {
    global $W, $NB, $SEP_THR, $SEP_HIST, $SEP_LOOK;
    $ts = $g['ts']; $l = $g['l']; $h = $g['h']; $c = $g['c']; $v = $g['v'];
    $n = count($ts);
    $chip = new Chip($NB, $W);
    for ($i = 0; $i < $W; $i++) $chip->push($l[$i], $h[$i], $v[$i]);
    $sepPrev = array_fill(0, $SEP_LOOK, 0.0); $sepIdx = 0;
    $merged = array_fill(0, $n, false);
    $sig = array_fill(0, $n, false);
    $pl = array_fill(0, $n, 0.0); $ph = array_fill(0, $n, 0.0);
    $cnt = ['merged' => 0, 'sig' => 0];
    for ($i = $W; $i < $n; $i++) {
        $chip->evictOldest();
        $chip->push($l[$i], $h[$i], $v[$i]);
        $pk = $chip->peaks();
        $sep = 0.0;
        if ($pk !== null) {
            $pl[$i] = $pk[0]; $ph[$i] = $pk[1];
            $sep = ($pk[1] - $pk[0]) / $pk[0];
            if ($sep <= $SEP_THR && $c[$i] >= $pk[0] * 0.99 && $c[$i] <= $pk[1] * 1.01) {
                $had = false;
                for ($k = 0; $k < $SEP_LOOK; $k++) if ($sepPrev[$k] >= $SEP_HIST) { $had = true; break; }
                if ($had) {
                    $merged[$i] = true; $cnt['merged']++;
                    if (empty($sig[$i - 1]) && empty($sig[$i - 2])) { $sig[$i] = true; $cnt['sig']++; }
                }
            }
        }
        $sepPrev[$sepIdx] = $sep; $sepIdx = ($sepIdx + 1) % $SEP_LOOK;
    }
    echo "[$bar] n=$n merged={$cnt['merged']} sig={$cnt['sig']}\n"; flush();
    return ['ts' => $ts, 'merged' => $merged, 'sig' => $sig, 'pl' => $pl, 'ph' => $ph, 'cnt' => $cnt];
}

// ===== 四周期全量扫描 =====
$S = [];
foreach (['3m', '5m', '15m', '1h'] as $b) $S[$b] = scan($b, load_k($b));

// ===== 3m 时间轴共振信号 =====
$tsF = $S['3m']['ts']; $oF = load_k('3m')['o']; $hF = load_k('3m')['h']; $lF = load_k('3m')['l']; $cF = load_k('3m')['c'];
$nF = count($tsF);
$barMin = 3;
$p = ['5m' => $W, '15m' => $W, '1h' => $W];
$len = ['5m' => count($S['5m']['ts']), '15m' => count($S['15m']['ts']), '1h' => count($S['1h']['ts'])];
$step = ['5m' => 5, '15m' => 15, '1h' => 60];
$tol = ['5m' => 120, '15m' => 240, '1h' => 2880];   // 共振容差(分钟): 该周期最近一次「双线合一信号」距今 ≤ 容差
$lastM = ['5m' => -1, '15m' => -1, '1h' => -1];  // 最近一次合一K线的收盘时间(ms)
$entry = array_fill(0, $nF, false);
$passCnt = ['base' => 0, 'm5' => 0, 'm15' => 0, 'm1h' => 0, 'entry' => 0];
for ($i = $W; $i < $nF - 1; $i++) {
    if (empty($S['3m']['merged'][$i])) continue;   // 3m 处于双线合一状态(触发周期)
    $passCnt['base']++;
    $t = $tsF[$i];
    foreach (['5m', '15m', '1h'] as $b) {
        while ($p[$b] < $len[$b] && $S[$b]['ts'][$p[$b]] + $step[$b] * 60000 <= $t) {
            if ($S[$b]['merged'][$p[$b]]) $lastM[$b] = $S[$b]['ts'][$p[$b]] + $step[$b] * 60000;
            $p[$b]++;
        }
        if ($lastM[$b] < 0 || $t - $lastM[$b] > $tol[$b] * 60000) continue 2;   // 超出共振容差
        $passCnt[$b === '5m' ? 'm5' : ($b === '15m' ? 'm15' : 'm1h')]++;
    }
    $entry[$i] = true; $passCnt['entry']++;
}
echo "resonance entries=$passCnt[entry]\n"; flush();

/** 3m时间轴模拟 ($useTP/$useLiq) */
function sim($entry, $S3, $tsF, $oF, $hF, $lF, $cF, $useTP, $useLiq) {
    global $LEV, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $EQ0, $CAPM, $ADDU, $W, $TO_BARS_DAYS, $TPR;
    $n = count($tsF);
    $fundPerBar = $FUND8H / 160;   // 3m: 每15分钟=1/32个8h, 3m=1/160
    $eq = $EQ0; $startT = $tsF[$W]; $trades = []; $bankrupt = false;
    $i = $W;
    while ($i < $n - 1 && !$bankrupt) {
        if (empty($entry[$i])) { $i++; continue; }
        $days = (int)floor((($tsF[$i] - $startT) / 1000) / 86400);
        $M0 = round(min(1.0 + 3.0 * $days, $CAPM), 2);
        if ($M0 < 1) $M0 = 1.0;
        if ($M0 > $eq) { if ($eq < 1) { $bankrupt = true; break; } $M0 = round($eq, 2); }
        $e = $oF[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0; $mg = $M0;
        $fee = $notional * $FEE_MAKER; $funding = 0.0;
        $tgtPx = $useTP ? $avg * (1 + $TPR) : INF;
        $liqPx = $useLiq ? $avg * (1 - 1.0 / $LEV + $MMR) : -INF;
        // 锚定开仓时 3m 筹码峰区
        $zb0 = $S3['pl'][$i] > 0 ? $S3['pl'][$i] * 0.998 : $e * 0.99;
        $zt0 = $S3['ph'][$i] > 0 ? $S3['ph'][$i] * 1.002 : $e * 1.01;
        $armed = false; $outcome = null; $exitPx = null; $j = $i + 1;
        while ($j < $n) {
            $funding += $notional * $fundPerBar;
            if ($useLiq && $lF[$j] <= $liqPx) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($useTP && $hF[$j] >= $tgtPx) { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            if (!$armed && $cF[$j] > $zt0) $armed = true;
            if ($armed && $cF[$j] < $zb0) { $outcome = 'SHIP'; $exitPx = ($j + 1 < $n) ? $oF[$j + 1] : $cF[$j]; break; }
            if ($j - $i >= $TO_BARS_DAYS * 480) { $outcome = 'TO'; $exitPx = $cF[$j]; break; }
            if (isset($entry[$j]) && $cF[$j] > $zt0 && $mg + $ADDU <= $CAPM && $j + 1 < $n) {
                $ap = $oF[$j + 1];
                if ($ap > 0) {
                    $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);
                    $notional += $ADDU * $LEV; $adds++; $mg += $ADDU;
                    $fee += $ADDU * $LEV * $FEE_TAKER;
                    $tgtPx = $useTP ? $avg * (1 + $TPR) : INF;
                    $liqPx = $useLiq ? $avg * (1 - 1.0 / $LEV + $MMR) : -INF;
                }
            }
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $cF[$j]; }
        $gross = ($exitPx - $avg) * $notional / $avg;
        $fee += $notional * $FEE_TAKER;
        $pnl = $gross - $fee - $funding;
        if ($pnl < -$eq) { $pnl = -$eq; $bankrupt = true; }
        $eq += $pnl;
        if ($eq <= 0.01) $bankrupt = true;
        $trades[] = ['tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds,
                     'm0' => $M0, 'mg' => round($mg, 2), 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),
                     'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];
        $i = $j + 1;
    }
    $tot = ['n' => count($trades), 'ship' => 0, 'to' => 0, 'win' => 0, 'liq' => 0, 'adds' => 0, 'pnl' => 0.0];
    foreach ($trades as $x) {
        if ($x['out'] == 'SHIP') $tot['ship']++;
        if ($x['out'] == 'TO') $tot['to']++;
        if ($x['out'] == 'WIN') $tot['win']++;
        if ($x['out'] == 'LIQ') $tot['liq']++;
        $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl'];
    }
    $tot['pnl'] = round($tot['pnl'], 2);
    $months = []; $daily = [];
    foreach ($trades as $x) {
        $d0 = bjday($x['tout']); $mo = substr($d0, 0, 7);
        $m = &$months[$mo];
        $m['n'] = ($m['n'] ?? 0) + 1;
        $m['ship'] = ($m['ship'] ?? 0) + ($x['out'] == 'SHIP' ? 1 : 0);
        $m['to'] = ($m['to'] ?? 0) + ($x['out'] == 'TO' ? 1 : 0);
        $m['win'] = ($m['win'] ?? 0) + ($x['out'] == 'WIN' ? 1 : 0);
        $m['liq'] = ($m['liq'] ?? 0) + ($x['out'] == 'LIQ' ? 1 : 0);
        $m['adds'] = ($m['adds'] ?? 0) + $x['adds'];
        $m['mg'] = round(($m['mg'] ?? 0) + $x['mg'], 2);
        $m['pnl'] = round(($m['pnl'] ?? 0) + $x['pnl'], 2);
        $m['eq_end'] = $x['eq'];
        unset($m);
        $d = &$daily[$d0];
        $d['n'] = ($d['n'] ?? 0) + 1;
        $d['adds'] = ($d['adds'] ?? 0) + $x['adds'];
        $d['mg'] = round(($d['mg'] ?? 0) + $x['mg'], 2);
        $d['pnl'] = round(($d['pnl'] ?? 0) + $x['pnl'], 2);
        $d['eq_end'] = $x['eq'];
        unset($d);
    }
    $peak = -INF; $maxdd = 0;
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }
    echo sprintf("[sim %s] n=%d ship=%d win=%d liq=%d to=%d adds=%d pnl=%.1f eqEnd=%.1f bk=%s\n",
        $useTP ? 'ctrl' : 'user', $tot['n'], $tot['ship'], $tot['win'], $tot['liq'], $tot['to'], $tot['adds'], $tot['pnl'], $eq, $bankrupt ? 'Y' : 'N');
    flush();
    return ['summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),
            'bankrupt' => $bankrupt, 'months' => $months, 'daily' => $daily, 'trades' => $trades];
}

$results = [];
$results['user'] = sim($entry, $S['3m'], $tsF, $oF, $hF, $lF, $cF, false, false);
$results['ctrl'] = sim($entry, $S['3m'], $tsF, $oF, $hF, $lF, $cF, true, true);

$out = [
    'meta' => [
        'inst' => 'ETH-USDT-SWAP', 'lev' => $LEV, 'eq0' => $EQ0, 'capm' => $CAPM,
        'pass' => $passCnt,
        'spans' => ['3m' => [bj($S['3m']['ts'][0]), bj(end($S['3m']['ts']))],
                    '5m' => [bj($S['5m']['ts'][0]), bj(end($S['5m']['ts']))],
                    '15m' => [bj($S['15m']['ts'][0]), bj(end($S['15m']['ts']))],
                    '1h' => [bj($S['1h']['ts'][0]), bj(end($S['1h']['ts']))]],
        'counts' => ['3m' => $S['3m']['cnt'], '5m' => $S['5m']['cnt'], '15m' => $S['15m']['cnt'], '1h' => $S['1h']['cnt']],
        'params' => '四周期共振筹码峰(共振容差: 5m≤2小时 / 15m≤4小时 / 1h≤48小时, 以各周期最近一次双线合一收盘距今计): 触发=3m 处于「双线合一」状态(拉升峰与洗盘峰价差≤0.4%且现价在峰区±1%内, 先分后合≥1%); 共振=5m/15m/1h 容差窗口内均出现过双线合一 → 下一根3m开盘买入 | 加仓=持仓中再出现共振入场点且现价站上开仓峰区上方 +1U, 总保证金≤50U | 平仓=出货峰(收盘曾上穿开仓锁仓峰区后跌破下沿)或超时14天 | 保证金=1U+3U×已过天数 封顶50U | 100x逐仓 | 起始500U | 净口径含手续费+资金费 | 筹码峰=各周期150根K线 vol_quote 60格增量直方图, 峰>1.3×均值, 相邻3格合并, 量能前2名 | user配置=无止盈无止损不模拟爆仓(权益归0破产); ctrl对照=止盈价格+2%+真实爆仓线-0.6%',
    ],
    'results' => $results,
];
file_put_contents('E:/finally-main/web/chippeak4_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "DATA OK " . round(memory_get_peak_usage(true) / 1048576) . "MB\n";
