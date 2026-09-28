/* ov_paint.js — 画图层: 0.618 + 视口高低 + 每转折牛顿标注 (无缠论画线)
 * 标注: KE=½mv² · g=v²/2h · F=mg(牛二) · 尖转折金▲
 * 无未来函数: 全部数值只用转折点之前的数据; 不画反F(牛三需出笔=未来数据)
 * 所有转折点都标数值(不再按KE大小过滤) */
window.OV = window.OV || {};

OV.paint = function () {
  const st = S[TF], D = OV.data[TF];
  if (!st || !st.rows || !st.rows.length || !D || !D.piv.length) return;
  const rows = st.rows, n = rows.length, iv = IV[TF];
  const g = geom();
  const first = Math.max(0, Math.floor(right - count + 1)), last = Math.min(n - 1, Math.floor(right));
  if (last < first) return;
  // 价格刻度(与主图draw一致, 含形成中K线)
  let ph = -1e18, pl = 1e18;
  for (let i = first; i <= last; i++) { if (rows[i][2] > ph) ph = rows[i][2]; if (rows[i][3] < pl) pl = rows[i][3]; }
  const F = st.forming, hasF = F && F.t === rows[last][0] + iv;
  if (hasF) { if (F.h > ph) ph = F.h; if (F.l < pl) pl = F.l; }
  const fibH = ph, fibL = pl;
  const pad = (ph - pl) * 0.07 || 1; ph += pad; pl -= pad;
  const ky = v => g.kTop + (ph - v) / (ph - pl) * g.kH;
  const xAt = i => g.x1 - (right - i) * g.pxBar;
  const pv = D.piv;
  // pivot行下标→x (行下标与rows一一对应, 直接xAt)
  const fs = Math.min(16, Math.max(13.5, g.pxBar * 1.5));

  ctx.save();
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  // ① 0.618 黄金分割 + 视口最高/最低
  if (fibH - fibL > 1e-9) {
    const p618 = fibL + (fibH - fibL) * 0.618;
    const y618 = ky(p618);
    ctx.setLineDash([8, 5]);
    ctx.strokeStyle = '#e6a817';
    ctx.lineWidth = 2;
    ctx.beginPath(); ctx.moveTo(0, y618); ctx.lineTo(g.x1, y618); ctx.stroke();
    ctx.setLineDash([]);
    ctx.font = 'bold 12px Consolas,monospace';
    ctx.textAlign = 'right';
    ctx.fillStyle = '#b07708';
    ctx.fillText('0.618  ' + p618.toFixed(2), g.x1 - 4, y618 - 5);
    ctx.font = 'bold 11px Consolas,monospace';
    ctx.textAlign = 'center';
    let hiIdx = first, loIdx = first;
    for (let i = first; i <= last; i++) { if (rows[i][2] > rows[hiIdx][2]) hiIdx = i; if (rows[i][3] < rows[loIdx][3]) loIdx = i; }
    ctx.fillStyle = '#c04a3a';
    ctx.fillText('最高 ' + fibH.toFixed(2), xAt(hiIdx), ky(fibH) + 14);
    ctx.fillStyle = '#0a7a44';
    ctx.fillText('最低 ' + fibL.toFixed(2), xAt(loIdx), ky(fibL) - 6);
  }
  // ② 牛顿标注 — 只显示尖转折(底部动能前25%): 金色▲+金色KE/g/F; 其余灰色全部隐藏
  const th = D.th;
  ctx.textAlign = 'center';
  for (let k = 0; k < pv.length; k++) {
    const o = pv[k];
    if (o.i < first || o.i > last || o.ke === undefined) continue;
    const x = xAt(o.i);
    if (x < 30 || x > g.x1 + 40) continue;
    const y = ky(o.p);
    const isBot = o.type === 0;
    if (!isBot || o.ke < th) continue;                                 // 顶/普通底: 灰字隐藏
    const txt = 'KE=' + o.ke.toExponential(1);
    const txt2 = 'g=' + (o.g == null ? '-' : o.g.toExponential(1))
      + ' F=' + (o.F == null ? '-' : o.F.toExponential(1));
    ctx.font = 'bold ' + fs + 'px Consolas,sans-serif';
    ctx.fillStyle = '#b07708';
    ctx.fillText(txt, x, y + 38);
    ctx.fillText(txt2, x, y + 54);
    const s = 9;                                                       // 金色▲
    ctx.fillStyle = '#e6a817';
    ctx.beginPath();
    ctx.moveTo(x, y + 8); ctx.lineTo(x - s, y + 8 + s * 1.5); ctx.lineTo(x + s, y + 8 + s * 1.5);
    ctx.closePath(); ctx.fill();
  }
  ctx.restore();
};
