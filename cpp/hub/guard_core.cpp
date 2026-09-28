// ==================================================================
// guard_core.cpp — 守护中枢核心组件 (拆分自 guard.cpp, 业务逻辑零改动)
// 托管目标 / 三级探活 / 内存看门狗 / 双开清理 / 配置自愈 / 状态上报 / 主循环
// 服务壳(SCM/安装/CLI)见 guard_main.cpp
// ------------------------------------------------------------------
// 文件职责: guard.exe(finally_guard 服务)的守护核心实现:
//   1) 7 个监控目标(g_tg): datahub/apihub/tphub/tradehub 进程 + apache/mariadb 服务;
//   2) 三级探活: 进程存在 → 心跳文件/HTTP/MySQL 实际可用;
//   3) 内存失控看门狗(mem_runaway)、双开防护(kill_duplicates);
//   4) 配置自愈(fixup_tasks 修 PT0S/电池/空闲/Enabled, fixup_svcs 修自启);
//   5) 状态上报(write_status 写 guard_status.json 给前端);
//   6) OKX 限频探测(okx_probe)与 guard_loop 主循环。
// 函数清单: logline / lower / widen / jesc / find_pid / file_age_sec /
//   http_probe / mysql_probe / okx_probe / ts_init / ts_get / task_running /
//   task_run2 / task_stop / svc_state / svc_start / svc_restart / probe /
//   mem_runaway / find_all_pids / kill_duplicates / proc_alive / spawn /
//   exit_reason / kill_proc / iso_secs / clear_task_repetition / fixup_tasks /
//   fixup_svcs / write_status / guard_loop
// 被谁调用: guard_main.cpp 的 service_main(SCM 服务模式)与 run_console(前台模式)
//           最终都进入 guard_loop; fixup_tasks/fixup_svcs 亦可被 -fixup 单独调用。
// ==================================================================
#define _WIN32_WINNT 0x0601                          // 目标系统 Win7+(Task Scheduler COM 等需要)
#include "guard.h"                                   // 公共声明(常量/Target/接口)
#include <winsock2.h>                                // WinSock2(socket 探活, 必须先于 windows.h)
#include <ws2tcpip.h>                                // inet_addr 等地址转换函数
#include <winhttp.h>                                 // WinHTTP, 用于 OKX 公网探活
#include <tlhelp32.h>                                // 进程快照(CreateToolhelp32Snapshot)
#include <psapi.h>                                   // 进程内存信息(GetProcessMemoryInfo)
#include <taskschd.h>                                // Task Scheduler COM 接口(计划任务管理)
#include <comdef.h>                                  // _bstr_t/_variant_t 等 COM 辅助类型
#include <cstdio>                                    // fopen/fprintf/snprintf
#include <ctime>                                     // time/localtime_s/strftime

volatile bool         g_stop = false;                // 全局停止标志: SCM 停止命令或 Ctrl+C 置 true
SERVICE_STATUS_HANDLE g_ssh = NULL;                  // SCM 服务状态句柄(service_main 注册后有效)
SERVICE_STATUS        g_ss;                          // SCM 服务状态结构体(状态上报给 SCM)
CRITICAL_SECTION      g_logCs;                       // 日志临界区: guard_loop 与崩溃回调并发写日志时互斥
DWORD                 g_startTick = 0;               // guard 启动时刻(tick), 用于计算运行时长 uptime

// --------------------------------------------------------------------------
// 日志
// --------------------------------------------------------------------------
void logline(const std::string& s) {                 // 写一行日志到 guard.log(线程安全+自动轮转)
    EnterCriticalSection(&g_logCs);                  // 进入临界区, 保证多线程写日志不交错
    FILE* f = fopen(LOGF, "a");                      // 以追加方式打开日志文件
    if (f) {                                         // 打开成功才做轮转检查
        fseek(f, 0, SEEK_END);                       // 定位到文件尾
        if (ftell(f) > ROTATE_BYTES) {               // 文件超过 4MB 阈值 → 触发轮转
            fclose(f); f = NULL;                     // 先关闭当前句柄才能改名
            DeleteFileA(LOGF ".1");                  // 删除上一代日志 guard.log.1
            MoveFileA(LOGF, LOGF ".1");              // 当前日志降级为 guard.log.1
            f = fopen(LOGF, "a");                    // 重新打开一个全新的空日志
        }
    }
    if (f) {                                         // 打开成功(可能是轮转后的新文件)才写内容
        time_t t = time(nullptr); struct tm lt; localtime_s(&lt, &t);   // 取当前本地时间
        char ts[32]; strftime(ts, sizeof(ts), "%Y-%m-%d %H:%M:%S", &lt); // 格式化为时间戳字符串
        fprintf(f, "[%s] %s\n", ts, s.c_str());      // 写入 "[时间] 内容" 一行
        fclose(f);                                   // 立即关闭, 确保每条日志都落盘
    }
    LeaveCriticalSection(&g_logCs);                  // 离开临界区
}

// --------------------------------------------------------------------------
// 目标
// --------------------------------------------------------------------------


Target g_tg[] = {                                    // 全局监控目标表(守护的核心配置)
  { "datahub","数据中枢", K_PROC, "E:\\finally-main\\cpp\\bin\\datahub.exe","E:\\finally-main\\cpp\\bin","datahub.exe",NULL,NULL,
    P_FILE, "E:\\datas\\log\\hb_datahub.txt", 60, 60, 3, 800, NULL,0,0,0,0,0,0,0,0,false,false,false,"" },  // 数据中枢: 心跳文件探活, 60秒停更判死, 内存上限800MB
  { "apihub","接口服务",  K_PROC, "E:\\finally-main\\cpp\\bin\\apihub.exe", "E:\\finally-main\\cpp\\bin","apihub.exe", NULL,NULL,
    P_HTTP, "127.0.0.1:8090/health", 0, 25, 3, 600, NULL,0,0,0,0,0,0,0,0,false,false,false,"" },            // 接口服务: HTTP /health 探活, 预热25秒, 内存上限600MB
  { "tphub","止盈引擎",   K_PROC, "E:\\finally-main\\cpp\\bin\\tphub.exe",  "E:\\finally-main\\cpp\\bin","tphub.exe",  NULL,NULL,
    P_FILE, "E:\\datas\\log\\hb_tphub.txt", 60, 60, 3, 400, NULL,0,0,0,0,0,0,0,0,false,false,false,"" },    // 止盈引擎: 心跳文件探活, 内存上限400MB
  { "tradehub","交易中枢(C++)", K_PROC, "E:\\finally-main\\cpp\\bin\\tradehub.exe","E:\\finally-main\\cpp\\bin","tradehub.exe",NULL,NULL,
    P_FILE, "E:\\datas\\log\\hb_tradehub.txt", 60, 300, 3, 800, NULL,0,0,0,0,0,0,0,0,false,false,false,"" }, // 交易中枢: 心跳探活, 预热300秒(开盘前初始化长), 内存上限800MB
  { "apache","网页服务(Apache)", K_SVC, NULL,NULL,NULL,NULL,"Apache2.4",
    P_HTTP, "127.0.0.1:80/health_probe", 0, 0, 6, 0, NULL,0,0,0,0,0,0,0,0,false,false,false,"" },           // Apache 服务: HTTP 80 端口探活, 连败6次才重启, 不监控内存
  { "mariadb","数据库(MariaDB)", K_SVC, NULL,NULL,NULL,NULL,"MariaDB",
    P_MYSQL, "127.0.0.1:3306", 0, 0, 6, 0, NULL,0,0,0,0,0,0,0,0,false,false,false,"" },                     // MariaDB 服务: MySQL 握手探活, 连败6次才重启
};
const int g_tgN = (int)(sizeof(g_tg) / sizeof(g_tg[0]));   // 目标个数(6), 供各处循环使用

static const char* g_fixTasks[] = {                  // 配置自愈覆盖的计划任务清单
    "finally_fill1m", "finally_apihub", "finally_tphub", "finally_guard",
    // finally_phpengine / finally_phpfill 已按"去PHP"指令停用, 不再纳入配置自愈   —— 原任务列表备注
};
static const int g_fixTaskN = (int)(sizeof(g_fixTasks) / sizeof(g_fixTasks[0]));   // 自愈任务个数(4)
static const char* g_fixSvcs[] = { "Apache2.4", "MariaDB" };   // 配置自愈覆盖的 Windows 服务清单
static const int g_fixSvcN = (int)(sizeof(g_fixSvcs) / sizeof(g_fixSvcs[0]));      // 自愈服务个数(2)

// 全局体检结果(供状态上报)
static struct {                                      // g_report: 聚合体检结果, write_status 打包成 JSON
    bool     tsOk = false;         // 计划任务 COM 可用   // ts_init 成功与否, 失败则任务类目标只读
    bool     poolProbed = false;   // apihub 是否已探过(避免启动期误报 DB 异常)  // 尚未探到 apihub 前不报 DB 异常
    long long klineLagMin = -1;    // ETH 5m 最新K线滞后(分钟)  // 由 datahub 心跳文件年龄粗略推断
    int      poolCount = 0;        // apihub /health 报的池内合约数(>0 = DB 可查)  // 端到端证明 MySQL 可查
    int      okxRl = 0;            // OKX 限频累计次数   // 累计检测到限频的次数
    int      okxRun = 0;           // 连续限频           // 当前连续限频次数(恢复后清零)
    int      okxMs = 0;            // OKX 往返毫秒       // 最近一次 OKX 探活的网络往返耗时
    std::string okxMsg = "未检测";                       // 最近一次 OKX 探活的描述文本
    bool     okxBad = false;                            // OKX 当前是否处于限频/异常状态
    std::string issues;            // 当前异常汇总(给前端)  // 主循环每轮重建的异常清单字符串
} g_report;

// --------------------------------------------------------------------------
// 小工具
// --------------------------------------------------------------------------
static std::string lower(std::string s) { for (auto& c : s) c = (char)tolower((unsigned char)c); return s; }   // 字符串转小写(进程名/路径比较用)
static std::wstring widen(const char* s) {           // ANSI 窄字符串 → 宽字符串(COM 接口都是 wchar)
    int n = MultiByteToWideChar(CP_ACP, 0, s, -1, NULL, 0);   // 先算转换后所需字符数(含结尾 0)
    std::wstring w(n ? n - 1 : 0, L'\0');            // 按实际长度(去掉结尾 0)分配缓冲
    if (n) MultiByteToWideChar(CP_ACP, 0, s, -1, &w[0], n);   // 真正执行转换写入缓冲
    return w;                                        // 返回宽字符串
}
static std::string jesc(const std::string& s) {      // JSON 字符串转义: 让 msg 可安全嵌入 JSON
    std::string o;                                   // 输出缓冲
    for (char c : s) {                               // 逐字符处理
        if (c == '"' || c == '\\') { o += '\\'; o += c; }   // 引号和反斜杠前加转义符
        else if (c == '\n') o += "\\n";              // 换行符转成 \n
        else if ((unsigned char)c < 0x20) o += ' ';  // 其他不可见控制字符替换为空格
        else o += c;                                 // 普通字符原样保留
    }
    return o;                                        // 返回转义后的字符串
}

DWORD find_pid(const char* proc, const char* exePath) {   // 按"进程名+exe 全路径"精确查找 PID
    HANDLE snap = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);   // 创建全系统进程快照
    if (snap == INVALID_HANDLE_VALUE) return 0;      // 快照创建失败返回 0(未找到)
    PROCESSENTRY32W pe; pe.dwSize = sizeof(pe);      // 快照遍历条目, 必须先设 dwSize
    std::string want = lower(proc), wantExe = lower(exePath);   // 目标进程名与路径都转小写做不区分大小写比较
    DWORD found = 0;                                 // 结果 PID, 0=未找到
    if (Process32FirstW(snap, &pe)) {                // 取第一个进程条目
        do {                                         // 遍历所有进程
            char nm[128]; WideCharToMultiByte(CP_ACP, 0, pe.szExeFile, -1, nm, sizeof(nm), NULL, NULL);   // 宽字符进程名转 ANSI
            if (lower(nm) != want) continue;         // 进程名不匹配则跳过(先粗筛)
            HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pe.th32ProcessID);   // 打开进程查完整路径
            if (hp) {                                // 打开成功才做路径精筛
                char buf[MAX_PATH * 2] = { 0 }; DWORD len = sizeof(buf) - 1;   // 路径缓冲及长度
                bool match = true;                   // 默认视为匹配(路径查询失败时不误杀)
                if (QueryFullProcessImageNameA(hp, 0, buf, &len)) match = (lower(buf) == wantExe);   // 查得到就用完整路径精确比对
                CloseHandle(hp);                     // 关闭进程句柄
                if (match) { found = pe.th32ProcessID; break; }   // 命中: 记录 PID 并结束遍历
            }
        } while (Process32NextW(snap, &pe));         // 继续下一条目
    }
    CloseHandle(snap);                               // 关闭快照句柄
    return found;                                    // 返回找到的 PID(或 0)
}

static long long file_age_sec(const char* path) {    // 计算文件最后修改时间距今的秒数(心跳文件判卡死用)
    WIN32_FILE_ATTRIBUTE_DATA fa;                    // 文件属性结构(含最后写入时间)
    if (!GetFileAttributesExA(path, GetFileExInfoStandard, &fa)) return -1;   // 文件不存在返回 -1
    FILETIME now; GetSystemTimeAsFileTime(&now);     // 取当前系统时间(100ns 单位)
    ULARGE_INTEGER a, b;                             // 用 64 位联合方便做减法
    a.LowPart = fa.ftLastWriteTime.dwLowDateTime; a.HighPart = fa.ftLastWriteTime.dwHighDateTime;   // a = 文件最后写入时间
    b.LowPart = now.dwLowDateTime;                b.HighPart = now.dwHighDateTime;                   // b = 当前时间
    if (b.QuadPart <= a.QuadPart) return 0;          // 文件时间在未来/刚写 → 年龄 0
    return (long long)((b.QuadPart - a.QuadPart) / 10000000ULL);   // 差值从 100ns 换算成秒
}

// HTTP 探活(本机明文; 有响应即视为"没卡死", 但过慢算卡死前兆)
static bool http_probe(const char* spec, int timeoutMs, long long* outMs, std::string* why, std::string* body = nullptr) {   // 解析 "host:port/path" 并发 GET 请求
    std::string s = spec;                            // 探活参数字符串
    size_t c1 = s.find(':'), c2 = s.find('/');       // 冒号分割 host:port, 斜杠开始 path
    if (c1 == std::string::npos || c2 == std::string::npos) return false;   // 格式非法直接判失败
    std::string host = s.substr(0, c1);              // 提取主机(如 127.0.0.1)
    int port = atoi(s.substr(c1 + 1, c2 - c1 - 1).c_str());   // 提取端口(如 8090)
    std::string path = s.substr(c2);                 // 提取路径(如 /health)
    SOCKET sk = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP);   // 创建 TCP socket
    if (sk == INVALID_SOCKET) { if (why) *why = "socket 创建失败"; return false; }   // 创建失败, 记录原因
    DWORD tmo = (DWORD)timeoutMs;                    // 超时毫秒数转 DWORD
    setsockopt(sk, SOL_SOCKET, SO_RCVTIMEO, (char*)&tmo, sizeof(tmo));   // 设置接收超时
    setsockopt(sk, SOL_SOCKET, SO_SNDTIMEO, (char*)&tmo, sizeof(tmo));   // 设置发送超时
    sockaddr_in a{}; a.sin_family = AF_INET; a.sin_port = htons((u_short)port);   // 组装 IPv4 目标地址(端口转网络序)
    a.sin_addr.s_addr = inet_addr(host.c_str());     // 点分 IP 转二进制(仅支持本机 IP 场景)
    long long t0 = GetTickCount64();                 // 记录起始时刻, 用于测往返耗时
    bool ok = false;                                 // 探活结果, 默认失败
    std::string all;                                 // 累积响应正文
    if (connect(sk, (sockaddr*)&a, sizeof(a)) == 0) {   // TCP 连接成功(端口有监听)
        std::string req = "GET " + path + " HTTP/1.0\r\nHost: " + host + "\r\nConnection: close\r\n\r\n";   // 构造极简 HTTP/1.0 GET 请求
        if (send(sk, req.c_str(), (int)req.size(), 0) > 0) {   // 请求发送成功
            char buf[2048]; int n = 0;               // 接收缓冲与本次读取字节数
            while ((n = recv(sk, buf, sizeof(buf), 0)) > 0) { all.append(buf, n); if (all.size() > 65536) break; }   // 循环读响应直到对端关闭或超 64KB
            if (all.find("HTTP/") != std::string::npos) {   // 响应里出现 HTTP 状态行 → 服务还活着
                ok = true;                           // 判定健康
                if (why) why->clear();               // 清空失败原因
            } else if (why) *why = "无 HTTP 响应(卡死或端口无服务)";   // 连上了但响应不是 HTTP → 疑似卡死
        } else if (why) *why = "请求发送失败";       // send 失败的原因记录
    } else if (why) *why = "TCP 连接被拒(进程在但没监听)";   // connect 失败: 进程在但服务没起来
    if (outMs) *outMs = (long long)(GetTickCount64() - t0);   // 输出往返耗时
    if (body) *body = all;                           // 输出响应正文(调用方可能解析)
    closesocket(sk);                                 // 关闭 socket
    return ok;                                       // 返回探活结果
}

// MySQL 握手探活: 连上后必须收到服务端 greeting(证明不是"端口在但数据库死了")
static bool mysql_probe(const char* spec, int timeoutMs, std::string* why) {   // 解析 "host:port" 并验证 MySQL 握手包
    std::string s = spec;                            // 探活参数
    size_t c = s.find(':');                          // 冒号分割 host 与 port
    if (c == std::string::npos) return false;        // 格式非法判失败
    std::string host = s.substr(0, c);               // 主机
    int port = atoi(s.substr(c + 1).c_str());        // 端口(3306)
    SOCKET sk = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP);   // 创建 TCP socket
    if (sk == INVALID_SOCKET) { if (why) *why = "socket 创建失败"; return false; }   // 创建失败
    DWORD tmo = (DWORD)timeoutMs;                    // 超时毫秒
    setsockopt(sk, SOL_SOCKET, SO_RCVTIMEO, (char*)&tmo, sizeof(tmo));   // 只需设置接收超时(等服务端 greeting)
    sockaddr_in a{}; a.sin_family = AF_INET; a.sin_port = htons((u_short)port);   // 目标地址
    a.sin_addr.s_addr = inet_addr(host.c_str());     // IP 转二进制
    bool ok = false;                                 // 结果默认失败
    if (connect(sk, (sockaddr*)&a, sizeof(a)) == 0) {   // TCP 连接成功
        char buf[256] = { 0 };                       // greeting 包缓冲
        int n = recv(sk, buf, sizeof(buf) - 1, 0);   // 等待服务端主动发来的握手包
        if (n > 5 && (unsigned char)buf[0] >= 8) ok = true;   // MySQL greeting: 长度>5 且协议版本>=8
        else if (why) *why = "连接上了但收不到 MySQL 握手(数据库卡死/连接数满)";   // 端口在但 DB 不干活 → 判死
    } else if (why) *why = "TCP 连接被拒(MySQL 未监听)";   // 连接失败原因
    closesocket(sk);                                 // 关闭 socket
    return ok;                                       // 返回结果
}

// OKX 探活(WinHTTP): 返回 0=正常 1=限频 -1=网络错误
int okx_probe(std::string* msg, int* ms) {           // 请求 OKX 公共时间接口, 判断限频/网络状况
    long long t0 = GetTickCount64();                 // 起始时刻, 用于统计往返耗时
    HINTERNET s = WinHttpOpen(L"finally_guard/3.0", WINHTTP_ACCESS_TYPE_DEFAULT_PROXY,   // 初始化 WinHTTP 会话(UA 标识)
                              WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, 0);   // 使用默认代理解析
    int r = -1;                                      // 返回值默认 -1(网络错误)
    if (s) {                                         // 会话创建成功才继续
        WinHttpSetTimeouts(s, 4000, 4000, 4000, 6000);   // 解析/连接/发送 4 秒, 接收 6 秒超时
        HINTERNET cn = WinHttpConnect(s, L"www.okx.com", INTERNET_DEFAULT_HTTPS_PORT, 0);   // 连接 OKX 官网 443 端口
        if (cn) {                                    // 连接成功
            HINTERNET rq = WinHttpOpenRequest(cn, L"GET", L"/api/v5/public/time", nullptr,   // 请求公共时间接口(无需鉴权)
                                              WINHTTP_NO_REFERER, WINHTTP_DEFAULT_ACCEPT_TYPES, WINHTTP_FLAG_SECURE);   // HTTPS 标志
            if (rq) {                                // 请求对象创建成功
                if (WinHttpSendRequest(rq, WINHTTP_NO_ADDITIONAL_HEADERS, 0, WINHTTP_NO_REQUEST_DATA, 0, 0, 0) &&   // 发送请求
                    WinHttpReceiveResponse(rq, nullptr)) {   // 并接收响应
                    DWORD st = 0, sz = sizeof(st);   // HTTP 状态码及其大小
                    WinHttpQueryHeaders(rq, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,   // 以数字形式取状态码
                                        WINHTTP_HEADER_NAME_BY_INDEX, &st, &sz, WINHTTP_NO_HEADER_INDEX);
                    std::string body;                // 响应正文缓冲
                    char buf[1024]; DWORD rd = 0;    // 读取缓冲与实读字节数
                    while (WinHttpReadData(rq, buf, sizeof(buf), &rd) && rd) { body.append(buf, rd); rd = 0; if (body.size() > 8192) break; }   // 循环读正文, 上限 8KB
                    if (st == 429) { r = 1; *msg = "HTTP 429 限频"; }   // 429 = 明确限频
                    else if (body.find("50011") != std::string::npos) { r = 1; *msg = "50011 请求过快(限频)"; }   // OKX 业务码 50011 = 请求过快
                    else if (body.find("50013") != std::string::npos) { r = 1; *msg = "50013 系统繁忙"; }         // OKX 业务码 50013 = 系统繁忙
                    else if (st == 200 && body.find("\"code\":\"0\"") != std::string::npos) { r = 0; msg->clear(); }   // 200 且业务码 0 = 完全正常
                    else { r = -1; *msg = "HTTP " + std::to_string(st) + " 异常响应"; }   // 其他情况归为网络/服务异常
                } else *msg = "请求失败(WinHTTP)";   // 发送或接收失败
                WinHttpCloseHandle(rq);              // 释放请求句柄
            } else *msg = "OpenRequest 失败";        // 请求对象创建失败
            WinHttpCloseHandle(cn);                  // 释放连接句柄
        } else *msg = "连接 okx.com 失败";           // 连接失败(网络断/DNS 故障)
        WinHttpCloseHandle(s);                       // 释放会话句柄
    } else *msg = "WinHttpOpen 失败";                // 会话初始化失败
    if (ms) *ms = (int)(GetTickCount64() - t0);      // 输出往返耗时
    return r;                                        // 返回 0/1/-1
}

// --------------------------------------------------------------------------
// 计划任务(COM)
// --------------------------------------------------------------------------
static ITaskService* g_ts = nullptr;                 // Task Scheduler 服务 COM 对象(ts_init 初始化)
bool ts_init() {                                     // 初始化 COM 并连接本机任务计划程序
    HRESULT hr = CoInitializeEx(NULL, COINIT_MULTITHREADED);   // 初始化 COM(多线程模型)
    if (FAILED(hr) && hr != RPC_E_CHANGED_MODE) return false;  // 除"模式已变"外的失败都算失败
    hr = CoCreateInstance(CLSID_TaskScheduler, NULL, CLSCTX_INPROC_SERVER, IID_ITaskService, (void**)&g_ts);   // 创建 TaskScheduler COM 实例
    if (FAILED(hr) || !g_ts) return false;           // 实例创建失败
    if (FAILED(g_ts->Connect(_variant_t(), _variant_t(), _variant_t(), _variant_t()))) {   // 连接本机任务服务(全部默认参数)
        g_ts->Release(); g_ts = nullptr; return false;   // 连接失败则释放并置空
    }
    return true;                                     // 初始化成功, g_ts 全局可用
}
static IRegisteredTask* ts_get(const char* name) {   // 按名称取根目录下的计划任务对象
    if (!g_ts) return nullptr;                       // 未初始化直接返回空
    ITaskFolder* root = nullptr;                     // 任务文件夹对象
    if (FAILED(g_ts->GetFolder(_bstr_t(L"\\"), &root)) || !root) return nullptr;   // 取根文件夹失败
    IRegisteredTask* t = nullptr;                    // 任务对象
    root->GetTask(_bstr_t(widen(name).c_str()), &t); // 按名称取任务(找不到时 t 保持空)
    root->Release();                                 // 释放文件夹对象
    return t;                                        // 返回任务对象(可能为 nullptr)
}
bool task_running(const char* name, bool* exists) {  // 查询任务是否处于运行态
    IRegisteredTask* t = ts_get(name);               // 先取任务对象
    if (!t) { if (exists) *exists = false; return false; }   // 任务不存在: exists=false, 未运行
    if (exists) *exists = true;                      // 任务存在
    TASK_STATE st = TASK_STATE_UNKNOWN;              // 任务状态
    t->get_State(&st);                               // 读取当前状态
    t->Release();                                    // 释放任务对象
    return st == TASK_STATE_RUNNING;                 // 仅 RUNNING 态视为"在运行"
}
HRESULT task_run2(const char* name) {                // 强制启动计划任务
    IRegisteredTask* t = ts_get(name);               // 取任务对象
    if (!t) return E_FAIL;                           // 任务不存在返回失败
    IRunningTask* rt = nullptr;                      // Run 返回的运行实例对象
    HRESULT hr = t->Run(_variant_t(), &rt);          // 立即执行一次(空参数)
    if (rt) rt->Release();                           // 释放运行实例
    t->Release();                                    // 释放任务对象
    return hr;                                       // 返回 HRESULT
}
HRESULT task_stop(const char* name) {                // 强制停止计划任务
    IRegisteredTask* t = ts_get(name);               // 取任务对象
    if (!t) return E_FAIL;                           // 不存在返回失败
    HRESULT hr = t->Stop(0);                         // 停止任务(0=全部实例)
    t->Release();                                    // 释放任务对象
    return hr;                                       // 返回 HRESULT
}

// --------------------------------------------------------------------------
// Windows 服务
// --------------------------------------------------------------------------
bool svc_state(const char* name, DWORD* state) {     // 查询服务当前状态
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_CONNECT);   // 连接 SCM(仅需连接权限)
    if (!scm) return false;                          // 连接失败
    SC_HANDLE svc = OpenServiceA(scm, name, SERVICE_QUERY_STATUS);   // 打开服务(查询状态权限)
    bool ok = false;                                 // 结果
    if (svc) {                                       // 服务打开成功
        SERVICE_STATUS st{};                         // 状态结构
        if (QueryServiceStatus(svc, &st)) { *state = st.dwCurrentState; ok = true; }   // 读取当前状态输出
        CloseServiceHandle(svc);                     // 关闭服务句柄
    }
    CloseServiceHandle(scm);                         // 关闭 SCM 句柄
    return ok;                                       // 返回是否查询成功
}
bool svc_start(const char* name) {                   // 启动服务
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_ALL_ACCESS);   // 打开 SCM(需全部权限)
    if (!scm) return false;                          // 失败(通常是权限不足)
    SC_HANDLE svc = OpenServiceA(scm, name, SERVICE_START | SERVICE_QUERY_STATUS);   // 打开服务(启动+查询权限)
    bool ok = false;                                 // 结果
    if (svc) {                                       // 服务打开成功
        if (StartServiceA(svc, 0, NULL)) ok = true;  // 启动成功
        else if (GetLastError() == ERROR_SERVICE_ALREADY_RUNNING) ok = true;   // "已在运行"也视为成功
        CloseServiceHandle(svc);                     // 关闭服务句柄
    }
    CloseServiceHandle(scm);                         // 关闭 SCM 句柄
    return ok;                                       // 返回结果
}
bool svc_restart(const char* name) {                 // 重启服务(停止→等停→再启动)
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_ALL_ACCESS);   // 打开 SCM
    if (!scm) return false;                          // 失败
    SC_HANDLE svc = OpenServiceA(scm, name, SERVICE_STOP | SERVICE_START | SERVICE_QUERY_STATUS);   // 打开服务(停/启/查权限)
    bool ok = false;                                 // 结果
    if (svc) {                                       // 服务打开成功
        SERVICE_STATUS st{};                         // 状态结构
        ControlService(svc, SERVICE_CONTROL_STOP, &st);   // 下发停止命令
        for (int i = 0; i < 40; i++) {               // 最多等 20 秒让它停完
            SERVICE_STATUS s2{};                     // 查询用状态结构
            if (!QueryServiceStatus(svc, &s2) || s2.dwCurrentState == SERVICE_STOPPED) break;   // 已停/查不到就结束等待
            Sleep(500);                              // 每 500ms 轮询一次
        }
        ok = svc_start(name);                        // 停完后再拉起来
        CloseServiceHandle(svc);                     // 关闭服务句柄
    }
    CloseServiceHandle(scm);                         // 关闭 SCM 句柄
    return ok;                                       // 返回结果
}

// --------------------------------------------------------------------------
// 探测
// --------------------------------------------------------------------------
bool probe(Target& t, std::string& why, std::string* body) {   // 按目标探活方式分派到具体探测器
    switch (t.probe) {                               // 依 ProbeKind 分派
    case P_FILE: {                                   // 第一级: 心跳文件新鲜度
        long long age = file_age_sec(t.probeArg);    // 心跳文件距今秒数
        if (age < 0) { why = std::string("心跳文件不存在: ") + t.probeArg; return false; }   // 文件不存在 → 从未运行或被删
        if (age > t.staleSec) {                      // 超过卡死阈值
            char b[128]; snprintf(b, sizeof(b), "卡死: 心跳停更 %lld 秒(阈值%d)", age, t.staleSec);   // 拼装原因
            why = b; return false;                   // 判死
        }
        return true;                                 // 心跳新鲜 → 健康
    }
    case P_HTTP: {                                   // 第二级: HTTP 实际可用性
        long long ms = 0;                            // 往返耗时
        bool ok = http_probe(t.probeArg, 4000, &ms, &why, body);   // 4 秒超时发 GET
        if (ok && ms > 3000) { char b[96]; snprintf(b, sizeof(b), "响应过慢 %lldms(>3000ms)", ms); why = b; return false; }   // 通了但超 3 秒 → 卡死前兆, 也算失败
        return ok;                                   // 返回结果
    }
    case P_MYSQL:                                    // 第二级: MySQL 实际可用性
        return mysql_probe(t.probeArg, 3000, &why);  // 3 秒超时验证握手
    default:                                         // P_NONE 或未知
        return true;                                 // 不探活 = 视为健康
    }
}

static bool mem_runaway(Target& t) {                 // 内存失控看门狗: 超上限×2 判失控
    if (!t.memLimitMB || !t.pid) return false;       // 未启用内存监控或无 PID 时跳过
    HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, t.pid);   // 打开进程查内存
    if (!hp) return false;                           // 打不开(已退出)不判失控
    PROCESS_MEMORY_COUNTERS pmc{}; pmc.cb = sizeof(pmc);   // 内存计数结构(需先设 cb)
    bool bad = false;                                // 是否失控
    if (GetProcessMemoryInfo(hp, &pmc, sizeof(pmc))) {   // 读取成功
        SIZE_T mb = pmc.WorkingSetSize / (1024 * 1024);   // 工作集换算成 MB
        if ((int)mb > t.memLimitMB * 2) bad = true;  // 超过上限 2 倍 → 判内存失控
        else if ((int)mb > t.memLimitMB) {           // 超上限但未到 2 倍 → 只告警不杀
            char b[160]; snprintf(b, sizeof(b), "[%s] 内存偏高 %lluMB (上限%dMB)",   // 拼告警日志
                                  t.key, (unsigned long long)mb, t.memLimitMB);
            logline(b);                              // 写日志
        }
    }
    CloseHandle(hp);                                 // 关闭进程句柄
    return bad;                                      // 返回是否失控
}

// --------------------------------------------------------------------------
// 动作
// --------------------------------------------------------------------------

// 找出所有同名同路径的进程(用于清理"双开")
static std::vector<DWORD> find_all_pids(const char* proc, const char* exePath) {   // 枚举全部同名同路径进程 PID
    std::vector<DWORD> out;                          // 结果集
    HANDLE snap = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);   // 全系统进程快照
    if (snap == INVALID_HANDLE_VALUE) return out;    // 快照失败返回空
    PROCESSENTRY32W pe; pe.dwSize = sizeof(pe);      // 遍历条目
    std::string want = lower(proc), wantExe = lower(exePath);   // 目标名与路径小写化
    if (Process32FirstW(snap, &pe)) {                // 取第一条
        do {                                         // 遍历全部进程
            char nm[128]; WideCharToMultiByte(CP_ACP, 0, pe.szExeFile, -1, nm, sizeof(nm), NULL, NULL);   // 进程名转 ANSI
            if (lower(nm) != want) continue;         // 名字不匹配跳过
            HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pe.th32ProcessID);   // 打开进程验证路径
            if (!hp) continue;                       // 打不开的跳过
            char buf[MAX_PATH * 2] = { 0 }; DWORD len = sizeof(buf) - 1;   // 路径缓冲
            if (QueryFullProcessImageNameA(hp, 0, buf, &len) && lower(buf) == wantExe)   // 完整路径匹配才收录
                out.push_back(pe.th32ProcessID);     // 加入结果集
            CloseHandle(hp);                         // 关闭句柄
        } while (Process32NextW(snap, &pe));         // 下一条
    }
    CloseHandle(snap);                               // 关闭快照
    return out;                                      // 返回全部匹配 PID
}

// 双开清理: 同一 exe 只允许一个实例(双开会同时写库+抢 OKX 限频配额)
static void kill_duplicates(Target& t) {             // 发现并清理重复实例
    if (t.kind != K_PROC) return;                    // 只对进程型目标生效
    std::vector<DWORD> v = find_all_pids(t.proc, t.exe);   // 找出全部实例
    if (v.size() < 2) return;                        // 只有一个(或零个)无需清理
    DWORD keep = 0;                                  // 要保留的 PID
    for (DWORD pid : v) if (pid == t.pid) keep = pid;   // 优先保留 guard 正托管的那只
    if (!keep) {                       // 我们托管的那只不在了 → 保留启动最早的一只
        ULONGLONG best = 0;                          // 最早创建时间
        for (DWORD pid : v) {                        // 遍历所有实例
            HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pid);   // 打开进程查创建时间
            if (!hp) continue;                       // 打不开跳过
            FILETIME c, e, k, u;                     // 创建/退出/内核/用户时间(只关心创建)
            if (GetProcessTimes(hp, &c, &e, &k, &u)) {   // 读取成功
                ULONGLONG t0 = ((ULONGLONG)c.dwHighDateTime << 32) | c.dwLowDateTime;   // 创建时间拼成 64 位
                if (!best || t0 < best) { best = t0; keep = pid; }   // 记录最早启动的实例
            }
            CloseHandle(hp);                         // 关闭句柄
        }
    }
    for (DWORD pid : v) {                            // 遍历全部实例
        if (pid == keep) continue;                   // 跳过要保留的
        HANDLE hp = OpenProcess(PROCESS_TERMINATE | PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pid);   // 打开重复实例(需终止权限)
        if (!hp) continue;                           // 打不开跳过
        char b[192];                                 // 日志缓冲
        snprintf(b, sizeof(b), "[%s] 发现重复实例 pid=%lu (保留 %lu) → 已清理", t.key, (unsigned long)pid, (unsigned long)keep);   // 拼日志
        logline(b);                                  // 记录清理动作
        TerminateProcess(hp, 1);                     // 强杀重复实例(退出码 1)
        CloseHandle(hp);                             // 关闭句柄
    }
}

static bool proc_alive(Target& t) {                  // 检查目标进程是否存活(含"接手外部实例"逻辑)
    if (t.h) {                                       // 有自己拉起的子进程句柄
        if (WaitForSingleObject(t.h, 0) != WAIT_OBJECT_0) return true;   // 立即等待未返回 → 子进程还活着
        // 自己拉起的子进程已退出: 先按"名称+全路径"重找 —— 计划任务/手动启动的另一个实例
        // 才是真正在跑的那个(子进程因单例检查立刻 exit 0), 直接接手, 否则会无限重启风暴
        CloseHandle(t.h); t.h = NULL;                // 清掉已退出的句柄
    }
    DWORD p = find_pid(t.proc, t.exe);               // 按名+路径全系统重找
    if (p) {                                         // 找到在跑的实例
        if (t.pid != p) {                            // PID 变了 = 接手了新实例
            char b[160]; snprintf(b, sizeof(b), "[%s] 接手已运行进程 pid=%lu", t.key, (unsigned long)p);   // 拼日志
            logline(b);                              // 记录接手
            t.startTick = GetTickCount();            // 重置计时(预热期重新计算)
        }
        t.pid = p;                                   // 更新托管 PID
        return true;                                 // 存活
    }
    return false;                                    // 全系统都找不到 → 确实死了
}
static bool spawn(Target& t) {                       // 拉起目标进程
    STARTUPINFOA si{}; si.cb = sizeof(si);           // 启动信息结构(必须先设 cb)
    PROCESS_INFORMATION pi{};                        // 收进程/线程句柄与 ID
    char cmd[MAX_PATH * 2];                          // 命令行缓冲
    snprintf(cmd, sizeof(cmd), "\"%s\"", t.exe);     // 命令行 = 带引号的 exe 路径(无参数)
    if (!CreateProcessA(t.exe, cmd, NULL, NULL, FALSE, CREATE_NO_WINDOW | CREATE_NEW_PROCESS_GROUP,   // 创建进程: 不弹窗口+独立进程组
                        NULL, t.workdir, &si, &pi)) {   // 工作目录用配置值
        char b[224]; snprintf(b, sizeof(b), "[%s] 拉取失败 err=%lu", t.key, GetLastError());   // 拼失败日志(含错误码)
        logline(b); return false;                    // 记录并返回失败
    }
    if (t.h) CloseHandle(t.h);                       // 若有旧句柄先关掉
    CloseHandle(pi.hThread);                         // 主线程句柄用不到, 立即关闭
    t.h = pi.hProcess; t.pid = pi.dwProcessId;       // 保存进程句柄与 PID
    t.startTick = t.lastProbeTick = GetTickCount();  // 重置启动与探活计时(重新计预热期)
    t.failN = t.memN = 0; t.restarts++;              // 清零失败计数, 累加重启次数
    char b[224];                                     // 日志缓冲
    snprintf(b, sizeof(b), "[%s] 已拉起 pid=%lu (第%d次守护重启)", t.key, (unsigned long)t.pid, t.restarts);   // 拼日志
    logline(b);                                      // 记录拉起
    return true;                                     // 成功
}
static std::string exit_reason(DWORD code) {         // 把进程退出码翻译成人类可读的原因
    switch (code) {                                  // 常见退出码映射
    case 0:          return "正常退出(0)";           // 0 = 程序自己正常结束
    case 1:          return "被强杀(TerminateProcess/taskkill)";   // 1 = 本守护强杀的惯用码
    case 0xC0000005: return "内存越界崩溃(0xC0000005)";             // 访问违例
    case 0xC0000409: return "栈溢出/快速失败(0xC0000409)";          // __fastfail/栈保护
    case 0xC000013A: return "控制台被关闭/中断(0xC000013A)";        // Ctrl+C 或控制台关闭
    case 0xC0000374: return "堆损坏(0xC0000374)";                   // heap corruption
    case 0xC0000017: return "内存不足(0xC0000017)";                 // 分配失败
    case 0x40010004: return "会话被注销(0x40010004) ★用户退出登录把进程带走";   // 用户注销导致(诊断重点)
    }
    char b[64]; snprintf(b, sizeof(b), "退出码 0x%08lX", (unsigned long)code);   // 未列出的码原样输出
    return b;                                        // 返回描述
}
static void kill_proc(Target& t, const char* why) {  // 判死后的强杀动作
    char b[256]; snprintf(b, sizeof(b), "[%s] 判定卡死(%s) → 强制重启", t.key, why);   // 拼日志
    logline(b);                                      // 记录强杀决策
    if (t.h) {                                       // 有自己托管的句柄
        TerminateProcess(t.h, 1);                    // 直接强杀(退出码 1)
        WaitForSingleObject(t.h, 3000);              // 最多等 3 秒确认退出
        CloseHandle(t.h); t.h = NULL;                // 关闭并清空句柄
    } else {                                         // 外部实例(只有 PID)
        HANDLE hp = OpenProcess(PROCESS_TERMINATE, FALSE, t.pid);   // 按 PID 打开(需终止权限)
        if (hp) { TerminateProcess(hp, 1); WaitForSingleObject(hp, 3000); CloseHandle(hp); }   // 强杀并等待、关闭
    }
    t.pid = 0; t.failN = 0; t.nextTryTick = 0;       // 清空 PID/失败计数, 立即允许重新拉起
}

// --------------------------------------------------------------------------
// 配置自愈
// --------------------------------------------------------------------------
// ISO8601 时长 → 秒 (PT1M=60, PT30S=30, PT1H=3600, P1D=86400)
static int iso_secs(const wchar_t* s) {              // 解析 ISO8601 时长字符串为秒数
    if (!s) return 0;                                // 空串返回 0
    long long v = 0; int unit = 0;                   // 数值与单位倍率
    for (const wchar_t* p = s; *p; ++p) {            // 逐字符解析
        if (*p >= L'0' && *p <= L'9') {              // 数字段累积
            v = v * 10 + (*p - L'0');                // 十进制累加
            if (v > 1000000000LL) return 0;          // 防溢出: 超大数值直接放弃
        } else if (*p == L'S') { unit = 1;     break; }   // S 结尾 = 秒
        else if (*p == L'M')   { unit = 60;    break; }   // M = 分钟
        else if (*p == L'H')   { unit = 3600;  break; }   // H = 小时
        else if (*p == L'D')   { unit = 86400; break; }   // D = 天
        else if (*p == L'T' || *p == L'P') continue;      // 前缀字符跳过
        else return 0;                               // 非法字符放弃解析
    }
    return (int)(v * unit);                          // 数值×倍率 = 秒
}
// 触发器自愈: 守护型任务必须"只启动一次、常驻运行"。
//   历史遗留: finally_* 任务带 <Repetition><Interval>PT1M</Interval> → 每 60 秒
//   拉起一个"幽灵实例"(被单例检查挡回后立刻自杀), 白烧 CPU 且与 guard 抢拉起。
//   这里把 TimeTrigger 上的重复间隔清空(空串 = 关闭重复, MSDN 语义)。
static bool clear_task_repetition(ITaskDefinition* def) {   // 清除任务触发器上的重复间隔
    if (!def) return false;                          // 空定义直接返回
    ITriggerCollection* tc = nullptr;                // 触发器集合
    if (FAILED(def->get_Triggers(&tc)) || !tc) return false;   // 取触发器集合失败
    bool chg = false;                                // 是否发生了修改
    LONG n = 0;                                      // 触发器个数
    if (SUCCEEDED(tc->get_Count(&n))) {              // 取个数成功
        for (LONG i = 1; i <= n && i <= 64; i++) {   // COM 集合下标从 1 开始(上限 64 防异常)
            ITrigger* tr = nullptr;                  // 单个触发器
            if (FAILED(tc->get_Item(i, &tr)) || !tr) continue;   // 取失败跳过
            ITimeTrigger* tt = nullptr;              // 时间触发器接口
            if (SUCCEEDED(tr->QueryInterface(IID_ITimeTrigger, (void**)&tt)) && tt) {   // 尝试转成 TimeTrigger
                IRepetitionPattern* rp = nullptr;    // 重复模式接口
                if (SUCCEEDED(tt->get_Repetition(&rp)) && rp) {   // 取重复模式成功
                    BSTR iv = nullptr;               // 间隔字符串
                    if (SUCCEEDED(rp->get_Interval(&iv)) && iv && iv[0]) {   // 存在非空间隔 = 有重复
                        int secs = iso_secs(iv);     // 解析间隔秒数(仅日志展示用)
                        HRESULT hr = rp->put_Interval(_bstr_t(L""));   // 写空串 = 关闭重复(MSDN 语义)
                        char b[192]; snprintf(b, sizeof(b), "[自愈] 触发器重复间隔 %d 秒 → %s(守护任务只需常驻一次)",   // 拼日志
                                              secs, SUCCEEDED(hr) ? "已关闭" : "关闭失败");
                        logline(b);                  // 记录修复动作
                        if (SUCCEEDED(hr)) chg = true;   // 标记有改动需写回
                    }
                    if (iv) SysFreeString(iv);       // 释放 BSTR
                    rp->Release();                   // 释放重复模式
                }
                tt->Release();                       // 释放时间触发器
            }
            tr->Release();                           // 释放基础触发器
        }
    }
    tc->Release();                                   // 释放触发器集合
    return chg;                                      // 返回是否有改动
}
int fixup_tasks() {                                  // 计划任务配置自愈主函数
    int fixedN = 0;                                  // 本轮修复计数
    for (int i = 0; i < g_fixTaskN; i++) {           // 遍历自愈任务清单
        IRegisteredTask* t = ts_get(g_fixTasks[i]);  // 取任务对象
        if (!t) continue;                            // 任务不存在(未安装)跳过
        ITaskDefinition* def = nullptr;              // 任务定义对象
        if (FAILED(t->get_Definition(&def)) || !def) { t->Release(); continue; }   // 取定义失败则跳过
        bool changed = false;                        // 本任务是否有改动
        ITaskSettings* st = nullptr;                 // 任务设置对象
        if (SUCCEEDED(def->get_Settings(&st)) && st) {   // 取设置成功
            VARIANT_BOOL b = VARIANT_FALSE;          // 布尔临时变量
            BSTR s = nullptr;                        // 字符串临时变量
            st->get_Enabled(&b);                     // 读当前启用状态
            if (b == VARIANT_FALSE) { st->put_Enabled(VARIANT_TRUE); changed = true; }   // 被人禁用了 → 强制启用
            if (SUCCEEDED(st->get_ExecutionTimeLimit(&s)) && s) {   // 读执行时限(防"30分钟被杀")
                if (wcscmp(s, L"PT0S") != 0) { st->put_ExecutionTimeLimit(_bstr_t(L"PT0S")); changed = true; }   // 不是 PT0S(无限) → 改为无限运行
                SysFreeString(s); s = nullptr;       // 释放 BSTR
            }
            st->get_DisallowStartIfOnBatteries(&b);  // 读"用电池时禁止启动"
            if (b != VARIANT_FALSE) { st->put_DisallowStartIfOnBatteries(VARIANT_FALSE); changed = true; }   // 强制关闭(服务器也可能有 UPS/电池)
            st->get_StopIfGoingOnBatteries(&b);      // 读"切到电池时停止"
            if (b != VARIANT_FALSE) { st->put_StopIfGoingOnBatteries(VARIANT_FALSE); changed = true; }   // 强制关闭
            IIdleSettings* idle = nullptr;           // 空闲设置接口
            if (SUCCEEDED(st->get_IdleSettings(&idle)) && idle) {   // 取空闲设置成功
                idle->get_StopOnIdleEnd(&b);         // 读"空闲结束即停止"
                if (b != VARIANT_FALSE) { idle->put_StopOnIdleEnd(VARIANT_FALSE); changed = true; }   // 强制关闭(空闲不许杀守护)
                idle->Release();                     // 释放接口
            }
            int rc = 0;                              // 失败重启次数
            st->get_RestartCount(&rc);               // 读当前值
            if (rc < 3) {                            // 没配失败重启(或太弱)
                // 必须 Interval + Count 一起写, 否则任务计划程序不会落 <RestartOnFailure>,
                // 下一轮读回来仍是 0 → 自愈永远"不收敛", 每 10 分钟白重写一次任务定义
                st->put_RestartInterval(_bstr_t(L"PT1M"));   // 失败后 1 分钟重启
                st->put_RestartCount(999);           // 重启次数给足(999≈无限)
                changed = true;                      // 标记改动
            }
        }
        if (st) st->Release();                       // 释放设置对象
        if (clear_task_repetition(def)) changed = true;   // 顺带清触发器重复间隔(防幽灵实例)
        if (changed && g_ts) {                       // 有改动才写回任务定义
            ITaskFolder* root = nullptr;             // 根文件夹
            if (SUCCEEDED(g_ts->GetFolder(_bstr_t(L"\\"), &root)) && root) {   // 取根文件夹成功
                IRegisteredTask* out = nullptr;      // 写回后的任务对象
                HRESULT r = root->RegisterTaskDefinition(_bstr_t(widen(g_fixTasks[i]).c_str()), def,   // 用修改后的定义重新注册(覆盖)
                                                         TASK_CREATE_OR_UPDATE, _variant_t(), _variant_t(),   // 创建或更新, 空凭据
                                                         TASK_LOGON_NONE, _variant_t(L""), &out);   // 任意用户态运行
                if (SUCCEEDED(r)) {                  // 写回成功
                    fixedN++;                        // 计入修复数
                    logline(std::string("[自愈] 任务 ") + g_fixTasks[i] +
                            " 危险配置已修复(无限运行/电池/空闲/失败重启/去重复触发)");   // 记录修复明细
                } else {                             // 写回失败
                    char b[160]; snprintf(b, sizeof(b), "[自愈] 任务 %s 写回失败 hr=0x%08lX", g_fixTasks[i], (unsigned long)r);   // 拼失败日志
                    logline(b);                      // 记录
                }
                if (out) out->Release();             // 释放返回的任务对象
                root->Release();                     // 释放文件夹
            }
        }
        def->Release();                              // 释放任务定义
        t->Release();                                // 释放任务对象
    }
    return fixedN;                                   // 返回修复数
}
int fixup_svcs() {                                   // 服务配置自愈: 确保自启
    int fixedN = 0;                                  // 修复计数
    for (int i = 0; i < g_fixSvcN; i++) {            // 遍历自愈服务清单
        SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_ALL_ACCESS);   // 打开 SCM
        if (!scm) break;                             // 打不开(权限)直接终止全部
        SC_HANDLE svc = OpenServiceA(scm, g_fixSvcs[i], SERVICE_QUERY_CONFIG | SERVICE_CHANGE_CONFIG);   // 打开服务(读/改配置权限)
        if (svc) {                                   // 打开成功
            DWORD need = 0;                          // 配置结构所需字节数
            QueryServiceConfigA(svc, NULL, 0, &need);   // 第一次调用只为取所需大小
            LPQUERY_SERVICE_CONFIGA cfg = (LPQUERY_SERVICE_CONFIGA)malloc(need ? need : 4096);   // 分配缓冲(兜底 4KB)
            if (cfg && QueryServiceConfigA(svc, cfg, need, &need)) {   // 读取配置成功
                if (cfg->dwStartType != SERVICE_AUTO_START) {   // 启动类型不是"自动"
                    if (ChangeServiceConfigA(svc, SERVICE_NO_CHANGE, SERVICE_AUTO_START, SERVICE_NO_CHANGE,   // 改回自动启动(其余不动)
                                             NULL, NULL, NULL, NULL, NULL, NULL, NULL)) {
                        fixedN++;                    // 计入修复数
                        logline(std::string("[自愈] 服务 ") + g_fixSvcs[i] + " 启动类型已改回[自动]");   // 记录修复
                    }
                }
            }
            if (cfg) free(cfg);                      // 释放配置缓冲
            CloseServiceHandle(svc);                 // 关闭服务句柄
        }
        CloseServiceHandle(scm);                     // 关闭 SCM 句柄
    }
    return fixedN;                                   // 返回修复数
}

// --------------------------------------------------------------------------
// 状态上报(前端读取)
// --------------------------------------------------------------------------
static void write_status() {                         // 把全部体检结果写成 guard_status.json(前端健康卡数据源)
    time_t t = time(nullptr); struct tm lt; localtime_s(&lt, &t);   // 当前本地时间
    char ts[32]; strftime(ts, sizeof(ts), "%Y-%m-%d %H:%M:%S", &lt);   // 格式化时间戳

    std::string tj;                                  // targets 数组的 JSON 片段
    for (int i = 0; i < g_tgN; i++) {                // 遍历所有目标
        Target& x = g_tg[i];                         // 当前目标引用
        if (i) tj += ",";                            // 第 2 个起补逗号
        char b[512];                                 // 单目标 JSON 缓冲
        snprintf(b, sizeof(b),                       // 拼单目标对象
                 "{\"key\":\"%s\",\"label\":\"%s\",\"kind\":\"%s\",\"run\":%d,\"ok\":%d,\"pid\":%lu,"
                 "\"restarts\":%d,\"msg\":\"%s\"}",
                 x.key, x.label,                     // 短名/显示名
                 x.kind == K_PROC ? "进程" : (x.kind == K_TASK ? "任务" : "服务"),   // 托管方式中文名
                 x.run ? 1 : 0, (x.run && (x.healthy || !x.probed)) ? 1 : 0, (unsigned long)x.pid,   // 运行/健康(未探过视为健康)/PID
                 x.restarts, jesc(x.msg).c_str());   // 重启次数/状态描述(转义)
        tj += b;                                     // 追加到数组
    }
    std::string js;                                  // 顶层 JSON
    js += "{\"ok\":true,\"ver\":\"guard-v3\",\"ts\":";   // 固定头: 版本与 Unix 时间戳
    js += std::to_string((long long)t);              // 时间戳数值
    js += ",\"time\":\""; js += ts; js += "\"";      // 人类可读时间
    js += ",\"uptime\":" + std::to_string((long long)((GetTickCount64() - g_startTick) / 1000));   // 运行秒数
    js += ",\"targets\":[" + tj + "]";               // 目标数组
    js += ",\"mysql\":{\"pool\":" + std::to_string(g_report.poolCount) +
          ",\"kline_lag_min\":" + std::to_string(g_report.klineLagMin) + "}";   // DB 体检: 池内合约数 + K线滞后
    js += ",\"okx\":{\"rl_total\":" + std::to_string(g_report.okxRl) +
          ",\"rl_run\":" + std::to_string(g_report.okxRun) +
          ",\"ms\":" + std::to_string(g_report.okxMs) +
          ",\"bad\":" + std::string(g_report.okxBad ? "true" : "false") +
          ",\"msg\":\"" + jesc(g_report.okxMsg) + "\"}";   // OKX 体检: 限频统计/耗时/状态
    js += ",\"task_api\":" + std::string(g_report.tsOk ? "true" : "false");   // 计划任务 COM 可用性
    js += ",\"issues\":\"" + jesc(g_report.issues) + "\"}";   // 异常汇总(转义后收尾)

    FILE* f = fopen(STATUSF ".tmp", "w");            // 先写临时文件(避免前端读到半截 JSON)
    if (f) { fwrite(js.data(), 1, js.size(), f); fclose(f); MoveFileExA(STATUSF ".tmp", STATUSF, MOVEFILE_REPLACE_EXISTING); }   // 写完原子改名覆盖正式文件
}

// --------------------------------------------------------------------------
// 主循环
// --------------------------------------------------------------------------
void guard_loop() {                                  // 守护主循环(服务模式与前台模式共用)
    WSADATA wsa; WSAStartup(MAKEWORD(2, 2), &wsa);   // 初始化 WinSock 2.2(HTTP/MySQL 探活需要)
    InitializeCriticalSection(&g_logCs);             // 初始化日志临界区
    g_startTick = GetTickCount();                    // 记录启动时刻
    logline("============ guard v3 启动 ============");   // 启动横幅日志
    logline("托管: 进程[datahub/apihub/tphub] 任务[phpengine/phpfill] 服务[Apache2.4/MariaDB] + OKX限频/MySQL断线体检");   // 托管清单日志
    g_report.tsOk = ts_init();                       // 初始化计划任务 COM
    if (!g_report.tsOk) logline("!! 计划任务接口初始化失败(任务类目标只读)");   // 失败告警(任务类只读)

    for (int i = 0; i < g_tgN; i++) {                // 启动期首轮普查: 接手已在跑的实例
        Target& t = g_tg[i];                         // 当前目标
        if (t.kind == K_PROC) {                      // 进程型
            DWORD pid = find_pid(t.proc, t.exe);     // 按名+路径找已运行实例
            if (pid) {                               // 找到 → 直接接手(不重复拉起)
                t.pid = pid; t.h = NULL;             // 记 PID, 无句柄(非自己拉起)
                t.startTick = GetTickCount();        // 重置启动计时
                t.lastProbeTick = GetTickCount() - PROBE_EVERY + 4000;   // 让首轮探活约 4 秒后就开始
                char b[160]; snprintf(b, sizeof(b), "[%s] 接手已运行进程 pid=%lu", t.key, (unsigned long)pid);   // 拼日志
                logline(b);                          // 记录接手
                t.healthy = true;              // 先用"未知即正常"避免前端误报, 探活失败会立刻转异常
            }
        } else if (t.kind == K_TASK) {               // 任务型
            bool ex = false, run = task_running(t.taskName, &ex);   // 查任务运行态
            if (run) { t.startTick = t.lastProbeTick = GetTickCount(); logline(std::string("[") + t.key + "] 任务已在运行"); }   // 在跑则重置计时并记录
            else if (!ex) logline(std::string("[") + t.key + "] !! 任务不存在, 需人工确认");   // 任务都不存在 → 告警人工介入
        }
    }
    fixup_tasks();                                   // 启动即做一轮计划任务配置自愈
    fixup_svcs();                                    // 启动即做一轮服务配置自愈

    DWORD lastSummary = 0, lastFixup = GetTickCount(), lastOkx = 0, lastStatus = 0;   // 各周期任务的节拍器(汇总/自愈/OKX/状态文件)
    logline("进入主循环");                           // 主循环开始日志
    while (!g_stop) {                                // 直到收到停止信号
        DWORD now = GetTickCount();                  // 本轮起始时刻
        g_report.issues.clear();                     // 重建本轮异常汇总

        for (int i = 0; i < g_tgN; i++) {            // 逐个处理全部目标
            Target& t = g_tg[i];                     // 当前目标
            t.msg.clear();                           // 清空上轮状态文本

            // ---------- 存活/拉起 ----------
            if (t.kind == K_PROC) {                  // 进程型: 查活并按需拉起
                t.run = proc_alive(t);               // 检查存活(含接手外部实例)
                if (!t.run && (t.probe == P_FILE || t.probe == P_HTTP)) {   // 句柄丢了但有探活手段
                    // 进程句柄丢了, 但心跳/HTTP 还新鲜 → 说明"另一个实例"(计划任务/手动启动)正在跑,
                    // 直接视为存活, 绝不拉起 —— 否则会和计划任务打架, 造成无限重启风暴 + 双开
                    std::string why2;                // 临时失败原因
                    if (probe(t, why2, nullptr)) { t.run = true; t.msg = "外部实例运行中(心跳正常)"; }   // 探活通过 → 认定外部实例在跑
                }
                if (!t.run) {                        // 确认不在运行
                    if (t.h) {                       // 有托管句柄 = 自己拉起的进程退出过
                        DWORD ec = 0;                // 退出码
                        if (GetExitCodeProcess(t.h, &ec)) {   // 读取退出码
                            char b[256]; snprintf(b, sizeof(b), "[%s] 进程退出 pid=%lu → %s",   // 拼日志(含可读原因)
                                                  t.key, (unsigned long)t.pid, exit_reason(ec).c_str());
                            logline(b);              // 记录退出
                        }
                        CloseHandle(t.h); t.h = NULL;   // 关闭并清空句柄
                    } else logline(std::string("[") + t.key + "] 进程已消失");   // 外部实例也消失
                    t.pid = 0; t.failN = t.memN = 0; // 清空 PID 与失败计数
                    t.healthy = false;               // 标记不健康
                    t.msg = "进程不在, 正在拉起";    // 更新状态文本
                    if (now >= t.nextTryTick) {      // 到了允许重试的时刻
                        if (!spawn(t)) {             // 拉起失败
                            t.backoffMs = t.backoffMs ? (t.backoffMs * 2 > 60000 ? 60000 : t.backoffMs * 2) : 5000;   // 指数退避: 首次5秒, 翻倍封顶60秒
                            t.nextTryTick = now + (DWORD)t.backoffMs;   // 安排下次重试时刻
                            t.msg = "拉起失败, 退避重试中";   // 更新状态文本
                        } else t.nextTryTick = 0;    // 拉起成功 → 清退避
                    }
                    g_report.issues += std::string(t.label) + "未运行; ";   // 计入异常汇总
                    continue;                        // 本目标处理结束(死进程没法探活)
                }
                if (t.backoffMs && now - t.startTick > 300000) t.backoffMs = 0;   // 稳定运行 5 分钟后清零退避
                kill_duplicates(t);   // 双开自动清理(每轮一次, 开销极小)
            } else if (t.kind == K_TASK) {           // 任务型: 查运行态并按需启动
                bool ex = false;                     // 任务是否存在
                t.run = task_running(t.taskName, &ex);   // 查询运行态
                if (!ex) { t.healthy = false; t.msg = "任务不存在"; g_report.issues += std::string(t.label) + "任务缺失; "; continue; }   // 任务缺失: 只报不修(需人工建任务)
                if (!t.run) {                        // 任务存在但没在跑
                    t.healthy = false;               // 标记不健康
                    t.msg = "任务未运行";            // 状态文本
                    if (now >= t.nextTryTick) {      // 到了重试时刻
                        HRESULT hr = task_run2(t.taskName);   // 强制启动一次
                        if (SUCCEEDED(hr)) {         // 启动成功
                            t.restarts++; t.startTick = t.lastProbeTick = GetTickCount(); t.failN = 0;   // 计数并重置计时
                            char b[192]; snprintf(b, sizeof(b), "[%s] 任务未运行 → 已启动(第%d次)", t.key, t.restarts);   // 拼日志
                            logline(b);              // 记录
                            t.msg = "刚被拉起";      // 状态文本
                        } else {                     // 启动失败
                            char b[192]; snprintf(b, sizeof(b), "[%s] 任务启动失败 hr=0x%08lX", t.key, (unsigned long)hr);   // 拼日志
                            logline(b);              // 记录
                            t.nextTryTick = now + 10000;   // 10 秒后重试
                            t.msg = "启动失败";      // 状态文本
                        }
                    }
                    g_report.issues += std::string(t.label) + "未运行; ";   // 计入异常
                    continue;                        // 结束本目标
                }
            } else {                                 // 服务型: 查状态并按需启动
                DWORD st = 0;                        // 服务状态
                bool got = svc_state(t.svcName, &st);// 查询状态
                t.run = got && st == SERVICE_RUNNING;// 只有 RUNNING 算在跑
                if (!t.run) {                        // 没在跑(或查询失败)
                    t.healthy = false;               // 标记不健康
                    t.msg = got ? "服务已停止" : "服务不存在";   // 区分原因
                    if (got && now >= t.nextTryTick) {   // 服务存在且到重试时刻
                        if (svc_start(t.svcName)) {  // 尝试启动
                            t.restarts++; t.failN = 0; t.lastProbeTick = GetTickCount();   // 计数并重置探活计时
                            logline(std::string("[") + t.key + "] 服务未运行 → 已启动");   // 记录
                            t.msg = "刚被启动";      // 状态文本
                        } else {                     // 启动失败
                            char b[192]; snprintf(b, sizeof(b), "[%s] 服务启动失败 err=%lu", t.key, GetLastError());   // 拼日志
                            logline(b);              // 记录
                            t.nextTryTick = now + 15000;   // 15 秒后重试
                        }
                    }
                    g_report.issues += std::string(t.label) + "未运行; ";   // 计入异常
                    continue;                        // 结束本目标
                }
            }

            // ---------- 探活 ----------
            if (now - t.lastProbeTick < PROBE_EVERY) continue;   // 未到 6 秒探活间隔则跳过
            t.lastProbeTick = now;                   // 记录本次探活时刻
            if (t.kind == K_PROC && now - t.startTick < (DWORD)t.warmupSec * 1000) continue;   // 进程还在预热期内不探(避免启动慢被误杀)

            std::string why, body;                   // 失败原因与响应正文
            bool healthy = probe(t, why, &body);     // 执行实际探活

            // apihub /health 顺带取池内合约数(端到端证明 MySQL 可查)
            if (healthy && t.probe == P_HTTP && t.key[0] == 'a' && t.key[1] == 'p' && t.key[2] == 'i') {   // 仅 apihub(api 前缀)的 HTTP 探活
                size_t p = body.find("\"pool\"");    // 在 /health 响应里找 pool 字段
                if (p != std::string::npos) {        // 找到了
                    size_t c = body.find("count\":", p);   // 再找其 count 子字段
                    if (c != std::string::npos) { g_report.poolCount = atoi(body.c_str() + c + 7); g_report.poolProbed = true; }   // 取池内合约数并标记已探
                }
            }

            if (healthy && t.kind == K_PROC && mem_runaway(t)) {   // 进程探活通过后查内存看门狗
                if (++t.memN >= 3) { kill_proc(t, "内存失控膨胀"); t.memN = 0; continue; }   // 连续 3 轮失控 → 强杀重启
            } else if (healthy) t.memN = 0;          // 探活健康且内存正常 → 清零计数

            if (healthy) {                           // 探活通过
                if (t.failN) logline(std::string("[") + t.key + "] 探活恢复正常");   // 曾失败过 → 记录恢复
                t.failN = 0; t.healthy = true; t.probed = true;   // 清失败计数, 标记健康
                continue;                            // 处理下一目标
            }

            t.healthy = false; t.probed = true;      // 探活失败: 标记不健康
            t.msg = why;                             // 状态文本 = 失败原因
            t.failN++;                               // 累加连续失败次数
            {                                        // 记录失败日志
                char b[256]; snprintf(b, sizeof(b), "[%s] 探活失败 %d/%d: %s", t.key, t.failN, t.failToKill, why.c_str());   // 含进度 n/阈值
                logline(b);                          // 写日志
            }
            g_report.issues += std::string(t.label) + "异常(" + why + "); ";   // 计入异常汇总
            if (t.failN < t.failToKill) continue;    // 未达判死阈值 → 继续观察
            t.failN = 0;                             // 达阈值: 清零后执行处置

            if (t.kind == K_PROC) kill_proc(t, why.c_str());   // 进程: 强杀(下一轮会重新拉起)
            else if (t.kind == K_TASK) {             // 任务: 停止并重启
                logline(std::string("[") + t.key + "] 任务卡死 → 停止并重启");   // 记录
                task_stop(t.taskName); Sleep(1000);  // 先停, 等 1 秒
                if (SUCCEEDED(task_run2(t.taskName))) t.restarts++;   // 再启动, 成功则计数
                t.startTick = t.lastProbeTick = GetTickCount();   // 重置计时(重新计预热)
            } else {                                 // 服务: 重启(断线自愈)
                logline(std::string("[") + t.key + "] 服务无响应 → 重启服务(断线自愈)");   // 记录
                svc_restart(t.svcName);              // 停止→等待→再启动
                t.startTick = t.lastProbeTick = GetTickCount();   // 重置计时
            }
        }

        // ---------- OKX 限频体检(每60秒) ----------
        if (now - lastOkx > OKX_EVERY) {             // 到 60 秒节拍
            lastOkx = now;                           // 重置节拍器
            std::string m; int ms = 0;               // 结果消息与耗时
            int r = okx_probe(&m, &ms);              // 执行 OKX 探活
            g_report.okxMs = ms;                     // 记录往返耗时
            if (r == 1) {                            // 限频
                g_report.okxRl++; g_report.okxRun++; g_report.okxBad = true; g_report.okxMsg = m;   // 更新累计/连续/状态
                if (g_report.okxRun == 1) logline("[OKX] 检测到限频: " + m + " → datahub 会自动放慢(自适应节流)");   // 首次限频记录(datahub 侧据此降速)
                else if (g_report.okxRun % 10 == 0)  // 持续限频每 10 次记一条(降噪)
                    logline("[OKX] 持续限频中(连续" + std::to_string(g_report.okxRun) + "次, 累计" + std::to_string(g_report.okxRl) + "次)");
            } else if (r == 0) {                     // 正常
                if (g_report.okxBad) logline("[OKX] 限频已恢复, 往返 " + std::to_string(ms) + "ms");   // 从限频恢复时记录
                g_report.okxRun = 0; g_report.okxBad = false; g_report.okxMsg = "正常";   // 清零连续计数与异常态
            } else {                                 // 网络错误
                g_report.okxBad = true; g_report.okxRun++; g_report.okxMsg = m;   // 记为异常
                logline("[OKX] 探活异常: " + m);     // 记录原因
            }
        }

        // ---------- K线新鲜度(每5分钟, 经 apihub 侧写不了 → 用文件心跳推断) ----------
        // 说明: 精确滞后由前端健康卡经 apihub /guard 一起展示; 这里记录心跳年龄作为粗判
        {                                            // datahub 心跳文件年龄 ≈ K线数据新鲜度
            long long age = file_age_sec("E:\\datas\\log\\hb_datahub.txt");   // 读心跳年龄
            g_report.klineLagMin = age < 0 ? -1 : (long long)(age / 60);   // 换算成分钟(不存在为 -1)
            if (age > 300 && age > 0) {              // 心跳停更超 5 分钟
                char b[192];                         // 日志缓冲
                snprintf(b, sizeof(b), "[数据] datahub 心跳已停更 %lld 秒 → 数据可能停更", age);   // 拼告警
                if (now - lastSummary > 60000) logline(b);   // 降噪: 最多每分钟一条
                g_report.issues += "数据中枢心跳停更; ";   // 计入异常汇总
            }
        }

        // ---------- MySQL 端到端: apihub 能查到池(池数>0 说明 DB 通) ----------
        {                                            // 用 apihub 探活顺带的结果做 DB 端到端判据
            static bool warnOnce = false;            // 断连告警只发一次(恢复再重置)
            if (g_report.poolProbed && g_report.poolCount == 0) {   // 已探过且池内 0 个合约
                if (!warnOnce) {                     // 尚未告警过
                    logline("[DB] !! apihub /health 报池内合约 0 个 → MySQL 可能断连(守护将持续观察)");   // 记录 DB 疑似断连
                    g_report.issues += "数据库查询异常; ";   // 计入异常汇总
                    warnOnce = true;                 // 标记已告警
                }
            } else if (warnOnce) { logline("[DB] 数据库查询恢复正常(pool=" + std::to_string(g_report.poolCount) + ")"); warnOnce = false; }   // 恢复时记录并重置
        }

        // ---------- 配置自愈 ----------
        if (now - lastFixup > FIXUP_EVERY) {         // 到 10 分钟节拍
            lastFixup = now;                         // 重置节拍器
            int a = fixup_tasks(), b = fixup_svcs(); // 修任务 + 修服务
            if (a || b) logline("[自愈] 本轮修复 " + std::to_string(a + b) + " 处被改弱的保护配置");   // 有修复才记日志
        }

        // ---------- 心跳 + 状态文件(前端用) ----------
        {                                            // 写 guard 自身心跳(供上层监督 guard 本身)
            FILE* f = fopen(HB_GUARD, "w");          // 覆盖写心跳文件
            if (f) { fprintf(f, "%lld\n", (long long)time(nullptr)); fclose(f); }   // 写入当前 Unix 时间戳
        }
        if (now - lastStatus > 2000) { lastStatus = now; write_status(); }   // 每 2 秒刷新一次状态 JSON

        // ---------- 汇总 ----------
        if (now - lastSummary > 300000) {            // 每 5 分钟做一次全量汇总日志
            lastSummary = now;                       // 重置节拍器
            std::string s = "状态:";                 // 汇总行前缀
            for (int i = 0; i < g_tgN; i++) {        // 遍历全部目标
                char b[128];                         // 单目标片段缓冲
                bool okNow = g_tg[i].run && (g_tg[i].healthy || !g_tg[i].probed);   // 健康判定(未探过视为正常)
                snprintf(b, sizeof(b), " %s%s(%s,重启%d)", g_tg[i].key, g_tg[i].run ? "✓" : "✗",   // 拼 "名✓(正常,重启n)"
                         okNow ? "正常" : "异常", g_tg[i].restarts);
                s += b;                              // 追加到汇总
            }
            logline(s);                              // 输出汇总
        }

        for (int k = 0; k < LOOP_MS / 200 && !g_stop; k++) Sleep(200);   // 分片休眠 3 秒(每 200ms 查一次停止标志, 保证快速响应停机)
    }
    write_status();                                  // 退出前最后写一次状态文件
    logline("guard 已停止(子进程保留, 由计划任务兜底)");   // 停止日志(托管子进程不杀, 留给计划任务兜底)
}
