<?php
/**
 * td9six_eth.php — ETH 单标的 六重共振(15m/1H/4H) 买入+加仓 一年回测生成器 (2026-09-27 用户指令)
 * 用户原话: "六重共振 eth合约 加仓每次1美金 每笔本金1美金买入 计算 盈利一年多少"
 * 六重共振 = 引擎 strategy/signals.go 同口径六指标(与 td9six_all.php 完全一致):
 *   triple_ma_bull / momentum_burst / adx_trend / fib_618 / macd_hist_rise / td_nine
 * 变体: six=6票全中(严格共振) · five=至少5票 · four=至少4票
 * 买入1U · 持仓中再遇信号 加仓+1U(均价/止盈重算, 不限轮数) · 10x(爆仓9.6%) · 止盈+2%价格 · 超时7天 · taker 0.05%
 * 双资金模式: refill续投(每轮固定再投1U, 累计盈亏) / strict严格(总本金1U池, 亏光停做)
 * 输出: web/td9sixeth_data.json
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 10; $MMR = 0.004; $FEE = 0.0005; $TP = 0.02; $U = 1.0;
$TMO_MS = 7 * 86400 * 1000;
$LIQ = 1.0 / $LEV - $MMR;
$BARS = ['15m' => '15m', '1h' => '1H', '4h' => '4H'];
$VARIANTS = ['six' => '六重共振(6票全中)', 'five' => '至少5票', 'four' => '至少4票'];
$CAP_CURVE = 200;

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
        if (is_nan($a[$i])) { if ($e !== null) $out[$i] = $e; continue; }
        $e = ($e === null) ? $a[$i] : $a[$i] * $k + $e * (1 - $k);
        $out[$i] = $e;
    }
    return $out;
}
function adxArr($h, $l, $c, $p = 14) {
    $n = count($c); $adx = array_fill(0, $n, NAN); $pdi = array_fill(0, $n, NAN); $mdi = array_fill(0, $n, NAN);
    $trS = 0.0; $pS = 0.0; $mS = 0.0; $dxs = []; $adxS = NAN;
    $prevH = null; $prevL = null; $prevC = null;
    for ($i = 0; $i < $n; $i++) {
        if ($prevC !== null) {
            $up = $h[$i] - $prevH; $dn = $prevL - $l[$i];
            $pDM = ($up > $dn && $up > 0) ? $up : 0.0;
            $mDM = ($dn > $up && $dn > 0) ? $dn : 0.0;
            $tr = max($h[$i] - $l[$i], max(abs($h[$i] - $prevC), abs($l[$i] - $prevC)));
            if ($i <= $p) { $trS += $tr; $pS += $pDM; $mS += $mDM; if ($i == $p && $trS > 0) { $pdi[$i] = 100 * $pS / $trS; $mdi[$i] = 100 * $mS / $trS; } }
            else {
                $trS = $trS - $trS / $p + $tr; $pS = $pS - $pS / $p + $pDM; $mS = $mS - $mS / $p + $mDM;
                if ($trS <= 0) continue;
                $pd = 100 * $pS / $trS; $md = 100 * $mS / $trS;
                $pdi[$i] = $pd; $mdi[$i] = $md;
                $dx = ($pd + $md) > 0 ? 100 * abs($pd - $md) / ($pd + $md) : 0;
                $dxs[] = $dx;
                if (count($dxs) == $p) { $adxS = array_sum($dxs) / $p; $adx[$i] = $adxS; }
                elseif (count($dxs) > $p) { $adxS = ($adxS * ($p - 1) + $dx) / $p; $adx[$i] = $adxS; }
            }
        }
        $prevH = $h[$i]; $prevL = $l[$i]; $prevC = $c[$i];
    }
    return [$adx, $pdi, $mdi];
}
function tdDownAt($c, $idx) {
    $cnt = 0;
    for ($i = $idx; $i >= 4; $i--) { if ($c[$i] < $c[$i - 4]) $cnt++; else break; }
    return $cnt;
}

function sim($ts, $o, $h, $l, $c, $sigOk, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, $withDetail, $refill) {
    global $CAP_CURVE;
    $n = count($c);
    $cash = $U; $pos = null; $bankrupt = false;
    $rounds = 0; $wins = 0; $pnlTotal = 0.0; $liqN = 0; $toN = 0; $addN = 0; $sigCnt = 0;
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
            else $exit = null;
            if ($exit !== null) {
                if ($withDetail) $trades[] = [$pos['t'], $ts[$i], $pos['adds'], $exit, round($pnl, 4), $why];
                $pnlTotal += $pnl; $rounds++; if ($why == 'LIQ') $liqN++; elseif ($why == 'TO') $toN++;
                if ($pnl > 0) $wins++;
                $curve[] = [$ts[$i], round($refill ? 1.0 + $pnlTotal : $cash, 4)];
                $pos = null;
                if (!$refill && $cash < $U && $why != 'TP') $bankrupt = true;
            }
        }
        if (!$bankrupt && $sigOk[$i]) {
            $sigCnt++;
            if (!$pos && ($refill || $cash >= $U)) {
                $net = $U * $LEV * (1 - $FEE);
                if (!$refill) $cash -= $U;
                $pos = ['t' => $ts[$i], 'q' => $net / $c[$i], 'u' => $U, 'net' => $net,
                        'avg' => $c[$i], 'tp' => $c[$i] * (1 + $TP), 'adds' => 0];
            } elseif ($pos && ($refill || $cash >= $U)) {
                $net = $U * $LEV * (1 - $FEE);
                if (!$refill) $cash -= $U;
                $pos['q'] += $net / $c[$i]; $pos['u'] += $U; $pos['net'] += $net;
                $pos['avg'] = $pos['net'] / $pos['q'];
                $pos['tp'] = $pos['avg'] * (1 + $TP);
                $pos['adds']++; $addN++;
            }
        }
    }
    if ($pos) {
        $exit = $c[$n - 1];
        $gross = $pos['q'] * $exit - $pos['net']; $feeOut = $pos['q'] * $exit * $FEE;
        if (!$refill) $cash += $pos['u'] + $gross - $feeOut; $pnl = $gross - $feeOut;
        if ($withDetail) $trades[] = [$pos['t'], $ts[$n - 1], $pos['adds'], $exit, round($pnl, 4), 'END'];
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
    $r = ['sigCnt' => $sigCnt, 'n' => $rounds, 'win' => $wins, 'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0,
          'pnl' => round($pnlTotal, 4), 'adds' => $addN, 'liq' => $liqN, 'to' => $toN,
          'bankrupt' => $bankrupt, 'cashEnd' => round($refill ? 1.0 + $pnlTotal : $cash, 4), 'maxdd' => round($maxdd, 4)];
    if ($withDetail) { $r['curve'] = $curve; $r['trades'] = $trades; }
    return $r;
}

$INST = 'eth';
$results = [];
foreach ($BARS as $bk => $okxBar) {
    $tbl = "kline_{$INST}_usdt_swap_{$bk}";
    $r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c` FROM `$tbl` WHERE candle_time >= $gT0 ORDER BY candle_time ASC");
    if (!$r) { echo "$tbl missing\n"; continue; }
    $ts = []; $o = []; $h = []; $l = []; $c = [];
    while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0 || (float)$x[1] <= 0) continue; $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }
    $r->free();
    $n = count($c);
    if ($n < 120) { echo "$tbl rows=$n too few\n"; continue; }

    // ---- 六指标(引擎同口径) ----
    $m7 = smaArr($c, 7); $m25 = smaArr($c, 25); $m99 = smaArr($c, 99);
    $e30 = emaArr($c, 30);
    list($adx, $pdi, $mdi) = adxArr($h, $l, $c);
    $e12 = emaArr($c, 12); $e26 = emaArr($c, 26);
    $dif = []; for ($i = 0; $i < $n; $i++) $dif[] = (is_nan($e12[$i]) || is_nan($e26[$i])) ? NAN : $e12[$i] - $e26[$i];
    $dea = emaArr($dif, 9);
    $hist = []; for ($i = 0; $i < $n; $i++) $hist[] = (is_nan($dif[$i]) || is_nan($dea[$i])) ? NAN : 2 * ($dif[$i] - $dea[$i]);

    $flg = [];
    foreach (['six', 'five', 'four'] as $vk) $flg[$vk] = array_fill(0, $n, false);
    $voteDist = array_fill(0, 7, 0);
    for ($i = 0; $i < $n; $i++) {
        $votes = 0;
        if (!is_nan($m99[$i]) && $m7[$i] > $m25[$i] && $m25[$i] > $m99[$i] && $c[$i] > $m7[$i]) $votes++;
        if ($i >= 12 && $c[$i - 12] > 0 && ($c[$i] / $c[$i - 12] - 1) * 100 > 2 && !is_nan($e30[$i]) && $c[$i] > $e30[$i]) $votes++;
        if (!is_nan($adx[$i]) && $adx[$i] > 25 && $pdi[$i] > $mdi[$i]) $votes++;
        if ($i >= 29) {
            $lo = INF; $hi = -INF;
            for ($k2 = $i - 29; $k2 <= $i; $k2++) { if ($c[$k2] < $lo) $lo = $c[$k2]; if ($c[$k2] > $hi) $hi = $c[$k2]; }
            if ($hi > $lo) {
                $fib = $hi - ($hi - $lo) * 0.618;
                if ($c[$i] >= $fib * 0.97 && $c[$i] <= $fib * 1.03 && $c[$i] > $o[$i]) $votes++;
            }
        }
        if ($i >= 3 && !is_nan($hist[$i]) && $hist[$i] > 0 && $hist[$i] > $hist[$i - 1] && $hist[$i - 1] > $hist[$i - 2] && !is_nan($hist[$i - 2]) && $hist[$i - 2] > $hist[$i - 3]) $votes++;
        if ($i >= 1 && tdDownAt($c, $i - 1) >= 9 && $c[$i] > $h[$i - 1]) $votes++;
        $voteDist[$votes]++;
        if ($votes >= 6) { $flg['six'][$i] = true; $flg['five'][$i] = true; $flg['four'][$i] = true; }
        elseif ($votes >= 5) { $flg['five'][$i] = true; $flg['four'][$i] = true; }
        elseif ($votes >= 4) { $flg['four'][$i] = true; }
    }

    $row = ['inst' => 'ETH-USDT-SWAP', 'bars' => $n, 'span' => [bj($ts[0]), bj($ts[$n - 1])],
            'days' => round(($ts[$n - 1] - $ts[0]) / 86400000.0, 1), 'voteDist' => $voteDist, 'variants' => []];
    foreach ($VARIANTS as $vk => $vn)
        $row['variants'][$vk] = ['refill' => sim($ts, $o, $h, $l, $c, $flg[$vk], $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, true, true),
                                 'strict' => sim($ts, $o, $h, $l, $c, $flg[$vk], $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, true, false)];
    $results[$bk] = $row;
    echo "$bk rows=$n sig6={$voteDist[6]} sig5={$voteDist[5]} sig4={$voteDist[4]}\n";
}

$out = ['generated' => date('Y-m-d H:i:s'),
        'params' => ['lev' => $LEV, 'tp' => $TP, 'u' => $U, 'fee' => $FEE, 'liq' => $LIQ, 'timeout_days' => 7, 'add_u' => 1.0],
        'variants' => $VARIANTS, 'span' => [bj($gT0), bj(time() * 1000)], 'results' => $results];
file_put_contents(__DIR__ . '/../web/td9sixeth_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "saved size=" . round(filesize(__DIR__ . '/../web/td9sixeth_data.json') / 1048576, 2) . "MB\n";
foreach ($results as $bk => $row) {
    echo "== ETH $bk ==\n";
    foreach ($row['variants'] as $vk => $modes) foreach ($modes as $mk => $v)
        printf("  %-5s %-7s sig=%-5d n=%-5d wr=%5.1f%% adds=%-4d liq=%-4d pnl=%+9.2f maxdd=%.2f\n",
            $vk, $mk, $v['sigCnt'], $v['n'], $v['wr'], $v['adds'], $v['liq'], $v['pnl'], $v['maxdd']);
}
