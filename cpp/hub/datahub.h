/* ==================================================================
 * datahub.h — datahub 数据中枢内部组件间声明 (datahub_fetch ↔ datahub_main)
 *
 * 【文件职责】
 *   datahub.exe 是全市场 K 线数据下载中枢（由计划任务 finally_fill1m 常驻拉起）。
 *   本头文件声明 datahub_fetch.cpp（专属限频抓取组件）与 datahub_main.cpp
 *   （HUB 主循环）之间共享的接口与全局变量，使两个编译单元解耦协作。
 *
 * 【函数清单（均实现在 datahub_fetch.cpp）】
 *   dl_http_get   : datahub 专属限频 HTTP GET 入口（ETH live 实时行情专用，
 *                   区别于 common 公共库的 http_get，走 rate_wait 匀速限流队列）
 *   fetch_store   : 拉 OKX K 线并入库（可带 after/before 翻页参数），返回写入根数
 *   fill_forward  : 增量追平某合约某周期的历史缺口，返回写入根数
 *   bar_sec       : 周期字符串 → 秒数（如 "5m"→300、"4h"→14400）
 *   tail_fetch    : 事件驱动增量拉尾部 limit 根并更新进度缓存
 *   all_insts     : 全市场合约列表（从 DB kline_ 表名反推）
 *   pool_insts    : 交易池合约列表（symbol_pool 表）
 *   hot_insts     : 热合约列表（近 48h 有成交，唯一需要 1m 实时的一小撮）
 *   prog_get/prog_set : (inst,tf)→最新 bar 时间的内存进度缓存读写
 *
 * 【全局变量】
 *   g_429count : 本轮 429 限频次数（hub_loop 自适应调节用）
 *   g_minMs    : 全局匀速限流间隔毫秒（自适应 120~300ms）
 * ================================================================== */
#pragma once                                   // 头文件防重复包含（MSVC 方式）
#include "../common/hub.h"                     // 引入公共组件：DB访问、日志、K线表名、OKX解析等
#include <atomic>                              // std::atomic：跨线程原子计数/标志
#include <mutex>                               // std::mutex：限流器与进度缓存的互斥锁

// ---- datahub_fetch.cpp ----                    以下声明均由 datahub_fetch.cpp 提供
bool dl_http_get(const std::string& path, std::string& out);   // 专属限频HTTP入口(ETH live用)
int  fetch_store(const std::string& inst, const std::string& tf, int limit,
                 bool useBefore, long long ref);               // 拉K线入库: useBefore决定 before(更新)/after(更旧) 翻页
long long fill_forward(const std::string& inst, const std::string& tf); // 增量追平历史缺口, 返回写入根数
int  bar_sec(const std::string& tf);            // 周期字符串→秒数, 未识别默认300秒(5m)
int  tail_fetch(const std::string& inst, const std::string& tf, int limit); // 增量拉最新limit根+更新进度, 返回写入数
std::vector<std::string> all_insts();           // 全市场合约列表(从DB kline_ 表名反推, 与 SymbolPool 同口径)
std::vector<std::string> pool_insts();          // 交易池合约列表(A1/B2 分级队列来源, symbol_pool 表)
std::vector<std::string> hot_insts();           // 热合约列表(A0 最高级, 近48h有成交, ≤30个)
long long prog_get(const std::string& inst, const std::string& tf); // 读进度缓存: 该(合约,周期)最新bar毫秒时间
void prog_set(const std::string& inst, const std::string& tf, long long v); // 写进度缓存(只增不减)
extern std::atomic<int> g_429count;   // 本轮429次数(hub_loop自适应用)
extern std::atomic<int> g_minMs;      // 全局匀速限流(自适应调节, 范围120~300ms)
