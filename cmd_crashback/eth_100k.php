<?php
/**
 * eth_100k.php — 一年赚10万U的保证金配比核算(CLI)
 * 方案A: 100x 每笔620U (20U版×31, 线性缩放)
 * 方案B: 10x 每笔5440U (500U版×10.24)
 * 共同: 六重共振+TP价格+2%+fib618加仓5轮+无美金强平 只买涨
 * 输出: web/eth_100k_data.json (含两方案账户安全核算)
 */
ini_set('memory_limit', '1024M');                                 // 提升 PHP 内存上限到 1G, 应对大 K 线数据集
set_time_limit(0);                                                // 取消脚本最大执行时间限制(CLI 长时间回测)
$MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 维持保证金率0.4%; maker手续费0.02%; taker手续费0.05%; 每8小时资金费率0.01%
$TPR = 0.02; $MAXADD = 5;                                         // 止盈幅度+2%; fib618信号最多加仓5轮

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库并设 utf8mb4 字符集, 返回连接对象
$DB = db();                                                       // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行 SQL, 把结果集所有行(数字索引)收集为数组返回
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }  // 滑动窗口计算简单移动平均线, 前 n-1 个位置为 null
function fibsig($c, $w = 30) {                                    // 定义 fib618 信号函数: 收盘价是否落在近 w 根区间 0.618 回撤位 ±3%
    $n = count($c); $out = array_fill(0, $n, false);              // 初始化输出数组, 默认全部无信号
    for ($i = $w - 1; $i < $n; $i++) {                            // 从第 w 根起逐根扫描
        $lo = INF; $hi = -INF;                                    // 初始化窗口最低/最高价
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }  // 求窗口内收盘价最高/最低
        if ($hi <= $lo) continue;                                 // 窗口无波动则跳过
        $fib = $hi - ($hi - $lo) * 0.618;                         // 计算区间 0.618 回撤位(fib618)
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true;  // 收盘价在 fib618 ±3% 内则标记信号
    }
    return $out;                                                  // 返回逐根布尔信号数组
}
function load_k($inst, $bar) {                                    // 从本地库加载指定品种/周期的 K 线数据
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按时间升序读取 kline_<INST>_usdt_swap_<bar> 表(列名 o/h/l/c/vol)
    $ts = []; $o = []; $h = []; $l = []; $c = []; $v = [];        // 初始化时间戳与开高低收量六个数组
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; }  // 逐行转型并入对应数组
    return [$ts, $o, $h, $l, $c, $v];                             // 返回六元组数据
}
function buy_ratio_series($o, $h, $l, $c, $v) {                   // 计算逐根K线"买方占比"序列(0~1)
    $n = count($c); $br = [];                                     // 取K线数量, 初始化输出
    for ($i = 0; $i < $n; $i++) {                                 // 逐根遍历
        $rng = $h[$i] - $l[$i];                                   // 当根振幅(高-低)
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;           // 收盘在振幅中的相对位置(-1~1)度量方向强度
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;         // 钳制方向值到 [-1,1]
        $br[$i] = 0.5 + $dir / 2;                                 // 映射到 0~1 作为买方占比
    }
    return $br;                                                   // 返回买方占比序列
}
function sim($o, $h, $l, $c, $ts, $fib, $entry, $br, $lev, $margin, $addU) {  // 核心回测模拟: 给定杠杆/保证金/加仓额逐笔模拟交易
    global $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $TPR, $MAXADD;  // 引入全局费率与参数
    $liqDrop = 1.0 / $lev - $MMR;                                 // 计算爆仓所需价格跌幅(1/杠杆 - 维持保证金率)
    $slP = ($lev <= 10) ? 0.03 : 10.0;   // 10x保留SL-3%; 100x下SL永不触发  // 止损幅度: 10x 用 -3%, 100x 设为 1000% 使其永不触发
    $trades = []; $n = count($ts); $i = 30;                       // 初始化交易列表, 从第30根开始扫(留足指标预热)
    while ($i < $n - 1) {                                         // 主循环逐根找入场信号
        if (empty($entry[$ts[$i]])) { $i++; continue; }           // 当前时间无六重共振入场信号则跳过
        $s = 0; $cnt = 0;                                         // 初始化近12根买方占比求和
        for ($k = $i - 11; $k <= $i; $k++) if ($k >= 0) { $s += $br[$k]; $cnt++; }  // 累加近12根买方占比
        if ($cnt == 0 || $s / $cnt < 0.5) { $i++; continue; }     // 均值买方占比<0.5(多头氛围不足)则跳过
        $e = $o[$i + 1];                                          // 以信号下一根开盘价入场
        $notional = $margin * $lev; $avg = $e; $adds = 0;         // 名义本金=保证金×杠杆; 记录持仓均价与已加仓次数
        $fee = $notional * $FEE_MAKER;                            // 入场 maker 手续费
        $liqPx = $avg * (1 - $liqDrop);                           // 初始爆仓价
        $slPx = $avg * (1 - $slP);                                // 初始止损价
        $tgtPx = $avg * (1 + $TPR);                               // 止盈价(均价+2%)
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;  // 初始化出场结果/出场价/扫描下标/持仓K线数
        while ($j < $n) {                                         // 逐根推进判断出场
            $barsHeld = $j - $i;                                  // 更新已持有K线数
            $hitLiq = $l[$j] <= $liqPx;                           // 本根最低价触及爆仓价
            $hitSl  = $l[$j] <= $slPx;                            // 本根最低价触及止损价
            $hitTp  = $h[$j] >= $tgtPx;                           // 本根最高价触及止盈价
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 爆仓出场
            if ($hitSl)  { $outcome = 'SL';  $exitPx = $slPx;  break; }  // 止损出场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }  // 止盈出场
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }  // 持仓超7天按收盘价强制平仓
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {  // 未达加仓上限且出现fib618信号且价格高于均价
                $ap = $o[$j + 1];                                 // 加仓价取下一根开盘价
                $avg = ($avg * $notional + $ap * $addU * $lev) / ($notional + $addU * $lev);  // 加权更新持仓均价
                $notional += $addU * $lev; $adds++;               // 扩大名义本金并计入加仓次数
                $fee += $addU * $lev * $FEE_TAKER;                // 加仓按 taker 计手续费
                $liqPx = $avg * (1 - $liqDrop);                   // 重算爆仓价
                $slPx = $avg * (1 - $slP);                        // 重算止损价
                $tgtPx = $avg * (1 + $TPR);                       // 重算止盈价
            }
            $j++;                                                 // 推进到下一根K线
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }  // 数据耗尽未出场则按末根收盘价强制了结
        $gross = ($exitPx - $avg) * $notional / $avg;             // 毛盈亏=(出场价-均价)×名义本金/均价
        $funding = $notional * $FUND8H * ($barsHeld / 8);         // 资金费成本: 名义本金×费率×(持仓小时数/8)
        $pnl = $gross - $fee - $funding;                          // 净盈亏=毛盈亏-手续费-资金费
        $mg = $margin + $adds * $addU;                            // 本笔总投入保证金(本金+各轮加仓)
        if ($pnl < -$mg) $pnl = -$mg;                             // 亏损封底为总保证金(不穿仓)
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,  // 记录一笔交易明细
                     'margin' => $mg, 'pnl' => round($pnl, 2)];   // 含入场/出场时间、结果、加仓次数、保证金、盈亏
        $i = $j + 1;                                              // 从出场后一根继续扫描(不重叠持仓)
    }
    return $trades;                                               // 返回全部交易列表
}
function analyze($trades, $bal) {                                 // 统计交易序列的账户安全指标
    $cum = 0; $minCum = 0; $maxStreak = 0; $streak = 0; $streakLoss = 0; $maxStreakLoss = 0;  // 初始化累计盈亏/最低点/最长连亏等
    $gw = 0; $gl = 0;                                             // 盈利总额与亏损总额
    foreach ($trades as $t) {                                     // 逐笔累加统计
        $cum += $t['pnl']; if ($cum < $minCum) $minCum = $cum;    // 累计盈亏并记录最低点(最大回撤用)
        if ($t['pnl'] < 0) { $streak++; $streakLoss += $t['pnl']; if ($streak > $maxStreak) { $maxStreak = $streak; $maxStreakLoss = $streakLoss; } }  // 亏损则连亏计数+1并更新最长连亏
        else { $streak = 0; $streakLoss = 0; }                    // 盈利则重置连亏计数
        if ($t['pnl'] >= 0) $gw += $t['pnl']; else $gl += $t['pnl'];  // 分别累计盈利与亏损总额
    }
    $maxMargin = max(array_column($trades, 'margin'));            // 单笔最大占用保证金
    $worst = min(array_column($trades, 'pnl'));                   // 最差单笔盈亏
    $minEq = $bal + $minCum;                                      // 最低权益=初始余额+累计盈亏最低点
    // 最低安全余额: 最低权益点仍够开下一笔满轮保证金
    $minSafeBal = -$minCum + $maxMargin;                          // 最低安全余额=回撤补偿+满轮保证金
    return ['total' => round($cum, 2), 'gross_win' => round($gw, 2), 'gross_lose' => round($gl, 2),  // 返回统计结果数组
            'min_cum' => round($minCum, 2), 'max_drawdown' => round(-$minCum, 2),                  // 含总盈亏/盈利亏损总额/最大回撤
            'max_lose_streak' => $maxStreak, 'max_streak_loss' => round($maxStreakLoss, 2),        // 最长连亏次数与连亏金额
            'worst_single' => round($worst, 2), 'max_margin' => $maxMargin,                        // 最差单笔与最大保证金
            'min_equity' => round($minEq, 2), 'min_safe_balance' => round($minSafeBal, 2),         // 最低权益与最低安全余额
            'safe' => ($minEq >= $maxMargin) ? '不爆仓' : '会爆仓(权益低于满轮保证金)'];            // 账户安全性判定文案
}

echo "== load ==\n"; flush();                                     // CLI 进度提示: 开始加载数据
[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h');      // 加载 ETH 1小时K线全量数据
[$ts4h, , , , $c4h, ] = load_k('eth', '4h');                      // 加载 ETH 4小时K线(只取时间戳与收盘)
$n = count($ts1h);                                                // 1小时K线总根数
echo "== breadth ==\n"; flush();                                  // 进度提示: 开始计算市场宽度
$up = []; $tot = [];                                              // 各日期站上MA20的品种数 / 有MA20的品种总数
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");  // 读全市场日线收盘价表
$cur = null; $win = []; $s = 0.0;                                 // 当前品种/20日滑动窗口/窗口和
foreach ($rr as $x) {                                             // 逐行遍历日线数据
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; }    // 换品种时重置窗口
    $win[] = (float)$x[2]; $s += (float)$x[2];                    // 收盘价入窗口并累加
    if (count($win) > 20) $s -= array_shift($win);                // 窗口超20个则移除最旧值
    if (count($win) == 20) {                                      // 凑满20日才计算MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2];       // 日期时间戳/MA20值/当日收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0);          // 收盘≥MA20则该日期强势品种数+1
        $tot[$t] = ($tot[$t] ?? 0) + 1;                           // 该日期有效品种总数+1
    }
}
$brSrc = [];                                                      // 各日期市场宽度(强势占比)源数据
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k;  // 品种数>10的日期才计算宽度=强势数/总数
$sorted = array_keys($brSrc); sort($sorted);                      // 有效日期升序排序(供二分查找)
function ffillBr($t) {                                            // 前向填充: 取不晚于时刻t的最近市场宽度
    global $brSrc, $sorted;                                       // 引用全局宽度数据
    $lo = 0; $hi = count($sorted) - 1; $res = null;               // 二分查找初始化
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }  // 标准二分找<=t的最大日期
    return $res !== null ? $brSrc[$res] : null;                   // 返回对应宽度值, 无则null
}
echo "== features ==\n"; flush();                                 // 进度提示: 开始计算特征指标
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);                       // 计算1小时收盘的MA20与MA10
$ma5_4h = ma($c4h, 5);                                            // 计算4小时收盘的MA5
$tr4hUp = [];                                                     // 4小时趋势状态表(时间戳=>是否在MA5上方)
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hUp[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i];  // 4小时收盘>MA5记为上行
$tr4hUpF = [];                                                    // 4小时趋势前向填充到1小时粒度的结果
$p4 = 0; $cur4 = null; $n4 = count($ts4h);                        // 4小时序列指针/当前状态/长度
foreach ($ts1h as $tt) { while ($p4 < $n4 && $ts4h[$p4] <= $tt) { $cur4 = $tr4hUp[$ts4h[$p4]] ?? $cur4; $p4++; } $tr4hUpF[$tt] = $cur4; }  // 每个1小时时刻对齐最近已收盘的4小时趋势
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h, $v1h);           // 计算1小时买方占比序列
$fib = fibsig($c1h);                                              // 计算1小时fib618加仓信号
$entryA = [];                                                     // 六重共振入场信号表(时间戳=>true)
for ($i = 30; $i < $n; $i++) {                                    // 从第30根起逐根判定入场条件
    $t = $ts1h[$i];                                               // 当前K线时间戳
    $b = ffillBr($t);                                             // 取该时刻市场宽度
    if ($b !== null && $b > 0.5 && $ma20[$i] !== null && $c1h[$i] > $ma20[$i] &&  // 条件: 市场宽度>0.5 且收盘>MA20
        $ma10[$i] !== null && $c1h[$i] > $ma10[$i] && !empty($tr4hUpF[$t])) $entryA[$t] = true;  // 且收盘>MA10 且4小时趋势上行 → 触发入场
}
echo "entryA=" . count($entryA) . "\n"; flush();                  // 输出入场信号总数

// ===== 方案A: 100x 每笔620U =====
echo "== plan A: 100x 620U ==\n"; flush();                        // 进度提示: 方案A模拟
$trA = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $br1h, 100, 620, 620);  // 100x杠杆、每笔保证金620U、加仓额620U
$sA = analyze($trA, 10000);                                       // 以1万U初始余额做账户安全核算
echo "A: total={$sA['total']} safe={$sA['safe']} minEq={$sA['min_equity']} minSafeBal={$sA['min_safe_balance']}\n"; flush();  // 输出方案A关键结果

// ===== 方案B: 10x 每笔5440U =====
echo "== plan B: 10x 5440U ==\n"; flush();                        // 进度提示: 方案B模拟
$trB = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $br1h, 10, 5440, 5440);  // 10x杠杆、每笔保证金5440U、加仓额5440U
$sB = analyze($trB, 42000);                                       // 以4.2万U初始余额做账户安全核算
echo "B: total={$sB['total']} safe={$sB['safe']} minEq={$sB['min_equity']} minSafeBal={$sB['min_safe_balance']}\n"; flush();  // 输出方案B关键结果

// 明细构建(两方案)
function build($trades, $bal) {                                   // 构建前端明细数据: 逐笔列表+逐日累计
    $cum = 0; $trOut = []; $daily = []; $no = 0;                  // 初始化累计盈亏/交易输出/按日汇总/序号
    foreach ($trades as $t) {                                     // 逐笔遍历
        $no++; $cum += $t['pnl'];                                 // 序号递增, 累加盈亏
        $d = date('Y-m-d', (int)($t['tout'] / 1000));             // 按出场日期归组(毫秒转秒)
        $daily[$d] = ($daily[$d] ?? 0) + $t['pnl'];               // 累计当日盈亏
        $trOut[] = ['no' => $no,                                  // 组装单笔明细行
            'tin' => date('Y-m-d H:i', (int)($t['tin'] / 1000)), 'tout' => date('Y-m-d H:i', (int)($t['tout'] / 1000)),  // 格式化入出场时间
            'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'pnl' => $t['pnl'],  // 出场类型/加仓次数/保证金/盈亏
            'cum' => round($cum, 2)];                             // 累计盈亏
    }
    ksort($daily);                                                // 按日期升序排列
    $dayRows = []; $c2 = 0;                                       // 初始化逐日行与二次累计
    foreach ($daily as $d => $v) { $c2 += $v; $dayRows[] = ['d' => $d, 'pnl' => round($v, 2), 'cum' => round($c2, 2)]; }  // 组装逐日盈亏与累计曲线
    return [$trOut, $dayRows];                                    // 返回明细与逐日数据
}
[$trAo, $dayA] = build($trA, 10000);                              // 构建方案A明细(1万U)
[$trBo, $dayB] = build($trB, 42000);                              // 构建方案B明细(4.2万U)
function summ($trades) {                                          // 汇总出场类型统计
    $w = 0; $lq = 0; $to = 0; $n = count($trades);                // 止盈/爆仓/超时计数与总笔数
    foreach ($trades as $t) { if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'LIQ') $lq++; elseif ($t['out'] == 'TO') $to++; }  // 按出场类型分类计数
    return ['n' => $n, 'win' => $w, 'liq' => $lq, 'to' => $to, 'win_r' => $n ? round(100 * $w / $n, 1) : 0];  // 返回笔数分布与胜率%
}
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400;        // 回测数据跨度天数
$out = [                                                          // 组装最终输出JSON
    'params' => ['entry' => '六重共振', 'tp' => '价格+2%', 'addon' => 'fib618加仓5轮(与本金同额)', 'span' => round($spanDays) . '天',  // 策略参数说明
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000)), 'dir' => '只买涨'],  // 数据区间与方向
    'A' => ['lev' => 100, 'margin' => 620, 'balance' => 10000, 'sl' => '无(爆仓线-0.6%兜底)'] + $sA + summ($trA) + ['daily' => $dayA, 'trades' => $trAo],  // 方案A: 参数+安全统计+出场统计+明细
    'B' => ['lev' => 10, 'margin' => 5440, 'balance' => 42000, 'sl' => '-3%(相对均价)'] + $sB + summ($trB) + ['daily' => $dayB, 'trades' => $trBo],  // 方案B: 同上
];
file_put_contents('E:/finally-main/web/eth_100k_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出JSON数据文件供前端页展示
echo "JSON OK A_trades=" . count($trAo) . " B_trades=" . count($trBo) . "\n";  // 完成提示
