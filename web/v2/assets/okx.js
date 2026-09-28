/* ============================================================
 *  okx.js — apihub 接口封装 (同源, 经 Apache 反代到 :8090)
 *  纯 AJAX, 无框架依赖
 * ============================================================ */
window.OKXAPI = (function () {
  var inflight = Object.create(null);

  function qs(o) {
    var a = [];
    for (var k in o) {
      if (o[k] === undefined || o[k] === null) continue;
      a.push(encodeURIComponent(k) + "=" + encodeURIComponent(o[k]));
    }
    return a.join("&");
  }

  function get(path, params, timeoutMs) {
    var url = path + (params ? "?" + qs(params) : "");
    var ctl = typeof AbortController !== "undefined" ? new AbortController() : null;
    var timer = null;
    if (ctl) timer = setTimeout(function () { ctl.abort(); }, timeoutMs || 15000);
    return fetch(url, { cache: "no-store", signal: ctl ? ctl.signal : undefined })
      .then(function (r) {
        if (timer) clearTimeout(timer);
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.text();
      })
      .then(function (txt) {
        try { return JSON.parse(txt); }
        catch (e) { throw new Error("非法 JSON: " + txt.slice(0, 80)); }
      })
      .catch(function (e) {
        if (timer) clearTimeout(timer);
        throw e;
      });
  }

  /* 去重: 同一 key 的并发请求合并(页面 3s 轮询 + 手动刷新时不打两份) */
  function dedup(key, fn) {
    if (inflight[key]) return inflight[key];
    var p = fn().then(function (v) {
      delete inflight[key];
      return v;
    }, function (e) {
      delete inflight[key];
      throw e;
    });
    inflight[key] = p;
    return p;
  }

  return {
    get: get,
    kline: function (inst, tf, limit, extra) {
      var p = { inst: inst, tf: tf, limit: limit };
      if (extra) for (var k in extra) p[k] = extra[k];
      return dedup("k|" + inst + "|" + tf + "|" + limit + "|" + qs(extra || {}), function () { return get("/kline", p); });
    },
    marks: function (inst, days) { return dedup("m|" + inst + "|" + days, function () { return get("/marks", { inst: inst, days: days }); }); },
    sigs: function (inst, bar, limit) { return dedup("s|" + inst + "|" + bar + "|" + limit, function () { return get("/sigs", { inst: inst, bar: bar, limit: limit }); }); },
    trades: function () { return dedup("trades", function () { return get("/trades"); }); },
    livestats: function (inst) { return dedup("ls|" + inst, function () { return get("/livestats", { inst: inst }); }); },
    gridmon: function () { return dedup("gm", function () { return get("/gridmon"); }); },
    guard: function () { return dedup("gd", function () { return get("/guard"); }); },
    symbols: function () { return dedup("sym", function () { return get("/symbols"); }); },
    boot: function () { return dedup("boot", function () { return get("/boot"); }); },
    ticker: function (inst) { return dedup("tk|" + inst, function () { return get("/ticker", { inst: inst }); }); },
  };
})();

/* ============================================================
 *  OKXU — 通用工具
 * ============================================================ */
window.OKXU = (function () {
  var TZ = 8 * 3600; // 北京时间偏移(秒)

  function num(v) { var n = parseFloat(v); return isFinite(n) ? n : 0; }

  function fmtPx(p) {
    p = num(p);
    if (!isFinite(p)) return "--";
    var a = Math.abs(p);
    if (a >= 10000) return p.toFixed(1);
    if (a >= 1000) return p.toFixed(2);
    if (a >= 100) return p.toFixed(3);
    if (a >= 1) return p.toFixed(4);
    if (a >= 0.01) return p.toFixed(5);
    if (a >= 0.0001) return p.toFixed(6);
    return p.toFixed(8);
  }

  function precOf(p) {
    var a = Math.abs(num(p));
    if (a >= 10000) return 1;
    if (a >= 1000) return 2;
    if (a >= 100) return 3;
    if (a >= 1) return 4;
    if (a >= 0.01) return 5;
    if (a >= 0.0001) return 6;
    return 8;
  }

  function short(s) {
    if (!s) return "";
    return String(s).replace("-USDT-SWAP", "").replace("-USDT", "");
  }

  /* 毫秒 → 北京时间字符串 */
  function ts(ms, withDate) {
    if (!ms) return "--";
    var d = new Date(num(ms) + TZ * 1000);
    function p2(n) { return (n < 10 ? "0" : "") + n; }
    var t = p2(d.getUTCHours()) + ":" + p2(d.getUTCMinutes());
    if (!withDate) return t;
    return p2(d.getUTCMonth() + 1) + "-" + p2(d.getUTCDate()) + " " + t;
  }

  function tsFull(ms) {
    if (!ms) return "--";
    var d = new Date(num(ms) + TZ * 1000);
    function p2(n) { return (n < 10 ? "0" : "") + n; }
    return d.getUTCFullYear() + "-" + p2(d.getUTCMonth() + 1) + "-" + p2(d.getUTCDate()) + " " +
      p2(d.getUTCHours()) + ":" + p2(d.getUTCMinutes()) + ":" + p2(d.getUTCSeconds());
  }

  /* 二分: 找 <= t 的最近 bar 时间(标记必须落在已存在的 bar 上) */
  function alignToBar(arr, t) {
    var lo = 0, hi = arr.length - 1, r = -1;
    while (lo <= hi) {
      var m = (lo + hi) >> 1;
      if (arr[m] <= t) { r = m; lo = m + 1; } else hi = m - 1;
    }
    return r >= 0 ? arr[r] : -1;
  }

  function clock() {
    var d = new Date(Date.now() + TZ * 1000);
    function p2(n) { return (n < 10 ? "0" : "") + n; }
    return d.getUTCFullYear() + "-" + p2(d.getUTCMonth() + 1) + "-" + p2(d.getUTCDate()) + " " +
      p2(d.getUTCHours()) + ":" + p2(d.getUTCMinutes()) + ":" + p2(d.getUTCSeconds());
  }

  return { TZ: TZ, num: num, fmtPx: fmtPx, precOf: precOf, short: short, ts: ts, tsFull: tsFull, alignToBar: alignToBar, clock: clock };
})();
