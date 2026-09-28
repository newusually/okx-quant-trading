/* ==================================================================
 * hub_util.cpp — 日志 / JSON / 缓存 / K线读写 / 通用小工具 公共组件
 * (逐字迁移自 apihub.cpp, 逻辑零改动)
 *
 * 文件职责：
 *   全系统的基础设施层，不涉及任何交易逻辑，被 api_eps / tradehub /
 *   datahub 等所有组件共用（编译进 libhub.a）：
 *   ① 日志：logline/log_setfile —— 线程安全追加写，超 1MB 自动截断防盘满；
 *   ② 数值/转义：numok/jnum/jesc/sqlesc/atoll_s/atof_s —— OKX 数值均为
 *      字符串，入库与拼 SQL/JSON 前必须校验与转义（防注入）；
 *   ③ 表名生成：ktable —— 币对+周期 → 合法 MySQL 表名；
 *   ④ TTL 缓存：cache_get/cache_put + g_fundCache/g_lsCache/g_tickCache/
 *      g_kfreshCache —— 减轻 OKX 接口压力与 MySQL 读压力，g_kfreshCache
 *      兼作 K线按需刷新节流（防止同一 inst|tf 被高频重复回源）；
 *   ⑤ OKX K线响应解析：okx_parse_candles —— 轻量字符串扫描（不引 JSON 库）；
 *   ⑥ JSON 小工具：json_str/j_str/j_num/j_split_objects/json_valid；
 *   ⑦ K线读写：ensure_table/store/read_recent —— DB K线表列名为
 *      o/h/l/c/vol（非 open/close），candle_time 为主键；
 *   ⑧ 指标：macd_full —— MACD(12,26,9) 及两条 EMA 原始值输出；
 *   ⑨ 时间/参数：parse_dt/r4/P/get_inst/valid_inst/valid_bar/strat_of_remark。
 *
 * 数据流向：
 *   OKX /candles 响应 → okx_parse_candles → store(REPLACE 入 MySQL)
 *   → read_recent(时间正序) → macd_full → 各 ep_* 接口输出 JSON。
 *
 * 被谁调用：hub_okx.cpp(okx_cred 间接用 db_q)、api_eps.cpp(全部接口)、
 *   tradehub 引擎（信号计算前先 read_recent + macd_full）。
 * ================================================================== */
#include "hub.h"                              // 公共声明头
#include <tlhelp32.h>                         // Windows 进程/模块快照 API(本文件实际未直接用, 保留原状)
#include <cstdio>                             // fopen/fprintf/snprintf
#include <cstring>                            // strncmp/strcmp
#include <ctime>                              // time/localtime_s/strftime
#include <cmath>                              // floor(r4 用)
#include <cctype>                             // isdigit/isalnum
#include <array>                              // std::array(保留原包含)
#include <thread>                             // std::thread(保留原包含)
#include <mutex>                              // std::mutex(日志锁/缓存锁)

// ---------------- 日志 ----------------
static std::string g_logPath = "E:\\datas\\log\\apihub.log"; // 默认日志路径(各组件 exe 启动时可用 log_setfile 覆盖)
static std::mutex g_logMtx;                   // 日志互斥锁: 多线程并发写同一文件必须串行化
void log_setfile(const char* path) {
    std::lock_guard<std::mutex> lk(g_logMtx); // 持锁改路径: 防止与正在写的 logline 竞态
    if (path) g_logPath = path;               // 空指针则保持默认路径
}
void logline(const std::string& s) {
    std::lock_guard<std::mutex> lk(g_logMtx); // 持锁写日志: 保证多线程日志行不交错
    FILE* f = fopen(g_logPath.c_str(), "a");  // 追加模式打开(日志只增不覆盖)
    if (!f) return;                           // 打不开(目录不存在/权限)则静默放弃, 日志不能拖垮交易主流程
    time_t t = time(nullptr); struct tm lt; localtime_s(&lt, &t); // 取本地时间做日志时间戳
    char ts[32]; strftime(ts, sizeof(ts), "%Y-%m-%d %H:%M:%S", &lt); // 格式化 "年-月-日 时:分:秒"
    fprintf(f, "[%s] %s\n", ts, s.c_str());   // 写一行 "[时间] 内容"
    fclose(f);                                // 每条日志开关一次文件: 量不大, 但进程被杀也不丢日志
    HANDLE h = CreateFileA(g_logPath.c_str(), GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, nullptr,
                           OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, nullptr);  // 再开只读句柄查文件大小
    if (h != INVALID_HANDLE_VALUE) {
        LARGE_INTEGER sz;                     // 64 位文件大小
        if (GetFileSizeEx(h, &sz) && sz.QuadPart > 1048576) { // 超过 1MB: 日志截断, 防止长期运行撑爆磁盘
            f = fopen(g_logPath.c_str(), "w");// "w" 模式重开即清空
            if (f) { fprintf(f, "[%s] log truncated\n", ts); fclose(f); } // 留一行截断标记, 便于知道发生过
        }
        CloseHandle(h);
    }
}

// ---------------- 基础小工具 ----------------
bool numok(const std::string& s) {
    if (s.empty()) return false;              // 空串不算数字
    for (char c : s)
        if (!((c >= '0' && c <= '9') || c == '.' || c == '-' || c == '+' || c == 'e' || c == 'E')) // 只允许数字/小数点/正负号/科学计数
            return false;                     // 出现任何其他字符(如 OKX 异常返回 "NaN"/HTML)即非法
    return true;                              // 通过: 可安全 atof 入库
}
std::string jnum(double v) {
    if (v != v || v > 1e300 || v < -1e300) return "0"; // NaN(v!=v)或正负溢出回退 "0": 保证输出是合法 JSON 数字
    char b[40]; snprintf(b, 40, "%.10g", v); return b; // 10 位有效数字: 兼顾精度与可读(价格/数量足够)
}
std::string jesc(const std::string& s) {
    std::string o; o.reserve(s.size() + 8);   // 预留少量余量减少重分配
    for (char c : s) {
        switch (c) {
        case '"': o += "\\\""; break; case '\\': o += "\\\\"; break; // JSON 必转义: 双引号与反斜杠
        case '\n': o += "\\n"; break; case '\r': o += "\\r"; break; case '\t': o += "\\t"; break; // 常见控制字符
        default:
            if ((unsigned char)c < 32) { char b[8]; snprintf(b, 8, "\\u%04x", c); o += b; } // 其余控制字符转 \u00XX
            else o += c;                      // 普通字符(含 UTF-8 中文)原样保留
        }
    }
    return o;
}
std::string sqlesc(const std::string& s) {
    std::string o; o.reserve(s.size() + 8);   // SQL 转义输出串
    for (char c : s) {
        if (c == '\\' || c == '\'' || c == '"') o += '\\'; // 反斜杠/单双引号前加反斜杠(MySQL 语法), 防注入
        if ((unsigned char)c < 32) { char b[8]; snprintf(b, 8, "\\%03o", c); o += b; continue; } // 控制字符转八进制转义, 且跳过原字符
        o += c;                               // 普通字符原样保留
    }
    return o;
}
long long atoll_s(const std::string& s) { return s.empty() ? 0 : atoll(s.c_str()); } // 空串安全转 0(避免 atoll 未定义行为)
double atof_s(const std::string& s) { return s.empty() ? 0.0 : atof(s.c_str()); }    // 空串安全转 0.0

// ---------------- ktable ----------------
std::string ktable(const std::string& inst, const std::string& tf) {
    std::string s = "kline_";                 // K线表统一前缀
    for (char c : inst) {                     // 处理币对部分: "ETH-USDT-SWAP" → "eth_usdt_swap"
        if (c == '-') { s += '_'; continue; } // 连字符换下划线(MySQL 表名不允许 -)
        char l = (c >= 'A' && c <= 'Z') ? c + 32 : c;  // 大写转小写(表名统一小写)
        if ((l >= 'a' && l <= 'z') || (l >= '0' && l <= '9') || l == '_') s += l; // 白名单过滤, 其他字符丢弃(防注入)
    }
    s += '_';                                 // 币对与周期之间的分隔
    for (char c : tf) {                       // 处理周期部分: "1H" → "1h"
        char l = (c >= 'A' && c <= 'Z') ? c + 32 : c;  // 统一小写
        if ((l >= 'a' && l <= 'z') || (l >= '0' && l <= '9')) s += l; // 只留字母数字(周期形如 1m/5m/1H)
    }
    return s;                                 // 最终如 kline_eth_usdt_swap_1h
}

// ---------------- 微型 TTL 缓存 ----------------
static std::mutex g_caMtx;                    // 缓存互斥锁: 保护下面全部缓存 map(所有缓存共用一把锁, 实现极简)
std::map<std::string, std::pair<long long, std::string>> g_fundCache, g_lsCache, g_tickCache, g_kfreshCache;
                                              // value = (过期时刻tick, 内容); 四类缓存: 资金费率/杠杆持仓/ticker行情/K线刷新节流
bool cache_get(std::map<std::string, std::pair<long long, std::string>>& m,
               const std::string& k, std::string& out) {
    std::lock_guard<std::mutex> lk(g_caMtx);  // 持锁查缓存(map 非线程安全)
    auto it = m.find(k);
    if (it == m.end() || GetTickCount64() >= (unsigned long long)it->second.first) return false; // 不存在或已过期(以系统启动毫秒数为时钟)都算未命中
    out = it->second.second;                  // 未过期: 取出缓存内容
    return true;                              // 命中
}
void cache_put(std::map<std::string, std::pair<long long, std::string>>& m,
               const std::string& k, const std::string& v, int ttlMs) {
    std::lock_guard<std::mutex> lk(g_caMtx);  // 持锁写缓存
    m[k] = { (long long)GetTickCount64() + ttlMs, v }; // 记录 "当前tick + TTL" 作为过期时刻(put 后立即 get 会命中)
}

// ---------------- OKX candles 响应解析 ----------------
std::vector<std::vector<std::string>> okx_parse_candles(const std::string& body) {
    std::vector<std::vector<std::string>> rows; // 输出: 每行 [ts,o,h,l,c,vol,volCcy,volQuote,confirm](OKX 原始字符串)
    size_t p = body.find("\"code\":\"0\"");   // 先确认业务码为 0(OKX 约定 code=0 才算请求成功)
    if (p == std::string::npos) return rows;  // 非成功响应: 返回空集, 上层不重试解析
    p = body.find("\"data\":[");              // 定位 data 数组起点
    if (p == std::string::npos) return rows;  // 无 data: 同样返回空
    p += 8;                                   // 跳过 "data":[ 共 8 字符, 指向第一个元素
    while (true) {
        p = body.find('[', p);                // 找到下一个内层数组(OKX candles 的 data 是数组的数组)
        if (p == std::string::npos) break;    // 扫完了
        size_t e = body.find(']', p);         // 内层数组结束位置
        if (e == std::string::npos) break;    // 截断的响应: 放弃剩余
        std::string row = body.substr(p + 1, e - p - 1); // 内层括号内容: "ts","o","h",... (OKX 全部为字符串)
        std::vector<std::string> vals;        // 一根K线的字段列表
        size_t i = 0;
        while (i < row.size()) {              // 逐个提取双引号字符串字面量
            size_t a = row.find('"', i);      // 开引号
            if (a == std::string::npos) break;
            size_t b = row.find('"', a + 1);  // 闭引号
            if (b == std::string::npos) break;
            vals.push_back(row.substr(a + 1, b - a - 1)); // 引号中间即字段值
            i = b + 1;                        // 继续找下一个
        }
        if (vals.size() >= 9 && numok(vals[0]) && numok(vals[1]) && numok(vals[2]) &&
            numok(vals[3]) && numok(vals[4])) // 前五个字段(ts/o/h/l/c)必须齐全且为数字, 脏数据直接丢弃
            rows.push_back(std::move(vals));
        p = e + 1;                            // 从本内层数组结束处继续扫
    }
    return rows;
}

// 提取 JSON 第一个 "key":"value" 字符串值
std::string json_str(const std::string& body, const std::string& key) {
    std::string pat = "\"" + key + "\":\"";   // 目标模式: "key":" (只匹配字符串值)
    size_t p = body.find(pat);
    if (p == std::string::npos) return "";    // key 不存在
    p += pat.size();                          // 跳到值的起点
    size_t e = body.find('"', p);             // 值的结束引号(假定值内无转义引号——OKX 响应字段均满足)
    if (e == std::string::npos) return "";    // 异常截断
    return body.substr(p, e - p);             // 返回裸值
}

// ---------------- JSON 通用小工具 (tphub 同源) ----------------
std::string j_str(const std::string& obj, const std::string& key) {
    std::string pat = "\"" + key + "\":\"";   // 模式: "key":"
    size_t p = obj.find(pat);
    if (p == std::string::npos) return "";    // 未命中
    p += pat.size();
    std::string o;                            // 逐字符累积到下一个引号
    while (p < obj.size() && obj[p] != '"') { o += obj[p]; p++; } // 与 json_str 等价但手写循环(逐字迁移保留)
    return o;
}
double j_num(const std::string& obj, const std::string& key) {
    std::string pat = "\"" + key + "\":";     // 模式: "key": (数值或字符串数字都匹配)
    size_t p = obj.find(pat);
    if (p == std::string::npos) return 0;     // key 不存在按 0 处理
    p += pat.size();
    while (p < obj.size() && (obj[p] == ' ')) p++; // 跳过冒号后的空格
    if (p < obj.size() && obj[p] == '"') { // 字符串数字
        size_t e = obj.find('"', p + 1);      // OKX 价格/数量是字符串形式, 这里取出后再 atof
        return atof(obj.substr(p + 1, e - p - 1).c_str());
    }
    size_t e = obj.find_first_of(",}]", p);   // 纯数值: 到逗号/右括号结束
    return atof(obj.substr(p, e - p).c_str());
}
// 把 "data":[{...},{...}] 拆成对象数组(花括号计数)
std::vector<std::string> j_split_objects(const std::string& body) {
    std::vector<std::string> out;             // 输出: 每个元素是一个完整 {...} 对象字符串
    size_t p = body.find("\"data\":[");       // 定位 data 数组
    if (p == std::string::npos) return out;
    p += 8;                                   // 跳过 "data":[
    while (p < body.size()) {
        while (p < body.size() && (body[p] == ' ' || body[p] == ',')) p++; // 跳过元素间空白与逗号
        if (p >= body.size() || body[p] == ']') break; // 数组结束
        if (body[p] != '{') break;            // 非对象元素: 放弃(只处理对象数组场景)
        int depth = 0; size_t st = p;         // 花括号深度计数: 兼容对象内嵌套 {...}
        for (; p < body.size(); p++) {
            if (body[p] == '{') depth++;
            else if (body[p] == '}') { depth--; if (depth == 0) { p++; break; } } // 深度归零即本对象结束
        }
        out.push_back(body.substr(st, p - st)); // 截取完整对象(含外层花括号)
    }
    return out;
}

// ---------------- K线读写 ----------------
void ensure_table(const std::string& inst, const std::string& tf) {
    char sql[1024];                           // 建表 SQL 缓冲
    snprintf(sql, sizeof(sql),
        "CREATE TABLE IF NOT EXISTS %s (candle_time bigint NOT NULL,"
        " o double DEFAULT NULL, h double DEFAULT NULL, l double DEFAULT NULL,"
        " c double DEFAULT NULL, vol double DEFAULT NULL,"
        " vol_ccy double DEFAULT NULL, vol_quote double DEFAULT NULL,"
        " confirm tinyint DEFAULT 1, PRIMARY KEY (candle_time))"
        " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", ktable(inst, tf).c_str()); // 列名 o/h/l/c/vol(非 open/close); candle_time 主键防重复入库
    db_ex(sql);                               // 执行建表(IF NOT EXISTS, 幂等可反复调用)
}

int store(const std::string& inst, const std::string& tf,
          const std::vector<std::vector<std::string>>& bars) {
    if (bars.empty()) return 0;               // 空数据无需入库
    const std::string t = ktable(inst, tf);   // 目标表名
    int w = 0;                                // 成功写入行数计数
    std::string vals;                         // 累积多行 VALUES 子句(批量插入远快于逐行)
    auto flush = [&]() {                      // 把累积的 VALUES 作为一个事务一次性提交
        if (vals.empty()) return;             // 没有积压数据直接返回
        std::string sql = "REPLACE INTO " + t +
            " (candle_time,o,h,l,c,vol,vol_ccy,vol_quote,confirm) VALUES " + vals; // REPLACE: 主键冲突时覆盖(最新K线会反复刷新同一行)
        if (!db_ex("START TRANSACTION")) return;   // 开事务失败(连接异常)则放弃本批
        if (db_ex(sql)) { if (db_ex("COMMIT")) w += (int)std::count(vals.begin(), vals.end(), '('); } // 提交成功: 按 '(' 个数统计写入行数
        else db_ex("ROLLBACK");               // 写失败: 回滚保证不落半批脏数据
        vals.clear();                         // 清空积压
    };
    for (auto& x : bars) {
        if (!numok(x[0]) || !numok(x[1]) || !numok(x[2]) || !numok(x[3]) ||
            !numok(x[4]) || !numok(x[5]) || !numok(x[6]) || !numok(x[7])) continue; // 前 8 个数值字段任一非法则丢弃该行(脏数据不入库)
        if (!vals.empty()) vals += ",";       // 行间逗号分隔
        vals += "(" + x[0] + "," + x[1] + "," + x[2] + "," + x[3] + "," + x[4] +
                "," + x[5] + "," + x[6] + "," + x[7] + "," + x[8] + ")";  // 拼一行 VALUES(字段已是 OKX 原始字符串, 直接拼)
        if (std::count(vals.begin(), vals.end(), '(') >= 200) flush();   // 每 200 行 flush 一次: 控制 SQL 长度与事务粒度
    }
    flush();                                  // 收尾: 提交剩余不足 200 行的部分
    return w;                                 // 返回实际入库行数(调用方据此判断是否需要补拉)
}

std::vector<Bar> read_recent(const std::string& inst, const std::string& bar, int n) {
    std::vector<Bar> out;                     // 输出: 时间正序的 Bar 数组(指标计算要求正序)
    RowSet rs = db_q("SELECT candle_time,o,h,l,c,vol FROM " + ktable(inst, bar) +
                     " WHERE c>0 ORDER BY candle_time DESC LIMIT " + std::to_string(n)); // c>0 过滤未成形K线; DESC+LIMIT 取最近 n 根
    if (rs.ok) {
        out.reserve(rs.rows.size());          // 预分配避免反复扩容
        for (auto it = rs.rows.rbegin(); it != rs.rows.rend(); ++it) // 逆序遍历: DB 返回是倒序, 反转成时间正序
            out.push_back({ atoll_s((*it)[0]), atof_s((*it)[1]), atof_s((*it)[2]),
                            atof_s((*it)[3]), atof_s((*it)[4]), atof_s((*it)[5]) }); // 字符串转数值构造 Bar(tms,o,h,l,c,v)
    }
    return out;
}

// ---------------- MACD(12,26,9) 带 e12/e26 输出 ----------------
void macd_full(const std::vector<double>& cl, int fast, int slow, int sig,
               std::vector<double>& dif, std::vector<double>& dea,
               std::vector<double>& hist, std::vector<double>& e12,
               std::vector<double>& e26) {
    int n = (int)cl.size();                   // 收盘价序列长度
    dif.assign(n, 0); dea.assign(n, 0); hist.assign(n, 0); e12.assign(n, 0); e26.assign(n, 0); // 五条输出线全部预置 0(前 slow 根天然为 0)
    if (n <= 0) return;                       // 空序列直接返回
    double kf = 2.0 / (fast + 1), ks = 2.0 / (slow + 1), kg = 2.0 / (sig + 1); // 三条 EMA 的平滑系数 α=2/(N+1)
    double a = cl[0], b = cl[0], d0 = 0;      // a=快EMA, b=慢EMA, d0=信号线DEA(递推变量)
    for (int i = 0; i < n; i++) {
        if (i == 0) { a = cl[0]; b = cl[0]; dif[0] = dea[0] = hist[0] = 0; d0 = 0; } // 首根以收盘价作为 EMA 种子, 指标前段不参与信号
        else {
            a += kf * (cl[i] - a);            // 快 EMA 递推
            b += ks * (cl[i] - b);            // 慢 EMA 递推
            dif[i] = a - b;                   // DIF = 快EMA - 慢EMA(即 MACD 线)
            d0 += kg * (dif[i] - d0);         // DEA = DIF 的 sig 周期 EMA(信号线)
            dea[i] = d0;
            hist[i] = (dif[i] - d0) * 2.0;    // 柱 = (DIF-DEA)*2(国内软件惯例乘 2)
        }
        e12[i] = a; e26[i] = b;               // 额外输出两条原始 EMA(信号引擎要拿 e12 做辅助判断)
    }
}

// ---------------- JSON 合法性校验 ----------------
bool json_valid(const std::string& s) {
    size_t i = 0, n = s.size();               // i=当前扫描位置, n=总长(手写递归下降解析器, 校验而不求值)
    auto ws = [&]() { while (i < n && (s[i] == ' ' || s[i] == '\t' || s[i] == '\n' || s[i] == '\r')) i++; }; // 跳过四种 JSON 空白符
    auto str = [&]() -> bool {                // 解析字符串字面量(处理转义)
        if (i >= n || s[i] != '"') return false;
        i++;                                  // 吃掉开引号
        while (i < n) { char c = s[i++];
            if (c == '\\') { if (i >= n) return false; i++; } // 反斜杠后必须还有字符(转义对)
            else if (c == '"') return true; } // 闭引号: 字符串合法
        return false;                         // 到尾都没闭引号: 非法
    };
    auto num = [&]() -> bool {                // 解析数值(整数/小数/科学计数)
        size_t st = i;
        if (i < n && s[i] == '-') i++;        // 可选负号
        while (i < n && isdigit((unsigned char)s[i])) i++;  // 整数部分
        if (i < n && s[i] == '.') { i++; while (i < n && isdigit((unsigned char)s[i])) i++; } // 可选小数部分
        if (i < n && (s[i] == 'e' || s[i] == 'E')) { i++; if (i < n && (s[i] == '+' || s[i] == '-')) i++; while (i < n && isdigit((unsigned char)s[i])) i++; } // 可选指数部分
        return i > st;                        // 至少消费 1 字符才算数字
    };
    std::function<bool()> val, obj, arr;      // 前置声明三个递归产生式(值/对象/数组)
    obj = [&]() -> bool {                     // object: '{' (key:value ,)* '}'
        if (i >= n || s[i] != '{') return false;
        i++; ws();
        if (i < n && s[i] == '}') { i++; return true; } // 空对象 {}
        while (true) {
            ws(); if (!str()) return false;   // key 必须是字符串
            ws(); if (i >= n || s[i] != ':') return false;  // key 后必须有冒号
            i++; ws(); if (!val()) return false;            // 冒号后递归解析值
            ws();
            if (i < n && s[i] == ',') { i++; continue; }    // 逗号: 继续下一对
            if (i < n && s[i] == '}') { i++; return true; } // 右花括号: 对象结束
            return false;                     // 其他字符: 非法
        }
    };
    arr = [&]() -> bool {                     // array: '[' (value ,)* ']'
        if (i >= n || s[i] != '[') return false;
        i++; ws();
        if (i < n && s[i] == ']') { i++; return true; } // 空数组
        while (true) {
            ws(); if (!val()) return false;   // 递归解析元素
            ws();
            if (i < n && s[i] == ',') { i++; continue; }    // 逗号继续
            if (i < n && s[i] == ']') { i++; return true; } // 右中括号结束
            return false;
        }
    };
    val = [&]() -> bool {                     // value: 对象|数组|字符串|true|false|null|数值
        ws();
        if (i >= n) return false;
        char c = s[i];
        if (c == '{') return obj();           // 嵌套对象
        if (c == '[') return arr();           // 嵌套数组
        if (c == '"') return str();           // 字符串
        if (n - i >= 4 && !strncmp(s.c_str() + i, "true", 4))  { i += 4; return true; } // 字面量 true
        if (n - i >= 5 && !strncmp(s.c_str() + i, "false", 5)) { i += 5; return true; } // 字面量 false
        if (n - i >= 4 && !strncmp(s.c_str() + i, "null", 4))  { i += 4; return true; } // 字面量 null
        return num();                         // 兜底按数值解析
    };
    if (!val()) return false;                 // 从第一个字符开始解析顶层值
    ws();                                     // 值后允许尾部空白
    return i == n;                            // 必须恰好消费完整个串才算合法(拒绝 "1}x" 之类)
}

// ---------------- 时间/数值 ----------------
long long parse_dt(const std::string& s) {
    int Y, M, D, h, m, sec;                   // 年月日时分秒
    if (sscanf(s.c_str(), "%d-%d-%d %d:%d:%d", &Y, &M, &D, &h, &m, &sec) != 6) return 0; // 格式不符返回 0(调用方以 0 判无效)
    auto days = [](int y, int mm, int d) -> long long { // 民用历法算法(Howard Hinnant 版): 日期 → 自1970-01-01的天数
        y -= mm <= 2;                         // 1/2 月并入上一历法年(简化闰年处理)
        long long era = (y >= 0 ? y : y - 399) / 400;       // 400 年一个纪元
        int yoe = (int)(y - era * 400);       // 纪元内年偏移
        int doy = (153 * (mm + (mm > 2 ? -3 : 9)) + 2) / 5 + d - 1; // 月内天偏移(3月为历法年首月)
        int doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;    // 年内累计天数(含闰年修正)
        return era * 146097 + doe - 719468;   // 146097=400年总天数, 719468=1970-01-01 的偏移
    };
    return days(Y, M, D) * 86400LL + h * 3600 + m * 60 + sec; // 天数×86400 + 当日秒偏移 = Unix 秒
}
double r4(double v) {
    return v < 0 ? -floor(-v * 10000 + 0.5) / 10000 : floor(v * 10000 + 0.5) / 10000; // 四舍五入到 4 位小数(正负分支避免 floor 对负数的行为差异)
}

// ---------------- 参数/校验 ----------------
std::string P(const Params& q, const char* k, const char* dflt) {
    auto it = q.find(k);                      // 查询参数表
    return it == q.end() ? std::string(dflt) : it->second; // 缺省回退默认值
}
bool valid_inst(const std::string& s) {
    if (s.empty() || s.size() > 32) return false; // 长度白名单(1~32)
    for (char c : s)
        if (!((c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') || c == '-')) return false; // 只允许大写字母/数字/连字符(合约ID规范, 兼防 SQL 注入)
    return true;
}
std::string get_inst(const Params& q) {
    std::string i = P(q, "inst", "ETH-USDT-SWAP"); // 取 inst 参数, 默认 ETH 永续
    return valid_inst(i) ? i : "ETH-USDT-SWAP";    // 非法输入统一回退默认合约(所有接口的安全兜底)
}
bool valid_bar(const std::string& s) {
    if (s.size() < 2 || s.size() > 4) return false; // 周期长度: 数字(1~3位)+单位字母
    for (size_t i = 0; i + 1 < s.size(); i++) if (!isdigit((unsigned char)s[i])) return false; // 除末位外全是数字
    char c = s.back();
    return c == 'm' || c == 'H' || c == 'h';  // 末位单位只认 分钟m / 小时H|H小写(如 1m/5m/15m/1H/4h)
}
std::string strat_of_remark(const std::string& remark) {
    size_t p = remark.find("hybrid open ");   // 订单备注中混合策略开仓的固定前缀
    if (p == std::string::npos) return "";    // 非该类订单: 返回空
    p += 12;                                  // 跳过 "hybrid open "(12字符) 指向策略名
    size_t e = p;
    while (e < remark.size() && (isalnum((unsigned char)remark[e]) || remark[e] == '_')) e++; // 策略名由字母数字下划线组成
    return remark.substr(p, e - p);           // 截取策略名(用于订单归因到策略)
}
