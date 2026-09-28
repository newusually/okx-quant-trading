/* ov_core.js — 力学覆盖层·取数与调度 (2026-09-28 计算全量下沉 C++)
 * 计算已全部在 C++ (sigcore.cpp pivots_full → apihub /sigs): 阈值ZigZag / ATR14 / KE=½mv² /
 * g=v²/2h / F=mg(牛二) / 角度 / 二阶导 / 尖转折阈值(底部KE前25%)。
 * 前端只做: ①请求 ②按时间戳对齐到当前K线缓冲 ③交给 ov_paint.js 画图。
 * 文件分工: ov_core.js 取数+调度+图例 · ov_paint.js 画图 (原 ov_pivot.js/ov_physics.js 已删除) */
window.OV = window.OV || {};
OV.data = {};                              // tf -> {piv:[...], th, key}

/* 时间戳 → 当前缓冲行下标(二分; rows 按 t 升序)。找不到返回 -1 */
function ovRowAt(rows, t) {
  let lo = 0, hi = rows.length - 1;
  while (lo <= hi) {
    const mid = (lo + hi) >> 1;
    if (rows[mid][0] === t) return mid;
    if (rows[mid][0] < t) lo = mid + 1; else hi = mid - 1;
  }
  return -1;
}

/* 重算调度: rows 内容变了才请求(新K线/翻页加载), 否则每帧直接用缓存 */
OV.compute = function (tf) {
  const st = S[tf];
  if (!st || !st.rows || st.rows.length < 50) return;
  const rows = st.rows;
  const key = rows.length + '_' + rows[rows.length - 1][0];
  const d = OV.data[tf];
  if (d && d.key === key) return;
  if (d && d.pending === key) return;                    // 同一 key 不重复请求
  OV.data[tf] = { piv: (d && d.piv) || [], th: (d && d.th) || 0, key: d ? d.key : 0, pending: key };
  const inst = window.OKX_INST || '';
  const url = (window.AH || '') + '/sigs?bar=' + encodeURIComponent(tf) + '&limit=500'
            + (inst ? '&inst=' + encodeURIComponent(inst) : '');
  fetch(url, { signal: (typeof tmo === 'function' ? tmo(6000) : undefined) })
    .then(r => r.json())
    .then(function (j) {
      if (!j || !j.ok || !j.data) return;
      const cur = S[tf];
      if (!cur || !cur.rows) return;
      const rw = cur.rows;
      const piv = [];
      for (let k = 0; k < j.data.length; k++) {
        const o = j.data[k];
        // /sigs 的 t 是毫秒(与 OKX 原始一致), 前端缓冲 rows[i][0] 是秒 → 归一到秒
        const ts = o.t > 1e12 ? Math.floor(o.t / 1000) : o.t;
        const i = ovRowAt(rw, ts);                        // 按时间戳对齐
        if (i < 0) continue;                              // 不在当前缓冲内 → 略过
        piv.push({ t: ts, i: i, p: o.p, type: o.type,
                   ke: o.ke, g: o.g, F: o.f, v: o.v, m: o.m, ang: o.ang, a: o.a, bars: o.bars });
      }
      const nk = rw.length + '_' + rw[rw.length - 1][0];
      OV.data[tf] = { piv: piv, th: j.th || 0, key: nk };
      if (typeof needDraw !== 'undefined') needDraw = true;
      const c = document.getElementById('ovCnt');
      if (c) {
        let cnt = 0;
        for (let k = 0; k < piv.length; k++) if (piv[k].type === 0 && piv[k].ke >= j.th) cnt++;
        c.textContent = tf + ' 力学转折 ' + piv.length + ' 个 · 尖转折 ' + cnt + ' 个 (C++计算)';
      }
    })
    .catch(function () {});
};

/* 图例 (DOM就绪后创建) */
function ovInitUI() {
  const host = document.getElementById('chart');
  if (!host || document.getElementById('ovLegend')) return;
  const lg = document.createElement('div');
  lg.id = 'ovLegend';
  lg.innerHTML = '<span style="color:#c8860a;font-weight:bold">▲</span>尖转折(动能前25%) '
    + '<span style="color:#888">KE=½mv² · g=v²/2h 重力加速度 · F=mg(牛顿二) · 0.618黄金分割 · 全部由 C++ 计算, 前端只画</span> '
    + '<span id="ovCnt"></span>';
  host.appendChild(lg);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ovInitUI);
else ovInitUI();

/* 包装主图 draw(): 主脚本就绪后挂载; 保护壳: 覆盖层抛错绝不杀主渲染循环 */
(function () {
  let tries = 0;
  const timer = setInterval(() => {
    tries++;
    if (typeof draw === 'function' && !draw.__ovhook) {
      const _d = draw;
      const w = function () {
        _d();
        try {
          if (typeof TF !== 'undefined' && typeof S !== 'undefined' && S[TF]) {
            OV.compute(TF);
            OV.paint();                    // ov_paint.js
          }
        } catch (e) { console.warn('[ov]', e && e.message); }
      };
      w.__ovhook = true;
      window.draw = w;
      if (typeof needDraw !== 'undefined') needDraw = true;
      clearInterval(timer);
    } else if (tries > 150) clearInterval(timer);
  }, 100);
})();
