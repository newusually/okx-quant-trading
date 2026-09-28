<?php
/**
 * eth_chippeak4.php — ETH 筹码峰战法·四周期共振 一年回测(CLI, 2026-09-25 用户指令)
 * 共振定义(以 3m 为入场时间轴):
 *   触发 = 3m 当根出现新鲜「双线合一」信号(价差≤0.4%, 此前24根内曾分离≥1%, 现价在峰区±1%, 2根去抖);
 *   共振 = 5m / 15m / 1h 各自【最新已收盘K线】的筹码峰双双线合一状态成立(价差≤0.4%);
 *   买入 = 触发×共振 → 下一根 3m 开盘。四周期筹码峰算法与单周期版完全一致
 *   (150根K线 vol_quote 60格增量直方图, 峰>1.3×均值, 相邻3格合并, 量能前2名)。
 * 加仓/平仓与单周期版相同:
 *   加仓 = 持仓中再触发(3m新信号×共振) 且 现价站上开仓峰区上方 → +1U(下一根开盘), 总保证金≤50U 停止;
 *   平仓 = 出货峰(收盘曾上穿开仓锁仓峰区上方后跌破峰区下沿, 下一根开盘全平) 或 超时14天;
 *   保证金 = 1U+3U×已过天数 封顶50U; 100x逐仓; 500U逐笔结转; 净口径含费。
 * 双配置: user = 无止盈·无止损·不模拟爆仓; ctrl = 止盈价格+2% + 真实爆仓线(-0.6%)。
 * 输出: web/chippeak4_data.json
 */
ini_set('memory_limit', '2048M');                                 // 提升 PHP 内存上限到 2G(四周期全量扫描)
set_time_limit(0);                                                // 取消脚本执行时间限制
$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆100x; 维持保证金率0.4%; 手续费与资金费率
$EQ0 = 500.0; $CAPM = 50.0; $ADDU = 1.0; $TPR = 0.02;             // 起始权益500U; 总保证金封顶50U; 每轮加仓1U; 止盈+2%
$W = 150; $NB = 60; $SEP_THR = 0.004; $SEP_HIST = 0.01; $SEP_LOOK = 24; $TO_BARS_DAYS = 14;  // 筹码峰: 窗口150根/60格/合一0.4%/分离1%/回看24根; 超时14天

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库并设 utf8mb4
$DB = db();                                                       // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行SQL收集全部结果行为数组
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }  // 毫秒转北京时间字符串
function bjday($ms) { return gmdate('Y-m-d', (int)($ms / 1000) + 8 * 3600); }   // 毫秒转北京日期字符串

function load_k($bar) {                                           // 加载 ETH 指定周期K线(带成交额)
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`,vol_quote FROM kline_eth_usdt_swap_$bar ORDER BY candle_time ASC");  // 按时间升序读 kline_eth_usdt_swap_<bar> 表
    $out = ['ts' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];  // 初始化结构
    foreach ($r as $x) { $out['ts'][] = (int)$x[0]; $out['o'][] = (float)$x[1]; $out['h'][] = (float)$x[2];  // 时间/开/高
        $out['l'][] = (float)$x[3]; $out['c'][] = (float)$x[4]; $out['v'][] = (float)$x[5]; }  // 低/收/成交额
    return $out;                                                  // 返回关联数组K线
}

/** 增量筹码分布器: 滚动窗口极值定区间, 区间漂移>0.5%或出界时全量重建, 否则增量加减 */
class Chip {                                                      // 筹码分布类(与单周期版同参)
    public $hist = []; public $lo = null; public $hi = null; public $bw = 0.0;  // 直方图/区间下界/上界/格宽
    private $nb; private $ring = []; private $ri = 0; private $cnt = 0; private $cap;  // 格数/环形缓冲/写指针/计数/容量
    function __construct($nb, $cap) { $this->nb = $nb; $this->cap = $cap; $this->hist = array_fill(0, $nb, 0.0); }  // 构造
    private function adds($l, $h, $v) {                           // 一根K线量均摊到价格格
        $nb = $this->nb; $a = array_fill(0, $nb, 0.0);            // 初始化增量
        if ($h <= $l || $v <= 0 || $this->bw <= 0) return $a;     // 无效数据返回空
        $bw = $this->bw;                                          // 当前格宽
        $i0 = (int)floor(($l - $this->lo) / $bw); $i1 = (int)floor(($h - $this->lo) / $bw);  // 高低价对应格下标
        $i0 = max(0, min($nb - 1, $i0)); $i1 = max(0, min($nb - 1, $i1));  // 钳制范围
        if ($i0 == $i1) { $a[$i0] = $v; return $a; }              // 同格全量入
        $per = $v / ($i1 - $i0 + 1);                              // 跨格均摊
        for ($i = $i0; $i <= $i1; $i++) $a[$i] = $per;            // 填充增量
        return $a;                                                // 返回增量
    }
    private function rebuild() {                                  // 全量重建直方图
        $lo = INF; $hi = -INF;                                    // 窗口极值
        foreach ($this->ring as $b) { if ($b === null) continue; if ($b[0] < $lo) $lo = $b[0]; if ($b[1] > $hi) $hi = $b[1]; }  // 扫描范围
        if ($hi <= $lo) $hi = $lo * 1.001 + 1e-9;                 // 防零区间
        $this->lo = $lo; $this->hi = $hi; $this->bw = ($hi - $lo) / $this->nb;  // 更新区间与格宽
        $this->hist = array_fill(0, $this->nb, 0.0);              // 清零
        foreach ($this->ring as $b) {                             // 重放全部K线
            if ($b === null) continue;                            // 空槽跳过
            $a = $this->adds($b[0], $b[1], $b[2]);                // 该根增量
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i];  // 累加
        }
    }
    function push($l, $h, $v) {                                   // 推入新K线
        $this->ring[$this->ri] = [$l, $h, $v];                    // 写入环形缓冲
        $this->ri = ($this->ri + 1) % $this->cap;                 // 指针前进
        if ($this->cnt < $this->cap) { $this->cnt++; $this->rebuild(); return; }  // 未满重建
        $lo = INF; $hi = -INF;                                    // 满窗扫描极值
        foreach ($this->ring as $b) { if ($b === null) continue; if ($b[0] < $lo) $lo = $b[0]; if ($b[1] > $hi) $hi = $b[1]; }  // 范围
        $rng = $hi - $lo; if ($rng <= 0) $rng = $hi * 0.001 + 1e-9;  // 防零区间
        $need = ($this->bw <= 0) || ($l < $this->lo) || ($h > $this->hi)  // 判定重建: 出界
             || (abs($lo - $this->lo) > 0.005 * $rng) || (abs($hi - $this->hi) > 0.005 * $rng);  // 或漂移>0.5%
        if ($need) { $this->lo = $lo; $this->hi = $hi; $this->bw = $rng / $this->nb;  // 需要重建
            $this->hist = array_fill(0, $this->nb, 0.0);          // 清零
            foreach ($this->ring as $b) { if ($b === null) continue;  // 重放全部
                $a = $this->adds($b[0], $b[1], $b[2]);
                for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i]; }  // 累加重建
        } else {
            $a = $this->adds($l, $h, $v);                         // 无需重建: 增量加
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i];  // 累加
        }
    }
    function evictOldest() {                                      // 淘汰最旧K线
        $old = $this->ring[$this->ri];                            // 写指针处最旧
        if ($old === null) return;                                // 空槽返回
        if ($this->bw > 0) {                                      // 有格宽才扣减
            $a = $this->adds($old[0], $old[1], $old[2]);          // 该根原增量
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0 && $this->hist[$i] >= $a[$i]) $this->hist[$i] -= $a[$i];  // 扣减
        }
        $this->ring[$this->ri] = null;                            // 清空槽
        $this->ri = ($this->ri + 1) % $this->cap;                 // 指针前进
    }
    /** 返回 [洗盘峰价, 拉升峰价] 或 null */
    function peaks() {                                            // 识别筹码峰
        $nb = $this->nb; $h = $this->hist;                        // 格数与直方图
        $tot = 0.0; for ($i = 0; $i < $nb; $i++) $tot += $h[$i];  // 总量
        if ($tot <= 0) return null;                               // 无量null
        $avg = $tot / $nb;                                        // 均值
        $pk = [];                                                 // 候选峰
        for ($i = 1; $i < $nb - 1; $i++) {                        // 局部极大扫描
            if ($h[$i] > $h[$i - 1] && $h[$i] >= $h[$i + 1] && $h[$i] > $avg * 1.3)  // >1.3×均值
                $pk[] = [$this->lo + ($i + 0.5) * $this->bw, $h[$i], $i];  // [峰价,峰量,下标]
        }
        if (count($pk) == 0) return null;                         // 无候选
        $mg = []; $cur = $pk[0];                                  // 相邻3格合并
        for ($k = 1; $k < count($pk); $k++) {
            if ($pk[$k][2] - $cur[2] <= 3) {                      // 间隔≤3格
                $tv = $cur[1] + $pk[$k][1];                       // 合并量
                $cur = [($cur[0] * $cur[1] + $pk[$k][0] * $pk[$k][1]) / $tv, $tv, $cur[2]];  // 量加权合并
            } else { $mg[] = $cur; $cur = $pk[$k]; }              // 收存另起
        }
        $mg[] = $cur;                                             // 收存末峰
        if (count($mg) < 2) return null;                          // 不足双峰
        usort($mg, function ($a, $b) { return $b[1] <=> $a[1]; });  // 按量降序
        $two = [$mg[0], $mg[1]];                                  // 量能前二
        usort($two, function ($a, $b) { return $a[0] <=> $b[0]; });  // 按价升序
        return [$two[0][0], $two[1][0]];                          // 返回[洗盘峰价, 拉升峰价]
    }
}

/** 单周期全量扫描: 输出逐根 merged/sig/双峰价 */
function scan($bar, $g) {                                         // 对一个周期做全量筹码峰扫描
    global $W, $NB, $SEP_THR, $SEP_HIST, $SEP_LOOK;               // 引入全局参数
    $ts = $g['ts']; $l = $g['l']; $h = $g['h']; $c = $g['c']; $v = $g['v'];  // 拆序列
    $n = count($ts);                                              // 根数
    $chip = new Chip($NB, $W);                                    // 筹码分布器
    for ($i = 0; $i < $W; $i++) $chip->push($l[$i], $h[$i], $v[$i]);  // 预填充窗口
    $sepPrev = array_fill(0, $SEP_LOOK, 0.0); $sepIdx = 0;        // 分离度环形历史
    $merged = array_fill(0, $n, false);                           // 逐根合一状态
    $sig = array_fill(0, $n, false);                              // 逐根新鲜信号
    $pl = array_fill(0, $n, 0.0); $ph = array_fill(0, $n, 0.0);   // 逐根洗盘峰价/拉升峰价
    $cnt = ['merged' => 0, 'sig' => 0];                           // 计数
    for ($i = $W; $i < $n; $i++) {                                // 滚动扫描
        $chip->evictOldest();                                     // 淘汰最旧
        $chip->push($l[$i], $h[$i], $v[$i]);                      // 推入新K线
        $pk = $chip->peaks();                                     // 双峰
        $sep = 0.0;                                               // 本根分离度
        if ($pk !== null) {                                       // 有双峰
            $pl[$i] = $pk[0]; $ph[$i] = $pk[1];                   // 记录双峰价
            $sep = ($pk[1] - $pk[0]) / $pk[0];                    // 分离度
            if ($sep <= $SEP_THR && $c[$i] >= $pk[0] * 0.99 && $c[$i] <= $pk[1] * 1.01) {  // 合一: 价差≤0.4%且现价在峰区±1%
                $had = false;                                     // 先分后合检查
                for ($k = 0; $k < $SEP_LOOK; $k++) if ($sepPrev[$k] >= $SEP_HIST) { $had = true; break; }  // 近24根曾分离≥1%
                if ($had) {
                    $merged[$i] = true; $cnt['merged']++;         // 合一状态成立
                    if (empty($sig[$i - 1]) && empty($sig[$i - 2])) { $sig[$i] = true; $cnt['sig']++; }  // 前2根无信号(去抖)才记新鲜信号
                }
            }
        }
        $sepPrev[$sepIdx] = $sep; $sepIdx = ($sepIdx + 1) % $SEP_LOOK;  // 更新分离历史
    }
    echo "[$bar] n=$n merged={$cnt['merged']} sig={$cnt['sig']}\n"; flush();  // 输出该周期统计
    return ['ts' => $ts, 'merged' => $merged, 'sig' => $sig, 'pl' => $pl, 'ph' => $ph, 'cnt' => $cnt];  // 返回扫描结果
}

// ===== 四周期全量扫描 =====
$S = [];                                                          // 各周期扫描结果
foreach (['3m', '5m', '15m', '1h'] as $b) $S[$b] = scan($b, load_k($b));  // 逐周期加载并扫描

// ===== 3m 时间轴共振信号 =====
$tsF = $S['3m']['ts']; $oF = load_k('3m')['o']; $hF = load_k('3m')['h']; $lF = load_k('3m')['l']; $cF = load_k('3m')['c'];  // 3m时间轴与K线序列
$nF = count($tsF);                                                // 3m根数
$barMin = 3;                                                      // 入场周期为3分钟
$p = ['5m' => $W, '15m' => $W, '1h' => $W];                       // 各高周期对齐指针(从窗口满后起)
$len = ['5m' => count($S['5m']['ts']), '15m' => count($S['15m']['ts']), '1h' => count($S['1h']['ts'])];  // 各周期根数
$step = ['5m' => 5, '15m' => 15, '1h' => 60];                     // 各周期K线分钟数
$tol = ['5m' => 120, '15m' => 240, '1h' => 2880];   // 共振容差(分钟): 该周期最近一次「双线合一信号」距今 ≤ 容差
$lastM = ['5m' => -1, '15m' => -1, '1h' => -1];  // 最近一次合一K线的收盘时间(ms)
$entry = array_fill(0, $nF, false);                               // 3m共振入场标志
$passCnt = ['base' => 0, 'm5' => 0, 'm15' => 0, 'm1h' => 0, 'entry' => 0];  // 各层通过计数
for ($i = $W; $i < $nF - 1; $i++) {                               // 逐根3m判定共振
    if (empty($S['3m']['merged'][$i])) continue;   // 3m 处于双线合一状态(触发周期)
    $passCnt['base']++;                                           // 通过触发层
    $t = $tsF[$i];                                                // 当前时点
    foreach (['5m', '15m', '1h'] as $b) {                         // 逐高周期检查共振
        while ($p[$b] < $len[$b] && $S[$b]['ts'][$p[$b]] + $step[$b] * 60000 <= $t) {  // 推进到不晚于t的已收盘K线
            if ($S[$b]['merged'][$p[$b]]) $lastM[$b] = $S[$b]['ts'][$p[$b]] + $step[$b] * 60000;  // 记录最近合一收盘时间
            $p[$b]++;                                             // 指针前进
        }
        if ($lastM[$b] < 0 || $t - $lastM[$b] > $tol[$b] * 60000) continue 2;   // 超出共振容差
        $passCnt[$b === '5m' ? 'm5' : ($b === '15m' ? 'm15' : 'm1h')]++;  // 该层通过计数
    }
    $entry[$i] = true; $passCnt['entry']++;                       // 三周期全过→共振入场成立
}
echo "resonance entries=$passCnt[entry]\n"; flush();              // 输出共振入场数

/** 3m时间轴模拟 ($useTP/$useLiq) */
function sim($entry, $S3, $tsF, $oF, $hF, $lF, $cF, $useTP, $useLiq) {  // 共振信号回测模拟
    global $LEV, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $EQ0, $CAPM, $ADDU, $W, $TO_BARS_DAYS, $TPR;  // 引入全局参数
    $n = count($tsF);                                             // 3m根数
    $fundPerBar = $FUND8H / 160;   // 3m: 每15分钟=1/32个8h, 3m=1/160
    $eq = $EQ0; $startT = $tsF[$W]; $trades = []; $bankrupt = false;  // 权益/起点/交易列表/破产标志
    $i = $W;                                                      // 从窗口满后开始
    while ($i < $n - 1 && !$bankrupt) {                           // 主循环
        if (empty($entry[$i])) { $i++; continue; }                // 无共振入场信号跳过
        $days = (int)floor((($tsF[$i] - $startT) / 1000) / 86400);  // 已过天数
        $M0 = round(min(1.0 + 3.0 * $days, $CAPM), 2);            // 保证金=1U+3U×天数, 封顶50U
        if ($M0 < 1) $M0 = 1.0;                                   // 保底1U
        if ($M0 > $eq) { if ($eq < 1) { $bankrupt = true; break; } $M0 = round($eq, 2); }  // 保证金不超权益
        $e = $oF[$i + 1];                                         // 入场价=下一根开盘
        $notional = $M0 * $LEV; $avg = $e; $adds = 0; $mg = $M0;  // 名义/均价/加仓/保证金
        $fee = $notional * $FEE_MAKER; $funding = 0.0;            // 开仓maker费/资金费
        $tgtPx = $useTP ? $avg * (1 + $TPR) : INF;                // 止盈价(对照配置)
        $liqPx = $useLiq ? $avg * (1 - 1.0 / $LEV + $MMR) : -INF; // 真实爆仓价(对照配置)
        // 锚定开仓时 3m 筹码峰区
        $zb0 = $S3['pl'][$i] > 0 ? $S3['pl'][$i] * 0.998 : $e * 0.99;  // 峰区下沿=洗盘峰-0.2%(无峰用入场价-1%)
        $zt0 = $S3['ph'][$i] > 0 ? $S3['ph'][$i] * 1.002 : $e * 1.01;  // 峰区上沿=拉升峰+0.2%
        $armed = false; $outcome = null; $exitPx = null; $j = $i + 1;  // 锁仓成立标志/出场结果/出场价/下标
        while ($j < $n) {                                         // 逐根推进持仓
            $funding += $notional * $fundPerBar;                  // 累计资金费
            if ($useLiq && $lF[$j] <= $liqPx) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 触真实爆仓线
            if ($useTP && $hF[$j] >= $tgtPx) { $outcome = 'WIN'; $exitPx = $tgtPx; break; }   // 触止盈
            if (!$armed && $cF[$j] > $zt0) $armed = true;         // 收盘上穿峰区上沿→锁仓拉升成立
            if ($armed && $cF[$j] < $zb0) { $outcome = 'SHIP'; $exitPx = ($j + 1 < $n) ? $oF[$j + 1] : $cF[$j]; break; }  // 成立后跌破下沿→出货全平
            if ($j - $i >= $TO_BARS_DAYS * 480) { $outcome = 'TO'; $exitPx = $cF[$j]; break; }  // 超14天(480根3m/天)超时平收
            if (isset($entry[$j]) && $cF[$j] > $zt0 && $mg + $ADDU <= $CAPM && $j + 1 < $n) {  // 加仓: 再现共振信号且现价站上峰区上沿且保证金≤50U
                $ap = $oF[$j + 1];                                // 加仓价=下一根开盘
                if ($ap > 0) {
                    $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);  // 加权更新均价
                    $notional += $ADDU * $LEV; $adds++; $mg += $ADDU;  // 扩名义/计次数/加保证金
                    $fee += $ADDU * $LEV * $FEE_TAKER;            // 加仓taker费
                    $tgtPx = $useTP ? $avg * (1 + $TPR) : INF;    // 重算止盈价
                    $liqPx = $useLiq ? $avg * (1 - 1.0 / $LEV + $MMR) : -INF;  // 重算爆仓价
                }
            }
            $j++;                                                 // 推进下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $cF[$j]; }  // 数据耗尽按末根收盘了结
        $gross = ($exitPx - $avg) * $notional / $avg;             // 毛盈亏
        $fee += $notional * $FEE_TAKER;                           // 平仓taker费
        $pnl = $gross - $fee - $funding;                          // 净盈亏
        if ($pnl < -$eq) { $pnl = -$eq; $bankrupt = true; }       // 亏损超权益则清零破产
        $eq += $pnl;                                              // 更新权益
        if ($eq <= 0.01) $bankrupt = true;                        // 权益近零破产
        $trades[] = ['tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds,  // 记录交易明细
                     'm0' => $M0, 'mg' => round($mg, 2), 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),  // 保证金/盈亏/权益
                     'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];  // 持仓小时
        $i = $j + 1;                                              // 出场后继续
    }
    $tot = ['n' => count($trades), 'ship' => 0, 'to' => 0, 'win' => 0, 'liq' => 0, 'adds' => 0, 'pnl' => 0.0];  // 汇总容器
    foreach ($trades as $x) {                                     // 逐笔统计
        if ($x['out'] == 'SHIP') $tot['ship']++;                  // 出货计数
        if ($x['out'] == 'TO') $tot['to']++;                      // 超时计数
        if ($x['out'] == 'WIN') $tot['win']++;                    // 止盈计数
        if ($x['out'] == 'LIQ') $tot['liq']++;                    // 爆仓计数
        $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl'];     // 累加加仓/盈亏
    }
    $tot['pnl'] = round($tot['pnl'], 2);                          // 盈亏保留2位
    $months = []; $daily = [];                                    // 按月/按日汇总
    foreach ($trades as $x) {                                     // 逐笔归组
        $d0 = bjday($x['tout']); $mo = substr($d0, 0, 7);         // 出场日期与月份
        $m = &$months[$mo];                                       // 按月引用
        $m['n'] = ($m['n'] ?? 0) + 1;                             // 月笔数
        $m['ship'] = ($m['ship'] ?? 0) + ($x['out'] == 'SHIP' ? 1 : 0);  // 月出货
        $m['to'] = ($m['to'] ?? 0) + ($x['out'] == 'TO' ? 1 : 0); // 月超时
        $m['win'] = ($m['win'] ?? 0) + ($x['out'] == 'WIN' ? 1 : 0);  // 月止盈
        $m['liq'] = ($m['liq'] ?? 0) + ($x['out'] == 'LIQ' ? 1 : 0);  // 月爆仓
        $m['adds'] = ($m['adds'] ?? 0) + $x['adds'];              // 月加仓
        $m['mg'] = round(($m['mg'] ?? 0) + $x['mg'], 2);          // 月保证金
        $m['pnl'] = round(($m['pnl'] ?? 0) + $x['pnl'], 2);       // 月盈亏
        $m['eq_end'] = $x['eq'];                                  // 月末权益
        unset($m);                                                // 解除引用
        $d = &$daily[$d0];                                        // 按日引用
        $d['n'] = ($d['n'] ?? 0) + 1;                             // 日笔数
        $d['adds'] = ($d['adds'] ?? 0) + $x['adds'];              // 日加仓
        $d['mg'] = round(($d['mg'] ?? 0) + $x['mg'], 2);          // 日保证金
        $d['pnl'] = round(($d['pnl'] ?? 0) + $x['pnl'], 2);       // 日盈亏
        $d['eq_end'] = $x['eq'];                                  // 日末权益
        unset($d);                                                // 解除引用
    }
    $peak = -INF; $maxdd = 0;                                     // 权益峰值与回撤
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }  // 逐笔更新
    echo sprintf("[sim %s] n=%d ship=%d win=%d liq=%d to=%d adds=%d pnl=%.1f eqEnd=%.1f bk=%s\n",  // 输出该配置汇总
        $useTP ? 'ctrl' : 'user', $tot['n'], $tot['ship'], $tot['win'], $tot['liq'], $tot['to'], $tot['adds'], $tot['pnl'], $eq, $bankrupt ? 'Y' : 'N');
    flush();                                                      // 立即输出
    return ['summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),  // 返回汇总
            'bankrupt' => $bankrupt, 'months' => $months, 'daily' => $daily, 'trades' => $trades];  // 破产/月日/明细
}

$results = [];                                                    // 双配置结果
$results['user'] = sim($entry, $S['3m'], $tsF, $oF, $hF, $lF, $cF, false, false);  // user配置: 无止盈无止损不模拟爆仓
$results['ctrl'] = sim($entry, $S['3m'], $tsF, $oF, $hF, $lF, $cF, true, true);    // ctrl对照: 止盈+2%+真实爆仓线

$out = [                                                          // 组装输出JSON
    'meta' => [
        'inst' => 'ETH-USDT-SWAP', 'lev' => $LEV, 'eq0' => $EQ0, 'capm' => $CAPM,  // 基础参数
        'pass' => $passCnt,                                       // 共振各层通过计数
        'spans' => ['3m' => [bj($S['3m']['ts'][0]), bj(end($S['3m']['ts']))],  // 各周期数据区间
                    '5m' => [bj($S['5m']['ts'][0]), bj(end($S['5m']['ts']))],
                    '15m' => [bj($S['15m']['ts'][0]), bj(end($S['15m']['ts']))],
                    '1h' => [bj($S['1h']['ts'][0]), bj(end($S['1h']['ts']))]],
        'counts' => ['3m' => $S['3m']['cnt'], '5m' => $S['5m']['cnt'], '15m' => $S['15m']['cnt'], '1h' => $S['1h']['cnt']],  // 各周期信号计数
        'params' => '四周期共振筹码峰(共振容差: 5m≤2小时 / 15m≤4小时 / 1h≤48小时, 以各周期最近一次双线合一收盘距今计): 触发=3m 处于「双线合一」状态(拉升峰与洗盘峰价差≤0.4%且现价在峰区±1%内, 先分后合≥1%); 共振=5m/15m/1h 容差窗口内均出现过双线合一 → 下一根3m开盘买入 | 加仓=持仓中再出现共振入场点且现价站上开仓峰区上方 +1U, 总保证金≤50U | 平仓=出货峰(收盘曾上穿开仓锁仓峰区后跌破下沿)或超时14天 | 保证金=1U+3U×已过天数 封顶50U | 100x逐仓 | 起始500U | 净口径含手续费+资金费 | 筹码峰=各周期150根K线 vol_quote 60格增量直方图, 峰>1.3×均值, 相邻3格合并, 量能前2名 | user配置=无止盈无止损不模拟爆仓(权益归0破产); ctrl对照=止盈价格+2%+真实爆仓线-0.6%',  // 完整策略口径
    ],
    'results' => $results,                                        // 双配置结果
];
file_put_contents('E:/finally-main/web/chippeak4_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出JSON
echo "DATA OK " . round(memory_get_peak_usage(true) / 1048576) . "MB\n";  // 完成提示与内存峰值
