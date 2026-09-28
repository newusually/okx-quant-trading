// ==================================================================
// datahub.h — datahub 内部组件间声明 (datahub_fetch ↔ datahub_main)
// ==================================================================
#pragma once
#include "../common/hub.h"
#include <atomic>
#include <mutex>

// ---- datahub_fetch.cpp ----
bool dl_http_get(const std::string& path, std::string& out);   // 专属限频HTTP入口(ETH live用)
int  fetch_store(const std::string& inst, const std::string& tf, int limit,
                 bool useBefore, long long ref);
long long fill_forward(const std::string& inst, const std::string& tf);
int  bar_sec(const std::string& tf);
int  tail_fetch(const std::string& inst, const std::string& tf, int limit);
std::vector<std::string> all_insts();
std::vector<std::string> pool_insts();
std::vector<std::string> hot_insts();
long long prog_get(const std::string& inst, const std::string& tf);
void prog_set(const std::string& inst, const std::string& tf, long long v);
extern std::atomic<int> g_429count;   // 本轮429次数(hub_loop自适应用)
extern std::atomic<int> g_minMs;      // 全局匀速限流(自适应调节)
