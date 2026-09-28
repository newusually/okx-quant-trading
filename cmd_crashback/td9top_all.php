<?php
/**
 * td9top_all.php — 全市场 15m 顶序列九7~九9 买入 一年回测生成器 (2026-09-26 用户指令)
 * 用户原话: "所有合约 给我算一下 回测 15分钟 九9 上涨的时候 买入信号 涨幅2% 有加仓每次1美金
 *           本金每个合约1美金 是否盈利一年 回测数据明细php localhost出来 要明细和盈利曲线"
 * (后追加: 顶序列计数 7/8/9 都算买入信号)
 *
 * 口径:
 *   信号 = 15m 上涨(顶)序列 计数恰好 7/8/9 (连续N根收盘>第4根前收盘, 断即清零, 标准TD口径)
 *   空仓遇信号 → 开多 1U 保证金 @信号根收盘价; 持仓再遇信号 → 加仓 +1U
 *   止盈 = 持仓均价 +2%(价格) 市价全平; 不设止损; 爆仓 = 跌幅≥1/100-0.4%≈0.6% 保证金全亏
 *   超时7天平仓; 数据末尾强制平仓(END); 费用 = taker 0.05% × notional(保证金×100) 进出都收
 *   本金 = 每合约独立 1U 起始权益; 开仓需现金≥1.05U(1U保证金+手续费); 爆仓后现金<1.05 = 破产停做
 * 输出: web/td9topall_data.json
 */
ini_set('memory_limit', '4096M');                                     // 提升PHP内存上限到4G, 全市场15mK线数据量大
set_time_limit(0);                                                    // 取消脚本执行时间限制
$LEV = 100; $MMR = 0.004; $FEE = 0.0005; $TP = 0.02; $U = 1.0;        // 杠杆100x / 维持保证金率0.4% / taker费率0.05% / 止盈+2% / 每笔本金1U
$TMO_MS = 7 * 86400 * 1000;                                           // 持仓超时阈值7天(毫秒), 到期按收盘价平仓
$LIQ = 1.0 / $LEV - $MMR; // 0.006                                    // 爆仓跌幅 = 1/100 - 0.4% = 0.6%
$CAP_TRADES = 500; $CAP_CURVE = 300;                                  // 每合约逐笔明细上限500笔 / 资金曲线上限300点

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接本地MariaDB的trading库并设UTF-8编码
$DB = db();                                                           // 建立全局数据库连接
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 毫秒时间戳转北京时间字符串

$insts = [];                                                          // 合约基础币种列表
$j = json_decode(file_get_contents('E:/datas/insts_all.json'), true); // 读取全量合约清单JSON
foreach ($j['insts'] as $s) if (preg_match('/^(.+)-USDT-SWAP$/', $s, $m2)) $insts[] = strtolower($m2[1]); // 从"XXX-USDT-SWAP"提取基础币名并转小写
$insts = array_values(array_unique($insts)); sort($insts);            // 去重并按字母排序
echo "insts=" . count($insts) . "\n";                                 // 打印合约总数

$gT0 = (time() - 365 * 86400) * 1000;                                 // 回测起始时间 = 一年前的毫秒时间戳
$results = [];                                                        // 各合约结果列表
$done = 0;                                                            // 已处理合约计数
foreach ($insts as $base) {                                           // 遍历全市场每个合约
    $tbl = "kline_{$base}_usdt_swap_15m";                             // 拼15m K线表名
    $r = $DB->query("SELECT candle_time,`c`,`h`,`l`,`o` FROM `$tbl` WHERE candle_time >= $gT0 ORDER BY candle_time ASC"); // 查一年K线(列名c/h/l/o)
    if (!$r) { $done++; continue; }                                   // 表不存在则跳过
    $ts = []; $c = []; $h = []; $l = []; $o = [];                     // 五列数组: 时间/收/高/低/开
    while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $c[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $o[] = (float)$x[4]; } // 逐行转型入数组
    $r->free();                                                       // 释放结果集
    $n = count($c);                                                   // 有效根数
    if ($n < 20) { $done++; continue; }                               // 数据不足20根跳过

    $cash = $U; $pos = null; $bankrupt = false;                       // 初始现金1U/无持仓/未破产
    $sigs = 0; $rounds = 0; $wins = 0; $pnlTotal = 0.0; $addN = 0; $liqN = 0; $toN = 0; // 信号数/笔数/胜场/累计盈亏/加仓数/爆仓数/超时数
    $trades = []; $curve = []; $tdUp = 0;                             // 逐笔明细/资金曲线/顶序列计数器
    $span = [bj($ts[0]), bj($ts[$n - 1])];                            // 该合约数据起止时间
    $days = ($ts[$n - 1] - $ts[0]) / 86400000.0;                      // 数据跨度天数

    for ($i = 4; $i < $n; $i++) {                                     // 从第5根开始(需要4根前收盘)
        $tdUp = ($c[$i] > $c[$i - 4]) ? $tdUp + 1 : 0;                // 顶序列: 收盘>4根前收盘则计数+1, 否则清零
        $sig = ($tdUp == 7 || $tdUp == 8 || $tdUp == 9);              // 买入信号: 计数恰好为7/8/9

        // ---- 1) 持仓管理: 爆仓 → 止盈 → 超时 ----
        if ($pos) {                                                   // 有持仓: 按优先级判出场
            $liqPx = $pos['avg'] * (1 - $LIQ);                        // 爆仓价 = 均价×(1-0.6%)
            if ($l[$i] <= $liqPx) {                                   // 最低价触及爆仓价
                // 爆仓: 保证金全亏(手续费开仓时已扣)
                $pnl = -$pos['u'];                                    // 盈亏 = 负全部投入本金(含历次加仓)
                $cash += 0;                                           // 现金无回笼
                $trades[] = [$pos['t'], $ts[$i], $pos['ph'], $pos['adds'], 0.0, round($pnl, 4), 'LIQ']; // 明细追加: 含信号计数ph, 出场价记0, 原因LIQ
                $pnlTotal += $pnl; $rounds++; $liqN++;                // 累计盈亏/笔数/爆仓数
                $curve[] = [$ts[$i], round($cash, 4)];                // 曲线追加当前现金
                $pos = null;                                          // 清空持仓
                if ($cash < $U) $bankrupt = true;                     // 现金不足1U → 破产停做
            } elseif ($h[$i] >= $pos['tp']) {                         // 最高价触及止盈价
                $exit = max($o[$i], $pos['tp']); // 跳空按开盘价      // 跳空高开按开盘价成交, 否则按止盈价
                $q = $pos['q'];                                       // 总仓位数量
                $gross = $q * $exit - $pos['net'];                    // 毛利 = 数量×出场价-净投入
                $feeOut = $q * $exit * $FEE;                          // 出场手续费
                $cash += $pos['u'] + $gross - $feeOut;                // 现金回笼本金+净利
                $pnl = $gross - $feeOut;                              // 该笔净盈亏
                $trades[] = [$pos['t'], $ts[$i], $pos['ph'], $pos['adds'], $exit, round($pnl, 4), 'TP']; // 明细追加, 原因TP
                $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;  // 累计盈亏/笔数/胜负
                $curve[] = [$ts[$i], round($cash, 4)];                // 曲线追加当前现金
                $pos = null;                                          // 清空持仓
            } elseif ($ts[$i] - $pos['t'] >= $TMO_MS) {               // 持仓超7天
                $exit = $c[$i];                                       // 按当根收盘价平仓
                $gross = $pos['q'] * $exit - $pos['net'];   // 毛利 = 数量×(平仓-均价) // 毛利 = 数量×出场价-净投入
                $feeOut = $pos['q'] * $exit * $FEE;                   // 出场手续费
                $cash += $pos['u'] + $gross - $feeOut;      // 回补保证金+净毛利 // 现金回笼本金+净利
                $pnl = $gross - $feeOut;                              // 该笔净盈亏
                $trades[] = [$pos['t'], $ts[$i], $pos['ph'], $pos['adds'], $exit, round($pnl, 4), 'TO']; // 明细追加, 原因TO
                $pnlTotal += $pnl; $rounds++; $toN++; if ($pnl > 0) $wins++; // 累计/超时数/胜负
                $curve[] = [$ts[$i], round($cash, 4)];                // 曲线追加当前现金
                $pos = null;                                          // 清空持仓
                if ($cash < $U) $bankrupt = true;                     // 现金不足1U → 破产停做
            }
        }
        // ---- 2) 信号: 开仓 / 加仓 (手续费从仓位内扣: 1U保证金即可开仓, notional = 100×(1-费率)) ----
        if ($sig) {                                                   // 当根有九7/八/九9信号
            $sigs++;                                                  // 信号计数+1
            if (!$pos && !$bankrupt && $cash >= $U) {                 // 空仓+未破产+资金够: 开多1U
                $net = $U * $LEV * (1 - $FEE); // 扣除开仓taker费后的实际入场名义 // 净名义投入 = 1U×100×(1-费率)
                $cash -= $U;                                          // 扣除本金
                $pos = ['t' => $ts[$i], 'ph' => $tdUp, 'q' => $net / $c[$i], 'u' => $U, // 记录: 时间/开仓时信号计数/数量/本金
                        'net' => $net, 'avg' => $c[$i], 'tp' => $c[$i] * (1 + $TP), 'adds' => 0]; // 净投入/均价/止盈价+2%/加仓次数清零
            } elseif ($pos && $cash >= $U) {                          // 持仓中再遇信号且资金够: 加仓1U
                $net = $U * $LEV * (1 - $FEE);                        // 加仓的净名义投入
                $cash -= $U;                                          // 扣除加仓本金
                $pos['q'] += $net / $c[$i];                           // 累加仓位数量
                $pos['u'] += $U; $pos['net'] += $net;                 // 累计本金/累计净投入
                $pos['avg'] = $pos['net'] / $pos['q'];                // 重算加权均价
                $pos['tp'] = $pos['avg'] * (1 + $TP);                 // 按新均价重算止盈价
                $pos['adds']++; $addN++;                              // 该笔加仓次数与总加仓数各+1
            }
        }
    }
    // ---- 3) 数据末尾强制平仓 ----
    if ($pos) {                                                       // 收尾: 期末持仓按最后收盘价强平
        $exit = $c[$n - 1];                                           // 出场价 = 最后收盘价
        $gross = $pos['q'] * $exit - $pos['net'];                     // 毛利
        $feeOut = $pos['q'] * $exit * $FEE;                           // 出场手续费
        $cash += $pos['u'] + $gross - $feeOut;                        // 现金回笼
        $pnl = $gross - $feeOut;                                      // 该笔净盈亏
        $trades[] = [$pos['t'], $ts[$n - 1], $pos['ph'], $pos['adds'], $exit, round($pnl, 4), 'END']; // 明细追加, 原因END
        $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;          // 累计盈亏/笔数/胜负
        $curve[] = [$ts[$n - 1], round($cash, 4)];                    // 曲线追加最后一点
        $pos = null;                                                  // 清空持仓
    }

    // 曲线降采样
    if (count($curve) > $CAP_CURVE) {                                 // 曲线超上限: 等间隔抽样
        $step = ceil(count($curve) / $CAP_CURVE);                     // 抽样步长
        $curve = array_filter($curve, function ($k) use ($step) { return $k % $step == 0; }, ARRAY_FILTER_USE_KEY); // 保留下标为步长整数倍的点
        $curve = array_values($curve);                                // 重建连续下标
    }
    if (count($trades) > $CAP_TRADES) $trades = array_slice($trades, 0, $CAP_TRADES); // 逐笔明细超上限则截断

    // maxdd (现金曲线)
    $maxdd = 0.0; $peak = -INF;                                       // 最大回撤/历史峰值初始化
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; } // 遍历曲线求最大回撤

    $results[] = [                                                    // 该合约完整结果入列表
        'inst' => strtoupper($base), 'days' => round($days, 1), 'bars' => $n, 'span' => $span, // 名称/跨度天数/根数/起止时间
        'sigs' => $sigs, 'n' => $rounds, 'win' => $wins,              // 信号数/笔数/胜场
        'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0,        // 胜率%
        'pnl' => round($pnlTotal, 4), 'adds' => $addN, 'liq' => $liqN, 'to' => $toN, // 盈亏/加仓数/爆仓数/超时数
        'bankrupt' => $bankrupt, 'cashEnd' => round($cash, 4), 'maxdd' => round($maxdd, 4), // 破产标志/期末现金/最大回撤
        'curve' => $curve, 'trades' => $trades,                       // 资金曲线/逐笔明细
    ];
    $done++;                                                          // 已处理计数+1
    if ($done % 40 == 0) echo "done $done / " . count($insts) . "\n"; // 每处理40个合约打印一次进度
}

usort($results, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; }); // 结果按累计盈亏降序排列(最赚的合约排前)

$tot = ['n' => 0, 'win' => 0, 'pnl' => 0, 'liq' => 0, 'adds' => 0, 'sigs' => 0, // 全市场汇总桶初始化
        'bankrupt' => 0, 'insts' => 0, 'pos' => 0, 'roundsPos' => 0, 'winsPos' => 0]; // 破产合约/参与合约/盈利合约/盈利合约笔数/盈利合约胜场
foreach ($results as $r) {                                            // 遍历各合约汇总
    if ($r['n'] == 0) continue;                                       // 无交易者跳过
    $tot['insts']++; $tot['n'] += $r['n']; $tot['win'] += $r['win']; $tot['pnl'] += $r['pnl']; // 累加合约数/笔数/胜场/盈亏
    $tot['liq'] += $r['liq']; $tot['adds'] += $r['adds']; $tot['sigs'] += $r['sigs']; // 累加爆仓/加仓/信号
    if ($r['bankrupt']) $tot['bankrupt']++;                           // 破产合约计数
    if ($r['pnl'] > 0) $tot['pos']++;                                 // 盈利合约计数
    $tot['roundsPos'] += $r['pnl'] > 0 ? $r['n'] : 0;                 // 仅盈利合约的笔数(供分层胜率)
    $tot['winsPos'] += $r['win'];                                     // 累计胜场
}

// 组合盈利曲线: 所有平仓按时间累加 pnl
$all = [];                                                            // 全市场所有平仓事件点集
foreach ($results as $r) foreach ($r['trades'] as $t) $all[] = [$t[1], $t[5]]; // 每笔取平仓时间+净盈亏
usort($all, function ($a, $b) { return $a[0] <=> $b[0]; });           // 按平仓时间升序排列
$comb = []; $acc = 0; $cnt = 0; $stepC = max(1, (int)floor(count($all) / 1500)); // 累计盈亏/计数/抽样步长约1500点
foreach ($all as $p) { $acc += $p[1]; if ($cnt++ % $stepC == 0) $comb[] = [$p[0], round($acc, 2)]; } // 逐笔累加并按步长抽样输出
if (!$comb || end($comb)[0] != (count($all) ? $all[count($all) - 1][0] : 0)) { if ($all) $comb[] = [$all[count($all) - 1][0], round($acc, 2)]; } // 补上最后一个点确保曲线完整

$out = [                                                              // 组装输出JSON
    'generated' => date('Y-m-d H:i:s'),                               // 生成时间
    'params' => ['lev' => $LEV, 'tp' => $TP, 'u' => $U, 'fee' => $FEE, 'liq' => $LIQ, 'timeout_days' => 7], // 参数快照
    'span' => [bj($gT0), bj(time() * 1000)],                          // 回测区间
    'total' => $tot, 'comb' => $comb, 'results' => $results,          // 全市场汇总/组合曲线/各合约明细
];
file_put_contents(__DIR__ . '/../web/td9topall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入web目录JSON供报告页读取
echo "saved. insts=" . count($results) . " total_pnl={$tot['pnl']} rounds={$tot['n']}\n"; // 打印: 参与合约数/总盈亏/总笔数
