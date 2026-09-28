<?php /* nqall.php — 主页骨架: 只含HTML结构+文件挂载, 无CSS无JS无计算
 * 文件分类: nqall.css(样式) · nqall_core.js(画布/几何/主循环) · nqall_data.js(数据/轮询)
 *           nqall_chart.js(绘图) · nqall_input.js(交互)
 * 热插拔力学覆盖层: ov_core/ov_pivot/ov_physics/ov_paint.js + ov_overlay.css (删挂载行即移除) */ ?>
<!DOCTYPE html><html lang="zh"><head><meta charset="utf-8"><title>纳指 K线 + MACD(12,26,60) 多周期联动图</title>
<!-- ▼▼ 热插拔力学覆盖层(无缠论·纯JS前端计算): 删除下面2行即完全移除 ▼▼ -->
<link rel="stylesheet" href="ov_overlay.css"><script src="ov_core.js"></script><script src="ov_pivot.js"></script><script src="ov_physics.js"></script><script src="ov_paint.js"></script>
<!-- ▲▲ 热插拔结束 ▲▲ -->
<link rel="stylesheet" href="nqall.css"></head><body>
<div id="wrap">
<div id="top">
<span id="tfs"><span class="tf on" data-tf="1m">1分</span><span class="tf" data-tf="3m">3分</span><span class="tf" data-tf="5m">5分</span><span class="tf" data-tf="15m">15分</span><span class="tf" data-tf="1h">1小时</span></span>
<span>纳斯达克100 (Dukascopy) · MACD(12,26,60) · <b id="stat"></b></span>
<span id="hint">滚轮缩放 · 拖拽平移 · 贴左/右边缘自动滚动(自动按需加载数据) · 悬停看详情</span>
</div>
<div id="chart"><canvas id="cv"></canvas><div id="tip"></div><div id="load">加载K线数据中…</div><div id="fetch">加载中…</div><div id="live">纳指 <span id="lvp">--</span> <span id="lvc"></span><small id="lvt"></small></div></div>
</div>
<script src="nqall_core.js"></script><script src="nqall_data.js"></script><script src="nqall_chart.js"></script><script src="nqall_input.js"></script>
</body></html>
