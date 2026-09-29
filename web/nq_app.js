/* ============================================================
 *  nq_app.js — NQ 纳指期货终端 前端主应用 (保留 v2 数字货币页全部样式体系)
 *  【文件职责】
 *    1) K线: 1m/5m/15m 三周期, 数据来自 C++ 组件 nqhub.exe 维护的 kline_nq_*
 *       (经 nq_api.php 窗口化输出, 拖最左自动翻页), 图面/工具/指标复用 OKXChart
 *    2) 实时: 5s 尾部轮询(nq_api?tf&limit=12 → setTail), 30s 全量刷新
 *    3) 模拟交易: /nqsim (状态) + /nqsim?op=order&side= (手动买卖排队)
 *       引擎在 nqhub.exe 内(自动策略 + 手动单成交), 本页只展示与投单
 *    4) AI 全市场模拟回测报告面板(/btlist, 齿轮展开滚动)
 *    5) 行情分析条: 1h 200 根本地算 24h 涨跌/振幅/7 日涨跌
 *  【铁律】模板绑定值里禁止出现裸比较表达式(一律走 methods), 防编辑器注入截断
 * ============================================================ */
(function () {
  var C = window.OKXChart, U = window.OKXU;              // 图表控制器 / 工具函数
  var TFS = ["1m", "5m", "15m"];                         // 支持的K线周期
  var NS = [300, 500, 800, 1200, 2000];                  // 初始可视根数选项

  /* ── 通用 GET(同源, Apache 转发) ── */
  function get(path, params, timeoutMs) {                // 返回 Promise<JSON>
    var qs = [];                                         // 查询串片段
    params = params || {};                               // 缺省
    for (var k in params) if (params[k] !== undefined && params[k] !== null) qs.push(encodeURIComponent(k) + "=" + encodeURIComponent(params[k]));   // 组装
    var url = path + (qs.length ? "?" + qs.join("&") : "");   // 完整 URL
    var ctl = typeof AbortController !== "undefined" ? new AbortController() : null;   // 超时控制器
    var tm = null;                                       // 定时器
    if (ctl) tm = setTimeout(function () { ctl.abort(); }, timeoutMs || 15000);   // 默认 15s 超时
    return fetch(url, { cache: "no-store", signal: ctl ? ctl.signal : undefined })   // 请求
      .then(function (r) { if (tm) clearTimeout(tm); return r.json(); });            // 解析 JSON
  }

  /* ── 图表默认配置(独立键名, 与数字货币页互不影响) ── */
  var DEF_CFG = {
    type: "candles", updown: "cn", grid: true, magnet: false, log: false,
    marks: true, pivots: false, avgline: true, tpline: false,
    ma: [5, 10, 20, 60], ema: [], boll: { on: false, n: 20, k: 2 },
    panes: ["vol", "macd"], rsiN: 14, kdjN: [9, 3, 3],
    barSpacing: 7, rightOffset: 8,
  };
  var CFG_KEY = "okx.nq.cfg";                            // localStorage 键
  var TYPES = [["candles", "蜡烛图"], ["bars", "美国线"], ["line", "折线"], ["area", "面积图"]];   // 图型
  var PANE_DEF = [["vol", "VOL", "成交量"], ["macd", "MACD", "指数平滑异同"], ["rsi", "RSI", "相对强弱"], ["kdj", "KDJ", "随机指标"]];   // 副图
  var TOOLS = [["cursor", "光标", "✛"], ["trend", "趋势线", "╱"], ["hline", "水平线", "─"], ["vline", "垂直线", "│"], ["ray", "射线", "➚"], ["rect", "矩形", "▭"], ["fib", "斐波那契", "≡"]];   // 绘图工具
  var COLORS = ["#f0b90b", "#ff4d4f", "#0ecb81", "#3b82f6", "#8b5cf6", "#e8eefb"];   // 画线色板

  var app = Vue.createApp({                            // Vue 应用
    data() {                                           // 状态
      var cfg = Object.assign({}, DEF_CFG);            // 配置副本
      try { var s = localStorage.getItem(CFG_KEY); if (s) cfg = Object.assign(cfg, JSON.parse(s)); } catch (e) { }   // 恢复持久化配置
      return {
        tf: "1m", nvis: 500, auto: true,               // 周期/可视根数/自动刷新
        cfg: cfg, ctrl: null,                          // 图表配置 / 控制器句柄
        rows: [], hover: null, atLatest: true, histEnd: false,   // K线/悬停/视口状态
        sym: { px: 0, t: 0, chg24: 0, hi24: 0, lo24: 0, chg7: 0 },   // 行情摘要
        sim: null, toast: "", pending: false,          // 模拟交易状态/提示
        btList: [], btExpand: false,                   // AI 回测报告列表/面板展开
        guard: { name: "finally_guard", ok: 0, ts: 0 },// 守护状态
        clock: "", drawTool: "cursor", drawColor: "#f0b90b",   // 时钟/画笔
        tfs: TFS, nsOpts: NS, types: TYPES, panes: PANE_DEF, tools: TOOLS, colors: COLORS,   // 常量表
        ctype: cfg.type,                               // 当前图型(下拉绑定)
      };
    },
    computed: {                                        // 派生
      pos() { return (this.sim && this.sim.pos) || { qty: 0, avg: 0, addpx: 0, adds: 0, upl: 0 }; },   // 持仓
      stat() { return (this.sim && this.sim.stat) || { ncl: 0, nwin: 0, pnl: 0, pmax: 0, pmin: 0 }; }, // 统计
      levP() { return (this.sim && this.sim.lev) || 10; },    // 杠杆
      tpP() { return (this.sim && this.sim.tp) || 0.2; },     // 止盈%
      addDipP() { return (this.sim && this.sim.addDip) || 0.1; },   // 加仓跌幅%
      coolP() { return (this.sim && this.sim.cool) || 30; },        // 冷却分钟
      maxAdds() { return (this.sim && this.sim.maxAdds) || 10; },   // 最多加仓
      hasPos() { return this.pos.qty > 0; },             // 是否持仓
      tpPx() { return this.pos.avg > 0 ? this.pos.avg * (1 + this.tpP / 100) : 0; },   // 止盈价
      uplPct() { return this.pos.avg > 0 ? (this.sym.px - this.pos.avg) / this.pos.avg * 100 : 0; },   // 浮盈%
      tpProg() {                                        // 止盈进度%(用于进度条)
        if (!this.hasPos || this.pos.avg <= 0 || this.tpPx <= this.pos.avg) return 0;
        var p = (this.sym.px - this.pos.avg) / (this.tpPx - this.pos.avg) * 100;   // 线性进度
        return Math.max(0, Math.min(100, p));           // 夹 0~100
      },
      winRate() { return this.stat.ncl > 0 ? this.stat.nwin / this.stat.ncl * 100 : 0; },   // 胜率
      trades() { return (this.sim && this.sim.trades) || []; },   // 台账
      orders() { return (this.sim && this.sim.orders) || []; },   // 挂单
      atLatestTxt() { return this.atLatest ? "最新" : "回看历史"; },   // 视口状态文案
      amp24() {                                        // 24h 振幅%
        return (this.sym.lo24 > 0) ? (this.sym.hi24 - this.sym.lo24) / this.sym.lo24 * 100 : 0;
      },
    },
    methods: {
      /* ── 格式化 ── */
      btTime(ts) {                                     // 回测场次时间 月-日 时:分
        if (!ts) return "";
        var s = U.tsFull(ts);                          // 完整北京时间
        return s.length >= 16 ? s.slice(5, 16) : s;    // 截 "MM-DD HH:mm"
      },
      colorBox(c) {                                    // 色板小方块样式(选中描边)
        return "background:" + c + ";outline:" + (this.drawColor === c ? "2px solid #b3c0d6" : "none");
      },
      barStyle() {                                     // 止盈进度条样式
        return "height:100%;width:" + this.tpProg + "%;background:linear-gradient(90deg,#0ecb81,#f0b90b)";
      },
      closedCls(st) { return st === "OPEN" ? "text-gold" : "text-txt-4"; },   // 台账状态色
      fmtPx(v) { return U.fmtPx(v); },                 // 价格格式化(按量级)
      num(v, n) { return (v === null || v === undefined) ? "-" : Number(v).toFixed(n === undefined ? 2 : n); },   // 数值格式化
      pct(v) { return (v >= 0 ? "+" : "") + Number(v).toFixed(2); },   // 百分比(带符号)
      sgnCls(v) { return v > 0 ? "text-up" : (v < 0 ? "text-dn" : "text-txt-3"); },   // 涨跌色(红涨绿跌)
      profitCls(v) { return v > 0 ? "text-up" : (v < 0 ? "text-dn" : "text-txt-3"); }, // 盈亏色
      kindName(k) { return k === "open" ? "开仓" : k === "add" ? "加仓" : k === "close" ? "平仓" : k; },   // 台账类型名
      kindCls(k) { return k === "open" ? "text-up" : k === "add" ? "text-gold" : "text-info"; },           // 台账类型色
      tfOn(t) { return this.tf === t; },               // 周期选中判断(模板里禁止裸比较)
      cfgOnMap(k) { return this.cfg.panes.indexOf(k) >= 0; },   // 副图开关
      maTxt() { return this.cfg.ma.join("/"); },       // MA 周期文案
      emaTxt() { return this.cfg.ema.length ? this.cfg.ema.join("/") : "—"; },   // EMA 周期文案
      bollOn() { return this.cfg.boll.on === true; },  // BOLL 开关
      marksOn() { return this.cfg.marks === true; },   // 标记开关
      avgOn() { return this.cfg.avgline === true; },   // 均价线开关
      badgeTxt() {                                     // 页脚数据源徽章
        return "实时价 " + U.fmtPx(this.sym.px) + " · K线 " + this.rows.length + " 根 · " + this.tf;
      },

      /* ── 数据加载 ── */
      loadKline() {                                    // 全量/切周期加载
        var self = this;                               // 闭包
        return get("/nq_api.php", { tf: this.tf, limit: this.nvis }).then(function (r) {   // 拉窗口
          if (!r || !r.ok) return;                     // 失败
          self.rows = r.rows;                          // 缓存
          if (self.ctrl) self.ctrl.setData({ rows: r.rows, marks: self.buildMarks(), pivots: [], lines: self.panelLines() });   // 灌图
        }).catch(function () { });
      },
      loadTail() {                                     // 尾部增量(含末根跳动)
        var self = this;                               // 闭包
        if (!this.ctrl) return Promise.resolve();       // 未挂载
        return get("/nq_api.php", { tf: this.tf, limit: 12 }).then(function (r) {   // 最近 12 根
          if (!r || !r.ok || !r.rows || !r.rows.length) return;   // 空
          self.ctrl.setTail(r.rows);                    // 增量灌图
          self.rows = self.ctrl.getRows();              // 同步缓存
          var last = r.rows[r.rows.length - 1];         // 末根
          if (last) self.sym.px = last[4];              // 最新价
        }).catch(function () { });
      },
      loadHistory() {                                  // 拖最左翻页(更老 600 根)
        var self = this;                               // 闭包
        if (this.histEnd) { return; }   // 已到底
        var first = this.rows.length ? this.rows[0][0] * 1000 : 0;   // 最早根 ms
        if (!first) return;                            // 无数据
        return get("/nq_api.php", { tf: this.tf, before: first, limit: 600 }).then(function (r) {   // 更老一页
          if (!r || !r.ok || !r.rows || !r.rows.length) { self.histEnd = true; if (self.ctrl) self.ctrl.histDone(true); return; }   // 到底
          self.ctrl.prepend(r.rows);                   // 前置插入+视口平移(无感翻页)
          if (self.ctrl) self.ctrl.histDone(r.rows.length < 600);   // 通知完成(<600=后端到底)
          self.rows = self.ctrl.getRows();             // 同步
        }).catch(function () { });
      },
      loadUpstream() {                                 // 1h 数据算行情摘要(24h/7d)
        var self = this;                               // 闭包
        return get("/nq_api.php", { tf: "1h", limit: 200 }).then(function (r) {   // 200 根 1h
          if (!r || !r.ok || !r.rows || r.rows.length < 25) return;   // 不足
          var a = r.rows, n = a.length, last = a[n - 1][4];   // 数组/末根收盘
          var i24 = Math.max(0, n - 25), i7 = Math.max(0, n - 169);   // 24h/7d 起点下标(NQ 每日约 23 小时)
          var b24 = a[i24][1], b7 = a[i7][1];          // 基准价(当根开盘)
          var hi = -1e18, lo = 1e18;                   // 24h 高低
          for (var i = i24; i < n; i++) { if (a[i][2] > hi) hi = a[i][2]; if (a[i][3] < lo) lo = a[i][3]; }   // 扫描
          self.sym.px = last;                          // 最新价
          self.sym.chg24 = b24 > 0 ? (last - b24) / b24 * 100 : 0;   // 24h 涨跌%
          self.sym.chg7 = b7 > 0 ? (last - b7) / b7 * 100 : 0;       // 7 日涨跌%
          self.sym.hi24 = hi; self.sym.lo24 = lo;      // 24h 极值
        }).catch(function () { });
      },
      loadSim() {                                      // 模拟交易状态
        var self = this;                               // 闭包
        return get("/nqsim").then(function (r) {       // 状态接口
          if (!r || !r.ok) return;                     // 失败
          self.sim = r;                                // 存状态
          if (self.sym.px <= 0) self.sym.px = r.px;    // 首帧补价
          if (self.ctrl) self.ctrl.setSignals(self.buildMarks(), [], self.panelLines(), 0);   // 刷新标记与均价线
        }).catch(function () { });
      },
      buildMarks() {                                   // 台账 → 图表标记
        var out = [], t = this.trades;                 // 输出/源
        for (var i = 0; i < t.length; i++) {           // 逐条
          var x = t[i];                                // 当前
          if (x.kind === "open" && x.et > 0) out.push({ kind: "buy", t: x.et });      // 开仓 → 买
          else if (x.kind === "add" && x.et > 0) out.push({ kind: "add", t: x.et });  // 加仓
          else if (x.kind === "close" && x.ct > 0) out.push({ kind: "close", t: x.ct });   // 平仓
        }
        return out;                                    // 返回
      },
      panelLines() {                                   // 均价/止盈线
        var p = this.pos;                              // 持仓
        if (!(p.qty > 0)) return { avg: 0, tp: 0, sl: 0 };   // 无仓不画
        return { avg: p.avg, tp: p.avg * (1 + this.tpP / 100), sl: 0 };   // 均价+止盈
      },
      loadBt() {                                       // AI 回测报告列表
        var self = this;                               // 闭包
        return get("/btlist", { n: 30 }).then(function (r) { if (r && r.ok) self.btList = r.list || []; }).catch(function () { });
      },
      loadGuard() {                                    // 守护状态
        var self = this;                               // 闭包
        return get("/guard").then(function (r) { if (r) self.guard = r; }).catch(function () { });
      },

      /* ── 交互 ── */
      pickTf(t) {                                      // 切周期
        if (this.tf === t) return;                     // 同周期不动作
        this.tf = t; this.histEnd = false;             // 更新状态
        var self = this;                               // 闭包
        this.loadKline().then(function () { if (self.ctrl) self.ctrl.applyPrecision(); });   // 重新加载并校正精度
      },
      onHover(row) { this.hover = row; },              // 悬停 OHLC
      onAtLatest(v) { this.atLatest = v; },            // 视口状态
      onNeedHistory() { this.loadHistory(); },         // 触发翻页
      pushCfg() {                                      // 下发图表配置并持久化
        try { localStorage.setItem(CFG_KEY, JSON.stringify(this.cfg)); } catch (e) { }   // 保存
        if (this.ctrl) this.ctrl.setCfg(this.cfg);    // 通知图表(运行时更新配置)
      },
      setCfgKey(k, v) { this.cfg[k] = v; this.pushCfg(); },   // 单键配置
      setCtype() { this.cfg.type = this.ctype; this.pushCfg(); },   // 图型下拉
      togglePane(k) {                                  // 副图开关
        var i = this.cfg.panes.indexOf(k);             // 当前位置
        if (i >= 0) this.cfg.panes.splice(i, 1); else this.cfg.panes.push(k);   // 反选
        this.pushCfg();                                // 下发
      },
      cycleMa() {                                      // MA 周期循环
        var sets = [[5, 10, 20, 60], [5, 20, 60], [10, 30, 60], [7, 25, 99], []];   // 预设
        var cur = this.cfg.ma.join("/"), idx = 0;      // 当前
        for (var i = 0; i < sets.length; i++) if (sets[i].join("/") === cur) idx = i;   // 找位
        this.cfg.ma = sets[(idx + 1) % sets.length]; this.pushCfg();   // 下一个
      },
      toggleBoll() { this.cfg.boll.on = !this.cfg.boll.on; this.pushCfg(); },   // BOLL 开关
      toggleMarks() { this.cfg.marks = !this.cfg.marks; this.pushCfg(); },      // 标记开关
      toggleAvg() { this.cfg.avgline = !this.cfg.avgline; this.pushCfg(); },    // 均价线
      setTool(t) { this.drawTool = t; this.cfg._tool = t; if (this.ctrl) this.ctrl.setTool(t); },   // 绘图工具
      setColor(c) { this.drawColor = c; if (this.ctrl) this.ctrl.setDrawColor(c); },   // 画笔色
      undoDraw() { if (this.ctrl) this.ctrl.undoDraw(); },         // 撤销
      clearDraw() { if (this.ctrl) this.ctrl.clearDraw(); },   // 清空
      fitAll() { if (this.ctrl) this.ctrl.fit(); },    // 适应宽度
      toLatest() { if (this.ctrl) this.ctrl.toLatest(); },   // 回到最新
      takeShot() {                                     // 截图(下载 png)
        if (!this.ctrl) return;                        // 未挂载
        var cv = this.ctrl.screenshot && this.ctrl.screenshot();   // 取得 canvas
        if (!cv) return;                               // 失败
        var a = document.createElement("a");           // 下载链接
        a.href = cv.toDataURL("image/png"); a.download = "nq_" + this.tf + ".png"; a.click();   // 触发下载
      },
      order(side) {                                    // 手动模拟买卖
        var self = this;                               // 闭包
        if (this.pending) return;                      // 防连点
        this.pending = true;                           // 上锁
        get("/nqsim", { op: "order", side: side, note: "网页手动" }).then(function (r) {   // 投单
          self.pending = false;                        // 解锁
          if (r && r.ok) { self.toast = "已排队: " + (side === "buy" ? "买入 2U" : side === "add" ? "加仓 1U" : "全部平仓") + " (15 秒内按最新 1m 收盘成交)"; self.loadSim(); }   // 提示
          else self.toast = "下单失败: " + ((r && r.error) || "未知错误");   // 失败提示
          setTimeout(function () { self.toast = ""; }, 6000);   // 6s 后清提示
        }).catch(function () { self.pending = false; self.toast = "下单请求异常"; setTimeout(function () { self.toast = ""; }, 6000); });   // 异常
      },
      /* ── 轮询 ── */
      tick() {                                         // 每秒: 时钟
        var d = new Date();                            // 当前
        this.clock = U.tsFull(d.getTime()).slice(11);  // HH:MM:SS
      },
      tickAll() {                                      // 主轮询(1s 心跳 + 分频)
        var s = Math.floor(Date.now() / 1000);         // 当前秒
        this.tick();                                   // 时钟
        if (!this.auto) return;                        // 自动刷新关闭
        if (document.hidden) return;                   // 页面隐藏暂停
        if (s % 5 === 0) this.loadTail();              // 5s 尾部
        if (s % 3 === 0) this.loadSim();               // 3s 模拟状态
        if (s % 10 === 0) this.loadGuard();            // 10s 守护
        if (s % 30 === 0) { this.loadKline(); this.loadUpstream(); }   // 30s 全量+摘要
        if (s % 60 === 0) this.loadBt();               // 60s 回测列表
      },
    },
    mounted() {                                        // 挂载
      var self = this;                                 // 闭包
      this.$nextTick(function () {                     // 等 DOM
        var el = document.getElementById("nqchart");   // 容器
        if (!el || !C) return;                         // 缺依赖
        self.ctrl = C.mount(el, {                      // 建图
          key: "NQ|" + self.tf,                        // 绘图持久化键(按周期区分)
          cfg: self.cfg,                               // 配置
          onHover: function (row) { self.onHover(row); },              // 悬停回调
          onAtLatest: function (v) { self.onAtLatest(v); },            // 视口回调
          onNeedHistory: function () { self.onNeedHistory(); },        // 翻页回调
        });
        self.loadKline();                              // 首屏K线
        self.loadSim();                                // 首屏模拟状态
        self.loadUpstream();                           // 首屏摘要
        self.loadBt();                                 // 首屏报告列表
        self.loadGuard();                              // 首屏守护
        self.tick();                                   // 时钟立即
      });
      setInterval(function () { self.tickAll(); }, 1000);   // 1s 心跳轮询
    },
  });
  app.mount("#app");                                   // 挂载到 #app
})();
