<?php
/**
 * fill_seg2.php — OKX历史K线分段回填v2: php fill_seg2.php <bar> <after> <stop> [after stop ...]
 * 多段顺序执行; 限速/网络错误无限重试(30分钟无进展才放弃); 自写日志 seg_{bar}.log
 * 本脚本为分段回填工具v2（非策略回测）：命令行传入一个 bar 周期与任意多组 after/stop 时间对，
 * 逐段从 after 向更老方向翻页拉取 ETH-USDT-SWAP 历史K线写入对应 K线表；
 * 限速/网络错误无限重试（仅当30分钟无任何进展才放弃），进度写入 seg_{bar}.log 日志文件。
 */
ini_set('memory_limit', '512M'); // 提高内存上限到512M
set_time_limit(0); // 取消执行时间限制
$bar = $argv[1]; // 取第一个命令行参数作为K线周期（如 1m/5m/1h）
$log = __DIR__ . "/seg_$bar.log"; // 计算本周期对应的日志文件路径
function L($bar, $s) { file_put_contents(__DIR__ . "/seg_{$bar}.log", date('H:i:s ') . $s . "\n", FILE_APPEND); } // 定义日志函数：带当前时分秒追加写入 seg_{bar}.log
$m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); // 连接本地 trading 库并设置字符集
$stmt = $m->prepare("INSERT IGNORE INTO kline_eth_usdt_swap_$bar (candle_time,`o`,`h`,`l`,`c`,vol,vol_ccy,vol_quote,confirm) VALUES (?,?,?,?,?,?,?,?,1)"); // 预编译幂等插入语句，目标表按周期拼接（列为 o/h/l/c/vol）
L($bar, "start args: " . implode(' ', array_slice($argv, 2))); // 记录启动时收到的所有时间段参数
for ($a = 2; $a < $argc; $a += 2) { // 按每2个参数一组（after, stop）顺序处理多段回填任务
    $after = (int)$argv[$a]; $stop = (int)$argv[$a + 1]; // 取出本段的起始与终止毫秒时间戳
    L($bar, "=== segment: " . gmdate('Y-m-d H:i', $after / 1000 + 8 * 3600) . " -> " . gmdate('Y-m-d H:i', $stop / 1000 + 8 * 3600)); // 写日志标记本段时间区间（UTC+8）
    $req = 0; $ins = 0; $lastProgress = time(); // 初始化本段的请求/插入计数与最近有进展的时间
    while (true) { // 本段主循环：不断向更老方向翻页
        $url = "https://www.okx.com/api/v5/market/history-candles?instId=ETH-USDT-SWAP&bar=" . preg_replace('/^(\d+)h$/', '${1}H', $bar) . "&after=$after&limit=100"; // 拼接接口URL：小写h周期名转成OKX要求的大写H，after 游标向更老翻页
        $ch = curl_init($url); // 初始化 cURL 会话
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]); // 字符串返回、20秒超时
        $body = curl_exec($ch); $cerr = curl_error($ch); curl_close($ch); // 执行请求，记录错误信息后关闭句柄
        $j = $body !== false ? json_decode($body, true) : null; // 请求成功则解析JSON，失败则置为 null
        if ($j === null || ($j['code'] ?? '1') !== '0') { // 网络失败或接口报错（含限速429）进入重试分支
            if (time() - $lastProgress > 1800) { L($bar, "ABORT: no progress 30min. last=$body"); break; } // 若已30分钟无任何进展则写日志放弃本段
            usleep(2000000); continue;   // 限速/网络错误: 无限重试
        }
        $fail = 0; // 成功后清零失败计数（保留字段）
        if (empty($j['data'])) { L($bar, "no data returned. req=$req ins=$ins"); break; } // 接口返回空数据（已到OKX最早历史）则结束本段
        $batchMin = PHP_INT_MAX; // 记录本批最旧时间戳作为下一页游标
        foreach ($j['data'] as $row) { // 遍历本批每根K线：[ts,o,h,l,c,vol,volCcy,volQuote,confirm]
            $ts = (int)$row[0]; if ($ts < $batchMin) $batchMin = $ts; // 取时间戳并刷新本批最小值
            if ($ts <= $stop) continue; // 早于等于终止时间的K线跳过不写
            $o=(float)$row[1]; $h=(float)$row[2]; $l=(float)$row[3]; $c=(float)$row[4]; // 解析开高低收
            $v=(float)$row[5]; $vc=(float)$row[6]; $vq=(float)$row[7]; // 解析三类成交量
            $stmt->bind_param('iddddddd', $ts, $o, $h, $l, $c, $v, $vc, $vq); $stmt->execute(); $ins += $stmt->affected_rows; // 绑定并插入，累加实际新增行数
        }
        $after = $batchMin; $req++; $lastProgress = time(); // 游标前移、请求计数加1、刷新最近进展时间
        if ($req % 500 === 0) L($bar, "req=$req ins=$ins at=" . gmdate('Y-m-d H:i', $after / 1000 + 8 * 3600)); // 每500次请求写一次进度日志（UTC+8）
        if ($after <= $stop) { L($bar, "segment done. req=$req ins=$ins"); break; } // 到达终止时间则写日志结束本段
        usleep(110000); // 请求间隔约110ms，控制在限速以内
    }
}
$stmt->close(); // 关闭预编译语句
L($bar, "ALL DONE"); // 写日志标记全部段回填完成
