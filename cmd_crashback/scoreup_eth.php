<?php
/**
 * scoreup_eth.php — ETH 15m Score递增买入 · 一年回测 (2026-09-27)
 * 规则同 scoreup_all.php: Score>=2事件 当前分>前一事件分→买入, TP+0.5%, 无止损,
 * 爆仓-4.6%(20x), 超时7天, taker 0.05%, 每笔1U×20x, 同票一仓. 窗口=最近365天.
 * 输出: web/scoreup_eth_data.json → 报告页 web/scoreupeth.php
 */
ini_set('memory_limit', '4096M');                                    // 提升PHP内存上限到4G
set_time_limit(0);                                                   // 取消执行时间限制
$DB = new mysqli('127.0.0.1', 'root', '', 'trading');                // 连接本地MariaDB的trading库
$DB->set_charset('utf8mb4');                                         // 设置字符集utf8mb4

$LEV = 20; $FEE = 0.0005; $U = 1.0; $TP = 0.005; $MMR = 0.004;       // 参数: 杠杆20x/taker费0.05%/每笔1U/止盈+0.5%/维持保证金率0.4%
$TMO_MS = 7 * 86400 * 1000;                                          // 超时=7天(毫秒), 到期强制平仓
$NOW_MS = (int)round(microtime(true) * 1000);                        // 当前毫秒时间戳
$WIN_MS = $NOW_MS - 365 * 86400 * 1000;                              // 回测窗口起点=365天前

function emaRaw($a, $p) {                                            // 通用EMA计算函数(数组a, 周期p)
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = 0.0; // 初始化: 长度/输出/平滑系数k=2/(p+1)
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; } // 首值取原始值, 之后递推EMA
    return $out;                                                     // 返回整条EMA序列
}

$r = $DB->query("SELECT candle_time,`h`,`l`,`c`,vol FROM kline_eth_usdt_swap_15m ORDER BY candle_time ASC"); // 读取ETH 15m K线(列名h/l/c/vol, 时间升序)
$t = []; $h = []; $l = []; $c = []; $v = [];                         // 初始化时间/高/低/收/量数组
while ($x = $r->fetch_row()) { if ((float)$x[3] <= 0) continue; $t[] = (int)($x[0] / 1000); $h[] = (float)$x[1]; $l[] = (float)$x[2]; $c[] = (float)$x[3]; $v[] = (float)$x[4]; } // 逐行取数: 跳过收盘价<=0的脏数据, 毫秒转秒
$r->free();                                                          // 释放结果集
$n = count($c);                                                      // K线总根数
echo "bars=$n\n";                                                    // 打印K线数量

$e12 = emaRaw($c, 12); $e26 = emaRaw($c, 26);                        // 计算12/26周期EMA
$difR = []; for ($i = 0; $i < $n; $i++) $difR[] = $e12[$i] - $e26[$i]; // DIF = EMA12 - EMA26
$deaR = emaRaw($difR, 60);                                           // DEA = DIF的60周期EMA
$macdR = []; for ($i = 0; $i < $n; $i++) $macdR[] = 2.0 * ($difR[$i] - $deaR[$i]); // MACD柱 = 2×(DIF-DEA)
$atr = []; $atr[0] = $h[0] - $l[0]; $ka = 2.0 / 15;                  // ATR首值取首根振幅, 平滑系数=2/15(14周期EMA口径)
for ($i = 1; $i < $n; $i++) { $tr = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1])); $atr[$i] = $tr * $ka + $atr[$i - 1] * (1 - $ka); } // 逐根TR取三值最大并EMA平滑
$vma = array_fill(0, $n, 0.0); $sv = 0.0;                            // 初始化量均线数组与滑动窗口和
for ($i = 0; $i < $n; $i++) { $sv += $v[$i]; if ($i >= 20) $sv -= $v[$i - 20]; $vma[$i] = $i >= 19 ? $sv / 20 : $sv / ($i + 1); } // 20周期滚动均量

$trades = []; $nSig = 0; $negRun = 0; $prevScore = 0.0; $pos = null; $nominal = $U * $LEV; // 初始化: 成交列表/信号数/DIF<0连续根数/前次评分/持仓/名义值20U
for ($i = 1; $i < $n; $i++) {                                        // 主循环: 逐根走线
    if ($difR[$i] < 0) $negRun++; else $negRun = 0;                  // 维护DIF<0连续根数
    if ($i < 35) continue;                                           // 前35根指标未预热, 跳过
    if ($pos !== null) {                                             // 有持仓时先处理出场
        $liqPx = $pos['px'] * (1 - 1 / $LEV + $MMR);                 // 爆仓价 = 开仓价×(1-1/杠杆+维持保证金率)≈-4.6%
        if ($l[$i] <= $liqPx) {                                      // 当根最低价触及爆仓价 → 爆仓
            $trades[] = ['t0' => $pos['t0'], 'px0' => $pos['px'], 't1' => $t[$i], 'px1' => $liqPx, 'why' => 'LIQ', 'pnl' => -$U, 's' => $pos['s']]; // 记录爆仓成交: 亏光1U本金
            $pos = null; continue;                                   // 清仓, 本根结束
        } elseif ($h[$i] >= $pos['tp']) {                            // 当根最高价触及止盈价 → 止盈+0.5%
            $gross = $nominal * $TP; $fee = ($nominal + $nominal * (1 + $TP)) * $FEE; // 毛利=名义×0.5%, 双边手续费
            $trades[] = ['t0' => $pos['t0'], 'px0' => $pos['px'], 't1' => $t[$i], 'px1' => $pos['tp'], 'why' => 'TP', 'pnl' => $gross - $fee, 's' => $pos['s']]; // 记录止盈成交
            $pos = null; continue;                                   // 清仓, 本根结束
        } elseif ($t[$i] - $pos['t0'] >= $TMO_MS) {                  // 持仓满7天 → 超时平仓
            $exit = $c[$i]; $gross = $nominal * ($exit - $pos['px']) / $pos['px']; $fee = ($nominal + $nominal * $exit / $pos['px']) * $FEE; // 按收盘价平: 毛利=名义×涨跌幅, 双边手续费
            $trades[] = ['t0' => $pos['t0'], 'px0' => $pos['px'], 't1' => $t[$i], 'px1' => $exit, 'why' => 'TO', 'pnl' => $gross - $fee, 's' => $pos['s']]; // 记录超时成交
            $pos = null; continue;                                   // 清仓, 本根结束
        }
        continue;                                                    // 持仓中不看新信号
    }
    if (!($macdR[$i] > 0 && $macdR[$i - 1] <= 0 && $difR[$i] < 0)) continue; // 候选信号: MACD柱上穿0且DIF<0(零轴下反转)
    $trough = 0.0;                                                   // 柱谷初值
    for ($j = max(0, $i - 30); $j < $i; $j++) if ($macdR[$j] < $trough) $trough = $macdR[$j]; // 最近30根内最深柱谷
    $a = max($atr[$i - 1], 1e-9);                                    // 前一根ATR(防除零)
    $s = 2.0 * min(abs($trough) / $a, 3.0) / 3.0 + 1.5 * min($negRun, 100) / 100.0; // 评分: 深柱深度(ATR归一)+下跌持续期
    if ($negRun >= 40) {                                             // 下跌满40根才判底背离
        $ss = $i - $negRun + 1; $mm = $ss + intdiv($negRun - 1, 2);  // 本轮下跌段起点ss与中点mm
        $t1 = $ss; for ($j = $ss; $j <= $mm; $j++) if ($l[$j] < $l[$t1]) $t1 = $j; // 前半段价格最低点t1
        $t2 = $mm + 1; for ($j = $mm + 1; $j < $i; $j++) if ($l[$j] < $l[$t2]) $t2 = $j; // 后半段价格最低点t2
        if ($l[$t2] < $l[$t1] && $macdR[$t2] > $macdR[$t1]) $s += 2.5; // 价创新低而柱谷抬高 → 底背离+2.5分
    }
    $s += 1.0;                                                       // 上穿确认恒+1分
    if ($v[$i] > 1.5 * $vma[$i - 1] && $vma[$i - 1] > 0) $s += 1.0;  // 放量(>1.5×均量)+1分
    if ($s < 2.0) continue;                                          // 只记Score>=2的事件
    $nSig++;                                                         // 有效信号计数
    if ($s > $prevScore && $t[$i] >= $WIN_MS / 1000) {               // 递增规则: 当前分>前次分且在回测窗口内 → 买入
        $pos = ['t0' => $t[$i], 'px' => $c[$i], 'tp' => $c[$i] * (1 + $TP), 's' => round($s, 1)]; // 开仓: 记录时间/开仓价/止盈价(+0.5%)/评分
    }
    $prevScore = $s;                                                 // 更新前次评分为当前分
}

$tot = 0.0; $w = 0; $liq = 0; $to = 0; $tp = 0; $byMonth = [];       // 初始化: 总盈亏/盈利数/爆仓数/超时数/止盈数/按月统计
foreach ($trades as $tr) {                                           // 遍历全部成交
    $tot += $tr['pnl'];                                              // 累加总盈亏
    if ($tr['pnl'] > 0) $w++;                                        // 盈利笔数+1
    if ($tr['why'] == 'LIQ') $liq++; elseif ($tr['why'] == 'TO') $to++; else $tp++; // 分别统计爆仓/超时/止盈
    $mk = gmdate('Y-m', $tr['t1'] + 8 * 3600);                       // 按平仓时间(东八区)取年月作为分月键
    if (!isset($byMonth[$mk])) $byMonth[$mk] = ['n' => 0, 'pnl' => 0.0, 'w' => 0]; // 初始化该月统计结构
    $byMonth[$mk]['n']++; $byMonth[$mk]['pnl'] += $tr['pnl']; if ($tr['pnl'] > 0) $byMonth[$mk]['w']++; // 累加: 笔数/盈亏/盈利笔数
}
$sum = ['bars' => $n, 'nSig' => $nSig, 'nTrade' => count($trades), 'pnl' => round($tot, 2), // 汇总: K线数/信号数/成交笔数/总盈亏
    'wr' => count($trades) ? round($w / count($trades) * 100, 1) : 0, // 总胜率%
    'tp' => $tp, 'liq' => $liq, 'to' => $to,                           // 止盈/爆仓/超时笔数
    'winStart' => gmdate('Y-m-d', $WIN_MS / 1000 + 8 * 3600), 'winEnd' => gmdate('Y-m-d', $NOW_MS / 1000 + 8 * 3600)]; // 回测窗口起止日期(东八区)
file_put_contents(__DIR__ . '/../web/scoreup_eth_data.json', json_encode([ // 结果写入JSON供报告页读取
    'sum' => $sum, 'byMonth' => $byMonth, 'trades' => $trades]));     // 内容: 汇总/按月统计/全部成交明细
printf("sigs=%d trades=%d pnl=%+.2f wr=%.1f%% tp=%d liq=%d to=%d\n", $nSig, count($trades), $tot, $sum['wr'], $tp, $liq, $to); // 控制台打印核心结果
