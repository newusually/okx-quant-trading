<?php
/**
 * td9bs_all.php — 全市场 买九9 vs 卖九9 五变体对照 一年回测生成器 (2026-09-27 用户指令)
 * 用户原话: "还不够 买九和卖9 不一样 算一下买和卖的具体回测情况 回测一年"
 *
 * 标准TD口径(strategy/sigstore.go 同源):
 *   买九9 = 底序列计数恰好=9(连续9根收盘<第4根前收盘, 下跌中跌不动 → 止跌预警, 买点)
 *   卖九9 = 顶序列计数恰好=9(连续9根收盘>第4根前收盘, 上涨中涨不动 → 滞涨预警, 卖点)
 * 五变体:
 *   A 买九9做多: 底九9开多, 止盈+2%
 *   B 卖九9做空: 顶九9开空, 止盈-2%(价格跌2%)
 *   C 经典TD多: 底九9开多 → 顶九9信号平多(无固定止盈)
 *   D 经典TD空: 顶九9开空 → 底九9信号平空(无固定止盈)
 *   E 双向: 底九9开多 + 顶九9开空(同一仓位槽, 各自止盈2%)
 * 周期: 15m / 1H / 4H | 10x(爆仓线≈9.6%) | 无加仓(纯粹比较买卖方向) | 超时7天 | taker 0.05% | 每合约1U本金
 * 输出: web/td9bsall_data.json
 */
ini_set('memory_limit', '4096M');                                    // 提升PHP内存上限到4G, 全市场×3周期×5变体数据量大
set_time_limit(0);                                                   // 取消执行时间限制
$LEV = 10; $MMR = 0.004; $FEE = 0.0005; $TP = 0.02; $U = 1.0;        // 参数: 杠杆10x/维持保证金率0.4%/taker费0.05%/止盈2%/每合约本金1U
$TMO_MS = 7 * 86400 * 1000;                                          // 超时=7天(毫秒), 到期强制平仓
$LIQ = 1.0 / $LEV - $MMR;                                            // 爆仓跌幅 = 1/10 - 0.4% = 9.6%
$BARS = ['15m' => '15m', '1h' => '1H', '4h' => '4H'];                // 三种K线周期(键=表名后缀, 值=显示名)
$VARIANTS = [                                                        // 五种策略变体定义(键=变体字母)
    'A' => '买九9做多(TP+2%)',                                        // A: 底九9开多, 固定止盈+2%
    'B' => '卖九9做空(TP-2%)',                                        // B: 顶九9开空, 固定止盈-2%
    'C' => '底九9买→顶九9平多(经典TD)',                                // C: 经典TD多, 反向信号平仓
    'D' => '顶九9空→底九9平空(经典TD反)',                              // D: 经典TD空, 反向信号平仓
    'E' => '双向:底九9多+顶九9空',                                     // E: 同一仓位槽双向开仓
];
$CAP = 120; $CAP_CURVE = 100;                                        // 明细成交最多保留120笔 / 盈利曲线最多100个点

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; } // 建立本地MariaDB连接(trading库, utf8mb4)
$DB = db();                                                          // 获取数据库连接对象
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); } // 毫秒时间戳转北京时间字符串(UTC+8)

$insts = [];                                                         // 币种基础名列表(如 eth)
$j = json_decode(file_get_contents('E:/datas/insts_all.json'), true); // 读取全市场合约清单JSON
foreach ($j['insts'] as $s) if (preg_match('/^(.+)-USDT-SWAP$/', $s, $m2)) $insts[] = strtolower($m2[1]); // 只取USDT永续, 提取基础名并转小写
$insts = array_values(array_unique($insts)); sort($insts);           // 去重并按名称排序
echo "insts=" . count($insts) . "\n";                                // 打印币种数量

$gT0 = (time() - 365 * 86400) * 1000;                                // 数据起点=365天前(毫秒)

/**
 * 方向通用模拟
 * @param int $mode 0=A买多 1=B卖空 2=C TD多 3=D TD空 4=E双向
 */
function sim($ts, $o, $h, $l, $c, $mode, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, $withDetail) { // 通用模拟函数: 按mode跑对应变体
    global $CAP, $CAP_CURVE;                                         // 引用全局的明细/曲线数量上限
    $n = count($c);                                                  // K线总根数
    $cash = $U; $pos = null; $bankrupt = false;                      // 初始化: 现金1U/持仓/破产标记
    $rounds = 0; $wins = 0; $pnlTotal = 0.0; $liqN = 0; $toN = 0; $sigN = 0; // 统计: 轮数/盈利数/总盈亏/爆仓/超时/信号平仓数
    $trades = []; $curve = []; $tdUp = 0; $tdDown = 0;               // 明细列表/盈利曲线/顶序列计数/底序列计数
    for ($i = 4; $i < $n; $i++) {                                    // 主循环: 从第5根起(需要前4根比较)
        // 标准TD序列: 顶=连续收盘>第4根前收(涨不动) 底=连续收盘<第4根前收(跌不动)
        $tdUp = ($c[$i] > $c[$i - 4]) ? $tdUp + 1 : 0;               // 顶序列: 收盘>4根前收盘则计数+1, 否则清零
        $tdDown = ($c[$i] < $c[$i - 4]) ? $tdDown + 1 : 0;           // 底序列: 收盘<4根前收盘则计数+1, 否则清零
        $buy9 = ($tdDown == 9);   // 买九9: 底序列恰9                  // 买九信号: 底计数恰好达到9
        $sell9 = ($tdUp == 9);    // 卖九9: 顶序列恰9                  // 卖九信号: 顶计数恰好达到9
        if ($pos) {                                                  // 有持仓时先处理出场
            $dir = $pos['dir'];                                      // 持仓方向(1多/-1空)
            $liqPx = $dir > 0 ? $pos['avg'] * (1 - $LIQ) : $pos['avg'] * (1 + $LIQ); // 爆仓价: 多头向下/空头向上偏移9.6%
            $hitLiq = $dir > 0 ? ($l[$i] <= $liqPx) : ($h[$i] >= $liqPx); // 是否触及爆仓价(按方向取低/高价)
            $hitTP = $dir > 0 ? ($h[$i] >= $pos['tp']) : ($l[$i] <= $pos['tp']); // 是否触及止盈价
            $sigExit = ($mode == 2 && $dir > 0 && $sell9) || ($mode == 3 && $dir < 0 && $buy9); // C/D变体: 出现反向九转信号则平仓
            $exit = null; $why = '';                                 // 出场价/原因初始化
            if ($hitLiq) { $exit = $liqPx; $why = 'LIQ'; }           // 爆仓优先
            elseif ($mode != 2 && $mode != 3 && $hitTP) { $exit = max($o[$i], min($pos['tp'], $dir > 0 ? PHP_FLOAT_MAX : -PHP_FLOAT_MAX)); $exit = $dir > 0 ? max($o[$i], $pos['tp']) : min($o[$i], $pos['tp']); $why = 'TP'; } // 固定止盈(A/B/E): 多头取max(开盘,止盈)/空头取min(开盘,止盈), 防跳空高估
            elseif ($sigExit) { $exit = $c[$i]; $why = 'SIG'; }      // 反向信号平仓: 按当根收盘价
            elseif ($ts[$i] - $pos['t'] >= $TMO_MS) { $exit = $c[$i]; $why = 'TO'; } // 超时7天平仓: 按当根收盘价
            if ($exit !== null) {                                    // 本根有出场事件
                if ($why == 'LIQ') { $cash += 0; $pnl = -$pos['u']; } // 爆仓: 保证金全损, 现金不回补
                else {                                               // 非爆仓出场
                    if ($dir > 0) { $gross = $pos['q'] * $exit - $pos['net']; } // 多头毛利 = 数量×出场价 - 净名义成本
                    else { $gross = $pos['net'] - $pos['q'] * $exit; } // 空头毛利 = 净名义成本 - 数量×出场价
                    $feeOut = $pos['q'] * $exit * $FEE;              // 平仓手续费 = 数量×出场价×费率
                    $cash += $pos['u'] + $gross - $feeOut;           // 现金回补: 本金+毛利-平仓手续费
                    $pnl = $gross - $feeOut;                         // 本轮净盈亏
                }
                if ($withDetail) $trades[] = [$pos['t'], $ts[$i], $dir, $exit, round($pnl, 4), $why]; // 需要明细时记录: 开仓时间/平仓时间/方向/出场价/盈亏/原因
                $pnlTotal += $pnl; $rounds++;                        // 累加盈亏, 轮数+1
                if ($why == 'LIQ') $liqN++; elseif ($why == 'TO') $toN++; elseif ($why == 'SIG') $sigN++; // 分别统计爆仓/超时/信号平仓
                if ($pnl > 0) $wins++;                               // 盈利轮数+1
                $curve[] = [$ts[$i], round($cash, 4)];               // 记录盈利曲线点(时间+现金)
                $pos = null;                                         // 清空持仓
                if ($cash < $U && $why != 'TP') $bankrupt = true;    // 止盈以外出场后现金不足1U本金 → 破产, 停止开仓
            }
        }
        // 入场
        $wantLong = ($mode == 0 || $mode == 2 || $mode == 4) && $buy9;  // 需开多: A/C/E变体遇买九9
        $wantShort = ($mode == 1 || $mode == 3 || $mode == 4) && $sell9; // 需开空: B/D/E变体遇卖九9
        if (!$pos && !$bankrupt && ($wantLong || $wantShort) && $cash >= $U) { // 空仓/未破产/有信号/现金足 → 开仓
            $dir = $wantLong ? 1 : -1;   // 同刻双信号优先做多(极少见)
            $net = $U * $LEV * (1 - $FEE);                           // 净名义 = 本金×杠杆×(1-开仓费率)
            $cash -= $U;                                             // 现金扣减1U保证金
            $tp = $dir > 0 ? $c[$i] * (1 + $TP) : $c[$i] * (1 - $TP); // 止盈价: 多头+2%/空头-2%
            $pos = ['t' => $ts[$i], 'q' => $net / $c[$i], 'u' => $U, 'net' => $net, // 建仓: 时间/数量=净名义/价/本金/净名义
                    'avg' => $c[$i], 'tp' => $tp, 'dir' => $dir];    // 开仓均价/止盈价/方向
        }
    }
    if ($pos) {                                                      // 期末仍持仓 → 按最后收盘价标记平仓
        $exit = $c[$n - 1]; $dir = $pos['dir'];                      // 出场价=最后一根收盘价
        $gross = $dir > 0 ? $pos['q'] * $exit - $pos['net'] : $pos['net'] - $pos['q'] * $exit; // 按方向计算毛利
        $feeOut = $pos['q'] * $exit * $FEE;                          // 平仓手续费
        $cash += $pos['u'] + $gross - $feeOut;                       // 现金回补
        $pnl = $gross - $feeOut;                                     // 本轮净盈亏
        if ($withDetail) $trades[] = [$pos['t'], $ts[$n - 1], $dir, $exit, round($pnl, 4), 'END']; // 记录期末平仓明细(原因END)
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
          'pnl' => round($pnlTotal, 4), 'liq' => $liqN, 'to' => $toN, 'sigx' => $sigN, // 总盈亏/爆仓/超时/信号平仓数
          'bankrupt' => $bankrupt, 'cashEnd' => round($cash, 4), 'maxdd' => round($maxdd, 4)]; // 破产标记/期末现金/最大回撤
    if ($withDetail) { $r['curve'] = $curve; if (count($trades) > $CAP) $trades = array_slice($trades, 0, $CAP); $r['trades'] = $trades; } // 需要明细时附上曲线与截断后的成交列表
    return $r;                                                       // 返回该变体结果
}

$results = []; $matrix = []; $bestV = [];                            // 初始化: 各周期明细/汇总矩阵/各周期最优变体
foreach ($BARS as $bk => $okxBar) {                                  // 外层: 遍历三种K线周期
    $resBar = []; $done = 0;                                         // 该周期结果行列表与完成计数
    foreach ($insts as $base) {                                      // 中层: 遍历全部币种
        $tbl = "kline_{$base}_usdt_swap_{$bk}";                      // 拼接K线表名
        $r = $DB->query("SELECT candle_time,`c`,`h`,`l`,`o` FROM `$tbl` WHERE candle_time >= $gT0 ORDER BY candle_time ASC"); // 读取该币一年K线(时间升序)
        if (!$r) { $done++; continue; }                              // 表不存在/查询失败则跳过
        $ts = []; $c = []; $h = []; $l = []; $o = [];                // 初始化时间/收/高/低/开数组
        while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $c[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $o[] = (float)$x[4]; } // 逐行取数
        $r->free();                                                  // 释放结果集
        $n = count($c);                                              // 该币K线根数
        if ($n < 110) { $done++; continue; }                         // 数据不足110根(TD序列无法形成)则跳过
        $row = ['inst' => strtoupper($base), 'days' => round(($ts[$n - 1] - $ts[0]) / 86400000.0, 1), // 结果行: 币对名/数据跨度天数
                'bars' => $n, 'span' => [bj($ts[0]), bj($ts[$n - 1])], 'variants' => []]; // K线根数/数据起止/各变体结果
        foreach (array_keys($VARIANTS) as $vk) $row['variants'][$vk] = sim($ts, $o, $h, $l, $c, ord($vk) - 65, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, false); // 第一轮: 五变体快速模拟(不带明细)
        $resBar[] = $row;                                            // 收入该周期结果
        $done++;                                                     // 完成计数+1
        if ($done % 100 == 0) echo "$bk done $done\n";               // 每100个币打印一次进度
    }
    $matrix[$bk] = [];                                               // 初始化该周期汇总矩阵
    foreach (array_keys($VARIANTS) as $vk) {                         // 逐变体聚合
        $t = ['n' => 0, 'win' => 0, 'pnl' => 0, 'liq' => 0, 'to' => 0, 'sigx' => 0, 'bankrupt' => 0, 'insts' => 0, 'pos' => 0]; // 初始化聚合结构
        foreach ($resBar as $row) { $v = $row['variants'][$vk]; if ($v['n'] == 0) continue; // 遍历币种结果, 无成交的跳过
            $t['insts']++; $t['n'] += $v['n']; $t['win'] += $v['win']; $t['pnl'] += $v['pnl']; // 累加: 有效币种/轮数/盈利数/盈亏
            $t['liq'] += $v['liq']; $t['to'] += $v['to']; $t['sigx'] += $v['sigx']; // 累加: 爆仓/超时/信号平仓
            if ($v['bankrupt']) $t['bankrupt']++;                    // 破产币种数+1
            if ($v['pnl'] > 0) $t['pos']++; }                        // 盈利币种数+1
        $matrix[$bk][$vk] = $t;                                      // 存入矩阵
    }
    $bv = 'A'; $bp = -INF;                                           // 找该周期最优变体: 初值A与负无穷
    foreach ($matrix[$bk] as $vk => $t) if ($t['pnl'] > $bp) { $bp = $t['pnl']; $bv = $vk; } // 按总盈亏取最大者
    $bestV[$bk] = $bv;                                               // 记录最优变体
    // 全变体补明细(带 curve/trades)
    foreach ($resBar as $idx => $row) {                              // 遍历该周期每个币种
        $base = strtolower($row['inst']);                            // 取币种基础名
        $r = $DB->query("SELECT candle_time,`c`,`h`,`l`,`o` FROM kline_{$base}_usdt_swap_{$bk} WHERE candle_time >= $gT0 ORDER BY candle_time ASC"); // 重新读取该币K线(补明细用)
        $ts = []; $c = []; $h = []; $l = []; $o = [];                // 初始化数组
        while ($x = $r->fetch_row()) { $ts[] = (int)$x[0]; $c[] = (float)$x[1]; $h[] = (float)$x[2]; $l[] = (float)$x[3]; $o[] = (float)$x[4]; } // 逐行取数
        $r->free();                                                  // 释放结果集
        foreach (array_keys($VARIANTS) as $vk)                       // 五个变体逐个重跑
            $resBar[$idx]['variants'][$vk] = sim($ts, $o, $h, $l, $c, ord($vk) - 65, $LEV, $FEE, $TP, $LIQ, $U, $TMO_MS, true); // 第二轮: 带明细(curve/trades)的完整模拟
    }
    $results[$bk] = $resBar;                                         // 存该周期全部币种明细结果
    echo "$bk best=$bv pnl=$bp\n";                                   // 打印该周期最优变体与盈亏
}

$out = ['generated' => date('Y-m-d H:i:s'),                          // 输出结构: 生成时间
        'params' => ['lev' => $LEV, 'tp' => $TP, 'u' => $U, 'fee' => $FEE, 'liq' => $LIQ, 'timeout_days' => 7, 'adds' => 0], // 参数: 杠杆/止盈/本金/费率/爆仓跌幅/超时/无加仓
        'variants' => $VARIANTS, 'span' => [bj($gT0), bj(time() * 1000)], // 变体说明/回测区间
        'matrix' => $matrix, 'bestV' => $bestV, 'results' => $results]; // 汇总矩阵/各周期最优变体/全部明细
file_put_contents(__DIR__ . '/../web/td9bsall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写入JSON供报告页读取(中文不转义)
echo "saved size=" . round(filesize(__DIR__ . '/../web/td9bsall_data.json') / 1048576, 1) . "MB\n";   // 打印输出文件大小(MB)
foreach ($BARS as $bk => $_) { echo "== $bk ==\n"; foreach ($matrix[$bk] as $vk => $t) printf("  %s %s: pnl=%.1f n=%d wr=%.1f%% liq=%d sig=%d 盈利合约=%d\n", $vk, $VARIANTS[$vk], $t['pnl'], $t['n'], $t['n'] ? round($t['win'] / $t['n'] * 100, 1) : 0, $t['liq'], $t['sigx'], $t['pos']); } // 控制台按周期打印各变体汇总对比
