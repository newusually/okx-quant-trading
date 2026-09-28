<?php
/**
 * eth_addcmp.php — 加仓策略对比回测(CLI): 现行A方案框架下, 8个加仓信号逐个替换
 * 策略 = 现行实盘同参(2026-09-25 build 0925J口径):
 *   买入: 六重共振全命中 ①全市场1D MA20宽度>50% ②1h>MA20 ③1h>MA10 ④4h>MA5
 *         ⑤非熔断(BTC 1h最近4根累计≤-3%) ⑥taker买比≥50%(1h方向代理)
 *   加仓: 信号X(8候选: macd_hist_rise/fib_618/cci_oversold/macd_golden/triple_ma_bull/momentum_burst/td_nine/adx_trend)
 *         + 仅上行(现价>均价) ≤5轮 每轮=开仓保证金; 下跌不减仓 —— 公式与 strategy/signals.go 逐字同参
 *   止盈: 价格+2%(自均价) 市价全平; 不设止损·下跌不减仓
 *   兜底: 50%兜底强平已取消(2026-09-25用户指令) → 亏损由交易所爆仓线兜底(LIQ=均价×(1-1/LEV+MMR))
 *   保证金: 每笔=1U+3U×已过天数 封顶 500/6; 100x
 *   对照组: noAdd(完全不加仓) —— 各加仓策略与它对比得出「加仓贡献」
 * 输出: web/addcmp_data.json
 */
ini_set('memory_limit', '2048M');                                 // 提升 PHP 内存上限到 2G(全市场逐合约回测)
set_time_limit(0);                                                // 取消脚本执行时间限制
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆100x; 止盈+2%; 维持保证金率0.4%; 手续费与资金费率
$BAL0 = 500.0; $CAP = $BAL0 / 6.0; $LBASE = 1.0;                  // 初始余额500U; 单笔保证金封顶500/6U; 保证金基数1U

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库并设 utf8mb4
$DB = db();                                                       // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行SQL收集全部结果行为数组
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }  // 滑动窗口均线, 前 n-1 个为 null
function ema($a, $n) { $out = []; $k = 2.0 / ($n + 1); $e = null; for ($i = 0; $i < count($a); $i++) { $e = ($e === null) ? $a[$i] : ($a[$i] * $k + $e * (1 - $k)); $out[$i] = ($i >= $n - 1) ? $e : null; } return $out; }  // 指数均线, 从第 n-1 个起有效

// ================= 8个加仓信号(与 strategy/signals.go 逐字同参) =================
function sig_fib618($c) { // sigFibGolden: 近30根区间 0.618±3% 且收阳  // fib618加仓信号
    $n = count($c); $out = array_fill(0, $n, false);              // 初始化输出全为无信号
    for ($i = 31; $i < $n; $i++) {                                // 从第31根起扫描
        $lo = INF; $hi = -INF;                                    // 窗口高低初始化
        for ($j = $i - 29; $j <= $i; $j++) { if ($c[$j] < $lo) $lo = $c[$j]; if ($c[$j] > $hi) $hi = $c[$j]; }  // 求30根窗口最高/最低
        if ($hi <= $lo) continue;                                 // 无波动跳过
        $fib = $hi - ($hi - $lo) * 0.618;                         // 计算0.618回撤位
        $out[$i] = ($c[$i] >= $fib * 0.97 && $c[$i] <= $fib * 1.03);  // 收盘在fib±3%内即信号
    }
    return $out;                                                  // 返回信号数组
}
function sig_cci($h, $l, $c) { // sigCCI: CCI(14) < -100          // CCI超卖加仓信号
    $n = count($c); $out = array_fill(0, $n, false); $tp = []; $sma = []; $s = 0.0; $dev = [];  // 初始化与典型价/均值容器
    for ($i = 0; $i < $n; $i++) { $tp[$i] = ($h[$i] + $l[$i] + $c[$i]) / 3; $s += $tp[$i]; if ($i >= 14) $s -= $tp[$i - 14]; $sma[$i] = ($i >= 13) ? $s / 14 : null; }  // 典型价与14期SMA
    for ($i = 13; $i < $n; $i++) {                                // 从第14根起算CCI
        $md = 0.0;                                                // 平均绝对偏差
        for ($j = $i - 13; $j <= $i; $j++) $md += abs($tp[$j] - $sma[$i]);  // 累加离差绝对值
        $md /= 14;                                                // 取均值
        $out[$i] = ($md > 0) && (($tp[$i] - $sma[$i]) / (0.015 * $md) < -100);  // CCI<-100 即超卖信号
    }
    return $out;                                                  // 返回信号数组
}
function macd_hist($c) { // MACD(12,26,9) 柱: EMA12-EMA26 再EMA9   // 计算MACD三件套
    $n = count($c); $e12 = ema($c, 12); $e26 = ema($c, 26);       // EMA12与EMA26
    $dif = []; for ($i = 0; $i < $n; $i++) $dif[$i] = ($e12[$i] !== null && $e26[$i] !== null) ? $e12[$i] - $e26[$i] : 0;  // DIF=EMA12-EMA26
    // dea: 对 dif 从第一个有效点起算 EMA9
    $dea = []; $e = null; $k = 2.0 / 10;                          // DEA即DIF的EMA9
    for ($i = 0; $i < $n; $i++) {                                 // 逐根递推
        if ($e12[$i] === null || $e26[$i] === null) { $dea[$i] = null; continue; }  // DIF无效则DEA无效
        $e = ($e === null) ? $dif[$i] : ($dif[$i] * $k + $e * (1 - $k));  // EMA9递推
        $dea[$i] = $e;                                            // 存DEA
    }
    $h = []; for ($i = 0; $i < $n; $i++) $h[$i] = ($dea[$i] !== null) ? 2 * ($dif[$i] - $dea[$i]) : null; // 中国习惯MACD柱=2*(DIF-DEA); talib.Macd的hist=DIF-DEA  // 先按中国口径算柱
    // 注意: talib.Macd histogram = dif - dea(不带×2)。引擎用 talib → 保持不带×2
    for ($i = 0; $i < $n; $i++) if ($dea[$i] !== null) $h[$i] = $dif[$i] - $dea[$i];  // 与talib对齐: 柱=DIF-DEA(不带×2)
    return [$dif, $dea, $h];                                      // 返回[DIF, DEA, 柱]
}
function sig_macd_golden($c) { // sigMACDCross: hist 上穿0 且最新hist>0  // MACD金叉信号
    [, , $h] = macd_hist($c); $n = count($c); $out = array_fill(0, $n, false);  // 取MACD柱
    for ($i = 1; $i < $n; $i++) { if ($h[$i] === null || $h[$i - 1] === null) continue; $out[$i] = ($h[$i - 1] <= 0 && $h[$i] > 0); }  // 柱由负转正即金叉
    return $out;                                                  // 返回信号数组
}
function sig_macd_hist_rise($c) { // sigMACDHistRise: hist>0 且连续3根放大  // MACD柱放大信号
    [, , $h] = macd_hist($c); $n = count($c); $out = array_fill(0, $n, false);  // 取MACD柱
    for ($i = 3; $i < $n; $i++) { if ($h[$i] === null || $h[$i - 1] === null || $h[$i - 2] === null || $h[$i - 3] === null) continue; $out[$i] = ($h[$i] > 0 && $h[$i] > $h[$i - 1] && $h[$i - 1] > $h[$i - 2]); }  // 柱>0且逐根放大
    return $out;                                                  // 返回信号数组
}
function sig_triple_ma($c) { // sigTripleMABull: MA7>MA25>MA99 且 收>MA7  // 三均线多头排列信号
    $n = count($c); $m7 = ma($c, 7); $m25 = ma($c, 25); $m99 = ma($c, 99); $out = array_fill(0, $n, false);  // 计算三条均线
    for ($i = 98; $i < $n; $i++) $out[$i] = ($m7[$i] > $m25[$i] && $m25[$i] > $m99[$i] && $c[$i] > $m7[$i]);  // 多头排列且收盘在MA7上方
    return $out;                                                  // 返回信号数组
}
function sig_momentum($c) { // sigMomentumBurst: ROC(12)>2 且 收>EMA30  // 动量爆发信号
    $n = count($c); $e = ema($c, 30); $out = array_fill(0, $n, false);  // EMA30
    for ($i = 30; $i < $n; $i++) { if ($c[$i - 12] <= 0) continue; $roc = ($c[$i] / $c[$i - 12] - 1) * 100; $out[$i] = ($roc > 2 && $c[$i] > $e[$i]); }  // 12根ROC>2%且收盘在EMA30上
    return $out;                                                  // 返回信号数组
}
function sig_adx($h, $l, $c) { // sigADXTrendUp: ADX(14)>25 且 +DI>-DI  // ADX趋势向上信号
    $n = count($c); $out = array_fill(0, $n, false);              // 初始化输出
    if ($n < 30) return $out;                                     // 数据不足直接返回
    $trS = 0.0; $pS = 0.0; $mS = 0.0; $adxPrev = null; $trE = null; $pE = null; $mE = null;  // Wilder平滑累加器
    $pdm = []; $mdm = []; $tr = [];                               // +DM/-DM/TR序列
    for ($i = 1; $i < $n; $i++) {                                 // 逐根计算DM与TR
        $up = $h[$i] - $h[$i - 1]; $dn = $l[$i - 1] - $l[$i];     // 上冲与下冲幅度
        $pdm[$i] = ($up > $dn && $up > 0) ? $up : 0;              // +DM: 上冲占优
        $mdm[$i] = ($dn > $up && $dn > 0) ? $dn : 0;              // -DM: 下冲占优
        $a = abs($h[$i] - $l[$i]); $b = abs($h[$i] - $c[$i - 1]); $cc = abs($l[$i] - $c[$i - 1]);  // 真实波幅三候选
        $tr[$i] = max($a, max($b, $cc));                          // TR=三者最大
    }
    $dxs = [];                                                    // DX序列
    for ($i = 14; $i < $n; $i++) {                                // 从第15根起Wilder平滑
        if ($i == 14) { $trS = 0; $pS = 0; $mS = 0; for ($j = 1; $j <= 14; $j++) { $trS += $tr[$j]; $pS += $pdm[$j]; $mS += $mdm[$j]; } }  // 首次用前14根求和
        else { $trS = $trS - $trS / 14 + $tr[$i]; $pS = $pS - $pS / 14 + $pdm[$i]; $mS = $mS - $mS / 14 + $mdm[$i]; }  // 之后Wilder递推
        if ($trS <= 0) { $dxs[$i] = 0; continue; }                // TR和为零则DX=0
        $pdi = 100 * $pS / $trS; $mdi = 100 * $mS / $trS;         // +DI与-DI
        $dx = ($pdi + $mdi > 0) ? 100 * abs($pdi - $mdi) / ($pdi + $mdi) : 0;  // DX
        $dxs[$i] = $dx;                                           // 存DX
        // ADX = DX 的14期均值(Wilder平滑)
        if ($i >= 27) {                                           // 有14个DX后开始
            $adxCnt = 0; $adxSum = 0;                             // ADX累加器
            // 简化: 用最近14个DX均值
            for ($j = $i - 13; $j <= $i; $j++) $adxSum += $dxs[$j];  // 累加近14个DX
            $adx = $adxSum / 14;                                  // ADX=均值
            $out[$i] = ($adx > 25 && $pdi > $mdi);                // ADX>25且+DI占优→趋势向上信号
        }
    }
    return $out;                                                  // 返回信号数组
}
function tdDownCnt($c, $i) { $n = 0; for ($j = $i; $j >= 4; $j--) { if ($c[$j] < $c[$j - 4]) $n++; else break; } return $n; }  // 统计TD连跌数: 收盘低于4根前收盘连续计数
function sig_tdnine($c, $h) { // sigTDNine: 前一根完成下跌9结构 且 当前根收盘>前一根最高  // 神奇九转信号
    $n = count($c); $out = array_fill(0, $n, false);              // 初始化输出
    for ($i = 1; $i < $n; $i++) { if (tdDownCnt($c, $i - 1) >= 9 && $c[$i] > $h[$i - 1]) $out[$i] = true; }  // 前根九转完成且本根收破前根高→反转信号
    return $out;                                                  // 返回信号数组
}

// ================= 数据准备 =================
$up = []; $tot = [];                                              // 全市场宽度容器
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");  // 读全市场日线收盘
$cur = null; $win = []; $s = 0.0;                                 // 当前品种/20日窗口/窗口和
foreach ($rr as $x) {                                             // 逐行遍历
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; }    // 换品种重置窗口
    $win[] = (float)$x[2]; $s += (float)$x[2];                    // 收盘入窗口累加
    if (count($win) > 20) $s -= array_shift($win);                // 超20移除最旧
    if (count($win) == 20) { $t = (int)$x[1]; $up[$t] = ($up[$t] ?? 0) + ((float)$x[2] >= $s / 20 ? 1 : 0); $tot[$t] = ($tot[$t] ?? 0) + 1; }  // 满20算MA20并统计强势数
}
$brSrc = [];                                                      // 宽度源数据
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k;  // 品种数>10才算宽度
$sorted = array_keys($brSrc); sort($sorted);                      // 有效日期升序
function ffillBr($t) { global $brSrc, $sorted; $lo = 0; $hi = count($sorted) - 1; $res = null;  // 前向填充取不晚于t的宽度
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($sorted[$mid] <= $t) { $res = $sorted[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }  // 二分查找
    return $res !== null ? $brSrc[$res] : null; }                 // 返回宽度或null

$rb = rows("SELECT candle_time,`c` FROM kline_btc_usdt_swap_1h ORDER BY candle_time");  // 读 BTC 1小时收盘
$tsB = []; $cB = []; foreach ($rb as $x) { $tsB[] = (int)$x[0]; $cB[] = (float)$x[1]; }  // 拆时间戳与收盘
$meltSet = [];                                                    // BTC熔断时刻集合
for ($i = 4; $i < count($cB); $i++) if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) { for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true; }  // 4小时内跌≥3%则之后4小时熔断

$tbls = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline_%_usdt_swap_1h' ORDER BY table_name");  // 枚举全部1h K线表
$insts = [];                                                      // 品种列表
foreach ($tbls as $x) { if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $x[0], $m)) $insts[] = $m[1]; }  // 从表名提取品种ID
echo "insts=" . count($insts) . " breadth_days=" . count($brSrc) . "\n"; flush();  // 输出品种数与宽度天数

$VARIANTS = ['noAdd', 'macd_hist_rise', 'fib_618', 'cci_oversold', 'macd_golden', 'triple_ma_bull', 'momentum_burst', 'td_nine', 'adx_trend'];  // 对照组noAdd+8个加仓信号变体

// ================= sim(现行口径: 无50%兜底, LIQ=交易所爆仓线) =================
function sim($o, $h, $l, $c, $ts, $addSig, $entry) {              // 核心回测: 给定加仓信号与入场集
    global $LEV, $TPR, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $CAP, $LBASE;  // 引入全局参数
    $liqDrop = 1.0 / $LEV - $MMR;                                 // 爆仓所需跌幅
    $startT = $ts[30];                                            // 回测起点时间(算已过天数用)
    $trades = []; $n = count($ts); $i = 30;                       // 交易列表/根数/起始下标
    while ($i < $n - 1) {                                         // 主循环找入场
        if (empty($entry[$ts[$i]])) { $i++; continue; }           // 无六重共振入场信号跳过
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400);  // 距回测起点的已过天数
        $M = round(min($LBASE + 3.0 * $days, $CAP), 2);           // 保证金=1U+3U×天数, 封顶500/6
        if ($M < 1) $M = 1.0;                                     // 保底1U
        $M0 = $M;                                                 // 开仓保证金(加仓每轮同额)
        $e = $o[$i + 1];                                          // 入场价=下一根开盘
        $notional = $M0 * $LEV; $avg = $e; $adds = 0; $addEv = 0; $addWinEv = 0;  // 名义/均价/加仓次数/加仓事件/加仓后止盈事件
        $fee = $notional * $FEE_MAKER; $funding = 0.0;            // 入场maker费/资金费累加
        $liqPx = $avg * (1 - $liqDrop);                           // 爆仓价
        $tgtPx = $avg * (1 + $TPR);                               // 止盈价(+2%)
        $outcome = null; $exitPx = null; $j = $i + 1;             // 出场结果/出场价/扫描下标
        while ($j < $n) {                                         // 逐根推进持仓
            $barsHeld = $j - $i;                                  // 持仓根数
            $funding += $notional * $FUND8H / 8;                  // 每根1h计1/8个资金费周期
            $hitLiq = $l[$j] <= $liqPx;                           // 最低触爆仓价
            $hitTp  = $h[$j] >= $tgtPx;                           // 最高触止盈价
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 爆仓出场
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }  // 止盈出场
            if ($barsHeld >= 7 * 24) { $outcome = 'TO'; $exitPx = $c[$j]; break; }  // 超7天超时平仓
            if ($adds < 5 && $j + 1 < $n && !empty($addSig[$j]) && $c[$j] > $avg) { // 信号X 仅上行 ≤5轮  // 加仓条件
                $ap = $o[$j + 1];                                 // 加仓价=下一根开盘
                $avg = ($avg * $notional + $ap * $M0 * $LEV) / ($notional + $M0 * $LEV);  // 加权更新均价
                $notional += $M0 * $LEV; $adds++; $addEv++;       // 扩名义/计次数/记加仓事件
                $fee += $M0 * $LEV * $FEE_TAKER;                  // 加仓taker费
                $liqPx = $avg * (1 - $liqDrop);                   // 重算爆仓价
                $tgtPx = $avg * (1 + $TPR);                       // 重算止盈价
            }
            $j++;                                                 // 推进下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }  // 数据耗尽按末根收盘了结
        if ($addEv > 0 && $outcome === 'WIN') $addWinEv++;        // 该笔有加仓且最终止盈→加仓致胜事件
        $gross = ($exitPx - $avg) * $notional / $avg;             // 毛盈亏
        $pnl = $gross - $fee - $funding;                          // 净盈亏
        $mg = $M0 * (1 + $adds);                                  // 本笔总保证金(本金+加仓轮数×每轮)
        if ($pnl < -$mg) $pnl = -$mg;                             // 亏损封底总保证金
        $trades[] = ['out' => $outcome, 'adds' => $adds, 'pnl' => round($pnl, 3)];  // 记录交易
        $i = $j + 1;                                              // 出场后继续
    }
    return [$trades, $addEv, $addWinEv];                          // 返回交易列表与加仓事件统计
}

$summary = []; $contractStats = []; $dailyPnl = [];               // 变体汇总/分合约统计/日盈亏(未用)
$done = 0;                                                        // 已处理合约计数
foreach ($insts as $inst) {                                       // 逐合约回测
    $t1 = "kline_{$inst}_usdt_swap_1h"; $t4 = "kline_{$inst}_usdt_swap_4h";  // 该合约1h/4h表名
    $chk = rows("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='trading' AND table_name IN ('$t1','$t4')");  // 检查两表是否齐备
    if ((int)$chk[0][0] < 2) continue;                            // 缺表跳过
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c` FROM $t1 ORDER BY candle_time ASC");  // 读1h K线全量
    $ts = []; $o = []; $h = []; $l = []; $c = [];                 // 初始化序列
    foreach ($r as $x) { $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; }  // 转型入数组
    $n = count($ts);                                              // 1h根数
    if ($n < 200) { $done++; continue; }                          // 数据太少跳过
    [$ts4h, $c4h] = [[], []];                                     // 4h数据初始化
    $r4 = rows("SELECT candle_time,`c` FROM $t4 ORDER BY candle_time ASC");  // 读4h收盘
    foreach ($r4 as $x) { $ts4h[] = (int)$x[0]; $c4h[] = (float)$x[1]; }  // 转型入数组
    $has4 = count($ts4h) >= 10;                                   // 4h数据是否够用
    $ma20 = ma($c, 20); $ma10 = ma($c, 10);                       // 1h MA20/MA10
    $ma5_4h = $has4 ? ma($c4h, 5) : [];                           // 4h MA5(有数据才算)
    $tr4hUpF = []; $p4 = 0; $cur4 = null; $n4 = count($ts4h);     // 4h趋势填充到1h
    foreach ($ts as $tt) { while ($p4 < $n4 && $ts4h[$p4] <= $tt) { if ($ma5_4h[$p4] !== null) $cur4 = $c4h[$p4] > $ma5_4h[$p4]; $p4++; } $tr4hUpF[$tt] = $cur4; }  // 对齐最近4h趋势
    // ⑥ taker买比(1h方向代理)
    $br1h = [];                                                   // 1h买方占比
    for ($i = 0; $i < $n; $i++) { $rng = $h[$i] - $l[$i]; $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0; if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1; $br1h[$i] = 0.5 + $dir / 2; }  // 收盘在振幅中的位置→0~1
    // 8个信号序列(1h, 与全市场回测同参口径)
    $sigO = [];                                                   // 信号序列容器
    $sigO['fib_618'] = sig_fib618($c);                            // fib618信号
    $sigO['cci_oversold'] = sig_cci($h, $l, $c);                  // CCI超卖信号
    $sigO['macd_golden'] = sig_macd_golden($c);                   // MACD金叉信号
    $sigO['macd_hist_rise'] = sig_macd_hist_rise($c);             // MACD柱放大信号
    $sigO['triple_ma_bull'] = sig_triple_ma($c);                  // 三均线多头信号
    $sigO['momentum_burst'] = sig_momentum($c);                   // 动量爆发信号
    $sigO['td_nine'] = sig_tdnine($c, $h);                        // 神奇九转信号
    $sigO['adx_trend'] = sig_adx($h, $l, $c);                     // ADX趋势信号
    // 入场集
    $entryA = [];                                                 // 六重共振入场集
    for ($i = 30; $i < $n; $i++) {                                // 从第30根起判定
        $t = $ts[$i];                                             // 当前时点
        if (!empty($meltSet[$t])) continue;                       // ⑤BTC熔断时段禁入
        $b = ffillBr($t);                                         // ①市场宽度
        if ($b === null || $b <= 0.5) continue;                   // 宽度需>50%
        if ($ma20[$i] === null || $c[$i] <= $ma20[$i]) continue;  // ②收盘>MA20
        if ($ma10[$i] === null || $c[$i] <= $ma10[$i]) continue;  // ③收盘>MA10
        if (empty($tr4hUpF[$t])) continue;                        // ④4h趋势向上
        if ($br1h[$i] < 0.5) continue;                            // ⑥taker买比≥50%
        $entryA[$t] = true;                                       // 全命中记录入场
    }
    if (!count($entryA)) { $done++; continue; }                   // 无入场信号跳过该合约
    foreach ($VARIANTS as $vk) {                                  // 逐变体回测
        $addSig = ($vk === 'noAdd') ? [] : $sigO[$vk];            // 对照组不加仓, 其余用对应信号
        [$tr, $aev, $awev] = sim($o, $h, $l, $c, $ts, $addSig, $entryA);  // 模拟该变体
        $pl = 0.0; $w = 0; $liq = 0; $adds = 0;                   // 统计累加器
        foreach ($tr as $x) { $pl += $x['pnl']; if ($x['out'] == 'WIN') $w++; if ($x['out'] == 'LIQ') $liq++; $adds += $x['adds']; }  // 累加盈亏/胜/爆仓/加仓
        $cnt = count($tr);                                        // 笔数
        $summary[$vk]['n'] = ($summary[$vk]['n'] ?? 0) + $cnt;    // 汇总: 笔数
        $summary[$vk]['win'] = ($summary[$vk]['win'] ?? 0) + $w;  // 汇总: 胜
        $summary[$vk]['liq'] = ($summary[$vk]['liq'] ?? 0) + $liq;  // 汇总: 爆仓
        $summary[$vk]['adds'] = ($summary[$vk]['adds'] ?? 0) + $adds;  // 汇总: 加仓轮数
        $summary[$vk]['total'] = ($summary[$vk]['total'] ?? 0) + $pl;  // 汇总: 总盈亏
        $summary[$vk]['add_evs'] = ($summary[$vk]['add_evs'] ?? 0) + $aev;  // 汇总: 加仓事件
        $summary[$vk]['add_win_evs'] = ($summary[$vk]['add_win_evs'] ?? 0) + $awev;  // 汇总: 加仓致胜事件
        if ($cnt > 0) $contractStats[$vk][] = ['inst' => strtoupper($inst), 'n' => $cnt, 'pnl' => round($pl, 2), 'wr' => round(100 * $w / $cnt, 1)];  // 分合约记录
    }
    $done++;                                                      // 完成一个合约
    if ($done % 60 == 0) { echo "progress $done/" . count($insts) . "\n"; flush(); }  // 每60合约输出进度
    unset($ts, $o, $h, $l, $c, $ts4h, $c4h, $ma20, $ma10, $ma5_4h, $tr4hUpF, $br1h, $sigO, $entryA);  // 释放内存
}

// 日盈亏曲线: 从 contractStats 无法还原, 改用逐日重算太贵 —— 用每变体盈亏排序即可; 曲线省略, 用 top/bottom 合约
$rank = [];                                                       // 各变体最好/最差合约排名
foreach ($VARIANTS as $vk) {                                      // 逐变体排名
    $arr = $contractStats[$vk] ?? [];                             // 该变体分合约数据
    usort($arr, function ($a, $b) { return $b['pnl'] <=> $a['pnl']; });  // 按盈亏降序
    $rank[$vk] = ['top' => array_slice($arr, 0, 10), 'bottom' => array_slice(array_reverse($arr), 0, 10)];  // 取前10与后10
}
$out = [                                                          // 组装输出JSON
    'params' => ['lev' => 100, 'bal0' => $BAL0, 'cap_per_trade' => round($CAP, 2), 'tp' => '价格+2%(自均价)',  // 基础参数
        'backstop' => '50%兜底强平已取消(2026-09-25用户指令) → 亏损由交易所爆仓线兜底(LIQ≈均价×-0.6%)',  // 兜底口径说明
        'entry' => '六重共振全命中(1h口径): ①全市场1D MA20宽度>50% ②1h>MA20 ③1h>MA10 ④4h>MA5 ⑤非熔断 ⑥taker买比≥50%',  // 入场口径
        'add' => '信号X + 仅上行(现价>均价) ≤5轮 每轮=开仓保证金; 下跌不减仓; 信号公式与 strategy/signals.go 逐字同参(1h口径)',  // 加仓口径
        'note' => '每合约同时只1仓; 保证金=1U+3U×已过天数 封顶500/6; noAdd=对照组(完全不加仓)'],  // 补充说明
    'variants' => $VARIANTS,                                      // 变体名列表
    'summary' => $summary, 'rank' => $rank,                       // 汇总与排名
];
file_put_contents('E:/finally-main/web/addcmp_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出JSON
echo "JSON OK\n";                                                 // 完成提示
foreach ($VARIANTS as $vk) {                                      // CLI 打印各变体对比行
    $s = $summary[$vk];                                           // 该变体汇总
    $n = max(1, $s['n']);                                         // 防除零笔数
    printf("%-16s n=%-6d win%%=%5.1f liq=%-5d adds=%-6d addEvs=%-6d addWin%%=%5.1f total=%10.1f ev=%6.2f\n",  // 格式化输出
        $vk, $s['n'], 100 * $s['win'] / $n, $s['liq'], $s['adds'], $s['add_evs'],  // 笔数/胜率/爆仓/加仓/事件
        $s['add_evs'] ? 100 * $s['add_win_evs'] / $s['add_evs'] : 0, $s['total'], $s['total'] / $n);  // 加仓胜率/总盈亏/单笔期望
}
