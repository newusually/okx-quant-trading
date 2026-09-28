<?php
/**
 * fill1m_back2.php — 反向补充进程: 从 2024-09-25 目标端用 before 参数向 newer 方向拉,
 * 与 fill1m_back.php(向 older 拉) 两头对拼, 在 2025-03-01 22:08 附近会合。INSERT IGNORE 幂等。
 * 本脚本为1m K线回补的“反向补充”进程（非策略回测）：从 2024-09-25 附近的时间戳出发，
 * 使用 OKX history-candles 的 before 参数向“更新”方向翻页，与正向回填进程两头对拼，
 * 追到 2025-03-01 22:08 的会合点即停止，写入 trading 库 kline_eth_usdt_swap_1m 表。
 */
ini_set('memory_limit', '512M'); // 提高内存上限到512M，保证长时间运行稳定
set_time_limit(0); // 取消执行时间限制
$STOP_TS = 1740835680000; // 2025-03-01 22:08 (UTC+8) — 主进程已覆盖到此, 追上即停
$before = 1747215680000;  // 2024-09-25 目标起点略后
$m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); // 连接本地 trading 库并设置 utf8mb4 字符集
$stmt = $m->prepare("INSERT IGNORE INTO kline_eth_usdt_swap_1m (candle_time,`o`,`h`,`l`,`c`,vol,vol_ccy,vol_quote,confirm) VALUES (?,?,?,?,?,?,?,?,1)"); // 预编译幂等插入语句（confirm 固定1，列为 o/h/l/c/vol）
$req = 0; $ins = 0; // 初始化请求计数与实际插入行数计数
while (true) { // 主循环：不断向更新方向翻页，直到追上正向进程
    $url = "https://www.okx.com/api/v5/market/history-candles?instId=ETH-USDT-SWAP&bar=1m&before=$before&limit=100"; // 拼接接口URL：before=$before 表示返回该时间戳之后（更新方向）的100根1m K线
    $ch = curl_init($url); // 初始化 cURL 会话
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]); // 设置以字符串返回响应、20秒超时
    $body = curl_exec($ch); curl_close($ch); // 执行请求并关闭句柄
    if ($body === false) { sleep(2); continue; } // 网络失败等2秒后重试
    $j = json_decode($body, true); // 解析JSON响应
    if (($j['code'] ?? '1') !== '0' || empty($j['data'])) { echo "stop: code=" . ($j['code'] ?? '-') . " req=$req ins=$ins\n"; break; } // 接口报错或无数据则打印统计并退出
    $batchMax = 0; // 记录本批次中最大（最新）的时间戳，用作下一页 before 游标
    foreach ($j['data'] as $row) { // 遍历本批每根K线：[ts,o,h,l,c,vol,volCcy,volQuote,confirm]
        $ts = (int)$row[0]; if ($ts > $batchMax) $batchMax = $ts; // 取时间戳并刷新本批最大值
        if ($ts >= $STOP_TS) continue; // 已到/越过会合点的K线跳过（正向进程已覆盖）
        $o=(float)$row[1]; $h=(float)$row[2]; $l=(float)$row[3]; $c=(float)$row[4]; // 解析开高低收
        $v=(float)$row[5]; $vc=(float)$row[6]; $vq=(float)$row[7]; // 解析三类成交量
        $stmt->bind_param('iddddddd', $ts, $o, $h, $l, $c, $v, $vc, $vq); $stmt->execute(); $ins += $stmt->affected_rows; // 绑定参数执行插入，累加实际新增行数
    }
    $before = $batchMax; $req++; // 游标前移到本批最新K线，请求计数加1
    if ($req % 200 === 0) echo "req=$req ins=$ins at=" . gmdate('Y-m-d H:i', $before / 1000 + 8 * 3600) . "\n"; // 每200次请求打印一次进度（UTC+8）
    if ($before >= $STOP_TS) { echo "met main stream\n"; break; } // 已追上正向回填进程的覆盖范围则结束
    usleep(110000); // 请求间隔约110ms，控制在限速以内
}
$stmt->close(); // 关闭预编译语句
echo "done req=$req ins=$ins\n"; // 打印总请求数与总插入行数
