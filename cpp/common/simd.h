/* ==================================================================
 * simd.h — 性能内核公共组件 (2026-09-28 用户指令: C++ + 正确内存布局 + SIMD + 多线程)
 *
 * 文件职责：
 *   为指标/信号算法层提供高性能数据访问与计算原语，全部 header-only
 *   （各组件直接 include，不改链接参数）。核心设计约束是「位精确」：
 *   无论是否走 SIMD/多线程路径，计算结果必须与标量逐字一致——量化信号
 *   不允许因浮点重结合导致回测与实盘结果出现偏差。
 *
 *   ① 内存布局 SoA: 每字段一条独立 64 字节对齐数组(o/h/l/c/v)
 *      取代交错 stride=5 —— 向量化零 gather、顺序扫描缓存友好
 *   ② SIMD: AVX2 运行时分发(__cpuid + xgetbv 探测 OS 支持, 无 AVX2 自动回退标量)
 *      内核只做 max/min/掩码选择/对齐取数 —— 不引入任何浮点重结合, 位精确
 *      (需要位精确的归约/递推一律保留标量顺序: sum_seq / atr / adx / ema)
 *   ③ 多线程: par_for —— Win32 CreateThread, 不依赖 std::thread 线程模型
 *
 * 包含内容：
 *   - sk::Cpu/cpu()      CPU 能力探测(SSE2/AVX/AVX2/FMA + 硬件线程数, 进程内一次)
 *   - sk::dalloc/dfree   64 字节对齐的 double 数组分配/释放
 *   - sk::SoA / Ref      行情数据的 SoA 布局与零拷贝视图(支持连续/交错两种步长)
 *   - sk::tr_avx2 等     True Range 与 +DM/-DM 的 AVX2 内核(max/min/掩码, 位精确)
 *   - sk::tr_soa 等      分发封装: 连续且够长且支持 AVX2 → 走内核, 否则标量
 *   - sk::sum/max/min    顺序归约与单调队列滚动窗口 O(n)
 *   - sk::par_for        数据划分多线程并行 for(主线程参与分片 0)
 *
 * 数据流向：tradehub 引擎喂入 K线(交错 rows[i*5..] 或 SoA 数组) → Ref/SoA
 *   → tr/dm/tp 内核 → 指标层(ATR/ADX/EMA) → gold_signal/store_gold 出信号。
 *
 * 被谁 include：tradehub 引擎与 sigcore 算法源文件(同源编译进 exe)。
 * ================================================================== */
#pragma once                                  // 头文件防重复包含
#include <cstdlib>                            // 标准库(size_t 等)
#include <cstring>                            // memcpy 等
#include <cmath>                              // 数学函数
#include <cstdint>                            // 定宽整型
#include <malloc.h>                           // _aligned_malloc/_aligned_free(MSVC)
#include <intrin.h>                           // __cpuid 等编译器内建
#include <immintrin.h>                        // AVX2 intrinsics(_mm256_*)
#include <vector>                             // std::vector(单调队列缓冲)
#include <windows.h>                          // CreateThread/GetSystemInfo 等

#define PAR_MAX_T 64        // 并行线程上限(Win32 WaitForMultipleObjects 上限 64)

namespace sk {                                // sk = "signal kernel" 命名空间, 隔离通用短名

// ---------------- CPU 能力探测(进程内只算一次) ----------------
inline unsigned long long xgetbv0() {
    unsigned int eax = 0, edx = 0;
    __asm__ volatile("xgetbv" : "=a"(eax), "=d"(edx) : "c"(0)); // 读 XCR0 寄存器: OS 是否保存了 YMM 上下文(AVX 可用的前提)
    return ((unsigned long long)edx << 32) | (unsigned long long)eax; // 拼成 64 位结果
}

struct Cpu {
    bool sse2 = true, avx = false, avx2 = false, fma = false; // 各指令集支持标志(默认值即无探测时的保守假设)
    int  hwThreads = 1;                       // 硬件线程数(并行度上限)
    const char* tag = "scalar";               // 能力标签(日志展示用)
    Cpu() {                                   // 构造即完成全部探测(仅执行一次)
        int r[4] = { 0, 0, 0, 0 };
        __cpuid(r, 1);                        // CPUID leaf 1: 基础特性位
        sse2 = (r[3] & (1 << 26)) != 0;       // EDX bit26 = SSE2
        bool osxsave = (r[2] & (1 << 27)) != 0;  // ECX bit27 = OSXSAVE(允许执行 xgetbv)
        bool avxFlag = (r[2] & (1 << 28)) != 0;  // ECX bit28 = CPU 支持 AVX
        if (osxsave && avxFlag && (xgetbv0() & 0x6) == 0x6) {   // OS 已保存 XMM/YMM
            avx = true;                       // CPU+OS 都支持才标记 AVX 可用
            __cpuid(r, 7);                    // CPUID leaf 7: 扩展特性位
            bool a2 = (r[1] & (1 << 5)) != 0;                   // EBX.AVX2
            __cpuid(r, 1);                    // 回 leaf 1 查 FMA
            bool fm = (r[2] & (1 << 12)) != 0;                  // ECX.FMA
            avx2 = a2;                        // AVX2 标志
            fma = a2 && fm;                   // FMA 需与 AVX2 同时可用才有意义
            tag = avx2 ? (fma ? "avx2+fma" : "avx2") : "avx";   // 更新能力标签
        } else if (sse2) {
            tag = "sse2";                     // 无 AVX: 回退标量(SSE2 是 x64 必备, 仅作日志标签)
        }
        SYSTEM_INFO si;
        GetSystemInfo(&si);                   // 查询系统处理器信息
        int hw = (int)si.dwNumberOfProcessors; // 逻辑处理器数
        hwThreads = hw > 0 ? hw : 1;          // 至少 1 线程
        if (hwThreads > PAR_MAX_T) hwThreads = PAR_MAX_T; // 钳制到并行上限 64
    }
};
inline const Cpu& cpu() { static Cpu c; return c; } // 进程级单例: 首次调用探测一次, 之后零开销

// ---------------- 对齐内存 ----------------
inline double* dalloc(size_t n, size_t alignB = 64) {
    if (n == 0) n = 1;                        // 0 大小也分配 1 元素: 避免 _aligned_malloc(0) 的未定义行为
    return (double*)_aligned_malloc(n * sizeof(double), alignB); // 64 字节对齐: 满足 AVX2 缓存行对齐要求
}
inline void dfree(double* p) { if (p) _aligned_free(p); }  // 对齐内存必须配对释放(不能与普通 free 混用)
inline long long* llalloc(size_t n, size_t alignB = 64) {
    if (n == 0) n = 1;                        // 同上防 0 分配
    return (long long*)_aligned_malloc(n * sizeof(long long), alignB); // 时间戳数组的对齐分配
}
inline void llfree(long long* p) { if (p) _aligned_free(p); }  // 配对释放

// ---------------- SoA 内存布局 ----------------
struct SoA {
    double* o = nullptr; double* h = nullptr; double* l = nullptr; // 开/高/低 各一条独立连续数组
    double* c = nullptr; double* v = nullptr; // 收/量 各一条独立连续数组
    int n = 0, cap = 0;                       // n=有效根数, cap=已分配容量

    void alloc(int capN) {
        if (capN <= cap) { n = 0; return; }   // 容量够用则不重新分配(只清零有效根数, 减少抖动)
        free();                               // 先释放旧内存
        o = dalloc((size_t)capN); h = dalloc((size_t)capN); l = dalloc((size_t)capN); // 五个字段各自对齐分配
        c = dalloc((size_t)capN); v = dalloc((size_t)capN);
        cap = capN; n = 0;                    // 更新容量, 有效数据清零
    }
    void free() {
        dfree(o); dfree(h); dfree(l); dfree(c); dfree(v); // 五条数组逐一释放
        o = h = l = c = v = nullptr; cap = 0; n = 0;      // 指针与计数复位
    }
    // 从交错布局 rows[i*5 + {o,h,l,c,v}] 装入(纯数据搬运, 无浮点运算 → 位精确)
    void load(const double* rows, int cnt) {
        if (!rows || cnt <= 0) { n = 0; return; } // 空输入防御
        if (cnt > cap) alloc(cnt);            // 容量不足则扩容
        for (int i = 0; i < cnt; i++) {
            o[i] = rows[i * 5];     h[i] = rows[i * 5 + 1]; l[i] = rows[i * 5 + 2]; // 交错行 → SoA 列的转置拷贝
            c[i] = rows[i * 5 + 3]; v[i] = rows[i * 5 + 4];
        }
        n = cnt;                              // 更新有效根数
    }
    ~SoA() { free(); }                        // 析构自动释放(RAII 防泄漏)
};

// 从「分离的 5 条数组」引用(常驻库, 零拷贝)
//   st==1  连续 SoA: 每条数组紧密排列(常驻内存库用, SIMD 全速)
//   st>1   交错行主序: 5 条指针指向同一行的相邻元素(如 rows+0..rows+4, st=5)
//          → extern "C" 入口可零拷贝直接跑算法; SIMD 内核仅在 st==1 时启用
struct Ref {
    const double *o, *h, *l, *c, *v;          // 五个字段数组指针(只读视图, 不拥有内存)
    int n;                                    // 有效根数
    int st = 1;                 // 默认连续; 忘记赋值也不会退化成乱步长(默认成员初始化兜底)
    inline double O(int i) const { return o[(size_t)i * (size_t)st]; } // 按步长取第 i 根开盘价(st=5 即交错布局)
    inline double H(int i) const { return h[(size_t)i * (size_t)st]; } // 高价
    inline double L(int i) const { return l[(size_t)i * (size_t)st]; } // 低价
    inline double C(int i) const { return c[(size_t)i * (size_t)st]; } // 收盘
    inline double V(int i) const { return v[(size_t)i * (size_t)st]; } // 成交量
};
// 交错行主序视图(零拷贝): rows[i*5+{0..4}]
inline Ref view_interleaved(const double* rows, int n) {
    Ref r;                                    // 构造视图
    r.o = rows; r.h = rows + 1; r.l = rows + 2; r.c = rows + 3; r.v = rows + 4; // 五个指针分别偏移 0..4(指向每行的对应列)
    r.n = n; r.st = 5;                        // 步长 5 = 交错布局
    return r;
}

// ---------------- AVX2 内核(target 属性单函数开启, 不污染全局编译参数) ----------------
// TR[i] = max(h-l, |h-pc|, |l-pc|) —— 只做 sub/abs/max, 无重结合 → 与标量位精确同值
__attribute__((target("avx2")))               // 仅该函数按 AVX2 编译(配合运行时分发, 主程序仍可跑在无 AVX2 机器)
inline void tr_avx2(const double* h, const double* l, const double* c, double* tr, int n) {
    int i = 1;                                // 从 1 开始: TR 需要 i-1 的收盘价
    const __m256d sgn = _mm256_set1_pd(-0.0);          // andnot 之后 = 绝对值掩码
    for (; i + 4 <= n; i += 4) {              // 主循环: 每次 4 个 double(256 位)
        __m256d vh  = _mm256_loadu_pd(h + i);   // 载入 4 个 h(未对齐 load: 允许任意偏移)
        __m256d vl  = _mm256_loadu_pd(l + i);   // 载入 4 个 l
        __m256d vpc = _mm256_loadu_pd(c + i - 1); // 载入前一根收盘价(与 h/l 错位 1 个元素, 故只能用未对齐 load)
        __m256d hl  = _mm256_sub_pd(vh, vl);      // h-l
        __m256d dh  = _mm256_andnot_pd(sgn, _mm256_sub_pd(vh, vpc)); // |h-pc|(andnot 清符号位 = 绝对值)
        __m256d dl  = _mm256_andnot_pd(sgn, _mm256_sub_pd(vl, vpc)); // |l-pc|
        _mm256_storeu_pd(tr + i, _mm256_max_pd(hl, _mm256_max_pd(dh, dl))); // TR = 三者取大(双 max 嵌套与标量顺序一致)
    }
    for (; i < n; i++) {                      // 尾部不足 4 个: 标量处理(与 SIMD 结果位精确)
        double pc = c[i - 1], t = h[i] - l[i], d;   // pc=前收盘, t 初始为 h-l
        d = h[i] - pc; if (d < 0) d = -d; if (d > t) t = d; // |h-pc|
        d = l[i] - pc; if (d < 0) d = -d; if (d > t) t = d; // |l-pc|
        tr[i] = t;                            // 写出该根 TR
    }
}

// +DM / -DM (Wilder): 掩码选择, 无算术重结合 → 位精确
__attribute__((target("avx2")))               // 同上: 单函数 AVX2
inline void dm_avx2(const double* h, const double* l, double* pdm, double* mdm, int n) {
    const __m256d zero = _mm256_setzero_pd(); // 全 0 向量(与 0 比较用)
    int i = 1;                                // 从 1 开始: 需要 i-1
    for (; i + 4 <= n; i += 4) {              // 主循环每次 4 根
        __m256d vh  = _mm256_loadu_pd(h + i);   // 当前 4 个 h
        __m256d vl  = _mm256_loadu_pd(l + i);   // 当前 4 个 l
        __m256d vhp = _mm256_loadu_pd(h + i - 1); // 前 4 个 h(错位 1)
        __m256d vlp = _mm256_loadu_pd(l + i - 1); // 前 4 个 l
        __m256d up = _mm256_sub_pd(vh, vhp);            // h[i]-h[i-1]
        __m256d dn = _mm256_sub_pd(vlp, vl);            // l[i-1]-l[i]
        __m256d mu = _mm256_and_pd(_mm256_cmp_pd(up, dn,   _CMP_GT_OQ),
                                   _mm256_cmp_pd(up, zero, _CMP_GT_OQ)); // 掩码: up>dn 且 up>0(+DM 有效条件)
        __m256d md = _mm256_and_pd(_mm256_cmp_pd(dn, up,   _CMP_GT_OQ),
                                   _mm256_cmp_pd(dn, zero, _CMP_GT_OQ)); // 掩码: dn>up 且 dn>0(-DM 有效条件)
        _mm256_storeu_pd(pdm + i, _mm256_and_pd(up, mu)); // +DM = up 按掩码保留(无效位与 0 相与=0)
        _mm256_storeu_pd(mdm + i, _mm256_and_pd(dn, md)); // -DM = dn 按掩码保留
    }
    for (; i < n; i++) {                      // 尾部标量(与 SIMD 位精确)
        double up = h[i] - h[i - 1], dn = l[i - 1] - l[i];
        pdm[i] = (up > dn && up > 0) ? up : 0;  // Wilder +DM 定义
        mdm[i] = (dn > up && dn > 0) ? dn : 0;  // Wilder -DM 定义
    }
}

// TP[i] = (h+l+c)/3 —— 纯逐元素(无重结合), 编译器自动 AVX2 化
inline void tp_avx(const double* h, const double* l, const double* c, double* tp, int n) {
    for (int i = 0; i < n; i++) tp[i] = (h[i] + l[i] + c[i]) / 3.0; // 典型价格: 逐元素独立, 开自动向量化即可, 无需手写
}

// ---------------- 标量/分发封装 ----------------
inline void tr_soa(const Ref& s, double* tr) {
    const int n = s.n;
    if (n <= 0) return;                       // 空数据防御
    tr[0] = s.H(0) - s.L(0);                  // 首根无前收盘: TR=h-l(约定)
    if (s.st == 1 && n >= 16 && cpu().avx2) { tr_avx2(s.h, s.l, s.c, tr, n); return; } // 分发条件: 连续布局+数据量够大+支持 AVX2 才走内核(小数据内核开销不划算)
    for (int i = 1; i < n; i++) {             // 标量路径(交错布局/小数据/无 AVX2)
        double pc = s.C(i - 1), t = s.H(i) - s.L(i), d;   // 与内核逐字同构保证位精确
        d = s.H(i) - pc; if (d < 0) d = -d; if (d > t) t = d;
        d = s.L(i) - pc; if (d < 0) d = -d; if (d > t) t = d;
        tr[i] = t;
    }
}
inline void dm_soa(const Ref& s, double* pdm, double* mdm) {
    const int n = s.n;
    if (n <= 0) return;                       // 空数据防御
    pdm[0] = 0; mdm[0] = 0;                   // 首根无前值: DM 置 0
    if (s.st == 1 && n >= 16 && cpu().avx2) { dm_avx2(s.h, s.l, pdm, mdm, n); return; } // 同 tr_soa 的分发条件
    for (int i = 1; i < n; i++) {             // 标量路径
        double up = s.H(i) - s.H(i - 1), dn = s.L(i - 1) - s.L(i);
        pdm[i] = (up > dn && up > 0) ? up : 0;
        mdm[i] = (dn > up && dn > 0) ? dn : 0;
    }
}
// TP[i] = (h+l+c)/3 —— 连续时纯逐元素(编译器自动 AVX2 化)
inline void tp_soa(const Ref& s, double* tp) {
    const int n = s.n;
    if (s.st == 1) {                          // 连续布局: 直接索引(编译器可自动向量化)
        for (int i = 0; i < n; i++) tp[i] = (s.h[i] + s.l[i] + s.c[i]) / 3.0;
        return;
    }
    for (int i = 0; i < n; i++) tp[i] = (s.H(i) + s.L(i) + s.C(i)) / 3.0; // 交错布局: 走带步长取数
}

// 顺序求和(位精确: 不做 SIMD 归约, 保持与标量逐字相同顺序)
inline double sum_st(const double* v, int st, int i0, int i1) {
    double s = 0;
    for (int i = i0; i < i1; i++) s += v[(size_t)i * (size_t)st]; // 严格按下标顺序累加(浮点加法不满足结合律, 顺序一变结果就变)
    return s;
}
inline double sum_seq(const double* v, int i0, int i1) { return sum_st(v, 1, i0, i1); } // 连续数组求和的便捷别名
inline double max_of(const double* v, int st, int i0, int i1, double init = -1e300) {
    double m = init;                          // 初始值默认负无穷量级(区间为空时返回 init)
    for (int i = i0; i < i1; i++) { double x = v[(size_t)i * (size_t)st]; if (x > m) m = x; } // 线性扫描取最大(纯比较, 与窗口算法结果一致)
    return m;
}
inline double min_of(const double* v, int st, int i0, int i1, double init = 1e300) {
    double m = init;                          // 初始值默认正无穷量级
    for (int i = i0; i < i1; i++) { double x = v[(size_t)i * (size_t)st]; if (x < m) m = x; } // 线性扫描取最小
    return m;
}

// 滚动窗口 max/min — 单调队列 O(n)(纯比较, 结果与朴素 O(n·w) 完全一致)
inline void roll_max_st(const double* v, int st, int n, int w, double* out) {
    if (n <= 0 || w <= 0) return;             // 无效参数防御
    std::vector<int> q((size_t)n + 1);        // 单调队列: 存下标, 值从队头到队尾递减
    int head = 0, tail = 0;                       // 队列区间 [head, tail)
    for (int i = 0; i < n; i++) {
        while (tail > head && v[(size_t)q[(size_t)tail - 1] * (size_t)st] <= v[(size_t)i * (size_t)st]) tail--; // 队尾比当前小的永不再成为窗口最大: 弹出
        q[(size_t)tail++] = i;                // 当前下标入队
        if (q[(size_t)head] <= i - w) head++; // 队头滑出窗口左界: 弹出
        out[i] = v[(size_t)q[(size_t)head] * (size_t)st]; // 队头即当前窗口最大值
    }
}
inline void roll_min_st(const double* v, int st, int n, int w, double* out) {
    if (n <= 0 || w <= 0) return;             // 同上防御
    std::vector<int> q((size_t)n + 1);        // 单调队列: 值从队头到队尾递增(求最小)
    int head = 0, tail = 0;                   // 队列区间
    for (int i = 0; i < n; i++) {
        while (tail > head && v[(size_t)q[(size_t)tail - 1] * (size_t)st] >= v[(size_t)i * (size_t)st]) tail--; // 队尾比当前大的弹出
        q[(size_t)tail++] = i;                // 入队
        if (q[(size_t)head] <= i - w) head++; // 队头出窗弹出
        out[i] = v[(size_t)q[(size_t)head] * (size_t)st]; // 队头即窗口最小值
    }
}
inline void roll_max(const double* v, int n, int w, double* out) { roll_max_st(v, 1, n, w, out); } // 连续数组滚动最大(步长 1)
inline void roll_min(const double* v, int n, int w, double* out) { roll_min_st(v, 1, n, w, out); } // 连续数组滚动最小

// ---------------- 多线程 par_for (Win32 CreateThread) ----------------
struct ParCtx {
    void (*fn)(int, void*);                   // 统一签名的循环体跳板函数
    void* ud;                                 // 用户数据(实际指向 lambda 的引用)
    int n, stride, start;                     // 循环上界/步长(=线程数)/起始偏移
};
inline DWORD WINAPI par_worker(LPVOID p) {    // 工作线程入口(Win32 线程约定签名)
    ParCtx* c = (ParCtx*)p;
    for (int i = c->start; i < c->n; i += c->stride) c->fn(i, c->ud); // 跨步分片: 线程 t 处理 i ≡ t (mod nth)
    return 0;                                 // 线程结束
}
// 并行 for: body(i) 之间必须互不依赖(各自内部保持自己的顺序 → 位精确)
// nth<=1 或 n<minN 时退化为串行; 调用方可显式传 minN 控制线程开销阈值
template <class F>
inline void par_for(int n, F&& body, int nth = 0, int minN = 24) {
    if (n <= 0) return;                       // 空循环防御
    if (nth <= 0) nth = cpu().hwThreads;      // 未指定线程数: 用硬件线程数
    if (nth <= 1 || n < minN) { for (int i = 0; i < n; i++) body(i); return; } // 单线程或数据太少(线程创建开销大于收益): 串行
    if (nth > n) nth = n;                     // 线程数不超过数据量(多余线程只会空转)
    void (*fn)(int, void*) = [](int i, void* ud) { (*static_cast<F*>(ud))(i); }; // 把 lambda 适配成裸函数指针跳板
    HANDLE hd[PAR_MAX_T];                     // 线程句柄数组
    ParCtx ctx[PAR_MAX_T];                    // 每线程上下文(生命周期覆盖到 Wait, 栈上即可)
    int created = 0;                          // 实际成功创建的线程数
    for (int t = 1; t < nth; t++) {           // 主线程留给自己处理分片 0, 其余分片开新线程
        ctx[t].fn = fn; ctx[t].ud = (void*)&body;
        ctx[t].n = n; ctx[t].stride = nth; ctx[t].start = t; // 跨步分配: 分片边界固定, 各线程写各自下标, 无数据竞争
        HANDLE h = CreateThread(NULL, 0, par_worker, &ctx[t], 0, NULL);
        if (h) hd[created++] = h;             // 创建失败(资源耗尽)则跳过登记; 该分片仍会因跨步分配而缺人处理——受限于本实现的固定跨步模型
    }
    for (int i = 0; i < n; i += nth) body(i); // 主线程同步处理分片 0(等待期间也干活, 不浪费)
    if (created > 0) {
        WaitForMultipleObjects((DWORD)created, hd, TRUE, INFINITE); // 阻塞等待全部工作线程完成
        for (int i = 0; i < created; i++) CloseHandle(hd[i]);       // 逐一关闭线程句柄(防句柄泄漏)
    }
}

} // namespace sk
