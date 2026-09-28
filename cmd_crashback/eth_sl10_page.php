<?php
/**
 * eth_sl10_page.php — 生成「10x SL-3% TP+0.5% + taker」三个月全明细数据 (CLI, 纯PHP)
 * 输出: E:/finally-main/web/eth_sl10_data.json
 * 本金假设: 5000U(单笔最大保证金500+5轮加仓=3000U内, 留余量); 10x逐仓; 初始保证金率10%
 */
ini_set('memory_limit', '1024M'); // 调高内存上限到 1G
set_time_limit(0); // 取消执行时间限制
$PRINCIPAL = 5000.0; // 本金 5000U
$MARGIN = 500.0; $ADD = 500.0; $MAXADD = 5; // 单笔初始保证金500U / 每轮加仓500U / 最多加仓5轮
$LEV = 10; $TPR = 0.005; $SLP = 0.03; // 杠杆10x / 止盈+0.5% / 止损-3%(相对均价)
$TAKER_TH = 0.5; // taker买比过滤阈值: 前12根买比均值须>0.5
$MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 维持保证金率0.4% / maker费0.02% / taker费0.05% / 资金费0.01%每8h

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接本地 MariaDB trading 库
$DB = db(); // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; } // 执行 SQL 收集全部行为索引数组
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; } // 滑动窗口简单均线 MA(n), 不足窗口返回 null
function fibsig($c, $w = 30) { // fib618 信号: 收盘价贴近30根K线区间的0.618回撤位(±3%)时为真
    $n = count($c); $out = array_fill(0, $n, false); // K线数与结果数组(默认false)
    for ($i = $w - 1; $i < $n; $i++) { // 从第30根开始逐根判定
        $lo = INF; $hi = -INF; // 窗口内最低/最高价
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; } // 扫描30根窗口求高低点
        if ($hi <= $lo) continue; // 区间无效则跳过
        $fib = $hi - ($hi - $lo) * 0.618; // 计算 0.618 回撤位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true; // 收盘在 fib位±3% 内则命中加仓信号
    }
    return $out; // 返回信号序列
}
function breadth($gridTs) { // 全市场宽度: 各1h表合约收盘≥MA20的占比, 按网格时间戳对齐
    global $DB; // 使用全局数据库连接
    $idx = array_flip($gridTs); $up = []; $tot = []; // 网格时间戳索引 / 上涨家数 / 统计家数
    $tabs = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline%_1h'"); // 找出所有 *_1h K线表
    foreach ($tabs as $t) { // 逐表(逐合约)扫描
        $r = $DB->query("SELECT candle_time, `c` FROM `{$t[0]}` ORDER BY candle_time ASC"); // 取该合约1h时间与收盘
        if (!$r) continue; // 查询失败则跳过该表
        $win = []; $s = 0.0; // 滑动窗口与累计和
        while ($x = $r->fetch_row()) { // 逐行处理
            $tt = (int)$x[0]; $cc = (float)$x[1]; // 时间戳与收盘价
            if (!isset($idx[$tt])) { $win = []; $s = 0.0; continue; } // 不在回测网格上的时间戳(数据空洞)则重置窗口
            $win[] = $cc; $s += $cc; // 收盘入窗累加
            if (count($win) > 20) $s -= array_shift($win); // 超20移除最旧
            if (count($win) == 20) { // 满20可算MA20
                $ma = $s / 20; // MA20值
                $up[$tt] = ($up[$tt] ?? 0) + ($cc >= $ma ? 1 : 0); // 收盘≥MA20 计入上涨
                $tot[$tt] = ($tot[$tt] ?? 0) + 1; // 该时刻可统计合约数+1
            }
        }
        $r->free(); // 释放结果集
    }
    $b = []; // 宽度结果
    foreach ($gridTs as $tt) $b[$tt] = (($tot[$tt] ?? 0) > 10) ? ($up[$tt] ?? 0) / $tot[$tt] : null; // 样本>10才计算占比否则null
    return $b; // 返回宽度序列
}
function ffill_map($gridTs, $srcTs, $srcVal) { // 前向填充: 把源序列(时间戳=>值)对齐到网格, 取≤网格时间的最近值
    $out = []; $p = 0; $cur = null; $n = count($srcTs); // 结果 / 源游标 / 最近值 / 源长度
    foreach ($gridTs as $tt) { // 遍历网格时间
        while ($p < $n && $srcTs[$p] <= $tt) { $cur = $srcVal[$srcTs[$p]] ?? $cur; $p++; } // 推进源游标更新最近值
        $out[$tt] = $cur; // 填充当前值
    }
    return $out; // 返回对齐后的序列
}
function load_k($inst, $bar) { // 加载指定合约/周期 K线(含成交量)
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC"); // 按时间升序取 o/h/l/c/vol
    $ts = []; $o = []; $h = []; $l = []; $c = []; $v = []; // 初始化六个数组
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; } // 逐行转类型
    return [$ts, $o, $h, $l, $c, $v]; // 返回六元组
}
function buy_ratio_series($o, $h, $l, $c, $v) { // 方向买比代理: 收盘相对振幅位置归一到[0,1]
    $n = count($c); $br = []; // K线数与结果
    for ($i = 0; $i < $n; $i++) { // 逐根计算
        $rng = $h[$i] - $l[$i]; // 振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0; // 收盘在振幅中的位置[-1,1]
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1; // 钳制范围
        $br[$i] = 0.5 + $dir / 2; // 映射到[0,1], 中性0.5
    }
    return $br; // 返回买比序列
}
function sim($o, $h, $l, $c, $ts, $fib, $entry, $br, $takerTh, $margin, $lev, $tpr, $slp) { // 核心回测: 逐笔模拟(含止损/fib加仓/taker过滤)
    global $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $ADD, $MAXADD; // 全局参数
    $liqDrop = 1.0 / $lev - $MMR; // 爆仓跌幅 = 1/杠杆 - 维持保证金率
    $trades = []; $n = count($ts); $i = 30; // 交易数组 / K线数 / 起始下标
    while ($i < $n - 1) { // 主循环找入场
        if (empty($entry[$ts[$i]])) { $i++; continue; } // 无入场信号则前进
        if ($takerTh !== null) { // 启用 taker买量过滤?
            $s = 0; $cnt = 0; // 买比和与样本数
            for ($k = $i - 11; $k <= $i; $k++) if ($k >= 0) { $s += $br[$k]; $cnt++; } // 取前12根(含当前)买比求均值
            if ($cnt == 0 || $s / $cnt < $takerTh) { $i++; continue; } // 均值低于阈值则放弃本次入场
        }
        $e = $o[$i + 1]; // 以下一根开盘价入场
        $notional = $margin * $lev; $avg = $e; $adds = 0; // 名义仓位 / 持仓均价 / 加仓轮数
        $fee = $notional * $FEE_MAKER; // 开仓 maker 手续费
        $liqPx = $avg * (1 - $liqDrop); // 爆仓价
        $slPx = $slp !== null ? $avg * (1 - $slp) : null; // 止损价(null=不止损)
        $tgtPx = $avg * (1 + $tpr); // 止盈价
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0; // 出场结果/出场价/推进游标/持仓根数
        while ($j < $n) { // 持仓期逐根推进
            $barsHeld = $j - $i; // 已持仓根数
            if ($l[$j] <= $liqPx) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 最低价触爆仓线→爆仓
            if ($slPx !== null && $l[$j] <= $slPx) { $outcome = 'SL'; $exitPx = $slPx; break; } // 触止损线→止损
            if ($h[$j] >= $tgtPx) { $outcome = 'WIN'; $exitPx = $tgtPx; break; } // 最高价触止盈线→止盈
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; } // 持仓超7天(1h根数)→超时平仓
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) { // 加仓条件: 未达上限+fib618信号+现价高于均价(仅上行)
                $ap = $o[$j + 1]; // 加仓价=下一根开盘价
                $avg = ($avg * $notional + $ap * $ADD * $lev) / ($notional + $ADD * $lev); // 加权更新均价
                $notional += $ADD * $lev; $adds++; // 名义仓位与轮数更新
                $fee += $ADD * $lev * $FEE_TAKER; // 加仓 taker 手续费
                $liqPx = $avg * (1 - $liqDrop); // 重算爆仓价
                $slPx = $slp !== null ? $avg * (1 - $slp) : null; // 重算止损价
                $tgtPx = $avg * (1 + $tpr); // 重算止盈价
            }
            $j++; // 推进
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; } // 数据尽头兜底按超时
        $gross = ($exitPx - $avg) * $notional / $avg; // 毛盈亏
        $funding = $notional * $FUND8H * ($barsHeld / 8); // 资金费 = 名义×费率×(持仓小时/8h)
        $pnl = $gross - $fee - $funding; // 净盈亏
        $mg = $margin + $adds * $ADD; // 总保证金
        if ($pnl < -$mg) $pnl = -$mg; // 亏损封底=保证金
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds, // 记录交易明细
                     'margin' => $mg, 'pnl' => round($pnl, 2), 'epx' => round($e, 2), 'xpx' => round($exitPx, 2), // 保证金/盈亏/入场价/出场价
                     'avg' => round($avg, 2), 'notional' => round($notional, 0), 'liqpx' => round($liqPx, 2), 'bars' => $barsHeld]; // 均价/名义/爆仓价/持仓根数
        $i = $j + 1; // 单仓位: 出场后继续扫描
    }
    return $trades; // 返回逐笔明细
}

echo "== load ==\n"; flush(); // 加载阶段提示
[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h'); // ETH 1h 主数据
[$ts4h, , , , $c4h, ] = load_k('eth', '4h'); // ETH 4h 收盘
[, , , , $cbtc1h, ] = load_k('btc', '1h'); // BTC 1h 收盘(熔断用)
echo "== breadth ==\n"; flush(); // 宽度阶段提示
$brMkt = breadth($ts1h); // 计算全市场宽度序列
echo "== features ==\n"; flush(); // 特征阶段提示
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10); // 1h MA20/MA10
$ma5_4h = ma($c4h, 5); // 4h MA5
$tr4hSrc = []; for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hSrc[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i]; // 4h趋势: 收盘>MA5
$tr4h = ffill_map($ts1h, $ts4h, $tr4hSrc); // 4h趋势前向填充到1h网格
$pc = []; for ($i = 0; $i < count($cbtc1h); $i++) $pc[$i] = ($i >= 4 && $cbtc1h[$i - 4] != 0) ? $cbtc1h[$i] / $cbtc1h[$i - 4] - 1 : null; // BTC 4小时累计涨跌幅
$crashSrc = []; for ($i = 0; $i < count($cbtc1h); $i++) if ($pc[$i] !== null) $crashSrc[$ts1h[$i]] = $pc[$i] < -0.03; // 跌幅<-3%标记为熔断
$crash = ffill_map($ts1h, $ts1h, $crashSrc); // 熔断标记对齐到1h网格
$entry = []; $n = count($ts1h); // 入场标记与K线数
for ($i = 0; $i < $n; $i++) { // 逐根生成入场信号
    $t = $ts1h[$i]; $b = $brMkt[$t]; // 当前时间与宽度
    $entry[$t] = ($b !== null && $b > 0.5 && $ma20[$i] !== null && $c1h[$i] > $ma20[$i] // 条件: 全市场宽度>50%
        && $ma10[$i] !== null && $c1h[$i] > $ma10[$i] && !empty($tr4h[$t]) && empty($crash[$t])); // +收盘>MA20>MA10+4h趋势向上+无BTC熔断
}
$fib = fibsig($c1h); // 1h fib618 加仓信号序列
$br = buy_ratio_series($o1h, $h1h, $l1h, $c1h, $v1h); // 1h 买比代理序列
echo "== sim ==\n"; flush(); // 回测阶段提示
$trades = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entry, $br, $TAKER_TH, $MARGIN, $LEV, $TPR, $SLP); // 执行回测
echo "trades=" . count($trades) . "\n"; // 输出交易笔数

// ===== 明细组装: 逐笔(本金/可用资金/保证金率) + 每日/每月 =====
$cum = 0.0; // 累计盈亏
$daily = []; $monthly = []; // 每日/每月盈亏容器
$trOut = []; // 前端逐笔明细
$no = 0; // 笔序号
foreach ($trades as $t) { // 逐笔组装
    $no++; // 序号+1
    $cum += $t['pnl']; // 累计盈亏结转
    $equity = $PRINCIPAL + $cum;                      // 平仓后权益
    $avail = $equity;                                  // 平仓瞬间空仓, 可用=权益
    $d = date('Y-m-d', (int)($t['tout'] / 1000)); // 出场日期
    $m = substr($d, 0, 7); // 出场年月
    $daily[$d] = ($daily[$d] ?? 0) + $t['pnl']; // 按日累加盈亏
    $monthly[$m] = ($monthly[$m] ?? 0) + $t['pnl']; // 按月累加盈亏
    $trOut[] = [ // 组装前端明细行
        'no' => $no, // 笔序号
        'tin' => date('Y-m-d H:i', (int)($t['tin'] / 1000)), // 入场时间
        'epx' => $t['epx'], // 入场价
        'tout' => date('Y-m-d H:i', (int)($t['tout'] / 1000)), // 出场时间
        'xpx' => $t['xpx'], // 出场价
        'out' => $t['out'], 'adds' => $t['adds'], // 出场类型与加仓轮数
        'margin' => $t['margin'], 'notional' => $t['notional'], // 保证金与名义仓位
        'mrate' => round(100 * $t['margin'] / $t['notional'], 1), // 实际保证金率(%)
        'liqpx' => $t['liqpx'], // 爆仓价
        'pnl' => $t['pnl'], // 净盈亏
        'pnl_r' => round(100 * $t['pnl'] / $t['margin'], 1), // 保证金收益率(%)
        'avail' => round($avail, 2), // 平仓后可用资金
        'cum' => round($cum, 2), // 累计盈亏
        'hold' => round($t['bars'] / 24 * 10) / 10,   // 持仓天数(1位小数)
    ];
}
ksort($daily); ksort($monthly); // 每日/每月按时间排序
$dayRows = []; $cum2 = 0; $wd = 0; $ld = 0; // 每日行 / 累计 / 盈利天数 / 亏损天数
foreach ($daily as $d => $v) { // 逐日组装
    $cum2 += $v; // 累计结转
    $cnt = 0; // 当日出场笔数
    foreach ($trades as $t) if (date('Y-m-d', (int)($t['tout'] / 1000)) == $d) $cnt++; // 统计当日出场笔数
    if ($v >= 0) $wd++; else $ld++; // 盈利/亏损天数计数
    $dayRows[] = ['d' => $d, 'n' => $cnt, 'pnl' => round($v, 2), 'cum' => round($cum2, 2)]; // 每日行: 日期/笔数/盈亏/累计
}
$totPnl = round($cum, 2); // 总盈亏
$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400; // 回测跨度天数
// K线数据(近30天) + 买卖标记
$kst = $ts1h[count($ts1h) - 1] - 30 * 86400000; // 近30天起点时间戳
$kline = []; $marks = []; // K线数组与买卖标记
for ($i = 0; $i < $n; $i++) { // 逐根筛选近30天K线
    if ($ts1h[$i] < $kst) continue; // 早于起点跳过
    $kline[] = [$ts1h[$i], $o1h[$i], $h1h[$i], $l1h[$i], $c1h[$i]]; // K线行: 时间+OHLC
}
foreach ($trades as $t) { // 生成买卖标记
    if ($t['tin'] >= $kst) $marks[] = ['t' => $t['tin'], 'k' => 'buy']; // 近30天入场→买点标记
    if ($t['tout'] >= $kst) $marks[] = ['t' => $t['tout'], 'k' => $t['out'], 'pnl' => $t['pnl']]; // 近30天出场→卖点标记(带出场类型与盈亏)
}
$out = [ // 组装输出
    'params' => ['principal' => $PRINCIPAL, 'margin' => $MARGIN, 'add' => $ADD, 'maxadd' => $MAXADD, // 参数: 本金/保证金/加仓额/加仓上限
                 'lev' => $LEV, 'tp' => '价格+0.5%(收益率+5%)', 'sl' => '-3%(相对均价)', 'taker' => '前12根买比均值>0.5', // 杠杆/止盈/止损/taker过滤说明
                 'mrate' => '10%(10x逐仓)', 'mmr' => '0.4%', 'liqdrop' => '-9.6%', 'span' => round($spanDays) . '天', // 保证金率/维持保证金率/爆仓跌幅/跨度
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000))], // 数据起止日期
    'summary' => ['n' => count($trades), 'total' => $totPnl, // 汇总: 笔数/总盈亏
                  'day_avg' => round($totPnl / max(1, $spanDays), 2), // 日均盈亏
                  'win_days' => $wd, 'lose_days' => $ld, // 盈利/亏损天数
                  'final_eq' => round($PRINCIPAL + $cum, 2)], // 期末权益
    'trades' => $trOut, 'daily' => $dayRows, 'monthly' => $monthly, // 逐笔/每日/每月明细
    'kline' => $kline, 'marks' => $marks, // 近30天K线与买卖标记
];
file_put_contents('E:/finally-main/web/eth_sl10_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入前端数据 JSON
echo "JSON OK: trades=" . count($trOut) . " days=" . count($dayRows) . " total=$totPnl\n"; // 完成提示
