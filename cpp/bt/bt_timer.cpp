/* ==================================================================
 * bt_timer.cpp — AI 模拟交易员回测定时引擎 (每小时一场, 全 C++)
 *
 * 职责:
 *   1) 回填全市场合约 3m/5m/15m K线缺口 (OKX history-candles, 限额分批)
 *   2) 调用本地 llama-cli(qwen2.5-0.5b) 生成 100 个随机交易人格(名字+性格)
 *   3) 全市场合约 × 最近一个月 × 100 交易员模拟(硬规则: 10x/禁死扛/
 *      网格跌档0.5%加仓+30分冷却+每小时≤6/第3次加仓直接平仓)
 *   4) 报告(全员汇总+前十详细含评语/获奖感言)写入 bt_reports 表, 保留7天
 *
 * 运行方式: 计划任务 finally_bttimer 开机自启, 常驻循环, 每小时整点+5分触发
 * 复用组件: libhub.a (db_q/db_ex/http_get/okx_parse_candles/store/logline)
 * ================================================================== */
#include "../common/hub.h"                    // 公共组件: DB/HTTP/K线读写/日志/JSON工具
#include <winsock2.h>                         // Windows Socket(hub.h 已带, 显式声明依赖顺序)
#include <windows.h>                          // CreatePipe/CreateProcess 管道进程 API
#include <cstdio>                             // printf/snprintf
#include <cstdlib>                            // rand/srand/system
#include <ctime>                              // time/localtime_r
#include <algorithm>                          // sort/min/max
#include <random>                             // mt19937 (每场随机人格/参数洗牌)

// ---------------- 常量配置 ----------------
static const char* LAMA_EXE  = "E:\\finally-main\\ai\\llama\\bin\\llama-cli.exe";      // llama-cli 可执行文件
static const char* LAMA_MODEL= "E:\\finally-main\\ai\\llama\\qwen2.5-0.5b-instruct-q4_k_m.gguf"; // 千问 0.5B 模型
static const char* LOG_PATH  = "E:\\datas\\log\\bttimer.txt";                          // 本组件日志文件
static const int   STEPS     = 2880;          // 回测窗口步数: 30天 × 96根/天 (15m)
static const long long STEP_MS = 900000LL;    // 每步毫秒数(15分钟)
static const int   LEV       = 10;            // 硬性杠杆: 全员 10X
static const double FEE      = 0.0005;        // 单边手续费 0.05%(吃单)
static const double LIQ_MOVE = 0.092;         // 强平线: 距均价逆向 9.2% (10x 留缓冲)
static const double ADD_DIP  = 0.005;         // 网格跌档: 跌破上次加仓价 0.5% 才允许再加
static const int   ADD_CD    = 2;             // 加仓冷却: 2 根 15m = 30 分钟
static const int   ADD_HOUR  = 6;             // 每小时(4根)加仓次数上限
static const int   ADD_MAX   = 2;             // 最多加仓 2 次; 第 3 次加仓请求 → 直接平仓
static const int   HOLD_MAX  = 288;           // 禁死扛: 最长持仓 288 根 = 3 天, 到时强平
static const int   WARMUP    = 210;           // 指标预热: 前 210 根(EMA99 需要)不出信号
static const int   SLOTS     = 3;             // 每人最多同时持有 3 个仓位(跨合约)
static const int   REQ_BUDGET= 500;           // 每轮 OKX 请求预算(防限频, 3m/5m 用轮转补齐)
static const int   KEEP_DAYS = 7;             // 报告保留天数(过期清理)

// ---------------- 交易原型 (8 种方法 × 多空) ----------------
enum { A_BOLL=0, A_TREND3=1, A_MOM=2, A_RSI=3, A_DIP=4, A_BRK=5, A_ADAPT=6, A_RANGE=7, A_N=8 };
static const char* ARCH_NAME[A_N] = {"布林回归","三线排列","动量追单","RSI极值","急跌接针","突破追高","变色龙","箱体高抛"};

// 5 档风控参数 (每人按档位取): 仓位比例/止盈/止损/最长持有
static const double V_SIZE[5] = {0.05, 0.10, 0.15, 0.20, 0.25};
static const double V_TP[5]   = {0.04, 0.03, 0.02, 0.05, 0.06};
static const double V_SL[5]   = {0.02, 0.015, 0.01, 0.025, 0.03};
static const int    V_HOLD[5] = {288, 288, 192, 288, 288};

// 8 种性格 (影响仓位放大/缩小与上头停机)
static const char* TEMPER_NAME[8] = {"冷静","贪婪","恐惧","复仇","上头","佛系","赌徒","纪律"};

// ---------------- 数据结构 ----------------
struct PC {                                   // 单合约数据(回测窗口内)
    std::string inst;                         // 合约名, 如 ETH-USDT-SWAP
    std::vector<double> o,h,l,c;              // 开高低收(STEPS 对齐, 无数据处填 0)
    std::vector<double> e7,e25,e99,bu,bl,rsi,roc;  // 指标: EMA7/25/99, 布林上下轨, RSI14, ROC4
    int last = -1;                            // 最后一个有效步下标(-1 = 无数据)
};
struct Ev {                                   // 入场事件(已按 step 排序)
    int step, ci, side;                       // 步号 / 合约下标 / 方向 1多-1空
};
struct Spec {                                 // 交易员人设
    std::string name, temper, archN;          // 名字 / 性格 / 方法名
    int arch, dir, vi;                        // 原型 / 方向(1多-1空, 0=自适应双向) / 参数档
    double size, tp, sl; int hold;            // 仓位比例 / 止盈 / 止损 / 最长持有
};
struct Pos {                                  // 持仓
    int ci, side, openStep, adds, lastAddStep; // 合约/方向/开仓步/已加仓次数/上次加仓步
    double margin, qty, avg, lastAddPx;       // 保证金/数量(币)/均价/上次加仓价
};
struct Trade {                                // 成交记录(前十名序列化用)
    long long ts; std::string inst, act; double px, pnl; int hasPnl; // hasPnl=0 为开/加仓(无盈亏)
};
struct Res {                                  // 单人模拟结果
    double eq=1000, peak=1000, dd=0, fee=0, lp=0, sp=0;   // 期末权益/峰值/最大回撤/手续费/多利润/空利润
    int ntr=0, win=0, liqs=0, f3=0, adds=0;   // 平仓笔数/胜/强平/三次加仓平仓/加仓次数
    std::vector<double> curve;                // 权益曲线(30点, 每96步采样)
    std::vector<Trade> trades;                // 成交明细(封顶 400 条)
};

static std::mt19937 g_rng;                    // 全局随机源(每场按时间播种)

// ---------------- 工具 ----------------
static long long now_ms() { return (long long)time(nullptr) * 1000LL; }   // 当前毫秒
static std::string tstr(long long ms) {      // 毫秒 → "MM-DD HH:00" 简洁时间串
    time_t t = ms / 1000; struct tm lt; localtime_s(&lt, &t);
    char b[32]; strftime(b, sizeof(b), "%m-%d %H:%M", &lt); return b;
}
static std::string clean_name(const std::string& s) {  // 清洗 LLM 起的名字(去分隔符/控制字符, 截 12 字节)
    std::string r; int bytes = 0;
    for (size_t i = 0; i < s.size() && bytes < 12; ) { // 逐字节扫描
        unsigned char ch = (unsigned char)s[i];
        if (ch < 0x80) {                           // ASCII: 只留数字/字母/常用字符
            if ((ch>='0'&&ch<='9')||(ch>='A'&&ch<='Z')||(ch>='a'&&ch<='z')||ch=='-'||ch=='.') { r+=s[i]; bytes++; }
            i++;
        } else {                                   // UTF-8 多字节(中文): 整个序列保留
            int len = ch>=0xF0?4 : ch>=0xE0?3 : ch>=0xC0?2 : 1;
            if (i+len > s.size()) break;
            r += s.substr(i, len); i += len; bytes += len;
        }
    }
    return r.empty() ? "无名" : r;                 // 兜底名
}

// ---------------- 进程调用: 执行 exe 并捕获 stdout(带超时) ----------------
static bool run_capture(const std::string& cmd, int timeoutMs, std::string& out) {
    SECURITY_ATTRIBUTES sa = { sizeof(sa), nullptr, TRUE };             // 句柄可继承
    HANDLE rd=nullptr, wr=nullptr, erd=nullptr, ewr=nullptr;           // stdout/stderr 管道
    if (!CreatePipe(&rd, &wr, &sa, 1<<20)) return false;              // 建 stdout 管道(1MB 缓冲)
    SetHandleInformation(rd, HANDLE_FLAG_INHERIT, 0);                  // 读端不被子进程继承
    if (!CreatePipe(&erd, &ewr, &sa, 1<<20)) { CloseHandle(rd); CloseHandle(wr); return false; } // stderr 管道(丢弃 ggml 日志)
    SetHandleInformation(erd, HANDLE_FLAG_INHERIT, 0);
    STARTUPINFOA si = {}; si.cb = sizeof(si);                          // 启动信息
    si.dwFlags = STARTF_USESTDHANDLES; si.hStdOutput = wr; si.hStdError = ewr; si.hStdInput = GetStdHandle(STD_INPUT_HANDLE);
    PROCESS_INFORMATION pi = {};                                       // 进程信息
    std::vector<char> cbuf(cmd.begin(), cmd.end()); cbuf.push_back(0); // CreateProcessA 要求可写命令行缓冲
    BOOL ok = CreateProcessA(nullptr, cbuf.data(), nullptr, nullptr, TRUE, CREATE_NO_WINDOW, nullptr, nullptr, &si, &pi);
    CloseHandle(wr); CloseHandle(ewr);                                 // 父进程关写端(否则读不到 EOF)
    if (!ok) { CloseHandle(rd); CloseHandle(erd); return false; }      // 启动失败
    out.clear(); char buf[4096]; DWORD t0 = GetTickCount();            // 异步读循环(防管道写满死锁)
    while (GetTickCount() - t0 < (DWORD)timeoutMs) {                   // 超时前轮询
        DWORD n = 0;
        while (PeekNamedPipe(rd, nullptr, 0, nullptr, &n, nullptr) && n > 0) {  // stdout 有数据就搬走
            DWORD rdN = 0; if (!ReadFile(rd, buf, sizeof(buf), &rdN, nullptr) || !rdN) break;
            out.append(buf, rdN);                                      // 累加输出
        }
        n = 0;                                                         // stderr 同样搬空(只丢不存)
        while (PeekNamedPipe(erd, nullptr, 0, nullptr, &n, nullptr) && n > 0) {
            DWORD rdN = 0; if (!ReadFile(erd, buf, sizeof(buf), &rdN, nullptr) || !rdN) break;
        }
        if (WaitForSingleObject(pi.hProcess, 120) == WAIT_OBJECT_0) {  // 进程已退出: 搬完剩余输出后收尾
            while (PeekNamedPipe(rd, nullptr, 0, nullptr, &n, nullptr) && n > 0) {
                DWORD rdN = 0; if (!ReadFile(rd, buf, sizeof(buf), &rdN, nullptr) || !rdN) break;
                out.append(buf, rdN);
            }
            break;
        }
    }
    TerminateProcess(pi.hProcess, 0);          // 超时兜底: 杀掉(已退出时无害)
    WaitForSingleObject(pi.hProcess, 2000);    // 等内核清理句柄
    CloseHandle(pi.hThread); CloseHandle(pi.hProcess); CloseHandle(rd); CloseHandle(erd);
    return !out.empty();                       // 有输出即算成功
}

// ---------------- LLM: 千问生成 100 个人格名字+性格 ----------------
static void llm_personas(std::vector<std::string>& names, std::vector<int>& tempers) {
    names.clear(); tempers.clear();            // 输出容器清空
    const char* TMPF = "E:\\datas\\tmp\\bt_prompt.txt";   // 提示词临时文件(llama-cli -f 读入, 避免命令行中文编码问题)
    FILE* f = fopen(TMPF, "wb");               // 二进制写 UTF-8
    if (!f) return;                            // 写不了就放弃 → 走随机兜底
    fprintf(f, "你是交易员人格生成器。输出100行, 每行格式: 名字|性格序号\n"
               "名字用2到4个中文字(如: 老钱 阿飞 赵四 慧姐), 性格序号0到7: 0冷静 1贪婪 2恐惧 3复仇 4上头 5佛系 6赌徒 7纪律\n"
               "只输出100行, 不要编号不要解释:\n");   // 提示词(紧凑, 0.5B 模型友好)
    fclose(f);
    char cmd[1024];                            // 拼命令行: -f 读提示词, -no-cnv 纯补全, 温度调高求随机
    snprintf(cmd, sizeof(cmd),
        "\"%s\" -m \"%s\" -st -c 2048 -n 1500 -t 4 --temp 1.15 -f \"%s\"",
        LAMA_EXE, LAMA_MODEL, TMPF);
    std::string out;                           // 捕获输出
    if (!run_capture(cmd, 300000, out)) { logline("LLM 人格生成失败/超时 → 随机兜底"); return; }
    size_t pos = 0;                            // 逐行解析 "名字|序号"
    while (pos < out.size() && (int)names.size() < 100) {
        size_t e = out.find('\n', pos); if (e == std::string::npos) e = out.size();
        std::string line = out.substr(pos, e - pos); pos = e + 1;
        size_t bar = line.find('|'); if (bar == std::string::npos) continue;   // 无分隔符跳过
        std::string nm = clean_name(line.substr(0, bar));                       // 左边=名字
        int tp = atoi(line.c_str() + bar + 1);                                  // 右边=性格序号
        if (nm.empty() || nm == "无名" || tp < 0 || tp > 7) continue;           // 非法行跳过
        names.push_back(nm); tempers.push_back(tp);
    }
    logline("LLM 生成人格 " + std::to_string(names.size()) + "/100");
}

// ---------------- LLM: 给前十生成评语+获奖感言 ----------------
static void llm_speeches(const std::vector<Spec>& sp, const std::vector<int>& topIdx,
                         const std::vector<Res>& res, std::vector<std::string>& cmt, std::vector<std::string>& spc) {
    cmt.assign(topIdx.size(), ""); spc.assign(topIdx.size(), "");   // 输出容器(空=走模板兜底)
    const char* TMPF = "E:\\datas\\tmp\\bt_prompt2.txt";
    FILE* f = fopen(TMPF, "wb"); if (!f) return;
    fprintf(f, "你是加密货币模拟交易大赛评委。下面是前十名成绩, 给每人写一行: 名字|评语(20字内)|获奖感言(50字内)\n");
    for (size_t i = 0; i < topIdx.size(); i++) {    // 成绩单(供评委参考)
        const Spec& s = sp[topIdx[i]]; const Res& r = res[topIdx[i]];
        fprintf(f, "%zu.%s %s%s %.1f%% 胜率%.0f%%\n", i+1, s.name.c_str(), s.archN.c_str(),
                s.dir==1?"做多":s.dir==-1?"做空":"自适应", (r.eq/1000.0-1)*100.0, r.ntr?100.0*r.win/r.ntr:0);
    }
    fprintf(f, "只输出10行, 语气生动:\n"); fclose(f);
    char cmd[1024];
    snprintf(cmd, sizeof(cmd),
        "\"%s\" -m \"%s\" -st -c 2048 -n 1200 -t 4 --temp 1.1 -f \"%s\"",
        LAMA_EXE, LAMA_MODEL, TMPF);
    std::string out;
    if (!run_capture(cmd, 240000, out)) { logline("LLM 评语生成失败 → 模板兜底"); return; }
    size_t pos = 0; int got = 0;
    while (pos < out.size() && got < (int)topIdx.size()) {   // 逐行解析三段式
        size_t e = out.find('\n', pos); if (e == std::string::npos) e = out.size();
        std::string line = out.substr(pos, e - pos); pos = e + 1;
        size_t b1 = line.find('|'); if (b1 == std::string::npos) continue;
        size_t b2 = line.find('|', b1 + 1); if (b2 == std::string::npos) continue;
        cmt[got] = clean_name(line.substr(b1+1, b2-b1-1));   // 评语(复用清洗, 截断超长)
        spc[got] = clean_name(line.substr(b2+1));            // 感言
        got++;
    }
    logline("LLM 评语 " + std::to_string(got) + "/10");
}

// ---------------- 回填: 单合约单周期缺口(用 history-candles 可翻旧账) ----------------
static int reqCount = 0;                       // 本轮已用请求数(全局预算)
static bool okx_fetch(const std::string& inst, const std::string& tf, bool history,
                      bool useAfter, long long ref, std::string& body) {   // useAfter=true 向旧翻, false 用 before 向新翻
    if (reqCount >= REQ_BUDGET) return false;  // 预算耗尽: 本轮不再发请求
    reqCount++;
    char path[512];                            // history=true 用历史接口(能取到一个月前的旧K线)
    const char* ep = history ? "history-candles" : "candles";   // 接口名
    if (ref > 0)
        snprintf(path, sizeof(path), "/api/v5/market/%s?instId=%s&bar=%s&limit=300&%s=%lld",
                 ep, inst.c_str(), tf.c_str(), useAfter ? "after" : "before", ref);
    else
        snprintf(path, sizeof(path), "/api/v5/market/%s?instId=%s&bar=%s&limit=300", ep, inst.c_str(), tf.c_str());
    return http_get("www.okx.com", path, body);   // 公共接口裸 GET(3 次重试内建)
}
static int backfill_one(const std::string& inst, const std::string& tf, bool wantMonth) {   // 返回写入根数
    bool ok; std::string mxs = db_scalar("SELECT MAX(candle_time) FROM " + ktable(inst, tf), ok);
    if (!ok) { ensure_table(inst, tf); mxs = ""; }                 // 表不存在先建表
    long long maxT = mxs.empty() ? 0 : atoll_s(mxs);               // 库内最新bar时间(毫秒)
    bool newTable = maxT == 0;                 // 新表标记(需要整月历史时用)
    int barSec = tf == "3m" ? 180 : tf == "5m" ? 300 : 900;        // 周期秒数
    long long nowBar = now_ms() / (barSec * 1000) * barSec * 1000; // 当前bar起点(对齐)
    if (maxT >= nowBar - 2LL * barSec * 1000) return 0;            // 已追平(容 2 根误差)
    int total = 0; long long ref = maxT;                           // 从缺口左边界向新翻(before)
    while (ref < nowBar && total < 1200) {                         // 单轮 1200 根上限(防占死配额)
        std::string body;
        if (!okx_fetch(inst, tf, false, false, ref, body)) break;  // before=ref 取比 ref 更新的
        auto rows = okx_parse_candles(body);                       // 解析 K 线
        if (rows.empty()) break;                                   // 没数据 = 到头
        int w = store(inst, tf, rows);                             // REPLACE 入库
        if (w < 0) break;                                          // 写库失败终止
        total += w;                                                // 累计
        long long newest = atoll_s(rows[0][0]);                    // 本批最新(OKX 返回倒序, 第一行最新)
        if (newest <= ref) break;                                  // 游标没前进: 防死循环
        ref = newest;                                              // 推进游标
        Sleep(30);                                                 // 轻微限速
    }
    // 新表 + 15m + 需要整月历史: 先取最新一页, 再用 after 向更旧翻补满窗口起点
    if (newTable && tf == "15m" && wantMonth) {
        long long start = now_ms() - (long long)STEPS * STEP_MS;   // 窗口起点
        long long ref2 = 0;                                        // 向旧翻的游标
        std::string body;
        if (okx_fetch(inst, tf, true, false, 0, body)) {           // 第一批(最新300根)
            auto rows = okx_parse_candles(body);
            if (!rows.empty()) { total += store(inst, tf, rows); ref2 = atoll_s(rows[rows.size()-1][0]); }   // 本批最旧作游标
        }
        int page = 0;                                              // 向更旧翻页, 最多 12 页
        while (ref2 > start && page < 12 && reqCount < REQ_BUDGET) {
            page++;
            std::string b2;
            if (!okx_fetch(inst, tf, true, true, ref2, b2)) break; // after=ref2 取更旧
            auto rows = okx_parse_candles(b2);
            if (rows.empty()) break;
            total += store(inst, tf, rows);
            long long oldest = atoll_s(rows[rows.size()-1][0]);    // 本批最旧(最后一行)
            if (oldest >= ref2) break;                             // 游标没前进: 防死循环
            ref2 = oldest;                                         // 继续向旧
            Sleep(30);
        }
    }
    return total;
}
static void backfill_all() {                   // 每轮回填主函数
    reqCount = 0;                              // 重置预算
    RowSet rs = db_q("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' "
                     "AND table_name LIKE 'kline\\_%usdt\\_swap\\_15m'");   // 以 15m 表为准取全市场清单
    if (!rs.ok || rs.rows.empty()) { logline("回填: 取合约清单失败"); return; }
    static int rotate = 0;                     // 轮转游标(3m/5m 分批补齐用)
    int insts = (int)rs.rows.size(), bf15 = 0, bf35 = 0;
    std::vector<std::string> insts3m5m;        // 记录本轮轮转到的合约(供 3m/5m)
    for (int i = 0; i < insts; i++) {          // 第一优先: 全部合约 15m 追平(模拟直接用)
        std::string tn = rs.rows[i][0];        // 表名 kline_eth_usdt_swap_15m
        size_t p1 = tn.find('_'), p2 = tn.rfind("_usdt_swap_15m");
        if (p1 == std::string::npos || p2 == std::string::npos || p2 <= p1) continue;
        std::string inst = tn.substr(p1 + 1, p2 - p1 - 1);   // 币对部分
        for (auto& ch : inst) if (ch >= 'a' && ch <= 'z') ch -= 32;   // 转大写
        inst += "-USDT-SWAP";                  // 还原合约名
        if (!valid_inst(inst)) continue;       // 合法性校验(防注入)
        int w = backfill_one(inst, "15m", true); bf15 += w > 0 ? w : 0;   // 15m 追平+月历史
        if (i % 3 == (rotate % 3)) insts3m5m.push_back(inst);             // 1/3 合约本轮轮到 3m/5m
        if (reqCount >= REQ_BUDGET) break;     // 预算耗尽: 下一轮继续(轮转保证最终全覆盖)
    }
    for (const std::string& inst : insts3m5m) {   // 第二优先: 轮转合约的 3m/5m 近期追平
        if (reqCount >= REQ_BUDGET) break;
        bf35 += backfill_one(inst, "5m", false);
        if (reqCount >= REQ_BUDGET) break;
        bf35 += backfill_one(inst, "3m", false);
    }
    rotate++;                                  // 轮转推进(下一轮换一批合约补 3m/5m)
    char b[160]; snprintf(b, sizeof(b), "回填完成: 合约%d 15m+%d根 3m/5m+%d根 请求%d/%d",
                          insts, bf15, bf35, reqCount, REQ_BUDGET);
    logline(b);
}

// ---------------- 数据加载 + 指标 ----------------
static void ema(const std::vector<double>& src, int n, std::vector<double>& dst) {   // 标准 EMA
    dst.assign(src.size(), 0); if (src.empty()) return;
    double k = 2.0 / (n + 1); dst[0] = src[0];
    for (size_t i = 1; i < src.size(); i++) dst[i] = src[i] * k + dst[i-1] * (1 - k);
}
static void load_market(std::vector<PC>& mkt) {   // 读全市场 15m 窗口数据并算指标
    long long t0 = (now_ms() - (long long)STEPS * STEP_MS) / STEP_MS * STEP_MS;   // 窗口起点(对齐 15m)
    RowSet rs = db_q("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' "
                     "AND table_name LIKE 'kline\\_%usdt\\_swap\\_15m'");
    if (!rs.ok) return;
    int ln = 0;                                // 进度计数(每 150 合约落一条日志, 崩溃可定位)
    for (auto& r : rs.rows) {                  // 逐合约加载
        std::string tn = r[0];
        if (++ln % 150 == 0) logline("加载行情中... " + std::to_string(ln) + "/" + std::to_string(rs.rows.size()));
        size_t p1 = tn.find('_'), p2 = tn.rfind("_usdt_swap_15m");
        if (p1 == std::string::npos || p2 == std::string::npos || p2 <= p1) continue;
        std::string inst = tn.substr(p1 + 1, p2 - p1 - 1);
        for (auto& ch : inst) if (ch >= 'a' && ch <= 'z') ch -= 32;
        inst += "-USDT-SWAP";
        if (!valid_inst(inst)) continue;
        RowSet ks = db_q("SELECT candle_time,o,h,l,c FROM " + tn + " WHERE candle_time>=" +
                         std::to_string(t0) + " AND confirm=1 ORDER BY candle_time");
        if (!ks.ok || ks.rows.size() < 1200) continue;    // 数据不足(新上市)跳过
        PC p; p.inst = inst; p.o.assign(STEPS,0); p.h.assign(STEPS,0); p.l.assign(STEPS,0); p.c.assign(STEPS,0);
        bool dirty = false;                    // 脏数据标记(0 价/高低倒挂 → 整合约弃用)
        for (auto& k : ks.rows) {              // 映射到统一时间步
            long long ts = atoll_s(k[0]);
            long long idx = (ts - t0) / STEP_MS;
            if (idx < 0 || idx >= STEPS) continue;
            double o=atof_s(k[1]), h=atof_s(k[2]), l=atof_s(k[3]), c=atof_s(k[4]);
            if (c <= 0 || l <= 0 || l > h || o <= 0) { dirty = true; break; }   // 脏行(如 SATS 前 0 价段)
            p.o[idx]=o; p.h[idx]=h; p.l[idx]=l; p.c[idx]=c;
            if ((int)idx > p.last) p.last = (int)idx;   // 记最后有效步
        }
        if (dirty || p.last < 1500) continue;  // 脏数据或覆盖太短跳过
        mkt.push_back(std::move(p));           // 收入
    }
    // ---- 逐合约算指标(EMA/布林/RSI/ROC) ----
    for (PC& p : mkt) {
        std::vector<double>& c = p.c;
        ema(c, 7, p.e7); ema(c, 25, p.e25); ema(c, 99, p.e99);   // 三线
        p.bu.assign(STEPS,0); p.bl.assign(STEPS,0);              // 布林(20,2)
        for (int i = 20; i < STEPS; i++) {                        // 20 根滚动均值/方差
            double s = 0, s2 = 0; int cnt = 0;
            for (int j = i - 19; j <= i; j++) { if (c[j] <= 0) continue; s += c[j]; s2 += c[j]*c[j]; cnt++; }
            if (cnt < 15) continue;                               // 缺数据太多不算
            double m = s / cnt, sd = sqrt(std::max(0.0, s2/cnt - m*m));
            p.bu[i] = m + 2*sd; p.bl[i] = m - 2*sd;
        }
        p.rsi.assign(STEPS,50); p.roc.assign(STEPS,0);           // RSI14(Wilder) + ROC4
        double ag = 0, al = 0;
        for (int i = 1; i < STEPS; i++) {
            if (c[i] <= 0 || c[i-1] <= 0) continue;
            double d = c[i] - c[i-1];                             // 价差
            ag = (ag * 13 + (d > 0 ? d : 0)) / 14;                // 平均涨
            al = (al * 13 + (d < 0 ? -d : 0)) / 14;               // 平均跌
            p.rsi[i] = al < 1e-12 ? 100 : 100 - 100 / (1 + ag / al);
            p.roc[i] = c[i-4] > 0 ? c[i] / c[i-4] - 1 : 0;        // 4 根动量
        }
    }
    logline("数据加载: " + std::to_string(mkt.size()) + " 合约 × " + std::to_string(STEPS) + " 根");
}
static inline int regime_of(const PC& p, int i) {   // 市场状态: 1上 -1下 0震荡
    if (p.e25[i] > p.e99[i] && p.c[i] > p.e25[i]) return 1;
    if (p.e25[i] < p.e99[i] && p.c[i] < p.e25[i]) return -1;
    return 0;
}
static double hh(const PC& p, int i, int n) {       // 前 n 根最高价
    double m = 0; for (int j = std::max(1, i-n); j < i; j++) if (p.h[j] > m) m = p.h[j]; return m;
}
static double ll(const PC& p, int i, int n) {       // 前 n 根最低价
    double m = 1e18; for (int j = std::max(1, i-n); j < i; j++) if (p.l[j] > 0 && p.l[j] < m) m = p.l[j]; return m;
}

// ---------------- 事件预计算: 每种原型×方向在每合约的入场点 ----------------
static void build_events(const std::vector<PC>& mkt, std::vector<Ev> ev[A_N][2]) {
    for (size_t ci = 0; ci < mkt.size(); ci++) {     // 逐合约扫
        const PC& p = mkt[ci];
        for (int i = WARMUP; i <= p.last; i++) {     // 预热后逐根判条件
            if (p.c[i] <= 0 || p.c[i-1] <= 0) continue;
            int reg = regime_of(p, i);               // 当前状态
            bool cond[A_N][2] = {};                  // 各原型多空条件命中表
            cond[A_BOLL][0] = p.bl[i] > 0 && p.c[i] < p.bl[i] && p.rsi[i] < 38;   // 布林下轨+RSI低
            cond[A_BOLL][1] = p.bu[i] > 0 && p.c[i] > p.bu[i] && p.rsi[i] > 62;   // 布林上轨+RSI高
            cond[A_TREND3][0] = p.e7[i] > p.e25[i] && p.e25[i] > p.e99[i] && p.c[i] > p.e7[i];  // 三线多头排列
            cond[A_TREND3][1] = p.e7[i] < p.e25[i] && p.e25[i] < p.e99[i] && p.c[i] < p.e7[i];  // 空头排列
            cond[A_MOM][0] = p.roc[i] > 0.02 && p.c[i] > p.e25[i];   // 动量向上
            cond[A_MOM][1] = p.roc[i] < -0.02 && p.c[i] < p.e25[i];  // 动量向下
            cond[A_RSI][0] = p.rsi[i] < 25;          // 超卖
            cond[A_RSI][1] = p.rsi[i] > 75;          // 超买
            cond[A_DIP][0] = p.c[i] < hh(p, i, 24) * 0.92;           // 24 根内跌 8% 接针
            cond[A_DIP][1] = p.c[i] > ll(p, i, 24) * 1.08;           // 涨 8% 逆势空
            cond[A_BRK][0] = p.c[i] > hh(p, i, 20);                  // 破 20 根新高
            cond[A_BRK][1] = p.c[i] < ll(p, i, 20);                  // 破 20 根新低
            cond[A_RANGE][0] = p.bl[i] > 0 && p.c[i] < p.bl[i] && p.rsi[i] >= 28 && p.rsi[i] <= 45;  // 箱底
            cond[A_RANGE][1] = p.bu[i] > 0 && p.c[i] > p.bu[i] && p.rsi[i] >= 55 && p.rsi[i] <= 72;  // 箱顶
            for (int a = 0; a < A_N; a++) {          // 收录进事件表
                if (a == A_ADAPT) continue;          // 变色龙单独处理
                if (cond[a][0]) ev[a][0].push_back({i, (int)ci, 1});   // 做多事件
                if (cond[a][1]) ev[a][1].push_back({i, (int)ci, -1});  // 做空事件
            }
            // 变色龙: 顺状态 — 上升趋势只留多头事件, 下降只留空头, 震荡用布林
            if (reg == 1 && cond[A_TREND3][0]) ev[A_ADAPT][0].push_back({i, (int)ci, 1});
            if (reg == -1 && cond[A_TREND3][1]) ev[A_ADAPT][1].push_back({i, (int)ci, -1});
            if (reg == 0) {
                if (cond[A_BOLL][0]) ev[A_ADAPT][0].push_back({i, (int)ci, 1});
                if (cond[A_BOLL][1]) ev[A_ADAPT][1].push_back({i, (int)ci, -1});
            }
        }
    }
}

// ---------------- 人设生成: 8 原型 × 多空 × 5 档 × 随机性格, 每场不同 ----------------
// ---------------- 名人专家库(真实交易大师, 每场随机抽取上场) ----------------
// 策略映射到模拟原型; 名言/风控理念来源: tradeciety/quantifiedstrategies/strike.money 等公开资料
struct Exp { const char* name; int arch, dir, vi, temper; const char* tip; };
static const Exp EXP[] = {
    {"利弗莫尔·投机之王",   A_BRK,    0,  2, 7, "市场永远没错, 错的是 opinion; 亏钱最快的方式是不认亏"},   // Jesse Livermore 趋势+逆势都不拘, 关键止损
    {"利弗莫尔·金字塔",     A_TREND3, 1,  3, 4, "钱是坐着赚的; 顺势金字塔加码, 永不摊平亏损"},             // pyramiding 加码盈利仓
    {"巴菲特·价值定投",     A_DIP,    1,  0, 5, "别人恐惧我贪婪; 买在恐慌, 拿得住才拿得到钱"},             // Buffett 逆势买+超长持
    {"索罗斯·反身性",       A_ADAPT,  0,  3, 7, "市场总是错的; 重要是判断错在哪个方向"},                   // Soros 反身性双向
    {"德鲁肯米勒·宏观之王", A_TREND3, 0,  4, 7, "看对要下重注, 但先保本; 现金也是仓位"},                   // Druckenmiller 集中+流动性
    {"保罗·都铎·琼斯",      A_ADAPT,  0,  0, 7, "每天假设所有仓位都是错的; 防守第一, 亏5%立刻减仓"},       // PTJ 200日线+1%风控
    {"丹尼斯·海龟之父",     A_TREND3, 0,  2, 7, "趋势是朋友; 规则可以教会, 纪律教不会"},                   // Richard Dennis 海龟实验
    {"塞柯塔·止损三诀",     A_TREND3, 1,  1, 7, "砍亏损砍亏损砍亏损; 人人都能发财, 前提是拿得住"},         // Ed Seykota
    {"拉里·海特·1%风控",    A_MOM,    0,  0, 7, "每次只冒 1% 的险, 活得久比赢得多重要"},                   // Larry Hite
    {"威廉姆斯·短线爆发",   A_BRK,    0,  4, 0, "交易 80% 是心理 20% 是策略; 波动率突破就是入场券"},       // Larry Williams
    {"达瓦斯·箱体舞者",     A_RANGE,  1,  2, 7, "只买创 52 周新高+放量的; 止损跟着箱子走"},                 // Nicolas Darvas box theory
    {"舒华兹·日内之王",     A_MOM,    0,  1, 7, "止损不可谈判; 每天归零, 上一单与今天无关"},               // Marty Schwartz
    {"马库斯·骑赢家的马",   A_TREND3, 1,  3, 0, "拿住赢的单子直到理由消失; 输的单子绝不加码"},             // Michael Marcus
    {"科夫纳·基本面趋势",   A_TREND3, 0,  2, 7, "止损放在'想法被证伪'的地方, 不是放在能亏多少钱的地方"},   // Bruce Kovner
    {"拉施克·价行动作派",   A_RANGE,  0,  1, 7, "价格领先, 指标滞后; 把秘密告诉你也没用, 因为你拿不住"},   // Linda Raschke
    {"勃兰特·图形老猎手",   A_BRK,    0,  1, 7, "没有好球就不出棒; 空仓也是策略"},                         // Peter Brandt
    {"保尔森·大空头",       A_TREND3, -1, 4, 6, "看准极端错价敢 all-in, 但用极小止损保护赌注"},            // John Paulson
    {"江恩·时间价格",       A_RANGE,  0,  2, 7, "永远用止损单; 不要过度交易, 不要逆势加仓"},               // W.D. Gann
    {"埃尔德·三重滤网",     A_TREND3, 0,  0, 7, "三重滤网多周期共振才出手; 仓位决定你能活多久"},           // Alexander Elder
    {"巴索·期望值大师",     A_ADAPT,  0,  2, 0, "不求每单都赢, 只求系统期望为正; 一致性重于完美"},         // Tom Basso
};
static const int EXP_N = sizeof(EXP) / sizeof(EXP[0]);   // 20 位名人
static const int EXP_SLOTS = 28;                          // 每场上场名人席位(其余 72 席随机人格)

static std::vector<Spec> make_specs(const std::vector<std::string>& llmNames, const std::vector<int>& llmTemps) {
    std::vector<Spec> out; out.reserve(100);
    // ---- 名人专家席位: 每场洗牌抽 EXP_SLOTS 位(不同场次名人阵容不同) ----
    std::vector<int> expIdx(EXP_N);            // 名人下标池
    for (int i = 0; i < EXP_N; i++) expIdx[i] = i;
    std::shuffle(expIdx.begin(), expIdx.end(), g_rng);           // 每场随机洗牌
    std::vector<int> expertSeats(100, -1);     // 座位→名人下标映射(默认 -1 = 普通席位)
    for (int i = 0; i < EXP_SLOTS; i++) expertSeats[i] = expIdx[i % EXP_N];   // 前 28 席给名人(可重复轮补)
    std::shuffle(expertSeats.begin(), expertSeats.end(), g_rng); // 名人座位也洗牌(位置不固定)
    std::vector<int> varIdx(100);              // 参数档洗牌(每场不同配比)
    for (int i = 0; i < 100; i++) varIdx[i] = i % 5;
    std::shuffle(varIdx.begin(), varIdx.end(), g_rng);
    std::vector<int> dirMap(100);              // 多空分配洗牌(多空各半)
    for (int i = 0; i < 100; i++) dirMap[i] = (i % 2) ? -1 : 1;
    std::shuffle(dirMap.begin(), dirMap.end(), g_rng);
    char nb[16];
    for (int k = 0; k < 100; k++) {            // 逐人生成
        Spec s; s.arch = k % A_N;              // 8 种原型轮流覆盖
        s.dir = dirMap[k];                     // 洗牌后的多空
        s.vi = varIdx[k];                      // 洗牌后的参数档
        if (s.arch == A_ADAPT) s.dir = 0;      // 变色龙 = 双向自适应
        s.size = V_SIZE[s.vi]; s.tp = V_TP[s.vi]; s.sl = V_SL[s.vi]; s.hold = V_HOLD[s.vi];
        int tp;                                // 性格: 优先 LLM, 缺失按序取
        if (k < (int)llmTemps.size()) tp = llmTemps[k]; else tp = (k * 7 + 3) % 8;
        s.temper = TEMPER_NAME[tp];
        s.name = k < (int)llmNames.size() ? llmNames[k] : (snprintf(nb, sizeof(nb), "员%02d", k+1), std::string(nb));
        s.archN = ARCH_NAME[s.arch];
        if (expertSeats[k] >= 0) {             // 名人专家席位: 覆盖人设为大师配置
            const Exp& e = EXP[expertSeats[k]];
            s.name = e.name; s.arch = e.arch; s.dir = e.dir; s.vi = e.vi;
            s.size = V_SIZE[e.vi]; s.tp = V_TP[e.vi]; s.sl = V_SL[e.vi]; s.hold = V_HOLD[e.vi];
            s.temper = TEMPER_NAME[e.temper]; tp = e.temper;
            s.archN = std::string(ARCH_NAME[e.arch]) + "·" + e.tip;   // 方法名带名人理念(报告可见)
        }
        out.push_back(s);
    }
    return out;
}

// ---------------- 单人模拟 (合并时间轴事件驱动) ----------------
static Res sim_one(const Spec& s, const std::vector<PC>& mkt, const std::vector<Ev>(&ev)[A_N][2], long long t0) {
    Res r; r.curve.reserve(31);
    std::vector<Pos> open;                     // 当前持仓(≤SLOTS)
    double eq = 1000;                          // 初始本金 1000U
    int lossStreak = 0, winStreak = 0;         // 连亏/连胜(性格效果用)
    long long tiltUntilStep = -1;              // 上头停机到哪一步
    double ddPeak = 1000;
    const std::vector<Ev> &EL = ev[s.arch][0], &ES = ev[s.arch][1];   // 多头事件流 / 空头事件流(dir 过滤决定用哪条)
    auto temperMult = [&]() -> double {            // 性格对仓位的放大系数
        if (s.temper == "贪婪" && winStreak >= 3) return 1.5;   // 贪婪+连胜3 → 加码
        if (s.temper == "复仇" && lossStreak >= 2) return 2.0;   // 复仇+连亏2 → 报复性加倍
        if (s.temper == "恐惧" && eq < ddPeak * 0.8) return 0.5; // 恐惧+回撤20% → 减半
        if (s.temper == "赌徒") return 1.3;                      // 赌徒永远偏大
        if (s.temper == "纪律" || s.temper == "冷静") return 1.0;// 纪律/冷静不动
        return 1.0;
    };
    auto addTrade = [&](long long ts, int ci, const std::string& act, double px, double pnl, int hasPnl) {
        if ((int)r.trades.size() >= 400) return;    // 明细封顶 400 条(前十序列化用)
        r.trades.push_back({ts, mkt[ci].inst, act, px, pnl, hasPnl ? 1 : 0});
    };
    // 平仓 lambda: 结算盈亏/统计/性格状态机
    auto close_pos = [&](Pos& p, int step, double px, const char* why) {
        double pnl = (px - p.avg) * p.qty * p.side;              // 价差盈亏
        if (pnl < -p.margin) pnl = -p.margin;                    // 逐仓: 亏穿以保证金封底
        double feeOut = px * p.qty * FEE;                        // 平仓手续费
        eq += p.margin + pnl - feeOut;                           // 保证金退回+盈亏-手续费
        r.fee += feeOut;                                         // 统计
        if (p.side > 0) r.lp += pnl - feeOut; else r.sp += pnl - feeOut;  // 多空分账
        r.ntr++;
        if (pnl > 0) { r.win++; winStreak++; lossStreak = 0; } else { lossStreak++; winStreak = 0; }
        if (eq > ddPeak) ddPeak = eq;                            // 回撤
        double ddNow = ddPeak > 0 ? (ddPeak - eq) / ddPeak : 0;
        if (ddNow > r.dd) r.dd = ddNow;
        addTrade(t0 + (long long)step * STEP_MS, p.ci, std::string(why) + (p.adds ? "·含加仓" : ""), px, pnl, 1);   // 记录
        // 从持仓表移除(与末位交换)
        for (size_t i = 0; i < open.size(); i++) if (&open[i] == &p) { std::swap(open[i], open.back()); open.pop_back(); break; }
    };
    for (int step = 0; step < STEPS; step++) {   // ===== 主时间轴 =====
        if (step % 96 == 0) {                    // 每天采样权益曲线
            r.curve.push_back(eq);
            if (eq > r.peak) r.peak = eq;
        }
        // ---- ① 持仓逐笔体检: 止盈/止损/强平/到时/加仓 ----
        for (int pi = (int)open.size() - 1; pi >= 0; pi--) {     // 倒序(平仓会交换删除)
            Pos& p = open[pi];
            const PC& cp = mkt[p.ci];
            if (step > cp.last || cp.c[step] <= 0) continue;     // 该步无数据跳过
            double hi = cp.h[step], lo = cp.l[step], px = cp.c[step];
            // 加仓判定(先于出场: 跌档即触发)
            if (p.adds <= ADD_MAX && p.side * (lo - p.lastAddPx) < 0 &&    // 逆向创出新低/新高
                fabs(lo - p.lastAddPx) / p.lastAddPx >= ADD_DIP &&         // 幅度 ≥ 0.5%
                step - p.lastAddStep >= ADD_CD) {                          // 冷却 30 分钟
                int hourAdds = 0;                                          // 每小时配额: 数最近 4 步加仓次数(简化: 每步最多1次, 冷却2步 → 自然≤2, 配额必过, 保留逻辑位)
                if (p.adds < ADD_MAX + 1 && hourAdds < ADD_HOUR) {         // 未超限
                    if (p.adds == ADD_MAX) {                               // 已经加过 2 次 → 第 3 次请求直接平仓(硬规则)
                        r.f3++;
                        double fill = p.side > 0 ? lo : hi;                // 按触发价成交
                        close_pos(p, step, fill, "三次加仓强平");
                        continue;
                    }
                    double fillPx = p.side > 0 ? lo : hi;                  // 加仓成交价(逆向极值)
                    double addM = p.margin * 0.5;                          // 加仓保证金 = 原仓 50%
                    double addQ = addM * LEV / fillPx;                     // 加仓数量
                    double feeIn = addM * LEV * FEE;                       // 加仓手续费
                    eq -= addM + feeIn; r.fee += feeIn;                    // 扣款
                    p.avg = (p.avg * p.qty + fillPx * addQ) / (p.qty + addQ);   // 重算均价
                    p.qty += addQ; p.margin += addM;                       // 并仓
                    p.lastAddPx = fillPx; p.lastAddStep = step; p.adds++; r.adds++;
                    addTrade(t0 + (long long)step * STEP_MS, p.ci, p.side > 0 ? "网格跌档加仓·多" : "网格跌档加仓·空", fillPx, 0, 0);
                }
            }
            // 出场判定(保守顺序: 先查止损/强平用低点, 再查止盈用高点)
            double slPx = p.avg * (1 - p.side * s.sl);                     // 止损价(随均价)
            double liqPx = p.avg * (1 - p.side * LIQ_MOVE);                // 强平价
            if ((p.side > 0 && lo <= liqPx) || (p.side < 0 && hi >= liqPx)) {   // 强平(最优先)
                r.liqs++;
                close_pos(p, step, liqPx, "保证金强平");
                continue;
            }
            if ((p.side > 0 && lo <= slPx) || (p.side < 0 && hi >= slPx)) {    // 止损(禁死扛)
                close_pos(p, step, slPx, "止损离场");
                continue;
            }
            double tpPx = p.avg * (1 + p.side * s.tp);                     // 止盈价(随均价)
            if ((p.side > 0 && hi >= tpPx) || (p.side < 0 && lo <= tpPx)) {
                close_pos(p, step, tpPx, "止盈落袋");
                continue;
            }
            if (step - p.openStep >= s.hold || step - p.openStep >= HOLD_MAX) { // 到时平仓(禁死扛兜底)
                close_pos(p, step, px, "到期平仓");
                continue;
            }
        }
        // ---- ② 性格停机检查(上头/连亏删APP) ----
        if (step < tiltUntilStep) continue;      // 停机中: 不看事件
        if (s.temper == "上头" && lossStreak >= 3) { tiltUntilStep = step + 96; lossStreak = 0; continue; }  // 上头连亏3 → 停一天
        // ---- ③ 事件入场: 对两条方向流分别处理本步事件(各做二分定位) ----
        for (int d = 0; d < 2; d++) {
            if (s.dir == 1 && d == 1) continue;               // 纯多头不看空流
            if (s.dir == -1 && d == 0) continue;              // 纯空头不看多流
            const std::vector<Ev>& stream = d == 0 ? EL : ES; // 本方向事件流(已按 step 升序)
            if (stream.empty()) continue;
            size_t lo2 = 0, hi2 = stream.size();              // 二分: 第一个 step>=当前步 的位置
            while (lo2 < hi2) { size_t mid = (lo2 + hi2) / 2; if (stream[mid].step < step) lo2 = mid + 1; else hi2 = mid; }
            for (size_t k = lo2; k < stream.size() && stream[k].step == step; k++) {   // 本步全部事件
                const Ev& e = stream[k];
                bool have = false;                            // 该合约已持仓?
                for (const Pos& p : open) if (p.ci == e.ci) { have = true; break; }
                if (have || (int)open.size() >= SLOTS) continue;   // 满仓/重复跳过
                if (eq < 50) break;                           // 权益枯竭
                const PC& cp = mkt[e.ci];
                double px = cp.c[step]; if (px <= 0) continue;
                double mult = temperMult();                   // 性格仓位系数
                double mg = eq * s.size * mult;               // 保证金
                if (mg > eq * 0.6) mg = eq * 0.6;             // 单仓不超权益 60%
                if (mg < 5) continue;                         // 太小不开
                double qty = mg * LEV / px;                   // 数量
                double feeIn = mg * LEV * FEE;                // 开仓手续费
                eq -= mg + feeIn; r.fee += feeIn;
                Pos np; np.ci = e.ci; np.side = e.side; np.openStep = step; np.adds = 0;
                np.lastAddStep = step; np.margin = mg; np.qty = qty; np.avg = px; np.lastAddPx = px;
                open.push_back(np);
                addTrade(t0 + (long long)step * STEP_MS, e.ci, s.archN + (e.side > 0 ? "·做多" : "·做空"), px, 0, 0);
            }
        }
    }
    // 期末: 强制清掉未平仓位(按最后收盘价结算, 不算平仓笔数)
    for (Pos& p : open) {
        const PC& cp = mkt[p.ci];
        double px = cp.last >= 0 ? cp.c[cp.last] : p.avg;
        double pnl = (px - p.avg) * p.qty * p.side;
        if (pnl < -p.margin) pnl = -p.margin;
        eq += p.margin + pnl - px * p.qty * FEE;
        if (p.side > 0) r.lp += pnl; else r.sp += pnl;
    }
    r.eq = eq; r.curve.push_back(eq);          // 期末权益入曲线
    return r;
}

// ---------------- 报告 JSON 生成 + 入库 ----------------
static void write_report(const std::vector<Spec>& sp, std::vector<Res>& res, int nc, long long t0, long long t1) {
    // 汇总总成交笔数
    int ntr = 0; for (const Res& r : res) ntr += r.ntr;
    // 排行: 按期末权益降序
    std::vector<int> order(res.size());
    for (size_t i = 0; i < order.size(); i++) order[i] = (int)i;
    std::sort(order.begin(), order.end(), [&](int a, int b) { return res[a].eq > res[b].eq; });
    // 多王/空王: 多利润最高(只多) / 空利润最高(只空)
    int bestL = -1, bestS = -1;
    for (int i : order) {
        if (sp[i].dir == 1 && (bestL < 0 || res[i].eq > res[bestL].eq)) bestL = i;
        if (sp[i].dir == -1 && (bestS < 0 || res[i].eq > res[bestS].eq)) bestS = i;
    }
    // ---- summary_json: 全员 100 行 ----
    std::string sj = "{\"ts\":" + std::to_string(now_ms()) + ",\"ps\":" + std::to_string(t0) + ",\"pe\":" + std::to_string(t1) +
                     ",\"nc\":" + std::to_string(nc) + ",\"nt\":" + std::to_string(ntr) + ",\"rows\":[";
    for (size_t k = 0; k < order.size(); k++) {          // 排行序输出
        int i = order[k]; const Spec& s = sp[i]; const Res& r = res[i];
        if (k) sj += ",";
        sj += "{\"rk\":" + std::to_string(k+1) + ",\"nm\":\"" + jesc(s.name) + "\",\"ar\":\"" + jesc(s.archN) +
              "\",\"dr\":" + std::to_string(s.dir) + ",\"tp\":\"" + jesc(s.temper) +
              "\",\"sz\":" + jnum(s.size) + ",\"tpv\":" + jnum(s.tp) + ",\"slv\":" + jnum(s.sl) +
              ",\"fin\":" + jnum(r.eq) + ",\"ret\":" + jnum((r.eq/1000.0-1)*100) +
              ",\"dd\":" + jnum(r.dd*100) + ",\"ntr\":" + std::to_string(r.ntr) +
              ",\"win\":" + jnum(r.ntr ? 100.0*r.win/r.ntr : 0) +
              ",\"liq\":" + std::to_string(r.liqs) + ",\"f3\":" + std::to_string(r.f3) +
              ",\"fee\":" + jnum(r.fee) + ",\"lp\":" + jnum(r.lp) + ",\"sp\":" + jnum(r.sp) + ",\"cv\":[";
        for (size_t j = 0; j < r.curve.size(); j++) { if (j) sj += ","; sj += jnum(r.curve[j]); }
        sj += "]}";
    }
    sj += "]}";
    // ---- top10_json: 前十详细(评语/感言/成交明细) ----
    std::vector<std::string> cmt, spc;
    std::vector<int> topIdx(order.begin(), order.begin() + std::min<size_t>(10, order.size()));
    llm_speeches(sp, topIdx, res, cmt, spc);
    static const char* CMT_TPL[8] = {"方法纪律在线，盈亏比合理","风格激进，靠趋势吃饭","出手太频，被手续费蚕食","亏后报复开仓是最大漏洞","节奏混乱，需要系统化","稳字当头，牺牲弹性换生存","赌性坚强，命运大起大落","纪律执行满分，值得实盘借鉴"};
    std::string tj = "{\"top\":[";
    for (size_t k = 0; k < topIdx.size(); k++) {
        int i = topIdx[k]; const Spec& s = sp[i]; const Res& r = res[i];
        if (k) tj += ",";
        std::string cm = cmt[k].empty() ? std::string(CMT_TPL[s.arch % 8]) : cmt[k];     // 评语(LLM 兜底模板)
        std::string sp2 = spc[k].empty() ?                                               // 感言(LLM 兜底模板)
            ("这场我靠「" + s.archN + (s.dir >= 0 ? "·顺多" : "·顺空") + "」打出 " +
             std::to_string((int)((r.eq/1000.0-1)*100)) + "%，关键在守纪律：止损不犹豫，加仓等跌档，三次加仓必走人。")
            : spc[k];
        tj += "{\"rk\":" + std::to_string(k+1) + ",\"nm\":\"" + jesc(s.name) + "\",\"ar\":\"" + jesc(s.archN) +
              "\",\"dr\":" + std::to_string(s.dir) + ",\"tp\":\"" + jesc(s.temper) +
              "\",\"sz\":" + jnum(s.size) + ",\"tpv\":" + jnum(s.tp) + ",\"slv\":" + jnum(s.sl) +
              ",\"fin\":" + jnum(r.eq) + ",\"ret\":" + jnum((r.eq/1000.0-1)*100) +
              ",\"dd\":" + jnum(r.dd*100) + ",\"ntr\":" + std::to_string(r.ntr) +
              ",\"win\":" + jnum(r.ntr ? 100.0*r.win/r.ntr : 0) +
              ",\"liq\":" + std::to_string(r.liqs) + ",\"f3\":" + std::to_string(r.f3) +
              ",\"fee\":" + jnum(r.fee) + ",\"cm\":\"" + jesc(cm) + "\",\"sp\":\"" + jesc(sp2) + "\",\"cv\":[";
        for (size_t j = 0; j < r.curve.size(); j++) { if (j) tj += ","; tj += jnum(r.curve[j]); }
        tj += "],\"tr\":[";
        int shown = 0;                            // 明细取最近 60 条
        for (int j = (int)r.trades.size() - 1; j >= 0 && shown < 60; j--, shown++) {
            const Trade& tr = r.trades[j];
            if (shown) tj += ",";
            tj += "{\"ts\":" + std::to_string(tr.ts) + ",\"in\":\"" + jesc(tr.inst) + "\",\"a\":\"" + jesc(tr.act) +
                  "\",\"px\":" + jnum(tr.px) + ",\"pn\":" + (tr.hasPnl ? jnum(tr.pnl) : std::string("null")) + "}";
        }
        tj += "]}";
    }
    tj += "]}";
    // ---- 一句话简介 ----
    char brief[256];
    snprintf(brief, sizeof(brief), "多王%s(%s) %+.1f%% · 空王%s %+.1f%% · %d人×%d币·%d笔",
             bestL >= 0 ? sp[bestL].name.c_str() : "-", bestL >= 0 ? sp[bestL].archN.c_str() : "-",
             bestL >= 0 ? (res[bestL].eq/1000.0-1)*100 : 0,
             bestS >= 0 ? sp[bestS].name.c_str() : "-", bestS >= 0 ? (res[bestS].eq/1000.0-1)*100 : 0,
             (int)res.size(), nc, ntr);
    // ---- 入库 ----
    std::string sql = "INSERT INTO bt_reports (run_ts,period_start,period_end,n_traders,n_contracts,n_trades,brief,summary_json,top10_json) VALUES (" +
                      std::to_string(now_ms()) + "," + std::to_string(t0) + "," + std::to_string(t1) + "," +
                      std::to_string(res.size()) + "," + std::to_string(nc) + "," + std::to_string(ntr) + ",'" +
                      sqlesc(brief) + "','" + sqlesc(sj) + "','" + sqlesc(tj) + "')";
    if (db_ex(sql)) logline("报告已入库: " + std::string(brief));
    else logline("报告入库失败!");
    db_ex("DELETE FROM bt_reports WHERE run_ts < " + std::to_string(now_ms() - (long long)KEEP_DAYS * 86400000LL));   // 过期清理
}

// ---------------- 单轮任务: 回填 → 人格 → 模拟 → 报告 ----------------
static void run_once() {
    long long t0 = (now_ms() - (long long)STEPS * STEP_MS) / STEP_MS * STEP_MS;   // 窗口起点
    long long t1 = now_ms();                    // 窗口终点
    logline("===== 开始一轮模拟 " + tstr(t1) + " =====");
    backfill_all();                             // ① 回填 3m/5m/15m
    std::vector<PC> mkt; load_market(mkt);      // ② 加载行情+指标
    if (mkt.size() < 20) { logline("可用合约不足 20, 本轮跳过"); return; }
    std::vector<std::string> names; std::vector<int> temps;
    llm_personas(names, temps);                 // ③ 千问生成人格
    g_rng.seed((unsigned)time(nullptr) ^ (unsigned)GetTickCount());   // 每场不同随机
    std::vector<Spec> sp = make_specs(names, temps);                  // ④ 100 人设
    std::vector<Ev> ev[A_N][2];                 // ⑤ 事件预计算
    build_events(mkt, ev);
    logline("事件预计算完成");                   // 分段留痕(定位崩溃段)
    std::vector<Res> res(sp.size());            // ⑥ 逐人模拟
    for (size_t i = 0; i < sp.size(); i++) res[i] = sim_one(sp[i], mkt, ev, t0);
    write_report(sp, res, (int)mkt.size(), t0, t1);                   // ⑦ 报告入库
    logline("===== 本轮结束 =====");
}

// ---------------- worker: 单实例互斥 + 主循环(真正干活的部分) ----------------
static int worker_main() {
    HANDLE mtx = CreateMutexA(nullptr, TRUE, "bttimer_single_instance");   // 命名互斥体: 防双开(计划任务+手动)
    if (!mtx || GetLastError() == ERROR_ALREADY_EXISTS) {  // 已有实例在跑
        logline("检测到另一实例已运行, 本进程退出");
        return 0;                                          // 直接退出
    }
    log_setfile(LOG_PATH);                      // 日志落 E:\datas\log\bttimer.txt
    logline("bt_timer worker 启动 (每小时一场 AI 模拟回测)");
    run_once();                                 // 启动即跑第一场(页面立刻有报告)
    for (;;) {                                  // 常驻: 每小时整点+5分触发下一场
        time_t now = time(nullptr);             // 当前时间
        struct tm lt; localtime_s(&lt, &now);   // 本地时间
        int secNow = lt.tm_min * 60 + lt.tm_sec;    // 本小时已过秒数
        int waitSec = 5 * 60 - secNow;          // 距本小时 05:00 的秒数
        if (waitSec <= 0) waitSec += 3600;      // 已过 05 分 → 等下一个小时的 05 分
        for (int i = 0; i < waitSec; i++) Sleep(1000);   // 秒级睡眠(便于进程随时被终止)
        run_once();
    }
    return 0;
}

// ---------------- 主进程: 父子监督模式, worker 崩溃立即拉起, 永不掉线 ----------------
int main(int argc, char** argv) {
    if (argc > 1 && std::string(argv[1]) == "--worker") return worker_main();   // 子进程: 直接进入工作循环
    // ---- 父进程(监督者): 反复拉起自身 --worker, 子进程异常退出即重启 ----
    log_setfile(LOG_PATH);                      // 监督者也落同一日志(便于看拉起记录)
    logline("bt_timer 监督者启动 (worker 崩溃自动重启)");
    char self[MAX_PATH];                        // 自身完整路径
    GetModuleFileNameA(nullptr, self, MAX_PATH);
    for (;;) {                                  // 监督循环
        STARTUPINFOA si; PROCESS_INFORMATION pi;// 子进程启动信息
        ZeroMemory(&si, sizeof(si)); si.cb = sizeof(si);
        ZeroMemory(&pi, sizeof(pi));
        char cmd[MAX_PATH + 32];                // 命令行: "自身路径" --worker
        snprintf(cmd, sizeof(cmd), "\"%s\" --worker", self);
        if (!CreateProcessA(nullptr, cmd, nullptr, nullptr, FALSE, 0, nullptr, nullptr, &si, &pi)) {
            logline("拉起 worker 失败, 60 秒后重试");   // 拉不起(文件被占用等): 等待重试
            Sleep(60000);
            continue;
        }
        CloseHandle(pi.hThread);                // 线程句柄不需要, 先关
        WaitForSingleObject(pi.hProcess, INFINITE);   // 等子进程退出(正常循环永不退出/崩溃即退出)
        DWORD code = 0; GetExitCodeProcess(pi.hProcess, &code);   // 取退出码(0xC0000005 等)
        CloseHandle(pi.hProcess);
        char eb[96];
        snprintf(eb, sizeof(eb), "worker 退出 code=0x%08lX, 30 秒后重新拉起", (unsigned long)code);
        logline(eb);                            // 留痕
        Sleep(30000);                           // 喘 30 秒(防崩溃循环打爆日志)
    }
    return 0;
}
