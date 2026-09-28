// ==================================================================
// guard_main.cpp — 守护中枢服务壳 (拆分自 guard.cpp, 逻辑零改动)
// Windows 服务(SCM) + 安装/卸载/ensure/status/fixup/console 命令行
// 核心逻辑见 guard_core.cpp
// ==================================================================
#define _WIN32_WINNT 0x0601
#include "guard.h"
#include <winsock2.h>
#include <ws2tcpip.h>
#include <taskschd.h>
#include <comdef.h>
#include <cstdio>
#include <ctime>

static DWORD WINAPI ctrl_handler(DWORD ctrl, DWORD, LPVOID, LPVOID) {
    switch (ctrl) {
    case SERVICE_CONTROL_STOP:
    case SERVICE_CONTROL_SHUTDOWN:
        g_ss.dwCurrentState = SERVICE_STOP_PENDING;
        g_ss.dwWaitHint = 8000;
        SetServiceStatus(g_ssh, &g_ss);
        g_stop = true;
        break;
    case SERVICE_CONTROL_INTERROGATE:
        SetServiceStatus(g_ssh, &g_ss);
        break;
    }
    return NO_ERROR;
}
static void WINAPI service_main(DWORD, LPSTR*) {
    g_ss.dwServiceType = SERVICE_WIN32_OWN_PROCESS;
    g_ss.dwCurrentState = SERVICE_START_PENDING;
    g_ss.dwControlsAccepted = SERVICE_ACCEPT_STOP | SERVICE_ACCEPT_SHUTDOWN;
    g_ssh = RegisterServiceCtrlHandlerExA(SVCNAME, ctrl_handler, NULL);
    if (!g_ssh) return;
    SetServiceStatus(g_ssh, &g_ss);
    g_ss.dwCurrentState = SERVICE_RUNNING;
    SetServiceStatus(g_ssh, &g_ss);
    guard_loop();
    g_ss.dwCurrentState = SERVICE_STOPPED;
    SetServiceStatus(g_ssh, &g_ss);
}
static std::string self_path() {
    char b[MAX_PATH * 2] = { 0 };
    GetModuleFileNameA(NULL, b, sizeof(b) - 1);
    return b;
}
static int do_install() {
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_ALL_ACCESS);
    if (!scm) { printf("需要管理员权限 (err=%lu)\n", GetLastError()); return 1; }
    std::string bin = "\"" + self_path() + "\"";
    SC_HANDLE svc = OpenServiceA(scm, SVCNAME, SERVICE_ALL_ACCESS);
    if (!svc) {
        svc = CreateServiceA(scm, SVCNAME, SVCDISPLAY, SERVICE_ALL_ACCESS,
                             SERVICE_WIN32_OWN_PROCESS, SERVICE_AUTO_START, SERVICE_ERROR_NORMAL,
                             bin.c_str(), NULL, NULL, NULL, NULL, NULL);
        if (!svc) { printf("创建服务失败 err=%lu\n", GetLastError()); CloseServiceHandle(scm); return 1; }
        printf("服务已创建: %s\n", SVCNAME);
    } else {
        ChangeServiceConfigA(svc, SERVICE_NO_CHANGE, SERVICE_AUTO_START, SERVICE_NO_CHANGE,
                             bin.c_str(), NULL, NULL, NULL, NULL, NULL, SVCDISPLAY);
        printf("服务已存在, 已更新路径与自启\n");
    }
    SERVICE_DESCRIPTIONA desc{};
    desc.lpDescription = (LPSTR)"OKX 量化系统守护中枢: 秒级探活+自动拉起 datahub/apihub/tphub/PHP引擎, 确保 Apache2.4 与 MariaDB 始终运行(含断线自愈), 监控 OKX 限频, 并自愈计划任务/服务的保护配置。纯 C++ 实现。";
    ChangeServiceConfig2A(svc, SERVICE_CONFIG_DESCRIPTION, &desc);

    SC_ACTION acts[3];
    for (int i = 0; i < 3; i++) { acts[i].Type = SC_ACTION_RESTART; acts[i].Delay = 2000; }
    SERVICE_FAILURE_ACTIONSW sfa{};
    sfa.dwResetPeriod = 0;
    sfa.cActions = 3;
    sfa.lpsaActions = acts;
    BOOL ok = ChangeServiceConfig2W(svc, SERVICE_CONFIG_FAILURE_ACTIONS, &sfa);

    SERVICE_FAILURE_ACTIONS_FLAG faf{};
    faf.fFailureActionsOnNonCrashFailures = TRUE;
    ChangeServiceConfig2A(svc, SERVICE_CONFIG_FAILURE_ACTIONS_FLAG, &faf);

    SERVICE_DELAYED_AUTO_START_INFO das{};
    das.fDelayedAutostart = FALSE;
    ChangeServiceConfig2A(svc, SERVICE_CONFIG_DELAYED_AUTO_START_INFO, &das);

    printf("失败自动重启: %s (3次x2秒, 重置周期0=永不放弃)\n", ok ? "已设置" : "设置失败");
    if (!StartServiceA(svc, 0, NULL)) {
        DWORD e = GetLastError();
        printf(e == ERROR_SERVICE_ALREADY_RUNNING ? "服务已在运行\n" : "启动服务失败 err=%lu\n", e);
    } else printf("服务已启动\n");
    CloseServiceHandle(svc); CloseServiceHandle(scm);
    printf("\n完成: 已写入系统。开机自动启动 / 与登录注销无关 / 被杀 2 秒内自愈。\n");
    return 0;
}
static int do_uninstall() {
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_ALL_ACCESS);
    if (!scm) { printf("需要管理员权限\n"); return 1; }
    SC_HANDLE svc = OpenServiceA(scm, SVCNAME, SERVICE_ALL_ACCESS);
    if (!svc) { printf("服务不存在\n"); CloseServiceHandle(scm); return 1; }
    SERVICE_STATUS st{};
    ControlService(svc, SERVICE_CONTROL_STOP, &st);
    Sleep(1500);
    printf(DeleteService(svc) ? "服务已删除\n" : "删除失败 err=%lu\n", GetLastError());
    CloseServiceHandle(svc); CloseServiceHandle(scm);
    return 0;
}
static int do_ensure() {
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_ALL_ACCESS);
    if (!scm) return 1;
    SC_HANDLE svc = OpenServiceA(scm, SVCNAME, SERVICE_ALL_ACCESS);
    if (!svc) { CloseServiceHandle(scm); return do_install(); }
    SERVICE_STATUS st{};
    if (QueryServiceStatus(svc, &st) && st.dwCurrentState != SERVICE_RUNNING) {
        if (StartServiceA(svc, 0, NULL)) logline("ensure: 服务未运行 → 已拉起");
        else logline(std::string("ensure: 拉起失败 err=") + std::to_string(GetLastError()));
    }
    CloseServiceHandle(svc); CloseServiceHandle(scm);
    return 0;
}
static int do_status() {
    InitializeCriticalSection(&g_logCs);
    WSADATA wsa; WSAStartup(MAKEWORD(2, 2), &wsa);
    ts_init();
    printf("=== 交易全链路守护状态 ===\n");
    for (int i = 0; i < g_tgN; i++) {
        Target& t = g_tg[i];
        const char* k = t.kind == K_PROC ? "进程" : (t.kind == K_TASK ? "任务" : "服务");
        bool alive = false; DWORD pid = 0;
        if (t.kind == K_PROC) { pid = find_pid(t.proc, t.exe); alive = pid != 0; }
        else if (t.kind == K_TASK) { bool ex = false; alive = task_running(t.taskName, &ex); }
        else { DWORD st = 0; alive = svc_state(t.svcName, &st) && st == SERVICE_RUNNING; }
        std::string why;
        bool healthy = alive ? probe(t, why) : false;
        printf("  [%s] %-10s %-12s 运行=%-3s pid=%-7lu 探活=%-4s %s\n",
               k, t.key, t.label, alive ? "是" : "否", (unsigned long)pid,
               healthy ? "正常" : "异常", healthy ? "" : why.c_str());
    }
    std::string m; int ms = 0;
    int r = okx_probe(&m, &ms);
    printf("  [外部] OKX 探活: %s (%dms)\n", r == 0 ? "正常" : m.c_str(), ms);
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_CONNECT);
    if (scm) {
        SC_HANDLE svc = OpenServiceA(scm, SVCNAME, SERVICE_QUERY_STATUS);
        if (svc) {
            SERVICE_STATUS st{};
            if (QueryServiceStatus(svc, &st)) {
                const char* s = "未知";
                switch (st.dwCurrentState) {
                case SERVICE_RUNNING: s = "运行中"; break;
                case SERVICE_STOPPED: s = "已停止"; break;
                case SERVICE_START_PENDING: s = "启动中"; break;
                }
                printf("  [守护] %s = %s (自启 + 失败自动重启)\n", SVCNAME, s);
            }
            CloseServiceHandle(svc);
        } else printf("  [守护] %s 未安装\n", SVCNAME);
        CloseServiceHandle(scm);
    }
    return 0;
}
static BOOL WINAPI console_ctrl(DWORD) { g_stop = true; return TRUE; }

// 崩溃兜底: 记录异常代码+地址(排障用)
static LONG WINAPI crash_filter(EXCEPTION_POINTERS* ep) {
    char b[256];
    if (ep && ep->ExceptionRecord)
        snprintf(b, sizeof(b), "!! guard 崩溃 代码=0x%08lX 地址=%p",
                 (unsigned long)ep->ExceptionRecord->ExceptionCode,
                 ep->ExceptionRecord->ExceptionAddress);
    else snprintf(b, sizeof(b), "!! guard 崩溃(无异常信息)");
    logline(b);
    return EXCEPTION_EXECUTE_HANDLER;
}

static int run_console() {
    SetConsoleCtrlHandler(console_ctrl, TRUE);
    SetUnhandledExceptionFilter(crash_filter);
    printf("guard v3 前台模式 (Ctrl+C 退出)\n"); fflush(stdout);
    guard_loop();
    return 0;
}

int WINAPI WinMain(HINSTANCE, HINSTANCE, LPSTR lpCmd, int) {
    std::string cmd = lpCmd ? lpCmd : "";
    if (cmd.find("-install")   != std::string::npos) return do_install();
    if (cmd.find("-uninstall") != std::string::npos) return do_uninstall();
    if (cmd.find("-ensure")    != std::string::npos) return do_ensure();
    if (cmd.find("-status")    != std::string::npos) return do_status();
    if (cmd.find("-fixup")     != std::string::npos) {
        InitializeCriticalSection(&g_logCs); ts_init();
        printf("配置自愈: 修复任务 %d 处, 服务 %d 处\n", fixup_tasks(), fixup_svcs());
        return 0;
    }
    if (cmd.find("-console")   != std::string::npos) return run_console();

    SERVICE_TABLE_ENTRYA te[] = {
        { (LPSTR)SVCNAME, (LPSERVICE_MAIN_FUNCTIONA)service_main },
        { NULL, NULL }
    };
    if (!StartServiceCtrlDispatcherA(te)) return run_console();
    return 0;
}
