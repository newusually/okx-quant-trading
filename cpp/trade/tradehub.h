/* ==================================================================
 * tradehub.h — 交易引擎组件声明头文件
 *
 * 【文件职责】
 *   tradehub 交易中枢的公共声明头：集中声明主壳、OKX 客户端、K线数据层、
 *   合约池、sigcore 内存库包装、引擎主循环、回填循环的对外函数，以及
 *   "交易铁律"硬锁常量(LOCK_*)，供 tradehub_main / trade_engine /
 *   trade_data / trade_okx / trade_backfill 五个源文件统一引用。
 *
 * 【函数清单】
 *   主壳(tradehub_main.cpp):      tlog / eng_log / trade_hb / g_run
 *   时间工具(trade_okx.cpp):      now_ms / ms_to_dt / local_dt_to_unix / today_str
 *   OKX 客户端(trade_okx.cpp):    okx_public_get / okx_ctval / okx_last_price /
 *                                 okx_positions / okx_position /
 *                                 okx_set_leverage_adaptive / okx_market_buy /
 *                                 okx_close_position / okx_cancel_all_algos /
 *                                 okx_fills_pnl / okx_candles
 *   K线数据层(trade_data.cpp):    kl_iv_ms / kl_read_recent / kl_fetch_store /
 *                                 kl_engine_rows
 *   合约池(trade_data.cpp):       pool_cached / pool_all_insts
 *   sigcore 包装(trade_data.cpp): sig_feed / sig_store_pit / sig_store_gold /
 *                                 sig_store_stats
 *   引擎(trade_engine.cpp):       engine_loop
 *   回填+池同步(trade_backfill.cpp): backfill_loop / pool_sync_if_due
 *
 * 【数据流】
 *   OKX REST → okx_candles → kl_fetch_store → DB K线表
 *   DB K线表 → kl_engine_rows → sig_feed → sigcore.dll 内存库(数据滞留进程内)
 *   内存库 → sig_store_gold(金▲信号) → trade_engine 买入/加仓决策
 *   决策 → okx_set_leverage_adaptive(实际杠杆) → okx_market_buy(市价买)
 *        → position_detail / trade_flow 台账
 *   OKX fills / positions-history → trade_backfill → trade_flow 盈亏回填
 *
 * 【策略硬锁常量 LOCK_*】(真钱交易核心口径, 代码级硬锁, 不可绕过)
 *   LOCK_LEVER=20          20 倍全仓(cross)交叉杠杆
 *   LOCK_ENTRY_USD=2.0     每笔开仓保证金默认 2 USDT(可被同目录 trade_cfg.json entry_usd 热覆盖)
 *   LOCK_ADD_USD=1.0       跌时加仓每次默认 +1 USDT(可被 trade_cfg.json add_usd 热覆盖; 同根3m只加1次)
 *   LOCK_TP_ROI=0.40       止盈 ROI 备用口径(40%)
 *   LOCK_TP_PCT=0.02       止盈唯一口径: 止盈价格=均价+2%(与 tphub 判定同源)
 *   LOCK_MAX_POSITIONS=12  全局最大同时持仓 12 个
 *   LOCK_MAX_BUYS_HOUR=3   每小时最多买入 3 笔
 *   LOCK_MAX_NEW_SCAN=1    单次扫描最多新开 1 单
 *   LOCK_COOLDOWN_MIN=60   同合约买入冷却 60 分钟
 *   止损: 完全禁用(永不止损); 止盈由 tphub.exe 实时接管, 本进程不卖
 * ================================================================== */
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
//   买入 = 5m+3m 金▲共振 · 2U · 20X cross · 只买 symbol_pool
//   加仓 = 跌时(现价<均价) 3m 金▲ · +1U · 同根3m只加1次 · 单合约30分冷却 · 全局每小时≤6
//   止盈 = tphub.exe 实时接管(本进程不碰)
//   止损 = 永不止损
//   闸门 = 持仓12 / 每小时买3 / 每轮开1 / 同合约冷却60分
// ==================================================================
#pragma once                                   // 防止头文件被重复包含
#include "../common/hub.h"                     // 公共组件: DB/HTTP/JSON/OKX签名/Bar 结构等
#include <string>                              // std::string
#include <vector>                              // std::vector
#include <set>                                 // std::set

// ---------------- 交易铁律常量 (bootstrap.php 同口径) ----------------
#define LOCK_LEVER          20                 // 硬锁: 20 倍全仓(cross)交叉杠杆
#define LOCK_ENTRY_USD      2.0                // 硬锁默认: 每笔开仓保证金 2 USDT(trade_cfg.json 可热覆盖)
#define LOCK_ADD_USD        1.0                // 硬锁默认: 跌时加仓每次 +1 USDT(可热覆盖; 同根3m不重复加)
#define LOCK_TP_ROI         0.40               // 硬锁: 止盈 ROI 备用口径 40%(tphub 接管)
// 止盈价格口径(唯一口径, 与 tphub 判定 / gridmon 的 tp_px / 网页K线止盈线 同源, 杠杆无关)
#define LOCK_TP_PCT         0.02               // 硬锁: 止盈价格=均价+2%(唯一止盈口径)
#define LOCK_MAX_POSITIONS  12                 // 硬锁: 全局最大同时持仓 12 个
#define LOCK_MAX_BUYS_HOUR  3                  // 硬锁: 每小时最多买入 3 笔
#define LOCK_MAX_NEW_SCAN   1                  // 硬锁: 单次扫描最多新开 1 单
#define LOCK_COOLDOWN_MIN   60                 // 硬锁: 同合约买入冷却 60 分钟(止损完全禁用)

extern const int FILLS_BACKFILL_MIN;           // 每轮回看 180 分钟成交
long long cutoff_ms();                         // 2026-09-27 22:40:00(本地) → 毫秒

// ---------------- 主壳 (tradehub_main.cpp) ----------------
void tlog(const std::string& s);                          // tradehub.txt 文本日志
void eng_log(const char* level, const char* module, const std::string& msg);  // logs表 + 文件
void trade_hb(const char* name);                          // 心跳文件 hb_<name>.txt
extern volatile bool g_run;                               // 全局运行标志

// ---------------- 时间工具 (trade_okx.cpp) ----------------
long long now_ms();                                       // 当前 Unix 毫秒时间戳
std::string ms_to_dt(long long ms);                       // ms → "Y-m-d H:i:s"(本地)
long long local_dt_to_unix(const char* s);                // "Y-m-d H:i:s"(本地) → unix 秒
std::string today_str();                                  // "Y-m-d"

// ---------------- OKX 客户端 (trade_okx.cpp) ----------------
bool   okx_public_get(const std::string& path, std::string& body);   // OKX 公共 GET 请求
double okx_ctval(const std::string& inst);                // 每张面值(进程内缓存)
double okx_last_price(const std::string& inst);           // 最新成交价(公共 ticker)
bool   okx_positions(std::vector<std::string>& objs);     // 全部持仓对象数组
bool   okx_position(const std::string& inst, std::string& obj);      // 单合约持仓对象
int    okx_set_leverage_adaptive(const std::string& inst, int want); // 自适应设杠杆(降档防保证金翻倍)
bool   okx_market_buy(const std::string& inst, int sz, std::string& resp); // 市价买入
bool   okx_close_position(const std::string& inst, std::string& resp);     // 市价平仓
void   okx_cancel_all_algos(const std::string& inst);     // 撤销全部未触发条件单(不挂止损止盈)
bool   okx_fills_pnl(const std::string& inst, int minutes, double& pnl, double& exitPx); // 成交盈亏统计
bool   okx_candles(const std::string& inst, const std::string& bar, int limit,   // 拉 K线
                   long long refMs, bool useRef, bool history, const char* dir,
                   std::vector<std::vector<std::string>>& rows);

// ---------------- K线数据层 (trade_data.cpp) ----------------
long long kl_iv_ms(const std::string& tf);                // 周期字符串 → 毫秒
std::vector<Bar> kl_read_recent(const std::string& inst, const std::string& tf, int limit); // 读最近N根
int  kl_fetch_store(const std::string& inst, const std::string& tf, int limit,   // OKX拉K线入库
                    long long refMs, bool useRef, bool history, const char* dir);
std::vector<Bar> kl_engine_rows(const std::string& inst, const std::string& tf); // 引擎取数(缺自动补)

// ---------------- 合约池 (trade_data.cpp) ----------------
std::vector<std::string> pool_cached();      // symbol_pool 表(30分钟进程内缓存)
std::vector<std::string> pool_all_insts();   // information_schema 反推 + 权威池

// ---------------- sigcore 内存库包装 (trade_data.cpp) ----------------
int  sig_feed(const std::string& inst, const std::string& bar, const std::vector<Bar>& rows); // 喂K线进内存库
bool sig_store_pit(const std::string& inst, const std::string& bar,  // 坑▲信号判定(直算内存库)
                   double& score, std::string& info);
// 金▲信号(自适应动能锚): 最新转折=底部 + 距最新K线<=8根 + KE>=该合约底部KE的75分位
bool sig_store_gold(const std::string& inst, const std::string& bar, // 金▲信号判定(买入/加仓依据)
                    double& ke, std::string& info);
int  sig_store_stats();                                   // 内存库序列数统计

// ---------------- 引擎 (trade_engine.cpp) ----------------
void engine_loop();                                       // 引擎主循环(10秒节拍: 买入/加仓/对账/快照)

// ---------------- 回填 + 池同步 (trade_backfill.cpp) ----------------
void backfill_loop();                                     // 回填守护主循环(3秒节拍)
void pool_sync_if_due();                                  // 每日 00:30 后同步一次
