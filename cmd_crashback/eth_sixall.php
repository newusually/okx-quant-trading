<?php
/**
 * eth_sixall.php — 全市场合约 六重共振牛熊双向回测(CLI, 2026-09-26 用户指令)
 * 用户: "给我全部更换买入卖空和加仓信号 改成全部六重共振信号 回测一年试一下 还是牛熊判定 然后所有合约搞一下 一年"
 *   信号定义(全部=六重共振, 不再用三均线):
 *     多头信号 = 牛市环境六条件从false→true的【新鲜触发】15m K线(逐根判定, 1h/4h/d条件向前填充)
 *       ① 全市场1D MA20宽度>50% ②本合约1h>MA20 ③1h>MA10 ④4h>MA5 ⑤非熔断(BTC 1h近4根跌≤-3%双向禁入) ⑥taker买比≥50%(本合约1h方向代理)
 *     空头信号 = 六条件全部反向 从false→true的新鲜触发15m K线
 *     加仓 = 持仓中同方向六重共振再次新鲜触发 + 顺向(多:现价>均价/空:现价<均价), 不限轮数, 每轮+1U, 无仓位上限
 *   保证金: 每笔固定1U(用户指令2026-09-26: 独立每个1U, 无阶梯不封顶) | 止盈: 价格±2% | 不设止损 | 爆仓线±0.6%@100x照模拟 | 超时7天
 *   资金费: 多头付/空头收 0.01%/8h | 净口径含手续费 | 每合约独立起始500U, 每笔固定1U
 *   范围: kline库全部 476 个 *-USDT-SWAP 合约, 15m主时间轴一年
 * 输出: web/sixall_data.json
 */
ini_set('memory_limit', '4096M');                 // 内存上限 4G(全市场15m数据量极大)
set_time_limit(0);                                // 取消执行时间限制
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆/止盈/维持保证金率/费率/资金费率
$EQ0 = 500.0; $ADDU = 1.0; $CAPM = 50.0;          // 每合约起始500U/每轮加仓1U/保证金上限50U(本版实际未启用阶梯)

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
unset($rr, $tsB, $cB);                            // 释放全局临时数据

// ===== 合约清单 =====
$insts = [];                                      // 合约名列表
foreach (rows("SHOW TABLES LIKE 'kline\\_%\\_usdt\\_swap\\_15m'") as $x) {  // 列出所有有15m表的合约
    if (preg_match('/^kline_(.+)_usdt_swap_15m$/', $x[0], $m)) $insts[] = $m[1];  // 提取合约名
}
sort($insts);                                     // 按名称排序
echo "insts=" . count($insts) . "\n"; flush();    // 输出合约总数

// ===== 逐合约回测 =====
$liqDrop = 1.0 / $LEV - $MMR;                     // 简化爆仓距离 ≈ 0.6%
$results = []; $done = 0;                         // 结果表/完成计数
foreach ($insts as $inst) {                       // 遍历全部合约
    [$tsF, $oF, $hF, $lF, $cF] = load_k($inst, '15m');  // 读 15m 主时间轴
    $nF = count($tsF);                            // 15m 根数
    if ($nF < 3000) { $results[$inst] = ['skip' => '15m数据不足(' . $nF . '根)']; continue; }  // <~31天跳过   // 数据不足约31天则跳过
    [$ts1, , , , $c1] = load_k($inst, '1h');      // 读 1h 收盘
    [$ts4, , , , $c4] = load_k($inst, '4h');      // 读 4h 收盘
    $n1 = count($ts1); $n4 = count($ts4);         // 1h/4h 根数
    if ($n1 < 300 || $n4 < 80) { $results[$inst] = ['skip' => '1h/4h数据不足']; continue; }  // 高周期数据不足跳过
    $ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5);  // 1h MA20/MA10 与 4h MA5
    $br1h = buyratio($oF, $hF, $lF, $cF); // placeholder overwritten below   // 占位(稍后被1h买比覆盖)
    [$ts1b, $o1b, $h1b, $l1b, $c1b] = load_k($inst, '1h');  // 再读1h完整OHLC
    $br1h = buyratio($o1b, $h1b, $l1b, $c1b);     // 真正的 1h taker买比序列

    // 六条件状态 + 新鲜信号
    $sixL = false; $sixS = false; $sigL = []; $sigS = [];  // 上根多/空六条件状态与新鲜信号集合
    $p1 = 0; $p4 = 0; $cur20 = null; $cur10 = null; $cur4 = null;  // 1h/4h游标与当前条件状态
    $span0 = $tsF[0]; $span1 = $tsF[$nF - 1];     // 该合约数据起止时间戳
    for ($i = 0; $i < $nF; $i++) {                // 逐根15m扫描
        $t = $tsF[$i];                            // 当前15m时间戳
        while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; }  // 前向推进: 用已收盘1h更新 ②③条件
        while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; }  // 前向推进: 用已收盘4h更新 ④条件
        $hb = (int)floor($t / 3600000) * 3600000; // 对齐到整点小时(熔断判定用)
        $b = ffillBr($t);                         // ① 全市场宽度(前向填充)
        $nl = false; $ns = false;                 // 本根多/空六条件是否全命中
        if ($b !== null && !isset($meltSet[$hb])) {  // ①宽度有效 且 ⑤非熔断时段
            $lo = 0; $hi = $n1 - 1; $ib = -1;     // 二分找 ≤t 的最近1h下标
            while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }  // 标准二分
            if ($ib >= 0) {                       // 找到对应1h K线
                $br = $br1h[$ib];                 // ⑥ 该1h的taker买比
                if ($b > 0.5 && $cur20 === true && $cur10 === true && $cur4 === true && $br >= 0.5) $nl = true;  // 多头: 宽度>50%+1h>MA20/MA10+4h>MA5+买比≥50%
                if ($b < 0.5 && $cur20 === false && $cur10 === false && $cur4 === false && $br < 0.5) $ns = true;  // 空头: 六条件全部反向
            }
        }
        if ($nl && !$sixL) $sigL[$i] = true;      // 多头条件 false→true 的新鲜触发才记信号
        if ($ns && !$sixS) $sigS[$i] = true;      // 空头新鲜触发
        $sixL = $nl; $sixS = $ns;                 // 滚动更新状态
    }
    $cntL = count($sigL); $cntS = count($sigS);   // 多/空信号数
    unset($ts1, $ts4, $c1, $c4, $ma20, $ma10, $ma5_4, $ts1b, $o1b, $h1b, $l1b, $c1b, $br1h);  // 释放内存

    // 单合约双向顺序模拟(独立500U)
    $eq = $EQ0; $startT = $tsF[0];                // 该合约独立权益从500U起
    $trades = []; $i = 0;                         // 成交列表/游标
    while ($i < $nF - 1) {                        // 逐根扫描
        $isL = isset($sigL[$i]); $isS = !$isL && isset($sigS[$i]);  // 本根是多头/空头信号(多头优先)
        if (!$isL && !$isS) { $i++; continue; }   // 非信号根跳过
        if ($i + 1 >= $nF || $oF[$i + 1] <= 0) { $i++; continue; }   // 脏数据: 0价格开盘跳过
        $side = $isL ? 'LONG' : 'SHORT';          // 方向
        $M0 = 1.0;   // 用户指令(2026-09-26): 每笔固定1U保证金, 无阶梯不封顶   // 本笔保证金固定 1U
        $e = $oF[$i + 1];                         // 下一根15m开盘入场
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;  // 名义/均价/加仓计数
        $fee = $notional * $FEE_MAKER; $funding = 0.0;  // 开仓手续费/资金费累计
        if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); }  // 多头爆仓价/止盈价
        else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - $TPR); }  // 空头爆仓价/止盈价
        $outcome = null; $j = $i + 1;             // 结局/持仓游标
        while ($j < $nF) {                        // 持仓推进
            if ($hF[$j] <= 0 || $lF[$j] <= 0) { $j++; continue; }   // 脏数据: 0高低价K线跳过
            $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 32;  // 每15m计提资金费(8h的1/32), 多头付/空头收
            $hitLiq = $isL ? ($lF[$j] <= $liqPx) : ($hF[$j] >= $liqPx);  // 触爆仓价判定(方向相关)
            $hitTp  = $isL ? ($hF[$j] >= $tgtPx) : ($lF[$j] <= $tgtPx);  // 触止盈价判定
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 爆仓
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }  // 止盈
            if ($j - $i >= 7 * 24 * 4) { $outcome = 'TO'; $exitPx = $cF[$j]; break; }  // 持仓超7天(15m×672根)超时平仓
            // 加仓: 同方向六重共振再次新鲜触发 + 顺向
            $sigOk = $isL ? (isset($sigL[$j]) && $cF[$j] > $avg) : (isset($sigS[$j]) && $cF[$j] < $avg);  // 同方向信号再触发+现价在顺向侧
            if ($sigOk && $j + 1 < $nF) {         // 满足加仓条件且还有下一根
                $ap = $oF[$j + 1];                // 加仓价 = 下一根开盘
                if ($ap > 0) {                    // 加仓价有效
                    $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);  // 加权新均价
                    $notional += $ADDU * $LEV; $adds++;  // 名义+1U×杠杆, 加仓计数+1(不限轮数)
                    $fee += $ADDU * $LEV * $FEE_TAKER;   // 加仓手续费(taker)
                    if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); }  // 重算多头爆仓/止盈价
                    else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - $TPR); }  // 重算空头爆仓/止盈价
                }
            }
            $j++;                                 // 推进下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $nF - 1; $exitPx = $cF[$j]; }  // 数据耗尽按最后收盘平仓
        $gross = $isL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);  // 毛盈亏(方向相关)
        $fee += $notional * $FEE_TAKER;           // 平仓手续费(taker)
        $pnl = $gross - $fee - $funding;          // 净盈亏
        $mg = $M0 + $adds * $ADDU;                // 该笔总保证金(1U + 每轮加仓1U)
        if ($pnl < -$mg) $pnl = -$mg;             // 亏损封顶到总保证金
        $eq += $pnl;                              // 更新合约权益
        $trades[] = ['side' => $side, 'tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds,  // 记录成交明细
                     'm0' => $M0, 'mg' => $mg, 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),  // 保证金/总保证金/盈亏/权益
                     'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];  // 持仓小时数
        $i = $j + 1;                              // 跳到出场后一根
    }
    // 汇总
    $tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'adds' => 0, 'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0];  // 该合约统计容器
    foreach ($trades as $x) {                     // 逐笔统计
        if ($x['out'] == 'WIN') $tot['win']++;    // 止盈笔数
        if ($x['out'] == 'LIQ') $tot['liq']++;    // 爆仓笔数
        if ($x['out'] == 'TO') $tot['to']++;      // 超时笔数
        $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl'];  // 累计加仓与盈亏
        if ($x['side'] == 'LONG') { $tot['l_n']++; $tot['l_pnl'] += $x['pnl']; }  // 多头笔数与盈亏
        else { $tot['s_n']++; $tot['s_pnl'] += $x['pnl']; }  // 空头笔数与盈亏
    }
    $tot['pnl'] = round($tot['pnl'], 2); $tot['l_pnl'] = round($tot['l_pnl'], 2); $tot['s_pnl'] = round($tot['s_pnl'], 2);  // 保留两位小数
    $peak = -INF; $maxdd = 0;                     // 权益峰值/最大回撤
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }  // 逐笔更新峰值与回撤
    $months = [];                                 // 月度统计
    foreach ($trades as $x) {                     // 逐笔聚合
        $m = gmdate('Y-m', (int)($x['tout'] / 1000) + 8 * 3600);  // 出场月份(北京时间)
        $mo = &$months[$m];                       // 引用该月聚合行
        $mo['n'] = ($mo['n'] ?? 0) + 1;           // 月笔数
        $mo['pnl'] = round(($mo['pnl'] ?? 0) + $x['pnl'], 2);  // 月盈亏
        $mo['eq_end'] = $x['eq'];                 // 月末权益
        unset($mo);                               // 解除引用
    }
    $results[$inst] = [                           // 存该合约完整结果
        'summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),  // 汇总/期末权益/最大回撤
        'span' => [bj($span0), bj($span1)], 'bars' => $nF, 'sigL' => $cntL, 'sigS' => $cntS,  // 数据跨度/根数/多空信号数
        'months' => $months, 'trades' => $trades, // 月度统计/逐笔明细
    ];
    $done++;                                      // 完成计数
    if ($done % 40 == 0) { echo "progress $done/" . count($insts) . " ($inst pnl={$tot['pnl']})\n"; flush(); }  // 每40个输出进度
    unset($tsF, $oF, $hF, $lF, $cF, $trades);     // 释放内存
}

// ===== 汇总 =====
$totN = 0; $totPnl = 0.0; $totWin = 0; $totLiq = 0; $totAdds = 0; $lp = 0.0; $sp = 0.0;  // 全市场合计: 笔数/盈亏/胜/爆/加仓/多盈/空盈
$rank = [];                                       // 合约排行
foreach ($results as $inst => $r) {               // 遍历各合约结果
    if (isset($r['skip'])) continue;              // 跳过被剔除的合约
    $totN += $r['summary']['n']; $totPnl += $r['summary']['pnl'];  // 累计笔数与盈亏
    $totWin += $r['summary']['win']; $totLiq += $r['summary']['liq']; $totAdds += $r['summary']['adds'];  // 累计胜/爆/加仓
    $lp += $r['summary']['l_pnl']; $sp += $r['summary']['s_pnl'];  // 累计多空盈亏
    $rank[] = ['inst' => $inst, 'n' => $r['summary']['n'], 'pnl' => $r['summary']['pnl'], 'eq_end' => $r['eq_end'],  // 排行行: 合约/笔数/盈亏/期末权益
               'win' => $r['summary']['win'], 'liq' => $r['summary']['liq'], 'maxdd' => $r['maxdd'],  // 胜/爆/回撤
               'l_pnl' => $r['summary']['l_pnl'], 's_pnl' => $r['summary']['s_pnl']];  // 多/空盈亏
}
usort($rank, fn($a, $b) => $b['pnl'] <=> $a['pnl']);  // 按盈亏降序
$posCnt = count(array_filter($rank, fn($x) => $x['pnl'] > 0));  // 盈利合约数
echo sprintf("TOTAL insts=%d traded=%d n=%d win=%d liq=%d adds=%d pnl=%.1f (L=%.1f S=%.1f) posInsts=%d\n",  // 打印全市场合计
    count($insts), count($rank), $totN, $totWin, $totLiq, $totAdds, $totPnl, $lp, $sp, $posCnt);  // 各项统计

// 全市场月度合并
$allMon = [];                                     // 全市场月度盈亏
foreach ($results as $inst => $r) {               // 遍历各合约
    if (isset($r['skip'])) continue;              // 跳过剔除的
    foreach ($r['months'] as $m => $v) {          // 累加各月盈亏
        $allMon[$m] = round(($allMon[$m] ?? 0) + $v['pnl'], 2);  // 合并到全市场月度
    }
}
ksort($allMon);                                   // 按月份排序

$out = [                                          // 组装输出 JSON
    'meta' => [                                   // 元信息
        'lev' => $LEV, 'tp' => '±2%', 'eq0' => $EQ0, 'insts' => count($insts),  // 杠杆/止盈/起始权益/合约数
        'params' => '信号全部=六重共振(不再用三均线): 多头信号=牛市六条件(全市场1D MA20宽度>50%/本合约1h>MA20/1h>MA10/4h>MA5/taker买比≥50%/BTC熔断禁入)从false→true的新鲜触发15m K线; 空头信号=六条件全部反向的新鲜触发; 加仓=持仓中同方向六重共振再次新鲜触发+顺向(多:现价>均价/空:现价<均价) 不限轮数 每轮+1U 无仓位上限 | 保证金=每笔固定1U(无阶梯不封顶, 用户指令) | 止盈=价格±2% | 不设止损·爆仓线±0.6%@100x照模拟 | 超时7天 | 资金费=多头付/空头收 0.01%/8h | 每合约独立起始500U·每笔1U | 净口径含手续费',  // 完整参数说明
    ],
    'total' => ['insts' => count($rank), 'skipped' => count($insts) - count($rank), 'n' => $totN, 'win' => $totWin, 'liq' => $totLiq,  // 全市场合计
                'adds' => $totAdds, 'pnl' => round($totPnl, 2), 'l_pnl' => round($lp, 2), 's_pnl' => round($sp, 2),  // 加仓/总盈亏/多空盈亏
                'pos_insts' => $posCnt, 'months' => $allMon],  // 盈利合约数/全市场月度
    'rank' => $rank, 'results' => $results,       // 排行/各合约完整结果
];
file_put_contents('E:/finally-main/web/sixall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出报告 JSON
echo "DATA OK\n";                                 // 完成提示
