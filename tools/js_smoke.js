/* 浏览器环境模拟器: 抓前端JS运行时错误 (语法已过, 找ReferenceError/TypeError) */
const fs = require('fs');

function el(id) {
  const e = {
    id, style: new Proxy({}, { set: () => true, get: () => '' }),
    textContent: '', innerHTML: '', value: '', dataset: {},
    classList: { toggle: () => {}, add: () => {}, remove: () => {}, contains: () => false },
    addEventListener: () => {}, appendChild: () => {}, onclick: null, onchange: null,
    querySelector: () => el(id + '_q'), querySelectorAll: () => [],
    getBoundingClientRect: () => ({ left: 0, top: 0, width: 800, height: 500 }),
    clientWidth: 800, clientHeight: 500, offsetWidth: 100, offsetHeight: 30,
    offsetLeft: 0, offsetTop: 0,
  };
  if (id === 'cv') e.getContext = () => noopCtx;
  return e;
}
const noopCtx = new Proxy({}, {
  get: (t, k) => {
    if (k === 'canvas') return el('cv');
    return (...a) => {
      if (k === 'measureText') return { width: 10 };
      if (k === 'createLinearGradient') return { addColorStop: () => {} };
      return undefined;
    };
  },
  set: () => true,
});
const elems = {};
const document = {
  getElementById: (id) => (elems[id] ||= el(id)),
  querySelectorAll: () => [],
  addEventListener: () => {},
  createElement: () => el('tmp'),
};
const window = {
  addEventListener: () => {},
  OKX_INST: 'RAY-USDT-SWAP', NQ_API_URL: 'okx_api.php', NQ_LIVE_URL: 'okx_live.php',
};
const fetchResult = (j) => Promise.resolve({ json: () => Promise.resolve(j) });
const fetch = (url) => {
  if (String(url).includes('kline')) return fetchResult({ ok: true, rows: [[1790526600, 2.16, 2.18, 2.15, 2.17, 0, 0, 0, 0, 0, null, 1]], last: 1790526600, bucket: 5, iv: 300, now: Date.now() });
  if (String(url).includes('live')) return fetchResult({ ok: 1, price: 2.17, ts: 1790526700, candles: [[1790526600, 2.16, 2.18, 2.15, 2.17]] });
  return fetchResult({ ok: true, data: [] });
};
const requestAnimationFrame = () => {};   // 不跑循环, 只抓加载期错误

const sandbox = {
  console, document, window, fetch, requestAnimationFrame,
  AbortSignal: { timeout: () => null },
  setTimeout: () => 0, setInterval: () => 0, clearTimeout: () => {},
  Date, Math, JSON, URL, URLSearchParams, Promise, isFinite, parseInt, parseFloat,
};
sandbox.window = new Proxy(window, { get: (t, k) => sandbox[k] !== undefined ? sandbox[k] : t[k], set: (t, k, v) => (t[k] = v, true) });
sandbox.self = sandbox.window;
vm = require('vm');
const ctx = vm.createContext(sandbox);

const files = ['nqall_core.js', 'nqall_data.js', 'nqall_chart.js', 'okx_panel.js'];
for (const f of files) {
  const code = fs.readFileSync('E:/finally-main/web/' + f, 'utf8');
  try {
    vm.runInContext(code, ctx, { filename: f });
    // 再手动触发一次 draw (模拟首帧渲染)
    if (f === 'okx_panel.js') {
      vm.runInContext(`
        S['1m'] = { bucket: 1, rows: [[1790526600,2.16,2.18,2.15,2.17,-0.001,0.0005,0.001,2,2,null,1]], last: 1790526600, exhaustOld:false, exhaustNew:false, fetching:false, forming:null };
        right = 0; count = 200; needDraw = true; draw();
        window.OKX_MARKS = [{t:1790526600000,kind:'buy',px:2.1667,profit:null},{t:1790526600000,kind:'add',px:2.1669,profit:null},{t:1790526700000,kind:'close',px:2.17,profit:2.35}];
        needDraw = true; draw();
      `, ctx, { filename: 'draw-test' });
    }
    console.log('[OK]', f);
  } catch (e) {
    console.log('[FAIL]', f, '→', e.message, '| stack:', (e.stack || '').split('\n')[1] || '');
  }
}
