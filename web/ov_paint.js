/* ov_paint.js — 画图层: 0.618 + 视口高低 + 每转折牛顿标注 (无缠论画线)
 * 标注: KE=½mv² · g=v²/2h · F=mg(牛二) · 尖转折金▲
 * 无未来函数: 全部数值只用转折点之前的数据; 不画反F(牛三需出笔=未来数据)
 * 所有转折点都标数值(不再按KE大小过滤)
 *
 * 【文件职责】力学覆盖层的绘制: 在主图K线上叠加0.618黄金分割线/视口高低点标注/尖转折金色▲与牛顿力学标注。
 * 【功能清单】OV.paint(): ①0.618分割线+视口最高/最低文字; ②尖转折(底部动能前25%)金▲+KE/g/F数值标注。
 */
window.OV = window.OV || {};                             // 全局覆盖层命名空间(与ov_core.js共享)

OV.paint = function () {                                 // 覆盖层绘制入口(由包装后的draw在每帧主图绘制后调用)
  const st = S[TF], D = OV.data[TF];                     // 当前周期K线状态与该周期转折点缓存
  if (!st || !st.rows || !st.rows.length || !D || !D.piv.length) return;  // 无K线或无转折点不绘制
  const rows = st.rows, n = rows.length, iv = IV[TF];    // K线行数组/总根数/当前周期秒数
  const g = geom();                                      // 几何布局对象
  const first = Math.max(0, Math.floor(right - count + 1)), last = Math.min(n - 1, Math.floor(right));  // 可视区间起止下标
  if (last < first) return;                              // 区间无效直接返回
  // 价格刻度(与主图draw一致, 含形成中K线)
  let ph = -1e18, pl = 1e18;                             // 视口极值初值
  for (let i = first; i <= last; i++) { if (rows[i][2] > ph) ph = rows[i][2]; if (rows[i][3] < pl) pl = rows[i][3]; }  // 扫描求最高/最低
  const F = st.forming, hasF = F && F.t === rows[last][0] + iv;  // 形成中K线有效性判定
  if (hasF) { if (F.h > ph) ph = F.h; if (F.l < pl) pl = F.l; }  // 形成中高低纳入极值
  const fibH = ph, fibL = pl;                            // 0.618计算用的原始极值(未加边距)
  const pad = (ph - pl) * 0.07 || 1; ph += pad; pl -= pad;  // 上下各加7%边距(与主图一致)
  const ky = v => g.kTop + (ph - v) / (ph - pl) * g.kH;  // 价格→y坐标换算
  const xAt = i => g.x1 - (right - i) * g.pxBar;         // 行下标→x坐标
  const pv = D.piv;                                      // 转折点数组
  // pivot行下标→x (行下标与rows一一对应, 直接xAt)
  const fs = Math.min(16, Math.max(13.5, g.pxBar * 1.5));  // 标注字号随K线间距自适应(13.5~16px)

  ctx.save();                                            // 保存绘图状态(绘制完恢复, 不污染主图)
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);                // 按DPR设置变换
  // ① 0.618 黄金分割 + 视口最高/最低
  if (fibH - fibL > 1e-9) {                              // 价格区间有效才画
    const p618 = fibL + (fibH - fibL) * 0.618;           // 0.618回撤位价格
    const y618 = ky(p618);                               // 对应y坐标
    ctx.setLineDash([8, 5]);                             // 虚线样式
    ctx.strokeStyle = '#e6a817';                         // 金色线
    ctx.lineWidth = 2;                                   // 线宽2
    ctx.beginPath(); ctx.moveTo(0, y618); ctx.lineTo(g.x1, y618); ctx.stroke();  // 画横向0.618虚线
    ctx.setLineDash([]);                                 // 恢复实线
    ctx.font = 'bold 12px Consolas,monospace';           // 0.618标签字体
    ctx.textAlign = 'right';                             // 右对齐
    ctx.fillStyle = '#b07708';                           // 深金色文字
    ctx.fillText('0.618  ' + p618.toFixed(2), g.x1 - 4, y618 - 5);  // 在线左上方标价格
    ctx.font = 'bold 11px Consolas,monospace';           // 高低点标签字体
    ctx.textAlign = 'center';                            // 居中对齐
    let hiIdx = first, loIdx = first;                    // 最高/最低所在K线下标
    for (let i = first; i <= last; i++) { if (rows[i][2] > rows[hiIdx][2]) hiIdx = i; if (rows[i][3] < rows[loIdx][3]) loIdx = i; }  // 找视口内最高/最低的K线位置
    ctx.fillStyle = '#c04a3a';                           // 高点文字暗红
    ctx.fillText('最高 ' + fibH.toFixed(2), xAt(hiIdx), ky(fibH) + 14);  // 在最高K线下方标"最高 价格"
    ctx.fillStyle = '#0a7a44';                           // 低点文字深绿
    ctx.fillText('最低 ' + fibL.toFixed(2), xAt(loIdx), ky(fibL) - 6);   // 在最低K线上方标"最低 价格"
  }
  // ② 牛顿标注 — 只显示尖转折(底部动能前25%): 金色▲+金色KE/g/F; 其余灰色全部隐藏
  const th = D.th;                                       // 尖转折KE阈值(C++算出的前25%分位)
  ctx.textAlign = 'center';                              // 标注文字居中
  for (let k = 0; k < pv.length; k++) {                  // 遍历每个转折点
    const o = pv[k];                                     // 单个转折点
    if (o.i < first || o.i > last || o.ke === undefined) continue;  // 不在视口或缺KE值跳过
    const x = xAt(o.i);                                  // 该转折点x坐标
    if (x < 30 || x > g.x1 + 40) continue;               // 超出绘图区(含右溢容差)跳过
    const y = ky(o.p);                                   // 该转折点价格y坐标
    const isBot = o.type === 0;                          // 是否底部转折(type=0)
    if (!isBot || o.ke < th) continue;                                 // 顶/普通底: 灰字隐藏
    const txt = 'KE=' + o.ke.toExponential(1);           // 动能标注文本(科学计数1位小数)
    const txt2 = 'g=' + (o.g == null ? '-' : o.g.toExponential(1))  // 重力加速度标注(空值显示-)
      + ' F=' + (o.F == null ? '-' : o.F.toExponential(1));  // 力(牛二)标注
    ctx.font = 'bold ' + fs + 'px Consolas,sans-serif';  // 自适应字号粗体
    ctx.fillStyle = '#b07708';                           // 金色文字
    ctx.fillText(txt, x, y + 38);                        // 画KE行(低点下方38px)
    ctx.fillText(txt2, x, y + 54);                       // 画g/F行(下方54px)
    const s = 9;                                                       // 金色▲
    ctx.fillStyle = '#e6a817';                           // 亮金色
    ctx.beginPath();                                     // 开始画三角
    ctx.moveTo(x, y + 8); ctx.lineTo(x - s, y + 8 + s * 1.5); ctx.lineTo(x + s, y + 8 + s * 1.5);  // ▲三顶点(低点下方)
    ctx.closePath(); ctx.fill();                         // 闭合填充
  }
  ctx.restore();                                         // 恢复绘图状态
};
