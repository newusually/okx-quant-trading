/* ==================================================================
 * tphub_main.cpp — OKX 量化交易系统 · C++ 实时止盈引擎 (tphub.exe)
 *
 * 【文件职责】
 *   独立的止盈盯盘进程：以 10 秒(5~10s节拍)轮询 OKX 全部持仓，
 *   一旦现价 >= 止盈价立即市价全平(close-position)。
 *   止盈价 = 持仓均价 × (1 + 2%)，20X 杠杆下等价于 ROI 40%；
 *   策略为"永不止损"，只做止盈。
 *
 * 【内容清单】
 *   1. tplog / heartbeat            —— 日志落盘与 5 秒心跳文件(供守护探活)
 *   2. tp_scan()                    —— 止盈主逻辑：拉持仓→算止盈线→达标即市价全平
 *                                      并同步台账(position_detail 关单/trade_flow 流水/grid_signal 清理)
 *   3. crash_handler                —— 未处理异常捕获，记录崩溃日志
 *   4. another_instance_running()   —— 跨会话单实例硬检查(按 exe 全路径比对)
 *   5. WinMain                      —— 入口：互斥量单实例 + 高优先级 + 无限轮询循环
 *
 * 【背景】价格口径止盈与杠杆无关，与 gridmon 的 tp_px、网页 K 线止盈线完全同源；
 *         对 OKX 杠杆上限 <20x 的合约(自适应降档)旧 ROI 口径会漏平，2026-09-28 已修正。
 * ================================================================== */
#include "../common/hub.h"   // 公共组件头文件：DB(hub_db)/OKX签名请求(hub_okx)/工具函数(hub_util) 及 JSON 解析等
#include <tlhelp32.h>        // Windows 工具帮助 API：进程快照(Process32First/Next)，用于单实例检查
#include <cstdio>            // C 标准输入输出：FILE/fopen/fprintf/snprintf
#include <cstring>           // C 字符串处理：strcmp 等
#include <ctime>             // C 时间处理：time/localtime_s/strftime

// ---------------- 日志(tphub 专用文件, 保持原路径) ----------------
static const char* TP_LOG = "E:\\datas\\log\\tphub.log";   // 止盈引擎专用日志文件路径
static void tplog(const std::string& s) {   // 向日志文件追加一行(带时间戳)，供人工排查与观察止盈行为
    FILE* f = fopen(TP_LOG, "a");   // 以追加模式打开日志文件
    if (!f) return;                 // 打开失败(如目录不存在)则静默放弃，不影响主流程
    time_t t = time(nullptr); struct tm lt; localtime_s(&lt, &t);   // 取当前时间并转本地时间结构
    char ts[32]; strftime(ts, sizeof(ts), "%Y-%m-%d %H:%M:%S", &lt);   // 格式化为 "年-月-日 时:分:秒"
    fprintf(f, "[%s] %s\n", ts, s.c_str());   // 写入 "[时间戳] 内容" 一行
    fclose(f);   // 关闭文件(每次写完即关，保证落盘)
}
// 5秒心跳文件, 供 guard.exe 判断"假死"   // 守护进程通过该文件的更新时间判断本进程是否存活
static void heartbeat() {   // 心跳：把当前 Unix 时间戳写入心跳文件
    FILE* f = fopen("E:\\datas\\log\\hb_tphub.txt", "w");   // 以覆盖写模式打开心跳文件
    if (!f) return;   // 打开失败则放弃(守护进程会因心跳过期而拉起重启)
    fprintf(f, "%lld\n", (long long)time(nullptr));   // 写入当前 Unix 秒级时间戳
    fclose(f);   // 关闭文件
}

// ---------------- 止盈主逻辑 ----------------
// 口径(2026-09-28 修正): **价格口径** last >= avg*(1+TP_PCT), 与 gridmon 的 tp_px、网页K线止盈线
//   完全一致, 且与杠杆无关。
//   旧口径 uplRatio>=0.40 只在 20x 时等价于"价格+2%"; 对 OKX 上限低于 20x 的合约
//   (okx_set_leverage_adaptive 会降档, 实测 LIGHT-USDT-SWAP = 10x) 需要价格 +4% 才触发,
//   于是"页面已越线但永不卖"。20x 合约两种口径逐点等价 → 本次修正不改变 20x 合约行为。
static const double TP_PCT = 0.02;   // 止盈: 价格 +2% (唯一口径)——止盈价=均价×(1+2%)，20X 杠杆下 ROI 40%
static const double TP_ROI = 0.40;   // 兜底: 仅当 avg/last 取不到时按 ROI 判定——ROI>=40% 才平仓
static std::string g_nearTxt;        // 最近一轮"距止盈最近"的合约描述——写入心跳日志便于观察
static double      g_nearGap = 1e9;  // 对应的距离(负数=已越过)——现价到止盈线的百分比距离

static int tp_scan() {   // 止盈主扫描：拉取全部持仓并逐个判定，返回值 = 平仓数×1000 + 检查数(负数=接口失败)
    std::string body;   // 存放 OKX 接口返回的原始 JSON 体
    if (!okx_private("GET", "/api/v5/account/positions?instType=SWAP", "", body)) return -1;   // 签名拉取全部永续合约持仓，失败返回 -1
    if (body.find("\"code\":\"0\"") == std::string::npos) {   // 校验 OKX 返回码是否为 "0"(成功)
        tplog("positions code!=0: " + body.substr(0, 160));   // 非 0 则截取前 160 字符记日志
        return -1;   // 返回 -1 表示本轮扫描失败
    }
    int checked = 0, closed = 0;   // checked=本轮实际检查的仓位数, closed=本轮成功平仓数
    g_nearTxt.clear(); g_nearGap = 1e9;          // 每轮重置"最接近止盈"记录
    for (auto& obj : j_split_objects(body)) {   // 把返回 JSON 拆成逐个持仓对象并遍历
        double pos = j_num(obj, "pos");          // 持仓数量(张)
        std::string side = j_str(obj, "posSide");   // 持仓方向(long/short/net)
        std::string inst = j_str(obj, "instId");    // 合约 ID，如 BTC-USDT-SWAP
        if (pos <= 0 || side == "short" || inst.empty()) continue;   // 跳过空仓/空头/无合约 ID 的记录(只管多单)
        checked++;   // 通过过滤，计为一次有效检查
        double avg = j_num(obj, "avgPx");          // 持仓均价
        double lastPx = j_num(obj, "last");        // 最新成交价
        if (lastPx <= 0) lastPx = j_num(obj, "markPx");   // last 异常时退用标记价格 markPx
        double lever = j_num(obj, "lever"); if (lever <= 0) lever = 20;   // 杠杆倍数，取不到默认按 20x
        double roi = j_num(obj, "uplRatio");       // OKX 返回的未实现收益率(ROI)
        if (roi <= -1 || roi == 0) roi = (avg > 0) ? (lastPx / avg - 1.0) * lever : 0;   // ROI 异常时自行计算:(现价/均价-1)×杠杆
        double tpPx = avg * (1.0 + TP_PCT);        // 止盈价 = 均价 × (1 + 2%)，20X 杠杆下即 ROI 40%
        // 记录距止盈最近者(>0=还差多少, <0=已越过), 供心跳日志观察
        if (avg > 0 && lastPx > 0) {               // 均价与现价都有效才计算距离
            double gap = tpPx / lastPx - 1.0;      // gap = 止盈价/现价 - 1，负数表示现价已越过止盈线
            if (gap < g_nearGap) {                 // 发现"距止盈更近"的持仓则更新记录
                g_nearGap = gap;                   // 更新最小距离
                char nb[160];                      // 描述文本缓冲区
                snprintf(nb, sizeof(nb), "%s 现价%.6g/均价%.6g %s%.2f%% ROI%.1f%%@%.0fx",   // 格式化：合约 现价/均价 方向 距离 ROI@杠杆
                         inst.c_str(), lastPx, avg, gap <= 0 ? "已越过" : "距止盈", gap * 100, roi * 100, lever);   // 填充各字段
                g_nearTxt = nb;                    // 保存到全局，供心跳日志输出
            }
        }
        // 价格口径优先(杠杆无关, 与页面止盈线同源); 取不到价格时才退回 ROI 口径
        bool hit = (avg > 0 && lastPx > 0) ? (lastPx >= tpPx) : (roi >= TP_ROI);   // 判定止盈：现价>=止盈价(主口径)；价格无效时 ROI>=40% 兜底
        if (!hit) continue;   // 未达止盈线则继续检查下一个持仓
        // ---- 止盈触发: 立即市价全平 ----
        char lg[256];   // 日志文本缓冲区
        snprintf(lg, sizeof(lg),   // 格式化止盈触发日志：合约/现价/止盈线/均价/百分比/等效ROI/杠杆
                 "止盈触发 %s 现价%.8g >= 止盈线%.8g (均价%.8g +%.0f%%) 等效ROI=%.1f%%@%.0fx → 市价全平",
                 inst.c_str(), lastPx, tpPx, avg, TP_PCT * 100, roi * 100, lever);   // 填充日志各字段
        tplog(lg);   // 写入触发日志
        std::string closer = "{\"instId\":\"" + inst + "\",\"mgnMode\":\"cross\",\"posSide\":\"long\",\"cldOrdPx\":\"\"}";   // 组装 close-position 请求体：全仓/多方向/市价(cldOrdPx 留空)
        std::string resp;   // 存放平仓接口返回
        if (!okx_private("POST", "/api/v5/trade/close-position", closer, resp)) {   // 调 OKX 市价全平接口(HTTP 层失败)
            tplog("close-position HTTP FAIL " + inst);   // 记录 HTTP 失败日志
            continue;   // 放弃该持仓，继续处理下一个
        }
        if (resp.find("\"code\":\"0\"") == std::string::npos) {   // 返回码非 0：需甄别"已无仓位"类错误
            // 已无仓位等情况: sCode 51016/51020 视为成功   // 51016=订单不存在/已平, 51020=仓位不存在——均视为已达成目的
            if (resp.find("51016") == std::string::npos && resp.find("51020") == std::string::npos) {   // 既非 51016 也非 51020 才算真失败
                tplog("close-position FAIL " + inst + ": " + resp.substr(0, 220));   // 记录真实失败原因(截前 220 字符)
                continue;   // 放弃该持仓
            }
        }
        closed++;   // 平仓成功，计数 +1
        // 台账: 关仓位 + 流水 + 看板清理 (真盈亏由 OKX fills backfill 统一写入)   // 即: 本地台账只关状态，盈亏数字由另一个回填进程补
        db_ex("UPDATE position_detail SET status='CLOSED', close_time=NOW() WHERE inst_id='" + inst + "' AND status='OPEN'");   // 台账仓位表：该合约 OPEN 记录置为 CLOSED 并记平仓时间
        db_ex("DELETE FROM grid_signal WHERE inst_id='" + inst + "'");   // 清理监测看板：删除该合约的 grid_signal 记录(已平仓不再监测)
        char sql[640];   // SQL 语句缓冲区
        snprintf(sql, sizeof(sql),   // 拼接插入 trade_flow 交易流水的 SQL
            "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,profit,remark)"
            " VALUES (NOW(),'%s','tp_close','long',%.10g,NULL,"
            "'C++ tphub 止盈平仓 现价%.4f>=止盈线%.6f(均价+2%%) 等效ROI=%.1f%%@%.0fx (真盈亏由OKX fills backfill写入)')",   // 记录平仓价/止盈线/等效ROI 等详情到备注
            inst.c_str(), lastPx, lastPx, tpPx, roi * 100, lever);   // 填充 SQL 各占位参数
        db_ex(sql);   // 执行插入：交易流水落库(profit 置 NULL，真盈亏由回填进程写)
        snprintf(lg, sizeof(lg), "止盈完成 %s 台账已关", inst.c_str());   // 格式化"止盈完成"日志
        tplog(lg);   // 写入完成日志
    }
    return closed * 1000 + checked;   // 打包返回：高 3 位是平仓数，低 3 位是检查数(供心跳日志展示)
}

// ---------------- 崩溃处理 / 单实例 ----------------
static LONG WINAPI crash_handler(EXCEPTION_POINTERS*) {   // 未处理异常过滤器：崩溃时兜底写日志后退出
    tplog("!! 崩溃退出, 计划任务1分钟内自动拉起 !!");   // 记录崩溃日志(守护计划任务每分钟拉起，实现自愈)
    return EXCEPTION_EXECUTE_HANDLER;   // 让系统按默认方式结束进程
}

// 单实例硬检查(跨会话双开防护, 按 exe 全路径比对)   // 互斥量只防同会话，此函数补防不同会话(如服务/计划任务)双开
static bool another_instance_running() {   // 遍历系统进程，比较 exe 全路径是否与自身相同
    char self[MAX_PATH * 2] = { 0 };   // 自身 exe 路径缓冲区
    GetModuleFileNameA(NULL, self, sizeof(self) - 1);   // 取自身 exe 完整路径
    std::string me = self;   // 存为字符串便于比较
    for (auto& c : me) c = (char)tolower((unsigned char)c);   // 统一转小写(Windows 路径大小写不敏感)
    HANDLE snap = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);   // 创建系统进程快照
    if (snap == INVALID_HANDLE_VALUE) return false;   // 快照失败则放弃检查(宁可放过也不误杀)
    PROCESSENTRY32W pe; pe.dwSize = sizeof(pe);   // 进程条目结构，必须先设 dwSize
    DWORD selfPid = GetCurrentProcessId();   // 自身 PID(遍历时跳过)
    bool dup = false;   // 是否发现重复实例
    if (Process32FirstW(snap, &pe)) {   // 取第一个进程条目
        do {
            if (pe.th32ProcessID == selfPid) continue;   // 跳过自己
            HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pe.th32ProcessID);   // 以最小查询权限打开进程(避免权限不足)
            if (!hp) continue;   // 打不开(权限不足/已退出)则跳过
            char buf[MAX_PATH * 2] = { 0 }; DWORD len = sizeof(buf) - 1;   // 对方 exe 路径缓冲区
            if (QueryFullProcessImageNameA(hp, 0, buf, &len)) {   // 查询对方 exe 完整路径
                std::string other = buf;   // 转字符串
                for (auto& c : other) c = (char)tolower((unsigned char)c);   // 统一转小写
                if (other == me) dup = true;   // 路径完全一致 → 已有实例在跑
            }
            CloseHandle(hp);   // 关闭进程句柄
            if (dup) break;   // 发现重复即提前结束遍历
        } while (Process32NextW(snap, &pe));   // 继续下一个进程
    }
    CloseHandle(snap);   // 关闭快照句柄
    return dup;   // 返回是否已有重复实例
}

int WINAPI WinMain(HINSTANCE, HINSTANCE, LPSTR, int) {   // Windows GUI 程序入口(-mwindows 无控制台窗口)
    SetUnhandledExceptionFilter(crash_handler);   // 注册崩溃兜底：异常时写日志再退出
    HANDLE mx = CreateMutexA(nullptr, TRUE, "Global\\finally_tphub");   // 创建全局命名互斥量(同会话单实例第一道防线)
    if (mx && GetLastError() == ERROR_ALREADY_EXISTS) return 0;   // 互斥量已存在 → 同会话已有实例，直接退出
    if (another_instance_running()) return 0;   // 跨会话双开硬拦截   // 第二道防线：exe 全路径比对，防跨会话双开   // 单实例
    SetPriorityClass(GetCurrentProcess(), ABOVE_NORMAL_PRIORITY_CLASS); // 止盈盯盘要快, 优先级高于引擎   // 提高进程优先级，保证止盈扫描不被交易引擎挤占 CPU
    tplog("==== tphub C++ 实时止盈引擎启动 pid=" + std::to_string(GetCurrentProcessId()) +   // 记录启动日志：进程 ID 与止盈口径说明
          " (5s节拍, 现价>=均价+2% 全平; 杠杆无关) ====");   // 启动日志第二行
    for (int i = 0; i < 60; i++) { if (db_ex("SELECT 1")) break; Sleep(5000); }   // 等待数据库可用：每 5 秒试一次，最多等 5 分钟
    int beats = 0;   // 心跳计数器(用于约每 5 分钟输出一次状态日志)
    heartbeat();   // 启动后立即写一次心跳文件
    while (true) {   // 主循环：永久轮询
        int r = tp_scan();   // 执行一轮止盈扫描(负数=接口失败，否则=平仓数×1000+检查数)
        heartbeat();                         // 每5秒心跳文件(供守护进程探活)   // 每轮扫描后刷新心跳，证明进程存活
        if (r < 0) Sleep(4000); // 失败稍等重试   // 扫描失败(网络/接口异常)多等 4 秒再重试
        else if (++beats % 60 == 0) { // 约每5分钟心跳   // 每 60 轮(约 5 分钟)输出一次状态心跳日志
            char lg[256];   // 日志缓冲区
            snprintf(lg, sizeof(lg), "心跳: 监控正常 (最近一轮检查%d仓) | 最接近止盈: %s",   // 格式化：检查仓位数 + 最接近止盈的合约
                     r % 1000, g_nearTxt.empty() ? "无持仓" : g_nearTxt.c_str());   // r 低 3 位是检查数；无持仓时给占位文案
            tplog(lg);   // 写入心跳日志
        }
        Sleep(5000);   // 轮询间隔 5 秒(节拍)
    }
    return 0;   // 不会到达(死循环)，仅为语法完整
}
