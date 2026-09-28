// ==================================================================
// trade_okx.cpp — OKX V5 客户端组件 (逐字对齐 inc/lib/OkxClient.php)
//   公共: instruments(tickers/ticker/candles) / ctVal
//   私有: positions / set-leverage(自适应降档) / order(市价买) /
//         close-position / orders-algo-pending+cancel-algos / fills-history
// 签名复用 common/hub_okx.cpp 的 okx_private(BCrypt HMAC-SHA256)
// ==================================================================
#include "tradehub.h"
#include <cstdio>
#include <cstring>
#include <cmath>
#include <chrono>

// ---------------- 时间工具 ----------------
long long now_ms() {
    using namespace std::chrono;
    return (long long)duration_cast<milliseconds>(system_clock::now().time_since_epoch()).count();
}
std::string ms_to_dt(long long ms) {
    time_t t = (time_t)(ms / 1000);
    struct tm lt; localtime_s(&lt, &t);
    char b[32]; strftime(b, sizeof(b), "%Y-%m-%d %H:%M:%S", &lt);
    return b;
}
long long local_dt_to_unix(const char* s) {
    int Y = 0, Mo = 0, D = 0, h = 0, mi = 0, se = 0;
    if (sscanf(s, "%d-%d-%d %d:%d:%d", &Y, &Mo, &D, &h, &mi, &se) != 6) return 0;
    struct tm tmv; memset(&tmv, 0, sizeof(tmv));
    tmv.tm_year = Y - 1900; tmv.tm_mon = Mo - 1; tmv.tm_mday = D;
    tmv.tm_hour = h; tmv.tm_min = mi; tmv.tm_sec = se;
    tmv.tm_isdst = -1;
    return (long long)mktime(&tmv);
}
std::string today_str() {
    time_t t = time(nullptr); struct tm lt; localtime_s(&lt, &t);
    char b[16]; strftime(b, sizeof(b), "%Y-%m-%d", &lt);
    return b;
}

// ---------------- 公共接口 ----------------
bool okx_public_get(const std::string& path, std::string& body) {
    return http_get("www.okx.com", path, body);
}

double okx_ctval(const std::string& inst) {
    static std::map<std::string, double> cache;
    auto it = cache.find(inst);
    if (it != cache.end()) return it->second;
    std::string body;
    double v = 0.0;
    if (okx_public_get("/api/v5/public/instruments?instType=SWAP&instId=" + inst, body) &&
        body.find("\"code\":\"0\"") != std::string::npos)
        v = j_num(body, "ctVal");
    cache[inst] = v;
    return v;
}

double okx_last_price(const std::string& inst) {
    std::string body;
    if (!okx_public_get("/api/v5/market/ticker?instId=" + inst, body)) return 0.0;
    if (body.find("\"code\":\"0\"") == std::string::npos) return 0.0;
    return j_num(body, "last");
}

// K线: dir=after 取比 refMs 更旧 / before 取比 refMs 更新 (与 PHP 同口径)
bool okx_candles(const std::string& inst, const std::string& bar, int limit,
                 long long refMs, bool useRef, bool history, const char* dir,
                 std::vector<std::vector<std::string>>& rows) {
    if (limit > 300) limit = 300;
    if (limit < 1) limit = 1;
    std::string ep = history ? "/api/v5/market/history-candles" : "/api/v5/market/candles";
    std::string url = ep + "?instId=" + inst + "&bar=" + bar + "&limit=" + std::to_string(limit);
    if (useRef) url += std::string("&") + dir + "=" + std::to_string(refMs);
    std::string body;
    if (!okx_public_get(url, body)) return false;
    rows = okx_parse_candles(body);
    return !rows.empty();
}

// ---------------- 私有接口 ----------------
bool okx_positions(std::vector<std::string>& objs) {
    std::string body;
    if (!okx_private("GET", "/api/v5/account/positions?instType=SWAP", "", body)) return false;
    if (body.find("\"code\":\"0\"") == std::string::npos) return false;
    objs = j_split_objects(body);
    return true;
}

bool okx_position(const std::string& inst, std::string& obj) {
    std::string body;
    if (!okx_private("GET", "/api/v5/account/positions?instId=" + inst, "", body)) return false;
    if (body.find("\"code\":\"0\"") == std::string::npos) return false;
    auto objs = j_split_objects(body);
    if (objs.empty()) return false;
    obj = objs[0];
    return true;
}

// 自适应设杠杆: 从 want 逐级降档直到成功 (LUNA 等上限低于 20x 的合约)
int okx_set_leverage_adaptive(const std::string& inst, int want) {
    int lv = want;
    while (lv >= 1) {
        std::string b = "{\"instId\":\"" + inst + "\",\"lever\":\"" + std::to_string(lv) +
                        "\",\"mgnMode\":\"cross\"}";
        std::string resp;
        if (okx_private("POST", "/api/v5/account/set-leverage", b, resp) &&
            resp.find("\"code\":\"0\"") != std::string::npos)
            return lv;
        std::string msg = j_str(resp, "sMsg");
        if (msg.empty()) msg = j_str(resp, "msg");
        bool leverErr = (msg.find("51169") != std::string::npos) ||
                        (msg.find("leverage") != std::string::npos) ||
                        (msg.find("杠杆") != std::string::npos);
        if (leverErr) lv = (lv >= 10) ? lv - 10 : (lv >= 5 ? lv - 3 : lv - 1);
        else          lv = (lv >= 10) ? lv - 10 : lv - 1;
    }
    return 1;
}

bool okx_market_buy(const std::string& inst, int sz, std::string& resp) {
    std::string b = "{\"instId\":\"" + inst + "\",\"tdMode\":\"cross\",\"side\":\"buy\","
                    "\"posSide\":\"long\",\"ordType\":\"market\",\"sz\":\"" + std::to_string(sz) + "\"}";
    return okx_private("POST", "/api/v5/trade/order", b, resp);
}

bool okx_close_position(const std::string& inst, std::string& resp) {
    std::string b = "{\"instId\":\"" + inst + "\",\"mgnMode\":\"cross\","
                    "\"posSide\":\"long\",\"cldOrdPx\":\"\"}";
    return okx_private("POST", "/api/v5/trade/close-position", b, resp);
}

// 撤销全部未触发条件单 (系统不挂止损止盈单, 开仓后清理历史残留)
void okx_cancel_all_algos(const std::string& inst) {
    std::string body;
    if (!okx_private("GET", "/api/v5/trade/orders-algo-pending?ordType=conditional&instId=" + inst, "", body))
        return;
    if (body.find("\"code\":\"0\"") == std::string::npos) return;
    std::string list;
    for (auto& o : j_split_objects(body)) {
        std::string aid = j_str(o, "algoId");
        if (aid.empty()) continue;
        if (!list.empty()) list += ",";
        list += "{\"algoId\":\"" + aid + "\",\"instId\":\"" + inst + "\"}";
    }
    if (list.empty()) return;
    std::string resp;
    okx_private("POST", "/api/v5/trade/cancel-algos", "[" + list + "]", resp);
}

// 最近 minutes 分钟平仓成交 → [pnl 合计, 出场均价]
bool okx_fills_pnl(const std::string& inst, int minutes, double& pnl, double& exitPx) {
    pnl = 0.0; exitPx = 0.0;
    long long begin = now_ms() - (long long)minutes * 60000LL;
    std::string path = "/api/v5/trade/fills-history?instType=SWAP&instId=" + inst +
                       "&begin=" + std::to_string(begin) + "&limit=100";
    std::string body;
    if (!okx_private("GET", path, "", body)) return false;
    if (body.find("\"code\":\"0\"") == std::string::npos) return false;
    double pxv = 0.0, szSum = 0.0;
    for (auto& f : j_split_objects(body)) {
        if (j_str(f, "side") != "sell") continue;
        pnl += j_num(f, "fillPnl");
        double sz = j_num(f, "fillSz");
        pxv += sz * j_num(f, "fillPx");
        szSum += sz;
    }
    exitPx = szSum > 0 ? pxv / szSum : 0.0;
    return true;
}
