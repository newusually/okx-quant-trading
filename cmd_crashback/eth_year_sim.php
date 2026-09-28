<?php
/**
 * eth_year_sim.php — 最近一年 · 买多+卖空 双向 · 10x/SL-3%/TP+0.5%/taker/fib618加仓 (CLI)
 * 宽度特征=全市场1D(kline1d, 一年)前向填充到1h网格; 模拟网格=ETH 1h(一年)
 * 输出: E:/finally-main/web/eth_year_data.json
 */
ini_set('memory_limit', '1024M'); // 调高内存上限到 1G
set_time_limit(0); // 取消执行时间限制
$PRINCIPAL = 5000.0; $MARGIN = 500.0; $ADD = 500.0; $MAXADD = 5; // 本金5000U / 单笔保证金500U / 每轮加仓500U / 最多5轮
$LEV = 10; $TPR = 0.005; $SLP = 0.03; $TAKER_TH = 0.5; // 杠杆10x / 止盈±0.5% / 止损±3% / taker买比阈值
$STOP50 = 50.0;   // 用户指令: 浮亏超过50U立即平仓(按当前名义仓位, 加仓后自动收紧)
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
function ffill_map($gridTs, $srcTs, $srcVal) { // 前向填充: 源序列对齐到网格时间
    $out = []; $p = 0; $cur = null; $n = count($srcTs); // 结果/游标/最近值/源长度
    foreach ($gridTs as $tt) { // 遍历网格
        while ($p < $n && $srcTs[$p] <= $tt) { $cur = $srcVal[$srcTs[$p]] ?? $cur; $p++; } // 推进游标取最近值
        $out[$tt] = $cur; // 填充
    }
    return $out; // 返回对齐结果
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
// dir=+1 多 / -1 空
function sim($o, $h, $l, $c, $ts, $fib, $entry, $br, $takerTh, $d) { // 核心回测: 支持多空双向(d=方向)
    global $MARGIN, $ADD, $MAXADD, $LEV, $TPR, $SLP, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $STOP50, $DBG; // 全局参数
    $liqDrop = 1.0 / $LEV - $MMR; // 爆仓跌幅 = 1/杠杆 - 维持保证金率
    $trades = []; $n = count($ts); $i = 30; $DBG['entry'] = 0; $DBG['passed'] = 0; // 交易数组/K线数/起始下标/调试计数
    while ($i < $n - 1) { // 主循环找入场
        if (empty($entry[$ts[$i]])) { $i++; continue; } // 无入场信号前进
        $DBG['entry']++; // 信号计数
        if ($takerTh !== null) { // 启用方向买比过滤?
            $s = 0; $cnt = 0; // 和与样本数
            for ($k = $i - 11; $k <= $i; $k++) if ($k >= 0) { $s += ($d > 0 ? $br[$k] : 1 - $br[$k]); $cnt++; } // 多取买比, 空取卖比(1-买比)
            if ($cnt == 0 || $s / $cnt < $takerTh) { $i++; continue; } // 均值低于阈值放弃入场
        }
        $DBG['passed']++; // 通过过滤计数
        $e = $o[$i + 1]; // 下一根开盘价入场
        $notional = $MARGIN * $LEV; $avg = $e; $adds = 0; // 名义仓位/均价/加仓轮数
        $fee = $notional * $FEE_MAKER; // 开仓maker费
        $liqPx = $avg * (1 - $d * $liqDrop); // 爆仓价(按方向)
        $slPx = $avg * (1 - $d * $SLP); // 止损价(按方向)
        $tgtPx = $avg * (1 + $d * $TPR); // 止盈价(按方向)
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0; // 出场状态初始化
        while ($j < $n) { // 持仓逐根推进
            $barsHeld = $j - $i; // 持仓根数
            $hitLiq = ($d > 0) ? ($l[$j] <= $liqPx) : ($h[$j] >= $liqPx); // 触爆仓线(多看低/空看高)
            // 用户铁律: 浮亏>50U 立即平仓(先于SL/TP, 同根K线保守优先)
            $stopPx = $avg * (1 - $d * ($STOP50 / $notional)); // 亏50U对应的反推价格
            $hit50 = ($d > 0) ? ($l[$j] <= $stopPx) : ($h[$j] >= $stopPx); // 触50U强平线?
            $hitSl  = ($d > 0) ? ($l[$j] <= $slPx)  : ($h[$j] >= $slPx); // 触止损线?
            $hitTp  = ($d > 0) ? ($h[$j] >= $tgtPx) : ($l[$j] <= $tgtPx); // 触止盈线?
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 爆仓出场
            if ($hit50)  { $outcome = 'SL50'; $exitPx = $stopPx; break; } // 50U强平出场
            if ($hitSl)  { $outcome = 'SL';  $exitPx = $slPx;  break; } // 止损出场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; } // 止盈出场
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; } // 超时7天平仓
            $addOk = ($d > 0) ? ($c[$j] > $avg) : ($c[$j] < $avg);   // 加仓: 多=上行, 空=下行
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $addOk) { // 加仓条件: 未达上限+fib618+顺势
                $ap = $o[$j + 1]; // 加仓价=下一根开盘
                $avg = ($avg * $notional + $ap * $ADD * $LEV) / ($notional + $ADD * $LEV); // 加权更新均价
                $notional += $ADD * $LEV; $adds++; // 仓位与轮数更新
                $fee += $ADD * $LEV * $FEE_TAKER; // 加仓taker费
                $liqPx = $avg * (1 - $d * $liqDrop); // 重算爆仓价
                $slPx = $avg * (1 - $d * $SLP); // 重算止损价
                $tgtPx = $avg * (1 + $d * $TPR); // 重算止盈价
            }
            $j++; // 推进
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; } // 数据尽头兜底
        $gross = ($exitPx - $avg) * $notional / $avg * $d; // 毛盈亏(乘方向)
        $funding = $notional * $FUND8H * ($barsHeld / 8) * $d;   // 多付空收
        $pnl = $gross - $fee - $funding; // 净盈亏
        $mg = $MARGIN + $adds * $ADD; // 总保证金
        if ($pnl < -$mg) $pnl = -$mg; // 亏损封底=保证金
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds, // 记录交易
                     'margin' => $mg, 'pnl' => round($pnl, 2), 'epx' => round($e, 2), 'xpx' => round($exitPx, 2), // 保证金/盈亏/入场价/出场价
                     'notional' => round($notional, 0), 'liqpx' => round($liqPx, 2), 'bars' => $barsHeld]; // 名义仓位/爆仓价/持仓根数
        $i = $j + 1; // 出场后继续扫描
    }
    return $trades; // 返回逐笔明细
}

echo "== load ETH/BTC 1h(一年) ==\n"; flush(); // 加载阶段提示
[$ts1h, $o1h, $h1h, $l1h, $c1h, $v1h] = load_k('eth', '1h'); // ETH 1h 主数据
[, , , , $cbtc1h, ] = load_k('btc', '1h'); // BTC 1h收盘(熔断/暴涨判定)
[$ts4h, , , , $c4h, ] = load_k('eth', '4h'); // ETH 4h收盘
$n = count($ts1h); // 1h K线数
echo "1h bars=$n (" . date('Y-m-d', (int)($ts1h[0] / 1000)) . " ~ " . date('Y-m-d', (int)(end($ts1h) / 1000)) . ")\n"; flush(); // 输出数据规模

echo "== breadth from kline1d ==\n"; flush(); // 宽度阶段提示
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
$brDays = count($brSrc); // 宽度数据天数
echo "breadth days=$brDays\n"; flush(); // 输出宽度天数

echo "== features ==\n"; flush(); // 特征阶段提示
$ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10); // 1h MA20/MA10
$ma5_4h = ma($c4h, 5); // 4h MA5
$tr4hUp = []; $tr4hDn = []; // 4h趋势向上/向下源
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) { $tr4hUp[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i]; $tr4hDn[$ts4h[$i]] = $c4h[$i] < $ma5_4h[$i]; } // 4h收盘与MA5比较定方向
$tr4hUpF = ffill_map($ts1h, $ts4h, $tr4hUp); // 4h向上趋势填充到1h网格
$tr4hDnF = ffill_map($ts1h, $ts4h, $tr4hDn); // 4h向下趋势填充到1h网格
$pc = []; for ($i = 0; $i < count($cbtc1h); $i++) $pc[$i] = ($i >= 4 && $cbtc1h[$i - 4] != 0) ? $cbtc1h[$i] / $cbtc1h[$i - 4] - 1 : null; // BTC 4小时累计涨跌
$crashSrc = []; $pumpSrc = []; // 熔断/暴涨源标记
for ($i = 0; $i < count($cbtc1h); $i++) { // 逐根判定
    if ($pc[$i] === null) continue; // 无数据跳过
    if ($pc[$i] < -0.03) $crashSrc[$ts1h[$i]] = true; // 跌超3%→熔断
    if ($pc[$i] > 0.03) $pumpSrc[$ts1h[$i]] = true; // 涨超3%→暴涨(禁开空)
}
$crash = ffill_map($ts1h, $ts1h, $crashSrc); // 熔断填充到1h网格
$pump = ffill_map($ts1h, $ts1h, $pumpSrc); // 暴涨填充到1h网格
$br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h, $v1h); // 1h买比代理
$fib = fibsig($c1h); // fib618加仓信号

$entryL = []; $entryS = []; // 多头/空头入场标记
$CN = ['b' => 0, 'u20' => 0, 'u10' => 0, 'tr' => 0, 'nc' => 0, 'all' => 0, 'bnull' => 0, // 多头各关通过计数
       'sb' => 0, 'd20' => 0, 'd10' => 0, 'str' => 0, 'np' => 0, 'sall' => 0]; // 空头各关通过计数
for ($i = 0; $i < $n; $i++) { // 逐根生成双向信号
    $t = $ts1h[$i]; // 当前时间
    $b = ffillBr($brSrc, $t); // 市场宽度
    if ($b === null) { $CN['bnull']++; continue; } // 宽度缺失跳过
    // 多头逐关
    if ($b > 0.5) { $CN['b']++; } else goto SCHK; // 关1: 宽度>50%
    if ($ma20[$i] !== null && $c1h[$i] > $ma20[$i]) { $CN['u20']++; } else goto SCHK; // 关2: 收盘>MA20
    if ($ma10[$i] !== null && $c1h[$i] > $ma10[$i]) { $CN['u10']++; } else goto SCHK; // 关3: 收盘>MA10
    if (!empty($tr4hUpF[$t])) { $CN['tr']++; } else goto SCHK; // 关4: 4h趋势向上
    if (empty($crashSrc[$t])) { $CN['nc']++; $entryL[$t] = true; $CN['all']++; } // 关5: 非熔断→多头信号成立
    SCHK:
    // 空头逐关
    if ($b < 0.5) { $CN['sb']++; } else continue; // 关1: 宽度<50%
    if ($ma20[$i] !== null && $c1h[$i] < $ma20[$i]) { $CN['d20']++; } else continue; // 关2: 收盘<MA20
    if ($ma10[$i] !== null && $c1h[$i] < $ma10[$i]) { $CN['d10']++; } else continue; // 关3: 收盘<MA10
    if (!empty($tr4hDnF[$t])) { $CN['str']++; } else continue; // 关4: 4h趋势向下
    if (empty($pumpSrc[$t])) { $CN['np']++; $entryS[$t] = true; $CN['sall']++; } // 关5: 非暴涨→空头信号成立
}
echo "DBG CN: " . json_encode($CN) . "\n"; flush(); // 输出漏斗计数
function ffillBr($src, $t) { // 前向填充: 取≤t最近的市场宽度
    static $last = null; static $k = null; // (未用)缓存
    // 简单实现: 每次 O(n) 会太慢 — 用二分
    $keys = array_keys($src); // 源时间戳
    static $sorted = null; // 排序缓存
    if ($sorted === null) { $sorted = $keys; sort($sorted); } // 首次调用时排序
    $lo = 0; $hi = count($sorted) - 1; $res = null; // 二分初始化
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; } // 二分找最近日期
    return $res !== null ? $src[$res] : null; // 返回宽度或null
}
echo "== sim long/short ==\n"; flush(); // 回测阶段提示
$trL = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryL, $br1h, $TAKER_TH, 1); // 跑多头回测
$dbgL = $DBG; // 保存多头调试计数
$trS = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entryS, $br1h, $TAKER_TH, -1); // 跑空头回测
echo "DBG entryL=" . count(array_filter($entryL)) . " seenL={$dbgL['entry']} passL={$dbgL['passed']} tradesL=" . count($trL) . "\n"; flush(); // 输出多头调试信息
echo "long=" . count($trL) . " short=" . count($trS) . "\n"; flush(); // 输出多空笔数

$spanDays = (end($ts1h) / 1000 - $ts1h[0] / 1000) / 86400; // 回测跨度天数
function build($trades, $dir, $principal) { // 明细组装: 逐笔+每日+每月
    $cum = 0.0; $trOut = []; $daily = []; $monthly = []; $no = 0; // 累计/逐笔行/每日/每月/序号
    foreach ($trades as $t) { // 逐笔
        $no++; $cum += $t['pnl']; // 序号与累计
        $d = date('Y-m-d', (int)($t['tout'] / 1000)); $m = substr($d, 0, 7); // 出场日期与年月
        $daily[$d] = ($daily[$d] ?? 0) + $t['pnl']; // 按日累加
        $monthly[$m] = ($monthly[$m] ?? 0) + $t['pnl']; // 按月累加
        $trOut[] = ['no' => $no, 'dir' => $dir, // 明细行: 序号与方向
            'tin' => date('Y-m-d H:i', (int)($t['tin'] / 1000)), 'epx' => $t['epx'], // 入场时间与价格
            'tout' => date('Y-m-d H:i', (int)($t['tout'] / 1000)), 'xpx' => $t['xpx'], // 出场时间与价格
            'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'notional' => $t['notional'], // 出场类型/加仓/保证金/名义
            'mrate' => 10.0, 'liqpx' => $t['liqpx'], 'pnl' => $t['pnl'], // 保证金率(10x=10%)/爆仓价/盈亏
            'pnl_r' => round(100 * $t['pnl'] / $t['margin'], 1), // 保证金收益率
            'avail' => round($principal + $cum, 2), 'cum' => round($cum, 2)]; // 可用资金与累计
    }
    ksort($daily); ksort($monthly); // 按时间排序
    return [$trOut, $daily, $monthly]; // 返回三组数据
}
[$trLo, $dayL, $monL] = build($trL, '多', $PRINCIPAL); // 组装多头
[$trSo, $dayS, $monS] = build($trS, '空', $PRINCIPAL); // 组装空头
// 每日合并(多/空/合计)
$allDays = array_unique(array_merge(array_keys($dayL), array_keys($dayS))); sort($allDays); // 合并全部交易日
$dayRows = []; $c1 = 0; $c2 = 0; $wd = 0; $ld = 0; // 行/多累计/总累计/盈利天数/亏损天数
foreach ($allDays as $d) { // 逐日合并
    $v1 = round($dayL[$d] ?? 0, 2); $v2 = round($dayS[$d] ?? 0, 2); $vt = round($v1 + $v2, 2); // 多盈亏/空盈亏/合计
    $c1 += $v1; $c2 += $vt; // 累计结转
    $nL = 0; $nS = 0; // 当日多/空出场笔数
    foreach ($trL as $t) if (date('Y-m-d', (int)($t['tout'] / 1000)) == $d) $nL++; // 统计多笔数
    foreach ($trS as $t) if (date('Y-m-d', (int)($t['tout'] / 1000)) == $d) $nS++; // 统计空笔数
    if ($vt >= 0) $wd++; else $ld++; // 盈利/亏损天数
    $dayRows[] = ['d' => $d, 'nL' => $nL, 'nS' => $nS, 'pL' => $v1, 'pS' => $v2, 'pT' => $vt, 'cum' => round($c2, 2)]; // 每日合并行
}
function summ($trades) { // 汇总统计
    $w = 0; $sl = 0; $lq = 0; $to = 0; $pl = 0; // 各类计数器
    foreach ($trades as $t) { if ($t['out'] == 'WIN') $w++; elseif ($t['out'] == 'SL' || $t['out'] == 'SL50') $sl++; elseif ($t['out'] == 'LIQ') $lq++; else $to++; $pl += $t['pnl']; } // 分类计数与盈亏
    return ['n' => count($trades), 'win' => $w, 'sl' => $sl, 'liq' => $lq, 'to' => $to, // 各类型笔数
            'win_r' => round(100 * $w / max(1, count($trades)), 1), 'total' => round($pl, 2), // 胜率与总盈亏
            'ev' => round($pl / max(1, count($trades)), 2)]; // 每笔期望
}
$out = [ // 组装输出
    'params' => ['principal' => $PRINCIPAL, 'margin' => $MARGIN, 'lev' => $LEV, 'tp' => '价格±0.5%', 'sl' => '±3%(相对均价)', // 参数: 本金/保证金/杠杆/止盈/止损
                 'taker' => '前12根买比均值>0.5(多)/<0.5(空)', 'span' => round($spanDays) . '天', // taker过滤与跨度
                 'range' => date('Y-m-d', (int)($ts1h[0] / 1000)) . ' ~ ' . date('Y-m-d', (int)(end($ts1h) / 1000)), // 数据起止
                 'breadth' => "全市场476合约 1D MA20宽度({$brDays}天)填充到1h网格"], // 宽度口径
    'sumL' => summ($trL), 'sumS' => summ($trS), // 多/空汇总
    'tradesL' => $trLo, 'tradesS' => $trSo, 'daily' => $dayRows, // 多/空逐笔与每日合并
    'monthly' => ['L' => $monL, 'S' => $monS], // 多/空月度
];
file_put_contents('E:/finally-main/web/eth_year_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入前端数据JSON
echo "JSON OK L=" . count($trLo) . " S=" . count($trSo) . " days=" . count($dayRows) . "\n"; // 完成提示
