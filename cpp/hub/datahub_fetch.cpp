// ==================================================================
// datahub_fetch.cpp — datahub 抓取组件 (拆分自 datahub.cpp, 逻辑零改动)
//   专属限频HTTP层: 全局匀速排队 rate_wait + 429/50011 全局退避 + 自适应 g_minMs
//   fetch_store / fill_forward / tail_fetch / 进度缓存 / 合约列表
//   注: 本组件 HTTP 与 common/hub_okx 不同(有限频队列), 因此为 datahub 专属
// ==================================================================
#include "datahub.h"
#include <winhttp.h>
#include <cstdio>
#include <cstring>
#include <ctime>
#include <cmath>
#include <map>
#include <algorithm>

// ---------------- WinHTTP GET (datahub 专属: 匀速限流+429退避) ----------------
static std::atomic<long long> g_throttleUntil(0);   // 429限频退避(全局)
std::atomic<int> g_429count(0);                     // 本轮429次数(hub_loop自适应用)
// 全局匀速限流器: 所有HTTP统一排队, 每个请求间隔 g_minMs(默认160ms), 平滑无突发
std::atomic<int> g_minMs(160);
static std::mutex g_rlMtx;
static long long g_nextSlot = 0;
static void rate_wait() {
    long long wait = 0;
    {
        std::lock_guard<std::mutex> lk(g_rlMtx);
        long long now = GetTickCount64();
        if (g_nextSlot < now) g_nextSlot = now;
        wait = g_nextSlot - now;
        g_nextSlot += g_minMs.load();
    }
    if (wait > 0) Sleep((DWORD)wait);
}
static bool dh_http_get(const std::string& path, std::string& out) {
    for (int a = 0; a < 3; a++) {
        long long th = g_throttleUntil.load();
        long long nowll = GetTickCount64();
        if (nowll < th) Sleep((DWORD)(th - nowll));     // 全局限频退避
        rate_wait();                                    // 全局匀速排队
        HINTERNET ses = WinHttpOpen(L"Mozilla/5.0 datahub/1.0", WINHTTP_ACCESS_TYPE_DEFAULT_PROXY,
                                    WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, 0);
        if (!ses) { Sleep(2000); continue; }
        HINTERNET con = WinHttpConnect(ses, L"www.okx.com", INTERNET_DEFAULT_HTTPS_PORT, 0);
        bool got = false;
        if (con) {
            std::wstring wpath(path.begin(), path.end());
            HINTERNET req = WinHttpOpenRequest(con, L"GET", wpath.c_str(), nullptr,
                                               WINHTTP_NO_REFERER, WINHTTP_DEFAULT_ACCEPT_TYPES,
                                               WINHTTP_FLAG_SECURE);
            if (req) {
                DWORD tmo = 15000;
                WinHttpSetOption(req, WINHTTP_OPTION_RECEIVE_TIMEOUT, &tmo, sizeof(tmo));
                if (WinHttpSendRequest(req, WINHTTP_NO_ADDITIONAL_HEADERS, 0,
                                       WINHTTP_NO_REQUEST_DATA, 0, 0, 0) && WinHttpReceiveResponse(req, nullptr)) {
                    DWORD st = 0, sz = sizeof(st);
                    WinHttpQueryHeaders(req, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,
                                        WINHTTP_HEADER_NAME_BY_INDEX, &st, &sz, WINHTTP_NO_HEADER_INDEX);
                    if (st == 200) {
                        out.clear();
                        char buf[16384]; DWORD rd = 0;
                        while (WinHttpReadData(req, buf, sizeof(buf), &rd) && rd) { out.append(buf, rd); rd = 0; }
                        if (out.find("\"code\":\"50011\"") != std::string::npos) {
                            // OKX限频(HTTP 200 + 50011): 全局退避6秒
                            g_throttleUntil = GetTickCount64() + 6000;
                            g_429count.fetch_add(1);
                            got = false;
                        } else {
                            got = true;
                        }
                    } else if (st == 429) {
                        // OKX限频: 全局退避6秒, 不再立即重试
                        g_throttleUntil = GetTickCount64() + 6000;
                        g_429count.fetch_add(1);
                        logline("OKX 429 限频 → 全局退避6s");
                        break;
                    }
                }
                WinHttpCloseHandle(req);
            }
            WinHttpCloseHandle(con);
        }
        WinHttpCloseHandle(ses);
        if (got) return true;
        Sleep(2000);
    }
    return false;
}

// ETH live 专用入口: 走同一限频队列
bool dl_http_get(const std::string& path, std::string& out) {
    return dh_http_get(path, out);
}

// ---------------- OKX bar 参数规范: 小时必须大写 H ----------------
static std::string okx_bar(const std::string& tf) {
    std::string b = tf;
    for (auto& c : b) if (c == 'h') c = 'H';
    return b;
}

// fetchStore: 拉OKX K线入库(可带 after/before 参数), 返回写入数
int fetch_store(const std::string& inst, const std::string& tf, int limit,
                bool useBefore, long long ref) {
    char path[512];
    if (ref > 0)
        snprintf(path, sizeof(path),
            "/api/v5/market/candles?instId=%s&bar=%s&limit=%d&%s=%lld",
            inst.c_str(), okx_bar(tf).c_str(), limit, useBefore ? "before" : "after", ref);
    else
        snprintf(path, sizeof(path),
            "/api/v5/market/candles?instId=%s&bar=%s&limit=%d",
            inst.c_str(), okx_bar(tf).c_str(), limit);
    std::string body;
    if (!dh_http_get(path, body)) return -1;
    return store(inst, tf, okx_parse_candles(body));
}

// fillForward (与 Klines.php 同口径): 追平返回0; 返回写入根数
long long fill_forward(const std::string& inst, const std::string& tf) {
    bool ok;
    std::string mxs = db_scalar("SELECT MAX(candle_time) FROM " + ktable(inst, tf), ok);
    if (!ok) { ensure_table(inst, tf); return fetch_store(inst, tf, 300, false, 0); }
    long long max = mxs.empty() ? 0 : atoll(mxs.c_str());
    const char* IVS[] = {"1m","3m","5m","15m","30m","1h","4h","1H","6H","12H","1D"};
    const int   IVS_SEC[] = {60,180,300,900,1800,3600,14400,3600,21600,43200,86400};
    int barSec = 300;
    std::string tfL;
    for (char c : tf) tfL += (c >= 'A' && c <= 'Z') ? c + 32 : c;
    for (int i = 0; i < 11; i++)
        if (tfL == IVS[i] || (i >= 7 && tf == IVS[i])) { barSec = IVS_SEC[i]; break; }
    long long nowMs = (long long)(floor(time(nullptr) * 1000.0 / (barSec * 1000)) * barSec * 1000);
    if (max >= nowMs - 2LL * barSec * 1000) return 0;   // 已追平
    long long total = 0, ref = max;
    while (ref < nowMs && total < 1200) {
        int w = fetch_store(inst, tf, 300, true, ref);
        if (w < 0) { logline("追平取数失败(限频/网络): " + inst + " " + tf); break; }
        if (w == 0) { logline("追平返回0行(OKX无数据/已到顶): " + inst + " " + tf + " ref=" + std::to_string(ref)); break; }
        total += w;
        bool ok2;
        std::string nm = db_scalar("SELECT MAX(candle_time) FROM " + ktable(inst, tf), ok2);
        long long newRef = ok2 ? atoll(nm.c_str()) : 0;
        if (newRef <= ref) break;
        ref = newRef;
        Sleep(40); // 限频由 rate_wait() 统一节流, 这里只做轻微礼让
    }
    if (total > 0) logline("追平 " + inst + " " + tf + " +" + std::to_string(total) + " 根");
    return total;
}

// ---------------- 周期秒数 ----------------
int bar_sec(const std::string& tf) {
    const char* IVS[] = {"1m","3m","5m","15m","30m","1h","4h","6h","12h","1d"};
    const int   SEC[] = {60,180,300,900,1800,3600,14400,21600,43200,86400};
    std::string tfL;
    for (char c : tf) tfL += (c >= 'A' && c <= 'Z') ? c + 32 : c;
    for (int i = 0; i < 10; i++) if (tfL == IVS[i]) return SEC[i];
    return 300;
}

// ---------------- 进度缓存: (inst,tf)->最新bar时间 (内存化, 零多余DB查询) ----------------
struct TfState { long long mx = 0; bool loaded = false; };
static std::map<std::string, std::map<std::string, TfState>> g_prog;
static std::mutex g_progMtx;

long long prog_get(const std::string& inst, const std::string& tf) {
    std::lock_guard<std::mutex> lk(g_progMtx);
    TfState& st = g_prog[inst][tf];
    if (!st.loaded) {
        bool ok;
        std::string v = db_scalar("SELECT MAX(candle_time) FROM " + ktable(inst, tf), ok);
        st.mx = (ok && !v.empty()) ? atoll(v.c_str()) : 0;
        st.loaded = true;
    }
    return st.mx;
}
void prog_set(const std::string& inst, const std::string& tf, long long v) {
    std::lock_guard<std::mutex> lk(g_progMtx);
    TfState& st = g_prog[inst][tf];
    if (v > st.mx) st.mx = v;
    st.loaded = true;
}

// ---------------- 全市场合约列表 (从DB表名反推, 与 SymbolPool::allInsts 同口径) ----------------
std::vector<std::string> all_insts() {
    std::vector<std::string> out;
    RowSet rs = db_q("SELECT DISTINCT table_name FROM information_schema.tables"
                     " WHERE table_schema='trading' AND table_name LIKE 'kline\\_%'");
    std::map<std::string, bool> seen;
    const char* TFS[] = {"1m","3m","5m","15m","30m","1h","4h"};
    for (auto& r : rs.rows) {
        std::string t = r[0];
        if (t.size() <= 6) continue;
        t = t.substr(6); // 去 kline_
        for (auto* tf : TFS) {
            std::string sfx = std::string("_") + tf;
            if (t.size() > sfx.size() && t.compare(t.size() - sfx.size(), sfx.size(), sfx) == 0) {
                std::string inst = t.substr(0, t.size() - sfx.size());
                for (auto& c : inst) if (c == '_') c = '-';
                for (auto& c : inst) if (c >= 'a' && c <= 'z') c -= 32;
                if (inst.size() > 10 && inst.compare(inst.size() - 10, 10, "-USDT-SWAP") == 0 && !seen[inst]) {
                    seen[inst] = true;
                    out.push_back(inst);
                }
                break;
            }
        }
    }
    return out;
}

// ---------------- 交易池 (symbol_pool 表) ----------------
std::vector<std::string> pool_insts() {
    std::vector<std::string> out;
    RowSet rs = db_q("SELECT inst_id FROM symbol_pool");
    for (auto& r : rs.rows) if (!r[0].empty()) out.push_back(r[0]);
    return out;
}

// ---------------- 热合约(近48h有成交): 唯一需要1m实时的一小撮 ----------------
std::vector<std::string> hot_insts() {
    std::vector<std::string> out;
    RowSet rs = db_q("SELECT inst_id, MAX(id) mid FROM trade_flow"
                     " WHERE action IN ('buy','add','close') AND trade_time >= NOW() - INTERVAL 48 HOUR"
                     " GROUP BY inst_id ORDER BY mid DESC LIMIT 30");
    for (auto& r : rs.rows) if (!r[0].empty()) out.push_back(r[0]);
    return out;
}

// ---------------- 增量拉尾部(事件驱动): 拉最新limit根入库+更新进度, 返回写入数 ----------------
int tail_fetch(const std::string& inst, const std::string& tf, int limit) {
    int w = fetch_store(inst, tf, limit, false, 0);
    if (w >= 0) {
        bool ok;
        std::string v = db_scalar("SELECT MAX(candle_time) FROM " + ktable(inst, tf), ok);
        prog_set(inst, tf, ok ? atoll(v.c_str()) : 0);
    }
    return w;
}
