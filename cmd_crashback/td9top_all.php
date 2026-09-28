<?php
/**
 * td9top_all.php — 全市场 15m 顶序列九7~九9 买入 一年回测生成器 (2026-09-26 用户指令)
 * 用户原话: "所有合约 给我算一下 回测 15分钟 九9 上涨的时候 买入信号 涨幅2% 有加仓每次1美金
 *           本金每个合约1美金 是否盈利一年 回测数据明细php localhost出来 要明细和盈利曲线"
 * (后追加: 顶序列计数 7/8/9 都算买入信号)
 *
 * 口径:
 *   信号 = 15m 上涨(顶)序列 计数恰好 7/8/9 (连续N根收盘>第4根前收盘, 断即清零, 标准TD口径)
 *   空仓遇信号 → 开多 1U 保证金 @信号根收盘价; 持仓再遇信号 → 加仓 +1U
 *   止盈 = 持仓均价 +2%(价格) 市价全平; 不设止损; 爆仓 = 跌幅≥1/100-0.4%≈0.6% 保证金全亏
 *   超时7天平仓; 数据末尾强制平仓(END); 费用 = taker 0.05% × notional(保证金×100) 进出都收
 *   本金 = 每合约独立 1U 起始权益; 开仓需现金≥1.05U(1U保证金+手续费); 爆仓后现金<1.05 = 破产停做
 * 输出: web/td9topall_data.json
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$LEV = 100; $MMR = 0.004; $FEE = 0.0005; $TP = 0.02; $U = 1.0;
$TMO_MS = 7 * 86400 * 1000;
$LIQ = 1.0 / $LEV - $MMR; // 0.006
$CAP_TRADES = 500; $CAP_CURVE = 300;

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
foreach ($insts as $base) {
    $tbl = "kline_{$base}_usdt_swap_15m";
    $r = $DB->query("SELECT candle_time,`c`,`h`,`l`,`o` FROM `$tbl` WHERE candle_time >= $gT0 ORDER BY candle_time ASC");
    if (!$r) { $done++; continue; }
    $ts = []; $c = []; $h = []; $l = []; $o = [];
    while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $c[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $o[] = (float)$x[4]; }
    $r->free();
    $n = count($c);
    if ($n < 20) { $done++; continue; }

    $cash = $U; $pos = null; $bankrupt = false;
    $sigs = 0; $rounds = 0; $wins = 0; $pnlTotal = 0.0; $addN = 0; $liqN = 0; $toN = 0;
    $trades = []; $curve = []; $tdUp = 0;
    $span = [bj($ts[0]), bj($ts[$n - 1])];
    $days = ($ts[$n - 1] - $ts[0]) / 86400000.0;

    for ($i = 4; $i < $n; $i++) {
        $tdUp = ($c[$i] > $c[$i - 4]) ? $tdUp + 1 : 0;
        $sig = ($tdUp == 7 || $tdUp == 8 || $tdUp == 9);

        // ---- 1) 持仓管理: 爆仓 → 止盈 → 超时 ----
        if ($pos) {
            $liqPx = $pos['avg'] * (1 - $LIQ);
            if ($l[$i] <= $liqPx) {
                // 爆仓: 保证金全亏(手续费开仓时已扣)
                $pnl = -$pos['u'];
                $cash += 0;
                $trades[] = [$pos['t'], $ts[$i], $pos['ph'], $pos['adds'], 0.0, round($pnl, 4), 'LIQ'];
                $pnlTotal += $pnl; $rounds++; $liqN++;
                $curve[] = [$ts[$i], round($cash, 4)];
                $pos = null;
                if ($cash < $U) $bankrupt = true;
            } elseif ($h[$i] >= $pos['tp']) {
                $exit = max($o[$i], $pos['tp']); // 跳空按开盘价
                $q = $pos['q'];
                $gross = $q * $exit - $pos['net'];
                $feeOut = $q * $exit * $FEE;
                $cash += $pos['u'] + $gross - $feeOut;
                $pnl = $gross - $feeOut;
                $trades[] = [$pos['t'], $ts[$i], $pos['ph'], $pos['adds'], $exit, round($pnl, 4), 'TP'];
                $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;
                $curve[] = [$ts[$i], round($cash, 4)];
                $pos = null;
            } elseif ($ts[$i] - $pos['t'] >= $TMO_MS) {
                $exit = $c[$i];
                $gross = $pos['q'] * $exit - $pos['net'];   // 毛利 = 数量×(平仓-均价)
                $feeOut = $pos['q'] * $exit * $FEE;
                $cash += $pos['u'] + $gross - $feeOut;      // 回补保证金+净毛利
                $pnl = $gross - $feeOut;
                $trades[] = [$pos['t'], $ts[$i], $pos['ph'], $pos['adds'], $exit, round($pnl, 4), 'TO'];
                $pnlTotal += $pnl; $rounds++; $toN++; if ($pnl > 0) $wins++;
                $curve[] = [$ts[$i], round($cash, 4)];
                $pos = null;
                if ($cash < $U) $bankrupt = true;
            }
        }
        // ---- 2) 信号: 开仓 / 加仓 (手续费从仓位内扣: 1U保证金即可开仓, notional = 100×(1-费率)) ----
        if ($sig) {
            $sigs++;
            if (!$pos && !$bankrupt && $cash >= $U) {
                $net = $U * $LEV * (1 - $FEE); // 扣除开仓taker费后的实际入场名义
                $cash -= $U;
                $pos = ['t' => $ts[$i], 'ph' => $tdUp, 'q' => $net / $c[$i], 'u' => $U,
                        'net' => $net, 'avg' => $c[$i], 'tp' => $c[$i] * (1 + $TP), 'adds' => 0];
            } elseif ($pos && $cash >= $U) {
                $net = $U * $LEV * (1 - $FEE);
                $cash -= $U;
                $pos['q'] += $net / $c[$i];
                $pos['u'] += $U; $pos['net'] += $net;
                $pos['avg'] = $pos['net'] / $pos['q'];
                $pos['tp'] = $pos['avg'] * (1 + $TP);
                $pos['adds']++; $addN++;
            }
        }
    }
    // ---- 3) 数据末尾强制平仓 ----
    if ($pos) {
        $exit = $c[$n - 1];
        $gross = $pos['q'] * $exit - $pos['net'];
        $feeOut = $pos['q'] * $exit * $FEE;
        $cash += $pos['u'] + $gross - $feeOut;
        $pnl = $gross - $feeOut;
        $trades[] = [$pos['t'], $ts[$n - 1], $pos['ph'], $pos['adds'], $exit, round($pnl, 4), 'END'];
        $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;
        $curve[] = [$ts[$n - 1], round($cash, 4)];
        $pos = null;
    }

    // 曲线降采样
    if (count($curve) > $CAP_CURVE) {
        $step = ceil(count($curve) / $CAP_CURVE);
        $curve = array_filter($curve, function ($k) use ($step) { return $k % $step == 0; }, ARRAY_FILTER_USE_KEY);
        $curve = array_values($curve);
    }
    if (count($trades) > $CAP_TRADES) $trades = array_slice($trades, 0, $CAP_TRADES);

    // maxdd (现金曲线)
    $maxdd = 0.0; $peak = -INF;
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; }

    $results[] = [
        'inst' => strtoupper($base), 'days' => round($days, 1), 'bars' => $n, 'span' => $span,
        'sigs' => $sigs, 'n' => $rounds, 'win' => $wins,
        'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0,
        'pnl' => round($pnlTotal, 4), 'adds' => $addN, 'liq' => $liqN, 'to' => $toN,
        'bankrupt' => $bankrupt, 'cashEnd' => round($cash, 4), 'maxdd' => round($maxdd, 4),
        'curve' => $curve, 'trades' => $trades,
    ];
    $done++;
    if ($done % 40 == 0) echo "done $done / " . count($insts) . "\n";
}

usort($results, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; });

$tot = ['n' => 0, 'win' => 0, 'pnl' => 0, 'liq' => 0, 'adds' => 0, 'sigs' => 0,
        'bankrupt' => 0, 'insts' => 0, 'pos' => 0, 'roundsPos' => 0, 'winsPos' => 0];
foreach ($results as $r) {
    if ($r['n'] == 0) continue;
    $tot['insts']++; $tot['n'] += $r['n']; $tot['win'] += $r['win']; $tot['pnl'] += $r['pnl'];
    $tot['liq'] += $r['liq']; $tot['adds'] += $r['adds']; $tot['sigs'] += $r['sigs'];
    if ($r['bankrupt']) $tot['bankrupt']++;
    if ($r['pnl'] > 0) $tot['pos']++;
    $tot['roundsPos'] += $r['pnl'] > 0 ? $r['n'] : 0;
    $tot['winsPos'] += $r['win'];
}

// 组合盈利曲线: 所有平仓按时间累加 pnl
$all = [];
foreach ($results as $r) foreach ($r['trades'] as $t) $all[] = [$t[1], $t[5]];
usort($all, function ($a, $b) { return $a[0] <=> $b[0]; });
$comb = []; $acc = 0; $cnt = 0; $stepC = max(1, (int)floor(count($all) / 1500));
foreach ($all as $p) { $acc += $p[1]; if ($cnt++ % $stepC == 0) $comb[] = [$p[0], round($acc, 2)]; }
if (!$comb || end($comb)[0] != (count($all) ? $all[count($all) - 1][0] : 0)) { if ($all) $comb[] = [$all[count($all) - 1][0], round($acc, 2)]; }

$out = [
    'generated' => date('Y-m-d H:i:s'),
    'params' => ['lev' => $LEV, 'tp' => $TP, 'u' => $U, 'fee' => $FEE, 'liq' => $LIQ, 'timeout_days' => 7],
    'span' => [bj($gT0), bj(time() * 1000)],
    'total' => $tot, 'comb' => $comb, 'results' => $results,
];
file_put_contents(__DIR__ . '/../web/td9topall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "saved. insts=" . count($results) . " total_pnl={$tot['pnl']} rounds={$tot['n']}\n";
