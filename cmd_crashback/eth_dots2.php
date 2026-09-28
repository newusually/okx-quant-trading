<?php
/**
 * eth_dots2.php — 全市场(剔除美股/ETF/商品代币化) 量化做T·均值回归/固定小止盈 对照回测(2026-09-26 用户指令)
 * 本脚本测什么: 上一版 eth_dots(突破追波动)全市场-1661.8U 全亏, 按报告建议做三组对照(信号与上一版完全相同, 仅方向/平仓不同):
 *   A 均值回归: 上轨触发→做空/下轨触发→做多, 平仓=回归中轨
 *   B 均值回归+TP: 同A方向, 平仓=固定止盈+0.3%(maker挂单)/回归中轨先到为准
 *   C 突破方向+TP: 上一版原方向, 平仓=固定止盈+0.3%/回归中轨
 *   爆仓±0.6%@100x照模拟/超时240根; 每笔1U, 每合约独立500U, 净口径含手续费+资金费。
 * 输出: 三变体×各合约明细与汇总, 写 web/dots2_data.json。
 */
ini_set('memory_limit', '4096M');                   // 内存上限4G(全市场1m)
set_time_limit(0);                                  // 取消时间限制
$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 参数: 100x/维持保证金/手续费/资金费
$EQ0 = 500.0;                                       // 每合约独立500U
$P = 20; $K = 2.0; $CCI_N = 20; $CCI_THR = 200.0; $BW_LOOK = 20; $BW_EXP = 1.05; $TO_BARS = 240; // 布林/CCI/带宽/超时参数(与上一版一致)
$TP = 0.003; // 固定小止盈 +0.3% 价格(100x 下 = +30% 收益率)   // 固定止盈阈值

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接trading库
$DB = db();                                         // 全局连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; } // 查询行数组
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 毫秒→北京时间字符串

$EXCL = explode(' ', 'aaoi aapl adbe amd alab amat amc amzn anthropic app asml avgo axti bill brkb bx cien coin cost crcl crdo crm crwv csco cxmt ddog dell dgai dkng gme glw gpro gps gtlb hanmi hims hood hpe hut hyundai ibm intc ionq iren isrg jnj kioxia klac lgelectronics lly lrcx lunr mara meta mrvl mrk mrna msft mstr mstu mu naver nbis net nflx nok now nvda nvdl oklo okta onds on openai orcl oscr oura oust poet pypl qcom rddt rdw riot rivn rklb rok samsung shein shell simo skdd skhynix skhy smci sndk snow softbank sony spcx strc tsem tsla tsll tsm ttmi ttwo twlo unh vrt wdc wmt xiaom xiaomi xom zhipu zhongji zm ewj ewt ewy ewz iwm jp225 kr200 qqq smh soxl soxs spy sqqq tqqq tmf us100 us500 uvxy xbi xle uso urnm xau xag xpd xpt xcu cl bz'); // 剔除清单: 美股/ETF/商品代币化

function load_1m($inst) {                           // 加载某合约1m K线
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_1m ORDER BY candle_time ASC"); // 升序读取
    $ts = []; $o = []; $h = []; $l = []; $c = [];   // 各列
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; } // 类型转换
    return [$ts, $o, $h, $l, $c];                   // 返回五元组
}

$insts = []; $exclN = 0;                            // 合约列表/剔除数
foreach (rows("SHOW TABLES LIKE 'kline\\_%\\_usdt\\_swap\\_1m'") as $x) { // 枚举1m表
    if (preg_match('/^kline_(.+)_usdt_swap_1m$/', $x[0], $m)) { // 提取合约名
        if (in_array($m[1], $EXCL, true)) { $exclN++; continue; } // 剔除清单内跳过
        $insts[] = $m[1];                           // 收入列表
    }
}
sort($insts);                                       // 排序
echo "insts=" . count($insts) . " excluded=$exclN\n"; flush(); // 打印数量

$liqDrop = 1.0 / $LEV - $MMR;                       // 爆仓跌幅≈0.6%

/** 单组合模拟: $flip=true→上轨做空/下轨做多(均值回归) ; $tp>0→固定止盈+tp(挂单maker平仓) */
function sim($flip, $tp, $ts, $o, $h, $l, $c, $mid, $sigUp, $sigDn, $n) { // 单变体模拟函数
    global $LEV, $FEE_MAKER, $FEE_TAKER, $FUND8H, $EQ0, $TO_BARS, $liqDrop; // 全局参数
    $eq = $EQ0; $trades = []; $i = 0;               // 权益/逐笔/游标
    while ($i < $n - 1) {                           // 逐信号开仓
        if (isset($sigUp[$i]))      $isS = $flip;          // 上轨: flip→做空 / 突破→做多
        elseif (isset($sigDn[$i]))  $isS = !$flip;         // 下轨: flip→做多 / 突破→做空
        else { $i++; continue; }                    // 无信号跳过
        if ($o[$i + 1] <= 0) { $i++; continue; }    // 无效开盘价跳过
        $isL = !$isS;                               // 是否做多
        $M0 = 1.0; $e = $o[$i + 1];                 // 1U保证金/成交价
        $notional = $M0 * $LEV; $fee = $notional * $FEE_MAKER; $funding = 0.0; // 名义/开仓maker费/资金费
        $liqPx = $isL ? $e * (1 - $liqDrop) : $e * (1 + $liqDrop); // 爆仓价
        $tpPx = $tp > 0 ? ($isL ? $e * (1 + $tp) : $e * (1 - $tp)) : 0.0; // 固定止盈价(多上/空下)
        $outcome = null; $exitPx = 0.0; $exitMaker = false; $j = $i + 1; // 出场状态/价/是否maker平仓/游标
        while ($j < $n) {                           // 持仓逐根
            if ($h[$j] <= 0 || $l[$j] <= 0) { $j++; continue; } // 无效K线跳过
            $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 480; // 1m根计提资金费
            $hitLiq = $isL ? ($l[$j] <= $liqPx) : ($h[$j] >= $liqPx); // 触及爆仓?
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 爆仓离场
            if ($tpPx > 0) {                        // 启用固定止盈时
                $hitTp = $isL ? ($h[$j] >= $tpPx) : ($l[$j] <= $tpPx); // 触及止盈价?
                if ($hitTp) { $outcome = 'XTP'; $exitPx = $tpPx; $exitMaker = true; break; } // 挂止盈单=maker
            }
            $rev = false;                           // 是否回归中轨
            if ($mid[$j] !== null) {
                if ($isL) $rev = ($c[$j] <= $mid[$j]); // 多: 收盘回中轨下
                else      $rev = ($c[$j] >= $mid[$j]); // 空: 收盘回中轨上
            }
            if ($rev) { $outcome = 'XREV'; $exitPx = $c[$j]; break; } // 回归中轨平仓(taker)
            if ($j - $i >= $TO_BARS) { $outcome = 'TO'; $exitPx = $c[$j]; break; } // 240根超时
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; } // 数据尽头按超时
        $fee += $notional * ($exitMaker ? $FEE_MAKER : $FEE_TAKER); // 平仓费: 挂单maker/市价taker
        $gross = $isL ? (($exitPx - $e) * $notional / $e) : (($e - $exitPx) * $notional / $e); // 浮动盈亏
        $pnl = $gross - $fee - $funding;            // 净盈亏
        if ($pnl < -$M0) $pnl = -$M0;               // 亏损钳制到1U
        $eq += $pnl;                                // 结转权益
        $trades[] = ['side' => $isL ? 'LONG' : 'SHORT', 'tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, // 逐笔记录
                     'm0' => $M0, 'pnl' => round($pnl, 4), 'eq' => round($eq, 2),
                     'holdm' => (int)(($ts[$j] - $ts[$i]) / 60000)]; // 持仓分钟
        $i = $j + 1;                                // 跳到出场后
    }
    return [$trades, $eq];                          // 返回逐笔与期末权益
}

function summarize($trades, $eq, $ts, $n) {         // 汇总统计函数
    $tot = ['n' => count($trades), 'xtp' => 0, 'xrev' => 0, 'liq' => 0, 'to' => 0, // 各出场计数
            'win' => 0, 'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0]; // 胜/盈亏/多空分计
    $holdSum = 0;                                   // 持仓分钟累计
    foreach ($trades as $x) {                       // 逐笔统计
        if ($x['out'] == 'XTP') $tot['xtp']++;      // 止盈出场数
        if ($x['out'] == 'XREV') $tot['xrev']++;    // 回归中轨数
        if ($x['out'] == 'LIQ') $tot['liq']++;      // 爆仓数
        if ($x['out'] == 'TO') $tot['to']++;        // 超时数
        if ($x['pnl'] > 0) $tot['win']++;           // 胜场
        $tot['pnl'] += $x['pnl']; $holdSum += $x['holdm']; // 盈亏/持仓累计
        if ($x['side'] == 'LONG') { $tot['l_n']++; $tot['l_pnl'] += $x['pnl']; } // 多头
        else { $tot['s_n']++; $tot['s_pnl'] += $x['pnl']; } // 空头
    }
    $tot['pnl'] = round($tot['pnl'], 2); $tot['l_pnl'] = round($tot['l_pnl'], 2); $tot['s_pnl'] = round($tot['s_pnl'], 2); // 保留2位
    $tot['hold_avg'] = $tot['n'] ? round($holdSum / $tot['n'], 1) : 0; // 平均持仓分钟
    $peak = -INF; $maxdd = 0;                       // 回撤计算
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; } // 逐笔更新
    $days = [];                                     // 按日盈亏
    foreach ($trades as $x) {
        $d = gmdate('m-d', (int)($x['tout'] / 1000) + 8 * 3600); // 出场日(北京时间)
        $days[$d] = round(($days[$d] ?? 0) + $x['pnl'], 2); // 累计
    }
    return ['summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2), // 返回汇总
            'span' => [bj($ts[0]), bj($ts[$n - 1])], 'days' => $days, 'trades' => $trades]; // 范围/日盈亏/逐笔
}

$VARIANTS = [                                       // 三个对照变体
    'A' => ['name' => '均值回归(上轨空/下轨多)·回归中轨平仓', 'flip' => true,  'tp' => 0.0], // A: 反向+中轨平仓
    'B' => ['name' => '均值回归(上轨空/下轨多)+固定止盈+0.3%快进快出', 'flip' => true,  'tp' => $TP], // B: 反向+小止盈
    'C' => ['name' => '原突破方向(上轨多/下轨空)+固定止盈+0.3%', 'flip' => false, 'tp' => $TP], // C: 原方向+小止盈
];

$results = [];   // [variant][inst]                 // 结果表[变体][合约]
$done = 0; $g0 = null; $g1 = null; $gSigL = 0; $gSigS = 0; // 计数/全局窗口/全局信号数
foreach ($insts as $inst) {                         // 逐合约
    [$ts, $o, $h, $l, $c] = load_1m($inst);         // 加载1m
    $n = count($ts);
    if ($n < $P + $BW_LOOK + 50) { foreach ($VARIANTS as $vk => $vc) $results[$vk][$inst] = ['skip' => '1m数据不足']; continue; } // 数据不足跳过
    if ($g0 === null || $ts[0] < $g0) $g0 = $ts[0]; // 更新全局窗口
    if ($g1 === null || $ts[$n - 1] > $g1) $g1 = $ts[$n - 1];

    // ---- 增量指标: CCI(20) / 布林(20,2σ) ----
    $cci = array_fill(0, $n, null);                 // CCI序列
    $up = array_fill(0, $n, null); $lo = array_fill(0, $n, null); $mid = array_fill(0, $n, null); // 布林上/下/中轨
    $tpQ = []; $sTP = 0.0;                          // 典型价队列(CCI用)
    $q = []; $s = 0.0; $s2 = 0.0;                   // 收盘队列(布林用)
    for ($i = 0; $i < $n; $i++) {                   // 逐根增量计算
        if ($c[$i] <= 0) continue;                  // 无效价格跳过
        $tpv = ($h[$i] + $l[$i] + $c[$i]) / 3;      // 典型价
        $tpQ[] = $tpv; $sTP += $tpv;                // 入队累加
        if (count($tpQ) > $CCI_N) $sTP -= array_shift($tpQ); // 队列保持20
        if (count($tpQ) == $CCI_N) {                // 满窗口
            $maTP = $sTP / $CCI_N; $md = 0.0;       // 均值/绝对偏差和
            foreach ($tpQ as $v) $md += abs($v - $maTP); // 累计偏差
            $md /= $CCI_N;                          // 平均
            if ($md > 0) $cci[$i] = ($tpv - $maTP) / (0.015 * $md); // CCI公式
        }
        $q[] = $c[$i]; $s += $c[$i]; $s2 += $c[$i] * $c[$i]; // 收盘入队
        if (count($q) > $P) { $old = array_shift($q); $s -= $old; $s2 -= $old * $old; } // 队列保持20
        if (count($q) == $P) {                      // 满窗口
            $m = $s / $P; $v = $s2 / $P - $m * $m; if ($v < 0) $v = 0; $sd = sqrt($v); // 均值/标准差
            $mid[$i] = $m; $up[$i] = $m + $K * $sd; $lo[$i] = $m - $K * $sd; // 中/上/下轨
        }
    }

    // ---- 信号(与上一版完全相同, 仅方向/平仓组合不同) ----
    $sigUp = []; $sigDn = []; $cntU = 0; $cntD = 0; // 上/下轨信号表与计数
    for ($i = $P + $BW_LOOK; $i < $n; $i++) {       // 逐根判信号
        if ($up[$i] === null || $up[$i - 1] === null || $up[$i - $BW_LOOK] === null) continue; // 指标未就绪
        $bw = $up[$i] - $lo[$i]; $bwPrev = $up[$i - $BW_LOOK] - $lo[$i - $BW_LOOK]; // 当前/20根前带宽
        if ($bwPrev <= 0 || $bw <= $bwPrev * $BW_EXP) continue; // 带宽需放大1.05倍
        $upCross = ($c[$i - 1] <= $up[$i - 1] && $c[$i] > $up[$i]); // 上穿上轨
        $dnCross = ($c[$i - 1] >= $lo[$i - 1] && $c[$i] < $lo[$i]); // 下穿下轨
        if ($upCross && $cci[$i] !== null && $cci[$i] > $CCI_THR) { $sigUp[$i] = true; $cntU++; } // 上轨+CCI>200
        if ($dnCross && $cci[$i] !== null && $cci[$i] < -$CCI_THR) { $sigDn[$i] = true; $cntD++; } // 下轨+CCI<-200
    }
    $gSigL += $cntU; $gSigS += $cntD;               // 累计全局信号数

    foreach ($VARIANTS as $vk => $vc) {             // 逐变体跑同一份数据
        [$trades, $eq] = sim($vc['flip'], $vc['tp'], $ts, $o, $h, $l, $c, $mid, $sigUp, $sigDn, $n); // 模拟
        $r = summarize($trades, $eq, $ts, $n);      // 汇总
        $r['bars'] = $n; $r['sigU'] = $cntU; $r['sigD'] = $cntD; // 附加根数/信号数
        $results[$vk][$inst] = $r;                  // 存入结果表
    }
    $done++;
    if ($done % 60 == 0) { echo "progress $done/" . count($insts) . " ($inst)\n"; flush(); } // 每60个打印进度
    unset($ts, $o, $h, $l, $c, $cci, $up, $lo, $mid, $trades); // 释放内存
    gc_collect_cycles();                            // 强制GC(4G内存压力下更稳)
}

// ===== 汇总 =====
$totals = []; $ranks = []; $allDaysV = [];          // 各变体汇总/排行/日盈亏
foreach ($VARIANTS as $vk => $vc) {                 // 逐变体
    $totN = 0; $totPnl = 0.0; $tX = 0; $tLiq = 0; $tTo = 0; $tWin = 0; $lp = 0.0; $sp = 0.0; // 合计容器
    $rank = []; $days = [];                         // 排行/日盈亏
    foreach ($results[$vk] as $inst => $r) {        // 逐合约累加
        if (isset($r['skip'])) continue;            // 跳过未交易
        $st = $r['summary'];
        $totN += $st['n']; $totPnl += $st['pnl']; $tX += $st['xrev']; $tLiq += $st['liq']; // 笔数/盈亏/回归/爆仓
        $tTo += $st['to']; $tWin += $st['win']; $lp += $st['l_pnl']; $sp += $st['s_pnl']; // 超时/胜/多空盈亏
        $rank[] = ['inst' => $inst, 'n' => $st['n'], 'pnl' => $st['pnl'], 'eq_end' => $r['eq_end'], // 排行条目
                   'win' => $st['win'], 'xtp' => $st['xtp'], 'xrev' => $st['xrev'], 'liq' => $st['liq'], 'to' => $st['to'],
                   'maxdd' => $r['maxdd'], 'hold' => $st['hold_avg'],
                   'l_pnl' => $st['l_pnl'], 's_pnl' => $st['s_pnl']];
        foreach ($r['days'] as $d => $v) $days[$d] = round(($days[$d] ?? 0) + $v, 2); // 累计日盈亏
    }
    usort($rank, fn($a, $b) => $b['pnl'] <=> $a['pnl']); // 按盈亏降序
    $posCnt = count(array_filter($rank, fn($x) => $x['pnl'] > 0)); // 盈利合约数
    $pnlList = array_column($rank, 'pnl'); sort($pnlList); // 盈亏列表排序(取中位数)
    $med = count($pnlList) ? $pnlList[intdiv(count($pnlList), 2)] : 0; // 中位数
    ksort($days);                                   // 日盈亏排序
    $totals[$vk] = ['name' => $vc['name'], 'insts' => count($rank), 'skipped' => count($insts) - count($rank), // 变体汇总
                    'n' => $totN, 'win' => $tWin, 'xrev' => $tX, 'liq' => $tLiq, 'to' => $tTo,
                    'pnl' => round($totPnl, 2), 'l_pnl' => round($lp, 2), 's_pnl' => round($sp, 2),
                    'pos_insts' => $posCnt, 'med_inst' => round($med, 2), 'days' => $days];
    $ranks[$vk] = $rank;                            // 保存排行
    echo sprintf("[%s] %s => n=%d win=%d xrev=%d liq=%d to=%d pnl=%.1f (L=%.1f S=%.1f) posInsts=%d med=%.2f\n", // 打印变体汇总
        $vk, $vc['name'], $totN, $tWin, $tX, $tLiq, $tTo, $totPnl, $lp, $sp, $posCnt, $med);
    flush();
}

$out = [                                            // 组装输出JSON
    'meta' => [
        'lev' => $LEV, 'eq0' => $EQ0, 'insts' => count($insts), 'excluded' => $exclN, // 元信息
        'window' => [bj($g0), bj($g1)], 'tp' => $TP, 'to_bars' => $TO_BARS, // 窗口/止盈/超时
        'params' => '上一版做T(突破方向追波动)全市场-1,661.8U全日亏损, 本轮按报告改法做三组对照。信号完全相同=1m收盘价穿越布林(20,2σ)轨道+CCI(20)极端(上轨CCI>200/下轨CCI<-200)+敞口放大(带宽>20根前×1.05), 仅方向与平仓不同: A=均值回归(上轨触发做空/下轨触发做多)·平仓=回归中轨; B=均值回归同A方向·平仓=固定止盈+0.3%价格(挂单maker)+回归中轨先到为准; C=原突破方向(上轨做多/下轨做空)+固定止盈+0.3%对照。爆仓线±0.6%@100x照模拟·超时240根(4小时)·每笔固定1U·每合约独立500U·净口径含手续费+资金费。1m数据窗口=OKX库仅存09-21 07:14起约5.1天', // 参数口径全文
    ],
    'variants' => array_map(fn($vk, $vc) => ['key' => $vk, 'name' => $vc['name'], 'flip' => $vc['flip'], 'tp' => $vc['tp']], array_keys($VARIANTS), $VARIANTS), // 变体说明
    'total' => $totals,                             // 各变体汇总
    'rank' => $ranks,                               // 各变体排行
    'results' => $results,                          // 各变体各合约明细
];
file_put_contents('E:/finally-main/web/dots2_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写出JSON
echo "DATA OK\n";                                   // 完成提示
