/*
 * ==================================================================
 * api_eps.cpp — apihub.exe 全部 HTTP 接口处理函数 (ep_*) 实现文件
 * ==================================================================
 * 文件职责:
 *   apihub.exe 是纯 C++ HTTP API 服务器(静态编译约 600KB, 替代原 PHP api.php,
 *   性能对比: kline 2.9x / livestats 220x)。本文件实现全部接口的业务逻辑:
 *   查询 MySQL(trading 库)、直连 OKX 公共行情补新、HMAC 签名查私有接口,
 *   统一返回 JSON; 全局 CORS:* + Cache-Control:no-store(由 main 层统一加头)。
 *
 * 接口清单 (ep_* 函数 → URL → 功能 → 主要参数):
 *   ep_kline     → /kline     → K线数据+MACD+按bucket聚合
 *                               参数: inst, tf(1m/3m/5m/15m/30m/1h/2h/4h),
 *                               limit(10~2000), bucket(分钟,聚合桶),
 *                               before(毫秒,取该时间之前→向更老翻页), after(毫秒,取之后),
 *                               fresh=N(>0 时先向 OKX 拉最近 N 根写库再返回,
 *                               同合约同周期 2 秒节流 g_kfreshCache)
 *   ep_live      → /live      → 实时价格+最近1m K线(直连OKX,失败回退DB)
 *                               参数: inst, tf(顺带刷新当前查看周期)
 *   ep_ticker    → /ticker    → 最新成交价(1秒缓存 g_tickCache)  参数: inst
 *   ep_trades    → /trades    → 成交流水(60秒内同向成交合并、OKX pos-history 补盈利)
 *   ep_stats     → /stats     → 统计汇总(日盈亏/总盈亏/回测/最新快照)
 *   ep_livestats → /livestats → 实时统计面板数据(整响应 1 秒 TTL 缓存 g_lsCache,
 *                               资金费率 60 秒缓存 g_fundCache)  参数: inst
 *   ep_gridmon   → /gridmon   → 网格监控(活动仓位+日志+策略参数JSON)
 *   ep_backcheck → /backcheck → 系统体检报告文本(E:\datas\log\backcheck.txt)
 *   ep_marks     → /marks     → 交易标记点(买/加/平,画在K线上)  参数: inst, days(1~30)
 *   ep_sigs      → /sigs      → 金▲转折点+力学指标(链接 sigcore 同源计算)
 *                               参数: inst, bar, limit, from, to(注意: t 为毫秒,
 *                               与 /kline 的秒级 t 不同!)
 *   ep_sigscan   → /sigscan   → 金信号全池扫描(默认 5m 走 30 秒后台缓存,
 *                               其他周期现算)  参数: bar
 *   ep_boot      → /boot      → 页面引导(默认合约+最近48小时交易过的合约)
 *   ep_symbols   → /symbols   → 合约池列表(symbol_pool 表)
 *   ep_settings  → /settings  → 设置键值存取(GET 读全表; 带 skey/sval 即写入,
 *                               skey 做了 [a-z0-9_] 字符白名单校验防SQL注入)
 *   ep_account   → /account   → 账户概况(OKX 私有接口 HMAC 签名查询余额/持仓)
 *   ep_health    → /health    → 健康自检(版本/落库序列数/合约池数量/锁仓参数/体检报告)
 *   ep_guard     → /guard     → 守护中枢状态(读 guard_status.json, 30秒不更新视为 stale)
 * ==================================================================
 */
// 公共头: Params/P/jnum/db_q/http_get/okx_parse_candles/缓存 等公共设施
#include "../common/hub.h"
// 标准输入输出: snprintf/fopen/fread
#include <cstdio>
// 字符串: strtol 等C字符串函数
#include <cstring>
// 时间: time(nullptr)
#include <ctime>
// 数学: floor
#include <cmath>
// 字符: isalnum/isxdigit
#include <cctype>
// 定长数组容器 std::array
#include <array>
// 线程(本文件未直接用,头文件统一引入)
#include <thread>
// 互斥锁: g_scanMtx
#include <mutex>
// 算法: std::reverse/std::sort/std::min/std::max
#include <algorithm>

// ---- tf(内部周期) → OKX bar 参数(小时必须大写, 小写静默取不到数据) ----
// 将内部周期字符串映射为 OKX API 的 bar 参数; OKX 要求小时级必须用大写 H, 小写会静默取不到数据
static std::string okx_bar_of(const std::string& tf) {   // 入参 tf: 内部周期表示(如 "1h")
    if (tf == "1m")  return "1m";    // 1分钟 → OKX "1m"(大小写不敏感)
    if (tf == "3m")  return "3m";    // 3分钟 → OKX "3m"
    if (tf == "5m")  return "5m";    // 5分钟 → OKX "5m"
    if (tf == "15m") return "15m";   // 15分钟 → OKX "15m"
    if (tf == "30m") return "30m";   // 30分钟 → OKX "30m"
    if (tf == "1h")  return "1H";    // 1小时 → OKX 必须大写 "1H"
    if (tf == "2h")  return "2H";    // 2小时 → OKX 必须大写 "2H"
    if (tf == "4h")  return "4H";    // 4小时 → OKX 必须大写 "4H"
    return "";                       // 未知周期返回空串, 调用方据此跳过
}

// ---- /kline ----
// K线主接口: 读库返回K线(可聚合)+MACD指标; 支持 before/after 翻页与 fresh 实时补新
std::string ep_kline(const Params& q) {   // q: HTTP 查询参数表
    static const std::map<std::string, int> IV = { {"1m",60},{"3m",180},{"5m",300},{"15m",900},{"1h",3600} };   // 周期→秒数(仅支持这5种原生周期, 2h/4h靠聚合)
    std::string tf = P(q, "tf", "1m");        // 取周期参数, 默认 1m
    auto ivit = IV.find(tf);                  // 在周期表里查找
    if (ivit == IV.end()) return "{\"err\":\"bad tf\"}";   // 非法周期直接报错
    int iv = ivit->second;                    // 该周期的秒数(如 5m=300)
    std::string inst = get_inst(q);           // 取合约ID(规范化, 默认 ETH-USDT-SWAP)

    // ---- fresh=N : 先向 OKX 拉当前周期最近 N 根(含"正在形成的那根")写入库, 再读库 ----
    // 页面 K 线"实时"的根本保障: DB 由 datahub 分级轮转落库, 单合约可能滞后数十分钟;
    // 同合约同周期 2 秒节流, 多客户端/高频轮询不会把 OKX 配额打爆。
    int freshN = atoi(P(q, "fresh", "0").c_str());   // 取 fresh 参数, 默认 0(不拉新)
    if (freshN > 0) {                          // 请求实时补新时
        if (freshN > 200) freshN = 200;        // 上限 200 根, 防 OKX 限频
        std::string bar = okx_bar_of(tf);      // 换算 OKX bar 参数(小时需大写)
        if (!bar.empty()) {                    // 仅已支持的周期才补新
            std::string ck = inst + "|" + tf, hit;   // 构造节流缓存键: 合约|周期
            if (!cache_get(g_kfreshCache, ck, hit)) {    // 2秒节流: 命中缓存则跳过本次OKX请求
                std::string fbody;             // 存放 OKX 原始响应
                if (http_get("www.okx.com",    // 直连 OKX 公共K线接口
                             "/api/v5/market/candles?instId=" + inst + "&bar=" + bar +
                             "&limit=" + std::to_string(freshN), fbody)) {   // 拉最近 freshN 根
                    auto fb = okx_parse_candles(fbody);   // 解析 OKX 响应为标准K线数组
                    if (!fb.empty()) store(inst, tf, fb);   // 解析成功则写入数据库
                }
                cache_put(g_kfreshCache, ck, "1", 2000);   // 无论成败都记 2 秒节流标记
            }
        }
    }

    std::string kt = ktable(inst, tf);        // 该合约+周期对应的K线表名
    int bmin = atoi(P(q, "bucket", "0").c_str());   // 取聚合桶宽度(分钟), 0=不聚合
    if (bmin < 1) bmin = iv / 60;             // 未指定则用周期本身的分钟数(即原生粒度)
    long long bm = (long long)bmin * 60 * 1000;   // 桶宽换算为毫秒
    int limit = atoi(P(q, "limit", "1000").c_str());   // 取返回根数上限, 默认 1000
    if (limit < 10) limit = 10;               // 下限 10 根
    if (limit > 2000) limit = 2000;           // 上限 2000 根
    bool hasBefore = q.count("before") && numok(P(q, "before"));   // 是否带合法 before(毫秒时间戳, 向更老翻页)
    bool hasAfter = q.count("after") && numok(P(q, "after"));      // 是否带合法 after(毫秒时间戳, 向更新翻页)
    long long before = hasBefore ? atoll_s(P(q, "before")) : 0;    // before 时间戳(毫秒)
    long long after = hasAfter ? atoll_s(P(q, "after")) : 0;       // after 时间戳(毫秒)

    bool exOk;                                // 未使用变量(保留原代码)
    (void)exOk;                               // 抑制未使用告警
    RowSet exr = db_q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='trading' AND table_name='" + kt + "'");   // 查 information_schema 判断K线表是否存在
    if (!exr.ok || exr.rows.empty() || atoi(exr.rows[0][0].c_str()) == 0)   // 表不存在(或查询失败/计数为0)
        return "{\"ok\":false,\"err\":\"no table " + kt + "\"}";   // 直接报错返回
    bool ok;                                  // db_scalar 的成功标志
    std::string lastS = db_scalar("SELECT MAX(candle_time) FROM " + kt, ok);   // 取库内最新K线时间(毫秒), 用于前端判断数据新鲜度

    std::string cond;                         // SQL 附加条件(时间翻页过滤)
    if (hasBefore) cond += " AND candle_time<" + std::to_string(before);   // before: 只取该毫秒之前 → 向更老翻页
    if (hasAfter) cond += " AND candle_time>" + std::to_string(after);     // after: 只取该毫秒之后
    const char* ord = hasAfter ? "ASC" : "DESC";   // after 翻页按时间升序取最早, 否则降序取最新

    // rows: [t(o秒), o,h,l,c, vol] (原生) 或聚合桶
    std::vector<std::array<double, 6>> rows;   // 结果行: [时间戳(秒), 开,高,低,收, 量]
    if (bm == (long long)iv * 1000) {          // 桶宽==原生周期 → 直接原生查询, 不聚合
        RowSet rs = db_q("SELECT candle_time,o,h,l,c,vol FROM " + kt + " WHERE c>0" + cond +
                         " ORDER BY candle_time " + ord + " LIMIT " + std::to_string(limit));   // 查原生K线(c>0过滤无效行)
        if (rs.ok) {                           // 查询成功
            if (!hasAfter) std::reverse(rs.rows.begin(), rs.rows.end());   // 降序查出的结果反转为升序输出
            for (auto& x : rs.rows)            // 遍历每一行
                rows.push_back({ (double)(atoll_s(x[0]) / 1000), atof_s(x[1]), atof_s(x[2]),   // 时间毫秒→秒, 转double
                                 atof_s(x[3]), atof_s(x[4]), atof_s(x[5]) });   // 高/收/量转double
        }
    } else {                                   // 桶宽>原生周期 → 查更多原生行后在内存聚合
        long long ratio = bm / ((long long)iv * 1000);   // 每个聚合桶包含的原生根数
        long long srcLimit = (long long)limit * ratio + 400;   // 需要的原生行数=目标根数×比例+富余
        if (srcLimit > 300000) srcLimit = 300000;   // 源行数上限 30 万, 防内存爆炸
        RowSet rs = db_q("SELECT candle_time,o,h,l,c,vol FROM " + kt + " WHERE c>0" + cond +
                         " ORDER BY candle_time " + ord + " LIMIT " + std::to_string(srcLimit));   // 拉取原生行
        if (rs.ok) {                           // 查询成功
            if (!hasAfter) std::reverse(rs.rows.begin(), rs.rows.end());   // 反转为时间升序
            std::map<long long, std::array<double, 6>> buck;   // 桶起始毫秒 → 聚合后的OHLCV
            for (auto& x : rs.rows) {          // 遍历原生行做聚合
                long long ts = atoll_s(x[0]);  // 原生行时间戳(毫秒)
                long long bk = (long long)floor((double)ts / bm) * bm;   // 归属桶的起始毫秒
                double o = atof_s(x[1]), h = atof_s(x[2]), l = atof_s(x[3]), c = atof_s(x[4]), v = atof_s(x[5]);   // 解出 OHLCV
                auto it = buck.find(bk);       // 桶是否已存在
                if (it == buck.end()) buck[bk] = { (double)(bk / 1000), o, h, l, c, v };   // 新桶: 时间=桶起点(秒), 直接放入
                else {                         // 已有桶则合并
                    auto& b = it->second;      // 取桶引用
                    b[5] += v;                 // 成交量累加
                    if (h > b[2]) b[2] = h;    // 更新桶内最高价
                    if (l < b[3]) b[3] = l;    // 更新桶内最低价
                    if (ts > b[0] * 1000) b[4] = c;   // 收盘=桶内最新一根(修正PHP口径)
                }
            }
            for (auto& kv : buck) rows.push_back(kv.second);   // map 按键(时间)升序, 依次输出聚合桶
        }
    }

    // MACD: warmup 700 根原生收盘
    int n = (int)rows.size();                  // 本次返回的行数
    std::vector<double> closes;                // MACD 计算用的收盘价序列(预热+本次)
    if (n > 0) {                               // 有数据才算指标
        long long t0ms = (long long)rows[0][0] * 1000;   // 首行时间(毫秒)
        RowSet wr = db_q("SELECT c FROM " + kt + " WHERE c>0 AND candle_time<" + std::to_string(t0ms) +
                         " ORDER BY candle_time DESC LIMIT 700");   // 取首行之前 700 根收盘价做 MACD 预热
        if (wr.ok) {                           // 预热查询成功
            closes.reserve(wr.rows.size() + n);   // 预分配容量
            for (auto it = wr.rows.rbegin(); it != wr.rows.rend(); ++it) closes.push_back(atof_s((*it)[0]));   // 降序结果倒序追加→升序
        }
        size_t off0 = closes.size();           // 预热段长度(本次行的偏移量)
        for (auto& r : rows) closes.push_back(r[4]);   // 追加本次每行的收盘价
        std::vector<double> dif, dea, hist, e12, e26;   // MACD 各输出序列
        macd_full(closes, 12, 26, 9, dif, dea, hist, e12, e26);   // 完整计算 MACD(12,26,9) 及 EMA
        size_t off = off0;                     // 从预热段末尾开始对齐本次行
        std::string body;                      // JSON 行数组体
        for (int k = 0; k < n; k++) {          // 逐行拼 JSON
            char row[512];                     // 单行格式化缓冲
            snprintf(row, 512, "[%.0f,%s,%s,%s,%s,%s,%s,%s,%s,%s,null,%s]",   // [t,o,h,l,c,dif,dea,hist,e12,e26,null,vol]
                     rows[k][0], jnum(rows[k][1]).c_str(), jnum(rows[k][2]).c_str(),   // 时间(秒)与开/高
                     jnum(rows[k][3]).c_str(), jnum(rows[k][4]).c_str(),               // 低/收
                     jnum(dif[off + k]).c_str(), jnum(dea[off + k]).c_str(), jnum(hist[off + k]).c_str(),   // DIF/DEA/柱
                     jnum(e12[off + k]).c_str(), jnum(e26[off + k]).c_str(), jnum(rows[k][5]).c_str());   // EMA12/26/量
            if (k) body += ",";                // 行间逗号分隔
            body += row;                       // 追加该行
        }
        long long nowMs = (long long)(time(nullptr)) * 1000;   // 当前服务器时间(毫秒)
        return "{\"ok\":true,\"tf\":\"" + tf + "\",\"bucket\":" + std::to_string(bmin) +   // 拼成功响应: 周期与桶宽
               ",\"iv\":" + std::to_string(iv) + ",\"rows\":[" + body + "],\"last\":" +   // 周期秒数+数据行+库内最新时间(秒)
               std::to_string(atoll_s(lastS) / 1000) + ",\"now\":" + std::to_string(nowMs) + "}";   // 当前时间(毫秒)
    }
    long long nowMs = (long long)(time(nullptr)) * 1000;   // 无数据时同样返回当前时间
    return "{\"ok\":true,\"tf\":\"" + tf + "\",\"bucket\":" + std::to_string(bmin) +       // 空行集的响应
           ",\"iv\":" + std::to_string(iv) + ",\"rows\":[],\"last\":" +                   // rows 为空数组
           std::to_string(atoll_s(lastS) / 1000) + ",\"now\":" + std::to_string(nowMs) + "}";   // 仍带 last/now
}

// ---- /live ----
// 实时行情: 直连 OKX 取最新价+最近 20 根 1m K线; 顺带刷新当前查看周期的K线入库; 失败回退 DB
std::string ep_live(const Params& q) {     // q: 查询参数(inst/tf)
    std::string inst = get_inst(q);        // 取合约ID
    std::string resp = "{\"ok\":0,\"price\":null,\"ts\":null,\"candles\":[]}";   // 默认失败响应
    std::string body;                      // OKX ticker 响应
    if (http_get("www.okx.com", "/api/v5/market/ticker?instId=" + inst, body)) {   // 请求 OKX 最新成交价
        std::string last = json_str(body, "last");   // 提取最新价字段
        if (!last.empty() && numok(last)) {           // 价格合法才继续
            std::string body2;             // 存放 1m K线响应
            if (http_get("www.okx.com", "/api/v5/market/candles?instId=" + inst + "&bar=1m&limit=20", body2)) {   // 请求最近 20 根 1m K线
                auto bars = okx_parse_candles(body2);   // 解析为标准K线
                if (!bars.empty()) {       // 解析非空
                    store(inst, "1m", bars);   // 页面在线也持续写库
                    // ---- 按需刷新"当前正在看的周期" ----
                    {                          // 局部作用域: 刷新当前 tf
                        std::string wtf = P(q, "tf");   // 页面正在看的周期
                        static const char* OK_BARS[] = {"1m","3m","5m","15m","30m","1H","2H","4H"};   // OKX bar 表示(小时大写)
                        static const char* MY_BARS[] = {"1m","3m","5m","15m","30m","1h","2h","4h"};   // 内部周期表示(小时小写)
                        std::string bar;       // 对应的 OKX bar
                        for (int i = 0; i < 8; i++) if (wtf == MY_BARS[i] || wtf == OK_BARS[i]) { bar = OK_BARS[i]; break; }   // 两种写法都匹配→得到 bar
                        if (!bar.empty() && bar != "1m") {   // 有效且不是 1m(1m 上面已拉)时
                            std::string body3; // 存放该周期K线响应
                            if (http_get("www.okx.com",   // 直连 OKX 拉该周期最近 50 根
                                         "/api/v5/market/candles?instId=" + inst + "&bar=" + bar + "&limit=50", body3)) {
                                auto wb = okx_parse_candles(body3);   // 解析
                                if (!wb.empty()) store(inst, wtf, wb);   // 写入库(按内部周期 tf)
                            }
                        }
                    }
                    std::string cd, price;     // K线 JSON 串与最新价
                    int tail = 0;              // 已输出的尾部根数
                    for (size_t i = bars.size(); i-- > 0 && tail < 20; i--, tail++) {   // 从最新往回最多取 20 根
                        auto& x = bars[i];     // 当前根
                        if (tail) cd += ",";   // 首根不加逗号
                        cd += "[" + std::to_string(atoll_s(x[0]) / 1000) + "," + x[1] + "," + x[2] + "," + x[3] + "," + x[4] + "]";   // [秒,o,h,l,c]
                        price = x[4];          // 最新一根的收盘价即实时价
                    }
                    char buf[64];              // 时间戳格式化缓冲
                    snprintf(buf, 64, "%lld", atoll_s(bars.back()[0]) / 1000);   // 最新根时间(毫秒→秒)
                    resp = "{\"ok\":1,\"price\":" + price + ",\"ts\":" + buf + ",\"candles\":[" + cd + "]}";   // 成功响应
                }
            }
        }
    }
    if (resp.find("\"ok\":0") != std::string::npos) {   // OKX 不通: 回退 DB 最新 20 根
        // OKX不通: 回退DB最新20根
        RowSet rs = db_q("SELECT candle_time,o,h,l,c FROM " + ktable(inst, "1m") +   // 读 1m K线表
                         " ORDER BY candle_time DESC LIMIT 20");   // 取最新 20 根
        if (rs.ok && !rs.rows.empty()) {       // 有数据才回退
            std::reverse(rs.rows.begin(), rs.rows.end());   // 反转为时间升序
            std::string cd;                    // K线 JSON 串
            for (size_t i = 0; i < rs.rows.size(); i++) {   // 逐行拼接
                auto& x = rs.rows[i];          // 当前行
                if (i) cd += ",";              // 行间逗号
                cd += "[" + std::to_string(atoll_s(x[0]) / 1000) + "," + jnum(atof_s(x[1])) + "," +   // 时间(秒)+开
                      jnum(atof_s(x[2])) + "," + jnum(atof_s(x[3])) + "," + jnum(atof_s(x[4])) + "]";   // 高/低/收
            }
            std::string price = jnum(atof_s(rs.rows.back()[4]));   // 最新一根收盘价
            resp = "{\"ok\":2,\"price\":" + price + ",\"ts\":" +   // ok=2 表示来自DB回退
                   std::to_string(atoll_s(rs.rows.back()[0]) / 1000) + ",\"candles\":[" + cd + "]}";   // 时间取最新根
        }
    }
    return resp;                           // 返回响应(ok=0/1/2)
}

// ---- /ticker ----
// 最新价: 1 秒缓存, 高频轮询友好
std::string ep_ticker(const Params& q) {   // q: 查询参数(inst)
    std::string inst = get_inst(q);        // 取合约ID
    std::string hit;                       // 缓存命中值
    if (cache_get(g_tickCache, inst, hit)) return hit;       // 1秒缓存
    std::string body;                      // OKX 响应
    if (http_get("www.okx.com", "/api/v5/market/ticker?instId=" + inst, body)) {   // 直连 OKX 取最新价
        std::string last = json_str(body, "last");   // 提取最新价
        if (!last.empty() && numok(last)) {           // 价格合法
            std::string out = "{\"ok\":true,\"price\":" + last + ",\"ts\":" + std::to_string(time(nullptr)) + "}";   // 拼成功响应(秒级时间戳)
            cache_put(g_tickCache, inst, out, 1000);   // 写 1 秒缓存
            return out;                    // 直接返回
        }
    }
    return "{\"ok\":false,\"price\":0,\"ts\":" + std::to_string(time(nullptr)) + "}";   // 失败响应
}

// ---- /trades (流水合并逻辑与 TradeApi::trades 同口径) ----
// 成交流水: 60 秒内同合约同动作的行合并, 平仓盈利以 OKX pos-history 为真源
std::string ep_trades(const Params&) {     // 无入参使用
    RowSet fl = db_q("SELECT trade_time, inst_id, action, price, profit, COALESCE(fee,0),"   // 查最近 800 条成交流水
                     " COALESCE(funding,0), sz, notional_usd, ord_id, remark"
                     " FROM trade_flow WHERE action IN ('buy','add','close')"
                     " ORDER BY trade_time DESC, id DESC LIMIT 800");   // 按时间倒序
    if (!fl.ok) return "{\"ok\":false,\"error\":\"db fail\"}";   // 查询失败
    // 平仓盈利真源: OKX pos-history 行
    std::map<std::string, std::vector<std::array<std::string, 4>>> ph;   // 合约 → [(时间,盈利,费,资金费)]
    RowSet cr = db_q("SELECT trade_time, inst_id, profit, COALESCE(fee,0), COALESCE(funding,0)"   // 查近 7 天带 OKX 真实盈利的平仓行
                     " FROM trade_flow WHERE action='close' AND profit IS NOT NULL"
                     " AND remark LIKE 'OKX pos-history%' AND trade_time >= NOW() - INTERVAL 7 DAY");
    if (cr.ok)                             // 查询成功
        for (auto& r : cr.rows)            // 按合约分组收集
            ph[r[1]].push_back({ r[0], r[2], r[3], r[4] });   // [时间,盈利,费,资金费]

    struct FR { std::string trade_time, inst_id, action, price, profit, fee, funding, sz, notional, ord_id, remark, strat; long long ts; bool profitNull; };   // 输出行结构(strat=开仓策略名)
    std::vector<FR> rows;                  // 最终输出集合
    auto strat_of = [](const std::string& remark) -> std::string {   // 从 remark 提取策略名("hybrid open <名>")
        size_t p = remark.find("hybrid open ");   // 定位前缀
        if (p == std::string::npos) return "";   // 没有则空
        p += 12;                           // 跳过前缀("hybrid open "长12)
        size_t e = p;                      // 名字结尾
        while (e < remark.size() && (isalnum((unsigned char)remark[e]) || remark[e] == '_')) e++;   // 连续字母数字下划线为名字
        return remark.substr(p, e - p);    // 截取策略名
    };
    for (auto& r : fl.rows) {              // 遍历流水行
        FR x{ r[0], r[1], r[2], r[3], r[4], r[5], r[6], r[7], r[8], r[9], r[10], "", parse_dt(r[0]), r[4].empty() };   // 转结构: 时间戳+盈利是否为空
        bool merged = false;               // 是否已并入前面的行
        for (auto& prev : rows) {          // 与已输出行尝试合并
            if (prev.action != x.action || prev.inst_id != x.inst_id) continue;   // 动作或合约不同不合并
            if (prev.ts > x.ts ? (prev.ts - x.ts) : (x.ts - prev.ts) > 60) continue;   // 时间差超过 60 秒不合并
            if (x.action == "close") {     // 平仓合并: 盈利/费用累加
                double pf = r4((prev.profitNull ? 0 : atof_s(prev.profit)) + (x.profitNull ? 0 : atof_s(x.profit)));   // 盈利求和(空值按0)
                prev.profit = jnum(pf);    // 回写盈利
                prev.fee = jnum(atof_s(prev.fee) + atof_s(x.fee));   // 手续费累加
                prev.funding = jnum(atof_s(prev.funding) + atof_s(x.funding));   // 资金费累加
                if (atof_s(x.price) > 0) prev.price = x.price;   // 用非零价覆盖
            } else {                       // 开仓/加仓合并: 数量与名义价值累加
                double notional = atof_s(prev.notional) + atof_s(x.notional);   // 名义价值累加
                double sz = atof_s(prev.sz) + atof_s(x.sz);   // 数量累加
                bool prevEngine = prev.remark.find("hybrid open") != std::string::npos || prev.remark.find("gold5m") != std::string::npos;   // 前行是否引擎单
                bool rowEngine = x.remark.find("hybrid open") != std::string::npos || x.remark.find("gold5m") != std::string::npos;   // 当前行是否引擎单
                if (rowEngine && !prevEngine) prev.remark = x.remark;   // 优先保留引擎单的 remark
                if (sz > 0) prev.sz = jnum(sz);   // 回写总数量
                prev.notional = jnum(notional);   // 回写总名义价值
                if (sz > 0 && notional > 0) prev.price = jnum(floor(notional / sz * 1e8) / 1e8);   // 重算均价(截断到1e-8)
                prev.fee = jnum(atof_s(prev.fee) + atof_s(x.fee));   // 手续费累加
                if (prev.ord_id.empty()) prev.ord_id = x.ord_id;   // 补订单号
            }
            merged = true;                 // 标记已合并
            break;                         // 只合并一次
        }
        if (merged) continue;              // 已合并, 跳过本行
        if (x.action == "close") {         // 未合并的平仓行: 用 OKX pos-history 补盈利
            if (x.profitNull) {            // 盈利为空才需要补
                auto it = ph.find(x.inst_id);   // 找该合约的 pos-history 行
                if (it != ph.end())            // 找到
                    for (auto& p : it->second) {   // 逐条尝试匹配
                        long long pts = parse_dt(p[0]);   // pos-history 时间
                        long long d = pts > x.ts ? pts - x.ts : x.ts - pts;   // 时间差绝对值
                        if (d <= 600) {        // 10 分钟内视为同一平仓
                            x.profit = p[1]; x.profitNull = false;   // 补盈利
                            if (atof_s(x.fee) == 0) x.fee = p[2];    // 费为0则补
                            if (atof_s(x.funding) == 0) x.funding = p[3];   // 资金费为0则补
                            break;         // 只匹配一条
                        }
                    }
            }
            if (atof_s(x.price) == 0 && x.profitNull) continue;   // 空台账行丢弃
        }
        x.strat = (x.action == "buy") ? strat_of(x.remark) : "";   // 仅开仓行提取策略名
        rows.push_back(std::move(x));      // 加入输出
    }
    std::sort(rows.begin(), rows.end(), [](const FR& a, const FR& b) { return a.trade_time > b.trade_time; });   // 合并后按时间倒序重排
    if (rows.size() > 500) rows.resize(500);   // 最终最多输出 500 条
    std::string body;                      // JSON 数组体
    for (auto& x : rows) {                 // 逐条拼 JSON
        if (!body.empty()) body += ",";    // 逗号分隔
        body += "{\"trade_time\":\"" + jesc(x.trade_time) + "\",\"inst_id\":\"" + jesc(x.inst_id) +   // 时间+合约
                "\",\"action\":\"" + jesc(x.action) + "\",\"price\":\"" + jesc(x.price) +   // 动作+价格
                "\",\"profit\":" + (x.profitNull ? "null" : "\"" + jesc(x.profit) + "\"") +   // 盈利(空则null)
                ",\"pnl\":" + (x.profitNull ? "null" : "\"" + jesc(x.profit) + "\"") +       // pnl 同 profit(兼容旧前端)
                ",\"entries\":0,\"ord_id\":\"" + jesc(x.ord_id) + "\",\"remark\":\"" + jesc(x.remark) +   // entries占位+订单号+备注
                "\",\"fee\":\"" + jesc(x.fee) + "\",\"funding\":\"" + jesc(x.funding) +   // 手续费+资金费
                "\",\"sz\":\"" + jesc(x.sz) + "\",\"notional_usd\":\"" + jesc(x.notional) +   // 数量+名义价值
                "\",\"strat\":\"" + jesc(x.strat) + "\"}";   // 策略名
    }
    return "{\"ok\":true,\"data\":[" + body + "]}";   // 成功响应
}

// ---- /stats ----
// 统计页: 日盈亏明细+总体汇总+回测汇总+最新账户快照
std::string ep_stats(const Params&) {      // 无入参使用
    const char* W = "profit IS NOT NULL AND profit <> 0 AND action NOT IN ('signal','guard') AND remark LIKE 'OKX pos-history%'";   // 统计口径: 仅 OKX 真实平仓行
    RowSet daily = db_q(std::string("SELECT DATE(trade_time) d, SUM(IF(profit>0,profit,0)) win_pnl, SUM(IF(profit<0,profit,0)) loss_pnl,") +   // 近 30 天按日聚合盈亏
                        " SUM(profit) net, SUM(COALESCE(fee,0)) fee_sum, SUM(COALESCE(funding,0)) fund_sum," +   // 净利+手续费+资金费
                        " SUM(IF(profit>0,1,0)) wins, COUNT(*) total FROM trade_flow WHERE " + W +   // 胜次数+总笔数
                        " GROUP BY DATE(trade_time) ORDER BY d DESC LIMIT 30");   // 按日期倒序取 30 天
    RowSet sum = db_q(std::string("SELECT COUNT(*) total, COALESCE(SUM(IF(p>0,1,0)),0) wins, COALESCE(SUM(p),0) net_pnl, COALESCE(AVG(p),0) avg_pnl,") +   // 总笔数/胜数/净利/均利
                      " (SELECT COALESCE(SUM(fee),0) FROM trade_flow WHERE " + W + ") fee_sum," +   // 全期手续费
                      " (SELECT COALESCE(SUM(funding),0) FROM trade_flow WHERE " + W + ") fund_sum" +   // 全期资金费
                      " FROM (SELECT profit p FROM trade_flow WHERE " + W + ") t");   // 同口径子查询
    std::string djs;                       // 日明细 JSON
    if (daily.ok)                          // 查询成功
        for (auto& r : daily.rows) {       // 逐日拼接
            if (!djs.empty()) djs += ",";  // 逗号分隔
            djs += "{\"d\":\"" + jesc(r[0]) + "\",\"win_pnl\":\"" + jesc(r[1]) + "\",\"loss_pnl\":\"" +   // 日期+盈利额+亏损额
                   jesc(r[2]) + "\",\"net\":\"" + jesc(r[3]) + "\",\"fee_sum\":\"" + jesc(r[4]) +   // 净利+手续费
                   "\",\"fund_sum\":\"" + jesc(r[5]) + "\",\"wins\":\"" + jesc(r[6]) + "\",\"total\":\"" + jesc(r[7]) + "\"}";   // 资金费+胜数+总数
        }
    std::string sjs = "null";              // 汇总 JSON(默认null)
    if (sum.ok && !sum.rows.empty()) {     // 汇总查询成功
        auto& r = sum.rows[0];             // 单行结果
        sjs = "{\"total\":\"" + jesc(r[0]) + "\",\"wins\":\"" + jesc(r[1]) + "\",\"net_pnl\":\"" + jesc(r[2]) +   // 总数+胜数+净利
              "\",\"avg_pnl\":\"" + jesc(r[3]) + "\",\"fee_sum\":\"" + jesc(r[4]) + "\",\"fund_sum\":\"" + jesc(r[5]) + "\"}";   // 均利+费+资金费
    }
    RowSet bt = db_q("SELECT COUNT(*) total, COALESCE(SUM(pnl),0) net_pnl FROM backtest_trades WHERE pnl IS NOT NULL");   // 回测汇总
    std::string bjs = "null";              // 回测 JSON(默认null)
    if (bt.ok && !bt.rows.empty())         // 查询成功
        bjs = "{\"total\":\"" + jesc(bt.rows[0][0]) + "\",\"net_pnl\":\"" + jesc(bt.rows[0][1]) + "\"}";   // 回测笔数+净利
    RowSet sn = db_q("SELECT snap_time, pnl, open_pos, pos_margin FROM pnl_history ORDER BY ts DESC LIMIT 1");   // 最新账户快照
    std::string njs = "null";              // 快照 JSON(默认null)
    if (sn.ok && !sn.rows.empty()) {       // 有快照
        auto& r = sn.rows[0];              // 单行
        njs = "{\"snap_time\":\"" + jesc(r[0]) + "\",\"pnl\":\"" + jesc(r[1]) + "\",\"open_pos\":\"" +   // 快照时间+浮盈+持仓数
              jesc(r[2]) + "\",\"pos_margin\":\"" + jesc(r[3]) + "\"}";   // 保证金
    }
    return "{\"ok\":true,\"data\":{\"daily\":[" + djs + "],\"summary\":" + sjs + ",\"backtest\":" + bjs + ",\"last_snap\":" + njs + "}}";   // 拼完整响应
}

// ---- /livestats ----
// 实时统计: 整响应 1 秒 TTL 缓存; 资金费率 60 秒缓存
std::string ep_livestats(const Params& q) {   // q: 查询参数(inst)
    std::string inst = get_inst(q);        // 取合约ID
    std::string cached;                    // 缓存命中值
    if (cache_get(g_lsCache, inst, cached)) return cached;   // 1秒微缓存
    RowSet s = db_q("SELECT COUNT(*) n, COALESCE(SUM(IF(profit>0,1,0)),0) wins, COALESCE(SUM(profit),0) net,"   // 已平仓统计: 笔数/胜数/净利
                    " COALESCE(SUM(COALESCE(fee,0)),0) fee_sum, COALESCE(SUM(COALESCE(funding,0)),0) fund_sum"   // 手续费+资金费
                    " FROM trade_flow WHERE action='close' AND profit IS NOT NULL AND remark LIKE 'OKX pos-history%'");   // 同样只算 OKX 真实行
    std::string nTr, nBuys;                // 总交易数/开仓次数
    {                                      // 局部作用域
        bool b1, b2;                       // 查询成功标志
        nTr = db_scalar("SELECT COUNT(*) FROM trade_flow WHERE action IN ('buy','add','close')", b1);   // 总流水数
        nBuys = db_scalar("SELECT COUNT(*) FROM trade_flow WHERE action IN ('buy','add')", b2);   // 开仓次数
        if (!b1) nTr = "0";                // 失败兜底 0
        if (!b2) nBuys = "0";              // 失败兜底 0
    }
    RowSet snap = db_q("SELECT pnl, open_pos, pos_margin, snap_time FROM pnl_history ORDER BY ts DESC LIMIT 1");   // 最新账户快照
    // 当前合约实时资金费率(OKX公共): 60s 缓存, 不再每请求都打 OKX
    std::string fjs = "null";              // 资金费率 JSON(默认null)
    if (!cache_get(g_fundCache, inst, fjs)) {   // 60 秒缓存未命中才请求
        fjs = "null";                      // 复位
        std::string body;                  // OKX 响应
        if (http_get("www.okx.com", "/api/v5/public/funding-rate?instId=" + inst, body)) {   // 请求 OKX 资金费率
            if (body.find("\"code\":\"0\"") != std::string::npos) {   // OKX 返回成功码
                std::string rate = json_str(body, "fundingRate");       // 当前费率
                std::string next = json_str(body, "nextFundingRate");   // 下期费率
                std::string fts = json_str(body, "fundingTime");        // 下次结算时间
                if (!rate.empty() && numok(rate))   // 费率合法才拼
                    fjs = "{\"rate\":" + rate + ",\"next\":" + (numok(next) ? next : "0") +   // rate+next(非法按0)
                          ",\"ts\":" + (numok(fts) ? fts : "0") + "}";   // 结算时间(非法按0)
            }
        }
        if (fjs != "null") cache_put(g_fundCache, inst, fjs, 60000);   // 成功才缓存(失败下次重试)
    }
    std::string net = "0", closed = "0", wins = "0", feeS = "0", fundS = "0";   // 已平仓统计默认值
    if (s.ok && !s.rows.empty()) {         // 统计查询成功
        net = jnum(r4(atof_s(s.rows[0][2])));   // 净利(保留4位)
        closed = s.rows[0][0]; wins = s.rows[0][1];   // 笔数+胜数
        feeS = jnum(r4(atof_s(s.rows[0][3]))); fundS = jnum(r4(atof_s(s.rows[0][4])));   // 费用合计
    }
    std::string upl = "0", openPos = "0", posM = "0", snapTime = "null";   // 快照默认值
    if (snap.ok && !snap.rows.empty()) {   // 快照存在
        upl = jnum(r4(atof_s(snap.rows[0][0])));   // 浮动盈亏
        openPos = snap.rows[0][1];         // 持仓数
        posM = jnum(r4(atof_s(snap.rows[0][2])));   // 保证金占用
        snapTime = "\"" + jesc(snap.rows[0][3]) + "\"";   // 快照时间(带引号)
    }
    std::string out = "{\"ok\":true,\"data\":{\"net_pnl\":" + net + ",\"closed\":" + closed + ",\"wins\":" + wins +   // 拼完整响应
           ",\"fee_sum\":" + feeS + ",\"fund_sum\":" + fundS + ",\"trades\":" + nTr + ",\"open_trades\":" + nBuys +   // 费用+总笔数+开仓数
           ",\"upl\":" + upl + ",\"open_pos\":" + openPos + ",\"pos_margin\":" + posM +   // 浮盈+持仓+保证金
           ",\"snap_time\":" + snapTime + ",\"funding\":" + fjs + ",\"inst\":\"" + jesc(inst) + "\"}}";   // 快照时间+资金费+合约
    cache_put(g_lsCache, inst, out, 1000);   // 1秒微缓存(页面3秒轮询, 多客户端共享)
    return out;                            // 返回响应
}

// ---- /gridmon ----
// 网格监控: 活动网格仓位+触发日志+策略参数
std::string ep_gridmon(const Params&) {    // 无入参使用
    std::string rows = "[]", logs = "[]", params = "null";   // 三个输出段默认空
    RowSet r1 = db_q("SELECT inst_id,updated,last_px,avg_px,atr_value,units,ladder_adds,max_levels,this_level_px,"   // 查活动网格信号(未平仓)
                     " next_down_px,next_up_px,dist_down_pct,dist_up_pct,next_add_usd,next_mult,"   // 下一档上下价位与距离
                     " sl_px,tp_px,sl_pct,tp_pct,state,note FROM grid_signal WHERE state <> '已平仓'"   // 止损止盈+状态+备注
                     " ORDER BY (state LIKE '★%' OR state LIKE '等待%') DESC, dist_down_pct ASC LIMIT 60");   // 关键状态优先, 再按下行距离升序
    if (r1.ok && !r1.rows.empty()) {       // 查询成功且有行
        rows = "[";                        // 开始拼数组
        for (size_t i = 0; i < r1.rows.size(); i++) {   // 逐行
            auto& r = r1.rows[i];          // 当前行
            const char* K[] = { "inst_id","updated","last_px","avg_px","atr_value","units","ladder_adds","max_levels",   // 21 个字段名(与SQL列对应)
                                "this_level_px","next_down_px","next_up_px","dist_down_pct","dist_up_pct",
                                "next_add_usd","next_mult","sl_px","tp_px","sl_pct","tp_pct","state","note" };
            std::string o = "{";           // 单个对象
            for (size_t k = 0; k < 21; k++) {   // 遍历 21 列
                if (k) o += ",";           // 逗号分隔
                o += std::string("\"") + K[k] + "\":\"" + jesc(r[k]) + "\"";   // "键":"值"(全部按字符串输出)
            }
            rows += o + "}";               // 收尾对象
            if (i + 1 < r1.rows.size()) rows += ",";   // 行间逗号
        }
        rows += "]";                       // 数组收尾
    }
    RowSet r2 = db_q("SELECT log_time,inst_id,level,last_px,trigger_px,add_usd,add_sz,kind,state,note"   // 查网格触发日志
                     " FROM grid_signal_log ORDER BY id DESC LIMIT 120");   // 最新 120 条
    if (r2.ok && !r2.rows.empty()) {       // 查询成功且有行
        logs = "[";                        // 开始拼数组
        for (size_t i = 0; i < r2.rows.size(); i++) {   // 逐行
            auto& r = r2.rows[i];          // 当前行
            const char* K[] = { "log_time","inst_id","level","last_px","trigger_px","add_usd","add_sz","kind","state","note" };   // 10 个字段名
            std::string o = "{";           // 单个对象
            for (size_t k = 0; k < 10; k++) {   // 遍历 10 列
                if (k) o += ",";           // 逗号分隔
                o += std::string("\"") + K[k] + "\":\"" + jesc(r[k]) + "\"";   // "键":"值"
            }
            logs += o + "}";               // 收尾对象
            if (i + 1 < r2.rows.size()) logs += ",";   // 行间逗号
        }
        logs += "]";                       // 数组收尾
    }
    RowSet sj = db_q("SELECT sval FROM app_settings WHERE skey='strategy_json'");   // 取策略参数 JSON 配置
    if (sj.ok && !sj.rows.empty() && sj.rows[0][0].find('{') == 0 && json_valid(sj.rows[0][0]))   // 以 { 开头且 JSON 合法
        params = sj.rows[0][0];            // 直接作为对象输出
    else if (sj.ok && !sj.rows.empty() && !sj.rows[0][0].empty())   // 有值但非法
        logline("gridmon: strategy_json 非法JSON, 已降级为 null (长度 " + std::to_string(sj.rows[0][0].size()) + ")");   // 记日志并降级
    return "{\"ok\":true,\"data\":{\"rows\":" + rows + ",\"logs\":" + logs + ",\"params\":" + params + "}}";   // 拼完整响应
}

// ---- /backcheck ----
// 体检报告: 直接读取固定路径的文本报告文件返回
std::string ep_backcheck(const Params&) {  // 无入参使用
    FILE* f = fopen("E:\\datas\\log\\backcheck.txt", "rb");   // 打开体检报告文件
    std::string txt = "暂无体检报告。";      // 默认提示
    if (f) {                               // 文件存在
        char buf[65537]; size_t n = fread(buf, 1, 65536, f); fclose(f);   // 最多读 64KB
        txt.assign(buf, n);                // 赋给字符串
    }
    return "{\"ok\":true,\"data\":\"" + jesc(txt) + "\"}";   // JSON 转义后返回
}

// ---- /marks ----
// 交易标记: 在 K 线上画买/加/平点位
std::string ep_marks(const Params& q) {    // q: 查询参数(inst/days)
    std::string inst = get_inst(q);        // 取合约ID
    int days = atoi(P(q, "days", "7").c_str());   // 查询天数, 默认 7
    if (days < 1) days = 1;                // 下限 1 天
    if (days > 30) days = 30;              // 上限 30 天
    std::string D = " trade_time >= NOW() - INTERVAL " + std::to_string(days) + " DAY";   // SQL 时间过滤片段
    struct Mk { long long t; std::string kind; std::string px; std::string profit; std::string strat; };   // 标记结构: 时间/类型/价/盈利/策略
    std::vector<Mk> out;                   // 输出集合
    // 买入: 引擎开仓行(整仓均价)
    RowSet rs = db_q("SELECT UNIX_TIMESTAMP(trade_time), price, remark FROM trade_flow"   // 查引擎开仓行
                     " WHERE inst_id='" + inst + "' AND action='buy' AND remark LIKE 'hybrid open%' AND" + D +
                     " ORDER BY trade_time ASC");   // 时间升序
    if (rs.ok)                             // 查询成功
        for (auto& r : rs.rows)            // 逐行转为标记
            out.push_back({ atoll_s(r[0]) * 1000, "buy", jnum(atof_s(r[1])), "null", strat_of_remark(r[2]) });   // 时间→毫秒, 盈利为null
    // 加仓: 引擎加仓行
    rs = db_q("SELECT UNIX_TIMESTAMP(trade_time), price FROM trade_flow"   // 查加仓行
              " WHERE inst_id='" + inst + "' AND action='add' AND" + D + " ORDER BY trade_time ASC");
    if (rs.ok)                             // 查询成功
        for (auto& r : rs.rows)            // 逐行转为标记
            out.push_back({ atoll_s(r[0]) * 1000, "add", jnum(atof_s(r[1])), "null", "gold5m" });   // 加仓固定标注 gold5m
    // 平仓: OKX真实盈利行(按分钟聚合)
    rs = db_q("SELECT FLOOR(UNIX_TIMESTAMP(trade_time)/60)*60, SUM(COALESCE(profit,0)), MAX(price)"   // 按分钟聚合盈利
              " FROM trade_flow WHERE inst_id='" + inst + "' AND action='close' AND profit IS NOT NULL"
              " AND remark LIKE 'OKX pos-history%' AND" + D + " GROUP BY FLOOR(UNIX_TIMESTAMP(trade_time)/60)*60"
              " ORDER BY FLOOR(UNIX_TIMESTAMP(trade_time)/60)*60 ASC");   // 按分钟升序
    if (rs.ok)                             // 查询成功
        for (auto& r : rs.rows) {          // 逐行转为标记
            double pf = atof_s(r[1]);      // 分钟合计盈利
            char pb[40]; snprintf(pb, 40, "%.2f", pf);   // 格式化为 2 位小数字符串
            out.push_back({ atoll_s(r[0]) * 1000, "close", jnum(atof_s(r[2])), pb, "" });   // 时间→毫秒, 价取该分钟最高价
        }
    std::sort(out.begin(), out.end(), [](const Mk& a, const Mk& b) { return a.t < b.t; });   // 全部按时间升序
    std::string body;                      // JSON 数组体
    for (auto& m : out) {                  // 逐个拼 JSON
        if (!body.empty()) body += ",";    // 逗号分隔
        body += "{\"t\":" + std::to_string(m.t) + ",\"kind\":\"" + m.kind + "\",\"px\":" + m.px +   // 时间+类型+价格
                ",\"profit\":" + m.profit + ",\"strat\":\"" + jesc(m.strat) + "\"}";   // 盈利+策略
    }
    return "{\"ok\":true,\"data\":[" + body + "]}";   // 成功响应
}

std::string ep_boot(const Params&) {       // 无入参使用
    RowSet rs = db_q("SELECT inst_id FROM trade_flow"   // 查最近 48 小时交易过的合约
                     " WHERE action IN ('buy','add','close') AND trade_time >= NOW() - INTERVAL 48 HOUR"
                     " GROUP BY inst_id ORDER BY MAX(id) DESC LIMIT 6");   // 按最新活动排序取 6 个
    std::string arr;                       // 最近合约 JSON 数组
    if (rs.ok)                             // 查询成功
        for (auto& r : rs.rows) {          // 逐个拼接
            if (!arr.empty()) arr += ",";  // 逗号分隔
            arr += "\"" + jesc(r[0]) + "\"";   // 带引号的合约名
        }
    std::string def = rs.ok && !rs.rows.empty() ? rs.rows[0][0] : "ETH-USDT-SWAP";   // 默认合约=最近交易的, 无则 ETH-USDT-SWAP
    return "{\"ok\":true,\"default\":\"" + jesc(def) + "\",\"recent\":[" + arr + "]}";   // 拼响应
}

// 合约池列表: 返回 symbol_pool 全部合约
std::string ep_symbols(const Params&) {    // 无入参使用
    RowSet rs = db_q("SELECT inst_id FROM symbol_pool ORDER BY inst_id");   // 按名称排序取全部
    std::string body;                      // JSON 数组体
    if (rs.ok)                             // 查询成功
        for (auto& r : rs.rows) {          // 逐个拼接
            if (!body.empty()) body += ",";   // 逗号分隔
            body += "\"" + jesc(r[0]) + "\"";   // 带引号的合约名
        }
    if (body.empty()) return "{\"ok\":true,\"data\":[\"ETH-USDT-SWAP\"]}";   // 池空兜底默认合约
    return "{\"ok\":true,\"data\":[" + body + "]}";   // 成功响应
}

// ---- /settings ----
// 设置键值: 带 skey/sval 即写入(skey 白名单校验防注入), 否则读全表
std::string ep_settings(const Params& q) { // q: 查询参数(skey/sval)
    std::string skey = P(q, "skey");       // 取键名
    if (!skey.empty()) {                   // 带键 → 写入模式
        std::string k;                     // 清洗后的键名
        for (char c : skey) {              // 逐字符白名单过滤
            char l = (c >= 'A' && c <= 'Z') ? c + 32 : c;   // 大写转小写
            if ((l >= 'a' && l <= 'z') || (l >= '0' && l <= '9') || l == '_') k += l;   // 仅保留 [a-z0-9_]
        }
        if (k.empty()) return "{\"ok\":false,\"error\":\"bad skey\"}";   // 清洗后为空 → 拒绝
        std::string sval = P(q, "sval");   // 取值(sqlesc 在SQL里转义)
        db_q("INSERT INTO app_settings (skey,sval) VALUES ('" + k + "','" + sqlesc(sval) +
             "') ON DUPLICATE KEY UPDATE sval=VALUES(sval)");   // upsert 键值
        return "{\"ok\":true,\"skey\":\"" + k + "\",\"sval\":\"" + jesc(sval) + "\"}";   // 回显写入结果
    }
    RowSet rs = db_q("SELECT skey,sval FROM app_settings");   // 无键 → 读全表
    std::string body;                      // JSON 对象体
    if (rs.ok)                             // 查询成功
        for (auto& r : rs.rows) {          // 逐行拼接
            if (!body.empty()) body += ",";   // 逗号分隔
            body += "\"" + jesc(r[0]) + "\":\"" + jesc(r[1]) + "\"";   // "键":"值"
        }
    return "{\"ok\":true,\"data\":{" + body + "}}";   // 成功响应
}

// ---- /account (OKX 私有签名) ----
// 账户概况: HMAC 签名调 OKX 私有接口查余额与持仓
std::string ep_account(const Params&) {    // 无入参使用
    std::string body;                      // 余额响应
    std::string err = okx_private_get("/api/v5/account/balance?ccy=USDT", body);   // 签名查询 USDT 余额
    if (!err.empty()) return "{\"ok\":false,\"error\":\"" + err + "\"}";   // 签名/网络失败
    bool codeOk = body.find("\"code\":\"0\"") != std::string::npos;   // OKX 业务码是否成功
    std::string avail = json_str(body, "availBal"), eq = json_str(body, "eq"), mgn = json_str(body, "mgnRatio");   // 可用余额/权益/保证金率
    std::string pbody;                     // 持仓响应
    int posN = 0;                          // 持仓数
    if (okx_private_get("/api/v5/account/positions", pbody).empty()) {   // 签名查询当前持仓
        size_t p = 0;                      // 查找位置
        while ((p = pbody.find("\"instId\"", p)) != std::string::npos) { posN++; p += 8; }   // 数 "instId" 出现次数=持仓数
    }
    if (!codeOk) return "{\"ok\":false,\"error\":\"okx code!=0\",\"positions\":" + std::to_string(posN) + "}";   // OKX 业务失败
    return "{\"ok\":true,\"avail\":" + (numok(avail) ? avail : "0") + ",\"equity\":" + (numok(eq) ? eq : "0") +   // 可用+权益(非法按0)
           ",\"mgnRatio\":" + (mgn.empty() ? "null" : "\"" + mgn + "\"") + ",\"positions\":" + std::to_string(posN) + "}";   // 保证金率+持仓数
}

// ---- /health ----
// 健康自检: 版本号/落库序列数/合约池数量/锁仓策略参数/体检报告前 8KB
std::string ep_health(const Params&) {     // 无入参使用
    FILE* f = fopen("E:\\datas\\log\\backcheck.txt", "rb");   // 打开体检报告
    std::string rep;                       // 报告内容
    if (f) {                               // 文件存在
        char buf[8193]; size_t n = fread(buf, 1, 8000, f); fclose(f);   // 只读前 8KB
        rep.assign(buf, n);                // 赋给字符串
    }
    RowSet pc = db_q("SELECT COUNT(*) FROM symbol_pool");   // 合约池数量
    int poolN = (pc.ok && !pc.rows.empty()) ? atoi(pc.rows[0][0].c_str()) : 0;   // 失败按 0
    return "{\"ok\":true,\"dll\":{\"version\":20270929,\"series\":0},\"pool\":{\"count\":" + std::to_string(poolN) +   // 合约池数量
           "},\"lock\":{\"lever\":20,\"entry_usd\":1.0,\"add_usd\":0.3333,\"tp_roi\":0.40,\"sl\":\"永不止损\",\"max_positions\":12},\"report\":\"" +   // 当前锁仓策略参数
           jesc(rep) + "\"}";              // 体检报告文本
}

// ---- /guard (守护中枢状态) ----
// 守护状态: 读 guard_status.json, 30 秒未更新视为 stale
std::string ep_guard(const Params&) {      // 无入参使用
    FILE* f = fopen("E:\\datas\\log\\guard_status.json", "rb");   // 打开守护状态文件
    if (!f) {                              // 文件不存在
        return "{\"ok\":false,\"error\":\"守护状态文件不存在(guard 服务未运行?)\","   // 报错说明
               "\"stale\":1,\"targets\":[],\"issues\":\"守护未运行\"}";   // stale=1, 空目标
    }
    char buf[16385];                       // 文件读取缓冲(16KB)
    size_t n = fread(buf, 1, 16384, f);    // 读文件
    fclose(f);                             // 关闭
    std::string body(buf, n);              // 转字符串
    while (!body.empty() && (body.back() == '\n' || body.back() == '\r' || body.back() == ' ')) body.pop_back();   // 去尾部空白
    if (!json_valid(body)) {               // JSON 校验失败
        logline("守护状态文件非法JSON, 已降级返回");   // 记日志
        return "{\"ok\":false,\"error\":\"守护状态文件损坏\",\"stale\":1,\"targets\":[],\"issues\":\"守护状态异常\"}";   // 降级响应
    }
    // 判断状态是否新鲜(超过 30 秒未更新 → 守护可能已死)
    long long age = -1;                    // 文件年龄(秒), -1=未知
    {                                      // 局部作用域: Win32 文件时间
        WIN32_FILE_ATTRIBUTE_DATA fa;      // 文件属性
        if (GetFileAttributesExA("E:\\datas\\log\\guard_status.json", GetFileExInfoStandard, &fa)) {   // 取最后写入时间
            FILETIME now; GetSystemTimeAsFileTime(&now);   // 当前系统时间(UTC FILETIME)
            ULARGE_INTEGER a, b;           // 64位化比较
            a.LowPart = fa.ftLastWriteTime.dwLowDateTime; a.HighPart = fa.ftLastWriteTime.dwHighDateTime;   // 文件最后写入时间
            b.LowPart = now.dwLowDateTime;                b.HighPart = now.dwHighDateTime;   // 当前时间
            age = (b.QuadPart > a.QuadPart) ? (long long)((b.QuadPart - a.QuadPart) / 10000000ULL) : 0;   // 差值换算秒(100ns单位)
        }
    }
    std::string extra = ",\"age_sec\":" + std::to_string(age) +   // 附加字段: 文件年龄
                        ",\"stale\":" + std::string(age < 0 || age > 30 ? "1" : "0");   // 超 30 秒或未知 → stale=1
    if (!body.empty() && body.back() == '}') { body.pop_back(); body += extra + "}"; }   // 在 JSON 末尾插入附加字段
    return body;                           // 返回(带附加字段的)守护状态
}

// ---- /btlist ----
// AI 模拟回测报告列表: 取最近 n 场(id/时间/规模/一句话简介), 首页面板数据源
std::string ep_btlist(const Params& q) {   // 可选参数 n(默认 3)
    int n = atoi(P(q, "n", "3").c_str());  // 场数
    if (n < 1 || n > 20) n = 3;            // 非法兜底
    RowSet rs = db_q("SELECT id,run_ts,period_start,period_end,n_traders,n_contracts,n_trades,brief "
                     "FROM bt_reports ORDER BY run_ts DESC LIMIT " + std::to_string(n));   // 最近 n 场
    if (!rs.ok) return "{\"ok\":false,\"error\":\"bt_reports 查询失败\"}";   // 查询失败
    std::string out = "{\"ok\":true,\"list\":[";   // 拼响应
    bool first = true;                     // 首元素逗号控制
    for (auto& r : rs.rows) {              // 逐场输出
        if (!first) out += ",";            // 第二场起补逗号
        first = false;
        out += "{\"id\":" + r[0] + ",\"ts\":" + r[1] + ",\"ps\":" + r[2] + ",\"pe\":" + r[3] +
               ",\"traders\":" + r[4] + ",\"contracts\":" + r[5] + ",\"trades\":" + r[6] +
               ",\"brief\":\"" + jesc(r[7]) + "\"}";   // 简介(转义防注入)
    }
    return out + "]}";                     // 收尾
}

// ---- /btreport ----
// 单场完整报告: ?id=场次ID → summary_json(全员) + top10_json(前十详细含评语/感言/明细)
std::string ep_btreport(const Params& q) { // 参数 id 必填
    std::string id = P(q, "id", "0");      // 取场次 ID
    if (id.empty() || id.find_first_not_of("0123456789") != std::string::npos)   // 只允许纯数字(防注入)
        return "{\"ok\":false,\"error\":\"id 非法\"}";
    RowSet rs = db_q("SELECT run_ts,period_start,period_end,n_traders,n_contracts,n_trades,brief,summary_json,top10_json "
                     "FROM bt_reports WHERE id=" + id);   // 取整场报告
    if (!rs.ok || rs.rows.empty()) return "{\"ok\":false,\"error\":\"报告不存在\"}";   // 不存在
    auto& r = rs.rows[0];                  // 单行
    return "{\"ok\":true,\"id\":" + id + ",\"ts\":" + r[0] + ",\"ps\":" + r[1] + ",\"pe\":" + r[2] +
           ",\"traders\":" + r[3] + ",\"contracts\":" + r[4] + ",\"trades\":" + r[5] +
           ",\"brief\":\"" + jesc(r[6]) + "\",\"summary\":" + r[7] + ",\"top10\":" + r[8] + "}";   // JSON 直拼(库内已是合法 JSON)
}
