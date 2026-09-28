/* 决定性验证: 录屏式canvas + 真实RAY数据, 确认买卖标注绘制命令真实执行 */
const fs = require('fs');
const vm = require('vm');

(async () => {
  // ① 拉真实数据
  const kl = await (await fetch('http://localhost/okx_api.php?action=kline&tf=5m&limit=200&inst=RAY-USDT-SWAP')).json();
  const mk = await (await fetch('http://localhost/api.php?action=marks&days=7&inst=RAY-USDT-SWAP')).json();
  const rows = kl.rows || [];
  const marks = mk.data || [];
  console.log('真实K线:', rows.length, '根 | 真实标注:', marks.length, '个 →', marks.map(m => m.kind).join(','));

  // ② 录屏canvas: 记录每次调用
  const calls = [];
  const recCtx = new Proxy({}, {
    get: (t, k) => (...a) => { calls.push([k, ...a]); if (k === 'measureText') return { width: 10 }; if (k === 'createLinearGradient') return { addColorStop: () => {} }; },
    set: (t, k, v) => { calls.push(['SET_' + String(k), v]); return true; },
  });

  function el(id) {
    const e = {
      id, style: new Proxy({}, { set: () => true, get: () => '' }),
      textContent: '', innerHTML: '', value: '', dataset: {},
      classList: { toggle: () => {}, add: () => {}, remove: () => {}, contains: () => false },
      addEventListener: () => {}, appendChild: () => {}, onclick: null, onchange: null,
      querySelector: () => el(id + '_q'), querySelectorAll: () => [],
      getBoundingClientRect: () => ({ left: 0, top: 0, width: 1200, height: 640 }),
      clientWidth: 1200, clientHeight: 640, offsetWidth: 100, offsetHeight: 30, offsetLeft: 0, offsetTop: 0,
    };
    if (id === 'cv') e.getContext = () => recCtx;
    return e;
  }
  const elems = {};
  const document = { getElementById: id => (elems[id] ||= el(id)), querySelectorAll: () => [], addEventListener: () => {}, createElement: () => el('tmp') };
  const window = { addEventListener: () => {}, OKX_INST: 'RAY-USDT-SWAP', NQ_API_URL: 'okx_api.php', NQ_LIVE_URL: 'okx_live.php' };
  const fr = j => Promise.resolve({ json: () => Promise.resolve(j) });
  const fetchM = url => {
    if (String(url).includes('kline')) return fr(kl);
    if (String(url).includes('live')) return fr({ ok: 1, price: rows.at(-1)[4], ts: rows.at(-1)[0], candles: rows.slice(-2) });
    return fr({ ok: true, data: [] });
  };
  const sandbox = { console, document, window, fetch: fetchM, requestAnimationFrame: () => {}, AbortSignal: { timeout: () => null }, setTimeout: () => 0, setInterval: () => 0, Date, Math, JSON, URL, URLSearchParams, Promise, isFinite, parseInt, parseFloat };
  sandbox.window = new Proxy(window, { get: (t, k) => sandbox[k] !== undefined ? sandbox[k] : t[k], set: (t, k, v) => (t[k] = v, true) });
  sandbox.self = sandbox.window;
  const ctx = vm.createContext(sandbox);

  for (const f of ['nqall_core.js', 'nqall_data.js', 'nqall_chart.js']) {
    vm.runInContext(fs.readFileSync('E:/finally-main/web/' + f, 'utf8'), ctx, { filename: f });
  }
  await new Promise(r => setImmediate(r));   // 等微任务: mock fetch数据进S
  await new Promise(r => setImmediate(r));

  // ③ 无标注 draw
  calls.length = 0;
  vm.runInContext(`right = ${rows.length - 1}; count = 200; needDraw = true; draw();`, ctx, { filename: 'd0' });
  const base = calls.length;
  // ④ 有标注 draw
  vm.runInContext(`window.OKX_MARKS = ${JSON.stringify(marks)}; needDraw = true; draw();`, ctx, { filename: 'd1' });
  const extra = calls.slice(base);
  const cnt = name => extra.filter(c => c[0] === name).length;
  console.log('---- 标注增量绘制调用 ----');
  console.log('quadraticCurveTo(叶片/箭体):', cnt('quadraticCurveTo'), '| arc(舷窗):', cnt('arc'),
    '| strokeText(盈利白边):', cnt('strokeText'), '| fillText:', cnt('fillText'),
    '| SET_fillStyle红:', extra.filter(c => c[0] === 'SET_fillStyle' && String(c[1]).includes('e03131')).length);
  const texts = extra.filter(c => c[0] === 'fillText' || c[0] === 'strokeText').map(c => String(c[1]));
  console.log('标注文字内容:', texts.join(' | ') || '(无)');
  console.log(cnt('quadraticCurveTo') >= 4 && cnt('strokeText') >= 1 ? '✅✅ 标注绘制确认执行' : '❌ 标注未执行, 有bug');
})().catch(e => { console.error('TEST-ERR', e); process.exit(1); });
