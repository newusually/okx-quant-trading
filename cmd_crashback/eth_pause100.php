<?php
// eth_pause100.php — 100U亏损爆仓 → 停止24小时 一年模拟(500U起步, 保证金每天+1U, 余额/6封顶)
error_reporting(E_ERROR);
$j = json_decode(file_get_contents(__DIR__ . '/../web/eth_20u_data.json'), true);
$trades0 = $j['trades'];          // 原序列: 20U固定保证金, pnl与保证金线性
$BAL0 = 500.0;
$BASE = 20.0;                     // 原序列基准保证金(缩放用)

function simulate($trades0, $bal0, $pauseLoss) {
    $bal = $bal0; $pauseUntil = 0; $nPause = 0; $nSkip = 0; $pausedHours = 0.0;
    $start = strtotime($trades0[0]['tin']);
    $detail = []; $daily = []; $mile = []; $marks = [1000, 5000, 10000, 50000];
    foreach ($trades0 as $t) {
        $tin = strtotime($t['tin']); $tout = strtotime($t['tout']);
        $date = substr($t['tin'], 0, 10);
        if ($tin < $pauseUntil) {           // 暂停期: 信号出现也跳过
            $nSkip++;
            $detail[] = ['tin' => $t['tin'], 'tout' => $t['tout'], 'out' => $t['out'],
                         'margin' => null, 'pnl' => null, 'status' => 'SKIP', 'bal' => $bal];
            continue;
        }
        $days = (int)floor(($tin - $start) / 86400);
        $m = round(min(1.0 + $days, $bal / 6.0), 2);
        $pnl = round($t['pnl'] / 20.0 * $m, 2);
        $bal = round($bal + $pnl, 2);
        $trigger = ($pauseLoss !== null && $pnl <= -$pauseLoss);
        if ($trigger) { $pauseUntil = $tout + 86400; $nPause++; $pausedHours += 24; }
        $detail[] = ['tin' => $t['tin'], 'tout' => $t['tout'], 'out' => $t['out'], 'adds' => $t['adds'],
                     'margin' => $m, 'pnl' => $pnl, 'status' => $trigger ? 'PAUSE' : 'OK', 'bal' => $bal];
        $daily[$date] = ($daily[$date] ?? 0) + $pnl;
        foreach ($marks as $k => $mv) {
            if ($bal >= $mv) { $mile[] = ['bal' => $mv, 'date' => $t['tout'], 'margin' => $m]; unset($marks[$k]); }
        }
    }
    ksort($daily);
    $minBal = $bal0; $c = $bal0;
    foreach ($detail as $d) if ($d['status'] != 'SKIP') { $c = $d['bal']; if ($c < $minBal) $minBal = $c; }
    $wins = 0; $gw = 0.0; $gl = 0.0; $n = 0;
    foreach ($detail as $d) if ($d['status'] != 'SKIP') { $n++; if ($d['pnl'] > 0) { $wins++; $gw += $d['pnl']; } else $gl += $d['pnl']; }
    return ['n' => $n, 'win' => $wins, 'liq_skip' => $nSkip, 'nPause' => $nPause,
            'pausedHours' => $pausedHours, 'total' => round($gw + $gl, 2),
            'gross_win' => round($gw, 2), 'gross_lose' => round($gl, 2),
            'win_r' => $n ? round(100 * $wins / $n, 1) : 0,
            'final' => round($bal, 2), 'min_bal' => round($minBal, 2), 'blown' => ($minBal <= 0),
            'detail' => $detail, 'daily' => $daily, 'milestones' => $mile];
}

$sweep = [];
foreach (['无暂停' => null, '亏50U暂停' => 50, '亏100U暂停' => 100, '亏200U暂停' => 200] as $name => $pl) {
    $r = simulate($trades0, $BAL0, $pl);
    $r['name'] = $name;
    $sweep[] = $r;
    echo sprintf("%s: 笔数=%d 暂停%d次(跳过%d信号) 总=%+.2f 期末=%.2f 最低余额=%.2f 爆仓=%s\n",
        $name, $r['n'], $r['nPause'], $r['liq_skip'], $r['total'], $r['final'], $r['min_bal'], $r['blown'] ? 'YES' : 'no');
}
$main = $sweep[2];   // 亏100U暂停 = 主配置

// 月度
$mon = [];
foreach ($main['detail'] as $d) if ($d['status'] != 'SKIP') { $k = substr($d['tout'], 0, 7); $mon[$k] = round(($mon[$k] ?? 0) + $d['pnl'], 2); }

$out = ['params' => ['balance' => $BAL0, 'rule' => '单笔亏损>=100U(爆仓) -> 停止24小时, 之后等下一个信号; 保证金=1U+已过天数, 封顶余额/6; 六重共振+止盈价格+2%+fib618加仓, 只买涨, 100x'],
        'sweep' => array_map(function ($s) { unset($s['detail'], $s['daily'], $s['milestones']); return $s; }, $sweep),
        'main' => $main, 'monthly' => $mon];
file_put_contents(__DIR__ . '/../web/eth_pause100_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON written\n";
