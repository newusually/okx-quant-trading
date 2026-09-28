<?php
/**
 * scoreup_all.php — 全数字币合约 15m Score递增买入回测 (2026-09-27)
 * 规则: 15m K线, MACD(12,26,60) Score公式(同 klineall.php 火箭, 满分8, 只记 Score>=2 事件)
 *       信号 = 当前Score事件的分数 > 前一个Score事件的分数 → 买入
 *       止盈 价格+0.5% · 不设止损 · 爆仓线-0.6%(100x) · 超时7天平仓 · taker 0.05%双边
 * 口径: 每笔本金1U × 100x杠杆(名义100U) · 同票同时只持一仓 · 回测区间=最近30天
 * 输出: web/scoreup_data.json → 报告页 web/scoreupall.php
 */
ini_set('memory_limit', '4096M');                                    // 提升PHP内存上限到4G, 全市场多币种数据量大
set_time_limit(0);                                                   // 取消执行时间限制
$DB = new mysqli('127.0.0.1', 'root', '', 'trading');                // 连接本地MariaDB的trading库
$DB->set_charset('utf8mb4');                                         // 设置字符集utf8mb4

$LEV = 20; $FEE = 0.0005; $U = 1.0; $TP = 0.005; $MMR = 0.004;       // 参数: 杠杆20x/taker费0.05%/每笔1U/止盈+0.5%/维持保证金率0.4%
$TMO_MS = 7 * 86400 * 1000;                                          // 超时=7天(毫秒), 到期强制平仓
$NOW_MS = (int)(microtime(true) * 1000);                             // 当前毫秒时间戳
$WIN_MS = $NOW_MS - 30 * 86400 * 1000;          // 最近30天            // 回测窗口起点=30天前
$LOAD_MS = $NOW_MS - 120 * 86400 * 1000;        // 加载120天供MACD预热 // 数据加载起点=120天前, 多加载90天供MACD等指标预热

function emaRaw($a, $p) {                                            // 通用EMA计算函数(数组a, 周期p)
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = 0.0; // 初始化: 长度/输出/平滑系数k=2/(p+1)
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; } // 首值取原始值, 之后递推EMA
    return $out;                                                     // 返回整条EMA序列
}

$rs = $DB->query("SHOW TABLES LIKE 'kline\\_%\\_15m'");              // 枚举库里所有15m K线表(即全部数字币合约)
$tables = [];                                                        // 表名列表
while ($r = $rs->fetch_row()) $tables[] = $r[0];                     // 逐行收集表名
$rs->free();                                                         // 释放结果集
sort($tables);                                                       // 表名排序, 保证处理顺序稳定

$trades = []; $perInst = [];                                         // 全局成交列表与各币种信号计数
$nTab = 0;                                                           // 有效参与的表(币种)计数
foreach ($tables as $tb) {                                           // 逐个币种表处理
    $inst = strtoupper(preg_replace('/^kline_(.+)_15m$/', '$1', $tb)); // 从表名提取币对名并转大写
    $inst = str_replace('_', '-', $inst);                            // 下划线转连字符(如 eth_usdt→ETH-USDT)
    $r = $DB->query("SELECT candle_time,`h`,`l`,`c`,vol FROM $tb WHERE candle_time >= $LOAD_MS ORDER BY candle_time ASC"); // 读取该币120天15m K线(时间升序)
    if (!$r) continue;                                               // 查询失败则跳过该表
    $t = []; $h = []; $l = []; $c = []; $v = [];                     // 初始化时间/高/低/收/量数组
    while ($x = $r->fetch_row()) { if ((float)$x[3] <= 0) continue; $t[] = (int)($x[0] / 1000); $h[] = (float)$x[1]; $l[] = (float)$x[2]; $c[] = (float)$x[3]; $v[] = (float)$x[4]; } // 逐行取数: 跳过收盘价<=0的脏数据, 毫秒转秒
    $r->free();                                                      // 释放结果集
    $n = count($c);                                                  // 该币K线根数
    if ($n < 600) continue;                                          // 数据不足600根(指标预热不够)则跳过
    $nTab++;                                                         // 有效币种数+1
    // MACD 12/26/60
    $e12 = emaRaw($c, 12); $e26 = emaRaw($c, 26);                    // 计算12/26周期EMA
    $difR = []; for ($i = 0; $i < $n; $i++) $difR[] = $e12[$i] - $e26[$i]; // DIF = EMA12 - EMA26
    $deaR = emaRaw($difR, 60);                                       // DEA = DIF的60周期EMA
    $macdR = []; for ($i = 0; $i < $n; $i++) $macdR[] = 2.0 * ($difR[$i] - $deaR[$i]); // MACD柱 = 2×(DIF-DEA)
    // ATR14 / volMA20
    $atr = []; $atr[0] = $h[0] - $l[0]; $ka = 2.0 / 15;              // ATR首值取首根振幅, 平滑系数=2/15(14周期EMA口径)
    for ($i = 1; $i < $n; $i++) { $tr = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1])); $atr[$i] = $tr * $ka + $atr[$i - 1] * (1 - $ka); } // 逐根TR取三值最大并EMA平滑
    $vma = array_fill(0, $n, 0.0); $sv = 0.0;                        // 初始化量均线数组与滑动窗口和
    for ($i = 0; $i < $n; $i++) { $sv += $v[$i]; if ($i >= 20) $sv -= $v[$i - 20]; $vma[$i] = $i >= 19 ? $sv / 20 : $sv / ($i + 1); } // 20周期滚动均量
    // Score事件 + 递增规则 + 模拟
    $sc = []; $negRun = 0; $prevScore = 0.0; $pos = null; $nominal = $U * $LEV; // 初始化: 事件列表/DIF<0连续根数/前次评分/持仓/名义值100U
    for ($i = 1; $i < $n; $i++) {                                    // 逐根走线
        if ($difR[$i] < 0) $negRun++; else $negRun = 0;              // 维护DIF<0连续根数
        if ($i < 35) continue;                                       // 前35根指标未预热, 跳过
        // ---- 持仓走线 (在信号判断前先处理, 同根K线爆仓优先) ----
        if ($pos !== null) {                                         // 有持仓时先处理出场
            $liqPx = $pos['px'] * (1 - 1 / $LEV + $MMR);             // 爆仓价 = 开仓价×(1-1/杠杆+维持保证金率)
            $done = false;                                           // 本根是否已平仓标记
            if ($l[$i] <= $liqPx) {                                       // 爆仓
                $trades[] = ['inst' => $inst, 't0' => $pos['t0'], 'px0' => $pos['px'], 't1' => $t[$i], 'px1' => $liqPx, 'why' => 'LIQ', 'pnl' => -$U, 's' => $pos['s']]; // 记录爆仓成交: 亏光1U本金
                $pos = null; $done = true;                           // 清仓并标记已处理
            } elseif ($h[$i] >= $pos['tp']) {                             // 止盈+0.5%
                $gross = $nominal * $TP; $fee = ($nominal + $nominal * (1 + $TP)) * $FEE; // 毛利=名义×0.5%, 双边手续费=开+平名义×费率
                $trades[] = ['inst' => $inst, 't0' => $pos['t0'], 'px0' => $pos['px'], 't1' => $t[$i], 'px1' => $pos['tp'], 'why' => 'TP', 'pnl' => $gross - $fee, 's' => $pos['s']]; // 记录止盈成交
                $pos = null; $done = true;                           // 清仓并标记已处理
            } elseif ($t[$i] - $pos['t0'] >= $TMO_MS) {                   // 超时7天
                $exit = $c[$i]; $gross = $nominal * ($exit - $pos['px']) / $pos['px']; $fee = ($nominal + $nominal * $exit / $pos['px']) * $FEE; // 超时按收盘价平: 毛利=名义×涨跌幅, 双边手续费
                $trades[] = ['inst' => $inst, 't0' => $pos['t0'], 'px0' => $pos['px'], 't1' => $t[$i], 'px1' => $exit, 'why' => 'TO', 'pnl' => $gross - $fee, 's' => $pos['s']]; // 记录超时成交
                $pos = null; $done = true;                           // 清仓并标记已处理
            }
            if ($done) continue;                                     // 本根已平仓, 不再看信号
            continue; // 持仓中不看新信号
        }
        // ---- Score事件 ----
        if (!($macdR[$i] > 0 && $macdR[$i - 1] <= 0 && $difR[$i] < 0)) continue; // 候选信号: MACD柱上穿0且DIF<0(零轴下反转)
        $trough = 0.0;                                               // 柱谷初值
        for ($j = max(0, $i - 30); $j < $i; $j++) if ($macdR[$j] < $trough) $trough = $macdR[$j]; // 最近30根内最深柱谷
        $a = max($atr[$i - 1], 1e-9);                                // 前一根ATR(防除零)
        $s = 2.0 * min(abs($trough) / $a, 3.0) / 3.0 + 1.5 * min($negRun, 100) / 100.0; // 评分: 深柱深度(ATR归一)+下跌持续期
        if ($negRun >= 40) {                                         // 下跌满40根才判底背离
            $ss = $i - $negRun + 1; $mm = $ss + intdiv($negRun - 1, 2); // 本轮下跌段起点ss与中点mm
            $t1 = $ss; for ($j = $ss; $j <= $mm; $j++) if ($l[$j] < $l[$t1]) $t1 = $j; // 前半段价格最低点t1
            $t2 = $mm + 1; for ($j = $mm + 1; $j < $i; $j++) if ($l[$j] < $l[$t2]) $t2 = $j; // 后半段价格最低点t2
            if ($l[$t2] < $l[$t1] && $macdR[$t2] > $macdR[$t1]) $s += 2.5; // 价创新低而柱谷抬高 → 底背离+2.5分
        }
        $s += 1.0;                                                   // 上穿确认恒+1分
        if ($v[$i] > 1.5 * $vma[$i - 1] && $vma[$i - 1] > 0) $s += 1.0; // 放量(>1.5×均量)+1分
        if ($s < 2.0) continue;                       // 只记 Score>=2 (同火箭口径)
        $sc[] = [$i, round($s, 1)];                                  // 记录Score事件(根序号+分值)
        // 递增规则: 当前分 > 前一分 → 买入 (回测窗口内, 空仓时)
        if ($s > $prevScore && $t[$i] >= $WIN_MS / 1000 && $t[$i] <= $NOW_MS / 1000) { // 分数递增且在30天回测窗口内
            $pos = ['t0' => $t[$i], 'px' => $c[$i], 'tp' => $c[$i] * (1 + $TP), 's' => round($s, 1)]; // 开仓: 记录时间/开仓价/止盈价(+0.5%)/评分
        }
        $prevScore = $s;                                             // 更新前次评分为当前分
    }
    if ($sc) $perInst[$inst] = count($sc);                           // 该币有信号则记录事件数
    unset($t, $h, $l, $c, $v, $e12, $e26, $difR, $deaR, $macdR, $atr, $vma, $sc); // 释放该币大数组, 控制内存
}

// ---- 汇总 ----
$tot = 0.0; $w = 0; $liq = 0; $to = 0; $tp = 0;                      // 初始化: 总盈亏/盈利数/爆仓数/超时数/止盈数
$byInst = [];                                                        // 按币种统计表
usort($trades, function ($a, $b) { return $a['t1'] <=> $b['t1'] ?: strcmp($a['inst'], $b['inst']); }); // 成交按平仓时间排序(同时间按币种名)
foreach ($trades as $tr) {                                           // 遍历全部成交
    $tot += $tr['pnl'];                                              // 累加总盈亏
    if ($tr['pnl'] > 0) $w++;                                        // 盈利笔数+1
    if ($tr['why'] == 'LIQ') $liq++; elseif ($tr['why'] == 'TO') $to++; else $tp++; // 分别统计爆仓/超时/止盈
    if (!isset($byInst[$tr['inst']])) $byInst[$tr['inst']] = ['n' => 0, 'pnl' => 0.0, 'w' => 0]; // 初始化该币统计结构
    $byInst[$tr['inst']]['n']++; $byInst[$tr['inst']]['pnl'] += $tr['pnl']; // 累加: 笔数/盈亏
    if ($tr['pnl'] > 0) $byInst[$tr['inst']]['w']++;                 // 该币盈利笔数+1
}
$sum = ['nTab' => $nTab, 'nTrade' => count($trades), 'pnl' => round($tot, 2), // 汇总: 币种数/总笔数/总盈亏
    'wr' => count($trades) ? round($w / count($trades) * 100, 1) : 0,         // 总胜率%
    'tp' => $tp, 'liq' => $liq, 'to' => $to,                                   // 止盈/爆仓/超时笔数
    'winInst' => count(array_filter($byInst, function ($x) { return $x['pnl'] > 0; })), // 盈利币种数
    'loseInst' => count(array_filter($byInst, function ($x) { return $x['pnl'] <= 0; })), // 亏损币种数
    'winStart' => gmdate('Y-m-d', $WIN_MS / 1000 + 8 * 3600), 'winEnd' => gmdate('Y-m-d', $NOW_MS / 1000 + 8 * 3600)]; // 回测窗口起止日期(东八区)

$samp = array_slice($trades, 0, 4000);                               // 明细最多保留前4000笔样本
file_put_contents(__DIR__ . '/../web/scoreup_data.json', json_encode([ // 结果写入JSON供报告页读取
    'sum' => $sum, 'byInst' => $byInst, 'trades' => $samp, 'nTradesAll' => count($trades)])); // 内容: 汇总/按币统计/明细样本/总笔数
printf("tables=%d trades=%d pnl=%+.2f wr=%.1f%% tp=%d liq=%d to=%d\n", $nTab, count($trades), $tot, $sum['wr'], $tp, $liq, $to); // 控制台打印核心结果
