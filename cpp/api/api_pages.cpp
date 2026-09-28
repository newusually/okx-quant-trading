// ==================================================================
// api_pages.cpp — 面板渲染组件 (C++ 渲染 HTML + SVG, 浏览器端仅绘制)
//   2026-09-28 交互版 v1 : 滚轮缩放 / 拖拽平移 / 悬停OHLC
//   2026-09-28 交互版 v2 : 恢复旧版视觉(隶书/金渐变标题/彩色卡片/悬浮监测窗)
//                         + 魔法棒光标 + ✨⭐星星粒子 + AJAX 增量刷新(无整页跳转)
//                         + 每卡刷新按钮 + 持仓数量显示 + K线贴边自动加载更早数据
//   计算/排版/SQL 全部在 C++; 浏览器端只做 DOM 替换与绘制, 0 PHP, 0 外部文件
// ==================================================================
#include "../common/hub.h"
#include <cstdio>
#include <cstring>
#include <ctime>
#include <cmath>
#include <cctype>
#include <map>
#include <algorithm>

std::string hesc(std::string s) {
    std::string o; o.reserve(s.size() + 16);
    for (char c : s) {
        if (c == '&') o += "&amp;";
        else if (c == '<') o += "&lt;";
        else if (c == '>') o += "&gt;";
        else if (c == '"') o += "&quot;";
        else o += c;
    }
    return o;
}
std::string hnum(double v, int prec) {
    char b[48]; snprintf(b, 48, "%.*f", prec, v); return b;
}
std::string hx(const std::string& s) {   // URL query encode (宽松)
    std::string o;
    for (char c : s)
        if (isalnum((unsigned char)c) || strchr("-_.", c)) o += c;
        else { char b[8]; snprintf(b, 8, "%%%02X", (unsigned char)c); o += b; }
    return o;
}
std::string htime(long long tms) {        // 毫秒 → HH:MM
    time_t t = (time_t)(tms / 1000);
    struct tm lt; localtime_s(&lt, &t);
    char b[16]; snprintf(b, 16, "%02d:%02d", lt.tm_hour, lt.tm_min); return b;
}
std::string hdate(long long tms) {        // 毫秒 → MM-DD HH:MM
    time_t t = (time_t)(tms / 1000);
    struct tm lt; localtime_s(&lt, &t);
    char b[24]; snprintf(b, 24, "%02d-%02d %02d:%02d", lt.tm_mon + 1, lt.tm_mday, lt.tm_hour, lt.tm_min); return b;
}
static std::string hdate2(long long tms) { // 秒 → YYYY-MM-DD HH:MM:SS (本地)
    time_t t = (time_t)tms;
    struct tm lt; localtime_s(&lt, &t);
    char b[32]; snprintf(b, 32, "%04d-%02d-%02d %02d:%02d:%02d", lt.tm_year + 1900, lt.tm_mon + 1,
                         lt.tm_mday, lt.tm_hour, lt.tm_min, lt.tm_sec);
    return b;
}
// 从 JSON 文本里抠字段(数字或字符串), 供复用 ep_livestats / ep_guard 的返回值
static std::string jgrab(const std::string& j, const char* k) {
    std::string kk = std::string("\"") + k + "\":";
    size_t i = j.find(kk); if (i == std::string::npos) return "";
    i += kk.size();
    if (i < j.size() && j[i] == '"') { size_t e = j.find('"', i + 1); return j.substr(i + 1, e - i - 1); }
    size_t e = j.find_first_of(",}", i);
    return j.substr(i, e - i);
}
// "2026-09-28 14:57:10" → "09-28 14:57" (去掉年份, 与旧面板一致)
static std::string noyear_str(const std::string& s) {
    return s.size() >= 16 ? s.substr(5, 11) : s;
}

// K线几何常量(svg_kline 与内联交互脚本共用, 两处必须一致)
static const int SK_W = 1180, SK_H = 640, SK_PX0 = 62, SK_PX1 = 1128;
static const double SK_BW = 8.0;      // 每根K线固定像素宽 → 内容比视口宽 = 可拖动滑动视窗

struct Pm { long long t; int kind; double px; double profit; };   // kind 0买 1加 2平

// ==================================================================
//  SVG K线 (C++ 画图: 蜡烛/成交量/MACD/0.618/金▲尖转折/买卖标注)
//    三层结构: zbg=固定背景(横网格/分隔/0.618) · zg=滚动内容(随鼠标平移) · zfg=固定前景(左右刻度条)
// ==================================================================
static std::string svg_kline(const std::vector<Bar>& all, const std::vector<double>& dif,
                             const std::vector<double>& dea, const std::vector<double>& hist,
                             const std::vector<int>& pidx, const std::vector<int>& ptype,
                             const std::vector<double>& pprice, const std::vector<double>& pke,
                             double th, const std::vector<Pm>& marks) {
    const int W = SK_W, H = SK_H;
    const int PX0 = SK_PX0;                      // 内容左起点(世界坐标)
    const int PY0 = 14, PY1 = 372;               // 价格区 y
    const int VY0 = 386, VY1 = 440;              // 成交量区
    const int MY0 = 452, MY1 = 576;              // MACD 区
    const int TY = 596;                          // 时间轴文字 y
    int n = (int)all.size();
    if (n < 5) return "<p class='muted'>K线不足</p>";
    double ph = -1e18, pl = 1e18, vmax = 0;
    for (int i = 0; i < n; i++) {
        if (all[i].h > ph) ph = all[i].h;
        if (all[i].l < pl) pl = all[i].l;
        if (all[i].v > vmax) vmax = all[i].v;
    }
    double pad = (ph - pl) * 0.06; if (pad <= 0) pad = ph * 0.001 + 1e-9;
    double phh = ph + pad, pll = pl - pad;
    double span = phh - pll; if (span <= 0) span = 1;
    double fibH = ph, fibL = pl, p618 = fibL + (fibH - fibL) * 0.618;
    double mmax = 1e-9;
    for (int i = 0; i < n; i++) { double a = fabs(hist[i]); if (a > mmax) mmax = a; }
    double bw = SK_BW;
    double CW = PX0 + n * bw + 18;               // 内容总宽(世界坐标, 可 > 视口宽)
    int cw = (int)(bw * 0.62); if (cw < 1) cw = 1;
    auto yP = [&](double p) { return PY1 - (p - pll) / span * (PY1 - PY0); };
    auto xC = [&](int i) { return PX0 + (i + 0.5) * bw; };

    std::string s;
    char b[256];
    snprintf(b, sizeof b, "<svg id='ksvg' viewBox='0 0 %d %d' width='100%%' style='display:block;background:#fff;touch-action:none'>", W, H);
    s += b;

    // ---------- ① 固定背景层: 横网格 + 区带分隔 + 0.618 ----------
    s += "<g id='zbg'>";
    for (int g = 0; g <= 6; g++) {
        double p = pll + span * g / 6.0; int y = (int)yP(p);
        snprintf(b, sizeof b, "<line x1='0' y1='%d' x2='%d' y2='%d' stroke='#eef1f5' stroke-width='1'/>", y, W, y);
        s += b;
    }
    snprintf(b, sizeof b, "<line x1='0' y1='%d' x2='%d' y2='%d' stroke='#e3e8f0' stroke-width='1'/>", VY0 - 8, W, VY0 - 8);
    s += b;
    snprintf(b, sizeof b, "<line x1='0' y1='%d' x2='%d' y2='%d' stroke='#e3e8f0' stroke-width='1'/>", MY0 - 8, W, MY0 - 8);
    s += b;
    {int y = (int)yP(p618);
     snprintf(b, sizeof b, "<line x1='0' y1='%d' x2='%d' y2='%d' stroke='#e6a817' stroke-width='1.6' stroke-dasharray='8,5'/>", y, W, y);
     s += b;}
    s += "</g>";

    // ---------- ② 滚动层: 蜡烛/成交量/MACD/时间轴/金▲/买卖标注 ----------
    s += "<g id='zg'>";
    for (int i = 0; i < n; i++) {
        const Bar& k = all[i];
        bool up = k.c >= k.o;
        const char* col = up ? "#e03131" : "#0a9c56";
        int x = (int)xC(i);
        int yh = (int)yP(k.h), yl = (int)yP(k.l);
        int yo = (int)yP(k.o), yc = (int)yP(k.c);
        int bt = yo < yc ? yo : yc, bh = abs(yc - yo); if (bh < 1) bh = 1;
        snprintf(b, sizeof b, "<line x1='%d' y1='%d' x2='%d' y2='%d' stroke='%s' stroke-width='1' vector-effect='non-scaling-stroke'/>"
                 "<rect x='%d' y='%d' width='%d' height='%d' fill='%s'/>",
                 x, yh, x, yl, col, x - cw / 2, bt, cw, bh, col);
        s += b;
        int vh = vmax > 0 ? (int)((k.v / vmax) * (VY1 - VY0)) : 0;
        snprintf(b, sizeof b, "<rect x='%d' y='%d' width='%d' height='%d' fill='%s' opacity='.55'/>",
                 x - cw / 2, VY1 - vh, cw, vh, col);
        s += b;
    }
    // MACD(横跨整个内容宽)
    {int myc = (MY0 + MY1) / 2;
     snprintf(b, sizeof b, "<line x1='0' y1='%d' x2='%.0f' y2='%d' stroke='#dde3ea' stroke-width='1'/>", myc, CW, myc); s += b;
     for (int i = 0; i < n; i++) {
         double h = hist[i] / mmax * ((MY1 - MY0) / 2.0 - 2);
         if (fabs(h) < 0.4) continue;
         int x = (int)xC(i);
         snprintf(b, sizeof b, "<rect x='%d' y='%.0f' width='%d' height='%.0f' fill='%s' opacity='.8'/>",
                  x - cw / 2, h >= 0 ? myc - h : myc, cw < 2 ? 2 : cw, fabs(h), h >= 0 ? "#e03131" : "#0a9c56");
         s += b;
     }
     auto line = [&](const std::vector<double>& v, const char* col) {
         std::string d; d.reserve(v.size() * 14 + 16); bool first = true;
         char bb[48];
         for (int i = 0; i < n; i++) {
             double yv = myc - v[i] / mmax * ((MY1 - MY0) / 2.0 - 2);
             if (yv < MY0 - 4) yv = MY0 - 4;
             if (yv > MY1 + 4) yv = MY1 + 4;
             snprintf(bb, 48, "%s%.1f,%.1f", first ? "M" : "L", xC(i), yv);
             d += bb; first = false;
         }
         return std::string("<path d='") + d + "' fill='none' stroke='" + col + "' stroke-width='1.3' vector-effect='non-scaling-stroke'/>";
     };
     s += line(dif, "#e8890c");
     s += line(dea, "#3b7dd8");}
    // 时间轴(随内容滚动)
    for (int g = 0; g < 6; g++) {
        int i = (int)((double)n * g / 6.0); if (i >= n) i = n - 1;
        snprintf(b, sizeof b, "<text x='%.0f' y='%d' font-size='10.5' fill='#9aa4b2' text-anchor='middle' font-family='Consolas,monospace'>%s</text>",
                 xC(i), TY, hdate(all[i].tms).c_str());
        s += b;
    }
    // 尖转折金▲ + KE/g/F
    for (int k = 0; k < (int)pidx.size(); k++) {
        if (ptype[k] != 0 || pke[k] <= 0 || pke[k] < th) continue;
        int i = pidx[k]; if (i < 0 || i >= n) continue;
        int x = (int)xC(i), y = (int)yP(pprice[k]);
        snprintf(b, sizeof b, "<path d='M%d,%d L%d,%d L%d,%d Z' fill='#e6a817'/>"
                 "<text x='%d' y='%d' font-size='11' font-weight='bold' fill='#b07708' text-anchor='middle' font-family='Consolas,monospace'>KE=%.2g</text>"
                 "<text x='%d' y='%d' font-size='10.5' fill='#b07708' text-anchor='middle' font-family='Consolas,monospace'>g=%.2g F=%.2g</text>",
                 x, y + 10, x - 8, y + 24, x + 8, y + 24,
                 x, y + 40, pke[k], x, y + 54, pke[k] * 2 / ((pprice[k] > 0) ? pprice[k] : 1), pke[k]);
        s += b;
    }
    // 买卖标注: buy🚀 / add▲ / close🍃(盈利)
    for (const Pm& m : marks) {
        if (m.px <= 0) continue;
        int x = -1;
        for (int i = 0; i < n; i++) if (all[i].tms == m.t) { x = (int)xC(i); break; }
        if (x < 0) continue;
        int y = (int)yP(m.px);
        const char* col = m.kind == 0 ? "#c0392b" : m.kind == 1 ? "#e6a817" : "#0a7a44";
        if (m.kind == 2) {
            snprintf(b, sizeof b, "<circle cx='%d' cy='%d' r='5' fill='%s'/>"
                     "<text x='%d' y='%d' font-size='10.5' font-weight='bold' fill='%s' text-anchor='middle' font-family='Consolas,monospace'>🍃%s%.2f</text>",
                     x, y, col, x, y - 12, col, m.profit >= 0 ? "+" : "", m.profit);
        } else if (m.kind == 1) {
            snprintf(b, sizeof b, "<path d='M%d,%d L%d,%d L%d,%d Z' fill='%s'/>"
                     "<text x='%d' y='%d' font-size='10' fill='%s' text-anchor='middle'>▲加</text>",
                     x, y - 6, x - 6, y - 18, x + 6, y - 18, col, x, y - 22, col);
        } else {
            snprintf(b, sizeof b, "<circle cx='%d' cy='%d' r='5' fill='%s'/>"
                     "<text x='%d' y='%d' font-size='10' fill='%s' text-anchor='middle'>🚀</text>",
                     x, y, col, x, y - 10, col);
        }
        s += b;
    }
    s += "</g>";

    // ---------- ③ 固定前景层: 左右刻度条(盖住滚动内容) + 价格刻度 + 0.618 标签 ----------
    s += "<g id='zfg'>";
    snprintf(b, sizeof b, "<rect x='0' y='0' width='%d' height='%d' fill='#fbfcfe' fill-opacity='.93'/>", PX0 - 2, H);
    s += b;
    for (int g = 0; g <= 6; g++) {
        double p = pll + span * g / 6.0; int y = (int)yP(p);
        snprintf(b, sizeof b, "<text x='%d' y='%d' font-size='11' fill='#9aa4b2' text-anchor='end' font-family='Consolas,monospace'>%s</text>",
                 PX0 - 6, y + 4, hnum(p, ph > 1000 ? 1 : (ph > 10 ? 3 : 5)).c_str());
        s += b;
    }
    snprintf(b, sizeof b, "<rect x='%d' y='0' width='%d' height='%d' fill='#fbfcfe' fill-opacity='.93'/>", W - 108, 108, H);
    s += b;
    {int y = (int)yP(p618);
     snprintf(b, sizeof b, "<text x='%d' y='%d' font-size='11.5' font-weight='bold' fill='#b07708' text-anchor='end' font-family='Consolas,monospace'>0.618 %s</text>",
              W - 6, y - 5, hnum(p618, ph > 1000 ? 2 : 4).c_str());
     s += b;}
    s += "</g>";
    s += "</svg>";
    return s;
}

// ==================================================================
//  K线数据包 (C++ 计算: 读库 → MACD → 力学 → SVG + 浏览器端交互数据)
// ==================================================================
struct ChartOut { std::string svg; std::string data; int n = 0; bool hasMore = false; };

static ChartOut make_chart(const std::string& inst, const std::string& tf, int nvis) {
    ChartOut co;
    std::vector<Bar> all = read_recent(inst, tf, 1000);
    int n = (int)all.size();
    std::vector<double> dif, dea, hist, e12, e26;
    if (n > 0) {
        std::vector<double> closes;
        std::string kt = ktable(inst, tf);
        RowSet wr = db_q("SELECT c FROM " + kt + " WHERE c>0 AND candle_time<" +
                         std::to_string(all[0].tms) + " ORDER BY candle_time DESC LIMIT 700");
        if (wr.ok) for (auto it = wr.rows.rbegin(); it != wr.rows.rend(); ++it) closes.push_back(atof_s((*it)[0]));
        for (auto& r : all) closes.push_back(r.c);
        macd_full(closes, 12, 26, 9, dif, dea, hist, e12, e26);
    }
    int start = n > nvis ? n - nvis : 0;
    std::vector<Bar> vis(all.begin() + start, all.end());
    std::vector<double> d1, a1, h1;
    if (n > 0) {
        d1.assign(dif.begin() + start, dif.end());
        a1.assign(dea.begin() + start, dea.end());
        h1.assign(hist.begin() + start, hist.end());
    }
    // 力学(C++)
    std::vector<int> pidx(n + 2), ptype(n + 2), pbars(n + 2);
    std::vector<double> pp(n + 2), pke(n + 2), pg(n + 2), pf(n + 2), pv(n + 2), pm(n + 2), pang(n + 2), pa2(n + 2);
    double th = 0;
    if (n >= 10) {
        std::vector<double> flat(n * 5);
        for (int i = 0; i < n; i++) {
            flat[i * 5] = all[i].o; flat[i * 5 + 1] = all[i].h; flat[i * 5 + 2] = all[i].l;
            flat[i * 5 + 3] = all[i].c; flat[i * 5 + 4] = all[i].v;
        }
        int np = pivots_full(flat.data(), n, tf.c_str(), pidx.data(), ptype.data(), pp.data(),
                             pke.data(), pg.data(), pf.data(), pv.data(), pm.data(), pang.data(),
                             pa2.data(), pbars.data(), &th, n + 2);
        if (np > 0) { pidx.resize(np > 0 ? np : 0); ptype.resize(np > 0 ? np : 0); pp.resize(np > 0 ? np : 0); pke.resize(np > 0 ? np : 0); }
    } else {
        pidx.clear(); ptype.clear(); pp.clear(); pke.clear();
    }
    // 是否还有更早的K线可加载(决定"左移贴边自动加载")
    co.hasMore = false;
    if (n > 0) {
        RowSet hm = db_q("SELECT 1 FROM " + ktable(inst, tf) + " WHERE c>0 AND candle_time<" +
                         std::to_string(all[0].tms) + " LIMIT 1");
        co.hasMore = hm.ok && !hm.rows.empty();
    }
    // 买卖标注(买入🚀/加仓▲/平仓🍃带盈利, 最近7天)
    std::vector<Pm> marks;
    {
        std::string D = " trade_time >= NOW() - INTERVAL 7 DAY";
        RowSet rs = db_q("SELECT UNIX_TIMESTAMP(trade_time)*1000, price, remark FROM trade_flow"
                         " WHERE inst_id='" + sqlesc(inst) + "' AND action='buy' AND remark LIKE 'hybrid open%'" + D);
        if (rs.ok) for (auto& r : rs.rows) marks.push_back({ atoll_s(r[0]), 0, atof_s(r[1]), 0 });
        rs = db_q("SELECT UNIX_TIMESTAMP(trade_time)*1000, price, remark FROM trade_flow"
                  " WHERE inst_id='" + sqlesc(inst) + "' AND action='add'" + D);
        if (rs.ok) for (auto& r : rs.rows) marks.push_back({ atoll_s(r[0]), 1, atof_s(r[1]), 0 });
        rs = db_q("SELECT FLOOR(UNIX_TIMESTAMP(trade_time)/60)*60000, MAX(price), SUM(COALESCE(profit,0)) FROM trade_flow"
                  " WHERE inst_id='" + sqlesc(inst) + "' AND action='close' AND profit IS NOT NULL"
                  " AND remark LIKE 'OKX pos-history%'" + D + " GROUP BY 1 ORDER BY 1 ASC");
        if (rs.ok) for (auto& r : rs.rows) marks.push_back({ atoll_s(r[0]), 2, atof_s(r[1]), atof_s(r[2]) });
    }
    co.svg = svg_kline(vis, d1, a1, h1, pidx, ptype, pp, pke, th, marks);
    co.n = (int)vis.size();

    // 浏览器端交互数据(几何常量与 svg_kline 严格一致)
    double cwWorld = SK_PX0 + co.n * SK_BW + 18;   // 内容总宽(世界坐标)
    std::string kd = "{\"w\":" + std::to_string(SK_W) + ",\"h\":" + std::to_string(SK_H) +
                     ",\"px0\":" + std::to_string(SK_PX0) + ",\"px1\":" + std::to_string(SK_PX1) +
                     ",\"bw\":" + jnum(SK_BW) + ",\"cw\":" + jnum(cwWorld) +
                     ",\"n\":" + std::to_string(co.n) +
                     ",\"has_more\":" + (co.hasMore ? "true" : "false") +
                     ",\"oldest\":" + std::to_string(vis.empty() ? 0 : vis.front().tms / 1000) +
                     ",\"newest\":" + std::to_string(vis.empty() ? 0 : vis.back().tms / 1000) +
                     ",\"bars\":[";
    for (size_t i = 0; i < vis.size(); i++) {
        const Bar& k = vis[i];
        if (i) kd += ",";
        kd += "[" + std::to_string(k.tms / 1000) + "," + jnum(k.o) + "," + jnum(k.h) + "," +
              jnum(k.l) + "," + jnum(k.c) + "," + jnum(k.v) + "]";
    }
    kd += "]}";
    co.data = kd;
    return co;
}

// ==================================================================
//  统计条 / 监测 / 流水 / 守护 片段 (全部 C++ 渲染)
// ==================================================================
struct LS { int closed = 0, wins = 0, trades = 0, openPos = 0; double net = 0, upl = 0, posM = 0;
            double feeS = 0, fundS = 0; std::string snap, inst; };

static LS load_ls(const std::string& inst) {
    LS o; o.inst = inst;
    Params q; q["inst"] = inst;
    std::string j = ep_livestats(q);
    o.net = atof_s(jgrab(j, "net_pnl"));
    o.upl = atof_s(jgrab(j, "upl"));
    o.posM = atof_s(jgrab(j, "pos_margin"));
    o.feeS = atof_s(jgrab(j, "fee_sum"));
    o.fundS = atof_s(jgrab(j, "fund_sum"));
    o.closed = atoi(jgrab(j, "closed").c_str());
    o.wins = atoi(jgrab(j, "wins").c_str());
    o.trades = atoi(jgrab(j, "trades").c_str());
    o.openPos = atoi(jgrab(j, "open_pos").c_str());
    o.snap = jgrab(j, "snap_time");
    return o;
}

// 顶栏实时统计(含持仓数量, 3秒 AJAX 增量替换)
static std::string stats_html(const std::string& inst) {
    LS s = load_ls(inst);
    double all = s.net + s.upl;
    double wr = s.closed > 0 ? (double)s.wins / s.closed * 100 : 0;
    auto ncol = [](double v) { return v > 0 ? "up" : (v < 0 ? "dn" : ""); };
    char b[64];
    auto nc = [&](double v) {
        snprintf(b, sizeof b, "%s%.2f U", v > 0 ? "+" : "", v);
        return std::string("<b class='") + ncol(v) + "'>" + b + "</b>";
    };
    std::string o;
    o += "<span>总盈亏(含浮动) " + nc(all) + "</span>";
    o += "<span>已实现 " + nc(s.net) + "</span>";
    o += "<span>浮动 " + nc(s.upl) + "</span>";
    snprintf(b, sizeof b, "%d", s.openPos);
    o += std::string("<span>持仓 <b class='") + (s.openPos > 0 ? "up" : "") + "'>" + b + "</b> 个 · 保证金 <b>" +
         hnum(s.posM, 2) + " U</b></span>";
    snprintf(b, sizeof b, "%d", s.trades);
    o += std::string("<span>交易 <b>") + b + "</b> 笔</span>";
    snprintf(b, sizeof b, "%d", s.closed);
    o += std::string("<span>已平仓 <b>") + b + "</b> · 胜率 <b>" + hnum(wr, 1) + "%</b></span>";
    o += "<span>手续费 <b>" + hnum(s.feeS, 3) + "</b> · 资金费 <b>" + hnum(s.fundS, 3) + "</b></span>";
    return o;
}
static std::string stats_extra(const std::string& inst) {
    LS s = load_ls(inst);
    // 图表卡右侧的持仓数量提示(用户要求: 持仓多少要写出来)
    return std::to_string(s.openPos);
}

// 监测卡: 概要行 + 表格行
static void grid_parts(const std::string& tf, int nvis, std::string& lineOut, std::string& rowsOut) {
    char b[512];
    LS s = load_ls("ETH-USDT-SWAP");
    std::string rows;
    int armed = 0, wait = 0, block = 0, closed = 0, nrow = 0;
    RowSet rs = db_q("SELECT inst_id,state,last_px,avg_px,ladder_adds,tp_px,tp_pct,dist_down_pct,note"
                     " FROM grid_signal WHERE state <> '已平仓'"
                     " ORDER BY (state LIKE '★%' OR state LIKE '等待%') DESC, dist_down_pct ASC LIMIT 60");
    if (rs.ok) for (auto& r : rs.rows) {
        nrow++;
        const std::string& st = r[1];
        if (st.find("已加仓") != std::string::npos || st.find("已触发") != std::string::npos) armed++;
        else if (st.find("风控") != std::string::npos) block++;
        else if (st.find("平仓") != std::string::npos) closed++;
        else wait++;
        const char* col = st.find("已加仓") != std::string::npos ? "#e03131"
                        : (st.find("风控") != std::string::npos ? "#e87c00"
                        : (st.find("平仓") != std::string::npos ? "#98a0ab" : "#2f6fed"));
        snprintf(b, sizeof b,
            "<tr class='clickable' data-inst='%s' title='%s'>"
            "<td style='color:#1c7ed6;text-decoration:underline'>%s</td>"
            "<td><b style='color:#e03131'>%s</b> 次</td>"
            "<td class='mono'>%s</td><td class='mono'>%s</td>"
            "<td class='mono'>%s <b style='color:#c0392b'>+%s%%</b></td>"
            "<td style='color:%s;font-weight:600'>%s</td></tr>",
            hesc(r[0]).c_str(), hesc(r[8]).c_str(), hesc(r[0]).c_str(),
            hesc(r[4]).c_str(), hesc(r[2]).c_str(), hesc(r[3]).c_str(),
            hesc(r[5]).c_str(), hesc(r[6]).c_str(), col, hesc(r[1]).c_str());
        rows += b;
    }
    if (rows.empty()) rows = "<tr><td colspan='6' class='muted'>当前无持仓在监测</td></tr>";
    snprintf(b, sizeof b,
        "<b>持仓 %d 个</b> · 保证金 <b>%.2f U</b> &nbsp;|&nbsp; 监测 %d 个 &nbsp;|&nbsp; 等待触发 %d "
        "&nbsp;|&nbsp; <b>已加仓信号 %d</b> &nbsp;|&nbsp; 风控拦截 %d &nbsp;|&nbsp; 已平仓 %d",
        s.openPos, s.posM, nrow, wait, armed, block, closed);
    lineOut = b;
    rowsOut = rows;
    (void)tf; (void)nvis;
}

// 流水卡: 表格行(最近 60 条, 平仓自动匹配 OKX 真实盈利)
static std::string flow_rows(const std::string& tf, int nvis, int& cntOut) {
    (void)tf; (void)nvis;
    std::string rows;
    int cnt = 0;
    RowSet rs = db_q("SELECT trade_time,inst_id,action,price,COALESCE(profit,0),COALESCE(fee,0),COALESCE(funding,0)"
                     " FROM trade_flow WHERE action IN ('buy','add','close')"
                     " ORDER BY trade_time DESC, id DESC LIMIT 60");
    if (rs.ok) for (auto& r : rs.rows) {
        cnt++;
        const char* act = strcmp(r[2].c_str(), "buy") == 0 ? "🚀买入" : (strcmp(r[2].c_str(), "add") == 0 ? "▲加仓" : "🍃平仓");
        bool isClose = strcmp(r[2].c_str(), "close") == 0;
        double pf = atof_s(r[4]);
        std::string pnlCell = isClose
            ? "<td class='" + std::string(pf > 0 ? "up" : (pf < 0 ? "dn" : "")) + "'>" +
              (pf > 0 ? "+" : "") + hnum(pf, 2) + "</td>"
            : "<td class='muted'>持仓中</td>";
        rows += "<tr class='clickable' data-inst='" + hesc(r[1]) + "'>"
              + "<td class='mono'>" + hesc(noyear_str(r[0])) + "</td>"
              + "<td style='color:#1c7ed6;text-decoration:underline'>" + hesc(r[1]) + "</td>"
              + "<td>" + act + "</td><td class='mono'>" + hnum(atof_s(r[3]), 6) + "</td>"
              + pnlCell
              + "<td class='muted mono'>" + (atof_s(r[5]) != 0 ? hnum(atof_s(r[5]), 4) : "-") + "</td>"
              + "<td class='muted mono'>" + (atof_s(r[6]) != 0 ? hnum(atof_s(r[6]), 4) : "-") + "</td></tr>";
    }
    if (rows.empty()) rows = "<tr><td colspan='7' class='muted'>暂无匹配流水</td></tr>";
    cntOut = cnt;
    return rows;
}

// 守护卡: 表格行(解析 finally_guard 的 guard_status.json)
static std::string guard_rows(const std::string& gj) {
    std::string rows;
    size_t p = gj.find("\"targets\":[");
    if (p == std::string::npos) return "<tr><td colspan='6' class='muted'>暂无守护信息</td></tr>";
    size_t q0 = p + 11, depth = 0, st = q0;
    for (size_t i = q0; i <= gj.size(); i++) {
        char c = i < gj.size() ? gj[i] : ']';
        if (c == '{') { if (depth == 0) st = i; depth++; }
        else if (c == '}') {
            depth--;
            if (depth == 0) {
                std::string o = gj.substr(st, i - st + 1);
                auto grab = [&](const char* k) -> std::string {
                    std::string kk = std::string("\"") + k + "\":";
                    size_t j = o.find(kk); if (j == std::string::npos) return "";
                    j += kk.size();
                    if (j < o.size() && o[j] == '"') { size_t e = o.find('"', j + 1); return o.substr(j + 1, e - j - 1); }
                    size_t e = o.find_first_of(",}", j);
                    return o.substr(j, e - j);
                };
                std::string lab = grab("label"), kind = grab("kind"), run = grab("run"),
                            ok = grab("ok"), pid = grab("pid"), msg = grab("msg"), rs2 = grab("restarts");
                rows += "<tr><td>" + hesc(lab) + "</td><td class='muted'>" + hesc(kind) + "</td>"
                      + "<td>" + (run == "1" ? "<span class='oktag'>运行</span>" : "<span class='badtag'>停止</span>") + "</td>"
                      + "<td>" + (ok == "1" ? "<span class='oktag'>正常</span>" : "<span class='badtag'>异常</span>") + "</td>"
                      + "<td class='mono'>" + hesc(pid == "0" || pid.empty() ? "-" : pid) + "</td>"
                      + "<td class='mono muted'>" + hesc(rs2.empty() ? "0" : rs2) + "</td>"
                      + "<td class='muted'>" + hesc(msg) + "</td></tr>";
            }
        }
    }
    if (rows.empty()) rows = "<tr><td colspan='7' class='muted'>暂无守护信息</td></tr>";
    return rows;
}

// ==================================================================
//  面板入口: GET / 或 /panel
// ==================================================================
extern const char* PANEL_JS;   // 定义见文件末尾(内联交互脚本), 此处前置声明

std::string render_panel(const Params& q) {
    std::string inst = get_inst(q);
    std::string tf = P(q, "tf", "5m");
    static const std::map<std::string, int> IV = { {"1m",60},{"3m",180},{"5m",300},{"15m",900},{"1h",3600} };
    if (!IV.count(tf)) tf = "5m";
    int nvis = atoi(P(q, "n", "300").c_str());
    if (nvis < 60) nvis = 60;
    if (nvis > 1500) nvis = 1500;

    ChartOut chart = make_chart(inst, tf, nvis);

    // 合约下拉(权威池全量) + 周期按钮 + 根数
    std::string optInsts;
    {
        RowSet rs = db_q("SELECT inst_id FROM symbol_pool ORDER BY inst_id");
        std::string cur = inst;
        bool found = false;
        if (rs.ok) for (auto& r : rs.rows) {
            if (cur == r[0]) found = true;
            optInsts += std::string("<option value='") + hesc(r[0]) + "'" + (cur == r[0] ? " selected" : "") + ">" + hesc(r[0]) + "</option>";
        }
        if (!found) optInsts = "<option value='" + hesc(cur) + "' selected>" + hesc(cur) + "</option>" + optInsts;
    }
    std::string tfBtns;
    for (auto& kv : IV) tfBtns += "<span class='tf" + std::string(tf == kv.first ? " on" : "") + "' data-tf='" + kv.first + "'>" + kv.first + "</span>";
    int NS[] = { 150, 300, 500, 800, 1200, 1500 };
    std::string optN;
    for (int v : NS) optN += "<option value='" + std::to_string(v) + "'" + (nvis == v ? " selected" : "") + ">" + std::to_string(v) + "根</option>";

    // 最近交易快捷 chips
    std::string recent;
    {
        std::string bj = ep_boot(Params());
        size_t a = bj.find("\"recent\":[");
        if (a != std::string::npos) {
            size_t e = bj.find(']', a);
            std::string arr = bj.substr(a + 10, e - a - 10);
            size_t i = 0;
            while (i < arr.size()) {
                size_t s0 = arr.find('"', i); if (s0 == std::string::npos) break;
                size_t s1 = arr.find('"', s0 + 1); if (s1 == std::string::npos) break;
                std::string v = arr.substr(s0 + 1, s1 - s0 - 1);
                std::string sh = v; size_t us = sh.find("-USDT-SWAP"); if (us != std::string::npos) sh = sh.substr(0, us);
                recent += "<span class='tf" + std::string(v == inst ? " on" : "") + "' data-inst='" + hesc(v) + "'>" + hesc(sh) + "</span>";
                i = s1 + 1;
            }
        }
    }

    // 各卡片初始数据(C++ 渲染, 之后由 AJAX 增量替换)
    std::string stHtml = stats_html(inst);
    std::string posCnt = stats_extra(inst);
    std::string gLine, gRows, fRows, gdRows;
    grid_parts(tf, nvis, gLine, gRows);
    int fCnt = 0;
    fRows = flow_rows(tf, nvis, fCnt);
    gdRows = guard_rows(ep_guard(Params()));
    LS ls = load_ls(inst);

    std::string o;
    o.reserve(120000);
    o += "<!DOCTYPE html><html lang='zh-CN'><head><meta charset='UTF-8'>"
         "<meta name='viewport' content='width=device-width,initial-scale=1'>"
         "<title>OKX 量化交易面板 · C++</title>"
         "<link rel='stylesheet' href='/panel.css?v=0928k'>"
         "</head><body>"

         // ---- 顶栏 ----
         "<div class='top'><b>📈 OKX 量化交易面板</b>"
         "<select id='symbol'>" + optInsts + "</select>"
         "<span id='status'>✓ 实时同步 " + hdate2((long long)time(nullptr)) + "</span>"
         "<span style='flex:1'></span>"
         "<span id='slineTop' class='muted'>引擎 tradehub(C++) · 无PHP · 无客户端</span></div>"

         // ---- 导航 ----
         "<div class='top nav'>"
         "<a href='#chartCard'>K线图</a><a href='#flowCard'>交易流水</a>"
         "<a href='#guardCard'>守护状态</a><a href='#monWin'>实时监测</a>"
         "<span style='flex:1'></span>"
         "<button class='rbtn' onclick='OKXP.all()'>⟳ 全部刷新</button>"
         "<button class='rbtn' onclick='OKXP.chart()'>⟳ K线</button>"
         "</div>"

         // ---- 策略跑马灯 ----
         "<div class='top' style='padding-top:5px;padding-bottom:6px'>"
         "<div id='aiMarq'><span class='mq'>策略：买入=15m+5m 黄金坑共振 · 20X · 每笔1U · 价格+2%止盈(ROI40%) · 永不止损 · "
         "加仓=跌时5m黄金坑 每轮+⅓U 不限轮数 · 只买权威池(symbol_pool) · 只买涨　|　"
         "架构：C++ 渲染 HTML+SVG(apihub) + C++ 交易中枢(tradehub) + C++ 数据中枢(datahub) + C++ 守护(guard) · "
         "0 PHP · 0 外部JS · AJAX 增量刷新(无整页跳转)　|　数据：全量 MySQL 存储(无 json 落盘)</span></div></div>"

         // ---- 实时统计条 ----
         "<div class='stats' id='statsBar'>" + stHtml + "</div>"

         "<div class='wrap'>"

         // ---- K线卡 ----
         "<div class='card c-chart' id='chartCard'>"
         "<h3 class='hc-chart'><span class='ht'>K线图（拖动=K线跟随鼠标平移 · 滚轮缩放 · 拖到最早端自动加载更早K线 · 悬停OHLC · 红涨绿跌）</span>"
         "<span class='fr'><span class='muted' id='chartPos'>持仓 " + posCnt + " 个</span>"
         "<select id='nsel' class='inp' style='padding:2px 6px'>" + optN + "</select>"
         "<button class='rbtn' onclick='OKXP.chart()'>⟳</button>"
         "<button class='rbtn' onclick='OKXP.latest()'>⏭ 最新</button></span></h3>"
         "<div id='tfs'>" + tfBtns + "</div>"
         "<div id='recentInsts'><span class='muted' style='font-size:12px'>⚡最近交易:</span>" + recent + "</div>"
         "<div class='kwrap' id='kwrap'><div id='ktip'></div>"
         "<div id='kgraph'>" + chart.svg + "</div>"
         "<div id='viewHint'></div><div id='fetch'></div></div>"
         "<div class='legend' id='klegend'>"
         "<span style='color:#e6a817;font-weight:bold'>▲金箭头</span>=买入点(动能前25%) · "
         "<b>🚀</b>=买入开仓 · <b style='color:#e6a817'>▲加</b>=跌时黄金坑加仓 · "
         "<b>🍃</b>=平仓(旁标盈利额, 盈红亏绿) · <b style='color:#b07708'>KE=½mv²</b> 动能 · "
         "<b style='color:#b07708'>g=v²/2h</b> 重力加速度 · <b style='color:#b07708'>F=mg</b>(牛顿二) · "
         "<span style='color:#e6a817'>┄0.618黄金分割</span> · <span style='color:#e8890c'>—DIF</span> "
         "<span style='color:#3b7dd8'>—DEA</span> MACD(12,26,9)　"
         "<b>🖱 按住拖动=K线跟随鼠标 · 滚轮缩放 · 双击回最新 · 悬停看OHLC · 拖到最左端自动加载更早K线</b>　"
         "<b style='color:#b07708'>当前 <span id='kinfo'>" + hesc(inst) + " · " + hesc(tf) + " · " + std::to_string(nvis) + "根</span></b></div>"
         "</div>"

         // ---- 流水卡 ----
         "<div class='card c-flow' id='flowCard' style='margin-top:12px'>"
         "<h3 class='hc-flow'><span class='ht'>交易流水（实时更新 · 平仓自动匹配OKX真实盈利 · 点合约名切K线）</span>"
         "<span class='fr'><span class='muted' id='flowCnt'>共 " + std::to_string(fCnt) + " 条</span>"
         "<button class='rbtn' onclick='OKXP.flow()'>⟳</button></span></h3>"
         "<div class='tw'><table id='flowT'><thead><tr>"
         "<th>时间</th><th>合约</th><th>方向</th><th>价格</th><th>净盈亏$</th><th>手续费$</th><th>资金费$</th>"
         "</tr></thead><tbody id='flowRows'>" + fRows + "</tbody></table></div></div>"

         // ---- 守护卡 ----
         "<div class='card c-chk' id='guardCard' style='margin-top:12px'>"
         "<h3 class='hc-chk'><span class='ht'>守护状态（finally_guard · Windows服务 · 全链路自愈）</span>"
         "<span class='fr'><button class='rbtn' onclick='OKXP.guard()'>⟳</button></span></h3>"
         "<div class='tw'><table id='guardT'><thead><tr>"
         "<th>目标</th><th>类型</th><th>运行</th><th>探活</th><th>pid</th><th>重启</th><th>说明</th>"
         "</tr></thead><tbody id='guardRows'>" + gdRows + "</tbody></table></div></div>"

         "<div class='foot'>C++ (apihub.exe) 直接渲染本页 · AJAX 增量刷新(3s/10s/15s) · "
         "<span class='mono'>持仓 " + posCnt + " 个</span></div>"
         "</div>"

         // ---- 悬浮实时监测窗(可拖动/可折叠, 与旧面板一致) ----
         "<div class='card c-grid float-monitor' id='monWin'>"
         "<h3 class='hc-grid'><span class='ht'>实时监测（黄金坑全池 · 20X · 每笔1U · +2%止盈 · 不止损 · 只买涨）</span>"
         "<span class='fr'><button class='rbtn' onclick='OKXP.grid()'>⟳</button>"
         "<button class='rbtn gmin' id='gminBtn' title='折叠/展开'>─</button></span></h3>"
         "<div class='gw-body'>"
         "<div class='sline' id='gline'>" + gLine + "</div>"
         "<div class='tw' id='gridTW'><table id='gridT'><thead><tr>"
         "<th>合约</th><th>加仓次数</th><th>现价</th><th>均价</th><th>止盈</th><th>状态</th>"
         "</tr></thead><tbody id='gridRows'>" + gRows + "</tbody></table></div>"
         "<div class='sline' style='margin-top:6px'><b>加仓策略</b>：跌时(现价&lt;均价)出现 5m 黄金坑信号即加仓 · "
         "每轮固定 +1U/3 · 不限轮数 · 加仓无仓位上限 | 永不止损 | 止盈不挂单：每10秒检测 价格+2%(ROI40%) 达标立即市价全平</div>"
         "</div></div>"

         "<script>window.PANEL_STATE={\"inst\":\"" + jesc(inst) + "\",\"tf\":\"" + jesc(tf) + "\",\"n\":" +
         std::to_string(nvis) + "};window.KDATA=" + chart.data + ";" + PANEL_JS + "</script>"
         "</body></html>";
    return o;
}

// ==================================================================
//  AJAX 片段接口 (C++ 渲染, 浏览器端仅替换 DOM)
//    /frag?name=stats|grid|flow|guard|chart&inst&tf&n
// ==================================================================
std::string ep_frag(const Params& q) {
    std::string name = P(q, "name", "");
    std::string inst = get_inst(q);
    std::string tf = P(q, "tf", "5m");
    int n = atoi(P(q, "n", "200").c_str());
    if (n < 60) n = 60;
    if (n > 2000) n = 2000;

    if (name == "stats") {
        std::string h = stats_html(inst);
        return "{\"ok\":true,\"html\":\"" + jesc(h) + "\",\"pos\":" + stats_extra(inst) + "}";
    }
    if (name == "grid") {
        std::string line, rows;
        grid_parts(tf, n, line, rows);
        return "{\"ok\":true,\"line\":\"" + jesc(line) + "\",\"rows\":\"" + jesc(rows) + "\"}";
    }
    if (name == "flow") {
        int cnt = 0;
        std::string rows = flow_rows(tf, n, cnt);
        return "{\"ok\":true,\"rows\":\"" + jesc(rows) + "\",\"cnt\":" + std::to_string(cnt) + "}";
    }
    if (name == "guard") {
        std::string rows = guard_rows(ep_guard(Params()));
        return "{\"ok\":true,\"rows\":\"" + jesc(rows) + "\"}";
    }
    if (name == "chart") {
        ChartOut co = make_chart(inst, tf, n);
        // co.data = {"w":..,"bars":[..]} → 直接内联, 再补 svg
        std::string inner = co.data;
        if (!inner.empty() && inner[0] == '{') inner = inner.substr(1);   // 去掉开头的 {
        return "{\"ok\":true,\"svg\":\"" + jesc(co.svg) + "\"," + inner;
    }
    return "{\"ok\":false,\"error\":\"unknown frag\"}";
}

// ==================================================================
//  内联交互脚本 (0 外部文件; 缩放/平移/悬停/AJAX/特效全本地)
// ==================================================================
const char* PANEL_JS = R"JSPANEL(
(function(){
'use strict';
var W=1180,H=640,PX0=62,PX1=1128;
var ST=window.PANEL_STATE||{inst:'ETH-USDT-SWAP',tf:'5m',n:200};
ST.busy={};ST.hasMore=false;
var D=null,s=1,tx=0,ty=0,drag=null,svg=null,zg=null,tip=null,ch=null,bw=8,CW=1180,autoArmed=true;
function $(i){return document.getElementById(i);}
function setH(i,h){var e=$(i);if(e)e.innerHTML=h;}
function say(t){var e=$('status');if(e)e.textContent=t;}
function clock(){return new Date().toLocaleTimeString('zh-CN',{hour12:false});}
function jget(u){return fetch(u,{cache:'no-store'}).then(function(r){return r.json();});}
function frag(n,x){return '/frag?name='+n+'&inst='+encodeURIComponent(ST.inst)+'&tf='+encodeURIComponent(ST.tf)+'&n='+ST.n+(x||'');}
function showFetch(t){var e=$('fetch');if(e){e.textContent=t;e.style.display='block';}}
function hideFetch(){var e=$('fetch');if(e)e.style.display='none';}
/* ---------- K线视图变换 ---------- */
function pt(ev){var p=svg.createSVGPoint();p.x=ev.clientX;p.y=ev.clientY;var m=svg.getScreenCTM();return m?p.matrixTransform(m.inverse()):null;}
function txMin(){return W-CW*s;}
function clamp(){if(tx>0)tx=0;if(tx<txMin())tx=txMin();if(ty>0)ty=0;if(ty<H-H*s)ty=H-H*s;}
function atNew(){return tx<=txMin()+.6;}
function atOld(){return tx>=-.6;}
function apply(){
  clamp();
  if(zg)zg.setAttribute('transform','translate('+tx+' '+ty+') scale('+s+')');
  var h=$('viewHint');
  if(h){
    if(s<=1.001&&atNew()){h.style.display='none';}
    else{
      h.style.display='block';
      h.textContent=(s>1.001?s.toFixed(2)+'x · ':'')+
        (atOld()&&D&&D.hasMore?'⬅ 已到最早, 继续拖自动加载更早K线':'拖动平移 · 滚轮缩放 · 双击回最新');
    }
  }
}
/* ---------- 特效: 魔法棒光标 + 星星粒子 ---------- */
function mkSpark(kw,x,y){
  var e=document.createElement('span');e.className='spark';
  e.textContent=Math.random()<.5?'\u2728':'\u2b50';
  e.style.left=(x-9)+'px';e.style.top=(y-9)+'px';
  kw.appendChild(e);setTimeout(function(){if(e.parentNode)e.parentNode.removeChild(e);},1200);
}
function sparks(kw,x,y){for(var i=0;i<3;i++)mkSpark(kw,x+(Math.random()*26-13),y+(Math.random()*26-13));}
setInterval(function(){
  if(document.hidden)return;
  var kw=$('kwrap');if(!kw)return;var r=kw.getBoundingClientRect();
  if(r.width<80||r.height<80)return;
  mkSpark(kw,12+Math.random()*(r.width-30),14+Math.random()*(r.height-60));
},900);
/* ---------- 绑定K线交互(每次换 svg 后重新绑定) ---------- */
function bind(){
  if(!svg||svg.__b)return;svg.__b=1;
  svg.addEventListener('wheel',function(ev){
    ev.preventDefault();
    var f=ev.deltaY<0?1.18:1/1.18,ns=s*f;
    if(ns<0.6)ns=0.6;if(ns>60)ns=60;
    var p=pt(ev);if(!p)return;
    tx=p.x-(p.x-tx)*ns/s;ty=p.y-(p.y-ty)*ns/s;s=ns;
    apply();
  },{passive:false});
  svg.addEventListener('mousedown',function(ev){
    drag={x:ev.clientX,y:ev.clientY,tx:tx,ty:ty};
    var kw=$('kwrap');if(kw&&ev.button===0){
      svg.style.cursor='url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'30\' height=\'30\'><text y=\'24\' font-size=\'24\'>🤚</text></svg>") 15 6, pointer';
      var r=kw.getBoundingClientRect();sparks(kw,ev.clientX-r.left,ev.clientY-r.top);
    }
    ev.preventDefault();
  });
  window.addEventListener('mousemove',function(ev){
    if(!drag)return;var m=svg.getScreenCTM();if(!m)return;
    var k=1/(m.a||1),dx=ev.clientX-drag.x,dy=ev.clientY-drag.y;
    tx=drag.tx+dx/k;ty=drag.ty+dy/k;          // K线跟随鼠标移动
    apply();
    // 拖到最早一端还继续往左拉 → 自动加载更早的K线(C++ 取库)
    if(tx>=-6&&dx>0&&D&&D.hasMore)loadMore();
  });
  window.addEventListener('mouseup',function(){if(drag){drag=null;svg.style.cursor='';}});
  svg.addEventListener('dblclick',function(){s=1;ty=0;tx=Math.min(0,W-CW);apply();});
  ch=document.createElementNS('http://www.w3.org/2000/svg','line');
  ch.setAttribute('stroke','#b6c2d1');ch.setAttribute('stroke-width','1');
  ch.setAttribute('stroke-dasharray','4,3');ch.setAttribute('visibility','hidden');
  ch.setAttribute('y1','14');ch.setAttribute('y2','576');
  svg.appendChild(ch);
  svg.addEventListener('mousemove',function(ev){
    if(!D)return;var p=pt(ev);if(!p)return;
    ch.setAttribute('x1',p.x);ch.setAttribute('x2',p.x);ch.setAttribute('visibility','visible');
    var wx=(p.x-tx)/s,i=Math.floor((wx-PX0)/bw);
    if(!tip)return;
    var box=svg.getBoundingClientRect();
    if(i>=0&&i<D.bars.length){
      var b=D.bars[i],up=b[4]>=b[1];
      tip.innerHTML='<b>'+(function(sec){var d=new Date(sec*1000);function z(v){return (v<10?'0':'')+v;}
        return (d.getMonth()+1)+'-'+z(d.getDate())+' '+z(d.getHours())+':'+z(d.getMinutes());})(b[0])+'</b>'+
        '<br>开 '+b[1]+'<br>高 '+b[2]+'<br>低 '+b[3]+
        '<br>收 <span style="color:'+(up?'#e03131':'#0a9c56')+'">'+b[4]+'</span><br>量 '+b[5];
      tip.style.display='block';
      var lx=ev.clientX-box.left+14,ly=ev.clientY-box.top+10;
      if(lx>box.width-140)lx=ev.clientX-box.left-150;
      if(ly>box.height-120)ly=ev.clientY-box.top-120;
      tip.style.left=lx+'px';tip.style.top=ly+'px';
    }else{tip.style.display='none';}
  });
  svg.addEventListener('mouseleave',function(){if(ch)ch.setAttribute('visibility','hidden');if(tip)tip.style.display='none';});
}
function mount(reset){
  var nsvg=$('ksvg');if(!nsvg)return;
  if(nsvg!==svg){svg=nsvg;zg=$('zg');tip=$('ktip');bind();}
  D=window.KDATA;if(!D||!D.bars||!D.bars.length)return;
  bw=D.bw||8;
  CW=D.cw||(D.px0+D.bars.length*bw+18);
  if(reset){s=1;ty=0;tx=Math.min(0,W-CW);}
  apply();
}
function kinfo(){var e=$('kinfo');if(e)e.textContent=ST.inst+' · '+ST.tf+' · '+ST.n+'根';}
/* ---------- K线: AJAX 刷新(reset=true 回到最新) ---------- */
function loadChart(reset){
  if(ST.busy.chart)return Promise.resolve(false);
  ST.busy.chart=1;showFetch('刷新K线…');
  return jget(frag('chart','')).then(function(j){
    ST.busy.chart=0;hideFetch();
    if(!j||!j.ok)return false;
    ST.n=j.n||ST.n;ST.hasMore=!!j.has_more;
    window.KDATA={w:j.w,h:j.h,px0:j.px0,px1:j.px1,bw:j.bw,cw:j.cw,bars:j.bars,hasMore:ST.hasMore};
    setH('kgraph',j.svg||'');
    mount(reset);kinfo();return true;
  })['catch'](function(e){ST.busy.chart=0;hideFetch();say('⚠ K线刷新失败: '+e);return false;});
}
/* ---------- K线: 贴到最早一端继续拖 → 自动加载更早数据(C++ 取库) ---------- */
function loadMore(){
  if(ST.busy.more||ST.busy.chart||!D||!D.hasMore||ST.n>=1500)return;
  ST.busy.more=1;
  var wx=(0-tx)/s,i0=Math.floor((wx-PX0)/bw);
  if(i0<0)i0=0;if(i0>D.bars.length-1)i0=D.bars.length-1;
  var aT=D.bars[i0][0],wOld=PX0+(i0+.5)*bw;
  showFetch('⬅ 正在加载更早K线…');
  var nNew=Math.min(1500,ST.n+300);
  jget(frag('chart','&n='+nNew)).then(function(j){
    ST.busy.more=0;hideFetch();
    if(!j||!j.ok)return;
    ST.n=j.n;ST.hasMore=!!j.has_more;
    window.KDATA={w:j.w,h:j.h,px0:j.px0,px1:j.px1,bw:j.bw,cw:j.cw,bars:j.bars,hasMore:ST.hasMore};
    setH('kgraph',j.svg||'');
    mount(false);
    var idx=-1;for(var i=0;i<D.bars.length;i++){if(D.bars[i][0]===aT){idx=i;break;}}
    if(idx>=0){
      var dtx=s*(wOld-(PX0+(idx+.5)*bw));
      tx+=dtx;if(drag)drag.tx+=dtx;      // 拖动中也要同步基准, 否则视图回弹
    }
    apply();kinfo();
    say('⬅ 已加载更早K线, 共 '+ST.n+' 根');
  })['catch'](function(){ST.busy.more=0;hideFetch();});
}
/* ---------- K线: 定时跟随最新(用户在看历史时不打扰) ---------- */
function tickChart(){
  if(document.hidden)return;
  if(!atNew())return;
  var n0=D?D.bars[D.bars.length-1][0]:0;
  loadChart(false).then(function(ok){
    if(!ok)return;
    tx=Math.min(0,W-CW);apply();
    if(D&&D.bars.length&&D.bars[D.bars.length-1][0]>n0)say('✓ 实时同步 '+clock());
  });
}
/* ---------- 其余卡片 AJAX ---------- */
function bindRows(root){
  var r=$(root);if(!r)return;
  var els=r.querySelectorAll('[data-inst]');
  for(var i=0;i<els.length;i++){(function(el){
    el.style.cursor='pointer';
    el.onclick=function(){switchInst(el.getAttribute('data-inst'));};
  })(els[i]);}
}
function refreshStats(){
  jget(frag('stats')).then(function(j){
    if(!j||!j.ok)return;
    setH('statsBar',j.html);
    var e=$('chartPos');if(e)e.textContent='持仓 '+j.pos+' 个';
  })['catch'](function(){});
}
function refreshGrid(){
  jget(frag('grid')).then(function(j){
    if(!j||!j.ok)return;
    setH('gline',j.line);setH('gridRows',j.rows);bindRows('gridRows');
  })['catch'](function(){});
}
function refreshFlow(){
  jget(frag('flow')).then(function(j){
    if(!j||!j.ok)return;
    setH('flowRows',j.rows);
    var e=$('flowCnt');if(e)e.textContent='共 '+j.cnt+' 条';
    bindRows('flowRows');
  })['catch'](function(){});
}
function refreshGuard(){
  jget(frag('guard')).then(function(j){if(j&&j.ok)setH('guardRows',j.rows);})['catch'](function(){});
}
/* ---------- 切换合约 / 周期 ---------- */
function switchInst(i){
  if(!i||i===ST.inst)return;
  ST.inst=i;
  try{history.replaceState(null,'','/?inst='+encodeURIComponent(i)+'&tf='+ST.tf+'&n='+ST.n);}catch(e){}
  var sel=$('symbol');if(sel)sel.value=i;
  var els=document.querySelectorAll('#recentInsts .tf');
  for(var k=0;k<els.length;k++){if(els[k].getAttribute('data-inst')===i)els[k].classList.add('on');else els[k].classList.remove('on');}
  say('切换 '+i+' …');
  ST.n=Math.max(200,ST.n);
  loadChart(true).then(function(){refreshStats();refreshGrid();refreshFlow();});
}
function switchTf(t){
  if(!t||t===ST.tf)return;
  ST.tf=t;
  var els=document.querySelectorAll('#tfs .tf');
  for(var k=0;k<els.length;k++){if(els[k].getAttribute('data-tf')===t)els[k].classList.add('on');else els[k].classList.remove('on');}
  try{history.replaceState(null,'','/?inst='+encodeURIComponent(ST.inst)+'&tf='+t+'&n='+ST.n);}catch(e){}
  say('切换周期 '+t+' …');
  loadChart(true).then(function(){refreshGrid();refreshFlow();});
}
/* ---------- 对外按钮 ---------- */
window.OKXP={
  chart:function(){loadChart(true);},
  latest:function(){loadChart(true).then(function(){s=1;ty=0;tx=Math.min(0,W-CW);apply();});},
  stats:refreshStats,grid:refreshGrid,flow:refreshFlow,guard:refreshGuard,
  inst:switchInst,tf:switchTf,
  all:function(){loadChart(true);refreshStats();refreshGrid();refreshFlow();refreshGuard();
    var e=$('nsel');if(e)ST.n=parseInt(e.value,10)||ST.n;say('⟳ 全部刷新 '+clock());}
};
/* ---------- 事件绑定 ---------- */
(function(){
  var sel=$('symbol');
  if(sel)sel.onchange=function(){switchInst(sel.value);};
  var els=document.querySelectorAll('#tfs .tf');
  for(var i=0;i<els.length;i++){(function(el){
    el.onclick=function(){switchTf(el.getAttribute('data-tf'));};
  })(els[i]);}
  var els2=document.querySelectorAll('#recentInsts .tf');
  for(var j=0;j<els2.length;j++){(function(el){
    el.onclick=function(){switchInst(el.getAttribute('data-inst'));};
  })(els2[j]);}
  var ns=$('nsel');
  if(ns)ns.onchange=function(){ST.n=parseInt(ns.value,10)||ST.n;loadChart(true);};
  var mb=$('gminBtn');
  if(mb)mb.onclick=function(e){
    e.stopPropagation();
    var mw=$('monWin');if(!mw)return;
    mw.classList.toggle('min');
    mb.textContent=mw.classList.contains('min')?'□':'─';
  };
})();
/* ---------- 悬浮监测窗: 标题栏拖动 ---------- */
(function(){
  var mw=$('monWin');if(!mw)return;
  var head=mw.querySelector('h3');
  var dr=false,sx=0,sy=0,ox=0,oy=0;
  function toAbs(){
    var r=mw.getBoundingClientRect();
    mw.style.left=Math.max(8,(window.innerWidth-r.width)/2)+'px';
    mw.style.top=Math.max(8,window.innerHeight-r.height-12)+'px';
    mw.style.bottom='auto';mw.style.right='auto';mw.style.transform='none';
  }
  head.addEventListener('mousedown',function(e){
    if(e.target&&e.target.id==='gminBtn')return;
    if(mw.style.left==='')toAbs();
    dr=true;sx=e.clientX;sy=e.clientY;ox=mw.offsetLeft;oy=mw.offsetTop;
    e.preventDefault();
  });
  window.addEventListener('mousemove',function(e){
    if(!dr)return;
    mw.style.left=Math.min(Math.max(8,ox+e.clientX-sx),window.innerWidth-120)+'px';
    mw.style.top=Math.min(Math.max(8,oy+e.clientY-sy),window.innerHeight-40)+'px';
  });
  window.addEventListener('mouseup',function(){dr=false;});
})();
/* ---------- 启动 ---------- */
mount(true);bindRows('gridRows');bindRows('flowRows');
setInterval(refreshStats,3000);
setInterval(refreshGrid,3000);
setInterval(refreshFlow,10000);
setInterval(refreshGuard,10000);
setInterval(tickChart,15000);
setInterval(function(){if(!document.hidden)say('✓ 实时同步 '+clock());},5000);
})();
)JSPANEL";

// ==================================================================
//  面板 CSS — 恢复旧版视觉(隶书/金渐变标题/彩色卡片/悬浮监测窗) + 特效
// ==================================================================
const char* PANEL_CSS = R"CSSCSS(
:root{--red:#e03131;--green:#0ca678;--blue:#2f6fed;--bg:#f4f6fa;--card:#fff;--line:#e0e5ef;--txt:#1f2329}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:15px/1.55 "LiSu","隶书","KaiTi","STKaiti","Microsoft YaHei",serif;color:var(--txt)}
.wrap{padding:12px 14px 130px;max-width:1560px;margin:0 auto}
.top{background:linear-gradient(180deg,#fff,#f0f1f5);border-bottom:1px solid var(--line);padding:9px 16px;
 display:flex;gap:12px;align-items:center;flex-wrap:wrap;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.top b{font-size:20px;font-weight:bold;letter-spacing:1px;
 background:linear-gradient(180deg,#ffe9a8,#f6c445 45%,#c98a1b 55%,#f2b52f);-webkit-background-clip:text;background-clip:text;
 color:transparent;filter:drop-shadow(0 1px 1px rgba(90,60,0,.55)) drop-shadow(0 -1px 0 rgba(255,255,255,.35))}
select,.inp{border:1px solid #cfd6e4;border-radius:6px;padding:5px 10px;background:#fff;font-size:13px;outline:none;font-family:inherit;color:var(--txt)}
select:hover,.inp:hover{border-color:var(--blue)}
#status{font-size:12px;color:#8a93a6}
.nav{gap:2px}
.nav a{font-size:14px;color:#33465e;text-decoration:none;padding:4px 12px;border:1px solid transparent;border-radius:6px}
.nav a:hover{background:#e8f0ff;border-color:#cfd6e4;color:#1c4fa0}
.rbtn{background:#fff;border:1px solid #cfd6e4;border-radius:6px;font-size:12px;padding:2px 9px;cursor:pointer;
 color:#33465e;font-family:inherit;line-height:1.7}
.rbtn:hover{background:#e8f0ff;border-color:var(--blue)}
#aiMarq{flex:1 1 260px;min-width:220px;overflow:hidden;white-space:nowrap;border:1px solid #b8e6d9;border-radius:8px;
 background:linear-gradient(180deg,#eafcf5,#d3f4e8);padding:4px 0;color:#0b5c40;font-size:14px}
#aiMarq .mq{display:inline-block;padding-left:60%;animation:mqmove 70s linear infinite}
#aiMarq:hover .mq{animation-play-state:paused;cursor:pointer}
@keyframes mqmove{0%{transform:translateX(0)}100%{transform:translateX(-100%)}}
.stats{display:flex;gap:16px;flex-wrap:wrap;align-items:center;background:#fff;border:1px solid var(--line);
 border-radius:10px;padding:8px 16px;margin:10px 14px 0;font-size:13.5px;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.stats b{font-weight:bold}
.card{border:1px solid rgba(90,110,160,.18);border-radius:10px;padding:12px;box-shadow:0 2px 10px rgba(60,80,140,.08)}
.card h3{font-size:19px;font-weight:bold;margin:0 0 8px;padding-bottom:6px;border-bottom:1px solid rgba(120,140,180,.25);
 display:flex;align-items:center;justify-content:center;gap:10px;position:relative;color:#2c3444}
.card h3 .ht{background-clip:text;-webkit-background-clip:text;color:transparent;transition:filter .2s;
 filter:drop-shadow(0 1px 1px rgba(0,0,0,.28)) drop-shadow(0 -1px 0 rgba(255,255,255,.4))}
.card h3:hover .ht{filter:drop-shadow(0 1px 2px rgba(0,0,0,.45)) brightness(1.15)}
.card h3 .fr{position:absolute;right:0;top:0;font-size:12px;display:flex;gap:6px;align-items:center;color:#8a93a6}
.hc-chart .ht{background-image:linear-gradient(180deg,#b48cff,#7b3ff2 45%,#4c1fa8 55%,#8d55f5)}
.hc-flow .ht{background-image:linear-gradient(180deg,#8ff0c4,#12b886 45%,#087f5b 55%,#2ecf9a)}
.hc-grid .ht{background-image:linear-gradient(180deg,#ffd08a,#f76707 45%,#c44e00 55%,#ff922b)}
.hc-chk .ht{background-image:linear-gradient(180deg,#9ec2ff,#1d6fe0 45%,#0d3f8f 55%,#4d97f2)}
.c-chart{background:linear-gradient(160deg,#eef4ff 0%,#f6f0ff 55%,#fdf2ff 100%)}
.c-flow{background:linear-gradient(160deg,#edfbf4 0%,#f0f9ff 100%)}
.c-grid{background:linear-gradient(160deg,#fff7e8 0%,#ffefe9 100%)}
.c-chk{background:linear-gradient(160deg,#f2f0ff 0%,#eef8ff 100%)}
#tfs{display:flex;gap:6px;margin:0 0 8px;flex-wrap:wrap}
.tf{cursor:pointer;border:1px solid #c9d4e4;border-radius:6px;padding:2px 12px;font-size:13px;color:#33465e;
 background:#fff;user-select:none}
.tf.on{background:#2b6cb0;color:#fff;border-color:#2b6cb0;font-weight:700}
#recentInsts{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:0 2px 8px}
.kwrap{position:relative}
#kgraph{width:100%;background:#fff;border:1px solid #e3e8f0;border-radius:8px;overflow:hidden;
 cursor:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='60' height='60'><text y='48' font-size='48'>🪄</text></svg>") 8 48, crosshair}
#kgraph svg{display:block}
.spark{position:absolute;pointer-events:none;font-size:36px;z-index:4;animation:spk 1.2s ease-out forwards}
@keyframes spk{0%{transform:scale(.4);opacity:1}60%{transform:scale(1.35);opacity:.95}100%{transform:scale(.5);opacity:0}}
#ktip{display:none;position:absolute;z-index:9;background:rgba(255,255,255,.97);border:1px solid #c9d4e4;
 border-radius:8px;padding:7px 10px;font:12px/1.7 Consolas,monospace;color:#1d2733;
 box-shadow:0 4px 14px rgba(20,40,80,.18);pointer-events:none;white-space:nowrap}
#fetch{position:absolute;right:14px;top:10px;z-index:8;font-size:12px;color:#2b6cb0;background:rgba(255,255,255,.94);
 border:1px solid #c9d4e4;border-radius:8px;padding:3px 10px;box-shadow:0 2px 8px rgba(20,40,80,.12);display:none}
#viewHint{display:none;position:absolute;left:14px;top:10px;z-index:8;font-size:12px;color:#8a6d3b;
 background:rgba(255,250,235,.95);border:1px solid #e8d6a8;border-radius:8px;padding:3px 10px;pointer-events:none}
.legend{font-size:12px;color:#8a93a6;margin-top:7px;line-height:1.9}
.tw{border:1px solid var(--line);border-radius:6px;overflow:auto;max-height:min(340px,36vh)}
#gridTW{max-height:min(420px,44vh)}
table{width:100%;border-collapse:collapse;font-size:14.5px;font-family:"LiSu","隶书","KaiTi","Microsoft YaHei",serif}
thead th{position:sticky;top:0;z-index:2;padding:7px 6px;text-align:center;font-size:16px;font-weight:bold;
 white-space:nowrap;border-bottom:1px solid var(--line)}
#flowT thead th{background:linear-gradient(180deg,#d8f5e6,#b2e6cd);border-bottom:2px solid #0ca678;color:#0b5c40}
#gridT thead th{background:linear-gradient(180deg,#ffe8c2,#ffd39e);border-bottom:2px solid #e8590c;color:#8a3a00}
#guardT thead th{background:linear-gradient(180deg,#dbe8ff,#bcd3fa);border-bottom:2px solid #1d6fe0;color:#0d3f8f}
tbody td{padding:8px 6px;border-bottom:1px solid #eef1f6;white-space:nowrap;text-align:center}
#flowT tbody tr:nth-child(even){background:#eefaf3}
#gridT tbody tr:nth-child(even){background:#fff6ea}
tbody tr:hover{background:#dceaff}
tr.clickable{cursor:pointer}
.up{color:var(--red);font-weight:bold}.dn{color:var(--green);font-weight:bold}
.muted{color:#98a0ab;font-size:12.5px}
.mono{font-family:Consolas,monospace;font-size:12.5px}
.sline{font-size:13px;background:linear-gradient(180deg,#f7f9fc,#eef2f9);border:1px solid var(--line);
 border-radius:8px;padding:7px 12px;margin-bottom:8px}
.oktag,.badtag{font-size:11.5px;font-weight:700;padding:1px 8px;border-radius:12px}
.oktag{background:#e6f7ee;color:#0a7a44}.badtag{background:#fdecea;color:#c0392b}
.c-grid.float-monitor{position:fixed;left:50%;bottom:12px;transform:translateX(-50%);width:min(720px,66vw);z-index:60;
 max-height:76vh;display:flex;flex-direction:column;box-shadow:0 8px 28px rgba(40,60,100,.30)}
.c-grid.float-monitor h3{font-size:14px;justify-content:space-between;text-align:left;padding:4px 8px;cursor:move;user-select:none}
.c-grid.float-monitor .gw-body{overflow:auto;min-height:0;flex:1;display:flex;flex-direction:column}
.c-grid.float-monitor .gw-body .tw{flex:1;min-height:200px;max-height:none}
.c-grid.float-monitor.min .gw-body{display:none}
.c-grid.float-monitor .sline{font-size:12px;line-height:1.6}
.c-grid.float-monitor #gridT thead th{font-size:14px;padding:6px}
.c-grid.float-monitor #gridT tbody td{font-size:13px;padding:6px;white-space:normal}
.gmin{flex:0 0 auto;width:26px;height:22px;line-height:20px;padding:0;font-size:13px;border-radius:6px;cursor:pointer}
.foot{text-align:center;color:#98a0ab;font-size:12.5px;margin-top:16px}
)CSSCSS";
