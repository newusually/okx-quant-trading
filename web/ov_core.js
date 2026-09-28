/* ov_core.js — 力学覆盖层·取数与调度 (2026-09-28 计算全量下沉 C++)
 * 计算已全部在 C++ (sigcore.cpp pivots_full → apihub /sigs): 阈值ZigZag / ATR14 / KE=½mv² /
 * g=v²/2h / F=mg(牛二) / 角度 / 二阶导 / 尖转折阈值(底部KE前25%)。
 * 前端只做: ①请求 ②按时间戳对齐到当前K线缓冲 ③交给 ov_paint.js 画图。
 * 文件分工: ov_core.js 取数+调度+图例 · ov_paint.js 画图 (原 ov_pivot.js/ov_physics.js 已删除)
 *
 * 【文件职责】力学转折覆盖层的前端调度: 请求C++算好的转折点, 按时间戳对齐K线缓冲, 维护缓存与图例, 包装draw()挂载绘制。
 * 【功能清单】1. ovRowAt() 时间戳二分对齐; 2. OV.compute() 带缓存的取数调度(每周期一份);
 *             3. ovInitUI() 图例DOM创建(尖转折说明+计数); 4. IIFE包装主图draw(): 主循环末尾追加覆盖层绘制(带保护壳)。
 */
window.OV = window.OV || {};                             // 全局覆盖层命名空间(跨文件共享)
OV.data = {};                              // tf -> {piv:[...], th, key}  // 各周期的转折点缓存: 键=周期, 值={转折点数组,尖转折阈值,数据版本key}

/* 时间戳 → 当前缓冲行下标(二分; rows 按 t 升序)。找不到返回 -1 */
function ovRowAt(rows, t) {                              // 二分查找时间戳t在K线缓冲中的下标
  let lo = 0, hi = rows.length - 1;                      // 二分区间
  while (lo <= hi) {                                     // 标准二分
    const mid = (lo + hi) >> 1;                          // 中点
    if (rows[mid][0] === t) return mid;                  // 命中 → 返回下标
    if (rows[mid][0] < t) lo = mid + 1; else hi = mid - 1;  // 缩小区间
  }
  return -1;                                             // 不在缓冲内
}

/* 重算调度: rows 内容变了才请求(新K线/翻页加载), 否则每帧直接用缓存 */
OV.compute = function (tf) {                             // 保证tf周期的转折点数据最新(每帧调用, 内部有缓存)
  const st = S[tf];                                      // 该周期K线状态
  if (!st || !st.rows || st.rows.length < 50) return;    // 数据不足50根不做力学计算
  const rows = st.rows;                                  // K线行数组
  const key = rows.length + '_' + rows[rows.length - 1][0];  // 数据版本key=根数+最新K线时间戳
  const d = OV.data[tf];                                 // 已有缓存
  if (d && d.key === key) return;                        // key相同(数据没变)直接用缓存
  if (d && d.pending === key) return;                    // 同一 key 不重复请求
  OV.data[tf] = { piv: (d && d.piv) || [], th: (d && d.th) || 0, key: d ? d.key : 0, pending: key };  // 先记下pending标记, 保留旧数据用于绘制
  const inst = window.OKX_INST || '';                    // 当前合约
  const url = (window.AH || '') + '/sigs?bar=' + encodeURIComponent(tf) + '&limit=500'  // 请求C++算好的转折点(最多500个)
            + (inst ? '&inst=' + encodeURIComponent(inst) : '');  // 带上合约参数
  fetch(url, { signal: (typeof tmo === 'function' ? tmo(6000) : undefined) })  // 发起请求(6秒超时, 若全局有tmo辅助函数)
    .then(r => r.json())                                 // 解析JSON
    .then(function (j) {                                 // 拿到响应
      if (!j || !j.ok || !j.data) return;                // 响应异常直接丢弃
      const cur = S[tf];                                 // 重新读当前缓冲(请求期间可能已变)
      if (!cur || !cur.rows) return;                     // 缓冲没了则放弃
      const rw = cur.rows;                               // K线行数组
      const piv = [];                                    // 对齐后的转折点数组
      for (let k = 0; k < j.data.length; k++) {          // 遍历C++返回的转折点
        const o = j.data[k];                             // 单个转折点原始对象
        // /sigs 的 t 是毫秒(与 OKX 原始一致), 前端缓冲 rows[i][0] 是秒 → 归一到秒
        const ts = o.t > 1e12 ? Math.floor(o.t / 1000) : o.t;  // 毫秒时间戳除1000归一为秒
        const i = ovRowAt(rw, ts);                        // 按时间戳对齐
        if (i < 0) continue;                              // 不在当前缓冲内 → 略过
        piv.push({ t: ts, i: i, p: o.p, type: o.type,     // 存入对齐结果: 时间/行下标/价格/类型(0底1顶)
                   ke: o.ke, g: o.g, F: o.f, v: o.v, m: o.m, ang: o.ang, a: o.a, bars: o.bars });  // 力学量: 动能/重力加速度/力/速度/质量/角度/加速度/间隔根数
      }
      const nk = rw.length + '_' + rw[rw.length - 1][0];  // 以最新缓冲重新计算版本key
      OV.data[tf] = { piv: piv, th: j.th || 0, key: nk };  // 写入新缓存(转折点+尖转折阈值+key)
      if (typeof needDraw !== 'undefined') needDraw = true;  // 请求重绘
      const c = document.getElementById('ovCnt');        // 图例中的计数元素
      if (c) {                                           // 图例存在才更新
        let cnt = 0;                                     // 尖转折计数
        for (let k = 0; k < piv.length; k++) if (piv[k].type === 0 && piv[k].ke >= j.th) cnt++;  // 统计底部且KE达尖转折阈值的个数
        c.textContent = tf + ' 力学转折 ' + piv.length + ' 个 · 尖转折 ' + cnt + ' 个 (C++计算)';  // 更新图例计数文案
      }
    })
    .catch(function () {});                              // 请求失败静默(覆盖层不影响主图)
};

/* 图例 (DOM就绪后创建) */
function ovInitUI() {                                    // 创建覆盖层图例
  const host = document.getElementById('chart');         // 图容器
  if (!host || document.getElementById('ovLegend')) return;  // 容器不存在或图例已建则跳过
  const lg = document.createElement('div');              // 创建图例div
  lg.id = 'ovLegend';                                    // id供CSS定位
  lg.innerHTML = '<span style="color:#c8860a;font-weight:bold">▲</span>尖转折(动能前25%) '  // 金色▲说明
    + '<span style="color:#888">KE=½mv² · g=v²/2h 重力加速度 · F=mg(牛顿二) · 0.618黄金分割 · 全部由 C++ 计算, 前端只画</span> '  // 公式说明(灰色)
    + '<span id="ovCnt"></span>';                        // 计数占位(OV.compute里更新)
  host.appendChild(lg);                                  // 挂到图上
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ovInitUI);  // 文档还在解析 → 等DOM就绪
else ovInitUI();                                         // 已就绪 → 立即创建

/* 包装主图 draw(): 主脚本就绪后挂载; 保护壳: 覆盖层抛错绝不杀主渲染循环 */
(function () {                                           // IIFE: 轮询等待draw定义后包装
  let tries = 0;                                         // 轮询计数
  const timer = setInterval(() => {                      // 每100ms尝试一次
    tries++;                                             // 计数+1
    if (typeof draw === 'function' && !draw.__ovhook) {  // draw已定义且未被包装过
      const _d = draw;                                   // 保存原draw
      const w = function () {                            // 包装后的draw
        _d();                                            // 先执行原主图绘制
        try {                                            // 保护壳: 覆盖层异常不影响主图
          if (typeof TF !== 'undefined' && typeof S !== 'undefined' && S[TF]) {  // 数据层就绪才画覆盖层
            OV.compute(TF);                              // 调度: 保证转折点数据最新(带缓存)
            OV.paint();                    // ov_paint.js  // 执行覆盖层绘制
          }
        } catch (e) { console.warn('[ov]', e && e.message); }  // 异常仅警告
      };
      w.__ovhook = true;                                 // 标记已包装(防重复包装)
      window.draw = w;                                   // 替换全局draw
      if (typeof needDraw !== 'undefined') needDraw = true;  // 请求一次重绘
      clearInterval(timer);                              // 停止轮询
    } else if (tries > 150) clearInterval(timer);        // 15秒仍未就绪则放弃
  }, 100);
})();
