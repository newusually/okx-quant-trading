/*
 * ============================================================================
 * OKX 量化交易终端 —— Qt5/6 单文件桌面程序 (C++ / Windows 部署)  main.cpp
 * ============================================================================
 * 【程序职责】
 *   本文件是 OKX 量化交易系统的 Qt 桌面交易终端(单文件 Qt 程序), 对接后台
 *   C++ 引擎的 apihub HTTP JSON 接口(本机 127.0.0.1, 见下方 API 常量),
 *   一切数据均为"只读显示": 分钟K线(QPainter 自绘, 红涨绿跌)、买卖点标注、
 *   止盈止损线、账户摘要、AI 策略记录、交易流水、加仓信号监测、3D 报表等。
 *   客户端不直接下单 —— 开仓/加仓/止盈全部由后台 C++ 引擎完成, 本程序唯一
 *   的可写操作是保存"保底持仓"设置; 另支持托盘最小化与置顶悬浮窗。
 *
 * 【类/结构清单】(按文件出现顺序)
 *   jnum/jnum64/fmtNoYear        : JSON 数值转换、日期去年份 等工具函数
 *   drawSparkStar/makeWandCursor/makeHandCursor : QPainter 手绘光标(魔法棒/小手)
 *   CandleChart                  : K线自绘控件(视口裁剪/MA/BOLL/九转/十字光标/缩放拖动)
 *   makeCandleIcon               : 自绘 K线风格程序图标(窗口/托盘/悬浮窗共用)
 *   Chart3D                      : 自绘伪 3D 等轴投影图表(盈利K线/盈亏柱/构成饼)
 *   FloatWin                     : 置顶无边框悬浮小窗(价格+总盈亏+仓位, 可拖动)
 *   ReportCenter                 : AI 报表中心弹窗(日报/调参/反思 + 3 张 3D 图)
 *   CurveWidget                  : 多策略盈利曲线自绘控件
 *   MainWindow                   : 主窗口(顶栏/K线/流水/监测面板/托盘/悬浮窗/各弹窗)
 *   main()                       : 入口(全局字体/样式表, 支持 --reports 直达报表中心)
 *
 * 【主窗口界面结构】
 *   顶栏工具条 : 标题 + 合约下拉 + 周期下拉 + 状态 + 账户摘要(总盈亏等一行字)
 *   第二行     : AI 自动策略滚动条 + 「AI日报」「AI详情」按钮
 *   左列       : K线图(高=窗口 1/4) + 指标图例 + 交易流水表(最近500条, 点合约切K线)
 *   右列       : 止盈+加仓信号监测面板(含保底持仓设置行) + 策略体检卡
 *   托盘       : 关闭=最小化; 菜单: 显示主界面/真正退出/显隐悬浮窗
 *   悬浮窗     : 右下角置顶小窗, 双击回到主界面
 * ============================================================================
 */
#include <QtWidgets>              // Qt Widgets 组件库(窗口/布局/表格/按钮等, 本程序主体)
#include <QFile>                  // 文件读写(K线图调试日志 _chart.log 用)
#include <QHash>                  // 哈希表(K线时间戳索引、信号索引用)
#include <QClipboard>             // 剪贴板(表格右键复制功能用)
#include <QDesktopServices>       // 系统桌面服务(用默认程序打开本地 Excel 报表用)
#include <functional>             // std::function(补拉历史回调/悬浮窗双击回调)
#include <algorithm>              // STL 算法(std::sort / std::remove_if 等)
#include <QtNetwork>              // Qt 网络模块(QNetworkAccessManager 调 apihub 接口)
#include <cmath>                  // 数学函数(std::sqrt 等, 布林带标准差用)

// api.php 的数字是 JSON 字符串(PHP json_encode MySQL 行), 统一转 double
static double jnum(const QJsonValue &v) {   // JSON 值 → double(兼容"字符串型数字"与数值型)
    return v.isString() ? v.toString().toDouble() : v.toDouble();   // 是字符串先转 QString 再 toDouble, 否则直接取数值
}
static qint64 jnum64(const QJsonValue &v) {   // JSON 值 → 64 位整数(毫秒时间戳等大数用)
    return (qint64)(v.isString() ? v.toString().toDouble() : v.toDouble());   // 同上兼容处理, 最后截断为整型
}

// "2026-09-23 08:05:33" -> "09-23 08:05:33" (去掉年份, 日期时间完整显示)
static QString fmtNoYear(const QString &dt) {   // 时间串去年份(表格里省宽度又保留完整时分秒)
    QDateTime t = QDateTime::fromString(dt, "yyyy-MM-dd HH:mm:ss");   // 先按标准格式解析
    if (!t.isValid()) t = QDateTime::fromString(dt, "yyyy-M-d H:m:s");   // 失败再兼容无前导零格式
    if (t.isValid()) return t.toString("MM-dd HH:mm:ss");   // 解析成功 → 重排为"月-日 时:分:秒"
    return dt.length() > 10 ? dt.mid(5) : dt;   // 兜底: 直接截掉前 5 个字符(年份+横杠)
}

static const char *API = "http://127.0.0.1/api.php";   // apihub 本地接口地址(所有 JSON 数据的只读来源)
static const char *MAIN_INST = "ETH-USDT-SWAP";   // 默认主合约: ETH-USDT 永续(ETH 模式)

// ================================================================ 手绘光标
// 2026-09-25 用户: Windows Server 无 Segoe UI Emoji 字体, 🪄/🤚 显示为黑色小方块 → 全部自己用 QPainter 画。
// 魔法棒: 棕色斜棒身 + 金色四芒星棒尖 + 星尘; 小手: 手掌+四指+拇指。热点在棒尖/掌心。
static void drawSparkStar(QPainter &p, double cx, double cy, double r, const QColor &c) {   // 画一颗四芒星(棒尖/星尘通用小工具)
    QPolygonF poly;   // 四芒星多边形顶点容器
    poly << QPointF(cx, cy - r) << QPointF(cx + r * 0.28, cy - r * 0.28)   // 上尖点 + 右上凹点
         << QPointF(cx + r, cy) << QPointF(cx + r * 0.28, cy + r * 0.28)   // 右尖点 + 右下凹点
         << QPointF(cx, cy + r) << QPointF(cx - r * 0.28, cy + r * 0.28)   // 下尖点 + 左下凹点
         << QPointF(cx - r, cy) << QPointF(cx - r * 0.28, cy - r * 0.28);  // 左尖点 + 左上凹点
    p.setPen(Qt::NoPen); p.setBrush(c);   // 不描边, 用传入颜色填充
    p.drawPolygon(poly);   // 绘制四芒星
}
static QCursor makeWandCursor() {   // 手绘"魔法棒"光标(64x64 透明底)
    QPixmap pm(64, 64); pm.fill(Qt::transparent);   // 64x64 画布, 先填透明
    QPainter p(&pm);   // 在 pixmap 上作画
    p.setRenderHint(QPainter::Antialiasing);   // 开抗锯齿, 线条平滑
    QPen stick(QColor(150, 96, 52), 7);            // 棕色棒身(对角线, 圆头)
    stick.setCapStyle(Qt::RoundCap);   // 线帽设为圆头
    p.setPen(stick); p.drawLine(12, 52, 38, 26);   // 画左下→右上的斜棒身
    QPen hl(QColor(216, 170, 118), 2);             // 棒身高光
    p.setPen(hl); p.drawLine(15, 48, 31, 32);   // 叠一条细高光线在棒身上
    drawSparkStar(p, 43, 21, 11, QColor(255, 214, 64));   // 金色棒尖四芒星
    drawSparkStar(p, 43, 21, 6, QColor(255, 255, 214));   // 棒尖内芯(亮黄叠层)
    drawSparkStar(p, 54, 9, 5, QColor(255, 236, 140));    // 星尘(右上小星)
    drawSparkStar(p, 32, 8, 4, QColor(255, 236, 140));    // 星尘(上方小星)
    drawSparkStar(p, 20, 20, 3, QColor(255, 236, 140));   // 星尘(左侧小星)
    p.end();   // 结束绘制(解除对 pixmap 的绑定)
    return QCursor(pm, 8, 56);   // 生成光标, 热点定在(8,56)棒身末端
}
static QCursor makeHandCursor() {   // 手绘"可爱小手"光标(64x64 透明底)
    QPixmap pm(64, 64); pm.fill(Qt::transparent);   // 64x64 画布, 先填透明
    QPainter p(&pm);   // 作画
    p.setRenderHint(QPainter::Antialiasing);   // 抗锯齿
    QPen outline(QColor(190, 125, 72), 2);   // 棕色描边笔
    p.setPen(outline); p.setBrush(QColor(255, 213, 170));   // 描边 + 肤色填充
    for (int i = 0; i < 4; ++i)                    // 四根手指
        p.drawRoundedRect(17 + i * 8, 10, 7, 20, 4, 4);   // 画 4 个并排圆角矩形作手指(逐个右移 8px)
    p.drawRoundedRect(15, 22, 34, 30, 13, 13);     // 手掌
    p.drawRoundedRect(44, 30, 13, 9, 5, 5);        // 拇指
    p.end();   // 结束绘制
    return QCursor(pm, 30, 16);   // 生成光标, 热点定在掌心
}

// ================================================================ K线自绘图
class CandleChart : public QWidget {   // K线自绘控件(纯 QPainter, 不依赖图表库)
public:   // 公开成员: 主窗口直接读写
    struct Candle { qint64 t; double o, h, l, c; };   // 单根K线: 时间戳(毫秒) + 开/高/低/收
    // 信号标注(2026-09-24 深夜用户指令): kind 0=买入🚀 1=加仓🚀 2=平仓🍃(附盈利)
    struct Marker  { qint64 t; double px; int kind; double profit; QString strat; };   // 买卖点标注: 时间/价格/类型/盈利/策略名
    struct Line    { double px; QColor col; QString label; };   // 水平线(止盈/止损): 价格 + 颜色 + 左侧文字标签
    // K线信号(2026-09-26 三次精简: 只留td_nine九转, 1号指标triple_ma已删): 后台预计算存 kline_signals, 客户端只读库标注不自己算
    struct SigMark { qint64 t; int mask; };   // K线信号: 时间戳 + 位掩码(九转计数等, 后台算好只读)

    QVector<Candle> candles;   // K线序列(全部已加载历史, 按时间升序)
    QVector<Marker> markers;   // 买卖点标注序列
    QVector<Line>   lines;   // 止盈/止损水平线序列
    QVector<SigMark> sigs;   // 九转信号序列
    QString lastTitle;   // 图表左上角标题(合约 周期 最新收盘 总根数)
    bool simple = false;   // 盈利K线精简模式: 不画MA/BOLL/图例/信号标注, 横轴带日期(用户: 盈利图别画指标)
    int viewCount = 100;   // 视口默认100根(2026-09-25 用户: 只显示最近100根, 原200, 缩小或往左拖才看更多)
    int viewEnd   = -1;    // 视口右端索引(-1=最新); 向左拖动变小看历史
    int dragStartX = 0, dragStartEnd = 0;   // 拖动起点: 鼠标 x 坐标 + 拖动开始时的视口右端索引
    bool dragging = false;   // 是否正在拖动K线
    bool loadingOld = false;               // 正在补拉更早历史(2026-09-26)
    std::function<void()> requestOlder;    // 往左拖触达最老K线时回调, 由外部补拉历史
    int hoverIdx = -1;     // 十字光标所在K线索引(-1=无)

    // 魔法棒星星特效(2026-09-24 22:09 用户指令)
    struct Spark { QPointF p; qint64 born; };   // 一颗星星: 出现位置 + 诞生时刻(算渐隐年龄用)
    QVector<Spark> sparkles;   // 存活中的星星列表
    QTimer *sparkTimer = nullptr;   // 星星动画定时器(每帧淘汰过期星星并重绘)

    void setCandles(const QVector<Candle> &c) {   // 合并新K线数据(增量合并不丢历史)
        // 按时间戳合并: 新数据覆盖同时间戳旧K线(实时更新最后一根), 历史K线永不丢失
        bool atLatest = (viewEnd < 0 || viewEnd >= candles.size());   // 记录合并前视口是否停在最新K线
        QHash<qint64, int> idx;   // 时间戳 → 下标 的索引表
        for (int i = 0; i < candles.size(); i++) idx[candles[i].t] = i;   // 先给现有K线建索引
        for (const auto &k : c) {   // 逐条合并新数据
            auto it = idx.find(k.t);   // 查找同时间戳的旧K线
            if (it != idx.end()) candles[it.value()] = k;   // 已存在 → 覆盖(实时刷新最后一根)
            else { candles.push_back(k); idx[k.t] = candles.size() - 1; }   // 不存在 → 追加并登记索引
        }
        std::sort(candles.begin(), candles.end(),   // 全量按时间升序排序
                  [](const Candle &a, const Candle &b) { return a.t < b.t; });   // 比较器: 时间早在前
        if (atLatest || viewEnd < 0 || viewEnd > candles.size()) viewEnd = candles.size();   // 原在看最新或越界 → 视口右端跟随到最新
        update();   // 触发重绘
    }
    void resetHistory() { candles.clear(); viewEnd = -1; hoverIdx = -1; update(); }   // 清空全部历史(切合约/周期时调用)
    void setMarkers(const QVector<Marker> &m) { markers = m; update(); }   // 更新买卖点标注并重绘
    void setLines(const QVector<Line> &l)     { lines = l; update(); }   // 更新止盈止损线并重绘
    void setSigs(const QVector<SigMark> &s)   { sigs = s; update(); }   // 更新九转信号并重绘

    CandleChart() {   // 构造: 鼠标跟踪 + 手绘光标 + 星星动画
        setMouseTracking(true);   // 不按键也收move事件(十字光标)
        // 2026-09-24 22:09 用户指令: K线图变魔法棒 —— 悬停魔法棒光标, 按左键变可爱小手, 点击处闪星星
        setCursor(wandCursor());   // 默认显示魔法棒光标
        sparkTimer = new QTimer(this);   // 创建星星动画定时器(父对象托管自动释放)
        sparkTimer->setInterval(90);   // 90ms 一帧
        connect(sparkTimer, &QTimer::timeout, this, [this] {   // 每帧: 清理过期星星并重绘
            const qint64 now = QDateTime::currentMSecsSinceEpoch();   // 当前毫秒时刻
            sparkles.erase(std::remove_if(sparkles.begin(), sparkles.end(),   // erase+remove_if 惯用法删除超龄星星
                              [now](const Spark &s) { return now - s.born > 1200; }), sparkles.end());   // 存活超 1.2 秒的删掉
            if (sparkles.isEmpty()) sparkTimer->stop();   // 星星全灭 → 停定时器省 CPU
            update();   // 重绘画布
        });
    }

    // 魔法棒/小手光标: 2026-09-25 用户(emoji 在 Windows Server 显示为黑色方块) → 手绘 pixmap
    QCursor wandCursor() const { return makeWandCursor(); }   // 取魔法棒光标(悬停态)
    QCursor handCursor() const { return makeHandCursor(); }   // 取小手光标(按下拖动态)

    // 由x坐标推算K线索索引(用于点击/移动显示OHLC)
    int idxAtX(int x) const {   // 屏幕坐标 x → K线数组下标
        const int M_L = 8, M_R = 64;   // 左右边距(与 paintEvent 保持一致)
        int end = viewEnd < 0 ? candles.size() : qBound(1, viewEnd, candles.size());   // 当前视口右端下标
        int beg = qMax(0, end - viewCount);   // 视口左端下标
        int n = end - beg;   // 视口内根数
        if (n <= 0) return -1;   // 无数据返回无效下标
        double cw = double(width() - M_L - M_R) / (n + 10);   // 左对齐: 右侧留10根K线宽空白(与paintEvent一致)
        int i = beg + int((x - M_L) / cw);   // 按每根宽度换算命中的下标
        return qBound(beg, i, end - 1);   // 夹回视口合法范围
    }

    void dbgLog(const QString &m) const {   // 调试日志: 追加写入本地 _chart.log
        QFile f("E:/finally-main/desktop-cpp/_chart.log");   // 日志文件路径(写死)
        if (f.open(QIODevice::Append)) f.write((QTime::currentTime().toString("HH:mm:ss ") + m + "\n").toUtf8());   // 打开成功则写"时刻+内容+换行"
    }
protected:   // 以下为覆写的鼠标/滚轮/绘制事件
    void mousePressEvent(QMouseEvent *e) override {   // 鼠标按下
        if (e->button() == Qt::LeftButton) {   // 只处理左键
            setCursor(handCursor());   // 按左键 → 可爱小手
            const qint64 now = QDateTime::currentMSecsSinceEpoch();   // 当前毫秒时刻
            const QPointF pos = e->position();   // 按下位置
            auto *rng = QRandomGenerator::global();   // 全局随机数发生器
            for (int i = 0; i < 3; ++i)   // 点击处闪3颗星星
                sparkles.push_back({pos + QPointF(rng->bounded(48) - 24, rng->bounded(48) - 24), now});   // 在点击点 ±24px 内随机撒星
            sparkTimer->start();   // 启动星星动画
            if (!candles.isEmpty()) {   // 有K线才允许进入拖动状态
                dragging = true; dragStartX = e->position().toPoint().x();   // 记录拖动起点 x
                dragStartEnd = viewEnd < 0 ? candles.size() : viewEnd;   // 记录拖动起点的视口右端
            }
        }
        hoverIdx = idxAtX(int(e->position().x()));   // 更新悬停K线下标(显示 OHLC 信息条)
        update();   // 重绘
    }
    void mouseMoveEvent(QMouseEvent *e) override {   // 鼠标移动(拖动平移视口 / 悬停十字光标)
        hoverIdx = idxAtX(int(e->position().x()));   // 点击/移动都显示当前K线信息
        if (!dragging || candles.isEmpty()) { update(); return; }   // 未拖动或无数据 → 只刷新十字光标
        const int M_L = 8, M_R = 64;   // 左右边距(与绘制一致)
        // 实际视口根数(缩放后 != viewCount 时也正确)
        int end = viewEnd < 0 ? candles.size() : qBound(1, viewEnd, candles.size());   // 视口右端下标
        int n = qMax(1, end - qMax(0, end - viewCount));   // 视口内根数(至少 1 防除零)
        double cw = double(width() - M_L - M_R) / (n + 10);   // 左对齐: 右侧留10根K线宽空白(与paintEvent一致)
        int shift = int((e->position().toPoint().x() - dragStartX) / cw);  // 2026-09-26 用户修复: 往左拖=看更早的K线(原方向反了,注释与代码不符)
        viewEnd = qBound(viewCount, dragStartEnd + shift, candles.size());   // 平移视口右端并夹在 [viewCount, 总数] 范围
        // 2026-09-26: 翻到最老K线附近自动补拉更早历史
        int begNow = qMax(0, qBound(1, viewEnd, candles.size()) - viewCount);   // 当前视口左端下标
        if (begNow <= 2 && !loadingOld && requestOlder) requestOlder();   // 快翻到最老 → 触发外部补拉更早历史
        update();   // 重绘
    }
    void mouseReleaseEvent(QMouseEvent *e) override {   // 鼠标松开
        dragging = false; hoverIdx = idxAtX(int(e->position().x()));   // 结束拖动, 刷新悬停下标
        setCursor(wandCursor());   // 松开 → 变回魔法棒
        update();   // 重绘
    }
    void leaveEvent(QEvent *) override { hoverIdx = -1; update(); }   // 鼠标移出控件 → 清除十字光标

    // 滚轮缩放K线: 上滚放大(根数变少) 下滚缩小(看到更多)。
    // 2026-09-25 01:09 用户修复: 缩放以【鼠标当前位置所指的K线】为锚点 ——
    // 缩放前后同一根K线始终停留在鼠标 x 位置(而不是视口右端/最新K线)。
    void wheelEvent(QWheelEvent *e) override {   // 滚轮缩放
        if (candles.isEmpty()) return;   // 无数据直接忽略
        const int M_L = 8, M_R = 64;   // 左右边距
        int end1 = viewEnd < 0 ? candles.size() : qBound(1, viewEnd, candles.size());   // 缩放前视口右端
        int beg1 = qMax(0, end1 - viewCount);   // 缩放前视口左端
        int n1 = qMax(1, end1 - beg1);   // 缩放前视口根数
        double plotW = double(width() - M_L - M_R);   // 绘图区总宽
        double t = qBound(0.0, (e->position().x() - M_L) / plotW, 1.0);   // 鼠标在视口内的相对位置0~1
        double anchor = beg1 + t * (n1 + 10);   // 鼠标指向的K线索引(含右侧空白外插)

        int old = viewCount;   // 记录缩放前根数
        if (e->angleDelta().y() > 0)   // 上滚
            viewCount = qMax(20, viewCount * 8 / 10);                       // 放大
        else   // 下滚
            viewCount = qMin(qMax(1000, candles.size()), viewCount * 14 / 10 + 2); // 缩小
        if (viewCount == old) return;   // 已到极限根数 → 不重绘

        // 锚点不变: 缩放后 anchor 这根K线仍落在鼠标 x 处 → 反解新的视口右端 viewEnd
        // i2 = beg2 + t*(n2+10), beg2=end2-viewCount, n2=viewCount → end2 = anchor + viewCount*(1-t) - 10*t
        int end2 = int(anchor + viewCount * (1.0 - t) - 10.0 * t + 0.5);   // 反解缩放后的视口右端
        viewEnd = qBound(viewCount, end2, candles.size());   // 夹在合法范围
        if (viewEnd >= candles.size()) viewEnd = candles.size();   // 右端=最新
        // 2026-09-26: 缩小到最老K线附近也自动补拉更早历史
        {
            int begNow = qMax(0, qBound(1, viewEnd, candles.size()) - viewCount);   // 缩放后视口左端
            if (begNow <= 2 && !loadingOld && requestOlder) requestOlder();   // 快翻到最老 → 补拉历史
        }
        update();   // 重绘
    }

    void paintEvent(QPaintEvent *) override {   // 全部绘制逻辑(核心)
        QPainter p(this);   // 画笔绑定本控件
        p.setRenderHint(QPainter::Antialiasing, true);   // 抗锯齿(用户: K线太难看)
        p.fillRect(rect(), QColor(255,255,255));   // 白色底
        if (candles.isEmpty()) {   // 无数据
            p.setPen(QColor(0x88,0x88,0x88));   // 灰色文字
            p.drawText(rect(), Qt::AlignCenter, QStringLiteral("等待K线数据..."));   // 居中提示
            return;   // 结束绘制
        }
        const int M_L = 8, M_R = 64, M_T = 26, M_B = 22;   // 绘图区四周边距
        QRect plot(M_L, M_T, width() - M_L - M_R, height() - M_T - M_B);   // 绘图区矩形

        // ---- 视口: 只取最近 viewCount 根 ----
        int end = viewEnd < 0 ? candles.size() : qBound(1, viewEnd, candles.size());   // 视口右端下标
        int beg = qMax(0, end - viewCount);   // 视口左端下标
        int n = end - beg;   // 视口根数
        if (n <= 0 || beg >= candles.size() || end - 1 >= candles.size()) {   // 视口数据异常
            viewEnd = candles.size();          // 视口异常自愈: 回到最新
            end = candles.size();   // 右端=总数
            beg = qMax(0, end - viewCount);   // 重算左端
            n = end - beg;   // 重算根数
            if (n <= 0) return;   // 仍无数据则放弃
        }
        const Candle &cBeg = candles[beg];   // 视口最早一根(取引用备用)
        const Candle &cEnd = candles[end - 1];   // 视口最新一根

        // Y轴只按可见K线范围自适应(不含全量历史)
        double lo = 1e18, hi = -1e18;   // 价格区间上下界初值
        for (int i = beg; i < end; i++) { lo = qMin(lo, candles[i].l); hi = qMax(hi, candles[i].h); }   // 扫描视口内所有K线的最低/最高
        if (hi - lo < 1e-12) hi = lo + 1;   // 区间过窄防除零
        // 止盈止损线仅在K线范围±1倍内参与定轴, 更远的直接裁掉不撑大Y轴
        double span = hi - lo;   // 当前价格跨度
        for (const auto &l : lines)   // 遍历止盈止损线
            if (l.px >= lo - span && l.px <= hi + span) { lo = qMin(lo, l.px); hi = qMax(hi, l.px); }   // 附近的线参与定轴
        // 信号标注不参与定轴(2026-09-24 用户指令: 别拉高或者拉低K线图)
        if (hi - lo < 1e-12) hi = lo + 1;   // 再防一次除零
        double pad = (hi - lo) * 0.05; lo -= pad; hi += pad;   // 上下各留 5% 空隙
        auto yOf = [&](double v) { return plot.bottom() - (v - lo) / (hi - lo) * plot.height(); };   // 价格 → 屏幕 y
        double cw = double(plot.width()) / (n + 10);   // 左对齐(用户指令): K线整体往左挪10根宽度, 右侧留白不顶边界
        auto xOf = [&](int i) { return plot.left() + cw * (i - beg + 0.5); };   // 下标 → 该根K线中心 x

        // ---- 指标序列: MA5/MA10/MA20 + 布林带(20,2) (全量计算, 只画视口; 盈利K线精简模式不画) ----
        QVector<double> ma5, ma10, ma20, bUp, bLo;   // 各指标序列
        if (!simple) {   // 精简模式跳过指标计算
        auto maSeries = [&](int period) {   // 计算一条简单移动均线
            QVector<double> out(candles.size(), qQNaN());   // 输出序列, 前段无效填 NaN
            for (int i = period - 1; i < candles.size(); i++) {   // 从第 period 根起有效
                double s = 0;   // 窗口内收盘价累加
                for (int j = i - period + 1; j <= i; j++) s += candles[j].c;   // 最近 period 根收盘求和
                out[i] = s / period;   // 均值
            }
            return out;   // 返回序列
        };
        ma5 = maSeries(5); ma10 = maSeries(10); ma20 = maSeries(20);   // 三条均线
        bUp = QVector<double>(candles.size(), qQNaN()); bLo = QVector<double>(candles.size(), qQNaN());   // 布林上下轨序列
        for (int i = 19; i < candles.size(); i++) {   // 布林带(20,2): 从第 20 根起
            double mean = ma20[i], var = 0;   // 均值=MA20, 方差初值
            for (int j = i - 19; j <= i; j++) var += (candles[j].c - mean) * (candles[j].c - mean);   // 20 根收盘对均值的方差和
            double sd = std::sqrt(var / 20.0);   // 标准差
            bUp[i] = mean + 2 * sd; bLo[i] = mean - 2 * sd;   // 上下轨 = 均值 ± 2倍标准差
        }
        }   // end if(!simple) 指标计算
        auto drawSeries = [&](const QVector<double> &v, const QColor &col, const QPen &pen) {   // 画一条指标折线(断点即 NaN 跳过)
            QPen pp = pen; pp.setColor(col); p.setPen(pp);   // 套用颜色
            bool started = false;   // 是否已落第一笔
            for (int i = beg; i < end; i++) {   // 只画视口内
                if (qIsNaN(v[i])) { started = false; continue; }   // 无效值 → 断线
                if (!started) { p.drawPoint(QPointF(xOf(i), yOf(v[i]))); started = true; continue; }   // 第一笔只画点
                p.drawLine(QPointF(xOf(i - 1), yOf(v[i - 1])), QPointF(xOf(i), yOf(v[i])));   // 与前一根连线
            }
        };

        // 布林带区域(半透明底色)先画, 垫在K线下面 (盈利K线精简模式不画)
        if (!simple) {   // 精简模式跳过
        QPainterPath band;   // 布林带区域路径
        bool started = false;   // 是否已开始
        for (int i = beg; i < end; i++) {   // 上轨从左到右
            if (qIsNaN(bUp[i])) continue;   // 无效跳过
            if (!started) { band.moveTo(xOf(i), yOf(bUp[i])); started = true; }   // 起笔
            else band.lineTo(xOf(i), yOf(bUp[i]));   // 连线
        }
        for (int i = end - 1; i >= beg; i--) {   // 下轨从右往左(围成闭环)
            if (qIsNaN(bLo[i])) continue;   // 无效跳过
            band.lineTo(xOf(i), yOf(bLo[i]));   // 连线
        }
        p.setPen(Qt::NoPen);   // 不描边
        p.setBrush(QColor(0x19,0x71,0xc2,22));   // 半透明蓝填充
        p.drawPath(band);   // 画出带状底色
        }   // end if(!simple) 布林带

        // 网格 + 价格轴
        p.setFont(QFont("Microsoft YaHei", 7));   // 小号字体
        for (int g = 0; g <= 5; g++) {   // 画 6 条水平网格线
            double v = lo + (hi - lo) * g / 5.0;   // 均分价格区间的刻度值
            int y = int(yOf(v));   // 刻度对应屏幕 y
            p.setPen(QPen(QColor(0xee,0xee,0xee), 1));   // 浅灰网格线
            p.drawLine(plot.left(), y, plot.right(), y);   // 横贯绘图区
            p.setPen(QColor(0x66,0x66,0x66));   // 深灰刻度文字
            p.drawText(width() - M_R + 4, y + 4, formatPx(v));   // 右侧价格轴标注
        }
        // 时间轴(每 n/6 根; 跨天处标注 MM-DD 日期, 用户: 20-22日都是一条线 今天没显示 -> 加日期)
        int step = qMax(1, n / 6);   // 大约画 6 个时间刻度
        QDate prevDay;   // 上一个刻度的日期(跨天检测)
        for (int i = 0; i < n; i += step) {   // 按步长遍历视口
            QDateTime dt = QDateTime::fromMSecsSinceEpoch(candles[beg + i].t);   // 该根K线时刻
            QString lab = dt.toString("HH:mm");   // 默认只显示时:分
            if (dt.date() != prevDay) { lab = dt.toString("MM-dd HH:mm"); prevDay = dt.date(); }   // 跨天刻度补日期
            p.setPen(QColor(0x66,0x66,0x66));   // 深灰文字
            p.drawText(int(xOf(beg + i)) - 40, height() - 6, lab);   // 画在底部时间轴
        }
        // 标题
        p.setPen(QColor(0x22,0x22,0x22));   // 深色标题
        p.setFont(QFont("Microsoft YaHei", 9, QFont::Bold));   // 加粗中号字
        p.drawText(plot.left(), 17, lastTitle);   // 画在左上角

        // K线: 红涨绿跌(中国习惯) — 只画视口内
        for (int i = beg; i < end; i++) {   // 遍历视口内每根K线
            const auto &k = candles[i];   // 当根引用
            bool up = k.c >= k.o;   // 收盘≥开盘为阳线
            QColor col = up ? QColor(0xe0,0x31,0x31) : QColor(0x0c,0xa6,0x78);   // 红=涨 绿=跌
            double x = xOf(i);   // 中心 x
            double bodyTop = yOf(qMax(k.o, k.c)), bodyBot = yOf(qMin(k.o, k.c));   // 实体上下沿
            double bw = qMax(1.5, cw * 0.62);   // 实体宽(最窄1.5px)
            p.setPen(col);   // 描边用K线色
            p.setBrush(col);   // 填充同色
            // 影线
            p.drawLine(QPointF(x, yOf(k.h)), QPointF(x, yOf(k.l)));   // 最高到最低竖线
            // 实体(十字星画横线)
            if (bodyBot - bodyTop < 1)   // 开收几乎相等(十字星)
                p.drawLine(QPointF(x - bw / 2, bodyTop), QPointF(x + bw / 2, bodyTop));   // 画一条横线
            else   // 正常实体
                p.drawRect(QRectF(x - bw / 2, bodyTop, bw, bodyBot - bodyTop));   // 画矩形实体
        }
        // 指标线: 布林上下轨 / MA5 / MA10 / MA20 (盈利K线精简模式不画)
        if (!simple) {   // 精简模式跳过
        drawSeries(bUp, QColor(0xf7,0x67,0x00), QPen(Qt::SolidLine));   // 布林上轨(橙)
        drawSeries(bLo, QColor(0xf7,0x67,0x00), QPen(Qt::SolidLine));   // 布林下轨(橙)
        drawSeries(ma5,  QColor(0xf1,0xc4,0x0c), QPen(Qt::SolidLine));   // MA5(黄)
        drawSeries(ma10, QColor(0x0c,0xa6,0x78), QPen(Qt::SolidLine));   // MA10(绿)
        drawSeries(ma20, QColor(0xae,0x3e,0xc9), QPen(Qt::SolidLine));   // MA20(紫)
        // 指标图例(标题右侧)
        p.setFont(QFont("Microsoft YaHei", 7));   // 小号字
        int lx = plot.right() - 250;   // 图例起始 x
        p.setPen(QColor(0xf1,0xc4,0x0c)); p.drawText(lx, 17, "MA5");   // 图例 MA5
        p.setPen(QColor(0x0c,0xa6,0x78)); p.drawText(lx + 32, 17, "MA10");   // 图例 MA10
        p.setPen(QColor(0xae,0x3e,0xc9)); p.drawText(lx + 68, 17, "MA20");   // 图例 MA20
        p.setPen(QColor(0xf7,0x67,0x00)); p.drawText(lx + 108, 17, "BOLL(20,2)");   // 图例 布林带
        }   // end if(!simple) 指标线+图例
        // 止盈/止损线
        for (const auto &l : lines) {   // 遍历止盈止损线
            int y = int(yOf(l.px));   // 价格对应屏幕 y
            p.setPen(Qt::DashLine);   // 先设虚线样式
            p.setPen(l.col);   // 再套线色
            p.drawLine(plot.left(), y, plot.right(), y);   // 横贯绘图区的水平线
            p.setPen(l.col);   // 标签同色
            p.drawText(plot.left() + 4, y - 3, l.label);   // 左上侧画"止盈/止损+价格"
        }
        // 信号标注(2026-09-24 深夜用户指令): 买入🚀/加仓🚀 在K线下方不远处(带时间HH:MM, 不拉高拉低K线图),
        // 平仓 🍃 绿叶 + 盈利两位小数 在K线上方
        p.setFont(QFont("Microsoft YaHei", 7));   // 小号字
        for (const auto &m : markers) {   // 遍历买卖点标注
            int ci = -1;   // 标注挂靠的K线下标
            for (int i = beg; i < end; i++)
                if (candles[i].t <= m.t) ci = i;   // 标注时刻所在(或之前最近)的K线
            if (ci < 0) continue;   // 视口内无挂靠K线 → 跳过
            double x = xOf(ci);   // 挂靠K线中心 x
            QString tm = QDateTime::fromMSecsSinceEpoch(m.t).toString("HH:mm");   // 标注时刻 HH:MM
            if (m.kind == 2) { // 平仓: 🍃 + 盈利两位小数
                double yTop = yOf(candles[ci].h) - 8;   // 挂在该K线最高价上方
                yTop = qMax(plot.top() + 34.0, yTop);   // 不越过顶部标题区
                p.setPen(QColor(0x0c, 0xa6, 0x78));   // 绿色
                p.drawText(QPointF(x - 7, yTop - 7), QStringLiteral("🍃"));   // 画绿叶
                QString pf = (m.profit >= 0 ? QStringLiteral("+") : QString()) + QString::number(m.profit, 'f', 2);   // 盈亏带符号两位小数
                p.drawText(QPointF(x - 16, yTop + 5), tm + ' ' + pf);   // 时间+盈亏
            } else {           // 买入/加仓: 🚀 + 时间
                double yBot = yOf(candles[ci].l) + 6;   // 挂在该K线最低价下方
                yBot = qMin(plot.bottom() - 28.0, yBot);   // 不越过底部时间轴
                p.setPen(QColor(0xe0, 0x31, 0x31));   // 红色
                p.drawText(QPointF(x - 7, yBot + 11), QStringLiteral("🚀"));   // 画火箭
                p.drawText(QPointF(x - 16, yBot + 23),   // 下一行: 类型+时间
                           (m.kind == 0   // 0=买入, 其余=加仓
                                ? QStringLiteral("买") + (m.strat.isEmpty() ? QString() : QStringLiteral(" ") + m.strat) + QStringLiteral(" ")
                                : QStringLiteral("加 ")) + tm);   // "买[策略]"或"加" + HH:MM
            }
        }

    // K线信号标注(2026-09-26 三次精简: 删除1号指标triple_ma_bull, 只留td_nine九转): K线下方远距
    // 九5~九9计数标注(紫), 九9底=红(止跌预警)/九9顶=绿(滞涨预警); 虚线连到信号柱。后台算好存库, 客户端只读。
    if (!simple && !sigs.isEmpty()) {   // 精简模式或无信号则跳过
        QHash<qint64, int> sidx;   // 时间戳 → 信号掩码
        for (const auto &s : sigs) sidx[s.t] = s.mask;   // 建索引
        p.setFont(QFont("Microsoft YaHei", 7, QFont::Bold));   // 加粗小字
        const double laneY[2] = { plot.bottom() - 5.0, plot.bottom() - 15.0 };   // 两车道贴底, 离K线柱远
        double laneX[2] = { -1e9, -1e9 };   // 两车道上次占用 x(初始为极远)
        QPen dashPen(QColor(0xb1, 0x97, 0xfc), 1, Qt::DashLine);   // 淡紫虚线笔
        for (int i = beg; i < end; i++) {   // 遍历视口内K线
            auto it = sidx.find(candles[i].t);   // 查该根是否有信号
            if (it == sidx.end() || it.value() == 0) continue;   // 无信号跳过
            const int m = it.value();   // 信号掩码
            // 标准神奇九转: 位2~5=计数5~9, 位6=顶序列, 位7=底序列
            const int tdPh = (m >> 2) & 15;   // 取九转计数字段
            QString label;   // 待画的标注文字
            if ((m & 2) && tdPh >= 5) label = QStringLiteral("九") + QString::number(tdPh);   // 仅显示"九5"~"九9"
            if (label.isEmpty()) continue;   // 不需要标注跳过
            double x = xOf(i);   // 信号柱中心 x
            if (x < plot.left() + 6 || x > plot.right() - 6) continue;   // 太靠边不画
            int lane = (x - laneX[0] >= 16.0) ? 0 :   // 车道0有 16px 空隙就用 0
                       ((x - laneX[1] >= 16.0) ? 1 : -1);   // 两车道都挤则只画虚线不画字
            double ly = lane >= 0 ? laneY[lane] : laneY[0];   // 标注 y 坐标
            p.setPen(dashPen);   // 虚线笔
            p.drawLine(QPointF(x, yOf(candles[i].l) + 3), QPointF(x, ly - 4));   // 从信号柱底虚线连到标注
            if (lane >= 0) {   // 有空车道才画字
                // 颜色: 九9底=红(止跌预警) · 九9顶=绿(滞涨预警) · 其余=紫
                QColor col(0x70, 0x48, 0xe8);   // 默认紫色
                if (tdPh == 9) col = (m & 128) ? QColor(0xe0, 0x31, 0x31) : QColor(0x0c, 0xa6, 0x78);   // 九9: 位7区分底(红)/顶(绿)
                p.setPen(col);   // 套色
                p.drawText(QPointF(x - 14, ly), label);   // 画"九N"
                laneX[lane] = x;   // 记录该车道占用位置
            }
        }
    }

        // ---- 十字光标: 点击/移动显示当前K线 时间+开高低收+涨跌幅 ----
        if (hoverIdx >= beg && hoverIdx < end) {   // 悬停在视口内有效K线上
            const Candle &k = candles[hoverIdx];   // 当前K线
            double x = xOf(hoverIdx);   // 中心 x
            p.setPen(QPen(QColor(0x88,0x88,0x88), 1, Qt::DashLine));   // 灰色虚线笔
            p.drawLine(QPointF(x, plot.top()), QPointF(x, plot.bottom()));   // 竖向十字线
            double chg = k.o > 0 ? (k.c - k.o) / k.o * 100 : 0;   // 该根涨跌幅%
            QString info = QStringLiteral("%1   开:%2  高:%3  低:%4  收:%5  %6%7%")   // 信息条模板
                               .arg(QDateTime::fromMSecsSinceEpoch(k.t).toString("yyyy-MM-dd HH:mm"),   // 时间
                                    formatPx(k.o), formatPx(k.h), formatPx(k.l), formatPx(k.c),   // 开高低收
                                    chg >= 0 ? QStringLiteral("+") : QStringLiteral(""),   // 涨跌符号
                                    QString::number(chg, 'f', 2));   // 涨跌幅两位小数
            QFont bf = p.font();   // 备份当前字体
            p.setFont(QFont("Microsoft YaHei", 8, QFont::Bold));   // 信息条用加粗字
            QFontMetrics fm(p.font());   // 量字宽
            int tw = fm.horizontalAdvance(info) + 20;   // 信息条宽度=文字宽+边距
            QRect box(plot.left() + 2, M_T + 2, tw, 22);   // 信息条矩形(顶部)
            p.setPen(QColor(0x33,0x33,0x33));   // 深色边
            p.setBrush(QColor(255,255,255,235));   // 近不透明白底
            p.drawRect(box);   // 画信息条底框
            p.setPen(chg >= 0 ? QColor(0xe0,0x31,0x31) : QColor(0x0c,0xa6,0x78));   // 涨红跌绿文字
            p.drawText(box.adjusted(10, 0, -10, 0), Qt::AlignVCenter | Qt::AlignLeft, info);   // 写入信息文字
            p.setFont(bf);   // 恢复字体
        }

        // ---- 魔法棒星星: 点击处✨/⭐ 1.2秒渐隐闪烁(2026-09-24 22:09) ----
        if (!sparkles.isEmpty()) {   // 有存活星星才画
            const qint64 now = QDateTime::currentMSecsSinceEpoch();   // 当前时刻
            p.setFont(QFont(QStringLiteral("Segoe UI Emoji"), 26));   // 星星也放大一倍
            for (const auto &s : sparkles) {   // 逐颗画
                double age = double(now - s.born) / 1200.0;   // 归一化年龄 0~1
                if (age < 0 || age > 1) continue;   // 越界(理论上已删)跳过
                p.setOpacity(age < .5 ? .4 + age : 1.0 - (age - .5) * 1.6);   // 前半程渐亮, 后半程渐隐
                p.drawText(s.p, age < .5 ? QStringLiteral("✨") : QStringLiteral("⭐"));   // 前半闪✨ 后半闪⭐
            }
            p.setOpacity(1.0);   // 恢复不透明
        }
    }
    QString formatPx(double v) const {   // 价格格式化(按大小自适应小数位)
        if (simple) return QString::number(v, 'f', 2);   // 盈利K线: 累计USDT 两位小数
        if (v >= 100) return QString::number(v, 'f', 1);   // ≥100 留 1 位小数
        if (v >= 1)   return QString::number(v, 'f', 3);   // ≥1 留 3 位小数
        return QString::number(v, 'f', 6);   // 更小留 6 位
    }
};

// 自绘K线风格图标(深蓝底+红绿蜡烛), 主窗口/托盘/悬浮窗共用
static QPixmap makeCandleIcon(int size) {   // 生成 K线风格程序图标(指定边长)
    QPixmap pm(size, size);   // 正方形画布
    pm.fill(QColor(0x1e,0x2a,0x3a));   // 深蓝底色
    QPainter ip(&pm);   // 作画
    ip.setRenderHint(QPainter::Antialiasing);   // 抗锯齿
    double s = size / 256.0;   // 按256设计稿缩放
    ip.setPen(Qt::NoPen);   // 不描边
    ip.setBrush(QColor(0xe0,0x31,0x31));   // 红色蜡烛1
    ip.drawRect(QRectF(50*s, 70*s, 36*s, 90*s));  ip.drawLine(QPointF(68*s,40*s), QPointF(68*s,190*s));   // 实体+上下影线
    ip.setBrush(QColor(0x0c,0xa6,0x78));   // 绿色蜡烛2
    ip.drawRect(QRectF(120*s, 110*s, 36*s, 70*s)); ip.drawLine(QPointF(138*s,85*s), QPointF(138*s,210*s));   // 实体+影线
    ip.setBrush(QColor(0xe0,0x31,0x31));   // 红色蜡烛3
    ip.drawRect(QRectF(178*s, 55*s, 36*s, 100*s)); ip.drawLine(QPointF(196*s,30*s), QPointF(196*s,175*s));   // 实体+影线
    ip.end();   // 结束绘制
    return pm;   // 返回图标 pixmap
}

// ================================================================ 3D图表(自绘等轴投影, 无需QtCharts)
struct K3D { qint64 t; double o, h, l, c; };   // 3D图表用单根K线数据(时间+开高低收)

class Chart3D : public QWidget {   // 伪3D自绘图表控件(盈利K线/柱状/饼 三种模式)
public:   // 公开成员
    enum Mode { Candles, Bars, Pie };   // 图表模式枚举
    Mode mode;   // 当前模式
    QVector<K3D> candles;                        // Candles: 盈利K线(累计USDT)
    QVector<QPair<QString,double>> bars;         // Bars: 小时盈亏(红涨绿跌)
    QVector<QPair<QString,double>> slices;       // Pie: 占比构成
    QString title;   // 图表左上角标题

    Chart3D(Mode m, QWidget *p = nullptr) : QWidget(p), mode(m) { setMinimumHeight(230); }   // 构造: 记录模式, 最小高230
    void setCandles(const QVector<K3D> &c) { candles = c; update(); }   // 设置盈利K线数据并重绘
    void setBars(const QVector<QPair<QString,double>> &b) { bars = b; update(); }   // 设置柱状数据并重绘
    void setPie(const QVector<QPair<QString,double>> &s) { slices = s; update(); }   // 设置饼图数据并重绘

protected:   // 绘制相关
    void drawEmpty(QPainter &p) {   // 无数据时的占位提示
        p.setPen(QColor(0x99,0x99,0x99));   // 灰色文字
        p.setFont(QFont("Microsoft YaHei", 9));   // 中号字
        p.drawText(rect(), Qt::AlignCenter, QStringLiteral("数据加载中..."));   // 居中提示
    }
    void paintEvent(QPaintEvent *) override {   // 总绘制入口
        QPainter p(this);   // 画笔
        p.setRenderHint(QPainter::Antialiasing);   // 抗锯齿
        // 白底圆角卡片 + 细边(与网页端卡片同风格)
        p.setPen(QColor(0xe0,0xe5,0xef));   // 浅灰边框
        p.setBrush(QColor(0xff,0xff,0xff));   // 白底
        p.drawRoundedRect(rect().adjusted(1,1,-2,-2), 10, 10);   // 圆角卡片
        p.setClipRect(rect().adjusted(2,2,-2,-2));   // 裁剪到卡片内
        if (mode == Candles) paintCandles(p);   // 分模式派发绘制
        else if (mode == Bars) paintBars(p);   // 柱状
        else paintPie(p);   // 饼图
    }

    // ---- 3D 盈利K线: 等轴投影(背面体+顶面+正面金属渐变), 红涨绿跌 ----
    void paintCandles(QPainter &p) {   // 3D 盈利K线绘制
        const int M = 40, MT = 28;   // 边距
        QRect plot(M, MT, width() - M - 12, height() - MT - M);   // 绘图区
        p.setPen(QColor(0x22,0x22,0x22)); p.setFont(QFont("Microsoft YaHei", 9, QFont::Bold));   // 标题字体
        p.drawText(plot.left(), 18, title);   // 画标题
        if (candles.isEmpty()) { drawEmpty(p); return; }   // 无数据画占位
        int n = qMin(60, candles.size());   // 最多画最近 60 根
        int beg = candles.size() - n;   // 起始下标
        double lo = 1e18, hi = -1e18;   // 价格区间初值
        for (int i = beg; i < candles.size(); ++i) { lo = qMin(lo, candles[i].l); hi = qMax(hi, candles[i].h); }   // 扫描最低/最高
        if (hi - lo < 1e-9) hi = lo + 1;   // 防除零
        double pad = (hi - lo) * 0.08; lo -= pad; hi += pad;   // 上下各留 8% 空隙
        auto yOf = [&](double v) { return plot.bottom() - (v - lo) / (hi - lo) * plot.height(); };   // 价格→y
        double cw = double(plot.width()) / (n + 10);   // 左对齐(用户指令): K线整体往左挪10根宽度, 右侧留白不顶边界
        p.setFont(QFont("Microsoft YaHei", 7));   // 刻度小字
        for (int g = 0; g <= 4; ++g) {   // 5 条水平网格
            double v = lo + (hi - lo) * g / 4;   // 均分刻度值
            int y = int(yOf(v));   // 刻度 y
            p.setPen(QPen(QColor(0xee,0xee,0xee), 1)); p.drawLine(plot.left(), y, plot.right(), y);   // 画网格线
            p.setPen(QColor(0x99,0x99,0x99));   // 灰色刻度文字
            p.drawText(plot.right() + 2, y + 4, QString::number(v, 'f', 1));   // 右侧价格轴
        }
        const double ddx = 6, ddy = -4;   // 等轴深度向量
        for (int i = beg; i < candles.size(); ++i) {   // 逐根画 3D 蜡烛
            const auto &k = candles[i];   // 当根引用
            bool up = k.c >= k.o;   // 阳线判断
            QColor col = up ? QColor(0xe0,0x31,0x31) : QColor(0x0c,0xa6,0x78);   // 红涨绿跌
            QColor dark = col.darker(135);   // 同色加深(背光面)
            double x = plot.left() + cw * (i - beg + 0.5);   // 中心 x
            double yT = yOf(qMax(k.o, k.c)), yB = yOf(qMin(k.o, k.c));   // 实体上下沿
            double bw = qMax(2.0, cw * 0.55);   // 实体宽(最窄2px)
            if (yB - yT < 2) { yT -= 1; yB = yT + 2; }   // 实体太薄强制 2px 可见
            p.setPen(Qt::NoPen);   // 不描边
            // 背面体
            p.setBrush(dark);   // 深色
            p.drawRect(QRectF(x - bw/2 + ddx, yT + ddy, bw, yB - yT));   // 向深度方向偏移的背面矩形
            // 顶面(平行四边形)
            QPainterPath top;   // 顶面路径
            top.moveTo(x - bw/2, yT); top.lineTo(x + bw/2, yT);   // 前上边
            top.lineTo(x + bw/2 + ddx, yT + ddy); top.lineTo(x - bw/2 + ddx, yT + ddy);   // 后上边(斜切)
            p.drawPath(top);   // 画顶面
            // 影线 前+后
            p.setPen(QPen(dark, 1));   // 深色影线(后)
            p.drawLine(QPointF(x + ddx, yOf(k.h) + ddy), QPointF(x + ddx, yOf(k.l) + ddy));   // 背面影线
            p.setPen(QPen(col, 1));   // 主色影线(前)
            p.drawLine(QPointF(x, yOf(k.h)), QPointF(x, yOf(k.l)));   // 正面影线
            // 正面: 金属渐变(凹凸有致)
            QLinearGradient g(x - bw/2, yT, x + bw/2, yB);   // 对角渐变
            g.setColorAt(0, col.lighter(135)); g.setColorAt(0.5, col); g.setColorAt(1, dark);   // 亮→本色→暗
            p.setBrush(QBrush(g));   // 渐变填充
            p.setPen(QPen(col.darker(110), 1));   // 微深描边
            p.drawRect(QRectF(x - bw/2, yT, bw, yB - yT));   // 画正面实体
        }
    }

    // ---- 3D 柱状图: 顶面+侧面+正面渐变, 红涨绿跌 ----
    void paintBars(QPainter &p) {   // 3D 柱状图绘制
        const int M = 12, MB = 30;   // 边距
        QRect plot(M, 30, width() - M - 12, height() - 30 - MB);   // 绘图区
        p.setPen(QColor(0x22,0x22,0x22)); p.setFont(QFont("Microsoft YaHei", 9, QFont::Bold));   // 标题字
        p.drawText(plot.left(), 18, title);   // 画标题
        if (bars.isEmpty()) { drawEmpty(p); return; }   // 无数据占位
        double hi = 1e-9;   // 绝对值最大盈亏
        for (auto &b : bars) hi = qMax(hi, qAbs(b.second));   // 扫描最大值
        if (hi < 1e-9) hi = 1;   // 防除零
        auto yOf = [&](double v) { return plot.center().y() - v / hi * plot.height() / 2; };   // 盈亏→y(以零轴为中心上下分布)
        double bw = qMax(3.0, double(plot.width()) / bars.size() * 0.55);   // 柱宽(最窄3px)
        double step = double(plot.width()) / bars.size();   // 每柱步距
        p.setFont(QFont("Microsoft YaHei", 7));   // 小字
        p.setPen(QPen(QColor(0xee,0xee,0xee), 1));   // 浅灰线
        p.drawLine(plot.left(), int(yOf(0)), plot.right(), int(yOf(0)));   // 画零轴基线
        const double ddx = 5, ddy = -4;   // 等轴深度向量
        int lb = qMax(1, bars.size() / 6);   // 底部标签抽样步距(约6个)
        for (int i = 0; i < bars.size(); ++i) {   // 逐柱绘制
            double v = bars[i].second;   // 该柱盈亏值
            bool up = v >= 0;   // 正=涨
            QColor col = up ? QColor(0xe0,0x31,0x31) : QColor(0x0c,0xa6,0x78);   // 红涨绿跌
            QColor dark = col.darker(135);   // 加深侧色
            double x = plot.left() + step * (i + 0.5);   // 柱中心 x
            double yT = yOf(qMax(0.0, v)), yB = yOf(qMin(0.0, v));   // 柱上下沿(相对零轴)
            p.setPen(Qt::NoPen);   // 不描边
            // 顶面(仅正值) / 底面(负值)
            QPainterPath cap;   // 顶/底面路径
            cap.moveTo(x - bw/2, yT); cap.lineTo(x + bw/2, yT);   // 前边
            cap.lineTo(x + bw/2 + ddx, yT + ddy); cap.lineTo(x - bw/2 + ddx, yT + ddy);   // 后边(斜切)
            p.setBrush(dark.lighter(110)); p.drawPath(cap);   // 稍亮填充
            // 侧面
            QPainterPath side;   // 侧面路径
            side.moveTo(x + bw/2, yT); side.lineTo(x + bw/2 + ddx, yT + ddy);   // 右上斜边
            side.lineTo(x + bw/2 + ddx, yB + ddy); side.lineTo(x + bw/2, yB);   // 右下斜边
            p.setBrush(dark); p.drawPath(side);   // 深色填充
            // 正面渐变
            QLinearGradient g(x - bw/2, yT, x + bw/2, yB);   // 对角渐变
            g.setColorAt(0, col.lighter(130)); g.setColorAt(1, col);   // 亮→本色
            p.setBrush(QBrush(g));   // 填充
            p.setPen(QPen(col.darker(110), 1));   // 描边
            p.drawRect(QRectF(x - bw/2, yT, bw, yB - yT));   // 画正面
            if (i % lb == 0) {   // 抽样画底部标签
                p.setPen(QColor(0x99,0x99,0x99));   // 灰字
                p.drawText(QRectF(plot.left() + step*i, plot.bottom() + 4, step, 22),   // 标签区域
                           Qt::AlignHCenter | Qt::AlignTop, bars[i].first);   // 居中写"N时"
            }
        }
    }

    // ---- 3D 饼图: 顶面椭圆扇区 + 下半圈侧壁(厚度感) ----
    void paintPie(QPainter &p) {   // 3D 饼图绘制
        double total = 0;   // 总量
        for (auto &s : slices) total += qMax(0.0, s.second);   // 累加各扇区(负值忽略)
        if (slices.isEmpty() || total <= 0) { drawEmpty(p); return; }   // 无有效数据占位
        static const QColor pal[] = {   // 6 色调色板
            QColor(0xe0,0x31,0x31), QColor(0x0c,0xa6,0x78), QColor(0x2f,0x6f,0xed),
            QColor(0xf1,0xc4,0x0c), QColor(0xae,0x3e,0xc9), QColor(0xf7,0x67,0x00)};
        int cx = int(width() * 0.34), cy = int(height() * 0.52);   // 椭圆中心(偏左给图例留位)
        int rx = int(qMin(width() * 0.30, height() * 0.30));   // 水平半径
        int ry = int(rx * 0.58);   // 纵向半径(压扁出3D透视感)
        int d = qMax(10, height() / 16);       // 3D 厚度
        QRectF ell(cx - rx, cy - ry, rx * 2.0, ry * 2.0);   // 顶面椭圆外接矩形
        // 侧壁: 下半圈(Qt角度180..360)逐度画竖条, 颜色按扇区归属加深
        for (int a = 180; a <= 360; a += 2) {   // 每2度一条竖线
            double f = ((90 - a + 720) % 360) / 360.0;   // 顺时针累计占比
            double acc = 0; int si = 0;   // 累计占比/扇区号
            for (int i = 0; i < slices.size(); ++i) {   // 找该角度所属扇区
                acc += qMax(0.0, slices[i].second) / total;   // 累加占比
                if (f <= acc + 1e-9) { si = i; break; }   // 命中即止
            }
            QColor c = pal[si % 6].darker(130);   // 该扇区色加深
            double t = a * 3.14159265358979 / 180;   // 角度转弧度
            double ex = cx + rx * std::cos(t), ey = cy - ry * std::sin(t);   // 椭圆边缘点
            p.setPen(QPen(c, 2));   // 竖条颜色
            p.drawLine(QPointF(ex, ey), QPointF(ex, ey + d));   // 向下画厚度竖条
        }
        // 顶面扇区(渐变高光)
        double start = 90 * 16;   // 从 12 点方向开始(Qt 1/16度 单位)
        for (int i = 0; i < slices.size(); ++i) {   // 逐扇区画
            double frac = qMax(0.0, slices[i].second) / total;   // 该扇区占比
            if (frac <= 0) continue;   // 无占比跳过
            int span = int(-frac * 360 * 16);   // 负=顺时针
            QColor col = pal[i % 6];   // 调色板取色
            QLinearGradient g(cx - rx, cy - ry, cx + rx, cy + ry);   // 对角渐变高光
            g.setColorAt(0, col.lighter(125)); g.setColorAt(1, col);   // 亮→本色
            p.setBrush(QBrush(g));   // 填充
            p.setPen(QPen(col.darker(115), 1));   // 描边
            p.drawPie(ell, int(start), span);   // 画扇区
            start += span;   // 累计起始角
        }
        // 图例(右侧): 色块+名称+占比
        p.setFont(QFont("Microsoft YaHei", 9));   // 中号字
        int ly = cy - slices.size() * 13;   // 图例起始 y(垂直居中)
        for (int i = 0; i < slices.size(); ++i) {   // 逐项画图例
            double frac = qMax(0.0, slices[i].second) / total;   // 占比
            p.setPen(Qt::NoPen);   // 色块不描边
            p.setBrush(pal[i % 6]);   // 同扇区色
            p.drawRect(cx + rx + 16, ly + i * 26 - 4, 12, 12);   // 12x12 色块
            p.setPen(QColor(0x33,0x33,0x33));   // 深灰文字
            p.drawText(cx + rx + 34, ly + i * 26 + 6,   // 色块右侧写文字
                       QStringLiteral("%1  %2  (%3%)").arg(slices[i].first,   // 名称
                       QString::number(slices[i].second, 'f', 0),   // 数值
                       QString::number(frac * 100, 'f', 1)));   // 百分比一位小数
        }
    }
};

// ================================================================ 悬浮窗(置顶小窗)
// emoji光标公共函数(悬浮窗用, 与K线图同款) —— 2026-09-25 改手绘(Windows Server 无 emoji 字体)
static QCursor makeEmojiCursor(const QString &emoji, int hotX, int hotY) {   // 按 emoji 名义选择手绘光标(参数保留兼容)
    Q_UNUSED(emoji);   // emoji 参数不再实际使用
    return emoji.contains(QChar(0x1FA84)) ? makeWandCursor() : makeHandCursor();   // 🪄(U+1FA84)→魔法棒, 其余→小手
}

class FloatWin : public QWidget {   // 置顶无边框悬浮小窗
public:   // 公开成员
    QLabel *pxLab, *pnlLab, *posLab;   // 价格/总盈亏/仓位 三行标签
    QPoint dragP;   // 拖动偏移(鼠标与窗口左上角差)
    bool dragging = false;   // 是否拖动中
    FloatWin() {   // 构造: 无边框+置顶+暗色底+三行内容
        setWindowFlags(Qt::FramelessWindowHint | Qt::WindowStaysOnTopHint | Qt::Tool);   // 无边框/总置顶/工具窗(不出现在任务栏)
        setWindowIcon(QIcon(makeCandleIcon(64)));   // 窗口图标
        setWindowTitle(QStringLiteral("OKX悬浮窗"));   // 窗口标题
        resize(236, 124);   // 固定小尺寸
        auto *row = new QHBoxLayout;   // 第一行: 图标+价格
        row->setSpacing(8);   // 间距
        auto *icLab = new QLabel;   // 图标标签
        icLab->setPixmap(makeCandleIcon(64).scaled(34, 34, Qt::KeepAspectRatio, Qt::SmoothTransformation));   // 34px 平滑缩放图标
        icLab->setFixedSize(34, 34);   // 固定尺寸
        row->addWidget(icLab);   // 加入行
        pxLab = new QLabel(QStringLiteral("加载中..."));   // 价格标签
        pxLab->setStyleSheet("color:#ffd9a0; font-family:'LiSu'; font-size:14pt; font-weight:bold;"); // 隶书四号(用户22:30)
        row->addWidget(pxLab, 1);   // 价格占满剩余宽
        pnlLab = new QLabel(QStringLiteral("总盈亏: -"));   // 总盈亏标签
        pnlLab->setAlignment(Qt::AlignCenter);   // 居中
        pnlLab->setStyleSheet("color:#ff8787; font-family:'LiSu'; font-size:14pt;");   // 隶书红字
        posLab = new QLabel(QStringLiteral("仓位: - USDT"));   // 2026-09-25 用户: 悬浮窗多加一行仓位
        posLab->setAlignment(Qt::AlignCenter);   // 居中
        posLab->setStyleSheet("color:#ffd9a0; font-family:'LiSu'; font-size:12pt;");   // 隶书金字
        auto *lay = new QVBoxLayout(this);   // 窗口主纵向布局
        lay->setContentsMargins(12, 8, 12, 8);   // 边距
        lay->setSpacing(2);   // 行距
        lay->addLayout(row);   // 第一行
        lay->addWidget(pnlLab);   // 第二行: 总盈亏
        lay->addWidget(posLab);   // 第三行: 仓位
        // 暗色金属底(用户22:30: 背景不能白色) + 魔法棒光标/按下变小手
        setStyleSheet("QWidget#floatHost { background: qlineargradient(x1:0,y1:0,x2:0,y2:1,"
                      " stop:0 #434d66, stop:0.45 #2e3648, stop:0.55 #262d3d, stop:1 #1e2432);"
                      " border:1px solid #5a6478; border-radius:10px; }");   // 暗色渐变底+圆角描边
        setObjectName("floatHost");   // 绑定样式选择器
        setCursor(makeEmojiCursor(QStringLiteral("🪄"), 8, 52));   // 默认魔法棒光标
        setToolTip(QStringLiteral("悬浮窗: 拖动移动 | 双击打开主界面"));   // 悬浮提示
    }
    void setPx(const QString &inst, double px) {   // 更新价格行
        pxLab->setText(QStringLiteral("%1  %2").arg(inst.section('-', 0, 0),   // 只取合约币名(ETH)
                                                    QString::number(px, 'f', px >= 100 ? 1 : 4)));   // 大价1位小数, 小价4位
    }
    void setPnl(double net) {   // 更新总盈亏行(带颜色)
        pnlLab->setText(QStringLiteral("总盈亏: %1%2 USDT").arg(net >= 0 ? "+" : "-",   // 符号
                              QString::number(std::abs(net), 'f', 2)));   // 绝对值两位小数
        pnlLab->setStyleSheet(net >= 0 ? "color:#ff8787; font-family:'LiSu'; font-size:14pt;"   // 盈利红
                                       : "color:#69db7c; font-family:'LiSu'; font-size:14pt;");   // 亏损绿
    }
    void setPos(double m) {   // 2026-09-25 用户: 悬浮窗「仓位」行(当前持仓占用保证金USDT)
        posLab->setText(m > 0 ? QStringLiteral("仓位: %1 USDT").arg(QString::number(m, 'f', 2))   // 有持仓显示金额
                              : QStringLiteral("仓位: 0 USDT(无持仓)"));   // 无持仓提示
    }
protected:   // 鼠标事件(拖动/双击)
    void mousePressEvent(QMouseEvent *e) override {   // 按下开始拖动
        dragging = true; dragP = e->globalPosition().toPoint() - frameGeometry().topLeft();   // 记录鼠标相对窗口偏移
        setCursor(makeEmojiCursor(QStringLiteral("🤚"), 28, 12));   // 按下变可爱小手(用户22:30)
    }
    void mouseMoveEvent(QMouseEvent *e) override {   // 移动窗口跟随
        if (dragging) move(e->globalPosition().toPoint() - dragP);   // 按偏移同步窗口位置
    }
    void mouseReleaseEvent(QMouseEvent *) override {   // 松开结束拖动
        dragging = false;   // 复位
        setCursor(makeEmojiCursor(QStringLiteral("🪄"), 8, 52));    // 松开回魔法棒
    }
    void mouseDoubleClickEvent(QMouseEvent *) override { if (onDouble) onDouble(); }   // 双击 → 回调(回主界面)
public:   // 对外回调
    std::function<void()> onDouble;   // 双击回调(由主窗口注入)
};

// ================================================================ AI报表中心弹窗(日报/调参/反思 + 3D图表, 应用内直接看)
class ReportCenter : public QDialog {   // AI 报表中心对话框
public:   // 公开成员
    Chart3D *c3d, *b3d, *p3d;   // 三张 3D 图: 盈利K线/小时盈亏柱/构成饼
    QTextBrowser *repView, *tuneView, *reflView;   // 三页报表文本: 日报/调参/反思
    QNetworkAccessManager *nam;   // 网络管理器(拉报表数据)

    ReportCenter(QWidget *parent = nullptr) : QDialog(parent) {   // 构造: 搭界面并立即加载数据
        setWindowTitle(QStringLiteral("AI 报表中心 · ETH模式 · 日报 / 调参 / 反思 + 3D图表"));   // 标题
        resize(1480, 920);   // 大窗尺寸
        auto *lay = new QVBoxLayout(this);   // 主布局
        auto *tabs = new QTabWidget(this);   // 三个选项卡

        // ---- 日报页: 3D盈利K线 + 3D小时盈亏柱 + 3D盈亏构成饼 + 彩色HTML日报 ----
        auto *repTab = new QWidget;   // 日报页容器
        auto *rl = new QVBoxLayout(repTab);   // 页内布局
        rl->setSpacing(6);   // 间距
        auto *charts = new QHBoxLayout;   // 三图横排
        c3d = new Chart3D(Chart3D::Candles);   // 3D 盈利K线
        c3d->title = QStringLiteral("3D 盈利K线(累计USDT·1小时)");   // 图题
        b3d = new Chart3D(Chart3D::Bars);   // 3D 小时盈亏柱
        b3d->title = QStringLiteral("3D 小时盈亏(U) 红涨绿跌");   // 图题
        p3d = new Chart3D(Chart3D::Pie);   // 3D 构成饼
        p3d->title = QStringLiteral("3D 盈亏构成");   // 图题
        for (auto *c : {c3d, b3d, p3d}) c->setFixedHeight(255);   // 三图统一固定高
        charts->addWidget(c3d, 3); charts->addWidget(b3d, 2); charts->addWidget(p3d, 2);   // 宽度比例 3:2:2
        rl->addLayout(charts);   // 图表行
        repView = new QTextBrowser;   // 日报正文(支持HTML)
        rl->addWidget(repView, 1);   // 占满剩余高
        tabs->addTab(repTab, QStringLiteral("📘 AI日报"));   // 加入日报页
        tuneView = new QTextBrowser;   // 调参页文本
        tabs->addTab(tuneView, QStringLiteral("⚙️ AI调参"));   // 加入调参页
        reflView = new QTextBrowser;   // 反思页文本
        tabs->addTab(reflView, QStringLiteral("🪞 AI反思"));   // 加入反思页
        lay->addWidget(tabs, 1);   // 选项卡占满

        // ---- 底部按钮(立体渐变): 刷新 + 应用内打开三份Excel ----
        auto *row = new QHBoxLayout;   // 按钮行
        auto *refreshBtn = new QPushButton(QStringLiteral("🔄 刷新数据"));   // 刷新按钮
        connect(refreshBtn, &QPushButton::clicked, this, &ReportCenter::load);   // 点击重新加载
        auto *x1 = new QPushButton(QStringLiteral("📦 日报Excel"));   // 日报Excel按钮
        connect(x1, &QPushButton::clicked, this, [] {   // 点击用系统默认程序打开
            QDesktopServices::openUrl(QUrl::fromLocalFile(QStringLiteral("E:/finally-main/web/ai_report.xlsx")));   // 日报文件路径
        });
        auto *x2 = new QPushButton(QStringLiteral("📦 调参Excel"));   // 调参Excel按钮
        connect(x2, &QPushButton::clicked, this, [] {   // 点击打开
            QDesktopServices::openUrl(QUrl::fromLocalFile(QStringLiteral("E:/finally-main/web/ai_tune.xlsx")));   // 调参文件路径
        });
        auto *x3 = new QPushButton(QStringLiteral("📦 反思Excel"));   // 反思Excel按钮
        connect(x3, &QPushButton::clicked, this, [] {   // 点击打开
            QDesktopServices::openUrl(QUrl::fromLocalFile(QStringLiteral("E:/finally-main/web/ai_reflect.xlsx")));   // 反思文件路径
        });
        row->addWidget(refreshBtn);   // 加入刷新按钮
        row->addWidget(x1); row->addWidget(x2); row->addWidget(x3);   // 加入三个Excel按钮
        row->addStretch();   // 右侧弹性
        lay->addLayout(row);   // 按钮行入布局

        nam = new QNetworkAccessManager(this);   // 创建网络管理器(父托管)
        nam->setProxy(QNetworkProxy(QNetworkProxy::NoProxy));   // 直连不走系统代理
        load();   // 构造完成立即加载一次
    }

private:   // 内部工具与加载逻辑
    // 简易表格行 -> 弹窗顶部摘要HTML
    static QString metaHeader(const QVariantList &cells) {   // 拼"蓝底摘要条"HTML
        QString h = QStringLiteral("<div style='background:#eef3ff;border:1px solid #cfe0ff;"   // 摘要条外壳样式
                                   "border-radius:8px;padding:8px 12px;font-size:13px;color:#33415c;'>");
        for (const auto &c : cells) h += c.toString() + QStringLiteral("&nbsp;&nbsp;|&nbsp;&nbsp;");   // 各项以竖线分隔
        return h + QStringLiteral("</div>");   // 收尾
    }
    static QString plainToHtml(const QString &t) {   // 纯文本 → pre 样式 HTML(保留换行)
        return QStringLiteral("<pre style='white-space:pre-wrap;font-family:Microsoft YaHei;"
                              "font-size:13px;line-height:1.8;color:#1f2329;'>%1</pre>").arg(t.toHtmlEscaped());   // 转义后包 pre
    }
    void load() {   // 拉取全部报表数据(4个接口)
        // 3D 盈利K线(profit_curve1h, 1小时累计)
        QUrl u1(API); u1.setQuery("action=profitt");   // 构造请求
        auto *r1 = nam->get(QNetworkRequest(u1));   // 发起 GET
        connect(r1, &QNetworkReply::finished, this, [this, r1] {   // 完成回调
            r1->deleteLater();   // 防泄漏
            auto d = QJsonDocument::fromJson(r1->readAll()).object();   // 解析 JSON
            QVector<K3D> cs;   // K线容器
            for (auto v : d["data"].toArray()) {   // 逐条转换
                auto o = v.toObject();   // 当条对象
                cs.push_back({jnum64(o["candle_time"]), jnum(o["o"]), jnum(o["h"]), jnum(o["l"]), jnum(o["c"])});   // 时间+开高低收
            }
            c3d->setCandles(cs);   // 喂给 3D K线图
        });
        // 3D 小时盈亏柱(pnl_history 每小时增量, 近24小时)
        QUrl u2(API); u2.setQuery("action=pnlhist&h=24");   // 请求近24小时快照
        auto *r2 = nam->get(QNetworkRequest(u2));   // 发起 GET
        connect(r2, &QNetworkReply::finished, this, [this, r2] {   // 完成回调
            r2->deleteLater();   // 防泄漏
            auto d = QJsonDocument::fromJson(r2->readAll()).object();   // 解析
            QMap<qint64, double> hourly;                  // 小时->该小时最后快照
            for (auto v : d["data"].toArray()) {   // 逐条登记(同小时取最后)
                auto o = v.toObject();   // 当条
                hourly[jnum64(o["ts"]) / 3600] = jnum(o["pnl"]);   // 秒→小时为键
            }
            QList<qint64> hs = hourly.keys();   // 全部小时键
            std::sort(hs.begin(), hs.end());   // 按时间排序
            QVector<QPair<QString,double>> bars;   // 柱数据
            double prev = 0;   // 上一小时累计
            if (!hs.isEmpty()) prev = hourly[hs.first()];   // 初始化为最早小时
            for (qint64 h : hs) {   // 逐小时算增量
                double v = hourly[h];   // 该小时累计
                bars.push_back({QDateTime::fromSecsSinceEpoch(h * 3600).toString(QStringLiteral("H时")), v - prev});   // 标签+增量
                prev = v;   // 滚动更新
            }
            while (bars.size() > 24) bars.removeFirst();   // 只留最近24根
            b3d->setBars(bars);   // 喂给 3D 柱图
        });
        // 3D 盈亏构成饼(盈利轮/亏损轮)
        QUrl u3(API); u3.setQuery("action=stats");   // 统计接口
        auto *r3 = nam->get(QNetworkRequest(u3));   // 发起 GET
        connect(r3, &QNetworkReply::finished, this, [this, r3] {   // 完成回调
            r3->deleteLater();   // 防泄漏
            auto d = QJsonDocument::fromJson(r3->readAll()).object();   // 解析
            auto s = d["data"].toObject()["summary"].toObject();   // 汇总对象
            int total = (int)jnum(s["total"]), wins = (int)jnum(s["wins"]);   // 总轮数/盈利轮数
            if (total > 0)   // 有交易才画
                p3d->setPie({{QStringLiteral("盈利轮"), double(wins)},   // 盈利扇区
                             {QStringLiteral("亏损轮"), double(total - wins)}});   // 亏损扇区
        });
        // 三张报表(数据库 ai_report / ai_tune / ai_reflect)
        fetch(QStringLiteral("report"));   // 日报
        fetch(QStringLiteral("tune"));   // 调参
        fetch(QStringLiteral("reflect"));   // 反思
    }
    void fetch(const QString &type) {   // 拉一种报表并渲染到对应页
        QUrl u(API); u.setQuery(QStringLiteral("action=aireport&type=%1").arg(type));   // 构造请求
        auto *r = nam->get(QNetworkRequest(u));   // 发起 GET
        connect(r, &QNetworkReply::finished, this, [this, r, type] {   // 完成回调
            r->deleteLater();   // 防泄漏
            auto d = QJsonDocument::fromJson(r->readAll()).object();   // 解析
            QString latest = d["latest"].toString();   // 最新一条正文
            // 顶部摘要(最新一条的元信息)
            QString head;   // 摘要条HTML
            if (d["list"].isArray() && !d["list"].toArray().isEmpty()) {   // 有列表才有元信息
                auto o0 = d["list"].toArray().at(0).toObject();   // 第一条
                if (type == QStringLiteral("report"))   // 日报摘要
                    head = metaHeader({QStringLiteral("最新日报: <b>%1</b>").arg(o0["report_time"].toString()),   // 日报时间
                        QStringLiteral("真实盈亏 <b style='color:%1'>%2U</b>").arg(jnum(o0["pnl"]) >= 0 ? "#e03131" : "#0ca678").arg(jnum(o0["pnl"]), 0, 'f', 2),   // 盈亏带色
                        QStringLiteral("胜率 <b>%1%</b>").arg(jnum(o0["win_rate"]), 0, 'f', 1),   // 胜率
                        QStringLiteral("平仓 %4 轮").arg((int)jnum(o0["trade_count"]))});   // 平仓轮数
                else if (type == QStringLiteral("tune"))   // 调参摘要
                    head = metaHeader({QStringLiteral("最新调参: <b>%1</b>").arg(o0["tune_time"].toString()),   // 调参时间
                        QStringLiteral("主周期 <b>%1</b>").arg(o0["main_bar"].toString()),   // 主周期
                        QStringLiteral("主策略 <b>%1</b>").arg(o0["main_strat"].toString()),   // 主策略
                        QStringLiteral("评分 <b>%1</b>").arg(jnum(o0["score"]), 0, 'f', 1),   // 评分
                        jnum(o0["changed"]) != 0 ? QStringLiteral("<b style='color:#e03131'>已变更</b>") : QStringLiteral("未变更")});   // 是否变更
                else   // 反思摘要
                    head = metaHeader({QStringLiteral("最新反思: <b>%1</b>").arg(o0["reflect_time"].toString()),   // 反思时间
                        QStringLiteral("胜率 <b>%1%</b>").arg(jnum(o0["wr_now"]), 0, 'f', 1),   // 当前胜率
                        QStringLiteral("决策 <b style='color:#2f6fed'>%1</b>").arg(o0["decision"].toString())});   // AI决策
            }
            if (type == QStringLiteral("report")) {   // 日报页渲染
                if (latest.isEmpty()) latest = QStringLiteral("<div style='color:#888;'>暂无日报, 引擎 08:05 自动生成(或用 cmd_report.exe 手动生成)</div>");   // 空提示
                repView->setHtml(head + latest);   // 摘要+正文
            } else if (type == QStringLiteral("tune")) {   // 调参页渲染
                tuneView->setHtml(head + (latest.isEmpty() ? QStringLiteral("<div style='color:#888;'>暂无调参记录</div>") : plainToHtml(latest)));   // 摘要+正文(纯文本转HTML)
            } else {   // 反思页渲染
                reflView->setHtml(head + (latest.isEmpty() ? QStringLiteral("<div style='color:#888;'>暂无反思记录</div>") : plainToHtml(latest)));   // 同上
            }
        });
    }
};

// ================================================================ 盈利曲线自绘(深度回测面板, 2026-09-25)
// 每小时累计盈亏曲线: 多策略多色折线, 红涨绿跌基线
class CurveWidget : public QWidget {   // 多策略盈利曲线控件
public:   // 公开成员
    QMap<QString, QVector<QPointF>> series;   // 策略名 -> (x=小时序, y=累计盈亏)
    double minX = 0, maxX = 1, minY = -1, maxY = 1;   // 数据范围(供坐标换算)
    void setData(const QMap<QString, QVector<QPointF>> &s) {   // 设置曲线数据并重算范围
        series = s;   // 保存
        minX = 1e18; maxX = -1e18; minY = 1e18; maxY = -1e18;   // 范围初值
        for (auto &pts : series)   // 遍历每条曲线
            for (auto &p : pts) {   // 遍历每个点
                minX = qMin(minX, p.x()); maxX = qMax(maxX, p.x());   // x 范围
                minY = qMin(minY, p.y()); maxY = qMax(maxY, p.y());   // y 范围
            }
        if (minX > maxX) { minX = 0; maxX = 1; }   // 空数据兜底
        if (minY > maxY) { minY = -1; maxY = 1; }   // 空数据兜底
        if (maxY - minY < 0.2) { double m = (maxY + minY) / 2; minY = m - 0.1; maxY = m + 0.1; }   // y 范围过窄时撑到 0.2
        update();   // 重绘
    }
protected:   // 绘制
    void paintEvent(QPaintEvent *) override {   // 画全部曲线
        QPainter p(this);   // 画笔
        p.fillRect(rect(), QColor(0xf6, 0xf8, 0xfc));   // 浅灰蓝底
        int L = 52, R = 10, T = 8, B = 20;   // 边距
        int w = width() - L - R, h = height() - T - B;   // 绘图区宽高
        if (w <= 10 || h <= 10 || series.isEmpty()) return;   // 太小或无数据直接返回
        // 零轴: 盈亏>0 红 / <0 绿(中国习惯)
        int y0 = T + int(h * (maxY - 0) / (maxY - minY));   // 零值对应 y
        p.setPen(QPen(QColor(0xc9, 0xd3, 0xe4), 1, Qt::DashLine));   // 浅灰虚线
        p.drawLine(L, y0, L + w, y0);   // 画零轴
        QColor pal[] = {QColor(0xe0,0x31,0x31), QColor(0x1d,0x6f,0xe0), QColor(0x9c,0x36,0xb5),   // 9色调色板
                        QColor(0xf7,0x67,0x07), QColor(0x0c,0xa6,0x78), QColor(0x10,0x98,0xad),
                        QColor(0x70,0x48,0xe8), QColor(0x5f,0x3d,0xc4), QColor(0xe8,0x89,0x0c)};
        int ci = 0;   // 曲线序号(取色用)
        QFont f = p.font(); f.setPointSize(8); p.setFont(f);   // 8号字
        for (auto it = series.begin(); it != series.end(); ++it, ++ci) {   // 逐条曲线
            const auto &pts = it.value();   // 该曲线全部点
            if (pts.isEmpty()) continue;   // 空曲线跳过
            QPen pen(pal[ci % 9], 1.6);   // 按序号取色, 1.6px 线宽
            p.setPen(pen);   // 套笔
            QPointF prev;   // 前一点
            for (int i = 0; i < pts.size(); ++i) {   // 逐点连线
                double x = L + w * (pts[i].x() - minX) / qMax(1e-9, maxX - minX);   // x 归一化映射
                double y = T + h * (maxY - pts[i].y()) / (maxY - minY);   // y 反向映射
                if (i) p.drawLine(prev, QPointF(x, y));   // 非首点连线
                prev = QPointF(x, y);   // 更新前点
            }
            p.setPen(QPen(pal[ci % 9], 1));   // 同色细笔写图例
            p.drawText(L + 4 + (ci % 3) * 110, T + 12 + (ci / 3) * 13, it.key());   // 左上角三列排布写策略名
        }
    }
};

// ================================================================ 主窗口
class MainWindow : public QMainWindow {   // 主窗口(界面+轮询数据+各弹窗)
public:   // 构造
    MainWindow(QWidget *parent = nullptr) : QMainWindow(parent) {   // 构造: 搭全部界面并启动轮询
        setWindowTitle(QStringLiteral("OKX 量化交易终端 (C++ Qt) - ETH模式·九7信号版·3m底九7买入·首仓30U·止盈+2%·九7加仓·每轮+1U·不限轮数"));   // 标题即当前策略摘要
        // 2026-09-25 01:15 用户: 按 1024x748 固定仍"页面太大只看到左K线" → RDP 会话实际分辨率=客户端窗口大小,
        // 写死任何值都可能超屏。改为窗口最大化自适应任意分辨率, 左右 2:1 与 K线高 1/4 仍由 resizeEvent 动态维持。
        resize(1024, 748);   // 初始尺寸
        setWindowState(Qt::WindowMaximized);   // 启动即最大化自适应分辨率

        auto *central = new QWidget;   // 中央容器
        auto *root = new QVBoxLayout(central);   // 2026-09-24 22:30: 顶栏工具条 + 原有左右分栏
        root->setContentsMargins(8, 4, 8, 0);   // 外边距
        root->setSpacing(4);   // 布局间距

        // ---- 顶栏工具条: 1:1还原网页 top 栏 ----
        // 2026-09-25 用户: 保留 AI日报, 删除「策略体检」「立即刷新」按钮;
        // AI滚动条拆出顶栏, 单独一行放在账户摘要(总盈亏)下面。
        auto *topBar = new QHBoxLayout;   // 顶栏横向布局
        topBar->addWidget(new QLabel(QStringLiteral("📈 OKX 量化交易面板")));   // 左侧标题
        // 合约下拉框: 按最长合约名自适应宽度, 纯下拉不允许输入(2026-09-25 用户)
        instCombo = new QComboBox; instCombo->setEditable(false);   // 合约下拉(不可输入)
        instCombo->setSizeAdjustPolicy(QComboBox::AdjustToContents);   // 宽度自适应内容
        barCombo = new QComboBox;   // 周期下拉
        // 用户 2026-09-24 晚: 策略唯一15m, K线默认且只显示15分钟线, 不显示其他周期
        for (const char *b : {"15m"})   // 仅一个周期项
            barCombo->addItem(b);   // 加入"15m"
        barCombo->setCurrentText("15m");   // 默认选中15m
        statusLabel = new QLabel;   // 状态标签(网络/刷新状态)
        statusLabel->setMinimumWidth(60);   // 2026-09-25: 允许压缩, 防顶栏撑爆最小宽
        topBar->addWidget(new QLabel(QStringLiteral("合约:")));   // 合约标签
        topBar->addWidget(instCombo);   // 合约下拉
        topBar->addWidget(new QLabel(QStringLiteral("周期:")));   // 周期标签
        topBar->addWidget(barCombo);   // 周期下拉
        topBar->addWidget(statusLabel);   // 状态文字
        topBar->addStretch();   // 网页 <span style="flex:1">
        statLabel = new QLabel(QStringLiteral("账户摘要 加载中..."));   // 账户摘要一行字
        statLabel->setWordWrap(false);   // 2026-09-25 用户: 总盈亏文字要完整显示, 不设最小宽截断
        statLabel->setTextInteractionFlags(Qt::TextSelectableByMouse);   // 文字可用鼠标选中复制
        statLabel->setStyleSheet(QStringLiteral(   // 粉色渐变摘要条样式
            "font-family:'LiSu'; font-size:14pt; color:#8c1d2f;"
            "background: qlineargradient(x1:0,y1:0,x2:1,y2:0, stop:0 #fff1f3, stop:1 #ffe3e8);"
            "border:1px solid #f1b8c1; border-radius:8px; padding:4px 14px;"));
        topBar->addWidget(statLabel, 1);   // 摘要占满剩余宽
        root->addLayout(topBar);   // 顶栏入主布局
        // ---- AI滚动条单独一行(账户摘要下面), 行尾放 AI日报 + AI详情 按钮(2026-09-25 用户) ----
        auto *marqBar = new QHBoxLayout;   // 第二行横向布局
        aiMarqLab = new QLabel(QStringLiteral("AI自动策略加载中…"));   // AI跑马灯标签
        aiMarqLab->setFrameShape(QFrame::NoFrame);   // 无边框
        aiMarqLab->setMinimumWidth(100);   // 最小宽
        aiMarqLab->setStyleSheet(QStringLiteral(   // 绿色渐变滚动条样式
            "font-family:'LiSu'; font-size:14pt; color:#0b5c40;"
            "background: qlineargradient(x1:0,y1:0,x2:1,y2:0, stop:0 #eafcf5, stop:1 #d3f4e8);"
            "border:1px solid #b8e6d9; border-radius:8px; padding:4px 10px;"));
        aiMarqLab->setToolTip(QStringLiteral("AI自动策略滚动播放, 点「AI详情」看完整内容"));   // 提示
        marqBar->addWidget(aiMarqLab, 1);   // 跑马灯占一半
        marqBar->addStretch(1);   // 2026-09-25 用户: 滚动条长度缩小到原来的一半(弹性1:1)
        auto *repBtn = new QPushButton(QStringLiteral("📊 AI日报"));   // AI日报按钮
        repBtn->setToolTip(QStringLiteral("弹窗显示: 3D盈利K线/3D柱状/3D饼图 + AI日报/调参/反思 彩色报表"));   // 提示
        connect(repBtn, &QPushButton::clicked, this, [this] {   // 点击弹报表中心
            ReportCenter dlg(this);   // 模态对话框
            dlg.exec();   // 阻塞显示
        });
        marqBar->addWidget(repBtn);   // 加入日报按钮
        auto *aiDetailBtn = new QPushButton(QStringLiteral("📋 AI详情"));   // AI详情按钮
        connect(aiDetailBtn, &QPushButton::clicked, this, &MainWindow::showAiDetailDlg);   // 点击弹详情
        aiDetailBtn->setStyleSheet(QStringLiteral(   // 绿色渐变按钮样式
            "QPushButton { background: qlineargradient(x1:0,y1:0,x2:0,y2:1,"
            " stop:0 #74e0b9, stop:0.5 #1fa876, stop:1 #128a5e); border: 1px solid #0e7d55; }"
            "QPushButton:hover { background: #2bbd86; }"));
        marqBar->addWidget(aiDetailBtn);   // 加入详情按钮
        root->addLayout(marqBar);   // 第二行入主布局

        auto *hbox = new QHBoxLayout;   // 左右分栏
        root->addLayout(hbox, 1);   // 占满剩余空间

        // ---- 左: K线区 + 交易流水(1:1还原网页左列: K线图卡 + 交易流水卡, 比例1fr) ----
        auto *left = new QVBoxLayout;   // 左列纵向布局

        chart = new CandleChart;   // K线自绘图
        chart->setFixedHeight(180);   // 初始值, resizeEvent 按窗口高 1/4 动态重算(用户: K线不许占一半页面)
        chart->requestOlder = [this] { loadOlderHistory(); };   // 2026-09-26: 往左拖到最老自动补拉更早历史
        left->addWidget(chart, 0);   // 固定高加入左列
        // 网页 .legend: MA5/MA10/MA20/布林带说明(1:1还原)
        auto *legend = new QLabel(QStringLiteral("MA5 ━ · MA10 ━ · MA20 ━ · 布林带(20,2) 橙色轨道"));   // 指标图例说明
        legend->setStyleSheet(QStringLiteral("font-size:11px;color:#8a94a6;"));   // 灰色小字
        left->addWidget(legend);   // 加入左列

        // ---- 左下: 交易流水(最近500条, 点合约名切K线) 纯净表格(2026-09-25 用户: 所有筛选/按钮/搜索框取消) ----
        flowBox = new QGroupBox(QStringLiteral("交易流水 (最近500条, 点合约名切K线)"));   // 流水面板
        flowBox->setObjectName("flowBox");   // 绑定样式
        auto *flowLay = new QVBoxLayout(flowBox);   // 面板布局
        flowTable = new QTableWidget(0, 7);   // 2026-09-25 用户: 删除「策略」栏(净盈亏/手续费/资金费 按实际)
        flowTable->setObjectName("flowTable");   // 绑定样式
        flowTable->setHorizontalHeaderLabels({QStringLiteral("时间"), QStringLiteral("合约"),   // 七列表头
                                              QStringLiteral("方向"), QStringLiteral("价格"),
                                              QStringLiteral("净盈亏$"), QStringLiteral("手续费$"),
                                              QStringLiteral("资金费$")});
        // 列宽可拖动调节(Interactive), 最后一列自适应
        flowTable->horizontalHeader()->setSectionResizeMode(0, QHeaderView::Interactive);   // 时间列可拖
        flowTable->horizontalHeader()->setSectionResizeMode(1, QHeaderView::Interactive);   // 合约列可拖
        flowTable->horizontalHeader()->setSectionResizeMode(2, QHeaderView::Interactive);   // 方向列可拖
        flowTable->horizontalHeader()->setSectionResizeMode(3, QHeaderView::Interactive);   // 价格列可拖
        // 2026-09-25 用户: 盈利栏太大 → 缩小到原来的一半(固定宽); 「策略」栏已删, 资金费列自适应
        flowTable->horizontalHeader()->setSectionResizeMode(4, QHeaderView::Interactive);   // 盈亏列可拖
        flowTable->horizontalHeader()->setSectionResizeMode(5, QHeaderView::Interactive);   // 手续费列可拖
        flowTable->horizontalHeader()->setSectionResizeMode(6, QHeaderView::Stretch);   // 资金费列自动撑满
        flowTable->horizontalHeader()->setDefaultAlignment(Qt::AlignCenter);   // 2026-09-25 用户: 列名居中
        flowTable->setColumnWidth(0, 128);   // 时间 MM-dd HH:mm:ss 完整显示
        flowTable->setColumnWidth(1, 132);   // 合约列宽
        flowTable->setColumnWidth(2, 56);   // 方向列宽
        flowTable->setColumnWidth(3, 92);   // 价格列宽
        flowTable->setColumnWidth(4, 80);   // 盈亏列宽
        flowTable->setColumnWidth(5, 76);   // 手续费列宽
        flowTable->setEditTriggers(QAbstractItemView::NoEditTriggers);   // 禁止编辑
        flowTable->setSelectionBehavior(QAbstractItemView::SelectRows);   // 整行选择
        flowTable->setAlternatingRowColors(true);   // 隔行变色
        flowTable->setSortingEnabled(true);      // 点表头排序(Excel)
        flowTable->setVerticalScrollMode(QAbstractItemView::ScrollPerPixel);   // 垂直按像素平滑滚动
        flowTable->verticalScrollBar()->setSingleStep(24);   // 滚动步长24px
        connect(flowTable, &QTableWidget::itemClicked, this, &MainWindow::onFlowClicked);   // 点合约名切K线
        enableTableCopy(flowTable, QStringLiteral("交易流水"));   // 右键可复制(用户 2026-09-24 晚)
        // 2026-09-25 用户: Excel式列筛选按钮对交易流水一并取消(监测表保留)
        flowLay->addWidget(flowTable);   // 表格入面板
        left->addWidget(flowBox, 1);   // 2026-09-25 用户: 三面板已删, 流水表占满左列剩余空间
        hbox->addLayout(left, 1);   // 1:1还原网页: 左列 1fr(自适应占满)

        // ---- 右: 信息面板(2026-09-25 用户: 左右一样宽且不许超边界 —— 弹性 1:1 平分, 不写死像素,
        //      任意 RDP 分辨率自适应; 右面板加 stretch=1, 与左列等分窗口宽) ----
        rightScroll = new QScrollArea;   // 右列滚动容器
        rightScroll->setWidgetResizable(true);   // 内容随容器伸缩
        rightScroll->setVerticalScrollBarPolicy(Qt::ScrollBarAsNeeded);   // 需要时显示滚动条
        rightScroll->setMinimumWidth(0);   // 允许压缩, 窄分辨率不撑爆
        rightScroll->setFrameShape(QFrame::NoFrame);   // 无边框
        auto *rightHost = new QWidget;   // 右列内容宿主
        auto *right = new QVBoxLayout(rightHost);   // 右列布局
        right->setSpacing(6);   // 间距

        // 2026-09-24 22:30 用户指令: 账户摘要移到顶栏「立即刷新」右边, 此处删除原面板

        gridBox = new QGroupBox(QStringLiteral("止盈 + 加仓信号监测 (ETH模式·九7信号版 · 100x只买涨 · 不设止损 · 价格+2%止盈 · 加仓=3m九7信号·每轮+1U·不限轮数(永远可加) · 首仓30U)"));   // 监测面板(标题即策略摘要)
        gridBox->setToolTip(QStringLiteral("ETH模式·九7信号版(2026-09-26用户指令): 仅ETH-USDT-SWAP只买涨, 买入唯一信号=3m K线底部九7(标准神奇九转: 连续7根收盘<第4根前收盘, 计数恰好=7, 与K线标注同口径); 首仓保证金固定30U(旧1U+3U/天阶梯已废除), 有持仓不重复开; 止损不设, 亏损由交易所爆仓线兜底; 止盈单不挂, 每10秒检测 价格+2%(100x ROI+200%) 达标或现价≥止盈价立即市价全平; 加仓=再次出现3m底九7信号, 不限轮数(永远可加), 每轮固定+1U, 加仓无仓位上限。"));   // 完整策略规则悬浮提示
        gridBox->setObjectName("gridBox");   // 绑定样式
        auto *gLay = new QVBoxLayout(gridBox);   // 面板布局
        gridHead = new QLabel(QStringLiteral("加载中..."));   // 监测汇总一行
        gridHead->setWordWrap(true);   // 允许换行
        gridHead->setStyleSheet("font-size:11px;color:#4a5568;");   // 灰色小字
        gLay->addWidget(gridHead);   // 加入面板
        gridTable = new QTableWidget(0, 5);   // 监测表(5列)
        gridTable->setObjectName("gridTable");   // 绑定样式
        gridTable->setHorizontalHeaderLabels({QStringLiteral("合约"), QStringLiteral("加仓数"),   // 五列表头
                                              QStringLiteral("现价"), QStringLiteral("止盈"),
                                              QStringLiteral("状态")});
        for (int i = 0; i < 5; ++i)   // 五列均分
            gridTable->horizontalHeader()->setSectionResizeMode(i, QHeaderView::Stretch);   // 2026-09-24 22:09: 填满右边到边框
        gridTable->horizontalHeader()->setDefaultAlignment(Qt::AlignCenter);   // 列名居中
        gridTable->verticalHeader()->setDefaultSectionSize(32);                // 行距加大(好看)
        gridTable->setFixedHeight(32 * 10 + 34);   // 2026-09-24 22:09: 监测表整体10行高度(354px; 原112px重复设置已删, 那曾把表格压到只剩2行)
        gridTable->verticalHeader()->setVisible(false);   // 隐藏行号
        gridTable->setEditTriggers(QAbstractItemView::NoEditTriggers);   // 禁止编辑
        gridTable->setSelectionBehavior(QAbstractItemView::SelectRows);   // 整行选择
        gridTable->verticalScrollBar()->setSingleStep(16);   // 滚动步长16px
        gridTable->setToolTip(QStringLiteral("每10秒刷新: 距下一档还差多少、触发后到底加没加仓、被风控拦在哪一步"));   // 提示
        // 用户 2026-09-24 晚: 点击合约名自动联动左边K线图切到该合约(15分钟线)
        connect(gridTable, &QTableWidget::cellClicked, this, [this](int row, int) {   // 点任意格
            if (auto *it = gridTable->item(row, 0)) {   // 取第0列合约名
                QString inst = it->text().trimmed().toUpper();   // 规整为大写
                if (!inst.isEmpty()) {   // 2026-09-25: 下拉框已改为不可输入 → 用 setCurrentText(命中列表项才切换)
                    int ix = instCombo->findText(inst);   // 在下拉中查该合约
                    if (ix >= 0) instCombo->setCurrentIndex(ix);   // 触发 currentTextChanged -> refreshAll
                }
            }
        });
        enableTableCopy(gridTable, QStringLiteral("止盈加仓"));   // 同样支持右键复制
        attachFilters(gridTable, gLay);   // Excel式列筛选(2026-09-24 22:09)
        gLay->addWidget(gridTable);   // 表格入面板
        // 1:1还原网页 #gridTip / #gridEntry 两行说明
        auto *gridTip = new QLabel(QStringLiteral("加仓策略: 再次出现 3m 底九7 信号(与买入同口径) 不限轮数(永远可加) · 每轮固定+1U · 加仓无仓位上限 · 首仓保证金固定30U | 亏损由交易所爆仓线兜底 | 不设止损 | 止盈不挂单: 每10秒检测 价格+2% 达标立即全平"));   // 加仓规则说明
        gridTip->setWordWrap(true);   // 换行
        gridTip->setStyleSheet(QStringLiteral("font-size:11px;color:#5c677d;background:#f6f8fb;border:1px solid #e3e8f0;border-radius:6px;padding:5px 8px;"));   // 浅灰提示条
        gLay->addWidget(gridTip);   // 加入面板
        auto *gridEntry = new QLabel(QStringLiteral("买入=3m底部九7: 连续7根收盘<第4根前收盘, 计数恰好=7, 断即清零；首仓保证金固定30U"));   // 买入条件说明
        gridEntry->setWordWrap(true);   // 换行
        gridEntry->setStyleSheet(QStringLiteral("font-size:11px;color:#8a6d3b;background:#fffaf0;border:1px solid #f0e3c8;border-radius:6px;padding:5px 8px;"));   // 米黄提示条
        gLay->addWidget(gridEntry);   // 加入面板
        // [2026-09-26 用户指令] 保底持仓 修改行: 持仓保证金 < 保底线 → 引擎自动补齐(默认30U, 数据库落盘)
        auto *floorRow = new QHBoxLayout();   // 保底持仓输入行
        auto *floorLbl = new QLabel(QStringLiteral("保底持仓(USDT):"));   // 标签
        floorLbl->setStyleSheet(QStringLiteral("font-size:12px;font-weight:600;color:#33415c;"));   // 样式
        floorEdit = new QLineEdit;   // 输入框
        floorEdit->setFixedWidth(90);   // 固定宽
        floorEdit->setPlaceholderText(QStringLiteral("30"));   // 默认提示30
        auto *floorBtn = new QPushButton(QStringLiteral("保存"));   // 保存按钮
        floorMsg = new QLabel(QStringLiteral("保证金低于保底线 → 引擎自动补齐到保底线(默认30U)"));   // 结果提示
        floorMsg->setStyleSheet(QStringLiteral("font-size:11px;color:#8a94a6;"));   // 灰字
        floorMsg->setWordWrap(true);   // 换行
        floorRow->addWidget(floorLbl);   // 标签
        floorRow->addWidget(floorEdit);   // 输入框
        floorRow->addWidget(floorBtn);   // 按钮
        floorRow->addWidget(floorMsg, 1);   // 提示占满剩余
        gLay->addLayout(floorRow);   // 行入面板
        connect(floorBtn, &QPushButton::clicked, this, [this] {   // 保存保底持仓
            bool okc = false;   // 转换成功标记
            const double v = floorEdit->text().toDouble(&okc);   // 读输入值
            floorMsg->setStyleSheet(QStringLiteral("font-size:11px;"));   // 先复位样式
            if (!okc || v < 1 || v > 500) {   // 校验 1~500
                floorMsg->setText(QStringLiteral("❌ 保底持仓须在 1~500 USDT 之间"));   // 错误提示
                floorMsg->setStyleSheet(QStringLiteral("font-size:11px;color:#e03131;font-weight:600;"));   // 红色
                return;   // 不提交
            }
            QUrl us(API); us.setQuery(QStringLiteral("action=settings&eth_floor_u=%1").arg(v, 0, 'f', 2));   // 提交设置接口
            auto *rf = nam->get(QNetworkRequest(us));   // 发起 GET
            connect(rf, &QNetworkReply::finished, this, [this, rf] {   // 完成回调
                rf->deleteLater();   // 防泄漏
                const auto d = QJsonDocument::fromJson(rf->readAll()).object();   // 解析
                if (d["ok"].toBool()) {   // 保存成功
                    floorMsg->setText(QStringLiteral("✅ 已保存: 保底持仓 %1U (引擎30秒内生效)").arg(jnum(d["eth_floor_u"]), 0, 'f', 0));   // 成功提示
                    floorMsg->setStyleSheet(QStringLiteral("font-size:11px;color:#0ca678;font-weight:600;"));   // 绿色
                } else {   // 失败
                    floorMsg->setText(QStringLiteral("❌ 保存失败"));   // 错误提示
                    floorMsg->setStyleSheet(QStringLiteral("font-size:11px;color:#e03131;font-weight:600;"));   // 红色
                }
            });
        });
        // 网页 #gridLogBtn: 全宽按钮
        auto *gLogBtn = new QPushButton(QStringLiteral("加仓信号历史（3m九7 触发 · 加仓 · 风控拦截 都留痕）"));   // 信号历史按钮
        connect(gLogBtn, &QPushButton::clicked, this, &MainWindow::showGridLog);   // 点击弹历史
        gLay->addWidget(gLogBtn);   // 加入面板
        right->addWidget(gridBox);   // 监测面板入右列

        // 1:1还原网页 c-chk 策略体检卡
        auto *chkBox = new QGroupBox(QStringLiteral("策略体检（ETH模式·九7信号版 实盘同参核实）"));   // 体检面板
        chkBox->setObjectName("chkBox");   // 绑定样式
        auto *chkLay = new QVBoxLayout(chkBox);   // 面板布局
        auto *chkNote = new QLabel(QStringLiteral("PASS=可正常交易 / WARN=频率过低 / FAIL=信号失效。深度学习策略修改后自动核实生效。"));   // 体检说明
        chkNote->setWordWrap(true);   // 换行
        chkNote->setStyleSheet(QStringLiteral("font-size:11px;color:#8a94a6;"));   // 灰字
        chkLay->addWidget(chkNote);   // 加入面板
        auto *chkBtn3 = new QPushButton(QStringLiteral("查看最近体检报告"));   // 体检按钮
        connect(chkBtn3, &QPushButton::clicked, this, &MainWindow::runBackcheck);   // 点击拉报告
        chkLay->addWidget(chkBtn3);   // 加入面板
        right->addWidget(chkBox);   // 体检卡入右列

        // 2026-09-24 22:30 用户指令: AI自动策略移到顶栏(滚动播放+详情按钮), 面板删除;
        // aiTable 转为隐藏数据源(渲染逻辑复用), AI筛选功能删除(不挂 attachFilters)
        aiTable = new QTableWidget(0, 3);   // 隐藏AI数据表(仅作数据源)
        aiTable->hide();   // 不显示

        right->addStretch();   // 底部弹性
        rightScroll->setWidget(rightHost);   // 装入滚动容器
        hbox->addWidget(rightScroll, 1);   // 2026-09-25 用户: 与左列 1:1 弹性平分(任意分辨率不超边界)

        setCentralWidget(central);   // 设为中央部件

        // ---- 托盘: 关闭=最小化, 菜单退出 ----
        // 自绘K线风格图标(深蓝底+红绿蜡烛), 窗口/任务栏/托盘共用
        QPixmap pm = makeCandleIcon(256);   // 生成 256px 图标
        setWindowIcon(QIcon(pm));   // 主窗口图标
        tray = new QSystemTrayIcon(this);   // 托盘图标
        auto menu = new QMenu;   // 托盘菜单
        menu->addAction(QStringLiteral("显示主界面"), this, &MainWindow::showNormal);   // 菜单: 显示
        menu->addAction(QStringLiteral("真正退出"), qApp, &QApplication::quit);   // 菜单: 退出
        tray->setContextMenu(menu);   // 挂菜单
        tray->setToolTip(QStringLiteral("OKX 量化交易终端"));   // 托盘提示
        tray->setIcon(QIcon(pm));   // 托盘图标
        tray->show();   // 显示托盘
        quitting = false;   // 初始非退出态(关闭=最小化)

        // ---- 悬浮窗: 置顶小窗(合约价+总盈亏), 可拖动, 双击回主界面 ----
        fw = new FloatWin;   // 创建悬浮窗
        fw->onDouble = [this] { showNormal(); activateWindow(); };   // 双击回主界面并激活
        QAction *fwAct = menu->addAction(QStringLiteral("显示/隐藏悬浮窗"));   // 托盘菜单项
        QObject::connect(fwAct, &QAction::triggered, this, [this] {   // 点击切换显隐
            fw->setVisible(!fw->isVisible());   // 反转可见性
        });
        QRect scr = QApplication::primaryScreen()->availableGeometry();   // 主屏可用区域
        fw->move(scr.right() - fw->width() - 16, scr.bottom() - fw->height() - 48);   // 初始放右下角
        fw->show();   // 显示悬浮窗

        // ---- 网络 ----
        nam = new QNetworkAccessManager(this);   // 网络管理器
        nam->setProxy(QNetworkProxy(QNetworkProxy::NoProxy)); // 仅本程序直连,不走系统代理
        timer = new QTimer(this);   // 轮询定时器
        connect(timer, &QTimer::timeout, this, &MainWindow::refreshAll);   // 每次超时全量刷新
        connect(instCombo, &QComboBox::currentTextChanged, this, &MainWindow::refreshAll);   // 切合约即刷新
        connect(barCombo, &QComboBox::currentTextChanged, this, &MainWindow::refreshAll);   // 切周期即刷新

        if (qEnvironmentVariable("QTAPP_NET") != "0") {   // 环境变量可关闭联网(调试用)
            loadSymbols();   // 拉合约列表
            refreshAll();   // 首次全量刷新
            // [2026-09-26 用户指令] 启动时读取保底持仓设置(数据库 app_settings)
            QUrl uf(API); uf.setQuery(QStringLiteral("action=settings"));   // 设置接口
            auto *rfl = nam->get(QNetworkRequest(uf));   // 发起 GET
            connect(rfl, &QNetworkReply::finished, this, [this, rfl] {   // 完成回调
                rfl->deleteLater();   // 防泄漏
                const auto d = QJsonDocument::fromJson(rfl->readAll()).object();   // 解析
                if (d["ok"].toBool() && floorEdit)   // 成功且有输入框
                    floorEdit->setText(QString::number(jnum(d["eth_floor_u"]), 'f', 0));   // 回填当前保底值
            });
            timer->start(3000);   // AJAX 实时同步: 3秒轮询全量数据接口
        }
    }

protected:   // 窗口事件
    void closeEvent(QCloseEvent *e) override {   // 关闭按钮
        if (!quitting) { hide(); e->ignore(); }   // 关闭=最小化到托盘
        else e->accept();   // 真退出才放行
    }
    // 2026-09-25 用户: 按屏幕比例动态布局 —— 左右弹性 1:1(布局系统自动平分, 不写死宽), K线图高=窗口高1/4
    void resizeEvent(QResizeEvent *) override {   // 窗口尺寸变化
        if (chart) chart->setFixedHeight(qMax(140, height() / 4));   // K线高=窗口1/4(最小140)
    }

private:   // 私有成员与方法
    QComboBox *instCombo, *barCombo;            // 2026-09-25: 流水筛选下拉/搜索框按用户指令删除
    QLabel *aiMarqLab = nullptr;                // AI自动策略滚动播放标签(顶栏)
    QTimer *mqTimer = nullptr;                  // 跑马灯计时器
    QString aiMqText;                           // 滚动全文
    int mqPos = 0;   // 当前滚动位置
    QLabel *statusLabel, *statLabel;   // 状态标签 / 账户摘要标签
    QScrollArea *rightScroll = nullptr;   // 2026-09-25 01:03: 右面板(2:1比例动态宽)
    QTableWidget *aiTable;   // 隐藏AI数据表
    CandleChart *chart;   // K线自绘图
    // CandleChart *profitChart 已移除(2026-09-24 21:40 用户取消盈利K线面板)
    QTableWidget *flowTable;   // 交易流水表
    struct FlowRow { QString time, inst, act; double px, pnl; double fee = 0, fund = 0; QString pos; QString remark; QString ordId; int fills = 1; };   // 2026-09-25 用户: 策略栏已删
    QVector<FlowRow> allFlowRows;   // 流水全量缓存(渲染用)
    struct AiRec { QString time; bool adj; QString text; double wr, profit; int cnt; };   // 单条AI记录
    QVector<AiRec> aiRecords;   // AI记录列表
    QGroupBox *accBox, *aiBox, *flowBox, *gridBox;   // 各分组面板(部分已弃用仅保留声明)
    // 2026-09-25 用户: 深度回测三面板已整体移除(crashTabs/btTable/liqTable/simTable/adviceLab/eqCurve/eqData 一并删除)

    // ---- Excel式列筛选(2026-09-24 22:09): 每列表头下方一个"列名▼"按钮, 点开勾选要显示的值 ----
    QMap<QTableWidget*, QMap<int, QSet<QString>>> fltState;   // 表→列→选中值集合(空=全显示)

    void attachFilters(QTableWidget *t, QVBoxLayout *lay) {   // 给表挂Excel式筛选按钮行
        auto *bar = new QWidget;   // 筛选按钮条容器
        auto *hl = new QHBoxLayout(bar);   // 横排
        hl->setContentsMargins(0, 0, 0, 2); hl->setSpacing(3);   // 边距与间距
        for (int c = 0; c < t->columnCount(); ++c) {   // 每列一个按钮
            auto *b = new QToolButton(bar);   // 工具按钮
            b->setText(t->horizontalHeaderItem(c)->text() + QStringLiteral(" ▼"));   // 按钮文字=列名+▼
            b->setToolTip(QStringLiteral("Excel式筛选: 勾选要显示的值"));   // 提示
            b->setStyleSheet(QStringLiteral(   // 浅蓝按钮样式
                "QToolButton{background:#f2f6ff;border:1px solid #c9d4e8;border-radius:4px;padding:2px 4px;"
                "font-size:12px;font-family:隶书;color:#33415c;}"
                "QToolButton:hover{border-color:#2f6fed;color:#2f6fed;background:#e8f0ff;}"));
            connect(b, &QToolButton::clicked, this, [this, t, c] { showFilterMenu(t, c); });   // 点击弹筛选菜单
            hl->addWidget(b, 1);   // 均分宽度
        }
        lay->addWidget(bar);   // 按钮条加入布局
    }

    void showFilterMenu(QTableWidget *t, int col) {   // 弹出某列的勾选筛选菜单
        QSet<QString> vals;   // 该列全部去重值
        for (int r = 0; r < t->rowCount(); ++r)   // 扫描全表
            if (auto *it = t->item(r, col)) vals.insert(it->text().trimmed());   // 收集单元格文本
        const QMap<int, QSet<QString>> &tm = fltState[t];   // 该表现有筛选状态
        const QSet<QString> cur = tm.value(col);   // 该列已选值(空=全选)
        QMenu menu(this);   // 菜单
        auto *all = new QAction(QStringLiteral("(全选)"), &menu);   // 全选项
        all->setCheckable(true);   // 可勾选
        all->setChecked(cur.isEmpty());   // 未筛选=勾上
        menu.addAction(all);   // 加入
        menu.addSeparator();   // 分隔线
        QList<QAction*> acts;   // 各值对应动作
        const QStringList sorted = QStringList(vals.constBegin(), vals.constEnd());   // 值列表
        for (const QString &v : sorted) {   // 逐值建勾选项
            auto *a = new QAction(v.isEmpty() ? QStringLiteral("(空)") : v, &menu);   // 空值显示"(空)"
            a->setCheckable(true);   // 可勾选
            a->setChecked(cur.isEmpty() || cur.contains(v));   // 默认勾选状态
            menu.addAction(a); acts << a;   // 加入
        }
        connect(all, &QAction::toggled, &menu, [&acts](bool on) {   // 全选联动
            for (auto *a : acts) { a->blockSignals(true); a->setChecked(on); a->blockSignals(false); }   // 同步各项但不触发级联
        });
        for (auto *a : acts)   // 各勾选项变化
            connect(a, &QAction::toggled, &menu, [this, t, col, &acts](bool) {   // 重新计算筛选集合
                QStringList sel;   // 勾中的值
                for (auto *x : acts) if (x->isChecked()) sel << x->text().replace(QStringLiteral("(空)"), QString());   // 收集(空→空串)
                if (sel.size() == acts.size()) fltState[t][col].clear();   // 全勾=不筛选
                else fltState[t][col] = QSet<QString>(sel.constBegin(), sel.constEnd());   // 否则记录选中集
                applyFilters(t);   // 立即应用
            });
        connect(all, &QAction::toggled, &menu, [this, t, col](bool on) {   // 全选时清空筛选
            if (on) { fltState[t][col].clear(); applyFilters(t); }   // 清空并应用
        });
        menu.exec(QCursor::pos());   // 在鼠标处模态弹出
        applyFilters(t);   // 关闭菜单后按最终勾选状态刷新
    }

    void applyFilters(QTableWidget *t) {   // 按筛选状态隐藏/显示行
        if (!t) return;   // 空表跳过
        const QMap<int, QSet<QString>> &st = fltState[t];   // 该表筛选状态
        for (int r = 0; r < t->rowCount(); ++r) {   // 逐行判定
            bool ok = true;   // 默认显示
            for (auto it = st.constBegin(); it != st.constEnd(); ++it) {   // 逐个筛选列
                if (it.value().isEmpty()) continue;   // 该列未筛选跳过
                auto *cell = t->item(r, it.key());   // 单元格
                if (!cell || !it.value().contains(cell->text().trimmed())) { ok = false; break; }   // 不在选中集 → 隐藏
            }
            t->setRowHidden(r, !ok);   // 应用可见性
        }
    }
    QTableWidget *gridTable = nullptr;   // 加仓信号 实时看板(3m九7, 每轮+1U, 不限轮数)
    QLabel *gridHead = nullptr;          // 加仓信号 一行摘要
    QLineEdit *floorEdit = nullptr;      // [2026-09-26] 保底持仓(USDT) 输入框(数据库 app_settings, 与网页共用)
    QLabel *floorMsg = nullptr;          // [2026-09-26] 保底持仓 保存结果提示
    QStringList gridLogHeader;           // 信号历史表头
    QVector<QStringList> gridLogs;       // 信号历史行
    FloatWin *fw = nullptr;   // 悬浮窗
    QSystemTrayIcon *tray;   // 托盘图标
    QNetworkAccessManager *nam;   // 网络管理器
    QTimer *timer;   // 3秒轮询定时器
    bool quitting = false;   // 是否真正退出(区分关闭=最小化)
    QString lastInst, lastBar;   // K线历史缓存对应的合约/周期

    // 表格右键复制菜单(用户 2026-09-24 晚: 交易流水右键无法复制 -> 单元格/整行/全部 三种复制)
    void enableTableCopy(QTableWidget *t, const QString &name) {   // 给表挂右键复制菜单
        t->setContextMenuPolicy(Qt::CustomContextMenu);   // 自定义右键菜单
        connect(t, &QTableWidget::customContextMenuRequested, this,   // 弹菜单
                [this, t, name](const QPoint &pos) {   // 右键位置
            QMenu menu(name, this);   // 菜单(标题=表名)
            QAction *aCell = menu.addAction(QStringLiteral("复制所选单元格"));   // 项1: 单元格
            QAction *aRow  = menu.addAction(QStringLiteral("复制整行"));   // 项2: 整行
            menu.addSeparator();   // 分隔
            QAction *aAll  = menu.addAction(QStringLiteral("复制全部(%1行)").arg(t->rowCount()));   // 项3: 全部
            QAction *act = menu.exec(t->viewport()->mapToGlobal(pos));   // 模态弹出
            if (!act) return;   // 未选返回
            auto rowText = [&](int r) {   // 拼一行文本(制表符分隔)
                QStringList cells;   // 单元格列表
                for (int c = 0; c < t->columnCount(); c++)
                    if (auto *i = t->item(r, c)) cells << i->text();   // 收集各列
                return cells.join("\t");   // 合并
            };
            QString txt;   // 待复制内容
            if (act == aCell) {   // 复制单元格
                if (auto *it = t->currentItem()) txt = it->text();   // 当前格文本
            } else if (act == aRow) {   // 复制整行
                txt = rowText(t->currentRow());   // 当前行
            } else if (act == aAll) {   // 复制全部
                QStringList rows;   // 行列表
                for (int r = 0; r < t->rowCount(); r++) rows << rowText(r);   // 逐行
                txt = rows.join("\n");   // 换行合并
            }
            if (!txt.isEmpty()) QApplication::clipboard()->setText(txt);   // 写入剪贴板
        });
    }

    void loadSymbols() {   // 拉合约列表填充下拉
        QUrl u(API); u.setQuery("action=symbols");   // symbols 接口
        auto *r = nam->get(QNetworkRequest(u));   // 发起 GET
        connect(r, &QNetworkReply::finished, this, [this, r] {   // 完成回调
            r->deleteLater();   // 防泄漏
            if (r->error() != QNetworkReply::NoError) {   // 网络错误
                statusLabel->setText(QStringLiteral("符号列表失败: %1").arg(r->errorString()));   // 状态提示
                return;   // 返回
            }
            QJsonDocument d = QJsonDocument::fromJson(r->readAll());   // 解析
            QStringList list;   // 合约列表
            for (auto v : d.object()["data"].toArray()) list << v.toString();   // 逐条收集
            QString cur = instCombo->currentText();   // 记住当前选中
            if (list.isEmpty()) list << MAIN_INST;   // 空列表兜底为ETH
            instCombo->blockSignals(true);   // 防止重建时触发刷新
            instCombo->clear(); instCombo->addItems(list);   // 重建下拉项
            int sel = list.contains(cur) ? list.indexOf(cur) : list.indexOf(QString::fromLatin1(MAIN_INST));   // 尽量恢复原选中
            instCombo->setCurrentIndex(sel >= 0 ? sel : 0);   // 2026-09-25: 非可编辑下拉
            instCombo->blockSignals(false);   // 恢复信号
        });
    }

    // 2026-09-26 用户: 往左拖到最老K线 → 从数据库/OKX补拉更早的历史K线(前插, 视口钉住不动)
    void loadOlderHistory() {   // 补拉更早历史K线
        if (chart->candles.isEmpty() || chart->loadingOld) return;   // 无数据或正在拉则跳过
        chart->loadingOld = true;   // 置忙标记
        const qint64 before = chart->candles.first().t;   // 以最早一根时间为锚向更早拉
        QUrl u(API); u.setQuery(QString("action=kline&inst=%1&bar=%2&before=%3").arg(lastInst, lastBar).arg(before));   // kline+before 参数
        auto *r = nam->get(QNetworkRequest(u));   // 发起 GET
        connect(r, &QNetworkReply::finished, this, [this, r] {   // 完成回调
            r->deleteLater();   // 防泄漏
            chart->loadingOld = false;   // 解除忙标记
            if (r->error() != QNetworkReply::NoError) return;   // 网络错返回
            auto d = QJsonDocument::fromJson(r->readAll()).object();   // 解析
            if (!d["ok"].toBool()) return;   // 业务失败返回
            QVector<CandleChart::Candle> cs;   // 更早K线容器
            for (auto v : d["data"].toArray()) {   // 逐条转换
                auto o = v.toObject();   // 当条对象
                cs.push_back({jnum64(o["candle_time"]),   // 时间戳
                              jnum(o["o"]), jnum(o["h"]),   // 开/高
                              jnum(o["l"]), jnum(o["c"])});   // 低/收
            }
            if (cs.isEmpty()) return;   // 没有更早数据返回
            const int oldSz = chart->candles.size();   // 合并前总数
            const bool atLatest = chart->viewEnd < 0 || chart->viewEnd >= oldSz;   // 合并前是否看最新
            chart->setCandles(cs);   // 合并进图表
            const int added = chart->candles.size() - oldSz;   // 新增根数
            if (!atLatest && chart->viewEnd > 0 && added > 0) chart->viewEnd += added;  // 前插后索引平移, 视口钉在同一根K线
            statusLabel->setText(QStringLiteral("已加载更早历史 共%1根").arg(chart->candles.size()));   // 状态提示
        });
    }

    void refreshAll() {   // 3秒轮询全量刷新(多个接口并发拉取)
        QString inst = instCombo->currentText().trimmed().toUpper();   // 当前合约(规整大写)
        if (inst.isEmpty()) return;   // 无合约返回
        if (!inst.contains("-USDT-")) inst = inst.section('-', 0, 0) + "-USDT-SWAP";   // 补全为USDT永续格式
        QString bar = barCombo->currentText();   // 当前周期
        // 2026-09-25 用户: loadCrashData 已随三面板移除

        // 切合约/周期时清空本地历史缓存(重新拉取), 同合约同周期则增量合并保留全部历史
        if (inst != lastInst || bar != lastBar) {   // 检测是否切换
            chart->resetHistory();   // 清空K线历史
            lastInst = inst; lastBar = bar;   // 记录新合约/周期
        }

        // K线
        QUrl u1(API); u1.setQuery(QString("action=kline&inst=%1&bar=%2").arg(inst, bar));   // kline 接口
        auto *r1 = nam->get(QNetworkRequest(u1));   // 发起 GET
        connect(r1, &QNetworkReply::finished, this, [this, r1, inst, bar] {   // 完成回调
            r1->deleteLater();   // 防泄漏
            if (r1->error() != QNetworkReply::NoError) {   // 网络错误
                statusLabel->setText(QStringLiteral("K线请求失败: %1").arg(r1->errorString()));   // 状态提示
                return;   // 返回
            }
            auto d = QJsonDocument::fromJson(r1->readAll()).object();   // 解析
            if (!d["ok"].toBool()) return;   // 业务失败返回
            QVector<CandleChart::Candle> cs;   // K线容器
            for (auto v : d["data"].toArray()) {   // 逐条转换
                auto o = v.toObject();   // 当条对象
                cs.push_back({jnum64(o["candle_time"]),   // 时间戳
                              jnum(o["o"]), jnum(o["h"]),   // 开/高
                              jnum(o["l"]), jnum(o["c"])});   // 低/收
            }
            chart->setCandles(cs);   // 增量合并进图表
            chart->lastTitle = QStringLiteral("%1 %2  收:%3  共%4根(含历史)")   // 更新图表标题
                                   .arg(inst, bar,   // 合约+周期
                                        cs.isEmpty() ? "-" : QString::number(cs.last().c),   // 最新收盘
                                        QString::number(chart->candles.size()));   // 总根数
            statusLabel->setText(QStringLiteral("实时刷新 | %1 根").arg(cs.size()));   // 状态提示
            if (fw && !cs.isEmpty()) fw->setPx(inst, cs.last().c);   // 同步价格到悬浮窗
        });

        // 止盈止损线 + 买卖点
        QUrl u2(API); u2.setQuery(QString("action=sltp&inst=%1").arg(inst));   // sltp 接口
        auto *r2 = nam->get(QNetworkRequest(u2));   // 发起 GET
        connect(r2, &QNetworkReply::finished, this, [this, r2] {   // 完成回调
            r2->deleteLater();   // 防泄漏
            auto d = QJsonDocument::fromJson(r2->readAll()).object();   // 解析
            QVector<CandleChart::Line> lines;   // 水平线容器
            for (auto v : d["data"].toArray()) {   // 逐持仓生成两条线
                auto o = v.toObject();   // 当条对象
                lines.push_back({jnum(o["tp_px"]), QColor(0xe0,0x31,0x31), QStringLiteral("止盈 %1").arg(jnum(o["tp_px"]))});   // 红色止盈线
                lines.push_back({jnum(o["stop_px"]), QColor(0x0c,0xa6,0x78), QStringLiteral("止损 %1").arg(jnum(o["stop_px"]))});   // 绿色止损线
            }
            chart->setLines(lines);   // 更新图表水平线
        });

        // 流水数据(交易流水表格; K线信号标注另由 marks 接口加载)
        QUrl u3(API); u3.setQuery(QString("action=trades"));   // trades 接口
        auto *r3 = nam->get(QNetworkRequest(u3));   // 发起 GET
        connect(r3, &QNetworkReply::finished, this, [this, r3] {   // 完成回调
            r3->deleteLater();   // 防泄漏
            auto d = QJsonDocument::fromJson(r3->readAll()).object();   // 解析
            if (!d["ok"].toBool()) return;   // 业务失败返回
            allFlowRows.clear();   // 重建流水缓存
            auto arr = d["data"].toArray();   // 流水数组
            for (auto v : arr) {   // 逐条处理
                auto o = v.toObject();   // 当条对象
                QString act = o["action"].toString();   // 动作类型
                if (act != "buy" && act != "close") continue;   // 只显示买入/平仓
                QDateTime t = QDateTime::fromString(o["trade_time"].toString(), "yyyy-M-d H:m:s");   // 先按无前导零解析
                if (!t.isValid()) t = QDateTime::fromString(o["trade_time"].toString(), "yyyy-MM-dd HH:mm:ss");   // 再按标准格式
                double px = jnum(o["price"]);   // 成交价
                // 流水行数据(缓存, 供筛选)
                double pnl = o.contains("profit") ? jnum(o["profit"]) : jnum(o["pnl"]);   // 盈亏字段兼容两种键名
                QString pos = o.value("pos_status").toString();   // 仓位状态
                QString ordId = o.value("ord_id").toString();   // 订单号(聚合同单用)
                // 同一笔订单的多笔部分成交(OKX fills 每笔成交记一行, 同 ord_id) 聚合成 1 行,
                // 防止看起来像"重复买入"(2026-09-24 用户反馈"同一个合约买了4次")
                if (act == "buy" && !ordId.isEmpty()) {   // 买入且有订单号
                    bool merged = false;   // 是否已并入现有行
                    for (auto &fr : allFlowRows) {   // 查同订单号的已有行
                        if (fr.act == "buy" && fr.ordId == ordId) {   // 命中
                            fr.px = px; fr.fills++; merged = true; break;   // 更新价格+成交笔数
                        }
                    }
                    if (merged) continue;   // 已并入则不新增行
                }
                allFlowRows.push_back({o["trade_time"].toString(), o["inst_id"].toString(),   // 时间+合约
                                       act, px, pnl, jnum(o["fee"]), jnum(o["funding"]), pos,   // 动作/价格/盈亏/费/资金费/仓位
                                       o.value("remark").toString(), ordId, 1});   // 备注/订单号/成交笔数
            }
            applyFilter();   // 重渲染流水表
        });

        // K线信号标注(2026-09-24 深夜指令): 买入🚀/加仓🚀/平仓🍃(附盈利两位小数), 时间只要 HH:MM
        QUrl u3b(API); u3b.setQuery(QString("action=marks&inst=%1&days=3").arg(inst));   // marks 接口(近3天)
        auto *r3b = nam->get(QNetworkRequest(u3b));   // 发起 GET
        connect(r3b, &QNetworkReply::finished, this, [this, r3b, inst] {   // 完成回调
            r3b->deleteLater();   // 防泄漏
            auto d = QJsonDocument::fromJson(r3b->readAll()).object();   // 解析
            QVector<CandleChart::Marker> ms;   // 标注容器
            if (d["ok"].toBool()) {   // 成功才处理
                for (auto v : d["data"].toArray()) {   // 逐条转换
                    auto o = v.toObject();   // 当条对象
                    QString kind = o["kind"].toString();   // 类型
                    int k = (kind == QStringLiteral("buy")) ? 0 : (kind == QStringLiteral("add")) ? 1 : 2;   // buy=0 add=1 其余(平仓)=2
                    double pf = o.value("profit").isNull() ? 0.0 : jnum(o["profit"]);   // 盈利(空则0)
                    ms.push_back({(qint64)jnum64(o["t"]), jnum(o["px"]), k, pf, o.value("strat").toString()});   // 时间/价格/类型/盈利/策略
                }
            }
            chart->setMarkers(ms);   // 更新标注
        });

        // K线信号(2026-09-26 二次精简): 后台预计算存 kline_signals, 客户端只读库渲染
        QUrl u3c(API); u3c.setQuery(QString("action=sigs&inst=%1&bar=%2").arg(inst, bar));   // sigs 接口
        auto *r3c = nam->get(QNetworkRequest(u3c));   // 发起 GET
        connect(r3c, &QNetworkReply::finished, this, [this, r3c] {   // 完成回调
            r3c->deleteLater();   // 防泄漏
            auto d = QJsonDocument::fromJson(r3c->readAll()).object();   // 解析
            QVector<CandleChart::SigMark> ss;   // 信号容器
            if (d["ok"].toBool()) {   // 成功才处理
                for (auto v : d["data"].toArray()) {   // 每条是 [时间, 掩码] 数组
                    auto a = v.toArray();   // 数组形式
                    ss.push_back({(qint64)a[0].toDouble(), (int)a[1].toDouble()});   // 时间+掩码
                }
            }
            chart->setSigs(ss);   // 更新信号
        });

        // 账户摘要(一行字) + 盈利K线(profit_curve 表, 实时落库)
        QUrl u4(API); u4.setQuery("action=stats");   // stats 接口
        auto *r4 = nam->get(QNetworkRequest(u4));   // 发起 GET
        connect(r4, &QNetworkReply::finished, this, [this, r4] {   // 完成回调
            r4->deleteLater();   // 防泄漏
            auto d = QJsonDocument::fromJson(r4->readAll()).object();   // 解析
            if (!d["ok"].toBool()) {   // 失败提示
                statLabel->setText(QStringLiteral("统计请求失败(%1), %2 重试中...")   // 错误+时间
                                       .arg(r4->errorString(),   // 错误串
                                            QTime::currentTime().toString("HH:mm:ss")));   // 当前时刻
                return;   // 返回
            }
            auto s = d["data"].toObject()["summary"].toObject();   // 汇总对象
            double net = jnum(s["net_pnl"]);   // 总净盈亏
            int total = (int)jnum(s["total"]), wins = (int)jnum(s["wins"]);   // 总笔数/盈利笔数
            double wr = total ? 100.0 * wins / total : 0;   // 胜率%
            // 引擎每分钟把总盈亏快照写入 pnl_history (数据库历史保存)
            QString snapT = QStringLiteral("--:--");   // 快照时间默认
            auto ls = d["data"].toObject()["last_snap"].toObject();   // 最近快照对象
            if (!ls.isEmpty()) snapT = ls["snap_time"].toString().mid(11, 5);   // HH:mm
            double posM = jnum(ls["pos_margin"]);   // 2026-09-25 用户: 仓位(当前持仓占用保证金USDT)
            // 一行字, 不换行; 2026-09-25 用户: 止损/网格/每分钟入库/更新时间 都删除
            Q_UNUSED(snapT);   // 快照时间暂不展示(防未用警告)
            statLabel->setText(QStringLiteral(   // 拼账户摘要一行(富文本)
                "总盈亏: <b style='color:%1'>%4 USDT</b> | 仓位: <b>%5 USDT</b> | 交易: %2笔 | 胜率: %3% | "
                "ETH九7信号版·100x只买涨·首仓30U·加仓=3m九7·每轮+1U·不限轮数·不设止损")
                .arg(net >= 0 ? "#e03131" : "#0ca678")   // 盈亏颜色(红盈绿亏)
                .arg(total).arg(wr, 0, 'f', 1)   // 笔数/胜率
                .arg((net >= 0 ? "+" : "") + QString::number(net, 'f', 2))   // 带符号盈亏
                .arg(QString::number(posM, 'f', 2)));   // 仓位
            if (fw) { fw->setPnl(net); fw->setPos(posM); }   // 同步到悬浮窗
        });

        // 2026-09-24 21:40 用户指令: 盈利K线面板已取消, profitt 拉取移除(3D报表中心里的盈利图保留)

        // 加仓信号 实时监测看板(每3秒轮询): 3m九7信号状态 + 信号历史
        QUrl u7(API); u7.setQuery("action=gridmon");   // gridmon 接口
        auto *r7 = nam->get(QNetworkRequest(u7));   // 发起 GET
        connect(r7, &QNetworkReply::finished, this, [this, r7] {   // 完成回调
            r7->deleteLater();   // 防泄漏
            auto d = QJsonDocument::fromJson(r7->readAll()).object();   // 解析
            if (!d["ok"].toBool() || !gridTable) return;   // 失败或控件未建返回
            auto data = d["data"].toObject();   // 数据体
            auto rows = data["rows"].toArray();   // 监测行数组
            gridTable->setRowCount(0);   // 清空重建
            int armed = 0, wait = 0, block = 0, closed = 0;   // 各状态计数
            for (auto v : rows) {   // 逐行渲染
                auto o = v.toObject();   // 当行对象
                int row = gridTable->rowCount();   // 新行号
                gridTable->insertRow(row);   // 插入行
                gridTable->setItem(row, 0, new QTableWidgetItem(o["inst_id"].toString()));   // 合约列
                // 2026-09-24 深夜: 「档位」列改「加仓数」= 真实已加仓轮数(台账 ladder_adds), 张数误导(131张被看成131次加仓)
                auto *addIt = new QTableWidgetItem(QString("%1 次").arg((int)jnum(o["ladder_adds"])));   // 加仓轮数
                addIt->setForeground(QBrush(QColor(0xe0,0x31,0x31)));   // 红色
                gridTable->setItem(row, 1, addIt);   // 加入
                gridTable->setItem(row, 2, new QTableWidgetItem(QString::number(jnum(o["last_px"]), 'g', 8)));   // 现价列
                // 止盈(带百分比的真实监控线; 距离%/下一档手数/止损列已按用户指令删除)
                double tpPx = jnum(o["tp_px"]), tpPct = jnum(o["tp_pct"]);   // 止盈价/止盈百分比
                auto *tpIt = new QTableWidgetItem(tpPx > 0   // 有止盈才显示
                    ? QString("%1\n+%2%").arg(QString::number(tpPx, 'g', 8)).arg(tpPct, 0, 'f', 1)   // 价格+百分比两行
                    : QStringLiteral("--"));   // 无止盈占位
                tpIt->setForeground(QBrush(QColor(0xe0,0x31,0x31)));   // 红=止盈
                gridTable->setItem(row, 3, tpIt);   // 加入
                QString st = o["state"].toString();   // 状态文字
                auto *stIt = new QTableWidgetItem(st);   // 状态单元格
                if (st.contains(QStringLiteral("已加仓"))) stIt->setForeground(QBrush(QColor(0xe0,0x31,0x31)));   // 红=已加仓
                else if (st.contains(QStringLiteral("风控"))) stIt->setForeground(QBrush(QColor(0xe8,0x7c,0x00)));   // 橙=风控拦截
                else if (st.contains(QStringLiteral("平仓"))) stIt->setForeground(QBrush(QColor(0x99,0x99,0x99)));   // 灰=已平仓
                else stIt->setForeground(QBrush(QColor(0x19,0x71,0xc2)));   // 蓝=其他(等待中)
                stIt->setToolTip(o["note"].toString());   // 悬浮显示说明
                gridTable->setItem(row, 4, stIt);   // 加入
                if (st.contains(QStringLiteral("已加仓")) || st.contains(QStringLiteral("已触发"))) armed++;   // 统计已触发
                else if (st.contains(QStringLiteral("风控"))) block++;   // 统计拦截
                else if (st.contains(QStringLiteral("平仓"))) closed++;   // 统计平仓
                else wait++;   // 其余=等待
            }
            for (int rr = 0; rr < gridTable->rowCount(); ++rr)    // 文字居中(2026-09-24 22:09)
                for (int cc = 0; cc < 5; ++cc)   // 全部5列
                    if (auto *it = gridTable->item(rr, cc)) it->setTextAlignment(Qt::AlignCenter);   // 单元格居中
            applyFilters(gridTable);                              // 保持Excel式列筛选
            gridHead->setText(QStringLiteral(   // 汇总一行
                "监测 %1 个持仓 | 等待 %2 | 已触发 %3 | 拦截 %4 | 已平仓 %5 | ETH模式·九7信号版: 首仓30U · 100x只买涨 · 止盈+2% · 加仓=3m九7·不限轮数(永远可加)·每轮+1U·无仓位上限 · 不设止损 · 更新 %6")
                .arg(rows.size()).arg(wait).arg(armed).arg(block).arg(closed)   // 各计数值
                .arg(QTime::currentTime().toString("HH:mm:ss")));   // 更新时刻
            gridLogs.clear();   // 重建信号历史缓存
            gridLogHeader = {QStringLiteral("时间"), QStringLiteral("合约"), QStringLiteral("类型"),   // 历史表9列头
                             QStringLiteral("档位"), QStringLiteral("当时价"), QStringLiteral("触发价"),
                             QStringLiteral("下一档手数"), QStringLiteral("状态"), QStringLiteral("说明")};
            for (auto v : data["logs"].toArray()) {   // 逐条历史
                auto o = v.toObject();   // 当条对象
                QStringList r;   // 一行单元格
                r << fmtNoYear(o["log_time"].toString())   // 时间(去年份)
                  << o["inst_id"].toString()   // 合约
                  << o["kind"].toString()   // 类型
                  << QString::number((int)jnum(o["level"]))   // 档位
                  << QString::number(jnum(o["last_px"]), 'g', 8)   // 当时价
                  << QString::number(jnum(o["trigger_px"]), 'g', 8)   // 触发价
                  << QString("%1$").arg(jnum(o["add_usd"]), 0, 'f', 2)   // 下一档手数($)
                  << o["state"].toString()   // 状态
                  << o["note"].toString();   // 说明
                gridLogs.push_back(r);   // 缓存
            }
        });

        // AI 记录（数据库 ai_learning 表, 带时间列表可滚动, 点击弹窗看详情）
        QUrl u5(API); u5.setQuery("action=ai");   // ai 接口
        auto *r5 = nam->get(QNetworkRequest(u5));   // 发起 GET
        connect(r5, &QNetworkReply::finished, this, [this, r5] {   // 完成回调
            r5->deleteLater();   // 防泄漏
            auto d = QJsonDocument::fromJson(r5->readAll()).object();   // 解析
            aiRecords.clear();   // 重建记录列表
            for (auto v : d["data"].toArray()) {   // 逐条转换
                auto o = v.toObject();   // 当条对象
                AiRec rec;   // 记录结构
                rec.time = o["learn_time"].toString();     // yyyy-MM-dd HH:mm:ss
                rec.adj = jnum(o["adjusted"]) != 0;        // PHP返回字符串"1"/"0", toInt()解析不了
                rec.text = o["suggestion"].toString();   // 策略正文
                rec.wr = jnum(o["win_rate"]);   // 当时胜率
                rec.profit = jnum(o["total_profit"]);   // 累计收益
                rec.cnt = (int)jnum(o["trade_count"]);   // 记录笔数
                aiRecords.push_back(rec);   // 入列表
            }
            renderAiTable();   // 渲染(隐藏表)并刷新跑马灯
        });
    }

    // ---- 深度回测三面板数据加载已整体移除(2026-09-25 用户指令) ----

    // AI表格渲染(2026-09-24 22:30: 表格转隐藏数据源, 顶栏滚动播放; 筛选功能删除)
    void renderAiTable() {   // AI记录写入隐藏表(供点击/跑马灯取数)
        aiTable->setRowCount(0);   // 清空
        for (int i = 0; i < aiRecords.size(); ++i) {   // 逐条
            const AiRec &rec = aiRecords[i];   // 记录引用
            QString summary = rec.text.section('\n', 0, 0);   // 只取首行摘要
            int row = aiTable->rowCount();   // 新行号
            aiTable->insertRow(row);   // 插入
            auto *tItem = new QTableWidgetItem(fmtNoYear(rec.time).section(':', 0, 1)); // 09-23 08:05 无年份
            tItem->setData(Qt::UserRole, i);            // 记住真实记录下标(筛选/排序后点击仍对得上)
            tItem->setForeground(QBrush(QColor(0x19,0x71,0xc2)));   // 时间蓝色
            aiTable->setItem(row, 0, tItem);   // 时间列
            auto *kItem = new QTableWidgetItem(rec.adj ? QStringLiteral("调参") : QStringLiteral("日报"));   // 类型文字
            kItem->setData(Qt::UserRole, i);   // 同样带真实下标
            kItem->setForeground(QBrush(rec.adj ? QColor(0xe0,0x31,0x31) : QColor(0x0c,0xa6,0x78)));   // 调参红/日报绿
            aiTable->setItem(row, 1, kItem);   // 类型列
            // 表格只显示第一行摘要, 完整内容点击弹窗
            auto *sItem = new QTableWidgetItem(summary);   // 摘要列
            sItem->setData(Qt::UserRole, i);   // 真实下标
            sItem->setToolTip(QStringLiteral("点击查看完整策略内容"));   // 提示
            aiTable->setItem(row, 2, sItem);   // 摘要列
        }
        for (int rr = 0; rr < aiTable->rowCount(); ++rr)      // 文字居中(2026-09-24 22:09)
            for (int cc = 0; cc < 3; ++cc)   // 全部3列
                if (auto *it = aiTable->item(rr, cc)) it->setTextAlignment(Qt::AlignCenter);   // 居中
        updateAiMarquee();                                     // 顶栏滚动播放
        if (aiTable->rowCount() == 0)   // 无记录
            aiTable->setRowCount(1),   // 建一行占位
            aiTable->setItem(0, 0, new QTableWidgetItem(QStringLiteral("AI引擎学习中,暂无记录")));   // 占位文字
    }

    // AI自动策略滚动播放: 最近8条首行连成循环文本, 每900ms移动一字(用户22:40: 比最初慢3倍)
    void updateAiMarquee() {   // 更新跑马灯文本并启动滚动
        if (!aiMarqLab) return;   // 控件未建返回
        QStringList parts;   // 各条摘要
        for (int i = 0; i < aiRecords.size() && i < 8; ++i) {   // 最近8条
            const AiRec &rec = aiRecords[i];   // 记录
            parts << QStringLiteral("%1 %2 %3")   // 时间+类型+首行摘要(截40字)
                         .arg(fmtNoYear(rec.time).section(':', 0, 1))   // MM-dd HH:mm
                         .arg(rec.adj ? QStringLiteral("【调参】") : QStringLiteral("【日报】"))   // 类型标记
                         .arg(rec.text.section('\n', 0, 0).left(40));   // 摘要
        }
        aiMqText = parts.join(QStringLiteral("　◆　"));   // 用◆拼接
        if (aiMqText.isEmpty()) aiMqText = QStringLiteral("AI引擎学习中,暂无记录");   // 空提示
        aiMqText += QStringLiteral("　◆　");   // 尾部接缝
        if (!mqTimer) {   // 定时器懒创建
            mqTimer = new QTimer(this);   // 创建(父托管)
            mqTimer->setInterval(900);   // 900ms 一字
            connect(mqTimer, &QTimer::timeout, this, [this] {   // 每拍滚动一位
                if (aiMqText.isEmpty()) return;   // 无文本返回
                mqPos = (mqPos + 1) % aiMqText.size();   // 循环推进
                aiMarqLab->setText(aiMqText.mid(mqPos) + aiMqText.left(mqPos));   // 旋转拼接实现滚动
            });
        }
        mqTimer->start();   // 启动
    }

    // AI详情弹窗(顶栏「AI详情」按钮): 最近40条完整内容
    void showAiDetailDlg() {   // AI详情对话框
        QDialog dlg(this);   // 模态对话框
        dlg.setWindowTitle(QStringLiteral("AI 自动策略详情(最近40条)"));   // 标题
        dlg.resize(980, 640);   // 尺寸
        auto *lay = new QVBoxLayout(&dlg);   // 布局
        auto *tb = new QTextBrowser;   // 富文本浏览
        QString html;   // 拼接HTML
        for (int i = 0; i < aiRecords.size() && i < 40; ++i) {   // 最近40条
            const AiRec &rec = aiRecords[i];   // 记录
            html += QStringLiteral("<div style='margin-bottom:10px;'>"   // 每条一块: 标题行+正文
                                   "<b style='color:%1;'>【%2 · %3】</b><br><pre style='white-space:pre-wrap;"
                                   "font-family:LiSu;margin:4px 0 0 0;font-size:14pt;'>%4</pre></div>")
                        .arg(rec.adj ? QStringLiteral("#e03131") : QStringLiteral("#0ca678"),   // 类型色
                             fmtNoYear(rec.time), rec.adj ? QStringLiteral("调参") : QStringLiteral("日报"),   // 时间+类型
                             rec.text.toHtmlEscaped());   // 转义正文
        }
        tb->setHtml(html.isEmpty() ? QStringLiteral("(暂无AI记录)") : html);   // 空提示或正文
        lay->addWidget(tb);   // 加入
        dlg.exec();   // 模态显示
    }

    // 加仓信号历史弹窗: 每一次触发/加仓/被风控拦截都留痕(来自 grid_signal_log 表)
    void showGridLog() {   // 信号历史对话框
        if (gridLogs.isEmpty()) {   // 无记录
            statusLabel->setText(QStringLiteral("信号历史: 暂无记录(还没有触发过加仓信号)"));   // 状态提示
            return;   // 不弹窗
        }
        QDialog dlg(this);   // 对话框
        dlg.setWindowTitle(QStringLiteral("加仓信号历史 (3m九7 触发·加仓·风控拦截 都留痕)"));   // 标题
        dlg.resize(1120, 560);   // 尺寸
        auto *lay = new QVBoxLayout(&dlg);   // 布局
        auto *t = new QTableWidget(gridLogs.size(), gridLogHeader.isEmpty() ? 9 : gridLogHeader.size(), &dlg);   // 历史表
        t->setHorizontalHeaderLabels(gridLogHeader.isEmpty()   // 表头(有缓存用缓存)
            ? QStringList{QStringLiteral("时间"), QStringLiteral("合约"), QStringLiteral("类型"),
                          QStringLiteral("档位"), QStringLiteral("当时价"), QStringLiteral("触发价"),
                          QStringLiteral("下一档手数"), QStringLiteral("状态"), QStringLiteral("说明")}
            : gridLogHeader);
        t->horizontalHeader()->setSectionResizeMode(8, QHeaderView::Stretch);   // 说明列撑满
        t->setColumnWidth(0, 116);   // 时间列宽
        t->setColumnWidth(1, 132);   // 合约列宽
        t->setColumnWidth(7, 110);   // 状态列宽
        t->setEditTriggers(QAbstractItemView::NoEditTriggers);   // 禁止编辑
        t->setSelectionBehavior(QAbstractItemView::SelectRows);   // 整行选择
        t->verticalHeader()->setVisible(false);   // 隐藏行号
        for (int i = 0; i < gridLogs.size(); ++i)   // 逐行
            for (int c = 0; c < gridLogs[i].size(); ++c)   // 逐列
                t->setItem(i, c, new QTableWidgetItem(gridLogs[i][c]));   // 填单元格
        lay->addWidget(t, 1);   // 表格占满
        auto *btn = new QPushButton(QStringLiteral("关闭"), &dlg);   // 关闭按钮
        connect(btn, &QPushButton::clicked, &dlg, &QDialog::accept);   // 点击关闭
        lay->addWidget(btn, 0);   // 底部按钮
        dlg.exec();   // 模态显示
    }

    // 策略体检: 拉 backcheck 报告弹窗展示(37策略x5周期全量回测核实)
    void runBackcheck() {   // 拉取并展示体检报告
        statusLabel->setText(QStringLiteral("策略体检: 读取报告..."));   // 状态提示
        QUrl u(API); u.setQuery("action=backcheck");   // backcheck 接口
        auto *r = nam->get(QNetworkRequest(u));   // 发起 GET
        connect(r, &QNetworkReply::finished, this, [this, r] {   // 完成回调
            r->deleteLater();   // 防泄漏
            auto d = QJsonDocument::fromJson(r->readAll()).object();   // 解析
            QString txt = d["data"].toString();   // 报告文本
            if (txt.isEmpty()) txt = QStringLiteral("体检报告获取失败: %1").arg(r->errorString());   // 失败提示
            statusLabel->setText(QStringLiteral("策略体检报告已加载"));   // 状态提示
            QDialog dlg(this);   // 报告对话框
            dlg.setWindowTitle(QStringLiteral("全策略回测体检报告 (ETH模式·九7信号版 实盘同参; 策略库健康度参考)"));   // 标题
            dlg.resize(900, 640);   // 尺寸
            auto *lay = new QVBoxLayout(&dlg);   // 布局
            auto *txtView = new QTextEdit(&dlg);   // 纯文本编辑器
            txtView->setReadOnly(true);   // 只读
            txtView->setFont(QFont("Consolas", 9));   // 等宽字体
            txtView->setPlainText(txt);   // 报告内容
            lay->addWidget(txtView, 1);   // 占满
            auto *btn = new QPushButton(QStringLiteral("关闭"), &dlg);   // 关闭按钮
            connect(btn, &QPushButton::clicked, &dlg, &QDialog::accept);   // 点击关闭
            lay->addWidget(btn, 0);   // 底部
            dlg.exec();   // 模态显示
        });
    }

    // 点击AI记录 -> 弹窗显示该次策略完整内容
    void onAiClicked(int row, int col) {   // AI记录点击(当前为隐藏表, 预留)
        Q_UNUSED(col);   // 列号未用
        int idx = row;   // 默认下标=行号
        if (auto *it = aiTable->item(row, 2)) idx = it->data(Qt::UserRole).toInt();   // 优先取真实下标
        if (idx < 0 || idx >= aiRecords.size()) return;   // 越界返回
        const AiRec &r = aiRecords[idx];   // 记录
        QString full = QStringLiteral("时间: %1\n类型: %2\n胜率: %3%   记录笔数: %4   收益: %5%\n\n%6")   // 详情文本
                           .arg(r.time, r.adj ? QStringLiteral("AI自动调参") : QStringLiteral("AI日报"))   // 时间+类型
                           .arg(r.wr, 0, 'f', 1).arg(r.cnt).arg(r.profit, 0, 'f', 2)   // 胜率/笔数/收益
                           .arg(r.text.isEmpty() ? QStringLiteral("(该条无详细内容)") : r.text);   // 正文
        QDialog dlg(this);   // 对话框
        dlg.setWindowTitle(QStringLiteral("AI 策略详情  -  %1").arg(r.time));   // 标题
        dlg.resize(600, 500);   // 尺寸
        auto *lay = new QVBoxLayout(&dlg);   // 布局
        auto *txt = new QTextEdit(&dlg);   // 文本区
        txt->setReadOnly(true);   // 只读
        txt->setFont(QFont("Microsoft YaHei", 10));   // 字体
        txt->setPlainText(full);   // 内容
        lay->addWidget(txt, 1);   // 占满
        auto *btn = new QPushButton(QStringLiteral("关闭"), &dlg);   // 关闭按钮
        connect(btn, &QPushButton::clicked, &dlg, &QDialog::accept);   // 点击关闭
        lay->addWidget(btn, 0);   // 底部
        dlg.exec();   // 模态显示
    }

    // 流水渲染(2026-09-25 用户: 筛选分类/关键字搜索/Excel列筛选全部取消, 纯净全量显示; 点表头仍可排序)
    void applyFilter() {   // 全量渲染流水表
        flowTable->setSortingEnabled(false);   // 渲染期间关排序(防行错位)
        flowTable->setRowCount(0);   // 清空
        for (const auto &r : allFlowRows) {   // 逐行渲染
            int row = flowTable->rowCount();   // 新行号
            flowTable->insertRow(row);   // 插入
            auto *tItem = new QTableWidgetItem(fmtNoYear(r.time));   // 09-23 08:05:33 无年份完整显示
            flowTable->setItem(row, 0, tItem);   // 时间列
            auto *instIt = new QTableWidgetItem(r.inst);   // 合约单元格
            instIt->setForeground(QBrush(QColor(0x1c,0x7e,0xd6)));   // 蓝色 = 可点击
            QFont uf = instIt->font(); uf.setUnderline(true); instIt->setFont(uf);   // 加下划线示可点
            flowTable->setItem(row, 1, instIt);   // 合约列
            flowTable->setItem(row, 2, new QTableWidgetItem(r.act == "buy" ? QStringLiteral("买入") : QStringLiteral("平仓")));   // 方向列
            auto *pxIt = new QTableWidgetItem;                       // 数值型排序(Excel式)
            pxIt->setText(QString::number(r.px, 'f', 4));   // 显示4位小数
            pxIt->setData(Qt::EditRole, r.px);   // 存数值供排序
            flowTable->setItem(row, 3, pxIt);   // 价格列
            auto *pnlIt = new QTableWidgetItem;   // 盈亏单元格
            pnlIt->setText(r.pnl ? QString::number(r.pnl, 'f', 2) : QStringLiteral("-"));   // 0显示"-"
            pnlIt->setData(Qt::EditRole, r.pnl);   // 数值排序
            pnlIt->setForeground(r.pnl > 0 ? QBrush(QColor(0xe0,0x31,0x31)) : QBrush(r.pnl < 0 ? QColor(0x0c,0xa6,0x78) : QColor(0x33,0x33,0x33)));   // 红盈/绿亏/灰0
            flowTable->setItem(row, 4, pnlIt);   // 盈亏列
            auto *feeIt = new QTableWidgetItem;                     // 手续费(负=支出, 2026-09-25 按实际)
            feeIt->setText(r.fee ? QString::number(r.fee, 'f', 4) : QStringLiteral("-"));   // 0显示"-"
            feeIt->setData(Qt::EditRole, r.fee);   // 数值排序
            feeIt->setForeground(QBrush(QColor(0x88,0x88,0x88)));   // 灰色
            flowTable->setItem(row, 5, feeIt);   // 手续费列
            auto *fundIt = new QTableWidgetItem;                    // 资金费(正收/负付, 2026-09-25 按实际)
            fundIt->setText(r.fund ? QString::number(r.fund, 'f', 4) : QStringLiteral("-"));   // 0显示"-"
            fundIt->setData(Qt::EditRole, r.fund);   // 数值排序
            fundIt->setForeground(QBrush(QColor(0x88,0x88,0x88)));   // 灰色
            flowTable->setItem(row, 6, fundIt);   // 资金费列
            if (r.act == "buy" && r.fills > 1)      // 买入行标注"同单多笔成交"聚合数
                flowTable->item(row, 2)->setText(QStringLiteral("买入(%1笔成交)").arg(r.fills));   // 方向列注明笔数
        }
        for (int rr = 0; rr < flowTable->rowCount(); ++rr)    // 文字居中(2026-09-24 22:09)
            for (int cc = 0; cc < 7; ++cc)   // 全部7列
                if (auto *it = flowTable->item(rr, cc)) it->setTextAlignment(Qt::AlignCenter);   // 居中
        flowTable->setSortingEnabled(true);   // 恢复点表头排序
    }

    // 点流水里的合约名 -> 左边K线切换到该合约
    void onFlowClicked(QTableWidgetItem *it) {   // 流水表点击
        if (!it || it->column() != 1) return;   // 只响应合约列
        QString inst = it->text().trimmed().toUpper();   // 规整大写
        if (inst.isEmpty() || !inst.contains("-")) return;   // 非法返回
        int ix = instCombo->findText(inst);   // 2026-09-25: 非可编辑下拉 → setCurrentIndex
        if (ix >= 0) instCombo->setCurrentIndex(ix);   // 命中则切换(触发刷新)
    }
};

int main(int argc, char *argv[]) {   // 程序入口
    QApplication app(argc, argv);   // Qt 应用对象
    app.setApplicationName("OKX Trading Terminal");   // 应用名
    QFont f(QStringLiteral("隶书"), 10);   // 2026-09-24 22:09 用户指令: 全局字体改隶书
    app.setFont(f);   // 应用全局字体

    // ---- 全局美化: 立体渐变按钮(按下变色) + 表格/表头/输入框/滚动条 精修 ----
    app.setStyleSheet(QStringLiteral(   // 全局 QSS 样式表
        "QMainWindow, QDialog { background: #f4f6fa; }"   // 窗口浅灰蓝底
        "QPushButton {"   // 按钮: 立体蓝色渐变
        "  background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #5b93ff, stop:0.5 #3b78f0, stop:1 #2f6fed);"
        "  color: white; border: 1px solid #2557c8; border-radius: 6px;"   // 白字+深蓝描边+圆角
        "  padding: 5px 16px; font-weight: bold; font-size: 13px;"   // 内距/加粗/字号
        "}"
        "QPushButton:hover {"   // 悬停: 提亮渐变
        "  background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #78a6ff, stop:0.5 #5588f5, stop:1 #4479f2);"
        "}"
        "QPushButton:pressed {"   // 按下: 压暗+下移视觉
        "  background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #1d47a8, stop:1 #2a56c0);"
        "  border-color: #16347c; padding-top: 7px; padding-bottom: 3px;"
        "}"
        "QGroupBox { background: #ffffff; border: 1px solid rgba(90,110,160,.25); border-radius: 10px;"   // 分组框: 白底圆角
        "  margin-top: 14px; padding: 10px 6px 6px 6px; font-weight: bold; }"   // 顶部留标题位
        /* 每个面板不同的柔和渐变底色(参考 dribbble 流行卡配色) */
        "QGroupBox#flowBox { background: qlineargradient(x1:0,y1:0,x2:1,y2:1, stop:0 #f2fbf7, stop:1 #e8f7fb); }"   // 流水面板: 绿青渐变
        "QGroupBox#gridBox { background: qlineargradient(x1:0,y1:0,x2:1,y2:1, stop:0 #fff8ec, stop:1 #fdeee6); }"   // 监测面板: 米橙渐变
        "QGroupBox::title { subcontrol-origin: margin; subcontrol-position: top center; left: 0; right: 0; padding: 0 6px;"   // 标题居中在边框上
        "  background: transparent; font-size: 15pt; font-weight: bold; letter-spacing: 1px; }"   /* 小三号(用户22:30); 2026-09-25 用户: 标题居中 */
        /* 标题各自不同金属色 + 悬停变亮 */
        "QGroupBox#flowBox::title { color: #0e9f6e; font-size: 10.5pt; }"   /* 2026-09-25 用户: 交易流水标题改5号字(原15pt太大, 下面表格看不见) */
        "QGroupBox#gridBox::title { color: #e8590c; }"   // 监测标题: 橙
        "QGroupBox:hover::title { color: #d6336c; }"   // 悬停: 玫红
        "QTableWidget { background: rgba(255,255,255,.88);"   // 表格: 半透明白底
        "  gridline-color: #e6ebf3; border: 1px solid rgba(90,110,160,.2); border-radius: 6px;"   // 浅网格+圆角边
        "  selection-background-color: #cfe2ff; selection-color: #16347c; font-size: 14px; }"   // 选中蓝/字号
        "QTableWidget::item { padding: 3px 4px; }"   // 单元格内距
        "QTableWidget::item:selected { border: 1px solid #2f6fed; }"   // 选中项蓝框
        /* 两张表表头配色不同: 流水=祖母绿, 监测=琥珀金 */
        "QTableWidget#flowTable QHeaderView::section { background: qlineargradient(x1:0,y1:0,x2:0,y2:1,"   // 流水表头: 绿色渐变
        "  stop:0 #d8f5e6, stop:1 #b2e6cd); border-bottom: 2px solid #0ca678; color: #0b5c40;"
        "  font-size: 10.5pt; }"   /* 2026-09-25 用户: 流水列名改5号字(10.5pt), 全局表头15pt对它不生效 */
        "QTableWidget#flowTable { alternate-background-color: #eefaf3; }"   // 流水隔行色: 淡绿
        "QTableWidget#gridTable QHeaderView::section { background: qlineargradient(x1:0,y1:0,x2:0,y2:1,"   // 监测表头: 金色渐变
        "  stop:0 #ffe8c2, stop:1 #ffd39e); border-bottom: 2px solid #e8590c; color: #8a3a00; }"
        "QTableWidget#gridTable { alternate-background-color: #fff6ea; }"   // 监测隔行色: 淡金
        "QHeaderView::section { background: qlineargradient(x1:0,y1:0,x2:0,y2:1,"   // 通用表头: 浅灰渐变
        "  stop:0 #fbfcfe, stop:1 #e8edf6); border: none; border-bottom: 2px solid #2f6fed;"
        "  padding: 5px 8px; font-size: 15pt; font-weight: bold; color: #33415c; }"   /* 小三号 */
        "QHeaderView::section:hover { background: #dce9ff; }"   // 表头悬停提亮
        "QTableCornerButton::section { background: #e8edf6; border: none; }"   // 左上角块
        /* 悬浮提示: 隶书四号 + 暗色底(用户22:30) */
        "QToolTip { font-family: 'LiSu'; font-size: 15pt; color: #f3e9d6;"
        "  background-color: #232a36; border: 1px solid #4a5568; padding: 4px 8px; }"   // 暗底浅字提示
        "QComboBox { background: white; border: 1px solid #cfd6e4; border-radius: 6px; padding: 4px 10px; }"   // 下拉框
        "QComboBox:hover { border-color: #2f6fed; }"   // 悬停蓝边
        "QComboBox::drop-down { border: none; width: 20px; }"   // 下拉箭头区
        "QComboBox QAbstractItemView { background: white; selection-background-color: #2f6fed; }"   // 弹出列表
        "QLineEdit { background: white; border: 1px solid #cfd6e4; border-radius: 6px; padding: 4px 8px; }"   // 输入框
        "QLineEdit:focus { border: 1px solid #2f6fed; }"   // 聚焦蓝边
        "QScrollBar:vertical { background: #eef1f6; width: 10px; border-radius: 5px; }"   // 纵向滚动条槽
        "QScrollBar::handle:vertical { background: #c3cbdb; border-radius: 5px; min-height: 30px; }"   // 滑块
        "QScrollBar::handle:vertical:hover { background: #2f6fed; }"   // 滑块悬停蓝
        "QScrollBar:horizontal { background: #eef1f6; height: 10px; border-radius: 5px; }"   // 横向滚动条槽
        "QScrollBar::handle:horizontal { background: #c3cbdb; border-radius: 5px; min-width: 30px; }"   // 滑块
        "QScrollBar::add-line, QScrollBar::sub-line { height: 0; width: 0; }"   // 隐藏步进箭头
        "QScrollBar::add-page, QScrollBar::sub-page { background: transparent; }"   // 页区透明
        "QLabel { background: transparent; }"   // 标签透明底
        "QTextEdit { background: white; border: 1px solid #e0e5ef; border-radius: 6px; }"   // 文本编辑
        "QTabWidget::pane { border: 1px solid #e0e5ef; border-radius: 6px; background: #ffffff; top: -1px; }"   // 选项卡面板
        "QTabBar::tab { background: qlineargradient(x1:0,y1:0,x2:0,y2:1, stop:0 #f4f6fa, stop:1 #e8edf6);"   // 选项卡: 灰蓝渐变
        "  border: 1px solid #e0e5ef; border-bottom: none; padding: 7px 20px; margin-right: 3px;"   // 内距+间距
        "  border-top-left-radius: 8px; border-top-right-radius: 8px; font-weight: bold; color: #66708a; }"   // 上圆角
        "QTabBar::tab:selected { background: #ffffff; color: #2f6fed; border-bottom: 2px solid #2f6fed; }"   // 选中: 白底蓝字
        "QTabBar::tab:hover { color: #2f6fed; }"   // 悬停蓝字
        "QTextBrowser { background: white; border: 1px solid #e0e5ef; border-radius: 6px; }"   // 富文本浏览框
    ));

    // --reports: 直达 AI 报表中心弹窗(3D图表+日报/调参/反思)
    if (app.arguments().contains(QStringLiteral("--reports"))) {   // 命令行带 --reports
        ReportCenter rc;   // 只建报表中心
        rc.show();   // 显示
        return app.exec();   // 进入事件循环(不进主窗口)
    }

    MainWindow w;   // 创建主窗口
    w.show();   // 显示
    return app.exec();   // 进入 Qt 事件循环直至退出
}
