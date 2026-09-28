/* ==================================================================
 * trade_data.cpp — K线数据层 + 合约池 + sigcore 内存库包装
 *
 * 【文件职责】
 *   引擎的"数据供给层"：三块能力——
 *   ① K线数据层: 周期→毫秒换算、读最近N根、OKX拉取入库、
 *      引擎取数(DB 最近300根, 缺表/不足/太旧自动从 OKX 补)
 *   ② 合约池: symbol_pool 权威池 30 分钟进程内缓存(pool_cached)、
 *      全量合约反推(information_schema + 权威池兜底, 回填用)
 *   ③ sigcore.dll 内存库包装: feed 喂增量 → 数据滞留本进程内存 →
 *      storePit/storeGold 在内存库上直算信号(零 marshaling 开销)。
 *      启动喂库全池 5m+3m 约 502 个序列。
 *
 * 【函数清单】
 *   kl_iv_ms()       周期字符串 → 毫秒(1m/3m/5m/15m/30m/1h/4h)
 *   kl_read_recent() 读某合约某周期最近 limit 根(委托 common)
 *   kl_fetch_store() OKX 拉 K线 → 入库(store)
 *   kl_engine_rows() 引擎取数: DB 300根, 缺表/不足100根/落后3根以上自动补
 *   pool_cached()    权威池缓存(30分钟 TTL, DB失败退回旧池)
 *   pool_all_insts() 全量合约(表名反推 + 权威池兜底, 回填用)
 *   sig_feed()       Bar 数组摊平成 ts[]+ohlcv[] 喂 sigcore 内存库
 *   sig_store_pit()  坑▲信号判定(内存库直算)
 *   sig_store_gold() 金▲信号判定(自适应动能锚, 买入/加仓依据)
 *   sig_store_stats() 内存库序列数统计
 *
 * 【数据流】
 *   OKX candles → kl_fetch_store → DB K线表(kline_<BASE>_<tf>)
 *   DB K线表 → kl_engine_rows → sig_feed → sigcore.dll 内存库
 *   内存库 → sig_store_gold(金▲) → trade_engine 买入/加仓判定
 *   symbol_pool 表 → pool_cached → 引擎扫描白名单
 * ================================================================== */
// ==================================================================
// trade_data.cpp — K线数据层 + 合约池 + sigcore 内存库包装
//   逐字对齐 inc/lib/Klines.php 与 inc/lib/SymbolPool.php 与 inc/lib/SigCore.php
//   内存库语义: feed() 喂增量 → 数据滞留本进程内存 → storePit() 直算零开销
// ==================================================================
#include "tradehub.h"                          // 本组件公共声明
#include <cstdio>                              // 标准IO
#include <cmath>                               // floor
#include <ctime>                               // time
#include <cctype>                              // toupper
#include <set>                                 // std::set(去重)

// ---------------- K线周期毫秒 (Klines::IVS 同口径) ----------------
long long kl_iv_ms(const std::string& tf) {    // 入参: 周期字符串
    if (tf == "1m")  return 60LL * 1000;       // 1m  → 60秒
    if (tf == "3m")  return 180LL * 1000;      // 3m  → 180秒(加仓周期)
    if (tf == "5m")  return 300LL * 1000;      // 5m  → 300秒(买入周期)
    if (tf == "15m") return 900LL * 1000;      // 15m → 900秒
    if (tf == "30m") return 1800LL * 1000;     // 30m → 1800秒
    if (tf == "1h" || tf == "1H") return 3600LL * 1000;      // 1h → 3600秒
    if (tf == "4h" || tf == "4H") return 14400LL * 1000;     // 4h → 14400秒
    return 300LL * 1000;                       // 未知周期兜底为 5m
}

std::vector<Bar> kl_read_recent(const std::string& inst, const std::string& tf, int limit) {   // 合约/周期/条数
    return read_recent(inst, tf, limit);       // common 组件同实现
}

int kl_fetch_store(const std::string& inst, const std::string& tf, int limit,   // 合约/周期/条数
                   long long refMs, bool useRef, bool history, const char* dir) {   // 参考时间/用否/历史接口/翻页方向
    std::vector<std::vector<std::string>> rows;    // OKX 原始行
    if (!okx_candles(inst, tf, limit, refMs, useRef, history, dir, rows)) return 0;   // 拉取失败返回0
    return store(inst, tf, rows);              // 入库并返回写入条数
}

// 引擎取数: DB 最近 300 根, 缺表/不足/太旧自动从 OKX 补 (Klines::engineRows 同口径)
std::vector<Bar> kl_engine_rows(const std::string& inst, const std::string& tf) {   // 合约/周期
    std::string t = ktable(inst, tf);          // K线表名 kline_<BASE>_<tf>
    bool ok = false;                           // db_scalar 出参
    std::string ex = db_scalar("SELECT COUNT(*) FROM information_schema.tables"    // 查表是否存在
                               " WHERE table_schema='trading' AND table_name='" + t + "'", ok);
    if (!ok || atoll_s(ex) == 0) {             // 查询失败或表不存在
        ensure_table(inst, tf);                // 建表
        kl_fetch_store(inst, tf, 300, 0, false, false, "after");    // 从 OKX 拉 300 根入库
        return kl_read_recent(inst, tf, 300);  // 再读回
    }
    std::vector<Bar> rows = kl_read_recent(inst, tf, 300);   // 读 DB 最近 300 根
    long long barMs = kl_iv_ms(tf);            // 周期毫秒
    long long nowMs = now_ms();                // 当前毫秒
    long long slot = (long long)(floor((double)nowMs / (double)barMs) * (double)barMs);   // 当前周期槽起点
    bool stale = rows.empty() || (rows.back().tms < slot - 3 * barMs);   // 太旧=落后超过3根
    if ((int)rows.size() < 100 || stale) {     // 不足100根或太旧
        kl_fetch_store(inst, tf, 300, 0, false, false, "after");    // 从 OKX 补数
        rows = kl_read_recent(inst, tf, 300);  // 补完再读
    }
    return rows;                               // 返回引擎可直接用的 Bar 序列
}

// ---------------- 合约池 ----------------
// 只读缓存(不联网): 引擎每轮扫描用, 30 分钟 TTL
std::vector<std::string> pool_cached() {       // 权威池进程内缓存
    static std::vector<std::string> cache;     // 缓存本体
    static long long at = 0;                   // 上次刷新时间
    if (!cache.empty() && (long long)time(nullptr) - at < 1800) return cache;   // 30分钟内直接回缓存
    RowSet rs = db_q("SELECT inst_id FROM symbol_pool ORDER BY inst_id");   // 查权威池表
    if (rs.ok && !rs.rows.empty()) {           // 查到数据才刷新缓存
        cache.clear();                         // 清旧缓存
        for (auto& r : rs.rows) cache.push_back(r[0]);   // 逐行装入
        at = (long long)time(nullptr);         // 记刷新时间
        return cache;                          // 返回新池
    }
    if (cache.empty()) return std::vector<std::string>();   // 从未成功过 → 空池
    return cache;                              // DB 短暂失败时退回旧池, 避免空扫
}

// 全量合约(回填用): information_schema 反推 + 权威池兜底
std::vector<std::string> pool_all_insts() {    // 全量合约列表
    static std::vector<std::string> derived;   // 反推结果(进程内只算一次)
    static bool inited = false;                // 是否已初始化
    if (!inited) {                             // 首次调用才扫 information_schema
        inited = true;                         // 标记已初始化
        std::set<std::string> set;             // 去重集合
        RowSet rs = db_q("SELECT DISTINCT table_name FROM information_schema.tables"    // 列出所有K线表
                         " WHERE table_schema='trading' AND table_name LIKE 'kline\\_%'");
        if (rs.ok) {                           // 查询成功
            const char* tfs[] = { "1m", "3m", "5m", "15m", "30m", "1h", "4h" };   // 已知周期后缀
            for (auto& r : rs.rows) {          // 遍历每个表名
                if (r.empty() || r[0].size() <= 6) continue;   // 太短跳过
                std::string t = r[0].substr(6);           // 去 "kline_"
                for (const char* tf : tfs) {   // 尝试匹配每个周期后缀
                    std::string sfx = std::string("_") + tf;   // 拼 "_tf" 后缀
                    if (t.size() <= sfx.size()) continue;      // 长度不够跳过
                    if (t.compare(t.size() - sfx.size(), sfx.size(), sfx) != 0) continue;   // 后缀不匹配
                    std::string base = t.substr(0, t.size() - sfx.size());   // 剩余部分=基础名
                    std::string inst;              // 还原合约 ID
                    for (char c : base) inst += (c == '_' ? '-' : (char)toupper((unsigned char)c));   // 下划线→横杠, 小写→大写
                    if (inst.size() > 10 && inst.compare(inst.size() - 10, 10, "-USDT-SWAP") == 0)   // 必须是 USDT 永续
                        set.insert(inst);
                    break;                         // 匹配到一个周期即停
                }
            }
        }
        for (auto& s : set) derived.push_back(s);   // 落入静态结果
    }
    std::set<std::string> all(derived.begin(), derived.end());   // 反推结果先行
    for (auto& p : pool_cached()) all.insert(p);    // 权威池兜底合并
    return std::vector<std::string>(all.begin(), all.end());   // 返回并集
}

