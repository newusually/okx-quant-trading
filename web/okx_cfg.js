/* okx_cfg.js — index.php 数据源配置 (nqall_data.js 读取)
 * 2026-09-28 v3 组件化架构: 必须用 PHP 调用组件(用户指令)
 *   浏览器(AJAX + JS + CSS) → PHP(api.php, 零计算零SQL纯转发) → C++ 组件(apihub/tradehub/sigcore)
 * NQ_API_URL=api.php/kline → C++ 组件直读数据库, 默认1000根, 拖动 before/after 分页续传
 * NQ_LIVE_URL=api.php/live → 左上角实时价格+形成中K线 (顺带REPLACE 1m入库)
 * window.AH = PHP 接口基址 (okx_panel.js 全部数据接口经 PHP 转发到 C++ 组件)
 * OKX_INST 当前合约 (URL ?inst= 可指定, 流水/监测卡点击联动切换) */
window.AH = 'api.php';
window.OKX_INST = (new URLSearchParams(location.search).get('inst')
  || window.OKX_DEFAULT_INST || 'ETH-USDT-SWAP').toUpperCase();
if (!/^[A-Z0-9\-]{1,32}$/.test(window.OKX_INST)) window.OKX_INST = 'ETH-USDT-SWAP';
window.NQ_API_URL = window.AH + '/kline';
window.NQ_LIVE_URL = window.AH + '/live';
