<?php
/**
 * kline1m_gen.php — 生成 ETH 1m 全年K线+MACD 数据文件 → web/kline1m_eth.json
 * 指标: MACD(12,26,60) DIF=EMA12-EMA26 · DEA=EMA60(DIF) · 柱=2*(DIF-DEA)
 * 页面: web/kline1meth.php (Canvas 联动图)
 * 本脚本为前端图表数据生成器（非策略回测）：从 trading 库 kline_eth_usdt_swap_1m 表
 * 读出全部1m K线，计算 MACD(12,26,60) 与“火箭”打分（score_eth.php 同款公式，满分8，
 * score>=2 者作为信号标注），打包成 JSON 写入 web/kline1m_eth.json 供 Canvas 页面渲染。
 */
ini_set('memory_limit', '8192M'); // 提高内存上限到8G，1m全年数据量很大
set_time_limit(0); // 取消执行时间限制
function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 定义数据库连接函数：连本地 trading 库并设 utf8mb4
$DB = db(); // 建立数据库连接
$r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_eth_usdt_swap_1m ORDER BY candle_time ASC"); // 按时间升序读出1m表全部K线（列为 o/h/l/c/vol）
$t = []; $o = []; $h = []; $l = []; $c = []; $v = []; // 初始化时间与开高低收量的并行数组
while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0) continue; $t[] = (int)($x[0] / 1000); $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; } // 逐行读取：跳过收盘价<=0的脏数据，时间转秒，其余转浮点入数组
$r->free(); // 释放查询结果集
$n = count($c); // K线总根数
// 输出用取整数组
$O = []; $H = []; $L = []; $C = []; // 初始化给前端展示用的取整价格数组
for ($i = 0; $i < $n; $i++) { $O[] = round($o[$i], 2); $H[] = round($h[$i], 2); $L[] = round($l[$i], 2); $C[] = round($c[$i], 2); } // 每根K线开高低收取2位小数
echo "bars=$n\n"; // 打印K线总数
function emaAll($a, $p) { // 定义EMA计算函数（返回取整到4位的EMA序列，本版未实际使用）
    $n = count($a); $out = []; $k = 2.0 / ($p + 1); $e = $a[0]; // 初始化：长度、输出、平滑系数 k=2/(周期+1)、首值
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[] = round($e, 4); } // 标准EMA递推：首根取原值，其后按权重混合
    return $out; // 返回EMA序列
}
// DIF 用未取整的 EMA 算, 最后再取整
function emaRaw($a, $p) { // 定义原始EMA计算函数（不取整，保证MACD精度）
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = $a[0]; // 初始化长度、输出数组、平滑系数、首值
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; } // EMA递推，保留完整浮点精度
    return $out; // 返回未取整的EMA序列
}
$e12 = emaRaw($c, 12); $e26 = emaRaw($c, 26); // 分别计算收盘价的12周期与26周期EMA
$difR = []; for ($i = 0; $i < $n; $i++) $difR[] = $e12[$i] - $e26[$i]; // DIF = EMA12 - EMA26（未取整原始值）
$deaR = emaRaw($difR, 60); // DEA = DIF 的60周期EMA（本框架口径，非常规9）
$d = []; $e = []; $m = []; // 初始化输出用数组：DIF(d)、DEA(e)、MACD柱(m)
for ($i = 0; $i < $n; $i++) { $dv = round($difR[$i], 4); $ev = round($deaR[$i], 4); $d[] = $dv; $e[] = $ev; $m[] = round(2.0 * ($difR[$i] - $deaR[$i]), 4); } // 逐根取整4位：DIF、DEA、柱=2*(DIF-DEA)
echo "macd done\n"; // 打印MACD计算完成

// ---- 打分公式 (同 score_eth.php, 满分8): 候选=柱上穿>0且DIF<0, 存 score>=2 的火箭标注 ----
$atr = []; $atr[0] = $h[0] - $l[0]; $ka = 2.0 / 15; // 初始化ATR：首根用振幅，平滑系数对应14周期
for ($i = 1; $i < $n; $i++) { // 从第2根开始递推ATR14
    $tr = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1])); // 真实波幅TR=振幅/跳空高/跳空低三者最大
    $atr[$i] = $tr * $ka + $atr[$i - 1] * (1 - $ka); // Wilder平滑更新ATR
}
$vma = array_fill(0, $n, 0.0); $sv = 0.0; // 初始化20周期均量数组与滑动窗口和
for ($i = 0; $i < $n; $i++) { $sv += $v[$i]; if ($i >= 20) $sv -= $v[$i - 20]; $vma[$i] = $i >= 19 ? $sv / 20 : $sv / ($i + 1); } // 滑动窗口计算均量：满20根取均值，不满取已有均值
$sc = []; $negRun = 0; $macdR = []; // 初始化火箭信号数组、DIF连负根数、原始MACD柱数组
for ($i = 0; $i < $n; $i++) $macdR[] = 2.0 * ($difR[$i] - $deaR[$i]); // 先算全部原始MACD柱（不取整）
for ($i = 0; $i < $n; $i++) { // 逐根扫描打分
    if ($difR[$i] < 0) $negRun++; else $negRun = 0; // 维护DIF连续为负的计数
    if ($i < 35) continue; // 前35根数据不足跳过
    if (!($macdR[$i] > 0 && $macdR[$i - 1] <= 0 && $difR[$i] < 0)) continue; // 候选条件：MACD柱上穿0且DIF仍在0轴下（水下金叉）
    $trough = 0.0; // 记录最近30根内MACD柱的最低谷值
    for ($j = max(0, $i - 30); $j < $i; $j++) if ($macdR[$j] < $trough) $trough = $macdR[$j]; // 扫描前30根找柱谷值
    $a = max($atr[$i - 1], 1e-9); // 取前一根ATR做归一化分母（防除零）
    $s = 2.0 * min(abs($trough) / $a, 3.0) / 3.0 // 打分项1：谷深相对ATR的深度（最多2分，封顶3倍ATR）
       + 1.5 * min($negRun, 100) / 100.0; // 打分项2：DIF水下持续时长（最多1.5分）
    if ($negRun >= 40) { // 若DIF水下超过40根，检查双底背离
        $ss = $i - $negRun + 1; $mm = $ss + intdiv($negRun - 1, 2); // 水下区间起点与中点
        $t1 = $ss; for ($j = $ss; $j <= $mm; $j++) if ($l[$j] < $l[$t1]) $t1 = $j; // 前半段最低价谷1
        $t2 = $mm + 1; for ($j = $mm + 1; $j < $i; $j++) if ($l[$j] < $l[$t2]) $t2 = $j; // 后半段最低价谷2
        if ($l[$t2] < $l[$t1] && $macdR[$t2] > $macdR[$t1]) $s += 2.5; // 价创新低但柱抬高=底背离，加2.5分
    }
    $s += 1.0; // 确认(上穿)恒1
    if ($v[$i] > 1.5 * $vma[$i - 1] && $vma[$i - 1] > 0) $s += 1.0; // 信号K线放量超1.5倍均量再加1分
    if ($s >= 2.0) $sc[] = [$i, round($s, 1)]; // 总分>=2 记为火箭信号：[索引, 分数]
}
echo "rockets=" . count($sc) . "\n"; // 打印火箭信号总数

$out = json_encode(['n' => $n, 't' => $t, 'o' => $O, 'h' => $H, 'l' => $L, 'c' => $C, 'd' => $d, 'e' => $e, 'm' => $m, 'sc' => $sc]); // 打包全部数据为JSON：根数、时间、取整价格、DIF/DEA/柱、信号
file_put_contents(__DIR__ . '/../web/kline1m_eth.json', $out); // 写入前端数据文件 web/kline1m_eth.json
echo "saved size=" . round(filesize(__DIR__ . '/../web/kline1m_eth.json') / 1048576, 1) . "MB\n"; // 打印输出文件大小（MB）
