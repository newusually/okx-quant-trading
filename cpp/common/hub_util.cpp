// ==================================================================
// hub_util.cpp — 日志 / JSON / 缓存 / K线读写 / 通用小工具 公共组件
// (逐字迁移自 apihub.cpp, 逻辑零改动)
// ==================================================================
#include "hub.h"
#include <tlhelp32.h>
#include <cstdio>
#include <cstring>
#include <ctime>
#include <cmath>
#include <cctype>
#include <array>
#include <thread>
#include <mutex>

// ---------------- 日志 ----------------
static std::string g_logPath = "E:\\datas\\log\\apihub.log";
static std::mutex g_logMtx;
void log_setfile(const char* path) {
    std::lock_guard<std::mutex> lk(g_logMtx);
    if (path) g_logPath = path;
}
void logline(const std::string& s) {
    std::lock_guard<std::mutex> lk(g_logMtx);
    FILE* f = fopen(g_logPath.c_str(), "a");
    if (!f) return;
    time_t t = time(nullptr); struct tm lt; localtime_s(&lt, &t);
    char ts[32]; strftime(ts, sizeof(ts), "%Y-%m-%d %H:%M:%S", &lt);
    fprintf(f, "[%s] %s\n", ts, s.c_str());
    fclose(f);
    HANDLE h = CreateFileA(g_logPath.c_str(), GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, nullptr,
                           OPEN_EXISTING, FILE_ATTRIBUTE_NORMAL, nullptr);
    if (h != INVALID_HANDLE_VALUE) {
        LARGE_INTEGER sz;
        if (GetFileSizeEx(h, &sz) && sz.QuadPart > 1048576) {
            f = fopen(g_logPath.c_str(), "w");
            if (f) { fprintf(f, "[%s] log truncated\n", ts); fclose(f); }
        }
        CloseHandle(h);
    }
}

// ---------------- 基础小工具 ----------------
bool numok(const std::string& s) {
    if (s.empty()) return false;
    for (char c : s)
        if (!((c >= '0' && c <= '9') || c == '.' || c == '-' || c == '+' || c == 'e' || c == 'E'))
            return false;
    return true;
}
std::string jnum(double v) {
    if (v != v || v > 1e300 || v < -1e300) return "0";
    char b[40]; snprintf(b, 40, "%.10g", v); return b;
}
std::string jesc(const std::string& s) {
    std::string o; o.reserve(s.size() + 8);
    for (char c : s) {
        switch (c) {
        case '"': o += "\\\""; break; case '\\': o += "\\\\"; break;
        case '\n': o += "\\n"; break; case '\r': o += "\\r"; break; case '\t': o += "\\t"; break;
        default:
            if ((unsigned char)c < 32) { char b[8]; snprintf(b, 8, "\\u%04x", c); o += b; }
            else o += c;
        }
    }
    return o;
}
std::string sqlesc(const std::string& s) {
    std::string o; o.reserve(s.size() + 8);
    for (char c : s) {
        if (c == '\\' || c == '\'' || c == '"') o += '\\';
        if ((unsigned char)c < 32) { char b[8]; snprintf(b, 8, "\\%03o", c); o += b; continue; }
        o += c;
    }
    return o;
}
long long atoll_s(const std::string& s) { return s.empty() ? 0 : atoll(s.c_str()); }
double atof_s(const std::string& s) { return s.empty() ? 0.0 : atof(s.c_str()); }

// ---------------- ktable ----------------
std::string ktable(const std::string& inst, const std::string& tf) {
    std::string s = "kline_";
    for (char c : inst) {
        if (c == '-') { s += '_'; continue; }
        char l = (c >= 'A' && c <= 'Z') ? c + 32 : c;
        if ((l >= 'a' && l <= 'z') || (l >= '0' && l <= '9') || l == '_') s += l;
    }
    s += '_';
    for (char c : tf) {
        char l = (c >= 'A' && c <= 'Z') ? c + 32 : c;
        if ((l >= 'a' && l <= 'z') || (l >= '0' && l <= '9')) s += l;
    }
    return s;
}

// ---------------- 微型 TTL 缓存 ----------------
static std::mutex g_caMtx;
std::map<std::string, std::pair<long long, std::string>> g_fundCache, g_lsCache, g_tickCache, g_kfreshCache;

bool cache_get(std::map<std::string, std::pair<long long, std::string>>& m,
               const std::string& k, std::string& out) {
    std::lock_guard<std::mutex> lk(g_caMtx);
    auto it = m.find(k);
    if (it == m.end() || GetTickCount64() >= (unsigned long long)it->second.first) return false;
    out = it->second.second;
    return true;
}
void cache_put(std::map<std::string, std::pair<long long, std::string>>& m,
               const std::string& k, const std::string& v, int ttlMs) {
    std::lock_guard<std::mutex> lk(g_caMtx);
    m[k] = { (long long)GetTickCount64() + ttlMs, v };
}

// ---------------- OKX candles 响应解析 ----------------
std::vector<std::vector<std::string>> okx_parse_candles(const std::string& body) {
    std::vector<std::vector<std::string>> rows;
    size_t p = body.find("\"code\":\"0\"");
    if (p == std::string::npos) return rows;
    p = body.find("\"data\":[");
    if (p == std::string::npos) return rows;
    p += 8;
    while (true) {
        p = body.find('[', p);
        if (p == std::string::npos) break;
        size_t e = body.find(']', p);
        if (e == std::string::npos) break;
        std::string row = body.substr(p + 1, e - p - 1);
        std::vector<std::string> vals;
        size_t i = 0;
        while (i < row.size()) {
            size_t a = row.find('"', i);
            if (a == std::string::npos) break;
            size_t b = row.find('"', a + 1);
            if (b == std::string::npos) break;
            vals.push_back(row.substr(a + 1, b - a - 1));
            i = b + 1;
        }
        if (vals.size() >= 9 && numok(vals[0]) && numok(vals[1]) && numok(vals[2]) &&
            numok(vals[3]) && numok(vals[4]))
            rows.push_back(std::move(vals));
        p = e + 1;
    }
    return rows;
}

// 提取 JSON 第一个 "key":"value" 字符串值
std::string json_str(const std::string& body, const std::string& key) {
    std::string pat = "\"" + key + "\":\"";
    size_t p = body.find(pat);
    if (p == std::string::npos) return "";
    p += pat.size();
    size_t e = body.find('"', p);
    if (e == std::string::npos) return "";
    return body.substr(p, e - p);
}

// ---------------- JSON 通用小工具 (tphub 同源) ----------------
std::string j_str(const std::string& obj, const std::string& key) {
    std::string pat = "\"" + key + "\":\"";
    size_t p = obj.find(pat);
    if (p == std::string::npos) return "";
    p += pat.size();
    std::string o;
    while (p < obj.size() && obj[p] != '"') { o += obj[p]; p++; }
    return o;
}
double j_num(const std::string& obj, const std::string& key) {
    std::string pat = "\"" + key + "\":";
    size_t p = obj.find(pat);
    if (p == std::string::npos) return 0;
    p += pat.size();
    while (p < obj.size() && (obj[p] == ' ')) p++;
    if (p < obj.size() && obj[p] == '"') { // 字符串数字
        size_t e = obj.find('"', p + 1);
        return atof(obj.substr(p + 1, e - p - 1).c_str());
    }
    size_t e = obj.find_first_of(",}]", p);
    return atof(obj.substr(p, e - p).c_str());
}
// 把 "data":[{...},{...}] 拆成对象数组(花括号计数)
std::vector<std::string> j_split_objects(const std::string& body) {
    std::vector<std::string> out;
    size_t p = body.find("\"data\":[");
    if (p == std::string::npos) return out;
    p += 8;
    while (p < body.size()) {
        while (p < body.size() && (body[p] == ' ' || body[p] == ',')) p++;
        if (p >= body.size() || body[p] == ']') break;
        if (body[p] != '{') break;
        int depth = 0; size_t st = p;
        for (; p < body.size(); p++) {
            if (body[p] == '{') depth++;
            else if (body[p] == '}') { depth--; if (depth == 0) { p++; break; } }
        }
        out.push_back(body.substr(st, p - st));
    }
    return out;
}

// ---------------- K线读写 ----------------
void ensure_table(const std::string& inst, const std::string& tf) {
    char sql[1024];
    snprintf(sql, sizeof(sql),
        "CREATE TABLE IF NOT EXISTS %s (candle_time bigint NOT NULL,"
        " o double DEFAULT NULL, h double DEFAULT NULL, l double DEFAULT NULL,"
        " c double DEFAULT NULL, vol double DEFAULT NULL,"
        " vol_ccy double DEFAULT NULL, vol_quote double DEFAULT NULL,"
        " confirm tinyint DEFAULT 1, PRIMARY KEY (candle_time))"
        " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", ktable(inst, tf).c_str());
    db_ex(sql);
}

int store(const std::string& inst, const std::string& tf,
          const std::vector<std::vector<std::string>>& bars) {
    if (bars.empty()) return 0;
    const std::string t = ktable(inst, tf);
    int w = 0;
    std::string vals;
    auto flush = [&]() {
        if (vals.empty()) return;
        std::string sql = "REPLACE INTO " + t +
            " (candle_time,o,h,l,c,vol,vol_ccy,vol_quote,confirm) VALUES " + vals;
        if (!db_ex("START TRANSACTION")) return;
        if (db_ex(sql)) { if (db_ex("COMMIT")) w += (int)std::count(vals.begin(), vals.end(), '('); }
        else db_ex("ROLLBACK");
        vals.clear();
    };
    for (auto& x : bars) {
        if (!numok(x[0]) || !numok(x[1]) || !numok(x[2]) || !numok(x[3]) ||
            !numok(x[4]) || !numok(x[5]) || !numok(x[6]) || !numok(x[7])) continue;
        if (!vals.empty()) vals += ",";
        vals += "(" + x[0] + "," + x[1] + "," + x[2] + "," + x[3] + "," + x[4] +
                "," + x[5] + "," + x[6] + "," + x[7] + "," + x[8] + ")";
        if (std::count(vals.begin(), vals.end(), '(') >= 200) flush();
    }
    flush();
    return w;
}

std::vector<Bar> read_recent(const std::string& inst, const std::string& bar, int n) {
    std::vector<Bar> out;
    RowSet rs = db_q("SELECT candle_time,o,h,l,c,vol FROM " + ktable(inst, bar) +
                     " WHERE c>0 ORDER BY candle_time DESC LIMIT " + std::to_string(n));
    if (rs.ok) {
        out.reserve(rs.rows.size());
        for (auto it = rs.rows.rbegin(); it != rs.rows.rend(); ++it)
            out.push_back({ atoll_s((*it)[0]), atof_s((*it)[1]), atof_s((*it)[2]),
                            atof_s((*it)[3]), atof_s((*it)[4]), atof_s((*it)[5]) });
    }
    return out;
}

// ---------------- MACD(12,26,9) 带 e12/e26 输出 ----------------
void macd_full(const std::vector<double>& cl, int fast, int slow, int sig,
               std::vector<double>& dif, std::vector<double>& dea,
               std::vector<double>& hist, std::vector<double>& e12,
               std::vector<double>& e26) {
    int n = (int)cl.size();
    dif.assign(n, 0); dea.assign(n, 0); hist.assign(n, 0); e12.assign(n, 0); e26.assign(n, 0);
    if (n <= 0) return;
    double kf = 2.0 / (fast + 1), ks = 2.0 / (slow + 1), kg = 2.0 / (sig + 1);
    double a = cl[0], b = cl[0], d0 = 0;
    for (int i = 0; i < n; i++) {
        if (i == 0) { a = cl[0]; b = cl[0]; dif[0] = dea[0] = hist[0] = 0; d0 = 0; }
        else {
            a += kf * (cl[i] - a);
            b += ks * (cl[i] - b);
            dif[i] = a - b;
            d0 += kg * (dif[i] - d0);
            dea[i] = d0;
            hist[i] = (dif[i] - d0) * 2.0;
        }
        e12[i] = a; e26[i] = b;
    }
}

// ---------------- JSON 合法性校验 ----------------
bool json_valid(const std::string& s) {
    size_t i = 0, n = s.size();
    auto ws = [&]() { while (i < n && (s[i] == ' ' || s[i] == '\t' || s[i] == '\n' || s[i] == '\r')) i++; };
    auto str = [&]() -> bool {
        if (i >= n || s[i] != '"') return false;
        i++;
        while (i < n) { char c = s[i++];
            if (c == '\\') { if (i >= n) return false; i++; }
            else if (c == '"') return true; }
        return false;
    };
    auto num = [&]() -> bool {
        size_t st = i;
        if (i < n && s[i] == '-') i++;
        while (i < n && isdigit((unsigned char)s[i])) i++;
        if (i < n && s[i] == '.') { i++; while (i < n && isdigit((unsigned char)s[i])) i++; }
        if (i < n && (s[i] == 'e' || s[i] == 'E')) { i++; if (i < n && (s[i] == '+' || s[i] == '-')) i++; while (i < n && isdigit((unsigned char)s[i])) i++; }
        return i > st;
    };
    std::function<bool()> val, obj, arr;
    obj = [&]() -> bool {
        if (i >= n || s[i] != '{') return false;
        i++; ws();
        if (i < n && s[i] == '}') { i++; return true; }
        while (true) {
            ws(); if (!str()) return false;
            ws(); if (i >= n || s[i] != ':') return false;
            i++; ws(); if (!val()) return false;
            ws();
            if (i < n && s[i] == ',') { i++; continue; }
            if (i < n && s[i] == '}') { i++; return true; }
            return false;
        }
    };
    arr = [&]() -> bool {
        if (i >= n || s[i] != '[') return false;
        i++; ws();
        if (i < n && s[i] == ']') { i++; return true; }
        while (true) {
            ws(); if (!val()) return false;
            ws();
            if (i < n && s[i] == ',') { i++; continue; }
            if (i < n && s[i] == ']') { i++; return true; }
            return false;
        }
    };
    val = [&]() -> bool {
        ws();
        if (i >= n) return false;
        char c = s[i];
        if (c == '{') return obj();
        if (c == '[') return arr();
        if (c == '"') return str();
        if (n - i >= 4 && !strncmp(s.c_str() + i, "true", 4))  { i += 4; return true; }
        if (n - i >= 5 && !strncmp(s.c_str() + i, "false", 5)) { i += 5; return true; }
        if (n - i >= 4 && !strncmp(s.c_str() + i, "null", 4))  { i += 4; return true; }
        return num();
    };
    if (!val()) return false;
    ws();
    return i == n;
}

// ---------------- 时间/数值 ----------------
long long parse_dt(const std::string& s) {
    int Y, M, D, h, m, sec;
    if (sscanf(s.c_str(), "%d-%d-%d %d:%d:%d", &Y, &M, &D, &h, &m, &sec) != 6) return 0;
    auto days = [](int y, int mm, int d) -> long long {
        y -= mm <= 2;
        long long era = (y >= 0 ? y : y - 399) / 400;
        int yoe = (int)(y - era * 400);
        int doy = (153 * (mm + (mm > 2 ? -3 : 9)) + 2) / 5 + d - 1;
        int doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
        return era * 146097 + doe - 719468;
    };
    return days(Y, M, D) * 86400LL + h * 3600 + m * 60 + sec;
}
double r4(double v) {
    return v < 0 ? -floor(-v * 10000 + 0.5) / 10000 : floor(v * 10000 + 0.5) / 10000;
}

// ---------------- 参数/校验 ----------------
std::string P(const Params& q, const char* k, const char* dflt) {
    auto it = q.find(k);
    return it == q.end() ? std::string(dflt) : it->second;
}
bool valid_inst(const std::string& s) {
    if (s.empty() || s.size() > 32) return false;
    for (char c : s)
        if (!((c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') || c == '-')) return false;
    return true;
}
std::string get_inst(const Params& q) {
    std::string i = P(q, "inst", "ETH-USDT-SWAP");
    return valid_inst(i) ? i : "ETH-USDT-SWAP";
}
bool valid_bar(const std::string& s) {
    if (s.size() < 2 || s.size() > 4) return false;
    for (size_t i = 0; i + 1 < s.size(); i++) if (!isdigit((unsigned char)s[i])) return false;
    char c = s.back();
    return c == 'm' || c == 'H' || c == 'h';
}
std::string strat_of_remark(const std::string& remark) {
    size_t p = remark.find("hybrid open ");
    if (p == std::string::npos) return "";
    p += 12;
    size_t e = p;
    while (e < remark.size() && (isalnum((unsigned char)remark[e]) || remark[e] == '_')) e++;
    return remark.substr(p, e - p);
}
