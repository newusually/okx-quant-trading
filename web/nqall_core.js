/* nqall_core.js — 主图核心: DOM引用 / 画布状态 / 几何 / 时间格式 / 主循环 (不含业务计算)
 * 文件分工: nqall_core.js(本文件) → nqall_data.js(数据/轮询) → nqall_chart.js(绘图) → nqall_input.js(交互)
 *
 * 【文件职责】K线组件的地基: 全局常量/DOM引用/画布尺寸/几何换算/时间价格格式化/主渲染循环/JS错误陷阱。
 * 【功能清单】1. 颜色与坐标轴宽度常量; 2. showJsErr+onerror+unhandledrejection 错误直接画上页面;
 *             3. resize() 画布自适应(含devicePixelRatio高清适配);
 *             4. fmtDT/fmtT/fmtP 时间价格格式化; 5. geom()/idxAt() 几何换算;
 *             6. tick() 主循环(边缘自动滚动+按需拉数据+按需重绘); 7. window.okxRedraw 全局重绘钩子。
 */
const UP = '#d5382f', DN = '#0a8f4e', AXX = '#8795a8', GRID = '#e8edf4', DIFC = '#d98b0b', DEAC = '#4a7dd6';  // 全局配色: 阳线红/阴线绿/轴字灰/网格浅灰/DIF橙/DEA蓝
const AXW = 64;                                          // 右侧价格坐标轴区宽度(像素)
const cv = document.getElementById('cv'), ctx = cv.getContext('2d');  // 画布元素与2D绘图上下文
const tip = document.getElementById('tip'), chart = document.getElementById('chart');  // 十字线悬浮窗与图容器
const fetchHint = document.getElementById('fetch');      // 取数提示角标(拉分页数据时显示)
let W = 0, H = 0, dpr = 1, needDraw = true;              // 画布宽高/设备像素比/重绘请求标志
let mx = -1, my = -1, inChart = false, dragging = false, dragX = 0, dragRight = 0, edgeDir = 0;  // 鼠标状态: 坐标/是否在图内/是否拖动/拖动起点/拖动时right值/边缘滚动方向

/* JS错误陷阱(2026-09-28g): 任何脚本错误直接画到页面上, 不再只进控制台 */
function showJsErr(msg) {                                // 在K线图左上角显示红色JS错误框
  let box = document.getElementById('jserr');            // 已有错误框则复用
  if (!box) {                                            // 没有则动态创建
    box = document.createElement('div'); box.id = 'jserr';  // 创建div并指定id
    box.style.cssText = 'position:absolute;left:8px;top:44px;z-index:99;max-width:90%;background:#fff3f3;border:2px solid #e03131;border-radius:8px;padding:8px 12px;font:12px/1.6 Consolas,"Microsoft YaHei",monospace;color:#c92a2a;white-space:pre-wrap;box-shadow:0 4px 14px rgba(160,30,30,.25)';  // 红边白底等宽字错误框样式
    chart.appendChild(box);                              // 挂到图容器上
  }
  box.textContent = ('' + msg).slice(0, 600);            // 写入错误信息(最长600字符防刷屏)
  const ld = document.getElementById('load'); if (ld) ld.style.display = 'none';  // 出错时隐藏"加载中"遮罩
}
window.onerror = function(msg, src, line, col) {         // 全局同步JS错误钩子
  showJsErr('⚠ JS错误: ' + msg + '\n@ ' + (src || '?').split('/').pop() + ':' + line + ':' + col);  // 显示错误与源文件行号
  return false;                                          // 返回false走浏览器默认控制台输出
};
window.addEventListener('unhandledrejection', e => {     // 未捕获的Promise拒绝钩子(AJAX失败等)
  showJsErr('⚠ 请求失败: ' + (e.reason && e.reason.message || e.reason));  // 显示失败原因
});

function resize() { dpr = window.devicePixelRatio || 1; W = chart.clientWidth; H = chart.clientHeight; cv.width = W * dpr; cv.height = H * dpr; needDraw = true; }  // 窗口尺寸变化: 按DPR重设画布物理尺寸并请求重绘
window.addEventListener('resize', resize); resize();     // 监听窗口缩放 + 初始执行一次

function fmtDT(s) { const d = new Date((s + 8 * 3600) * 1000); const p = x => ('' + x).padStart(2, '0'); return d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate()) + ' ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()); }  // 秒时间戳→"YYYY-MM-DD HH:MM"(UTC+8北京时间)
function fmtT(s) { const d = new Date((s + 8 * 3600) * 1000); const p = x => ('' + x).padStart(2, '0'); return p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate()) + ' ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()); }  // 秒时间戳→"MM-DD HH:MM"(短格式)
/* 价格格式: 小数点后4位(1~1000区间币种), 大市值2位, 微价币6位; 不加千分位(2026-09-28 用户指令) */
function fmtP(v) { v = +v; if (!isFinite(v)) return ''; const a = Math.abs(v);  // 价格格式化入口(非法值返回空串)
  return v.toFixed(a >= 1000 ? 2 : (a >= 1 ? 4 : 6)); }  // ≥1000两位小数, ≥1四位, 微价币六位

function geom() { const plotW = W - AXW, macdH = Math.max(110, H * 0.30), kTop = 8, kH = H - macdH - kTop - 26, mTop = H - macdH - 14, mH = macdH - 10;  // 计算布局: 绘图区宽/MACD副图高(至少110px)/主图顶边与高度/副图顶边与高度
  return { plotW, macdH, kTop, kH, mTop, mH, pxBar: plotW / count, x0: 0, x1: W - AXW }; }  // 返回几何对象: pxBar=每根K线占宽, x1=绘图区右边界
function idxAt(x) { const g = geom(); return Math.round(right - (g.x1 - x) / g.pxBar); }  // 屏幕x坐标→K线下标(用于十字线定位)

// 主循环: 边缘自动滚动 + 按需拉取 + 重绘 (draw在nqall_chart.js, ensureData在nqall_data.js)
function tick() {                                        // 主渲染循环(每帧执行)
  // 数据层(nqall_data.js)可能尚未执行完成, 此时 S/TF 还不存在(旧内核还会抛 ReferenceError 弹红框)
  if (typeof S === 'undefined' || typeof TF === 'undefined') { requestAnimationFrame(tick); return; }  // 数据层未就绪则下一帧再试
  const st = S[TF];                                      // 当前周期的数据状态对象
  if (st && st.rows.length && edgeDir !== 0 && !dragging && !st.fetching) {  // 鼠标贴边且未在拖动/拉数 → 自动滚动
    right += Math.max(1.5, count / 45) * edgeDir;        // 每帧滚动量与可见根数成正比(方向由edgeDir决定)
    clampRight(); needDraw = true;                       // 夹住right边界并请求重绘
  }
  if (st && st.rows.length) ensureData();                // 有数据时检查是否需要按需拉取(翻页/新K线)
  if (needDraw) { needDraw = false; try { draw(); } catch (e) { console.warn('[draw]', e && e.message); } }  // 有重绘请求则重绘一次(draw异常只警告不杀循环)
  requestAnimationFrame(tick);                           // 请求下一帧
}
requestAnimationFrame(tick);                             // 启动主循环
/* 全局重绘钩子: 标注/面板数据更新后调用 window.okxRedraw() 立即重画K线(跨文件可靠触发) */
window.okxRedraw = function () { needDraw = true; };     // 对外暴露: 置重绘标志, 下一帧生效
