// ==================================================================
// trade_backfill.cpp — 成交回填 + K线洞扫描 + 合约池每日同步
//   逐字迁移自 engine/backfill.php + engine/pool_sync.php
//   ① 每分钟: fills-history(最多5页) + positions-history(单页) 回填 trade_flow
//      时间截断 CUTOFF=2026-09-27 22:40 本地时区(老数据一律不收)
//   ② 洞扫描: 每轮 1 个合约 × 6 周期, 扫出的洞用 before= 逐段补
//   ③ 池同步: 每日 00:30 后过滤(剔除股票类/非live/价格越界/公告下线)
// ==================================================================
#include "tradehub.h"
#include <cstdio>
#include <cmath>
#include <ctime>
#include <cctype>
#include <map>
#include <set>
#include <climits>

const int FILLS_BACKFILL_MIN = 180;

// 截断时间(本地时区, 与 PHP strtotime 同口径)
long long cutoff_ms() {
    static long long v = local_dt_to_unix("2026-09-27 22:40:00") * 1000LL;
    return v;
}

// ---------------- 成交明细回填 ----------------
static int backfill_fills() {
    long long begin = now_ms() - (long long)FILLS_BACKFILL_MIN * 60000LL;
    std::string after;
    int n = 0;
    for (int page = 0; page < 5; page++) {
        if (!g_run) break;
        std::string path = "/api/v5/trade/fills-history?instType=SWAP&begin=" +
                           std::to_string(begin) + "&limit=100";
        if (!after.empty()) path += "&after=" + after;
        std::string body;
        if (!okx_private("GET", path, "", body)) break;
        if (body.find("\"code\":\"0\"") == std::string::npos) break;
        auto objs = j_split_objects(body);
        if (objs.empty()) break;
        long long oldest = LLONG_MAX;
        for (auto& f : objs) {
            long long ts = (long long)j_num(f, "ts");
            if (ts < oldest) oldest = ts;
            if (ts < cutoff_ms()) continue;                 // 老数据一律不收
            std::string inst = j_str(f, "instId");
            if (inst.empty()) continue;
            std::string t = ms_to_dt(ts);
            std::string action = (j_str(f, "side") == "buy") ? "buy" : "close";
            std::string remark = (action == "close")
                ? ("OKX pos-history (fills " + j_str(f, "subType") + ")")
                : std::string("OKX fills backfill");
            double px = j_num(f, "fillPx");
            double sz = j_num(f, "fillSz");
            char sql[2048];
            snprintf(sql, sizeof(sql),
                "SELECT COUNT(*) FROM trade_flow WHERE inst_id='%s' AND trade_time='%s'"
                " AND action='%s' AND price=%.10g AND sz=%.10g",
                inst.c_str(), t.c_str(), action.c_str(), px, sz);
            bool ok = false;
            if (atoll_s(db_scalar(sql, ok)) > 0) continue;   // 已存在
            double notional = j_num(f, "fillNotional");
            if (notional == 0) notional = px * sz;
            double fee = fabs(j_num(f, "fee"));
            std::string ordId = j_str(f, "ordId");
            std::string posSide = j_str(f, "posSide");
            if (posSide.empty()) posSide = "long";
            if (action == "close") {
                snprintf(sql, sizeof(sql),
                    "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,sz,notional_usd,"
                    "profit,fee,ord_id,remark) VALUES ('%s','%s','%s','%s',%.10g,%.10g,%.10g,%.10g,%.10g,'%s','%s')",
                    t.c_str(), inst.c_str(), action.c_str(), posSide.c_str(), px, sz, notional,
                    j_num(f, "fillPnl"), fee, sqlesc(ordId).c_str(), sqlesc(remark).c_str());
            } else {
                snprintf(sql, sizeof(sql),
                    "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,sz,notional_usd,"
                    "profit,fee,ord_id,remark) VALUES ('%s','%s','%s','%s',%.10g,%.10g,%.10g,NULL,%.10g,'%s','%s')",
                    t.c_str(), inst.c_str(), action.c_str(), posSide.c_str(), px, sz, notional,
                    fee, sqlesc(ordId).c_str(), sqlesc(remark).c_str());
            }
            if (db_ex(sql)) n++;
        }
        if (oldest < cutoff_ms()) break;                     // 整页已老于截断 → 完成
        after = j_str(objs.back(), "billId");
        if (after.empty() || objs.size() < 100) break;
        Sleep(250);                                          // fills-history 限频 10次/2秒
    }
    return n;
}

// ---------------- 历史仓位回填(单页100条/分钟, 有 realizedPnl 才入) ----------------
static int backfill_pos_history() {
    int n = 0;
    std::string body;
    if (!okx_private("GET", "/api/v5/account/positions-history?instType=SWAP&limit=100", "", body)) return 0;
    if (body.find("\"code\":\"0\"") == std::string::npos) return 0;
    for (auto& f : j_split_objects(body)) {
        long long ts = (long long)j_num(f, "uTime");
        if (ts < cutoff_ms()) continue;
        double pnl = j_num(f, "realizedPnl");
        if (pnl == 0) continue;
        std::string inst = j_str(f, "instId");
        if (inst.empty()) continue;
        std::string t = ms_to_dt(ts);
        std::string tMinus = ms_to_dt(ts - 60000LL);
        char sql[2048];
        snprintf(sql, sizeof(sql),
            "SELECT COUNT(*) FROM trade_flow WHERE inst_id='%s' AND action='close'"
            " AND trade_time BETWEEN '%s' AND '%s' AND remark LIKE 'OKX pos-history%%'",
            inst.c_str(), tMinus.c_str(), t.c_str());
        bool ok = false;
        double funding = j_num(f, "fundingFee");
        if (atoll_s(db_scalar(sql, ok)) > 0) {
            // 已有 fills 口径盈利行: 仅补资金费, 费用不重复计
            snprintf(sql, sizeof(sql),
                "UPDATE trade_flow SET funding=%.10g WHERE id=("
                " SELECT id FROM (SELECT id FROM trade_flow WHERE inst_id='%s' AND action='close'"
                " AND trade_time BETWEEN '%s' AND '%s' AND remark LIKE 'OKX pos-history%%'"
                " ORDER BY id DESC LIMIT 1) x)",
                funding, inst.c_str(), tMinus.c_str(), t.c_str());
            db_ex(sql);
            continue;
        }
        snprintf(sql, sizeof(sql),
            "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,profit,fee,funding,remark)"
            " VALUES ('%s','%s','close','long',%.10g,%.10g,%.10g,%.10g,'%s')",
            t.c_str(), inst.c_str(), j_num(f, "closeAvgPx"), pnl,
            fabs(j_num(f, "totalFee")), funding,
            sqlesc("OKX pos-history type=" + j_str(f, "type")).c_str());
        db_ex(sql);
        snprintf(sql, sizeof(sql),
            "UPDATE position_detail SET status='CLOSED', close_time='%s', profit=%.10g"
            " WHERE inst_id='%s' AND status='OPEN'", t.c_str(), pnl, inst.c_str());
        db_ex(sql);
        n++;
    }
    return n;
}

// ---------------- K线洞扫描(单合约单周期: 找空洞逐段补) ----------------
static int hole_scan(const std::string& inst, const std::string& tf) {
    std::string t = ktable(inst, tf);
    long long iv = kl_iv_ms(tf);
    bool ok = false;
    std::string minS = db_scalar("SELECT MIN(candle_time) FROM " + t, ok);
    std::string maxS = db_scalar("SELECT MAX(candle_time) FROM " + t, ok);
    if (minS.empty() || maxS.empty()) return 0;
    long long mn = atoll_s(minS), mx = atoll_s(maxS);
    if (mn <= 0 || mx <= 0) return 0;
    long long slots = (mx - mn) / iv + 1;
    long long cnt = atoll_s(db_scalar("SELECT COUNT(*) FROM " + t, ok));
    if (cnt >= slots) return 0;                      // 无洞
    long long prev = -1;
    const int pageSize = 20000;
    int offset = 0;
    while (g_run) {
        RowSet rs = db_q("SELECT candle_time FROM " + t + " ORDER BY candle_time ASC LIMIT " +
                         std::to_string(pageSize) + " OFFSET " + std::to_string(offset));
        if (!rs.ok || rs.rows.empty()) break;
        for (auto& r : rs.rows) {
            if (!g_run) break;
            long long ct = atoll_s(r[0]);
            if (prev >= 0 && ct - prev > iv) {
                long long after = prev;
                while (after < ct - iv) {
                    if (!g_run) break;
                    kl_fetch_store(inst, tf, 300, after, true, false, "before");
                    Sleep(150);
                    std::string nm = db_scalar("SELECT MAX(candle_time) FROM " + t +
                                               " WHERE candle_time<" + std::to_string(ct), ok);
                    long long newMax = nm.empty() ? 0 : atoll_s(nm);
                    if (newMax <= after) break;      // 补不动了, 防死循环
                    after = newMax;
                }
            }
            prev = ct;
        }
        offset += pageSize;
        if ((int)rs.rows.size() < pageSize) break;
    }
    return 1;
}

// ---------------- 洞扫描状态落库(app_settings.fill_holes) ----------------
// 紧凑格式: "2026-09-28|BASE1,BASE2,..." (477 合约约 2.4KB, 不超 sval 上限)
static std::string g_holeDay;
static std::set<std::string> g_holeBases;

static void hole_state_load() {
    bool ok = false;
    std::string v = db_scalar("SELECT sval FROM app_settings WHERE skey='fill_holes'", ok);
    size_t p = v.find('|');
    if (p == std::string::npos) return;
    g_holeDay = v.substr(0, p);
    std::string rest = v.substr(p + 1);
    size_t i = 0;
    while (i < rest.size()) {
        size_t c = rest.find(',', i);
        if (c == std::string::npos) c = rest.size();
        if (c > i) g_holeBases.insert(rest.substr(i, c - i));
        i = c + 1;
    }
}

static void hole_state_save() {
    std::string v = g_holeDay + "|";
    bool first = true;
    for (auto& b : g_holeBases) {
        if (!first) v += ",";
        v += b;
        first = false;
        if (v.size() > 6000) break;
    }
    if (v.size() <= 7000)
        db_ex("REPLACE INTO app_settings (skey,sval,updated_at) VALUES ('fill_holes','" +
              sqlesc(v) + "',NOW())");
}

// ---------------- 合约池每日同步(池内过滤, 不新增合约) ----------------
static std::string fetch_url(const std::string& url) {
    if (url.compare(0, 8, "https://") != 0) return "";
    std::string rest = url.substr(8);
    size_t sl = rest.find('/');
    if (sl == std::string::npos) return "";
    std::string host = rest.substr(0, sl);
    std::string path = rest.substr(sl);
    std::string hdr = "Accept-Language: zh-CN,zh;q=0.9,en;q=0.8\r\n";
    std::string out;
    if (http_req("GET", host, path, hdr, "", out)) return out;
    return "";
}

static std::string strip_tags(const std::string& s) {
    std::string o;
    o.reserve(s.size() / 2);
    bool in = false;
    for (char c : s) {
        if (c == '<') { in = true; continue; }
        if (c == '>') { in = false; o += ' '; continue; }
        if (!in) o += c;
    }
    return o;
}

// 提取 /help/ 之后含 "delist" 的文章 slug (等价于 PHP 正则, 手写避免 std::regex 膨胀)
static void find_delist_slugs(const std::string& html, std::set<std::string>& out) {
    size_t p = 0;
    while ((p = html.find("/help/", p)) != std::string::npos) {
        size_t st = p + 6, e = st;
        while (e < html.size()) {
            unsigned char c = (unsigned char)html[e];
            if (islower(c) || isdigit(c) || html[e] == '-') e++;
            else break;
        }
        if (e > st) {
            std::string s = html.substr(st, e - st);
            if (s.find("delist") != std::string::npos) out.insert(s);
        }
        p = (e > st) ? e : st;
    }
}

// 提取 BASEUSDT 形式的合约基础名 (等价于 PHP \b([A-Z0-9]{2,15})USDT\b)
static void find_bases(const std::string& txt, std::set<std::string>& out) {
    size_t i = 0, n = txt.size();
    while (i < n) {
        unsigned char c = (unsigned char)txt[i];
        if (!(isupper(c) || isdigit(c))) { i++; continue; }
        size_t st = i;
        while (i < n) {
            unsigned char d = (unsigned char)txt[i];
            if (isupper(d) || isdigit(d)) i++;
            else break;
        }
        size_t len = i - st;
        if (len >= 2 && len <= 15 && i + 4 <= n && txt.compare(i, 4, "USDT") == 0) {
            bool tailOk = (i + 4 >= n);
            if (!tailOk) {
                unsigned char nx = (unsigned char)txt[i + 4];
                tailOk = !(isupper(nx) || isdigit(nx));
            }
            if (tailOk) out.insert(txt.substr(st, len));
        }
    }
}

static void pool_sync_run() {
    RowSet prs = db_q("SELECT inst_id FROM symbol_pool");
    std::vector<std::string> poollist;
    if (prs.ok) for (auto& r : prs.rows) if (!r.empty()) poollist.push_back(r[0]);
    tlog("==== pool_sync 启动 | 当前池 " + std::to_string(poollist.size()) + " 个 ====");

    // ① OKX 官方分类 + 交易状态
    std::string body;
    if (!okx_public_get("/api/v5/public/instruments?instType=SWAP", body) ||
        body.find("\"code\":\"0\"") == std::string::npos) {
        tlog("ERROR instruments 拉取失败, 本轮跳过(保持原池)");
        return;
    }
    std::map<std::string, std::pair<std::string, std::string>> meta;   // instId → (cat,state)
    for (auto& d : j_split_objects(body)) {
        std::string id = j_str(d, "instId");
        if (id.empty()) continue;
        std::string cat = j_str(d, "instCategory");
        if (cat.empty()) cat = "1";
        std::string st = j_str(d, "state");
        if (st.empty()) st = "live";
        meta[id] = std::make_pair(cat, st);
    }

    // ② 最新价
    std::map<std::string, double> px;
    std::string tk;
    if (okx_public_get("/api/v5/market/tickers?instType=SWAP", tk) &&
        tk.find("\"code\":\"0\"") != std::string::npos)
        for (auto& t : j_split_objects(tk)) {
            std::string id = j_str(t, "instId");
            if (!id.empty()) px[id] = j_num(t, "last");
        }

    // ③ 下架公告解析(帮助中心网页; 公告 API 地区封锁)
    std::set<std::string> delisted;
    const char* HOSTS[] = { "https://www.okx.com/zh-hans", "https://www.okx.ac/en-ar", "https://www.okx.pro" };
    std::vector<std::string> slugs;
    for (const char* host : HOSTS) {
        std::string html = fetch_url(std::string(host) + "/help/section/announcements-delistings");
        if (html.size() < 5000) continue;
        std::set<std::string> uniq;
        find_delist_slugs(html, uniq);
        if (!uniq.empty()) { slugs.assign(uniq.begin(), uniq.end()); break; }
    }
    if (slugs.size() > 14) slugs.resize(14);
    tlog("下架公告候选文章: " + std::to_string(slugs.size()) + " 篇");
    for (auto& slug : slugs) {
        std::string s = slug;
        for (auto& c : s) c = (char)tolower((unsigned char)c);
        if (s.find("perpetual") == std::string::npos && s.find("swap") == std::string::npos)
            continue;                                  // 只看永续相关(现货下架不影响 SWAP 池)
        for (const char* host : HOSTS) {
            std::string art = fetch_url(std::string(host) + "/help/" + slug);
            if (art.size() < 3000) continue;
            std::string txt = strip_tags(art);
            std::set<std::string> bases;
            find_bases(txt, bases);
            for (auto& b : bases)
                if (meta.count(b + "-USDT-SWAP")) delisted.insert(b);
            break;
        }
    }
    {
        std::string dl;
        for (auto& d : delisted) { if (!dl.empty()) dl += ","; dl += d; }
        tlog("公告涉及下线合约基础名: " + (dl.empty() ? std::string("(无)") : dl));
    }

    // ④ 过滤(不新增合约: 只在现有池基础上剔除)
    std::vector<std::string> keep, drop;
    for (auto& inst : poollist) {
        std::string base = inst.substr(0, inst.find('-'));
        std::string cat = "9", st = "offline";
        auto mi = meta.find(inst);
        if (mi != meta.end()) { cat = mi->second.first; st = mi->second.second; }
        double p = 0.0;
        auto pi = px.find(inst);
        if (pi != px.end()) p = pi->second;
        std::string why;
        if (cat != "1") why = "股票/ETF类(cat=" + cat + ")";
        else if (st != "live") why = "非live状态(" + st + ")";
        else if (!(p > 0.001 && p < 100)) why = "价格越界(" + jnum(p) + ")";
        else if (delisted.count(base)) why = "公告下线(" + base + ")";
        if (!why.empty()) drop.push_back(inst + " [" + why + "]");
        else keep.push_back(inst);
    }

    // ⑤ 有变化才写库
    if (keep.size() != poollist.size()) {
        db_ex("START TRANSACTION");
        db_ex("DELETE FROM symbol_pool");
        for (auto& inst : keep)
            db_ex("INSERT INTO symbol_pool (inst_id, updated_at) VALUES ('" + inst + "', NOW())");
        db_ex("COMMIT");
        std::string d;
        for (auto& x : drop) { if (!d.empty()) d += " | "; d += x; }
        tlog("池已更新: " + std::to_string(poollist.size()) + " → " + std::to_string(keep.size()) +
             " 剔除明细: " + d);
    } else {
        tlog("无变化: " + std::to_string(keep.size()) + " 个 (公告/分类/价格均达标)");
    }
    tlog("==== pool_sync 完成 | 池 " + std::to_string(keep.size()) + " 个 ====");
}

// 每日 00:30 后执行一次(进程首次调用只登记日期, 避免启动当天重复跑)
static int g_lastPoolDay = -1;
void pool_sync_if_due() {
    time_t t = time(nullptr);
    struct tm lt; localtime_s(&lt, &t);
    int day = lt.tm_year * 400 + lt.tm_yday;
    if (g_lastPoolDay < 0) { g_lastPoolDay = day; return; }
    if (g_lastPoolDay == day) return;
    if (lt.tm_hour == 0 && lt.tm_min < 30) return;
    g_lastPoolDay = day;
    pool_sync_run();
}

// ---------------- 回填主循环 ----------------
void backfill_loop() {
    long long fillLast = 0;
    int scanIdx = 0;
    const char* TFS[] = { "1m", "3m", "5m", "15m", "1h", "4h" };
    hole_state_load();

    while (g_run) {
        trade_hb("backfill");
        try {
            long long now = (long long)time(nullptr);
            // ① 每分钟: 成交 + 仓位历史回填
            if (now - fillLast >= 60) {
                fillLast = now;
                int nf = backfill_fills();
                int np = backfill_pos_history();
                tlog("成交回填 " + std::to_string(nf) + " 笔 / 仓位回填 " + std::to_string(np) + " 笔");
            }
            // ② 洞扫描: 每轮 1 个合约 × 6 周期(状态落库, 全池约 40 分钟一轮)
            std::vector<std::string> insts = pool_all_insts();
            if (!insts.empty()) {
                std::string inst = insts[(size_t)(scanIdx % (int)insts.size())];
                scanIdx++;
                std::string day = today_str();
                if (day != g_holeDay) { g_holeDay = day; g_holeBases.clear(); }
                std::string base = inst.substr(0, inst.find('-'));
                if (!g_holeBases.count(base)) {
                    for (const char* tf : TFS) {
                        if (!g_run) break;
                        hole_scan(inst, tf);
                    }
                    g_holeBases.insert(base);
                    hole_state_save();
                }
                tlog("洞扫描游标=" + std::to_string(scanIdx) + "/" + std::to_string(insts.size()) +
                     "(K线追平已移交datahub)");
            }
            // ③ 合约池每日同步
            pool_sync_if_due();
        } catch (...) {
            tlog("回填主循环异常(C++ exception)");
        }
        Sleep(3000);
    }
    tlog("回填主循环退出");
}
