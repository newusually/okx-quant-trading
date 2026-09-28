<?php
/**
 * eth_all15m.php — 无共振版: 所有合约 fib618上涨信号 + fib618仅上行加仓≤5轮 + 50%兜底强平(CLI)
 * A/B 两部分:
 *   A: 15m K线, 476合约, 数据只有 2026-08-23~09-25(约34天) → 真实"一个月"答案
 *   B: 1h  K线, 476合约, 完整一年(2025-09-25~2026-09-25) → 真实"一年"答案(同策略, 周期1h)
 * 策略: 买入=fib618回调信号+上涨结构(窗口30内高点出现在低点之后); 加仓=fib618+现价>均价≤5轮每轮=开仓保证金;
 *       下跌不减仓; 止盈=价格+2%自均价; 50%兜底强平=浮亏达该笔总保证金(含加仓)×50%市价全平;
 *       保证金=1U+1U×已过天数, 封顶500/6; 同合约同时1仓。
 * 输出: web/eth_all15m_data.json
 */
ini_set('memory_limit', '2048M');                                 // 提升 PHP 内存上限到 2G(全市场两轮回测)
set_time_limit(0);                                                // 取消脚本执行时间限制
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆100x; 止盈+2%; 维持保证金率0.4%; 手续费与资金费率
$BAL0 = 500.0; $CAP = $BAL0 / 6.0; $BASE = 1.0; $STEP = 1.0;      // 余额500U; 保证金封顶500/6U; 基数1U; 每日递增1U

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库并设 utf8mb4
$DB = db();                                                       // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行SQL收集全部结果行为数组
function fibsigUp($c, $w = 30) {                                  // 入场信号: fib618回调+上涨结构(高点在低点之后)
    // fib618回调信号 + 上涨结构: 窗口内 swing high 出现在 swing low 之后
    $n = count($c); $out = array_fill(0, $n, false);              // 初始化输出全为无信号
    for ($i = $w - 1; $i < $n; $i++) {                            // 从第30根起扫描
        $lo = INF; $hi = -INF; $loIdx = 0; $hiIdx = 0;            // 窗口高低与位置
        for ($j = $i - $w + 1; $j <= $i; $j++) {                  // 扫描30根窗口
            if ($c[$j] < $lo) { $lo = $c[$j]; $loIdx = $j; }      // 记录最低价及位置
            if ($c[$j] > $hi) { $hi = $c[$j]; $hiIdx = $j; }      // 记录最高价及位置
        }
        if ($hi <= $lo || $hiIdx <= $loIdx) continue;             // 要求高点出现在低点之后(上涨结构)
        $fib = $hi - ($hi - $lo) * 0.618;                         // 计算0.618回撤位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true;  // 收盘在fib±3%内即入场信号
    }
    return $out;                                                  // 返回信号数组
}
function fibsig($c, $w = 30) { // 加仓用(与之前回测同参)          // 加仓信号: 纯fib618回调
    $n = count($c); $out = array_fill(0, $n, false);              // 初始化输出
    for ($i = $w - 1; $i < $n; $i++) {                            // 从第30根起扫描
        $lo = INF; $hi = -INF;                                    // 窗口高低初始化
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }  // 求30根窗口高低
        if ($hi <= $lo) continue;                                 // 无波动跳过
        $fib = $hi - ($hi - $lo) * 0.618;                         // 0.618回撤位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true;  // 收盘在fib±3%内即加仓信号
    }
    return $out;                                                  // 返回信号数组
}
function load_k($inst, $bar) {                                    // 加载指定品种/周期K线
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按时间升序读 kline_<INST>_usdt_swap_<bar> 表
    $ts = []; $o = []; $h = []; $l = []; $c = [];                 // 初始化序列
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }  // 转型入数组
    return [$ts, $o, $h, $l, $c];                                 // 返回五元组
}

function sim($o, $h, $l, $c, $ts, $fibE, $fibA, $barHours) {      // 核心回测: $barHours 为K线小时数(资金费/超时换算)
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP, $BASE, $STEP;  // 引入全局参数
    $liqDrop = 1.0 / $LEV - $MMR;                                 // 爆仓所需跌幅
    $startT = $ts[30];                                            // 回测起点时间
    $maxHold = (int)(7 * 24 / $barHours);                         // 7天超时对应的K线根数
    $trades = []; $n = count($ts); $i = 30;                       // 交易列表/根数/起始下标
    while ($i < $n - 1) {                                         // 主循环找入场
        if (empty($fibE[$ts[$i]])) { $i++; continue; }            // 无fib618上涨入场信号跳过
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400); // 已过天数
        $M = round(min($BASE + $STEP * $days, $CAP), 2);          // 保证金=1U+1U×天数, 封顶500/6
        if ($M < 1) $M = 1.0;                                     // 保底1U
        $M0 = $M;                                                 // 开仓保证金(加仓每轮同额)
        $e = $o[$i + 1];                                          // 入场价=下一根开盘
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;             // 名义/均价/加仓次数
        $fee = $notional * $FEE_MAKER;                            // 入场maker费
        $funding = 0.0;                                           // 资金费累加
        $liqPx = $avg * (1 - $liqDrop);                           // 爆仓价
        $tgtPx = $avg * (1 + $TPR);                               // 止盈价
        $stopPx = $avg * (1 - 0.5 / $LEV);                        // 50%兜底强平价(浮亏达保证金50%)
        $outcome = null; $exitPx = null; $j = $i + 1;             // 出场结果/出场价/扫描下标
        while ($j < $n) {                                         // 逐根推进持仓
            $barsHeld = $j - $i;                                  // 持仓根数
            $funding += $notional * $FUND8H / 8 * $barHours;      // 按K线小时数折算资金费
            $hitCut = $l[$j] <= $stopPx;                          // 触50%兜底强平价
            $hitLiq = $l[$j] <= $liqPx;                           // 触爆仓价
            $hitTp  = $h[$j] >= $tgtPx;                           // 触止盈价
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; }  // 50%兜底强平出场
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }   // 爆仓出场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }   // 止盈出场
            if ($barsHeld >= $maxHold) { $outcome = 'TO'; $exitPx = $c[$j]; break; }  // 超7天超时平仓
            if ($adds < 5 && $j + 1 < $n && !empty($fibA[$ts[$j]]) && $c[$j] > $avg) {  // 加仓: fib618信号+仅上行≤5轮
                $ap = $o[$j + 1];                                 // 加仓价=下一根开盘
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);  // 加权更新均价
                $notional += $M0 * $LEV; $adds++;                 // 扩名义/计次数
                $fee += $M0 * $LEV * $FEE_TAKER;                  // 加仓taker费
                $liqPx = $avg * (1 - $liqDrop);                   // 重算爆仓价
                $tgtPx = $avg * (1 + $TPR);                       // 重算止盈价
                $stopPx = $avg * (1 - 0.5 / $LEV);                // 重算兜底强平价
            }
            $j++;                                                 // 推进下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }  // 数据耗尽按末根收盘了结
        $gross = ($exitPx - $avg) * $notional / $avg;             // 毛盈亏
        $pnl = $gross - $fee - $funding;                          // 净盈亏
        $mg = $M0 * (1 + $adds);                                  // 本笔总保证金
        if ($pnl < -$mg) $pnl = -$mg;                             // 亏损封底总保证金
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,  // 记录交易明细
                     'margin' => $mg, 'pnl' => round($pnl, 3)];   // 时间/结果/加仓/保证金/盈亏
        $i = $j + 1;                                              // 出场后继续
    }
    return $trades;                                               // 返回交易列表
}

function aggregate($trades, $tag, &$store) {                      // 聚合统计并写入 $store[$tag]
    $n = 0; $w = 0; $cut = 0; $adds = 0; $pl = 0.0;               // 笔数/胜/兜底强平/加仓/盈亏
    $daily = []; $monthly = [];                                   // 按日/按月盈亏
    foreach ($trades as $x) {                                     // 逐笔统计
        $n++; $pl += $x['pnl']; $adds += $x['adds'];              // 累加计数
        if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'CUT') $cut++;  // 胜/强平计数
        $d = date('Y-m-d', (int)($x['tout'] / 1000));             // 出场日期
        $daily[$d] = ($daily[$d] ?? 0) + $x['pnl'];               // 当日盈亏
        $m = substr($d, 0, 7);                                    // 月份
        $monthly[$m] = ($monthly[$m] ?? 0) + $x['pnl'];           // 当月盈亏
    }
    $days = array_keys($daily); sort($days);                      // 日期升序
    $eq = 500.0; $peak = 500.0; $maxDD = 0.0; $dailyArr = [];     // 权益曲线(从500U起)
    foreach ($days as $d) { $eq += $daily[$d]; if ($eq > $peak) $peak = $eq; if ($peak - $eq > $maxDD) $maxDD = $peak - $eq; $dailyArr[] = ['d' => $d, 'pnl' => round($daily[$d], 2), 'eq' => round($eq, 2)]; }  // 逐日累计权益并更新峰值/最大回撤
    $monthlyArr = [];                                             // 月度汇总行
    foreach ($monthly as $m => $v) { $md = 0; foreach ($dailyArr as $r) if (substr($r['d'], 0, 7) === $m) $md++; $monthlyArr[] = ['m' => $m, 'pnl' => round($v, 2), 'days' => $md, 'avg' => $md ? round($v / $md, 2) : 0]; }  // 月盈亏与月内交易日数及日均
    $store[$tag] = ['n' => $n, 'win' => $w, 'cut' => $cut, 'adds' => $adds,  // 存入统计结果
        'total' => round($pl, 2), 'max_dd' => round($maxDD, 2),   // 总盈亏与最大回撤
        'days' => count($dailyArr), 'avg_daily' => count($dailyArr) ? round($pl / count($dailyArr), 2) : 0,  // 交易日数与日均盈亏
        'ev' => $n ? round($pl / $n, 3) : 0,                      // 单笔期望
        'range' => $n ? (date('Y-m-d', (int)($trades[0]['tin'] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($trades)['tout'] / 1000))) : '',  // 交易区间
        'monthly' => $monthlyArr, 'daily' => $dailyArr];          // 月度与逐日数据
}

// ===== 合约清单 =====
$tbls = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline_%_usdt_swap_1h' ORDER BY table_name");  // 枚举全部1h表
$insts = [];                                                      // 品种列表
foreach ($tbls as $x) { if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $x[0], $m)) $insts[] = $m[1]; }  // 提取品种ID
echo "insts=" . count($insts) . "\n"; flush();                    // 输出品种数

// ===== A: 15m(约34天真实数据) =====
$tradesA = []; $done = 0;                                         // A部分交易与进度
foreach ($insts as $inst) {                                       // 逐合约跑15m
    $chk = rows("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='trading' AND table_name='kline_{$inst}_usdt_swap_15m'");  // 检查15m表是否存在
    if (!(int)$chk[0][0]) continue;                               // 无表跳过
    [$ts, $o, $h, $l, $c] = load_k($inst, '15m');                 // 加载15m K线
    if (count($ts) < 200) { $done++; continue; }                  // 数据太少跳过
    $fibE = fibsigUp($c); $fibA = fibsig($c);                     // 入场/加仓信号序列
    $fE = []; foreach ($ts as $k => $t) if ($fibE[$k]) $fE[$t] = true;  // 入场信号转时间戳映射
    $fA = []; foreach ($ts as $k => $t) if ($fibA[$k]) $fA[$t] = true;  // 加仓信号转时间戳映射
    foreach (sim($o, $h, $l, $c, $ts, $fE, $fA, 0.25) as $x) { $x['inst'] = $inst; $tradesA[] = $x; }  // 回测15m(每根0.25小时)并入总表
    $done++;                                                      // 完成一个
    if ($done % 80 == 0) { echo "A $done/476\n"; flush(); }       // 每80个输出进度
    unset($ts, $o, $h, $l, $c, $fibE, $fibA, $fE, $fA);           // 释放内存
}
aggregate($tradesA, 'm15', $store);                               // 聚合A部分统计
echo "A15m: " . json_encode(array_intersect_key($store['m15'], array_flip(['n','win','cut','total','max_dd','days','avg_daily'])), JSON_UNESCAPED_UNICODE) . "\n"; flush();  // 输出A部分关键统计

// ===== B: 1h(完整一年) =====
$tradesB = []; $done = 0;                                         // B部分交易与进度
foreach ($insts as $inst) {                                       // 逐合约跑1h
    [$ts, $o, $h, $l, $c] = load_k($inst, '1h');                  // 加载1h K线
    if (count($ts) < 300) { $done++; continue; }                  // 数据太少跳过
    $fibE = fibsigUp($c); $fibA = fibsig($c);                     // 入场/加仓信号
    $fE = []; foreach ($ts as $k => $t) if ($fibE[$k]) $fE[$t] = true;  // 入场映射
    $fA = []; foreach ($ts as $k => $t) if ($fibA[$k]) $fA[$t] = true;  // 加仓映射
    foreach (sim($o, $h, $l, $c, $ts, $fE, $fA, 1.0) as $x) { $x['inst'] = $inst; $tradesB[] = $x; }  // 回测1h并入总表
    $done++;                                                      // 完成一个
    if ($done % 80 == 0) { echo "B $done/476\n"; flush(); }       // 进度输出
    unset($ts, $o, $h, $l, $c, $fibE, $fibA, $fE, $fA);           // 释放内存
}
aggregate($tradesB, 'h1', $store);                                // 聚合B部分统计
echo "B1h: " . json_encode(array_intersect_key($store['h1'], array_intersect_key($store['h1'], array_flip(['n','win','cut','total','max_dd','days','avg_daily']))), JSON_UNESCAPED_UNICODE) . "\n"; flush();  // 输出B部分关键统计

// 合约排行(1h档)
$per = [];                                                        // 排行数组
$tmp = [];                                                        // 按合约累加的临时表
foreach ($tradesB as $x) { $k = strtoupper($x['inst'] ?? ''); $tmp[$k]['pnl'] = ($tmp[$k]['pnl'] ?? 0) + $x['pnl']; $tmp[$k]['n'] = ($tmp[$k]['n'] ?? 0) + 1; }  // 逐合约累加盈亏与笔数
foreach ($tmp as $k => $v) $per[] = ['inst' => $k, 'n' => $v['n'], 'pnl' => round($v['pnl'], 2)];  // 组装排行行
usort($per, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; });  // 按盈亏降序

$out = [                                                          // 组装输出JSON
    'params' => ['strategy' => '无共振版: fib618上涨信号买入(窗口30内高点在低点之后+回调至61.8位±3%) + fib618仅上行加仓≤5轮(每轮=开仓保证金) + 下跌不减仓 + 止盈价格+2%自均价 + 50%兜底强平(浮亏达该笔总保证金(含加仓)×50%市价全平)',  // 策略口径说明
        'margin' => '每笔=1U+1U×已过天数, 封顶500/6', 'bal0' => $BAL0, 'lev' => 100,  // 保证金与基础参数
        'note' => '15m全市场数据库只有约34天(8/23~9/25), "一个月"用真实15m数据; "一年"用同策略跑1h完整一年(2025-09-25~2026-09-25)'],  // 数据口径说明
    'm15' => $store['m15'], 'h1' => $store['h1'],                 // A/B两部分统计
    'rank_1h' => ['top' => array_slice($per, 0, 12), 'bottom' => array_slice(array_reverse($per), 0, 12)],  // 1h档合约盈亏前12与后12
];
file_put_contents('E:/finally-main/web/eth_all15m_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出JSON
echo "JSON OK\n";                                                 // 完成提示
