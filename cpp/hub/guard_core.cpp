// ==================================================================
// guard_core.cpp — 守护中枢核心组件 (拆分自 guard.cpp, 业务逻辑零改动)
// 托管目标 / 三级探活 / 内存看门狗 / 双开清理 / 配置自愈 / 状态上报 / 主循环
// 服务壳(SCM/安装/CLI)见 guard_main.cpp
// ==================================================================
#define _WIN32_WINNT 0x0601
#include "guard.h"
#include <winsock2.h>
#include <ws2tcpip.h>
#include <winhttp.h>
#include <tlhelp32.h>
#include <psapi.h>
#include <taskschd.h>
#include <comdef.h>
#include <cstdio>
#include <ctime>

volatile bool         g_stop = false;
SERVICE_STATUS_HANDLE g_ssh = NULL;
SERVICE_STATUS        g_ss;
CRITICAL_SECTION      g_logCs;
DWORD                 g_startTick = 0;

// --------------------------------------------------------------------------
// 日志
// --------------------------------------------------------------------------
void logline(const std::string& s) {
    EnterCriticalSection(&g_logCs);
    FILE* f = fopen(LOGF, "a");
    if (f) {
        fseek(f, 0, SEEK_END);
        if (ftell(f) > ROTATE_BYTES) {
            fclose(f); f = NULL;
            DeleteFileA(LOGF ".1");
            MoveFileA(LOGF, LOGF ".1");
            f = fopen(LOGF, "a");
        }
    }
    if (f) {
        time_t t = time(nullptr); struct tm lt; localtime_s(&lt, &t);
        char ts[32]; strftime(ts, sizeof(ts), "%Y-%m-%d %H:%M:%S", &lt);
        fprintf(f, "[%s] %s\n", ts, s.c_str());
        fclose(f);
    }
    LeaveCriticalSection(&g_logCs);
}

// --------------------------------------------------------------------------
// 目标
// --------------------------------------------------------------------------


Target g_tg[] = {
  { "datahub","数据中枢", K_PROC, "E:\\finally-main\\cpp\\bin\\datahub.exe","E:\\finally-main\\cpp\\bin","datahub.exe",NULL,NULL,
    P_FILE, "E:\\datas\\log\\hb_datahub.txt", 60, 60, 3, 800, NULL,0,0,0,0,0,0,0,0,false,false,false,"" },
  { "apihub","接口服务",  K_PROC, "E:\\finally-main\\cpp\\bin\\apihub.exe", "E:\\finally-main\\cpp\\bin","apihub.exe", NULL,NULL,
    P_HTTP, "127.0.0.1:8090/health", 0, 25, 3, 600, NULL,0,0,0,0,0,0,0,0,false,false,false,"" },
  { "tphub","止盈引擎",   K_PROC, "E:\\finally-main\\cpp\\bin\\tphub.exe",  "E:\\finally-main\\cpp\\bin","tphub.exe",  NULL,NULL,
    P_FILE, "E:\\datas\\log\\hb_tphub.txt", 60, 60, 3, 400, NULL,0,0,0,0,0,0,0,0,false,false,false,"" },
  { "tradehub","交易中枢(C++)", K_PROC, "E:\\finally-main\\cpp\\bin\\tradehub.exe","E:\\finally-main\\cpp\\bin","tradehub.exe",NULL,NULL,
    P_FILE, "E:\\datas\\log\\hb_tradehub.txt", 60, 300, 3, 800, NULL,0,0,0,0,0,0,0,0,false,false,false,"" },
  { "apache","网页服务(Apache)", K_SVC, NULL,NULL,NULL,NULL,"Apache2.4",
    P_HTTP, "127.0.0.1:80/health_probe", 0, 0, 6, 0, NULL,0,0,0,0,0,0,0,0,false,false,false,"" },
  { "mariadb","数据库(MariaDB)", K_SVC, NULL,NULL,NULL,NULL,"MariaDB",
    P_MYSQL, "127.0.0.1:3306", 0, 0, 6, 0, NULL,0,0,0,0,0,0,0,0,false,false,false,"" },
};
const int g_tgN = (int)(sizeof(g_tg) / sizeof(g_tg[0]));

static const char* g_fixTasks[] = {
    "finally_fill1m", "finally_apihub", "finally_tphub", "finally_guard",
    // finally_phpengine / finally_phpfill 已按"去PHP"指令停用, 不再纳入配置自愈
};
static const int g_fixTaskN = (int)(sizeof(g_fixTasks) / sizeof(g_fixTasks[0]));
static const char* g_fixSvcs[] = { "Apache2.4", "MariaDB" };
static const int g_fixSvcN = (int)(sizeof(g_fixSvcs) / sizeof(g_fixSvcs[0]));

// 全局体检结果(供状态上报)
static struct {
    bool     tsOk = false;         // 计划任务 COM 可用
    bool     poolProbed = false;   // apihub 是否已探过(避免启动期误报 DB 异常)
    long long klineLagMin = -1;    // ETH 5m 最新K线滞后(分钟)
    int      poolCount = 0;        // apihub /health 报的池内合约数(>0 = DB 可查)
    int      okxRl = 0;            // OKX 限频累计次数
    int      okxRun = 0;           // 连续限频
    int      okxMs = 0;            // OKX 往返毫秒
    std::string okxMsg = "未检测";
    bool     okxBad = false;
    std::string issues;            // 当前异常汇总(给前端)
} g_report;

// --------------------------------------------------------------------------
// 小工具
// --------------------------------------------------------------------------
static std::string lower(std::string s) { for (auto& c : s) c = (char)tolower((unsigned char)c); return s; }
static std::wstring widen(const char* s) {
    int n = MultiByteToWideChar(CP_ACP, 0, s, -1, NULL, 0);
    std::wstring w(n ? n - 1 : 0, L'\0');
    if (n) MultiByteToWideChar(CP_ACP, 0, s, -1, &w[0], n);
    return w;
}
static std::string jesc(const std::string& s) {
    std::string o;
    for (char c : s) {
        if (c == '"' || c == '\\') { o += '\\'; o += c; }
        else if (c == '\n') o += "\\n";
        else if ((unsigned char)c < 0x20) o += ' ';
        else o += c;
    }
    return o;
}

DWORD find_pid(const char* proc, const char* exePath) {
    HANDLE snap = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);
    if (snap == INVALID_HANDLE_VALUE) return 0;
    PROCESSENTRY32W pe; pe.dwSize = sizeof(pe);
    std::string want = lower(proc), wantExe = lower(exePath);
    DWORD found = 0;
    if (Process32FirstW(snap, &pe)) {
        do {
            char nm[128]; WideCharToMultiByte(CP_ACP, 0, pe.szExeFile, -1, nm, sizeof(nm), NULL, NULL);
            if (lower(nm) != want) continue;
            HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pe.th32ProcessID);
            if (hp) {
                char buf[MAX_PATH * 2] = { 0 }; DWORD len = sizeof(buf) - 1;
                bool match = true;
                if (QueryFullProcessImageNameA(hp, 0, buf, &len)) match = (lower(buf) == wantExe);
                CloseHandle(hp);
                if (match) { found = pe.th32ProcessID; break; }
            }
        } while (Process32NextW(snap, &pe));
    }
    CloseHandle(snap);
    return found;
}

static long long file_age_sec(const char* path) {
    WIN32_FILE_ATTRIBUTE_DATA fa;
    if (!GetFileAttributesExA(path, GetFileExInfoStandard, &fa)) return -1;
    FILETIME now; GetSystemTimeAsFileTime(&now);
    ULARGE_INTEGER a, b;
    a.LowPart = fa.ftLastWriteTime.dwLowDateTime; a.HighPart = fa.ftLastWriteTime.dwHighDateTime;
    b.LowPart = now.dwLowDateTime;                b.HighPart = now.dwHighDateTime;
    if (b.QuadPart <= a.QuadPart) return 0;
    return (long long)((b.QuadPart - a.QuadPart) / 10000000ULL);
}

// HTTP 探活(本机明文; 有响应即视为"没卡死", 但过慢算卡死前兆)
static bool http_probe(const char* spec, int timeoutMs, long long* outMs, std::string* why, std::string* body = nullptr) {
    std::string s = spec;
    size_t c1 = s.find(':'), c2 = s.find('/');
    if (c1 == std::string::npos || c2 == std::string::npos) return false;
    std::string host = s.substr(0, c1);
    int port = atoi(s.substr(c1 + 1, c2 - c1 - 1).c_str());
    std::string path = s.substr(c2);
    SOCKET sk = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP);
    if (sk == INVALID_SOCKET) { if (why) *why = "socket 创建失败"; return false; }
    DWORD tmo = (DWORD)timeoutMs;
    setsockopt(sk, SOL_SOCKET, SO_RCVTIMEO, (char*)&tmo, sizeof(tmo));
    setsockopt(sk, SOL_SOCKET, SO_SNDTIMEO, (char*)&tmo, sizeof(tmo));
    sockaddr_in a{}; a.sin_family = AF_INET; a.sin_port = htons((u_short)port);
    a.sin_addr.s_addr = inet_addr(host.c_str());
    long long t0 = GetTickCount64();
    bool ok = false;
    std::string all;
    if (connect(sk, (sockaddr*)&a, sizeof(a)) == 0) {
        std::string req = "GET " + path + " HTTP/1.0\r\nHost: " + host + "\r\nConnection: close\r\n\r\n";
        if (send(sk, req.c_str(), (int)req.size(), 0) > 0) {
            char buf[2048]; int n = 0;
            while ((n = recv(sk, buf, sizeof(buf), 0)) > 0) { all.append(buf, n); if (all.size() > 65536) break; }
            if (all.find("HTTP/") != std::string::npos) {
                ok = true;
                if (why) why->clear();
            } else if (why) *why = "无 HTTP 响应(卡死或端口无服务)";
        } else if (why) *why = "请求发送失败";
    } else if (why) *why = "TCP 连接被拒(进程在但没监听)";
    if (outMs) *outMs = (long long)(GetTickCount64() - t0);
    if (body) *body = all;
    closesocket(sk);
    return ok;
}

// MySQL 握手探活: 连上后必须收到服务端 greeting(证明不是"端口在但数据库死了")
static bool mysql_probe(const char* spec, int timeoutMs, std::string* why) {
    std::string s = spec;
    size_t c = s.find(':');
    if (c == std::string::npos) return false;
    std::string host = s.substr(0, c);
    int port = atoi(s.substr(c + 1).c_str());
    SOCKET sk = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP);
    if (sk == INVALID_SOCKET) { if (why) *why = "socket 创建失败"; return false; }
    DWORD tmo = (DWORD)timeoutMs;
    setsockopt(sk, SOL_SOCKET, SO_RCVTIMEO, (char*)&tmo, sizeof(tmo));
    sockaddr_in a{}; a.sin_family = AF_INET; a.sin_port = htons((u_short)port);
    a.sin_addr.s_addr = inet_addr(host.c_str());
    bool ok = false;
    if (connect(sk, (sockaddr*)&a, sizeof(a)) == 0) {
        char buf[256] = { 0 };
        int n = recv(sk, buf, sizeof(buf) - 1, 0);
        if (n > 5 && (unsigned char)buf[0] >= 8) ok = true;   // MySQL greeting: 长度>5 且协议版本>=8
        else if (why) *why = "连接上了但收不到 MySQL 握手(数据库卡死/连接数满)";
    } else if (why) *why = "TCP 连接被拒(MySQL 未监听)";
    closesocket(sk);
    return ok;
}

// OKX 探活(WinHTTP): 返回 0=正常 1=限频 -1=网络错误
int okx_probe(std::string* msg, int* ms) {
    long long t0 = GetTickCount64();
    HINTERNET s = WinHttpOpen(L"finally_guard/3.0", WINHTTP_ACCESS_TYPE_DEFAULT_PROXY,
                              WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, 0);
    int r = -1;
    if (s) {
        WinHttpSetTimeouts(s, 4000, 4000, 4000, 6000);
        HINTERNET cn = WinHttpConnect(s, L"www.okx.com", INTERNET_DEFAULT_HTTPS_PORT, 0);
        if (cn) {
            HINTERNET rq = WinHttpOpenRequest(cn, L"GET", L"/api/v5/public/time", nullptr,
                                              WINHTTP_NO_REFERER, WINHTTP_DEFAULT_ACCEPT_TYPES, WINHTTP_FLAG_SECURE);
            if (rq) {
                if (WinHttpSendRequest(rq, WINHTTP_NO_ADDITIONAL_HEADERS, 0, WINHTTP_NO_REQUEST_DATA, 0, 0, 0) &&
                    WinHttpReceiveResponse(rq, nullptr)) {
                    DWORD st = 0, sz = sizeof(st);
                    WinHttpQueryHeaders(rq, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,
                                        WINHTTP_HEADER_NAME_BY_INDEX, &st, &sz, WINHTTP_NO_HEADER_INDEX);
                    std::string body;
                    char buf[1024]; DWORD rd = 0;
                    while (WinHttpReadData(rq, buf, sizeof(buf), &rd) && rd) { body.append(buf, rd); rd = 0; if (body.size() > 8192) break; }
                    if (st == 429) { r = 1; *msg = "HTTP 429 限频"; }
                    else if (body.find("50011") != std::string::npos) { r = 1; *msg = "50011 请求过快(限频)"; }
                    else if (body.find("50013") != std::string::npos) { r = 1; *msg = "50013 系统繁忙"; }
                    else if (st == 200 && body.find("\"code\":\"0\"") != std::string::npos) { r = 0; msg->clear(); }
                    else { r = -1; *msg = "HTTP " + std::to_string(st) + " 异常响应"; }
                } else *msg = "请求失败(WinHTTP)";
                WinHttpCloseHandle(rq);
            } else *msg = "OpenRequest 失败";
            WinHttpCloseHandle(cn);
        } else *msg = "连接 okx.com 失败";
        WinHttpCloseHandle(s);
    } else *msg = "WinHttpOpen 失败";
    if (ms) *ms = (int)(GetTickCount64() - t0);
    return r;
}

// --------------------------------------------------------------------------
// 计划任务(COM)
// --------------------------------------------------------------------------
static ITaskService* g_ts = nullptr;
bool ts_init() {
    HRESULT hr = CoInitializeEx(NULL, COINIT_MULTITHREADED);
    if (FAILED(hr) && hr != RPC_E_CHANGED_MODE) return false;
    hr = CoCreateInstance(CLSID_TaskScheduler, NULL, CLSCTX_INPROC_SERVER, IID_ITaskService, (void**)&g_ts);
    if (FAILED(hr) || !g_ts) return false;
    if (FAILED(g_ts->Connect(_variant_t(), _variant_t(), _variant_t(), _variant_t()))) {
        g_ts->Release(); g_ts = nullptr; return false;
    }
    return true;
}
static IRegisteredTask* ts_get(const char* name) {
    if (!g_ts) return nullptr;
    ITaskFolder* root = nullptr;
    if (FAILED(g_ts->GetFolder(_bstr_t(L"\\"), &root)) || !root) return nullptr;
    IRegisteredTask* t = nullptr;
    root->GetTask(_bstr_t(widen(name).c_str()), &t);
    root->Release();
    return t;
}
bool task_running(const char* name, bool* exists) {
    IRegisteredTask* t = ts_get(name);
    if (!t) { if (exists) *exists = false; return false; }
    if (exists) *exists = true;
    TASK_STATE st = TASK_STATE_UNKNOWN;
    t->get_State(&st);
    t->Release();
    return st == TASK_STATE_RUNNING;
}
HRESULT task_run2(const char* name) {
    IRegisteredTask* t = ts_get(name);
    if (!t) return E_FAIL;
    IRunningTask* rt = nullptr;
    HRESULT hr = t->Run(_variant_t(), &rt);
    if (rt) rt->Release();
    t->Release();
    return hr;
}
HRESULT task_stop(const char* name) {
    IRegisteredTask* t = ts_get(name);
    if (!t) return E_FAIL;
    HRESULT hr = t->Stop(0);
    t->Release();
    return hr;
}

// --------------------------------------------------------------------------
// Windows 服务
// --------------------------------------------------------------------------
bool svc_state(const char* name, DWORD* state) {
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_CONNECT);
    if (!scm) return false;
    SC_HANDLE svc = OpenServiceA(scm, name, SERVICE_QUERY_STATUS);
    bool ok = false;
    if (svc) {
        SERVICE_STATUS st{};
        if (QueryServiceStatus(svc, &st)) { *state = st.dwCurrentState; ok = true; }
        CloseServiceHandle(svc);
    }
    CloseServiceHandle(scm);
    return ok;
}
bool svc_start(const char* name) {
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_ALL_ACCESS);
    if (!scm) return false;
    SC_HANDLE svc = OpenServiceA(scm, name, SERVICE_START | SERVICE_QUERY_STATUS);
    bool ok = false;
    if (svc) {
        if (StartServiceA(svc, 0, NULL)) ok = true;
        else if (GetLastError() == ERROR_SERVICE_ALREADY_RUNNING) ok = true;
        CloseServiceHandle(svc);
    }
    CloseServiceHandle(scm);
    return ok;
}
bool svc_restart(const char* name) {
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_ALL_ACCESS);
    if (!scm) return false;
    SC_HANDLE svc = OpenServiceA(scm, name, SERVICE_STOP | SERVICE_START | SERVICE_QUERY_STATUS);
    bool ok = false;
    if (svc) {
        SERVICE_STATUS st{};
        ControlService(svc, SERVICE_CONTROL_STOP, &st);
        for (int i = 0; i < 40; i++) {
            SERVICE_STATUS s2{};
            if (!QueryServiceStatus(svc, &s2) || s2.dwCurrentState == SERVICE_STOPPED) break;
            Sleep(500);
        }
        ok = svc_start(name);
        CloseServiceHandle(svc);
    }
    CloseServiceHandle(scm);
    return ok;
}

// --------------------------------------------------------------------------
// 探测
// --------------------------------------------------------------------------
bool probe(Target& t, std::string& why, std::string* body) {
    switch (t.probe) {
    case P_FILE: {
        long long age = file_age_sec(t.probeArg);
        if (age < 0) { why = std::string("心跳文件不存在: ") + t.probeArg; return false; }
        if (age > t.staleSec) {
            char b[128]; snprintf(b, sizeof(b), "卡死: 心跳停更 %lld 秒(阈值%d)", age, t.staleSec);
            why = b; return false;
        }
        return true;
    }
    case P_HTTP: {
        long long ms = 0;
        bool ok = http_probe(t.probeArg, 4000, &ms, &why, body);
        if (ok && ms > 3000) { char b[96]; snprintf(b, sizeof(b), "响应过慢 %lldms(>3000ms)", ms); why = b; return false; }
        return ok;
    }
    case P_MYSQL:
        return mysql_probe(t.probeArg, 3000, &why);
    default:
        return true;
    }
}

static bool mem_runaway(Target& t) {
    if (!t.memLimitMB || !t.pid) return false;
    HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, t.pid);
    if (!hp) return false;
    PROCESS_MEMORY_COUNTERS pmc{}; pmc.cb = sizeof(pmc);
    bool bad = false;
    if (GetProcessMemoryInfo(hp, &pmc, sizeof(pmc))) {
        SIZE_T mb = pmc.WorkingSetSize / (1024 * 1024);
        if ((int)mb > t.memLimitMB * 2) bad = true;
        else if ((int)mb > t.memLimitMB) {
            char b[160]; snprintf(b, sizeof(b), "[%s] 内存偏高 %lluMB (上限%dMB)",
                                  t.key, (unsigned long long)mb, t.memLimitMB);
            logline(b);
        }
    }
    CloseHandle(hp);
    return bad;
}

// --------------------------------------------------------------------------
// 动作
// --------------------------------------------------------------------------

// 找出所有同名同路径的进程(用于清理"双开")
static std::vector<DWORD> find_all_pids(const char* proc, const char* exePath) {
    std::vector<DWORD> out;
    HANDLE snap = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);
    if (snap == INVALID_HANDLE_VALUE) return out;
    PROCESSENTRY32W pe; pe.dwSize = sizeof(pe);
    std::string want = lower(proc), wantExe = lower(exePath);
    if (Process32FirstW(snap, &pe)) {
        do {
            char nm[128]; WideCharToMultiByte(CP_ACP, 0, pe.szExeFile, -1, nm, sizeof(nm), NULL, NULL);
            if (lower(nm) != want) continue;
            HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pe.th32ProcessID);
            if (!hp) continue;
            char buf[MAX_PATH * 2] = { 0 }; DWORD len = sizeof(buf) - 1;
            if (QueryFullProcessImageNameA(hp, 0, buf, &len) && lower(buf) == wantExe)
                out.push_back(pe.th32ProcessID);
            CloseHandle(hp);
        } while (Process32NextW(snap, &pe));
    }
    CloseHandle(snap);
    return out;
}

// 双开清理: 同一 exe 只允许一个实例(双开会同时写库+抢 OKX 限频配额)
static void kill_duplicates(Target& t) {
    if (t.kind != K_PROC) return;
    std::vector<DWORD> v = find_all_pids(t.proc, t.exe);
    if (v.size() < 2) return;
    DWORD keep = 0;
    for (DWORD pid : v) if (pid == t.pid) keep = pid;
    if (!keep) {                       // 我们托管的那只不在了 → 保留启动最早的一只
        ULONGLONG best = 0;
        for (DWORD pid : v) {
            HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pid);
            if (!hp) continue;
            FILETIME c, e, k, u;
            if (GetProcessTimes(hp, &c, &e, &k, &u)) {
                ULONGLONG t0 = ((ULONGLONG)c.dwHighDateTime << 32) | c.dwLowDateTime;
                if (!best || t0 < best) { best = t0; keep = pid; }
            }
            CloseHandle(hp);
        }
    }
    for (DWORD pid : v) {
        if (pid == keep) continue;
        HANDLE hp = OpenProcess(PROCESS_TERMINATE | PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pid);
        if (!hp) continue;
        char b[192];
        snprintf(b, sizeof(b), "[%s] 发现重复实例 pid=%lu (保留 %lu) → 已清理", t.key, (unsigned long)pid, (unsigned long)keep);
        logline(b);
        TerminateProcess(hp, 1);
        CloseHandle(hp);
    }
}

static bool proc_alive(Target& t) {
    if (t.h) {
        if (WaitForSingleObject(t.h, 0) != WAIT_OBJECT_0) return true;
        // 自己拉起的子进程已退出: 先按"名称+全路径"重找 —— 计划任务/手动启动的另一个实例
        // 才是真正在跑的那个(子进程因单实例检查立刻 exit 0), 直接接手, 否则会无限重启风暴
        CloseHandle(t.h); t.h = NULL;
    }
    DWORD p = find_pid(t.proc, t.exe);
    if (p) {
        if (t.pid != p) {
            char b[160]; snprintf(b, sizeof(b), "[%s] 接手已运行进程 pid=%lu", t.key, (unsigned long)p);
            logline(b);
            t.startTick = GetTickCount();
        }
        t.pid = p;
        return true;
    }
    return false;
}
static bool spawn(Target& t) {
    STARTUPINFOA si{}; si.cb = sizeof(si);
    PROCESS_INFORMATION pi{};
    char cmd[MAX_PATH * 2];
    snprintf(cmd, sizeof(cmd), "\"%s\"", t.exe);
    if (!CreateProcessA(t.exe, cmd, NULL, NULL, FALSE, CREATE_NO_WINDOW | CREATE_NEW_PROCESS_GROUP,
                        NULL, t.workdir, &si, &pi)) {
        char b[224]; snprintf(b, sizeof(b), "[%s] 拉取失败 err=%lu", t.key, GetLastError());
        logline(b); return false;
    }
    if (t.h) CloseHandle(t.h);
    CloseHandle(pi.hThread);
    t.h = pi.hProcess; t.pid = pi.dwProcessId;
    t.startTick = t.lastProbeTick = GetTickCount();
    t.failN = t.memN = 0; t.restarts++;
    char b[224];
    snprintf(b, sizeof(b), "[%s] 已拉起 pid=%lu (第%d次守护重启)", t.key, (unsigned long)t.pid, t.restarts);
    logline(b);
    return true;
}
static std::string exit_reason(DWORD code) {
    switch (code) {
    case 0:          return "正常退出(0)";
    case 1:          return "被强杀(TerminateProcess/taskkill)";
    case 0xC0000005: return "内存越界崩溃(0xC0000005)";
    case 0xC0000409: return "栈溢出/快速失败(0xC0000409)";
    case 0xC000013A: return "控制台被关闭/中断(0xC000013A)";
    case 0xC0000374: return "堆损坏(0xC0000374)";
    case 0xC0000017: return "内存不足(0xC0000017)";
    case 0x40010004: return "会话被注销(0x40010004) ★用户退出登录把进程带走";
    }
    char b[64]; snprintf(b, sizeof(b), "退出码 0x%08lX", (unsigned long)code);
    return b;
}
static void kill_proc(Target& t, const char* why) {
    char b[256]; snprintf(b, sizeof(b), "[%s] 判定卡死(%s) → 强制重启", t.key, why);
    logline(b);
    if (t.h) {
        TerminateProcess(t.h, 1);
        WaitForSingleObject(t.h, 3000);
        CloseHandle(t.h); t.h = NULL;
    } else {
        HANDLE hp = OpenProcess(PROCESS_TERMINATE, FALSE, t.pid);
        if (hp) { TerminateProcess(hp, 1); WaitForSingleObject(hp, 3000); CloseHandle(hp); }
    }
    t.pid = 0; t.failN = 0; t.nextTryTick = 0;
}

// --------------------------------------------------------------------------
// 配置自愈
// --------------------------------------------------------------------------
// ISO8601 时长 → 秒 (PT1M=60, PT30S=30, PT1H=3600, P1D=86400)
static int iso_secs(const wchar_t* s) {
    if (!s) return 0;
    long long v = 0; int unit = 0;
    for (const wchar_t* p = s; *p; ++p) {
        if (*p >= L'0' && *p <= L'9') {
            v = v * 10 + (*p - L'0');
            if (v > 1000000000LL) return 0;
        } else if (*p == L'S') { unit = 1;     break; }
        else if (*p == L'M')   { unit = 60;    break; }
        else if (*p == L'H')   { unit = 3600;  break; }
        else if (*p == L'D')   { unit = 86400; break; }
        else if (*p == L'T' || *p == L'P') continue;
        else return 0;
    }
    return (int)(v * unit);
}
// 触发器自愈: 守护型任务必须"只启动一次、常驻运行"。
//   历史遗留: finally_* 任务带 <Repetition><Interval>PT1M</Interval> → 每 60 秒
//   拉起一个"幽灵实例"(被单例检查挡回后立刻自杀), 白烧 CPU 且与 guard 抢拉起。
//   这里把 TimeTrigger 上的重复间隔清空(空串 = 关闭重复, MSDN 语义)。
static bool clear_task_repetition(ITaskDefinition* def) {
    if (!def) return false;
    ITriggerCollection* tc = nullptr;
    if (FAILED(def->get_Triggers(&tc)) || !tc) return false;
    bool chg = false;
    LONG n = 0;
    if (SUCCEEDED(tc->get_Count(&n))) {
        for (LONG i = 1; i <= n && i <= 64; i++) {
            ITrigger* tr = nullptr;
            if (FAILED(tc->get_Item(i, &tr)) || !tr) continue;
            ITimeTrigger* tt = nullptr;
            if (SUCCEEDED(tr->QueryInterface(IID_ITimeTrigger, (void**)&tt)) && tt) {
                IRepetitionPattern* rp = nullptr;
                if (SUCCEEDED(tt->get_Repetition(&rp)) && rp) {
                    BSTR iv = nullptr;
                    if (SUCCEEDED(rp->get_Interval(&iv)) && iv && iv[0]) {
                        int secs = iso_secs(iv);
                        HRESULT hr = rp->put_Interval(_bstr_t(L""));
                        char b[192]; snprintf(b, sizeof(b), "[自愈] 触发器重复间隔 %d 秒 → %s(守护任务只需常驻一次)",
                                              secs, SUCCEEDED(hr) ? "已关闭" : "关闭失败");
                        logline(b);
                        if (SUCCEEDED(hr)) chg = true;
                    }
                    if (iv) SysFreeString(iv);
                    rp->Release();
                }
                tt->Release();
            }
            tr->Release();
        }
    }
    tc->Release();
    return chg;
}
int fixup_tasks() {
    int fixedN = 0;
    for (int i = 0; i < g_fixTaskN; i++) {
        IRegisteredTask* t = ts_get(g_fixTasks[i]);
        if (!t) continue;
        ITaskDefinition* def = nullptr;
        if (FAILED(t->get_Definition(&def)) || !def) { t->Release(); continue; }
        bool changed = false;
        ITaskSettings* st = nullptr;
        if (SUCCEEDED(def->get_Settings(&st)) && st) {
            VARIANT_BOOL b = VARIANT_FALSE;
            BSTR s = nullptr;
            st->get_Enabled(&b);
            if (b == VARIANT_FALSE) { st->put_Enabled(VARIANT_TRUE); changed = true; }
            if (SUCCEEDED(st->get_ExecutionTimeLimit(&s)) && s) {
                if (wcscmp(s, L"PT0S") != 0) { st->put_ExecutionTimeLimit(_bstr_t(L"PT0S")); changed = true; }
                SysFreeString(s); s = nullptr;
            }
            st->get_DisallowStartIfOnBatteries(&b);
            if (b != VARIANT_FALSE) { st->put_DisallowStartIfOnBatteries(VARIANT_FALSE); changed = true; }
            st->get_StopIfGoingOnBatteries(&b);
            if (b != VARIANT_FALSE) { st->put_StopIfGoingOnBatteries(VARIANT_FALSE); changed = true; }
            IIdleSettings* idle = nullptr;
            if (SUCCEEDED(st->get_IdleSettings(&idle)) && idle) {
                idle->get_StopOnIdleEnd(&b);
                if (b != VARIANT_FALSE) { idle->put_StopOnIdleEnd(VARIANT_FALSE); changed = true; }
                idle->Release();
            }
            int rc = 0;
            st->get_RestartCount(&rc);
            if (rc < 3) {
                // 必须 Interval + Count 一起写, 否则任务计划程序不会落 <RestartOnFailure>,
                // 下一轮读回来仍是 0 → 自愈永远"不收敛", 每 10 分钟白重写一次任务定义
                st->put_RestartInterval(_bstr_t(L"PT1M"));
                st->put_RestartCount(999);
                changed = true;
            }
        }
        if (st) st->Release();
        if (clear_task_repetition(def)) changed = true;
        if (changed && g_ts) {
            ITaskFolder* root = nullptr;
            if (SUCCEEDED(g_ts->GetFolder(_bstr_t(L"\\"), &root)) && root) {
                IRegisteredTask* out = nullptr;
                HRESULT r = root->RegisterTaskDefinition(_bstr_t(widen(g_fixTasks[i]).c_str()), def,
                                                         TASK_CREATE_OR_UPDATE, _variant_t(), _variant_t(),
                                                         TASK_LOGON_NONE, _variant_t(L""), &out);
                if (SUCCEEDED(r)) {
                    fixedN++;
                    logline(std::string("[自愈] 任务 ") + g_fixTasks[i] +
                            " 危险配置已修复(无限运行/电池/空闲/失败重启/去重复触发)");
                } else {
                    char b[160]; snprintf(b, sizeof(b), "[自愈] 任务 %s 写回失败 hr=0x%08lX", g_fixTasks[i], (unsigned long)r);
                    logline(b);
                }
                if (out) out->Release();
                root->Release();
            }
        }
        def->Release();
        t->Release();
    }
    return fixedN;
}
int fixup_svcs() {
    int fixedN = 0;
    for (int i = 0; i < g_fixSvcN; i++) {
        SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_ALL_ACCESS);
        if (!scm) break;
        SC_HANDLE svc = OpenServiceA(scm, g_fixSvcs[i], SERVICE_QUERY_CONFIG | SERVICE_CHANGE_CONFIG);
        if (svc) {
            DWORD need = 0;
            QueryServiceConfigA(svc, NULL, 0, &need);
            LPQUERY_SERVICE_CONFIGA cfg = (LPQUERY_SERVICE_CONFIGA)malloc(need ? need : 4096);
            if (cfg && QueryServiceConfigA(svc, cfg, need, &need)) {
                if (cfg->dwStartType != SERVICE_AUTO_START) {
                    if (ChangeServiceConfigA(svc, SERVICE_NO_CHANGE, SERVICE_AUTO_START, SERVICE_NO_CHANGE,
                                             NULL, NULL, NULL, NULL, NULL, NULL, NULL)) {
                        fixedN++;
                        logline(std::string("[自愈] 服务 ") + g_fixSvcs[i] + " 启动类型已改回[自动]");
                    }
                }
            }
            if (cfg) free(cfg);
            CloseServiceHandle(svc);
        }
        CloseServiceHandle(scm);
    }
    return fixedN;
}

// --------------------------------------------------------------------------
// 状态上报(前端读取)
// --------------------------------------------------------------------------
static void write_status() {
    time_t t = time(nullptr); struct tm lt; localtime_s(&lt, &t);
    char ts[32]; strftime(ts, sizeof(ts), "%Y-%m-%d %H:%M:%S", &lt);

    std::string tj;
    for (int i = 0; i < g_tgN; i++) {
        Target& x = g_tg[i];
        if (i) tj += ",";
        char b[512];
        snprintf(b, sizeof(b),
                 "{\"key\":\"%s\",\"label\":\"%s\",\"kind\":\"%s\",\"run\":%d,\"ok\":%d,\"pid\":%lu,"
                 "\"restarts\":%d,\"msg\":\"%s\"}",
                 x.key, x.label,
                 x.kind == K_PROC ? "进程" : (x.kind == K_TASK ? "任务" : "服务"),
                 x.run ? 1 : 0, (x.run && (x.healthy || !x.probed)) ? 1 : 0, (unsigned long)x.pid,
                 x.restarts, jesc(x.msg).c_str());
        tj += b;
    }
    std::string js;
    js += "{\"ok\":true,\"ver\":\"guard-v3\",\"ts\":";
    js += std::to_string((long long)t);
    js += ",\"time\":\""; js += ts; js += "\"";
    js += ",\"uptime\":" + std::to_string((long long)((GetTickCount64() - g_startTick) / 1000));
    js += ",\"targets\":[" + tj + "]";
    js += ",\"mysql\":{\"pool\":" + std::to_string(g_report.poolCount) +
          ",\"kline_lag_min\":" + std::to_string(g_report.klineLagMin) + "}";
    js += ",\"okx\":{\"rl_total\":" + std::to_string(g_report.okxRl) +
          ",\"rl_run\":" + std::to_string(g_report.okxRun) +
          ",\"ms\":" + std::to_string(g_report.okxMs) +
          ",\"bad\":" + std::string(g_report.okxBad ? "true" : "false") +
          ",\"msg\":\"" + jesc(g_report.okxMsg) + "\"}";
    js += ",\"task_api\":" + std::string(g_report.tsOk ? "true" : "false");
    js += ",\"issues\":\"" + jesc(g_report.issues) + "\"}";

    FILE* f = fopen(STATUSF ".tmp", "w");
    if (f) { fwrite(js.data(), 1, js.size(), f); fclose(f); MoveFileExA(STATUSF ".tmp", STATUSF, MOVEFILE_REPLACE_EXISTING); }
}

// --------------------------------------------------------------------------
// 主循环
// --------------------------------------------------------------------------
void guard_loop() {
    WSADATA wsa; WSAStartup(MAKEWORD(2, 2), &wsa);
    InitializeCriticalSection(&g_logCs);
    g_startTick = GetTickCount();
    logline("============ guard v3 启动 ============");
    logline("托管: 进程[datahub/apihub/tphub] 任务[phpengine/phpfill] 服务[Apache2.4/MariaDB] + OKX限频/MySQL断线体检");
    g_report.tsOk = ts_init();
    if (!g_report.tsOk) logline("!! 计划任务接口初始化失败(任务类目标只读)");

    for (int i = 0; i < g_tgN; i++) {
        Target& t = g_tg[i];
        if (t.kind == K_PROC) {
            DWORD pid = find_pid(t.proc, t.exe);
            if (pid) {
                t.pid = pid; t.h = NULL;
                t.startTick = GetTickCount();
                t.lastProbeTick = GetTickCount() - PROBE_EVERY + 4000;
                char b[160]; snprintf(b, sizeof(b), "[%s] 接手已运行进程 pid=%lu", t.key, (unsigned long)pid);
                logline(b);
                t.healthy = true;              // 先用"未知即正常"避免前端误报, 探活失败会立刻转异常
            }
        } else if (t.kind == K_TASK) {
            bool ex = false, run = task_running(t.taskName, &ex);
            if (run) { t.startTick = t.lastProbeTick = GetTickCount(); logline(std::string("[") + t.key + "] 任务已在运行"); }
            else if (!ex) logline(std::string("[") + t.key + "] !! 任务不存在, 需人工确认");
        }
    }
    fixup_tasks();
    fixup_svcs();

    DWORD lastSummary = 0, lastFixup = GetTickCount(), lastOkx = 0, lastStatus = 0;
    logline("进入主循环");
    while (!g_stop) {
        DWORD now = GetTickCount();
        g_report.issues.clear();

        for (int i = 0; i < g_tgN; i++) {
            Target& t = g_tg[i];
            t.msg.clear();

            // ---------- 存活/拉起 ----------
            if (t.kind == K_PROC) {
                t.run = proc_alive(t);
                if (!t.run && (t.probe == P_FILE || t.probe == P_HTTP)) {
                    // 进程句柄丢了, 但心跳/HTTP 还新鲜 → 说明"另一个实例"(计划任务/手动启动)正在跑,
                    // 直接视为存活, 绝不拉起 —— 否则会和计划任务打架, 造成无限重启风暴 + 双开
                    std::string why2;
                    if (probe(t, why2, nullptr)) { t.run = true; t.msg = "外部实例运行中(心跳正常)"; }
                }
                if (!t.run) {
                    if (t.h) {
                        DWORD ec = 0;
                        if (GetExitCodeProcess(t.h, &ec)) {
                            char b[256]; snprintf(b, sizeof(b), "[%s] 进程退出 pid=%lu → %s",
                                                  t.key, (unsigned long)t.pid, exit_reason(ec).c_str());
                            logline(b);
                        }
                        CloseHandle(t.h); t.h = NULL;
                    } else logline(std::string("[") + t.key + "] 进程已消失");
                    t.pid = 0; t.failN = t.memN = 0;
                    t.healthy = false;
                    t.msg = "进程不在, 正在拉起";
                    if (now >= t.nextTryTick) {
                        if (!spawn(t)) {
                            t.backoffMs = t.backoffMs ? (t.backoffMs * 2 > 60000 ? 60000 : t.backoffMs * 2) : 5000;
                            t.nextTryTick = now + (DWORD)t.backoffMs;
                            t.msg = "拉起失败, 退避重试中";
                        } else t.nextTryTick = 0;
                    }
                    g_report.issues += std::string(t.label) + "未运行; ";
                    continue;
                }
                if (t.backoffMs && now - t.startTick > 300000) t.backoffMs = 0;
                kill_duplicates(t);   // 双开自动清理(每轮一次, 开销极小)
            } else if (t.kind == K_TASK) {
                bool ex = false;
                t.run = task_running(t.taskName, &ex);
                if (!ex) { t.healthy = false; t.msg = "任务不存在"; g_report.issues += std::string(t.label) + "任务缺失; "; continue; }
                if (!t.run) {
                    t.healthy = false;
                    t.msg = "任务未运行";
                    if (now >= t.nextTryTick) {
                        HRESULT hr = task_run2(t.taskName);
                        if (SUCCEEDED(hr)) {
                            t.restarts++; t.startTick = t.lastProbeTick = GetTickCount(); t.failN = 0;
                            char b[192]; snprintf(b, sizeof(b), "[%s] 任务未运行 → 已启动(第%d次)", t.key, t.restarts);
                            logline(b);
                            t.msg = "刚被拉起";
                        } else {
                            char b[192]; snprintf(b, sizeof(b), "[%s] 任务启动失败 hr=0x%08lX", t.key, (unsigned long)hr);
                            logline(b);
                            t.nextTryTick = now + 10000;
                            t.msg = "启动失败";
                        }
                    }
                    g_report.issues += std::string(t.label) + "未运行; ";
                    continue;
                }
            } else {
                DWORD st = 0;
                bool got = svc_state(t.svcName, &st);
                t.run = got && st == SERVICE_RUNNING;
                if (!t.run) {
                    t.healthy = false;
                    t.msg = got ? "服务已停止" : "服务不存在";
                    if (got && now >= t.nextTryTick) {
                        if (svc_start(t.svcName)) {
                            t.restarts++; t.failN = 0; t.lastProbeTick = GetTickCount();
                            logline(std::string("[") + t.key + "] 服务未运行 → 已启动");
                            t.msg = "刚被启动";
                        } else {
                            char b[192]; snprintf(b, sizeof(b), "[%s] 服务启动失败 err=%lu", t.key, GetLastError());
                            logline(b);
                            t.nextTryTick = now + 15000;
                        }
                    }
                    g_report.issues += std::string(t.label) + "未运行; ";
                    continue;
                }
            }

            // ---------- 探活 ----------
            if (now - t.lastProbeTick < PROBE_EVERY) continue;
            t.lastProbeTick = now;
            if (t.kind == K_PROC && now - t.startTick < (DWORD)t.warmupSec * 1000) continue;

            std::string why, body;
            bool healthy = probe(t, why, &body);

            // apihub /health 顺带取池内合约数(端到端证明 MySQL 可查)
            if (healthy && t.probe == P_HTTP && t.key[0] == 'a' && t.key[1] == 'p' && t.key[2] == 'i') {
                size_t p = body.find("\"pool\"");
                if (p != std::string::npos) {
                    size_t c = body.find("count\":", p);
                    if (c != std::string::npos) { g_report.poolCount = atoi(body.c_str() + c + 7); g_report.poolProbed = true; }
                }
            }

            if (healthy && t.kind == K_PROC && mem_runaway(t)) {
                if (++t.memN >= 3) { kill_proc(t, "内存失控膨胀"); t.memN = 0; continue; }
            } else if (healthy) t.memN = 0;

            if (healthy) {
                if (t.failN) logline(std::string("[") + t.key + "] 探活恢复正常");
                t.failN = 0; t.healthy = true; t.probed = true;
                continue;
            }

            t.healthy = false; t.probed = true;
            t.msg = why;
            t.failN++;
            {
                char b[256]; snprintf(b, sizeof(b), "[%s] 探活失败 %d/%d: %s", t.key, t.failN, t.failToKill, why.c_str());
                logline(b);
            }
            g_report.issues += std::string(t.label) + "异常(" + why + "); ";
            if (t.failN < t.failToKill) continue;
            t.failN = 0;

            if (t.kind == K_PROC) kill_proc(t, why.c_str());
            else if (t.kind == K_TASK) {
                logline(std::string("[") + t.key + "] 任务卡死 → 停止并重启");
                task_stop(t.taskName); Sleep(1000);
                if (SUCCEEDED(task_run2(t.taskName))) t.restarts++;
                t.startTick = t.lastProbeTick = GetTickCount();
            } else {
                logline(std::string("[") + t.key + "] 服务无响应 → 重启服务(断线自愈)");
                svc_restart(t.svcName);
                t.startTick = t.lastProbeTick = GetTickCount();
            }
        }

        // ---------- OKX 限频体检(每60秒) ----------
        if (now - lastOkx > OKX_EVERY) {
            lastOkx = now;
            std::string m; int ms = 0;
            int r = okx_probe(&m, &ms);
            g_report.okxMs = ms;
            if (r == 1) {
                g_report.okxRl++; g_report.okxRun++; g_report.okxBad = true; g_report.okxMsg = m;
                if (g_report.okxRun == 1) logline("[OKX] 检测到限频: " + m + " → datahub 会自动放慢(自适应节流)");
                else if (g_report.okxRun % 10 == 0)
                    logline("[OKX] 持续限频中(连续" + std::to_string(g_report.okxRun) + "次, 累计" + std::to_string(g_report.okxRl) + "次)");
            } else if (r == 0) {
                if (g_report.okxBad) logline("[OKX] 限频已恢复, 往返 " + std::to_string(ms) + "ms");
                g_report.okxRun = 0; g_report.okxBad = false; g_report.okxMsg = "正常";
            } else {
                g_report.okxBad = true; g_report.okxRun++; g_report.okxMsg = m;
                logline("[OKX] 探活异常: " + m);
            }
        }

        // ---------- K线新鲜度(每5分钟, 经 apihub 侧写不了 → 用文件心跳推断) ----------
        // 说明: 精确滞后由前端健康卡经 apihub /guard 一起展示; 这里记录心跳年龄作为粗判
        {
            long long age = file_age_sec("E:\\datas\\log\\hb_datahub.txt");
            g_report.klineLagMin = age < 0 ? -1 : (long long)(age / 60);
            if (age > 300 && age > 0) {
                char b[192];
                snprintf(b, sizeof(b), "[数据] datahub 心跳已停更 %lld 秒 → 数据可能停更", age);
                if (now - lastSummary > 60000) logline(b);   // 降噪: 最多每分钟一条
                g_report.issues += "数据中枢心跳停更; ";
            }
        }

        // ---------- MySQL 端到端: apihub 能查到池(池数>0 说明 DB 通) ----------
        {
            static bool warnOnce = false;
            if (g_report.poolProbed && g_report.poolCount == 0) {
                if (!warnOnce) {
                    logline("[DB] !! apihub /health 报池内合约 0 个 → MySQL 可能断连(守护将持续观察)");
                    g_report.issues += "数据库查询异常; ";
                    warnOnce = true;
                }
            } else if (warnOnce) { logline("[DB] 数据库查询恢复正常(pool=" + std::to_string(g_report.poolCount) + ")"); warnOnce = false; }
        }

        // ---------- 配置自愈 ----------
        if (now - lastFixup > FIXUP_EVERY) {
            lastFixup = now;
            int a = fixup_tasks(), b = fixup_svcs();
            if (a || b) logline("[自愈] 本轮修复 " + std::to_string(a + b) + " 处被改弱的保护配置");
        }

        // ---------- 心跳 + 状态文件(前端用) ----------
        {
            FILE* f = fopen(HB_GUARD, "w");
            if (f) { fprintf(f, "%lld\n", (long long)time(nullptr)); fclose(f); }
        }
        if (now - lastStatus > 2000) { lastStatus = now; write_status(); }

        // ---------- 汇总 ----------
        if (now - lastSummary > 300000) {
            lastSummary = now;
            std::string s = "状态:";
            for (int i = 0; i < g_tgN; i++) {
                char b[128];
                bool okNow = g_tg[i].run && (g_tg[i].healthy || !g_tg[i].probed);
                snprintf(b, sizeof(b), " %s%s(%s,重启%d)", g_tg[i].key, g_tg[i].run ? "✓" : "✗",
                         okNow ? "正常" : "异常", g_tg[i].restarts);
                s += b;
            }
            logline(s);
        }

        for (int k = 0; k < LOOP_MS / 200 && !g_stop; k++) Sleep(200);
    }
    write_status();
    logline("guard 已停止(子进程保留, 由计划任务兜底)");
}
