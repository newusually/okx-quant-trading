/* nqall_chart.js — 绘图层: K线主图 + MACD副图 + 十字线悬浮窗 + 状态栏 (只画不算业务数据)
 * 依赖: nqall_core.js(ctx/geom/fmt) + nqall_data.js(S/TF/right/count/IV)
 *
 * 【文件职责】唯一的绘图函数 draw(): 把 nqall_data.js 缓冲的数据画成完整K线图, 每帧由主循环按需调用。
 * 【功能清单】1. 可视区间价格/MACD幅度计算(含形成中K线); 2. 网格+价格轴+时间轴;
 *             3. K线蜡烛绘制(含半透明形成中K线); 4. MACD柱+DIF/DEA线;
 *             5. 买卖平仓标注(🚀买入火箭/▲加仓金三角/🍃平仓绿叶+盈利额, 二分对齐K线);
 *             6. 十字线+OHLC悬浮窗; 7. 状态栏(缓冲根数/聚合/区间/标注数)。
 */
function draw() {                                        // 主绘图函数: 每帧重画整幅K线图
  const st = S[TF], D = st ? st.rows : null;             // 当前周期状态与K线行数组
  if (!D || !D.length) return;                           // 无数据不绘制
  const g = geom(); ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.clearRect(0, 0, W, H);  // 取几何, 按DPR设置变换, 清空画布
  const first = Math.max(0, Math.floor(right - count + 1)), last = Math.min(D.length - 1, Math.floor(right));  // 可视区间起止K线下标
  if (last < first) return;                              // 区间无效(数据边界)直接返回
  let ph = -1e18, pl = 1e18;                             // 视口内最高价/最低价初值(正负无穷)
  for (let i = first; i <= last; i++) { if (D[i][2] > ph) ph = D[i][2]; if (D[i][3] < pl) pl = D[i][3]; }  // 扫描可视K线求高低极值(D[i][2]高/D[i][3]低)
  // 形成中的K线纳入视野
  const F = st.forming;                                  // 形成中的K线对象
  const hasF = F && F.t === D[last][0] + IV[TF];         // 形成中K线恰好接在最后一根之后才算有效
  if (hasF) { if (F.h > ph) ph = F.h; if (F.l < pl) pl = F.l; }  // 形成中K线的高低也纳入极值
  const pad = (ph - pl) * 0.07 || 1; ph += pad; pl -= pad;  // 价格上下各留7%边距(极窄区间时兜底1)
  const ky = v => g.kTop + (ph - v) / (ph - pl) * g.kH;  // 价格→主图y坐标换算函数
  let mm = 0; for (let i = first; i <= last; i++) { const a = Math.max(Math.abs(D[i][5]), Math.abs(D[i][6]), Math.abs(D[i][7])); if (a > mm) mm = a; }  // 求MACD三列(DIF/DEA/柱)的最大绝对值定副图刻度
  if (hasF) mm = Math.max(mm, Math.abs(F.m));            // 形成中K线的MACD柱也纳入
  mm *= 1.15; if (mm <= 0) mm = 1;                       // 放大15%留边距, 全零时兜底1
  const my0 = v => g.mTop + g.mH / 2 - v / mm * (g.mH / 2);  // MACD值→副图y坐标(0轴居中)
  const xAt = i => g.x1 - (right - i) * g.pxBar;         // K线下标→屏幕x坐标
  const bw = Math.max(1, g.pxBar * 0.72);                // 蜡烛实体宽度(占K线间距72%, 至少1px)
  // 网格+价格轴
  ctx.font = '11px Consolas,monospace'; ctx.textBaseline = 'middle';  // 轴文字字体与垂直居中基线
  const steps = [0.0001, 0.0002, 0.0005, 0.001, 0.002, 0.005, 0.01, 0.02, 0.05, 0.1, 0.2, 0.5, 1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000];  // 候选刻度步长(1/2/5数列)
  let stp = steps.find(s => (ph - pl) / s <= 8) || 5000;  // 选第一个使纵向≤8格的步长
  ctx.strokeStyle = GRID; ctx.fillStyle = AXX; ctx.lineWidth = 1;  // 网格线颜色/轴字颜色/线宽1
  for (let v = Math.ceil(pl / stp) * stp; v <= ph; v += stp) {  // 从区间内第一个整刻度画到顶
    const y = ky(v); ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(g.x1, y); ctx.stroke();  // 画一条横向网格线
    ctx.textAlign = 'left'; ctx.fillText(fmtP(v), W - AXW + 6, y);  // 右侧价格轴标注该刻度值
  }
  // 时间轴
  ctx.textAlign = 'center';                              // 时间文字居中对齐
  const kStep = Math.max(1, Math.ceil(95 / g.pxBar));     // 时间标签间隔根数(约95px一个标签)
  for (let i = first; i <= last; i++) {                  // 遍历可视K线
    if ((i - first) % kStep !== 0) continue;             // 不在标签间隔上的跳过
    const d = new Date((D[i][0] + 8 * 3600) * 1000);     // 该K线时间戳转北京时间Date
    const dayB = d.getUTCHours() === 0 && d.getUTCMinutes() === 0;  // 整点00:00视为日分界
    const x = xAt(i);                                    // 该K线的x坐标
    ctx.strokeStyle = GRID; ctx.beginPath(); ctx.moveTo(x, g.kTop); ctx.lineTo(x, H - 26); ctx.stroke();  // 画纵向网格线(贯穿主图到副图底)
    ctx.fillStyle = AXX;                                 // 轴字颜色
    ctx.fillText(dayB ? (d.getUTCMonth() + 1) + '-' + d.getUTCDate() : ('' + d.getUTCHours()).padStart(2, '0') + ':' + ('' + d.getUTCMinutes()).padStart(2, '0'), x, H - 13);  // 日分界显示"月-日", 其余显示"时:分"
  }
  // K线
  function candle(i, t, o, h, l, c) {                    // 画一根蜡烛(参数: 下标/时间/开高低收)
    const x = xAt(i), up = c >= o;                       // x坐标与阴阳判定(收≥开为阳)
    ctx.strokeStyle = ctx.fillStyle = up ? UP : DN; ctx.lineWidth = Math.min(1.4, bw * 0.15 + 0.5);  // 阳红阴绿, 影线线宽随实体宽自适应
    ctx.beginPath(); ctx.moveTo(x, ky(h)); ctx.lineTo(x, ky(l)); ctx.stroke();  // 画最高到最低的影线
    const y1 = ky(Math.max(o, c)), y2 = ky(Math.min(o, c)), hh = Math.max(1, y2 - y1);  // 实体上下沿y与高度(至少1px)
    if (bw <= 1.6) ctx.fillRect(x - bw / 2, y1, bw, hh);  // 极窄时阴阳都填充实块
    else if (up) ctx.strokeRect(x - bw / 2, y1, bw, hh);  // 阳线画空心矩形
    else ctx.fillRect(x - bw / 2, y1, bw, hh);            // 阴线填充实心矩形
  }
  for (let i = first; i <= last; i++) candle(i, D[i][0], D[i][1], D[i][2], D[i][3], D[i][4]);  // 逐根画可视K线
  if (hasF) { // 形成中: 半透明
    ctx.globalAlpha = 0.55; candle(last + 1, F.t, F.o, F.h, F.l, F.c); ctx.globalAlpha = 1;  // 形成中K线以55%透明度画在最后一根之后
  }
  // MACD
  ctx.strokeStyle = '#d0d7e2'; ctx.beginPath(); ctx.moveTo(0, g.mTop + g.mH / 2); ctx.lineTo(g.x1, g.mTop + g.mH / 2); ctx.stroke();  // 画MACD零轴线
  function macdBar(i, m) { const x = xAt(i); ctx.fillStyle = m >= 0 ? UP : DN;  // MACD柱: 正值红负值绿
    const y = my0(Math.max(m, 0)), y2 = my0(Math.min(m, 0));  // 柱的两端y(从零轴起)
    ctx.fillRect(x - Math.max(0.5, bw * 0.35), y, Math.max(1, bw * 0.7), Math.max(1, y2 - y)); }  // 画柱(宽为实体的一半)
  for (let i = first; i <= last; i++) macdBar(i, D[i][7]);  // 逐根画MACD柱(D[i][7])
  if (hasF) macdBar(last + 1, F.m);                      // 形成中K线的MACD柱
  function line(f, col) { ctx.strokeStyle = col; ctx.lineWidth = 1.2; ctx.beginPath(); let st2 = false;  // 画一条MACD折线(参数: 取值函数/颜色)
    for (let i = first; i <= last; i++) { const px = xAt(i), y = my0(f(i)); if (!st2) { ctx.moveTo(px, y); st2 = true; } else ctx.lineTo(px, y); }  // 逐点连线
    if (hasF) { ctx.lineTo(xAt(last + 1), my0(f(last + 1))); }  // 形成中K线也纳入线尾
    ctx.stroke(); }                                      // 描边完成
  line(i => D[i][5], DIFC); line(i => D[i][6], DEAC);    // 画DIF线(D[i][5])与DEA线(D[i][6])
  ctx.fillStyle = AXX; ctx.textAlign = 'left';           // 副图刻度文字
  ctx.fillText(mm.toFixed(2), W - AXW + 6, g.mTop + 8); ctx.fillText((-mm).toFixed(2), W - AXW + 6, g.mTop + g.mH - 6); ctx.fillText('0', W - AXW + 6, g.mTop + g.mH / 2);  // 标注副图正负刻度上限与0
  ctx.fillStyle = DIFC; ctx.fillText('DIF', W - AXW + 6, g.mTop + 24); ctx.fillStyle = DEAC; ctx.fillText('DEA', W - AXW + 6, g.mTop + 38);  // 副图右上角DIF/DEA图例文字
  // ===== 买卖平仓标注 (window.OKX_MARKS: [{t(ms),kind:buy/add/close,px,profit}]) — emoji版 🚀买入 🍃平仓 ▲加仓 =====
  const MK = window.OKX_MARKS;                           // 全局买卖标注数组(okx_panel.js加载)
  let mkN = 0;                                           // 落在当前视野内的标注计数
  if (MK && MK.length) {                                 // 有标注才绘制
    const bSec = st.bucket * 60;                         // 单根K线时长(秒)
    const t0 = D[first][0], t1 = D[last][0] + IV[TF];    // 视口时间范围(首根开~末根+周期)
    ctx.textBaseline = 'middle';                         // 文字垂直居中
    for (const m of MK) {                                // 遍历每条标注
      const mt = Math.round(m.t / 1000);                 // 标注时间毫秒→秒
      if (mt < t0 - bSec * 2 || mt > t1 + bSec * 2) continue;  // 时间超出视口(±2根容差)跳过
      // 二分找最近K线
      let lo = first, hi = last;                         // 二分区间
      while (lo < hi) { const mid = (lo + hi) >> 1; if (D[mid][0] < mt) lo = mid + 1; else hi = mid; }  // 找第一个时间≥mt的K线
      let idx = (lo > first && Math.abs(D[lo - 1][0] - mt) < Math.abs(D[lo][0] - mt)) ? lo - 1 : lo;  // 与前一根比较取时间更近者
      if (Math.abs(D[idx][0] - mt) > bSec * 2) continue;  // 距最近K线超过2根时长 → 视为不在本周期, 跳过
      mkN++;                                             // 有效标注计数+1
      const onF = hasF && idx === last && mt > D[last][0];  // 标注落在形成中K线的时间段内
      const x = xAt(idx) + (onF ? g.pxBar : 0);          // x坐标(落在形成中K线则右移一根)
      ctx.textAlign = 'center';                          // 标注文字居中
      if (m.kind === 'buy' || m.kind === 'add') {          // 买入🚀红火箭(矢量) / 加仓金▲: 低点下方
        const y = ky(D[idx][3]) + 18;                    // 基准y=该K线低点下方18px
        if (m.kind === 'buy') {                          // 买入标注: 画矢量火箭
          ctx.fillStyle = '#e03131'; ctx.strokeStyle = '#fff'; ctx.lineWidth = 1.5;  // 红色箭体+白描边
          ctx.beginPath();                                  // 箭体
          ctx.moveTo(x, y - 11);                            // 箭体顶尖
          ctx.quadraticCurveTo(x + 6, y - 3, x + 4, y + 6);  // 右侧弧线
          ctx.lineTo(x - 4, y + 6);                         // 底边
          ctx.quadraticCurveTo(x - 6, y - 3, x, y - 11);    // 左侧弧线
          ctx.closePath(); ctx.fill(); ctx.stroke();        // 闭合填色描边
          ctx.fillStyle = '#ff922b';                        // 尾焰
          ctx.beginPath(); ctx.moveTo(x - 3, y + 7); ctx.lineTo(x, y + 13); ctx.lineTo(x + 3, y + 7); ctx.closePath(); ctx.fill();  // 画三角尾焰
          ctx.fillStyle = '#fff';                           // 舷窗
          ctx.beginPath(); ctx.arc(x, y - 2, 2, 0, 6.283); ctx.fill();  // 画白色小圆舷窗
          ctx.lineWidth = 1;                                // 恢复默认线宽
        } else {                                          // 加仓标注: 画金色实心▲
          ctx.font = 'bold 12px "Microsoft YaHei",sans-serif';  // 字体(供后续文字)
          ctx.fillStyle = '#e6a817'; ctx.strokeStyle = '#fff'; ctx.lineWidth = 2;  // 金色填充+白描边
          ctx.beginPath(); ctx.moveTo(x, y - 7); ctx.lineTo(x - 6, y + 4); ctx.lineTo(x + 6, y + 4); ctx.closePath();  // 画三角路径
          ctx.stroke(); ctx.fill();                        // 先描边后填充
        }
      } else if (m.kind === 'close') {                     // 平仓🍃绿叶子(矢量): 高点上方+盈利额(盈红亏绿)
        const win = (m.profit || 0) >= 0;                  // 是否盈利
        const col = win ? '#e03131' : '#2f9e44';           // 盈利红色/亏损绿色
        const py = (m.px > 0 && m.px < ph && m.px > pl) ? ky(m.px) : ky(D[idx][2]);  // 平仓价在视野内用其y, 否则用收盘价y
        const y = Math.min(py, ky(D[idx][2])) - 22;        // 叶子基准y=两者较高者再上移22px
        ctx.fillStyle = '#2f9e44'; ctx.strokeStyle = '#e9fbee'; ctx.lineWidth = 1;  // 绿叶+浅绿描边
        ctx.beginPath();                                  // 叶片: 两段贝塞尔
        ctx.moveTo(x, y + 10);                             // 叶尖起点
        ctx.quadraticCurveTo(x - 10, y + 3, x, y - 9);     // 左半叶弧线
        ctx.quadraticCurveTo(x + 10, y + 3, x, y + 10);    // 右半叶弧线
        ctx.closePath(); ctx.fill(); ctx.stroke();         // 闭合填色描边
        ctx.strokeStyle = '#e9fbee';                      // 叶脉
        ctx.beginPath(); ctx.moveTo(x, y + 8); ctx.lineTo(x, y - 6); ctx.stroke();  // 画中间竖叶脉
        ctx.font = 'bold 11px "Microsoft YaHei",sans-serif';  // 盈利额文字字体
        ctx.fillStyle = col; ctx.strokeStyle = 'rgba(255,255,255,.9)'; ctx.lineWidth = 3;  // 文字色+白描边(保证可读)
        const txt = (win ? '+' : '') + (m.profit || 0).toFixed(2) + 'U';  // 盈利额文本如"+2.35U"
        ctx.strokeText(txt, x, y - 17); ctx.fillText(txt, x, y - 17);  // 先白边后填色画文字
        ctx.lineWidth = 1;                                 // 恢复默认线宽
      }
    }
    ctx.font = '11px Consolas,monospace'; ctx.lineWidth = 1;  // 恢复默认字体与线宽
  }
  // 十字线+悬浮窗
  if (inChart && mx >= 0 && mx <= g.x1 && my >= 0 && my < H - 26) {  // 鼠标在绘图区内才画十字线
    let idx = Math.max(0, Math.min(D.length - 1, idxAt(mx)));  // 鼠标x对应的K线下标(夹在有效范围)
    const overF = hasF && idxAt(mx) > last;              // 鼠标悬停在形成中K线上
    const t = overF ? F.t : D[idx][0], o = overF ? F.o : D[idx][1], h = overF ? F.h : D[idx][2], l = overF ? F.l : D[idx][3], c = overF ? F.c : D[idx][4];  // 取该K线开高低收(形成中用F)
    const dd = overF ? F.d : D[idx][5], ee = overF ? F.e : D[idx][6], mv = overF ? F.m : D[idx][7];  // 取DIF/DEA/MACD柱
    ctx.setLineDash([4, 4]); ctx.strokeStyle = '#94a3b8';  // 虚线样式灰色
    ctx.beginPath(); ctx.moveTo(mx, g.kTop); ctx.lineTo(mx, H - 26); ctx.stroke();  // 竖向十字线
    ctx.beginPath(); ctx.moveTo(0, my); ctx.lineTo(g.x1, my); ctx.stroke(); ctx.setLineDash([]);  // 横向十字线并恢复实线
    if (my < g.mTop) { const v = ph - (my - g.kTop) / g.kH * (ph - pl); ctx.fillStyle = '#5b6b80'; ctx.fillText(fmtP(v), W - AXW + 6, my); }  // 十字线在主图: 轴上标对应价格
    else { const v = (g.mTop + g.mH / 2 - my) / (g.mH / 2) * mm; ctx.fillStyle = '#5b6b80'; ctx.fillText(v.toFixed(3), W - AXW + 6, my); }  // 在副图: 轴上标对应MACD值
    const pi = overF ? D.length - 1 : Math.max(0, idx - 1);  // 用于涨幅比较的"前一根"下标
    const pc = pi >= 0 && pi < D.length ? D[pi][4] : o;  // 前收盘价(无前一根则用开盘价)
    const chg = (c / pc - 1) * 100, amp = (h - l) / pc * 100, clsc = c >= pc;  // 相对前一根的涨幅/振幅/收涨标志
    tip.innerHTML = '<div class="t">' + fmtDT(t) + (overF ? ' · <span style="color:#b45309">形成中</span>' : '') + '</div>'  // 悬浮窗首行: 时间(形成中加橙色标记)
      + '开 <b>' + fmtP(o) + '</b> · 高 <b class="up">' + fmtP(h) + '</b><br>'  // 开/高行
      + '低 <b class="dn">' + fmtP(l) + '</b> · 收 <b class="' + (clsc ? 'up' : 'dn') + '">' + fmtP(c) + '</b><br>'  // 低/收行(收涨红跌绿)
      + '涨幅 <b class="' + (chg >= 0 ? 'up' : 'dn') + '">' + (chg >= 0 ? '+' : '') + chg.toFixed(3) + '%</b> · 振幅 <b>' + amp.toFixed(3) + '%</b><br>'  // 涨幅/振幅行
      + '<span style="color:' + DIFC + '">DIF ' + dd.toFixed(4) + '</span> · <span style="color:' + DEAC + '">DEA ' + ee.toFixed(4) + '</span><br>'  // DIF/DEA行
      + 'MACD柱 <b style="color:' + (mv >= 0 ? UP : DN) + '">' + mv.toFixed(4) + '</b>';  // MACD柱行
    tip.style.display = 'block';                         // 显示悬浮窗
    const tw = tip.offsetWidth;                          // 悬浮窗宽度(用于防溢出翻转)
    tip.style.left = (mx + 18 + tw > W - 10 ? mx - 18 - tw : mx + 18) + 'px';  // 默认在鼠标右侧, 靠右溢出时翻到左侧
    tip.style.top = Math.min(Math.max(10, my - 30), H - tip.offsetHeight - 10) + 'px';  // 垂直跟随并夹在图区内
  } else tip.style.display = 'none';                     // 鼠标不在图内隐藏悬浮窗
  // 状态栏
  document.getElementById('stat').textContent = '缓冲' + D.length.toLocaleString() + '根'  // 状态栏: 缓冲K线根数
    + (st.bucket > IV[TF] / 60 ? (' · 聚合' + st.bucket + '分钟') : '')  // 由小周期聚合而成时显示聚合分钟数
    + (st.exhaustOld ? ' · 已到最早' : '')               // 历史已拉完标志
    + ' · ' + fmtT(D[first][0]) + ' ~ ' + fmtT(hasF ? F.t : D[last][0])  // 缓冲覆盖的时间区间
    + (MK && MK.length ? (' · 买卖标注' + mkN + '个(7天内)') : '');  // 视野内标注数量(近7天)
}
