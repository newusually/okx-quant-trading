// ==================================================================
// trade_data.cpp — K线数据层 + 合约池 + sigcore 内存库包装
//   逐字对齐 inc/lib/Klines.php 与 inc/lib/SymbolPool.php 与 inc/lib/SigCore.php
//   内存库语义: feed() 喂增量 → 数据滞留本进程内存 → storePit() 直算零开销
// ==================================================================
#include "tradehub.h"
#include <cstdio>
#include <cmath>
#include <ctime>
#include <cctype>
#include <set>

// ---------------- K线周期毫秒 (Klines::IVS 同口径) ----------------
long long kl_iv_ms(const std::string& tf) {
    if (tf == "1m")  return 60LL * 1000;
    if (tf == "3m")  return 180LL * 1000;
    if (tf == "5m")  return 300LL * 1000;
    if (tf == "15m") return 900LL * 1000;
    if (tf == "30m") return 1800LL * 1000;
    if (tf == "1h" || tf == "1H") return 3600LL * 1000;
    if (tf == "4h" || tf == "4H") return 14400LL * 1000;
    return 300LL * 1000;
}

std::vector<Bar> kl_read_recent(const std::string& inst, const std::string& tf, int limit) {
    return read_recent(inst, tf, limit);   // common 组件同实现
}

int kl_fetch_store(const std::string& inst, const std::string& tf, int limit,
                   long long refMs, bool useRef, bool history, const char* dir) {
    std::vector<std::vector<std::string>> rows;
    if (!okx_candles(inst, tf, limit, refMs, useRef, history, dir, rows)) return 0;
    return store(inst, tf, rows);
}

// 引擎取数: DB 最近 300 根, 缺表/不足/太旧自动从 OKX 补 (Klines::engineRows 同口径)
std::vector<Bar> kl_engine_rows(const std::string& inst, const std::string& tf) {
    std::string t = ktable(inst, tf);
    bool ok = false;
    std::string ex = db_scalar("SELECT COUNT(*) FROM information_schema.tables"
                               " WHERE table_schema='trading' AND table_name='" + t + "'", ok);
    if (!ok || atoll_s(ex) == 0) {
        ensure_table(inst, tf);
        kl_fetch_store(inst, tf, 300, 0, false, false, "after");
        return kl_read_recent(inst, tf, 300);
    }
    std::vector<Bar> rows = kl_read_recent(inst, tf, 300);
    long long barMs = kl_iv_ms(tf);
    long long nowMs = now_ms();
    long long slot = (long long)(floor((double)nowMs / (double)barMs) * (double)barMs);
    bool stale = rows.empty() || (rows.back().tms < slot - 3 * barMs);
    if ((int)rows.size() < 100 || stale) {
        kl_fetch_store(inst, tf, 300, 0, false, false, "after");
        rows = kl_read_recent(inst, tf, 300);
    }
    return rows;
}

// ---------------- 合约池 ----------------
// 只读缓存(不联网): 引擎每轮扫描用, 30 分钟 TTL
std::vector<std::string> pool_cached() {
    static std::vector<std::string> cache;
    static long long at = 0;
    if (!cache.empty() && (long long)time(nullptr) - at < 1800) return cache;
    RowSet rs = db_q("SELECT inst_id FROM symbol_pool ORDER BY inst_id");
    if (rs.ok && !rs.rows.empty()) {
        cache.clear();
        for (auto& r : rs.rows) cache.push_back(r[0]);
        at = (long long)time(nullptr);
        return cache;
    }
    if (cache.empty()) return std::vector<std::string>();
    return cache;   // DB 短暂失败时退回旧池, 避免空扫
}

// 全量合约(回填用): information_schema 反推 + 权威池兜底
std::vector<std::string> pool_all_insts() {
    static std::vector<std::string> derived;
    static bool inited = false;
    if (!inited) {
        inited = true;
        std::set<std::string> set;
        RowSet rs = db_q("SELECT DISTINCT table_name FROM information_schema.tables"
                         " WHERE table_schema='trading' AND table_name LIKE 'kline\\_%'");
        if (rs.ok) {
            const char* tfs[] = { "1m", "3m", "5m", "15m", "30m", "1h", "4h" };
            for (auto& r : rs.rows) {
                if (r.empty() || r[0].size() <= 6) continue;
                std::string t = r[0].substr(6);          // 去 "kline_"
                for (const char* tf : tfs) {
                    std::string sfx = std::string("_") + tf;
                    if (t.size() <= sfx.size()) continue;
                    if (t.compare(t.size() - sfx.size(), sfx.size(), sfx) != 0) continue;
                    std::string base = t.substr(0, t.size() - sfx.size());
                    std::string inst;
                    for (char c : base) inst += (c == '_' ? '-' : (char)toupper((unsigned char)c));
                    if (inst.size() > 10 && inst.compare(inst.size() - 10, 10, "-USDT-SWAP") == 0)
                        set.insert(inst);
                    break;
                }
            }
        }
        for (auto& s : set) derived.push_back(s);
    }
    std::set<std::string> all(derived.begin(), derived.end());
    for (auto& p : pool_cached()) all.insert(p);
    return std::vector<std::string>(all.begin(), all.end());
}

// ---------------- sigcore 内存库包装 ----------------
int sig_feed(const std::string& inst, const std::string& bar, const std::vector<Bar>& rows) {
    std::string key = inst + "|" + bar;
    int n = (int)rows.size();
    if (n <= 0) return store_count(key.c_str());
    std::vector<long long> ts((size_t)n);
    std::vector<double> b((size_t)n * 5);
    for (int i = 0; i < n; i++) {
        ts[(size_t)i] = rows[(size_t)i].tms;
        b[(size_t)i * 5]     = rows[(size_t)i].o;
        b[(size_t)i * 5 + 1] = rows[(size_t)i].h;
        b[(size_t)i * 5 + 2] = rows[(size_t)i].l;
        b[(size_t)i * 5 + 3] = rows[(size_t)i].c;
        b[(size_t)i * 5 + 4] = rows[(size_t)i].v;
    }
    return store_upsert(key.c_str(), ts.data(), b.data(), n);
}

bool sig_store_pit(const std::string& inst, const std::string& bar,
                   double& score, std::string& info) {
    std::string key = inst + "|" + bar;
    double sc = 0.0;
    char inf[256];
    inf[0] = 0;
    int r = store_pit(key.c_str(), bar.c_str(), &sc, inf, 256);
    score = sc;
    info = inf;
    return r == 1;
}

// 金▲: 用内存库里滞留的序列直算(零 marshaling), 返回 1=触发
bool sig_store_gold(const std::string& inst, const std::string& bar,
                    double& ke, std::string& info) {
    std::string key = inst + "|" + bar;
    double k = 0.0, th = 0.0;
    int dist = 0;
    char inf[256];
    inf[0] = 0;
    int r = store_gold(key.c_str(), bar.c_str(), &k, &th, &dist, inf, 256);
    ke = k;
    info = inf;
    return r == 1;
}

int sig_store_stats() { return store_stats(); }
