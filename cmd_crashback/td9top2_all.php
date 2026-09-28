<?php
/**
 * td9top2_all.php — 全市场 15m 九1~九9上涨买入×三均线加仓 10x 一年回测生成器 (2026-09-26 用户指令)
 * 用户原话: "改成10X 然后继续 算15分钟 九1-9 9个 买入信号 每笔1美金 持仓均价+2%(价格) 市价全平
 *           不设止损 爆仓线0.6%(按10x折算=1/10-维持0.4%≈9.6%) 超时7天平仓 taker 0.05% 进出
 *           算一下 如何才可以不爆仓 赚很多钱 胜率可以低点 加仓1美金每次 加仓按 triple_ma_bull 三均线多头排列(7>25>99) 15分钟"
 *
 * 口径:
 *   买入 = 15m 顶序列计数 1~9 全部(连续N根收盘>第4根前收盘, 上涨中, 断即清零) → 空仓即开多 1U
 *   加仓 = 持仓中 triple_ma_bull(15m: MA7>MA25>MA99 且收>MA7) 每根触发 +1U (四组对照: 不限/12轮/5轮/禁止)
 *   止盈 = 持仓均价 +2%(价格) 市价全平 | 不设止损 | 爆仓 = 跌幅≥1/10-0.4% = 9.6% | 超时7天 | 数据末尾平仓
 *   杠杆10x: 1U保证金=10U名义 | taker 0.05% 进出(开仓从仓位内扣) | 每合约独立1U本金, 现金<1U = 破产停做
 * 输出: web/td9top2all_data.json
 */
ini_set('memory_limit', '4096M');                                     // 提升PHP内存上限到4G, 全市场15mK线数据量大
set_time_limit(0);                                                    // 取消脚本执行时间限制
$LEV = 10; $MMR = 0.004; $FEE = 0.0005; $TP = 0.02; $U = 1.0;         // 杠杆10x / 维持保证金率0.4% / taker费率0.05% / 止盈+2% / 每笔本金1U
$TMO_MS = 7 * 86400 * 1000;                                           // 持仓超时阈值7天(毫秒), 到期按收盘价平仓
$LIQ = 1.0 / $LEV - $MMR; // 0.096                                    // 爆仓跌幅 = 1/10 - 0.4% = 9.6%
$CAP_TRADES = 250; $CAP_CURVE = 120;                                  // 每合约逐笔明细上限250笔 / 资金曲线上限120点
$VARIANTS = ['A' => '不限加仓', 'B' => '加仓≤12轮', 'C' => '加仓≤5轮', 'D' => '禁止加仓']; // 四个加仓对照变体的展示名
$VCAP = ['A' => PHP_INT_MAX, 'B' => 12, 'C' => 5, 'D' => 0];          // 各变体的加仓轮数上限: 不限/12/5/0

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

/** 单变模拟: 返回该合约该变体的回测结果 */
function sim($ts, $o, $h, $l, $c, $maOK, $addCap, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS) { // 模拟器: 输入K线+三均线信号+加仓上限
    global $CAP_TRADES, $CAP_CURVE, $VCAP;                            // 引用全局明细/曲线/变体上限
    $n = count($c);                                                   // K线总根数
    $cash = $U; $pos = null; $bankrupt = false;                       // 初始现金1U/无持仓/未破产
    $sigs = 0; $rounds = 0; $wins = 0; $pnlTotal = 0.0; $addN = 0; $liqN = 0; $toN = 0; // 信号数/笔数/胜场/累计盈亏/加仓数/爆仓数/超时数
    $trades = []; $curve = [];                                        // 逐笔明细/资金曲线
    $tdUp = 0;                                                        // 顶序列计数器清零
    for ($i = 4; $i < $n; $i++) {                                     // 从第5根开始(需要4根前收盘)
        $tdUp = ($c[$i] > $c[$i - 4]) ? $tdUp + 1 : 0;                // 顶序列: 收盘>4根前收盘则计数+1, 否则清零
        $entrySig = ($tdUp >= 1 && $tdUp <= 9);                       // 买入信号: 计数在1~9之间(九1~九9上涨中)
        $maSig = $maOK ? $maOK[$i] : false;                           // 加仓信号: 当根三均线多头排列

        // ---- 1) 持仓管理: 爆仓 → 止盈 → 超时 ----
        if ($pos) {                                                   // 有持仓: 按优先级判出场
            $liqPx = $pos['avg'] * (1 - $LIQ);                        // 爆仓价 = 均价×(1-9.6%)
            if ($l[$i] <= $liqPx) {                                   // 最低价触及爆仓价: 本金全损
                $cash += 0; $pnl = -$pos['u'];                        // 现金无回笼, 盈亏=负全部投入本金
                $trades[] = [$pos['t'], $ts[$i], $pos['adds'], 0.0, round($pnl, 4), 'LIQ']; // 明细追加, 出场价记0, 原因LIQ
                $pnlTotal += $pnl; $rounds++; $liqN++;                // 累计盈亏/笔数/爆仓数
                $curve[] = [$ts[$i], round($cash, 4)];                // 曲线追加当前现金
                $pos = null;                                          // 清空持仓
                if ($cash < $U) $bankrupt = true;                     // 现金不足1U → 破产停做
            } elseif ($h[$i] >= $pos['tp']) {                         // 最高价触及止盈价
                $exit = max($o[$i], $pos['tp']);                      // 跳空高开按开盘价成交, 否则按止盈价
                $gross = $pos['q'] * $exit - $pos['net'];             // 毛利 = 数量×出场价-净投入
                $feeOut = $pos['q'] * $exit * $FEE;                   // 出场手续费
                $cash += $pos['u'] + $gross - $feeOut;                // 现金回笼本金+净利
                $pnl = $gross - $feeOut;                              // 该笔净盈亏
                $trades[] = [$pos['t'], $ts[$i], $pos['adds'], $exit, round($pnl, 4), 'TP']; // 明细追加, 原因TP
                $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;  // 累计盈亏/笔数/胜负
                $curve[] = [$ts[$i], round($cash, 4)];                // 曲线追加当前现金
                $pos = null;                                          // 清空持仓
            } elseif ($ts[$i] - $pos['t'] >= $TMO_MS) {               // 持仓超7天
                $exit = $c[$i];                                       // 按当根收盘价平仓
                $gross = $pos['q'] * $exit - $pos['net'];             // 毛利
                $feeOut = $pos['q'] * $exit * $FEE;                   // 出场手续费
                $cash += $pos['u'] + $gross - $feeOut;                // 现金回笼
                $pnl = $gross - $feeOut;                              // 该笔净盈亏
                $trades[] = [$pos['t'], $ts[$i], $pos['adds'], $exit, round($pnl, 4), 'TO']; // 明细追加, 原因TO
                $pnlTotal += $pnl; $rounds++; $toN++; if ($pnl > 0) $wins++; // 累计/超时数/胜负
                $curve[] = [$ts[$i], round($cash, 4)];                // 曲线追加当前现金
                $pos = null;                                          // 清空持仓
                if ($cash < $U) $bankrupt = true;                     // 现金不足1U → 破产停做
            }
        }
        // ---- 2) 开仓: 九1~九9 上涨信号 ----
        if (!$pos && !$bankrupt && $entrySig && $cash >= $U) {        // 空仓+未破产+有信号+资金够: 开多1U
            $sigs++;                                                  // 信号计数+1
            $net = $U * $LEV * (1 - $FEE);                            // 净名义投入 = 1U×10×(1-费率)
            $cash -= $U;                                              // 扣除本金
            $pos = ['t' => $ts[$i], 'q' => $net / $c[$i], 'u' => $U, 'net' => $net, // 记录: 开仓时间/数量/本金/净投入
                    'avg' => $c[$i], 'tp' => $c[$i] * (1 + $TP), 'adds' => 0]; // 均价=收盘/止盈价+2%/加仓次数清零
        } elseif ($pos && $maSig && $pos['adds'] < $addCap && $cash >= $U) { // 持仓+三均线信号+未达加仓上限+资金够
            // ---- 3) 加仓: 三均线多头排列 ----
            $sigs++;                                                  // 信号计数+1
            $net = $U * $LEV * (1 - $FEE);                            // 加仓的净名义投入
            $cash -= $U;                                              // 扣除加仓本金
            $pos['q'] += $net / $c[$i];                               // 累加仓位数量
            $pos['u'] += $U; $pos['net'] += $net;                     // 累计本金/累计净投入
            $pos['avg'] = $pos['net'] / $pos['q'];                    // 重算加权均价
            $pos['tp'] = $pos['avg'] * (1 + $TP);                     // 按新均价重算止盈价
            $pos['adds']++; $addN++;                                  // 该笔加仓次数与总加仓数各+1
        }
    }
    if ($pos) {                                                       // 收尾: 期末持仓按最后收盘价强平
        $exit = $c[$n - 1];                                           // 出场价 = 最后收盘价
        $gross = $pos['q'] * $exit - $pos['net'];                     // 毛利
        $feeOut = $pos['q'] * $exit * $FEE;                           // 出场手续费
        $cash += $pos['u'] + $gross - $feeOut;                        // 现金回笼
        $pnl = $gross - $feeOut;                                      // 该笔净盈亏
        $trades[] = [$pos['t'], $ts[$n - 1], $pos['adds'], $exit, round($pnl, 4), 'END']; // 明细追加, 原因END
        $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;          // 累计盈亏/笔数/胜负
        $curve[] = [$ts[$n - 1], round($cash, 4)];                    // 曲线追加最后一点
    }
    if (count($curve) > $CAP_CURVE) {                                 // 曲线超上限: 等间隔抽样
        $step = (int)ceil(count($curve) / $CAP_CURVE);                // 抽样步长
        $c2 = []; foreach ($curve as $k => $p) if ($k % $step == 0) $c2[] = $p; // 每隔step取一点
        $curve = $c2;                                                 // 替换为抽样后曲线
    }
    if (count($trades) > $CAP_TRADES) $trades = array_slice($trades, 0, $CAP_TRADES); // 逐笔明细超上限则截断
    $maxdd = 0.0; $peak = -INF;                                       // 最大回撤/历史峰值初始化
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; } // 遍历曲线求最大回撤
    return ['sigs' => $sigs, 'n' => $rounds, 'win' => $wins,          // 返回统计: 信号数/笔数/胜场
            'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0,    // 胜率%
            'pnl' => round($pnlTotal, 4), 'adds' => $addN, 'liq' => $liqN, 'to' => $toN, // 盈亏/加仓数/爆仓数/超时数
            'bankrupt' => $bankrupt, 'cashEnd' => round($cash, 4),    // 破产标志/期末现金
            'maxdd' => round($maxdd, 4), 'curve' => $curve, 'trades' => $trades]; // 最大回撤/曲线/逐笔明细
}

foreach ($insts as $base) {                                           // 遍历全市场每个合约
    $tbl = "kline_{$base}_usdt_swap_15m";                             // 拼15m K线表名
    $r = $DB->query("SELECT candle_time,`c`,`h`,`l`,`o` FROM `$tbl` WHERE candle_time >= $gT0 ORDER BY candle_time ASC"); // 查一年K线(注意列名是c/h/l/o)
    if (!$r) { $done++; continue; }                                   // 表不存在则跳过
    $ts = []; $c = []; $h = []; $l = []; $o = [];                     // 五列数组: 时间/收/高/低/开
    while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $c[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $o[] = (float)$x[4]; } // 逐行转型入数组
    $r->free();                                                       // 释放结果集
    $n = count($c);                                                   // 有效根数
    if ($n < 110) { $done++; continue; }                              // 不足110根跳过

    // 三均线多头排列(与引擎 signals.go sigTripleMA 同口径: MA7>MA25>MA99 且 收>MA7)
    $maOK = array_fill(0, $n, false);                                 // 加仓信号布尔数组
    $s7 = 0.0; $s25 = 0.0; $s99 = 0.0;                                // MA7/MA25/MA99的滑动和
    for ($i = 0; $i < $n; $i++) {                                     // 逐根滑动窗口计算
        $s7 += $c[$i]; $s25 += $c[$i]; $s99 += $c[$i];                // 三窗口各加入当根收盘
        if ($i >= 7) $s7 -= $c[$i - 7];                               // MA7窗口滑出最老一根
        if ($i >= 25) $s25 -= $c[$i - 25];                            // MA25窗口滑出最老一根
        if ($i >= 99) $s99 -= $c[$i - 99];                            // MA99窗口滑出最老一根
        if ($i >= 99) {                                               // 攒满99根才可判定
            $m7 = $s7 / 7; $m25 = $s25 / 25; $m99 = $s99 / 99;        // 三均线值 = 各滑动和/周期
            $maOK[$i] = ($m7 > $m25 && $m25 > $m99 && $c[$i] > $m7);  // 多头排列且收>MA7时标记加仓信号
        }
    }

    $row = ['inst' => strtoupper($base), 'days' => round(($ts[$n - 1] - $ts[0]) / 86400000.0, 1), // 该合约基础信息: 名称/数据跨度天数
            'bars' => $n, 'span' => [bj($ts[0]), bj($ts[$n - 1])], 'variants' => []]; // 根数/起止时间/变体容器
    foreach ($VCAP as $vk => $cap) {                                  // 遍历四个加仓对照变体
        $v = sim($ts, $o, $h, $l, $c, $maOK, $cap, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS); // 逐变体模拟
        if ($vk !== 'A') { unset($v['trades']); if (count($v['curve']) > 40) $v['curve'] = array_slice($v['curve'], 0, 40); } // 非A变体精简输出: 去明细/曲线截到40点, 减小JSON体积
        $row['variants'][$vk] = $v;                                   // 存入该合约变体结果
    }
    $results[] = $row;                                                // 存入总结果列表
    $done++;                                                          // 已处理计数+1
    if ($done % 40 == 0) echo "done $done\n";                         // 每处理40个合约打印一次进度
}

// 汇总
$tot = [];                                                            // 全市场四变体汇总桶
foreach ($VCAP as $vk => $vn) {                                       // 按变体汇总
    $t = ['n' => 0, 'win' => 0, 'pnl' => 0, 'liq' => 0, 'adds' => 0, 'bankrupt' => 0, 'insts' => 0, 'pos' => 0]; // 汇总字段初始化
    foreach ($results as $r) { $v = $r['variants'][$vk]; if ($v['n'] == 0) continue; // 遍历各合约, 无交易者跳过
        $t['insts']++; $t['n'] += $v['n']; $t['win'] += $v['win']; $t['pnl'] += $v['pnl']; // 累加合约数/笔数/胜场/盈亏
        $t['liq'] += $v['liq']; $t['adds'] += $v['adds'];             // 累加爆仓数/加仓数
        if ($v['bankrupt']) $t['bankrupt']++;                         // 破产合约计数
        if ($v['pnl'] > 0) $t['pos']++; }                             // 盈利合约计数
    $tot[$vk] = $t;                                                   // 存入汇总
}

// 组合曲线(按变体): A 用逐笔平仓盈亏; B/C/D 用各合约 cash 曲线差分
$comb = [];                                                           // 四变体的全市场组合累计盈亏曲线
foreach ($VCAP as $vk => $vn) {                                       // 逐变体拼装
    $pts = [];                                                        // [时间, 盈亏增量]点集
    if ($vk === 'A') {                                                // A变体: 直接用逐笔平仓盈亏
        foreach ($results as $r) foreach ($r['variants']['A']['trades'] as $tr) $pts[] = [$tr[1], $tr[4]]; // 每笔取平仓时间+盈亏
    } else {                                                          // B/C/D: 曲线被抽样过, 用相邻点现金差分还原增量
        foreach ($results as $r) {                                    // 遍历各合约
            $prev = 1.0;                                              // 前值从初始1U起
            foreach ($r['variants'][$vk]['curve'] as $p) { $pts[] = [$p[0], $p[1] - $prev]; $prev = $p[1]; } // 差分出各时间点的盈亏增量
        }
    }
    usort($pts, function ($a, $b) { return $a[0] <=> $b[0]; });       // 按时间升序合并全市场所有点
    $acc = 0; $out = []; $cnt = 0;                                    // 累计盈亏/输出曲线/计数
    $stepC = max(1, (int)floor(count($pts) / 1200));                  // 抽样步长: 控制组合曲线约1200点内
    foreach ($pts as $p) { $acc += $p[1]; if ($cnt++ % $stepC == 0) $out[] = [$p[0], round($acc, 2)]; } // 逐点累加并按步长抽样输出
    if ($pts) $out[] = [$pts[count($pts) - 1][0], round($acc, 2)];    // 补上最后一个点确保曲线完整
    $comb[$vk] = $out;                                                // 存入组合曲线
}

$out = ['generated' => date('Y-m-d H:i:s'),                           // 组装输出: 生成时间
        'params' => ['lev' => $LEV, 'tp' => $TP, 'u' => $U, 'fee' => $FEE, 'liq' => $LIQ, 'timeout_days' => 7], // 参数快照
        'variants' => $VARIANTS, 'span' => [bj($gT0), bj(time() * 1000)], // 变体名表/回测区间
        'total' => $tot, 'comb' => $comb, 'results' => $results];     // 全市场汇总/组合曲线/各合约明细
file_put_contents(__DIR__ . '/../web/td9top2all_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入web目录JSON供报告页读取
echo "saved size=" . round(filesize(__DIR__ . '/../web/td9top2all_data.json') / 1048576, 1) . "MB\n";   // 打印输出文件大小(MB)
foreach ($VCAP as $vk => $vn) echo "$vk $vn: pnl={$tot[$vk]['pnl']} n={$tot[$vk]['n']} liq={$tot[$vk]['liq']} pos_insts={$tot[$vk]['pos']}\n"; // 控制台汇总: 各变体盈亏/笔数/爆仓数/盈利合约数
