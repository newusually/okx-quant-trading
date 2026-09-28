<?php
/**
 * eth_drop10.php — 全市场回测(CLI): 跌幅榜「日跌幅≥10%」 vs 「日涨幅≥20%」 前置过滤 × 六重共振A方案 @20x
 * 本脚本测什么: 在A方案(六重共振+fib618加仓+TP+2%+50%兜底, 20x)上对比四种前置过滤口径的胜率/盈亏差异:
 *   DA 昨跌≥10%(无未来函数) / DB 盘中跌≥10% / GA 昨涨≥20% / GB 盘中涨≥20%。
 * 过滤口径(四组对比):
 *   DA 昨跌≥10%(无未来函数): 前一日日线跌幅≥10% → 当天允许买
 *   DB 盘中跌≥10%: 当日盘中(1h收盘/前一日日线收盘-1)≤-10% → 该根之后允许买
 *   GA 昨涨≥20%: 前一日日线涨幅≥20% → 当天允许买
 *   GB 盘中涨≥20%: 当日盘中涨幅≥20% → 该根之后允许买
 * 策略 = A方案(与实盘/eth_allmarket同参, 本轮 20x 逐仓):
 *   买入: 六重共振全命中 ①全市场1D MA20宽度>50% ②1h>MA20 ③1h>MA10 ④4h>MA5
 *         ⑤非熔断(BTC 1h最近4根累计≤-3%) ⑥taker买比≥50%(1h方向代理)
 *   加仓: fib618黄金回调+仅上行(现价>均价) ≤5轮 每轮=开仓保证金; 下跌不减仓
 *   止盈: 价格+2%(自均价) 市价全平
 *   50%兜底强平: 浮亏达该笔总保证金(含加仓)×50% → 立即市价全平 (20x → 价格-2.5%)
 *   保证金: 每笔=base+3U×已过天数, 封顶 500/6
 * 输出: 四口径×两阶梯汇总/权益曲线/排行, 写 web/drop10_data.json。
 */
ini_set('memory_limit', '2048M');                   // 内存上限2G
set_time_limit(0);                                  // 取消时间限制
$LEV = 20; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 参数: 20x/止盈2%/维持保证金/手续费/资金费
$BAL0 = 500.0; $CAP = $BAL0 / 6.0; $G20 = 0.20; $D10 = -0.10; // 起始500U/保证金封顶/急涨阈值/急跌阈值

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
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC"); // 升序读取
    $ts = []; $o = []; $h = []; $l = []; $c = [];   // 各列
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; } // 类型转换
    return [$ts, $o, $h, $l, $c];                   // 返回五元组
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

// ===== kline1d: 每币每日收盘 =====
$dayClose = [];                                     // 每币每日收盘
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time"); // 全市场日线
$cur = null;                                        // 当前币
foreach ($rr as $x) {                               // 逐行
    $iid = strtolower(str_replace('-USDT-SWAP', '', $x[0])); // 币短名
    if ($iid !== $cur) { $cur = $iid; }             // 更新
    $dayClose[$cur][gmdate('Y-m-d', (int)($x[1] / 1000))] = (float)$x[2]; // 日期→收盘
}
echo "kline1d insts=" . count($dayClose) . "\n"; flush(); // 打印币数

// 四组口径名单
$allowDA = []; $g20Count = []; $d10Count = []; $prevClose = []; // 名单/急涨日计数/急跌日计数/前收表
foreach ($dayClose as $iid => $dc) {                // 逐币
    ksort($dc);                                     // 日期排序
    $prevC = null;                                  // 前收
    foreach ($dc as $d => $cv) {                    // 逐日
        $prevClose[$iid][$d] = $prevC;              // 记录前收
        if ($prevC !== null && $prevC > 0) {        // 有效前收
            $g = $cv / $prevC - 1;                  // 日涨跌幅
            $nextDay = gmdate('Y-m-d', strtotime($d . ' +1 day')); // 次日(名单生效日)
            if ($g >= $G20) { $g20Count[$d] = ($g20Count[$d] ?? 0) + 1; $allowDA['GA'][$iid][$nextDay] = true; } // 昨涨≥20%→次日GA
            if ($g <= $D10) { $d10Count[$d] = ($d10Count[$d] ?? 0) + 1; $allowDA['DA'][$iid][$nextDay] = true; } // 昨跌≥10%→次日DA
        }
        $prevC = $cv;                               // 滚动前收
    }
}
echo "days(涨≥20%)=" . count($g20Count) . " days(跌≥10%)=" . count($d10Count) . "\n"; flush(); // 打印统计

// ===== ① 全市场1D MA20 宽度 =====
$up = []; $tot = [];                                // 站上MA20币数/有效币数
$cur = null; $win = []; $s = 0.0;                   // 窗口状态
foreach ($rr as $x) {                               // 再扫日线
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
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k; // 有效币>10才计
$sorted = array_keys($brSrc); sort($sorted);        // 日期升序
function ffillBr($t) {                              // 前向填充宽度
    global $brSrc, $sorted;                         // 全局源
    $lo = 0; $hi = count($sorted) - 1; $res = null; // 二分初始化
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; } // 找≤t最大日期
    return $res !== null ? $brSrc[$res] : null;     // 返回占比
}
echo "breadth days=" . count($brSrc) . "\n"; flush(); // 打印天数

// ===== ⑤ 熔断: BTC 1h 最近4根累计≤-3% =====
[$tsB, , , , $cB] = load_k('btc', '1h');            // BTC 1h收盘
$meltSet = [];                                      // 熔断小时集
for ($i = 4; $i < count($cB); $i++) {               // 逐根
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) { // 4根累计≤-3%
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true; // 标记随后4小时
    }
}
echo "melt hours=" . count($meltSet) . "\n"; flush(); // 打印熔断数

// ===== 合约清单 =====
$tbls = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline_%_usdt_swap_1h' ORDER BY table_name"); // 枚举1h表
$insts = [];                                        // 合约列表
foreach ($tbls as $x) { if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $x[0], $m)) $insts[] = $m[1]; } // 提取合约名
echo "insts=" . count($insts) . "\n"; flush();      // 打印数量

// ===== A方案 sim(20x) =====
function sim($o, $h, $l, $c, $ts, $fib, $entry, $lbase) { // A方案模拟
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP; // 全局参数
    $liqDrop = 1.0 / $LEV - $MMR;                   // 爆仓跌幅≈4.6%
    $startT = $ts[30];                              // 天数基准
    $trades = []; $n = count($ts); $i = 30;         // 初始化
    while ($i < $n - 1) {                           // 逐入场
        if (empty($entry[$ts[$i]])) { $i++; continue; } // 非入场跳过
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400); // 已过天数
        $M = round(min($lbase + 3.0 * $days, $CAP), 2); // 保证金=基数+3U/天封顶
        if ($M < 1) $M = 1.0;                       // 保底1U
        $M0 = $M;                                   // 开仓保证金
        $e = $o[$i + 1];                            // 下一根开盘成交
        $notional = $M0 * $LEV; $avg = $e; $adds = 0; // 名义/均价/加仓数
        $fee = $notional * $FEE_MAKER;              // 开仓maker费
        $funding = 0.0;                             // 资金费
        $liqPx = $avg * (1 - $liqDrop);             // 爆仓价
        $tgtPx = $avg * (1 + $TPR);                 // 止盈价
        $stopPx = $avg * (1 - 0.5 / $LEV);   // 50%兜底: 20x → 价格-2.5%   // 兜底价
        $outcome = null; $exitPx = null; $j = $i + 1; // 出场状态/游标
        while ($j < $n) {                           // 逐根推进
            $barsHeld = $j - $i;                    // 持仓根数
            $funding += $notional * $FUND8H / 8;    // 1/8资金费
            $hitCut = $l[$j] <= $stopPx;            // 触兜底?
            $hitLiq = $l[$j] <= $liqPx;             // 触爆仓?
            $hitTp  = $h[$j] >= $tgtPx;             // 触止盈?
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; } // 兜底离场
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 爆仓离场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; } // 止盈离场
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; } // 7天超时
            if ($adds < 5 && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) { // fib+仅上行+未满5轮
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
        $gross = ($exitPx - $avg) * $notional / $avg; // 浮动盈亏
        $pnl = $gross - $fee - $funding;            // 净盈亏
        $mg = $M0 * (1 + $adds);                    // 总保证金
        if ($pnl < -$mg) $pnl = -$mg;               // 亏损钳制
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds, // 逐笔记录
                     'margin' => $mg, 'pnl' => round($pnl, 3)];
        $i = $j + 1;                                // 跳到出场后
    }
    return $trades;                                 // 返回逐笔
}

$ladders = [['base' => 1.0, 'name' => '1U+3U/天'], ['base' => 20.0, 'name' => '20U+3U/天']]; // 两档阶梯
$variants = ['DA' => '昨跌≥10%(跌幅榜)', 'DB' => '盘中跌≥10%', 'GA' => '昨涨≥20%', 'GB' => '盘中涨≥20%']; // 四口径
$summary = []; $contractStats = []; $dailyPnl = []; $sample = []; // 汇总/合约统计/日P&L/样本
$entryCnt = ['DA' => 0, 'DB' => 0, 'GA' => 0, 'GB' => 0]; // 各口径入场数
$gMin = PHP_INT_MAX; $gMax = 0;                     // 全局时间范围
foreach ($tsB as $t) { if ($t < $gMin) $gMin = $t; if ($t > $gMax) $gMax = $t; } // 求范围

$done = 0;                                          // 已处理数
foreach ($insts as $inst) {                         // 逐合约
    $t1 = "kline_{$inst}_usdt_swap_1h"; $t4 = "kline_{$inst}_usdt_swap_4h"; // 表名
    $chk = rows("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='trading' AND table_name IN ('$t1','$t4')"); // 检查表
    if ((int)$chk[0][0] < 2) continue;              // 缺表跳过
    [$ts1h, $o1h, $h1h, $l1h, $c1h] = load_k($inst, '1h'); // 1h
    $n = count($ts1h);
    if ($n < 200) { $done++; continue; }            // 数据少跳过
    [$ts4h, , , , $c4h] = load_k($inst, '4h');      // 4h
    $has4 = count($ts4h) >= 10;                     // 4h够否
    $ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);     // 1h MA
    $ma5_4h = $has4 ? ma($c4h, 5) : [];             // 4h MA5
    $tr4hUpF = []; $p4 = 0; $cur4 = null; $n4 = count($ts4h); // 4h趋势填充
    foreach ($ts1h as $tt) {
        while ($p4 < $n4 && $ts4h[$p4] <= $tt) { if ($ma5_4h[$p4] !== null) $cur4 = $c4h[$p4] > $ma5_4h[$p4]; $p4++; } // 双指针更新
        $tr4hUpF[$tt] = $cur4;                      // 每1h的4h趋势
    }
    $br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h); // 本币买比
    $fib = fibsig($c1h);                            // 本币fib信号
    // 六重共振基础命中
    $base = [];                                     // 基础命中表
    for ($i = 30; $i < $n; $i++) {                  // 逐根判定
        $t = $ts1h[$i];
        if (!empty($meltSet[$t])) continue;         // ⑤熔断禁买
        $b = ffillBr($t);                           // ①宽度
        if ($b === null || $b <= 0.5) continue;     // 宽度>50%
        if ($ma20[$i] === null || $c1h[$i] <= $ma20[$i]) continue; // ②1h>MA20
        if ($ma10[$i] === null || $c1h[$i] <= $ma10[$i]) continue; // ③1h>MA10
        if (empty($tr4hUpF[$t])) continue;          // ④4h向上
        if ($br1h[$i] < 0.5) continue;              // ⑥买比≥50%
        $base[$t] = $i;                             // 命中
    }
    // 各口径过滤
    $entryDA = []; $entryGA = [];                   // 昨跌/昨涨入场表
    foreach ($base as $t => $i) {                   // 逐命中
        $d = gmdate('Y-m-d', (int)($t / 1000));     // 所属日期
        if (!empty($allowDA['DA'][$inst][$d])) $entryDA[$t] = true; // 昨跌≥10%当日允许
        if (!empty($allowDA['GA'][$inst][$d])) $entryGA[$t] = true; // 昨涨≥20%当日允许
    }
    $entryDB = []; $entryGB = [];                   // 盘中跌/涨入场表
    if (!empty($prevClose[$inst])) {                // 有前收才算
        $pc = $prevClose[$inst];                    // 本币前收
        foreach ($base as $t => $i) {               // 逐命中
            $d = gmdate('Y-m-d', (int)($t / 1000)); // 日期
            if (!isset($pc[$d])) continue;          // 无前收跳过
            $p0 = $pc[$d];                          // 前日收盘
            if ($p0 === null || $p0 <= 0) continue; // 无效跳过
            $g = $c1h[$i] / $p0 - 1;                // 盘中涨跌
            if ($g <= $D10) $entryDB[$t] = true;    // 盘中跌≥10%→DB
            if ($g >= $G20) $entryGB[$t] = true;    // 盘中涨≥20%→GB
        }
    }
    $entryCnt['DA'] += count($entryDA); $entryCnt['DB'] += count($entryDB); // 累计入场数
    $entryCnt['GA'] += count($entryGA); $entryCnt['GB'] += count($entryGB);
    $entryBy = ['DA' => $entryDA, 'DB' => $entryDB, 'GA' => $entryGA, 'GB' => $entryGB]; // 口径→入场表
    foreach ($variants as $vk => $vn) {             // 逐口径
        foreach ($ladders as $ld) {                 // 逐阶梯
            $tr = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryBy[$vk], $ld['base']); // 模拟
            $pl = 0.0; $w = 0; $cut = 0; $adds = 0; // 本组统计
            foreach ($tr as $x) {                   // 逐笔
                $pl += $x['pnl']; if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'CUT') $cut++; $adds += $x['adds']; // 累计
                $d = gmdate('Y-m-d', (int)($x['tout'] / 1000)); // 出场日
                $dailyPnl[$vk][$ld['name']][$d] = ($dailyPnl[$vk][$ld['name']][$d] ?? 0) + $x['pnl']; // 日P&L累计
            }
            $cnt = count($tr);                      // 笔数
            $summary[$vk][$ld['name']]['n'] = ($summary[$vk][$ld['name']]['n'] ?? 0) + $cnt; // 笔数
            $summary[$vk][$ld['name']]['win'] = ($summary[$vk][$ld['name']]['win'] ?? 0) + $w; // 胜
            $summary[$vk][$ld['name']]['cut'] = ($summary[$vk][$ld['name']]['cut'] ?? 0) + $cut; // 兜底
            $summary[$vk][$ld['name']]['liq'] = ($summary[$vk][$ld['name']]['liq'] ?? 0); // 爆仓(不统计)
            $summary[$vk][$ld['name']]['adds'] = ($summary[$vk][$ld['name']]['adds'] ?? 0) + $adds; // 加仓
            $summary[$vk][$ld['name']]['total'] = ($summary[$vk][$ld['name']]['total'] ?? 0) + $pl; // 盈亏
            if ($cnt > 0) $contractStats[$vk][$ld['name']][] = ['inst' => strtoupper($inst), 'n' => $cnt, 'pnl' => round($pl, 2), 'wr' => round(100 * $w / $cnt, 1)]; // 合约战绩
            if ($vk == 'DA' && $ld['base'] == 1.0) $sample[] = array_slice($tr, -40); // DA+1U取样本
        }
    }
    $done++;
    if ($done % 40 == 0) { echo "progress $done/" . count($insts) . "\n"; flush(); } // 进度
    unset($ts1h, $o1h, $h1h, $l1h, $c1h, $ts4h, $c4h, $ma20, $ma10, $ma5_4h, $tr4hUpF, $br1h, $fib, $base, $entryDA, $entryDB, $entryGA, $entryGB); // 释放内存
}
echo "entryBars DA={$entryCnt['DA']} DB={$entryCnt['DB']} GA={$entryCnt['GA']} GB={$entryCnt['GB']}\n"; flush(); // 打印入场总数

// 组合曲线/排行
$curve = [];                                        // 权益曲线
foreach ($variants as $vk => $vn) {                 // 逐口径
    foreach ($ladders as $ld) {                     // 逐阶梯
        $nm = $ld['name'];
        $eq = $BAL0; $peak = $BAL0; $maxDD = 0.0;   // 权益/峰值/回撤
        $days = array_keys($dailyPnl[$vk][$nm] ?? []); sort($days); // 交易日升序
        $c2 = [];                                   // 曲线点
        foreach ($days as $d) { $eq += $dailyPnl[$vk][$nm][$d]; if ($eq > $peak) $peak = $eq; if ($peak - $eq > $maxDD) $maxDD = $peak - $eq; $c2[] = ['d' => $d, 'eq' => round($eq, 2)]; } // 逐日累计
        $curve[$vk][$nm] = ['days' => $c2, 'max_dd' => round($maxDD, 2)]; // 曲线+回撤
        $worst = []; foreach ($dailyPnl[$vk][$nm] ?? [] as $d => $v) $worst[] = ['d' => $d, 'pnl' => round($v, 2)]; // 日P&L
        usort($worst, function ($a, $b) { return $a['pnl'] <=> $b['pnl']; }); // 升序
        $curve[$vk][$nm]['worst_days'] = array_slice($worst, 0, 5); // 最差5天
        $curve[$vk][$nm]['best_days'] = array_slice(array_reverse($worst), 0, 5); // 最好5天
    }
}
$rank = [];                                         // 合约排行
foreach ($variants as $vk => $vn) foreach ($ladders as $ld) {
    $arr = $contractStats[$vk][$ld['name']] ?? [];  // 各合约战绩
    usort($arr, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; }); // 按盈亏降序
    $rank[$vk][$ld['name']] = ['top' => array_slice($arr, 0, 10), 'bottom' => array_slice(array_reverse($arr), 0, 10)]; // 前10/后10
}
$spanDays = ($gMax / 1000 - $gMin / 1000) / 86400;  // 跨度天数
arsort($d10Count); $d10Top = [];                    // 急跌日排序
foreach (array_slice($d10Count, 0, 10, true) as $d => $k) $d10Top[] = ['d' => $d, 'coins' => $k]; // 急跌最集中10天
$out = [                                            // 组装输出JSON
    'params' => ['lev' => 20, 'bal0' => $BAL0, 'cap_per_trade' => round($CAP, 2), 'tp' => '价格+2%(自均价)', // 参数
        'backstop' => '50%兜底强平: 浮亏达到该笔总保证金(含加仓)×50% → 立即市价全平 (20x → 价格-2.5%)', // 兜底
        'entry' => '六重共振全命中: ①全市场1D MA20宽度>50% ②1h>MA20 ③1h>MA10 ④4h>MA5 ⑤非熔断(BTC 1h最近4根累计≤-3%) ⑥taker买比≥50% + 前置过滤(跌幅榜/涨幅榜)', // 入场
        'add' => 'fib_618黄金回调+仅上行(现价>均价) ≤5轮 每轮=开仓保证金; 下跌不减仓', // 加仓
        'note' => '加仓/兜底口径与A方案完全同参; 每合约同时只1仓; 保证金封顶=500/6(组合不复利)', // 备注
        'span' => round($spanDays) . '天',
        'range' => date('Y-m-d', (int)($gMin / 1000)) . ' ~ ' . date('Y-m-d', (int)($gMax / 1000)), // 范围
        'insts' => count($insts)],                  // 合约数
    'd10_stats' => ['days_with_d10' => count($d10Count), 'top_days' => $d10Top, // 急跌统计
        'days_with_g20' => count($g20Count)],       // 急涨日数
    'entry_bars' => $entryCnt,                      // 入场数
    'summary' => $summary, 'curve' => $curve, 'rank' => $rank, // 汇总/曲线/排行
    'sample' => array_map(function ($t) {           // 样本格式化
        return ['tin' => date('m-d H:i', (int)($t['tin'] / 1000)), 'tout' => date('m-d H:i', (int)($t['tout'] / 1000)),
                'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'pnl' => $t['pnl']];
    }, end($sample) ?: []),                         // 末组样本
];
file_put_contents('E:/finally-main/web/drop10_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写出JSON
echo "JSON OK\n";                                   // 完成
foreach ($variants as $vk => $vn) foreach ($ladders as $ld) { $nm = $ld['name'];
    echo "$vn $nm: " . json_encode($summary[$vk][$nm] ?? [], JSON_UNESCAPED_UNICODE) . " maxDD=" . ($curve[$vk][$nm]['max_dd'] ?? '-') . "\n"; } // 打印各组合汇总
