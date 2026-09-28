<?php
/**
 * td9res_all.php — ETH/BTC 买九5/6/8 × 六指标共振 一年回测生成器 (2026-09-27 用户指令)
 * 用户原话: "搞一下共振，15分钟，1H，4H,买九5/六/8 为正的方向 然后+cci +rsi+macd +taker +kdj+adx
 *           结合共振一下 一年 eth btc合约 算一下 盈利多少 回测一年"
 *
 * 买入 = 顶序列计数恰好 ∈ {5,6,8}(连续N根收盘>第4根前收盘, 上涨中, 前测1H为正的方向) — 基线
 * 六指标(同周期K线算, 各一票):
 *   CCI(14)>100 强势 | RSI(14) 55~75 多头未超买 | MACD hist>0 | Taker放量 vol>SMA20(vol)
 *   KDJ(9,3,3) K>D 且 K<90 | ADX(14)>20 有趋势
 * 变体: base=九5/6/8裸信号 · 六指标各自单独过滤 · vote2/vote3/vote4=至少N票共振
 * 10x(爆仓线≈9.6%) | 止盈+2%价格市价全平 | 无加仓 | 超时7天 | taker 0.05% | 每合约独立1U本金
 * 输出: web/td9resall_data.json
 */
ini_set('memory_limit', '2048M');                                     // 提升PHP内存上限到2G, 应对全量K线数组
set_time_limit(0);                                                    // 取消脚本执行时间限制, 全量回测耗时长
$LEV = 10; $MMR = 0.004; $FEE = 0.0005; $TP = 0.02; $U = 1.0;         // 杠杆10x / 维持保证金率0.4% / taker费率0.05% / 止盈+2% / 每笔本金1U
$TMO_MS = 7 * 86400 * 1000;                                           // 持仓超时阈值7天(毫秒), 到期按收盘价平仓
$LIQ = 1.0 / $LEV - $MMR;                                             // 爆仓跌幅 = 1/杠杆 - 维持保证金率 = 9.6%
$BARS = ['15m' => '15m', '1h' => '1H', '4h' => '4H'];                 // 回测三种K线周期: 15分钟/1小时/4小时(键=表名后缀, 值=展示名)
$INSTS = ['eth' => 'ETH', 'btc' => 'BTC'];                            // 回测两个标的: ETH与BTC的USDT永续合约
$KS = [5, 6, 8];                                                      // 顶序列计数命中集合: 恰好数到5/6/8时视为买九信号
$VARIANTS = [                                                         // 十个变体的展示名: 基线/六指标各自过滤/多票共振
    'base'  => '九5/6/8裸信号(无过滤)',                                // 变体base: 不加任何指标过滤
    'cci'   => '九+CCI>100',                                            // 变体cci: 叠加CCI强势过滤
    'rsi'   => '九+RSI55~75',                                           // 变体rsi: 叠加RSI多头未超买过滤
    'macd'  => '九+MACD柱>0',                                           // 变体macd: 叠加MACD红柱过滤
    'taker' => '九+Taker放量',                                          // 变体taker: 叠加成交量放大过滤
    'kdj'   => '九+KDJ多头',                                            // 变体kdj: 叠加KDJ金叉未超买过滤
    'adx'   => '九+ADX>20',                                             // 变体adx: 叠加ADX有趋势过滤
    'vote2' => '九+至少2票共振',                                        // 变体vote2: 六指标至少2票同时成立
    'vote3' => '九+至少3票共振',                                        // 变体vote3: 六指标至少3票同时成立
    'vote4' => '九+至少4票共振',                                        // 变体vote4: 六指标至少4票同时成立
];
$CAP_CURVE = 100;                                                     // 资金曲线最多保留100个点, 超出则等间隔抽样

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接本地MariaDB的trading库并设UTF-8编码
$DB = db();                                                           // 建立全局数据库连接
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 毫秒时间戳转北京时间的日期字符串

$gT0 = (time() - 365 * 86400) * 1000;                                 // 回测起始时间 = 一年前的毫秒时间戳

function smaArr($a, $p) {                                             // 简单移动平均: 对数组$a取$p周期SMA, 返回等长数组(前段为NAN)
    $n = count($a); $out = array_fill(0, $n, NAN); $s = 0.0;          // 数组长度/输出初始化为NAN/滑动和清零
    for ($i = 0; $i < $n; $i++) { $s += $a[$i]; if ($i >= $p) $s -= $a[$i - $p]; if ($i >= $p - 1) $out[$i] = $s / $p; } // 滑动窗口求和并除以周期
    return $out;                                                      // 返回SMA序列
}
function emaArr($a, $p) {                                             // 指数移动平均: 对数组$a取$p周期EMA, 返回等长数组
    $n = count($a); $out = array_fill(0, $n, NAN); $k = 2.0 / ($p + 1); $e = null; // 长度/输出NAN初始化/平滑系数k=2/(p+1)/EMA种子
    for ($i = 0; $i < $n; $i++) {                                     // 逐根遍历
        if (is_nan($a[$i])) { if ($e !== null) $out[$i] = $e; continue; } // 跳过NAN不污染种子
        $e = ($e === null) ? $a[$i] : $a[$i] * $k + $e * (1 - $k);    // 首值作种子, 之后按EMA公式递推
        $out[$i] = $e;                                                // 写入当前EMA值
    }
    return $out;                                                      // 返回EMA序列
}
// Wilder ADX(14)
function adxArr($h, $l, $c, $p = 14) {                                // Wilder口径ADX: 由高低收计算趋势强度序列
    $n = count($c); $trS = 0.0; $pS = 0.0; $mS = 0.0; $adx = array_fill(0, $n, NAN); // 长度/TR和/+DM和/-DM和/ADX输出初始化
    $trS2 = 0.0; $pS2 = 0.0; $mS2 = 0.0; $dxs = [];                   // ADX平滑用的TR和(复用)/DX累积数组
    $prevH = null; $prevL = null; $prevC = null;                      // 前一根的高低收缓存
    foreach (range(0, $n - 1) as $i) {                                // 逐根遍历
        if ($prevC !== null) {                                        // 从第二根开始才能算DM/TR
            $up = $h[$i] - $prevH; $dn = $prevL - $l[$i];             // 当根上冲幅度/下探幅度
            $pDM = ($up > $dn && $up > 0) ? $up : 0.0;                // +DM: 上冲>下探且>0时取上冲值, 否则0
            $mDM = ($dn > $up && $dn > 0) ? $dn : 0.0;                // -DM: 下探>上冲且>0时取下探值, 否则0
            $tr = max($h[$i] - $l[$i], max(abs($h[$i] - $prevC), abs($l[$i] - $prevC))); // TR: 振幅/跳空两种口径取最大
            if ($i <= $p) { $trS += $tr; $pS += $pDM; $mS += $mDM; }  // 前$p根: 简单累加作为初始和
            else {                                                    // 之后: Wilder平滑递推
                $trS = $trS - $trS / $p + $tr; $pS = $pS - $pS / $p + $pDM; $mS = $mS - $mS / $p + $mDM; // 平滑和 = 旧和-旧和/p+新值
                $pDI = $trS > 0 ? 100 * $pS / $trS : 0; $mDI = $trS > 0 ? 100 * $mS / $trS : 0; // +DI/-DI = 方向DM占TR比例
                $dx = ($pDI + $mDI) > 0 ? 100 * abs($pDI - $mDI) / ($pDI + $mDI) : 0; // DX = 两DI之差的绝对值占比
                $dxs[] = $dx;                                         // 收集DX用于首次ADX平均
                if (count($dxs) == $p) { $adx[$i] = array_sum($dxs) / $p; $trS2 = $adx[$i]; } // 攒满$p个DX: 首个ADX取简单平均
                elseif (count($dxs) > $p) { $trS2 = ($trS2 * ($p - 1) + $dx) / $p; $adx[$i] = $trS2; } // 之后ADX按Wilder平滑递推
            }
        }
        $prevH = $h[$i]; $prevL = $l[$i]; $prevC = $c[$i];            // 更新前一根高低收缓存
    }
    return $adx;                                                      // 返回ADX序列
}
// KDJ(9,3,3) 返回 [K[], D[]]
function kdjArr($h, $l, $c, $p = 9) {                                 // KDJ(9,3,3): 返回K线与D线两个数组
    $n = count($c); $K = array_fill(0, $n, NAN); $D = array_fill(0, $n, NAN); // 长度/K线D线输出初始化为NAN
    $k = 50.0; $d = 50.0;                                             // K与D的初值取中性值50
    for ($i = 0; $i < $n; $i++) {                                     // 逐根遍历
        if ($i >= $p - 1) {                                           // 攒够$p根后才开始计算
            $hh = max(array_slice($h, $i - $p + 1, $p)); $ll = min(array_slice($l, $i - $p + 1, $p)); // $p周期内最高价/最低价
            $rsv = ($hh > $ll) ? 100 * ($c[$i] - $ll) / ($hh - $ll) : 50; // RSV = 收盘在高低区间内的位置百分比(区间零宽取50)
            $k = 2.0 / 3 * $k + 1.0 / 3 * $rsv; $d = 2.0 / 3 * $d + 1.0 / 3 * $k; // K = 2/3旧K+1/3RSV; D = 2/3旧D+1/3K
            $K[$i] = $k; $D[$i] = $d;                                 // 写入当根K值与D值
        }
    }
    return [$K, $D];                                                  // 返回[K数组, D数组]
}

function sim($ts, $o, $h, $l, $c, $sigOk, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, $withDetail, $refill = false) { // 单变体模拟器: 输入K线+信号数组, 输出统计; $withDetail=是否记录明细, $refill=无限资金模式(只看信号质量)
    global $CAP, $CAP_CURVE;                                          // 引用全局曲线采样上限
    $n = count($c);                                                   // K线总根数
    $cash = $U; $pos = null; $bankrupt = false;                       // 初始现金1U/当前无持仓/未破产
    $rounds = 0; $wins = 0; $pnlTotal = 0.0; $liqN = 0; $toN = 0; $sigCnt = 0; // 总笔数/盈利笔数/累计盈亏/爆仓数/超时数/信号数
    $trades = []; $curve = [];                                        // 逐笔明细数组/资金曲线数组
    for ($i = 0; $i < $n; $i++) {                                     // 逐根K线推进
        if ($pos) {                                                   // 有持仓: 先判出场
            $liqPx = $pos['avg'] * (1 - $LIQ);                        // 该仓位的爆仓价 = 开仓均价×(1-爆仓跌幅)
            if ($l[$i] <= $liqPx) { if (!$refill) $cash += 0; $pnl = -$pos['u']; $why = 'LIQ'; $exit = $liqPx; } // 最低价触及爆仓价: 本金全损, 标记LIQ
            elseif ($h[$i] >= $pos['tp']) { $exit = max($o[$i], $pos['tp']); $why = 'TP'; // 最高价触及止盈价: 开盘跳空高开则按开盘价成交
                $gross = $pos['q'] * $exit - $pos['net']; $feeOut = $pos['q'] * $exit * $FEE; // 毛利 = 数量×出场价-净投入; 出场手续费 = 数量×价×费率
                if (!$refill) $cash += $pos['u'] + $gross - $feeOut; $pnl = $gross - $feeOut; } // 现金回笼本金+净利; 记净盈亏
            elseif ($ts[$i] - $pos['t'] >= $TMO_MS) { $exit = $c[$i]; $why = 'TO'; // 持仓超7天: 按收盘价平仓, 标记TO
                $gross = $pos['q'] * $exit - $pos['net']; $feeOut = $pos['q'] * $exit * $FEE; // 毛利与出场手续费同上
                if (!$refill) $cash += $pos['u'] + $gross - $feeOut; $pnl = $gross - $feeOut; } // 现金回笼; 记净盈亏
            else { $exit = null; }                                    // 本根无出场事件
            if ($exit !== null) {                                     // 有出场: 结算该笔
                if ($withDetail) $trades[] = [$pos['t'], $ts[$i], $exit, round($pnl, 4), $why]; // 记录逐笔: 开仓时间/平仓时间/出场价/盈亏/原因
                $pnlTotal += $pnl; $rounds++; if ($why == 'LIQ') $liqN++; elseif ($why == 'TO') $toN++; // 累计盈亏/笔数/分类计数
                if ($pnl > 0) $wins++;                                // 盈利则胜场+1
                $curve[] = [$ts[$i], round($refill ? 1.0 + $pnlTotal : $cash, 4)]; // 曲线追加: 时间+当前净值(refill模式用1+累计盈亏)
                $pos = null;                                          // 清空持仓
                if (!$refill && $cash < $U && $why != 'TP') $bankrupt = true; // 非refill且现金不足1U且非止盈出场 → 判定破产
            }
        }
        if (!$pos && !$bankrupt && $sigOk[$i] && ($refill || $cash >= $U)) { // 无持仓+未破产+当根有信号+资金够1U时开仓(refill无视资金)
            $net = $U * $LEV * (1 - $FEE);                            // 净名义投入 = 本金×杠杆×(1-开仓费率)
            if (!$refill) $cash -= $U; $sigCnt++;                     // 扣除本金(refill模式不扣); 信号计数+1
            $pos = ['t' => $ts[$i], 'q' => $net / $c[$i], 'u' => $U, 'net' => $net, // 记录开仓时间/数量=净投入/收盘价/本金/净投入
                    'avg' => $c[$i], 'tp' => $c[$i] * (1 + $TP)];     // 开仓均价=当根收盘; 止盈价=均价×(1+2%)
        }
    }
    if ($pos) {                                                       // 循环结束仍有持仓: 按最后一根收盘价强制平仓
        $exit = $c[$n - 1];                                           // 出场价 = 最后收盘价
        $gross = $pos['q'] * $exit - $pos['net']; $feeOut = $pos['q'] * $exit * $FEE; // 毛利与出场手续费同上
        if (!$refill) $cash += $pos['u'] + $gross - $feeOut; $pnl = $gross - $feeOut; // 现金回笼; 记净盈亏
        if ($withDetail) $trades[] = [$pos['t'], $ts[$n - 1], $exit, round($pnl, 4), 'END']; // 明细追加, 原因标记END
        $pnlTotal += $pnl; $rounds++; if ($pnl > 0) $wins++;          // 累计盈亏/笔数/胜负
        $curve[] = [$ts[$n - 1], round($refill ? 1.0 + $pnlTotal : $cash, 4)]; // 曲线追加最后一点
    }
    if (count($curve) > $CAP_CURVE) {                                 // 曲线点数超上限: 等间隔抽样压缩
        $step = (int)ceil(count($curve) / $CAP_CURVE);                // 抽样步长 = 总点数/上限向上取整
        $c2 = []; foreach ($curve as $k2 => $p) if ($k2 % $step == 0) $c2[] = $p; // 每隔step取一点
        $curve = $c2;                                                 // 用抽样后的曲线替换
    }
    $maxdd = 0.0; $peak = -INF;                                       // 最大回撤初始化0 / 历史峰值初始化负无穷
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; } // 遍历曲线: 更新峰值并求峰值到当点的最大落差
    $r = ['n' => $rounds, 'win' => $wins, 'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0, // 组装统计: 笔数/胜场/胜率%
          'pnl' => round($pnlTotal, 4), 'sig' => $sigCnt, 'liq' => $liqN, 'to' => $toN, // 累计盈亏/信号数/爆仓数/超时数
          'bankrupt' => $bankrupt, 'cashEnd' => round($cash, 4), 'maxdd' => round($maxdd, 4)]; // 破产标志/期末现金/最大回撤
    if ($withDetail) { $r['curve'] = $curve; if (count($trades) > 200) $trades = array_slice($trades, 0, 200); $r['trades'] = $trades; } // 需要明细时附曲线+逐笔(最多留200笔)
    return $r;                                                        // 返回该变体统计结果
}

$results = [];                                                        // 总结果容器: [币种][周期][变体]三维结构
foreach ($INSTS as $ik => $iup) {                                     // 外层遍历币种(eth/btc)
    foreach ($BARS as $bk => $okxBar) {                               // 内层遍历周期(15m/1h/4h)
        $tbl = "kline_{$ik}_usdt_swap_{$bk}";                         // 拼K线表名, 如 kline_eth_usdt_swap_1h
        $r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c`,vol FROM `$tbl` WHERE candle_time >= $gT0 ORDER BY candle_time ASC"); // 查一年内K线(注意列名是o/h/l/c/vol)
        if (!$r) { echo "no table $tbl\n"; continue; }                // 表不存在则提示并跳过该组合
        $ts = []; $o = []; $h = []; $l = []; $c = []; $v = [];        // 六列数组: 时间/开/高/低/收/量
        while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0 || (float)$x[1] <= 0) continue; $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; $v[] = (float)$x[5]; } // 逐行取数, 过滤收盘或开盘≤0的脏数据, 转型入数组
        $r->free();                                                   // 释放查询结果集
        $n = count($c);                                               // 有效K线根数
        if ($n < 120) { echo "too few $tbl=$n\n"; continue; }         // 数据不足120根则跳过该组合

        // ---- 指标 ----
        $cci = array_fill(0, $n, NAN);                                // CCI(14)输出初始化为NAN
        $P = 14; $tpS = 0.0; $mfS = 0.0; $q = [];                     // 周期14/典型价滑动和/滑动和/典型价缓存
        for ($i = 0; $i < $n; $i++) {                                 // 逐根计算CCI
            $tp = ($h[$i] + $l[$i] + $c[$i]) / 3.0; $q[] = $tp;       // 典型价 = (高+低+收)/3, 入缓存
            $tpS += $tp; $mfS += abs($tp - ($i ? $q[$i - 1] : $tp));  // 累加典型价及其一阶差分绝对值
            if ($i >= $P && $i - $P - 1 >= 0) { $tpS -= $q[$i - $P]; $mfS -= abs($q[$i - $P] - $q[$i - $P - 1]); } // 滑出窗口外最老一根的贡献
            if ($i >= $P - 1 && $mfS > 0) $cci[$i] = ($tpS / $P - $tp) / (0.015 * $mfS / $P); // CCI = (均典型价-当根)/(0.015×平均绝对偏差)
        }
        // RSI(14) Wilder
        $rsi = array_fill(0, $n, NAN); $ag = 0.0; $al = 0.0;          // RSI输出初始化/平均涨幅/平均跌幅
        for ($i = 1; $i < $n; $i++) {                                 // 从第二根开始算涨跌
            $ch = $c[$i] - $c[$i - 1]; $g = max($ch, 0.0); $ls = max(-$ch, 0.0); // 收盘差/上涨部分/下跌部分
            if ($i <= $P) { $ag += $g; $al += $ls; if ($i == $P) { $ag /= $P; $al /= $P; $rsi[$i] = $al == 0 ? 100 : 100 - 100 / (1 + $ag / $al); } } // 前14根累加, 第14根取均值出首个RSI
            else { $ag = ($ag * ($P - 1) + $g) / $P; $al = ($al * ($P - 1) + $ls) / $P; $rsi[$i] = $al == 0 ? 100 : 100 - 100 / (1 + $ag / $al); } // 之后Wilder平滑: 均值=(旧均值×13+新值)/14
        }
        $e12 = emaArr($c, 12); $e26 = emaArr($c, 26);                 // 收盘价12/26周期EMA, 供MACD用
        $dif = []; for ($i = 0; $i < $n; $i++) $dif[] = (is_nan($e12[$i]) || is_nan($e26[$i])) ? NAN : $e12[$i] - $e26[$i]; // DIF = EMA12 - EMA26
        $dea = emaArr($dif, 9);                                       // DEA = DIF的9周期EMA
        $hist = []; for ($i = 0; $i < $n; $i++) $hist[] = (is_nan($dif[$i]) || is_nan($dea[$i])) ? NAN : 2 * ($dif[$i] - $dea[$i]); // MACD柱 = 2×(DIF-DEA)
        $vma = smaArr($v, 20);                                        // 成交量20周期SMA, 供放量判断
        list($kdjK, $kdjD) = kdjArr($h, $l, $c);                      // 计算KDJ的K线与D线
        $adx = adxArr($h, $l, $c);                                    // 计算Wilder ADX(14)

        // ---- 信号 ----
        $tdUp = 0;                                                    // 顶序列计数器清零
        $nine = array_fill(0, $n, false);                             // 买九信号布尔数组(计数∈{5,6,8}为真)
        $flg = ['cci' => array_fill(0, $n, false), 'rsi' => array_fill(0, $n, false), 'macd' => array_fill(0, $n, false), // 六指标成立标志数组, 每指标一票
                'taker' => array_fill(0, $n, false), 'kdj' => array_fill(0, $n, false), 'adx' => array_fill(0, $n, false)];
        for ($i = 0; $i < $n; $i++) {                                 // 逐根生成信号与各指标票
            $tdUp = ($c[$i] > ($i >= 4 ? $c[$i - 4] : INF)) ? $tdUp + 1 : 0; // 顶序列: 收盘>4根前收盘则计数+1, 否则清零(不足4根时INF保证清零)
            $nine[$i] = in_array($tdUp, $KS, true);                   // 计数恰好为5/6/8时标记买九信号
            if (!is_nan($cci[$i])) $flg['cci'][$i] = $cci[$i] > 100;  // CCI票: >100视为强势
            if (!is_nan($rsi[$i])) $flg['rsi'][$i] = $rsi[$i] > 55 && $rsi[$i] < 75; // RSI票: 55~75多头且未超买
            if (!is_nan($hist[$i])) $flg['macd'][$i] = $hist[$i] > 0; // MACD票: 红柱>0
            if (!is_nan($vma[$i]) && $vma[$i] > 0) $flg['taker'][$i] = $v[$i] > $vma[$i]; // Taker票: 当根量>20期均量即放量
            if (!is_nan($kdjK[$i])) $flg['kdj'][$i] = $kdjK[$i] > $kdjD[$i] && $kdjK[$i] < 90; // KDJ票: K>D金叉状态且K未超买(<90)
            if (!is_nan($adx[$i])) $flg['adx'][$i] = $adx[$i] > 20;   // ADX票: >20视为有趋势
        }
        $row = ['inst' => $iup, 'bar' => strtoupper($bk), 'days' => round(($ts[$n - 1] - $ts[0]) / 86400000.0, 1), // 该组合的基础信息: 币种/周期/数据跨度天数
                'bars' => $n, 'span' => [bj($ts[0]), bj($ts[$n - 1])], 'variants' => []]; // K线根数/起止时间/变体结果容器
        foreach ($VARIANTS as $vk => $vn) {                           // 遍历10个变体逐一回测
            $sigOk = array_fill(0, $n, false);                        // 该变体的最终开仓信号数组
            for ($i = 0; $i < $n; $i++) {                             // 逐根判定
                if (!$nine[$i]) continue;                             // 非买九5/6/8的根直接跳过
                if ($vk == 'base') { $sigOk[$i] = true; continue; }   // base变体: 裸信号直接放行
                if (isset($flg[$vk])) { $sigOk[$i] = $flg[$vk][$i]; continue; } // 单指标变体: 直接取该指标票
                $need = (int)substr($vk, 4);                          // voteN变体: 从键名取所需票数N
                $votes = 0; foreach ($flg as $f) if ($f[$i]) $votes++; // 统计当根六指标总票数
                $sigOk[$i] = $votes >= $need;                         // 票数达标才放行
            }
            $row['variants'][$vk] = sim($ts, $o, $h, $l, $c, $sigOk, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, true); // 正常资金模式回测(带明细)
            $row['refill'][$vk] = sim($ts, $o, $h, $l, $c, $sigOk, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, false, true); // 无限资金模式回测(只看信号本身质量)
        }
        $results[$ik][$bk] = $row;                                    // 存入总结果三维结构
        echo "$iup $bk done n=$n\n";                                  // 控制台打印该组合完成进度
    }
}

$out = ['generated' => date('Y-m-d H:i:s'),                           // 组装输出: 生成时间
        'params' => ['lev' => $LEV, 'tp' => $TP, 'u' => $U, 'fee' => $FEE, 'liq' => $LIQ, 'timeout_days' => 7, 'ks' => $KS], // 参数快照: 杠杆/止盈/本金/费率/爆仓跌幅/超时天数/计数集合
        'variants' => $VARIANTS, 'span' => [bj($gT0), bj(time() * 1000)], 'results' => $results]; // 变体名表/回测区间/全部结果
file_put_contents(__DIR__ . '/../web/td9resall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 结果JSON写入web目录(中文不转义), 供HTML报告页读取
echo "saved size=" . round(filesize(__DIR__ . '/../web/td9resall_data.json') / 1048576, 2) . "MB\n";   // 打印输出文件大小(MB)
foreach ($results as $ik => $bars) foreach ($bars as $bk => $row) {   // 控制台汇总打印各组合结果
    echo "== $iup $bk ==\n";                                          // 组合标题(币种+周期)
    foreach ($row['variants'] as $vk => $vv) printf("  %-6s %s: n=%d wr=%.1f%% liq=%d pnl=%+.1f\n", $vk, $VARIANTS[$vk], $vv['n'], $vv['wr'], $vv['liq'], $vv['pnl']); // 每变体一行: 笔数/胜率/爆仓数/累计盈亏
}
