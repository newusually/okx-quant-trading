<?php
/**
 * eth_chippeak.php — ETH 筹码峰战法 一年回测(CLI, 2026-09-25 用户指令)
 * 策略 = 筹码峰战法(3m/5m/15m/1h 四周期):
 *   筹码峰算法: 滚动最近150根K线, 每根K线把 vol_quote 均匀摊到其 [低,高] 区间
 *     (60格直方图, 增量更新); 峰 = 局部极大(>1.3×均值), 相邻3格内合并, 取量能前2名:
 *     高价峰 = 拉升峰, 低价峰 = 洗盘峰; 最早期主峰 = 建仓峰(锁仓, 不破不出)。
 *   买入 = 双线合一: 拉升峰与洗盘峰 价差≤0.4%(合一), 且此前24根内曾分离≥1%(先分后合),
 *          现价在峰区±1%内; 下一根开盘买入。同一根K线只触发1次。
 *   加仓 = 持仓中再出现 双线合一 → +1U(下一根开盘), 不限轮数, 总保证金(含加仓)≤50U 停止。
 *   平仓 = 出货峰: 收盘曾上穿峰区上方(锁仓拉升成立)后, 收盘跌破峰区下沿 → 下一根开盘全平;
 *          超时14天平收。不设止损; 不模拟爆仓(浮亏照算, 权益扣到0即破产终止)。
 *   保证金 = 1U + 3U×已过天数, 封顶50U; 100x 逐仓; 起始权益 500U 逐笔结转。
 *   净口径: 开仓maker 0.02% / 加仓·平仓 taker 0.05% / 资金费 0.01%/8h。
 * 输出: web/chippeak_data.json
 */
ini_set('memory_limit', '2048M');                                 // 提升 PHP 内存上限到 2G
set_time_limit(0);                                                // 取消脚本执行时间限制
$LEV = 100; $MMR = 0.004; $FEE_MAKER = 0.0002; $FEE_TAKER = 0.0005; $FUND8H = 0.0001;  // 杠杆100x; 维持保证金率0.4%; 手续费与资金费率
$EQ0 = 500.0; $CAPM = 50.0; $ADDU = 1.0; $TPR = 0.02;             // 起始权益500U; 总保证金封顶50U; 每轮加仓1U; 止盈+2%
$W = 150; $NB = 60; $SEP_THR = 0.004; $SEP_HIST = 0.01; $SEP_LOOK = 24; $TO_BARS_DAYS = 14;  // 筹码峰: 窗口150根/60格/合一价差0.4%/分离1%/回看24根; 超时14天

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
class Chip {                                                      // 筹码分布类
    public $hist = []; public $lo = null; public $hi = null; public $bw = 0.0;  // 直方图/区间下界/上界/格宽
    private $nb; private $ring = []; private $ri = 0; private $cnt = 0; private $cap;  // 格数/环形缓冲/写指针/计数/容量
    function __construct($nb, $cap) { $this->nb = $nb; $this->cap = $cap; $this->hist = array_fill(0, $nb, 0.0); }  // 构造: 格数与窗口容量
    private function adds($l, $h, $v) {                           // 一根K线的量均摊到价格格, 返回增量
        $nb = $this->nb; $a = array_fill(0, $nb, 0.0);            // 初始化增量数组
        if ($h <= $l || $v <= 0 || $this->bw <= 0) return $a;     // 无效数据返回空
        $bw = $this->bw;                                          // 当前格宽
        $i0 = (int)floor(($l - $this->lo) / $bw); $i1 = (int)floor(($h - $this->lo) / $bw);  // 高低价对应格下标
        $i0 = max(0, min($nb - 1, $i0)); $i1 = max(0, min($nb - 1, $i1));  // 钳制到有效范围
        if ($i0 == $i1) { $a[$i0] = $v; return $a; }              // 同格全量入
        $per = $v / ($i1 - $i0 + 1);                              // 跨格均摊
        for ($i = $i0; $i <= $i1; $i++) $a[$i] = $per;            // 填充增量
        return $a;                                                // 返回增量数组
    }
    private function rebuild() {                                  // 全量重建直方图
        $lo = INF; $hi = -INF;                                    // 窗口极值
        foreach ($this->ring as $b) { if ($b === null) continue; if ($b[0] < $lo) $lo = $b[0]; if ($b[1] > $hi) $hi = $b[1]; }  // 扫描范围
        if ($hi <= $lo) $hi = $lo * 1.001 + 1e-9;                 // 防零区间
        $this->lo = $lo; $this->hi = $hi; $this->bw = ($hi - $lo) / $this->nb;  // 更新区间与格宽
        $this->hist = array_fill(0, $this->nb, 0.0);              // 清零直方图
        foreach ($this->ring as $b) {                             // 重放每根K线
            if ($b === null) continue;                            // 空槽跳过
            $a = $this->adds($b[0], $b[1], $b[2]);                // 该根增量
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i];  // 累加
        }
    }
    function push($l, $h, $v) {                                   // 推入新K线
        $this->ring[$this->ri] = [$l, $h, $v];                    // 写入环形缓冲
        $this->ri = ($this->ri + 1) % $this->cap;                 // 指针环形前进
        if ($this->cnt < $this->cap) { $this->cnt++; $this->rebuild(); return; }  // 未满时计数并重建
        // 满窗: 扫描窗口极值, 漂移>0.5%或出界→重建, 否则增量加
        $lo = INF; $hi = -INF;                                    // 扫描极值
        foreach ($this->ring as $b) { if ($b === null) continue; if ($b[0] < $lo) $lo = $b[0]; if ($b[1] > $hi) $hi = $b[1]; }  // 范围
        $rng = $hi - $lo; if ($rng <= 0) $rng = $hi * 0.001 + 1e-9;  // 防零区间
        $need = ($this->bw <= 0) || ($l < $this->lo) || ($h > $this->hi)  // 判定是否重建: 出界
             || (abs($lo - $this->lo) > 0.005 * $rng) || (abs($hi - $this->hi) > 0.005 * $rng);  // 或漂移>0.5%
        if ($need) { $this->lo = $lo; $this->hi = $hi; $this->bw = $rng / $this->nb;  // 需要重建: 更新区间
            $this->hist = array_fill(0, $this->nb, 0.0);          // 清零
            foreach ($this->ring as $b) { if ($b === null) continue;  // 重放全部
                $a = $this->adds($b[0], $b[1], $b[2]);
                for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i]; }  // 累加重建
        } else {
            $a = $this->adds($l, $h, $v);                         // 无需重建: 只增量加新K线
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0) $this->hist[$i] += $a[$i];  // 累加
        }
    }
    function evictOldest() {                                      // 淘汰最旧一根K线
        $old = $this->ring[$this->ri];                            // 写指针处即最旧
        if ($old === null) return;                                // 空槽直接返回
        if ($this->bw > 0) {                                      // 有格宽才扣减
            $a = $this->adds($old[0], $old[1], $old[2]);          // 该根原增量
            for ($i = 0; $i < $this->nb; $i++) if ($a[$i] != 0.0 && $this->hist[$i] >= $a[$i]) $this->hist[$i] -= $a[$i];  // 从直方图扣减
        }
        $this->ring[$this->ri] = null;                            // 清空槽位
        $this->ri = ($this->ri + 1) % $this->cap;                 // 指针前进
    }
    /** 返回 [ [低价峰,量], [高价峰,量] ] 或 null */
    function peaks() {                                            // 识别筹码峰
        $nb = $this->nb; $h = $this->hist;                        // 格数与直方图
        $tot = 0.0; for ($i = 0; $i < $nb; $i++) $tot += $h[$i];  // 直方图总量
        if ($tot <= 0) return null;                               // 无量返回null
        $avg = $tot / $nb;                                        // 每格均值
        $pk = [];                                                 // 候选峰
        for ($i = 1; $i < $nb - 1; $i++) {                        // 扫描局部极大
            if ($h[$i] > $h[$i - 1] && $h[$i] >= $h[$i + 1] && $h[$i] > $avg * 1.3)  // 高于两侧且>1.3×均值
                $pk[] = [$this->lo + ($i + 0.5) * $this->bw, $h[$i], $i];  // [峰价,峰量,下标]
        }
        if (count($pk) == 0) return null;                         // 无候选峰
        // 相邻3格内合并
        $mg = []; $cur = $pk[0];                                  // 峰合并
        for ($k = 1; $k < count($pk); $k++) {
            if ($pk[$k][2] - $cur[2] <= 3) {                      // 间隔≤3格
                $tv = $cur[1] + $pk[$k][1];                       // 合并量
                $cur = [($cur[0] * $cur[1] + $pk[$k][0] * $pk[$k][1]) / $tv, $tv, $cur[2]];  // 量加权合并峰价
            } else { $mg[] = $cur; $cur = $pk[$k]; }              // 收存另起新峰
        }
        $mg[] = $cur;                                             // 收存末峰
        if (count($mg) < 2) return null;                          // 不足双峰返回null
        usort($mg, function ($a, $b) { return $b[1] <=> $a[1]; });  // 按峰量降序
        $two = [$mg[0], $mg[1]];                                  // 量能前二
        usort($two, function ($a, $b) { return $a[0] <=> $b[0]; });  // 按价格升序
        return [[$two[0][0], $two[0][1]], [$two[1][0], $two[1][1]]]; // [洗盘峰(低), 拉升峰(高)]
    }
}

/** 单周期回测 ($useTP=止盈+2%  $useLiq=真实爆仓线) */
function sim($bar, $g, $useTP, $useLiq) {                         // 指定周期的筹码峰战法回测
    global $LEV, $MMR, $FEE_MAKER, $FEE_TAKER, $FUND8H, $EQ0, $CAPM, $ADDU, $W, $NB,
           $SEP_THR, $SEP_HIST, $SEP_LOOK, $TO_BARS_DAYS, $TPR;   // 引入全局参数
    $ts = $g['ts']; $o = $g['o']; $h = $g['h']; $l = $g['l']; $c = $g['c']; $v = $g['v'];  // 拆出K线序列
    $n = count($ts);                                              // 根数
    $barMin = (int)round(($ts[1] - $ts[0]) / 60000);              // 每根K线的分钟数
    $fundPerBar = $FUND8H / (8 * 60 / $barMin);                   // 每根K线的资金费率
    $chip = new Chip($NB, $W);                                    // 创建筹码分布器
    // 先填充窗口(不产生信号)
    for ($i = 0; $i < $W; $i++) $chip->push($l[$i], $h[$i], $v[$i]);  // 预填充前150根

    $sepPrev = array_fill(0, $SEP_LOOK, 0.0); $sepIdx = 0;        // 近24根分离度环形历史
    $signals = 0; $blocked = 0;                                   // 合一信号数/被去重拦截数
    $entrySig = [];  // i => true
    for ($i = $W; $i < $n - 1; $i++) {                            // 滚动扫描生成入场信号
        $chip->evictOldest();                                     // 淘汰最旧保持150根窗
        $chip->push($l[$i], $h[$i], $v[$i]);                      // 推入新K线
        $pk = $chip->peaks();                                     // 取双峰
        $sep = 0.0; $merged = false;                              // 本根分离度/合一标志
        if ($pk !== null) {                                       // 有双峰才判
            $pl = $pk[0][0]; $ph = $pk[1][0];                     // 洗盘峰价/拉升峰价
            $sep = ($ph - $pl) / $pl;                             // 双峰分离度
            $mid = ($pl + $ph) / 2;                               // 峰区中点(未再使用)
            $merged = ($sep <= $SEP_THR) && ($c[$i] >= $pl * 0.99) && ($c[$i] <= $ph * 1.01);  // 合一: 价差≤0.4%且现价在峰区±1%
            // 先分后合: 此前 LOOK 根内曾分离≥SEP_HIST
            if ($merged) {
                $had = false;                                     // 检查历史分离
                for ($k = 0; $k < $SEP_LOOK; $k++) if ($sepPrev[$k] >= $SEP_HIST) { $had = true; break; }  // 近24根曾分离≥1%
                if (!$had) $merged = false;                       // 无先分后合则不算
            }
        }
        if ($merged) { $signals++; if (empty($entrySig[$i - 1]) && empty($entrySig[$i - 2])) $entrySig[$i] = true; else $blocked++; }  // 合一信号且前两根无信号才记录(去重)
        $sepPrev[$sepIdx] = $sep; $sepIdx = ($sepIdx + 1) % $SEP_LOOK;  // 记录分离度进环形历史
    }
    echo "[$bar] bars=$n signals=$signals blocked=$blocked\n"; flush();  // 输出该周期信号统计

    // ===== sim =====
    $eq = $EQ0; $startT = $ts[$W]; $trades = []; $bankrupt = false;  // 权益/起点/交易列表/破产标志
    $i = $W;                                                      // 从窗口满后开始
    while ($i < $n - 1 && !$bankrupt) {                           // 主循环
        if (empty($entrySig[$i])) { $i++; continue; }             // 无入场信号跳过
        $days = (int)floor((($ts[$i] - $startT) / 1000) / 86400); // 已过天数
        $M0 = round(min(1.0 + 3.0 * $days, $CAPM), 2);            // 保证金=1U+3U×天数, 封顶50U
        if ($M0 < 1) $M0 = 1.0;                                   // 保底1U
        if ($M0 > $eq) { if ($eq < 1) { $bankrupt = true; break; } $M0 = round($eq, 2); }  // 保证金不超权益, 权益<1U破产
        $e = $o[$i + 1];                                          // 入场价=下一根开盘
        $notional = $M0 * $LEV; $avg = $e; $adds = 0; $mg = $M0;  // 名义/均价/加仓/保证金
        $fee = $notional * $FEE_MAKER; $funding = 0.0;            // 开仓maker费/资金费
        $tgtPx = $useTP ? $avg * (1 + $TPR) : INF;                // 止盈价(对照配置才启用)
        $liqPx = $useLiq ? $avg * (1 - 1.0 / $LEV + $MMR) : -INF; // 真实爆仓价(对照配置才启用)
        $armed = false; $outcome = null; $exitPx = null; $j = $i + 1;  // 锁仓拉升成立标志/出场结果/出场价/下标
        // 锚定开仓时的锁仓峰区(建仓峰区): 跌破下沿=出货, 上穿上方=锁仓拉升成立
        $c2 = new Chip($NB, $W);                                  // 开仓时点的筹码分布
        for ($k = max(0, $i + 1 - $W); $k <= $i; $k++) $c2->push($l[$k], $h[$k], $v[$k]);  // 用开仓前150根构建
        $pk0 = $c2->peaks();                                      // 开仓时双峰
        if ($pk0 !== null) { $zb0 = $pk0[0][0] * 0.998; $zt0 = $pk0[1][0] * 1.002; }  // 锚定峰区: 下沿=洗盘峰-0.2%, 上沿=拉升峰+0.2%
        else { $zb0 = $e * 0.99; $zt0 = $e * 1.01; }              // 无双峰时用入场价±1%兜底
        while ($j < $n) {                                         // 逐根推进持仓
            $funding += $notional * $fundPerBar;                  // 累计资金费
            // 对照配置: 真实爆仓线 / 止盈+2% (盘中判定)
            if ($useLiq && $l[$j] <= $liqPx) { $outcome = 'LIQ'; $exitPx = $liqPx; break; }  // 触真实爆仓线出场
            if ($useTP && $h[$j] >= $tgtPx) { $outcome = 'WIN'; $exitPx = $tgtPx; break; }   // 触止盈出场
            // 出货峰判定: 收盘曾上穿锁仓峰区上方后, 收盘跌破峰区下沿 → 出货
            if (!$armed && $c[$j] > $zt0) $armed = true;          // 收盘上穿上沿→锁仓拉升成立
            if ($armed && $c[$j] < $zb0) { $outcome = 'SHIP'; $exitPx = ($j + 1 < $n) ? $o[$j + 1] : $c[$j]; break; }  // 成立后跌破下沿→出货峰全平
            if ($j - $i >= $TO_BARS_DAYS * 86400 * 1000 / ($barMin * 60000)) { $outcome = 'TO'; $exitPx = $c[$j]; break; }  // 持仓超14天按根数换算超时平收
            // 加仓: 再现双线合一 + 现价站上峰区上方(锁仓拉升) + 总保证金≤50U
            if (isset($entrySig[$j]) && $c[$j] > $zt0 && $mg + $ADDU <= $CAPM && $j + 1 < $n) {  // 加仓条件
                $ap = $o[$j + 1];                                 // 加仓价=下一根开盘
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
        if ($outcome === null) { $outcome = 'TO'; $j = $n - 1; $exitPx = $c[$j]; }  // 数据耗尽按末根收盘了结
        $gross = ($exitPx - $avg) * $notional / $avg;             // 毛盈亏
        $fee += $notional * $FEE_TAKER;                           // 平仓taker费
        $pnl = $gross - $fee - $funding;                          // 净盈亏
        if ($pnl < -$eq) { $pnl = -$eq; $bankrupt = true; }       // 亏损超权益则清零并破产
        $eq += $pnl;                                              // 更新权益
        if ($eq <= 0.01) $bankrupt = true;                        // 权益近零破产
        $trades[] = ['tin' => $ts[$i], 'tout' => $ts[$j], 'out' => $outcome, 'adds' => $adds,  // 记录交易明细
                     'm0' => $M0, 'mg' => round($mg, 2), 'pnl' => round($pnl, 3), 'eq' => round($eq, 2),  // 开仓保证金/总保证金/盈亏/权益
                     'holdh' => round(($ts[$j] - $ts[$i]) / 3600000.0, 1)];  // 持仓小时
        $i = $j + 1;                                              // 出场后继续
    }
    // ===== 汇总 =====
    $tot = ['n' => count($trades), 'ship' => 0, 'to' => 0, 'win' => 0, 'liq' => 0, 'adds' => 0, 'pnl' => 0.0];  // 汇总容器
    foreach ($trades as $x) {                                     // 逐笔统计
        if ($x['out'] == 'SHIP') $tot['ship']++;                  // 出货峰平仓计数
        if ($x['out'] == 'TO') $tot['to']++;                      // 超时计数
        if ($x['out'] == 'WIN') $tot['win']++;                    // 止盈计数
        if ($x['out'] == 'LIQ') $tot['liq']++;                    // 爆仓计数
        $tot['adds'] += $x['adds']; $tot['pnl'] += $x['pnl'];     // 累加加仓/盈亏
    }
    $tot['pnl'] = round($tot['pnl'], 2);                          // 盈亏保留2位
    $months = []; $daily = [];                                    // 按月/按日汇总
    foreach ($trades as $x) {                                     // 逐笔归组
        $mo = bjday($x['tout']); $mo = substr($mo, 0, 7);         // 出场月份
        $m = &$months[$mo];                                       // 按月引用
        $m['n'] = ($m['n'] ?? 0) + 1;                             // 月笔数
        $m['ship'] = ($m['ship'] ?? 0) + ($x['out'] == 'SHIP' ? 1 : 0);  // 月出货笔数
        $m['to'] = ($m['to'] ?? 0) + ($x['out'] == 'TO' ? 1 : 0); // 月超时笔数
        $m['win'] = ($m['win'] ?? 0) + ($x['out'] == 'WIN' ? 1 : 0);  // 月止盈笔数
        $m['liq'] = ($m['liq'] ?? 0) + ($x['out'] == 'LIQ' ? 1 : 0);  // 月爆仓笔数
        $m['adds'] = ($m['adds'] ?? 0) + $x['adds'];              // 月加仓轮数
        $m['mg'] = round(($m['mg'] ?? 0) + $x['mg'], 2);          // 月保证金合计
        $m['pnl'] = round(($m['pnl'] ?? 0) + $x['pnl'], 2);       // 月盈亏
        $m['eq_end'] = $x['eq'];                                  // 月末权益
        unset($m);                                                // 解除引用
        $d = &$daily[bjday($x['tout'])];                          // 按日引用
        $d['n'] = ($d['n'] ?? 0) + 1;                             // 日笔数
        $d['adds'] = ($d['adds'] ?? 0) + $x['adds'];              // 日加仓
        $d['mg'] = round(($d['mg'] ?? 0) + $x['mg'], 2);          // 日保证金
        $d['pnl'] = round(($d['pnl'] ?? 0) + $x['pnl'], 2);       // 日盈亏
        $d['eq_end'] = $x['eq'];                                  // 日末权益
        unset($d);                                                // 解除引用
    }
    $peak = -INF; $maxdd = 0;                                     // 权益峰值与最大回撤
    foreach ($trades as $x) { if ($x['eq'] > $peak) $peak = $x['eq']; $dd = $peak - $x['eq']; if ($dd > $maxdd) $maxdd = $dd; }  // 逐笔更新
    echo sprintf("[%s] n=%d ship=%d to=%d adds=%d pnl=%.1f eqEnd=%.1f maxdd=%.1f bk=%s\n",  // 输出该周期汇总
        $bar, $tot['n'], $tot['ship'], $tot['to'], $tot['adds'], $tot['pnl'], $eq, $maxdd, $bankrupt ? 'Y' : 'N');
    flush();                                                      // 立即输出
    return [
        'bar' => $bar, 'bars' => $n,                              // 周期与根数
        'span' => [bj($ts[0]), bj($ts[$n - 1])],                  // 数据区间
        'signals' => $signals, 'blocked' => $blocked,             // 信号与去重统计
        'summary' => $tot, 'eq_end' => round($eq, 2), 'maxdd' => round($maxdd, 2),  // 汇总/期末权益/回撤
        'bankrupt' => $bankrupt, 'months' => $months, 'daily' => $daily, 'trades' => $trades,  // 破产/月日汇总/明细
    ];
}

$results = [];                                                    // 各周期结果
foreach (['3m', '5m', '15m', '1h'] as $bar) {                     // 遍历四周期
    $g = load_k($bar);                                            // 加载该周期K线
    $results[$bar]['user'] = sim($bar, $g, false, false);   // 用户配置: 无止盈·无止损·不模拟爆仓
    $results[$bar]['ctrl'] = sim($bar, $g, true, true);     // 对照配置: 止盈+2% + 真实爆仓线
}

$out = [                                                          // 组装输出JSON
    'meta' => [
        'inst' => 'ETH-USDT-SWAP', 'lev' => $LEV, 'eq0' => $EQ0, 'capm' => $CAPM, 'tpr' => 0.02,  // 基础参数
        'params' => '筹码峰战法: 买入=拉升峰与洗盘峰「双线合一」(价差≤0.4%, 先分后合≥1%, 现价在峰区±1%内) 下一根开盘买入 | 加仓=持仓中再现双线合一且现价站上峰区上方 → +1U(下一根开盘), 不限轮数, 总保证金(含加仓)≤50U 停止 | 平仓=出货峰(收盘曾上穿开仓锁仓峰区上方后跌破峰区下沿, 下一根开盘全平) 或 超时14天 | 保证金=1U+3U×已过天数 封顶50U | 100x逐仓 | 起始权益500U 逐笔结转 | 净口径: 开仓maker0.02%+加仓/平仓taker0.05%+资金费0.01%/8h | 筹码峰=150根K线 vol_quote 60格增量直方图, 峰>1.3×均值, 相邻3格合并, 量能前2名: 高价峰=拉升峰 低价峰=洗盘峰 最早期主峰=建仓峰(锁仓不动) | user配置=用户指定(无止盈·无止损·不模拟爆仓, 浮亏照算权益归0即破产); ctrl对照=同信号+止盈价格+2%+真实爆仓线(价格-0.6%)',  // 完整策略口径
    ],
    'results' => $results,                                        // 各周期 user/ctrl 结果
];
file_put_contents('E:/finally-main/web/chippeak_data.json', json_encode($out, JSON_UNESCAPED_UNICODE));  // 写出JSON
echo "DATA OK " . round(memory_get_peak_usage(true) / 1048576) . "MB\n";  // 完成提示与内存峰值
