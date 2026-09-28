/* ==================================================================
 * trade_engine.cpp — C++ 交易引擎主循环 (逐字迁移自 engine/engine.php)
 *
 * 【文件职责】
 *   交易决策核心：10 秒节拍依次执行——
 *   ① 启动喂库: 池内全量合约 × 5m/3m 喂入 sigcore.dll 内存库(约502序列)
 *   ② 持仓管理 + 跌时3m金▲加仓(每10秒)
 *   ③ 60 秒对账(OKX 无仓但台账 OPEN → 自动关台账)
 *   ④ 每分钟 pnl 快照(pnl_history 表)
 *   ⑤ 新 5m 槽: 全池增量喂库(5m/3m) + buy_scan 买入扫描
 *   止盈由 tphub.exe 实时接管(本组件绝不卖, 防双卖); 止损完全禁用。
 *
 * 【函数清单】
 *   feed_inst()      单合约单周期: DB 取数(不足自动补) → 喂内存库
 *   feed_inst_lite() 增量喂: 只拉最近 3 根已收盘K线(单合约1请求)
 *   size_for()       张数计算: usd×实际杠杆÷(ctVal×价格), 至少1张
 *   open_position()  开仓: 设杠杆→算张数→市价买→台账+流水→撤条件单
 *   add_position()   加仓: 市价买 +1U → 更新台账加仓次数 + 流水
 *   close_ledger()   平仓台账: 关台账 + 删网格信号 + 事件流水
 *   reconcile()      对账: OKX 已无仓但台账 OPEN → 关闭
 *   gates_ok()       买入前闸门检查(持仓数/每小时买入数/单扫描数)
 *   buy_scan()       买入扫描: 全池 → 5m金▲命中 → 拉3m验金▲ → 双周期共振开仓
 *   manage_positions() 持仓看板快照(grid_signal) + 跌时3m金▲加仓判定
 *   minute_snap()    每分钟快照: 占用保证金/浮盈 → pnl_history
 *   engine_loop()    主循环: 10 秒对齐节拍
 *
 * 【数据流】
 *   OKX candles → feed_inst_lite → sigcore 内存库 → sig_store_gold(金▲)
 *   金▲共振 → gates_ok 六重闸门(持仓≤12/时买≤3/单扫≤1/60分冷却/
 *   大盘熔断ETH-BTC 15m×4根跌>1%/只买白名单symbol_pool) → okx_set_leverage_adaptive
 *   (返回的实际杠杆参与计算, 不可用 LOCK_LEVER 常量) → okx_market_buy
 *   → position_detail/trade_flow 台账; 加仓: 网格跌档(跌破上次加仓价 add_dip_pct%) → +1U,
 *   30分钟冷却 + 每小时≤6次 (2026-09-29 用户拍板: 回测收益+59%/次数-40%/最差减半)
 * ================================================================== */
// ==================================================================
// trade_engine.cpp — C++ 交易引擎主循环 (逐字迁移自 engine/engine.php)
//   10 秒节拍: 持仓管理+跌时3m金▲加仓 → 60秒对账 → 每分钟快照 → 新5m槽全池扫描买入
//   止盈已由 tphub.exe 实时接管(本组件不碰, 防双卖)
//   闸门: 持仓12 / 每小时买3 / 每轮开1 / 同合约冷却60分(硬锁, 不可绕过)
// ==================================================================
#include "tradehub.h"                          // 本组件公共声明(LOCK_* 硬锁/OKX/数据层)
#include <cstdio>                              // snprintf
#include <cmath>                               // floor
#include <ctime>                               // time
#include <map>                                 // std::map(加仓槽位/ctVal缓存)
#include <set>                                 // std::set(存活持仓集合)

// inst → 已加仓的 3m 槽位(同一根 3m 只加一次)
static std::map<std::string, long long> g_addSlot;   // 加仓槽位表(保留: 兼容旧数据结构, 现行判定走价格网格)

// ---------------- 喂库 ----------------
// 单合约单周期: DB 取数(不足自动补) → 推入 sigcore 内存库
static void feed_inst(const std::string& inst, const std::string& tf) {   // 合约/周期
    std::vector<Bar> rows = kl_engine_rows(inst, tf);   // 从 DB 取最近300根(缺自动补)
    if (!rows.empty()) sig_feed(inst, tf, rows);        // 非空才推入 sigcore 内存库
}

// 增量喂: 只拉最近 3 根已收盘K线
static void feed_inst_lite(const std::string& inst, const std::string& tf) {   // 合约/周期
    std::vector<std::vector<std::string>> bars;         // OKX 原始K线行
    if (!okx_candles(inst, tf, 3, 0, false, false, "after", bars)) return;   // 拉 3 根失败则放弃
    std::vector<Bar> rows;                              // 已收盘 Bar 数组
    for (auto& b : bars) {                              // 遍历每根K线
        if (b.size() < 9 || b[8] != "1") continue;      // confirm==1 已收盘
        Bar r;                                          // 构造 Bar 结构
        r.tms = atoll_s(b[0]); r.o = atof_s(b[1]); r.h = atof_s(b[2]);   // 时间戳/开/高
        r.l = atof_s(b[3]);    r.c = atof_s(b[4]); r.v = atof_s(b[5]);   // 低/收/量
        rows.push_back(r);                              // 收入数组
    }
    if (!rows.empty()) sig_feed(inst, tf, rows);        // 非空推入 sigcore 内存库
}

// ---------------- 张数: usd保证金 × 实际杠杆 ÷ (ctVal×价格), 至少1张 ----------------
// 注意: 必须传**实际生效杠杆**(okx_set_leverage_adaptive 的返回值), 不能用 LOCK_LEVER 常量 ——
//       OKX 上限 <20x 的合约会降档(实测 LIGHT=10x), 若仍按 20x 算张数, 保证金会翻倍(2U→4U)。
static int size_for(const std::string& inst, double usd, double px, int lever) {   // 合约/保证金/价格/实际杠杆
    static std::map<std::string, double> ct;            // ctVal(每张面值)进程内缓存
    auto it = ct.find(inst);                            // 查缓存
    if (it == ct.end()) { ct[inst] = okx_ctval(inst); it = ct.find(inst); }   // 无缓存则拉一次并入
    double ctVal = it->second;                          // 每张合约面值
    if (ctVal <= 0 || px <= 0) return 0;                // 面值/价格非法 → 无法计算
    if (lever < 1) lever = LOCK_LEVER;                  // 杠杆异常兜底为 20x
    int sz = (int)floor(usd * lever / (ctVal * px));    // 张数 = 保证金×杠杆 ÷ 名义单张价值
    return sz < 1 ? 1 : sz;                             // 至少 1 张
}

// ---------------- 金额配置: trade_cfg.json(与 exe 同目录, 改文件即热生效) ----------------
// 文件格式: {"entry_usd":2.0, "add_usd":1.0}   缺文件/缺键/非法值 → 沿用当前值(初始=LOCK_ 硬锁默认)
// 每 10 秒重读一次文件, 保存后下一节拍生效, 无需重启进程; 值变化会落 logs 表审计。
static double g_entryUsd = LOCK_ENTRY_USD;           // 当前买入保证金(USDT, 初始=硬锁 2U)
static double g_addUsd   = LOCK_ADD_USD;             // 当前加仓保证金(USDT, 初始=硬锁 1U)
static double g_addDip   = 0.005;                    // 网格跌档阈值(小数, 默认 0.5%: 现价须跌破上次加仓价 0.5% 才允许再加)
static void cfg_reload() {                           // 热加载金额配置(引擎每轮调用)
    static time_t lastChk = 0;                       // 上次实际读文件时间(10s 节流)
    time_t now = time(nullptr);                      // 当前时间
    if (now - lastChk < 10) return;                  // 不足 10s 跳过本轮
    lastChk = now;                                   // 记录本轮检查时间
    char path[MAX_PATH] = {0};                       // exe 全路径缓冲
    GetModuleFileNameA(nullptr, path, MAX_PATH);     // 取本进程 exe 路径(与工作目录无关)
    std::string p(path);                             // 转 std::string
    std::string cfg = p.substr(0, p.find_last_of("\\/") + 1) + "trade_cfg.json";   // 同目录配置文件
    FILE* f = fopen(cfg.c_str(), "rb");              // 打开配置文件
    if (!f) return;                                  // 无文件 → 沿用当前值
    std::string s; char buf[1024]; size_t n;         // 读全部内容
    while ((n = fread(buf, 1, sizeof(buf), f)) > 0) s.append(buf, n);   // 追加到字符串
    fclose(f);                                       // 关闭
    double e = j_num(s, "entry_usd");                // 买入金额(j_num 缺键返回 0)
    double a = j_num(s, "add_usd");                  // 加仓金额
    double dp = j_num(s, "add_dip_pct");             // 网格跌档阈值(百分数, 0.5=跌 0.5%)
    if (e < 0.01 || e > 1000.0) e = g_entryUsd;      // 非法值兜底 → 保持现值(防手滑写崩)
    if (a < 0.01 || a > 1000.0) a = g_addUsd;        // 同上
    if (dp < 0.05 || dp > 5.0) dp = g_addDip * 100.0;   // 跌档阈值合法区间 0.05%~5%
    dp /= 100.0;                                     // 百分数 → 小数
    if (e != g_entryUsd || a != g_addUsd || dp != g_addDip) {   // 值有变化 → 审计日志
        char cl[200];                                // 日志缓冲
        snprintf(cl, sizeof(cl), "金额配置热更新: 买入=%.2fU 加仓=%.2fU 跌档=%.2f%% (%s)",
                 e, a, dp * 100.0, cfg.c_str());     // 变更文案
        eng_log("INFO", "engine", cl);               // 落 logs 表+文件
    }
    g_entryUsd = e;                                  // 生效: 买入
    g_addUsd = a;                                    // 生效: 加仓
    g_addDip = dp;                                   // 生效: 跌档阈值
}

// ---------------- 开仓: 下单 + 台账 + 流水 ----------------
static void open_position(const std::string& inst, const std::string& why) {   // 合约/开仓原因
    double px = okx_last_price(inst);                   // 取实时最新价
    if (px <= 0) { eng_log("WARN", "buy", inst + " 取不到实时价, 放弃"); return; }   // 无价不下单
    int lev = okx_set_leverage_adaptive(inst, LOCK_LEVER);   // 自适应设杠杆(降档合约取实际值)
    int sz = size_for(inst, g_entryUsd, px, lev);       // 按实际杠杆算张数(金额=trade_cfg.json)
    if (sz <= 0) { eng_log("ERROR", "buy", inst + " 张数计算为0, 放弃"); return; }   // 张数为0放弃

    std::string resp;                                   // 下单响应体
    if (!okx_market_buy(inst, sz, resp) || resp.find("\"code\":\"0\"") == std::string::npos) {   // 市价买失败
        std::string msg = j_str(resp, "sMsg");          // 提取 OKX 错误消息
        if (msg.empty()) msg = j_str(resp, "msg");      // 兼容 msg 字段
        if (msg.empty()) msg = "unknown";               // 都空给 unknown
        eng_log("ERROR", "buy", "开仓下单 " + inst + " sz=" + std::to_string(sz) + " 失败: " + msg);   // 记失败日志
        return;                                         // 下单失败不写台账
    }
    char lg[256];                                       // 成功日志缓冲
    snprintf(lg, sizeof(lg), "金▲开仓 %s sz=%d (≈%.1fU保证金·%dx) %s",   // 开仓成功文案
             inst.c_str(), sz, g_entryUsd, lev, why.c_str());
    eng_log("INFO", "buy", lg);                         // 落 logs 表+文件

    Sleep(3000);                                        // 等 OKX 撮合回填均价
    std::string pobj;                                   // 单合约持仓对象
    double avg = px, posSz = (double)sz;                // 均价/持仓张数兜底初值
    if (okx_position(inst, pobj)) {                     // 拉实际持仓
        double a = j_num(pobj, "avgPx"); if (a > 0) avg = a;    // 用实际成交均价
        double p = j_num(pobj, "pos");   if (p > 0) posSz = p;  // 用实际持仓张数
    }
    char sql[1024];                                     // SQL 缓冲
    snprintf(sql, sizeof(sql),                          // 写持仓台账
        "INSERT INTO position_detail (inst_id,open_time,units,avg_px,last_px,status,ladder_adds,open_bar,buy_strategy)"
        " VALUES ('%s',NOW(),%d,%.10g,%.10g,'OPEN',0,'5m','gold15_5')",   // 状态OPEN/加仓0次/策略gold15_5
        inst.c_str(), (int)posSz, avg, px);
    db_ex(sql);                                         // 执行台账插入
    snprintf(sql, sizeof(sql),                          // 写交易流水
        "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,sz,notional_usd,profit,ord_id,remark)"
        " VALUES (NOW(),'%s','buy','long',%.10g,%.10g,%.10g,NULL,'','hybrid open gold15_5 (%s)')",   // 买入/多/名义价值
        inst.c_str(), px, posSz, posSz * avg, sqlesc(why).substr(0, 200).c_str());
    db_ex(sql);                                         // 执行流水插入
    okx_cancel_all_algos(inst);                         // 不挂任何止损止盈单
}

// ---------------- 加仓: 跌时金▲, 每轮 +1U (同根3m只加1次) ----------------
static void add_position(const std::string& inst, double avg, double last, const std::string& why) {   // 合约/均价/现价/原因
    int lev = okx_set_leverage_adaptive(inst, LOCK_LEVER);   // 实际生效杠杆(降档合约保护: 防保证金翻倍)
    int sz = size_for(inst, g_addUsd, last, lev);       // 按实际杠杆算加仓张数(金额=trade_cfg.json)
    if (sz <= 0) return;                                // 张数为0放弃
    std::string resp;                                   // 下单响应体
    if (!okx_market_buy(inst, sz, resp) || resp.find("\"code\":\"0\"") == std::string::npos) {   // 市价买失败
        std::string msg = j_str(resp, "sMsg");          // 提取错误消息
        if (msg.empty()) msg = j_str(resp, "msg");      // 兼容 msg 字段
        if (msg.empty()) msg = "unknown";               // 兜底
        eng_log("ERROR", "add", "加仓下单 " + inst + " sz=" + std::to_string(sz) + " 失败: " + msg);   // 记失败
        return;                                         // 失败不改台账
    }
    char sql[1024];                                     // SQL 缓冲
    snprintf(sql, sizeof(sql),                          // 更新台账: 加仓次数+1, 记最新加仓价
        "UPDATE position_detail SET ladder_adds=ladder_adds+1, last_add_px=%.10g, last_px=%.10g"
        " WHERE inst_id='%s' AND status='OPEN'", last, last, inst.c_str());   // 只更新 OPEN 台账
    db_ex(sql);                                         // 执行更新
    snprintf(sql, sizeof(sql),                          // 写加仓流水
        "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,sz,notional_usd,remark)"
        " VALUES (NOW(),'%s','add','long',%.10g,%d,%.10g,'%s')",   // action=add
        inst.c_str(), last, sz, sz * last,
        sqlesc("网格跌档加仓 (" + why + ") +1U").substr(0, 400).c_str());
    db_ex(sql);                                         // 执行插入
    char lg[256];                                       // 成功日志缓冲
    snprintf(lg, sizeof(lg), "网格跌档加仓 %s sz=%d (+1U · %dx) last=%.6g avg=%.6g %s",   // 加仓文案(含实际杠杆)
             inst.c_str(), sz, lev, last, avg, why.c_str());
    eng_log("INFO", "add", lg);                         // 落日志
}

// ---------------- 平仓台账: 关台账 + 事件流水(真盈亏由 OKX 回填) ----------------
static void close_ledger(const std::string& inst, const char* action, const std::string& remark) {   // 合约/动作/备注
    db_ex("UPDATE position_detail SET status='CLOSED', close_time=NOW() WHERE inst_id='" + inst + "' AND status='OPEN'");   // 关台账
    db_ex("DELETE FROM grid_signal WHERE inst_id='" + inst + "'");   // 清掉网格信号看板行
    char sql[1024];                                     // SQL 缓冲
    snprintf(sql, sizeof(sql),                          // 写平仓事件流水
        "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,profit,remark)"
        " VALUES (NOW(),'%s','%s','long',0,NULL,'%s')",   // price/profit 置空, 真盈亏由回填补
        inst.c_str(), action, sqlesc(remark).substr(0, 400).c_str());
    db_ex(sql);                                         // 执行插入
}

// ---------------- 台账对账: OKX 已无仓但台账 OPEN → 关闭 ----------------
static void reconcile() {                               // 每 60 秒执行一次
    std::set<std::string> alive;                        // OKX 实际存活持仓集合
    std::vector<std::string> objs;                      // 持仓对象数组
    if (okx_positions(objs))                            // 拉全部持仓
        for (auto& o : objs) if (j_num(o, "pos") > 0) alive.insert(j_str(o, "instId"));   // pos>0 的合约记为存活
    RowSet rs = db_q("SELECT DISTINCT inst_id FROM position_detail WHERE status='OPEN'");   // 查台账中 OPEN 的合约
    if (!rs.ok) return;                                 // 查库失败跳过本轮
    for (auto& r : rs.rows) {                           // 遍历 OPEN 台账
        if (r.empty() || r[0].empty()) continue;        // 空行跳过
        if (alive.count(r[0])) continue;                // OKX 还有仓 → 正常持有
        close_ledger(r[0], "close", "对账平仓 (OKX已无持仓, 台账自动关闭)");   // 无仓却OPEN → 关台账
        eng_log("INFO", "reconcile", "对账平仓 " + r[0]);   // 记对账日志
    }
}

// ---------------- 闸门检查(买入前) ----------------
static bool gates_ok(int opened_this_scan) {            // 入参: 本轮扫描已开仓数
    bool ok = false;                                    // db_scalar 出参
    int openPos = (int)atoll_s(db_scalar("SELECT COUNT(*) FROM position_detail WHERE status='OPEN'", ok));   // 当前 OPEN 持仓数
    if (openPos >= LOCK_MAX_POSITIONS) return false;    // 闸门①: 持仓≥12 禁开
    int buysH = (int)atoll_s(db_scalar(                 // 统计近 60 分钟买入数
        "SELECT COUNT(*) FROM trade_flow WHERE action='buy' AND trade_time>NOW()-INTERVAL 60 MINUTE", ok));
    if (buysH >= LOCK_MAX_BUYS_HOUR) return false;      // 闸门②: 每小时买≥3 禁开
    if (opened_this_scan >= LOCK_MAX_NEW_SCAN) return false;   // 闸门③: 本扫描已开≥1 禁再开
    return true;                                        // 三闸全过允许开仓
}

// ---------------- 5m 收盘买入扫描: 全池 → 5m+3m 金▲共振 → 开仓 ----------------
//   口径(2026-09-28 用户指定): 只用 5m 与 3m 两周期各自的金▲底部动能锚(KE>=该合约底部KE的75分位,
//   与引擎同源自适应, 不用固定 KE 绝对值 —— 实测 KE 含成交量量纲, 跨合约不可比)。
//   实测(10合约/24.5天/实盘口径): 5m+3m 共振 +0.190%/胜率57% > 单5m 0.172%/54% > 现状5m+15m六维 0.123%/52%。
static void buy_scan(const std::vector<std::string>& pool) {   // 入参: 权威合约池(白名单)
    int opened = 0, i = 0;                              // 本轮已开仓数/游标
    std::set<std::string> alive;                        // OKX 存活持仓集合
    std::vector<std::string> objs;                      // 持仓对象数组
    if (okx_positions(objs))                            // 拉全部持仓
        for (auto& o : objs) if (j_num(o, "pos") > 0) alive.insert(j_str(o, "instId"));   // 记存活合约

    for (auto& inst : pool) {                           // 遍历白名单池
        if (!g_run) break;                              // 全局退出标志
        i++;                                            // 游标推进
        Sleep(1);                                       // 单核让步
        if (!gates_ok(opened)) break;                   // 闸门不过直接结束扫描
        if (alive.count(inst)) continue;                // 有持仓不开
        bool ok = false;                                // db_scalar 出参
        int n = (int)atoll_s(db_scalar("SELECT COUNT(*) FROM trade_flow WHERE inst_id='" + inst +    // 统计该合约近冷却期买入数
             "' AND action='buy' AND trade_time>NOW()-INTERVAL " + std::to_string(LOCK_COOLDOWN_MIN) + " MINUTE", ok));
        if (n > 0) continue;                            // 冷却中跳过
        double k5 = 0.0, k3 = 0.0;                      // 5m/3m 金▲ KE 值
        std::string i5, i3;                             // 信号说明
        if (!sig_store_gold(inst, "5m", k5, i5)) continue;   // ① 5m 金▲未命中 → 下一个
        feed_inst_lite(inst, "3m");                     // 仅对 5m 已命中的合约补最新 3m(单合约 1 请求)
        if (!sig_store_gold(inst, "3m", k3, i3)) continue;   // ② 3m 金▲未命中 → 双周期不共振, 放弃
        std::string remark = "金▲共振命中 5m[KE=" + jnum(k5) + "] 3m[KE=" + jnum(k3) + "]";   // 信号备注(双KE)
        char sql[1024];                                 // SQL 缓冲
        snprintf(sql, sizeof(sql),                      // 先写 signal 流水(买入痕迹)
            "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,remark)"
            " VALUES (NOW(),'%s','signal','long','%s')",
            inst.c_str(), sqlesc(remark).c_str());
        db_ex(sql);                                     // 执行
        open_position(inst, "5m+3m金▲共振");            // 双周期共振 → 正式开仓
        opened++;                                       // 本轮开仓计数
    }
}

// ---------------- 持仓看板快照 + 跌时金▲加仓 ----------------
static void manage_positions() {                        // 每 10 秒执行
    std::vector<std::string> objs;                      // OKX 持仓对象数组
    if (!okx_positions(objs)) return;                   // 拉持仓失败跳过
    long long now = (long long)time(nullptr);           // 当前 Unix 秒
    for (auto& p : objs) {                              // 遍历每个持仓
        std::string inst = j_str(p, "instId");          // 合约 ID
        double pos = j_num(p, "pos");                   // 持仓张数
        if (inst.empty() || pos <= 0) continue;         // 空仓/无效条目跳过
        if (j_str(p, "posSide") == "short") continue;   // 只管多仓
        double avg = j_num(p, "avgPx");                 // 持仓均价
        double last = j_num(p, "last");                 // 现价
        if (last <= 0) last = j_num(p, "markPx");       // 现价缺失退用标记价
        double uplRatio = j_num(p, "uplRatio");         // 未实现收益率(ROI)
        bool ok = false;                                // db_scalar 出参
        std::string addsS = db_scalar("SELECT CONCAT(ladder_adds,'|',IFNULL(last_add_px,0)) FROM position_detail WHERE inst_id='" + inst +    // 查台账: 加仓次数|上次加仓价
                                      "' AND status='OPEN' ORDER BY id DESC LIMIT 1", ok);
        int adds = 0;                                   // 已加仓次数
        double lapx = 0.0;                              // 上次加仓价(从未加过=0)
        {   std::size_t bar = addsS.find('|');          // 拆 "次数|价格"
            if (bar != std::string::npos) {             // 格式合法
                adds = (int)atoll_s(addsS.substr(0, bar).c_str());          // 次数
                lapx = atof(addsS.substr(bar + 1).c_str());                 // 价格
            }
        }
        double tpPx = avg * (1.0 + LOCK_TP_PCT);        // 止盈线 = 均价 × (1+2%)  唯一口径

        // 加仓检测: 网格跌档(2026-09-29 用户拍板)——现价须跌破基准价×(1-跌档阈值)才允许再加,
        // 基准 = 上次加仓价(从未加过则用开仓均价); 天然满足"同价位不重复加仓"。
        // 防失控: 单合约 30 分钟冷却 + 全局每小时 ≤6 次 保留; 跌档阈值 trade_cfg.json add_dip_pct 可调。
        double basePx = (lapx > 0 ? lapx : avg);        // 跌档基准价
        bool canAdd = false;                            // 是否允许加仓
        std::string why;                                // 加仓触发说明
        if (avg > 0 && basePx > 0 && last < basePx * (1.0 - g_addDip)) {   // 创出新低(跌破基准 0.5%)
            std::string lastAddS = db_scalar("SELECT UNIX_TIMESTAMP(MAX(trade_time)) FROM trade_flow"    // 最近一次加仓时间
                                             " WHERE inst_id='" + inst + "' AND action='add'", ok);
            long long lastAdd = lastAddS.empty() ? 0 : atoll_s(lastAddS);   // 0=从未加仓
            bool coolOk = (lastAdd == 0) || (now - lastAdd >= 1800);    // 30 分钟冷却检查
            int addsH = (int)atoll_s(db_scalar(         // 统计近 60 分钟全局加仓次数
                "SELECT COUNT(*) FROM trade_flow WHERE action='add' AND trade_time>NOW()-INTERVAL 60 MINUTE", ok));
            bool hourOk = addsH < 6;                    // 每小时≤6次限制
            if (coolOk && hourOk) {                     // 冷却+频次都过
                canAdd = true;                          // 网格跌档命中 → 允许加仓
                char wb[200];                           // 触发说明缓冲
                snprintf(wb, sizeof(wb), "网格跌档 %.2f%%(基准%.6g 现价%.6g)", g_addDip * 100.0, basePx, last);
                why = wb;                               // 落库用
            }
        }

        // 止盈状态/文案: 与 tphub 判定同口径(价格 +2%), 并显示实际杠杆(可能被降档)
        double lever = j_num(p, "lever"); if (lever <= 0) lever = LOCK_LEVER;   // 实际杠杆(异常兜底20x)
        bool tpHit = (last > 0 && avg > 0) ? (last >= tpPx) : (uplRatio >= LOCK_TP_ROI);   // 止盈达标判定(价格优先/ROI兜底)
        const char* state = canAdd ? "★网格跌档待加仓"  // 看板状态文案
                          : (tpHit ? "★止盈达标"
                          : (last < basePx * (1 - g_addDip / 2) ? "等待创出新低" : "持有(未创新低)"));
        char note[512];                                 // 看板备注缓冲
        snprintf(note, sizeof(note),                    // 组装与 tphub 同口径的备注
            "金▲版 | 网格跌档加仓+%.2fU(创新低%.2f%%) | 止盈线%.6f(价格+2%%; %.0fx→ROI%.0f%%) | 不止损 | 现价ROI=%.1f%%",   // 不止损铁律
            g_addUsd, g_addDip * 100.0, tpPx, lever, LOCK_TP_PCT * 100.0 * lever, uplRatio * 100.0);
        char sql[2048];                                 // SQL 缓冲
        snprintf(sql, sizeof(sql),                      // 写/更新 grid_signal 持仓看板
            "REPLACE INTO grid_signal (inst_id,updated,last_px,avg_px,units,ladder_adds,next_add_usd,"
            "tp_px,tp_pct,state,note,dist_down_pct)"
            " VALUES ('%s',NOW(),%.10g,%.10g,%d,%d,%.10g,%.10g,2.0,'%s','%s',0)",   // tp_pct=2.0 / 下次加仓+1U
            inst.c_str(), last, avg, (int)pos, adds, g_addUsd, tpPx,
            state, sqlesc(note).substr(0, 180).c_str());
        db_ex(sql);                                     // 执行看板更新

        if (canAdd) {                                   // 通过全部加仓条件
            snprintf(sql, sizeof(sql),                  // 写加仓触发日志表
                "INSERT INTO grid_signal_log (log_time,inst_id,level,last_px,trigger_px,add_usd,kind,state,note)"
                " VALUES (NOW(),'%s',0,%.10g,%.10g,%.10g,'grid','触发加仓','%s')",
                inst.c_str(), last, last, g_addUsd, sqlesc(why).substr(0, 180).c_str());
            db_ex(sql);                                 // 执行
            add_position(inst, avg, last, why);         // 正式执行加仓(+1U 市价买)
        }
    }
}

// ---------------- 每分钟快照: pnl_history ----------------
static void minute_snap() {                             // 每分钟执行一次
    std::vector<std::string> objs;                      // OKX 持仓对象数组
    if (!okx_positions(objs)) return;                   // 拉失败跳过
    double posM = 0.0, upl = 0.0;                       // 占用保证金合计/浮盈合计
    int n = 0;                                          // 持仓数量
    for (auto& p : objs) {                              // 遍历持仓
        if (j_num(p, "pos") <= 0) continue;             // 空仓跳过
        n++;                                            // 计数
        double imr = j_num(p, "imr");                   // 初始保证金
        if (imr <= 0) imr = j_num(p, "notionalUsd") / (double)LOCK_LEVER;   // 缺失按名义/20x 估算
        posM += imr;                                    // 累加保证金
        upl += j_num(p, "upl");                         // 累加浮盈
    }
    char sql[512];                                      // SQL 缓冲
    snprintf(sql, sizeof(sql),                          // 写 pnl 快照
        "INSERT INTO pnl_history (ts,pnl,open_pos,pos_margin,snap_time) VALUES (%lld,%.4f,%d,%.4f,NOW())",
        (long long)time(nullptr), upl, n, posM);
    db_ex(sql);                                         // 执行
}

// ---------------- 主循环 ----------------
void engine_loop() {                                    // 引擎主循环(主线程)
    bool bootFed = false;                               // 启动喂库是否完成
    long long last5mSlot = 0, lastMin = 0, lastReconcile = 0;   // 5m槽/分钟/对账 游标

    while (g_run) {                                     // 常驻循环(退出靠 g_run)
        trade_hb("tradehub");                           // 每轮刷新心跳文件
        cfg_reload();                                   // 每轮热加载金额配置(trade_cfg.json, 10s 节流)
        try {                                           // 异常兜底: 单轮异常不杀进程
            long long now = (long long)time(nullptr);   // 当前 Unix 秒
            long long slot5 = (long long)(floor((double)now / 300.0) * 300.0);   // 当前 5m 槽起点

            // ① 启动喂库: 池内全部 × 5m/3m 喂入内存(数据此后滞留本进程)
            if (!bootFed) {                             // 只在启动时执行一次
                std::vector<std::string> pool = pool_cached();   // 取权威池
                eng_log("INFO", "boot", "开始喂DLL内存库: " + std::to_string(pool.size()) + " 合约 × 5m/3m …");   // 喂库开始日志
                int fed = 0;                            // 已喂计数
                for (auto& inst : pool) {               // 遍历全池
                    if (!g_run) break;                  // 退出标志
                    feed_inst(inst, "5m");              // 喂 5m 全量进内存库
                    feed_inst(inst, "3m");              // 喂 3m 全量进内存库
                    fed++;                              // 计数
                    if (fed % 20 == 0) trade_hb("tradehub");   // 喂库阶段也心跳
                    if (fed % 50 == 0)                  // 每 50 个打一次进度
                        eng_log("INFO", "boot", "内存库喂入进度 " + std::to_string(fed) + "/" + std::to_string(pool.size()));
                }
                bootFed = true;                         // 标记喂库完成
                eng_log("INFO", "boot", "DLL内存库喂入完成: 序列数=" + std::to_string(sig_store_stats()));   // 完成日志(约502序列)
            }

            // ② 止盈由 tphub.exe 实时接管, 此处不做(防双卖)

            // ③ 持仓管理 + 跌时金▲加仓(每10秒)
            manage_positions();                         // 看板快照+加仓判定

            // ④ 对账(每60秒)
            if (now - lastReconcile >= 60) { lastReconcile = now; reconcile(); }   // 60秒一次台账对账

            // ⑤ 每分钟快照
            if (now / 60 != lastMin) { lastMin = now / 60; minute_snap(); }   // 跨分钟才快照

            // ⑥ 新 5m 槽 → 全池增量喂(5m/3m) + 买入扫描
            if (slot5 != last5mSlot && bootFed) {       // 进入新 5m 槽且已完成启动喂库
                last5mSlot = slot5;                     // 更新槽游标
                std::vector<std::string> pool = pool_cached();   // 取权威池(白名单)
                if (!pool.empty()) {                    // 池非空才扫
                    for (auto& inst : pool) {           // 全池增量喂库
                        if (!g_run) break;              // 退出标志
                        feed_inst_lite(inst, "5m");     // 增量补最新已收盘 5m
                        feed_inst_lite(inst, "3m");     // 增量补最新已收盘 3m
                        Sleep(1);                       // 单核让步
                    }
                    buy_scan(pool);                     // 全池买入扫描(六重闸门)
                }
            }
        } catch (...) {                                 // 任意异常兜底
            eng_log("ERROR", "engine", "主循环异常(C++ exception)");   // 记日志继续跑
        }

        // 睡到下一个 10 秒边界(过期则兜底小睡, 防忙转)
        long long rem = 10 - ((long long)time(nullptr) % 10);   // 距下一 10s 边界的秒数
        if (rem > 1) Sleep((DWORD)(rem * 1000));        // 正常睡满
        else Sleep(300);                                // 边界不足1秒则小睡300ms防忙转
    }
    eng_log("INFO", "engine", "引擎主循环退出");         // 退出日志
}
