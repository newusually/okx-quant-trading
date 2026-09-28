// ==================================================================
// guard.h — 守护中枢内部组件间声明 (guard_core ↔ guard_main)
// ------------------------------------------------------------------
// 文件职责: 守护模块的公共头文件, 声明服务常量、监控目标结构体 Target
//           以及 guard_core.cpp 提供给 guard_main.cpp 的全部函数接口。
// 函数清单: 本文件仅含声明(无实现), 实现分布在:
//           - guard_core.cpp: logline/probe/fixup_tasks/fixup_svcs/
//             guard_loop/okx_probe/task_*/ts_init/svc_*/find_pid
//           - guard_main.cpp: SCM 服务壳与命令行处理(仅调用方)
// 被谁调用: guard_core.cpp 与 guard_main.cpp 均 #include 本头文件,
//           通过它共享全局目标表 g_tg 与探活/自愈/主循环等接口。
// ==================================================================
#pragma once                                        // 防止头文件被重复包含
#include <winsock2.h>                               // WinSock2 头(必须先于 windows.h, 避免 winsock1 冲突)
#include <windows.h>                                // Windows API 核心(服务/句柄/临界区等)
#include <string>                                   // std::string, 用于 Target::msg 与函数参数
#include <vector>                                   // std::vector, 供 kill_duplicates 的 pid 列表

#define SVCNAME      "finally_guard"                // Windows SCM 服务名(安装/控制/查询的唯一标识)
#define SVCDISPLAY   "OKX Hubs Guard (finally)"     // 服务在服务管理器中显示的友好名称
#define LOGF         "E:\\datas\\log\\guard.log"    // 守护日志文件路径(带轮转)
#define STATUSF      "E:\\datas\\log\\guard_status.json"  // 状态上报 JSON 文件路径(前端健康卡读取)
#define HB_GUARD     "E:\\datas\\log\\hb_guard.txt" // guard 自身的心跳文件(每轮循环刷新时间戳)
#define ROTATE_BYTES (4 * 1024 * 1024)              // 日志轮转阈值 4MB: 超过后 rename 为 .1 重新开写
#define LOOP_MS      3000                           // 主循环单轮休眠周期 3 秒(可中断式分片休眠)
#define PROBE_EVERY  6000    // 探活间隔(3次失败≈18秒判死)  // 同一目标两次探活的最小间隔 6 秒
#define FIXUP_EVERY  600000                            // 配置自愈周期 10 分钟(fixup_tasks/fixup_svcs)
#define OKX_EVERY    60000                             // OKX 限频探测周期 60 秒

enum Kind      { K_PROC, K_TASK, K_SVC };           // 目标托管方式: 直接进程 / 计划任务 / Windows 服务
enum ProbeKind { P_FILE, P_HTTP, P_MYSQL, P_NONE }; // 三级探活之实际探测方式: 心跳文件/HTTP/MySQL/不探

struct Target {                                     // 单个监控目标的静态配置 + 运行态
    const char* key;                                // 目标短名(如 "datahub"), 用于日志与状态 JSON
    const char* label;                              // 中文显示名(如 "数据中枢"), 给前端展示
    Kind        kind;                               // 托管方式: 进程/计划任务/服务
    const char* exe;        // K_PROC                // 进程型: exe 完整路径(拉起/匹配用)
    const char* workdir;    // K_PROC                // 进程型: 工作目录(CreateProcess 的 lpCurrentDirectory)
    const char* proc;       // K_PROC                // 进程型: 进程名(如 "datahub.exe", 快照匹配用)
    const char* taskName;   // K_TASK                // 任务型: 计划任务名(COM 接口 GetTask 用)
    const char* svcName;    // K_SVC                 // 服务型: Windows 服务名(OpenService 用)
    ProbeKind   probe;                              // 实际探活方式: 心跳文件/HTTP/MySQL
    const char* probeArg;                           // 探活参数: 心跳文件路径 / "host:port/path" / "host:port"
    int         staleSec;                           // P_FILE: 心跳文件超过该秒数未更新即判卡死
    int         warmupSec;                          // 进程刚拉起后的预热期(秒), 预热期内不探活以免误杀
    int         failToKill;                         // 连续探活失败达到该次数才执行强杀重启
    int         memLimitMB;                         // 内存看门狗上限(MB), 0=不监控; 超限×2 判失控
    // 运行态                                            —— 以下均为运行期状态, 重启后归零
    HANDLE  h;                                      // 自己拉起的子进程句柄(NULL=外部实例或未知)
    DWORD   pid;                                    // 当前托管进程的 PID(0=未知/未运行)
    DWORD   startTick, lastProbeTick, nextTryTick;  // 启动时刻 / 上次探活时刻 / 下次允许重试时刻
    int     failN, memN, backoffMs, restarts;       // 连续探活失败数 / 内存超限计数 / 拉起退避毫秒 / 累计重启次数
    bool    run, healthy, probed;                   // 是否存活 / 最近探活是否健康 / 是否已完成过首次探活
    std::string msg;                                // 当前状态描述文本(写入状态 JSON 给前端)
};

// ---- guard_core.cpp ----                              以下均为 guard_core.cpp 实现的全局与函数
extern Target g_tg[];                               // 全局监控目标表(6 个: 4 进程 + 2 服务)
extern const int g_tgN;                             // 目标表元素个数
extern CRITICAL_SECTION g_logCs;                    // 日志写文件的临界区(多线程安全)
extern volatile bool g_stop;                        // 全局停止标志(SCM 停止/Ctrl+C 置位, 主循环检测退出)
extern DWORD g_startTick;                           // guard 启动时刻(算 uptime 用)
extern SERVICE_STATUS_HANDLE g_ssh;                 // SCM 服务状态句柄(SetServiceStatus 用)
extern SERVICE_STATUS g_ss;                         // SCM 服务状态结构体
void logline(const std::string& s);                 // 写一行带时间戳的日志(临界区保护, 4MB 轮转)
bool probe(Target& t, std::string& why, std::string* body = nullptr);  // 按目标类型分派三级探活, why 返回失败原因
int  fixup_tasks();                                 // 自愈计划任务: 强制修复 PT0S/电池/空闲/Enabled/失败重启
int  fixup_svcs();                                  // 自愈 Windows 服务: 把 Apache/MariaDB 启动类型改回自动
void guard_loop();                                  // 守护主循环: 存活检查→拉起→探活→自愈→状态上报
int  okx_probe(std::string* msg, int* ms);          // OKX 限频探测: 0=正常 1=限频(429/50011/50013) -1=网络错误

// 计划任务(COM)                                        —— 封装 Task Scheduler COM 接口
bool task_running(const char* name, bool* exists);  // 查询计划任务是否处于 RUNNING 态(exists 返回任务是否存在)
HRESULT task_run2(const char* name);                // 强制启动一次计划任务(IRunningTask)
HRESULT task_stop(const char* name);                // 强制停止计划任务
bool ts_init();                                     // 初始化 COM + 连接 Task Scheduler 服务(g_ts 全局可用)

// 服务(SCM)                                            —— 封装服务控制管理器操作
bool svc_state(const char* name, DWORD* state);     // 查询服务当前状态(SERVICE_RUNNING 等)
bool svc_start(const char* name);                   // 启动服务(已在运行视为成功)
bool svc_restart(const char* name);                 // 重启服务: 停止→轮询等待→再启动
DWORD find_pid(const char* proc, const char* exePath);  // 按进程名+exe 全路径精确定位 PID(防同名误判), 0=未找到
