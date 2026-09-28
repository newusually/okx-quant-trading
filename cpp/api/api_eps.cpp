// ==================================================================
// api_eps.cpp — 全部接口实现 (逐字迁移自 apihub.cpp, 逻辑零改动)
// ==================================================================
#include "../common/hub.h"
#include <cstdio>
#include <cstring>
#include <ctime>
#include <cmath>
#include <cctype>
#include <array>
#include <thread>
#include <mutex>
#include <algorithm>

// ---- tf(内部周期) → OKX bar 参数(小时必须大写, 小写静默取不到数据) ----
static std::string okx_bar_of(const std::string& tf) {
    if (tf == "1m")  return "1m";
    if (tf == "3m")  return "3m";
    if (tf == "5m")  return "5m";
    if (tf == "15m") return "15m";
    if (tf == "30m") return "30m";
    if (tf == "1h")  return "1H";
    if (tf == "2h")  return "2H";
    if (tf == "4h")  return "4H";
    return "";
}

// ---- /kline ----
std::string ep_kline(const Params& q) {
    static const std::map<std::string, int> IV = { {"1m",60},{"3m",180},{"5m",300},{"15m",900},{"1h",3600} };
    std::string tf = P(q, "tf", "1m");
    auto ivit = IV.find(tf);
    if (ivit == IV.end()) return "{\"err\":\"bad tf\"}";
    int iv = ivit->second;
    std::string inst = get_inst(q);

    // ---- fresh=N : 先向 OKX 拉当前周期最近 N 根(含"正在形成的那根")写入库, 再读库 ----
    // 页面 K 线"实时"的根本保障: DB 由 datahub 分级轮转落库, 单合约可能滞后数十分钟;
    // 同合约同周期 2 秒节流, 多客户端/高频轮询不会把 OKX 配额打爆。
    int freshN = atoi(P(q, "fresh", "0").c_str());
    if (freshN > 0) {
        if (freshN > 200) freshN = 200;
        std::string bar = okx_bar_of(tf);
        if (!bar.empty()) {
            std::string ck = inst + "|" + tf, hit;
            if (!cache_get(g_kfreshCache, ck, hit)) {
                std::string fbody;
                if (http_get("www.okx.com",
                             "/api/v5/market/candles?instId=" + inst + "&bar=" + bar +
                             "&limit=" + std::to_string(freshN), fbody)) {
                    auto fb = okx_parse_candles(fbody);
                    if (!fb.empty()) store(inst, tf, fb);
                }
                cache_put(g_kfreshCache, ck, "1", 2000);
            }
        }
    }

    std::string kt = ktable(inst, tf);
    int bmin = atoi(P(q, "bucket", "0").c_str());
    if (bmin < 1) bmin = iv / 60;
    long long bm = (long long)bmin * 60 * 1000;
    int limit = atoi(P(q, "limit", "1000").c_str());
    if (limit < 10) limit = 10;
    if (limit > 2000) limit = 2000;
    bool hasBefore = q.count("before") && numok(P(q, "before"));
    bool hasAfter = q.count("after") && numok(P(q, "after"));
    long long before = hasBefore ? atoll_s(P(q, "before")) : 0;
    long long after = hasAfter ? atoll_s(P(q, "after")) : 0;

    bool exOk;
    (void)exOk;
    RowSet exr = db_q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='trading' AND table_name='" + kt + "'");
    if (!exr.ok || exr.rows.empty() || atoi(exr.rows[0][0].c_str()) == 0)
        return "{\"ok\":false,\"err\":\"no table " + kt + "\"}";
    bool ok;
    std::string lastS = db_scalar("SELECT MAX(candle_time) FROM " + kt, ok);

    std::string cond;
    if (hasBefore) cond += " AND candle_time<" + std::to_string(before);
    if (hasAfter) cond += " AND candle_time>" + std::to_string(after);
    const char* ord = hasAfter ? "ASC" : "DESC";

    // rows: [t(o秒), o,h,l,c, vol] (原生) 或聚合桶
    std::vector<std::array<double, 6>> rows;
    if (bm == (long long)iv * 1000) {
        RowSet rs = db_q("SELECT candle_time,o,h,l,c,vol FROM " + kt + " WHERE c>0" + cond +
                         " ORDER BY candle_time " + ord + " LIMIT " + std::to_string(limit));
        if (rs.ok) {
            if (!hasAfter) std::reverse(rs.rows.begin(), rs.rows.end());
            for (auto& x : rs.rows)
                rows.push_back({ (double)(atoll_s(x[0]) / 1000), atof_s(x[1]), atof_s(x[2]),
                                 atof_s(x[3]), atof_s(x[4]), atof_s(x[5]) });
        }
    } else {
        long long ratio = bm / ((long long)iv * 1000);
        long long srcLimit = (long long)limit * ratio + 400;
        if (srcLimit > 300000) srcLimit = 300000;
        RowSet rs = db_q("SELECT candle_time,o,h,l,c,vol FROM " + kt + " WHERE c>0" + cond +
                         " ORDER BY candle_time " + ord + " LIMIT " + std::to_string(srcLimit));
        if (rs.ok) {
            if (!hasAfter) std::reverse(rs.rows.begin(), rs.rows.end());
            std::map<long long, std::array<double, 6>> buck;
            for (auto& x : rs.rows) {
                long long ts = atoll_s(x[0]);
                long long bk = (long long)floor((double)ts / bm) * bm;
                double o = atof_s(x[1]), h = atof_s(x[2]), l = atof_s(x[3]), c = atof_s(x[4]), v = atof_s(x[5]);
                auto it = buck.find(bk);
                if (it == buck.end()) buck[bk] = { (double)(bk / 1000), o, h, l, c, v };
                else {
                    auto& b = it->second;
                    b[5] += v;
                    if (h > b[2]) b[2] = h;
                    if (l < b[3]) b[3] = l;
                    if (ts > b[0] * 1000) b[4] = c;   // 收盘=桶内最新一根(修正PHP口径)
                }
            }
            for (auto& kv : buck) rows.push_back(kv.second);
        }
    }

    // MACD: warmup 700 根原生收盘
    int n = (int)rows.size();
    std::vector<double> closes;
    if (n > 0) {
        long long t0ms = (long long)rows[0][0] * 1000;
        RowSet wr = db_q("SELECT c FROM " + kt + " WHERE c>0 AND candle_time<" + std::to_string(t0ms) +
                         " ORDER BY candle_time DESC LIMIT 700");
        if (wr.ok) {
            closes.reserve(wr.rows.size() + n);
            for (auto it = wr.rows.rbegin(); it != wr.rows.rend(); ++it) closes.push_back(atof_s((*it)[0]));
        }
        size_t off0 = closes.size();
        for (auto& r : rows) closes.push_back(r[4]);
        std::vector<double> dif, dea, hist, e12, e26;
        macd_full(closes, 12, 26, 9, dif, dea, hist, e12, e26);
        size_t off = off0;
        std::string body;
        for (int k = 0; k < n; k++) {
            char row[512];
            snprintf(row, 512, "[%.0f,%s,%s,%s,%s,%s,%s,%s,%s,%s,null,%s]",
                     rows[k][0], jnum(rows[k][1]).c_str(), jnum(rows[k][2]).c_str(),
                     jnum(rows[k][3]).c_str(), jnum(rows[k][4]).c_str(),
                     jnum(dif[off + k]).c_str(), jnum(dea[off + k]).c_str(), jnum(hist[off + k]).c_str(),
                     jnum(e12[off + k]).c_str(), jnum(e26[off + k]).c_str(), jnum(rows[k][5]).c_str());
            if (k) body += ",";
            body += row;
        }
        long long nowMs = (long long)(time(nullptr)) * 1000;
        return "{\"ok\":true,\"tf\":\"" + tf + "\",\"bucket\":" + std::to_string(bmin) +
               ",\"iv\":" + std::to_string(iv) + ",\"rows\":[" + body + "],\"last\":" +
               std::to_string(atoll_s(lastS) / 1000) + ",\"now\":" + std::to_string(nowMs) + "}";
    }
    long long nowMs = (long long)(time(nullptr)) * 1000;
    return "{\"ok\":true,\"tf\":\"" + tf + "\",\"bucket\":" + std::to_string(bmin) +
           ",\"iv\":" + std::to_string(iv) + ",\"rows\":[],\"last\":" +
           std::to_string(atoll_s(lastS) / 1000) + ",\"now\":" + std::to_string(nowMs) + "}";
}

// ---- /live ----
std::string ep_live(const Params& q) {
    std::string inst = get_inst(q);
    std::string resp = "{\"ok\":0,\"price\":null,\"ts\":null,\"candles\":[]}";
    std::string body;
    if (http_get("www.okx.com", "/api/v5/market/ticker?instId=" + inst, body)) {
        std::string last = json_str(body, "last");
        if (!last.empty() && numok(last)) {
            std::string body2;
            if (http_get("www.okx.com", "/api/v5/market/candles?instId=" + inst + "&bar=1m&limit=20", body2)) {
                auto bars = okx_parse_candles(body2);
                if (!bars.empty()) {
                    store(inst, "1m", bars);   // 页面在线也持续写库
                    // ---- 按需刷新"当前正在看的周期" ----
                    {
                        std::string wtf = P(q, "tf");
                        static const char* OK_BARS[] = {"1m","3m","5m","15m","30m","1H","2H","4H"};
                        static const char* MY_BARS[] = {"1m","3m","5m","15m","30m","1h","2h","4h"};
                        std::string bar;
                        for (int i = 0; i < 8; i++) if (wtf == MY_BARS[i] || wtf == OK_BARS[i]) { bar = OK_BARS[i]; break; }
                        if (!bar.empty() && bar != "1m") {
                            std::string body3;
                            if (http_get("www.okx.com",
                                         "/api/v5/market/candles?instId=" + inst + "&bar=" + bar + "&limit=50", body3)) {
                                auto wb = okx_parse_candles(body3);
                                if (!wb.empty()) store(inst, wtf, wb);
                            }
                        }
                    }
                    std::string cd, price;
                    int tail = 0;
                    for (size_t i = bars.size(); i-- > 0 && tail < 20; i--, tail++) {
                        auto& x = bars[i];
                        if (tail) cd += ",";
                        cd += "[" + std::to_string(atoll_s(x[0]) / 1000) + "," + x[1] + "," + x[2] + "," + x[3] + "," + x[4] + "]";
                        price = x[4];
                    }
                    char buf[64];
                    snprintf(buf, 64, "%lld", atoll_s(bars.back()[0]) / 1000);
                    resp = "{\"ok\":1,\"price\":" + price + ",\"ts\":" + buf + ",\"candles\":[" + cd + "]}";
                }
            }
        }
    }
    if (resp.find("\"ok\":0") != std::string::npos) {
        // OKX不通: 回退DB最新20根
        RowSet rs = db_q("SELECT candle_time,o,h,l,c FROM " + ktable(inst, "1m") +
                         " ORDER BY candle_time DESC LIMIT 20");
        if (rs.ok && !rs.rows.empty()) {
            std::reverse(rs.rows.begin(), rs.rows.end());
            std::string cd;
            for (size_t i = 0; i < rs.rows.size(); i++) {
                auto& x = rs.rows[i];
                if (i) cd += ",";
                cd += "[" + std::to_string(atoll_s(x[0]) / 1000) + "," + jnum(atof_s(x[1])) + "," +
                      jnum(atof_s(x[2])) + "," + jnum(atof_s(x[3])) + "," + jnum(atof_s(x[4])) + "]";
            }
            std::string price = jnum(atof_s(rs.rows.back()[4]));
            resp = "{\"ok\":2,\"price\":" + price + ",\"ts\":" +
                   std::to_string(atoll_s(rs.rows.back()[0]) / 1000) + ",\"candles\":[" + cd + "]}";
        }
    }
    return resp;
}

// ---- /ticker ----
std::string ep_ticker(const Params& q) {
    std::string inst = get_inst(q);
    std::string hit;
    if (cache_get(g_tickCache, inst, hit)) return hit;       // 1秒缓存
    std::string body;
    if (http_get("www.okx.com", "/api/v5/market/ticker?instId=" + inst, body)) {
        std::string last = json_str(body, "last");
        if (!last.empty() && numok(last)) {
            std::string out = "{\"ok\":true,\"price\":" + last + ",\"ts\":" + std::to_string(time(nullptr)) + "}";
            cache_put(g_tickCache, inst, out, 1000);
            return out;
        }
    }
    return "{\"ok\":false,\"price\":0,\"ts\":" + std::to_string(time(nullptr)) + "}";
}

// ---- /trades (流水合并逻辑与 TradeApi::trades 同口径) ----
std::string ep_trades(const Params&) {
    RowSet fl = db_q("SELECT trade_time, inst_id, action, price, profit, COALESCE(fee,0),"
                     " COALESCE(funding,0), sz, notional_usd, ord_id, remark"
                     " FROM trade_flow WHERE action IN ('buy','add','close')"
                     " ORDER BY trade_time DESC, id DESC LIMIT 800");
    if (!fl.ok) return "{\"ok\":false,\"error\":\"db fail\"}";
    // 平仓盈利真源: OKX pos-history 行
    std::map<std::string, std::vector<std::array<std::string, 4>>> ph;
    RowSet cr = db_q("SELECT trade_time, inst_id, profit, COALESCE(fee,0), COALESCE(funding,0)"
                     " FROM trade_flow WHERE action='close' AND profit IS NOT NULL"
                     " AND remark LIKE 'OKX pos-history%' AND trade_time >= NOW() - INTERVAL 7 DAY");
    if (cr.ok)
        for (auto& r : cr.rows)
            ph[r[1]].push_back({ r[0], r[2], r[3], r[4] });

    struct FR { std::string trade_time, inst_id, action, price, profit, fee, funding, sz, notional, ord_id, remark, strat; long long ts; bool profitNull; };
    std::vector<FR> rows;
    auto strat_of = [](const std::string& remark) -> std::string {
        size_t p = remark.find("hybrid open ");
        if (p == std::string::npos) return "";
        p += 12;
        size_t e = p;
        while (e < remark.size() && (isalnum((unsigned char)remark[e]) || remark[e] == '_')) e++;
        return remark.substr(p, e - p);
    };
    for (auto& r : fl.rows) {
        FR x{ r[0], r[1], r[2], r[3], r[4], r[5], r[6], r[7], r[8], r[9], r[10], "", parse_dt(r[0]), r[4].empty() };
        bool merged = false;
        for (auto& prev : rows) {
            if (prev.action != x.action || prev.inst_id != x.inst_id) continue;
            if (prev.ts > x.ts ? (prev.ts - x.ts) : (x.ts - prev.ts) > 60) continue;
            if (x.action == "close") {
                double pf = r4((prev.profitNull ? 0 : atof_s(prev.profit)) + (x.profitNull ? 0 : atof_s(x.profit)));
                prev.profit = jnum(pf);
                prev.fee = jnum(atof_s(prev.fee) + atof_s(x.fee));
                prev.funding = jnum(atof_s(prev.funding) + atof_s(x.funding));
                if (atof_s(x.price) > 0) prev.price = x.price;
            } else {
                double notional = atof_s(prev.notional) + atof_s(x.notional);
                double sz = atof_s(prev.sz) + atof_s(x.sz);
                bool prevEngine = prev.remark.find("hybrid open") != std::string::npos || prev.remark.find("gold5m") != std::string::npos;
                bool rowEngine = x.remark.find("hybrid open") != std::string::npos || x.remark.find("gold5m") != std::string::npos;
                if (rowEngine && !prevEngine) prev.remark = x.remark;
                if (sz > 0) prev.sz = jnum(sz);
                prev.notional = jnum(notional);
                if (sz > 0 && notional > 0) prev.price = jnum(floor(notional / sz * 1e8) / 1e8);
                prev.fee = jnum(atof_s(prev.fee) + atof_s(x.fee));
                if (prev.ord_id.empty()) prev.ord_id = x.ord_id;
            }
            merged = true;
            break;
        }
        if (merged) continue;
        if (x.action == "close") {
            if (x.profitNull) {
                auto it = ph.find(x.inst_id);
                if (it != ph.end())
                    for (auto& p : it->second) {
                        long long pts = parse_dt(p[0]);
                        long long d = pts > x.ts ? pts - x.ts : x.ts - pts;
                        if (d <= 600) {
                            x.profit = p[1]; x.profitNull = false;
                            if (atof_s(x.fee) == 0) x.fee = p[2];
                            if (atof_s(x.funding) == 0) x.funding = p[3];
                            break;
                        }
                    }
            }
            if (atof_s(x.price) == 0 && x.profitNull) continue;   // 空台账行丢弃
        }
        x.strat = (x.action == "buy") ? strat_of(x.remark) : "";
        rows.push_back(std::move(x));
    }
    std::sort(rows.begin(), rows.end(), [](const FR& a, const FR& b) { return a.trade_time > b.trade_time; });
    if (rows.size() > 500) rows.resize(500);
    std::string body;
    for (auto& x : rows) {
        if (!body.empty()) body += ",";
        body += "{\"trade_time\":\"" + jesc(x.trade_time) + "\",\"inst_id\":\"" + jesc(x.inst_id) +
                "\",\"action\":\"" + jesc(x.action) + "\",\"price\":\"" + jesc(x.price) +
                "\",\"profit\":" + (x.profitNull ? "null" : "\"" + jesc(x.profit) + "\"") +
                ",\"pnl\":" + (x.profitNull ? "null" : "\"" + jesc(x.profit) + "\"") +
                ",\"entries\":0,\"ord_id\":\"" + jesc(x.ord_id) + "\",\"remark\":\"" + jesc(x.remark) +
                "\",\"fee\":\"" + jesc(x.fee) + "\",\"funding\":\"" + jesc(x.funding) +
                "\",\"sz\":\"" + jesc(x.sz) + "\",\"notional_usd\":\"" + jesc(x.notional) +
                "\",\"strat\":\"" + jesc(x.strat) + "\"}";
    }
    return "{\"ok\":true,\"data\":[" + body + "]}";
}

// ---- /stats ----
std::string ep_stats(const Params&) {
    const char* W = "profit IS NOT NULL AND profit <> 0 AND action NOT IN ('signal','guard') AND remark LIKE 'OKX pos-history%'";
    RowSet daily = db_q(std::string("SELECT DATE(trade_time) d, SUM(IF(profit>0,profit,0)) win_pnl, SUM(IF(profit<0,profit,0)) loss_pnl,") +
                        " SUM(profit) net, SUM(COALESCE(fee,0)) fee_sum, SUM(COALESCE(funding,0)) fund_sum," +
                        " SUM(IF(profit>0,1,0)) wins, COUNT(*) total FROM trade_flow WHERE " + W +
                        " GROUP BY DATE(trade_time) ORDER BY d DESC LIMIT 30");
    RowSet sum = db_q(std::string("SELECT COUNT(*) total, COALESCE(SUM(IF(p>0,1,0)),0) wins, COALESCE(SUM(p),0) net_pnl, COALESCE(AVG(p),0) avg_pnl,") +
                      " (SELECT COALESCE(SUM(fee),0) FROM trade_flow WHERE " + W + ") fee_sum," +
                      " (SELECT COALESCE(SUM(funding),0) FROM trade_flow WHERE " + W + ") fund_sum" +
                      " FROM (SELECT profit p FROM trade_flow WHERE " + W + ") t");
    std::string djs;
    if (daily.ok)
        for (auto& r : daily.rows) {
            if (!djs.empty()) djs += ",";
            djs += "{\"d\":\"" + jesc(r[0]) + "\",\"win_pnl\":\"" + jesc(r[1]) + "\",\"loss_pnl\":\"" +
                   jesc(r[2]) + "\",\"net\":\"" + jesc(r[3]) + "\",\"fee_sum\":\"" + jesc(r[4]) +
                   "\",\"fund_sum\":\"" + jesc(r[5]) + "\",\"wins\":\"" + jesc(r[6]) + "\",\"total\":\"" + jesc(r[7]) + "\"}";
        }
    std::string sjs = "null";
    if (sum.ok && !sum.rows.empty()) {
        auto& r = sum.rows[0];
        sjs = "{\"total\":\"" + jesc(r[0]) + "\",\"wins\":\"" + jesc(r[1]) + "\",\"net_pnl\":\"" + jesc(r[2]) +
              "\",\"avg_pnl\":\"" + jesc(r[3]) + "\",\"fee_sum\":\"" + jesc(r[4]) + "\",\"fund_sum\":\"" + jesc(r[5]) + "\"}";
    }
    RowSet bt = db_q("SELECT COUNT(*) total, COALESCE(SUM(pnl),0) net_pnl FROM backtest_trades WHERE pnl IS NOT NULL");
    std::string bjs = "null";
    if (bt.ok && !bt.rows.empty())
        bjs = "{\"total\":\"" + jesc(bt.rows[0][0]) + "\",\"net_pnl\":\"" + jesc(bt.rows[0][1]) + "\"}";
    RowSet sn = db_q("SELECT snap_time, pnl, open_pos, pos_margin FROM pnl_history ORDER BY ts DESC LIMIT 1");
    std::string njs = "null";
    if (sn.ok && !sn.rows.empty()) {
        auto& r = sn.rows[0];
        njs = "{\"snap_time\":\"" + jesc(r[0]) + "\",\"pnl\":\"" + jesc(r[1]) + "\",\"open_pos\":\"" +
              jesc(r[2]) + "\",\"pos_margin\":\"" + jesc(r[3]) + "\"}";
    }
    return "{\"ok\":true,\"data\":{\"daily\":[" + djs + "],\"summary\":" + sjs + ",\"backtest\":" + bjs + ",\"last_snap\":" + njs + "}}";
}

// ---- /livestats ----
std::string ep_livestats(const Params& q) {
    std::string inst = get_inst(q);
    std::string cached;
    if (cache_get(g_lsCache, inst, cached)) return cached;   // 1秒微缓存
    RowSet s = db_q("SELECT COUNT(*) n, COALESCE(SUM(IF(profit>0,1,0)),0) wins, COALESCE(SUM(profit),0) net,"
                    " COALESCE(SUM(COALESCE(fee,0)),0) fee_sum, COALESCE(SUM(COALESCE(funding,0)),0) fund_sum"
                    " FROM trade_flow WHERE action='close' AND profit IS NOT NULL AND remark LIKE 'OKX pos-history%'");
    std::string nTr, nBuys;
    {
        bool b1, b2;
        nTr = db_scalar("SELECT COUNT(*) FROM trade_flow WHERE action IN ('buy','add','close')", b1);
        nBuys = db_scalar("SELECT COUNT(*) FROM trade_flow WHERE action IN ('buy','add')", b2);
        if (!b1) nTr = "0";
        if (!b2) nBuys = "0";
    }
    RowSet snap = db_q("SELECT pnl, open_pos, pos_margin, snap_time FROM pnl_history ORDER BY ts DESC LIMIT 1");
    // 当前合约实时资金费率(OKX公共): 60s 缓存, 不再每请求都打 OKX
    std::string fjs = "null";
    if (!cache_get(g_fundCache, inst, fjs)) {
        fjs = "null";
        std::string body;
        if (http_get("www.okx.com", "/api/v5/public/funding-rate?instId=" + inst, body)) {
            if (body.find("\"code\":\"0\"") != std::string::npos) {
                std::string rate = json_str(body, "fundingRate");
                std::string next = json_str(body, "nextFundingRate");
                std::string fts = json_str(body, "fundingTime");
                if (!rate.empty() && numok(rate))
                    fjs = "{\"rate\":" + rate + ",\"next\":" + (numok(next) ? next : "0") +
                          ",\"ts\":" + (numok(fts) ? fts : "0") + "}";
            }
        }
        if (fjs != "null") cache_put(g_fundCache, inst, fjs, 60000);   // 成功才缓存(失败下次重试)
    }
    std::string net = "0", closed = "0", wins = "0", feeS = "0", fundS = "0";
    if (s.ok && !s.rows.empty()) {
        net = jnum(r4(atof_s(s.rows[0][2])));
        closed = s.rows[0][0]; wins = s.rows[0][1];
        feeS = jnum(r4(atof_s(s.rows[0][3]))); fundS = jnum(r4(atof_s(s.rows[0][4])));
    }
    std::string upl = "0", openPos = "0", posM = "0", snapTime = "null";
    if (snap.ok && !snap.rows.empty()) {
        upl = jnum(r4(atof_s(snap.rows[0][0])));
        openPos = snap.rows[0][1];
        posM = jnum(r4(atof_s(snap.rows[0][2])));
        snapTime = "\"" + jesc(snap.rows[0][3]) + "\"";
    }
    std::string out = "{\"ok\":true,\"data\":{\"net_pnl\":" + net + ",\"closed\":" + closed + ",\"wins\":" + wins +
           ",\"fee_sum\":" + feeS + ",\"fund_sum\":" + fundS + ",\"trades\":" + nTr + ",\"open_trades\":" + nBuys +
           ",\"upl\":" + upl + ",\"open_pos\":" + openPos + ",\"pos_margin\":" + posM +
           ",\"snap_time\":" + snapTime + ",\"funding\":" + fjs + ",\"inst\":\"" + jesc(inst) + "\"}}";
    cache_put(g_lsCache, inst, out, 1000);   // 1秒微缓存(页面3秒轮询, 多客户端共享)
    return out;
}

// ---- /gridmon ----
std::string ep_gridmon(const Params&) {
    std::string rows = "[]", logs = "[]", params = "null";
    RowSet r1 = db_q("SELECT inst_id,updated,last_px,avg_px,atr_value,units,ladder_adds,max_levels,this_level_px,"
                     " next_down_px,next_up_px,dist_down_pct,dist_up_pct,next_add_usd,next_mult,"
                     " sl_px,tp_px,sl_pct,tp_pct,state,note FROM grid_signal WHERE state <> '已平仓'"
                     " ORDER BY (state LIKE '★%' OR state LIKE '等待%') DESC, dist_down_pct ASC LIMIT 60");
    if (r1.ok && !r1.rows.empty()) {
        rows = "[";
        for (size_t i = 0; i < r1.rows.size(); i++) {
            auto& r = r1.rows[i];
            const char* K[] = { "inst_id","updated","last_px","avg_px","atr_value","units","ladder_adds","max_levels",
                                "this_level_px","next_down_px","next_up_px","dist_down_pct","dist_up_pct",
                                "next_add_usd","next_mult","sl_px","tp_px","sl_pct","tp_pct","state","note" };
            std::string o = "{";
            for (size_t k = 0; k < 21; k++) {
                if (k) o += ",";
                o += std::string("\"") + K[k] + "\":\"" + jesc(r[k]) + "\"";
            }
            rows += o + "}";
            if (i + 1 < r1.rows.size()) rows += ",";
        }
        rows += "]";
    }
    RowSet r2 = db_q("SELECT log_time,inst_id,level,last_px,trigger_px,add_usd,add_sz,kind,state,note"
                     " FROM grid_signal_log ORDER BY id DESC LIMIT 120");
    if (r2.ok && !r2.rows.empty()) {
        logs = "[";
        for (size_t i = 0; i < r2.rows.size(); i++) {
            auto& r = r2.rows[i];
            const char* K[] = { "log_time","inst_id","level","last_px","trigger_px","add_usd","add_sz","kind","state","note" };
            std::string o = "{";
            for (size_t k = 0; k < 10; k++) {
                if (k) o += ",";
                o += std::string("\"") + K[k] + "\":\"" + jesc(r[k]) + "\"";
            }
            logs += o + "}";
            if (i + 1 < r2.rows.size()) logs += ",";
        }
        logs += "]";
    }
    RowSet sj = db_q("SELECT sval FROM app_settings WHERE skey='strategy_json'");
    if (sj.ok && !sj.rows.empty() && sj.rows[0][0].find('{') == 0 && json_valid(sj.rows[0][0]))
        params = sj.rows[0][0];
    else if (sj.ok && !sj.rows.empty() && !sj.rows[0][0].empty())
        logline("gridmon: strategy_json 非法JSON, 已降级为 null (长度 " + std::to_string(sj.rows[0][0].size()) + ")");
    return "{\"ok\":true,\"data\":{\"rows\":" + rows + ",\"logs\":" + logs + ",\"params\":" + params + "}}";
}

// ---- /backcheck ----
std::string ep_backcheck(const Params&) {
    FILE* f = fopen("E:\\datas\\log\\backcheck.txt", "rb");
    std::string txt = "暂无体检报告。";
    if (f) {
        char buf[65537]; size_t n = fread(buf, 1, 65536, f); fclose(f);
        txt.assign(buf, n);
    }
    return "{\"ok\":true,\"data\":\"" + jesc(txt) + "\"}";
}

// ---- /marks ----
std::string ep_marks(const Params& q) {
    std::string inst = get_inst(q);
    int days = atoi(P(q, "days", "7").c_str());
    if (days < 1) days = 1;
    if (days > 30) days = 30;
    std::string D = " trade_time >= NOW() - INTERVAL " + std::to_string(days) + " DAY";
    struct Mk { long long t; std::string kind; std::string px; std::string profit; std::string strat; };
    std::vector<Mk> out;
    // 买入: 引擎开仓行(整仓均价)
    RowSet rs = db_q("SELECT UNIX_TIMESTAMP(trade_time), price, remark FROM trade_flow"
                     " WHERE inst_id='" + inst + "' AND action='buy' AND remark LIKE 'hybrid open%' AND" + D +
                     " ORDER BY trade_time ASC");
    if (rs.ok)
        for (auto& r : rs.rows)
            out.push_back({ atoll_s(r[0]) * 1000, "buy", jnum(atof_s(r[1])), "null", strat_of_remark(r[2]) });
    // 加仓: 引擎加仓行
    rs = db_q("SELECT UNIX_TIMESTAMP(trade_time), price FROM trade_flow"
              " WHERE inst_id='" + inst + "' AND action='add' AND" + D + " ORDER BY trade_time ASC");
    if (rs.ok)
        for (auto& r : rs.rows)
            out.push_back({ atoll_s(r[0]) * 1000, "add", jnum(atof_s(r[1])), "null", "gold5m" });
    // 平仓: OKX真实盈利行(按分钟聚合)
    rs = db_q("SELECT FLOOR(UNIX_TIMESTAMP(trade_time)/60)*60, SUM(COALESCE(profit,0)), MAX(price)"
              " FROM trade_flow WHERE inst_id='" + inst + "' AND action='close' AND profit IS NOT NULL"
              " AND remark LIKE 'OKX pos-history%' AND" + D + " GROUP BY FLOOR(UNIX_TIMESTAMP(trade_time)/60)*60"
              " ORDER BY FLOOR(UNIX_TIMESTAMP(trade_time)/60)*60 ASC");
    if (rs.ok)
        for (auto& r : rs.rows) {
            double pf = atof_s(r[1]);
            char pb[40]; snprintf(pb, 40, "%.2f", pf);
            out.push_back({ atoll_s(r[0]) * 1000, "close", jnum(atof_s(r[2])), pb, "" });
        }
    std::sort(out.begin(), out.end(), [](const Mk& a, const Mk& b) { return a.t < b.t; });
    std::string body;
    for (auto& m : out) {
        if (!body.empty()) body += ",";
        body += "{\"t\":" + std::to_string(m.t) + ",\"kind\":\"" + m.kind + "\",\"px\":" + m.px +
                ",\"profit\":" + m.profit + ",\"strat\":\"" + jesc(m.strat) + "\"}";
    }
    return "{\"ok\":true,\"data\":[" + body + "]}";
}

// ---- /sigs (金▲转折点+力学, 链接 sigcore.cpp 同源计算) ----
std::string ep_sigs(const Params& q) {
    std::string inst = get_inst(q);
    std::string bar = P(q, "bar", "5m");
    if (!valid_bar(bar)) bar = "5m";
    std::vector<Bar> rows = read_recent(inst, bar, (int)std::min<long long>(2000, std::max<long long>(60, numok(P(q, "limit")) ? atoll_s(P(q, "limit")) : 500)));
    long long from = numok(P(q, "from")) ? atoll_s(P(q, "from")) : 0;
    long long to = numok(P(q, "to")) ? atoll_s(P(q, "to")) : 0;
    if (from > 0) {
        std::vector<Bar> f;
        for (auto& r : rows) if (r.tms >= from) f.push_back(r);
        rows.swap(f);
    }
    if (to > 0) {
        std::vector<Bar> f;
        for (auto& r : rows) if (r.tms <= to) f.push_back(r);
        rows.swap(f);
    }
    int n = (int)rows.size();
    if (n < 10) return "{\"ok\":true,\"data\":[]}";
    std::vector<double> flat(n * 5);
    for (int i = 0; i < n; i++) {
        flat[i * 5] = rows[i].o; flat[i * 5 + 1] = rows[i].h; flat[i * 5 + 2] = rows[i].l;
        flat[i * 5 + 3] = rows[i].c; flat[i * 5 + 4] = rows[i].v;
    }
    std::vector<int> idx(n + 2), typ(n + 2), bars(n + 2);
    std::vector<double> pv(n + 2), ke(n + 2), g(n + 2), f(n + 2), v(n + 2), m(n + 2), ang(n + 2), a2(n + 2);
    double th = 0;
    int np = pivots_full(flat.data(), n, bar.c_str(), idx.data(), typ.data(), pv.data(),
                         ke.data(), g.data(), f.data(), v.data(), m.data(), ang.data(), a2.data(),
                         bars.data(), &th, n + 2);
    std::string body;
    for (int k = 0; k < np; k++) {
        if (idx[k] < 0 || idx[k] >= n) continue;
        if (!body.empty()) body += ",";
        body += "{\"t\":" + std::to_string(rows[idx[k]].tms) +
                ",\"i\":" + std::to_string(idx[k]) +
                ",\"p\":" + jnum(pv[k]) +
                ",\"type\":" + std::to_string(typ[k]) +
                ",\"ke\":" + jnum(ke[k]) + ",\"g\":" + jnum(g[k]) + ",\"f\":" + jnum(f[k]) +
                ",\"v\":" + jnum(v[k]) + ",\"m\":" + jnum(m[k]) + ",\"ang\":" + jnum(ang[k]) +
                ",\"a\":" + jnum(a2[k]) + ",\"bars\":" + std::to_string(bars[k]) + "}";
    }
    return "{\"ok\":true,\"bar\":\"" + jesc(bar) + "\",\"th\":" + jnum(th) +
           ",\"n\":" + std::to_string(n) + ",\"data\":[" + body + "]}";
}

// ---- /sigscan (30s 后台缓存) ----
static std::mutex g_scanMtx;
static std::string g_scanCache = "{\"ok\":true,\"bar\":\"5m\",\"pool\":0,\"data\":[]}";
void sigscan_rebuild() {
    RowSet pool = db_q("SELECT inst_id FROM symbol_pool ORDER BY inst_id");
    if (!pool.ok || pool.rows.empty()) return;
    std::string hits;
    int poolN = (int)pool.rows.size();
    for (auto& r : pool.rows) {
        std::string inst = r[0];
        std::vector<Bar> rows = read_recent(inst, "5m", 300);
        if ((int)rows.size() < 30) continue;
        int n = (int)rows.size();
        std::vector<double> flat(n * 5);
        for (int i = 0; i < n; i++) {
            flat[i * 5] = rows[i].o; flat[i * 5 + 1] = rows[i].h; flat[i * 5 + 2] = rows[i].l;
            flat[i * 5 + 3] = rows[i].c; flat[i * 5 + 4] = rows[i].v;
        }
        double ke = 0, th = 0; int dist = 0;
        char info[160] = { 0 };
        int fired = gold_signal(flat.data(), n, "5m", &ke, &th, &dist, info, 160);
        if (fired == 1) {
            if (!hits.empty()) hits += ",";
            hits += "{\"inst\":\"" + jesc(inst) + "\",\"ke\":" + jnum(ke) + ",\"dist\":" +
                    std::to_string(dist) + ",\"info\":\"" + jesc(info) + "\"}";
            if (hits.size() > 4096) break;
        }
        Sleep(2);
    }
    std::string body = "{\"ok\":true,\"bar\":\"5m\",\"pool\":" + std::to_string(poolN) + ",\"data\":[" + hits + "]}";
    std::lock_guard<std::mutex> lk(g_scanMtx);
    g_scanCache = body;
}
void sigscan_loop() {
    logline("sigscan 缓存线程启动 (30s)");
    while (true) {
        sigscan_rebuild();
        Sleep(30000);
    }
}
std::string ep_sigscan(const Params& q) {
    std::string bar = P(q, "bar", "5m");
    if (!valid_bar(bar)) bar = "5m";
    if (bar == "5m") {
        std::lock_guard<std::mutex> lk(g_scanMtx);
        return g_scanCache;
    }
    // 非默认周期: 现算(低频场景)
    RowSet pool = db_q("SELECT inst_id FROM symbol_pool ORDER BY inst_id");
    std::string hits;
    if (pool.ok)
        for (auto& r : pool.rows) {
            std::vector<Bar> rows = read_recent(r[0], bar, 300);
            if ((int)rows.size() < 30) continue;
            int n = (int)rows.size();
            std::vector<double> flat(n * 5);
            for (int i = 0; i < n; i++) {
                flat[i * 5] = rows[i].o; flat[i * 5 + 1] = rows[i].h; flat[i * 5 + 2] = rows[i].l;
                flat[i * 5 + 3] = rows[i].c; flat[i * 5 + 4] = rows[i].v;
            }
            double ke = 0, th = 0; int dist = 0;
            char info[160] = { 0 };
            if (gold_signal(flat.data(), n, bar.c_str(), &ke, &th, &dist, info, 160) == 1) {
                if (!hits.empty()) hits += ",";
                hits += "{\"inst\":\"" + jesc(r[0]) + "\",\"ke\":" + jnum(ke) + ",\"dist\":" +
                        std::to_string(dist) + ",\"info\":\"" + jesc(info) + "\"}";
            }
        }
    int poolN = pool.ok ? (int)pool.rows.size() : 0;
    return "{\"ok\":true,\"bar\":\"" + bar + "\",\"pool\":" + std::to_string(poolN) + ",\"data\":[" + hits + "]}";
}

// ---- /boot (页面引导数据: 默认合约 + 最近交易合约) ----
std::string ep_boot(const Params&) {
    RowSet rs = db_q("SELECT inst_id FROM trade_flow"
                     " WHERE action IN ('buy','add','close') AND trade_time >= NOW() - INTERVAL 48 HOUR"
                     " GROUP BY inst_id ORDER BY MAX(id) DESC LIMIT 6");
    std::string arr;
    if (rs.ok)
        for (auto& r : rs.rows) {
            if (!arr.empty()) arr += ",";
            arr += "\"" + jesc(r[0]) + "\"";
        }
    std::string def = rs.ok && !rs.rows.empty() ? rs.rows[0][0] : "ETH-USDT-SWAP";
    return "{\"ok\":true,\"default\":\"" + jesc(def) + "\",\"recent\":[" + arr + "]}";
}

std::string ep_symbols(const Params&) {
    RowSet rs = db_q("SELECT inst_id FROM symbol_pool ORDER BY inst_id");
    std::string body;
    if (rs.ok)
        for (auto& r : rs.rows) {
            if (!body.empty()) body += ",";
            body += "\"" + jesc(r[0]) + "\"";
        }
    if (body.empty()) return "{\"ok\":true,\"data\":[\"ETH-USDT-SWAP\"]}";
    return "{\"ok\":true,\"data\":[" + body + "]}";
}

// ---- /settings ----
std::string ep_settings(const Params& q) {
    std::string skey = P(q, "skey");
    if (!skey.empty()) {
        std::string k;
        for (char c : skey) {
            char l = (c >= 'A' && c <= 'Z') ? c + 32 : c;
            if ((l >= 'a' && l <= 'z') || (l >= '0' && l <= '9') || l == '_') k += l;
        }
        if (k.empty()) return "{\"ok\":false,\"error\":\"bad skey\"}";
        std::string sval = P(q, "sval");
        db_q("INSERT INTO app_settings (skey,sval) VALUES ('" + k + "','" + sqlesc(sval) +
             "') ON DUPLICATE KEY UPDATE sval=VALUES(sval)");
        return "{\"ok\":true,\"skey\":\"" + k + "\",\"sval\":\"" + jesc(sval) + "\"}";
    }
    RowSet rs = db_q("SELECT skey,sval FROM app_settings");
    std::string body;
    if (rs.ok)
        for (auto& r : rs.rows) {
            if (!body.empty()) body += ",";
            body += "\"" + jesc(r[0]) + "\":\"" + jesc(r[1]) + "\"";
        }
    return "{\"ok\":true,\"data\":{" + body + "}}";
}

// ---- /account (OKX 私有签名) ----
std::string ep_account(const Params&) {
    std::string body;
    std::string err = okx_private_get("/api/v5/account/balance?ccy=USDT", body);
    if (!err.empty()) return "{\"ok\":false,\"error\":\"" + err + "\"}";
    bool codeOk = body.find("\"code\":\"0\"") != std::string::npos;
    std::string avail = json_str(body, "availBal"), eq = json_str(body, "eq"), mgn = json_str(body, "mgnRatio");
    std::string pbody;
    int posN = 0;
    if (okx_private_get("/api/v5/account/positions", pbody).empty()) {
        size_t p = 0;
        while ((p = pbody.find("\"instId\"", p)) != std::string::npos) { posN++; p += 8; }
    }
    if (!codeOk) return "{\"ok\":false,\"error\":\"okx code!=0\",\"positions\":" + std::to_string(posN) + "}";
    return "{\"ok\":true,\"avail\":" + (numok(avail) ? avail : "0") + ",\"equity\":" + (numok(eq) ? eq : "0") +
           ",\"mgnRatio\":" + (mgn.empty() ? "null" : "\"" + mgn + "\"") + ",\"positions\":" + std::to_string(posN) + "}";
}

// ---- /health ----
std::string ep_health(const Params&) {
    FILE* f = fopen("E:\\datas\\log\\backcheck.txt", "rb");
    std::string rep;
    if (f) {
        char buf[8193]; size_t n = fread(buf, 1, 8000, f); fclose(f);
        rep.assign(buf, n);
    }
    RowSet pc = db_q("SELECT COUNT(*) FROM symbol_pool");
    int poolN = (pc.ok && !pc.rows.empty()) ? atoi(pc.rows[0][0].c_str()) : 0;
    return "{\"ok\":true,\"dll\":{\"version\":20270927,\"series\":" + std::to_string(store_stats()) +
           "},\"pool\":{\"count\":" + std::to_string(poolN) +
           "},\"lock\":{\"lever\":20,\"entry_usd\":1.0,\"add_usd\":0.3333,\"tp_roi\":0.40,\"sl\":\"永不止损\",\"max_positions\":12},\"report\":\"" +
           jesc(rep) + "\"}";
}

// ---- /guard (守护中枢状态) ----
std::string ep_guard(const Params&) {
    FILE* f = fopen("E:\\datas\\log\\guard_status.json", "rb");
    if (!f) {
        return "{\"ok\":false,\"error\":\"守护状态文件不存在(guard 服务未运行?)\","
               "\"stale\":1,\"targets\":[],\"issues\":\"守护未运行\"}";
    }
    char buf[16385];
    size_t n = fread(buf, 1, 16384, f);
    fclose(f);
    std::string body(buf, n);
    while (!body.empty() && (body.back() == '\n' || body.back() == '\r' || body.back() == ' ')) body.pop_back();
    if (!json_valid(body)) {
        logline("守护状态文件非法JSON, 已降级返回");
        return "{\"ok\":false,\"error\":\"守护状态文件损坏\",\"stale\":1,\"targets\":[],\"issues\":\"守护状态异常\"}";
    }
    // 判断状态是否新鲜(超过 30 秒未更新 → 守护可能已死)
    long long age = -1;
    {
        WIN32_FILE_ATTRIBUTE_DATA fa;
        if (GetFileAttributesExA("E:\\datas\\log\\guard_status.json", GetFileExInfoStandard, &fa)) {
            FILETIME now; GetSystemTimeAsFileTime(&now);
            ULARGE_INTEGER a, b;
            a.LowPart = fa.ftLastWriteTime.dwLowDateTime; a.HighPart = fa.ftLastWriteTime.dwHighDateTime;
            b.LowPart = now.dwLowDateTime;                b.HighPart = now.dwHighDateTime;
            age = (b.QuadPart > a.QuadPart) ? (long long)((b.QuadPart - a.QuadPart) / 10000000ULL) : 0;
        }
    }
    std::string extra = ",\"age_sec\":" + std::to_string(age) +
                        ",\"stale\":" + std::string(age < 0 || age > 30 ? "1" : "0");
    if (!body.empty() && body.back() == '}') { body.pop_back(); body += extra + "}"; }
    return body;
}
