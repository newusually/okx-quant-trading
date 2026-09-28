// ==================================================================
// datahub_main.cpp — OKX 数据中枢主程序薄壳 (拆分自 datahub.cpp, 业务逻辑零改动)
// 单一 HUB 循环(15秒节拍): 交易池优先 / ETH实时+指标重算 / 全市场477×6 / 追平
// DB/工具复用 common 公共组件; 抓取组件见 datahub_fetch.cpp
// ==================================================================
#include "datahub.h"
#include <tlhelp32.h>
#include <cstdio>
#include <cstring>
#include <ctime>
#include <map>
#include <thread>

// ---------------- T1: ETH 实时 (替代 okx_realtime.php) ----------------
struct TFdef { const char* bar; int ivSec; int win; };
static const TFdef RT_BARS[] = {
    {"1m", 60, 70}, {"3m", 180, 40}, {"5m", 300, 30}, {"15m", 900, 20}, {"1H", 3600, 12},
};
static const char* RT_INST = "ETH-USDT-SWAP";
static std::map<std::string, long long> g_lastMax;
static std::mutex g_rtMtx;

// 增量重算 okx_ind_{tf} 尾部MACD(12,26,60): 取尾部2400根算EMA链, 只REPLACE尾部1300根
static int rebuild_ind(const std::string& tf, const std::string& tbl) {
    RowSet rs = db_q("SELECT candle_time,c FROM " + tbl + " WHERE c>0 ORDER BY candle_time DESC LIMIT 2400");
    if (!rs.ok || rs.rows.size() < 60) return 0;
    std::vector<std::pair<long long, double>> src;
    for (auto it = rs.rows.rbegin(); it != rs.rows.rend(); ++it)
        src.push_back({atoll((*it)[0].c_str()), atof((*it)[1].c_str())});
    long long writeFrom = src[src.size() > 1300 ? src.size() - 1300 : 0].first;
    double e12 = 0, e26 = 0, dea = 0;
    std::string vals;
    int w = 0, n = 0;
    auto flush = [&]() {
        if (vals.empty()) return;
        std::string sql = "REPLACE INTO okx_ind_" + tf +
            " (candle_time,dif,dea,macd,e12,e26,score) VALUES " + vals;
        if (!db_ex("START TRANSACTION")) return;
        if (db_ex(sql)) db_ex("COMMIT"); else db_ex("ROLLBACK");
        w += (int)std::count(vals.begin(), vals.end(), '(');
        vals.clear();
    };
    for (size_t i = 0; i < src.size(); i++) {
        double c = src[i].second;
        e12 = (i == 0) ? c : c * 2.0 / 13.0 + e12 * 11.0 / 13.0;
        e26 = (i == 0) ? c : c * 2.0 / 27.0 + e26 * 25.0 / 27.0;
        double dif = e12 - e26;
        dea = (i == 0) ? dif : dif * 2.0 / 61.0 + dea * 59.0 / 61.0;
        n++;
        if (src[i].first >= writeFrom) {
            char buf[256];
            snprintf(buf, sizeof(buf), "(%lld,%.12g,%.12g,%.12g,%.12g,%.12g,NULL)",
                     src[i].first, dif, dea, 2 * (dif - dea), e12, e26);
            if (!vals.empty()) vals += ",";
            vals += buf;
            if (n % 200 == 0) flush();
        }
    }
    flush();
    return w;
}

// ---------------- ETH live: 每15秒全周期 live bar + MACD指标重算 ----------------
static void eth_live() {
    long long nowS = time(nullptr);
    for (auto& d : RT_BARS) {
        std::string tf = d.bar, tfl;
        for (char c : tf) tfl += (c >= 'A' && c <= 'Z') ? c + 32 : c;
        std::string tbl = ktable(RT_INST, tf);
        std::string itbl = "okx_ind_" + tfl;
        long long after = nowS * 1000 + (long long)d.ivSec * 1000;
        std::map<long long, std::vector<std::string>> seen;
        while (true) {
            char path[256];
            snprintf(path, sizeof(path),
                "/api/v5/market/candles?instId=%s&bar=%s&limit=100&after=%lld",
                RT_INST, tf.c_str(), after);
            std::string body;
            // datahub 专属限频HTTP在 fetch 组件内(匀速排队+429退避), ETH live 走同一队列
            if (!dl_http_get(path, body)) break;
            auto data = okx_parse_candles(body);
            if (data.empty()) break;
            for (auto& x : data) seen[atoll(x[0].c_str())] = x;
            after = atoll(data[data.size() - 1][0].c_str());
            if ((int)seen.size() >= d.win || (int)data.size() < 100) break;
        }
        int nw = 0;
        long long mx = 0;
        for (auto& kv : seen) {
            auto& x = kv.second;
            char sql[512];
            snprintf(sql, sizeof(sql),
                "REPLACE INTO %s (candle_time,o,h,l,c,vol,vol_ccy,vol_quote,confirm)"
                " VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                tbl.c_str(), x[0].c_str(), x[1].c_str(), x[2].c_str(), x[3].c_str(),
                x[4].c_str(), x[5].c_str(), x[6].c_str(), x[7].c_str(), x[8].c_str());
            if (numok(x[0]) && numok(x[4]) && db_ex(sql)) nw++;
            if (kv.first > mx) mx = kv.first;
        }
        std::lock_guard<std::mutex> lk(g_rtMtx);
        if (!g_lastMax.count(tfl)) {
            bool ok;
            std::string v = db_scalar("SELECT MAX(candle_time) FROM " + itbl, ok);
            g_lastMax[tfl] = ok ? atoll(v.c_str()) : 0;
        }
        if (mx > 0 && (nw > 0 || mx > g_lastMax[tfl])) {
            int w = rebuild_ind(tfl, tbl);
            g_lastMax[tfl] = mx;
            if (w) {
                char lg[128];
                snprintf(lg, sizeof(lg), "%s K线+%d 指标重算%d行", tfl.c_str(), nw, w);
                logline(lg);
            }
        }
        prog_set(RT_INST, tf, mx);
        Sleep(60);
    }
}

// ---------------- HUB 主循环 (T1+T2 合并, 15秒节拍, 4线程并发抓取) ----------------
struct Task { std::string inst; std::string tf; int limit; bool catchup; };
static std::vector<Task> g_tasks;
static size_t g_taskIdx = 0;
static std::mutex g_taskMtx;
static std::atomic<int> g_budget(0);      // 新鲜任务预算
static std::atomic<int> g_cbudget(0);     // 追平任务独立预算(防被新鲜任务饿死)
static std::atomic<int> g_catchups(0);
static std::atomic<int> g_fok(0), g_ffail(0), g_cok(0), g_cfail(0);   // 诊断计数

static void task_worker() {
    while (true) {
        Task t;
        {
            std::lock_guard<std::mutex> lk(g_taskMtx);
            if (g_taskIdx >= g_tasks.size()) return;
            t = g_tasks[g_taskIdx++];
        }
        if (t.catchup) {
            if (g_cbudget.fetch_sub(1) <= 0) continue;      // 追平独立预算
            if (g_catchups.fetch_add(1) >= 80) continue;
            long long r = fill_forward(t.inst, t.tf);
            if (r > 0) g_cok.fetch_add(1); else g_cfail.fetch_add(1);
        } else {
            if (g_budget.fetch_sub(1) <= 0) continue;       // 新鲜任务预算
            int w = tail_fetch(t.inst, t.tf, t.limit);
            if (w > 0) g_fok.fetch_add(1);
            else { g_ffail.fetch_add(1); logline("新鲜取数失败 " + t.inst + " " + t.tf + " w=" + std::to_string(w)); }
        }
        Sleep(30); // 礼让
    }
}

static void hub_loop() {
    logline("datahub HUB 启动 (合并T1+T2, 15s节拍, 4线程并发, 全市场477×6实时)");
    std::vector<std::string> pool = pool_insts(), all = all_insts(), hot = hot_insts();
    {
        char lg[160];
        snprintf(lg, sizeof(lg), "交易池=%d 全市场=%d 热合约=%d", (int)pool.size(), (int)all.size(), (int)hot.size());
        logline(lg);
    }
    std::map<std::string, bool> inPool;
    for (auto& s : pool) inPool[s] = true;
    int tick = 0;
    int lastMin = -1;
    while (true) {
        long long t0 = GetTickCount64();
        try {
            if (++tick % 40 == 0 || pool.empty() || all.empty()) { // 约每10分钟刷新
                pool = pool_insts();
                all = all_insts();
                hot = hot_insts();
                inPool.clear();
                for (auto& s : pool) inPool[s] = true;
                char lg[160];
                snprintf(lg, sizeof(lg), "刷新列表: 池=%d 全市场=%d 热=%d", (int)pool.size(), (int)all.size(), (int)hot.size());
                logline(lg);
            }
            long long nowS = time(nullptr);
            g_budget = 130;              // 新鲜任务预算
            g_cbudget = 90;              // 追平独立预算
            g_catchups = 0;
            int minuteNow = (int)(nowS / 60);
            bool newMin = (minuteNow != lastMin);
            if (newMin) lastMin = minuteNow;
            g_tasks.clear();
            g_taskIdx = 0;
            std::vector<Task> catchupQ;   // 积压追平

            // ===== 任务队列: 按价值分级 + 级别内部公平 =====
            //   A0 热合约(近48h有成交, ≤30个) × 1m/3m/5m/15m  ← 用户正在看图/正在交易的, 必须最实时
            //   A1 池内 × 3m/5m/15m                            ← 交易只看这三个周期(金▲共振), 最高优先
            //   B  池外 × 5m/15m (按tick切片1/4)               ← 切片轮转, 保证每个合约都被轮到
            //   B2 池内 × 1h/4h                                ← 大周期低优先
            // 【池内1m 已移除】1m只做热合约
            auto push_task = [&](const std::string& inst, const char* tf) {
                int bs = bar_sec(tf);
                long long closed = (nowS / bs) * bs - bs;
                long long mx = prog_get(inst, tf);
                if (mx >= closed * 1000) return;
                bool cu = mx < (closed - 3LL * bs) * 1000;
                if (cu) catchupQ.push_back({inst, tf, 3, true});
                else g_tasks.push_back({inst, tf, 3, false});
            };
            static const char* HOT_TFS[] = {"1m", "3m", "5m", "15m"};
            for (auto& inst : hot)
                for (auto* tf : HOT_TFS) push_task(inst, tf);
            static const char* POOL_TFS[] = {"3m", "5m", "15m"};
            for (auto& inst : pool)
                for (auto* tf : POOL_TFS) push_task(inst, tf);
            {
                size_t slot = (size_t)(tick % 4);       // 1/4 合约/tick → 60秒全覆盖(5m/15m足够)
                for (size_t ii = 0; ii < all.size(); ii++) {
                    const std::string& inst = all[ii];
                    if (inPool[inst]) continue;
                    if (ii % 4 != slot) continue;
                    push_task(inst, "5m");
                    push_task(inst, "15m");
                }
            }
            for (auto& inst : pool) {
                push_task(inst, "1h");
                push_task(inst, "4h");
            }
            // D. 新鲜/追平 2:1 交错(池外追平欠账清不掉的历史修复)
            {
                std::vector<Task> fresh;
                fresh.swap(g_tasks);
                size_t fi = 0, ci = 0;
                while (fi < fresh.size() || ci < catchupQ.size()) {
                    for (int k = 0; k < 2 && fi < fresh.size(); k++) g_tasks.push_back(fresh[fi++]);
                    if (ci < catchupQ.size()) g_tasks.push_back(catchupQ[ci++]);
                }
                if (tick % 4 == 0) {
                    char lg[128];
                    snprintf(lg, sizeof(lg), "队列: 新鲜%d 追平%d 合计%d", (int)fresh.size(), (int)catchupQ.size(), (int)g_tasks.size());
                    logline(lg);
                }
            }

            // 4 线程并发消化任务队列
            {
                std::vector<std::thread> ws;
                for (int i = 0; i < 4; i++) ws.emplace_back(task_worker);
                for (auto& w : ws) w.join();
            }
            // ETH live 全周期 + 指标重算 (每15秒, 5请求, 主线程)
            eth_live();
            // 每轮写心跳文件(供 guard.exe 探活, 日志不保证每轮都有输出)
            {
                FILE* hf = fopen("E:\\datas\\log\\hb_datahub.txt", "w");
                if (hf) { fprintf(hf, "%lld\n", (long long)time(nullptr)); fclose(hf); }
            }
            // 自适应节流: 本轮429多 → 放慢; 长时间无429 → 逐步加速
            int n429 = g_429count.exchange(0);
            if (n429 > 0) {
                int nm = g_minMs.load() + 15 * (n429 > 3 ? 3 : 1);
                if (nm > 300) nm = 300;
                g_minMs = nm;
            } else if (g_minMs.load() > 120) {
                int nm = g_minMs.load() - 5;
                g_minMs = nm < 120 ? 120 : nm;
            }
            if (tick % 4 == 0) {
                char lg[256];
                snprintf(lg, sizeof(lg),
                         "tick#%d 任务%d 新鲜(成功%d/失败%d) 追平(成功%d/失败%d) 节流%dms(%.1f/s) 429=%d 耗时%lldms",
                         tick, (int)g_tasks.size(), g_fok.exchange(0), g_ffail.exchange(0),
                         g_cok.exchange(0), g_cfail.exchange(0),
                         g_minMs.load(), 1000.0 / (g_minMs.load() > 0 ? g_minMs.load() : 1), n429,
                         GetTickCount64() - t0);
                logline(lg);
            }
        } catch (...) {
            logline("hub_loop 异常(已捕获, 继续)");
        }
        long long el = GetTickCount64() - t0;
        Sleep(el < 15000 ? (15000 - (int)el) : 100);
    }
}

// ---------------- main ----------------
static LONG WINAPI crash_handler(EXCEPTION_POINTERS*) {
    logline("!! 未处理异常(崩溃), 进程将退出, 等待计划任务1分钟内自动拉起 !!");
    return EXCEPTION_EXECUTE_HANDLER;
}

// 单实例硬检查(跨会话双开防护, 按 exe 全路径比对)
static bool another_instance_running() {
    char self[MAX_PATH * 2] = { 0 };
    GetModuleFileNameA(NULL, self, sizeof(self) - 1);
    std::string me = self;
    for (auto& c : me) c = (char)tolower((unsigned char)c);
    HANDLE snap = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);
    if (snap == INVALID_HANDLE_VALUE) return false;
    PROCESSENTRY32W pe; pe.dwSize = sizeof(pe);
    DWORD selfPid = GetCurrentProcessId();
    bool dup = false;
    if (Process32FirstW(snap, &pe)) {
        do {
            if (pe.th32ProcessID == selfPid) continue;
            HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pe.th32ProcessID);
            if (!hp) continue;
            char buf[MAX_PATH * 2] = { 0 }; DWORD len = sizeof(buf) - 1;
            if (QueryFullProcessImageNameA(hp, 0, buf, &len)) {
                std::string other = buf;
                for (auto& c : other) c = (char)tolower((unsigned char)c);
                if (other == me) dup = true;
            }
            CloseHandle(hp);
            if (dup) break;
        } while (Process32NextW(snap, &pe));
    }
    CloseHandle(snap);
    return dup;
}

int WINAPI WinMain(HINSTANCE, HINSTANCE, LPSTR, int) {
    SetUnhandledExceptionFilter(crash_handler);
    HANDLE mx = CreateMutexA(nullptr, TRUE, "Global\\finally_datahub");
    if (mx && GetLastError() == ERROR_ALREADY_EXISTS) return 0;
    if (another_instance_running()) return 0;   // 跨会话双开硬拦截 // 单实例
    SetPriorityClass(GetCurrentProcess(), BELOW_NORMAL_PRIORITY_CLASS);
    log_setfile("E:\\datas\\log\\datahub.log");
    logline("==== datahub C++ 数据中枢 v2 启动 pid=" + std::to_string(GetCurrentProcessId()) + " ====");
    // 等待数据库就绪(开机mysql可能未起)
    bool ok = false;
    for (int i = 0; i < 60; i++) { if (db_ex("SELECT 1")) { ok = true; break; } Sleep(5000); }
    if (!ok) { logline("DB 连接失败60次, 退出"); return 1; }
    logline("DB 已连接 trading@127.0.0.1 (libmysql)");
    std::thread t1(hub_loop);
    t1.join();
    return 0;
}
