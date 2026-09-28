/* okx_theme.js — 特殊交互特效: 悬停魔法棒+闪烁星星(空闲自动闪), 按左键变可爱小手 (移植自原OKX面板) */
const chartEl = document.getElementById('chart');
const cvEl = document.getElementById('cv');
const HAND_CUR = 'url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'30\' height=\'30\'><text y=\'24\' font-size=\'24\'>🤚</text></svg>") 15 6, pointer';
function sparkAt(x, y) {
  const s = document.createElement('span'); s.className = 'spark';
  s.textContent = Math.random() < .5 ? '✨' : '⭐';
  s.style.left = (x - 9) + 'px'; s.style.top = (y - 9) + 'px';
  chartEl.appendChild(s); setTimeout(() => s.remove(), 1200);
}
chartEl.addEventListener('mousedown', e => {
  if (e.button !== 0) return;
  chartEl.style.cursor = HAND_CUR;                     // 按左键 → 可爱小手
  cvEl.style.cursor = HAND_CUR;
  for (let i = 0; i < 3; i++) sparkAt(e.offsetX + (Math.random() * 26 - 13), e.offsetY + (Math.random() * 26 - 13));
});
function curBack() { chartEl.style.cursor = ''; cvEl.style.cursor = ''; }   // 松开 → 变回魔法棒
chartEl.addEventListener('mouseup', curBack);
chartEl.addEventListener('mouseleave', curBack);
setInterval(() => {                                    // 空闲时星星在K线图上闪烁(原版口径900ms)
  if (document.hidden) return;
  const r = chartEl.getBoundingClientRect();
  sparkAt(12 + Math.random() * (r.width - 30), 12 + Math.random() * (r.height - 30));
}, 900);
