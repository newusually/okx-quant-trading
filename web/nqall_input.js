/* nqall_input.js — 交互层: 鼠标拖拽/悬停十字线/滚轮缩放/边缘自动滚动 (只改状态, 不绘图不拉数据)
 * 依赖: nqall_core.js(cv/geom/状态) + nqall_data.js(right/count/clampRight)
 *
 * 【文件职责】K线图的全部鼠标交互: 把用户操作翻译成状态变量(right/count/mx/my等), 由 nqall_core.js 主循环统一重绘。
 * 【功能清单】1. mousemove 鼠标移动(十字线坐标/拖拽平移/边缘自动滚动判定);
 *             2. mouseleave 离开图区清理; 3. mousedown/mouseup 拖拽起止;
 *             4. zoomAt+wheel 滚轮缩放(以鼠标为锚点); 5. 双击复位默认200根。
 */
cv.addEventListener('mousemove', e => {                  // 鼠标在画布上移动
  const r = cv.getBoundingClientRect(); mx = e.clientX - r.left; my = e.clientY - r.top; inChart = true;  // 换算为画布内坐标, 标记在图内
  if (dragging) { right = dragRight - Math.round((mx - dragX) / geom().pxBar); clampRight(); needDraw = true; edgeDir = 0; return; }  // 拖拽中: 按位移量平移right视窗, 不做边缘滚动
  const E = 80, g = geom();                              // E=边缘感应区宽度(80px), g=几何对象
  if (mx < E) edgeDir = -(1 - mx / E); else if (mx > g.x1 - E) edgeDir = (1 - (g.x1 - mx) / E); else edgeDir = 0;  // 距左/右边缘越近滚动越快, 中部为0
  needDraw = true;                                       // 坐标变了 → 重绘十字线
});
cv.addEventListener('mouseleave', () => { inChart = false; edgeDir = 0; tip.style.display = 'none'; needDraw = true; });  // 鼠标离开: 清除十字线与悬浮窗并停边缘滚动
cv.addEventListener('mousedown', e => { dragging = true; dragX = e.clientX - cv.getBoundingClientRect().left; dragRight = right; });  // 按下: 记录拖动起点x与当时right值
window.addEventListener('mouseup', () => dragging = false);  // 全局松开: 结束拖拽(移出画布松开也能结束)
/* 滚轮缩放: 挂在整个#chart容器(画布+边缘+图例都生效), 以鼠标位置为锚点放大/缩小 2026-09-28g */
function zoomAt(cx, deltaY) {                            // 以屏幕x=cx为锚点缩放
  const st = S[TF]; if (!st || !st.rows || st.rows.length < 2) return;  // 无数据/数据不足时忽略
  const g = geom(), before = idxAt(cx);                  // 缩放前鼠标所指的K线下标(锚点)
  count = Math.round(count * (deltaY > 0 ? 1.15 : 1 / 1.15));  // 滚轮向下缩小视野(根数×1.15), 向上放大(÷1.15)
  count = Math.max(20, Math.min(3000, count));           // 可见根数限制在20~3000
  right = Math.round(before + (g.x1 - cx) / g.plotW * count);  // 调整right使锚点K线保持在原屏幕位置
  clampRight(); needDraw = true;                         // 夹边界并请求重绘
}
chart.addEventListener('wheel', e => {                   // 滚轮事件挂在整个图容器
  e.preventDefault();                                    // 阻止页面滚动
  zoomAt(e.clientX - chart.getBoundingClientRect().left, e.deltaY);  // 换算容器内x并执行缩放
}, { passive: false });                                  // passive:false 才能调用preventDefault
chart.addEventListener('dblclick', () => { count = 200; clampRight(); needDraw = true; });  // 双击复位缩放
