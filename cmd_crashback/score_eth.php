<?php
/**
 * score_eth.php — ETH 1m MACD(12,26,60) 打分公式 阶段分值分档回测 (2026-09-27 用户指令)
 * 用户原话: "给我打分公式跑回测 给我设置每个阶段性分值 计算ROI+10%(价+0.1%) 回测一年数据
 *   告诉我到底哪个最赚钱 20X就行 eth"
 *
 * 打分公式(上一轮交付的 ATR 归一化版, 满分 8.0):
 *   Score = 2.0×min(|柱谷30|/ATR14, 3)/3      ← 深柱深度与波动挂钩(不写死-5)
 *         + 1.5×min(DIF<0连续根数,100)/100    ← 下跌动能持续期
 *         + 2.5×底背离                        ← 价新低而柱谷抬高(唯一预示大反弹的结构)
 *         + 1.0×确认(柱上穿>0)                ← 候选信号本身即满足
 *         + 1.0×放量(>1.5×MA20)              ← 反转有真实买盘
 *   候选 = MACD柱上穿>0 且 DIF<0 (0轴下动能反转)
 * 阶段分值档: 0(全部) / 1.5 / 2.0 / 2.5 / 3.0 / 3.5 / 4.0 / 5.0
 * 20x · 爆仓=价格-4.6%(亏光350U保证金) · 每轮本金350U全仓 · taker 0.05% · 超时48h
 * 止盈: 价格+2% (ROI+40% @20x)
 * 输出: web/scoreeth_data.json
 */
ini_set('memory_limit', '6144M');                                    // 提升PHP内存上限到6G, 1分钟K线数据量大
set_time_limit(0);                                                   // 取消执行时间限制, 保证长回测跑完
$LEV = 20; $MMR = 0.004; $FEE = 0.0005; $CAP = 350.0; $TMO_MS = 48 * 3600 * 1000; // 参数: 杠杆20x/维持保证金率0.4%/taker费0.05%/每轮本金350U/超时48小时
$LIQ = 1.0 / $LEV - $MMR;                                            // 爆仓跌幅 = 1/杠杆 - 维持保证金率 = -4.6%

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 建立本地MariaDB连接(trading库, utf8mb4)
$DB = db();                                                          // 获取数据库连接对象
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 毫秒时间戳转北京时间字符串(UTC+8)

// ---- 1) 加载 1m ----
$gT0 = (time() - 368 * 86400) * 1000;                                // 数据起点=368天前(毫秒), 比一年多留几天做指标预热
$r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM kline_eth_usdt_swap_1m WHERE candle_time >= $gT0 ORDER BY candle_time ASC"); // 读取ETH 1m K线(列名o/h/l/c/vol, 时间升序)
$ts = []; $o = []; $h = []; $l = []; $c = []; $v = [];               // 初始化时间/开/高/低/收/量数组
while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0) continue; $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; } // 逐行取数: 跳过收盘价<=0的脏数据
$r->free();                                                          // 释放结果集内存
$n = count($c);                                                      // K线总根数
echo "bars=$n span=" . bj($ts[0]) . " ~ " . bj($ts[$n - 1]) . "\n";  // 打印根数与数据起止时间

// ---- 2) 指标 ----
function emaAll($a, $p) {                                            // 通用EMA计算函数(数组a, 周期p)
    $n = count($a); $out = array_fill(0, $n, 0.0); $k = 2.0 / ($p + 1); $e = $a[0]; // 初始化: 长度/输出/平滑系数k=2/(p+1)
    for ($i = 0; $i < $n; $i++) { $e = ($i == 0) ? $a[0] : $a[$i] * $k + $e * (1 - $k); $out[$i] = $e; } // 首值取原始值, 之后递推EMA
    return $out;                                                     // 返回整条EMA序列
}
$e12 = emaAll($c, 12); $e26 = emaAll($c, 26);                        // 计算12周期与26周期EMA
$dif = []; for ($i = 0; $i < $n; $i++) $dif[] = $e12[$i] - $e26[$i]; // DIF = EMA12 - EMA26
$dea = emaAll($dif, 60);                                             // DEA = DIF的60周期EMA
$macd = []; for ($i = 0; $i < $n; $i++) $macd[] = 2.0 * ($dif[$i] - $dea[$i]); // MACD柱 = 2×(DIF-DEA)
// ATR14 (EMA口径)
$tr = []; $atr = [];                                                 // 初始化真实波幅与ATR数组
$tr[0] = $h[0] - $l[0]; $atr[0] = $tr[0];                            // 首根TR/ATR取当根振幅
$ka = 2.0 / 15;                                                      // ATR平滑系数=2/15(即14周期EMA口径)
for ($i = 1; $i < $n; $i++) {                                        // 逐根递推
    $tr[$i] = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1])); // TR = 三值取大: 当根振幅/跳空高/跳空低
    $atr[$i] = $tr[$i] * $ka + $atr[$i - 1] * (1 - $ka);             // ATR按EMA方式平滑
}
// vol MA20
$vma = array_fill(0, $n, 0.0); $sv = 0.0;                            // 初始化量均线数组与滑动窗口和
for ($i = 0; $i < $n; $i++) { $sv += $v[$i]; if ($i >= 20) $sv -= $v[$i - 20]; $vma[$i] = $i >= 19 ? $sv / 20 : $sv / ($i + 1); } // 20周期滚动均量(不足按实际根数均)
echo "indicators done\n";                                            // 打印指标计算完成

// ---- 3) 候选信号 + 打分 ----
$cands = [];   // [i, score, comp...]                                 // 候选信号列表: 根序号+总分
$negRun = 0;                                                         // DIF<0连续根数计数器
for ($i = 0; $i < $n; $i++) {                                        // 逐根扫描找候选信号
    if ($dif[$i] < 0) $negRun++; else $negRun = 0;                   // 维护DIF<0连续根数
    if ($i < 35 || $i < 0) continue;                                 // 前35根指标未预热, 跳过
    if (!($macd[$i] > 0 && $macd[$i - 1] <= 0 && $dif[$i] < 0)) continue; // 候选条件: MACD柱上穿0且DIF仍<0(零轴下反转)
    // comp1 深柱: 最近30根柱谷 / ATR
    $trough = 0.0;                                                   // 柱谷初值
    for ($j = max(0, $i - 30); $j < $i; $j++) if ($macd[$j] < $trough) $trough = $macd[$j]; // 找最近30根内最深柱谷
    $a = max($atr[$i - 1], 1e-9);                                    // 前一根ATR(防除零)
    $c1 = 2.0 * min(abs($trough) / $a, 3.0) / 3.0;                   // 分项1: 深柱深度ATR归一, 上限2.0分
    // comp2 持续期
    $c2 = 1.5 * min($negRun, 100) / 100.0;                           // 分项2: 下跌持续期(封顶100根), 上限1.5分
    // comp3 底背离: 本轮 DIF<0 段前后半 各自价格谷点 比价/比柱
    $c3 = 0.0;                                                       // 分项3初值
    if ($negRun >= 40) {                                             // 下跌满40根才判定背离
        $s = $i - $negRun + 1; $m = $s + intdiv($negRun - 1, 2);     // 本轮下跌段起点s与中点m
        $t1 = $s; for ($j = $s; $j <= $m; $j++) if ($l[$j] < $l[$t1]) $t1 = $j; // 前半段价格最低点t1
        $t2 = $m + 1; for ($j = $m + 1; $j < $i; $j++) if ($l[$j] < $l[$t2]) $t2 = $j; // 后半段价格最低点t2
        if ($l[$t2] < $l[$t1] && $macd[$t2] > $macd[$t1]) $c3 = 2.5; // 价创新低而柱谷抬高 → 底背离, +2.5分
    }
    // comp4 确认(上穿)恒=1; comp5 放量
    $c4 = 1.0;                                                       // 分项4: 候选信号本身即上穿确认, 恒+1分
    $c5 = ($v[$i] > 1.5 * $vma[$i - 1] && $vma[$i - 1] > 0) ? 1.0 : 0.0; // 分项5: 放量(>1.5×20期均量)则+1分
    $cands[] = ['i' => $i, 's' => $c1 + $c2 + $c3 + $c4 + $c5];      // 存入候选: 根序号+五项合计分
}
$nc = count($cands);                                                 // 候选信号总数
echo "candidates=$nc\n";                                             // 打印候选数量

// ---- 4) 每个候选一次前向走线, 止盈口径共用 ----
$TPS = ['p2' => 0.02];  // 价格+2% (ROI+40% @20x)                    // 止盈口径集合: 仅价+2%一种
$tpPxOf = [];                                                        // (预留)止盈价缓存
$rows = [];                                                          // 每个候选的模拟结果行
$netX = $CAP * $LEV * (1 - $FEE);                                    // 开仓名义扣手续费后的净名义值
foreach ($cands as $ci => $cd) {                                     // 逐个候选做前向走线模拟
    $i0 = $cd['i']; $px = $c[$i0];                                   // 信号根序号与开仓价(当根收盘)
    $liqPx = $px * (1 - $LIQ);                                       // 爆仓价 = 开仓价×(1-4.6%)
    $firstLiq = -1; $firstTP = [];                                   // 首次爆仓根/各口径首次止盈根, -1表示未发生
    foreach ($TPS as $tk => $tp) $firstTP[$tk] = -1;                 // 初始化各止盈口径的首触根
    $need = count($TPS);                                             // (预留)待触发口径数
    for ($i = $i0 + 1; $i < $n; $i++) {                              // 从信号次根起逐根前向走线
        if ($firstLiq < 0 && $l[$i] <= $liqPx) $firstLiq = $i;       // 首次触及爆仓价的根
        foreach ($TPS as $tk => $tp) {                               // 遍历各止盈口径
            if ($firstTP[$tk] < 0 && $h[$i] >= $px * (1 + $tp)) $firstTP[$tk] = $i; // 首次触及止盈价的根
        }
        // 一旦爆仓出现: 未到TP的口径全部=爆仓, 已到TP的口径保留更早的TP → 可停止走线
        // 全部口径都已到TP → 更晚的爆仓无关 → 可停止走线
        if ($firstLiq >= 0) break;                                   // 已爆仓, 后续无需再看
        $all = true; foreach ($firstTP as $f) if ($f < 0) { $all = false; break; } // 检查是否所有口径都已止盈
        if ($all) break;                                             // 全部止盈触发, 停止走线
        if ($ts[$i] - $ts[$i0] >= $TMO_MS) break; // 超时, 剩余口径按超时
    }
    $q = $netX / $px; $feeOut = $q * $FEE;                           // 持仓数量=净名义/价, 平仓手续费
    $row = ['t0' => $ts[$i0], 'px' => $px, 's' => round($cd['s'], 2)]; // 结果行: 开仓时间/价/评分
    foreach ($TPS as $tk => $tp) {                                   // 逐口径确定出场方式与盈亏
        $fi = $firstTP[$tk]; $fl = $firstLiq;                        // 该口径首次止盈根与首次爆仓根
        if ($fl >= 0 && ($fi < 0 || $fl <= $fi)) { $exit = $liqPx; $why = 'LIQ'; $ti = $fl; } // 爆仓先于止盈 → 按爆仓出场
        elseif ($fi >= 0) { $exit = max($o[$fi], $px * (1 + $tp)); $why = 'TP'; $ti = $fi; } // 止盈出场价=max(当根开盘, 止盈价), 防跳空高估
        else { // 超时或期末
            $ti = -1;                                                // 出场根初始化为未找到
            for ($i = $i0 + 1; $i < $n; $i++) if ($ts[$i] - $ts[$i0] >= $TMO_MS) { $ti = $i; break; } // 找首个超时48h的根
            if ($ti < 0) $ti = $n - 1;                               // 没超时则按最后一根(期末)平
            $exit = $c[$ti]; $why = ($ts[$ti] - $ts[$i0] >= $TMO_MS) ? 'TO' : 'END'; // 出场原因: 超时TO或数据末尾END
        }
        $pnl = ($why == 'LIQ') ? -$CAP : $q * ($exit - $px) - $q * $exit * $FEE; // 盈亏: 爆仓亏光350U本金; 其余=数量×价差-平仓手续费
        $row[$tk] = ['pnl' => $pnl, 'why' => $why, 't1' => $ts[$ti]]; // 存该口径结果: 盈亏/原因/平仓时间
    }
    $rows[] = $row;                                                  // 收入结果集
    if ($ci % 2000 == 0) echo "sim $ci/$nc\n";                       // 每2000个候选打印一次进度
}
echo "sim done\n";                                                   // 模拟全部完成

// ---- 5) 分档聚合 ----
// 注意: PHP 浮点数不能当数组键(会被截断成int导致 2.0/2.5 互相覆盖), 一律用规范化字符串键
function tkey($th) { $s = number_format((float)$th, 1, '.', ''); return rtrim(rtrim($s, '0'), '.'); } // 分值阈值转规范化字符串键(如"2.0"→"2")
$THS = [0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 5.0];                       // 阶段分值档位列表
$THK = array_map('tkey', $THS);                                      // 各档位对应的字符串键
$agg = [];                                                           // 分档聚合结果
foreach ($THS as $idx => $th) {                                      // 遍历每个分值档
    $k = $THK[$idx];                                                 // 该档的字符串键
    foreach ($TPS as $tk => $tp) {                                   // 遍历每个止盈口径
        $nT = 0; $pnl = 0.0; $w = 0; $liq = 0; $to = 0;              // 初始化: 笔数/总盈亏/盈利数/爆仓数/超时数
        foreach ($rows as $rw) {                                     // 遍历所有候选结果
            if ($rw['s'] < $th) continue;                            // 评分低于该档阈值则不计入
            $nT++; $p = $rw[$tk]['pnl']; $pnl += $p;                 // 计入笔数并累加盈亏
            if ($p > 0) $w++; if ($rw[$tk]['why'] == 'LIQ') $liq++; if ($rw[$tk]['why'] == 'TO') $to++; // 分别统计盈利/爆仓/超时
        }
        $agg[$k][$tk] = ['n' => $nT, 'pnl' => round($pnl, 2),        // 存聚合结果: 笔数/总盈亏
            'roi' => $nT ? round($pnl / ($CAP * $nT) * 100, 2) : 0,  // 总ROI%(按投入本金合计)
            'avg' => $nT ? round($pnl / $nT, 3) : 0,                 // 单笔平均盈亏
            'wr' => $nT ? round($w / $nT * 100, 1) : 0, 'liq' => $liq, 'to' => $to]; // 胜率%/爆仓数/超时数
        printf("th>=%.1f %-6s n=%-6d wr=%5.1f%% liq=%-5d pnl=%+11.2f roi=%+7.2f%%\n", $th, $tk, $nT, $agg[$k][$tk]['wr'], $liq, $pnl, $agg[$k][$tk]['roi']); // 打印各档统计行
    }
}
// 分值分布(0.5一档, 字符串键)
$hist = [];                                                          // 分值直方图
foreach ($rows as $rw) { $bin = (int)floor($rw['s'] * 2); $key = number_format($bin / 2, 1); $hist[$key] = ($hist[$key] ?? 0) + 1; } // 按0.5分一档计数
ksort($hist);                                                        // 按分值升序排列直方图

// 明细样本(最多3000行, 均匀抽样)
$step = max(1, intdiv(count($rows), 3000));                          // 抽样步长: 保证样本≤3000行
$samp = [];                                                          // 明细样本列表
for ($k = 0; $k < count($rows); $k += $step) {                       // 按步长均匀抽取
    $rw = $rows[$k];                                                 // 取该行结果
    $samp[] = [bj($rw['t0']), $rw['s'], round($rw['px'], 2),         // 样本字段: 开仓时间/评分/开仓价
        round($rw['p2']['pnl'], 2), $rw['p2']['why']];               // 价+2%口径的盈亏与出场原因
}
$out = ['generated' => date('Y-m-d H:i:s'),                          // 输出结构: 生成时间
    'params' => ['macd' => '12,26,60', 'lev' => $LEV, 'liq' => round($LIQ, 4), 'cap' => $CAP, 'fee' => $FEE, 'timeout_h' => 48, // 参数: MACD周期/杠杆/爆仓跌幅/本金/费率/超时
        'score_formula' => '2.0*min(|柱谷30|/ATR14,3)/3 + 1.5*min(DIF<0根数,100)/100 + 2.5*底背离 + 1.0*确认 + 1.0*放量'], // 评分公式文本
    'span' => [bj($ts[0]), bj($ts[$n - 1])], 'bars' => $n, 'candidates' => $nc, // 数据区间/根数/候选数
    'tpNames' => ['p2' => '价+2% (ROI+40% @20x)'],                   // 止盈口径名称
    'thresholds' => $THK, 'agg' => $agg, 'hist' => $hist, 'samples' => $samp]; // 分值档/聚合结果/分布直方图/明细样本
file_put_contents(__DIR__ . '/../web/scoreeth_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入JSON供报告页读取(中文不转义)
echo "saved size=" . round(filesize(__DIR__ . '/../web/scoreeth_data.json') / 1048576, 2) . "MB\n";   // 打印输出文件大小(MB)
