<?php
/**
 * eth_final1y.php — ETH 终版回测(CLI, 2026-09-25 用户指令)
 * 本脚本测什么: ETH 终版策略, 与实盘 build 0925N 完全同参——15m K线逐根判定六重共振(1h/4h/d条件向前填充),
 *   保证金=1U+3U/天封顶总余额/10, 加仓=15m三均线多头排列+仅上行≤5轮每轮+1U, 止盈+2%, 无止损无兜底(交易所爆仓线-0.6%@100x),
 *   起始500U逐笔结转(动态复利), 净口径含手续费+资金费。
 * 参数: 100x杠杆, 布林/CCI无关; 关键阈值: 宽度>50%, 熔断-3%, 买比≥50%, 余额/10仓位上限。
 * 输出: 全部逐笔+月度汇总+各条件命中统计, 写 web/final1y_data.json。
 * 策略 = 与实盘 build 0925N 完全同参:
 *   买入: 六重共振全命中(逐15m K线判定, 1h/4h/d 条件向前填充)
 *     ① 全市场1D MA20宽度>50%(kline1d 全部合约) ②ETH 1h>MA20 ③1h>MA10
 *     ④4h>MA5 ⑤非熔断(BTC 1h最近4根累计跌幅≤-3%禁买) ⑥taker买比≥50%(1h K线方向代理)
 *   保证金: 每笔 = 1U + 3U×已过天数, 封顶【当前总余额/10】(动态权益, 用户硬锁)
 *   加仓: 15m K线 triple_ma_bull 三均线多头排列(MA7>MA25>MA99且价>MA7) + 仅上行(现价>均价)
 *         ≤5轮, 每轮固定+1U, 且 加仓后总保证金(含本轮) ≤ 总余额/10
 *   止盈: 价格+2%(自均价) 市价全平; 不设止损; 无兜底强平(交易所爆仓线=价格-0.6%@100x)
 *   净口径: 含开仓maker费/加仓taker费/平仓taker费 + 资金费率0.01%/8h
 *   起始权益 500U, 逐笔结转(动态复利)
 * 输出: web/final1y_data.json
 */
ini_set('memory_limit', '2048M');                   // 内存上限2G
set_time_limit(0);                                  // 取消时间限制
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 参数: 100x/止盈2%/维持保证金/手续费/资金费
$EQ0 = 500.0; $MAXADDS = 5; $ADDU = 1.0;            // 起始500U/最多加仓5轮/每轮+1U

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接trading库
$DB = db();                                         // 全局连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; } // 查询行数组
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; } // 滑动均线(增量法)
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 毫秒→北京时间字符串

function load_k($inst, $bar) {                      // 加载K线
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC"); // 升序读取
    $ts = []; $o = []; $h = []; $l = []; $c = [];   // 各列
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; } // 类型转换
    return [$ts, $o, $h, $l, $c];                   // 返回五元组
}

// ===== ① 全市场 1D MA20 宽度 =====
$up = []; $tot = [];                                // 站上MA20币数/有效币数
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
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k; // 有效币>10才计
$brDays = array_keys($brSrc); sort($brDays);        // 日期升序
function ffillBr($t) {                              // 前向填充宽度
    global $brSrc, $brDays;                         // 全局源
    $lo = 0; $hi = count($brDays) - 1; $res = null; // 二分初始化
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($brDays[$mid] <= $t) { $res = $brDays[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; } // 找≤t最大日期
    return $res !== null ? $brSrc[$res] : null;     // 返回占比
}
echo "breadth days=" . count($brSrc) . "\n"; flush(); // 打印天数

// ===== ⑤ BTC 熔断时刻集合 =====
[$tsB, , , , $cB] = load_k('btc', '1h');            // BTC 1h收盘
$meltSet = [];                                      // 熔断小时集
for ($i = 4; $i < count($cB); $i++) {               // 逐根
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) { // 4根累计≤-3%
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true; // 标记随后4小时
    }
}
echo "melt hours=" . count($meltSet) . "\n"; flush(); // 打印熔断数

// ===== ETH K线: 15m 主时间轴 + 1h/4h 条件向前填充 =====
[$tsF, $oF, $hF, $lF, $cF] = load_k('eth', '15m');  // ETH 15m主时间轴
[$ts1, , , , $c1] = load_k('eth', '1h');            // ETH 1h收盘
[$ts4, , , , $c4] = load_k('eth', '4h');            // ETH 4h收盘
$ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5); // 1h MA20/MA10 与 4h MA5
$ma7 = ma($cF, 7); $ma25 = ma($cF, 25); $ma99 = ma($cF, 99); // 15m三均线(加仓条件)

// taker买比代理(1h K线方向)
function buyratio($o, $h, $l, $c) {                 // 买比代理函数
    $n = count($c); $br = [];                       // 初始化
    for ($i = 0; $i < $n; $i++) {                   // 逐根
        $rng = $h[$i] - $l[$i];                     // 振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0; // 方向
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1; // 钳制
        $br[$i] = 0.5 + $dir / 2;                   // 映射[0,1]
    }
    return $br;                                     // 返回序列
}
[$ts1b, $o1b, $h1b, $l1b, $c1b] = load_k('eth', '1h'); // 再取1h完整OHLC(买比用)
$br1h = buyratio($o1b, $h1b, $l1b, $c1b);           // 1h买比序列

$nF = count($tsF);                                  // 15m根数
echo "eth15m bars=$nF span=" . bj($tsF[0]) . " ~ " . bj($tsF[$nF - 1]) . "\n"; flush(); // 打印范围

// ===== 逐15m判定六重共振 + 统计各条件命中 =====
$cnt = ['c1' => 0, 'c2' => 0, 'c3' => 0, 'c4' => 0, 'c5' => 0, 'c6' => 0, 'entry' => 0, 'melt_block' => 0]; // 各条件命中/入场/熔断禁买计数
$p1 = 0; $p4 = 0; $n1 = count($ts1); $n4 = count($ts4); // 双指针/1h与4h根数
$cur20 = null; $cur10 = null; $cur4 = null; $curBr = null; // 当前1h/4h条件状态
$entry = [];                                        // 入场索引表
$startT = $tsF[30];                                 // 天数基准
for ($i = 30; $i < $nF; $i++) {                     // 逐15m判定
    $t = $tsF[$i];
    while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; } // 双指针: 更新1h条件(②③)
    while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; } // 更新4h条件(④)
    $hb = (int)floor($t / 3600000) * 3600000;       // 该15m所属小时
    // ⑤ 熔断(该15m所在小时被熔断标记 → 禁买)
    if (isset($meltSet[$hb])) { $cnt['melt_block']++; continue; } // 熔断小时跳过并计数
    $b = ffillBr($t);                               // ①市场宽度
    if ($b === null || $b <= 0.5) continue; $cnt['c1']++; // 宽度>50%命中计数
    if (!$cur20) continue; $cnt['c2']++;            // ②1h>MA20
    if (!$cur10) continue; $cnt['c3']++;            // ③1h>MA10
    if (!$cur4) continue; $cnt['c4']++;             // ④4h>MA5
    // ⑥ taker买比: 当前1h K线方向代理
    $ib = -1; // 用二分找 1h index                  // 二分查找当前1h索引
    $lo = 0; $hi = $n1 - 1;
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; } // 找≤t最大1h索引
    if ($ib < 0 || $br1h[$ib] < 0.5) continue; $cnt['c6']++; // ⑥买比≥50%
    $entry[$i] = true; $cnt['entry']++;             // 六重全命中→入场
}
echo "entry15m=" . count($entry) . "\n"; flush();   // 打印入场数

// ===== 三均线多头排列信号序列(15m) =====
$tri = [];                                          // triple_ma_bull信号
for ($i = 0; $i < $nF; $i++) {                      // 逐根
    $tri[$i] = ($ma7[$i] !== null && $ma25[$i] !== null && $ma99[$i] !== null) // 三均线就绪
        && ($ma7[$i] > $ma25[$i] && $ma25[$i] > $ma99[$i] && $cF[$i] > $ma7[$i]); // MA7>MA25>MA99 且价>MA7
}

// ===== sim: 单仓位顺序回测, 动态权益 =====
$liqDrop = 1.0 / $LEV - $MMR;   // -0.6%            // 交易所爆仓跌幅
$eq = $EQ0;                                         // 动态权益(复利)
$trades = [];                                       // 逐笔
$i = 30;                                            // 游标
while ($i < $nF - 1) {                              // 逐入场
    if (empty($entry[$i])) { $i++; continue; }      // 非入场跳过
    $days = (int)floor((($tsF[$i] - $startT) / 1000) / 86400); // 已过天数
    $cap = $eq / 10.0;                              // 仓位上限=当前总余额/10(动态)
    $M0 = round(min(1.0 + 3.0 * $days, $cap), 2);   // 保证金=1U+3U/天封顶
    if ($M0 < 1) $M0 = 1.0;                         // 保底1U
    $e = $oF[$i + 1];                               // 下一根15m开盘成交
    $notional = $M0 * $LEV; $avg = $e; $adds = 0;   // 名义/均价/加仓数
    $fee = $notional * $FEE_MAKER; $funding = 0.0;  // 开仓maker费/资金费
    $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); // 爆仓价/止盈价
    $outcome = null; $exitPx = null; $j = $i + 1;   // 出场状态/游标
    $skipAddBlocks = 0;                             // 因仓位上限被拒的加仓计数
    while ($j < $nF) {                              // 持仓逐根
        $funding += $notional * $FUND8H / 32;   // 每15m 1/32个8h资金费   // 15m根计提资金费
        $hitLiq = $lF[$j] <= $liqPx;                // 触及爆仓价?
        $hitTp  = $hF[$j] >= $tgtPx;                // 触及止盈价?
        if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 爆仓离场
        if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; } // 止盈离场
        if ($j - $i >= 7 * 24 * 4) { $outcome = 'TO'; $exitPx = $cF[$j]; break; } // 7天超时(15m根)
        // 加仓: 15m三均线多头排列 + 仅上行 + ≤5轮 + 仓位≤余额/10
        if ($adds < $MAXADDS && $tri[$j] && $cF[$j] > $avg) { // 条件全满足
            $cap2 = $eq / 10.0;                     // 当前余额/10
            if ($M0 + $adds * $ADDU + $ADDU <= $cap2 && $j + 1 < $nF) { // 加仓后总保证金仍≤上限
                $ap = $oF[$j + 1];                  // 下一根开盘加仓
                if ($ap > 0) {
                    $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV); // 重算均价
                    $notional += $ADDU * $LEV; $adds++; // 名义+1U杠杆额/计数
                    $fee += $ADDU * $LEV * $FEE_TAKER; // 加仓taker费
                    $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); // 重算爆仓/止盈价
                }
            } else $skipAddBlocks++;                // 超上限被拒
        }
        $j++;                                       // 下一根
    }
    if ($outcome === null) { $outcome = 'TO'; $j = $nF - 1; $exitPx = $cF[$j]; } // 尽头按超时
    $gross = ($exitPx - $avg) * $notional / $avg;   // 浮动盈亏
    $fee += $notional * $FEE_TAKER;   // 平仓 taker   // 平仓taker费
    $pnl = $gross - $fee - $funding;                // 净盈亏
    $mg = $M0 + $adds * $ADDU;                      // 总保证金
    if ($pnl < -$mg) $pnl = -$mg;                   // 亏损钳制到保证金
    $eq += $pnl;                                    // 结转权益(复利)
    $trades[] = ['tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds, // 逐笔记录
                 'm0' => $M0, 'mg' => $mg, 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),
                 'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)]; // 持仓小时数
    $i = $j + 1;                                    // 跳到出场后
}

// ===== 汇总 =====
$tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'adds' => 0, 'pnl' => 0.0, 'fee' => 0.0]; // 统计容器
foreach ($trades as $x) {                           // 逐笔统计
    if ($x['out'] == 'WIN') $tot['win']++;          // 止盈数
    if ($x['out'] == 'LIQ') $tot['liq']++;          // 爆仓数
    if ($x['out'] == 'TO') $tot['to']++;            // 超时数
    $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl']; // 加仓/盈亏累计
}
$months = [];                                       // 月度汇总
foreach ($trades as $x) {                           // 逐笔
    $m = gmdate('Y-m', (int)($x['tout'] / 1000) + 8 * 3600); // 出场月(北京时间)
    $mo = &$months[$m];                             // 引用该月条目
    $mo['n'] = ($mo['n'] ?? 0) + 1;                 // 笔数
    $mo['win'] = ($mo['win'] ?? 0) + ($x['out'] == 'WIN' ? 1 : 0); // 止盈数
    $mo['liq'] = ($mo['liq'] ?? 0) + ($x['out'] == 'LIQ' ? 1 : 0); // 爆仓数
    $mo['to'] = ($mo['to'] ?? 0) + ($x['out'] == 'TO' ? 1 : 0); // 超时数
    $mo['adds'] = ($mo['adds'] ?? 0) + $x['adds'];  // 加仓数
    $mo['pnl'] = round(($mo['pnl'] ?? 0) + $x['pnl'], 2); // 月盈亏
    $mo['eq_end'] = $x['eq'];                       // 月末权益
    unset($mo);                                     // 解除引用
}
// 最大回撤(按逐笔权益)
$peak = -INF; $maxdd = 0;                           // 峰值/回撤
foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; } // 逐笔更新

echo sprintf("TOTAL n=%d win=%d liq=%d to=%d adds=%d pnl=%.1f eqEnd=%.1f maxdd=%.1f\n", // 打印汇总
    $tot['n'], $tot['win'], $tot['liq'], $tot['to'], $tot['adds'], $tot['pnl'], $eq, $maxdd);

$out = [                                            // 组装输出JSON
    'meta' => [
        'inst' => 'ETH-USDT-SWAP', 'lev' => $LEV, 'tp' => '+2%', 'eq0' => $EQ0, // 元信息
        'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF, // 范围/根数
        'params' => '买入=六重共振全命中 | 保证金=1U+3U/天 封顶总余额/10 | 加仓=15m三均线多头排列(MA7>MA25>MA99)+仅上行 ≤5轮 每轮+1U 仓位≤总余额/10 | 止盈=价格+2% | 不设止损·无兜底(交易所爆仓线-0.6%) | 净口径含手续费+资金费', // 参数全文
    ],
    'summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2), // 汇总/期末权益/回撤
    'cond' => $cnt, 'months' => $months, 'trades' => $trades, // 条件命中/月度/逐笔
];
file_put_contents('E:/finally-main/web/final1y_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写出JSON
echo "DATA OK\n";                                   // 完成
