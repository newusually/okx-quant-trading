/* ============================================================
 *  okx.js — OKX 相关工具函数 / 后端接口封装
 *  【文件职责】
 *    1. window.OKXAPI：apihub 后端接口的 AJAX 封装（同源，经 Apache 反代到 :8090），
 *       纯 fetch 实现、无框架依赖；内置 请求去重(合并并发) 与 超时中断。
 *       提供 kline / marks / sigs / trades / livestats / gridmon / guard /
 *       symbols / boot / ticker 等接口方法。
 *    2. window.OKXU：通用工具集 —— 数字解析、价格格式化/精度推断、
 *       合约名缩写、北京时间格式化(+8h 偏移)、K线时间二分对齐、时钟字符串。
 *  【核心功能清单】
 *    · qs(params)：对象 → URL 查询串(跳过 null/undefined，encodeURIComponent)
 *    · get(path, params, timeoutMs)：GET + JSON 解析，AbortController 超时中断(默认15s)
 *    · dedup(key, fn)：同 key 并发请求合并，页面 3s 轮询+手动刷新不打两份
 *    · kline(inst,tf,limit,extra)：拉K线，支持 before=xxx 历史分页参数
 *    · OKXU.TZ=28800：所有时间 +8h 后按 UTC 读取，即得北京时间字符串
 *    · alignToBar(times, t)：二分找 <=t 的最近 K 线时间(信号标记必须落在已存在的 bar 上)
 * ============================================================ */

/* ─────────── OKXAPI：后端接口封装 ─────────── */
window.OKXAPI = (function () {                         // 全局接口模块：IIFE 封装，返回各接口方法
  var inflight = Object.create(null);                  // 进行中请求表：key → Promise，用于并发去重

  function qs(o) {                                     // 把参数对象序列化为 URL 查询串
    var a = [];                                        // 收集 "k=v" 片段
    for (var k in o) {                                 // 遍历参数
      if (o[k] === undefined || o[k] === null) continue; // 跳过空值
      a.push(encodeURIComponent(k) + "=" + encodeURIComponent(o[k])); // 键值都做 URL 编码
    }
    return a.join("&");                                // 用 & 拼接
  }

  function get(path, params, timeoutMs) {              // 核心 GET 请求：返回解析后的 JSON 的 Promise
    var url = path + (params ? "?" + qs(params) : ""); // 拼接查询串(有参数才加 ?)
    var ctl = typeof AbortController !== "undefined" ? new AbortController() : null; // 超时中断控制器(老浏览器无此 API 则跳过)
    var timer = null;                                  // 超时定时器句柄
    if (ctl) timer = setTimeout(function () { ctl.abort(); }, timeoutMs || 15000); // 到时(默认15s)中断请求
    return fetch(url, { cache: "no-store", signal: ctl ? ctl.signal : undefined }) // 发起请求，禁用缓存
      .then(function (r) {                             // 响应处理
        if (timer) clearTimeout(timer);                // 已响应：清除超时定时器
        if (!r.ok) throw new Error("HTTP " + r.status); // 非 2xx：抛出状态码错误
        return r.text();                               // 先取原始文本(便于校验 JSON 合法性)
      })
      .then(function (txt) {                           // 文本 → JSON
        try { return JSON.parse(txt); }                // 正常解析
        catch (e) { throw new Error("非法 JSON: " + txt.slice(0, 80)); } // 解析失败：抛错并附前80字符便于排障
      })
      .catch(function (e) {                            // 统一兜底
        if (timer) clearTimeout(timer);                // 确保定时器被清(abort 场景也会走到这)
        throw e;                                       // 原样上抛
      });
  }

  /* 去重: 同一 key 的并发请求合并(页面 3s 轮询 + 手动刷新时不打两份) */
  function dedup(key, fn) {                            // 请求去重：同 key 的并发调用共享同一个 Promise
    if (inflight[key]) return inflight[key];           // 已有进行中的同 key 请求：直接复用
    var p = fn().then(function (v) {                   // 发起新请求
      delete inflight[key];                            // 成功后清出在途表
      return v;                                        // 透传结果
    }, function (e) {                                  // 失败分支
      delete inflight[key];                            // 同样清出，允许后续重试
      throw e;                                         // 原样上抛
    });
    inflight[key] = p;                                 // 登记在途请求
    return p;                                          // 返回共享 Promise
  }

  return {                                             // 对外接口集：全部经 dedup 防并发重复
    get: get,                                          // 暴露原始 get(特殊场景直接用)
    kline: function (inst, tf, limit, extra) {         // K线接口：合约/周期/根数，extra 可带 before=xxx(历史分页)
      var p = { inst: inst, tf: tf, limit: limit };    // 基础参数
      if (extra) for (var k in extra) p[k] = extra[k]; // 合并附加参数
      return dedup("k|" + inst + "|" + tf + "|" + limit + "|" + qs(extra || {}), function () { return get("/kline", p); }); // key 含全部参数，精确去重
    },
    marks: function (inst, days) { return dedup("m|" + inst + "|" + days, function () { return get("/marks", { inst: inst, days: days }); }); }, // 交易标记(近 N 天)
    sigs: function (inst, bar, limit) { return dedup("s|" + inst + "|" + bar + "|" + limit, function () { return get("/sigs", { inst: inst, bar: bar, limit: limit }); }); }, // 信号列表
    trades: function () { return dedup("trades", function () { return get("/trades"); }); }, // 成交记录
    livestats: function (inst) { return dedup("ls|" + inst, function () { return get("/livestats", { inst: inst }); }); }, // 实时统计
    gridmon: function () { return dedup("gm", function () { return get("/gridmon"); }); }, // 网格监控
    guard: function () { return dedup("gd", function () { return get("/guard"); }); }, // 风控守卫状态
    symbols: function () { return dedup("sym", function () { return get("/symbols"); }); }, // 合约列表
    boot: function () { return dedup("boot", function () { return get("/boot"); }); }, // 启动信息
    ticker: function (inst) { return dedup("tk|" + inst, function () { return get("/ticker", { inst: inst }); }); }, // 单合约实时行情
  };
})();

/* ============================================================
 *  OKXU — 通用工具
 * ============================================================ */
window.OKXU = (function () {                           // 全局工具模块：IIFE 封装
  var TZ = 8 * 3600; // 北京时间偏移(秒)               // +8h 偏移：时间戳加上它再按 UTC 读，就是北京时间

  function num(v) { var n = parseFloat(v); return isFinite(n) ? n : 0; } // 安全转数字：非法/NaN 一律返回 0

  function fmtPx(p) {                                  // 按价格量级格式化显示(大币少小数、土狗币多小数)
    p = num(p);                                        // 先安全转数字
    if (!isFinite(p)) return "--";                     // 仍非法显示占位符
    var a = Math.abs(p);                               // 取绝对值判断量级
    if (a >= 10000) return p.toFixed(1);               // 万级：1位小数
    if (a >= 1000) return p.toFixed(2);                // 千级：2位
    if (a >= 100) return p.toFixed(3);                 // 百级：3位
    if (a >= 1) return p.toFixed(4);                   // 个位级：4位
    if (a >= 0.01) return p.toFixed(5);                // 分级：5位
    if (a >= 0.0001) return p.toFixed(6);              // 更小：6位
    return p.toFixed(8);                               // 土狗币兜底：8位
  }

  function precOf(p) {                                 // 按价格量级推断 lwc 图表价格轴小数位
    var a = Math.abs(num(p));                          // 量级
    if (a >= 10000) return 1;                          // 万级：1位
    if (a >= 1000) return 2;                           // 千级：2位
    if (a >= 100) return 3;                            // 百级：3位
    if (a >= 1) return 4;                              // 个位：4位
    if (a >= 0.01) return 5;                           // 分级：5位
    if (a >= 0.0001) return 6;                         // 微价：6位
    return 8;                                          // 兜底：8位
  }

  function short(s) {                                  // 合约名缩写：去掉常见后缀便于展示
    if (!s) return "";                                 // 空值返回空串
    return String(s).replace("-USDT-SWAP", "").replace("-USDT", ""); // "BTC-USDT-SWAP" → "BTC"
  }

  /* 毫秒 → 北京时间字符串 */
  function ts(ms, withDate) {                          // 毫秒时间戳 → "HH:mm" 或 "MM-DD HH:mm"
    if (!ms) return "--";                              // 空值占位
    var d = new Date(num(ms) + TZ * 1000);             // 加8h偏移后按 UTC 读即北京时间
    function p2(n) { return (n < 10 ? "0" : "") + n; } // 补零
    var t = p2(d.getUTCHours()) + ":" + p2(d.getUTCMinutes()); // "HH:mm"
    if (!withDate) return t;                           // 不带日期：只返回时分
    return p2(d.getUTCMonth() + 1) + "-" + p2(d.getUTCDate()) + " " + t; // 带日期："MM-DD HH:mm"
  }

  function tsFull(ms) {                                // 毫秒 → 完整 "YYYY-MM-DD HH:mm:ss"(北京时间)
    if (!ms) return "--";                              // 空值占位
    var d = new Date(num(ms) + TZ * 1000);             // 加偏移
    function p2(n) { return (n < 10 ? "0" : "") + n; } // 补零
    return d.getUTCFullYear() + "-" + p2(d.getUTCMonth() + 1) + "-" + p2(d.getUTCDate()) + " " +
      p2(d.getUTCHours()) + ":" + p2(d.getUTCMinutes()) + ":" + p2(d.getUTCSeconds()); // 拼完整串
  }

  /* 二分: 找 <= t 的最近 bar 时间(标记必须落在已存在的 bar 上) */
  function alignToBar(arr, t) {                        // 在升序时间数组中二分找 <=t 的最近时间(对不齐的信号吸附到K线)
    var lo = 0, hi = arr.length - 1, r = -1;           // 双指针与结果下标(-1=找不到)
    while (lo <= hi) {                                 // 标准二分
      var m = (lo + hi) >> 1;                          // 中点
      if (arr[m] <= t) { r = m; lo = m + 1; } else hi = m - 1; // 命中<=t：记下标并往右找更近的
    }
    return r >= 0 ? arr[r] : -1;                       // 返回对齐后的时间，找不到返回 -1
  }

  function clock() {                                   // 当前时刻的北京时间字符串(页面时钟用)
    var d = new Date(Date.now() + TZ * 1000);          // 当前毫秒 + 8h 偏移
    function p2(n) { return (n < 10 ? "0" : "") + n; } // 补零
    return d.getUTCFullYear() + "-" + p2(d.getUTCMonth() + 1) + "-" + p2(d.getUTCDate()) + " " +
      p2(d.getUTCHours()) + ":" + p2(d.getUTCMinutes()) + ":" + p2(d.getUTCSeconds()); // "YYYY-MM-DD HH:mm:ss"
  }

  return { TZ: TZ, num: num, fmtPx: fmtPx, precOf: precOf, short: short, ts: ts, tsFull: tsFull, alignToBar: alignToBar, clock: clock }; // 暴露全部工具函数
})();
