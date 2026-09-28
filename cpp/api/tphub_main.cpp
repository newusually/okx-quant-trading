// ==================================================================
// tphub_main.cpp — C++ 实时止盈引擎 (组件化拆分自 tphub.cpp, 业务逻辑零改动)
// 职责: 每5秒全市场持仓监控, 现价 >= 均价×1.02 (价格+2%) → 立即市价全平(close-position)
//       + 台账关闭 + trade_flow 事件 + grid_signal 清理
// 口径: **价格口径 现价>=均价+2%** 止盈(与 gridmon 的 tp_px、网页K线止盈线完全同源, 杠杆无关),
//       不止损, cross 全仓, posSide long。
//       20x 时该口径与 ROI>=40% 逐点等价; 但 OKX 上限 <20x 的合约(自适应降档, 实测 LIGHT=10x)
//       旧 ROI 口径要价格 +4% 才平 → "页面已越线却永不卖", 2026-09-28 修正。
// 依赖: common 公共组件(hub_db/hub_okx/hub_util) — DB/签名/JSON 全部复用
// 自愈: 单实例互斥 + 计划任务 finally_tphub 每分钟拉起
// 编译: g++ -O2 -mwindows -static -o bin/tphub.exe api/tphub_main.cpp common/... (见 build 命令)
// ==================================================================
#include "../common/hub.h"
#include <tlhelp32.h>
#include <cstdio>
#include <cstring>
#include <ctime>

// ---------------- 日志(tphub 专用文件, 保持原路径) ----------------
static const char* TP_LOG = "E:\\datas\\log\\tphub.log";
static void tplog(const std::string& s) {
    FILE* f = fopen(TP_LOG, "a");
    if (!f) return;
    time_t t = time(nullptr); struct tm lt; localtime_s(&lt, &t);
    char ts[32]; strftime(ts, sizeof(ts), "%Y-%m-%d %H:%M:%S", &lt);
    fprintf(f, "[%s] %s\n", ts, s.c_str());
    fclose(f);
}
// 5秒心跳文件, 供 guard.exe 判断"假死"
static void heartbeat() {
    FILE* f = fopen("E:\\datas\\log\\hb_tphub.txt", "w");
    if (!f) return;
    fprintf(f, "%lld\n", (long long)time(nullptr));
    fclose(f);
}

// ---------------- 止盈主逻辑 ----------------
// 口径(2026-09-28 修正): **价格口径** last >= avg*(1+TP_PCT), 与 gridmon 的 tp_px、网页K线止盈线
//   完全一致, 且与杠杆无关。
//   旧口径 uplRatio>=0.40 只在 20x 时等价于"价格+2%"; 对 OKX 上限低于 20x 的合约
//   (okx_set_leverage_adaptive 会降档, 实测 LIGHT-USDT-SWAP = 10x) 需要价格 +4% 才触发,
//   于是"页面已越线但永不卖"。20x 合约两种口径逐点等价 → 本次修正不改变 20x 合约行为。
static const double TP_PCT = 0.02;   // 止盈: 价格 +2% (唯一口径)
static const double TP_ROI = 0.40;   // 兜底: 仅当 avg/last 取不到时按 ROI 判定
static std::string g_nearTxt;        // 最近一轮"距止盈最近"的合约描述
static double      g_nearGap = 1e9;  // 对应的距离(负数=已越过)

static int tp_scan() {
    std::string body;
    if (!okx_private("GET", "/api/v5/account/positions?instType=SWAP", "", body)) return -1;
    if (body.find("\"code\":\"0\"") == std::string::npos) {
        tplog("positions code!=0: " + body.substr(0, 160));
        return -1;
    }
    int checked = 0, closed = 0;
    g_nearTxt.clear(); g_nearGap = 1e9;          // 每轮重置"最接近止盈"记录
    for (auto& obj : j_split_objects(body)) {
        double pos = j_num(obj, "pos");
        std::string side = j_str(obj, "posSide");
        std::string inst = j_str(obj, "instId");
        if (pos <= 0 || side == "short" || inst.empty()) continue;
        checked++;
        double avg = j_num(obj, "avgPx");
        double lastPx = j_num(obj, "last");
        if (lastPx <= 0) lastPx = j_num(obj, "markPx");
        double lever = j_num(obj, "lever"); if (lever <= 0) lever = 20;
        double roi = j_num(obj, "uplRatio");
        if (roi <= -1 || roi == 0) roi = (avg > 0) ? (lastPx / avg - 1.0) * lever : 0;
        double tpPx = avg * (1.0 + TP_PCT);
        // 记录距止盈最近者(>0=还差多少, <0=已越过), 供心跳日志观察
        if (avg > 0 && lastPx > 0) {
            double gap = tpPx / lastPx - 1.0;
            if (gap < g_nearGap) {
                g_nearGap = gap;
                char nb[160];
                snprintf(nb, sizeof(nb), "%s 现价%.6g/均价%.6g %s%.2f%% ROI%.1f%%@%.0fx",
                         inst.c_str(), lastPx, avg, gap <= 0 ? "已越过" : "距止盈", gap * 100, roi * 100, lever);
                g_nearTxt = nb;
            }
        }
        // 价格口径优先(杠杆无关, 与页面止盈线同源); 取不到价格时才退回 ROI 口径
        bool hit = (avg > 0 && lastPx > 0) ? (lastPx >= tpPx) : (roi >= TP_ROI);
        if (!hit) continue;
        // ---- 止盈触发: 立即市价全平 ----
        char lg[256];
        snprintf(lg, sizeof(lg),
                 "止盈触发 %s 现价%.8g >= 止盈线%.8g (均价%.8g +%.0f%%) 等效ROI=%.1f%%@%.0fx → 市价全平",
                 inst.c_str(), lastPx, tpPx, avg, TP_PCT * 100, roi * 100, lever);
        tplog(lg);
        std::string closer = "{\"instId\":\"" + inst + "\",\"mgnMode\":\"cross\",\"posSide\":\"long\",\"cldOrdPx\":\"\"}";
        std::string resp;
        if (!okx_private("POST", "/api/v5/trade/close-position", closer, resp)) {
            tplog("close-position HTTP FAIL " + inst);
            continue;
        }
        if (resp.find("\"code\":\"0\"") == std::string::npos) {
            // 已无仓位等情况: sCode 51016/51020 视为成功
            if (resp.find("51016") == std::string::npos && resp.find("51020") == std::string::npos) {
                tplog("close-position FAIL " + inst + ": " + resp.substr(0, 220));
                continue;
            }
        }
        closed++;
        // 台账: 关仓位 + 流水 + 看板清理 (真盈亏由 OKX fills backfill 统一写入)
        db_ex("UPDATE position_detail SET status='CLOSED', close_time=NOW() WHERE inst_id='" + inst + "' AND status='OPEN'");
        db_ex("DELETE FROM grid_signal WHERE inst_id='" + inst + "'");
        char sql[640];
        snprintf(sql, sizeof(sql),
            "INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,profit,remark)"
            " VALUES (NOW(),'%s','tp_close','long',%.10g,NULL,"
            "'C++ tphub 止盈平仓 现价%.4f>=止盈线%.6f(均价+2%%) 等效ROI=%.1f%%@%.0fx (真盈亏由OKX fills backfill写入)')",
            inst.c_str(), lastPx, lastPx, tpPx, roi * 100, lever);
        db_ex(sql);
        snprintf(lg, sizeof(lg), "止盈完成 %s 台账已关", inst.c_str());
        tplog(lg);
    }
    return closed * 1000 + checked;
}

// ---------------- 崩溃处理 / 单实例 ----------------
static LONG WINAPI crash_handler(EXCEPTION_POINTERS*) {
    tplog("!! 崩溃退出, 计划任务1分钟内自动拉起 !!");
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
    HANDLE mx = CreateMutexA(nullptr, TRUE, "Global\\finally_tphub");
    if (mx && GetLastError() == ERROR_ALREADY_EXISTS) return 0;
    if (another_instance_running()) return 0;   // 跨会话双开硬拦截   // 单实例
    SetPriorityClass(GetCurrentProcess(), ABOVE_NORMAL_PRIORITY_CLASS); // 止盈盯盘要快, 优先级高于引擎
    tplog("==== tphub C++ 实时止盈引擎启动 pid=" + std::to_string(GetCurrentProcessId()) +
          " (5s节拍, 现价>=均价+2% 全平; 杠杆无关) ====");
    for (int i = 0; i < 60; i++) { if (db_ex("SELECT 1")) break; Sleep(5000); }
    int beats = 0;
    heartbeat();
    while (true) {
        int r = tp_scan();
        heartbeat();                         // 每5秒心跳文件(供守护进程探活)
        if (r < 0) Sleep(4000); // 失败稍等重试
        else if (++beats % 60 == 0) { // 约每5分钟心跳
            char lg[256];
            snprintf(lg, sizeof(lg), "心跳: 监控正常 (最近一轮检查%d仓) | 最接近止盈: %s",
                     r % 1000, g_nearTxt.empty() ? "无持仓" : g_nearTxt.c_str());
            tplog(lg);
        }
        Sleep(5000);
    }
    return 0;
}
