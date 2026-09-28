<?php
/**
 * fill_seg.php — 通用OKX历史K线分段回填: php fill_seg.php <表名后缀> <bar> <after_ms> <stop_ms>
 * 从 after 时间向更老翻页, 直到 <= stop 或接口无数据。INSERT IGNORE 幂等, 429/限速自动重试。
 * 本脚本为通用K线分段回填工具（非策略回测）：命令行传入表名后缀（如 5m/1h）、OKX bar 参数、
 * 起始时间戳 after 与终止时间戳 stop，从 after 向更老方向翻页拉取 ETH-USDT-SWAP 的历史K线，
 * 逐根写入 trading 库的 kline_eth_usdt_swap_<后缀> 表，遇到 stop 时间或接口无数据即停止。
 */
ini_set('memory_limit', '512M'); // 提高内存上限到512M
set_time_limit(0); // 取消执行时间限制
[$tf, $bar, $after, $stop] = [$argv[1], $argv[2], (int)$argv[3], (int)$argv[4]]; // 解析命令行参数：表名后缀、bar周期、起始毫秒时间戳、终止毫秒时间戳
$m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); // 连接本地 trading 库并设置字符集
$stmt = $m->prepare("INSERT IGNORE INTO kline_eth_usdt_swap_$tf (candle_time,`o`,`h`,`l`,`c`,vol,vol_ccy,vol_quote,confirm) VALUES (?,?,?,?,?,?,?,?,1)"); // 预编译幂等插入语句，目标表按后缀拼接（列为 o/h/l/c/vol）
$req = 0; $ins = 0; $fail = 0; // 初始化请求计数、插入计数、连续失败计数
echo "[$tf] start after=" . gmdate('Y-m-d H:i', $after / 1000 + 8 * 3600) . " stop=" . gmdate('Y-m-d H:i', $stop / 1000 + 8 * 3600) . "\n"; // 打印本次回填的时间区间（UTC+8）
while (true) { // 主循环：不断向更老方向翻页
    $url = "https://www.okx.com/api/v5/market/history-candles?instId=ETH-USDT-SWAP&bar=$bar&after=$after&limit=100"; // 拼接接口URL：after 游标向更老翻页，每次100根
    $ch = curl_init($url); // 初始化 cURL 会话
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]); // 字符串返回、20秒超时
    $body = curl_exec($ch); curl_close($ch); // 执行请求并关闭句柄
    if ($body === false) { if (++$fail > 30) { echo "[$tf] curl dead\n"; break; } usleep(500000); continue; } // 网络失败计数加1，连续失败超30次则放弃，否则等0.5秒重试
    $j = json_decode($body, true); // 解析JSON响应
    if (($j['code'] ?? '1') !== '0') { // 限速等重试
        if (++$fail > 30) { echo "[$tf] stop: code={$j['code']} msg={$j['msg']}\n"; break; } // 接口报错（如429限速）连续超30次则打印原因并放弃
        usleep(800000); continue; // 否则等0.8秒后重试本次请求
    }
    $fail = 0; // 请求成功则清零失败计数
    if (empty($j['data'])) { echo "[$tf] no data. req=$req ins=$ins\n"; break; } // 接口返回空数据（已到OKX最早历史）则结束
    $batchMin = PHP_INT_MAX; // 记录本批最旧时间戳作为下一页游标
    foreach ($j['data'] as $row) { // 遍历本批每根K线：[ts,o,h,l,c,vol,volCcy,volQuote,confirm]
        $ts = (int)$row[0]; if ($ts < $batchMin) $batchMin = $ts; // 取时间戳并刷新本批最小值
        if ($ts <= $stop) continue; // 早于等于终止时间的K线跳过不写
        $o=(float)$row[1]; $h=(float)$row[2]; $l=(float)$row[3]; $c=(float)$row[4]; // 解析开高低收
        $v=(float)$row[5]; $vc=(float)$row[6]; $vq=(float)$row[7]; // 解析三类成交量
        $stmt->bind_param('iddddddd', $ts, $o, $h, $l, $c, $v, $vc, $vq); $stmt->execute(); $ins += $stmt->affected_rows; // 绑定并插入，累加实际新增行数
    }
    $after = $batchMin; $req++; // 游标前移，请求计数加1
    if ($req % 200 === 0) echo "[$tf] req=$req ins=$ins at=" . gmdate('Y-m-d H:i', $after / 1000 + 8 * 3600) . "\n"; // 每200次请求打印进度
    if ($after <= $stop) { echo "[$tf] reached stop. req=$req ins=$ins\n"; break; } // 到达终止时间则结束
    usleep(110000); // 请求间隔约110ms，控制在限速以内
}
$stmt->close(); // 关闭预编译语句
echo "[$tf] DONE req=$req ins=$ins\n"; // 打印本段回填完成的总请求数与插入行数
