// ==================================================================
// hub_okx.cpp — WinHTTP + OKX 签名公共组件 (逐字迁移自 apihub.cpp / tphub.cpp)
//   http_req        通用 GET/POST (3次重试, GET 150/400ms 退避, POST 1500ms)
//   okx_private     OKX 私有接口(带签名, GET/POST), 凭证从 okx_cred 表读
// ==================================================================
#include "hub.h"
#include <winhttp.h>
#include <bcrypt.h>

// ---------------- WinHTTP 通用请求 ----------------
bool http_req(const char* method, const std::string& host, const std::string& path,
              const std::string& extraHeaders, const std::string& sendBody, std::string& out) {
    bool isGet = strcmp(method, "GET") == 0 || strcmp(method, "get") == 0;
    std::wstring whost(host.begin(), host.end());
    std::wstring wpath(path.begin(), path.end());
    std::wstring wmethod(method, method + strlen(method));
    for (int a = 0; a < 3; a++) {
        HINTERNET ses = WinHttpOpen(L"Mozilla/5.0 hub/1.0", WINHTTP_ACCESS_TYPE_DEFAULT_PROXY,
                                    WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, 0);
        if (!ses) { Sleep(1500); continue; }
        HINTERNET con = WinHttpConnect(ses, whost.c_str(), INTERNET_DEFAULT_HTTPS_PORT, 0);
        bool got = false;
        if (con) {
            HINTERNET req = WinHttpOpenRequest(con, wmethod.c_str(), wpath.c_str(), nullptr,
                                               WINHTTP_NO_REFERER, WINHTTP_DEFAULT_ACCEPT_TYPES,
                                               WINHTTP_FLAG_SECURE);
            if (req) {
                DWORD tmo = 12000;
                WinHttpSetOption(req, WINHTTP_OPTION_RECEIVE_TIMEOUT, &tmo, sizeof(tmo));
                std::wstring wh(extraHeaders.begin(), extraHeaders.end());
                if (WinHttpSendRequest(req, extraHeaders.empty() ? WINHTTP_NO_ADDITIONAL_HEADERS : wh.c_str(),
                                       (DWORD)wh.size(),
                                       sendBody.empty() ? WINHTTP_NO_REQUEST_DATA : (LPVOID)sendBody.data(),
                                       (DWORD)sendBody.size(), (DWORD)sendBody.size(), 0) &&
                    WinHttpReceiveResponse(req, nullptr)) {
                    DWORD st = 0, sz = sizeof(st);
                    WinHttpQueryHeaders(req, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,
                                        WINHTTP_HEADER_NAME_BY_INDEX, &st, &sz, WINHTTP_NO_HEADER_INDEX);
                    if (st == 200) {
                        out.clear();
                        char buf[16384]; DWORD rd = 0;
                        while (WinHttpReadData(req, buf, sizeof(buf), &rd) && rd) { out.append(buf, rd); rd = 0; }
                        got = true;
                    }
                }
                WinHttpCloseHandle(req);
            }
            WinHttpCloseHandle(con);
        }
        WinHttpCloseHandle(ses);
        if (got) return true;
        // GET 轻量退避(150/400ms, 原固定1500×3会把并发拖到3s+); POST 沿用 1500ms
        Sleep(isGet ? (a == 0 ? 150 : 400) : 1500);
    }
    return false;
}

bool http_get_hdr(const std::string& host, const std::string& path,
                  const std::string& extraHeaders, std::string& out) {
    return http_req("GET", host, path, extraHeaders, "", out);
}

bool http_get(const std::string& host, const std::string& path, std::string& out) {
    return http_req("GET", host, path, "", "", out);
}

// ---------------- OKX 签名 (BCrypt HMAC-SHA256 + base64) ----------------
std::string b64enc(const unsigned char* d, int n) {
    static const char* T = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    std::string o;
    for (int i = 0; i < n; i += 3) {
        int b0 = d[i], b1 = i + 1 < n ? d[i + 1] : 0, b2 = i + 2 < n ? d[i + 2] : 0;
        o += T[b0 >> 2];
        o += T[((b0 & 3) << 4) | (b1 >> 4)];
        o += (i + 1 < n) ? T[((b1 & 15) << 2) | (b2 >> 6)] : '=';
        o += (i + 2 < n) ? T[b2 & 63] : '=';
    }
    return o;
}

std::string hmac_sha256_b64(const std::string& key, const std::string& msg) {
    BCRYPT_ALG_HANDLE alg = nullptr;
    BCRYPT_HASH_HANDLE hh = nullptr;
    unsigned char mac[32];
    std::string out;
    do {
        if (BCryptOpenAlgorithmProvider(&alg, BCRYPT_SHA256_ALGORITHM, nullptr, BCRYPT_ALG_HANDLE_HMAC_FLAG) != 0) break;
        if (BCryptCreateHash(alg, &hh, nullptr, 0, (PUCHAR)key.data(), (ULONG)key.size(), 0) != 0) break;
        if (BCryptHashData(hh, (PUCHAR)msg.data(), (ULONG)msg.size(), 0) != 0) break;
        if (BCryptFinishHash(hh, mac, 32, 0) != 0) break;
        out = b64enc(mac, 32);
    } while (false);
    if (hh) BCryptDestroyHash(hh);
    if (alg) BCryptCloseAlgorithmProvider(alg, 0);
    return out;
}

std::string okx_ts() {
    SYSTEMTIME st;
    GetSystemTime(&st);
    char b[40];
    snprintf(b, 40, "%04d-%02d-%02dT%02d:%02d:%02d.%03dZ", st.wYear, st.wMonth, st.wDay,
             st.wHour, st.wMinute, st.wSecond, st.wMilliseconds);
    return b;
}

bool okx_cred(std::string& ak, std::string& sk, std::string& pp) {
    RowSet cr = db_q("SELECT api_key, secret_key, passphrase FROM okx_cred WHERE id=1");
    if (!cr.ok || cr.rows.empty()) return false;
    ak = cr.rows[0][0]; sk = cr.rows[0][1]; pp = cr.rows[0][2];
    return true;
}

std::string okx_private_get(const std::string& pathWithQuery, std::string& body) {
    std::string ak, sk, pp;
    if (!okx_cred(ak, sk, pp)) return "no cred";
    std::string ts = okx_ts();
    std::string sign = hmac_sha256_b64(sk, ts + "GET" + pathWithQuery);
    std::string hdr = "OK-ACCESS-KEY: " + ak + "\r\nOK-ACCESS-SIGN: " + sign +
                      "\r\nOK-ACCESS-TIMESTAMP: " + ts + "\r\nOK-ACCESS-PASSPHRASE: " + pp +
                      "\r\nContent-Type: application/json\r\n";
    if (!http_get_hdr("www.okx.com", pathWithQuery, hdr, body)) return "http fail";
    return "";
}

bool okx_private(const char* method, const std::string& path,
                 const std::string& body, std::string& out) {
    std::string ak, sk, pp;
    if (!okx_cred(ak, sk, pp)) { logline("no cred"); return false; }
    std::string ts = okx_ts();
    std::string sign = hmac_sha256_b64(sk, ts + method + path + body);
    std::string hdr = "OK-ACCESS-KEY: " + ak + "\r\nOK-ACCESS-SIGN: " + sign +
                      "\r\nOK-ACCESS-TIMESTAMP: " + ts + "\r\nOK-ACCESS-PASSPHRASE: " + pp +
                      "\r\nContent-Type: application/json\r\n";
    return http_req(method, "www.okx.com", path, hdr, body, out);
}
