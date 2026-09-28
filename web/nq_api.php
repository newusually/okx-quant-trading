<?php
/**
 * nq_api.php — 纳指K线窗口化数据API (分页按需加载)
 * 参数:
 *   tf      1m/3m/5m/15m/1h (必填)
 *   bucket  聚合分钟数, 默认=周期本身 (缩放很小时前端自动加大, 服务端聚合)
 *   before  ms, 取该时刻之前的N根 (DESC端点, 向左翻页/初始加载)
 *   after   ms, 取该时刻之后的N根 (向右翻页)
 *   limit   根数, 默认500, 上限2000
 * 返回: {ok, tf, bucket, iv, rows:[[t,o,h,l,c,dif,dea,macd,e12,e26,score],...升序], last:最新收盘ms, now:服务器ms}
 * 指标来自 nq_ind_{tf} (后台 nq_bg.py 预计算), score 非NULL即🚀信号
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
$r = $m->query("SELECT MAX(candle_time) FROM kline_nq_{$TF}");
$last = (int)$r->fetch_row()[0];

function outRows($m, $TF, $iv, $bm, $limit, $before, $after, $last) {
    $native = ($bm == $iv * 1000);
    // 决定方向与锚点
    $desc = ($after === null);           // 无after=向左/初始, DESC取尾部
    if ($native) {
        $cond = '';
        if ($before !== null) $cond .= " AND k.candle_time<$before";
        if ($after !== null)  $cond .= " AND k.candle_time>$after";
        $ord = $desc ? 'DESC' : 'ASC';
        $q = "SELECT k.candle_time,k.o,k.h,k.l,k.c,i.dif,i.dea,i.macd,i.e12,i.e26,i.score,k.vol
              FROM kline_nq_{$TF} k LEFT JOIN nq_ind_{$TF} i ON i.candle_time=k.candle_time
              WHERE k.c>0{$cond} ORDER BY k.candle_time {$ord} LIMIT {$limit}";
        $r = $m->query($q);
        $rows = [];
        while ($x = $r->fetch_row()) {
            $rows[] = [(int)($x[0] / 1000), (float)$x[1], (float)$x[2], (float)$x[3], (float)$x[4],
                       $x[5] !== null ? (float)$x[5] : 0, $x[6] !== null ? (float)$x[6] : 0,
                       $x[7] !== null ? (float)$x[7] : 0, $x[8] !== null ? (float)$x[8] : 0,
                       $x[9] !== null ? (float)$x[9] : 0, $x[10] !== null ? (float)$x[10] : null, (float)$x[11]];
        }
        if ($desc) $rows = array_reverse($rows);
        return $rows;
    }
    // 聚合桶: 从源表拉窗口内1m, PHP聚合 (score=桶内最大, dif/dea/e12/e26=桶尾)
    $cond = '';
    if ($before !== null) $cond .= " AND candle_time<$before";
    if ($after !== null)  $cond .= " AND candle_time>$after";
    $srcLimit = min(300000, $limit * (int)($bm / ($iv * 1000)) + 400);
    $ord = $desc ? 'DESC' : 'ASC';
    $q = "SELECT candle_time,o,h,l,c,vol FROM kline_nq_{$TF} WHERE c>0{$cond} ORDER BY candle_time {$ord} LIMIT {$srcLimit}";
    $r = $m->query($q);
    $src = [];
    while ($x = $r->fetch_row()) $src[] = [(int)$x[0], (float)$x[1], (float)$x[2], (float)$x[3], (float)$x[4], (float)$x[5]];
    if ($desc) $src = array_reverse($src);
    // 指标按桶尾取
    $indq = "SELECT candle_time,dif,dea,macd,e12,e26,score FROM nq_ind_{$TF} WHERE candle_time>=? AND candle_time<?";
    $st = $m->prepare($indq);
    if ($src) {
        $lo = $src[0][0]; $hi = end($src)[0] + $iv * 1000;
        $p = $lo; $hi2 = $hi;
        $st->bind_param('ii', $p, $hi2); $st->execute();
        $ir = $st->get_result();
        $ind = [];
        while ($x = $ir->fetch_row()) $ind[(int)$x[0]] = $x;
        $st->close();
    } else $ind = [];
    $buck = [];
    foreach ($src as $b) {
        $bk = (int)floor($b[0] / $bm) * $bm;
        if (!isset($buck[$bk])) $buck[$bk] = ['o' => $b[1], 'h' => $b[2], 'l' => $b[3], 'c' => $b[4], 'tl' => $b[0], 'v' => $b[5], 's' => null];
        else {
            $buck[$bk]['v'] += $b[5];
            if ($b[2] > $buck[$bk]['h']) $buck[$bk]['h'] = $b[2];
            if ($b[3] < $buck[$bk]['l']) $buck[$bk]['l'] = $b[3];
            if ($b[0] > $buck[$bk]['tl']) { $buck[$bk]['tl'] = $b[0]; $buck[$bk]['c'] = $b[4]; }
        }
    }
    // 每桶末根的指标 + 桶内最大score
    $needEnd = array_keys($buck);
    $rows = [];
    foreach ($buck as $bk => $v) {
        $eT = null;
        for ($t = $v['tl']; $t >= $bk && $eT === null; $t -= $iv * 1000) if (isset($ind[$t])) $eT = $ind[$t];
        // 桶内最大score: 扫描ind
        $smax = null;
        for ($t = $bk; $t <= $v['tl']; $t += $iv * 1000) {
            if (isset($ind[$t]) && $ind[$t][6] !== null) { $sv = (float)$ind[$t][6]; if ($smax === null || $sv > $smax) $smax = $sv; }
        }
        $rows[] = [$bk / 1000, $v['o'], $v['h'], $v['l'], $v['c'],
                   $eT ? (float)$eT[1] : 0, $eT ? (float)$eT[2] : 0, $eT ? (float)$eT[3] : 0,
                   $eT ? (float)$eT[4] : 0, $eT ? (float)$eT[5] : 0, $smax, $v['v']];
    }
    return $rows;
}

$rows = outRows($m, $TF, $iv, $bm, $limit, $before, $after, $last);
echo json_encode(['ok' => true, 'tf' => $TF, 'bucket' => (int)($bm / 60000), 'iv' => $iv,
                  'rows' => $rows, 'last' => (int)($last / 1000), 'now' => (int)round(microtime(true) * 1000)]);
