<?php
/**
 * eth_sixflip.php — 六重共振+三均线 牛熊双向 · 50%保证金亏损反转型 回测(CLI, 2026-09-26)
 * 用户: "爆仓=保证金的50%亏 自己设置这个条件让他不爆仓 直接换成反向做多或者做空, 然后还是止盈±2% 还是加仓 止盈 试一下是否可以赚更多"
 *   入场: 牛市=六重共振 AND 15m三均线多头排列→做多; 熊市=六条件全部反向 AND 三均线空头排列→做空 (与 eth_sixshort 完全同参)
 *   加仓: 对应方向三均线排列+顺向, 不限轮数, 每轮+1U, 无仓位上限
 *   保证金: 链首笔=1U+3U×已过天数 封顶50U; 翻转腿继承当前总保证金(含加仓)
 *   风控: 不爆仓 → 浮亏达当前总保证金的50%(100x=价格逆向0.5%)立即市价平掉, 原地反向开仓(同保证金), 新腿继续止盈±2%+加仓, 可连续翻转
 *   止盈: 价格±2%(自当腿均价) | 超时: 链首笔起7天平仓 | 资金费: 多付/空收 0.01%/8h | 翻转开平均taker
 *   同根K线先判翻转(悲观序); 翻转后跳到下一根K线再判新腿(避免同根反复翻转死循环)
 * 输出: web/sixflip_data.json (对照基线 = eth_sixshort 爆仓版 +6,442.8U)
 */
ini_set('memory_limit', '2048M');                 // 内存上限 2G
set_time_limit(0);                                // 取消执行时间限制
$LEV = 100; $TPR = 0.02; $FLIP = 0.005; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆/止盈2%/翻转阈值0.5%(=保证金50%)/费率/资金费率
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

// ===== 全局① 全市场 1D MA20 宽度 =====
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

// ===== 全局⑤ BTC 熔断 =====
[$tsB, , , , $cB] = load_k('btc', '1h');          // 读 BTC 1h 收盘
$meltSet = [];                                    // 熔断小时集合
for ($i = 4; $i < count($cB); $i++) {             // 扫描4根累计跌幅
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {  // 4根累计≤-3%触发
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;  // 触发后4小时双向禁入
    }
}
unset($rr, $tsB, $cB);                            // 释放内存

// ===== ETH K线 =====
[$tsF, $oF, $hF, $lF, $cF] = load_k('eth', '15m');  // 读 ETH 15m 主时间轴
[$ts1, , , , $c1] = load_k('eth', '1h');          // 读 ETH 1h 收盘
[$ts4, , , , $c4] = load_k('eth', '4h');          // 读 ETH 4h 收盘
$ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5);  // 1h MA20/MA10 与 4h MA5
$ma7 = ma($cF, 7); $ma25 = ma($cF, 25); $ma99 = ma($cF, 99);    // 15m 三均线 MA7/MA25/MA99
[$ts1b, $o1b, $h1b, $l1b, $c1b] = load_k('eth', '1h');  // 再读1h完整OHLC
$br1h = buyratio($o1b, $h1b, $l1b, $c1b);         // 1h taker买比序列

$nF = count($tsF);                                // 15m 总根数
echo "eth15m bars=$nF span=" . bj($tsF[0]) . " ~ " . bj($tsF[$nF - 1]) . "\n"; flush();  // 输出根数与数据跨度

// ===== 六条件状态 + 三均线 =====
$p1 = 0; $p4 = 0; $n1 = count($ts1); $n4 = count($ts4);  // 1h/4h游标与根数
$cur20 = null; $cur10 = null; $cur4 = null;       // 当前条件状态(前向填充)
$sixL = array_fill(0, $nF, false); $sixS = array_fill(0, $nF, false);  // 每根15m的多/空六条件布尔
for ($i = 30; $i < $nF; $i++) {                   // 跳过预热期逐根判定
    $t = $tsF[$i];                                // 当前时间戳
    while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; }  // 前向推进: 更新 ②③条件
    while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; }  // 前向推进: 更新 ④条件
    $hb = (int)floor($t / 3600000) * 3600000;     // 对齐到整点小时
    if (isset($meltSet[$hb])) continue;           // ⑤ 熔断时段跳过
    $b = ffillBr($t);                             // ① 全市场宽度
    if ($b === null) continue;                    // 宽度无效跳过
    $lo = 0; $hi = $n1 - 1; $ib = -1;             // 二分找对应1h下标
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }  // 标准二分
    if ($ib < 0) continue;                        // 无对应1h跳过
    $br = $br1h[$ib];                             // ⑥ 该1h的taker买比
    if ($b > 0.5 && $cur20 === true && $cur10 === true && $cur4 === true && $br >= 0.5) $sixL[$i] = true;  // 多头六条件全命中
    if ($b < 0.5 && $cur20 === false && $cur10 === false && $cur4 === false && $br < 0.5) $sixS[$i] = true;  // 空头六条件全反向命中
}
$triL = array_fill(0, $nF, false); $triS = array_fill(0, $nF, false);  // 三均线多/空排列布尔
for ($i = 0; $i < $nF; $i++) {                    // 逐根判定三均线
    if ($ma7[$i] === null || $ma25[$i] === null || $ma99[$i] === null) continue;  // 均线未就绪跳过
    if ($ma7[$i] > $ma25[$i] && $ma25[$i] > $ma99[$i] && $cF[$i] > $ma7[$i]) $triL[$i] = true;  // 多头排列: MA7>MA25>MA99 且收盘>MA7
    if ($ma7[$i] < $ma25[$i] && $ma25[$i] < $ma99[$i] && $cF[$i] < $ma7[$i]) $triS[$i] = true;  // 空头排列: 反向
}
$eL = array_fill(0, $nF, false); $eS = array_fill(0, $nF, false);  // 最终入场信号
$cnt = ['eL' => 0, 'eS' => 0];                    // 多/空入场计数
for ($i = 30; $i < $nF; $i++) {                   // 合成入场: 六重共振 AND 三均线
    if ($sixL[$i] && $triL[$i]) { $eL[$i] = true; $cnt['eL']++; }  // 多头入场
    if ($sixS[$i] && $triS[$i]) { $eS[$i] = true; $cnt['eS']++; }  // 空头入场
}
echo "entryL={$cnt['eL']} entryS={$cnt['eS']}\n"; flush();  // 输出多空入场信号数

// ===== sim: 单账户顺序双向, 50%保证金亏损反转 =====
$eq = $EQ0; $startT = $tsF[30];                   // 权益从500U起/阶梯起点
$trades = []; $i = 30; $dead = false;             // 成交列表/游标/爆仓标志
while ($i < $nF - 1 && !$dead) {                  // 主循环(爆仓即停)
    $isL = !empty($eL[$i]); $isS = !$isL && !empty($eS[$i]);  // 本根信号方向(多头优先)
    if (!$isL && !$isS) { $i++; continue; }       // 非信号根跳过
    if ($i + 1 >= $nF || $oF[$i + 1] <= 0) { $i++; continue; }  // 脏数据跳过
    $days = (int)floor((($tsF[$i] - $startT) / 1000) / 86400);  // 已过天数
    $M0 = round(min(1.0 + 3.0 * $days, $CAPM), 2);  // 链首笔保证金 = 1U+3U×天数, 封顶50U
    if ($M0 < 1) $M0 = 1.0;                       // 下限1U
    $legL = $isL;                       // 当腿方向   // 当前腿方向(翻转时切换)
    $mg = $M0;                          // 链内继承总保证金(含加仓)   // 链内总保证金
    $avg = $oF[$i + 1];                 // 当腿均价 = 下一根开盘
    $notional = $mg * $LEV;             // 名义仓位 = 保证金×杠杆
    $legFee = $notional * $FEE_MAKER;   // 首笔开仓 maker   // 开仓手续费(maker)
    $legFunding = 0.0;                  // 本腿资金费累计
    $chainStart = $i;                   // 链首下标(超时/持仓时长基准)
    $j = $i + 1;                        // 持仓游标
    while ($j < $nF) {                  // 逐根推进
        if ($hF[$j] <= 0 || $lF[$j] <= 0) { $j++; continue; }  // 脏数据跳过
        $legFunding += ($legL ? 1 : -1) * $notional * $FUND8H / 32;  // 每15m计提资金费(多付/空收)
        if ($legL) { $hitFlip = $lF[$j] <= $avg * (1 - $FLIP); $hitTp = $hF[$j] >= $avg * (1 + $TPR); }  // 多腿: 跌0.5%触发翻转/涨2%止盈
        else       { $hitFlip = $hF[$j] >= $avg * (1 + $FLIP); $hitTp = $lF[$j] <= $avg * (1 - $TPR); }  // 空腿: 涨0.5%触发翻转/跌2%止盈
        // 同根K线先判翻转(悲观序)
        if ($hitFlip) {                 // 触发翻转: 平掉当前腿反向开仓
            $exitPx = $legL ? $avg * (1 - $FLIP) : $avg * (1 + $FLIP);  // 翻转平仓价
            $gross = $legL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);  // 本腿毛盈亏
            $legFee += $notional * $FEE_TAKER;   // 翻转平仓 taker   // 平仓手续费(taker)
            $pnl = $gross - $legFee - $legFunding;  // 本腿净盈亏
            if ($pnl < -$mg) $pnl = -$mg;   // 亏损封顶到总保证金(不爆仓)
            $eq += $pnl;                    // 入账
            if ($eq <= 0) { $dead = true; } // 权益归零 → 账户死亡
            $trades[] = ['side' => $legL ? 'LONG' : 'SHORT', 'tin' => $tsF[$chainStart], 'tout' => $tsF[$j],  // 记录翻转腿成交
                         'out' => 'FLIP', 'adds' => 0, 'm0' => $M0, 'mg' => $mg,  // 结局=FLIP
                         'pnl' => round($pnl, 3), 'eq' => round($eq, 2),  // 盈亏/权益
                         'holdh' => round(($tsF[$j] - $tsF[max($chainStart, $j - 96 * 7)]) / 3600000.0, 1)];  // 持仓时长(限制最大7天窗口)
            if ($dead) { $j++; break; }     // 已死亡退出
            // 原地反向开仓(继承保证金)
            $legL = !$legL;                 // 方向取反
            $avg = $exitPx;                 // 新腿均价 = 翻转平仓价
            $notional = $mg * $LEV;         // 新腿名义(继承总保证金)
            $legFee = $notional * $FEE_TAKER;    // 反向开仓 taker   // 新腿开仓手续费(taker)
            $legFunding = 0.0;              // 新腿资金费清零
            $j++;                                 // 新腿从下一根K线再判   // 跳过同根防止反复翻转死循环
            continue;                       // 继续新腿判定
        }
        if ($hitTp) {                       // 止盈出场
            $exitPx = $legL ? $avg * (1 + $TPR) : $avg * (1 - $TPR);  // 止盈价
            $gross = $legL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);  // 毛盈亏
            $legFee += $notional * $FEE_TAKER;  // 平仓手续费
            $pnl = $gross - $legFee - $legFunding;  // 净盈亏
            if ($pnl < -$mg) $pnl = -$mg;   // 亏损封顶
            $eq += $pnl;                    // 入账
            $trades[] = ['side' => $legL ? 'LONG' : 'SHORT', 'tin' => $tsF[$chainStart], 'tout' => $tsF[$j],  // 记录止盈成交
                         'out' => 'WIN', 'adds' => 0, 'm0' => $M0, 'mg' => $mg,  // 结局=WIN
                         'pnl' => round($pnl, 3), 'eq' => round($eq, 2),  // 盈亏/权益
                         'holdh' => round(($tsF[$j] - $tsF[$chainStart]) / 3600000.0, 1)];  // 持仓时长
            break;                          // 链结束
        }
        if ($j - $chainStart >= 7 * 24 * 4) {  // 链首起超7天(15m×672根)
            $exitPx = $cF[$j];              // 按收盘超时平仓
            $gross = $legL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);  // 毛盈亏
            $legFee += $notional * $FEE_TAKER;  // 平仓手续费
            $pnl = $gross - $legFee - $legFunding;  // 净盈亏
            if ($pnl < -$mg) $pnl = -$mg;   // 亏损封顶
            $eq += $pnl;                    // 入账
            $trades[] = ['side' => $legL ? 'LONG' : 'SHORT', 'tin' => $tsF[$chainStart], 'tout' => $tsF[$j],  // 记录超时成交
                         'out' => 'TO', 'adds' => 0, 'm0' => $M0, 'mg' => $mg,  // 结局=TO
                         'pnl' => round($pnl, 3), 'eq' => round($eq, 2),  // 盈亏/权益
                         'holdh' => round(($tsF[$j] - $tsF[$chainStart]) / 3600000.0, 1)];  // 持仓时长
            break;                          // 链结束
        }
        // 加仓: 当腿方向三均线排列 + 顺向, 不限轮数, 每轮+1U
        $triOk = $legL ? ($triL[$j] && $cF[$j] > $avg) : ($triS[$j] && $cF[$j] < $avg);  // 当腿三均线排列成立+现价在顺向侧
        if ($triOk && $j + 1 < $nF && $oF[$j + 1] > 0) {  // 满足加仓条件
            $ap = $oF[$j + 1];              // 加仓价 = 下一根开盘
            $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);  // 加权新均价
            $notional += $ADDU * $LEV; $mg += $ADDU;  // 名义+1U×杠杆, 总保证金+1U
            $legFee += $ADDU * $LEV * $FEE_TAKER;  // 加仓手续费
        }
        $j++;                               // 推进下一根
    }
    if ($j >= $nF && empty($trades) || (end($trades)['tin'] == $tsF[$chainStart] && end($trades)['tout'] < $tsF[$j])) {  // 链未闭合的边界检查(保留原逻辑)
        // 链未闭合(K线尽头)——按超时收尾
    }
    $i = max($j, $chainStart + 1);          // 游标推进到链结束后(至少前进一步)
}

// ===== 汇总 =====
function summarize($trades, $eqEnd) {       // 成交列表汇总
    $tot = ['n' => count($trades), 'win' => 0, 'flip' => 0, 'to' => 0, 'adds' => 0, 'pnl' => 0.0];  // 统计容器
    foreach ($trades as $x) {               // 逐笔统计
        if ($x['out'] == 'WIN') $tot['win']++;    // 止盈笔数
        if ($x['out'] == 'FLIP') $tot['flip']++;  // 翻转笔数
        if ($x['out'] == 'TO') $tot['to']++;      // 超时笔数
        $tot['pnl'] += $x['pnl'];           // 累计盈亏
    }
    $months = [];                           // 月度统计
    foreach ($trades as $x) {               // 逐笔聚合
        $m = gmdate('Y-m', (int)($x['tout'] / 1000) + 8 * 3600);  // 出场月份(北京时间)
        $mo = &$months[$m];                 // 引用该月聚合行
        $mo['n'] = ($mo['n'] ?? 0) + 1;     // 月笔数
        $mo['win'] = ($mo['win'] ?? 0) + ($x['out'] == 'WIN' ? 1 : 0);    // 月止盈数
        $mo['flip'] = ($mo['flip'] ?? 0) + ($x['out'] == 'FLIP' ? 1 : 0); // 月翻转数
        $mo['pnl'] = round(($mo['pnl'] ?? 0) + $x['pnl'], 2);  // 月盈亏
        $mo['eq_end'] = $x['eq'];           // 月末权益
        unset($mo);                         // 解除引用
    }
    $peak = -INF; $maxdd = 0;               // 峰值/最大回撤
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }  // 逐笔更新
    return ['summary' => $tot, 'eq_end' => round($eqEnd, 2), 'maxdd' => round($maxdd, 2), 'months' => $months, 'trades' => $trades];  // 返回汇总
}
$trL = array_values(array_filter($trades, fn($x) => $x['side'] == 'LONG'));   // 多头腿成交
$trS = array_values(array_filter($trades, fn($x) => $x['side'] == 'SHORT'));  // 空头腿成交
$all = summarize($trades, $eq);           // 全部汇总
$L = summarize($trL, 0); $S = summarize($trS, 0);  // 多/空分别汇总
printf("ALL n=%d win=%d flip=%d to=%d pnl=%.1f eqEnd=%.1f maxdd=%.1f dead=%d\n",  // 打印全部统计
    $all['summary']['n'], $all['summary']['win'], $all['summary']['flip'], $all['summary']['to'], $all['summary']['pnl'], $all['eq_end'], $all['maxdd'], $dead ? 1 : 0);  // 各项
printf("LONG n=%d win=%d flip=%d pnl=%.1f | SHORT n=%d win=%d flip=%d pnl=%.1f\n",  // 打印多空分项
    $L['summary']['n'], $L['summary']['win'], $L['summary']['flip'], $L['summary']['pnl'],  // 多头统计
    $S['summary']['n'], $S['summary']['win'], $S['summary']['flip'], $S['summary']['pnl']);  // 空头统计

$out = [                                  // 组装输出 JSON
    'meta' => [                           // 元信息
        'inst' => 'ETH-USDT-SWAP', 'lev' => $LEV, 'tp' => '±2%', 'eq0' => $EQ0, 'flip_at' => '保证金50%(价格0.5%)',  // 品种/杠杆/止盈/起始权益/翻转阈值
        'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF, 'cond' => $cnt,  // 数据跨度/根数/入场条件计数
        'params' => '入场: 牛市=六重共振 AND 15m三均线多头排列→做多; 熊市=六条件全部反向 AND 三均线空头排列→做空(与爆仓版基线同参) | 加仓=当腿方向三均线排列+顺向 不限轮数 每轮+1U | 保证金=链首笔1U+3U×已过天数封顶50U, 翻转腿继承当前总保证金(含加仓) | 风控=不爆仓: 浮亏达总保证金50%(价格逆向0.5%)立即平掉并原地反向开仓, 新腿继续止盈±2%+加仓, 可连续翻转 | 止盈=价格±2%(自当腿均价) | 超时=链首笔起7天 | 资金费=多付/空收0.01%/8h | 同根K线先判翻转(悲观序), 翻转后下一根K线起判新腿 | 净口径含手续费+资金费 | 起始500U 单账户顺序',  // 完整参数说明
    ],
    'ALL' => $all, 'LONG' => $L, 'SHORT' => $S, 'dead' => $dead,  // 全部/多头/空头汇总与爆仓标志
];
file_put_contents('E:/finally-main/web/sixflip_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出报告 JSON
echo "DATA OK\n";                         // 完成提示
