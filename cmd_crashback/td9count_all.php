<?php
/**
 * td9count_all.php — 全市场 1H/4H 九1~九9 逐计数选优 一年回测生成器 (2026-09-26 用户指令)
 * 用户原话: "1H 4H 给我测试 九1~九9 计算 哪种算买入信号 可以盈利一年 赚很多
 *           加仓+1U 按 triple_ma_bull 三均线多头排列(MA7>25>99 且收>MA7) · 超时7天 · taker 0.05% 进出 · 每合约独立1U本金"
 *
 * 口径(与 td9top2_all.php 10x 版一致):
 *   买入 = 15m→改 1H/4H 顶序列计数 恰好=k (k=1..9 逐个测, 连续N根收盘>第4根前收盘, 上涨中, 断即清零)
 *   加仓 = 持仓中 triple_ma_bull(MA7>MA25>MA99 且收>MA7) 每根触发 +1U (现金允许时, 不限轮)
 *   10x(爆仓线 1/10-0.4%=9.6%) | 止盈 = 均价+2%(价格) 市价全平 | 不设止损 | 超时7天 | 数据末尾平仓
 *   taker 0.05% 进出(开仓从仓位内扣) | 每合约独立1U本金, 现金<1U 破产停做
 * 输出: web/td9countall_data.json (每 bar 只存最优计数的 trades/curve 供下钻)
 */
ini_set('memory_limit', '4096M');                                    // 提升PHP内存上限到4G, 全市场×2周期×9计数数据量大
set_time_limit(0);                                                   // 取消执行时间限制
$LEV = 10; $MMR = 0.004; $FEE = 0.0005; $TP = 0.02; $U = 1.0;        // 参数: 杠杆10x/维持保证金率0.4%/taker费0.05%/止盈2%/每合约本金1U
$TMO_MS = 7 * 86400 * 1000;                                          // 超时=7天(毫秒), 到期强制平仓
$LIQ = 1.0 / $LEV - $MMR;                                            // 爆仓跌幅 = 1/10 - 0.4% = 9.6%
$BARS = ['1h' => '1H', '4h' => '4H'];                                // 两种K线周期(键=表名后缀, 值=显示名)
$CAP = 200; $CAP_CURVE = 120;                                        // 明细成交最多保留200笔 / 盈利曲线最多120个点

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 建立本地MariaDB连接(trading库, utf8mb4)
$DB = db();                                                          // 获取数据库连接对象
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 毫秒时间戳转北京时间字符串(UTC+8)

$insts = [];                                                         // 币种基础名列表(如 eth)
$j = json_decode(file_get_contents('E:/datas/insts_all.json'), true); // 读取全市场合约清单JSON
foreach ($j['insts'] as $s) if (preg_match('/^(.+)-USDT-SWAP$/', $s, $m2)) $insts[] = strtolower($m2[1]); // 只取USDT永续, 提取基础名并转小写
$insts = array_values(array_unique($insts)); sort($insts);           // 去重并按名称排序
echo "insts=" . count($insts) . "\n";                                // 打印币种数量

$gT0 = (time() - 365 * 86400) * 1000;                                // 数据起点=365天前(毫秒)

function sim($ts, $o, $h, $l, $c, $maOK, $K, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, $withDetail) { // 模拟函数: K线+三均线多头标记+计数K阈值
    global $CAP, $CAP_CURVE;                                         // 引用全局的明细/曲线数量上限
    $n = count($c);                                                  // K线总根数
    $cash = $U; $pos = null; $bankrupt = false;                      // 初始化: 现金1U/持仓/破产标记
    $rounds = 0; $wins = 0; $pnlTotal = 0.0; $addN = 0; $liqN = 0; $toN = 0; // 统计: 轮数/盈利数/总盈亏/加仓数/爆仓数/超时数
    $trades = []; $curve = []; $tdUp = 0;                            // 明细列表/盈利曲线/顶序列计数
    for ($i = 4; $i < $n; $i++) {                                    // 主循环: 从第5根起(需要前4根比较)
        $tdUp = ($c[$i] > $c[$i - 4]) ? $tdUp + 1 : 0;               // 顶序列: 收盘>4根前收盘则计数+1, 否则清零
        $entrySig = ($tdUp == $K);                                   // 入场信号: 顶序列计数恰好等于K(九K)
        if ($pos) {                                                  // 有持仓时先处理出场
            $liqPx = $pos['avg'] * (1 - $LIQ);                       // 爆仓价 = 均价×(1-9.6%)
            if ($l[$i] <= $liqPx) {                                  // 当根最低价触及爆仓价 → 爆仓
                $cash += 0; $pnl = -$pos['u'];                       // 保证金全损: 现金不回补, 亏损=累计保证金
                if ($withDetail) $trades[] = [$pos['t'], $ts[$i], $pos['adds'], 0.0, round($pnl, 4), 'LIQ']; // 记录爆仓明细(出场价0表示爆仓)
                $pnlTotal += $pnl; $rounds++; $liqN++;               // 累加盈亏/轮数/爆仓数
                $curve[] = [$ts[$i], round($cash, 4)];               // 记录盈利曲线点
                $pos = null;                                         // 清空持仓
                if ($cash < $U) $bankrupt = true;                    // 现金不足1U本金 → 破产停做
            } elseif ($h[$i] >= $pos['tp']) {                        // 当根最高价触及止盈价 → 止盈
                $exit = max($o[$i], $pos['tp']);                     // 出场价=max(当根开盘, 止盈价), 防跳空高估
                $gross = $pos['q'] * $exit - $pos['net'];            // 毛利 = 数量×出场价 - 净名义成本
                $feeOut = $pos['q'] * $exit * $FEE;                  // 平仓手续费
                $cash += $pos['u'] + $gross - $feeOut;               // 现金回补: 保证金+毛利-平仓手续费
                $pnl = $gross - $feeOut;                             // 本轮净盈亏
                if ($withDetail) $trades[] = [$pos['t'], $ts[$i], $pos['adds'], $exit, round($pnl, 4), 'TP']; // 记录止盈明细
                $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++; // 累加盈亏/轮数/盈利数
                $curve[] = [$ts[$i], round($cash, 4)];               // 记录盈利曲线点
                $pos = null;                                         // 清空持仓
            } elseif ($ts[$i] - $pos['t'] >= $TMO_MS) {              // 持仓满7天 → 超时平仓
                $exit = $c[$i];                                      // 出场价=当根收盘价
                $gross = $pos['q'] * $exit - $pos['net'];            // 毛利 = 数量×出场价 - 净名义成本
                $feeOut = $pos['q'] * $exit * $FEE;                  // 平仓手续费
                $cash += $pos['u'] + $gross - $feeOut;               // 现金回补
                $pnl = $gross - $feeOut;                             // 本轮净盈亏
                if ($withDetail) $trades[] = [$pos['t'], $ts[$i], $pos['adds'], $exit, round($pnl, 4), 'TO']; // 记录超时明细
                $pnlTotal += $pnl; $rounds++; $toN++; if ($pnl > 0) $wins++; // 累加盈亏/轮数/超时数/盈利数
                $curve[] = [$ts[$i], round($cash, 4)];               // 记录盈利曲线点
                $pos = null;                                         // 清空持仓
                if ($cash < $U) $bankrupt = true;                    // 现金不足1U本金 → 破产停做
            }
        }
        if (!$pos && !$bankrupt && $entrySig && $cash >= $U) {       // 空仓/未破产/出现九K信号/现金足 → 开首仓
            $net = $U * $LEV * (1 - $FEE);                           // 净名义 = 1U×10x×(1-费率)
            $cash -= $U;                                             // 现金扣减1U保证金
            $pos = ['t' => $ts[$i], 'q' => $net / $c[$i], 'u' => $U, 'net' => $net, // 建仓: 时间/数量/本金/净名义
                    'avg' => $c[$i], 'tp' => $c[$i] * (1 + $TP), 'adds' => 0]; // 均价/止盈价(+2%)/加仓次数
        } elseif ($pos && $maOK[$i] && $cash >= $U) {                // 持仓中遇三均线多头排列且现金足 → 加仓+1U
            $net = $U * $LEV * (1 - $FEE);                           // 本次加仓的净名义
            $cash -= $U;                                             // 现金扣减1U保证金
            $pos['q'] += $net / $c[$i];                              // 数量累加
            $pos['u'] += $U; $pos['net'] += $net;                    // 保证金与净名义累加
            $pos['avg'] = $pos['net'] / $pos['q'];                   // 重算持仓均价
            $pos['tp'] = $pos['avg'] * (1 + $TP);                    // 止盈价跟随均价上移
            $pos['adds']++; $addN++;                                 // 持仓加仓次数与全局加仓数+1
        }
    }
    if ($pos) {                                                      // 期末仍持仓 → 按最后收盘价标记平仓
        $exit = $c[$n - 1];                                          // 出场价=最后一根收盘价
        $gross = $pos['q'] * $exit - $pos['net'];                    // 毛利
        $feeOut = $pos['q'] * $exit * $FEE;                          // 平仓手续费
        $cash += $pos['u'] + $gross - $feeOut;                       // 现金回补
        $pnl = $gross - $feeOut;                                     // 本轮净盈亏
        if ($withDetail) $trades[] = [$pos['t'], $ts[$n - 1], $pos['adds'], $exit, round($pnl, 4), 'END']; // 记录期末平仓明细(原因END)
        $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;         // 累加盈亏/轮数/盈利数
        $curve[] = [$ts[$n - 1], round($cash, 4)];                   // 追加盈利曲线终点
    }
    if (count($curve) > $CAP_CURVE) {                                // 曲线点超过上限时抽样压缩
        $step = (int)ceil(count($curve) / $CAP_CURVE);               // 抽样步长
        $c2 = []; foreach ($curve as $k2 => $p) if ($k2 % $step == 0) $c2[] = $p; // 每隔step取一点
        $curve = $c2;                                                // 用压缩后的曲线替换
    }
    $maxdd = 0.0; $peak = -INF;                                      // 最大回撤与历史峰值初始化
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; } // 遍历曲线求最大回撤
    $r = ['n' => $rounds, 'win' => $wins, 'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0, // 结果: 轮数/盈利数/胜率%
          'pnl' => round($pnlTotal, 4), 'adds' => $addN, 'liq' => $liqN, 'to' => $toN, // 总盈亏/加仓数/爆仓数/超时数
          'bankrupt' => $bankrupt, 'cashEnd' => round($cash, 4), 'maxdd' => round($maxdd, 4)]; // 破产标记/期末现金/最大回撤
    if ($withDetail) { $r['curve'] = $curve; if (count($trades) > $CAP) $trades = array_slice($trades, 0, $CAP); $r['trades'] = $trades; } // 需要明细时附上曲线与截断后的成交列表
    return $r;                                                       // 返回该计数结果
}

$results = []; $matrix = [];                                         // 初始化: 各周期明细/汇总矩阵
foreach ($BARS as $bk => $okxBar) {                                  // 外层: 遍历1H/4H两种周期
    $resBar = []; $done = 0;                                         // 该周期结果行列表与完成计数
    foreach ($insts as $base) {                                      // 中层: 遍历全部币种
        $tbl = "kline_{$base}_usdt_swap_$bk";                        // 拼接K线表名
        $r = $DB->query("SELECT candle_time,`c`,`h`,`l`,`o` FROM `$tbl` WHERE candle_time >= $gT0 ORDER BY candle_time ASC"); // 读取该币一年K线(时间升序)
        if (!$r) { $done++; continue; }                              // 表不存在/查询失败则跳过
        $ts = []; $c = []; $h = []; $l = []; $o = [];                // 初始化时间/收/高/低/开数组
        while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $c[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $o[] = (float)$x[4]; } // 逐行取数
        $r->free();                                                  // 释放结果集
        $n = count($c);                                              // 该币K线根数
        if ($n < 110) { $done++; continue; }                         // 数据不足110根则跳过

        $maOK = array_fill(0, $n, false);                            // 三均线多头排列标记数组(逐根)
        $s7 = 0.0; $s25 = 0.0; $s99 = 0.0;                           // MA7/MA25/MA99滑动窗口和
        for ($i = 0; $i < $n; $i++) {                                // 逐根计算均线与多头标记
            $s7 += $c[$i]; $s25 += $c[$i]; $s99 += $c[$i];           // 收盘价加入各窗口
            if ($i >= 7) $s7 -= $c[$i - 7];                          // MA7窗口滑出旧值
            if ($i >= 25) $s25 -= $c[$i - 25];                       // MA25窗口滑出旧值
            if ($i >= 99) $s99 -= $c[$i - 99];                       // MA99窗口滑出旧值
            if ($i >= 99) { $m7 = $s7 / 7; $m25 = $s25 / 25; $m99 = $s99 / 99; // 满99根后计算三条均线
                $maOK[$i] = ($m7 > $m25 && $m25 > $m99 && $c[$i] > $m7); } // 多头排列: MA7>MA25>MA99 且收盘>MA7
        }
        $row = ['inst' => strtoupper($base), 'days' => round(($ts[$n - 1] - $ts[0]) / 86400000.0, 1), // 结果行: 币对名/数据跨度天数
                'bars' => $n, 'span' => [bj($ts[0]), bj($ts[$n - 1])], 'counts' => []]; // K线根数/数据起止/各计数结果
        foreach (range(1, 9) as $K) $row['counts'][$K] = sim($ts, $o, $h, $l, $c, $maOK, $K, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, false); // 第一轮: 九1~九9逐计数快速模拟(不带明细)
        $resBar[] = $row;                                            // 收入该周期结果
        $done++;                                                     // 完成计数+1
        if ($done % 100 == 0) echo "$bk done $done\n";               // 每100个币打印一次进度
    }
    // 汇总矩阵 + 找最优计数
    $matrix[$bk] = [];                                               // 初始化该周期汇总矩阵
    foreach (range(1, 9) as $K) {                                    // 逐计数聚合
        $t = ['n' => 0, 'win' => 0, 'pnl' => 0, 'liq' => 0, 'adds' => 0, 'bankrupt' => 0, 'insts' => 0, 'pos' => 0]; // 初始化聚合结构
        foreach ($resBar as $row) { $v = $row['counts'][$K]; if ($v['n'] == 0) continue; // 遍历币种结果, 无成交的跳过
            $t['insts']++; $t['n'] += $v['n']; $t['win'] += $v['win']; $t['pnl'] += $v['pnl']; // 累加: 有效币种/轮数/盈利数/盈亏
            $t['liq'] += $v['liq']; $t['adds'] += $v['adds'];        // 累加: 爆仓数/加仓数
            if ($v['bankrupt']) $t['bankrupt']++;                    // 破产币种数+1
            if ($v['pnl'] > 0) $t['pos']++; }                        // 盈利币种数+1
        $matrix[$bk][$K] = $t;                                       // 存入矩阵
    }
    $bestK = 1; $bestP = -INF;                                       // 找该周期最优计数: 初值九1与负无穷
    foreach ($matrix[$bk] as $K => $t) if ($t['pnl'] > $bestP) { $bestP = $t['pnl']; $bestK = $K; } // 按总盈亏取最大者
    // 只为最优计数补 trades/curve
    foreach ($resBar as $idx => $row) {                              // 遍历该周期每个币种
        $tbl = "kline_" . strtolower(str_replace('-', '_', $row['inst'] === strtoupper($row['inst']) ? $row['inst'] : $row['inst'])) ; // (兼容旧逻辑的表名拼接, 实际下行才使用)
        // 重新读取并重跑最优计数(带明细)
        $base = strtolower(str_replace('-USDT-SWAP', '', $row['inst'])); // 从币对名还原基础名
        $r = $DB->query("SELECT candle_time,`c`,`h`,`l`,`o` FROM kline_{$base}_usdt_swap_$bk WHERE candle_time >= $gT0 ORDER BY candle_time ASC"); // 重新读取该币K线(补明细用)
        $ts = []; $c = []; $h = []; $l = []; $o = [];                // 初始化数组
        while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $c[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $o[] = (float)$x[4]; } // 逐行取数
        $r->free();                                                  // 释放结果集
        $n = count($c);                                              // 该币K线根数
        $maOK = array_fill(0, $n, false);                            // 重算三均线多头标记
        $s7 = 0.0; $s25 = 0.0; $s99 = 0.0;                           // 三条均线滑动窗口和
        for ($i = 0; $i < $n; $i++) {                                // 逐根计算
            $s7 += $c[$i]; $s25 += $c[$i]; $s99 += $c[$i];           // 收盘价加入各窗口
            if ($i >= 7) $s7 -= $c[$i - 7];                          // MA7窗口滑出旧值
            if ($i >= 25) $s25 -= $c[$i - 25];                       // MA25窗口滑出旧值
            if ($i >= 99) $s99 -= $c[$i - 99];                       // MA99窗口滑出旧值
            if ($i >= 99) { $m7 = $s7 / 7; $m25 = $s25 / 25; $m99 = $s99 / 99; // 满99根后计算三条均线
                $maOK[$i] = ($m7 > $m25 && $m25 > $m99 && $c[$i] > $m7); } // 多头排列标记
        }
        $det = sim($ts, $o, $h, $l, $c, $maOK, $bestK, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, true); // 第二轮: 只重跑最优计数(带明细curve/trades)
        $resBar[$idx]['counts'][$bestK] = $det;                      // 用带明细的结果替换
    }
    $results[$bk] = $resBar;                                         // 存该周期全部币种明细结果
    echo "$bk best=九$bestK pnl=$bestP\n";                           // 打印该周期最优计数与盈亏
}

$out = ['generated' => date('Y-m-d H:i:s'),                          // 输出结构: 生成时间
        'params' => ['lev' => $LEV, 'tp' => $TP, 'u' => $U, 'fee' => $FEE, 'liq' => $LIQ, 'timeout_days' => 7], // 参数: 杠杆/止盈/本金/费率/爆仓跌幅/超时
        'span' => [bj($gT0), bj(time() * 1000)],                     // 回测区间
        'matrix' => $matrix, 'bestK' => [], 'results' => $results];  // 汇总矩阵/最优计数(下面填)/全部明细
foreach ($BARS as $bk => $_) { $bk2 = $bk; $bp = -INF; foreach ($matrix[$bk] as $K => $t) if ($t['pnl'] > $bp) { $bp = $t['pnl']; $bk2 = $K; } $out['bestK'][$bk] = $bk2; } // 逐周期把最优计数写入bestK
file_put_contents(__DIR__ . '/../web/td9countall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入JSON供报告页读取(中文不转义)
echo "saved size=" . round(filesize(__DIR__ . '/../web/td9countall_data.json') / 1048576, 1) . "MB\n";   // 打印输出文件大小(MB)
foreach ($BARS as $bk => $_) { echo "== $bk ==\n"; foreach ($matrix[$bk] as $K => $t) printf("  九%d: pnl=%.1f n=%d wr=%.1f%% liq=%d 盈利合约=%d\n", $K, $t['pnl'], $t['n'], $t['n'] ? round($t['win'] / $t['n'] * 100, 1) : 0, $t['liq'], $t['pos']); } // 控制台按周期打印各计数汇总对比
