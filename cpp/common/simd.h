// ==================================================================
// simd.h — 性能内核公共组件 (2026-09-28 用户指令: C++ + 正确内存布局 + SIMD + 多线程)
//
//   ① 内存布局 SoA: 每字段一条独立 64 字节对齐数组(o/h/l/c/v)
//      取代交错 stride=5 —— 向量化零 gather、顺序扫描缓存友好
//   ② SIMD: AVX2 运行时分发(__cpuid + xgetbv 探测 OS 支持, 无 AVX2 自动回退标量)
//      内核只做 max/min/掩码选择/对齐取数 —— 不引入任何浮点重结合, 位精确
//      (需要位精确的归约/递推一律保留标量顺序: sum_seq / atr / adx / ema)
//   ③ 多线程: par_for —— Win32 CreateThread, 不依赖 std::thread 线程模型
//
//   头文件-only, 各组件直接 include, 不改链接参数
// ==================================================================
#pragma once
#include <cstdlib>
#include <cstring>
#include <cmath>
#include <cstdint>
#include <malloc.h>
#include <intrin.h>
#include <immintrin.h>
#include <vector>
#include <windows.h>

#define PAR_MAX_T 64        // 并行线程上限(Win32 WaitForMultipleObjects 上限 64)

namespace sk {

// ---------------- CPU 能力探测(进程内只算一次) ----------------
inline unsigned long long xgetbv0() {
    unsigned int eax = 0, edx = 0;
    __asm__ volatile("xgetbv" : "=a"(eax), "=d"(edx) : "c"(0));
    return ((unsigned long long)edx << 32) | (unsigned long long)eax;
}

struct Cpu {
    bool sse2 = true, avx = false, avx2 = false, fma = false;
    int  hwThreads = 1;
    const char* tag = "scalar";
    Cpu() {
        int r[4] = { 0, 0, 0, 0 };
        __cpuid(r, 1);
        sse2 = (r[3] & (1 << 26)) != 0;
        bool osxsave = (r[2] & (1 << 27)) != 0;
        bool avxFlag = (r[2] & (1 << 28)) != 0;
        if (osxsave && avxFlag && (xgetbv0() & 0x6) == 0x6) {   // OS 已保存 XMM/YMM
            avx = true;
            __cpuid(r, 7);
            bool a2 = (r[1] & (1 << 5)) != 0;                   // EBX.AVX2
            __cpuid(r, 1);
            bool fm = (r[2] & (1 << 12)) != 0;                  // ECX.FMA
            avx2 = a2;
            fma = a2 && fm;
            tag = avx2 ? (fma ? "avx2+fma" : "avx2") : "avx";
        } else if (sse2) {
            tag = "sse2";
        }
        SYSTEM_INFO si;
        GetSystemInfo(&si);
        int hw = (int)si.dwNumberOfProcessors;
        hwThreads = hw > 0 ? hw : 1;
        if (hwThreads > PAR_MAX_T) hwThreads = PAR_MAX_T;
    }
};
inline const Cpu& cpu() { static Cpu c; return c; }

// ---------------- 对齐内存 ----------------
inline double* dalloc(size_t n, size_t alignB = 64) {
    if (n == 0) n = 1;
    return (double*)_aligned_malloc(n * sizeof(double), alignB);
}
inline void dfree(double* p) { if (p) _aligned_free(p); }
inline long long* llalloc(size_t n, size_t alignB = 64) {
    if (n == 0) n = 1;
    return (long long*)_aligned_malloc(n * sizeof(long long), alignB);
}
inline void llfree(long long* p) { if (p) _aligned_free(p); }

// ---------------- SoA 内存布局 ----------------
struct SoA {
    double* o = nullptr; double* h = nullptr; double* l = nullptr;
    double* c = nullptr; double* v = nullptr;
    int n = 0, cap = 0;

    void alloc(int capN) {
        if (capN <= cap) { n = 0; return; }
        free();
        o = dalloc((size_t)capN); h = dalloc((size_t)capN); l = dalloc((size_t)capN);
        c = dalloc((size_t)capN); v = dalloc((size_t)capN);
        cap = capN; n = 0;
    }
    void free() {
        dfree(o); dfree(h); dfree(l); dfree(c); dfree(v);
        o = h = l = c = v = nullptr; cap = 0; n = 0;
    }
    // 从交错布局 rows[i*5 + {o,h,l,c,v}] 装入(纯数据搬运, 无浮点运算 → 位精确)
    void load(const double* rows, int cnt) {
        if (!rows || cnt <= 0) { n = 0; return; }
        if (cnt > cap) alloc(cnt);
        for (int i = 0; i < cnt; i++) {
            o[i] = rows[i * 5];     h[i] = rows[i * 5 + 1]; l[i] = rows[i * 5 + 2];
            c[i] = rows[i * 5 + 3]; v[i] = rows[i * 5 + 4];
        }
        n = cnt;
    }
    ~SoA() { free(); }
};

// 从「分离的 5 条数组」引用(常驻库, 零拷贝)
//   st==1  连续 SoA: 每条数组紧密排列(常驻内存库用, SIMD 全速)
//   st>1   交错行主序: 5 条指针指向同一行的相邻元素(如 rows+0..rows+4, st=5)
//          → extern "C" 入口可零拷贝直接跑算法; SIMD 内核仅在 st==1 时启用
struct Ref {
    const double *o, *h, *l, *c, *v;
    int n;
    int st = 1;                 // 默认连续; 忘记赋值也不会退化成乱步长(默认成员初始化兜底)
    inline double O(int i) const { return o[(size_t)i * (size_t)st]; }
    inline double H(int i) const { return h[(size_t)i * (size_t)st]; }
    inline double L(int i) const { return l[(size_t)i * (size_t)st]; }
    inline double C(int i) const { return c[(size_t)i * (size_t)st]; }
    inline double V(int i) const { return v[(size_t)i * (size_t)st]; }
};
// 交错行主序视图(零拷贝): rows[i*5+{0..4}]
inline Ref view_interleaved(const double* rows, int n) {
    Ref r;
    r.o = rows; r.h = rows + 1; r.l = rows + 2; r.c = rows + 3; r.v = rows + 4;
    r.n = n; r.st = 5;
    return r;
}

// ---------------- AVX2 内核(target 属性单函数开启, 不污染全局编译参数) ----------------
// TR[i] = max(h-l, |h-pc|, |l-pc|) —— 只做 sub/abs/max, 无重结合 → 与标量位精确同值
__attribute__((target("avx2")))
inline void tr_avx2(const double* h, const double* l, const double* c, double* tr, int n) {
    int i = 1;
    const __m256d sgn = _mm256_set1_pd(-0.0);          // andnot 之后 = 绝对值掩码
    for (; i + 4 <= n; i += 4) {
        __m256d vh  = _mm256_loadu_pd(h + i);
        __m256d vl  = _mm256_loadu_pd(l + i);
        __m256d vpc = _mm256_loadu_pd(c + i - 1);
        __m256d hl  = _mm256_sub_pd(vh, vl);
        __m256d dh  = _mm256_andnot_pd(sgn, _mm256_sub_pd(vh, vpc));
        __m256d dl  = _mm256_andnot_pd(sgn, _mm256_sub_pd(vl, vpc));
        _mm256_storeu_pd(tr + i, _mm256_max_pd(hl, _mm256_max_pd(dh, dl)));
    }
    for (; i < n; i++) {
        double pc = c[i - 1], t = h[i] - l[i], d;
        d = h[i] - pc; if (d < 0) d = -d; if (d > t) t = d;
        d = l[i] - pc; if (d < 0) d = -d; if (d > t) t = d;
        tr[i] = t;
    }
}

// +DM / -DM (Wilder): 掩码选择, 无算术重结合 → 位精确
__attribute__((target("avx2")))
inline void dm_avx2(const double* h, const double* l, double* pdm, double* mdm, int n) {
    const __m256d zero = _mm256_setzero_pd();
    int i = 1;
    for (; i + 4 <= n; i += 4) {
        __m256d vh  = _mm256_loadu_pd(h + i);
        __m256d vl  = _mm256_loadu_pd(l + i);
        __m256d vhp = _mm256_loadu_pd(h + i - 1);
        __m256d vlp = _mm256_loadu_pd(l + i - 1);
        __m256d up = _mm256_sub_pd(vh, vhp);            // h[i]-h[i-1]
        __m256d dn = _mm256_sub_pd(vlp, vl);            // l[i-1]-l[i]
        __m256d mu = _mm256_and_pd(_mm256_cmp_pd(up, dn,   _CMP_GT_OQ),
                                   _mm256_cmp_pd(up, zero, _CMP_GT_OQ));
        __m256d md = _mm256_and_pd(_mm256_cmp_pd(dn, up,   _CMP_GT_OQ),
                                   _mm256_cmp_pd(dn, zero, _CMP_GT_OQ));
        _mm256_storeu_pd(pdm + i, _mm256_and_pd(up, mu));
        _mm256_storeu_pd(mdm + i, _mm256_and_pd(dn, md));
    }
    for (; i < n; i++) {
        double up = h[i] - h[i - 1], dn = l[i - 1] - l[i];
        pdm[i] = (up > dn && up > 0) ? up : 0;
        mdm[i] = (dn > up && dn > 0) ? dn : 0;
    }
}

// TP[i] = (h+l+c)/3 —— 纯逐元素(无重结合), 编译器自动 AVX2 化
inline void tp_avx(const double* h, const double* l, const double* c, double* tp, int n) {
    for (int i = 0; i < n; i++) tp[i] = (h[i] + l[i] + c[i]) / 3.0;
}

// ---------------- 标量/分发封装 ----------------
inline void tr_soa(const Ref& s, double* tr) {
    const int n = s.n;
    if (n <= 0) return;
    tr[0] = s.H(0) - s.L(0);
    if (s.st == 1 && n >= 16 && cpu().avx2) { tr_avx2(s.h, s.l, s.c, tr, n); return; }
    for (int i = 1; i < n; i++) {
        double pc = s.C(i - 1), t = s.H(i) - s.L(i), d;
        d = s.H(i) - pc; if (d < 0) d = -d; if (d > t) t = d;
        d = s.L(i) - pc; if (d < 0) d = -d; if (d > t) t = d;
        tr[i] = t;
    }
}
inline void dm_soa(const Ref& s, double* pdm, double* mdm) {
    const int n = s.n;
    if (n <= 0) return;
    pdm[0] = 0; mdm[0] = 0;
    if (s.st == 1 && n >= 16 && cpu().avx2) { dm_avx2(s.h, s.l, pdm, mdm, n); return; }
    for (int i = 1; i < n; i++) {
        double up = s.H(i) - s.H(i - 1), dn = s.L(i - 1) - s.L(i);
        pdm[i] = (up > dn && up > 0) ? up : 0;
        mdm[i] = (dn > up && dn > 0) ? dn : 0;
    }
}
// TP[i] = (h+l+c)/3 —— 连续时纯逐元素(编译器自动 AVX2 化)
inline void tp_soa(const Ref& s, double* tp) {
    const int n = s.n;
    if (s.st == 1) {
        for (int i = 0; i < n; i++) tp[i] = (s.h[i] + s.l[i] + s.c[i]) / 3.0;
        return;
    }
    for (int i = 0; i < n; i++) tp[i] = (s.H(i) + s.L(i) + s.C(i)) / 3.0;
}

// 顺序求和(位精确: 不做 SIMD 归约, 保持与标量逐字相同顺序)
inline double sum_st(const double* v, int st, int i0, int i1) {
    double s = 0;
    for (int i = i0; i < i1; i++) s += v[(size_t)i * (size_t)st];
    return s;
}
inline double sum_seq(const double* v, int i0, int i1) { return sum_st(v, 1, i0, i1); }
inline double max_of(const double* v, int st, int i0, int i1, double init = -1e300) {
    double m = init;
    for (int i = i0; i < i1; i++) { double x = v[(size_t)i * (size_t)st]; if (x > m) m = x; }
    return m;
}
inline double min_of(const double* v, int st, int i0, int i1, double init = 1e300) {
    double m = init;
    for (int i = i0; i < i1; i++) { double x = v[(size_t)i * (size_t)st]; if (x < m) m = x; }
    return m;
}

// 滚动窗口 max/min — 单调队列 O(n)(纯比较, 结果与朴素 O(n·w) 完全一致)
inline void roll_max_st(const double* v, int st, int n, int w, double* out) {
    if (n <= 0 || w <= 0) return;
    std::vector<int> q((size_t)n + 1);
    int head = 0, tail = 0;                       // 队列区间 [head, tail)
    for (int i = 0; i < n; i++) {
        while (tail > head && v[(size_t)q[(size_t)tail - 1] * (size_t)st] <= v[(size_t)i * (size_t)st]) tail--;
        q[(size_t)tail++] = i;
        if (q[(size_t)head] <= i - w) head++;
        out[i] = v[(size_t)q[(size_t)head] * (size_t)st];
    }
}
inline void roll_min_st(const double* v, int st, int n, int w, double* out) {
    if (n <= 0 || w <= 0) return;
    std::vector<int> q((size_t)n + 1);
    int head = 0, tail = 0;
    for (int i = 0; i < n; i++) {
        while (tail > head && v[(size_t)q[(size_t)tail - 1] * (size_t)st] >= v[(size_t)i * (size_t)st]) tail--;
        q[(size_t)tail++] = i;
        if (q[(size_t)head] <= i - w) head++;
        out[i] = v[(size_t)q[(size_t)head] * (size_t)st];
    }
}
inline void roll_max(const double* v, int n, int w, double* out) { roll_max_st(v, 1, n, w, out); }
inline void roll_min(const double* v, int n, int w, double* out) { roll_min_st(v, 1, n, w, out); }

// ---------------- 多线程 par_for (Win32 CreateThread) ----------------
struct ParCtx {
    void (*fn)(int, void*);
    void* ud;
    int n, stride, start;
};
inline DWORD WINAPI par_worker(LPVOID p) {
    ParCtx* c = (ParCtx*)p;
    for (int i = c->start; i < c->n; i += c->stride) c->fn(i, c->ud);
    return 0;
}
// 并行 for: body(i) 之间必须互不依赖(各自内部保持自己的顺序 → 位精确)
// nth<=1 或 n<minN 时退化为串行; 调用方可显式传 minN 控制线程开销阈值
template <class F>
inline void par_for(int n, F&& body, int nth = 0, int minN = 24) {
    if (n <= 0) return;
    if (nth <= 0) nth = cpu().hwThreads;
    if (nth <= 1 || n < minN) { for (int i = 0; i < n; i++) body(i); return; }
    if (nth > n) nth = n;
    void (*fn)(int, void*) = [](int i, void* ud) { (*static_cast<F*>(ud))(i); };
    HANDLE hd[PAR_MAX_T];
    ParCtx ctx[PAR_MAX_T];
    int created = 0;
    for (int t = 1; t < nth; t++) {
        ctx[t].fn = fn; ctx[t].ud = (void*)&body;
        ctx[t].n = n; ctx[t].stride = nth; ctx[t].start = t;
        HANDLE h = CreateThread(NULL, 0, par_worker, &ctx[t], 0, NULL);
        if (h) hd[created++] = h;
    }
    for (int i = 0; i < n; i += nth) body(i);
    if (created > 0) {
        WaitForMultipleObjects((DWORD)created, hd, TRUE, INFINITE);
        for (int i = 0; i < created; i++) CloseHandle(hd[i]);
    }
}

} // namespace sk
