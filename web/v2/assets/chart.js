/* ============================================================
 *  chart.js — Vue3 面板的图表核心
 *  基于 TradingView Lightweight Charts v5 库(lwc5.js)封装，全量重写约 900 行
 *  【文件职责】
 *    对外暴露 window.OKXChart.mount(el, opts)：在指定 DOM 容器内构建完整行情图表，
 *    并返回一组操作 API（数据更新/历史分页/信号标注/绘图工具/配置切换等），
 *    供 Vue 面板与 3 秒尾部轮询模块驱动。
 *  【核心功能清单】
 *    1. K线主图 + 副图 pane：VOL / MACD / RSI / KDJ，最多 3 个副图，任意组合
 *    2. 数据更新双通道：
 *       · setTail(rows) 增量更新 —— 3 秒尾部轮询逐根 series.update，
 *         覆盖末根(实时价跳动)或追加新根(收线)
 *       · setData(rows,{merge}) 全量刷新 —— merge 模式按时间合并旧数据，
 *         不冲掉已加载的历史分页
 *    3. 历史分页(拖到最左自动翻页)：subscribeVisibleLogicalRangeChange 中
 *       逻辑区间 from < 30 触发 onNeedHistory 回调 → 外部请求
 *       /kline?before=最早根ms 加载更老K线 → prepend 前置插入 + 视口整体平移(无感)；
 *       后端 histEnd(返回 < 600 根)后停止；8s 超时兜底防止回调丢失卡死；
 *       MAX_BARS = 20000 内存保护上限
 *    4. 图表类型切换：candles(蜡烛) / bars(美国线) / line(线) / area(面积)
 *    5. 指标叠加：主图 MA / EMA / BOLL；副图指标每次数据变更全量重算
 *       (万根级别耗时 < 2ms，3 秒一次无压力)
 *    6. canvas 绘图工具(原生叠加层)：趋势线/水平线/垂直线/射线/矩形/
 *       斐波那契回撤 6 种 + 6 色；右键删除 / 双击清空 / 撤销；
 *       localStorage 按 "合约|周期" 键持久化，页面重建后自动恢复
 *    7. setSignals(marks, pivots, lines, th)：接收后端信号标注 ——
 *       · 交易标记 marks：买🚀/加仓▲/平仓🍃 标签
 *       · 转折点 pivots：ZigZag 顶底点(前端同根K线去重)
 *       · 金▲标注：仅底部点且下跌腿动能 ke >= th(与引擎 gold_signal 同口径的
 *         75分位真实阈值)时显示大号金色"金▲"标签，否则只显示小灰点
 *       · lines：均价 / 止盈 / 止盈水平虚线
 *    8. 时区处理：所有时间戳统一 +8h(OFF=28800s) 后按 UTC 呈现 → 图面即北京时间
 * ============================================================ */
window.OKXChart = (function () {                       // 全局图表模块 OKXChart：IIFE 立即执行，对外只暴露 mount 一个入口
  var LWC = window.LightweightCharts;                  // 缓存 lightweight-charts 库(lwc5.js)命名空间，后续建图全靠它
  var OFF = window.OKXU.TZ; // 28800 秒                // 北京时间偏移量(秒)：时间戳 +8h 后按 UTC 渲染，图面即北京时间
  var MAX_BARS = 20000;     // 内存保护上限            // 单合约内存中最多保留的 K 线根数（历史分页的硬上限）

  /* ══════════════════ 指标数学(纯函数) ══════════════════ */
  function smaA(v, n) {                                // 简单移动平均 SMA：输入数值数组 v、周期 n，返回同长数组(不足周期处为 null)
    var out = new Array(v.length), s = 0;              // out 为输出数组，s 为滑动窗口和
    for (var i = 0; i < v.length; i++) {               // 遍历每个数据点
      s += v[i];                                       // 累加当前值进窗口和
      if (i >= n) s -= v[i - n];                       // 窗口满后减去滑出的最早值，保持窗口长度为 n
      out[i] = i >= n - 1 ? s / n : null;              // 凑满 n 个才输出均值，否则置 null
    }
    return out;                                        // 返回 SMA 序列
  }
  function emaA(v, n) {                                // 指数移动平均 EMA：平滑系数 k=2/(n+1)，前 n-1 个为 null
    var out = new Array(v.length), k = 2 / (n + 1), prev = null; // out 输出，k 平滑系数，prev 上一轮 EMA 值
    for (var i = 0; i < v.length; i++) {               // 遍历数据
      prev = prev === null ? v[i] : v[i] * k + prev * (1 - k); // 首个值直接作种子，之后按标准 EMA 递推
      out[i] = i >= n - 1 ? prev : null;               // 同样前 n-1 个不输出
    }
    return out;                                        // 返回 EMA 序列
  }
  function stdA(v, n) {                                // 滚动标准差 STD(周期 n)：BOLL 上下轨计算用
    var out = new Array(v.length);                     // 输出数组
    for (var i = 0; i < v.length; i++) {               // 逐点计算
      if (i < n - 1) { out[i] = null; continue; }      // 窗口未满置 null
      var m = 0, j;                                    // m 为窗口均值，j 循环变量
      for (j = i - n + 1; j <= i; j++) m += v[j];      // 累加窗口内 n 个值
      m /= n;                                          // 求均值
      var s = 0;                                       // s 为方差累加器
      for (j = i - n + 1; j <= i; j++) { var d = v[j] - m; s += d * d; } // 累加各点偏差平方
      out[i] = Math.sqrt(s / n);                       // 总体标准差 = sqrt(方差)
    }
    return out;                                        // 返回 STD 序列
  }
  function rsiA(c, n) {                                // RSI(相对强弱指标)：Wilder 平滑法，c 为收盘价数组
    var out = new Array(c.length), i;                  // out 输出，i 循环变量
    for (i = 0; i < c.length; i++) out[i] = null;      // 先全部初始化为 null
    if (c.length <= n) return out;                     // 数据不足周期，直接返回全 null
    var g = 0, l = 0;                                  // g 平均涨幅、l 平均跌幅
    for (i = 1; i <= n; i++) { var d = c[i] - c[i - 1]; if (d >= 0) g += d; else l -= d; } // 首个窗口：累计涨/跌幅度
    g /= n; l /= n;                                    // 取窗口平均，得到初始 avg gain / avg loss
    out[n] = l === 0 ? 100 : 100 - 100 / (1 + g / l);  // 第 n 点输出首个 RSI = 100 - 100/(1+RS)
    for (i = n + 1; i < c.length; i++) {               // 之后逐点 Wilder 递推
      var dd = c[i] - c[i - 1];                        // 当日涨跌
      var up = dd > 0 ? dd : 0, dn = dd < 0 ? -dd : 0; // 拆出涨幅与跌幅(正数)
      g = (g * (n - 1) + up) / n;                      // Wilder 平滑平均涨幅
      l = (l * (n - 1) + dn) / n;                      // Wilder 平滑平均跌幅
      out[i] = l === 0 ? 100 : 100 - 100 / (1 + g / l); // 输出该点 RSI
    }
    return out;                                        // 返回 RSI 序列
  }
  function kdjA(h, l, c, n, m1, m2) {                  // KDJ 指标：h/l/c 为高低收数组，n=RSV周期，m1/m2 为 K、D 平滑周期
    var kn = new Array(c.length), dn = new Array(c.length), jn = new Array(c.length); // K/D/J 三条输出序列
    var K = 50, D = 50;                                // K、D 初始种子值(中性 50)
    for (var i = 0; i < c.length; i++) {               // 逐点递推
      if (i < n - 1) { kn[i] = dn[i] = jn[i] = null; continue; } // 窗口未满置 null
      var hh = -Infinity, ll = Infinity;               // 窗口内最高价/最低价
      for (var j = i - n + 1; j <= i; j++) { if (h[j] > hh) hh = h[j]; if (l[j] < ll) ll = l[j]; } // 扫窗口求高低
      var rsv = (hh - ll) === 0 ? 50 : (c[i] - ll) / (hh - ll) * 100; // RSV = 收盘在高低区间内的位置百分比(区间为0时取50)
      K = (K * (m1 - 1) + rsv) / m1;                   // K = RSV 的 m1 周期平滑
      D = (D * (m2 - 1) + K) / m2;                     // D = K 的 m2 周期平滑
      kn[i] = K; dn[i] = D; jn[i] = 3 * K - 2 * D;     // 记录 K、D，J = 3K - 2D
    }
    return { k: kn, d: dn, j: jn };                    // 返回三条序列
  }

  var MA_COLORS = ["#f0b90b", "#3b82f6", "#8b5cf6", "#14b8a6", "#ec4899", "#f97316"]; // MA/EMA 线的固定调色板(6色循环)

  function hasMarkersApi() { return typeof LWC.createSeriesMarkers === "function"; } // 检测 lwc5 是否提供 createSeriesMarkers 标记 API(版本兼容)

  function mount(el, o) {                              // 对外主入口：在容器 el 上挂载图表，o 为可选配置(onHover/onNeedHistory 等回调)
    o = o || {};                                       // 参数缺省为空对象
    el.style.position = el.style.position || "relative"; // 容器需相对定位，canvas 叠加层才能绝对定位铺满

    var cfg = Object.assign({                          // 图表配置对象：默认值 + 用户传入覆盖
      type: "candles",     // candles | bars | line | area // 主图类型：蜡烛/美国线/折线/面积
      updown: "cn",        // cn=红涨绿跌  us=绿涨红跌    // 涨跌配色习惯：中式/美式
      grid: true,                                         // 是否显示网格线
      magnet: false,                                      // 十字光标是否磁吸到 K 线
      log: false,                                         // 价格轴是否对数刻度
      marks: true, pivots: true,                          // 是否显示交易标记/算法转折点图层
      avgline: true, tpline: true,                        // 是否显示均价线/止盈止损线
      ma: [5, 10, 20, 60],                                // 默认叠加 4 条 MA
      ema: [],                                            // 默认不叠加 EMA
      boll: { on: false, n: 20, k: 2 },                   // BOLL 默认关闭(20周期2倍标准差)
      panes: ["vol", "macd"],                             // 默认副图：成交量 + MACD
      rsiN: 14,                                           // RSI 周期
      kdjN: [9, 3, 3],                                    // KDJ 参数(9,3,3)
      barSpacing: 7,                                      // 初始每根K线像素宽
      rightOffset: 8,                                     // 最新K线右侧留白根数
    }, o.cfg || {});                                     // 用户 cfg 覆盖默认值

    // 兼容旧 layers 入参                                  // 旧版接口用 layers 描述图层，这里映射到新 cfg 字段
    if (o.layers) {                                      // 若传了旧版 layers
      if (o.layers.marks != null) cfg.marks = o.layers.marks;      // 映射交易标记开关
      if (o.layers.pivots != null) cfg.pivots = o.layers.pivots;   // 映射转折点开关
      var pn = [];                                       // 临时副图列表
      if (o.layers.vol) pn.push("vol");                  // layers.vol → 副图 vol
      if (o.layers.macd) pn.push("macd");                // layers.macd → 副图 macd
      if (pn.length) cfg.panes = pn;                     // 有映射结果才覆盖 panes
    }

    var chart = null, cs = null, cv = null, ctx = null;  // chart 图表实例 / cs 主图系列 / cv 绘图 canvas / ctx 其 2d 上下文
    var maS = {}, emaS = {}, bollS = null, paneS = {};   // MA 系列集合(按周期索引) / EMA 集合 / BOLL 三线 / 副图系列集合(按名称)
    var priceLines = [], markerPrim = null;              // 主图价格线(均价/止盈止损)数组 / 标记系列句柄
    var lastRows = [], byTime = Object.create(null), times = []; // 最新K线数据(行数组) / 时间→行 的映射 / 时间戳数组(与 lastRows 同序)
    var lastMarks = [], lastPivots = [], lastLines = null, lastTh = 0; // 缓存的信号数据：交易标记 / 转折点 / 均价止盈线 / 金▲KE阈值
    var savedRange = null, suppress = false, atLatest = true; // 重建图表时保存的视口范围 / 抑制范围回调标志 / 当前是否停在最新K线
    var hoverCb = o.onHover || null, rangeCb = o.onAtLatest || null; // 十字光标悬停回调 / 是否在最新端状态变化回调
    var histCb = o.onNeedHistory || null;                // 历史分页回调：需要更老K线时通知外部去请求 /kline?before=
    var histBusy = false, histEnd = false, histAt = 0;   // 历史加载进行中标志 / 历史已到底标志 / 本次触发时间戳(8s超时兜底用)
    var key = "default";                                 // 绘图工具持久化键(实际为 合约|周期，由 setKey 设置)
    var draw = {                                         // 绘图工具状态对象
      tool: "cursor", color: "#f0b90b", width: 1.5,      // 当前工具(默认光标) / 画笔颜色 / 线宽
      shapes: [], draft: null, dirty: true, raf: 0, lastT: 0, // 已保存图形列表 / 正在拖拽的半成品 / 需重绘标志 / 动画帧ID / 上帧时间(节流)
    };

    /* ─────── 配色 ─────── */
    function upCol() { return cfg.updown === "us" ? "#0ecb81" : "#ff4d4f"; } // 阳线颜色：美式绿涨 / 中式红涨
    function dnCol() { return cfg.updown === "us" ? "#ff4d4f" : "#0ecb81"; } // 阴线颜色：美式红跌 / 中式绿跌
    function isOHLC() { return cfg.type === "candles" || cfg.type === "bars"; } // 当前类型是否为 OHLC 形态(蜡烛/美国线)，决定数据点结构

    /* ─────── 图表选项 ─────── */
    function chartOpts() {                               // 生成 LWC createChart 的整体选项(每次重建图表时调用)
      return {                                           // 返回配置字面量
        autoSize: true,                                  // 跟随容器尺寸自动调整
        layout: {                                        // 布局/字体
          background: { type: "solid", color: "transparent" }, // 背景透明(由页面深色主题透出)
          textColor: "#7d8ca6",                          // 文字颜色(轴标签)
          fontSize: 10,                                  // 字号
          fontFamily: "Inter, system-ui, 'PingFang SC', 'Microsoft YaHei', sans-serif", // 字体栈(含中文字体)
          attributionLogo: false,                        // 隐藏库的 attribution logo
        },
        grid: {                                          // 网格线
          vertLines: { color: cfg.grid ? "rgba(27,36,52,.85)" : "transparent" }, // 竖网格：可开关
          horzLines: { color: cfg.grid ? "rgba(27,36,52,.85)" : "transparent" }, // 横网格：可开关
        },
        crosshair: {                                     // 十字光标
          // 磁吸模式(Magnet)吸附到K线，否则普通模式；老版本库无 CrosshairMode 时兜底为 0
          mode: (cfg.magnet && LWC.CrosshairMode) ? LWC.CrosshairMode.Magnet : (LWC.CrosshairMode ? LWC.CrosshairMode.Normal : 0),
          vertLine: { color: "rgba(240,185,11,.45)", width: 1, style: 2, labelBackgroundColor: "#253247" }, // 竖线样式(虚线金色)与时间标签底色
          horzLine: { color: "rgba(240,185,11,.45)", width: 1, style: 2, labelBackgroundColor: "#253247" }, // 横线样式与价格标签底色
        },
        rightPriceScale: {                               // 右侧价格轴
          borderColor: "#1b2434",                        // 轴边框色
          scaleMargins: { top: 0.08, bottom: 0.08 },     // 上下留白 8%
          entireTextOnly: true,                          // 刻度标签完整显示不裁半
          mode: cfg.log ? 1 : 0,                         // 1=对数刻度 0=普通刻度
        },
        timeScale: {                                     // 底部时间轴
          borderColor: "#1b2434",                        // 轴边框色
          timeVisible: true,                             // 显示时分
          secondsVisible: false,                         // 不显示秒
          rightOffset: cfg.rightOffset,                  // 最新K线右侧留白
          barSpacing: cfg.barSpacing,                    // 初始K线宽度
          minBarSpacing: 0.15,                           // 缩放下限(可压缩到极小看全局)
          fixLeftEdge: false,                            // 不锁定左边缘(允许往左拖触发历史加载)
          lockVisibleTimeRangeOnResize: true,            // 窗口缩放时保持可见范围不变
          tickMarkFormatter: function (time, type) {     // 自定义刻度文案：按刻度级别显示 年/年月/月日/时分
            var d = new Date(time * 1000);               // 秒 → 毫秒转 Date(已加过8h偏移，用 UTC 读数即北京时间)
            function p2(n) { return (n < 10 ? "0" : "") + n; } // 两位数补零工具
            var Y = d.getUTCFullYear(), M = p2(d.getUTCMonth() + 1), D = p2(d.getUTCDate()); // 年/月/日
            var h = p2(d.getUTCHours()), m = p2(d.getUTCMinutes()); // 时/分
            if (type === 0) return String(Y);            // 级别0：跨年处只显示年
            if (type === 1) return Y + "-" + M;          // 级别1：年-月
            if (type === 2) return M + "-" + D;          // 级别2：月-日
            if (type === 3) return h + ":" + m;          // 级别3：时:分
            return M + "-" + D + " " + h + ":" + m;      // 兜底：月-日 时:分
          },
        },
        localization: {                                  // 本地化
          locale: "zh-CN",                               // 中文环境
          timeFormatter: function (time) {               // 十字光标/悬浮时间完整格式
            var d = new Date(time * 1000);               // 秒→毫秒
            function p2(n) { return (n < 10 ? "0" : "") + n; } // 补零
            return d.getUTCFullYear() + "-" + p2(d.getUTCMonth() + 1) + "-" + p2(d.getUTCDate()) +
              " " + p2(d.getUTCHours()) + ":" + p2(d.getUTCMinutes()); // 输出 "YYYY-MM-DD HH:mm"(北京时间)
          },
        },
        handleScroll: { mouseWheel: true, pressedMouseMove: true, horzTouchDrag: true, vertTouchDrag: false }, // 滚轮/拖拽水平平移，禁止垂直拖动
        handleScale: { axisPressedMouseMove: true, mouseWheel: true, pinch: true }, // 支持拖轴缩放/滚轮缩放/双指缩放
      };
    }

    /* ═══════════════ 价格线(均价/止盈/止损) ═══════════════ */
    function clearLines() {                              // 移除主图上所有价格线
      if (!cs) return;                                   // 主图系列不存在直接返回
      for (var i = 0; i < priceLines.length; i++) { try { cs.removePriceLine(priceLines[i]); } catch (e) { } } // 逐条移除(异常静默)
      priceLines = [];                                   // 清空句柄数组
    }
    function addLine(spec) {                             // 添加一条价格线：spec={price,color,title}
      if (!cs || !spec || !(spec.price > 0)) return;     // 参数无效(价格为0/负)则跳过
      try {                                              // 防御库版本差异
        priceLines.push(cs.createPriceLine({             // 在主图上创建价格线并记录句柄
          price: spec.price, color: spec.color, lineWidth: 1, // 价格/颜色/线宽
          lineStyle: LWC.LineStyle ? LWC.LineStyle.Dashed : 2, // 虚线样式(老版本用数字2兜底)
          axisLabelVisible: true, title: spec.title || "", // 轴上显示标签，可带标题文字
        }));
      } catch (e) { }                                    // 失败静默
    }
    function syncLines() {                               // 按配置与最新信号数据重建全部价格线
      clearLines();                                      // 先清空旧的
      if (cfg.avgline) addLine(lastLines && lastLines.avg ? { price: lastLines.avg, color: "#f0b90b", title: "均价" } : null); // 均价线(金色)
      if (cfg.tpline) addLine(lastLines && lastLines.tp ? { price: lastLines.tp, color: "#0ecb81", title: "止盈 +2%" } : null); // 止盈线(绿色)
      if (cfg.tpline) addLine(lastLines && lastLines.sl ? { price: lastLines.sl, color: "#ff4d4f", title: "止损" } : null);     // 止损线(红色)
    }

    /* ═══════════════ 信号标记 ═══════════════ */
    function buildMarkers() {                            // 根据缓存信号重建K线上的所有标记(买/加/平 + 转折点)
      if (!cs) return;                                   // 主图未建好直接返回
      var arr = [];                                      // 收集全部标记点
      if (cfg.marks && times.length) {                   // ── 交易标记图层开启且有数据 ──
        var t0 = times[0], vis = [];                     // t0 为当前最早K线时间，vis 为可见标记
        for (var z = 0; z < lastMarks.length; z++) if (Math.floor(lastMarks[z].t / 1000) + OFF >= t0) vis.push(lastMarks[z]); // 只保留图表范围内(+8h后)的标记
        if (vis.length > 500) vis = vis.slice(vis.length - 500); // 超过500个时只取最近500个，防止标记过密拖慢渲染
        for (var i = 0; i < vis.length; i++) {           // 遍历可见标记
          var m = vis[i];                                // 当前标记
          if (m.kind !== "buy" && m.kind !== "add" && m.kind !== "close") continue; // 只处理 买/加/平 三类
          var tm = window.OKXU.alignToBar(times, Math.floor(m.t / 1000) + OFF); // 把标记时间对齐到已存在的K线(二分找<=t的最近bar)
          if (tm < 0) continue;                          // 对齐失败(超出范围)跳过
          if (m.kind === "buy") arr.push({ time: tm, position: "belowBar", color: upCol(), shape: "arrowUp", text: "🚀买 2U", size: 1.4 }); // 首买：阳线色上箭头"🚀买 2U"
          else if (m.kind === "add") arr.push({ time: tm, position: "belowBar", color: "#f0b90b", shape: "arrowUp", text: "▲加仓 +1U", size: 1.1 }); // 加仓：金色箭头(每次+1U, 同根3m不重复)
          else {                                         // 平仓标记
            var pf = m.profit == null ? 0 : m.profit;    // 平仓盈亏(缺省0)
            arr.push({                                   // 上方箭头，颜色随盈亏正负
              time: tm, position: "aboveBar", color: pf >= 0 ? upCol() : dnCol(), shape: "arrowDown",
              text: "🍃平 " + (pf >= 0 ? "+" : "") + pf.toFixed(2) + "U", size: 1.1, // 文案带盈亏金额
            });
          }
        }
      }
      if (cfg.pivots && times.length) {                  // ── 转折点(ZigZag)图层开启 ──
        var seenP = {};                                  // 去重表：同根K线同类型只画一个
        for (var k = 0; k < lastPivots.length; k++) {    // 遍历后端转折点
          var p = lastPivots[k];                         // 当前点{t,type,ke}
          var t2 = window.OKXU.alignToBar(times, Math.floor(p.t / 1000) + OFF); // 时间对齐到K线
          if (t2 < 0) continue;                          // 超范围跳过
          var pk = t2 + "|" + (p.type === 0 ? "b" : "t"); // 去重键：K线时间+类型(底/顶)
          if (seenP[pk]) continue;                       // 同根K线去重(ZigZag 宽幅K线会重复输出)
          seenP[pk] = 1;                                 // 登记已见
          var bottom = p.type === 0;                     // type 0=底部转折 1=顶部转折
          // 真·金▲ = 底部且下跌腿动能 KE >= 该合约底部KE的75分位阈值(与引擎 gold_signal 完全同口径)
          var sig = p.ke > 0 && lastTh > 0 && p.ke >= lastTh; // 是否达到金▲阈值：ke>0 且后端给过阈值 th 且 ke>=th
          var col, sz, txt = "";                         // 颜色/尺寸/文案
          if (bottom) {                                  // 底部点
            if (sig) { col = "#f0b90b"; sz = 1.2; txt = "金▲ KE" + Math.round(p.ke); } // 达标：金色大点+金▲KE值
            else { col = "#8b5cf6"; sz = 0.6; txt = p.ke > 0 ? "KE" + Math.round(p.ke) : ""; } // 未达标：紫色小点
          } else {                                       // 顶部点
            col = "#3b82f6";                             // 蓝色
            if (sig) { sz = 1.1; txt = "顶 KE" + Math.round(p.ke); } else { sz = 0.6; } // 达标显示"顶 KE"文案，否则小点
          }
          arr.push({ time: t2, position: bottom ? "belowBar" : "aboveBar", color: col, shape: "circle", text: txt, size: sz }); // 底部画在K线下方，顶部画上方
        }
      }
      arr.sort(function (a, b) { return a.time - b.time; }); // 标记必须按时间升序(lwc 要求)
      if (markerPrim && typeof markerPrim.setMarkers === "function") markerPrim.setMarkers(arr); // 已有标记系列：直接替换内容
      else if (hasMarkersApi()) markerPrim = LWC.createSeriesMarkers(cs, arr); // lwc5：创建标记系列
      else if (typeof cs.setMarkers === "function") cs.setMarkers(arr);        // 老版本 lwc4 兼容：系列自带 setMarkers
    }

    /* ═══════════════ 建图 ═══════════════ */
    function build() {                                   // (重)建图表：销毁旧实例，按当前 cfg 创建主图+副图+叠加线
      if (chart) { try { chart.remove(); } catch (e) { } chart = null; } // 销毁旧图表实例(异常静默)
      cs = null; maS = {}; emaS = {}; bollS = null; paneS = {}; markerPrim = null; priceLines = []; // 全部系列句柄复位
      if (cv && cv.parentNode) cv.parentNode.removeChild(cv); // 移除旧绘图 canvas
      cv = ctx = null;                                   // canvas 与上下文置空

      chart = LWC.createChart(el, chartOpts());          // 在容器上创建图表

      var prec = window.OKXU.precOf(lastRows.length ? lastRows[lastRows.length - 1][4] : 1); // 按最新收盘价推断价格小数位
      var pf = { type: "price", precision: prec, minMove: Math.pow(10, -prec) }; // 价格格式：精度+最小变动
      var def;                                           // 主图系列类型
      if (cfg.type === "bars") def = LWC.BarSeries;      // 美国线
      else if (cfg.type === "line") def = LWC.LineSeries; // 折线
      else if (cfg.type === "area") def = LWC.AreaSeries; // 面积
      else def = LWC.CandlestickSeries;                  // 默认蜡烛

      var csOpt = { priceLineVisible: false, lastValueVisible: true, priceFormat: pf }; // 主图系列公共选项：不画现价线、显示最新值
      if (cfg.type === "candles") {                      // 蜡烛图配色
        csOpt.upColor = upCol(); csOpt.downColor = dnCol();         // 阳/阴实体色
        csOpt.borderUpColor = upCol(); csOpt.borderDownColor = dnCol(); // 阳/阴边框色
        csOpt.wickUpColor = upCol(); csOpt.wickDownColor = dnCol();     // 阳/阴影线色
      } else if (cfg.type === "bars") {                  // 美国线配色
        csOpt.upColor = upCol(); csOpt.downColor = dnCol();         // 阳/阴色
      } else if (cfg.type === "line") {                  // 折线样式
        csOpt.color = "#e8eefb"; csOpt.lineWidth = 1.4; csOpt.crosshairMarkerVisible = true; // 浅白线+十字圆点
      } else {                                           // 面积样式
        csOpt.lineColor = "#f0b90b"; csOpt.topColor = "rgba(240,185,11,.28)"; // 金色线+顶部渐变
        csOpt.bottomColor = "rgba(240,185,11,0)"; csOpt.lineWidth = 1.4;      // 底部透明渐变
      }
      cs = chart.addSeries(def, csOpt, 0);               // 在 pane0(主图)创建主图系列

      // 主图叠加: MA                                   // ── 按配置创建 MA 线系列 ──
      for (var i = 0; i < cfg.ma.length; i++) {          // 遍历 MA 周期列表
        var p = +cfg.ma[i];                              // 周期转数字
        if (!(p > 1)) continue;                          // 非法周期跳过
        maS[p] = chart.addSeries(LWC.LineSeries, {       // 以周期为键创建线系列
          color: MA_COLORS[i % MA_COLORS.length], lineWidth: 1, priceLineVisible: false, // 调色板取色
          lastValueVisible: false, crosshairMarkerVisible: false, // 不显示最新值/十字点
        }, 0);
      }
      // 主图叠加: EMA                                  // ── EMA 线(虚线区分) ──
      for (var j = 0; j < cfg.ema.length; j++) {         // 遍历 EMA 周期列表
        var q = +cfg.ema[j];                             // 周期转数字
        if (!(q > 1)) continue;                          // 非法跳过
        emaS[q] = chart.addSeries(LWC.LineSeries, {      // 创建 EMA 线系列
          color: MA_COLORS[(j + 2) % MA_COLORS.length], lineWidth: 1.1, // 错开取色避免与 MA 撞色
          lineStyle: LWC.LineStyle ? LWC.LineStyle.Dashed : 2,          // 虚线样式
          priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false, // 隐藏多余元素
        }, 0);
      }
      // 主图叠加: BOLL                                  // ── 布林带三线 ──
      if (cfg.boll && cfg.boll.on) {                     // BOLL 开启时
        bollS = {                                        // 上轨/中轨/下轨三个线系列
          up: chart.addSeries(LWC.LineSeries, { color: "rgba(59,130,246,.9)", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, 0), // 上轨(蓝)
          mid: chart.addSeries(LWC.LineSeries, { color: "rgba(240,185,11,.85)", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, 0), // 中轨(金)
          dn: chart.addSeries(LWC.LineSeries, { color: "rgba(59,130,246,.9)", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, 0),  // 下轨(蓝)
        };
      }

      // 副图 pane                                      // ── 按配置创建副图(最多3个) ──
      for (var n = 0; n < cfg.panes.length; n++) {       // 遍历副图名称列表
        var pi = n + 1, name = cfg.panes[n], g = { i: pi }; // 副图索引从1开始 / 名称 / 该副图的系列集合
        if (name === "vol") {                            // 成交量副图
          g.vol = chart.addSeries(LWC.HistogramSeries, { priceFormat: { type: "volume" }, priceLineVisible: false, lastValueVisible: false }, pi); // 柱状图(成交量格式)
        } else if (name === "macd") {                    // MACD 副图：柱 + DIF线 + DEA线
          g.hist = chart.addSeries(LWC.HistogramSeries, { priceLineVisible: false, lastValueVisible: false }, pi);  // 红绿柱
          g.dif = chart.addSeries(LWC.LineSeries, { color: "#f0b90b", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, pi); // DIF 快线(金)
          g.dea = chart.addSeries(LWC.LineSeries, { color: "#3b82f6", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, pi); // DEA 慢线(蓝)
        } else if (name === "rsi") {                     // RSI 副图
          g.rsi = chart.addSeries(LWC.LineSeries, { color: "#8b5cf6", lineWidth: 1.2, priceLineVisible: false, lastValueVisible: true, crosshairMarkerVisible: false }, pi); // RSI 线(紫，显示最新值)
          try {                                          // 加 70/30 超买超卖参考线
            g.rsi.createPriceLine({ price: 70, color: "rgba(255,77,79,.5)", lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: "70" }); // 超买线70
            g.rsi.createPriceLine({ price: 30, color: "rgba(14,203,129,.5)", lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: "30" }); // 超卖线30
          } catch (e) { }                                // 失败静默
        } else if (name === "kdj") {                     // KDJ 副图：K/D/J 三线
          g.k = chart.addSeries(LWC.LineSeries, { color: "#f0b90b", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, pi); // K(金)
          g.d = chart.addSeries(LWC.LineSeries, { color: "#3b82f6", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, pi); // D(蓝)
          g.j = chart.addSeries(LWC.LineSeries, { color: "#ec4899", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, pi); // J(粉)
        }
        paneS[name] = g;                                 // 登记到副图集合
      }

      // pane 高度(主图占大头)                           // ── 分配各 pane 的拉伸比例 ──
      try {                                              // 老版本库可能无 panes API，整体 try
        var ps = chart.panes();                          // 取全部 pane
        if (ps && ps.length > 1 && ps[0].setStretchFactor) { // 支持拉伸因子时
          ps[0].setStretchFactor(6);                     // 主图占 6 份(大头)
          for (var x = 1; x < ps.length; x++) {          // 逐个副图分配
            if (!ps[x].setStretchFactor) continue;       // API 缺失跳过
            var nm = cfg.panes[x - 1];                   // 该副图名称
            ps[x].setStretchFactor(nm === "macd" ? 1.5 : (nm === "vol" ? 1.1 : 1.3)); // MACD 稍高、VOL 稍矮
          }
        }
        if (ps && ps.length > 1) {                       // 副图价格轴样式统一
          for (var y = 1; y < ps.length; y++) {          // 遍历副图
            try { ps[y].priceScale("right").applyOptions({ scaleMargins: { top: 0.12, bottom: 0.05 }, borderColor: "#1b2434" }); } catch (e2) { } // 副图右轴留白与边框色
          }
        }
      } catch (e) { }                                    // 整体兜底

      applyData(true);                                   // 把已缓存的数据灌入新系列
      syncLines();                                       // 重建价格线
      buildMarkers();                                    // 重建信号标记

      chart.subscribeCrosshairMove(function (param) {    // 订阅十字光标移动 → 向上抛悬停数据(盘中面板用)
        if (!hoverCb) return;                            // 未注册回调直接返回
        if (!param || !param.time || !param.point) { hoverCb(null); return; } // 移出K线区域时回调 null
        var row = byTime[param.time];                    // 由时间索引回查原始K线行
        if (!row) { hoverCb(null); return; }             // 查不到(空隙)回调 null
        hoverCb({                                        // 组装悬停数据包
          time: param.time, x: param.point.x, y: param.point.y, // 对齐后的时间与像素坐标
          tstr: window.OKXU.tsFull(row[0] * 1000),       // 完整北京时间字符串
          o: row[1], h: row[2], l: row[3], c: row[4],    // 开高低收
          dif: row[5], dea: row[6], hist: row[7], vol: row[11], // MACD 三值与成交量
          pane: param.paneIndex,                         // 光标所在 pane 索引
        });
        draw.dirty = true;                               // 光标移动也需要重绘叠加层
      });

      chart.timeScale().subscribeVisibleLogicalRangeChange(function (r) { // 订阅可视逻辑区间变化(缩放/平移都触发)
        if (!r) return;                                  // 空回调忽略
        draw.dirty = true;                               // 视口变了需要重绘叠加图形
        if (suppress) return;                            // 程序内部设置视口时抑制逻辑，避免误触发
        var tot = lastRows.length;                       // 当前K线总数
        var nowAt = r.to >= tot - 2.5;                   // 视口右端贴近最后一根 → 认为停在最新端
        if (nowAt !== atLatest) { atLatest = nowAt; if (rangeCb) rangeCb(atLatest); } // 状态变化时通知外部(如"回到最新"按钮显隐)
        // 拖到最左 → 自动向更早翻页(8s 超时兜底, 防止回调丢失后卡死)
        if (r.from < 30 && (!histBusy || (Date.now() - histAt > 8000)) && !histEnd && times.length && histCb && tot < MAX_BARS) { // 触发条件：左端<30根、非忙(或已超8秒)、未到底、有回调、未超上限
          histBusy = true; histAt = Date.now();          // 置忙并记录触发时刻(供超时兜底)
          histCb(times[0] - OFF);                        // 回调外部：传入最早K线的原始毫秒时间(即 /kline?before= 参数)
        }
      });

      if (savedRange) {                                  // 重建图表前保存过视口 → 恢复
        suppress = true;                                 // 抑制范围内的翻页/状态回调
        try { chart.timeScale().setVisibleLogicalRange(savedRange); } catch (e) { }  // 恢复视口
        suppress = false;                                // 解除抑制
      }

      mountCanvas();                                     // 挂载绘图 canvas 叠加层
      if (cv) cv.style.pointerEvents = draw.tool === "cursor" ? "none" : "auto"; // 光标工具时 canvas 不拦截鼠标，绘图工具时拦截
      draw.dirty = true;                                 // 标记需要重绘
    }

    /* ═══════════════ 数据 ═══════════════ */
    function mkPt(r) {                                   // 把一行K线数据 [t,o,h,l,c,...] 转成 lwc 数据点
      var t = r[0] + OFF;                                // 时间戳加8h偏移(秒)
      return isOHLC()                                    // 蜡烛/美国线用 OHLC 四价
        ? { time: t, open: r[1], high: r[2], low: r[3], close: r[4] }
        : { time: t, value: r[4] };                      // 线/面积只用收盘价
    }

    function applyData(full) {                           // 全量重灌数据：由 lastRows 重建主图/成交量/全部指标
      if (!cs || !lastRows.length) return;               // 无系列或无数据直接返回
      var c = [], v = [];                                // c 主图数据点数组，v 成交量点数组
      byTime = Object.create(null); times = [];          // 重建索引：时间→行映射、时间数组
      var uv = cfg.updown === "us" ? "rgba(14,203,129,.32)" : "rgba(255,77,79,.32)"; // 阳线成交量色(半透明)
      var dv = cfg.updown === "us" ? "rgba(255,77,79,.32)" : "rgba(14,203,129,.32)"; // 阴线成交量色
      for (var i = 0; i < lastRows.length; i++) {        // 遍历全部K线
        var r = lastRows[i], t = r[0] + OFF;             // 当前行与偏移后时间
        c.push(mkPt(r));                                 // 主图数据点
        byTime[t] = r; times.push(t);                    // 更新索引
        v.push({ time: t, value: r[11] || 0, color: r[4] >= r[1] ? uv : dv }); // 成交量点(收盘>=开盘为阳)
      }
      try { cs.setData(c); } catch (e) { console.warn("candle setData", e); } // 灌入主图(失败打印警告)
      var vg = paneS.vol;                                // 取成交量副图
      if (vg && vg.vol) { try { vg.vol.setData(v); } catch (e) { } } // 灌入成交量
      applyIndicators();                                 // 重算并灌入全部指标线
      void full;                                         // full 参数保留(当前全量重算无差别)
    }

    /* 指标(MA/EMA/BOLL/副图): 全量重算 —— 万根级别耗时 <2ms, 3 秒一次无压力 */
    function applyIndicators() {                         // 重算所有指标线并 setData(数据变更后统一调用)
      if (!lastRows.length) return;                      // 无数据直接返回
      var n = lastRows.length, i, t;                     // n 为根数
      var closes = new Array(n), highs = new Array(n), lows = new Array(n); // 抽出 收/高/低 三个一维数组供指标函数用
      for (i = 0; i < n; i++) { closes[i] = lastRows[i][4]; highs[i] = lastRows[i][2]; lows[i] = lastRows[i][3]; } // 抽数
      function toLine(arr) {                             // 把指标数值数组转成 lwc 线数据点
        var out = [];                                    // 输出点数组
        // 从可视区左侧往前多取, 保证线进视野时不是断头
        for (var k = 0; k < n; k++) if (arr[k] != null && isFinite(arr[k])) out.push({ time: times[k], value: arr[k] }); // 跳过 null/非有限值
        return out;                                      // 返回线数据
      }
      for (var key1 in maS) {                            // ── 逐条 MA ──
        if (!maS.hasOwnProperty(key1)) continue;         // 跳过原型链属性
        try { maS[key1].setData(toLine(smaA(closes, +key1))); } catch (e) { } // 计算并灌入 SMA
      }
      for (var key2 in emaS) {                           // ── 逐条 EMA ──
        if (!emaS.hasOwnProperty(key2)) continue;        // 跳过原型链
        try { emaS[key2].setData(toLine(emaA(closes, +key2))); } catch (e) { } // 计算并灌入 EMA
      }
      if (bollS) {                                       // ── BOLL 三轨 ──
        var bn = +cfg.boll.n || 20, bk = +cfg.boll.k || 2; // 周期/倍数(缺省 20/2)
        var mid = smaA(closes, bn), sd = stdA(closes, bn); // 中轨=SMA，标准差
        var up = [], dn = [];                            // 上/下轨数组
        for (i = 0; i < n; i++) {                        // 逐点合成
          up[i] = mid[i] == null ? null : mid[i] + bk * sd[i]; // 上轨 = 中轨 + k*STD
          dn[i] = mid[i] == null ? null : mid[i] - bk * sd[i]; // 下轨 = 中轨 - k*STD
        }
        try { bollS.mid.setData(toLine(mid)); bollS.up.setData(toLine(up)); bollS.dn.setData(toLine(dn)); } catch (e) { } // 灌入三线
      }
      var g;                                             // 副图引用
      if ((g = paneS.macd)) {                            // ── MACD 副图：直接用后端算好的 DIF/DEA/HIST(第5/6/7列) ──
        var dif = [], dea = [], his = [];                // 三个点数组
        for (i = 0; i < n; i++) {                        // 遍历K线
          var r = lastRows[i]; t = times[i];             // 行与时间
          if (r[5] != null && isFinite(r[5])) dif.push({ time: t, value: r[5] }); // DIF 点
          if (r[6] != null && isFinite(r[6])) dea.push({ time: t, value: r[6] }); // DEA 点
          if (r[7] != null && isFinite(r[7])) his.push({ time: t, value: r[7], color: r[7] >= 0 ? "rgba(255,77,79,.6)" : "rgba(14,203,129,.6)" }); // 柱：正红负绿
        }
        try { g.dif.setData(dif); g.dea.setData(dea); g.hist.setData(his); } catch (e) { } // 灌入三系列
      }
      if ((g = paneS.rsi)) {                             // ── RSI 副图 ──
        try { g.rsi.setData(toLine(rsiA(closes, +cfg.rsiN || 14))); } catch (e) { } // 前端重算 RSI
      }
      if ((g = paneS.kdj)) {                             // ── KDJ 副图 ──
        var kj = kdjA(highs, lows, closes, (cfg.kdjN[0] || 9), (cfg.kdjN[1] || 3), (cfg.kdjN[2] || 3)); // 前端重算 K/D/J
        try { g.k.setData(toLine(kj.k)); g.d.setData(toLine(kj.d)); g.j.setData(toLine(kj.j)); } catch (e) { } // 灌入三线
      }
    }

    /* ═══════════════ 坐标换算(绘图用) ═══════════════ */
    function logicalOfTime(t) {                          // 时间 → 逻辑索引(可为小数，落在两根K线之间时线性插值)
      var n = times.length;                              // K线根数
      if (!n) return 0;                                  // 无数据返回 0
      if (t <= times[0]) { var a = n > 1 ? times[1] - times[0] : 60; return (t - times[0]) / a; } // 早于首根：按首根间隔外推
      if (t >= times[n - 1]) { var b = n > 1 ? times[n - 1] - times[n - 2] : 60; return (n - 1) + (t - times[n - 1]) / b; } // 晚于末根：按末段间隔外推
      var lo = 0, hi = n - 1;                            // 二分查找夹逼
      while (hi - lo > 1) { var m = (lo + hi) >> 1; if (times[m] <= t) lo = m; else hi = m; } // 找到 t 所在的两根之间
      var iv = (times[hi] - times[lo]) || 1;             // 该处K线间隔(防0)
      return lo + (t - times[lo]) / iv;                  // 线性插值出小数逻辑索引
    }
    function xOfTime(t) {                                // 时间 → 像素 x 坐标(绘图用)
      var x = null;                                      // 结果
      try { x = chart.timeScale().timeToCoordinate(t); } catch (e) { } // 首选官方 API
      if (x != null) return x;                           // 命中直接返回
      try { x = chart.timeScale().logicalToCoordinate(logicalOfTime(t)); } catch (e) { } // 兜底：先转逻辑索引再转坐标(时间超出数据范围时)
      return x;                                          // 返回坐标(可能为 null)
    }
    function timeOfX(x) {                                // 像素 x → 时间(绘图取点用)
      var t = null;                                      // 结果
      try { t = chart.timeScale().coordinateToTime(x); } catch (e) { } // 首选官方 API
      if (t != null) return t;                           // 命中返回
      var lg = null;                                     // 逻辑索引兜底
      try { lg = chart.timeScale().coordinateToLogical(x); } catch (e) { } // x → 逻辑索引
      if (lg == null) return null;                       // 转换失败
      var n = times.length;                              // 根数
      if (!n) return null;                               // 无数据
      if (lg <= 0) { var a = n > 1 ? times[1] - times[0] : 60; return Math.round(times[0] + lg * a); } // 左端外推
      if (lg >= n - 1) { var b = n > 1 ? times[n - 1] - times[n - 2] : 60; return Math.round(times[n - 1] + (lg - (n - 1)) * b); } // 右端外推
      var i0 = Math.floor(lg), f = lg - i0, iv = (times[i0 + 1] - times[i0]) || 60; // 取整段索引、小数部分、间隔
      return Math.round(times[i0] + f * iv);             // 线性插值出时间(秒)
    }
    function yOfPrice(p) { try { return cs.priceToCoordinate(p); } catch (e) { return null; } } // 价格 → 像素 y
    function priceOfY(y) { try { return cs.coordinateToPrice(y); } catch (e) { return null; } } // 像素 y → 价格

    /* ═══════════════ 绘图工具 ═══════════════ */
    function loadShapes() {                              // 从 localStorage 恢复该"合约|周期"的绘图图形
      draw.shapes = [];                                  // 先清空
      try {                                              // JSON 解析防御
        var raw = localStorage.getItem("okx.draw." + key); // 读取持久化串
        if (raw) { var a = JSON.parse(raw); if (a && a.length) draw.shapes = a; } // 有内容则恢复
      } catch (e) { }                                    // 解析失败当无数据
      draw.dirty = true;                                 // 触发重绘
    }
    function saveShapes() {                              // 图形列表持久化到 localStorage
      try { localStorage.setItem("okx.draw." + key, JSON.stringify(draw.shapes)); } catch (e) { } // 按 key 存 JSON(失败静默)
    }
    function plotSize() {                                // 取主图绘图区尺寸(宽=时间轴宽，高=主 pane 高)
      var w = 0, h = 0;                                  // 宽高
      try { w = chart.timeScale().width(); } catch (e) { } // 时间轴宽度≈绘图区宽
      try { h = chart.panes()[0].getHeight(); } catch (e) { } // 主 pane 高度
      if (!w) w = el.clientWidth;                        // 兜底：容器宽
      if (!h) h = el.clientHeight * 0.7;                 // 兜底：容器高 70%
      return { w: w, h: h };                             // 返回尺寸
    }

    function drawShape(s, ghost) {                       // 在 canvas 上绘制一个图形；ghost=true 表示拖拽中的半成品(半透明)
      var ps = plotSize();                               // 绘图区尺寸
      ctx.save();                                        // 保存画布状态
      ctx.globalAlpha = ghost ? 0.75 : 1;                // 半成品略透明
      ctx.strokeStyle = s.color || "#f0b90b";            // 描边色
      ctx.fillStyle = s.color || "#f0b90b";              // 填充色
      ctx.lineWidth = s.width || 1.5;                    // 线宽
      ctx.setLineDash(s.dash || []);                     // 虚线样式(默认实线)
      var x1, y1, x2, y2, lv, i, y, px;                  // 复用的坐标/循环变量

      if (s.type === "hline") {                          // ── 水平线：整宽横线 + 右侧价格标签 ──
        y = yOfPrice(s.p1);                              // 价格 → y
        if (y != null) {                                 // 在可视价格范围内才画
          ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(ps.w, y); ctx.stroke(); // 从左到右画横线
          ctx.setLineDash([]);                           // 标签区改实线
          ctx.font = "10px Inter, sans-serif";           // 标签字体
          var lbl = window.OKXU.fmtPx(s.p1);             // 格式化价格文案
          var tw = ctx.measureText(lbl).width;           // 量文案宽度
          ctx.fillStyle = s.color || "#f0b90b";          // 标签底色
          ctx.fillRect(ps.w - tw - 10, y - 8, tw + 8, 16); // 画标签底块
          ctx.fillStyle = "#0b1018";                     // 标签文字色(深)
          ctx.fillText(lbl, ps.w - tw - 6, y + 4);       // 写价格
        }
      } else if (s.type === "vline") {                   // ── 垂直线：整高竖线 + 顶部时间标签 ──
        x1 = xOfTime(s.t1);                              // 时间 → x
        if (x1 != null) {                                // 在可视范围内才画
          ctx.beginPath(); ctx.moveTo(x1, 0); ctx.lineTo(x1, ps.h); ctx.stroke(); // 从上到下画竖线
          var dt = new Date(s.t1 * 1000);                // 时间转 Date
          function p2(n) { return (n < 10 ? "0" : "") + n; } // 补零
          var s2 = p2(dt.getUTCMonth() + 1) + "-" + p2(dt.getUTCDate()) + " " + p2(dt.getUTCHours()) + ":" + p2(dt.getUTCMinutes()); // "MM-DD HH:mm"
          ctx.setLineDash([]);                           // 标签区实线
          ctx.font = "10px Inter, sans-serif";           // 标签字体
          var w2 = ctx.measureText(s2).width;            // 文案宽度
          ctx.fillStyle = s.color || "#f0b90b";          // 标签底色
          ctx.fillRect(x1 - w2 / 2 - 3, 2, w2 + 6, 15);  // 顶部标签底块
          ctx.fillStyle = "#0b1018";                     // 文字色
          ctx.fillText(s2, x1 - w2 / 2, 13);             // 写时间
        }
      } else {                                           // ── 其余类型都需要两个端点 ──
        if (s.t1 == null || s.t2 == null) return ctx.restore(); // 端点不全直接退出
        x1 = xOfTime(s.t1); y1 = yOfPrice(s.p1);         // 端点1坐标
        x2 = xOfTime(s.t2); y2 = yOfPrice(s.p2);         // 端点2坐标
        if (x1 == null || x2 == null || y1 == null || y2 == null) return ctx.restore(); // 任一坐标不可见则跳过
        if (s.type === "ray") {                          // ── 射线：从端点1沿方向延伸到绘图区右缘 ──
          var dx = x2 - x1, dy = y2 - y1;                // 方向向量
          var k = dx !== 0 ? (ps.w - x1) / dx : 9999;    // 缩放系数：延伸到右缘(垂直时取大数)
          ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x1 + dx * k, y1 + dy * k); ctx.stroke(); // 画射线
        } else if (s.type === "rect") {                  // ── 矩形：半透明填充 + 实线边框 ──
          ctx.globalAlpha = ghost ? 0.5 : 0.18;          // 填充透明度
          ctx.fillRect(x1, y1, x2 - x1, y2 - y1);        // 填充矩形
          ctx.globalAlpha = ghost ? 0.75 : 1;            // 恢复描边透明度
          ctx.strokeRect(x1, y1, x2 - x1, y2 - y1);      // 描边
        } else if (s.type === "fib") {                   // ── 斐波那契回撤：7条水平线(0/0.236/0.382/0.5/0.618/0.786/1) ──
          lv = [0, 0.236, 0.382, 0.5, 0.618, 0.786, 1];  // 回撤位
          var lo = Math.min(s.p1, s.p2), hi = Math.max(s.p1, s.p2); // 区间低/高价
          var xa = Math.min(x1, x2), xb = Math.max(x1, x2); // 起点横向范围
          for (i = 0; i < lv.length; i++) {              // 逐条画
            px = hi - (hi - lo) * lv[i];                 // 该回撤位对应的价格
            y = yOfPrice(px);                            // 转 y 坐标
            if (y == null) continue;                     // 不可见跳过
            ctx.globalAlpha = ghost ? 0.6 : 0.9;         // 透明度
            ctx.setLineDash(lv[i] === 0 || lv[i] === 1 ? [] : [4, 3]); // 0/1位实线，中间位虚线
            ctx.strokeStyle = lv[i] === 0.618 ? "#f0b90b" : (s.color || "#8b5cf6"); // 0.618黄金位高亮金色
            ctx.beginPath(); ctx.moveTo(xa, y); ctx.lineTo(ps.w, y); ctx.stroke(); // 从起点延伸到右缘
            ctx.setLineDash([]);                         // 标签实线
            ctx.font = "9px Inter, sans-serif";          // 标签字体
            ctx.globalAlpha = 1;                         // 标签不透明
            ctx.fillStyle = lv[i] === 0.618 ? "#f0b90b" : "#8ea0bb"; // 标签颜色
            ctx.fillText(lv[i] + "  " + window.OKXU.fmtPx(px), xa + 3, y - 2); // 写"比例 价格"
          }
        } else { // trend                                // ── 趋势线：两端点连线 + 端点圆点 ──
          ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke(); // 画线段
          ctx.setLineDash([]);                           // 端点实心
          ctx.beginPath(); ctx.arc(x1, y1, 2.6, 0, 6.284); ctx.fill(); // 端点1圆点
          ctx.beginPath(); ctx.arc(x2, y2, 2.6, 0, 6.284); ctx.fill(); // 端点2圆点
        }
      }
      ctx.restore();                                     // 恢复画布状态
    }

    function drawAxisHints() {                           // 绘图模式下在左下角画操作提示文案
      if (draw.tool === "cursor") return;                // 光标模式不画
      var names = { trend: "趋势线", hline: "水平线", vline: "垂直线", ray: "射线", rect: "矩形", fib: "斐波那契" }; // 工具中文名
      var ps = plotSize();                               // 绘图区尺寸
      ctx.save();                                        // 保存状态
      ctx.font = "11px Inter, sans-serif";               // 提示字体
      ctx.fillStyle = "rgba(240,185,11,.92)";            // 金色提示
      ctx.fillText("✎ " + (names[draw.tool] || draw.tool) + " · 拖拽绘制 · 右键删线 · Esc 退出", 10, Math.max(14, ps.h - 8)); // 写提示
      ctx.restore();                                     // 恢复
    }

    function redraw() {                                  // 整体重绘 canvas 叠加层(DPR 适配)
      if (!ctx) return;                                  // 无上下文返回
      var w = el.clientWidth, h = el.clientHeight;       // 容器尺寸
      if (!w || !h) return;                              // 尺寸为0(隐藏)跳过
      var dpr = window.devicePixelRatio || 1;            // 设备像素比(高清屏)
      if (cv.width !== Math.round(w * dpr) || cv.height !== Math.round(h * dpr)) { // 物理尺寸不匹配时
        cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr); // 重设 canvas 物理尺寸
      }
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);            // 变换到 CSS 像素坐标系
      ctx.clearRect(0, 0, w, h);                         // 清屏
      if (!draw.shapes.length && !draw.draft && draw.tool === "cursor") return; // 没有图形且是光标模式：空层即可
      var ps = plotSize();                               // 绘图区尺寸
      ctx.save();                                        // 保存
      ctx.beginPath(); ctx.rect(0, 0, ps.w, ps.h); ctx.clip(); // 裁剪到主图绘图区(不画进副图/价格轴)
      for (var i = 0; i < draw.shapes.length; i++) {     // 逐个绘制已保存图形
        try { drawShape(draw.shapes[i], false); } catch (e) { } // 异常静默(单个图形坏了不影响其他)
      }
      if (draw.draft) { try { drawShape(draw.draft, true); } catch (e) { } } // 再画拖拽中的半成品
      ctx.restore();                                     // 恢复裁剪
      drawAxisHints();                                   // 画操作提示
    }

    function loop(ts) {                                  // rAF 主循环：约 30fps 节流重绘叠加层
      draw.raf = requestAnimationFrame(loop);            // 排下一帧
      if (ts - draw.lastT < 33) return;                  // 距上帧 <33ms 跳过(≈30fps)
      draw.lastT = ts;                                   // 记录本帧时间
      if (draw.dirty || draw.shapes.length || draw.draft || draw.tool !== "cursor") { // 需要重绘或层上有内容时
        draw.dirty = false;                              // 清除脏标志
        redraw();                                        // 重绘
      }
    }

    function mountCanvas() {                             // 创建绘图 canvas 并绑定鼠标/触摸事件
      cv = document.createElement("canvas");             // 新建 canvas
      cv.style.cssText = "position:absolute;left:0;top:0;width:100%;height:100%;z-index:8;pointer-events:none;"; // 绝对定位铺满、默认不拦截鼠标
      el.appendChild(cv);                                // 挂进容器
      ctx = cv.getContext("2d");                         // 取 2d 上下文

      cv.addEventListener("pointerdown", function (e) {  // 按下：开始绘制/删除
        if (draw.tool === "cursor") return;              // 光标模式不处理
        e.preventDefault();                              // 阻止默认(选区等)
        var r = cv.getBoundingClientRect(), x = e.clientX - r.left, y = e.clientY - r.top; // 换算 canvas 内坐标
        if (e.button === 2) { delAt(x, y); return; }     // 右键：删除命中的图形
        var t = timeOfX(x), p = priceOfY(y);             // 像素 → (时间,价格)
        if (t == null || p == null) return;              // 超出可视范围放弃
        if (draw.tool === "hline" || draw.tool === "vline") { // 水平/垂直线：单击即完成
          var s = { type: draw.tool, t1: t, p1: p, color: draw.color, width: draw.width }; // 生成图形
          draw.shapes.push(s); saveShapes(); draw.dirty = true; // 入列+持久化+重绘
          return;                                        // 结束
        }
        draw.draft = { type: draw.tool, t1: t, p1: p, t2: t, p2: p, color: draw.color, width: draw.width }; // 其余类型：先建半成品(两点暂重合)
        try { cv.setPointerCapture(e.pointerId); } catch (e2) { } // 捕获指针，拖出 canvas 也能继续
      });

      cv.addEventListener("pointermove", function (e) {  // 移动：更新半成品第二端点
        if (!draw.draft) return;                         // 无拖拽中的图形忽略
        var r = cv.getBoundingClientRect(), x = e.clientX - r.left, y = e.clientY - r.top; // 内部坐标
        var t = timeOfX(x), p = priceOfY(y);             // 转(时间,价格)
        if (t == null || p == null) return;              // 超范围忽略
        draw.draft.t2 = t; draw.draft.p2 = p;            // 更新第二端点
        draw.dirty = true;                               // 触发重绘
      });

      cv.addEventListener("pointerup", function (e) {    // 抬起：提交图形
        if (!draw.draft) return;                         // 无拖拽忽略
        var d = draw.draft; draw.draft = null;           // 取出半成品
        if (d.t2 != null && (Math.abs(d.t1 - d.t2) > 0 || d.p1 !== d.p2)) { // 两端点有区分才算有效(过滤误点)
          draw.shapes.push(d); saveShapes();             // 入列并持久化
        }
        draw.dirty = true;                               // 重绘
      });

      cv.addEventListener("contextmenu", function (e) { if (draw.tool !== "cursor") e.preventDefault(); }); // 绘图模式下禁用右键菜单(右键留给删线)
      cv.addEventListener("dblclick", function (e) {     // 双击
        if (draw.tool !== "cursor") { e.preventDefault(); clearDraw(); } // 绘图模式下双击清空全部图形
      });
    }

    function distSeg(px, py, x1, y1, x2, y2) {           // 点(px,py) 到线段(x1,y1)-(x2,y2) 的最短距离(命中检测用)
      var dx = x2 - x1, dy = y2 - y1;                    // 线段向量
      var L = dx * dx + dy * dy;                         // 长度平方
      var t = L ? ((px - x1) * dx + (py - y1) * dy) / L : 0; // 投影参数 t∈[0,1]
      t = Math.max(0, Math.min(1, t));                   // 钳制到线段内
      var ax = x1 + t * dx, ay = y1 + t * dy;            // 线段上最近点
      return Math.sqrt((px - ax) * (px - ax) + (py - ay) * (py - ay)); // 欧氏距离
    }
    function distShape(s, x, y) {                        // 点到某图形的近似最短距离(用于右键命中判定)
      var ps = plotSize();                               // 绘图区尺寸(射线延伸用)
      if (s.type === "hline") { var yy = yOfPrice(s.p1); return yy == null ? null : Math.abs(y - yy); } // 水平线：|dy|
      if (s.type === "vline") { var xx = xOfTime(s.t1); return xx == null ? null : Math.abs(x - xx); }  // 垂直线：|dx|
      var x1 = xOfTime(s.t1), y1 = yOfPrice(s.p1), x2 = xOfTime(s.t2), y2 = yOfPrice(s.p2); // 两端点坐标
      if (x1 == null || x2 == null || y1 == null || y2 == null) return null; // 任一不可见无法判定
      if (s.type === "ray") {                            // 射线：延伸后再算点到线距离
        var dx = x2 - x1, dy = y2 - y1;                  // 方向向量
        var k = dx !== 0 ? (ps.w - x1) / dx : 9999;      // 延伸系数
        return distSeg(x, y, x1, y1, x1 + dx * k, y1 + dy * k); // 距离
      }
      if (s.type === "rect") {                          // 矩形：取四条边的最小距离
        var d = Math.min(
          distSeg(x, y, x1, y1, x2, y1), distSeg(x, y, x2, y1, x2, y2), // 上边、右边
          distSeg(x, y, x2, y2, x1, y2), distSeg(x, y, x1, y2, x1, y1)); // 下边、左边
        return d;                                        // 最小距离
      }
      if (s.type === "fib") return distSeg(x, y, x1, y1, x2, y2); // 斐波那契：用对角线近似
      return distSeg(x, y, x1, y1, x2, y2);              // 趋势线：点到线段距离
    }
    function delAt(x, y) {                               // 右键删除：删掉点击处命中的图形(取最近的)
      var best = -1, bd = 10;                            // 最优索引与阈值(10px 内算命中)
      for (var i = draw.shapes.length - 1; i >= 0; i--) { // 从最上层往下找
        var d = distShape(draw.shapes[i], x, y);         // 该图形到点击点距离
        if (d != null && d < bd) { bd = d; best = i; }   // 更近则记录
      }
      if (best >= 0) { draw.shapes.splice(best, 1); saveShapes(); draw.dirty = true; } // 命中：删除+持久化+重绘
    }

    build();                                             // 首次建图
    draw.raf = requestAnimationFrame(loop);              // 启动叠加层重绘循环

    /* ═══════════════ 对外 API ═══════════════ */
    return {                                             // 返回图表操作接口集
      el: el,                                            // 容器元素引用

      /* 全量/合并刷新。opts.merge=true 时保留已加载的历史(轮询用) */
      setData: function (payload, opts) {                // 全量刷新入口：payload={rows,marks,pivots,lines}
        opts = opts || {};                               // 参数缺省
        if (payload.rows) {                              // 有K线数据
          if (opts.merge && lastRows.length) {           // merge 模式且已有数据：合并而不冲掉历史
            var map = Object.create(null), i;            // 旧数据时间→下标映射
            for (i = 0; i < lastRows.length; i++) map[lastRows[i][0]] = i; // 建索引
            for (i = 0; i < payload.rows.length; i++) {  // 遍历新行
              var r = payload.rows[i], at = map[r[0]];   // 新行与其在旧数据中的位置
              if (at == null) { if (!lastRows.length || r[0] > lastRows[lastRows.length - 1][0]) lastRows.push(r); } // 旧无此行：仅在比末根更新时追加(保持有序)
              else lastRows[at] = r;                     // 已存在：原地覆盖(末根刷新)
            }
          } else {                                       // 非 merge：直接替换
            lastRows = payload.rows;                     // 整体替换
          }
        }
        if (payload.marks) lastMarks = payload.marks;    // 同步更新缓存的交易标记
        if (payload.pivots) lastPivots = payload.pivots; // 更新转折点
        if (payload.lines !== undefined) lastLines = payload.lines; // 更新均价/止盈止损线
        applyData(true);                                 // 全量重灌
        syncLines();                                     // 重建价格线
        buildMarkers();                                  // 重建标记
        draw.dirty = true;                               // 重绘叠加层
      },

      setSignals: function (marks, pivots, lines, th) {  // 单独更新信号标注(不重灌K线，开销小)
        if (marks) lastMarks = marks;                    // 交易标记
        if (pivots) lastPivots = pivots;                 // 转折点
        if (lines !== undefined) lastLines = lines;      // 均价/止盈止损线
        if (th !== undefined) lastTh = th || 0;          // 金▲KE 阈值(与后端 gold_signal 同口径)
        syncLines();                                     // 重建价格线
        buildMarkers();                                  // 重建标记
      },

      /* 只更新末根(实时价跳动) */
      updateLast: function (price, tsMs) {              // 轻量末根跳动更新：只改收盘/高低，不重算指标
        if (!cs || !lastRows.length || !(price > 0)) return false; // 参数无效返回失败
        var r = lastRows[lastRows.length - 1];           // 末根行
        var t = r[0] + OFF;                              // 末根时间(+8h)
        if (tsMs) {                                      // 带时间戳时校验仍属于同一根
          var iv = lastRows.length > 1 ? (r[0] - lastRows[lastRows.length - 2][0]) : 300; // 推断K线周期(缺省300s)
          if (iv <= 0) iv = 300;                         // 防非法周期
          var barT = Math.floor((tsMs / 1000 + OFF) / iv) * iv; // 按周期取整算出所属bar
          if (barT > t) return false;                    // 已跨入新bar：交由 setTail 处理，这里放弃
        }
        try {                                            // update 防御
          if (isOHLC()) {                                // OHLC 形态
            cs.update({ time: t, open: r[1], high: Math.max(r[2], price), low: r[3] > 0 ? Math.min(r[3], price) : price, close: price }); // 更新高低收
          } else {                                       // 线/面积
            cs.update({ time: t, value: price });        // 只更新值
          }
        } catch (e) { return false; }                    // 失败返回 false
        // 指标末端同步(MA/BOLL 末点微调, 视觉不滞后)
        var gv = paneS.vol;                              // 成交量副图
        if (gv && gv.vol) {                              // 存在则同步末根成交量
          try { gv.vol.update({ time: t, value: r[11] || 0, color: r[4] >= r[1] ? "rgba(255,77,79,.32)" : "rgba(14,203,129,.32)" }); } catch (e) { } // 按阴阳着色
        }
        draw.dirty = true;                               // 重绘
        return true;                                     // 成功
      },

      /* 增量尾部: 覆盖末根/追加新根 */
      setTail: function (tail) {                        // 3秒轮询增量入口：tail 为最新若干根(首根多为未收盘的末根)
        if (!cs || !tail || !tail.length) return 0;      // 空数据返回 0
        var app = 0, i;                                  // app=实际新增根数
        for (i = 0; i < tail.length; i++) {              // 逐根处理
          var r = tail[i];                               // 当前行
          if (!r || !(r[0] > 0) || !(r[4] > 0)) continue; // 时间/价格非法跳过
          var t = r[0] + OFF, known = (t in byTime);     // 偏移后时间与是否已存在
          if (!known) {                                  // ── 新根 ──
            if (times.length && t <= times[times.length - 1]) { // 时间不晚于末根 → 属于历史空洞(极少)
              // 中间补洞(极少): 定位插入
              var idx = -1;                              // 插入点
              for (var q = times.length - 1; q >= 0; q--) if (times[q] < t) { idx = q; break; } // 从后往前找第一个更早的时间
              if (idx < 0) { times.unshift(t); lastRows.unshift(r); } // 比所有还早：插到最前
              else { times.splice(idx + 1, 0, t); lastRows.splice(idx + 1, 0, r); } // 否则插入其后
              applyData(true);                           // 插队会打乱索引，整体重灌一次
              continue;                                  // 下一根
            }
            times.push(t); app++;                        // 正常追加：登记时间、新增计数
            lastRows.push(r);                            // 追加数据行
          } else {                                       // ── 已知根(末根覆盖) ──
            var pos = -1;                                // 其在 lastRows 中的位置
            for (var z = lastRows.length - 1; z >= 0; z--) if (lastRows[z][0] === r[0]) { pos = z; break; } // 从后往前找同时间行
            if (pos >= 0) lastRows[pos] = r;             // 原地覆盖(收盘后终值)
          }
          byTime[t] = r;                                 // 更新索引
          try {                                          // 增量 update(比 setData 便宜得多)
            if (isOHLC()) cs.update({ time: t, open: r[1], high: r[2], low: r[3], close: r[4] }); // 覆盖/追加该根
            else cs.update({ time: t, value: r[4] });    // 线形态只更新收盘
          } catch (e) { }                                // 静默
        }
        var gv = paneS.vol;                              // 成交量副图
        if (gv && gv.vol) {                              // 存在则同步
          for (i = 0; i < tail.length; i++) {            // 逐根
            var rr = tail[i];                            // 当前行
            if (!rr || !(rr[0] > 0)) continue;           // 非法跳过
            try { gv.vol.update({ time: rr[0] + OFF, value: rr[11] || 0, color: rr[4] >= rr[1] ? "rgba(255,77,79,.32)" : "rgba(14,203,129,.32)" }); } catch (e) { } // 覆盖/追加成交量
          }
        }
        applyIndicators();                               // 重算全部指标(万根 <2ms)
        if (app) buildMarkers();                         // 有新根才重建标记(覆盖末根无需)
        draw.dirty = true;                               // 重绘
        return app;                                      // 返回新增根数
      },

      /* 前置更早的历史(拖到最左触发): 视口保持不动 */
      prepend: function (rows) {                        // 历史分页回填：把更老的K线插到头部并平移视口
        if (!rows || !rows.length || !cs) return 0;      // 空数据返回 0
        var cur = null;                                  // 当前视口逻辑范围
        try { cur = chart.timeScale().getVisibleLogicalRange(); } catch (e) { } // 记录插入前视口
        var seen = Object.create(null), i;               // 已有时间集合(去重)
        for (i = 0; i < lastRows.length; i++) seen[lastRows[i][0]] = 1; // 登记现有时间
        var add = [];                                    // 待前置的新行
        for (i = 0; i < rows.length; i++) {              // 遍历返回行
          var r2 = rows[i];                              // 当前行
          if (!r2 || !(r2[0] > 0) || !(r2[4] > 0) || seen[r2[0]]) continue; // 非法或重复跳过
          seen[r2[0]] = 1; add.push(r2);                 // 登记并入列
        }
        if (!add.length) return 0;                       // 全是重复：没新增
        add.sort(function (a, b) { return a[0] - b[0]; }); // 按时间升序
        lastRows = add.concat(lastRows);                 // 前置拼接
        if (lastRows.length > MAX_BARS) lastRows = lastRows.slice(lastRows.length - MAX_BARS); // 超上限时丢最老(内存保护)
        applyData(true);                                 // 全量重灌
        buildMarkers();                                  // 重建标记(范围变大)
        if (cur) {                                       // 视口整体平移 → 用户看到的画面纹丝不动
          suppress = true;                               // 抑制平移期间的翻页触发
          try { chart.timeScale().setVisibleLogicalRange({ from: cur.from + add.length, to: cur.to + add.length }); } catch (e) { } // 右移新增根数
          suppress = false;                              // 解除抑制
        }
        draw.dirty = true;                               // 重绘
        return add.length;                               // 返回实际前置根数
      },

      histDone: function (exhausted) { histBusy = false; if (exhausted) histEnd = true; }, // 外部通知历史加载完成；exhausted=true 表示后端已到底(返回<600根)不再翻页
      histReset: function () { histBusy = false; histEnd = false; }, // 切换合约/周期时重置历史分页状态
      histState: function () { return { busy: histBusy, end: histEnd }; }, // 查询历史分页状态(排障用)

      /* 绘图工具 */
      setTool: function (name) {                        // 切换绘图工具(cursor=退出绘图模式)
        draw.tool = name || "cursor";                   // 设置工具(缺省光标)
        draw.draft = null;                              // 丢弃未完成半成品
        if (cv) cv.style.pointerEvents = draw.tool === "cursor" ? "none" : "auto"; // 光标模式让事件穿透 canvas
        if (cv) cv.style.cursor = draw.tool === "cursor" ? "default" : "crosshair"; // 绘图模式显示十字光标
        draw.dirty = true;                              // 重绘(提示文案变化)
      },
      setDrawColor: function (c) { draw.color = c; draw.dirty = true; }, // 设置画笔颜色
      getShapes: function () { return draw.shapes; },   // 获取当前图形列表
      clearDraw: function () { draw.shapes = []; draw.draft = null; saveShapes(); draw.dirty = true; }, // 清空全部图形并持久化
      undoDraw: function () { if (draw.shapes.length) { draw.shapes.pop(); saveShapes(); draw.dirty = true; } }, // 撤销最后一个图形
      setKey: function (inst, tf) {                     // 绑定持久化键：按 合约|周期 区分绘图层
        key = (inst || "x") + "|" + (tf || "");         // 组合键
        loadShapes();                                   // 立即加载该键的图形
      },

      /* 图表配置 */
      setCfg: function (next) {                         // 运行时更新配置；结构性变化需重建，外观变化走 applyOptions
        var structural = ["type", "panes", "ma", "ema", "boll", "updown", "log"]; // 需要重建图表的字段(系列结构变了)
        var old = cfg;                                  // 旧配置
        var needBuild = false;                          // 是否需要重建
        for (var i = 0; i < structural.length; i++) {   // 逐项比较
          var k = structural[i];                        // 字段名
          if (JSON.stringify(old[k]) !== JSON.stringify(next[k])) { needBuild = true; break; } // 值变了则重建
        }
        cfg = Object.assign({}, old, next);             // 合并出新配置
        if (needBuild) {                                // ── 重建路径 ──
          try { savedRange = chart.timeScale().getVisibleLogicalRange(); } catch (e) { savedRange = null; } // 先保存视口，重建后恢复
          build();                                      // 重建图表
        } else {                                        // ── 轻量路径：只改外观 ──
          try {
            chart.applyOptions({                        // 热更新图表选项
              grid: {                                   // 网格开关
                vertLines: { color: cfg.grid ? "rgba(27,36,52,.85)" : "transparent" },
                horzLines: { color: cfg.grid ? "rgba(27,36,52,.85)" : "transparent" },
              },
              crosshair: {                              // 磁吸开关
                mode: (cfg.magnet && LWC.CrosshairMode) ? LWC.CrosshairMode.Magnet : (LWC.CrosshairMode ? LWC.CrosshairMode.Normal : 0),
              },
            });
            chart.timeScale().applyOptions({ rightOffset: cfg.rightOffset, barSpacing: cfg.barSpacing }); // 时间轴选项
          } catch (e) { }                               // 静默
          applyIndicators();                            // 指标参数(ma/ema 周期等)可能变了：重算
          syncLines();                                  // 均价/止盈线开关可能变了
          buildMarkers();                               // 标记开关可能变了
        }
        draw.dirty = true;                              // 重绘
      },
      getCfg: function () { return cfg; },              // 读取当前配置

      getRows: function () { return lastRows; },        // 读取当前K线数据(行数组)
      getLayers: function () { return { marks: cfg.marks, pivots: cfg.pivots, vol: cfg.panes.indexOf("vol") >= 0, macd: cfg.panes.indexOf("macd") >= 0 }; }, // 读取图层开关快照(兼容旧接口)

      fit: function () { savedRange = null; try { chart.timeScale().fitContent(); } catch (e) { } }, // 适配全部数据到视口
      toLatest: function () {                           // 滚动回最新K线
        savedRange = null;                              // 清除保存的视口
        try { chart.timeScale().scrollToRealTime(); } catch (e) { } // 平滑滚到最右
        atLatest = true; if (rangeCb) rangeCb(true);    // 立即同步"在最新端"状态
      },
      isAtLatest: function () { return atLatest; },     // 查询是否停在最新端
      applyPrecision: function () {                     // 切换合约后按新价格量级重新应用小数位
        if (!cs || !lastRows.length) return;            // 无数据返回
        var p = window.OKXU.precOf(lastRows[lastRows.length - 1][4]); // 按最新价推断精度
        var pf = { type: "price", precision: p, minMove: Math.pow(10, -p) }; // 新价格格式
        try { cs.applyOptions({ priceFormat: pf }); } catch (e) { }  // 主图
        var q;                                          // 循环变量
        for (q in maS) { if (maS.hasOwnProperty(q)) try { maS[q].applyOptions({ priceFormat: pf }); } catch (e) { } } // 各 MA 线
        for (q in emaS) { if (emaS.hasOwnProperty(q)) try { emaS[q].applyOptions({ priceFormat: pf }); } catch (e) { } } // 各 EMA 线
        if (bollS) { try { bollS.up.applyOptions({ priceFormat: pf }); bollS.mid.applyOptions({ priceFormat: pf }); bollS.dn.applyOptions({ priceFormat: pf }); } catch (e) { } } // BOLL 三线
      },
      screenshot: function () { try { return chart.takeScreenshot(); } catch (e) { return null; } }, // 截图：返回 canvas(失败 null)
      /* 自检探针: 运行期内部状态(供探针页/排障使用) */
      stats: function () {                              // 导出内部状态快照
        var np = 0, s = 0, k;                           // pane 数 / 系列计数 / 循环变量
        try { np = chart.panes().length; } catch (e) { } // 当前 pane 总数
        for (k in maS) if (maS.hasOwnProperty(k)) s++;  // 统计 MA 线数
        for (k in emaS) if (emaS.hasOwnProperty(k)) s++; // 统计 EMA 线数
        s += Object.keys(paneS).length;                 // 累加副图数
        var vis = null;                                 // 可视范围
        try { vis = chart.timeScale().getVisibleLogicalRange(); } catch (e) { } // 读取视口
        return {                                        // 状态包
          bars: lastRows.length, times: times.length, panes: np, // 根数/时间数/pane数
          ma: Object.keys(maS), ema: Object.keys(emaS), boll: !!bollS, // 指标清单
          subPanes: Object.keys(paneS), series: s, shapes: draw.shapes.length, // 副图/系列/图形统计
          tool: draw.tool, atLatest: atLatest, histEnd: histEnd,       // 工具/视口/历史状态
          vis: vis ? { from: Math.round(vis.from), to: Math.round(vis.to) } : null, // 可视逻辑区间
          marks: lastMarks.length, pivots: lastPivots.length,          // 信号数量
        };
      },
      destroy: function () {                            // 销毁：停 rAF、移除图表
        if (draw.raf) cancelAnimationFrame(draw.raf);   // 停掉重绘循环
        if (chart) { try { chart.remove(); } catch (e) { } chart = null; } // 销毁图表实例
      },
    };
  }

  return { mount: mount };                              // 模块对外只暴露 mount
})();
