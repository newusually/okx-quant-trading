<?php
/**
 * nq_agg.php — 从 kline_nq_1m 本地聚合 3m/5m/15m/1h (不访问外网)
 * 本脚本为纳指K线数据聚合工具（非策略回测）：从本地 trading 库的纳指1m表 kline_nq_1m
 * 逐根聚合出 3m/5m/15m/1h 高周期K线（REPLACE INTO 写回 kline_nq_{tf} 表），纯本地计算
 * 不访问外网；最后打印各周期表的行数与时间范围汇总。
 */
ini_set('memory_limit', '2048M'); // 提高内存上限到2G
set_time_limit(0); // 取消执行时间限制
$m = new mysqli('127.0.0.1', 'root', '', 'trading'); // 连接本地 MariaDB 的 trading 库
$m->set_charset('utf8mb4'); // 设置连接字符集为 utf8mb4

foreach (['3m' => 180, '5m' => 300, '15m' => 900, '1h' => 3600] as $tf => $sec) { // 遍历四个目标周期及其对应秒数
    $t0 = microtime(true); // 记录本周期开始时间（统计耗时）
    $m->query("DELETE FROM kline_nq_$tf"); // 先清空目标周期表，全量重建
    $ms = $sec * 1000; // 本周期一根K线的毫秒长度
    $r = $m->query("SELECT candle_time,o,h,l,c,vol FROM kline_nq_1m ORDER BY candle_time ASC"); // 按时间升序读出全部1m K线
    $buf = null; $out = []; $cnt = 0; // 初始化当前聚合桶、待写缓冲、总写出行数
    $st = $m->prepare("REPLACE INTO kline_nq_$tf (candle_time,o,h,l,c,vol) VALUES (?,?,?,?,?,?)"); // 预编译 REPLACE 写入语句（主键冲突则覆盖）
    $flush = function () use (&$out, &$cnt, $st, &$buf) { // 定义落库闭包：把缓冲里的聚合K线批量写入
        if ($buf !== null) { $out[] = $buf; $buf = null; } // 先把未收盘的当前桶收尾入缓冲
        foreach ($out as $x) { // 逐行写库
            $tms = $x[0]; $o = $x[1]; $h = $x[2]; $l = $x[3]; $c = $x[4]; $v = $x[5]; // 取出桶内六字段
            $st->bind_param('iddddi', $tms, $o, $h, $l, $c, $v); // 绑定参数
            $st->execute(); // 执行写入
        }
        $cnt += count($out); $out = []; // 累计计数并清空缓冲
    };
    while ($x = $r->fetch_row()) { // 逐根扫描1m K线
        $tms = (int)$x[0]; $o = (float)$x[1]; $h = (float)$x[2]; $l = (float)$x[3]; $c = (float)$x[4]; $v = (float)$x[5]; // 解析时间与开高低收量
        if ($o <= 0) continue; // 跳过无效K线
        $b = intdiv($tms, $ms) * $ms; // 计算本1m所属高周期桶的起始毫秒（对齐到周期边界）
        if ($buf !== null && $buf[0] !== $b) { // 桶起始变化说明上一根高周期K线已完整
            $out[] = $buf; $buf = null; // 上一桶入缓冲
            if (count($out) >= 5000) $flush(); // 攒满5000根批量落库一次
        }
        if ($buf === null) $buf = [$b, $o, $h, $l, $c, $v]; // 新桶：直接用本根初始化开高低收量
        else { // 同桶合并
            if ($h > $buf[2]) $buf[2] = $h; // 更新桶内最高价
            if ($l < $buf[3]) $buf[3] = $l; // 更新桶内最低价
            $buf[4] = $c; $buf[5] += $v; // 收盘价取最新，成交量累加
        }
    }
    $flush(); // 扫描结束后把最后一桶及剩余缓冲落库
    $st->close(); $r->free(); // 关闭语句、释放结果集
    printf("%s: rows=%d %.1fs\n", $tf, $cnt, microtime(true) - $t0); // 打印本周期聚合行数与耗时
}
// 汇总
foreach (['1m', '3m', '5m', '15m', '1h'] as $tf) { // 遍历五个周期打印汇总
    $q = $m->query("SELECT COUNT(*), FROM_UNIXTIME(MIN(candle_time)/1000), FROM_UNIXTIME(MAX(candle_time)/1000) FROM kline_nq_$tf")->fetch_row(); // 查各表行数与首末时间
    echo "$tf: $q[0]  $q[1] ~ $q[2]\n"; // 打印行数与时间范围
}
echo "done\n"; // 全部完成
