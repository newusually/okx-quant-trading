<?php
/**
 * fib618_eth.php — ETH 15m 真实黄金分割 0.618 买点 检测入库 + 一年回测 (2026-09-27 用户指令)
 * 用户原话: "给我真正正确的黄金分割买点 0.618 下跌以后上涨到0.618 给我15分钟的ETH合约 帮我划线
 *            要一年的数据 ... 保存在数据库 并且回测一年 黄金分割胜率 赚多少"
 *
 * 买点定义(经典黄金分割回撤):
 *   1) 找一段下跌: 摆动高点 H (阶段最高) → 阴跌到摆动低点 L (阶段最低, 跌幅>=2%)
 *   2) 从 L 反弹, 当 15m 收盘价上穿 0.618 回撤位 fib = L + (H-L)*0.618 → 买入信号
 *      (回撤 61.8% 是黄金分割最强阻力/买点位, 用户口径: 下跌后上涨到0.618)
 *   3) 触发后重新找下一段下跌
 * 入库: trading.fib618_marks (inst,bar,t_signal,t_high,t_low,px_high,px_low,px_fib) — 网页划线用
 * 回测: 信号收盘买 1U · 10x · taker 0.05% · 超时7天 · 爆仓9.6% · 止盈档位 +0.5%/+1%/+2%/回归前高
 * 输出: web/fib618eth_data.json
 */
ini_set('memory_limit', '2048M'); // 调高内存上限到 2G
set_time_limit(0); // 取消执行时间限制
$LEV = 10; $MMR = 0.004; $FEE = 0.0005; $U = 1.0; $TMO_MS = 7 * 86400 * 1000; // 杠杆10x/维持保证金率0.4%/taker费0.05%/每笔1U/超时7天(毫秒)
$LIQ = 1.0 / $LEV - $MMR; // 爆仓跌幅 = 1/杠杆 - 维持保证金率 = 9.6%
/* 用户口径: 显著下跌才画 fib; 1.5% 15m约对应一次像样回调 */
$DROP_MIN = 0.015;   // 摆动高→低 最小跌幅 2% (过滤横盘毛刺)
$INST = 'eth'; $BAR = '15m'; // 标的与周期

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 连接本地 MariaDB trading 库
$DB = db(); // 建立全局数据库连接
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 毫秒时间戳转北京时间字符串

// ---- 1) 加载一年K线 ----
$gT0 = (time() - 368 * 86400) * 1000; // 起始时间戳(约368天前, 覆盖一年)
$r = $DB->query("SELECT candle_time,`o`,`h`,`l`,`c` FROM kline_{$INST}_usdt_swap_{$BAR} WHERE candle_time >= $gT0 ORDER BY candle_time ASC"); // 按时间升序取该时段K线
$ts = []; $o = []; $h = []; $l = []; $c = []; // 初始化数组
while ($x = $r->fetch_row()) { if ((float)$x[4] <= 0) continue; $ts[] = (int)$x[0]; $o[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $c[] = (float)$x[4]; } // 逐行转类型(过滤无效收盘)
$r->free(); // 释放结果集
$n = count($c); // K线数
echo "bars=$n span=" . bj($ts[0]) . " ~ " . bj($ts[$n - 1]) . "\n"; // 输出数据规模与跨度

// ---- 2) 检测 0.618 买点 ----
// 状态机(真实施划口径 — 永远锚定【最近一段腿】):
//   seek_high: 跟踪摆动高点 H; 从 H 跌幅>=2% → seek_low
//   seek_low:  跟踪摆动低点 L(loIdx) 与反弹最高 rbH;
//              · 创新低 → 重新锚定: H=失败反弹高点rbH(若无反弹维持原H), L=新低 (熊市连续出新腿)
//              · 收盘阳线站上 fib=L+(H-L)*0.618 → 买点(重置)
//              · 价格突破当前腿高点 H → 下跌腿失效 → seek_high
$signals = []; // 买点信号数组
$hiIdx = 0; $loIdx = -1; $rbH = 0.0; $anchored = false;  // anchored: H是否已重锚为失败反弹高点
$H = 0.0; // 当前锚定摆动高点
$phase = 'seek_high'; // 初始阶段: 找高点
for ($i = 1; $i < $n; $i++) { // 逐根推进状态机
    if ($phase == 'seek_high') { // 找摆动高点阶段
        if ($h[$i] > $h[$hiIdx]) { $hiIdx = $i; $loIdx = -1; $anchored = false; } // 创新高→更新高点
        if ($l[$i] <= $h[$hiIdx] * (1 - $DROP_MIN)) { $phase = 'seek_low'; $loIdx = $i; $rbH = $h[$i]; $anchored = false; } // 从高点跌够最小跌幅→进入找低点阶段
    } else { // 找摆动低点阶段
        $rbH = max($rbH, $h[$i]); // 跟踪反弹最高价
        if ($l[$i] < $l[$loIdx]) {                    // 创新低 → 重锚最近一段腿
            if ($rbH > $l[$i] && $rbH < $h[$hiIdx]) { $H = $rbH; $anchored = true; } // 有失败反弹→锚定其高点
            else if (!$anchored) $H = $h[$hiIdx]; // 无反弹维持原高点
            $loIdx = $i; $rbH = $h[$i]; // 更新低点与反弹基准
        } else if (!$anchored) $H = $h[$hiIdx]; // 未重锚时维持原高点
        $L = $l[$loIdx]; // 当前摆动低点
        if ($h[$i] > $H) { $phase = 'seek_high'; $hiIdx = $i; $loIdx = -1; $anchored = false; continue; } // 下跌腿失效
        $fib = $L + ($H - $L) * 0.618; // 计算 0.618 回撤买点位
        if ($c[$i] >= $fib && $c[$i] > $o[$i]) {      // 收盘阳线站上 0.618 → 买点
            $signals[] = ['t' => $ts[$i], 'i' => $i, 'H' => $H, 'L' => $L, 'fib' => $fib]; // 记录信号
            $phase = 'seek_high'; $hiIdx = $i; $loIdx = -1; $anchored = false; // 触发后重置找下一段下跌
        }
    }
}
// 回填每段腿的 tH (锚定高点时间): 信号前回溯找 h==H 的位置(限400根)
foreach ($signals as $k => $s) { // 逐信号回溯
    $tH = -1; // 高点时间(未找到=-1)
    for ($m2 = $s['i'] - 1; $m2 >= 0 && $s['i'] - $m2 < 600; $m2--) { // 向前扫描最多600根
        if (abs($h[$m2] - $s['H']) < 1e-9) { $tH = $ts[$m2]; break; } // 找到等于锚定高点的K线
        if ($l[$m2] < $s['L']) break; // 低于摆动低点则提前终止
    }
    $signals[$k]['tH'] = $tH; // 存高点时间
    $signals[$k]['tL'] = $ts[$s['i'] - 1]; // 低点时间默认前一根
    for ($m2 = $s['i'] - 1; $m2 >= 0; $m2--) { if (abs($l[$m2] - $s['L']) < 1e-9) { $signals[$k]['tL'] = $ts[$m2]; break; } } // 回溯找等于低点的K线时间
}
echo "signals=" . count($signals) . "\n"; // 输出信号数

// ---- 3) 入库 (划线用, 幂等) ----
$DB->query("CREATE TABLE IF NOT EXISTS fib618_marks ( // 建划线标记表(不存在才建)
  id INT AUTO_INCREMENT PRIMARY KEY, // 自增主键
  inst VARCHAR(32) NOT NULL, bar VARCHAR(8) NOT NULL, // 标的与周期
  t_signal BIGINT NOT NULL, t_high BIGINT NOT NULL, t_low BIGINT NOT NULL, // 信号/高点/低点时间
  px_high DOUBLE NOT NULL, px_low DOUBLE NOT NULL, px_fib DOUBLE NOT NULL, // 高点/低点/fib价格
  signal_px DOUBLE NOT NULL DEFAULT 0, // 信号根价格
  UNIQUE KEY uk (inst, bar, t_signal), KEY idx_t (inst, bar, t_signal)) ENGINE=InnoDB"); // 唯一键保证幂等+查询索引
$st = $DB->prepare("REPLACE INTO fib618_marks (inst,bar,t_signal,t_high,t_low,px_high,px_low,px_fib,signal_px) VALUES (?,?,?,?,?,?,?,?,?)"); // 预编译REPLACE(幂等写入)
$cnt = 0; // 写入计数
foreach ($signals as $s) { // 逐信号入库
    $st->bind_param('ssssddddd', $INST, $BAR, $s['t'], $s['tH'], $s['tL'], $s['H'], $s['L'], $s['fib'], $c[$s['i']]); // 绑定参数
    $st->execute(); $cnt++; // 执行并计数
}
$st->close(); // 关闭预编译语句
echo "db saved=$cnt\n"; // 输出入库数

// ---- 4) 回测: 信号收盘买1U · 10x · 各止盈档 ----
function bt($ts, $o, $h, $l, $c, $signals, $TP, $refill, $FEE, $LEV, $LIQ, $U, $TMO_MS) { // 固定止盈档回测(refill=盈利滚仓)
    $n = count($c); $pnlTotal = 0.0; $rounds = 0; $wins = 0; $liqN = 0; $toN = 0; // K线数/累计盈亏/笔数/胜/爆仓/超时
    $cash = $U; $bankrupt = false; $trades = []; $curve = []; // 现金/破产标志/明细/权益曲线
    foreach ($signals as $s) { // 逐信号交易
        if ($bankrupt) break; // 破产终止
        $i0 = $s['i']; // 信号根下标
        if (!$refill && $cash < $U) break; // 严格模式资金不足则停
        if (!$refill) $cash -= $U; // 严格模式先扣1U本金
        $net = $U * $LEV * (1 - $FEE); // 扣除开仓手续费后的净名义金
        $q = $net / $c[$i0]; $u = $U; // 买入数量/本金
        $entry = $c[$i0]; $tpPx = $entry * (1 + $TP); $liqPx = $entry * (1 - $LIQ); // 入场价/止盈价/爆仓价
        $pnl = null; $why = ''; $exit = 0; $tExit = 0; // 盈亏/出场原因/出场价/出场时间
        for ($i = $i0; $i < $n; $i++) { // 持仓逐根推进
            if ($i > $i0) { // 信号根自身不平仓
                if ($l[$i] <= $liqPx) { $why = 'LIQ'; $exit = $liqPx; $tExit = $ts[$i]; break; } // 触爆仓线
                if ($h[$i] >= $tpPx) { $exit = max($o[$i], $tpPx); $why = 'TP'; $tExit = $ts[$i]; break; } // 触止盈(开盘高于止盈按开盘成交)
                if ($ts[$i] - $ts[$i0] >= $TMO_MS) { $exit = $c[$i]; $why = 'TO'; $tExit = $ts[$i]; break; } // 超时7天平仓
            }
        }
        if ($why == '') { $exit = $c[$n - 1]; $why = 'END'; $tExit = $ts[$n - 1]; } // 数据尽头按END
        $gross = $q * $exit - $net; $feeOut = $q * $exit * $FEE; // 毛盈亏与平仓手续费
        $pnl = $gross - $feeOut; // 净盈亏
        if ($refill) { $pnlTotal += $pnl; $curve[] = [$tExit, round(1.0 + $pnlTotal, 4)]; } // 滚仓模式: 累计盈亏计曲线
        else { $cash += $u + $gross - $feeOut; $curve[] = [$tExit, round($cash, 4)]; $pnlTotal += $pnl; if ($cash < $U && $why != 'TP') $bankrupt = true; } // 严格模式: 现金结转, 亏穿且非止盈→破产
        $rounds++; if ($pnl > 0) $wins++; if ($why == 'LIQ') $liqN++; if ($why == 'TO') $toN++; // 各类计数
        $trades[] = [$s['t'], $tExit, round($s['H'], 2), round($s['L'], 2), round($s['fib'], 2), round($entry, 2), round($exit, 2), round($pnl, 4), $why]; // 记录交易明细
    }
    $maxdd = 0.0; $peak = -INF; // 最大回撤/峰值
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; } // 逐点算回撤
    return ['n' => $rounds, 'win' => $wins, 'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0, // 返回: 笔数/胜数/胜率
            'pnl' => round($pnlTotal, 4), 'liq' => $liqN, 'to' => $toN, 'cashEnd' => round($refill ? 1 + $pnlTotal : $cash, 4), // 总盈亏/爆仓/超时/期末资金
            'maxdd' => round($maxdd, 4), 'trades' => $trades, 'curve' => $curve]; // 最大回撤/明细/曲线
}

$TPS = ['tp05' => 0.005, 'tp10' => 0.01, 'tp20' => 0.02]; // 三档固定止盈: +0.5%/+1%/+2%
$bts = []; // 回测结果容器
foreach (['10x' => [$LEV, $LIQ], '3x' => [3, 1.0 / 3 - $MMR]] as $lk => [$lev2, $liq2]) { // 10x与3x两档杠杆
    foreach ($TPS as $k => $tp) { // 逐止盈档
        $bts[$k][$lk]['refill'] = bt($ts, $o, $h, $l, $c, $signals, $tp, true, $FEE, $lev2, $liq2, $U, $TMO_MS); // 滚仓模式
        $bts[$k][$lk]['strict'] = bt($ts, $o, $h, $l, $c, $signals, $tp, false, $FEE, $lev2, $liq2, $U, $TMO_MS); // 严格模式
    }
}
$bts['rehigh']['10x']['refill'] = btRehigh($ts, $o, $h, $l, $c, $signals, true, $FEE, $LEV, $LIQ, $U, $TMO_MS); // 回归前高止盈(10x滚仓)
$bts['rehigh']['10x']['strict'] = btRehigh($ts, $o, $h, $l, $c, $signals, false, $FEE, $LEV, $LIQ, $U, $TMO_MS); // 回归前高止盈(10x严格)
$bts['rehigh']['3x']['refill'] = btRehigh($ts, $o, $h, $l, $c, $signals, true, $FEE, 3, 1.0 / 3 - $MMR, $U, $TMO_MS); // 回归前高止盈(3x滚仓)
$bts['rehigh']['3x']['strict'] = btRehigh($ts, $o, $h, $l, $c, $signals, false, $FEE, 3, 1.0 / 3 - $MMR, $U, $TMO_MS); // 回归前高止盈(3x严格)
foreach ($bts as $k => $modes) foreach ($modes as $lk => $mm) foreach ($mm as $mk => $v) // 逐结果打印对齐表格
    printf("  %-8s %-4s %-7s n=%-4d wr=%5.1f%% liq=%-3d pnl=%+9.2f cashEnd=%8.2f\n", $k, $lk, $mk, $v['n'], $v['wr'], $v['liq'], $v['pnl'], $v['cashEnd']); // 输出统计行
function btRehigh($ts, $o, $h, $l, $c, $signals, $refill, $FEE, $LEV, $LIQ, $U, $TMO_MS) { // 回归前高止盈回测(止盈价=锚定高点H)
    $n = count($c); $pnlTotal = 0.0; $rounds = 0; $wins = 0; $liqN = 0; $toN = 0; // K线数/累计盈亏/笔数/胜/爆仓/超时
    $cash = $U; $bankrupt = false; $trades = []; $curve = []; // 现金/破产标志/明细/曲线
    foreach ($signals as $s) { // 逐信号交易
        if ($bankrupt) break; // 破产终止
        $i0 = $s['i']; // 信号根下标
        if (!$refill && $cash < $U) break; // 严格模式资金不足停
        if (!$refill) $cash -= $U; // 严格模式扣1U本金
        $net = $U * $LEV * (1 - $FEE); // 扣费后净名义金
        $q = $net / $c[$i0]; $u = $U; // 买入数量/本金
        $entry = $c[$i0]; $tpPx = $s['H']; $liqPx = $entry * (1 - $LIQ); // 入场价/止盈价=前高H/爆仓价
        $why = ''; $exit = 0; $tExit = 0; // 出场状态
        for ($i = $i0; $i < $n; $i++) { // 持仓逐根推进
            if ($i > $i0) { // 信号根自身不平仓
                if ($l[$i] <= $liqPx) { $why = 'LIQ'; $exit = $liqPx; $tExit = $ts[$i]; break; } // 触爆仓线
                if ($h[$i] >= $tpPx) { $exit = max($o[$i], $tpPx); $why = 'TP'; $tExit = $ts[$i]; break; } // 回归前高止盈
                if ($ts[$i] - $ts[$i0] >= $TMO_MS) { $exit = $c[$i]; $why = 'TO'; $tExit = $ts[$i]; break; } // 超时平仓
            }
        }
        if ($why == '') { $exit = $c[$n - 1]; $why = 'END'; $tExit = $ts[$n - 1]; } // 数据尽头按END
        $gross = $q * $exit - $net; $feeOut = $q * $exit * $FEE; // 毛盈亏与平仓费
        $pnl = $gross - $feeOut; // 净盈亏
        if ($refill) { $pnlTotal += $pnl; $curve[] = [$tExit, round(1.0 + $pnlTotal, 4)]; } // 滚仓模式计曲线
        else { $cash += $u + $gross - $feeOut; $curve[] = [$tExit, round($cash, 4)]; $pnlTotal += $pnl; if ($cash < $U && $why != 'TP') $bankrupt = true; } // 严格模式现金结转
        $rounds++; if ($pnl > 0) $wins++; if ($why == 'LIQ') $liqN++; if ($why == 'TO') $toN++; // 各类计数
        $trades[] = [$s['t'], $tExit, round($s['H'], 2), round($s['L'], 2), round($s['fib'], 2), round($entry, 2), round($exit, 2), round($pnl, 4), $why]; // 记录明细
    }
    $maxdd = 0.0; $peak = -INF; // 回撤/峰值
    foreach ($curve as $p) { if ($p[1] > $peak) $peak = $p[1]; $dd = $peak - $p[1]; if ($dd > $maxdd) $maxdd = $dd; } // 逐点算回撤
    return ['n' => $rounds, 'win' => $wins, 'wr' => $rounds ? round($wins / $rounds * 100, 1) : 0, // 返回统计
            'pnl' => round($pnlTotal, 4), 'liq' => $liqN, 'to' => $toN, 'cashEnd' => round($refill ? 1 + $pnlTotal : $cash, 4), // 总盈亏/期末资金
            'maxdd' => round($maxdd, 4), 'trades' => $trades, 'curve' => $curve]; // 回撤/明细/曲线
}

// ---- 5) 输出 (K线抽样到 ~12000 根方便网页加载, fib 全量) ----
$step = max(1, (int)ceil($n / 12000)); // 抽样步长
$k2 = []; // 抽样K线
for ($i = 0; $i < $n; $i += $step) $k2[] = [$ts[$i], $o[$i], $h[$i], $l[$i], $c[$i]]; // 等间隔抽样
$sigOut = []; // 信号输出
foreach ($signals as $s) $sigOut[] = ['t' => $s['t'], 'tH' => $s['tH'], 'tL' => $s['tL'], 'H' => $s['H'], 'L' => $s['L'], 'fib' => $s['fib']]; // 全量信号(含划线四点)
$out = ['generated' => date('Y-m-d H:i:s'), 'inst' => 'ETH-USDT-SWAP', 'bar' => '15m', // 元信息: 生成时间/标的/周期
        'span' => [bj($ts[0]), bj($ts[$n - 1])], 'bars_total' => $n, 'bars_sampled' => count($k2), 'step' => $step, // 跨度/总数/抽样数/步长
        'params' => ['lev' => $LEV, 'fee' => $FEE, 'liq' => round($LIQ, 4), 'u' => $U, 'timeout_days' => 7, 'drop_min' => $DROP_MIN], // 回测参数
        'candles' => $k2, 'signals' => $sigOut, 'backtests' => $bts]; // K线/信号/回测结果
file_put_contents(__DIR__ . '/../web/fib618eth_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入前端数据JSON
echo "saved size=" . round(filesize(__DIR__ . '/../web/fib618eth_data.json') / 1048576, 2) . "MB\n"; // 输出文件大小
