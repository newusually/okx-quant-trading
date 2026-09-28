<?php
/**
 * fill1m_back.php — 从OKX补 ETH-USDT-SWAP 1m 更早历史K线 (当前最早 2025-09-26 06:08 → 目标再往前366天)
 * 接口: /api/v5/market/history-candles (100根/次, 限速20次/2s) · INSERT IGNORE 幂等
 * 本脚本为数据回填工具（非策略回测）：查询本库 kline_eth_usdt_swap_1m 表当前最早K线时间，
 * 以该时间-366天为回补目标，通过 OKX history-candles 接口用 after 参数向更早翻页拉取，
 * 逐根写入本地 MariaDB trading 库的 1m K线表（列名 o/h/l/c/vol），最后打印补齐后的时间范围。
 */
ini_set('memory_limit', '1024M'); // 提高PHP内存上限到1G，防止长时间翻页累积内存溢出
set_time_limit(0); // 取消脚本执行时间限制，允许无限期运行直到补齐目标
$TARGET = null; // 运行时按当前最早时间-366天
$m = new mysqli('127.0.0.1', 'root', '', 'trading'); // 连接本地 MariaDB 的 trading 库
$m->set_charset('utf8mb4'); // 设置连接字符集为 utf8mb4，避免编码问题
$r = $m->query("SELECT MIN(candle_time) FROM kline_eth_usdt_swap_1m"); // 查询1m K线表中当前最早的一根K线时间戳
$min = (int)$r->fetch_row()[0]; // 取出最早时间戳（毫秒），作为回补起点
$TARGET = $min - 366 * 86400 * 1000; // 回补目标时间 = 最早时间再往前366天（毫秒）
echo "current_min=$min (" . gmdate('Y-m-d H:i', $min / 1000 + 8 * 3600) . ") target=" . gmdate('Y-m-d H:i', $TARGET / 1000 + 8 * 3600) . "\n"; // 打印当前最早时间与回补目标时间（UTC+8显示）
$after = $min; $req = 0; $ins = 0; $buf = []; $stmt = $m->prepare("INSERT IGNORE INTO kline_eth_usdt_swap_1m (candle_time,`o`,`h`,`l`,`c`,vol,vol_ccy,vol_quote,confirm) VALUES (?,?,?,?,?,?,?,?,1)"); // 初始化翻页游标/请求计数/插入计数，并预编译幂等插入语句（confirm 固定为1，列名为 o/h/l/c/vol）
while (true) { // 主循环：不断向更早方向翻页，直到达到目标
    $url = "https://www.okx.com/api/v5/market/history-candles?instId=ETH-USDT-SWAP&bar=1m&after=$after&limit=100"; // 拼接OKX历史K线接口URL：after=$after 表示返回该时间戳之前的100根1m K线
    $ch = curl_init($url); // 初始化 cURL 会话
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true]); // 设置返回字符串、20秒超时、开启SSL证书校验
    $body = curl_exec($ch); curl_close($ch); // 执行请求并立即关闭句柄，拿到响应体
    if ($body === false) { echo "curl fail, retry\n"; sleep(2); continue; } // 网络失败则等2秒重试本次循环
    $j = json_decode($body, true); // 解析OKX返回的JSON为关联数组
    if (($j['code'] ?? '1') !== '0' || empty($j['data'])) { echo "stop: code=" . ($j['code'] ?? '-') . " msg=" . ($j['msg'] ?? 'empty') . " req=$req ins=$ins\n"; break; } // 接口报错或无更多数据（已到OKX最早历史）则打印原因并退出主循环
    $batchMin = PHP_INT_MAX; // 记录本批次中最小（最旧）的时间戳，用作下一页的 after 游标
    foreach ($j['data'] as $row) { // 遍历本批返回的每根K线（格式：[ts,o,h,l,c,vol,volCcy,volQuote,confirm]）
        $ts = (int)$row[0]; if ($ts < $batchMin) $batchMin = $ts; // 取K线时间戳并刷新本批最小值
        if ($ts < $TARGET) continue; // 早于回补目标的K线直接跳过不写库
        $o = (float)$row[1]; $h = (float)$row[2]; $l = (float)$row[3]; $c = (float)$row[4]; // 解析开高低收价格
        $v = (float)$row[5]; $vc = (float)$row[6]; $vq = (float)$row[7]; // 解析成交量（张）、币量、计价币量
        $stmt->bind_param('iddddddd', $ts, $o, $h, $l, $c, $v, $vc, $vq); $stmt->execute(); $ins += $stmt->affected_rows; // 绑定参数并执行插入，累加实际新增行数（INSERT IGNORE 重复时为0）
    }
    $after = $batchMin; $req++; // 游标前移到本批最旧K线，请求计数加1
    if ($req % 200 === 0) echo "req=$req ins=$ins at=" . gmdate('Y-m-d H:i', $after / 1000 + 8 * 3600) . "\n"; // 每200次请求打印一次进度
    if ($after <= $TARGET) { echo "reached target\n"; break; } // 已翻页到回补目标时间则结束
    usleep(110000); // 每次请求间隔约110ms（约9次/秒），低于OKX限速20次/2秒
}
$stmt->close(); // 关闭预编译语句释放资源
$r = $m->query("SELECT MIN(candle_time), MAX(candle_time), COUNT(*) FROM kline_eth_usdt_swap_1m"); // 回补完成后重新查询表的最早/最晚时间与总行数
$x = $r->fetch_row(); // 取出统计结果
echo "final: min=" . gmdate('Y-m-d H:i', $x[0] / 1000 + 8 * 3600) . " max=" . gmdate('Y-m-d H:i', $x[1] / 1000 + 8 * 3600) . " count=$x[2]\n"; // 打印补齐后表的时间范围与总K线数（UTC+8显示）
