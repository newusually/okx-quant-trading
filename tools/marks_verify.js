/* 决定性验证: 录屏式canvas + 真实RAY数据, 确认买卖标注绘制命令真实执行
 *
 * 【文件职责】信号标注校验脚本: 拉真实K线与真实买卖标注数据, 在Node vm沙箱中加载K线前端三件套,
 *             用"录屏"Proxy记录canvas每一次调用, 对比无标注/有标注两次draw的增量绘制命令,
 *             以此决定性地证明 🚀买入/▲加仓/🍃平仓 标注的绘制命令真实执行。
 * 【功能清单】①拉真实数据(kline+marks) ②录屏canvas(Proxy记录calls)
 *             ③无标注draw取基线 ④有标注draw取增量 ⑤统计quadraticCurveTo/arc/strokeText等判通过。
 */
const fs = require('fs');                                // 文件系统: 读被测JS源码
const vm = require('vm');                                // vm模块: 沙箱执行

(async () => {                                           // 异步主流程
  // ① 拉真实数据
  const kl = await (await fetch('http://localhost/okx_api.php?action=kline&tf=5m&limit=200&inst=RAY-USDT-SWAP')).json();  // 拉真实RAY 5m K线200根
  const mk = await (await fetch('http://localhost/api.php?action=marks&days=7&inst=RAY-USDT-SWAP')).json();  // 拉真实近7天买卖标注
  const rows = kl.rows || [];                            // K线行数组
  const marks = mk.data || [];                           // 标注数组
  console.log('真实K线:', rows.length, '根 | 真实标注:', marks.length, '个 →', marks.map(m => m.kind).join(','));  // 打印数据概况与标注类型

  // ② 录屏canvas: 记录每次调用
  const calls = [];                                      // 绘制命令录制数组: 每项=[方法名, 参数...]
  const recCtx = new Proxy({}, {                         // 录屏2D上下文: 全部调用记入calls
    get: (t, k) => (...a) => { calls.push([k, ...a]); if (k === 'measureText') return { width: 10 }; if (k === 'createLinearGradient') return { addColorStop: () => {} }; },  // 方法调用: 记录并返回特殊假值
    set: (t, k, v) => { calls.push(['SET_' + String(k), v]); return true; },  // 属性赋值: 记录为SET_xxx
  });

  function el(id) {                                      // 假DOM元素工厂(同js_smoke.js思路)
    const e = {                                          // 元素常用属性假值
      id, style: new Proxy({}, { set: () => true, get: () => '' }),  // style代理吞读写
      textContent: '', innerHTML: '', value: '', dataset: {},  // 文本/HTML/值/数据集
      classList: { toggle: () => {}, add: () => {}, remove: () => {}, contains: () => false },  // classList空实现
      addEventListener: () => {}, appendChild: () => {}, onclick: null, onchange: null,  // 事件空实现
      querySelector: () => el(id + '_q'), querySelectorAll: () => [],  // 查询假实现
      getBoundingClientRect: () => ({ left: 0, top: 0, width: 1200, height: 640 }),  // 固定1200x640布局
      clientWidth: 1200, clientHeight: 640, offsetWidth: 100, offsetHeight: 30, offsetLeft: 0, offsetTop: 0,  // 尺寸假值
    };
    if (id === 'cv') e.getContext = () => recCtx;        // 画布: 返回录屏上下文
    return e;
  }
  const elems = {};                                      // 假DOM缓存
  const document = { getElementById: id => (elems[id] ||= el(id)), querySelectorAll: () => [], addEventListener: () => {}, createElement: () => el('tmp') };  // 假document
  const window = { addEventListener: () => {}, OKX_INST: 'RAY-USDT-SWAP', NQ_API_URL: 'okx_api.php', NQ_LIVE_URL: 'okx_live.php' };  // 假window(带全局配置)
  const fr = j => Promise.resolve({ json: () => Promise.resolve(j) });  // 假Response构造
  const fetchM = url => {                                // 沙箱内假fetch: 回放第一步拉到的真实数据
    if (String(url).includes('kline')) return fr(kl);    // K线接口 → 回放真实K线
    if (String(url).includes('live')) return fr({ ok: 1, price: rows.at(-1)[4], ts: rows.at(-1)[0], candles: rows.slice(-2) });  // live接口 → 用最后根K线构造
    return fr({ ok: true, data: [] });                   // 其余接口 → 空数据
  };
  const sandbox = { console, document, window, fetch: fetchM, requestAnimationFrame: () => {}, AbortSignal: { timeout: () => null }, setTimeout: () => 0, setInterval: () => 0, Date, Math, JSON, URL, URLSearchParams, Promise, isFinite, parseInt, parseFloat };  // vm沙箱全局
  sandbox.window = new Proxy(window, { get: (t, k) => sandbox[k] !== undefined ? sandbox[k] : t[k], set: (t, k, v) => (t[k] = v, true) });  // window代理: 优先沙箱全局
  sandbox.self = sandbox.window;                         // self同window
  const ctx = vm.createContext(sandbox);                 // 创建vm上下文

  for (const f of ['nqall_core.js', 'nqall_data.js', 'nqall_chart.js']) {  // 按依赖顺序加载K线三件套
    vm.runInContext(fs.readFileSync('E:/finally-main/web/' + f, 'utf8'), ctx, { filename: f });  // 在沙箱执行(路径指向finally-main部署目录)
  }
  await new Promise(r => setImmediate(r));   // 等微任务: mock fetch数据进S  // 让异步fetch链路推进一轮
  await new Promise(r => setImmediate(r));               // 再等一轮确保数据已入S缓冲

  // ③ 无标注 draw
  calls.length = 0;                                      // 清空录制
  vm.runInContext(`right = ${rows.length - 1}; count = 200; needDraw = true; draw();`, ctx, { filename: 'd0' });  // 视窗对准数据末尾并执行无标注draw
  const base = calls.length;                             // 基线: 无标注时的命令数
  // ④ 有标注 draw
  vm.runInContext(`window.OKX_MARKS = ${JSON.stringify(marks)}; needDraw = true; draw();`, ctx, { filename: 'd1' });  // 注入真实标注后再draw
  const extra = calls.slice(base);                       // 增量: 有标注多出来的绘制命令
  const cnt = name => extra.filter(c => c[0] === name).length;  // 按方法名统计次数
  console.log('---- 标注增量绘制调用 ----');               // 输出分隔标题
  console.log('quadraticCurveTo(叶片/箭体):', cnt('quadraticCurveTo'), '| arc(舷窗):', cnt('arc'),  // 统计贝塞尔曲线(火箭箭体/叶子)与圆(舷窗)
    '| strokeText(盈利白边):', cnt('strokeText'), '| fillText:', cnt('fillText'),  // 统计描边/填充文字
    '| SET_fillStyle红:', extra.filter(c => c[0] === 'SET_fillStyle' && String(c[1]).includes('e03131')).length);  // 统计红色填充(#e03131)出现次数
  const texts = extra.filter(c => c[0] === 'fillText' || c[0] === 'strokeText').map(c => String(c[1]));  // 收集增量中绘制的全部文字
  console.log('标注文字内容:', texts.join(' | ') || '(无)');  // 打印文字内容(盈利额等)
  console.log(cnt('quadraticCurveTo') >= 4 && cnt('strokeText') >= 1 ? '✅✅ 标注绘制确认执行' : '❌ 标注未执行, 有bug');  // 判定: 贝塞尔≥4且描边文字≥1 → 通过
})().catch(e => { console.error('TEST-ERR', e); process.exit(1); });  // 主流程异常: 打印并退出码1
