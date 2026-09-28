/* ==================================================================
 * datahub_fetch.cpp — datahub 抓取组件 (拆分自 datahub.cpp, 逻辑零改动)
 *
 * 【文件职责】
 *   datahub.exe（计划任务 finally_fill1m 常驻）的全市场 K 线下载抓取层。
 *   本组件实现 datahub 专属的限频 HTTP 层：与 common 公共库的 http_get 不同，
 *   所有请求先经 rate_wait() 全局匀速排队（间隔 g_minMs，自适应 120~300ms），
 *   遇 OKX 429 / HTTP200+code 50011 限频时全局退避 6 秒并放慢 g_minMs。
 *
 * 【函数清单】
 *   rate_wait     : (static) 全局匀速限流器，逐请求排队，平滑无突发
 *   dh_http_get   : (static) 专属限频 HTTP GET（WinHTTP，3 次重试+429/50011 退避）
 *   dl_http_get   : ETH live 实时行情专用入口，复用同一限频队列
 *   okx_bar       : (static) OKX bar 参数规范化——小时必须大写 1H/4H（小写静默取不到数据）
 *   fetch_store   : 拉 OKX K 线入库，支持 after(更旧)/before(更新) 翻页
 *   fill_forward  : 增量追平历史缺口（单轮最多 1200 根，与 Klines.php 同口径）
 *   bar_sec       : 周期字符串 → 秒数
 *   prog_get/prog_set : (inst,tf)→最新 bar 时间的内存进度缓存（免多余 DB 查询）
 *   all_insts     : 全市场合约列表（从 DB kline_ 表名反推）
 *   pool_insts    : 交易池合约列表（symbol_pool 表）
 *   hot_insts     : 热合约列表（近 48h 有成交，A0 级，≤30 个）
 *   tail_fetch    : 事件驱动增量拉尾部 limit 根并更新进度缓存
 * ================================================================== */
#include "datahub.h"                           // 本组件的接口声明与公共组件 hub.h
#include <winhttp.h>                           // Windows HTTP 客户端 API (WinHttpOpen/Connect/...)
#include <cstdio>                              // snprintf / atoll 等 C 标准输入输出
#include <cstring>                             // C 字符串工具(隐含依赖)
#include <ctime>                               // time(nullptr) 取当前时间戳
#include <cmath>                               // floor: bar 时间对齐取整
#include <map>                                 // std::map: 进度缓存嵌套结构
#include <algorithm>                           // STL 算法(隐含依赖)

// ---------------- WinHTTP GET (datahub 专属: 匀速限流+429退避) ----------------
static std::atomic<long long> g_throttleUntil(0);   // 429限频退避(全局)
std::atomic<int> g_429count(0);                     // 本轮429次数(hub_loop自适应用)
// 全局匀速限流器: 所有HTTP统一排队, 每个请求间隔 g_minMs(默认160ms), 平滑无突发
std::atomic<int> g_minMs(160);                      // 请求最小间隔毫秒, hub_loop 自适应调节(120~300)
static std::mutex g_rlMtx;                          // 保护 g_nextSlot 的互斥锁(多线程同时取号)
static long long g_nextSlot = 0;                    // 下一个可发请求的时间槽(ms, GetTickCount64 时基)
static void rate_wait() {                           // 全局匀速限流器: 每个HTTP请求发送前必须先调用
    long long wait = 0;                             // 本次需要等待的毫秒数
    {                                               // --- 临界区: 领取时间槽, 保证请求间隔不小于 g_minMs ---
        std::lock_guard<std::mutex> lk(g_rlMtx);    // 加锁, 防止多线程领到同一时间槽
        long long now = GetTickCount64();           // 当前系统毫秒时钟
        if (g_nextSlot < now) g_nextSlot = now;     // 若槽已落后(空闲期), 拉回到当前时刻, 避免空等
        wait = g_nextSlot - now;                    // 计算需等待时长(0表示立即可发)
        g_nextSlot += g_minMs.load();               // 把时间槽向后推进一个最小间隔, 给下一个请求
    }                                               // --- 退出临界区(睡眠放在锁外, 不阻塞他人领号) ---
    if (wait > 0) Sleep((DWORD)wait);               // 等到属于自己的时间槽再放行
}
static bool dh_http_get(const std::string& path, std::string& out) { // 专属限频HTTP GET: path为OKX API路径, out收响应体
    for (int a = 0; a < 3; a++) {                   // 最多重试 3 次(限频/网络失败后循环再来)
        long long th = g_throttleUntil.load();      // 读取全局退避截止时刻(其他线程触发的429/50011)
        long long nowll = GetTickCount64();         // 当前毫秒时钟
        if (nowll < th) Sleep((DWORD)(th - nowll));     // 全局限频退避
        rate_wait();                                    // 全局匀速排队
        HINTERNET ses = WinHttpOpen(L"Mozilla/5.0 datahub/1.0", WINHTTP_ACCESS_TYPE_DEFAULT_PROXY, // 创建WinHTTP会话, UA标识datahub, 使用默认代理
                                    WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, 0);            // 不指定代理名/绕过列表, 同步模式
        if (!ses) { Sleep(2000); continue; }        // 会话创建失败(资源紧张等), 等2秒重试
        HINTERNET con = WinHttpConnect(ses, L"www.okx.com", INTERNET_DEFAULT_HTTPS_PORT, 0); // 连接 OKX 主站 443 端口
        bool got = false;                           // 本轮是否成功取到有效数据
        if (con) {                                  // 连接成功才继续发请求
            std::wstring wpath(path.begin(), path.end()); // 把窄字符路径逐字符转宽字符(URL为ASCII, 安全)
            HINTERNET req = WinHttpOpenRequest(con, L"GET", wpath.c_str(), nullptr,   // 构造 GET 请求
                                               WINHTTP_NO_REFERER, WINHTTP_DEFAULT_ACCEPT_TYPES, // 无Referer、默认Accept
                                               WINHTTP_FLAG_SECURE);                   // 走 HTTPS
            if (req) {                              // 请求句柄创建成功
                DWORD tmo = 15000;                  // 接收超时 15 秒(防止OKX卡死拖住工作线程)
                WinHttpSetOption(req, WINHTTP_OPTION_RECEIVE_TIMEOUT, &tmo, sizeof(tmo)); // 设置读超时选项
                if (WinHttpSendRequest(req, WINHTTP_NO_ADDITIONAL_HEADERS, 0,         // 发送请求(无附加头、无请求体)
                                       WINHTTP_NO_REQUEST_DATA, 0, 0, 0) && WinHttpReceiveResponse(req, nullptr)) { // 并接收响应
                    DWORD st = 0, sz = sizeof(st);  // st=HTTP状态码, sz=读取字节数
                    WinHttpQueryHeaders(req, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,  // 查询状态码并以数字形式返回
                                        WINHTTP_HEADER_NAME_BY_INDEX, &st, &sz, WINHTTP_NO_HEADER_INDEX);
                    if (st == 200) {                // HTTP 200: 还需检查业务码(OKX限频可能包在200里)
                        out.clear();                // 清空输出缓冲, 准备累积响应体
                        char buf[16384]; DWORD rd = 0; // 16KB读缓冲 + 实际读到字节数
                        while (WinHttpReadData(req, buf, sizeof(buf), &rd) && rd) { out.append(buf, rd); rd = 0; } // 循环读满整个响应体
                        if (out.find("\"code\":\"50011\"") != std::string::npos) { // 响应含50011 = OKX业务层限频
                            // OKX限频(HTTP 200 + 50011): 全局退避6秒
                            g_throttleUntil = GetTickCount64() + 6000; // 设置全局退避截止: 6秒内所有线程先睡
                            g_429count.fetch_add(1);  // 限频计数+1, 供 hub_loop 自适应放慢 g_minMs
                            got = false;              // 标记本轮失败, 走重试
                        } else {                      // 无业务错误码
                            got = true;               // 成功取到有效数据
                        }
                    } else if (st == 429) {         // HTTP 429: OKX 网关层限频
                        // OKX限频: 全局退避6秒, 不再立即重试
                        g_throttleUntil = GetTickCount64() + 6000; // 同样全局退避6秒
                        g_429count.fetch_add(1);    // 限频计数+1(自适应依据)
                        logline("OKX 429 限频 → 全局退避6s"); // 写日志留痕
                        break;                      // 直接跳出重试循环(等待全局退避后由下一轮任务再试)
                    }
                }
                WinHttpCloseHandle(req);            // 释放请求句柄
            }
            WinHttpCloseHandle(con);                // 释放连接句柄
        }
        WinHttpCloseHandle(ses);                    // 释放会话句柄
        if (got) return true;                       // 成功: 返回 true, out 里就是响应体
        Sleep(2000);                                // 失败: 等2秒再进入下一次重试
    }
    return false;                                   // 3次重试均失败: 返回 false, 由调用方决定重试/放弃
}

// ETH live 专用入口: 走同一限频队列
bool dl_http_get(const std::string& path, std::string& out) { // 对外导出: ETH实时行情等也必须遵守全局限流
    return dh_http_get(path, out);                  // 直接转发到内部实现, 保证单一限流队列
}

// ---------------- OKX bar 参数规范: 小时必须大写 H ----------------
static std::string okx_bar(const std::string& tf) { // 把内部周期名转成OKX bar参数
    std::string b = tf;                             // 拷贝一份待转换
    for (auto& c : b) if (c == 'h') c = 'H';        // 小写h→大写H: OKX小时周期必须1H/4H, 小写会静默取不到数据
    return b;                                       // 返回规范化后的周期串
}

// fetchStore: 拉OKX K线入库(可带 after/before 参数), 返回写入数
int fetch_store(const std::string& inst, const std::string& tf, int limit,   // inst合约名, tf周期, limit单页根数
                bool useBefore, long long ref) {   // ref>0时翻页: before=取比ref更新, after=取比ref更旧
    char path[512];                                // 拼接URL路径的缓冲区
    if (ref > 0)                                   // 带翻页游标的请求
        snprintf(path, sizeof(path),               // 格式化完整API路径
            "/api/v5/market/candles?instId=%s&bar=%s&limit=%d&%s=%lld",
            inst.c_str(), okx_bar(tf).c_str(), limit, useBefore ? "before" : "after", ref); // bar小时必须大写; after=更旧 before=更新
    else                                           // 不带游标: 只拉最新 limit 根
        snprintf(path, sizeof(path),               // 拼接不带翻页参数的路径
            "/api/v5/market/candles?instId=%s&bar=%s&limit=%d",
            inst.c_str(), okx_bar(tf).c_str(), limit); // 常规最新数据请求
    std::string body;                              // 接收HTTP响应体
    if (!dh_http_get(path, body)) return -1;       // 限频/网络失败返回-1, 调用方可区分
    return store(inst, tf, okx_parse_candles(body)); // 解析K线JSON并写库(common组件), 返回实际写入根数
}

// fillForward (与 Klines.php 同口径): 追平返回0; 返回写入根数
long long fill_forward(const std::string& inst, const std::string& tf) { // 增量追平: 从库里最新bar一直补到当前
    bool ok;                                       // DB查询成功标志
    std::string mxs = db_scalar("SELECT MAX(candle_time) FROM " + ktable(inst, tf), ok); // 查该表最新K线时间(毫秒)
    if (!ok) { ensure_table(inst, tf); return fetch_store(inst, tf, 300, false, 0); }    // 表不存在: 先建表, 再整页拉最新300根
    long long max = mxs.empty() ? 0 : atoll(mxs.c_str());  // 最新bar毫秒时间(空表记0)
    const char* IVS[] = {"1m","3m","5m","15m","30m","1h","4h","1H","6H","12H","1D"};      // 周期名对照表(含大小写两种形式)
    const int   IVS_SEC[] = {60,180,300,900,1800,3600,14400,3600,21600,43200,86400};      // 各周期对应秒数
    int barSec = 300;                              // 默认按5分钟(300秒)处理
    std::string tfL;                               // 周期名的全小写形式
    for (char c : tf) tfL += (c >= 'A' && c <= 'Z') ? c + 32 : c;  // 逐字符转小写
    for (int i = 0; i < 11; i++)                   // 在对照表里查找当前周期
        if (tfL == IVS[i] || (i >= 7 && tf == IVS[i])) { barSec = IVS_SEC[i]; break; } // i>=7的大写形式需精确匹配
    long long nowMs = (long long)(floor(time(nullptr) * 1000.0 / (barSec * 1000)) * barSec * 1000); // 当前bar周期起点(向下取整对齐)
    if (max >= nowMs - 2LL * barSec * 1000) return 0;   // 已追平
    long long total = 0, ref = max;                // total=累计写入根数; ref=翻页游标(从最新bar开始)
    while (ref < nowMs && total < 1200) {          // 循环翻页直到追平或本轮达1200根上限(防单次占死限流配额)
        int w = fetch_store(inst, tf, 300, true, ref); // 用 after=ref 向更旧方向翻页, 每页300根
        if (w < 0) { logline("追平取数失败(限频/网络): " + inst + " " + tf); break; } // 失败: 记日志并终止本轮
        if (w == 0) { logline("追平返回0行(OKX无数据/已到顶): " + inst + " " + tf + " ref=" + std::to_string(ref)); break; } // 0行=OKX没更旧数据了, 到头
        total += w;                                // 累加本轮写入根数
        bool ok2;                                  // 查询成功标志
        std::string nm = db_scalar("SELECT MAX(candle_time) FROM " + ktable(inst, tf), ok2); // 重查最新bar时间作为新游标
        long long newRef = ok2 ? atoll(nm.c_str()) : 0; // 解析新游标(查失败记0)
        if (newRef <= ref) break;                  // 游标没前进: 防死循环, 退出
        ref = newRef;                              // 推进游标继续向"更新"方向补
        Sleep(40); // 限频由 rate_wait() 统一节流, 这里只做轻微礼让
    }
    if (total > 0) logline("追平 " + inst + " " + tf + " +" + std::to_string(total) + " 根"); // 有写入才记日志
    return total;                                  // 返回本轮追平写入的总根数
}

// ---------------- 周期秒数 ----------------
int bar_sec(const std::string& tf) {               // 周期字符串→秒数(用于bar时间对齐与新鲜度判断)
    const char* IVS[] = {"1m","3m","5m","15m","30m","1h","4h","6h","12h","1d"};  // 支持的周期名(全小写)
    const int   SEC[] = {60,180,300,900,1800,3600,14400,21600,43200,86400};      // 对应秒数
    std::string tfL;                               // 全小写形式
    for (char c : tf) tfL += (c >= 'A' && c <= 'Z') ? c + 32 : c;  // 转小写后匹配(容忍1H/1h写法)
    for (int i = 0; i < 10; i++) if (tfL == IVS[i]) return SEC[i]; // 命中即返回对应秒数
    return 300;                                    // 未识别默认5分钟
}

// ---------------- 进度缓存: (inst,tf)->最新bar时间 (内存化, 零多余DB查询) ----------------
struct TfState { long long mx = 0; bool loaded = false; };      // 单个(合约,周期)的状态: 最新bar时间+是否已从DB加载
static std::map<std::string, std::map<std::string, TfState>> g_prog;  // 两级map: 合约→周期→状态
static std::mutex g_progMtx;                       // 保护 g_prog 的互斥锁(4个工作线程并发读写)

long long prog_get(const std::string& inst, const std::string& tf) { // 读进度: 返回该(合约,周期)最新bar毫秒时间
    std::lock_guard<std::mutex> lk(g_progMtx);     // 加锁(首次加载会写map)
    TfState& st = g_prog[inst][tf];                // 取出(或创建)对应状态条目
    if (!st.loaded) {                              // 尚未从DB加载过: 查一次DB并缓存
        bool ok;                                   // 查询成功标志
        std::string v = db_scalar("SELECT MAX(candle_time) FROM " + ktable(inst, tf), ok); // 查最新bar时间
        st.mx = (ok && !v.empty()) ? atoll(v.c_str()) : 0; // 解析, 失败/空表记0
        st.loaded = true;                          // 标记已加载, 之后不再查DB
    }
    return st.mx;                                  // 返回缓存值
}
void prog_set(const std::string& inst, const std::string& tf, long long v) { // 写进度: 拉到新数据后更新缓存
    std::lock_guard<std::mutex> lk(g_progMtx);     // 加锁
    TfState& st = g_prog[inst][tf];                // 取出(或创建)状态条目
    if (v > st.mx) st.mx = v;                      // 只增不减(时间单调前进)
    st.loaded = true;                              // 标记有效
}

// ---------------- 全市场合约列表 (从DB表名反推, 与 SymbolPool::allInsts 同口径) ----------------
std::vector<std::string> all_insts() {             // 枚举全市场已建表的USDT永续合约
    std::vector<std::string> out;                  // 结果集
    RowSet rs = db_q("SELECT DISTINCT table_name FROM information_schema.tables"   // 查trading库下所有 kline_ 前缀表
                     " WHERE table_schema='trading' AND table_name LIKE 'kline\\_%'"); // 表名形如 kline_BTC-USDT-SWAP_5m
    std::map<std::string, bool> seen;              // 已收录合约去重表
    const char* TFS[] = {"1m","3m","5m","15m","30m","1h","4h"};  // K线表可能带的周期后缀
    for (auto& r : rs.rows) {                      // 逐表解析
        std::string t = r[0];                      // 完整表名
        if (t.size() <= 6) continue;               // 太短(不足"kline_"+内容), 跳过
        t = t.substr(6); // 去 kline_
        for (auto* tf : TFS) {                     // 尝试剥离各周期后缀
            std::string sfx = std::string("_") + tf;   // 如 "_5m"
            if (t.size() > sfx.size() && t.compare(t.size() - sfx.size(), sfx.size(), sfx) == 0) { // 表名以该后缀结尾
                std::string inst = t.substr(0, t.size() - sfx.size()); // 去掉后缀得合约名(建表时'-'换成'_'且小写)
                for (auto& c : inst) if (c == '_') c = '-';  // '_'还原为'-': BTC_USDT_SWAP→BTC-USDT-SWAP
                for (auto& c : inst) if (c >= 'a' && c <= 'z') c -= 32; // 小写转大写
                if (inst.size() > 10 && inst.compare(inst.size() - 10, 10, "-USDT-SWAP") == 0 && !seen[inst]) { // 只收USDT永续, 且去重
                    seen[inst] = true;             // 标记已收录
                    out.push_back(inst);           // 加入结果
                }
                break;                             // 匹配到一个周期后缀即止, 防止重复处理
            }
        }
    }
    return out;                                    // 返回全市场合约列表(约477个)
}

// ---------------- 交易池 (symbol_pool 表) ----------------
std::vector<std::string> pool_insts() {            // 读取交易池(A1/B2 分级队列的数据源)
    std::vector<std::string> out;                  // 结果集(约251个)
    RowSet rs = db_q("SELECT inst_id FROM symbol_pool"); // 查池表全部合约ID
    for (auto& r : rs.rows) if (!r[0].empty()) out.push_back(r[0]); // 逐行收录非空合约名
    return out;                                    // 返回交易池合约列表
}

// ---------------- 热合约(近48h有成交): 唯一需要1m实时的一小撮 ----------------
std::vector<std::string> hot_insts() {             // A0级热合约: 用户正在看图/正在交易的
    std::vector<std::string> out;                  // 结果集(≤30个)
    RowSet rs = db_q("SELECT inst_id, MAX(id) mid FROM trade_flow"   // 从成交流水表统计
                     " WHERE action IN ('buy','add','close') AND trade_time >= NOW() - INTERVAL 48 HOUR" // 近48小时的买入/加仓/平仓动作
                     " GROUP BY inst_id ORDER BY mid DESC LIMIT 30");  // 按最近成交排序, 取前30
    for (auto& r : rs.rows) if (!r[0].empty()) out.push_back(r[0]); // 逐行收录非空合约名
    return out;                                    // 返回热合约列表
}

// ---------------- 增量拉尾部(事件驱动): 拉最新limit根入库+更新进度, 返回写入数 ----------------
int tail_fetch(const std::string& inst, const std::string& tf, int limit) { // 新鲜任务: 只补最新一段
    int w = fetch_store(inst, tf, limit, false, 0);   // 无游标拉最新limit根(写库), w=写入数/-1失败
    if (w >= 0) {                                  // 请求成功(含0根)才更新进度
        bool ok;                                   // 查询成功标志
        std::string v = db_scalar("SELECT MAX(candle_time) FROM " + ktable(inst, tf), ok); // 重查最新bar时间
        prog_set(inst, tf, ok ? atoll(v.c_str()) : 0); // 同步进内存进度缓存
    }
    return w;                                      // 返回写入根数(负数=失败)
}
