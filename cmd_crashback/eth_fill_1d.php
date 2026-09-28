<?php
/**
 * eth_fill_1d.php — 全市场 1D 收盘价补一年 (断点续传 + 失败日志)
 * 本脚本做什么: 数据回填工具——对全市场所有有1h表的合约, 从OKX history-candles 拉取日线(1D)
 *   收盘价补入 kline1d 表(约一年), 已有≥300根且足够老的合约跳过(断点续传), API失败重试3次并写日志。
 * 参数: 回补终点 = 当前时间-365天; 日志 E:/datas/log/fill1d.log。
 * 输出: 控制台+日志进度; 写库表 kline1d(inst_id, candle_time, c)。
 */
ini_set('memory_limit', '512M');                    // 内存上限512M
set_time_limit(0);                                  // 取消时间限制
$START = (int)((time() - 365 * 86400) * 1000);      // 回补起点: 一年前的毫秒时间戳
$LOG = fopen('E:/datas/log/fill1d.log', 'w');       // 打开日志文件(覆盖写)

function logp($s) { global $LOG; fwrite($LOG, date('H:i:s ') . $s . "\n"); fflush($LOG); echo $s . "\n"; flush(); } // 同时写日志与控制台(带时间)
function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接trading库
$DB = db();                                         // 全局连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; } // 查询行数组

function okx($url) {                                // OKX API请求封装(重试3次)
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: Mozilla/5.0\r\n"]]); // 10s超时+UA头
    for ($try = 0; $try < 3; $try++) {              // 最多重试3次
        $raw = @file_get_contents($url, false, $ctx); // 发起请求(静默失败)
        if ($raw === false) { usleep(400000); continue; } // 网络失败等0.4s重试
        $j = json_decode($raw, true);               // 解析JSON
        if ($j && ($j['code'] ?? '1') === '0') return $j['data']; // code=0返回数据
        logp("  ! api code=" . ($j['code'] ?? '?') . " " . substr($url, 0, 90)); // 记录异常code
        usleep(600000);                             // 等0.6s再试
    }
    return [];                                      // 3次失败返回空
}

$tabs = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline%_1h'"); // 枚举所有1h表(确定合约范围)
$insts = [];                                        // 合约列表(全称)
foreach ($tabs as $t) if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $t[0], $m)) $insts[] = strtoupper($m[1]) . '-USDT-SWAP'; // 表名→OKX instId
logp("insts=" . count($insts));                     // 记录合约数

$done = 0; $skip = 0; $fail = 0;                    // 完成/跳过/失败计数
$i = 0;
foreach ($insts as $inst) {                         // 逐合约
    // 断点续传: 已有足够日线且够老则跳过
    $have = rows("SELECT COUNT(*), IFNULL(MIN(candle_time),0) FROM kline1d WHERE inst_id='" . $DB->real_escape_string($inst) . "'"); // 查已有日线数与最早时间
    if ((int)$have[0][0] >= 300 && (int)$have[0][1] <= $START + 3 * 86400000) { $skip++; continue; } // ≥300根且最早早于起点+3天 → 跳过
    $after = ''; $total = 0; $reqs = 0;             // 分页游标/本合约插入数/请求数
    while (true) {                                  // 分页循环拉取
        $url = "https://www.okx.com/api/v5/market/history-candles?instId=$inst&bar=1D&limit=100$after"; // 100根/页
        $data = okx($url);                          // 请求
        $reqs++;
        if (!$data) { $fail++; break; }             // 拉不到则记失败并结束本合约
        $oldest = PHP_INT_MAX;                      // 本批最早时间
        $vals = [];                                 // 待插入值
        foreach ($data as $r) {                     // 逐根
            $ts = (int)$r[0]; $oldest = min($oldest, $ts); // 更新最早
            if ($ts < $START) continue;             // 早于起点跳过(只补一年)
            $vals[] = "('" . $DB->real_escape_string($inst) . "',$ts," . (float)$r[4] . ")"; // 组值(inst_id, ts, 收盘)
        }
        if ($vals) {                                // 有数据则入库
            $sql = "INSERT INTO kline1d (inst_id,candle_time,c) VALUES " . implode(',', $vals) . " ON DUPLICATE KEY UPDATE c=VALUES(c)"; // 批量upsert
            if (!$DB->query($sql)) logp("  ! SQL: " . $DB->error); // SQL失败记日志
            else $total += count($vals);            // 累计插入数
        }
        if ($oldest <= $START || count($data) < 100 || $reqs > 15) break; // 到达起点/不足一页/超15请求则停
        $after = '&after=' . $oldest;               // 游标前移
        usleep(300000);                             // 限速300ms
    }
    $done++;
    if ($done % 25 == 0) logp("progress $done/" . count($insts) . " (skip=$skip fail=$fail) last=$inst ins=$total"); // 每25个打印进度
    usleep(200000);                                 // 限速200ms
}
logp("FILL1D OK done=$done skip=$skip fail=$fail"); // 收尾汇总
