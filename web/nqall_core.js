/* nqall_core.js — 主图核心: DOM引用 / 画布状态 / 几何 / 时间格式 / 主循环 (不含业务计算)
 * 文件分工: nqall_core.js(本文件) → nqall_data.js(数据/轮询) → nqall_chart.js(绘图) → nqall_input.js(交互) */
const UP = '#d5382f', DN = '#0a8f4e', AXX = '#8795a8', GRID = '#e8edf4', DIFC = '#d98b0b', DEAC = '#4a7dd6';
const AXW = 64;
const cv = document.getElementById('cv'), ctx = cv.getContext('2d');
const tip = document.getElementById('tip'), chart = document.getElementById('chart');
const fetchHint = document.getElementById('fetch');
let W = 0, H = 0, dpr = 1, needDraw = true;
let mx = -1, my = -1, inChart = false, dragging = false, dragX = 0, dragRight = 0, edgeDir = 0;

/* JS错误陷阱(2026-09-28g): 任何脚本错误直接画到页面上, 不再只进控制台 */
function showJsErr(msg) {
  let box = document.getElementById('jserr');
  if (!box) {
    box = document.createElement('div'); box.id = 'jserr';
    box.style.cssText = 'position:absolute;left:8px;top:44px;z-index:99;max-width:90%;background:#fff3f3;border:2px solid #e03131;border-radius:8px;padding:8px 12px;font:12px/1.6 Consolas,"Microsoft YaHei",monospace;color:#c92a2a;white-space:pre-wrap;box-shadow:0 4px 14px rgba(160,30,30,.25)';
    chart.appendChild(box);
  }
  box.textContent = ('' + msg).slice(0, 600);
  const ld = document.getElementById('load'); if (ld) ld.style.display = 'none';
}
window.onerror = function(msg, src, line, col) {
  showJsErr('⚠ JS错误: ' + msg + '\n@ ' + (src || '?').split('/').pop() + ':' + line + ':' + col);
  return false;
};
window.addEventListener('unhandledrejection', e => {
  showJsErr('⚠ 请求失败: ' + (e.reason && e.reason.message || e.reason));
});

function resize() { dpr = window.devicePixelRatio || 1; W = chart.clientWidth; H = chart.clientHeight; cv.width = W * dpr; cv.height = H * dpr; needDraw = true; }
window.addEventListener('resize', resize); resize();

function fmtDT(s) { const d = new Date((s + 8 * 3600) * 1000); const p = x => ('' + x).padStart(2, '0'); return d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate()) + ' ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()); }
function fmtT(s) { const d = new Date((s + 8 * 3600) * 1000); const p = x => ('' + x).padStart(2, '0'); return p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate()) + ' ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()); }
/* 价格格式: 小数点后4位(1~1000区间币种), 大市值2位, 微价币6位; 不加千分位(2026-09-28 用户指令) */
function fmtP(v) { v = +v; if (!isFinite(v)) return ''; const a = Math.abs(v);
  return v.toFixed(a >= 1000 ? 2 : (a >= 1 ? 4 : 6)); }

function geom() { const plotW = W - AXW, macdH = Math.max(110, H * 0.30), kTop = 8, kH = H - macdH - kTop - 26, mTop = H - macdH - 14, mH = macdH - 10;
  return { plotW, macdH, kTop, kH, mTop, mH, pxBar: plotW / count, x0: 0, x1: W - AXW }; }
function idxAt(x) { const g = geom(); return Math.round(right - (g.x1 - x) / g.pxBar); }

// 主循环: 边缘自动滚动 + 按需拉取 + 重绘 (draw在nqall_chart.js, ensureData在nqall_data.js)
function tick() {
  // 数据层(nqall_data.js)可能尚未执行完成, 此时 S/TF 还不存在(旧内核还会抛 ReferenceError 弹红框)
  if (typeof S === 'undefined' || typeof TF === 'undefined') { requestAnimationFrame(tick); return; }
  const st = S[TF];
  if (st && st.rows.length && edgeDir !== 0 && !dragging && !st.fetching) {
    right += Math.max(1.5, count / 45) * edgeDir;
    clampRight(); needDraw = true;
  }
  if (st && st.rows.length) ensureData();
  if (needDraw) { needDraw = false; try { draw(); } catch (e) { console.warn('[draw]', e && e.message); } }
  requestAnimationFrame(tick);
}
requestAnimationFrame(tick);
/* 全局重绘钩子: 标注/面板数据更新后调用 window.okxRedraw() 立即重画K线(跨文件可靠触发) */
window.okxRedraw = function () { needDraw = true; };
