<?php
/**
 * macd1m_eth.php — ETH 1m MACD(12,26,60) 两策略 一年回测生成器 (2026-09-27 用户指令)
 * 用户原话: "...1分钟数据...使用1分钟计算macd 参数是12,26,60 计算1分钟K线 30根 macd 0轴以下 有macd<-5
 *   然后后面macd>0买入 上涨10个点 卖出 100X 还有 macd<0 有超过50根都是这样 后面macd>0的时候买入
 *   再跌再加仓 直到不跌 也是 macd>0买入 上涨10个点 卖出 50美金是本金 帮我算一下"
 * 本回测脚本测什么：ETH 1m 两个 MACD 水下反转策略（A：30根内深柱<-5后柱上穿0买入；B：DIF水下
 * ≥50根后柱上穿0买入，每再跌0.2%加仓直到不跌/资金用完）。口径：100x、50U本金/轮、taker 0.05%、
 * 48小时超时平仓；止盈对照 价格+10%/+1%/+0.1%/+0.2%。输出统计与信号样本到 web/macd1meth_data.json。
 */
ini_set('memory_limit', '6144M'); // 提高内存上限到6G，1m全年数据量大
set_time_limit(0); // 取消执行时间限制
$LEV = 100; $MMR = 0.004; $FEE = 0.0005; $CAP = 50.0; $TMO_MS = 48 * 3600 * 1000; // 全局参数：杠杆100x、维持保证金率0.4%、taker费率0.05%、单轮本金50U、超时48小时（毫秒）
$LIQ = 1.0 / $LEV - $MMR; // 爆仓价格跌幅 = 1/杠杆 - 维持保证金率 = 0.6%

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 定义数据库连接函数：连本地 trading 库并设 utf8mb4
$DB = db(); // 建立数据库连接
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 定义毫秒时间戳转北京时间字符串的辅助函数

// ---- 1) 加载 1m ----
$gT0 = (time() - 368 * 86400) * 1000; // 回测窗口起点：当前时间往前约368天（毫秒）
$r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_eth_usdt_swap_1m WHERE candle_time >= $gT0 ORDER BY candle_time ASC"); // 按时间升序读窗口内全部1m K线（列为 o/h/l/c）
$ts = []; $o = []; $h = []; $l = []; $c = []; // 初始化时间与开高低收的并行数组
while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0) continue; $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; } // 逐行读取：跳过收盘价<=0的脏数据
$r->free(); // 释放结果集
$n = count($c); // K线总根数
echo "bars=$n span=" . bj($ts[0]) . " ~ " . bj($ts[$n - 1]) . "\n"; // 打印根数与时间范围（北京时间）
if ($n < 100000) { echo "DATA INCOMPLETE - wait fill\n"; } // 数据不足10万根提示K线尚未补齐

// ---- 2) MACD(12,26,60) ----
function emaAll($a, $p) { // 定义EMA计算函数
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = $a[0]; // 初始化长度、输出、平滑系数、首值
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; } // 标准EMA递推
    return $out; // 返回EMA序列
}
$e12 = emaAll($c, 12); $e26 = emaAll($c, 26); // 收盘价的12/26周期EMA
$dif = []; for ($i = 0; $i < $n; $i++) $dif[] = $e12[$i] - $e26[$i]; // DIF = EMA12 - EMA26
$dea = emaAll($dif, 60); // DEA = DIF 的60周期EMA（用户指定参数把标准9换成60）
$macd = []; for ($i = 0; $i < $n; $i++) $macd[] = 2.0 * ($dif[$i] - $dea[$i]); // MACD柱 = 2×(DIF-DEA)（中国软件口径）
echo "macd computed\n"; // 打印MACD计算完成

// ---- 3) 信号 ----
// A: 最近30根内存在 DIF<0 && MACD<-5, 之后柱上穿>0
$sigA = []; // 策略A信号索引数组
$lastDeepIdx = -999; // 最近一次出现深柱（DIF<0且柱<-5）的索引
for ($i = 0; $i < $n; $i++) { // 逐根扫描
    if ($dif[$i] < 0 && $macd[$i] < -5) $lastDeepIdx = $i; // 记录深柱位置
    if ($i - $lastDeepIdx <= 30 && $lastDeepIdx >= 0 && $macd[$i] > 0 && $macd[$i - 1] <= 0) { // 深柱出现后30根内、柱上穿0则触发信号
        $sigA[] = $i; $lastDeepIdx = -999; // 触发后需重新出现深柱
    }
}
// B: DIF<0 连续>=50根 后, 柱上穿>0
$sigB = []; // 策略B信号索引数组
$negRun = 0; // DIF连续为负的计数
for ($i = 0; $i < $n; $i++) { // 逐根扫描
    if ($dif[$i] < 0) $negRun++; // DIF为负计数加1
    else $negRun = 0; // DIF转正则清零
    if ($negRun >= 50 && $macd[$i] > 0 && $macd[$i - 1] <= 0) { $sigB[] = $i; } // 水下≥50根且柱上穿0则触发信号
}
echo "sigA=" . count($sigA) . " sigB=" . count($sigB) . "\n"; // 打印两策略信号数量

// ---- 4) 回测 ----
// 单轮: 本金50U(保证金), 100x。A: 全仓一次买入。B: 初始10U, 每较上次加仓价再跌0.2%加10U(总≤50U, 价格创新低才加=直到不跌)。
function simRound($ts, $o, $h, $l, $c, $i0, $TPPX, $mode, $LEV, $FEE, $LIQ, $CAP, $TMO_MS) { // 定义单轮回测函数：从信号K线i0开仓，模拟到出场
    $n = count($c); // 数据总根数
    $adds = []; // 加仓/开仓记录
    if ($mode == 'A') { $adds[] = ['i' => $i0, 'u' => $CAP, 'px' => $c[$i0]]; $lastAddPx = $c[$i0]; $left = 0.0; } // 策略A：首根全仓50U按收盘价买入，无剩余资金
    else { // 策略B：分批买入模式
        $u1 = 10.0; $adds[] = ['i' => $i0, 'u' => $u1, 'px' => $c[$i0]]; $lastAddPx = $c[$i0]; $left = $CAP - $u1; // 首次仅10U，剩余40U备用加仓
    }
    $q = 0.0; $net = 0.0; $margin = 0.0; // 初始化持仓量、累计名义成本净额、累计保证金
    foreach ($adds as $a) { $netX = $a['u'] * $LEV * (1 - $FEE); $q += $netX / $a['px']; $net += $netX; $margin += $a['u']; } // 汇总各笔投入：扣费后名义/价格=币量，累计成本与保证金
    $avg = $net / $q; // 持仓均价 = 总成本 / 总币量
    $tpPx = $avg * (1 + $TPPX); $liqPx = $avg * (1 - $LIQ); // 止盈价=均价×(1+TP)  爆仓价=均价×(1-0.6%)
    $why = ''; $exit = 0; $tExit = 0; $liqN = 0; // 初始化出场原因、出场价、出场时间、爆仓标记
    for ($i = $i0 + 1; $i < $n; $i++) { // 从信号下一根开始逐根模拟
        // B: 回调加仓 (先于爆仓判定同根: 用当根低点判断加仓, 加仓价 = min(open, trigger))
        if ($mode == 'B' && $left >= 9.999 && $l[$i] <= $lastAddPx * (1 - 0.002)) { // 策略B：仍有≥10U资金且当根低点较上次加仓价再跌0.2%则加仓
            $addPx = min($o[$i], $lastAddPx * (1 - 0.002)); // 加仓价取开盘价与触发价较小者（保守）
            $u2 = min(10.0, $left); // 每次加10U，不足10U则用剩余全部
            $netX = $u2 * $LEV * (1 - $FEE); // 本次扣费后名义价值
            $q += $netX / $addPx; $net += $netX; $margin += $u2; $left -= $u2; // 更新币量、成本、保证金、剩余资金
            $avg = $net / $q; $tpPx = $avg * (1 + $TPPX); $liqPx = $avg * (1 - $LIQ); // 重算均价并更新止盈/爆仓价
            $adds[] = ['i' => $i, 'u' => $u2, 'px' => $addPx]; $lastAddPx = $addPx; // 记录本次加仓，更新参考价
        }
        if ($l[$i] <= $liqPx) { $why = 'LIQ'; $exit = $liqPx; $tExit = $ts[$i]; $liqN = 1; break; } // 当根低点触及爆仓价：爆仓出场
        if ($h[$i] >= $tpPx) { $exit = max($o[$i], $tpPx); $why = 'TP'; $tExit = $ts[$i]; break; } // 当根高点触及止盈价：止盈出场（开盘价更高则按开盘价）
        if ($ts[$i] - $ts[$i0] >= $TMO_MS) { $exit = $c[$i]; $why = 'TO'; $tExit = $ts[$i]; break; } // 持仓超48小时：按收盘价超时平仓
    }
    if ($why == '') { $exit = $c[$n - 1]; $why = 'END'; $tExit = $ts[$n - 1]; } // 一直未出场：按数据末尾收盘价强制平仓
    $gross = $q * $exit - $net; $feeOut = $q * $exit * $FEE; // 计算毛利（出场名义-净成本）与出场手续费
    $pnl = ($why == 'LIQ') ? -$margin : $gross - $feeOut;   // 爆仓=亏光全部已投入保证金
    return ['t0' => $ts[$i0], 't1' => $tExit, 'adds' => count($adds) - 1, 'margin' => $margin, // 返回本轮结果：开仓/平仓时间、加仓次数、保证金
            'avg' => $avg, 'exit' => $exit, 'pnl' => $pnl, 'why' => $why]; // 均价、出场价、净盈亏、出场原因
}

$TPS = ['p10' => 0.10, 'p1' => 0.01, 'p01' => 0.001, 'p02' => 0.002]; // 四档止盈口径：价格+10%/+1%/+0.1%(=ROI10%)/+0.2%(ROI20%)
$res = []; // 结果容器：res[策略][止盈档]
foreach (['A' => $sigA, 'B' => $sigB] as $sk => $sigs) { // 遍历两个策略
    foreach ($TPS as $tk => $tp) { // 遍历四档止盈
        $rounds = []; $pnlTotal = 0; $wins = 0; $liqN = 0; $toN = 0; $addN = 0; $marginUsed = 0.0; // 初始化本组合统计：轮次明细、总盈亏、胜数、爆仓数、超时数、加仓数、总保证金
        foreach ($sigs as $i0) { // 逐个信号回测一轮
            $rt = simRound($ts, $o, $h, $l, $c, $i0, $tp, $sk, $LEV, $FEE, $LIQ, $CAP, $TMO_MS); // 模拟本轮
            $pnlTotal += $rt['pnl']; if ($rt['pnl'] > 0) $wins++; if ($rt['why'] == 'LIQ') $liqN++; if ($rt['why'] == 'TO') $toN++; // 累计盈亏并统计胜负/爆仓/超时
            $addN += $rt['adds']; $marginUsed += $rt['margin']; // 累计加仓次数与保证金用量
            $rounds[] = [$rt['t0'], $rt['t1'], $rt['adds'], round($rt['avg'], 2), round($rt['exit'], 2), round($rt['pnl'], 2), $rt['why']]; // 记录本轮明细：开仓时间/平仓时间/加仓次数/均价/出场价/盈亏/原因
        }
        $res[$sk][$tk] = ['n' => count($sigs), 'pnl' => round($pnlTotal, 2), 'win' => $wins, // 汇总本组合：信号数、总盈亏、胜数
            'wr' => count($sigs) ? round($wins / count($sigs) * 100, 1) : 0, 'liq' => $liqN, 'to' => $toN, // 胜率、爆仓数、超时数
            'adds' => $addN, 'marginUsed' => round($marginUsed, 1), // 加仓总数、保证金用量
            'trades' => count($rounds) > 400 ? array_slice($rounds, 0, 400) : $rounds]; // 明细最多保留前400轮防止JSON过大
        printf("%s %-4s n=%-5d wr=%5.1f%% liq=%-5d adds=%-6d pnl=%+12.2f\n", $sk, $tk, count($sigs), $res[$sk][$tk]['wr'], $liqN, $addN, $pnlTotal); // 打印本组合统计行
    }
}

// 保存信号样本(画图/核对用): 每策略前200个
$sigSave = []; // 信号样本容器
foreach (['A' => $sigA, 'B' => $sigB] as $sk => $sigs) { // 遍历两策略
    $arr = []; // 本策略样本数组
    foreach (array_slice($sigs, 0, 200) as $i0) $arr[] = ['t' => $ts[$i0], 'px' => $c[$i0], 'macd' => round($macd[$i0], 3)]; // 取前200个信号：时间/价格/MACD柱值
    $sigSave[$sk] = $arr; // 存入样本容器
}
$out = ['generated' => date('Y-m-d H:i:s'), // 输出数据包：生成时间
    'params' => ['macd' => '12,26,60', 'lev' => $LEV, 'liq' => round($LIQ, 4), 'cap' => $CAP, 'fee' => $FEE, 'timeout_h' => 48], // 参数快照：MACD参数/杠杆/爆仓跌幅/本金/费率/超时
    'span' => [bj($ts[0]), bj($ts[$n - 1])], 'bars' => $n, // 数据范围与根数
    'sigCounts' => ['A' => count($sigA), 'B' => count($sigB)], // 两策略信号总数
    'results' => $res, 'sigSamples' => $sigSave]; // 全部组合统计与信号样本
file_put_contents(__DIR__ . '/../web/macd1meth_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入前端数据文件 web/macd1meth_data.json
echo "saved size=" . round(filesize(__DIR__ . '/../web/macd1meth_data.json') / 1048576, 2) . "MB\n"; // 打印输出文件大小（MB）
