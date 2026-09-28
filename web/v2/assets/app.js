/* ============================================================
 *  app.js — OKX 量化终端 v2 (Vue3 + Lightweight Charts + Tailwind)
 *  数据全部来自 C++ 组件 apihub.exe (AJAX, 同源经 Apache 反代)
 * ============================================================ */
(function () {
  var A = window.OKXAPI, U = window.OKXU, C = window.OKXChart;

  var TFS = ["1m", "3m", "5m", "15m", "1h"];
  var IV = { "1m": 60, "3m": 180, "5m": 300, "15m": 900, "1h": 3600 };
  var NS = [150, 300, 500, 800, 1200, 1500];

  /* ── 图表默认配置(可存 localStorage) ── */
  var DEF_CFG = {
    type: "candles", updown: "cn", grid: true, magnet: false, log: false,
    marks: true, pivots: true, avgline: true, tpline: true,
    ma: [5, 10, 20, 60], ema: [], boll: { on: false, n: 20, k: 2 },
    panes: ["vol", "macd"], rsiN: 14, kdjN: [9, 3, 3],
    barSpacing: 7, rightOffset: 8,
  };
  var CFG_KEY = "okx.v2.cfg";
  var TYPES = [["candles", "蜡烛图"], ["bars", "美国线"], ["line", "折线"], ["area", "面积图"]];
  var UPDOWN = [["cn", "红涨绿跌"], ["us", "绿涨红跌"]];
  var PANE_DEF = [["vol", "VOL", "成交量"], ["macd", "MACD", "指数平滑异同"], ["rsi", "RSI", "相对强弱"], ["kdj", "KDJ", "随机指标"]];
  var TOOLS = [
    ["cursor", "光标", "✛"], ["trend", "趋势线", "╱"], ["hline", "水平线", "─"],
    ["vline", "垂直线", "│"], ["ray", "射线", "➚"], ["rect", "矩形", "▭"], ["fib", "斐波那契", "≡"],
  ];
  var DRAW_COLORS = ["#f0b90b", "#ff4d4f", "#0ecb81", "#3b82f6", "#8b5cf6", "#e8eefb"];

  /* ── 转折点净化 ───────────────────────────────────────────────
   * ① 同一根K线去重: 阈值 ZigZag 遇到"单根振幅 >= 阈值"的宽幅K线时, 会把同一根
   *    反复输出成顶/底交替(实测 ACH 5m 里 09-27 07:00 被重复输出 21 次), 图上就是
   *    一堆重叠圆点 —— 这是"看起来公式错了"的直观来源。
   * ② KE 分位: KE=½mv² 是绝对量(m=该段均量), 只在本合约内有意义, 跨合约不可比,
   *    故额外算"在本合约同类转折点里的分位"(P0~P100), 越接近 100 动能越强。 */
  function pivClean(arr) {
    if (!arr || !arr.length) return [];
    var by = {}, out = [];
    for (var i = 0; i < arr.length; i++) {
      var p = arr[i], k = String(p.t), e = by[k];
      // 同一根K线只保留一条: 有真实动能(ke>0)的优先, 否则保留先到的
      // (宽幅K线会被 ZigZag 反复输出成 顶/底 交替, ACH 5m 实测同一根重复 21 次)
      if (!e) { by[k] = p; out.push(p); }
      else if (!(e.ke > 0) && p.ke > 0) {
        e.ke = p.ke; e.g = p.g; e.f = p.f; e.ang = p.ang; e.bars = p.bars;
        e.type = p.type; e.p = p.p; e.i = p.i;
      }
    }
    out.sort(function (a, b) { return a.t - b.t; });
    rank(out, true);
    rank(out, false);
    return out;
  }
  function rank(ps, isBot) {
    var ks = [];
    for (var i = 0; i < ps.length; i++) if ((ps[i].type === 0) === isBot && ps[i].ke > 0) ks.push(ps[i].ke);
    ks.sort(function (a, b) { return a - b; });
    for (var j = 0; j < ps.length; j++) {
      var p = ps[j];
      if ((p.type === 0) !== isBot) continue;
      if (!(p.ke > 0)) { p.pctl = null; continue; }
      var c = 0;
      while (c < ks.length && ks[c] <= p.ke) c++;
      p.pctl = Math.round(100 * c / ks.length);
    }
  }

  function loadCfg() {
    var c = JSON.parse(JSON.stringify(DEF_CFG));
    try {
      var raw = localStorage.getItem(CFG_KEY);
      if (raw) {
        var o = JSON.parse(raw);
        for (var k in o) if (o.hasOwnProperty(k) && c[k] !== undefined) c[k] = o[k];
      }
    } catch (e) { }
    return c;
  }

  var PARAM_ROWS = [
    ["leverage", "杠杆", "x"],
    ["unit_usd", "单笔", "U"],
    ["tp_pct", "止盈", "%"],
    ["max_positions", "最大持仓", "个"],
    ["max_buys_per_hour", "每小时买入", "次"],
    ["max_new_per_scan", "每轮新开", "个"],
    ["add_usd_step", "加仓步长", "U"],
    ["add_cooldown_min", "加仓冷却", "分"],
    ["add_max_per_hour", "每小时加仓", "次"],
    ["grid_max_levels", "最大档位", "档"],
    ["max_units", "最大张数", "张"],
    ["trade_bar", "交易周期", ""],
  ];

  var app = Vue.createApp({
    data: function () {
      return {
        // 配置常量(模板用)
        TFS: TFS, NS: NS, TYPES: TYPES, UPDOWN: UPDOWN, PANE_DEF: PANE_DEF,
        TOOLS: TOOLS, DRAW_COLORS: DRAW_COLORS,
        // 选择状态
        inst: "AUCTION-USDT-SWAP", tf: "5m", nvis: 300,
        cfg: loadCfg(), tool: "cursor", drawColor: "#f0b90b", setOpen: false,
        auto: true, q: "", dropOpen: false,
        // 合约选择走顶栏下拉(dropGroups: 最近交易 + 全部合约)
        // 实时价(1.5s AJAX /ticker): px=最新价 ref=参考价(上一根收盘) dir=1涨/-1跌 n=变动序号(驱动闪光)
        // kt=K线增量刷新成功次数 app=累计新增周期根数(自检: 证明前端轮询链路在跑)
        live: { px: 0, ref: 0, chg: 0, amt: "", dir: 0, n: 0, kt: 0, app: 0 },
        // 历史分页: hloading=正在拉更早; hcount=已额外加载根数; hend=已到最早
        hloading: false, hcount: 0, hend: false,
        // 数据
        rows: [], marks: [], pivots: [], th: 0,
        trades: [], ls: null, grid: null, guard: null,
        syms: [], recent: [],
        // 状态
        clock: U.clock(), busy: false, kloading: false, kerr: "",
        followOff: false, lagMin: 0, hover: null,
      };
    },

    computed: {
      /* ── 当前合约行情(取自K线最后两根) ── */
      sym: function () {
        var r = this.rows;
        if (!r || !r.length) return { px: 0, chg: 0, short: U.short(this.inst) };
        var last = r[r.length - 1], prev = r.length > 1 ? r[r.length - 2] : last;
        var chg = prev[4] ? ((last[4] - prev[4]) / prev[4]) * 100 : 0;
        return { px: last[4], chg: chg, short: U.short(this.inst) };
      },

      /* ── 区间涨跌(只统计最近 nvis 根) ── */
      range: function () {
        var all = this.rows;
        if (all.length < 2) return { pct: 0, hi: 0, lo: 0 };
        var n = Math.min(all.length, this.nvis);
        var r = all.slice(all.length - n);
        var hi = -Infinity, lo = Infinity;
        for (var i = 0; i < r.length; i++) { if (r[i][2] > hi) hi = r[i][2]; if (r[i][3] < lo) lo = r[i][3]; }
        var pct = r[0][4] ? ((r[r.length - 1][4] - r[0][4]) / r[0][4]) * 100 : 0;
        return { pct: pct, hi: hi, lo: lo };
      },

      /* ── 图表高度随副图数量自适应 ── */
      chartH: function () {
        var n = (this.cfg.panes || []).length;
        return 430 + n * 76;
      },

      /* ── 指标/图层开关状态 ── */
      indOn: function () {
        var c = this.cfg, p = c.panes || [];
        return {
          ma: !!(c.ma && c.ma.length), ema: !!(c.ema && c.ema.length),
          boll: !!(c.boll && c.boll.on),
          vol: p.indexOf("vol") >= 0, macd: p.indexOf("macd") >= 0,
          rsi: p.indexOf("rsi") >= 0, kdj: p.indexOf("kdj") >= 0,
        };
      },

      /* ── 核心指标条 ── */
      metrics: function () {
        var d = this.ls, o = [];
        function push(k, v, c) { o.push({ k: k, v: v, c: c || "text-txt-1" }); }
        if (!d) {
          for (var i = 0; i < 8; i++) push("—", "--");
          return o;
        }
        var total = d.net_pnl + d.upl;
        push("总盈亏(含浮动)", (total >= 0 ? "+" : "") + total.toFixed(2) + " U", total >= 0 ? "text-up" : "text-dn");
        push("已实现", (d.net_pnl >= 0 ? "+" : "") + d.net_pnl.toFixed(2) + " U", d.net_pnl >= 0 ? "text-up" : "text-dn");
        push("浮动", (d.upl >= 0 ? "+" : "") + d.upl.toFixed(2) + " U", d.upl >= 0 ? "text-up" : "text-dn");
        var wr = d.closed ? (d.wins / d.closed) * 100 : 0;
        push("胜率", wr.toFixed(1) + "% (" + d.wins + "/" + d.closed + ")", wr >= 60 ? "text-up" : "text-txt-1");
        push("交易笔数", String(d.trades || 0));
        push("持仓 / 保证金", d.open_pos + " 个 · " + d.pos_margin.toFixed(2) + "U", d.open_pos > 0 ? "text-gold" : "text-txt-3");
        push("手续费 / 资金费", d.fee_sum.toFixed(3) + " / " + d.fund_sum.toFixed(3), "text-txt-2");
        var fr = d.funding && d.funding.rate != null ? d.funding.rate * 100 : null;
        push("资金费率", fr == null ? "--" : fr.toFixed(4) + "%", fr == null ? "text-txt-3" : (fr >= 0 ? "text-up" : "text-dn"));
        push("区间涨跌(" + Math.min(this.rows.length, this.nvis) + "根)", (this.range.pct >= 0 ? "+" : "") + this.range.pct.toFixed(2) + "%", this.range.pct >= 0 ? "text-up" : "text-dn");
        return o;
      },

      /* ── 合约下拉分组 ── */
      dropGroups: function () {
        var q = this.q.trim().toUpperCase();
        function m(s) { return !q || s.toUpperCase().indexOf(q) >= 0; }
        var g = [];
        var rec = this.recent.filter(m);
        if (rec.length) g.push({ name: "最近交易", list: rec.slice(0, 24) });
        var rest = this.syms.filter(function (s) { return m(s) && rec.indexOf(s) < 0; });
        if (rest.length) g.push({ name: "全部合约 (" + rest.length + ")", list: rest.slice(0, 150) });
        return g;
      },

      /* ── 实时价闪烁样式(涨红/跌绿, 变动瞬间闪光) ── */
      liveFlash: function () {
        if (this.live.dir > 0) return "text-up animate-flashup";
        if (this.live.dir < 0) return "text-dn animate-flashdn";
        return this.live.chg >= 0 ? "text-up" : "text-dn";
      },
      liveBadge: function () {
        return this.live.chg >= 0 ? "border-up/40 bg-up/10 text-up" : "border-dn/40 bg-dn/10 text-dn";
      },

      /* ── 信号统计 + 表格(倒序) ── */
      sigN: function () {
        var n = { buy: 0, add: 0, close: 0 };
        for (var i = 0; i < this.marks.length; i++) if (n[this.marks[i].kind] != null) n[this.marks[i].kind]++;
        return n;
      },
      markRows: function () { return this.marks.slice().reverse().slice(0, 60); },
      pivRows: function () { return this.pivots.slice().reverse().slice(0, 80); },
      marksDays: function () {
        var n = Math.max(this.nvis, Math.min(this.rows.length, 4000));
        var d = Math.ceil((n * IV[this.tf]) / 86400) + 1;
        return Math.max(1, Math.min(90, d));
      },

      /* ── 网格/持仓 ── */
      gridRows: function () { return (this.grid && this.grid.rows) || []; },
      gridLogs: function () { return ((this.grid && this.grid.logs) || []).slice(0, 40); },

      /* ── 守护 ── */
      guardTargets: function () { return (this.guard && this.guard.targets) || []; },
      guardOk: function () { var n = 0, t = this.guardTargets; for (var i = 0; i < t.length; i++) if (t[i].ok) n++; return n; },
      /* 真·金▲计数: 底部 + 下跌腿动能 KE 达该合约底部KE的75分位阈值(与引擎同口径) */
      goldN: function () {
        var n = 0, t = this.th;
        for (var i = 0; i < this.pivots.length; i++) {
          var p = this.pivots[i];
          if (p && p.type === 0 && p.ke > 0 && t > 0 && p.ke >= t) n++;
        }
        return n;
      },
      guardN: function () { return this.guardTargets.length; },
      guardBad: function () { return this.guardN > 0 && this.guardOk === this.guardN; },

      /* ── 风控参数 ── */
      params: function () {
        var p = this.grid && this.grid.params;
        if (!p) return [];
        var out = [];
        for (var i = 0; i < PARAM_ROWS.length; i++) {
          var key = PARAM_ROWS[i][0], lbl = PARAM_ROWS[i][1], unit = PARAM_ROWS[i][2];
          var v = p[key];
          if (v == null) continue;
          if (typeof v === "number") v = Math.abs(v) < 1 && v !== 0 ? String(+v.toFixed(4)) : String(v);
          out.push({ k: lbl, v: v + (unit ? " " + unit : "") });
        }
        return out;
      },

      /* ── 当前合约的持仓线(均价/止盈) ── */
      curPos: function () {
        var r = this.gridRows;
        for (var i = 0; i < r.length; i++) if (r[i].inst_id === this.inst) return r[i];
        return null;
      },
    },

    methods: {
      /* 工具透传(模板使用) */
      num: U.num, fmtPx: U.fmtPx, short: U.short,
      ts: function (ms) { return U.ts(ms, true); },

      /* ── 信号样式 ── */
      /* 类名助手(HTML 绑定里**禁止出现 ">" 字符**: 大纲/编辑器注入属性时会把它当标签结束
       * 从而截断绑定 → Vue 报 SyntaxError 整页白屏。故一律走方法调用) */
      sgnCls: function (v) { return v >= 0 ? "text-up" : "text-dn"; },
      dirCls: function (d) { return d > 0 ? "text-up animate-arrowpop" : "text-ink-500"; },
      dnCls: function (d) { return d < 0 ? "text-dn animate-arrowpop" : "text-ink-500"; },
      profitCls: function (p) { return p == null ? "text-txt-4" : (p >= 0 ? "text-up" : "text-dn"); },
      pctlCls: function (v) { return v == null ? "text-txt-4" : (v >= 75 ? "text-gold" : (v >= 50 ? "text-txt-2" : "text-txt-4")); },
      /* 真·金▲: 底部 + 下跌腿动能 KE 达该合约底部KE的75分位阈值(与引擎 gold_signal 同口径) */
      isGold: function (p) { return !!p && p.type === 0 && p.ke > 0 && this.th > 0 && p.ke >= this.th; },
      kindTxt: function (k) { return k === "buy" ? "🚀 买入" : k === "add" ? "▲ 加仓" : "🍃 平仓"; },
      kindCls: function (k) {
        return k === "buy" ? "border-up/40 bg-up/10 text-up"
          : k === "add" ? "border-gold/40 bg-gold/10 text-gold"
            : "border-ink-500 bg-ink-750 text-txt-2";
      },
      actTxt: function (a) { return a === "buy" ? "买入" : a === "add" ? "加仓" : a === "close" ? "平仓" : a; },
      actCls: function (a) {
        return a === "buy" ? "border-up/40 bg-up/10 text-up"
          : a === "add" ? "border-gold/40 bg-gold/10 text-gold"
            : "border-dn/40 bg-dn/10 text-dn";
      },
      pnlTxt: function (t) {
        if (t.profit == null || t.profit === "") return "text-txt-4";
        return U.num(t.profit) >= 0 ? "text-up" : "text-dn";
      },

      /* ── 止盈进度 ── */
      tpPct: function (r) {
        var last = U.num(r.last_px), avg = U.num(r.avg_px), tp = U.num(r.tp_px);
        if (!(avg > 0) || !(tp > avg)) return 0;
        var p = ((last - avg) / (tp - avg)) * 100;
        return Math.max(0, Math.min(100, p));
      },
      tpDist: function (r) {
        var last = U.num(r.last_px), tp = U.num(r.tp_px);
        if (!(last > 0) || !(tp > 0)) return 0;
        return ((tp - last) / last) * 100;
      },
      /* ── 选择合约/周期 ── */
      pick: function (s) {
        if (!s) return;
        this.inst = s; this.dropOpen = false; this.q = "";
        this.pivots = []; this.marks = []; this.th = 0;
        this.resetLive();
        if (this.ctrl) { this.ctrl.setKey(s, this.tf); this.ctrl.histReset(); }
        this.hcount = 0; this.hend = false;
        location.hash = "#/" + s + "/" + this.tf;
        this.loadKline(); this.loadSignals(); this.loadOne();
      },
      setTf: function (t) {
        if (this.tf === t) return;
        this.tf = t;
        this.resetLive();
        if (this.ctrl) { this.ctrl.setKey(this.inst, t); this.ctrl.histReset(); }
        this.hcount = 0; this.hend = false;
        location.hash = "#/" + this.inst + "/" + t;
        this.loadKline(); this.loadSignals();
      },
      fitChart: function () { if (this.ctrl) { this.ctrl.fit(); this.followOff = false; } },
      toLatest: function () { if (this.ctrl) { this.ctrl.toLatest(); this.followOff = false; } },

      /* ══════ 图表配置(指标/样式/绘图) ══════ */
      saveCfg: function () {
        try { localStorage.setItem(CFG_KEY, JSON.stringify(this.cfg)); } catch (e) { }
      },
      pushCfg: function () {
        if (this.ctrl) this.ctrl.setCfg(this.cfg);
        this.saveCfg();
        this.followOff = false;
      },
      setCfgKey: function (k, v) {
        var o = Object.assign({}, this.cfg);
        o[k] = v;
        this.cfg = o;
        this.pushCfg();
      },
      toggleMA: function () { this.setCfgKey("ma", (this.cfg.ma && this.cfg.ma.length) ? [] : [5, 10, 20, 60]); },
      toggleEMA: function () { this.setCfgKey("ema", (this.cfg.ema && this.cfg.ema.length) ? [] : [7, 25, 99]); },
      toggleBOLL: function () {
        var b = Object.assign({}, this.cfg.boll);
        b.on = !b.on;
        this.setCfgKey("boll", b);
      },
      togglePane: function (n) {
        var p = (this.cfg.panes || []).slice();
        var i = p.indexOf(n);
        if (i >= 0) p.splice(i, 1);
        else { if (p.length >= 3) return; p.push(n); }
        this.setCfgKey("panes", p);
      },
      setMaStr: function (e) {
        var a = String(e.target.value || "").split(/[,\s]+/).map(function (x) { return parseInt(x, 10); })
          .filter(function (x) { return x > 1 && x < 500; });
        this.setCfgKey("ma", a.slice(0, 6));
      },
      setEmaStr: function (e) {
        var a = String(e.target.value || "").split(/[,\s]+/).map(function (x) { return parseInt(x, 10); })
          .filter(function (x) { return x > 1 && x < 500; });
        this.setCfgKey("ema", a.slice(0, 6));
      },
      setBollN: function (e) {
        var b = Object.assign({}, this.cfg.boll); b.n = Math.max(2, Math.min(200, parseInt(e.target.value, 10) || 20));
        this.setCfgKey("boll", b);
      },
      setBollK: function (e) {
        var b = Object.assign({}, this.cfg.boll); b.k = Math.max(0.5, Math.min(5, parseFloat(e.target.value) || 2));
        this.setCfgKey("boll", b);
      },
      setRsiN: function (e) { this.setCfgKey("rsiN", Math.max(2, Math.min(100, parseInt(e.target.value, 10) || 14))); },
      setKdjStr: function (e) {
        var a = String(e.target.value || "").split(/[,\s]+/).map(function (x) { return parseInt(x, 10); })
          .filter(function (x) { return x > 0 && x < 100; });
        if (a.length === 3) this.setCfgKey("kdjN", a);
      },
      resetCfg: function () {
        this.cfg = JSON.parse(JSON.stringify(DEF_CFG));
        if (this.ctrl) this.ctrl.setKey(this.inst, this.tf);
        this.pushCfg();
      },

      /* ══════ 绘图工具 ══════ */
      setTool: function (t) {
        this.tool = t;
        if (this.ctrl) this.ctrl.setTool(t);
      },
      setDrawColor: function (c) {
        this.drawColor = c;
        if (this.ctrl) this.ctrl.setDrawColor(c);
      },
      clearDraw: function () { if (this.ctrl) this.ctrl.clearDraw(); },
      undoDraw: function () { if (this.ctrl) this.ctrl.undoDraw(); },

      /* ══════ 历史分页: 拖到最左自动补 600 根 ══════ */
      loadHistory: function (oldestSec) {
        if (this.hloading || this.hend) return;
        var self = this, inst = this.inst, tf = this.tf;
        this.hloading = true;
        var before = Math.round(oldestSec) * 1000;
        return A.kline(inst, tf, 600, { before: before }).then(function (j) {
          self.hloading = false;
          if (self.inst !== inst || self.tf !== tf) return;
          if (!j || !j.ok || !j.rows || !j.rows.length) {
            self.hend = true;
            if (self.ctrl) self.ctrl.histDone(true);
            return;
          }
          var got = j.rows.length;
          var add = self.ctrl ? self.ctrl.prepend(j.rows) : 0;
          self.hcount += add;
          self.rows = self.ctrl ? self.ctrl.getRows() : self.rows;
          var over = (got < 600 || add === 0);
          if (self.ctrl) self.ctrl.histDone(over);
          if (over) self.hend = true;
          if (add) self.loadSignals();
        }, function () {
          self.hloading = false;
          if (self.ctrl) self.ctrl.histDone(false);
        });
      },

      /* ── 实时价: AJAX /ticker → 头部数字 + 图上末根K线实时跳动 ── */
      loadTick: function () {
        var self = this, inst = this.inst;
        return A.ticker(inst).then(function (d) {
          if (self.inst !== inst) return;
          if (!d || !d.ok || !(U.num(d.price) > 0)) return;
          self.pushTick(U.num(d.price), d.ts);
        }, function () { });
      },
      pushTick: function (px, tsSec) {
        if (!(px > 0)) return;
        var rows = this.rows;
        if (!rows.length) return;
        var prev = this.live.px;
        var ref = rows.length > 1 ? U.num(rows[rows.length - 2][4]) : U.num(rows[rows.length - 1][1]);
        if (!(ref > 0)) ref = px;
        var p = U.precOf(px), amt = px - ref;
        this.live = {
          px: px, ref: ref,
          chg: ref > 0 ? ((px - ref) / ref) * 100 : 0,
          amt: (amt >= 0 ? "+" : "") + amt.toFixed(p),
          dir: prev > 0 ? (px > prev ? 1 : (px < prev ? -1 : this.live.dir)) : 0,
          n: (prev > 0 && px !== prev) ? this.live.n + 1 : this.live.n,
          kt: this.live.kt, app: this.live.app,
        };
        if (this.ctrl) this.ctrl.updateLast(px, tsSec ? tsSec * 1000 : 0);
      },
      pollTick: function () {
        if (!this.auto || document.visibilityState === "hidden") return;
        this.loadTick();
      },
      resetLive: function () {
        this.live = { px: 0, ref: 0, chg: 0, amt: "", dir: 0, n: 0, kt: 0, app: 0 };
      },

      /* ── 数据加载 ── */
      /* keepHist=true 时只做增量合并(保留已加载的更早K线) */
      loadKline: function (keepHist) {
        var self = this;
        this.kloading = true; this.kerr = "";
        // fresh=100: 服务端先向 OKX 拉最近100根(含正在形成的那根)写库再返回 → 首屏即最新
        return A.kline(this.inst, this.tf, this.nvis, { fresh: 100 }).then(function (j) {
          self.kloading = false;
          if (!j || !j.ok) { self.kerr = (j && j.err) || "K线接口异常"; return; }
          var rows = j.rows || [];
          if (j.now && j.last) self.lagMin = Math.max(0, Math.round((j.now / 1000 - j.last) / 60));
          if (self.ctrl) {
            self.ctrl.setData({ rows: rows }, { merge: !!keepHist });
            self.ctrl.applyPrecision();
            self.rows = self.ctrl.getRows();
          } else {
            self.rows = rows;
          }
          if (!keepHist) {
            self.hcount = 0; self.hend = false;
            if (self.ctrl) self.ctrl.histReset();
          }
          // K线到位后用收盘价初始化/校正实时价基准(参考价=上一根收盘)
          if (self.rows.length) self.pushTick(U.num(self.rows[self.rows.length - 1][4]));
          self.syncLines();
        }, function (e) { self.kloading = false; self.kerr = "K线请求失败: " + e.message; });
      },

      loadSignals: function () {
        var self = this;
        var days = this.marksDays;
        var lim = Math.max(this.nvis, Math.min(this.rows.length, 2000));
        return Promise.all([
          A.sigs(this.inst, this.tf, lim).catch(function () { return null; }),
          A.marks(this.inst, days).catch(function () { return null; }),
        ]).then(function (r) {
          var sg = r[0], mk = r[1];
          self.th = (sg && sg.th) || 0;
          self.pivots = (sg && sg.ok && sg.data) ? pivClean(sg.data) : [];
          self.marks = (mk && mk.ok && mk.data) ? mk.data : [];
          if (self.ctrl) {
            self.ctrl.setSignals(self.marks, self.pivots, self.lineSpec(), self.th);
          }
        });
      },

      lineSpec: function () {
        var p = this.curPos;
        if (!p) return null;
        return { avg: U.num(p.avg_px), tp: U.num(p.tp_px) };
      },
      syncLines: function () {
        if (this.ctrl) this.ctrl.setSignals(undefined, undefined, this.lineSpec());
      },

      loadOne: function () {
        var self = this;
        return A.livestats(this.inst).then(function (j) { if (j && j.ok) self.ls = j.data; }, function () { });
      },
      loadTrades: function () {
        var self = this;
        return A.trades().then(function (j) { if (j && j.ok) self.trades = j.data || []; }, function () { });
      },
      loadGrid: function () {
        var self = this;
        return A.gridmon().then(function (j) {
          if (j && j.ok) { self.grid = j.data; self.syncLines(); }
        }, function () { });
      },
      loadGuard: function () {
        var self = this;
        return A.guard().then(function (j) { if (j && j.ok) self.guard = j; }, function () { });
      },

      loadMeta: function () {
        var self = this;
        return Promise.all([
          A.symbols().catch(function () { return null; }),
          A.boot().catch(function () { return null; }),
          A.gridmon().catch(function () { return null; }),
        ]).then(function (r) {
          if (r[0] && r[0].ok) self.syms = r[0].data || [];
          if (r[1] && r[1].ok) self.recent = r[1].recent || [];
          if (r[2] && r[2].ok) self.grid = r[2].data;
          // 初始合约: hash > boot.default
          var m = /^#\/([A-Z0-9\-]+)\/(\w+)$/.exec(location.hash || "");
          if (m) { self.inst = m[1]; if (TFS.indexOf(m[2]) >= 0) self.tf = m[2]; }
          else if (r[1] && r[1].ok && r[1].default) self.inst = r[1].default;
        });
      },

      refreshAll: function (manual) {
        var self = this;
        this.busy = !!manual;
        return Promise.all([
          this.loadKline(), this.loadSignals(), this.loadOne(),
          this.loadTrades(), this.loadGrid(), this.loadGuard(),
        ]).then(function () { self.busy = false; }, function () { self.busy = false; });
      },

      /* 轻量轮询: 只刷不重画的项 */
      poll: function () {
        if (!this.auto) return;
        if (document.visibilityState === "hidden") return;
        this.loadOne(); this.loadGrid();
      },
      pollTrades: function () { if (this.auto && document.visibilityState === "visible") this.loadTrades(); },
      pollGuard: function () { if (this.auto && document.visibilityState === "visible") this.loadGuard(); },
      pollKline: function () {
        if (!this.auto || document.visibilityState === "hidden") return;
        if (this.ctrl && !this.ctrl.isAtLatest()) { this.followOff = true; return; }
        this.followOff = false;
        this.loadKline(true);   // 合并模式: 不冲掉已加载的更早K线
      },
      /* 3s 尾部刷新: 只拉最近 10 根(fresh 强制打 OKX) → 图面实时补新根 + 更新进行中根 */
      pollKTail: function () {
        if (!this.auto || document.visibilityState === "hidden") return;
        if (!this.rows.length) return;
        if (this.ctrl && !this.ctrl.isAtLatest()) return;   // 用户正在看历史 → 不打扰
        var self = this, inst = this.inst, tf = this.tf;
        return A.kline(inst, tf, 10, { fresh: 1 }).then(function (j) {
          if (self.inst !== inst || self.tf !== tf) return;
          if (!j || !j.ok || !j.rows || !j.rows.length) return;
          var tail = j.rows, app = self.ctrl ? self.ctrl.setTail(tail) : 0;
          self.live.kt = self.live.kt + 1;
          if (app) self.live.app = self.live.app + app;
          if (self.ctrl) self.rows = self.ctrl.getRows();
          if (j.now && j.last) self.lagMin = Math.max(0, Math.round((j.now / 1000 - j.last) / 60));
          if (app) self.loadSignals();   // 新周期开始 → 买卖信号立即重取
        }, function () { });
      },
    },

    mounted: function () {
      var self = this;

      // 图表挂载(全部配置从 cfg 读; 绘图按 合约|周期 记忆)
      this.ctrl = C.mount(this.$refs.chartEl, {
        cfg: this.cfg,
        onHover: function (h) { self.hover = h; },
        onAtLatest: function (v) { self.followOff = !v; },
        onNeedHistory: function (oldestSec) { self.loadHistory(oldestSec); },
      });
      this.ctrl.setKey(this.inst, this.tf);
      this.ctrl.setDrawColor(this.drawColor);
      this.ctrl.setTool("cursor");

      this.loadMeta().then(function () {
        self.ctrl.setKey(self.inst, self.tf);
        return self.refreshAll(false);
      });

      // 时钟
      this._t1 = setInterval(function () { self.clock = U.clock(); }, 1000);
      // 轮询
      this._t2 = setInterval(function () { self.poll(); }, 3000);
      this._t3 = setInterval(function () { self.pollKline(); }, 20000);          // 全量(带 fresh)
      this._t4 = setInterval(function () { self.pollTrades(); }, 15000);
      this._t5 = setInterval(function () { self.pollGuard(); }, 10000);
      // 买卖信号: 15s 重取(新买入不刷新页面也能落图)
      this._t6 = setInterval(function () {
        if (self.auto && document.visibilityState === "visible") self.loadSignals();
      }, 15000);
      // 实时价: 1.5s 打 /ticker(服务端 1s 缓存) → 头部与图面末根K线实时跳动
      this._t7 = setInterval(function () { self.pollTick(); }, 1500);
      // K线尾部: 3s 只拉最近10根 → 图面实时跟到最新一根(含进行中)
      this._t8 = setInterval(function () { self.pollKTail(); }, 3000);

      // 点击空白关闭下拉
      document.addEventListener("click", function (e) {
        if (self.dropOpen && !(e.target.closest && e.target.closest(".relative"))) self.dropOpen = false;
      });
      // Esc 退出绘图工具 / 关闭设置面板
      document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") { self.setTool("cursor"); self.setOpen = false; }
      });
      window.addEventListener("resize", function () { if (self.ctrl && self.ctrl.applyPrecision) self.ctrl.applyPrecision(); });
    },

    beforeUnmount: function () {
      clearInterval(this._t1); clearInterval(this._t2); clearInterval(this._t3);
      clearInterval(this._t4); clearInterval(this._t5); clearInterval(this._t6);
      clearInterval(this._t7); clearInterval(this._t8);
      if (this.ctrl) this.ctrl.destroy();
    },
  });

  app.mount("#app");
})();
