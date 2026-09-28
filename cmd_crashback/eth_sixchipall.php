<?php
/**
 * eth_sixchipall.php — 全市场(剔除美股/ETF/商品代币化永续) 六重共振×筹码峰双线合一 一年回测
 * 用户(2026-09-26): "美股/ETF代币化永续全部取消, 信号全部换成六重共振(不再用三均线):
 *   多头信号=牛市六条件从假→真新鲜触发15m; 空头=六条件全部反向新鲜触发;
 *   另外要求同步筹码峰: 滚动150根/60格直方图/峰>1.3×均值/相邻3格合并/量能前2名;
 *   买入=双线合一(价差≤0.4%, 此前24根内曾分离≥1%, 现价峰区±1%) 下一根开盘买;
 *   加仓=持仓中同方向六重共振再触发+顺向 +1U不限轮数; 每笔固定1U; 每合约独立500U;
 *   止盈±2% | 100x爆仓线照模拟 | 超时7天 | 净口径含手续费"
 * 即: 入场 = 六重共振新鲜触发 AND 该根K线筹码峰双线合一(双重过滤)
 * 输出: web/sixchipall_data.json
 */
ini_set('memory_limit', '4096M');                 // 内存上限 4G
set_time_limit(0);                                // 取消执行时间限制
$LEV = 100; $TPR = 0.02; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆/止盈/维持保证金率/费率/资金费率
$EQ0 = 500.0; $ADDU = 1.0;                        // 每合约起始权益500U/每轮加仓1U
$W = 150; $NB = 60; $SEP_THR = 0.004; $SEP_HIST = 0.01; $SEP_LOOK = 24;  // 筹码峰参数: 滚动150根/60格/合一价差阈值0.4%/分离阈值1%/回看24根

function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }  // 连接本地 MariaDB trading 库
$DB = db();                                       // 建立全局连接
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }  // 执行 SQL 返回全部行
function ma($a, $n) { $out = []; $s = 0.0; for ($i = 0; $i < count($a); $i++) { $s += $a[$i]; if ($i >= $n) $s -= $a[$i - $n]; $out[$i] = ($i >= $n - 1) ? $s / $n : null; } return $out; }  // 滑动均线 MA
function bj($ms) { return gmdate('Y-m-d H:i', (int)($ms / 1000) + 8 * 3600); }  // 毫秒时间戳转北京时间字符串

// ===== 剔除清单: 美股/全球股票/pre-IPO/ETF/指数/商品/外汇 代币化永续 =====
$EXCL = explode(' ', 'aaoi aapl adbe amd alab amat amc amzn anthropic app asml avgo axti bill brkb bx cien coin cost crcl crdo crm crwv csco cxmt ddog dell dgai dkng gme glw gpro gps gtlb hanmi hims hood hpe hut hyundai ibm intc ionq iren isrg jnj kioxia klac lgelectronics lly lrcx lunr mara meta mrvl mrk mrna msft mstr mstu mu naver nbis net nflx nok now nvda nvdl oklo okta onds on openai orcl oscr oura oust poet pypl qcom rddt rdw riot rivn rklb rok samsung shein shell simo skdd skhynix skhy smci sndk snow softbank sony spcx strc tsem tsla tsll tsm ttmi ttwo twlo unh vrt wdc wmt xiaom xiaomi xom zhipu zhongji zm ewj ewt ewy ewz iwm jp225 kr200 qqq smh soxl soxs spy sqqq tqqq tmf us100 us500 uvxy xbi xle uso urnm xau xag xpd xpt xcu cl bz');  // 用户指令剔除的非加密资产合约名单

function load_k($inst, $bar, $withVol = false) {  // 读取 K线表, 可选 vol_quote 列
    $v = $withVol ? ',vol_quote' : '';            // 按需拼接成交额列
    $r = rows("SELECT candle_time,`o`,`h`,`l`,`c`{$v} FROM kline_{$inst}_usdt_swap_$bar ORDER BY candle_time ASC");  // 按 candle_time 升序
    $out = ['ts' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];  // 各列字典
    foreach ($r as $x) { $out['ts'][] = (int)$x[0]; $out['o'][] = (float)$x[1]; $out['h'][] = (float)$x[2];  // 类型转换
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

/** 增量筹码分布器(与eth_chippeak.php同参): 滚动窗口极值定区间, 漂移>0.5%或出界重建 */
class Chip {                                      // 筹码分布类: 滚动窗口价格-成交量直方图
    public $hist = []; public $lo = null; public $hi = null; public $bw = 0.0;  // 直方图/价格下界/上界/格宽
    private $nb; private $ring = []; private $ri = 0; private $cnt = 0; private $cap;  // 格数/环形缓冲/写入位/已填充数/容量
    function __construct($nb, $cap) { $this->nb = $nb; $this->cap = $cap; $this->hist = array_fill(0, $nb, 0.0); }  // 构造: 格数与窗口容量
    private function adds($l, $h, $v) {           // 一根K线的量均匀摊到其价格覆盖的格子
        $nb = $this->nb; $a = array_fill(0, $nb, 0.0);  // 单根贡献数组
        if ($h <= $l || $v <= 0 || $this->bw <= 0) return $a;  // 无效输入返回空
        $bw = $this->bw;                          // 当前格宽
        $i0 = (int)floor(($l - $this->lo) / $bw); $i1 = (int)floor(($h - $this->lo) / $bw);  // 起止格下标
        $i0 = max(0, min($nb - 1, $i0)); $i1 = max(0, min($nb - 1, $i1));  // 夹到合法范围
        if ($i0 == $i1) { $a[$i0] = $v; return $a; }  // 单格全额
        $per = $v / ($i1 - $i0 + 1);              // 跨格均摊
        for ($i = $i0; $i <= $i1; $i++) $a[$i] = $per;  // 逐格填入
        return $a;                                // 返回贡献
    }
    private function rebuild() {                  // 全量重建直方图
        $lo = INF; $hi = -INF;                    // 窗口价格范围
        foreach ($this->ring as $b) { if ($b === null) continue; if ($b[0] < $lo) $lo = $b[0]; if ($b[1] > $hi) $hi = $b[1]; }  // 求窗口高低
        if ($hi <= $lo) $hi = $lo * 1.001 + 1e-9; // 防零范围
        $this->lo = $lo; $this->hi = $hi; $this->bw = ($hi - $lo) / $this->nb;  // 更新边界与格宽
        $this->hist = array_fill(0, $this->nb, 0.0);  // 清空直方图
        foreach ($this->ring as $b) {             // 重新摊入全部K线
            if ($b === null) continue;            // 跳过空槽
            $a = $this->adds($b[0], $b[1], $b[2]);  // 该根贡献
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i];  // 累加
        }
    }
    function push($l, $h, $v) {                   // 推入一根K线(增量更新)
        $this->ring[$this->ri] = [$l, $h, $v];    // 写入环形缓冲
        $this->ri = ($this->ri + 1) % $this->cap; // 写入位前移
        if ($this->cnt < $this->cap) { $this->cnt++; $this->rebuild(); return; }  // 未满 → 全量重建
        $lo = INF; $hi = -INF;                    // 已满 → 检查边界变化
        foreach ($this->ring as $b) { if ($b === null) continue; if ($b[0] < $lo) $lo = $b[0]; if ($b[1] > $hi) $hi = $b[1]; }  // 求窗口边界
        $rng = $hi - $lo; if ($rng <= 0) $rng = $hi * 0.001 + 1e-9;  // 防零范围
        $need = ($this->bw <= 0) || ($l < $this->lo) || ($h > $this->hi)  // 越界须重建
             || (abs($lo - $this->lo) > 0.005 * $rng) || (abs($hi - $this->hi) > 0.005 * $rng);  // 边界漂移>0.5%幅度也重建
        if ($need) { $this->lo = $lo; $this->hi = $hi; $this->bw = $rng / $this->nb;  // 重建: 更新边界
            $this->hist = array_fill(0, $this->nb, 0.0);  // 清空直方图
            foreach ($this->ring as $b) { if ($b === null) continue;  // 重新摊入
                $a = $this->adds($b[0], $b[1], $b[2]);
                for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i]; }
        } else {                                  // 边界稳定 → 纯增量累加
            $a = $this->adds($l, $h, $v);
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i];
        }
    }
    function evictOldest() {                      // 滑动窗口淘汰最旧一根
        $old = $this->ring[$this->ri];            // ri 指向的最旧槽位
        if ($old === null) return;                // 空槽返回
        if ($this->bw > 0) {                      // 直方图有效做减法
            $a = $this->adds($old[0], $old[1], $old[2]);  // 旧根贡献
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0 && $this->hist[$i] >= $a[$i]) $this->hist[$i] -= $a[$i];  // 扣除
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
        $pk = [];                                 // 候选峰
        for ($i = 1; $i < $nb - 1; $i++) {        // 局部极大且>1.3×均值
            if ($h[$i] > $h[$i - 1] && $h[$i] >= $h[$i + 1] && $h[$i] > $avg * 1.3)
                $pk[] = [$this->lo + ($i + 0.5) * $this->bw, $h[$i], $i];  // [峰价格, 峰量, 格下标]
        }
        if (count($pk) == 0) return null;         // 无候选峰
        $mg = []; $cur = $pk[0];                  // 相邻3格内合并
        for ($k = 1; $k < count($pk); $k++) {     // 遍历候选
            if ($pk[$k][2] - $cur[2] <= 3) {      // 距离≤3格合并
                $tv = $cur[1] + $pk[$k][1];       // 合并总量
                $cur = [($cur[0] * $cur[1] + $pk[$k][0] * $pk[$k][1]) / $tv, $tv, $cur[2]];  // 量加权均价
            } else { $mg[] = $cur; $cur = $pk[$k]; }  // 另起新峰
        }
        $mg[] = $cur;                             // 收入末峰
        if (count($mg) < 2) return null;          // 不足双峰
        usort($mg, function ($a, $b) { return $b[1] <=> $a[1]; });  // 按量降序
        $two = [$mg[0], $mg[1]];                  // 量能前2名
        usort($two, function ($a, $b) { return $a[0] <=> $b[0]; });  // 按价格升序
        return [[$two[0][0], $two[0][1]], [$two[1][0], $two[1][1]]]; // [洗盘峰(低), 拉升峰(高)]   // 返回[低价峰, 高价峰]
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
$gB = load_k('btc', '1h');                        // 读 BTC 1h
$tsB = $gB['ts']; $cB = $gB['c'];                 // 时间戳/收盘
$meltSet = [];                                    // 熔断小时集合
for ($i = 4; $i < count($cB); $i++) {             // 扫描4根累计跌幅
    if ($cB[$i - 4] > 0 && ($cB[$i] / $cB[$i - 4] - 1) <= -0.03) {  // 4根累计≤-3%触发
        for ($k = 0; $k < 4; $k++) $meltSet[$tsB[$i] + $k * 3600000] = true;  // 触发后4小时禁入
    }
}
unset($rr, $gB, $tsB, $cB);                       // 释放内存

// ===== 合约清单(剔除美股/ETF/商品) =====
$insts = []; $exclN = 0;                          // 合约列表/被剔除数
foreach (rows("SHOW TABLES LIKE 'kline\\_%\\_usdt\\_swap\\_15m'") as $x) {  // 列出有15m表的合约
    if (preg_match('/^kline_(.+)_usdt_swap_15m$/', $x[0], $m)) {  // 提取合约名
        if (in_array($m[1], $EXCL, true)) { $exclN++; continue; }  // 剔除清单中的跳过计数
        $insts[] = $m[1];                         // 保留合约
    }
}
sort($insts);                                     // 按名称排序
echo "insts=" . count($insts) . " (excluded US/ETF/commodity=$exclN)\n"; flush();  // 输出合约数与剔除数

// ===== 逐合约回测 =====
$liqDrop = 1.0 / $LEV - $MMR;                     // 简化爆仓距离 ≈ 0.6%
$results = []; $done = 0;                         // 结果表/进度
foreach ($insts as $inst) {                       // 遍历全部合约
    $gF = load_k($inst, '15m', true);             // 读 15m(含成交额)
    $tsF = $gF['ts']; $oF = $gF['o']; $hF = $gF['h']; $lF = $gF['l']; $cF = $gF['c']; $vF = $gF['v'];  // 拆列
    $nF = count($tsF);                            // 15m 根数
    if ($nF < 3000) { $results[$inst] = ['skip' => '15m数据不足(' . $nF . '根)']; continue; }  // 数据不足约31天跳过
    $g1 = load_k($inst, '1h'); $g4 = load_k($inst, '4h');  // 读 1h/4h
    $ts1 = $g1['ts']; $c1 = $g1['c']; $ts4 = $g4['ts']; $c4 = $g4['c'];  // 取时间戳/收盘
    $n1 = count($ts1); $n4 = count($ts4);         // 1h/4h 根数
    if ($n1 < 300 || $n4 < 80) { $results[$inst] = ['skip' => '1h/4h数据不足']; continue; }  // 高周期不足跳过
    $ma20 = ma($c1, 20); $ma10 = ma($c1, 10); $ma5_4 = ma($c4, 5);  // 1h MA20/MA10 与 4h MA5
    $br1h = buyratio($g1['o'], $g1['h'], $g1['l'], $g1['c']);  // 1h taker买比

    // 六条件状态 + 新鲜信号
    $sixL = false; $sixS = false; $sigL = []; $sigS = [];  // 状态与新鲜信号
    $p1 = 0; $p4 = 0; $cur20 = null; $cur10 = null; $cur4 = null;  // 游标与条件状态
    for ($i = 0; $i < $nF; $i++) {                // 逐根15m扫描
        $t = $tsF[$i];                            // 当前时间戳
        while ($p1 < $n1 && $ts1[$p1] <= $t) { if ($ma20[$p1] !== null) $cur20 = $c1[$p1] > $ma20[$p1]; if ($ma10[$p1] !== null) $cur10 = $c1[$p1] > $ma10[$p1]; $p1++; }  // 前向推进: ②③条件
        while ($p4 < $n4 && $ts4[$p4] <= $t) { if ($ma5_4[$p4] !== null) $cur4 = $c4[$p4] > $ma5_4[$p4]; $p4++; }  // 前向推进: ④条件
        $hb = (int)floor($t / 3600000) * 3600000; // 对齐整点小时
        $b = ffillBr($t);                         // ① 全市场宽度
        $nl = false; $ns = false;                 // 本根多/空命中
        if ($b !== null && !isset($meltSet[$hb])) {  // ①有效 且 ⑤非熔断
            $lo = 0; $hi = $n1 - 1; $ib = -1;     // 二分找对应1h下标
            while ($lo <= $hi) { $mid = (int)(($lo + $hi) / 2); if ($ts1[$mid] <= $t) { $ib = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }  // 标准二分
            if ($ib >= 0) {                       // 找到1h
                $br = $br1h[$ib];                 // ⑥ taker买比
                if ($b > 0.5 && $cur20 === true && $cur10 === true && $cur4 === true && $br >= 0.5) $nl = true;  // 多头六条件全命中
                if ($b < 0.5 && $cur20 === false && $cur10 === false && $cur4 === false && $br < 0.5) $ns = true;  // 空头全反向
            }
        }
        if ($nl && !$sixL) $sigL[$i] = true;      // 多头 false→真 新鲜触发
        if ($ns && !$sixS) $sigS[$i] = true;      // 空头新鲜触发
        $sixL = $nl; $sixS = $ns;                 // 滚动状态
    }
    $cntL = count($sigL); $cntS = count($sigS);   // 多/空信号数
    unset($g1, $g4, $ts1, $ts4, $c1, $c4, $ma20, $ma10, $ma5_4, $br1h);  // 释放内存

    // ===== 筹码峰 双线合一(逐15m K线) =====
    $chip = new Chip($NB, $W);                    // 创建筹码分布器
    for ($i = 0; $i < $W; $i++) if ($hF[$i] > 0 && $lF[$i] > 0) $chip->push($lF[$i], $hF[$i], $vF[$i]);  // 预填窗口
    $sepPrev = array_fill(0, $SEP_LOOK, 0.0); $sepIdx = 0;  // 分离度环形记录
    $mergedSig = []; $mergeCnt = 0;               // 合一信号集合/计数
    for ($i = $W; $i < $nF - 1; $i++) {           // 窗口满后逐根扫描
        if ($hF[$i] > 0 && $lF[$i] > 0 && $vF[$i] > 0) {  // K线有效
            $chip->evictOldest();                 // 滑出最旧
            $chip->push($lF[$i], $hF[$i], $vF[$i]);  // 推入当前
            $pk = $chip->peaks();                 // 取双峰
            $sep = 0.0; $mg = false;              // 分离度/合一标志
            if ($pk !== null) {                   // 有双峰
                $pl = $pk[0][0]; $ph = $pk[1][0]; // 低价峰/高价峰
                if ($pl > 0) {                    // 低价峰有效
                    $sep = ($ph - $pl) / $pl;     // 两峰分离度
                    $mid = ($pl + $ph) / 2;       // 峰区中点
                    $mg = ($sep <= $SEP_THR) && ($cF[$i] >= $mid * 0.99) && ($cF[$i] <= $mid * 1.01);  // 合一: 价差≤0.4%+现价峰区±1%
                    if ($mg) {                    // 满足合一 → 查先分后合
                        $everSep = false;         // 是否曾分离≥1%
                        for ($k = 1; $k <= $SEP_LOOK; $k++) {  // 回看24根
                            $idx = ((($sepIdx - $k) % $SEP_LOOK) + $SEP_LOOK) % $SEP_LOOK;   // 修复PHP负取模   // 环形取历史分离度
                            if ($sepPrev[$idx] >= $SEP_HIST) { $everSep = true; break; }  // 曾达1%
                        }
                        if ($everSep) { $mergedSig[$i] = true; $mergeCnt++; }  // 记合一信号
                    }
                }
            }
            $sepPrev[$sepIdx] = $sep; $sepIdx = ($sepIdx + 1) % $SEP_LOOK;  // 记录本根分离度
        }
    }
    unset($vF, $chip);                            // 释放内存

    // ===== 模拟: 入场=六重共振新鲜触发 AND 双线合一 =====
    $eq = $EQ0;                                   // 该合约独立权益从500U起
    $trades = []; $i = 0;                         // 成交列表/游标
    while ($i < $nF - 1) {                        // 逐根扫描
        $isL = isset($sigL[$i]) && isset($mergedSig[$i]);   // 多头: 六重共振信号 AND 本根双线合一
        $isS = !$isL && isset($sigS[$i]) && isset($mergedSig[$i]);  // 空头: 同样要求合一
        if (!$isL && !$isS) { $i++; continue; }   // 非信号根跳过
        if ($i + 1 >= $nF || $oF[$i + 1] <= 0) { $i++; continue; }  // 脏数据跳过
        $side = $isL ? 'LONG' : 'SHORT';          // 方向
        $M0 = 1.0;                                // 每笔固定1U保证金
        $e = $oF[$i + 1];                         // 下一根15m开盘入场
        $notional = $M0 * $LEV; $avg = $e; $adds = 0;  // 名义/均价/加仓计数
        $fee = $notional * $FEE_MAKER; $funding = 0.0;  // 开仓maker费/资金费累计
        if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); }  // 多头爆仓价/止盈价
        else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - $TPR); }  // 空头爆仓价/止盈价
        $outcome = null; $j = $i + 1;             // 结局/游标
        while ($j < $nF) {                        // 持仓推进
            if ($hF[$j] <= 0 || $lF[$j] <= 0) { $j++; continue; }  // 脏数据跳过
            $funding += ($isL ? 1 : -1) * $notional * $FUND8H / 32;  // 每15m计提资金费(多付/空收)
            $hitLiq = $isL ? ($lF[$j] <= $liqPx) : ($hF[$j] >= $liqPx);  // 触爆仓价判定
            $hitTp  = $isL ? ($hF[$j] >= $tgtPx) : ($lF[$j] <= $tgtPx);  // 触止盈价判定
            if ($hitLiq) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 爆仓
            if ($hitTp)  { $outcome = 'WIN'; $exitPx = $tgtPx; break; }  // 止盈
            if ($j - $i >= 7 * 24 * 4) { $outcome = 'TO'; $exitPx = $cF[$j]; break; }  // 持仓超7天超时平仓
            // 加仓: 同方向六重共振再次新鲜触发 + 顺向
            $sigOk = $isL ? (isset($sigL[$j]) && $cF[$j] > $avg) : (isset($sigS[$j]) && $cF[$j] < $avg);  // 同方向信号+现价在顺向侧
            if ($sigOk && $j + 1 < $nF) {         // 满足加仓条件
                $ap = $oF[$j + 1];                // 加仓价 = 下一根开盘
                if ($ap > 0) {                    // 加仓价有效
                    $avg = ($avg * $notional + $ap * $ADDU * $LEV) / ($notional + $ADDU * $LEV);  // 加权新均价
                    $notional += $ADDU * $LEV; $adds++;  // 名义+1U×杠杆, 计数+1(不限轮数)
                    $fee += $ADDU * $LEV * $FEE_TAKER;   // 加仓taker费
                    if ($isL) { $liqPx = $avg * (1 - $liqDrop); $tgtPx = $avg * (1 + $TPR); }  // 重算多头爆仓/止盈价
                    else      { $liqPx = $avg * (1 + $liqDrop); $tgtPx = $avg * (1 - $TPR); }  // 重算空头爆仓/止盈价
                }
            }
            $j++;                                 // 推进下一根
        }
        if ($outcome === null) { $outcome = 'TO'; $j = $nF - 1; $exitPx = $cF[$j]; }  // 数据耗尽按最后收盘平仓
        $gross = $isL ? (($exitPx - $avg) * $notional / $avg) : (($avg - $exitPx) * $notional / $avg);  // 毛盈亏
        $fee += $notional * $FEE_TAKER;           // 平仓taker费
        $pnl = $gross - $fee - $funding;          // 净盈亏
        $mg = $M0 + $adds * $ADDU;                // 该笔总保证金
        if ($pnl < -$mg) $pnl = -$mg;             // 亏损封顶
        $eq += $pnl;                              // 入账
        $trades[] = ['side' => $side, 'tin' => $tsF[$i], 'tout' => $tsF[$j], 'out' => $outcome, 'adds' => $adds,  // 记录成交明细
                     'm0' => $M0, 'mg' => $mg, 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),  // 保证金/盈亏/权益
                     'holdh' => round(($tsF[$j] - $tsF[$i]) / 3600000.0, 1)];  // 持仓小时数
        $i = $j + 1;                              // 跳到出场后一根
    }
    // 汇总
    $tot = ['n' => count($trades), 'win' => 0, 'liq' => 0, 'to' => 0, 'adds' => 0, 'pnl' => 0.0, 'l_pnl' => 0.0, 's_pnl' => 0.0, 'l_n' => 0, 's_n' => 0];  // 统计容器
    foreach ($trades as $x) {                     // 逐笔统计
        if ($x['out'] == 'WIN') $tot['win']++;    // 止盈数
        if ($x['out'] == 'LIQ') $tot['liq']++;    // 爆仓数
        if ($x['out'] == 'TO') $tot['to']++;      // 超时数
        $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl'];  // 累计加仓与盈亏
        if ($x['side'] == 'LONG') { $tot['l_n']++; $tot['l_pnl'] += $x['pnl']; }  // 多头分项
        else { $tot['s_n']++; $tot['s_pnl'] += $x['pnl']; }  // 空头分项
    }
    $tot['pnl'] = round($tot['pnl'], 2); $tot['l_pnl'] = round($tot['l_pnl'], 2); $tot['s_pnl'] = round($tot['s_pnl'], 2);  // 保留两位
    $peak = -INF; $maxdd = 0;                     // 峰值/最大回撤
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }  // 逐笔更新
    $months = [];                                 // 月度统计
    foreach ($trades as $x) {                     // 逐笔聚合
        $m = gmdate('Y-m', (int)($x['tout'] / 1000) + 8 * 3600);  // 出场月份
        $mo = &$months[$m];                       // 引用该月行
        $mo['n'] = ($mo['n'] ?? 0) + 1;           // 月笔数
        $mo['pnl'] = round(($mo['pnl'] ?? 0) + $x['pnl'], 2);  // 月盈亏
        $mo['eq_end'] = $x['eq'];                 // 月末权益
        unset($mo);                               // 解除引用
    }
    $results[$inst] = [                           // 存该合约结果
        'summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),  // 汇总/期末权益/回撤
        'span' => [bj($tsF[0]), bj($tsF[$nF - 1])], 'bars' => $nF, 'sigL' => $cntL, 'sigS' => $cntS, 'merge' => $mergeCnt,  // 跨度/根数/信号与合一计数
        'months' => $months, 'trades' => $trades, // 月度/明细
    ];
    $done++;                                      // 完成计数
    if ($done % 40 == 0) { echo "progress $done/" . count($insts) . " ($inst pnl={$tot['pnl']})\n"; flush(); }  // 每40个输出进度
    unset($tsF, $oF, $hF, $lF, $cF, $trades);     // 释放内存
}

// ===== 汇总 =====
$totN = 0; $totPnl = 0.0; $totWin = 0; $totLiq = 0; $totAdds = 0; $lp = 0.0; $sp = 0.0;  // 全市场合计
$rank = [];                                       // 合约排行
foreach ($results as $inst => $r) {               // 遍历各合约
    if (isset($r['skip'])) continue;              // 跳过剔除的
    $totN += $r['summary']['n']; $totPnl += $r['summary']['pnl'];  // 累计
    $totWin += $r['summary']['win']; $totLiq += $r['summary']['liq']; $totAdds += $r['summary']['adds'];  // 累计
    $lp += $r['summary']['l_pnl']; $sp += $r['summary']['s_pnl'];  // 累计多空盈亏
    $rank[] = ['inst' => $inst, 'n' => $r['summary']['n'], 'pnl' => $r['summary']['pnl'], 'eq_end' => $r['eq_end'],  // 排行行
               'win' => $r['summary']['win'], 'liq' => $r['summary']['liq'], 'maxdd' => $r['maxdd'],  // 胜/爆/回撤
               'l_pnl' => $r['summary']['l_pnl'], 's_pnl' => $r['summary']['s_pnl']];  // 多空分项
}
usort($rank, fn($a, $b) => $b['pnl'] <=> $a['pnl']);  // 按盈亏降序
$posCnt = count(array_filter($rank, fn($x) => $x['pnl'] > 0));  // 盈利合约数
echo sprintf("TOTAL insts=%d excluded=%d traded=%d n=%d win=%d liq=%d adds=%d pnl=%.1f (L=%.1f S=%.1f) posInsts=%d\n",  // 打印全市场合计
    count($insts), $exclN, count($rank), $totN, $totWin, $totLiq, $totAdds, $totPnl, $lp, $sp, $posCnt);  // 各项

$allMon = [];                                     // 全市场月度盈亏
foreach ($results as $inst => $r) {               // 遍历各合约
    if (isset($r['skip'])) continue;              // 跳过剔除的
    foreach ($r['months'] as $m => $v) $allMon[$m] = round(($allMon[$m] ?? 0) + $v['pnl'], 2);  // 合并各月
}
ksort($allMon);                                   // 按月份排序

$out = [                                          // 组装输出 JSON
    'meta' => [                                   // 元信息
        'lev' => $LEV, 'tp' => '±2%', 'eq0' => $EQ0, 'insts' => count($insts), 'excluded' => $exclN,  // 杠杆/止盈/起始权益/合约数/剔除数
        'params' => '范围=全市场剔除美股/ETF/指数/商品/外汇/全球股票代币化永续(剔除' . $exclN . '个) | 信号=六重共振(不再用三均线): 多头=牛市六条件(全市场1D MA20宽度>50%/1h>MA20/1h>MA10/4h>MA5/taker买比≥50%/BTC熔断禁入)从假→真新鲜触发15m; 空头=六条件全部反向新鲜触发 | 入场=六重共振新鲜触发 AND 筹码峰双线合一(滚动150根/60格vol_quote直方图, 峰>1.3×均值·相邻3格合并·量能前2名, 两峰价差≤0.4% 且此前24根内曾分离≥1%, 现价峰区±1%) → 下一根开盘买 | 加仓=持仓中同方向六重共振再触发+顺向(多:现价>均价/空:现价<均价) 不限轮数 每轮+1U | 保证金=每笔固定1U | 止盈=价格±2% | 不设止损·爆仓线±0.6%@100x照模拟 | 超时7天 | 资金费=多头付/空头收 0.01%/8h | 每合约独立起始500U·每笔1U | 净口径含手续费',  // 完整参数说明
    ],
    'total' => ['insts' => count($rank), 'skipped' => count($insts) - count($rank), 'n' => $totN, 'win' => $totWin, 'liq' => $totLiq,  // 全市场合计
                'adds' => $totAdds, 'pnl' => round($totPnl, 2), 'l_pnl' => round($lp, 2), 's_pnl' => round($sp, 2),  // 盈亏
                'pos_insts' => $posCnt, 'months' => $allMon],  // 盈利数/月度
    'rank' => $rank, 'results' => $results,       // 排行/各合约明细
];
file_put_contents('E:/finally-main/web/sixchipall_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出报告 JSON
echo "DATA OK\n";                                 // 完成提示
