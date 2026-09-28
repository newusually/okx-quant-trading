<?php
/**
 * eth_sweep.php — 一年参数矩阵扫描(CLI): 找更高利润/更多交易/胜率20%即可的配置
 * 维度: 亏X美金强平(0=无,10,30,50,100,150,200,250,300) × 止盈价格(0.5%,1%,1.5%,2%,3%)
 *       × fib618加仓(开/关) × 入场(六重共振/仅多周期/多周期+多策略)
 * 固定: 10x逐仓 500U/笔 只做多 SL-3%(相对均价) 资金费/手续费全算
 * 输出: web/eth_sweep_data.json
 */
ini_set('memory_limit', '1024M'); // 调高内存上限到 1G
set_time_limit(0); // 取消执行时间限制
$PRINCIPAL = 5000.0; $MARGIN = 500.0; $ADD = 500.0; $MAXADD = 5; // 本金5000U / 单笔保证金500U / 每轮加仓500U / 最多5轮
$LEV = 10; $SLP = 0.03; // 杠杆10x / 固定止损-3%(相对均价)
$MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 维持保证金率0.4%/maker费0.02%/taker费0.05%/资金费0.01%每8h

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
function load_k($inst, $bar) { // 加载指定合约/周期K线(含vol)
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC"); // 按时间升序取列
    $ts = []; $o = []; $h = []; $l = []; $c = []; $v = []; // 初始化数组
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; } // 逐行转类型
    return [$ts, $o, $h, $l, $c, $v]; // 返回六元组
}
function buy_ratio_series($o, $h, $l, $c, $v) { // 方向买比代理: 收盘相对振幅位置归一到[0,1]
    $n = count($c); $br = []; // K线数与结果
    for ($i = 0; $i < $n; $i++) { // 逐根
        $rng = $h[$i] - $l[$i]; // 振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0; // 收盘相对位置[-1,1]
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1; // 钳制
        $br[$i] = 0.5 + $dir / 2; // 映射到[0,1]
    }
    return $br; // 返回买比序列
}
/**
 * sim — 只做多
 * $entry: [ts=>true]; $takerTh: null=不过滤; $lossCut: 0=无美金强平, >0=浮亏达X美金立即平
 * $tpR: 止盈价格涨幅; $addon: fib618加仓开关
 */
function sim($o, $h, $l, $c, $ts, $fib, $entry, $br, $takerTh, $lossCut, $tpR, $addon) { // 核心回测: 矩阵参数化的模拟器
    global $MARGIN, $ADD, $MAXADD, $LEV, $SLP, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H; // 全局参数
    $liqDrop = 1.0 / $LEV - $MMR; // 爆仓跌幅 = 1/杠杆 - 维持保证金率
    $trades = []; $n = count($ts); $i = 30; // 交易数组/K线数/起始下标
    while ($i < $n - 1) { // 主循环找入场
        if (empty($entry[$ts[$i]])) { $i++; continue; } // 无入场信号前进
        if ($takerTh !== null) { // 启用 taker 买比过滤?
            $s = 0; $cnt = 0; // 和与样本数
            for ($k = $i - 11; $k <= $i; $k++) if ($k >= 0) { $s += $br[$k]; $cnt++; } // 前12根买比均值
            if ($cnt == 0 || $s / $cnt < $takerTh) { $i++; continue; } // 低于阈值放弃入场
        }
        $e = $o[$i + 1]; // 下一根开盘价入场
        $notional = $MARGIN * $LEV; $avg = $e; $adds = 0; // 名义仓位/均价/加仓轮数
        $fee = $notional * $FEE_MAKER; // 开仓 maker 手续费
        $liqPx = $avg * (1 - $liqDrop); // 爆仓价
        $slPx = $avg * (1 - $SLP); // 固定止损价(-3%)
        $tgtPx = $avg * (1 + $tpR); // 止盈价(参数化)
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0; // 出场状态初始化
        while ($j < $n) { // 持仓逐根推进
            $barsHeld = $j - $i; // 持仓根数
            $stopPx = ($lossCut > 0) ? $avg * (1 - $lossCut / $notional) : 0; // 亏X美金强平价(0=不设)
            $hitLiq = $l[$j] <= $liqPx; // 触爆仓线?
            $hitCut = ($lossCut > 0) && ($l[$j] <= $stopPx); // 触美金强平线?
            $hitSl  = $l[$j] <= $slPx; // 触固定止损线?
            $hitTp  = $h[$j] >= $tgtPx; // 触止盈线?
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 爆仓出场
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; }   // 亏X美金强平
            if ($hitSl)  { $outcome = 'SL';  $exitPx = $slPx;  break; } // 固定止损出场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; } // 止盈出场
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; } // 超时7天平仓
            if ($addon && $adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) { // fib618加仓(可开关): 未达上限+仅上行
                $ap = $o[$j + 1]; // 加仓价=下一根开盘
                $avg = ($avg * $notional + $ap * $ADD * $LEV) / ($notional + $ADD * $LEV); // 加权更新均价
                $notional += $ADD * $LEV; $adds++; // 仓位与轮数更新
                $fee += $ADD * $LEV * $FEE_TAKER; // 加仓 taker 手续费
                $liqPx = $avg * (1 - $liqDrop); // 重算爆仓价
                $slPx = $avg * (1 - $SLP); // 重算止损价
                $tgtPx = $avg * (1 + $tpR); // 重算止盈价
            }
            $j++; // 推进
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; } // 数据尽头兜底
        $gross = ($exitPx - $avg) * $notional / $avg; // 毛盈亏
        $funding = $notional * $FUND8H * ($barsHeld / 8); // 资金费
        $pnl = $gross - $fee - $funding; // 净盈亏
        $mg = $MARGIN + $adds * $ADD; // 总保证金
        if ($pnl < -$mg) $pnl = -$mg; // 亏损封底=保证金
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds, // 记录交易
                     'margin' => $mg, 'pnl' => round($pnl, 2)]; // 保证金与盈亏
        $i = $j + 1; // 出场后继续扫描
    }
    return $trades; // 返回逐笔明细
}
function summ($trades) { // 汇总统计
    $w = 0; $cut = 0; $sl = 0; $lq = 0; $to = 0; $pl = 0.0; // 各类计数器
    foreach ($trades as $t) { // 逐笔
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'CUT') $cut++; // 止盈/美金强平计数
        elseif ($t['out'] == 'SL') $sl++; elseif ($t['out'] == 'LIQ') $lq++; else $to++; // 止损/爆仓/超时计数
        $pl += $t['pnl']; // 总盈亏累加
    }
    $n = count($trades); // 笔数
    return ['n' => $n, 'win' => $w, 'cut' => $cut, 'sl' => $sl, 'liq' => $lq, 'to' => $to, // 各类型笔数
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2), // 胜率与总盈亏
            'ev' => $n ? round($pl / $n, 2) : 0]; // 每笔期望
}

echo "== load data ==\n"; flush(); // 加载阶段提示
[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h'); // ETH 1h 主数据
[$ts4h, , , , $c4h, ] = load_k('eth', '4h'); // ETH 4h 收盘
$n = count($ts1h); // 1h K线数
echo "1h bars=$n (" . date('Y-m-d', (int)($ts1h[0] / 1000)) . " ~ " . date('Y-m-d', (int)(end($ts1h) / 1000)) . ")\n"; flush(); // 输出数据规模与跨度

echo "== breadth ==\n"; flush(); // 宽度阶段提示
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

echo "== features ==\n"; flush(); // 特征阶段提示
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10); // 1h MA20/MA10
$ma5_4h = ma($c4h, 5); // 4h MA5
$tr4hUp = []; // 4h趋势源
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hUp[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i]; // 4h收盘>MA5=趋势向上
$tr4hUpF = []; // 填充后的4h趋势
$p4 = 0; $cur4 = null; $n4 = count($ts4h); // 游标/最近值/4h长度
foreach ($ts1h as $tt) { while ($p4 < $n4 && $ts4h[$p4] <= $tt) { $cur4 = $tr4hUp[$ts4h[$p4]] ?? $cur4; $p4++; } $tr4hUpF[$tt] = $cur4; } // 前向填充到1h网格
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h, $v1h); // 1h买比代理
$fib = fibsig($c1h); // 1h fib618信号

// 三种入场口径
$entryA = []; $entryB = []; $entryC = [];   // A六重共振 B仅多周期 C多周期+多策略(fib618并入)
for ($i = 30; $i < $n; $i++) { // 逐根生成三种信号
    $t = $ts1h[$i]; // 当前时间
    $b = ffillBr($t); // 市场宽度
    $u20 = ($ma20[$i] !== null && $c1h[$i] > $ma20[$i]); // 收盘>1h MA20
    $u10 = ($ma10[$i] !== null && $c1h[$i] > $ma10[$i]); // 收盘>1h MA10
    $tr4 = !empty($tr4hUpF[$t]); // 4h趋势向上
    // A 六重共振: 宽度>0.5 + 1h MA10/MA20多头 + 4h趋势多 + 非熔断
    if ($b !== null && $b > 0.5 && $u20 && $u10 && $tr4) $entryA[$t] = true; // A口径命中
    // B 仅多周期: 1h MA10/MA20 多头 + 4h趋势多 (无宽度/无taker)
    if ($u20 && $u10 && $tr4) $entryB[$t] = true; // B口径命中
    // C 多周期+多策略: B 或 fib618信号(非熔断时)
    if (isset($entryB[$t]) || (!empty($fib[$i]) && $u20)) $entryC[$t] = true; // C口径=B∪fib618
}
echo "entryA=" . count($entryA) . " B=" . count($entryB) . " C=" . count($entryC) . "\n"; flush(); // 输出三种信号数

// ===== 矩阵扫描 =====
$cuts = [0, 10, 30, 50, 100, 150, 200, 250, 300]; // 亏X美金强平档位
$tps  = [0.005, 0.01, 0.015, 0.02, 0.03]; // 止盈涨幅档位
$ents = ['A' => [$entryA, 0.5, '六重共振(宽度+趋势+taker)'], // 入场口径A: 六重共振+taker过滤0.5
         'B' => [$entryB, null, '仅多周期(1h双均线+4h趋势)'], // 口径B: 无taker过滤
         'C' => [$entryC, null, '多周期+多策略(多周期∪fib618)']]; // 口径C
$results = []; // 结果容器
$bestTrades = null; $bestKey = null; // 最优配置明细与键
foreach ($ents as $ek => [$emap, $tth, $ename]) { // 逐入场口径
    foreach ($tps as $tpR) { // 逐止盈档
        foreach ([1, 0] as $adOn) { // 加仓开/关
            foreach ($cuts as $cut) { // 逐强平档
                $tr = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $emap, $br1h, $tth, $cut, $tpR, $adOn); // 跑该组合
                $sm = summ($tr); // 汇总
                $key = "{$ek}|tp{$tpR}|ad{$adOn}|cut{$cut}"; // 组合键
                $results[$key] = ['ent' => $ek, 'entName' => $ename, 'tp' => round($tpR * 100, 1), // 记录组合参数
                                  'addon' => $adOn, 'cut' => $cut] + $sm; // 加上统计
                if ($bestKey === null || $sm['total'] > $results[$bestKey]['total']) { $bestKey = $key; $bestTrades = $tr; } // 更新最优组合
            }
        }
        echo "done ent=$ek tp=" . ($tpR * 100) . "% (" . count($results) . " 组合)\n"; flush(); // 输出进度
    }
}
uasort($results, fn($a, $b) => $b['total'] <=> $a['total']); // 按总盈亏降序
$results = array_values($results); // 重排索引
echo "BEST: " . json_encode($results[0]) . "\n"; flush(); // 输出最优组合

// 最优配置的明细(每日+逐笔)
$cum = 0; $trOut = []; $daily = []; $no = 0; // 累计/逐笔行/每日/序号
foreach ($bestTrades as $t) { // 逐笔组装最优明细
    $no++; $cum += $t['pnl']; // 序号与累计
    $d = date('Y-m-d', (int)($t['tout'] / 1000)); // 出场日期
    $daily[$d] = ($daily[$d] ?? 0) + $t['pnl']; // 按日累加
    $trOut[] = ['no' => $no, // 笔序号
        'tin' => date('Y-m-d H:i', (int)($t['tin'] / 1000)), 'tout' => date('Y-m-d H:i', (int)($t['tout'] / 1000)), // 入出场时间
        'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'pnl' => $t['pnl'], // 出场类型/加仓/保证金/盈亏
        'pnl_r' => round(100 * $t['pnl'] / $t['margin'], 1), 'cum' => round($cum, 2)]; // 保证金收益率与累计
}
ksort($daily); // 每日按时间排序
$dayRows = []; $c2 = 0; // 每日行/累计
foreach ($daily as $d => $v) { $c2 += $v; $dayRows[] = ['d' => $d, 'pnl' => round($v, 2), 'cum' => round($c2, 2)]; } // 逐日累计

$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400; // 回测跨度天数
$out = [ // 组装输出
    'params' => ['lev' => 10, 'margin' => 500, 'sl' => '±3%(相对均价)', 'span' => round($spanDays) . '天', // 固定参数
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000)), // 数据起止
                 'dir' => '只做多(空头已证负期望)'], // 方向
    'best' => ['key' => $bestKey] + $results[0], // 最优组合
    'results' => $results, // 全部组合结果
    'best_daily' => $dayRows, // 最优组合每日盈亏
    'best_trades' => $trOut, // 最优组合逐笔明细
];
file_put_contents('E:/finally-main/web/eth_sweep_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入前端数据JSON
echo "JSON OK combos=" . count($results) . " bestTrades=" . count($trOut) . " days=" . count($dayRows) . "\n"; // 完成提示
