/* ==================================================================
 * bt_timer.cpp — AI 模拟交易员回测定时引擎 (每小时一场, 全 C++)
 *
 * 职责:
 *   1) 回填全市场合约 3m/5m/15m K线缺口 (OKX history-candles, 限额分批)
 *   2) 调用本地 llama-cli(qwen2.5-0.5b) 生成 100 个随机交易人格(名字+性格)
 *   3) 全市场合约 × 最近一个月 × 100 交易员自由发挥模拟(0929 用户拍板: 无硬规则):
 *      每人随机杠杆3~20x / 止盈止损自选(可不止损) / 加仓风格自创(跌档或盈利金字塔) /
 *      每场洗牌 16 种原型——8 经典 + 8 自创物理/玄学指标(动能·反作用力·熵·引力·
 *      量子隧穿·热力学温度·牛顿力·易经卦象), 阈值每场随机 → 天马行空不重样
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
static const double FEE      = 0.0005;        // 单边手续费 0.05%(吃单)
static const int   WARMUP    = 210;           // 指标预热: 前 210 根(EMA99 需要)不出信号
static const int   SLOTS     = 3;             // 每人最多同时持有 3 个仓位(跨合约)
static const int   REQ_BUDGET= 500;           // 每轮 OKX 请求预算(防限频, 3m/5m 用轮转补齐)
static const int   KEEP_DAYS = 7;             // 报告保留天数(过期清理)

// ---------------- 交易原型 (8 经典 + 8 自创物理/玄学指标 × 多空) ----------------
enum { A_BOLL=0, A_TREND3=1, A_MOM=2, A_RSI=3, A_DIP=4, A_BRK=5, A_ADAPT=6, A_RANGE=7,
       A_KE=8, A_RX=9, A_EN=10, A_GR=11, A_QT=12, A_TT=13, A_NP=14, A_YX=15, A_N=16 };
static const char* ARCH_NAME[A_N] = {
    "布林回归","三线排列","动量追单","RSI极值","急跌接针","突破追高","变色龙","箱体高抛",
    "动能爆发KE","反作用力F'","熵变有序H","引力回归G","量子隧穿Q","热力温度T","牛顿力F=ma","玄学卦象Y"};

// 5 档风控基线 (每人按档位取后再随机漂移, 不再硬规则): 仓位比例/止盈/止损/最长持有
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
    // ---- 自创物理/玄学指标层(0929 天马行空版, 每场阈值随机) ----
    std::vector<double> atr, vr;              // ATR14 / 量比(vol ÷ 24根均量)
    std::vector<double> fke;                  // 动能 KE = ½mv² ≈ 0.5×量比×(|Δc|/ATR)²
    std::vector<double> fac;                  // 加速度 a = ROC - ROC₋₁(二阶差分)
    std::vector<double> fen;                  // 熵 H = 8根收益滚动标准差(无序度)
    std::vector<double> fgr;                  // 引力场 G = (c-EMA99)/ATR(偏离引力井的距离)
    std::vector<double> fqt;                  // 隧穿位 Q = (c-布林下轨)/(上轨-下轨)(能级 0~1)
    std::vector<double> ftt;                  // 热力学温度 T = ATR% ÷ 其24根均值(市场冷热)
    std::vector<double> fnp;                  // 牛顿力 F = ma = 量比×加速度
    std::vector<double> fyx;                  // 卦象 Y = (step×合约序号) 哈希值(玄学随机门)
    int last = -1;                            // 最后一个有效步下标(-1 = 无数据)
};
struct Ev {                                   // 入场事件(已按 step 排序)
    int step, ci, side;                       // 步号 / 合约下标 / 方向 1多-1空
};
struct Spec {                                 // 交易员人设
    std::string name, temper, archN;          // 名字 / 性格 / 方法名(自创公式带参数戳)
    int arch, dir;                            // 原型 / 方向(1多-1空, 0=自适应双向)
    double size, tp, sl; int hold;            // 仓位比例 / 止盈 / 止损(0=不止损, 自由发挥) / 最长持有
    int lev;                                  // 自选杠杆 3~20x(0929: 不再硬性 10x)
    double addDip;                            // 自创加仓触发幅度(0.3%~3%, 金字塔型为盈利幅度)
    int addCd, addCap;                        // 自定加仓冷却(步) / 加仓次数上限(0=永不加仓)
    bool pyramid;                             // false=逆势跌档加仓 / true=顺势盈利金字塔加码
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
static std::string g_thrNote;                 // 本场自创指标参数戳(build_events 生成, 报告可见)

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

// ---------------- LLM: 给前十生成评语(按名字对齐解析, 不再产感言) ----------------
static void llm_speeches(const std::vector<Spec>& sp, const std::vector<int>& topIdx,
                         const std::vector<Res>& res, std::vector<std::string>& cmt) {
    cmt.assign(topIdx.size(), "");                        // 输出容器(空=走模板兜底)
    const char* TMPF = "E:\\datas\\tmp\\bt_prompt2.txt";
    FILE* f = fopen(TMPF, "wb"); if (!f) return;
    fprintf(f, "你是加密货币模拟交易大赛评委。下面是前十名成绩, 给每人写一行, 格式严格为: 名字|评语(20字内)\n");
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
    while (pos < out.size() && got < (int)topIdx.size()) {   // 逐行解析: 名字|评语 (按名字对齐, 杜绝错位)
        size_t e = out.find('\n', pos); if (e == std::string::npos) e = out.size();
        std::string line = out.substr(pos, e - pos); pos = e + 1;
        size_t b1 = line.find('|'); if (b1 == std::string::npos) continue;
        std::string nm = clean_name(line.substr(0, b1));
        std::string cm = clean_name(line.substr(b1 + 1));
        if (nm.empty() || cm.empty()) continue;
        for (size_t k = 0; k < topIdx.size(); k++)       // 名字对上谁就评谁
            if (cmt[k].empty() && sp[topIdx[k]].name.find(nm) != std::string::npos) { cmt[k] = cm; got++; break; }
    }
    logline("LLM 评语 " + std::to_string(got) + "/10");
}

// ---------------- 大感言: 数据驱动生成 ≥500字/人(保证必存在, 六段式多分析多感悟) ----------------
static std::string rep_str(std::string t, const std::vector<std::pair<std::string, std::string>>& m) {
    for (auto& p : m) { size_t pos; while ((pos = t.find(p.first)) != std::string::npos) t.replace(pos, p.first.size(), p.second); }
    return t;
}
static std::string big_speech(const Spec& s, const Res& r, int rank) {
    auto pct = [](double x) { char b[24]; snprintf(b, sizeof(b), "%.1f", x); return std::string(b); };
    double ret = (r.eq / 1000.0 - 1) * 100.0;
    double winr = r.ntr ? 100.0 * r.win / r.ntr : 0;
    std::vector<std::pair<std::string, std::string>> M = {
        {"%RANK%", std::to_string(rank)}, {"%NAME%", s.name}, {"%ARCH%", s.archN},
        {"%TEMPER%", s.temper}, {"%DIR%", s.dir == 1 ? "做多" : s.dir == -1 ? "做空" : "多空自适应"},
        {"%LEV%", std::to_string(s.lev)}, {"%SIZE%", pct(s.size * 100)},
        {"%TP%", pct(s.tp * 100)}, {"%SLP%", s.sl > 0 ? ("止损 " + pct(s.sl * 100) + "%") : "从不设止损"},
        {"%ADD%", s.pyramid ? "顺势金字塔加仓——赚了才加, 越涨越谨慎, 让利润自己奔跑" : "逆势跌档补仓——越跌越买, 用均价摊薄成本, 等风再把船抬起来"},
        {"%RET%", (ret >= 0 ? "+" : "") + pct(ret)}, {"%NTR%", std::to_string(r.ntr)},
        {"%WIN%", pct(winr)}, {"%DD%", pct(r.dd * 100)}, {"%FEE%", pct(r.fee)},
        {"%LIQ%", std::to_string(r.liqs)}, {"%FIN%", pct(r.eq / 1000.0)} };
    auto pick = [&](const std::vector<std::string>& pool) {   // 每次抽一句(每场不同人不同句)
        return rep_str(pool[g_rng() % pool.size()], M);
    };
    // ① 开场·获奖心情
    std::string sp1 = pick({
        "大家好, 我是%NAME%。拿到本场第%RANK%名, 期末权益%FIN%U、收益%RET%%%, 我第一时间想到的不是庆祝, 而是复盘——市场永远是对的, 赢的只是恰好站对了它那一边。",
        "感谢评委。我以第%RANK%名收官, %RET%%%的成绩放在整场%NTR%笔交易的背景里看, 每一笔都有它的必然: 不是我聪明, 是我的%ARCH%体系在这段行情里找到了自己的生态位。",
        "站上领奖台, 我先给被市场教育过的自己鞠一躬。%RET%%%、期末%FIN%U, 这个数字背后是%NTR%次决策, 每次决策背后是%LEV%倍杠杆下对生死的敬畏。" });
    // ② 方法·入场逻辑
    std::string sp2 = pick({
        "我的方法核心是「%ARCH%」。%DIR%只做一件事: 等指标自己开口说话, 信号不到绝不动手, 信号来了绝不犹豫。很多人亏钱不是因为不懂, 而是因为太懂——懂到忍不住去预测, 而我只跟随。",
        "「%ARCH%」是我的独门武器。%DIR%的每一笔入场都要过三道关: 形态关、动能关、情绪关。三关全过才扣扳机, 缺一就空仓看戏。宁可错过一百次, 不可错进一次。",
        "我信仰「%ARCH%」。市场是概率的海洋, 我不追求每次都对, 只追求对的时候赚足、错的时候亏小。%DIR%的入场上, 我把确定性排第一、赔率排第二、频率排最后。" });
    // ③ 仓位·风控哲学
    std::string sp3 = pick({
        "仓位上我用%SIZE%%仓起步, %LEV%倍杠杆, %ADD%。%SLP%。风控不是止损线上的一个数字, 而是开仓前就想好的退路——先算输, 再算赢。",
        "我的仓位哲学: 活着比赚钱重要一万倍。%SIZE%%底仓、%LEV%倍杠杆, %ADD%。%SLP%。杠杆是放大器, 放大的可以是利润, 更可以是贪婪, 我用仓位把它摁住。",
        "风控是我这场的生命线: %SIZE%%仓、%LEV%倍杠杆、%SLP%。%ADD%。市场专治各种不服, 我唯一不服的是『重仓一把梭』——那是把命运交给抛硬币。" });
    // ④ 数据·自我剖析(按数据定制)
    std::string sp4 = winr >= 55 ?
        pick({ "%WIN%%的胜率、%NTR%笔交易, 数据证明我的入场过滤是有效的。但我不敢飘——胜率高也意味着我可能错过了一些该做的行情, 下一步要研究的是『少而精』和『多而稳』之间的平衡。",
               "复盘数据: %NTR%笔、胜率%WIN%%、最大回撤%DD%%、手续费%FEE%U、强平%LIQ%次。这个成绩单我给自己打八十分——扣掉的二十分在回撤管理, %DD%%的回撤说明浮盈时我还是不够果断落袋。" }) :
        pick({ "胜率只有%WIN%%, %NTR%笔里一大半是小的试探单。但我活着, 还赚了%RET%%——靠的是盈亏比。截断亏损、让利润奔跑这句话, 我用整场做了一次注脚。",
               "数据不会说谎: %NTR%笔、胜率%WIN%%、回撤%DD%%、费用%FEE%U。低胜率高盈亏比是我的画像, 每一次小亏都是在为那一笔大赚交学费, 学费交得起, 就不算亏。" });
    if (r.liqs == 0) sp4 += "全场零强平, 这是我最自豪的数字——可以慢, 但不能死。";
    else sp4 += "强平%LIQ%次是我的耻辱柱, 杠杆的毒我尝过了, 下场一定减。";
    // ⑤ 心态·感悟
    std::string sp5 = pick({
        "我是「%TEMPER%」的性格, 这场我学会了和它共处: 亏损时不报复性开仓, 盈利时不觉得自己是神。情绪是交易者最大的对手盘, K线只是它作案的现场。",
        "「%TEMPER%」的我这一路跌跌撞撞。最大的感悟是: 交易的反义词不是不交易, 是乱交易。空仓也是一种仓位, 等待也是一种进攻。",
        "性格「%TEMPER%」是我的双刃剑。这场我终于明白: 体系会因为性格而变形, 但纪律可以把变形拉回来。每天收盘问自己一句——今天的操作, 是计划内的, 还是情绪的?" });
    // ⑥ 敬畏·寄语
    std::string sp6 = pick({
        "最后想对市场说声谢谢, 也对后辈说一句: 这个市场里, 慢就是快, 少就是多。复利的敌人不是波动, 是急于求成。愿你我都能在下一场行情里, 既赚到钱, 也赚到认知。",
        "市场每天都有新故事, 但老规矩从不变: 趋势是朋友、杠杆是猛兽、本金是命根。我的%RET%%%只是这段行情的馈赠, 把它当成实力是危险的开始。敬畏市场, 才能长期留在牌桌上。",
        "给还在亏钱的朋友一句忠告: 先用小仓位把『不亏大钱』练成肌肉记忆, 再谈赚钱。我这场%FIN%U的权益曲线不是天赋, 是把每一次『差点上头』摁回去的结果。下个赛场, 我们再见。" });
    // 保底段(若六段合计仍偏短, 再补一段通用感悟, 确保 ≥500 字)
    std::string sp = sp1 + "\n" + sp2 + "\n" + sp3 + "\n" + sp4 + "\n" + sp5 + "\n" + sp6;
    if (sp.size() < 500 * 3) sp += "\n补充感悟: 回头看这%NTR%笔交易, 真正决定胜负的往往不是入场那一瞬间的聪明, 而是持仓过程中无数个想平仓又拿住的夜晚、想加仓又忍住的清晨。%ARCH%给了我一双看市场的眼睛, 但让这双眼睛不被恐惧和贪婪蒙住的, 是纪律。市场永远会比你想的更极端, 也永远会比你想的更有耐心——你要做的, 就是比它更能等, 比它更能扛, 比它更敬畏。这一场结束了, 下一场, 依然是新的修行。";
    return sp;
}

// ---------------- 一句优点总结(首页列表标题用, 按数据挑最亮眼处) ----------------
static std::string merit(const Res& r) {
    double ret = (r.eq / 1000.0 - 1) * 100.0;
    double winr = r.ntr ? 100.0 * r.win / r.ntr : 0;
    char b[48];
    if (ret >= 100) return "吃足大趋势·复利滚雪球";
    if (r.dd <= 8) { snprintf(b, sizeof(b), "回撤仅%.1f%%·稳如磐石", r.dd * 100); return b; }
    if (winr >= 60) { snprintf(b, sizeof(b), "胜率%.0f%%·出手即中", winr); return b; }
    if (r.liqs == 0 && r.ntr >= 20) return "全程零强平·风控满分";
    if (r.ntr <= 60) return "少出手多命中·子弹金贵";
    return "纪律在线·盈亏比合理";
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
        // ---- 自创物理/玄学指标层(0929 天马行空版) ----
        p.atr.assign(STEPS,0); p.vr.assign(STEPS,1);
        p.fke.assign(STEPS,0); p.fac.assign(STEPS,0); p.fen.assign(STEPS,0);
        p.fgr.assign(STEPS,0); p.fqt.assign(STEPS,0); p.ftt.assign(STEPS,1);
        p.fnp.assign(STEPS,0); p.fyx.assign(STEPS,0);
        double atrE = 0;                       // ATR 的 24 根指数均值(温度基准)
        unsigned hsh = (unsigned)(std::hash<std::string>{}(p.inst) & 0xFFFF);   // 玄学种子(合约指纹)
        for (int i = 1; i < STEPS; i++) {
            if (c[i] <= 0) continue;
            double tr = p.h[i] - p.l[i];       // 真实波幅(简化=振幅)
            if (i >= 1 && c[i-1] > 0) tr = std::max(tr, fabs(p.h[i]-c[i-1]));
            if (i >= 1 && c[i-1] > 0) tr = std::max(tr, fabs(p.l[i]-c[i-1]));
            p.atr[i] = i == 1 ? tr : p.atr[i-1] * 13 / 14 + tr / 14;    // Wilder ATR14
            if (p.atr[i] <= 0) p.atr[i] = c[i] * 0.001;                 // 防零
            atrE = i == 1 ? p.atr[i] : atrE * 23 / 24 + p.atr[i] / 24;  // 温度基准(EMA24)
            p.ftt[i] = atrE > 0 ? (p.atr[i] / c[i]) / (atrE / c[i]) : 1;   // 热力学温度 T
            double dv = 0, vrS = 0;            // 量比: vol/24根均量(用 o 数组存不了 vol → 用振幅代理波动能量)
            // 注: 加载层未带成交量, 量比改用「振幅比」(T 的倒数扰动) —— m=质量用 T 代理
            dv = p.atr[i] / (atrE > 0 ? atrE : 1);    // 能量代理
            vrS = dv;                          // m 代理
            double v = (c[i-4] > 0 ? fabs(c[i]/c[i-4]-1) : 0) / (p.atr[i]/c[i] * 4 + 1e-12);   // 归一速度
            p.fke[i] = 0.5 * vrS * v * v;      // 动能 KE = ½mv²
            p.fac[i] = (i >= 8 ? p.roc[i] - p.roc[i-4] : 0);            // 加速度(4 根 ROC 差分)
            p.fnp[i] = vrS * p.fac[i] * 100;   // 牛顿力 F = ma(放大到可读量级)
            double s8 = 0, s8v = 0;            // 熵: 8 根对数收益标准差
            int cnt8 = 0;
            for (int j = std::max(1, i-7); j <= i; j++) if (c[j] > 0 && c[j-1] > 0) {
                double rj = log(c[j] / c[j-1]); s8 += rj; s8v += rj*rj; cnt8++;
            }
            if (cnt8 >= 6) { double m8 = s8/cnt8; p.fen[i] = sqrt(std::max(0.0, s8v/cnt8 - m8*m8)); }
            p.fgr[i] = p.e99[i] > 0 ? (c[i] - p.e99[i]) / p.atr[i] : 0;    // 引力场: 距引力井的 ATR 数
            double bw = p.bu[i] - p.bl[i];     // 隧穿位: 布林能级坐标
            p.fqt[i] = bw > 1e-12 ? (c[i] - p.bl[i]) / bw : 0.5;           // 0=下能级 1=上能级
            p.fyx[i] = (double)((hsh + (unsigned)i * 2654435761u) >> 23 & 0xFF) / 255.0;   // 卦象: 黄金比例哈希
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
// 8 种自创原型(动能/反作用力/熵/引力/隧穿/温度/牛顿力/玄学)的阈值每场由 g_rng 洗牌 → 每场不同指标参数
static void build_events(const std::vector<PC>& mkt, std::vector<Ev> ev[A_N][2]) {
    auto rnd = [](double a, double b) {        // 均匀随机 [a,b]
        std::uniform_real_distribution<double> d(a, b); return d(g_rng);
    };
    double thKE = rnd(1.2, 2.6);               // 动能爆发阈值(KE)
    double thRX = rnd(0.01, 0.03);             // 反作用力阈值(加速度)
    double thEN = rnd(0.004, 0.010);           // 熵减阈值(8根收益标准差)
    double thGR = rnd(1.8, 3.2);               // 引力回归阈值(ATR 距离)
    double thQT = rnd(0.96, 1.10);             // 隧穿上能级(布林坐标)
    double thQTl = 1.0 - thQT + 0.06;          // 隧穿下能级(对称)
    double thTTh = rnd(1.9, 2.6);              // 过热温度阈值
    double thTTl = rnd(0.45, 0.75);            // 低温点火阈值
    double thNP = rnd(0.8, 2.2);               // 牛顿力阈值
    double thYX = rnd(0.85, 0.97);             // 玄学吉门概率位
    char thrNote[160];                         // 本场指标参数戳(写入方法名, 报告可见)
    snprintf(thrNote, sizeof(thrNote), "本场阈值 KE>%.1f G|>%.1f Q>%.2f T>%.1f F|>%.1f", thKE, thGR, thQT, thTTh, thNP);
    g_thrNote = thrNote;                       // 存全局供人设名追加
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
            // ---- 自创物理/玄学原型(每场阈值不同) ----
            cond[A_KE][0] = p.fke[i] > thKE && p.roc[i] > 0;    // 动能爆发且方向向上 → 多
            cond[A_KE][1] = p.fke[i] > thKE && p.roc[i] < 0;    // 动能爆发且方向向下 → 空
            cond[A_RX][0] = p.roc[i] < -thRX && p.fac[i] > 0 && p.c[i] < p.e25[i];   // 下跌中反作用力转正(反弹应力) → 多
            cond[A_RX][1] = p.roc[i] >  thRX && p.fac[i] < 0 && p.c[i] > p.e25[i];   // 上涨中反作用力转负(回落应力) → 空
            cond[A_EN][0] = p.fen[i] < thEN && p.roc[i] > 0;    // 熵减(秩序涌现)且方向向上 → 多
            cond[A_EN][1] = p.fen[i] < thEN && p.roc[i] < 0;    // 熵减且方向向下 → 空
            cond[A_GR][0] = p.fgr[i] < -thGR && p.c[i] > 0;     // 深入引力井下 → 被引力拉回 → 多
            cond[A_GR][1] = p.fgr[i] >  thGR && p.c[i] > 0;     // 远离引力井上空 → 被引力拉回 → 空
            cond[A_QT][0] = p.fqt[i] > thQT && p.c[i] > p.e25[i];   // 隧穿上能级(势垒突破) → 多
            cond[A_QT][1] = p.fqt[i] < thQTl && p.c[i] < p.e25[i];  // 跌穿下能级 → 空
            cond[A_TT][0] = p.ftt[i] < thTTl && p.roc[i] > 0;   // 低温点火(冷缩后升温) → 多
            cond[A_TT][1] = p.ftt[i] > thTTh;                   // 过热必冷却 → 空
            cond[A_NP][0] = p.fnp[i] >  thNP && p.c[i] > p.e25[i];  // 牛顿力正向(质量×加速度) → 多
            cond[A_NP][1] = p.fnp[i] < -thNP && p.c[i] < p.e25[i];  // 牛顿力负向 → 空
            cond[A_YX][0] = p.fyx[i] > thYX && p.e25[i] > p.e25[i-8];   // 卦象吉门+中期向上 → 多
            cond[A_YX][1] = p.fyx[i] < (1.0 - thYX) && p.e25[i] < p.e25[i-8];   // 凶门+中期向下 → 空
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
    auto rnd = [](double a, double b) {        // 均匀随机 [a,b]
        std::uniform_real_distribution<double> d(a, b); return d(g_rng);
    };
    auto rndi = [](int a, int b) {             // 均匀随机整数 [a,b]
        std::uniform_int_distribution<int> d(a, b); return d(g_rng);
    };
    // ---- 名人专家席位: 每场洗牌抽 EXP_SLOTS 位(不同场次名人阵容不同) ----
    std::vector<int> expIdx(EXP_N);            // 名人下标池
    for (int i = 0; i < EXP_N; i++) expIdx[i] = i;
    std::shuffle(expIdx.begin(), expIdx.end(), g_rng);           // 每场随机洗牌
    std::vector<int> expertSeats(100, -1);     // 座位→名人下标映射(默认 -1 = 普通席位)
    for (int i = 0; i < EXP_SLOTS; i++) expertSeats[i] = expIdx[i % EXP_N];   // 前 28 席给名人(可重复轮补)
    std::shuffle(expertSeats.begin(), expertSeats.end(), g_rng); // 名人座位也洗牌(位置不固定)
    std::vector<int> dirMap(100);              // 多空分配洗牌(多空各半)
    for (int i = 0; i < 100; i++) dirMap[i] = (i % 2) ? -1 : 1;
    std::shuffle(dirMap.begin(), dirMap.end(), g_rng);
    std::vector<int> archPool(100);            // 16 种原型随机分配(物理/玄学占一半席位)
    for (int i = 0; i < 100; i++) archPool[i] = i % A_N;
    std::shuffle(archPool.begin(), archPool.end(), g_rng);
    char nb[16];
    for (int k = 0; k < 100; k++) {            // 逐人生成
        Spec s;                                // 自由发挥人设
        s.arch = archPool[k];                  // 随机原型(16 选 1, 每场洗牌)
        s.dir = dirMap[k];                     // 洗牌后的多空
        if (s.arch == A_ADAPT) s.dir = 0;      // 变色龙 = 双向自适应
        s.size = rnd(0.04, 0.30);              // 仓位 4%~30% 自选
        s.tp = rnd(0.015, 0.08);               // 止盈 1.5%~8% 自选
        s.sl = rnd(0, 1) < 0.25 ? 0.0 : rnd(0.008, 0.04);   // 25% 的胆大派不止损, 其余 0.8%~4%
        s.hold = rndi(96, 1600);               // 最长持有 1 天~16 天(允许死扛, 天赋自由)
        s.lev = rndi(3, 20);                   // 自选杠杆 3~20x
        s.addDip = rnd(0.003, 0.03);           // 自创加仓触发幅度 0.3%~3%
        s.addCd = rndi(1, 12);                 // 自定冷却 15 分钟~3 小时
        s.addCap = rnd(0, 1) < 0.2 ? 0 : rndi(1, 6);        // 20% 永不加仓, 其余 1~6 次
        s.pyramid = rnd(0, 1) < 0.35;          // 35% 顺势金字塔派(盈利加码), 其余逆势跌档派
        int tp;                                // 性格: 优先 LLM, 缺失按序取
        if (k < (int)llmTemps.size()) tp = llmTemps[k]; else tp = (k * 7 + 3) % 8;
        s.temper = TEMPER_NAME[tp];
        s.name = k < (int)llmNames.size() ? llmNames[k] : (snprintf(nb, sizeof(nb), "员%02d", k+1), std::string(nb));
        // 方法名 = 原型 + 自创参数戳(公式参数随机 → 每场每个"新指标"都不重样)
        char ab[220];
        if (s.arch == A_KE)  snprintf(ab, sizeof(ab), "动能爆发KE·½mv²·杠杆%dx", s.lev);
        else if (s.arch == A_RX)  snprintf(ab, sizeof(ab), "反作用力F'·回撤%.1f%%·杠杆%dx", s.addDip*100, s.lev);
        else if (s.arch == A_EN)  snprintf(ab, sizeof(ab), "熵变有序H·秩序门·杠杆%dx", s.lev);
        else if (s.arch == A_GR)  snprintf(ab, sizeof(ab), "引力回归G·深井%.1fATR·杠杆%dx", s.addDip*33, s.lev);
        else if (s.arch == A_QT)  snprintf(ab, sizeof(ab), "量子隧穿Q·能级跃迁·杠杆%dx", s.lev);
        else if (s.arch == A_TT)  snprintf(ab, sizeof(ab), "热力学T·冷热循环·杠杆%dx", s.lev);
        else if (s.arch == A_NP)  snprintf(ab, sizeof(ab), "牛顿力F=ma·质量×加速度·杠杆%dx", s.lev);
        else if (s.arch == A_YX)  snprintf(ab, sizeof(ab), "玄学卦象Y·吉位%.0f%%·杠杆%dx", 90.0, s.lev);
        else snprintf(ab, sizeof(ab), "%s·杠杆%dx·%s", ARCH_NAME[s.arch], s.lev, s.pyramid ? "金字塔" : "跌档");
        s.archN = ab;
        if (expertSeats[k] >= 0) {             // 名人专家席位: 覆盖人设为大师配置
            const Exp& e = EXP[expertSeats[k]];
            s.name = e.name; s.arch = e.arch; s.dir = e.dir;
            s.size = rnd(0.05, 0.25); s.tp = rnd(0.02, 0.06); s.sl = rnd(0.008, 0.03);
            s.hold = rndi(192, 960); s.lev = rndi(5, 15);
            s.addDip = rnd(0.005, 0.02); s.addCd = rndi(1, 8); s.addCap = rndi(1, 4);
            s.pyramid = e.arch == A_TREND3;    // 趋势派顺势加码, 其余逆势补
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
            // 加仓判定(自由发挥版, 0929): 每人自带加仓哲学 ——
            //   逆势跌档派: 逆向偏离上次加仓价 ≥ addDip 且冷却满 → 补仓摊平
            //   顺势金字塔派: 顺向偏离上次加仓价 ≥ addDip 且冷却满 → 盈利加码(让利润奔跑)
            //   addCap=0 永不加仓; 加到上限后不再加(不强制平仓, 自由发挥)
            if (p.adds < s.addCap && step - p.lastAddStep >= s.addCd) {
                double dev = p.side * (lo - p.lastAddPx) / p.lastAddPx;   // 逆向偏离度(多仓用低点)
                double devP = p.side * (hi - p.lastAddPx) / p.lastAddPx;  // 顺向偏离度(多仓用高点)
                bool hit = s.pyramid ? (devP >= s.addDip) : (dev >= s.addDip);   // 各派触发
                if (hit) {
                    double fillPx = s.pyramid ? hi : lo;                   // 成交价(顺向/逆向极值)
                    double addM = p.margin * (0.3 + 0.2 * (p.adds % 2));   // 加仓保证金 30%/50% 交替(自创节奏)
                    double addQ = addM * s.lev / fillPx;                   // 加仓数量
                    double feeIn = addM * s.lev * FEE;                     // 加仓手续费
                    eq -= addM + feeIn; r.fee += feeIn;                    // 扣款
                    p.avg = (p.avg * p.qty + fillPx * addQ) / (p.qty + addQ);   // 重算均价
                    p.qty += addQ; p.margin += addM;                       // 并仓
                    p.lastAddPx = fillPx; p.lastAddStep = step; p.adds++; r.adds++;
                    addTrade(t0 + (long long)step * STEP_MS, p.ci,
                             s.pyramid ? (p.side > 0 ? "金字塔加码·多" : "金字塔加码·空")
                                       : (p.side > 0 ? "跌档补仓·多" : "涨档补仓·空"), fillPx, 0, 0);
                }
            }
            // 出场判定(自由发挥): 强平线随自选杠杆(1/lev 留 5% 缓冲); sl=0 的胆大派无止损; hold 到期才走
            double liqMove = 1.0 / s.lev * 0.95;                           // 强平距离 = 1/杠杆×95%
            double slPx = p.avg * (1 - p.side * s.sl);                     // 止损价(随均价; sl=0 时与均价重合不触发)
            double liqPx = p.avg * (1 - p.side * liqMove);                 // 强平价
            if ((p.side > 0 && lo <= liqPx) || (p.side < 0 && hi >= liqPx)) {   // 强平(最优先)
                r.liqs++;
                close_pos(p, step, liqPx, "保证金强平");
                continue;
            }
            if (s.sl > 0 && ((p.side > 0 && lo <= slPx) || (p.side < 0 && hi >= slPx))) {    // 止损(有止损的人才触发)
                close_pos(p, step, slPx, "止损离场");
                continue;
            }
            double tpPx = p.avg * (1 + p.side * s.tp);                     // 止盈价(随均价)
            if ((p.side > 0 && hi >= tpPx) || (p.side < 0 && lo <= tpPx)) {
                close_pos(p, step, tpPx, "止盈落袋");
                continue;
            }
            if (step - p.openStep >= s.hold) {                             // 自选最长持有到期
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
                double qty = mg * s.lev / px;                 // 数量(自选杠杆)
                double feeIn = mg * s.lev * FEE;              // 开仓手续费
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
              ",\"liq\":" + std::to_string(r.liqs) + ",\"f3\":" + std::to_string(r.adds) +
              ",\"fee\":" + jnum(r.fee) + ",\"lp\":" + jnum(r.lp) + ",\"sp\":" + jnum(r.sp) + ",\"cv\":[";
        for (size_t j = 0; j < r.curve.size(); j++) { if (j) sj += ","; sj += jnum(r.curve[j]); }
        sj += "]}";
    }
    sj += "]}";
    // ---- top10_json: 前十详细(评语/感言/成交明细) ----
    std::vector<std::string> cmt;
    std::vector<int> topIdx(order.begin(), order.begin() + std::min<size_t>(10, order.size()));
    llm_speeches(sp, topIdx, res, cmt);
    static const char* CMT_TPL[8] = {"方法纪律在线，盈亏比合理","风格激进，靠趋势吃饭","出手太频，被手续费蚕食","亏后报复开仓是最大漏洞","节奏混乱，需要系统化","稳字当头，牺牲弹性换生存","赌性坚强，命运大起大落","纪律执行满分，值得实盘借鉴"};
    std::string tj = "{\"top\":[";
    for (size_t k = 0; k < topIdx.size(); k++) {
        int i = topIdx[k]; const Spec& s = sp[i]; const Res& r = res[i];
        if (k) tj += ",";
        std::string cm = cmt[k].empty() ? std::string(CMT_TPL[s.arch % 8]) : cmt[k];     // 评语(LLM 兜底模板)
        std::string sp2 = big_speech(s, r, (int)k + 1);                                  // 大感言(数据驱动 ≥500字, 必存在)
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
    // ---- 一句话简介(多王/空王各带一句优点总结) ----
    std::string mL = bestL >= 0 ? merit(res[bestL]) : "";
    std::string mS = bestS >= 0 ? merit(res[bestS]) : "";
    char brief[256];
    snprintf(brief, sizeof(brief), "多王%s(%s) %+.1f%%·%s | 空王%s %+.1f%%·%s | %d人×%d币·%d笔",
             bestL >= 0 ? sp[bestL].name.c_str() : "-", bestL >= 0 ? sp[bestL].archN.c_str() : "-",
             bestL >= 0 ? (res[bestL].eq/1000.0-1)*100 : 0, mL.c_str(),
             bestS >= 0 ? sp[bestS].name.c_str() : "-", bestS >= 0 ? (res[bestS].eq/1000.0-1)*100 : 0,
             mS.c_str(),
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
