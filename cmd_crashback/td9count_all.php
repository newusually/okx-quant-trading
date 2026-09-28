<?php
/**
 * td9count_all.php — 全市场 1H/4H 九1~九9 逐计数选优 一年回测生成器 (2026-09-26 用户指令)
 * 用户原话: "1H 4H 给我测试 九1~九9 计算 哪种算买入信号 可以盈利一年 赚很多
 *           加仓+1U 按 triple_ma_bull 三均线多头排列(MA7>25>99 且收>MA7) · 超时7天 · taker 0.05% 进出 · 每合约独立1U本金"
 *
 * 口径(与 td9top2_all.php 10x 版一致):
 *   买入 = 15m→改 1H/4H 顶序列计数 恰好=k (k=1..9 逐个测, 连续N根收盘>第4根前收盘, 上涨中, 断即清零)
 *   加仓 = 持仓中 triple_ma_bull(MA7>MA25>MA99 且收>MA7) 每根触发 +1U (现金允许时, 不限轮)
 *   10x(爆仓线 1/10-0.4%=9.6%) | 止盈 = 均价+2%(价格) 市价全平 | 不设止损 | 超时7天 | 数据末尾平仓
 *   taker 0.05% 进出(开仓从仓位内扣) | 每合约独立1U本金, 现金<1U 破产停做
 * 输出: web/td9countall_data.json (每 bar 只存最优计数的 trades/curve 供下钻)
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$LEV = 10; $MMR = 0.004; $FEE = 0.0005; $TP = 0.02; $U = 1.0;
$TMO_MS = 7 * 86400 * 1000;
$LIQ = 1.0 / $LEV - $MMR;
$BARS = ['1h' => '1H', '4h' => '4H'];
$CAP = 200; $CAP_CURVE = 120;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }

$insts = [];
$j = json_decode(file_get_contents('E:/datas/insts_all.json'), true);
foreach ($j['insts'] as $s) if (preg_match('/^(.+)-USDT-SWAP$/', $s, $m2)) $insts[] = strtolower($m2[1]);
$insts = array_values(array_unique($insts)); sort($insts);
echo "insts=" . count($insts) . "\n";

$gT0 = (time() - 365 * 86400) * 1000;

function sim($ts, $o, $h, $l, $c, $maOK, $K, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, $withDetail) {
    global $CAP, $CAP_CURVE;
    $n = count($c);
    $cash = $U; $pos = null; $bankrupt = false;
    $rounds = 0; $wins = 0; $pnlTotal = 0.0; $addN = 0; $liqN = 0; $toN = 0;
    $trades = []; $curve = []; $tdUp = 0;
    for ($i = 4; $i < $n; $i++) {
        $tdUp = ($c[$i] > $c[$i - 4]) ? $tdUp + 1 : 0;
        $entrySig = ($tdUp == $K);
        if ($pos) {
            $liqPx = $pos['avg'] * (1 - $LIQ);
            if ($l[$i] <= $liqPx) {
                $cash += 0; $pnl = -$pos['u'];
                if ($withDetail) $trades[] = [$pos['t'], $ts[$i], $pos['adds'], 0.0, round($pnl, 4), 'LIQ'];
                $pnlTotal += $pnl; $rounds++; $liqN++;
                $curve[] = [$ts[$i], round($cash, 4)];
                $pos = null;
                if ($cash < $U) $bankrupt = true;
            } elseif ($h[$i] >= $pos['tp']) {
                $exit = max($o[$i], $pos['tp']);
                $gross = $pos['q'] * $exit - $pos['net'];
                $feeOut = $pos['q'] * $exit * $FEE;
                $cash += $pos['u'] + $gross - $feeOut;
                $pnl = $gross - $feeOut;
                if ($withDetail) $trades[] = [$pos['t'], $ts[$i], $pos['adds'], $exit, round($pnl, 4), 'TP'];
                $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;
                $curve[] = [$ts[$i], round($cash, 4)];
                $pos = null;
            } elseif ($ts[$i] - $pos['t'] >= $TMO_MS) {
                $exit = $c[$i];
                $gross = $pos['q'] * $exit - $pos['net'];
                $feeOut = $pos['q'] * $exit * $FEE;
                $cash += $pos['u'] + $gross - $feeOut;
                $pnl = $gross - $feeOut;
                if ($withDetail) $trades[] = [$pos['t'], $ts[$i], $pos['adds'], $exit, round($pnl, 4), 'TO'];
                $pnlTotal += $pnl; $rounds++; $toN++; if ($pnl > 0) $wins++;
                $curve[] = [$ts[$i], round($cash, 4)];
                $pos = null;
                if ($cash < $U) $bankrupt = true;
            }
        }
        if (!$pos && !$bankrupt && $entrySig && $cash >= $U) {
            $net = $U * $LEV * (1 - $FEE);
            $cash -= $U;
            $pos = ['t' => $ts[$i], 'q' => $net / $c[$i], 'u' => $U, 'net' => $net,
                    'avg' => $c[$i], 'tp' => $c[$i] * (1 + $TP), 'adds' => 0];
        } elseif ($pos && $maOK[$i] && $cash >= $U) {
            $net = $U * $LEV * (1 - $FEE);
            $cash -= $U;
            $pos['q'] += $net / $c[$i];
            $pos['u'] += $U; $pos['net'] += $net;
            $pos['avg'] = $pos['net'] / $pos['q'];
            $pos['tp'] = $pos['avg'] * (1 + $TP);
            $pos['adds']++; $addN++;
        }
    }
    if ($pos) {
        $exit = $c[$n - 1];
        $gross = $pos['q'] * $exit - $pos['net'];
        $feeOut = $pos['q'] * $exit * $FEE;
        $cash += $pos['u'] + $gross - $feeOut;
        $pnl = $gross - $feeOut;
        if ($withDetail) $trades[] = [$pos['t'], $ts[$n - 1], $pos['adds'], $exit, round($pnl, 4), 'END'];
        $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;
        $curve[] = [$ts[$n - 1], round($cash, 4)];
    }
    if (count($curve) > $CAP_CURVE) {
        $step = (int)ceil(count($curve) / $CAP_CURVE);
        $c2 = []; foreach ($curve as $k2 => $p) if ($k2 % $step == 0) $c2[] = $p;
        $curve = $c2;
    }
    $maxdd = 0.0; $peak = -INF;
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; }
    $r = ['n' => $rounds, 'win' => $wins, 'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0,
          'pnl' => round($pnlTotal, 4), 'adds' => $addN, 'liq' => $liqN, 'to' => $toN,
          'bankrupt' => $bankrupt, 'cashEnd' => round($cash, 4), 'maxdd' => round($maxdd, 4)];
    if ($withDetail) { $r['curve'] = $curve; if (count($trades) > $CAP) $trades = array_slice($trades, 0, $CAP); $r['trades'] = $trades; }
    return $r;
}

$results = []; $matrix = [];
foreach ($BARS as $bk => $okxBar) {
    $resBar = []; $done = 0;
    foreach ($insts as $base) {
        $tbl = "kline_{$base}_usdt_swap_$bk";
        $r = $DB->query("SELECT candle_time,`c`,`h`,`l`,`o` FROM `$tbl` WHERE candle_time >= $gT0 ORDER BY candle_time ASC");
        if (!$r) { $done++; continue; }
        $ts = []; $c = []; $h = []; $l = []; $o = [];
        while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $c[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $o[] = (float)$x[4]; }
        $r->free();
        $n = count($c);
        if ($n < 110) { $done++; continue; }

        $maOK = array_fill(0, $n, false);
        $s7 = 0.0; $s25 = 0.0; $s99 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $s7 += $c[$i]; $s25 += $c[$i]; $s99 += $c[$i];
            if ($i >= 7) $s7 -= $c[$i - 7];
            if ($i >= 25) $s25 -= $c[$i - 25];
            if ($i >= 99) $s99 -= $c[$i - 99];
            if ($i >= 99) { $m7 = $s7 / 7; $m25 = $s25 / 25; $m99 = $s99 / 99;
                $maOK[$i] = ($m7 > $m25 && $m25 > $m99 && $c[$i] > $m7); }
        }
        $row = ['inst' => strtoupper($base), 'days' => round(($ts[$n - 1] - $ts[0]) / 86400000.0, 1),
                'bars' => $n, 'span' => [bj($ts[0]), bj($ts[$n - 1])], 'counts' => []];
        foreach (range(1, 9) as $K) $row['counts'][$K] = sim($ts, $o, $h, $l, $c, $maOK, $K, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, false);
        $resBar[] = $row;
        $done++;
        if ($done % 100 == 0) echo "$bk done $done\n";
    }
    // 汇总矩阵 + 找最优计数
    $matrix[$bk] = [];
    foreach (range(1, 9) as $K) {
        $t = ['n' => 0, 'win' => 0, 'pnl' => 0, 'liq' => 0, 'adds' => 0, 'bankrupt' => 0, 'insts' => 0, 'pos' => 0];
        foreach ($resBar as $row) { $v = $row['counts'][$K]; if ($v['n'] == 0) continue;
            $t['insts']++; $t['n'] += $v['n']; $t['win'] += $v['win']; $t['pnl'] += $v['pnl'];
            $t['liq'] += $v['liq']; $t['adds'] += $v['adds'];
            if ($v['bankrupt']) $t['bankrupt']++;
            if ($v['pnl'] > 0) $t['pos']++; }
        $matrix[$bk][$K] = $t;
    }
    $bestK = 1; $bestP = -INF;
    foreach ($matrix[$bk] as $K => $t) if ($t['pnl'] > $bestP) { $bestP = $t['pnl']; $bestK = $K; }
    // 只为最优计数补 trades/curve
    foreach ($resBar as $idx => $row) {
        $tbl = "kline_" . strtolower(str_replace('-', '_', $row['inst'] === strtoupper($row['inst']) ? $row['inst'] : $row['inst'])) ;
        // 重新读取并重跑最优计数(带明细)
        $base = strtolower(str_replace('-USDT-SWAP', '', $row['inst']));
        $r = $DB->query("SELECT candle_time,`c`,`h`,`l`,`o` FROM kline_{$base}_usdt_swap_$bk WHERE candle_time >= $gT0 ORDER BY candle_time ASC");
        $ts = []; $c = []; $h = []; $l = []; $o = [];
        while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $c[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $o[] = (float)$x[4]; }
        $r->free();
        $n = count($c);
        $maOK = array_fill(0, $n, false);
        $s7 = 0.0; $s25 = 0.0; $s99 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $s7 += $c[$i]; $s25 += $c[$i]; $s99 += $c[$i];
            if ($i >= 7) $s7 -= $c[$i - 7];
            if ($i >= 25) $s25 -= $c[$i - 25];
            if ($i >= 99) $s99 -= $c[$i - 99];
            if ($i >= 99) { $m7 = $s7 / 7; $m25 = $s25 / 25; $m99 = $s99 / 99;
                $maOK[$i] = ($m7 > $m25 && $m25 > $m99 && $c[$i] > $m7); }
        }
        $det = sim($ts, $o, $h, $l, $c, $maOK, $bestK, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, true);
        $resBar[$idx]['counts'][$bestK] = $det;
    }
    $results[$bk] = $resBar;
    echo "$bk best=九$bestK pnl=$bestP\n";
}

$out = ['generated' => date('Y-m-d H:i:s'),
        'params' => ['lev' => $LEV, 'tp' => $TP, 'u' => $U, 'fee' => $FEE, 'liq' => $LIQ, 'timeout_days' => 7],
        'span' => [bj($gT0), bj(time() * 1000)],
        'matrix' => $matrix, 'bestK' => [], 'results' => $results];
foreach ($BARS as $bk => $_) { $bk2 = $bk; $bp = -INF; foreach ($matrix[$bk] as $K => $t) if ($t['pnl'] > $bp) { $bp = $t['pnl']; $bk2 = $K; } $out['bestK'][$bk] = $bk2; }
file_put_contents(__DIR__ . '/../web/td9countall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "saved size=" . round(filesize(__DIR__ . '/../web/td9countall_data.json') / 1048576, 1) . "MB\n";
foreach ($BARS as $bk => $_) { echo "== $bk ==\n"; foreach ($matrix[$bk] as $K => $t) printf("  九%d: pnl=%.1f n=%d wr=%.1f%% liq=%d 盈利合约=%d\n", $K, $t['pnl'], $t['n'], $t['n'] ? round($t['win'] / $t['n'] * 100, 1) : 0, $t['liq'], $t['pos']); }
