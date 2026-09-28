/* ==================================================================
 * sigcore.cpp — OKX 量化交易系统 信号算法核心库（编译产物 sigcore.dll）
 *
 * 【文件职责】
 *   本文件编译为 sigcore.dll，同时被 C++ 宿主程序（apihub/tphub/tradehub）
 *   与 PHP FFI 加载调用，提供交易信号的全部计算内核：
 *   金▲动能信号、黄金坑六维信号、阈值 ZigZag 转折点、MACD 等技术指标，
 *   以及一个常驻进程内存的 K 线数据库（增量喂入 + 去重 + 滚动淘汰 + 结果缓存）。
 *
 * 【导出函数清单（全部 extern "C" + dllexport）】
 *   dll_version()   : 返回库版本号（如 20270928）
 *   macd_calc()     : 标准 MACD 指标（EMA12/26，DEA=DIF 的 EMA9）
 *   gold_signal()   : 金▲底部动能信号（1=触发 / 0=未触发 / 负=错误）
 *   pit_signal()    : 黄金坑六维复合信号（分型锚+CCI+ADX+MACD+KDJ+动能）
 *   pivots_calc()   : 全部 ZigZag 转折点 + 基础力学标注（KE/g/F）
 *   pivots_full()   : 全部转折点 + 完整力学量（v/m/角度/二阶导/腿根数/KE阈值）
 *   store_upsert()  : 常驻K线库增量喂入（按 ts 合并去重，滚动保留最近600根）
 *   store_gold()    : 直接用内存K线算金▲信号（结果按数据版本缓存）
 *   store_pit()     : 直接用内存K线算黄金坑信号（结果按数据版本缓存）
 *   store_count()   : 查询某序列滞留根数
 *   store_stats()   : 查询滞留序列总数
 *   store_clear()   : 清空全部内存K线数据
 *
 * 【算法说明】
 *   1. 金▲信号 gold_signal/gold_pivots —— 物理动能锚：
 *      先做阈值 ZigZag（反向波动 >= thr×ATR14 才确认转折，thr 随周期分段）；
 *      对每个底部转折点计算"入腿动能" KE = ½mv²，其中 m=入腿均量、
 *      v=|Δ价|/(ATR14×入腿根数)。底部 KE 越大，越容易出现 V 形反转。
 *      触发阈值用"底部 KE 的 75 分位数"（即前 25% 尖转折）而非绝对值——
 *      因为不同合约的成交量/价格量纲不可比，分位数天然自适应各合约。
 *      无未来函数：转折点只用它之前的数据；触发条件=最新确认转折是金▲底部
 *      且距最新K线 <= 8 根。
 *   2. ZigZag 保险丝：新转折点下标必须严格大于上一个已输出点。宽幅K线
 *      （H-L >= thr×ATR）会让同一根同时满足"顶延伸"与"跌够出底"，旧实现
 *      曾在同一根K线上顶底交替输出重复 126 次，此保险丝即为杜绝该事故。
 *   3. 转折点价取该K线的极值（顶=最高价、底=最低价），不是收盘价。
 *   4. pit_signal 黄金坑六维：金▲自适应动能锚 + 缠论底分型 + CCI20<-100
 *      回升 + ADX14 多头主导 + MACD 柱抬升 + KDJ 超卖 + 反转阳线，
 *      六个维度至少命中一个（配合双锚过滤）才触发。
 *   5. macd_calc：标准 MACD(12/26/9)，DIF=EMA12-EMA26，DEA=DIF 的 EMA9，
 *      柱=2×(DIF-DEA)。pivots_calc：对每个转折点输出 KE/g/F 力学标注。
 *   6. 常驻内存K线库：store_upsert 增量喂入并按 ts 去重，超 600 根滚动淘汰
 *      最老；store_gold/store_pit 将信号结果按数据版本缓存（数据未变直接
 *      返回缓存）；store_count/store_stats/store_clear 用于查询与清理。
 * ================================================================== */
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

#include "common/simd.h"   // 自研 SIMD/内存内核: 对齐分配、SoA 视图、TR/DM 向量化、单调队列等
#include <cmath>           // atan 等数学函数
#include <cstdlib>         // 标准库工具
#include <cstring>         // strcmp/memcpy/memmove/memset 等字符串与内存操作
#include <cstdio>          // snprintf 格式化输出(用于 info 提示串)
#include <vector>          // std::vector 临时数组(分位数统计用)
#include <algorithm>       // std::sort 排序(求分位数)

#define GOLD_FRESH_BARS 8  // 金▲触发新鲜度: 底部转折点距最新K线不得超过 8 根
#define SERIES_CAP 600      // 每个序列滞留内存的最大根数(滚动淘汰最老)
#define SERIES_MAX 4096     // 最多滞留的序列数(合约×周期)

// ==================================================================
// 入口适配: 交错 rows[n*5] → SoA(五条对齐数组)
//   每线程一份常驻 scratch(按需扩容, 调用间复用 → 无 per-call malloc, 缓存常热)
//   一次擦写 5*n8 连续块, 每段按 n8(=8 的倍数) 对齐 → 每段都 64B 对齐
//   纯数据搬运, 无浮点运算 → 位精确
//   (实测: 相比每调用 dalloc/dfree, pit_signal 快 1.47x —— 40KB 冷块首次触达是主因)
// ==================================================================
struct TxScr { double* p; int cap; };   // 转置暂存块: p=对齐内存指针, cap=当前容量(double 个数)
static thread_local TxScr g_tx;     // POD + 零初始化 → 无析构, 不注册 atexit

// 按需获取/扩容线程本地暂存块(need 为 double 个数), 返回指针(失败为 nullptr)
static double* tx_scratch(int need) {
    if (g_tx.cap < need) {                          // 容量不足才重新分配(调用间复用, 避免频繁 malloc)
        sk::dfree(g_tx.p);                          // 释放旧块
        g_tx.p = sk::dalloc((size_t)need);          // 分配 64B 对齐的新块
        g_tx.cap = g_tx.p ? need : 0;               // 分配失败则容量归零
    }
    return g_tx.p;                                  // 返回暂存块指针
}

// ---------------- 每线程工作区槽位(免 per-call malloc / 免 vector 零初始化) ----------------
//   槽位互不嵌套: 调用链 gold_signal→gold_signal_ref 用 GOLD 槽,
//   pit_signal→pit_signal_ref 用 PIT 槽再套 GOLD 槽 → 两者不重叠, 安全。
enum { WS_GOLD = 0, WS_GOLDPV, WS_PIT, WS_PFULL, WS_PCALC, WS_N };   // WS_TX 即 g_tx
static thread_local TxScr g_ws[WS_N];               // 各槽位的工作区(线程本地, 互不干扰)
// 取指定槽位的 double 数组, 容量不足时自动扩容; 返回指针(失败为 nullptr)
static double* ws(int slot, int need) {
    if (g_ws[slot].cap < need) {                    // 该槽容量不够则重分配
        sk::dfree(g_ws[slot].p);                    // 释放旧内存
        g_ws[slot].p = sk::dalloc((size_t)need);    // 分配对齐新内存
        g_ws[slot].cap = g_ws[slot].p ? need : 0;   // 记录新容量(失败归零)
    }
    return g_ws[slot].p;                            // 返回工作区指针
}

struct Txb {           // 交错→SoA 转置结果块: 携带数据指针、补齐长度和 SoA 视图引用
    double* p;         // 整块内存起始指针(o/h/l/c/v 五段连续排布)
    int n8;            // 每条数组的补齐长度(n 向上取整到 8 的倍数, 保证每段 64B 对齐)
    sk::Ref r;         // 指向五条数组的 SoA 视图(供算法内核直接使用)
};
// 把交错 rows[n*5](每根K线 o,h,l,c,v 紧挨)转置成五条连续数组的 SoA 布局
static Txb tx_make(const double* rows, int n) {
    Txb t;                                         // 结果块
    int n8 = (n + 7) & ~7;                         // n 向上对齐到 8 的倍数(SIMD 补齐)
    t.n8 = n8;                                     // 记录补齐长度
    t.p = tx_scratch(5 * n8);                      // 申请能放下五条数组的暂存块
    if (!t.p) { t.r.o = t.r.h = t.r.l = t.r.c = t.r.v = nullptr; t.r.n = 0; return t; }  // 分配失败: 返回空视图
    double* o = t.p;                               // 第 1 段: 开盘价数组
    double* h = t.p + n8;                          // 第 2 段: 最高价数组
    double* l = t.p + 2 * n8;                      // 第 3 段: 最低价数组
    double* c = t.p + 3 * n8;                      // 第 4 段: 收盘价数组
    double* v = t.p + 4 * n8;                      // 第 5 段: 成交量数组
    for (int i = 0; i < n; i++) {                  // 逐根K线做交错→分离的纯数据搬运
        o[i] = rows[i * 5];     h[i] = rows[i * 5 + 1];
        l[i] = rows[i * 5 + 2]; c[i] = rows[i * 5 + 3]; v[i] = rows[i * 5 + 4];
    }
    t.r.o = o; t.r.h = h; t.r.l = l; t.r.c = c; t.r.v = v; t.r.n = n; t.r.st = 1;  // 组装 SoA 视图(st=1 连续布局)
    return t;                                      // 返回转置结果
}

static double fabs1(double x) { return x < 0 ? -x : x; }  // 手写绝对值(与 fabs 位精确等价, 便于编译器内联)

// 按K线周期字符串返回 ZigZag 转折阈值系数(反向波动需达到 SEGM×ATR14 才算转折)
static double segm_of(const char* bar) {
    // 与 GoldSEGM 一致: 1m/3m:5.0, 5m:5.5, 15m:6.0, 30m:6.5, 1H:6.5, 4H:7.0
    if (strcmp(bar, "5m") == 0) return 5.5;              // 5 分钟线阈值系数 5.5
    if (strcmp(bar, "15m") == 0) return 6.0;             // 15 分钟线阈值系数 6.0
    if (strcmp(bar, "30m") == 0) return 6.5;             // 30 分钟线阈值系数 6.5
    if (strcmp(bar, "1H") == 0 || strcmp(bar, "1h") == 0) return 6.5;   // 1 小时线阈值系数 6.5(大小写都认)
    if (strcmp(bar, "4H") == 0 || strcmp(bar, "4h") == 0) return 7.0;   // 4 小时线阈值系数 7.0(大小写都认)
    return 5.0; // 1m / 3m / 未知默认
}

// ==================================================================
// 常驻内存K线库: PHP 只喂增量, 数据滞留在本进程内存中(SoA 布局)
//   key = "INST|bar" (如 "BTC-USDT-SWAP|5m")
//   store_upsert   增量喂K线(按 ts 合并去重, 滚动保留最近 SERIES_CAP 根)
//   store_gold     直接用内存里的数据算金▲信号(零 marshaling)
//   store_count / store_stats / store_clear
// ==================================================================

struct Series {                 // 单个"合约|周期"的滞留K线序列(含信号结果缓存)
    char key[128];              // 序列键 "INST|bar"
    long long* ts;              // 毫秒开盘时间戳数组(升序)
    double *o, *h, *l, *c, *v;      // SoA: 五条 64B 对齐独立数组
    int n, cap;                     // n=当前根数, cap=实际分配容量(根)
    int fired;                      // 最近一次 store_gold 结果(1/0/-1未算)
    double ke, th;              // 缓存的金▲动能值与阈值
    int dist;                   // 缓存的底部距最新K线的根数
    char info[160];             // 缓存的金▲提示信息串
    int pfire;                      // 最近一次 store_pit 结果(1/0/-1未算)
    double pscore;              // 缓存的黄金坑总分
    char pinfo[160];            // 缓存的黄金坑提示信息串
};
static Series g_series[SERIES_MAX];   // 全部滞留序列的静态槽位数组
static int g_seriesN = 0;             // 当前已占用的槽位个数

// ---------------- 哈希索引 (替代原 O(n) 线性扫描; SERIES_MAX=4096 < HSZ=8192 故探测必然终止) ----------------
#define HSZ 8192                     // 哈希表大小(2 的幂, 便于用位与取模)
static int g_hidx[HSZ];             // 0=空; 存 series 下标+1

// FNV-1a 哈希: 对字符串逐字符异或后乘质数, 返回 64 位散列
static inline unsigned long long fnv1a(const char* s) {
    unsigned long long h = 1469598103934665603ULL;  // FNV-1a 标准初始偏移量
    while (*s) { h ^= (unsigned char)(*s++); h *= 1099511628211ULL; }  // 逐字符: 先异或再乘 FNV 质数
    return h;                                       // 返回最终散列值
}
static void hash_clear() { memset(g_hidx, 0, sizeof(g_hidx)); }  // 清空哈希索引(全部槽位置 0=空)

// 向开放寻址哈希表插入 key→series 下标 idx(内部存 idx+1, 0 保留表示空)
static void hash_put(const char* key, int idx) {
    unsigned h = (unsigned)(fnv1a(key) & (HSZ - 1));   // 散列取模得到起始槽位
    while (g_hidx[h]) h = (h + 1) & (HSZ - 1);         // 线性探测: 槽被占则顺移到下一个空槽
    g_hidx[h] = idx + 1;                               // 写入"下标+1"(0 表示空槽)
}
static void hash_rebuild() {          // 仅在发生槽位淘汰(memmove 整体前移)后调用
    hash_clear();                                      // 先清空索引
    for (int i = 0; i < g_seriesN; i++) hash_put(g_series[i].key, i);  // 再按当前下标全部重插(前移后下标已变)
}
// 在哈希索引中查找序列, 命中返回下标, 未命中返回 -1
static int series_find(const char* key) {
    unsigned h = (unsigned)(fnv1a(key) & (HSZ - 1));   // 起始探测槽位
    while (g_hidx[h]) {                                // 沿探测链逐槽检查
        int idx = g_hidx[h] - 1;                       // 还原真实下标(存的是下标+1)
        if (strcmp(g_series[idx].key, key) == 0) return idx;  // 键完全匹配 → 找到
        h = (h + 1) & (HSZ - 1);                       // 不匹配继续线性探测下一槽
    }
    return -1;                                         // 探测链走到空槽 → 不存在
}

// 释放一个序列占用的全部内存并复位字段
static void series_free(Series* s) {
    sk::llfree(s->ts);                                 // 释放时间戳数组
    sk::dfree(s->o); sk::dfree(s->h); sk::dfree(s->l); sk::dfree(s->c); sk::dfree(s->v);  // 释放五条 SoA 价格量数组
    s->ts = nullptr; s->o = s->h = s->l = s->c = s->v = nullptr;  // 指针全部置空防悬挂
    s->n = s->cap = 0;                                 // 根数与容量清零
}
// 容量保证(修复: 原实现容量由"首灌批次"决定, 后续更大批次只查 `n >= SERIES_CAP+批大小`
// 而不校验真实容量 → 首灌3根后再灌1000根会堆溢出。此处按实际需要扩容, 数据不变 → 位精确)
// 确保序列至少能装下 need 根K线, 不足则按 1.5 倍策略扩容并整体拷贝旧数据
static bool series_ensure(Series* s, int need) {
    if (need <= s->cap) return true;                   // 容量已够, 直接返回
    int nc = s->cap ? s->cap : (SERIES_CAP + 16);      // 新容量起点: 已有容量, 首次则给默认 616 根
    while (nc < need) nc = nc + nc / 2 + 32;           // 1.5 倍加常数增长直到覆盖需求
    long long* nts = sk::llalloc((size_t)nc);          // 分配新时间戳数组
    double* no = sk::dalloc((size_t)nc); double* nh = sk::dalloc((size_t)nc);  // 分配新开/高数组
    double* nl = sk::dalloc((size_t)nc); double* nc2 = sk::dalloc((size_t)nc);  // 分配新低/收数组
    double* nv = sk::dalloc((size_t)nc);               // 分配新量数组
    if (!nts || !no || !nh || !nl || !nc2 || !nv) return false;   // 任一分配失败 → 放弃扩容
    if (s->n > 0) {                                    // 有旧数据则整体拷到新数组
        memcpy(nts, s->ts, sizeof(long long) * (size_t)s->n);   // 拷时间戳
        memcpy(no, s->o, sizeof(double) * (size_t)s->n);        // 拷开盘价
        memcpy(nh, s->h, sizeof(double) * (size_t)s->n);        // 拷最高价
        memcpy(nl, s->l, sizeof(double) * (size_t)s->n);        // 拷最低价
        memcpy(nc2, s->c, sizeof(double) * (size_t)s->n);       // 拷收盘价
        memcpy(nv, s->v, sizeof(double) * (size_t)s->n);        // 拷成交量
    }
    series_free(s);                                    // 释放旧数组
    s->ts = nts; s->o = no; s->h = nh; s->l = nl; s->c = nc2; s->v = nv;  // 换上新数组
    s->cap = nc;                                       // 记录新容量
    return true;                                       // 扩容成功
}
// 滚动淘汰最老: 一次批量 memmove(原实现逐根 while 循环 memmove, 结果同)
// 把序列裁剪到最多 SERIES_CAP(600) 根, 多出的最老数据一次性前移覆盖删除
static void series_trim(Series* s) {
    int over = s->n - SERIES_CAP;                      // 超出上限的根数
    if (over <= 0) return;                             // 未超限无需裁剪
    int keep = s->n - over;                            // 保留的根数(=SERIES_CAP)
    memmove(s->ts, s->ts + over, sizeof(long long) * (size_t)keep);  // 时间戳整体前移丢弃最老 over 根
    memmove(s->o, s->o + over, sizeof(double) * (size_t)keep);       // 开盘价前移
    memmove(s->h, s->h + over, sizeof(double) * (size_t)keep);       // 最高价前移
    memmove(s->l, s->l + over, sizeof(double) * (size_t)keep);       // 最低价前移
    memmove(s->c, s->c + over, sizeof(double) * (size_t)keep);       // 收盘价前移
    memmove(s->v, s->v + over, sizeof(double) * (size_t)keep);       // 成交量前移
    s->n = keep;                                       // 更新根数
}

// ==================================================================
// 指标内核 — 全部接收 sk::Ref(SoA), 运算顺序与标量逐字一致
// ==================================================================

// Wilder ATR14 (种子=第一根高低差)
//   连续布局(st==1)且有 AVX2: TR 预计算走 SIMD(GL 通道 adx14 还要复用 tr[]) → 划算
//   交错布局(st>1): TR 直接融合进 ATR 递推 —— 与原始实现逐字同序同值, 且省掉 n 次额外写
// 计算 Wilder 平滑的 14 周期真实波幅 ATR; tr 为可选的每根 TR 输出(可为 nullptr)
static void gold_atr14(const sk::Ref& r, double* tr, double* atr) {
    int n = r.n;                                       // K线根数
    if (n <= 0) return;                                // 空数据直接返回
    double a = r.H(0) - r.L(0);             // h0 - l0 (与 tr[0] 同值)
    if (r.st == 1 && n >= 16 && sk::cpu().avx2) {      // 连续布局+数据够长+CPU 支持 AVX2 → 走 SIMD 快路
        sk::tr_soa(r, tr);                             // 向量化预计算全部 TR(真实波幅)
        for (int i = 0; i < n; i++) {                  // 仍按标量顺序做 Wilder 递推(位精确)
            if (i > 0) a += (tr[i] - a) / 14.0;        // Wilder 平滑: a += (TR-a)/14
            if (a < 1e-9) a = 1e-9;                    // 防止 ATR 退化到 0 导致除零
            atr[i] = a;                                // 写出当前 ATR
        }
        return;                                        // 快路结束
    }
    for (int i = 0; i < n; i++) {                      // 标量通用路(交错布局或无 AVX2)
        if (i > 0) {                                   // 第 1 根只做种子, 之后逐根算 TR
            double pc = r.C(i - 1);                    // 前一根收盘价
            double t = r.H(i) - r.L(i), d;             // TR 候选①: 当根高低差
            d = r.H(i) - pc; if (d < 0) d = -d; if (d > t) t = d;  // 候选②: |高-前收|, 取更大者
            d = r.L(i) - pc; if (d < 0) d = -d; if (d > t) t = d;  // 候选③: |低-前收|, 取更大者 → TR 确定
            if (tr) tr[i] = t;                         // 调用方要 TR 数组则写出
            a += (t - a) / 14.0;                       // Wilder 平滑更新 ATR
        } else if (tr) {                               // 第 1 根的 TR
            tr[0] = a;                                 // = 首根高低差(种子)
        }
        if (a < 1e-9) a = 1e-9;                        // 防零保护
        atr[i] = a;                                    // 写出当前 ATR
    }
}

// 阈值ZigZag: 输出转折点 pv[k*3] = {下标, 价, 类型(1顶/0底)}, 返回个数
// 阈值 ZigZag 转折点识别: 只有反向波动达到 thr×ATR14 才确认转折
static int gold_pivots(const sk::Ref& r, double thr, const double* atr, double* pv, int maxP) {
    int n = r.n;                                       // K线根数
    if (n < 10) return 0;                              // 数据太少无法识别转折
    int hiI = 0, loI = 0, cnt = 0, lastT = -1;         // 当前段最高/最低点下标、已输出点数、上一转折类型
    double hiP = r.H(0), loP = r.L(0);                 // 当前段最高/最低价(从第 0 根起步)
    for (int i = 1; i < n; i++) {                      // 从第 1 根开始扫描
        double h = r.H(i), l = r.L(i);                 // 当根高/低价
        if (h > hiP) { hiP = h; hiI = i; }             // 刷新当前段最高点
        if (l < loP) { loP = l; loI = i; }             // 刷新当前段最低点
        if (lastT < 0) {                               // 尚无方向: 等高低差累积到阈值以确定首个转折
            if (hiP - loP >= thr * atr[i]) {           // 波动幅度达标 → 可以定出第一个转折点
                int pi; double pp, pt;                 // 转折点下标/价格/类型
                if (hiI < loI) { pi = loI; pp = loP; pt = 0; }   // 高点先出现 → 当前段是下跌, 低点是转折(底)
                else           { pi = hiI; pp = hiP; pt = 1; }  // 否则低点先出现 → 上涨, 高点是转折(顶)
                if (cnt >= maxP) return cnt;           // 输出槽满则提前结束
                pv[cnt * 3] = pi; pv[cnt * 3 + 1] = pp; pv[cnt * 3 + 2] = pt; cnt++;  // 写出转折点三元组
                lastT = (int)pt;                       // 记录当前方向(1 顶 / 0 底)
                hiI = loI = pi; hiP = r.H(pi); loP = r.L(pi);    // 从转折点重新开始累积下一段
            }
            continue;                                  // 未定方向前不进入延伸/确认逻辑
        }
        double lastP = pv[(cnt - 1) * 3 + 1];          // 上一个已确认转折点的价格
        if (lastT == 1) { // 顶之后: 创新高→顶延伸; 跌够一段→确认底
            if (h > lastP) {                           // 创出新高 → 顶点顺延到当根(延伸)
                pv[(cnt - 1) * 3] = i; pv[(cnt - 1) * 3 + 1] = h; pv[(cnt - 1) * 3 + 2] = 1;  // 原地更新顶点
                loI = i; loP = l;                      // 新低候选从当根重新计
                continue;                              // 本根处理完毕
            }
            if (loP <= lastP - thr * atr[i]) {         // 从顶回落幅度达到阈值 → 确认一个新底
                // 保险丝: 新转折点下标必须严格晚于上一个已输出点。
                //   宽幅K线(H-L >= thr*ATR)会让同一根同时满足"顶延伸"与"跌够出底",
                //   旧实现据此在同一根上顶底交替输出, cnt 每轮自增 → 同一根重复几十次。
                if (loI > (int)pv[(cnt - 1) * 3]) {    // 保险丝: 新底下标必须严格大于上个顶点下标, 防同根重复输出
                    if (cnt >= maxP) return cnt;       // 输出槽满则提前结束
                    pv[cnt * 3] = loI; pv[cnt * 3 + 1] = loP; pv[cnt * 3 + 2] = 0; cnt++;  // 写出新底转折点
                }
                lastT = 0; hiI = loI; hiP = r.H(loI);  // 切换为"底之后"状态, 新高候选从该底起算
            }
        } else { // 底之后: 创新低→底延伸; 涨够一段→确认顶
            if (l < lastP) {                           // 创出新低 → 底点顺延到当根(延伸)
                pv[(cnt - 1) * 3] = i; pv[(cnt - 1) * 3 + 1] = l; pv[(cnt - 1) * 3 + 2] = 0;  // 原地更新底点
                hiI = i; hiP = h;                      // 新高候选从当根重新计
                continue;                              // 本根处理完毕
            }
            if (hiP >= lastP + thr * atr[i]) {         // 从底反弹幅度达到阈值 → 确认一个新顶
                if (hiI > (int)pv[(cnt - 1) * 3]) {          // 同上保险丝
                    if (cnt >= maxP) return cnt;       // 输出槽满则提前结束
                    pv[cnt * 3] = hiI; pv[cnt * 3 + 1] = hiP; pv[cnt * 3 + 2] = 1; cnt++;  // 写出新顶转折点
                }
                lastT = 1; loI = hiI; loP = r.L(hiI);  // 切换为"顶之后"状态, 新低候选从该顶起算
            }
        }
    }
    return cnt;                                        // 返回输出转折点总数
}

// 入腿均量(顺序求和, 位精确): m = mean(v[i0..i1])
// 计算转折"入腿"(上一转折点到当前转折点)的平均成交量
static double leg_mean(const sk::Ref& r, int i0, int i1) {
    double vs = sk::sum_st(r.v, r.st, i0, i1 + 1);     // 按标量顺序求成交量区间和(位精确)
    int c = i1 - i0 + 1;                               // 入腿包含的根数
    double m = 1e-9;                                   // 默认下限 1e-9, 防除零
    if (c > 0) m = vs / (double)c;                     // 均量 = 总量/根数
    if (m < 1e-9) m = 1e-9;                            // 下限保护
    return m;                                          // 返回入腿均量
}

// 入腿动能 KE=0.5*m*v^2 (m=入腿均量, v=|dp|/(ATR14*根数))
// 计算一段行情(入腿)的物理动能: KE = ½mv², m=均量, v=|价差|/(ATR14×根数)
static double gold_leg_ke(const sk::Ref& r, const double* atr, int i0, double p0, int i1, double p1) {
    int bars = i1 - i0;                                // 入腿跨越的根数
    double dp = fabs1(p1 - p0);                        // 价差绝对值
    if (bars <= 0 || dp <= 0 || i1 < 0 || i1 >= r.n || atr[i1] <= 0) return 0;  // 非法输入(零腿/零位移/越界/ATR 非正) → 动能为 0
    double a14 = atr[i1];                              // 腿末端处的 ATR14
    double v = dp / (a14 * (double)bars);              // 归一化"速度": 每根每 ATR 的位移
    double m = leg_mean(r, i0, i1);                    // "质量": 入腿平均成交量
    return 0.5 * m * v * v;                            // 动能 KE = ½mv²
}

// CCI20: TP=(h+l+c)/3, SMA20, MD=mean|TP-SMA|。TP 逐元素预计算(自动向量化), 求和保持顺序
// 计算 20 周期顺势指标 CCI; tp 为每根典型价(TP)的输出, 供外部复用
static void cci20(const sk::Ref& r, double* tp, double* cci) {
    int n = r.n;                                       // K线根数
    sk::tp_soa(r, tp);                                 // 向量化预计算典型价 TP=(h+l+c)/3
    for (int i = 0; i < n; i++) {                      // 逐根计算 CCI
        cci[i] = 0;                                    // 默认值 0
        if (i < 19) continue;                          // 不足 20 根窗口时保持 0
        double sma = sk::sum_seq(tp, i - 19, i + 1);   // 顺序求 20 根 TP 之和(位精确)
        sma /= 20.0;                                   // 得到 SMA20
        double md = 0;                                 // 平均绝对偏差累加器
        for (int j = i - 19; j <= i; j++) md += fabs1(tp[j] - sma);  // 顺序累加 |TP-SMA|
        md /= 20.0;                                    // 得到 MD(平均绝对偏差)
        cci[i] = (md < 1e-12) ? 0 : (tp[i] - sma) / (0.015 * md);    // CCI=(TP-SMA)/(0.015×MD), MD 近零则置 0
    }
}

// Wilder ADX14: 输出 adx/pdi/mdi (i<27 时为0)。TR/+DM/-DM 走 SIMD 预计算, 三段递推保持顺序
// 计算 Wilder ADX14 趋向指标, 同时输出 +DI/-DI 两条方向线
static void adx14(const sk::Ref& r, double* tr, double* pdm, double* mdm,
                  double* adx, double* pdi, double* mdi) {
    int n = r.n;                                       // K线根数
    sk::tr_soa(r, tr);                                 // SIMD 预计算真实波幅 TR
    sk::dm_soa(r, pdm, mdm);                           // SIMD 预计算上升/下降动向 +DM/-DM
    double str = 0, sp = 0, sm = 0, adxAcc = 0;        // TR/+DM/-DM 的 Wilder 平滑值、ADX 累计值
    int adxN = 0;                                      // 已纳入 ADX 平均的 DX 个数
    for (int i = 1; i < n; i++) {                      // 从第 1 根开始递推
        double t = tr[i], p = pdm[i], m2 = mdm[i];     // 取当根 TR/+DM/-DM
        if (i <= 14) {                                 // 前 14 根: 做初始累加(种子期)
            str += t; sp += p; sm += m2;               // 简单累加 14 根
            if (i < 14) { adx[i] = 0; pdi[i] = 0; mdi[i] = 0; continue; }  // 种子期未满, 指标输出 0
        } else {                                       // 第 14 根之后: Wilder 平滑递推
            str = str - str / 14.0 + t;                // str = str - str/14 + TR
            sp  = sp  - sp / 14.0  + p;                // sp  = sp  - sp /14 + +DM
            sm  = sm  - sm / 14.0  + m2;               // sm  = sm  - sm /14 + -DM
        }
        double pdv = (str > 1e-12) ? 100.0 * sp / str : 0;          // +DI = 100×+DM平滑/TR平滑
        double mdv = (str > 1e-12) ? 100.0 * sm / str : 0;          // -DI = 100×-DM平滑/TR平滑
        double dx = (pdv + mdv > 1e-12) ? 100.0 * fabs1(pdv - mdv) / (pdv + mdv) : 0;  // DX=100×|+DI - -DI|/(+DI + -DI)
        if (adxN == 0) { adxAcc = dx; adxN = 1; }      // 第一个 DX 直接作为 ADX 种子
        else { adxAcc = (adxAcc * adxN + dx) / (adxN + 1); adxN++; }   // 之后用累计平均(与逐字标量口径一致)
        adx[i] = (i >= 27) ? adxAcc : 0;               // 27 根起 ADX 才有效, 之前输出 0
        pdi[i] = pdv; mdi[i] = mdv;                    // 写出 +DI/-DI
    }
}

// KDJ(9,3,3): 窗口极值走 O(n) 单调队列(纯比较, 与朴素 O(n·9) 同值)
// 计算 KDJ(9,3,3) 随机指标; hh/ll 为 9 窗口最高/最低(供复用)
static void kdj9(const sk::Ref& r, double* hh, double* ll, double* K, double* D, double* J) {
    int n = r.n;                                       // K线根数
    sk::roll_max_st(r.h, r.st, n, 9, hh);              // 单调队列求 9 窗口滚动最高价
    sk::roll_min_st(r.l, r.st, n, 9, ll);              // 单调队列求 9 窗口滚动最低价
    double k = 50, d = 50;                             // K/D 初值 50(经典设定)
    for (int i = 0; i < n; i++) {                      // 逐根递推
        double hv = hh[i], lv = ll[i];                 // 当根所在窗口的最高/最低
        double rsv = (hv - lv > 1e-12) ? (r.C(i) - lv) / (hv - lv) * 100.0 : 50;  // RSV=收盘在窗口区间的百分位, 区间为零则 50
        k = k * 2.0 / 3.0 + rsv / 3.0;                 // K = 2/3×前K + 1/3×RSV
        d = d * 2.0 / 3.0 + k / 3.0;                   // D = 2/3×前D + 1/3×K
        K[i] = k; D[i] = d; J[i] = 3 * k - 2 * d;      // 写出 K/D/J(J=3K-2D)
    }
}

// 缠论底分型: 最近3个确认位内出现底分型(中间K线高低点皆最低), 返回分型中间K线下标, 无则-1
// 检测最近几根内是否出现"缠论底分型": 中间K线的低点和高点都比左右两根更低
static int chan_bottom_fx(const sk::Ref& r) {
    int n = r.n;                                       // K线根数
    for (int mid = n - 2; mid >= n - 4 && mid >= 1; mid--) {  // 中间候选K线: 从倒数第 2 根向左最多查 3 个位置
        double l = r.L(mid), h = r.H(mid);             // 中间K线的低/高价
        double lp = r.L(mid - 1), hp = r.H(mid - 1);   // 左一根的低/高价
        double ln = r.L(mid + 1), hn = r.H(mid + 1);   // 右一根的低/高价
        if (l < lp && l < ln && h < hp && h < hn) return mid;  // 低点与高点都居中最低 → 底分型成立, 返回中间下标
    }
    return -1;                                         // 未发现底分型
}

// macd_calc 的内部实现(close 序列, 12/26/9)
// MACD 内核(固定 12/26/9): DIF=EMA12-EMA26, DEA=DIF 的 EMA9, 柱=2×(DIF-DEA)
static void macd_calc_impl(const double* c, int n, double* dif, double* dea, double* hist) {
    double kf = 2.0 / 13.0, ks = 2.0 / 27.0, kg = 2.0 / 10.0;  // 三个 EMA 平滑系数: 2/(N+1)
    double e12 = n > 0 ? c[0] : 0, e26 = n > 0 ? c[0] : 0, d0 = 0;  // EMA12/EMA26/DEA 的初值(首收盘, 0)
    for (int i = 0; i < n; i++) {                      // 逐根递推
        double cc = c[i];                              // 当根收盘价
        if (i == 0) { e12 = cc; e26 = cc; d0 = 0; dif[0] = 0; dea[0] = 0; hist[0] = 0; continue; }  // 首根全部置 0 种子
        e12 += kf * (cc - e12);                        // EMA12 快线递推
        e26 += ks * (cc - e26);                        // EMA26 慢线递推
        dif[i] = e12 - e26;                            // DIF = 快慢 EMA 之差
        d0 += kg * (dif[i] - d0);                      // DEA = DIF 的 EMA9
        dea[i] = d0;                                   // 写出 DEA
        hist[i] = (dif[i] - d0) * 2.0;                 // MACD 柱 = 2×(DIF-DEA)
    }
}

// ---------------- 黄金坑复合信号指标 (2026-09-28 v2) ----------------
// 二阶导: 转折点前15根收盘价(ATR 归一)抛物线最小二乘的 2*c2。顺序求和保持
// 计算转折点处价格曲线的"二阶导"(曲率): 对前 15 根 ATR 归一化收盘价做抛物线最小二乘拟合
static double piv_curv(const double* cl, int st, int ri, double a14) {
    int i0 = ri - 15; if (i0 < 0) i0 = 0;              // 拟合窗口起点(最多前 15 根, 不越界)
    int npts = ri - i0;                                // 实际参与拟合的点数
    if (npts < 8 || a14 <= 0) return 0;                // 点太少或 ATR 非正 → 曲率记 0
    double s0 = 0, s1 = 0, s2 = 0, s3 = 0, s4 = 0, sy = 0, sty = 0, st2y = 0;  // 最小二乘所需的各阶矩累加器
    for (int i = i0; i < ri; i++) {                    // 遍历拟合窗口
        double t = (double)(i - ri), y = cl[(size_t)i * (size_t)st] / a14;   // 收盘价 / ATR
        s0 += 1; s1 += t; s2 += t * t; s3 += t * t * t; s4 += t * t * t * t;  // 累加 t 的 0~4 阶矩
        sy += y; sty += t * y; st2y += t * t * y;      // 累加 y、t·y、t²·y
    }
    double det = s4 * (s2 * s0 - s1 * s1) - s3 * (s3 * s0 - s1 * s2) + s2 * (s3 * s1 - s2 * s2);  // 3×3 正规方程行列式
    if (fabs1(det) < 1e-12) return 0;                  // 行列式近零(病态) → 放弃拟合
    double c2 = (st2y * (s2 * s0 - s1 * s1) - sty * (s3 * s0 - s1 * s2) + sy * (s3 * s1 - s2 * s2)) / det;  // 克莱姆法则解出二次项系数 c2
    return 2 * c2;                                     // 二阶导 = 2×c2(抛物线 y=c2t²+… 的曲率度量)
}

// ---------------- 金▲信号 (SoA 核心) ----------------
// 金▲信号核心: 底部转折点动能进入全史前 25% 且足够新鲜 → 触发
static int gold_signal_ref(const sk::Ref& r, const char* bar,
                           double* ke_out, double* th_out, int* dist_out,
                           char* info, int infoLen) {
    int n = r.n;                                       // K线根数
    if (n < 30 || !bar) {                              // 数据不足或周期参数缺失
        if (info && infoLen > 16) snprintf(info, infoLen, "%s", n < 30 ? "K线不足" : "参数错误");  // 写明失败原因
        return 0;                                      // 返回未触发
    }
    double thr = segm_of(bar);                         // 取该周期的 ZigZag 阈值系数
    double* Wa = ws(WS_GOLD, n * 2 + 8);               // 申请工作区: tr 与 atr 两段
    if (!Wa) return -1;                                // 内存不足报错
    double* tr  = Wa;                                  // 第 1 段: 真实波幅数组
    double* atr = tr + n;                              // 第 2 段: ATR14 数组
    gold_atr14(r, tr, atr);                            // 计算 Wilder ATR14
    double* pv = ws(WS_GOLDPV, 3 * (n + 2));           // 申请转折点输出缓冲(每点 3 个 double)
    if (!pv) return -1;                                // 内存不足报错
    int npv = gold_pivots(r, thr, atr, pv, n + 2);     // 识别全部阈值 ZigZag 转折点
    int ret = 0;                                       // 默认未触发
    if (npv < 2) {                                     // 转折点不足 2 个无法定义"入腿"
        if (info && infoLen > 16) snprintf(info, infoLen, "无转折点");  // 提示原因
    } else {
        std::vector<double> bke((size_t)npv);          // 各底部点的动能值表
        std::vector<int> bidx((size_t)npv);            // 各底部点的K线下标表
        int nb = 0;                                    // 有效底部动能点个数
        for (int k = 1; k < npv; k++) {                // 从第 2 个点起, 逐点计算入腿动能
            if ((int)pv[k * 3 + 2] != 0) continue;     // 只统计底部点(类型 0)
            double ke = gold_leg_ke(r, atr, (int)pv[(k - 1) * 3], pv[(k - 1) * 3 + 1],          // 入腿起点: 上一转折点
                                    (int)pv[k * 3], pv[k * 3 + 1]);   // 入腿终点: 当前底部点
            if (ke > 0) { bke[nb] = ke; bidx[nb] = (int)pv[k * 3]; nb++; }  // 动能有效则入表
        }
        if (nb == 0) {                                 // 一个有效底部动能点都没有
            if (info && infoLen > 16) snprintf(info, infoLen, "无底部动能点");  // 提示原因
        } else {
            // 75分位阈值(底部KE前25%)
            std::vector<double> ks(bke.begin(), bke.begin() + nb);  // 拷贝有效动能序列
            std::sort(ks.begin(), ks.end());           // 升序排序以便取分位数
            int thi = (int)((double)nb * 0.75);        // 75 分位位置索引
            if (thi >= nb) thi = nb - 1;               // 越界保护(取最大值)
            double th = ks[(size_t)thi];               // 尖转折阈值 = 底部 KE 的 75 分位数
            // 触发: 最新确认转折=底部 且 距最新K线<=GOLD_FRESH_BARS 且 KE>=th
            double* last = pv + (npv - 1) * 3;         // 最新一个已确认转折点
            if ((int)last[2] != 0) {                   // 最新转折是顶而非底
                if (info && infoLen > 16) snprintf(info, infoLen, "最新转折非底部");  // 提示原因
            } else {
                int dist = n - 1 - (int)last[0];       // 底部点距最新K线的根数
                if (dist > GOLD_FRESH_BARS) {          // 底部太旧(超过 8 根)
                    if (info && infoLen > 16) snprintf(info, infoLen, "底部已过期");  // 提示原因
                } else {
                    double ke = 0;                     // 当前底部的动能值
                    for (int i = 0; i < nb; i++) if (bidx[i] == (int)last[0]) { ke = bke[i]; break; }  // 从动能表查到该底部的 KE
                    if (ke_out)  *ke_out = ke;         // 输出动能值(调用方可能不要)
                    if (th_out)  *th_out = th;         // 输出阈值
                    if (dist_out) *dist_out = dist;    // 输出新鲜度距离
                    if (ke <= 0 || ke < th) {          // 动能无效或未进前 25%
                        if (info && infoLen > 16)
                            snprintf(info, infoLen, "动能不足前25%%(KE=%.4g<th=%.4g)", ke, th);  // 提示原因(含数值)
                    } else {
                        ret = 1;                       // 全部条件满足 → 金▲触发!
                        if (info && infoLen > 16)
                            snprintf(info, infoLen, "金▲底部动能前25%%(KE=%.4g>=th=%.4g, 低点距今%d根)", ke, th, dist);  // 触发详情
                    }
                }
            }
        }
    }
    return ret;                                        // 返回触发状态
}

// ---------------- 黄金坑六维信号 (SoA 核心) ----------------
// 双锚(必须): ①最近3根内已确认底分型 且 现价距坑底<=3%防追高 且 坑深>=2.5×ATR14
//            ②坑前下跌腿急跌动能 速度|dp|/(ATR14*根数)>=1.2
// 其余四维(CCI/ADX/MACD/KDJ)至少中2 → 触发 (严格过滤横盘毛刺, 求稳不求多)
// 返回 1=触发 / 0=未触发 / -1=错误; score_out=总分0~6
// 黄金坑六维信号核心: 分型锚+CCI+ADX+MACD+KDJ+急跌动能, 配合金▲自适应动能锚做最终触发
static int pit_signal_ref(const sk::Ref& r, const char* bar,
                          double* score_out, char* info, int infoLen) {
    (void)bar;                                         // 周期参数本核心未直接使用(避免告警)
    int n = r.n;                                       // K线根数
    if (n < 120) {                                     // 黄金坑需要足够长的历史(至少 120 根)
        if (info && infoLen > 16) snprintf(info, infoLen, "K线不足(%d,需120)", n);  // 提示原因
        return -1;                                     // 返回错误
    }
    double* W = ws(WS_PIT, n * 17 + 16);               // 申请 PIT 槽工作区: 17 条长度 n 的数组
    if (!W) return -1;                                 // 内存不足报错
    double* atr = W;                                   // ATR14 数组
    double* tr  = atr + n;                             // TR 数组
    double* tp  = tr + n;                              // 典型价 TP 数组
    double* pdm = tp + n;                              // +DM 数组
    double* mdm = pdm + n;                             // -DM 数组
    double* cci = mdm + n;                             // CCI20 数组
    double* ax  = cci + n;                             // ADX14 数组
    double* pdx = ax + n;                              // +DI 数组
    double* mdx = pdx + n;                             // -DI 数组
    double* K   = mdx + n;                             // KDJ 的 K 线
    double* D   = K + n;                               // KDJ 的 D 线
    double* J   = D + n;                               // KDJ 的 J 线
    double* dif = J + n;                               // MACD 的 DIF
    double* dea = dif + n;                             // MACD 的 DEA
    double* hist = dea + n;                            // MACD 柱
    double* hh  = hist + n;                            // KDJ 9 窗口最高
    double* ll  = hh + n;                              // KDJ 9 窗口最低

    gold_atr14(r, tr, atr);                            // 算 ATR14(供坑深/动能归一)
    cci20(r, tp, cci);                                 // 算 CCI20
    adx14(r, tr, pdm, mdm, ax, pdx, mdx);              // 算 ADX14 与 +DI/-DI
    kdj9(r, hh, ll, K, D, J);                          // 算 KDJ
    macd_calc_impl(r.c, n, dif, dea, hist);            // 算 MACD

    int i = n - 1;   // 最新已收盘K线
    int score = 0;                                     // 六维命中计数
    char s2[24] = "-", s3[24] = "-", s4[24] = "-", s5[24] = "-", s6[24] = "-";  // 各维命中详情串(未命中显示"-")
    // ① 缠论底分型锚(必须) + 坑深
    int fx = chan_bottom_fx(r);                        // 检测最近是否出现底分型(返回中间K线下标)
    int anchor = (fx >= 0);                            // 锚①是否成立
    // 防追高: 现价距分型低点 <=3%
    if (anchor && r.C(i) > r.L(fx) * 1.03) anchor = 0; // 现价已高出分型低点 3% 以上 → 视为追高, 锚失效
    double depth = 0;                                  // 坑深(以 ATR14 为单位)
    if (anchor) {                                      // 锚仍成立才继续验证坑深
        depth = 0;
        int hiI2 = fx; double hiP2 = -1e18;            // 坑沿(近 40 根最高点)下标与价格
        (void)hiI2;                                    // 该下标此处仅记录, 显式避免未用告警
        for (int k = (fx - 40 > 0 ? fx - 40 : 0); k <= fx; k++)  // 在分型前的 40 根内找坑沿最高点
            if (r.H(k) > hiP2) { hiP2 = r.H(k); hiI2 = k; }  // 刷新最高点
        if (atr[fx] > 0) depth = (hiP2 - r.L(fx)) / atr[fx];  // 坑深 = (坑沿高点-坑底低点)/ATR14
        if (depth < 2.5) anchor = 0;    // 坑深不足=横盘毛刺, 不算坑
    }
    if (anchor) score++;                               // 维度①命中则计分
    // ② CCI20 进坑(<-100)且回升
    double cciMin = 1e18;                              // 近 10 根 CCI 最低值
    for (int k = i - 9; k <= i; k++) if (k >= 0 && cci[k] < cciMin) cciMin = cci[k];  // 扫窗口求最低
    if (cciMin < -100 && cci[i] > cci[i - 1]) { score++; snprintf(s2, 24, "cci%.0f", cci[i]); }  // 超卖过(-100 下)且正在回升 → 命中
    // ③ ADX>=15 且 多头主导(+DI>-DI 或 +DI升-DI降)
    if (ax[i] >= 15.0 && (pdx[i] > mdx[i] || (pdx[i] > pdx[i - 1] && mdx[i] < mdx[i - 1]))) {  // 趋势强度达标且多方占优
        score++; snprintf(s3, 24, "adx%.0f", ax[i]);   // 命中并记录 ADX 值
    }
    // ④ MACD柱连续2根抬升
    if (hist[i] > hist[i - 1] && hist[i - 1] > hist[i - 2]) { score++; snprintf(s4, 24, "m%.4g", hist[i]); }  // 柱状连续两根变高 → 动能修复, 命中
    // ⑤ KDJ J超卖(<0)且回升 或 K上穿D
    double jMin = 1e18;                                // 近 5 根 J 最低值
    for (int k = i - 4; k <= i; k++) if (k >= 0 && J[k] < jMin) jMin = J[k];  // 扫窗口求最低
    if ((jMin < 0 && J[i] > J[i - 1]) || (K[i - 1] <= D[i - 1] && K[i] > D[i])) {  // J 超卖回升 或 K 线金叉 D 线
        score++; snprintf(s5, 24, "j%.1f", J[i]);      // 命中并记录 J 值
    }
    // ⑥ 动能: 坑前下跌腿速度 |dp|/(ATR14*根数) >= 1.2 (急跌成坑, 必须项)
    bool vOk = false;                                  // 维度⑥是否命中
    if (anchor) {                                      // 依赖分型锚成立
        int hiI = fx; double hiP = -1e18;              // 坑沿最高点(近 40 根)
        for (int k = (fx - 40 > 0 ? fx - 40 : 0); k <= fx; k++)  // 扫描找坑沿
            if (r.H(k) > hiP) { hiP = r.H(k); hiI = k; }  // 刷新最高点
        int bars = fx - hiI;                           // 下跌腿根数
        if (bars > 0 && atr[fx] > 0) {                 // 腿有效才计算
            double v = (hiP - r.L(fx)) / (atr[fx] * bars);  // 下跌"速度" = 跌幅/(ATR×根数)
            if (v >= 1.2) { vOk = true; score++; snprintf(s6, 24, "v%.1f", v); }  // 速度够快(急跌) → 命中
        }
    }
    int others = score - (anchor ? 1 : 0) - (vOk ? 1 : 0);  // "其余四维"的命中数(CCI/ADX/MACD/KDJ)
    // 反转确认(必须): 最新K线收阳 且 收盘抬升 (坑右沿确认, 不接飞刀)
    bool revOk = r.C(i) > r.O(i) && r.C(i) > r.C(i - 1);  // 最新K线收阳且收盘比前一根高
    (void)revOk;                                       // 当前版本仅记录不参与触发(避免告警)
    // 自适应动能锚(必须): 沿用金▲分位数KE口径(急跌底动能进前25%), 按合约自适应
    double ke = 0, th = 0; int dist = 0; char ginfo[160] = { 0 };  // 金▲子信号的输出缓冲
    bool goldOk = (gold_signal_ref(r, bar, &ke, &th, &dist, ginfo, 160) == 1);  // 复用金▲核心算自适应动能锚
    // 触发: 自适应动能锚(保留原入场时机) + 四维确认至少中1 (只滤最差毛刺, 不过度延迟入场)
    int ret = (goldOk && others >= 1) ? 1 : 0;         // 金▲锚命中且其余四维至少中 1 → 触发
    if (vOk && !goldOk) score++;  // 记分展示用
    if (score_out) *score_out = (double)score;         // 输出总分
    if (info && infoLen > 16)
        snprintf(info, infoLen, "分型%s CCI%s ADX%s MACD%s KDJ%s 动能%s",       // 拼六维命中详情
                 anchor ? "Y" : "N", s2, s3, s4, s5, s6);  // 锚与各维逐项展示
    return ret;                                        // 返回触发状态
}

// ---------------- 全部转折点+完整力学量 (SoA 核心) ----------------
// 输出: idx[], type[](1顶/0底), pA[](转折点价=极值), ke[], g[], f[], v[](斜率),
//       m[](入腿均量), ang[](角度°), a2[](二阶导), bars[](入腿根数)
// *thOut = 底部KE 75分位阈值(尖转折线, 与 gold_signal 同口径)
// 口径与 ov_physics.js 逐字一致: v=dp/(ATR·bars), KE=½mv², g=v²/(2h), F=mg=KE/h, h=dp/ATR
// 全部转折点+完整力学量核心: 对每个 ZigZag 转折点输出一整套物理力学标注
static int pivots_full_ref(const sk::Ref& r, const char* bar,
                           int* idx, int* type, double* pA,
                           double* keA, double* gA, double* fA,
                           double* vA, double* mA, double* angA, double* a2A, int* barsA,
                           double* thOut, int maxP) {
    int n = r.n;                                       // K线根数
    if (n < 10 || !bar) return -1;                     // 数据太少或参数缺失 → 错误
    double thr = segm_of(bar);                         // 周期对应的 ZigZag 阈值系数
    double* Wa = ws(WS_GOLD, n * 2 + 8);               // 申请 tr/atr 工作区
    if (!Wa) return -1;                                // 内存不足报错
    double* tr  = Wa;                                  // TR 数组
    double* atr = tr + n;                              // ATR14 数组
    gold_atr14(r, tr, atr);                            // 计算 ATR14
    double* pv = ws(WS_GOLDPV, 3 * (n + 2));           // 申请转折点缓冲
    if (!pv) return -1;                                // 内存不足报错
    int npv = gold_pivots(r, thr, atr, pv, n + 2);     // 识别全部转折点

    // 第一遍: 每点力学量。各转折点独立, 但**实测每点仅 ~0.5us**(npv 最大 86 → 全量 <50us),
    //   而 CreateThread ×3 约 200~450us → 并行净亏 4.8x, 故此处保持串行。
    //   多线程用在"批次大"的地方(cmd_pitbt 全合约回测), 见该组件。
    double* pvv = pv;                                  // 转折点数组别名
    int lim = npv < maxP ? npv : maxP;                 // 实际输出点数(不超过调用方缓冲)
    const double* atrp = atr;                          // ATR 数组别名(循环内用)
    for (int k = 0; k < lim; k++) {                    // 逐转折点计算力学量
        int pi = (int)pvv[k * 3];                      // 该转折点的K线下标
        double ke = 0, g = 0, f = 0, v = 0, m = 0, ang = 0, a2 = 0;  // 各力学量默认 0
        int bars = 0;                                  // 入腿根数默认 0
        if (k > 0) {                                   // 第一个点没有"入腿", 从第二个点起算
            int pi0 = (int)pvv[(k - 1) * 3];           // 入腿起点(上一转折点)下标
            double p0 = pvv[(k - 1) * 3 + 1], p1 = pvv[k * 3 + 1];  // 腿两端转折点价
            bars = pi - pi0;                           // 入腿根数
            double dp = fabs1(p1 - p0);                // 腿的价差绝对值
            double a14 = (pi >= 0 && pi < n) ? atrp[pi] : 1e-9;  // 腿末端 ATR(越界时给极小值)
            if (a14 < 1e-9) a14 = 1e-9;                // 防零保护
            if (bars > 0 && dp > 0) {                  // 腿有效才计算各量
                v = dp / (a14 * (double)bars);                   // 斜率(一阶导, ATR/根)
                double hN = dp / a14;                            // 笔高(ATR 单位)
                m = leg_mean(r, pi0, pi);                        // 质量: 入腿平均成交量
                ke = 0.5 * m * v * v;                            // 动能 KE=½mv²
                ang = atan(v) * 180.0 / 3.14159265358979323846;   // 速度对应的倾角(度)
                g = v * v / (2.0 * hN);                           // 等效重力加速度 g=v²/2h
                f = ke / hN;                                      // 等效作用力 F=mg=KE/h
                a2 = piv_curv(r.c, r.st, pi, a14);                      // 价格曲线二阶导(曲率)
            }
        }
        idx[k] = pi; type[k] = (int)pvv[k * 3 + 2];    // 输出转折点下标与类型(1 顶/0 底)
        if (pA) pA[k] = pvv[k * 3 + 1];                  // 转折点价(极值), 不是收盘价
        keA[k] = ke; gA[k] = g; fA[k] = f;             // 输出动能/加速度/力
        vA[k] = v; mA[k] = m; angA[k] = ang; a2A[k] = a2; barsA[k] = bars;  // 输出速度/质量/角度/二阶导/腿根数
    }

    // 阈值: 底部KE 75分位(尖转折前25%)。串行收集以保持与标量相同的 k 顺序
    std::vector<double> bke;                           // 底部动能集合
    for (int k = 1; k < lim; k++)                      // 逐点收集(跳过第 0 个无腿点)
        if (type[k] == 0 && keA[k] > 0) bke.push_back(keA[k]);  // 只要有效底部动能
    double th = 0;                                     // 尖转折阈值默认 0
    if (!bke.empty()) {                                // 有底部样本才求分位
        std::sort(bke.begin(), bke.end());             // 升序排序
        size_t ti = (size_t)((double)bke.size() * 0.75);  // 75 分位索引
        if (ti >= bke.size()) ti = bke.size() - 1;     // 越界保护
        th = bke[ti];                                  // 取分位值作为阈值
    }
    if (thOut) *thOut = th;                            // 输出阈值(尖转折线)
    return lim;                                        // 返回输出点数
}

// ---------------- 全部转折点+基础力学标注 (SoA 核心) ----------------
// 全部转折点+基础力学量核心: 每个转折点输出 KE/g/F 三项简化标注
static int pivots_calc_ref(const sk::Ref& r, const char* bar,
                           int* idx, int* type, double* keA, double* gA, double* fA, int maxP) {
    int n = r.n;                                       // K线根数
    if (n < 10 || !bar) return -1;                     // 数据太少或参数缺失 → 错误
    double thr = segm_of(bar);                         // 周期对应的 ZigZag 阈值系数
    double* Wa = ws(WS_GOLD, n * 2 + 8);               // 申请 tr/atr 工作区
    if (!Wa) return -1;                                // 内存不足报错
    double* tr  = Wa;                                  // TR 数组
    double* atr = tr + n;                              // ATR14 数组
    gold_atr14(r, tr, atr);                            // 计算 ATR14
    double* pv = ws(WS_GOLDPV, 3 * (n + 2));           // 申请转折点缓冲
    if (!pv) return -1;                                // 内存不足报错
    int npv = gold_pivots(r, thr, atr, pv, n + 2);     // 识别全部转折点
    int out = 0;                                       // 已输出点数
    for (int k = 0; k < npv && out < maxP; k++) {      // 逐点计算直到耗尽或缓冲满
        int pi = (int)pv[k * 3];                       // 该转折点下标
        double ke = 0, g = 0, f = 0;                   // 力学量默认 0
        if (k > 0) {                                   // 第二个点起才有入腿
            int pi0 = (int)pv[(k - 1) * 3];            // 入腿起点下标
            double p0 = pv[(k - 1) * 3 + 1], p1 = pv[k * 3 + 1];  // 腿两端价格
            ke = gold_leg_ke(r, atr, pi0, p0, pi, p1); // 入腿动能 KE=½mv²
            int bars = pi - pi0;                       // 入腿根数
            if (bars > 0) {                            // 腿有效才算 g/F
                f = ke / (double)bars;                       // F = KE/h (牛二, h=入腿根数)
                g = ke / (leg_mean(r, pi0, pi) * (double)bars);  // g = KE/(m*h)
            }
        }
        idx[out] = pi; type[out] = (int)pv[k * 3 + 2]; // 输出下标与类型
        keA[out] = ke; gA[out] = g; fA[out] = f;       // 输出三项力学量
        out++;                                         // 输出计数 +1
    }
    return out;                                        // 返回输出点数
}

extern "C" {                                           // C 接口导出段(供 DLL/FFI 调用, 无名字修饰)

// ---------- 版本 ----------
// 返回库版本号(格式 yyyymmdd), 供宿主/PHP 检查 ABI 兼容
__declspec(dllexport) int dll_version() { return 20270928; }

// ---------- MACD (EMA12/26, DEA=EMA9 of DIF) ----------
// close[n] -> dif[n], dea[n], hist[n]; 返回 0 成功
// 标准 MACD 指标导出(周期可配): close 序列 → DIF/DEA/柱 三条输出
__declspec(dllexport) int macd_calc(const double* close, int n, int fast, int slow, int sig,
                                    double* dif, double* dea, double* hist) {
    if (!close || n <= 0) return -1;                   // 参数非法 → 错误
    double kf = 2.0 / (fast + 1), ks = 2.0 / (slow + 1), kg = 2.0 / (sig + 1);  // 按 fast/slow/sig 计算平滑系数
    double e12 = close[0], e26 = close[0], d0 = 0;     // EMA12/EMA26/DEA 初值
    for (int i = 0; i < n; i++) {                      // 逐根递推
        if (i == 0) { e12 = close[0]; e26 = close[0]; dif[i] = 0; dea[i] = 0; hist[i] = 0; d0 = 0; continue; }  // 首根全 0 种子
        e12 = e12 + kf * (close[i] - e12);             // 快线 EMA 递推
        e26 = e26 + ks * (close[i] - e26);             // 慢线 EMA 递推
        dif[i] = e12 - e26;                            // DIF = 快-慢
        d0 = d0 + kg * (dif[i] - d0);                  // DEA = DIF 的 EMA(sig)
        dea[i] = d0;                                   // 写出 DEA
        hist[i] = (dif[i] - d0) * 2.0;                 // 柱 = 2×(DIF-DEA)
    }
    return 0;                                          // 成功
}

// ---------- 金▲信号 ----------
// rows: n*5 交错 [o,h,l,c,v]; bar: 周期字符串; n>=30
// 返回 1=金▲触发 / 0=未触发 / 负数=错误
// 金▲信号导出入口: 直接接受交错排列的K线行(零拷贝视图)
__declspec(dllexport) int gold_signal(const double* rows, int n, const char* bar,
                                      double* ke_out, double* th_out, int* dist_out,
                                      char* info, int infoLen) {
    if (!rows || n < 30 || !bar) {                     // 参数/数据量校验
        if (info && infoLen > 16) snprintf(info, infoLen, "%s", n < 30 ? "K线不足" : "参数错误");  // 写明失败原因
        return 0;                                      // 返回未触发
    }
    // 零拷贝: 直接在交错行主序上跑(st=5), 无 transpose / 无分配
    sk::Ref r = sk::view_interleaved(rows, n);         // 把交错数组包装成 stride=5 的只读 SoA 视图
    return gold_signal_ref(r, bar, ke_out, th_out, dist_out, info, infoLen);  // 调用 SoA 核心完成计算
}

// ---------- 黄金坑复合信号 (六维: 缠论底分型锚+CCI+ADX+MACD+KDJ+动能) ----------
// 黄金坑信号导出入口
__declspec(dllexport) int pit_signal(const double* rows, int n, const char* bar,
                                     double* score_out, char* info, int infoLen) {
    if (!rows || n < 120) {                            // 参数/数据量校验(至少 120 根)
        if (info && infoLen > 16) snprintf(info, infoLen, "K线不足(%d,需120)", n);  // 写明失败原因
        return -1;                                     // 返回错误
    }
    // 唯一需要连续布局的入口: CCI 的 20 窗口 TP 与 KDJ 的 9 窗口极值都靠连续数组才快
    Txb t = tx_make(rows, n);                          // 交错→SoA 转置到线程本地暂存块
    if (!t.p) return -1;                               // 转置失败(内存不足)
    return pit_signal_ref(t.r, bar, score_out, info, infoLen);  // 调用 SoA 核心完成计算
}

// ---------- 全部转折点+力学标注(供网页/接口展示) ----------
// 转折点+基础力学标注导出入口
__declspec(dllexport) int pivots_calc(const double* rows, int n, const char* bar,
                                      int* idx, int* type, double* keA, double* gA, double* fA, int maxP) {
    if (!rows || n < 10 || !bar) return -1;            // 参数校验
    sk::Ref r = sk::view_interleaved(rows, n);      // 零拷贝
    return pivots_calc_ref(r, bar, idx, type, keA, gA, fA, maxP);  // 调用核心计算
}

// ---------- 全部转折点+完整力学量 ----------
// 转折点+完整力学量导出入口
__declspec(dllexport) int pivots_full(const double* rows, int n, const char* bar,
                                      int* idx, int* type, double* pA,
                                      double* keA, double* gA, double* fA,
                                      double* vA, double* mA, double* angA, double* a2A, int* barsA,
                                      double* thOut, int maxP) {
    if (!rows || n < 10 || !bar) return -1;            // 参数校验
    sk::Ref r = sk::view_interleaved(rows, n);      // 零拷贝
    return pivots_full_ref(r, bar, idx, type, pA, keA, gA, fA, vA, mA, angA, a2A, barsA, thOut, maxP);  // 调用核心计算
}

// ---------- 常驻内存K线库 ----------
// 增量喂K线: ts[n]毫秒开盘时间 + bars[n*5](o,h,l,c,v), 按 ts 合并去重, 滚动保留最近 SERIES_CAP 根
// 返回当前滞留根数, -1=错误
// 常驻K线库·增量喂入: 同 ts 覆盖更新, 新 ts 有序插入, 满 600 根滚动淘汰最老
__declspec(dllexport) int store_upsert(const char* key, const long long* ts, const double* bars, int n) {
    if (!key || !ts || (!bars && n > 0) || n < 0) return -1;   // 参数合法性校验
    if (strlen(key) >= 128) return -1;                 // 键过长(超过 key 缓冲) → 错误
    int si = series_find(key);                         // 哈希索引查找序列
    if (si < 0) {                                      // 序列不存在 → 新建槽位
        if (g_seriesN >= SERIES_MAX) {                 // 槽位已满(4096 个)
            // 淘汰最老一个槽位(覆盖第0个并把整体前移一格)
            series_free(&g_series[0]);                 // 释放最老序列的内存
            memmove(&g_series[0], &g_series[1], sizeof(Series) * (SERIES_MAX - 1));  // 全部槽位整体前移一格
            memset(&g_series[SERIES_MAX - 1], 0, sizeof(Series));   // 清掉前移后残留的重复槽
            g_seriesN = SERIES_MAX - 1;                // 占用数减一
            si = 0;                                    // 新序列占用第 0 槽
            hash_rebuild();                            // 下标全变 → 重建哈希索引
        } else {
            si = g_seriesN++;                          // 直接追加到末尾空槽
        }
        Series* s2 = &g_series[si];                    // 新序列槽位
        memset(s2, 0, sizeof(Series));                 // 清零初始化
        snprintf(s2->key, 128, "%s", key);             // 复制序列键
        if (!series_ensure(s2, SERIES_CAP + (n > 0 ? n : 0) + 16)) { series_free(s2); g_seriesN--; return -1; }  // 预分配容量, 失败则回滚
        s2->n = 0;                                     // 初始根数 0
        s2->fired = -1;                                // 金▲缓存置"未算"
        s2->pfire = -1;                                // 黄金坑缓存置"未算"
        hash_put(s2->key, si);                         // 登记到哈希索引
    }
    Series* s = &g_series[si];                         // 取目标序列
    for (int k = 0; k < n; k++) {                      // 逐根处理本批K线
        // 找插入位置(尾部通常递增, 从后往前扫)
        int pos = s->n;                                // 默认插到末尾
        while (pos > 0 && s->ts[pos - 1] > ts[k]) pos--;  // 从后向前找第一个 ts 不大于新K线的位置
        if (pos > 0 && s->ts[pos - 1] == ts[k]) {      // 该时间戳已存在
            // 已存在 → 更新这一根(最新价可能变化)
            s->o[pos - 1] = bars[k * 5];     s->h[pos - 1] = bars[k * 5 + 1];   // 覆盖开/高
            s->l[pos - 1] = bars[k * 5 + 2]; s->c[pos - 1] = bars[k * 5 + 3];   // 覆盖低/收
            s->v[pos - 1] = bars[k * 5 + 4];                 // 覆盖量(未收盘K线会反复更新)
            continue;                                  // 本根处理完毕, 不新增
        }
        if (!series_ensure(s, s->n + 1)) break;        // 容量保证(失败则中止本批)
        memmove(s->ts + pos + 1, s->ts + pos, sizeof(long long) * (size_t)(s->n - pos));  // 时间戳腾出插入位
        memmove(s->o + pos + 1, s->o + pos, sizeof(double) * (size_t)(s->n - pos));       // 开盘价后移
        memmove(s->h + pos + 1, s->h + pos, sizeof(double) * (size_t)(s->n - pos));       // 最高价后移
        memmove(s->l + pos + 1, s->l + pos, sizeof(double) * (size_t)(s->n - pos));       // 最低价后移
        memmove(s->c + pos + 1, s->c + pos, sizeof(double) * (size_t)(s->n - pos));       // 收盘价后移
        memmove(s->v + pos + 1, s->v + pos, sizeof(double) * (size_t)(s->n - pos));       // 成交量后移
        s->ts[pos] = ts[k];                            // 写入新时间戳
        s->o[pos] = bars[k * 5];     s->h[pos] = bars[k * 5 + 1];   // 写入开/高
        s->l[pos] = bars[k * 5 + 2]; s->c[pos] = bars[k * 5 + 3];   // 写入低/收
        s->v[pos] = bars[k * 5 + 4];                   // 写入量
        s->n++;                                        // 根数 +1
        s->fired = -1; // 数据变了, 信号缓存失效
        s->pfire = -1;                                 // 黄金坑缓存同样失效
    }
    series_trim(s);                                    // 超过 600 根则滚动淘汰最老
    return s->n;                                       // 返回当前滞留根数
}

// 用内存里的数据算金▲信号(结果按数据版本缓存): 1=触发 0=未触发 -1=无数据
// 常驻K线库·金▲信号: 直接用滞留内存计算, 结果缓存到数据变更为止
__declspec(dllexport) int store_gold(const char* key, const char* bar,
                                     double* ke_out, double* th_out, int* dist_out,
                                     char* info, int infoLen) {
    if (!key || !bar) return -1;                       // 参数校验
    int si = series_find(key);                         // 查找序列
    if (si < 0) { if (info && infoLen > 16) snprintf(info, infoLen, "内存无该序列"); return -1; }  // 未喂过该序列 → 错误
    Series* s = &g_series[si];                         // 取序列
    if (s->n < 30) { if (info && infoLen > 16) snprintf(info, infoLen, "K线不足(%d根)", s->n); return -1; }  // 金▲至少 30 根
    if (s->fired >= 0) { // 缓存命中
        if (ke_out) *ke_out = s->ke;                   // 回放缓存动能
        if (th_out) *th_out = s->th;                   // 回放缓存阈值
        if (dist_out) *dist_out = s->dist;             // 回放缓存距离
        if (info && infoLen > 16) snprintf(info, infoLen, "%s", s->info);  // 回放缓存提示
        return s->fired;                               // 直接返回缓存结果
    }
    double ke = 0, th = 0; int dist = 0;               // 计算结果暂存
    char buf[160] = { 0 };                             // 提示串暂存
    sk::Ref r{ s->o, s->h, s->l, s->c, s->v, s->n, 1 };          // 直接构造滞留内存的 SoA 视图(零拷贝)
    int r2 = gold_signal_ref(r, bar, &ke, &th, &dist, buf, 160); // 调用核心计算
    s->fired = r2; s->ke = ke; s->th = th; s->dist = dist;       // 结果写入缓存(数据未变则后续直接命中)
    snprintf(s->info, 160, "%s", buf);                 // 提示串写入缓存
    if (ke_out) *ke_out = ke;                          // 输出动能量
    if (th_out) *th_out = th;                          // 输出阈值
    if (dist_out) *dist_out = dist;                    // 输出距离
    if (info && infoLen > 16) snprintf(info, infoLen, "%s", buf);  // 输出提示串
    return r2;                                         // 返回触发状态
}

// 用内存里的数据算黄金坑信号(结果按数据版本缓存): 1=触发 0=未触发 -1=无数据
// 常驻K线库·黄金坑信号: 直接用滞留内存计算, 结果缓存到数据变更为止
__declspec(dllexport) int store_pit(const char* key, const char* bar,
                                    double* score_out, char* info, int infoLen) {
    if (!key || !bar) return -1;                       // 参数校验
    int si = series_find(key);                         // 查找序列
    if (si < 0) { if (info && infoLen > 16) snprintf(info, infoLen, "内存无该序列"); return -1; }  // 未喂过该序列 → 错误
    Series* s = &g_series[si];                         // 取序列
    if (s->n < 120) { if (info && infoLen > 16) snprintf(info, infoLen, "K线不足(%d根)", s->n); return -1; }  // 黄金坑至少 120 根
    if (s->pfire >= 0) { // 缓存命中
        if (score_out) *score_out = s->pscore;         // 回放缓存总分
        if (info && infoLen > 16) snprintf(info, infoLen, "%s", s->pinfo);  // 回放缓存提示
        return s->pfire;                               // 直接返回缓存结果
    }
    double sc = 0;                                     // 计算结果暂存
    char buf[160] = { 0 };                             // 提示串暂存
    sk::Ref r{ s->o, s->h, s->l, s->c, s->v, s->n, 1 };          // 滞留内存的 SoA 视图(零拷贝)
    int r2 = pit_signal_ref(r, bar, &sc, buf, 160);    // 调用核心计算
    s->pfire = r2; s->pscore = sc;                     // 结果写入缓存
    snprintf(s->pinfo, 160, "%s", buf);                // 提示串写入缓存
    if (score_out) *score_out = sc;                    // 输出总分
    if (info && infoLen > 16) snprintf(info, infoLen, "%s", buf);  // 输出提示串
    return r2;                                         // 返回触发状态
}

// 滞留根数(找不到=-1)
// 查询某序列当前滞留的K线根数(序列不存在返回 -1)
__declspec(dllexport) int store_count(const char* key) {
    if (!key) return -1;                               // 空键 → 错误
    int si = series_find(key);                         // 查找序列
    return si < 0 ? -1 : g_series[si].n;               // 存在返回根数, 否则 -1
}

// 统计: 序列个数
// 返回当前滞留的序列总数(合约×周期 个数)
__declspec(dllexport) int store_stats() { return g_seriesN; }

// 清空全部内存数据
// 清空常驻库: 释放所有序列内存并复位计数与哈希索引
__declspec(dllexport) void store_clear() {
    for (int i = 0; i < g_seriesN; i++) series_free(&g_series[i]);  // 逐序列释放内存
    g_seriesN = 0;                                     // 序列计数清零
    hash_clear();                                      // 哈希索引清空
}

} // extern "C"
