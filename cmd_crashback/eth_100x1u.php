<?php
/**
 * eth_100x1u.php — 同款最优配置换100x·1U保证金·只买涨(CLI)
 * 配置: 六重共振入场 + TP价格+2% + fib618加仓(每次1U, 最多5轮) + 余额500U
 * 美金强平: 250U在1U保证金下无意义, 对比档 0/0.5/1/2/3U
 * 输出: web/eth_100x1u_data.json
 */
ini_set('memory_limit', '1024M');                                 // 提升 PHP 内存上限到 1G
set_time_limit(0);                                                // 取消脚本执行时间限制
$BALANCE = 500.0; $MARGIN = 1.0; $ADD = 1.0; $MAXADD = 5;         // 账户余额500U; 每笔保证金1U; 每轮加仓1U; 最多加仓5轮
$LEV = 100; $TPR = 0.02; $SLP = 0.03;    // SL-3%在100x下永远晚于爆仓, 保留仅为完整  // 杠杆100x; 止盈+2%; 止损-3%(实际不会先于爆仓触发)
$MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 维持保证金率0.4%; maker费0.02%; taker费0.05%; 8小时资金费率0.01%

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库并设 utf8mb4, 返回连接对象
$DB = db();                                                       // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行 SQL, 收集全部结果行为数组
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }  // 滑动窗口计算移动平均线, 前 n-1 个为 null
function fibsig($c, $w = 30) {                                    // fib618 信号: 收盘价是否落在近 w 根区间 0.618 回撤位 ±3%
    $n = count($c); $out = array_fill(0, $n, false);              // 初始化输出数组全为无信号
    for ($i = $w - 1; $i < $n; $i++) {                            // 从第 w 根起逐根扫描
        $lo = INF; $hi = -INF;                                    // 初始化窗口最低/最高价
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }  // 求窗口内最高/最低收盘
        if ($hi <= $lo) continue;                                 // 窗口无波动则跳过
        $fib = $hi - ($hi - $lo) * 0.618;                         // 计算 0.618 回撤位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true;  // 收盘在 fib618 ±3% 内标记信号
    }
    return $out;                                                  // 返回逐根布尔信号
}
function load_k($inst, $bar) {                                    // 加载指定品种/周期 K 线
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按时间升序读 kline_<INST>_usdt_swap_<bar> 表(列名 o/h/l/c/vol)
    $ts = []; $o = []; $h = []; $l = []; $c = []; $v = [];        // 初始化时间戳与开高低收量数组
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; }  // 逐行转型入数组
    return [$ts, $o, $h, $l, $c, $v];                             // 返回六元组
}
function buy_ratio_series($o, $h, $l, $c, $v) {                   // 计算逐根K线"买方占比"序列(0~1)
    $n = count($c); $br = [];                                     // 取根数, 初始化输出
    for ($i = 0; $i < $n; $i++) {                                 // 逐根遍历
        $rng = $h[$i] - $l[$i];                                   // 当根振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;           // 收盘在振幅中的相对位置(-1~1)
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;         // 钳制到 [-1,1]
        $br[$i] = 0.5 + $dir / 2;                                 // 映射到 0~1
    }
    return $br;                                                   // 返回买方占比序列
}
function sim($o, $h, $l, $c, $ts, $fib, $entry, $br, $lossCut) {  // 核心回测模拟: $lossCut 为美金强平档(亏损金额止损)
    global $MARGIN, $ADD, $MAXADD, $LEV, $TPR, $SLP, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H;  // 引入全局参数
    $liqDrop = 1.0 / $LEV - $MMR;                                 // 爆仓所需跌幅(1/杠杆 - 维持保证金率)
    $trades = []; $n = count($ts); $i = 30;                       // 初始化交易列表, 第30根起扫描(指标预热)
    while ($i < $n - 1) {                                         // 主循环逐根找入场信号
        if (empty($entry[$ts[$i]])) { $i++; continue; }           // 无六重共振入场信号则跳过
        $s = 0; $cnt = 0;                                         // 初始化近12根买方占比求和
        for ($k = $i - 11; $k <= $i; $k++) if ($k >= 0) { $s += $br[$k]; $cnt++; }  // 累加近12根买方占比
        if ($cnt == 0 || $s / $cnt < 0.5) { $i++; continue; }     // 均值<0.5(多头氛围不足)跳过
        $e = $o[$i + 1];                                          // 以信号下一根开盘价入场
        $notional = $MARGIN * $LEV; $avg = $e; $adds = 0;         // 名义本金=保证金×杠杆; 均价与加仓次数初始化
        $fee = $notional * $FEE_MAKER;                            // 入场 maker 手续费
        $liqPx = $avg * (1 - $liqDrop);                           // 初始爆仓价
        $slPx = $avg * (1 - $SLP);                                // 初始止损价(-3%)
        $tgtPx = $avg * (1 + $TPR);                               // 止盈价(+2%)
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;  // 初始化出场结果/出场价/扫描下标/持仓根数
        while ($j < $n) {                                         // 逐根推进判断出场
            $barsHeld = $j - $i;                                  // 更新持仓K线数
            $stopPx = ($lossCut > 0) ? $avg * (1 - $lossCut / $notional) : 0;  // 美金强平价: 亏损$lossCut金额对应的价格(0档则不启用)
            $hitLiq = $l[$j] <= $liqPx;                           // 最低价触及爆仓价
            $hitCut = ($lossCut > 0) && ($l[$j] <= $stopPx);      // 最低价触及美金强平价(启用时)
            $hitSl  = $l[$j] <= $slPx;                            // 最低价触及止损价
            $hitTp  = $h[$j] >= $tgtPx;                           // 最高价触及止盈价
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 爆仓出场
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; } // 美金强平出场
            if ($hitSl)  { $outcome = 'SL';  $exitPx = $slPx;  break; }  // 止损出场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }  // 止盈出场
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }  // 持仓超7天按收盘强制平仓
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {  // 未达加仓上限且fib618信号且价高于均价
                $ap = $o[$j + 1];                                 // 加仓价取下一根开盘价
                $avg = ($avg * $notional + $ap * $ADD * $LEV) / ($notional + $ADD * $LEV);  // 加权更新持仓均价
                $notional += $ADD * $LEV; $adds++;                // 扩大名义本金并计加仓次数
                $fee += $ADD * $LEV * $FEE_TAKER;                 // 加仓按 taker 计手续费
                $liqPx = $avg * (1 - $liqDrop);                   // 重算爆仓价
                $slPx = $avg * (1 - $SLP);                        // 重算止损价
                $tgtPx = $avg * (1 + $TPR);                       // 重算止盈价
            }
            $j++;                                                 // 推进下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }  // 数据耗尽按末根收盘了结
        $gross = ($exitPx - $avg) * $notional / $avg;             // 毛盈亏
        $funding = $notional * $FUND8H * ($barsHeld / 8);         // 资金费成本(每8小时一计)
        $pnl = $gross - $fee - $funding;                          // 净盈亏
        $mg = $MARGIN + $adds * $ADD;                             // 本笔总投入保证金
        if ($pnl < -$mg) $pnl = -$mg;                             // 亏损封底为总保证金
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,  // 记录一笔交易明细
                     'margin' => $mg, 'pnl' => round($pnl, 3)];   // 含时间/结果/加仓/保证金/盈亏
        $i = $j + 1;                                              // 出场后继续扫描(不重叠持仓)
    }
    return $trades;                                               // 返回全部交易
}
function summ($trades) {                                          // 汇总出场类型与盈亏统计
    $w = 0; $cut = 0; $sl = 0; $lq = 0; $to = 0; $pl = 0.0; $gross_win = 0.0; $gross_lose = 0.0;  // 各类计数与盈亏累加器
    foreach ($trades as $t) {                                     // 逐笔分类统计
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'CUT') $cut++;  // 止盈/美金强平计数
        elseif ($t['out'] == 'SL') $sl++; elseif ($t['out'] == 'LIQ') $lq++; else $to++;  // 止损/爆仓/超时计数
        $pl += $t['pnl'];                                         // 累计净盈亏
        if ($t['pnl'] >= 0) $gross_win += $t['pnl']; else $gross_lose += $t['pnl'];  // 分别累计盈/亏总额
    }
    $n = count($trades);                                          // 总笔数
    return ['n' => $n, 'win' => $w, 'cut' => $cut, 'sl' => $sl, 'liq' => $lq, 'to' => $to,  // 返回统计数组
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2),  // 胜率%与总盈亏
            'gross_win' => round($gross_win, 2), 'gross_lose' => round($gross_lose, 2),  // 盈利/亏损总额
            'ev' => $n ? round($pl / $n, 3) : 0];                 // 单笔期望值
}

echo "== load ==\n"; flush();                                     // 进度提示: 加载数据
[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h');      // 加载 ETH 1小时K线
[$ts4h, , , , $c4h, ] = load_k('eth', '4h');                      // 加载 ETH 4小时K线(只取时间戳与收盘)
$n = count($ts1h);                                                // 1小时K线总根数

echo "== breadth ==\n"; flush();                                  // 进度提示: 市场宽度
$up = []; $tot = [];                                              // 各日站上MA20品种数/有效品种总数
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");  // 读全市场日线收盘价
$cur = null; $win = []; $s = 0.0;                                 // 当前品种/20日滑动窗口/窗口和
foreach ($rr as $x) {                                             // 逐行遍历日线
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; }    // 换品种重置窗口
    $win[] = (float)$x[2]; $s += (float)$x[2];                    // 收盘入窗口并累加
    if (count($win) > 20) $s -= array_shift($win);                // 超20个移除最旧
    if (count($win) == 20) {                                      // 凑满20日计算MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2];       // 时间戳/MA20/当日收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0);          // 收盘≥MA20则强势数+1
        $tot[$t] = ($tot[$t] ?? 0) + 1;                           // 品种总数+1
    }
}
$brSrc = [];                                                      // 各日市场宽度源数据
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k;  // 品种数>10的日期算宽度=强势/总数
$sorted = array_keys($brSrc); sort($sorted);                      // 有效日期升序(供二分)
function ffillBr($t) {                                            // 前向填充: 取不晚于t的最近市场宽度
    global $brSrc, $sorted;                                       // 引用全局数据
    $lo = 0; $hi = count($sorted) - 1; $res = null;               // 二分初始化
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }  // 二分找<=t的最大日期
    return $res !== null ? $brSrc[$res] : null;                   // 返回宽度值或null
}
echo "== features ==\n"; flush();                                 // 进度提示: 特征计算
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);                       // 1小时 MA20/MA10
$ma5_4h = ma($c4h, 5);                                            // 4小时 MA5
$tr4hUp = [];                                                     // 4小时趋势状态表
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hUp[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i];  // 4小时收盘>MA5记为上行
$tr4hUpF = [];                                                    // 趋势前向填充到1小时粒度
$p4 = 0; $cur4 = null; $n4 = count($ts4h);                        // 指针/当前状态/长度
foreach ($ts1h as $tt) { while ($p4 < $n4 && $ts4h[$p4] <= $tt) { $cur4 = $tr4hUp[$ts4h[$p4]] ?? $cur4; $p4++; } $tr4hUpF[$tt] = $cur4; }  // 对齐最近已收盘4小时趋势
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h, $v1h);           // 1小时买方占比序列
$fib = fibsig($c1h);                                              // 1小时fib618加仓信号
$entryA = [];                                                     // 六重共振入场信号表
for ($i = 30; $i < $n; $i++) {                                    // 从第30根起判定入场
    $t = $ts1h[$i];                                               // 当前时间戳
    $b = ffillBr($t);                                             // 该时刻市场宽度
    if ($b !== null && $b > 0.5 && $ma20[$i] !== null && $c1h[$i] > $ma20[$i] &&  // 条件: 宽度>0.5 且收盘>MA20
        $ma10[$i] !== null && $c1h[$i] > $ma10[$i] && !empty($tr4hUpF[$t])) $entryA[$t] = true;  // 且收盘>MA10 且4小时上行 → 入场
}
echo "entryA=" . count($entryA) . "\n"; flush();                  // 输出入场信号数

// ===== 强平档对比 + 主配置明细 =====
$cuts = [0, 0.5, 1, 2, 3];                                        // 美金强平对比档位(0=不启用)
$results = []; $bestTrades = null; $bestCut = null;               // 结果集/最优档交易明细/最优档位
foreach ($cuts as $cut) {                                         // 遍历各强平档分别回测
    $tr = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $br1h, $cut);  // 模拟该档回测
    $sm = summ($tr);                                              // 汇总统计
    $results[] = ['cut' => $cut] + $sm;                           // 记录该档结果
    if ($bestCut === null || $sm['total'] > end($results)['total']) { $bestCut = $cut; $bestTrades = $tr; }  // 更新总盈亏最优档
    echo "cut={$cut}U: " . json_encode($sm) . "\n"; flush();      // 输出该档结果
}
usort($results, fn($a, $b) => $b['total'] <=> $a['total']);       // 结果按总盈亏降序排列

// 主配置(用户原话: 同款配置100x1U) = cut=0 逐笔/每日
$main = null;                                                     // 主配置(cut=0)统计
foreach ($results as $r) if ($r['cut'] == 0) $main = $r;          // 从结果中取 cut=0 档

$cum = 0; $trOut = []; $daily = []; $no = 0;                      // 初始化累计盈亏/明细/按日汇总/序号
foreach ($bestTrades as $t) {                                     // 遍历最优档交易
    $no++; $cum += $t['pnl'];                                     // 序号递增, 累加盈亏
    $d = date('Y-m-d', (int)($t['tout'] / 1000));                 // 按出场日期归组
    $daily[$d] = ($daily[$d] ?? 0) + $t['pnl'];                   // 累计当日盈亏
    $trOut[] = ['no' => $no,                                      // 组装单笔明细行
        'tin' => date('Y-m-d H:i', (int)($t['tin'] / 1000)), 'tout' => date('Y-m-d H:i', (int)($t['tout'] / 1000)),  // 格式化入出场时间
        'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'pnl' => $t['pnl'],  // 出场类型/加仓/保证金/盈亏
        'cum' => round($cum, 2)];                                 // 累计盈亏
}
ksort($daily);                                                    // 按日期升序
$dayRows = []; $c2 = 0; $wd = 0; $ld = 0;                         // 逐日行/累计/盈利天数/亏损天数
foreach ($daily as $d => $v) { $c2 += $v; if ($v >= 0) $wd++; else $ld++; $dayRows[] = ['d' => $d, 'pnl' => round($v, 2), 'cum' => round($c2, 2)]; }  // 组装逐日盈亏与累计, 统计盈亏天数

$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;        // 回测跨度天数
$out = [                                                          // 组装输出JSON
    'params' => ['lev' => 100, 'balance' => 500, 'margin' => 1, 'add' => 1, 'tp' => '价格+2%(ROI+200%)',  // 策略参数: 杠杆/余额/保证金/加仓/止盈
                 'liq' => '价格-0.6%爆仓归零', 'span' => round($spanDays) . '天',  // 爆仓说明与跨度
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000)),  // 数据区间
                 'dir' => '只买涨'],                              // 交易方向
    'main' => ['cut' => 0] + $main,                               // 主配置(cut=0)统计
    'results' => $results,                                        // 各强平档对比结果
    'best_cut' => $bestCut,                                       // 最优强平档位
    'trades' => $trOut, 'daily' => $dayRows, 'win_days' => $wd, 'lose_days' => $ld,  // 明细/逐日/盈亏天数
];
file_put_contents('E:/finally-main/web/eth_100x1u_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出JSON供前端展示
echo "JSON OK trades=" . count($trOut) . " days=" . count($dayRows) . "\n";  // 完成提示
