<?php
/**
 * eth_backstop.php — 自设强平兜底比例 回测(CLI)
 * 机制: 浮亏达 该笔总保证金(含加仓)×frac → 市价全平(抢在交易所强平线~60%之前)
 *   frac=0    现行(只靠交易所爆仓兜底, 亏~60%保证金被强平)
 *   frac=0.5  引擎新兜底(保证单笔最终亏损 ≤ 该笔保证金)
 * 价格口径: 亏损/总保证金 = 跌幅×杠杆 → 强平价 = 均价×(1 - frac/LEV), 与加仓轮数无关(比例恒定)
 * 阶梯两套: 1U+3U/天(引擎现行) 与 20U+3U/天, 均封顶余额/6, 每轮加仓=开仓时保证金
 * 输出: web/eth_backstop_data.json
 */
ini_set('memory_limit', '1024M');                                 // 提升 PHP 内存上限到 1G
set_time_limit(0);                                                // 取消脚本执行时间限制
$LEV = 100; $TPR = 0.02; $MAXADD = 5;                             // 杠杆100x; 止盈+2%; 最多加仓5轮
$MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 维持保证金率0.4%; 手续费与资金费率

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库并设 utf8mb4
$DB = db();                                                       // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行SQL收集全部结果行为数组
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }  // 滑动窗口均线, 前 n-1 个为 null
function fibsig($c, $w = 30) {                                    // fib618信号: 收盘落在30根区间0.618回撤位±3%
    $n = count($c); $out = array_fill(0, $n, false);              // 初始化输出全为无信号
    for ($i = $w - 1; $i < $n; $i++) {                            // 从第30根起扫描
        $lo = INF; $hi = -INF;                                    // 窗口高低初始化
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }  // 求窗口高低
        if ($hi <= $lo) continue;                                 // 无波动跳过
        $fib = $hi - ($hi - $lo) * 0.618;                         // 0.618回撤位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true;  // 收盘在fib±3%内即信号
    }
    return $out;                                                  // 返回信号数组
}
function load_k($inst, $bar) {                                    // 加载指定品种/周期K线
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按时间升序读 kline 表(列名 o/h/l/c/vol)
    $ts = []; $o = []; $h = []; $l = []; $c = []; $v = [];        // 初始化序列
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; }  // 转型入数组
    return [$ts, $o, $h, $l, $c, $v];                             // 返回六元组
}
function buy_ratio_series($o, $h, $l, $c) {                       // 逐根买方占比(taker买比代理)
    $n = count($c); $br = [];                                     // 根数与输出
    for ($i = 0; $i < $n; $i++) {                                 // 逐根计算
        $rng = $h[$i] - $l[$i];                                   // 当根振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;           // 收盘在振幅中的位置(-1~1)
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;         // 钳制
        $br[$i] = 0.5 + $dir / 2;                                 // 映射到0~1
    }
    return $br;                                                   // 返回序列
}

[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h');      // 加载 ETH 1小时K线
[$ts4h, , , , $c4h, ] = load_k('eth', '4h');                      // 加载 ETH 4小时K线(只取时间戳与收盘)
$n = count($ts1h);                                                // 1h根数

// breadth
$up = []; $tot = [];                                              // 各日强势数/总数
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");  // 读全市场日线收盘
$cur = null; $win = []; $s = 0.0;                                 // 当前品种/20日窗口/窗口和
foreach ($rr as $x) {                                             // 逐行遍历
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; }    // 换品种重置
    $win[] = (float)$x[2]; $s += (float)$x[2];                    // 收盘入窗口累加
    if (count($win) > 20) $s -= array_shift($win);                // 超20移除最旧
    if (count($win) == 20) {                                      // 满20算MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2];       // 时间戳/MA20/收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0);          // 收盘≥MA20强势+1
        $tot[$t] = ($tot[$t] ?? 0) + 1;                           // 总数+1
    }
}
$brSrc = [];                                                      // 宽度源
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k;  // 品种数>10才算宽度
$sorted = array_keys($brSrc); sort($sorted);                      // 日期升序
function ffillBr($t) {                                            // 前向填充取宽度
    global $brSrc, $sorted;                                       // 引用全局
    $lo = 0; $hi = count($sorted) - 1; $res = null;               // 二分初始化
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }  // 二分找<=t最大日期
    return $res !== null ? $brSrc[$res] : null;                   // 返回宽度或null
}
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);                       // 1h MA20/MA10
$ma5_4h = ma($c4h, 5);                                            // 4h MA5
$tr4hUp = [];                                                     // 4h趋势状态表
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hUp[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i];  // 4h收盘>MA5记上行
$tr4hUpF = []; $p4 = 0; $cur4 = null; $n4 = count($ts4h);         // 填充到1h粒度准备
foreach ($ts1h as $tt) { while ($p4 < $n4 && $ts4h[$p4] <= $tt) { $cur4 = $tr4hUp[$ts4h[$p4]] ?? $cur4; $p4++; } $tr4hUpF[$tt] = $cur4; }  // 对齐最近4h趋势
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h);                 // 1h买方占比
$fib = fibsig($c1h);                                              // fib618加仓信号
$entryA = [];                                                     // 六重共振入场集
for ($i = 30; $i < $n; $i++) {                                    // 逐根判定
    $t = $ts1h[$i];                                               // 当前时点
    $b = ffillBr($t);                                             // 市场宽度
    if ($b !== null && $b > 0.5 && $ma20[$i] !== null && $c1h[$i] > $ma20[$i] &&  // 宽度>0.5 且收盘>MA20
        $ma10[$i] !== null && $c1h[$i] > $ma10[$i] && !empty($tr4hUpF[$t])) $entryA[$t] = true;  // 且收盘>MA10 且4h上行 → 入场
}
echo "entry=" . count($entryA) . "\n"; flush();                   // 输出入场信号数

// ===== sim: frac=自设强平比例(0=不启用) =====
function sim($o, $h, $l, $c, $ts, $fib, $entry, $frac, $lbase) {  // 核心回测: $frac 兜底比例, $lbase 保证金基数
    global $LEV, $TPR, $MAXADD, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H;  // 引入全局参数
    $liqDrop = 1.0 / $LEV - $MMR;                                 // 交易所爆仓所需跌幅
    $bal = 500.0;                                                 // 账户余额从500U起
    $startT = $ts[30];                                            // 回测起点
    $trades = []; $n = count($ts); $i = 30;                       // 交易列表/根数/起始下标
    while ($i < $n - 1) {                                         // 主循环找入场
        if (empty($entry[$ts[$i]])) { $i++; continue; }           // 无入场信号跳过
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400); // 已过天数
        $M = round(min($lbase + 3.0 * $days, $bal / 6.0), 2);     // 保证金=base+3U×天数, 封顶余额/6
        if ($M < 1) $M = 1.0;                                     // 保底1U
        $M0 = $M;                                                 // 开仓保证金
        $e = $o[$i + 1];                                          // 入场价=下一根开盘
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;             // 名义/均价/加仓次数
        $fee = $notional * $FEE_MAKER;                            // 入场maker费
        $liqPx = $avg * (1 - $liqDrop);                           // 交易所爆仓价
        $tgtPx = $avg * (1 + $TPR);                               // 止盈价
        $stopPx = ($frac > 0) ? $avg * (1 - $frac / $LEV) : 0;    // 自设强平价(比例恒定), frac=0不启用
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;  // 出场结果/出场价/扫描下标/持仓根数
        while ($j < $n) {                                         // 逐根推进持仓
            $barsHeld = $j - $i;                                  // 更新持仓根数
            $hitCut = ($frac > 0) && ($l[$j] <= $stopPx);         // 触自设强平价(启用时)
            $hitLiq = $l[$j] <= $liqPx;                           // 触交易所爆仓价
            $hitTp  = $h[$j] >= $tgtPx;                           // 触止盈价
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; }   // 自设强平先判(抢在交易所线前)
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }    // 爆仓出场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }    // 止盈出场
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }  // 超7天超时平仓
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {  // fib618仅上行加仓≤5轮
                $ap = $o[$j + 1];                                 // 加仓价=下一根开盘
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);  // 加权更新均价
                $notional += $M0 * $LEV; $adds++;                 // 扩名义/计次数
                $fee += $M0 * $LEV * $FEE_TAKER;                  // 加仓taker费
                $liqPx = $avg * (1 - $liqDrop);                   // 重算爆仓价
                $tgtPx = $avg * (1 + $TPR);                       // 重算止盈价
                if ($frac > 0) $stopPx = $avg * (1 - $frac / $LEV);  // 重算自设强平价
            }
            $j++;                                                 // 推进下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }  // 数据耗尽按末根收盘了结
        $gross = ($exitPx - $avg) * $notional / $avg;             // 毛盈亏
        $funding = $notional * $FUND8H * ($barsHeld / 8);         // 资金费成本
        $pnl = $gross - $fee - $funding;                          // 净盈亏
        $mg = $M0 * (1 + $adds);                                  // 本笔总保证金
        if ($pnl < -$mg) $pnl = -$mg;                             // 亏损封底总保证金
        $bal = round($bal + $pnl, 2);                             // 更新账户余额
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,  // 记录交易
                     'margin' => $mg, 'pnl' => round($pnl, 3), 'bal' => $bal];  // 时间/结果/加仓/保证金/盈亏/余额
        if ($bal <= 0) break;                                     // 余额归零停止回测
        $i = $j + 1;                                              // 出场后继续
    }
    return [$trades, $bal];                                       // 返回交易列表与期末余额
}
function summ($trades, $bal) {                                    // 汇总统计
    $w = 0; $cut = 0; $lq = 0; $pl = 0.0; $cum = 0.0; $peak = 0.0; $maxDD = 0.0; $maxLoss = 0.0;  // 各类累加器
    foreach ($trades as $t) {                                     // 逐笔统计
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'CUT') $cut++; elseif ($t['out'] == 'LIQ') $lq++;  // 出场类型计数
        $pl += $t['pnl']; $cum += $t['pnl'];                      // 累计盈亏
        if (-$t['pnl'] > $maxLoss) $maxLoss = -$t['pnl'];         // 最差单笔亏损
        if ($cum > $peak) $peak = $cum;                           // 累计盈亏峰值
        if ($peak - $cum > $maxDD) $maxDD = $peak - $cum;         // 最大回撤
    }
    $n = count($trades);                                          // 总笔数
    return ['n' => $n, 'win' => $w, 'cut' => $cut, 'liq' => $lq,  // 返回统计
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2),  // 胜率与总盈亏
            'final_bal' => round($bal, 2), 'max_dd' => round($maxDD, 2),  // 期末余额与最大回撤
            'max_loss' => round($maxLoss, 2), 'ev' => $n ? round($pl / $n, 3) : 0];  // 最差单笔与单笔期望
}

// ===== 跑矩阵: 阶梯(1U/20U) × frac 档 =====
$fracs = [0, 0.4, 0.5, 0.55, 0.7];                                // 兜底比例档位(0=不启用)
$ladders = [['base' => 1.0, 'name' => '1U+3U/天(引擎现行)'], ['base' => 20.0, 'name' => '20U+3U/天']];  // 两套保证金阶梯
$results = []; $detail = [];                                      // 结果矩阵/明细
foreach ($ladders as $ld) {                                       // 逐阶梯
    foreach ($fracs as $fr) {                                     // 逐frac档
        [$tr, $bal] = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $fr, $ld['base']);  // 模拟该组合
        $sm = summ($tr, $bal);                                    // 汇总统计
        $key = $ld['base'] . '_' . $fr;                           // 组合键
        $results[] = ['key' => $key, 'ladder' => $ld['name'], 'frac' => $fr] + $sm;  // 存结果
        $detail[$key] = array_slice($tr, -60);                    // 保留末60笔明细
        echo "{$ld['name']} frac=$fr: " . json_encode($sm, JSON_UNESCAPED_UNICODE) . "\n"; flush();  // 输出该组合结果
    }
}
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;        // 回测跨度天数
function detOut($tr) {                                            // 明细格式化输出
    $out = [];                                                    // 输出数组
    foreach ($tr as $t) $out[] = ['tin' => date('m-d H:i', (int)($t['tin'] / 1000)),  // 格式化入场时间
        'tout' => date('m-d H:i', (int)($t['tout'] / 1000)), 'out' => $t['out'], 'adds' => $t['adds'],  // 出场时间/结果/加仓
        'margin' => $t['margin'], 'pnl' => $t['pnl'], 'bal' => $t['bal']];  // 保证金/盈亏/余额
    return $out;                                                  // 返回格式化明细
}
$out = [                                                          // 组装输出JSON
    'params' => ['lev' => 100, 'bal0' => 500, 'tp' => '价格+2%', 'add' => 'fib618仅上行≤5轮每轮=开仓保证金',  // 基础参数
                 'rule' => '自设强平兜底: 浮亏达该笔总保证金×frac → 市价全平; 交易所强平线≈亏60%保证金(价格-0.6%)',  // 兜底规则说明
                 'span' => round($spanDays) . '天',                // 数据跨度
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000))],  // 数据区间
    'results' => $results,                                        // 阶梯×frac 结果矩阵
    'detail_1_0' => detOut($detail['1_0']), 'detail_1_0.5' => detOut($detail['1_0.5']),  // 1U阶梯 frac=0 与 0.5 的明细对比
];
file_put_contents('E:/finally-main/web/eth_backstop_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出JSON
echo "JSON OK\n";                                                 // 完成提示
