/* ==================================================================
 * tradehub_main.cpp — C++ 交易中枢主进程壳
 *
 * 【文件职责】
 *   交易主进程入口(WinMain)：两种运行模式——
 *   ① selftest 只读自检模式(命令行带 selftest): 验证 DB/池/K线喂库/
 *      金▲信号/OKX 公有&私有接口，全程不下单不写交易表，切换前安全验证；
 *   ② 常驻主循环: 双线程——引擎线程 10 秒节拍(①止盈由tphub接管
 *      ②持仓管理+跌够三多头加仓 ③60s对账 ④每分钟pnl快照 ⑤新5m槽
 *      全池增量喂库+买入扫描) + 回填线程 3 秒节拍(成交/仓位回填+洞扫描)；
 *      启动横幅打印策略口径"买入=5m+3m金▲共振·2U·20X·加仓=跌够三多头
 *      +1U(同根3m不重复)·永不止损"。
 *
 * 【函数清单】
 *   tlog()                  tradehub.txt 带时间戳文本日志(互斥保护)
 *   eng_log()               引擎日志双写: logs 表(网页可见) + tradehub.txt
 *   trade_hb()              心跳文件 hb_<name>.txt 覆盖写(供 guard 判卡死)
 *   crash_handler()         未处理异常回调: 留日志后退出, guard 数秒内拉起
 *   another_instance_running() 跨会话双开硬拦截(进程快照比对可执行文件路径)
 *   selftest()              只读自检(DB/池/喂库/金▲/OKX接口)
 *   WinMain()               入口: selftest 分流 / 全局互斥单实例 / 等 DB /
 *                           打印策略横幅 / 启动回填线程 + 引擎主循环
 *
 * 【数据流】
 *   启动 → DB 连接重试(60次×5s) → eng_log 横幅(策略口径落 logs 表)
 *   → backfill_loop(子线程, 3s节拍回填) + engine_loop(主线程, 10s节拍交易)
 *   → 退出时 g_run=false 通知两循环收尾
 * ================================================================== */
// ==================================================================
// tradehub_main.cpp — C++ 交易中枢主壳
//   替代 engine.php(交易引擎) + backfill.php(回填) + pool_sync.php(池同步)
//   双线程: 引擎 10 秒节拍 | 回填 3 秒节拍(每分钟成交回填 + 每轮洞扫描)
//   自愈: 全局互斥 + 跨会话双开硬拦截 + guard.exe 守护 hb_tradehub.txt
// ==================================================================
#include "tradehub.h"                          // 本组件公共声明(LOCK_* 硬锁/各分区函数)
#include <tlhelp32.h>                          // 进程快照 API(双开拦截用)
#include <cstdio>                              // FILE/fopen/fprintf/snprintf
#include <cstring>                             // strstr
#include <ctime>                               // time/localtime_s/strftime
#include <thread>                              // std::thread(回填线程)
#include <mutex>                               // std::mutex(日志互斥)

volatile bool g_run = true;                    // 全局运行标志(两主循环的退出开关)

static const char* TH_LOG = "E:\\datas\\log\\tradehub.txt";   // 主壳文本日志路径
static std::mutex g_tlogMtx;                   // tlog 写文件互斥锁(双线程共用)

void tlog(const std::string& s) {              // 带时间戳追加写 tradehub.txt
    std::lock_guard<std::mutex> lk(g_tlogMtx); // 加锁防止双线程写文件交错
    FILE* f = fopen(TH_LOG, "a");              // 追加模式打开日志文件
    if (!f) return;                            // 打开失败静默放弃
    time_t t = time(nullptr);                  // 当前 Unix 秒
    struct tm lt; localtime_s(&lt, &t);        // 转本地时间结构
    char ts[32]; strftime(ts, sizeof(ts), "%Y-%m-%d %H:%M:%S", &lt);  // 格式化时间前缀
    fprintf(f, "[%s] %s\n", ts, s.c_str());    // 写一行 "[时间] 内容"
    fclose(f);                                 // 关闭文件(每次开写关, 防崩溃丢日志)
}

// 引擎日志: 双写 logs 表(网页可见) + tradehub.txt
void eng_log(const char* level, const char* module, const std::string& msg) {  // 级别/模块/消息
    std::string m = msg.size() > 2000 ? msg.substr(0, 2000) : msg;  // 截断超长消息防 SQL/文件过大
    db_ex("INSERT INTO logs (log_time,level,module,message) VALUES (NOW(),'" + std::string(level) +    // 写 logs 表
          "','" + std::string(module) + "','" + sqlesc(m) + "')");   // 模块与转义后的消息入库
    tlog(std::string("[") + level + "] " + module + " " + m);  // 同步落一份到文本日志
}

// 心跳文件(覆盖写, 供 guard.exe 判"进程卡死")
void trade_hb(const char* name) {              // name=tradehub/backfill 两个心跳
    char p[160];                               // 心跳文件路径缓冲
    snprintf(p, sizeof(p), "E:\\datas\\log\\hb_%s.txt", name);  // 拼 hb_<name>.txt 路径
    FILE* f = fopen(p, "w");                   // 覆盖写模式打开
    if (!f) return;                            // 打开失败静默放弃
    fprintf(f, "%lld loop pid=%lu\n", (long long)time(nullptr), GetCurrentProcessId());  // 写时间戳+PID
    fclose(f);                                 // 关闭
}

static LONG WINAPI crash_handler(EXCEPTION_POINTERS*) {   // 未处理异常回调(结构化异常)
    tlog("!! 未处理异常(崩溃), 进程将退出, guard 将在数秒内自动拉起 !!");  // 留崩溃日志
    return EXCEPTION_EXECUTE_HANDLER;          // 让进程按默认方式退出(由 guard 拉起)
}

// 跨会话双开硬拦截(命名互斥体在 Session 0/1 之间可能落到不同命名空间)
static bool another_instance_running() {       // 返回 true=已有同名进程在跑
    char self[MAX_PATH * 2] = { 0 };           // 自身可执行文件路径缓冲
    GetModuleFileNameA(NULL, self, sizeof(self) - 1);  // 取自身完整路径
    std::string me = self;                     // 转字符串
    for (auto& c : me) c = (char)tolower((unsigned char)c);   // 统一小写便于比对
    HANDLE snap = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);  // 建全系统进程快照
    if (snap == INVALID_HANDLE_VALUE) return false;   // 快照失败则放行(互斥体已兜底)
    PROCESSENTRY32W pe; pe.dwSize = sizeof(pe);       // 进程条目结构并初始化大小
    DWORD selfPid = GetCurrentProcessId();     // 自身 PID(跳过自己)
    bool dup = false;                          // 重复标志
    if (Process32FirstW(snap, &pe)) {          // 遍历第一个进程
        do {                                   // 循环遍历快照中所有进程
            if (pe.th32ProcessID == selfPid) continue;   // 跳过自身
            HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pe.th32ProcessID);  // 打开进程(仅查路径)
            if (!hp) continue;                 // 打不开(权限)则跳过
            char buf[MAX_PATH * 2] = { 0 }; DWORD len = sizeof(buf) - 1;   // 对方可执行文件路径缓冲
            if (QueryFullProcessImageNameA(hp, 0, buf, &len)) {        // 查询对方进程映像路径
                std::string other = buf;       // 转字符串
                for (auto& c : other) c = (char)tolower((unsigned char)c); // 统一小写
                if (other == me) dup = true;   // 路径相同 → 已有双开
            }
            CloseHandle(hp);                   // 关闭进程句柄
            if (dup) break;                    // 发现双开提前退出循环
        } while (Process32NextW(snap, &pe));   // 继续下一个进程
    }
    CloseHandle(snap);                         // 关闭快照句柄
    return dup;                                // 返回是否存在双开
}

// ============ 只读自检模式 (tradehub.exe selftest) ============
// 验证: DB连接 / 池读取 / K线喂库 / 黄金坑信号 / OKX私有&公有接口
// 全程不下单、不写任何交易表, 用于切换前安全验证
static int selftest() {                        // 返回非0表示自检失败
    tlog("==== SELFTEST 开始 (只读, 绝不下单) ====");   // 自检开始标记
    if (!db_ex("SELECT 1")) { tlog("DB FAIL"); return 1; }  // ① 验证 DB 连通性
    tlog("DB OK (trading@127.0.0.1)");         // DB 正常

    std::vector<std::string> pool = pool_cached();      // ② 读权威合约池(缓存口径)
    tlog("池内合约: " + std::to_string(pool.size()));   // 打印池大小
    if (pool.empty()) { tlog("池为空, 终止"); return 2; }   // 池空视为自检失败

    int fed = 0;                               // 已喂库合约计数
    for (int i = 0; i < 3 && i < (int)pool.size(); i++) {   // 抽前 3 个合约做喂库+信号验证
        std::vector<Bar> r5 = kl_engine_rows(pool[i], "5m");  // 取 5m K线(缺自动补)
        std::vector<Bar> r3 = kl_engine_rows(pool[i], "3m");  // 取 3m K线(缺自动补)
        sig_feed(pool[i], "5m", r5);           // 喂 5m 进 sigcore 内存库
        sig_feed(pool[i], "3m", r3);           // 喂 3m 进 sigcore 内存库
        double k5 = 0, k3 = 0;                 // 5m/3m 金▲ KE 值
        std::string i5, i3;                    // 5m/3m 信号说明
        bool f5 = sig_store_gold(pool[i], "5m", k5, i5);   // 判 5m 金▲
        bool f3 = sig_store_gold(pool[i], "3m", k3, i3);   // 判 3m 金▲
        tlog(pool[i] + " 5m=" + std::to_string(r5.size()) + "根 金▲=" + (f5 ? "Y" : "N") +   // 打印 5m 根数/金▲/KE
             " [KE=" + jnum(k5) + " " + i5 + "] | 3m=" + std::to_string(r3.size()) + "根 金▲=" + (f3 ? "Y" : "N") +   // 打印 3m 同口径
             " [KE=" + jnum(k3) + " " + i3 + "]");
        fed++;                                 // 完成一个合约
    }
    tlog("喂库测试 " + std::to_string(fed) + " 个合约 | 内存库序列数=" + std::to_string(sig_store_stats()));   // ③ 喂库统计

    std::vector<std::string> objs;             // OKX 持仓对象数组
    bool pok = okx_positions(objs);            // ④ 验证 OKX 私有持仓接口
    int livePos = 0;                           // 有效多头持仓数
    for (auto& o : objs) if (j_num(o, "pos") > 0) livePos++;   // 统计 pos>0 的条目
    tlog(std::string("OKX 持仓接口: ") + (pok ? "OK" : "FAIL") +   // 打印持仓接口结果
         " | 持仓条数=" + std::to_string(objs.size()) + " 有效多头=" + std::to_string(livePos));

    tlog("ETH 最新价=" + jnum(okx_last_price("ETH-USDT-SWAP")) +    // ⑤ 验证公共行情接口
         " ctVal=" + jnum(okx_ctval("ETH-USDT-SWAP")));             // 最新价+每张面值
    std::string fb;                            // 成交明细响应体
    bool fok = okx_private("GET", "/api/v5/trade/fills-history?instType=SWAP&limit=1", "", fb);  // 验证私有成交接口
    tlog(std::string("成交明细接口: ") + (fok ? "OK" : "FAIL") +   // 打印成交接口结果
         " | code0=" + ((fb.find("\"code\":\"0\"") != std::string::npos) ? "Y" : "N"));  // 校验返回码
    tlog("==== SELFTEST 结束 ====");           // 自检结束标记
    return 0;                                  // 自检通过
}

int WINAPI WinMain(HINSTANCE, HINSTANCE, LPSTR, int) {   // GUI 无窗口入口
    if (strstr(GetCommandLineA(), "selftest")) return selftest();  // 命令行带 selftest → 只读自检模式
    SetUnhandledExceptionFilter(crash_handler);          // 注册崩溃回调(留日志后退出)
    HANDLE mx = CreateMutexA(nullptr, TRUE, "Global\\finally_tradehub");   // 全局命名互斥体(单实例第一道)
    if (mx && GetLastError() == ERROR_ALREADY_EXISTS) return 0;   // 已有实例 → 直接退出
    if (another_instance_running()) return 0;  // 严禁双开: 双引擎会重复下单
    SetPriorityClass(GetCurrentProcess(), ABOVE_NORMAL_PRIORITY_CLASS);   // 提到高优先级(交易进程)

    log_setfile("E:\\datas\\log\\tradehub.txt");         // 公共组件日志也落同一文件
    tlog("==== tradehub C++ 交易中枢启动 pid=" + std::to_string(GetCurrentProcessId()) +   // 启动横幅
         " | 引擎10s节拍 + 回填3s节拍 ====");

    bool ok = false;                           // DB 连接成功标志
    for (int i = 0; i < 60; i++) { if (db_ex("SELECT 1")) { ok = true; break; } Sleep(5000); }   // 最多重试 60 次×5s
    if (!ok) { tlog("DB 连接失败60次, 退出"); return 1; }   // 连不上 DB 放弃启动
    tlog("DB 已连接 trading@127.0.0.1 (libmysql)");      // DB 就绪

    eng_log("INFO", "engine",                  // 策略口径横幅落 logs 表(网页可见)
            "===== C++ 交易中枢启动 | 买入=5m+3m金▲共振+1h振幅>2%过滤·2U·20X·加仓=跌够1%+三多头确认+1U·止盈=tphub接管·永不止损·金额trade_cfg.json热改 =====");

    trade_hb("tradehub");                      // 初始化引擎心跳文件
    trade_hb("backfill");                      // 初始化回填心跳文件

    std::thread(backfill_loop).detach();       // 回填线程(分离, 3s 节拍自主运行)
    engine_loop();                             // 主线程 = 引擎(10s 节拍, 阻塞直到退出)
    g_run = false;                             // 引擎退出后通知回填线程收尾
    tlog("==== tradehub 退出 ====");           // 退出日志
    return 0;                                  // 正常退出
}
