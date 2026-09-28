<?php
/**
 * kline_api.php — ETH K线窗口化数据API (与 nq_api.php 同逻辑, 数据源换成 kline_eth_{tf} 表)
 * 参数:
 *   tf      1m/3m/5m/15m/1h (必填)
 *   bucket  聚合分钟数, 默认=周期本身 (缩放很小时前端自动加大, 服务端聚合)
 *   before  ms, 取该时刻之前的N根 (DESC端点, 向左翻页/初始加载)
 *   after   ms, 取该时刻之后的N根 (向右翻页)
 *   limit   根数, 默认500, 上限2000
 * 返回: {ok, tf, bucket, iv, rows:[[t,o,h,l,c,dif,dea,macd,e12,e26,score,vol],...升序], last:最新收盘秒, now:服务器ms}
 * 指标(MACD/score)已随快照存入同表; e12/e26 无(快照未存, 填0, 仅实时形成中K线续算用, 快照无实时)
 */
header('Content-Type: application/json; charset=utf-8');
$IV = ['1m' => 60, '3m' => 180, '5m' => 300, '15m' => 900, '1h' => 3600];
$TF = $_GET['tf'] ?? '1m';
if (!isset($IV[$TF])) { echo json_encode(['err' => 'bad tf']); exit; }
$iv = $IV[$TF];
$bmin = isset($_GET['bucket']) ? max(1, (int)$_GET['bucket']) : (int)($iv / 60);
$bm = $bmin * 60 * 1000;
$limit = min(2000, max(10, (int)($_GET['limit'] ?? 500)));
$before = isset($_GET['before']) ? (int)$_GET['before'] : null;
$after = isset($_GET['after']) ? (int)$_GET['after'] : null;

$m = new mysqli('127.0.0.1', 'root', '', 'trading');
$r = $m->query("SELECT MAX(candle_time) FROM kline_eth_{$TF}");
$last = (int)$r->fetch_row()[0];

function outRows($m, $TF, $iv, $bm, $limit, $before, $after) {
    $native = ($bm == $iv * 1000);
    $desc = ($after === null);           // 无after=向左/初始, DESC取尾部
    if ($native) {
        $cond = '';
        if ($before !== null) $cond .= " AND candle_time<$before";
        if ($after !== null)  $cond .= " AND candle_time>$after";
        $ord = $desc ? 'DESC' : 'ASC';
        $q = "SELECT candle_time,o,h,l,c,dif,dea,macd,score,vol
              FROM kline_eth_{$TF} WHERE c>0{$cond} ORDER BY candle_time {$ord} LIMIT {$limit}";
        $r = $m->query($q);
        $rows = [];
        while ($x = $r->fetch_row()) {
            $rows[] = [(int)($x[0] / 1000), (float)$x[1], (float)$x[2], (float)$x[3], (float)$x[4],
                       (float)$x[5], (float)$x[6], (float)$x[7], 0, 0,
                       $x[8] !== null ? (float)$x[8] : null, (float)$x[9]];
        }
        if ($desc) $rows = array_reverse($rows);
        return $rows;
    }
    // 聚合桶: 从本表拉窗口内原生K线, PHP聚合 (dif/dea/macd=桶尾, score=桶内最大)
    $cond = '';
    if ($before !== null) $cond .= " AND candle_time<$before";
    if ($after !== null)  $cond .= " AND candle_time>$after";
    $srcLimit = min(300000, $limit * (int)($bm / ($iv * 1000)) + 400);
    $ord = $desc ? 'DESC' : 'ASC';
    $q = "SELECT candle_time,o,h,l,c,vol,dif,dea,macd,score FROM kline_eth_{$TF} WHERE c>0{$cond} ORDER BY candle_time {$ord} LIMIT {$srcLimit}";
    $r = $m->query($q);
    $src = [];
    while ($x = $r->fetch_row()) $src[] = [(int)$x[0], (float)$x[1], (float)$x[2], (float)$x[3], (float)$x[4], (float)$x[5],
                                           $x[6], $x[7], $x[8], $x[9]];
    if ($desc) $src = array_reverse($src);
    $buck = [];
    foreach ($src as $b) {
        $bk = (int)floor($b[0] / $bm) * $bm;
        if (!isset($buck[$bk])) $buck[$bk] = ['o' => $b[1], 'h' => $b[2], 'l' => $b[3], 'c' => $b[4], 'tl' => $b[0], 'v' => $b[5],
                                              'd' => $b[6], 'e' => $b[7], 'mm' => $b[8], 's' => $b[9] !== null ? (float)$b[9] : null];
        else {
            $buck[$bk]['v'] += $b[5];
            if ($b[2] > $buck[$bk]['h']) $buck[$bk]['h'] = $b[2];
            if ($b[3] < $buck[$bk]['l']) $buck[$bk]['l'] = $b[3];
            if ($b[0] > $buck[$bk]['tl']) { $buck[$bk]['tl'] = $b[0]; $buck[$bk]['c'] = $b[4]; $buck[$bk]['d'] = $b[6]; $buck[$bk]['e'] = $b[7]; $buck[$bk]['mm'] = $b[8]; }
            if ($b[9] !== null) { $sv = (float)$b[9]; if ($buck[$bk]['s'] === null || $sv > $buck[$bk]['s']) $buck[$bk]['s'] = $sv; }
        }
    }
    $rows = [];
    foreach ($buck as $bk => $v) {
        $rows[] = [$bk / 1000, $v['o'], $v['h'], $v['l'], $v['c'],
                   $v['d'] !== null ? (float)$v['d'] : 0, $v['e'] !== null ? (float)$v['e'] : 0, $v['mm'] !== null ? (float)$v['mm'] : 0,
                   0, 0, $v['s'], $v['v']];
    }
    return $rows;
}

$rows = outRows($m, $TF, $iv, $bm, $limit, $before, $after);
echo json_encode(['ok' => true, 'tf' => $TF, 'bucket' => (int)($bm / 60000), 'iv' => $iv,
                  'rows' => $rows, 'last' => (int)($last / 1000), 'now' => (int)round(microtime(true) * 1000)]);
