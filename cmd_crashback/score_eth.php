<?php
/**
 * score_eth.php — ETH 1m MACD(12,26,60) 打分公式 阶段分值分档回测 (2026-09-27 用户指令)
 * 用户原话: "给我打分公式跑回测 给我设置每个阶段性分值 计算ROI+10%(价+0.1%) 回测一年数据
 *   告诉我到底哪个最赚钱 20X就行 eth"
 *
 * 打分公式(上一轮交付的 ATR 归一化版, 满分 8.0):
 *   Score = 2.0×min(|柱谷30|/ATR14, 3)/3      ← 深柱深度与波动挂钩(不写死-5)
 *         + 1.5×min(DIF<0连续根数,100)/100    ← 下跌动能持续期
 *         + 2.5×底背离                        ← 价新低而柱谷抬高(唯一预示大反弹的结构)
 *         + 1.0×确认(柱上穿>0)                ← 候选信号本身即满足
 *         + 1.0×放量(>1.5×MA20)              ← 反转有真实买盘
 *   候选 = MACD柱上穿>0 且 DIF<0 (0轴下动能反转)
 * 阶段分值档: 0(全部) / 1.5 / 2.0 / 2.5 / 3.0 / 3.5 / 4.0 / 5.0
 * 20x · 爆仓=价格-4.6%(亏光350U保证金) · 每轮本金350U全仓 · taker 0.05% · 超时48h
 * 止盈: 价格+2% (ROI+40% @20x)
 * 输出: web/scoreeth_data.json
 */
ini_set('memory_limit', '6144M');
set_time_limit(0);
$LEV = 20; $MMR = 0.004; $FEE = 0.0005; $CAP = 350.0; $TMO_MS = 48 * 3600 * 1000;
$LIQ = 1.0 / $LEV - $MMR;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }

// ---- 1) 加载 1m ----
$gT0 = (time() - 368 * 86400) * 1000;
$r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_eth_usdt_swap_1m WHERE candle_time >= $gT0 ORDER BY candle_time ASC");
$ts = []; $o = []; $h = []; $l = []; $c = []; $v = [];
while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0) continue; $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; }
$r->free();
$n = count($c);
echo "bars=$n span=" . bj($ts[0]) . " ~ " . bj($ts[$n - 1]) . "\n";

// ---- 2) 指标 ----
function emaAll($a, $p) {
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = $a[0];
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; }
    return $out;
}
$e12 = emaAll($c, 12); $e26 = emaAll($c, 26);
$dif = []; for ($i = 0; $i < $n; $i++) $dif[] = $e12[$i] - $e26[$i];
$dea = emaAll($dif, 60);
$macd = []; for ($i = 0; $i < $n; $i++) $macd[] = 2.0 * ($dif[$i] - $dea[$i]);
// ATR14 (EMA口径)
$tr = []; $atr = [];
$tr[0] = $h[0] - $l[0]; $atr[0] = $tr[0];
$ka = 2.0 / 15;
for ($i = 1; $i < $n; $i++) {
    $tr[$i] = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1]));
    $atr[$i] = $tr[$i] * $ka + $atr[$i - 1] * (1 - $ka);
}
// vol MA20
$vma = array_fill(0, $n, 0.0); $sv = 0.0;
for ($i = 0; $i < $n; $i++) { $sv += $v[$i]; if ($i >= 20) $sv -= $v[$i - 20]; $vma[$i] = $i >= 19 ? $sv / 20 : $sv / ($i + 1); }
echo "indicators done\n";

// ---- 3) 候选信号 + 打分 ----
$cands = [];   // [i, score, comp...]
$negRun = 0;
for ($i = 0; $i < $n; $i++) {
    if ($dif[$i] < 0) $negRun++; else $negRun = 0;
    if ($i < 35 || $i < 0) continue;
    if (!($macd[$i] > 0 && $macd[$i - 1] <= 0 && $dif[$i] < 0)) continue;
    // comp1 深柱: 最近30根柱谷 / ATR
    $trough = 0.0;
    for ($j = max(0, $i - 30); $j < $i; $j++) if ($macd[$j] < $trough) $trough = $macd[$j];
    $a = max($atr[$i - 1], 1e-9);
    $c1 = 2.0 * min(abs($trough) / $a, 3.0) / 3.0;
    // comp2 持续期
    $c2 = 1.5 * min($negRun, 100) / 100.0;
    // comp3 底背离: 本轮 DIF<0 段前后半 各自价格谷点 比价/比柱
    $c3 = 0.0;
    if ($negRun >= 40) {
        $s = $i - $negRun + 1; $m = $s + intdiv($negRun - 1, 2);
        $t1 = $s; for ($j = $s; $j <= $m; $j++) if ($l[$j] < $l[$t1]) $t1 = $j;
        $t2 = $m + 1; for ($j = $m + 1; $j < $i; $j++) if ($l[$j] < $l[$t2]) $t2 = $j;
        if ($l[$t2] < $l[$t1] && $macd[$t2] > $macd[$t1]) $c3 = 2.5;
    }
    // comp4 确认(上穿)恒=1; comp5 放量
    $c4 = 1.0;
    $c5 = ($v[$i] > 1.5 * $vma[$i - 1] && $vma[$i - 1] > 0) ? 1.0 : 0.0;
    $cands[] = ['i' => $i, 's' => $c1 + $c2 + $c3 + $c4 + $c5];
}
$nc = count($cands);
echo "candidates=$nc\n";

// ---- 4) 每个候选一次前向走线, 止盈口径共用 ----
$TPS = ['p2' => 0.02];  // 价格+2% (ROI+40% @20x)
$tpPxOf = [];
$rows = [];
$netX = $CAP * $LEV * (1 - $FEE);
foreach ($cands as $ci => $cd) {
    $i0 = $cd['i']; $px = $c[$i0];
    $liqPx = $px * (1 - $LIQ);
    $firstLiq = -1; $firstTP = [];
    foreach ($TPS as $tk => $tp) $firstTP[$tk] = -1;
    $need = count($TPS);
    for ($i = $i0 + 1; $i < $n; $i++) {
        if ($firstLiq < 0 && $l[$i] <= $liqPx) $firstLiq = $i;
        foreach ($TPS as $tk => $tp) {
            if ($firstTP[$tk] < 0 && $h[$i] >= $px * (1 + $tp)) $firstTP[$tk] = $i;
        }
        // 一旦爆仓出现: 未到TP的口径全部=爆仓, 已到TP的口径保留更早的TP → 可停止走线
        // 全部口径都已到TP → 更晚的爆仓无关 → 可停止走线
        if ($firstLiq >= 0) break;
        $all = true; foreach ($firstTP as $f) if ($f < 0) { $all = false; break; }
        if ($all) break;
        if ($ts[$i] - $ts[$i0] >= $TMO_MS) break; // 超时, 剩余口径按超时
    }
    $q = $netX / $px; $feeOut = $q * $FEE;
    $row = ['t0' => $ts[$i0], 'px' => $px, 's' => round($cd['s'], 2)];
    foreach ($TPS as $tk => $tp) {
        $fi = $firstTP[$tk]; $fl = $firstLiq;
        if ($fl >= 0 && ($fi < 0 || $fl <= $fi)) { $exit = $liqPx; $why = 'LIQ'; $ti = $fl; }
        elseif ($fi >= 0) { $exit = max($o[$fi], $px * (1 + $tp)); $why = 'TP'; $ti = $fi; }
        else { // 超时或期末
            $ti = -1;
            for ($i = $i0 + 1; $i < $n; $i++) if ($ts[$i] - $ts[$i0] >= $TMO_MS) { $ti = $i; break; }
            if ($ti < 0) $ti = $n - 1;
            $exit = $c[$ti]; $why = ($ts[$ti] - $ts[$i0] >= $TMO_MS) ? 'TO' : 'END';
        }
        $pnl = ($why == 'LIQ') ? -$CAP : $q * ($exit - $px) - $q * $exit * $FEE;
        $row[$tk] = ['pnl' => $pnl, 'why' => $why, 't1' => $ts[$ti]];
    }
    $rows[] = $row;
    if ($ci % 2000 == 0) echo "sim $ci/$nc\n";
}
echo "sim done\n";

// ---- 5) 分档聚合 ----
// 注意: PHP 浮点数不能当数组键(会被截断成int导致 2.0/2.5 互相覆盖), 一律用规范化字符串键
function tkey($th) { $s = number_format((float)$th, 1, '.', ''); return rtrim(rtrim($s, '0'), '.'); }
$THS = [0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 5.0];
$THK = array_map('tkey', $THS);
$agg = [];
foreach ($THS as $idx => $th) {
    $k = $THK[$idx];
    foreach ($TPS as $tk => $tp) {
        $nT = 0; $pnl = 0.0; $w = 0; $liq = 0; $to = 0;
        foreach ($rows as $rw) {
            if ($rw['s'] < $th) continue;
            $nT++; $p = $rw[$tk]['pnl']; $pnl += $p;
            if ($p > 0) $w++; if ($rw[$tk]['why'] == 'LIQ') $liq++; if ($rw[$tk]['why'] == 'TO') $to++;
        }
        $agg[$k][$tk] = ['n' => $nT, 'pnl' => round($pnl, 2),
            'roi' => $nT ? round($pnl / ($CAP * $nT) * 100, 2) : 0,
            'avg' => $nT ? round($pnl / $nT, 3) : 0,
            'wr' => $nT ? round($w / $nT * 100, 1) : 0, 'liq' => $liq, 'to' => $to];
        printf("th>=%.1f %-6s n=%-6d wr=%5.1f%% liq=%-5d pnl=%+11.2f roi=%+7.2f%%\n", $th, $tk, $nT, $agg[$k][$tk]['wr'], $liq, $pnl, $agg[$k][$tk]['roi']);
    }
}
// 分值分布(0.5一档, 字符串键)
$hist = [];
foreach ($rows as $rw) { $bin = (int)floor($rw['s'] * 2); $key = number_format($bin / 2, 1); $hist[$key] = ($hist[$key] ?? 0) + 1; }
ksort($hist);

// 明细样本(最多3000行, 均匀抽样)
$step = max(1, intdiv(count($rows), 3000));
$samp = [];
for ($k = 0; $k < count($rows); $k += $step) {
    $rw = $rows[$k];
    $samp[] = [bj($rw['t0']), $rw['s'], round($rw['px'], 2),
        round($rw['p2']['pnl'], 2), $rw['p2']['why']];
}
$out = ['generated' => date('Y-m-d H:i:s'),
    'params' => ['macd' => '12,26,60', 'lev' => $LEV, 'liq' => round($LIQ, 4), 'cap' => $CAP, 'fee' => $FEE, 'timeout_h' => 48,
        'score_formula' => '2.0*min(|柱谷30|/ATR14,3)/3 + 1.5*min(DIF<0根数,100)/100 + 2.5*底背离 + 1.0*确认 + 1.0*放量'],
    'span' => [bj($ts[0]), bj($ts[$n - 1])], 'bars' => $n, 'candidates' => $nc,
    'tpNames' => ['p2' => '价+2% (ROI+40% @20x)'],
    'thresholds' => $THK, 'agg' => $agg, 'hist' => $hist, 'samples' => $samp];
file_put_contents(__DIR__ . '/../web/scoreeth_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "saved size=" . round(filesize(__DIR__ . '/../web/scoreeth_data.json') / 1048576, 2) . "MB\n";
