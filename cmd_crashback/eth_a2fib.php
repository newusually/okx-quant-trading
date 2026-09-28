<?php
/**
 * eth_a2fib.php — ETH 单合约 A2(轨道共振24根窗+合一24根窗+均值回归中轨平仓) × 斐波那契加仓 一年回测(2026-09-26 用户指令)
 * 用户口径:
 *   入场 = 六重共振新鲜触发 AND 双线合一(≤24根窗) AND 布林触轨(≤24根窗, 下轨→做多/上轨→做空) [A2原口径]
 *   平仓 = 回归中轨(均值回归) | 100x爆仓线±0.6%照模拟 | 不设止损 | 超时7天兜底
 *   资金 = 每天1U新仓(每日最多开1笔1U) + 每天额外3U加仓额度(每天最多加3轮) 叠加每次1U加仓
 *   加仓 = 斐波那契0.618黄金回调 15m线(与实盘引擎 sigFibGolden 同口径: 近30根高低点, fib=hi-(hi-lo)*0.618,
 *          现价在fib±3%内; 多头=阳线确认, 空头=镜像阴线) + 顺向(多:现价>均价/空:现价<均价)
 *   仓位 = 总保证金封顶50U, 加仓不限轮数但每次仅1U
 * 输出: web/a2fib_data.json
 */
ini_set('memory_limit', '4096M');                                 // 提升 PHP 内存上限到 4G(一年15m数据+筹码峰开销大)
set_time_limit(0);                                                // 取消脚本执行时间限制
$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆100x; 维持保证金率0.4%; maker费0.02%; taker费0.05%; 8小时资金费率0.01%
$EQ0 = 500.0; $ADDU = 1.0; $CAPM = 50.0; $DAILY_ADD = 3; $TO_DAYS = 7;  // 初始权益500U; 每次加仓1U; 总保证金封顶50U; 每日加仓额度3轮; 超时7天
$W = 150; $NB = 60; $SEP_THR = 0.004; $SEP_HIST = 0.01; $SEP_LOOK = 24;  // 筹码峰: 滚动窗口150根/价格60格/合一价差阈值0.4%/分离阈值1%/回看24根
$P = 20; $K = 2.0; $W24 = 24; $FIB_N = 30; $FIB_TOL = 0.03;       // 布林参数(20周期,2σ); 状态窗24根; fib窗口30根; fib容差±3%
$INST = 'eth';                                                    // 回测品种: ETH

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库并设 utf8mb4
$DB = db();                                                       // 建立全局数据库连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行SQL收集全部结果行为数组
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }  // 滑动窗口均线, 前 n-1 个为 null
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }  // 毫秒时间戳转北京时间(UTC+8)字符串
function bjday($ms) { return gmdate('Y-m-d', (int)($ms / 1000) + 8 * 3600); }   // 毫秒时间戳转北京日期字符串
function winState($events, $n, $look) {                           // 事件序列转"近look根内是否有事件"状态窗
    $out = array_fill(0, $n, false); $cnt = 0;                    // 初始化输出与滑动计数
    for ($i = 0; $i < $n; $i++) {                                 // 逐根推进
        if (isset($events[$i])) $cnt++;                           // 当前根有事件计数+1
        if ($i - $look >= 0 && isset($events[$i - $look])) $cnt--;  // 滑出窗口的旧事件计数-1
        $out[$i] = $cnt > 0;                                      // 窗口内存在事件则为true
    }
    return $out;                                                  // 返回状态窗数组
}
function load_k($inst, $bar, $withVol = false) {                  // 加载K线, 可选附带成交额列
    $v = $withVol ? ',vol_quote' : '';                            // 需要时拼接 vol_quote 字段
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`{$v} FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按时间升序读 kline_<INST>_usdt_swap_<bar> 表(列名 o/h/l/c)
    $out = ['ts' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];  // 初始化输出结构
    foreach ($r as $x) { $out['ts'][] = (int)$x[0]; $out['o'][] = (float)$x[1]; $out['h'][] = (float)$x[2];  // 逐行转型: 时间/开/高
        $out['l'][] = (float)$x[3]; $out['c'][] = (float)$x[4]; if ($withVol) $out['v'][] = (float)$x[5]; }  // 低/收/可选成交额
    return $out;                                                  // 返回关联数组形式K线
}
function buyratio($o, $h, $l, $c) {                               // 计算逐根K线买方占比(0~1)
    $n = count($c); $br = [];                                     // 取根数, 初始化输出
    for ($i = 0; $i < $n; $i++) {                                 // 逐根遍历
        $rng = $h[$i] - $l[$i];                                   // 当根振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;           // 收盘在振幅中的相对位置(-1~1)
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;         // 钳制到 [-1,1]
        $br[$i] = 0.5 + $dir / 2;                                 // 映射到 0~1
    }
    return $br;                                                   // 返回买方占比序列
}

class Chip {                                                      // 筹码峰类: 滚动窗口成交量分布直方图
    public $hist = []; public $lo = null; public $hi = null; public $bw = 0.0;  // 公开: 直方图/价格区间下界/上界/每格宽度
    private $nb; private $ring = []; private $ri = 0; private $cnt = 0; private $cap;  // 私有: 格数/环形缓冲/写指针/已填充数/容量
    function __construct($nb, $cap) { $this->nb = $nb; $this->cap = $cap; $this->hist = array_fill(0, $nb, 0.0); }  // 构造: 指定格数与窗口容量, 直方图清零
    private function adds($l, $h, $v) {                           // 把一根K线的量按价格区间均摊到各格, 返回增量数组
        $nb = $this->nb; $a = array_fill(0, $nb, 0.0);            // 初始化增量数组
        if ($h <= $l || $v <= 0 || $this->bw <= 0) return $a;     // 无效数据直接返回空增量
        $bw = $this->bw;                                          // 当前格宽
        $i0 = (int)floor(($l - $this->lo) / $bw); $i1 = (int)floor(($h - $this->lo) / $bw);  // 计算高低价对应格下标
        $i0 = max(0, min($nb - 1, $i0)); $i1 = max(0, min($nb - 1, $i1));  // 钳制到有效格范围
        if ($i0 == $i1) { $a[$i0] = $v; return $a; }              // 同一格则全量入该格
        $per = $v / ($i1 - $i0 + 1);                              // 跨多格则均摊每格量
        for ($i = $i0; $i <= $i1; $i++) $a[$i] = $per;            // 填充各格增量
        return $a;                                                // 返回增量数组
    }
    private function rebuild() {                                  // 全量重建直方图(窗口价格范围变化时)
        $lo = INF; $hi = -INF;                                    // 初始化环形缓冲内最高/最低
        foreach ($this->ring as $b) { if ($b === null) continue; if ($b[0] < $lo) $lo = $b[0]; if ($b[1] > $hi) $hi = $b[1]; }  // 扫描求价格范围
        if ($hi <= $lo) $hi = $lo * 1.001 + 1e-9;                 // 防止零区间
        $this->lo = $lo; $this->hi = $hi; $this->bw = ($hi - $lo) / $this->nb;  // 更新区间与格宽
        $this->hist = array_fill(0, $this->nb, 0.0);              // 直方图清零
        foreach ($this->ring as $b) {                             // 重放窗口内每根K线
            if ($b === null) continue;                            // 空槽跳过
            $a = $this->adds($b[0], $b[1], $b[2]);                // 计算该根增量
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i];  // 累加到直方图
        }
    }
    function push($l, $h, $v) {                                   // 推入一根新K线并维护直方图
        if (!isset($this->ring[$this->ri])) $this->ring[$this->ri] = null;  // 确保槽位存在
        $this->ring[$this->ri] = [$l, $h, $v];                    // 新K线写入环形缓冲
        $this->ri = ($this->ri + 1) % $this->cap;                 // 写指针环形前进
        if ($this->cnt < $this->cap) { $this->cnt++; $this->rebuild(); return; }  // 未满时计数+1并全量重建
        $lo = INF; $hi = -INF;                                    // 已满: 计算当前窗口价格范围
        foreach ($this->ring as $b) { if ($b === null) continue; if ($b[0] < $lo) $lo = $b[0]; if ($b[1] > $hi) $hi = $b[1]; }  // 扫描范围
        $rng = $hi - $lo; if ($rng <= 0) $rng = $hi * 0.001 + 1e-9;  // 防零区间
        $need = ($this->bw <= 0) || ($l < $this->lo) || ($h > $this->hi)  // 判断是否需重建: 新K线越界
             || (abs($lo - $this->lo) > 0.005 * $rng) || (abs($hi - $this->hi) > 0.005 * $rng);  // 或范围漂移超0.5%
        if ($need) { $this->lo = $lo; $this->hi = $hi; $this->bw = $rng / $this->nb;  // 需要重建: 更新区间格宽
            $this->hist = array_fill(0, $this->nb, 0.0);          // 直方图清零
            foreach ($this->ring as $b) { if ($b === null) continue;  // 重放全部K线
                $a = $this->adds($b[0], $b[1], $b[2]);
                for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i]; }  // 累加重建
        } else {
            $a = $this->adds($l, $h, $v);                         // 无需重建: 仅增量累加新K线
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i];  // 累加
        }
    }
    function evictOldest() {                                      // 淘汰最旧一根K线(从直方图扣减)
        $old = $this->ring[$this->ri] ?? null;                    // 写指针处即最旧槽
        if ($old === null) { $this->ring[$this->ri] = null; $this->ri = ($this->ri + 1) % $this->cap; return; }  // 空槽仅推进指针
        if ($this->bw > 0) {                                      // 有有效格宽才扣减
            $a = $this->adds($old[0], $old[1], $old[2]);          // 计算该根原增量
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0 && $this->hist[$i] >= $a[$i]) $this->hist[$i] -= $a[$i];  // 从直方图扣减(防负)
        }
        $this->ring[$this->ri] = null;                            // 清空槽位
        $this->ri = ($this->ri + 1) % $this->cap;                 // 指针环形前进
    }
    function peaks() {                                            // 识别筹码峰, 返回量能前二的两个峰(按价格排序)
        $nb = $this->nb; $h = $this->hist;                        // 格数与直方图
        $tot = 0.0; for ($i = 0; $i < $nb; $i++) $tot += $h[$i];  // 直方图总量
        if ($tot <= 0) return null;                               // 无量返回null
        $avg = $tot / $nb;                                        // 每格均值
        $pk = [];                                                 // 候选峰列表
        for ($i = 1; $i < $nb - 1; $i++) {                        // 扫描局部极大值
            if ($h[$i] > $h[$i - 1] && $h[$i] >= $h[$i + 1] && $h[$i] > $avg * 1.3)  // 高于两侧且超均值1.3倍
                $pk[] = [$this->lo + ($i + 0.5) * $this->bw, $h[$i], $i];  // 记录[峰价, 峰量, 格下标]
        }
        if (count($pk) == 0) return null;                         // 无候选峰
        $mg = []; $cur = $pk[0];                                  // 相邻3格内峰合并
        for ($k = 1; $k < count($pk); $k++) {
            if ($pk[$k][2] - $cur[2] <= 3) {                      // 与当前峰间隔≤3格
                $tv = $cur[1] + $pk[$k][1];                       // 合并量
                $cur = [($cur[0] * $cur[1] + $pk[$k][0] * $pk[$k][1]) / $tv, $tv, $cur[2]];  // 量加权合并峰价
            } else { $mg[] = $cur; $cur = $pk[$k]; }              // 间隔大则收存另起新峰
        }
        $mg[] = $cur;                                             // 收存最后一个峰
        if (count($mg) < 2) return null;                          // 不足两个峰返回null
        usort($mg, function ($a, $b) { return $b[1] <=> $a[1]; });  // 按峰量降序
        $two = [$mg[0], $mg[1]];                                  // 取量能前二
        usort($two, function ($a, $b) { return $a[0] <=> $b[0]; });  // 再按价格升序(低峰/高峰)
        return [[$two[0][0], $two[0][1]], [$two[1][0], $two[1][1]]];  // 返回两个[峰价, 峰量]
    }
}

// ===== 全局① 全市场 1D MA20 宽度 =====
$up = []; $tot = [];                                              // 各日站上MA20品种数/有效品种总数
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");  // 读全市场日线收盘
$cur = null; $win = []; $s = 0.0;                                 // 当前品种/20日滑动窗口/窗口和
foreach ($rr as $x) {                                             // 逐行遍历
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; }    // 换品种重置窗口
    $win[] = (float)$x[2]; $s += (float)$x[2];                    // 收盘入窗口并累加
    if (count($win) > 20) $s -= array_shift($win);                // 超20个移除最旧
    if (count($win) == 20) {                                      // 满20日算MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2];       // 时间戳/MA20/收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0);          // 收盘≥MA20则强势数+1
        $tot[$t] = ($tot[$t] ?? 0) + 1;                           // 品种总数+1
    }
}
$brSrc = [];                                                      // 宽度源数据
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k;  // 品种数>10的日期算宽度
$brDays = array_keys($brSrc); sort($brDays);                      // 有效日期升序(供二分)
function ffillBr($t) {                                            // 前向填充: 取不晚于t的最近宽度
    global $brSrc, $brDays;                                       // 引用全局数据
    $lo = 0; $hi = count($brDays) - 1; $res = null;               // 二分初始化
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($brDays[$mid] <= $t) { $res = $brDays[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }  // 二分找<=t最大日期
    return $res !== null ? $brSrc[$res] : null;                   // 返回宽度或null
}
echo "breadth days=" . count($brSrc) . "\n"; flush();             // 输出有效宽度天数

// ===== 全局⑤ BTC 熔断 =====
$gB = load_k('btc', '1h');                                        // 加载 BTC 1小时K线
$tsB = $gB['ts']; $cB = $gB['c'];                                 // 时间戳与收盘
$meltSet = [];                                                    // 熔断时刻集合(禁入场)
for ($i = 4; $i < count($cB); $i++) {                             // 逐根判定4根(4小时)暴跌
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {  // 4小时内跌≥3%
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;  // 之后4个小时都标记熔断
    }
}
unset($rr, $gB, $tsB, $cB);                                       // 释放大数组内存

// ===== ETH 数据 =====
$gF = load_k($INST, '15m', true);                                 // 加载 ETH 15m K线(带成交额, 主网格)
$tsF = $gF['ts']; $oF = $gF['o']; $hF = $gF['h']; $lF = $gF['l']; $cF = $gF['c']; $vF = $gF['v'];  // 拆出各序列
$nF = count($tsF);                                                // 15m根数
echo "ETH 15m bars=$nF span=" . bj($tsF[0]) . " ~ " . bj($tsF[$nF - 1]) . "\n"; flush();  // 输出数据规模与区间
$g1 = load_k($INST, '1h'); $g4 = load_k($INST, '4h');             // 加载 ETH 1h/4h K线
$ts1 = $g1['ts']; $c1 = $g1['c']; $ts4 = $g4['ts']; $c4 = $g4['c'];  // 各取时间戳与收盘
$n1 = count($ts1); $n4 = count($ts4);                             // 1h/4h根数
$ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5);    // 1h MA20/MA10 与 4h MA5
$br1h = buyratio($g1['o'], $g1['h'], $g1['l'], $g1['c']);         // 1h 买方占比序列

// 六条件状态 + 新鲜信号
$sixL = false; $sixS = false; $sigL = []; $sigS = [];             // 上根多/空共振状态与新鲜信号表
$p1 = 0; $p4 = 0; $cur20 = null; $cur10 = null; $cur4 = null;     // 1h/4h对齐指针与当前趋势状态
for ($i = 0; $i < $nF; $i++) {                                    // 逐根15m判定
    $t = $tsF[$i];                                                // 当前时点
    while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; }  // 对齐最近1h: 更新>MA20/>MA10状态
    while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; }  // 对齐最近4h: 更新>MA5状态
    $hb = (int)floor($t / 3600000) * 3600000;                     // 所在小时整点(熔断对齐)
    $b = ffillBr($t);                                             // 该日市场宽度
    $nl = false; $ns = false;                                     // 本根多/空共振
    if ($b !== null && !isset($meltSet[$hb])) {                   // 有宽度且不在BTC熔断时段
        $lo = 0; $hi = $n1 - 1; $ib = -1;                         // 二分找最近已收盘1h下标
        while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }  // 标准二分
        if ($ib >= 0) {
            $br = $br1h[$ib];                                     // 该1h买方占比
            if ($b > 0.5 && $cur20 === true && $cur10 === true && $cur4 === true && $br >= 0.5) $nl = true;  // 多头六重共振: 宽度+三趋势+买比≥50%
            if ($b < 0.5 && $cur20 === false && $cur10 === false && $cur4 === false && $br < 0.5) $ns = true;  // 空头镜像共振
        }
    }
    if ($nl && !$sixL) $sigL[$i] = true;                          // 多头共振由无到有为"新鲜"信号
    if ($ns && !$sixS) $sigS[$i] = true;                          // 空头新鲜信号
    $sixL = $nl; $sixS = $ns;                                     // 更新状态供下根比较
}
$cntL = count($sigL); $cntS = count($sigS);                       // 多/空新鲜信号计数
unset($g1, $g4, $ts1, $ts4, $c1, $c4, $ma20, $ma10, $ma5_4, $br1h);  // 释放内存
echo "six fresh: L=$cntL S=$cntS\n"; flush();                     // 输出信号计数

// ===== 筹码峰 双线合一 =====
$chip = new Chip($NB, $W);                                        // 创建筹码峰对象(60格/150根窗)
for ($i = 0; $i < $W; $i++) if ($hF[$i] > 0 && $lF[$i] > 0) $chip->push($lF[$i], $hF[$i], $vF[$i]);  // 预填充前150根建立窗口
$sepPrev = array_fill(0, $SEP_LOOK, 0.0); $sepIdx = 0;            // 近24根双峰分离度环形历史与写指针
$mergedSig = []; $mergeCnt = 0;                                   // "双线合一"信号表与计数
for ($i = $W; $i < $nF - 1; $i++) {                               // 从窗口满后逐根滚动
    if ($hF[$i] > 0 && $lF[$i] > 0 && $vF[$i] > 0) {              // 有效K线才处理
        $chip->evictOldest();                                     // 淘汰最旧一根保持150根窗
        $chip->push($lF[$i], $hF[$i], $vF[$i]);                   // 推入新K线更新直方图
        $pk = $chip->peaks();                                     // 取量能前二筹码峰
        $sep = 0.0; $mg = false;                                  // 本根分离度与合一标志
        if ($pk !== null) {                                       // 有双峰才计算
            $pl = $pk[0][0]; $ph = $pk[1][0];                     // 低峰价/高峰价
            if ($pl > 0) {
                $sep = ($ph - $pl) / $pl;                         // 双峰分离度=(高-低)/低
                $midP = ($pl + $ph) / 2;                          // 双峰中点价
                $mg = ($sep <= $SEP_THR) && ($cF[$i] >= $midP * 0.99) && ($cF[$i] <= $midP * 1.01);  // 合一: 分离度≤0.4% 且现价在峰区±1%
                if ($mg) {
                    $everSep = false;                             // 检查近24根内是否曾分离≥1%(先分后合)
                    for ($k = 1; $k <= $SEP_LOOK; $k++) {
                        $idx = ((($sepIdx - $k) % $SEP_LOOK) + $SEP_LOOK) % $SEP_LOOK;  // 环形回看第k根下标
                        if ($sepPrev[$idx] >= $SEP_HIST) { $everSep = true; break; }  // 曾达1%分离则满足
                    }
                    if ($everSep) { $mergedSig[$i] = true; $mergeCnt++; }  // 先分后合成立记录信号
                }
            }
        }
        $sepPrev[$sepIdx] = $sep; $sepIdx = ($sepIdx + 1) % $SEP_LOOK;  // 记录本根分离度进环形历史
    }
}
unset($vF, $chip);                                                // 释放筹码峰内存
$mergeWin24 = winState($mergedSig, $nF, $W24);                    // 合一信号转近24根(6h)状态窗
echo "mergedSig=$mergeCnt\n"; flush();                            // 输出合一信号数

// ===== 布林(20,2σ) 中轨 + 触轨事件 + 24根窗 =====
$blMid = array_fill(0, $nF, null);                                // 布林中轨数组
$q = []; $s = 0.0; $s2 = 0.0;                                     // 滑动窗口/窗口和/窗口平方和
$bandL = []; $bandS = [];                                         // 触下轨/上轨事件表
for ($i = 0; $i < $nF; $i++) {                                    // 逐根滚动计算
    if ($cF[$i] > 0) {                                            // 有效收盘
        $q[] = $cF[$i]; $s += $cF[$i]; $s2 += $cF[$i] * $cF[$i];  // 收盘入窗并累加一次项/平方项
        if (count($q) > $P) { $old = array_shift($q); $s -= $old; $s2 -= $old * $old; }  // 超20个移除最旧
        if (count($q) == $P) {                                    // 满20根计算布林
            $m = $s / $P; $v = $s2 / $P - $m * $m; if ($v < 0) $v = 0; $sd = sqrt($v);  // 中轨=均值; 方差=平方均值-均值平方(防负); σ
            $blMid[$i] = $m;                                      // 记录中轨
            if ($cF[$i] <= $m - $K * $sd) $bandL[$i] = true;      // 收盘≤中轨-2σ记触下轨(做多口径)
            if ($cF[$i] >= $m + $K * $sd) $bandS[$i] = true;      // 收盘≥中轨+2σ记触上轨(做空口径)
        }
    }
}
$bandWin24L = winState($bandL, $nF, $W24);                        // 触下轨转近24根状态窗
$bandWin24S = winState($bandS, $nF, $W24);                        // 触上轨转近24根状态窗

// ===== 斐波那契0.618 (15m, 与实盘引擎 sigFibGolden 同口径) =====
// 多头: 近30根 lo/hi, fib=hi-(hi-lo)*0.618, 现价在fib±3%内 且 阳线; 空头: 镜像(阴线)
$fibL = []; $fibS = []; $fibLn = 0; $fibSn = 0;                   // 多/空fib信号表与计数
for ($i = $FIB_N - 1; $i < $nF; $i++) {                           // 从第30根起逐根判定
    $lo = INF; $hi = -INF;                                        // 窗口高低初始化
    for ($k = $i - $FIB_N + 1; $k <= $i; $k++) { if ($cF[$k] < $lo) $lo = $cF[$k]; if ($cF[$k] > $hi) $hi = $cF[$k]; }  // 求30根窗口最高/最低收盘
    if ($hi <= $lo || $lo <= 0) continue;                         // 无效窗口跳过
    $fib = $hi - ($hi - $lo) * 0.618;                             // 计算0.618回撤位
    $last = $cF[$i];                                              // 当根收盘
    if ($last >= $fib * (1 - $FIB_TOL) && $last <= $fib * (1 + $FIB_TOL)) {  // 收盘在fib±3%内
        if ($last > $oF[$i]) { $fibL[$i] = true; $fibLn++; }      // 阳线→多头加仓信号
        elseif ($last < $oF[$i]) { $fibS[$i] = true; $fibSn++; }  // 阴线→空头加仓信号
    }
}
echo "fib618: L=$fibLn S=$fibSn\n"; flush();                      // 输出fib信号计数

// ===== 模拟: A2入场 + fib618加仓 + 中轨平仓 =====
$liqDrop = 1.0 / $LEV - $MMR;                                     // 爆仓所需跌幅(0.6%)
$eq = $EQ0; $trades = []; $bankrupt = false;                      // 权益/交易列表/破产标志
$i = $W + 1;                                                      // 从窗口满后开始扫描
$openedToday = false; $addsToday = 0; $curDay = bjday($tsF[$i]);  // 今日已开仓/今日加仓轮数/当前北京日期
$entryBlocked = 0;                                                // 因每日限额被拦截的入场次数
while ($i < $nF - 1 && !$bankrupt) {                              // 主循环(未破产且数据未尽)
    $d = bjday($tsF[$i]);                                         // 当前北京日期
    if ($d !== $curDay) { $curDay = $d; $openedToday = false; $addsToday = 0; }  // 跨日重置当日额度
    $isL = isset($sigL[$i]); $isS = !$isL && isset($sigS[$i]);    // 本根多/空新鲜共振信号
    if ($isL || $isS) {                                           // 有共振信号还需筹码峰确认
        $mgOk = $mergeWin24[$i];                                  // 近24根内是否双线合一
        if (!$mgOk) { $isL = false; $isS = false; }               // 不合一则放弃信号
    }
    if (($isL || $isS)) {                                         // 有信号还需布林触轨确认
        if ($isL && !$bandWin24L[$i]) $isL = false;               // 多头需近24根内触过下轨
        if ($isS && !$bandWin24S[$i]) $isS = false;               // 空头需近24根内触过上轨
    }
    if (!$isL && !$isS) { $i++; continue; }                       // 最终无信号推进下一根
    if ($openedToday) { $entryBlocked++; $i++; continue; }   // 每天最多1笔1U新仓  // 今日已开仓则拦截并计数
    if ($oF[$i + 1] <= 0) { $i++; continue; }                     // 下一根开盘无效跳过
    $M0 = 1.0;                                                    // 新仓保证金固定1U
    if ($M0 > $eq) { if ($eq < 1) { $bankrupt = true; break; } }  // 权益不足1U且<1则破产
    $e = $oF[$i + 1];                                             // 入场价=下一根开盘
    $notional = $M0 * $LEV; $avg = $e; $adds = 0; $mg = $M0;      // 名义本金100U/均价/加仓次数/保证金
    $fee = $notional * $FEE_MAKER; $funding = 0.0;                // 入场maker手续费/资金费累加
    $liqPx = $isL ? $avg * (1 - $liqDrop) : $avg * (1 + $liqDrop);  // 爆仓价(多向下0.6%/空向上0.6%)
    $outcome = null; $exitPx = 0.0; $j = $i + 1;                  // 出场结果/出场价/持仓扫描下标
    $openedToday = true;                                          // 标记今日已开仓
    $walkDay = $curDay;                                           // 持仓期内当前日期(加仓额度按日)
    while ($j < $nF) {                                            // 逐根推进持仓
        $jd = bjday($tsF[$j]);                                    // 持仓根日期
        if ($jd !== $walkDay) { $walkDay = $jd; $addsToday = 0; } // 跨日重置加仓额度
        if ($hF[$j] <= 0 || $lF[$j] <= 0) { $j++; continue; }     // 无效K线跳过
        $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 32;   // 资金费: 名义×费率/32(15m每32根=8小时), 空头收反向
        $hitLiq = $isL ? ($lF[$j] <= $liqPx) : ($hF[$j] >= $liqPx);  // 判爆仓(多头看最低/空头看最高)
        if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 爆仓出场
        if ($blMid[$j] !== null && ($isL ? ($cF[$j] >= $blMid[$j]) : ($cF[$j] <= $blMid[$j]))) { $outcome = 'XREV'; $exitPx = $cF[$j]; break; }  // 收盘回归中轨→均值回归平仓
        if ($j - $i >= $TO_DAYS * 24 * 4) { $outcome = 'TO'; $exitPx = $cF[$j]; break; }  // 持仓超7天(672根15m)超时平仓
        // 加仓: fib618(15m)同向信号 + 顺向 + 每日加仓额度3U + 仓位保证金≤50U
        $fibOk = $isL ? (isset($fibL[$j]) && $cF[$j] > $avg) : (isset($fibS[$j]) && $cF[$j] < $avg);  // fib信号且顺向(多头价>均价/空头价<均价)
        if ($fibOk && $addsToday < $DAILY_ADD && $mg + $ADDU <= $CAPM && $j + 1 < $nF) {  // 满足每日3轮/总保证金50U上限
            $ap = $oF[$j + 1];                                    // 加仓价=下一根开盘
            if ($ap > 0) {
                $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);  // 加权更新均价
                $notional += $ADDU * $LEV; $adds++; $mg += $ADDU; $addsToday++;  // 扩名义/计次数/加保证金/占当日额度
                $fee += $ADDU * $LEV * $FEE_TAKER;                // 加仓taker手续费
                $liqPx = $isL ? $avg * (1 - $liqDrop) : $avg * (1 + $liqDrop);  // 重算爆仓价
            }
        }
        $j++;                                                     // 推进下一根
    }
    if ($outcome === null) { $outcome = 'TO'; $j = $nF - 1; $exitPx = $cF[$j]; }  // 数据耗尽按末根收盘了结
    $fee += $notional * $FEE_TAKER;                               // 出场taker手续费
    $gross = $isL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);  // 毛盈亏(空头方向相反)
    $pnl = $gross - $fee - $funding;                              // 净盈亏=毛-手续费-资金费
    if ($pnl < -$mg) $pnl = -$mg;                                 // 亏损封底为本笔总保证金
    if ($pnl < -$eq) { $pnl = -$eq; $bankrupt = true; }           // 亏损超账户权益则权益清零并破产
    $eq += $pnl;                                                  // 更新账户权益
    if ($eq <= 0.01) $bankrupt = true;                            // 权益近零判破产
    $trades[] = ['side' => $isL ? 'LONG' : 'SHORT', 'tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome,  // 记录一笔交易明细
                 'adds' => $adds, 'mg' => round($mg, 2), 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),  // 方向/时间/结果/加仓/保证金/盈亏/权益
                 'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];  // 持仓小时数
    $i = $j + 1;                                                  // 出场后继续扫描
}

// ===== 汇总 =====
$tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'xrev' => 0, 'adds' => 0,  // 汇总容器初始化
        'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0];  // 总盈亏/多空分项
$holdSum = 0.0; $mgSum = 0.0;                                     // 持仓时长与保证金累加
$daily = []; $months = [];                                        // 按日/按月盈亏
foreach ($trades as $x) {                                         // 逐笔统计
    if ($x['out'] == 'LIQ') $tot['liq']++;                        // 爆仓计数
    if ($x['out'] == 'TO') $tot['to']++;                          // 超时计数
    if ($x['out'] == 'XREV') $tot['xrev']++;                      // 中轨平仓计数
    if ($x['pnl'] > 0) $tot['win']++;                             // 盈利计数
    $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl']; $holdSum += $x['holdh']; $mgSum += $x['mg'];  // 累加加仓/盈亏/时长/保证金
    if ($x['side'] == 'LONG') { $tot['l_n']++; $tot['l_pnl'] += $x['pnl']; }  // 多头分项
    else { $tot['s_n']++; $tot['s_pnl'] += $x['pnl']; }           // 空头分项
    $dd = bjday($x['tout']);                                      // 出场北京日期
    $daily[$dd] = round(($daily[$dd] ?? 0) + $x['pnl'], 2);       // 当日盈亏
    $m = substr($dd, 0, 7);                                       // 月份
    $months[$m] = round(($months[$m] ?? 0) + $x['pnl'], 2);       // 当月盈亏
}
ksort($daily); ksort($months);                                    // 按时间升序
$tot['pnl'] = round($tot['pnl'], 2); $tot['l_pnl'] = round($tot['l_pnl'], 2); $tot['s_pnl'] = round($tot['s_pnl'], 2);  // 盈亏保留2位
$tot['hold_avg'] = $tot['n'] ? round($holdSum / $tot['n'], 1) : 0;  // 平均持仓小时
$tot['mg_avg'] = $tot['n'] ? round($mgSum / $tot['n'], 2) : 0;    // 平均每笔保证金
$peak = -INF; $maxdd = 0;                                         // 权益峰值与最大回撤
foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd2 = $peak - $x['eq']; if ($dd2 > $maxdd) $maxdd = $dd2; }  // 逐笔更新峰值与回撤
// 日级权益曲线(每日收盘后权益 = 前值 + 当日已实现)
$eqCurve = []; $run = $EQ0; $ti = 0; $tn = count($trades);        // 初始化曲线/累计权益/计数
$dayList = array_keys($daily);                                    // 有交易日期列表
$eqByDay = [];                                                    // 按日权益映射
$perDay = [];                                                     // 每日已实现盈亏
foreach ($trades as $x) { $dd = bjday($x['tout']); $perDay[$dd] = round(($perDay[$dd] ?? 0) + $x['pnl'], 2); }  // 按出场日汇总盈亏
foreach ($perDay as $dd => $p) { $run = round($run + $p, 2); $eqByDay[$dd] = $run; }  // 逐日累加得权益曲线
echo sprintf("[A2FIB] n=%d win=%d(%.1f%%) liq=%d to=%d xrev=%d adds=%d pnl=%.2f (L=%.1f S=%.1f) eqEnd=%.2f maxdd=%.2f entryBlocked=%d bankrupt=%s\n",  // 输出汇总一行
    $tot['n'], $tot['win'], $tot['n'] ? $tot['win'] / $tot['n'] * 100 : 0, $tot['liq'], $tot['to'], $tot['xrev'],  // 笔数/胜率/爆仓/超时/中轨平仓
    $tot['adds'], $tot['pnl'], $tot['l_pnl'], $tot['s_pnl'], $eq, $maxdd, $entryBlocked, $bankrupt ? 'YES' : 'no');  // 加仓/盈亏/多空分项/期末权益/回撤/拦截/破产

$out = [                                                          // 组装输出JSON
    'meta' => [
        'inst' => $INST, 'lev' => $LEV, 'eq0' => $EQ0, 'capm' => $CAPM, 'daily_add' => $DAILY_ADD, 'to_days' => $TO_DAYS,  // 基础参数
        'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF, 'bankrupt' => $bankrupt, 'entry_blocked' => $entryBlocked, 'maxdd' => round($maxdd, 2),  // 区间/根数/破产/拦截/回撤
        'params' => 'ETH单合约 15m 一年 100x | 入场=A2口径: 六重共振新鲜触发(全市场1D MA20宽度>50%/1h>MA20/1h>MA10/4h>MA5/taker买比≥50%/BTC熔断禁入, 空头全反向) AND 筹码峰双线合一(滚动150根/60格vol_quote直方图·峰>1.3×均值·相邻3格合并·量能前2名; 价差≤0.4% 且此前24根内曾分离≥1% 先分后合·现价峰区±1%)≤24根(6h)状态窗 AND 布林(20,2σ)触轨(下轨→多/上轨→空)≤24根状态窗 | 平仓=回归中轨(均值回归)·100x爆仓线±0.6%照模拟·不设止损·超时7天兜底 | 资金: 每天1U新仓(每日最多开1笔) + 每天额外3U加仓额度 + 每次加仓1U | 加仓=斐波那契0.618黄金回调15m(与实盘引擎sigFibGolden同口径: 近30根高低点 fib=hi-(hi-lo)*0.618 现价±3%内; 多头阳线/空头镜像阴线) + 顺向 | 仓位总保证金封顶50U 加仓不限轮数 | 净口径含手续费(maker进/taker出+加仓)+资金费',  // 完整策略口径文字说明
    ],
    'total' => $tot,                                              // 汇总统计
    'daily' => $daily, 'months' => $months, 'eq_curve' => $eqByDay,  // 逐日/逐月盈亏与权益曲线
    'counts' => ['sigL' => $cntL, 'sigS' => $cntS, 'merge' => $mergeCnt, 'fibL' => $fibLn, 'fibS' => $fibSn],  // 各类信号计数
    'trades' => $trades,                                          // 逐笔明细
];
file_put_contents('E:/finally-main/web/a2fib_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出JSON供前端展示
echo "DATA OK\n";                                                 // 完成提示
