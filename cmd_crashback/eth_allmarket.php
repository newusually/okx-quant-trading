<?php
/**
 * eth_allmarket.php — 全市场回测(CLI): 不限ETH, 全部476个 *-USDT-SWAP 合约
 * 策略 = A方案(与实盘同口径):
 *   买入: 六重共振全命中 ①全市场1D MA20宽度>50% ②本合约 1h>MA20 ③1h>MA10 ④4h>MA5
 *         ⑤非熔断(BTC 1h 最近4根累计跌幅≤-3% 熔断期禁买) ⑥本合约 taker买比≥50%(1h K线方向代理)
 *   加仓: 15m口径在本回测用1h替代(与ETH回测同参) fib_618 黄金回调信号 + 仅上行(现价>均价), ≤5轮, 每轮=开仓保证金
 *   减仓: 不减仓(下跌扛住)
 *   止盈: 不挂单, 实时监控价格+2%(自均价) → 市价全平
 *   50%兜底强平: 浮亏达到该笔总保证金(含加仓)×50% → 立即市价全平 (价格≤均价×(1-0.5/100))
 *   保证金: 每笔=base+3U×已过天数, 封顶 500/6(不跨合约复利, 组合口径)
 * 输出: web/eth_allmarket_data.json
 */
ini_set('memory_limit', '2048M');                                 // 提升 PHP 内存上限到 2G
set_time_limit(0);                                                // 取消脚本执行时间限制
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆100x; 止盈+2%; 维持保证金率0.4%; 手续费与资金费率
$BAL0 = 500.0; $CAP = $BAL0 / 6.0;                                // 余额500U; 单笔保证金封顶500/6U

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库并设 utf8mb4
$DB = db();                                                       // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行SQL收集全部结果行为数组
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }  // 滑动窗口均线, 前 n-1 个为 null
function fibsig($c, $w = 30) {                                    // fib618加仓信号: 收盘落在30根区间0.618回撤位±3%
    $n = count($c); $out = array_fill(0, $n, false);              // 初始化输出全为无信号
    for ($i = $w - 1; $i < $n; $i++) {                            // 从第30根起扫描
        $lo = INF; $hi = -INF;                                    // 窗口高低初始化
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }  // 求窗口最高/最低
        if ($hi <= $lo) continue;                                 // 无波动跳过
        $fib = $hi - ($hi - $lo) * 0.618;                         // 0.618回撤位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true;  // 收盘在fib±3%内即信号
    }
    return $out;                                                  // 返回信号数组
}
function load_k($inst, $bar) {                                    // 加载指定品种/周期K线
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按时间升序读 kline_<INST>_usdt_swap_<bar> 表
    $ts = []; $o = []; $h = []; $l = []; $c = [];                 // 初始化序列
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }  // 转型入数组
    return [$ts, $o, $h, $l, $c];                                 // 返回五元组
}
function buy_ratio_series($o, $h, $l, $c) {                       // 逐根买方占比(taker买比代理)
    $n = count($c); $br = [];                                     // 根数与输出
    for ($i = 0; $i < $n; $i++) {                                 // 逐根计算
        $rng = $h[$i] - $l[$i];                                   // 当根振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;           // 收盘在振幅中的位置(-1~1)
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;         // 钳制
        $br[$i] = 0.5 + $dir / 2;                                 // 映射到0~1
    }
    return $br;                                                   // 返回序列
}

// ===== ① 全市场1D MA20 宽度(kline1d, 476合约) =====
$up = []; $tot = [];                                              // 各日强势数/总数
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");  // 读全市场日线收盘
$cur = null; $win = []; $s = 0.0;                                 // 当前品种/20日窗口/窗口和
foreach ($rr as $x) {                                             // 逐行遍历
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; }    // 换品种重置
    $win[] = (float)$x[2]; $s += (float)$x[2];                    // 收盘入窗口累加
    if (count($win) > 20) $s -= array_shift($win);                // 超20移除最旧
    if (count($win) == 20) {                                      // 满20算MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2];       // 时间戳/MA20/收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0);          // 收盘≥MA20强势+1
        $tot[$t] = ($tot[$t] ?? 0) + 1;                           // 总数+1
    }
}
$brSrc = [];                                                      // 宽度源
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k;  // 品种数>10才算宽度
$sorted = array_keys($brSrc); sort($sorted);                      // 日期升序
function ffillBr($t) {                                            // 前向填充取宽度
    global $brSrc, $sorted;                                       // 引用全局
    $lo = 0; $hi = count($sorted) - 1; $res = null;               // 二分初始化
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }  // 二分找<=t最大日期
    return $res !== null ? $brSrc[$res] : null;                   // 返回宽度或null
}
echo "breadth days=" . count($brSrc) . "\n"; flush();             // 输出宽度天数

// ===== ⑤ 熔断序列: BTC 1h 最近4根累计跌幅≤-3% =====
[$tsB, , , , $cB] = load_k('btc', '1h');                          // 加载 BTC 1小时K线
$meltSrc = [];                                                    // 熔断触发时刻
for ($i = 4; $i < count($cB); $i++) {                             // 逐根判定
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) $meltSrc[$tsB[$i]] = true;  // 4小时内跌≥3%记触发
}
$meltKeys = array_keys($meltSrc); // 有熔断标记的时刻(稀疏), 判定: 该时刻之后4小时内视为熔断
$meltSet = [];                                                    // 展开后的熔断小时集合
foreach ($meltKeys as $mt) { for ($k = 0; $k < 4; $k++) $meltSet[$mt + $k * 3600000] = true; }  // 触发后4小时均标熔断
echo "melt hours=" . count($meltSet) . "\n"; flush();             // 输出熔断小时数

// ===== 合约清单(有1h表的) =====
$tbls = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline_%_usdt_swap_1h' ORDER BY table_name");  // 枚举1h表
$insts = [];                                                      // 品种列表
foreach ($tbls as $x) { if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $x[0], $m)) $insts[] = $m[1]; }  // 提取品种ID
echo "insts=" . count($insts) . "\n"; flush();                    // 输出品种数

// ===== A方案 sim(与 eth_cycle A 完全同参) =====
function sim($o, $h, $l, $c, $ts, $fib, $entry, $lbase) {         // 核心回测: $lbase 为保证金基数
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP;  // 引入全局参数
    $liqDrop = 1.0 / $LEV - $MMR;                                 // 爆仓所需跌幅
    $startT = $ts[30];                                            // 回测起点
    $trades = []; $n = count($ts); $i = 30;                       // 交易列表/根数/起始下标
    while ($i < $n - 1) {                                         // 主循环找入场
        if (empty($entry[$ts[$i]])) { $i++; continue; }           // 无入场信号跳过
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400); // 已过天数
        $M = round(min($lbase + 3.0 * $days, $CAP), 2);           // 保证金=base+3U×天数, 封顶500/6
        if ($M < 1) $M = 1.0;                                     // 保底1U
        $M0 = $M;                                                 // 开仓保证金
        $e = $o[$i + 1];                                          // 入场价=下一根开盘
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;             // 名义/均价/加仓次数
        $fee = $notional * $FEE_MAKER;                            // 入场maker费
        $funding = 0.0;                                           // 资金费累加
        $liqPx = $avg * (1 - $liqDrop);                           // 爆仓价
        $tgtPx = $avg * (1 + $TPR);                               // 止盈价
        $stopPx = $avg * (1 - 0.5 / $LEV);   // 50%兜底: 浮亏=总保证金(含加仓)×50%  // 50%兜底强平价
        $outcome = null; $exitPx = null; $j = $i + 1;             // 出场结果/出场价/扫描下标
        while ($j < $n) {                                         // 逐根推进持仓
            $barsHeld = $j - $i;                                  // 持仓根数
            $funding += $notional * $FUND8H / 8;                  // 每根1h计1/8资金费周期
            $hitCut = $l[$j] <= $stopPx;                          // 触兜底强平价
            $hitLiq = $l[$j] <= $liqPx;                           // 触爆仓价
            $hitTp  = $h[$j] >= $tgtPx;                           // 触止盈价
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; }  // 50%兜底强平出场
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }   // 爆仓出场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }   // 止盈出场
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }  // 超7天超时平仓
            if ($adds < 5 && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) { // fib618 仅上行加仓, ≤5轮  // 加仓条件
                $ap = $o[$j + 1];                                 // 加仓价=下一根开盘
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);  // 加权更新均价
                $notional += $M0 * $LEV; $adds++;                 // 扩名义/计次数
                $fee += $M0 * $LEV * $FEE_TAKER;                  // 加仓taker费
                $liqPx = $avg * (1 - $liqDrop);                   // 重算爆仓价
                $tgtPx = $avg * (1 + $TPR);                       // 重算止盈价
                $stopPx = $avg * (1 - 0.5 / $LEV);                // 重算兜底价
            }
            $j++;                                                 // 推进下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }  // 数据耗尽按末根收盘了结
        $gross = ($exitPx - $avg) * $notional / $avg;             // 毛盈亏
        $pnl = $gross - $fee - $funding;                          // 净盈亏
        $mg = $M0 * (1 + $adds);                                  // 本笔总保证金
        if ($pnl < -$mg) $pnl = -$mg;                             // 亏损封底总保证金
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,  // 记录交易
                     'margin' => $mg, 'pnl' => round($pnl, 3)];   // 时间/结果/加仓/保证金/盈亏
        $i = $j + 1;                                              // 出场后继续
    }
    return $trades;                                               // 返回交易列表
}

$ladders = [['base' => 1.0, 'name' => '1U+3U/天'], ['base' => 20.0, 'name' => '20U+3U/天']];  // 两种保证金阶梯(基数1U/20U)
$summary = []; $contractStats = []; $dailyPnl = []; $sample = []; // 汇总/分合约/按日盈亏/示例
$gMin = PHP_INT_MAX; $gMax = 0;                                   // 全局时间范围
foreach ($tsB as $t) { if ($t < $gMin) $gMin = $t; if ($t > $gMax) $gMax = $t; }  // 取BTC数据首尾时刻作为区间

$done = 0;                                                        // 已处理合约数
foreach ($insts as $inst) {                                       // 逐合约回测
    $t1 = "kline_{$inst}_usdt_swap_1h"; $t4 = "kline_{$inst}_usdt_swap_4h";  // 1h/4h表名
    $chk = rows("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='trading' AND table_name IN ('$t1','$t4')");  // 检查表齐备
    if ((int)$chk[0][0] < 2) continue;                            // 缺表跳过
    [$ts1h, $o1h, $h1h, $l1h, $c1h] = load_k($inst, '1h');        // 加载1h K线
    $n = count($ts1h);                                            // 根数
    if ($n < 200) { $done++; continue; }                          // 数据太少跳过
    $has4 = true;                                                 // 4h数据可用标志
    [$ts4h, , , , $c4h] = load_k($inst, '4h');                    // 加载4h收盘
    if (count($ts4h) < 10) $has4 = false;                         // 4h数据不足
    $ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);                   // 1h MA20/MA10
    $ma5_4h = $has4 ? ma($c4h, 5) : [];                           // 4h MA5
    $tr4hUpF = []; $p4 = 0; $cur4 = null; $n4 = count($ts4h);     // 4h趋势填充准备
    foreach ($ts1h as $tt) {                                      // 对齐到1h粒度
        while ($p4 < $n4 && $ts4h[$p4] <= $tt) { if ($ma5_4h[$p4] !== null) $cur4 = $c4h[$p4] > $ma5_4h[$p4]; $p4++; }  // 取最近4h趋势
        $tr4hUpF[$tt] = $cur4;                                    // 填充
    }
    $br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h);             // 1h买方占比
    $fib = fibsig($c1h);                                          // fib618加仓信号
    $entryA = [];                                                 // 六重共振入场集
    for ($i = 30; $i < $n; $i++) {                                // 逐根判定
        $t = $ts1h[$i];                                           // 当前时点
        if (!empty($meltSet[$t])) continue;                       // ⑤ 非熔断
        $b = ffillBr($t);                                         // ① 市场宽度
        if ($b === null || $b <= 0.5) continue;                   // ① 宽度>50%
        if ($ma20[$i] === null || $c1h[$i] <= $ma20[$i]) continue;// ② 收盘>MA20
        if ($ma10[$i] === null || $c1h[$i] <= $ma10[$i]) continue;// ③ 收盘>MA10
        if (empty($tr4hUpF[$t])) continue;                        // ④ 4h趋势向上
        if ($br1h[$i] < 0.5) continue;                            // ⑥ taker买比≥50%
        $entryA[$t] = true;                                       // 全命中记录入场
    }
    foreach ($ladders as $ld) {                                   // 两种保证金阶梯分别回测
        $tr = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $ld['base']);  // 模拟该阶梯
        $pl = 0.0; $w = 0; $cut = 0; $adds = 0;                   // 统计累加器
        foreach ($tr as $x) {                                     // 逐笔统计
            $pl += $x['pnl']; if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'CUT') $cut++; $adds += $x['adds'];  // 累加盈亏/胜/强平/加仓
            $d = date('Y-m-d', (int)($x['tout'] / 1000));         // 出场日期
            $dailyPnl[$ld['name']][$d] = ($dailyPnl[$ld['name']][$d] ?? 0) + $x['pnl'];  // 按阶梯按日累加
        }
        $cnt = count($tr);                                        // 笔数
        $summary[$ld['name']]['n'] = ($summary[$ld['name']]['n'] ?? 0) + $cnt;    // 汇总笔数
        $summary[$ld['name']]['win'] = ($summary[$ld['name']]['win'] ?? 0) + $w;  // 汇总胜
        $summary[$ld['name']]['cut'] = ($summary[$ld['name']]['cut'] ?? 0) + $cut;  // 汇总强平
        $summary[$ld['name']]['adds'] = ($summary[$ld['name']]['adds'] ?? 0) + $adds;  // 汇总加仓
        $summary[$ld['name']]['total'] = ($summary[$ld['name']]['total'] ?? 0) + $pl;  // 汇总盈亏
        if ($cnt > 0) $contractStats[$ld['name']][] = ['inst' => strtoupper($inst), 'n' => $cnt, 'pnl' => round($pl, 2), 'wr' => round(100 * $w / $cnt, 1)];  // 分合约记录
    }
    if ($inst === 'eth') $sample['eth'] = array_slice($tr, -40); // 保留ETH末40笔示例(20U阶梯)
    $done++;                                                      // 完成一个
    if ($done % 40 == 0) { echo "progress $done/" . count($insts) . "\n"; flush(); }  // 每40个输出进度
    unset($ts1h, $o1h, $h1h, $l1h, $c1h, $ts4h, $c4h, $ma20, $ma10, $ma5_4h, $tr4hUpF, $br1h, $fib, $entryA);  // 释放内存
}

// 组合日权益曲线(从500起)
$curve = [];                                                      // 各阶梯曲线
foreach ($ladders as $ld) {                                       // 逐阶梯构建
    $nm = $ld['name']; $eq = $BAL0; $peak = $BAL0; $maxDD = 0.0;  // 名称/权益/峰值/最大回撤
    $days = array_keys($dailyPnl[$nm] ?? []); sort($days);        // 日期升序
    $c2 = [];                                                     // 逐日权益行
    foreach ($days as $d) { $eq += $dailyPnl[$nm][$d]; if ($eq > $peak) $peak = $eq; if ($peak - $eq > $maxDD) $maxDD = $peak - $eq; $c2[] = ['d' => $d, 'eq' => round($eq, 2)]; }  // 逐日累加并更新回撤
    $curve[$nm] = ['days' => $c2, 'max_dd' => round($maxDD, 2)];  // 存曲线与回撤
    // 最亏日
    $worst = []; foreach ($dailyPnl[$nm] ?? [] as $d => $v) $worst[] = ['d' => $d, 'pnl' => round($v, 2)];  // 逐日盈亏行
    usort($worst, function ($a, $b) { return $a['pnl'] <=> $b['pnl']; });  // 按盈亏升序(最亏在前)
    $curve[$nm]['worst_days'] = array_slice($worst, 0, 8);        // 最亏8日
    $curve[$nm]['best_days'] = array_slice(array_reverse($worst), 0, 8);  // 最赚8日
}
// 合约排行
$rank = [];                                                       // 各阶梯合约排行
foreach ($ladders as $ld) {                                       // 逐阶梯
    $arr = $contractStats[$ld['name']] ?? [];                     // 分合约数据
    usort($arr, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; });  // 按盈亏降序
    $rank[$ld['name']] = ['top' => array_slice($arr, 0, 15), 'bottom' => array_slice(array_reverse($arr), 0, 15)];  // 前15与后15
}
$spanDays = ($gMax / 1000 - $gMin / 1000) / 86400;                // 数据跨度天数
$out = [                                                          // 组装输出JSON
    'params' => ['lev' => 100, 'bal0' => $BAL0, 'cap_per_trade' => round($CAP, 2), 'tp' => '价格+2%(自均价)',  // 基础参数
        'backstop' => '50%兜底强平: 浮亏达到该笔总保证金(含加仓)×50% → 立即市价全平',  // 兜底口径
        'entry' => '六重共振全命中: ①全市场1D MA20宽度>50% ②本合约1h>MA20 ③1h>MA10 ④4h>MA5 ⑤非熔断(BTC 1h最近4根累计≤-3%) ⑥本合约taker买比≥50%',  // 入场口径
        'add' => 'fib_618黄金回调+仅上行(现价>均价) ≤5轮 每轮=开仓保证金; 下跌不减仓',  // 加仓口径
        'note' => '加仓/兜底口径与ETH回测同参; 每合约独立持仓(同合约同时只1仓); 保证金封顶=500/6(组合不复利)',  // 补充说明
        'span' => round($spanDays) . '天',                        // 数据跨度
        'range' => date('Y-m-d', (int)($gMin / 1000)) . ' ~ ' . date('Y-m-d', (int)($gMax / 1000)),  // 数据区间
        'insts' => count($insts)],                                // 品种数
    'summary' => $summary, 'curve' => $curve, 'rank' => $rank,    // 汇总/曲线/排行
    'sample_eth' => array_map(function ($t) {                     // ETH示例交易格式化
        return ['tin' => date('m-d H:i', (int)($t['tin'] / 1000)), 'tout' => date('m-d H:i', (int)($t['tout'] / 1000)),  // 格式化入出场时间
                'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'pnl' => $t['pnl']];  // 结果/加仓/保证金/盈亏
    }, $sample['eth'] ?? []),
];
file_put_contents('E:/finally-main/web/eth_allmarket_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出JSON
echo "JSON OK\n";                                                 // 完成提示
foreach ($ladders as $ld) { $nm = $ld['name']; echo "$nm: " . json_encode($summary[$nm], JSON_UNESCAPED_UNICODE) . " maxDD=" . $curve[$nm]['max_dd'] . "\n"; }  // 输出各阶梯汇总与回撤
