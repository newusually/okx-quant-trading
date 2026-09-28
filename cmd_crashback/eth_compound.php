<?php
/**
 * eth_compound.php — 500U起步·保证金每天+1U·一年模拟(CLI)
 * 规则: 100x逐仓 只买涨 六重共振+TP价格+2%+fib618加仓(加仓额=当笔保证金, 最多5轮)
 * 每笔保证金 = min(1U + 已过天数, 余额/6)  ← 每天+1U, 余额不够加时自动封顶
 * 交易序列复用 20U 固定保证金模拟(信号/出场与仓位无关, 盈亏对保证金线性)
 * 输出: web/eth_compound_data.json
 */
ini_set('memory_limit', '512M');
set_time_limit(0);
$BASE = json_decode(file_get_contents('E:/finally-main/web/eth_20u_data.json'), true);
$trades0 = $BASE['trades'];   // tout='Y-m-d H:i'字符串, pnl为20U基准
$BAL0 = 500.0;

function compound_daily1($trades0, $bal0, $step = 1) {
    $bal = $bal0; $minBal = $bal; $blown = false; $blownAt = null;
    $out = []; $daily = []; $mon = [];
    $t0 = null;
    $marks = [10, 50, 100, 500, 1000, 5000, 10000, 50000, 100000];
    $milestones = []; foreach ($marks as $mk) $milestones[$mk] = null;
    $mi = 0; $no = 0; $lastM = 0.0;
    foreach ($trades0 as $t) {
        $no++;
        $day = substr($t['tout'], 0, 10);
        if ($t0 === null) $t0 = strtotime($t['tin']);
        $daysElapsed = max(0, (int)floor((strtotime($t['tin']) - $t0) / 86400));
        // 每笔保证金 = 1U + 已过天数, 封顶 余额/6 (满轮爆仓-6M不致死)
        $m = 1.0 + $step * $daysElapsed;
        $mCap = $bal / 6.0;
        if ($m > $mCap) $m = $mCap;
        if ($bal < 0.3) { $blown = true; $blownAt = $t['tout']; break; }
        $pnl = $t['pnl'] * ($m / 20.0);   // 对保证金线性
        $bal += $pnl;
        if ($bal < $minBal) $minBal = $bal;
        $daily[$day] = ($daily[$day] ?? 0) + $pnl;
        $k = substr($day, 0, 7); $mon[$k] = round(($mon[$k] ?? 0) + $pnl, 2);
        $out[] = ['no' => $no, 'day' => $daysElapsed,
            'tin' => $t['tin'], 'tout' => $t['tout'], 'out' => $t['out'], 'adds' => $t['adds'],
            'margin' => round($m, 2), 'pnl' => round($pnl, 2), 'bal' => round($bal, 2)];
        $lastM = $m;
        while ($mi < count($marks) && $bal >= $marks[$mi]) {
            $milestones[$marks[$mi]] = ['date' => $t['tout'], 'margin' => round($m, 2), 'bal' => round($bal, 2)];
            $mi++;
        }
    }
    ksort($daily);
    $dayRows = []; $c = 0; $wd = 0; $ld = 0;
    foreach ($daily as $d => $v) { $c += $v; if ($v >= 0) $wd++; else $ld++; $dayRows[] = ['d' => $d, 'pnl' => round($v, 2), 'bal' => round($bal0 + $c, 2)]; }
    $mseries = []; foreach ($out as $t) $mseries[] = $t['margin'];
    return ['final' => round($bal, 2), 'profit' => round($bal - $bal0, 2),
            'min_bal' => round($minBal, 2), 'blown' => $blown, 'blown_at' => $blownAt,
            'n' => count($out), 'win_days' => $wd, 'lose_days' => $ld,
            'margin_max' => round(max($mseries), 2), 'margin_last' => round($lastM, 2),
            'monthly' => $mon, 'daily' => $dayRows, 'trades' => $out, 'milestones' => $milestones];
}

$sweep = [];
foreach ([1, 2, 5, 10] as $st) { $s = compound_daily1($trades0, $BAL0, $st); $sweep[] = ['step' => $st, 'final' => $s['final'], 'min_bal' => $s['min_bal'], 'blown' => $s['blown']]; echo "step=+{$st}U/day final={$s['final']} min_bal={$s['min_bal']} blown=" . ($s['blown'] ? 'YES' : 'no') . "\n"; }
$r = compound_daily1($trades0, $BAL0, 1);
echo "final={$r['final']} profit={$r['profit']} min_bal={$r['min_bal']} blown=" . ($r['blown'] ? 'YES@' . $r['blown_at'] : 'no') . " margin_last={$r['margin_last']} margin_max={$r['margin_max']}\n";
foreach ($r['monthly'] as $k => $v) echo "mon $k: $v\n";
foreach ($r['milestones'] as $mk => $v) echo "milestone {$mk}U: " . ($v ? "{$v['date']} (当日保证金{$v['margin']}U)" : '未达到') . "\n";

$out = [
    'params' => ['start' => 500, 'lev' => 100, 'tp' => '价格+2%', 'addon' => 'fib618加仓(额=当笔保证金, 最多5轮)',
                 'rule' => '每笔保证金 = 1U + 已过天数, 封顶 余额/6', 'dir' => '只买涨',
                 'span' => $BASE['params']['span'], 'range' => $BASE['params']['range']],
    'sweep' => $sweep,
    'result' => $r,
];
file_put_contents('E:/finally-main/web/eth_compound_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));
echo "JSON OK trades=" . count($r['trades']) . " days=" . count($r['daily']) . "\n";
