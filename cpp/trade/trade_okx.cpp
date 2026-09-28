/* ==================================================================
 * trade_okx.cpp — OKX V5 客户端组件 (逐字对齐 inc/lib/OkxClient.php)
 *
 * 【文件职责】
 *   封装 OKX V5 REST 接口的薄客户端层：
 *   公共: instruments(每张面值 ctVal) / tickers(最新价) / candles(K线,
 *         dir=after 取更旧 / before 取更新, 带翻页参考时间)
 *   私有: positions(持仓) / set-leverage(自适应逐级降档, 防保证金翻倍) /
 *         order(市价买, 全仓cross做多) / close-position(平仓) /
 *         orders-algo-pending + cancel-algos(撤条件单, 不挂止损止盈) /
 *         fills-history(成交明细→盈亏统计)
 *   签名复用 common/hub_okx.cpp 的 okx_private(BCrypt HMAC-SHA256)
 *
 * 【函数清单】
 *   now_ms()                  当前 Unix 毫秒(system_clock)
 *   ms_to_dt()                毫秒 → "Y-m-d H:i:s"(本地时区)
 *   local_dt_to_unix()        本地时间字符串 → Unix 秒
 *   today_str()               今日 "Y-m-d"
 *   okx_public_get()          公共 GET 透传
 *   okx_ctval()               每张合约面值(进程内缓存)
 *   okx_last_price()          最新成交价(公共 ticker)
 *   okx_candles()             K线拉取(after=更旧/before=更新)
 *   okx_positions()           全部 SWAP 持仓对象数组
 *   okx_position()            单合约持仓对象
 *   okx_set_leverage_adaptive() 自适应设杠杆: 从 want 逐级降档直到成功
 *   okx_market_buy()          市价买入(全仓cross/做多)
 *   okx_close_position()      市价平多仓
 *   okx_cancel_all_algos()    撤销全部未触发条件单
 *   okx_fills_pnl()           近 minutes 分钟 sell 成交 → [pnl合计, 出场均价]
 *
 * 【数据流】
 *   HTTP(www.okx.com) → okx_public_get / okx_private → JSON 字符串
 *   → j_num/j_str/j_split_objects 解析 → 供 trade_engine/trade_backfill 使用
 *   实际杠杆: okx_set_leverage_adaptive 返回值 → size_for 计算张数
 *   (不可用 LOCK_LEVER 常量, 否则降档合约保证金会翻倍)
 * ================================================================== */
// ==================================================================
// trade_okx.cpp — OKX V5 客户端组件 (逐字对齐 inc/lib/OkxClient.php)
//   公共: instruments(tickers/ticker/candles) / ctVal
//   私有: positions / set-leverage(自适应降档) / order(市价买) /
//         close-position / orders-algo-pending+cancel-algos / fills-history
// 签名复用 common/hub_okx.cpp 的 okx_private(BCrypt HMAC-SHA256)
// ==================================================================
#include "tradehub.h"                          // 本组件公共声明
#include <cstdio>                              // sscanf/snprintf
#include <cstring>                             // memset
#include <cmath>                               // fabs
#include <chrono>                              // system_clock(now_ms)

// ---------------- 时间工具 ----------------
long long now_ms() {                           // 当前 Unix 毫秒
    using namespace std::chrono;               // 简化 chrono 类型名
    return (long long)duration_cast<milliseconds>(system_clock::now().time_since_epoch()).count();   // 系统时钟→毫秒
}
std::string ms_to_dt(long long ms) {           // 毫秒 → 本地时间字符串
    time_t t = (time_t)(ms / 1000);            // 转 Unix 秒
    struct tm lt; localtime_s(&lt, &t);        // 转本地时间
    char b[32]; strftime(b, sizeof(b), "%Y-%m-%d %H:%M:%S", &lt);   // 格式化
    return b;                                  // 返回 "Y-m-d H:i:s"
}
long long local_dt_to_unix(const char* s) {    // 本地时间串 → Unix 秒
    int Y = 0, Mo = 0, D = 0, h = 0, mi = 0, se = 0;   // 年月日时分秒
    if (sscanf(s, "%d-%d-%d %d:%d:%d", &Y, &Mo, &D, &h, &mi, &se) != 6) return 0;   // 解析失败返回0
    struct tm tmv; memset(&tmv, 0, sizeof(tmv));   // tm 结构清零
    tmv.tm_year = Y - 1900; tmv.tm_mon = Mo - 1; tmv.tm_mday = D;   // 年(从1900)/月(从0)/日
    tmv.tm_hour = h; tmv.tm_min = mi; tmv.tm_sec = se;   // 时分秒
    tmv.tm_isdst = -1;                         // 不用夏令时
    return (long long)mktime(&tmv);            // 本地时区 → Unix 秒
}
std::string today_str() {                      // 今日日期字符串
    time_t t = time(nullptr); struct tm lt; localtime_s(&lt, &t);   // 当前本地时间
    char b[16]; strftime(b, sizeof(b), "%Y-%m-%d", &lt);   // 格式化 "Y-m-d"
    return b;
}

// ---------------- 公共接口 ----------------
bool okx_public_get(const std::string& path, std::string& body) {   // 公共 GET
    return http_get("www.okx.com", path, body);    // 透传到公共 HTTP 组件
}

double okx_ctval(const std::string& inst) {    // 每张合约面值(ctVal)
    static std::map<std::string, double> cache;    // 进程内缓存(面值不变)
    auto it = cache.find(inst);                // 查缓存
    if (it != cache.end()) return it->second;  // 命中直接返回
    std::string body;                          // 响应体
    double v = 0.0;                            // 面值
    if (okx_public_get("/api/v5/public/instruments?instType=SWAP&instId=" + inst, body) &&   // 查合约详情
        body.find("\"code\":\"0\"") != std::string::npos)   // 返回码为0
        v = j_num(body, "ctVal");              // 提取面值
    cache[inst] = v;                           // 写缓存(失败也缓存0防反复请求)
    return v;                                  // 返回面值
}

double okx_last_price(const std::string& inst) {   // 最新成交价
    std::string body;                          // 响应体
    if (!okx_public_get("/api/v5/market/ticker?instId=" + inst, body)) return 0.0;   // 请求失败
    if (body.find("\"code\":\"0\"") == std::string::npos) return 0.0;   // 返回码异常
    return j_num(body, "last");                // 提取最新价
}

// K线: dir=after 取比 refMs 更旧 / before 取比 refMs 更新 (与 PHP 同口径)
bool okx_candles(const std::string& inst, const std::string& bar, int limit,   // 合约/周期/条数
                 long long refMs, bool useRef, bool history, const char* dir,   // 参考时间/用否/历史接口/方向
                 std::vector<std::vector<std::string>>& rows) {   // 出参: K线行数组
    if (limit > 300) limit = 300;              // 上限 300 根
    if (limit < 1) limit = 1;                  // 下限 1 根
    std::string ep = history ? "/api/v5/market/history-candles" : "/api/v5/market/candles";   // 历史/近端接口二选一
    std::string url = ep + "?instId=" + inst + "&bar=" + bar + "&limit=" + std::to_string(limit);   // 拼 URL
    if (useRef) url += std::string("&") + dir + "=" + std::to_string(refMs);   // 带翻页参考时间
    std::string body;                          // 响应体
    if (!okx_public_get(url, body)) return false;   // 请求失败
    rows = okx_parse_candles(body);            // 解析成行数组
    return !rows.empty();                      // 非空才算成功
}

// ---------------- 私有接口 ----------------
bool okx_positions(std::vector<std::string>& objs) {   // 全部持仓对象
    std::string body;                          // 响应体
    if (!okx_private("GET", "/api/v5/account/positions?instType=SWAP", "", body)) return false;   // 私有请求失败
    if (body.find("\"code\":\"0\"") == std::string::npos) return false;   // 返回码异常
    objs = j_split_objects(body);              // 拆出全部持仓对象
    return true;                               // 成功
}

bool okx_position(const std::string& inst, std::string& obj) {   // 单合约持仓
    std::string body;                          // 响应体
    if (!okx_private("GET", "/api/v5/account/positions?instId=" + inst, "", body)) return false;   // 私有请求失败
    if (body.find("\"code\":\"0\"") == std::string::npos) return false;   // 返回码异常
    auto objs = j_split_objects(body);         // 拆对象(至多1个)
    if (objs.empty()) return false;            // 无持仓
    obj = objs[0];                             // 取第一个
    return true;                               // 成功
}

// 自适应设杠杆: 从 want 逐级降档直到成功 (LUNA 等上限低于 20x 的合约)
int okx_set_leverage_adaptive(const std::string& inst, int want) {   // 合约/期望杠杆
    int lv = want;                             // 从期望值开始试
    while (lv >= 1) {                          // 逐级降档直到1x
        std::string b = "{\"instId\":\"" + inst + "\",\"lever\":\"" + std::to_string(lv) +   // 设杠杆请求体
                        "\",\"mgnMode\":\"cross\"}";   // 全仓(cross)模式
        std::string resp;                      // 响应体
        if (okx_private("POST", "/api/v5/account/set-leverage", b, resp) &&   // 尝试设杠杆
            resp.find("\"code\":\"0\"") != std::string::npos)   // 成功
            return lv;                         // 返回实际生效杠杆
        std::string msg = j_str(resp, "sMsg"); // 提取错误消息
        if (msg.empty()) msg = j_str(resp, "msg");   // 兼容字段
        bool leverErr = (msg.find("51169") != std::string::npos) ||   // 51169=杠杆超上限错误码
                        (msg.find("leverage") != std::string::npos) ||   // 英文杠杆错误
                        (msg.find("杠杆") != std::string::npos);   // 中文杠杆错误
        if (leverErr) lv = (lv >= 10) ? lv - 10 : (lv >= 5 ? lv - 3 : lv - 1);   // 杠杆错 → 大步降档
        else          lv = (lv >= 10) ? lv - 10 : lv - 1;   // 其他错也降档重试
    }
    return 1;                                  // 全部失败兜底 1x
}

bool okx_market_buy(const std::string& inst, int sz, std::string& resp) {   // 市价买
    std::string b = "{\"instId\":\"" + inst + "\",\"tdMode\":\"cross\",\"side\":\"buy\","   // 全仓/买入
                    "\"posSide\":\"long\",\"ordType\":\"market\",\"sz\":\"" + std::to_string(sz) + "\"}";   // 多头/市价/张数
    return okx_private("POST", "/api/v5/trade/order", b, resp);   // 下单
}

bool okx_close_position(const std::string& inst, std::string& resp) {   // 市价平仓
    std::string b = "{\"instId\":\"" + inst + "\",\"mgnMode\":\"cross\","   // 全仓
                    "\"posSide\":\"long\",\"cldOrdPx\":\"\"}";   // 平多仓/市价
    return okx_private("POST", "/api/v5/trade/close-position", b, resp);   // 一键平仓
}

// 撤销全部未触发条件单 (系统不挂止损止盈单, 开仓后清理历史残留)
void okx_cancel_all_algos(const std::string& inst) {   // 合约
    std::string body;                          // 响应体
    if (!okx_private("GET", "/api/v5/trade/orders-algo-pending?ordType=conditional&instId=" + inst, "", body))   // 查未触发条件单
        return;
    if (body.find("\"code\":\"0\"") == std::string::npos) return;   // 返回码异常
    std::string list;                          // 待撤单列表(JSON数组片段)
    for (auto& o : j_split_objects(body)) {    // 遍历每张条件单
        std::string aid = j_str(o, "algoId");  // 条件单 ID
        if (aid.empty()) continue;             // 无 ID 跳过
        if (!list.empty()) list += ",";        // 逗号分隔
        list += "{\"algoId\":\"" + aid + "\",\"instId\":\"" + inst + "\"}";   // 拼撤销项
    }
    if (list.empty()) return;                  // 无单可撤
    std::string resp;                          // 响应体
    okx_private("POST", "/api/v5/trade/cancel-algos", "[" + list + "]", resp);   // 批量撤销
}

// 最近 minutes 分钟平仓成交 → [pnl 合计, 出场均价]
bool okx_fills_pnl(const std::string& inst, int minutes, double& pnl, double& exitPx) {   // 合约/回看分钟/出参
    pnl = 0.0; exitPx = 0.0;                   // 出参清零
    long long begin = now_ms() - (long long)minutes * 60000LL;   // 起始毫秒
    std::string path = "/api/v5/trade/fills-history?instType=SWAP&instId=" + inst +   // 成交明细接口
                       "&begin=" + std::to_string(begin) + "&limit=100";   // 限100条
    std::string body;                          // 响应体
    if (!okx_private("GET", path, "", body)) return false;   // 请求失败
    if (body.find("\"code\":\"0\"") == std::string::npos) return false;   // 返回码异常
    double pxv = 0.0, szSum = 0.0;             // 加权价格累计/张数累计
    for (auto& f : j_split_objects(body)) {    // 遍历每笔成交
        if (j_str(f, "side") != "sell") continue;   // 只统计卖出(平仓)成交
        pnl += j_num(f, "fillPnl");            // 累加每笔已实现盈亏
        double sz = j_num(f, "fillSz");        // 成交张数
        pxv += sz * j_num(f, "fillPx");        // 张数×价格(加权)
        szSum += sz;                           // 累加张数
    }
    exitPx = szSum > 0 ? pxv / szSum : 0.0;    // 出场均价 = 加权平均
    return true;                               // 成功
}
