<?php
/**
 * eth_fibreduce.php — 下跌按斐波那契回撤位减仓 对比回测(CLI)
 * 本脚本测什么: ETH 1h 主时间轴上对比"下跌时按fib回撤位减仓"的5种出场变体(A/B/C/D/E),
 *   全部基于六重共振只买涨 + TP+2% + 50%兜底强平 + 100x。
 * 变体(均含50%兜底强平 + 六重共振只买涨 + TP+2% + 100x):
 *   A 现行: fib618仅上行加仓≤5轮, 下跌不减仓
 *   B 不加仓: 0轮加仓, 下跌也不减
 *   C 不加仓+fib减仓: 跌破回撤38.2%/50%/61.8% 各减当前持仓1/3(自开仓均价至入场后最高点的回撤)
 *   D 加仓+fib减仓: 上行fib618加仓 与 下跌fib减仓 同时启用(动态)
 *   E 不加仓+只在61.8减半: 单档减仓
 * fib减仓口径: hi=入场后最高价, hi≥均价×1.005 才有效; 档位=hi-(hi-均价)×r; 跌破→下一根开盘减仓
 * 输出: 5变体×2阶梯汇总 + 1U阶梯A/C末50笔明细, 写 web/eth_fibreduce_data.json。
 */
ini_set('memory_limit', '1024M');                   // 内存上限1G
set_time_limit(0);                                  // 取消时间限制
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 参数: 100x/止盈2%/维持保证金/手续费/资金费

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接trading库
$DB = db();                                         // 全局连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; } // 查询行数组
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; } // 滑动均线(增量法)
function fibsig($c, $w = 30) {                      // fib618回调信号(30根窗口61.8%回调位±3%)
    $n = count($c); $out = array_fill(0, $n, false); // 初始化
    for ($i = $w - 1; $i < $n; $i++) {              // 逐根
        $lo = INF; $hi = -INF;                      // 窗口高低
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; } // 窗口极值
        if ($hi <= $lo) continue;                   // 无波动跳过
        $fib = $hi - ($hi - $lo) * 0.618;           // 61.8%回调位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true; // ±3%内命中
    }
    return $out;                                    // 返回信号
}
function load_k($inst, $bar) {                      // 加载K线
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC"); // 升序读取
    $ts = []; $o = []; $h = []; $l = []; $c = []; $v = []; // 各列
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; } // 类型转换
    return [$ts, $o, $h, $l, $c, $v];               // 返回六元组
}
function buy_ratio_series($o, $h, $l, $c) {         // 买比代理: 方向/振幅→[0,1]
    $n = count($c); $br = [];                       // 初始化
    for ($i = 0; $i < $n; $i++) {                   // 逐根
        $rng = $h[$i] - $l[$i];                     // 振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0; // 方向
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1; // 钳制
        $br[$i] = 0.5 + $dir / 2;                   // 映射
    }
    return $br;                                     // 返回序列
}

[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h'); // ETH 1h主时间轴
[$ts4h, , , , $c4h, ] = load_k('eth', '4h');        // ETH 4h收盘
$n = count($ts1h);                                  // 1h根数
$up = []; $tot = [];                                // 宽度计数器
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time"); // 全市场日线
$cur = null; $win = []; $s = 0.0;                   // 窗口状态
foreach ($rr as $x) {                               // 逐行
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; } // 换币重置
    $win[] = (float)$x[2]; $s += (float)$x[2];      // 入窗累加
    if (count($win) > 20) $s -= array_shift($win);  // 保持20
    if (count($win) == 20) {                        // 满窗口
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2]; // 时间/MA/收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0); // 站上均线计数
        $tot[$t] = ($tot[$t] ?? 0) + 1;             // 有效币数
    }
}
$brSrc = [];                                        // 宽度比例表
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k; // 有效币>10
$sorted = array_keys($brSrc); sort($sorted);        // 日期升序
function ffillBr($t) {                              // 前向填充
    global $brSrc, $sorted;                         // 全局源
    $lo = 0; $hi = count($sorted) - 1; $res = null; // 二分初始化
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; } // 找≤t
    return $res !== null ? $brSrc[$res] : null;     // 返回占比
}
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);         // 1h MA20/MA10
$ma5_4h = ma($c4h, 5);                              // 4h MA5
$tr4hUp = [];                                       // 4h趋势表
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hUp[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i]; // 收盘>MA5
$tr4hUpF = []; $p4 = 0; $cur4 = null; $n4 = count($ts4h); // 1h对4h填充
foreach ($ts1h as $tt) { while ($p4 < $n4 && $ts4h[$p4] <= $tt) { $cur4 = $tr4hUp[$ts4h[$p4]] ?? $cur4; $p4++; } $tr4hUpF[$tt] = $cur4; } // 双指针
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h);   // 1h买比
$fib = fibsig($c1h);                                // fib加仓信号
$entryA = [];                                       // 入场表
for ($i = 30; $i < $n; $i++) {                      // 逐根判六重(ETH单币无熔断条件)
    $t = $ts1h[$i];
    $b = ffillBr($t);                               // ①宽度
    if ($b !== null && $b > 0.5 && $ma20[$i] !== null && $c1h[$i] > $ma20[$i] && // ①>50% 且 ②1h>MA20
        $ma10[$i] !== null && $c1h[$i] > $ma10[$i] && !empty($tr4hUpF[$t])) $entryA[$t] = true; // ③1h>MA10 且 ④4h向上
}
echo "entry=" . count($entryA) . "\n"; flush();     // 打印入场数

// ===== sim: addsOn(加仓轮数) redMode(0=不减 1=三档 2=单档61.8减半) =====
function sim($o, $h, $l, $c, $ts, $fib, $entry, $addsMax, $redMode, $lbase) { // 单变体模拟
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H; // 全局参数
    $liqDrop = 1.0 / $LEV - $MMR;                   // 爆仓跌幅≈0.6%
    $bal = 500.0;                                   // 起始余额
    $startT = $ts[30];                              // 天数基准
    $trades = []; $n = count($ts); $i = 30;         // 初始化
    while ($i < $n - 1) {                           // 逐入场
        if (empty($entry[$ts[$i]])) { $i++; continue; } // 非入场跳过
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400); // 已过天数
        $M = round(min($lbase + 3.0 * $days, $bal / 6.0), 2); // 保证金=基数+3U/天封顶余额/6
        if ($M < 1) $M = 1.0;                       // 保底1U
        $M0 = $M;                                   // 开仓保证金
        $e = $o[$i + 1];                            // 下一根开盘成交
        $notional = $M0 * $LEV; $notional0 = $notional; $avg = $e; $adds = 0; $reds = 0; // 名义/原始名义/均价/加仓/减仓计数
        $fee = $notional * $FEE_MAKER;              // 开仓maker费
        $realized = 0.0; $funding = 0.0;            // 已实现/资金费
        $liqPx = $avg * (1 - $liqDrop);             // 爆仓价
        $tgtPx = $avg * (1 + $TPR);                 // 止盈价
        $stopPx = $avg * (1 - 0.5 / $LEV);              // 50%兜底(自设强平)   // 兜底价
        $hi = $e; $usedLvl = [false, false, false]; // 最高价/三档使用标记
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0; // 出场状态/游标/持仓根数
        while ($j < $n) {                           // 逐根推进
            $barsHeld = $j - $i;                    // 持仓根数
            $funding += $notional * $FUND8H / 8;         // 每1h根计提资金费   // 1/8资金费
            $hitCut = $l[$j] <= $stopPx;            // 触兜底?
            $hitLiq = $l[$j] <= $liqPx;             // 触爆仓?
            $hitTp  = $h[$j] >= $tgtPx;             // 触止盈?
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; } // 兜底离场
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 爆仓离场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; } // 止盈离场
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; } // 7天超时
            // ---- 下跌 fib 减仓 ----
            if ($redMode > 0 && $notional > 0) {    // 启用减仓且有持仓
                if ($h[$j] > $hi) $hi = $h[$j];     // 更新入场后最高价
                if ($hi >= $avg * 1.005) {          // 最高价高出均价0.5%才生效
                    $rng = $hi - $avg;              // 回撤基准区间
                    $levels = $redMode == 1 ? [0.382, 0.5, 0.618] : [0.618]; // 三档或单档61.8
                    foreach ($levels as $k => $r) { // 逐档检查
                        if ($usedLvl[$k]) continue; // 该档已用跳过
                        $lvl = $hi - $rng * $r;     // 该档回撤位价格
                        if ($l[$j] <= $lvl) {       // 本根跌破档位
                            $usedLvl[$k] = true;    // 标记已用
                            $sellPx = $o[$j + 1] ?? $c[$j]; // 下一根开盘卖出
                            $sellNot = ($redMode == 1) ? $notional / 3.0 : $notional / 2.0; // 三档减1/3, 单档减1/2
                            $realized += ($sellPx - $avg) * $sellNot / $avg; // 已实现盈亏
                            $fee += $sellNot * $FEE_TAKER; // 减仓taker费
                            $notional -= $sellNot; $reds++; // 名义减少/计数
                            if ($notional < $notional0 * 0.02) { $outcome = 'FIB'; $exitPx = $sellPx; $notional = 0; break 2; } // 减到不足2%→fib全减离场
                        }
                    }
                }
            }
            // ---- 上行 fib618 加仓 ----
            if ($addsMax > 0 && $adds < $addsMax && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) { // fib信号+仅上行+未满轮
                $ap = $o[$j + 1];                   // 下一根开盘加仓
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV); // 重算均价
                $notional += $M0 * $LEV; $adds++;   // 名义加一轮
                $fee += $M0 * $LEV * $FEE_TAKER;    // 加仓taker费
                $liqPx = $avg * (1 - $liqDrop);     // 重算爆仓价
                $tgtPx = $avg * (1 + $TPR);         // 重算止盈价
                $stopPx = $avg * (1 - 0.5 / $LEV);  // 重算兜底价
            }
            $j++;                                   // 下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; } // 尽头按超时
        $gross = ($exitPx - $avg) * $notional / $avg; // 剩余持仓浮动盈亏
        $pnl = $realized + $gross - $fee - $funding; // 净盈亏
        $mg = $M0 * (1 + $adds);                    // 总保证金
        if ($pnl < -$mg) $pnl = -$mg;               // 亏损钳制
        $bal = round($bal + $pnl, 2);               // 结转余额
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds, 'reds' => $reds, // 逐笔记录
                     'margin' => $mg, 'pnl' => round($pnl, 3), 'bal' => $bal];
        if ($bal <= 0) break;                       // 余额归零停止
        $i = $j + 1;                                // 跳到出场后
    }
    return [$trades, $bal];                         // 返回逐笔与余额
}
function summ($trades, $bal) {                      // 汇总统计
    $w = 0; $cut = 0; $lq = 0; $fb = 0; $to = 0; $pl = 0.0; $cum = 0.0; $peak = 0.0; $maxDD = 0.0; $maxLoss = 0.0; $reds = 0; // 计数器
    foreach ($trades as $t) {                       // 逐笔
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'CUT') $cut++; elseif ($t['out'] == 'LIQ') $lq++; // 按出场计数
        elseif ($t['out'] == 'FIB') $fb++; else $to++; // fib离场/超时
        $reds += $t['reds'];                        // 减仓累计
        $pl += $t['pnl']; $cum += $t['pnl'];        // 盈亏累计
        if (-$t['pnl'] > $maxLoss) $maxLoss = -$t['pnl']; // 最大单笔亏损
        if ($cum > $peak) $peak = $cum;             // 峰值
        if ($peak - $cum > $maxDD) $maxDD = $peak - $cum; // 最大回撤
    }
    $n = count($trades);                            // 笔数
    return ['n' => $n, 'win' => $w, 'fibout' => $fb, 'cut' => $cut, 'liq' => $lq, 'reds' => $reds, // 返回统计
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2),
            'final_bal' => round($bal, 2), 'max_dd' => round($maxDD, 2),
            'max_loss' => round($maxLoss, 2), 'ev' => $n ? round($pl / $n, 3) : 0]; // 胜率/总盈亏/余额/回撤/期望
}

$variants = [                                       // 5种变体
    ['a' => 5, 'r' => 0, 'name' => 'A 现行: 加仓≤5轮·不减仓'], // A
    ['a' => 0, 'r' => 0, 'name' => 'B 不加仓·不减仓'], // B
    ['a' => 0, 'r' => 1, 'name' => 'C 不加仓+fib三档减仓(38.2/50/61.8各减1/3)'], // C
    ['a' => 5, 'r' => 1, 'name' => 'D 加仓≤5轮+fib三档减仓(动态)'], // D
    ['a' => 0, 'r' => 2, 'name' => 'E 不加仓+只在61.8减半'], // E
];
$ladders = [['base' => 1.0, 'name' => '1U+3U/天'], ['base' => 20.0, 'name' => '20U+3U/天']]; // 两档阶梯
$results = []; $detail = [];                        // 结果/明细
foreach ($ladders as $ld) {                         // 逐阶梯
    foreach ($variants as $v) {                     // 逐变体
        [$tr, $bal] = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $v['a'], $v['r'], $ld['base']); // 模拟
        $sm = summ($tr, $bal);                      // 汇总
        $key = $ld['base'] . '_' . $v['a'] . '_' . $v['r']; // 组合键
        $results[] = ['key' => $key, 'ladder' => $ld['name'], 'name' => $v['name']] + $sm; // 合并结果
        $detail[$key] = array_slice($tr, -50);      // 末50笔明细
        echo "{$ld['name']} {$v['name']}: " . json_encode($sm, JSON_UNESCAPED_UNICODE) . "\n"; flush(); // 打印进度
    }
}
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400; // 跨度天数
function detOut($tr) {                              // 明细格式化
    $out = [];
    foreach ($tr as $t) $out[] = ['tin' => date('m-d H:i', (int)($t['tin'] / 1000)),
        'tout' => date('m-d H:i', (int)($t['tout'] / 1000)), 'out' => $t['out'], 'adds' => $t['adds'], // 时间/原因/加仓
        'reds' => $t['reds'], 'margin' => $t['margin'], 'pnl' => $t['pnl'], 'bal' => $t['bal']]; // 减仓/保证金/盈亏/余额
    return $out;
}
$out = [                                            // 组装输出JSON
    'params' => ['lev' => 100, 'bal0' => 500, 'tp' => '价格+2%', 'backstop' => '自设强平兜底50%保证金', // 参数
                 'fibReduce' => 'hi=入场后最高价, hi≥均价1.005%生效; 档位=hi-(hi-均价)×回撤比; 跌破→下一根开盘减当前量1/3(或61.8减半)', // 减仓口径
                 'span' => round($spanDays) . '天',
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000))], // 范围
    'results' => $results,                          // 全变体汇总
    'detail_C' => detOut($detail['1_0_1']), 'detail_A' => detOut($detail['1_5_0']), // 1U阶梯C/A明细
];
file_put_contents('E:/finally-main/web/eth_fibreduce_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写出JSON
echo "JSON OK\n";                                   // 完成
