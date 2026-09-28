<?php
/**
 * eth_top10.php — 排行榜前10盈利合约 × 保证金1U起步每天+1U 阶梯 回测(CLI)
 * 策略 = A方案同参: 六重共振全命中买入 / fib618仅上行加仓≤5轮(每轮=开仓保证金) / 下跌不减仓
 *         止盈价格+2% / 50%兜底强平(浮亏达该笔总保证金(含加仓)×50%市价全平)
 * 保证金: M = min(1U + 1U×已过天数, 500/6)
 * 前10合约来源: eth_allmarket_data.json 排行(1U+3U/天 档 top10)
 * 输出: web/eth_top10_data.json
 */
ini_set('memory_limit', '2048M'); // 调高内存上限到 2G
set_time_limit(0); // 取消执行时间限制
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 杠杆100x/止盈2%/维持保证金率0.4%/maker费0.02%/taker费0.05%/资金费0.01%每8h
$BAL0 = 500.0; $CAP = $BAL0 / 6.0; $BASE = 1.0; $STEP = 1.0; // 起始500U / 保证金封顶500/6 / 阶梯基数1U / 每日步进1U

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接本地 MariaDB trading 库
$DB = db(); // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; } // 执行 SQL 收集全部行
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; } // 滑动窗口简单均线 MA(n)
function fibsig($c, $w = 30) { // fib618信号: 收盘贴近30根窗口0.618回撤位(±3%)
    $n = count($c); $out = array_fill(0, $n, false); // K线数与结果数组
    for ($i = $w - 1; $i < $n; $i++) { // 从第30根开始判定
        $lo = INF; $hi = -INF; // 窗口高低点
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; } // 扫窗口
        if ($hi <= $lo) continue; // 区间无效跳过
        $fib = $hi - ($hi - $lo) * 0.618; // 0.618回撤位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true; // 收盘贴fib位命中
    }
    return $out; // 返回信号序列
}
function load_k($inst, $bar) { // 加载指定合约/周期K线
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC"); // 按时间升序取列
    $ts = []; $o = []; $h = []; $l = []; $c = []; // 初始化数组
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; } // 逐行转类型
    return [$ts, $o, $h, $l, $c]; // 返回五元组
}
function buy_ratio_series($o, $h, $l, $c) { // 方向买比代理: 收盘相对振幅位置归一到[0,1]
    $n = count($c); $br = []; // K线数与结果
    for ($i = 0; $i < $n; $i++) { // 逐根
        $rng = $h[$i] - $l[$i]; // 振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0; // 收盘相对位置[-1,1]
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1; // 钳制
        $br[$i] = 0.5 + $dir / 2; // 映射到[0,1]
    }
    return $br; // 返回买比序列
}

// 前10合约
$prev = json_decode(file_get_contents('E:/finally-main/web/eth_allmarket_data.json'), true); // 读取全市场回测结果
$top10 = array_slice(array_column($prev['rank']['1U+3U/天']['top'], 'inst'), 0, 10); // 取1U+3U/天档盈利排行前10
$top10 = array_map('strtolower', $top10); // 转小写(与表名一致)
echo "top10=" . implode(',', $top10) . "\n"; flush(); // 输出前10合约

// ① 宽度
$up = []; $tot = []; // 上涨家数/统计家数
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time"); // 全市场日K收盘
$cur = null; $win = []; $s = 0.0; // 当前合约/滑动窗口/窗口和
foreach ($rr as $x) { // 逐行扫描
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; } // 换合约重置
    $win[] = (float)$x[2]; $s += (float)$x[2]; // 收盘入窗
    if (count($win) > 20) $s -= array_shift($win); // 超20移除最旧
    if (count($win) == 20) { // 满20可算MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2]; // 时间/MA20/收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0); // 上涨家数
        $tot[$t] = ($tot[$t] ?? 0) + 1; // 统计家数
    }
}
$brSrc = []; // 宽度序列
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k; // 样本>10才算占比
$sorted = array_keys($brSrc); sort($sorted); // 日期升序
function ffillBr($t) { // 前向填充: 取≤t最近宽度
    global $brSrc, $sorted; // 全局序列
    $lo = 0; $hi = count($sorted) - 1; $res = null; // 二分初始化
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; } // 二分
    return $res !== null ? $brSrc[$res] : null; // 返回宽度或null
}
// ⑤ 熔断
[$tsB, , , , $cB] = load_k('btc', '1h'); // BTC 1h收盘(熔断判定)
$meltSet = []; // 熔断标记集合
for ($i = 4; $i < count($cB); $i++) { // 从第5根起需向前看4根
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) { // 4小时累计跌≤-3%为熔断
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true; // 熔断后4小时均禁买
    }
}

function sim($o, $h, $l, $c, $ts, $fib, $entry) { // 单合约回测: A方案同参
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP, $BASE, $STEP; // 全局参数
    $liqDrop = 1.0 / $LEV - $MMR; // 爆仓跌幅≈0.6%
    $startT = $ts[30]; // 阶梯起算点
    $trades = []; $n = count($ts); $i = 30; // 交易数组/K线数/起始下标
    while ($i < $n - 1) { // 主循环找入场
        if (empty($entry[$ts[$i]])) { $i++; continue; } // 无信号前进
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400); // 已过天数
        $M = round(min($BASE + $STEP * $days, $CAP), 2); // 保证金=1U+1U×天数, 封顶500/6
        if ($M < 1) $M = 1.0; // 下限1U
        $M0 = $M; // 开仓时保证金(加仓同额)
        $e = $o[$i + 1]; // 下一根开盘价入场
        $notional = $M0 * $LEV; $avg = $e; $adds = 0; // 名义仓位/均价/加仓轮数
        $fee = $notional * $FEE_MAKER; // 开仓maker费
        $funding = 0.0; // 资金费累计
        $liqPx = $avg * (1 - $liqDrop); // 爆仓价
        $tgtPx = $avg * (1 + $TPR); // 止盈价
        $stopPx = $avg * (1 - 0.5 / $LEV); // 50%兜底强平价(亏0.5%价格)
        $outcome = null; $exitPx = null; $j = $i + 1; // 出场状态初始化
        while ($j < $n) { // 持仓逐根推进
            $barsHeld = $j - $i; // 持仓根数
            $funding += $notional * $FUND8H / 8; // 每根计提资金费(1h=8h的1/8)
            $hitCut = $l[$j] <= $stopPx; // 触兜底强平线?
            $hitLiq = $l[$j] <= $liqPx; // 触爆仓线?
            $hitTp  = $h[$j] >= $tgtPx; // 触止盈线?
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; } // 兜底强平出场
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 爆仓出场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; } // 止盈出场
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; } // 超时7天平仓
            if ($adds < 5 && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) { // 加仓: ≤5轮+fib618+仅上行
                $ap = $o[$j + 1]; // 加仓价=下一根开盘
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV); // 加权更新均价
                $notional += $M0 * $LEV; $adds++; // 仓位与轮数更新
                $fee += $M0 * $LEV * $FEE_TAKER; // 加仓taker费
                $liqPx = $avg * (1 - $liqDrop); // 重算爆仓价
                $tgtPx = $avg * (1 + $TPR); // 重算止盈价
                $stopPx = $avg * (1 - 0.5 / $LEV); // 重算兜底强平价
            }
            $j++; // 推进
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; } // 数据尽头兜底
        $gross = ($exitPx - $avg) * $notional / $avg; // 毛盈亏
        $pnl = $gross - $fee - $funding; // 净盈亏
        $mg = $M0 * (1 + $adds); // 总保证金
        if ($pnl < -$mg) $pnl = -$mg; // 亏损封底=保证金
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds, // 记录交易
                     'margin' => $mg, 'pnl' => round($pnl, 3)]; // 保证金与盈亏
        $i = $j + 1; // 出场后继续扫描
    }
    return $trades; // 返回逐笔明细
}

$perContract = []; $daily = []; $allTrades = []; // 每合约汇总/每日盈亏/全部明细
foreach ($top10 as $inst) { // 逐前10合约回测
    [$ts1h, $o1h, $h1h, $l1h, $c1h] = load_k($inst, '1h'); // 加载1h主数据
    $n = count($ts1h); // K线数
    if ($n < 200) continue; // 数据太短跳过
    [$ts4h, , , , $c4h] = load_k($inst, '4h'); // 加载4h收盘
    $ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10); // 1h MA20/MA10
    $ma5_4h = ma($c4h, 5); // 4h MA5
    $tr4hUpF = []; $p4 = 0; $cur4 = null; $n4 = count($ts4h); // 4h趋势填充初始化
    foreach ($ts1h as $tt) { // 前向填充4h趋势到1h网格
        while ($p4 < $n4 && $ts4h[$p4] <= $tt) { if ($ma5_4h[$p4] !== null) $cur4 = $c4h[$p4] > $ma5_4h[$p4]; $p4++; } // 推进游标更新趋势
        $tr4hUpF[$tt] = $cur4; // 填充
    }
    $br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h); // 1h买比代理
    $fib = fibsig($c1h); // fib618加仓信号
    $entryA = []; // 六重共振入场标记
    for ($i = 30; $i < $n; $i++) { // 逐根判定六重共振
        $t = $ts1h[$i]; // 当前时间
        if (!empty($meltSet[$t])) continue; // 条件⑤: BTC熔断时段禁买
        $b = ffillBr($t); // 条件①: 市场宽度
        if ($b === null || $b <= 0.5) continue; // 宽度≤50%不满足
        if ($ma20[$i] === null || $c1h[$i] <= $ma20[$i]) continue; // 条件②: 收盘>MA20
        if ($ma10[$i] === null || $c1h[$i] <= $ma10[$i]) continue; // 条件③: 收盘>MA10
        if (empty($tr4hUpF[$t])) continue; // 条件④: 4h趋势向上
        if ($br1h[$i] < 0.5) continue; // 条件⑥: 1h买比≥0.5
        $entryA[$t] = true; // 六重共振全命中
    }
    $tr = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA); // 跑该合约回测
    $pl = 0.0; $w = 0; $cut = 0; $adds = 0; $mg = 0.0; // 该合约统计量
    foreach ($tr as $x) { // 逐笔统计
        $pl += $x['pnl']; $mg += $x['margin']; $adds += $x['adds']; // 盈亏/保证金/加仓累加
        if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'CUT') $cut++; // 止盈/强平计数
        $d = date('Y-m-d', (int)($x['tout'] / 1000)); // 出场日期
        $daily[$d] = ($daily[$d] ?? 0) + $x['pnl']; // 按日聚合盈亏
    }
    $cnt = count($tr); // 该合约笔数
    $perContract[] = ['inst' => strtoupper($inst), 'n' => $cnt, 'win' => $w, 'cut' => $cut, // 每合约汇总行
                      'wr' => $cnt ? round(100 * $w / $cnt, 1) : 0, 'adds' => $adds, // 胜率与加仓轮数
                      'avg_margin' => $cnt ? round($mg / $cnt, 2) : 0, 'pnl' => round($pl, 2), // 平均保证金与总盈亏
                      'ev' => $cnt ? round($pl / $cnt, 2) : 0]; // 每笔期望
    foreach ($tr as $x) { $x['inst'] = strtoupper($inst); $allTrades[] = $x; } // 明细附合约名归档
    echo "$inst done n=$cnt pnl=" . round($pl, 2) . "\n"; flush(); // 输出进度
    unset($ts1h, $o1h, $h1h, $l1h, $c1h, $ts4h, $c4h, $ma20, $ma10, $ma5_4h, $tr4hUpF, $br1h, $fib, $entryA); // 及时释放内存
}

// 日收益序列 + 权益曲线
$days = array_keys($daily); sort($days); // 日期升序
$eq = $BAL0; $peak = $BAL0; $maxDD = 0.0; $dailyArr = []; // 权益/峰值/最大回撤/每日行
foreach ($days as $d) { // 逐日结转
    $eq += $daily[$d]; if ($eq > $peak) $peak = $eq; if ($peak - $eq > $maxDD) $maxDD = $peak - $eq; // 更新权益峰值与回撤
    $dailyArr[] = ['d' => $d, 'pnl' => round($daily[$d], 2), 'eq' => round($eq, 2)]; // 每日行
}
// 月度汇总
$monthly = []; $monthDays = []; // 月度盈亏/有交易天数
foreach ($dailyArr as $r) { // 逐日归月
    $m = substr($r['d'], 0, 7); // 年月
    $monthly[$m] = ($monthly[$m] ?? 0) + $r['pnl']; // 月盈亏累加
    $monthDays[$m] = ($monthDays[$m] ?? 0) + 1; // 月天数累加
}
$monthlyArr = []; // 月度行
foreach ($monthly as $m => $v) $monthlyArr[] = ['m' => $m, 'days' => $monthDays[$m], 'pnl' => round($v, 2), // 月/天数/盈亏
    'avg' => round($v / max(1, $monthDays[$m]), 2)]; // 月日均
usort($monthlyArr, function ($a, $b) { return strcmp($a['m'], $b['m']); }); // 按月排序

$total = array_sum(array_column($perContract, 'pnl')); // 全部合约总盈亏
$nAll = array_sum(array_column($perContract, 'n')); // 总笔数
$out = [ // 组装输出
    'params' => ['lev' => 100, 'bal0' => $BAL0, 'margin' => '每笔=1U+1U×已过天数, 封顶500/6; 加仓每轮=开仓时保证金', // 参数: 杠杆/起始/保证金规则
        'backstop' => '50%兜底强平: 浮亏达到该笔总保证金(含加仓)×50% → 立即市价全平', // 兜底强平说明
        'top10' => array_map('strtoupper', $top10), 'note' => '各合约独立持仓(同合约同时1仓), 收益按日聚合'], // 前10名单与说明
    'perContract' => $perContract, // 每合约汇总
    'total' => ['pnl' => round($total, 2), 'n' => $nAll, // 总盈亏与总笔数
        'span_days' => count($dailyArr), 'avg_daily' => round($total / max(1, count($dailyArr)), 2), // 交易日数与日均
        'max_dd' => round($maxDD, 2), 'final_eq' => round($eq, 2)], // 最大回撤与期末权益
    'monthly' => $monthlyArr, 'daily' => $dailyArr, // 月度与每日明细
];
file_put_contents('E:/finally-main/web/eth_top10_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入前端数据JSON
echo "JSON OK total=" . round($total, 2) . " days=" . count($dailyArr) . " avg/day=" . round($total / max(1, count($dailyArr)), 2) . " maxDD=" . round($maxDD, 2) . "\n"; // 完成提示
