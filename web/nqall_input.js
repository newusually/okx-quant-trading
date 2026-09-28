/* nqall_input.js — 交互层: 鼠标拖拽/悬停十字线/滚轮缩放/边缘自动滚动 (只改状态, 不绘图不拉数据)
 * 依赖: nqall_core.js(cv/geom/状态) + nqall_data.js(right/count/clampRight) */
cv.addEventListener('mousemove', e => {
  const r = cv.getBoundingClientRect(); mx = e.clientX - r.left; my = e.clientY - r.top; inChart = true;
  if (dragging) { right = dragRight - Math.round((mx - dragX) / geom().pxBar); clampRight(); needDraw = true; edgeDir = 0; return; }
  const E = 80, g = geom();
  if (mx < E) edgeDir = -(1 - mx / E); else if (mx > g.x1 - E) edgeDir = (1 - (g.x1 - mx) / E); else edgeDir = 0;
  needDraw = true;
});
cv.addEventListener('mouseleave', () => { inChart = false; edgeDir = 0; tip.style.display = 'none'; needDraw = true; });
cv.addEventListener('mousedown', e => { dragging = true; dragX = e.clientX - cv.getBoundingClientRect().left; dragRight = right; });
window.addEventListener('mouseup', () => dragging = false);
/* 滚轮缩放: 挂在整个#chart容器(画布+边缘+图例都生效), 以鼠标位置为锚点放大/缩小 2026-09-28g */
function zoomAt(cx, deltaY) {
  const st = S[TF]; if (!st || !st.rows || st.rows.length < 2) return;
  const g = geom(), before = idxAt(cx);
  count = Math.round(count * (deltaY > 0 ? 1.15 : 1 / 1.15));
  count = Math.max(20, Math.min(3000, count));
  right = Math.round(before + (g.x1 - cx) / g.plotW * count);
  clampRight(); needDraw = true;
}
chart.addEventListener('wheel', e => {
  e.preventDefault();
  zoomAt(e.clientX - chart.getBoundingClientRect().left, e.deltaY);
}, { passive: false });
chart.addEventListener('dblclick', () => { count = 200; clampRight(); needDraw = true; });  // 双击复位缩放
