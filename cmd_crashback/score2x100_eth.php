<?php
/**
 * score2x100_eth.php — ETH 15m Score买入/加仓 · 100x · 500U本金 · 首仓30U · 加仓1U (2026-09-27)
 * 规则: Score>=2事件(火箭同公式) → 无仓则开首仓30U保证金(名义3000U), 有仓则加仓1U(名义100U)
 *       止盈 价格+2%(跟随均价上移, 市价全平) · 无止损 · 爆仓线=均价×(1-1/100+0.004)=-0.6% · 无超时
 *       同根K线爆仓优先(保守) · taker 0.05%双边 · 现金流记账(起始500U)
 * 输出: web/score2100eth_data.json → 报告页 web/score2100eth.php
 */
ini_set('memory_limit', '4096M');                                    // 提升PHP内存上限到4G, 应对全量K线数组
set_time_limit(0);                                                   // 取消脚本执行时间限制, 长回测可跑完
$DB = new mysqli('127.0.0.1', 'root', '', 'trading');                // 连接本地MariaDB的trading库(K线存放库)
$DB->set_charset('utf8mb4');                                         // 设置连接字符集为utf8mb4, 避免中文乱码

$LEV = 100; $FEE = 0.0005; $MMR = 0.004;                             // 杠杆100x · taker费率0.05% · 维持保证金率0.4%
$FIRST = 30.0; $ADD = 1.0; $TPP = 0.02;                              // 首仓保证金30U · 每次加仓1U · 止盈幅度+2%
$CASH0 = 500.0;                                                      // 起始现金500U(现金流记账口径)
$NOW_MS = (int)round(microtime(true) * 1000);                        // 当前毫秒时间戳
$WIN_MS = $NOW_MS - 365 * 86400 * 1000;                              // 回测窗口起点=一年前(只交易最近365天的信号)

function emaRaw($a, $p) {                                            // 通用EMA计算函数(数组a, 周期p)
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = 0.0; // 初始化: 长度/输出数组/平滑系数k=2/(p+1)
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; } // 首值取原始值, 之后递推EMA
    return $out;                                                     // 返回整条EMA序列
}

$r = $DB->query("SELECT candle_time,`h`,`l`,`c`,vol FROM kline_eth_usdt_swap_15m ORDER BY candle_time ASC"); // 读取ETH 15m K线(列名h/l/c/vol, 按时间升序)
$t = []; $h = []; $l = []; $c = []; $v = [];                         // 初始化时间/最高/最低/收盘/成交量数组
while ($x = $r->fetch_row()) { if ((float)$x[3] <= 0) continue; $t[] = (int)($x[0] / 1000); $h[] = (float)$x[1]; $l[] = (float)$x[2]; $c[] = (float)$x[3]; $v[] = (float)$x[4]; } // 逐行取数: 跳过收盘价<=0的脏数据, 毫秒转秒存入各数组
$r->free();                                                          // 释放查询结果集内存
$n = count($c);                                                      // K线总根数
echo "bars=$n\n";                                                    // 控制台打印K线数量

$e12 = emaRaw($c, 12); $e26 = emaRaw($c, 26);                        // 计算收盘价12周期与26周期EMA
$difR = []; for ($i = 0; $i < $n; $i++) $difR[] = $e12[$i] - $e26[$i]; // DIF = EMA12 - EMA26(快慢线差)
$deaR = emaRaw($difR, 60);                                           // DEA = DIF的60周期EMA(慢平滑)
$macdR = []; for ($i = 0; $i < $n; $i++) $macdR[] = 2.0 * ($difR[$i] - $deaR[$i]); // MACD柱 = 2×(DIF-DEA)
$atr = []; $atr[0] = $h[0] - $l[0]; $ka = 2.0 / 15;                  // ATR初始化为首根振幅, 平滑系数ka=2/15(即14周期EMA口径)
for ($i = 1; $i < $n; $i++) { $tr = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1])); $atr[$i] = $tr * $ka + $atr[$i - 1] * (1 - $ka); } // 逐根计算真实波幅TR并EMA平滑得ATR
$vma = array_fill(0, $n, 0.0); $sv = 0.0;                            // 初始化成交量均线数组与滑动窗口和
for ($i = 0; $i < $n; $i++) { $sv += $v[$i]; if ($i >= 20) $sv -= $v[$i - 20]; $vma[$i] = $i >= 19 ? $sv / 20 : $sv / ($i + 1); } // 20周期滚动均量(不足20根按实际根数均)

$cash = $CASH0; $rounds = []; $negRun = 0; $pos = null; $rid = 0;    // 初始化: 现金/轮次记录/DIF<0连续根数/持仓/轮次编号
$nSig = 0; $nAdd = 0;                                                // 信号计数器与加仓计数器
for ($i = 1; $i < $n; $i++) {                                        // 主循环: 从第2根K线开始逐根走线
    if ($difR[$i] < 0) $negRun++; else $negRun = 0;                  // 统计DIF<0(零轴下)连续根数, 用于评分
    if ($i < 35) continue;                                           // 前35根指标未预热, 跳过
    // ---- 持仓: 先走线判爆仓/止盈 ----
    if ($pos !== null) {                                             // 有持仓时先处理出场逻辑
        $avg = $pos['costN'] / $pos['q'];                            // 当前持仓均价 = 总名义成本/总数量
        $liqPx = $avg * (1 - 1 / $LEV + $MMR);                       // 爆仓价 = 均价×(1-1/杠杆+维持保证金率)
        $tpPx = $avg * (1 + $TPP);                                   // 止盈价 = 均价×(1+2%), 跟随均价上移
        if ($l[$i] <= $liqPx) {                                     // 爆仓: 保证金全损
            $cash += 0;                                              // 爆仓后保证金归零, 现金不变(已在开仓时扣除)
            $rounds[$rid]['t1'] = $t[$i]; $rounds[$rid]['px1'] = $liqPx; $rounds[$rid]['why'] = 'LIQ'; // 记录平仓时间/爆仓价/原因
            $rounds[$rid]['pnl'] = -($pos['m'] + $pos['fee']);       // 本轮亏损 = 保证金+手续费全损
            $rounds[$rid]['delta'] = round($cash - $rounds[$rid]['cb'], 2); // 本轮现金变动 = 期末现金-开仓前现金
            $rounds[$rid]['ca'] = round($cash, 2);                   // 记录平仓后现金余额
            $pos = null;                                             // 清空持仓
        } elseif ($h[$i] >= $tpPx) {                                // 止盈+2%: 市价全平
            $gross = $pos['costN'] * $TPP;                           // 毛利 = 名义成本×2%
            $exitFee = $pos['costN'] * (1 + $TPP) * $FEE;            // 平仓手续费 = 名义×(1+2%)×费率
            $cash += $pos['m'] + $gross - $exitFee;                  // 现金回补: 保证金+毛利-平仓手续费
            $rounds[$rid]['t1'] = $t[$i]; $rounds[$rid]['px1'] = $tpPx; $rounds[$rid]['why'] = 'TP'; // 记录平仓时间/止盈价/原因
            $rounds[$rid]['pnl'] = $gross - $exitFee - $pos['fee'];  // 本轮净盈亏 = 毛利-平仓手续费-开仓累计手续费
            $rounds[$rid]['delta'] = round($cash - $rounds[$rid]['cb'], 2); // 本轮现金变动
            $rounds[$rid]['ca'] = round($cash, 2);                   // 记录平仓后现金余额
            $pos = null;                                             // 清空持仓
        }
    }
    // ---- Score事件 ----
    if (!($macdR[$i] > 0 && $macdR[$i - 1] <= 0 && $difR[$i] < 0)) continue; // 候选信号: MACD柱上穿0轴且DIF仍<0(零轴下动能反转)
    $trough = 0.0;                                                   // 初始化柱谷值为0
    for ($j = max(0, $i - 30); $j < $i; $j++) if ($macdR[$j] < $trough) $trough = $macdR[$j]; // 找最近30根内最深柱谷
    $a = max($atr[$i - 1], 1e-9);                                    // 取前一根ATR(防除零)
    $s = 2.0 * min(abs($trough) / $a, 3.0) / 3.0 + 1.5 * min($negRun, 100) / 100.0; // 评分项: 深柱深度(ATR归一)+下跌持续期
    if ($negRun >= 40) {                                             // 下跌持续满40根才可能形成底背离
        $ss = $i - $negRun + 1; $mm = $ss + intdiv($negRun - 1, 2);  // 本轮下跌段起点ss与中点mm
        $t1 = $ss; for ($j = $ss; $j <= $mm; $j++) if ($l[$j] < $l[$t1]) $t1 = $j; // 前半段价格最低点t1
        $t2 = $mm + 1; for ($j = $mm + 1; $j < $i; $j++) if ($l[$j] < $l[$t2]) $t2 = $j; // 后半段价格最低点t2
        if ($l[$t2] < $l[$t1] && $macdR[$t2] > $macdR[$t1]) $s += 2.5; // 价创新低而柱谷抬高 → 底背离, 加2.5分
    }
    $s += 1.0;                                                       // 评分项: 上穿确认(候选信号本身满足), +1分
    if ($v[$i] > 1.5 * $vma[$i - 1] && $vma[$i - 1] > 0) $s += 1.0;  // 评分项: 放量(>1.5×20期均量), +1分
    if ($s < 2.0) continue;                                          // 只交易Score>=2的事件
    if ($t[$i] < $WIN_MS / 1000) continue;                          // 只在回测窗口内交易
    $nSig++;                                                         // 有效信号计数
    if ($pos !== null) {                                            // 加仓1U
        if ($cash >= $ADD + $LEV * $ADD * $FEE) {                    // 现金足够覆盖加仓保证金+手续费才加
            $p = $c[$i];                                             // 加仓价=当根收盘价
            $cash -= $ADD + $LEV * $ADD * $FEE;                      // 现金扣减: 1U保证金+名义100U的开仓手续费
            $pos['m'] += $ADD; $pos['q'] += $LEV * $ADD / $p; $pos['costN'] += $LEV * $ADD; $pos['fee'] += $LEV * $ADD * $FEE; // 累加: 保证金/数量/名义成本/手续费
            $pos['adds'][] = ['t' => $t[$i], 'p' => $p];             // 持仓内记录本次加仓(时间+价格)
            $rounds[$rid]['adds'][] = ['t' => $t[$i], 'p' => $p];    // 轮次记录同步追加加仓明细
            $nAdd++;                                                 // 加仓次数+1
        }
    } elseif ($cash >= $FIRST + $LEV * $FIRST * $FEE) {             // 开首仓30U(保证金1.5U费)
        $p = $c[$i]; $rid++;                                         // 开仓价=当根收盘价, 轮次编号+1
        $cb0 = $cash;                                                // 记录开仓前现金(用于算本轮现金变动)
        $pos = ['m' => $FIRST, 'q' => $LEV * $FIRST / $p, 'costN' => $LEV * $FIRST, 'fee' => $LEV * $FIRST * $FEE, 'adds' => []]; // 建仓: 保证金30U/数量=3000U名义/价/手续费/加仓列表
        $cash -= $FIRST + $LEV * $FIRST * $FEE;                      // 现金扣减: 30U保证金+名义3000U手续费
        $rounds[$rid] = ['t0' => $t[$i], 'px0' => $p, 's' => round($s, 1), 'adds' => [], 'cb' => $cb0]; // 初始化轮次记录(开仓时间/价格/评分/加仓表/开仓前现金)
    }
}
// 期末未平仓 → 按最后收盘价标记
$open = null;                                                        // 期末未平仓信息初始化为空
if ($pos !== null) {                                                 // 若回测结束仍持仓
    $avg = $pos['costN'] / $pos['q'];                                // 持仓均价
    $unreal = $pos['q'] * ($c[$n - 1] - $avg) - $pos['costN'] * (1 + ($c[$n - 1] / $avg)) * $FEE - $pos['fee']; // 浮动盈亏 = 数量×价差-双边手续费(按名义)-累计手续费
    $open = ['t0' => $rounds[$rid]['t0'], 'px0' => $rounds[$rid]['px0'], 'm' => $pos['m'], 'nAdd' => count($pos['adds']), 'avg' => $avg, 'last' => $c[$n - 1], 'unreal' => round($unreal, 2)]; // 组装未平仓信息(开仓时间/价/保证金/加仓次数/均价/最新价/浮盈)
}
// 汇总

$tot = 0.0; $w = 0; $tpN = 0; $liqN = 0; $byMonth = [];              // 初始化: 总盈亏/盈利轮数/止盈数/爆仓数/按月统计
foreach ($rounds as $k => $rd) {                                     // 遍历所有轮次
    if (!isset($rd['why'])) continue;                                // 跳过尚未平仓(期末持仓)的轮次
    $tot += $rd['pnl'];                                              // 累加总盈亏
    if ($rd['pnl'] > 0) $w++;                                        // 盈利轮数+1
    if ($rd['why'] == 'TP') $tpN++; else $liqN++;                    // 分别统计止盈/爆仓次数
    $mk = gmdate('Y-m', $rd['t1'] + 8 * 3600);                       // 按平仓时间(东八区)取年月作为分月键
    if (!isset($byMonth[$mk])) $byMonth[$mk] = ['n' => 0, 'pnl' => 0.0, 'w' => 0]; // 初始化该月统计结构
    $byMonth[$mk]['n']++; $byMonth[$mk]['pnl'] += $rd['pnl']; if ($rd['pnl'] > 0) $byMonth[$mk]['w']++; // 累加: 笔数/盈亏/盈利笔数
}
// 输出轮次(含每轮加仓时间序列重新走一遍记录)
$sum = ['bars' => $n, 'nSig' => $nSig, 'nAdd' => $nAdd, 'nRound' => count($rounds), 'pnl' => round($tot, 2), // 汇总: K线数/信号数/加仓数/轮次数/总盈亏
    'cashEnd' => round($CASH0 + $tot, 2), 'wr' => ($tpN + $liqN) ? round($w / ($tpN + $liqN) * 100, 1) : 0,   // 期末现金/胜率(已平仓口径)
    'tp' => $tpN, 'liq' => $liqN, 'open' => $open,                                                                  // 止盈/爆仓次数/未平仓信息
    'winStart' => gmdate('Y-m-d', $WIN_MS / 1000 + 8 * 3600), 'winEnd' => gmdate('Y-m-d', $NOW_MS / 1000 + 8 * 3600)]; // 回测窗口起止日期(东八区)
file_put_contents(__DIR__ . '/../web/score2100eth_data.json', json_encode([ // 结果写入JSON供报告页读取
    'sum' => $sum, 'byMonth' => $byMonth, 'rounds' => array_values($rounds)])); // 内容: 汇总/按月统计/轮次明细
printf("sigs=%d rounds=%d adds=%d tp=%d liq=%d pnl=%+.2f cashEnd=%.2f\n", $nSig, count($rounds), $nAdd, $tpN, $liqN, $tot, $CASH0 + $tot); // 控制台打印核心结果
printf("REAL_CASH=%.2f openM=%.2f\n", $cash, $pos !== null ? $pos['m'] : 0); // 打印真实现金余额与未平仓占用保证金
