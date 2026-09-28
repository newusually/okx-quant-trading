// ==================================================================
// cmd_pitbt.cpp — 黄金坑公式回测对比 (旧gold_signal vs 新pit_signal)
// 数据: MySQL kline_{inst}_5m 真实K线; 模拟: 信号bar收盘确认 → 下一根开盘买入
//       止盈+2%(价格) 或 60根(5小时)超时平仓, 单合约同时只持1仓(信号重叠忽略)
// 用法: cmd_pitbt.exe [合约数=30] [天数=45] [可选: INST名 只测单个]
// 编译: g++ -O2 -mwindows -static -o bin/cmd_pitbt.exe cmd_pitbt.cpp sigcore.cpp
//       -Ivendor/.../include -Lvendor/.../lib -l:libmysql.dll
// ==================================================================
#include <windows.h>
#include <mysql.h>
#include "common/simd.h"      // SoA/SIMD/par_for (2026-09-28 性能内核)
#include <string>
#include <vector>
#include <cstdio>
#include <cstring>
#include <cmath>
#include <ctime>
#include <chrono>
#include <algorithm>

static MYSQL* g_my = nullptr;
struct RowSet { std::vector<std::vector<std::string>> rows; bool ok = false; };
static RowSet db_q(const std::string& sql) {
    RowSet rs;
    if (!g_my) {
        g_my = mysql_init(nullptr);
        my_bool r = 1; mysql_options(g_my, MYSQL_OPT_RECONNECT, &r);
        if (!mysql_real_connect(g_my, "127.0.0.1", "root", "", "trading", 3306, nullptr, 0)) {
            fprintf(stderr, "DB FAIL: %s\n", mysql_error(g_my)); return rs;
        }
    }
    if (mysql_query(g_my, sql.c_str()) != 0) return rs;
    MYSQL_RES* res = mysql_store_result(g_my);
    if (res) {
        while (MYSQL_ROW r = mysql_fetch_row(res)) {
            std::vector<std::string> row;
            for (unsigned i = 0; i < mysql_num_fields(res); i++) row.push_back(r[i] ? r[i] : "");
            rs.rows.push_back(std::move(row));
        }
        mysql_free_result(res);
    }
    rs.ok = true;
    return rs;
}

extern "C" {
int gold_signal(const double* rows, int n, const char* bar, double* ke, double* th, int* dist, char* info, int infoLen);
int pit_signal(const double* rows, int n, const char* bar, double* score, char* info, int infoLen);
}

struct Bar { long long t; double o, h, l, c, v; };

struct Stat { int trades = 0, wins = 0, up5 = 0; double sumPct = 0; double worst = 0; };
static void feed(Stat& s, bool win, double pct, bool u5) {
    s.trades++; if (win) s.wins++; s.sumPct += pct; if (pct < s.worst) s.worst = pct; if (u5) s.up5++;
}

// 对单个合约跑两策略: bars 已按时间升序; 返回是否有效
static bool run_inst(const std::string& inst, const std::vector<Bar>& bars, Stat& old_, Stat& pit_) {
    (void)inst;
    const int WIN = 400, WARM = 300, HOLD = 120;
    if ((int)bars.size() < WARM + 10) return false;
    std::vector<double> flat(WIN * 5);
    int holdUntilOld = -1, holdUntilPit = -1;   // 持仓到该bar下标(含), 期间不开新仓
    int i0 = (WARM > WIN - 1) ? WARM : WIN - 1;
    for (int i = i0; i < (int)bars.size() - 2; i++) {
        int st = i - WIN + 1;
        for (int k = 0; k < WIN; k++) {
            const Bar& b = bars[st + k];
            flat[k * 5] = b.o; flat[k * 5 + 1] = b.h; flat[k * 5 + 2] = b.l;
            flat[k * 5 + 3] = b.c; flat[k * 5 + 4] = b.v;
        }
        double sc = 0; char info[160];
        const Bar& nx = bars[i + 1];
        if (i > holdUntilOld) {
            double ke = 0, th = 0; int dist = 0;
            if (gold_signal(flat.data(), WIN, "5m", &ke, &th, &dist, info, 160) == 1) {
                double entry = nx.o; bool win = false; double exit = entry;
                int j = i + 1;
                for (; j <= i + HOLD && j < (int)bars.size(); j++) {
                    if (bars[j].h >= entry * 1.02) { win = true; exit = entry * 1.02; break; }
                    exit = bars[j].c;
                }
                double pct = (exit / entry - 1) * 100.0;
                bool u5 = (i + 6 < (int)bars.size()) && bars[i + 5].c > entry;
                feed(old_, win, pct, u5);
                holdUntilOld = std::min(j, (int)bars.size() - 1);
            }
        }
        if (i > holdUntilPit) {
            if (pit_signal(flat.data(), WIN, "5m", &sc, info, 160) == 1) {
                double entry = nx.o; bool win = false; double exit = entry;
                int j = i + 1;
                for (; j <= i + HOLD && j < (int)bars.size(); j++) {
                    if (bars[j].h >= entry * 1.02) { win = true; exit = entry * 1.02; break; }
                    exit = bars[j].c;
                }
                double pct = (exit / entry - 1) * 100.0;
                bool u5 = (i + 6 < (int)bars.size()) && bars[i + 5].c > entry;
                feed(pit_, win, pct, u5);
                holdUntilPit = std::min(j, (int)bars.size() - 1);
            }
        }
    }
    return true;
}

int main(int argc, char** argv) {
    int nInst = argc > 1 ? atoi(argv[1]) : 30;
    int days = argc > 2 ? atoi(argv[2]) : 45;
    std::string only = argc > 3 ? argv[3] : "";
    // 合约列表
    std::vector<std::string> insts;
    if (!only.empty()) insts.push_back(only);
    else {
        RowSet rs = db_q("SELECT inst_id FROM symbol_pool ORDER BY inst_id LIMIT " + std::to_string(nInst));
        for (auto& r : rs.rows) insts.push_back(r[0]);
    }
    // ---- 阶段1: 串行取数 (MySQL 连接非线程安全, 必须在并行段之前完成) ----
    std::vector<std::string> names;
    std::vector<std::vector<Bar>> allBars;
    long long tLoad = (long long)(time(nullptr) - (long long)days * 86400) * 1000;
    for (auto& inst : insts) {
        std::string tbl = "kline_";
        for (char c : inst) tbl += (c == '-') ? '_' : (c >= 'A' && c <= 'Z') ? c + 32 : c;
        tbl += "_5m";
        RowSet rs = db_q("SELECT candle_time,o,h,l,c,vol FROM " + tbl +
                         " WHERE c>0 AND candle_time >= " + std::to_string(tLoad) +
                         " ORDER BY candle_time ASC");
        if (!rs.ok || rs.rows.size() < 320) continue;
        std::vector<Bar> bars;
        for (auto& r : rs.rows)
            bars.push_back({atoll(r[0].c_str()), atof(r[1].c_str()), atof(r[2].c_str()),
                            atof(r[3].c_str()), atof(r[4].c_str()), atof(r[5].c_str())});
        names.push_back(inst);
        allBars.push_back(std::move(bars));
    }
    // ---- 阶段2: 并行回测 (合约间完全独立; 每合约内部严格保持原顺序 → 结果逐位一致) ----
    int K = (int)names.size();
    std::vector<Stat> oS((size_t)K), pS((size_t)K);
    std::vector<char> okS((size_t)K, 0);
    auto t0 = std::chrono::steady_clock::now();
    sk::par_for(K, [&](int i) { okS[(size_t)i] = run_inst(names[(size_t)i], allBars[(size_t)i],
                                                           oS[(size_t)i], pS[(size_t)i]) ? 1 : 0; }, 0, 2);
    auto t1 = std::chrono::steady_clock::now();
    printf("回测计算: %d 合约 × %d 线程 → %.2f s\n", K, sk::cpu().hwThreads,
           std::chrono::duration<double>(t1 - t0).count());

    // ---- 阶段3: 串行汇总输出 (顺序与单线程版完全一致) ----
    Stat oT, pT;
    int done = 0;
    printf("inst | OLD: 笔数 胜%% 均%% 总%% | PIT: 笔数 胜%% 均%% 总%%\n");
    for (int i = 0; i < K; i++) {
        if (!okS[(size_t)i]) continue;
        const std::string& inst = names[(size_t)i];
        const Stat& o = oS[(size_t)i];
        const Stat& p = pS[(size_t)i];
        done++;
        printf("%-18s | %4d %5.1f%% %+6.2f %+8.2f | %4d %5.1f%% %+6.2f %+8.2f\n",
               inst.c_str(), o.trades, o.trades ? 100.0 * o.wins / o.trades : 0,
               o.trades ? o.sumPct / o.trades : 0, o.sumPct,
               p.trades, p.trades ? 100.0 * p.wins / p.trades : 0,
               p.trades ? p.sumPct / p.trades : 0, p.sumPct);
        oT.trades += o.trades; oT.wins += o.wins; oT.sumPct += o.sumPct; oT.up5 += o.up5;
        pT.trades += p.trades; pT.wins += p.wins; pT.sumPct += p.sumPct; pT.up5 += p.up5;
    }
    printf("\n===== 汇总 (%d 合约, %d 天, 5m, +2%%止盈/60根超时) =====\n", done, days);
    printf("旧金▲ : 笔数=%d 胜率=%.1f%% 平均=%+.3f%% 总计=%+.1f%%\n",
           oT.trades, oT.trades ? 100.0 * oT.wins / oT.trades : 0,
           oT.trades ? oT.sumPct / oT.trades : 0, oT.sumPct);
    printf("新黄金坑: 笔数=%d 胜率=%.1f%% 平均=%+.3f%% 总计=%+.1f%%\n",
           pT.trades, pT.trades ? 100.0 * pT.wins / pT.trades : 0,
           pT.trades ? pT.sumPct / pT.trades : 0, pT.sumPct);
    return 0;
}
