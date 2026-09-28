// ==================================================================
// hub.h — 公共组件头文件 (apihub / tradehub 等共用)
// 2026-09-28 组件化拆分(纯物理拆分, 逻辑零改动):
//   common/hub_db.cpp   MySQL 组件
//   common/hub_okx.cpp  WinHTTP + OKX 签名组件
//   common/hub_util.cpp 日志/JSON/缓存/K线读写组件
//   api/api_eps.cpp     接口实现
//   api/api_pages.cpp   零JS面板渲染
//   api/apihub_main.cpp HTTP服务器薄壳
// ==================================================================
#pragma once
#include <winsock2.h>
#include <windows.h>
#include <string>
#include <vector>
#include <map>
#include <mutex>
#include <functional>
#include <mysql.h>

struct RowSet { std::vector<std::vector<std::string>> rows; bool ok = false; };
struct Bar { long long tms; double o, h, l, c, v; };
typedef std::map<std::string, std::string> Params;

// ---------------- 日志组件 (hub_util) ----------------
void logline(const std::string& s);
void log_setfile(const char* path);   // 各组件exe启动时设置自己的日志路径(默认 apihub.log)

// ---------------- DB 组件 (hub_db) ----------------
extern MYSQL* g_my;
bool db_connect_locked();
RowSet db_q(const std::string& sql);
std::string db_scalar(const std::string& sql, bool& ok);
bool db_ex(const std::string& sql);

// ---------------- 工具组件 (hub_util) ----------------
bool numok(const std::string& s);
std::string jnum(double v);
std::string jesc(const std::string& s);
std::string sqlesc(const std::string& s);
long long atoll_s(const std::string& s);
double atof_s(const std::string& s);
std::string ktable(const std::string& inst, const std::string& tf);
std::vector<std::vector<std::string>> okx_parse_candles(const std::string& body);
std::string json_str(const std::string& body, const std::string& key);
void ensure_table(const std::string& inst, const std::string& tf);
int store(const std::string& inst, const std::string& tf,
          const std::vector<std::vector<std::string>>& bars);
std::vector<Bar> read_recent(const std::string& inst, const std::string& bar, int n);
void macd_full(const std::vector<double>& cl, int fast, int slow, int sig,
               std::vector<double>& dif, std::vector<double>& dea,
               std::vector<double>& hist, std::vector<double>& e12, std::vector<double>& e26);
bool json_valid(const std::string& s);
long long parse_dt(const std::string& s);
double r4(double v);
std::string strat_of_remark(const std::string& remark);
bool valid_inst(const std::string& s);
bool valid_bar(const std::string& s);
std::string get_inst(const Params& q);
std::string P(const Params& q, const char* k, const char* dflt = "");

// 微型 TTL 缓存
extern std::map<std::string, std::pair<long long, std::string>> g_fundCache;
extern std::map<std::string, std::pair<long long, std::string>> g_lsCache;
extern std::map<std::string, std::pair<long long, std::string>> g_tickCache;
extern std::map<std::string, std::pair<long long, std::string>> g_kfreshCache;  // K线按需刷新节流(inst|tf)
bool cache_get(std::map<std::string, std::pair<long long, std::string>>& m,
               const std::string& k, std::string& out);
void cache_put(std::map<std::string, std::pair<long long, std::string>>& m,
               const std::string& k, const std::string& v, int ttlMs);

// ---------------- OKX 组件 (hub_okx) ----------------
bool http_req(const char* method, const std::string& host, const std::string& path,
              const std::string& extraHeaders, const std::string& sendBody, std::string& out);
bool http_get_hdr(const std::string& host, const std::string& path,
                  const std::string& extraHeaders, std::string& out);
bool http_get(const std::string& host, const std::string& path, std::string& out);
std::string b64enc(const unsigned char* d, int n);
std::string hmac_sha256_b64(const std::string& key, const std::string& msg);
std::string okx_ts();
bool okx_cred(std::string& ak, std::string& sk, std::string& pp);
std::string okx_private_get(const std::string& pathWithQuery, std::string& body);
bool okx_private(const char* method, const std::string& path,
                 const std::string& body, std::string& out);

// ---------------- JSON 通用小工具 (hub_util, OKX 响应解析用) ----------------
std::string j_str(const std::string& obj, const std::string& key);
double j_num(const std::string& obj, const std::string& key);
std::vector<std::string> j_split_objects(const std::string& body);

// ---------------- sigcore 算法组件 (同源编译进 exe) ----------------
extern "C" {
int gold_signal(const double* rows, int n, const char* bar, double* ke_out, double* th_out,
                int* dist_out, char* info, int infoLen);
int pivots_calc(const double* rows, int n, const char* bar, int* idx, int* type,
                double* keA, double* gA, double* fA, int maxP);
int pivots_full(const double* rows, int n, const char* bar, int* idx, int* type, double* pA,
                double* keA, double* gA, double* fA, double* vA, double* mA, double* angA,
                double* a2A, int* barsA, double* thOut, int maxP);
// 常驻内存K线库(tradehub 引擎专用): 喂增量 → 数据滞留本进程内存 → 直算信号
int store_upsert(const char* key, const long long* ts, const double* bars, int n);
int store_gold(const char* key, const char* bar, double* ke_out, double* th_out,
               int* dist_out, char* info, int infoLen);
int store_pit(const char* key, const char* bar, double* score_out, char* info, int infoLen);
int store_count(const char* key);
int store_stats();
}

// ---------------- 接口实现 (api_eps) ----------------
std::string ep_kline(const Params& q);
std::string ep_live(const Params& q);
std::string ep_ticker(const Params& q);
std::string ep_trades(const Params& q);
std::string ep_stats(const Params& q);
std::string ep_livestats(const Params& q);
std::string ep_gridmon(const Params& q);
std::string ep_backcheck(const Params& q);
std::string ep_marks(const Params& q);
std::string ep_sigs(const Params& q);
std::string ep_sigscan(const Params& q);
std::string ep_boot(const Params& q);
std::string ep_symbols(const Params& q);
std::string ep_settings(const Params& q);
std::string ep_account(const Params& q);
std::string ep_health(const Params& q);
std::string ep_guard(const Params& q);
void sigscan_loop();

// ---------------- 面板渲染 (api_pages) ----------------
std::string render_panel(const Params& q);
std::string ep_frag(const Params& q);      // AJAX 片段接口: /frag?name=stats|grid|flow|guard|chart
extern const char* PANEL_CSS;
std::string hesc(std::string s);
std::string hnum(double v, int prec = 2);
std::string hx(const std::string& s);
std::string htime(long long tms);
std::string hdate(long long tms);
