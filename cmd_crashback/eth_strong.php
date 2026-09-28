<?php
/**
 * eth_strong.php — 每天找强势币 + 现行策略 一年回测(CLI)
 * 选币: 每天00:00(UTC)按 1h K线 24h 涨幅排名, 取涨幅>0 的前 TOP N 个强势币, 当天只交易这些币
 * 策略(与现行A方案同参): 买入=fib618回调信号+上涨结构(窗口30内高点在低点之后);
 *   加仓=fib618+现价>均价≤5轮每轮=开仓保证金; 下跌不减仓; 止盈=价格+2%自均价;
 *   50%兜底强平=浮亏达该笔总保证金(含加仓)×50%市价全平(=价格-0.5%/100x);
 *   保证金=每品种1U+1U×已过天数, 封顶500/6; 同合约同时1仓; 100x逐仓; maker进taker加仓。
 * 输出: web/eth_strong_data.json  (TOP10 / TOP5 / TOP20 三档对比)
 */
ini_set('memory_limit', '2048M'); // 调高内存上限到 2G(全市场合约多)
set_time_limit(0); // 取消执行时间限制
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 杠杆100x/止盈2%/维持保证金率0.4%/maker费0.02%/taker费0.05%/资金费0.01%每8h
$BAL0 = 500.0; $CAP = $BAL0 / 6.0; $BASE = 1.0; $STEP = 1.0; // 起始500U / 单笔保证金封顶500/6 / 阶梯基数1U / 每日步进1U

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接本地 MariaDB trading 库
$DB = db(); // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; } // 执行 SQL 收集全部行
function fibsigUp($c, $w = 30) { // fib618买入信号(带上涨结构): 收盘贴0.618回撤位 且 窗口高点出现在低点之后
    $n = count($c); $out = array_fill(0, $n, false); // K线数与结果数组
    for ($i = $w - 1; $i < $n; $i++) { // 从第30根开始判定
        $lo = INF; $hi = -INF; $loIdx = 0; $hiIdx = 0; // 窗口高低点及其下标
        for ($j = $i - $w + 1; $j <= $i; $j++) { // 扫描窗口
            if ($c[$j] < $lo) { $lo = $c[$j]; $loIdx = $j; } // 更新最低点及位置
            if ($c[$j] > $hi) { $hi = $c[$j]; $hiIdx = $j; } // 更新最高点及位置
        }
        if ($hi <= $lo || $hiIdx <= $loIdx) continue; // 区间无效或高点不在低点后(非上涨结构)跳过
        $fib = $hi - ($hi - $lo) * 0.618; // 0.618回撤位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true; // 收盘在 fib位±3% 内命中买入信号
    }
    return $out; // 返回信号序列
}
function fibsig($c, $w = 30) { // fib618加仓信号(不带结构要求)
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
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC"); // 按时间升序取 o/h/l/c
    $ts = []; $o = []; $h = []; $l = []; $c = []; // 初始化数组
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; } // 逐行转类型
    return [$ts, $o, $h, $l, $c]; // 返回五元组
}

function sim($o, $h, $l, $c, $ts, $fibE, $fA, $allowDays, $inst, $barHours) { // 单合约回测: 依榜单白名单过滤入场
    // $allowDays: day => [inst => true] 只允许这些天这些币开仓
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP, $BASE, $STEP; // 全局参数
    $liqDrop = 1.0 / $LEV - $MMR; // 爆仓跌幅 ≈ 0.6%
    $startT = $ts[30]; // 阶梯起算点
    $maxHold = (int)(7 * 24 / $barHours); // 最大持仓根数(7天折算)
    $trades = []; $n = count($ts); $i = 30; // 交易数组/K线数/起始下标
    while ($i < $n - 1) { // 主循环找入场
        if (empty($fibE[$ts[$i]])) { $i++; continue; } // 无fib618买入信号前进
        $day = gmdate('Y-m-d', (int)($ts[$i] / 1000)); // 当前UTC日期
        if (empty($allowDays[$day][$inst])) { $i++; continue; } // 该币不在当日TOP榜单白名单则跳过
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400); // 已过天数
        $M = round(min($BASE + $STEP * $days, $CAP), 2); // 保证金 = 1U+1U×天数, 封顶500/6
        if ($M < 1) $M = 1.0; // 下限1U
        $M0 = $M; // 开仓时保证金(加仓同额)
        $e = $o[$i + 1]; // 下一根开盘价入场
        $notional = $M0 * $LEV; $avg = $e; $adds = 0; // 名义仓位/均价/加仓轮数
        $fee = $notional * $FEE_MAKER; // 开仓 maker 手续费
        $funding = 0.0; // 资金费累计
        $liqPx = $avg * (1 - $liqDrop); // 爆仓价
        $tgtPx = $avg * (1 + $TPR); // 止盈价
        $stopPx = $avg * (1 - 0.5 / $LEV); // 50%兜底强平: 亏0.5%价格 = 保证金×50%
        $outcome = null; $exitPx = null; $j = $i + 1; // 出场状态初始化
        while ($j < $n) { // 持仓逐根推进
            $barsHeld = $j - $i; // 持仓根数
            $funding += $notional * $FUND8H / 8 * $barHours; // 每根计提资金费(按周期折算)
            $hitCut = $l[$j] <= $stopPx; // 触50%兜底强平线?
            $hitLiq = $l[$j] <= $liqPx; // 触爆仓线?
            $hitTp  = $h[$j] >= $tgtPx; // 触止盈线?
            if ($hitCut) { $outcome = 'CUT50'; $exitPx = $stopPx; break; } // 兜底强平出场
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 爆仓出场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; } // 止盈出场
            if ($barsHeld >= $maxHold) { $outcome = 'TO'; $exitPx = $c[$j]; break; } // 超时平仓
            if ($adds < 5 && $j + 1 < $n && !empty($fA[$ts[$j]]) && $c[$j] > $avg) { // 加仓: ≤5轮+fib618信号+仅上行
                $ap = $o[$j + 1]; // 加仓价=下一根开盘
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV); // 加权更新均价
                $notional += $M0 * $LEV; $adds++; // 仓位与轮数更新
                $fee += $M0 * $LEV * $FEE_TAKER; // 加仓 taker 手续费
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

function aggregate($trades, $tag, &$store) { // 汇总统计并按标签存入结果容器
    $n = 0; $w = 0; $cut = 0; $liq = 0; $adds = 0; $pl = 0.0; // 各类计数器
    $daily = []; $monthly = []; // 每日/每月盈亏
    foreach ($trades as $x) { // 逐笔统计
        $n++; $pl += $x['pnl']; $adds += $x['adds']; // 笔数/总盈亏/加仓轮数
        if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'CUT50') $cut++; if ($x['out'] == 'LIQ') $liq++; // 分类计数
        $d = date('Y-m-d', (int)($x['tout'] / 1000)); // 出场日期
        $daily[$d] = ($daily[$d] ?? 0) + $x['pnl']; // 按日累加
        $m = substr($d, 0, 7); // 出场年月
        $monthly[$m] = ($monthly[$m] ?? 0) + $x['pnl']; // 按月累加
    }
    $days = array_keys($daily); sort($days); // 日期升序
    $eq = 500.0; $peak = 500.0; $maxDD = 0.0; $dailyArr = []; // 权益曲线/峰值/最大回撤/每日行
    foreach ($days as $d) { $eq += $daily[$d]; if ($eq > $peak) $peak = $eq; if ($peak - $eq > $maxDD) $maxDD = $peak - $eq; $dailyArr[] = ['d' => $d, 'pnl' => round($daily[$d], 2), 'eq' => round($eq, 2)]; } // 逐日结转权益并算回撤
    $monthlyArr = []; // 月度行
    foreach ($monthly as $m => $v) { $md = 0; foreach ($dailyArr as $r) if (substr($r['d'], 0, 7) === $m) $md++; $monthlyArr[] = ['m' => $m, 'pnl' => round($v, 2), 'days' => $md, 'avg' => $md ? round($v / $md, 2) : 0]; } // 每月统计有交易天数并算日均
    arsort($daily); // 每日盈亏降序
    $topDays = []; // 最佳交易日
    foreach (array_slice($daily, 0, 8, true) as $d => $v) $topDays[] = ['d' => $d, 'pnl' => round($v, 2)]; // 取前8大盈利日
    $store[$tag] = ['n' => $n, 'win' => $w, 'cut50' => $cut, 'liq' => $liq, 'adds' => $adds, // 汇总: 各类计数
        'total' => round($pl, 2), 'max_dd' => round($maxDD, 2), // 总盈亏与最大回撤
        'days' => count($dailyArr), 'avg_daily' => count($dailyArr) ? round($pl / count($dailyArr), 2) : 0, // 交易日数与日均
        'ev' => $n ? round($pl / $n, 3) : 0, // 每笔期望
        'range' => $n ? (date('Y-m-d', (int)($trades[0]['tin'] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($trades)['tout'] / 1000))) : '', // 交易起止日期
        'top_days' => $topDays, // 最佳交易日
        'monthly' => $monthlyArr, 'daily' => $dailyArr]; // 月度与每日明细
}

// ===== 合约清单 =====
$tbls = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline_%_usdt_swap_1h' ORDER BY table_name"); // 找出所有1h K线表
$insts = []; // 合约列表
foreach ($tbls as $x) { if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $x[0], $m)) $insts[] = $m[1]; } // 从表名提取合约名
echo "insts=" . count($insts) . "\n"; flush(); // 输出合约数

// ===== Pass1: 每天强势币排名 — 用 kline1d 日线(469币全年), mom = 日涨幅>0 =====
$dayMom = []; // 每天 momentum(涨幅)映射: 日期 => 合约 => 涨幅
$dr = rows("SELECT inst_id, candle_time, `c` FROM kline1d ORDER BY inst_id, candle_time ASC"); // 全市场日线收盘
$prevC = []; $prevInst = ''; // 前一日收盘缓存/当前合约
foreach ($dr as $x) { // 逐行扫描
    $iid = strtolower(str_replace('-USDT-SWAP', '', $x[0])); // 'ETH-USDT-SWAP' -> 'eth' (与1h表名一致)
    $t = (int)$x[1]; $cv = (float)$x[2]; // 时间戳与收盘
    if ($iid !== $prevInst) { $prevC[$iid] = null; $prevInst = $iid; } // 换合约时前收盘置空
    $d = gmdate('Y-m-d', (int)($t / 1000)); // UTC日期
    if ($prevC[$iid] !== null && $prevC[$iid] > 0) { // 有前一日收盘才能算涨幅
        $mom = $cv / $prevC[$iid] - 1; // 日涨幅
        if ($mom > 0) $dayMom[$d][$iid] = $mom; // 涨幅>0才进当日候选
    }
    $prevC[$iid] = $cv; // 更新前一日收盘
}
// 每天 Top N 名单
$topSets = ['10' => [], '5' => [], '20' => []]; // 三档白名单: TOP10/TOP5/TOP20
foreach ($dayMom as $d => $arr) { // 逐日生成榜单
    arsort($arr); // 按涨幅降序
    $keys = array_keys($arr); // 合约名序列
    foreach (['10' => 10, '5' => 5, '20' => 20] as $n => $lim) { // 三档限额
        foreach (array_slice($keys, 0, $lim) as $inst) $topSets[$n][$d][$inst] = true; // 取前N进白名单
    }
}
echo "days=" . count($dayMom) . " top10days=" . count($topSets['10']) . " top5days=" . count($topSets['5']) . " top20days=" . count($topSets['20']) . "\n"; flush(); // 输出各档覆盖天数
// 诊断: 抽样3天看候选数与三档集合大小
$si = 0; // 抽样计数器
foreach ($dayMom as $d => $arr) { // 逐日
    if ($si++ % 30 != 0) continue; // 每30天抽1天
    $cand = count($arr); // 当日候选数
    $s5 = isset($topSets['5'][$d]) ? count($topSets['5'][$d]) : 0; // TOP5集合大小
    $s10 = isset($topSets['10'][$d]) ? count($topSets['10'][$d]) : 0; // TOP10集合大小
    $s20 = isset($topSets['20'][$d]) ? count($topSets['20'][$d]) : 0; // TOP20集合大小
    echo "diag $d cand=$cand top5=$s5 top10=$s10 top20=$s20\n"; flush(); // 输出诊断
}

// ===== Pass2: 逐合约回测(三个榜单共用信号, 只差 allowDays) =====
$fibCache = []; // (预留)信号缓存
$tradesBy = ['10' => [], '5' => [], '20' => []]; // 三档交易明细
foreach ($insts as $k => $inst) { // 逐合约
    [$ts, $o, $h, $l, $c] = load_k($inst, '1h'); // 加载该合约1h数据
    if (count($ts) < 300) continue; // 数据不足300根跳过
    $fibE = fibsigUp($c); $fibA = fibsig($c); // 买入信号(带结构)与加仓信号
    $fE = []; foreach ($ts as $i2 => $t) if ($fibE[$i2]) $fE[$t] = true; // 买入信号转时间戳映射
    $fA = []; foreach ($ts as $i2 => $t) if ($fibA[$i2]) $fA[$t] = true; // 加仓信号转时间戳映射
    foreach (['10', '5', '20'] as $n) { // 三档榜单各跑一遍
        $tr = sim($o, $h, $l, $c, $ts, $fE, $fA, $topSets[$n], $inst, 1.0); // 回测(1h周期)
        foreach ($tr as $x) { $x['inst'] = $inst; $tradesBy[$n][] = $x; } // 记录合约名并归档
    }
    if (($k + 1) % 80 == 0) { echo "P2 " . ($k + 1) . "/" . count($insts) . "\n"; flush(); } // 每80合约报进度
    unset($ts, $o, $h, $l, $c, $fibE, $fibA, $fE, $fA); // 及时释放内存
}

$store = []; // 汇总结果容器
foreach (['10' => 'top10', '5' => 'top5', '20' => 'top20'] as $n => $tag) { // 三档逐一汇总
    aggregate($tradesBy[$n], $tag, $store); // 统计
    echo "$tag: n={$store[$tag]['n']} total={$store[$tag]['total']} dd={$store[$tag]['max_dd']} ev={$store[$tag]['ev']}\n"; flush(); // 输出结果
}

// 合约排行(TOP10档)
$per = []; $tmp = []; // 排行结果/临时聚合
foreach ($tradesBy['10'] as $x) { $k2 = strtoupper($x['inst']); $tmp[$k2]['pnl'] = ($tmp[$k2]['pnl'] ?? 0) + $x['pnl']; $tmp[$k2]['n'] = ($tmp[$k2]['n'] ?? 0) + 1; } // 按合约聚合盈亏与笔数
foreach ($tmp as $k2 => $v) $per[] = ['inst' => $k2, 'n' => $v['n'], 'pnl' => round($v['pnl'], 2)]; // 转排行行
usort($per, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; }); // 按盈亏降序

$out = [ // 组装输出
    'params' => ['strategy' => '每天(UTC)按前一日日线涨幅(kline1d, 469币全年)选强势币(涨幅>0), 当天只交易TopN; 买入=fib618回调信号+上涨结构(窗口30内高点在低点之后, 1h K线); 加仓=fib618+现价>均价≤5轮(每轮=开仓保证金); 下跌不减仓; 止盈=价格+2%自均价; 50%兜底强平=浮亏达该笔总保证金(含加仓)×50%市价全平; 100x逐仓', // 策略说明
        'margin' => '每品种每笔=1U+1U×已过天数, 封顶500/6', 'bal0' => $BAL0, 'lev' => 100, // 保证金规则/起始资金/杠杆
        'note' => '1h K线完整一年 2025-09-25 ~ 2026-09-25, 476合约'], // 数据说明
    'top10' => $store['top10'], 'top5' => $store['top5'], 'top20' => $store['top20'], // 三档汇总
    'rank_top10' => ['top' => array_slice($per, 0, 12), 'bottom' => array_slice(array_reverse($per), 0, 12)], // TOP10档合约盈亏前12与后12
];
file_put_contents('E:/finally-main/web/eth_strong_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入前端数据JSON
echo "JSON OK\n"; // 完成提示
