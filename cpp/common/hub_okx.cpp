/* ==================================================================
 * hub_okx.cpp — WinHTTP + OKX 签名公共组件 (逐字迁移自 apihub.cpp / tphub.cpp)
 *
 * 文件职责：
 *   封装系统对外 HTTP 通信的全部能力：
 *   ① WinHTTP 层：https 请求、超时控制、重试退避；
 *   ② OKX 鉴权层：HMAC-SHA256 签名 + 请求头拼装，访问 OKX 私有接口
 *     （账户余额、下单、撤单等真钱操作）。
 *
 * 包含函数及作用：
 *   - http_req          通用 GET/POST (3次重试, GET 150/400ms 退避, POST 1500ms)
 *   - http_get_hdr      带自定义头的 GET（OKX 签名请求用）
 *   - http_get          裸 GET（公共行情接口）
 *   - b64enc            手写 base64 编码（签名结果编码）
 *   - hmac_sha256_b64   Windows BCrypt 计算 HMAC-SHA256 并 base64（OKX 鉴权核心）
 *   - okx_ts            生成 OKX 要求的 ISO8601 UTC 时间戳（毫秒精度）
 *   - okx_cred          从数据库 okx_cred 表 id=1 读取 API 凭证
 *   - okx_private_get   OKX 私有 GET（带签名头）
 *   - okx_private       OKX 私有 GET/POST 通用入口
 *
 * 数据流向：
 *   调用方(api_eps/tradehub) → okx_private 系列 / http_* → WinHTTP → www.okx.com
 *   签名材料 = 时间戳 + 方法 + 路径(私有GET) 或 +请求体(POST)，密钥为 SK。
 *   凭证(ak/sk/pp)来自 MySQL okx_cred 表 id=1（经 hub_db 的 db_q）。
 *
 *   注意：/sigs 接口的 t 参数是毫秒；/kline 接口的 t 参数是秒（OKX 官方约定）。
 *
 * 被谁调用：api_eps.cpp(行情/账户接口)、tradehub 引擎(下单/对账)。
 * ================================================================== */
#include "hub.h"                              // 公共声明头(db_q/logline 等)
#include <winhttp.h>                          // Windows HTTP 客户端 API
#include <bcrypt.h>                           // Windows 加密原语 API(HMAC-SHA256)

// ---------------- WinHTTP 通用请求 ----------------
bool http_req(const char* method, const std::string& host, const std::string& path,
              const std::string& extraHeaders, const std::string& sendBody, std::string& out) {
    bool isGet = strcmp(method, "GET") == 0 || strcmp(method, "get") == 0;  // 判断是否 GET: 决定重试退避节奏
    std::wstring whost(host.begin(), host.end());   // 窄字符转宽字符(WinHTTP 只接受 UTF-16; 域名/路径均为 ASCII, 安全)
    std::wstring wpath(path.begin(), path.end());   // 请求路径同样转宽字符
    std::wstring wmethod(method, method + strlen(method)); // HTTP 方法转宽字符
    for (int a = 0; a < 3; a++) {             // 最多重试 3 次(网络抖动/瞬时失败容忍)
        HINTERNET ses = WinHttpOpen(L"Mozilla/5.0 hub/1.0", WINHTTP_ACCESS_TYPE_DEFAULT_PROXY,
                                    WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, 0); // 创建 HTTP 会话(UA 伪装成浏览器, 降低被 WAF 拦截概率)
        if (!ses) { Sleep(1500); continue; }  // 会话创建失败(如系统资源紧张): 等 1.5s 再试
        HINTERNET con = WinHttpConnect(ses, whost.c_str(), INTERNET_DEFAULT_HTTPS_PORT, 0); // 建立 TLS 连接(默认 443 端口)
        bool got = false;                     // 本轮是否成功取到 200 响应
        if (con) {
            HINTERNET req = WinHttpOpenRequest(con, wmethod.c_str(), wpath.c_str(), nullptr,
                                               WINHTTP_NO_REFERER, WINHTTP_DEFAULT_ACCEPT_TYPES,
                                               WINHTTP_FLAG_SECURE); // 构造请求对象(SECURE = https)
            if (req) {
                DWORD tmo = 12000;            // 接收超时 12s: 行情接口不宜久等, 拖慢交易决策
                WinHttpSetOption(req, WINHTTP_OPTION_RECEIVE_TIMEOUT, &tmo, sizeof(tmo));
                std::wstring wh(extraHeaders.begin(), extraHeaders.end()); // 附加头转宽字符(签名头/Content-Type)
                if (WinHttpSendRequest(req, extraHeaders.empty() ? WINHTTP_NO_ADDITIONAL_HEADERS : wh.c_str(),
                                       (DWORD)wh.size(),
                                       sendBody.empty() ? WINHTTP_NO_REQUEST_DATA : (LPVOID)sendBody.data(),
                                       (DWORD)sendBody.size(), (DWORD)sendBody.size(), 0) &&
                    WinHttpReceiveResponse(req, nullptr)) {  // 发送请求(带头/体)并等待响应到达
                    DWORD st = 0, sz = sizeof(st);
                    WinHttpQueryHeaders(req, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,
                                        WINHTTP_HEADER_NAME_BY_INDEX, &st, &sz, WINHTTP_NO_HEADER_INDEX); // 读取 HTTP 状态码
                    if (st == 200) {          // 只认 200: OKX 错误也常带 4xx/5xx, 非成功不重试也直接判失败
                        out.clear();          // 清空输出缓冲, 避免残留旧数据
                        char buf[16384]; DWORD rd = 0;   // 16KB 分块读取缓冲
                        while (WinHttpReadData(req, buf, sizeof(buf), &rd) && rd) { out.append(buf, rd); rd = 0; } // 循环读满整个响应体
                        got = true;           // 标记成功, 跳出重试循环
                    }
                }
                WinHttpCloseHandle(req);      // 释放请求句柄(每轮必须, 防句柄泄漏)
            }
            WinHttpCloseHandle(con);          // 释放连接句柄
        }
        WinHttpCloseHandle(ses);              // 释放会话句柄
        if (got) return true;                 // 成功: 返回
        // GET 轻量退避(150/400ms, 原固定1500×3会把并发拖到3s+); POST 沿用 1500ms
        Sleep(isGet ? (a == 0 ? 150 : 400) : 1500);  // GET 用于行情轮询, 退避要短; POST 涉及交易, 保守等 1.5s
    }
    return false;                             // 3 次都失败: 放弃, 由调用方决定降级策略(缓存/报错)
}

bool http_get_hdr(const std::string& host, const std::string& path,
                  const std::string& extraHeaders, std::string& out) {
    return http_req("GET", host, path, extraHeaders, "", out);  // 带自定义头的 GET: 只是 http_req 的便捷封装
}

bool http_get(const std::string& host, const std::string& path, std::string& out) {
    return http_req("GET", host, path, "", "", out);  // 无附加头的裸 GET: OKX 公共行情(K线/ticker)专用
}

// ---------------- OKX 签名 (BCrypt HMAC-SHA256 + base64) ----------------
std::string b64enc(const unsigned char* d, int n) {
    static const char* T = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/"; // 标准 base64 字母表
    std::string o;                            // 输出串
    for (int i = 0; i < n; i += 3) {          // 每 3 字节一组 → 4 个 base64 字符
        int b0 = d[i], b1 = i + 1 < n ? d[i + 1] : 0, b2 = i + 2 < n ? d[i + 2] : 0; // 不足 3 字节补 0(末尾用 = 占位)
        o += T[b0 >> 2];                      // 第 1 字符: 取 b0 高 6 位
        o += T[((b0 & 3) << 4) | (b1 >> 4)];  // 第 2 字符: b0 低 2 位 + b1 高 4 位
        o += (i + 1 < n) ? T[((b1 & 15) << 2) | (b2 >> 6)] : '='; // 第 3 字符: 无第 2 字节时补 =
        o += (i + 2 < n) ? T[b2 & 63] : '=';  // 第 4 字符: 无第 3 字节时补 =
    }
    return o;
}

std::string hmac_sha256_b64(const std::string& key, const std::string& msg) {
    BCRYPT_ALG_HANDLE alg = nullptr;          // SHA256 算法提供者句柄
    BCRYPT_HASH_HANDLE hh = nullptr;          // 哈希对象句柄
    unsigned char mac[32];                    // SHA256 输出固定 32 字节
    std::string out;                          // 输出: base64 后的签名字符串
    do {                                      // do-while(false) 模拟 goto cleanup: 任一步失败即跳出统一清理
        if (BCryptOpenAlgorithmProvider(&alg, BCRYPT_SHA256_ALGORITHM, nullptr, BCRYPT_ALG_HANDLE_HMAC_FLAG) != 0) break; // 以 HMAC 模式打开 SHA256 提供者
        if (BCryptCreateHash(alg, &hh, nullptr, 0, (PUCHAR)key.data(), (ULONG)key.size(), 0) != 0) break; // 创建哈希对象并注入密钥(即 HMAC 的 key)
        if (BCryptHashData(hh, (PUCHAR)msg.data(), (ULONG)msg.size(), 0) != 0) break; // 喂入待签名消息(时间戳+方法+路径+体)
        if (BCryptFinishHash(hh, mac, 32, 0) != 0) break;  // 完成计算, 取出 32 字节摘要
        out = b64enc(mac, 32);                // OKX 要求签名是 base64 形式
    } while (false);
    if (hh) BCryptDestroyHash(hh);            // 统一释放哈希对象
    if (alg) BCryptCloseAlgorithmProvider(alg, 0); // 统一释放算法提供者
    return out;
}

std::string okx_ts() {
    SYSTEMTIME st;                            // Windows 系统时间结构
    GetSystemTime(&st);                       // 取 UTC 时间(OKX 要求时间戳必须是 UTC, 不能用本地时间)
    char b[40];                               // 格式化缓冲
    snprintf(b, 40, "%04d-%02d-%02dT%02d:%02d:%02d.%03dZ", st.wYear, st.wMonth, st.wDay,
             st.wHour, st.wMinute, st.wSecond, st.wMilliseconds); // ISO8601 格式, 毫秒精度, 末尾 Z 表示 UTC
    return b;
}

bool okx_cred(std::string& ak, std::string& sk, std::string& pp) {
    RowSet cr = db_q("SELECT api_key, secret_key, passphrase FROM okx_cred WHERE id=1"); // 凭证固定存 okx_cred 表 id=1(单账户系统)
    if (!cr.ok || cr.rows.empty()) return false;  // 查询失败或表空: 无凭证, 无法调用私有接口
    ak = cr.rows[0][0]; sk = cr.rows[0][1]; pp = cr.rows[0][2];  // 依次取出 API Key / Secret / 口令
    return true;
}

std::string okx_private_get(const std::string& pathWithQuery, std::string& body) {
    std::string ak, sk, pp;                   // 三个凭证: API Key / Secret Key / Passphrase
    if (!okx_cred(ak, sk, pp)) return "no cred";  // 凭证缺失: 返回错误串(空串=成功约定)
    std::string ts = okx_ts();                // 签名时间戳: 与请求头中的 TIMESTAMP 必须完全一致
    std::string sign = hmac_sha256_b64(sk, ts + "GET" + pathWithQuery); // OKX GET 签名材料 = 时间戳+方法+路径(含query), 不含请求体
    std::string hdr = "OK-ACCESS-KEY: " + ak + "\r\nOK-ACCESS-SIGN: " + sign +
                      "\r\nOK-ACCESS-TIMESTAMP: " + ts + "\r\nOK-ACCESS-PASSPHRASE: " + pp +
                      "\r\nContent-Type: application/json\r\n";  // OKX 鉴权四件套头 + JSON 内容类型
    if (!http_get_hdr("www.okx.com", pathWithQuery, hdr, body)) return "http fail"; // 发请求, 网络失败返回错误串
    return "";                                // 空串 = 成功, body 为响应原文
}

bool okx_private(const char* method, const std::string& path,
                 const std::string& body, std::string& out) {
    std::string ak, sk, pp;                   // 凭证容器
    if (!okx_cred(ak, sk, pp)) { logline("no cred"); return false; } // 无凭证: 记日志(下单失败必须留痕)
    std::string ts = okx_ts();                // 时间戳: 每次请求独立生成(过期签名会被 OKX 拒绝)
    std::string sign = hmac_sha256_b64(sk, ts + method + path + body); // POST 签名材料 = 时间戳+方法+路径+请求体(与 GET 的区别是多 body)
    std::string hdr = "OK-ACCESS-KEY: " + ak + "\r\nOK-ACCESS-SIGN: " + sign +
                      "\r\nOK-ACCESS-TIMESTAMP: " + ts + "\r\nOK-ACCESS-PASSPHRASE: " + pp +
                      "\r\nContent-Type: application/json\r\n";  // 鉴权头拼装(与 okx_private_get 同构)
    return http_req(method, "www.okx.com", path, hdr, body, out);  // 发送(POST 用 http_req 才能带请求体与长退避)
}
