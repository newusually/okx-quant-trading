// ==================================================================
// tradehub_main.cpp — C++ 交易中枢主壳
//   替代 engine.php(交易引擎) + backfill.php(回填) + pool_sync.php(池同步)
//   双线程: 引擎 10 秒节拍 | 回填 3 秒节拍(每分钟成交回填 + 每轮洞扫描)
//   自愈: 全局互斥 + 跨会话双开硬拦截 + guard.exe 守护 hb_tradehub.txt
// ==================================================================
#include "tradehub.h"
#include <tlhelp32.h>
#include <cstdio>
#include <cstring>
#include <ctime>
#include <thread>
#include <mutex>

volatile bool g_run = true;

static const char* TH_LOG = "E:\\datas\\log\\tradehub.txt";
static std::mutex g_tlogMtx;

void tlog(const std::string& s) {
    std::lock_guard<std::mutex> lk(g_tlogMtx);
    FILE* f = fopen(TH_LOG, "a");
    if (!f) return;
    time_t t = time(nullptr);
    struct tm lt; localtime_s(&lt, &t);
    char ts[32]; strftime(ts, sizeof(ts), "%Y-%m-%d %H:%M:%S", &lt);
    fprintf(f, "[%s] %s\n", ts, s.c_str());
    fclose(f);
}

// 引擎日志: 双写 logs 表(网页可见) + tradehub.txt
void eng_log(const char* level, const char* module, const std::string& msg) {
    std::string m = msg.size() > 2000 ? msg.substr(0, 2000) : msg;
    db_ex("INSERT INTO logs (log_time,level,module,message) VALUES (NOW(),'" + std::string(level) +
          "','" + std::string(module) + "','" + sqlesc(m) + "')");
    tlog(std::string("[") + level + "] " + module + " " + m);
}

// 心跳文件(覆盖写, 供 guard.exe 判"进程卡死")
void trade_hb(const char* name) {
    char p[160];
    snprintf(p, sizeof(p), "E:\\datas\\log\\hb_%s.txt", name);
    FILE* f = fopen(p, "w");
    if (!f) return;
    fprintf(f, "%lld loop pid=%lu\n", (long long)time(nullptr), GetCurrentProcessId());
    fclose(f);
}

static LONG WINAPI crash_handler(EXCEPTION_POINTERS*) {
    tlog("!! 未处理异常(崩溃), 进程将退出, guard 将在数秒内自动拉起 !!");
    return EXCEPTION_EXECUTE_HANDLER;
}

// 跨会话双开硬拦截(命名互斥体在 Session 0/1 之间可能落到不同命名空间)
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

// ============ 只读自检模式 (tradehub.exe selftest) ============
// 验证: DB连接 / 池读取 / K线喂库 / 黄金坑信号 / OKX私有&公有接口
// 全程不下单、不写任何交易表, 用于切换前安全验证
static int selftest() {
    tlog("==== SELFTEST 开始 (只读, 绝不下单) ====");
    if (!db_ex("SELECT 1")) { tlog("DB FAIL"); return 1; }
    tlog("DB OK (trading@127.0.0.1)");

    std::vector<std::string> pool = pool_cached();
    tlog("池内合约: " + std::to_string(pool.size()));
    if (pool.empty()) { tlog("池为空, 终止"); return 2; }

    int fed = 0;
    for (int i = 0; i < 3 && i < (int)pool.size(); i++) {
        std::vector<Bar> r5 = kl_engine_rows(pool[i], "5m");
        std::vector<Bar> r3 = kl_engine_rows(pool[i], "3m");
        sig_feed(pool[i], "5m", r5);
        sig_feed(pool[i], "3m", r3);
        double k5 = 0, k3 = 0;
        std::string i5, i3;
        bool f5 = sig_store_gold(pool[i], "5m", k5, i5);
        bool f3 = sig_store_gold(pool[i], "3m", k3, i3);
        tlog(pool[i] + " 5m=" + std::to_string(r5.size()) + "根 金▲=" + (f5 ? "Y" : "N") +
             " [KE=" + jnum(k5) + " " + i5 + "] | 3m=" + std::to_string(r3.size()) + "根 金▲=" + (f3 ? "Y" : "N") +
             " [KE=" + jnum(k3) + " " + i3 + "]");
        fed++;
    }
    tlog("喂库测试 " + std::to_string(fed) + " 个合约 | 内存库序列数=" + std::to_string(sig_store_stats()));

    std::vector<std::string> objs;
    bool pok = okx_positions(objs);
    int livePos = 0;
    for (auto& o : objs) if (j_num(o, "pos") > 0) livePos++;
    tlog(std::string("OKX 持仓接口: ") + (pok ? "OK" : "FAIL") +
         " | 持仓条数=" + std::to_string(objs.size()) + " 有效多头=" + std::to_string(livePos));

    tlog("ETH 最新价=" + jnum(okx_last_price("ETH-USDT-SWAP")) +
         " ctVal=" + jnum(okx_ctval("ETH-USDT-SWAP")));
    std::string fb;
    bool fok = okx_private("GET", "/api/v5/trade/fills-history?instType=SWAP&limit=1", "", fb);
    tlog(std::string("成交明细接口: ") + (fok ? "OK" : "FAIL") +
         " | code0=" + ((fb.find("\"code\":\"0\"") != std::string::npos) ? "Y" : "N"));
    tlog("==== SELFTEST 结束 ====");
    return 0;
}

int WINAPI WinMain(HINSTANCE, HINSTANCE, LPSTR, int) {
    if (strstr(GetCommandLineA(), "selftest")) return selftest();
    SetUnhandledExceptionFilter(crash_handler);
    HANDLE mx = CreateMutexA(nullptr, TRUE, "Global\\finally_tradehub");
    if (mx && GetLastError() == ERROR_ALREADY_EXISTS) return 0;
    if (another_instance_running()) return 0;      // 严禁双开: 双引擎会重复下单
    SetPriorityClass(GetCurrentProcess(), ABOVE_NORMAL_PRIORITY_CLASS);

    log_setfile("E:\\datas\\log\\tradehub.txt");
    tlog("==== tradehub C++ 交易中枢启动 pid=" + std::to_string(GetCurrentProcessId()) +
         " | 引擎10s节拍 + 回填3s节拍 ====");

    bool ok = false;
    for (int i = 0; i < 60; i++) { if (db_ex("SELECT 1")) { ok = true; break; } Sleep(5000); }
    if (!ok) { tlog("DB 连接失败60次, 退出"); return 1; }
    tlog("DB 已连接 trading@127.0.0.1 (libmysql)");

    eng_log("INFO", "engine",
            "===== C++ 交易中枢启动 | 买入=5m+3m金▲共振·1U·20X·加仓=跌时3m金▲+1U/3·止盈=tphub接管·永不止损 =====");

    trade_hb("tradehub");
    trade_hb("backfill");

    std::thread(backfill_loop).detach();           // 回填线程
    engine_loop();                                 // 主线程 = 引擎
    g_run = false;
    tlog("==== tradehub 退出 ====");
    return 0;
}
