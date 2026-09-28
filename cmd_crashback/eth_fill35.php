<?php
/**
 * eth_fill35.php — 补全 ETH-USDT-SWAP 3m/5m/15m 一年历史K线(OKX history-candles, curl复用连接)
 * 本脚本测什么/做什么: 数据回填工具——从OKX history-candles API 向前回补 ETH 3m/5m/15m K线约一年,
 *   断点续传(以表内最小时间为起点), INSERT IGNORE 去重, 限速+重试, 支持指定单周期。
 * 参数: 命令行 [3m|5m|15m|all], 目标终点=当前时间-366天。
 * 输出: 控制台进度(请求数/插入数/耗时), 写入库表 kline_eth_usdt_swap_{3m,5m,15m}。
 */
set_time_limit(0);                                  // 取消时间限制
$ONLY = $argv[1] ?? 'all';                          // 命令行参数: 指定周期, 默认all
function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接trading库
$DB = db();                                         // 全局连接
$ch = curl_init();                                  // 创建curl句柄(复用连接)
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, // 返回字符串/超时20s/连接超时10s
    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['Connection: keep-alive']]); // 跳过证书校验/启用压缩/保持长连接

$yearAgo = (time() - 366 * 86400) * 1000;           // 回补终点: 一年前的毫秒时间戳
$bars = $ONLY === 'all' ? ['3m' => 180000, '5m' => 300000, '15m' => 900000] : [$ONLY => ['3m' => 180000, '5m' => 300000, '15m' => 900000][$ONLY]]; // 周期→单根毫秒数(all=3周期, 或仅指定周期)
foreach ($bars as $bar => $ms) {                    // 逐周期回补
    $tbl = "kline_eth_usdt_swap_$bar";              // 目标表名
    $r = $DB->query("SELECT MIN(candle_time) FROM $tbl"); // 查表内最早时间
    $min = (int)($r->fetch_row()[0] ?? 0);          // 最小时间戳
    if ($min <= $yearAgo + $ms) { echo "$bar already full (min=$min)\n"; continue; } // 已覆盖一年则跳过(断点续传)
    $after = $min; $ins = 0; $req = 0; $err = 0; $t0 = time(); // 游标=最早时间/插入数/请求数/连续错误数/起始时间
    echo "$bar filling back to " . date('Y-m-d', (int)($yearAgo / 1000)) . "\n"; flush(); // 打印回补目标
    while ($after > $yearAgo) {                     // 未到一年前就一直拉
        curl_setopt($ch, CURLOPT_URL, "https://www.okx.com/api/v5/market/history-candles?instId=ETH-USDT-SWAP&bar=$bar&limit=100&after=$after"); // 请求比after更早的100根
        $j = json_decode(curl_exec($ch), true);     // 执行并解析JSON
        $req++;                                     // 请求计数
        if (!$j || ($j['code'] ?? '1') !== '0' || empty($j['data'])) { // 接口异常或空数据
            usleep(500000); if (++$err > 8) { echo "$bar ERR break at " . date('Y-m-d H:i', (int)($after / 1000)) . " msg=" . ($j['msg'] ?? 'null') . "\n"; break; } // 等0.5s重试, 连续9次失败则中断
            continue;
        }
        $err = 0;                                   // 成功则清零错误计数
        $stmt = $DB->prepare("INSERT IGNORE INTO $tbl (candle_time,`o`,`h`,`l`,`c`,vol,vol_ccy,vol_quote,confirm) VALUES (?,?,?,?,?,?,?,?,?)"); // 预编译插入(IGNORE去重)
        $earliest = PHP_INT_MAX;                    // 本批最早时间
        foreach ($j['data'] as $k) {                // 逐根入库
            $t = (int)$k[0];                        // 时间戳
            if ($t < $earliest) $earliest = $t;     // 更新本批最早
            $o1 = (float)$k[1]; $h1 = (float)$k[2]; $l1 = (float)$k[3]; $c1 = (float)$k[4]; // o/h/l/c
            $v1 = (float)$k[5]; $v2 = (float)$k[6]; $v3 = (float)$k[7]; $cf = (int)$k[8]; // 成交量/币量/ Quote量/确认标志
            $stmt->bind_param('idddddddi', $t, $o1, $h1, $l1, $c1, $v1, $v2, $v3, $cf); // 绑定参数
            $stmt->execute(); $ins += $stmt->affected_rows; // 执行并累计实际插入数
        }
        $stmt->close();                             // 关闭预处理
        $after = $earliest;                         // 游标前移(向更早)
        if ($req % 100 == 0) { echo "  $bar req=$req ins=$ins at " . date('Y-m-d H:i', (int)($after / 1000)) . " (" . (time() - $t0) . "s)\n"; flush(); } // 每100请求打印进度
        usleep(120000);                             // 限速: 每请求间120ms
    }
    $r2 = $DB->query("SELECT COUNT(*), FROM_UNIXTIME(MIN(candle_time)/1000,'%Y-%m-%d') FROM $tbl"); // 回补后统计
    [$cnt, $mn] = $r2->fetch_row();                 // 总行数/最早日期
    echo "$bar DONE ins=$ins req=$req total=$cnt min=$mn (" . (time() - $t0) . "s)\n"; flush(); // 打印该周期完成汇总
}
echo "ALL DONE\n";                                  // 全部完成
