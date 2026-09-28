<?php
/**
 * eth_sixtri.php — ETH 六重共振+三均线多头排列 买入回测(CLI, 2026-09-26 用户指令)
 * 用户: "我怎么感觉六共振是错的 给我换成六共振 三均线多头排列 买入 然后加仓也用这个 试一下 一年回测"
 *   A组(新版): 买入 = 六重共振全命中 AND 15m三均线多头排列(MA7>MA25>MA99且收>MA7)
 *   B组(对照): 买入 = 仅15m三均线多头排列(验证六重共振是否拖累)
 *   加仓(两组同): 15m三均线多头排列 + 仅上行(现价>均价), 不限轮数, 每轮固定+1U, 无仓位上限(2026-09-25 build 0925Q规则)
 *   保证金: 每笔 = 1U + 3U×已过天数, 封顶50U(买入封顶50美金)
 *   止盈: 价格+2%(自均价) 市价全平; 不设止损; 交易所爆仓线=价格-0.6%@100x 照模拟
 *   净口径: 开仓maker费/加仓taker费/平仓taker费 + 资金费率0.01%/8h; 起始500U 逐笔结转
 * 输出: web/sixtri_data.json
 */
ini_set('memory_limit', '2048M'); // 调高 PHP 内存上限到 2G, 应对全市场 1D K线大数组
set_time_limit(0); // 取消脚本执行时间限制, 允许长跑回测
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 杠杆100x/止盈+2%/维持保证金率0.4%/maker费率0.02%/taker费率0.05%/资金费率0.01%每8小时
$EQ0 = 500.0; $ADDU = 1.0; $CAPM = 50.0; // 起始权益500U / 每轮加仓1U / 单笔保证金封顶50U

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接本地 MariaDB trading 库并设 utf8mb4 编码
$DB = db(); // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; } // 执行 SQL 并以索引数组形式收集全部行
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; } // 滑动窗口计算简单移动平均线 MA(n), 前段不足窗口时返回 null
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 毫秒时间戳转北京时间(UTC+8)字符串

function load_k($inst, $bar) { // 从 K线表加载指定合约/周期的 OHLC 数据
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC"); // 按时间升序取该周期 K线(列名是 o/h/l/c)
    $ts = []; $o = []; $h = []; $l = []; $c = []; // 初始化时间戳与开高低收五个数组
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; } // 逐行转为整数时间戳与浮点价格
    return [$ts, $o, $h, $l, $c]; // 返回五元组
}

// ===== ① 全市场 1D MA20 宽度(六重共振条件1) =====
$up = []; $tot = []; // $up=每日站上MA20的币数, $tot=每日有20日历史可算MA20的币数
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time"); // 取全市场日K收盘价, 按合约与时间排序
$cur = null; $win = []; $s = 0.0; // 当前合约标识 / 滑动窗口数组 / 窗口和
foreach ($rr as $x) { // 逐行扫描全市场日K
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; } // 换合约时重置窗口与累计和
    $win[] = (float)$x[2]; $s += (float)$x[2]; // 收盘价入窗并累加
    if (count($win) > 20) $s -= array_shift($win); // 窗口超20时移除最旧值并扣减和
    if (count($win) == 20) { // 满20日才能计算MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2]; // 当日时间戳/MA20值/当日收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0); // 收盘≥MA20 计入上涨家数
        $tot[$t] = ($tot[$t] ?? 0) + 1; // 该日可统计的合约数+1
    }
}
$brSrc = []; // 市场宽度(上涨占比)时间序列
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k; // 样本数>10 的日期才计算上涨占比, 过滤开盘初期
$brDays = array_keys($brSrc); sort($brDays); // 宽度数据按日期升序排列, 供二分查找
function ffillBr($t) { // 给定毫秒时间戳, 找到 ≤t 的最近一条市场宽度值(前向填充)
    global $brSrc, $brDays; // 引用全局宽度序列
    $lo = 0; $hi = count($brDays) - 1; $res = null; // 二分查找上下界与结果
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($brDays[$mid] <= $t) { $res = $brDays[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; } // 标准二分找最后一个 ≤t 的日期
    return $res !== null ? $brSrc[$res] : null; // 命中返回宽度值, 否则 null
}
echo "breadth days=" . count($brSrc) . "\n"; flush(); // 输出宽度数据天数并立即刷新

// ===== ⑤ BTC 熔断时刻集合 =====
[$tsB, , , , $cB] = load_k('btc', '1h'); // 加载 BTC 1h 收盘价(六重共振条件5: BTC 熔断)
$meltSet = []; // 熔断标记集合(键=小时起点毫秒时间戳)
for ($i = 4; $i < count($cB); $i++) { // 从第5根开始需向前看4根
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) { // 4小时累计跌幅 ≤ -3% 视为熔断
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true; // 该熔断后4个小时均标记为禁买
    }
}

// ===== ETH K线: 15m 主时间轴 + 1h/4h 条件 =====
[$tsF, $oF, $hF, $lF, $cF] = load_k('eth', '15m'); // 加载 ETH 15m K线作为主回测时间轴
[$ts1, , , , $c1] = load_k('eth', '1h'); // 加载 ETH 1h 收盘价(条件2/3用)
[$ts4, , , , $c4] = load_k('eth', '4h'); // 加载 ETH 4h 收盘价(条件4用)
$ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5); // 1h MA20/MA10 与 4h MA5
$ma7 = ma($cF, 7); $ma25 = ma($cF, 25); $ma99 = ma($cF, 99); // 15m 三均线 MA7/MA25/MA99

function buyratio($o, $h, $l, $c) { // 计算"买入占比"序列: 按每根K线内收盘相对振幅位置归一到 [0,1]
    $n = count($c); $br = []; // K线数量与结果数组
    for ($i = 0; $i < $n; $i++) { // 逐根计算
        $rng = $h[$i] - $l[$i]; // 当根振幅(高-低)
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0; // 收盘在振幅中的相对位置, [-1,1]
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1; // 钳制到 [-1,1]
        $br[$i] = 0.5 + $dir / 2; // 映射到 [0,1], 越接近1表示多头买盘越强
    }
    return $br; // 返回买入占比序列
}
$br1h = buyratio($oF, $hF, $lF, $cF); // 复用15m不需要; 用1h
[$ts1b, $o1b, $h1b, $l1b, $c1b] = load_k('eth', '1h'); // 加载 ETH 1h 完整 OHLC 用于计算真实买入占比
$br1h = buyratio($o1b, $h1b, $l1b, $c1b); // 用 1h 数据覆盖计算买入占比(六重共振条件6)

$nF = count($tsF); // 15m 主时间轴K线根数
echo "eth15m bars=$nF span=" . bj($tsF[0]) . " ~ " . bj($tsF[$nF - 1]) . "\n"; flush(); // 输出主数据规模与时间跨度

// ===== 逐15m判定六重共振(条件集合) =====
$cnt = ['six' => 0, 'tri' => 0, 'six_and_tri' => 0]; // 各条件命中计数器
$p1 = 0; $p4 = 0; $n1 = count($ts1); $n4 = count($ts4); // 1h/4h 游标与长度
$cur20 = null; $cur10 = null; $cur4 = null; // 缓存最近一根 1h/4h 的条件判定结果
$six = array_fill(0, $nF, false); // 每根15m的六重共振命中标记
for ($i = 30; $i < $nF; $i++) { // 从第31根起逐15m判定
    $t = $tsF[$i]; // 当前15m时间戳
    while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; } // 同步1h游标: 收盘>MA20→条件2, 收盘>MA10→条件3
    while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; } // 同步4h游标: 收盘>MA5→条件4
    $hb = (int)floor($t / 3600000) * 3600000; // 当前15m所属小时的起点时间戳
    if (isset($meltSet[$hb])) continue; // 命中BTC熔断时段则跳过(条件5)
    $b = ffillBr($t); // 取该时刻的市场宽度(条件1)
    if ($b === null || $b <= 0.5) continue; // 宽度缺失或≤50%(半数以上币种未站上MA20)则不满足
    if (!$cur20) continue; // 条件2: 1h收盘>MA20 不满足则跳过
    if (!$cur10) continue; // 条件3: 1h收盘>MA10 不满足则跳过
    if (!$cur4) continue; // 条件4: 4h收盘>MA5 不满足则跳过
    $lo = 0; $hi = $n1 - 1; $ib = -1; // 二分查找当前15m之前最近一根1h的下标
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; } // 标准二分
    if ($ib < 0 || $br1h[$ib] < 0.5) continue; // 1h买入占比<0.5 则条件6不满足
    $six[$i] = true; $cnt['six']++; // 六重共振全命中, 标记并计数
}
// ===== 三均线多头排列(15m) =====
$tri = array_fill(0, $nF, false); // 每根15m的三均线多头排列标记
for ($i = 0; $i < $nF; $i++) { // 逐根判定
    $tri[$i] = ($ma7[$i] !== null && $ma25[$i] !== null && $ma99[$i] !== null) // 三条均线均已就绪
        && ($ma7[$i] > $ma25[$i] && $ma25[$i] > $ma99[$i] && $cF[$i] > $ma7[$i]); // MA7>MA25>MA99 且收盘价站上MA7
    if ($tri[$i]) $cnt['tri']++; // 三均线命中计数
    if ($six[$i] && $tri[$i]) $cnt['six_and_tri']++; // 六重共振与三均线同时命中计数
}
echo "six=" . $cnt['six'] . " tri=" . $cnt['tri'] . " six_and_tri=" . $cnt['six_and_tri'] . "\n"; flush(); // 输出各条件统计

// ===== sim: 单仓位顺序回测, 动态权益(build 0925Q规则: 买入封顶50U, 加仓不限轮数每轮+1U) =====
function sim($entry, $tsF, $oF, $hF, $lF, $cF, $tri) { // 核心回测函数: 按入场标记逐笔模拟
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $EQ0, $ADDU, $CAPM; // 引入全局参数
    $liqDrop = 1.0 / $LEV - $MMR; // 爆仓跌幅 = 1/杠杆 - 维持保证金率 ≈ 0.6%
    $eq = $EQ0; $nF = count($tsF); $startT = $tsF[30]; // 当前权益 / K线数 / 回测起点(用于算已过天数)
    $trades = []; $i = 30; // 交易明细数组, 从第31根开始扫描
    while ($i < $nF - 1) { // 主循环: 找入场点
        if (empty($entry[$i])) { $i++; continue; } // 该根无入场信号则前进
        $days = (int)floor((($tsF[$i] - $startT) / 1000) / 86400); // 距回测起点的已过天数
        $M0 = round(min(1.0 + 3.0 * $days, $CAPM), 2); // 本笔保证金 = 1U + 3U×天数, 封顶50U
        if ($M0 < 1) $M0 = 1.0; // 保证金下限1U
        $e = $oF[$i + 1]; // 以信号后一根15m开盘价入场
        $notional = $M0 * $LEV; $avg = $e; $adds = 0; // 名义仓位=保证金×杠杆 / 持仓均价 / 加仓轮数
        $fee = $notional * $FEE_MAKER; $funding = 0.0; // 开仓maker手续费, 资金费累计清零
        $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); // 爆仓价 / 止盈价(自均价+2%)
        $outcome = null; $j = $i + 1; // 出场结果未定, 从下一根开始逐根模拟
        while ($j < $nF) { // 持仓期逐15m推进
            $funding += $notional * $FUND8H / 32; // 每根15m计提资金费(0.01%/8h 折算到15m)
            $hitLiq = $lF[$j] <= $liqPx; // 本根最低价触及爆仓线?
            $hitTp  = $hF[$j] >= $tgtPx; // 本根最高价触及止盈线?
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 先判爆仓: 以爆仓价出场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; } // 再判止盈: 以止盈价出场
            if ($j - $i >= 7 * 24 * 4) { $outcome = 'TO'; $exitPx = $cF[$j]; break; } // 持仓超7天(672根15m)超时以收盘价平仓
            // 加仓: 三均线多头排列 + 仅上行, 不限轮数, 每轮+1U, 无仓位上限
            if ($tri[$j] && $cF[$j] > $avg && $j + 1 < $nF) { // 加仓条件: 三均线多头排列且现价高于均价
                $ap = $oF[$j + 1]; // 加仓价=下一根开盘价
                if ($ap > 0) { // 价格有效才加仓
                    $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV); // 加权更新持仓均价
                    $notional += $ADDU * $LEV; $adds++; // 名义仓位增加, 加仓轮数+1
                    $fee += $ADDU * $LEV * $FEE_TAKER; // 加仓计 taker 手续费
                    $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); // 按新均价重算爆仓价与止盈价
                }
            }
            $j++; // 推进到下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $nF - 1; $exitPx = $cF[$j]; } // 数据尽头仍未出场则按超时处理
        $gross = ($exitPx - $avg) * $notional / $avg; // 毛盈亏 = 价差×名义仓位/均价
        $fee += $notional * $FEE_TAKER; // 平仓 taker 手续费
        $pnl = $gross - $fee - $funding; // 净盈亏 = 毛利 - 总手续费 - 资金费
        $mg = $M0 + $adds * $ADDU; // 本笔总保证金(初始+加仓追加)
        if ($pnl < -$mg) $pnl = -$mg; // 亏损封底为保证金(爆仓最多亏完保证金)
        $eq += $pnl; // 权益结转
        $trades[] = ['tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds, // 记录: 入场/出场时间, 出场类型, 加仓轮数
                     'm0' => $M0, 'mg' => $mg, 'pnl' => round($pnl, 3), 'eq' => round($eq, 2), // 保证金/总保证金/净盈亏/累计权益
                     'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)]; // 持仓小时数
        $i = $j + 1; // 单仓位模式: 出场后从下一根继续找入场
    }
    // 汇总
    $tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'adds' => 0, 'pnl' => 0.0]; // 汇总容器: 笔数/止盈/爆仓/超时/加仓总轮数/总盈亏
    foreach ($trades as $x) { // 逐笔累加
        if ($x['out'] == 'WIN') $tot['win']++; // 止盈笔数
        if ($x['out'] == 'LIQ') $tot['liq']++; // 爆仓笔数
        if ($x['out'] == 'TO') $tot['to']++; // 超时笔数
        $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl']; // 加仓轮数与盈亏累计
    }
    $months = []; // 按月统计容器
    foreach ($trades as $x) { // 逐笔按出场月份归组
        $m = gmdate('Y-m', (int)($x['tout'] / 1000) + 8 * 3600); // 出场时间的北京年月
        $mo = &$months[$m]; // 引用该月统计
        $mo['n'] = ($mo['n'] ?? 0) + 1; // 月笔数
        $mo['win'] = ($mo['win'] ?? 0) + ($x['out'] == 'WIN' ? 1 : 0); // 月止盈数
        $mo['liq'] = ($mo['liq'] ?? 0) + ($x['out'] == 'LIQ' ? 1 : 0); // 月爆仓数
        $mo['to'] = ($mo['to'] ?? 0) + ($x['out'] == 'TO' ? 1 : 0); // 月超时数
        $mo['adds'] = ($mo['adds'] ?? 0) + $x['adds']; // 月加仓轮数
        $mo['pnl'] = round(($mo['pnl'] ?? 0) + $x['pnl'], 2); // 月净盈亏
        $mo['eq_end'] = $x['eq']; // 月末权益
        unset($mo); // 解除引用
    }
    $peak = -INF; $maxdd = 0; // 权益峰值与最大回撤
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; } // 逐笔更新峰值并计算回撤
    return ['summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2), // 返回汇总/期末权益/最大回撤
            'months' => $months, 'trades' => $trades]; // 月度统计与逐笔明细
}

$entryA = array_fill(0, $nF, false); // A组入场标记(六重共振+三均线)
$entryB = array_fill(0, $nF, false); // B组入场标记(仅三均线)
for ($i = 30; $i < $nF; $i++) { // 逐根生成入场标记
    if ($six[$i] && $tri[$i]) $entryA[$i] = true; // A组: 六重共振 AND 三均线多头排列
    if ($tri[$i]) $entryB[$i] = true; // B组: 仅三均线多头排列
}
echo "entryA(six+tri)=" . array_sum($entryA) . " entryB(tri only)=" . array_sum($entryB) . "\n"; flush(); // 输出两组入场信号数

$resA = sim($entryA, $tsF, $oF, $hF, $lF, $cF, $tri); // 跑 A 组回测
echo "A done n=" . $resA['summary']['n'] . " pnl=" . $resA['summary']['pnl'] . " eqEnd=" . $resA['eq_end'] . "\n"; flush(); // 输出 A 组进度
$resB = sim($entryB, $tsF, $oF, $hF, $lF, $cF, $tri); // 跑 B 组回测
echo "B done n=" . $resB['summary']['n'] . " pnl=" . $resB['summary']['pnl'] . " eqEnd=" . $resB['eq_end'] . "\n"; flush(); // 输出 B 组进度

$out = [ // 组装输出数据
    'meta' => [ // 元信息
        'inst' => 'ETH-USDT-SWAP', 'lev' => $LEV, 'tp' => '+2%', 'eq0' => $EQ0, // 标的/杠杆/止盈/起始权益
        'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF, 'cond' => $cnt, // 数据跨度/K线数/条件命中统计
        'params' => 'A组 买入=六重共振全命中 AND 15m三均线多头排列(MA7>MA25>MA99且收>MA7) | B组对照 买入=仅15m三均线多头排列 | 加仓(两组同)=15m三均线多头排列+仅上行(现价>均价) 不限轮数 每轮固定+1U 无仓位上限 | 保证金=1U+3U×已过天数 封顶50U(买入封顶50美金) | 止盈=价格+2% | 不设止损·交易所爆仓线-0.6%照模拟 | 超时7天平仓 | 净口径含手续费+资金费 | 起始500U', // 参数说明文本
    ],
    'A' => $resA, 'B' => $resB, // 两组回测结果
];
file_put_contents('E:/finally-main/web/sixtri_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 结果写入前端数据 JSON(不转义中文)
echo "DATA OK\n"; // 完成提示
