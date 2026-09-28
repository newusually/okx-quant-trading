<?php
/**
 * eth_pause100.php — 100U亏损爆仓 → 停止24小时 一年模拟(500U起步, 保证金每天+1U, 余额/6封顶)
 * 数据源: web/eth_20u_data.json 一年成交序列(pnl与保证金线性, 按比例缩放)
 * 规则: 保证金 = 1U + 1U×已过天数, 封顶 余额/6; 策略为六重共振+TP价格+2%+fib618加仓
 *       触发: 单笔亏损达到阈值(主配置100U) → 暂停24小时, 期间信号全部跳过
 * 输出: web/eth_pause100_data.json
 */
error_reporting(E_ERROR);                         // 只报致命错误
$j = json_decode(file_get_contents(__DIR__ . '/../web/eth_20u_data.json'), true);  // 读入 eth_20u 回测成交序列
$trades0 = $j['trades'];          // 原序列: 20U固定保证金, pnl与保证金线性   // 取原始成交列表
$BAL0 = 500.0;                                    // 初始资金 500U
$BASE = 20.0;                     // 原序列基准保证金(缩放用)   // 原序列的基准保证金, 用于按比例换算盈亏

function simulate($trades0, $bal0, $pauseLoss) {  // 核心模拟: pauseLoss=暂停触发亏损额(null=不暂停)
    $bal = $bal0; $pauseUntil = 0; $nPause = 0; $nSkip = 0; $pausedHours = 0.0;  // 余额/暂停截止时间/暂停次数/跳过信号数/累计暂停小时
    $start = strtotime($trades0[0]['tin']);       // 回测起点时间
    $detail = []; $daily = []; $mile = []; $marks = [1000, 5000, 10000, 50000];  // 明细/每日盈亏/里程碑/目标线
    foreach ($trades0 as $t) {                    // 逐笔遍历信号
        $tin = strtotime($t['tin']); $tout = strtotime($t['tout']);  // 进出场时间戳
        $date = substr($t['tin'], 0, 10);         // 入场日期
        if ($tin < $pauseUntil) {           // 暂停期: 信号出现也跳过
            $nSkip++;                             // 跳过计数+1
            $detail[] = ['tin' => $t['tin'], 'tout' => $t['tout'], 'out' => $t['out'],  // 记录跳过的信号
                         'margin' => null, 'pnl' => null, 'status' => 'SKIP', 'bal' => $bal];  // 保证金/盈亏为空, 状态 SKIP
            continue;                             // 不产生盈亏, 下一笔
        }
        $days = (int)floor(($tin - $start) / 86400);  // 距起点整天数
        $m = round(min(1.0 + $days, $bal / 6.0), 2);  // 保证金 = 1U + 1U×天数, 封顶余额/6
        $pnl = round($t['pnl'] / 20.0 * $m, 2);   // 盈亏按保证金比例从20U基准缩放
        $bal = round($bal + $pnl, 2);             // 更新余额
        $trigger = ($pauseLoss !== null && $pnl <= -$pauseLoss);  // 是否触发暂停(亏损达到阈值)
        if ($trigger) { $pauseUntil = $tout + 86400; $nPause++; $pausedHours += 24; }  // 触发: 出场后24小时内暂停, 计数
        $detail[] = ['tin' => $t['tin'], 'tout' => $t['tout'], 'out' => $t['out'], 'adds' => $t['adds'],  // 记录实盘明细
                     'margin' => $m, 'pnl' => $pnl, 'status' => $trigger ? 'PAUSE' : 'OK', 'bal' => $bal];  // 触发笔标记 PAUSE
        $daily[$date] = ($daily[$date] ?? 0) + $pnl;  // 按日累计盈亏
        foreach ($marks as $k => $mv) {           // 检查里程碑
            if ($bal >= $mv) { $mile[] = ['bal' => $mv, 'date' => $t['tout'], 'margin' => $m]; unset($marks[$k]); }  // 余额达标记录里程碑(1000/5000/10000/50000U)
        }
    }
    ksort($daily);                                // 每日盈亏按日期排序
    $minBal = $bal0; $c = $bal0;                  // 最低余额初始化
    foreach ($detail as $d) if ($d['status'] != 'SKIP') { $c = $d['bal']; if ($c < $minBal) $minBal = $c; }  // 扫描非跳过笔的余额求最低
    $wins = 0; $gw = 0.0; $gl = 0.0; $n = 0;      // 胜场/总盈/总亏/实盘笔数
    foreach ($detail as $d) if ($d['status'] != 'SKIP') { $n++; if ($d['pnl'] > 0) { $wins++; $gw += $d['pnl']; } else $gl += $d['pnl']; }  // 统计实盘胜率与盈亏
    return ['n' => $n, 'win' => $wins, 'liq_skip' => $nSkip, 'nPause' => $nPause,  // 返回汇总
            'pausedHours' => $pausedHours, 'total' => round($gw + $gl, 2),  // 累计暂停小时/总盈亏
            'gross_win' => round($gw, 2), 'gross_lose' => round($gl, 2),  // 总盈/总亏
            'win_r' => $n ? round(100 * $wins / $n, 1) : 0,  // 胜率%
            'final' => round($bal, 2), 'min_bal' => round($minBal, 2), 'blown' => ($minBal <= 0),  // 期末/最低余额/是否爆仓
            'detail' => $detail, 'daily' => $daily, 'milestones' => $mile];  // 明细/每日/里程碑
}

$sweep = [];                                      // 四档阈值对比
foreach (['无暂停' => null, '亏50U暂停' => 50, '亏100U暂停' => 100, '亏200U暂停' => 200] as $name => $pl) {  // 对照组与50/100/200U三档
    $r = simulate($trades0, $BAL0, $pl);          // 跑该档模拟
    $r['name'] = $name;                           // 附名称
    $sweep[] = $r;                                // 存入对比表
    echo sprintf("%s: 笔数=%d 暂停%d次(跳过%d信号) 总=%+.2f 期末=%.2f 最低余额=%.2f 爆仓=%s\n",  // 打印摘要
        $name, $r['n'], $r['nPause'], $r['liq_skip'], $r['total'], $r['final'], $r['min_bal'], $r['blown'] ? 'YES' : 'no');  // 各项统计
}
$main = $sweep[2];   // 亏100U暂停 = 主配置   // 以100U档为主展示对象

// 月度
$mon = [];                                        // 主配置月度盈亏
foreach ($main['detail'] as $d) if ($d['status'] != 'SKIP') { $k = substr($d['tout'], 0, 7); $mon[$k] = round(($mon[$k] ?? 0) + $d['pnl'], 2); }  // 非跳过笔按出场月份聚合

$out = ['params' => ['balance' => $BAL0, 'rule' => '单笔亏损>=100U(爆仓) -> 停止24小时, 之后等下一个信号; 保证金=1U+已过天数, 封顶余额/6; 六重共振+止盈价格+2%+fib618加仓, 只买涨, 100x'],  // 参数与规则说明
        'sweep' => array_map(function ($s) { unset($s['detail'], $s['daily'], $s['milestones']); return $s; }, $sweep),  // 四档对比(去掉大体量明细)
        'main' => $main, 'monthly' => $mon];      // 主配置全量结果/月度盈亏
file_put_contents(__DIR__ . '/../web/eth_pause100_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出报告 JSON
echo "JSON written\n";                            // 完成提示
