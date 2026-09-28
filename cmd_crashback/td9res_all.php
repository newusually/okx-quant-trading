<?php
/**
 * td9res_all.php — ETH/BTC 买九5/6/8 × 六指标共振 一年回测生成器 (2026-09-27 用户指令)
 * 用户原话: "搞一下共振，15分钟，1H，4H,买九5/六/8 为正的方向 然后+cci +rsi+macd +taker +kdj+adx
 *           结合共振一下 一年 eth btc合约 算一下 盈利多少 回测一年"
 *
 * 买入 = 顶序列计数恰好 ∈ {5,6,8}(连续N根收盘>第4根前收盘, 上涨中, 前测1H为正的方向) — 基线
 * 六指标(同周期K线算, 各一票):
 *   CCI(14)>100 强势 | RSI(14) 55~75 多头未超买 | MACD hist>0 | Taker放量 vol>SMA20(vol)
 *   KDJ(9,3,3) K>D 且 K<90 | ADX(14)>20 有趋势
 * 变体: base=九5/6/8裸信号 · 六指标各自单独过滤 · vote2/vote3/vote4=至少N票共振
 * 10x(爆仓线≈9.6%) | 止盈+2%价格市价全平 | 无加仓 | 超时7天 | taker 0.05% | 每合约独立1U本金
 * 输出: web/td9resall_data.json
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 10; $MMR = 0.004; $FEE = 0.0005; $TP = 0.02; $U = 1.0;
$TMO_MS = 7 * 86400 * 1000;
$LIQ = 1.0 / $LEV - $MMR;
$BARS = ['15m' => '15m', '1h' => '1H', '4h' => '4H'];
$INSTS = ['eth' => 'ETH', 'btc' => 'BTC'];
$KS = [5, 6, 8];
$VARIANTS = [
    'base'  => '九5/6/8裸信号(无过滤)',
    'cci'   => '九+CCI>100',
    'rsi'   => '九+RSI55~75',
    'macd'  => '九+MACD柱>0',
    'taker' => '九+Taker放量',
    'kdj'   => '九+KDJ多头',
    'adx'   => '九+ADX>20',
    'vote2' => '九+至少2票共振',
    'vote3' => '九+至少3票共振',
    'vote4' => '九+至少4票共振',
];
$CAP_CURVE = 100;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }

$gT0 = (time() - 365 * 86400) * 1000;

function smaArr($a, $p) {
    $n = count($a); $out = array_fill(0, $n, NAN); $s = 0.0;
    for ($i = 0; $i < $n; $i++) { $s += $a[$i]; if ($i >= $p) $s -= $a[$i - $p]; if ($i >= $p - 1) $out[$i] = $s / $p; }
    return $out;
}
function emaArr($a, $p) {
    $n = count($a); $out = array_fill(0, $n, NAN); $k = 2.0 / ($p + 1); $e = null;
    for ($i = 0; $i < $n; $i++) {
        if (is_nan($a[$i])) { if ($e !== null) $out[$i] = $e; continue; } // 跳过NAN不污染种子
        $e = ($e === null) ? $a[$i] : $a[$i] * $k + $e * (1 - $k);
        $out[$i] = $e;
    }
    return $out;
}
// Wilder ADX(14)
function adxArr($h, $l, $c, $p = 14) {
    $n = count($c); $trS = 0.0; $pS = 0.0; $mS = 0.0; $adx = array_fill(0, $n, NAN);
    $trS2 = 0.0; $pS2 = 0.0; $mS2 = 0.0; $dxs = [];
    $prevH = null; $prevL = null; $prevC = null;
    foreach (range(0, $n - 1) as $i) {
        if ($prevC !== null) {
            $up = $h[$i] - $prevH; $dn = $prevL - $l[$i];
            $pDM = ($up > $dn && $up > 0) ? $up : 0.0;
            $mDM = ($dn > $up && $dn > 0) ? $dn : 0.0;
            $tr = max($h[$i] - $l[$i], max(abs($h[$i] - $prevC), abs($l[$i] - $prevC)));
            if ($i <= $p) { $trS += $tr; $pS += $pDM; $mS += $mDM; }
            else {
                $trS = $trS - $trS / $p + $tr; $pS = $pS - $pS / $p + $pDM; $mS = $mS - $mS / $p + $mDM;
                $pDI = $trS > 0 ? 100 * $pS / $trS : 0; $mDI = $trS > 0 ? 100 * $mS / $trS : 0;
                $dx = ($pDI + $mDI) > 0 ? 100 * abs($pDI - $mDI) / ($pDI + $mDI) : 0;
                $dxs[] = $dx;
                if (count($dxs) == $p) { $adx[$i] = array_sum($dxs) / $p; $trS2 = $adx[$i]; }
                elseif (count($dxs) > $p) { $trS2 = ($trS2 * ($p - 1) + $dx) / $p; $adx[$i] = $trS2; }
            }
        }
        $prevH = $h[$i]; $prevL = $l[$i]; $prevC = $c[$i];
    }
    return $adx;
}
// KDJ(9,3,3) 返回 [K[], D[]]
function kdjArr($h, $l, $c, $p = 9) {
    $n = count($c); $K = array_fill(0, $n, NAN); $D = array_fill(0, $n, NAN);
    $k = 50.0; $d = 50.0;
    for ($i = 0; $i < $n; $i++) {
        if ($i >= $p - 1) {
            $hh = max(array_slice($h, $i - $p + 1, $p)); $ll = min(array_slice($l, $i - $p + 1, $p));
            $rsv = ($hh > $ll) ? 100 * ($c[$i] - $ll) / ($hh - $ll) : 50;
            $k = 2.0 / 3 * $k + 1.0 / 3 * $rsv; $d = 2.0 / 3 * $d + 1.0 / 3 * $k;
            $K[$i] = $k; $D[$i] = $d;
        }
    }
    return [$K, $D];
}

function sim($ts, $o, $h, $l, $c, $sigOk, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, $withDetail, $refill = false) {
    global $CAP, $CAP_CURVE;
    $n = count($c);
    $cash = $U; $pos = null; $bankrupt = false;
    $rounds = 0; $wins = 0; $pnlTotal = 0.0; $liqN = 0; $toN = 0; $sigCnt = 0;
    $trades = []; $curve = [];
    for ($i = 0; $i < $n; $i++) {
        if ($pos) {
            $liqPx = $pos['avg'] * (1 - $LIQ);
            if ($l[$i] <= $liqPx) { if (!$refill) $cash += 0; $pnl = -$pos['u']; $why = 'LIQ'; $exit = $liqPx; }
            elseif ($h[$i] >= $pos['tp']) { $exit = max($o[$i], $pos['tp']); $why = 'TP';
                $gross = $pos['q'] * $exit - $pos['net']; $feeOut = $pos['q'] * $exit * $FEE;
                if (!$refill) $cash += $pos['u'] + $gross - $feeOut; $pnl = $gross - $feeOut; }
            elseif ($ts[$i] - $pos['t'] >= $TMO_MS) { $exit = $c[$i]; $why = 'TO';
                $gross = $pos['q'] * $exit - $pos['net']; $feeOut = $pos['q'] * $exit * $FEE;
                if (!$refill) $cash += $pos['u'] + $gross - $feeOut; $pnl = $gross - $feeOut; }
            else { $exit = null; }
            if ($exit !== null) {
                if ($withDetail) $trades[] = [$pos['t'], $ts[$i], $exit, round($pnl, 4), $why];
                $pnlTotal += $pnl; $rounds++; if ($why == 'LIQ') $liqN++; elseif ($why == 'TO') $toN++;
                if ($pnl > 0) $wins++;
                $curve[] = [$ts[$i], round($refill ? 1.0 + $pnlTotal : $cash, 4)];
                $pos = null;
                if (!$refill && $cash < $U && $why != 'TP') $bankrupt = true;
            }
        }
        if (!$pos && !$bankrupt && $sigOk[$i] && ($refill || $cash >= $U)) {
            $net = $U * $LEV * (1 - $FEE);
            if (!$refill) $cash -= $U; $sigCnt++;
            $pos = ['t' => $ts[$i], 'q' => $net / $c[$i], 'u' => $U, 'net' => $net,
                    'avg' => $c[$i], 'tp' => $c[$i] * (1 + $TP)];
        }
    }
    if ($pos) {
        $exit = $c[$n - 1];
        $gross = $pos['q'] * $exit - $pos['net']; $feeOut = $pos['q'] * $exit * $FEE;
        if (!$refill) $cash += $pos['u'] + $gross - $feeOut; $pnl = $gross - $feeOut;
        if ($withDetail) $trades[] = [$pos['t'], $ts[$n - 1], $exit, round($pnl, 4), 'END'];
        $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;
        $curve[] = [$ts[$n - 1], round($refill ? 1.0 + $pnlTotal : $cash, 4)];
    }
    if (count($curve) > $CAP_CURVE) {
        $step = (int)ceil(count($curve) / $CAP_CURVE);
        $c2 = []; foreach ($curve as $k2 => $p) if ($k2 % $step == 0) $c2[] = $p;
        $curve = $c2;
    }
    $maxdd = 0.0; $peak = -INF;
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; }
    $r = ['n' => $rounds, 'win' => $wins, 'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0,
          'pnl' => round($pnlTotal, 4), 'sig' => $sigCnt, 'liq' => $liqN, 'to' => $toN,
          'bankrupt' => $bankrupt, 'cashEnd' => round($cash, 4), 'maxdd' => round($maxdd, 4)];
    if ($withDetail) { $r['curve'] = $curve; if (count($trades) > 200) $trades = array_slice($trades, 0, 200); $r['trades'] = $trades; }
    return $r;
}

$results = [];
foreach ($INSTS as $ik => $iup) {
    foreach ($BARS as $bk => $okxBar) {
        $tbl = "kline_{$ik}_usdt_swap_{$bk}";
        $r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM `$tbl` WHERE candle_time >= $gT0 ORDER BY candle_time ASC");
        if (!$r) { echo "no table $tbl\n"; continue; }
        $ts = []; $o = []; $h = []; $l = []; $c = []; $v = [];
        while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0 || (float)$x[1] <= 0) continue; $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; }
        $r->free();
        $n = count($c);
        if ($n < 120) { echo "too few $tbl=$n\n"; continue; }

        // ---- 指标 ----
        $cci = array_fill(0, $n, NAN);
        $P = 14; $tpS = 0.0; $mfS = 0.0; $q = [];
        for ($i = 0; $i < $n; $i++) {
            $tp = ($h[$i] + $l[$i] + $c[$i]) / 3.0; $q[] = $tp;
            $tpS += $tp; $mfS += abs($tp - ($i ? $q[$i - 1] : $tp));
            if ($i >= $P && $i - $P - 1 >= 0) { $tpS -= $q[$i - $P]; $mfS -= abs($q[$i - $P] - $q[$i - $P - 1]); }
            if ($i >= $P - 1 && $mfS > 0) $cci[$i] = ($tpS / $P - $tp) / (0.015 * $mfS / $P);
        }
        // RSI(14) Wilder
        $rsi = array_fill(0, $n, NAN); $ag = 0.0; $al = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $ch = $c[$i] - $c[$i - 1]; $g = max($ch, 0.0); $ls = max(-$ch, 0.0);
            if ($i <= $P) { $ag += $g; $al += $ls; if ($i == $P) { $ag /= $P; $al /= $P; $rsi[$i] = $al == 0 ? 100 : 100 - 100 / (1 + $ag / $al); } }
            else { $ag = ($ag * ($P - 1) + $g) / $P; $al = ($al * ($P - 1) + $ls) / $P; $rsi[$i] = $al == 0 ? 100 : 100 - 100 / (1 + $ag / $al); }
        }
        $e12 = emaArr($c, 12); $e26 = emaArr($c, 26);
        $dif = []; for ($i = 0; $i < $n; $i++) $dif[] = (is_nan($e12[$i]) || is_nan($e26[$i])) ? NAN : $e12[$i] - $e26[$i];
        $dea = emaArr($dif, 9);
        $hist = []; for ($i = 0; $i < $n; $i++) $hist[] = (is_nan($dif[$i]) || is_nan($dea[$i])) ? NAN : 2 * ($dif[$i] - $dea[$i]);
        $vma = smaArr($v, 20);
        list($kdjK, $kdjD) = kdjArr($h, $l, $c);
        $adx = adxArr($h, $l, $c);

        // ---- 信号 ----
        $tdUp = 0;
        $nine = array_fill(0, $n, false);
        $flg = ['cci' => array_fill(0, $n, false), 'rsi' => array_fill(0, $n, false), 'macd' => array_fill(0, $n, false),
                'taker' => array_fill(0, $n, false), 'kdj' => array_fill(0, $n, false), 'adx' => array_fill(0, $n, false)];
        for ($i = 0; $i < $n; $i++) {
            $tdUp = ($c[$i] > ($i >= 4 ? $c[$i - 4] : INF)) ? $tdUp + 1 : 0;
            $nine[$i] = in_array($tdUp, $KS, true);
            if (!is_nan($cci[$i])) $flg['cci'][$i] = $cci[$i] > 100;
            if (!is_nan($rsi[$i])) $flg['rsi'][$i] = $rsi[$i] > 55 && $rsi[$i] < 75;
            if (!is_nan($hist[$i])) $flg['macd'][$i] = $hist[$i] > 0;
            if (!is_nan($vma[$i]) && $vma[$i] > 0) $flg['taker'][$i] = $v[$i] > $vma[$i];
            if (!is_nan($kdjK[$i])) $flg['kdj'][$i] = $kdjK[$i] > $kdjD[$i] && $kdjK[$i] < 90;
            if (!is_nan($adx[$i])) $flg['adx'][$i] = $adx[$i] > 20;
        }
        $row = ['inst' => $iup, 'bar' => strtoupper($bk), 'days' => round(($ts[$n - 1] - $ts[0]) / 86400000.0, 1),
                'bars' => $n, 'span' => [bj($ts[0]), bj($ts[$n - 1])], 'variants' => []];
        foreach ($VARIANTS as $vk => $vn) {
            $sigOk = array_fill(0, $n, false);
            for ($i = 0; $i < $n; $i++) {
                if (!$nine[$i]) continue;
                if ($vk == 'base') { $sigOk[$i] = true; continue; }
                if (isset($flg[$vk])) { $sigOk[$i] = $flg[$vk][$i]; continue; }
                $need = (int)substr($vk, 4);
                $votes = 0; foreach ($flg as $f) if ($f[$i]) $votes++;
                $sigOk[$i] = $votes >= $need;
            }
            $row['variants'][$vk] = sim($ts, $o, $h, $l, $c, $sigOk, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, true);
            $row['refill'][$vk] = sim($ts, $o, $h, $l, $c, $sigOk, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, false, true);
        }
        $results[$ik][$bk] = $row;
        echo "$iup $bk done n=$n\n";
    }
}

$out = ['generated' => date('Y-m-d H:i:s'),
        'params' => ['lev' => $LEV, 'tp' => $TP, 'u' => $U, 'fee' => $FEE, 'liq' => $LIQ, 'timeout_days' => 7, 'ks' => $KS],
        'variants' => $VARIANTS, 'span' => [bj($gT0), bj(time() * 1000)], 'results' => $results];
file_put_contents(__DIR__ . '/../web/td9resall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "saved size=" . round(filesize(__DIR__ . '/../web/td9resall_data.json') / 1048576, 2) . "MB\n";
foreach ($results as $ik => $bars) foreach ($bars as $bk => $row) {
    echo "== $iup $bk ==\n";
    foreach ($row['variants'] as $vk => $vv) printf("  %-6s %s: n=%d wr=%.1f%% liq=%d pnl=%+.1f\n", $vk, $VARIANTS[$vk], $vv['n'], $vv['wr'], $vv['liq'], $vv['pnl']);
}
