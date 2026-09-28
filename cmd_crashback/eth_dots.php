<?php
/**
 * eth_dots.php — 全市场(剔除美股/ETF/商品代币化) 量化做T·1分钟轨道  回测(2026-09-26 用户指令)
 * 本脚本测什么: 全市场1分钟K线上做T四步骤——①1m周期 ②CCI(20)极端(>200/<-200)判定波动大
 *   ③布林带宽>20根前×1.05(敞口放大) ④1m收盘价穿越布林(20,2σ)轨: 上穿轨→追多, 下穿轨→追空;
 *   平仓=回归中轨/爆仓±0.6%@100x/超时240根; 每笔1U, 每合约独立500U, 净口径含手续费+资金费。
 * 参数: 布林(20,2σ), CCI阈值200, 带宽放大1.05, 超时240根; 剔除美股/ETF/商品代币化清单。
 * 输出: 各合约明细与排行+全市场汇总, 写 web/dots_data.json。
 */
ini_set('memory_limit', '4096M');                   // 内存上限4G(全市场1m数据)
set_time_limit(0);                                  // 取消时间限制
$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001; // 参数: 100x/维持保证金0.4%/maker/taker/资金费
$EQ0 = 500.0;                                       // 每合约独立起始权益500U
$P = 20; $K = 2.0; $CCI_N = 20; $CCI_THR = 200.0; $BW_LOOK = 20; $BW_EXP = 1.05; $TO_BARS = 240; // 布林周期/σ倍数/CCI窗口/CCI阈值/带宽对比回看/带宽放大系数/超时根数

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接trading库
$DB = db();                                         // 全局连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; } // 查询行数组
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 毫秒时间戳→北京时间字符串

$EXCL = explode(' ', 'aaoi aapl adbe amd alab amat amc amzn anthropic app asml avgo axti bill brkb bx cien coin cost crcl crdo crm crwv csco cxmt ddog dell dgai dkng gme glw gpro gps gtlb hanmi hims hood hpe hut hyundai ibm intc ionq iren isrg jnj kioxia klac lgelectronics lly lrcx lunr mara meta mrvl mrk mrna msft mstr mstu mu naver nbis net nflx nok now nvda nvdl oklo okta onds on openai orcl oscr oura oust poet pypl qcom rddt rdw riot rivn rklb rok samsung shein shell simo skdd skhynix skhy smci sndk snow softbank sony spcx strc tsem tsla tsll tsm ttmi ttwo twlo unh vrt wdc wmt xiaom xiaomi xom zhipu zhongji zm ewj ewt ewy ewz iwm jp225 kr200 qqq smh soxl soxs spy sqqq tqqq tmf us100 us500 uvxy xbi xle uso urnm xau xag xpd xpt xcu cl bz'); // 剔除清单: 美股/ETF/指数/商品/外汇代币化

function load_1m($inst) {                           // 加载某合约1m K线
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$inst}_usdt_swap_1m ORDER BY candle_time ASC"); // 升序读取
    $ts = []; $o = []; $h = []; $l = []; $c = [];   // 各列
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; } // 类型转换
    return [$ts, $o, $h, $l, $c];                   // 返回五元组
}

$insts = []; $exclN = 0;                            // 合约列表/被剔除数
foreach (rows("SHOW TABLES LIKE 'kline\\_%\\_usdt\\_swap\\_1m'") as $x) { // 枚举所有1m表
    if (preg_match('/^kline_(.+)_usdt_swap_1m$/', $x[0], $m)) { // 提取合约名
        if (in_array($m[1], $EXCL, true)) { $exclN++; continue; } // 在剔除清单中则跳过
        $insts[] = $m[1];                           // 收入合约列表
    }
}
sort($insts);                                       // 合约排序
echo "insts=" . count($insts) . " excluded=$exclN\n"; flush(); // 打印数量

$liqDrop = 1.0 / $LEV - $MMR;                       // 爆仓跌幅≈0.6%(100x)
$results = []; $done = 0; $g0 = null; $g1 = null;   // 结果表/计数/全局窗口
foreach ($insts as $inst) {                         // 逐合约
    [$ts, $o, $h, $l, $c] = load_1m($inst);         // 加载1m数据
    $n = count($ts);
    if ($n < $P + $BW_LOOK + 50) { $results[$inst] = ['skip' => '1m数据不足']; continue; } // 数据不足跳过
    if ($g0 === null || $ts[0] < $g0) $g0 = $ts[0]; // 更新全局最早
    if ($g1 === null || $ts[$n - 1] > $g1) $g1 = $ts[$n - 1]; // 最晚

    // ---- 增量指标: CCI(20) / 布林(20,2σ) ----
    $cci = array_fill(0, $n, null);                 // CCI序列
    $up = array_fill(0, $n, null); $lo = array_fill(0, $n, null); $mid = array_fill(0, $n, null); // 布林上/下/中轨
    $tpQ = []; $sTP = 0.0;                          // 典型价队列与其和(CCI用)
    $q = []; $s = 0.0; $s2 = 0.0;                   // 收盘队列/和/平方和(布林用)
    for ($i = 0; $i < $n; $i++) {                   // 逐根增量计算
        if ($c[$i] <= 0) continue;                  // 无效价格跳过
        $tp = ($h[$i] + $l[$i] + $c[$i]) / 3;       // 典型价TP
        $tpQ[] = $tp; $sTP += $tp;                  // 入队累加
        if (count($tpQ) > $CCI_N) $sTP -= array_shift($tpQ); // 队列保持20
        if (count($tpQ) == $CCI_N) {                // 满20可算CCI
            $maTP = $sTP / $CCI_N; $md = 0.0;       // TP均值/平均绝对偏差
            foreach ($tpQ as $v) $md += abs($v - $maTP); // 累计偏差
            $md /= $CCI_N;                          // 求均值
            if ($md > 0) $cci[$i] = ($tp - $maTP) / (0.015 * $md); // CCI公式
        }
        $q[] = $c[$i]; $s += $c[$i]; $s2 += $c[$i] * $c[$i]; // 收盘入队
        if (count($q) > $P) { $old = array_shift($q); $s -= $old; $s2 -= $old * $old; } // 队列保持20
        if (count($q) == $P) {                      // 满20可算布林
            $m = $s / $P; $v = $s2 / $P - $m * $m; if ($v < 0) $v = 0; $sd = sqrt($v); // 均值/方差(防负)/标准差
            $mid[$i] = $m; $up[$i] = $m + $K * $sd; $lo[$i] = $m - $K * $sd; // 中轨/上轨/下轨
        }
    }

    // ---- 信号: 价格穿越轨道 + CCI方向 + 敞口放大 ----
    $sigL = []; $sigS = []; $cntL = 0; $cntS = 0;   // 多/空信号表与计数
    for ($i = $P + $BW_LOOK; $i < $n; $i++) {       // 从有带宽对比能力处开始
        if ($up[$i] === null || $up[$i - 1] === null || $up[$i - $BW_LOOK] === null) continue; // 指标未就绪
        $bw = $up[$i] - $lo[$i]; $bwPrev = $up[$i - $BW_LOOK] - $lo[$i - $BW_LOOK]; // 当前带宽/20根前带宽
        if ($bwPrev <= 0 || $bw <= $bwPrev * $BW_EXP) continue; // ③带宽需放大1.05倍以上
        $upCross = ($c[$i - 1] <= $up[$i - 1] && $c[$i] > $up[$i]);     // 曲线上穿上轨 → 买多   // ④上穿上轨
        $dnCross = ($c[$i - 1] >= $lo[$i - 1] && $c[$i] < $lo[$i]);     // 曲线下穿下轨 → 卖空   // ④下穿下轨
        if ($upCross && $cci[$i] !== null && $cci[$i] > $CCI_THR) { $sigL[$i] = true; $cntL++; } // 上轨+CCI>200 → 多信号
        if ($dnCross && $cci[$i] !== null && $cci[$i] < -$CCI_THR) { $sigS[$i] = true; $cntS++; } // 下轨+CCI<-200 → 空信号
    }

    // ---- 模拟 ----
    $eq = $EQ0; $trades = []; $i = 0;               // 权益/逐笔/游标
    while ($i < $n - 1) {                           // 逐信号开仓
        $isL = isset($sigL[$i]); $isS = !$isL && isset($sigS[$i]); // 优先判多
        if (!$isL && !$isS) { $i++; continue; }     // 无信号跳过
        if ($o[$i + 1] <= 0) { $i++; continue; }    // 无效开盘价跳过
        $side = $isL ? 'LONG' : 'SHORT';            // 方向
        $M0 = 1.0; $e = $o[$i + 1];                 // 每笔固定1U保证金/成交价
        $notional = $M0 * $LEV; $fee = $notional * $FEE_MAKER; $funding = 0.0; // 名义/开仓maker费/资金费
        $liqPx = $isL ? $e * (1 - $liqDrop) : $e * (1 + $liqDrop); // 爆仓价(多下/空上)
        $outcome = null; $j = $i + 1;               // 出场状态/游标
        while ($j < $n) {                           // 持仓逐根
            if ($h[$j] <= 0 || $l[$j] <= 0) { $j++; continue; } // 无效K线跳过
            $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 480; // 1m根计提1/480个8h资金费(空方收付相反)
            $hitLiq = $isL ? ($l[$j] <= $liqPx) : ($h[$j] >= $liqPx); // 触及爆仓价?
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; } // 爆仓离场
            // 回归中轨 = 做T的卖/买回
            $rev = false;                           // 是否回归中轨
            if ($mid[$j] !== null) {
                if ($isL) $rev = ($c[$j] <= $mid[$j]); // 多头: 收盘回到中轨下
                else      $rev = ($c[$j] >= $mid[$j]); // 空头: 收盘回到中轨上
            }
            if ($rev) { $outcome = 'XREV'; $exitPx = $c[$j]; break; } // 回归中轨平仓
            if ($j - $i >= $TO_BARS) { $outcome = 'TO'; $exitPx = $c[$j]; break; } // 240根超时平仓
            $j++;
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; } // 数据尽头按超时
        $gross = $isL ? (($exitPx - $e) * $notional / $e) : (($e - $exitPx) * $notional / $e); // 浮动盈亏(多/空)
        $fee += $notional * $FEE_TAKER;             // 平仓taker费
        $pnl = $gross - $fee - $funding;            // 净盈亏
        if ($pnl < -$M0) $pnl = -$M0;               // 亏损钳制到1U保证金
        $eq += $pnl;                                // 结转权益
        $trades[] = ['side' => $side, 'tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, // 逐笔记录
                     'm0' => $M0, 'pnl' => round($pnl, 4), 'eq' => round($eq, 2),
                     'holdm' => (int)(($ts[$j] - $ts[$i]) / 60000)]; // 持仓分钟数
        $i = $j + 1;                                // 跳到出场后
    }
    // 汇总
    $tot = ['n' => count($trades), 'xrev' => 0, 'liq' => 0, 'to' => 0, 'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0]; // 统计容器
    $holdSum = 0;                                   // 持仓分钟累计
    foreach ($trades as $x) {                       // 逐笔统计
        if ($x['out'] == 'XREV') $tot['xrev']++;    // 回归中轨出场数
        if ($x['out'] == 'LIQ') $tot['liq']++;      // 爆仓数
        if ($x['out'] == 'TO') $tot['to']++;        // 超时数
        $tot['pnl'] += $x['pnl']; $holdSum += $x['holdm']; // 盈亏/持仓累计
        if ($x['side'] == 'LONG') { $tot['l_n']++; $tot['l_pnl'] += $x['pnl']; } // 多头笔数/盈亏
        else { $tot['s_n']++; $tot['s_pnl'] += $x['pnl']; } // 空头
    }
    $tot['pnl'] = round($tot['pnl'], 2); $tot['l_pnl'] = round($tot['l_pnl'], 2); $tot['s_pnl'] = round($tot['s_pnl'], 2); // 保留2位
    $tot['hold_avg'] = $tot['n'] ? round($holdSum / $tot['n'], 1) : 0; // 平均持仓分钟
    $peak = -INF; $maxdd = 0;                       // 权益峰值/最大回撤
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; } // 逐笔更新回撤
    $days = [];                                     // 按日盈亏
    foreach ($trades as $x) {
        $d = gmdate('m-d', (int)($x['tout'] / 1000) + 8 * 3600); // 出场日(北京时间)
        $days[$d] = round(($days[$d] ?? 0) + $x['pnl'], 2); // 累计
    }
    $results[$inst] = [                             // 保存该合约结果
        'summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2), // 汇总/期末权益/回撤
        'span' => [bj($ts[0]), bj($ts[$n - 1])], 'bars' => $n, 'sigL' => $cntL, 'sigS' => $cntS, // 数据范围/根数/多空信号数
        'days' => $days, 'trades' => $trades,       // 日盈亏/逐笔
    ];
    $done++;
    if ($done % 60 == 0) { echo "progress $done/" . count($insts) . " ($inst pnl={$tot['pnl']})\n"; flush(); } // 每60个打印进度
    unset($ts, $o, $h, $l, $c, $cci, $up, $lo, $mid, $trades); // 释放内存
}

// ===== 汇总 =====
$totN = 0; $totPnl = 0.0; $totX = 0; $totLiq = 0; $lp = 0.0; $sp = 0.0; // 全市场合计
$rank = [];                                         // 排行表
foreach ($results as $inst => $r) {                 // 逐合约累加
    if (isset($r['skip'])) continue;                // 跳过未交易
    $totN += $r['summary']['n']; $totPnl += $r['summary']['pnl']; // 笔数/盈亏
    $totX += $r['summary']['xrev']; $totLiq += $r['summary']['liq']; // 回归/爆仓数
    $lp += $r['summary']['l_pnl']; $sp += $r['summary']['s_pnl']; // 多/空盈亏
    $rank[] = ['inst' => $inst, 'n' => $r['summary']['n'], 'pnl' => $r['summary']['pnl'], 'eq_end' => $r['eq_end'], // 排行条目
               'xrev' => $r['summary']['xrev'], 'liq' => $r['summary']['liq'], 'maxdd' => $r['maxdd'],
               'hold' => $r['summary']['hold_avg'], 'l_pnl' => $r['summary']['l_pnl'], 's_pnl' => $r['summary']['s_pnl']];
}
usort($rank, fn($a, $b) => $b['pnl'] <=> $a['pnl']); // 按盈亏降序
$posCnt = count(array_filter($rank, fn($x) => $x['pnl'] > 0)); // 盈利合约数
echo sprintf("TOTAL insts=%d excluded=%d traded=%d n=%d xrev=%d liq=%d pnl=%.1f (L=%.1f S=%.1f) posInsts=%d\n", // 打印全市场汇总
    count($insts), $exclN, count($rank), $totN, $totX, $totLiq, $totPnl, $lp, $sp, $posCnt);

$allDays = [];                                      // 全市场按日盈亏
foreach ($results as $inst => $r) {
    if (isset($r['skip'])) continue;
    foreach ($r['days'] as $d => $v) $allDays[$d] = round(($allDays[$d] ?? 0) + $v, 2); // 累计各合约日盈亏
}
ksort($allDays);                                    // 日期排序

$out = [                                            // 组装输出JSON
    'meta' => [
        'lev' => $LEV, 'eq0' => $EQ0, 'insts' => count($insts), 'excluded' => $exclN, // 元信息
        'window' => [bj($g0), bj($g1)],             // 全局数据窗口
        'params' => '量化做T四步骤: ①周期=1分钟K线 ②波动大=CCI(20)方向对齐(买多CCI>200/卖空CCI<-200) ③轨道敞口放大=布林带宽>20根前×1.05(波动变大·信号准备) ④交叉点动手=波动曲线(1m收盘价)与布林轨(20,2σ)交叉: 曲线上穿上轨=先买多后卖, 曲线下穿下轨=先卖空后买 [注: 用户原话"下轨上穿上轨"经探针证实同尺度快慢布林物理不可能(全市场0信号), 按做T标准口径=价格穿越轨道] | 平仓=回归中轨(做T的卖/买回)·爆仓线±0.6%@100x照模拟·超时240根(4小时) | 不加仓·每笔固定1U | 每合约独立500U | 净口径含手续费(maker进/taker出)+资金费 | 1m数据窗口=OKX库仅存09-21 07:14起约5.1天(全市场一致, 一个月1m未入库)', // 参数口径全文
    ],
    'total' => ['insts' => count($rank), 'skipped' => count($insts) - count($rank), 'n' => $totN, 'xrev' => $totX, 'liq' => $totLiq, // 全市场汇总
                'pnl' => round($totPnl, 2), 'l_pnl' => round($lp, 2), 's_pnl' => round($sp, 2),
                'pos_insts' => $posCnt, 'days' => $allDays],
    'rank' => $rank, 'results' => $results,         // 排行/各合约明细
];
file_put_contents('E:/finally-main/web/dots_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写出JSON
echo "DATA OK\n";                                   // 完成提示
