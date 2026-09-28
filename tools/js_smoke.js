/* 浏览器环境模拟器: 抓前端JS运行时错误 (语法已过, 找ReferenceError/TypeError)
 *
 * 【文件职责】前端冒烟测试: 在 Node vm 沙箱中模拟浏览器环境(document/window/fetch/canvas),
 *             依次加载 K线核心/数据/绘图/面板 4 个 JS, 捕获加载期 ReferenceError/TypeError,
 *             并对 okx_panel.js 额外模拟首帧 draw() 调用(含买卖标注)。
 * 【功能清单】1. el() 假DOM元素工厂(Proxy兜底一切属性); 2. noopCtx 空操作canvas上下文;
 *             3. 假document/window/fetch(返回固定K线与live数据); 4. vm沙箱加载并试跑 draw()。
 */
const fs = require('fs');                                // 文件系统模块: 读被测JS源码

function el(id) {                                        // 假DOM元素工厂: 按id造一个"什么都能接"的元素
  const e = {                                            // 元素对象: 常用属性全部给假值
    id, style: new Proxy({}, { set: () => true, get: () => '' }),  // style: Proxy吞掉任何读写
    textContent: '', innerHTML: '', value: '', dataset: {},  // 文本/HTML/值/数据集默认空
    classList: { toggle: () => {}, add: () => {}, remove: () => {}, contains: () => false },  // classList: 各方法空实现
    addEventListener: () => {}, appendChild: () => {}, onclick: null, onchange: null,  // 事件: 空实现
    querySelector: () => el(id + '_q'), querySelectorAll: () => [],  // 查询: 返回嵌套假元素/空列表
    getBoundingClientRect: () => ({ left: 0, top: 0, width: 800, height: 500 }),  // 位置: 固定800x500
    clientWidth: 800, clientHeight: 500, offsetWidth: 100, offsetHeight: 30,  // 尺寸假值
    offsetLeft: 0, offsetTop: 0,                         // 偏移假值
  };
  if (id === 'cv') e.getContext = () => noopCtx;         // 画布元素: 返回空操作2D上下文
  return e;
}
const noopCtx = new Proxy({}, {                          // 空操作canvas 2D上下文: 任何方法调用都吞掉
  get: (t, k) => {                                       // 属性/方法读取
    if (k === 'canvas') return el('cv');                 // ctx.canvas 返回假画布元素
    return (...a) => {                                   // 任何"方法"都返回一个函数
      if (k === 'measureText') return { width: 10 };     // measureText 返回固定宽度
      if (k === 'createLinearGradient') return { addColorStop: () => {} };  // 渐变对象: addColorStop空实现
      return undefined;                                  // 其余方法返回undefined
    };
  },
  set: () => true,                                       // 任何属性赋值都吞掉
});
const elems = {};                                        // 假DOM缓存: 同id复用同一元素
const document = {                                       // 假document对象
  getElementById: (id) => (elems[id] ||= el(id)),        // 按id取元素(懒创建并缓存)
  querySelectorAll: () => [],                            // 选择器查询: 永远空列表
  addEventListener: () => {},                            // 全局事件: 空实现
  createElement: () => el('tmp'),                        // 创建元素: 返回假元素
};
const window = {                                         // 假window对象: 前端脚本读的全局配置
  addEventListener: () => {},                            // 事件: 空实现
  OKX_INST: 'RAY-USDT-SWAP', NQ_API_URL: 'okx_api.php', NQ_LIVE_URL: 'okx_live.php',  // 当前合约与接口地址(与真实页一致)
};
const fetchResult = (j) => Promise.resolve({ json: () => Promise.resolve(j) });  // 构造一个带json()的假Response
const fetch = (url) => {                                 // 假fetch: 按URL分发固定数据
  if (String(url).includes('kline')) return fetchResult({ ok: true, rows: [[1790526600, 2.16, 2.18, 2.15, 2.17, 0, 0, 0, 0, 0, null, 1]], last: 1790526600, bucket: 5, iv: 300, now: Date.now() });  // K线接口: 返回1根假RAY K线
  if (String(url).includes('live')) return fetchResult({ ok: 1, price: 2.17, ts: 1790526700, candles: [[1790526600, 2.16, 2.18, 2.15, 2.17]] });  // live接口: 返回假实时价
  return fetchResult({ ok: true, data: [] });            // 其余接口: 返回空数据
};
const requestAnimationFrame = () => {};   // 不跑循环, 只抓加载期错误  // 假rAF: 不真正循环渲染

const sandbox = {                                        // vm沙箱全局对象: 列出脚本可能用到的所有全局
  console, document, window, fetch, requestAnimationFrame,
  AbortSignal: { timeout: () => null },                  // AbortSignal.timeout: 返回null(无超时信号)
  setTimeout: () => 0, setInterval: () => 0, clearTimeout: () => {},  // 定时器: 空实现防真轮询
  Date, Math, JSON, URL, URLSearchParams, Promise, isFinite, parseInt, parseFloat,  // 透传标准内置对象
};
sandbox.window = new Proxy(window, { get: (t, k) => sandbox[k] !== undefined ? sandbox[k] : t[k], set: (t, k, v) => (t[k] = v, true) });  // window代理: 优先从沙箱取全局(如needDraw), 赋值写回
sandbox.self = sandbox.window;                           // self 与 window 同引用(模拟浏览器)
vm = require('vm');                                      // vm模块: 创建隔离上下文
const ctx = vm.createContext(sandbox);                   // 用沙箱建vm上下文

const files = ['nqall_core.js', 'nqall_data.js', 'nqall_chart.js', 'okx_panel.js'];  // 被测脚本清单(按依赖顺序加载)
for (const f of files) {                                 // 逐个加载执行
  const code = fs.readFileSync('E:/finally-main/web/' + f, 'utf8');  // 读源码(注意路径指向finally-main部署目录)
  try {
    vm.runInContext(code, ctx, { filename: f });         // 在沙箱中执行该脚本(抛错即捕获)
    // 再手动触发一次 draw (模拟首帧渲染)
    if (f === 'okx_panel.js') {                          // 仅对面板脚本做首帧模拟
      vm.runInContext(`
        S['1m'] = { bucket: 1, rows: [[1790526600,2.16,2.18,2.15,2.17,-0.001,0.0005,0.001,2,2,null,1]], last: 1790526600, exhaustOld:false, exhaustNew:false, fetching:false, forming:null };  // 注入1m假K线数据
        right = 0; count = 200; needDraw = true; draw();  // 设置视窗并触发一次无标注draw
        window.OKX_MARKS = [{t:1790526600000,kind:'buy',px:2.1667,profit:null},{t:1790526600000,kind:'add',px:2.1669,profit:null},{t:1790526700000,kind:'close',px:2.17,profit:2.35}];  // 注入买入/加仓/平仓三类假标注
        needDraw = true; draw();                          // 再触发一次带标注draw
      `, ctx, { filename: 'draw-test' });                // 执行首帧模拟脚本
    }
    console.log('[OK]', f);                              // 该脚本加载(及首帧模拟)无异常
  } catch (e) {                                          // 捕获运行时错误
    console.log('[FAIL]', f, '→', e.message, '| stack:', (e.stack || '').split('\n')[1] || '');  // 打印错误消息与调用栈首行
  }
}
