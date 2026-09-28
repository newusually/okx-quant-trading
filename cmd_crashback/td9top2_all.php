<?php
/**
 * td9top2_all.php — 全市场 15m 九1~九9上涨买入×三均线加仓 10x 一年回测生成器 (2026-09-26 用户指令)
 * 用户原话: "改成10X 然后继续 算15分钟 九1-9 9个 买入信号 每笔1美金 持仓均价+2%(价格) 市价全平
 *           不设止损 爆仓线0.6%(按10x折算=1/10-维持0.4%≈9.6%) 超时7天平仓 taker 0.05% 进出
 *           算一下 如何才可以不爆仓 赚很多钱 胜率可以低点 加仓1美金每次 加仓按 triple_ma_bull 三均线多头排列(7>25>99) 15分钟"
 *
 * 口径:
 *   买入 = 15m 顶序列计数 1~9 全部(连续N根收盘>第4根前收盘, 上涨中, 断即清零) → 空仓即开多 1U
 *   加仓 = 持仓中 triple_ma_bull(15m: MA7>MA25>MA99 且收>MA7) 每根触发 +1U (四组对照: 不限/12轮/5轮/禁止)
 *   止盈 = 持仓均价 +2%(价格) 市价全平 | 不设止损 | 爆仓 = 跌幅≥1/10-0.4% = 9.6% | 超时7天 | 数据末尾平仓
 *   杠杆10x: 1U保证金=10U名义 | taker 0.05% 进出(开仓从仓位内扣) | 每合约独立1U本金, 现金<1U = 破产停做
 * 输出: web/td9top2all_data.json
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$LEV = 10; $MMR = 0.004; $FEE = 0.0005; $TP = 0.02; $U = 1.0;
$TMO_MS = 7 * 86400 * 1000;
$LIQ = 1.0 / $LEV - $MMR; // 0.096
$CAP_TRADES = 250; $CAP_CURVE = 120;
$VARIANTS = ['A' => '不限加仓', 'B' => '加仓≤12轮', 'C' => '加仓≤5轮', 'D' => '禁止加仓'];
$VCAP = ['A' => PHP_INT_MAX, 'B' => 12, 'C' => 5, 'D' => 0];

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }

$insts = [];
$j = json_decode(file_get_contents('E:/datas/insts_all.json'), true);
foreach ($j['insts'] as $s) if (preg_match('/^(.+)-USDT-SWAP$/', $s, $m2)) $insts[] = strtolower($m2[1]);
$insts = array_values(array_unique($insts)); sort($insts);
echo "insts=" . count($insts) . "\n";

$gT0 = (time() - 365 * 86400) * 1000;
$results = [];
$done = 0;

/** 单变模拟: 返回该合约该变体的回测结果 */
function sim($ts, $o, $h, $l, $c, $maOK, $addCap, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS) {
    global $CAP_TRADES, $CAP_CURVE, $VCAP;
    $n = count($c);
    $cash = $U; $pos = null; $bankrupt = false;
    $sigs = 0; $rounds = 0; $wins = 0; $pnlTotal = 0.0; $addN = 0; $liqN = 0; $toN = 0;
    $trades = []; $curve = [];
    $tdUp = 0;
    for ($i = 4; $i < $n; $i++) {
        $tdUp = ($c[$i] > $c[$i - 4]) ? $tdUp + 1 : 0;
        $entrySig = ($tdUp >= 1 && $tdUp <= 9);
        $maSig = $maOK ? $maOK[$i] : false;

        // ---- 1) 持仓管理: 爆仓 → 止盈 → 超时 ----
        if ($pos) {
            $liqPx = $pos['avg'] * (1 - $LIQ);
            if ($l[$i] <= $liqPx) {
                $cash += 0; $pnl = -$pos['u'];
                $trades[] = [$pos['t'], $ts[$i], $pos['adds'], 0.0, round($pnl, 4), 'LIQ'];
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
                $trades[] = [$pos['t'], $ts[$i], $pos['adds'], $exit, round($pnl, 4), 'TP'];
                $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;
                $curve[] = [$ts[$i], round($cash, 4)];
                $pos = null;
            } elseif ($ts[$i] - $pos['t'] >= $TMO_MS) {
                $exit = $c[$i];
                $gross = $pos['q'] * $exit - $pos['net'];
                $feeOut = $pos['q'] * $exit * $FEE;
                $cash += $pos['u'] + $gross - $feeOut;
                $pnl = $gross - $feeOut;
                $trades[] = [$pos['t'], $ts[$i], $pos['adds'], $exit, round($pnl, 4), 'TO'];
                $pnlTotal += $pnl; $rounds++; $toN++; if ($pnl > 0) $wins++;
                $curve[] = [$ts[$i], round($cash, 4)];
                $pos = null;
                if ($cash < $U) $bankrupt = true;
            }
        }
        // ---- 2) 开仓: 九1~九9 上涨信号 ----
        if (!$pos && !$bankrupt && $entrySig && $cash >= $U) {
            $sigs++;
            $net = $U * $LEV * (1 - $FEE);
            $cash -= $U;
            $pos = ['t' => $ts[$i], 'q' => $net / $c[$i], 'u' => $U, 'net' => $net,
                    'avg' => $c[$i], 'tp' => $c[$i] * (1 + $TP), 'adds' => 0];
        } elseif ($pos && $maSig && $pos['adds'] < $addCap && $cash >= $U) {
            // ---- 3) 加仓: 三均线多头排列 ----
            $sigs++;
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
        $trades[] = [$pos['t'], $ts[$n - 1], $pos['adds'], $exit, round($pnl, 4), 'END'];
        $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;
        $curve[] = [$ts[$n - 1], round($cash, 4)];
    }
    if (count($curve) > $CAP_CURVE) {
        $step = (int)ceil(count($curve) / $CAP_CURVE);
        $c2 = []; foreach ($curve as $k => $p) if ($k % $step == 0) $c2[] = $p;
        $curve = $c2;
    }
    if (count($trades) > $CAP_TRADES) $trades = array_slice($trades, 0, $CAP_TRADES);
    $maxdd = 0.0; $peak = -INF;
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; }
    return ['sigs' => $sigs, 'n' => $rounds, 'win' => $wins,
            'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0,
            'pnl' => round($pnlTotal, 4), 'adds' => $addN, 'liq' => $liqN, 'to' => $toN,
            'bankrupt' => $bankrupt, 'cashEnd' => round($cash, 4),
            'maxdd' => round($maxdd, 4), 'curve' => $curve, 'trades' => $trades];
}

foreach ($insts as $base) {
    $tbl = "kline_{$base}_usdt_swap_15m";
    $r = $DB->query("SELECT candle_time,`c`,`h`,`l`,`o` FROM `$tbl` WHERE candle_time >= $gT0 ORDER BY candle_time ASC");
    if (!$r) { $done++; continue; }
    $ts = []; $c = []; $h = []; $l = []; $o = [];
    while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $c[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $o[] = (float)$x[4]; }
    $r->free();
    $n = count($c);
    if ($n < 110) { $done++; continue; }

    // 三均线多头排列(与引擎 signals.go sigTripleMA 同口径: MA7>MA25>MA99 且 收>MA7)
    $maOK = array_fill(0, $n, false);
    $s7 = 0.0; $s25 = 0.0; $s99 = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $s7 += $c[$i]; $s25 += $c[$i]; $s99 += $c[$i];
        if ($i >= 7) $s7 -= $c[$i - 7];
        if ($i >= 25) $s25 -= $c[$i - 25];
        if ($i >= 99) $s99 -= $c[$i - 99];
        if ($i >= 99) {
            $m7 = $s7 / 7; $m25 = $s25 / 25; $m99 = $s99 / 99;
            $maOK[$i] = ($m7 > $m25 && $m25 > $m99 && $c[$i] > $m7);
        }
    }

    $row = ['inst' => strtoupper($base), 'days' => round(($ts[$n - 1] - $ts[0]) / 86400000.0, 1),
            'bars' => $n, 'span' => [bj($ts[0]), bj($ts[$n - 1])], 'variants' => []];
    foreach ($VCAP as $vk => $cap) {
        $v = sim($ts, $o, $h, $l, $c, $maOK, $cap, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS);
        if ($vk !== 'A') { unset($v['trades']); if (count($v['curve']) > 40) $v['curve'] = array_slice($v['curve'], 0, 40); }
        $row['variants'][$vk] = $v;
    }
    $results[] = $row;
    $done++;
    if ($done % 40 == 0) echo "done $done\n";
}

// 汇总
$tot = [];
foreach ($VCAP as $vk => $vn) {
    $t = ['n' => 0, 'win' => 0, 'pnl' => 0, 'liq' => 0, 'adds' => 0, 'bankrupt' => 0, 'insts' => 0, 'pos' => 0];
    foreach ($results as $r) { $v = $r['variants'][$vk]; if ($v['n'] == 0) continue;
        $t['insts']++; $t['n'] += $v['n']; $t['win'] += $v['win']; $t['pnl'] += $v['pnl'];
        $t['liq'] += $v['liq']; $t['adds'] += $v['adds'];
        if ($v['bankrupt']) $t['bankrupt']++;
        if ($v['pnl'] > 0) $t['pos']++; }
    $tot[$vk] = $t;
}

// 组合曲线(按变体): A 用逐笔平仓盈亏; B/C/D 用各合约 cash 曲线差分
$comb = [];
foreach ($VCAP as $vk => $vn) {
    $pts = [];
    if ($vk === 'A') {
        foreach ($results as $r) foreach ($r['variants']['A']['trades'] as $tr) $pts[] = [$tr[1], $tr[4]];
    } else {
        foreach ($results as $r) {
            $prev = 1.0;
            foreach ($r['variants'][$vk]['curve'] as $p) { $pts[] = [$p[0], $p[1] - $prev]; $prev = $p[1]; }
        }
    }
    usort($pts, function ($a, $b) { return $a[0] <=> $b[0]; });
    $acc = 0; $out = []; $cnt = 0;
    $stepC = max(1, (int)floor(count($pts) / 1200));
    foreach ($pts as $p) { $acc += $p[1]; if ($cnt++ % $stepC == 0) $out[] = [$p[0], round($acc, 2)]; }
    if ($pts) $out[] = [$pts[count($pts) - 1][0], round($acc, 2)];
    $comb[$vk] = $out;
}

$out = ['generated' => date('Y-m-d H:i:s'),
        'params' => ['lev' => $LEV, 'tp' => $TP, 'u' => $U, 'fee' => $FEE, 'liq' => $LIQ, 'timeout_days' => 7],
        'variants' => $VARIANTS, 'span' => [bj($gT0), bj(time() * 1000)],
        'total' => $tot, 'comb' => $comb, 'results' => $results];
file_put_contents(__DIR__ . '/../web/td9top2all_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "saved size=" . round(filesize(__DIR__ . '/../web/td9top2all_data.json') / 1048576, 1) . "MB\n";
foreach ($VCAP as $vk => $vn) echo "$vk $vn: pnl={$tot[$vk]['pnl']} n={$tot[$vk]['n']} liq={$tot[$vk]['liq']} pos_insts={$tot[$vk]['pos']}\n";
