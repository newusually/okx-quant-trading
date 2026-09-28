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
error_reporting(E_ERROR | E_PARSE); // 只报严重错误, 屏蔽 notice/warning
set_time_limit(0); // 取消执行时间限制
ini_set('memory_limit', '1024M'); // 内存上限 1G

$db = new mysqli('127.0.0.1', 'root', '', 'trading'); // 连接本地 MariaDB trading 库
$db->query("SET SESSION innodb_lock_wait_timeout=60"); // 设置锁等待超时60秒

$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 杠杆100x/维持保证金率0.4%/maker费0.02%/taker费0.05%/资金费0.01%每8h
$EQ0 = 500.0; $UNIT = 1.0; $TO_MS = 7 * 86400000; $liqDrop = 1.0 / $LEV - $MMR; // 起始权益500U / 每笔1U保证金 / 超时7天(毫秒) / 爆仓跌幅≈0.6%
$BARS = ['3m' => 180000, '5m' => 300000, '15m' => 900000, '1h' => 3600000, '4h' => 14400000]; // 五个对照周期及其毫秒数
$YEAR_MS = 365 * 86400000; // 一年毫秒数(数据取近一年)
const TOP = 1; const BOT = -1; // 顶序列/底序列方向常量

function bj($ms) { return gmdate('Y-m-d H:i', $ms / 1000 + 8 * 3600); } // 毫秒时间戳转北京时间字符串

$out = ['generated' => date('Y-m-d H:i'), 'inst' => 'ETH-USDT-SWAP', 'span' => [], 'freq' => [], 'variants' => [], 'res' => []]; // 输出容器: 生成时间/标的/各周期跨度/计数频次/变体结果

$VARIANTS = [ // 四种玩法变体定义
    'A' => '九9反转(底9开多/顶9开空, 反向九9平)', // A: 九9反转
    'B' => '九9+确认(收盘破信号根高/低点才进)', // B: 九9+确认
    'C' => '九6~九9分批(6/7/8/9每根+0.25U建满1U)', // C: 分批建仓
    'D' => '九9反转+固定止盈±2%(sixchipall口径)', // D: 九9反转+止盈
];

foreach ($BARS as $bar => $barMs) { // 逐周期回测
    $t0 = microtime(true); // 计时起点
    $r = $db->query("SELECT candle_time,o,h,l,c FROM `kline_eth_usdt_swap_$bar` // 取该周期近一年K线
                     WHERE candle_time >= " . ((time() * 1000) - $YEAR_MS) . " ORDER BY candle_time ASC"); // 按时间升序
    $ts = $o = $h = $l = $c = []; // 初始化数组
    while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; } // 逐行转类型
    $n = count($c); // K线数
    $out['span'][$bar] = ['from' => bj($ts[0]), 'to' => bj($ts[$n - 1]), 'bars' => $n]; // 记录该周期数据跨度
    if ($n < 200) { echo "$bar data too short\n"; continue; } // 数据太短跳过该周期

    // ---- TD 九转计数 (与 sigstore.go 逐字同口径, 从 i=108 起与库一致) ----
    $phase = array_fill(0, $n, 0); $dir = array_fill(0, $n, 0); // 每根的TD计数与方向
    $tdUp = 0; $tdDown = 0; // 顶/底序列连续计数器
    $cnt = [5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0]; // (预留)计数统计
    for ($i = 108; $i < $n; $i++) { // 从第109根开始(与库一致)
        if ($c[$i] > $c[$i - 4]) $tdUp++; else $tdUp = 0; // 收>4根前收盘→顶序列+1, 否则清零
        if ($c[$i] < $c[$i - 4]) $tdDown++; else $tdDown = 0; // 收<4根前收盘→底序列+1, 否则清零
        // 与 sigstore.go 同口径: phase 用未封顶的原始计数, 仅 5..9 恰好区间标注(10+ 不标)
        if ($tdUp >= 5)      { $p = $tdUp;  $d = TOP; } // 顶序列计数≥5
        elseif ($tdDown >= 5) { $p = $tdDown; $d = BOT; } // 底序列计数≥5
        else { $p = 0; $d = 0; } // 无有效序列
        if ($p >= 5 && $p <= 9) { $phase[$i] = $p; $dir[$i] = $d; } // 仅5~9标注(九5~九9)
    }
    $cnt2 = [5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0]; // 各计数出现频次
    for ($i = 108; $i < $n; $i++) if ($phase[$i] >= 5) $cnt2[$phase[$i]]++; // 统计频次
    $top9 = 0; $bot9 = 0; // 顶九9/底九9次数
    for ($i = 108; $i < $n; $i++) { if ($phase[$i] == 9 && $dir[$i] == TOP) $top9++; if ($phase[$i] == 9 && $dir[$i] == BOT) $bot9++; } // 统计九9次数
    $out['freq'][$bar] = ['n5' => $cnt2[5], 'n6' => $cnt2[6], 'n7' => $cnt2[7], 'n8' => $cnt2[8], 'n9' => $cnt2[9], 'top9' => $top9, 'bot9' => $bot9]; // 记录频次

    // ---- 模拟 ----
    /**
     * $mode: A|B|C
     * 返回 [trades, eqEnd, maxDD]
     * trade: tin,tout,dir,mg,avg,xp,pnl,out(LIQT/REV/TO),holdh,adds
     */
    if (!function_exists('sim')) { // 防重复定义(首次循环时定义)
    function sim($mode, $barMs, $ts, $o, $h, $l, $c, $phase, $dir) { // TD九转逐根状态机模拟
        global $LEV, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $EQ0, $UNIT, $TO_MS, $liqDrop; // 全局参数
        $n = count($c); // K线数
        $eq = $EQ0; $peak = $eq; $maxDD = 0.0; // 权益/峰值/最大回撤
        $trades = []; $i = 108; // 交易数组/起始下标
        // 逐根状态机
        $pos = null; // ['dir'=>+1/-1,'avg','mg','notional','fee','fund','tin','adds','waitDir','sigRef'] 当前持仓状态
        $pending = null; // ['dir','ref'] 下一根开盘执行的入场
        for ($i = 108; $i < $n; $i++) { // 逐根推进
            // ---- 1. 持仓中: 先扫本根 liq ----
            if ($pos) { // 有持仓
                $p =& $pos; // 引用持仓
                $liqPx = $p['dir'] > 0 ? $p['avg'] * (1 - $liqDrop) : $p['avg'] * (1 + $liqDrop); // 按方向计算爆仓价
                $hit = $p['dir'] > 0 ? ($l[$i] <= $liqPx) : ($h[$i] >= $liqPx); // 多头看最低/空头看最高是否触爆仓线
                $p['fund'] += ($p['dir'] > 0 ? 1 : -1) * $p['notional'] * $FUND8H / 32; // 计提资金费(空头收反号)
                if ($hit) { // 爆仓
                    $xp = $liqPx; // 以爆仓价出场
                    $pnl = ($xp / $p['avg'] - 1) * $p['dir'] * $p['notional'] - $p['fee'] - $p['fund'] - $p['notional'] * $FEE_TAKER; // 净盈亏含taker平仓费
                    $eq += $pnl; // 权益结转
                    $trades[] = ['tin' => $p['tin'], 'tout' => $ts[$i], 'dir' => $p['dir'], 'mg' => $p['mg'], 'avg' => $p['avg'], 'xp' => $xp, 'pnl' => $pnl, 'out' => 'LIQ', 'holdh' => round(($ts[$i] - $p['tin']) / 3600000, 1), 'adds' => $p['adds']]; // 记录爆仓交易
                    unset($p); $pos = null; $pending = null; // 清空持仓与待执行
                } else {
                    // ---- 2. 出场信号: 反向九9 / 超时 / [D组]止盈±2% ----
                    $tpHit = false; // 止盈触发标志
                    if ($mode == 'D') { // 仅D组有固定止盈
                        $tpPx = $p['dir'] > 0 ? $p['avg'] * 1.02 : $p['avg'] * 0.98; // 止盈价=均价±2%
                        $tpHit = $p['dir'] > 0 ? ($h[$i] >= $tpPx) : ($l[$i] <= $tpPx); // 是否触及
                    }
                    $rev = ($p['dir'] > 0 && $phase[$i] == 9 && $dir[$i] == TOP) || ($p['dir'] < 0 && $phase[$i] == 9 && $dir[$i] == BOT); // 反向九9出现→平仓信号
                    $to = ($ts[$i] - $p['tin']) >= $TO_MS; // 持仓超7天?
                    if ($tpHit || $rev || $to) { // 任一出场条件成立
                        $xp = $tpHit ? ($p['dir'] > 0 ? $p['avg'] * 1.02 : $p['avg'] * 0.98) : $c[$i]; // 止盈按止盈价, 其余按本根收盘
                        $pnl = ($xp / $p['avg'] - 1) * $p['dir'] * $p['notional'] - $p['fee'] - $p['fund'] - $p['notional'] * $FEE_TAKER; // 净盈亏
                        $eq += $pnl; // 权益结转
                        $trades[] = ['tin' => $p['tin'], 'tout' => $ts[$i], 'dir' => $p['dir'], 'mg' => $p['mg'], 'avg' => $p['avg'], 'xp' => $xp, 'pnl' => $pnl, 'out' => $tpHit ? 'TP' : ($rev ? 'REV' : 'TO'), 'holdh' => round(($ts[$i] - $p['tin']) / 3600000, 1), 'adds' => $p['adds']]; // 记录交易(区分TP/REV/TO)
                        unset($p); $pos = null; // 清空持仓
                        // 反手: 反向九9 触发出场时, A/C 下一根开盘反手开仓(由本根 pending 设置, 见下)
                    } elseif ($mode == 'C' && $p['mg'] < $UNIT && $phase[$i] >= 6 && $phase[$i] <= 9 // C组分批补仓: 未建满+计数6~9
                              && (($p['dir'] > 0 && $dir[$i] == BOT) || ($p['dir'] < 0 && $dir[$i] == TOP))) { // 且序列方向与持仓同向
                        // C: 分批补足 (信号根收盘价成交)
                        $add = min(0.25, $UNIT - $p['mg']); // 本轮补0.25U(封顶建满1U)
                        $p['avg'] = ($p['avg'] * $p['notional'] + $c[$i] * $add * $LEV) / ($p['notional'] + $add * $LEV); // 按收盘价加权更新均价
                        $p['notional'] += $add * $LEV; $p['mg'] += $add; $p['adds']++; // 仓位/保证金/轮数更新
                        $p['fee'] += $add * $LEV * $FEE_MAKER; // 补仓计 maker 费
                    }
                }
            }
            // ---- 3. 待执行入场 (上一根信号 → 本根开盘; B组确认等待态有 ref 键不入场) ----
            if (!$pos && $pending && !isset($pending['ref']) && $i + 0 == $i) { // 有待执行且非B确认等待态
                $d = $pending['dir']; // 取方向
                $ep = $o[$i]; $mg = ($mode == 'C') ? 0.25 : $UNIT; // 入场价=本根开盘; C组首仓0.25U其余1U
                $pos = ['dir' => $d, 'avg' => $ep, 'mg' => $mg, 'notional' => $mg * $LEV, 'fee' => $mg * $LEV * $FEE_MAKER, 'fund' => 0.0, 'tin' => $ts[$i], 'adds' => 0]; // 建仓
                $pending = null; // 清空待执行
            }
            // ---- 4. 平仓后/空仓时: 依据本根信号设 pending ----
            if (!$pos && !$pending) { // 空仓且无待执行
                if ($phase[$i] == 9) { // 本根恰为九9
                    if ($dir[$i] == BOT && ($mode != 'B')) $pending = ['dir' => 1];                    // A/C: 底9 → 多
                    if ($dir[$i] == TOP && ($mode != 'B')) $pending = ['dir' => -1];                   // A/C: 顶9 → 空
                    if ($mode == 'B') $pending = ['dir' => ($dir[$i] == BOT ? 1 : -1), 'ref' => $i];   // B: 等确认
                }
            }
            // ---- 5. B: 确认触发 (持仓等待确认: 收盘破信号根高/低点 → 下一根开盘进) ----
            if (!$pos && $pending && isset($pending['ref'])) { // B组确认等待态
                $ri = $pending['ref']; $d = $pending['dir']; // 信号根下标与方向
                $ok = $d > 0 ? ($c[$i] > $h[$ri]) : ($c[$i] < $l[$ri]); // 收盘突破信号根高点(多)/低点(空)即确认
                if ($ok) { $pending = ['dir' => $d]; } // 确认通过→改为次根开盘入场
                elseif ($phase[$i] == 9) { $pending = ['dir' => ($dir[$i] == BOT ? 1 : -1), 'ref' => $i]; } // 刷新信号
            }
            // ---- 6. C: 空仓时序列分批首仓也在信号根收盘直接建(不走次根开盘), 保持与持仓中补仓一致 ----
            if (!$pos && !$pending && $mode == 'C' && $phase[$i] >= 6 && $phase[$i] <= 9) { // C组空仓遇计数6~9
                $d = $dir[$i] == BOT ? 1 : -1; // 底序列→多, 顶序列→空
                $ep = $c[$i]; // 以信号根收盘价建仓
                $pos = ['dir' => $d, 'avg' => $ep, 'mg' => 0.25, 'notional' => 0.25 * $LEV, 'fee' => 0.25 * $LEV * $FEE_MAKER, 'fund' => 0.0, 'tin' => $ts[$i], 'adds' => 0]; // 首仓0.25U
            }
            // ---- 权益峰值/回撤 ----
            if ($eq > $peak) $peak = $eq; // 更新峰值
            $dd = $peak - $eq; // 当前回撤
            if ($dd > $maxDD) $maxDD = $dd; // 更新最大回撤
            if ($eq <= 0) { $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$i], 'dir' => 0, 'mg' => 0, 'avg' => 0, 'xp' => 0, 'pnl' => -$EQ0, 'out' => 'BANKRUPT', 'holdh' => 0, 'adds' => 0]; break; } // 权益归零记BANKRUPT并终止
        }
        return [$trades, $eq, $maxDD]; // 返回逐笔/期末权益/最大回撤
    }
    } // function_exists

    $out['variants'][$bar] = []; // 该周期变体结果容器
    foreach ($VARIANTS as $vk => $vname) { // 逐变体回测
        [$trades, $eqEnd, $maxDD] = sim($vk, $barMs, $ts, $o, $h, $l, $c, $phase, $dir); // 跑模拟
                    $tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'rev' => 0, 'tp' => 0, 'adds' => 0, 'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0]; // 汇总容器
        $holdSum = 0.0; // 持仓时长累计
        foreach ($trades as $x) { // 逐笔统计
            if ($x['pnl'] > 0) $tot['win']++; // 盈利笔数
            if ($x['out'] == 'LIQ') $tot['liq']++; // 爆仓
            if ($x['out'] == 'TO') $tot['to']++; // 超时
            if ($x['out'] == 'REV') $tot['rev']++; // 反向九9平仓
            if ($x['out'] == 'TP') $tot['tp']++; // 止盈
            $tot['adds'] += $x['adds']; // 加仓轮数
            $tot['pnl'] += $x['pnl']; // 总盈亏
            if ($x['dir'] > 0) { $tot['l_n']++; $tot['l_pnl'] += $x['pnl']; } // 多头笔数与盈亏
            if ($x['dir'] < 0) { $tot['s_n']++; $tot['s_pnl'] += $x['pnl']; } // 空头笔数与盈亏
            $holdSum += $x['holdh']; // 持仓时长累加
        }
        $tot['avg_hold'] = $tot['n'] ? round($holdSum / $tot['n'], 1) : 0; // 平均持仓小时
        $tot['winrate'] = $tot['n'] ? round($tot['win'] / $tot['n'] * 100, 1) : 0; // 胜率(%)
        // 月度
        $months = []; // 月度盈亏
        foreach ($trades as $x) { $m = gmdate('Y-m', $x['tout'] / 1000 + 8 * 3600); $months[$m] = ($months[$m] ?? 0) + $x['pnl']; } // 按出场月累加
        ksort($months); // 按月排序
        // 日级权益曲线 (事件点近似: 出场时刻的累计权益)
        $run = 0.0; $eqc = []; // 累计盈亏/曲线点
        foreach ($trades as $x) { $run += $x['pnl']; $eqc[] = ['t' => $x['tout'], 'eq' => $EQ0 + $run]; } // 每笔出场记录权益
        // 抽稀: 每隔 max(1, ceil(N/400)) 个点
        $step = max(1, (int)ceil(count($eqc) / 400)); // 抽稀步长(最多400点)
        $eqc2 = []; for ($k = 0; $k < count($eqc); $k += $step) $eqc2[] = $eqc[$k]; // 等间隔抽点
        if (count($eqc)) $eqc2[] = end($eqc); // 补最后一个点
        $out['variants'][$bar][$vk] = [ // 保存该变体结果
            'name' => $vname, 'summary' => $tot, 'eq_end' => round($eqEnd, 2), 'maxdd' => round($maxDD, 2), // 名称/汇总/期末权益/最大回撤
            'months' => $months, 'eq' => $eqc2, 'trades' => $trades, // 月度/权益曲线/逐笔
        ];
        echo "[$bar][$vk] n={$tot['n']} win={$tot['winrate']}% liq={$tot['liq']} to={$tot['to']} pnl=" . round($tot['pnl'], 2) . " dd=" . round($maxDD, 2) . "\n"; // 输出该变体进度
        flush(); // 立即刷新
    }
    echo "[$bar] done " . round(microtime(true) - $t0, 1) . "s\n"; flush(); // 输出该周期耗时
}

$out['variant_names'] = $VARIANTS; // 变体名称表
file_put_contents(__DIR__ . '/../web/td9_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入前端数据JSON
echo "SAVED\n"; // 完成提示
