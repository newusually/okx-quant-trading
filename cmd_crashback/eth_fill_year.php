<?php
/**
 * eth_fill_year.php — 从OKX补齐最近一年历史K线 (CLI)
 * 本脚本做什么: 数据回填工具, 两步: ① ETH/BTC 1h + ETH 4h 补入各自现有 kline_* 表(OHLCV全列);
 *   ② 全市场476合约日线收盘价补入新表 kline1d(只存收盘c, 用于市场宽度计算)。
 * 参数: 回补终点 = 当前时间-365天; OKX history-candles, 100根/页, 限速+重试。
 * 输出: 控制台进度; 写库表 kline_eth/btc_usdt_swap_{1h,4h} 与 kline1d。
 */
ini_set('memory_limit', '512M');                    // 内存上限512M
set_time_limit(0);                                  // 取消时间限制
$START = (int)((time() - 365 * 86400) * 1000);      // 回补起点: 一年前毫秒时间戳

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接trading库
$DB = db();                                         // 全局连接
$DB->query("CREATE TABLE IF NOT EXISTS kline1d (inst_id VARCHAR(64) NOT NULL, candle_time BIGINT NOT NULL, c DOUBLE NOT NULL, PRIMARY KEY(inst_id, candle_time)) ENGINE=InnoDB"); // 建日线表(不存在时): 主键(合约,时间), 仅存收盘

function okx($url) {                                // OKX API封装(重试3次)
    for ($try = 0; $try < 3; $try++) {              // 最多3次
        $j = json_decode(@file_get_contents($url), true); // 请求并解析
        if ($j && ($j['code'] ?? '1') === '0') return $j['data']; // 成功返回数据
        usleep(300000);                             // 失败等0.3s重试
    }
    return [];                                      // 失败返回空
}
function upsert_k($tb, $rows, $start) {             // 批量upsert K线(OHLCV全列)
    global $DB;                                     // 全局连接
    $vals = []; $n = 0;                             // 值/计数
    foreach ($rows as $r) {                         // 逐根
        $ts = (int)$r[0];                           // 时间戳
        if ($ts < $start) continue;                 // 早于起点跳过
        $vals[] = "($ts," . (float)$r[1] . "," . (float)$r[2] . "," . (float)$r[3] . "," . (float)$r[4] . "," . (float)$r[5] . "," . (float)$r[6] . "," . (float)$r[7] . "," . (int)$r[8] . ")"; // 组值(ts,o,h,l,c,vol,volCcy,volQuote,confirm)
        $n++;
    }
    if (!$vals) return 0;                           // 无数据返回0
    $sql = "INSERT INTO `$tb` (candle_time,o,h,l,c,vol,vol_ccy,vol_quote,confirm) VALUES " . implode(',', $vals) . // 批量插入
           " ON DUPLICATE KEY UPDATE o=VALUES(o),h=VALUES(h),l=VALUES(l),c=VALUES(c),vol=VALUES(vol),confirm=VALUES(confirm)"; // 冲突时更新
    $DB->query($sql);                               // 执行
    return $n;                                      // 返回条数
}
function fetch_bar($instId, $bar, $start, $tb, $label) { // 分页拉取某合约某周期到起点
    $after = ''; $total = 0; $reqs = 0; $oldest = PHP_INT_MAX; // 游标/计数/最早
    while (true) {                                  // 分页循环
        $url = "https://www.okx.com/api/v5/market/history-candles?instId=$instId&bar=$bar&limit=100$after"; // 100根/页
        $data = okx($url);                          // 请求
        $reqs++;
        if (!$data) break;                          // 空数据结束
        $oldest = PHP_INT_MAX;                      // 重置最早
        foreach ($data as $r) $oldest = min($oldest, (int)$r[0]); // 求本批最早
        $total += upsert_k($tb, $data, $start);     // 入库并累计
        if ($oldest <= $start || count($data) < 100) break; // 到起点/不足一页则停
        $after = '&after=' . $oldest;               // 游标前移
        usleep(120000);                             // 限速120ms
        if ($reqs > 200) break;                     // 保险: 最多200请求
    }
    echo "  $label [$instId $bar] req=$reqs ins=$total\n"; flush(); // 打印该序列完成情况
    return $total;                                  // 返回插入数
}

echo "== ① ETH/BTC 1h + ETH 4h (一年) ==\n"; flush(); // 第一步标题
fetch_bar('ETH-USDT-SWAP', '1H', $START, 'kline_eth_usdt_swap_1h', 'main'); // ETH 1h回补
fetch_bar('BTC-USDT-SWAP', '1H', $START, 'kline_btc_usdt_swap_1h', 'main'); // BTC 1h回补
fetch_bar('ETH-USDT-SWAP', '4H', $START, 'kline_eth_usdt_swap_4h', 'main'); // ETH 4h回补

echo "== ② 全市场 1D → kline1d ==\n"; flush();      // 第二步标题
$tabs = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline%_1h'"); // 枚举1h表确定合约
$insts = [];                                        // 合约列表
foreach ($tabs as $t) {
    if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $t[0], $m)) $insts[] = $m[1] . '-USDT-SWAP'; // 表名→instId
}
echo "insts=" . count($insts) . "\n"; flush();      // 打印合约数
$i = 0;
foreach ($insts as $inst) {                         // 逐合约
    $after = ''; $total = 0;                        // 游标/插入数
    while (true) {                                  // 分页循环
        $url = "https://www.okx.com/api/v5/market/history-candles?instId=$inst&bar=1D&limit=100$after"; // 日线100根/页
        $data = okx($url);                          // 请求
        if (!$data) break;                          // 空则结束
        $oldest = PHP_INT_MAX;                      // 本批最早
        foreach ($data as $r) $oldest = min($oldest, (int)$r[0]); // 更新
        $vals = [];                                 // 待插值
        foreach ($data as $r) {                     // 逐根
            $ts = (int)$r[0];
            if ($ts < $START) continue;             // 早于起点跳过
            $vals[] = "('" . $DB->real_escape_string($inst) . "',$ts," . (float)$r[4] . ")"; // 只存(inst_id, ts, 收盘)
        }
        if ($vals) {                                // 有值则入库
            $sql = "INSERT INTO kline1d (inst_id,candle_time,c) VALUES " . implode(',', $vals) . " ON DUPLICATE KEY UPDATE c=VALUES(c)"; // 批量upsert
            $DB->query($sql);
            $total += count($vals);                 // 累计
        }
        if ($oldest <= $START || count($data) < 100) break; // 到起点/不足页则停
        $after = '&after=' . $oldest;               // 游标前移
        usleep(120000);                             // 限速
        if ($total > 5000) break;                   // 单合约上限5000根(保险)
    }
    if ((++$i % 60) == 0) { echo "  1D $i/" . count($insts) . "\n"; flush(); } // 每60个打印进度
}
echo "FILL OK\n";                                   // 完成
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; } // 查询行数组(脚本尾部定义, 首次调用在②)
