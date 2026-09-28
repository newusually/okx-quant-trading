<?php
/**
 * eth_compound.php — 500U起步·保证金每天+1U·一年模拟(CLI)
 * 本脚本测什么: 复利口径模拟——复用 eth_20u 固定保证金模拟的交易序列(信号/出场与仓位无关, 盈亏对保证金线性),
 *   把每笔保证金改为 1U+每天递增step U(封顶 余额/6), 起始500U, 100x逐仓, 只买涨, TP+2%, fib618加仓≤5轮, 无止损。
 * 参数: step 扫描 1/2/5/10 U/天; 交易序列来源 web/eth_20u_data.json。
 * 输出: 各步长对比(sweep) + step=1 完整结果(逐笔/日/月P&L、权益里程碑), 写 web/eth_compound_data.json。
 */
ini_set('memory_limit', '512M');                    // 内存上限提高到512M(逐笔明细数组较大)
set_time_limit(0);                                  // 取消脚本执行时间限制(长跑批处理)
$BASE = json_decode(file_get_contents('E:/finally-main/web/eth_20u_data.json'), true); // 读取20U固定保证金基准模拟结果(JSON)
$trades0 = $BASE['trades'];   // tout='Y-m-d H:i'字符串, pnl为20U基准   // 取出逐笔交易序列(出场时间为字符串, pnl按20U保证金计算)
$BAL0 = 500.0;                                      // 起始总权益 500U

function compound_daily1($trades0, $bal0, $step = 1) { // 复利模拟函数: 按每笔保证金=1U+step×已过天数 重演交易序列
    $bal = $bal0; $minBal = $bal; $blown = false; $blownAt = null; // 初始化余额/最低余额/是否爆仓/爆仓时间
    $out = []; $daily = []; $mon = [];              // 逐笔明细/按日P&L/按月P&L 三个容器
    $t0 = null;                                     // 首笔入场时间戳(用于计算已过天数)
    $marks = [10, 50, 100, 500, 1000, 5000, 10000, 50000, 100000]; // 权益里程碑档位(U)
    $milestones = []; foreach ($marks as $mk) $milestones[$mk] = null; // 里程碑初始化为"未达到"
    $mi = 0; $no = 0; $lastM = 0.0;                 // 里程碑游标/交易序号/最后一笔保证金
    foreach ($trades0 as $t) {                      // 逐笔重演基准交易序列
        $no++;                                      // 交易序号递增
        $day = substr($t['tout'], 0, 10);           // 该笔出场日期(Y-m-d)
        if ($t0 === null) $t0 = strtotime($t['tin']); // 记录首笔入场时间作为天数基准
        $daysElapsed = max(0, (int)floor((strtotime($t['tin']) - $t0) / 86400)); // 距首笔已过整天数
        // 每笔保证金 = 1U + 已过天数, 封顶 余额/6 (满轮爆仓-6M不致死)
        $m = 1.0 + $step * $daysElapsed;            // 本笔保证金 = 1U + step×已过天数
        $mCap = $bal / 6.0;                         // 保证金封顶 = 当前余额/6(留出加仓空间防爆)
        if ($m > $mCap) $m = $mCap;                 // 超上限则压到上限
        if ($bal < 0.3) { $blown = true; $blownAt = $t['tout']; break; } // 余额低于0.3U判定爆仓, 记录时间并停止
        $pnl = $t['pnl'] * ($m / 20.0);   // 对保证金线性   // 按比例缩放pnl: 基准是20U保证金, 盈亏与保证金成正比
        $bal += $pnl;                               // 累计到余额(逐笔结转=复利)
        if ($bal < $minBal) $minBal = $bal;         // 更新历史最低余额
        $daily[$day] = ($daily[$day] ?? 0) + $pnl;  // 累计当日P&L
        $k = substr($day, 0, 7); $mon[$k] = round(($mon[$k] ?? 0) + $pnl, 2); // 累计当月P&L(保留2位)
        $out[] = ['no' => $no, 'day' => $daysElapsed, // 记录本笔明细: 序号/已过天数
            'tin' => $t['tin'], 'tout' => $t['tout'], 'out' => $t['out'], 'adds' => $t['adds'], // 入/出场时间/出场原因/加仓轮数
            'margin' => round($m, 2), 'pnl' => round($pnl, 2), 'bal' => round($bal, 2)]; // 保证金/本笔盈亏/交易后余额
        $lastM = $m;                                // 记录最后一笔保证金
        while ($mi < count($marks) && $bal >= $marks[$mi]) { // 余额突破里程碑档位(可能一笔跨多档)
            $milestones[$marks[$mi]] = ['date' => $t['tout'], 'margin' => round($m, 2), 'bal' => round($bal, 2)]; // 记录达成时间/当时保证金/余额
            $mi++;                                  // 移动到下一档
        }
    }
    ksort($daily);                                  // 按日期排序日P&L
    $dayRows = []; $c = 0; $wd = 0; $ld = 0;        // 日行数组/累计值/盈利天数/亏损天数
    foreach ($daily as $d => $v) { $c += $v; if ($v >= 0) $wd++; else $ld++; $dayRows[] = ['d' => $d, 'pnl' => round($v, 2), 'bal' => round($bal0 + $c, 2)]; } // 逐日生成[pnl, 累计权益]并统计盈亏天数
    $mseries = []; foreach ($out as $t) $mseries[] = $t['margin']; // 收集每笔保证金序列(取最大值用)
    return ['final' => round($bal, 2), 'profit' => round($bal - $bal0, 2), // 返回: 最终余额/净利润
            'min_bal' => round($minBal, 2), 'blown' => $blown, 'blown_at' => $blownAt, // 最低余额/是否爆仓/爆仓时间
            'n' => count($out), 'win_days' => $wd, 'lose_days' => $ld, // 笔数/盈亏天数
            'margin_max' => round(max($mseries), 2), 'margin_last' => round($lastM, 2), // 最大/最后保证金
            'monthly' => $mon, 'daily' => $dayRows, 'trades' => $out, 'milestones' => $milestones]; // 月/日/逐笔明细+里程碑
}

$sweep = [];                                        // 步长扫描结果容器
foreach ([1, 2, 5, 10] as $st) { $s = compound_daily1($trades0, $BAL0, $st); $sweep[] = ['step' => $st, 'final' => $s['final'], 'min_bal' => $s['min_bal'], 'blown' => $s['blown']]; echo "step=+{$st}U/day final={$s['final']} min_bal={$s['min_bal']} blown=" . ($s['blown'] ? 'YES' : 'no') . "\n"; } // 对4种递增步长分别模拟并打印对比
$r = compound_daily1($trades0, $BAL0, 1);           // 正式结果: step=1(每天+1U)
echo "final={$r['final']} profit={$r['profit']} min_bal={$r['min_bal']} blown=" . ($r['blown'] ? 'YES@' . $r['blown_at'] : 'no') . " margin_last={$r['margin_last']} margin_max={$r['margin_max']}\n"; // 打印核心汇总指标
foreach ($r['monthly'] as $k => $v) echo "mon $k: $v\n"; // 打印每月P&L
foreach ($r['milestones'] as $mk => $v) echo "milestone {$mk}U: " . ($v ? "{$v['date']} (当日保证金{$v['margin']}U)" : '未达到') . "\n"; // 打印各权益里程碑达成情况

$out = [                                            // 组装输出JSON
    'params' => ['start' => 500, 'lev' => 100, 'tp' => '价格+2%', 'addon' => 'fib618加仓(额=当笔保证金, 最多5轮)', // 参数: 起始500U/100x/止盈/加仓规则
                 'rule' => '每笔保证金 = 1U + 已过天数, 封顶 余额/6', 'dir' => '只买涨', // 保证金递增规则/只做方向
                 'span' => $BASE['params']['span'], 'range' => $BASE['params']['range']], // 沿用基准模拟的周期与时间范围
    'sweep' => $sweep,                              // 4种步长的对比结果
    'result' => $r,                                 // step=1 的完整结果
];
file_put_contents('E:/finally-main/web/eth_compound_data.json', json_encode($out, JSON_UNESCAPED_UNICODE)); // 写出JSON报告(中文不转义)
echo "JSON OK trades=" . count($r['trades']) . " days=" . count($r['daily']) . "\n"; // 打印完成信息(笔数/天数)
