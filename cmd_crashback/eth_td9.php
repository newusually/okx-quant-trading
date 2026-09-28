<?php
/**
 * eth_td9.php — 神奇九转(标准TD口径) 一年回测: 3m/5m/15m/1h/4h 五周期对照 (2026-09-26)
 * 口径: 与 strategy/sigstore.go ComputeKlineSignals 完全一致(数据库 kline_signals 同款):
 *   顶序列 = 连续N根 收>c[i-4] (涨不动); 底序列 = 连续N根 收<c[i-4] (跌不动);
 *   计数连续, 断即清零; 只取计数 5~9 (九5~九9), 恰好第9根=预警。
 * 三组玩法(每笔1U保证金, 100x, 无止损, 爆仓线照模拟, 超时7天):
 *   A 九9反转   : 底九9→开多 / 顶九9→开空(信号根次根开盘进); 反向九9平仓(可反手)
 *   B 九9+确认  : 底九9后等收盘>信号根高点才开多(空镜像); 平仓同A
 *   C 九6~九9分批: 计数6/7/8/9每根+0.25U同向建满1U(信号根收盘成交); 平仓同A
 * 费用: 进maker 0.02% / 出taker 0.05% / 资金费 0.01%/8h; 净口径。
 */
error_reporting(E_ERROR | E_PARSE);
set_time_limit(0);
ini_set('memory_limit', '1024M');

$db = new mysqli('127.0.0.1', 'root', '', 'trading');
$db->query("SET SESSION innodb_lock_wait_timeout=60");

$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;
$EQ0 = 500.0; $UNIT = 1.0; $TO_MS = 7 * 86400000; $liqDrop = 1.0 / $LEV - $MMR;
$BARS = ['3m' => 180000, '5m' => 300000, '15m' => 900000, '1h' => 3600000, '4h' => 14400000];
$YEAR_MS = 365 * 86400000;
const TOP = 1; const BOT = -1;

function bj($ms) { return gmdate('Y-m-d H:i', $ms / 1000 + 8 * 3600); }

$out = ['generated' => date('Y-m-d H:i'), 'inst' => 'ETH-USDT-SWAP', 'span' => [], 'freq' => [], 'variants' => [], 'res' => []];

$VARIANTS = [
    'A' => '九9反转(底9开多/顶9开空, 反向九9平)',
    'B' => '九9+确认(收盘破信号根高/低点才进)',
    'C' => '九6~九9分批(6/7/8/9每根+0.25U建满1U)',
    'D' => '九9反转+固定止盈±2%(sixchipall口径)',
];

foreach ($BARS as $bar => $barMs) {
    $t0 = microtime(true);
    $r = $db->query("SELECT candle_time,o,h,l,c FROM `kline_eth_usdt_swap_$bar`
                     WHERE candle_time >= " . ((time() * 1000) - $YEAR_MS) . " ORDER BY candle_time ASC");
    $ts = $o = $h = $l = $c = [];
    while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }
    $n = count($c);
    $out['span'][$bar] = ['from' => bj($ts[0]), 'to' => bj($ts[$n - 1]), 'bars' => $n];
    if ($n < 200) { echo "$bar data too short\n"; continue; }

    // ---- TD 九转计数 (与 sigstore.go 逐字同口径, 从 i=108 起与库一致) ----
    $phase = array_fill(0, $n, 0); $dir = array_fill(0, $n, 0);
    $tdUp = 0; $tdDown = 0;
    $cnt = [5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0];
    for ($i = 108; $i < $n; $i++) {
        if ($c[$i] > $c[$i - 4]) $tdUp++; else $tdUp = 0;
        if ($c[$i] < $c[$i - 4]) $tdDown++; else $tdDown = 0;
        // 与 sigstore.go 同口径: phase 用未封顶的原始计数, 仅 5..9 恰好区间标注(10+ 不标)
        if ($tdUp >= 5)      { $p = $tdUp;  $d = TOP; }
        elseif ($tdDown >= 5) { $p = $tdDown; $d = BOT; }
        else { $p = 0; $d = 0; }
        if ($p >= 5 && $p <= 9) { $phase[$i] = $p; $dir[$i] = $d; }
    }
    $cnt2 = [5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0];
    for ($i = 108; $i < $n; $i++) if ($phase[$i] >= 5) $cnt2[$phase[$i]]++;
    $top9 = 0; $bot9 = 0;
    for ($i = 108; $i < $n; $i++) { if ($phase[$i] == 9 && $dir[$i] == TOP) $top9++; if ($phase[$i] == 9 && $dir[$i] == BOT) $bot9++; }
    $out['freq'][$bar] = ['n5' => $cnt2[5], 'n6' => $cnt2[6], 'n7' => $cnt2[7], 'n8' => $cnt2[8], 'n9' => $cnt2[9], 'top9' => $top9, 'bot9' => $bot9];

    // ---- 模拟 ----
    /**
     * $mode: A|B|C
     * 返回 [trades, eqEnd, maxDD]
     * trade: tin,tout,dir,mg,avg,xp,pnl,out(LIQT/REV/TO),holdh,adds
     */
    if (!function_exists('sim')) {
    function sim($mode, $barMs, $ts, $o, $h, $l, $c, $phase, $dir) {
        global $LEV, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $EQ0, $UNIT, $TO_MS, $liqDrop;
        $n = count($c);
        $eq = $EQ0; $peak = $eq; $maxDD = 0.0;
        $trades = []; $i = 108;
        // 逐根状态机
        $pos = null; // ['dir'=>+1/-1,'avg','mg','notional','fee','fund','tin','adds','waitDir','sigRef']
        $pending = null; // ['dir','ref'] 下一根开盘执行的入场
        for ($i = 108; $i < $n; $i++) {
            // ---- 1. 持仓中: 先扫本根 liq ----
            if ($pos) {
                $p =& $pos;
                $liqPx = $p['dir'] > 0 ? $p['avg'] * (1 - $liqDrop) : $p['avg'] * (1 + $liqDrop);
                $hit = $p['dir'] > 0 ? ($l[$i] <= $liqPx) : ($h[$i] >= $liqPx);
                $p['fund'] += ($p['dir'] > 0 ? 1 : -1) * $p['notional'] * $FUND8H / 32;
                if ($hit) {
                    $xp = $liqPx;
                    $pnl = ($xp / $p['avg'] - 1) * $p['dir'] * $p['notional'] - $p['fee'] - $p['fund'] - $p['notional'] * $FEE_TAKER;
                    $eq += $pnl;
                    $trades[] = ['tin' => $p['tin'], 'tout' => $ts[$i], 'dir' => $p['dir'], 'mg' => $p['mg'], 'avg' => $p['avg'], 'xp' => $xp, 'pnl' => $pnl, 'out' => 'LIQ', 'holdh' => round(($ts[$i] - $p['tin']) / 3600000, 1), 'adds' => $p['adds']];
                    unset($p); $pos = null; $pending = null;
                } else {
                    // ---- 2. 出场信号: 反向九9 / 超时 / [D组]止盈±2% ----
                    $tpHit = false;
                    if ($mode == 'D') {
                        $tpPx = $p['dir'] > 0 ? $p['avg'] * 1.02 : $p['avg'] * 0.98;
                        $tpHit = $p['dir'] > 0 ? ($h[$i] >= $tpPx) : ($l[$i] <= $tpPx);
                    }
                    $rev = ($p['dir'] > 0 && $phase[$i] == 9 && $dir[$i] == TOP) || ($p['dir'] < 0 && $phase[$i] == 9 && $dir[$i] == BOT);
                    $to = ($ts[$i] - $p['tin']) >= $TO_MS;
                    if ($tpHit || $rev || $to) {
                        $xp = $tpHit ? ($p['dir'] > 0 ? $p['avg'] * 1.02 : $p['avg'] * 0.98) : $c[$i];
                        $pnl = ($xp / $p['avg'] - 1) * $p['dir'] * $p['notional'] - $p['fee'] - $p['fund'] - $p['notional'] * $FEE_TAKER;
                        $eq += $pnl;
                        $trades[] = ['tin' => $p['tin'], 'tout' => $ts[$i], 'dir' => $p['dir'], 'mg' => $p['mg'], 'avg' => $p['avg'], 'xp' => $xp, 'pnl' => $pnl, 'out' => $tpHit ? 'TP' : ($rev ? 'REV' : 'TO'), 'holdh' => round(($ts[$i] - $p['tin']) / 3600000, 1), 'adds' => $p['adds']];
                        unset($p); $pos = null;
                        // 反手: 反向九9 触发出场时, A/C 下一根开盘反手开仓(由本根 pending 设置, 见下)
                    } elseif ($mode == 'C' && $p['mg'] < $UNIT && $phase[$i] >= 6 && $phase[$i] <= 9
                              && (($p['dir'] > 0 && $dir[$i] == BOT) || ($p['dir'] < 0 && $dir[$i] == TOP))) {
                        // C: 分批补足 (信号根收盘价成交)
                        $add = min(0.25, $UNIT - $p['mg']);
                        $p['avg'] = ($p['avg'] * $p['notional'] + $c[$i] * $add * $LEV) / ($p['notional'] + $add * $LEV);
                        $p['notional'] += $add * $LEV; $p['mg'] += $add; $p['adds']++;
                        $p['fee'] += $add * $LEV * $FEE_MAKER;
                    }
                }
            }
            // ---- 3. 待执行入场 (上一根信号 → 本根开盘; B组确认等待态有 ref 键不入场) ----
            if (!$pos && $pending && !isset($pending['ref']) && $i + 0 == $i) {
                $d = $pending['dir'];
                $ep = $o[$i]; $mg = ($mode == 'C') ? 0.25 : $UNIT;
                $pos = ['dir' => $d, 'avg' => $ep, 'mg' => $mg, 'notional' => $mg * $LEV, 'fee' => $mg * $LEV * $FEE_MAKER, 'fund' => 0.0, 'tin' => $ts[$i], 'adds' => 0];
                $pending = null;
            }
            // ---- 4. 平仓后/空仓时: 依据本根信号设 pending ----
            if (!$pos && !$pending) {
                if ($phase[$i] == 9) {
                    if ($dir[$i] == BOT && ($mode != 'B')) $pending = ['dir' => 1];                    // A/C: 底9 → 多
                    if ($dir[$i] == TOP && ($mode != 'B')) $pending = ['dir' => -1];                   // A/C: 顶9 → 空
                    if ($mode == 'B') $pending = ['dir' => ($dir[$i] == BOT ? 1 : -1), 'ref' => $i];   // B: 等确认
                }
            }
            // ---- 5. B: 确认触发 (持仓等待确认: 收盘破信号根高/低点 → 下一根开盘进) ----
            if (!$pos && $pending && isset($pending['ref'])) {
                $ri = $pending['ref']; $d = $pending['dir'];
                $ok = $d > 0 ? ($c[$i] > $h[$ri]) : ($c[$i] < $l[$ri]);
                if ($ok) { $pending = ['dir' => $d]; }
                elseif ($phase[$i] == 9) { $pending = ['dir' => ($dir[$i] == BOT ? 1 : -1), 'ref' => $i]; } // 刷新信号
            }
            // ---- 6. C: 空仓时序列分批首仓也在信号根收盘直接建(不走次根开盘), 保持与持仓中补仓一致 ----
            if (!$pos && !$pending && $mode == 'C' && $phase[$i] >= 6 && $phase[$i] <= 9) {
                $d = $dir[$i] == BOT ? 1 : -1;
                $ep = $c[$i];
                $pos = ['dir' => $d, 'avg' => $ep, 'mg' => 0.25, 'notional' => 0.25 * $LEV, 'fee' => 0.25 * $LEV * $FEE_MAKER, 'fund' => 0.0, 'tin' => $ts[$i], 'adds' => 0];
            }
            // ---- 权益峰值/回撤 ----
            if ($eq > $peak) $peak = $eq;
            $dd = $peak - $eq;
            if ($dd > $maxDD) $maxDD = $dd;
            if ($eq <= 0) { $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$i], 'dir' => 0, 'mg' => 0, 'avg' => 0, 'xp' => 0, 'pnl' => -$EQ0, 'out' => 'BANKRUPT', 'holdh' => 0, 'adds' => 0]; break; }
        }
        return [$trades, $eq, $maxDD];
    }
    } // function_exists

    $out['variants'][$bar] = [];
    foreach ($VARIANTS as $vk => $vname) {
        [$trades, $eqEnd, $maxDD] = sim($vk, $barMs, $ts, $o, $h, $l, $c, $phase, $dir);
                    $tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'rev' => 0, 'tp' => 0, 'adds' => 0, 'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0];
        $holdSum = 0.0;
        foreach ($trades as $x) {
            if ($x['pnl'] > 0) $tot['win']++;
            if ($x['out'] == 'LIQ') $tot['liq']++;
            if ($x['out'] == 'TO') $tot['to']++;
            if ($x['out'] == 'REV') $tot['rev']++;
            if ($x['out'] == 'TP') $tot['tp']++;
            $tot['adds'] += $x['adds'];
            $tot['pnl'] += $x['pnl'];
            if ($x['dir'] > 0) { $tot['l_n']++; $tot['l_pnl'] += $x['pnl']; }
            if ($x['dir'] < 0) { $tot['s_n']++; $tot['s_pnl'] += $x['pnl']; }
            $holdSum += $x['holdh'];
        }
        $tot['avg_hold'] = $tot['n'] ? round($holdSum / $tot['n'], 1) : 0;
        $tot['winrate'] = $tot['n'] ? round($tot['win'] / $tot['n'] * 100, 1) : 0;
        // 月度
        $months = [];
        foreach ($trades as $x) { $m = gmdate('Y-m', $x['tout'] / 1000 + 8 * 3600); $months[$m] = ($months[$m] ?? 0) + $x['pnl']; }
        ksort($months);
        // 日级权益曲线 (事件点近似: 出场时刻的累计权益)
        $run = 0.0; $eqc = [];
        foreach ($trades as $x) { $run += $x['pnl']; $eqc[] = ['t' => $x['tout'], 'eq' => $EQ0 + $run]; }
        // 抽稀: 每隔 max(1, ceil(N/400)) 个点
        $step = max(1, (int)ceil(count($eqc) / 400));
        $eqc2 = []; for ($k = 0; $k < count($eqc); $k += $step) $eqc2[] = $eqc[$k];
        if (count($eqc)) $eqc2[] = end($eqc);
        $out['variants'][$bar][$vk] = [
            'name' => $vname, 'summary' => $tot, 'eq_end' => round($eqEnd, 2), 'maxdd' => round($maxDD, 2),
            'months' => $months, 'eq' => $eqc2, 'trades' => $trades,
        ];
        echo "[$bar][$vk] n={$tot['n']} win={$tot['winrate']}% liq={$tot['liq']} to={$tot['to']} pnl=" . round($tot['pnl'], 2) . " dd=" . round($maxDD, 2) . "\n";
        flush();
    }
    echo "[$bar] done " . round(microtime(true) - $t0, 1) . "s\n"; flush();
}

$out['variant_names'] = $VARIANTS;
file_put_contents(__DIR__ . '/../web/td9_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "SAVED\n";
