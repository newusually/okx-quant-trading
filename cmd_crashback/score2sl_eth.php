<?php
/**
 * score2sl_eth.php — ETH 15m Score买入/加仓 · 100x · 500U · 30U首仓+1U加仓 · 止损价分档对照 (2026-09-27)
 * 规则: Score>=2事件 → 无仓开首仓30U(名义3000U), 有仓加仓1U · TP价格+2%(跟均价)
 *       止损=均价×(1-SL) 分档; 顺序: 先爆仓(-0.6%)→再止损→再止盈(同根保守)
 *       taker 0.05%按名义价值 · 现金流记账500U · 一年窗口
 * 变体: noSL / SL0.2% / SL0.3% / SL0.4% / SL0.5% → web/score2100sl_data.json → web/score2100sl.php
 * 本回测脚本测什么：ETH 15m 的 Score 火箭信号策略在 100x 杠杆下的止损价分档对照回测——
 * Score>=2 时无仓开30U首仓（名义3000U），有仓加仓1U（fib_618 类逢跌加仓思路的固定档），
 * 止盈价格+2%（跟随均价），出场优先级 爆仓→止损→止盈；现金流记账总资金500U，一年窗口；
 * 输出 noSL 与 SL0.2%~SL0.5% 五个变体的逐轮明细、月度盈亏到 web/score2100sl_data.json。
 */
ini_set('memory_limit', '4096M'); // 提高内存上限到4G
set_time_limit(0); // 取消执行时间限制
$DB = new mysqli('127.0.0.1', 'root', '', 'trading'); // 连接本地 MariaDB 的 trading 库
$DB->set_charset('utf8mb4'); // 设置连接字符集为 utf8mb4

$LEV = 100; $FEE = 0.0005; $MMR = 0.004; // 全局参数：杠杆100x、taker费率0.05%、维持保证金率0.4%
$FIRST = 30.0; $ADD = 1.0; $TPP = 0.02; $CASH0 = 500.0; // 首仓30U保证金、每次加仓1U、止盈价格+2%、初始现金500U
$NOW_MS = (int)round(microtime(true) * 1000); // 当前毫秒时间戳
$WIN_MS = $NOW_MS - 365 * 86400 * 1000; // 回测窗口起点：一年前（毫秒）
$VARS = ['noSL' => 0.0, 'SL0.2' => 0.002, 'SL0.3' => 0.003, 'SL0.4' => 0.004, 'SL0.5' => 0.005]; // 五个止损变体：无止损/止损0.2%~0.5%

function emaRaw($a, $p) { // 定义原始EMA计算函数（不取整，保证MACD精度）
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = 0.0; // 初始化长度、输出、平滑系数、首值
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; } // 标准EMA递推
    return $out; // 返回未取整EMA序列
}

$r = $DB->query("SELECT candle_time,`h`,`l`,`c`,vol FROM kline_eth_usdt_swap_15m ORDER BY candle_time ASC"); // 按时间升序读全部15m K线（列为 o/h/l/c/vol，本表用不到o）
$t = []; $h = []; $l = []; $c = []; $v = []; // 初始化时间与高低收量的并行数组
while ($x = $r->fetch_row()) { if ((float)$x[3] <= 0) continue; $t[] = (int)($x[0] / 1000); $h[] = (float)$x[1]; $l[] = (float)$x[2]; $c[] = (float)$x[3]; $v[] = (float)$x[4]; } // 逐行读取：跳过收盘价<=0的脏数据，时间转秒
$r->free(); // 释放结果集
$n = count($c); // K线总根数
echo "bars=$n\n"; // 打印根数

$e12 = emaRaw($c, 12); $e26 = emaRaw($c, 26); // 收盘价的12/26周期EMA
$difR = []; for ($i = 0; $i < $n; $i++) $difR[] = $e12[$i] - $e26[$i]; // DIF = EMA12 - EMA26
$deaR = emaRaw($difR, 60); // DEA = DIF 的60周期EMA（本框架口径）
$macdR = []; for ($i = 0; $i < $n; $i++) $macdR[] = 2.0 * ($difR[$i] - $deaR[$i]); // 原始MACD柱 = 2*(DIF-DEA)
$atr = []; $atr[0] = $h[0] - $l[0]; $ka = 2.0 / 15; // 初始化ATR：首根用振幅，平滑系数对应14周期
for ($i = 1; $i < $n; $i++) { $tr = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1])); $atr[$i] = $tr * $ka + $atr[$i - 1] * (1 - $ka); } // Wilder平滑递推ATR14
$vma = array_fill(0, $n, 0.0); $sv = 0.0; // 初始化均量数组与滑动窗口和
for ($i = 0; $i < $n; $i++) { $sv += $v[$i]; if ($i >= 20) $sv -= $v[$i - 20]; $vma[$i] = $i >= 19 ? $sv / 20 : $sv / ($i + 1); } // 滑动窗口算20周期均量

// Score事件预计算(全变体共用): [barIndex, score]
$sigs = []; $negRun = 0; // 初始化信号数组与DIF连负计数
for ($i = 1; $i < $n; $i++) { // 逐根扫描打分
    if ($difR[$i] < 0) $negRun++; else $negRun = 0; // 维护DIF连续为负计数
    if ($i < 35) continue; // 前35根数据不足跳过
    if (!($macdR[$i] > 0 && $macdR[$i - 1] <= 0 && $difR[$i] < 0)) continue; // 候选：MACD柱上穿0且DIF仍在水下
    $trough = 0.0; // 记录前30根MACD柱谷值
    for ($j = max(0, $i - 30); $j < $i; $j++) if ($macdR[$j] < $trough) $trough = $macdR[$j]; // 扫描前30根找谷值
    $a = max($atr[$i - 1], 1e-9); // 取前一根ATR做归一化分母（防除零）
    $s = 2.0 * min(abs($trough) / $a, 3.0) / 3.0 + 1.5 * min($negRun, 100) / 100.0; // 打分：谷深（最多2分）+ 水下时长（最多1.5分）
    if ($negRun >= 40) { // 水下超40根检查双底背离
        $ss = $i - $negRun + 1; $mm = $ss + intdiv($negRun - 1, 2); // 水下区间起点与中点
        $t1 = $ss; for ($j = $ss; $j <= $mm; $j++) if ($l[$j] < $l[$t1]) $t1 = $j; // 前半段价格谷1
        $t2 = $mm + 1; for ($j = $mm + 1; $j < $i; $j++) if ($l[$j] < $l[$t2]) $t2 = $j; // 后半段价格谷2
        if ($l[$t2] < $l[$t1] && $macdR[$t2] > $macdR[$t1]) $s += 2.5; // 价创新低柱抬高=底背离，加2.5分
    }
    $s += 1.0; // 确认(上穿)恒1
    if ($v[$i] > 1.5 * $vma[$i - 1] && $vma[$i - 1] > 0) $s += 1.0; // 放量1.5倍再加1分
    if ($s >= 2.0 && $t[$i] >= $WIN_MS / 1000) $sigs[] = [$i, round($s, 1)]; // 总分>=2且在一年窗口内记为信号：[索引, 分数]
}
echo "sigs=" . count($sigs) . "\n"; // 打印信号总数
$sigAt = []; // 建立索引->分数的映射表，供逐根回放快速查询
foreach ($sigs as $sg) $sigAt[$sg[0]] = $sg[1]; // 填充映射

$out = []; // 输出容器：out[变体名] = {sum, byMonth, rounds}
foreach ($VARS as $vName => $slP) { // 依次回测五个止损变体
    $cash = $CASH0; $rounds = []; $pos = null; $rid = 0; $nAdd = 0; // 初始化：现金500U、轮次明细、当前持仓、轮次编号、总加仓次数
    for ($i = 1; $i < $n; $i++) { // 逐根回放
        if ($pos !== null) { // 有持仓先做出场判定
            $avg = $pos['costN'] / $pos['q']; // 当前持仓均价 = 名义成本 / 币量
            $liqPx = $avg * (1 - 1 / $LEV + $MMR); // 爆仓价 = 均价×(1 - 1/杠杆 + 维持保证金率)
            $tpPx = $avg * (1 + $TPP); // 止盈价 = 均价×(1+2%)
            $slPx = $slP > 0 ? $avg * (1 - $slP) : -1; // 止损价 = 均价×(1-SL档)，无止损变体置-1
            if ($l[$i] <= $liqPx) {                                        // 1) 爆仓
                $rounds[$rid]['t1'] = $t[$i]; $rounds[$rid]['px1'] = $liqPx; $rounds[$rid]['why'] = 'LIQ'; // 记录平仓时间/价格/原因
                $rounds[$rid]['pnl'] = -($pos['m'] + $pos['fee']); // 爆仓亏损=亏光保证金+手续费
                $pos = null; // 清仓
            } elseif ($slP > 0 && $l[$i] <= $slPx) {                       // 2) 止损
                $loss = $pos['q'] * ($slPx - $avg); // 止损毛亏 = 币量×(止损价-均价)
                $exitFee = $pos['costN'] * (1 - $slP) * $FEE; // 出场手续费按止损后名义价值计
                $cash += $pos['m'] + $loss - $exitFee; // 现金流：退回保证金+亏损额-手续费
                $rounds[$rid]['t1'] = $t[$i]; $rounds[$rid]['px1'] = $slPx; $rounds[$rid]['why'] = 'SL'; // 记录平仓信息
                $rounds[$rid]['pnl'] = $loss - $exitFee - $pos['fee']; // 本轮净盈亏=毛亏-出场费-开仓费
                $pos = null; // 清仓
            } elseif ($h[$i] >= $tpPx) {                                   // 3) 止盈
                $gross = $pos['costN'] * $TPP; // 止盈毛利 = 名义成本×2%（与均价涨幅等价）
                $exitFee = $pos['costN'] * (1 + $TPP) * $FEE; // 出场手续费按止盈后名义价值计
                $cash += $pos['m'] + $gross - $exitFee; // 现金流：退回保证金+毛利-手续费
                $rounds[$rid]['t1'] = $t[$i]; $rounds[$rid]['px1'] = $tpPx; $rounds[$rid]['why'] = 'TP'; // 记录平仓信息
                $rounds[$rid]['pnl'] = $gross - $exitFee - $pos['fee']; // 本轮净盈亏=毛利-出场费-开仓费
                $pos = null; // 清仓
            }
            if ($pos === null) $rounds[$rid]['ca'] = round($cash, 2);      // 平仓后剩余现金
        }
        if (isset($sigAt[$i])) { // 本根有Score信号
            if ($pos !== null) {                                           // 加仓1U
                if ($cash >= $ADD + $LEV * $ADD * $FEE) { // 现金足够支付保证金+手续费才加仓
                    $p = $c[$i]; // 按本根收盘价加仓
                    $cash -= $ADD + $LEV * $ADD * $FEE; // 扣除加仓保证金与手续费
                    $pos['m'] += $ADD; $pos['q'] += $LEV * $ADD / $p; $pos['costN'] += $LEV * $ADD; $pos['fee'] += $LEV * $ADD * $FEE; // 更新保证金/币量/名义成本/累计手续费
                    $rounds[$rid]['adds'][] = ['t' => $t[$i], 'p' => $p]; // 记录本次加仓时间与价格
                    $nAdd++; // 加仓次数累计
                }
            } elseif ($cash >= $FIRST + $LEV * $FIRST * $FEE) {            // 开首仓30U
                $p = $c[$i]; $rid++; // 开仓价取收盘价，轮次编号加1
                $cb0 = $cash; // 记录开仓前现金余额
                $pos = ['m' => $FIRST, 'q' => $LEV * $FIRST / $p, 'costN' => $LEV * $FIRST, 'fee' => $LEV * $FIRST * $FEE]; // 建仓：保证金30U、币量=名义3000U/价、名义成本、开仓手续费
                $cash -= $FIRST + $LEV * $FIRST * $FEE; // 扣除首仓保证金与手续费
                $rounds[$rid] = ['t0' => $t[$i], 'px0' => $p, 's' => $sigAt[$i], 'adds' => [], 'cb' => $cb0]; // 记录本轮：开仓时间/价格/信号分/加仓列表/开仓前现金
            }
        }
    }
    $open = null; // 期末仍持仓的轮次信息
    if ($pos !== null) { // 数据结束时仍有持仓
        $avg = $pos['costN'] / $pos['q']; // 持仓均价
        $unreal = $pos['q'] * ($c[$n - 1] - $avg) - $pos['costN'] * (1 + $c[$n - 1] / $avg) * $FEE - $pos['fee']; // 浮动盈亏=价差毛利-双边估算手续费-已付开仓费
        $open = ['t0' => $rounds[$rid]['t0'], 'px0' => $rounds[$rid]['px0'], 'm' => $pos['m'], 'nAdd' => count($rounds[$rid]['adds']), 'unreal' => round($unreal, 2)]; // 记录持仓轮次：开仓时间/价格/保证金/加仓次数/浮动盈亏
    }
    $tot = 0.0; $w = 0; $tpN = 0; $liqN = 0; $slN = 0; $byMonth = []; // 初始化本变体统计：总盈亏、胜数、止盈/爆仓/止损数、月度分布
    foreach ($rounds as $rd) { // 遍历全部轮次
        if (!isset($rd['why'])) continue; // 期末未平仓的轮次不计入统计
        $tot += $rd['pnl']; // 累计盈亏
        if ($rd['pnl'] > 0) $w++; // 盈利轮计数
        if ($rd['why'] == 'TP') $tpN++; elseif ($rd['why'] == 'LIQ') $liqN++; else $slN++; // 分出场原因计数
        $mk = gmdate('Y-m', $rd['t1'] + 8 * 3600); // 平仓月份（北京时间）
        if (!isset($byMonth[$mk])) $byMonth[$mk] = ['n' => 0, 'pnl' => 0.0, 'w' => 0]; // 初始化该月统计
        $byMonth[$mk]['n']++; $byMonth[$mk]['pnl'] += $rd['pnl']; if ($rd['pnl'] > 0) $byMonth[$mk]['w']++; // 月度：轮数/盈亏/胜数累计
    }
    $closed = array_values(array_filter($rounds, function ($x) { return isset($x['why']); })); // 只保留已平仓轮次作为输出明细
    $sum = ['nRound' => count($closed), 'nAdd' => $nAdd, // 汇总：平仓轮数、加仓总数
        'pnl' => round($tot, 2), 'cashEnd' => round($CASH0 + $tot, 2), // 总盈亏、期末现金（500U+盈亏）
        'wr' => count($closed) ? round($w / count($closed) * 100, 1) : 0, // 胜率
        'tp' => $tpN, 'liq' => $liqN, 'sl' => $slN, 'open' => $open]; // 止盈/爆仓/止损计数与期末持仓
    $out[$vName] = ['sum' => $sum, 'byMonth' => $byMonth, 'rounds' => $closed]; // 本变体全部结果入输出容器
    printf("%s: rounds=%d adds=%d tp=%d sl=%d liq=%d pnl=%+.2f cashEnd=%.2f cash=%.2f\n", $vName, $sum['nRound'], $nAdd, $tpN, $slN, $liqN, $tot, $CASH0 + $tot, $cash); // 打印本变体统计行
}
file_put_contents(__DIR__ . '/../web/score2100sl_data.json', json_encode(['vars' => $out, 'winStart' => gmdate('Y-m-d', $WIN_MS / 1000 + 8 * 3600), 'winEnd' => gmdate('Y-m-d', $NOW_MS / 1000 + 8 * 3600)])); // 写入前端数据文件：五变体结果+回测窗口起止日期
echo "ALL DONE\n"; // 全部完成
