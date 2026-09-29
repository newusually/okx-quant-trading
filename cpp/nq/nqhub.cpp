/* ==================================================================
 * nqhub.cpp — NQ 纳指100期货 数据守护 + 模拟交易引擎 (finally-main C++ 组件)
 *
 * 文件职责:
 *   1) 实时: 每 15 秒拉 Dukascopy jetta 1m(BID) → REPLACE INTO kline_nq_1m
 *   2) 回填: 启动/每轮检查 kline_nq_1m 最新时间, 落后超 3 分钟则按天
 *            (/candles/minute/{code}/BID/{Y}/{M}/{D}) 补齐缺口 (每轮限 60 天)
 *   3) 聚合: 1m 本地聚合近 3 天 → 3m/5m/15m/1h (REPLACE, 幂等)
 *   4) 模拟: NQ 期货模拟买卖 —— 自动策略(5m 三多头 + 1m 放量买 / +0.2% 止盈 /
 *            跌 0.1% 且 1m 放量反弹 加仓 / 不止损) + 手动单(nq_sim_orders 队列)
 *   全链路计算结果落 nq_sim_pos / nq_sim_trades, 前端只读展示 (铁律: PHP 只显示)
 *
 * 被谁启动: 计划任务 finally_nqhub (SYSTEM/AtStartup/隐藏), 同 bt_timer 模式
 * 日志: E:\datas\log\nqhub.txt
 * ================================================================== */
#include "../common/hub.h"
#include <thread>
#include <chrono>
#include <cmath>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <string>
#include <vector>
#include <map>
#include <algorithm>

static const char* DUK_HOST = "jetta.dukascopy.com";       // Dukascopy 行情主机
static const char* DUK_CODE = "USATECH.IDX-USD";           // 纳指100 指数代码(BID 价, 与旧 nq_live.php 同源)
static const char* LOGF     = "E:\\datas\\log\\nqhub.txt"; // 日志文件(与 bt_timer 同风格)
static const int   REALTIME_WINDOW_SEC = 1800;             // 实时窗口: 往前 30 分钟(覆盖断线重连后的小缺口)
static const int   BACKFILL_MAX_DAYS   = 60;               // 单轮回填天数上限(防一口气打爆远端)
static const int   AGG_DAYS            = 3;                // 每轮从 1m 重聚合的天数

/* ---------------- 模拟交易参数(引擎侧常量, 与前端图例同口径) ---------------- */
static const double SIM_DIRECT_USD = 2.0;     // 首次开仓名义本金(U)
static const double SIM_ADD_USD    = 1.0;     // 每次加仓名义本金(U)
static const double SIM_ADD_DIP    = 0.001;   // 加仓前提: 现价 <= 上次加仓价 ×(1-0.1%)
static const double SIM_TP_PCT     = 0.002;   // 止盈: 现价 >= 均价 ×(1+0.2%)
static const double SIM_POS_LEV    = 10.0;    // 模拟杠杆(10x, 与旧硬规则同档)
static const int    SIM_MAX_ADDS   = 10;      // 最多加仓次数
static const long long SIM_ADD_COOLDOWN = 1800;   // 加仓冷却(秒)

/* ---------------- 小工具 ---------------- */
static long long now_s() { return (long long)time(nullptr); }   // 当前秒级时间戳

// 从 JSON 对象串提取数组元素(仅支持纯数字数组, 手写轻量解析, Dukascopy 格式固定)
static void jarr(const std::string& s, const char* key, std::vector<double>& out) {
    out.clear();                                              // 清空输出
    std::string pat = std::string("\"") + key + "\":";         // 特征串 "key":
    size_t p = s.find(pat);                                   // 定位键
    if (p == std::string::npos) return;                        // 无此键
    size_t b = s.find('[', p); if (b == std::string::npos) return;   // 数组起点
    size_t e = s.find(']', b); if (e == std::string::npos) return;   // 数组终点
    const char* c = s.c_str() + b + 1;                         // 游标
    while (c < s.c_str() + e) {                                // 逐元素扫描
        char* nx = nullptr;                                    // 结束指针
        double v = strtod(c, &nx);                             // 读取数字
        if (nx == c) break;                                    // 无数字 → 结束
        out.push_back(v);                                      // 收录
        c = nx;                                                // 前进
        while (c < s.c_str() + e && (*c == ',' || *c == ' ')) c++;   // 跳过分隔符
    }
}

// Dukascopy 差分解码: 还原 [[ts_ms,o,h,l,c,vol]...] (与 web/nq_live.php decode() 同口径)
static bool decode_duk(const std::string& js, std::vector<std::array<double, 6>>& rows) {
    double mult = j_num(js, "multiplier"), shift = j_num(js, "shift"), ts0 = j_num(js, "timestamp");   // 乘数/步长/起始时间
    std::vector<double> tm, op, hi, lo, cl, vo;                // 差分数组
    jarr(js, "times", tm); jarr(js, "opens", op); jarr(js, "highs", hi);
    jarr(js, "lows", lo);  jarr(js, "closes", cl); jarr(js, "volumes", vo);
    if (mult <= 0 || shift <= 0 || tm.empty() || op.size() != tm.size()) return false;   // 结构异常
    double ou = round(j_num(js, "open") / mult), hu = round(j_num(js, "high") / mult);   // 开盘/最高差分基准
    double lu = round(j_num(js, "low") / mult),  cu = round(j_num(js, "close") / mult);  // 最低/收盘差分基准
    double ts = ts0;                                           // 时间累加器
    rows.clear();                                              // 清空输出
    for (size_t i = 0; i < tm.size(); i++) {                   // 逐根还原
        ts += tm[i] * shift;                                   // 时间: 基准 + 步长×偏移
        ou += op[i]; hu += hi[i]; lu += lo[i]; cu += cl[i];    // 价格: 基准 + 差分
        if (ou > 0 && cu > 0)                                  // 有效根
            rows.push_back({ts, ou * mult, hu * mult, lu * mult, cu * mult, i < vo.size() ? vo[i] : 0.0});   // 真实价 × 乘数
    }
    return !rows.empty();                                      // 至少一根
}

// 批量 REPLACE 入库 kline_nq_1m (每 100 根一条语句)
static int upsert_1m(const std::vector<std::array<double, 6>>& rows) {
    int n = 0;                                                 // 成功计数
    for (size_t i = 0; i < rows.size(); i += 100) {            // 分批
        std::string sql = "REPLACE INTO kline_nq_1m (candle_time,o,h,l,c,vol) VALUES ";   // 语句头
        char buf[160];                                         // 单行缓冲
        size_t end = std::min(rows.size(), i + 100);           // 本批结束
        for (size_t k = i; k < end; k++) {                     // 拼值
            snprintf(buf, sizeof(buf), "%s(%.0f,%.2f,%.2f,%.2f,%.2f,%.2f)",
                     k > i ? "," : "", rows[k][0], rows[k][1], rows[k][2], rows[k][3], rows[k][4], rows[k][5]);
            sql += buf;                                        // 追加
        }
        if (db_ex(sql)) n += (int)(end - i);                   // 执行
    }
    return n;                                                  // 返回写入根数
}

/* ---------------- ① 实时: jetta 1m 增量 ---------------- */
static int realtime_tick() {
    long long from = (now_s() - REALTIME_WINDOW_SEC) * 1000LL; // 起点: 30 分钟前(ms)
    char path[256];                                            // 请求路径
    snprintf(path, sizeof(path), "/v1/candles/minute/%s/BID?from=%lld", DUK_CODE, from);
    std::string js;                                            // 响应体
    if (!http_get(DUK_HOST, path, js)) return -1;              // 网络失败
    std::vector<std::array<double, 6>> rows;                   // 解码结果
    if (!decode_duk(js, rows)) return -1;                      // 解码失败
    int n = upsert_1m(rows);                                   // 入库
    return n;                                                  // 写入根数
}

/* ---------------- ② 回填: 按天补齐缺口 ---------------- */
static long long max_1m_ms() {
    bool ok = false;                                           // db_scalar 出参
    std::string s = db_scalar("SELECT IFNULL(MAX(candle_time),0) FROM kline_nq_1m", ok);
    return s.empty() ? 0 : (long long)atof(s.c_str());         // 最新时间(ms, double 化读)
}

// UTC 日期 → 天数序号(简化: 用 timegm 口径自算, 避免时区函数)
static void ymd_from_days(long long days, int& y, int& m, int& d) {
    time_t t = (time_t)(days * 86400LL);                       // 天数 → 秒
    struct tm g;                                               // UTC 结构
    gmtime_s(&g, &t);                                          // 转换
    y = g.tm_year + 1900; m = g.tm_mon + 1; d = g.tm_mday;     // 拆出年月日
}
static long long days_of(long long ms) { return ms / 86400000LL; }   // 毫秒 → UTC 天数序号

static int backfill_gap() {
    long long mx = max_1m_ms();                                // 库里最新
    if (mx <= 0) mx = (now_s() - 400LL * 86400LL) * 1000LL;    // 空库: 从一年前起(由下方限流兜底)
    long long gapMin = (now_s() * 1000LL - mx) / 60000LL;      // 落后分钟数
    if (gapMin < 5) return 0;                                  // 只差几根 → 实时已覆盖
    long long d0 = days_of(mx) ;                               // 缺口起始日(UTC)
    long long dN = days_of(now_s() * 1000LL);                  // 今天(UTC)
    if (dN - d0 > 400) d0 = dN - 400;                          // 上限保护
    int done = 0;                                              // 已拉取天数
    for (long long d = d0; d <= dN && done < BACKFILL_MAX_DAYS; d++) {   // 逐日
        int y, m, dd; ymd_from_days(d, y, m, dd);              // 该日 UTC 年月日
        char path[256];                                        // 请求路径(M/D 不补零)
        snprintf(path, sizeof(path), "/v1/candles/minute/%s/BID/%d/%d/%d", DUK_CODE, y, m, dd);
        std::string js;                                        // 响应体
        if (!http_get(DUK_HOST, path, js)) { std::this_thread::sleep_for(std::chrono::milliseconds(300)); continue; }   // 非交易日(404)→跳过
        std::vector<std::array<double, 6>> rows;               // 解码结果
        if (decode_duk(js, rows)) { upsert_1m(rows); done++; }  // 入库
        std::this_thread::sleep_for(std::chrono::milliseconds(1200));  // 限速 1.2s/请求
    }
    if (done) logline("回填 " + std::to_string(done) + " 天缺口 (起 " + std::to_string(d0) + " 止 " + std::to_string(dN) + ")");   // 日志
    return done;                                               // 返回天数
}

/* ---------------- ③ 聚合: 1m → 3m/5m/15m/1h ---------------- */
static void aggregate_tf(const char* tf, int mins) {
    long long since = (now_s() - (long long)AGG_DAYS * 86400LL) * 1000LL;   // 窗口起点
    RowSet rs = db_q("SELECT candle_time,o,h,l,c,vol FROM kline_nq_1m WHERE candle_time>=" +
                     std::to_string(since) + " ORDER BY candle_time ASC");   // 拉 1m
    if (!rs.ok || rs.rows.empty()) return;                     // 无数据
    long long bucket = (long long)mins * 60000LL;              // 桶大小(ms)
    struct B { double o, h, l, c, v; long long bt; };          // 桶结构
    std::map<long long, B> bk;                                 // 桶序(=桶起点) → 数据
    for (auto& r : rs.rows) {                                  // 逐根归并
        if (r.size() < 6) continue;                            // 列数保护
        long long t = (long long)atof(r[0].c_str());           // 时间
        double o = atof(r[1].c_str()), h = atof(r[2].c_str()); // 开/高
        double l = atof(r[3].c_str()), c = atof(r[4].c_str()), v = atof(r[5].c_str());   // 低/收/量
        if (c <= 0) continue;                                  // 脏数据
        long long b = (t / bucket) * bucket;                   // 所属桶
        auto it = bk.find(b);                                  // 找桶
        if (it == bk.end()) bk[b] = B{o, h, l, c, v, t};       // 新桶
        else {                                                 // 已有桶
            B& x = it->second;                                 // 引用
            x.h = std::max(x.h, h); x.l = std::min(x.l, l);    // 高取最大/低取最小
            if (t > x.bt) { x.bt = t; x.c = c; }               // 更晚的根: 覆盖收盘
            x.v += v;                                          // 量累加
        }
    }
    int n = 0;                                                 // 写入计数
    for (auto& kv : bk) {                                      // 逐桶 REPLACE
        char sql[512];                                         // 语句缓冲
        snprintf(sql, sizeof(sql),
                 "REPLACE INTO kline_nq_%s (candle_time,o,h,l,c,vol) VALUES (%lld,%.2f,%.2f,%.2f,%.2f,%.2f)",
                 tf, (long long)kv.first, kv.second.o, kv.second.h, kv.second.l, kv.second.c, kv.second.v);
        if (db_ex(sql)) n++;                                   // 执行
    }
    (void)n;                                                   // 计数仅调试用
}

/* ---------------- ③b 指标: MACD(12,26,9) → nq_ind_{tf} ---------------- */
//  旧 nq_bg.py 的指标计算职责由本组件接管(铁律: 后台计算全 C++),
//  每轮随聚合一起重算近 2 天并 REPLACE, 前 2000 根做 EMA 预热。
static void ind_tf(const char* tf) {
    RowSet rs = db_q(std::string("SELECT candle_time,c FROM kline_nq_") + tf + " ORDER BY candle_time DESC LIMIT 2000");   // 新→旧
    if (!rs.ok || rs.rows.size() < 40) return;                 // 数据不足(EMA 未收敛)
    std::vector<std::pair<long long, double>> v;               // 升序 (时间, 收盘)
    for (auto it = rs.rows.rbegin(); it != rs.rows.rend(); ++it)   // 反转
        v.push_back({(long long)atof((*it)[0].c_str()), atof((*it)[1].c_str())});
    const double k12 = 2.0 / 13.0, k26 = 2.0 / 27.0, k9 = 2.0 / 10.0;   // EMA 平滑系数
    double e12 = v[0].second, e26 = v[0].second, dea = 0;      // 初值(首根收盘)
    long long keep = (now_s() - 2LL * 86400LL) * 1000LL;       // 只写近 2 天(旧行保持不动)
    std::string head = std::string("REPLACE INTO nq_ind_") + tf + " (candle_time,dif,dea,macd,e12,e26) VALUES ";   // 语句头
    std::string sql = head;                                    // 当前批
    bool first = true;                                         // 批内首元素
    for (auto& p : v) {                                        // 逐根递推
        e12 = p.second * k12 + e12 * (1 - k12);                // EMA12
        e26 = p.second * k26 + e26 * (1 - k26);                // EMA26
        double dif = e12 - e26;                                // DIF
        dea = dif * k9 + dea * (1 - k9);                       // DEA
        double hist = (dif - dea) * 2.0;                       // MACD 柱
        if (p.first < keep) continue;                          // 窗口外不写
        char b[192];                                           // 单行缓冲
        snprintf(b, sizeof(b), "%s(%lld,%.4f,%.4f,%.4f,%.4f,%.4f)", first ? "" : ",", p.first, dif, dea, hist, e12, e26);
        sql += b; first = false;                               // 追加
        if (sql.size() > 4000) { db_ex(sql); sql = head; first = true; }   // 分批落库(防超长)
    }
    if (!first) db_ex(sql);                                    // 尾批
}

/* ---------------- ④ 模拟交易引擎 ---------------- */
struct Pos { double qty = 0, avg = 0, lastAdd = 0; int adds = 0; long long lastAddTs = 0; };   // 持仓状态

static void sim_ddl() {                                        // 建表(幂等)
    db_ex("CREATE TABLE IF NOT EXISTS nq_sim_orders (id INT AUTO_INCREMENT PRIMARY KEY, ts DATETIME DEFAULT CURRENT_TIMESTAMP,"
          " action VARCHAR(8) NOT NULL, note VARCHAR(120) DEFAULT '', status VARCHAR(8) DEFAULT 'PENDING',"
          " filled_px DOUBLE DEFAULT 0, filled_ts DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");   // 手动单队列
    db_ex("CREATE TABLE IF NOT EXISTS nq_sim_trades (id INT AUTO_INCREMENT PRIMARY KEY, open_time DATETIME, close_time DATETIME NULL,"
          " kind VARCHAR(8) NOT NULL, side VARCHAR(4) DEFAULT 'long', qty DOUBLE, open_px DOUBLE, close_px DOUBLE NULL,"
          " profit DOUBLE DEFAULT 0, reason VARCHAR(120) DEFAULT '', status VARCHAR(8) DEFAULT 'OPEN') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");   // 成交台账
    db_ex("CREATE TABLE IF NOT EXISTS nq_sim_pos (id INT PRIMARY KEY, qty DOUBLE DEFAULT 0, avg_px DOUBLE DEFAULT 0,"
          " last_add_px DOUBLE DEFAULT 0, adds INT DEFAULT 0, last_add_ts BIGINT DEFAULT 0, updated DATETIME DEFAULT CURRENT_TIMESTAMP)"
          " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");       // 单行持仓态
    db_ex("INSERT IGNORE INTO nq_sim_pos (id,qty,avg_px,last_add_px,adds,last_add_ts) VALUES (1,0,0,0,0,0)");   // 初始化单行
}

static Pos pos_load() {                                        // 读持仓
    Pos p;                                                     // 返回体
    RowSet rs = db_q("SELECT qty,avg_px,last_add_px,adds,last_add_ts FROM nq_sim_pos WHERE id=1");   // 单行
    if (rs.ok && !rs.rows.empty() && rs.rows[0].size() >= 5) {  // 有行
        p.qty = atof(rs.rows[0][0].c_str()); p.avg = atof(rs.rows[0][1].c_str());   // 数量/均价
        p.lastAdd = atof(rs.rows[0][2].c_str()); p.adds = atoi(rs.rows[0][3].c_str());   // 上次加仓价/次数
        p.lastAddTs = (long long)atof(rs.rows[0][4].c_str());   // 上次加仓时间
    }
    return p;                                                  // 返回
}
static void pos_save(const Pos& p) {                           // 写持仓
    char sql[512];                                             // 语句缓冲
    snprintf(sql, sizeof(sql), "UPDATE nq_sim_pos SET qty=%.8f,avg_px=%.4f,last_add_px=%.4f,adds=%d,last_add_ts=%lld,updated=NOW() WHERE id=1",
             p.qty, p.avg, p.lastAdd, p.adds, p.lastAddTs);
    db_ex(sql);                                                // 执行
}
static void trade_open(double px, double usd, const std::string& reason, const std::string& kind, Pos& p) {   // 开/加仓记账
    double q = usd * SIM_POS_LEV / px;                         // 名义本金×杠杆 → 指数点数数量
    double nq = p.qty + q;                                     // 新总量
    p.avg = nq > 0 ? (p.avg * p.qty + px * q) / nq : px;       // 摊薄均价
    p.qty = nq; p.lastAdd = px; p.adds++; p.lastAddTs = now_s();   // 更新状态
    char sql[512];                                             // 语句缓冲
    snprintf(sql, sizeof(sql), "INSERT INTO nq_sim_trades (open_time,kind,side,qty,open_px,reason,status) VALUES (NOW(),'%s','long',%.8f,%.4f,'%s','OPEN')",
             kind.c_str(), q, px, reason.c_str());
    db_ex(sql); pos_save(p);                                   // 落库
}
static double trade_close(double px, const std::string& reason, Pos& p) {   // 全平记账
    if (p.qty <= 0) return 0;                                  // 无仓
    double profit = (px - p.avg) * p.qty;                      // 盈亏(点数×数量)
    char sql[640];                                             // 语句缓冲
    snprintf(sql, sizeof(sql),
             "INSERT INTO nq_sim_trades (open_time,close_time,kind,side,qty,open_px,close_px,profit,reason,status)"
             " VALUES (NOW(),NOW(),'close','long',%.8f,%.4f,%.4f,%.2f,'%s','CLOSED')", p.qty, p.avg, px, profit, reason.c_str());
    db_ex(sql);                                                // 台账
    db_ex("UPDATE nq_sim_trades SET status='CLOSED' WHERE status='OPEN'");   // 历史开仓行收口
    p.qty = 0; p.avg = 0; p.lastAdd = 0; p.adds = 0; p.lastAddTs = 0;        // 复位
    pos_save(p);                                               // 落库
    return profit;                                             // 返回盈亏
}

// 最近已收 1m 的 (涨幅%, 量, 20根均量, 收盘价, ATR14, 末根振幅)
//   NQ 指数成交量粒度粗(0.00~0.14, 2×均量仅 0.25% 概率) → 放量条件改为
//   "振幅 ≥ 1.2×ATR14 或 量 ≥ 2×均量" 双通道(2026-09-29 参数试算后定档)
static bool last_1m(double& pct, double& vol, double& avg20, double& closePx, double& atr14, double& rng) {
    RowSet rs = db_q("SELECT o,h,l,c,vol FROM kline_nq_1m ORDER BY candle_time DESC LIMIT 21");   // 新→旧
    if (!rs.ok || rs.rows.size() < 15) return false;           // 不足(需 14 根算 ATR)
    double o0 = atof(rs.rows[0][0].c_str());                   // 最新开盘(列序: o,h,l,c,vol)
    double h0 = atof(rs.rows[0][1].c_str());                   // 最新最高
    double l0 = atof(rs.rows[0][2].c_str());                   // 最新最低
    double c0 = atof(rs.rows[0][3].c_str());                   // 最新收盘
    if (o0 <= 0 || c0 <= 0) return false;                      // 脏
    pct = (c0 - o0) / o0 * 100.0; closePx = c0;                // 涨幅
    rng = h0 - l0;                                             // 末根振幅(点数)
    vol = atof(rs.rows[0][4].c_str());                         // 最新量
    double s = 0; int n = 0;                                   // 均量累计
    for (size_t i = 1; i < rs.rows.size(); i++) { s += atof(rs.rows[i][4].c_str()); n++; }   // 前 20 根
    avg20 = n ? s / n : 0;                                     // 均量
    double tr = 0; int m = 0;                                  // ATR 累计(前 14 根的高低价差)
    for (size_t i = 1; i <= 14 && i < rs.rows.size(); i++) {
        double h = atof(rs.rows[i][1].c_str()), l = atof(rs.rows[i][2].c_str());
        if (h > 0 && l > 0 && h >= l) { tr += h - l; m++; }
    }
    atr14 = m ? tr / m : 0;                                    // ATR14(点数)
    return avg20 > 0 && atr14 > 0;                             // 有效
}

// 5m EMA 三线 (7/25/99) 是否多头排列
static bool ema3_bull5m() {
    RowSet rs = db_q("SELECT c FROM kline_nq_5m ORDER BY candle_time DESC LIMIT 200");   // 新→旧
    if (!rs.ok || rs.rows.size() < 120) return false;          // 预热不足
    std::vector<double> cl;                                    // 升序收盘
    for (auto it = rs.rows.rbegin(); it != rs.rows.rend(); ++it) cl.push_back(atof((*it)[0].c_str()));   // 反转
    auto ema = [&](int n) {                                    // EMA 计算(最后一个值)
        double k = 2.0 / (n + 1), e = cl[0];                   // 平滑系数/初值
        for (size_t i = 1; i < cl.size(); i++) e = cl[i] * k + e * (1 - k);   // 迭代
        return e;                                              // 返回最新 EMA
    };
    double e7 = ema(7), e25 = ema(25), e99 = ema(99);          // 三线
    return e7 > e25 && e25 > e99 && cl.back() > e7;            // 多头排列且价在最短均线上
}

static void sim_tick() {                                       // 每轮调用
    Pos p = pos_load();                                        // 读持仓
    double pct = 0, vol = 0, avg20 = 0, px = 0, atr14 = 0, rng = 0;   // 1m 口径(先零初始化, 防未初始化告警)
    if (!last_1m(pct, vol, avg20, px, atr14, rng)) return;     // 数据不足
    /* 手动单先执行(优先级: 用户手点的先成交) */
    RowSet ors = db_q("SELECT id,action FROM nq_sim_orders WHERE status='PENDING' ORDER BY id ASC LIMIT 20");   // 待处理单
    if (ors.ok) for (auto& o : ors.rows) {                     // 逐单
        if (o.size() < 2) continue;                            // 列保护
        long long id = (long long)atof(o[0].c_str());          // 单号
        std::string act = o[1];                                // 动作
        if (act == "buy") {                                    // 手动买
            trade_open(px, SIM_DIRECT_USD, "手动买入", p.qty > 0 ? "add" : "open", p);
        } else if (act == "add") {                             // 手动加仓
            trade_open(px, SIM_ADD_USD, "手动加仓", "add", p);
        } else if (act == "sell" || act == "close") {          // 手动平多
            trade_close(px, "手动平仓", p);
        }
        char uq[256];                                          // 标记完成
        snprintf(uq, sizeof(uq), "UPDATE nq_sim_orders SET status='FILLED',filled_px=%.4f,filled_ts=NOW() WHERE id=%lld", px, id);
        db_ex(uq);                                             // 落库
    }
    /* 自动策略 */
    bool burst = (atr14 > 0 && rng >= 1.2 * atr14) || (avg20 > 0 && vol >= 2.0 * avg20);   // 动能确认: 振幅放大 或 放量
    if (p.qty <= 0) {                                          // 空仓 → 找买点
        if (ema3_bull5m() && pct > 0.02 && burst)               // 5m 三多头 + 1m 红盘 + 动能放大
            trade_open(px, SIM_DIRECT_USD, "自动:5m三多头+1m动能", "open", p);
    } else {                                                   // 有仓 → 止盈/加仓
        if (px >= p.avg * (1.0 + SIM_TP_PCT)) {                // 止盈
            trade_close(px, "自动:+0.2%止盈", p);
        } else if (p.adds < SIM_MAX_ADDS && px <= p.lastAdd * (1.0 - SIM_ADD_DIP) &&   // 跌够 + 动能放大 + 冷却
                   pct > 0.01 && burst && (now_s() - p.lastAddTs) >= SIM_ADD_COOLDOWN) {
            trade_open(px, SIM_ADD_USD, "自动:跌0.1%+1m动能", "add", p);
        }
    }
}

/* ---------------- main ---------------- */
int main() {
    log_setfile(LOGF);                                         // 日志落文件
    logline("nqhub 启动: NQ 纳指数据守护 + 模拟交易引擎");     // 启动横幅
    sim_ddl();                                                 // 建模拟表
    int bf = backfill_gap();                                   // 启动先补一次缺口(实时只覆盖 30 分钟)
    logline("启动回填完成: " + std::to_string(bf) + " 天");     // 日志
    int aggTick = 0;                                           // 聚合节流计数
    for (;;) {                                                 // 常驻循环
        int n = realtime_tick();                               // ① 实时 1m 增量入库
        aggTick++;                                             // 轮次计数
        if (n < 0) {                                           // 实时失败(网络抖动/远端维护)
            logline("实时拉取失败 → 立即尝试回填修复");        // 日志
            backfill_gap();                                    // ② 回填兜底
        } else if (aggTick % 40 == 0) {                        // 每 40 轮(≈10 分钟)一次缺口体检
            backfill_gap();                                    // 兜住长时间断线(限 60 天/轮)
        }
        if (aggTick % 4 == 1) {                                // 每 4 轮(≈1 分钟)聚合一次
            aggregate_tf("3m", 3); aggregate_tf("5m", 5);      // 3m/5m
            aggregate_tf("15m", 15); aggregate_tf("1h", 60);   // 15m/1h
            ind_tf("1m"); ind_tf("3m");                        // 指标: 1m/3m MACD
            ind_tf("5m"); ind_tf("15m"); ind_tf("1h");         // 指标: 5m/15m/1h MACD
        }
        sim_tick();                                            // ④ 模拟交易(手动单+自动策略)
        std::this_thread::sleep_for(std::chrono::seconds(15)); // 15 秒节奏
    }
    return 0;                                                  // 不可达
}
