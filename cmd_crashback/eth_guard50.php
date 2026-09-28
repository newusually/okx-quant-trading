<?php
/**
 * eth_guard50.php — 亏损50U→实盘暂停·模拟盘跟单·模拟盈利+50U恢复 一年回测
 * 数据源: web/eth_20u_data.json (一年191笔信号, 20U基准保证金, pnl与保证金线性)
 * 规则: 实盘保证金 = 1U + 3U×已过天数, 封顶 余额/6; 六重共振+TP价格+2%+fib618加仓(序列已含)
 *       触发暂停: A=单笔亏损>50U / B=账户距高点回撤>50U
 *       暂停期间: 信号照常"模拟盘"跟单(同保证金阶梯), 模拟盘累计盈利 >= +50U → 恢复实盘
 * 输出: web/eth_guard50_data.json
 */
error_reporting(E_ERROR);                         // 只报致命错误, 屏蔽告警
$j = json_decode(file_get_contents(__DIR__ . '/../web/eth_20u_data.json'), true);  // 读入 eth_20u 回测的成交序列作为信号源
$trades0 = $j['trades'];                          // 取原始成交列表(每笔含进出场时间/结局/盈亏等)
$BAL0 = 500.0; $BASE = 20.0; $STEP = 3.0; $RESUME = 50.0;  // 初始资金500U/基准保证金20U/每天递增3U/恢复实盘所需模拟盈利50U

function marginAt($t, $start, $bal) {             // 计算某时点的保证金: 1U + 3U×已过天数, 封顶 余额/6
    global $STEP;                                 // 引用全局每日递增量
    $days = (int)floor((strtotime($t) - $start) / 86400);  // 距回测起点的整天数
    $m = 1.0 + $STEP * $days;                     // 基础保证金 = 1U + 3U×天数
    if ($m > $bal / 6.0) $m = $bal / 6.0;         // 封顶 = 当前余额÷6
    if ($m < 1) $m = 1;                           // 下限 1U
    return round($m, 2);                          // 保留两位小数返回
}

function simulate($trades0, $bal0, $mode) { // mode: null=无规则, 'A'=单笔亏50U, 'B'=回撤50U   // 核心模拟: 按规则控制实盘/模拟盘切换
    global $BASE;                                 // 引用全局基准保证金
    $bal = $bal0; $peak = $bal0; $paper = 0.0; $paused = false;  // 余额/权益峰值/模拟盘累计盈/是否暂停中
    $start = strtotime($trades0[0]['tin']);       // 回测起点时间
    $detail = []; $daily = []; $nReal = 0; $nPaper = 0; $nPause = 0;  // 明细/每日盈亏/实盘笔数/模拟笔数/暂停次数
    $gw = 0.0; $gl = 0.0; $minBal = $bal0; $mile = []; $marks = [1000, 5000, 10000, 30000];  // 总盈/总亏/最低余额/里程碑列表/目标线
    $pauseStreakMax = 0; $pauseStart = null; $pauseStreak = 0;  // 连续实盘段计数相关变量(保留统计)
    foreach ($trades0 as $t) {                    // 逐笔遍历信号
        $tin = $t['tin']; $tout = $t['tout']; $date = substr($tin, 0, 10);  // 进/出场时间与入场日期
        $m = marginAt($tin, $start, $bal);        // 该笔应投入的保证金
        $pnl0 = $t['pnl'] / $BASE * $m;           // 按保证金比例缩放盈亏(原序列基于20U基准)
        if ($paused) {                            // 暂停期间: 该笔走模拟盘
            $pp = round($pnl0, 2);                // 模拟盘盈亏(不入余额)
            $paper = round($paper + $pp, 2);      // 累计模拟盘盈亏
            $nPaper++;                            // 模拟笔数+1
            $detail[] = ['tin' => $tin, 'tout' => $tout, 'out' => $t['out'], 'adds' => $t['adds'],  // 记录模拟盘明细
                         'margin' => $m, 'pnl' => $pp, 'status' => 'PAPER', 'bal' => $bal, 'paper' => $paper];  // status=PAPER 标记
            if ($paper >= $RESUME) { $paused = false; $paper = 0.0; }  // 模拟盘累计盈利≥+50U → 恢复实盘并清零计数
            continue;                             // 模拟盘不影响余额, 直接下一笔
        }
        $pnl = round($pnl0, 2);                   // 实盘盈亏
        $bal = round($bal + $pnl, 2);             // 更新账户余额
        if ($bal > $peak) $peak = $bal;           // 更新权益峰值
        if ($bal < $minBal) $minBal = $bal;       // 记录最低余额
        $nReal++;                                 // 实盘笔数+1
        if ($pnl > 0) $gw += $pnl; else $gl += $pnl;  // 分别累计盈/亏
        $daily[$date] = round(($daily[$date] ?? 0) + $pnl, 2);  // 按日累计实盘盈亏
        $trig = false;                            // 是否触发暂停
        if ($mode === 'A' && $pnl <= -50) $trig = true;  // 方案A: 单笔亏损超50U → 触发
        if ($mode === 'B' && $peak - $bal > 50) $trig = true;  // 方案B: 距高点回撤超50U → 触发
        $detail[] = ['tin' => $tin, 'tout' => $tout, 'out' => $t['out'], 'adds' => $t['adds'],  // 记录实盘明细
                     'margin' => $m, 'pnl' => $pnl, 'status' => $trig ? 'PAUSE' : 'REAL', 'bal' => $bal, 'paper' => $paper];  // 触发笔标记 PAUSE
        if ($trig) {                              // 处理触发暂停
            $nPause++; $paused = true; $paper = 0.0;  // 暂停次数+1, 进入暂停态, 模拟盘计数清零
            if ($pauseStreak > $pauseStreakMax) $pauseStreakMax = $pauseStreak;  // 更新最长实盘连击记录
            $pauseStreak = 0;                     // 重置连击计数
        }
        foreach ($marks as $k => $mv) if ($bal >= $mv) { $mile[] = ['bal' => $mv, 'date' => $tout, 'margin' => $m]; unset($marks[$k]); }  // 余额达标即记录里程碑(1000/5000/10000/30000U)
    }
    ksort($daily);                                // 每日盈亏按日期排序
    return ['mode' => $mode, 'n' => $nReal, 'nPaper' => $nPaper, 'nPause' => $nPause,  // 返回汇总统计
            'total' => round($gw + $gl, 2), 'gross_win' => round($gw, 2), 'gross_lose' => round($gl, 2),  // 总盈亏/总盈/总亏
            'final' => round($bal, 2), 'min_bal' => round($minBal, 2), 'blown' => $minBal <= 0,  // 期末余额/最低余额/是否爆仓
            'detail' => $detail, 'daily' => $daily, 'milestones' => $mile];  // 明细/每日/里程碑
}

$sweep = [];                                      // 三种模式对比结果
foreach ([null => '无规则(对照)', 'A' => '单笔亏50U→停·模拟+50U恢复', 'B' => '回撤50U→停·模拟+50U恢复'] as $mode => $name) {  // 对照组/A方案/B方案
    $r = simulate($trades0, $BAL0, $mode);        // 跑该模式模拟
    $r['name'] = $name;                           // 附上模式名
    $sweep[$mode] = $r;                           // 存入对比表
    echo sprintf("%s: 实盘%d笔 模拟%d笔 暂停%d次 总=%+.2f 期末=%.2f 最低=%.2f\n",  // 打印该模式摘要
        $name, $r['n'], $r['nPaper'], $r['nPause'], $r['total'], $r['final'], $r['min_bal']);  // 各项统计
}
$main = $sweep['A'];                              // 以方案A为主展示对象
// 月度(实盘部分)
$mon = []; $monN = [];                            // 实盘月度盈亏/月度笔数
foreach ($main['detail'] as $d) if ($d['status'] != 'PAPER') { $k = substr($d['tout'], 0, 7); $mon[$k] = round(($mon[$k] ?? 0) + $d['pnl'], 2); $monN[$k] = ($monN[$k] ?? 0) + 1; }  // 只统计非模拟盘, 按出场月份聚合

$out = ['params' => ['balance' => $BAL0, 'step' => '+3U/天', 'resume' => $RESUME,  // 输出参数说明
        'rule' => '实盘: 单笔亏损>50U(方案A)/距高点回撤>50U(方案B) → 暂停实盘; 期间信号走模拟盘跟单(同保证金阶梯), 模拟盘累计盈利>=+50U → 恢复实盘; 无暂停版配置: 六重共振+100x+TP价格+2%+fib618加仓+只买涨'],  // 规则完整描述
        'main' => $main, 'monthly' => $mon, 'monthlyN' => $monN,  // 主结果(方案A全量)/月度盈亏/月度笔数
        'sweep' => array_map(function ($s) { unset($s['detail'], $s['daily'], $s['milestones']); return $s; }, $sweep)];  // 三模式对比(去掉大体量明细)
file_put_contents(__DIR__ . '/../web/eth_guard50_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出报告 JSON
echo "JSON written\n";                            // 完成提示
