<?php /* klineall.php — ETH两年快照骨架: 只含HTML结构+文件挂载, 无CSS无JS无计算
 * 组件与 nqall.php 完全同套: nqall.css · nqall_core.js · nqall_data.js · nqall_chart.js · nqall_input.js
 * 数据源差异在 kline_cfg.js(NQ_API_URL=kline_api.php, 关实时轮询) — 其余一字不改
 * 热插拔力学覆盖层: ov_core/ov_pivot/ov_physics/ov_paint.js + ov_overlay.css (删挂载行即移除) */ ?>
<!DOCTYPE html><html lang="zh"><head><meta charset="utf-8"><title>ETH K线 + MACD(12,26,60) 多周期联动图</title> <!-- 文档声明+中文页头+标题 -->
<!-- ▼▼ 热插拔力学覆盖层(无缠论·纯JS前端计算): 删除下面2行即完全移除 ▼▼ --> <!-- 覆盖层开始标记注释 -->
<link rel="stylesheet" href="ov_overlay.css?v=20260927a"><script src="ov_core.js?v=20260927a"></script><script src="ov_pivot.js?v=20260927a"></script><script src="ov_physics.js?v=20260927a"></script><script src="ov_paint.js?v=20260927a"></script> <!-- 挂载力学覆盖层: 样式+核心/分型/物理/画笔四个JS(带版本号防缓存) -->
<!-- ▲▲ 热插拔结束 ▲▲ --> <!-- 覆盖层结束标记注释 -->
<link rel="stylesheet" href="nqall.css?v=20260927a"></head><body> <!-- 主样式表(带版本号)并进入 body -->
<div id="wrap"> <!-- 页面最外层容器 -->
<div id="top"> <!-- 顶栏容器 -->
<span id="tfs"><span class="tf on" data-tf="1m">1分</span><span class="tf" data-tf="3m">3分</span><span class="tf" data-tf="5m">5分</span><span class="tf" data-tf="15m">15分</span><span class="tf" data-tf="1h">1小时</span></span> <!-- 周期切换按钮组(1分默认高亮on, data-tf 供JS读取) -->
<span>ETH/USDT 两年快照 · MACD(12,26,60) · <b id="stat"></b></span> <!-- 标题说明 + 状态占位(stat由JS填充) -->
<span id="hint">滚轮缩放 · 拖拽平移 · 贴左/右边缘自动滚动(自动按需加载数据) · 悬停看详情</span> <!-- 操作提示文案 -->
</div> <!-- 顶栏结束 -->
<div id="chart"><canvas id="cv"></canvas><div id="tip"></div><div id="load">加载K线数据中…</div><div id="fetch">加载中…</div><div id="live">ETH <span id="lvp">快照数据</span> <span id="lvc"></span><small id="lvt"></small></div></div> <!-- 图表区: 画布+悬停提示+首屏加载遮罩+取数提示+左上角实时价格栏(快照模式价格位固定文案) -->
</div> <!-- 外层容器结束 -->
<script src="kline_cfg.js?v=20260927a"></script><script src="nqall_core.js?v=20260927a"></script><script src="nqall_data.js?v=20260927a"></script><script src="nqall_chart.js?v=20260927a"></script><script src="nqall_input.js?v=20260927a"></script> <!-- 按序挂载: ETH配置 + 画布核心 + 数据层 + 绘图层 + 交互层 -->
</body></html> <!-- body 与 html 结束 -->
