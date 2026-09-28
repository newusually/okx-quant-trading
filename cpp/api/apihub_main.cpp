// ==================================================================
// apihub_main.cpp — HTTP 服务器薄壳 (逐字迁移自 apihub.cpp, 逻辑零改动)
// 监听 0.0.0.0:8090; 路由 handle_req; 每连接一线程; 过载保护 24 连接
// ==================================================================
#include "../common/hub.h"
#include <tlhelp32.h>
#include <cstdio>
#include <cstring>
#include <cctype>
#include <thread>
#include <atomic>
#include <mutex>

static std::string urldec(const std::string& s) {
    std::string o; o.reserve(s.size());
    for (size_t i = 0; i < s.size(); i++) {
        if (s[i] == '+') { o += ' '; continue; }
        if (s[i] == '%' && i + 2 < s.size() && isxdigit((unsigned char)s[i + 1]) && isxdigit((unsigned char)s[i + 2])) {
            char hb[3] = { s[i + 1], s[i + 2], 0 };
            o += (char)strtol(hb, nullptr, 16);
            i += 2;
            continue;
        }
        o += s[i];
    }
    return o;
}
static std::atomic<int> g_conns(0);

static void handle_req(const std::string& target, std::string& outBody, int& outCode) {
    // target: /action?query
    std::string action = target, qs;
    size_t qp = target.find('?');
    if (qp != std::string::npos) { action = target.substr(0, qp); qs = target.substr(qp + 1); }
    if (!action.empty() && action[0] == '/') action = action.substr(1);
    Params q;
    {
        size_t i = 0;
        while (i < qs.size()) {
            size_t a = qs.find('&', i);
            if (a == std::string::npos) a = qs.size();
            std::string kv = qs.substr(i, a - i);
            size_t eq = kv.find('=');
            if (eq != std::string::npos)
                q[urldec(kv.substr(0, eq))] = urldec(kv.substr(eq + 1));
            i = a + 1;
        }
    }
    outCode = 200;
    // 零JS面板: / 与 /panel 输出 HTML, /panel.css 输出样式
    static std::string cachedPanel, cachedPanelKey;
    static std::mutex panelMx;
    if (action.empty() || action == "panel" || action == "panel.css") {
        if (action == "panel.css") {
            outBody = PANEL_CSS;
            outCode = 200;
            return;
        }
        std::string key = action + "|" + qs;
        static volatile long long lastTick = 0;
        std::lock_guard<std::mutex> lk(panelMx);
        long long nowMs = GetTickCount64();
        bool fresh = cachedPanelKey == key && !cachedPanel.empty() && (nowMs - lastTick) < 3000;
        if (!fresh) {
            cachedPanel = render_panel(q);
            cachedPanelKey = key;
            lastTick = nowMs;
        }
        outBody = cachedPanel;
        outCode = 200;
        return;
    }
    if (action == "kline")            outBody = ep_kline(q);
    else if (action == "frag")        outBody = ep_frag(q);   // 面板 AJAX 片段(C++ 渲染)
    else if (action == "live")        outBody = ep_live(q);
    else if (action == "ticker")      outBody = ep_ticker(q);
    else if (action == "trades")      outBody = ep_trades(q);
    else if (action == "stats")       outBody = ep_stats(q);
    else if (action == "livestats")   outBody = ep_livestats(q);
    else if (action == "gridmon")     outBody = ep_gridmon(q);
    else if (action == "backcheck")   outBody = ep_backcheck(q);
    else if (action == "marks")       outBody = ep_marks(q);
    else if (action == "sigs")        outBody = ep_sigs(q);
    else if (action == "sigscan")     outBody = ep_sigscan(q);
    else if (action == "boot")        outBody = ep_boot(q);
    else if (action == "symbols")     outBody = ep_symbols(q);
    else if (action == "settings")    outBody = ep_settings(q);
    else if (action == "account")     outBody = ep_account(q);
    else if (action == "health")      outBody = ep_health(q);
    else if (action == "guard")       outBody = ep_guard(q);
    else { outBody = "{\"ok\":false,\"error\":\"unknown action: " + jesc(action) + "\"}"; outCode = 404; }
}

static void serve_client(SOCKET c) {
    char buf[8192];
    int n = recv(c, buf, sizeof(buf) - 1, 0);
    if (n > 0) {
        buf[n] = 0;
        std::string req(buf);
        std::string target = "/";
        size_t sp1 = req.find(' ');
        if (sp1 != std::string::npos) {
            size_t sp2 = req.find(' ', sp1 + 1);
            if (sp2 != std::string::npos) target = req.substr(sp1 + 1, sp2 - sp1 - 1);
        }
        // 内容类型: 面板/样式 → html/css, 其余 → json
        std::string path = target.substr(0, target.find('?'));
        bool isHtml = path == "/" || path == "/panel";
        bool isCss = path == "/panel.css";
        const char* ctype = isCss ? "text/css; charset=utf-8" : isHtml ? "text/html; charset=utf-8"
                          : "application/json; charset=utf-8";
        DWORD t0 = GetTickCount();
        std::string body;
        int code = 200;
        handle_req(target, body, code);
        char hdr[300];
        snprintf(hdr, 300, "HTTP/1.1 %d %s\r\nContent-Type: %s\r\n"
                 "Access-Control-Allow-Origin: *\r\nCache-Control: no-store, no-cache\r\n"
                 "Content-Length: %d\r\nConnection: close\r\n\r\n",
                 code, code == 200 ? "OK" : "ERR", ctype, (int)body.size());
        std::string resp = hdr + body;
        int off = 0;
        while (off < (int)resp.size()) {
            int w = send(c, resp.data() + off, (int)resp.size() - off, 0);
            if (w <= 0) break;
            off += w;
        }
        if (GetTickCount() - t0 > 1500)
            logline("slow req " + target.substr(0, 60) + " " + std::to_string(GetTickCount() - t0) + "ms");
    }
    closesocket(c);
    g_conns--;
}

static LONG WINAPI crash_handler(EXCEPTION_POINTERS*) {
    logline("!! 未处理异常(崩溃), 进程将退出, 等待计划任务1分钟内自动拉起 !!");
    return EXCEPTION_EXECUTE_HANDLER;
}

// ---- 单实例硬检查 ----
// 命名互斥体在"服务会话(0) vs 用户会话(1)"之间可能落到不同命名空间而导致双开,
// 双开会让两个进程同时写库、抢同一份 OKX 限频配额 → 必须按可执行文件全路径硬检查。
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
    // ---- 离线自检: apihub.exe --dump → 把面板与AJAX片段渲染到文件(不占端口/不受单实例限制) ----
    if (strstr(GetCommandLineA(), "--dump")) {
        auto wf = [](const char* path, const std::string& s) {
            FILE* f = fopen(path, "wb");
            if (f) { fwrite(s.data(), 1, s.size(), f); fclose(f); }
        };
        wf("E:\\datas\\log\\panel_dump.html", render_panel(Params()));
        Params q; q["name"] = "stats";  wf("E:\\datas\\log\\frag_stats.json", ep_frag(q));
        q["name"] = "chart"; q["inst"] = "ETH-USDT-SWAP"; q["tf"] = "5m"; q["n"] = "200";
        wf("E:\\datas\\log\\frag_chart.json", ep_frag(q));
        q["name"] = "grid";  wf("E:\\datas\\log\\frag_grid.json", ep_frag(q));
        q["name"] = "flow";  wf("E:\\datas\\log\\frag_flow.json", ep_frag(q));
        q["name"] = "guard"; wf("E:\\datas\\log\\frag_guard.json", ep_frag(q));
        return 0;
    }
    SetUnhandledExceptionFilter(crash_handler);
    HANDLE mx = CreateMutexA(nullptr, TRUE, "Global\\finally_apihub");
    if (mx && GetLastError() == ERROR_ALREADY_EXISTS) return 0;
    if (another_instance_running()) return 0;   // 跨会话双开硬拦截   // 单实例
    logline("==== apihub C++ API服务器启动 pid=" + std::to_string(GetCurrentProcessId()) + " ====");
    // 等待数据库就绪
    bool ok = false;
    for (int i = 0; i < 60; i++) { if (db_ex("SELECT 1")) { ok = true; break; } Sleep(5000); }
    if (!ok) { logline("DB 连接失败60次, 退出"); return 1; }
    logline("DB 已连接 trading@127.0.0.1 (libmysql)");
    // 金▲快扫后台缓存
    std::thread(sigscan_loop).detach();

    WSADATA wsa;
    if (WSAStartup(MAKEWORD(2, 2), &wsa) != 0) { logline("WSAStartup FAIL"); return 1; }
    SOCKET ls = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP);
    if (ls == INVALID_SOCKET) { logline("socket FAIL"); return 1; }
    BOOL reuse = TRUE;
    setsockopt(ls, SOL_SOCKET, SO_REUSEADDR, (char*)&reuse, sizeof(reuse));
    sockaddr_in addr{};
    addr.sin_family = AF_INET;
    addr.sin_port = htons(8090);
    addr.sin_addr.s_addr = htonl(INADDR_ANY);        // 监听全部网卡(远程浏览器可访问)
    if (bind(ls, (sockaddr*)&addr, sizeof(addr)) != 0) {
        logline("bind 8090 FAIL: " + std::to_string(WSAGetLastError()));
        return 1;
    }
    listen(ls, 32);
    logline("apihub 监听 0.0.0.0:8090 就绪");
    while (true) {
        SOCKET c = accept(ls, nullptr, nullptr);
        if (c == INVALID_SOCKET) { Sleep(200); continue; }
        if (g_conns.load() > 24) {   // 过载保护
            const char* busy = "HTTP/1.1 503 Busy\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
            send(c, busy, (int)strlen(busy), 0);
            closesocket(c);
            continue;
        }
        g_conns++;
        DWORD tmo = 10000;
        setsockopt(c, SOL_SOCKET, SO_RCVTIMEO, (char*)&tmo, sizeof(tmo));
        setsockopt(c, SOL_SOCKET, SO_SNDTIMEO, (char*)&tmo, sizeof(tmo));
        std::thread(serve_client, c).detach();
    }
    return 0;
}
