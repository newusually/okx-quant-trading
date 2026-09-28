<?php
/**
 * eth_chippeak.php — ETH 筹码峰战法 一年回测(CLI, 2026-09-25 用户指令)
 * 策略 = 筹码峰战法(3m/5m/15m/1h 四周期):
 *   筹码峰算法: 滚动最近150根K线, 每根K线把 vol_quote 均匀摊到其 [低,高] 区间
 *     (60格直方图, 增量更新); 峰 = 局部极大(>1.3×均值), 相邻3格内合并, 取量能前2名:
 *     高价峰 = 拉升峰, 低价峰 = 洗盘峰; 最早期主峰 = 建仓峰(锁仓, 不破不出)。
 *   买入 = 双线合一: 拉升峰与洗盘峰 价差≤0.4%(合一), 且此前24根内曾分离≥1%(先分后合),
 *          现价在峰区±1%内; 下一根开盘买入。同一根K线只触发1次。
 *   加仓 = 持仓中再出现 双线合一 → +1U(下一根开盘), 不限轮数, 总保证金(含加仓)≤50U 停止。
 *   平仓 = 出货峰: 收盘曾上穿峰区上方(锁仓拉升成立)后, 收盘跌破峰区下沿 → 下一根开盘全平;
 *          超时14天平收。不设止损; 不模拟爆仓(浮亏照算, 权益扣到0即破产终止)。
 *   保证金 = 1U + 3U×已过天数, 封顶50U; 100x 逐仓; 起始权益 500U 逐笔结转。
 *   净口径: 开仓maker 0.02% / 加仓·平仓 taker 0.05% / 资金费 0.01%/8h。
 * 输出: web/chippeak_data.json
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
        // 满窗: 扫描窗口极值, 漂移>0.5%或出界→重建, 否则增量加
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
        // 相邻3格内合并
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

/** 单周期回测 ($useTP=止盈+2%  $useLiq=真实爆仓线) */
function sim($bar, $g, $useTP, $useLiq) {
    global $LEV, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $EQ0, $CAPM, $ADDU, $W, $NB,
           $SEP_THR, $SEP_HIST, $SEP_LOOK, $TO_BARS_DAYS, $TPR;
    $ts = $g['ts']; $o = $g['o']; $h = $g['h']; $l = $g['l']; $c = $g['c']; $v = $g['v'];
    $n = count($ts);
    $barMin = (int)round(($ts[1] - $ts[0]) / 60000);
    $fundPerBar = $FUND8H / (8 * 60 / $barMin);
    $chip = new Chip($NB, $W);
    // 先填充窗口(不产生信号)
    for ($i = 0; $i < $W; $i++) $chip->push($l[$i], $h[$i], $v[$i]);

    $sepPrev = array_fill(0, $SEP_LOOK, 0.0); $sepIdx = 0;
    $signals = 0; $blocked = 0;
    $entrySig = [];  // i => true
    for ($i = $W; $i < $n - 1; $i++) {
        $chip->evictOldest();
        $chip->push($l[$i], $h[$i], $v[$i]);
        $pk = $chip->peaks();
        $sep = 0.0; $merged = false;
        if ($pk !== null) {
            $pl = $pk[0][0]; $ph = $pk[1][0];
            $sep = ($ph - $pl) / $pl;
            $mid = ($pl + $ph) / 2;
            $merged = ($sep <= $SEP_THR) && ($c[$i] >= $pl * 0.99) && ($c[$i] <= $ph * 1.01);
            // 先分后合: 此前 LOOK 根内曾分离≥SEP_HIST
            if ($merged) {
                $had = false;
                for ($k = 0; $k < $SEP_LOOK; $k++) if ($sepPrev[$k] >= $SEP_HIST) { $had = true; break; }
                if (!$had) $merged = false;
            }
        }
        if ($merged) { $signals++; if (empty($entrySig[$i - 1]) && empty($entrySig[$i - 2])) $entrySig[$i] = true; else $blocked++; }
        $sepPrev[$sepIdx] = $sep; $sepIdx = ($sepIdx + 1) % $SEP_LOOK;
    }
    echo "[$bar] bars=$n signals=$signals blocked=$blocked\n"; flush();

    // ===== sim =====
    $eq = $EQ0; $startT = $ts[$W]; $trades = []; $bankrupt = false;
    $i = $W;
    while ($i < $n - 1 && !$bankrupt) {
        if (empty($entrySig[$i])) { $i++; continue; }
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400);
        $M0 = round(min(1.0 + 3.0 * $days, $CAPM), 2);
        if ($M0 < 1) $M0 = 1.0;
        if ($M0 > $eq) { if ($eq < 1) { $bankrupt = true; break; } $M0 = round($eq, 2); }
        $e = $o[$i + 1];
        $notional = $M0 * $LEV; $avg = $e; $adds = 0; $mg = $M0;
        $fee = $notional * $FEE_MAKER; $funding = 0.0;
        $tgtPx = $useTP ? $avg * (1 + $TPR) : INF;
        $liqPx = $useLiq ? $avg * (1 - 1.0 / $LEV + $MMR) : -INF;
        $armed = false; $outcome = null; $exitPx = null; $j = $i + 1;
        // 锚定开仓时的锁仓峰区(建仓峰区): 跌破下沿=出货, 上穿上方=锁仓拉升成立
        $c2 = new Chip($NB, $W);
        for ($k = max(0, $i + 1 - $W); $k <= $i; $k++) $c2->push($l[$k], $h[$k], $v[$k]);
        $pk0 = $c2->peaks();
        if ($pk0 !== null) { $zb0 = $pk0[0][0] * 0.998; $zt0 = $pk0[1][0] * 1.002; }
        else { $zb0 = $e * 0.99; $zt0 = $e * 1.01; }
        while ($j < $n) {
            $funding += $notional * $fundPerBar;
            // 对照配置: 真实爆仓线 / 止盈+2% (盘中判定)
            if ($useLiq && $l[$j] <= $liqPx) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }
            if ($useTP && $h[$j] >= $tgtPx) { $outcome = 'WIN'; $exitPx = $tgtPx; break; }
            // 出货峰判定: 收盘曾上穿锁仓峰区上方后, 收盘跌破峰区下沿 → 出货
            if (!$armed && $c[$j] > $zt0) $armed = true;
            if ($armed && $c[$j] < $zb0) { $outcome = 'SHIP'; $exitPx = ($j + 1 < $n) ? $o[$j + 1] : $c[$j]; break; }
            if ($j - $i >= $TO_BARS_DAYS * 86400 * 1000 / ($barMin * 60000)) { $outcome = 'TO'; $exitPx = $c[$j]; break; }
            // 加仓: 再现双线合一 + 现价站上峰区上方(锁仓拉升) + 总保证金≤50U
            if (isset($entrySig[$j]) && $c[$j] > $zt0 && $mg + $ADDU <= $CAPM && $j + 1 < $n) {
                $ap = $o[$j + 1];
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
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }
        $gross = ($exitPx - $avg) * $notional / $avg;
        $fee += $notional * $FEE_TAKER;
        $pnl = $gross - $fee - $funding;
        if ($pnl < -$eq) { $pnl = -$eq; $bankrupt = true; }
        $eq += $pnl;
        if ($eq <= 0.01) $bankrupt = true;
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,
                     'm0' => $M0, 'mg' => round($mg, 2), 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),
                     'holdh' => round(($ts[$j] - $ts[$i]) / 3600000.0, 1)];
        $i = $j + 1;
    }
    // ===== 汇总 =====
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
        $mo = bjday($x['tout']); $mo = substr($mo, 0, 7);
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
        $d = &$daily[bjday($x['tout'])];
        $d['n'] = ($d['n'] ?? 0) + 1;
        $d['adds'] = ($d['adds'] ?? 0) + $x['adds'];
        $d['mg'] = round(($d['mg'] ?? 0) + $x['mg'], 2);
        $d['pnl'] = round(($d['pnl'] ?? 0) + $x['pnl'], 2);
        $d['eq_end'] = $x['eq'];
        unset($d);
    }
    $peak = -INF; $maxdd = 0;
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }
    echo sprintf("[%s] n=%d ship=%d to=%d adds=%d pnl=%.1f eqEnd=%.1f maxdd=%.1f bk=%s\n",
        $bar, $tot['n'], $tot['ship'], $tot['to'], $tot['adds'], $tot['pnl'], $eq, $maxdd, $bankrupt ? 'Y' : 'N');
    flush();
    return [
        'bar' => $bar, 'bars' => $n,
        'span' => [bj($ts[0]), bj($ts[$n - 1])],
        'signals' => $signals, 'blocked' => $blocked,
        'summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),
        'bankrupt' => $bankrupt, 'months' => $months, 'daily' => $daily, 'trades' => $trades,
    ];
}

$results = [];
foreach (['3m', '5m', '15m', '1h'] as $bar) {
    $g = load_k($bar);
    $results[$bar]['user'] = sim($bar, $g, false, false);   // 用户配置: 无止盈·无止损·不模拟爆仓
    $results[$bar]['ctrl'] = sim($bar, $g, true, true);     // 对照配置: 止盈+2% + 真实爆仓线
}

$out = [
    'meta' => [
        'inst' => 'ETH-USDT-SWAP', 'lev' => $LEV, 'eq0' => $EQ0, 'capm' => $CAPM, 'tpr' => 0.02,
        'params' => '筹码峰战法: 买入=拉升峰与洗盘峰「双线合一」(价差≤0.4%, 先分后合≥1%, 现价在峰区±1%内) 下一根开盘买入 | 加仓=持仓中再现双线合一且现价站上峰区上方 → +1U(下一根开盘), 不限轮数, 总保证金(含加仓)≤50U 停止 | 平仓=出货峰(收盘曾上穿开仓锁仓峰区上方后跌破峰区下沿, 下一根开盘全平) 或 超时14天 | 保证金=1U+3U×已过天数 封顶50U | 100x逐仓 | 起始权益500U 逐笔结转 | 净口径: 开仓maker0.02%+加仓/平仓taker0.05%+资金费0.01%/8h | 筹码峰=150根K线 vol_quote 60格增量直方图, 峰>1.3×均值, 相邻3格合并, 量能前2名: 高价峰=拉升峰 低价峰=洗盘峰 最早期主峰=建仓峰(锁仓不动) | user配置=用户指定(无止盈·无止损·不模拟爆仓, 浮亏照算权益归0即破产); ctrl对照=同信号+止盈价格+2%+真实爆仓线(价格-0.6%)',
    ],
    'results' => $results,
];
file_put_contents('E:/finally-main/web/chippeak_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "DATA OK " . round(memory_get_peak_usage(true) / 1048576) . "MB\n";
