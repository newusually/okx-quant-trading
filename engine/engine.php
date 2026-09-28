<?php
/**
 * engine/engine.php — 常驻交易引擎 (php-win.exe 隐藏窗口运行, 替代 Go 引擎 + Python watchdog)
 * 2026-09-27 PHP+C++ 架构: 所有计算走 sigcore.dll(C++内存K线库), 所有交易走 OkxClient(PHP curl)
 *
 * 交易铁律(代码级硬锁):
 *   买入   = 15m 与 5m 同现金▲(共振) · 每笔1U · 20X全仓 · 只买 symbollist 权威池
 *   加仓   = 跌的时候(现价<均价)出现 5m 金▲ · 每轮 +1U/3 · 不限轮数
 *   止盈   = 每10秒检测 价格+2%(ROI40%@20X) 达标立即市价全平
 *   止损   = 永不止损
 *   闸门   = 并发持仓12 / 每小时买入3 / 每轮开1仓 / 60分钟冷却 / 有持仓不开
 *
 * 主循环(10秒节拍):
 *   ① 止盈快检(全部持仓)  ② 跌时金▲加仓检测  ③ 持仓台账对账  ④ 5m收盘买入扫描
 *   ⑤ grid_signal 看板快照 + pnl_history 每分钟快照
 */
require_once dirname(__DIR__) . '\\web\\inc\\bootstrap.php';

set_time_limit(0);
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED);

$RUN = true;
$bootFed = false;         // DLL内存库首次喂满
$addSlot = [];            // inst => 已加仓的5m槽位(同一根5m只加一次)
$last5mSlot = 0;          // 上次买入扫描的5m槽
$lastMin = 0;             // 每分钟快照标记
$lastReconcile = 0;

/* 单核机器: 引擎降为低优先级, 让Apache网页响应永远优先 (2026-09-28) */
pclose(popen('powershell -NoProfile -Command "Get-Process -Id ' . getmypid() . ' | ForEach-Object { $_.PriorityClass = \'BelowNormal\' }"', 'r'));
eng_log('INFO', 'engine', '===== PHP+C++ 引擎启动 build 0927P | DLL v' . SigCore::version() . ' | 买入=15m+5m金▲共振·1U·20X·+2%止盈·不止损 =====');

// ---------- 业务函数 ----------

/** 喂DLL内存库: 单合约单周期, 只喂已收盘K线, 缺数据自动拉OKX */
function feedInst(string $inst, string $tf, int $need = 300): void {
    $rows = Klines::engineRows($inst, $tf); // DB 300根, 不足/太旧自动OKX补
    if ($rows) SigCore::feed($inst, $tf, $rows);
}

/** 增量喂(已喂过的合约, 只拉最近3根保持新鲜) */
function feedInstLite(string $inst, string $tf): void {
    try {
        $bars = OkxClient::candles($inst, $tf, 3);
        $closed = array_values(array_filter($bars, fn($b) => $b[6] === 1));
        if ($closed) SigCore::feed($inst, $tf, $closed);
    } catch (Throwable $e) { /* 单合约失败不影响全局 */ }
}

/** 张数: usd保证金 × 20x ÷ (ctVal×价格), 至少1张 */
function sizeFor(string $inst, float $usd, float $px): int {
    static $ct = [];
    if (!isset($ct[$inst])) $ct[$inst] = OkxClient::ctVal($inst);
    $ctVal = $ct[$inst];
    if ($ctVal <= 0 || $px <= 0) return 0;
    return max(1, (int)floor($usd * LOCK_LEVER / ($ctVal * $px)));
}

/** 开仓(买入): 全套下单+台账+流水 */
function openPosition(string $inst, string $why): void {
    $px = OkxClient::lastPrice($inst);
    if ($px <= 0) { eng_log('WARN', 'buy', "$inst 取不到实时价, 放弃"); return; }
    $lev = OkxClient::setLeverageAdaptive($inst, LOCK_LEVER);
    $sz = sizeFor($inst, LOCK_ENTRY_USD, $px);
    $r = OkxClient::marketBuy($inst, $sz);
    if (($r['code'] ?? '') !== '0') {
        $msg = $r['data'][0]['sMsg'] ?? ($r['msg'] ?? 'unknown');
        eng_log('ERROR', 'buy', "开仓下单 $inst sz=$sz 失败: $msg");
        return;
    }
    eng_log('INFO', 'buy', "金▲开仓 $inst sz=$sz (≈" . LOCK_ENTRY_USD . "U保证金·{$lev}x) $why");
    usleep(3000000); // 等 OKX 撮合回填均价
    $pos = OkxClient::position($inst);
    $avg = $pos ? (float)$pos['avgPx'] : $px;
    Db::ex("INSERT INTO position_detail (inst_id,open_time,units,avg_px,last_px,status,ladder_adds,open_bar,buy_strategy)
            VALUES (?,NOW(),?,?,?,'OPEN',0,'5m','gold15_5')", [$inst, (int)($pos['pos'] ?? $sz), $avg, $px]);
    Db::ex("INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,sz,notional_usd,profit,ord_id,remark)
            VALUES (NOW(),?,'buy','long',?,?,?,NULL,?,?)",
        [$inst, $px, (float)($pos['pos'] ?? $sz), (float)($pos['pos'] ?? $sz) * $avg, '', "hybrid open gold15_5 L1 ($why)"]);
    OkxClient::cancelAllAlgos($inst); // 不挂任何止损止盈单
}

/** 加仓: 跌时金▲, 每轮 +1U/3 */
function addPosition(string $inst, array $pos, float $last, string $why): void {
    $sz = sizeFor($inst, LOCK_ADD_USD, $last);
    $r = OkxClient::marketBuy($inst, $sz);
    if (($r['code'] ?? '') !== '0') {
        $msg = $r['data'][0]['sMsg'] ?? ($r['msg'] ?? 'unknown');
        eng_log('ERROR', 'add', "加仓下单 $inst sz=$sz 失败: $msg");
        return;
    }
    Db::ex("UPDATE position_detail SET ladder_adds=ladder_adds+1, last_add_px=?, last_px=? WHERE inst_id=? AND status='OPEN'",
        [$last, $last, $inst]);
    Db::ex("INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,sz,notional_usd,remark)
            VALUES (NOW(),?,'add','long',?,?,?,?)",
        [$inst, $last, $sz, $sz * $last, "gold5m add (跌时金▲ $why) +1U/3"]);
    eng_log('INFO', 'add', "跌时金▲加仓 $inst sz=$sz (+1U/3) last=$last avg={$pos['avgPx']} $why");
}

/** 平仓台账: 关台账 + 写事件流水(真实盈亏由 OKX pos-history 回填统一写入) */
function closeLedger(string $inst, string $action, string $remark): void {
    Db::ex("UPDATE position_detail SET status='CLOSED', close_time=NOW() WHERE inst_id=? AND status='OPEN'", [$inst]);
    Db::ex("DELETE FROM grid_signal WHERE inst_id=?", [$inst]);
    Db::ex("INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,profit,remark)
            VALUES (NOW(),?,?,'long',0,NULL,?)", [$inst, $action, $remark]);
}

/** 止盈快检: 全部持仓 ROI≥40%(=价格+2%@20X) 市价全平 */
function tpCheckAll(): void {
    $positions = OkxClient::positions();
    $alive = [];
    foreach ($positions as $p) {
        if (($p['posSide'] ?? 'net') === 'short') continue;
        $inst = $p['instId'];
        $pos = (float)$p['pos'];
        if ($pos <= 0) continue;
        $alive[$inst] = true;
        $avg = (float)$p['avgPx'];
        $mark = (float)$p['markPx'];
        $last = (float)($p['last'] ?: $mark);
        if ($avg <= 0) continue;
        $markRoi = (float)($p['uplRatio'] ?? 0);
        $lastRoi = ($last / $avg - 1) * LOCK_LEVER;
        if ($markRoi < LOCK_TP_ROI && $lastRoi < LOCK_TP_ROI) continue;
        // 复核后全平
        $r = OkxClient::closePositionMarket($inst);
        if (($r['code'] ?? '') !== '0') {
            $msg = $r['data'][0]['sMsg'] ?? ($r['msg'] ?? 'unknown');
            eng_log('ERROR', 'tp', "止盈全平 $inst 失败: $msg (下轮重试)");
            continue;
        }
        [$pnl, $exitPx] = OkxClient::fillsPnl($inst, 40);
        $p = Db::one("SELECT id FROM position_detail WHERE inst_id=? AND status='OPEN' ORDER BY id DESC LIMIT 1", [$inst]);
        if ($p) Db::ex("UPDATE position_detail SET status='CLOSED', close_time=NOW(), profit=? WHERE id=?", [$pnl, $p['id']]);
        Db::ex("DELETE FROM grid_signal WHERE inst_id=?", [$inst]);
        Db::ex("INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,profit,remark)
                VALUES (NOW(),?,'close','long',?,NULL,?)", [$inst, $exitPx,
                sprintf('10秒快检止盈全平 标记ROI=%.1f%% 现价ROI=%.1f%% ≥%.0f%% (均价%.6f 现价%.6f)', $markRoi * 100, $lastRoi * 100, LOCK_TP_ROI * 100, $avg, $last)]);
        eng_log('INFO', 'tp', sprintf('止盈全平 %s 标记ROI=%.1f%% 现价ROI=%.1f%% exit=%.6f pnl=%.2f(以OKX回填为准)', $inst, $markRoi * 100, $lastRoi * 100, $exitPx, $pnl));
    }
    return; // $alive 由对账使用(见下)
}

/** 台账对账: OKX 已无仓但台账 OPEN → 关闭 */
function reconcile(): void {
    $alive = [];
    foreach (OkxClient::positions() as $p) if ((float)$p['pos'] > 0) $alive[$p['instId']] = true;
    $opens = Db::q("SELECT DISTINCT inst_id FROM position_detail WHERE status='OPEN'");
    foreach ($opens as $o) {
        if (!isset($alive[$o['inst_id']])) {
            closeLedger($o['inst_id'], 'close', '对账平仓 (OKX已无持仓, 台账自动关闭)');
            eng_log('INFO', 'reconcile', '对账平仓 ' . $o['inst_id']);
        }
    }
}

/** 闸门检查(买入前) */
function gatesOk(int &$openedThisScan): bool {
    $openPos = (int)Db::scalar("SELECT COUNT(*) FROM position_detail WHERE status='OPEN'");
    if ($openPos >= LOCK_MAX_POSITIONS) return false;
    $buysH = (int)Db::scalar("SELECT COUNT(*) FROM trade_flow WHERE action='buy' AND trade_time>NOW()-INTERVAL 60 MINUTE");
    if ($buysH >= LOCK_MAX_BUYS_HOUR) return false;
    if ($openedThisScan >= LOCK_MAX_NEW_SCAN) return false;
    return true;
}

/** 5m 收盘买入扫描: 全池 → 15m+5m 金▲共振 → 开仓 (每25个穿插一次止盈快检, 保证10秒级盯盘不中断)
 *  2026-09-28 单核优化: 持仓检查改一次positions()批量(原来294次单查HTTP把CPU打满) */
function buyScan(array $pool): void {
    $opened = 0;
    $i = 0;
    $alive = [];
    try { foreach (OkxClient::positions() as $p) if ((float)($p['pos'] ?? 0) > 0) $alive[$p['instId']] = true; } catch (Throwable $e) {}
    foreach ($pool as $inst) {
        $i++;
        if ($i % 25 === 0) tpCheckAll(); // 扫描期间保持止盈快检节奏
        usleep(2000);                    // 单核让步
        if (!gatesOk($opened)) break;
        // ① 有持仓不开(批量缓存)
        if (!empty($alive[$inst])) continue;
        // ② 冷却
        $n = (int)Db::scalar("SELECT COUNT(*) FROM trade_flow WHERE inst_id=? AND action='buy' AND trade_time>NOW()-INTERVAL ? MINUTE", [$inst, LOCK_COOLDOWN_MIN]);
        if ($n > 0) continue;
        // ③ 黄金坑共振(六维: 金▲动能+缠论分型+CCI+ADX+MACD+KDJ, 数据已在DLL内存)
        [$f5, $s5, $i5] = SigCore::storePit($inst, '5m');
        if (!$f5) continue;
        [$f15, $s15, $i15] = SigCore::storePit($inst, '15m');
        if (!$f15) continue;
        Db::ex("INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,remark) VALUES (NOW(),?,'signal','long',?)",
            [$inst, "黄金坑共振命中 5m[{$s5}分] 15m[{$s15}分]"]);
        openPosition($inst, "15m+5m黄金坑共振");
        $opened++;
    }
}

/** 持仓看板快照(grid_signal) + 跌时金▲加仓检测 */
function managePositions(): void {
    $positions = OkxClient::positions();
    foreach ($positions as $p) {
        $inst = $p['instId'];
        $pos = (float)$p['pos'];
        if ($pos <= 0) continue;
        $avg = (float)$p['avgPx'];
        $last = (float)($p['last'] ?: $p['markPx']);
        $uplRatio = (float)($p['uplRatio'] ?? 0);
        $open = Db::one("SELECT ladder_adds FROM position_detail WHERE inst_id=? AND status='OPEN' ORDER BY id DESC LIMIT 1", [$inst]);
        $adds = $open ? (int)$open['ladder_adds'] : 0;
        $tpPx = $avg * (1 + 0.02);
        // 加仓检测: 跌(现价<均价) + 5m黄金坑 + 同一根5m只加一次
        // 2026-09-28 防失控(ARX 84笔事故): 单合约30分钟冷却 + 全局每小时≤6次
        global $addSlot;
        $slot5 = (int)(floor(time() / 300) * 300);
        $canAdd = false;
        $why = '';
        if ($avg > 0 && $last < $avg && ($addSlot[$inst] ?? 0) !== $slot5) {
            $lastAdd = Db::scalar("SELECT UNIX_TIMESTAMP(MAX(trade_time)) FROM trade_flow WHERE inst_id=? AND action='add'", [$inst]);
            $coolOk = ($lastAdd === '' || $lastAdd === null) || (time() - (int)$lastAdd >= 1800);
            $hourOk = (int)Db::scalar("SELECT COUNT(*) FROM trade_flow WHERE action='add' AND trade_time>NOW()-INTERVAL 60 MINUTE") < 6;
            if ($coolOk && $hourOk) {
                feedInstLite($inst, '5m');
                [$f5, $sc5, $i5] = SigCore::storePit($inst, '5m');
                if ($f5) { $canAdd = true; $why = $i5; }
            }
        }
        $state = $canAdd ? '★金▲待加仓' : ($uplRatio >= LOCK_TP_ROI ? '★止盈达标' : ($last < $avg ? '等待跌时金▲' : '持有(现价≥均价)'));
        $note = sprintf('金▲版 | 跌时5m金▲加仓+1U/3 | 止盈线%.6f(+2%%=ROI40%%@20X) | 不止损 | 现价ROI=%.1f%%', $tpPx, $uplRatio * 100);
        Db::ex("REPLACE INTO grid_signal (inst_id,updated,last_px,avg_px,units,ladder_adds,next_add_usd,tp_px,tp_pct,state,note,dist_down_pct)
                VALUES (?,NOW(),?,?,?,?,?,?,2.0,?,?,0)",
            [$inst, $last, $avg, (int)$pos, $adds, LOCK_ADD_USD, $tpPx, $state, $note]);
        if ($canAdd) {
            $addSlot[$inst] = $slot5;
            Db::ex("INSERT INTO grid_signal_log (log_time,inst_id,level,last_px,trigger_px,add_usd,kind,state,note)
                    VALUES (NOW(),?,0,?,?,?,?,?,?)", [$inst, $last, $last, LOCK_ADD_USD, 'gold', '触发加仓', $why]);
            addPosition($inst, ['avgPx' => $avg], $last, $why);
        }
    }
}

/** 每分钟快照: pnl_history(网页顶部"仓位"数据源) */
function minuteSnap(): void {
    $posM = 0.0; $upl = 0.0; $n = 0;
    foreach (OkxClient::positions() as $p) {
        if ((float)$p['pos'] <= 0) continue;
        $n++;
        try {
            $imr = (float)($p['imr'] ?? 0);
            if ($imr <= 0) $imr = (float)$p['notionalUsd'] / LOCK_LEVER;
            $posM += $imr;
            $upl += (float)($p['upl'] ?? 0);
        } catch (Throwable $e) {}
    }
    Db::ex("INSERT INTO pnl_history (ts,pnl,open_pos,pos_margin,snap_time) VALUES (?,?,?,?,NOW())",
        [time(), round($upl, 4), $n, round($posM, 4)]);
}

// ---------- 主循环 ----------
while (true) {
    hb('engine');            // 心跳(供 guard.exe 判活/判卡死)
    $t0 = microtime(true);
    try {
        $now = time();
        $slot5 = (int)(floor($now / 300) * 300);

        // ① 启动喂库: 池内全部 × 5m/15m 喂 DLL 内存(一次性, 数据此后滞留 C++ 内存)
        if (!$bootFed) {
            $pool = SymbolPool::cached();
            eng_log('INFO', 'boot', '开始喂DLL内存库: ' . count($pool) . ' 合约 × 5m/15m …');
            $fed = 0;
            foreach ($pool as $inst) {
                feedInst($inst, '5m');
                feedInst($inst, '15m');
                $fed++;
                if ($fed % 20 === 0) hb('engine');   // 喂库阶段也要心跳(否则被守护误判卡死)
                if ($fed % 50 === 0) eng_log('INFO', 'boot', "内存库喂入进度 $fed/" . count($pool));
            }
            $bootFed = true;
            eng_log('INFO', 'boot', 'DLL内存库喂入完成: 序列数=' . SigCore::storeStats());
        }

        // ② 止盈快检 → 已由 C++ tphub.exe 实时接管(5秒节拍), PHP侧停用防双卖 (2026-09-28)
        // tpCheckAll();

        // ③ 持仓管理 + 跌时金▲加仓(每10秒)
        managePositions();

        // ④ 对账(每60秒)
        if ($now - $lastReconcile >= 60) {
            $lastReconcile = $now;
            reconcile();
        }

        // ⑤ 每分钟快照
        if ((int)($now / 60) !== $lastMin) {
            $lastMin = (int)($now / 60);
            try { minuteSnap(); } catch (Throwable $e) {}
        }

        // ⑥ 5m 收盘买入扫描(新5m槽触发)
        if ($slot5 !== $last5mSlot && $bootFed) {
            $last5mSlot = $slot5;
            $pool = SymbolPool::cached();
            if ($pool) {
                // 全池增量喂最新收盘K线(单核优化: 15m只在15m收盘槽喂, DLL缓存返回旧信号是正确语义)
                $is15 = ($slot5 % 900 === 0);
                foreach ($pool as $inst) {
                    feedInstLite($inst, '5m');
                    if ($is15) feedInstLite($inst, '15m');
                    usleep(2000);
                }
                buyScan($pool);
            }
        }
    } catch (Throwable $e) {
        eng_log('ERROR', 'engine', '主循环异常: ' . $e->getMessage() . ' @ line ' . $e->getLine());
    }

    // 精确睡到下一个10秒边界(过期则兜底小睡, 防止忙转)
    $next = ceil(microtime(true) / 10) * 10;
    if ($next - microtime(true) > 0.5) time_sleep_until($next);
    else usleep(300000);
}
