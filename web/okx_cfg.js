/* okx_cfg.js — index.php 数据源配置 (nqall_data.js 读取)
 * 2026-09-28 v3 组件化架构: 必须用 PHP 调用组件(用户指令)
 *   浏览器(AJAX + JS + CSS) → PHP(api.php, 零计算零SQL纯转发) → C++ 组件(apihub/tradehub/sigcore)
 * NQ_API_URL=api.php/kline → C++ 组件直读数据库, 默认1000根, 拖动 before/after 分页续传
 * NQ_LIVE_URL=api.php/live → 左上角实时价格+形成中K线 (顺带REPLACE 1m入库)
 * window.AH = PHP 接口基址 (okx_panel.js 全部数据接口经 PHP 转发到 C++ 组件)
 * OKX_INST 当前合约 (URL ?inst= 可指定, 流水/监测卡点击联动切换)
 *
 * 【文件职责】前端全局配置: 定义 PHP 接口基址、当前合约、K线/实时行情接口地址。
 * 【功能清单】1. window.AH 接口基址; 2. window.OKX_INST 当前合约(URL参数可覆盖+合法性校验);
 *             3. NQ_API_URL K线接口; 4. NQ_LIVE_URL 实时行情接口。
 */
window.AH = 'api.php';                                   // PHP 接口基址: 所有数据请求经 api.php 转发到 C++ 组件
window.OKX_INST = (new URLSearchParams(location.search).get('inst')  // 从URL ?inst= 读取指定合约
  || window.OKX_DEFAULT_INST || 'ETH-USDT-SWAP').toUpperCase();      // 无参数则用默认合约, 统一转大写
if (!/^[A-Z0-9\-]{1,32}$/.test(window.OKX_INST)) window.OKX_INST = 'ETH-USDT-SWAP';  // 合约名非法(含特殊字符/超长)时回退默认值
window.NQ_API_URL = window.AH + '/kline';                // K线历史接口地址: api.php/kline (C++直读数据库)
window.NQ_LIVE_URL = window.AH + '/live';                // 实时行情接口地址: api.php/live (实时价+形成中K线)
