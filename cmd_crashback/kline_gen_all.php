<?php
/**
 * kline_gen_all.php — 为 1m/3m/5m/15m/1h 生成K线+MACD+打分 JSON → web/kline_eth_{tf}.json
 * MACD(12,26,60) · Score>=2 的信号存入 sc (火箭标注, 同 score_eth.php 公式, 满分8)
 * 本脚本为前端图表数据批量生成器（非策略回测）：对 1m/3m/5m/15m/1h 五个周期依次执行——
 * 从 trading 库对应K线表读数据，算 MACD(12,26,60)、ATR14、均量20 与火箭打分（score>=2），
 * 打包成 JSON 写入 web/kline_eth_{tf}.json，每周期处理完打印统计并释放内存。
 */
ini_set('memory_limit', '8192M'); // 提高内存上限到8G，1m全年数据量大
set_time_limit(0); // 取消执行时间限制
function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 定义数据库连接函数：连本地 trading 库并设 utf8mb4
$DB = db(); // 建立数据库连接
function emaRaw($a, $p) { // 定义原始EMA计算函数（不取整，保证MACD精度）
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = $a[0]; // 初始化长度、输出、平滑系数、首值
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; } // 标准EMA递推
    return $out; // 返回未取整EMA序列
}
foreach (['1m', '3m', '5m', '15m', '1h'] as $tf) { // 依次处理五个K线周期
    $t0 = microtime(true); // 记录本周期开始时间（用于统计耗时）
    $r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_eth_usdt_swap_$tf ORDER BY candle_time ASC"); // 按时间升序读该周期全部K线（列为 o/h/l/c/vol）
    $t = []; $o = []; $h = []; $l = []; $c = []; $v = []; // 初始化时间与开高低收量的并行数组
    while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0) continue; $t[] = (int)($x[0] / 1000); $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; } // 逐行读取：跳过收盘价<=0的脏数据，时间转秒
    $r->free(); // 释放结果集
    $n = count($c); // 本周期K线根数
    if ($n < 100) { echo "$tf: only $n bars, skip\n"; continue; } // 数据不足100根则跳过该周期
    $O = []; $H = []; $L = []; $C = []; // 初始化前端展示用取整价格数组
    for ($i = 0; $i < $n; $i++) { $O[] = round($o[$i], 2); $H[] = round($h[$i], 2); $L[] = round($l[$i], 2); $C[] = round($c[$i], 2); } // 开高低收取2位小数
    $e12 = emaRaw($c, 12); $e26 = emaRaw($c, 26); // 收盘价的12/26周期EMA
    $difR = []; for ($i = 0; $i < $n; $i++) $difR[] = $e12[$i] - $e26[$i]; // DIF = EMA12 - EMA26
    $deaR = emaRaw($difR, 60); // DEA = DIF 的60周期EMA（本框架口径）
    $d = []; $e = []; $m = []; $macdR = []; // 初始化输出DIF/DEA/柱数组与原始柱数组
    for ($i = 0; $i < $n; $i++) { $macdR[] = 2.0 * ($difR[$i] - $deaR[$i]); $d[] = round($difR[$i], 4); $e[] = round($deaR[$i], 4); $m[] = round($macdR[$i], 4); } // 逐根：原始柱=2*(DIF-DEA)，输出值取整4位
    // ATR14 / volMA20
    $atr = []; $atr[0] = $h[0] - $l[0]; $ka = 2.0 / 15; // 初始化ATR：首根用振幅，平滑系数对应14周期
    for ($i = 1; $i < $n; $i++) { $tr = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1])); $atr[$i] = $tr * $ka + $atr[$i - 1] * (1 - $ka); } // Wilder平滑递推ATR14
    $vma = array_fill(0, $n, 0.0); $sv = 0.0; // 初始化均量数组与滑动窗口和
    for ($i = 0; $i < $n; $i++) { $sv += $v[$i]; if ($i >= 20) $sv -= $v[$i - 20]; $vma[$i] = $i >= 19 ? $sv / 20 : $sv / ($i + 1); } // 滑动窗口算20周期均量
    // 打分
    $sc = []; $negRun = 0; // 初始化火箭信号数组与DIF连负计数
    for ($i = 0; $i < $n; $i++) { // 逐根扫描打分
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
        if ($s >= 2.0) $sc[] = [$i, round($s, 1)]; // 总分>=2记为火箭信号：[索引, 分数]
    }
    file_put_contents(__DIR__ . "/../web/kline_eth_$tf.json", // 写入本周期的前端数据文件
        json_encode(['tf' => $tf, 'n' => $n, 't' => $t, 'o' => $O, 'h' => $H, 'l' => $L, 'c' => $C, 'd' => $d, 'e' => $e, 'm' => $m, 'sc' => $sc])); // JSON内容：周期、根数、时间、取整价格、DIF/DEA/柱、信号
    printf("%s: bars=%d rockets=%d span=%s~%s size=%.1fMB %.0fs\n", $tf, $n, count($sc), // 打印本周期统计：根数、信号数、时间范围（UTC+8）
        gmdate('Y-m-d H:i', $t[0] + 8 * 3600), gmdate('Y-m-d H:i', $t[$n - 1] + 8 * 3600), // 首末K线时间
        filesize(__DIR__ . "/../web/kline_eth_$tf.json") / 1048576, microtime(true) - $t0); // 文件大小(MB)与耗时(秒)
    unset($t, $o, $h, $l, $c, $v, $O, $H, $L, $C, $e12, $e26, $difR, $deaR, $d, $e, $m, $macdR, $atr, $vma, $sc); // 释放本周期全部大数组内存，防止1m周期溢出
}
echo "all done\n"; // 全部周期处理完成
