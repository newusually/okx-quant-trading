// OKX 量化交易终端 (C++ Qt 6, 无Python)
// 功能: 分钟线K线(QPainter自绘,红涨绿跌) + 买卖点标记 + 止盈止损线
//       + 账户摘要 + AI策略记录 + 最新流水 + 托盘最小化
// 数据源: localhost/api.php (与Go引擎/PHP面板同一MySQL)
#include <QtWidgets>
#include <QFile>
#include <QHash>
#include <QClipboard>
#include <QDesktopServices>
#include <functional>
#include <algorithm>
#include <QtNetwork>
#include <cmath>

// api.php 的数字是 JSON 字符串(PHP json_encode MySQL 行), 统一转 double
static double jnum(const QJsonValue &v) {
    return v.isString() ? v.toString().toDouble() : v.toDouble();
}
static qint64 jnum64(const QJsonValue &v) {
    return (qint64)(v.isString() ? v.toString().toDouble() : v.toDouble());
}

// "2026-09-23 08:05:33" -> "09-23 08:05:33" (去掉年份, 日期时间完整显示)
static QString fmtNoYear(const QString &dt) {
    QDateTime t = QDateTime::fromString(dt, "yyyy-MM-dd HH:mm:ss");
    if (!t.isValid()) t = QDateTime::fromString(dt, "yyyy-M-d H:m:s");
    if (t.isValid()) return t.toString("MM-dd HH:mm:ss");
    return dt.length() > 10 ? dt.mid(5) : dt;
}

static const char *API = "http://127.0.0.1/api.php";
static const char *MAIN_INST = "ETH-USDT-SWAP";

// ================================================================ 手绘光标
// 2026-09-25 用户: Windows Server 无 Segoe UI Emoji 字体, 🪄/🤚 显示为黑色小方块 → 全部自己用 QPainter 画。
// 魔法棒: 棕色斜棒身 + 金色四芒星棒尖 + 星尘; 小手: 手掌+四指+拇指。热点在棒尖/掌心。
static void drawSparkStar(QPainter &p, double cx, double cy, double r, const QColor &c) {
    QPolygonF poly;
    poly << QPointF(cx, cy - r) << QPointF(cx + r * 0.28, cy - r * 0.28)
         << QPointF(cx + r, cy) << QPointF(cx + r * 0.28, cy + r * 0.28)
         << QPointF(cx, cy + r) << QPointF(cx - r * 0.28, cy + r * 0.28)
         << QPointF(cx - r, cy) << QPointF(cx - r * 0.28, cy - r * 0.28);
    p.setPen(Qt::NoPen); p.setBrush(c);
    p.drawPolygon(poly);
}
static QCursor makeWandCursor() {
    QPixmap pm(64, 64); pm.fill(Qt::transparent);
    QPainter p(&pm);
    p.setRenderHint(QPainter::Antialiasing);
    QPen stick(QColor(150, 96, 52), 7);            // 棕色棒身(对角线, 圆头)
    stick.setCapStyle(Qt::RoundCap);
    p.setPen(stick); p.drawLine(12, 52, 38, 26);
    QPen hl(QColor(216, 170, 118), 2);             // 棒身高光
    p.setPen(hl); p.drawLine(15, 48, 31, 32);
    drawSparkStar(p, 43, 21, 11, QColor(255, 214, 64));   // 金色棒尖四芒星
    drawSparkStar(p, 43, 21, 6, QColor(255, 255, 214));
    drawSparkStar(p, 54, 9, 5, QColor(255, 236, 140));    // 星尘
    drawSparkStar(p, 32, 8, 4, QColor(255, 236, 140));
    drawSparkStar(p, 20, 20, 3, QColor(255, 236, 140));
    p.end();
    return QCursor(pm, 8, 56);
}
static QCursor makeHandCursor() {
    QPixmap pm(64, 64); pm.fill(Qt::transparent);
    QPainter p(&pm);
    p.setRenderHint(QPainter::Antialiasing);
    QPen outline(QColor(190, 125, 72), 2);
    p.setPen(outline); p.setBrush(QColor(255, 213, 170));
    for (int i = 0; i < 4; ++i)                    // 四根手指
        p.drawRoundedRect(17 + i * 8, 10, 7, 20, 4, 4);
    p.drawRoundedRect(15, 22, 34, 30, 13, 13);     // 手掌
    p.drawRoundedRect(44, 30, 13, 9, 5, 5);        // 拇指
    p.end();
    return QCursor(pm, 30, 16);
}

// ================================================================ K线自绘图
class CandleChart : public QWidget {
public:
    struct Candle { qint64 t; double o, h, l, c; };
    // 信号标注(2026-09-24 深夜用户指令): kind 0=买入🚀 1=加仓🚀 2=平仓🍃(附盈利)
    struct Marker  { qint64 t; double px; int kind; double profit; QString strat; };
    struct Line    { double px; QColor col; QString label; };
    // K线信号(2026-09-26 三次精简: 只留td_nine九转, 1号指标triple_ma已删): 后台预计算存 kline_signals, 客户端只读库标注不自己算
    struct SigMark { qint64 t; int mask; };

    QVector<Candle> candles;
    QVector<Marker> markers;
    QVector<Line>   lines;
    QVector<SigMark> sigs;
    QString lastTitle;
    bool simple = false;   // 盈利K线精简模式: 不画MA/BOLL/图例/信号标注, 横轴带日期(用户: 盈利图别画指标)
    int viewCount = 100;   // 视口默认100根(2026-09-25 用户: 只显示最近100根, 原200, 缩小或往左拖才看更多)
    int viewEnd   = -1;    // 视口右端索引(-1=最新); 向左拖动变小看历史
    int dragStartX = 0, dragStartEnd = 0;
    bool dragging = false;
    bool loadingOld = false;               // 正在补拉更早历史(2026-09-26)
    std::function<void()> requestOlder;    // 往左拖触达最老K线时回调, 由外部补拉历史
    int hoverIdx = -1;     // 十字光标所在K线索引

    // 魔法棒星星特效(2026-09-24 22:09 用户指令)
    struct Spark { QPointF p; qint64 born; };
    QVector<Spark> sparkles;
    QTimer *sparkTimer = nullptr;

    void setCandles(const QVector<Candle> &c) {
        // 按时间戳合并: 新数据覆盖同时间戳旧K线(实时更新最后一根), 历史K线永不丢失
        bool atLatest = (viewEnd < 0 || viewEnd >= candles.size());
        QHash<qint64, int> idx;
        for (int i = 0; i < candles.size(); i++) idx[candles[i].t] = i;
        for (const auto &k : c) {
            auto it = idx.find(k.t);
            if (it != idx.end()) candles[it.value()] = k;
            else { candles.push_back(k); idx[k.t] = candles.size() - 1; }
        }
        std::sort(candles.begin(), candles.end(),
                  [](const Candle &a, const Candle &b) { return a.t < b.t; });
        if (atLatest || viewEnd < 0 || viewEnd > candles.size()) viewEnd = candles.size();
        update();
    }
    void resetHistory() { candles.clear(); viewEnd = -1; hoverIdx = -1; update(); }
    void setMarkers(const QVector<Marker> &m) { markers = m; update(); }
    void setLines(const QVector<Line> &l)     { lines = l; update(); }
    void setSigs(const QVector<SigMark> &s)   { sigs = s; update(); }

    CandleChart() {
        setMouseTracking(true);   // 不按键也收move事件(十字光标)
        // 2026-09-24 22:09 用户指令: K线图变魔法棒 —— 悬停魔法棒光标, 按左键变可爱小手, 点击处闪星星
        setCursor(wandCursor());
        sparkTimer = new QTimer(this);
        sparkTimer->setInterval(90);
        connect(sparkTimer, &QTimer::timeout, this, [this] {
            const qint64 now = QDateTime::currentMSecsSinceEpoch();
            sparkles.erase(std::remove_if(sparkles.begin(), sparkles.end(),
                              [now](const Spark &s) { return now - s.born > 1200; }), sparkles.end());
            if (sparkles.isEmpty()) sparkTimer->stop();
            update();
        });
    }

    // 魔法棒/小手光标: 2026-09-25 用户(emoji 在 Windows Server 显示为黑色方块) → 手绘 pixmap
    QCursor wandCursor() const { return makeWandCursor(); }
    QCursor handCursor() const { return makeHandCursor(); }

    // 由x坐标推算K线索索引(用于点击/移动显示OHLC)
    int idxAtX(int x) const {
        const int M_L = 8, M_R = 64;
        int end = viewEnd < 0 ? candles.size() : qBound(1, viewEnd, candles.size());
        int beg = qMax(0, end - viewCount);
        int n = end - beg;
        if (n <= 0) return -1;
        double cw = double(width() - M_L - M_R) / (n + 10);   // 左对齐: 右侧留10根K线宽空白(与paintEvent一致)
        int i = beg + int((x - M_L) / cw);
        return qBound(beg, i, end - 1);
    }

    void dbgLog(const QString &m) const {
        QFile f("E:/finally-main/desktop-cpp/_chart.log");
        if (f.open(QIODevice::Append)) f.write((QTime::currentTime().toString("HH:mm:ss ") + m + "\n").toUtf8());
    }
protected:
    void mousePressEvent(QMouseEvent *e) override {
        if (e->button() == Qt::LeftButton) {
            setCursor(handCursor());   // 按左键 → 可爱小手
            const qint64 now = QDateTime::currentMSecsSinceEpoch();
            const QPointF pos = e->position();
            auto *rng = QRandomGenerator::global();
            for (int i = 0; i < 3; ++i)   // 点击处闪3颗星星
                sparkles.push_back({pos + QPointF(rng->bounded(48) - 24, rng->bounded(48) - 24), now});
            sparkTimer->start();
            if (!candles.isEmpty()) {
                dragging = true; dragStartX = e->position().toPoint().x();
                dragStartEnd = viewEnd < 0 ? candles.size() : viewEnd;
            }
        }
        hoverIdx = idxAtX(int(e->position().x()));
        update();
    }
    void mouseMoveEvent(QMouseEvent *e) override {
        hoverIdx = idxAtX(int(e->position().x()));   // 点击/移动都显示当前K线信息
        if (!dragging || candles.isEmpty()) { update(); return; }
        const int M_L = 8, M_R = 64;
        // 实际视口根数(缩放后 != viewCount 时也正确)
        int end = viewEnd < 0 ? candles.size() : qBound(1, viewEnd, candles.size());
        int n = qMax(1, end - qMax(0, end - viewCount));
        double cw = double(width() - M_L - M_R) / (n + 10);   // 左对齐: 右侧留10根K线宽空白(与paintEvent一致)
        int shift = int((e->position().toPoint().x() - dragStartX) / cw);  // 2026-09-26 用户修复: 往左拖=看更早的K线(原方向反了,注释与代码不符)
        viewEnd = qBound(viewCount, dragStartEnd + shift, candles.size());
        // 2026-09-26: 翻到最老K线附近自动补拉更早历史
        int begNow = qMax(0, qBound(1, viewEnd, candles.size()) - viewCount);
        if (begNow <= 2 && !loadingOld && requestOlder) requestOlder();
        update();
    }
    void mouseReleaseEvent(QMouseEvent *e) override {
        dragging = false; hoverIdx = idxAtX(int(e->position().x()));
        setCursor(wandCursor());   // 松开 → 变回魔法棒
        update();
    }
    void leaveEvent(QEvent *) override { hoverIdx = -1; update(); }

    // 滚轮缩放K线: 上滚放大(根数变少) 下滚缩小(看到更多)。
    // 2026-09-25 01:09 用户修复: 缩放以【鼠标当前位置所指的K线】为锚点 ——
    // 缩放前后同一根K线始终停留在鼠标 x 位置(而不是视口右端/最新K线)。
    void wheelEvent(QWheelEvent *e) override {
        if (candles.isEmpty()) return;
        const int M_L = 8, M_R = 64;
        int end1 = viewEnd < 0 ? candles.size() : qBound(1, viewEnd, candles.size());
        int beg1 = qMax(0, end1 - viewCount);
        int n1 = qMax(1, end1 - beg1);
        double plotW = double(width() - M_L - M_R);
        double t = qBound(0.0, (e->position().x() - M_L) / plotW, 1.0);   // 鼠标在视口内的相对位置0~1
        double anchor = beg1 + t * (n1 + 10);   // 鼠标指向的K线索引(含右侧空白外插)

        int old = viewCount;
        if (e->angleDelta().y() > 0)
            viewCount = qMax(20, viewCount * 8 / 10);                       // 放大
        else
            viewCount = qMin(qMax(1000, candles.size()), viewCount * 14 / 10 + 2); // 缩小
        if (viewCount == old) return;

        // 锚点不变: 缩放后 anchor 这根K线仍落在鼠标 x 处 → 反解新的视口右端 viewEnd
        // i2 = beg2 + t*(n2+10), beg2=end2-viewCount, n2=viewCount → end2 = anchor + viewCount*(1-t) - 10*t
        int end2 = int(anchor + viewCount * (1.0 - t) - 10.0 * t + 0.5);
        viewEnd = qBound(viewCount, end2, candles.size());
        if (viewEnd >= candles.size()) viewEnd = candles.size();   // 右端=最新
        // 2026-09-26: 缩小到最老K线附近也自动补拉更早历史
        {
            int begNow = qMax(0, qBound(1, viewEnd, candles.size()) - viewCount);
            if (begNow <= 2 && !loadingOld && requestOlder) requestOlder();
        }
        update();
    }

    void paintEvent(QPaintEvent *) override {
        QPainter p(this);
        p.setRenderHint(QPainter::Antialiasing, true);   // 抗锯齿(用户: K线太难看)
        p.fillRect(rect(), QColor(255,255,255));
        if (candles.isEmpty()) {
            p.setPen(QColor(0x88,0x88,0x88));
            p.drawText(rect(), Qt::AlignCenter, QStringLiteral("等待K线数据..."));
            return;
        }
        const int M_L = 8, M_R = 64, M_T = 26, M_B = 22;
        QRect plot(M_L, M_T, width() - M_L - M_R, height() - M_T - M_B);

        // ---- 视口: 只取最近 viewCount 根 ----
        int end = viewEnd < 0 ? candles.size() : qBound(1, viewEnd, candles.size());
        int beg = qMax(0, end - viewCount);
        int n = end - beg;
        if (n <= 0 || beg >= candles.size() || end - 1 >= candles.size()) {
            viewEnd = candles.size();          // 视口异常自愈: 回到最新
            end = candles.size();
            beg = qMax(0, end - viewCount);
            n = end - beg;
            if (n <= 0) return;
        }
        const Candle &cBeg = candles[beg];
        const Candle &cEnd = candles[end - 1];

        // Y轴只按可见K线范围自适应(不含全量历史)
        double lo = 1e18, hi = -1e18;
        for (int i = beg; i < end; i++) { lo = qMin(lo, candles[i].l); hi = qMax(hi, candles[i].h); }
        if (hi - lo < 1e-12) hi = lo + 1;
        // 止盈止损线仅在K线范围±1倍内参与定轴, 更远的直接裁掉不撑大Y轴
        double span = hi - lo;
        for (const auto &l : lines)
            if (l.px >= lo - span && l.px <= hi + span) { lo = qMin(lo, l.px); hi = qMax(hi, l.px); }
        // 信号标注不参与定轴(2026-09-24 用户指令: 别拉高或者拉低K线图)
        if (hi - lo < 1e-12) hi = lo + 1;
        double pad = (hi - lo) * 0.05; lo -= pad; hi += pad;
        auto yOf = [&](double v) { return plot.bottom() - (v - lo) / (hi - lo) * plot.height(); };
        double cw = double(plot.width()) / (n + 10);   // 左对齐(用户指令): K线整体往左挪10根宽度, 右侧留白不顶边界
        auto xOf = [&](int i) { return plot.left() + cw * (i - beg + 0.5); };

        // ---- 指标序列: MA5/MA10/MA20 + 布林带(20,2) (全量计算, 只画视口; 盈利K线精简模式不画) ----
        QVector<double> ma5, ma10, ma20, bUp, bLo;
        if (!simple) {
        auto maSeries = [&](int period) {
            QVector<double> out(candles.size(), qQNaN());
            for (int i = period - 1; i < candles.size(); i++) {
                double s = 0;
                for (int j = i - period + 1; j <= i; j++) s += candles[j].c;
                out[i] = s / period;
            }
            return out;
        };
        ma5 = maSeries(5); ma10 = maSeries(10); ma20 = maSeries(20);
        bUp = QVector<double>(candles.size(), qQNaN()); bLo = QVector<double>(candles.size(), qQNaN());
        for (int i = 19; i < candles.size(); i++) {
            double mean = ma20[i], var = 0;
            for (int j = i - 19; j <= i; j++) var += (candles[j].c - mean) * (candles[j].c - mean);
            double sd = std::sqrt(var / 20.0);
            bUp[i] = mean + 2 * sd; bLo[i] = mean - 2 * sd;
        }
        }   // end if(!simple) 指标计算
        auto drawSeries = [&](const QVector<double> &v, const QColor &col, const QPen &pen) {
            QPen pp = pen; pp.setColor(col); p.setPen(pp);
            bool started = false;
            for (int i = beg; i < end; i++) {
                if (qIsNaN(v[i])) { started = false; continue; }
                if (!started) { p.drawPoint(QPointF(xOf(i), yOf(v[i]))); started = true; continue; }
                p.drawLine(QPointF(xOf(i - 1), yOf(v[i - 1])), QPointF(xOf(i), yOf(v[i])));
            }
        };

        // 布林带区域(半透明底色)先画, 垫在K线下面 (盈利K线精简模式不画)
        if (!simple) {
        QPainterPath band;
        bool started = false;
        for (int i = beg; i < end; i++) {
            if (qIsNaN(bUp[i])) continue;
            if (!started) { band.moveTo(xOf(i), yOf(bUp[i])); started = true; }
            else band.lineTo(xOf(i), yOf(bUp[i]));
        }
        for (int i = end - 1; i >= beg; i--) {
            if (qIsNaN(bLo[i])) continue;
            band.lineTo(xOf(i), yOf(bLo[i]));
        }
        p.setPen(Qt::NoPen);
        p.setBrush(QColor(0x19,0x71,0xc2,22));
        p.drawPath(band);
        }   // end if(!simple) 布林带

        // 网格 + 价格轴
        p.setFont(QFont("Microsoft YaHei", 7));
        for (int g = 0; g <= 5; g++) {
            double v = lo + (hi - lo) * g / 5.0;
            int y = int(yOf(v));
            p.setPen(QPen(QColor(0xee,0xee,0xee), 1));
            p.drawLine(plot.left(), y, plot.right(), y);
            p.setPen(QColor(0x66,0x66,0x66));
            p.drawText(width() - M_R + 4, y + 4, formatPx(v));
        }
        // 时间轴(每 n/6 根; 跨天处标注 MM-DD 日期, 用户: 20-22日都是一条线 今天没显示 -> 加日期)
        int step = qMax(1, n / 6);
        QDate prevDay;
        for (int i = 0; i < n; i += step) {
            QDateTime dt = QDateTime::fromMSecsSinceEpoch(candles[beg + i].t);
            QString lab = dt.toString("HH:mm");
            if (dt.date() != prevDay) { lab = dt.toString("MM-dd HH:mm"); prevDay = dt.date(); }
            p.setPen(QColor(0x66,0x66,0x66));
            p.drawText(int(xOf(beg + i)) - 40, height() - 6, lab);
        }
        // 标题
        p.setPen(QColor(0x22,0x22,0x22));
        p.setFont(QFont("Microsoft YaHei", 9, QFont::Bold));
        p.drawText(plot.left(), 17, lastTitle);

        // K线: 红涨绿跌(中国习惯) — 只画视口内
        for (int i = beg; i < end; i++) {
            const auto &k = candles[i];
            bool up = k.c >= k.o;
            QColor col = up ? QColor(0xe0,0x31,0x31) : QColor(0x0c,0xa6,0x78);
            double x = xOf(i);
            double bodyTop = yOf(qMax(k.o, k.c)), bodyBot = yOf(qMin(k.o, k.c));
            double bw = qMax(1.5, cw * 0.62);
            p.setPen(col);
            p.setBrush(col);
            // 影线
            p.drawLine(QPointF(x, yOf(k.h)), QPointF(x, yOf(k.l)));
            // 实体(十字星画横线)
            if (bodyBot - bodyTop < 1)
                p.drawLine(QPointF(x - bw / 2, bodyTop), QPointF(x + bw / 2, bodyTop));
            else
                p.drawRect(QRectF(x - bw / 2, bodyTop, bw, bodyBot - bodyTop));
        }
        // 指标线: 布林上下轨 / MA5 / MA10 / MA20 (盈利K线精简模式不画)
        if (!simple) {
        drawSeries(bUp, QColor(0xf7,0x67,0x00), QPen(Qt::SolidLine));
        drawSeries(bLo, QColor(0xf7,0x67,0x00), QPen(Qt::SolidLine));
        drawSeries(ma5,  QColor(0xf1,0xc4,0x0c), QPen(Qt::SolidLine));
        drawSeries(ma10, QColor(0x0c,0xa6,0x78), QPen(Qt::SolidLine));
        drawSeries(ma20, QColor(0xae,0x3e,0xc9), QPen(Qt::SolidLine));
        // 指标图例(标题右侧)
        p.setFont(QFont("Microsoft YaHei", 7));
        int lx = plot.right() - 250;
        p.setPen(QColor(0xf1,0xc4,0x0c)); p.drawText(lx, 17, "MA5");
        p.setPen(QColor(0x0c,0xa6,0x78)); p.drawText(lx + 32, 17, "MA10");
        p.setPen(QColor(0xae,0x3e,0xc9)); p.drawText(lx + 68, 17, "MA20");
        p.setPen(QColor(0xf7,0x67,0x00)); p.drawText(lx + 108, 17, "BOLL(20,2)");
        }   // end if(!simple) 指标线+图例
        // 止盈/止损线
        for (const auto &l : lines) {
            int y = int(yOf(l.px));
            p.setPen(Qt::DashLine);
            p.setPen(l.col);
            p.drawLine(plot.left(), y, plot.right(), y);
            p.setPen(l.col);
            p.drawText(plot.left() + 4, y - 3, l.label);
        }
        // 信号标注(2026-09-24 深夜用户指令): 买入🚀/加仓🚀 在K线下方不远处(带时间HH:MM, 不拉高拉低K线图),
        // 平仓 🍃 绿叶 + 盈利两位小数 在K线上方
        p.setFont(QFont("Microsoft YaHei", 7));
        for (const auto &m : markers) {
            int ci = -1;
            for (int i = beg; i < end; i++)
                if (candles[i].t <= m.t) ci = i;   // 标注时刻所在(或之前最近)的K线
            if (ci < 0) continue;
            double x = xOf(ci);
            QString tm = QDateTime::fromMSecsSinceEpoch(m.t).toString("HH:mm");
            if (m.kind == 2) { // 平仓: 🍃 + 盈利两位小数
                double yTop = yOf(candles[ci].h) - 8;
                yTop = qMax(plot.top() + 34.0, yTop);
                p.setPen(QColor(0x0c, 0xa6, 0x78));
                p.drawText(QPointF(x - 7, yTop - 7), QStringLiteral("🍃"));
                QString pf = (m.profit >= 0 ? QStringLiteral("+") : QString()) + QString::number(m.profit, 'f', 2);
                p.drawText(QPointF(x - 16, yTop + 5), tm + ' ' + pf);
            } else {           // 买入/加仓: 🚀 + 时间
                double yBot = yOf(candles[ci].l) + 6;
                yBot = qMin(plot.bottom() - 28.0, yBot);
                p.setPen(QColor(0xe0, 0x31, 0x31));
                p.drawText(QPointF(x - 7, yBot + 11), QStringLiteral("🚀"));
                p.drawText(QPointF(x - 16, yBot + 23),
                           (m.kind == 0
                                ? QStringLiteral("买") + (m.strat.isEmpty() ? QString() : QStringLiteral(" ") + m.strat) + QStringLiteral(" ")
                                : QStringLiteral("加 ")) + tm);
            }
        }

    // K线信号标注(2026-09-26 三次精简: 删除1号指标triple_ma_bull, 只留td_nine九转): K线下方远距
    // 九5~九9计数标注(紫), 九9底=红(止跌预警)/九9顶=绿(滞涨预警); 虚线连到信号柱。后台算好存库, 客户端只读。
    if (!simple && !sigs.isEmpty()) {
        QHash<qint64, int> sidx;
        for (const auto &s : sigs) sidx[s.t] = s.mask;
        p.setFont(QFont("Microsoft YaHei", 7, QFont::Bold));
        const double laneY[2] = { plot.bottom() - 5.0, plot.bottom() - 15.0 };   // 两车道贴底, 离K线柱远
        double laneX[2] = { -1e9, -1e9 };
        QPen dashPen(QColor(0xb1, 0x97, 0xfc), 1, Qt::DashLine);
        for (int i = beg; i < end; i++) {
            auto it = sidx.find(candles[i].t);
            if (it == sidx.end() || it.value() == 0) continue;
            const int m = it.value();
            // 标准神奇九转: 位2~5=计数5~9, 位6=顶序列, 位7=底序列
            const int tdPh = (m >> 2) & 15;
            QString label;
            if ((m & 2) && tdPh >= 5) label = QStringLiteral("九") + QString::number(tdPh);
            if (label.isEmpty()) continue;
            double x = xOf(i);
            if (x < plot.left() + 6 || x > plot.right() - 6) continue;
            int lane = (x - laneX[0] >= 16.0) ? 0 :
                       ((x - laneX[1] >= 16.0) ? 1 : -1);   // 两车道都挤则只画虚线不画字
            double ly = lane >= 0 ? laneY[lane] : laneY[0];
            p.setPen(dashPen);
            p.drawLine(QPointF(x, yOf(candles[i].l) + 3), QPointF(x, ly - 4));
            if (lane >= 0) {
                // 颜色: 九9底=红(止跌预警) · 九9顶=绿(滞涨预警) · 其余=紫
                QColor col(0x70, 0x48, 0xe8);
                if (tdPh == 9) col = (m & 128) ? QColor(0xe0, 0x31, 0x31) : QColor(0x0c, 0xa6, 0x78);
                p.setPen(col);
                p.drawText(QPointF(x - 14, ly), label);
                laneX[lane] = x;
            }
        }
    }

        // ---- 十字光标: 点击/移动显示当前K线 时间+开高低收+涨跌幅 ----
        if (hoverIdx >= beg && hoverIdx < end) {
            const Candle &k = candles[hoverIdx];
            double x = xOf(hoverIdx);
            p.setPen(QPen(QColor(0x88,0x88,0x88), 1, Qt::DashLine));
            p.drawLine(QPointF(x, plot.top()), QPointF(x, plot.bottom()));
            double chg = k.o > 0 ? (k.c - k.o) / k.o * 100 : 0;
            QString info = QStringLiteral("%1   开:%2  高:%3  低:%4  收:%5  %6%7%")
                               .arg(QDateTime::fromMSecsSinceEpoch(k.t).toString("yyyy-MM-dd HH:mm"),
                                    formatPx(k.o), formatPx(k.h), formatPx(k.l), formatPx(k.c),
                                    chg >= 0 ? QStringLiteral("+") : QStringLiteral(""),
                                    QString::number(chg, 'f', 2));
            QFont bf = p.font();
            p.setFont(QFont("Microsoft YaHei", 8, QFont::Bold));
            QFontMetrics fm(p.font());
            int tw = fm.horizontalAdvance(info) + 20;
            QRect box(plot.left() + 2, M_T + 2, tw, 22);
            p.setPen(QColor(0x33,0x33,0x33));
            p.setBrush(QColor(255,255,255,235));
            p.drawRect(box);
            p.setPen(chg >= 0 ? QColor(0xe0,0x31,0x31) : QColor(0x0c,0xa6,0x78));
            p.drawText(box.adjusted(10, 0, -10, 0), Qt::AlignVCenter | Qt::AlignLeft, info);
            p.setFont(bf);
        }

        // ---- 魔法棒星星: 点击处✨/⭐ 1.2秒渐隐闪烁(2026-09-24 22:09) ----
        if (!sparkles.isEmpty()) {
            const qint64 now = QDateTime::currentMSecsSinceEpoch();
            p.setFont(QFont(QStringLiteral("Segoe UI Emoji"), 26));   // 星星也放大一倍
            for (const auto &s : sparkles) {
                double age = double(now - s.born) / 1200.0;
                if (age < 0 || age > 1) continue;
                p.setOpacity(age < .5 ? .4 + age : 1.0 - (age - .5) * 1.6);
                p.drawText(s.p, age < .5 ? QStringLiteral("✨") : QStringLiteral("⭐"));
            }
            p.setOpacity(1.0);
        }
    }
    QString formatPx(double v) const {
        if (simple) return QString::number(v, 'f', 2);   // 盈利K线: 累计USDT 两位小数
        if (v >= 100) return QString::number(v, 'f', 1);
        if (v >= 1)   return QString::number(v, 'f', 3);
        return QString::number(v, 'f', 6);
    }
};

// 自绘K线风格图标(深蓝底+红绿蜡烛), 主窗口/托盘/悬浮窗共用
static QPixmap makeCandleIcon(int size) {
    QPixmap pm(size, size);
    pm.fill(QColor(0x1e,0x2a,0x3a));
    QPainter ip(&pm);
    ip.setRenderHint(QPainter::Antialiasing);
    double s = size / 256.0;   // 按256设计稿缩放
    ip.setPen(Qt::NoPen);
    ip.setBrush(QColor(0xe0,0x31,0x31));
    ip.drawRect(QRectF(50*s, 70*s, 36*s, 90*s));  ip.drawLine(QPointF(68*s,40*s), QPointF(68*s,190*s));
    ip.setBrush(QColor(0x0c,0xa6,0x78));
    ip.drawRect(QRectF(120*s, 110*s, 36*s, 70*s)); ip.drawLine(QPointF(138*s,85*s), QPointF(138*s,210*s));
    ip.setBrush(QColor(0xe0,0x31,0x31));
    ip.drawRect(QRectF(178*s, 55*s, 36*s, 100*s)); ip.drawLine(QPointF(196*s,30*s), QPointF(196*s,175*s));
    ip.end();
    return pm;
}

// ================================================================ 3D图表(自绘等轴投影, 无需QtCharts)
struct K3D { qint64 t; double o, h, l, c; };

class Chart3D : public QWidget {
public:
    enum Mode { Candles, Bars, Pie };
    Mode mode;
    QVector<K3D> candles;                        // Candles: 盈利K线(累计USDT)
    QVector<QPair<QString,double>> bars;         // Bars: 小时盈亏(红涨绿跌)
    QVector<QPair<QString,double>> slices;       // Pie: 占比构成
    QString title;

    Chart3D(Mode m, QWidget *p = nullptr) : QWidget(p), mode(m) { setMinimumHeight(230); }
    void setCandles(const QVector<K3D> &c) { candles = c; update(); }
    void setBars(const QVector<QPair<QString,double>> &b) { bars = b; update(); }
    void setPie(const QVector<QPair<QString,double>> &s) { slices = s; update(); }

protected:
    void drawEmpty(QPainter &p) {
        p.setPen(QColor(0x99,0x99,0x99));
        p.setFont(QFont("Microsoft YaHei", 9));
        p.drawText(rect(), Qt::AlignCenter, QStringLiteral("数据加载中..."));
    }
    void paintEvent(QPaintEvent *) override {
        QPainter p(this);
        p.setRenderHint(QPainter::Antialiasing);
        // 白底圆角卡片 + 细边(与网页端卡片同风格)
        p.setPen(QColor(0xe0,0xe5,0xef));
        p.setBrush(QColor(0xff,0xff,0xff));
        p.drawRoundedRect(rect().adjusted(1,1,-2,-2), 10, 10);
        p.setClipRect(rect().adjusted(2,2,-2,-2));
        if (mode == Candles) paintCandles(p);
        else if (mode == Bars) paintBars(p);
        else paintPie(p);
    }

    // ---- 3D 盈利K线: 等轴投影(背面体+顶面+正面金属渐变), 红涨绿跌 ----
    void paintCandles(QPainter &p) {
        const int M = 40, MT = 28;
        QRect plot(M, MT, width() - M - 12, height() - MT - M);
        p.setPen(QColor(0x22,0x22,0x22)); p.setFont(QFont("Microsoft YaHei", 9, QFont::Bold));
        p.drawText(plot.left(), 18, title);
        if (candles.isEmpty()) { drawEmpty(p); return; }
        int n = qMin(60, candles.size());
        int beg = candles.size() - n;
        double lo = 1e18, hi = -1e18;
        for (int i = beg; i < candles.size(); ++i) { lo = qMin(lo, candles[i].l); hi = qMax(hi, candles[i].h); }
        if (hi - lo < 1e-9) hi = lo + 1;
        double pad = (hi - lo) * 0.08; lo -= pad; hi += pad;
        auto yOf = [&](double v) { return plot.bottom() - (v - lo) / (hi - lo) * plot.height(); };
        double cw = double(plot.width()) / (n + 10);   // 左对齐(用户指令): K线整体往左挪10根宽度, 右侧留白不顶边界
        p.setFont(QFont("Microsoft YaHei", 7));
        for (int g = 0; g <= 4; ++g) {
            double v = lo + (hi - lo) * g / 4;
            int y = int(yOf(v));
            p.setPen(QPen(QColor(0xee,0xee,0xee), 1)); p.drawLine(plot.left(), y, plot.right(), y);
            p.setPen(QColor(0x99,0x99,0x99));
            p.drawText(plot.right() + 2, y + 4, QString::number(v, 'f', 1));
        }
        const double ddx = 6, ddy = -4;   // 等轴深度向量
        for (int i = beg; i < candles.size(); ++i) {
            const auto &k = candles[i];
            bool up = k.c >= k.o;
            QColor col = up ? QColor(0xe0,0x31,0x31) : QColor(0x0c,0xa6,0x78);
            QColor dark = col.darker(135);
            double x = plot.left() + cw * (i - beg + 0.5);
            double yT = yOf(qMax(k.o, k.c)), yB = yOf(qMin(k.o, k.c));
            double bw = qMax(2.0, cw * 0.55);
            if (yB - yT < 2) { yT -= 1; yB = yT + 2; }
            p.setPen(Qt::NoPen);
            // 背面体
            p.setBrush(dark);
            p.drawRect(QRectF(x - bw/2 + ddx, yT + ddy, bw, yB - yT));
            // 顶面(平行四边形)
            QPainterPath top;
            top.moveTo(x - bw/2, yT); top.lineTo(x + bw/2, yT);
            top.lineTo(x + bw/2 + ddx, yT + ddy); top.lineTo(x - bw/2 + ddx, yT + ddy);
            p.drawPath(top);
            // 影线 前+后
            p.setPen(QPen(dark, 1));
            p.drawLine(QPointF(x + ddx, yOf(k.h) + ddy), QPointF(x + ddx, yOf(k.l) + ddy));
            p.setPen(QPen(col, 1));
            p.drawLine(QPointF(x, yOf(k.h)), QPointF(x, yOf(k.l)));
            // 正面: 金属渐变(凹凸有致)
            QLinearGradient g(x - bw/2, yT, x + bw/2, yB);
            g.setColorAt(0, col.lighter(135)); g.setColorAt(0.5, col); g.setColorAt(1, dark);
            p.setBrush(QBrush(g));
            p.setPen(QPen(col.darker(110), 1));
            p.drawRect(QRectF(x - bw/2, yT, bw, yB - yT));
        }
    }

    // ---- 3D 柱状图: 顶面+侧面+正面渐变, 红涨绿跌 ----
    void paintBars(QPainter &p) {
        const int M = 12, MB = 30;
        QRect plot(M, 30, width() - M - 12, height() - 30 - MB);
        p.setPen(QColor(0x22,0x22,0x22)); p.setFont(QFont("Microsoft YaHei", 9, QFont::Bold));
        p.drawText(plot.left(), 18, title);
        if (bars.isEmpty()) { drawEmpty(p); return; }
        double hi = 1e-9;
        for (auto &b : bars) hi = qMax(hi, qAbs(b.second));
        if (hi < 1e-9) hi = 1;
        auto yOf = [&](double v) { return plot.center().y() - v / hi * plot.height() / 2; };
        double bw = qMax(3.0, double(plot.width()) / bars.size() * 0.55);
        double step = double(plot.width()) / bars.size();
        p.setFont(QFont("Microsoft YaHei", 7));
        p.setPen(QPen(QColor(0xee,0xee,0xee), 1));
        p.drawLine(plot.left(), int(yOf(0)), plot.right(), int(yOf(0)));
        const double ddx = 5, ddy = -4;
        int lb = qMax(1, bars.size() / 6);
        for (int i = 0; i < bars.size(); ++i) {
            double v = bars[i].second;
            bool up = v >= 0;
            QColor col = up ? QColor(0xe0,0x31,0x31) : QColor(0x0c,0xa6,0x78);
            QColor dark = col.darker(135);
            double x = plot.left() + step * (i + 0.5);
            double yT = yOf(qMax(0.0, v)), yB = yOf(qMin(0.0, v));
            p.setPen(Qt::NoPen);
            // 顶面(仅正值) / 底面(负值)
            QPainterPath cap;
            cap.moveTo(x - bw/2, yT); cap.lineTo(x + bw/2, yT);
            cap.lineTo(x + bw/2 + ddx, yT + ddy); cap.lineTo(x - bw/2 + ddx, yT + ddy);
            p.setBrush(dark.lighter(110)); p.drawPath(cap);
            // 侧面
            QPainterPath side;
            side.moveTo(x + bw/2, yT); side.lineTo(x + bw/2 + ddx, yT + ddy);
            side.lineTo(x + bw/2 + ddx, yB + ddy); side.lineTo(x + bw/2, yB);
            p.setBrush(dark); p.drawPath(side);
            // 正面渐变
            QLinearGradient g(x - bw/2, yT, x + bw/2, yB);
            g.setColorAt(0, col.lighter(130)); g.setColorAt(1, col);
            p.setBrush(QBrush(g));
            p.setPen(QPen(col.darker(110), 1));
            p.drawRect(QRectF(x - bw/2, yT, bw, yB - yT));
            if (i % lb == 0) {
                p.setPen(QColor(0x99,0x99,0x99));
                p.drawText(QRectF(plot.left() + step*i, plot.bottom() + 4, step, 22),
                           Qt::AlignHCenter | Qt::AlignTop, bars[i].first);
            }
        }
    }

    // ---- 3D 饼图: 顶面椭圆扇区 + 下半圈侧壁(厚度感) ----
    void paintPie(QPainter &p) {
        double total = 0;
        for (auto &s : slices) total += qMax(0.0, s.second);
        if (slices.isEmpty() || total <= 0) { drawEmpty(p); return; }
        static const QColor pal[] = {
            QColor(0xe0,0x31,0x31), QColor(0x0c,0xa6,0x78), QColor(0x2f,0x6f,0xed),
            QColor(0xf1,0xc4,0x0c), QColor(0xae,0x3e,0xc9), QColor(0xf7,0x67,0x00)};
        int cx = int(width() * 0.34), cy = int(height() * 0.52);
        int rx = int(qMin(width() * 0.30, height() * 0.30));
        int ry = int(rx * 0.58);
        int d = qMax(10, height() / 16);       // 3D 厚度
        QRectF ell(cx - rx, cy - ry, rx * 2.0, ry * 2.0);
        // 侧壁: 下半圈(Qt角度180..360)逐度画竖条, 颜色按扇区归属加深
        for (int a = 180; a <= 360; a += 2) {
            double f = ((90 - a + 720) % 360) / 360.0;   // 顺时针累计占比
            double acc = 0; int si = 0;
            for (int i = 0; i < slices.size(); ++i) {
                acc += qMax(0.0, slices[i].second) / total;
                if (f <= acc + 1e-9) { si = i; break; }
            }
            QColor c = pal[si % 6].darker(130);
            double t = a * 3.14159265358979 / 180;
            double ex = cx + rx * std::cos(t), ey = cy - ry * std::sin(t);
            p.setPen(QPen(c, 2));
            p.drawLine(QPointF(ex, ey), QPointF(ex, ey + d));
        }
        // 顶面扇区(渐变高光)
        double start = 90 * 16;
        for (int i = 0; i < slices.size(); ++i) {
            double frac = qMax(0.0, slices[i].second) / total;
            if (frac <= 0) continue;
            int span = int(-frac * 360 * 16);   // 负=顺时针
            QColor col = pal[i % 6];
            QLinearGradient g(cx - rx, cy - ry, cx + rx, cy + ry);
            g.setColorAt(0, col.lighter(125)); g.setColorAt(1, col);
            p.setBrush(QBrush(g));
            p.setPen(QPen(col.darker(115), 1));
            p.drawPie(ell, int(start), span);
            start += span;
        }
        // 图例(右侧): 色块+名称+占比
        p.setFont(QFont("Microsoft YaHei", 9));
        int ly = cy - slices.size() * 13;
        for (int i = 0; i < slices.size(); ++i) {
            double frac = qMax(0.0, slices[i].second) / total;
            p.setPen(Qt::NoPen);
            p.setBrush(pal[i % 6]);
            p.drawRect(cx + rx + 16, ly + i * 26 - 4, 12, 12);
            p.setPen(QColor(0x33,0x33,0x33));
            p.drawText(cx + rx + 34, ly + i * 26 + 6,
                       QStringLiteral("%1  %2  (%3%)").arg(slices[i].first,
                       QString::number(slices[i].second, 'f', 0),
                       QString::number(frac * 100, 'f', 1)));
        }
    }
};

// ================================================================ 悬浮窗(置顶小窗)
// emoji光标公共函数(悬浮窗用, 与K线图同款) —— 2026-09-25 改手绘(Windows Server 无 emoji 字体)
static QCursor makeEmojiCursor(const QString &emoji, int hotX, int hotY) {
    Q_UNUSED(emoji);
    return emoji.contains(QChar(0x1FA84)) ? makeWandCursor() : makeHandCursor();
}

class FloatWin : public QWidget {
public:
    QLabel *pxLab, *pnlLab, *posLab;
    QPoint dragP;
    bool dragging = false;
    FloatWin() {
        setWindowFlags(Qt::FramelessWindowHint | Qt::WindowStaysOnTopHint | Qt::Tool);
        setWindowIcon(QIcon(makeCandleIcon(64)));
        setWindowTitle(QStringLiteral("OKX悬浮窗"));
        resize(236, 124);
        auto *row = new QHBoxLayout;
        row->setSpacing(8);
        auto *icLab = new QLabel;
        icLab->setPixmap(makeCandleIcon(64).scaled(34, 34, Qt::KeepAspectRatio, Qt::SmoothTransformation));
        icLab->setFixedSize(34, 34);
        row->addWidget(icLab);
        pxLab = new QLabel(QStringLiteral("加载中..."));
        pxLab->setStyleSheet("color:#ffd9a0; font-family:'LiSu'; font-size:14pt; font-weight:bold;"); // 隶书四号(用户22:30)
        row->addWidget(pxLab, 1);
        pnlLab = new QLabel(QStringLiteral("总盈亏: -"));
        pnlLab->setAlignment(Qt::AlignCenter);
        pnlLab->setStyleSheet("color:#ff8787; font-family:'LiSu'; font-size:14pt;");
        posLab = new QLabel(QStringLiteral("仓位: - USDT"));   // 2026-09-25 用户: 悬浮窗多加一行仓位
        posLab->setAlignment(Qt::AlignCenter);
        posLab->setStyleSheet("color:#ffd9a0; font-family:'LiSu'; font-size:12pt;");
        auto *lay = new QVBoxLayout(this);
        lay->setContentsMargins(12, 8, 12, 8);
        lay->setSpacing(2);
        lay->addLayout(row);
        lay->addWidget(pnlLab);
        lay->addWidget(posLab);
        // 暗色金属底(用户22:30: 背景不能白色) + 魔法棒光标/按下变小手
        setStyleSheet("QWidget#floatHost { background: qlineargradient(x1:0,y1:0,x2:0,y2:1,"
                      " stop:0 #434d66, stop:0.45 #2e3648, stop:0.55 #262d3d, stop:1 #1e2432);"
                      " border:1px solid #5a6478; border-radius:10px; }");
        setObjectName("floatHost");
        setCursor(makeEmojiCursor(QStringLiteral("🪄"), 8, 52));
        setToolTip(QStringLiteral("悬浮窗: 拖动移动 | 双击打开主界面"));
    }
    void setPx(const QString &inst, double px) {
        pxLab->setText(QStringLiteral("%1  %2").arg(inst.section('-', 0, 0),
                                                    QString::number(px, 'f', px >= 100 ? 1 : 4)));
    }
    void setPnl(double net) {
        pnlLab->setText(QStringLiteral("总盈亏: %1%2 USDT").arg(net >= 0 ? "+" : "-",
                              QString::number(std::abs(net), 'f', 2)));
        pnlLab->setStyleSheet(net >= 0 ? "color:#ff8787; font-family:'LiSu'; font-size:14pt;"
                                       : "color:#69db7c; font-family:'LiSu'; font-size:14pt;");
    }
    void setPos(double m) {   // 2026-09-25 用户: 悬浮窗「仓位」行(当前持仓占用保证金USDT)
        posLab->setText(m > 0 ? QStringLiteral("仓位: %1 USDT").arg(QString::number(m, 'f', 2))
                              : QStringLiteral("仓位: 0 USDT(无持仓)"));
    }
protected:
    void mousePressEvent(QMouseEvent *e) override {
        dragging = true; dragP = e->globalPosition().toPoint() - frameGeometry().topLeft();
        setCursor(makeEmojiCursor(QStringLiteral("🤚"), 28, 12));   // 按下变可爱小手(用户22:30)
    }
    void mouseMoveEvent(QMouseEvent *e) override {
        if (dragging) move(e->globalPosition().toPoint() - dragP);
    }
    void mouseReleaseEvent(QMouseEvent *) override {
        dragging = false;
        setCursor(makeEmojiCursor(QStringLiteral("🪄"), 8, 52));    // 松开回魔法棒
    }
    void mouseDoubleClickEvent(QMouseEvent *) override { if (onDouble) onDouble(); }
public:
    std::function<void()> onDouble;
};

// ================================================================ AI报表中心弹窗(日报/调参/反思 + 3D图表, 应用内直接看)
class ReportCenter : public QDialog {
public:
    Chart3D *c3d, *b3d, *p3d;
    QTextBrowser *repView, *tuneView, *reflView;
    QNetworkAccessManager *nam;

    ReportCenter(QWidget *parent = nullptr) : QDialog(parent) {
        setWindowTitle(QStringLiteral("AI 报表中心 · ETH模式 · 日报 / 调参 / 反思 + 3D图表"));
        resize(1480, 920);
        auto *lay = new QVBoxLayout(this);
        auto *tabs = new QTabWidget(this);

        // ---- 日报页: 3D盈利K线 + 3D小时盈亏柱 + 3D盈亏构成饼 + 彩色HTML日报 ----
        auto *repTab = new QWidget;
        auto *rl = new QVBoxLayout(repTab);
        rl->setSpacing(6);
        auto *charts = new QHBoxLayout;
        c3d = new Chart3D(Chart3D::Candles);
        c3d->title = QStringLiteral("3D 盈利K线(累计USDT·1小时)");
        b3d = new Chart3D(Chart3D::Bars);
        b3d->title = QStringLiteral("3D 小时盈亏(U) 红涨绿跌");
        p3d = new Chart3D(Chart3D::Pie);
        p3d->title = QStringLiteral("3D 盈亏构成");
        for (auto *c : {c3d, b3d, p3d}) c->setFixedHeight(255);
        charts->addWidget(c3d, 3); charts->addWidget(b3d, 2); charts->addWidget(p3d, 2);
        rl->addLayout(charts);
        repView = new QTextBrowser;
        rl->addWidget(repView, 1);
        tabs->addTab(repTab, QStringLiteral("📘 AI日报"));
        tuneView = new QTextBrowser;
        tabs->addTab(tuneView, QStringLiteral("⚙️ AI调参"));
        reflView = new QTextBrowser;
        tabs->addTab(reflView, QStringLiteral("🪞 AI反思"));
        lay->addWidget(tabs, 1);

        // ---- 底部按钮(立体渐变): 刷新 + 应用内打开三份Excel ----
        auto *row = new QHBoxLayout;
        auto *refreshBtn = new QPushButton(QStringLiteral("🔄 刷新数据"));
        connect(refreshBtn, &QPushButton::clicked, this, &ReportCenter::load);
        auto *x1 = new QPushButton(QStringLiteral("📦 日报Excel"));
        connect(x1, &QPushButton::clicked, this, [] {
            QDesktopServices::openUrl(QUrl::fromLocalFile(QStringLiteral("E:/finally-main/web/ai_report.xlsx")));
        });
        auto *x2 = new QPushButton(QStringLiteral("📦 调参Excel"));
        connect(x2, &QPushButton::clicked, this, [] {
            QDesktopServices::openUrl(QUrl::fromLocalFile(QStringLiteral("E:/finally-main/web/ai_tune.xlsx")));
        });
        auto *x3 = new QPushButton(QStringLiteral("📦 反思Excel"));
        connect(x3, &QPushButton::clicked, this, [] {
            QDesktopServices::openUrl(QUrl::fromLocalFile(QStringLiteral("E:/finally-main/web/ai_reflect.xlsx")));
        });
        row->addWidget(refreshBtn);
        row->addWidget(x1); row->addWidget(x2); row->addWidget(x3);
        row->addStretch();
        lay->addLayout(row);

        nam = new QNetworkAccessManager(this);
        nam->setProxy(QNetworkProxy(QNetworkProxy::NoProxy));
        load();
    }

private:
    // 简易表格行 -> 弹窗顶部摘要HTML
    static QString metaHeader(const QVariantList &cells) {
        QString h = QStringLiteral("<div style='background:#eef3ff;border:1px solid #cfe0ff;"
                                   "border-radius:8px;padding:8px 12px;font-size:13px;color:#33415c;'>");
        for (const auto &c : cells) h += c.toString() + QStringLiteral("&nbsp;&nbsp;|&nbsp;&nbsp;");
        return h + QStringLiteral("</div>");
    }
    static QString plainToHtml(const QString &t) {
        return QStringLiteral("<pre style='white-space:pre-wrap;font-family:Microsoft YaHei;"
                              "font-size:13px;line-height:1.8;color:#1f2329;'>%1</pre>").arg(t.toHtmlEscaped());
    }
    void load() {
        // 3D 盈利K线(profit_curve1h, 1小时累计)
        QUrl u1(API); u1.setQuery("action=profitt");
        auto *r1 = nam->get(QNetworkRequest(u1));
        connect(r1, &QNetworkReply::finished, this, [this, r1] {
            r1->deleteLater();
            auto d = QJsonDocument::fromJson(r1->readAll()).object();
            QVector<K3D> cs;
            for (auto v : d["data"].toArray()) {
                auto o = v.toObject();
                cs.push_back({jnum64(o["candle_time"]), jnum(o["o"]), jnum(o["h"]), jnum(o["l"]), jnum(o["c"])});
            }
            c3d->setCandles(cs);
        });
        // 3D 小时盈亏柱(pnl_history 每小时增量, 近24小时)
        QUrl u2(API); u2.setQuery("action=pnlhist&h=24");
        auto *r2 = nam->get(QNetworkRequest(u2));
        connect(r2, &QNetworkReply::finished, this, [this, r2] {
            r2->deleteLater();
            auto d = QJsonDocument::fromJson(r2->readAll()).object();
            QMap<qint64, double> hourly;                  // 小时->该小时最后快照
            for (auto v : d["data"].toArray()) {
                auto o = v.toObject();
                hourly[jnum64(o["ts"]) / 3600] = jnum(o["pnl"]);
            }
            QList<qint64> hs = hourly.keys();
            std::sort(hs.begin(), hs.end());
            QVector<QPair<QString,double>> bars;
            double prev = 0;
            if (!hs.isEmpty()) prev = hourly[hs.first()];
            for (qint64 h : hs) {
                double v = hourly[h];
                bars.push_back({QDateTime::fromSecsSinceEpoch(h * 3600).toString(QStringLiteral("H时")), v - prev});
                prev = v;
            }
            while (bars.size() > 24) bars.removeFirst();
            b3d->setBars(bars);
        });
        // 3D 盈亏构成饼(盈利轮/亏损轮)
        QUrl u3(API); u3.setQuery("action=stats");
        auto *r3 = nam->get(QNetworkRequest(u3));
        connect(r3, &QNetworkReply::finished, this, [this, r3] {
            r3->deleteLater();
            auto d = QJsonDocument::fromJson(r3->readAll()).object();
            auto s = d["data"].toObject()["summary"].toObject();
            int total = (int)jnum(s["total"]), wins = (int)jnum(s["wins"]);
            if (total > 0)
                p3d->setPie({{QStringLiteral("盈利轮"), double(wins)},
                             {QStringLiteral("亏损轮"), double(total - wins)}});
        });
        // 三张报表(数据库 ai_report / ai_tune / ai_reflect)
        fetch(QStringLiteral("report"));
        fetch(QStringLiteral("tune"));
        fetch(QStringLiteral("reflect"));
    }
    void fetch(const QString &type) {
        QUrl u(API); u.setQuery(QStringLiteral("action=aireport&type=%1").arg(type));
        auto *r = nam->get(QNetworkRequest(u));
        connect(r, &QNetworkReply::finished, this, [this, r, type] {
            r->deleteLater();
            auto d = QJsonDocument::fromJson(r->readAll()).object();
            QString latest = d["latest"].toString();
            // 顶部摘要(最新一条的元信息)
            QString head;
            if (d["list"].isArray() && !d["list"].toArray().isEmpty()) {
                auto o0 = d["list"].toArray().at(0).toObject();
                if (type == QStringLiteral("report"))
                    head = metaHeader({QStringLiteral("最新日报: <b>%1</b>").arg(o0["report_time"].toString()),
                        QStringLiteral("真实盈亏 <b style='color:%1'>%2U</b>").arg(jnum(o0["pnl"]) >= 0 ? "#e03131" : "#0ca678").arg(jnum(o0["pnl"]), 0, 'f', 2),
                        QStringLiteral("胜率 <b>%1%</b>").arg(jnum(o0["win_rate"]), 0, 'f', 1),
                        QStringLiteral("平仓 %4 轮").arg((int)jnum(o0["trade_count"]))});
                else if (type == QStringLiteral("tune"))
                    head = metaHeader({QStringLiteral("最新调参: <b>%1</b>").arg(o0["tune_time"].toString()),
                        QStringLiteral("主周期 <b>%1</b>").arg(o0["main_bar"].toString()),
                        QStringLiteral("主策略 <b>%1</b>").arg(o0["main_strat"].toString()),
                        QStringLiteral("评分 <b>%1</b>").arg(jnum(o0["score"]), 0, 'f', 1),
                        jnum(o0["changed"]) != 0 ? QStringLiteral("<b style='color:#e03131'>已变更</b>") : QStringLiteral("未变更")});
                else
                    head = metaHeader({QStringLiteral("最新反思: <b>%1</b>").arg(o0["reflect_time"].toString()),
                        QStringLiteral("胜率 <b>%1%</b>").arg(jnum(o0["wr_now"]), 0, 'f', 1),
                        QStringLiteral("决策 <b style='color:#2f6fed'>%1</b>").arg(o0["decision"].toString())});
            }
            if (type == QStringLiteral("report")) {
                if (latest.isEmpty()) latest = QStringLiteral("<div style='color:#888;'>暂无日报, 引擎 08:05 自动生成(或用 cmd_report.exe 手动生成)</div>");
                repView->setHtml(head + latest);
            } else if (type == QStringLiteral("tune")) {
                tuneView->setHtml(head + (latest.isEmpty() ? QStringLiteral("<div style='color:#888;'>暂无调参记录</div>") : plainToHtml(latest)));
            } else {
                reflView->setHtml(head + (latest.isEmpty() ? QStringLiteral("<div style='color:#888;'>暂无反思记录</div>") : plainToHtml(latest)));
            }
        });
    }
};

// ================================================================ 盈利曲线自绘(深度回测面板, 2026-09-25)
// 每小时累计盈亏曲线: 多策略多色折线, 红涨绿跌基线
class CurveWidget : public QWidget {
public:
    QMap<QString, QVector<QPointF>> series;   // 策略名 -> (x=小时序, y=累计盈亏)
    double minX = 0, maxX = 1, minY = -1, maxY = 1;
    void setData(const QMap<QString, QVector<QPointF>> &s) {
        series = s;
        minX = 1e18; maxX = -1e18; minY = 1e18; maxY = -1e18;
        for (auto &pts : series)
            for (auto &p : pts) {
                minX = qMin(minX, p.x()); maxX = qMax(maxX, p.x());
                minY = qMin(minY, p.y()); maxY = qMax(maxY, p.y());
            }
        if (minX > maxX) { minX = 0; maxX = 1; }
        if (minY > maxY) { minY = -1; maxY = 1; }
        if (maxY - minY < 0.2) { double m = (maxY + minY) / 2; minY = m - 0.1; maxY = m + 0.1; }
        update();
    }
protected:
    void paintEvent(QPaintEvent *) override {
        QPainter p(this);
        p.fillRect(rect(), QColor(0xf6, 0xf8, 0xfc));
        int L = 52, R = 10, T = 8, B = 20;
        int w = width() - L - R, h = height() - T - B;
        if (w <= 10 || h <= 10 || series.isEmpty()) return;
        // 零轴: 盈亏>0 红 / <0 绿(中国习惯)
        int y0 = T + int(h * (maxY - 0) / (maxY - minY));
        p.setPen(QPen(QColor(0xc9, 0xd3, 0xe4), 1, Qt::DashLine));
        p.drawLine(L, y0, L + w, y0);
        QColor pal[] = {QColor(0xe0,0x31,0x31), QColor(0x1d,0x6f,0xe0), QColor(0x9c,0x36,0xb5),
                        QColor(0xf7,0x67,0x07), QColor(0x0c,0xa6,0x78), QColor(0x10,0x98,0xad),
                        QColor(0x70,0x48,0xe8), QColor(0x5f,0x3d,0xc4), QColor(0xe8,0x89,0x0c)};
        int ci = 0;
        QFont f = p.font(); f.setPointSize(8); p.setFont(f);
        for (auto it = series.begin(); it != series.end(); ++it, ++ci) {
            const auto &pts = it.value();
            if (pts.isEmpty()) continue;
            QPen pen(pal[ci % 9], 1.6);
            p.setPen(pen);
            QPointF prev;
            for (int i = 0; i < pts.size(); ++i) {
                double x = L + w * (pts[i].x() - minX) / qMax(1e-9, maxX - minX);
                double y = T + h * (maxY - pts[i].y()) / (maxY - minY);
                if (i) p.drawLine(prev, QPointF(x, y));
                prev = QPointF(x, y);
            }
            p.setPen(QPen(pal[ci % 9], 1));
            p.drawText(L + 4 + (ci % 3) * 110, T + 12 + (ci / 3) * 13, it.key());
        }
    }
};

// ================================================================ 主窗口
class MainWindow : public QMainWindow {
public:
    MainWindow(QWidget *parent = nullptr) : QMainWindow(parent) {
        setWindowTitle(QStringLiteral("OKX 量化交易终端 (C++ Qt) - ETH模式·九7信号版·3m底九7买入·首仓30U·止盈+2%·九7加仓·每轮+1U·不限轮数"));
        // 2026-09-25 01:15 用户: 按 1024x748 固定仍"页面太大只看到左K线" → RDP 会话实际分辨率=客户端窗口大小,
        // 写死任何值都可能超屏。改为窗口最大化自适应任意分辨率, 左右 2:1 与 K线高 1/4 仍由 resizeEvent 动态维持。
        resize(1024, 748);
        setWindowState(Qt::WindowMaximized);

        auto *central = new QWidget;
        auto *root = new QVBoxLayout(central);   // 2026-09-24 22:30: 顶栏工具条 + 原有左右分栏
        root->setContentsMargins(8, 4, 8, 0);
        root->setSpacing(4);

        // ---- 顶栏工具条: 1:1还原网页 top 栏 ----
        // 2026-09-25 用户: 保留 AI日报, 删除「策略体检」「立即刷新」按钮;
        // AI滚动条拆出顶栏, 单独一行放在账户摘要(总盈亏)下面。
        auto *topBar = new QHBoxLayout;
        topBar->addWidget(new QLabel(QStringLiteral("📈 OKX 量化交易面板")));
        // 合约下拉框: 按最长合约名自适应宽度, 纯下拉不允许输入(2026-09-25 用户)
        instCombo = new QComboBox; instCombo->setEditable(false);
        instCombo->setSizeAdjustPolicy(QComboBox::AdjustToContents);
        barCombo = new QComboBox;
        // 用户 2026-09-24 晚: 策略唯一15m, K线默认且只显示15分钟线, 不显示其他周期
        for (const char *b : {"15m"})
            barCombo->addItem(b);
        barCombo->setCurrentText("15m");
        statusLabel = new QLabel;
        statusLabel->setMinimumWidth(60);   // 2026-09-25: 允许压缩, 防顶栏撑爆最小宽
        topBar->addWidget(new QLabel(QStringLiteral("合约:")));
        topBar->addWidget(instCombo);
        topBar->addWidget(new QLabel(QStringLiteral("周期:")));
        topBar->addWidget(barCombo);
        topBar->addWidget(statusLabel);
        topBar->addStretch();   // 网页 <span style="flex:1">
        statLabel = new QLabel(QStringLiteral("账户摘要 加载中..."));
        statLabel->setWordWrap(false);   // 2026-09-25 用户: 总盈亏文字要完整显示, 不设最小宽截断
        statLabel->setTextInteractionFlags(Qt::TextSelectableByMouse);
        statLabel->setStyleSheet(QStringLiteral(
            "font-family:'LiSu'; font-size:14pt; color:#8c1d2f;"
            "background: qlineargradient(x1:0,y1:0,x2:1,y2:0, stop:0 #fff1f3, stop:1 #ffe3e8);"
            "border:1px solid #f1b8c1; border-radius:8px; padding:4px 14px;"));
        topBar->addWidget(statLabel, 1);
        root->addLayout(topBar);
        // ---- AI滚动条单独一行(账户摘要下面), 行尾放 AI日报 + AI详情 按钮(2026-09-25 用户) ----
        auto *marqBar = new QHBoxLayout;
        aiMarqLab = new QLabel(QStringLiteral("AI自动策略加载中…"));
        aiMarqLab->setFrameShape(QFrame::NoFrame);
        aiMarqLab->setMinimumWidth(100);
        aiMarqLab->setStyleSheet(QStringLiteral(
            "font-family:'LiSu'; font-size:14pt; color:#0b5c40;"
            "background: qlineargradient(x1:0,y1:0,x2:1,y2:0, stop:0 #eafcf5, stop:1 #d3f4e8);"
            "border:1px solid #b8e6d9; border-radius:8px; padding:4px 10px;"));
        aiMarqLab->setToolTip(QStringLiteral("AI自动策略滚动播放, 点「AI详情」看完整内容"));
        marqBar->addWidget(aiMarqLab, 1);
        marqBar->addStretch(1);   // 2026-09-25 用户: 滚动条长度缩小到原来的一半(弹性1:1)
        auto *repBtn = new QPushButton(QStringLiteral("📊 AI日报"));
        repBtn->setToolTip(QStringLiteral("弹窗显示: 3D盈利K线/3D柱状/3D饼图 + AI日报/调参/反思 彩色报表"));
        connect(repBtn, &QPushButton::clicked, this, [this] {
            ReportCenter dlg(this);
            dlg.exec();
        });
        marqBar->addWidget(repBtn);
        auto *aiDetailBtn = new QPushButton(QStringLiteral("📋 AI详情"));
        connect(aiDetailBtn, &QPushButton::clicked, this, &MainWindow::showAiDetailDlg);
        aiDetailBtn->setStyleSheet(QStringLiteral(
            "QPushButton { background: qlineargradient(x1:0,y1:0,x2:0,y2:1,"
            " stop:0 #74e0b9, stop:0.5 #1fa876, stop:1 #128a5e); border: 1px solid #0e7d55; }"
            "QPushButton:hover { background: #2bbd86; }"));
        marqBar->addWidget(aiDetailBtn);
        root->addLayout(marqBar);

        auto *hbox = new QHBoxLayout;
        root->addLayout(hbox, 1);

        // ---- 左: K线区 + 交易流水(1:1还原网页左列: K线图卡 + 交易流水卡, 比例1fr) ----
        auto *left = new QVBoxLayout;

        chart = new CandleChart;
        chart->setFixedHeight(180);   // 初始值, resizeEvent 按窗口高 1/4 动态重算(用户: K线不许占一半页面)
        chart->requestOlder = [this] { loadOlderHistory(); };   // 2026-09-26: 往左拖到最老自动补拉更早历史
        left->addWidget(chart, 0);
        // 网页 .legend: MA5/MA10/MA20/布林带说明(1:1还原)
        auto *legend = new QLabel(QStringLiteral("MA5 ━ · MA10 ━ · MA20 ━ · 布林带(20,2) 橙色轨道"));
        legend->setStyleSheet(QStringLiteral("font-size:11px;color:#8a94a6;"));
        left->addWidget(legend);

        // ---- 左下: 交易流水(最近500条, 点合约名切K线) 纯净表格(2026-09-25 用户: 所有筛选/按钮/搜索框取消) ----
        flowBox = new QGroupBox(QStringLiteral("交易流水 (最近500条, 点合约名切K线)"));
        flowBox->setObjectName("flowBox");
        auto *flowLay = new QVBoxLayout(flowBox);
        flowTable = new QTableWidget(0, 7);   // 2026-09-25 用户: 删除「策略」栏(净盈亏/手续费/资金费 按实际)
        flowTable->setObjectName("flowTable");
        flowTable->setHorizontalHeaderLabels({QStringLiteral("时间"), QStringLiteral("合约"),
                                              QStringLiteral("方向"), QStringLiteral("价格"),
                                              QStringLiteral("净盈亏$"), QStringLiteral("手续费$"),
                                              QStringLiteral("资金费$")});
        // 列宽可拖动调节(Interactive), 最后一列自适应
        flowTable->horizontalHeader()->setSectionResizeMode(0, QHeaderView::Interactive);
        flowTable->horizontalHeader()->setSectionResizeMode(1, QHeaderView::Interactive);
        flowTable->horizontalHeader()->setSectionResizeMode(2, QHeaderView::Interactive);
        flowTable->horizontalHeader()->setSectionResizeMode(3, QHeaderView::Interactive);
        // 2026-09-25 用户: 盈利栏太大 → 缩小到原来的一半(固定宽); 「策略」栏已删, 资金费列自适应
        flowTable->horizontalHeader()->setSectionResizeMode(4, QHeaderView::Interactive);
        flowTable->horizontalHeader()->setSectionResizeMode(5, QHeaderView::Interactive);
        flowTable->horizontalHeader()->setSectionResizeMode(6, QHeaderView::Stretch);
        flowTable->horizontalHeader()->setDefaultAlignment(Qt::AlignCenter);   // 2026-09-25 用户: 列名居中
        flowTable->setColumnWidth(0, 128);   // 时间 MM-dd HH:mm:ss 完整显示
        flowTable->setColumnWidth(1, 132);
        flowTable->setColumnWidth(2, 56);
        flowTable->setColumnWidth(3, 92);
        flowTable->setColumnWidth(4, 80);
        flowTable->setColumnWidth(5, 76);
        flowTable->setEditTriggers(QAbstractItemView::NoEditTriggers);
        flowTable->setSelectionBehavior(QAbstractItemView::SelectRows);
        flowTable->setAlternatingRowColors(true);
        flowTable->setSortingEnabled(true);      // 点表头排序(Excel)
        flowTable->setVerticalScrollMode(QAbstractItemView::ScrollPerPixel);
        flowTable->verticalScrollBar()->setSingleStep(24);
        connect(flowTable, &QTableWidget::itemClicked, this, &MainWindow::onFlowClicked);
        enableTableCopy(flowTable, QStringLiteral("交易流水"));   // 右键可复制(用户 2026-09-24 晚)
        // 2026-09-25 用户: Excel式列筛选按钮对交易流水一并取消(监测表保留)
        flowLay->addWidget(flowTable);
        left->addWidget(flowBox, 1);   // 2026-09-25 用户: 三面板已删, 流水表占满左列剩余空间
        hbox->addLayout(left, 1);   // 1:1还原网页: 左列 1fr(自适应占满)

        // ---- 右: 信息面板(2026-09-25 用户: 左右一样宽且不许超边界 —— 弹性 1:1 平分, 不写死像素,
        //      任意 RDP 分辨率自适应; 右面板加 stretch=1, 与左列等分窗口宽) ----
        rightScroll = new QScrollArea;
        rightScroll->setWidgetResizable(true);
        rightScroll->setVerticalScrollBarPolicy(Qt::ScrollBarAsNeeded);
        rightScroll->setMinimumWidth(0);   // 允许压缩, 窄分辨率不撑爆
        rightScroll->setFrameShape(QFrame::NoFrame);
        auto *rightHost = new QWidget;
        auto *right = new QVBoxLayout(rightHost);
        right->setSpacing(6);

        // 2026-09-24 22:30 用户指令: 账户摘要移到顶栏「立即刷新」右边, 此处删除原面板

        gridBox = new QGroupBox(QStringLiteral("止盈 + 加仓信号监测 (ETH模式·九7信号版 · 100x只买涨 · 不设止损 · 价格+2%止盈 · 加仓=3m九7信号·每轮+1U·不限轮数(永远可加) · 首仓30U)"));
        gridBox->setToolTip(QStringLiteral("ETH模式·九7信号版(2026-09-26用户指令): 仅ETH-USDT-SWAP只买涨, 买入唯一信号=3m K线底部九7(标准神奇九转: 连续7根收盘<第4根前收盘, 计数恰好=7, 与K线标注同口径); 首仓保证金固定30U(旧1U+3U/天阶梯已废除), 有持仓不重复开; 止损不设, 亏损由交易所爆仓线兜底; 止盈单不挂, 每10秒检测 价格+2%(100x ROI+200%) 达标或现价≥止盈价立即市价全平; 加仓=再次出现3m底九7信号, 不限轮数(永远可加), 每轮固定+1U, 加仓无仓位上限。"));
        gridBox->setObjectName("gridBox");
        auto *gLay = new QVBoxLayout(gridBox);
        gridHead = new QLabel(QStringLiteral("加载中..."));
        gridHead->setWordWrap(true);
        gridHead->setStyleSheet("font-size:11px;color:#4a5568;");
        gLay->addWidget(gridHead);
        gridTable = new QTableWidget(0, 5);
        gridTable->setObjectName("gridTable");
        gridTable->setHorizontalHeaderLabels({QStringLiteral("合约"), QStringLiteral("加仓数"),
                                              QStringLiteral("现价"), QStringLiteral("止盈"),
                                              QStringLiteral("状态")});
        for (int i = 0; i < 5; ++i)
            gridTable->horizontalHeader()->setSectionResizeMode(i, QHeaderView::Stretch);   // 2026-09-24 22:09: 填满右边到边框
        gridTable->horizontalHeader()->setDefaultAlignment(Qt::AlignCenter);   // 列名居中
        gridTable->verticalHeader()->setDefaultSectionSize(32);                // 行距加大(好看)
        gridTable->setFixedHeight(32 * 10 + 34);   // 2026-09-24 22:09: 监测表整体10行高度(354px; 原112px重复设置已删, 那曾把表格压到只剩2行)
        gridTable->verticalHeader()->setVisible(false);
        gridTable->setEditTriggers(QAbstractItemView::NoEditTriggers);
        gridTable->setSelectionBehavior(QAbstractItemView::SelectRows);
        gridTable->verticalScrollBar()->setSingleStep(16);
        gridTable->setToolTip(QStringLiteral("每10秒刷新: 距下一档还差多少、触发后到底加没加仓、被风控拦在哪一步"));
        // 用户 2026-09-24 晚: 点击合约名自动联动左边K线图切到该合约(15分钟线)
        connect(gridTable, &QTableWidget::cellClicked, this, [this](int row, int) {
            if (auto *it = gridTable->item(row, 0)) {
                QString inst = it->text().trimmed().toUpper();
                if (!inst.isEmpty()) {   // 2026-09-25: 下拉框已改为不可输入 → 用 setCurrentText(命中列表项才切换)
                    int ix = instCombo->findText(inst);
                    if (ix >= 0) instCombo->setCurrentIndex(ix);   // 触发 currentTextChanged -> refreshAll
                }
            }
        });
        enableTableCopy(gridTable, QStringLiteral("止盈加仓"));   // 同样支持右键复制
        attachFilters(gridTable, gLay);   // Excel式列筛选(2026-09-24 22:09)
        gLay->addWidget(gridTable);
        // 1:1还原网页 #gridTip / #gridEntry 两行说明
        auto *gridTip = new QLabel(QStringLiteral("加仓策略: 再次出现 3m 底九7 信号(与买入同口径) 不限轮数(永远可加) · 每轮固定+1U · 加仓无仓位上限 · 首仓保证金固定30U | 亏损由交易所爆仓线兜底 | 不设止损 | 止盈不挂单: 每10秒检测 价格+2% 达标立即全平"));
        gridTip->setWordWrap(true);
        gridTip->setStyleSheet(QStringLiteral("font-size:11px;color:#5c677d;background:#f6f8fb;border:1px solid #e3e8f0;border-radius:6px;padding:5px 8px;"));
        gLay->addWidget(gridTip);
        auto *gridEntry = new QLabel(QStringLiteral("买入=3m底部九7: 连续7根收盘<第4根前收盘, 计数恰好=7, 断即清零；首仓保证金固定30U"));
        gridEntry->setWordWrap(true);
        gridEntry->setStyleSheet(QStringLiteral("font-size:11px;color:#8a6d3b;background:#fffaf0;border:1px solid #f0e3c8;border-radius:6px;padding:5px 8px;"));
        gLay->addWidget(gridEntry);
        // [2026-09-26 用户指令] 保底持仓 修改行: 持仓保证金 < 保底线 → 引擎自动补齐(默认30U, 数据库落盘)
        auto *floorRow = new QHBoxLayout();
        auto *floorLbl = new QLabel(QStringLiteral("保底持仓(USDT):"));
        floorLbl->setStyleSheet(QStringLiteral("font-size:12px;font-weight:600;color:#33415c;"));
        floorEdit = new QLineEdit;
        floorEdit->setFixedWidth(90);
        floorEdit->setPlaceholderText(QStringLiteral("30"));
        auto *floorBtn = new QPushButton(QStringLiteral("保存"));
        floorMsg = new QLabel(QStringLiteral("保证金低于保底线 → 引擎自动补齐到保底线(默认30U)"));
        floorMsg->setStyleSheet(QStringLiteral("font-size:11px;color:#8a94a6;"));
        floorMsg->setWordWrap(true);
        floorRow->addWidget(floorLbl);
        floorRow->addWidget(floorEdit);
        floorRow->addWidget(floorBtn);
        floorRow->addWidget(floorMsg, 1);
        gLay->addLayout(floorRow);
        connect(floorBtn, &QPushButton::clicked, this, [this] {
            bool okc = false;
            const double v = floorEdit->text().toDouble(&okc);
            floorMsg->setStyleSheet(QStringLiteral("font-size:11px;"));
            if (!okc || v < 1 || v > 500) {
                floorMsg->setText(QStringLiteral("❌ 保底持仓须在 1~500 USDT 之间"));
                floorMsg->setStyleSheet(QStringLiteral("font-size:11px;color:#e03131;font-weight:600;"));
                return;
            }
            QUrl us(API); us.setQuery(QStringLiteral("action=settings&eth_floor_u=%1").arg(v, 0, 'f', 2));
            auto *rf = nam->get(QNetworkRequest(us));
            connect(rf, &QNetworkReply::finished, this, [this, rf] {
                rf->deleteLater();
                const auto d = QJsonDocument::fromJson(rf->readAll()).object();
                if (d["ok"].toBool()) {
                    floorMsg->setText(QStringLiteral("✅ 已保存: 保底持仓 %1U (引擎30秒内生效)").arg(jnum(d["eth_floor_u"]), 0, 'f', 0));
                    floorMsg->setStyleSheet(QStringLiteral("font-size:11px;color:#0ca678;font-weight:600;"));
                } else {
                    floorMsg->setText(QStringLiteral("❌ 保存失败"));
                    floorMsg->setStyleSheet(QStringLiteral("font-size:11px;color:#e03131;font-weight:600;"));
                }
            });
        });
        // 网页 #gridLogBtn: 全宽按钮
        auto *gLogBtn = new QPushButton(QStringLiteral("加仓信号历史（3m九7 触发 · 加仓 · 风控拦截 都留痕）"));
        connect(gLogBtn, &QPushButton::clicked, this, &MainWindow::showGridLog);
        gLay->addWidget(gLogBtn);
        right->addWidget(gridBox);

        // 1:1还原网页 c-chk 策略体检卡
        auto *chkBox = new QGroupBox(QStringLiteral("策略体检（ETH模式·九7信号版 实盘同参核实）"));
        chkBox->setObjectName("chkBox");
        auto *chkLay = new QVBoxLayout(chkBox);
        auto *chkNote = new QLabel(QStringLiteral("PASS=可正常交易 / WARN=频率过低 / FAIL=信号失效。深度学习策略修改后自动核实生效。"));
        chkNote->setWordWrap(true);
        chkNote->setStyleSheet(QStringLiteral("font-size:11px;color:#8a94a6;"));
        chkLay->addWidget(chkNote);
        auto *chkBtn3 = new QPushButton(QStringLiteral("查看最近体检报告"));
        connect(chkBtn3, &QPushButton::clicked, this, &MainWindow::runBackcheck);
        chkLay->addWidget(chkBtn3);
        right->addWidget(chkBox);

        // 2026-09-24 22:30 用户指令: AI自动策略移到顶栏(滚动播放+详情按钮), 面板删除;
        // aiTable 转为隐藏数据源(渲染逻辑复用), AI筛选功能删除(不挂 attachFilters)
        aiTable = new QTableWidget(0, 3);
        aiTable->hide();

        right->addStretch();
        rightScroll->setWidget(rightHost);
        hbox->addWidget(rightScroll, 1);   // 2026-09-25 用户: 与左列 1:1 弹性平分(任意分辨率不超边界)

        setCentralWidget(central);

        // ---- 托盘: 关闭=最小化, 菜单退出 ----
        // 自绘K线风格图标(深蓝底+红绿蜡烛), 窗口/任务栏/托盘共用
        QPixmap pm = makeCandleIcon(256);
        setWindowIcon(QIcon(pm));
        tray = new QSystemTrayIcon(this);
        auto menu = new QMenu;
        menu->addAction(QStringLiteral("显示主界面"), this, &MainWindow::showNormal);
        menu->addAction(QStringLiteral("真正退出"), qApp, &QApplication::quit);
        tray->setContextMenu(menu);
        tray->setToolTip(QStringLiteral("OKX 量化交易终端"));
        tray->setIcon(QIcon(pm));
        tray->show();
        quitting = false;

        // ---- 悬浮窗: 置顶小窗(合约价+总盈亏), 可拖动, 双击回主界面 ----
        fw = new FloatWin;
        fw->onDouble = [this] { showNormal(); activateWindow(); };
        QAction *fwAct = menu->addAction(QStringLiteral("显示/隐藏悬浮窗"));
        QObject::connect(fwAct, &QAction::triggered, this, [this] {
            fw->setVisible(!fw->isVisible());
        });
        QRect scr = QApplication::primaryScreen()->availableGeometry();
        fw->move(scr.right() - fw->width() - 16, scr.bottom() - fw->height() - 48);
        fw->show();

        // ---- 网络 ----
        nam = new QNetworkAccessManager(this);
        nam->setProxy(QNetworkProxy(QNetworkProxy::NoProxy)); // 仅本程序直连,不走系统代理
        timer = new QTimer(this);
        connect(timer, &QTimer::timeout, this, &MainWindow::refreshAll);
        connect(instCombo, &QComboBox::currentTextChanged, this, &MainWindow::refreshAll);
        connect(barCombo, &QComboBox::currentTextChanged, this, &MainWindow::refreshAll);

        if (qEnvironmentVariable("QTAPP_NET") != "0") {
            loadSymbols();
            refreshAll();
            // [2026-09-26 用户指令] 启动时读取保底持仓设置(数据库 app_settings)
            QUrl uf(API); uf.setQuery(QStringLiteral("action=settings"));
            auto *rfl = nam->get(QNetworkRequest(uf));
            connect(rfl, &QNetworkReply::finished, this, [this, rfl] {
                rfl->deleteLater();
                const auto d = QJsonDocument::fromJson(rfl->readAll()).object();
                if (d["ok"].toBool() && floorEdit)
                    floorEdit->setText(QString::number(jnum(d["eth_floor_u"]), 'f', 0));
            });
            timer->start(3000);   // AJAX 实时同步: 3秒轮询全量数据接口
        }
    }

protected:
    void closeEvent(QCloseEvent *e) override {
        if (!quitting) { hide(); e->ignore(); }   // 关闭=最小化到托盘
        else e->accept();
    }
    // 2026-09-25 用户: 按屏幕比例动态布局 —— 左右弹性 1:1(布局系统自动平分, 不写死宽), K线图高=窗口高1/4
    void resizeEvent(QResizeEvent *) override {
        if (chart) chart->setFixedHeight(qMax(140, height() / 4));
    }

private:
    QComboBox *instCombo, *barCombo;            // 2026-09-25: 流水筛选下拉/搜索框按用户指令删除
    QLabel *aiMarqLab = nullptr;                // AI自动策略滚动播放标签(顶栏)
    QTimer *mqTimer = nullptr;                  // 跑马灯计时器
    QString aiMqText;                           // 滚动全文
    int mqPos = 0;
    QLabel *statusLabel, *statLabel;
    QScrollArea *rightScroll = nullptr;   // 2026-09-25 01:03: 右面板(2:1比例动态宽)
    QTableWidget *aiTable;
    CandleChart *chart;
    // CandleChart *profitChart 已移除(2026-09-24 21:40 用户取消盈利K线面板)
    QTableWidget *flowTable;
    struct FlowRow { QString time, inst, act; double px, pnl; double fee = 0, fund = 0; QString pos; QString remark; QString ordId; int fills = 1; };   // 2026-09-25 用户: 策略栏已删
    QVector<FlowRow> allFlowRows;
    struct AiRec { QString time; bool adj; QString text; double wr, profit; int cnt; };
    QVector<AiRec> aiRecords;
    QGroupBox *accBox, *aiBox, *flowBox, *gridBox;
    // 2026-09-25 用户: 深度回测三面板已整体移除(crashTabs/btTable/liqTable/simTable/adviceLab/eqCurve/eqData 一并删除)

    // ---- Excel式列筛选(2026-09-24 22:09): 每列表头下方一个"列名▼"按钮, 点开勾选要显示的值 ----
    QMap<QTableWidget*, QMap<int, QSet<QString>>> fltState;

    void attachFilters(QTableWidget *t, QVBoxLayout *lay) {
        auto *bar = new QWidget;
        auto *hl = new QHBoxLayout(bar);
        hl->setContentsMargins(0, 0, 0, 2); hl->setSpacing(3);
        for (int c = 0; c < t->columnCount(); ++c) {
            auto *b = new QToolButton(bar);
            b->setText(t->horizontalHeaderItem(c)->text() + QStringLiteral(" ▼"));
            b->setToolTip(QStringLiteral("Excel式筛选: 勾选要显示的值"));
            b->setStyleSheet(QStringLiteral(
                "QToolButton{background:#f2f6ff;border:1px solid #c9d4e8;border-radius:4px;padding:2px 4px;"
                "font-size:12px;font-family:隶书;color:#33415c;}"
                "QToolButton:hover{border-color:#2f6fed;color:#2f6fed;background:#e8f0ff;}"));
            connect(b, &QToolButton::clicked, this, [this, t, c] { showFilterMenu(t, c); });
            hl->addWidget(b, 1);
        }
        lay->addWidget(bar);
    }

    void showFilterMenu(QTableWidget *t, int col) {
        QSet<QString> vals;
        for (int r = 0; r < t->rowCount(); ++r)
            if (auto *it = t->item(r, col)) vals.insert(it->text().trimmed());
        const QMap<int, QSet<QString>> &tm = fltState[t];
        const QSet<QString> cur = tm.value(col);
        QMenu menu(this);
        auto *all = new QAction(QStringLiteral("(全选)"), &menu);
        all->setCheckable(true);
        all->setChecked(cur.isEmpty());
        menu.addAction(all);
        menu.addSeparator();
        QList<QAction*> acts;
        const QStringList sorted = QStringList(vals.constBegin(), vals.constEnd());
        for (const QString &v : sorted) {
            auto *a = new QAction(v.isEmpty() ? QStringLiteral("(空)") : v, &menu);
            a->setCheckable(true);
            a->setChecked(cur.isEmpty() || cur.contains(v));
            menu.addAction(a); acts << a;
        }
        connect(all, &QAction::toggled, &menu, [&acts](bool on) {
            for (auto *a : acts) { a->blockSignals(true); a->setChecked(on); a->blockSignals(false); }
        });
        for (auto *a : acts)
            connect(a, &QAction::toggled, &menu, [this, t, col, &acts](bool) {
                QStringList sel;
                for (auto *x : acts) if (x->isChecked()) sel << x->text().replace(QStringLiteral("(空)"), QString());
                if (sel.size() == acts.size()) fltState[t][col].clear();
                else fltState[t][col] = QSet<QString>(sel.constBegin(), sel.constEnd());
                applyFilters(t);
            });
        connect(all, &QAction::toggled, &menu, [this, t, col](bool on) {
            if (on) { fltState[t][col].clear(); applyFilters(t); }
        });
        menu.exec(QCursor::pos());
        applyFilters(t);   // 关闭菜单后按最终勾选状态刷新
    }

    void applyFilters(QTableWidget *t) {
        if (!t) return;
        const QMap<int, QSet<QString>> &st = fltState[t];
        for (int r = 0; r < t->rowCount(); ++r) {
            bool ok = true;
            for (auto it = st.constBegin(); it != st.constEnd(); ++it) {
                if (it.value().isEmpty()) continue;
                auto *cell = t->item(r, it.key());
                if (!cell || !it.value().contains(cell->text().trimmed())) { ok = false; break; }
            }
            t->setRowHidden(r, !ok);
        }
    }
    QTableWidget *gridTable = nullptr;   // 加仓信号 实时看板(3m九7, 每轮+1U, 不限轮数)
    QLabel *gridHead = nullptr;          // 加仓信号 一行摘要
    QLineEdit *floorEdit = nullptr;      // [2026-09-26] 保底持仓(USDT) 输入框(数据库 app_settings, 与网页共用)
    QLabel *floorMsg = nullptr;          // [2026-09-26] 保底持仓 保存结果提示
    QStringList gridLogHeader;           // 信号历史表头
    QVector<QStringList> gridLogs;       // 信号历史行
    FloatWin *fw = nullptr;
    QSystemTrayIcon *tray;
    QNetworkAccessManager *nam;
    QTimer *timer;
    bool quitting = false;
    QString lastInst, lastBar;   // K线历史缓存对应的合约/周期

    // 表格右键复制菜单(用户 2026-09-24 晚: 交易流水右键无法复制 -> 单元格/整行/全部 三种复制)
    void enableTableCopy(QTableWidget *t, const QString &name) {
        t->setContextMenuPolicy(Qt::CustomContextMenu);
        connect(t, &QTableWidget::customContextMenuRequested, this,
                [this, t, name](const QPoint &pos) {
            QMenu menu(name, this);
            QAction *aCell = menu.addAction(QStringLiteral("复制所选单元格"));
            QAction *aRow  = menu.addAction(QStringLiteral("复制整行"));
            menu.addSeparator();
            QAction *aAll  = menu.addAction(QStringLiteral("复制全部(%1行)").arg(t->rowCount()));
            QAction *act = menu.exec(t->viewport()->mapToGlobal(pos));
            if (!act) return;
            auto rowText = [&](int r) {
                QStringList cells;
                for (int c = 0; c < t->columnCount(); c++)
                    if (auto *i = t->item(r, c)) cells << i->text();
                return cells.join("\t");
            };
            QString txt;
            if (act == aCell) {
                if (auto *it = t->currentItem()) txt = it->text();
            } else if (act == aRow) {
                txt = rowText(t->currentRow());
            } else if (act == aAll) {
                QStringList rows;
                for (int r = 0; r < t->rowCount(); r++) rows << rowText(r);
                txt = rows.join("\n");
            }
            if (!txt.isEmpty()) QApplication::clipboard()->setText(txt);
        });
    }

    void loadSymbols() {
        QUrl u(API); u.setQuery("action=symbols");
        auto *r = nam->get(QNetworkRequest(u));
        connect(r, &QNetworkReply::finished, this, [this, r] {
            r->deleteLater();
            if (r->error() != QNetworkReply::NoError) {
                statusLabel->setText(QStringLiteral("符号列表失败: %1").arg(r->errorString()));
                return;
            }
            QJsonDocument d = QJsonDocument::fromJson(r->readAll());
            QStringList list;
            for (auto v : d.object()["data"].toArray()) list << v.toString();
            QString cur = instCombo->currentText();
            if (list.isEmpty()) list << MAIN_INST;
            instCombo->blockSignals(true);
            instCombo->clear(); instCombo->addItems(list);
            int sel = list.contains(cur) ? list.indexOf(cur) : list.indexOf(QString::fromLatin1(MAIN_INST));
            instCombo->setCurrentIndex(sel >= 0 ? sel : 0);   // 2026-09-25: 非可编辑下拉
            instCombo->blockSignals(false);
        });
    }

    // 2026-09-26 用户: 往左拖到最老K线 → 从数据库/OKX补拉更早的历史K线(前插, 视口钉住不动)
    void loadOlderHistory() {
        if (chart->candles.isEmpty() || chart->loadingOld) return;
        chart->loadingOld = true;
        const qint64 before = chart->candles.first().t;
        QUrl u(API); u.setQuery(QString("action=kline&inst=%1&bar=%2&before=%3").arg(lastInst, lastBar).arg(before));
        auto *r = nam->get(QNetworkRequest(u));
        connect(r, &QNetworkReply::finished, this, [this, r] {
            r->deleteLater();
            chart->loadingOld = false;
            if (r->error() != QNetworkReply::NoError) return;
            auto d = QJsonDocument::fromJson(r->readAll()).object();
            if (!d["ok"].toBool()) return;
            QVector<CandleChart::Candle> cs;
            for (auto v : d["data"].toArray()) {
                auto o = v.toObject();
                cs.push_back({jnum64(o["candle_time"]),
                              jnum(o["o"]), jnum(o["h"]),
                              jnum(o["l"]), jnum(o["c"])});
            }
            if (cs.isEmpty()) return;
            const int oldSz = chart->candles.size();
            const bool atLatest = chart->viewEnd < 0 || chart->viewEnd >= oldSz;
            chart->setCandles(cs);
            const int added = chart->candles.size() - oldSz;
            if (!atLatest && chart->viewEnd > 0 && added > 0) chart->viewEnd += added;  // 前插后索引平移, 视口钉在同一根K线
            statusLabel->setText(QStringLiteral("已加载更早历史 共%1根").arg(chart->candles.size()));
        });
    }

    void refreshAll() {
        QString inst = instCombo->currentText().trimmed().toUpper();
        if (inst.isEmpty()) return;
        if (!inst.contains("-USDT-")) inst = inst.section('-', 0, 0) + "-USDT-SWAP";
        QString bar = barCombo->currentText();
        // 2026-09-25 用户: loadCrashData 已随三面板移除

        // 切合约/周期时清空本地历史缓存(重新拉取), 同合约同周期则增量合并保留全部历史
        if (inst != lastInst || bar != lastBar) {
            chart->resetHistory();
            lastInst = inst; lastBar = bar;
        }

        // K线
        QUrl u1(API); u1.setQuery(QString("action=kline&inst=%1&bar=%2").arg(inst, bar));
        auto *r1 = nam->get(QNetworkRequest(u1));
        connect(r1, &QNetworkReply::finished, this, [this, r1, inst, bar] {
            r1->deleteLater();
            if (r1->error() != QNetworkReply::NoError) {
                statusLabel->setText(QStringLiteral("K线请求失败: %1").arg(r1->errorString()));
                return;
            }
            auto d = QJsonDocument::fromJson(r1->readAll()).object();
            if (!d["ok"].toBool()) return;
            QVector<CandleChart::Candle> cs;
            for (auto v : d["data"].toArray()) {
                auto o = v.toObject();
                cs.push_back({jnum64(o["candle_time"]),
                              jnum(o["o"]), jnum(o["h"]),
                              jnum(o["l"]), jnum(o["c"])});
            }
            chart->setCandles(cs);
            chart->lastTitle = QStringLiteral("%1 %2  收:%3  共%4根(含历史)")
                                   .arg(inst, bar,
                                        cs.isEmpty() ? "-" : QString::number(cs.last().c),
                                        QString::number(chart->candles.size()));
            statusLabel->setText(QStringLiteral("实时刷新 | %1 根").arg(cs.size()));
            if (fw && !cs.isEmpty()) fw->setPx(inst, cs.last().c);
        });

        // 止盈止损线 + 买卖点
        QUrl u2(API); u2.setQuery(QString("action=sltp&inst=%1").arg(inst));
        auto *r2 = nam->get(QNetworkRequest(u2));
        connect(r2, &QNetworkReply::finished, this, [this, r2] {
            r2->deleteLater();
            auto d = QJsonDocument::fromJson(r2->readAll()).object();
            QVector<CandleChart::Line> lines;
            for (auto v : d["data"].toArray()) {
                auto o = v.toObject();
                lines.push_back({jnum(o["tp_px"]), QColor(0xe0,0x31,0x31), QStringLiteral("止盈 %1").arg(jnum(o["tp_px"]))});
                lines.push_back({jnum(o["stop_px"]), QColor(0x0c,0xa6,0x78), QStringLiteral("止损 %1").arg(jnum(o["stop_px"]))});
            }
            chart->setLines(lines);
        });

        // 流水数据(交易流水表格; K线信号标注另由 marks 接口加载)
        QUrl u3(API); u3.setQuery(QString("action=trades"));
        auto *r3 = nam->get(QNetworkRequest(u3));
        connect(r3, &QNetworkReply::finished, this, [this, r3] {
            r3->deleteLater();
            auto d = QJsonDocument::fromJson(r3->readAll()).object();
            if (!d["ok"].toBool()) return;
            allFlowRows.clear();
            auto arr = d["data"].toArray();
            for (auto v : arr) {
                auto o = v.toObject();
                QString act = o["action"].toString();
                if (act != "buy" && act != "close") continue;
                QDateTime t = QDateTime::fromString(o["trade_time"].toString(), "yyyy-M-d H:m:s");
                if (!t.isValid()) t = QDateTime::fromString(o["trade_time"].toString(), "yyyy-MM-dd HH:mm:ss");
                double px = jnum(o["price"]);
                // 流水行数据(缓存, 供筛选)
                double pnl = o.contains("profit") ? jnum(o["profit"]) : jnum(o["pnl"]);
                QString pos = o.value("pos_status").toString();
                QString ordId = o.value("ord_id").toString();
                // 同一笔订单的多笔部分成交(OKX fills 每笔成交记一行, 同 ord_id) 聚合成 1 行,
                // 防止看起来像"重复买入"(2026-09-24 用户反馈"同一个合约买了4次")
                if (act == "buy" && !ordId.isEmpty()) {
                    bool merged = false;
                    for (auto &fr : allFlowRows) {
                        if (fr.act == "buy" && fr.ordId == ordId) {
                            fr.px = px; fr.fills++; merged = true; break;
                        }
                    }
                    if (merged) continue;
                }
                allFlowRows.push_back({o["trade_time"].toString(), o["inst_id"].toString(),
                                       act, px, pnl, jnum(o["fee"]), jnum(o["funding"]), pos,
                                       o.value("remark").toString(), ordId, 1});
            }
            applyFilter();
        });

        // K线信号标注(2026-09-24 深夜指令): 买入🚀/加仓🚀/平仓🍃(附盈利两位小数), 时间只要 HH:MM
        QUrl u3b(API); u3b.setQuery(QString("action=marks&inst=%1&days=3").arg(inst));
        auto *r3b = nam->get(QNetworkRequest(u3b));
        connect(r3b, &QNetworkReply::finished, this, [this, r3b, inst] {
            r3b->deleteLater();
            auto d = QJsonDocument::fromJson(r3b->readAll()).object();
            QVector<CandleChart::Marker> ms;
            if (d["ok"].toBool()) {
                for (auto v : d["data"].toArray()) {
                    auto o = v.toObject();
                    QString kind = o["kind"].toString();
                    int k = (kind == QStringLiteral("buy")) ? 0 : (kind == QStringLiteral("add")) ? 1 : 2;
                    double pf = o.value("profit").isNull() ? 0.0 : jnum(o["profit"]);
                    ms.push_back({(qint64)jnum64(o["t"]), jnum(o["px"]), k, pf, o.value("strat").toString()});
                }
            }
            chart->setMarkers(ms);
        });

        // K线信号(2026-09-26 二次精简): 后台预计算存 kline_signals, 客户端只读库渲染
        QUrl u3c(API); u3c.setQuery(QString("action=sigs&inst=%1&bar=%2").arg(inst, bar));
        auto *r3c = nam->get(QNetworkRequest(u3c));
        connect(r3c, &QNetworkReply::finished, this, [this, r3c] {
            r3c->deleteLater();
            auto d = QJsonDocument::fromJson(r3c->readAll()).object();
            QVector<CandleChart::SigMark> ss;
            if (d["ok"].toBool()) {
                for (auto v : d["data"].toArray()) {
                    auto a = v.toArray();
                    ss.push_back({(qint64)a[0].toDouble(), (int)a[1].toDouble()});
                }
            }
            chart->setSigs(ss);
        });

        // 账户摘要(一行字) + 盈利K线(profit_curve 表, 实时落库)
        QUrl u4(API); u4.setQuery("action=stats");
        auto *r4 = nam->get(QNetworkRequest(u4));
        connect(r4, &QNetworkReply::finished, this, [this, r4] {
            r4->deleteLater();
            auto d = QJsonDocument::fromJson(r4->readAll()).object();
            if (!d["ok"].toBool()) {
                statLabel->setText(QStringLiteral("统计请求失败(%1), %2 重试中...")
                                       .arg(r4->errorString(),
                                            QTime::currentTime().toString("HH:mm:ss")));
                return;
            }
            auto s = d["data"].toObject()["summary"].toObject();
            double net = jnum(s["net_pnl"]);
            int total = (int)jnum(s["total"]), wins = (int)jnum(s["wins"]);
            double wr = total ? 100.0 * wins / total : 0;
            // 引擎每分钟把总盈亏快照写入 pnl_history (数据库历史保存)
            QString snapT = QStringLiteral("--:--");
            auto ls = d["data"].toObject()["last_snap"].toObject();
            if (!ls.isEmpty()) snapT = ls["snap_time"].toString().mid(11, 5);   // HH:mm
            double posM = jnum(ls["pos_margin"]);   // 2026-09-25 用户: 仓位(当前持仓占用保证金USDT)
            // 一行字, 不换行; 2026-09-25 用户: 止损/网格/每分钟入库/更新时间 都删除
            Q_UNUSED(snapT);
            statLabel->setText(QStringLiteral(
                "总盈亏: <b style='color:%1'>%4 USDT</b> | 仓位: <b>%5 USDT</b> | 交易: %2笔 | 胜率: %3% | "
                "ETH九7信号版·100x只买涨·首仓30U·加仓=3m九7·每轮+1U·不限轮数·不设止损")
                .arg(net >= 0 ? "#e03131" : "#0ca678")
                .arg(total).arg(wr, 0, 'f', 1)
                .arg((net >= 0 ? "+" : "") + QString::number(net, 'f', 2))
                .arg(QString::number(posM, 'f', 2)));
            if (fw) { fw->setPnl(net); fw->setPos(posM); }
        });

        // 2026-09-24 21:40 用户指令: 盈利K线面板已取消, profitt 拉取移除(3D报表中心里的盈利图保留)

        // 加仓信号 实时监测看板(每3秒轮询): 3m九7信号状态 + 信号历史
        QUrl u7(API); u7.setQuery("action=gridmon");
        auto *r7 = nam->get(QNetworkRequest(u7));
        connect(r7, &QNetworkReply::finished, this, [this, r7] {
            r7->deleteLater();
            auto d = QJsonDocument::fromJson(r7->readAll()).object();
            if (!d["ok"].toBool() || !gridTable) return;
            auto data = d["data"].toObject();
            auto rows = data["rows"].toArray();
            gridTable->setRowCount(0);
            int armed = 0, wait = 0, block = 0, closed = 0;
            for (auto v : rows) {
                auto o = v.toObject();
                int row = gridTable->rowCount();
                gridTable->insertRow(row);
                gridTable->setItem(row, 0, new QTableWidgetItem(o["inst_id"].toString()));
                // 2026-09-24 深夜: 「档位」列改「加仓数」= 真实已加仓轮数(台账 ladder_adds), 张数误导(131张被看成131次加仓)
                auto *addIt = new QTableWidgetItem(QString("%1 次").arg((int)jnum(o["ladder_adds"])));
                addIt->setForeground(QBrush(QColor(0xe0,0x31,0x31)));
                gridTable->setItem(row, 1, addIt);
                gridTable->setItem(row, 2, new QTableWidgetItem(QString::number(jnum(o["last_px"]), 'g', 8)));
                // 止盈(带百分比的真实监控线; 距离%/下一档手数/止损列已按用户指令删除)
                double tpPx = jnum(o["tp_px"]), tpPct = jnum(o["tp_pct"]);
                auto *tpIt = new QTableWidgetItem(tpPx > 0
                    ? QString("%1\n+%2%").arg(QString::number(tpPx, 'g', 8)).arg(tpPct, 0, 'f', 1)
                    : QStringLiteral("--"));
                tpIt->setForeground(QBrush(QColor(0xe0,0x31,0x31)));   // 红=止盈
                gridTable->setItem(row, 3, tpIt);
                QString st = o["state"].toString();
                auto *stIt = new QTableWidgetItem(st);
                if (st.contains(QStringLiteral("已加仓"))) stIt->setForeground(QBrush(QColor(0xe0,0x31,0x31)));
                else if (st.contains(QStringLiteral("风控"))) stIt->setForeground(QBrush(QColor(0xe8,0x7c,0x00)));
                else if (st.contains(QStringLiteral("平仓"))) stIt->setForeground(QBrush(QColor(0x99,0x99,0x99)));
                else stIt->setForeground(QBrush(QColor(0x19,0x71,0xc2)));
                stIt->setToolTip(o["note"].toString());
                gridTable->setItem(row, 4, stIt);
                if (st.contains(QStringLiteral("已加仓")) || st.contains(QStringLiteral("已触发"))) armed++;
                else if (st.contains(QStringLiteral("风控"))) block++;
                else if (st.contains(QStringLiteral("平仓"))) closed++;
                else wait++;
            }
            for (int rr = 0; rr < gridTable->rowCount(); ++rr)    // 文字居中(2026-09-24 22:09)
                for (int cc = 0; cc < 5; ++cc)
                    if (auto *it = gridTable->item(rr, cc)) it->setTextAlignment(Qt::AlignCenter);
            applyFilters(gridTable);                              // 保持Excel式列筛选
            gridHead->setText(QStringLiteral(
                "监测 %1 个持仓 | 等待 %2 | 已触发 %3 | 拦截 %4 | 已平仓 %5 | ETH模式·九7信号版: 首仓30U · 100x只买涨 · 止盈+2% · 加仓=3m九7·不限轮数(永远可加)·每轮+1U·无仓位上限 · 不设止损 · 更新 %6")
                .arg(rows.size()).arg(wait).arg(armed).arg(block).arg(closed)
                .arg(QTime::currentTime().toString("HH:mm:ss")));
            gridLogs.clear();
            gridLogHeader = {QStringLiteral("时间"), QStringLiteral("合约"), QStringLiteral("类型"),
                             QStringLiteral("档位"), QStringLiteral("当时价"), QStringLiteral("触发价"),
                             QStringLiteral("下一档手数"), QStringLiteral("状态"), QStringLiteral("说明")};
            for (auto v : data["logs"].toArray()) {
                auto o = v.toObject();
                QStringList r;
                r << fmtNoYear(o["log_time"].toString())
                  << o["inst_id"].toString()
                  << o["kind"].toString()
                  << QString::number((int)jnum(o["level"]))
                  << QString::number(jnum(o["last_px"]), 'g', 8)
                  << QString::number(jnum(o["trigger_px"]), 'g', 8)
                  << QString("%1$").arg(jnum(o["add_usd"]), 0, 'f', 2)
                  << o["state"].toString()
                  << o["note"].toString();
                gridLogs.push_back(r);
            }
        });

        // AI 记录（数据库 ai_learning 表, 带时间列表可滚动, 点击弹窗看详情）
        QUrl u5(API); u5.setQuery("action=ai");
        auto *r5 = nam->get(QNetworkRequest(u5));
        connect(r5, &QNetworkReply::finished, this, [this, r5] {
            r5->deleteLater();
            auto d = QJsonDocument::fromJson(r5->readAll()).object();
            aiRecords.clear();
            for (auto v : d["data"].toArray()) {
                auto o = v.toObject();
                AiRec rec;
                rec.time = o["learn_time"].toString();     // yyyy-MM-dd HH:mm:ss
                rec.adj = jnum(o["adjusted"]) != 0;        // PHP返回字符串"1"/"0", toInt()解析不了
                rec.text = o["suggestion"].toString();
                rec.wr = jnum(o["win_rate"]);
                rec.profit = jnum(o["total_profit"]);
                rec.cnt = (int)jnum(o["trade_count"]);
                aiRecords.push_back(rec);
            }
            renderAiTable();
        });
    }

    // ---- 深度回测三面板数据加载已整体移除(2026-09-25 用户指令) ----

    // AI表格渲染(2026-09-24 22:30: 表格转隐藏数据源, 顶栏滚动播放; 筛选功能删除)
    void renderAiTable() {
        aiTable->setRowCount(0);
        for (int i = 0; i < aiRecords.size(); ++i) {
            const AiRec &rec = aiRecords[i];
            QString summary = rec.text.section('\n', 0, 0);
            int row = aiTable->rowCount();
            aiTable->insertRow(row);
            auto *tItem = new QTableWidgetItem(fmtNoYear(rec.time).section(':', 0, 1)); // 09-23 08:05 无年份
            tItem->setData(Qt::UserRole, i);            // 记住真实记录下标(筛选/排序后点击仍对得上)
            tItem->setForeground(QBrush(QColor(0x19,0x71,0xc2)));
            aiTable->setItem(row, 0, tItem);
            auto *kItem = new QTableWidgetItem(rec.adj ? QStringLiteral("调参") : QStringLiteral("日报"));
            kItem->setData(Qt::UserRole, i);
            kItem->setForeground(QBrush(rec.adj ? QColor(0xe0,0x31,0x31) : QColor(0x0c,0xa6,0x78)));
            aiTable->setItem(row, 1, kItem);
            // 表格只显示第一行摘要, 完整内容点击弹窗
            auto *sItem = new QTableWidgetItem(summary);
            sItem->setData(Qt::UserRole, i);
            sItem->setToolTip(QStringLiteral("点击查看完整策略内容"));
            aiTable->setItem(row, 2, sItem);
        }
        for (int rr = 0; rr < aiTable->rowCount(); ++rr)      // 文字居中(2026-09-24 22:09)
            for (int cc = 0; cc < 3; ++cc)
                if (auto *it = aiTable->item(rr, cc)) it->setTextAlignment(Qt::AlignCenter);
        updateAiMarquee();                                     // 顶栏滚动播放
        if (aiTable->rowCount() == 0)
            aiTable->setRowCount(1),
            aiTable->setItem(0, 0, new QTableWidgetItem(QStringLiteral("AI引擎学习中,暂无记录")));
    }

    // AI自动策略滚动播放: 最近8条首行连成循环文本, 每900ms移动一字(用户22:40: 比最初慢3倍)
    void updateAiMarquee() {
        if (!aiMarqLab) return;
        QStringList parts;
        for (int i = 0; i < aiRecords.size() && i < 8; ++i) {
            const AiRec &rec = aiRecords[i];
            parts << QStringLiteral("%1 %2 %3")
                         .arg(fmtNoYear(rec.time).section(':', 0, 1))
                         .arg(rec.adj ? QStringLiteral("【调参】") : QStringLiteral("【日报】"))
                         .arg(rec.text.section('\n', 0, 0).left(40));
        }
        aiMqText = parts.join(QStringLiteral("　◆　"));
        if (aiMqText.isEmpty()) aiMqText = QStringLiteral("AI引擎学习中,暂无记录");
        aiMqText += QStringLiteral("　◆　");
        if (!mqTimer) {
            mqTimer = new QTimer(this);
            mqTimer->setInterval(900);
            connect(mqTimer, &QTimer::timeout, this, [this] {
                if (aiMqText.isEmpty()) return;
                mqPos = (mqPos + 1) % aiMqText.size();
                aiMarqLab->setText(aiMqText.mid(mqPos) + aiMqText.left(mqPos));
            });
        }
        mqTimer->start();
    }

    // AI详情弹窗(顶栏「AI详情」按钮): 最近40条完整内容
    void showAiDetailDlg() {
        QDialog dlg(this);
        dlg.setWindowTitle(QStringLiteral("AI 自动策略详情(最近40条)"));
        dlg.resize(980, 640);
        auto *lay = new QVBoxLayout(&dlg);
        auto *tb = new QTextBrowser;
        QString html;
        for (int i = 0; i < aiRecords.size() && i < 40; ++i) {
            const AiRec &rec = aiRecords[i];
            html += QStringLiteral("<div style='margin-bottom:10px;'>"
                                   "<b style='color:%1;'>【%2 · %3】</b><br><pre style='white-space:pre-wrap;"
                                   "font-family:LiSu;margin:4px 0 0 0;font-size:14pt;'>%4</pre></div>")
                        .arg(rec.adj ? QStringLiteral("#e03131") : QStringLiteral("#0ca678"),
                             fmtNoYear(rec.time), rec.adj ? QStringLiteral("调参") : QStringLiteral("日报"),
                             rec.text.toHtmlEscaped());
        }
        tb->setHtml(html.isEmpty() ? QStringLiteral("(暂无AI记录)") : html);
        lay->addWidget(tb);
        dlg.exec();
    }

    // 加仓信号历史弹窗: 每一次触发/加仓/被风控拦截都留痕(来自 grid_signal_log 表)
    void showGridLog() {
        if (gridLogs.isEmpty()) {
            statusLabel->setText(QStringLiteral("信号历史: 暂无记录(还没有触发过加仓信号)"));
            return;
        }
        QDialog dlg(this);
        dlg.setWindowTitle(QStringLiteral("加仓信号历史 (3m九7 触发·加仓·风控拦截 都留痕)"));
        dlg.resize(1120, 560);
        auto *lay = new QVBoxLayout(&dlg);
        auto *t = new QTableWidget(gridLogs.size(), gridLogHeader.isEmpty() ? 9 : gridLogHeader.size(), &dlg);
        t->setHorizontalHeaderLabels(gridLogHeader.isEmpty()
            ? QStringList{QStringLiteral("时间"), QStringLiteral("合约"), QStringLiteral("类型"),
                          QStringLiteral("档位"), QStringLiteral("当时价"), QStringLiteral("触发价"),
                          QStringLiteral("下一档手数"), QStringLiteral("状态"), QStringLiteral("说明")}
            : gridLogHeader);
        t->horizontalHeader()->setSectionResizeMode(8, QHeaderView::Stretch);
        t->setColumnWidth(0, 116);
        t->setColumnWidth(1, 132);
        t->setColumnWidth(7, 110);
        t->setEditTriggers(QAbstractItemView::NoEditTriggers);
        t->setSelectionBehavior(QAbstractItemView::SelectRows);
        t->verticalHeader()->setVisible(false);
        for (int i = 0; i < gridLogs.size(); ++i)
            for (int c = 0; c < gridLogs[i].size(); ++c)
                t->setItem(i, c, new QTableWidgetItem(gridLogs[i][c]));
        lay->addWidget(t, 1);
        auto *btn = new QPushButton(QStringLiteral("关闭"), &dlg);
        connect(btn, &QPushButton::clicked, &dlg, &QDialog::accept);
        lay->addWidget(btn, 0);
        dlg.exec();
    }

    // 策略体检: 拉 backcheck 报告弹窗展示(37策略x5周期全量回测核实)
    void runBackcheck() {
        statusLabel->setText(QStringLiteral("策略体检: 读取报告..."));
        QUrl u(API); u.setQuery("action=backcheck");
        auto *r = nam->get(QNetworkRequest(u));
        connect(r, &QNetworkReply::finished, this, [this, r] {
            r->deleteLater();
            auto d = QJsonDocument::fromJson(r->readAll()).object();
            QString txt = d["data"].toString();
            if (txt.isEmpty()) txt = QStringLiteral("体检报告获取失败: %1").arg(r->errorString());
            statusLabel->setText(QStringLiteral("策略体检报告已加载"));
            QDialog dlg(this);
            dlg.setWindowTitle(QStringLiteral("全策略回测体检报告 (ETH模式·九7信号版 实盘同参; 策略库健康度参考)"));
            dlg.resize(900, 640);
            auto *lay = new QVBoxLayout(&dlg);
            auto *txtView = new QTextEdit(&dlg);
            txtView->setReadOnly(true);
            txtView->setFont(QFont("Consolas", 9));
            txtView->setPlainText(txt);
            lay->addWidget(txtView, 1);
            auto *btn = new QPushButton(QStringLiteral("关闭"), &dlg);
            connect(btn, &QPushButton::clicked, &dlg, &QDialog::accept);
            lay->addWidget(btn, 0);
            dlg.exec();
        });
    }

    // 点击AI记录 -> 弹窗显示该次策略完整内容
    void onAiClicked(int row, int col) {
        Q_UNUSED(col);
        int idx = row;
        if (auto *it = aiTable->item(row, 2)) idx = it->data(Qt::UserRole).toInt();
        if (idx < 0 || idx >= aiRecords.size()) return;
        const AiRec &r = aiRecords[idx];
        QString full = QStringLiteral("时间: %1\n类型: %2\n胜率: %3%   记录笔数: %4   收益: %5%\n\n%6")
                           .arg(r.time, r.adj ? QStringLiteral("AI自动调参") : QStringLiteral("AI日报"))
                           .arg(r.wr, 0, 'f', 1).arg(r.cnt).arg(r.profit, 0, 'f', 2)
                           .arg(r.text.isEmpty() ? QStringLiteral("(该条无详细内容)") : r.text);
        QDialog dlg(this);
        dlg.setWindowTitle(QStringLiteral("AI 策略详情  -  %1").arg(r.time));
        dlg.resize(600, 500);
        auto *lay = new QVBoxLayout(&dlg);
        auto *txt = new QTextEdit(&dlg);
        txt->setReadOnly(true);
        txt->setFont(QFont("Microsoft YaHei", 10));
        txt->setPlainText(full);
        lay->addWidget(txt, 1);
        auto *btn = new QPushButton(QStringLiteral("关闭"), &dlg);
        connect(btn, &QPushButton::clicked, &dlg, &QDialog::accept);
        lay->addWidget(btn, 0);
        dlg.exec();
    }

    // 流水渲染(2026-09-25 用户: 筛选分类/关键字搜索/Excel列筛选全部取消, 纯净全量显示; 点表头仍可排序)
    void applyFilter() {
        flowTable->setSortingEnabled(false);
        flowTable->setRowCount(0);
        for (const auto &r : allFlowRows) {
            int row = flowTable->rowCount();
            flowTable->insertRow(row);
            auto *tItem = new QTableWidgetItem(fmtNoYear(r.time));   // 09-23 08:05:33 无年份完整显示
            flowTable->setItem(row, 0, tItem);
            auto *instIt = new QTableWidgetItem(r.inst);
            instIt->setForeground(QBrush(QColor(0x1c,0x7e,0xd6)));   // 蓝色 = 可点击
            QFont uf = instIt->font(); uf.setUnderline(true); instIt->setFont(uf);
            flowTable->setItem(row, 1, instIt);
            flowTable->setItem(row, 2, new QTableWidgetItem(r.act == "buy" ? QStringLiteral("买入") : QStringLiteral("平仓")));
            auto *pxIt = new QTableWidgetItem;                       // 数值型排序(Excel式)
            pxIt->setText(QString::number(r.px, 'f', 4));
            pxIt->setData(Qt::EditRole, r.px);
            flowTable->setItem(row, 3, pxIt);
            auto *pnlIt = new QTableWidgetItem;
            pnlIt->setText(r.pnl ? QString::number(r.pnl, 'f', 2) : QStringLiteral("-"));
            pnlIt->setData(Qt::EditRole, r.pnl);
            pnlIt->setForeground(r.pnl > 0 ? QBrush(QColor(0xe0,0x31,0x31)) : QBrush(r.pnl < 0 ? QColor(0x0c,0xa6,0x78) : QColor(0x33,0x33,0x33)));
            flowTable->setItem(row, 4, pnlIt);
            auto *feeIt = new QTableWidgetItem;                     // 手续费(负=支出, 2026-09-25 按实际)
            feeIt->setText(r.fee ? QString::number(r.fee, 'f', 4) : QStringLiteral("-"));
            feeIt->setData(Qt::EditRole, r.fee);
            feeIt->setForeground(QBrush(QColor(0x88,0x88,0x88)));
            flowTable->setItem(row, 5, feeIt);
            auto *fundIt = new QTableWidgetItem;                    // 资金费(正收/负付, 2026-09-25 按实际)
            fundIt->setText(r.fund ? QString::number(r.fund, 'f', 4) : QStringLiteral("-"));
            fundIt->setData(Qt::EditRole, r.fund);
            fundIt->setForeground(QBrush(QColor(0x88,0x88,0x88)));
            flowTable->setItem(row, 6, fundIt);
            if (r.act == "buy" && r.fills > 1)      // 买入行标注"同单多笔成交"聚合数
                flowTable->item(row, 2)->setText(QStringLiteral("买入(%1笔成交)").arg(r.fills));
        }
        for (int rr = 0; rr < flowTable->rowCount(); ++rr)    // 文字居中(2026-09-24 22:09)
            for (int cc = 0; cc < 7; ++cc)
                if (auto *it = flowTable->item(rr, cc)) it->setTextAlignment(Qt::AlignCenter);
        flowTable->setSortingEnabled(true);
    }

    // 点流水里的合约名 -> 左边K线切换到该合约
    void onFlowClicked(QTableWidgetItem *it) {
        if (!it || it->column() != 1) return;
        QString inst = it->text().trimmed().toUpper();
        if (inst.isEmpty() || !inst.contains("-")) return;
        int ix = instCombo->findText(inst);   // 2026-09-25: 非可编辑下拉 → setCurrentIndex
        if (ix >= 0) instCombo->setCurrentIndex(ix);
    }
};

int main(int argc, char *argv[]) {
    QApplication app(argc, argv);
    app.setApplicationName("OKX Trading Terminal");
    QFont f(QStringLiteral("隶书"), 10);   // 2026-09-24 22:09 用户指令: 全局字体改隶书
    app.setFont(f);

    // ---- 全局美化: 立体渐变按钮(按下变色) + 表格/表头/输入框/滚动条 精修 ----
    app.setStyleSheet(QStringLiteral(
        "QMainWindow, QDialog { background: #f4f6fa; }"
        "QPushButton {"
        "  background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #5b93ff, stop:0.5 #3b78f0, stop:1 #2f6fed);"
        "  color: white; border: 1px solid #2557c8; border-radius: 6px;"
        "  padding: 5px 16px; font-weight: bold; font-size: 13px;"
        "}"
        "QPushButton:hover {"
        "  background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #78a6ff, stop:0.5 #5588f5, stop:1 #4479f2);"
        "}"
        "QPushButton:pressed {"
        "  background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #1d47a8, stop:1 #2a56c0);"
        "  border-color: #16347c; padding-top: 7px; padding-bottom: 3px;"
        "}"
        "QGroupBox { background: #ffffff; border: 1px solid rgba(90,110,160,.25); border-radius: 10px;"
        "  margin-top: 14px; padding: 10px 6px 6px 6px; font-weight: bold; }"
        /* 每个面板不同的柔和渐变底色(参考 dribbble 流行卡配色) */
        "QGroupBox#flowBox { background: qlineargradient(x1:0,y1:0,x2:1,y2:1, stop:0 #f2fbf7, stop:1 #e8f7fb); }"
        "QGroupBox#gridBox { background: qlineargradient(x1:0,y1:0,x2:1,y2:1, stop:0 #fff8ec, stop:1 #fdeee6); }"
        "QGroupBox::title { subcontrol-origin: margin; subcontrol-position: top center; left: 0; right: 0; padding: 0 6px;"
        "  background: transparent; font-size: 15pt; font-weight: bold; letter-spacing: 1px; }"   /* 小三号(用户22:30); 2026-09-25 用户: 标题居中 */
        /* 标题各自不同金属色 + 悬停变亮 */
        "QGroupBox#flowBox::title { color: #0e9f6e; font-size: 10.5pt; }"   /* 2026-09-25 用户: 交易流水标题改5号字(原15pt太大, 下面表格看不见) */
        "QGroupBox#gridBox::title { color: #e8590c; }"
        "QGroupBox:hover::title { color: #d6336c; }"
        "QTableWidget { background: rgba(255,255,255,.88);"
        "  gridline-color: #e6ebf3; border: 1px solid rgba(90,110,160,.2); border-radius: 6px;"
        "  selection-background-color: #cfe2ff; selection-color: #16347c; font-size: 14px; }"
        "QTableWidget::item { padding: 3px 4px; }"
        "QTableWidget::item:selected { border: 1px solid #2f6fed; }"
        /* 两张表表头配色不同: 流水=祖母绿, 监测=琥珀金 */
        "QTableWidget#flowTable QHeaderView::section { background: qlineargradient(x1:0,y1:0,x2:0,y2:1,"
        "  stop:0 #d8f5e6, stop:1 #b2e6cd); border-bottom: 2px solid #0ca678; color: #0b5c40;"
        "  font-size: 10.5pt; }"   /* 2026-09-25 用户: 流水列名改5号字(10.5pt), 全局表头15pt对它不生效 */
        "QTableWidget#flowTable { alternate-background-color: #eefaf3; }"
        "QTableWidget#gridTable QHeaderView::section { background: qlineargradient(x1:0,y1:0,x2:0,y2:1,"
        "  stop:0 #ffe8c2, stop:1 #ffd39e); border-bottom: 2px solid #e8590c; color: #8a3a00; }"
        "QTableWidget#gridTable { alternate-background-color: #fff6ea; }"
        "QHeaderView::section { background: qlineargradient(x1:0,y1:0,x2:0,y2:1,"
        "  stop:0 #fbfcfe, stop:1 #e8edf6); border: none; border-bottom: 2px solid #2f6fed;"
        "  padding: 5px 8px; font-size: 15pt; font-weight: bold; color: #33415c; }"   /* 小三号 */
        "QHeaderView::section:hover { background: #dce9ff; }"
        "QTableCornerButton::section { background: #e8edf6; border: none; }"
        /* 悬浮提示: 隶书四号 + 暗色底(用户22:30) */
        "QToolTip { font-family: 'LiSu'; font-size: 15pt; color: #f3e9d6;"
        "  background-color: #232a36; border: 1px solid #4a5568; padding: 4px 8px; }"
        "QComboBox { background: white; border: 1px solid #cfd6e4; border-radius: 6px; padding: 4px 10px; }"
        "QComboBox:hover { border-color: #2f6fed; }"
        "QComboBox::drop-down { border: none; width: 20px; }"
        "QComboBox QAbstractItemView { background: white; selection-background-color: #2f6fed; }"
        "QLineEdit { background: white; border: 1px solid #cfd6e4; border-radius: 6px; padding: 4px 8px; }"
        "QLineEdit:focus { border: 1px solid #2f6fed; }"
        "QScrollBar:vertical { background: #eef1f6; width: 10px; border-radius: 5px; }"
        "QScrollBar::handle:vertical { background: #c3cbdb; border-radius: 5px; min-height: 30px; }"
        "QScrollBar::handle:vertical:hover { background: #2f6fed; }"
        "QScrollBar:horizontal { background: #eef1f6; height: 10px; border-radius: 5px; }"
        "QScrollBar::handle:horizontal { background: #c3cbdb; border-radius: 5px; min-width: 30px; }"
        "QScrollBar::add-line, QScrollBar::sub-line { height: 0; width: 0; }"
        "QScrollBar::add-page, QScrollBar::sub-page { background: transparent; }"
        "QLabel { background: transparent; }"
        "QTextEdit { background: white; border: 1px solid #e0e5ef; border-radius: 6px; }"
        "QTabWidget::pane { border: 1px solid #e0e5ef; border-radius: 6px; background: #ffffff; top: -1px; }"
        "QTabBar::tab { background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #f4f6fa, stop:1 #e8edf6);"
        "  border: 1px solid #e0e5ef; border-bottom: none; padding: 7px 20px; margin-right: 3px;"
        "  border-top-left-radius: 8px; border-top-right-radius: 8px; font-weight: bold; color: #66708a; }"
        "QTabBar::tab:selected { background: #ffffff; color: #2f6fed; border-bottom: 2px solid #2f6fed; }"
        "QTabBar::tab:hover { color: #2f6fed; }"
        "QTextBrowser { background: white; border: 1px solid #e0e5ef; border-radius: 6px; }"
    ));

    // --reports: 直达 AI 报表中心弹窗(3D图表+日报/调参/反思)
    if (app.arguments().contains(QStringLiteral("--reports"))) {
        ReportCenter rc;
        rc.show();
        return app.exec();
    }

    MainWindow w;
    w.show();
    return app.exec();
}
