/* ==================================================================
 * hub.h — 公共组件头文件 (apihub / tradehub 等共用)
 *
 * 文件职责：
 *   本文件是整个真钱交易系统公共组件库的唯一公共声明头，被编译进 libhub.a，
 *   所有组件 exe（apihub / tradehub / datahub 等）都通过 include 本文件获得
 *   统一的常量、数据结构与函数签名，保证各组件间接口一致。
 *
 * 内容概览（按分区）：
 *   - RowSet / Bar / Params   基础数据结构
 *   - 日志组件                logline / log_setfile
 *   - DB 组件                 db_connect_locked / db_q / db_scalar / db_ex
 *   - 工具组件                数值转换、表名生成、K线解析读写、MACD、JSON 校验等
 *   - TTL 缓存                fund/ls/tick/kfresh 四类缓存 + cache_get/cache_put
 *   - OKX 组件                http_req / 签名(hmac) / okx_private(_get)
 *   - JSON 小工具             j_str / j_num / j_split_objects
 *   - sigcore 算法组件        黄金信号、摆动点计算、常驻内存K线库(extern "C")
 *   - 接口实现                api_eps 中的各 ep_* 接口入口
 *   - 面板渲染                api_pages 中的 render_panel 及 HTML 工具函数
 *
 * 数据流向：
 *   HTTP 请求 → api_eps(ep_*) → hub_okx 拉 OKX 行情/账户 → hub_util 解析 JSON
 *   → store 写入 MySQL(hub_db) → read_recent/macd_full 读取计算 → 渲染面板返回。
 *
 * 注意：DB K线表列名为 o/h/l/c/vol（不是 open/close），见 ensure_table/read_recent。
 *
 * 2026-09-28 组件化拆分(纯物理拆分, 逻辑零改动):
 *   common/hub_db.cpp   MySQL 组件
 *   common/hub_okx.cpp  WinHTTP + OKX 签名组件
 *   common/hub_util.cpp 日志/JSON/缓存/K线读写组件
 *   api/api_eps.cpp     接口实现
 *   api/api_pages.cpp   零JS面板渲染
 *   api/apihub_main.cpp HTTP服务器薄壳
 * ================================================================== */
#pragma once                                  // 头文件防重复包含(MSVC/GCC 通用)
#include <winsock2.h>                         // Windows Socket(必须先于 windows.h 防宏冲突)
#include <windows.h>                          // Windows 基础 API(Sleep/CreateFile 等)
#include <string>                             // std::string
#include <vector>                             // std::vector
#include <map>                                // std::map(TTL 缓存/Params 用)
#include <mutex>                              // std::mutex(全局连接/日志锁)
#include <functional>                         // std::function(json_valid 递归校验用)
#include <mysql.h>                            // MySQL C API(MYSQL/MYSQL_RES 类型)

struct RowSet { std::vector<std::vector<std::string>> rows; bool ok = false; }; // SQL 查询结果集: 行×列的字符串矩阵 + 执行是否成功标志
struct Bar { long long tms; double o, h, l, c, v; };  // 单根K线: 毫秒时间戳 + 开高低收/成交量
typedef std::map<std::string, std::string> Params;    // HTTP 查询参数表(key=value, 由 URL 解析而来)

// ---------------- 日志组件 (hub_util) ----------------
void logline(const std::string& s);           // 追加一行带时间戳的日志到日志文件(线程安全, 超1MB自动截断)
void log_setfile(const char* path);   // 各组件exe启动时设置自己的日志路径(默认 apihub.log)

// ---------------- DB 组件 (hub_db) ----------------
extern MYSQL* g_my;                           // 全局唯一 MySQL 连接句柄(所有线程共用, 由互斥锁保护)
bool db_connect_locked();                     // (已持锁状态下)建立/重建 MySQL 连接, 内部调用方专用
RowSet db_q(const std::string& sql);          // 执行 SELECT, 返回结果集; 失败时 ok=false 并写日志
std::string db_scalar(const std::string& sql, bool& ok); // 执行查询只取第一行第一列的单值(如 COUNT/MAX)
bool db_ex(const std::string& sql);           // 执行写语句(INSERT/UPDATE/CREATE/事务控制), 返回是否成功

// ---------------- 工具组件 (hub_util) ----------------
bool numok(const std::string& s);             // 校验字符串是否为合法数字(OKX 返回的价格/数量都是字符串)
std::string jnum(double v);                   // double 转 JSON 数字字符串(NaN/溢出回退为 "0")
std::string jesc(const std::string& s);       // 转义字符串使其可安全嵌入 JSON(引号/换行/控制字符)
std::string sqlesc(const std::string& s);     // 转义字符串使其可安全嵌入 SQL(防注入: 反斜杠/引号)
long long atoll_s(const std::string& s);      // 安全字符串转 long long(空串返回 0 而非未定义行为)
double atof_s(const std::string& s);          // 安全字符串转 double(空串返回 0.0)
std::string ktable(const std::string& inst, const std::string& tf); // 由 币对+周期 生成合法 MySQL 表名(如 kline_eth_usdt_swap_1h)
std::vector<std::vector<std::string>> okx_parse_candles(const std::string& body); // 解析 OKX /candles 响应为 K线行集合
std::string json_str(const std::string& body, const std::string& key);  // 从 JSON 顶层提取指定 key 的字符串值
void ensure_table(const std::string& inst, const std::string& tf);      // 确保该币对+周期的K线表存在(不存在则建表)
int store(const std::string& inst, const std::string& tf,
          const std::vector<std::vector<std::string>>& bars);             // 批量 REPLACE 入库K线(分批事务, 返回成功写入行数)
std::vector<Bar> read_recent(const std::string& inst, const std::string& bar, int n); // 按时间正序读最近 n 根已确认K线
void macd_full(const std::vector<double>& cl, int fast, int slow, int sig,
               std::vector<double>& dif, std::vector<double>& dea,
               std::vector<double>& hist, std::vector<double>& e12, std::vector<double>& e26); // 计算 MACD 三线并额外输出两条 EMA 原始值
bool json_valid(const std::string& s);        // 手写递归下降校验 JSON 是否合法(不依赖第三方库)
long long parse_dt(const std::string& s);     // 解析 "YYYY-MM-DD HH:MM:SS" 为 Unix 秒时间戳
double r4(double v);                          // 四舍五入保留 4 位小数(信号阈值展示用)
std::string strat_of_remark(const std::string& remark); // 从订单备注 "hybrid open xxx" 提取策略名
bool valid_inst(const std::string& s);        // 校验合约 ID 格式(大写字母/数字/连字符, 防 SQL 注入)
bool valid_bar(const std::string& s);         // 校验 K线周期格式(如 1m/5m/1H/4H)
std::string get_inst(const Params& q);        // 从查询参数取合约 ID, 非法时回退默认 ETH-USDT-SWAP
std::string P(const Params& q, const char* k, const char* dflt = ""); // 从查询参数取值, 缺省时返回默认值

// 微型 TTL 缓存
extern std::map<std::string, std::pair<long long, std::string>> g_fundCache;   // 资金费率缓存(key=inst, value=过期tick+内容)
extern std::map<std::string, std::pair<long long, std::string>> g_lsCache;     // 持仓/杠杆类缓存
extern std::map<std::string, std::pair<long long, std::string>> g_tickCache;   // 行情 ticker 缓存(减轻 OKX 公共接口压力)
extern std::map<std::string, std::pair<long long, std::string>> g_kfreshCache; // K线按需刷新节流(inst|tf)
bool cache_get(std::map<std::string, std::pair<long long, std::string>>& m,
               const std::string& k, std::string& out);                        // 读缓存: 未过期则取出内容返回 true
void cache_put(std::map<std::string, std::pair<long long, std::string>>& m,
               const std::string& k, const std::string& v, int ttlMs);         // 写缓存: 记录当前 tick+ttl 为过期时刻

// ---------------- OKX 组件 (hub_okx) ----------------
bool http_req(const char* method, const std::string& host, const std::string& path,
              const std::string& extraHeaders, const std::string& sendBody, std::string& out); // WinHTTP 通用 GET/POST(3次重试, 200 才算成功)
bool http_get_hdr(const std::string& host, const std::string& path,
                  const std::string& extraHeaders, std::string& out);          // 带自定义头的 GET(OKX 签名头走这里)
bool http_get(const std::string& host, const std::string& path, std::string& out); // 无附加头的裸 GET(公共行情接口)
std::string b64enc(const unsigned char* d, int n);              // 原始字节转 base64 字符串(签名编码用)
std::string hmac_sha256_b64(const std::string& key, const std::string& msg);  // HMAC-SHA256 签名并 base64(OKX 鉴权核心)
std::string okx_ts();                                           // 生成 OKX 要求的 ISO8601 UTC 时间戳(毫秒精度)
bool okx_cred(std::string& ak, std::string& sk, std::string& pp); // 从数据库 okx_cred 表 id=1 读 API 凭证
std::string okx_private_get(const std::string& pathWithQuery, std::string& body); // OKX 私有 GET(带签名), 返回空串表示成功
bool okx_private(const char* method, const std::string& path,
                 const std::string& body, std::string& out);    // OKX 私有 GET/POST 通用入口(下单/撤单/查账户走这里)

// ---------------- JSON 通用小工具 (hub_util, OKX 响应解析用) ----------------
std::string j_str(const std::string& obj, const std::string& key);  // 提取对象中 key 对应的字符串值(轻量手写解析)
double j_num(const std::string& obj, const std::string& key);       // 提取 key 对应的数值(兼容字符串形式数字)
std::vector<std::string> j_split_objects(const std::string& body);  // 把 "data":[{...},{...}] 拆成单个对象字符串数组

// ---------------- sigcore 算法组件 (同源编译进 exe) ----------------
extern "C" {
int gold_signal(const double* rows, int n, const char* bar, double* ke_out, double* th_out,
                int* dist_out, char* info, int infoLen);            // 黄金信号主算法: 输入K线数组, 输出 ke/th 阈值与信号描述
int pivots_calc(const double* rows, int n, const char* bar, int* idx, int* type,
                double* keA, double* gA, double* fA, int maxP);     // 计算摆动点(枢轴点)基础数组: 索引/类型/三组强度值
int pivots_full(const double* rows, int n, const char* bar, int* idx, int* type, double* pA,
                double* keA, double* gA, double* fA, double* vA, double* mA, double* angA,
                double* a2A, int* barsA, double* thOut, int maxP);  // 摆动点完整版: 额外输出角度/斜率/距离等多维属性
// 常驻内存K线库(tradehub 引擎专用): 喂增量 → 数据滞留本进程内存 → 直算信号
int store_upsert(const char* key, const long long* ts, const double* bars, int n); // 向常驻内存K线库插入/更新K线(免 DB 往返)
int store_gold(const char* key, const char* bar, double* ke_out, double* th_out,
               int* dist_out, char* info, int infoLen);             // 直接对内存K线库跑黄金信号(毫秒级响应)
int store_pit(const char* key, const char* bar, double* score_out, char* info, int infoLen); // 对内存K线库跑 PIT(点位)评分
int store_count(const char* key);                 // 查询某 key 在内存K线库中的K线数量
int store_stats();                                // 输出内存K线库整体统计(各组数量/内存占用)
}

// ---------------- 接口实现 (api_eps) ----------------
std::string ep_kline(const Params& q);            // 接口 /kline: 返回K线数据(优先DB, 不足时回源OKX刷新)
std::string ep_live(const Params& q);             // 接口 /live: 实时行情快照
std::string ep_ticker(const Params& q);           // 接口 /ticker: 最新成交价/买卖盘(走 TTL 缓存)
std::string ep_trades(const Params& q);           // 接口 /trades: 最近成交流水
std::string ep_stats(const Params& q);            // 接口 /stats: 系统统计信息
std::string ep_livestats(const Params& q);        // 接口 /livestats: 实时监控统计
std::string ep_gridmon(const Params& q);          // 接口 /gridmon: 网格策略监控数据
std::string ep_backcheck(const Params& q);        // 接口 /backcheck: 信号回测核查
std::string ep_marks(const Params& q);            // 接口 /marks: 图表信号标记
std::string ep_boot(const Params& q);             // 接口 /boot: 面板启动引导数据
std::string ep_symbols(const Params& q);          // 接口 /symbols: 可交易合约列表
std::string ep_settings(const Params& q);         // 接口 /settings: 读写系统配置
std::string ep_account(const Params& q);          // 接口 /account: 账户余额/持仓(走 OKX 私有接口)
std::string ep_health(const Params& q);           // 接口 /health: 健康检查(DB/网络连通性)
std::string ep_guard(const Params& q);            // 接口 /guard: 风控守护状态
std::string ep_btlist(const Params& q);           // 接口 /btlist: AI模拟回测报告列表(最近n场简介)
std::string ep_btreport(const Params& q);         // 接口 /btreport: 单场完整报告(?id=场次ID)

// ---------------- 面板渲染 (api_pages) ----------------
std::string render_panel(const Params& q);        // 渲染零JS主面板整页 HTML(服务端拼接, 浏览器直显)
std::string ep_frag(const Params& q);      // AJAX 片段接口: /frag?name=stats|grid|flow|guard|chart
extern const char* PANEL_CSS;                     // 面板内联 CSS 常量(样式与渲染逻辑分离)
std::string hesc(std::string s);                  // HTML 转义(防 XSS: & < > " 等)
std::string hnum(double v, int prec = 2);         // 数值格式化为 HTML 展示字符串(默认 2 位小数)
std::string hx(const std::string& s);             // 字符串转十六进制(调试图表数据用)
std::string htime(long long tms);                 // 毫秒时间戳转 HH:MM:SS 展示
std::string hdate(long long tms);                 // 毫秒时间戳转 MM-DD 展示
