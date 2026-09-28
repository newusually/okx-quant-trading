/* ==================================================================
 * trade_backfill.cpp — 成交回填 + K线洞扫描 + 合约池每日同步守护
 *
 * 【文件职责】
 *   回填守护线程(3 秒节拍, 与引擎线程并行)：
 *   ① 成交回填 backfill_fills: fills-history 回看 180 分钟, OKX 翻页方向
 *      after=更旧 / before=更新, fills 最多 5 页(限频 10次/2秒);
 *      pos-history 单页 100 条防游标死循环; 时间截断 CUTOFF=2026-09-27
 *      22:40 本地时区(老数据一律不收), 回填 trade_flow 真实盈亏。
 *   ② K线洞扫描 hole_scan: 每轮 1 个合约 × 6 周期(1m~4h), MIN/MAX 差
 *      槽位数 > 实际行数即有洞, 用 before= 逐段补, 补不动即停防死循环。
 *   ③ 洞扫描状态落库: app_settings skey='fill_holes' 紧凑格式
 *      "日期|BASE1,BASE2,..."(477 合约约 2.4KB, 不超 sval 上限), 重启可续。
 *   ④ 池同步 pool_sync_run: 每日 00:30 后一次, 只剔除不新增
 *      (股票类/非live/价格越界 0.001~100/公告下线)。
 *
 * 【函数清单】
 *   cutoff_ms()            截断时间 2026-09-27 22:40 → 毫秒(只算一次)
 *   backfill_fills()       fills-history 逐页回填 trade_flow(≤5页)
 *   backfill_pos_history() positions-history 单页回填(有 realizedPnl 才入)
 *   hole_scan()            单合约单周期洞扫描 + before= 逐段补洞
 *   hole_state_load/save() 洞扫描状态读写 app_settings.fill_holes
 *   fetch_url()            HTTPS GET(限 www.okx.com 域名)
 *   strip_tags()           去除 HTML 标签
 *   find_delist_slugs()    提取 /help/ 下含 delist 的文章 slug
 *   find_bases()           提取 BASEUSDT 形式合约基础名
 *   pool_sync_run()        合约池每日过滤同步
 *   pool_sync_if_due()     每日 00:30 后触发一次(首次调用只登记日期)
 *   backfill_loop()        回填主循环(3 秒节拍)
 *
 * 【数据流】
 *   OKX fills-history / positions-history → 去重(CUTOFF 截断) → trade_flow
 *   trade_flow.close → position_detail 状态 CLOSED(真实盈亏以回填为准)
 *   K线表 MIN/MAX/COUNT → hole_scan → kl_fetch_store(before=) 补洞
 *   OKX instruments/tickers + 公告网页 → pool_sync_run → symbol_pool
 * ================================================================== */
// ==================================================================
// trade_backfill.cpp — 成交回填 + K线洞扫描 + 合约池每日同步
//   逐字迁移自 engine/backfill.php + engine/pool_sync.php
//   ① 每分钟: fills-history(最多5页) + positions-history(单页) 回填 trade_flow
//      时间截断 CUTOFF=2026-09-27 22:40 本地时区(老数据一律不收)
//   ② 洞扫描: 每轮 1 个合约 × 6 周期, 扫出的洞用 before= 逐段补
//   ③ 池同步: 每日 00:30 后过滤(剔除股票类/非live/价格越界/公告下线)
// ==================================================================
#include "tradehub.h"                          // 本组件公共声明
#include <cstdio>                              // snprintf
#include <cmath>                               // fabs
#include <ctime>                               // time/localtime_s
#include <cctype>                              // tolower/isupper/isdigit
#include <map>                                 // std::map(合约元数据/价格)
#include <set>                                 // std::set(去重)
#include <climits>                             // LLONG_MAX

const int FILLS_BACKFILL_MIN = 180;            // 每轮回看 180 分钟成交(对外常量, 声明在头文件)

// 截断时间(本地时区, 与 PHP strtotime 同口径)
long long cutoff_ms() {                        // 老于此刻的数据一律不收
    static long long v = local_dt_to_unix("2026-09-27 22:40:00") * 1000LL;   // 只算一次(静态)
    return v;                                  // 返回毫秒
}

// ---------------- 成交明细回填 ----------------
static int backfill_fills() {                  // 返回本轮新回填条数
    long long begin = now_ms() - (long long)FILLS_BACKFILL_MIN * 60000LL;   // 起始=现在-180分钟
    std::string after;                         // 翻页游标(billId)
    int n = 0;                                 // 新增计数
    for (int page = 0; page < 5; page++) {     // fills 最多 5 页(防游标死循环)
        if (!g_run) break;                     // 全局退出标志
        std::string path = "/api/v5/trade/fills-history?instType=SWAP&begin=" +   // 成交明细接口
                           std::to_string(begin) + "&limit=100";   // 每页100条
        if (!after.empty()) path += "&after=" + after;   // OKX 翻页: after=取更旧一页
        std::string body;                      // 响应体
        if (!okx_private("GET", path, "", body)) break;   // 请求失败结束
        if (body.find("\"code\":\"0\"") == std::string::npos) break;   // 返回码异常结束
        auto objs = j_split_objects(body);     // 拆出成交对象数组
        if (objs.empty()) break;               // 无数据=已到头
        long long oldest = LLONG_MAX;          // 本页最早时间戳
        for (auto& f : objs) {                 // 遍历每笔成交
            long long ts = (long long)j_num(f, "ts");   // 成交毫秒时间
            if (ts < oldest) oldest = ts;      // 记录本页最早
            if (ts < cutoff_ms()) continue;    // 老数据一律不收
            std::string inst = j_str(f, "instId");   // 合约
            if (inst.empty()) continue;        // 空合约跳过
            std::string t = ms_to_dt(ts);      // 转本地时间串
            std::string action = (j_str(f, "side") == "buy") ? "buy" : "close";   // 买→buy, 卖→close
            std::string remark = (action == "close")   // 备注区分来源
                ? ("OKX pos-history (fills " + j_str(f, "subType") + ")")   // 平仓备注带 subType
                : std::string("OKX fills backfill");   // 买入备注
            double px = j_num(f, "fillPx");    // 成交价
            double sz = j_num(f, "fillSz");    // 成交张数
            char sql[2048];                    // SQL 缓冲
            snprintf(sql, sizeof(sql),         // 五元组去重(时间+合约+动作+价+量)
                "SELECT COUNT(*) FROM trade_flow WHERE inst_id='%s' AND trade_time='%s'"
                " AND action='%s' AND price=%.10g AND sz=%.10g",
                inst.c_str(), t.c_str(), action.c_str(), px, sz);
            bool ok = false;                   // db_scalar 出参
            if (atoll_s(db_scalar(sql, ok)) > 0) continue;   // 已存在跳过
            double notional = j_num(f, "fillNotional");   // 名义价值
            if (notional == 0) notional = px * sz;   // 缺失按 价×量 估算
            double fee = fabs(j_num(f, "fee"));      // 手续费(取绝对值)
            std::string ordId = j_str(f, "ordId");   // 订单 ID
            std::string posSide = j_str(f, "posSide");   // 持仓方向
            if (posSide.empty()) posSide = "long";   // 缺失兜底多头
            if (action == "close") {           // 平仓成交: 带 fillPnl 盈亏
                snprintf(sql, sizeof(sql),
                    "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,sz,notional_usd,"
                    "profit,fee,ord_id,remark) VALUES ('%s','%s','%s','%s',%.10g,%.10g,%.10g,%.10g,%.10g,'%s','%s')",   // profit=fillPnl
                    t.c_str(), inst.c_str(), action.c_str(), posSide.c_str(), px, sz, notional,
                    j_num(f, "fillPnl"), fee, sqlesc(ordId).c_str(), sqlesc(remark).c_str());
            } else {                           // 买入成交: profit 为 NULL
                snprintf(sql, sizeof(sql),
                    "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,sz,notional_usd,"
                    "profit,fee,ord_id,remark) VALUES ('%s','%s','%s','%s',%.10g,%.10g,%.10g,NULL,%.10g,'%s','%s')",
                    t.c_str(), inst.c_str(), action.c_str(), posSide.c_str(), px, sz, notional,
                    fee, sqlesc(ordId).c_str(), sqlesc(remark).c_str());
            }
            if (db_ex(sql)) n++;               // 入库成功才计数
        }
        if (oldest < cutoff_ms()) break;       // 整页已老于截断 → 完成
        after = j_str(objs.back(), "billId");  // 翻页游标=本页最后一条 billId(after=更旧)
        if (after.empty() || objs.size() < 100) break;   // 游标空/不足一页=已到头
        Sleep(250);                            // fills-history 限频 10次/2秒
    }
    return n;                                  // 返回新增条数
}

// ---------------- 历史仓位回填(单页100条/分钟, 有 realizedPnl 才入) ----------------
static int backfill_pos_history() {            // 返回本轮新回填条数
    int n = 0;                                 // 新增计数
    std::string body;                          // 响应体
    if (!okx_private("GET", "/api/v5/account/positions-history?instType=SWAP&limit=100", "", body)) return 0;   // 只拉单页100条(防游标死循环)
    if (body.find("\"code\":\"0\"") == std::string::npos) return 0;   // 返回码异常
    for (auto& f : j_split_objects(body)) {    // 遍历每条历史仓位
        long long ts = (long long)j_num(f, "uTime");   // 更新时间毫秒
        if (ts < cutoff_ms()) continue;        // 老数据不收
        double pnl = j_num(f, "realizedPnl");  // 已实现盈亏
        if (pnl == 0) continue;                // 无盈亏不入
        std::string inst = j_str(f, "instId"); // 合约
        if (inst.empty()) continue;            // 空合约跳过
        std::string t = ms_to_dt(ts);          // 平仓时间串
        std::string tMinus = ms_to_dt(ts - 60000LL);   // 平仓前1分钟(去重窗口下界)
        char sql[2048];                        // SQL 缓冲
        snprintf(sql, sizeof(sql),             // 查是否已有 fills 口径的平仓行
            "SELECT COUNT(*) FROM trade_flow WHERE inst_id='%s' AND action='close'"
            " AND trade_time BETWEEN '%s' AND '%s' AND remark LIKE 'OKX pos-history%%'",
            inst.c_str(), tMinus.c_str(), t.c_str());
        bool ok = false;                       // db_scalar 出参
        double funding = j_num(f, "fundingFee");   // 资金费
        if (atoll_s(db_scalar(sql, ok)) > 0) { // 已有行
            // 已有 fills 口径盈利行: 仅补资金费, 费用不重复计
            snprintf(sql, sizeof(sql),         // 只 UPDATE funding 字段
                "UPDATE trade_flow SET funding=%.10g WHERE id=("
                " SELECT id FROM (SELECT id FROM trade_flow WHERE inst_id='%s' AND action='close'"
                " AND trade_time BETWEEN '%s' AND '%s' AND remark LIKE 'OKX pos-history%%'"
                " ORDER BY id DESC LIMIT 1) x)",   // 子查询取最新一条
                funding, inst.c_str(), tMinus.c_str(), t.c_str());
            db_ex(sql);                        // 执行补资金费
            continue;                          // 不重复插入
        }
        snprintf(sql, sizeof(sql),             // 新插入平仓行(带总费用/资金费/类型)
            "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,profit,fee,funding,remark)"
            " VALUES ('%s','%s','close','long',%.10g,%.10g,%.10g,%.10g,'%s')",
            t.c_str(), inst.c_str(), j_num(f, "closeAvgPx"), pnl,   // 平仓均价+已实现盈亏
            fabs(j_num(f, "totalFee")), funding,
            sqlesc("OKX pos-history type=" + j_str(f, "type")).c_str());
        db_ex(sql);                            // 执行插入
        snprintf(sql, sizeof(sql),             // 同步关台账并写盈亏
            "UPDATE position_detail SET status='CLOSED', close_time='%s', profit=%.10g"
            " WHERE inst_id='%s' AND status='OPEN'", t.c_str(), pnl, inst.c_str());
        db_ex(sql);                            // 执行更新
        n++;                                   // 计数
    }
    return n;                                  // 返回新增条数
}

// ---------------- K线洞扫描(单合约单周期: 找空洞逐段补) ----------------
static int hole_scan(const std::string& inst, const std::string& tf) {   // 合约/周期
    std::string t = ktable(inst, tf);          // K线表名
    long long iv = kl_iv_ms(tf);               // 周期毫秒
    bool ok = false;                           // db_scalar 出参
    std::string minS = db_scalar("SELECT MIN(candle_time) FROM " + t, ok);   // 最早K线时间
    std::string maxS = db_scalar("SELECT MAX(candle_time) FROM " + t, ok);   // 最晚K线时间
    if (minS.empty() || maxS.empty()) return 0;   // 空表跳过
    long long mn = atoll_s(minS), mx = atoll_s(maxS);   // 转毫秒
    if (mn <= 0 || mx <= 0) return 0;          // 非法跳过
    long long slots = (mx - mn) / iv + 1;      // 理论槽位数
    long long cnt = atoll_s(db_scalar("SELECT COUNT(*) FROM " + t, ok));   // 实际行数
    if (cnt >= slots) return 0;                // 无洞
    long long prev = -1;                       // 上一条时间戳(-1=首条)
    const int pageSize = 20000;                // 分页大小(2万条)
    int offset = 0;                            // 分页偏移
    while (g_run) {                            // 分页遍历全表
        RowSet rs = db_q("SELECT candle_time FROM " + t + " ORDER BY candle_time ASC LIMIT " +   // 按时间升序取一页
                         std::to_string(pageSize) + " OFFSET " + std::to_string(offset));
        if (!rs.ok || rs.rows.empty()) break;  // 无更多数据
        for (auto& r : rs.rows) {              // 遍历本页
            if (!g_run) break;                 // 退出标志
            long long ct = atoll_s(r[0]);      // 当前时间戳
            if (prev >= 0 && ct - prev > iv) { // 相邻间隔>1周期 → 有洞
                long long after = prev;        // 从洞左端开始
                while (after < ct - iv) {      // 逐段补到洞右端
                    if (!g_run) break;         // 退出标志
                    kl_fetch_store(inst, tf, 300, after, true, false, "before");   // OKX 翻页: before=取更新一段
                    Sleep(150);                // 限频小睡
                    std::string nm = db_scalar("SELECT MAX(candle_time) FROM " + t +    // 查补到的最晚时间
                                               " WHERE candle_time<" + std::to_string(ct), ok);
                    long long newMax = nm.empty() ? 0 : atoll_s(nm);   // 新右端
                    if (newMax <= after) break;   // 补不动了, 防死循环
                    after = newMax;            // 推进游标
                }
            }
            prev = ct;                         // 记录上一条
        }
        offset += pageSize;                    // 下一页
        if ((int)rs.rows.size() < pageSize) break;   // 不足一页=到底
    }
    return 1;                                  // 本合约本周期扫描完成
}

// ---------------- 洞扫描状态落库(app_settings.fill_holes) ----------------
// 紧凑格式: "2026-09-28|BASE1,BASE2,..." (477 合约约 2.4KB, 不超 sval 上限)
static std::string g_holeDay;                  // 当前扫描日期
static std::set<std::string> g_holeBases;      // 当日已扫描的基础名集合

static void hole_state_load() {                // 启动时从 DB 恢复状态
    bool ok = false;                           // db_scalar 出参
    std::string v = db_scalar("SELECT sval FROM app_settings WHERE skey='fill_holes'", ok);   // 读紧凑串
    size_t p = v.find('|');                    // 日期与名单的分隔
    if (p == std::string::npos) return;        // 格式非法跳过
    g_holeDay = v.substr(0, p);                // 恢复日期
    std::string rest = v.substr(p + 1);        // 剩余=逗号分隔 BASE 名单
    size_t i = 0;                              // 游标
    while (i < rest.size()) {                  // 逐段切分
        size_t c = rest.find(',', i);          // 找下一个逗号
        if (c == std::string::npos) c = rest.size();   // 没有则到末尾
        if (c > i) g_holeBases.insert(rest.substr(i, c - i));   // 装入集合
        i = c + 1;                             // 跳过逗号
    }
}

static void hole_state_save() {                // 状态写回 DB(紧凑格式)
    std::string v = g_holeDay + "|";           // 日期|前缀
    bool first = true;                         // 逗号控制
    for (auto& b : g_holeBases) {              // 逐个 BASE 拼接
        if (!first) v += ",";                  // 非首个加逗号
        v += b;                                // 拼 BASE
        first = false;                         // 关闭首标志
        if (v.size() > 6000) break;            // 防超长(6000即截断)
    }
    if (v.size() <= 7000)                      // 总长 7000 上限(sval 限制)
        db_ex("REPLACE INTO app_settings (skey,sval,updated_at) VALUES ('fill_holes','" +   // REPLACE 整串覆写
              sqlesc(v) + "',NOW())");
}

// ---------------- 合约池每日同步(池内过滤, 不新增合约) ----------------
static std::string fetch_url(const std::string& url) {   // 抓取网页(仅 OKX 域名)
    if (url.compare(0, 8, "https://") != 0) return "";   // 必须是 https
    std::string rest = url.substr(8);          // 去掉协议头
    size_t sl = rest.find('/');                // host/path 分界
    if (sl == std::string::npos) return "";    // 无路径非法
    std::string host = rest.substr(0, sl);     // 主机名
    std::string path = rest.substr(sl);        // 路径
    std::string hdr = "Accept-Language: zh-CN,zh;q=0.9,en;q=0.8\r\n";   // 请求头(中文优先)
    std::string out;                           // 响应体
    if (http_req("GET", host, path, hdr, "", out)) return out;   // 发请求
    return "";                                 // 失败返回空
}

static std::string strip_tags(const std::string& s) {    // 去除 HTML 标签
    std::string o;                             // 输出缓冲
    o.reserve(s.size() / 2);                   // 预留一半容量
    bool in = false;                           // 是否在标签内
    for (char c : s) {                         // 逐字符扫描
        if (c == '<') { in = true; continue; } // 进标签
        if (c == '>') { in = false; o += ' '; continue; }   // 出标签补空格
        if (!in) o += c;                       // 标签外才输出
    }
    return o;                                  // 返回纯文本
}

// 提取 /help/ 之后含 "delist" 的文章 slug (等价于 PHP 正则, 手写避免 std::regex 膨胀)
static void find_delist_slugs(const std::string& html, std::set<std::string>& out) {   // 网页源码/输出集合
    size_t p = 0;                              // 搜索游标
    while ((p = html.find("/help/", p)) != std::string::npos) {   // 找每个 /help/ 链接
        size_t st = p + 6, e = st;             // slug 起点/终点
        while (e < html.size()) {              // 扫 slug 合法字符
            unsigned char c = (unsigned char)html[e];   // 当前字符
            if (islower(c) || isdigit(c) || html[e] == '-') e++;   // 小写/数字/横杠
            else break;                        // 其他字符结束
        }
        if (e > st) {                          // 有有效 slug
            std::string s = html.substr(st, e - st);   // 截取
            if (s.find("delist") != std::string::npos) out.insert(s);   // 含 delist 才收
        }
        p = (e > st) ? e : st;                 // 推进游标
    }
}

// 提取 BASEUSDT 形式的合约基础名 (等价于 PHP \b([A-Z0-9]{2,15})USDT\b)
static void find_bases(const std::string& txt, std::set<std::string>& out) {   // 文本/输出集合
    size_t i = 0, n = txt.size();              // 游标/长度
    while (i < n) {                            // 全文扫描
        unsigned char c = (unsigned char)txt[i];   // 当前字符
        if (!(isupper(c) || isdigit(c))) { i++; continue; }   // 非大写/数字跳过
        size_t st = i;                         // 词起点
        while (i < n) {                        // 吃连续大写/数字
            unsigned char d = (unsigned char)txt[i];
            if (isupper(d) || isdigit(d)) i++;
            else break;
        }
        size_t len = i - st;                   // 词长
        if (len >= 2 && len <= 15 && i + 4 <= n && txt.compare(i, 4, "USDT") == 0) {   // 2~15位且紧跟USDT
            bool tailOk = (i + 4 >= n);        // USDT 后必须是词边界
            if (!tailOk) {
                unsigned char nx = (unsigned char)txt[i + 4];
                tailOk = !(isupper(nx) || isdigit(nx));   // 后继非字母数字才合法
            }
            if (tailOk) out.insert(txt.substr(st, len));   // 收入 BASE 名
        }
    }
}

static void pool_sync_run() {                  // 池同步执行体(每日一次)
    RowSet prs = db_q("SELECT inst_id FROM symbol_pool");   // 读当前池
    std::vector<std::string> poollist;         // 池列表
    if (prs.ok) for (auto& r : prs.rows) if (!r.empty()) poollist.push_back(r[0]);   // 装入
    tlog("==== pool_sync 启动 | 当前池 " + std::to_string(poollist.size()) + " 个 ====");   // 启动日志

    // ① OKX 官方分类 + 交易状态
    std::string body;                          // 响应体
    if (!okx_public_get("/api/v5/public/instruments?instType=SWAP", body) ||   // 拉全部 SWAP 合约元数据
        body.find("\"code\":\"0\"") == std::string::npos) {   // 失败
        tlog("ERROR instruments 拉取失败, 本轮跳过(保持原池)");   // 保持原池不动
        return;
    }
    std::map<std::string, std::pair<std::string, std::string>> meta;   // instId → (cat,state)
    for (auto& d : j_split_objects(body)) {    // 遍历合约元数据
        std::string id = j_str(d, "instId");   // 合约 ID
        if (id.empty()) continue;              // 空跳过
        std::string cat = j_str(d, "instCategory");   // 官方分类(1=普通)
        if (cat.empty()) cat = "1";            // 缺失兜底普通类
        std::string st = j_str(d, "state");    // 交易状态
        if (st.empty()) st = "live";           // 缺失兜底 live
        meta[id] = std::make_pair(cat, st);    // 入表
    }

    // ② 最新价
    std::map<std::string, double> px;          // instId → 最新价
    std::string tk;                            // tickers 响应体
    if (okx_public_get("/api/v5/market/tickers?instType=SWAP", tk) &&   // 拉全市场行情
        tk.find("\"code\":\"0\"") != std::string::npos)
        for (auto& t : j_split_objects(tk)) {  // 遍历每个 ticker
            std::string id = j_str(t, "instId");   // 合约
            if (!id.empty()) px[id] = j_num(t, "last");   // 记最新价
        }

    // ③ 下架公告解析(帮助中心网页; 公告 API 地区封锁)
    std::set<std::string> delisted;            // 已公告下线的基础名
    const char* HOSTS[] = { "https://www.okx.com/zh-hans", "https://www.okx.ac/en-ar", "https://www.okx.pro" };   // 多镜像域名
    std::vector<std::string> slugs;            // 下架公告文章 slug 列表
    for (const char* host : HOSTS) {           // 逐镜像尝试
        std::string html = fetch_url(std::string(host) + "/help/section/announcements-delistings");   // 抓下架公告列表页
        if (html.size() < 5000) continue;      // 内容太短视为失败
        std::set<std::string> uniq;            // 去重集合
        find_delist_slugs(html, uniq);         // 提取 slug
        if (!uniq.empty()) { slugs.assign(uniq.begin(), uniq.end()); break; }   // 拿到即用
    }
    if (slugs.size() > 14) slugs.resize(14);   // 最多处理 14 篇
    tlog("下架公告候选文章: " + std::to_string(slugs.size()) + " 篇");   // 候选数日志
    for (auto& slug : slugs) {                 // 逐篇解析
        std::string s = slug;                  // 复制转小写
        for (auto& c : s) c = (char)tolower((unsigned char)c);
        if (s.find("perpetual") == std::string::npos && s.find("swap") == std::string::npos)
            continue;                          // 只看永续相关(现货下架不影响 SWAP 池)
        for (const char* host : HOSTS) {       // 逐镜像抓正文
            std::string art = fetch_url(std::string(host) + "/help/" + slug);   // 抓文章页
            if (art.size() < 3000) continue;   // 内容太短换下一镜像
            std::string txt = strip_tags(art); // 去标签
            std::set<std::string> bases;       // BASE 名集合
            find_bases(txt, bases);            // 提取 BASEUSDT 形式名
            for (auto& b : bases)              // 逐个核对
                if (meta.count(b + "-USDT-SWAP")) delisted.insert(b);   // 确为池内永续才记下线
            break;                             // 拿到正文即停
        }
    }
    {                                          // 汇总日志
        std::string dl;                        // 逗号串
        for (auto& d : delisted) { if (!dl.empty()) dl += ","; dl += d; }
        tlog("公告涉及下线合约基础名: " + (dl.empty() ? std::string("(无)") : dl));
    }

    // ④ 过滤(不新增合约: 只在现有池基础上剔除)
    std::vector<std::string> keep, drop;       // 保留/剔除列表
    for (auto& inst : poollist) {              // 逐个池内合约判定
        std::string base = inst.substr(0, inst.find('-'));   // 基础名(横杠前)
        std::string cat = "9", st = "offline"; // 元数据缺失兜底(视为不合格)
        auto mi = meta.find(inst);             // 查元数据
        if (mi != meta.end()) { cat = mi->second.first; st = mi->second.second; }   // 有则覆盖
        double p = 0.0;                        // 最新价
        auto pi = px.find(inst);               // 查价格
        if (pi != px.end()) p = pi->second;    // 有则取
        std::string why;                       // 剔除原因
        if (cat != "1") why = "股票/ETF类(cat=" + cat + ")";   // 剔除①: 非普通分类
        else if (st != "live") why = "非live状态(" + st + ")";   // 剔除②: 停牌/下线
        else if (!(p > 0.001 && p < 100)) why = "价格越界(" + jnum(p) + ")";   // 剔除③: 不在(0.001,100)
        else if (delisted.count(base)) why = "公告下线(" + base + ")";   // 剔除④: 公告下线
        if (!why.empty()) drop.push_back(inst + " [" + why + "]");   // 记剔除明细
        else keep.push_back(inst);             // 达标保留
    }

    // ⑤ 有变化才写库
    if (keep.size() != poollist.size()) {      // 池有增减
        db_ex("START TRANSACTION");            // 事务开始
        db_ex("DELETE FROM symbol_pool");      // 清空池表
        for (auto& inst : keep)                // 重写保留合约
            db_ex("INSERT INTO symbol_pool (inst_id, updated_at) VALUES ('" + inst + "', NOW())");
        db_ex("COMMIT");                       // 提交事务
        std::string d;                         // 剔除明细串
        for (auto& x : drop) { if (!d.empty()) d += " | "; d += x; }
        tlog("池已更新: " + std::to_string(poollist.size()) + " → " + std::to_string(keep.size()) +   // 更新日志
             " 剔除明细: " + d);
    } else {
        tlog("无变化: " + std::to_string(keep.size()) + " 个 (公告/分类/价格均达标)");   // 无变化日志
    }
    tlog("==== pool_sync 完成 | 池 " + std::to_string(keep.size()) + " 个 ====");   // 完成日志
}

// 每日 00:30 后执行一次(进程首次调用只登记日期, 避免启动当天重复跑)
static int g_lastPoolDay = -1;                 // 上次同步的日期编号
void pool_sync_if_due() {                      // 回填循环每轮调用
    time_t t = time(nullptr);                  // 当前时间
    struct tm lt; localtime_s(&lt, &t);        // 本地时间
    int day = lt.tm_year * 400 + lt.tm_yday;   // 日期唯一编号
    if (g_lastPoolDay < 0) { g_lastPoolDay = day; return; }   // 首次只登记不执行
    if (g_lastPoolDay == day) return;          // 今天已跑过
    if (lt.tm_hour == 0 && lt.tm_min < 30) return;   // 00:00~00:30 之间不跑
    g_lastPoolDay = day;                       // 标记今天已执行
    pool_sync_run();                           // 执行同步
}

// ---------------- 回填主循环 ----------------
void backfill_loop() {                         // 回填守护(独立线程, 3s节拍)
    long long fillLast = 0;                    // 上次成交回填时间
    int scanIdx = 0;                           // 洞扫描游标
    const char* TFS[] = { "1m", "3m", "5m", "15m", "1h", "4h" };   // 扫描周期集
    hole_state_load();                         // 启动恢复洞扫描状态

    while (g_run) {                            // 常驻循环
        trade_hb("backfill");                  // 刷新回填心跳
        try {                                  // 异常兜底: 单轮异常不杀线程
            long long now = (long long)time(nullptr);   // 当前时间
            // ① 每分钟: 成交 + 仓位历史回填
            if (now - fillLast >= 60) {        // 满 60 秒
                fillLast = now;                // 记录本轮时间
                int nf = backfill_fills();     // fills-history 回填(≤5页)
                int np = backfill_pos_history();   // pos-history 回填(单页)
                tlog("成交回填 " + std::to_string(nf) + " 笔 / 仓位回填 " + std::to_string(np) + " 笔");   // 轮报日志
            }
            // ② 洞扫描: 每轮 1 个合约 × 6 周期(状态落库, 全池约 40 分钟一轮)
            std::vector<std::string> insts = pool_all_insts();   // 全量合约(反推+权威池)
            if (!insts.empty()) {              // 非空才扫
                std::string inst = insts[(size_t)(scanIdx % (int)insts.size())];   // 轮转取一个合约
                scanIdx++;                     // 游标推进
                std::string day = today_str(); // 今日日期
                if (day != g_holeDay) { g_holeDay = day; g_holeBases.clear(); }   // 跨天重置已扫名单
                std::string base = inst.substr(0, inst.find('-'));   // 该合约基础名
                if (!g_holeBases.count(base)) {   // 今天还没扫过
                    for (const char* tf : TFS) {   // 6 个周期逐个扫描
                        if (!g_run) break;     // 退出标志
                        hole_scan(inst, tf);   // 单周期洞扫描+补洞
                    }
                    g_holeBases.insert(base);  // 标记已扫
                    hole_state_save();         // 状态落库(重启可续)
                }
                tlog("洞扫描游标=" + std::to_string(scanIdx) + "/" + std::to_string(insts.size()) +   // 轮报日志
                     "(K线追平已移交datahub)");
            }
            // ③ 合约池每日同步
            pool_sync_if_due();                // 每日 00:30 后触发一次
        } catch (...) {                        // 任意异常兜底
            tlog("回填主循环异常(C++ exception)");   // 记日志继续跑
        }
        Sleep(3000);                           // 3 秒节拍
    }
    tlog("回填主循环退出");                     // 退出日志
}
