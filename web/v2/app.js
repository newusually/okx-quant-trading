/* ============================================================
 *  app.js — OKX 量化终端 v2 (Vue3 主应用 · 组合思路 + Lightweight Charts + Tailwind)
 *  文件职责: 整个面板的唯一前端入口应用 —— 状态管理、数据轮询、图表控制、
 *           设置抽屉、绘图工具、历史分页、信号/转折点/持仓/流水渲染全在此。
 *  功能清单:
 *    1) 状态: 当前合约 inst / 周期 tf / 可视根数 nvis; K线数据单一真源
 *       (rows 始终从 chart 控制器 getRows() 同步, 避免双份拷贝错位)
 *    2) 轮询体系: 20s 全量K线 pollKline + 3s 尾部轮询 pollKTail
 *       (/kline?fresh=1&limit=10 → 图面实时补新根 + 更新进行中根);
 *       1.5s 实时价 /ticker; 15s marks/sigs 信号轮询(原5分钟太慢已改);
 *       3s 持仓/实况 poll; 15s 流水; 10s 守护 —— 页面隐藏时全部暂停
 *    3) 新根触发: pollKTail 检测到新增K线根 → 立即 loadSignals 重取买卖信号
 *    4) 工具栏/设置抽屉: 图表类型/涨跌配色/网格/磁吸/对数轴/间距/留白,
 *       MA/EMA/BOLL/RSI/KDJ/副图 panes, cfg 全量持久化到 localStorage
 *       (key = okx.v2.cfg), 修改即时 pushCfg 下发图表并保存
 *    5) 指标开关 indOn (computed); pivClean 转折点去重(同一根K线只留一条
 *       + KE 同类分位 P0~P100); isGold/goldN 金▲判定(底部 KE ≥ 引擎 75 分位阈值 th)
 *    6) 工具栏按钮: 图表类型/指标开关/绘图工具(趋势线/水平线/矩形/斐波那契等)/
 *       撤销/清空/全屏(适应宽度); 图例文案见 index.html:
 *       "5m+3m 金▲共振开仓" "跌时 3m 金▲" "+2% 止盈"
 *    7) 历史分页: 拖到最左自动 loadHistory 补 600 根, keepHist 合并不冲掉旧数据
 *  数据全部来自 C++ 组件 apihub.exe (AJAX, 同源经 Apache 反代)
 * ============================================================ */
(function () {                                   // IIFE 立即执行: 隔离作用域, 不污染全局
  var A = window.OKXAPI, U = window.OKXU, C = window.OKXChart;   // 取全局三大模块: API 封装 / 工具函数 / 图表控制器

  var TFS = ["1m", "3m", "5m", "15m", "1h"];     // 支持的K线周期列表(顶栏/图头周期切换条共用)
  var IV = { "1m": 60, "3m": 180, "5m": 300, "15m": 900, "1h": 3600 };   // 各周期对应的秒数(用于 marksDays 换算天数)
  var NS = [150, 300, 500, 800, 1200, 1500];     // 初始可视根数下拉选项

  /* ── 图表默认配置(可存 localStorage) ── */
  var DEF_CFG = {                                // 图表默认配置对象(恢复默认 / 首次加载基准)
    type: "candles", updown: "cn", grid: true, magnet: false, log: false,   // 蜡烛图/红涨绿跌/网格开/磁吸关/对数轴关
    marks: true, pivots: true, avgline: true, tpline: true,   // 买卖信号/转折点/均价线/止盈线 默认全开
    ma: [5, 10, 20, 60], ema: [], boll: { on: false, n: 20, k: 2 },   // MA 默认 4 条, EMA 默认关, BOLL 默认关(20,2)
    panes: ["vol", "macd"], rsiN: 14, kdjN: [9, 3, 3],   // 副图默认 VOL+MACD, RSI 周期14, KDJ 参数 9,3,3
    barSpacing: 7, rightOffset: 8,               // K线间距 7px / 右侧留白 8 根
  };
  var CFG_KEY = "okx.v2.cfg";                    // localStorage 持久化键名(设置抽屉读写)
  var TYPES = [["candles", "蜡烛图"], ["bars", "美国线"], ["line", "折线"], ["area", "面积图"]];   // 图表类型选项[值, 中文名]
  var UPDOWN = [["cn", "红涨绿跌"], ["us", "绿涨红跌"]];   // 涨跌配色方案选项(中国市场惯例 红=涨)
  var PANE_DEF = [["vol", "VOL", "成交量"], ["macd", "MACD", "指数平滑异同"], ["rsi", "RSI", "相对强弱"], ["kdj", "KDJ", "随机指标"]];   // 副图指标定义[键, 缩写, 全称]
  var TOOLS = [                                  // 绘图工具列表[键, 名称, 按钮图标]
    ["cursor", "光标", "✛"], ["trend", "趋势线", "╱"], ["hline", "水平线", "─"],
    ["vline", "垂直线", "│"], ["ray", "射线", "➚"], ["rect", "矩形", "▭"], ["fib", "斐波那契", "≡"],
  ];
  var DRAW_COLORS = ["#f0b90b", "#ff4d4f", "#0ecb81", "#3b82f6", "#8b5cf6", "#e8eefb"];   // 画线颜色色板(金/红/绿/蓝/紫/白)

  /* ── 转折点净化 ───────────────────────────────────────────────
   * ① 同一根K线去重: 阈值 ZigZag 遇到"单根振幅 >= 阈值"的宽幅K线时, 会把同一根
   *    反复输出成顶/底交替(实测 ACH 5m 里 09-27 07:00 被重复输出 21 次), 图上就是
   *    一堆重叠圆点 —— 这是"看起来公式错了"的直观来源。
   * ② KE 分位: KE=½mv² 是绝对量(m=该段均量), 只在本合约内有意义, 跨合约不可比,
   *    故额外算"在本合约同类转折点里的分位"(P0~P100), 越接近 100 动能越强。 */
  function pivClean(arr) {                       // 转折点净化入口: 去重 + 排序 + 打分位
    if (!arr || !arr.length) return [];          // 空入空出
    var by = {}, out = [];                       // by: 时间戳→已收录点的索引表; out: 输出数组
    for (var i = 0; i < arr.length; i++) {       // 遍历服务端返回的原始转折点
      var p = arr[i], k = String(p.t), e = by[k];   // p=当前点, k=所属K线开仓时间字符串, e=同根已收录的点
      // 同一根K线只保留一条: 有真实动能(ke>0)的优先, 否则保留先到的
      // (宽幅K线会被 ZigZag 反复输出成 顶/底 交替, ACH 5m 实测同一根重复 21 次)
      if (!e) { by[k] = p; out.push(p); }        // 该K线首次出现 → 直接收录
      else if (!(e.ke > 0) && p.ke > 0) {        // 已收录的没有真实动能而新点有 → 用新点字段覆盖
        e.ke = p.ke; e.g = p.g; e.f = p.f; e.ang = p.ang; e.bars = p.bars;   // 覆盖动能/重力/分位/角度/根数等力学字段
        e.type = p.type; e.p = p.p; e.i = p.i;   // 覆盖顶底类型/价格/序号
      }
    }
    out.sort(function (a, b) { return a.t - b.t; });   // 按时间升序排列(绘图与表格展示需要)
    rank(out, true);                             // 给所有"底"算 KE 分位(升序百分比)
    rank(out, false);                            // 给所有"顶"算 KE 分位(同类内独立排名)
    return out;                                  // 返回净化后的转折点数组
  }
  function rank(ps, isBot) {                     // 分位计算: 对指定类型(底/顶)按 KE 排名打 P0~P100
    var ks = [];                                 // 收集该类型的所有有效 KE 值
    for (var i = 0; i < ps.length; i++) if ((ps[i].type === 0) === isBot && ps[i].ke > 0) ks.push(ps[i].ke);   // 只取同类且 ke>0 的
    ks.sort(function (a, b) { return a - b; });  // KE 升序排序(用于二分式累计计数)
    for (var j = 0; j < ps.length; j++) {        // 再次遍历每个点
      var p = ps[j];                             // 当前点
      if ((p.type === 0) !== isBot) continue;    // 类型不符(底/顶不匹配) → 跳过
      if (!(p.ke > 0)) { p.pctl = null; continue; }   // 无有效动能 → 分位置空(界面显示 —)
      var c = 0;                                 // 计数器: KE 小于等于当前值的个数
      while (c < ks.length && ks[c] <= p.ke) c++;   // 线性推进到第一个大于 p.ke 的位置
      p.pctl = Math.round(100 * c / ks.length);  // 分位 = 累计占比取整(0~100)
    }
  }

  function loadCfg() {                           // 从 localStorage 载入图表配置(与默认值合并)
    var c = JSON.parse(JSON.stringify(DEF_CFG)); // 深拷贝默认配置作为基底
    try {
      var raw = localStorage.getItem(CFG_KEY);   // 读取持久化的配置字符串
      if (raw) {                                 // 存在才解析合并
        var o = JSON.parse(raw);                 // 解析用户配置
        for (var k in o) if (o.hasOwnProperty(k) && c[k] !== undefined) c[k] = o[k];   // 只合并默认里已有的键(防脏数据注入)
      }
    } catch (e) { }                              // JSON 损坏等异常 → 静默用默认配置
    return c;                                    // 返回合并后的配置
  }

  var PARAM_ROWS = [                             // 风控参数展示行定义[字段键, 中文标签, 单位]
    ["leverage", "杠杆", "x"],                   // 杠杆倍数
    ["unit_usd", "单笔", "U"],                   // 单笔开仓金额
    ["tp_pct", "止盈", "%"],                     // 止盈百分比
    ["max_positions", "最大持仓", "个"],         // 同时持有的最大合约数
    ["max_buys_per_hour", "每小时买入", "次"],   // 每小时新开仓限速
    ["max_new_per_scan", "每轮新开", "个"],      // 单轮扫描新开仓上限
    ["add_usd_step", "加仓步长", "U"],           // 每次加仓金额
    ["add_cooldown_min", "加仓冷却", "分"],      // 两次加仓最小间隔(分钟)
    ["add_max_per_hour", "每小时加仓", "次"],    // 每小时加仓限速
    ["grid_max_levels", "最大档位", "档"],       // 网格最大档位数
    ["max_units", "最大张数", "张"],             // 单合约最大张数
    ["trade_bar", "交易周期", ""],               // 引擎实际执行交易的K线周期
  ];

  var app = Vue.createApp({                      // 创建 Vue3 应用实例(Options 风格写法)
    data: function () {                          // 根组件响应式数据定义
      return {
        // 配置常量(模板用)
        TFS: TFS, NS: NS, TYPES: TYPES, UPDOWN: UPDOWN, PANE_DEF: PANE_DEF,   // 把常量挂到 data 供模板 v-for 使用
        TOOLS: TOOLS, DRAW_COLORS: DRAW_COLORS,  // 绘图工具与颜色色板同样暴露给模板
        // 选择状态
        inst: "AUCTION-USDT-SWAP", tf: "5m", nvis: 300,   // 当前合约/周期/初始可视根数(hash 或 boot 会覆盖)
        cfg: loadCfg(), tool: "cursor", drawColor: "#f0b90b", setOpen: false,   // 图表配置(localStorage)/当前绘图工具/画线颜色/设置抽屉开关
        auto: true, q: "", dropOpen: false,      // 自动刷新开关/合约搜索关键字/合约下拉展开状态
        // 合约选择走顶栏下拉(dropGroups: 最近交易 + 全部合约)
        // 实时价(1.5s AJAX /ticker): px=最新价 ref=参考价(上一根收盘) dir=1涨/-1跌 n=变动序号(驱动闪光)
        // kt=K线增量刷新成功次数 app=累计新增周期根数(自检: 证明前端轮询链路在跑)
        live: { px: 0, ref: 0, chg: 0, amt: "", dir: 0, n: 0, kt: 0, app: 0 },   // 实时价状态聚合对象(头部与图例共用)
        // 历史分页: hloading=正在拉更早; hcount=已额外加载根数; hend=已到最早
        hloading: false, hcount: 0, hend: false,   // 历史分页三状态(图头 badge 与"已到最早"提示用)
        // 数据
        rows: [], marks: [], pivots: [], th: 0,  // K线(单一真源, 由 ctrl.getRows() 同步)/买卖信号标记/算法转折点/金▲KE阈值
        trades: [], ls: null, grid: null, guard: null,   // 交易流水/本合约实况统计/网格监控(持仓+参数)/守护状态
        syms: [], recent: [],                    // 全部合约列表/最近交易合约列表(下拉分组用)
        // 状态
        clock: U.clock(), busy: false, kloading: false, kerr: "",   // 时钟字符串/手动刷新中/K线加载中/K线错误文案
        followOff: false, lagMin: 0, hover: null,   // 是否已暂停跟随最新/数据滞后分钟数/悬停OHLC浮窗数据
        btList: [],                              // AI模拟回测报告列表(60s轮询 /btlist, 齿轮可展开滚动)
        btExpand: false,                         // 回测面板展开态(默认3行, 点齿轮展开滚动看全部)
      };
    },

    computed: {                                  // 计算属性区(模板大量绑定于此)
      /* ── 当前合约行情(取自K线最后两根) ── */
      sym: function () {                         // 当前合约摘要: 最新价/涨跌幅/短名
        var r = this.rows;                       // 引用K线数组
        if (!r || !r.length) return { px: 0, chg: 0, short: U.short(this.inst) };   // 无数据 → 返回占位对象
        var last = r[r.length - 1], prev = r.length > 1 ? r[r.length - 2] : last;   // 最后一根与倒数第二根(算涨跌用)
        var chg = prev[4] ? ((last[4] - prev[4]) / prev[4]) * 100 : 0;   // 涨跌幅% = (今收-昨收)/昨收*100([4]=收盘价)
        return { px: last[4], chg: chg, short: U.short(this.inst) };   // 返回最新价/涨跌幅/合约短名
      },

      /* ── 区间涨跌(只统计最近 nvis 根) ── */
      range: function () {                       // 可视区间的涨跌幅与高低点
        var all = this.rows;                     // 全部K线
        if (all.length < 2) return { pct: 0, hi: 0, lo: 0 };   // 不足 2 根 → 占位
        var n = Math.min(all.length, this.nvis); // 取最近 nvis 根(与图表可视窗口一致)
        var r = all.slice(all.length - n);       // 截出统计区间
        var hi = -Infinity, lo = Infinity;       // 高/低点初始化为极值
        for (var i = 0; i < r.length; i++) { if (r[i][2] > hi) hi = r[i][2]; if (r[i][3] < lo) lo = r[i][3]; }   // 扫描最高价[2]/最低价[3]
        var pct = r[0][4] ? ((r[r.length - 1][4] - r[0][4]) / r[0][4]) * 100 : 0;   // 区间涨跌% = (末收-首收)/首收
        return { pct: pct, hi: hi, lo: lo };     // 返回涨跌幅与高低点
      },

      /* ── 图表高度随副图数量自适应 ── */
      chartH: function () {                      // 主图容器高度(随副图个数撑高)
        var n = (this.cfg.panes || []).length;   // 当前开启的副图数量
        return 430 + n * 76;                     // 主图基准 430px, 每个副图加 76px
      },

      /* ── 指标/图层开关状态 ── */
      indOn: function () {                       // 各指标开关布尔表(工具栏 chip 高亮用)
        var c = this.cfg, p = c.panes || [];     // c=配置, p=副图列表
        return {
          ma: !!(c.ma && c.ma.length), ema: !!(c.ema && c.ema.length),   // MA/EMA: 数组非空即开
          boll: !!(c.boll && c.boll.on),         // BOLL: 看 on 开关
          vol: p.indexOf("vol") >= 0, macd: p.indexOf("macd") >= 0,   // VOL/MACD: 是否在副图列表里
          rsi: p.indexOf("rsi") >= 0, kdj: p.indexOf("kdj") >= 0,   // RSI/KDJ: 同上
        };
      },

      /* ── 核心指标条 ── */
      metrics: function () {                     // 顶栏指标条数据(9 格: 盈亏/胜率/持仓/资金费/区间涨跌)
        var d = this.ls, o = [];                 // d=本合约实况数据, o=输出行数组
        function push(k, v, c) { o.push({ k: k, v: v, c: c || "text-txt-1" }); }   // 行构造助手: [标题, 值, 颜色类]
        if (!d) {                                // 数据未到 → 填 8 格占位
          for (var i = 0; i < 8; i++) push("—", "--");
          return o;
        }
        var total = d.net_pnl + d.upl;           // 总盈亏 = 已实现 + 浮动
        push("总盈亏(含浮动)", (total >= 0 ? "+" : "") + total.toFixed(2) + " U", total >= 0 ? "text-up" : "text-dn");   // 总盈亏行(红盈绿亏)
        push("已实现", (d.net_pnl >= 0 ? "+" : "") + d.net_pnl.toFixed(2) + " U", d.net_pnl >= 0 ? "text-up" : "text-dn");   // 已实现盈亏行
        push("浮动", (d.upl >= 0 ? "+" : "") + d.upl.toFixed(2) + " U", d.upl >= 0 ? "text-up" : "text-dn");   // 未实现浮动盈亏行
        var wr = d.closed ? (d.wins / d.closed) * 100 : 0;   // 胜率% = 赢的笔数/已平仓笔数
        push("胜率", wr.toFixed(1) + "% (" + d.wins + "/" + d.closed + ")", wr >= 60 ? "text-up" : "text-txt-1");   // 胜率行(≥60% 标红)
        push("交易笔数", String(d.trades || 0));   // 总交易笔数行
        push("持仓 / 保证金", d.open_pos + " 个 · " + d.pos_margin.toFixed(2) + "U", d.open_pos > 0 ? "text-gold" : "text-txt-3");   // 持仓数/占用保证金行
        push("手续费 / 资金费", d.fee_sum.toFixed(3) + " / " + d.fund_sum.toFixed(3), "text-txt-2");   // 累计手续费/资金费行
        var fr = d.funding && d.funding.rate != null ? d.funding.rate * 100 : null;   // 当前资金费率(%)
        push("资金费率", fr == null ? "--" : fr.toFixed(4) + "%", fr == null ? "text-txt-3" : (fr >= 0 ? "text-up" : "text-dn"));   // 资金费率行(正=收/负=付)
        push("区间涨跌(" + Math.min(this.rows.length, this.nvis) + "根)", (this.range.pct >= 0 ? "+" : "") + this.range.pct.toFixed(2) + "%", this.range.pct >= 0 ? "text-up" : "text-dn");   // 可视区间涨跌行
        return o;                                // 返回指标条行数组
      },

      /* ── 合约下拉分组 ── */
      dropGroups: function () {                  // 合约下拉的分组(最近交易优先 + 全部合约)
        var q = this.q.trim().toUpperCase();     // 搜索关键字(统一大写匹配)
        function m(s) { return !q || s.toUpperCase().indexOf(q) >= 0; }   // 匹配函数: 空关键字全过, 否则子串包含
        var g = [];                              // 输出分组数组
        var rec = this.recent.filter(m);         // 最近交易里先过滤
        if (rec.length) g.push({ name: "最近交易", list: rec.slice(0, 24) });   // 有就放第一组(最多24个)
        var rest = this.syms.filter(function (s) { return m(s) && rec.indexOf(s) < 0; });   // 全部合约过滤且排除已入"最近"的
        if (rest.length) g.push({ name: "全部合约 (" + rest.length + ")", list: rest.slice(0, 150) });   // 第二组(展示最多150个)
        return g;                                // 返回分组
      },

      /* ── 实时价闪烁样式(涨红/跌绿, 变动瞬间闪光) ── */
      liveFlash: function () {                   // 实时价数字的动态样式(上涨闪红/下跌闪绿)
        if (this.live.dir > 0) return "text-up animate-flashup";   // 本次较上次上涨 → 红色闪光
        if (this.live.dir < 0) return "text-dn animate-flashdn";   // 下跌 → 绿色闪光
        return this.live.chg >= 0 ? "text-up" : "text-dn";   // 无变动 → 按相对参考价的涨跌静态着色
      },
      liveBadge: function () {                   // 涨跌幅徽章配色(红涨绿跌底色)
        return this.live.chg >= 0 ? "border-up/40 bg-up/10 text-up" : "border-dn/40 bg-dn/10 text-dn";   // 涨/跌两套边框+底色+文字
      },

      /* ── 信号统计 + 表格(倒序) ── */
      sigN: function () {                        // 信号分类计数(买/加/平)
        var n = { buy: 0, add: 0, close: 0 };    // 三类计数器
        for (var i = 0; i < this.marks.length; i++) if (n[this.marks[i].kind] != null) n[this.marks[i].kind]++;   // 逐条累加(未知 kind 忽略)
        return n;                                // 返回计数对象
      },
      markRows: function () { return this.marks.slice().reverse().slice(0, 60); },   // 信号表格行: 倒序(最新在前)最多60条
      pivRows: function () { return this.pivots.slice().reverse().slice(0, 80); },   // 转折点表格行: 倒序最多80条
      marksDays: function () {                   // 信号查询回看天数(随根数与周期换算, 限 1~90 天)
        var n = Math.max(this.nvis, Math.min(this.rows.length, 4000));   // 以根数为准(上限4000根)
        var d = Math.ceil((n * IV[this.tf]) / 86400) + 1;   // 根数×周期秒数÷86400 → 天数向上取整再+1
        return Math.max(1, Math.min(90, d));     // 夹在 1~90 天之间
      },

      /* ── 网格/持仓 ── */
      gridRows: function () { return (this.grid && this.grid.rows) || []; },   // 持仓行列表(gridmon.data.rows)
      gridLogs: function () { return ((this.grid && this.grid.logs) || []).slice(0, 40); },   // 加仓触发日志(最多40条)

      /* ── 守护 ── */
      guardTargets: function () { return (this.guard && this.guard.targets) || []; },   // 守护目标进程列表
      guardOk: function () { var n = 0, t = this.guardTargets; for (var i = 0; i < t.length; i++) if (t[i].ok) n++; return n; },   // 存活目标计数
      /* 真·金▲计数: 底部 + 下跌腿动能 KE 达该合约底部KE的75分位阈值(与引擎同口径) */
      goldN: function () {                       // 金▲数量: 满足 isGold 条件的转折点个数
        var n = 0, t = this.th;                  // n=计数器, t=引擎下发的 KE 阈值
        for (var i = 0; i < this.pivots.length; i++) {   // 遍历所有转折点
          var p = this.pivots[i];                // 当前点
          if (p && p.type === 0 && p.ke > 0 && t > 0 && p.ke >= t) n++;   // 底部 + 有动能 + 阈值有效 + KE≥阈值 → 计入
        }
        return n;                                // 返回金▲总数
      },
      guardN: function () { return this.guardTargets.length; },   // 守护目标总数
      guardBad: function () { return this.guardN > 0 && this.guardOk === this.guardN; },   // 守护"全活"标记(全部ok时顶部灯变绿闪烁)

      /* ── 风控参数 ── */
      params: function () {                      // 风控铁律卡片数据(按 PARAM_ROWS 顺序格式化)
        var p = this.grid && this.grid.params;   // 取引擎参数对象
        if (!p) return [];                       // 无数据 → 空数组
        var out = [];                            // 输出行
        for (var i = 0; i < PARAM_ROWS.length; i++) {   // 按预定义顺序遍历
          var key = PARAM_ROWS[i][0], lbl = PARAM_ROWS[i][1], unit = PARAM_ROWS[i][2];   // 键/标签/单位
          var v = p[key];                        // 原始值
          if (v == null) continue;               // 空值跳过
          if (typeof v === "number") v = Math.abs(v) < 1 && v !== 0 ? String(+v.toFixed(4)) : String(v);   // 小于1的非零数保留4位小数去尾零
          out.push({ k: lbl, v: v + (unit ? " " + unit : "") });   // 组装[标签, 值+单位]
        }
        return out;                              // 返回格式化后的参数行
      },

      /* ── 当前合约的持仓线(均价/止盈) ── */
      curPos: function () {                      // 当前inst在持仓表中的行(画均价线/止盈线用)
        var r = this.gridRows;                   // 全部持仓行
        for (var i = 0; i < r.length; i++) if (r[i].inst_id === this.inst) return r[i];   // 按合约ID匹配
        return null;                             // 当前合约无持仓
      },
    },

    methods: {                                   // 方法区(事件处理/加载/轮询/样式助手)
      /* 工具透传(模板使用) */
      num: U.num, fmtPx: U.fmtPx, short: U.short,   // 透传全局工具: 数值化/价格格式化/合约短名
      ts: function (ms) { return U.ts(ms, true); },   // 时间戳格式化(含秒级, 北京时间)

      /* ── 信号样式 ── */
      /* 类名助手(HTML 绑定里**禁止出现 ">" 字符**: 大纲/编辑器注入属性时会把它当标签结束
       * 从而截断绑定 → Vue 报 SyntaxError 整页白屏。故一律走方法调用) */
      sgnCls: function (v) { return v >= 0 ? "text-up" : "text-dn"; },   // 按正负给涨/跌色类(模板里代替 v>=0 比较)
      dirCls: function (d) { return d > 0 ? "text-up animate-arrowpop" : "text-ink-500"; },   // 上箭头样式(上涨时弹跳)
      dnCls: function (d) { return d < 0 ? "text-dn animate-arrowpop" : "text-ink-500"; },   // 下箭头样式(下跌时弹跳)
      profitCls: function (p) { return p == null ? "text-txt-4" : (p >= 0 ? "text-up" : "text-dn"); },   // 盈亏着色(空=灰/正=红/负=绿)
      pctlCls: function (v) { return v == null ? "text-txt-4" : (v >= 75 ? "text-gold" : (v >= 50 ? "text-txt-2" : "text-txt-4")); },   // 动能分位着色(≥75金色/≥50中灰/其余浅灰)
      /* 真·金▲: 底部 + 下跌腿动能 KE 达该合约底部KE的75分位阈值(与引擎 gold_signal 同口径) */
      isGold: function (p) { return !!p && p.type === 0 && p.ke > 0 && this.th > 0 && p.ke >= this.th; },   // 金▲判定: 底部+KE≥引擎阈值(表格"金▲"徽章)
      kindTxt: function (k) { return k === "buy" ? "🚀 买入" : k === "add" ? "▲ 加仓" : "🍃 平仓"; },   // 信号类型文案(带图标)
      kindCls: function (k) {                    // 信号类型徽章配色(买红/加金/平灰)
        return k === "buy" ? "border-up/40 bg-up/10 text-up"
          : k === "add" ? "border-gold/40 bg-gold/10 text-gold"   // 加仓=金色
            : "border-ink-500 bg-ink-750 text-txt-2";   // 其余(平仓)=灰
      },
      actTxt: function (a) { return a === "buy" ? "买入" : a === "add" ? "加仓" : a === "close" ? "平仓" : a; },   // 流水动作文案
      actCls: function (a) {                     // 流水动作徽章配色(买红/加金/平绿)
        return a === "buy" ? "border-up/40 bg-up/10 text-up"
          : a === "add" ? "border-gold/40 bg-gold/10 text-gold"   // 加仓=金
            : "border-dn/40 bg-dn/10 text-dn";   // 平仓=绿
      },
      pnlTxt: function (t) {                     // 流水净盈亏列着色
        if (t.profit == null || t.profit === "") return "text-txt-4";   // 无盈亏(未平仓) → 灰
        return U.num(t.profit) >= 0 ? "text-up" : "text-dn";   // 有盈亏 → 红盈绿亏
      },

      /* ── 止盈进度 ── */
      tpPct: function (r) {                      // 止盈进度条百分比(现价在均价→止盈区间里的位置)
        var last = U.num(r.last_px), avg = U.num(r.avg_px), tp = U.num(r.tp_px);   // 现价/持仓均价/止盈价
        if (!(avg > 0) || !(tp > avg)) return 0;   // 均价或止盈价非法 → 0
        var p = ((last - avg) / (tp - avg)) * 100;   // 进度% = (现价-均价)/(止盈-均价)
        return Math.max(0, Math.min(100, p));    // 夹在 0~100
      },
      tpDist: function (r) {                     // 距止盈还差的百分比
        var last = U.num(r.last_px), tp = U.num(r.tp_px);   // 现价/止盈价
        if (!(last > 0) || !(tp > 0)) return 0;  // 非法 → 0
        return ((tp - last) / last) * 100;       // 还需上涨的百分比
      },
      /* ── 选择合约/周期 ── */
      pick: function (s) {                       // 选择合约(下拉点击/卡片点击/流水点击共用)
        if (!s) return;                          // 空参数忽略
        this.inst = s; this.dropOpen = false; this.q = "";   // 切合约/收下拉/清搜索词
        this.pivots = []; this.marks = []; this.th = 0;   // 清空旧合约的信号数据(防串台)
        this.resetLive();                        // 重置实时价状态
        if (this.ctrl) { this.ctrl.setKey(s, this.tf); this.ctrl.histReset(); }   // 图表切key并清历史分页缓存
        this.hcount = 0; this.hend = false;      // 重置历史分页状态
        location.hash = "#/" + s + "/" + this.tf;   // 更新 hash 路由(#/合约/周期, 刷新可恢复)
        this.loadKline(); this.loadSignals(); this.loadOne();   // 三路立即加载: K线/信号/实况
      },
      setTf: function (t) {                      // 切换K线周期
        if (this.tf === t) return;               // 同周期点击忽略
        this.tf = t;                             // 更新周期
        this.resetLive();                        // 重置实时价
        if (this.ctrl) { this.ctrl.setKey(this.inst, t); this.ctrl.histReset(); }   // 图表切key(绘图按合约|周期记忆)并清历史
        this.hcount = 0; this.hend = false;      // 重置分页状态
        location.hash = "#/" + this.inst + "/" + t;   // 同步 hash 路由
        this.loadKline(); this.loadSignals();    // 重拉K线与信号
      },
      fitChart: function () { if (this.ctrl) { this.ctrl.fit(); this.followOff = false; } },   // 适应宽度按钮: 图表全宽显示并恢复跟随
      toLatest: function () { if (this.ctrl) { this.ctrl.toLatest(); this.followOff = false; } },   // 回到最新按钮: 滚到最右并恢复跟随

      /* ══════ 图表配置(指标/样式/绘图) ══════ */
      saveCfg: function () {                     // 把当前 cfg 持久化到 localStorage
        try { localStorage.setItem(CFG_KEY, JSON.stringify(this.cfg)); } catch (e) { }   // 写失败(隐私模式等)静默
      },
      pushCfg: function () {                     // 配置变更后的统一出口: 下发图表 + 持久化
        if (this.ctrl) this.ctrl.setCfg(this.cfg);   // 先推给图表控制器重算指标
        this.saveCfg();                          // 再落 localStorage
        this.followOff = false;                  // 重算后恢复跟随最新
      },
      setCfgKey: function (k, v) {               // 设置单个配置键(模板各类控件共用)
        var o = Object.assign({}, this.cfg);     // 浅拷贝保证 Vue 响应式触发
        o[k] = v;                                // 更新该键
        this.cfg = o;                            // 回写
        this.pushCfg();                          // 下发+保存
      },
      toggleMA: function () { this.setCfgKey("ma", (this.cfg.ma && this.cfg.ma.length) ? [] : [5, 10, 20, 60]); },   // MA 开关: 开=默认4条/关=空数组
      toggleEMA: function () { this.setCfgKey("ema", (this.cfg.ema && this.cfg.ema.length) ? [] : [7, 25, 99]); },   // EMA 开关: 开=默认 7/25/99
      toggleBOLL: function () {                  // BOLL 开关(只翻转 on)
        var b = Object.assign({}, this.cfg.boll);   // 拷贝 boll 配置
        b.on = !b.on;                            // 翻转
        this.setCfgKey("boll", b);               // 回写下发
      },
      togglePane: function (n) {                 // 副图指标开关(最多同时 3 个)
        var p = (this.cfg.panes || []).slice();  // 拷贝当前副图列表
        var i = p.indexOf(n);                    // 是否已开
        if (i >= 0) p.splice(i, 1);              // 已开 → 移除
        else { if (p.length >= 3) return; p.push(n); }   // 未开 → 加入(满3个则忽略)
        this.setCfgKey("panes", p);              // 回写下发
      },
      setMaStr: function (e) {                   // MA 周期输入框解析("5,10" → [5,10])
        var a = String(e.target.value || "").split(/[,\s]+/).map(function (x) { return parseInt(x, 10); })   // 按逗号/空白切分转整数
          .filter(function (x) { return x > 1 && x < 500; });   // 过滤非法周期(2~499)
        this.setCfgKey("ma", a.slice(0, 6));     // 最多 6 条
      },
      setEmaStr: function (e) {                  // EMA 周期输入框解析(同 MA)
        var a = String(e.target.value || "").split(/[,\s]+/).map(function (x) { return parseInt(x, 10); })   // 切分转整数
          .filter(function (x) { return x > 1 && x < 500; });   // 过滤非法值
        this.setCfgKey("ema", a.slice(0, 6));    // 最多 6 条
      },
      setBollN: function (e) {                   // BOLL 周期 N 输入(夹 2~200, 默认20)
        var b = Object.assign({}, this.cfg.boll); b.n = Math.max(2, Math.min(200, parseInt(e.target.value, 10) || 20));   // 解析并夹取范围
        this.setCfgKey("boll", b);               // 回写下发
      },
      setBollK: function (e) {                   // BOLL 倍数 K 输入(夹 0.5~5, 默认2)
        var b = Object.assign({}, this.cfg.boll); b.k = Math.max(0.5, Math.min(5, parseFloat(e.target.value) || 2));   // 解析并夹取范围
        this.setCfgKey("boll", b);               // 回写下发
      },
      setRsiN: function (e) { this.setCfgKey("rsiN", Math.max(2, Math.min(100, parseInt(e.target.value, 10) || 14))); },   // RSI 周期输入(夹 2~100, 默认14)
      setKdjStr: function (e) {                  // KDJ 参数输入("9,3,3")
        var a = String(e.target.value || "").split(/[,\s]+/).map(function (x) { return parseInt(x, 10); })   // 切分转整数
          .filter(function (x) { return x > 0 && x < 100; });   // 过滤非法值
        if (a.length === 3) this.setCfgKey("kdjN", a);   // 必须恰好 3 个参数才生效
      },
      resetCfg: function () {                    // 恢复默认配置按钮
        this.cfg = JSON.parse(JSON.stringify(DEF_CFG));   // 深拷贝默认配置
        if (this.ctrl) this.ctrl.setKey(this.inst, this.tf);   // 图表重置key(内部重算全部指标)
        this.pushCfg();                          // 下发+保存
      },

      /* ══════ 绘图工具 ══════ */
      setTool: function (t) {                    // 选择绘图工具(工具栏/设置抽屉共用)
        this.tool = t;                           // 记录当前工具(高亮用)
        if (this.ctrl) this.ctrl.setTool(t);     // 下发给图表控制器
      },
      setDrawColor: function (c) {               // 选择画线颜色
        this.drawColor = c;                      // 记录颜色(色板高亮)
        if (this.ctrl) this.ctrl.setDrawColor(c);   // 下发
      },
      clearDraw: function () { if (this.ctrl) this.ctrl.clearDraw(); },   // 清空本图(合约+周期)的全部画线
      undoDraw: function () { if (this.ctrl) this.ctrl.undoDraw(); },   // 撤销最后一笔画线

      /* ══════ 历史分页: 拖到最左自动补 600 根 ══════ */
      loadHistory: function (oldestSec) {        // 图表拖到最左时回调: 按最旧时间戳向前拉600根
        if (this.hloading || this.hend) return;  // 正在拉或已到最早 → 忽略
        var self = this, inst = this.inst, tf = this.tf;   // 快照当前合约/周期(异步回来校验防串台)
        this.hloading = true;                    // 置加载中(顶部显示"正在加载更早的K线")
        var before = Math.round(oldestSec) * 1000;   // 最旧一根的秒 → 毫秒(before 参数)
        return A.kline(inst, tf, 600, { before: before }).then(function (j) {   // 请求 before 之前的 600 根
          self.hloading = false;                 // 结束加载态
          if (self.inst !== inst || self.tf !== tf) return;   // 期间用户切了合约/周期 → 丢弃
          if (!j || !j.ok || !j.rows || !j.rows.length) {   // 无数据 → 视为已到最早
            self.hend = true;                    // 置结束标记
            if (self.ctrl) self.ctrl.histDone(true);   // 通知图表停止再触发
            return;
          }
          var got = j.rows.length;               // 本轮实际拿到的根数
          var add = self.ctrl ? self.ctrl.prepend(j.rows) : 0;   // 前插进图表(内部去重), 返回真实新增根数
          self.hcount += add;                    // 累计已额外加载根数(图头 +N 徽章)
          self.rows = self.ctrl ? self.ctrl.getRows() : self.rows;   // 同步单一真源
          var over = (got < 600 || add === 0);   // 不足600根或没有真实新增 → 判定已到最早
          if (self.ctrl) self.ctrl.histDone(over);   // 通知图表
          if (over) self.hend = true;            // 结束标记
          if (add) self.loadSignals();           // 有新增 → 信号范围扩大, 立即重取
        }, function () {                         // 请求失败分支
          self.hloading = false;                 // 结束加载态(下次滚到最左可重试)
          if (self.ctrl) self.ctrl.histDone(false);   // 通知图表恢复触发能力
        });
      },

      /* ── 实时价: AJAX /ticker → 头部数字 + 图上末根K线实时跳动 ── */
      loadTick: function () {                    // 拉 1.5s 一次的最新成交价
        var self = this, inst = this.inst;       // 快照合约(异步校验)
        return A.ticker(inst).then(function (d) {   // 请求 /ticker
          if (self.inst !== inst) return;        // 合约已切换 → 丢弃
          if (!d || !d.ok || !(U.num(d.price) > 0)) return;   // 数据非法 → 忽略
          self.pushTick(U.num(d.price), d.ts);   // 合法 → 推进实时价状态
        }, function () { });                     // 失败静默(下轮再来)
      },
      pushTick: function (px, tsSec) {           // 把新价写进 live 状态并同步图表末根
        if (!(px > 0)) return;                   // 非法价忽略
        var rows = this.rows;                    // 当前K线
        if (!rows.length) return;                // 无K线则无从算参考价
        var prev = this.live.px;                 // 上一次价格(判断涨跌方向)
        var ref = rows.length > 1 ? U.num(rows[rows.length - 2][4]) : U.num(rows[rows.length - 1][1]);   // 参考价=上一根收盘(仅1根时用其开盘)
        if (!(ref > 0)) ref = px;                // 参考价非法 → 用现价兜底
        var p = U.precOf(px), amt = px - ref;    // 价格精度/价差
        this.live = {                            // 整体替换 live 对象(触发响应式)
          px: px, ref: ref,                      // 最新价/参考价
          chg: ref > 0 ? ((px - ref) / ref) * 100 : 0,   // 相对参考价的涨跌%
          amt: (amt >= 0 ? "+" : "") + amt.toFixed(p),   // 带符号价差文本
          dir: prev > 0 ? (px > prev ? 1 : (px < prev ? -1 : this.live.dir)) : 0,   // 方向: 涨1/跌-1/持平沿用
          n: (prev > 0 && px !== prev) ? this.live.n + 1 : this.live.n,   // 变动次数(驱动 :key 重新闪光)
          kt: this.live.kt, app: this.live.app,  // K线自检计数沿用
        };
        if (this.ctrl) this.ctrl.updateLast(px, tsSec ? tsSec * 1000 : 0);   // 同步图表末根收盘价(进行中根实时跳动)
      },
      pollTick: function () {                    // 实时价轮询入口(1.5s)
        if (!this.auto || document.visibilityState === "hidden") return;   // 已暂停或页面隐藏 → 跳过
        this.loadTick();                         // 拉一次
      },
      resetLive: function () {                   // 重置实时价状态(切合约/周期时)
        this.live = { px: 0, ref: 0, chg: 0, amt: "", dir: 0, n: 0, kt: 0, app: 0 };   // 全部归零
      },

      /* ── 数据加载 ── */
      /* keepHist=true 时只做增量合并(保留已加载的更早K线) */
      loadKline: function (keepHist) {           // 全量加载K线(20s轮询与切合约共用)
        var self = this;                        // 闭包引用
        this.kloading = true; this.kerr = "";   // 置加载态并清错误
        // fresh=100: 服务端先向 OKX 拉最近100根(含正在形成的那根)写库再返回 → 首屏即最新
        return A.kline(this.inst, this.tf, this.nvis, { fresh: 100 }).then(function (j) {   // 请求 nvis 根(强制新鲜)
          self.kloading = false;                 // 结束加载态
          if (!j || !j.ok) { self.kerr = (j && j.err) || "K线接口异常"; return; }   // 失败 → 记录错误文案(图中央红字)
          var rows = j.rows || [];               // K线行数组
          if (j.now && j.last) self.lagMin = Math.max(0, Math.round((j.now / 1000 - j.last) / 60));   // 计算数据滞后分钟数(顶栏显示)
          if (self.ctrl) {                       // 图表控制器存在(正常路径)
            self.ctrl.setData({ rows: rows }, { merge: !!keepHist });   // 写入数据(merge=轮询模式保留历史分页结果)
            self.ctrl.applyPrecision();          // 按价格量级重设小数位
            self.rows = self.ctrl.getRows();     // ★单一真源: rows 始终取自控制器
          } else {
            self.rows = rows;                    // 无控制器兜底(理论上不发生)
          }
          if (!keepHist) {                       // 全量模式(非轮询)才重置分页
            self.hcount = 0; self.hend = false;  // 清分页计数
            if (self.ctrl) self.ctrl.histReset();   // 通知图表重置历史状态
          }
          // K线到位后用收盘价初始化/校正实时价基准(参考价=上一根收盘)
          if (self.rows.length) self.pushTick(U.num(self.rows[self.rows.length - 1][4]));   // 用最新收盘价刷新 live
          self.syncLines();                      // 同步持仓均价/止盈线
        }, function (e) { self.kloading = false; self.kerr = "K线请求失败: " + e.message; });   // 网络异常分支
      },

      loadSignals: function () {                 // 加载买卖信号 + 算法转折点(15s轮询/新根/切合约共用)
        var self = this;                        // 闭包引用
        var days = this.marksDays;              // 信号回看天数
        var lim = Math.max(this.nvis, Math.min(this.rows.length, 2000));   // 转折点查询根数(夹2000)
        return Promise.all([                    // 两个请求并行
          A.sigs(this.inst, this.tf, lim).catch(function () { return null; }),   // /sigs: 转折点+KE阈值(失败返回null)
          A.marks(this.inst, days).catch(function () { return null; }),   // /marks: 买卖信号标记(失败返回null)
        ]).then(function (r) {
          var sg = r[0], mk = r[1];              // 解构两个结果
          self.th = (sg && sg.th) || 0;          // 金▲KE阈值(引擎75分位)
          self.pivots = (sg && sg.ok && sg.data) ? pivClean(sg.data) : [];   // 转折点先净化再入库
          self.marks = (mk && mk.ok && mk.data) ? mk.data : [];   // 信号标记直接入库
          if (self.ctrl) {                       // 下发给图表(画箭头/圆点/持仓线)
            self.ctrl.setSignals(self.marks, self.pivots, self.lineSpec(), self.th);
          }
        });
      },

      lineSpec: function () {                    // 当前合约持仓线规格(均价/止盈价)
        var p = this.curPos;                     // 当前持仓行
        if (!p) return null;                     // 无持仓 → null(图表移除线)
        return { avg: U.num(p.avg_px), tp: U.num(p.tp_px) };   // 返回均价与止盈价
      },
      syncLines: function () {                   // 只同步持仓线(不动信号)
        if (this.ctrl) this.ctrl.setSignals(undefined, undefined, this.lineSpec());   // 前两参 undefined=保持
      },

      loadOne: function () {                     // 加载本合约实况统计(指标条数据源)
        var self = this;                        // 闭包引用
        return A.livestats(this.inst).then(function (j) { if (j && j.ok) self.ls = j.data; }, function () { });   // 成功才更新, 失败静默
      },
      loadTrades: function () {                  // 加载全局交易流水
        var self = this;                        // 闭包引用
        return A.trades().then(function (j) { if (j && j.ok) self.trades = j.data || []; }, function () { });   // 成功才更新
      },
      loadGrid: function () {                    // 加载网格监控(持仓+风控参数+加仓日志)
        var self = this;                        // 闭包引用
        return A.gridmon().then(function (j) {
          if (j && j.ok) { self.grid = j.data; self.syncLines(); }   // 成功更新并同步持仓线(新开仓/平仓后线跟着变)
        }, function () { });                     // 失败静默
      },
      loadGuard: function () {                   // 加载守护状态(各进程存活/重启次数)
        var self = this;                        // 闭包引用
        return A.guard().then(function (j) { if (j && j.ok) self.guard = j; }, function () { });   // 成功才更新
      },

      loadMeta: function () {                    // 启动元信息: 合约池/最近交易/网格初始(决定默认合约)
        var self = this;                        // 闭包引用
        return Promise.all([                    // 三请求并行
          A.symbols().catch(function () { return null; }),   // 全部合约(失败null)
          A.boot().catch(function () { return null; }),      // 启动信息: 最近交易+默认合约
          A.gridmon().catch(function () { return null; }),   // 网格数据
        ]).then(function (r) {
          if (r[0] && r[0].ok) self.syms = r[0].data || [];   // 合约池入库
          if (r[1] && r[1].ok) self.recent = r[1].recent || [];   // 最近交易入库
          if (r[2] && r[2].ok) self.grid = r[2].data;   // 网格入库
          // 初始合约: hash > boot.default
          var m = /^#\/([A-Z0-9\-]+)\/(\w+)$/.exec(location.hash || "");   // 解析 #/合约/周期
          if (m) { self.inst = m[1]; if (TFS.indexOf(m[2]) >= 0) self.tf = m[2]; }   // hash 优先(周期需合法)
          else if (r[1] && r[1].ok && r[1].default) self.inst = r[1].default;   // 无hash → 用引擎默认合约
        });
      },

      refreshAll: function (manual) {            // 手动/首次全量刷新(六路数据并行)
        var self = this;                        // 闭包引用
        this.busy = !!manual;                   // 手动刷新时刷新按钮转圈
        return Promise.all([                    // 并行拉全部
          this.loadKline(), this.loadSignals(), this.loadOne(),   // K线/信号/实况
          this.loadTrades(), this.loadGrid(), this.loadGuard(),   // 流水/网格/守护
        ]).then(function () { self.busy = false; }, function () { self.busy = false; });   // 完成或失败都解除busy
      },

      /* 轻量轮询: 只刷不重画的项 */
      poll: function () {                        // 3s 轻量轮询(实况+网格, 不动图表)
        if (!this.auto) return;                  // 已暂停 → 跳过
        if (document.visibilityState === "hidden") return;   // 页面隐藏 → 跳过
        this.loadOne(); this.loadGrid();         // 只刷指标条与持仓(无图重绘开销)
      },
      pollTrades: function () { if (this.auto && document.visibilityState === "visible") this.loadTrades(); },   // 15s 流水轮询(可见且自动才刷)
      pollGuard: function () { if (this.auto && document.visibilityState === "visible") this.loadGuard(); },   // 10s 守护轮询
      /* AI模拟回测报告: 60s 轮询列表 + 点击打开详报页 */
      pollBt: function () {                      // 60s 报告列表轮询(报告每小时才一场, 60s 足够)
        var self = this;                         // 保存 this
        A.get("/btlist", null, 8000).then(function (j) {   // 拉最近 3 场简介
          if (j && j.ok) self.btList = j.list || [];       // 成功 → 更新列表(驱动首页面板)
        }, function () { });                     // 失败静默(下轮重试)
      },
      openBt: function (id) {                    // 点击报告行 → 新标签打开详报页
        window.open("/v2/bt_report.html?id=" + id, "_blank");   // 静态详报页自己再拉 /btreport?id=
      },
      btTime: function (ms) {                    // 报告时刻格式化: MM-DD HH:mm
        if (!ms) return "--";                    // 空值兜底
        var d = new Date(ms), p = function (x) { return (x < 10 ? "0" : "") + x; };   // 补零助手
        return p(d.getMonth() + 1) + "-" + p(d.getDate()) + " " + p(d.getHours()) + ":" + p(d.getMinutes());   // 月-日 时:分
      },
      pollKline: function () {                   // 20s 全量K线轮询
        if (!this.auto || document.visibilityState === "hidden") return;   // 暂停/隐藏 → 跳过
        if (this.ctrl && !this.ctrl.isAtLatest()) { this.followOff = true; return; }   // 用户在看历史 → 不打扰(只标记跟随暂停)
        this.followOff = false;                  // 在最新处 → 保持跟随
        this.loadKline(true);   // 合并模式: 不冲掉已加载的更早K线
      },
      /* 3s 尾部刷新: 只拉最近 10 根(fresh 强制打 OKX) → 图面实时补新根 + 更新进行中根 */
      pollKTail: function () {                   // 3s 尾部轮询(K线实时性的主力)
        if (!this.auto || document.visibilityState === "hidden") return;   // 暂停/隐藏 → 跳过
        if (!this.rows.length) return;           // 还没有基础数据 → 跳过
        if (this.ctrl && !this.ctrl.isAtLatest()) return;   // 用户正在看历史 → 不打扰
        var self = this, inst = this.inst, tf = this.tf;   // 快照合约/周期
        return A.kline(inst, tf, 10, { fresh: 1 }).then(function (j) {   // 只拉最近10根(强制打OKX)
          if (self.inst !== inst || self.tf !== tf) return;   // 期间切了合约/周期 → 丢弃
          if (!j || !j.ok || !j.rows || !j.rows.length) return;   // 无数据 → 忽略
          var tail = j.rows, app = self.ctrl ? self.ctrl.setTail(tail) : 0;   // 增量合并进图表(内部补新根/更新进行中根), 返回新增根数
          self.live.kt = self.live.kt + 1;       // 自检: 增量刷新成功次数+1(头部K线徽章)
          if (app) self.live.app = self.live.app + app;   // 累计新增周期根数(自检徽章)
          if (self.ctrl) self.rows = self.ctrl.getRows();   // 同步单一真源
          if (j.now && j.last) self.lagMin = Math.max(0, Math.round((j.now / 1000 - j.last) / 60));   // 更新滞后分钟数
          if (app) self.loadSignals();   // 新周期开始 → 买卖信号立即重取
        }, function () { });                     // 失败静默(下轮重试)
      },
    },

    mounted: function () {                       // 生命周期: 挂载后初始化图表/轮询/全局事件
      var self = this;                          // 闭包引用

      // 图表挂载(全部配置从 cfg 读; 绘图按 合约|周期 记忆)
      this.ctrl = C.mount(this.$refs.chartEl, {   // 把图表挂到 ref=chartEl 的容器上
        cfg: this.cfg,                          // 传入当前配置(类型/指标/副图全由 cfg 决定)
        onHover: function (h) { self.hover = h; },   // 悬停回调 → 右上OHLC浮窗
        onAtLatest: function (v) { self.followOff = !v; },   // 是否在最新处 → 控制"已暂停跟随"提示
        onNeedHistory: function (oldestSec) { self.loadHistory(oldestSec); },   // 拖到最左 → 触发历史分页加载
      });
      this.ctrl.setKey(this.inst, this.tf);     // 设置初始合约/周期(绘图记忆key)
      this.ctrl.setDrawColor(this.drawColor);   // 设置默认画线颜色
      this.ctrl.setTool("cursor");              // 默认光标(视图)模式

      this.loadMeta().then(function () {        // 先拿元信息(确定初始合约)
        self.ctrl.setKey(self.inst, self.tf);   // 元信息可能改了合约 → 重设图表key
        return self.refreshAll(false);          // 再全量刷新六路数据
      });

      // 时钟
      this._t1 = setInterval(function () { self.clock = U.clock(); }, 1000);   // 1s: 顶栏时钟
      // 轮询
      this._t2 = setInterval(function () { self.poll(); }, 3000);   // 3s: 实况+网格轻量轮询
      this._t3 = setInterval(function () { self.pollKline(); }, 20000);          // 全量(带 fresh)
      this._t4 = setInterval(function () { self.pollTrades(); }, 15000);   // 15s: 交易流水
      this._t5 = setInterval(function () { self.pollGuard(); }, 10000);   // 10s: 守护状态
      // 买卖信号: 15s 重取(新买入不刷新页面也能落图)
      this._t6 = setInterval(function () {      // 15s 信号轮询定时器
        if (self.auto && document.visibilityState === "visible") self.loadSignals();   // 可见且自动才重取(原5分钟太慢已改15s)
      }, 15000);
      // 实时价: 1.5s 打 /ticker(服务端 1s 缓存) → 头部与图面末根K线实时跳动
      this._t7 = setInterval(function () { self.pollTick(); }, 1500);   // 1.5s 实时价定时器
      // K线尾部: 3s 只拉最近10根 → 图面实时跟到最新一根(含进行中)
      this._t8 = setInterval(function () { self.pollKTail(); }, 3000);   // 3s 尾部轮询定时器
      this._t9 = setInterval(function () { self.pollBt(); }, 60000);   // 60s AI模拟回测报告列表轮询
      this.pollBt();                            // 挂载即拉一次报告列表(面板立即可见)

      // 点击空白关闭下拉
      document.addEventListener("click", function (e) {   // 全局点击监听
        if (self.dropOpen && !(e.target.closest && e.target.closest(".relative"))) self.dropOpen = false;   // 点击落在 .relative 外 → 收起合约下拉
      });
      // Esc 退出绘图工具 / 关闭设置面板
      document.addEventListener("keydown", function (e) {   // 全局按键监听
        if (e.key === "Escape") { self.setTool("cursor"); self.setOpen = false; }   // Esc → 回光标模式+关设置抽屉
      });
      window.addEventListener("resize", function () { if (self.ctrl && self.ctrl.applyPrecision) self.ctrl.applyPrecision(); });   // 窗口缩放 → 重设价格小数位适配
    },

    beforeUnmount: function () {                 // 生命周期: 卸载前清理
      clearInterval(this._t1); clearInterval(this._t2); clearInterval(this._t3);   // 清 1s/3s/20s 定时器
      clearInterval(this._t4); clearInterval(this._t5); clearInterval(this._t6);   // 清 15s/10s/15s 定时器
      clearInterval(this._t7); clearInterval(this._t8);   // 清 1.5s/3s 定时器
      if (this.ctrl) this.ctrl.destroy();       // 销毁图表控制器(释放事件与实例)
    },
  });

  app.mount("#app");                             // 挂载到 index.html 的 #app 容器
})();
