/* nqall_chart.js — 绘图层: K线主图 + MACD副图 + 十字线悬浮窗 + 状态栏 (只画不算业务数据)
 * 依赖: nqall_core.js(ctx/geom/fmt) + nqall_data.js(S/TF/right/count/IV) */
function draw() {
  const st = S[TF], D = st ? st.rows : null;
  if (!D || !D.length) return;
  const g = geom(); ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.clearRect(0, 0, W, H);
  const first = Math.max(0, Math.floor(right - count + 1)), last = Math.min(D.length - 1, Math.floor(right));
  if (last < first) return;
  let ph = -1e18, pl = 1e18;
  for (let i = first; i <= last; i++) { if (D[i][2] > ph) ph = D[i][2]; if (D[i][3] < pl) pl = D[i][3]; }
  // 形成中的K线纳入视野
  const F = st.forming;
  const hasF = F && F.t === D[last][0] + IV[TF];
  if (hasF) { if (F.h > ph) ph = F.h; if (F.l < pl) pl = F.l; }
  const pad = (ph - pl) * 0.07 || 1; ph += pad; pl -= pad;
  const ky = v => g.kTop + (ph - v) / (ph - pl) * g.kH;
  let mm = 0; for (let i = first; i <= last; i++) { const a = Math.max(Math.abs(D[i][5]), Math.abs(D[i][6]), Math.abs(D[i][7])); if (a > mm) mm = a; }
  if (hasF) mm = Math.max(mm, Math.abs(F.m));
  mm *= 1.15; if (mm <= 0) mm = 1;
  const my0 = v => g.mTop + g.mH / 2 - v / mm * (g.mH / 2);
  const xAt = i => g.x1 - (right - i) * g.pxBar;
  const bw = Math.max(1, g.pxBar * 0.72);
  // 网格+价格轴
  ctx.font = '11px Consolas,monospace'; ctx.textBaseline = 'middle';
  const steps = [0.0001, 0.0002, 0.0005, 0.001, 0.002, 0.005, 0.01, 0.02, 0.05, 0.1, 0.2, 0.5, 1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000];
  let stp = steps.find(s => (ph - pl) / s <= 8) || 5000;
  ctx.strokeStyle = GRID; ctx.fillStyle = AXX; ctx.lineWidth = 1;
  for (let v = Math.ceil(pl / stp) * stp; v <= ph; v += stp) {
    const y = ky(v); ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(g.x1, y); ctx.stroke();
    ctx.textAlign = 'left'; ctx.fillText(fmtP(v), W - AXW + 6, y);
  }
  // 时间轴
  ctx.textAlign = 'center';
  const kStep = Math.max(1, Math.ceil(95 / g.pxBar));
  for (let i = first; i <= last; i++) {
    if ((i - first) % kStep !== 0) continue;
    const d = new Date((D[i][0] + 8 * 3600) * 1000);
    const dayB = d.getUTCHours() === 0 && d.getUTCMinutes() === 0;
    const x = xAt(i);
    ctx.strokeStyle = GRID; ctx.beginPath(); ctx.moveTo(x, g.kTop); ctx.lineTo(x, H - 26); ctx.stroke();
    ctx.fillStyle = AXX;
    ctx.fillText(dayB ? (d.getUTCMonth() + 1) + '-' + d.getUTCDate() : ('' + d.getUTCHours()).padStart(2, '0') + ':' + ('' + d.getUTCMinutes()).padStart(2, '0'), x, H - 13);
  }
  // K线
  function candle(i, t, o, h, l, c) {
    const x = xAt(i), up = c >= o;
    ctx.strokeStyle = ctx.fillStyle = up ? UP : DN; ctx.lineWidth = Math.min(1.4, bw * 0.15 + 0.5);
    ctx.beginPath(); ctx.moveTo(x, ky(h)); ctx.lineTo(x, ky(l)); ctx.stroke();
    const y1 = ky(Math.max(o, c)), y2 = ky(Math.min(o, c)), hh = Math.max(1, y2 - y1);
    if (bw <= 1.6) ctx.fillRect(x - bw / 2, y1, bw, hh);
    else if (up) ctx.strokeRect(x - bw / 2, y1, bw, hh);
    else ctx.fillRect(x - bw / 2, y1, bw, hh);
  }
  for (let i = first; i <= last; i++) candle(i, D[i][0], D[i][1], D[i][2], D[i][3], D[i][4]);
  if (hasF) { // 形成中: 半透明
    ctx.globalAlpha = 0.55; candle(last + 1, F.t, F.o, F.h, F.l, F.c); ctx.globalAlpha = 1;
  }
  // MACD
  ctx.strokeStyle = '#d0d7e2'; ctx.beginPath(); ctx.moveTo(0, g.mTop + g.mH / 2); ctx.lineTo(g.x1, g.mTop + g.mH / 2); ctx.stroke();
  function macdBar(i, m) { const x = xAt(i); ctx.fillStyle = m >= 0 ? UP : DN;
    const y = my0(Math.max(m, 0)), y2 = my0(Math.min(m, 0));
    ctx.fillRect(x - Math.max(0.5, bw * 0.35), y, Math.max(1, bw * 0.7), Math.max(1, y2 - y)); }
  for (let i = first; i <= last; i++) macdBar(i, D[i][7]);
  if (hasF) macdBar(last + 1, F.m);
  function line(f, col) { ctx.strokeStyle = col; ctx.lineWidth = 1.2; ctx.beginPath(); let st2 = false;
    for (let i = first; i <= last; i++) { const px = xAt(i), y = my0(f(i)); if (!st2) { ctx.moveTo(px, y); st2 = true; } else ctx.lineTo(px, y); }
    if (hasF) { ctx.lineTo(xAt(last + 1), my0(f(last + 1))); }
    ctx.stroke(); }
  line(i => D[i][5], DIFC); line(i => D[i][6], DEAC);
  ctx.fillStyle = AXX; ctx.textAlign = 'left';
  ctx.fillText(mm.toFixed(2), W - AXW + 6, g.mTop + 8); ctx.fillText((-mm).toFixed(2), W - AXW + 6, g.mTop + g.mH - 6); ctx.fillText('0', W - AXW + 6, g.mTop + g.mH / 2);
  ctx.fillStyle = DIFC; ctx.fillText('DIF', W - AXW + 6, g.mTop + 24); ctx.fillStyle = DEAC; ctx.fillText('DEA', W - AXW + 6, g.mTop + 38);
  // ===== 买卖平仓标注 (window.OKX_MARKS: [{t(ms),kind:buy/add/close,px,profit}]) — emoji版 🚀买入 🍃平仓 ▲加仓 =====
  const MK = window.OKX_MARKS;
  let mkN = 0;
  if (MK && MK.length) {
    const bSec = st.bucket * 60;
    const t0 = D[first][0], t1 = D[last][0] + IV[TF];
    ctx.textBaseline = 'middle';
    for (const m of MK) {
      const mt = Math.round(m.t / 1000);
      if (mt < t0 - bSec * 2 || mt > t1 + bSec * 2) continue;
      // 二分找最近K线
      let lo = first, hi = last;
      while (lo < hi) { const mid = (lo + hi) >> 1; if (D[mid][0] < mt) lo = mid + 1; else hi = mid; }
      let idx = (lo > first && Math.abs(D[lo - 1][0] - mt) < Math.abs(D[lo][0] - mt)) ? lo - 1 : lo;
      if (Math.abs(D[idx][0] - mt) > bSec * 2) continue;
      mkN++;
      const onF = hasF && idx === last && mt > D[last][0];
      const x = xAt(idx) + (onF ? g.pxBar : 0);
      ctx.textAlign = 'center';
      if (m.kind === 'buy' || m.kind === 'add') {          // 买入🚀红火箭(矢量) / 加仓金▲: 低点下方
        const y = ky(D[idx][3]) + 18;
        if (m.kind === 'buy') {
          ctx.fillStyle = '#e03131'; ctx.strokeStyle = '#fff'; ctx.lineWidth = 1.5;
          ctx.beginPath();                                  // 箭体
          ctx.moveTo(x, y - 11);
          ctx.quadraticCurveTo(x + 6, y - 3, x + 4, y + 6);
          ctx.lineTo(x - 4, y + 6);
          ctx.quadraticCurveTo(x - 6, y - 3, x, y - 11);
          ctx.closePath(); ctx.fill(); ctx.stroke();
          ctx.fillStyle = '#ff922b';                        // 尾焰
          ctx.beginPath(); ctx.moveTo(x - 3, y + 7); ctx.lineTo(x, y + 13); ctx.lineTo(x + 3, y + 7); ctx.closePath(); ctx.fill();
          ctx.fillStyle = '#fff';                           // 舷窗
          ctx.beginPath(); ctx.arc(x, y - 2, 2, 0, 6.283); ctx.fill();
          ctx.lineWidth = 1;
        } else {
          ctx.font = 'bold 12px "Microsoft YaHei",sans-serif';
          ctx.fillStyle = '#e6a817'; ctx.strokeStyle = '#fff'; ctx.lineWidth = 2;
          ctx.beginPath(); ctx.moveTo(x, y - 7); ctx.lineTo(x - 6, y + 4); ctx.lineTo(x + 6, y + 4); ctx.closePath();
          ctx.stroke(); ctx.fill();
        }
      } else if (m.kind === 'close') {                     // 平仓🍃绿叶子(矢量): 高点上方+盈利额(盈红亏绿)
        const win = (m.profit || 0) >= 0;
        const col = win ? '#e03131' : '#2f9e44';
        const py = (m.px > 0 && m.px < ph && m.px > pl) ? ky(m.px) : ky(D[idx][2]);
        const y = Math.min(py, ky(D[idx][2])) - 22;
        ctx.fillStyle = '#2f9e44'; ctx.strokeStyle = '#e9fbee'; ctx.lineWidth = 1;
        ctx.beginPath();                                  // 叶片: 两段贝塞尔
        ctx.moveTo(x, y + 10);
        ctx.quadraticCurveTo(x - 10, y + 3, x, y - 9);
        ctx.quadraticCurveTo(x + 10, y + 3, x, y + 10);
        ctx.closePath(); ctx.fill(); ctx.stroke();
        ctx.strokeStyle = '#e9fbee';                      // 叶脉
        ctx.beginPath(); ctx.moveTo(x, y + 8); ctx.lineTo(x, y - 6); ctx.stroke();
        ctx.font = 'bold 11px "Microsoft YaHei",sans-serif';
        ctx.fillStyle = col; ctx.strokeStyle = 'rgba(255,255,255,.9)'; ctx.lineWidth = 3;
        const txt = (win ? '+' : '') + (m.profit || 0).toFixed(2) + 'U';
        ctx.strokeText(txt, x, y - 17); ctx.fillText(txt, x, y - 17);
        ctx.lineWidth = 1;
      }
    }
    ctx.font = '11px Consolas,monospace'; ctx.lineWidth = 1;
  }
  // 十字线+悬浮窗
  if (inChart && mx >= 0 && mx <= g.x1 && my >= 0 && my < H - 26) {
    let idx = Math.max(0, Math.min(D.length - 1, idxAt(mx)));
    const overF = hasF && idxAt(mx) > last;
    const t = overF ? F.t : D[idx][0], o = overF ? F.o : D[idx][1], h = overF ? F.h : D[idx][2], l = overF ? F.l : D[idx][3], c = overF ? F.c : D[idx][4];
    const dd = overF ? F.d : D[idx][5], ee = overF ? F.e : D[idx][6], mv = overF ? F.m : D[idx][7];
    ctx.setLineDash([4, 4]); ctx.strokeStyle = '#94a3b8';
    ctx.beginPath(); ctx.moveTo(mx, g.kTop); ctx.lineTo(mx, H - 26); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(0, my); ctx.lineTo(g.x1, my); ctx.stroke(); ctx.setLineDash([]);
    if (my < g.mTop) { const v = ph - (my - g.kTop) / g.kH * (ph - pl); ctx.fillStyle = '#5b6b80'; ctx.fillText(fmtP(v), W - AXW + 6, my); }
    else { const v = (g.mTop + g.mH / 2 - my) / (g.mH / 2) * mm; ctx.fillStyle = '#5b6b80'; ctx.fillText(v.toFixed(3), W - AXW + 6, my); }
    const pi = overF ? D.length - 1 : Math.max(0, idx - 1);
    const pc = pi >= 0 && pi < D.length ? D[pi][4] : o;
    const chg = (c / pc - 1) * 100, amp = (h - l) / pc * 100, clsc = c >= pc;
    tip.innerHTML = '<div class="t">' + fmtDT(t) + (overF ? ' · <span style="color:#b45309">形成中</span>' : '') + '</div>'
      + '开 <b>' + fmtP(o) + '</b> · 高 <b class="up">' + fmtP(h) + '</b><br>'
      + '低 <b class="dn">' + fmtP(l) + '</b> · 收 <b class="' + (clsc ? 'up' : 'dn') + '">' + fmtP(c) + '</b><br>'
      + '涨幅 <b class="' + (chg >= 0 ? 'up' : 'dn') + '">' + (chg >= 0 ? '+' : '') + chg.toFixed(3) + '%</b> · 振幅 <b>' + amp.toFixed(3) + '%</b><br>'
      + '<span style="color:' + DIFC + '">DIF ' + dd.toFixed(4) + '</span> · <span style="color:' + DEAC + '">DEA ' + ee.toFixed(4) + '</span><br>'
      + 'MACD柱 <b style="color:' + (mv >= 0 ? UP : DN) + '">' + mv.toFixed(4) + '</b>';
    tip.style.display = 'block';
    const tw = tip.offsetWidth;
    tip.style.left = (mx + 18 + tw > W - 10 ? mx - 18 - tw : mx + 18) + 'px';
    tip.style.top = Math.min(Math.max(10, my - 30), H - tip.offsetHeight - 10) + 'px';
  } else tip.style.display = 'none';
  // 状态栏
  document.getElementById('stat').textContent = '缓冲' + D.length.toLocaleString() + '根'
    + (st.bucket > IV[TF] / 60 ? (' · 聚合' + st.bucket + '分钟') : '')
    + (st.exhaustOld ? ' · 已到最早' : '')
    + ' · ' + fmtT(D[first][0]) + ' ~ ' + fmtT(hasF ? F.t : D[last][0])
    + (MK && MK.length ? (' · 买卖标注' + mkN + '个(7天内)') : '');
}
