<?php
/**
 * eth_tp10.php — ETH 3m/5m/15m 斐波那契+黄金分割买入 100x 一年回测(CLI)
 * 买入: 上涨结构(窗口30内高点在低点之后) + 回调至黄金分割位:
 *   A=仅0.618经典回调;  B=0.382/0.5/0.618 三档任一(±1.5%容差)
 * 加仓: fib618信号+现价>均价 ≤5轮, 每轮=开仓保证金; 下跌不减仓
 * 50%兜底强平: 浮亏达该笔总保证金(含加仓)×50% (=价格-0.5%@100x) → 市价全平
 * 止盈矩阵: tp01=价格+0.1%(100x ROI+10%) / tp2=价格+2%(现行) / tp10=价格+10%
 * 保证金: 每笔=1U+1U×已过天数, 封顶500/6; 同周期同时1仓, 可多次重复进出; 100x逐仓
 * 输出: web/eth_tp10_data.json
 */
ini_set('memory_limit', '2048M'); // 调高内存上限到 2G
set_time_limit(0); // 取消执行时间限制
$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 杠杆100x/维持保证金率0.4%/maker费0.02%/taker费0.05%/资金费0.01%每8h
$CAP = 500.0 / 6.0; $BASE = 1.0; $STEP = 1.0; // 保证金封顶500/6 / 阶梯基数1U / 每日步进1U

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接本地 MariaDB trading 库
$DB = db(); // 建立全局数据库连接
function load_k($bar) { // 加载 ETH 指定周期K线
    $r = $GLOBALS['DB']->query("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_eth_usdt_swap_$bar ORDER BY candle_time ASC"); // 按时间升序取 o/h/l/c
    $ts = []; $o = []; $h = []; $l = []; $c = []; // 初始化数组
    while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; } // 逐行转类型
    return [$ts, $o, $h, $l, $c]; // 返回五元组
}
// 黄金分割回调信号: 返回每根bar命中的档位(0=无, 382/500/618)
function goldenSig($c, $w = 30, $tol = 0.015) { // 收盘贴近窗口黄金分割位(±1.5%)时记录档位
    $n = count($c); $out = []; // K线数与结果
    for ($i = $w - 1; $i < $n; $i++) { // 从第30根开始判定
        $lo = INF; $hi = -INF; $loIdx = 0; $hiIdx = 0; // 窗口高低点及下标
        for ($j = $i - $w + 1; $j <= $i; $j++) { // 扫描窗口
            if ($c[$j] < $lo) { $lo = $c[$j]; $loIdx = $j; } // 更新最低点及位置
            if ($c[$j] > $hi) { $hi = $c[$j]; $hiIdx = $j; } // 更新最高点及位置
        }
        if ($hi <= $lo || $hiIdx <= $loIdx) continue; // 区间无效或非上涨结构(高点须在低点后)跳过
        $rng = $hi - $lo; // 窗口振幅
        foreach ([618 => 0.618, 500 => 0.5, 382 => 0.382] as $lv => $f) { // 三档黄金分割
            $px = $hi - $rng * $f; // 该档回撤价位
            if ($px * (1 - $tol) <= $c[$i] && $c[$i] <= $px * (1 + $tol)) { $out[$i] = $lv; break; } // 收盘在档位±1.5%内命中
        }
    }
    return $out; // 返回档位映射
}
function fibAddSig($c, $w = 30) { // 加仓: 0.618位±3%
    $n = count($c); $out = []; // K线数与结果
    for ($i = $w - 1; $i < $n; $i++) { // 从第30根开始判定
        $lo = INF; $hi = -INF; // 窗口高低点
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; } // 扫窗口
        if ($hi <= $lo) continue; // 区间无效跳过
        $fib = $hi - ($hi - $lo) * 0.618; // 0.618回撤位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true; // 收盘贴fib位命中
    }
    return $out; // 返回信号映射
}

function sim($o, $h, $l, $c, $ts, $entries, $fAdd, $tpRate, $barHours, $startDay) { // 核心回测: 单周期单参数组
    global $LEV, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP, $BASE, $STEP; // 全局参数
    $liqDrop = 1.0 / $LEV - $MMR; // 爆仓跌幅≈0.6%
    $startT = $ts[30]; // 阶梯起算点
    $maxHold = (int)(24 * 7 / $barHours); // 最长7天
    $trades = []; $n = count($ts); $i = 30; // 交易数组/K线数/起始下标
    while ($i < $n - 1) { // 主循环找入场
        if (empty($entries[$i])) { $i++; continue; } // 无买入信号前进
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400); // 已过天数
        $M = round(min($BASE + $STEP * $days, $CAP), 2); // 保证金=1U+1U×天数, 封顶500/6
        if ($M < 1) $M = 1.0; // 下限1U
        $M0 = $M; // 开仓时保证金(加仓同额)
        $e = $o[$i + 1]; // 下一根开盘价入场
        $notional = $M0 * $LEV; $avg = $e; $adds = 0; // 名义仓位/均价/加仓轮数
        $fee = $notional * $FEE_MAKER; // 开仓maker费
        $funding = 0.0; // 资金费累计
        $liqPx = $avg * (1 - $liqDrop); // 爆仓价
        $tgtPx = $avg * (1 + $tpRate); // 止盈价(参数化)
        $stopPx = $avg * (1 - 0.5 / $LEV); // 50%保证金兜底
        $outcome = null; $exitPx = null; $j = $i + 1; // 出场状态初始化
        while ($j < $n) { // 持仓逐根推进
            $barsHeld = $j - $i; // 持仓根数
            $funding += $notional * $FUND8H / 8 * $barHours; // 每根计提资金费
            if ($l[$j] <= $stopPx) { $outcome = 'CUT50'; $exitPx = $stopPx; break; } // 触兜底强平线
            if ($l[$j] <= $liqPx) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 触爆仓线
            if ($h[$j] >= $tgtPx) { $outcome = 'WIN'; $exitPx = $tgtPx; break; } // 触止盈线
            if ($barsHeld >= $maxHold) { $outcome = 'TO'; $exitPx = $c[$j]; break; } // 超时平仓
            if ($adds < 5 && $j + 1 < $n && !empty($fAdd[$j]) && $c[$j] > $avg) { // 加仓: ≤5轮+fib618+仅上行
                $ap = $o[$j + 1]; // 加仓价=下一根开盘
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV); // 加权更新均价
                $notional += $M0 * $LEV; $adds++; // 仓位与轮数更新
                $fee += $M0 * $LEV * $FEE_TAKER; // 加仓taker费
                $liqPx = $avg * (1 - $liqDrop); // 重算爆仓价
                $tgtPx = $avg * (1 + $tpRate); // 重算止盈价
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

function aggregate($trades, &$store, $tag) { // 汇总统计并按标签存入容器
    $n = 0; $w = 0; $cut = 0; $liq = 0; $to = 0; $adds = 0; $pl = 0.0; $holdSum = 0; // 各类计数器
    $daily = []; $monthly = []; // 每日/每月盈亏
    foreach ($trades as $x) { // 逐笔统计
        $n++; $pl += $x['pnl']; $adds += $x['adds']; $holdSum += ($x['tout'] - $x['tin']); // 笔数/盈亏/加仓/持仓时长
        if ($x['out'] == 'WIN') $w++; elseif ($x['out'] == 'CUT50') $cut++; elseif ($x['out'] == 'LIQ') $liq++; elseif ($x['out'] == 'TO') $to++; // 分类计数
        $d = gmdate('Y-m-d', (int)($x['tout'] / 1000)); // 出场日期
        $daily[$d] = ($daily[$d] ?? 0) + $x['pnl']; // 按日累加
        $m = substr($d, 0, 7); // 年月
        $monthly[$m] = ($monthly[$m] ?? 0) + $x['pnl']; // 按月累加
    }
    $days = array_keys($daily); sort($days); // 日期升序
    $eq = 500.0; $peak = 500.0; $maxDD = 0.0; $dailyArr = []; // 权益/峰值/回撤/每日行
    foreach ($days as $d) { $eq += $daily[$d]; if ($eq > $peak) $peak = $eq; if ($peak - $eq > $maxDD) $maxDD = $peak - $eq; $dailyArr[] = ['d' => $d, 'pnl' => round($daily[$d], 2), 'eq' => round($eq, 2)]; } // 逐日结转权益
    $monthlyArr = []; // 月度行
    foreach ($monthly as $m => $v) { $md = 0; foreach ($dailyArr as $r) if (substr($r['d'], 0, 7) === $m) $md++; $monthlyArr[] = ['m' => $m, 'pnl' => round($v, 2), 'days' => $md, 'avg' => $md ? round($v / $md, 2) : 0]; } // 月度统计
    $store[$tag] = ['n' => $n, 'win' => $w, 'cut50' => $cut, 'liq' => $liq, 'to' => $to, 'adds' => $adds, // 汇总: 各类计数
        'total' => round($pl, 2), 'max_dd' => round($maxDD, 2), 'final_eq' => round(500 + $pl, 2), // 总盈亏/回撤/期末权益
        'days' => count($dailyArr), 'avg_daily' => count($dailyArr) ? round($pl / count($dailyArr), 2) : 0, // 交易日数与日均
        'ev' => $n ? round($pl / $n, 3) : 0, // 每笔期望
        'avg_hold_h' => $n ? round($holdSum / $n / 3600000, 1) : 0, // 平均持仓小时
        'winrate' => $n ? round($w / $n * 100, 1) : 0, // 胜率(%)
        'range' => $n ? (gmdate('Y-m-d', (int)($trades[0]['tin'] / 1000)) . ' ~ ' . gmdate('Y-m-d', (int)(end($trades)['tout'] / 1000))) : '', // 交易起止
        'monthly' => $monthlyArr, 'daily' => $dailyArr]; // 月度与每日明细
}

// ===== 数据准备: 加载三周期 + 信号 =====
$DATA = []; // bar => ['ts','o','h','l','c','gA'(618 only),'gB'(382/500/618),'fAdd','barHours']
foreach (['3m' => 0.05, '5m' => 1 / 12, '15m' => 0.25] as $bar => $bh) { // 逐周期(附周期小时数)
    [$ts, $o, $h, $l, $c] = load_k($bar); // 加载该周期K线
    if (count($ts) < 100) { echo "$bar no data\n"; continue; } // 数据不足跳过
    $gAll = goldenSig($c); // 计算全部黄金分割信号
    $gA = []; $gB = []; // A组(仅618)/B组(三档)
    foreach ($gAll as $i => $lv) { if ($lv == 618) $gA[$i] = true; $gB[$i] = true; } // 拆分A/B两组信号
    $fRaw = fibAddSig($c); // fib618加仓信号
    $fAdd = []; // 加仓信号容器
    foreach ($fRaw as $i => $v) $fAdd[$i] = true; // 转布尔映射
    $DATA[$bar] = ['ts' => $ts, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c, 'gA' => $gA, 'gB' => $gB, 'fAdd' => $fAdd, 'bh' => $bh]; // 存入数据容器
    echo "$bar loaded bars=" . count($ts) . " entryA=" . count($gA) . " entryB=" . count($gB) . "\n"; flush(); // 输出加载进度
}

$store = []; // 结果容器
// 18 组合: 3周期 × 2买入法 × 3止盈
foreach ($DATA as $bar => $D) { // 逐周期
    foreach (['A' => 'fib618经典', 'B' => '黄金分割三档'] as $ek => $ename) { // 逐买入法
        foreach (['tp01' => 0.001, 'tp2' => 0.02, 'tp10' => 0.10] as $tpk => $tpRate) { // 逐止盈档
            $tag = "{$bar}_{$ek}_{$tpk}"; // 组合标签
            $tr = sim($D['o'], $D['h'], $D['l'], $D['c'], $D['ts'], $D['g' . $ek], $D['fAdd'], $tpRate, $D['bh'], 0); // 跑该组合
            aggregate($tr, $store, $tag); // 汇总
            echo "$tag: n={$store[$tag]['n']} win%={$store[$tag]['winrate']} total={$store[$tag]['total']} dd={$store[$tag]['max_dd']}\n"; flush(); // 输出进度
        }
    }
}

$out = [ // 组装输出
    'params' => ['strategy' => 'ETH-USDT-SWAP 100x逐仓只买涨; 买入=上涨结构(窗口30内高点在低点之后)+黄金分割回调(±1.5%容差): A=仅0.618, B=0.382/0.5/0.618任一; 加仓=fib618+现价>均价≤5轮每轮=开仓保证金; 下跌不减仓; 50%兜底强平=浮亏达该笔总保证金(含加仓)×50%市价全平(价格-0.5%@100x)', // 策略说明
        'tp' => 'tp01=价格+0.1%(100x ROI+10%) | tp2=价格+2%(ROI+200%) | tp10=价格+10%(ROI+1000%)', // 止盈说明
        'margin' => '每笔=1U+1U×已过天数, 封顶500/6; 最长持仓7天超时平', 'lev' => 100], // 保证金与杠杆
    'results' => $store, // 18组合结果
];
file_put_contents('E:/finally-main/web/eth_tp10_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入前端数据JSON
echo "JSON OK\n"; // 完成提示
