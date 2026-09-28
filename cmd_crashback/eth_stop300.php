<?php
/**
 * eth_stop300.php — ETH模式 动态保证金阶梯 + 浮亏止损X档 一年对比(CLI)
 * 配置(与实盘 ethmode.go 1:1):
 *   六重共振入场(宽度>50% + 1h>MA20/MA10 + 4h>MA5 + taker买比>=50%) 只买涨
 *   保证金 = 1U + 3U×已过天数, 封顶 余额/6 (每笔开仓时计算)
 *   100x 逐仓, TP 价格+2%(ROI+200%), 不挂条件单
 *   加仓 = 15m fib_618 信号 + 仅上行(现价>均价), 最多5轮, 每轮加仓 = 开仓时保证金
 *   止损对比: 浮亏达到 X U(整仓含加仓) → 市价全平, X = 0(不设)/100/200/300/400/500
 * 输出: web/eth_stop300_data.json
 */
ini_set('memory_limit', '1024M'); // 调高内存上限到 1G
set_time_limit(0); // 取消执行时间限制
$BALANCE = 500.0; // 起始余额 500U
$LEV = 100; $TPR = 0.02; $MAXADD = 5; // 杠杆100x / 止盈+2% / 最多加仓5轮
$MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 维持保证金率0.4% / maker费0.02% / taker费0.05% / 资金费0.01%每8h
$LADDER_BASE = 1.0; $LADDER_STEP = 3.0; // 保证金阶梯: 基数1U, 每过一天+3U

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接本地 MariaDB trading 库
$DB = db(); // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; } // 执行 SQL 收集全部行
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; } // 滑动窗口简单均线 MA(n)
function fibsig($c, $w = 30) { // fib618 信号: 收盘贴近30根窗口0.618回撤位(±3%)
    $n = count($c); $out = array_fill(0, $n, false); // K线数与结果数组
    for ($i = $w - 1; $i < $n; $i++) { // 从第30根开始判定
        $lo = INF; $hi = -INF; // 窗口最低/最高
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; } // 扫窗口求高低点
        if ($hi <= $lo) continue; // 区间无效跳过
        $fib = $hi - ($hi - $lo) * 0.618; // 0.618回撤位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true; // 收盘在 fib位±3% 内命中
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
        $br[$i] = 0.5 + $dir / 2; // 映射到[0,1], 中性0.5
    }
    return $br; // 返回买比序列
}

// sim: 动态保证金阶梯 + 每轮加仓=开仓时保证金 + 浮亏止损 cut(U)
function sim($o, $h, $l, $c, $ts, $fib, $entry, $br, $cut) { // 核心回测: cut=浮亏止损金额(0=不设)
    global $LEV, $TPR, $MAXADD, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $LADDER_BASE, $LADDER_STEP; // 全局参数
    $liqDrop = 1.0 / $LEV - $MMR; // 爆仓跌幅 ≈ 0.6%
    $bal = 500.0;                       // 余额(复利承接)
    $startT = $ts[30];                  // 阶梯起算 = 第一笔信号
    $trades = []; $n = count($ts); $i = 30; // 交易数组 / K线数 / 起始下标
    while ($i < $n - 1) { // 主循环找入场
        if (empty($entry[$ts[$i]])) { $i++; continue; } // 无入场信号前进
        $s = 0; $cnt = 0; // taker买比均值计算
        for ($k = $i - 11; $k <= $i; $k++) if ($k >= 0) { $s += $br[$k]; $cnt++; } // 取前12根买比
        if ($cnt == 0 || $s / $cnt < 0.5) { $i++; continue; } // 买比均值<0.5放弃入场
        // ===== 保证金阶梯(开仓时) =====
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400); // 距第一笔信号的已过天数
        $M = round(min($LADDER_BASE + $LADDER_STEP * $days, $bal / 6.0), 2); // 保证金 = 1U+3U×天数, 封顶余额/6
        if ($M < 1) $M = 1.0; // 保证金下限1U
        $M0 = $M;                        // 开仓时保证金(每轮加仓都用它)
        $e = $o[$i + 1]; // 下一根开盘价入场
        $notional = $M0 * $LEV; $avg = $e; $adds = 0; // 名义仓位/均价/加仓轮数
        $fee = $notional * $FEE_MAKER; // 开仓 maker 手续费
        $liqPx = $avg * (1 - $liqDrop); // 爆仓价
        $tgtPx = $avg * (1 + $TPR); // 止盈价
        $stopPx = ($cut > 0) ? $avg * (1 - $cut / $notional) : 0; // 止损价: 由浮亏cut U反推价格跌幅(0=不设止损)
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0; // 出场状态初始化
        while ($j < $n) { // 持仓逐根推进
            $barsHeld = $j - $i; // 持仓根数
            $hitLiq = $l[$j] <= $liqPx; // 触爆仓线?
            $hitCut = ($cut > 0) && ($l[$j] <= $stopPx); // 触浮亏止损线?
            $hitTp  = $h[$j] >= $tgtPx; // 触止盈线?
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 爆仓出场
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; } // 止损出场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; } // 止盈出场
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; } // 持仓超7天超时平仓
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) { // 加仓: 未达上限+fib618信号+仅上行
                $ap = $o[$j + 1]; // 加仓价=下一根开盘
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV); // 加权更新均价(每轮加仓额=开仓保证金M0)
                $notional += $M0 * $LEV; $adds++; // 仓位与轮数更新
                $fee += $M0 * $LEV * $FEE_TAKER; // 加仓 taker 手续费
                $liqPx = $avg * (1 - $liqDrop); // 重算爆仓价
                $tgtPx = $avg * (1 + $TPR); // 重算止盈价
                if ($cut > 0) $stopPx = $avg * (1 - $cut / $notional);   // 加仓后均价上移, 止损线重算
            }
            $j++; // 推进
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; } // 数据尽头兜底按超时
        $gross = ($exitPx - $avg) * $notional / $avg; // 毛盈亏
        $funding = $notional * $FUND8H * ($barsHeld / 8); // 资金费(1h网格: 8根=8h)
        $pnl = $gross - $fee - $funding; // 净盈亏
        $mg = $M0 * (1 + $adds); // 总保证金 = 开仓保证金×(1+加仓轮数)
        if ($pnl < -$mg) $pnl = -$mg;    // 逐仓: 最多亏掉全部保证金
        $bal = round($bal + $pnl, 2); // 余额复利结转
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds, // 记录交易
                     'margin' => $mg, 'pnl' => round($pnl, 3), 'bal' => $bal]; // 保证金/盈亏/余额
        if ($bal <= 0) break;            // 账户打穿
        $i = $j + 1; // 出场后继续扫描
    }
    return [$trades, $bal]; // 返回逐笔明细与期末余额
}
function summ($trades) { // 汇总统计
    $w = 0; $cut = 0; $lq = 0; $to = 0; $pl = 0.0; $gw = 0.0; $gl = 0.0; $maxDD = 0.0; $peak = 0.0; $cum = 0.0; // 各类计数器
    foreach ($trades as $t) { // 逐笔统计
        if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'CUT') $cut++; // 止盈/止损计数
        elseif ($t['out'] == 'LIQ') $lq++; else $to++; // 爆仓/超时计数
        $pl += $t['pnl']; $cum += $t['pnl']; // 总盈亏与累计曲线
        if ($cum > $peak) $peak = $cum; // 更新峰值
        if ($peak - $cum > $maxDD) $maxDD = $peak - $cum; // 更新最大回撤
        if ($t['pnl'] >= 0) $gw += $t['pnl']; else $gl += $t['pnl']; // 分离毛盈利/毛亏损
    }
    $n = count($trades); // 笔数
    return ['n' => $n, 'win' => $w, 'cut' => $cut, 'liq' => $lq, 'to' => $to, // 各类型笔数
            'win_r' => $n ? round(100 * $w / $n, 1) : 0, 'total' => round($pl, 2), // 胜率(%)与总盈亏
            'gross_win' => round($gw, 2), 'gross_lose' => round($gl, 2), // 毛盈利/毛亏损
            'max_dd' => round($maxDD, 2), 'final_bal' => round(500 + $pl, 2), // 最大回撤与期末余额
            'ev' => $n ? round($pl / $n, 3) : 0]; // 每笔期望
}

echo "== load ==\n"; flush(); // 加载阶段提示
[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h'); // ETH 1h 主数据
[$ts4h, , , , $c4h, ] = load_k('eth', '4h'); // ETH 4h 收盘
$n = count($ts1h); // 1h K线数

echo "== breadth ==\n"; flush(); // 宽度阶段提示
$up = []; $tot = []; // 上涨家数/统计家数
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time"); // 全市场日K收盘
$cur = null; $win = []; $s = 0.0; // 当前合约/滑动窗口/窗口和
foreach ($rr as $x) { // 逐行扫描
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; } // 换合约重置窗口
    $win[] = (float)$x[2]; $s += (float)$x[2]; // 收盘入窗
    if (count($win) > 20) $s -= array_shift($win); // 超20移除最旧
    if (count($win) == 20) { // 满20可算MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2]; // 时间/MA20/收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0); // 收盘≥MA20计入上涨
        $tot[$t] = ($tot[$t] ?? 0) + 1; // 统计家数+1
    }
}
$brSrc = []; // 宽度序列
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k; // 样本>10才算占比
$sorted = array_keys($brSrc); sort($sorted); // 日期升序供二分
function ffillBr($t) { // 前向填充: 取≤t最近的市场宽度
    global $brSrc, $sorted; // 全局序列
    $lo = 0; $hi = count($sorted) - 1; $res = null; // 二分初始化
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; } // 二分找最近日期
    return $res !== null ? $brSrc[$res] : null; // 返回宽度或null
}
echo "== features ==\n"; flush(); // 特征阶段提示
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10); // 1h MA20/MA10
$ma5_4h = ma($c4h, 5); // 4h MA5
$tr4hUp = []; // 4h趋势源
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hUp[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i]; // 4h收盘>MA5=趋势向上
$tr4hUpF = []; // 填充后的4h趋势
$p4 = 0; $cur4 = null; $n4 = count($ts4h); // 游标/最近值/4h长度
foreach ($ts1h as $tt) { while ($p4 < $n4 && $ts4h[$p4] <= $tt) { $cur4 = $tr4hUp[$ts4h[$p4]] ?? $cur4; $p4++; } $tr4hUpF[$tt] = $cur4; } // 前向填充4h趋势到1h网格
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h, $v1h); // 1h买比代理
$fib = fibsig($c1h); // 1h fib618加仓信号
$entryA = []; // 入场标记
for ($i = 30; $i < $n; $i++) { // 逐根生成六重共振信号
    $t = $ts1h[$i]; // 当前时间
    $b = ffillBr($t); // 市场宽度
    if ($b !== null && $b > 0.5 && $ma20[$i] !== null && $c1h[$i] > $ma20[$i] && // 条件: 宽度>50%+收盘>MA20
        $ma10[$i] !== null && $c1h[$i] > $ma10[$i] && !empty($tr4hUpF[$t])) $entryA[$t] = true; // +收盘>MA10+4h趋势向上
}
echo "entryA=" . count($entryA) . "\n"; flush(); // 输出信号数

// ===== 止损档对比(0=不设止损[现行] / 100 / 200 / 300 / 400 / 500) =====
$cuts = [0, 100, 200, 300, 400, 500]; // 六档浮亏止损金额
$results = []; $allTrades = []; // 汇总结果与各档明细
foreach ($cuts as $cut) { // 逐档回测
    [$tr, $fin] = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryA, $br1h, $cut); // 跑该档模拟
    $sm = summ($tr); // 汇总统计
    $results[] = ['cut' => $cut] + $sm; // 结果行=止损档+统计
    $allTrades[$cut] = $tr; // 保存明细
    echo "cut={$cut}U: " . json_encode($sm, JSON_UNESCAPED_UNICODE) . "\n"; flush(); // 输出进度
}
usort($results, fn($a, $b) => $b['total'] <=> $a['total']); // 按总盈亏降序排列

// 300U 与 0 的逐笔明细(对比)
function detailOut($tr) { // 明细转前端格式
    $cum = 0; $out = []; // 累计与结果
    foreach ($tr as $t) { // 逐笔
        $cum += $t['pnl']; // 累计结转
        $out[] = ['tin' => date('Y-m-d H:i', (int)($t['tin'] / 1000)), // 入场时间
                  'tout' => date('Y-m-d H:i', (int)($t['tout'] / 1000)), // 出场时间
                  'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], // 出场类型/加仓轮数/保证金
                  'pnl' => $t['pnl'], 'bal' => $t['bal'], 'cum' => round($cum, 2)]; // 盈亏/余额/累计
    }
    return $out; // 返回明细
}
$d0 = detailOut($allTrades[0]); // 不设止损版逐笔明细
$d300 = detailOut($allTrades[300]); // 300U止损版逐笔明细
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400; // 回测跨度天数

// 月度(300U版)
$mon = []; // 月度容器
foreach ($allTrades[300] as $t) { $k = date('Y-m', (int)($t['tout'] / 1000)); $mon[$k] = round(($mon[$k] ?? 0) + $t['pnl'], 2); } // 300U版按月累加盈亏

$out = [ // 组装输出
    'params' => ['lev' => 100, 'balance' => 500, 'ladder' => '1U+3U×已过天数, 封顶余额/6', // 参数: 杠杆/余额/阶梯说明
                 'add' => 'fib618+仅上行, ≤5轮, 每轮=开仓时保证金', 'tp' => '价格+2%(ROI+200%)', // 加仓与止盈说明
                 'rule' => '浮亏达到X U(整仓含加仓) → 市价全平; X=0 即现行不设止损', // 止损规则说明
                 'span' => round($spanDays) . '天', // 跨度
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000)), // 数据起止
                 'dir' => '只买涨'], // 方向
    'results' => $results, // 各档汇总
    'trades_0' => $d0, 'trades_300' => $d300, 'monthly_300' => $mon, // 0档/300档明细与300档月度
];
file_put_contents('E:/finally-main/web/eth_stop300_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入前端数据JSON
echo "JSON OK t0=" . count($d0) . " t300=" . count($d300) . "\n"; // 完成提示
