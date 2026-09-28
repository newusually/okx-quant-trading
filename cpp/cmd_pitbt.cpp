/* ==================================================================
 * cmd_pitbt.cpp — 黄金坑策略回测命令行工具
 *
 * 【文件职责】
 *   从 MySQL 读取真实 5 分钟K线（kline_{inst}_5m 表），喂入内存K线数组后
 *   逐根滚动调用 sigcore 的两个信号函数做历史回测对比：
 *     · 旧策略 gold_signal（金▲动能信号）
 *     · 新策略 pit_signal（黄金坑六维信号）
 *   最后按合约与汇总两级统计胜率 / 平均收益 / 总收益。
 *
 * 【回测口径】
 *   信号在该根K线收盘确认 → 下一根开盘价买入；
 *   止盈 +2%（以最高价触及判定）或持有 60 根（5 小时）超时以收盘价平仓；
 *   单合约同时只持 1 仓，持仓期间忽略新信号（old/pit 两策略仓位独立）。
 *   另统计"5 根后仍盈利"(up5) 比例作为辅助指标。
 *
 * 【用法】
 *   cmd_pitbt.exe [合约数=30] [天数=45] [可选: INST名 只测单个]
 *   编译: g++ -O2 -mwindows -static -o bin/cmd_pitbt.exe cmd_pitbt.cpp sigcore.cpp
 *         -Ivendor/.../include -Lvendor/.../lib -l:libmysql.dll
 *
 * 【导出函数清单】（命令行工具，仅 main 一个入口）
 *   main()        : 解析参数 → 读DB取K线 → 并行逐合约回测 → 汇总输出
 *   （内部辅助） db_q: MySQL 查询（懒连接+自动重连）；run_inst: 单合约双策略回测；
 *              feed: 累加单笔交易到统计结构
 * ================================================================== */
#include <windows.h>          // Windows 基础头(atoll 等 CRT 声明所在平台)
#include <mysql.h>            // MySQL C API(连接/查询/取结果)
#include "common/simd.h"      // SoA/SIMD/par_for (2026-09-28 性能内核)
#include <string>             // std::string
#include <vector>             // std::vector 动态数组
#include <cstdio>             // printf/fprintf/snprintf
#include <cstring>            // 字符串工具
#include <cmath>              // 数学函数
#include <ctime>              // time() 计算回测起始时刻
#include <chrono>             // 高精度计时(回测耗时统计)
#include <algorithm>          // std::min 等

static MYSQL* g_my = nullptr;                      // 全局 MySQL 连接句柄(懒初始化, 全程复用)
struct RowSet { std::vector<std::vector<std::string>> rows; bool ok = false; };  // 查询结果集: 字符串行集合 + 成功标志
// 执行一条 SQL 并把结果取回为字符串二维表(首次调用时懒建连接)
static RowSet db_q(const std::string& sql) {
    RowSet rs;                                     // 待返回的结果集
    if (!g_my) {                                   // 尚无连接 → 先建立
        g_my = mysql_init(nullptr);                // 初始化 MySQL 句柄
        my_bool r = 1; mysql_options(g_my, MYSQL_OPT_RECONNECT, &r);  // 开启断线自动重连
        if (!mysql_real_connect(g_my, "127.0.0.1", "root", "", "trading", 3306, nullptr, 0)) {  // 连接本机 trading 库
            fprintf(stderr, "DB FAIL: %s\n", mysql_error(g_my)); return rs;  // 失败: 打印错误并返回空集
        }
    }
    if (mysql_query(g_my, sql.c_str()) != 0) return rs;   // 执行 SQL, 失败返回空集
    MYSQL_RES* res = mysql_store_result(g_my);     // 一次性取回全部结果
    if (res) {                                     // 有结果集则逐行读取
        while (MYSQL_ROW r = mysql_fetch_row(res)) {          // 逐行取
            std::vector<std::string> row;              // 本行各列
            for (unsigned i = 0; i < mysql_num_fields(res); i++) row.push_back(r[i] ? r[i] : "");  // NULL 列转空串
            rs.rows.push_back(std::move(row));         // 收入结果集
        }
        mysql_free_result(res);                        // 释放结果集内存
    }
    rs.ok = true;                                     // 标记查询执行成功(行数可能为 0)
    return rs;                                        // 返回结果
}

extern "C" {                                       // 声明来自 sigcore.dll 的两个信号接口
int gold_signal(const double* rows, int n, const char* bar, double* ke, double* th, int* dist, char* info, int infoLen);  // 旧金▲信号
int pit_signal(const double* rows, int n, const char* bar, double* score, char* info, int infoLen);  // 新黄金坑信号
}

struct Bar { long long t; double o, h, l, c, v; }; // 单根K线: 毫秒时间戳+开高低收量

struct Stat { int trades = 0, wins = 0, up5 = 0; double sumPct = 0; double worst = 0; };  // 回测统计: 笔数/胜数/5根后盈利数/收益和/最差单笔
static void feed(Stat& s, bool win, double pct, bool u5) {   // 把一笔交易结果累加进统计
    s.trades++; if (win) s.wins++; s.sumPct += pct; if (pct < s.worst) s.worst = pct; if (u5) s.up5++;  // 逐项累加
}

// 对单个合约跑两策略: bars 已按时间升序; 返回是否有效
// 单合约回测: 对每根K线滚动开 400 根窗口, 分别喂旧/新信号并模拟交易
static bool run_inst(const std::string& inst, const std::vector<Bar>& bars, Stat& old_, Stat& pit_) {
    (void)inst;                                    // 合约名此处不参与计算(避免未用告警)
    const int WIN = 400, WARM = 300, HOLD = 120;   // 窗口 400 根 / 预热 300 根 / 单笔最长持有 120 根
    if ((int)bars.size() < WARM + 10) return false;   // 数据太少(不足预热量) → 该合约跳过
    std::vector<double> flat(WIN * 5);             // 交错排列的滑动窗口缓冲(o,h,l,c,v ×400)
    int holdUntilOld = -1, holdUntilPit = -1;   // 持仓到该bar下标(含), 期间不开新仓
    int i0 = (WARM > WIN - 1) ? WARM : WIN - 1;    // 起始下标: 保证窗口有 400 根且已过预热期
    for (int i = i0; i < (int)bars.size() - 2; i++) {   // 逐根扫描(留 2 根余量供"下一根开盘"与统计)
        int st = i - WIN + 1;                      // 当前窗口起点下标
        for (int k = 0; k < WIN; k++) {            // 把窗口 400 根拷入交错缓冲
            const Bar& b = bars[st + k];           // 取窗口内第 k 根
            flat[k * 5] = b.o; flat[k * 5 + 1] = b.h; flat[k * 5 + 2] = b.l;   // 写入开/高/低
            flat[k * 5 + 3] = b.c; flat[k * 5 + 4] = b.v;    // 写入收/量
        }
        double sc = 0; char info[160];             // 信号得分与提示串缓冲
        const Bar& nx = bars[i + 1];               // 下一根K线(信号收盘确认 → 下一根开盘买入)
        if (i > holdUntilOld) {                    // 旧策略当前空仓才允许开新仓
            double ke = 0, th = 0; int dist = 0;   // 金▲输出: 动能/阈值/距离
            if (gold_signal(flat.data(), WIN, "5m", &ke, &th, &dist, info, 160) == 1) {   // 金▲触发
                double entry = nx.o; bool win = false; double exit = entry;   // 入场=下一根开盘价, 初始退出价=入场价
                int j = i + 1;                     // 从下一根开始逐根找退出点
                for (; j <= i + HOLD && j < (int)bars.size(); j++) {   // 最多持有 HOLD 根
                    if (bars[j].h >= entry * 1.02) { win = true; exit = entry * 1.02; break; }   // 最高价触及 +2% → 止盈
                    exit = bars[j].c;              // 未触发止盈则记录当根收盘价(超时平仓用)
                }
                double pct = (exit / entry - 1) * 100.0;   // 本笔收益率(%)
                bool u5 = (i + 6 < (int)bars.size()) && bars[i + 5].c > entry;   // 辅助指标: 5 根后收盘是否仍高于入场
                feed(old_, win, pct, u5);          // 计入旧策略统计
                holdUntilOld = std::min(j, (int)bars.size() - 1);   // 持仓至平仓根, 期间不再开仓
            }
        }
        if (i > holdUntilPit) {                    // 新策略当前空仓才允许开新仓
            if (pit_signal(flat.data(), WIN, "5m", &sc, info, 160) == 1) {   // 黄金坑触发
                double entry = nx.o; bool win = false; double exit = entry;   // 入场=下一根开盘价
                int j = i + 1;                     // 逐根找退出点
                for (; j <= i + HOLD && j < (int)bars.size(); j++) {   // 最多持有 HOLD 根
                    if (bars[j].h >= entry * 1.02) { win = true; exit = entry * 1.02; break; }   // +2% 止盈
                    exit = bars[j].c;              // 记录当根收盘价
                }
                double pct = (exit / entry - 1) * 100.0;   // 本笔收益率(%)
                bool u5 = (i + 6 < (int)bars.size()) && bars[i + 5].c > entry;   // 5 根后仍盈利判定
                feed(pit_, win, pct, u5);          // 计入新策略统计
                holdUntilPit = std::min(j, (int)bars.size() - 1);   // 持仓锁定
            }
        }
    }
    return true;                                   // 该合约回测有效完成
}

int main(int argc, char** argv) {                  // 程序入口: 参数→取数→并行回测→汇总
    int nInst = argc > 1 ? atoi(argv[1]) : 30;     // 参数1: 参测合约数(默认 30)
    int days = argc > 2 ? atoi(argv[2]) : 45;      // 参数2: 回测天数(默认 45)
    std::string only = argc > 3 ? argv[3] : "";    // 参数3: 指定单合约则只测它
    // 合约列表
    std::vector<std::string> insts;                // 待测合约 ID 列表
    if (!only.empty()) insts.push_back(only);      // 指定了单合约 → 只测它
    else {
        RowSet rs = db_q("SELECT inst_id FROM symbol_pool ORDER BY inst_id LIMIT " + std::to_string(nInst));  // 从合约池取前 N 个
        for (auto& r : rs.rows) insts.push_back(r[0]);   // 收集合约 ID
    }
    // ---- 阶段1: 串行取数 (MySQL 连接非线程安全, 必须在并行段之前完成) ----
    std::vector<std::string> names;                // 实际参测的合约名(数据够长的才保留)
    std::vector<std::vector<Bar>> allBars;         // 各合约的K线数组(与 names 一一对应)
    long long tLoad = (long long)(time(nullptr) - (long long)days * 86400) * 1000;   // 回测起点: 今天-days 的毫秒时间戳
    for (auto& inst : insts) {                     // 逐合约串行拉取K线
        std::string tbl = "kline_";                // 拼表名: kline_{inst}_5m
        for (char c : inst) tbl += (c == '-') ? '_' : (c >= 'A' && c <= 'Z') ? c + 32 : c;   // '-' 换 '_', 大写转小写
        tbl += "_5m";                              // 追加周期后缀
        RowSet rs = db_q("SELECT candle_time,o,h,l,c,vol FROM " + tbl +      // 查时间/开/高/低/收/量
                         " WHERE c>0 AND candle_time >= " + std::to_string(tLoad) +   // 过滤: 有效价+起始时间之后
                         " ORDER BY candle_time ASC");   // 按时间升序
        if (!rs.ok || rs.rows.size() < 320) continue;   // 查询失败或数据不足(320 根) → 跳过该合约
        std::vector<Bar> bars;                     // 本合约K线数组
        for (auto& r : rs.rows)                    // 逐行转换为 Bar 结构
            bars.push_back({atoll(r[0].c_str()), atof(r[1].c_str()), atof(r[2].c_str()),   // 时间/开/高
                            atof(r[3].c_str()), atof(r[4].c_str()), atof(r[5].c_str())});  // 低/收/量
        names.push_back(inst);                     // 登记参测合约名
        allBars.push_back(std::move(bars));        // 登记其K线数据
    }
    // ---- 阶段2: 并行回测 (合约间完全独立; 每合约内部严格保持原顺序 → 结果逐位一致) ----
    int K = (int)names.size();                     // 实际参测合约数
    std::vector<Stat> oS((size_t)K), pS((size_t)K);   // 每合约的旧/新策略统计
    std::vector<char> okS((size_t)K, 0);           // 每合约的回测成功标志
    auto t0 = std::chrono::steady_clock::now();    // 计时开始
    sk::par_for(K, [&](int i) { okS[(size_t)i] = run_inst(names[(size_t)i], allBars[(size_t)i],   // 多线程逐合约回测
                                                           oS[(size_t)i], pS[(size_t)i]) ? 1 : 0; }, 0, 2);   // 结果写入各自统计槽(无共享写)
    auto t1 = std::chrono::steady_clock::now();    // 计时结束
    printf("回测计算: %d 合约 × %d 线程 → %.2f s\n", K, sk::cpu().hwThreads,   // 打印耗时与线程数
           std::chrono::duration<double>(t1 - t0).count());

    // ---- 阶段3: 串行汇总输出 (顺序与单线程版完全一致) ----
    Stat oT, pT;                                   // 全局汇总: 旧/新策略
    int done = 0;                                  // 有效完成回测的合约数
    printf("inst | OLD: 笔数 胜%% 均%% 总%% | PIT: 笔数 胜%% 均%% 总%%\n");   // 表头
    for (int i = 0; i < K; i++) {                  // 按固定顺序输出各合约结果
        if (!okS[(size_t)i]) continue;             // 回测失败的合约跳过
        const std::string& inst = names[(size_t)i];   // 合约名
        const Stat& o = oS[(size_t)i];             // 旧策略统计
        const Stat& p = pS[(size_t)i];             // 新策略统计
        done++;                                    // 有效合约计数
        printf("%-18s | %4d %5.1f%% %+6.2f %+8.2f | %4d %5.1f%% %+6.2f %+8.2f\n",   // 打印单行对比
               inst.c_str(), o.trades, o.trades ? 100.0 * o.wins / o.trades : 0,   // 旧: 笔数/胜率
               o.trades ? o.sumPct / o.trades : 0, o.sumPct,   // 旧: 均收益/总收益
               p.trades, p.trades ? 100.0 * p.wins / p.trades : 0,   // 新: 笔数/胜率
               p.trades ? p.sumPct / p.trades : 0, p.sumPct);  // 新: 均收益/总收益
        oT.trades += o.trades; oT.wins += o.wins; oT.sumPct += o.sumPct; oT.up5 += o.up5;   // 累加旧策略汇总
        pT.trades += p.trades; pT.wins += p.wins; pT.sumPct += p.sumPct; pT.up5 += p.up5;   // 累加新策略汇总
    }
    printf("\n===== 汇总 (%d 合约, %d 天, 5m, +2%%止盈/60根超时) =====\n", done, days);   // 汇总标题
    printf("旧金▲ : 笔数=%d 胜率=%.1f%% 平均=%+.3f%% 总计=%+.1f%%\n",   // 打印旧策略汇总
           oT.trades, oT.trades ? 100.0 * oT.wins / oT.trades : 0,   // 笔数/胜率
           oT.trades ? oT.sumPct / oT.trades : 0, oT.sumPct);        // 平均/总计
    printf("新黄金坑: 笔数=%d 胜率=%.1f%% 平均=%+.3f%% 总计=%+.1f%%\n",  // 打印新策略汇总
           pT.trades, pT.trades ? 100.0 * pT.wins / pT.trades : 0,   // 笔数/胜率
           pT.trades ? pT.sumPct / pT.trades : 0, pT.sumPct);        // 平均/总计
    return 0;                                      // 正常退出
}
