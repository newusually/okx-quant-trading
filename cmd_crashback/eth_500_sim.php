<?php
/**
 * eth_500_sim.php — 500U 仓位 ETH 100x/20x 爆仓测算 + 数月历史回测 + SVG 图表 (纯PHP)
 * 用户方案: 六重共振入场 / 每笔500U / 无止损 / ROI+20%止盈(价格+0.2%从均价) / fib618加仓5轮每次500U
 * 输出: E:/datas/log/eth_500_report.html
 */
ini_set('memory_limit', '1024M');                                 // 提升 PHP 内存上限到 1G
set_time_limit(0);                                                // 取消脚本执行时间限制

$MARGIN = 500.0; $ADD = 500.0; $MAXADD = 5;                       // 每笔保证金500U; 每轮加仓500U; 最多加仓5轮
$TPR = 0.002;                 // 止盈: 价格+0.2% => ROI+20%(100x)  // 止盈价格比: 均价+0.2%即100x下ROI+20%
$MMR = 0.004;                 // 维持保证金率                      // 交易所维持保证金率0.4%
$FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;       // maker费0.02%; taker费0.05%; 8小时资金费率0.01%
$TIMEOUT = 7 * 24;            // 7天兜底(1h根数)                  // 持仓超7天(168根1h)强制平仓

function db() {                                                   // 连接本地数据库
    $m = new mysqli('127.0.0.1', 'root', '', 'trading');          // 连接 MariaDB trading 库
    $m->set_charset('utf8mb4');                                   // 设置 utf8mb4 字符集
    return $m;                                                    // 返回连接对象
}
$DB = db();                                                       // 建立全局数据库连接

function rows($sql) {                                             // 执行SQL收集全部结果行
    global $DB;                                                   // 引用全局连接
    $r = $DB->query($sql);                                        // 执行查询
    $out = [];                                                    // 初始化输出数组
    while ($x = $r->fetch_row()) $out[] = $x;                     // 逐行取数字索引结果
    $r->free();                                                   // 释放结果集
    return $out;                                                  // 返回行数组
}
function col1($sql) {                                             // 执行SQL只取第一行第一列
    global $DB;                                                   // 引用全局连接
    $r = $DB->query($sql);                                        // 执行查询
    $x = $r->fetch_row();                                         // 取第一行
    $r->free();                                                   // 释放结果集
    return $x ? $x[0] : null;                                     // 返回第一列或null
}

// ---------- 工具 ----------
function ma($a, $n) { // 简单均线数组(前n-1为null)                // 简单移动平均线工具函数
    $out = []; $s = 0.0;                                          // 初始化输出与窗口和
    for ($i = 0; $i < count($a); $i++) {                          // 逐个元素滑动
        $s += $a[$i];                                             // 新值入窗口
        if ($i >= $n) $s -= $a[$i - $n];                          // 移除窗口外旧值
        $out[$i] = ($i >= $n - 1) ? $s / $n : null;               // 满 n 个才输出均值, 否则 null
    }
    return $out;                                                  // 返回均线数组
}
function fibsig($c, $w = 30) { // fib_618: 30根窗口 0.618回撤位±3%, 信号在"该根收盘"判定  // fib618 加仓信号
    $n = count($c); $out = array_fill(0, $n, false);              // 初始化输出全为无信号
    for ($i = $w - 1; $i < $n; $i++) {                            // 从第 w 根起逐根扫描
        $lo = INF; $hi = -INF;                                    // 初始化窗口最低/最高
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }  // 求窗口内最高/最低收盘
        if ($hi <= $lo) continue;                                 // 窗口无波动跳过
        $fib = $hi - ($hi - $lo) * 0.618;                         // 计算 0.618 回撤位
        $last = $c[$i];                                           // 当根收盘价
        if ($fib * 0.97 <= $last && $last <= $fib * 1.03) $out[$i] = true;  // 收盘在 fib618 ±3% 内标记信号
    }
    return $out;                                                  // 返回逐根布尔信号
}
// 全市场宽度(流式): suffix='1h'/'15m', 主网格=ETH对应周期ts
function breadth($gridTs, $suffix) {                              // 计算全市场宽度: 各日站上MA20品种占比
    global $DB;                                                   // 引用全局连接
    $idx = array_flip($gridTs);                                   // 主网格时间戳哈希(快速判存在)
    $up = []; $tot = [];                                          // 各时点强势品种数/总数
    $tabs = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline%_$suffix'");  // 枚举该周期全部K线表
    $k = 0;                                                       // 已处理表计数
    foreach ($tabs as $t) {                                       // 逐表流式处理
        $tb = $t[0];                                              // 表名
        $r = $DB->query("SELECT candle_time, `c` FROM `$tb` ORDER BY candle_time ASC");  // 按时间升序读时间与收盘
        if (!$r) continue;                                        // 查询失败跳过
        $win = []; $s = 0.0;                                      // 20根滑动窗口与窗口和
        while ($x = $r->fetch_row()) {                            // 逐行读取
            $tt = (int)$x[0]; $cc = (float)$x[1];                 // 时间戳与收盘价
            if (!isset($idx[$tt])) { $win = []; $s = 0.0; continue; } // 跳格重置窗口
            $win[] = $cc; $s += $cc;                              // 收盘入窗口并累加
            if (count($win) > 20) { $s -= array_shift($win); }    // 超20个移除最旧
            if (count($win) == 20) {                              // 满20根计算MA20
                $ma = $s / 20;                                    // MA20值
                $up[$tt] = ($up[$tt] ?? 0) + ($cc >= $ma ? 1 : 0);  // 收盘≥MA20则强势数+1
                $tot[$tt] = ($tot[$tt] ?? 0) + 1;                 // 品种总数+1
            }
        }
        $r->free();                                               // 释放结果集
        if ((++$k % 150) == 0) { echo "  breadth[$suffix] $k/" . count($tabs) . "\n"; flush(); }  // 每150表输出进度
    }
    $b = [];                                                      // 初始化宽度结果
    foreach ($gridTs as $tt) $b[$tt] = (($tot[$tt] ?? 0) > 10) ? ($up[$tt] ?? 0) / $tot[$tt] : null;  // 品种数>10才算宽度, 否则null
    return $b;                                                    // 返回主网格宽度映射
}
// 低周期标志 → 主网格前值填充
function ffill_map($gridTs, $srcTs, $srcVal) {                    // 把低周期标志前向填充到主网格
    $out = []; $p = 0; $cur = null; $n = count($srcTs);           // 初始化输出/源指针/当前值/源长度
    foreach ($gridTs as $tt) {                                    // 遍历主网格时点
        while ($p < $n && $srcTs[$p] <= $tt) { $cur = $srcVal[$srcTs[$p]] ?? $cur; $p++; }  // 取不晚于tt的最近源值
        $out[$tt] = $cur;                                         // 填充当前值
    }
    return $out;                                                  // 返回填充结果
}
function pctchg($a, $n) {                                         // 计算n根前涨跌幅数组
    $out = [];                                                    // 初始化输出
    for ($i = 0; $i < count($a); $i++) $out[$i] = ($i >= $n && $a[$i - $n] != 0) ? $a[$i] / $a[$i - $n] - 1 : null;  // 相对n根前的涨跌幅, 不足为null
    return $out;                                                  // 返回涨跌幅数组
}
function corr($x, $y) {                                           // 计算皮尔逊相关系数
    $n = count($x); if ($n < 3) return 0;                         // 样本不足返回0
    $mx = array_sum($x) / $n; $my = array_sum($y) / $n;           // 两序列均值
    $sxy = $sxx = $syy = 0;                                       // 协方差与方差累加器
    for ($i = 0; $i < $n; $i++) { $dx = $x[$i] - $mx; $dy = $y[$i] - $my; $sxy += $dx * $dy; $sxx += $dx * $dx; $syy += $dy * $dy; }  // 累加离差积与平方
    return ($sxx > 0 && $syy > 0) ? $sxy / sqrt($sxx * $syy) : 0; // 相关系数 = 协方差/√(方差积)
}

// ---------- 加载 ETH 多周期 ----------
function load_k($inst, $bar) {                                    // 加载指定品种/周期K线
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按时间升序读 kline_<INST>_usdt_swap_<bar> 表(列名 o/h/l/c)
    $ts = []; $o = []; $h = []; $l = []; $c = [];                 // 初始化时间戳与开高低收数组
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }  // 逐行转型入数组
    return [$ts, $o, $h, $l, $c];                                 // 返回五元组
}
echo "== load ETH/BTC klines ==\n"; flush();                      // 进度提示: 加载K线
[$ts15, $o15, $h15, $l15, $c15] = load_k('eth', '15m');           // ETH 15分钟K线(主网格之一)
[$ts1h, $o1h, $h1h, $l1h, $c1h] = load_k('eth', '1h');            // ETH 1小时K线(主网格之一)
[$ts4h, , , , $c4h] = load_k('eth', '4h');                        // ETH 4小时收盘(趋势用)
[$ts5m, , , , $c5m] = load_k('eth', '5m');                        // ETH 5分钟收盘(动量用)
[$ts3m, , , , $c3m] = load_k('eth', '3m');                        // ETH 3分钟收盘(动量用)
[, , , , $cbtc15] = load_k('btc', '15m');                         // BTC 15分钟收盘(暴跌判定用)
[, , , , $cbtc1h] = load_k('btc', '1h');                          // BTC 1小时收盘(暴跌判定用)
$lastPx = $c15[count($c15) - 1];                                  // ETH最新价(15m末根收盘)
echo "ETH 15m: " . count($c15) . " bars, last=$lastPx ; ETH 1h: " . count($c1h) . " bars\n"; flush();  // 输出数据规模

// ---------- 500U 爆仓表 ----------
$levs = [100, 50, 20, 10, 8];                                     // 对比杠杆档位
$liqRows = [];                                                    // 爆仓测算行
foreach ($levs as $lv) {                                          // 逐杠杆计算
    $drop = 1.0 / $lv - $MMR;                                     // 爆仓所需跌幅(1/杠杆-维持保证金率)
    $notional = $MARGIN * $lv;                                    // 名义仓位=保证金×杠杆
    $liqRows[] = [$lv, $notional, $drop * 100, $lastPx * $drop, $lastPx * (1 - $drop), $notional * $drop];  // [杠杆,名义,跌幅%,价距USD,爆仓价,爆仓损失]
}

// ---------- 共振特征 ----------
echo "== features ==\n"; flush();                                 // 进度提示: 特征计算
$ma20_15 = ma($c15, 20);                                          // ETH 15m MA20
$ethUp15 = [];                                                    // 15m ETH站上MA20状态表
for ($i = 0; $i < count($c15); $i++) $ethUp15[$ts15[$i]] = ($ma20_15[$i] !== null && $c15[$i] > $ma20_15[$i]);  // 收盘>MA20记true
$pcBTC15 = pctchg($cbtc15, 4);                                    // BTC 15m 相对4根前(1小时)涨跌幅
$crash15 = []; for ($i = 0; $i < count($cbtc15); $i++) $crash15[$ts15[$i]] = ($pcBTC15[$i] !== null && $pcBTC15[$i] < -0.01);  // BTC 1小时内跌超1%记为暴跌
// 5m/3m 动量 → 15m网格
$ma24_5 = ma($c5m, 24); $mo5src = []; for ($i = 0; $i < count($c5m); $i++) if ($ma24_5[$i] !== null) $mo5src[$ts5m[$i]] = $c5m[$i] > $ma24_5[$i];  // 5m收盘>MA24(2小时)记动量向上
$ma40_3 = ma($c3m, 40); $mo3src = []; for ($i = 0; $i < count($c3m); $i++) if ($ma40_3[$i] !== null) $mo3src[$ts3m[$i]] = $c3m[$i] > $ma40_3[$i];  // 3m收盘>MA40(2小时)记动量向上

echo "== breadth 15m (32天) ==\n"; flush();                       // 进度提示: 15m市场宽度
$br15 = breadth($ts15, '15m');                                    // 计算15m粒度全市场宽度
echo "== breadth 1h (3个月) ==\n"; flush();                       // 进度提示: 1h市场宽度
$br1h = breadth($ts1h, '1h');                                     // 计算1h粒度全市场宽度

// 1h 网格特征
$ma20_1h = ma($c1h, 20); $ma10_1h = ma($c1h, 10);                 // ETH 1h MA20与MA10
$ethUp1h = []; $tr1h = [];                                        // 1h ETH强弱与短趋势状态表
for ($i = 0; $i < count($c1h); $i++) { $ethUp1h[$ts1h[$i]] = ($ma20_1h[$i] !== null && $c1h[$i] > $ma20_1h[$i]); $tr1h[$ts1h[$i]] = ($ma10_1h[$i] !== null && $c1h[$i] > $ma10_1h[$i]); }  // 收盘>MA20记强弱, 收盘>MA10记短趋势
$ma5_4h = ma($c4h, 5); $tr4hSrc = [];                             // 4h MA5与趋势源
for ($i = 0; $i < count($c4h); $i++) if ($ma5_4h[$i] !== null) $tr4hSrc[$ts4h[$i]] = $c4h[$i] > $ma5_4h[$i];  // 4h收盘>MA5记上行
$tr4h = ffill_map($ts1h, $ts4h, $tr4hSrc);                        // 4h趋势前向填充到1h网格
$pcBTC1h = pctchg($cbtc1h, 4);                                    // BTC 1h 相对4根前涨跌幅
$crash1hSrc = []; for ($i = 0; $i < count($cbtc1h); $i++) $crash1hSrc[$ts1h[$i]] = ($pcBTC1h[$i] !== null && $pcBTC1h[$i] < -0.03);  // BTC 4小时内跌超3%记为暴跌

// 入场标志(15m网格: 共振项从1h/4h/5m/3m前向填充)
$tr1hSrc = []; for ($i = 0; $i < count($c1h); $i++) if ($ma10_1h[$i] !== null) $tr1hSrc[$ts1h[$i]] = $c1h[$i] > $ma10_1h[$i];  // 1h短趋势源(收盘>MA10)
$tr1hFor15 = ffill_map($ts15, $ts1h, $tr1hSrc);                   // 1h短趋势填充到15m网格
$tr4hFor15 = ffill_map($ts15, $ts4h, $tr4hSrc);                   // 4h趋势填充到15m网格
$crashFor15 = ffill_map($ts15, $ts15, $crash15);                  // 暴跌标志对齐15m网格
$entry15 = []; $n15 = count($ts15);                               // 15m入场标志与根数
for ($i = 0; $i < $n15; $i++) {                                   // 逐根判定六重共振
    $t = $ts15[$i]; $b = $br15[$t];                               // 当前时点与市场宽度
    $entry15[$t] = ($b !== null && $b > 0.5 && !empty($ethUp15[$t]) && !empty($tr1hFor15[$t]) && !empty($tr4hFor15[$t])  // 条件: 宽度>0.5 + 15m站上MA20 + 1h/4h趋势上行
        && !empty($mo5src[$t] ?? false) && !empty($mo3src[$t] ?? false) && empty($crashFor15[$t]));  // + 5m/3m动量向上 且无BTC暴跌 → 入场
}
$entry1h = []; $n1h = count($ts1h);                               // 1h入场标志与根数
$crashFor1h = ffill_map($ts1h, $ts1h, $crash1hSrc);               // 暴跌标志对齐1h网格
for ($i = 0; $i < $n1h; $i++) {                                   // 逐根判定
    $t = $ts1h[$i]; $b = $br1h[$t];                               // 当前时点与市场宽度
    $entry1h[$t] = ($b !== null && $b > 0.5 && !empty($ethUp1h[$t]) && !empty($tr1h[$t]) && !empty($tr4h[$t]) && empty($crashFor1h[$t]));  // 宽度>0.5 + 强弱/短长趋势上行 + 无暴跌 → 入场
}

// ---------- 模拟器 ----------
// margin 每笔保证金, lev 杠杆, tpr 止盈价格比, addm 加仓保证金, bars8h 每8h根数
function sim($o, $h, $l, $c, $ts, $fib, $entry, $margin, $lev, $tpr, $addm, $bars8h, $timeoutBars) {  // 核心回测模拟器(参数化杠杆/保证金/止盈/加仓/超时)
    global $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $ADD, $MAXADD;  // 引入全局费率与加仓参数
    $liqDrop = 1.0 / $lev - $MMR;                                 // 爆仓所需跌幅
    $trades = [];                                                 // 交易列表
    $n = count($ts);                                              // K线根数
    $i = 30;                                                      // 从第30根起扫描(预热)
    while ($i < $n - 1) {                                         // 主循环逐根找入场
        if (empty($entry[$ts[$i]])) { $i++; continue; }           // 无入场信号跳过
        $e = $o[$i + 1];                                          // 下一根开盘价入场
        $notional = $margin * $lev; $avg = $e; $adds = 0;         // 名义本金/均价/加仓次数初始化
        $fee = $notional * $FEE_MAKER;                            // 入场maker手续费
        $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $tpr);  // 爆仓价与止盈价
        $outcome = null; $exitPx = null; $j = $i + 1; $barsHeld = 0;  // 出场结果/出场价/扫描下标/持仓根数
        while ($j < $n) {                                         // 逐根推进判出场
            $barsHeld = $j - $i;                                  // 更新持仓根数
            if ($l[$j] <= $liqPx) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 触爆仓价→爆仓出场
            if ($h[$j] >= $tgtPx) { $outcome = 'WIN'; $exitPx = $tgtPx; break; }  // 触止盈价→止盈出场
            if ($barsHeld >= $timeoutBars) { $outcome = 'TO'; $exitPx = $c[$j]; break; }  // 超时按收盘平仓
            if ($adds < $MAXADD && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {  // 未达上限且fib618信号且价高于均价
                $ap = $o[$j + 1];                                 // 加仓价取下一根开盘
                $avg = ($avg * $notional + $ap * $addm * $lev) / ($notional + $addm * $lev);  // 加权更新均价
                $notional += $addm * $lev; $adds++;               // 扩大名义本金计加仓次数
                $fee += $addm * $lev * $FEE_TAKER;                // 加仓taker手续费
                $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $tpr);  // 重算爆仓价与止盈价
            }
            $j++;                                                 // 推进下一根
        }
        $gross = ($exitPx - $avg) * $notional / $avg;             // 毛盈亏
        $funding = $notional * $FUND8H * ($barsHeld / $bars8h);   // 资金费成本(按每8小时根数折算)
        $pnl = ($outcome === 'LIQ') ? -$margin : ($gross - $fee - $funding);  // 爆仓损失全部保证金, 否则净盈亏
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,  // 记录一笔交易
                     'margin' => $margin + $adds * $addm, 'pnl' => $pnl, 'bars' => $barsHeld];  // 含总保证金/盈亏/持仓根数
        $i = $j + 1;                                              // 出场后继续扫描
    }
    return $trades;                                               // 返回全部交易
}
function agg($trades, $spanDays) {                                // 聚合统计: 笔数/胜率/爆仓率/逐日月度盈亏
    $daily = []; $mon = []; $liqs = [];                           // 按日/按月盈亏与爆仓日期列表
    foreach ($trades as $t) {                                     // 逐笔归组
        $d = date('Y-m-d', (int)($t['tout'] / 1000));             // 出场日期
        $m = substr($d, 0, 7);                                    // 出场月份
        $daily[$d] = ($daily[$d] ?? 0) + $t['pnl'];               // 累计当日盈亏
        $mon[$m] = ($mon[$m] ?? 0) + $t['pnl'];                   // 累计当月盈亏
        if ($t['out'] === 'LIQ') $liqs[] = $d;                    // 记录爆仓日期
    }
    ksort($daily); ksort($mon);                                   // 按时间升序
    $w = 0; $lq = 0; $to = 0; $pl = 0;                            // 各类计数与总盈亏
    foreach ($trades as $t) { if ($t['out'] === 'WIN') $w++; elseif ($t['out'] === 'LIQ') $lq++; else $to++; $pl += $t['pnl']; }  // 分类计数并累加盈亏
    $n = max(1, count($trades));                                  // 防除零的总笔数
    return ['n' => count($trades), 'win' => $w, 'liq' => $lq, 'to' => $to,  // 返回统计数组
            'win_r' => round(100 * $w / $n, 1), 'liq_r' => round(100 * $lq / $n, 1),  // 胜率%与爆仓率%
            'pnl' => round($pl, 2), 'ev' => round($pl / $n, 2),   // 总盈亏与单笔期望
            'day_avg' => round($pl / max(1, $spanDays), 2),       // 日均盈亏
            'daily' => $daily, 'mon' => $mon, 'liqs' => $liqs, 'trades' => $trades];  // 逐日/逐月/爆仓日/明细
}

echo "== sim 15m (32天) ==\n"; flush();                           // 进度提示: 15m回测
$fib15 = fibsig($c15);                                            // 15m fib618信号
$t15_100 = sim($o15, $h15, $l15, $c15, $ts15, $fib15, $entry15, $MARGIN, 100, $TPR, $ADD, 32, 7 * 96);  // 15m网格100x回测(96根/8h, 超时7天)
$t15_20  = sim($o15, $h15, $l15, $c15, $ts15, $fib15, $entry15, $MARGIN, 20, $TPR, $ADD, 32, 7 * 96);   // 15m网格20x对照回测
$span15 = (end($ts15) - $ts15[0]) / 86400000;                     // 15m数据跨度天数(毫秒换算)
$a15_100 = agg($t15_100, $span15); $a15_20 = agg($t15_20, $span15);  // 聚合两档统计
echo "15m 100x: n={$a15_100['n']} liq={$a15_100['liq']} pnl={$a15_100['pnl']}\n"; flush();  // 输出15m 100x关键结果

echo "== sim 1h (3个月) ==\n"; flush();                           // 进度提示: 1h回测
$fib1h = fibsig($c1h);                                            // 1h fib618信号
$t1h_100 = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib1h, $entry1h, $MARGIN, 100, $TPR, $ADD, 8, $TIMEOUT);  // 1h网格100x回测(8根/8h)
$t1h_20  = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib1h, $entry1h, $MARGIN, 20, $TPR, $ADD, 8, $TIMEOUT);   // 1h网格20x对照回测
$span1h = (end($ts1h) - $ts1h[0]) / 86400000;                     // 1h数据跨度天数
$a1h_100 = agg($t1h_100, $span1h); $a1h_20 = agg($t1h_20, $span1h);  // 聚合两档统计
echo "1h 100x: n={$a1h_100['n']} liq={$a1h_100['liq']} pnl={$a1h_100['pnl']}\n"; flush();  // 输出1h 100x关键结果

// ---------- 共振评分 vs ETH 价格 (1h, 3个月) ----------
$score = [];                                                      // 每根1h的共振评分(0~5)
for ($i = 0; $i < $n1h; $i++) {                                   // 逐根累加满足的共振项
    $t = $ts1h[$i]; $s = 0; $b = $br1h[$t];                       // 时点/评分/宽度
    if ($b !== null && $b > 0.5) $s++;                            // 宽度>0.5 计1分
    if (!empty($ethUp1h[$t])) $s++;                               // 站上MA20 计1分
    if (!empty($tr1h[$t])) $s++;                                  // 1h短趋势向上 计1分
    if (!empty($tr4h[$t])) $s++;                                  // 4h趋势向上 计1分
    if (empty($crashFor1h[$t])) $s++;                             // 无BTC暴跌 计1分
    $score[] = $s;                                                // 存评分
}
$scoreS = []; // 24根平滑
for ($i = 0; $i < $n1h; $i++) {                                   // 24根(1天)窗口平滑评分
    $sl = array_slice($score, max(0, $i - 23), 24);               // 取近24根评分切片
    $scoreS[] = array_sum($sl) / count($sl);                      // 均值即平滑评分
}
// 相关性: 评分 vs 价格; 评分 vs 未来24h涨幅
$xs = []; $ys = []; $xf = []; $yf = [];                           // 相关性样本对
for ($i = 40; $i < $n1h - 24; $i++) {                             // 留足前后余量采样
    $xs[] = $scoreS[$i]; $ys[] = $c1h[$i];                        // 评分 vs 当期价格
    $xf[] = $scoreS[$i]; $yf[] = ($c1h[$i + 24] / $c1h[$i] - 1) * 100;  // 评分 vs 未来24h涨幅%
}
$rLevel = round(corr($xs, $ys), 3);                               // 评分与价格水平相关系数
$rFwd = round(corr($xf, $yf), 3);                                 // 评分与未来涨幅相关系数

// ---------- SVG 图表 ----------
function svg_head($w, $h, $bg = '#ffffff') {                      // 生成SVG头部标签
    return "<svg xmlns='http://www.w3.org/2000/svg' width='$w' height='$h' viewBox='0 0 $w $h' style='background:$bg;border:1px solid #dde3ee;border-radius:8px'>";  // 带尺寸/背景/边框样式的svg开标签
}
function svg_line($pts, $color, $wpx = 1.4, $dash = '') {         // 由点列生成SVG折线路径
    $d = '';                                                      // 路径数据
    foreach ($pts as $k => $p) $d .= ($k ? 'L' : 'M') . round($p[0], 1) . ',' . round($p[1], 1);  // 首点M移动其余L连线
    return "<path d='$d' fill='none' stroke='$color' stroke-width='$wpx'" . ($dash ? " stroke-dasharray='$dash'" : '') . "/>";  // 输出path元素(可带虚线)
}
function svg_text($x, $y, $s, $size = 11, $color = '#445', $anchor = 'start') {  // 生成SVG文本元素
    return "<text x='$x' y='$y' font-size='$size' fill='$color' text-anchor='$anchor' font-family='Microsoft YaHei'>" . htmlspecialchars($s) . "</text>";  // 带位置/字号/颜色/对齐的文本
}
// 图1: 共振评分 vs ETH 价格 (双线同图, 各自归一)
function chart_score_price($ts1h, $c1h, $scoreS, $W = 940, $H = 300) {  // 生成评分与价格双轴对比图
    $n = count($c1h); $padL = 52; $padR = 52; $padT = 26; $padB = 40;  // 根数与四周边距
    $x0 = $padL; $x1 = $W - $padR; $y0 = $H - $padB; $y1 = $padT; // 绘图区四角
    $pmin = min($c1h); $pmax = max($c1h); $smin = 0; $smax = 5;   // 价格范围与评分范围(0~5)
    $X = function ($i) use ($x0, $x1, $n) { return $x0 + ($x1 - $x0) * $i / ($n - 1); };  // 下标→X坐标映射
    $Yp = function ($v) use ($y0, $y1, $pmin, $pmax) { return $y0 - ($y0 - $y1) * ($v - $pmin) / max(0.001, $pmax - $pmin); };  // 价格→Y坐标
    $Ys = function ($v) use ($y0, $y1, $smin, $smax) { return $y0 - ($y0 - $y1) * ($v - $smin) / $smax; };  // 评分→Y坐标
    $s = svg_head($W, $H);                                        // 开始SVG
    // 网格与Y轴
    for ($g = 0; $g <= 4; $g++) {                                 // 画5条水平网格线
        $v = $pmin + ($pmax - $pmin) * $g / 4;                    // 对应价格刻度
        $yy = $Yp($v);                                            // 刻度Y坐标
        $s .= "<line x1='$x0' y1='$yy' x2='$x1' y2='$yy' stroke='#eef1f7'/>";  // 网格线
        $s .= svg_text(6, $yy + 4, round($v), 10, '#889');        // 左侧刻度标注
    }
    // 入场区着色(score>=5)
    for ($i = 0; $i < $n; $i++) {                                 // 遍历找满分窗口
        if ($scoreS[$i] >= 4.9) $s .= "<rect x='" . $X($i) . "' y='$y1' width='2' height='" . ($y0 - $y1) . "' fill='#ffe9ec'/>";  // 画浅红竖条标记共振满分
    }
    $pp = []; $sp = [];                                           // 价格/评分点列
    for ($i = 0; $i < $n; $i++) { $pp[] = [$X($i), $Yp($c1h[$i])]; $sp[] = [$X($i), $Ys($scoreS[$i])]; }  // 生成两序列坐标点
    $s .= svg_line($pp, '#4a7bd4', 1.6);            // ETH价格(蓝)
    $s .= svg_line($sp, '#d33', 1.6);               // 共振评分(红)
    // X轴月份
    $lastM = '';                                                  // 上次标注月份(未再使用)
    for ($i = 0; $i < $n; $i += 168) {                            // 每周(168根1h)标注一次
        $m = date('m-d', (int)($ts1h[$i] / 1000));                // 该点日期
        $s .= svg_text($X($i), $H - 14, $m, 10, '#889', 'middle');  // X轴日期标注
    }
    $s .= svg_text($x0, 16, 'ETH价格(左轴, 蓝)', 11, '#4a7bd4');  // 图例: 价格线
    $s .= svg_text($x1, 16, '共振评分0-5(右轴, 红, 24h平滑)', 11, '#d33', 'end');  // 图例: 评分线
    $s .= svg_text($W / 2, $H - 2, '深红底色 = 共振满分窗口(入场条件全满足)', 10, '#a66', 'middle');  // 底部说明
    return $s . '</svg>';                                         // 返回完整SVG
}
// 图2: ETH 15m K线 最近7天 + 入场/爆仓标记
function chart_kline($ts15, $o15, $h15, $l15, $c15, $trades, $bars = 672, $W = 940, $H = 320) {  // 生成近7天15mK线图并标记交易点
    $n = count($ts15); $st = max(0, $n - $bars);                  // 根数与起始下标(最近672根)
    $padL = 52; $padR = 20; $padT = 26; $padB = 34;               // 四周边距
    $x0 = $padL; $x1 = $W - $padR; $y0 = $H - $padB; $y1 = $padT; // 绘图区四角
    $pmin = INF; $pmax = -INF;                                    // 价格范围初始化
    for ($i = $st; $i < $n; $i++) { $pmin = min($pmin, $l15[$i]); $pmax = max($pmax, $h15[$i]); }  // 取区间内最低/最高
    $cw = ($x1 - $x0) / $bars;                                    // 每根K线宽度
    $X = function ($i) use ($x0, $cw, $st) { return $x0 + ($i - $st) * $cw; };  // 下标→X坐标
    $Y = function ($v) use ($y0, $y1, $pmin, $pmax) { return $y0 - ($y0 - $y1) * ($v - $pmin) / max(0.001, $pmax - $pmin); };  // 价格→Y坐标
    $s = svg_head($W, $H);                                        // 开始SVG
    $tmap = [];                                                   // 入场时间→出场类型映射
    foreach ($trades as $t) { $tmap[$t['tin']] = $t['out']; }     // 构建交易标记映射
    for ($i = $st; $i < $n; $i++) {                               // 逐根画K线
        $up = $c15[$i] >= $o15[$i];                               // 判断阴阳线
        $col = $up ? '#d33' : '#0a6';   // 红涨绿跌
        $x = $X($i); $yo = $Y($o15[$i]); $yc = $Y($c15[$i]);      // 本根X坐标与开收盘Y坐标
        $s .= "<line x1='" . round($x + $cw / 2) . "' y1='" . round($Y($h15[$i])) . "' x2='" . round($x + $cw / 2) . "' y2='" . round($Y($l15[$i])) . "' stroke='$col' stroke-width='0.6'/>";  // 画高低价影线
        $top = min($yo, $yc); $hh = max(0.6, abs($yc - $yo));     // 实体顶与实体高
        $s .= "<rect x='" . round($x + $cw * 0.15) . "' y='" . round($top) . "' width='" . round(max(1, $cw * 0.7)) . "' height='" . round($hh) . "' fill='$col'/>";  // 画实体矩形
        if (isset($tmap[$ts15[$i]])) {                            // 该根有开仓
            $out = $tmap[$ts15[$i]];                              // 该笔出场类型
            $col2 = $out === 'LIQ' ? '#7a00cc' : '#e6890a';       // 爆仓紫色/其他橙色
            $yy = $out === 'LIQ' ? $Y($h15[$i]) - 8 : $Y($l15[$i]) + 12;  // 爆仓点在K线上方, 开仓点在下方
            $s .= "<circle cx='" . round($x + $cw / 2) . "' cy='" . round($yy) . "' r='4' fill='$col2'/>";  // 画标记圆点
        }
    }
    for ($g = 0; $g <= 4; $g++) {                                 // 画5条水平网格线
        $v = $pmin + ($pmax - $pmin) * $g / 4; $yy = $Y($v);      // 价格刻度与Y坐标
        $s .= "<line x1='$x0' y1='$yy' x2='$x1' y2='$yy' stroke='#eef1f7'/>" . svg_text(6, $yy + 4, round($v, 1), 10, '#889');  // 网格线与刻度
    }
    $s .= svg_text($x0, 16, 'ETH 15m K线(最近7天) · 橙点=开仓 · 紫点=爆仓', 11, '#445');  // 标题
    return $s . '</svg>';                                         // 返回完整SVG
}
// 图3: 累计盈亏曲线
function chart_equity($a100, $a20, $W = 940, $H = 280) {          // 生成100x/20x两条累计盈亏曲线
    $pts = [];                                                    // 各序列数据
    foreach (['100x' => ['#d33', $a100], '20x' => ['#0a6', $a20]] as $lab => $cfg) {  // 遍历两档配置
        [$col, $a] = $cfg;                                        // 颜色与聚合数据
        $cum = 0; $p = [[0, 0]];                                  // 累计盈亏与点列(起点原点)
        $times = array_keys($a['daily'] ?: [0]);                  // 逐日键列表
        if (count($times) < 2) continue;                          // 数据不足跳过
        $t0 = strtotime($times[0]); $tN = strtotime(end($times)); // 起止日期时间戳
        $span = max(1, $tN - $t0);                                // 总跨度秒数
        foreach ($a['daily'] as $d => $v) {                       // 逐日累加
            $cum += $v;                                           // 累计盈亏
            $p[] = [(strtotime($d) - $t0) / $span, $cum];         // 归一化X与累计Y
        }
        $pts[$lab] = [$col, $p, $cum];                            // 存该档曲线
    }
    if (!$pts) return '';                                         // 无数据返回空
    $minv = 0; $maxv = 0;                                         // Y值范围
    foreach ($pts as $cfg) foreach ($cfg[1] as $q) { $minv = min($minv, $q[1]); $maxv = max($maxv, $q[1]); }  // 求所有点最小/最大
    $padL = 56; $padR = 20; $padT = 26; $padB = 34;               // 边距
    $x0 = $padL; $x1 = $W - $padR; $y0 = $H - $padB; $y1 = $padT; // 绘图区四角
    $X = function ($u) use ($x0, $x1) { return $x0 + ($x1 - $x0) * $u; };  // 归一X→像素
    $Y = function ($v) use ($y0, $y1, $minv, $maxv) { return $y0 - ($y0 - $y1) * ($v - $minv) / max(0.001, $maxv - $minv); };  // 盈亏值→Y
    $s = svg_head($W, $H);                                        // 开始SVG
    $s .= "<line x1='$x0' y1='" . $Y(0) . "' x2='$x1' y2='" . $Y(0) . "' stroke='#bbb' stroke-dasharray='4 3'/>";  // 画0轴虚线
    foreach ($pts as $lab => $cfg) {                              // 逐档画曲线
        $p = [];                                                  // 像素点列
        foreach ($cfg[1] as $q) $p[] = [$X($q[0]), $Y($q[1])];    // 数据点转像素
        $s .= svg_line($p, $cfg[0], 1.8);                         // 画折线
        $s .= svg_text($x1 - 4, $Y($cfg[2]) - 6, "$lab 累计 {$cfg[2]}U", 11, $cfg[0], 'end');  // 线尾标注累计值
    }
    for ($g = 0; $g <= 4; $g++) {                                 // 画5条网格线
        $v = $minv + ($maxv - $minv) * $g / 4; $yy = $Y($v);      // 刻度与Y坐标
        $s .= "<line x1='$x0' y1='$yy' x2='$x1' y2='$yy' stroke='#eef1f7'/>" . svg_text(4, $yy + 4, round($v), 10, '#889');  // 网格与标注
    }
    $s .= svg_text($x0, 16, '累计盈亏曲线(USD) · 红=100x · 绿=20x · 500U/笔', 11, '#445');  // 标题
    return $s . '</svg>';                                         // 返回完整SVG
}
// 图4: 每日盈亏柱状
function chart_daily($a, $W = 940, $H = 240) {                    // 生成每日盈亏柱状图
    $d = $a['daily'];                                             // 逐日盈亏
    if (!$d) return '';                                           // 无数据返回空
    $keys = array_keys($d);                                       // 日期键列表
    $minv = min(0, min($d)); $maxv = max(0, max($d));             // Y范围(含0轴)
    $padL = 56; $padR = 20; $padT = 26; $padB = 44;               // 边距
    $x0 = $padL; $x1 = $W - $padR; $y0 = $H - $padB; $y1 = $padT; // 绘图区四角
    $n = count($keys); $bw = ($x1 - $x0) / max(1, $n);            // 天数与柱宽
    $Y = function ($v) use ($y0, $y1, $minv, $maxv) { return $y0 - ($y0 - $y1) * ($v - $minv) / max(0.001, $maxv - $minv); };  // 盈亏→Y
    $s = svg_head($W, $H);                                        // 开始SVG
    $s .= "<line x1='$x0' y1='" . $Y(0) . "' x2='$x1' y2='" . $Y(0) . "' stroke='#bbb'/>";  // 0轴线
    $k = 0;                                                       // 柱序号
    foreach ($d as $day => $v) {                                  // 逐日画柱
        $x = $x0 + $k * $bw;                                      // 柱X起点
        $col = $v >= 0 ? '#d33' : '#0a6';                         // 盈红/亏绿
        $ya = $Y($v); $yb = $Y(0);                                // 柱顶与0轴Y
        $s .= "<rect x='" . round($x + 1) . "' y='" . round(min($ya, $yb)) . "' width='" . round(max(1, $bw - 2)) . "' height='" . round(abs($ya - $yb)) . "' fill='$col'/>";  // 画柱体
        if ($k % max(1, (int)($n / 10)) == 0) $s .= svg_text($x + $bw / 2, $H - 24, substr($day, 5), 9, '#889', 'middle');  // 约每1/10处标日期
        $k++;                                                     // 柱序号递增
    }
    for ($g = 0; $g <= 4; $g++) {                                 // 画5条网格线
        $v = $minv + ($maxv - $minv) * $g / 4; $yy = $Y($v);      // 刻度与Y
        $s .= "<line x1='$x0' y1='$yy' x2='$x1' y2='$yy' stroke='#eef1f7'/>" . svg_text(4, $yy + 4, round($v), 10, '#889');  // 网格与标注
    }
    $s .= svg_text($x0, 16, '每日盈亏(USD) · 红=赚 绿=亏 · 100x/500U (1h网格3个月)', 11, '#445');  // 标题
    return $s . '</svg>';                                         // 返回完整SVG
}
echo "== charts ==\n"; flush();                                   // 进度提示: 生成图表
$g1 = chart_score_price($ts1h, $c1h, $scoreS);                    // 图1: 评分vs价格
$g2 = chart_kline($ts15, $o15, $h15, $l15, $c15, $t15_100);       // 图2: 15mK线+交易点
$g3 = chart_equity($a1h_100, $a1h_20);                            // 图3: 累计盈亏曲线
$g4 = chart_daily($a1h_100);                                      // 图4: 每日盈亏柱状

// ---------- HTML 报告 ----------
function trow($cells, $tags = []) {                               // 生成表格行, cells为单元格内容, tags为样式类
    $h = '<tr>';                                                  // 行开始
    foreach ($cells as $i => $v) { $cls = $tags[$i] ?? ''; $h .= "<td class='$cls'>$v</td>"; }  // 逐格拼接(带样式类)
    return $h . '</tr>';                                          // 行结束
}
function sign($v) { return $v >= 0 ? "+$v" : "$v"; }              // 数字带正负号显示
$html = ['<html><head><meta charset="utf-8"><style>
body{font-family:Microsoft YaHei;background:#f7f9fc;margin:20px} h2,h3{color:#1c3f77}
table{border-collapse:collapse;margin:10px 0;background:#fff}td,th{border:1px solid #ccd8ee;padding:6px 12px;text-align:center;font-size:13px}
th{background:#dbe7ff}.up{color:#d33;font-weight:bold}.dn{color:#0a6;font-weight:bold}.bad{background:#ffe8e8}.good{background:#e8ffe8}.muted{color:#889}
svg{margin:10px 0}
</style></head><body>',                                                            // HTML头部与样式
'<h2>ETH 500U 仓位 · 100x/20x 爆仓测算 + 3个月历史回测 + 图表</h2>',                // 页面主标题
"<p class='muted'>ETH最新价 $lastPx · 数据: 1h网格 3个月(06-23起476合约) · 15m网格 32天 · 方案: 六重共振入场 / 每笔{$MARGIN}U / 无止损 / ROI+20%止盈 / fib618加仓5轮每次{$ADD}U / 资金费0.01%/8h</p>",  // 摘要说明(最新价/数据范围/方案)

'<h3>① 500U 仓位爆仓表(逐仓, 维持保证金0.4%)</h3>',                                // 爆仓表标题
'<table><tr><th>杠杆</th><th>名义仓位</th><th>爆仓跌幅</th><th>爆仓价距(USD)</th><th>爆仓价</th><th>爆仓损失</th></tr>'];  // 爆仓表头
foreach ($liqRows as $r) {                                        // 逐杠杆输出爆仓行
    $cls = $r[0] == 100 ? ' class="bad"' : '';                    // 100x行标红底
    $html[] = "<tr$cls><td>{$r[0]}x</td><td>" . round($r[1]) . "U</td><td>-{$r[2]}%</td><td>-$" . round($r[3], 2) . "</td><td>$" . round($r[4], 2) . "</td><td>-" . round($r[5]) . "U</td></tr>";  // 该杠杆爆仓数据行
}
$html[] = '</table>';                                             // 爆仓表结束
$html[] = "<p>· 100x: ETH 跌 <b>" . round($liqRows[0][3], 2) . "</b> 美元(才 0.6%)就爆, 一根15分钟阴线的事; 爆仓损失 " . round($liqRows[0][5]) . "U(500U保证金被吃掉" . round($liqRows[0][5]) . ", 维持保证金部分退回)<br>  // 100x爆仓风险文字说明
· 资金费: 100x 下 50,000U 名义 × 0.01%/8h = <b>5U/8小时</b> = 收益率 -1%/8h, 持仓越久扣越多</p>";  // 资金费文字说明

function block($title, $a, $span) {                               // 生成一个回测统计表格块
    $h = "<h3>$title</h3><table><tr><th>笔数</th><th>胜</th><th>爆仓</th><th>超时</th><th>胜率</th><th>爆仓率</th><th>总盈亏</th><th>EV/笔</th><th>日均</th></tr>";  // 标题与表头
    $h .= trow([$a['n'], $a['win'], $a['liq'], $a['to'], $a['win_r'] . '%', $a['liq_r'] . '%',  // 数据行: 笔数/胜/爆仓/超时/胜率/爆仓率
                sign($a['pnl']) . 'U', sign($a['ev']) . 'U', sign($a['day_avg']) . 'U'],  // 总盈亏/EV/日均
               ['', '', $a['liq'] ? 'bad' : '', '', '', $a['liq_r'] > 5 ? 'bad' : '', $a['pnl'] >= 0 ? 'up' : 'dn', $a['pnl'] >= 0 ? 'up' : 'dn', $a['pnl'] >= 0 ? 'up' : 'dn']);  // 按结果上色
    $h .= "</table><p class='muted'>回测跨度 $span 天</p>";       // 表结束与跨度说明
    return $h;                                                    // 返回HTML块
}
$html[] = block('② 15m 网格 · 32天 · 500U 100x', $a15_100, round($span15));  // 块②: 15m 100x统计
$html[] = block('③ 15m 网格 · 32天 · 500U 20x(对照)', $a15_20, round($span15));  // 块③: 15m 20x对照
$html[] = block('④ 1h 网格 · 3个月 · 500U 100x', $a1h_100, round($span1h));  // 块④: 1h 100x统计
$html[] = block('⑤ 1h 网格 · 3个月 · 500U 20x(对照)', $a1h_20, round($span1h));  // 块⑤: 1h 20x对照

$html[] = '<h3>⑥ 每月盈亏(1h 网格 3个月)</h3><table><tr><th>月份</th><th>100x 盈亏</th><th>20x 盈亏</th></tr>';  // 月度盈亏表标题与表头
$mons = array_unique(array_merge(array_keys($a1h_100['mon']), array_keys($a1h_20['mon']))); sort($mons);  // 合并两档月份并排序
foreach ($mons as $m) {                                           // 逐月输出
    $v1 = $a1h_100['mon'][$m] ?? 0; $v2 = $a1h_20['mon'][$m] ?? 0;  // 两档该月盈亏
    $html[] = trow([$m, sign(round($v1, 2)) . 'U', sign(round($v2, 2)) . 'U'], ['', $v1 >= 0 ? 'up' : 'dn', $v2 >= 0 ? 'up' : 'dn']);  // 该月行(按盈亏上色)
}
$html[] = '</table>';                                             // 月度表结束

if ($a1h_100['liqs']) {                                           // 100x有爆仓记录时
    $html[] = '<h3>⑦ 爆仓历史(1h网格 100x): ' . count($a1h_100['liqs']) . ' 次</h3><p class="bad">' . htmlspecialchars(implode(', ', $a1h_100['liqs'])) . '</p>';  // 输出爆仓次数与日期列表
} else {
    $html[] = '<h3>⑦ 爆仓历史(1h网格 100x): 0 次</h3>';          // 无爆仓
}
if ($a1h_20['liqs']) {                                            // 20x有爆仓记录时
    $html[] = '<p>20x 爆仓 ' . count($a1h_20['liqs']) . ' 次: ' . htmlspecialchars(implode(', ', array_slice($a1h_20['liqs'], 0, 20))) . '</p>';  // 输出前20条爆仓日期
} else {
    $html[] = '<p class="good">20x 整个 3 个月爆仓 0 次</p>';     // 20x无爆仓(绿底强调)
}

$html[] = '<h3>⑧ 共振信号 vs ETH 实际曲线(交叉检查)</h3>';        // 图1标题
$html[] = $g1;                                                    // 插入图1
$html[] = "<p>共振评分与 ETH 价格水平相关系数 r=<b>$rLevel</b> · 评分与<b>未来24h涨幅</b>相关系数 r=<b>$rFwd</b>" .  // 相关系数说明
    ($rFwd > 0.05 ? ' (正相关: 评分高时后续略偏涨, 交叉形态正常)' : ($rFwd < -0.05 ? ' (负相关: 评分高反而后续偏跌, 信号滞后/追高风险)' : ' (几乎不相关: 信号对短期涨幅无预测力)')) . '</p>';  // 按相关性给结论
$html[] = '<h3>⑨ ETH 15m K线(近7天)与开仓/爆仓点</h3>' . $g2;     // 图2标题与内容
$html[] = '<h3>⑩ 累计盈亏曲线</h3>' . $g3;                        // 图3标题与内容
$html[] = '<h3>⑪ 每日盈亏</h3>' . $g4;                            // 图4标题与内容

$html[] = '</body></html>';                                       // HTML收尾
file_put_contents('E:/datas/log/eth_500_report.html', implode("\n", $html));  // 拼接写出HTML报告
echo "REPORT OK\n";                                               // 完成提示
