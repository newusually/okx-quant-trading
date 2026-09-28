<?php
/**
 * eth_sixma.php — ETH 六重共振×三均线多头排列 买入+加仓 一年回测(CLI, 2026-09-26 用户指令)
 * 用户: "六重共振 triple_ma_bull 三均线多头排列(7>25>99) 买入和加仓都给我上 ETH合约
 *        不固定加仓仓位 只要能明确上涨就给我加 不设置爆仓止损 给我一年回测"
 *   买入 = 六重共振全命中 AND 15m三均线多头排列(MA7>MA25>MA99且收>MA7)
 *   加仓 = 不固定仓位(无50U封顶/不限轮数/每次+1U), 只要"明确上涨"就加:
 *          A组 明确上涨=收盘创开仓以来新高(且排列仍成立)
 *          B组 明确上涨=现价>持仓均价(且排列仍成立)
 *          C组 对照=裸三均线排列买入(无六共振), 加仓同A(验证六共振贡献)
 *   平仓 = 三均线多头排列破坏(MA7<MA25 或 MA25<MA99) 收盘价全平; 不设止盈/止损/爆仓(穿-0.6%不平仓照持有)
 *   净口径: 开仓maker费/加仓taker费/平仓taker费 + 资金费率0.01%/8h; 起始500U 逐笔结转
 * 输出: web/sixma_data.json
 */
ini_set('memory_limit', '2048M');                 // 内存上限 2G
set_time_limit(0);                                // 取消执行时间限制
$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆/维持保证金率/费率/资金费率
$EQ0 = 500.0; $ADDU = 1.0;                        // 起始权益500U/每轮加仓1U

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库
$DB = db();                                       // 建立全局连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行 SQL 返回全部行
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }  // 滑动均线 MA
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }  // 毫秒时间戳转北京时间(到分)
function bjd($ms) { return gmdate('Y-m-d', (int)($ms / 1000) + 8 * 3600); }     // 毫秒时间戳转北京时间(到日)

function load_k($inst, $bar) {                    // 读取 K线表(o/h/l/c 列)
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按 candle_time 升序
    $ts = []; $o = []; $h = []; $l = []; $c = []; // 时间戳与 OHLC
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }  // 类型转换装入
    return [$ts, $o, $h, $l, $c];                 // 返回五元组
}

// ===== ① 全市场 1D MA20 宽度(六重共振条件1) =====
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

// ===== ⑤ BTC 熔断时刻集合 =====
[$tsB, , , , $cB] = load_k('btc', '1h');          // 读 BTC 1h 收盘
$meltSet = [];                                    // 熔断小时集合
for ($i = 4; $i < count($cB); $i++) {             // 扫描4根累计跌幅
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {  // 4根累计≤-3%触发
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;  // 触发后4小时禁入
    }
}

// ===== ETH K线: 15m 主时间轴 + 1h/4h 条件 =====
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

// ===== 逐15m判定六重共振 =====
$cnt = ['six' => 0, 'tri' => 0, 'six_and_tri' => 0];  // 各条件命中计数
$p1 = 0; $p4 = 0; $n1 = count($ts1); $n4 = count($ts4);  // 1h/4h游标与根数
$cur20 = null; $cur10 = null; $cur4 = null;       // 当前条件状态(前向填充)
$six = array_fill(0, $nF, false);                 // 六重共振布尔序列
for ($i = 30; $i < $nF; $i++) {                   // 逐根判定
    $t = $tsF[$i];                                // 当前时间戳
    while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; }  // 前向推进: ②③条件
    while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; }  // 前向推进: ④条件
    $hb = (int)floor($t / 3600000) * 3600000;     // 对齐整点小时
    if (isset($meltSet[$hb])) continue;           // ⑤ 熔断时段跳过
    $b = ffillBr($t);                             // ① 全市场宽度
    if ($b === null || $b <= 0.5) continue;       // ① 宽度须>50%
    if (!$cur20) continue;                        // ② 1h须在MA20上方
    if (!$cur10) continue;                        // ③ 1h须在MA10上方
    if (!$cur4) continue;                         // ④ 4h须在MA5上方
    $lo = 0; $hi = $n1 - 1; $ib = -1;             // 二分找对应1h下标
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }  // 标准二分
    if ($ib < 0 || $br1h[$ib] < 0.5) continue;    // ⑥ taker买比须≥50%
    $six[$i] = true; $cnt['six']++;               // 六条件全命中
}
// ===== 三均线多头排列(15m) =====
$tri = array_fill(0, $nF, false);                 // 三均线排列布尔序列
for ($i = 0; $i < $nF; $i++) {                    // 逐根判定
    $tri[$i] = ($ma7[$i] !== null && $ma25[$i] !== null && $ma99[$i] !== null)  // 均线就绪
        && ($ma7[$i] > $ma25[$i] && $ma25[$i] > $ma99[$i] && $cF[$i] > $ma7[$i]);  // MA7>MA25>MA99 且收盘>MA7
    if ($tri[$i]) $cnt['tri']++;                  // 排列命中计数
    if ($six[$i] && $tri[$i]) $cnt['six_and_tri']++;  // 双条件同时命中计数
}
echo "six=" . $cnt['six'] . " tri=" . $cnt['tri'] . " six_and_tri=" . $cnt['six_and_tri'] . "\n"; flush();  // 输出计数

/**
 * sim — 单仓位顺序回测
 * $mode: 'NH'=加仓需收盘创开仓以来新高 | 'AVG'=加仓需现价>均价
 * $useSix: 买入是否要求六重共振
 * 不设止盈/止损/爆仓; 平仓=排列破坏(收盘判定) 全平
 */
function sim($entry, $mode, $useSix, $tsF, $oF, $hF, $lF, $cF, $tri, $six) {  // 核心模拟函数
    global $LEV, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $EQ0, $ADDU;  // 引用全局参数
    $eq = $EQ0; $nF = count($tsF);                // 权益从500U起/15m根数
    $trades = []; $i = 30;                        // 成交列表/游标
    while ($i < $nF - 1) {                        // 逐根扫描
        $okEntry = $tri[$i] && (!$useSix || $six[$i]);  // 入场: 三均线排列成立, useSix时还须六重共振
        if (!$okEntry) { $i++; continue; }        // 非信号根跳过
        $e = $oF[$i + 1];                         // 下一根开盘入场
        if ($e <= 0) { $i++; continue; }          // 脏数据跳过
        $notional = $ADDU * $LEV; $avg = $e; $adds = 0;  // 名义=1U×杠杆/均价/加仓计数
        $fee = $notional * $FEE_MAKER; $funding = 0.0;   // 开仓maker费/资金费累计
        $hiSince = $e; $outcome = null; $j = $i + 1;  // 开仓以来最高价/结局/游标
        while ($j < $nF) {                        // 持仓推进
            $funding += $notional * $FUND8H / 32; // 每15m计提资金费(8h的1/32)
            // 平仓: 三均线多头排列破坏(收盘判定)
            if (!$tri[$j]) { $outcome = 'BRK'; $exitPx = $cF[$j]; break; }  // 排列破坏 → 收盘全平
            // 加仓: 排列仍成立 + 明确上涨, 不限轮数 无仓位上限 每轮+1U
            $up = ($mode === 'NH') ? ($cF[$j] > $hiSince) : ($cF[$j] > $avg);  // 明确上涨: NH=创新高 / AVG=现价>均价
            if ($up && $j + 1 < $nF) {            // 满足加仓条件
                $ap = $oF[$j + 1];                // 加仓价 = 下一根开盘
                if ($ap > 0) {                    // 加仓价有效
                    $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);  // 加权新均价
                    $notional += $ADDU * $LEV; $adds++;  // 名义+1U×杠杆, 加仓计数+1
                    $fee += $ADDU * $LEV * $FEE_TAKER;   // 加仓taker费
                }
            }
            if ($cF[$j] > $hiSince) $hiSince = $cF[$j];  // 更新开仓以来最高收盘
            $j++;                                 // 推进下一根
        }
        if ($outcome === null) { $outcome = 'END'; $j = $nF - 1; $exitPx = $cF[$j]; }  // 数据耗尽按最后收盘平仓
        $gross = ($exitPx - $avg) * $notional / $avg;  // 毛盈亏
        $fee += $notional * $FEE_TAKER;           // 平仓taker费
        $pnl = $gross - $fee - $funding;   // 无爆仓: 盈亏不封底   // 净盈亏(无爆仓封底)
        $eq += $pnl;                              // 入账
        $trades[] = ['tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds,  // 记录成交
                     'mg' => round($ADDU + $adds * $ADDU, 2), 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),  // 总保证金/盈亏/权益
                     'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];  // 持仓小时数
        $i = $j + 1;                              // 跳到出场后一根
    }
    // 汇总
    $tot = ['n' => count($trades), 'win' => 0, 'brk' => 0, 'adds' => 0, 'pnl' => 0.0, 'maxmg' => 0.0];  // 统计容器
    foreach ($trades as $x) {                     // 逐笔统计
        if ($x['pnl'] > 0) $tot['win']++;         // 盈利笔数
        if ($x['out'] == 'BRK') $tot['brk']++;    // 排列破坏平仓笔数
        $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl'];  // 累计加仓与盈亏
        if ($x['mg'] > $tot['maxmg']) $tot['maxmg'] = $x['mg'];  // 最大单笔保证金
    }
    $tot['pnl'] = round($tot['pnl'], 2);          // 盈亏保留两位
    // 月度
    $months = [];                                 // 月度统计
    foreach ($trades as $x) {                     // 逐笔聚合
        $m = gmdate('Y-m', (int)($x['tout'] / 1000) + 8 * 3600);  // 出场月份(北京时间)
        $mo = &$months[$m];                       // 引用该月聚合行
        $mo['n'] = ($mo['n'] ?? 0) + 1;           // 月笔数
        $mo['win'] = ($mo['win'] ?? 0) + ($x['pnl'] > 0 ? 1 : 0);  // 月盈利笔数
        $mo['adds'] = ($mo['adds'] ?? 0) + $x['adds'];  // 月加仓总数
        $mo['pnl'] = round(($mo['pnl'] ?? 0) + $x['pnl'], 2);  // 月盈亏
        $mo['eq_end'] = $x['eq'];                 // 月末权益
        unset($mo);                               // 解除引用
    }
    // 日权益曲线(含空日结转)
    $daily = [];                                  // 日权益表
    $day = null; $lastEq = $EQ0; $peak = -INF; $maxdd = 0;  // 当前日/最近权益/峰值/最大回撤
    foreach ($trades as $x) {                     // 逐笔构建曲线
        $d = bjd($x['tout']);                     // 出场日期
        if ($day !== null && $d !== $day) { $daily[$day] = $lastEq; }  // 日期切换时把上一日权益落盘
        $day = $d; $lastEq = $x['eq'];            // 更新当前日与权益
        if ($x['eq'] > $peak) $peak = $x['eq'];   // 更新峰值
        $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd;  // 更新最大回撤
    }
    if ($day !== null) $daily[$day] = $lastEq;    // 最后一日落盘
    ksort($daily);                                // 按日期排序
    return ['summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),  // 返回汇总/期末权益/回撤
            'months' => $months, 'daily' => $daily, 'trades' => $trades];  // 月度/日曲线/明细
}

$entryST = array_fill(0, $nF, false);  // 六共振+排列   // A/B组入场信号
$entryT  = array_fill(0, $nF, false);  // 裸排列   // C组入场信号(对照)
for ($i = 30; $i < $nF; $i++) {                   // 合成入场集合
    if ($six[$i] && $tri[$i]) $entryST[$i] = true;  // 六重共振 AND 三均线排列
    if ($tri[$i]) $entryT[$i] = true;          // 仅三均线排列
}
echo "entryST=" . array_sum($entryST) . " entryT=" . array_sum($entryT) . "\n"; flush();  // 输出两组信号数
$mST = []; $mS = [];                              // 月度信号统计
for ($i = 30; $i < $nF; $i++) { $m = gmdate('Y-m', (int)($tsF[$i] / 1000) + 8 * 3600); if ($six[$i]) $mS[$m] = ($mS[$m] ?? 0) + 1; if ($entryST[$i]) $mST[$m] = ($mST[$m] ?? 0) + 1; }  // 按月计数六共振与双条件信号
ksort($mS); ksort($mST);                          // 按月份排序
echo "six/mo: " . implode(' ', array_map(fn($k, $v) => "$k=$v", array_keys($mS), $mS)) . "\n";  // 打印六共振月度分布
echo "six+tri/mo: " . implode(' ', array_map(fn($k, $v) => "$k=$v", array_keys($mST), $mST)) . "\n"; flush();  // 打印双条件月度分布

$resA = sim($entryST, 'NH',  true,  $tsF, $oF, $hF, $lF, $cF, $tri, $six);  // A组: 六共振入场+创新高加仓
echo "A(NH,six) n=" . $resA['summary']['n'] . " pnl=" . $resA['summary']['pnl'] . " eqEnd=" . $resA['eq_end'] . "\n"; flush();  // 输出A组结果
$resB = sim($entryST, 'AVG', true,  $tsF, $oF, $hF, $lF, $cF, $tri, $six);  // B组: 六共振入场+现价>均价加仓
echo "B(AVG,six) n=" . $resB['summary']['n'] . " pnl=" . $resB['summary']['pnl'] . " eqEnd=" . $resB['eq_end'] . "\n"; flush();  // 输出B组结果
$resC = sim($entryT,  'NH',  false, $tsF, $oF, $hF, $lF, $cF, $tri, $six);  // C组: 裸三均线入场(对照)
echo "C(NH,bare) n=" . $resC['summary']['n'] . " pnl=" . $resC['summary']['pnl'] . " eqEnd=" . $resC['eq_end'] . "\n"; flush();  // 输出C组结果

$out = [                                          // 组装输出 JSON
    'meta' => [                                   // 元信息
        'inst' => 'ETH-USDT-SWAP', 'lev' => $LEV, 'eq0' => $EQ0,  // 品种/杠杆/起始权益
        'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF, 'cond' => $cnt,  // 数据跨度/根数/条件计数
        'params' => '买入=六重共振全命中 AND 15m三均线多头排列(MA7>MA25>MA99且收>MA7) | 加仓=不固定仓位(无封顶/不限轮数/每轮+1U), 明确上涨即加: A=收盘创开仓以来新高 B=现价>持仓均价 (排列仍成立时) | 平仓=三均线多头排列破坏收盘全平 | 不设止盈/止损/爆仓(穿-0.6%不平仓照持有) | C组对照=裸三均线买入(无六共振) | 净口径含手续费+资金费 | 起始500U | 100x',  // 完整参数说明
    ],
    'A' => $resA, 'B' => $resB, 'C' => $resC,     // 三组完整结果
];
file_put_contents('E:/finally-main/web/sixma_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出报告 JSON
echo "DATA OK\n";                                 // 完成提示
