<?php
/**
 * eth_sixshort.php — ETH 牛市买多+熊市卖空 双向回测(CLI, 2026-09-26 用户指令)
 * 用户: "能不能判断一下 熊市卖空 牛市买多 这样能不能盈利 回测一下"
 *   多头: 六重共振(牛市环境)全命中 AND 15m三均线多头排列(MA7>MA25>MA99且收>MA7) → 做多
 *   空头: 熊市共振(六条件全部反向: 全市场1D MA20宽度<50% / ETH 1h<MA20 / 1h<MA10 / 4h<MA5 /
 *         taker卖压(买比<50%) / BTC熔断双向禁入) AND 15m三均线空头排列(MA7<MA25<MA99且收<MA7) → 做空
 *   加仓(双向同): 对应方向三均线排列 + 顺向(多:现价>均价 / 空:现价<均价), 不限轮数, 每轮+1U, 无仓位上限
 *   保证金: 1U+3U×已过天数 封顶50U | 止盈: 价格±2%(自均价) | 不设止损 | 爆仓线±0.6%@100x 照模拟 | 超时7天
 *   资金费: 多头支付 0.01%/8h, 空头收取(ETH资金费常态为正的简化假设)
 *   净口径: 开仓maker费/加仓taker费/平仓taker费 ± 资金费; 起始500U 单账户顺序交易
 * 输出: web/sixshort_data.json
 */
ini_set('memory_limit', '2048M');                 // 内存上限 2G
set_time_limit(0);                                // 取消执行时间限制
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆/止盈/维持保证金率/费率/资金费率
$EQ0 = 500.0; $ADDU = 1.0; $CAPM = 50.0;          // 起始权益500U/每轮加仓1U/保证金封顶50U

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库
$DB = db();                                       // 建立全局连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行 SQL 返回全部行
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }  // 滑动均线 MA
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }  // 毫秒时间戳转北京时间字符串

function load_k($inst, $bar) {                    // 读取 K线表(o/h/l/c 列)
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按 candle_time 升序
    $ts = []; $o = []; $h = []; $l = []; $c = []; // 时间戳与 OHLC
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }  // 类型转换装入
    return [$ts, $o, $h, $l, $c];                 // 返回五元组
}

// ===== ① 全市场 1D MA20 宽度 =====
$up = []; $tot = [];                              // 每日站上MA20币数 / 样本币数
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");  // 读全市场日线收盘
$cur = null; $win = []; $s = 0.0;                 // 当前合约/滑窗/窗口和
foreach ($rr as $x) {                             // 逐行扫描
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; }  // 换币重置
    $win[] = (float)$x[2]; $s += (float)$x[2];    // 收盘入窗
    if (count($win) > 20) $s -= array_shift($win);  // 窗口超20移除最旧
    if (count($win) == 20) {                      // 满20根算 MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2];  // 时间戳/MA20/收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0);  // 站上MA20计数
        $tot[$t] = ($tot[$t] ?? 0) + 1;           // 样本计数
    }
}
$brSrc = [];                                      // 宽度源数据
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k;  // 样本>10才计算
$brDays = array_keys($brSrc); sort($brDays);      // 时间戳升序供二分
function ffillBr($t) {                            // 前向填充: 二分找 ≤t 的最近宽度
    global $brSrc, $brDays;                       // 引用全局数据
    $lo = 0; $hi = count($brDays) - 1; $res = null;  // 二分上下界/结果
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($brDays[$mid] <= $t) { $res = $brDays[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }  // 标准二分
    return $res !== null ? $brSrc[$res] : null;   // 返回宽度或 null
}
echo "breadth days=" . count($brSrc) . "\n"; flush();  // 输出宽度覆盖天数

// ===== ⑤ BTC 熔断时刻集合(双向禁入) =====
[$tsB, , , , $cB] = load_k('btc', '1h');          // 读 BTC 1h 收盘
$meltSet = [];                                    // 熔断小时集合
for ($i = 4; $i < count($cB); $i++) {             // 扫描4根累计跌幅
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {  // 4根累计≤-3%触发
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;  // 触发后4小时双向禁入
    }
}

// ===== ETH K线 =====
[$tsF, $oF, $hF, $lF, $cF] = load_k('eth', '15m');  // 读 ETH 15m 主时间轴
[$ts1, , , , $c1] = load_k('eth', '1h');          // 读 1h 收盘
[$ts4, , , , $c4] = load_k('eth', '4h');          // 读 4h 收盘
$ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5);  // 1h MA20/MA10 与 4h MA5
$ma7 = ma($cF, 7); $ma25 = ma($cF, 25); $ma99 = ma($cF, 99);    // 15m 三均线 MA7/MA25/MA99

function buyratio($o, $h, $l, $c) {               // taker买比代理: 实体位置映射 0~1
    $n = count($c); $br = [];                     // 结果数组
    for ($i = 0; $i < $n; $i++) {                 // 逐根计算
        $rng = $h[$i] - $l[$i];                   // 振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;  // 实体方向比例
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;  // 限幅
        $br[$i] = 0.5 + $dir / 2;                 // 映射到 0~1
    }
    return $br;                                   // 返回买比序列
}
[$ts1b, $o1b, $h1b, $l1b, $c1b] = load_k('eth', '1h');  // 再读1h完整OHLC
$br1h = buyratio($o1b, $h1b, $l1b, $c1b);         // 1h taker买比序列

$nF = count($tsF);                                // 15m 总根数
echo "eth15m bars=$nF span=" . bj($tsF[0]) . " ~ " . bj($tsF[$nF - 1]) . "\n"; flush();  // 输出根数与跨度

// ===== 逐15m判定 多头六重共振 / 空头熊市共振 =====
$cnt = ['sixL' => 0, 'sixS' => 0, 'triL' => 0, 'triS' => 0, 'eL' => 0, 'eS' => 0];  // 各条件命中计数
$p1 = 0; $p4 = 0; $n1 = count($ts1); $n4 = count($ts4);  // 1h/4h游标与根数
$cur20 = null; $cur10 = null; $cur4 = null;       // 当前条件状态(前向填充)
$sixL = array_fill(0, $nF, false); $sixS = array_fill(0, $nF, false);  // 多/空共振布尔序列
for ($i = 30; $i < $nF; $i++) {                   // 逐根判定
    $t = $tsF[$i];                                // 当前时间戳
    while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; }  // 前向推进: ②③条件
    while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; }  // 前向推进: ④条件
    $hb = (int)floor($t / 3600000) * 3600000;     // 对齐整点小时
    if (isset($meltSet[$hb])) continue;                 // 熔断双向禁入   // ⑤ 熔断时段跳过
    $b = ffillBr($t);                             // ① 全市场宽度
    if ($b === null) continue;                    // 宽度无效跳过
    $lo = 0; $hi = $n1 - 1; $ib = -1;             // 二分找对应1h下标
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }  // 标准二分
    if ($ib < 0) continue;                        // 无对应1h跳过
    $br = $br1h[$ib];                             // ⑥ 该1h的taker买比
    if ($b > 0.5 && $cur20 && $cur10 && $cur4 && $br >= 0.5) { $sixL[$i] = true; $cnt['sixL']++; }  // 多头: 宽度>50%+1h>MA20/MA10+4h>MA5+买比≥50%
    if ($b < 0.5 && $cur20 === false && $cur10 === false && $cur4 === false && $br < 0.5) { $sixS[$i] = true; $cnt['sixS']++; }  // 空头: 六条件全部反向
}
// ===== 三均线排列(15m) =====
$triL = array_fill(0, $nF, false); $triS = array_fill(0, $nF, false);  // 多/空三均线排列布尔
for ($i = 0; $i < $nF; $i++) {                    // 逐根判定
    if ($ma7[$i] === null || $ma25[$i] === null || $ma99[$i] === null) continue;  // 均线未就绪跳过
    if ($ma7[$i] > $ma25[$i] && $ma25[$i] > $ma99[$i] && $cF[$i] > $ma7[$i]) { $triL[$i] = true; $cnt['triL']++; }  // 多头排列
    if ($ma7[$i] < $ma25[$i] && $ma25[$i] < $ma99[$i] && $cF[$i] < $ma7[$i]) { $triS[$i] = true; $cnt['triS']++; }  // 空头排列
}
$eL = array_fill(0, $nF, false); $eS = array_fill(0, $nF, false);  // 最终多/空入场信号
for ($i = 30; $i < $nF; $i++) {                   // 合成入场: 共振 AND 排列
    if ($sixL[$i] && $triL[$i]) { $eL[$i] = true; $cnt['eL']++; }  // 多头入场
    if ($sixS[$i] && $triS[$i]) { $eS[$i] = true; $cnt['eS']++; }  // 空头入场
}
echo "sixL={$cnt['sixL']} sixS={$cnt['sixS']} triL={$cnt['triL']} triS={$cnt['triS']} entryL={$cnt['eL']} entryS={$cnt['eS']}\n"; flush();  // 输出各条件与入场计数

// ===== sim: 单账户顺序双向回测 =====
$liqDrop = 1.0 / $LEV - $MMR;                     // 简化爆仓距离 ≈ 0.6%
$eq = $EQ0; $startT = $tsF[30];                   // 权益从500U起/阶梯起点
$trades = []; $i = 30;                            // 成交列表/游标
while ($i < $nF - 1) {                            // 逐根扫描
    $isL = !empty($eL[$i]); $isS = !$isL && !empty($eS[$i]);  // 信号方向(多头优先)
    if (!$isL && !$isS) { $i++; continue; }       // 非信号根跳过
    $side = $isL ? 'LONG' : 'SHORT';              // 方向
    $days = (int)floor((($tsF[$i] - $startT) / 1000) / 86400);  // 已过天数
    $M0 = round(min(1.0 + 3.0 * $days, $CAPM), 2);  // 保证金 = 1U+3U×天数, 封顶50U
    if ($M0 < 1) $M0 = 1.0;                       // 下限1U
    $e = $oF[$i + 1];                             // 下一根15m开盘入场
    $notional = $M0 * $LEV; $avg = $e; $adds = 0; // 名义/均价/加仓计数
    $fee = $notional * $FEE_MAKER; $funding = 0.0;  // 开仓maker费/资金费累计
    if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); }  // 多头爆仓价/止盈价
    else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - $TPR); }  // 空头爆仓价/止盈价
    $outcome = null; $j = $i + 1;                 // 结局/持仓游标
    while ($j < $nF) {                            // 持仓推进
        $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 32;  // 每15m计提资金费(多付/空收)
        $hitLiq = $isL ? ($lF[$j] <= $liqPx) : ($hF[$j] >= $liqPx);  // 触爆仓价判定
        $hitTp  = $isL ? ($hF[$j] >= $tgtPx) : ($lF[$j] <= $tgtPx);  // 触止盈价判定
        if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 爆仓
        if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }  // 止盈
        if ($j - $i >= 7 * 24 * 4) { $outcome = 'TO'; $exitPx = $cF[$j]; break; }  // 持仓超7天超时平仓
        // 加仓: 对应方向三均线排列 + 顺向, 不限轮数, 每轮+1U
        $triOk = $isL ? ($triL[$j] && $cF[$j] > $avg) : ($triS[$j] && $cF[$j] < $avg);  // 方向排列成立+现价在顺向侧
        if ($triOk && $j + 1 < $nF) {             // 满足加仓条件
            $ap = $oF[$j + 1];                    // 加仓价 = 下一根开盘
            if ($ap > 0) {                        // 加仓价有效
                $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);  // 加权新均价
                $notional += $ADDU * $LEV; $adds++;  // 名义+1U×杠杆, 计数+1
                $fee += $ADDU * $LEV * $FEE_TAKER;   // 加仓taker费
                if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); }  // 重算多头爆仓/止盈价
                else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - $TPR); }  // 重算空头爆仓/止盈价
            }
        }
        $j++;                                     // 推进下一根
    }
    if ($outcome === null) { $outcome = 'TO'; $j = $nF - 1; $exitPx = $cF[$j]; }  // 数据耗尽按最后收盘平仓
    $gross = $isL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);  // 毛盈亏(方向相关)
    $fee += $notional * $FEE_TAKER;               // 平仓taker费
    $pnl = $gross - $fee - $funding;              // 净盈亏
    $mg = $M0 + $adds * $ADDU;                    // 该笔总保证金
    if ($pnl < -$mg) $pnl = -$mg;                 // 亏损封顶到总保证金
    $eq += $pnl;                                  // 入账
    $trades[] = ['side' => $side, 'tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds,  // 记录成交明细
                 'm0' => $M0, 'mg' => $mg, 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),  // 保证金/总保证金/盈亏/权益
                 'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];  // 持仓小时数
    $i = $j + 1;                                  // 跳到出场后一根
}

function summarize($trades, $eqEnd) {             // 成交列表汇总
    $tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'adds' => 0, 'pnl' => 0.0];  // 统计容器
    foreach ($trades as $x) {                     // 逐笔统计
        if ($x['out'] == 'WIN') $tot['win']++;    // 止盈笔数
        if ($x['out'] == 'LIQ') $tot['liq']++;    // 爆仓笔数
        if ($x['out'] == 'TO') $tot['to']++;      // 超时笔数
        $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl'];  // 累计加仓与盈亏
    }
    $months = [];                                 // 月度统计
    foreach ($trades as $x) {                     // 逐笔聚合
        $m = gmdate('Y-m', (int)($x['tout'] / 1000) + 8 * 3600);  // 出场月份(北京时间)
        $mo = &$months[$m];                       // 引用该月聚合行
        $mo['n'] = ($mo['n'] ?? 0) + 1;           // 月笔数
        $mo['win'] = ($mo['win'] ?? 0) + ($x['out'] == 'WIN' ? 1 : 0);  // 月止盈数
        $mo['liq'] = ($mo['liq'] ?? 0) + ($x['out'] == 'LIQ' ? 1 : 0);  // 月爆仓数
        $mo['adds'] = ($mo['adds'] ?? 0) + $x['adds'];  // 月加仓总数
        $mo['pnl'] = round(($mo['pnl'] ?? 0) + $x['pnl'], 2);  // 月盈亏
        $mo['eq_end'] = $x['eq'];                 // 月末权益
        unset($mo);                               // 解除引用
    }
    $peak = -INF; $maxdd = 0;                     // 峰值/最大回撤
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }  // 逐笔更新
    return ['summary' => $tot, 'eq_end' => round($eqEnd, 2), 'maxdd' => round($maxdd, 2), 'months' => $months, 'trades' => $trades];  // 返回汇总
}

$trL = array_values(array_filter($trades, fn($x) => $x['side'] == 'LONG'));   // 多头成交
$trS = array_values(array_filter($trades, fn($x) => $x['side'] == 'SHORT'));  // 空头成交
$all = summarize($trades, $eq);                   // 全部汇总
$L = summarize($trL, 0); $S = summarize($trS, 0); // 多/空分别汇总
printf("ALL n=%d win=%d liq=%d pnl=%.1f eqEnd=%.1f maxdd=%.1f\n", $all['summary']['n'], $all['summary']['win'], $all['summary']['liq'], $all['summary']['pnl'], $all['eq_end'], $all['maxdd']);  // 打印全部统计
printf("LONG n=%d win=%d liq=%d pnl=%.1f | SHORT n=%d win=%d liq=%d pnl=%.1f\n",  // 打印多空分项
    $L['summary']['n'], $L['summary']['win'], $L['summary']['liq'], $L['summary']['pnl'],  // 多头统计
    $S['summary']['n'], $S['summary']['win'], $S['summary']['liq'], $S['summary']['pnl']);  // 空头统计

$out = [                                          // 组装输出 JSON
    'meta' => [                                   // 元信息
        'inst' => 'ETH-USDT-SWAP', 'lev' => $LEV, 'tp' => '±2%', 'eq0' => $EQ0,  // 品种/杠杆/止盈/起始权益
        'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF, 'cond' => $cnt,  // 数据跨度/根数/条件计数
        'params' => '牛市买多=六重共振(全市场1D MA20宽度>50%/ETH 1h>MA20/1h>MA10/4h>MA5/taker买比≥50%/BTC熔断禁入) AND 15m三均线多头排列(MA7>MA25>MA99且收>MA7); 熊市卖空=六条件全部反向 AND 15m三均线空头排列(MA7<MA25<MA99且收<MA7); 加仓双向同=对应方向三均线排列+顺向(多:现价>均价/空:现价<均价) 不限轮数 每轮+1U 无仓位上限 | 保证金=1U+3U×已过天数 封顶50U | 止盈=价格±2%(自均价) | 不设止损·爆仓线±0.6%@100x照模拟 | 超时7天 | 资金费=多头付/空头收 0.01%/8h(简化假设) | 净口径含手续费+资金费 | 起始500U 单账户顺序',  // 完整参数说明
    ],
    'ALL' => $all, 'LONG' => $L, 'SHORT' => $S,   // 全部/多头/空头汇总
];
file_put_contents('E:/finally-main/web/sixshort_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出报告 JSON
echo "DATA OK\n";                                 // 完成提示
