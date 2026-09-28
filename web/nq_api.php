<?php
/**
 * nq_api.php — 纳指K线窗口化数据API (分页按需加载)
 * 职责: 向前端图表面板提供纳指 K线窗口化分页数据(K线表 LEFT JOIN 指标表 / 跨周期服务端聚合)
 * 参数:
 *   tf      1m/3m/5m/15m/1h (必填)
 *   bucket  聚合分钟数, 默认=周期本身 (缩放很小时前端自动加大, 服务端聚合)
 *   before  ms, 取该时刻之前的N根 (DESC端点, 向左翻页/初始加载)
 *   after   ms, 取该时刻之后的N根 (向右翻页)
 *   limit   根数, 默认500, 上限2000
 * 返回: {ok, tf, bucket, iv, rows:[[t,o,h,l,c,dif,dea,macd,e12,e26,score],...升序], last:最新收盘ms, now:服务器ms}
 * 指标来自 nq_ind_{tf} (后台 nq_bg.py 预计算), score 非NULL即🚀信号
 * 函数清单: outRows() — 按窗口参数取行(原生直查或聚合)
 * 被谁调用: nqall.php 挂载的 nqall_data.js (历史遗留: NQ 指数数据接口)
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
$r = $m->query("SELECT MAX(candle_time) FROM kline_nq_{$TF}"); // 查该周期表中最新K线时间戳
$last = (int)$r->fetch_row()[0];                           // 最新时间戳(毫秒), 供前端增量拉取定位

function outRows($m, $TF, $iv, $bm, $limit, $before, $after, $last) { // 核心: 按窗口取数据行
    $native = ($bm == $iv * 1000);                   // 判断是否原生周期直取(聚合桶=周期本身时无需聚合)
    // 决定方向与锚点
    $desc = ($after === null);           // 无after=向左/初始, DESC取尾部
    if ($native) {                                   // —— 原生直取路径 ——
        $cond = '';                                  // 附加 WHERE 条件串
        if ($before !== null) $cond .= " AND k.candle_time<$before"; // before 锚点过滤(带表别名)
        if ($after !== null)  $cond .= " AND k.candle_time>$after";  // after 锚点过滤
        $ord = $desc ? 'DESC' : 'ASC';               // 排序方向: 初始/左翻页倒序取尾, 右翻页升序
        $q = "SELECT k.candle_time,k.o,k.h,k.l,k.c,i.dif,i.dea,i.macd,i.e12,i.e26,i.score,k.vol
              FROM kline_nq_{$TF} k LEFT JOIN nq_ind_{$TF} i ON i.candle_time=k.candle_time
              WHERE k.c>0{$cond} ORDER BY k.candle_time {$ord} LIMIT {$limit}"; // K线表 LEFT JOIN 指标表(同时间戳关联, 指标缺失不丢K线)
        $r = $m->query($q);                          // 执行查询
        $rows = [];                                  // 结果容器
        while ($x = $r->fetch_row()) {               // 逐行取数值数组
            $rows[] = [(int)($x[0] / 1000), (float)$x[1], (float)$x[2], (float)$x[3], (float)$x[4],   // 时间转秒 + OHLC 转浮点
                       $x[5] !== null ? (float)$x[5] : 0, $x[6] !== null ? (float)$x[6] : 0,           // dif/dea(NULL补0)
                       $x[7] !== null ? (float)$x[7] : 0, $x[8] !== null ? (float)$x[8] : 0,           // macd/e12(NULL补0)
                       $x[9] !== null ? (float)$x[9] : 0, $x[10] !== null ? (float)$x[10] : null, (float)$x[11]]; // e26(NULL补0) + score(NULL保留=无信号) + 成交量
        }
        if ($desc) $rows = array_reverse($rows);     // 倒序取的尾部行反转回升序
        return $rows;                                // 返回原生行
    }
    // 聚合桶: 从源表拉窗口内1m, PHP聚合 (score=桶内最大, dif/dea/e12/e26=桶尾)
    $cond = '';                                      // 附加 WHERE 条件串
    if ($before !== null) $cond .= " AND candle_time<$before"; // before 锚点过滤
    if ($after !== null)  $cond .= " AND candle_time>$after";  // after 锚点过滤
    $srcLimit = min(300000, $limit * (int)($bm / ($iv * 1000)) + 400); // 源行数上限 = 目标根数×每桶含源根数 + 400缓冲(硬顶30万)
    $ord = $desc ? 'DESC' : 'ASC';                   // 源数据排序方向同上
    $q = "SELECT candle_time,o,h,l,c,vol FROM kline_nq_{$TF} WHERE c>0{$cond} ORDER BY candle_time {$ord} LIMIT {$srcLimit}"; // 拉窗口内全部源K线(仅价格量, 指标稍后单独取)
    $r = $m->query($q);                              // 执行查询
    $src = [];                                       // 源行容器
    while ($x = $r->fetch_row()) $src[] = [(int)$x[0], (float)$x[1], (float)$x[2], (float)$x[3], (float)$x[4], (float)$x[5]]; // 时间+OHLCV 数值化
    if ($desc) $src = array_reverse($src);           // 倒序源行反转回升序
    // 指标按桶尾取
    $indq = "SELECT candle_time,dif,dea,macd,e12,e26,score FROM nq_ind_{$TF} WHERE candle_time>=? AND candle_time<?"; // 指标表预备语句(取窗口内全部)
    $st = $m->prepare($indq);                        // 预备语句(防注入)
    if ($src) {                                      // 有源数据才查指标
        $lo = $src[0][0]; $hi = end($src)[0] + $iv * 1000; // 指标窗口: 首根时间 ~ 末根时间+一个周期
        $p = $lo; $hi2 = $hi;                        // 绑定用临时变量(bind_param 需引用)
        $st->bind_param('ii', $p, $hi2); $st->execute(); // 绑定整数区间并执行
        $ir = $st->get_result();                     // 取结果集
        $ind = [];                                   // 指标映射: 时间ms → 指标行
        while ($x = $ir->fetch_row()) $ind[(int)$x[0]] = $x; // 以时间为键建立哈希(供桶尾/桶内最大查询)
        $st->close();                                // 关闭语句释放资源
    } else $ind = [];                                // 无源数据: 空指标映射
    $buck = [];                                      // 聚合桶: 桶起始ms → 聚合数据
    foreach ($src as $b) {                           // 遍历源行逐桶归并
        $bk = (int)floor($b[0] / $bm) * $bm;         // 计算该行所属桶的起始毫秒
        if (!isset($buck[$bk])) $buck[$bk] = ['o' => $b[1], 'h' => $b[2], 'l' => $b[3], 'c' => $b[4], 'tl' => $b[0], 'v' => $b[5], 's' => null]; // 新桶: 直接取该行 OHLCV, tl=桶内最晚时间, score初始null
        else {
            $buck[$bk]['v'] += $b[5];                // 已有桶: 成交量累加
            if ($b[2] > $buck[$bk]['h']) $buck[$bk]['h'] = $b[2]; // 高价取桶内最大
            if ($b[3] < $buck[$bk]['l']) $buck[$bk]['l'] = $b[3]; // 低价取桶内最小
            if ($b[0] > $buck[$bk]['tl']) { $buck[$bk]['tl'] = $b[0]; $buck[$bk]['c'] = $b[4]; } // 更晚的行: 覆盖收盘价与桶尾时间
        }
    }
    // 每桶末根的指标 + 桶内最大score
    $needEnd = array_keys($buck);                    // 全部桶起始时间(用于逐桶回查)
    $rows = [];                                      // 输出行容器
    foreach ($buck as $bk => $v) {                   // 遍历桶
        $eT = null;                                  // 桶尾指标行(未找到为null)
        for ($t = $v['tl']; $t >= $bk && $eT === null; $t -= $iv * 1000) if (isset($ind[$t])) $eT = $ind[$t]; // 从桶尾向桶首倒查第一个有指标的根作为桶尾指标
        // 桶内最大score: 扫描ind
        $smax = null;                                // 桶内最大score(初始null)
        for ($t = $bk; $t <= $v['tl']; $t += $iv * 1000) {   // 从桶首到桶尾逐根扫描
            if (isset($ind[$t]) && $ind[$t][6] !== null) { $sv = (float)$ind[$t][6]; if ($smax === null || $sv > $smax) $smax = $sv; } // score非NULL则参与取最大
        }
        $rows[] = [$bk / 1000, $v['o'], $v['h'], $v['l'], $v['c'],   // 桶起始时间转秒 + 聚合OHLC
                   $eT ? (float)$eT[1] : 0, $eT ? (float)$eT[2] : 0, $eT ? (float)$eT[3] : 0,   // 桶尾 dif/dea/macd(无则0)
                   $eT ? (float)$eT[4] : 0, $eT ? (float)$eT[5] : 0, $smax, $v['v']];            // 桶尾 e12/e26(无则0) + 桶内最大score + 聚合成交量
    }
    return $rows;                                    // 返回聚合行(升序)
}

$rows = outRows($m, $TF, $iv, $bm, $limit, $before, $after, $last); // 执行取数
echo json_encode(['ok' => true, 'tf' => $TF, 'bucket' => (int)($bm / 60000), 'iv' => $iv,  // 输出响应头字段: 成功标志/周期/聚合分钟/周期秒
                  'rows' => $rows, 'last' => (int)($last / 1000), 'now' => (int)round(microtime(true) * 1000)]); // 数据行 + 最新收盘秒 + 服务器当前毫秒
