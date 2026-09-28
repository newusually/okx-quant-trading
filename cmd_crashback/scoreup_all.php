<?php
/**
 * scoreup_all.php — 全数字币合约 15m Score递增买入回测 (2026-09-27)
 * 规则: 15m K线, MACD(12,26,60) Score公式(同 klineall.php 火箭, 满分8, 只记 Score>=2 事件)
 *       信号 = 当前Score事件的分数 > 前一个Score事件的分数 → 买入
 *       止盈 价格+0.5% · 不设止损 · 爆仓线-0.6%(100x) · 超时7天平仓 · taker 0.05%双边
 * 口径: 每笔本金1U × 100x杠杆(名义100U) · 同票同时只持一仓 · 回测区间=最近30天
 * 输出: web/scoreup_data.json → 报告页 web/scoreupall.php
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$DB = new mysqli('127.0.0.1', 'root', '', 'trading');
$DB->set_charset('utf8mb4');

$LEV = 20; $FEE = 0.0005; $U = 1.0; $TP = 0.005; $MMR = 0.004;
$TMO_MS = 7 * 86400 * 1000;
$NOW_MS = (int)(microtime(true) * 1000);
$WIN_MS = $NOW_MS - 30 * 86400 * 1000;          // 最近30天
$LOAD_MS = $NOW_MS - 120 * 86400 * 1000;        // 加载120天供MACD预热

function emaRaw($a, $p) {
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = 0.0;
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; }
    return $out;
}

$rs = $DB->query("SHOW TABLES LIKE 'kline\\_%\\_15m'");
$tables = [];
while ($r = $rs->fetch_row()) $tables[] = $r[0];
$rs->free();
sort($tables);

$trades = []; $perInst = [];
$nTab = 0;
foreach ($tables as $tb) {
    $inst = strtoupper(preg_replace('/^kline_(.+)_15m$/', '$1', $tb));
    $inst = str_replace('_', '-', $inst);
    $r = $DB->query("SELECT candle_time,`h`,`l`,`c`,vol FROM $tb WHERE candle_time >= $LOAD_MS ORDER BY candle_time ASC");
    if (!$r) continue;
    $t = []; $h = []; $l = []; $c = []; $v = [];
    while ($x = $r->fetch_row()) { if ((float)$x[3] <= 0) continue; $t[] = (int)($x[0] / 1000); $h[] = (float)$x[1]; $l[] = (float)$x[2]; $c[] = (float)$x[3]; $v[] = (float)$x[4]; }
    $r->free();
    $n = count($c);
    if ($n < 600) continue;
    $nTab++;
    // MACD 12/26/60
    $e12 = emaRaw($c, 12); $e26 = emaRaw($c, 26);
    $difR = []; for ($i = 0; $i < $n; $i++) $difR[] = $e12[$i] - $e26[$i];
    $deaR = emaRaw($difR, 60);
    $macdR = []; for ($i = 0; $i < $n; $i++) $macdR[] = 2.0 * ($difR[$i] - $deaR[$i]);
    // ATR14 / volMA20
    $atr = []; $atr[0] = $h[0] - $l[0]; $ka = 2.0 / 15;
    for ($i = 1; $i < $n; $i++) { $tr = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1])); $atr[$i] = $tr * $ka + $atr[$i - 1] * (1 - $ka); }
    $vma = array_fill(0, $n, 0.0); $sv = 0.0;
    for ($i = 0; $i < $n; $i++) { $sv += $v[$i]; if ($i >= 20) $sv -= $v[$i - 20]; $vma[$i] = $i >= 19 ? $sv / 20 : $sv / ($i + 1); }
    // Score事件 + 递增规则 + 模拟
    $sc = []; $negRun = 0; $prevScore = 0.0; $pos = null; $nominal = $U * $LEV;
    for ($i = 1; $i < $n; $i++) {
        if ($difR[$i] < 0) $negRun++; else $negRun = 0;
        if ($i < 35) continue;
        // ---- 持仓走线 (在信号判断前先处理, 同根K线爆仓优先) ----
        if ($pos !== null) {
            $liqPx = $pos['px'] * (1 - 1 / $LEV + $MMR);
            $done = false;
            if ($l[$i] <= $liqPx) {                                       // 爆仓
                $trades[] = ['inst' => $inst, 't0' => $pos['t0'], 'px0' => $pos['px'], 't1' => $t[$i], 'px1' => $liqPx, 'why' => 'LIQ', 'pnl' => -$U, 's' => $pos['s']];
                $pos = null; $done = true;
            } elseif ($h[$i] >= $pos['tp']) {                             // 止盈+0.5%
                $gross = $nominal * $TP; $fee = ($nominal + $nominal * (1 + $TP)) * $FEE;
                $trades[] = ['inst' => $inst, 't0' => $pos['t0'], 'px0' => $pos['px'], 't1' => $t[$i], 'px1' => $pos['tp'], 'why' => 'TP', 'pnl' => $gross - $fee, 's' => $pos['s']];
                $pos = null; $done = true;
            } elseif ($t[$i] - $pos['t0'] >= $TMO_MS) {                   // 超时7天
                $exit = $c[$i]; $gross = $nominal * ($exit - $pos['px']) / $pos['px']; $fee = ($nominal + $nominal * $exit / $pos['px']) * $FEE;
                $trades[] = ['inst' => $inst, 't0' => $pos['t0'], 'px0' => $pos['px'], 't1' => $t[$i], 'px1' => $exit, 'why' => 'TO', 'pnl' => $gross - $fee, 's' => $pos['s']];
                $pos = null; $done = true;
            }
            if ($done) continue;
            continue; // 持仓中不看新信号
        }
        // ---- Score事件 ----
        if (!($macdR[$i] > 0 && $macdR[$i - 1] <= 0 && $difR[$i] < 0)) continue;
        $trough = 0.0;
        for ($j = max(0, $i - 30); $j < $i; $j++) if ($macdR[$j] < $trough) $trough = $macdR[$j];
        $a = max($atr[$i - 1], 1e-9);
        $s = 2.0 * min(abs($trough) / $a, 3.0) / 3.0 + 1.5 * min($negRun, 100) / 100.0;
        if ($negRun >= 40) {
            $ss = $i - $negRun + 1; $mm = $ss + intdiv($negRun - 1, 2);
            $t1 = $ss; for ($j = $ss; $j <= $mm; $j++) if ($l[$j] < $l[$t1]) $t1 = $j;
            $t2 = $mm + 1; for ($j = $mm + 1; $j < $i; $j++) if ($l[$j] < $l[$t2]) $t2 = $j;
            if ($l[$t2] < $l[$t1] && $macdR[$t2] > $macdR[$t1]) $s += 2.5;
        }
        $s += 1.0;
        if ($v[$i] > 1.5 * $vma[$i - 1] && $vma[$i - 1] > 0) $s += 1.0;
        if ($s < 2.0) continue;                       // 只记 Score>=2 (同火箭口径)
        $sc[] = [$i, round($s, 1)];
        // 递增规则: 当前分 > 前一分 → 买入 (回测窗口内, 空仓时)
        if ($s > $prevScore && $t[$i] >= $WIN_MS / 1000 && $t[$i] <= $NOW_MS / 1000) {
            $pos = ['t0' => $t[$i], 'px' => $c[$i], 'tp' => $c[$i] * (1 + $TP), 's' => round($s, 1)];
        }
        $prevScore = $s;
    }
    if ($sc) $perInst[$inst] = count($sc);
    unset($t, $h, $l, $c, $v, $e12, $e26, $difR, $deaR, $macdR, $atr, $vma, $sc);
}

// ---- 汇总 ----
$tot = 0.0; $w = 0; $liq = 0; $to = 0; $tp = 0;
$byInst = [];
usort($trades, function ($a, $b) { return $a['t1'] <=> $b['t1'] ?: strcmp($a['inst'], $b['inst']); });
foreach ($trades as $tr) {
    $tot += $tr['pnl'];
    if ($tr['pnl'] > 0) $w++;
    if ($tr['why'] == 'LIQ') $liq++; elseif ($tr['why'] == 'TO') $to++; else $tp++;
    if (!isset($byInst[$tr['inst']])) $byInst[$tr['inst']] = ['n' => 0, 'pnl' => 0.0, 'w' => 0];
    $byInst[$tr['inst']]['n']++; $byInst[$tr['inst']]['pnl'] += $tr['pnl'];
    if ($tr['pnl'] > 0) $byInst[$tr['inst']]['w']++;
}
$sum = ['nTab' => $nTab, 'nTrade' => count($trades), 'pnl' => round($tot, 2),
    'wr' => count($trades) ? round($w / count($trades) * 100, 1) : 0,
    'tp' => $tp, 'liq' => $liq, 'to' => $to,
    'winInst' => count(array_filter($byInst, function ($x) { return $x['pnl'] > 0; })),
    'loseInst' => count(array_filter($byInst, function ($x) { return $x['pnl'] <= 0; })),
    'winStart' => gmdate('Y-m-d', $WIN_MS / 1000 + 8 * 3600), 'winEnd' => gmdate('Y-m-d', $NOW_MS / 1000 + 8 * 3600)];

$samp = array_slice($trades, 0, 4000);
file_put_contents(__DIR__ . '/../web/scoreup_data.json', json_encode([
    'sum' => $sum, 'byInst' => $byInst, 'trades' => $samp, 'nTradesAll' => count($trades)]));
printf("tables=%d trades=%d pnl=%+.2f wr=%.1f%% tp=%d liq=%d to=%d\n", $nTab, count($trades), $tot, $sum['wr'], $tp, $liq, $to);
