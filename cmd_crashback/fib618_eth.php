<?php
/**
 * fib618_eth.php — ETH 15m 真实黄金分割 0.618 买点 检测入库 + 一年回测 (2026-09-27 用户指令)
 * 用户原话: "给我真正正确的黄金分割买点 0.618 下跌以后上涨到0.618 给我15分钟的ETH合约 帮我划线
 *            要一年的数据 ... 保存在数据库 并且回测一年 黄金分割胜率 赚多少"
 *
 * 买点定义(经典黄金分割回撤):
 *   1) 找一段下跌: 摆动高点 H (阶段最高) → 阴跌到摆动低点 L (阶段最低, 跌幅>=2%)
 *   2) 从 L 反弹, 当 15m 收盘价上穿 0.618 回撤位 fib = L + (H-L)*0.618 → 买入信号
 *      (回撤 61.8% 是黄金分割最强阻力/买点位, 用户口径: 下跌后上涨到0.618)
 *   3) 触发后重新找下一段下跌
 * 入库: trading.fib618_marks (inst,bar,t_signal,t_high,t_low,px_high,px_low,px_fib) — 网页划线用
 * 回测: 信号收盘买 1U · 10x · taker 0.05% · 超时7天 · 爆仓9.6% · 止盈档位 +0.5%/+1%/+2%/回归前高
 * 输出: web/fib618eth_data.json
 */
ini_set('memory_limit', '2048M');
set_time_limit(0);
$LEV = 10; $MMR = 0.004; $FEE = 0.0005; $U = 1.0; $TMO_MS = 7 * 86400 * 1000;
$LIQ = 1.0 / $LEV - $MMR;
/* 用户口径: 显著下跌才画 fib; 1.5% 15m约对应一次像样回调 */
$DROP_MIN = 0.015;   // 摆动高→低 最小跌幅 2% (过滤横盘毛刺)
$INST = 'eth'; $BAR = '15m';

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }

// ---- 1) 加载一年K线 ----
$gT0 = (time() - 368 * 86400) * 1000;
$r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$INST}_usdt_swap_{$BAR} WHERE candle_time >= $gT0 ORDER BY candle_time ASC");
$ts = []; $o = []; $h = []; $l = []; $c = [];
while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0) continue; $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }
$r->free();
$n = count($c);
echo "bars=$n span=" . bj($ts[0]) . " ~ " . bj($ts[$n - 1]) . "\n";

// ---- 2) 检测 0.618 买点 ----
// 状态机(真实施划口径 — 永远锚定【最近一段腿】):
//   seek_high: 跟踪摆动高点 H; 从 H 跌幅>=2% → seek_low
//   seek_low:  跟踪摆动低点 L(loIdx) 与反弹最高 rbH;
//              · 创新低 → 重新锚定: H=失败反弹高点rbH(若无反弹维持原H), L=新低 (熊市连续出新腿)
//              · 收盘阳线站上 fib=L+(H-L)*0.618 → 买点(重置)
//              · 价格突破当前腿高点 H → 下跌腿失效 → seek_high
$signals = [];
$hiIdx = 0; $loIdx = -1; $rbH = 0.0; $anchored = false;  // anchored: H是否已重锚为失败反弹高点
$H = 0.0;
$phase = 'seek_high';
for ($i = 1; $i < $n; $i++) {
    if ($phase == 'seek_high') {
        if ($h[$i] > $h[$hiIdx]) { $hiIdx = $i; $loIdx = -1; $anchored = false; }
        if ($l[$i] <= $h[$hiIdx] * (1 - $DROP_MIN)) { $phase = 'seek_low'; $loIdx = $i; $rbH = $h[$i]; $anchored = false; }
    } else {
        $rbH = max($rbH, $h[$i]);
        if ($l[$i] < $l[$loIdx]) {                    // 创新低 → 重锚最近一段腿
            if ($rbH > $l[$i] && $rbH < $h[$hiIdx]) { $H = $rbH; $anchored = true; }
            else if (!$anchored) $H = $h[$hiIdx];
            $loIdx = $i; $rbH = $h[$i];
        } else if (!$anchored) $H = $h[$hiIdx];
        $L = $l[$loIdx];
        if ($h[$i] > $H) { $phase = 'seek_high'; $hiIdx = $i; $loIdx = -1; $anchored = false; continue; } // 下跌腿失效
        $fib = $L + ($H - $L) * 0.618;
        if ($c[$i] >= $fib && $c[$i] > $o[$i]) {      // 收盘阳线站上 0.618 → 买点
            $signals[] = ['t' => $ts[$i], 'i' => $i, 'H' => $H, 'L' => $L, 'fib' => $fib];
            $phase = 'seek_high'; $hiIdx = $i; $loIdx = -1; $anchored = false;
        }
    }
}
// 回填每段腿的 tH (锚定高点时间): 信号前回溯找 h==H 的位置(限400根)
foreach ($signals as $k => $s) {
    $tH = -1;
    for ($m2 = $s['i'] - 1; $m2 >= 0 && $s['i'] - $m2 < 600; $m2--) {
        if (abs($h[$m2] - $s['H']) < 1e-9) { $tH = $ts[$m2]; break; }
        if ($l[$m2] < $s['L']) break;
    }
    $signals[$k]['tH'] = $tH;
    $signals[$k]['tL'] = $ts[$s['i'] - 1];
    for ($m2 = $s['i'] - 1; $m2 >= 0; $m2--) { if (abs($l[$m2] - $s['L']) < 1e-9) { $signals[$k]['tL'] = $ts[$m2]; break; } }
}
echo "signals=" . count($signals) . "\n";

// ---- 3) 入库 (划线用, 幂等) ----
$DB->query("CREATE TABLE IF NOT EXISTS fib618_marks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  inst VARCHAR(32) NOT NULL, bar VARCHAR(8) NOT NULL,
  t_signal BIGINT NOT NULL, t_high BIGINT NOT NULL, t_low BIGINT NOT NULL,
  px_high DOUBLE NOT NULL, px_low DOUBLE NOT NULL, px_fib DOUBLE NOT NULL,
  signal_px DOUBLE NOT NULL DEFAULT 0,
  UNIQUE KEY uk (inst, bar, t_signal), KEY idx_t (inst, bar, t_signal)) ENGINE=InnoDB");
$st = $DB->prepare("REPLACE INTO fib618_marks (inst,bar,t_signal,t_high,t_low,px_high,px_low,px_fib,signal_px) VALUES (?,?,?,?,?,?,?,?,?)");
$cnt = 0;
foreach ($signals as $s) {
    $st->bind_param('ssssddddd', $INST, $BAR, $s['t'], $s['tH'], $s['tL'], $s['H'], $s['L'], $s['fib'], $c[$s['i']]);
    $st->execute(); $cnt++;
}
$st->close();
echo "db saved=$cnt\n";

// ---- 4) 回测: 信号收盘买1U · 10x · 各止盈档 ----
function bt($ts, $o, $h, $l, $c, $signals, $TP, $refill, $FEE, $LEV, $LIQ, $U, $TMO_MS) {
    $n = count($c); $pnlTotal = 0.0; $rounds = 0; $wins = 0; $liqN = 0; $toN = 0;
    $cash = $U; $bankrupt = false; $trades = []; $curve = [];
    foreach ($signals as $s) {
        if ($bankrupt) break;
        $i0 = $s['i'];
        if (!$refill && $cash < $U) break;
        if (!$refill) $cash -= $U;
        $net = $U * $LEV * (1 - $FEE);
        $q = $net / $c[$i0]; $u = $U;
        $entry = $c[$i0]; $tpPx = $entry * (1 + $TP); $liqPx = $entry * (1 - $LIQ);
        $pnl = null; $why = ''; $exit = 0; $tExit = 0;
        for ($i = $i0; $i < $n; $i++) {
            if ($i > $i0) {
                if ($l[$i] <= $liqPx) { $why = 'LIQ'; $exit = $liqPx; $tExit = $ts[$i]; break; }
                if ($h[$i] >= $tpPx) { $exit = max($o[$i], $tpPx); $why = 'TP'; $tExit = $ts[$i]; break; }
                if ($ts[$i] - $ts[$i0] >= $TMO_MS) { $exit = $c[$i]; $why = 'TO'; $tExit = $ts[$i]; break; }
            }
        }
        if ($why == '') { $exit = $c[$n - 1]; $why = 'END'; $tExit = $ts[$n - 1]; }
        $gross = $q * $exit - $net; $feeOut = $q * $exit * $FEE;
        $pnl = $gross - $feeOut;
        if ($refill) { $pnlTotal += $pnl; $curve[] = [$tExit, round(1.0 + $pnlTotal, 4)]; }
        else { $cash += $u + $gross - $feeOut; $curve[] = [$tExit, round($cash, 4)]; $pnlTotal += $pnl; if ($cash < $U && $why != 'TP') $bankrupt = true; }
        $rounds++; if ($pnl > 0) $wins++; if ($why == 'LIQ') $liqN++; if ($why == 'TO') $toN++;
        $trades[] = [$s['t'], $tExit, round($s['H'], 2), round($s['L'], 2), round($s['fib'], 2), round($entry, 2), round($exit, 2), round($pnl, 4), $why];
    }
    $maxdd = 0.0; $peak = -INF;
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; }
    return ['n' => $rounds, 'win' => $wins, 'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0,
            'pnl' => round($pnlTotal, 4), 'liq' => $liqN, 'to' => $toN, 'cashEnd' => round($refill ? 1 + $pnlTotal : $cash, 4),
            'maxdd' => round($maxdd, 4), 'trades' => $trades, 'curve' => $curve];
}

$TPS = ['tp05' => 0.005, 'tp10' => 0.01, 'tp20' => 0.02];
$bts = [];
foreach (['10x' => [$LEV, $LIQ], '3x' => [3, 1.0 / 3 - $MMR]] as $lk => [$lev2, $liq2]) {
    foreach ($TPS as $k => $tp) {
        $bts[$k][$lk]['refill'] = bt($ts, $o, $h, $l, $c, $signals, $tp, true, $FEE, $lev2, $liq2, $U, $TMO_MS);
        $bts[$k][$lk]['strict'] = bt($ts, $o, $h, $l, $c, $signals, $tp, false, $FEE, $lev2, $liq2, $U, $TMO_MS);
    }
}
$bts['rehigh']['10x']['refill'] = btRehigh($ts, $o, $h, $l, $c, $signals, true, $FEE, $LEV, $LIQ, $U, $TMO_MS);
$bts['rehigh']['10x']['strict'] = btRehigh($ts, $o, $h, $l, $c, $signals, false, $FEE, $LEV, $LIQ, $U, $TMO_MS);
$bts['rehigh']['3x']['refill'] = btRehigh($ts, $o, $h, $l, $c, $signals, true, $FEE, 3, 1.0 / 3 - $MMR, $U, $TMO_MS);
$bts['rehigh']['3x']['strict'] = btRehigh($ts, $o, $h, $l, $c, $signals, false, $FEE, 3, 1.0 / 3 - $MMR, $U, $TMO_MS);
foreach ($bts as $k => $modes) foreach ($modes as $lk => $mm) foreach ($mm as $mk => $v)
    printf("  %-8s %-4s %-7s n=%-4d wr=%5.1f%% liq=%-3d pnl=%+9.2f cashEnd=%8.2f\n", $k, $lk, $mk, $v['n'], $v['wr'], $v['liq'], $v['pnl'], $v['cashEnd']);
function btRehigh($ts, $o, $h, $l, $c, $signals, $refill, $FEE, $LEV, $LIQ, $U, $TMO_MS) {
    $n = count($c); $pnlTotal = 0.0; $rounds = 0; $wins = 0; $liqN = 0; $toN = 0;
    $cash = $U; $bankrupt = false; $trades = []; $curve = [];
    foreach ($signals as $s) {
        if ($bankrupt) break;
        $i0 = $s['i'];
        if (!$refill && $cash < $U) break;
        if (!$refill) $cash -= $U;
        $net = $U * $LEV * (1 - $FEE);
        $q = $net / $c[$i0]; $u = $U;
        $entry = $c[$i0]; $tpPx = $s['H']; $liqPx = $entry * (1 - $LIQ);
        $why = ''; $exit = 0; $tExit = 0;
        for ($i = $i0; $i < $n; $i++) {
            if ($i > $i0) {
                if ($l[$i] <= $liqPx) { $why = 'LIQ'; $exit = $liqPx; $tExit = $ts[$i]; break; }
                if ($h[$i] >= $tpPx) { $exit = max($o[$i], $tpPx); $why = 'TP'; $tExit = $ts[$i]; break; }
                if ($ts[$i] - $ts[$i0] >= $TMO_MS) { $exit = $c[$i]; $why = 'TO'; $tExit = $ts[$i]; break; }
            }
        }
        if ($why == '') { $exit = $c[$n - 1]; $why = 'END'; $tExit = $ts[$n - 1]; }
        $gross = $q * $exit - $net; $feeOut = $q * $exit * $FEE;
        $pnl = $gross - $feeOut;
        if ($refill) { $pnlTotal += $pnl; $curve[] = [$tExit, round(1.0 + $pnlTotal, 4)]; }
        else { $cash += $u + $gross - $feeOut; $curve[] = [$tExit, round($cash, 4)]; $pnlTotal += $pnl; if ($cash < $U && $why != 'TP') $bankrupt = true; }
        $rounds++; if ($pnl > 0) $wins++; if ($why == 'LIQ') $liqN++; if ($why == 'TO') $toN++;
        $trades[] = [$s['t'], $tExit, round($s['H'], 2), round($s['L'], 2), round($s['fib'], 2), round($entry, 2), round($exit, 2), round($pnl, 4), $why];
    }
    $maxdd = 0.0; $peak = -INF;
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; }
    return ['n' => $rounds, 'win' => $wins, 'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0,
            'pnl' => round($pnlTotal, 4), 'liq' => $liqN, 'to' => $toN, 'cashEnd' => round($refill ? 1 + $pnlTotal : $cash, 4),
            'maxdd' => round($maxdd, 4), 'trades' => $trades, 'curve' => $curve];
}

// ---- 5) 输出 (K线抽样到 ~12000 根方便网页加载, fib 全量) ----
$step = max(1, (int)ceil($n / 12000));
$k2 = [];
for ($i = 0; $i < $n; $i += $step) $k2[] = [$ts[$i], $o[$i], $h[$i], $l[$i], $c[$i]];
$sigOut = [];
foreach ($signals as $s) $sigOut[] = ['t' => $s['t'], 'tH' => $s['tH'], 'tL' => $s['tL'], 'H' => $s['H'], 'L' => $s['L'], 'fib' => $s['fib']];
$out = ['generated' => date('Y-m-d H:i:s'), 'inst' => 'ETH-USDT-SWAP', 'bar' => '15m',
        'span' => [bj($ts[0]), bj($ts[$n - 1])], 'bars_total' => $n, 'bars_sampled' => count($k2), 'step' => $step,
        'params' => ['lev' => $LEV, 'fee' => $FEE, 'liq' => round($LIQ, 4), 'u' => $U, 'timeout_days' => 7, 'drop_min' => $DROP_MIN],
        'candles' => $k2, 'signals' => $sigOut, 'backtests' => $bts];
file_put_contents(__DIR__ . '/../web/fib618eth_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "saved size=" . round(filesize(__DIR__ . '/../web/fib618eth_data.json') / 1048576, 2) . "MB\n";
