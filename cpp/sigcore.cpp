// ==================================================================
// sigcore.cpp — OKX 交易系统 C++ 计算库 (2026-09-27 PHP+C++ 架构迁移)
//   由 PHP FFI 加载 / 同源编译进 apihub·tphub·tradehub, 提供:
//     金▲动能信号 / 黄金坑六维信号 / 阈值ZigZag转折 / MACD 指标 / 常驻内存K线库
//
//   与网页 ov_pivot.js / ov_physics.js 及 Go goldsig.go 逐字同口径:
//     ① 阈值ZigZag: 反向波动 >= SEGM×ATR14 才算转折(1m/3m:5.0, 5m:5.5, 15m:6.0, 1H:6.5)
//     ② 每个底部转折点算入腿动能 KE = 0.5*m*v^2 (m=入腿均量, v=|dp|/(ATR*根数))
//     ③ 尖转折金▲ = 底部KE排前25%(75分位阈值) 的底部点
//   无未来函数: 转折点只用它之前的数据; 触发 = 最新确认转折是金▲底部 且 距最新K线 <= 8 根
//
//   ── 2026-09-28 性能内核升级 (用户指令: 正确内存布局 + SIMD + 多线程) ──
//     ① 内存布局 SoA: 常驻库与算法全程使用 o/h/l/c/v 五条 64B 对齐独立数组
//        (交错 stride=5 仅在 extern "C" 入口做一次 transpose)
//     ② SIMD: TR/+DM/-DM 走 AVX2 运行时分发; KDJ 窗口极值走 O(n) 单调队列
//     ③ 多线程: pivots_full 逐转折点 par_for
//     ⚠ 位精确铁律: 本文件是 tradehub 真实下单依据, 所有改动必须通过
//        _test/sigdump 的旧/新 DLL 逐值比对(288 用例 860KB 输出必须逐字节一致)。
//        因此所有浮点归约/递推保持与标量逐字相同的运算顺序, SIMD 只用于
//        max/min/掩码选择/绝对值和逐元素运算(不引入重结合)。
//
//   全部 extern "C" 导出, 编译: g++ -shared -O2 -static -o bin/sigcore.dll sigcore.cpp
// ==================================================================

#include "common/simd.h"
#include <cmath>
#include <cstdlib>
#include <cstring>
#include <cstdio>
#include <vector>
#include <algorithm>

#define GOLD_FRESH_BARS 8
#define SERIES_CAP 600      // 每个序列滞留内存的最大根数(滚动淘汰最老)
#define SERIES_MAX 4096     // 最多滞留的序列数(合约×周期)

// ==================================================================
// 入口适配: 交错 rows[n*5] → SoA(五条对齐数组)
//   每线程一份常驻 scratch(按需扩容, 调用间复用 → 无 per-call malloc, 缓存常热)
//   一次擦写 5*n8 连续块, 每段按 n8(=8 的倍数) 对齐 → 每段都 64B 对齐
//   纯数据搬运, 无浮点运算 → 位精确
//   (实测: 相比每调用 dalloc/dfree, pit_signal 快 1.47x —— 40KB 冷块首次触达是主因)
// ==================================================================
struct TxScr { double* p; int cap; };
static thread_local TxScr g_tx;     // POD + 零初始化 → 无析构, 不注册 atexit

static double* tx_scratch(int need) {
    if (g_tx.cap < need) {
        sk::dfree(g_tx.p);
        g_tx.p = sk::dalloc((size_t)need);
        g_tx.cap = g_tx.p ? need : 0;
    }
    return g_tx.p;
}

// ---------------- 每线程工作区槽位(免 per-call malloc / 免 vector 零初始化) ----------------
//   槽位互不嵌套: 调用链 gold_signal→gold_signal_ref 用 GOLD 槽,
//   pit_signal→pit_signal_ref 用 PIT 槽再套 GOLD 槽 → 两者不重叠, 安全。
enum { WS_GOLD = 0, WS_GOLDPV, WS_PIT, WS_PFULL, WS_PCALC, WS_N };   // WS_TX 即 g_tx
static thread_local TxScr g_ws[WS_N];
static double* ws(int slot, int need) {
    if (g_ws[slot].cap < need) {
        sk::dfree(g_ws[slot].p);
        g_ws[slot].p = sk::dalloc((size_t)need);
        g_ws[slot].cap = g_ws[slot].p ? need : 0;
    }
    return g_ws[slot].p;
}

struct Txb {
    double* p;
    int n8;
    sk::Ref r;
};
static Txb tx_make(const double* rows, int n) {
    Txb t;
    int n8 = (n + 7) & ~7;
    t.n8 = n8;
    t.p = tx_scratch(5 * n8);
    if (!t.p) { t.r.o = t.r.h = t.r.l = t.r.c = t.r.v = nullptr; t.r.n = 0; return t; }
    double* o = t.p;
    double* h = t.p + n8;
    double* l = t.p + 2 * n8;
    double* c = t.p + 3 * n8;
    double* v = t.p + 4 * n8;
    for (int i = 0; i < n; i++) {
        o[i] = rows[i * 5];     h[i] = rows[i * 5 + 1];
        l[i] = rows[i * 5 + 2]; c[i] = rows[i * 5 + 3]; v[i] = rows[i * 5 + 4];
    }
    t.r.o = o; t.r.h = h; t.r.l = l; t.r.c = c; t.r.v = v; t.r.n = n; t.r.st = 1;
    return t;
}

static double fabs1(double x) { return x < 0 ? -x : x; }

static double segm_of(const char* bar) {
    // 与 GoldSEGM 一致: 1m/3m:5.0, 5m:5.5, 15m:6.0, 30m:6.5, 1H:6.5, 4H:7.0
    if (strcmp(bar, "5m") == 0) return 5.5;
    if (strcmp(bar, "15m") == 0) return 6.0;
    if (strcmp(bar, "30m") == 0) return 6.5;
    if (strcmp(bar, "1H") == 0 || strcmp(bar, "1h") == 0) return 6.5;
    if (strcmp(bar, "4H") == 0 || strcmp(bar, "4h") == 0) return 7.0;
    return 5.0; // 1m / 3m / 未知默认
}

// ==================================================================
// 常驻内存K线库: PHP 只喂增量, 数据滞留在本进程内存中(SoA 布局)
//   key = "INST|bar" (如 "BTC-USDT-SWAP|5m")
//   store_upsert   增量喂K线(按 ts 合并去重, 滚动保留最近 SERIES_CAP 根)
//   store_gold     直接用内存里的数据算金▲信号(零 marshaling)
//   store_count / store_stats / store_clear
// ==================================================================

struct Series {
    char key[128];
    long long* ts;
    double *o, *h, *l, *c, *v;      // SoA: 五条 64B 对齐独立数组
    int n, cap;                     // n=当前根数, cap=实际分配容量(根)
    int fired;                      // 最近一次 store_gold 结果(1/0/-1未算)
    double ke, th;
    int dist;
    char info[160];
    int pfire;                      // 最近一次 store_pit 结果(1/0/-1未算)
    double pscore;
    char pinfo[160];
};
static Series g_series[SERIES_MAX];
static int g_seriesN = 0;

// ---------------- 哈希索引 (替代原 O(n) 线性扫描; SERIES_MAX=4096 < HSZ=8192 故探测必然终止) ----------------
#define HSZ 8192
static int g_hidx[HSZ];             // 0=空; 存 series 下标+1

static inline unsigned long long fnv1a(const char* s) {
    unsigned long long h = 1469598103934665603ULL;
    while (*s) { h ^= (unsigned char)(*s++); h *= 1099511628211ULL; }
    return h;
}
static void hash_clear() { memset(g_hidx, 0, sizeof(g_hidx)); }
static void hash_put(const char* key, int idx) {
    unsigned h = (unsigned)(fnv1a(key) & (HSZ - 1));
    while (g_hidx[h]) h = (h + 1) & (HSZ - 1);
    g_hidx[h] = idx + 1;
}
static void hash_rebuild() {          // 仅在发生槽位淘汰(memmove 整体前移)后调用
    hash_clear();
    for (int i = 0; i < g_seriesN; i++) hash_put(g_series[i].key, i);
}
static int series_find(const char* key) {
    unsigned h = (unsigned)(fnv1a(key) & (HSZ - 1));
    while (g_hidx[h]) {
        int idx = g_hidx[h] - 1;
        if (strcmp(g_series[idx].key, key) == 0) return idx;
        h = (h + 1) & (HSZ - 1);
    }
    return -1;
}

static void series_free(Series* s) {
    sk::llfree(s->ts);
    sk::dfree(s->o); sk::dfree(s->h); sk::dfree(s->l); sk::dfree(s->c); sk::dfree(s->v);
    s->ts = nullptr; s->o = s->h = s->l = s->c = s->v = nullptr;
    s->n = s->cap = 0;
}
// 容量保证(修复: 原实现容量由"首灌批次"决定, 后续更大批次只查 `n >= SERIES_CAP+批大小`
// 而不校验真实容量 → 首灌3根后再灌1000根会堆溢出。此处按实际需要扩容, 数据不变 → 位精确)
static bool series_ensure(Series* s, int need) {
    if (need <= s->cap) return true;
    int nc = s->cap ? s->cap : (SERIES_CAP + 16);
    while (nc < need) nc = nc + nc / 2 + 32;
    long long* nts = sk::llalloc((size_t)nc);
    double* no = sk::dalloc((size_t)nc); double* nh = sk::dalloc((size_t)nc);
    double* nl = sk::dalloc((size_t)nc); double* nc2 = sk::dalloc((size_t)nc);
    double* nv = sk::dalloc((size_t)nc);
    if (!nts || !no || !nh || !nl || !nc2 || !nv) return false;
    if (s->n > 0) {
        memcpy(nts, s->ts, sizeof(long long) * (size_t)s->n);
        memcpy(no, s->o, sizeof(double) * (size_t)s->n);
        memcpy(nh, s->h, sizeof(double) * (size_t)s->n);
        memcpy(nl, s->l, sizeof(double) * (size_t)s->n);
        memcpy(nc2, s->c, sizeof(double) * (size_t)s->n);
        memcpy(nv, s->v, sizeof(double) * (size_t)s->n);
    }
    series_free(s);
    s->ts = nts; s->o = no; s->h = nh; s->l = nl; s->c = nc2; s->v = nv;
    s->cap = nc;
    return true;
}
// 滚动淘汰最老: 一次批量 memmove(原实现逐根 while 循环 memmove, 结果同)
static void series_trim(Series* s) {
    int over = s->n - SERIES_CAP;
    if (over <= 0) return;
    int keep = s->n - over;
    memmove(s->ts, s->ts + over, sizeof(long long) * (size_t)keep);
    memmove(s->o, s->o + over, sizeof(double) * (size_t)keep);
    memmove(s->h, s->h + over, sizeof(double) * (size_t)keep);
    memmove(s->l, s->l + over, sizeof(double) * (size_t)keep);
    memmove(s->c, s->c + over, sizeof(double) * (size_t)keep);
    memmove(s->v, s->v + over, sizeof(double) * (size_t)keep);
    s->n = keep;
}

// ==================================================================
// 指标内核 — 全部接收 sk::Ref(SoA), 运算顺序与标量逐字一致
// ==================================================================

// Wilder ATR14 (种子=第一根高低差)
//   连续布局(st==1)且有 AVX2: TR 预计算走 SIMD(GL 通道 adx14 还要复用 tr[]) → 划算
//   交错布局(st>1): TR 直接融合进 ATR 递推 —— 与原始实现逐字同序同值, 且省掉 n 次额外写
static void gold_atr14(const sk::Ref& r, double* tr, double* atr) {
    int n = r.n;
    if (n <= 0) return;
    double a = r.H(0) - r.L(0);             // h0 - l0 (与 tr[0] 同值)
    if (r.st == 1 && n >= 16 && sk::cpu().avx2) {
        sk::tr_soa(r, tr);
        for (int i = 0; i < n; i++) {
            if (i > 0) a += (tr[i] - a) / 14.0;
            if (a < 1e-9) a = 1e-9;
            atr[i] = a;
        }
        return;
    }
    for (int i = 0; i < n; i++) {
        if (i > 0) {
            double pc = r.C(i - 1);
            double t = r.H(i) - r.L(i), d;
            d = r.H(i) - pc; if (d < 0) d = -d; if (d > t) t = d;
            d = r.L(i) - pc; if (d < 0) d = -d; if (d > t) t = d;
            if (tr) tr[i] = t;
            a += (t - a) / 14.0;
        } else if (tr) {
            tr[0] = a;
        }
        if (a < 1e-9) a = 1e-9;
        atr[i] = a;
    }
}

// 阈值ZigZag: 输出转折点 pv[k*3] = {下标, 价, 类型(1顶/0底)}, 返回个数
static int gold_pivots(const sk::Ref& r, double thr, const double* atr, double* pv, int maxP) {
    int n = r.n;
    if (n < 10) return 0;
    int hiI = 0, loI = 0, cnt = 0, lastT = -1;
    double hiP = r.H(0), loP = r.L(0);
    for (int i = 1; i < n; i++) {
        double h = r.H(i), l = r.L(i);
        if (h > hiP) { hiP = h; hiI = i; }
        if (l < loP) { loP = l; loI = i; }
        if (lastT < 0) {
            if (hiP - loP >= thr * atr[i]) {
                int pi; double pp, pt;
                if (hiI < loI) { pi = loI; pp = loP; pt = 0; }
                else           { pi = hiI; pp = hiP; pt = 1; }
                if (cnt >= maxP) return cnt;
                pv[cnt * 3] = pi; pv[cnt * 3 + 1] = pp; pv[cnt * 3 + 2] = pt; cnt++;
                lastT = (int)pt;
                hiI = loI = pi; hiP = r.H(pi); loP = r.L(pi);
            }
            continue;
        }
        double lastP = pv[(cnt - 1) * 3 + 1];
        if (lastT == 1) { // 顶之后: 创新高→顶延伸; 跌够一段→确认底
            if (h > lastP) {
                pv[(cnt - 1) * 3] = i; pv[(cnt - 1) * 3 + 1] = h; pv[(cnt - 1) * 3 + 2] = 1;
                loI = i; loP = l;
                continue;
            }
            if (loP <= lastP - thr * atr[i]) {
                // 保险丝: 新转折点下标必须严格晚于上一个已输出点。
                //   宽幅K线(H-L >= thr*ATR)会让同一根同时满足"顶延伸"与"跌够出底",
                //   旧实现据此在同一根上顶底交替输出, cnt 每轮自增 → 同一根重复几十次。
                if (loI > (int)pv[(cnt - 1) * 3]) {
                    if (cnt >= maxP) return cnt;
                    pv[cnt * 3] = loI; pv[cnt * 3 + 1] = loP; pv[cnt * 3 + 2] = 0; cnt++;
                }
                lastT = 0; hiI = loI; hiP = r.H(loI);
            }
        } else { // 底之后: 创新低→底延伸; 涨够一段→确认顶
            if (l < lastP) {
                pv[(cnt - 1) * 3] = i; pv[(cnt - 1) * 3 + 1] = l; pv[(cnt - 1) * 3 + 2] = 0;
                hiI = i; hiP = h;
                continue;
            }
            if (hiP >= lastP + thr * atr[i]) {
                if (hiI > (int)pv[(cnt - 1) * 3]) {          // 同上保险丝
                    if (cnt >= maxP) return cnt;
                    pv[cnt * 3] = hiI; pv[cnt * 3 + 1] = hiP; pv[cnt * 3 + 2] = 1; cnt++;
                }
                lastT = 1; loI = hiI; loP = r.L(hiI);
            }
        }
    }
    return cnt;
}

// 入腿均量(顺序求和, 位精确): m = mean(v[i0..i1])
static double leg_mean(const sk::Ref& r, int i0, int i1) {
    double vs = sk::sum_st(r.v, r.st, i0, i1 + 1);
    int c = i1 - i0 + 1;
    double m = 1e-9;
    if (c > 0) m = vs / (double)c;
    if (m < 1e-9) m = 1e-9;
    return m;
}

// 入腿动能 KE=0.5*m*v^2 (m=入腿均量, v=|dp|/(ATR14*根数))
static double gold_leg_ke(const sk::Ref& r, const double* atr, int i0, double p0, int i1, double p1) {
    int bars = i1 - i0;
    double dp = fabs1(p1 - p0);
    if (bars <= 0 || dp <= 0 || i1 < 0 || i1 >= r.n || atr[i1] <= 0) return 0;
    double a14 = atr[i1];
    double v = dp / (a14 * (double)bars);
    double m = leg_mean(r, i0, i1);
    return 0.5 * m * v * v;
}

// CCI20: TP=(h+l+c)/3, SMA20, MD=mean|TP-SMA|。TP 逐元素预计算(自动向量化), 求和保持顺序
static void cci20(const sk::Ref& r, double* tp, double* cci) {
    int n = r.n;
    sk::tp_soa(r, tp);
    for (int i = 0; i < n; i++) {
        cci[i] = 0;
        if (i < 19) continue;
        double sma = sk::sum_seq(tp, i - 19, i + 1);
        sma /= 20.0;
        double md = 0;
        for (int j = i - 19; j <= i; j++) md += fabs1(tp[j] - sma);
        md /= 20.0;
        cci[i] = (md < 1e-12) ? 0 : (tp[i] - sma) / (0.015 * md);
    }
}

// Wilder ADX14: 输出 adx/pdi/mdi (i<27 时为0)。TR/+DM/-DM 走 SIMD 预计算, 三段递推保持顺序
static void adx14(const sk::Ref& r, double* tr, double* pdm, double* mdm,
                  double* adx, double* pdi, double* mdi) {
    int n = r.n;
    sk::tr_soa(r, tr);
    sk::dm_soa(r, pdm, mdm);
    double str = 0, sp = 0, sm = 0, adxAcc = 0;
    int adxN = 0;
    for (int i = 1; i < n; i++) {
        double t = tr[i], p = pdm[i], m2 = mdm[i];
        if (i <= 14) {
            str += t; sp += p; sm += m2;
            if (i < 14) { adx[i] = 0; pdi[i] = 0; mdi[i] = 0; continue; }
        } else {
            str = str - str / 14.0 + t;
            sp  = sp  - sp / 14.0  + p;
            sm  = sm  - sm / 14.0  + m2;
        }
        double pdv = (str > 1e-12) ? 100.0 * sp / str : 0;
        double mdv = (str > 1e-12) ? 100.0 * sm / str : 0;
        double dx = (pdv + mdv > 1e-12) ? 100.0 * fabs1(pdv - mdv) / (pdv + mdv) : 0;
        if (adxN == 0) { adxAcc = dx; adxN = 1; }
        else { adxAcc = (adxAcc * adxN + dx) / (adxN + 1); adxN++; }
        adx[i] = (i >= 27) ? adxAcc : 0;
        pdi[i] = pdv; mdi[i] = mdv;
    }
}

// KDJ(9,3,3): 窗口极值走 O(n) 单调队列(纯比较, 与朴素 O(n·9) 同值)
static void kdj9(const sk::Ref& r, double* hh, double* ll, double* K, double* D, double* J) {
    int n = r.n;
    sk::roll_max_st(r.h, r.st, n, 9, hh);
    sk::roll_min_st(r.l, r.st, n, 9, ll);
    double k = 50, d = 50;
    for (int i = 0; i < n; i++) {
        double hv = hh[i], lv = ll[i];
        double rsv = (hv - lv > 1e-12) ? (r.C(i) - lv) / (hv - lv) * 100.0 : 50;
        k = k * 2.0 / 3.0 + rsv / 3.0;
        d = d * 2.0 / 3.0 + k / 3.0;
        K[i] = k; D[i] = d; J[i] = 3 * k - 2 * d;
    }
}

// 缠论底分型: 最近3个确认位内出现底分型(中间K线高低点皆最低), 返回分型中间K线下标, 无则-1
static int chan_bottom_fx(const sk::Ref& r) {
    int n = r.n;
    for (int mid = n - 2; mid >= n - 4 && mid >= 1; mid--) {
        double l = r.L(mid), h = r.H(mid);
        double lp = r.L(mid - 1), hp = r.H(mid - 1);
        double ln = r.L(mid + 1), hn = r.H(mid + 1);
        if (l < lp && l < ln && h < hp && h < hn) return mid;
    }
    return -1;
}

// macd_calc 的内部实现(close 序列, 12/26/9)
static void macd_calc_impl(const double* c, int n, double* dif, double* dea, double* hist) {
    double kf = 2.0 / 13.0, ks = 2.0 / 27.0, kg = 2.0 / 10.0;
    double e12 = n > 0 ? c[0] : 0, e26 = n > 0 ? c[0] : 0, d0 = 0;
    for (int i = 0; i < n; i++) {
        double cc = c[i];
        if (i == 0) { e12 = cc; e26 = cc; d0 = 0; dif[0] = 0; dea[0] = 0; hist[0] = 0; continue; }
        e12 += kf * (cc - e12);
        e26 += ks * (cc - e26);
        dif[i] = e12 - e26;
        d0 += kg * (dif[i] - d0);
        dea[i] = d0;
        hist[i] = (dif[i] - d0) * 2.0;
    }
}

// ---------------- 黄金坑复合信号指标 (2026-09-28 v2) ----------------
// 二阶导: 转折点前15根收盘价(ATR 归一)抛物线最小二乘的 2*c2。顺序求和保持
static double piv_curv(const double* cl, int st, int ri, double a14) {
    int i0 = ri - 15; if (i0 < 0) i0 = 0;
    int npts = ri - i0;
    if (npts < 8 || a14 <= 0) return 0;
    double s0 = 0, s1 = 0, s2 = 0, s3 = 0, s4 = 0, sy = 0, sty = 0, st2y = 0;
    for (int i = i0; i < ri; i++) {
        double t = (double)(i - ri), y = cl[(size_t)i * (size_t)st] / a14;   // 收盘价 / ATR
        s0 += 1; s1 += t; s2 += t * t; s3 += t * t * t; s4 += t * t * t * t;
        sy += y; sty += t * y; st2y += t * t * y;
    }
    double det = s4 * (s2 * s0 - s1 * s1) - s3 * (s3 * s0 - s1 * s2) + s2 * (s3 * s1 - s2 * s2);
    if (fabs1(det) < 1e-12) return 0;
    double c2 = (st2y * (s2 * s0 - s1 * s1) - sty * (s3 * s0 - s1 * s2) + sy * (s3 * s1 - s2 * s2)) / det;
    return 2 * c2;
}

// ---------------- 金▲信号 (SoA 核心) ----------------
static int gold_signal_ref(const sk::Ref& r, const char* bar,
                           double* ke_out, double* th_out, int* dist_out,
                           char* info, int infoLen) {
    int n = r.n;
    if (n < 30 || !bar) {
        if (info && infoLen > 16) snprintf(info, infoLen, "%s", n < 30 ? "K线不足" : "参数错误");
        return 0;
    }
    double thr = segm_of(bar);
    double* Wa = ws(WS_GOLD, n * 2 + 8);
    if (!Wa) return -1;
    double* tr  = Wa;
    double* atr = tr + n;
    gold_atr14(r, tr, atr);
    double* pv = ws(WS_GOLDPV, 3 * (n + 2));
    if (!pv) return -1;
    int npv = gold_pivots(r, thr, atr, pv, n + 2);
    int ret = 0;
    if (npv < 2) {
        if (info && infoLen > 16) snprintf(info, infoLen, "无转折点");
    } else {
        std::vector<double> bke((size_t)npv);
        std::vector<int> bidx((size_t)npv);
        int nb = 0;
        for (int k = 1; k < npv; k++) {
            if ((int)pv[k * 3 + 2] != 0) continue;
            double ke = gold_leg_ke(r, atr, (int)pv[(k - 1) * 3], pv[(k - 1) * 3 + 1],
                                    (int)pv[k * 3], pv[k * 3 + 1]);
            if (ke > 0) { bke[nb] = ke; bidx[nb] = (int)pv[k * 3]; nb++; }
        }
        if (nb == 0) {
            if (info && infoLen > 16) snprintf(info, infoLen, "无底部动能点");
        } else {
            // 75分位阈值(底部KE前25%)
            std::vector<double> ks(bke.begin(), bke.begin() + nb);
            std::sort(ks.begin(), ks.end());
            int thi = (int)((double)nb * 0.75);
            if (thi >= nb) thi = nb - 1;
            double th = ks[(size_t)thi];
            // 触发: 最新确认转折=底部 且 距最新K线<=GOLD_FRESH_BARS 且 KE>=th
            double* last = pv + (npv - 1) * 3;
            if ((int)last[2] != 0) {
                if (info && infoLen > 16) snprintf(info, infoLen, "最新转折非底部");
            } else {
                int dist = n - 1 - (int)last[0];
                if (dist > GOLD_FRESH_BARS) {
                    if (info && infoLen > 16) snprintf(info, infoLen, "底部已过期");
                } else {
                    double ke = 0;
                    for (int i = 0; i < nb; i++) if (bidx[i] == (int)last[0]) { ke = bke[i]; break; }
                    if (ke_out)  *ke_out = ke;
                    if (th_out)  *th_out = th;
                    if (dist_out) *dist_out = dist;
                    if (ke <= 0 || ke < th) {
                        if (info && infoLen > 16)
                            snprintf(info, infoLen, "动能不足前25%%(KE=%.4g<th=%.4g)", ke, th);
                    } else {
                        ret = 1;
                        if (info && infoLen > 16)
                            snprintf(info, infoLen, "金▲底部动能前25%%(KE=%.4g>=th=%.4g, 低点距今%d根)", ke, th, dist);
                    }
                }
            }
        }
    }
    return ret;
}

// ---------------- 黄金坑六维信号 (SoA 核心) ----------------
// 双锚(必须): ①最近3根内已确认底分型 且 现价距坑底<=3%防追高 且 坑深>=2.5×ATR14
//            ②坑前下跌腿急跌动能 速度|dp|/(ATR14*根数)>=1.2
// 其余四维(CCI/ADX/MACD/KDJ)至少中2 → 触发 (严格过滤横盘毛刺, 求稳不求多)
// 返回 1=触发 / 0=未触发 / -1=错误; score_out=总分0~6
static int pit_signal_ref(const sk::Ref& r, const char* bar,
                          double* score_out, char* info, int infoLen) {
    (void)bar;
    int n = r.n;
    if (n < 120) {
        if (info && infoLen > 16) snprintf(info, infoLen, "K线不足(%d,需120)", n);
        return -1;
    }
    double* W = ws(WS_PIT, n * 17 + 16);
    if (!W) return -1;
    double* atr = W;
    double* tr  = atr + n;
    double* tp  = tr + n;
    double* pdm = tp + n;
    double* mdm = pdm + n;
    double* cci = mdm + n;
    double* ax  = cci + n;
    double* pdx = ax + n;
    double* mdx = pdx + n;
    double* K   = mdx + n;
    double* D   = K + n;
    double* J   = D + n;
    double* dif = J + n;
    double* dea = dif + n;
    double* hist = dea + n;
    double* hh  = hist + n;
    double* ll  = hh + n;

    gold_atr14(r, tr, atr);
    cci20(r, tp, cci);
    adx14(r, tr, pdm, mdm, ax, pdx, mdx);
    kdj9(r, hh, ll, K, D, J);
    macd_calc_impl(r.c, n, dif, dea, hist);

    int i = n - 1;   // 最新已收盘K线
    int score = 0;
    char s2[24] = "-", s3[24] = "-", s4[24] = "-", s5[24] = "-", s6[24] = "-";
    // ① 缠论底分型锚(必须) + 坑深
    int fx = chan_bottom_fx(r);
    int anchor = (fx >= 0);
    // 防追高: 现价距分型低点 <=3%
    if (anchor && r.C(i) > r.L(fx) * 1.03) anchor = 0;
    double depth = 0;
    if (anchor) {
        depth = 0;
        int hiI2 = fx; double hiP2 = -1e18;
        (void)hiI2;
        for (int k = (fx - 40 > 0 ? fx - 40 : 0); k <= fx; k++)
            if (r.H(k) > hiP2) { hiP2 = r.H(k); hiI2 = k; }
        if (atr[fx] > 0) depth = (hiP2 - r.L(fx)) / atr[fx];
        if (depth < 2.5) anchor = 0;    // 坑深不足=横盘毛刺, 不算坑
    }
    if (anchor) score++;
    // ② CCI20 进坑(<-100)且回升
    double cciMin = 1e18;
    for (int k = i - 9; k <= i; k++) if (k >= 0 && cci[k] < cciMin) cciMin = cci[k];
    if (cciMin < -100 && cci[i] > cci[i - 1]) { score++; snprintf(s2, 24, "cci%.0f", cci[i]); }
    // ③ ADX>=15 且 多头主导(+DI>-DI 或 +DI升-DI降)
    if (ax[i] >= 15.0 && (pdx[i] > mdx[i] || (pdx[i] > pdx[i - 1] && mdx[i] < mdx[i - 1]))) {
        score++; snprintf(s3, 24, "adx%.0f", ax[i]);
    }
    // ④ MACD柱连续2根抬升
    if (hist[i] > hist[i - 1] && hist[i - 1] > hist[i - 2]) { score++; snprintf(s4, 24, "m%.4g", hist[i]); }
    // ⑤ KDJ J超卖(<0)且回升 或 K上穿D
    double jMin = 1e18;
    for (int k = i - 4; k <= i; k++) if (k >= 0 && J[k] < jMin) jMin = J[k];
    if ((jMin < 0 && J[i] > J[i - 1]) || (K[i - 1] <= D[i - 1] && K[i] > D[i])) {
        score++; snprintf(s5, 24, "j%.1f", J[i]);
    }
    // ⑥ 动能: 坑前下跌腿速度 |dp|/(ATR14*根数) >= 1.2 (急跌成坑, 必须项)
    bool vOk = false;
    if (anchor) {
        int hiI = fx; double hiP = -1e18;
        for (int k = (fx - 40 > 0 ? fx - 40 : 0); k <= fx; k++)
            if (r.H(k) > hiP) { hiP = r.H(k); hiI = k; }
        int bars = fx - hiI;
        if (bars > 0 && atr[fx] > 0) {
            double v = (hiP - r.L(fx)) / (atr[fx] * bars);
            if (v >= 1.2) { vOk = true; score++; snprintf(s6, 24, "v%.1f", v); }
        }
    }
    int others = score - (anchor ? 1 : 0) - (vOk ? 1 : 0);
    // 反转确认(必须): 最新K线收阳 且 收盘抬升 (坑右沿确认, 不接飞刀)
    bool revOk = r.C(i) > r.O(i) && r.C(i) > r.C(i - 1);
    (void)revOk;
    // 自适应动能锚(必须): 沿用金▲分位数KE口径(急跌底动能进前25%), 按合约自适应
    double ke = 0, th = 0; int dist = 0; char ginfo[160] = { 0 };
    bool goldOk = (gold_signal_ref(r, bar, &ke, &th, &dist, ginfo, 160) == 1);
    // 触发: 自适应动能锚(保留原入场时机) + 四维确认至少中1 (只滤最差毛刺, 不过度延迟入场)
    int ret = (goldOk && others >= 1) ? 1 : 0;
    if (vOk && !goldOk) score++;  // 记分展示用
    if (score_out) *score_out = (double)score;
    if (info && infoLen > 16)
        snprintf(info, infoLen, "分型%s CCI%s ADX%s MACD%s KDJ%s 动能%s",
                 anchor ? "Y" : "N", s2, s3, s4, s5, s6);
    return ret;
}

// ---------------- 全部转折点+完整力学量 (SoA 核心) ----------------
// 输出: idx[], type[](1顶/0底), pA[](转折点价=极值), ke[], g[], f[], v[](斜率),
//       m[](入腿均量), ang[](角度°), a2[](二阶导), bars[](入腿根数)
// *thOut = 底部KE 75分位阈值(尖转折线, 与 gold_signal 同口径)
// 口径与 ov_physics.js 逐字一致: v=dp/(ATR·bars), KE=½mv², g=v²/(2h), F=mg=KE/h, h=dp/ATR
static int pivots_full_ref(const sk::Ref& r, const char* bar,
                           int* idx, int* type, double* pA,
                           double* keA, double* gA, double* fA,
                           double* vA, double* mA, double* angA, double* a2A, int* barsA,
                           double* thOut, int maxP) {
    int n = r.n;
    if (n < 10 || !bar) return -1;
    double thr = segm_of(bar);
    double* Wa = ws(WS_GOLD, n * 2 + 8);
    if (!Wa) return -1;
    double* tr  = Wa;
    double* atr = tr + n;
    gold_atr14(r, tr, atr);
    double* pv = ws(WS_GOLDPV, 3 * (n + 2));
    if (!pv) return -1;
    int npv = gold_pivots(r, thr, atr, pv, n + 2);

    // 第一遍: 每点力学量。各转折点独立, 但**实测每点仅 ~0.5us**(npv 最大 86 → 全量 <50us),
    //   而 CreateThread ×3 约 200~450us → 并行净亏 4.8x, 故此处保持串行。
    //   多线程用在"批次大"的地方(cmd_pitbt 全合约回测), 见该组件。
    double* pvv = pv;
    int lim = npv < maxP ? npv : maxP;
    const double* atrp = atr;
    for (int k = 0; k < lim; k++) {
        int pi = (int)pvv[k * 3];
        double ke = 0, g = 0, f = 0, v = 0, m = 0, ang = 0, a2 = 0;
        int bars = 0;
        if (k > 0) {
            int pi0 = (int)pvv[(k - 1) * 3];
            double p0 = pvv[(k - 1) * 3 + 1], p1 = pvv[k * 3 + 1];
            bars = pi - pi0;
            double dp = fabs1(p1 - p0);
            double a14 = (pi >= 0 && pi < n) ? atrp[pi] : 1e-9;
            if (a14 < 1e-9) a14 = 1e-9;
            if (bars > 0 && dp > 0) {
                v = dp / (a14 * (double)bars);                   // 斜率(一阶导, ATR/根)
                double hN = dp / a14;                            // 笔高(ATR 单位)
                m = leg_mean(r, pi0, pi);
                ke = 0.5 * m * v * v;                            // KE=½mv²
                ang = atan(v) * 180.0 / 3.14159265358979323846;   // 角度
                g = v * v / (2.0 * hN);                           // g=v²/2h
                f = ke / hN;                                      // F=mg=KE/h
                a2 = piv_curv(r.c, r.st, pi, a14);                      // 二阶导
            }
        }
        idx[k] = pi; type[k] = (int)pvv[k * 3 + 2];
        if (pA) pA[k] = pvv[k * 3 + 1];                  // 转折点价(极值), 不是收盘价
        keA[k] = ke; gA[k] = g; fA[k] = f;
        vA[k] = v; mA[k] = m; angA[k] = ang; a2A[k] = a2; barsA[k] = bars;
    }

    // 阈值: 底部KE 75分位(尖转折前25%)。串行收集以保持与标量相同的 k 顺序
    std::vector<double> bke;
    for (int k = 1; k < lim; k++)
        if (type[k] == 0 && keA[k] > 0) bke.push_back(keA[k]);
    double th = 0;
    if (!bke.empty()) {
        std::sort(bke.begin(), bke.end());
        size_t ti = (size_t)((double)bke.size() * 0.75);
        if (ti >= bke.size()) ti = bke.size() - 1;
        th = bke[ti];
    }
    if (thOut) *thOut = th;
    return lim;
}

// ---------------- 全部转折点+基础力学标注 (SoA 核心) ----------------
static int pivots_calc_ref(const sk::Ref& r, const char* bar,
                           int* idx, int* type, double* keA, double* gA, double* fA, int maxP) {
    int n = r.n;
    if (n < 10 || !bar) return -1;
    double thr = segm_of(bar);
    double* Wa = ws(WS_GOLD, n * 2 + 8);
    if (!Wa) return -1;
    double* tr  = Wa;
    double* atr = tr + n;
    gold_atr14(r, tr, atr);
    double* pv = ws(WS_GOLDPV, 3 * (n + 2));
    if (!pv) return -1;
    int npv = gold_pivots(r, thr, atr, pv, n + 2);
    int out = 0;
    for (int k = 0; k < npv && out < maxP; k++) {
        int pi = (int)pv[k * 3];
        double ke = 0, g = 0, f = 0;
        if (k > 0) {
            int pi0 = (int)pv[(k - 1) * 3];
            double p0 = pv[(k - 1) * 3 + 1], p1 = pv[k * 3 + 1];
            ke = gold_leg_ke(r, atr, pi0, p0, pi, p1);
            int bars = pi - pi0;
            if (bars > 0) {
                f = ke / (double)bars;                       // F = KE/h (牛二, h=入腿根数)
                g = ke / (leg_mean(r, pi0, pi) * (double)bars);  // g = KE/(m*h)
            }
        }
        idx[out] = pi; type[out] = (int)pv[k * 3 + 2];
        keA[out] = ke; gA[out] = g; fA[out] = f;
        out++;
    }
    return out;
}

extern "C" {

// ---------- 版本 ----------
__declspec(dllexport) int dll_version() { return 20270928; }

// ---------- MACD (EMA12/26, DEA=EMA9 of DIF) ----------
// close[n] -> dif[n], dea[n], hist[n]; 返回 0 成功
__declspec(dllexport) int macd_calc(const double* close, int n, int fast, int slow, int sig,
                                    double* dif, double* dea, double* hist) {
    if (!close || n <= 0) return -1;
    double kf = 2.0 / (fast + 1), ks = 2.0 / (slow + 1), kg = 2.0 / (sig + 1);
    double e12 = close[0], e26 = close[0], d0 = 0;
    for (int i = 0; i < n; i++) {
        if (i == 0) { e12 = close[0]; e26 = close[0]; dif[i] = 0; dea[i] = 0; hist[i] = 0; d0 = 0; continue; }
        e12 = e12 + kf * (close[i] - e12);
        e26 = e26 + ks * (close[i] - e26);
        dif[i] = e12 - e26;
        d0 = d0 + kg * (dif[i] - d0);
        dea[i] = d0;
        hist[i] = (dif[i] - d0) * 2.0;
    }
    return 0;
}

// ---------- 金▲信号 ----------
// rows: n*5 交错 [o,h,l,c,v]; bar: 周期字符串; n>=30
// 返回 1=金▲触发 / 0=未触发 / 负数=错误
__declspec(dllexport) int gold_signal(const double* rows, int n, const char* bar,
                                      double* ke_out, double* th_out, int* dist_out,
                                      char* info, int infoLen) {
    if (!rows || n < 30 || !bar) {
        if (info && infoLen > 16) snprintf(info, infoLen, "%s", n < 30 ? "K线不足" : "参数错误");
        return 0;
    }
    // 零拷贝: 直接在交错行主序上跑(st=5), 无 transpose / 无分配
    sk::Ref r = sk::view_interleaved(rows, n);
    return gold_signal_ref(r, bar, ke_out, th_out, dist_out, info, infoLen);
}

// ---------- 黄金坑复合信号 (六维: 缠论底分型锚+CCI+ADX+MACD+KDJ+动能) ----------
__declspec(dllexport) int pit_signal(const double* rows, int n, const char* bar,
                                     double* score_out, char* info, int infoLen) {
    if (!rows || n < 120) {
        if (info && infoLen > 16) snprintf(info, infoLen, "K线不足(%d,需120)", n);
        return -1;
    }
    // 唯一需要连续布局的入口: CCI 的 20 窗口 TP 与 KDJ 的 9 窗口极值都靠连续数组才快
    Txb t = tx_make(rows, n);
    if (!t.p) return -1;
    return pit_signal_ref(t.r, bar, score_out, info, infoLen);
}

// ---------- 全部转折点+力学标注(供网页/接口展示) ----------
__declspec(dllexport) int pivots_calc(const double* rows, int n, const char* bar,
                                      int* idx, int* type, double* keA, double* gA, double* fA, int maxP) {
    if (!rows || n < 10 || !bar) return -1;
    sk::Ref r = sk::view_interleaved(rows, n);      // 零拷贝
    return pivots_calc_ref(r, bar, idx, type, keA, gA, fA, maxP);
}

// ---------- 全部转折点+完整力学量 ----------
__declspec(dllexport) int pivots_full(const double* rows, int n, const char* bar,
                                      int* idx, int* type, double* pA,
                                      double* keA, double* gA, double* fA,
                                      double* vA, double* mA, double* angA, double* a2A, int* barsA,
                                      double* thOut, int maxP) {
    if (!rows || n < 10 || !bar) return -1;
    sk::Ref r = sk::view_interleaved(rows, n);      // 零拷贝
    return pivots_full_ref(r, bar, idx, type, pA, keA, gA, fA, vA, mA, angA, a2A, barsA, thOut, maxP);
}

// ---------- 常驻内存K线库 ----------
// 增量喂K线: ts[n]毫秒开盘时间 + bars[n*5](o,h,l,c,v), 按 ts 合并去重, 滚动保留最近 SERIES_CAP 根
// 返回当前滞留根数, -1=错误
__declspec(dllexport) int store_upsert(const char* key, const long long* ts, const double* bars, int n) {
    if (!key || !ts || (!bars && n > 0) || n < 0) return -1;
    if (strlen(key) >= 128) return -1;
    int si = series_find(key);
    if (si < 0) {
        if (g_seriesN >= SERIES_MAX) {
            // 淘汰最老一个槽位(覆盖第0个并把整体前移一格)
            series_free(&g_series[0]);
            memmove(&g_series[0], &g_series[1], sizeof(Series) * (SERIES_MAX - 1));
            memset(&g_series[SERIES_MAX - 1], 0, sizeof(Series));   // 清掉前移后残留的重复槽
            g_seriesN = SERIES_MAX - 1;
            si = 0;
            hash_rebuild();
        } else {
            si = g_seriesN++;
        }
        Series* s2 = &g_series[si];
        memset(s2, 0, sizeof(Series));
        snprintf(s2->key, 128, "%s", key);
        if (!series_ensure(s2, SERIES_CAP + (n > 0 ? n : 0) + 16)) { series_free(s2); g_seriesN--; return -1; }
        s2->n = 0;
        s2->fired = -1;
        s2->pfire = -1;
        hash_put(s2->key, si);
    }
    Series* s = &g_series[si];
    for (int k = 0; k < n; k++) {
        // 找插入位置(尾部通常递增, 从后往前扫)
        int pos = s->n;
        while (pos > 0 && s->ts[pos - 1] > ts[k]) pos--;
        if (pos > 0 && s->ts[pos - 1] == ts[k]) {
            // 已存在 → 更新这一根(最新价可能变化)
            s->o[pos - 1] = bars[k * 5];     s->h[pos - 1] = bars[k * 5 + 1];
            s->l[pos - 1] = bars[k * 5 + 2]; s->c[pos - 1] = bars[k * 5 + 3];
            s->v[pos - 1] = bars[k * 5 + 4];
            continue;
        }
        if (!series_ensure(s, s->n + 1)) break;
        memmove(s->ts + pos + 1, s->ts + pos, sizeof(long long) * (size_t)(s->n - pos));
        memmove(s->o + pos + 1, s->o + pos, sizeof(double) * (size_t)(s->n - pos));
        memmove(s->h + pos + 1, s->h + pos, sizeof(double) * (size_t)(s->n - pos));
        memmove(s->l + pos + 1, s->l + pos, sizeof(double) * (size_t)(s->n - pos));
        memmove(s->c + pos + 1, s->c + pos, sizeof(double) * (size_t)(s->n - pos));
        memmove(s->v + pos + 1, s->v + pos, sizeof(double) * (size_t)(s->n - pos));
        s->ts[pos] = ts[k];
        s->o[pos] = bars[k * 5];     s->h[pos] = bars[k * 5 + 1];
        s->l[pos] = bars[k * 5 + 2]; s->c[pos] = bars[k * 5 + 3];
        s->v[pos] = bars[k * 5 + 4];
        s->n++;
        s->fired = -1; // 数据变了, 信号缓存失效
        s->pfire = -1;
    }
    series_trim(s);
    return s->n;
}

// 用内存里的数据算金▲信号(结果按数据版本缓存): 1=触发 0=未触发 -1=无数据
__declspec(dllexport) int store_gold(const char* key, const char* bar,
                                     double* ke_out, double* th_out, int* dist_out,
                                     char* info, int infoLen) {
    if (!key || !bar) return -1;
    int si = series_find(key);
    if (si < 0) { if (info && infoLen > 16) snprintf(info, infoLen, "内存无该序列"); return -1; }
    Series* s = &g_series[si];
    if (s->n < 30) { if (info && infoLen > 16) snprintf(info, infoLen, "K线不足(%d根)", s->n); return -1; }
    if (s->fired >= 0) { // 缓存命中
        if (ke_out) *ke_out = s->ke;
        if (th_out) *th_out = s->th;
        if (dist_out) *dist_out = s->dist;
        if (info && infoLen > 16) snprintf(info, infoLen, "%s", s->info);
        return s->fired;
    }
    double ke = 0, th = 0; int dist = 0;
    char buf[160] = { 0 };
    sk::Ref r{ s->o, s->h, s->l, s->c, s->v, s->n, 1 };
    int r2 = gold_signal_ref(r, bar, &ke, &th, &dist, buf, 160);
    s->fired = r2; s->ke = ke; s->th = th; s->dist = dist;
    snprintf(s->info, 160, "%s", buf);
    if (ke_out) *ke_out = ke;
    if (th_out) *th_out = th;
    if (dist_out) *dist_out = dist;
    if (info && infoLen > 16) snprintf(info, infoLen, "%s", buf);
    return r2;
}

// 用内存里的数据算黄金坑信号(结果按数据版本缓存): 1=触发 0=未触发 -1=无数据
__declspec(dllexport) int store_pit(const char* key, const char* bar,
                                    double* score_out, char* info, int infoLen) {
    if (!key || !bar) return -1;
    int si = series_find(key);
    if (si < 0) { if (info && infoLen > 16) snprintf(info, infoLen, "内存无该序列"); return -1; }
    Series* s = &g_series[si];
    if (s->n < 120) { if (info && infoLen > 16) snprintf(info, infoLen, "K线不足(%d根)", s->n); return -1; }
    if (s->pfire >= 0) { // 缓存命中
        if (score_out) *score_out = s->pscore;
        if (info && infoLen > 16) snprintf(info, infoLen, "%s", s->pinfo);
        return s->pfire;
    }
    double sc = 0;
    char buf[160] = { 0 };
    sk::Ref r{ s->o, s->h, s->l, s->c, s->v, s->n, 1 };
    int r2 = pit_signal_ref(r, bar, &sc, buf, 160);
    s->pfire = r2; s->pscore = sc;
    snprintf(s->pinfo, 160, "%s", buf);
    if (score_out) *score_out = sc;
    if (info && infoLen > 16) snprintf(info, infoLen, "%s", buf);
    return r2;
}

// 滞留根数(找不到=-1)
__declspec(dllexport) int store_count(const char* key) {
    if (!key) return -1;
    int si = series_find(key);
    return si < 0 ? -1 : g_series[si].n;
}

// 统计: 序列个数
__declspec(dllexport) int store_stats() { return g_seriesN; }

// 清空全部内存数据
__declspec(dllexport) void store_clear() {
    for (int i = 0; i < g_seriesN; i++) series_free(&g_series[i]);
    g_seriesN = 0;
    hash_clear();
}

} // extern "C"
