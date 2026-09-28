// ==================================================================
// guard.h — 守护中枢内部组件间声明 (guard_core ↔ guard_main)
// ==================================================================
#pragma once
#include <winsock2.h>
#include <windows.h>
#include <string>
#include <vector>

#define SVCNAME      "finally_guard"
#define SVCDISPLAY   "OKX Hubs Guard (finally)"
#define LOGF         "E:\\datas\\log\\guard.log"
#define STATUSF      "E:\\datas\\log\\guard_status.json"
#define HB_GUARD     "E:\\datas\\log\\hb_guard.txt"
#define ROTATE_BYTES (4 * 1024 * 1024)
#define LOOP_MS      3000
#define PROBE_EVERY  6000    // 探活间隔(3次失败≈18秒判死)
#define FIXUP_EVERY  600000
#define OKX_EVERY    60000

enum Kind      { K_PROC, K_TASK, K_SVC };
enum ProbeKind { P_FILE, P_HTTP, P_MYSQL, P_NONE };

struct Target {
    const char* key;
    const char* label;
    Kind        kind;
    const char* exe;        // K_PROC
    const char* workdir;    // K_PROC
    const char* proc;       // K_PROC
    const char* taskName;   // K_TASK
    const char* svcName;    // K_SVC
    ProbeKind   probe;
    const char* probeArg;
    int         staleSec;
    int         warmupSec;
    int         failToKill;
    int         memLimitMB;
    // 运行态
    HANDLE  h;
    DWORD   pid;
    DWORD   startTick, lastProbeTick, nextTryTick;
    int     failN, memN, backoffMs, restarts;
    bool    run, healthy, probed;
    std::string msg;
};

// ---- guard_core.cpp ----
extern Target g_tg[];
extern const int g_tgN;
extern CRITICAL_SECTION g_logCs;
extern volatile bool g_stop;
extern DWORD g_startTick;
extern SERVICE_STATUS_HANDLE g_ssh;
extern SERVICE_STATUS g_ss;
void logline(const std::string& s);
bool probe(Target& t, std::string& why, std::string* body = nullptr);
int  fixup_tasks();
int  fixup_svcs();
void guard_loop();
int  okx_probe(std::string* msg, int* ms);

// 计划任务(COM)
bool task_running(const char* name, bool* exists);
HRESULT task_run2(const char* name);
HRESULT task_stop(const char* name);
bool ts_init();

// 服务(SCM)
bool svc_state(const char* name, DWORD* state);
bool svc_start(const char* name);
bool svc_restart(const char* name);
DWORD find_pid(const char* proc, const char* exePath);
