// ==================================================================
// trade_engine.cpp — C++ 交易引擎主循环 (逐字迁移自 engine/engine.php)
//   10 秒节拍: 持仓管理+跌时3m金▲加仓 → 60秒对账 → 每分钟快照 → 新5m槽全池扫描买入
//   止盈已由 tphub.exe 实时接管(本组件不碰, 防双卖)
//   闸门: 持仓12 / 每小时买3 / 每轮开1 / 同合约冷却60分(硬锁, 不可绕过)
// ==================================================================
#include "tradehub.h"
#include <cstdio>
#include <cmath>
#include <ctime>
#include <map>
#include <set>

// inst → 已加仓的 3m 槽位(同一根 3m 只加一次)
static std::map<std::string, long long> g_addSlot;

// ---------------- 喂库 ----------------
// 单合约单周期: DB 取数(不足自动补) → 推入 sigcore 内存库
static void feed_inst(const std::string& inst, const std::string& tf) {
    std::vector<Bar> rows = kl_engine_rows(inst, tf);
    if (!rows.empty()) sig_feed(inst, tf, rows);
}

// 增量喂: 只拉最近 3 根已收盘K线
static void feed_inst_lite(const std::string& inst, const std::string& tf) {
    std::vector<std::vector<std::string>> bars;
    if (!okx_candles(inst, tf, 3, 0, false, false, "after", bars)) return;
    std::vector<Bar> rows;
    for (auto& b : bars) {
        if (b.size() < 9 || b[8] != "1") continue;   // confirm==1 已收盘
        Bar r;
        r.tms = atoll_s(b[0]); r.o = atof_s(b[1]); r.h = atof_s(b[2]);
        r.l = atof_s(b[3]);    r.c = atof_s(b[4]); r.v = atof_s(b[5]);
        rows.push_back(r);
    }
    if (!rows.empty()) sig_feed(inst, tf, rows);
}

// ---------------- 张数: usd保证金 × 实际杠杆 ÷ (ctVal×价格), 至少1张 ----------------
// 注意: 必须传**实际生效杠杆**(okx_set_leverage_adaptive 的返回值), 不能用 LOCK_LEVER 常量 ——
//       OKX 上限 <20x 的合约会降档(实测 LIGHT=10x), 若仍按 20x 算张数, 保证金会翻倍(1U→2U)。
static int size_for(const std::string& inst, double usd, double px, int lever) {
    static std::map<std::string, double> ct;
    auto it = ct.find(inst);
    if (it == ct.end()) { ct[inst] = okx_ctval(inst); it = ct.find(inst); }
    double ctVal = it->second;
    if (ctVal <= 0 || px <= 0) return 0;
    if (lever < 1) lever = LOCK_LEVER;
    int sz = (int)floor(usd * lever / (ctVal * px));
    return sz < 1 ? 1 : sz;
}

// ---------------- 开仓: 下单 + 台账 + 流水 ----------------
static void open_position(const std::string& inst, const std::string& why) {
    double px = okx_last_price(inst);
    if (px <= 0) { eng_log("WARN", "buy", inst + " 取不到实时价, 放弃"); return; }
    int lev = okx_set_leverage_adaptive(inst, LOCK_LEVER);
    int sz = size_for(inst, LOCK_ENTRY_USD, px, lev);
    if (sz <= 0) { eng_log("ERROR", "buy", inst + " 张数计算为0, 放弃"); return; }

    std::string resp;
    if (!okx_market_buy(inst, sz, resp) || resp.find("\"code\":\"0\"") == std::string::npos) {
        std::string msg = j_str(resp, "sMsg");
        if (msg.empty()) msg = j_str(resp, "msg");
        if (msg.empty()) msg = "unknown";
        eng_log("ERROR", "buy", "开仓下单 " + inst + " sz=" + std::to_string(sz) + " 失败: " + msg);
        return;
    }
    char lg[256];
    snprintf(lg, sizeof(lg), "金▲开仓 %s sz=%d (≈%.1fU保证金·%dx) %s",
             inst.c_str(), sz, (double)LOCK_ENTRY_USD, lev, why.c_str());
    eng_log("INFO", "buy", lg);

    Sleep(3000);                       // 等 OKX 撮合回填均价
    std::string pobj;
    double avg = px, posSz = (double)sz;
    if (okx_position(inst, pobj)) {
        double a = j_num(pobj, "avgPx"); if (a > 0) avg = a;
        double p = j_num(pobj, "pos");   if (p > 0) posSz = p;
    }
    char sql[1024];
    snprintf(sql, sizeof(sql),
        "INSERT INTO position_detail (inst_id,open_time,units,avg_px,last_px,status,ladder_adds,open_bar,buy_strategy)"
        " VALUES ('%s',NOW(),%d,%.10g,%.10g,'OPEN',0,'5m','gold15_5')",
        inst.c_str(), (int)posSz, avg, px);
    db_ex(sql);
    snprintf(sql, sizeof(sql),
        "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,sz,notional_usd,profit,ord_id,remark)"
        " VALUES (NOW(),'%s','buy','long',%.10g,%.10g,%.10g,NULL,'','hybrid open gold15_5 (%s)')",
        inst.c_str(), px, posSz, posSz * avg, sqlesc(why).substr(0, 200).c_str());
    db_ex(sql);
    okx_cancel_all_algos(inst);        // 不挂任何止损止盈单
}

// ---------------- 加仓: 跌时金▲, 每轮 +1U/3 ----------------
static void add_position(const std::string& inst, double avg, double last, const std::string& why) {
    int lev = okx_set_leverage_adaptive(inst, LOCK_LEVER);   // 实际生效杠杆(降档合约保护: 防保证金翻倍)
    int sz = size_for(inst, LOCK_ADD_USD, last, lev);
    if (sz <= 0) return;
    std::string resp;
    if (!okx_market_buy(inst, sz, resp) || resp.find("\"code\":\"0\"") == std::string::npos) {
        std::string msg = j_str(resp, "sMsg");
        if (msg.empty()) msg = j_str(resp, "msg");
        if (msg.empty()) msg = "unknown";
        eng_log("ERROR", "add", "加仓下单 " + inst + " sz=" + std::to_string(sz) + " 失败: " + msg);
        return;
    }
    char sql[1024];
    snprintf(sql, sizeof(sql),
        "UPDATE position_detail SET ladder_adds=ladder_adds+1, last_add_px=%.10g, last_px=%.10g"
        " WHERE inst_id='%s' AND status='OPEN'", last, last, inst.c_str());
    db_ex(sql);
    snprintf(sql, sizeof(sql),
        "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,sz,notional_usd,remark)"
        " VALUES (NOW(),'%s','add','long',%.10g,%d,%.10g,'%s')",
        inst.c_str(), last, sz, sz * last,
        sqlesc("gold5m add (跌时金▲ " + why + ") +1U/3").substr(0, 400).c_str());
    db_ex(sql);
    char lg[256];
    snprintf(lg, sizeof(lg), "跌时金▲加仓 %s sz=%d (+1U/3 · %dx) last=%.6g avg=%.6g %s",
             inst.c_str(), sz, lev, last, avg, why.c_str());
    eng_log("INFO", "add", lg);
}

// ---------------- 平仓台账: 关台账 + 事件流水(真盈亏由 OKX 回填) ----------------
static void close_ledger(const std::string& inst, const char* action, const std::string& remark) {
    db_ex("UPDATE position_detail SET status='CLOSED', close_time=NOW() WHERE inst_id='" + inst + "' AND status='OPEN'");
    db_ex("DELETE FROM grid_signal WHERE inst_id='" + inst + "'");
    char sql[1024];
    snprintf(sql, sizeof(sql),
        "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,profit,remark)"
        " VALUES (NOW(),'%s','%s','long',0,NULL,'%s')",
        inst.c_str(), action, sqlesc(remark).substr(0, 400).c_str());
    db_ex(sql);
}

// ---------------- 台账对账: OKX 已无仓但台账 OPEN → 关闭 ----------------
static void reconcile() {
    std::set<std::string> alive;
    std::vector<std::string> objs;
    if (okx_positions(objs))
        for (auto& o : objs) if (j_num(o, "pos") > 0) alive.insert(j_str(o, "instId"));
    RowSet rs = db_q("SELECT DISTINCT inst_id FROM position_detail WHERE status='OPEN'");
    if (!rs.ok) return;
    for (auto& r : rs.rows) {
        if (r.empty() || r[0].empty()) continue;
        if (alive.count(r[0])) continue;
        close_ledger(r[0], "close", "对账平仓 (OKX已无持仓, 台账自动关闭)");
        eng_log("INFO", "reconcile", "对账平仓 " + r[0]);
    }
}

// ---------------- 闸门检查(买入前) ----------------
static bool gates_ok(int opened_this_scan) {
    bool ok = false;
    int openPos = (int)atoll_s(db_scalar("SELECT COUNT(*) FROM position_detail WHERE status='OPEN'", ok));
    if (openPos >= LOCK_MAX_POSITIONS) return false;
    int buysH = (int)atoll_s(db_scalar(
        "SELECT COUNT(*) FROM trade_flow WHERE action='buy' AND trade_time>NOW()-INTERVAL 60 MINUTE", ok));
    if (buysH >= LOCK_MAX_BUYS_HOUR) return false;
    if (opened_this_scan >= LOCK_MAX_NEW_SCAN) return false;
    return true;
}

// ---------------- 5m 收盘买入扫描: 全池 → 5m+3m 金▲共振 → 开仓 ----------------
//   口径(2026-09-28 用户指定): 只用 5m 与 3m 两周期各自的金▲底部动能锚(KE>=该合约底部KE的75分位,
//   与引擎同源自适应, 不用固定 KE 绝对值 —— 实测 KE 含成交量量纲, 跨合约不可比)。
//   实测(10合约/24.5天/实盘口径): 5m+3m 共振 +0.190%/胜率57% > 单5m 0.172%/54% > 现状5m+15m六维 0.123%/52%。
static void buy_scan(const std::vector<std::string>& pool) {
    int opened = 0, i = 0;
    std::set<std::string> alive;
    std::vector<std::string> objs;
    if (okx_positions(objs))
        for (auto& o : objs) if (j_num(o, "pos") > 0) alive.insert(j_str(o, "instId"));

    for (auto& inst : pool) {
        if (!g_run) break;
        i++;
        Sleep(1);                              // 单核让步
        if (!gates_ok(opened)) break;
        if (alive.count(inst)) continue;        // 有持仓不开
        bool ok = false;
        int n = (int)atoll_s(db_scalar("SELECT COUNT(*) FROM trade_flow WHERE inst_id='" + inst +
             "' AND action='buy' AND trade_time>NOW()-INTERVAL " + std::to_string(LOCK_COOLDOWN_MIN) + " MINUTE", ok));
        if (n > 0) continue;                    // 冷却中
        double k5 = 0.0, k3 = 0.0;
        std::string i5, i3;
        if (!sig_store_gold(inst, "5m", k5, i5)) continue;
        feed_inst_lite(inst, "3m");             // 仅对 5m 已命中的合约补最新 3m(单合约 1 请求)
        if (!sig_store_gold(inst, "3m", k3, i3)) continue;
        std::string remark = "金▲共振命中 5m[KE=" + jnum(k5) + "] 3m[KE=" + jnum(k3) + "]";
        char sql[1024];
        snprintf(sql, sizeof(sql),
            "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,remark)"
            " VALUES (NOW(),'%s','signal','long','%s')",
            inst.c_str(), sqlesc(remark).c_str());
        db_ex(sql);
        open_position(inst, "5m+3m金▲共振");
        opened++;
    }
}

// ---------------- 持仓看板快照 + 跌时金▲加仓 ----------------
static void manage_positions() {
    std::vector<std::string> objs;
    if (!okx_positions(objs)) return;
    long long now = (long long)time(nullptr);
    for (auto& p : objs) {
        std::string inst = j_str(p, "instId");
        double pos = j_num(p, "pos");
        if (inst.empty() || pos <= 0) continue;
        if (j_str(p, "posSide") == "short") continue;
        double avg = j_num(p, "avgPx");
        double last = j_num(p, "last");
        if (last <= 0) last = j_num(p, "markPx");
        double uplRatio = j_num(p, "uplRatio");
        bool ok = false;
        std::string addsS = db_scalar("SELECT ladder_adds FROM position_detail WHERE inst_id='" + inst +
                                      "' AND status='OPEN' ORDER BY id DESC LIMIT 1", ok);
        int adds = (int)atoll_s(addsS);
        double tpPx = avg * (1.0 + LOCK_TP_PCT);

        // 加仓检测: 跌(现价<均价) + 3m金▲ + 同根3m只加一次 (2026-09-28 用户指定: 加仓走 3m)
        // 防失控: 单合约 30 分钟冷却 + 全局每小时 ≤6 次
        long long slot3 = (long long)(floor((double)now / 180.0) * 180.0);
        bool canAdd = false;
        std::string why;
        auto sit = g_addSlot.find(inst);
        bool slotUsed = (sit != g_addSlot.end() && sit->second == slot3);
        if (avg > 0 && last < avg && !slotUsed) {
            std::string lastAddS = db_scalar("SELECT UNIX_TIMESTAMP(MAX(trade_time)) FROM trade_flow"
                                             " WHERE inst_id='" + inst + "' AND action='add'", ok);
            long long lastAdd = lastAddS.empty() ? 0 : atoll_s(lastAddS);
            bool coolOk = (lastAdd == 0) || (now - lastAdd >= 1800);
            int addsH = (int)atoll_s(db_scalar(
                "SELECT COUNT(*) FROM trade_flow WHERE action='add' AND trade_time>NOW()-INTERVAL 60 MINUTE", ok));
            bool hourOk = addsH < 6;
            if (coolOk && hourOk) {
                feed_inst_lite(inst, "3m");
                double kk = 0.0;
                std::string inf;
                if (sig_store_gold(inst, "3m", kk, inf)) { canAdd = true; why = inf; }
            }
        }

        // 止盈状态/文案: 与 tphub 判定同口径(价格 +2%), 并显示实际杠杆(可能被降档)
        double lever = j_num(p, "lever"); if (lever <= 0) lever = LOCK_LEVER;
        bool tpHit = (last > 0 && avg > 0) ? (last >= tpPx) : (uplRatio >= LOCK_TP_ROI);
        const char* state = canAdd ? "★金▲待加仓"
                          : (tpHit ? "★止盈达标"
                          : (last < avg ? "等待跌时金▲" : "持有(现价≥均价)"));
        char note[512];
        snprintf(note, sizeof(note),
            "金▲版 | 跌时3m金▲加仓+1U/3 | 止盈线%.6f(价格+2%%; %.0fx→ROI%.0f%%) | 不止损 | 现价ROI=%.1f%%",
            tpPx, lever, LOCK_TP_PCT * 100.0 * lever, uplRatio * 100.0);
        char sql[2048];
        snprintf(sql, sizeof(sql),
            "REPLACE INTO grid_signal (inst_id,updated,last_px,avg_px,units,ladder_adds,next_add_usd,"
            "tp_px,tp_pct,state,note,dist_down_pct)"
            " VALUES ('%s',NOW(),%.10g,%.10g,%d,%d,%.10g,%.10g,2.0,'%s','%s',0)",
            inst.c_str(), last, avg, (int)pos, adds, (double)LOCK_ADD_USD, tpPx,
            state, sqlesc(note).substr(0, 180).c_str());
        db_ex(sql);

        if (canAdd) {
            g_addSlot[inst] = slot3;
            snprintf(sql, sizeof(sql),
                "INSERT INTO grid_signal_log (log_time,inst_id,level,last_px,trigger_px,add_usd,kind,state,note)"
                " VALUES (NOW(),'%s',0,%.10g,%.10g,%.10g,'gold','触发加仓','%s')",
                inst.c_str(), last, last, (double)LOCK_ADD_USD, sqlesc(why).substr(0, 180).c_str());
            db_ex(sql);
            add_position(inst, avg, last, why);
        }
    }
}

// ---------------- 每分钟快照: pnl_history ----------------
static void minute_snap() {
    std::vector<std::string> objs;
    if (!okx_positions(objs)) return;
    double posM = 0.0, upl = 0.0;
    int n = 0;
    for (auto& p : objs) {
        if (j_num(p, "pos") <= 0) continue;
        n++;
        double imr = j_num(p, "imr");
        if (imr <= 0) imr = j_num(p, "notionalUsd") / (double)LOCK_LEVER;
        posM += imr;
        upl += j_num(p, "upl");
    }
    char sql[512];
    snprintf(sql, sizeof(sql),
        "INSERT INTO pnl_history (ts,pnl,open_pos,pos_margin,snap_time) VALUES (%lld,%.4f,%d,%.4f,NOW())",
        (long long)time(nullptr), upl, n, posM);
    db_ex(sql);
}

// ---------------- 主循环 ----------------
void engine_loop() {
    bool bootFed = false;
    long long last5mSlot = 0, lastMin = 0, lastReconcile = 0;

    while (g_run) {
        trade_hb("tradehub");
        try {
            long long now = (long long)time(nullptr);
            long long slot5 = (long long)(floor((double)now / 300.0) * 300.0);

            // ① 启动喂库: 池内全部 × 5m/3m 喂入内存(数据此后滞留本进程)
            if (!bootFed) {
                std::vector<std::string> pool = pool_cached();
                eng_log("INFO", "boot", "开始喂DLL内存库: " + std::to_string(pool.size()) + " 合约 × 5m/3m …");
                int fed = 0;
                for (auto& inst : pool) {
                    if (!g_run) break;
                    feed_inst(inst, "5m");
                    feed_inst(inst, "3m");
                    fed++;
                    if (fed % 20 == 0) trade_hb("tradehub");   // 喂库阶段也心跳
                    if (fed % 50 == 0)
                        eng_log("INFO", "boot", "内存库喂入进度 " + std::to_string(fed) + "/" + std::to_string(pool.size()));
                }
                bootFed = true;
                eng_log("INFO", "boot", "DLL内存库喂入完成: 序列数=" + std::to_string(sig_store_stats()));
            }

            // ② 止盈由 tphub.exe 实时接管, 此处不做(防双卖)

            // ③ 持仓管理 + 跌时金▲加仓(每10秒)
            manage_positions();

            // ④ 对账(每60秒)
            if (now - lastReconcile >= 60) { lastReconcile = now; reconcile(); }

            // ⑤ 每分钟快照
            if (now / 60 != lastMin) { lastMin = now / 60; minute_snap(); }

            // ⑥ 新 5m 槽 → 全池增量喂(5m/3m) + 买入扫描
            if (slot5 != last5mSlot && bootFed) {
                last5mSlot = slot5;
                std::vector<std::string> pool = pool_cached();
                if (!pool.empty()) {
                    for (auto& inst : pool) {
                        if (!g_run) break;
                        feed_inst_lite(inst, "5m");
                        feed_inst_lite(inst, "3m");
                        Sleep(1);
                    }
                    buy_scan(pool);
                }
            }
        } catch (...) {
            eng_log("ERROR", "engine", "主循环异常(C++ exception)");
        }

        // 睡到下一个 10 秒边界(过期则兜底小睡, 防忙转)
        long long rem = 10 - ((long long)time(nullptr) % 10);
        if (rem > 1) Sleep((DWORD)(rem * 1000));
        else Sleep(300);
    }
    eng_log("INFO", "engine", "引擎主循环退出");
}
