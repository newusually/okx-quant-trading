<?php
/**
 * nq_live.php — 纳指实时数据端点 (nqall.php 每15秒轮询)
 * 职责: 实时拉取 Dukascopy 1m K线 → REPLACE 入库 kline_nq_1m → 返回最新价与最近20根
 * 只实时获取 1m: 拉 Dukascopy jetta API 实时1mK线 → REPLACE 入库 kline_nq_1m
 * (其他周期 3m/5m/15m/1h 不碰远端, 由 nq_agg.php 从 1m 本地聚合)
 * 返回: {ok, price, ts, candles:[[ts_s,o,h,l,c],...x20]}
 * 函数清单: jfetch() — HTTP GET 取 JSON; decode() — Dukascopy 差分编码解码为标准K线
 * 返回码语义: ok=1 实时成功 / ok=2 远端失败回退DB / ok=0 全部失败
 * 被谁调用: nqall.php 前端 JS 每15秒轮询 (历史遗留: NQ 指数实时接口)
 */
header('Content-Type: application/json; charset=utf-8');  // 响应头: JSON + UTF-8
header('Cache-Control: no-store');                        // 响应头: 禁缓存(实时行情)
ini_set('default_socket_timeout', 15);                    // socket 默认超时设 15 秒(远端拉取保护)

$CODE = 'USATECH.IDX-USD';                                // Dukascopy 纳指100 指数代码(BID 价)
$FROM = (time() - 1800) * 1000;                           // 起始毫秒时间戳(当前往前30分钟)
$URL  = "https://jetta.dukascopy.com/v1/candles/minute/{$CODE}/BID?from={$FROM}"; // Dukascopy jetta 分钟K线接口 URL

function jfetch($url) {                                   // HTTP GET 拉取并解码 JSON
    $ctx = stream_context_create(['http' => ['timeout' => 12, 'header' => "User-Agent: Mozilla/5.0\r\n"]]); // 流上下文: 12秒超时 + 浏览器UA
    $d = @file_get_contents($url, false, $ctx);           // 发起请求(@抑制失败警告)
    if ($d === false) return null;                        // 网络失败返回 null
    return json_decode($d, true);                         // 成功则解码为关联数组
}

function decode($d) {                                     // 解码 Dukascopy 差分编码数据
    if (!$d || empty($d['times']) || !isset($d['multiplier'])) return []; // 结构不完整: 返回空
    $mult = $d['multiplier']; $shift = $d['shift']; $ts = $d['timestamp']; // 价格乘数 / 时间步长 / 起始时间戳
    $ou = round($d['open'] / $mult); $hu = round($d['high'] / $mult);      // 开高价: 基准值除以乘数取整(差分基准)
    $lu = round($d['low'] / $mult);  $cu = round($d['close'] / $mult);     // 低收盘: 同上
    $out = [];                                            // 输出K线数组
    $n = count($d['times']);                              // K线根数
    for ($i = 0; $i < $n; $i++) {                         // 逐根还原(差分累加)
        $ts += $d['times'][$i] * $shift;                  // 时间: 基准累加 步长×偏移
        $ou += $d['opens'][$i]; $hu += $d['highs'][$i]; $lu += $d['lows'][$i]; $cu += $d['closes'][$i]; // 价格: 各基准累加差分偏移
        if ($ou > 0 && $cu > 0) $out[] = [$ts, $ou * $mult, $hu * $mult, $lu * $mult, $cu * $mult, (float)$d['volumes'][$i]]; // 有效行(价格>0): 还原真实值 ×乘数, 附成交量
    }
    return $out;                                          // 返回 [[ts_ms,o,h,l,c,vol]...]
}

$resp = ['ok' => 0, 'price' => null, 'ts' => null, 'candles' => []]; // 响应骨架(默认失败态)
$data = jfetch($URL);                                     // 拉取远端数据
if ($data === null) { echo json_encode($resp); exit; }    // 远端彻底失败: 返回失败态并终止
$rows = decode($data);                                    // 解码为标准K线行

$m = new mysqli('127.0.0.1', 'root', '', 'trading');      // 直连 MySQL(密码为空属正常)
$m->set_charset('utf8mb4');                               // 设置 utf8mb4 字符集

if ($rows) {                                              // —— 实时成功路径 ——
    $st = $m->prepare("REPLACE INTO kline_nq_1m (candle_time,o,h,l,c,vol) VALUES (?,?,?,?,?,?)"); // 预备语句: REPLACE 幂等入库
    foreach ($rows as $r) {                               // 逐根写入
        $tms = $r[0]; $o = $r[1]; $h = $r[2]; $l = $r[3]; $c = $r[4]; $v = $r[5]; // 拆行到绑定变量
        $st->bind_param('iddddi', $tms, $o, $h, $l, $c, $v); // 绑定类型: i=int时间, d=价格, i=量
        $st->execute();                                   // 执行写入(同时间戳覆盖)
    }
    $st->close();                                         // 关闭语句

    $last = end($rows);                                   // 取最新一根
    $resp['price'] = round($last[4], 2);                  // 最新收盘价(保留2位)
    $resp['ts'] = (int)($last[0] / 1000);                 // 最新时间戳转秒
    $tail = array_slice($rows, -20);                      // 截取最近20根
    foreach ($tail as $r) $resp['candles'][] = [(int)($r[0] / 1000), round($r[1], 2), round($r[2], 2), round($r[3], 2), round($r[4], 2)]; // 每根: 时间转秒 + OHLC 保留2位
    $resp['ok'] = 1;                                      // 标记实时成功
} else {
    // 实时接口失败: 回退DB最新
    $q = $m->query("SELECT candle_time,o,h,l,c FROM kline_nq_1m ORDER BY candle_time DESC LIMIT 20"); // 从本地库倒序取最近20根
    if ($q && $q->num_rows) {                             // 库里有数据
        $rr = []; while ($x = $q->fetch_row()) $rr[] = $x; // 逐行取出
        $rr = array_reverse($rr);                         // 反转回升序
        $resp['price'] = round((float)$rr[count($rr)-1][4], 2); // 最新一根收盘价
        $resp['ts'] = (int)($rr[count($rr)-1][0] / 1000);       // 最新一根时间戳转秒
        foreach ($rr as $x) $resp['candles'][] = [(int)($x[0] / 1000), round((float)$x[1], 2), round((float)$x[2], 2), round((float)$x[3], 2), round((float)$x[4], 2)]; // 逐根组装输出
        $resp['ok'] = 2;                                  // 标记回退DB成功
    }
}
$m->close();                                              // 关闭数据库连接
echo json_encode($resp);                                  // 输出最终 JSON 响应
