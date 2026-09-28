/* ============================================================
 *  chart.js — OKX 图表内核 (TradingView Lightweight Charts v5)
 *  · 图表类型: 蜡烛 / 美国线 / 线 / 面积
 *  · 主图叠加: MA / EMA / BOLL
 *  · 副图 pane: 成交量 / MACD / RSI / KDJ (可任意组合)
 *  · 交易信号(买/加/平) · 算法转折点 · 均价线 · 止盈线
 *  · 历史按需加载: 拖到最左自动向更早翻页(prepend, 视口不动)
 *  · 原生绘图: 趋势线/水平线/垂直线/射线/矩形/斐波那契(可删/清空/记忆)
 *  · 时区: 统一 +8h 后按 UTC 呈现 → 图面即北京时间
 * ============================================================ */
window.OKXChart = (function () {
  var LWC = window.LightweightCharts;
  var OFF = window.OKXU.TZ; // 28800 秒
  var MAX_BARS = 20000;     // 内存保护上限

  /* ══════════════════ 指标数学(纯函数) ══════════════════ */
  function smaA(v, n) {
    var out = new Array(v.length), s = 0;
    for (var i = 0; i < v.length; i++) {
      s += v[i];
      if (i >= n) s -= v[i - n];
      out[i] = i >= n - 1 ? s / n : null;
    }
    return out;
  }
  function emaA(v, n) {
    var out = new Array(v.length), k = 2 / (n + 1), prev = null;
    for (var i = 0; i < v.length; i++) {
      prev = prev === null ? v[i] : v[i] * k + prev * (1 - k);
      out[i] = i >= n - 1 ? prev : null;
    }
    return out;
  }
  function stdA(v, n) {
    var out = new Array(v.length);
    for (var i = 0; i < v.length; i++) {
      if (i < n - 1) { out[i] = null; continue; }
      var m = 0, j;
      for (j = i - n + 1; j <= i; j++) m += v[j];
      m /= n;
      var s = 0;
      for (j = i - n + 1; j <= i; j++) { var d = v[j] - m; s += d * d; }
      out[i] = Math.sqrt(s / n);
    }
    return out;
  }
  function rsiA(c, n) {
    var out = new Array(c.length), i;
    for (i = 0; i < c.length; i++) out[i] = null;
    if (c.length <= n) return out;
    var g = 0, l = 0;
    for (i = 1; i <= n; i++) { var d = c[i] - c[i - 1]; if (d >= 0) g += d; else l -= d; }
    g /= n; l /= n;
    out[n] = l === 0 ? 100 : 100 - 100 / (1 + g / l);
    for (i = n + 1; i < c.length; i++) {
      var dd = c[i] - c[i - 1];
      var up = dd > 0 ? dd : 0, dn = dd < 0 ? -dd : 0;
      g = (g * (n - 1) + up) / n;
      l = (l * (n - 1) + dn) / n;
      out[i] = l === 0 ? 100 : 100 - 100 / (1 + g / l);
    }
    return out;
  }
  function kdjA(h, l, c, n, m1, m2) {
    var kn = new Array(c.length), dn = new Array(c.length), jn = new Array(c.length);
    var K = 50, D = 50;
    for (var i = 0; i < c.length; i++) {
      if (i < n - 1) { kn[i] = dn[i] = jn[i] = null; continue; }
      var hh = -Infinity, ll = Infinity;
      for (var j = i - n + 1; j <= i; j++) { if (h[j] > hh) hh = h[j]; if (l[j] < ll) ll = l[j]; }
      var rsv = (hh - ll) === 0 ? 50 : (c[i] - ll) / (hh - ll) * 100;
      K = (K * (m1 - 1) + rsv) / m1;
      D = (D * (m2 - 1) + K) / m2;
      kn[i] = K; dn[i] = D; jn[i] = 3 * K - 2 * D;
    }
    return { k: kn, d: dn, j: jn };
  }

  var MA_COLORS = ["#f0b90b", "#3b82f6", "#8b5cf6", "#14b8a6", "#ec4899", "#f97316"];

  function hasMarkersApi() { return typeof LWC.createSeriesMarkers === "function"; }

  function mount(el, o) {
    o = o || {};
    el.style.position = el.style.position || "relative";

    var cfg = Object.assign({
      type: "candles",     // candles | bars | line | area
      updown: "cn",        // cn=红涨绿跌  us=绿涨红跌
      grid: true,
      magnet: false,
      log: false,
      marks: true, pivots: true,
      avgline: true, tpline: true,
      ma: [5, 10, 20, 60],
      ema: [],
      boll: { on: false, n: 20, k: 2 },
      panes: ["vol", "macd"],
      rsiN: 14,
      kdjN: [9, 3, 3],
      barSpacing: 7,
      rightOffset: 8,
    }, o.cfg || {});

    // 兼容旧 layers 入参
    if (o.layers) {
      if (o.layers.marks != null) cfg.marks = o.layers.marks;
      if (o.layers.pivots != null) cfg.pivots = o.layers.pivots;
      var pn = [];
      if (o.layers.vol) pn.push("vol");
      if (o.layers.macd) pn.push("macd");
      if (pn.length) cfg.panes = pn;
    }

    var chart = null, cs = null, cv = null, ctx = null;
    var maS = {}, emaS = {}, bollS = null, paneS = {};
    var priceLines = [], markerPrim = null;
    var lastRows = [], byTime = Object.create(null), times = [];
    var lastMarks = [], lastPivots = [], lastLines = null, lastTh = 0;
    var savedRange = null, suppress = false, atLatest = true;
    var hoverCb = o.onHover || null, rangeCb = o.onAtLatest || null;
    var histCb = o.onNeedHistory || null;
    var histBusy = false, histEnd = false, histAt = 0;
    var key = "default";
    var draw = {
      tool: "cursor", color: "#f0b90b", width: 1.5,
      shapes: [], draft: null, dirty: true, raf: 0, lastT: 0,
    };

    /* ─────── 配色 ─────── */
    function upCol() { return cfg.updown === "us" ? "#0ecb81" : "#ff4d4f"; }
    function dnCol() { return cfg.updown === "us" ? "#ff4d4f" : "#0ecb81"; }
    function isOHLC() { return cfg.type === "candles" || cfg.type === "bars"; }

    /* ─────── 图表选项 ─────── */
    function chartOpts() {
      return {
        autoSize: true,
        layout: {
          background: { type: "solid", color: "transparent" },
          textColor: "#7d8ca6",
          fontSize: 10,
          fontFamily: "Inter, system-ui, 'PingFang SC', 'Microsoft YaHei', sans-serif",
          attributionLogo: false,
        },
        grid: {
          vertLines: { color: cfg.grid ? "rgba(27,36,52,.85)" : "transparent" },
          horzLines: { color: cfg.grid ? "rgba(27,36,52,.85)" : "transparent" },
        },
        crosshair: {
          mode: (cfg.magnet && LWC.CrosshairMode) ? LWC.CrosshairMode.Magnet : (LWC.CrosshairMode ? LWC.CrosshairMode.Normal : 0),
          vertLine: { color: "rgba(240,185,11,.45)", width: 1, style: 2, labelBackgroundColor: "#253247" },
          horzLine: { color: "rgba(240,185,11,.45)", width: 1, style: 2, labelBackgroundColor: "#253247" },
        },
        rightPriceScale: {
          borderColor: "#1b2434",
          scaleMargins: { top: 0.08, bottom: 0.08 },
          entireTextOnly: true,
          mode: cfg.log ? 1 : 0,
        },
        timeScale: {
          borderColor: "#1b2434",
          timeVisible: true,
          secondsVisible: false,
          rightOffset: cfg.rightOffset,
          barSpacing: cfg.barSpacing,
          minBarSpacing: 0.15,
          fixLeftEdge: false,
          lockVisibleTimeRangeOnResize: true,
          tickMarkFormatter: function (time, type) {
            var d = new Date(time * 1000);
            function p2(n) { return (n < 10 ? "0" : "") + n; }
            var Y = d.getUTCFullYear(), M = p2(d.getUTCMonth() + 1), D = p2(d.getUTCDate());
            var h = p2(d.getUTCHours()), m = p2(d.getUTCMinutes());
            if (type === 0) return String(Y);
            if (type === 1) return Y + "-" + M;
            if (type === 2) return M + "-" + D;
            if (type === 3) return h + ":" + m;
            return M + "-" + D + " " + h + ":" + m;
          },
        },
        localization: {
          locale: "zh-CN",
          timeFormatter: function (time) {
            var d = new Date(time * 1000);
            function p2(n) { return (n < 10 ? "0" : "") + n; }
            return d.getUTCFullYear() + "-" + p2(d.getUTCMonth() + 1) + "-" + p2(d.getUTCDate()) +
              " " + p2(d.getUTCHours()) + ":" + p2(d.getUTCMinutes());
          },
        },
        handleScroll: { mouseWheel: true, pressedMouseMove: true, horzTouchDrag: true, vertTouchDrag: false },
        handleScale: { axisPressedMouseMove: true, mouseWheel: true, pinch: true },
      };
    }

    /* ═══════════════ 价格线(均价/止盈/止损) ═══════════════ */
    function clearLines() {
      if (!cs) return;
      for (var i = 0; i < priceLines.length; i++) { try { cs.removePriceLine(priceLines[i]); } catch (e) { } }
      priceLines = [];
    }
    function addLine(spec) {
      if (!cs || !spec || !(spec.price > 0)) return;
      try {
        priceLines.push(cs.createPriceLine({
          price: spec.price, color: spec.color, lineWidth: 1,
          lineStyle: LWC.LineStyle ? LWC.LineStyle.Dashed : 2,
          axisLabelVisible: true, title: spec.title || "",
        }));
      } catch (e) { }
    }
    function syncLines() {
      clearLines();
      if (cfg.avgline) addLine(lastLines && lastLines.avg ? { price: lastLines.avg, color: "#f0b90b", title: "均价" } : null);
      if (cfg.tpline) addLine(lastLines && lastLines.tp ? { price: lastLines.tp, color: "#0ecb81", title: "止盈 +2%" } : null);
      if (cfg.tpline) addLine(lastLines && lastLines.sl ? { price: lastLines.sl, color: "#ff4d4f", title: "止损" } : null);
    }

    /* ═══════════════ 信号标记 ═══════════════ */
    function buildMarkers() {
      if (!cs) return;
      var arr = [];
      if (cfg.marks && times.length) {
        var t0 = times[0], vis = [];
        for (var z = 0; z < lastMarks.length; z++) if (Math.floor(lastMarks[z].t / 1000) + OFF >= t0) vis.push(lastMarks[z]);
        if (vis.length > 500) vis = vis.slice(vis.length - 500);
        for (var i = 0; i < vis.length; i++) {
          var m = vis[i];
          if (m.kind !== "buy" && m.kind !== "add" && m.kind !== "close") continue;
          var tm = window.OKXU.alignToBar(times, Math.floor(m.t / 1000) + OFF);
          if (tm < 0) continue;
          if (m.kind === "buy") arr.push({ time: tm, position: "belowBar", color: upCol(), shape: "arrowUp", text: "🚀买 1U", size: 1.4 });
          else if (m.kind === "add") arr.push({ time: tm, position: "belowBar", color: "#f0b90b", shape: "arrowUp", text: "▲加仓 +⅓U", size: 1.1 });
          else {
            var pf = m.profit == null ? 0 : m.profit;
            arr.push({
              time: tm, position: "aboveBar", color: pf >= 0 ? upCol() : dnCol(), shape: "arrowDown",
              text: "🍃平 " + (pf >= 0 ? "+" : "") + pf.toFixed(2) + "U", size: 1.1,
            });
          }
        }
      }
      if (cfg.pivots && times.length) {
        var seenP = {};
        for (var k = 0; k < lastPivots.length; k++) {
          var p = lastPivots[k];
          var t2 = window.OKXU.alignToBar(times, Math.floor(p.t / 1000) + OFF);
          if (t2 < 0) continue;
          var pk = t2 + "|" + (p.type === 0 ? "b" : "t");
          if (seenP[pk]) continue;                       // 同根K线去重(ZigZag 宽幅K线会重复输出)
          seenP[pk] = 1;
          var bottom = p.type === 0;
          // 真·金▲ = 底部且下跌腿动能 KE >= 该合约底部KE的75分位阈值(与引擎 gold_signal 完全同口径)
          var sig = p.ke > 0 && lastTh > 0 && p.ke >= lastTh;
          var col, sz, txt = "";
          if (bottom) {
            if (sig) { col = "#f0b90b"; sz = 1.2; txt = "金▲ KE" + Math.round(p.ke); }
            else { col = "#8b5cf6"; sz = 0.6; txt = p.ke > 0 ? "KE" + Math.round(p.ke) : ""; }
          } else {
            col = "#3b82f6";
            if (sig) { sz = 1.1; txt = "顶 KE" + Math.round(p.ke); } else { sz = 0.6; }
          }
          arr.push({ time: t2, position: bottom ? "belowBar" : "aboveBar", color: col, shape: "circle", text: txt, size: sz });
        }
      }
      arr.sort(function (a, b) { return a.time - b.time; });
      if (markerPrim && typeof markerPrim.setMarkers === "function") markerPrim.setMarkers(arr);
      else if (hasMarkersApi()) markerPrim = LWC.createSeriesMarkers(cs, arr);
      else if (typeof cs.setMarkers === "function") cs.setMarkers(arr);
    }

    /* ═══════════════ 建图 ═══════════════ */
    function build() {
      if (chart) { try { chart.remove(); } catch (e) { } chart = null; }
      cs = null; maS = {}; emaS = {}; bollS = null; paneS = {}; markerPrim = null; priceLines = [];
      if (cv && cv.parentNode) cv.parentNode.removeChild(cv);
      cv = ctx = null;

      chart = LWC.createChart(el, chartOpts());

      var prec = window.OKXU.precOf(lastRows.length ? lastRows[lastRows.length - 1][4] : 1);
      var pf = { type: "price", precision: prec, minMove: Math.pow(10, -prec) };
      var def;
      if (cfg.type === "bars") def = LWC.BarSeries;
      else if (cfg.type === "line") def = LWC.LineSeries;
      else if (cfg.type === "area") def = LWC.AreaSeries;
      else def = LWC.CandlestickSeries;

      var csOpt = { priceLineVisible: false, lastValueVisible: true, priceFormat: pf };
      if (cfg.type === "candles") {
        csOpt.upColor = upCol(); csOpt.downColor = dnCol();
        csOpt.borderUpColor = upCol(); csOpt.borderDownColor = dnCol();
        csOpt.wickUpColor = upCol(); csOpt.wickDownColor = dnCol();
      } else if (cfg.type === "bars") {
        csOpt.upColor = upCol(); csOpt.downColor = dnCol();
      } else if (cfg.type === "line") {
        csOpt.color = "#e8eefb"; csOpt.lineWidth = 1.4; csOpt.crosshairMarkerVisible = true;
      } else {
        csOpt.lineColor = "#f0b90b"; csOpt.topColor = "rgba(240,185,11,.28)";
        csOpt.bottomColor = "rgba(240,185,11,0)"; csOpt.lineWidth = 1.4;
      }
      cs = chart.addSeries(def, csOpt, 0);

      // 主图叠加: MA
      for (var i = 0; i < cfg.ma.length; i++) {
        var p = +cfg.ma[i];
        if (!(p > 1)) continue;
        maS[p] = chart.addSeries(LWC.LineSeries, {
          color: MA_COLORS[i % MA_COLORS.length], lineWidth: 1, priceLineVisible: false,
          lastValueVisible: false, crosshairMarkerVisible: false,
        }, 0);
      }
      // 主图叠加: EMA
      for (var j = 0; j < cfg.ema.length; j++) {
        var q = +cfg.ema[j];
        if (!(q > 1)) continue;
        emaS[q] = chart.addSeries(LWC.LineSeries, {
          color: MA_COLORS[(j + 2) % MA_COLORS.length], lineWidth: 1.1,
          lineStyle: LWC.LineStyle ? LWC.LineStyle.Dashed : 2,
          priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false,
        }, 0);
      }
      // 主图叠加: BOLL
      if (cfg.boll && cfg.boll.on) {
        bollS = {
          up: chart.addSeries(LWC.LineSeries, { color: "rgba(59,130,246,.9)", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, 0),
          mid: chart.addSeries(LWC.LineSeries, { color: "rgba(240,185,11,.85)", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, 0),
          dn: chart.addSeries(LWC.LineSeries, { color: "rgba(59,130,246,.9)", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, 0),
        };
      }

      // 副图 pane
      for (var n = 0; n < cfg.panes.length; n++) {
        var pi = n + 1, name = cfg.panes[n], g = { i: pi };
        if (name === "vol") {
          g.vol = chart.addSeries(LWC.HistogramSeries, { priceFormat: { type: "volume" }, priceLineVisible: false, lastValueVisible: false }, pi);
        } else if (name === "macd") {
          g.hist = chart.addSeries(LWC.HistogramSeries, { priceLineVisible: false, lastValueVisible: false }, pi);
          g.dif = chart.addSeries(LWC.LineSeries, { color: "#f0b90b", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, pi);
          g.dea = chart.addSeries(LWC.LineSeries, { color: "#3b82f6", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, pi);
        } else if (name === "rsi") {
          g.rsi = chart.addSeries(LWC.LineSeries, { color: "#8b5cf6", lineWidth: 1.2, priceLineVisible: false, lastValueVisible: true, crosshairMarkerVisible: false }, pi);
          try {
            g.rsi.createPriceLine({ price: 70, color: "rgba(255,77,79,.5)", lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: "70" });
            g.rsi.createPriceLine({ price: 30, color: "rgba(14,203,129,.5)", lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: "30" });
          } catch (e) { }
        } else if (name === "kdj") {
          g.k = chart.addSeries(LWC.LineSeries, { color: "#f0b90b", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, pi);
          g.d = chart.addSeries(LWC.LineSeries, { color: "#3b82f6", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, pi);
          g.j = chart.addSeries(LWC.LineSeries, { color: "#ec4899", lineWidth: 1, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false }, pi);
        }
        paneS[name] = g;
      }

      // pane 高度(主图占大头)
      try {
        var ps = chart.panes();
        if (ps && ps.length > 1 && ps[0].setStretchFactor) {
          ps[0].setStretchFactor(6);
          for (var x = 1; x < ps.length; x++) {
            if (!ps[x].setStretchFactor) continue;
            var nm = cfg.panes[x - 1];
            ps[x].setStretchFactor(nm === "macd" ? 1.5 : (nm === "vol" ? 1.1 : 1.3));
          }
        }
        if (ps && ps.length > 1) {
          for (var y = 1; y < ps.length; y++) {
            try { ps[y].priceScale("right").applyOptions({ scaleMargins: { top: 0.12, bottom: 0.05 }, borderColor: "#1b2434" }); } catch (e2) { }
          }
        }
      } catch (e) { }

      applyData(true);
      syncLines();
      buildMarkers();

      chart.subscribeCrosshairMove(function (param) {
        if (!hoverCb) return;
        if (!param || !param.time || !param.point) { hoverCb(null); return; }
        var row = byTime[param.time];
        if (!row) { hoverCb(null); return; }
        hoverCb({
          time: param.time, x: param.point.x, y: param.point.y,
          tstr: window.OKXU.tsFull(row[0] * 1000),
          o: row[1], h: row[2], l: row[3], c: row[4],
          dif: row[5], dea: row[6], hist: row[7], vol: row[11],
          pane: param.paneIndex,
        });
        draw.dirty = true;
      });

      chart.timeScale().subscribeVisibleLogicalRangeChange(function (r) {
        if (!r) return;
        draw.dirty = true;
        if (suppress) return;
        var tot = lastRows.length;
        var nowAt = r.to >= tot - 2.5;
        if (nowAt !== atLatest) { atLatest = nowAt; if (rangeCb) rangeCb(atLatest); }
        // 拖到最左 → 自动向更早翻页(8s 超时兜底, 防止回调丢失后卡死)
        if (r.from < 30 && (!histBusy || (Date.now() - histAt > 8000)) && !histEnd && times.length && histCb && tot < MAX_BARS) {
          histBusy = true; histAt = Date.now();
          histCb(times[0] - OFF);
        }
      });

      if (savedRange) {
        suppress = true;
        try { chart.timeScale().setVisibleLogicalRange(savedRange); } catch (e) { } 
        suppress = false;
      }

      mountCanvas();
      if (cv) cv.style.pointerEvents = draw.tool === "cursor" ? "none" : "auto";
      draw.dirty = true;
    }

    /* ═══════════════ 数据 ═══════════════ */
    function mkPt(r) {
      var t = r[0] + OFF;
      return isOHLC()
        ? { time: t, open: r[1], high: r[2], low: r[3], close: r[4] }
        : { time: t, value: r[4] };
    }

    function applyData(full) {
      if (!cs || !lastRows.length) return;
      var c = [], v = [];
      byTime = Object.create(null); times = [];
      var uv = cfg.updown === "us" ? "rgba(14,203,129,.32)" : "rgba(255,77,79,.32)";
      var dv = cfg.updown === "us" ? "rgba(255,77,79,.32)" : "rgba(14,203,129,.32)";
      for (var i = 0; i < lastRows.length; i++) {
        var r = lastRows[i], t = r[0] + OFF;
        c.push(mkPt(r));
        byTime[t] = r; times.push(t);
        v.push({ time: t, value: r[11] || 0, color: r[4] >= r[1] ? uv : dv });
      }
      try { cs.setData(c); } catch (e) { console.warn("candle setData", e); }
      var vg = paneS.vol;
      if (vg && vg.vol) { try { vg.vol.setData(v); } catch (e) { } }
      applyIndicators();
      void full;
    }

    /* 指标(MA/EMA/BOLL/副图): 全量重算 —— 万根级别耗时 <2ms, 3 秒一次无压力 */
    function applyIndicators() {
      if (!lastRows.length) return;
      var n = lastRows.length, i, t;
      var closes = new Array(n), highs = new Array(n), lows = new Array(n);
      for (i = 0; i < n; i++) { closes[i] = lastRows[i][4]; highs[i] = lastRows[i][2]; lows[i] = lastRows[i][3]; }

      function toLine(arr) {
        var out = [];
        // 从可视区左侧往前多取, 保证线进视野时不是断头
        for (var k = 0; k < n; k++) if (arr[k] != null && isFinite(arr[k])) out.push({ time: times[k], value: arr[k] });
        return out;
      }

      for (var key1 in maS) {
        if (!maS.hasOwnProperty(key1)) continue;
        try { maS[key1].setData(toLine(smaA(closes, +key1))); } catch (e) { }
      }
      for (var key2 in emaS) {
        if (!emaS.hasOwnProperty(key2)) continue;
        try { emaS[key2].setData(toLine(emaA(closes, +key2))); } catch (e) { }
      }
      if (bollS) {
        var bn = +cfg.boll.n || 20, bk = +cfg.boll.k || 2;
        var mid = smaA(closes, bn), sd = stdA(closes, bn);
        var up = [], dn = [];
        for (i = 0; i < n; i++) {
          up[i] = mid[i] == null ? null : mid[i] + bk * sd[i];
          dn[i] = mid[i] == null ? null : mid[i] - bk * sd[i];
        }
        try { bollS.mid.setData(toLine(mid)); bollS.up.setData(toLine(up)); bollS.dn.setData(toLine(dn)); } catch (e) { }
      }

      var g;
      if ((g = paneS.macd)) {
        var dif = [], dea = [], his = [];
        for (i = 0; i < n; i++) {
          var r = lastRows[i]; t = times[i];
          if (r[5] != null && isFinite(r[5])) dif.push({ time: t, value: r[5] });
          if (r[6] != null && isFinite(r[6])) dea.push({ time: t, value: r[6] });
          if (r[7] != null && isFinite(r[7])) his.push({ time: t, value: r[7], color: r[7] >= 0 ? "rgba(255,77,79,.6)" : "rgba(14,203,129,.6)" });
        }
        try { g.dif.setData(dif); g.dea.setData(dea); g.hist.setData(his); } catch (e) { }
      }
      if ((g = paneS.rsi)) {
        try { g.rsi.setData(toLine(rsiA(closes, +cfg.rsiN || 14))); } catch (e) { }
      }
      if ((g = paneS.kdj)) {
        var kj = kdjA(highs, lows, closes, (cfg.kdjN[0] || 9), (cfg.kdjN[1] || 3), (cfg.kdjN[2] || 3));
        try { g.k.setData(toLine(kj.k)); g.d.setData(toLine(kj.d)); g.j.setData(toLine(kj.j)); } catch (e) { }
      }
    }

    /* ═══════════════ 坐标换算(绘图用) ═══════════════ */
    function logicalOfTime(t) {
      var n = times.length;
      if (!n) return 0;
      if (t <= times[0]) { var a = n > 1 ? times[1] - times[0] : 60; return (t - times[0]) / a; }
      if (t >= times[n - 1]) { var b = n > 1 ? times[n - 1] - times[n - 2] : 60; return (n - 1) + (t - times[n - 1]) / b; }
      var lo = 0, hi = n - 1;
      while (hi - lo > 1) { var m = (lo + hi) >> 1; if (times[m] <= t) lo = m; else hi = m; }
      var iv = (times[hi] - times[lo]) || 1;
      return lo + (t - times[lo]) / iv;
    }
    function xOfTime(t) {
      var x = null;
      try { x = chart.timeScale().timeToCoordinate(t); } catch (e) { }
      if (x != null) return x;
      try { x = chart.timeScale().logicalToCoordinate(logicalOfTime(t)); } catch (e) { }
      return x;
    }
    function timeOfX(x) {
      var t = null;
      try { t = chart.timeScale().coordinateToTime(x); } catch (e) { }
      if (t != null) return t;
      var lg = null;
      try { lg = chart.timeScale().coordinateToLogical(x); } catch (e) { }
      if (lg == null) return null;
      var n = times.length;
      if (!n) return null;
      if (lg <= 0) { var a = n > 1 ? times[1] - times[0] : 60; return Math.round(times[0] + lg * a); }
      if (lg >= n - 1) { var b = n > 1 ? times[n - 1] - times[n - 2] : 60; return Math.round(times[n - 1] + (lg - (n - 1)) * b); }
      var i0 = Math.floor(lg), f = lg - i0, iv = (times[i0 + 1] - times[i0]) || 60;
      return Math.round(times[i0] + f * iv);
    }
    function yOfPrice(p) { try { return cs.priceToCoordinate(p); } catch (e) { return null; } }
    function priceOfY(y) { try { return cs.coordinateToPrice(y); } catch (e) { return null; } }

    /* ═══════════════ 绘图工具 ═══════════════ */
    function loadShapes() {
      draw.shapes = [];
      try {
        var raw = localStorage.getItem("okx.draw." + key);
        if (raw) { var a = JSON.parse(raw); if (a && a.length) draw.shapes = a; }
      } catch (e) { }
      draw.dirty = true;
    }
    function saveShapes() {
      try { localStorage.setItem("okx.draw." + key, JSON.stringify(draw.shapes)); } catch (e) { }
    }
    function plotSize() {
      var w = 0, h = 0;
      try { w = chart.timeScale().width(); } catch (e) { }
      try { h = chart.panes()[0].getHeight(); } catch (e) { }
      if (!w) w = el.clientWidth;
      if (!h) h = el.clientHeight * 0.7;
      return { w: w, h: h };
    }

    function drawShape(s, ghost) {
      var ps = plotSize();
      ctx.save();
      ctx.globalAlpha = ghost ? 0.75 : 1;
      ctx.strokeStyle = s.color || "#f0b90b";
      ctx.fillStyle = s.color || "#f0b90b";
      ctx.lineWidth = s.width || 1.5;
      ctx.setLineDash(s.dash || []);
      var x1, y1, x2, y2, lv, i, y, px;

      if (s.type === "hline") {
        y = yOfPrice(s.p1);
        if (y != null) {
          ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(ps.w, y); ctx.stroke();
          ctx.setLineDash([]);
          ctx.font = "10px Inter, sans-serif";
          var lbl = window.OKXU.fmtPx(s.p1);
          var tw = ctx.measureText(lbl).width;
          ctx.fillStyle = s.color || "#f0b90b";
          ctx.fillRect(ps.w - tw - 10, y - 8, tw + 8, 16);
          ctx.fillStyle = "#0b1018";
          ctx.fillText(lbl, ps.w - tw - 6, y + 4);
        }
      } else if (s.type === "vline") {
        x1 = xOfTime(s.t1);
        if (x1 != null) {
          ctx.beginPath(); ctx.moveTo(x1, 0); ctx.lineTo(x1, ps.h); ctx.stroke();
          var dt = new Date(s.t1 * 1000);
          function p2(n) { return (n < 10 ? "0" : "") + n; }
          var s2 = p2(dt.getUTCMonth() + 1) + "-" + p2(dt.getUTCDate()) + " " + p2(dt.getUTCHours()) + ":" + p2(dt.getUTCMinutes());
          ctx.setLineDash([]);
          ctx.font = "10px Inter, sans-serif";
          var w2 = ctx.measureText(s2).width;
          ctx.fillStyle = s.color || "#f0b90b";
          ctx.fillRect(x1 - w2 / 2 - 3, 2, w2 + 6, 15);
          ctx.fillStyle = "#0b1018";
          ctx.fillText(s2, x1 - w2 / 2, 13);
        }
      } else {
        if (s.t1 == null || s.t2 == null) return ctx.restore();
        x1 = xOfTime(s.t1); y1 = yOfPrice(s.p1);
        x2 = xOfTime(s.t2); y2 = yOfPrice(s.p2);
        if (x1 == null || x2 == null || y1 == null || y2 == null) return ctx.restore();
        if (s.type === "ray") {
          var dx = x2 - x1, dy = y2 - y1;
          var k = dx !== 0 ? (ps.w - x1) / dx : 9999;
          ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x1 + dx * k, y1 + dy * k); ctx.stroke();
        } else if (s.type === "rect") {
          ctx.globalAlpha = ghost ? 0.5 : 0.18;
          ctx.fillRect(x1, y1, x2 - x1, y2 - y1);
          ctx.globalAlpha = ghost ? 0.75 : 1;
          ctx.strokeRect(x1, y1, x2 - x1, y2 - y1);
        } else if (s.type === "fib") {
          lv = [0, 0.236, 0.382, 0.5, 0.618, 0.786, 1];
          var lo = Math.min(s.p1, s.p2), hi = Math.max(s.p1, s.p2);
          var xa = Math.min(x1, x2), xb = Math.max(x1, x2);
          for (i = 0; i < lv.length; i++) {
            px = hi - (hi - lo) * lv[i];
            y = yOfPrice(px);
            if (y == null) continue;
            ctx.globalAlpha = ghost ? 0.6 : 0.9;
            ctx.setLineDash(lv[i] === 0 || lv[i] === 1 ? [] : [4, 3]);
            ctx.strokeStyle = lv[i] === 0.618 ? "#f0b90b" : (s.color || "#8b5cf6");
            ctx.beginPath(); ctx.moveTo(xa, y); ctx.lineTo(ps.w, y); ctx.stroke();
            ctx.setLineDash([]);
            ctx.font = "9px Inter, sans-serif";
            ctx.globalAlpha = 1;
            ctx.fillStyle = lv[i] === 0.618 ? "#f0b90b" : "#8ea0bb";
            ctx.fillText(lv[i] + "  " + window.OKXU.fmtPx(px), xa + 3, y - 2);
          }
        } else { // trend
          ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
          ctx.setLineDash([]);
          ctx.beginPath(); ctx.arc(x1, y1, 2.6, 0, 6.284); ctx.fill();
          ctx.beginPath(); ctx.arc(x2, y2, 2.6, 0, 6.284); ctx.fill();
        }
      }
      ctx.restore();
    }

    function drawAxisHints() {
      if (draw.tool === "cursor") return;
      var names = { trend: "趋势线", hline: "水平线", vline: "垂直线", ray: "射线", rect: "矩形", fib: "斐波那契" };
      var ps = plotSize();
      ctx.save();
      ctx.font = "11px Inter, sans-serif";
      ctx.fillStyle = "rgba(240,185,11,.92)";
      ctx.fillText("✎ " + (names[draw.tool] || draw.tool) + " · 拖拽绘制 · 右键删线 · Esc 退出", 10, Math.max(14, ps.h - 8));
      ctx.restore();
    }

    function redraw() {
      if (!ctx) return;
      var w = el.clientWidth, h = el.clientHeight;
      if (!w || !h) return;
      var dpr = window.devicePixelRatio || 1;
      if (cv.width !== Math.round(w * dpr) || cv.height !== Math.round(h * dpr)) {
        cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr);
      }
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, w, h);
      if (!draw.shapes.length && !draw.draft && draw.tool === "cursor") return;
      var ps = plotSize();
      ctx.save();
      ctx.beginPath(); ctx.rect(0, 0, ps.w, ps.h); ctx.clip();
      for (var i = 0; i < draw.shapes.length; i++) {
        try { drawShape(draw.shapes[i], false); } catch (e) { }
      }
      if (draw.draft) { try { drawShape(draw.draft, true); } catch (e) { } }
      ctx.restore();
      drawAxisHints();
    }

    function loop(ts) {
      draw.raf = requestAnimationFrame(loop);
      if (ts - draw.lastT < 33) return;
      draw.lastT = ts;
      if (draw.dirty || draw.shapes.length || draw.draft || draw.tool !== "cursor") {
        draw.dirty = false;
        redraw();
      }
    }

    function mountCanvas() {
      cv = document.createElement("canvas");
      cv.style.cssText = "position:absolute;left:0;top:0;width:100%;height:100%;z-index:8;pointer-events:none;";
      el.appendChild(cv);
      ctx = cv.getContext("2d");

      cv.addEventListener("pointerdown", function (e) {
        if (draw.tool === "cursor") return;
        e.preventDefault();
        var r = cv.getBoundingClientRect(), x = e.clientX - r.left, y = e.clientY - r.top;
        if (e.button === 2) { delAt(x, y); return; }
        var t = timeOfX(x), p = priceOfY(y);
        if (t == null || p == null) return;
        if (draw.tool === "hline" || draw.tool === "vline") {
          var s = { type: draw.tool, t1: t, p1: p, color: draw.color, width: draw.width };
          draw.shapes.push(s); saveShapes(); draw.dirty = true;
          return;
        }
        draw.draft = { type: draw.tool, t1: t, p1: p, t2: t, p2: p, color: draw.color, width: draw.width };
        try { cv.setPointerCapture(e.pointerId); } catch (e2) { }
      });

      cv.addEventListener("pointermove", function (e) {
        if (!draw.draft) return;
        var r = cv.getBoundingClientRect(), x = e.clientX - r.left, y = e.clientY - r.top;
        var t = timeOfX(x), p = priceOfY(y);
        if (t == null || p == null) return;
        draw.draft.t2 = t; draw.draft.p2 = p;
        draw.dirty = true;
      });

      cv.addEventListener("pointerup", function (e) {
        if (!draw.draft) return;
        var d = draw.draft; draw.draft = null;
        if (d.t2 != null && (Math.abs(d.t1 - d.t2) > 0 || d.p1 !== d.p2)) {
          draw.shapes.push(d); saveShapes();
        }
        draw.dirty = true;
      });

      cv.addEventListener("contextmenu", function (e) { if (draw.tool !== "cursor") e.preventDefault(); });
      cv.addEventListener("dblclick", function (e) {
        if (draw.tool !== "cursor") { e.preventDefault(); clearDraw(); }
      });
    }

    function distSeg(px, py, x1, y1, x2, y2) {
      var dx = x2 - x1, dy = y2 - y1;
      var L = dx * dx + dy * dy;
      var t = L ? ((px - x1) * dx + (py - y1) * dy) / L : 0;
      t = Math.max(0, Math.min(1, t));
      var ax = x1 + t * dx, ay = y1 + t * dy;
      return Math.sqrt((px - ax) * (px - ax) + (py - ay) * (py - ay));
    }
    function distShape(s, x, y) {
      var ps = plotSize();
      if (s.type === "hline") { var yy = yOfPrice(s.p1); return yy == null ? null : Math.abs(y - yy); }
      if (s.type === "vline") { var xx = xOfTime(s.t1); return xx == null ? null : Math.abs(x - xx); }
      var x1 = xOfTime(s.t1), y1 = yOfPrice(s.p1), x2 = xOfTime(s.t2), y2 = yOfPrice(s.p2);
      if (x1 == null || x2 == null || y1 == null || y2 == null) return null;
      if (s.type === "ray") {
        var dx = x2 - x1, dy = y2 - y1;
        var k = dx !== 0 ? (ps.w - x1) / dx : 9999;
        return distSeg(x, y, x1, y1, x1 + dx * k, y1 + dy * k);
      }
      if (s.type === "rect") {
        var d = Math.min(
          distSeg(x, y, x1, y1, x2, y1), distSeg(x, y, x2, y1, x2, y2),
          distSeg(x, y, x2, y2, x1, y2), distSeg(x, y, x1, y2, x1, y1));
        return d;
      }
      if (s.type === "fib") return distSeg(x, y, x1, y1, x2, y2);
      return distSeg(x, y, x1, y1, x2, y2);
    }
    function delAt(x, y) {
      var best = -1, bd = 10;
      for (var i = draw.shapes.length - 1; i >= 0; i--) {
        var d = distShape(draw.shapes[i], x, y);
        if (d != null && d < bd) { bd = d; best = i; }
      }
      if (best >= 0) { draw.shapes.splice(best, 1); saveShapes(); draw.dirty = true; }
    }

    build();
    draw.raf = requestAnimationFrame(loop);

    /* ═══════════════ 对外 API ═══════════════ */
    return {
      el: el,

      /* 全量/合并刷新。opts.merge=true 时保留已加载的历史(轮询用) */
      setData: function (payload, opts) {
        opts = opts || {};
        if (payload.rows) {
          if (opts.merge && lastRows.length) {
            var map = Object.create(null), i;
            for (i = 0; i < lastRows.length; i++) map[lastRows[i][0]] = i;
            for (i = 0; i < payload.rows.length; i++) {
              var r = payload.rows[i], at = map[r[0]];
              if (at == null) { if (!lastRows.length || r[0] > lastRows[lastRows.length - 1][0]) lastRows.push(r); }
              else lastRows[at] = r;
            }
          } else {
            lastRows = payload.rows;
          }
        }
        if (payload.marks) lastMarks = payload.marks;
        if (payload.pivots) lastPivots = payload.pivots;
        if (payload.lines !== undefined) lastLines = payload.lines;
        applyData(true);
        syncLines();
        buildMarkers();
        draw.dirty = true;
      },

      setSignals: function (marks, pivots, lines, th) {
        if (marks) lastMarks = marks;
        if (pivots) lastPivots = pivots;
        if (lines !== undefined) lastLines = lines;
        if (th !== undefined) lastTh = th || 0;
        syncLines();
        buildMarkers();
      },

      /* 只更新末根(实时价跳动) */
      updateLast: function (price, tsMs) {
        if (!cs || !lastRows.length || !(price > 0)) return false;
        var r = lastRows[lastRows.length - 1];
        var t = r[0] + OFF;
        if (tsMs) {
          var iv = lastRows.length > 1 ? (r[0] - lastRows[lastRows.length - 2][0]) : 300;
          if (iv <= 0) iv = 300;
          var barT = Math.floor((tsMs / 1000 + OFF) / iv) * iv;
          if (barT > t) return false;
        }
        try {
          if (isOHLC()) {
            cs.update({ time: t, open: r[1], high: Math.max(r[2], price), low: r[3] > 0 ? Math.min(r[3], price) : price, close: price });
          } else {
            cs.update({ time: t, value: price });
          }
        } catch (e) { return false; }
        // 指标末端同步(MA/BOLL 末点微调, 视觉不滞后)
        var gv = paneS.vol;
        if (gv && gv.vol) {
          try { gv.vol.update({ time: t, value: r[11] || 0, color: r[4] >= r[1] ? "rgba(255,77,79,.32)" : "rgba(14,203,129,.32)" }); } catch (e) { }
        }
        draw.dirty = true;
        return true;
      },

      /* 增量尾部: 覆盖末根/追加新根 */
      setTail: function (tail) {
        if (!cs || !tail || !tail.length) return 0;
        var app = 0, i;
        for (i = 0; i < tail.length; i++) {
          var r = tail[i];
          if (!r || !(r[0] > 0) || !(r[4] > 0)) continue;
          var t = r[0] + OFF, known = (t in byTime);
          if (!known) {
            if (times.length && t <= times[times.length - 1]) {
              // 中间补洞(极少): 定位插入
              var idx = -1;
              for (var q = times.length - 1; q >= 0; q--) if (times[q] < t) { idx = q; break; }
              if (idx < 0) { times.unshift(t); lastRows.unshift(r); }
              else { times.splice(idx + 1, 0, t); lastRows.splice(idx + 1, 0, r); }
              applyData(true);
              continue;
            }
            times.push(t); app++;
            lastRows.push(r);
          } else {
            var pos = -1;
            for (var z = lastRows.length - 1; z >= 0; z--) if (lastRows[z][0] === r[0]) { pos = z; break; }
            if (pos >= 0) lastRows[pos] = r;
          }
          byTime[t] = r;
          try {
            if (isOHLC()) cs.update({ time: t, open: r[1], high: r[2], low: r[3], close: r[4] });
            else cs.update({ time: t, value: r[4] });
          } catch (e) { }
        }
        var gv = paneS.vol;
        if (gv && gv.vol) {
          for (i = 0; i < tail.length; i++) {
            var rr = tail[i];
            if (!rr || !(rr[0] > 0)) continue;
            try { gv.vol.update({ time: rr[0] + OFF, value: rr[11] || 0, color: rr[4] >= rr[1] ? "rgba(255,77,79,.32)" : "rgba(14,203,129,.32)" }); } catch (e) { }
          }
        }
        applyIndicators();
        if (app) buildMarkers();
        draw.dirty = true;
        return app;
      },

      /* 前置更早的历史(拖到最左触发): 视口保持不动 */
      prepend: function (rows) {
        if (!rows || !rows.length || !cs) return 0;
        var cur = null;
        try { cur = chart.timeScale().getVisibleLogicalRange(); } catch (e) { }
        var seen = Object.create(null), i;
        for (i = 0; i < lastRows.length; i++) seen[lastRows[i][0]] = 1;
        var add = [];
        for (i = 0; i < rows.length; i++) {
          var r2 = rows[i];
          if (!r2 || !(r2[0] > 0) || !(r2[4] > 0) || seen[r2[0]]) continue;
          seen[r2[0]] = 1; add.push(r2);
        }
        if (!add.length) return 0;
        add.sort(function (a, b) { return a[0] - b[0]; });
        lastRows = add.concat(lastRows);
        if (lastRows.length > MAX_BARS) lastRows = lastRows.slice(lastRows.length - MAX_BARS);
        applyData(true);
        buildMarkers();
        if (cur) {
          suppress = true;
          try { chart.timeScale().setVisibleLogicalRange({ from: cur.from + add.length, to: cur.to + add.length }); } catch (e) { }
          suppress = false;
        }
        draw.dirty = true;
        return add.length;
      },

      histDone: function (exhausted) { histBusy = false; if (exhausted) histEnd = true; },
      histReset: function () { histBusy = false; histEnd = false; },
      histState: function () { return { busy: histBusy, end: histEnd }; },

      /* 绘图工具 */
      setTool: function (name) {
        draw.tool = name || "cursor";
        draw.draft = null;
        if (cv) cv.style.pointerEvents = draw.tool === "cursor" ? "none" : "auto";
        if (cv) cv.style.cursor = draw.tool === "cursor" ? "default" : "crosshair";
        draw.dirty = true;
      },
      setDrawColor: function (c) { draw.color = c; draw.dirty = true; },
      getShapes: function () { return draw.shapes; },
      clearDraw: function () { draw.shapes = []; draw.draft = null; saveShapes(); draw.dirty = true; },
      undoDraw: function () { if (draw.shapes.length) { draw.shapes.pop(); saveShapes(); draw.dirty = true; } },
      setKey: function (inst, tf) {
        key = (inst || "x") + "|" + (tf || "");
        loadShapes();
      },

      /* 图表配置 */
      setCfg: function (next) {
        var structural = ["type", "panes", "ma", "ema", "boll", "updown", "log"];
        var old = cfg;
        var needBuild = false;
        for (var i = 0; i < structural.length; i++) {
          var k = structural[i];
          if (JSON.stringify(old[k]) !== JSON.stringify(next[k])) { needBuild = true; break; }
        }
        cfg = Object.assign({}, old, next);
        if (needBuild) {
          try { savedRange = chart.timeScale().getVisibleLogicalRange(); } catch (e) { savedRange = null; }
          build();
        } else {
          try {
            chart.applyOptions({
              grid: {
                vertLines: { color: cfg.grid ? "rgba(27,36,52,.85)" : "transparent" },
                horzLines: { color: cfg.grid ? "rgba(27,36,52,.85)" : "transparent" },
              },
              crosshair: {
                mode: (cfg.magnet && LWC.CrosshairMode) ? LWC.CrosshairMode.Magnet : (LWC.CrosshairMode ? LWC.CrosshairMode.Normal : 0),
              },
            });
            chart.timeScale().applyOptions({ rightOffset: cfg.rightOffset, barSpacing: cfg.barSpacing });
          } catch (e) { }
          applyIndicators();
          syncLines();
          buildMarkers();
        }
        draw.dirty = true;
      },
      getCfg: function () { return cfg; },

      getRows: function () { return lastRows; },
      getLayers: function () { return { marks: cfg.marks, pivots: cfg.pivots, vol: cfg.panes.indexOf("vol") >= 0, macd: cfg.panes.indexOf("macd") >= 0 }; },

      fit: function () { savedRange = null; try { chart.timeScale().fitContent(); } catch (e) { } },
      toLatest: function () {
        savedRange = null;
        try { chart.timeScale().scrollToRealTime(); } catch (e) { }
        atLatest = true; if (rangeCb) rangeCb(true);
      },
      isAtLatest: function () { return atLatest; },
      applyPrecision: function () {
        if (!cs || !lastRows.length) return;
        var p = window.OKXU.precOf(lastRows[lastRows.length - 1][4]);
        var pf = { type: "price", precision: p, minMove: Math.pow(10, -p) };
        try { cs.applyOptions({ priceFormat: pf }); } catch (e) { }
        var q;
        for (q in maS) { if (maS.hasOwnProperty(q)) try { maS[q].applyOptions({ priceFormat: pf }); } catch (e) { } }
        for (q in emaS) { if (emaS.hasOwnProperty(q)) try { emaS[q].applyOptions({ priceFormat: pf }); } catch (e) { } }
        if (bollS) { try { bollS.up.applyOptions({ priceFormat: pf }); bollS.mid.applyOptions({ priceFormat: pf }); bollS.dn.applyOptions({ priceFormat: pf }); } catch (e) { } }
      },
      screenshot: function () { try { return chart.takeScreenshot(); } catch (e) { return null; } },
      /* 自检探针: 运行期内部状态(供探针页/排障使用) */
      stats: function () {
        var np = 0, s = 0, k;
        try { np = chart.panes().length; } catch (e) { }
        for (k in maS) if (maS.hasOwnProperty(k)) s++;
        for (k in emaS) if (emaS.hasOwnProperty(k)) s++;
        s += Object.keys(paneS).length;
        var vis = null;
        try { vis = chart.timeScale().getVisibleLogicalRange(); } catch (e) { }
        return {
          bars: lastRows.length, times: times.length, panes: np,
          ma: Object.keys(maS), ema: Object.keys(emaS), boll: !!bollS,
          subPanes: Object.keys(paneS), series: s, shapes: draw.shapes.length,
          tool: draw.tool, atLatest: atLatest, histEnd: histEnd,
          vis: vis ? { from: Math.round(vis.from), to: Math.round(vis.to) } : null,
          marks: lastMarks.length, pivots: lastPivots.length,
        };
      },
      destroy: function () {
        if (draw.raf) cancelAnimationFrame(draw.raf);
        if (chart) { try { chart.remove(); } catch (e) { } chart = null; }
      },
    };
  }

  return { mount: mount };
})();
