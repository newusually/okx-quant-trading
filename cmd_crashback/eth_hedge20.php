<?php
/**
 * eth_hedge20.php — ETH模式 保证金20U起步+3U/天 × 对冲方案对比(CLI)
 * 方案:
 *   A0 纯多头(现行): 六重共振开多 + fib618仅上行加仓≤5轮 + TP+2% + 爆仓兜底
 *   B1 双向镜像: 多头=六重共振; 空头=镜像六重共振(1h<MA20/MA10 + 4h<MA5 + taker卖≥50%), 两腿独立
 *   B2 动态对冲(加仓减仓): 持多期间收盘跌破均价×(1-trig) → 开等额空单对冲;
 *      空头fib618顺势(下行)加仓≤5轮; 空头止盈=再跌1%(ROI+100%)落袋→可再开; 回升+0.2%小亏平空;
 *      对冲期间两腿合并计算组合爆仓(浮亏>总保证金才爆)
 * 共同: 保证金=20U+3U×已过天数 封顶余额/6, 每轮加仓=开仓时保证金, 100x逐仓, TP价格+2%
 * 输出: web/eth_hedge20_data.json
 */
ini_set('memory_limit', '1024M');                 // 内存上限 1G
set_time_limit(0);                                // 取消执行时间限制(CLI 长跑)
$BAL0 = 500.0;                                    // 初始资金 500U
$LEV = 100; $TPR = 0.02; $MAXADD = 5;             // 杠杆100x/止盈+2%/最多加仓5轮
$MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 维持保证金率/maker费/taker费/8h资金费率
$LADDER_BASE = 20.0; $LADDER_STEP = 3.0;          // 保证金阶梯: 20U起步, 每天递增3U

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库
$DB = db();                                       // 建立全局连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行 SQL 返回全部行
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }  // 滑动均线 MA(累积和 O(n))
function fibsig($c, $w = 30) {                    // fib618 信号: 30根窗口内回踩 0.618 分位 ±3% 触发
    $n = count($c); $out = array_fill(0, $n, false);  // 输出初始化为全 false
    for ($i = $w - 1; $i < $n; $i++) {            // 从窗口满处开始扫描
        $lo = INF; $hi = -INF;                    // 窗口最低/最高
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }  // 求窗口高低点
        if ($hi <= $lo) continue;                 // 无波动跳过
        $fib = $hi - ($hi - $lo) * 0.618;         // 0.618 回调位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true;  // 收盘落入 fib±3% 区间即信号
    }
    return $out;                                  // 返回信号序列
}
function load_k($inst, $bar) {                    // 读取 K线表(o/h/l/c/vol 列)
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按 candle_time 升序
    $ts = []; $o = []; $h = []; $l = []; $c = []; $v = [];  // 时间戳与 OHLCV 数组
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; }  // 类型转换逐列装入
    return [$ts, $o, $h, $l, $c, $v];             // 返回六元组
}
function buy_ratio_series($o, $h, $l, $c) {       // taker买比代理: 实体在振幅中的位置映射到 0~1
    $n = count($c); $br = [];                     // 结果数组
    for ($i = 0; $i < $n; $i++) {                 // 逐根计算
        $rng = $h[$i] - $l[$i];                   // 振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;  // 实体方向比例(-1~1)
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;  // 限幅
        $br[$i] = 0.5 + $dir / 2;                 // 映射到 0~1
    }
    return $br;                                   // 返回买比序列
}

echo "== load ==\n"; flush();                     // 阶段提示: 加载数据
[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h');  // 读 ETH 1h K线
[$ts4h, , , , $c4h, ] = load_k('eth', '4h');      // 读 ETH 4h 收盘
$n = count($ts1h);                                // 1h 总根数

echo "== breadth ==\n"; flush();                  // 阶段提示: 计算全市场宽度
$up = []; $tot = [];                              // 每日站上MA20币数 / 每日样本币数
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");  // 读全市场日线收盘
$cur = null; $win = []; $s = 0.0;                 // 当前合约/20根滑窗/窗口和
foreach ($rr as $x) {                             // 逐行扫描日线
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; }  // 换币重置窗口
    $win[] = (float)$x[2]; $s += (float)$x[2];    // 收盘入窗并累加
    if (count($win) > 20) $s -= array_shift($win);  // 窗口超出20根移除最旧
    if (count($win) == 20) {                      // 窗口满20才可算 MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2];  // 时间戳/MA20/收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0);  // 站上 MA20 计数
        $tot[$t] = ($tot[$t] ?? 0) + 1;           // 样本计数
    }
}
$brSrc = [];                                      // 宽度源数据: 时间戳 => 占比
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k;  // 样本>10个币才算宽度
$sorted = array_keys($brSrc); sort($sorted);      // 时间戳升序供二分查找
function ffillBr($t) {                            // 前向填充: 二分找 ≤t 的最近宽度
    global $brSrc, $sorted;                       // 引用全局数据
    $lo = 0; $hi = count($sorted) - 1; $res = null;  // 二分上下界/结果
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }  // 标准二分
    return $res !== null ? $brSrc[$res] : null;   // 返回宽度或 null
}
echo "== features ==\n"; flush();                 // 阶段提示: 计算特征
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);       // 1h MA20/MA10
$ma5_4h = ma($c4h, 5);                            // 4h MA5
$tr4hUp = [];                                     // 4h收盘>MA5 布尔表: 4h时间戳 => 布尔
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hUp[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i];  // 逐根记录 4h>MA5 状态
$tr4hUpF = [];                                    // 前向填充后的状态: 1h时间戳 => 布尔
$p4 = 0; $cur4 = null; $n4 = count($ts4h);        // 4h游标/当前状态/4h根数
foreach ($ts1h as $tt) { while ($p4 < $n4 && $ts4h[$p4] <= $tt) { $cur4 = $tr4hUp[$ts4h[$p4]] ?? $cur4; $p4++; } $tr4hUpF[$tt] = $cur4; }  // 用已收盘4h状态对齐每根1h
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h); // 1h taker买比序列
$fib = fibsig($c1h);                              // 1h fib618 信号序列

// ===== 信号: 多=六重共振(5关), 空=镜像 =====
$entryL = []; $entryS = [];                       // 多头/空头入场信号集合: 时间戳 => true
for ($i = 30; $i < $n; $i++) {                    // 跳过前30根预热
    $t = $ts1h[$i];                               // 当前时间戳
    $b = ffillBr($t);                             // ① 全市场宽度
    if ($b === null || $ma20[$i] === null || $ma10[$i] === null || !isset($tr4hUpF[$t])) continue;  // 任一特征缺失跳过
    $brAvg = 0; $cnt = 0;                         // 近12根买比均值/计数
    for ($k = max(0, $i - 11); $k <= $i; $k++) { $brAvg += $br1h[$k]; $cnt++; }  // 累加最近12根买比
    $brAvg /= $cnt;                               // 求均值
    if ($b > 0.5 && $c1h[$i] > $ma20[$i] && $c1h[$i] > $ma10[$i] && $tr4hUpF[$t] && $brAvg >= 0.5) $entryL[$t] = true;  // 多头: 宽度>50%+1h>MA20/MA10+4h>MA5+买比均值≥50%
    if ($b > 0.5 && $c1h[$i] < $ma20[$i] && $c1h[$i] < $ma10[$i] && !$tr4hUpF[$t] && $brAvg < 0.5) $entryS[$t] = true;  // 空头: 全部条件取反(镜像)
}
echo "entryL=" . count($entryL) . " entryS=" . count($entryS) . "\n"; flush();  // 输出多/空信号数

// ===== 信号频率: 每天信号根数(1h口径) =====
$daySig = [];                                     // 每日多/空信号计数
foreach ($entryL as $t => $_) { $d = date('Y-m-d', (int)($t / 1000)); $daySig[$d]['L'] = ($daySig[$d]['L'] ?? 0) + 1; }  // 多头信号按日计数
foreach ($entryS as $t => $_) { $d = date('Y-m-d', (int)($t / 1000)); $daySig[$d]['S'] = ($daySig[$d]['S'] ?? 0) + 1; }  // 空头信号按日计数
ksort($daySig);                                   // 按日期升序
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;  // 数据总跨度(天)
$last30 = array_slice($daySig, -30, null, true);  // 最后30天的信号统计
$last30Rows = [];                                 // 最后30天行数据
foreach ($last30 as $d => $v) $last30Rows[] = ['d' => $d, 'L' => $v['L'] ?? 0, 'S' => $v['S'] ?? 0];  // 逐日转行
$l30sum = 0; $l30max = 0;                         // 近30天多头信号总和/单日最大
foreach ($last30Rows as $r) { $l30sum += $r['L']; if ($r['L'] > $l30max) $l30max = $r['L']; }  // 累计与求最大

// margin ladder
$startT = $ts1h[30];                              // 保证金阶梯起点时间戳
function marginAt($t, $bal) {                     // 计算时点保证金: 20U + 3U×天数, 封顶余额/6
    global $startT, $LADDER_BASE, $LADDER_STEP;   // 引用全局参数
    $days = (int)floor((($t - $startT) / 1000) / 86400);  // 已过整天数
    $m = min($LADDER_BASE + $LADDER_STEP * $days, $bal / 6.0);  // 阶梯保证金与上限取小
    return max(1.0, round($m, 2));                // 下限1U, 两位小数
}

// ===== A0/B2: 多头(可带动态对冲) =====
function simLong($o, $h, $l, $c, $ts, $fib, $entry, $hedgeCfg) {  // 多头模拟, hedgeCfg.trig>0 时启用动态对冲
    global $LEV, $TPR, $MAXADD, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H;  // 引用全局参数
    $liqDrop = 1.0 / $LEV - $MMR;                 // 简化爆仓距离
    $bal = 500.0;                                 // 账户余额从500U起
    $trades = []; $n = count($ts); $i = 30;       // 成交列表/根数/游标
    while ($i < $n - 1) {                         // 逐根扫描
        if (empty($entry[$ts[$i]])) { $i++; continue; }  // 非信号根跳过
        $M0 = marginAt($ts[$i], $bal);            // 本笔保证金(阶梯)
        $e = $o[$i + 1];                          // 下一根开盘入场
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;  // 名义仓位/均价/加仓计数
        $fee = $notional * $FEE_MAKER;            // 开仓手续费
        $liqPx = $avg * (1 - $liqDrop);           // 多头爆仓价
        $tgtPx = $avg * (1 + $TPR);               // 多头止盈价 +2%
        // 对冲空头腿
        $hgOn = false; $hgAvg = 0; $hgNot = 0; $hgAdds = 0; $hgM0 = 0; $hgFee = 0;  // 对冲腿状态: 开关/均价/名义/加仓数/保证金/手续费
        $hgTgt = 0; $hgExit = 0; $hgBars = 0; $hgOpens = 0;  // 空头止盈价/止损平空价/持仓根数/开空次数
        $trig = $hedgeCfg['trig'] ?? 0;           // 对冲触发阈值(跌破均价百分比)
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;  // 结局/出场价/游标/持仓根数
        while ($j < $n) {                         // 持仓期间逐根推进
            $barsHeld = $j - $i;                  // 已持仓小时数
            if ($hgOn) $hgBars++;                 // 对冲腿持仓根数
            $hitLiq = $l[$j] <= $liqPx;           // 多头触爆仓价
            $hitTp  = $h[$j] >= $tgtPx;           // 多头触止盈价
            // 对冲管理(先于爆仓判定: 对冲激活=组合账户)
            if ($trig > 0) {                      // 启用了动态对冲
                if ($hgOn) {                      // 已有对冲空单 → 管理空腿
                    // 空头止盈: 再跌1% → 落袋(可再开)
                    if ($l[$j] <= $hgTgt) {       // 最低触及空头止盈价
                        $fee += $hgNot * $FEE_TAKER;                   // 空头平仓手续费
                        $bal += ($hgAvg - $hgTgt) * $hgNot / $hgAvg;   // 空头盈利
                        $bal -= $hgNot * $FUND8H * 0;                   // 空头收funding, 记到平仓统一算   // funding 暂不计(系数为0)
                        $hgOn = false;                                 // 空腿关闭(可再次开)
                    } elseif ($h[$j] >= $hgExit) {                     // 回升+0.2% 小亏平空
                        $fee += $hgNot * $FEE_TAKER;  // 平空手续费
                        $bal += ($hgAvg - $hgExit) * $hgNot / $hgAvg;  // 空头小亏入账
                        $hgOn = false;                // 空腿关闭
                    } elseif (!empty($fib[$j]) && $c[$j] < $hgAvg && $hgAdds < 5 && $j + 1 < $n) {  // fib信号+价格在空腿均价下方(顺势下行)+未满5轮
                        $ap = $o[$j + 1];             // 加仓价 = 下一根开盘
                        $hgAvg = ($hgAvg * $hgNot + $ap * $hgM0 * $LEV) / ($hgNot + $hgM0 * $LEV);  // 加权新均价
                        $hgNot += $hgM0 * $LEV; $hgAdds++;  // 空腿名义增加, 加仓计数+1
                        $fee += $hgM0 * $LEV * $FEE_TAKER;  // 加仓手续费
                        $hgTgt = $hgAvg * (1 - 0.01);  // 止盈价更新为新均价再跌1%
                    }
                } elseif ($c[$j] < $avg * (1 - $trig) && $j + 1 < $n) {  // 未对冲: 收盘跌破均价×(1-trig) → 开空对冲
                    $hgM0 = marginAt($ts[$j], $bal);  // 空腿保证金(阶梯)
                    $hgAvg = $o[$j + 1]; $hgNot = $hgM0 * $LEV; $hgAdds = 0; $hgOpens++;  // 空腿均价/名义初始化, 开空次数+1
                    $fee += $hgNot * $FEE_TAKER;  // 开空手续费
                    $hgTgt = $hgAvg * (1 - 0.01); $hgExit = $hgAvg * (1 + 0.002);  // 空腿止盈=再跌1%/小亏止损=回升0.2%
                    $hgOn = true;                 // 对冲激活
                }
            }
            if ($hgOn) {                          // 对冲激活时按组合账户判定爆仓
                // 组合清算: 两腿浮亏 > 两腿总保证金×0.996 → 全爆
                $longU = ($c[$j] - $avg) * $notional / $avg;          // 多腿浮动盈亏
                $shortU = ($hgAvg - $c[$j]) * $hgNot / $hgAvg;        // 空腿浮动盈亏
                $mgTotal = $M0 * (1 + $adds) + $hgM0 * (1 + $hgAdds); // 两腿总保证金
                if ($longU + $shortU <= -$mgTotal * 0.996) { $outcome = 'LIQ'; $exitPx = $c[$j]; break; }  // 组合浮亏超总保证金 → 整体爆仓
            } elseif ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 无对冲时多头单独爆仓判定
            if ($hitTp) { $outcome = 'WIN'; $exitPx = $tgtPx; break; }  // 多头止盈
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }  // 持仓超7天超时平仓
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {  // 多头fib加仓: 仅上行(现价>均价)+未满5轮
                $ap = $o[$j + 1];                 // 加仓价 = 下一根开盘
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);  // 加权新均价
                $notional += $M0 * $LEV; $adds++; // 名义增加, 计数+1
                $fee += $M0 * $LEV * $FEE_TAKER;  // 加仓手续费
                $liqPx = $avg * (1 - $liqDrop);   // 重算爆仓价
                $tgtPx = $avg * (1 + $TPR);       // 重算止盈价
            }
            $j++;                                 // 推进下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }  // 数据耗尽按最后收盘平仓
        // 多头结算
        $gross = ($exitPx - $avg) * $notional / $avg;  // 多头毛盈亏
        $funding = $notional * $FUND8H * ($barsHeld / 8);  // 多头资金费(每8h计一次)
        $pnl = $gross - $fee - $funding;          // 多头净盈亏
        $mg = $M0 * (1 + $adds);                  // 多腿总保证金
        if ($pnl < -$mg) $pnl = -$mg;             // 亏损封顶到保证金
        // 对冲腿随多头结束一并平掉(若有)
        $hgPnl = 0;                               // 空腿盈亏
        if ($hgOn) {                              // 多头平仓时空腿仍开着 → 一并结算
            $hgGross = ($hgAvg - $exitPx) * $hgNot / $hgAvg;  // 空腿毛盈亏(按多头出场价平掉)
            $hgPnl = $hgGross + $hgNot * $FUND8H * ($hgBars / 8);   // 空头收funding   // 空腿净盈亏 = 毛利 + 资金费收入
            $bal += $hgPnl;                       // 空腿盈亏入账
        }
        $bal = round($bal + $pnl, 2);             // 多头盈亏入账
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,  // 记录成交明细
                     'hg' => $hgOpens, 'margin' => round($mg + ($hgOn ? $hgM0 * (1 + $hgAdds) : 0), 2),  // 含空腿开空次数与合并保证金
                     'pnl' => round($pnl + $hgPnl, 3), 'bal' => $bal];  // 两腿合并盈亏与账户余额
        if ($bal <= 0) break;                     // 爆仓即停止
        $i = $j + 1;                              // 跳到出场后一根
    }
    return [$trades, $bal];                       // 返回成交列表与期末余额
}

// ===== B1: 空头独立(镜像) =====
function simShort($o, $h, $l, $c, $ts, $fib, $entry) {  // 空头独立模拟: 结构与多头镜像
    global $LEV, $TPR, $MAXADD, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H;  // 引用全局参数
    $liqRise = 1.0 / $LEV - $MMR;                 // 空头爆仓距离(价格上冲)
    $bal = 500.0;                                 // 空头独立账户从500U起
    $trades = []; $n = count($ts); $i = 30;       // 成交/根数/游标
    while ($i < $n - 1) {                         // 逐根扫描
        if (empty($entry[$ts[$i]])) { $i++; continue; }  // 非空头信号跳过
        $M0 = marginAt($ts[$i], $bal);            // 本笔保证金
        $e = $o[$i + 1];                          // 下一根开盘开空
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;  // 名义/均价/加仓计数
        $fee = $notional * $FEE_MAKER;            // 开仓手续费
        $liqPx = $avg * (1 + $liqRise);           // 空头爆仓价(上涨方向)
        $tgtPx = $avg * (1 - $TPR);               // 空头止盈价(-2%)
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;  // 结局/出场价/游标/持仓根数
        while ($j < $n) {                         // 持仓推进
            $barsHeld = $j - $i;                  // 持仓小时数
            $hitLiq = $h[$j] >= $liqPx;           // 最高触及爆仓价
            $hitTp  = $l[$j] <= $tgtPx;           // 最低触及止盈价
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 爆仓
            if ($hitTp) { $outcome = 'WIN'; $exitPx = $tgtPx; break; }   // 止盈
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }  // 超7天超时平仓
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] < $avg) {  // 空头fib加仓: 仅下行(现价<均价)+未满5轮
                $ap = $o[$j + 1];                 // 加仓价
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);  // 加权新均价
                $notional += $M0 * $LEV; $adds++; // 名义增加
                $fee += $M0 * $LEV * $FEE_TAKER;  // 加仓手续费
                $liqPx = $avg * (1 + $liqRise);   // 重算爆仓价
                $tgtPx = $avg * (1 - $TPR);       // 重算止盈价
            }
            $j++;                                 // 推进下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }  // 数据耗尽平仓
        $gross = ($avg - $exitPx) * $notional / $avg;  // 空头毛盈亏(方向取反)
        $funding = $notional * $FUND8H * ($barsHeld / 8);   // 空头收 funding   // 资金费为空头收入
        $pnl = $gross + $funding - $fee;          // 空头净盈亏
        $mg = $M0 * (1 + $adds);                  // 总保证金
        if ($pnl < -$mg) $pnl = -$mg;             // 亏损封顶
        $bal = round($bal + $pnl, 2);             // 入账
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,  // 记录成交
                     'margin' => $mg, 'pnl' => round($pnl, 3), 'bal' => $bal];  // 保证金/盈亏/余额
        if ($bal <= 0) break;                     // 爆仓停止
        $i = $j + 1;                              // 下一笔
    }
    return [$trades, $bal];                       // 返回成交与余额
}

function summ($trades, $bal) {                    // 成交列表汇总统计
    $w = 0; $lq = 0; $to = 0; $pl = 0.0; $cum = 0.0; $peak = 0.0; $maxDD = 0.0; $adds = 0; $hg = 0;  // 胜/爆/超时/总盈/累计/峰值/最大回撤/加仓/对冲计数
    foreach ($trades as $t) {                     // 逐笔统计
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'LIQ') $lq++; else $to++;  // 按结局分类计数
        $pl += $t['pnl']; $cum += $t['pnl']; $adds += $t['adds'];  // 累计盈亏与加仓
        $hg += $t['hg'] ?? 0;                     // 累计对冲开空次数
        if ($cum > $peak) $peak = $cum;           // 更新累计盈亏峰值
        if ($peak - $cum > $maxDD) $maxDD = $peak - $cum;  // 更新最大回撤
    }
    $n = count($trades);                          // 总笔数
    return ['n' => $n, 'win' => $w, 'liq' => $lq, 'to' => $to, 'adds' => $adds, 'hg' => $hg,  // 汇总字段
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2),  // 胜率/总盈亏
            'final_bal' => round($bal, 2), 'max_dd' => round($maxDD, 2),  // 期末余额/最大回撤
            'ev' => $n ? round($pl / $n, 3) : 0]; // 单笔期望值
}
function windowSum($trades, $days) {              // 近N天窗口统计
    $cut = end($trades)['tout'] - $days * 86400000;  // 窗口起点时间戳
    $pl = 0; $w = 0; $nn = 0;                     // 盈亏/胜场/笔数
    foreach ($trades as $t) if ($t['tout'] >= $cut) { $nn++; $pl += $t['pnl']; if ($t['out'] == 'WIN') $w++; }  // 统计窗口内成交
    return ['n' => $nn, 'win' => $w, 'win_r' => $nn ? round(100 * $w / $nn, 1) : 0, 'total' => round($pl, 2)];  // 窗口摘要
}

echo "== sims ==\n"; flush();                     // 阶段提示: 开始模拟
$res = []; $detail = [];                          // 各方案结果/各方案明细
// A0 纯多头
[$trA, $balA] = simLong($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryL, ['trig' => 0]);  // A0: 不带对冲的纯多头
$res[] = ['key' => 'A0', 'name' => 'A0 纯多头(现行)·20U+3U/天'] + summ($trA, $balA) + ['last30' => windowSum($trA, 30)];  // 汇总+近30天窗口
$detail['A0'] = $trA;                             // 保存A0明细
echo "A0: " . json_encode($res[0], JSON_UNESCAPED_UNICODE) . "\n"; flush();  // 输出A0结果
// B2 动态对冲 trig 档
foreach ([0.003, 0.005, 0.008] as $tg) {          // 三档对冲触发阈值: 跌破均价0.3%/0.5%/0.8%
    [$trB, $balB] = simLong($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryL, ['trig' => $tg]);  // 带动态对冲跑多头
    $sm = summ($trB, $balB);                      // 汇总
    $res[] = ['key' => 'B2_' . $tg, 'name' => "B2 动态对冲·跌破{$tg}%开空对冲"] + $sm + ['last30' => windowSum($trB, 30)];  // 存结果
    $detail['B2_' . $tg] = $trB;                  // 保存明细
    echo "B2_$tg: " . json_encode($sm, JSON_UNESCAPED_UNICODE) . "\n"; flush();  // 输出该档结果
}
// B1 双向镜像
[$trL, $balL] = simLong($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryL, ['trig' => 0]);  // 多腿独立跑
[$trS, $balS] = simShort($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryS);  // 空腿独立跑
$merged = array_merge($trL, $trS);                // 合并多空成交
usort($merged, fn($a, $b) => $a['tin'] <=> $b['tin']);  // 按入场时间排序
$smB1 = summ($merged, $balL + $balS - 500);       // 两账户合并(各500U, 减去一份本金)
$res[] = ['key' => 'B1', 'name' => 'B1 双向镜像(多+空独立)'] + $smB1 + ['last30' => windowSum($merged, 30)];  // 存B1结果
$detail['B1'] = $merged;                          // 保存B1明细
echo "B1: " . json_encode($smB1, JSON_UNESCAPED_UNICODE) . "\n"; flush();  // 输出B1结果

// 逐笔明细(A0 + 最优对冲档, 各取最后80笔)
function detailOut($tr, $k = 80) {                // 明细格式化输出(默认最后80笔)
    $tr = array_slice($tr, -$k);                  // 截取最后k笔
    $out = [];                                    // 格式化结果
    foreach ($tr as $t) $out[] = ['tin' => date('m-d H:i', (int)($t['tin'] / 1000)),  // 入场时间格式化
        'tout' => date('m-d H:i', (int)($t['tout'] / 1000)), 'out' => $t['out'], 'adds' => $t['adds'],  // 出场时间/结局/加仓数
        'hg' => $t['hg'] ?? 0, 'margin' => $t['margin'], 'pnl' => $t['pnl'], 'bal' => $t['bal']];  // 对冲次数/保证金/盈亏/余额
    return $out;                                  // 返回明细
}

$out = [                                          // 组装输出 JSON
    'params' => ['lev' => 100, 'bal0' => 500, 'margin' => '20U+3U×已过天数, 封顶余额/6',  // 参数说明
                 'add' => 'fib618顺势加仓≤5轮, 每轮=开仓时保证金', 'tp' => '价格+2%(ROI+200%)',  // 加仓与止盈说明
                 'hedge' => 'B2: 收盘跌破均价×(1-trig)→开等额空单; 空头fib618下行加仓≤5轮; 再跌1%止盈落袋; 回升+0.2%小亏平空; 对冲期间合并计算组合爆仓',  // 对冲规则说明
                 'span' => round($spanDays) . '天',  // 回测跨度
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000))],  // 起止日期
    'signals' => ['total_L' => count($entryL), 'total_S' => count($entryS),  // 多/空信号总数
                  'per_day_L' => round(count($entryL) / $spanDays, 2),  // 多头信号日均
                  'per_day_S' => round(count($entryS) / $spanDays, 2),  // 空头信号日均
                  'last30_sum_L' => $l30sum, 'last30_max_L' => $l30max,  // 近30天多头信号合计/峰值
                  'last30_per_day_L' => round($l30sum / max(1, count($last30Rows)), 2),  // 近30天日均
                  'last30' => $last30Rows],       // 近30天逐日明细
    'results' => $res,                            // 各方案汇总
    'detail_A0' => detailOut($detail['A0']),      // A0 逐笔明细
    'detail_best' => detailOut($detail['B2_0.005']),  // 最优对冲档(0.5%)逐笔明细
];
file_put_contents('E:/finally-main/web/eth_hedge20_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出报告 JSON
echo "JSON OK\n";                                 // 完成提示
