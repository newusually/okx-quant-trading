/* ==================================================================
 * datahub_main.cpp — OKX 数据中枢主程序 (拆分自 datahub.cpp, 逻辑零改动)
 *
 * 【文件职责】
 *   datahub.exe 主程序：计划任务 finally_fill1m 常驻拉起，WinMain 入口 +
 *   单一 HUB 主循环（15 秒节拍）。每轮构建分级任务队列并由 4 个工作线程
 *   并发消化，再执行 ETH 实时行情与指标重算，最后写心跳文件并自适应调
 *   整限流间隔 g_minMs（120~300ms）。
 *
 * 【分级任务队列】
 *   A0 热合约（近48h有成交，≤30个）× 1m/3m/5m/15m —— 用户正在交易，最实时
 *   A1 池内 251 个 × 3m/5m/15m —— 交易只看这三周期（金▲共振）
 *   B  池外 226 个 × 5m/15m，按 ii%4==tick%4 切片轮转防头部霸占
 *   B2 池内 × 1h/4h —— 大周期低优先；池内 1m 已移除（1m 只做热合约）
 *   队列最终按 新鲜:追平 = 2:1 交错排列
 *
 * 【函数清单】
 *   rebuild_ind  : (static) 重建 okx_ind_{tf} 尾部 MACD(12,26,60) 指标
 *   eth_live     : (static) T1—每15秒拉 ETH 全周期 live bar + 指标重算
 *   task_worker  : (static) 工作线程：从任务队列取任务执行（预算控制）
 *   hub_loop     : (static) HUB 主循环：构建分级队列/并发抓取/心跳/自适应
 *   crash_handler: (static) 未处理异常过滤器（崩溃后等计划任务拉起）
 *   another_instance_running : (static) 跨会话单实例硬检查
 *   WinMain      : 程序入口：单实例互斥、DB 就绪等待、启动 hub_loop 线程
 * ================================================================== */
#include "datahub.h"                           // datahub 内部接口(fetch组件)与公共组件
#include <tlhelp32.h>                          // 进程快照API: 单实例检查用
#include <cstdio>                              // FILE/snprintf/fopen: 心跳文件
#include <cstring>                             // C字符串工具
#include <ctime>                               // time(nullptr): 当前时间戳
#include <map>                                 // std::map: ETH live 的游标与进度缓存
#include <thread>                              // std::thread: 4工作线程并发抓取

// ---------------- T1: ETH 实时 (替代 okx_realtime.php) ----------------
struct TFdef { const char* bar; int ivSec; int win; };          // 周期定义: bar名/周期秒数/每轮目标窗口根数
static const TFdef RT_BARS[] = {                               // ETH实时覆盖的周期清单(5个)
    {"1m", 60, 70}, {"3m", 180, 40}, {"5m", 300, 30}, {"15m", 900, 20}, {"1H", 3600, 12},  // 注意: 小时必须大写"1H", 小写OKX静默取不到数据
};
static const char* RT_INST = "ETH-USDT-SWAP";                  // T1实时行情固定跟踪的合约: ETH永续
static std::map<std::string, long long> g_lastMax;             // 各周期上次已重算到的最新bar时间(避免重复重算)
static std::mutex g_rtMtx;                                     // 保护 g_lastMax 的互斥锁

// 增量重算 okx_ind_{tf} 尾部MACD(12,26,60): 取尾部2400根算EMA链, 只REPLACE尾部1300根
static int rebuild_ind(const std::string& tf, const std::string& tbl) { // tf=周期名, tbl=对应K线表
    RowSet rs = db_q("SELECT candle_time,c FROM " + tbl + " WHERE c>0 ORDER BY candle_time DESC LIMIT 2400"); // 按时间倒序取最新2400根收盘价
    if (!rs.ok || rs.rows.size() < 60) return 0;   // 查询失败或数据不足60根(EMA预热不够), 不算
    std::vector<std::pair<long long, double>> src; // 源数据: (bar时间ms, 收盘价) 升序排列
    for (auto it = rs.rows.rbegin(); it != rs.rows.rend(); ++it)   // 逆序迭代: 把倒序结果转成正序
        src.push_back({atoll((*it)[0].c_str()), atof((*it)[1].c_str())});   // 逐根转数值存入
    long long writeFrom = src[src.size() > 1300 ? src.size() - 1300 : 0].first;  // 只写尾部1300根: 记录写入起始bar时间
    double e12 = 0, e26 = 0, dea = 0;              // EMA12/EMA26/DEA 三条递推序列
    std::string vals;                              // 攒批的REPLACE VALUES片段
    int w = 0, n = 0;                              // w=累计写入行数, n=已递推根数(攒批计数)
    auto flush = [&]() {                           // 局部函数: 把攒好的VALUES批量写库
        if (vals.empty()) return;                  // 没有攒批内容直接返回
        std::string sql = "REPLACE INTO okx_ind_" + tf +   // 拼REPLACE语句(主键冲突即覆盖)
            " (candle_time,dif,dea,macd,e12,e26,score) VALUES " + vals;   // 指标表列: 时间+MACD各值+评分(此处置NULL)
        if (!db_ex("START TRANSACTION")) return;   // 开事务失败(连接断)则放弃本批
        if (db_ex(sql)) db_ex("COMMIT"); else db_ex("ROLLBACK");   // 执行成功提交, 失败回滚
        w += (int)std::count(vals.begin(), vals.end(), '(');       // 按'('个数统计本批写入行数
        vals.clear();                              // 清空攒批缓冲
    };
    for (size_t i = 0; i < src.size(); i++) {      // 正序逐根递推EMA链
        double c = src[i].second;                  // 当前bar收盘价
        e12 = (i == 0) ? c : c * 2.0 / 13.0 + e12 * 11.0 / 13.0;   // EMA12递推: 首根直接取收盘价
        e26 = (i == 0) ? c : c * 2.0 / 27.0 + e26 * 25.0 / 27.0;   // EMA26递推
        double dif = e12 - e26;                    // DIF = 快线-慢线
        dea = (i == 0) ? dif : dif * 2.0 / 61.0 + dea * 59.0 / 61.0; // DEA(DIF的9期EMA≈2/61递推)
        n++;                                       // 递推计数+1(用于攒批节流)
        if (src[i].first >= writeFrom) {           // 只有尾部1300根内的bar才写入
            char buf[256];                         // 单行VALUES片段缓冲
            snprintf(buf, sizeof(buf), "(%lld,%.12g,%.12g,%.12g,%.12g,%.12g,NULL)",   // 拼(时间,dif,dea,macd,e12,e26,score)
                     src[i].first, dif, dea, 2 * (dif - dea), e12, e26);   // macd=2*(dif-dea), score暂为NULL
            if (!vals.empty()) vals += ",";        // 片段间补逗号
            vals += buf;                           // 追加本行片段
            if (n % 200 == 0) flush();             // 每200根批量写库一次, 减少DB交互
        }
    }
    flush();                                       // 把最后不足200根的余量写库
    return w;                                      // 返回实际写入行数
}

// ---------------- ETH live: 每15秒全周期 live bar + MACD指标重算 ----------------
static void eth_live() {                           // T1: 实时跟踪ETH各周期最新K线(含未收盘live bar)
    long long nowS = time(nullptr);                // 当前秒级时间戳
    for (auto& d : RT_BARS) {                      // 逐周期处理(1m/3m/5m/15m/1H)
        std::string tf = d.bar, tfl;               // tf=OKX周期名, tfl=全小写形式(用于表名后缀)
        for (char c : tf) tfl += (c >= 'A' && c <= 'Z') ? c + 32 : c;  // 逐字符转小写
        std::string tbl = ktable(RT_INST, tf);     // K线表名: kline_ETH-USDT-SWAP_{tf}
        std::string itbl = "okx_ind_" + tfl;       // 对应指标表名: okx_ind_{tf}
        long long after = nowS * 1000 + (long long)d.ivSec * 1000;   // 翻页游标: 当前bar起点之后(OKX after=取比游标更新的数据)
        std::map<long long, std::vector<std::string>> seen;          // 已收集的bar: 时间→原始字段行(自动按时间升序去重)
        while (true) {                             // 翻页循环直到取够窗口或无新数据
            char path[256];                        // API路径缓冲
            snprintf(path, sizeof(path),           // 拼K线接口路径: after翻页向"更新"方向
                "/api/v5/market/candles?instId=%s&bar=%s&limit=100&after=%lld",
                RT_INST, tf.c_str(), after);       // 单页100根
            std::string body;                      // 响应体缓冲
            // datahub 专属限频HTTP在 fetch 组件内(匀速排队+429退避), ETH live 走同一队列
            if (!dl_http_get(path, body)) break;   // 请求失败(限频/网络)则终止本周期本轮
            auto data = okx_parse_candles(body);   // 解析K线JSON为数组(每行9字段: ts,o,h,l,c,vol,...)
            if (data.empty()) break;               // 无新数据: 翻页结束
            for (auto& x : data) seen[atoll(x[0].c_str())] = x;   // 逐根按时间戳收入seen(map去重)
            after = atoll(data[data.size() - 1][0].c_str());      // 游标推进到本页最后一根的时间
            if ((int)seen.size() >= d.win || (int)data.size() < 100) break;   // 凑够窗口根数或不足一整页: 结束翻页
        }
        int nw = 0;                                // 本周期实际写入K线根数
        long long mx = 0;                          // 本周期见到的最新bar时间
        for (auto& kv : seen) {                    // 升序逐根写库
            auto& x = kv.second;                   // 该bar的原始字段行
            char sql[512];                         // REPLACE语句缓冲
            snprintf(sql, sizeof(sql),             // 拼REPLACE: K线表列名固定为 o/h/l/c/vol
                "REPLACE INTO %s (candle_time,o,h,l,c,vol,vol_ccy,vol_quote,confirm)"
                " VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                tbl.c_str(), x[0].c_str(), x[1].c_str(), x[2].c_str(), x[3].c_str(),   // 时间/开/高/低/收
                x[4].c_str(), x[5].c_str(), x[6].c_str(), x[7].c_str(), x[8].c_str()); // 成交量/币量/ Quote量/confirm标志
            if (numok(x[0]) && numok(x[4]) && db_ex(sql)) nw++;   // 时间与收盘价均为合法数字才写库, 成功计数
            if (kv.first > mx) mx = kv.first;      // 记录最新bar时间
        }
        std::lock_guard<std::mutex> lk(g_rtMtx);   // 进入 g_lastMax 保护区
        if (!g_lastMax.count(tfl)) {               // 该周期首次运行: 先从DB初始化上次进度
            bool ok;                               // 查询成功标志
            std::string v = db_scalar("SELECT MAX(candle_time) FROM " + itbl, ok);   // 查指标表最新时间
            g_lastMax[tfl] = ok ? atoll(v.c_str()) : 0;   // 初始化进度缓存
        }
        if (mx > 0 && (nw > 0 || mx > g_lastMax[tfl])) {   // 有新K线或进度前进了才重算指标
            int w = rebuild_ind(tfl, tbl);         // 重建该周期尾部MACD指标
            g_lastMax[tfl] = mx;                   // 更新进度为本次最新bar
            if (w) {                               // 有写入才记日志
                char lg[128];                      // 日志缓冲
                snprintf(lg, sizeof(lg), "%s K线+%d 指标重算%d行", tfl.c_str(), nw, w);   // 如"5m K线+3 指标重算1300行"
                logline(lg);                       // 写日志
            }
        }
        prog_set(RT_INST, tf, mx);                 // 把ETH进度同步到全局进度缓存(供hub_loop判新鲜)
        Sleep(60);                                 // 周期间礼让60ms(限流主责在rate_wait)
    }
}

// ---------------- HUB 主循环 (T1+T2 合并, 15秒节拍, 4线程并发抓取) ----------------
struct Task { std::string inst; std::string tf; int limit; bool catchup; };   // 任务项: 合约/周期/单页根数/是否追平
static std::vector<Task> g_tasks;                  // 本轮任务队列(工作线程共享)
static size_t g_taskIdx = 0;                       // 队列消费游标(原子性由 g_taskMtx 保护)
static std::mutex g_taskMtx;                       // 保护任务队列/游标的互斥锁
static std::atomic<int> g_budget(0);      // 新鲜任务预算
static std::atomic<int> g_cbudget(0);     // 追平任务独立预算(防被新鲜任务饿死)
static std::atomic<int> g_catchups(0);             // 本轮已执行的追平任务数(上限80防过载)
static std::atomic<int> g_fok(0), g_ffail(0), g_cok(0), g_cfail(0);   // 诊断计数

static void task_worker() {                        // 工作线程主体: 4个线程并发消化任务队列
    while (true) {                                 // 循环取任务直到队列耗尽
        Task t;                                    // 当前任务
        {                                          // --- 临界区: 取下一个任务 ---
            std::lock_guard<std::mutex> lk(g_taskMtx);   // 加锁保护队列与游标
            if (g_taskIdx >= g_tasks.size()) return;     // 队列已空: 线程退出
            t = g_tasks[g_taskIdx++];              // 取出任务并推进游标
        }                                          // --- 出临界区(执行任务不持锁) ---
        if (t.catchup) {                           // 追平任务: 历史缺口较大
            if (g_cbudget.fetch_sub(1) <= 0) continue;      // 追平独立预算
            if (g_catchups.fetch_add(1) >= 80) continue;    // 本轮追平超80个: 跳过防限频过载
            long long r = fill_forward(t.inst, t.tf);       // 执行追平: 从库里最新bar补到当前
            if (r > 0) g_cok.fetch_add(1); else g_cfail.fetch_add(1);   // 按结果累计成功/失败
        } else {                                   // 新鲜任务: 只差最新1-3根
            if (g_budget.fetch_sub(1) <= 0) continue;       // 新鲜任务预算
            int w = tail_fetch(t.inst, t.tf, t.limit);      // 增量拉最新limit根
            if (w > 0) g_fok.fetch_add(1);         // 成功计数
            else { g_ffail.fetch_add(1); logline("新鲜取数失败 " + t.inst + " " + t.tf + " w=" + std::to_string(w)); }   // 失败记日志
        }
        Sleep(30); // 礼让
    }
}

static void hub_loop() {                           // HUB主循环: 15秒一拍, 构建分级队列→并发消化→ETH实时→心跳→自适应
    logline("datahub HUB 启动 (合并T1+T2, 15s节拍, 4线程并发, 全市场477×6实时)");   // 启动横幅
    std::vector<std::string> pool = pool_insts(), all = all_insts(), hot = hot_insts();   // 初始加载: 交易池/全市场/热合约三张列表
    {                                              // 打印列表规模概览
        char lg[160];                              // 日志缓冲
        snprintf(lg, sizeof(lg), "交易池=%d 全市场=%d 热合约=%d", (int)pool.size(), (int)all.size(), (int)hot.size());   // 如"交易池=251 全市场=477 热合约=12"
        logline(lg);                               // 写日志
    }
    std::map<std::string, bool> inPool;            // 池内合约快速判定表
    for (auto& s : pool) inPool[s] = true;         // 逐个登记
    int tick = 0;                                  // 循环轮次计数(切片轮转与刷新用)
    int lastMin = -1;                              // 上轮所在的分钟序号(判新分钟用)
    while (true) {                                 // 主循环永不退出(靠计划任务守护)
        long long t0 = GetTickCount64();           // 本轮开始时刻(算耗时与15秒节拍)
        try {                                      // 整轮try包裹: 任何异常不杀死主循环
            if (++tick % 40 == 0 || pool.empty() || all.empty()) { // 约每10分钟刷新
                pool = pool_insts();               // 重读交易池
                all = all_insts();                 // 重读全市场
                hot = hot_insts();                 // 重读热合约
                inPool.clear();                    // 清空并重建池判定表
                for (auto& s : pool) inPool[s] = true;   // 逐个登记
                char lg[160];                      // 日志缓冲
                snprintf(lg, sizeof(lg), "刷新列表: 池=%d 全市场=%d 热=%d", (int)pool.size(), (int)all.size(), (int)hot.size());   // 刷新概览
                logline(lg);                       // 写日志
            }
            long long nowS = time(nullptr);        // 本轮时刻(秒)
            g_budget = 130;              // 新鲜任务预算
            g_cbudget = 90;              // 追平独立预算
            g_catchups = 0;                        // 追平计数清零
            int minuteNow = (int)(nowS / 60);      // 当前分钟序号
            bool newMin = (minuteNow != lastMin);  // 是否跨入新的一分钟
            if (newMin) lastMin = minuteNow;       // 记录(预留新分钟逻辑)
            g_tasks.clear();                       // 清空上一轮任务队列
            g_taskIdx = 0;                         // 消费游标归零
            std::vector<Task> catchupQ;   // 积压追平

            // ===== 任务队列: 按价值分级 + 级别内部公平 =====
            //   A0 热合约(近48h有成交, ≤30个) × 1m/3m/5m/15m  ← 用户正在看图/正在交易的, 必须最实时
            //   A1 池内 × 3m/5m/15m                            ← 交易只看这三个周期(金▲共振), 最高优先
            //   B  池外 × 5m/15m (按tick切片1/4)               ← 切片轮转, 保证每个合约都被轮到
            //   B2 池内 × 1h/4h                                ← 大周期低优先
            // 【池内1m 已移除】1m只做热合约
            auto push_task = [&](const std::string& inst, const char* tf) {   // 生成单任务: 已新鲜则跳过, 落后多则归追平队列
                int bs = bar_sec(tf);              // 该周期秒数
                long long closed = (nowS / bs) * bs - bs;   // 最近一根"已收盘"bar的起点时间(秒)
                long long mx = prog_get(inst, tf); // 该(合约,周期)库里最新bar时间(ms, 带内存缓存)
                if (mx >= closed * 1000) return;   // 已含最新收盘bar: 数据新鲜, 无需任务
                bool cu = mx < (closed - 3LL * bs) * 1000;  // 落后超过3根bar: 视为积压需追平
                if (cu) catchupQ.push_back({inst, tf, 3, true});   // 入追平队列
                else g_tasks.push_back({inst, tf, 3, false});      // 否则入新鲜队列(主流)
            };
            static const char* HOT_TFS[] = {"1m", "3m", "5m", "15m"};   // A0级热合约的周期组合
            for (auto& inst : hot)                 // 遍历热合约(≤30个)
                for (auto* tf : HOT_TFS) push_task(inst, tf);   // A0: 1m/3m/5m/15m 全生成
            static const char* POOL_TFS[] = {"3m", "5m", "15m"};   // A1级池内的周期组合(1m已移除)
            for (auto& inst : pool)                // 遍历交易池(约251个)
                for (auto* tf : POOL_TFS) push_task(inst, tf);   // A1: 3m/5m/15m
            {                                      // --- B级: 池外全市场, 切片轮转 ---
                size_t slot = (size_t)(tick % 4);       // 1/4 合约/tick → 60秒全覆盖(5m/15m足够)
                for (size_t ii = 0; ii < all.size(); ii++) {    // 遍历全市场
                    const std::string& inst = all[ii];  // 当前合约
                    if (inPool[inst]) continue;         // 池内合约已由A1覆盖: 跳过
                    if (ii % 4 != slot) continue;       // 不在本tick切片(ii%4==tick%4): 跳过, 下轮轮到
                    push_task(inst, "5m");              // B级: 池外只做5m
                    push_task(inst, "15m");             // B级: 池外只做15m
                }
            }
            for (auto& inst : pool) {              // B2级: 池内大周期低优先
                push_task(inst, "1h");             // 1小时线(OKX参数须写1H大写, 由okx_bar转换)
                push_task(inst, "4h");             // 4小时线
            }
            // D. 新鲜/追平 2:1 交错(池外追平欠账清不掉的历史修复)
            {                                      // --- 队列交错: 防追平欠账永远排在后面 ---
                std::vector<Task> fresh;           // 暂存新鲜队列
                fresh.swap(g_tasks);               // 把已生成的新鲜任务挪到fresh
                size_t fi = 0, ci = 0;             // 两个游标
                while (fi < fresh.size() || ci < catchupQ.size()) {   // 交替拼接直到两队都耗尽
                    for (int k = 0; k < 2 && fi < fresh.size(); k++) g_tasks.push_back(fresh[fi++]);   // 每2个新鲜任务…
                    if (ci < catchupQ.size()) g_tasks.push_back(catchupQ[ci++]);   // …后插1个追平任务(2:1交错)
                }
                if (tick % 4 == 0) {               // 每4轮打印一次队列统计
                    char lg[128];                  // 日志缓冲
                    snprintf(lg, sizeof(lg), "队列: 新鲜%d 追平%d 合计%d", (int)fresh.size(), (int)catchupQ.size(), (int)g_tasks.size());   // 队列规模
                    logline(lg);                   // 写日志
                }
            }

            // 4 线程并发消化任务队列
            {
                std::vector<std::thread> ws;       // 工作线程容器
                for (int i = 0; i < 4; i++) ws.emplace_back(task_worker);   // 启动4个工作线程
                for (auto& w : ws) w.join();       // 等全部线程消化完队列
            }
            // ETH live 全周期 + 指标重算 (每15秒, 5请求, 主线程)
            eth_live();                            // T1: ETH五周期实时K线+MACD重算(主线程串行)
            // 每轮写心跳文件(供 guard.exe 探活, 日志不保证每轮都有输出)
            {
                FILE* hf = fopen("E:\\datas\\log\\hb_datahub.txt", "w");   // 覆盖写心跳文件
                if (hf) { fprintf(hf, "%lld\n", (long long)time(nullptr)); fclose(hf); }   // 写当前秒级时间戳并关闭
            }
            // 自适应节流: 本轮429多 → 放慢; 长时间无429 → 逐步加速
            int n429 = g_429count.exchange(0);     // 取走本轮429/50011计数并清零
            if (n429 > 0) {                        // 本轮有限频: 放慢限流
                int nm = g_minMs.load() + 15 * (n429 > 3 ? 3 : 1);   // 多次限频(+45ms)或单次(+15ms)
                if (nm > 300) nm = 300;            // 上限300ms
                g_minMs = nm;                      // 应用新间隔
            } else if (g_minMs.load() > 120) {     // 无限频且当前慢于下限: 逐步加速
                int nm = g_minMs.load() - 5;       // 每轮提速5ms
                g_minMs = nm < 120 ? 120 : nm;     // 下限120ms
            }
            if (tick % 4 == 0) {                   // 每4轮(约1分钟)打印一次运行统计
                char lg[256];                      // 日志缓冲
                snprintf(lg, sizeof(lg),           // 汇总本轮任务/成败/节流/耗时
                         "tick#%d 任务%d 新鲜(成功%d/失败%d) 追平(成功%d/失败%d) 节流%dms(%.1f/s) 429=%d 耗时%lldms",
                         tick, (int)g_tasks.size(), g_fok.exchange(0), g_ffail.exchange(0),   // 新鲜任务成败(读后清零)
                         g_cok.exchange(0), g_cfail.exchange(0),                              // 追平任务成败(读后清零)
                         g_minMs.load(), 1000.0 / (g_minMs.load() > 0 ? g_minMs.load() : 1), n429,   // 当前节流间隔与折算QPS
                         GetTickCount64() - t0);   // 本轮耗时
                logline(lg);                       // 写日志
            }
        } catch (...) {                            // 兜底: 任何本轮异常
            logline("hub_loop 异常(已捕获, 继续)");   // 记日志后继续下一轮, 保证常驻
        }
        long long el = GetTickCount64() - t0;      // 本轮实际耗时
        Sleep(el < 15000 ? (15000 - (int)el) : 100);   // 补足15秒节拍; 超时则仅歇100ms快速进入下一轮
    }
}

// ---------------- main ----------------
static LONG WINAPI crash_handler(EXCEPTION_POINTERS*) {   // 未处理异常过滤器: 崩溃时留日志后退出
    logline("!! 未处理异常(崩溃), 进程将退出, 等待计划任务1分钟内自动拉起 !!");   // 留崩溃痕迹, 靠finally_fill1m守护拉起
    return EXCEPTION_EXECUTE_HANDLER;              // 结束默认处理: 进程退出
}

// 单实例硬检查(跨会话双开防护, 按 exe 全路径比对)
static bool another_instance_running() {           // 全局互斥名跨会话可能失效, 此处按exe全路径逐一比对进程
    char self[MAX_PATH * 2] = { 0 };               // 自身exe路径缓冲
    GetModuleFileNameA(NULL, self, sizeof(self) - 1);   // 取自身全路径
    std::string me = self;                         // 转string便于比较
    for (auto& c : me) c = (char)tolower((unsigned char)c);   // 路径转小写(Windows不区分大小写)
    HANDLE snap = CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0);   // 建全系统进程快照
    if (snap == INVALID_HANDLE_VALUE) return false;   // 快照失败: 放行(后面还有命名互斥兜底)
    PROCESSENTRY32W pe; pe.dwSize = sizeof(pe);    // 进程条目结构并初始化dwSize
    DWORD selfPid = GetCurrentProcessId();         // 自身PID(比对时跳过)
    bool dup = false;                              // 是否发现双开
    if (Process32FirstW(snap, &pe)) {              // 开始枚举进程
        do {
            if (pe.th32ProcessID == selfPid) continue;   // 跳过自己
            HANDLE hp = OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pe.th32ProcessID);   // 打开进程(仅查路径所需权限)
            if (!hp) continue;                     // 打不开(权限不足/已退出): 跳过
            char buf[MAX_PATH * 2] = { 0 }; DWORD len = sizeof(buf) - 1;   // 对方exe路径缓冲
            if (QueryFullProcessImageNameA(hp, 0, buf, &len)) {   // 查询对方exe全路径
                std::string other = buf;           // 转string
                for (auto& c : other) c = (char)tolower((unsigned char)c);   // 转小写
                if (other == me) dup = true;       // 与自身同路径: 判定双开
            }
            CloseHandle(hp);                       // 关闭进程句柄
            if (dup) break;                        // 已发现双开: 提前结束枚举
        } while (Process32NextW(snap, &pe));       // 继续下一个进程
    }
    CloseHandle(snap);                             // 释放快照句柄
    return dup;                                    // 返回是否存在另一实例
}

int WINAPI WinMain(HINSTANCE, HINSTANCE, LPSTR, int) {   // GUI程序入口(无控制台窗口, 适合常驻后台)
    SetUnhandledExceptionFilter(crash_handler);    // 注册崩溃过滤器(崩溃留日志)
    HANDLE mx = CreateMutexA(nullptr, TRUE, "Global\\finally_datahub");   // 建全局命名互斥(同会话防双开)
    if (mx && GetLastError() == ERROR_ALREADY_EXISTS) return 0;   // 互斥已存在: 已有实例在跑, 静默退出
    if (another_instance_running()) return 0;   // 跨会话双开硬拦截 // 单实例
    SetPriorityClass(GetCurrentProcess(), BELOW_NORMAL_PRIORITY_CLASS);   // 降为低于正常优先级: 不抢占交易主程序CPU
    log_setfile("E:\\datas\\log\\datahub.log");    // 指定日志文件路径
    logline("==== datahub C++ 数据中枢 v2 启动 pid=" + std::to_string(GetCurrentProcessId()) + " ====");   // 启动横幅含PID
    // 等待数据库就绪(开机mysql可能未起)
    bool ok = false;                               // DB就绪标志
    for (int i = 0; i < 60; i++) { if (db_ex("SELECT 1")) { ok = true; break; } Sleep(5000); }   // 最多等60×5秒=5分钟
    if (!ok) { logline("DB 连接失败60次, 退出"); return 1; }   // 5分钟仍连不上: 放弃退出(等计划任务再拉起)
    logline("DB 已连接 trading@127.0.0.1 (libmysql)");   // 记录DB就绪
    std::thread t1(hub_loop);                      // 启动HUB主循环线程
    t1.join();                                     // 主线程阻塞等待(常驻直到进程被杀)
    return 0;                                      // 正常退出(实际不会到达)
}
