<?php
/**
 * kline_api.php — ETH K线窗口化数据API (与 nq_api.php 同逻辑, 数据源换成 kline_eth_{tf} 表)
 * 职责: 向前端图表面板提供 ETH K线窗口化分页数据(原生周期直取 / 跨周期服务端聚合)
 * 参数:
 *   tf      1m/3m/5m/15m/1h (必填)
 *   bucket  聚合分钟数, 默认=周期本身 (缩放很小时前端自动加大, 服务端聚合)
 *   before  ms, 取该时刻之前的N根 (DESC端点, 向左翻页/初始加载)
 *   after   ms, 取该时刻之后的N根 (向右翻页)
 *   limit   根数, 默认500, 上限2000
 * 返回: {ok, tf, bucket, iv, rows:[[t,o,h,l,c,dif,dea,macd,e12,e26,score,vol],...升序], last:最新收盘秒, now:服务器ms}
 * 指标(MACD/score)已随快照存入同表; e12/e26 无(快照未存, 填0, 仅实时形成中K线续算用, 快照无实时)
 * 函数清单: outRows() — 按窗口参数取行(原生直查或聚合)
 * 被谁调用: klineall.php 挂载的 nqall_data.js(经 kline_cfg.js 的 NQ_API_URL 指向本文件)
 */
header('Content-Type: application/json; charset=utf-8');  // 响应头: JSON + UTF-8
$IV = ['1m' => 60, '3m' => 180, '5m' => 300, '15m' => 900, '1h' => 3600]; // 支持的周期名→秒数映射
$TF = $_GET['tf'] ?? '1m';                                 // 取周期参数(默认1m)
if (!isset($IV[$TF])) { echo json_encode(['err' => 'bad tf']); exit; } // 非法周期: 输出错误并终止
$iv = $IV[$TF];                                            // 该周期对应秒数
$bmin = isset($_GET['bucket']) ? max(1, (int)$_GET['bucket']) : (int)($iv / 60); // 聚合分钟数(至少1, 默认=周期自身分钟数)
$bm = $bmin * 60 * 1000;                                   // 聚合桶大小(毫秒)
$limit = min(2000, max(10, (int)($_GET['limit'] ?? 500))); // 返回根数(夹在 10~2000, 默认500)
$before = isset($_GET['before']) ? (int)$_GET['before'] : null; // 左翻页锚点(取该时刻之前的N根)
$after = isset($_GET['after']) ? (int)$_GET['after'] : null;    // 右翻页锚点(取该时刻之后的N根)

$m = new mysqli('127.0.0.1', 'root', '', 'trading');       // 直连 MySQL(历史遗留写法, 密码为空属正常)
$r = $m->query("SELECT MAX(candle_time) FROM kline_eth_{$TF}"); // 查该周期表中最新K线时间戳
$last = (int)$r->fetch_row()[0];                           // 最新时间戳(毫秒), 供前端增量拉取定位

function outRows($m, $TF, $iv, $bm, $limit, $before, $after) { // 核心: 按窗口取数据行
    $native = ($bm == $iv * 1000);                   // 判断是否原生周期直取(聚合桶=周期本身时无需聚合)
    $desc = ($after === null);           // 无after=向左/初始, DESC取尾部
    if ($native) {                                   // —— 原生直取路径 ——
        $cond = '';                                  // 附加 WHERE 条件串
        if ($before !== null) $cond .= " AND candle_time<$before"; // 有before锚点: 只取更早的
        if ($after !== null)  $cond .= " AND candle_time>$after";  // 有after锚点: 只取更新的
        $ord = $desc ? 'DESC' : 'ASC';               // 排序方向: 初始/左翻页倒序取尾, 右翻页升序
        $q = "SELECT candle_time,o,h,l,c,dif,dea,macd,score,vol
              FROM kline_eth_{$TF} WHERE c>0{$cond} ORDER BY candle_time {$ord} LIMIT {$limit}"; // 直查K线表(过滤无效收盘, 预算好的指标随行取出)
        $r = $m->query($q);                          // 执行查询
        $rows = [];                                  // 结果容器
        while ($x = $r->fetch_row()) {               // 逐行取数值数组
            $rows[] = [(int)($x[0] / 1000), (float)$x[1], (float)$x[2], (float)$x[3], (float)$x[4],      // 时间转秒 + OHLC 转浮点
                       (float)$x[5], (float)$x[6], (float)$x[7], 0, 0,                                    // dif/dea/macd + e12/e26(快照未存, 固定填0)
                       $x[8] !== null ? (float)$x[8] : null, (float)$x[9]];                               // score(NULL保留为null即无信号) + 成交量
        }
        if ($desc) $rows = array_reverse($rows);     // 倒序取的尾部行反转回升序
        return $rows;                                // 返回原生行
    }
    // 聚合桶: 从本表拉窗口内原生K线, PHP聚合 (dif/dea/macd=桶尾, score=桶内最大)
    $cond = '';                                      // 附加 WHERE 条件串
    if ($before !== null) $cond .= " AND candle_time<$before"; // before 锚点过滤
    if ($after !== null)  $cond .= " AND candle_time>$after";  // after 锚点过滤
    $srcLimit = min(300000, $limit * (int)($bm / ($iv * 1000)) + 400); // 源行数上限 = 目标根数×每桶含源根数 + 400缓冲(硬顶30万防失控)
    $ord = $desc ? 'DESC' : 'ASC';                   // 源数据排序方向同上
    $q = "SELECT candle_time,o,h,l,c,vol,dif,dea,macd,score FROM kline_eth_{$TF} WHERE c>0{$cond} ORDER BY candle_time {$ord} LIMIT {$srcLimit}"; // 拉窗口内全部源K线
    $r = $m->query($q);                              // 执行查询
    $src = [];                                       // 源行容器
    while ($x = $r->fetch_row()) $src[] = [(int)$x[0], (float)$x[1], (float)$x[2], (float)$x[3], (float)$x[4], (float)$x[5],   // 时间+OHLCV
                                           $x[6], $x[7], $x[8], $x[9]];                                                         // 指标列(dif/dea/macd/score, 可能NULL)
    if ($desc) $src = array_reverse($src);           // 倒序源行反转回升序
    $buck = [];                                      // 聚合桶: 桶起始ms → 聚合数据
    foreach ($src as $b) {                           // 遍历源行逐桶归并
        $bk = (int)floor($b[0] / $bm) * $bm;         // 计算该行所属桶的起始毫秒
        if (!isset($buck[$bk])) $buck[$bk] = ['o' => $b[1], 'h' => $b[2], 'l' => $b[3], 'c' => $b[4], 'tl' => $b[0], 'v' => $b[5],  // 新桶: 直接取该行 OHLCV, tl=桶内最晚时间
                                              'd' => $b[6], 'e' => $b[7], 'mm' => $b[8], 's' => $b[9] !== null ? (float)$b[9] : null]; // 指标先取首行值(后续被桶尾覆盖)
        else {
            $buck[$bk]['v'] += $b[5];                // 已有桶: 成交量累加
            if ($b[2] > $buck[$bk]['h']) $buck[$bk]['h'] = $b[2]; // 高价取桶内最大
            if ($b[3] < $buck[$bk]['l']) $buck[$bk]['l'] = $b[3]; // 低价取桶内最小
            if ($b[0] > $buck[$bk]['tl']) { $buck[$bk]['tl'] = $b[0]; $buck[$bk]['c'] = $b[4]; $buck[$bk]['d'] = $b[6]; $buck[$bk]['e'] = $b[7]; $buck[$bk]['mm'] = $b[8]; } // 更晚的行: 覆盖收盘价与桶尾指标(dif/dea/macd取桶尾)
            if ($b[9] !== null) { $sv = (float)$b[9]; if ($buck[$bk]['s'] === null || $sv > $buck[$bk]['s']) $buck[$bk]['s'] = $sv; } // score 取桶内最大(非NULL才参与)
        }
    }
    $rows = [];                                      // 输出行容器
    foreach ($buck as $bk => $v) {                   // 遍历桶生成输出行
        $rows[] = [$bk / 1000, $v['o'], $v['h'], $v['l'], $v['c'],                       // 桶起始时间转秒 + 聚合OHLC
                   $v['d'] !== null ? (float)$v['d'] : 0, $v['e'] !== null ? (float)$v['e'] : 0, $v['mm'] !== null ? (float)$v['mm'] : 0, // 桶尾指标(NULL补0)
                   0, 0, $v['s'], $v['v']];                                              // e12/e26固定0 + 桶内最大score + 聚合成交量
    }
    return $rows;                                    // 返回聚合行(升序)
}

$rows = outRows($m, $TF, $iv, $bm, $limit, $before, $after); // 执行取数
echo json_encode(['ok' => true, 'tf' => $TF, 'bucket' => (int)($bm / 60000), 'iv' => $iv,  // 输出响应头字段: 成功标志/周期/聚合分钟/周期秒
                  'rows' => $rows, 'last' => (int)($last / 1000), 'now' => (int)round(microtime(true) * 1000)]); // 数据行 + 最新收盘秒 + 服务器当前毫秒
