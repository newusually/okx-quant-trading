// ==================================================================
// guard_main.cpp — 守护中枢服务壳 (拆分自 guard.cpp, 逻辑零改动)
// Windows 服务(SCM) + 安装/卸载/ensure/status/fixup/console 命令行
// 核心逻辑见 guard_core.cpp
// ------------------------------------------------------------------
// 文件职责: guard.exe 的 Windows SCM 服务壳与命令行入口:
//   - ServiceMain/控制处理器: 向 SCM 上报状态, 响应停止/关机/查询;
//   - 命令行参数: -install(安装并启动) / -uninstall / -ensure(兜底拉起) /
//     -status(打印全链路状态) / -fixup(手动配置自愈) / -console(前台调试);
//   - 无参数时经 StartServiceCtrlDispatcher 进入服务模式,
//     Dispatcher 失败(如双击直跑)自动回退前台模式;
//   - 崩溃兜底: 记录异常代码与地址到 guard.log。
// 编译注意: 需链接 -lole32 -loleaut32 -ltaskschd; MinGW 的 libuuid 缺
//   CLSID_TaskScheduler 定义, 必须链 libtaskschd 才能解析该符号。
// 函数清单: ctrl_handler / service_main / self_path / do_install /
//   do_uninstall / do_ensure / do_status / console_ctrl / crash_filter /
//   run_console / WinMain
// 被谁调用: 可执行入口(WinMain); service_main 由 SCM 调度; 
//   ctrl_handler 由 SCM 控制分发器调用; crash_filter 由系统异常分发调用。
// ==================================================================
#define _WIN32_WINNT 0x0601                          // 目标系统 Win7+(服务/COM 接口需要)
#include "guard.h"                                   // 公共声明(服务名/常量/guard_core 接口)
#include <winsock2.h>                                // WinSock2(status 命令探活用, 须先于 windows.h)
#include <ws2tcpip.h>                                // 地址转换辅助
#include <taskschd.h>                                // Task Scheduler COM(ts_init 需要)
#include <comdef.h>                                  // _bstr_t/_variant_t COM 辅助类型
#include <cstdio>                                    // printf
#include <ctime>                                     // time(原文件包含, 保持不动)

static DWORD WINAPI ctrl_handler(DWORD ctrl, DWORD, LPVOID, LPVOID) {   // SCM 控制处理器: 接收服务控制命令
    switch (ctrl) {                                  // 按控制码分派
    case SERVICE_CONTROL_STOP:                       // 停止命令
    case SERVICE_CONTROL_SHUTDOWN:                   // 系统关机
        g_ss.dwCurrentState = SERVICE_STOP_PENDING;  // 上报"正在停止"
        g_ss.dwWaitHint = 8000;                      // 预告最长 8 秒内完成
        SetServiceStatus(g_ssh, &g_ss);              // 提交状态给 SCM
        g_stop = true;                               // 置停止标志, guard_loop 检测后退出
        break;                                       // 处理完毕
    case SERVICE_CONTROL_INTERROGATE:                // SCM 要求即时回报当前状态
        SetServiceStatus(g_ssh, &g_ss);              // 重发当前状态即可
        break;                                       // 处理完毕
    }
    return NO_ERROR;                                 // 命令已受理
}
static void WINAPI service_main(DWORD, LPSTR*) {     // SCM 服务主函数(服务模式入口)
    g_ss.dwServiceType = SERVICE_WIN32_OWN_PROCESS;  // 独占进程型服务
    g_ss.dwCurrentState = SERVICE_START_PENDING;     // 初始状态: 启动中
    g_ss.dwControlsAccepted = SERVICE_ACCEPT_STOP | SERVICE_ACCEPT_SHUTDOWN;   // 声明接受停止与关机控制
    g_ssh = RegisterServiceCtrlHandlerExA(SVCNAME, ctrl_handler, NULL);   // 注册控制处理器, 拿状态句柄
    if (!g_ssh) return;                              // 注册失败无法与 SCM 通信, 直接放弃
    SetServiceStatus(g_ssh, &g_ss);                  // 上报"启动中"
    g_ss.dwCurrentState = SERVICE_RUNNING;           // 切换为运行中
    SetServiceStatus(g_ssh, &g_ss);                  // 上报"运行中"(此后进入守护)
    guard_loop();                                    // 进入守护主循环(阻塞直到 g_stop)
    g_ss.dwCurrentState = SERVICE_STOPPED;           // 主循环退出后置已停止
    SetServiceStatus(g_ssh, &g_ss);                  // 上报"已停止", 服务生命周期结束
}
static std::string self_path() {                     // 取 guard.exe 自身完整路径(安装服务时写入 binPath)
    char b[MAX_PATH * 2] = { 0 };                    // 路径缓冲(2 倍余量防长路径)
    GetModuleFileNameA(NULL, b, sizeof(b) - 1);      // 取当前模块 exe 路径
    return b;                                        // 返回路径字符串
}
static int do_install() {                            // -install: 创建/更新 finally_guard 服务并启动
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_ALL_ACCESS);   // 打开 SCM(需管理员权限)
    if (!scm) { printf("需要管理员权限 (err=%lu)\n", GetLastError()); return 1; }   // 权限不足直接失败
    std::string bin = "\"" + self_path() + "\"";     // binPath = 带引号的自身路径(防空格)
    SC_HANDLE svc = OpenServiceA(scm, SVCNAME, SERVICE_ALL_ACCESS);   // 先试打开已存在的服务
    if (!svc) {                                      // 服务不存在 → 创建
        svc = CreateServiceA(scm, SVCNAME, SVCDISPLAY, SERVICE_ALL_ACCESS,   // 名称/显示名/全部权限
                             SERVICE_WIN32_OWN_PROCESS, SERVICE_AUTO_START, SERVICE_ERROR_NORMAL,   // 独占进程/开机自启/正常错误处理
                             bin.c_str(), NULL, NULL, NULL, NULL, NULL);   // 可执行路径, 其余默认
        if (!svc) { printf("创建服务失败 err=%lu\n", GetLastError()); CloseServiceHandle(scm); return 1; }   // 创建失败
        printf("服务已创建: %s\n", SVCNAME);         // 创建成功提示
    } else {                                         // 服务已存在 → 更新配置
        ChangeServiceConfigA(svc, SERVICE_NO_CHANGE, SERVICE_AUTO_START, SERVICE_NO_CHANGE,   // 保持类型, 确保自启
                             bin.c_str(), NULL, NULL, NULL, NULL, NULL, SVCDISPLAY);   // 更新路径与显示名
        printf("服务已存在, 已更新路径与自启\n");    // 更新成功提示
    }
    SERVICE_DESCRIPTIONA desc{};                     // 服务描述结构
    desc.lpDescription = (LPSTR)"OKX 量化系统守护中枢: 秒级探活+自动拉起 datahub/apihub/tphub/PHP引擎, 确保 Apache2.4 与 MariaDB 始终运行(含断线自愈), 监控 OKX 限频, 并自愈计划任务/服务的保护配置。纯 C++ 实现。";   // 服务管理器里显示的说明
    ChangeServiceConfig2A(svc, SERVICE_CONFIG_DESCRIPTION, &desc);   // 写入描述

    SC_ACTION acts[3];                               // 失败动作数组(3 次重启)
    for (int i = 0; i < 3; i++) { acts[i].Type = SC_ACTION_RESTART; acts[i].Delay = 2000; }   // 每次: 重启服务, 延迟 2 秒
    SERVICE_FAILURE_ACTIONSW sfa{};                  // 失败动作配置
    sfa.dwResetPeriod = 0;                           // 失败计数永不重置(0=从不放弃重试)
    sfa.cActions = 3;                                // 共 3 个动作
    sfa.lpsaActions = acts;                          // 指向动作数组
    BOOL ok = ChangeServiceConfig2W(svc, SERVICE_CONFIG_FAILURE_ACTIONS, &sfa);   // 写入失败自动重启配置

    SERVICE_FAILURE_ACTIONS_FLAG faf{};              // 失败动作触发条件标志
    faf.fFailureActionsOnNonCrashFailures = TRUE;    // 非崩溃式退出(如正常退出码)也触发失败重启
    ChangeServiceConfig2A(svc, SERVICE_CONFIG_FAILURE_ACTIONS_FLAG, &faf);   // 写入该标志

    SERVICE_DELAYED_AUTO_START_INFO das{};           // 延迟自启配置
    das.fDelayedAutostart = FALSE;                   // 关闭延迟自启 → 开机立即启动(不等空闲)
    ChangeServiceConfig2A(svc, SERVICE_CONFIG_DELAYED_AUTO_START_INFO, &das);   // 写入

    printf("失败自动重启: %s (3次x2秒, 重置周期0=永不放弃)\n", ok ? "已设置" : "设置失败");   // 重启配置结果
    if (!StartServiceA(svc, 0, NULL)) {              // 安装后立即启动服务
        DWORD e = GetLastError();                    // 取错误码
        printf(e == ERROR_SERVICE_ALREADY_RUNNING ? "服务已在运行\n" : "启动服务失败 err=%lu\n", e);   // 已在运行不算错
    } else printf("服务已启动\n");                   // 启动成功
    CloseServiceHandle(svc); CloseServiceHandle(scm);// 关闭句柄
    printf("\n完成: 已写入系统。开机自动启动 / 与登录注销无关 / 被杀 2 秒内自愈。\n");   // 完成摘要
    return 0;                                        // 成功
}
static int do_uninstall() {                          // -uninstall: 停止并删除服务
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_ALL_ACCESS);   // 打开 SCM
    if (!scm) { printf("需要管理员权限\n"); return 1; }   // 权限不足
    SC_HANDLE svc = OpenServiceA(scm, SVCNAME, SERVICE_ALL_ACCESS);   // 打开目标服务
    if (!svc) { printf("服务不存在\n"); CloseServiceHandle(scm); return 1; }   // 未安装
    SERVICE_STATUS st{};                             // 状态结构
    ControlService(svc, SERVICE_CONTROL_STOP, &st);  // 先下发停止命令
    Sleep(1500);                                     // 等 1.5 秒让服务退出(否则删除可能失败)
    printf(DeleteService(svc) ? "服务已删除\n" : "删除失败 err=%lu\n", GetLastError());   // 删除并报告结果
    CloseServiceHandle(svc); CloseServiceHandle(scm);// 关闭句柄
    return 0;                                        // 成功
}
static int do_ensure() {                             // -ensure: 兜底检查(服务没装就装, 没跑就拉起)
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_ALL_ACCESS);   // 打开 SCM
    if (!scm) return 1;                              // 失败
    SC_HANDLE svc = OpenServiceA(scm, SVCNAME, SERVICE_ALL_ACCESS);   // 打开服务
    if (!svc) { CloseServiceHandle(scm); return do_install(); }   // 未安装 → 转入完整安装流程
    SERVICE_STATUS st{};                             // 状态结构
    if (QueryServiceStatus(svc, &st) && st.dwCurrentState != SERVICE_RUNNING) {   // 已安装但没在运行
        if (StartServiceA(svc, 0, NULL)) logline("ensure: 服务未运行 → 已拉起");   // 拉起并记日志
        else logline(std::string("ensure: 拉起失败 err=") + std::to_string(GetLastError()));   // 失败记录错误码
    }
    CloseServiceHandle(svc); CloseServiceHandle(scm);// 关闭句柄
    return 0;                                        // 成功
}
static int do_status() {                             // -status: 打印全链路守护状态(人工巡检用)
    InitializeCriticalSection(&g_logCs);             // 初始化日志临界区(probe 内部可能写日志)
    WSADATA wsa; WSAStartup(MAKEWORD(2, 2), &wsa);   // 初始化 WinSock(HTTP/MySQL 探活需要)
    ts_init();                                       // 初始化计划任务 COM(任务型探活需要)
    printf("=== 交易全链路守护状态 ===\n");          // 标题
    for (int i = 0; i < g_tgN; i++) {                // 遍历全部监控目标
        Target& t = g_tg[i];                         // 当前目标
        const char* k = t.kind == K_PROC ? "进程" : (t.kind == K_TASK ? "任务" : "服务");   // 托管方式中文名
        bool alive = false; DWORD pid = 0;           // 存活与 PID
        if (t.kind == K_PROC) { pid = find_pid(t.proc, t.exe); alive = pid != 0; }   // 进程型: 快照找 PID
        else if (t.kind == K_TASK) { bool ex = false; alive = task_running(t.taskName, &ex); }   // 任务型: 查运行态
        else { DWORD st = 0; alive = svc_state(t.svcName, &st) && st == SERVICE_RUNNING; }   // 服务型: 查 SCM 状态
        std::string why;                             // 探活失败原因
        bool healthy = alive ? probe(t, why) : false;// 存活才做实际探活
        printf("  [%s] %-10s %-12s 运行=%-3s pid=%-7lu 探活=%-4s %s\n",   // 打印单行状态
               k, t.key, t.label, alive ? "是" : "否", (unsigned long)pid,
               healthy ? "正常" : "异常", healthy ? "" : why.c_str());
    }
    std::string m; int ms = 0;                       // OKX 探活消息与耗时
    int r = okx_probe(&m, &ms);                      // 执行 OKX 外部探活
    printf("  [外部] OKX 探活: %s (%dms)\n", r == 0 ? "正常" : m.c_str(), ms);   // 打印结果
    SC_HANDLE scm = OpenSCManagerA(NULL, NULL, SC_MANAGER_CONNECT);   // 连接 SCM 查自身服务状态
    if (scm) {                                       // 连接成功
        SC_HANDLE svc = OpenServiceA(scm, SVCNAME, SERVICE_QUERY_STATUS);   // 打开 finally_guard
        if (svc) {                                   // 已安装
            SERVICE_STATUS st{};                     // 状态结构
            if (QueryServiceStatus(svc, &st)) {      // 读取成功
                const char* s = "未知";              // 状态文本
                switch (st.dwCurrentState) {         // 映射常见状态
                case SERVICE_RUNNING: s = "运行中"; break;        // 运行中
                case SERVICE_STOPPED: s = "已停止"; break;        // 已停止
                case SERVICE_START_PENDING: s = "启动中"; break;  // 启动中
                }
                printf("  [守护] %s = %s (自启 + 失败自动重启)\n", SVCNAME, s);   // 打印守护自身状态
            }
            CloseServiceHandle(svc);                 // 关闭服务句柄
        } else printf("  [守护] %s 未安装\n", SVCNAME);   // 未安装提示
        CloseServiceHandle(scm);                     // 关闭 SCM 句柄
    }
    return 0;                                        // 成功
}
static BOOL WINAPI console_ctrl(DWORD) { g_stop = true; return TRUE; }   // 前台模式 Ctrl+C/关闭控制台 → 置停止标志

// 崩溃兜底: 记录异常代码+地址(排障用)
static LONG WINAPI crash_filter(EXCEPTION_POINTERS* ep) {   // 未处理异常过滤器(前台与服务模式通用)
    char b[256];                                     // 日志缓冲
    if (ep && ep->ExceptionRecord)                   // 有异常记录
        snprintf(b, sizeof(b), "!! guard 崩溃 代码=0x%08lX 地址=%p",   // 拼异常码+出错地址
                 (unsigned long)ep->ExceptionRecord->ExceptionCode,
                 ep->ExceptionRecord->ExceptionAddress);
    else snprintf(b, sizeof(b), "!! guard 崩溃(无异常信息)");   // 无详细信息
    logline(b);                                      // 落盘到 guard.log
    return EXCEPTION_EXECUTE_HANDLER;                // 结束进程(交给系统默认处理)
}

static int run_console() {                           // -console: 前台调试模式(不进 SCM)
    SetConsoleCtrlHandler(console_ctrl, TRUE);       // 注册控制台处理器(Ctrl+C 优雅退出)
    SetUnhandledExceptionFilter(crash_filter);       // 注册崩溃兜底
    printf("guard v3 前台模式 (Ctrl+C 退出)\n"); fflush(stdout);   // 提示模式并立即刷新
    guard_loop();                                    // 进入守护主循环
    return 0;                                        // 退出
}

int WINAPI WinMain(HINSTANCE, HINSTANCE, LPSTR lpCmd, int) {   // 入口(GUI 子系统, 无控制台窗口)
    std::string cmd = lpCmd ? lpCmd : "";            // 命令行参数
    if (cmd.find("-install")   != std::string::npos) return do_install();     // 安装服务并启动
    if (cmd.find("-uninstall") != std::string::npos) return do_uninstall();   // 卸载服务
    if (cmd.find("-ensure")    != std::string::npos) return do_ensure();      // 兜底: 没装则装, 没跑则拉
    if (cmd.find("-status")    != std::string::npos) return do_status();      // 打印状态
    if (cmd.find("-fixup")     != std::string::npos) {                        // 手动触发配置自愈
        InitializeCriticalSection(&g_logCs); ts_init();   // 初始化日志与计划任务 COM
        printf("配置自愈: 修复任务 %d 处, 服务 %d 处\n", fixup_tasks(), fixup_svcs());   // 执行并报告
        return 0;                                    // 退出
    }
    if (cmd.find("-console")   != std::string::npos) return run_console();    // 前台调试模式

    SERVICE_TABLE_ENTRYA te[] = {                    // 服务入口表(无参数默认走 SCM 服务模式)
        { (LPSTR)SVCNAME, (LPSERVICE_MAIN_FUNCTIONA)service_main },   // 服务名 → ServiceMain
        { NULL, NULL }                               // 表尾哨兵
    };
    if (!StartServiceCtrlDispatcherA(te)) return run_console();   // 调度失败(非 SCM 启动, 如手动双击)→ 回退前台模式
    return 0;                                        // 服务模式正常返回
}
