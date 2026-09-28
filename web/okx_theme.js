/* okx_theme.js — 特殊交互特效: 悬停魔法棒+闪烁星星(空闲自动闪), 按左键变可爱小手 (移植自原OKX面板)
 *
 * 【文件职责】K线图区域的装饰性交互特效(纯视觉, 不影响数据与绘图逻辑)。
 * 【功能清单】1. 魔法棒/小手光标切换(悬停魔法棒, 按左键变小手, 松开还原);
 *             2. sparkAt() 星星粒子生成(按下时随机迸发3颗);
 *             3. 空闲时每900ms在图上随机位置自动闪烁一颗星星(页面隐藏时暂停)。
 */
const chartEl = document.getElementById('chart');        // K线图容器元素(特效挂载点)
const cvEl = document.getElementById('cv');              // 绘图画布元素(光标同步切换)
const HAND_CUR = 'url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'30\' height=\'30\'><text y=\'24\' font-size=\'24\'>🤚</text></svg>") 15 6, pointer';  // 小手光标: 内联SVG绘制的🤚emoji, 热点(15,6), 兜底pointer
function sparkAt(x, y) {                                 // 在指定坐标生成一颗星星粒子
  const s = document.createElement('span'); s.className = 'spark';  // 创建粒子span(样式类spark控制动画)
  s.textContent = Math.random() < .5 ? '✨' : '⭐';      // 随机选✨或⭐两种粒子
  s.style.left = (x - 9) + 'px'; s.style.top = (y - 9) + 'px';  // 定位到坐标(减9让粒子中心对准鼠标)
  chartEl.appendChild(s); setTimeout(() => s.remove(), 1200);   // 挂到图上, 1.2秒动画结束后自动移除
}
chartEl.addEventListener('mousedown', e => {             // 在图上按下鼠标
  if (e.button !== 0) return;                            // 只响应左键
  chartEl.style.cursor = HAND_CUR;                     // 按左键 → 可爱小手
  cvEl.style.cursor = HAND_CUR;                          // 画布同步变小手
  for (let i = 0; i < 3; i++) sparkAt(e.offsetX + (Math.random() * 26 - 13), e.offsetY + (Math.random() * 26 - 13));  // 在鼠标附近随机迸发3颗星星
});
function curBack() { chartEl.style.cursor = ''; cvEl.style.cursor = ''; }   // 松开 → 变回魔法棒
chartEl.addEventListener('mouseup', curBack);            // 松开鼠标 → 恢复默认光标
chartEl.addEventListener('mouseleave', curBack);         // 离开图区 → 恢复默认光标
setInterval(() => {                                    // 空闲时星星在K线图上闪烁(原版口径900ms)
  if (document.hidden) return;                           // 页面不在前台时跳过(省资源)
  const r = chartEl.getBoundingClientRect();             // 取图区尺寸
  sparkAt(12 + Math.random() * (r.width - 30), 12 + Math.random() * (r.height - 30));  // 在图区内随机位置(留边距)闪一颗星
}, 900);                                                 // 每900ms一次
