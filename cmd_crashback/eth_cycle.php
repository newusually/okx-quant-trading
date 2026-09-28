<?php
/**
 * eth_cycle.php — 先减仓后加仓 / 循环减仓加仓直到盈利 对比回测(CLI)
 * 本脚本测什么: ETH 1h 主时间轴上对比5种出场变体(A/F/G/H/I), 全部基于六重共振只买涨 + TP+2% + 50%兜底强平 + 100x:
 *   A 现行: fib618仅上行加仓≤5轮, 下跌不减仓
 *   F 先减仓后加仓: 跌破回撤38.2/50/61.8各减1/3(每档一次), 之后仍可fib618加仓
 *   G 循环减仓加仓: 跌破61.8回撤档减半, 回升突破档价加回, 无限循环, fib加仓关闭
 *   H 循环直到盈利: 同G但无7天超时(最长30天), 只有止盈/兜底/全减完离场
 *   I G的循环 + fib618加仓≤5轮
 * 参数: 1U+3U/天 与 20U+3U/天 两档保证金阶梯; 余额500U。
 * 输出: 5变体×2阶梯的汇总统计 + 1U/20U 关键变体逐笔明细, 写 web/eth_cycle_data.json。
 */
ini_set('memory_limit', '1024M');                   // 内存上限提高到1024M(K线数组+逐笔明细)
set_time_limit(0);                                  // 取消脚本执行时间限制
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 全局参数: 100x杠杆/止盈2%/维持保证金率0.4%/maker费0.02%/taker费0.05%/资金费0.01%每8h

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接本地trading库(UTF8)
$DB = db();                                         // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; } // 执行SQL并返回行数组
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; } // 滑动窗口均线(增量法), 前 n-1 根为 null
function fibsig($c, $w = 30) {                      // fib618黄金回调信号: 30根窗口高低点的61.8%回调位附近(±3%)
    $n = count($c); $out = array_fill(0, $n, false); // 初始化输出信号数组
    for ($i = $w - 1; $i < $n; $i++) {              // 从第30根开始逐根计算
        $lo = INF; $hi = -INF;                      // 窗口最低/最高价
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; } // 求30根收盘的最小/最大值
        if ($hi <= $lo) continue;                   // 无波动则跳过
        $fib = $hi - ($hi - $lo) * 0.618;           // 计算61.8%黄金回调位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true; // 收盘落在回调位±3%区间 → 信号命中
    }
    return $out;                                    // 返回布尔信号序列
}
function load_k($inst, $bar) {                      // 从K线表加载全部数据(列名o/h/l/c/vol)
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC"); // 按时间升序读取
    $ts = []; $o = []; $h = []; $l = []; $c = []; $v = []; // 各列数组
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; } // 拆列转类型
    return [$ts, $o, $h, $l, $c, $v];               // 返回六元组
}
function buy_ratio_series($o, $h, $l, $c) {         // taker买比代理: 用1h K线方向(收-开)/振幅 映射到0~1
    $n = count($c); $br = [];                       // 初始化
    for ($i = 0; $i < $n; $i++) {                   // 逐根计算
        $rng = $h[$i] - $l[$i];                     // 本根振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0; // 方向占比(-1~1), 无振幅为0
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1; // 钳制到[-1,1]
        $br[$i] = 0.5 + $dir / 2;                   // 映射到[0,1], 0.5为中性
    }
    return $br;                                     // 返回买比序列
}

[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h'); // 加载ETH 1h K线(主时间轴)
[$ts4h, , , , $c4h, ] = load_k('eth', '4h');        // 加载ETH 4h收盘价(条件④用)
$n = count($ts1h);                                  // 1h总根数
$up = []; $tot = [];                                // 市场宽度: 每日收盘≥MA20的币数 / 有MA20的币数
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time"); // 读取全市场日线收盘
$cur = null; $win = []; $s = 0.0;                   // 当前币种/20日滑动窗口/窗口和
foreach ($rr as $x) {                               // 逐行扫描日线
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; } // 换币时重置窗口
    $win[] = (float)$x[2]; $s += (float)$x[2];      // 收盘入窗并累加
    if (count($win) > 20) $s -= array_shift($win);  // 窗口超过20日则移除最旧值
    if (count($win) == 20) {                        // 凑满20日才有MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2]; // 日期/MA20/当日收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0); // 收盘≥MA20 计为"站上均线的币"
        $tot[$t] = ($tot[$t] ?? 0) + 1;             // 当日有效币总数
    }
}
$brSrc = [];                                        // 市场宽度(比例)源数据: 日期→占比
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k; // 有效币>10才计算占比(避免小样本)
$sorted = array_keys($brSrc); sort($sorted);        // 日期升序数组(二分查找用)
function ffillBr($t) {                              // 前向填充: 取≤t的最近一天的宽度占比
    global $brSrc, $sorted;                         // 使用全局源数据
    $lo = 0; $hi = count($sorted) - 1; $res = null; // 二分边界与结果
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; } // 标准二分找≤t的最大日期
    return $res !== null ? $brSrc[$res] : null;     // 返回该日占比(无则null)
}
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);         // ETH 1h MA20/MA10(条件②③)
$ma5_4h = ma($c4h, 5);                              // ETH 4h MA5(条件④)
$tr4hUp = [];                                       // 4h收盘>MA5 的布尔表: 时间戳→true/false
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hUp[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i]; // 有MA5时判定趋势向上
$tr4hUpF = []; $p4 = 0; $cur4 = null; $n4 = count($ts4h); // 1h对4h条件的前向填充/游标/当前状态/4h根数
foreach ($ts1h as $tt) { while ($p4 < $n4 && $ts4h[$p4] <= $tt) { $cur4 = $tr4hUp[$ts4h[$p4]] ?? $cur4; $p4++; } $tr4hUpF[$tt] = $cur4; } // 双指针填充: 每1h时刻取最近4h趋势
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h);   // ETH 1h买比代理(条件⑥加仓用)
$fib = fibsig($c1h);                                // ETH 1h fib618回调信号(加仓用)
$entryA = [];                                       // 六重共振入场时刻表(条件⑤熔断此处未纳入, ETH单币方案)
for ($i = 30; $i < $n; $i++) {                      // 从第31根开始判定(保证均线就绪)
    $t = $ts1h[$i];                                 // 当前1h时间戳
    $b = ffillBr($t);                               // 市场宽度(条件①)
    if ($b !== null && $b > 0.5 && $ma20[$i] !== null && $c1h[$i] > $ma20[$i] && // ①宽度>50% 且 ②1h>MA20
        $ma10[$i] !== null && $c1h[$i] > $ma10[$i] && !empty($tr4hUpF[$t])) $entryA[$t] = true; // ③1h>MA10 且 ④4h趋势向上 → 记入场
}
echo "entry=" . count($entryA) . "\n"; flush();     // 打印入场信号总数并立即输出

// ===== sim: addsMax(加仓轮数) mode(0=不减 1=先减后加 2=循环减加 3=循环减加直到盈利[30天上限]) =====
function sim($o, $h, $l, $c, $ts, $fib, $entry, $addsMax, $mode, $lbase) { // 单变体模拟: 参数=K线/信号/入场表/加仓上限/模式/保证金基数
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H; // 引用全局参数
    $liqDrop = 1.0 / $LEV - $MMR;                   // 交易所爆仓价跌幅≈0.6%(100x)
    $bal = 500.0;                                   // 起始余额500U
    $startT = $ts[30];                              // 回测起点时间(保证金递增的天数基准)
    $trades = []; $n = count($ts); $i = 30;         // 逐笔容器/根数/游标
    $cycleMode = ($mode >= 2);                      // 是否循环减加模式(G/H/I)
    $maxHold = ($mode == 3) ? 30 * 24 : 7 * 24;     // 最长持仓: H为30天, 其余7天
    while ($i < $n - 1) {                           // 主循环: 逐入场信号开仓
        if (empty($entry[$ts[$i]])) { $i++; continue; } // 非入场信号时刻跳过
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400); // 距回测起点已过整天数
        $M = round(min($lbase + 3.0 * $days, $bal / 6.0), 2); // 每笔保证金 = 基数+3U×天数, 封顶余额/6
        if ($M < 1) $M = 1.0;                       // 保底1U
        $M0 = $M;                                   // 记录开仓保证金(加仓额基准)
        $e = $o[$i + 1];                            // 下一根开盘价成交(无未来函数)
        $notional = $M0 * $LEV; $notional0 = $notional; $avg = $e; $adds = 0; $reds = 0; $addbacks = 0; $cycles = 0; // 初始名义/原始名义/均价/加仓数/减仓数/加回数/循环次数
        $fee = $notional * $FEE_MAKER;              // 开仓maker手续费
        $realized = 0.0; $funding = 0.0;            // 减仓已实现盈亏/累计资金费
        $liqPx = $avg * (1 - $liqDrop);             // 交易所爆仓价(下方)
        $tgtPx = $avg * (1 + $TPR);                 // 止盈价 = 均价+2%
        $stopPx = $avg * (1 - 0.5 / $LEV);              // 50%兜底(自设强平)   // 兜底强平价 = 均价-0.5%(浮亏50%保证金)
        $hi = $e; $usedLvl = [false, false, false]; $lvlPx = [0.0, 0.0, 0.0]; // 入场后最高价/三档是否已用/各档位价格
        $outcome = null; $exitPx = null; $j = $i + 1; // 出场原因/出场价/逐根游标
        $redLvls = [0.382, 0.5, 0.618];             // 三档回撤比(38.2%/50%/61.8%)
        while ($j < $n) {                           // 持仓逐根推进
            $barsHeld = $j - $i;                    // 已持仓根数
            $funding += $notional * $FUND8H / 8;         // 每1h根计提资金费   // 每根计提1/8个8h资金费
            $hitCut = $l[$j] <= $stopPx;            // 本根最低触及兜底强平价?
            $hitLiq = $l[$j] <= $liqPx;             // 本根最低触及交易所爆仓价?
            $hitTp  = $h[$j] >= $tgtPx;             // 本根最高触及止盈价?
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; } // 兜底强平离场
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 爆仓离场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; } // 止盈离场
            if ($barsHeld >= $maxHold) { $outcome = 'TO'; $exitPx = $c[$j]; break; } // 超时收盘离场
            // ---- 下跌 fib 减仓 / 循环减加 ----
            if ($mode > 0 && $notional > 0) {       // 有减仓机制且有持仓时
                if ($h[$j] > $hi) $hi = $h[$j];     // 更新入场后最高价
                if ($hi >= $avg * 1.005) {          // 最高价至少高出均价0.5%才启用回撤档
                    $rng = $hi - $avg;              // 回撤基准区间
                    if ($cycleMode) {
                        // 循环模式: 单档61.8回撤位, 跌破→减半; 回升突破→原档价加回(见下), 无限循环
                        $lvl = $hi - $rng * 0.618;  // 单档61.8%回撤位
                        if (!$usedLvl[0] && $l[$j] <= $lvl) { // 未减过且本根跌破档位
                            $usedLvl[0] = true; $lvlPx[0] = $lvl; // 标记已减并记录档价
                            $sellPx = $o[$j + 1] ?? $c[$j];   // 下一根开盘价卖出
                            $sellNot = $notional * 0.5;       // 减当前持仓的一半
                            $realized += ($sellPx - $avg) * $sellNot / $avg; // 累计减仓已实现盈亏
                            $fee += $sellNot * $FEE_TAKER;    // 减仓taker手续费
                            $notional -= $sellNot; $reds++;   // 名义减少/减仓计数
                        }
                    } else {
                        foreach ($redLvls as $k => $r) { // 三档各检查一次
                            $lvl = $hi - $rng * $r;  // 该档回撤位价格
                            if (!$usedLvl[$k] && $l[$j] <= $lvl) { // 该档未用且跌破
                                $usedLvl[$k] = true; $lvlPx[$k] = $lvl; // 标记并记录档价
                                $sellPx = $o[$j + 1] ?? $c[$j];   // 下一根开盘卖出
                                $sellNot = $notional0 / 3.0;      // 减原始名义的1/3
                                if ($sellNot > $notional) $sellNot = $notional; // 不超过当前持仓
                                $realized += ($sellPx - $avg) * $sellNot / $avg; // 累计已实现盈亏
                                $fee += $sellNot * $FEE_TAKER;    // 减仓taker费
                                $notional -= $sellNot; $reds++;   // 名义减少/减仓计数
                                if ($notional < $notional0 * 0.02) { $outcome = 'FIB'; $exitPx = $sellPx; $notional = 0; break 2; } // 减到不足2%视为fib全减离场
                            }
                        }
                    }
                }
            }
            // ---- 循环加回: 价格回升突破已减档位 → 原档价加回半仓 ----
            if ($cycleMode) {                        // 仅循环模式(G/H/I)
                if ($usedLvl[0] && $notional < $notional0 * 0.999 && $h[$j] >= $lvlPx[0]) { // 已减过且回升触及档价
                    $buyPx = $lvlPx[0];              // 以原档价加回(限位单假设)
                    $addNot = $notional0 - $notional; // 加回减掉的份额
                    $avg = ($avg * $notional + $buyPx * $addNot) / ($notional + $addNot); // 重算持仓均价
                    $notional = $notional0;          // 名义恢复到原始值
                    $fee += $addNot * $FEE_TAKER;    // 加回taker费
                    $usedLvl[0] = false; $addbacks++; $cycles++; // 档位复位/计数(可再次减)
                    $liqPx = $avg * (1 - $liqDrop);  // 重算爆仓价
                    $tgtPx = $avg * (1 + $TPR);      // 重算止盈价
                    $stopPx = $avg * (1 - 0.5 / $LEV); // 重算兜底价
                }
            }
            // ---- 上行 fib618 加仓 ----
            if ($addsMax > 0 && $adds < $addsMax && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) { // 允许加仓且fib信号命中且现价高于均价(仅上行)
                $ap = $o[$j + 1];                    // 下一根开盘价加仓
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV); // 重算均价
                $notional += $M0 * $LEV; $adds++;    // 名义增加一轮/加仓计数
                $fee += $M0 * $LEV * $FEE_TAKER;     // 加仓taker费
                $liqPx = $avg * (1 - $liqDrop);      // 重算爆仓价
                $tgtPx = $avg * (1 + $TPR);          // 重算止盈价
                $stopPx = $avg * (1 - 0.5 / $LEV);   // 重算兜底价
            }
            $j++;                                    // 推进到下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; } // 数据尽头强制按超时收盘离场
        $gross = ($exitPx - $avg) * $notional / $avg; // 剩余持仓的浮动盈亏(出场价vs均价)
        $pnl = $realized + $gross - $fee - $funding;  // 净盈亏 = 已实现+浮动-手续费-资金费
        $mg = $M0 * (1 + $adds);                      // 该笔总保证金(含加仓)
        if ($pnl < -$mg) $pnl = -$mg;                 // 亏损钳制在保证金内(逐仓最多亏保证金)
        $bal = round($bal + $pnl, 2);                 // 结转到余额
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds, 'reds' => $reds, // 记录逐笔: 入/出场时间/出场原因/加仓/减仓
                     'cyc' => $cycles, 'margin' => $mg, 'pnl' => round($pnl, 3), 'bal' => $bal]; // 循环次数/总保证金/盈亏/余额
        if ($bal <= 0) break;                         // 余额归零则停止回测
        $i = $j + 1;                                  // 跳到出场后下一根(无重叠仓位)
    }
    return [$trades, $bal];                           // 返回逐笔与最终余额
}
function summ($trades, $bal) {                        // 汇总统计函数
    $w = 0; $cut = 0; $lq = 0; $fb = 0; $to = 0; $pl = 0.0; $cum = 0.0; $peak = 0.0; $maxDD = 0.0; $maxLoss = 0.0; // 各计数器: 胜/兜底/爆仓/fib离场/超时/总盈亏/累计/峰值/最大回撤/最大单笔亏损
    $reds = 0; $cyc = 0; $fbWinAfter = 0;             // 总减仓数/总循环数/fib离场中盈利的笔数
    foreach ($trades as $t) {                         // 逐笔统计
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'CUT') $cut++; elseif ($t['out'] == 'LIQ') $lq++; // 按出场原因计数
        elseif ($t['out'] == 'FIB') { $fb++; if ($t['pnl'] > 0) $fbWinAfter++; } else $to++; // fib离场计数(含其中盈利数)
        $reds += $t['reds']; $cyc += $t['cyc'];       // 累计减仓/循环次数
        $pl += $t['pnl']; $cum += $t['pnl'];          // 累计盈亏
        if (-$t['pnl'] > $maxLoss) $maxLoss = -$t['pnl']; // 更新最大单笔亏损
        if ($cum > $peak) $peak = $cum;               // 更新累计盈利峰值
        if ($peak - $cum > $maxDD) $maxDD = $peak - $cum; // 更新最大回撤
    }
    $n = count($trades);                              // 总笔数
    return ['n' => $n, 'win' => $w, 'fibout' => $fb, 'fib_win' => $fbWinAfter, 'cut' => $cut, 'liq' => $lq, // 返回统计数组
            'reds' => $reds, 'cyc' => $cyc,
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2), // 胜率/总盈亏
            'final_bal' => round($bal, 2), 'max_dd' => round($maxDD, 2), // 最终余额/最大回撤
            'max_loss' => round($maxLoss, 2), 'ev' => $n ? round($pl / $n, 3) : 0]; // 最大亏损/单笔期望
}

$variants = [                                         // 5种出场变体定义
    ['a' => 5, 'm' => 0, 'name' => 'A 现行: 加仓≤5轮·不减仓'], // A: 加仓5轮, 不减仓
    ['a' => 5, 'm' => 1, 'name' => 'F 先减仓(三档各1/3)后加仓≤5轮'], // F: 三档减仓+加仓
    ['a' => 0, 'm' => 2, 'name' => 'G 循环减仓加仓(跌破61.8减半·回升原档加回·无限循环)'], // G: 循环减加
    ['a' => 0, 'm' => 3, 'name' => 'H 循环减仓加仓直到盈利(不设7天超时, 最长30天)'], // H: 循环直到盈利
    ['a' => 5, 'm' => 2, 'name' => 'I 循环减仓加仓 + fib618加仓≤5轮'], // I: 循环+加仓
];
$ladders = [['base' => 1.0, 'name' => '1U+3U/天'], ['base' => 20.0, 'name' => '20U+3U/天']]; // 两种保证金阶梯
$results = []; $detail = [];                          // 汇总结果/各变体末50笔明细
foreach ($ladders as $ld) {                           // 外层: 阶梯
    foreach ($variants as $v) {                       // 内层: 变体
        [$tr, $bal] = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $v['a'], $v['m'], $ld['base']); // 跑模拟
        $sm = summ($tr, $bal);                        // 汇总统计
        $key = $ld['base'] . '_' . $v['a'] . '_' . $v['m']; // 组合键 = 阶梯_加仓_模式
        $results[] = ['key' => $key, 'ladder' => $ld['name'], 'name' => $v['name']] + $sm; // 合并入结果表
        $detail[$key] = array_slice($tr, -50);        // 保存末50笔明细
        echo "{$ld['name']} {$v['name']}: " . json_encode($sm, JSON_UNESCAPED_UNICODE) . "\n"; flush(); // 打印进度
    }
}
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400; // 回测跨度天数
function detOut($tr) {                                // 逐笔明细格式化(毫秒转北京时间字符串)
    $out = [];
    foreach ($tr as $t) $out[] = ['tin' => date('m-d H:i', (int)($t['tin'] / 1000)),
        'tout' => date('m-d H:i', (int)($t['tout'] / 1000)), 'out' => $t['out'], 'adds' => $t['adds'], // 时间/原因/加仓
        'reds' => $t['reds'], 'cyc' => $t['cyc'], 'margin' => $t['margin'], 'pnl' => $t['pnl'], 'bal' => $t['bal']]; // 减仓/循环/保证金/盈亏/余额
    return $out;
}
$out = [                                              // 组装输出JSON
    'params' => ['lev' => 100, 'bal0' => 500, 'tp' => '价格+2%', 'backstop' => '自设强平兜底50%保证金', // 参数说明
                 'fibReduce' => 'hi=入场后最高价, hi≥均价1.005%生效; 档位=hi-(hi-均价)×(38.2%/50%/61.8%); 跌破→下一根开盘减notional0的1/3; 循环模式=回升突破该档价→原档价加回1/3(限位单假设)', // 减仓/循环口径
                 'cycleUntilWin' => 'H方案不设7天超时(最长30天), 只有止盈/兜底/全减完才离场', // H方案特殊说明
                 'span' => round($spanDays) . '天',   // 跨度
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000))], // 数据范围
    'results' => $results,                            // 全部变体汇总
    'detail_A' => detOut($detail['1_5_0']), 'detail_F' => detOut($detail['1_5_1']), // 1U阶梯A/F明细
    'detail_G' => detOut($detail['1_0_2']), 'detail_H' => detOut($detail['1_0_3']), // 1U阶梯G/H明细
    'detail_I' => detOut($detail['1_5_2']),           // 1U阶梯I明细
    'detail_A20' => detOut($detail['20_5_0']), 'detail_H20' => detOut($detail['20_0_3']), // 20U阶梯A/H明细
];
file_put_contents('E:/finally-main/web/eth_cycle_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写出JSON报告
echo "JSON OK\n";                                     // 完成提示
