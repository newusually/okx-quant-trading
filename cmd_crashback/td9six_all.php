<?php
/**
 * td9six_all.php — 全市场 六重共振(1H/4H) 买入+加仓 一年回测生成器 (2026-09-27 用户指令)
 * 用户原话: "六重共振1H 4H 所有合约 加仓每次1美金 每笔本金1美金买入 所有合约 计算一下 盈利一年多少"
 *
 * 六重共振 = 引擎 strategy/signals.go 同口径六指标, 同一根K线同时成立:
 *   1 triple_ma_bull  MA7>MA25>MA99 且收>MA7
 *   2 momentum_burst  ROC(12)>2% 且 收>EMA30
 *   3 adx_trend       ADX(14)>25 且 +DI>-DI
 *   4 fib_618         近30根高低点回撤61.8%位±3% 且阳线
 *   5 macd_hist_rise  MACD柱>0 且连续3根递增
 *   6 td_nine         前一根底序列计数>=9 且当前收盘突破前根高点
 * 变体: six=六票全中(严格共振) · five=至少5票 · four=至少4票
 * 买入1U · 持仓中再遇信号 加仓+1U(均价/止盈重算) · 10x(爆仓9.6%) · 止盈+2%价格 · 超时7天 · taker 0.05%
 * 双资金模式: refill续投(每轮固定1U) / strict严格(每合约1U池, 亏光停做)
 * 输出: web/td9sixall_data.json
 */
ini_set('memory_limit', '4096M');                                     // 提升PHP内存上限到4G, 全市场K线数组开销大
set_time_limit(0);                                                    // 取消脚本执行时间限制
$LEV = 10; $MMR = 0.004; $FEE = 0.0005; $TP = 0.02; $U = 1.0;         // 杠杆10x / 维持保证金率0.4% / taker费率0.05% / 止盈+2% / 每笔本金1U
$TMO_MS = 7 * 86400 * 1000;                                           // 持仓超时阈值7天(毫秒), 到期按收盘价平仓
$LIQ = 1.0 / $LEV - $MMR;                                             // 爆仓跌幅 = 1/杠杆 - 维持保证金率 = 9.6%
$BARS = ['1h' => '1H', '4h' => '4H'];                                 // 回测两种周期: 1小时/4小时
$VARIANTS = ['six' => '六重共振(6票全中)', 'five' => '至少5票', 'four' => '至少4票']; // 三个变体: 六票全中/至少5票/至少4票
$CAP_CURVE = 100;                                                     // 资金曲线最多保留100个点, 超出则等间隔抽样

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接本地MariaDB的trading库并设UTF-8编码
$DB = db();                                                           // 建立全局数据库连接
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 毫秒时间戳转北京时间字符串

$insts = [];                                                          // 合约基础币种列表(如 eth/btc/sol)
$j = json_decode(file_get_contents('E:/datas/insts_all.json'), true); // 读取全量合约清单JSON
foreach ($j['insts'] as $s) if (preg_match('/^(.+)-USDT-SWAP$/', $s, $m2)) $insts[] = strtolower($m2[1]); // 从"XXX-USDT-SWAP"提取基础币名并转小写
$insts = array_values(array_unique($insts)); sort($insts);            // 去重并按字母排序
echo "insts=" . count($insts) . "\n";                                 // 打印合约总数

$gT0 = (time() - 365 * 86400) * 1000;                                 // 回测起始时间 = 一年前的毫秒时间戳

function smaArr($a, $p) {                                             // 简单移动平均: 数组$a的$p周期SMA
    $n = count($a); $out = array_fill(0, $n, NAN); $s = 0.0;          // 长度/输出NAN初始化/滑动和清零
    for ($i = 0; $i < $n; $i++) { $s += $a[$i]; if ($i >= $p) $s -= $a[$i - $p]; if ($i >= $p - 1) $out[$i] = $s / $p; } // 滑动窗口求和并除以周期
    return $out;                                                      // 返回SMA序列
}
function emaArr($a, $p) {                                             // 指数移动平均: 数组$a的$p周期EMA
    $n = count($a); $out = array_fill(0, $n, NAN); $k = 2.0 / ($p + 1); $e = null; // 长度/输出NAN/平滑系数/EMA种子
    for ($i = 0; $i < $n; $i++) {                                     // 逐根递推
        if (is_nan($a[$i])) { if ($e !== null) $out[$i] = $e; continue; } // 跳过NAN不污染种子
        $e = ($e === null) ? $a[$i] : $a[$i] * $k + $e * (1 - $k);    // 首值作种子, 之后按EMA公式递推
        $out[$i] = $e;                                                // 写入当前EMA值
    }
    return $out;                                                      // 返回EMA序列
}
// Wilder ADX(14) + DI±, 返回 [adx, pdi, mdi]
function adxArr($h, $l, $c, $p = 14) {                                // Wilder口径ADX并附+DI/-DI两序列, 供共振第3票用
    $n = count($c); $adx = array_fill(0, $n, NAN); $pdi = array_fill(0, $n, NAN); $mdi = array_fill(0, $n, NAN); // 三个输出序列初始化为NAN
    $trS = 0.0; $pS = 0.0; $mS = 0.0; $dxs = []; $adxS = NAN;         // TR和/+DM和/-DM和/DX累积/ADX平滑值
    $prevH = null; $prevL = null; $prevC = null;                      // 前一根高低收缓存
    for ($i = 0; $i < $n; $i++) {                                     // 逐根遍历
        if ($prevC !== null) {                                        // 从第二根开始计算
            $up = $h[$i] - $prevH; $dn = $prevL - $l[$i];             // 当根上冲/下探幅度
            $pDM = ($up > $dn && $up > 0) ? $up : 0.0;                // +DM: 上冲占优时取值否则0
            $mDM = ($dn > $up && $dn > 0) ? $dn : 0.0;                // -DM: 下探占优时取值否则0
            $tr = max($h[$i] - $l[$i], max(abs($h[$i] - $prevC), abs($l[$i] - $prevC))); // TR取三种口径最大值
            if ($i <= $p) { $trS += $tr; $pS += $pDM; $mS += $mDM; if ($i == $p && $trS > 0) { $pdi[$i] = 100 * $pS / $trS; $mdi[$i] = 100 * $mS / $trS; } } // 前p根累加, 第p根出首个DI±
            else {                                                    // 之后Wilder平滑递推
                $trS = $trS - $trS / $p + $tr; $pS = $pS - $pS / $p + $pDM; $mS = $mS - $mS / $p + $mDM; // 平滑和 = 旧和-旧和/p+新值
                if ($trS <= 0) continue;                              // TR和为0则跳过防除零
                $pd = 100 * $pS / $trS; $md = 100 * $mS / $trS;       // +DI/-DI = 方向DM占TR比例
                $pdi[$i] = $pd; $mdi[$i] = $md;                       // 写入两DI序列
                $dx = ($pd + $md) > 0 ? 100 * abs($pd - $md) / ($pd + $md) : 0; // DX = 两DI差值占比
                $dxs[] = $dx;                                         // 收集DX
                if (count($dxs) == $p) { $adxS = array_sum($dxs) / $p; $adx[$i] = $adxS; } // 攒满p个: 首个ADX取简单平均
                elseif (count($dxs) > $p) { $adxS = ($adxS * ($p - 1) + $dx) / $p; $adx[$i] = $adxS; } // 之后ADX按Wilder平滑
            }
        }
        $prevH = $h[$i]; $prevL = $l[$i]; $prevC = $c[$i];            // 更新前一根缓存
    }
    return [$adx, $pdi, $mdi];                                        // 返回[ADX, +DI, -DI]三元组
}
// TD底序列计数: 在 idx 处连续收盘<第4根前收 的计数
function tdDownAt($c, $idx) {                                         // 从idx往回数TD底序列计数(收盘<4根前收为1票)
    $cnt = 0;                                                         // 计数器清零
    for ($i = $idx; $i >= 4; $i--) { if ($c[$i] < $c[$i - 4]) $cnt++; else break; } // 连续满足则+1, 一旦中断即停止
    return $cnt;                                                      // 返回底序列计数
}

function sim($ts, $o, $h, $l, $c, $sigOk, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, $withDetail, $refill) { // 单变体模拟器: $refill=续投模式(无限资金)否则严格1U池
    global $CAP_CURVE;                                                // 引用全局曲线采样上限
    $n = count($c);                                                   // K线总根数
    $cash = $U; $pos = null; $bankrupt = false;                       // 初始现金1U/无持仓/未破产
    $rounds = 0; $wins = 0; $pnlTotal = 0.0; $liqN = 0; $toN = 0; $addN = 0; // 笔数/胜场/累计盈亏/爆仓数/超时数/加仓次数
    $trades = []; $curve = [];                                        // 逐笔明细/资金曲线
    for ($i = 0; $i < $n; $i++) {                                     // 逐根K线推进
        if ($pos) {                                                   // 有持仓: 先判出场
            $liqPx = $pos['avg'] * (1 - $LIQ);                        // 爆仓价 = 均价×(1-爆仓跌幅)
            if ($l[$i] <= $liqPx) { if (!$refill) $cash += 0; $pnl = -$pos['u']; $why = 'LIQ'; $exit = $liqPx; } // 触及爆仓价: 本金全损, 标记LIQ
            elseif ($h[$i] >= $pos['tp']) { $exit = max($o[$i], $pos['tp']); $why = 'TP'; // 触及止盈价: 跳空高开按开盘价成交
                $gross = $pos['q'] * $exit - $pos['net']; $feeOut = $pos['q'] * $exit * $FEE; // 毛利与出场手续费
                if (!$refill) $cash += $pos['u'] + $gross - $feeOut; $pnl = $gross - $feeOut; } // 现金回笼本金+净利
            elseif ($ts[$i] - $pos['t'] >= $TMO_MS) { $exit = $c[$i]; $why = 'TO'; // 超7天: 收盘价平仓, 标记TO
                $gross = $pos['q'] * $exit - $pos['net']; $feeOut = $pos['q'] * $exit * $FEE; // 毛利与出场手续费
                if (!$refill) $cash += $pos['u'] + $gross - $feeOut; $pnl = $gross - $feeOut; } // 现金回笼
            else $exit = null;                                        // 本根无出场事件
            if ($exit !== null) {                                     // 有出场: 结算
                if ($withDetail) $trades[] = [$pos['t'], $ts[$i], $pos['adds'], $exit, round($pnl, 4), $why]; // 明细: 开/平时间+加仓次数+出场价+盈亏+原因
                $pnlTotal += $pnl; $rounds++; if ($why == 'LIQ') $liqN++; elseif ($why == 'TO') $toN++; // 累计与分类计数
                if ($pnl > 0) $wins++;                                // 盈利则胜场+1
                $curve[] = [$ts[$i], round($refill ? 1.0 + $pnlTotal : $cash, 4)]; // 曲线追加: 时间+净值
                $pos = null;                                          // 清空持仓
                if (!$refill && $cash < $U && $why != 'TP') $bankrupt = true; // 严格模式现金不足1U且非止盈出场 → 破产停做
            }
        }
        if (!$bankrupt && $sigOk[$i]) {                               // 未破产且当根有信号
            if (!$pos && ($refill || $cash >= $U)) {                  // 无持仓且资金够: 开首仓
                $net = $U * $LEV * (1 - $FEE);                        // 净名义投入 = 本金×杠杆×(1-费率)
                if (!$refill) $cash -= $U;                            // 严格模式扣除本金
                $pos = ['t' => $ts[$i], 'q' => $net / $c[$i], 'u' => $U, 'net' => $net, // 记录: 时间/数量/本金/净投入
                        'avg' => $c[$i], 'tp' => $c[$i] * (1 + $TP), 'adds' => 0]; // 均价=收盘/止盈价+2%/加仓次数清零
            } elseif ($pos && ($refill || $cash >= $U)) {             // 持仓中再遇信号且资金够: 加仓1U
                $net = $U * $LEV * (1 - $FEE);                        // 加仓的净名义投入
                if (!$refill) $cash -= $U;                            // 严格模式扣除加仓本金
                $pos['q'] += $net / $c[$i]; $pos['u'] += $U; $pos['net'] += $net; // 累加数量/累计本金/累计净投入
                $pos['avg'] = $pos['net'] / $pos['q'];                // 重算加权均价
                $pos['tp'] = $pos['avg'] * (1 + $TP);                 // 按新均价重算止盈价
                $pos['adds']++; $addN++;                              // 该笔加仓次数与总加仓数各+1
            }
        }
    }
    if ($pos) {                                                       // 收尾: 期末持仓按最后收盘价强平
        $exit = $c[$n - 1];                                           // 出场价 = 最后收盘价
        $gross = $pos['q'] * $exit - $pos['net']; $feeOut = $pos['q'] * $exit * $FEE; // 毛利与手续费
        if (!$refill) $cash += $pos['u'] + $gross - $feeOut; $pnl = $gross - $feeOut; // 现金回笼; 记净盈亏
        if ($withDetail) $trades[] = [$pos['t'], $ts[$n - 1], $pos['adds'], $exit, round($pnl, 4), 'END']; // 明细追加, 原因END
        $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;          // 累计盈亏/笔数/胜负
        $curve[] = [$ts[$n - 1], round($refill ? 1.0 + $pnlTotal : $cash, 4)]; // 曲线追加最后一点
    }
    if (count($curve) > $CAP_CURVE) {                                 // 曲线超上限: 等间隔抽样
        $step = (int)ceil(count($curve) / $CAP_CURVE);                // 抽样步长
        $c2 = []; foreach ($curve as $k2 => $p) if ($k2 % $step == 0) $c2[] = $p; // 每隔step取一点
        $curve = $c2;                                                 // 替换为抽样后曲线
    }
    $maxdd = 0.0; $peak = -INF;                                       // 最大回撤/历史峰值初始化
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; } // 遍历曲线求最大回撤
    $r = ['n' => $rounds, 'win' => $wins, 'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0, // 统计: 笔数/胜场/胜率
          'pnl' => round($pnlTotal, 4), 'adds' => $addN, 'liq' => $liqN, 'to' => $toN, // 盈亏/加仓数/爆仓数/超时数
          'bankrupt' => $bankrupt, 'cashEnd' => round($refill ? 1.0 + $pnlTotal : $cash, 4), 'maxdd' => round($maxdd, 4)]; // 破产/期末净值/最大回撤
    if ($withDetail) { $r['curve'] = $curve; if (count($trades) > 150) $trades = array_slice($trades, 0, 150); $r['trades'] = $trades; } // 需明细时附曲线+逐笔(上限150笔)
    return $r;                                                        // 返回统计结果
}

$results = []; $matrix = []; $bestV = [];                             // 明细结果/汇总矩阵/各周期最优变体
foreach ($BARS as $bk => $okxBar) {                                   // 外层遍历周期(1h/4h)
    $resBar = []; $done = 0;                                          // 该周期各合约结果/已处理计数
    foreach ($insts as $base) {                                       // 遍历全市场每个合约
        $tbl = "kline_{$base}_usdt_swap_{$bk}";                       // 拼K线表名, 如 kline_sol_usdt_swap_1h
        $r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c` FROM `$tbl` WHERE candle_time >= $gT0 ORDER BY candle_time ASC"); // 查一年K线(列名o/h/l/c)
        if (!$r) { $done++; continue; }                               // 表不存在则跳过
        $ts = []; $o = []; $h = []; $l = []; $c = [];                 // 五列数组: 时间/开/高/低/收
        while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0 || (float)$x[1] <= 0) continue; $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; } // 逐行取数并过滤脏数据
        $r->free();                                                   // 释放结果集
        $n = count($c);                                               // 有效根数
        if ($n < 120) { $done++; continue; }                          // 不足120根跳过

        // ---- 指标(引擎同口径) ----
        $m7 = smaArr($c, 7); $m25 = smaArr($c, 25); $m99 = smaArr($c, 99); // 三均线MA7/MA25/MA99
        $e30 = emaArr($c, 30);                                        // EMA30供动量票用
        list($adx, $pdi, $mdi) = adxArr($h, $l, $c);                  // ADX与+DI/-DI
        $e12 = emaArr($c, 12); $e26 = emaArr($c, 26);                 // MACD用的EMA12/EMA26
        $dif = []; for ($i = 0; $i < $n; $i++) $dif[] = (is_nan($e12[$i]) || is_nan($e26[$i])) ? NAN : $e12[$i] - $e26[$i]; // DIF = EMA12-EMA26
        $dea = emaArr($dif, 9);                                       // DEA = DIF的9周期EMA
        $hist = []; for ($i = 0; $i < $n; $i++) $hist[] = (is_nan($dif[$i]) || is_nan($dea[$i])) ? NAN : 2 * ($dif[$i] - $dea[$i]); // MACD柱 = 2×(DIF-DEA)

        $flg = [];                                                    // 三变体的开仓信号标志数组
        foreach (['six', 'five', 'four'] as $vk) $flg[$vk] = array_fill(0, $n, false); // 初始化为全false
        for ($i = 0; $i < $n; $i++) {                                 // 逐根统计六票
            $votes = 0;                                               // 当根票数清零
            // 1 triple_ma_bull
            if (!is_nan($m99[$i]) && $m7[$i] > $m25[$i] && $m25[$i] > $m99[$i] && $c[$i] > $m7[$i]) $votes++; // 三线多头排列且收>MA7则+1票
            // 2 momentum_burst: ROC(12)>2% 且 收>EMA30
            if ($i >= 12 && $c[$i - 12] > 0 && ($c[$i] / $c[$i - 12] - 1) * 100 > 2 && !is_nan($e30[$i]) && $c[$i] > $e30[$i]) $votes++; // 12根涨幅超2%且收在EMA30上则+1票
            // 3 adx_trend: ADX>25 且 +DI>-DI
            if (!is_nan($adx[$i]) && $adx[$i] > 25 && $pdi[$i] > $mdi[$i]) $votes++; // ADX强且多头方向占优则+1票
            // 4 fib_618: 近30根高低点 61.8%回撤位±3% 且阳线
            if ($i >= 29) {                                           // 攒够30根才可算
                $lo = INF; $hi = -INF;                                // 窗口最低/最高初始化
                for ($k2 = $i - 29; $k2 <= $i; $k2++) { if ($c[$k2] < $lo) $lo = $c[$k2]; if ($c[$k2] > $hi) $hi = $c[$k2]; } // 30根收盘扫出高低点
                if ($hi > $lo) {                                      // 区间有效才计算
                    $fib = $hi - ($hi - $lo) * 0.618;                 // 61.8%回撤位
                    if ($c[$i] >= $fib * 0.97 && $c[$i] <= $fib * 1.03 && $c[$i] > $o[$i]) $votes++; // 收盘落在回撤位±3%且为阳线则+1票
                }
            }
            // 5 macd_hist_rise: hist>0 连续3根递增
            if ($i >= 3 && !is_nan($hist[$i]) && $hist[$i] > 0 && $hist[$i] > $hist[$i - 1] && $hist[$i - 1] > $hist[$i - 2] && !is_nan($hist[$i - 2]) && $hist[$i - 2] > $hist[$i - 3]) $votes++; // MACD柱为正且连续3根递增则+1票
            // 6 td_nine: 前一根底计数>=9 且当前收盘突破前根高点
            if ($i >= 1 && tdDownAt($c, $i - 1) >= 9 && $c[$i] > $h[$i - 1]) $votes++; // 前根底序列满9且当根收盘破前根高点则+1票
            if ($votes >= 6) { $flg['six'][$i] = true; $flg['five'][$i] = true; $flg['four'][$i] = true; } // 六票全中: 三变体全放行
            elseif ($votes >= 5) { $flg['five'][$i] = true; $flg['four'][$i] = true; } // 五票: five/four放行
            elseif ($votes >= 4) { $flg['four'][$i] = true; }         // 四票: 仅four放行
        }
        $row = ['inst' => strtoupper($base), 'days' => round(($ts[$n - 1] - $ts[0]) / 86400000.0, 1), // 该合约基础信息: 名称/数据跨度天数
                'bars' => $n, 'span' => [bj($ts[0]), bj($ts[$n - 1])], 'variants' => []]; // 根数/起止时间/变体容器
        foreach ($VARIANTS as $vk => $vn) $row['variants'][$vk] = sim($ts, $o, $h, $l, $c, $flg[$vk], $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, true, false); // 严格资金模式逐变体回测(带明细)
        $resBar[] = $row;                                             // 存入该周期结果列表
        $done++;                                                      // 已处理计数+1
        if ($done % 100 == 0) echo "$bk done $done\n";                // 每处理100个合约打印一次进度
    }
    $matrix[$bk] = [];                                                // 该周期汇总矩阵初始化
    foreach ($VARIANTS as $vk => $vn) {                               // 按变体汇总全市场
        $t = ['n' => 0, 'win' => 0, 'pnl' => 0, 'adds' => 0, 'liq' => 0, 'bankrupt' => 0, 'insts' => 0, 'pos' => 0]; // 汇总桶: 笔数/胜场/盈亏/加仓/爆仓/破产合约数/参与合约数/盈利合约数
        foreach ($resBar as $row) { $v = $row['variants'][$vk]; if ($v['n'] == 0) continue; // 遍历各合约, 无交易者跳过
            $t['insts']++; $t['n'] += $v['n']; $t['win'] += $v['win']; $t['pnl'] += $v['pnl']; // 累加合约数/笔数/胜场/盈亏
            $t['adds'] += $v['adds']; $t['liq'] += $v['liq'];         // 累加加仓数/爆仓数
            if ($v['bankrupt']) $t['bankrupt']++;                     // 破产合约计数
            if ($v['pnl'] > 0) $t['pos']++; }                         // 盈利合约计数
        $matrix[$bk][$vk] = $t;                                       // 存入汇总矩阵
    }
    $bv = 'six'; $bp = -INF;                                          // 找该周期累计盈亏最高的变体
    foreach ($matrix[$bk] as $vk => $t) if ($t['pnl'] > $bp) { $bp = $t['pnl']; $bv = $vk; } // 遍历比较取最优
    $bestV[$bk] = $bv;                                                // 记录最优变体
    $results[$bk] = $resBar;                                          // 存入总结果
    echo "$bk best=$bv pnl=$bp\n";                                    // 打印该周期最优变体与盈亏
}

$out = ['generated' => date('Y-m-d H:i:s'),                           // 组装输出: 生成时间
        'params' => ['lev' => $LEV, 'tp' => $TP, 'u' => $U, 'fee' => $FEE, 'liq' => $LIQ, 'timeout_days' => 7, 'add_u' => 1.0], // 参数快照: 杠杆/止盈/本金/费率/爆仓跌幅/超时/加仓额
        'variants' => $VARIANTS, 'span' => [bj($gT0), bj(time() * 1000)], // 变体名表/回测区间
        'matrix' => $matrix, 'bestV' => $bestV, 'results' => $results]; // 汇总矩阵/最优变体/全量明细
file_put_contents(__DIR__ . '/../web/td9sixall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入web目录JSON供报告页读取
echo "saved size=" . round(filesize(__DIR__ . '/../web/td9sixall_data.json') / 1048576, 1) . "MB\n";   // 打印输出文件大小(MB)
foreach ($BARS as $bk => $_) { echo "== $bk ==\n"; foreach ($matrix[$bk] as $vk => $t) printf("  %-5s %s: pnl=%.1f n=%d wr=%.1f%% adds=%d liq=%d 盈利合约=%d\n", $vk, $VARIANTS[$vk], $t['pnl'], $t['n'], $t['n'] ? round($t['win'] / $t['n'] * 100, 1) : 0, $t['adds'], $t['liq'], $t['pos']); } // 控制台汇总: 各周期各变体的盈亏/笔数/胜率/加仓/爆仓/盈利合约数
