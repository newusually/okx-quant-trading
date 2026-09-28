<?php
/**
 * td9bs_all.php — 全市场 买九9 vs 卖九9 五变体对照 一年回测生成器 (2026-09-27 用户指令)
 * 用户原话: "还不够 买九和卖9 不一样 算一下买和卖的具体回测情况 回测一年"
 *
 * 标准TD口径(strategy/sigstore.go 同源):
 *   买九9 = 底序列计数恰好=9(连续9根收盘<第4根前收盘, 下跌中跌不动 → 止跌预警, 买点)
 *   卖九9 = 顶序列计数恰好=9(连续9根收盘>第4根前收盘, 上涨中涨不动 → 滞涨预警, 卖点)
 * 五变体:
 *   A 买九9做多: 底九9开多, 止盈+2%
 *   B 卖九9做空: 顶九9开空, 止盈-2%(价格跌2%)
 *   C 经典TD多: 底九9开多 → 顶九9信号平多(无固定止盈)
 *   D 经典TD空: 顶九9开空 → 底九9信号平空(无固定止盈)
 *   E 双向: 底九9开多 + 顶九9开空(同一仓位槽, 各自止盈2%)
 * 周期: 15m / 1H / 4H | 10x(爆仓线≈9.6%) | 无加仓(纯粹比较买卖方向) | 超时7天 | taker 0.05% | 每合约1U本金
 * 输出: web/td9bsall_data.json
 */
ini_set('memory_limit', '4096M');
set_time_limit(0);
$LEV = 10; $MMR = 0.004; $FEE = 0.0005; $TP = 0.02; $U = 1.0;
$TMO_MS = 7 * 86400 * 1000;
$LIQ = 1.0 / $LEV - $MMR;
$BARS = ['15m' => '15m', '1h' => '1H', '4h' => '4H'];
$VARIANTS = [
    'A' => '买九9做多(TP+2%)',
    'B' => '卖九9做空(TP-2%)',
    'C' => '底九9买→顶九9平多(经典TD)',
    'D' => '顶九9空→底九9平空(经典TD反)',
    'E' => '双向:底九9多+顶九9空',
];
$CAP = 120; $CAP_CURVE = 100;

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }

$insts = [];
$j = json_decode(file_get_contents('E:/datas/insts_all.json'), true);
foreach ($j['insts'] as $s) if (preg_match('/^(.+)-USDT-SWAP$/', $s, $m2)) $insts[] = strtolower($m2[1]);
$insts = array_values(array_unique($insts)); sort($insts);
echo "insts=" . count($insts) . "\n";

$gT0 = (time() - 365 * 86400) * 1000;

/**
 * 方向通用模拟
 * @param int $mode 0=A买多 1=B卖空 2=C TD多 3=D TD空 4=E双向
 */
function sim($ts, $o, $h, $l, $c, $mode, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, $withDetail) {
    global $CAP, $CAP_CURVE;
    $n = count($c);
    $cash = $U; $pos = null; $bankrupt = false;
    $rounds = 0; $wins = 0; $pnlTotal = 0.0; $liqN = 0; $toN = 0; $sigN = 0;
    $trades = []; $curve = []; $tdUp = 0; $tdDown = 0;
    for ($i = 4; $i < $n; $i++) {
        // 标准TD序列: 顶=连续收盘>第4根前收(涨不动) 底=连续收盘<第4根前收(跌不动)
        $tdUp = ($c[$i] > $c[$i - 4]) ? $tdUp + 1 : 0;
        $tdDown = ($c[$i] < $c[$i - 4]) ? $tdDown + 1 : 0;
        $buy9 = ($tdDown == 9);   // 买九9: 底序列恰9
        $sell9 = ($tdUp == 9);    // 卖九9: 顶序列恰9
        if ($pos) {
            $dir = $pos['dir'];
            $liqPx = $dir > 0 ? $pos['avg'] * (1 - $LIQ) : $pos['avg'] * (1 + $LIQ);
            $hitLiq = $dir > 0 ? ($l[$i] <= $liqPx) : ($h[$i] >= $liqPx);
            $hitTP = $dir > 0 ? ($h[$i] >= $pos['tp']) : ($l[$i] <= $pos['tp']);
            $sigExit = ($mode == 2 && $dir > 0 && $sell9) || ($mode == 3 && $dir < 0 && $buy9);
            $exit = null; $why = '';
            if ($hitLiq) { $exit = $liqPx; $why = 'LIQ'; }
            elseif ($mode != 2 && $mode != 3 && $hitTP) { $exit = max($o[$i], min($pos['tp'], $dir > 0 ? PHP_FLOAT_MAX : -PHP_FLOAT_MAX)); $exit = $dir > 0 ? max($o[$i], $pos['tp']) : min($o[$i], $pos['tp']); $why = 'TP'; }
            elseif ($sigExit) { $exit = $c[$i]; $why = 'SIG'; }
            elseif ($ts[$i] - $pos['t'] >= $TMO_MS) { $exit = $c[$i]; $why = 'TO'; }
            if ($exit !== null) {
                if ($why == 'LIQ') { $cash += 0; $pnl = -$pos['u']; }
                else {
                    if ($dir > 0) { $gross = $pos['q'] * $exit - $pos['net']; }
                    else { $gross = $pos['net'] - $pos['q'] * $exit; }
                    $feeOut = $pos['q'] * $exit * $FEE;
                    $cash += $pos['u'] + $gross - $feeOut;
                    $pnl = $gross - $feeOut;
                }
                if ($withDetail) $trades[] = [$pos['t'], $ts[$i], $dir, $exit, round($pnl, 4), $why];
                $pnlTotal += $pnl; $rounds++;
                if ($why == 'LIQ') $liqN++; elseif ($why == 'TO') $toN++; elseif ($why == 'SIG') $sigN++;
                if ($pnl > 0) $wins++;
                $curve[] = [$ts[$i], round($cash, 4)];
                $pos = null;
                if ($cash < $U && $why != 'TP') $bankrupt = true;
            }
        }
        // 入场
        $wantLong = ($mode == 0 || $mode == 2 || $mode == 4) && $buy9;
        $wantShort = ($mode == 1 || $mode == 3 || $mode == 4) && $sell9;
        if (!$pos && !$bankrupt && ($wantLong || $wantShort) && $cash >= $U) {
            $dir = $wantLong ? 1 : -1;   // 同刻双信号优先做多(极少见)
            $net = $U * $LEV * (1 - $FEE);
            $cash -= $U;
            $tp = $dir > 0 ? $c[$i] * (1 + $TP) : $c[$i] * (1 - $TP);
            $pos = ['t' => $ts[$i], 'q' => $net / $c[$i], 'u' => $U, 'net' => $net,
                    'avg' => $c[$i], 'tp' => $tp, 'dir' => $dir];
        }
    }
    if ($pos) {
        $exit = $c[$n - 1]; $dir = $pos['dir'];
        $gross = $dir > 0 ? $pos['q'] * $exit - $pos['net'] : $pos['net'] - $pos['q'] * $exit;
        $feeOut = $pos['q'] * $exit * $FEE;
        $cash += $pos['u'] + $gross - $feeOut;
        $pnl = $gross - $feeOut;
        if ($withDetail) $trades[] = [$pos['t'], $ts[$n - 1], $dir, $exit, round($pnl, 4), 'END'];
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
          'pnl' => round($pnlTotal, 4), 'liq' => $liqN, 'to' => $toN, 'sigx' => $sigN,
          'bankrupt' => $bankrupt, 'cashEnd' => round($cash, 4), 'maxdd' => round($maxdd, 4)];
    if ($withDetail) { $r['curve'] = $curve; if (count($trades) > $CAP) $trades = array_slice($trades, 0, $CAP); $r['trades'] = $trades; }
    return $r;
}

$results = []; $matrix = []; $bestV = [];
foreach ($BARS as $bk => $okxBar) {
    $resBar = []; $done = 0;
    foreach ($insts as $base) {
        $tbl = "kline_{$base}_usdt_swap_{$bk}";
        $r = $DB->query("SELECT candle_time,`c`,`h`,`l`,`o` FROM `$tbl` WHERE candle_time >= $gT0 ORDER BY candle_time ASC");
        if (!$r) { $done++; continue; }
        $ts = []; $c = []; $h = []; $l = []; $o = [];
        while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $c[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $o[] = (float)$x[4]; }
        $r->free();
        $n = count($c);
        if ($n < 110) { $done++; continue; }
        $row = ['inst' => strtoupper($base), 'days' => round(($ts[$n - 1] - $ts[0]) / 86400000.0, 1),
                'bars' => $n, 'span' => [bj($ts[0]), bj($ts[$n - 1])], 'variants' => []];
        foreach (array_keys($VARIANTS) as $vk) $row['variants'][$vk] = sim($ts, $o, $h, $l, $c, ord($vk) - 65, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, false);
        $resBar[] = $row;
        $done++;
        if ($done % 100 == 0) echo "$bk done $done\n";
    }
    $matrix[$bk] = [];
    foreach (array_keys($VARIANTS) as $vk) {
        $t = ['n' => 0, 'win' => 0, 'pnl' => 0, 'liq' => 0, 'to' => 0, 'sigx' => 0, 'bankrupt' => 0, 'insts' => 0, 'pos' => 0];
        foreach ($resBar as $row) { $v = $row['variants'][$vk]; if ($v['n'] == 0) continue;
            $t['insts']++; $t['n'] += $v['n']; $t['win'] += $v['win']; $t['pnl'] += $v['pnl'];
            $t['liq'] += $v['liq']; $t['to'] += $v['to']; $t['sigx'] += $v['sigx'];
            if ($v['bankrupt']) $t['bankrupt']++;
            if ($v['pnl'] > 0) $t['pos']++; }
        $matrix[$bk][$vk] = $t;
    }
    $bv = 'A'; $bp = -INF;
    foreach ($matrix[$bk] as $vk => $t) if ($t['pnl'] > $bp) { $bp = $t['pnl']; $bv = $vk; }
    $bestV[$bk] = $bv;
    // 全变体补明细(带 curve/trades)
    foreach ($resBar as $idx => $row) {
        $base = strtolower($row['inst']);
        $r = $DB->query("SELECT candle_time,`c`,`h`,`l`,`o` FROM kline_{$base}_usdt_swap_{$bk} WHERE candle_time >= $gT0 ORDER BY candle_time ASC");
        $ts = []; $c = []; $h = []; $l = []; $o = [];
        while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $c[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $o[] = (float)$x[4]; }
        $r->free();
        foreach (array_keys($VARIANTS) as $vk)
            $resBar[$idx]['variants'][$vk] = sim($ts, $o, $h, $l, $c, ord($vk) - 65, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, true);
    }
    $results[$bk] = $resBar;
    echo "$bk best=$bv pnl=$bp\n";
}

$out = ['generated' => date('Y-m-d H:i:s'),
        'params' => ['lev' => $LEV, 'tp' => $TP, 'u' => $U, 'fee' => $FEE, 'liq' => $LIQ, 'timeout_days' => 7, 'adds' => 0],
        'variants' => $VARIANTS, 'span' => [bj($gT0), bj(time() * 1000)],
        'matrix' => $matrix, 'bestV' => $bestV, 'results' => $results];
file_put_contents(__DIR__ . '/../web/td9bsall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "saved size=" . round(filesize(__DIR__ . '/../web/td9bsall_data.json') / 1048576, 1) . "MB\n";
foreach ($BARS as $bk => $_) { echo "== $bk ==\n"; foreach ($matrix[$bk] as $vk => $t) printf("  %s %s: pnl=%.1f n=%d wr=%.1f%% liq=%d sig=%d 盈利合约=%d\n", $vk, $VARIANTS[$vk], $t['pnl'], $t['n'], $t['n'] ? round($t['win'] / $t['n'] * 100, 1) : 0, $t['liq'], $t['sigx'], $t['pos']); }
