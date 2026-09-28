// ==================================================================
// tradehub.h — C++ 交易中枢组件头
// 替代 engine.php(交易引擎) + backfill.php(成交回填) + pool_sync.php(池同步)
//
// 组件划分(单文件均 < 300 行):
//   trade_okx.cpp      OKX V5 客户端(签名/委托/持仓/K线/时间工具)
//   trade_data.cpp     K线数据层 + 合约池 + sigcore 内存库包装
//   trade_engine.cpp   交易引擎主循环(买入/加仓/对账/快照)
//   trade_backfill.cpp 成交回填 + 洞扫描 + 池同步
//   tradehub_main.cpp  主壳(单实例/日志/心跳/双线程)
//
// 交易铁律(与 bootstrap.php 逐字一致, 代码级硬锁):
//   买入 = 15m+5m 黄金坑共振 · 1U · 20X cross · 只买 symbol_pool
//   加仓 = 跌时(现价<均价) 5m 黄金坑 · +1U/3 · 单合约30分冷却 · 全局每小时≤6
//   止盈 = tphub.exe 实时接管(本进程不碰)
//   止损 = 永不止损
//   闸门 = 持仓12 / 每小时买3 / 每轮开1 / 同合约冷却60分
// ==================================================================
#pragma once
#include "../common/hub.h"
#include <string>
#include <vector>
#include <set>

// ---------------- 交易铁律常量 (bootstrap.php 同口径) ----------------
#define LOCK_LEVER          20
#define LOCK_ENTRY_USD      1.0
#define LOCK_ADD_USD        (1.0 / 3.0)
#define LOCK_TP_ROI         0.40
// 止盈价格口径(唯一口径, 与 tphub 判定 / gridmon 的 tp_px / 网页K线止盈线 同源, 杠杆无关)
#define LOCK_TP_PCT         0.02
#define LOCK_MAX_POSITIONS  12
#define LOCK_MAX_BUYS_HOUR  3
#define LOCK_MAX_NEW_SCAN   1
#define LOCK_COOLDOWN_MIN   60

extern const int FILLS_BACKFILL_MIN;     // 每轮回看 180 分钟成交
long long cutoff_ms();                   // 2026-09-27 22:40:00(本地) → 毫秒

// ---------------- 主壳 (tradehub_main.cpp) ----------------
void tlog(const std::string& s);                          // tradehub.txt 文本日志
void eng_log(const char* level, const char* module, const std::string& msg);  // logs表 + 文件
void trade_hb(const char* name);                          // 心跳文件 hb_<name>.txt
extern volatile bool g_run;                                // 全局运行标志

// ---------------- 时间工具 (trade_okx.cpp) ----------------
long long now_ms();
std::string ms_to_dt(long long ms);                        // ms → "Y-m-d H:i:s"(本地)
long long local_dt_to_unix(const char* s);                 // "Y-m-d H:i:s"(本地) → unix 秒
std::string today_str();                                   // "Y-m-d"

// ---------------- OKX 客户端 (trade_okx.cpp) ----------------
bool   okx_public_get(const std::string& path, std::string& body);
double okx_ctval(const std::string& inst);                 // 每张面值(进程内缓存)
double okx_last_price(const std::string& inst);
bool   okx_positions(std::vector<std::string>& objs);      // 全部持仓对象数组
bool   okx_position(const std::string& inst, std::string& obj);
int    okx_set_leverage_adaptive(const std::string& inst, int want);
bool   okx_market_buy(const std::string& inst, int sz, std::string& resp);
bool   okx_close_position(const std::string& inst, std::string& resp);
void   okx_cancel_all_algos(const std::string& inst);
bool   okx_fills_pnl(const std::string& inst, int minutes, double& pnl, double& exitPx);
bool   okx_candles(const std::string& inst, const std::string& bar, int limit,
                   long long refMs, bool useRef, bool history, const char* dir,
                   std::vector<std::vector<std::string>>& rows);

// ---------------- K线数据层 (trade_data.cpp) ----------------
long long kl_iv_ms(const std::string& tf);
std::vector<Bar> kl_read_recent(const std::string& inst, const std::string& tf, int limit);
int  kl_fetch_store(const std::string& inst, const std::string& tf, int limit,
                    long long refMs, bool useRef, bool history, const char* dir);
std::vector<Bar> kl_engine_rows(const std::string& inst, const std::string& tf);

// ---------------- 合约池 (trade_data.cpp) ----------------
std::vector<std::string> pool_cached();      // symbol_pool 表(30分钟进程内缓存)
std::vector<std::string> pool_all_insts();   // information_schema 反推 + 权威池

// ---------------- sigcore 内存库包装 (trade_data.cpp) ----------------
int  sig_feed(const std::string& inst, const std::string& bar, const std::vector<Bar>& rows);
bool sig_store_pit(const std::string& inst, const std::string& bar,
                   double& score, std::string& info);
// 金▲信号(自适应动能锚): 最新转折=底部 + 距最新K线<=8根 + KE>=该合约底部KE的75分位
bool sig_store_gold(const std::string& inst, const std::string& bar,
                    double& ke, std::string& info);
int  sig_store_stats();

// ---------------- 引擎 (trade_engine.cpp) ----------------
void engine_loop();

// ---------------- 回填 + 池同步 (trade_backfill.cpp) ----------------
void backfill_loop();
void pool_sync_if_due();                     // 每日 00:30 后同步一次
