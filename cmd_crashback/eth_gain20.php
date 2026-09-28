<?php
/**
 * eth_gain20.php — 全市场回测(CLI): 只买「日涨幅≥20%」的币 × 六重共振 A方案
 * 选币: 两种口径对比
 *   A 昨涨口径(无未来函数): 前一日日线(UTC)涨幅≥20% → 当天允许买
 *   B 盘中口径: 当日盘中涨幅(1h收盘/前一日日线收盘-1)≥20% → 该根之后允许买
 * 策略 = A方案(与实盘/eth_allmarket同参):
 *   买入: 六重共振全命中 ①全市场1D MA20宽度>50% ②1h>MA20 ③1h>MA10 ④4h>MA5
 *         ⑤非熔断(BTC 1h最近4根累计≤-3%) ⑥taker买比≥50%(1h方向代理)
 *   加仓: fib618黄金回调+仅上行(现价>均价) ≤5轮 每轮=开仓保证金; 下跌不减仓
 *   止盈: 价格+2%(自均价) 市价全平
 *   50%兜底强平: 浮亏达该笔总保证金(含加仓)×50% → 立即市价全平
 *   保证金: 每笔=base+3U×已过天数, 封顶 500/6
 * 输出: web/eth_gain20_data.json
 */
ini_set('memory_limit', '2048M');                 // 脚本内存上限提高到 2G(全市场数据量大)
set_time_limit(0);                                // 取消脚本执行时间限制(CLI 长跑)
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆100x/止盈+2%/维持保证金率0.4%/maker费率0.02%/taker费率0.05%/8小时资金费率0.01%
$BAL0 = 500.0; $CAP = $BAL0 / 6.0; $G20 = 0.20;   // 初始资金500U/单笔保证金封顶500÷6/涨幅阈值20%

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库并设置 utf8mb4 字符集
$DB = db();                                       // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行 SQL 并以索引数组形式返回全部行
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }  // 滑动窗口均值(MA): 用累积和 O(n) 计算, 不足窗口时为 null
function fibsig($c, $w = 30) {                    // 计算 fib618 信号: 30 根窗口内价格回踩至区间 0.618 分位 ±3% 视为信号
    $n = count($c); $out = array_fill(0, $n, false);  // 初始化输出数组, 全部为 false
    for ($i = $w - 1; $i < $n; $i++) {            // 从第 w 根开始逐根扫描
        $lo = INF; $hi = -INF;                    // 窗口内最低/最高价初始值
        for ($j = $i - $w + 1; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }  // 求窗口内最高最低收盘价
        if ($hi <= $lo) continue;                 // 窗口内价格无波动则跳过
        $fib = $hi - ($hi - $lo) * 0.618;         // 计算黄金分割 0.618 回调位
        if ($fib * 0.97 <= $c[$i] && $c[$i] <= $fib * 1.03) $out[$i] = true;  // 收盘价落入 fib 位 ±3% 区间即触发信号
    }
    return $out;                                  // 返回布尔信号序列
}
function load_k($inst, $bar) {                    // 从 K线表(列名 o/h/l/c)读取指定合约与周期的全部数据
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按 candle_time 升序查询开盘/最高/最低/收盘
    $ts = []; $o = []; $h = []; $l = []; $c = [];  // 分别保存时间戳与 OHLC
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }  // 逐行转类型装入数组
    return [$ts, $o, $h, $l, $c];                 // 返回五元组
}
function buy_ratio_series($o, $h, $l, $c) {       // 用 K线形态模拟 taker 买比: 实体在振幅中的位置映射到 0~1
    $n = count($c); $br = [];                     // 买比结果数组
    for ($i = 0; $i < $n; $i++) {                 // 逐根计算
        $rng = $h[$i] - $l[$i];                   // 当根振幅(高-低)
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;  // 实体占振幅比例(-1~1), 振幅为0时取0
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;  // 限幅保护
        $br[$i] = 0.5 + $dir / 2;                 // 映射到 0~1, 0.5 为中性
    }
    return $br;                                   // 返回买比序列
}

// ===== kline1d: 每币每日收盘 + 日涨幅 + 前一日收盘(盘中口径用) =====
$dayClose = [];   // inst => day => close            // 每个币每个 UTC 日期的日线收盘价
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");  // 读全市场日线收盘
$cur = null;                                      // 当前遍历的合约名
foreach ($rr as $x) {                             // 逐行遍历日线数据
    $iid = strtolower(str_replace('-USDT-SWAP', '', $x[0]));  // 去掉后缀并转小写得到币种名(如 ETH)
    if ($iid !== $cur) { $cur = $iid; }           // 切换当前合约
    $dayClose[$cur][gmdate('Y-m-d', (int)($x[1] / 1000))] = (float)$x[2];  // 按 UTC 日期存收盘价
}
echo "kline1d insts=" . count($dayClose) . "\n"; flush();  // 输出日线覆盖的币种数并立即刷新

// A口径: 前一日涨幅≥20% → 当天允许;  B口径: 前一日收盘价(盘中涨幅分母)
$allowA = []; $prevClose = []; $g20Count = []; // day => 币数   // A口径白名单/B口径前收/每日涨幅≥20%币数
foreach ($dayClose as $iid => $dc) {              // 遍历每个币的日线
    ksort($dc);                                   // 按日期升序排序
    $prevDay = null; $prevC = null;               // 上一交易日与上一日收盘
    foreach ($dc as $d => $cv) {                  // 逐日遍历
        $prevClose[$iid][$d] = $prevC;            // 记录该日的前一日收盘(盘中涨幅分母)
        if ($prevC !== null && $prevC > 0) {      // 有有效前收才可算涨幅
            $g = $cv / $prevC - 1;                // 当日涨幅
            if ($g >= $G20) {                     // 涨幅≥20%
                $g20Count[$d] = ($g20Count[$d] ?? 0) + 1;  // 该日涨幅≥20%币数+1
                $nextDay = gmdate('Y-m-d', strtotime($d . ' +1 day'));  // 计算次日日期
                $allowA[$iid][$nextDay] = true; // 前一日涨≥20% → 次日可买   // A口径: 白名单写到次日(无未来函数)
            }
        }
        $prevDay = $d; $prevC = $cv;              // 滚动更新前一日状态
    }
}
$g20Days = count($g20Count);                      // 出现过≥20%涨幅币的天数
$g20CoinsAvg = $g20Days ? array_sum($g20Count) / $g20Days : 0;  // 平均每天有多少个币涨≥20%
arsort($g20Count);                                // 按≥20%币数降序, 便于取最热日
echo "days(有≥20%涨幅币)=$g20Days avgCoins=" . round($g20CoinsAvg, 2) . " maxDay=" . key($g20Count) . "=" . current($g20Count) . "\n"; flush();  // 输出统计并刷新

// ===== ① 全市场1D MA20 宽度 =====
$up = []; $tot = [];                              // 每日站上 MA20 的币数 / 每日有 MA20 的币数
$cur = null; $win = []; $s = 0.0;                 // 当前合约/20根滑窗/窗口和
foreach ($rr as $x) {                             // 再次遍历日线
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; }  // 换币时重置窗口
    $win[] = (float)$x[2]; $s += (float)$x[2];    // 收盘价入窗并累加
    if (count($win) > 20) $s -= array_shift($win);  // 窗口超20根则移出最旧值
    if (count($win) == 20) {                      // 窗口满20根才可算 MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2];  // 时间戳/MA20值/当前收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0);  // 该日站上 MA20 计数
        $tot[$t] = ($tot[$t] ?? 0) + 1;           // 该日参与统计币数
    }
}
$brSrc = [];                                      // 宽度(站上MA20占比)源数据: 时间戳 => 比例
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k;  // 样本币数>10 才计算宽度
$sorted = array_keys($brSrc); sort($sorted);      // 所有有效时间戳升序排列, 供二分查找
function ffillBr($t) {                            // 前向填充: 找 ≤t 的最近一次宽度值(二分查找)
    global $brSrc, $sorted;                       // 引用全局宽度数据
    $lo = 0; $hi = count($sorted) - 1; $res = null;  // 二分上下界与结果
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }  // 标准二分找最近时间戳
    return $res !== null ? $brSrc[$res] : null;   // 返回对应宽度或 null
}
echo "breadth days=" . count($brSrc) . "\n"; flush();  // 输出宽度覆盖天数并刷新

// ===== ⑤ 熔断: BTC 1h 最近4根累计≤-3% =====
[$tsB, , , , $cB] = load_k('btc', '1h');          // 读 BTC 1h 时间戳与收盘价
$meltSet = [];                                    // 熔断小时集合(触发后4小时均算熔断)
for ($i = 4; $i < count($cB); $i++) {             // 从第5根开始扫描
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {  // 4根累计跌幅≤-3%
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;  // 触发时点起4个小时都标记为熔断
    }
}
echo "melt hours=" . count($meltSet) . "\n"; flush();  // 输出熔断小时数并刷新

// ===== 合约清单 =====
$tbls = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline_%_usdt_swap_1h' ORDER BY table_name");  // 从元数据表列出所有有 1h K线表的合约
$insts = [];                                      // 合约名列表
foreach ($tbls as $x) { if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $x[0], $m)) $insts[] = $m[1]; }  // 从表名中提取合约名
echo "insts=" . count($insts) . "\n"; flush();    // 输出合约总数并刷新

// ===== A方案 sim(与 eth_allmarket 完全同参) =====
function sim($o, $h, $l, $c, $ts, $fib, $entry, $lbase) {  // 单合约模拟: 输入 OHLC/时间戳/fib信号/入场集合/保证金基数
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP;  // 引用全局参数
    $liqDrop = 1.0 / $LEV - $MMR;                 // 简化爆仓距离 = 1/杠杆 - 维持保证金率
    $startT = $ts[30];                            // 回测起点时间戳(跳过前30根预热)
    $trades = []; $n = count($ts); $i = 30;       // 成交列表/总根数/游标从30开始
    while ($i < $n - 1) {                         // 逐根扫描
        if (empty($entry[$ts[$i]])) { $i++; continue; }  // 该小时不在入场集合则跳过
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400);  // 距回测起点的天数
        $M = round(min($lbase + 3.0 * $days, $CAP), 2);  // 本笔保证金 = 基数 + 3U×天数, 封顶 500/6
        if ($M < 1) $M = 1.0;                     // 保证金下限 1U
        $M0 = $M;                                 // 记录开仓保证金(每轮加仓都用此值)
        $e = $o[$i + 1];                          // 下一根开盘价入场(避免未来函数)
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;  // 名义仓位=保证金×杠杆/均价=入场价/加仓次数清零
        $fee = $notional * $FEE_MAKER;            // 开仓手续费(maker)
        $funding = 0.0;                           // 累计资金费
        $liqPx = $avg * (1 - $liqDrop);           // 简化爆仓价
        $tgtPx = $avg * (1 + $TPR);               // 止盈价 = 均价+2%
        $stopPx = $avg * (1 - 0.5 / $LEV);   // 50%兜底: 浮亏=总保证金(含加仓)×50%   // 兜底强平价: 价格跌0.5%(100x下浮亏=保证金50%)
        $outcome = null; $exitPx = null; $j = $i + 1;  // 结局/出场价/逐根游标
        while ($j < $n) {                         // 持仓期间逐根推进
            $barsHeld = $j - $i;                  // 已持仓小时数
            $funding += $notional * $FUND8H / 8;  // 每小时计提 8h 资金费的 1/8
            $hitCut = $l[$j] <= $stopPx;          // 本根最低触及兜底线
            $hitLiq = $l[$j] <= $liqPx;           // 本根最低触及爆仓价
            $hitTp  = $h[$j] >= $tgtPx;           // 本根最高触及止盈价
            if ($hitCut) { $outcome = 'CUT'; $exitPx = $stopPx; break; }  // 兜底强平优先
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }   // 其次爆仓
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }   // 触及止盈
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }  // 持仓超7天按收盘强制平仓
            if ($adds < 5 && $j + 1 < $n && !empty($fib[$j]) && $c[$j] > $avg) {  // fib618信号+现价在均价上方(仅上行)且加仓未满5轮
                $ap = $o[$j + 1];                 // 加仓价 = 下一根开盘
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);  // 加权计算新均价
                $notional += $M0 * $LEV; $adds++; // 名义仓位增加一轮, 加仓计数+1
                $fee += $M0 * $LEV * $FEE_TAKER;  // 加仓按 taker 费率计手续费
                $liqPx = $avg * (1 - $liqDrop);   // 重算爆仓价
                $tgtPx = $avg * (1 + $TPR);       // 重算止盈价
                $stopPx = $avg * (1 - 0.5 / $LEV);  // 重算兜底强平价
            }
            $j++;                                 // 推进到下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }  // 数据跑完仍未出场则按最后一根收盘平仓
        $gross = ($exitPx - $avg) * $notional / $avg;  // 毛盈亏 = 价差 × 名义仓位 / 均价
        $pnl = $gross - $fee - $funding;          // 净盈亏 = 毛利 - 手续费 - 资金费
        $mg = $M0 * (1 + $adds);                  // 该笔总保证金(含加仓)
        if ($pnl < -$mg) $pnl = -$mg;             // 亏损不超过总保证金(兜底强平保证了这一点)
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,  // 记录一笔成交明细
                     'margin' => $mg, 'pnl' => round($pnl, 3)];  // 含进出时间/结局/加仓轮数/保证金/盈亏
        $i = $j + 1;                              // 跳到出场后一根继续(单合约同时只1仓)
    }
    return $trades;                               // 返回该合约全部成交
}

$ladders = [['base' => 1.0, 'name' => '1U+3U/天'], ['base' => 20.0, 'name' => '20U+3U/天']];  // 两种保证金阶梯: 1U 或 20U 起步
$variants = ['A' => '昨涨≥20%', 'B' => '盘中≥20%'];  // 两种选币口径
$summary = []; $contractStats = []; $dailyPnl = []; $sample = [];  // 汇总/合约排行/每日盈亏/样本成交
$entryCnt = ['A' => 0, 'B' => 0];                 // 两种口径的入场信号根数计数
$gMin = PHP_INT_MAX; $gMax = 0;                   // 全数据时间范围(最小/最大时间戳)
foreach ($tsB as $t) { if ($t < $gMin) $gMin = $t; if ($t > $gMax) $gMax = $t; }  // 用 BTC 1h 时间戳确定数据跨度

$done = 0;                                        // 已处理合约计数
foreach ($insts as $inst) {                       // 遍历全部合约
    $t1 = "kline_{$inst}_usdt_swap_1h"; $t4 = "kline_{$inst}_usdt_swap_4h";  // 该币 1h/4h 表名
    $chk = rows("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='trading' AND table_name IN ('$t1','$t4')");  // 检查两张表是否都存在
    if ((int)$chk[0][0] < 2) continue;            // 缺任一张表则跳过
    [$ts1h, $o1h, $h1h, $l1h, $c1h] = load_k($inst, '1h');  // 读 1h K线
    $n = count($ts1h);                            // 1h 根数
    if ($n < 200) { $done++; continue; }          // 数据不足200根跳过
    [$ts4h, , , , $c4h] = load_k($inst, '4h');    // 读 4h 收盘
    $has4 = count($ts4h) >= 10;                   // 4h 数据是否够算 MA5
    $ma20 = ma($c1h, 20); $ma10 = ma($c1h, 10);   // 1h 的 MA20/MA10
    $ma5_4h = $has4 ? ma($c4h, 5) : [];           // 4h 的 MA5(数据不足则为空)
    $tr4hUpF = []; $p4 = 0; $cur4 = null; $n4 = count($ts4h);  // 4h>MA5 状态映射/游标/当前状态/4h根数
    foreach ($ts1h as $tt) {                      // 把 4h 状态对齐到每根 1h
        while ($p4 < $n4 && $ts4h[$p4] <= $tt) { if ($ma5_4h[$p4] !== null) $cur4 = $c4h[$p4] > $ma5_4h[$p4]; $p4++; }  // 前向推进: 用已收盘的 4h 值更新状态
        $tr4hUpF[$tt] = $cur4;                    // 该 1h 时点的 4h>MA5 布尔状态
    }
    $br1h = buy_ratio_series($o1h, $h1h, $l1h, $c1h);  // 1h taker买比序列
    $fib = fibsig($c1h);                          // 1h fib618 信号序列
    // 六重共振基础命中
    $base = [];                                   // 六重共振命中集合: 时间戳 => 下标
    for ($i = 30; $i < $n; $i++) {                // 跳过前30根预热
        $t = $ts1h[$i];                           // 当前时间戳
        if (!empty($meltSet[$t])) continue;       // ⑤ 熔断时段不开仓
        $b = ffillBr($t);                         // ① 取全市场 MA20 宽度
        if ($b === null || $b <= 0.5) continue;   // ① 宽度须>50%
        if ($ma20[$i] === null || $c1h[$i] <= $ma20[$i]) continue;  // ② 1h收盘须>MA20
        if ($ma10[$i] === null || $c1h[$i] <= $ma10[$i]) continue;  // ③ 1h收盘须>MA10
        if (empty($tr4hUpF[$t])) continue;        // ④ 4h须在 MA5 上方
        if ($br1h[$i] < 0.5) continue;            // ⑥ taker买比须≥50%
        $base[$t] = $i;                           // 全部命中 → 记为有效入场点
    }
    // A口径: 当天在前一日涨≥20%名单
    $entryA = [];                                 // A口径入场集合
    foreach ($base as $t => $i) {                 // 遍历基础命中点
        $d = gmdate('Y-m-d', (int)($t / 1000));   // 该时点所在 UTC 日期
        if (!empty($allowA[$inst][$d])) $entryA[$t] = true;  // 当日允许(A口径白名单)才保留
    }
    // B口径: 盘中涨幅 = 1h收盘/前一日日线收盘 - 1 ≥ 20%
    $entryB = [];                                 // B口径入场集合
    if (!empty($prevClose[$inst])) {              // 该币有前收数据才计算
        $pc = $prevClose[$inst];                  // 前1日收盘表
        $pcDays = array_keys($pc);                // 日期列表
        foreach ($base as $t => $i) {             // 遍历基础命中点
            $d = gmdate('Y-m-d', (int)($t / 1000));  // 所在日期
            if (!isset($pc[$d])) continue;        // 无前收跳过
            $p0 = $pc[$d];                        // 前一日日线收盘
            if ($p0 === null || $p0 <= 0) continue;  // 前收无效跳过
            if ($c1h[$i] / $p0 - 1 >= $G20) $entryB[$t] = true;  // 盘中涨幅≥20% → 允许买
        }
    }
    $entryCnt['A'] += count($entryA); $entryCnt['B'] += count($entryB);  // 累加两种口径的信号数
    foreach ($variants as $vk => $vn) {           // 遍历两种选币口径
        $entry = ($vk == 'A') ? $entryA : $entryB;  // 取对应入场集合
        foreach ($ladders as $ld) {               // 遍历两种保证金阶梯
            $tr = sim($o1h, $h1h, $l1h, $c1h, $ts1h, $fib, $entry, $ld['base']);  // 跑模拟
            $pl = 0.0; $w = 0; $cut = 0; $adds = 0;  // 总盈/胜场/兜底次数/加仓总数
            foreach ($tr as $x) {                 // 汇总每笔成交
                $pl += $x['pnl']; if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'CUT') $cut++; $adds += $x['adds'];  // 累加盈亏/胜场/兜底/加仓
                $d = gmdate('Y-m-d', (int)($x['tout'] / 1000));  // 出场日期
                $dailyPnl[$vk][$ld['name']][$d] = ($dailyPnl[$vk][$ld['name']][$d] ?? 0) + $x['pnl'];  // 按口径×阶梯×日期累计盈亏
            }
            $cnt = count($tr);                    // 成交笔数
            $summary[$vk][$ld['name']]['n'] = ($summary[$vk][$ld['name']]['n'] ?? 0) + $cnt;  // 累加笔数
            $summary[$vk][$ld['name']]['win'] = ($summary[$vk][$ld['name']]['win'] ?? 0) + $w;  // 累加胜场
            $summary[$vk][$ld['name']]['cut'] = ($summary[$vk][$ld['name']]['cut'] ?? 0) + $cut;  // 累加兜底强平次数
            $summary[$vk][$ld['name']]['liq'] = ($summary[$vk][$ld['name']]['liq'] ?? 0);  // 爆仓次数(本策略理论上为0)
            $summary[$vk][$ld['name']]['to'] = ($summary[$vk][$ld['name']]['to'] ?? 0);    // 超时平仓次数
            $summary[$vk][$ld['name']]['adds'] = ($summary[$vk][$ld['name']]['adds'] ?? 0) + $adds;  // 累加加仓轮数
            $summary[$vk][$ld['name']]['total'] = ($summary[$vk][$ld['name']]['total'] ?? 0) + $pl;  // 累加总盈亏
            if ($cnt > 0) $contractStats[$vk][$ld['name']][] = ['inst' => strtoupper($inst), 'n' => $cnt, 'pnl' => round($pl, 2), 'wr' => round(100 * $w / $cnt, 1)];  // 记录该合约成绩用于排行
            if ($vk == 'A' && $inst === 'eth') $sample[$ld['name']] = array_slice($tr, -40);  // ETH 的 A口径结果取最后40笔作样本
        }
    }
    $done++;                                      // 完成一个合约
    if ($done % 40 == 0) { echo "progress $done/" . count($insts) . "\n"; flush(); }  // 每40个输出一次进度
    unset($ts1h, $o1h, $h1h, $l1h, $c1h, $ts4h, $c4h, $ma20, $ma10, $ma5_4h, $tr4hUpF, $br1h, $fib, $base, $entryA, $entryB);  // 及时释放内存
}
echo "entryBars A={$entryCnt['A']} B={$entryCnt['B']}\n"; flush();  // 输出两种口径的入场信号总数

// 组合曲线/排行
$curve = [];                                      // 权益曲线与统计
foreach ($variants as $vk => $vn) {               // 遍历口径
    foreach ($ladders as $ld) {                   // 遍历阶梯
        $nm = $ld['name'];                        // 阶梯名
        $eq = $BAL0; $peak = $BAL0; $maxDD = 0.0; // 权益/峰值/最大回撤从500U起算
        $days = array_keys($dailyPnl[$vk][$nm] ?? []); sort($days);  // 有盈亏记录的日期升序
        $c2 = [];                                 // 曲线点列表
        foreach ($days as $d) { $eq += $dailyPnl[$vk][$nm][$d]; if ($eq > $peak) $peak = $eq; if ($peak - $eq > $maxDD) $maxDD = $peak - $eq; $c2[] = ['d' => $d, 'eq' => round($eq, 2)]; }  // 逐日累加权益并更新峰值/回撤
        $curve[$vk][$nm] = ['days' => $c2, 'max_dd' => round($maxDD, 2)];  // 存曲线与最大回撤
        $worst = []; foreach ($dailyPnl[$vk][$nm] ?? [] as $d => $v) $worst[] = ['d' => $d, 'pnl' => round($v, 2)];  // 收集每日盈亏
        usort($worst, function ($a, $b) { return $a['pnl'] <=> $b['pnl']; });  // 按盈亏升序(最差在前)
        $curve[$vk][$nm]['worst_days'] = array_slice($worst, 0, 8);           // 最差8天
        $curve[$vk][$nm]['best_days'] = array_slice(array_reverse($worst), 0, 8);  // 最好8天
    }
}
$rank = [];                                       // 合约盈亏排行
foreach ($variants as $vk => $vn) foreach ($ladders as $ld) {  // 口径×阶梯
    $arr = $contractStats[$vk][$ld['name']] ?? [];  // 该组合的合约成绩
    usort($arr, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; });  // 按盈亏降序
    $rank[$vk][$ld['name']] = ['top' => array_slice($arr, 0, 10), 'bottom' => array_slice(array_reverse($arr), 0, 10)];  // 取最好/最差各10名
}
$spanDays = ($gMax / 1000 - $gMin / 1000) / 86400;  // 回测数据总跨度(天)
$g20Top = [];                                     // 涨≥20%币数最多的日子
foreach (array_slice($g20Count, 0, 10, true) as $d => $k) $g20Top[] = ['d' => $d, 'coins' => $k];  // 取前10个最热日
$out = [                                          // 组装输出 JSON
    'params' => ['lev' => 100, 'bal0' => $BAL0, 'cap_per_trade' => round($CAP, 2), 'tp' => '价格+2%(自均价)',  // 基础参数说明
        'backstop' => '50%兜底强平: 浮亏达到该笔总保证金(含加仓)×50% → 立即市价全平',  // 兜底规则说明
        'entry' => '六重共振全命中: ①全市场1D MA20宽度>50% ②1h>MA20 ③1h>MA10 ④4h>MA5 ⑤非熔断(BTC 1h最近4根累计≤-3%) ⑥taker买比≥50% + 前置过滤「日涨幅≥20%」',  // 入场规则说明
        'filterA' => 'A昨涨口径(无未来函数): 前一日日线涨幅≥20% → 当天可买',  // A口径说明
        'filterB' => 'B盘中口径: 当日盘中涨幅(1h收盘/前一日日线收盘-1)≥20% → 该根之后可买',  // B口径说明
        'add' => 'fib_618黄金回调+仅上行(现价>均价) ≤5轮 每轮=开仓保证金; 下跌不减仓',  // 加仓规则说明
        'note' => '加仓/兜底口径与A方案完全同参; 每合约同时只1仓; 保证金封顶=500/6(组合不复利)',  // 补充说明
        'span' => round($spanDays) . '天',        // 回测跨度
        'range' => date('Y-m-d', (int)($gMin / 1000)) . ' ~ ' . date('Y-m-d', (int)($gMax / 1000)),  // 起止日期
        'insts' => count($insts)],                // 合约数量
    'g20_stats' => ['days_with_g20' => $g20Days, 'avg_coins' => round($g20CoinsAvg, 2), 'top_days' => $g20Top],  // 涨≥20%统计
    'entry_bars' => $entryCnt,                    // 两种口径信号数
    'summary' => $summary, 'curve' => $curve, 'rank' => $rank,  // 汇总/曲线/排行
    'sample' => array_map(function ($t) {         // 样本成交(格式化时间)
        return ['tin' => date('m-d H:i', (int)($t['tin'] / 1000)), 'tout' => date('m-d H:i', (int)($t['tout'] / 1000)),  // 进出时间格式化
                'out' => $t['out'], 'adds' => $t['adds'], 'margin' => $t['margin'], 'pnl' => $t['pnl']];  // 结局/加仓/保证金/盈亏
    }, $sample['1U+3U/天'] ?? []),                // 取 1U 阶梯样本
];
file_put_contents('E:/finally-main/web/eth_gain20_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出报告 JSON(中文不转义)
echo "JSON OK\n";                                 // 输出完成提示
foreach ($variants as $vk => $vn) foreach ($ladders as $ld) { $nm = $ld['name'];   // 遍历口径×阶梯打印汇总
    echo "$vn $nm: " . json_encode($summary[$vk][$nm] ?? [], JSON_UNESCAPED_UNICODE) . " maxDD=" . ($curve[$vk][$nm]['max_dd'] ?? '-') . "\n"; }  // 输出各组成绩与最大回撤
