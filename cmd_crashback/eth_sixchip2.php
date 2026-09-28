<?php
/**
 * eth_sixchip2.php — ETH 单合约 六重共振×筹码峰双线合一×布林轨道共振 一年回测(2026-09-26 用户指令)
 * 用户: "六重共振((上轨做空/下轨做多, 赚均值回归)或改固定小止盈(+0.3%)) 用这个条件共振
 *        + 六重共振(双线合一, 同步筹码峰: 滚动150根K线/成交额摊60格直方图/峰>1.3×均值/相邻3格合并/量能前2名
 *        → 高价峰=拉升峰/低价峰=洗盘峰/最早期主峰=建仓峰; 买入=双线合一: 两峰价差≤0.4%(此前24根内曾分离≥1%,先分后合)
 *        且现价在峰区±1%内 → 下一根开盘买)" + 修正: "只单独算ETH"
 * 三组对照(同一数据/信号, 仅入场过滤与平仓不同):
 *   A/B组入场 = 六重共振新鲜触发 AND 双线合一 AND 布林(20,2σ)下轨触→做多/上轨触→做空
 *     [严格同刻 AND 实测一年仅1笔 → 按 chippeak4 先例改"状态+容差窗": 触轨≤6根(1.5h)/24根(6h)内, 合一≤8根(2h)/24根(6h)内]
 *   A 均值回归: 平仓=回归中轨 | B 固定止盈: 平仓=+0.3%价格(maker) | C 对照=sixchipall原口径(同刻合一·无轨道·±2%)
 *   加仓=同方向六重共振再触发+顺向+1U不限轮 | 每笔固定1U | 100x爆仓线照模拟 | 超时7天 | 净口径含手续费+资金费
 * 输出: web/sixchip2_data.json
 */
ini_set('memory_limit', '4096M');                 // 内存上限 4G
set_time_limit(0);                                // 取消执行时间限制
$LEV = 100; $TPR = 0.02; $TP03 = 0.003; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆/止盈2%/小止盈0.3%/维持保证金率/费率/资金费率
$EQ0 = 500.0; $ADDU = 1.0;                        // 起始权益500U/每轮加仓1U
$W = 150; $NB = 60; $SEP_THR = 0.004; $SEP_HIST = 0.01; $SEP_LOOK = 24;  // 筹码峰参数: 滚动150根/60格/合一价差阈值0.4%/分离阈值1%/回看24根
$INST = 'eth';                                    // 目标合约 = ETH(用户指令只单独算ETH)
$P = 20; $K = 2.0; // 布林(20,2σ) @15m   // 布林带参数: 20周期/2倍标准差

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库
$DB = db();                                       // 建立全局连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行 SQL 返回全部行
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }  // 滑动均线 MA
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }  // 毫秒时间戳转北京时间字符串

function load_k($inst, $bar, $withVol = false) {  // 读取 K线表, 可选附带 vol_quote 成交额列
    $v = $withVol ? ',vol_quote' : '';            // 需要成交量时拼接列名
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`{$v} FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按 candle_time 升序
    $out = ['ts' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];  // 关联数组存放各列
    foreach ($r as $x) { $out['ts'][] = (int)$x[0]; $out['o'][] = (float)$x[1]; $out['h'][] = (float)$x[2];  // 类型转换装入
        $out['l'][] = (float)$x[3]; $out['c'][] = (float)$x[4]; if ($withVol) $out['v'][] = (float)$x[5]; }  // 高/低/收与可选成交额
    return $out;                                  // 返回字典
}
function buyratio($o, $h, $l, $c) {               // taker买比代理: 实体位置映射 0~1
    $n = count($c); $br = [];                     // 结果数组
    for ($i = 0; $i < $n; $i++) {                 // 逐根计算
        $rng = $h[$i] - $l[$i];                   // 振幅
        $dir = $rng > 0 ? ($c[$i] - $o[$i]) / $rng : 0;  // 实体方向比例
        if ($dir > 1) $dir = 1; if ($dir < -1) $dir = -1;  // 限幅
        $br[$i] = 0.5 + $dir / 2;                 // 映射到 0~1
    }
    return $br;                                   // 返回买比序列
}

/** 增量筹码分布器(与eth_chippeak/eth_sixchipall同参) */
class Chip {                                      // 筹码分布类: 滚动窗口内的价格-成交量直方图
    public $hist = []; public $lo = null; public $hi = null; public $bw = 0.0;  // 直方图/价格下界/上界/格宽
    private $nb; private $ring = []; private $ri = 0; private $cnt = 0; private $cap;  // 格数/环形缓冲/写入位/已填充数/容量
    function __construct($nb, $cap) { $this->nb = $nb; $this->cap = $cap; $this->hist = array_fill(0, $nb, 0.0); }  // 构造: 指定格数与窗口容量
    private function adds($l, $h, $v) {           // 把一根K线的成交量均匀摊到其价格覆盖的格子上
        $nb = $this->nb; $a = array_fill(0, $nb, 0.0);  // 单根贡献数组
        if ($h <= $l || $v <= 0 || $this->bw <= 0) return $a;  // 无效输入返回空
        $bw = $this->bw;                          // 当前格宽
        $i0 = (int)floor(($l - $this->lo) / $bw); $i1 = (int)floor(($h - $this->lo) / $bw);  // 计算起止格下标
        $i0 = max(0, min($nb - 1, $i0)); $i1 = max(0, min($nb - 1, $i1));  // 夹到合法范围
        if ($i0 == $i1) { $a[$i0] = $v; return $a; }  // 落在单格则全额
        $per = $v / ($i1 - $i0 + 1);              // 跨多格则均摊
        for ($i = $i0; $i <= $i1; $i++) $a[$i] = $per;  // 逐格填均摊量
        return $a;                                // 返回贡献
    }
    private function rebuild() {                  // 全量重建直方图(窗口未满或边界大变时)
        $lo = INF; $hi = -INF;                    // 窗口价格范围
        foreach ($this->ring as $b) { if ($b === null) continue; if ($b[0] < $lo) $lo = $b[0]; if ($b[1] > $hi) $hi = $b[1]; }  // 求窗口最高最低
        if ($hi <= $lo) $hi = $lo * 1.001 + 1e-9; // 防止范围为零
        $this->lo = $lo; $this->hi = $hi; $this->bw = ($hi - $lo) / $this->nb;  // 更新边界与格宽
        $this->hist = array_fill(0, $this->nb, 0.0);  // 清空直方图
        foreach ($this->ring as $b) {             // 把窗口内每根K线重新摊入
            if ($b === null) continue;            // 跳过空槽
            $a = $this->adds($b[0], $b[1], $b[2]);  // 计算该根贡献
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i];  // 累加到直方图
        }
    }
    function push($l, $h, $v) {                   // 推入一根K线(增量更新)
        $this->ring[$this->ri] = [$l, $h, $v];    // 写入环形缓冲
        $this->ri = ($this->ri + 1) % $this->cap; // 写入位前移
        if ($this->cnt < $this->cap) { $this->cnt++; $this->rebuild(); return; }  // 窗口未满 → 全量重建
        $lo = INF; $hi = -INF;                    // 已满 → 检查新根是否大幅改变边界
        foreach ($this->ring as $b) { if ($b === null) continue; if ($b[0] < $lo) $lo = $b[0]; if ($b[1] > $hi) $hi = $b[1]; }  // 求窗口边界
        $rng = $hi - $lo; if ($rng <= 0) $rng = $hi * 0.001 + 1e-9;  // 防零范围
        $need = ($this->bw <= 0) || ($l < $this->lo) || ($h > $this->hi)  // 越界须重建
             || (abs($lo - $this->lo) > 0.005 * $rng) || (abs($hi - $this->hi) > 0.005 * $rng);  // 边界偏移>0.5%幅度也重建
        if ($need) { $this->lo = $lo; $this->hi = $hi; $this->bw = $rng / $this->nb;  // 重建: 更新边界格宽
            $this->hist = array_fill(0, $this->nb, 0.0);  // 清空直方图
            foreach ($this->ring as $b) { if ($b === null) continue;  // 重新摊入全部K线
                $a = $this->adds($b[0], $b[1], $b[2]);
                for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i]; }
        } else {                                  // 边界基本不变 → 纯增量累加新根
            $a = $this->adds($l, $h, $v);
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i];
        }
    }
    function evictOldest() {                      // 从窗口移除最旧一根(等价滑动窗口淘汰)
        $old = $this->ring[$this->ri];            // ri 指向的最旧槽位
        if ($old === null) return;                // 空槽直接返回
        if ($this->bw > 0) {                      // 直方图有效则做减法增量
            $a = $this->adds($old[0], $old[1], $old[2]);  // 该旧根的贡献
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0 && $this->hist[$i] >= $a[$i]) $this->hist[$i] -= $a[$i];  // 从直方图扣除
        }
        $this->ring[$this->ri] = null;            // 清空槽位
        $this->ri = ($this->ri + 1) % $this->cap; // 写入位前移
    }
    /** 返回 [ [低价峰,量], [高价峰,量] ] 或 null */
    function peaks() {                            // 提取量能前两名的筹码峰
        $nb = $this->nb; $h = $this->hist;        // 格数/直方图
        $tot = 0.0; for ($i = 0; $i < $nb; $i++) $tot += $h[$i];  // 总量
        if ($tot <= 0) return null;               // 无数据
        $avg = $tot / $nb;                        // 每格均值
        $pk = [];                                 // 候选峰列表
        for ($i = 1; $i < $nb - 1; $i++) {        // 找局部极大且>1.3×均值
            if ($h[$i] > $h[$i - 1] && $h[$i] >= $h[$i + 1] && $h[$i] > $avg * 1.3)
                $pk[] = [$this->lo + ($i + 0.5) * $this->bw, $h[$i], $i];  // [峰价格(格中心), 峰量, 格下标]
        }
        if (count($pk) == 0) return null;         // 无候选峰
        $mg = []; $cur = $pk[0];                  // 相邻3格内合并
        for ($k = 1; $k < count($pk); $k++) {     // 遍历候选峰
            if ($pk[$k][2] - $cur[2] <= 3) {      // 与当前峰相距≤3格 → 合并
                $tv = $cur[1] + $pk[$k][1];       // 合并总量
                $cur = [($cur[0] * $cur[1] + $pk[$k][0] * $pk[$k][1]) / $tv, $tv, $cur[2]];  // 量加权均价合并
            } else { $mg[] = $cur; $cur = $pk[$k]; }  // 不相邻则另起新峰
        }
        $mg[] = $cur;                             // 收入最后一个峰
        if (count($mg) < 2) return null;          // 不足双峰返回 null
        usort($mg, function ($a, $b) { return $b[1] <=> $a[1]; });  // 按峰量降序
        $two = [$mg[0], $mg[1]];                  // 取量能前2名
        usort($two, function ($a, $b) { return $a[0] <=> $b[0]; });  // 再按价格升序(低价峰在前)
        return [[$two[0][0], $two[0][1]], [$two[1][0], $two[1][1]]];  // 返回 [低价峰, 高价峰]
    }
}

// ===== 全局① 全市场 1D MA20 宽度 =====
$up = []; $tot = [];                              // 每日站上MA20币数 / 样本币数
$rr = rows("SELECT inst_id, candle_time, c FROM kline1d ORDER BY inst_id, candle_time");  // 读全市场日线收盘
$cur = null; $win = []; $s = 0.0;                 // 当前合约/滑窗/窗口和
foreach ($rr as $x) {                             // 逐行扫描
    if ($x[0] !== $cur) { $cur = $x[0]; $win = []; $s = 0.0; }  // 换币重置
    $win[] = (float)$x[2]; $s += (float)$x[2];    // 收盘入窗
    if (count($win) > 20) $s -= array_shift($win);  // 窗口超20移除最旧
    if (count($win) == 20) {                      // 满20根算 MA20
        $t = (int)$x[1]; $ma = $s / 20; $cc = (float)$x[2];  // 时间戳/MA20/收盘
        $up[$t] = ($up[$t] ?? 0) + ($cc >= $ma ? 1 : 0);  // 站上MA20计数
        $tot[$t] = ($tot[$t] ?? 0) + 1;           // 样本计数
    }
}
$brSrc = [];                                      // 宽度源数据
foreach ($tot as $t => $k) if ($k > 10) $brSrc[$t] = $up[$t] / $k;  // 样本>10才计算
$brDays = array_keys($brSrc); sort($brDays);      // 时间戳升序供二分
function ffillBr($t) {                            // 前向填充: 二分找 ≤t 的最近宽度
    global $brSrc, $brDays;                       // 引用全局数据
    $lo = 0; $hi = count($brDays) - 1; $res = null;  // 二分上下界/结果
    while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($brDays[$mid] <= $t) { $res = $brDays[$mid]; $lo = $mid + 1; } else $hi = $mid - 1; }  // 标准二分
    return $res !== null ? $brSrc[$res] : null;   // 返回宽度或 null
}
echo "breadth days=" . count($brSrc) . "\n"; flush();  // 输出宽度覆盖天数

// ===== 全局⑤ BTC 熔断 =====
$gB = load_k('btc', '1h');                        // 读 BTC 1h K线
$tsB = $gB['ts']; $cB = $gB['c'];                 // 时间戳/收盘
$meltSet = [];                                    // 熔断小时集合
for ($i = 4; $i < count($cB); $i++) {             // 扫描4根累计跌幅
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {  // 4根累计≤-3%触发
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;  // 触发后4小时禁入
    }
}
unset($rr, $gB, $tsB, $cB);                       // 释放内存

// ===== ETH 数据 =====
$inst = $INST;                                    // 目标合约
$gF = load_k($inst, '15m', true);                 // 读 ETH 15m(含成交额)
$tsF = $gF['ts']; $oF = $gF['o']; $hF = $gF['h']; $lF = $gF['l']; $cF = $gF['c']; $vF = $gF['v'];  // 拆出各列
$nF = count($tsF);                                // 15m 根数
echo "ETH 15m bars=$nF span=" . bj($tsF[0]) . " ~ " . bj($tsF[$nF - 1]) . "\n"; flush();  // 输出根数与跨度
$g1 = load_k($inst, '1h'); $g4 = load_k($inst, '4h');  // 读 1h/4h
$ts1 = $g1['ts']; $c1 = $g1['c']; $ts4 = $g4['ts']; $c4 = $g4['c'];  // 取时间戳/收盘
$n1 = count($ts1); $n4 = count($ts4);             // 1h/4h 根数
$ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5);  // 1h MA20/MA10 与 4h MA5
$br1h = buyratio($g1['o'], $g1['h'], $g1['l'], $g1['c']);  // 1h taker买比序列

// 六条件状态 + 新鲜信号
$sixL = false; $sixS = false; $sigL = []; $sigS = [];  // 上根多/空六条件状态与新鲜信号
$p1 = 0; $p4 = 0; $cur20 = null; $cur10 = null; $cur4 = null;  // 游标与条件状态
for ($i = 0; $i < $nF; $i++) {                    // 逐根15m扫描
    $t = $tsF[$i];                                // 当前时间戳
    while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; }  // 前向推进: ②③条件
    while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; }  // 前向推进: ④条件
    $hb = (int)floor($t / 3600000) * 3600000;     // 对齐整点小时
    $b = ffillBr($t);                             // ① 全市场宽度
    $nl = false; $ns = false;                     // 本根多/空是否全命中
    if ($b !== null && !isset($meltSet[$hb])) {   // ①有效 且 ⑤非熔断
        $lo = 0; $hi = $n1 - 1; $ib = -1;         // 二分找对应1h下标
        while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }  // 标准二分
        if ($ib >= 0) {                           // 找到对应1h
            $br = $br1h[$ib];                     // ⑥ 该1h的taker买比
            if ($b > 0.5 && $cur20 === true && $cur10 === true && $cur4 === true && $br >= 0.5) $nl = true;  // 多头六条件全命中
            if ($b < 0.5 && $cur20 === false && $cur10 === false && $cur4 === false && $br < 0.5) $ns = true;  // 空头六条件全反向
        }
    }
    if ($nl && !$sixL) $sigL[$i] = true;          // 多头 false→true 新鲜触发
    if ($ns && !$sixS) $sigS[$i] = true;          // 空头新鲜触发
    $sixL = $nl; $sixS = $ns;                     // 滚动更新状态
}
$cntL = count($sigL); $cntS = count($sigS);       // 多/空新鲜信号数
unset($g1, $g4, $ts1, $ts4, $c1, $c4, $ma20, $ma10, $ma5_4, $br1h);  // 释放内存
echo "six fresh: L=$cntL S=$cntS\n"; flush();     // 输出信号数

// ===== 筹码峰 双线合一(逐15m K线) =====
$chip = new Chip($NB, $W);                        // 创建筹码分布器(60格/150根窗口)
for ($i = 0; $i < $W; $i++) if ($hF[$i] > 0 && $lF[$i] > 0) $chip->push($lF[$i], $hF[$i], $vF[$i]);  // 预填前150根
$sepPrev = array_fill(0, $SEP_LOOK, 0.0); $sepIdx = 0;  // 近24根的分离度环形记录/写入位
$mergedSig = []; $mergeCnt = 0;                   // 双线合一信号集合/计数
for ($i = $W; $i < $nF - 1; $i++) {               // 从窗口满后逐根扫描
    if ($hF[$i] > 0 && $lF[$i] > 0 && $vF[$i] > 0) {  // K线有效
        $chip->evictOldest();                     // 滑出最旧一根
        $chip->push($lF[$i], $hF[$i], $vF[$i]);   // 推入当前根
        $pk = $chip->peaks();                     // 取双峰
        $sep = 0.0; $mg = false;                  // 分离度/合一标志
        if ($pk !== null) {                       // 有双峰才计算
            $pl = $pk[0][0]; $ph = $pk[1][0];     // 低价峰/高价峰价格
            if ($pl > 0) {                        // 低价峰有效
                $sep = ($ph - $pl) / $pl;         // 两峰分离度
                $midP = ($pl + $ph) / 2;          // 峰区中点
                $mg = ($sep <= $SEP_THR) && ($cF[$i] >= $midP * 0.99) && ($cF[$i] <= $midP * 1.01);  // 合一: 价差≤0.4% 且现价在峰区±1%
                if ($mg) {                        // 满足合一 → 检查此前是否曾分离
                    $everSep = false;             // 是否曾分离≥1%
                    for ($k = 1; $k <= $SEP_LOOK; $k++) {  // 回看24根
                        $idx = ((($sepIdx - $k) % $SEP_LOOK) + $SEP_LOOK) % $SEP_LOOK;  // 环形取历史分离度
                        if ($sepPrev[$idx] >= $SEP_HIST) { $everSep = true; break; }  // 曾达1%即"先分后合"
                    }
                    if ($everSep) { $mergedSig[$i] = true; $mergeCnt++; }  // 记为双线合一信号
                }
            }
        }
        $sepPrev[$sepIdx] = $sep; $sepIdx = ($sepIdx + 1) % $SEP_LOOK;  // 记录本根分离度并前移
    }
}
unset($vF, $chip);                                // 释放内存
echo "mergedSig=$mergeCnt\n"; flush();            // 输出合一信号数

// ===== 布林(20,2σ) @15m =====
$blMid = array_fill(0, $nF, null); $blUp = array_fill(0, $nF, null); $blLo = array_fill(0, $nF, null);  // 中轨/上轨/下轨
$q = []; $s = 0.0; $s2 = 0.0;                     // 滑窗/一阶和/二阶和
for ($i = 0; $i < $nF; $i++) {                    // 逐根计算
    if ($cF[$i] <= 0) continue;                   // 无效价跳过
    $q[] = $cF[$i]; $s += $cF[$i]; $s2 += $cF[$i] * $cF[$i];  // 收盘入窗
    if (count($q) > $P) { $old = array_shift($q); $s -= $old; $s2 -= $old * $old; }  // 窗口超长移除最旧
    if (count($q) == $P) {                        // 窗口满20算布林
        $m = $s / $P; $v = $s2 / $P - $m * $m; if ($v < 0) $v = 0; $sd = sqrt($v);  // 均值/方差(防负)/标准差
        $blMid[$i] = $m; $blUp[$i] = $m + $K * $sd; $blLo[$i] = $m - $K * $sd;  // 中轨=MA20 / 上轨=+2σ / 下轨=-2σ
    }
}
// 轨道共振(状态+容差窗): 信号根收盘触下轨→做多 / 触上轨→做空 (同刻 AND 实测一年仅1笔 → 改容差窗)
$bandL = []; $bandS = [];                         // 触下轨/触上轨事件
$bandLn = 0; $bandSn = 0;                         // 事件计数
for ($i = 0; $i < $nF; $i++) {                    // 逐根判定
    if ($blLo[$i] !== null && $cF[$i] <= $blLo[$i]) { $bandL[$i] = true; $bandLn++; }  // 收盘触下轨
    if ($blUp[$i] !== null && $cF[$i] >= $blUp[$i]) { $bandS[$i] = true; $bandSn++; }  // 收盘触上轨
}
/** 窗口状态: 数组事件在过去 $look 根内(含当前)出现过 → true */
function winState($events, $n, $look) {           // 把稀疏事件转成容差窗内的连续状态
    $out = array_fill(0, $n, false); $cnt = 0;    // 输出/滑动窗口内事件计数
    for ($i = 0; $i < $n; $i++) {                 // 逐根推进
        if (isset($events[$i])) $cnt++;           // 当前根有事件计数+1
        if ($i - $look >= 0 && isset($events[$i - $look])) $cnt--;  // 滑出窗口的事件计数-1
        $out[$i] = $cnt > 0;                      // 窗口内有过事件即为 true
    }
    return $out;                                  // 返回状态序列
}
echo "band touch: lower=$bandLn upper=$bandSn\n"; flush();  // 输出触轨次数

// ===== 模拟(多组) =====
$liqDrop = 1.0 / $LEV - $MMR;                     // 简化爆仓距离 ≈ 0.6%
/** $mode: MID=回归中轨 | TP03=固定止盈+0.3%(maker) | TP2=止盈±2%(taker)
 *  $bandLook/$mergeLook: 轨道触碰/双线合一 容差窗根数(0=关, merge同刻用原mergedSig) */
function sim($mode, $bandLook, $mergeLook, $tsF, $oF, $hF, $lF, $cF, $blMid, $bandWinL, $bandWinS, $mergeWin, $mergedSig, $sigL, $sigS, $nF) {  // 多组模拟核心
    global $LEV, $TPR, $TP03, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $EQ0, $ADDU, $liqDrop;  // 引用全局参数
    $eq = $EQ0; $trades = []; $i = 0;             // 权益/成交/游标
    while ($i < $nF - 1) {                        // 逐根扫描
        $isL = isset($sigL[$i]); $isS = !$isL && isset($sigS[$i]);  // 六重共振信号方向(多头优先)
        if ($isL || $isS) { // 双线合一: 同刻 或 容差窗状态
            $mgOk = ($mergeLook == 0) ? isset($mergedSig[$i]) : $mergeWin[$i];  // mergeLook=0 时要求同刻合一, 否则用容差窗状态
            if (!$mgOk) { $isL = false; $isS = false; }  // 不满足合一则放弃信号
        }
        if (($isL || $isS) && $bandLook > 0) { // 轨道共振容差窗
            if ($isL && !$bandWinL[$i]) $isL = false;  // 多头须在触下轨容差窗内
            if ($isS && !$bandWinS[$i]) $isS = false;  // 空头须在触上轨容差窗内
        }
        if (!$isL && !$isS) { $i++; continue; }   // 过滤后无信号跳过
        if ($oF[$i + 1] <= 0) { $i++; continue; } // 脏数据跳过
        $M0 = 1.0;                                // 每笔固定1U保证金
        $e = $oF[$i + 1];                         // 下一根开盘入场
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;  // 名义/均价/加仓计数
        $fee = $notional * $FEE_MAKER; $funding = 0.0;  // 开仓maker费/资金费累计
        if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + ($mode == 'TP03' ? $TP03 : $TPR)); }  // 多头爆仓价/止盈价(按模式选0.3%或2%)
        else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - ($mode == 'TP03' ? $TP03 : $TPR)); }  // 空头爆仓价/止盈价
        $outcome = null; $exitPx = 0.0; $exitMaker = false; $j = $i + 1;  // 结局/出场价/出场是否maker/游标
        while ($j < $nF) {                        // 持仓推进
            if ($hF[$j] <= 0 || $lF[$j] <= 0) { $j++; continue; }  // 脏数据跳过
            $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 32;  // 每15m计提资金费(多付/空收)
            $hitLiq = $isL ? ($lF[$j] <= $liqPx) : ($hF[$j] >= $liqPx);  // 触爆仓价判定
            $hitTp  = ($mode != 'MID') ? ($isL ? ($hF[$j] >= $tgtPx) : ($lF[$j] <= $tgtPx)) : false;  // MID模式无固定止盈
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 爆仓
            if ($hitTp) { $outcome = 'WIN'; $exitPx = $tgtPx; $exitMaker = ($mode == 'TP03'); break; }  // 止盈(TP03按maker出)
            if ($mode == 'MID' && $blMid[$j] !== null) {  // 均值回归模式: 回归中轨平仓
                if ($isL ? ($cF[$j] >= $blMid[$j]) : ($cF[$j] <= $blMid[$j])) { $outcome = 'XREV'; $exitPx = $cF[$j]; break; }  // 收盘回到中轨即平
            }
            if ($j - $i >= 7 * 24 * 4) { $outcome = 'TO'; $exitPx = $cF[$j]; break; }  // 持仓超7天超时平仓
            // 加仓: 同方向六重共振再触发 + 顺向
            $sigOk = $isL ? (isset($sigL[$j]) && $cF[$j] > $avg) : (isset($sigS[$j]) && $cF[$j] < $avg);  // 同方向信号+顺向
            if ($sigOk && $j + 1 < $nF) {         // 满足加仓条件
                $ap = $oF[$j + 1];                // 加仓价 = 下一根开盘
                if ($ap > 0) {                    // 加仓价有效
                    $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);  // 加权新均价
                    $notional += $ADDU * $LEV; $adds++;  // 名义+1U×杠杆, 计数+1
                    $fee += $ADDU * $LEV * $FEE_TAKER;   // 加仓taker费
                    if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + ($mode == 'TP03' ? $TP03 : $TPR)); }  // 重算多头爆仓/止盈价
                    else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - ($mode == 'TP03' ? $TP03 : $TPR)); }  // 重算空头爆仓/止盈价
                }
            }
            $j++;                                 // 推进下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $nF - 1; $exitPx = $cF[$j]; }  // 数据耗尽按最后收盘平仓
        $fee += $notional * ($exitMaker ? $FEE_MAKER : $FEE_TAKER);  // 平仓费: TP03按maker, 其余taker
        $gross = $isL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);  // 毛盈亏(方向相关)
        $pnl = $gross - $fee - $funding;          // 净盈亏
        $mg = $M0 + $adds * $ADDU;                // 该笔总保证金
        if ($pnl < -$mg) $pnl = -$mg;             // 亏损封顶到总保证金
        $eq += $pnl;                              // 入账
        $trades[] = ['side' => $isL ? 'LONG' : 'SHORT', 'tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds,  // 记录成交明细
                     'm0' => $M0, 'mg' => $mg, 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),  // 保证金/总保证金/盈亏/权益
                     'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];  // 持仓小时数
        $i = $j + 1;                              // 跳到出场后一根
    }
    return [$trades, $eq];                        // 返回成交与期末权益
}

$W6 = 6; $W8 = 8; $W24 = 24;   // 容差窗: 6根=1.5h / 8根=2h / 24根=6h   // 三种容差窗根数
$bandWinL6 = winState($bandL, $nF, $W6);  $bandWinS6 = winState($bandS, $nF, $W6);   // 触轨6根窗状态(多/空)
$bandWinL24 = winState($bandL, $nF, $W24); $bandWinS24 = winState($bandS, $nF, $W24);  // 触轨24根窗状态
$mergeWin8 = winState($mergedSig, $nF, $W8);      // 合一8根窗状态
$mergeWin24 = winState($mergedSig, $nF, $W24);    // 合一24根窗状态

$VARIANTS = [                                     // 五组对照变体定义
    'A1' => ['name' => '轨道共振(6根窗)+合一(8根窗)+均值回归·中轨平仓', 'mode' => 'MID',  'bandLook' => 6,  'mergeLook' => 8,  // A1: 短窗+均值回归
             'bwL' => $bandWinL6, 'bwS' => $bandWinS6, 'mw' => $mergeWin8],  // 传入对应窗状态
    'A2' => ['name' => '轨道共振(24根窗)+合一(24根窗)+均值回归·中轨平仓', 'mode' => 'MID',  'bandLook' => 24, 'mergeLook' => 24,  // A2: 长窗+均值回归
             'bwL' => $bandWinL24, 'bwS' => $bandWinS24, 'mw' => $mergeWin24],  // 长窗状态
    'B1' => ['name' => '轨道共振(6根窗)+合一(8根窗)+固定止盈+0.3%(maker)', 'mode' => 'TP03', 'bandLook' => 6,  'mergeLook' => 8,  // B1: 短窗+小止盈
             'bwL' => $bandWinL6, 'bwS' => $bandWinS6, 'mw' => $mergeWin8],  // 短窗状态
    'B2' => ['name' => '轨道共振(24根窗)+合一(24根窗)+固定止盈+0.3%(maker)', 'mode' => 'TP03', 'bandLook' => 24, 'mergeLook' => 24,  // B2: 长窗+小止盈
             'bwL' => $bandWinL24, 'bwS' => $bandWinS24, 'mw' => $mergeWin24],  // 长窗状态
    'C'  => ['name' => '原sixchipall口径(同刻合一·无轨道过滤·止盈±2%)对照', 'mode' => 'TP2', 'bandLook' => 0, 'mergeLook' => 0,  // C: 对照组(原口径)
             'bwL' => [], 'bwS' => [], 'mw' => []],  // 无容差窗
];

$results = [];                                    // 各组结果
foreach ($VARIANTS as $vk => $vc) {               // 逐组跑模拟
    [$trades, $eq] = sim($vc['mode'], $vc['bandLook'], $vc['mergeLook'], $tsF, $oF, $hF, $lF, $cF, $blMid,  // 调用模拟
                         $vc['bwL'], $vc['bwS'], $vc['mw'], $mergedSig, $sigL, $sigS, $nF);  // 传入窗状态与信号
    $tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'xrev' => 0, 'adds' => 0, 'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0];  // 统计容器
    $holdSum = 0.0;                               // 持仓时长累计
    foreach ($trades as $x) {                     // 逐笔统计
        if ($x['out'] == 'LIQ') $tot['liq']++;    // 爆仓数
        if ($x['out'] == 'TO') $tot['to']++;      // 超时数
        if ($x['out'] == 'XREV') $tot['xrev']++;  // 回归中轨平仓数
        if ($x['pnl'] > 0) $tot['win']++;   // 胜率=实际盈利笔(含止盈/回归中轨盈利平仓)
        $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl']; $holdSum += $x['holdh'];  // 累计加仓/盈亏/时长
        if ($x['side'] == 'LONG') { $tot['l_n']++; $tot['l_pnl'] += $x['pnl']; }  // 多头笔数与盈亏
        else { $tot['s_n']++; $tot['s_pnl'] += $x['pnl']; }  // 空头笔数与盈亏
    }
    $tot['pnl'] = round($tot['pnl'], 2); $tot['l_pnl'] = round($tot['l_pnl'], 2); $tot['s_pnl'] = round($tot['s_pnl'], 2);  // 保留两位
    $tot['hold_avg'] = $tot['n'] ? round($holdSum / $tot['n'], 1) : 0;  // 平均持仓时长
    $peak = -INF; $maxdd = 0;                     // 峰值/最大回撤
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }  // 逐笔更新
    $months = [];                                 // 月度统计
    foreach ($trades as $x) {                     // 逐笔聚合
        $m = gmdate('Y-m', (int)($x['tout'] / 1000) + 8 * 3600);  // 出场月份(北京时间)
        $mo = &$months[$m];                       // 引用该月聚合行
        $mo['n'] = ($mo['n'] ?? 0) + 1;           // 月笔数
        $mo['pnl'] = round(($mo['pnl'] ?? 0) + $x['pnl'], 2);  // 月盈亏
        $mo['eq_end'] = $x['eq'];                 // 月末权益
        unset($mo);                               // 解除引用
    }
    $results[$vk] = ['summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),  // 存该组结果
                     'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF,  // 数据跨度/根数
                     'sigL' => $cntL, 'sigS' => $cntS, 'merge' => $mergeCnt, 'bandLn' => $bandLn, 'bandSn' => $bandSn,  // 各层信号计数
                     'months' => $months, 'trades' => $trades];  // 月度/明细
    echo sprintf("[%s] %s => n=%d win=%d liq=%d to=%d xrev=%d adds=%d pnl=%.2f (L=%.1f S=%.1f) eqEnd=%.1f maxdd=%.1f\n",  // 打印该组摘要
        $vk, $vc['name'], $tot['n'], $tot['win'], $tot['liq'], $tot['to'], $tot['xrev'], $tot['adds'], $tot['pnl'], $tot['l_pnl'], $tot['s_pnl'], $eq, $maxdd);  // 各项
    flush();                                      // 立即刷新
}

$out = [                                          // 组装输出 JSON
    'meta' => [                                   // 元信息
        'inst' => $INST, 'lev' => $LEV, 'eq0' => $EQ0, 'tp' => $TPR, 'tp03' => $TP03,  // 品种/杠杆/起始权益/两种止盈
        'params' => 'ETH单合约(用户指令"只单独算ETH") | 15m K线一年 | 入场共三层: ①六重共振新鲜触发(多头=全市场1D MA20宽度>50%/1h>MA20/1h>MA10/4h>MA5/taker买比≥50%/BTC熔断禁入 从假→真; 空头=全部反向) ②筹码峰双线合一(滚动150根/60格vol_quote直方图增量更新, 峰=局部极大且>1.3×均值·相邻3格合并·量能前2名; 两峰价差≤0.4% 且此前24根内曾分离≥1% 先分后合; 现价峰区±1%) ③布林(20,2σ)轨道共振: 收盘触下轨→做多/触上轨→做空 [严格同刻一年仅1笔 → 状态+容差窗: A1/B1轨≤6根窗+合一≤8根窗; A2/B2各≤24根窗] | 平仓: A=回归中轨(均值回归) B=固定止盈+0.3%价格(maker挂单) C=止盈±2%(同刻合一·无轨道·对照) | 加仓=同方向六重共振再触发+顺向 不限轮 每轮+1U | 每笔固定1U·独立500U·100x·爆仓线±0.6%照模拟·超时7天·净口径含手续费+资金费',  // 完整参数说明
    ],
    'variants' => array_map(fn($vk, $vc) => ['key' => $vk, 'name' => $vc['name']], array_keys($VARIANTS), $VARIANTS),  // 变体清单
    'results' => $results,                        // 各组完整结果
];
file_put_contents('E:/finally-main/web/sixchip2_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出报告 JSON
echo "DATA OK\n";                                 // 完成提示
