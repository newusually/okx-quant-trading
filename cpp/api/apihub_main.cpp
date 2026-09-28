/*
 * ==================================================================
 * apihub_main.cpp — apihub.exe 服务器主入口 (HTTP 薄壳)
 * ==================================================================
 * 文件职责:
 *   apihub.exe 是纯 C++ HTTP API 服务器, 静态编译约 600KB, 替代原 PHP api.php
 *   (性能对比: kline 2.9x / livestats 220x)。本文件只做"薄壳"工作:
 *   监听 0.0.0.0:8090, 每连接一线程(过载上限 24), 解析 HTTP 请求行与查询串,
 *   把路由分发到 api_eps.cpp 实现的 ep_* 接口函数, 统一加
 *   CORS:Access-Control-Allow-Origin:* 与 Cache-Control:no-store 响应头。
 *   另含: URL解码、零JS面板渲染缓存、崩溃兜底、跨会话单实例硬检查、
 *   --dump 离线自检模式。
 *
 * 接口清单 (handle_req 路由 → ep_* 函数):
 *   / 与 /panel → 零JS HTML 面板(3秒缓存);  /panel.css → 面板样式
 *   /kline → ep_kline    K线+MACD(before向更老翻页 / after / fresh=N 拉新, 2秒节流)
 *   /frag  → ep_frag     面板 AJAX 片段(C++ 渲染)
 *   /live → ep_live      实时价+1m K线(直连OKX, 失败回退DB)
 *   /ticker → ep_ticker  最新价(1秒缓存)
 *   /trades → ep_trades  成交流水(合并+OKX补盈利)
 *   /stats → ep_stats    统计汇总
 *   /livestats → ep_livestats  实时统计(整响应1秒TTL缓存)
 *   /gridmon → ep_gridmon      网格监控
 *   /backcheck → ep_backcheck  体检报告
 *   /marks → ep_marks   交易标记点(注意 /marks与/sigs 的 t 是毫秒, /kline 的 t 是秒)
 *   /sigs → ep_sigs     金▲转折点+力学(t 为毫秒!)
 *   /sigscan → ep_sigscan     全池金信号扫描(5m 走 30s 后台缓存)
 *   /boot → ep_boot     页面引导(默认+最近交易合约)
 *   /symbols → ep_symbols     合约池列表
 *   /settings → ep_settings   设置键值(GET读/POST写, skey 白名单防注入)
 *   /account → ep_account     账户概况(OKX HMAC 私有签名)
 *   /health → ep_health 健康自检
 *   /guard → ep_guard   守护中枢状态
 *   其余 → 404 unknown action
 * ==================================================================
 */
// 公共头: Params、P、各 ep_* 函数、render_panel、db_ex、logline、jesc 等公共设施
#include "../common/hub.h"
// 进程快照: CreateToolhelp32Snapshot 等单实例检查用
#include <tlhelp32.h>
// 标准输入输出: snprintf/fopen
#include <cstdio>
// 字符串: strstr/strtol/strlen
#include <cstring>
// 字符: isxdigit/tolower
#include <cctype>
// 线程: 每连接一线程 + sigscan 后台线程
#include <thread>
// 原子量: g_conns 在线连接计数
#include <atomic>
// 互斥锁: panelMx 保护面板缓存
#include <mutex>

// URL 解码: '+' 转空格, %XX 十六进制转字节(用于查询参数还原)
static std::string urldec(const std::string& s) {   // 入参 s: 已切出的原始编码串
    std::string o; o.reserve(s.size());    // 输出串, 预留容量避免反复扩容
    for (size_t i = 0; i < s.size(); i++) {   // 逐字符扫描
        if (s[i] == '+') { o += ' '; continue; }   // '+' 还原为空格(表单编码约定)
        if (s[i] == '%' && i + 2 < s.size() && isxdigit((unsigned char)s[i + 1]) && isxdigit((unsigned char)s[i + 2])) {   // %XX 且后两位都是十六进制
            char hb[3] = { s[i + 1], s[i + 2], 0 };   // 取两位十六进制字符
            o += (char)strtol(hb, nullptr, 16);   // 转成对应字节
            i += 2;                        // 跳过已消费的两个字符
            continue;                      // 继续下一个
        }
        o += s[i];                         // 普通字符原样拷贝
    }
    return o;                              // 返回解码结果
}
static std::atomic<int> g_conns(0);        // 当前在线连接数(原子), 用于过载保护

// 请求路由分发: 解析 target 里的 action 与查询串, 分发到 ep_* / 面板渲染
static void handle_req(const std::string& target, std::string& outBody, int& outCode) {   // target=/action?query
    // target: /action?query
    std::string action = target, qs;       // action=路由名, qs=查询串
    size_t qp = target.find('?');          // 定位 '?' 分隔符
    if (qp != std::string::npos) { action = target.substr(0, qp); qs = target.substr(qp + 1); }   // 切出路径与查询串
    if (!action.empty() && action[0] == '/') action = action.substr(1);   // 去掉开头的 '/'
    Params q;                              // 解析后的查询参数表
    {                                      // 局部作用域: 解析 a=b&c=d
        size_t i = 0;                      // 扫描位置
        while (i < qs.size()) {            // 逐段处理
            size_t a = qs.find('&', i);    // 找下一个 '&' 分隔
            if (a == std::string::npos) a = qs.size();   // 最后一段
            std::string kv = qs.substr(i, a - i);   // 切出 k=v 片段
            size_t eq = kv.find('=');      // 找 '=' 分隔
            if (eq != std::string::npos)   // 合法 k=v
                q[urldec(kv.substr(0, eq))] = urldec(kv.substr(eq + 1));   // 键值分别 URL 解码后入表
            i = a + 1;                     // 移到下一段
        }
    }
    outCode = 200;                         // 默认状态码 200
    // 零JS面板: / 与 /panel 输出 HTML, /panel.css 输出样式
    static std::string cachedPanel, cachedPanelKey;   // 面板 HTML 缓存与其对应键(路由+查询串)
    static std::mutex panelMx;             // 保护面板缓存的锁
    if (action.empty() || action == "panel" || action == "panel.css") {   // 面板路由
        if (action == "panel.css") {       // 样式请求
            outBody = PANEL_CSS;           // 直接返回内置 CSS 常量
            outCode = 200;                 // 200
            return;                        // 处理完毕
        }
        std::string key = action + "|" + qs;   // 缓存键: 路由+完整查询串
        static volatile long long lastTick = 0;   // 上次渲染时间(毫秒)
        std::lock_guard<std::mutex> lk(panelMx);   // 加锁(缓存为静态共享)
        long long nowMs = GetTickCount64();   // 当前系统毫秒
        bool fresh = cachedPanelKey == key && !cachedPanel.empty() && (nowMs - lastTick) < 3000;   // 同键且 3 秒内 → 视为新鲜
        if (!fresh) {                      // 缓存失效
            cachedPanel = render_panel(q); // 重新渲染整个面板
            cachedPanelKey = key;          // 记录键
            lastTick = nowMs;              // 记录时间
        }
        outBody = cachedPanel;             // 返回(可能缓存的)面板 HTML
        outCode = 200;                     // 200
        return;                            // 处理完毕
    }
    if (action == "kline")            outBody = ep_kline(q);   // K线+MACD
    else if (action == "frag")        outBody = ep_frag(q);   // 面板 AJAX 片段(C++ 渲染)
    else if (action == "live")        outBody = ep_live(q);   // 实时价+1mK线
    else if (action == "ticker")      outBody = ep_ticker(q);   // 最新价
    else if (action == "trades")      outBody = ep_trades(q);   // 成交流水
    else if (action == "stats")       outBody = ep_stats(q);   // 统计汇总
    else if (action == "livestats")   outBody = ep_livestats(q);   // 实时统计(1秒缓存)
    else if (action == "gridmon")     outBody = ep_gridmon(q);   // 网格监控
    else if (action == "backcheck")   outBody = ep_backcheck(q);   // 体检报告
    else if (action == "marks")       outBody = ep_marks(q);   // 交易标记
    else if (action == "boot")        outBody = ep_boot(q);   // 页面引导
    else if (action == "symbols")     outBody = ep_symbols(q);   // 合约池
    else if (action == "settings")    outBody = ep_settings(q);   // 设置键值
    else if (action == "account")     outBody = ep_account(q);   // 账户(OKX签名)
    else if (action == "health")      outBody = ep_health(q);   // 健康自检
    else if (action == "guard")       outBody = ep_guard(q);   // 守护状态
    else if (action == "btlist")      outBody = ep_btlist(q);  // AI模拟回测报告列表(最近n场)
    else if (action == "btreport")    outBody = ep_btreport(q);   // 单场完整报告(?id=)
    else { outBody = "{\"ok\":false,\"error\":\"unknown action: " + jesc(action) + "\"}"; outCode = 404; }   // 未知路由 → 404
}

// 单连接服务: 收请求 → 路由 → 拼 HTTP 响应 → 回写 → 关闭
static void serve_client(SOCKET c) {       // c: accept 得到的客户端套接字
    char buf[8192];                        // 请求读取缓冲(8KB)
    int n = recv(c, buf, sizeof(buf) - 1, 0);   // 一次性收请求头(GET场景足够)
    if (n > 0) {                           // 收到数据
        buf[n] = 0;                        // 补字符串结束符
        std::string req(buf);              // 转字符串
        std::string target = "/";          // 请求目标(默认 /)
        size_t sp1 = req.find(' ');        // 方法后的第一个空格
        if (sp1 != std::string::npos) {    // 找到
            size_t sp2 = req.find(' ', sp1 + 1);   // 目标后的第二个空格
            if (sp2 != std::string::npos) target = req.substr(sp1 + 1, sp2 - sp1 - 1);   // 切出 "GET xxx HTTP/1.1" 中的 xxx
        }
        // 内容类型: 面板/样式 → html/css, 其余 → json
        std::string path = target.substr(0, target.find('?'));   // 去查询串取纯路径
        bool isHtml = path == "/" || path == "/panel";           // 面板 → HTML
        bool isCss = path == "/panel.css";                        // 样式 → CSS
        const char* ctype = isCss ? "text/css; charset=utf-8" : isHtml ? "text/html; charset=utf-8"   // 按路由选 Content-Type
                          : "application/json; charset=utf-8";   // 其余一律 JSON
        DWORD t0 = GetTickCount();         // 计时起点(慢请求日志用)
        std::string body;                  // 响应体
        int code = 200;                    // 状态码
        handle_req(target, body, code);    // 路由分发, 生成响应
        char hdr[300];                     // 响应头缓冲
        snprintf(hdr, 300, "HTTP/1.1 %d %s\r\nContent-Type: %s\r\n"   // 状态行+类型
                 "Access-Control-Allow-Origin: *\r\nCache-Control: no-store, no-cache\r\n"   // CORS任意来源+禁缓存
                 "Content-Length: %d\r\nConnection: close\r\n\r\n",   // 长度+短连接(每次关闭)
                 code, code == 200 ? "OK" : "ERR", ctype, (int)body.size());   // 状态文本与长度
        std::string resp = hdr + body;     // 头+体合并
        int off = 0;                       // 已发送偏移
        while (off < (int)resp.size()) {   // 循环发送直到完毕
            int w = send(c, resp.data() + off, (int)resp.size() - off, 0);   // 发送剩余部分
            if (w <= 0) break;             // 发送失败(对端断开)则中止
            off += w;                      // 累加进度
        }
        if (GetTickCount() - t0 > 1500)    // 耗时超 1.5 秒
            logline("slow req " + target.substr(0, 60) + " " + std::to_string(GetTickCount() - t0) + "ms");   // 记慢请求日志
    }
    closesocket(c);                        // 关闭连接(Connection: close)
    g_conns--;                             // 在线计数减一
}

// 崩溃兜底: 未处理异常时记日志, 让计划任务拉起
static LONG WINAPI crash_handler(EXCEPTION_POINTERS*) {   // Win32 未处理异常过滤器
    logline("!! 未处理异常(崩溃), 进程将退出, 等待计划任务1分钟内自动拉起 !!");   // 留死亡日志
    return EXCEPTION_EXECUTE_HANDLER;      // 结束进程
}

// ---- 单实例硬检查 ----
// 命名互斥体在"服务会话(0) vs 用户会话(1)"之间可能落到不同命名空间而导致双开,
// 双开会让两个进程同时写库、抢同一份 OKX 限频配额 → 必须按可执行文件全路径硬检查。
// 遍历系统进程, 比较可执行文件全路径是否与本进程相同(跨会话双开拦截)
static bool another_instance_running() {   // 返回 true 表示已有同类进程在跑
    char self[MAX_PATH * 2] = { 0 };       // 本进程 exe 路径缓冲
    GetModuleFileNameA(NULL, self, sizeof(self) - 1);   // 取本进程 exe 全路径
    std::string me = self;                 // 转字符串
    for (auto& c : me) c = (char)tolower((unsigned char)c);   // 统一小写便于比较
    HANDLE snap = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);   // 创建系统进程快照
    if (snap == INVALID_HANDLE_VALUE) return false;   // 快照失败 → 不拦截(宁可放过)
    PROCESSENTRY32W pe; pe.dwSize = sizeof(pe);   // 进程条目结构
    DWORD selfPid = GetCurrentProcessId(); // 本进程 PID(要跳过)
    bool dup = false;                      // 是否发现重复
    if (Process32FirstW(snap, &pe)) {      // 取第一个进程
        do {
            if (pe.th32ProcessID == selfPid) continue;   // 跳过自己
            HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pe.th32ProcessID);   // 打开该进程(只查询权限)
            if (!hp) continue;             // 打不开(权限)就跳过
            char buf[MAX_PATH * 2] = { 0 }; DWORD len = sizeof(buf) - 1;   // exe 路径缓冲
            if (QueryFullProcessImageNameA(hp, 0, buf, &len)) {   // 取对方 exe 全路径
                std::string other = buf;   // 转字符串
                for (auto& c : other) c = (char)tolower((unsigned char)c);   // 统一小写
                if (other == me) dup = true;   // 全路径一致 → 双开
            }
            CloseHandle(hp);               // 关闭进程句柄
            if (dup) break;                // 已发现, 提前结束
        } while (Process32NextW(snap, &pe));   // 继续下一个进程
    }
    CloseHandle(snap);                     // 关闭快照
    return dup;                            // 返回检查结果
}

// 主入口: 单实例 → 等DB → 启扫描线程 → 监听8090 → 每连接一线程
int WINAPI WinMain(HINSTANCE, HINSTANCE, LPSTR, int) {   // GUI 入口(无控制台窗口)
    // ---- 离线自检: apihub.exe --dump → 把面板与AJAX片段渲染到文件(不占端口/不受单实例限制) ----
    if (strstr(GetCommandLineA(), "--dump")) {   // 命令行带 --dump → 离线渲染模式
        auto wf = [](const char* path, const std::string& s) {   // 小工具: 写文件
            FILE* f = fopen(path, "wb");   // 二进制新建
            if (f) { fwrite(s.data(), 1, s.size(), f); fclose(f); }   // 写入并关闭
        };
        wf("E:\\datas\\log\\panel_dump.html", render_panel(Params()));   // 落盘整面板 HTML
        Params q; q["name"] = "stats";  wf("E:\\datas\\log\\frag_stats.json", ep_frag(q));   // stats 片段
        q["name"] = "chart"; q["inst"] = "ETH-USDT-SWAP"; q["tf"] = "5m"; q["n"] = "200";   // chart 片段参数
        wf("E:\\datas\\log\\frag_chart.json", ep_frag(q));   // chart 片段
        q["name"] = "grid";  wf("E:\\datas\\log\\frag_grid.json", ep_frag(q));   // grid 片段
        q["name"] = "flow";  wf("E:\\datas\\log\\frag_flow.json", ep_frag(q));   // flow 片段
        q["name"] = "guard"; wf("E:\\datas\\log\\frag_guard.json", ep_frag(q));   // guard 片段
        return 0;                          // 自检完成即退出
    }
    SetUnhandledExceptionFilter(crash_handler);   // 注册崩溃兜底
    HANDLE mx = CreateMutexA(nullptr, TRUE, "Global\\finally_apihub");   // 全局命名互斥体(第一道单实例防线)
    if (mx && GetLastError() == ERROR_ALREADY_EXISTS) return 0;   // 已有实例 → 静默退出
    if (another_instance_running()) return 0;   // 跨会话双开硬拦截   // 单实例
    logline("==== apihub C++ API服务器启动 pid=" + std::to_string(GetCurrentProcessId()) + " ====");   // 启动日志
    // 等待数据库就绪
    bool ok = false;                       // DB 就绪标志
    for (int i = 0; i < 60; i++) { if (db_ex("SELECT 1")) { ok = true; break; } Sleep(5000); }   // 最多重试 60 次×5 秒(共 5 分钟)
    if (!ok) { logline("DB 连接失败60次, 退出"); return 1; }   // 5 分钟仍失败则退出
    logline("DB 已连接 trading@127.0.0.1 (libmysql)");   // 连接成功日志
    // 金▲快扫后台缓存
    WSADATA wsa;                           // Winsock 初始化结构
    if (WSAStartup(MAKEWORD(2, 2), &wsa) != 0) { logline("WSAStartup FAIL"); return 1; }   // 初始化 Winsock 2.2 失败则退出
    SOCKET ls = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP);   // 创建 TCP 监听套接字
    if (ls == INVALID_SOCKET) { logline("socket FAIL"); return 1; }   // 创建失败退出
    BOOL reuse = TRUE;                     // 地址复用选项
    setsockopt(ls, SOL_SOCKET, SO_REUSEADDR, (char*)&reuse, sizeof(reuse));   // 允许快速重启时重绑端口
    sockaddr_in addr{};                    // 监听地址结构
    addr.sin_family = AF_INET;             // IPv4
    addr.sin_port = htons(8090);           // 端口 8090(网络字节序)
    addr.sin_addr.s_addr = htonl(INADDR_ANY);        // 监听全部网卡(远程浏览器可访问)
    if (bind(ls, (sockaddr*)&addr, sizeof(addr)) != 0) {   // 绑定失败
        logline("bind 8090 FAIL: " + std::to_string(WSAGetLastError()));   // 记录错误码
        return 1;                          // 退出
    }
    listen(ls, 32);                        // 开始监听, 等待队列 32
    logline("apihub 监听 0.0.0.0:8090 就绪");   // 就绪日志
    while (true) {                         // 主循环: 永久接受连接
        SOCKET c = accept(ls, nullptr, nullptr);   // 阻塞等新连接
        if (c == INVALID_SOCKET) { Sleep(200); continue; }   // accept 异常稍候重试
        if (g_conns.load() > 24) {   // 过载保护
            const char* busy = "HTTP/1.1 503 Busy\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";   // 503 短响应
            send(c, busy, (int)strlen(busy), 0);   // 回 503
            closesocket(c);                // 关闭
            continue;                      // 继续下一个
        }
        g_conns++;                         // 在线计数加一
        DWORD tmo = 10000;                 // 收发超时 10 秒
        setsockopt(c, SOL_SOCKET, SO_RCVTIMEO, (char*)&tmo, sizeof(tmo));   // 接收超时(防慢客户端占线程)
        setsockopt(c, SOL_SOCKET, SO_SNDTIMEO, (char*)&tmo, sizeof(tmo));   // 发送超时
        std::thread(serve_client, c).detach();   // 每连接一线程(分离, 自行回收)
    }
    return 0;                              // 不可达(主循环永不退出)
}
