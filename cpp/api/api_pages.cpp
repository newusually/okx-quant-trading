// ==================================================================
// api_pages.cpp — 面板渲染组件 (C++ 渲染 HTML + SVG, 浏览器端仅绘制)
//   2026-09-28 交互版 v1 : 滚轮缩放 / 拖拽平移 / 悬停OHLC
//   2026-09-28 交互版 v2 : 恢复旧版视觉(隶书/金渐变标题/彩色卡片/悬浮监测窗)
//                         + 魔法棒光标 + ✨⭐星星粒子 + AJAX 增量刷新(无整页跳转)
//                         + 每卡刷新按钮 + 持仓数量显示 + K线贴边自动加载更早数据
//   计算/排版/SQL 全部在 C++; 浏览器端只做 DOM 替换与绘制, 0 PHP, 0 外部文件
// ==================================================================
/* ==================================================================
 * api_pages.cpp — OKX 量化交易系统 · C++ 服务端网页面板渲染 (apihub.exe)
 *
 * 【文件职责】
 *   零外部文件、零前端框架的服务端渲染面板：全部 HTML/CSS/SVG/JS 都由
 *   本 C++ 文件生成并直接输出给浏览器，无 <script src>、无 fetch 外部资源。
 *
 * 【内容清单】
 *   1. 工具函数     hesc/hnum/hx/htime/hdate/hdate2/jgrab/noyear_str —— HTML转义/格式化/JSON抠字段
 *   2. svg_kline    纯 C++ 画 SVG K线图：蜡烛(红涨绿跌)/成交量/MACD(DIF-DEA)/
 *                   0.618斐波那契线/金▲转折点(标KE动能值,g/F)/买卖点(🚀买入▲加仓🍃平仓)；
 *                   三层结构 zbg固定背景 / zg滚动内容 / zfg固定前景，
 *                   根节点 id=ksvg，vector-effect=non-scaling-stroke 支持滚轮缩放不糊线
 *   3. make_chart   读库→MACD→力学转折点→SVG + KDATA 数据数组(供浏览器交互)
 *   4. 统计/监测/流水/守护 片段渲染 (stats_html/grid_parts/flow_rows/guard_rows)
 *   5. render_panel 面板入口(GET / 或 /panel)：输出整页 HTML(样式来自 PANEL_CSS，
 *                   交互来自 PANEL_JS 内联脚本：滚轮缩放/拖拽平移/悬停OHLC浮窗+十字线/双击复位)
 *   6. ep_frag      AJAX 片段接口(/frag)：stats/grid/flow/guard/chart 增量刷新
 *   7. PANEL_JS     内联交互脚本(原始字符串，JS语法注释不破坏字符串)
 *   8. PANEL_CSS    面板样式(原始字符串，CSS语法注释不破坏字符串)
 * ================================================================== */
#include "../common/hub.h"   // 公共组件：Bar/K线读取(read_recent)/数据库(db_q/db_ex)/参数(Params/P)/ep_*接口/macd_full/pivots_full 等
#include <cstdio>            // C 标准输入输出：snprintf 等
#include <cstring>           // C 字符串处理：strcmp/strchr
#include <ctime>             // C 时间处理：time/localtime_s
#include <cmath>             // 数学函数：fabs
#include <cctype>            // 字符分类：isalnum
#include <map>               // std::map：周期→秒数映射表
#include <algorithm>         // STL 算法(备用)

std::string hesc(std::string s) {   // HTML 转义：把 & < > " 换成实体，防注入/防破坏页面结构
    std::string o; o.reserve(s.size() + 16);   // 预留容量，避免反复扩容
    for (char c : s) {   // 逐字符扫描
        if (c == '&') o += "&amp;";     // & → &amp;
        else if (c == '<') o += "&lt;";   // < → &lt; (防止被当作标签开始)
        else if (c == '>') o += "&gt;";   // > → &gt;
        else if (c == '"') o += "&quot;";   // " → &quot; (防止破坏属性值)
        else o += c;   // 其他字符原样保留
    }
    return o;   // 返回转义后的安全字符串
}
std::string hnum(double v, int prec) {   // 数字格式化：按指定小数位转字符串(用于页面显示价格/金额)
    char b[48]; snprintf(b, 48, "%.*f", prec, v); return b;   // printf 的 %.*f 动态精度格式化
}
std::string hx(const std::string& s) {   // URL query encode (宽松)
    std::string o;   // 结果串
    for (char c : s)   // 逐字符
        if (isalnum((unsigned char)c) || strchr("-_.", c)) o += c;   // 字母数字与 -_. 直通
        else { char b[8]; snprintf(b, 8, "%%%02X", (unsigned char)c); o += b; }   // 其余转 %XX 十六进制
    return o;   // 返回编码结果
}
std::string htime(long long tms) {        // 毫秒 → HH:MM
    time_t t = (time_t)(tms / 1000);   // 毫秒时间戳转秒
    struct tm lt; localtime_s(&lt, &t);   // 转本地时间结构
    char b[16]; snprintf(b, 16, "%02d:%02d", lt.tm_hour, lt.tm_min); return b;   // 只取时:分，两位补零
}
std::string hdate(long long tms) {        // 毫秒 → MM-DD HH:MM
    time_t t = (time_t)(tms / 1000);   // 毫秒转秒
    struct tm lt; localtime_s(&lt, &t);   // 本地时间
    char b[24]; snprintf(b, 24, "%02d-%02d %02d:%02d", lt.tm_mon + 1, lt.tm_mday, lt.tm_hour, lt.tm_min); return b;   // 月-日 时:分(tm_mon 从 0 起)
}
static std::string hdate2(long long tms) { // 秒 → YYYY-MM-DD HH:MM:SS (本地)
    time_t t = (time_t)tms;   // 入参已是秒级
    struct tm lt; localtime_s(&lt, &t);   // 本地时间
    char b[32]; snprintf(b, 32, "%04d-%02d-%02d %02d:%02d:%02d", lt.tm_year + 1900, lt.tm_mon + 1,   // 完整年月日时分秒(tm_year 从 1900 起)
                         lt.tm_mday, lt.tm_hour, lt.tm_min, lt.tm_sec);   // 日时分秒部分
    return b;   // 返回完整时间串
}
// 从 JSON 文本里抠字段(数字或字符串), 供复用 ep_livestats / ep_guard 的返回值
static std::string jgrab(const std::string& j, const char* k) {   // 轻量 JSON 取字段：找 "key": 后面的值
    std::string kk = std::string("\"") + k + "\":";   // 拼出查找目标 `"key":`
    size_t i = j.find(kk); if (i == std::string::npos) return "";   // 找不到该键返回空
    i += kk.size();   // 跳到值起点
    if (i < j.size() && j[i] == '"') { size_t e = j.find('"', i + 1); return j.substr(i + 1, e - i - 1); }   // 字符串值：取两个引号之间
    size_t e = j.find_first_of(",}", i);   // 数字值：到逗号或右括号为止
    return j.substr(i, e - i);   // 返回原始值文本
}
// "2026-09-28 14:57:10" → "09-28 14:57" (去掉年份, 与旧面板一致)
static std::string noyear_str(const std::string& s) {   // 去年份显示：截取第 5~15 字符
    return s.size() >= 16 ? s.substr(5, 11) : s;   // "MM-DD HH:MM" 共 11 字符；过短则原样返回
}

// K线几何常量(svg_kline 与内联交互脚本共用, 两处必须一致)
static const int SK_W = 1180, SK_H = 640, SK_PX0 = 62, SK_PX1 = 1128;   // SVG 画布宽/高、内容区左右边界(世界坐标)，JS 端缩放平移计算依赖
static const double SK_BW = 8.0;      // 每根K线固定像素宽 → 内容比视口宽 = 可拖动滑动视窗

struct Pm { long long t; int kind; double px; double profit; };   // kind 0买 1加 2平   // 买卖标注点：毫秒时间/类型/成交价/平仓盈利

// ==================================================================
//  SVG K线 (C++ 画图: 蜡烛/成交量/MACD/0.618/金▲尖转折/买卖标注)
//    三层结构: zbg=固定背景(横网格/分隔/0.618) · zg=滚动内容(随鼠标平移) · zfg=固定前景(左右刻度条)
// ==================================================================
static std::string svg_kline(const std::vector<Bar>& all, const std::vector<double>& dif,   // 入参：可见K线数组
                             const std::vector<double>& dea, const std::vector<double>& hist,   // DEA 线与 MACD 柱
                             const std::vector<int>& pidx, const std::vector<int>& ptype,   // 力学转折点下标与类型(0=金▲)
                             const std::vector<double>& pprice, const std::vector<double>& pke,   // 转折点价格与 KE 动能值
                             double th, const std::vector<Pm>& marks) {   // KE 阈值 th 与买卖标注列表
    const int W = SK_W, H = SK_H;   // 画布尺寸(视口)
    const int PX0 = SK_PX0;                      // 内容左起点(世界坐标)
    const int PY0 = 14, PY1 = 372;               // 价格区 y   // 价格(K线)区的上下边界
    const int VY0 = 386, VY1 = 440;              // 成交量区   // 成交量柱状区上下边界
    const int MY0 = 452, MY1 = 576;              // MACD 区   // MACD 区上下边界
    const int TY = 596;                          // 时间轴文字 y   // 底部时间标签的基线位置
    int n = (int)all.size();   // K线根数
    if (n < 5) return "<p class='muted'>K线不足</p>";   // 数据太少直接显示提示文字
    double ph = -1e18, pl = 1e18, vmax = 0;   // 最高/最低价、最大成交量(求极值)
    for (int i = 0; i < n; i++) {   // 遍历所有K线
        if (all[i].h > ph) ph = all[i].h;   // 记录最高价
        if (all[i].l < pl) pl = all[i].l;   // 记录最低价
        if (all[i].v > vmax) vmax = all[i].v;   // 记录最大量
    }
    double pad = (ph - pl) * 0.06; if (pad <= 0) pad = ph * 0.001 + 1e-9;   // 价格区间上下各留 6% 空白(单边行情防除零)
    double phh = ph + pad, pll = pl - pad;   // 加边距后的价格上下限
    double span = phh - pll; if (span <= 0) span = 1;   // 价格总跨度(防 0)
    double fibH = ph, fibL = pl, p618 = fibL + (fibH - fibL) * 0.618;   // 斐波那契 0.618 位：区间低点 + 高低差×0.618
    double mmax = 1e-9;   // MACD 柱最大绝对值(防除零)
    for (int i = 0; i < n; i++) { double a = fabs(hist[i]); if (a > mmax) mmax = a; }   // 求 MACD 柱最大幅值
    double bw = SK_BW;   // 每根 K 线的世界坐标宽度(固定 8px)
    double CW = PX0 + n * bw + 18;               // 内容总宽(世界坐标, 可 > 视口宽) → 可拖动
    int cw = (int)(bw * 0.62); if (cw < 1) cw = 1;   // 蜡烛实体宽 = 62% 的档宽
    auto yP = [&](double p) { return PY1 - (p - pll) / span * (PY1 - PY0); };   // 价格→y 像素(线性映射到价格区)
    auto xC = [&](int i) { return PX0 + (i + 0.5) * bw; };   // 下标→该根K线中心的 x 像素

    std::string s;   // SVG 输出串
    char b[256];   // snprintf 格式化缓冲区
    snprintf(b, sizeof b, "<svg id='ksvg' viewBox='0 0 %d %d' width='100%%' style='display:block;background:#fff;touch-action:none'>", W, H);   // 根节点 id=ksvg：viewBox 定世界坐标，白底、禁用触摸默认手势
    s += b;   // 追加到输出

    // ---------- ① 固定背景层: 横网格 + 区带分隔 + 0.618 ----------
    s += "<g id='zbg'>";   // 背景层组(不随平移缩放，永远铺满视口)
    for (int g = 0; g <= 6; g++) {   // 画 7 条横向价格网格线
        double p = pll + span * g / 6.0; int y = (int)yP(p);   // 六等分价格档位与其 y 坐标
        snprintf(b, sizeof b, "<line x1='0' y1='%d' x2='%d' y2='%d' stroke='#eef1f5' stroke-width='1'/>", y, W, y);   // 浅灰横线铺满整宽
        s += b;   // 追加
    }
    snprintf(b, sizeof b, "<line x1='0' y1='%d' x2='%d' y2='%d' stroke='#e3e8f0' stroke-width='1'/>", VY0 - 8, W, VY0 - 8);   // 价格区与成交量区的分隔横线
    s += b;   // 追加
    snprintf(b, sizeof b, "<line x1='0' y1='%d' x2='%d' y2='%d' stroke='#e3e8f0' stroke-width='1'/>", MY0 - 8, W, MY0 - 8);   // 成交量区与 MACD 区的分隔横线
    s += b;   // 追加
    {int y = (int)yP(p618);   // 0.618 黄金分割线的 y 坐标
     snprintf(b, sizeof b, "<line x1='0' y1='%d' x2='%d' y2='%d' stroke='#e6a817' stroke-width='1.6' stroke-dasharray='8,5'/>", y, W, y);   // 金色虚线：0.618 斐波那契水平线
     s += b;}   // 追加
    s += "</g>";   // 背景层结束

    // ---------- ② 滚动层: 蜡烛/成交量/MACD/时间轴/金▲/买卖标注 ----------
    s += "<g id='zg'>";   // 滚动内容层组(整体被 JS 施加 translate+scale 变换，实现平移缩放)
    for (int i = 0; i < n; i++) {   // 逐根画蜡烛与成交量
        const Bar& k = all[i];   // 当前 K 线
        bool up = k.c >= k.o;   // 收盘≥开盘为涨
        const char* col = up ? "#e03131" : "#0a9c56";   // 红涨绿跌配色
        int x = (int)xC(i);   // 该根K线中心 x
        int yh = (int)yP(k.h), yl = (int)yP(k.l);   // 最高/最低价的 y
        int yo = (int)yP(k.o), yc = (int)yP(k.c);   // 开盘/收盘价的 y
        int bt = yo < yc ? yo : yc, bh = abs(yc - yo); if (bh < 1) bh = 1;   // 实体顶部 y 与高度(十字星至少 1px)
        snprintf(b, sizeof b, "<line x1='%d' y1='%d' x2='%d' y2='%d' stroke='%s' stroke-width='1' vector-effect='non-scaling-stroke'/>"   // 影线：细线 + non-scaling-stroke 保证缩放后线宽不变
                 "<rect x='%d' y='%d' width='%d' height='%d' fill='%s'/>",   // 蜡烛实体矩形
                 x, yh, x, yl, col, x - cw / 2, bt, cw, bh, col);   // 影线起止/颜色 与 实体位置/尺寸/颜色
        s += b;   // 追加
        int vh = vmax > 0 ? (int)((k.v / vmax) * (VY1 - VY0)) : 0;   // 成交量柱高：按最大量归一化到量区高度
        snprintf(b, sizeof b, "<rect x='%d' y='%d' width='%d' height='%d' fill='%s' opacity='.55'/>",   // 半透明量柱，颜色随涨跌
                 x - cw / 2, VY1 - vh, cw, vh, col);   // 量柱从区底向上画
        s += b;   // 追加
    }
    // MACD(横跨整个内容宽)
    {int myc = (MY0 + MY1) / 2;   // MACD 零轴 y(区带中线)
     snprintf(b, sizeof b, "<line x1='0' y1='%d' x2='%.0f' y2='%d' stroke='#dde3ea' stroke-width='1'/>", myc, CW, myc); s += b;   // 零轴基准灰线(横跨内容总宽)
     for (int i = 0; i < n; i++) {   // 逐根画 MACD 柱
         double h = hist[i] / mmax * ((MY1 - MY0) / 2.0 - 2);   // 柱高按最大幅值归一化(半区高度，留 2px 余量)
         if (fabs(h) < 0.4) continue;   // 太小的柱不画(防糊)
         int x = (int)xC(i);   // 柱中心 x
         snprintf(b, sizeof b, "<rect x='%d' y='%.0f' width='%d' height='%.0f' fill='%s' opacity='.8'/>",   // MACD 柱：正值在零轴上方、负值在下方
                  x - cw / 2, h >= 0 ? myc - h : myc, cw < 2 ? 2 : cw, fabs(h), h >= 0 ? "#e03131" : "#0a9c56");   // 红多绿空配色
         s += b;   // 追加
     }
     auto line = [&](const std::vector<double>& v, const char* col) {   // 折线生成器：把序列画成 SVG path(用于 DIF/DEA)
         std::string d; d.reserve(v.size() * 14 + 16); bool first = true;   // path 的 d 串与首点标志
         char bb[48];   // 单点格式化缓冲
         for (int i = 0; i < n; i++) {   // 逐点
             double yv = myc - v[i] / mmax * ((MY1 - MY0) / 2.0 - 2);   // 值→y(以零轴为中心)
             if (yv < MY0 - 4) yv = MY0 - 4;   // 上越界裁剪
             if (yv > MY1 + 4) yv = MY1 + 4;   // 下越界裁剪
             snprintf(bb, 48, "%s%.1f,%.1f", first ? "M" : "L", xC(i), yv);   // 首点 M(移笔)，其后 L(连线)
             d += bb; first = false;   // 拼接坐标
         }
         return std::string("<path d='") + d + "' fill='none' stroke='" + col + "' stroke-width='1.3' vector-effect='non-scaling-stroke'/>";   // 无填充折线，缩放时线宽恒定
     };
     s += line(dif, "#e8890c");   // DIF 快线：橙色
     s += line(dea, "#3b7dd8");}   // DEA 慢线：蓝色
    // 时间轴(随内容滚动)
    for (int g = 0; g < 6; g++) {   // 底部 6 个时间刻度(放在滚动层，随平移移动)
        int i = (int)((double)n * g / 6.0); if (i >= n) i = n - 1;   // 均分取对应K线下标(末尾防越界)
        snprintf(b, sizeof b, "<text x='%.0f' y='%d' font-size='10.5' fill='#9aa4b2' text-anchor='middle' font-family='Consolas,monospace'>%s</text>",   // 灰色等宽字时间标签
                 xC(i), TY, hdate(all[i].tms).c_str());   // 位置与文本(MM-DD HH:MM)
        s += b;   // 追加
    }
    // 尖转折金▲ + KE/g/F
    for (int k = 0; k < (int)pidx.size(); k++) {   // 遍历力学转折点
        if (ptype[k] != 0 || pke[k] <= 0 || pke[k] < th) continue;   // 只画"金▲"型且动能达标的点
        int i = pidx[k]; if (i < 0 || i >= n) continue;   // 点下标防越界
        int x = (int)xC(i), y = (int)yP(pprice[k]);   // 金▲位置(转折K线中心/价格处)
        snprintf(b, sizeof b, "<path d='M%d,%d L%d,%d L%d,%d Z' fill='#e6a817'>"   // 金色小三角(尖转折标记主体)
                 "<text x='%d' y='%d' font-size='11' font-weight='bold' fill='#b07708' text-anchor='middle' font-family='Consolas,monospace'>KE=%.2g</text>"   // ▲下方第一行：KE 动能值(买点=动能前25%)
                 "<text x='%d' y='%d' font-size='10.5' fill='#b07708' text-anchor='middle' font-family='Consolas,monospace'>g=%.2g F=%.2g</text>",   // ▲下方第二行：g(重力加速度)/F(牛顿第二定律)力学参数
                 x, y + 10, x - 8, y + 24, x + 8, y + 24,   // 三角形三个顶点
                 x, y + 40, pke[k], x, y + 54, pke[k] * 2 / ((pprice[k] > 0) ? pprice[k] : 1), pke[k]);   // KE 值 / g、F 值(F=KE×2/价格)
        s += b;   // 追加
    }
    // 买卖标注: buy🚀 / add▲ / close🍃(盈利)
    for (const Pm& m : marks) {   // 遍历真实交易标注
        if (m.px <= 0) continue;   // 无有效成交价则跳过
        int x = -1;   // 该笔成交对应的K线 x
        for (int i = 0; i < n; i++) if (all[i].tms == m.t) { x = (int)xC(i); break; }   // 按毫秒时间戳匹配K线
        if (x < 0) continue;   // 视口内没有对应K线则跳过
        int y = (int)yP(m.px);   // 成交价的 y
        const char* col = m.kind == 0 ? "#c0392b" : m.kind == 1 ? "#e6a817" : "#0a7a44";   // 买=深红 / 加仓=金 / 平仓=绿
        if (m.kind == 2) {   // 平仓标注 🍃
            snprintf(b, sizeof b, "<circle cx='%d' cy='%d' r='5' fill='%s'/>"   // 圆点标记平仓位置
                     "<text x='%d' y='%d' font-size='10.5' font-weight='bold' fill='%s' text-anchor='middle' font-family='Consolas,monospace'>🍃%s%.2f</text>",   // 🍃 + 盈利额(盈红亏绿由配色保证，正数带 +)
                     x, y, col, x, y - 12, col, m.profit >= 0 ? "+" : "", m.profit);   // 圆点与文本参数
        } else if (m.kind == 1) {   // 加仓标注 ▲加
            snprintf(b, sizeof b, "<path d='M%d,%d L%d,%d L%d,%d Z' fill='%s'/>"   // 金色小三角
                     "<text x='%d' y='%d' font-size='10' fill='%s' text-anchor='middle'>▲加</text>",   // "▲加"文字(跌时黄金坑加仓点)
                     x, y - 6, x - 6, y - 18, x + 6, y - 18, col, x, y - 22, col);   // 三角与文字参数
        } else {   // 买入标注 🚀
            snprintf(b, sizeof b, "<circle cx='%d' cy='%d' r='5' fill='%s'/>"   // 圆点标记买入位置
                     "<text x='%d' y='%d' font-size='10' fill='%s' text-anchor='middle'>🚀</text>",   // 🚀 = 买入开仓
                     x, y, col, x, y - 10, col);   // 圆点与文字参数
        }
        s += b;   // 追加
    }
    s += "</g>";   // 滚动层结束

    // ---------- ③ 固定前景层: 左右刻度条(盖住滚动内容) + 价格刻度 + 0.618 标签 ----------
    s += "<g id='zfg'>";   // 前景层组(不随变换移动，遮住滚到边上的内容)
    snprintf(b, sizeof b, "<rect x='0' y='0' width='%d' height='%d' fill='#fbfcfe' fill-opacity='.93'/>", PX0 - 2, H);   // 左侧刻度遮罩条(半透明白)
    s += b;   // 追加
    for (int g = 0; g <= 6; g++) {   // 左侧 7 个价格刻度文字
        double p = pll + span * g / 6.0; int y = (int)yP(p);   // 网格档位价格与 y
        snprintf(b, sizeof b, "<text x='%d' y='%d' font-size='11' fill='#9aa4b2' text-anchor='end' font-family='Consolas,monospace'>%s</text>",   // 右对齐价格标签(随价格量级定小数位)
                 PX0 - 6, y + 4, hnum(p, ph > 1000 ? 1 : (ph > 10 ? 3 : 5)).c_str());   // 千元级1位/十元级3位/其他5位小数
        s += b;   // 追加
    }
    snprintf(b, sizeof b, "<rect x='%d' y='0' width='%d' height='%d' fill='#fbfcfe' fill-opacity='.93'/>", W - 108, 108, H);   // 右上角遮罩块(放 0.618 标签)
    s += b;   // 追加
    {int y = (int)yP(p618);   // 0.618 线的 y
     snprintf(b, sizeof b, "<text x='%d' y='%d' font-size='11.5' font-weight='bold' fill='#b07708' text-anchor='end' font-family='Consolas,monospace'>0.618 %s</text>",   // 金色加粗 "0.618 数值" 标签
              W - 6, y - 5, hnum(p618, ph > 1000 ? 2 : 4).c_str());   // 靠右、略高于线
     s += b;}   // 追加
    s += "</g>";   // 前景层结束
    s += "</svg>";   // SVG 根节点闭合
    return s;   // 返回完整 SVG 字符串
}

// ==================================================================
//  K线数据包 (C++ 计算: 读库 → MACD → 力学 → SVG + 浏览器端交互数据)
// ==================================================================
struct ChartOut { std::string svg; std::string data; int n = 0; bool hasMore = false; };   // 图表输出：SVG串 / KDATA数据JSON / 可见根数 / 是否还有更早K线

static ChartOut make_chart(const std::string& inst, const std::string& tf, int nvis) {   // 生成指定合约+周期的图表(可见 nvis 根)
    ChartOut co;   // 输出结构
    std::vector<Bar> all = read_recent(inst, tf, 1000);   // 读库：最近 1000 根 K 线
    int n = (int)all.size();   // 实际根数
    std::vector<double> dif, dea, hist, e12, e26;   // MACD 各序列
    if (n > 0) {   // 有数据才算指标
        std::vector<double> closes;   // 收盘价序列(含更早历史以预热 MACD)
        std::string kt = ktable(inst, tf);   // 该合约对应K线表名
        RowSet wr = db_q("SELECT c FROM " + kt + " WHERE c>0 AND candle_time<" +   // 取本批最早K线之前的 700 根收盘价(倒序)
                         std::to_string(all[0].tms) + " ORDER BY candle_time DESC LIMIT 700");   // 作为 MACD 预热数据
        if (wr.ok) for (auto it = wr.rows.rbegin(); it != wr.rows.rend(); ++it) closes.push_back(atof_s((*it)[0]));   // 倒序结果反转成正序加入
        for (auto& r : all) closes.push_back(r.c);   // 追加本批收盘价
        macd_full(closes, 12, 26, 9, dif, dea, hist, e12, e26);   // 计算 MACD(12,26,9)：DIF/DEA/柱
    }
    int start = n > nvis ? n - nvis : 0;   // 只保留最后 nvis 根的起点下标
    std::vector<Bar> vis(all.begin() + start, all.end());   // 可见K线切片
    std::vector<double> d1, a1, h1;   // 可见段的 DIF/DEA/柱
    if (n > 0) {   // 有数据才切片
        d1.assign(dif.begin() + start, dif.end());   // DIF 切片
        a1.assign(dea.begin() + start, dea.end());   // DEA 切片
        h1.assign(hist.begin() + start, hist.end());   // MACD 柱切片
    }
    // 力学已删(0929: sigcore 全链路退役) → 金▲标注数组恒空, SVG K线主体保留
    std::vector<int> pidx, ptype;               // 转折点下标/类型(恒空)
    std::vector<double> pp, pke;                // 转折点价格/动能(恒空)
    double th = 0;                              // 阈值恒 0
    // 是否还有更早的K线可加载(决定"左移贴边自动加载")
    co.hasMore = false;   // 默认没有更早数据
    if (n > 0) {   // 有数据才查
        RowSet hm = db_q("SELECT 1 FROM " + ktable(inst, tf) + " WHERE c>0 AND candle_time<" +   // 查库中是否存在更早的有效K线
                         std::to_string(all[0].tms) + " LIMIT 1");   // 存在 1 条即说明可加载
        co.hasMore = hm.ok && !hm.rows.empty();   // 查到即置 true
    }
    // 买卖标注(买入🚀/加仓▲/平仓🍃带盈利, 最近7天)
    std::vector<Pm> marks;   // 标注列表
    {
        std::string D = " trade_time >= NOW() - INTERVAL 7 DAY";   // 时间过滤：只取最近 7 天
        RowSet rs = db_q("SELECT UNIX_TIMESTAMP(trade_time)*1000, price, remark FROM trade_flow"   // 查开仓流水(hybrid open=买入)
                         " WHERE inst_id='" + sqlesc(inst) + "' AND action='buy' AND remark LIKE 'hybrid open%'" + D);   // 限定本合约
        if (rs.ok) for (auto& r : rs.rows) marks.push_back({ atoll_s(r[0]), 0, atof_s(r[1]), 0 });   // kind 0 = 买入🚀
        rs = db_q("SELECT UNIX_TIMESTAMP(trade_time)*1000, price, remark FROM trade_flow"   // 查加仓流水
                  " WHERE inst_id='" + sqlesc(inst) + "' AND action='add'" + D);   // action=add
        if (rs.ok) for (auto& r : rs.rows) marks.push_back({ atoll_s(r[0]), 1, atof_s(r[1]), 0 });   // kind 1 = 加仓▲
        rs = db_q("SELECT FLOOR(UNIX_TIMESTAMP(trade_time)/60)*60000, MAX(price), SUM(COALESCE(profit,0)) FROM trade_flow"   // 平仓流水：按分钟聚合(同分钟多笔合一)
                  " WHERE inst_id='" + sqlesc(inst) + "' AND action='close' AND profit IS NOT NULL"
                  " AND remark LIKE 'OKX pos-history%'" + D + " GROUP BY 1 ORDER BY 1 ASC");   // 只取 OKX 真实盈亏记录，按时间升序
        if (rs.ok) for (auto& r : rs.rows) marks.push_back({ atoll_s(r[0]), 2, atof_s(r[1]), atof_s(r[2]) });   // kind 2 = 平仓🍃(带盈利额)
    }
    co.svg = svg_kline(vis, d1, a1, h1, pidx, ptype, pp, pke, th, marks);   // 生成 SVG K线图
    co.n = (int)vis.size();   // 可见根数

    // 浏览器端交互数据(几何常量与 svg_kline 严格一致)
    double cwWorld = SK_PX0 + co.n * SK_BW + 18;   // 内容总宽(世界坐标)   // JS 端平移范围计算需要
    std::string kd = "{\"w\":" + std::to_string(SK_W) + ",\"h\":" + std::to_string(SK_H) +   // KDATA JSON：画布宽高
                     ",\"px0\":" + std::to_string(SK_PX0) + ",\"px1\":" + std::to_string(SK_PX1) +   // 内容左右边界
                     ",\"bw\":" + jnum(SK_BW) + ",\"cw\":" + jnum(cwWorld) +   // 每根宽度与内容总宽
                     ",\"n\":" + std::to_string(co.n) +   // 可见根数
                     ",\"has_more\":" + (co.hasMore ? "true" : "false") +   // 是否还能加载更早
                     ",\"oldest\":" + std::to_string(vis.empty() ? 0 : vis.front().tms / 1000) +   // 最早一根的秒级时间
                     ",\"newest\":" + std::to_string(vis.empty() ? 0 : vis.back().tms / 1000) +   // 最新一根的秒级时间
                     ",\"bars\":[";   // K线数据数组开始
    for (size_t i = 0; i < vis.size(); i++) {   // 逐根输出 [时间,开,高,低,收,量]
        const Bar& k = vis[i];   // 当前根
        if (i) kd += ",";   // 逗号分隔
        kd += "[" + std::to_string(k.tms / 1000) + "," + jnum(k.o) + "," + jnum(k.h) + "," +   // 秒级时间/开/高
              jnum(k.l) + "," + jnum(k.c) + "," + jnum(k.v) + "]";   // 低/收/量
    }
    kd += "]}";   // 数组与对象闭合
    co.data = kd;   // 存入输出
    return co;   // 返回图表数据包
}

// ==================================================================
//  统计条 / 监测 / 流水 / 守护 片段 (全部 C++ 渲染)
// ==================================================================
struct LS { int closed = 0, wins = 0, trades = 0, openPos = 0; double net = 0, upl = 0, posM = 0;   // 已平仓数/胜场/交易笔数/持仓数 与 已实现/浮动/占用保证金
            double feeS = 0, fundS = 0; std::string snap, inst; };   // 手续费合计/资金费合计 与 快照时间/合约

static LS load_ls(const std::string& inst) {   // 从 ep_livestats 接口加载统计信息
    LS o; o.inst = inst;   // 初始化
    Params q; q["inst"] = inst;   // 组装参数
    std::string j = ep_livestats(q);   // 调统计接口拿 JSON
    o.net = atof_s(jgrab(j, "net_pnl"));   // 已实现盈亏
    o.upl = atof_s(jgrab(j, "upl"));   // 浮动盈亏
    o.posM = atof_s(jgrab(j, "pos_margin"));   // 占用保证金
    o.feeS = atof_s(jgrab(j, "fee_sum"));   // 手续费合计
    o.fundS = atof_s(jgrab(j, "fund_sum"));   // 资金费合计
    o.closed = atoi(jgrab(j, "closed").c_str());   // 已平仓笔数
    o.wins = atoi(jgrab(j, "wins").c_str());   // 获胜笔数
    o.trades = atoi(jgrab(j, "trades").c_str());   // 交易总笔数
    o.openPos = atoi(jgrab(j, "open_pos").c_str());   // 当前持仓数
    o.snap = jgrab(j, "snap_time");   // 快照时间
    return o;   // 返回统计结构
}

// 顶栏实时统计(含持仓数量, 3秒 AJAX 增量替换)
static std::string stats_html(const std::string& inst) {   // 渲染顶栏统计条 HTML
    LS s = load_ls(inst);   // 取统计
    double all = s.net + s.upl;   // 总盈亏 = 已实现 + 浮动
    double wr = s.closed > 0 ? (double)s.wins / s.closed * 100 : 0;   // 胜率%
    auto ncol = [](double v) { return v > 0 ? "up" : (v < 0 ? "dn" : ""); };   // 盈亏配色类名：正=up红 负=dn绿
    char b[64];   // 数字格式化缓冲
    auto nc = [&](double v) {   // 生成带色盈亏 <b> 片段(正数带 +)
        snprintf(b, sizeof b, "%s%.2f U", v > 0 ? "+" : "", v);   // 格式化为 "+12.34 U"
        return std::string("<b class='") + ncol(v) + "'>" + b + "</b>";   // 包上红/绿样式
    };
    std::string o;   // 输出串
    o += "<span>总盈亏(含浮动) " + nc(all) + "</span>";   // 顶栏项：总盈亏
    o += "<span>已实现 " + nc(s.net) + "</span>";   // 顶栏项：已实现盈亏
    o += "<span>浮动 " + nc(s.upl) + "</span>";   // 顶栏项：浮动盈亏
    snprintf(b, sizeof b, "%d", s.openPos);   // 持仓数转字符串
    o += std::string("<span>持仓 <b class='") + (s.openPos > 0 ? "up" : "") + "'>" + b + "</b> 个 · 保证金 <b>" +   // 顶栏项：持仓个数(有仓标红)与占用保证金
         hnum(s.posM, 2) + " U</b></span>";   // 保证金数值(2位小数)
    snprintf(b, sizeof b, "%d", s.trades);   // 交易笔数转字符串
    o += std::string("<span>交易 <b>") + b + "</b> 笔</span>";   // 顶栏项：交易笔数
    snprintf(b, sizeof b, "%d", s.closed);   // 已平仓数转字符串
    o += std::string("<span>已平仓 <b>") + b + "</b> · 胜率 <b>" + hnum(wr, 1) + "%</b></span>";   // 顶栏项：已平仓数与胜率
    o += "<span>手续费 <b>" + hnum(s.feeS, 3) + "</b> · 资金费 <b>" + hnum(s.fundS, 3) + "</b></span>";   // 顶栏项：手续费与资金费合计
    return o;   // 返回统计条 HTML
}
static std::string stats_extra(const std::string& inst) {   // 只取持仓数量(给 K线卡与页脚显示)
    LS s = load_ls(inst);   // 取统计
    // 图表卡右侧的持仓数量提示(用户要求: 持仓多少要写出来)
    return std::to_string(s.openPos);   // 返回持仓数字符串
}

// 监测卡: 概要行 + 表格行
static void grid_parts(const std::string& tf, int nvis, std::string& lineOut, std::string& rowsOut) {   // 渲染实时监测卡：概要行与持仓表格行(两个出参)
    char b[512];   // 格式化缓冲
    LS s = load_ls("ETH-USDT-SWAP");   // 借用统计接口取全账户持仓数/保证金
    std::string rows;   // 表格行累积
    int armed = 0, wait = 0, block = 0, closed = 0, nrow = 0;   // 已加仓/等待/风控/平仓计数与行数
    RowSet rs = db_q("SELECT inst_id,state,last_px,avg_px,ladder_adds,tp_px,tp_pct,dist_down_pct,note"   // 查监测表：未平仓记录，已加仓/等待优先，距止盈近的靠前
                     " FROM grid_signal WHERE state <> '已平仓'"
                     " ORDER BY (state LIKE '★%' OR state LIKE '等待%') DESC, dist_down_pct ASC LIMIT 60");   // 最多 60 行
    if (rs.ok) for (auto& r : rs.rows) {   // 逐行渲染
        nrow++;   // 行数+1
        const std::string& st = r[1];   // 状态文本
        if (st.find("已加仓") != std::string::npos || st.find("已触发") != std::string::npos) armed++;   // 已加仓/已触发计数
        else if (st.find("风控") != std::string::npos) block++;   // 风控拦截计数
        else if (st.find("平仓") != std::string::npos) closed++;   // 待平仓计数
        else wait++;   // 其余算等待触发
        const char* col = st.find("已加仓") != std::string::npos ? "#e03131"   // 状态色：已加仓=红
                        : (st.find("风控") != std::string::npos ? "#e87c00"   // 风控=橙
                        : (st.find("平仓") != std::string::npos ? "#98a0ab" : "#2f6fed"));   // 平仓=灰 / 其他=蓝
        snprintf(b, sizeof b,   // 拼一行表格：可点击行(tr), 带合约与备注提示
            "<tr class='clickable' data-inst='%s' title='%s'>"   // 行头：data-inst 供点击切K线，title=悬浮备注
            "<td style='color:#1c7ed6;text-decoration:underline'>%s</td>"   // 合约名：蓝色下划线(可点击)
            "<td><b style='color:#e03131'>%s</b> 次</td>"   // 加仓次数：红色加粗
            "<td class='mono'>%s</td><td class='mono'>%s</td>"   // 现价/均价：等宽字体
            "<td class='mono'>%s <b style='color:#c0392b'>+%s%%</b></td>"   // 止盈价与止盈百分比(红)
            "<td style='color:%s;font-weight:600'>%s</td></tr>",   // 状态格：按状态着色加粗
            hesc(r[0]).c_str(), hesc(r[8]).c_str(), hesc(r[0]).c_str(),   // 合约/备注/合约(已转义)
            hesc(r[4]).c_str(), hesc(r[2]).c_str(), hesc(r[3]).c_str(),   // 加仓次数/现价/均价
            hesc(r[5]).c_str(), hesc(r[6]).c_str(), col, hesc(r[1]).c_str());   // 止盈价/止盈%/状态色/状态
        rows += b;   // 累积
    }
    if (rows.empty()) rows = "<tr><td colspan='6' class='muted'>当前无持仓在监测</td></tr>";   // 空表占位提示
    snprintf(b, sizeof b,   // 概要行：持仓/保证金/监测/等待/加仓信号/风控/平仓 计数
        "<b>持仓 %d 个</b> · 保证金 <b>%.2f U</b> &nbsp;|&nbsp; 监测 %d 个 &nbsp;|&nbsp; 等待触发 %d "
        "&nbsp;|&nbsp; <b>已加仓信号 %d</b> &nbsp;|&nbsp; 风控拦截 %d &nbsp;|&nbsp; 已平仓 %d",   // 各计数项
        s.openPos, s.posM, nrow, wait, armed, block, closed);   // 填充数值
    lineOut = b;   // 输出概要行
    rowsOut = rows;   // 输出表格行
    (void)tf; (void)nvis;   // 参数保留但未用(保持接口签名)
}

// 流水卡: 表格行(最近 60 条, 平仓自动匹配 OKX 真实盈利)
static std::string flow_rows(const std::string& tf, int nvis, int& cntOut) {   // 渲染交易流水表格行(出参=条数)
    (void)tf; (void)nvis;   // 参数保留但未用
    std::string rows;   // 行累积
    int cnt = 0;   // 条数计数
    RowSet rs = db_q("SELECT trade_time,inst_id,action,price,COALESCE(profit,0),COALESCE(fee,0),COALESCE(funding,0)"   // 查流水：时间/合约/动作/价/盈亏/手续费/资金费(空值归0)
                     " FROM trade_flow WHERE action IN ('buy','add','close')"
                     " ORDER BY trade_time DESC, id DESC LIMIT 60");   // 只看买/加/平，最新 60 条
    if (rs.ok) for (auto& r : rs.rows) {   // 逐行渲染
        cnt++;   // 计数
        const char* act = strcmp(r[2].c_str(), "buy") == 0 ? "🚀买入" : (strcmp(r[2].c_str(), "add") == 0 ? "▲加仓" : "🍃平仓");   // 动作→表情文案
        bool isClose = strcmp(r[2].c_str(), "close") == 0;   // 是否平仓记录
        double pf = atof_s(r[4]);   // 净盈亏
        std::string pnlCell = isClose   // 盈亏单元格
            ? "<td class='" + std::string(pf > 0 ? "up" : (pf < 0 ? "dn" : "")) + "'>" +   // 平仓：按盈亏红/绿着色
              (pf > 0 ? "+" : "") + hnum(pf, 2) + "</td>"   // 正数带 +，2位小数
            : "<td class='muted'>持仓中</td>";   // 未平仓：灰色"持仓中"
        rows += "<tr class='clickable' data-inst='" + hesc(r[1]) + "'>"   // 可点击行(点合约名切K线)
              + "<td class='mono'>" + hesc(noyear_str(r[0])) + "</td>"   // 时间(去年份)
              + "<td style='color:#1c7ed6;text-decoration:underline'>" + hesc(r[1]) + "</td>"   // 合约名：蓝链样式
              + "<td>" + act + "</td><td class='mono'>" + hnum(atof_s(r[3]), 6) + "</td>"   // 动作与成交价(6位小数)
              + pnlCell   // 盈亏格
              + "<td class='muted mono'>" + (atof_s(r[5]) != 0 ? hnum(atof_s(r[5]), 4) : "-") + "</td>"   // 手续费(0 显示 -)
              + "<td class='muted mono'>" + (atof_s(r[6]) != 0 ? hnum(atof_s(r[6]), 4) : "-") + "</td></tr>";   // 资金费(0 显示 -)
    }
    if (rows.empty()) rows = "<tr><td colspan='7' class='muted'>暂无匹配流水</td></tr>";   // 空表占位
    cntOut = cnt;   // 回传条数
    return rows;   // 返回行 HTML
}

// 守护卡: 表格行(解析 finally_guard 的 guard_status.json)
static std::string guard_rows(const std::string& gj) {   // 解析守护进程状态 JSON 并渲染表格行
    std::string rows;   // 行累积
    size_t p = gj.find("\"targets\":[");   // 定位 targets 数组起点
    if (p == std::string::npos) return "<tr><td colspan='6' class='muted'>暂无守护信息</td></tr>";   // 无数据占位
    size_t q0 = p + 11, depth = 0, st = q0;   // 数组内起点、花括号深度、当前对象起点
    for (size_t i = q0; i <= gj.size(); i++) {   // 逐字符扫描
        char c = i < gj.size() ? gj[i] : ']';   // 末尾补 ']' 促使最后一个对象闭合
        if (c == '{') { if (depth == 0) st = i; depth++; }   // 进入对象：深度0时记起点
        else if (c == '}') {   // 退出对象
            depth--;   // 深度-1
            if (depth == 0) {   // 完整对象取出来了
                std::string o = gj.substr(st, i - st + 1);   // 该守护目标的 JSON 对象文本
                auto grab = [&](const char* k) -> std::string {   // 局部取字段函数(同 jgrab 逻辑)
                    std::string kk = std::string("\"") + k + "\":";   // 查找 `"key":`
                    size_t j = o.find(kk); if (j == std::string::npos) return "";   // 找不到返回空
                    j += kk.size();   // 跳到值
                    if (j < o.size() && o[j] == '"') { size_t e = o.find('"', j + 1); return o.substr(j + 1, e - j - 1); }   // 字符串值
                    size_t e = o.find_first_of(",}", j);   // 数字值
                    return o.substr(j, e - j);   // 返回
                };
                std::string lab = grab("label"), kind = grab("kind"), run = grab("run"),   // 名称/类型/是否运行
                            ok = grab("ok"), pid = grab("pid"), msg = grab("msg"), rs2 = grab("restarts");   // 探活/进程号/说明/重启次数
                rows += "<tr><td>" + hesc(lab) + "</td><td class='muted'>" + hesc(kind) + "</td>"   // 名称 + 类型(灰)
                      + "<td>" + (run == "1" ? "<span class='oktag'>运行</span>" : "<span class='badtag'>停止</span>") + "</td>"   // 运行状态徽标(绿/红)
                      + "<td>" + (ok == "1" ? "<span class='oktag'>正常</span>" : "<span class='badtag'>异常</span>") + "</td>"   // 探活徽标(绿/红)
                      + "<td class='mono'>" + hesc(pid == "0" || pid.empty() ? "-" : pid) + "</td>"   // 进程号(0/空 显示 -)
                      + "<td class='mono muted'>" + hesc(rs2.empty() ? "0" : rs2) + "</td>"   // 重启次数(空补 0)
                      + "<td class='muted'>" + hesc(msg) + "</td></tr>";   // 说明文字
            }
        }
    }
    if (rows.empty()) rows = "<tr><td colspan='7' class='muted'>暂无守护信息</td></tr>";   // 解析结果为空占位
    return rows;   // 返回行 HTML
}

// ==================================================================
//  面板入口: GET / 或 /panel
// ==================================================================
extern const char* PANEL_JS;   // 定义见文件末尾(内联交互脚本), 此处前置声明

std::string render_panel(const Params& q) {   // 整页面板渲染入口
    std::string inst = get_inst(q);   // 当前合约(URL 参数，默认权威池首个)
    std::string tf = P(q, "tf", "5m");   // K线周期，默认 5m
    static const std::map<std::string, int> IV = { {"1m",60},{"3m",180},{"5m",300},{"15m",900},{"1h",3600} };   // 合法周期→秒数映射
    if (!IV.count(tf)) tf = "5m";   // 非法周期回退 5m
    int nvis = atoi(P(q, "n", "300").c_str());   // 可见根数，默认 300
    if (nvis < 60) nvis = 60;   // 下限 60
    if (nvis > 1500) nvis = 1500;   // 上限 1500

    ChartOut chart = make_chart(inst, tf, nvis);   // 生成 K线 SVG 与 KDATA 交互数据

    // 合约下拉(权威池全量) + 周期按钮 + 根数
    std::string optInsts;   // 合约 <option> 列表
    {
        RowSet rs = db_q("SELECT inst_id FROM symbol_pool ORDER BY inst_id");   // 权威合约池全量
        std::string cur = inst;   // 当前选中合约
        bool found = false;   // 当前合约是否在池中
        if (rs.ok) for (auto& r : rs.rows) {   // 逐个生成选项
            if (cur == r[0]) found = true;   // 标记当前合约在池
            optInsts += std::string("<option value='") + hesc(r[0]) + "'" + (cur == r[0] ? " selected" : "") + ">" + hesc(r[0]) + "</option>";   // 下拉项(当前项 selected)
        }
        if (!found) optInsts = "<option value='" + hesc(cur) + "' selected>" + hesc(cur) + "</option>" + optInsts;   // 当前合约不在池则插到最前
    }
    std::string tfBtns;   // 周期按钮组
    for (auto& kv : IV) tfBtns += "<span class='tf" + std::string(tf == kv.first ? " on" : "") + "' data-tf='" + kv.first + "'>" + kv.first + "</span>";   // 每个周期一个可点击 chip(当前高亮 on)
    int NS[] = { 150, 300, 500, 800, 1200, 1500 };   // 根数下拉候选
    std::string optN;   // 根数 <option> 列表
    for (int v : NS) optN += "<option value='" + std::to_string(v) + "'" + (nvis == v ? " selected" : "") + ">" + std::to_string(v) + "根</option>";   // 当前根数 selected

    // 最近交易快捷 chips
    std::string recent;   // 最近交易合约 chip 列表
    {
        std::string bj = ep_boot(Params());   // 调 boot 接口拿概要 JSON
        size_t a = bj.find("\"recent\":[");   // 定位 recent 数组
        if (a != std::string::npos) {   // 有数据才解析
            size_t e = bj.find(']', a);   // 数组终点
            std::string arr = bj.substr(a + 10, e - a - 10);   // 取数组内容(去掉 "recent":[ 前缀)
            size_t i = 0;   // 扫描位置
            while (i < arr.size()) {   // 逐个抽引号字符串
                size_t s0 = arr.find('"', i); if (s0 == std::string::npos) break;   // 左引号
                size_t s1 = arr.find('"', s0 + 1); if (s1 == std::string::npos) break;   // 右引号
                std::string v = arr.substr(s0 + 1, s1 - s0 - 1);   // 合约全名
                std::string sh = v; size_t us = sh.find("-USDT-SWAP"); if (us != std::string::npos) sh = sh.substr(0, us);   // 短名(去掉 -USDT-SWAP 后缀)
                recent += "<span class='tf" + std::string(v == inst ? " on" : "") + "' data-inst='" + hesc(v) + "'>" + hesc(sh) + "</span>";   // chip(当前高亮，点击切合约)
                i = s1 + 1;   // 继续
            }
        }
    }

    // 各卡片初始数据(C++ 渲染, 之后由 AJAX 增量替换)
    std::string stHtml = stats_html(inst);   // 顶栏统计条
    std::string posCnt = stats_extra(inst);   // 持仓数量
    std::string gLine, gRows, fRows, gdRows;   // 监测概要/监测行/流水行/守护行
    grid_parts(tf, nvis, gLine, gRows);   // 监测卡数据
    int fCnt = 0;   // 流水条数
    fRows = flow_rows(tf, nvis, fCnt);   // 流水卡数据
    gdRows = guard_rows(ep_guard(Params()));   // 守护卡数据
    LS ls = load_ls(inst);   // 统计(备用)

    std::string o;   // 整页 HTML 输出串
    o.reserve(120000);   // 预留 ~120KB 容量避免反复扩容
    o += "<!DOCTYPE html><html lang='zh-CN'><head><meta charset='UTF-8'>"   // 页面头：中文 UTF-8
         "<meta name='viewport' content='width=device-width,initial-scale=1'>"   // 移动端视口适配
         "<title>OKX 量化交易面板 · C++</title>"   // 浏览器标签页标题
         "<link rel='stylesheet' href='/panel.css?v=0928k'>"   // 引入面板样式(PANEL_CSS 由 apihub 提供，带版本号防缓存)
         "</head><body>"   // 头部结束，正文开始

         // ---- 顶栏 ----
         "<div class='top'><b>📈 OKX 量化交易面板</b>"   // 顶栏：金色渐变站点标题
         "<select id='symbol'>" + optInsts + "</select>"   // 合约下拉框(id=symbol，JS 监听切换)
         "<span id='status'>✓ 实时同步 " + hdate2((long long)time(nullptr)) + "</span>"   // 同步状态文字(id=status，JS 定时更新)
         "<span style='flex:1'></span>"   // 弹性占位(把右侧内容推到行尾)
         "<span id='slineTop' class='muted'>引擎 tradehub(C++) · 无PHP · 无客户端</span></div>"   // 架构说明文字

         // ---- 导航 ----
         "<div class='top nav'>"   // 第二条导航栏
         "<a href='#chartCard'>K线图</a><a href='#flowCard'>交易流水</a>"   // 页内锚点：K线卡/流水卡
         "<a href='#guardCard'>守护状态</a><a href='#monWin'>实时监测</a>"   // 页内锚点：守护卡/悬浮监测窗
         "<span style='flex:1'></span>"   // 弹性占位
         "<button class='rbtn' onclick='OKXP.all()'>⟳ 全部刷新</button>"   // 全部刷新按钮(调 JS OKXP.all)
         "<button class='rbtn' onclick='OKXP.chart()'>⟳ K线</button>"   // 仅刷新K线按钮
         "</div>"   // 导航结束

         // ---- 策略跑马灯 ----
         "<div class='top' style='padding-top:5px;padding-bottom:6px'>"   // 第三条栏：策略跑马灯容器
         "<div id='aiMarq'><span class='mq'>策略：买入=15m+5m 黄金坑共振 · 20X · 每笔1U · 价格+2%止盈(ROI40%) · 永不止损 · "   // 跑马灯内容第一段：买入策略
         "加仓=跌时5m黄金坑 每轮+⅓U 不限轮数 · 只买权威池(symbol_pool) · 只买涨　|　"   // 第二段：加仓与选币规则
         "架构：C++ 渲染 HTML+SVG(apihub) + C++ 交易中枢(tradehub) + C++ 数据中枢(datahub) + C++ 守护(guard) · "   // 第三段：系统架构
         "0 PHP · 0 外部JS · AJAX 增量刷新(无整页跳转)　|　数据：全量 MySQL 存储(无 json 落盘)</span></div></div>"   // 第四段：数据架构，跑马灯结束

         // ---- 实时统计条 ----
         "<div class='stats' id='statsBar'>" + stHtml + "</div>"   // 统计条(id=statsBar，3秒 AJAX 替换)

         "<div class='wrap'>"   // 主体容器开始

         // ---- K线卡 ----
         "<div class='card c-chart' id='chartCard'>"   // K线卡片容器(淡紫渐变背景)
         "<h3 class='hc-chart'><span class='ht'>K线图（拖动=K线跟随鼠标平移 · 滚轮缩放 · 拖到最早端自动加载更早K线 · 悬停OHLC · 红涨绿跌）</span>"   // 卡标题(紫色渐变字)+操作说明
         "<span class='fr'><span class='muted' id='chartPos'>持仓 " + posCnt + " 个</span>"   // 标题右侧：持仓数量提示
         "<select id='nsel' class='inp' style='padding:2px 6px'>" + optN + "</select>"   // 根数下拉(id=nsel)
         "<button class='rbtn' onclick='OKXP.chart()'>⟳</button>"   // K线刷新按钮
         "<button class='rbtn' onclick='OKXP.latest()'>⏭ 最新</button></span></h3>"   // 跳回最新按钮
         "<div id='tfs'>" + tfBtns + "</div>"   // 周期按钮组(id=tfs)
         "<div id='recentInsts'><span class='muted' style='font-size:12px'>⚡最近交易:</span>" + recent + "</div>"   // 最近交易合约 chips 行
         "<div class='kwrap' id='kwrap'><div id='ktip'></div>"   // K线包裹层(id=kwrap)+悬停 OHLC 浮窗(id=ktip)
         "<div id='kgraph'>" + chart.svg + "</div>"   // SVG 图容器(id=kgraph，JS 替换内容)
         "<div id='viewHint'></div><div id='fetch'></div></div>"   // 视图提示角标(id=viewHint)+加载中提示(id=fetch)
         "<div class='legend' id='klegend'>"   // 图例区(id=klegend)
         "<span style='color:#e6a817;font-weight:bold'>▲金箭头</span>=买入点(动能前25%) · "   // 图例：金▲含义
         "<b>🚀</b>=买入开仓 · <b style='color:#e6a817'>▲加</b>=跌时黄金坑加仓 · "   // 图例：🚀与▲加含义
         "<b>🍃</b>=平仓(旁标盈利额, 盈红亏绿) · <b style='color:#b07708'>KE=½mv²</b> 动能 · "   // 图例：🍃与 KE 公式
         "<b style='color:#b07708'>g=v²/2h</b> 重力加速度 · <b style='color:#b07708'>F=mg</b>(牛顿二) · "   // 图例：g 与 F 公式
         "<span style='color:#e6a817'>┄0.618黄金分割</span> · <span style='color:#e8890c'>—DIF</span> "   // 图例：0.618 线与 DIF 线色
         "<span style='color:#3b7dd8'>—DEA</span> MACD(12,26,9)　"   // 图例：DEA 线色与 MACD 参数
         "<b>🖱 按住拖动=K线跟随鼠标 · 滚轮缩放 · 双击回最新 · 悬停看OHLC · 拖到最左端自动加载更早K线</b>　"   // 交互说明加粗一行
         "<b style='color:#b07708'>当前 <span id='kinfo'>" + hesc(inst) + " · " + hesc(tf) + " · " + std::to_string(nvis) + "根</span></b></div>"   // 当前合约/周期/根数(id=kinfo，JS 更新)
         "</div>"   // K线卡结束

         // ---- 流水卡 ----
         "<div class='card c-flow' id='flowCard' style='margin-top:12px'>"   // 流水卡片容器(淡绿渐变)
         "<h3 class='hc-flow'><span class='ht'>交易流水（实时更新 · 平仓自动匹配OKX真实盈利 · 点合约名切K线）</span>"   // 卡标题(绿色渐变字)
         "<span class='fr'><span class='muted' id='flowCnt'>共 " + std::to_string(fCnt) + " 条</span>"   // 条数显示(id=flowCnt)
         "<button class='rbtn' onclick='OKXP.flow()'>⟳</button></span></h3>"   // 流水刷新按钮
         "<div class='tw'><table id='flowT'><thead><tr>"   // 可滚动表容器与流水表(id=flowT)
         "<th>时间</th><th>合约</th><th>方向</th><th>价格</th><th>净盈亏$</th><th>手续费$</th><th>资金费$</th>"   // 表头七列
         "</tr></thead><tbody id='flowRows'>" + fRows + "</tbody></table></div></div>"   // 表体(id=flowRows，AJAX 替换)

         // ---- 守护卡 ----
         "<div class='card c-chk' id='guardCard' style='margin-top:12px'>"   // 守护卡片容器(淡蓝渐变)
         "<h3 class='hc-chk'><span class='ht'>守护状态（finally_guard · Windows服务 · 全链路自愈）</span>"   // 卡标题(蓝色渐变字)
         "<span class='fr'><button class='rbtn' onclick='OKXP.guard()'>⟳</button></span></h3>"   // 守护刷新按钮
         "<div class='tw'><table id='guardT'><thead><tr>"   // 守护表(id=guardT)
         "<th>目标</th><th>类型</th><th>运行</th><th>探活</th><th>pid</th><th>重启</th><th>说明</th>"   // 表头七列
         "</tr></thead><tbody id='guardRows'>" + gdRows + "</tbody></table></div></div>"   // 表体(id=guardRows)

         "<div class='foot'>C++ (apihub.exe) 直接渲染本页 · AJAX 增量刷新(3s/10s/15s) · "   // 页脚：渲染方与刷新频率说明
         "<span class='mono'>持仓 " + posCnt + " 个</span></div>"   // 页脚：持仓数量
         "</div>"   // 主体容器结束

         // ---- 悬浮实时监测窗(可拖动/可折叠, 与旧面板一致) ----
         "<div class='card c-grid float-monitor' id='monWin'>"   // 悬浮监测窗(id=monWin，固定底部居中可拖动)
         "<h3 class='hc-grid'><span class='ht'>实时监测（黄金坑全池 · 20X · 每笔1U · +2%止盈 · 不止损 · 只买涨）</span>"   // 窗标题(橙色渐变字，也是拖动手柄)
         "<span class='fr'><button class='rbtn' onclick='OKXP.grid()'>⟳</button>"   // 监测刷新按钮
         "<button class='rbtn gmin' id='gminBtn' title='折叠/展开'>─</button></span></h3>"   // 折叠/展开按钮(id=gminBtn)
         "<div class='gw-body'>"   // 窗体内容区(可滚动)
         "<div class='sline' id='gline'>" + gLine + "</div>"   // 概要行(id=gline)
         "<div class='tw' id='gridTW'><table id='gridT'><thead><tr>"   // 可滚动表容器与监测表(id=gridT)
         "<th>合约</th><th>加仓次数</th><th>现价</th><th>均价</th><th>止盈</th><th>状态</th>"   // 表头六列
         "</tr></thead><tbody id='gridRows'>" + gRows + "</tbody></table></div>"   // 表体(id=gridRows)
         "<div class='sline' style='margin-top:6px'><b>加仓策略</b>：跌时(现价&lt;均价)出现 5m 黄金坑信号即加仓 · "   // 底部策略说明第一段
         "每轮固定 +1U/3 · 不限轮数 · 加仓无仓位上限 | 永不止损 | 止盈不挂单：每10秒检测 价格+2%(ROI40%) 达标立即市价全平</div>"   // 第二段：止盈引擎口径说明
         "</div></div>"   // 窗体与悬浮窗结束

         "<script>window.PANEL_STATE={\"inst\":\"" + jesc(inst) + "\",\"tf\":\"" + jesc(tf) + "\",\"n\":" +   // 注入面板状态：当前合约/周期/根数(供 PANEL_JS 读取)
         std::to_string(nvis) + "};window.KDATA=" + chart.data + ";" + PANEL_JS + "</script>"   // 注入 KDATA 数据数组与内联交互脚本，页面收尾
         "</body></html>";   // HTML 文档结束
    return o;   // 返回整页 HTML
}

// ==================================================================
//  AJAX 片段接口 (C++ 渲染, 浏览器端仅替换 DOM)
//    /frag?name=stats|grid|flow|guard|chart&inst&tf&n
// ==================================================================
std::string ep_frag(const Params& q) {   // AJAX 片段接口：按 name 返回对应卡片增量数据(JSON)
    std::string name = P(q, "name", "");   // 片段名
    std::string inst = get_inst(q);   // 合约
    std::string tf = P(q, "tf", "5m");   // 周期
    int n = atoi(P(q, "n", "200").c_str());   // 根数
    if (n < 60) n = 60;   // 下限
    if (n > 2000) n = 2000;   // 上限

    if (name == "stats") {   // 统计条片段
        std::string h = stats_html(inst);   // 渲染统计条 HTML
        return "{\"ok\":true,\"html\":\"" + jesc(h) + "\",\"pos\":" + stats_extra(inst) + "}";   // 返回 HTML(已 JS 转义)与持仓数
    }
    if (name == "grid") {   // 监测卡片段
        std::string line, rows;   // 概要与表格行
        grid_parts(tf, n, line, rows);   // 渲染
        return "{\"ok\":true,\"line\":\"" + jesc(line) + "\",\"rows\":\"" + jesc(rows) + "\"}";   // 返回两段 HTML
    }
    if (name == "flow") {   // 流水卡片段
        int cnt = 0;   // 条数
        std::string rows = flow_rows(tf, n, cnt);   // 渲染
        return "{\"ok\":true,\"rows\":\"" + jesc(rows) + "\",\"cnt\":" + std::to_string(cnt) + "}";   // 返回行与条数
    }
    if (name == "guard") {   // 守护卡片段
        std::string rows = guard_rows(ep_guard(Params()));   // 取守护状态并渲染
        return "{\"ok\":true,\"rows\":\"" + jesc(rows) + "\"}";   // 返回行
    }
    if (name == "chart") {   // K线片段
        ChartOut co = make_chart(inst, tf, n);   // 重新生成图
        // co.data = {"w":..,"bars":[..]} → 直接内联, 再补 svg
        std::string inner = co.data;   // KDATA 数据
        if (!inner.empty() && inner[0] == '{') inner = inner.substr(1);   // 去掉开头的 {   // 便于与外层 JSON 合并
        return "{\"ok\":true,\"svg\":\"" + jesc(co.svg) + "\"," + inner;   // 返回 SVG + 几何/数组数据合并的 JSON
    }
    return "{\"ok\":false,\"error\":\"unknown frag\"}";   // 未知片段名报错
}

// ==================================================================
//  内联交互脚本 (0 外部文件; 缩放/平移/悬停/AJAX/特效全本地)
// ==================================================================
const char* PANEL_JS = R"JSPANEL(
(function(){   // JS: IIFE 开始(隔离作用域，避免污染全局)
'use strict';   // JS: 启用严格模式
var W=1180,H=640,PX0=62,PX1=1128;   // JS: 与 C++ 端 SK_* 完全一致的几何常量(画布宽高与内容边界)
var ST=window.PANEL_STATE||{inst:'ETH-USDT-SWAP',tf:'5m',n:200};   // JS: 面板状态(当前合约/周期/根数)，C++ 已注入否则用默认
ST.busy={};ST.hasMore=false;   // JS: 各请求的防重入标志 + 是否还有更早K线
var D=null,s=1,tx=0,ty=0,drag=null,svg=null,zg=null,tip=null,ch=null,bw=8,CW=1180,autoArmed=true;   // JS: KDATA数据/缩放倍数/平移量/拖拽状态/SVG引用/滚动层/浮窗/十字线/根宽/内容宽/自动跟随开关
function $(i){return document.getElementById(i);}   // JS: getElementById 简写
function setH(i,h){var e=$(i);if(e)e.innerHTML=h;}   // JS: 设置元素 innerHTML(存在才设)
function say(t){var e=$('status');if(e)e.textContent=t;}   // JS: 更新顶栏同步状态文字
function clock(){return new Date().toLocaleTimeString('zh-CN',{hour12:false});}   // JS: 当前时间 HH:MM:SS
function jget(u){return fetch(u,{cache:'no-store'}).then(function(r){return r.json();});}   // JS: GET 请求并解析 JSON(禁缓存)
function frag(n,x){return '/frag?name='+n+'&inst='+encodeURIComponent(ST.inst)+'&tf='+encodeURIComponent(ST.tf)+'&n='+ST.n+(x||'');}   // JS: 拼 /frag 片段接口 URL(带合约/周期/根数与附加参数)
function showFetch(t){var e=$('fetch');if(e){e.textContent=t;e.style.display='block';}}   // JS: 显示"加载中"角标
function hideFetch(){var e=$('fetch');if(e)e.style.display='none';}   // JS: 隐藏"加载中"角标
/* ---------- K线视图变换 ---------- */
function pt(ev){var p=svg.createSVGPoint();p.x=ev.clientX;p.y=ev.clientY;var m=svg.getScreenCTM();return m?p.matrixTransform(m.inverse()):null;}   // JS: 屏幕坐标→SVG 世界坐标(逆矩阵变换)
function txMin(){return W-CW*s;}   // JS: 平移量下限(内容右缘对齐视口右缘)
function clamp(){if(tx>0)tx=0;if(tx<txMin())tx=txMin();if(ty>0)ty=0;if(ty<H-H*s)ty=H-H*s;}   // JS: 把平移量夹在合法范围内(不许拖出内容)
function atNew(){return tx<=txMin()+.6;}   // JS: 是否已贴到最新端(容差0.6)
function atOld(){return tx>=-.6;}   // JS: 是否已贴到最早端
function apply(){   // JS: 把当前缩放/平移应用到 SVG 滚动层
  clamp();   // JS: 先夹紧平移量
  if(zg)zg.setAttribute('transform','translate('+tx+' '+ty+') scale('+s+')');   // JS: 给 zg 组设置 translate+scale 变换(核心绘制动作)
  var h=$('viewHint');   // JS: 取视图提示角标
  if(h){   // JS: 存在才更新
    if(s<=1.001&&atNew()){h.style.display='none';}   // JS: 原始大小且贴最新端→隐藏提示
    else{   // JS: 否则显示提示
      h.style.display='block';   // JS: 显示
      h.textContent=(s>1.001?s.toFixed(2)+'x · ':'')+   // JS: 缩放倍数(>1时)
        (atOld()&&D&&D.hasMore?'⬅ 已到最早, 继续拖自动加载更早K线':'拖动平移 · 滚轮缩放 · 双击回最新');   // JS: 贴最早端提示加载，否则操作提示
    }   // JS: 提示逻辑结束
  }   // JS: 角标存在判断结束
}   // JS: apply 结束
/* ---------- 特效: 魔法棒光标 + 星星粒子 ---------- */
function mkSpark(kw,x,y){   // JS: 在指定位置生成一颗星星粒子
  var e=document.createElement('span');e.className='spark';   // JS: 创建 span.spark 元素(CSS 动画)
  e.textContent=Math.random()<.5?'\u2728':'\u2b50';   // JS: 随机 ✨ 或 ⭐
  e.style.left=(x-9)+'px';e.style.top=(y-9)+'px';   // JS: 定位到点击处附近
  kw.appendChild(e);setTimeout(function(){if(e.parentNode)e.parentNode.removeChild(e);},1200);   // JS: 挂到K线区，1.2秒后自动移除
}   // JS: mkSpark 结束
function sparks(kw,x,y){for(var i=0;i<3;i++)mkSpark(kw,x+(Math.random()*26-13),y+(Math.random()*26-13));}   // JS: 一次炸出 3 颗随机偏移的星星
setInterval(function(){   // JS: 定时特效：K线区随机飘星星
  if(document.hidden)return;   // JS: 页面不可见时跳过(省资源)
  var kw=$('kwrap');if(!kw)return;var r=kw.getBoundingClientRect();   // JS: 取K线区位置尺寸
  if(r.width<80||r.height<80)return;   // JS: 区域太小不特效
  mkSpark(kw,12+Math.random()*(r.width-30),14+Math.random()*(r.height-60));   // JS: 在区域内随机一点生成星星
},900);   // JS: 每 0.9 秒一次
/* ---------- 绑定K线交互(每次换 svg 后重新绑定) ---------- */
function bind(){   // JS: 给 SVG 绑定滚轮/拖拽/悬停事件(只绑一次)
  if(!svg||svg.__b)return;svg.__b=1;   // JS: 防重复绑定(打标记)
  svg.addEventListener('wheel',function(ev){   // JS: 滚轮缩放
    ev.preventDefault();   // JS: 阻止页面滚动
    var f=ev.deltaY<0?1.18:1/1.18,ns=s*f;   // JS: 上滚放大/下滚缩小，每次 ×1.18
    if(ns<0.6)ns=0.6;if(ns>60)ns=60;   // JS: 缩放范围 0.6~60 倍
    var p=pt(ev);if(!p)return;   // JS: 取鼠标处世界坐标(缩放锚点)
    tx=p.x-(p.x-tx)*ns/s;ty=p.y-(p.y-ty)*ns/s;s=ns;   // JS: 以鼠标为锚点调整平移量(滚向哪缩到哪)并更新倍数
    apply();   // JS: 应用变换
  },{passive:false});   // JS: 需要 preventDefault 故关掉 passive
  svg.addEventListener('mousedown',function(ev){   // JS: 按下开始拖拽
    drag={x:ev.clientX,y:ev.clientY,tx:tx,ty:ty};   // JS: 记录起点与当时平移量
    var kw=$('kwrap');if(kw&&ev.button===0){   // JS: 左键才换光标+特效
      svg.style.cursor='url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'30\' height=\'30\'><text y=\'24\' font-size=\'24\'>🤚</text></svg>") 15 6, pointer';   // JS: 换成✋抓取光标(内联 SVG data URI)
      var r=kw.getBoundingClientRect();sparks(kw,ev.clientX-r.left,ev.clientY-r.top);   // JS: 按下点炸星星
    }   // JS: 左键分支结束
    ev.preventDefault();   // JS: 阻止默认选中
  });   // JS: mousedown 结束
  window.addEventListener('mousemove',function(ev){   // JS: 窗口级 mousemove 处理拖拽
    if(!drag)return;var m=svg.getScreenCTM();if(!m)return;   // JS: 非拖拽或拿不到矩阵则退出
    var k=1/(m.a||1),dx=ev.clientX-drag.x,dy=ev.clientY-drag.y;   // JS: 屏幕像素→SVG单位换算系数与位移
    tx=drag.tx+dx/k;ty=drag.ty+dy/k;          // K线跟随鼠标移动   // JS: 更新平移量(K线跟着鼠标走)
    apply();   // JS: 应用变换
    // 拖到最早一端还继续往左拉 → 自动加载更早的K线(C++ 取库)
    if(tx>=-6&&dx>0&&D&&D.hasMore)loadMore();   // JS: 贴最早端继续右拉→触发加载更早数据
  });   // JS: mousemove 结束
  window.addEventListener('mouseup',function(){if(drag){drag=null;svg.style.cursor='';}});   // JS: 松开结束拖拽并恢复光标
  svg.addEventListener('dblclick',function(){s=1;ty=0;tx=Math.min(0,W-CW);apply();});   // JS: 双击复位：1倍缩放并回到最新
  ch=document.createElementNS('http://www.w3.org/2000/svg','line');   // JS: 创建十字竖线元素
  ch.setAttribute('stroke','#b6c2d1');ch.setAttribute('stroke-width','1');   // JS: 浅灰细线
  ch.setAttribute('stroke-dasharray','4,3');ch.setAttribute('visibility','hidden');   // JS: 虚线样式，默认隐藏
  ch.setAttribute('y1','14');ch.setAttribute('y2','576');   // JS: 竖线贯穿价格+成交量+MACD 区
  svg.appendChild(ch);   // JS: 挂到 SVG 上
  svg.addEventListener('mousemove',function(ev){   // JS: 悬停：十字线 + OHLC 浮窗
    if(!D)return;var p=pt(ev);if(!p)return;   // JS: 无数据或坐标转换失败则退出
    ch.setAttribute('x1',p.x);ch.setAttribute('x2',p.x);ch.setAttribute('visibility','visible');   // JS: 竖线跟随鼠标并显示
    var wx=(p.x-tx)/s,i=Math.floor((wx-PX0)/bw);   // JS: 世界坐标→K线下标
    if(!tip)return;   // JS: 无浮窗元素则到此为止
    var box=svg.getBoundingClientRect();   // JS: SVG 屏幕矩形(浮窗定位用)
    if(i>=0&&i<D.bars.length){   // JS: 下标合法才显示浮窗
      var b=D.bars[i],up=b[4]>=b[1];   // JS: 该根数据与涨跌
      tip.innerHTML='<b>'+(function(sec){var d=new Date(sec*1000);function z(v){return (v<10?'0':'')+v;}   // JS: 浮窗首行：格式化时间(月-日 时:分)
        return (d.getMonth()+1)+'-'+z(d.getDate())+' '+z(d.getHours())+':'+z(d.getMinutes());})(b[0])+'</b>'+   // JS: 时间拼接
        '<br>开 '+b[1]+'<br>高 '+b[2]+'<br>低 '+b[3]+   // JS: 开高低三行
        '<br>收 <span style="color:'+(up?'#e03131':'#0a9c56')+'">'+b[4]+'</span><br>量 '+b[5];   // JS: 收盘(红涨绿跌)与成交量
      tip.style.display='block';   // JS: 显示浮窗
      var lx=ev.clientX-box.left+14,ly=ev.clientY-box.top+10;   // JS: 浮窗默认在鼠标右下
      if(lx>box.width-140)lx=ev.clientX-box.left-150;   // JS: 靠右时翻到左侧防出界
      if(ly>box.height-120)ly=ev.clientY-box.top-120;   // JS: 靠下时翻到上方防出界
      tip.style.left=lx+'px';tip.style.top=ly+'px';   // JS: 设置浮窗位置
    }else{tip.style.display='none';}   // JS: 无对应K线则隐藏浮窗
  });   // JS: 悬停监听结束
  svg.addEventListener('mouseleave',function(){if(ch)ch.setAttribute('visibility','hidden');if(tip)tip.style.display='none';});   // JS: 移出隐藏十字线与浮窗
}   // JS: bind 结束
function mount(reset){   // JS: 挂载/刷新后的视图初始化
  var nsvg=$('ksvg');if(!nsvg)return;   // JS: 取新 SVG 根(id=ksvg)
  if(nsvg!==svg){svg=nsvg;zg=$('zg');tip=$('ktip');bind();}   // JS: SVG 换了新内容→重取滚动层/浮窗并(重新)绑定事件
  D=window.KDATA;if(!D||!D.bars||!D.bars.length)return;   // JS: 读取 KDATA 数据数组，无数据退出
  bw=D.bw||8;   // JS: 每根宽度(与 C++ 一致)
  CW=D.cw||(D.px0+D.bars.length*bw+18);   // JS: 内容总宽
  if(reset){s=1;ty=0;tx=Math.min(0,W-CW);}   // JS: reset 模式→1倍缩放贴最新
  apply();   // JS: 应用视图
}   // JS: mount 结束
function kinfo(){var e=$('kinfo');if(e)e.textContent=ST.inst+' · '+ST.tf+' · '+ST.n+'根';}   // JS: 更新图例里的"当前合约·周期·根数"
/* ---------- K线: AJAX 刷新(reset=true 回到最新) ---------- */
function loadChart(reset){   // JS: 拉取并替换整张K线
  if(ST.busy.chart)return Promise.resolve(false);   // JS: 防重入
  ST.busy.chart=1;showFetch('刷新K线…');   // JS: 上锁并显示加载提示
  return jget(frag('chart','')).then(function(j){   // JS: 请求 chart 片段
    ST.busy.chart=0;hideFetch();   // JS: 解锁并隐藏提示
    if(!j||!j.ok)return false;   // JS: 失败退出
    ST.n=j.n||ST.n;ST.hasMore=!!j.has_more;   // JS: 更新根数与"有更早数据"标志
    window.KDATA={w:j.w,h:j.h,px0:j.px0,px1:j.px1,bw:j.bw,cw:j.cw,bars:j.bars,hasMore:ST.hasMore};   // JS: 重建 KDATA
    setH('kgraph',j.svg||'');   // JS: 替换 SVG DOM
    mount(reset);kinfo();return true;   // JS: 重新挂载视图并更新图例
  })['catch'](function(e){ST.busy.chart=0;hideFetch();say('⚠ K线刷新失败: '+e);return false;});   // JS: 出错解锁并提示
}   // JS: loadChart 结束
/* ---------- K线: 贴到最早一端继续拖 → 自动加载更早数据(C++ 取库) ---------- */
function loadMore(){   // JS: 贴边自动加载更早K线
  if(ST.busy.more||ST.busy.chart||!D||!D.hasMore||ST.n>=1500)return;   // JS: 防重入/无更早/已达上限(1500根)则退出
  ST.busy.more=1;   // JS: 上锁
  var wx=(0-tx)/s,i0=Math.floor((wx-PX0)/bw);   // JS: 计算当前视口左缘对应哪根K线
  if(i0<0)i0=0;if(i0>D.bars.length-1)i0=D.bars.length-1;   // JS: 夹紧下标
  var aT=D.bars[i0][0],wOld=PX0+(i0+.5)*bw;   // JS: 记住锚点K线的时间与世界坐标x(加载后保持视位)
  showFetch('⬅ 正在加载更早K线…');   // JS: 显示加载提示
  var nNew=Math.min(1500,ST.n+300);   // JS: 根数每次 +300(封顶1500)
  jget(frag('chart','&n='+nNew)).then(function(j){   // JS: 请求更多根数的 chart 片段
    ST.busy.more=0;hideFetch();   // JS: 解锁并隐藏提示
    if(!j||!j.ok)return;   // JS: 失败退出
    ST.n=j.n;ST.hasMore=!!j.has_more;   // JS: 更新根数与标志
    window.KDATA={w:j.w,h:j.h,px0:j.px0,px1:j.px1,bw:j.bw,cw:j.cw,bars:j.bars,hasMore:ST.hasMore};   // JS: 重建 KDATA
    setH('kgraph',j.svg||'');   // JS: 替换 SVG
    mount(false);   // JS: 重挂载(不复位视图)
    var idx=-1;for(var i=0;i<D.bars.length;i++){if(D.bars[i][0]===aT){idx=i;break;}}   // JS: 找到锚点K线的新下标
    if(idx>=0){   // JS: 找到了才校正平移
      var dtx=s*(wOld-(PX0+(idx+.5)*bw));   // JS: 计算锚点位置偏移量
      tx+=dtx;if(drag)drag.tx+=dtx;      // 拖动中也要同步基准, 否则视图回弹   // JS: 应用偏移并同步拖拽基准(防回弹)
    }   // JS: 校正结束
    apply();kinfo();   // JS: 应用视图并更新图例
    say('⬅ 已加载更早K线, 共 '+ST.n+' 根');   // JS: 状态提示
  })['catch'](function(){ST.busy.more=0;hideFetch();});   // JS: 出错解锁
}   // JS: loadMore 结束
/* ---------- K线: 定时跟随最新(用户在看历史时不打扰) ---------- */
function tickChart(){   // JS: 定时刷新最新K线(仅当用户贴在最新端)
  if(document.hidden)return;   // JS: 页面不可见跳过
  if(!atNew())return;   // JS: 用户在看历史则不打扰
  var n0=D?D.bars[D.bars.length-1][0]:0;   // JS: 记录当前最新K线时间
  loadChart(false).then(function(ok){   // JS: 静默刷新
    if(!ok)return;   // JS: 失败退出
    tx=Math.min(0,W-CW);apply();   // JS: 保持贴最新
    if(D&&D.bars.length&&D.bars[D.bars.length-1][0]>n0)say('✓ 实时同步 '+clock());   // JS: 有新K线时更新同步时间
  });   // JS: 回调结束
}   // JS: tickChart 结束
/* ---------- 其余卡片 AJAX ---------- */
function bindRows(root){   // JS: 给表格行绑定"点合约名切K线"
  var r=$(root);if(!r)return;   // JS: 容器不存在退出
  var els=r.querySelectorAll('[data-inst]');   // JS: 找出所有带 data-inst 的行
  for(var i=0;i<els.length;i++){(function(el){   // JS: 闭包逐个绑定
    el.style.cursor='pointer';   // JS: 手型光标
    el.onclick=function(){switchInst(el.getAttribute('data-inst'));};   // JS: 点击切换合约
  })(els[i]);}   // JS: 循环结束
}   // JS: bindRows 结束
function refreshStats(){   // JS: 刷新统计条(3秒一次)
  jget(frag('stats')).then(function(j){   // JS: 请求 stats 片段
    if(!j||!j.ok)return;   // JS: 失败静默
    setH('statsBar',j.html);   // JS: 替换统计条 HTML
    var e=$('chartPos');if(e)e.textContent='持仓 '+j.pos+' 个';   // JS: 同步更新K线卡上的持仓数
  })['catch'](function(){});   // JS: 静默容错
}   // JS: refreshStats 结束
function refreshGrid(){   // JS: 刷新监测卡(3秒一次)
  jget(frag('grid')).then(function(j){   // JS: 请求 grid 片段
    if(!j||!j.ok)return;   // JS: 失败静默
    setH('gline',j.line);setH('gridRows',j.rows);bindRows('gridRows');   // JS: 替换概要与表格行并重绑点击
  })['catch'](function(){});   // JS: 静默容错
}   // JS: refreshGrid 结束
function refreshFlow(){   // JS: 刷新流水卡(10秒一次)
  jget(frag('flow')).then(function(j){   // JS: 请求 flow 片段
    if(!j||!j.ok)return;   // JS: 失败静默
    setH('flowRows',j.rows);   // JS: 替换表格行
    var e=$('flowCnt');if(e)e.textContent='共 '+j.cnt+' 条';   // JS: 更新条数
    bindRows('flowRows');   // JS: 重绑行点击
  })['catch'](function(){});   // JS: 静默容错
}   // JS: refreshFlow 结束
function refreshGuard(){   // JS: 刷新守护卡(10秒一次)
  jget(frag('guard')).then(function(j){if(j&&j.ok)setH('guardRows',j.rows);})['catch'](function(){});   // JS: 请求 guard 片段并替换行
}   // JS: refreshGuard 结束
/* ---------- 切换合约 / 周期 ---------- */
function switchInst(i){   // JS: 切换合约
  if(!i||i===ST.inst)return;   // JS: 无效或相同则跳过
  ST.inst=i;   // JS: 更新状态
  try{history.replaceState(null,'','/?inst='+encodeURIComponent(i)+'&tf='+ST.tf+'&n='+ST.n);}catch(e){}   // JS: 无刷新改 URL(可分享/刷新保持)
  var sel=$('symbol');if(sel)sel.value=i;   // JS: 同步下拉框选中项
  var els=document.querySelectorAll('#recentInsts .tf');   // JS: 最近交易 chips
  for(var k=0;k<els.length;k++){if(els[k].getAttribute('data-inst')===i)els[k].classList.add('on');else els[k].classList.remove('on');}   // JS: 高亮当前合约 chip
  say('切换 '+i+' …');   // JS: 状态提示
  ST.n=Math.max(200,ST.n);   // JS: 切合约至少看 200 根
  loadChart(true).then(function(){refreshStats();refreshGrid();refreshFlow();});   // JS: 重载K线并刷新其余卡片
}   // JS: switchInst 结束
function switchTf(t){   // JS: 切换周期
  if(!t||t===ST.tf)return;   // JS: 无效或相同则跳过
  ST.tf=t;   // JS: 更新状态
  var els=document.querySelectorAll('#tfs .tf');   // JS: 周期按钮组
  for(var k=0;k<els.length;k++){if(els[k].getAttribute('data-tf')===t)els[k].classList.add('on');else els[k].classList.remove('on');}   // JS: 高亮当前周期
  try{history.replaceState(null,'','/?inst='+encodeURIComponent(ST.inst)+'&tf='+t+'&n='+ST.n);}catch(e){}   // JS: 改 URL
  say('切换周期 '+t+' …');   // JS: 状态提示
  loadChart(true).then(function(){refreshGrid();refreshFlow();});   // JS: 重载K线并刷新关联卡片
}   // JS: switchTf 结束
/* ---------- 对外按钮 ---------- */
window.OKXP={   // JS: 暴露给 onclick 的全局按钮 API
  chart:function(){loadChart(true);},   // JS: ⟳K线：重载并回最新
  latest:function(){loadChart(true).then(function(){s=1;ty=0;tx=Math.min(0,W-CW);apply();});},   // JS: ⏭最新：重载后强制1倍贴最新
  stats:refreshStats,grid:refreshGrid,flow:refreshFlow,guard:refreshGuard,   // JS: 各卡片手动刷新
  inst:switchInst,tf:switchTf,   // JS: 切合约/周期
  all:function(){loadChart(true);refreshStats();refreshGrid();refreshFlow();refreshGuard();   // JS: ⟳全部刷新：K线+四张卡
    var e=$('nsel');if(e)ST.n=parseInt(e.value,10)||ST.n;say('⟳ 全部刷新 '+clock());}   // JS: 顺便同步根数下拉并提示时间
};   // JS: OKXP 结束
/* ---------- 事件绑定 ---------- */
(function(){   // JS: 静态控件绑定 IIFE
  var sel=$('symbol');   // JS: 合约下拉
  if(sel)sel.onchange=function(){switchInst(sel.value);};   // JS: 下拉变更→切合约
  var els=document.querySelectorAll('#tfs .tf');   // JS: 周期按钮
  for(var i=0;i<els.length;i++){(function(el){   // JS: 闭包绑定
    el.onclick=function(){switchTf(el.getAttribute('data-tf'));};   // JS: 点击切周期
  })(els[i]);}   // JS: 循环结束
  var els2=document.querySelectorAll('#recentInsts .tf');   // JS: 最近交易 chips
  for(var j=0;j<els2.length;j++){(function(el){   // JS: 闭包绑定
    el.onclick=function(){switchInst(el.getAttribute('data-inst'));};   // JS: 点击切合约
  })(els2[j]);}   // JS: 循环结束
  var ns=$('nsel');   // JS: 根数下拉
  if(ns)ns.onchange=function(){ST.n=parseInt(ns.value,10)||ST.n;loadChart(true);};   // JS: 变更→更新根数并重载
  var mb=$('gminBtn');   // JS: 悬浮窗折叠按钮
  if(mb)mb.onclick=function(e){   // JS: 点击折叠/展开
    e.stopPropagation();   // JS: 防止触发标题栏拖动
    var mw=$('monWin');if(!mw)return;   // JS: 取悬浮窗
    mw.classList.toggle('min');   // JS: 切换折叠类
    mb.textContent=mw.classList.contains('min')?'□':'─';   // JS: 换按钮图标
  };   // JS: 折叠逻辑结束
})();   // JS: 绑定 IIFE 结束
/* ---------- 悬浮监测窗: 标题栏拖动 ---------- */
(function(){   // JS: 悬浮窗拖动 IIFE
  var mw=$('monWin');if(!mw)return;   // JS: 窗不存在退出
  var head=mw.querySelector('h3');   // JS: 标题栏作拖动手柄
  var dr=false,sx=0,sy=0,ox=0,oy=0;   // JS: 拖拽状态与起点
  function toAbs(){   // JS: 首次拖动前把 CSS 定位转为绝对坐标
    var r=mw.getBoundingClientRect();   // JS: 当前屏幕位置
    mw.style.left=Math.max(8,(window.innerWidth-r.width)/2)+'px';   // JS: 保持水平居中
    mw.style.top=Math.max(8,window.innerHeight-r.height-12)+'px';   // JS: 保持底部位置
    mw.style.bottom='auto';mw.style.right='auto';mw.style.transform='none';   // JS: 去掉 CSS 底部定位/变换，改由 left/top 控制
  }   // JS: toAbs 结束
  head.addEventListener('mousedown',function(e){   // JS: 按下开始拖窗
    if(e.target&&e.target.id==='gminBtn')return;   // JS: 点折叠按钮不拖窗
    if(mw.style.left==='')toAbs();   // JS: 首次转绝对定位
    dr=true;sx=e.clientX;sy=e.clientY;ox=mw.offsetLeft;oy=mw.offsetTop;   // JS: 记录起点
    e.preventDefault();   // JS: 防止文字选中
  });   // JS: mousedown 结束
  window.addEventListener('mousemove',function(e){   // JS: 拖动中
    if(!dr)return;   // JS: 非拖拽退出
    mw.style.left=Math.min(Math.max(8,ox+e.clientX-sx),window.innerWidth-120)+'px';   // JS: 更新位置(限制在视口内)
    mw.style.top=Math.min(Math.max(8,oy+e.clientY-sy),window.innerHeight-40)+'px';   // JS: 同上
  });   // JS: mousemove 结束
  window.addEventListener('mouseup',function(){dr=false;});   // JS: 松开结束拖窗
})();   // JS: 拖动 IIFE 结束
/* ---------- 启动 ---------- */
mount(true);bindRows('gridRows');bindRows('flowRows');   // JS: 初始挂载K线+给两表格绑行点击
setInterval(refreshStats,3000);   // JS: 统计条 3 秒刷新
setInterval(refreshGrid,3000);   // JS: 监测卡 3 秒刷新
setInterval(refreshFlow,10000);   // JS: 流水卡 10 秒刷新
setInterval(refreshGuard,10000);   // JS: 守护卡 10 秒刷新
setInterval(tickChart,15000);   // JS: K线跟随最新 15 秒
setInterval(function(){if(!document.hidden)say('✓ 实时同步 '+clock());},5000);   // JS: 每 5 秒更新同步时间(页面可见时)
})();   // JS: 主 IIFE 结束
)JSPANEL";

// ==================================================================
//  面板 CSS — 恢复旧版视觉(隶书/金渐变标题/彩色卡片/悬浮监测窗) + 特效
// ==================================================================
const char* PANEL_CSS = R"CSSCSS(
:root{--red:#e03131;--green:#0ca678;--blue:#2f6fed;--bg:#f4f6fa;--card:#fff;--line:#e0e5ef;--txt:#1f2329}   /* CSS: 全局色变量：红涨/绿跌/蓝/背景/卡片/描边/正文 */
*{box-sizing:border-box}   /* CSS: 全局 border-box 盒模型 */
body{margin:0;background:var(--bg);font:15px/1.55 "LiSu","隶书","KaiTi","STKaiti","Microsoft YaHei",serif;color:var(--txt)}   /* CSS: 页面底色+隶书字体栈 */
.wrap{padding:12px 14px 130px;max-width:1560px;margin:0 auto}   /* CSS: 主容器：限宽居中，底部留悬浮窗空间 */
.top{background:linear-gradient(180deg,#fff,#f0f1f5);border-bottom:1px solid var(--line);padding:9px 16px;   /* CSS: 顶栏：白→灰渐变+底描边 */
 display:flex;gap:12px;align-items:center;flex-wrap:wrap;box-shadow:0 1px 4px rgba(0,0,0,.05)}   /* CSS: 弹性布局+轻微投影 */
.top b{font-size:20px;font-weight:bold;letter-spacing:1px;   /* CSS: 站点标题字号字距 */
 background:linear-gradient(180deg,#ffe9a8,#f6c445 45%,#c98a1b 55%,#f2b52f);-webkit-background-clip:text;background-clip:text;   /* CSS: 金色渐变文字(裁剪到文字) */
 color:transparent;filter:drop-shadow(0 1px 1px rgba(90,60,0,.55)) drop-shadow(0 -1px 0 rgba(255,255,255,.35))}   /* CSS: 文字镂空+上下投影立体感 */
select,.inp{border:1px solid #cfd6e4;border-radius:6px;padding:5px 10px;background:#fff;font-size:13px;outline:none;font-family:inherit;color:var(--txt)}   /* CSS: 下拉框/输入框样式 */
select:hover,.inp:hover{border-color:var(--blue)}   /* CSS: 悬停描边变蓝 */
#status{font-size:12px;color:#8a93a6}   /* CSS: 同步状态小字 */
.nav{gap:2px}   /* CSS: 导航栏间距 */
.nav a{font-size:14px;color:#33465e;text-decoration:none;padding:4px 12px;border:1px solid transparent;border-radius:6px}   /* CSS: 导航链接(胶囊边框) */
.nav a:hover{background:#e8f0ff;border-color:#cfd6e4;color:#1c4fa0}   /* CSS: 导航悬停高亮 */
.rbtn{background:#fff;border:1px solid #cfd6e4;border-radius:6px;font-size:12px;padding:2px 9px;cursor:pointer;   /* CSS: 小刷新按钮基础样式 */
 color:#33465e;font-family:inherit;line-height:1.7}   /* CSS: 按钮文字 */
.rbtn:hover{background:#e8f0ff;border-color:var(--blue)}   /* CSS: 按钮悬停变蓝 */
#aiMarq{flex:1 1 260px;min-width:220px;overflow:hidden;white-space:nowrap;border:1px solid #b8e6d9;border-radius:8px;   /* CSS: 策略跑马灯外壳(不换行+绿描边) */
 background:linear-gradient(180deg,#eafcf5,#d3f4e8);padding:4px 0;color:#0b5c40;font-size:14px}   /* CSS: 淡绿渐变底+深绿字 */
#aiMarq .mq{display:inline-block;padding-left:60%;animation:mqmove 70s linear infinite}   /* CSS: 滚动文字：70秒匀速无限循环 */
#aiMarq:hover .mq{animation-play-state:paused;cursor:pointer}   /* CSS: 悬停暂停跑马灯 */
@keyframes mqmove{0%{transform:translateX(0)}100%{transform:translateX(-100%)}}   /* CSS: 跑马灯关键帧：从起点移出左侧 */
.stats{display:flex;gap:16px;flex-wrap:wrap;align-items:center;background:#fff;border:1px solid var(--line);   /* CSS: 统计条：弹性多行 */
 border-radius:10px;padding:8px 16px;margin:10px 14px 0;font-size:13.5px;box-shadow:0 1px 4px rgba(0,0,0,.04)}   /* CSS: 圆角白卡+微投影 */
.stats b{font-weight:bold}   /* CSS: 统计数字加粗 */
.card{border:1px solid rgba(90,110,160,.18);border-radius:10px;padding:12px;box-shadow:0 2px 10px rgba(60,80,140,.08)}   /* CSS: 通用卡片：圆角+投影 */
.card h3{font-size:19px;font-weight:bold;margin:0 0 8px;padding-bottom:6px;border-bottom:1px solid rgba(120,140,180,.25);   /* CSS: 卡标题：底部分隔线 */
 display:flex;align-items:center;justify-content:center;gap:10px;position:relative;color:#2c3444}   /* CSS: 居中布局(右侧按钮绝对定位) */
.card h3 .ht{background-clip:text;-webkit-background-clip:text;color:transparent;transition:filter .2s;   /* CSS: 标题文字渐变镂空(具体颜色由各卡 hc-* 指定) */
 filter:drop-shadow(0 1px 1px rgba(0,0,0,.28)) drop-shadow(0 -1px 0 rgba(255,255,255,.4))}   /* CSS: 立体投影 */
.card h3:hover .ht{filter:drop-shadow(0 1px 2px rgba(0,0,0,.45)) brightness(1.15)}   /* CSS: 悬停标题提亮 */
.card h3 .fr{position:absolute;right:0;top:0;font-size:12px;display:flex;gap:6px;align-items:center;color:#8a93a6}   /* CSS: 标题右侧工具区(绝对定位) */
.hc-chart .ht{background-image:linear-gradient(180deg,#b48cff,#7b3ff2 45%,#4c1fa8 55%,#8d55f5)}   /* CSS: K线卡标题紫色渐变 */
.hc-flow .ht{background-image:linear-gradient(180deg,#8ff0c4,#12b886 45%,#087f5b 55%,#2ecf9a)}   /* CSS: 流水卡标题绿色渐变 */
.hc-grid .ht{background-image:linear-gradient(180deg,#ffd08a,#f76707 45%,#c44e00 55%,#ff922b)}   /* CSS: 监测窗标题橙色渐变 */
.hc-chk .ht{background-image:linear-gradient(180deg,#9ec2ff,#1d6fe0 45%,#0d3f8f 55%,#4d97f2)}   /* CSS: 守护卡标题蓝色渐变 */
.c-chart{background:linear-gradient(160deg,#eef4ff 0%,#f6f0ff 55%,#fdf2ff 100%)}   /* CSS: K线卡淡紫渐变底 */
.c-flow{background:linear-gradient(160deg,#edfbf4 0%,#f0f9ff 100%)}   /* CSS: 流水卡淡绿渐变底 */
.c-grid{background:linear-gradient(160deg,#fff7e8 0%,#ffefe9 100%)}   /* CSS: 监测窗淡橙渐变底 */
.c-chk{background:linear-gradient(160deg,#f2f0ff 0%,#eef8ff 100%)}   /* CSS: 守护卡淡蓝渐变底 */
#tfs{display:flex;gap:6px;margin:0 0 8px;flex-wrap:wrap}   /* CSS: 周期按钮行 */
.tf{cursor:pointer;border:1px solid #c9d4e4;border-radius:6px;padding:2px 12px;font-size:13px;color:#33465e;   /* CSS: 周期/合约 chip 基础样式 */
 background:#fff;user-select:none}   /* CSS: 白底+禁止选中 */
.tf.on{background:#2b6cb0;color:#fff;border-color:#2b6cb0;font-weight:700}   /* CSS: 选中态：蓝底白字加粗 */
#recentInsts{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:0 2px 8px}   /* CSS: 最近交易 chips 行 */
.kwrap{position:relative}   /* CSS: K线包裹层(浮窗/角标以它定位) */
#kgraph{width:100%;background:#fff;border:1px solid #e3e8f0;border-radius:8px;overflow:hidden;   /* CSS: SVG图容器：圆角+裁剪 */
 cursor:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='60' height='60'><text y='48' font-size='48'>🪄</text></svg>") 8 48, crosshair}   /* CSS: 魔法棒🪄自定义光标(内联SVG data URI)，兜底十字线 */
#kgraph svg{display:block}   /* CSS: SVG 撑满容器 */
.spark{position:absolute;pointer-events:none;font-size:36px;z-index:4;animation:spk 1.2s ease-out forwards}   /* CSS: 星星粒子：绝对定位+缩放淡出动画 */
@keyframes spk{0%{transform:scale(.4);opacity:1}60%{transform:scale(1.35);opacity:.95}100%{transform:scale(.5);opacity:0}}   /* CSS: 粒子关键帧：放大再缩小消失 */
#ktip{display:none;position:absolute;z-index:9;background:rgba(255,255,255,.97);border:1px solid #c9d4e4;   /* CSS: 悬停 OHLC 浮窗：近白不透明底 */
 border-radius:8px;padding:7px 10px;font:12px/1.7 Consolas,monospace;color:#1d2733;   /* CSS: 等宽小字 */
 box-shadow:0 4px 14px rgba(20,40,80,.18);pointer-events:none;white-space:nowrap}   /* CSS: 投影+不挡鼠标+不换行 */
#fetch{position:absolute;right:14px;top:10px;z-index:8;font-size:12px;color:#2b6cb0;background:rgba(255,255,255,.94);   /* CSS: 加载中角标(右上) */
 border:1px solid #c9d4e4;border-radius:8px;padding:3px 10px;box-shadow:0 2px 8px rgba(20,40,80,.12);display:none}   /* CSS: 默认隐藏 */
#viewHint{display:none;position:absolute;left:14px;top:10px;z-index:8;font-size:12px;color:#8a6d3b;   /* CSS: 视图提示角标(左上)：缩放倍数/加载提示 */
 background:rgba(255,250,235,.95);border:1px solid #e8d6a8;border-radius:8px;padding:3px 10px;pointer-events:none}   /* CSS: 淡黄底不挡鼠标 */
.legend{font-size:12px;color:#8a93a6;margin-top:7px;line-height:1.9}   /* CSS: K线图例小字 */
.tw{border:1px solid var(--line);border-radius:6px;overflow:auto;max-height:min(340px,36vh)}   /* CSS: 可滚动表容器(限高) */
#gridTW{max-height:min(420px,44vh)}   /* CSS: 监测表容器更高些 */
table{width:100%;border-collapse:collapse;font-size:14.5px;font-family:"LiSu","隶书","KaiTi","Microsoft YaHei",serif}   /* CSS: 表格通栏+隶书 */
thead th{position:sticky;top:0;z-index:2;padding:7px 6px;text-align:center;font-size:16px;font-weight:bold;   /* CSS: 表头吸顶滚动不消失 */
 white-space:nowrap;border-bottom:1px solid var(--line)}   /* CSS: 表头不换行+底线 */
#flowT thead th{background:linear-gradient(180deg,#d8f5e6,#b2e6cd);border-bottom:2px solid #0ca678;color:#0b5c40}   /* CSS: 流水表头绿色主题 */
#gridT thead th{background:linear-gradient(180deg,#ffe8c2,#ffd39e);border-bottom:2px solid #e8590c;color:#8a3a00}   /* CSS: 监测表头橙色主题 */
#guardT thead th{background:linear-gradient(180deg,#dbe8ff,#bcd3fa);border-bottom:2px solid #1d6fe0;color:#0d3f8f}   /* CSS: 守护表头蓝色主题 */
tbody td{padding:8px 6px;border-bottom:1px solid #eef1f6;white-space:nowrap;text-align:center}   /* CSS: 数据格：居中不换行 */
#flowT tbody tr:nth-child(even){background:#eefaf3}   /* CSS: 流水表斑马纹(偶数行淡绿) */
#gridT tbody tr:nth-child(even){background:#fff6ea}   /* CSS: 监测表斑马纹(偶数行淡橙) */
tbody tr:hover{background:#dceaff}   /* CSS: 行悬停淡蓝高亮 */
tr.clickable{cursor:pointer}   /* CSS: 可点击行手型光标 */
.up{color:var(--red);font-weight:bold}.dn{color:var(--green);font-weight:bold}   /* CSS: 盈利=红 / 亏损=绿(A股配色习惯) */
.muted{color:#98a0ab;font-size:12.5px}   /* CSS: 弱化灰字 */
.mono{font-family:Consolas,monospace;font-size:12.5px}   /* CSS: 等宽数字 */
.sline{font-size:13px;background:linear-gradient(180deg,#f7f9fc,#eef2f9);border:1px solid var(--line);   /* CSS: 概要行：淡灰渐变条 */
 border-radius:8px;padding:7px 12px;margin-bottom:8px}   /* CSS: 圆角内边距 */
.oktag,.badtag{font-size:11.5px;font-weight:700;padding:1px 8px;border-radius:12px}   /* CSS: 状态徽标基础(胶囊形) */
.oktag{background:#e6f7ee;color:#0a7a44}.badtag{background:#fdecea;color:#c0392b}   /* CSS: 正常=绿底 / 异常=红底 */
.c-grid.float-monitor{position:fixed;left:50%;bottom:12px;transform:translateX(-50%);width:min(720px,66vw);z-index:60;   /* CSS: 悬浮监测窗：固定底部居中浮在最上层 */
 max-height:76vh;display:flex;flex-direction:column;box-shadow:0 8px 28px rgba(40,60,100,.30)}   /* CSS: 限高+纵向布局+大投影 */
.c-grid.float-monitor h3{font-size:14px;justify-content:space-between;text-align:left;padding:4px 8px;cursor:move;user-select:none}   /* CSS: 窗标题=拖动手柄(move光标) */
.c-grid.float-monitor .gw-body{overflow:auto;min-height:0;flex:1;display:flex;flex-direction:column}   /* CSS: 窗体内容可滚动 */
.c-grid.float-monitor .gw-body .tw{flex:1;min-height:200px;max-height:none}   /* CSS: 表格占满剩余高度 */
.c-grid.float-monitor.min .gw-body{display:none}   /* CSS: 折叠态隐藏内容只留标题 */
.c-grid.float-monitor .sline{font-size:12px;line-height:1.6}   /* CSS: 悬浮窗内概要行紧凑字号 */
.c-grid.float-monitor #gridT thead th{font-size:14px;padding:6px}   /* CSS: 悬浮窗表头紧凑 */
.c-grid.float-monitor #gridT tbody td{font-size:13px;padding:6px;white-space:normal}   /* CSS: 悬浮窗数据格允许换行 */
.gmin{flex:0 0 auto;width:26px;height:22px;line-height:20px;padding:0;font-size:13px;border-radius:6px;cursor:pointer}   /* CSS: 折叠按钮小方块 */
.foot{text-align:center;color:#98a0ab;font-size:12.5px;margin-top:16px}   /* CSS: 页脚灰字居中 */
)CSSCSS";
