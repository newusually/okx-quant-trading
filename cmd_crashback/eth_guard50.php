<?php
// eth_guard50.php — 亏损50U→实盘暂停·模拟盘跟单·模拟盈利+50U恢复 一年回测
// 数据: web/eth_20u_data.json (一年191笔信号, 20U基准保证金, pnl与保证金线性)
// 规则: 实盘保证金 = 1U + 3U×已过天数, 封顶 余额/6; 六重共振+TP价格+2%+fib618加仓(序列已含)
//       触发暂停: A=单笔亏损>50U / B=账户距高点回撤>50U
//       暂停期间: 信号照常"模拟盘"跟单(同保证金阶梯), 模拟盘累计盈利 >= +50U → 恢复实盘
error_reporting(E_ERROR);
$j = json_decode(file_get_contents(__DIR__ . '/../web/eth_20u_data.json'), true);
$trades0 = $j['trades'];
$BAL0 = 500.0; $BASE = 20.0; $STEP = 3.0; $RESUME = 50.0;

function marginAt($t, $start, $bal) {
    global $STEP;
    $days = (int)floor((strtotime($t) - $start) / 86400);
    $m = 1.0 + $STEP * $days;
    if ($m > $bal / 6.0) $m = $bal / 6.0;
    if ($m < 1) $m = 1;
    return round($m, 2);
}

function simulate($trades0, $bal0, $mode) { // mode: null=无规则, 'A'=单笔亏50U, 'B'=回撤50U
    global $BASE;
    $bal = $bal0; $peak = $bal0; $paper = 0.0; $paused = false;
    $start = strtotime($trades0[0]['tin']);
    $detail = []; $daily = []; $nReal = 0; $nPaper = 0; $nPause = 0;
    $gw = 0.0; $gl = 0.0; $minBal = $bal0; $mile = []; $marks = [1000, 5000, 10000, 30000];
    $pauseStreakMax = 0; $pauseStart = null; $pauseStreak = 0;
    foreach ($trades0 as $t) {
        $tin = $t['tin']; $tout = $t['tout']; $date = substr($tin, 0, 10);
        $m = marginAt($tin, $start, $bal);
        $pnl0 = $t['pnl'] / $BASE * $m;
        if ($paused) {
            $pp = round($pnl0, 2);
            $paper = round($paper + $pp, 2);
            $nPaper++;
            $detail[] = ['tin' => $tin, 'tout' => $tout, 'out' => $t['out'], 'adds' => $t['adds'],
                         'margin' => $m, 'pnl' => $pp, 'status' => 'PAPER', 'bal' => $bal, 'paper' => $paper];
            if ($paper >= $RESUME) { $paused = false; $paper = 0.0; }
            continue;
        }
        $pnl = round($pnl0, 2);
        $bal = round($bal + $pnl, 2);
        if ($bal > $peak) $peak = $bal;
        if ($bal < $minBal) $minBal = $bal;
        $nReal++;
        if ($pnl > 0) $gw += $pnl; else $gl += $pnl;
        $daily[$date] = round(($daily[$date] ?? 0) + $pnl, 2);
        $trig = false;
        if ($mode === 'A' && $pnl <= -50) $trig = true;
        if ($mode === 'B' && $peak - $bal > 50) $trig = true;
        $detail[] = ['tin' => $tin, 'tout' => $tout, 'out' => $t['out'], 'adds' => $t['adds'],
                     'margin' => $m, 'pnl' => $pnl, 'status' => $trig ? 'PAUSE' : 'REAL', 'bal' => $bal, 'paper' => $paper];
        if ($trig) {
            $nPause++; $paused = true; $paper = 0.0;
            if ($pauseStreak > $pauseStreakMax) $pauseStreakMax = $pauseStreak;
            $pauseStreak = 0;
        }
        foreach ($marks as $k => $mv) if ($bal >= $mv) { $mile[] = ['bal' => $mv, 'date' => $tout, 'margin' => $m]; unset($marks[$k]); }
    }
    ksort($daily);
    return ['mode' => $mode, 'n' => $nReal, 'nPaper' => $nPaper, 'nPause' => $nPause,
            'total' => round($gw + $gl, 2), 'gross_win' => round($gw, 2), 'gross_lose' => round($gl, 2),
            'final' => round($bal, 2), 'min_bal' => round($minBal, 2), 'blown' => $minBal <= 0,
            'detail' => $detail, 'daily' => $daily, 'milestones' => $mile];
}

$sweep = [];
foreach ([null => '无规则(对照)', 'A' => '单笔亏50U→停·模拟+50U恢复', 'B' => '回撤50U→停·模拟+50U恢复'] as $mode => $name) {
    $r = simulate($trades0, $BAL0, $mode);
    $r['name'] = $name;
    $sweep[$mode] = $r;
    echo sprintf("%s: 实盘%d笔 模拟%d笔 暂停%d次 总=%+.2f 期末=%.2f 最低=%.2f\n",
        $name, $r['n'], $r['nPaper'], $r['nPause'], $r['total'], $r['final'], $r['min_bal']);
}
$main = $sweep['A'];
// 月度(实盘部分)
$mon = []; $monN = [];
foreach ($main['detail'] as $d) if ($d['status'] != 'PAPER') { $k = substr($d['tout'], 0, 7); $mon[$k] = round(($mon[$k] ?? 0) + $d['pnl'], 2); $monN[$k] = ($monN[$k] ?? 0) + 1; }

$out = ['params' => ['balance' => $BAL0, 'step' => '+3U/天', 'resume' => $RESUME,
        'rule' => '实盘: 单笔亏损>50U(方案A)/距高点回撤>50U(方案B) → 暂停实盘; 期间信号走模拟盘跟单(同保证金阶梯), 模拟盘累计盈利>=+50U → 恢复实盘; 无暂停版配置: 六重共振+100x+TP价格+2%+fib618加仓+只买涨'],
        'main' => $main, 'monthly' => $mon, 'monthlyN' => $monN,
        'sweep' => array_map(function ($s) { unset($s['detail'], $s['daily'], $s['milestones']); return $s; }, $sweep)];
file_put_contents(__DIR__ . '/../web/eth_guard50_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON written\n";
