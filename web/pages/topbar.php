<?php
/**
 * pages/topbar.php — 顶栏(标题/合约下拉/实时价/策略横幅)
 */
?>
<body>
<div class="top">
  <b>📈 OKX 量化交易面板</b>
  <select id="symbol"></select>
  <span id="status">连接中…</span>
  <span style="flex:1"></span>
  <span id="slineTop">加载中…</span>
</div>
<div class="top" style="padding-top:6px;padding-bottom:8px">
  <div id="liveStats" style="flex:1 1 100%;display:flex;gap:14px;flex-wrap:wrap;font-size:13px;align-items:center">
    <span class="muted">实时统计加载中…</span>
  </div>
</div>
<div class="top" style="padding-top:0;padding-bottom:8px">
  <div style="flex:1 1 100%" title="黄金坑全池版策略说明"><span class="mq" style="color:#8a6d3b">策略：买入=15m+5m 黄金坑共振(金▲动能锚+缠论底分型+CCI+ADX+MACD+KDJ 六维) · 20X每笔1U · 价格+2%止盈(ROI40%) · 永不止损 · 加仓=跌时5m黄金坑 每轮+⅓U 不限轮数 · 只买权威池(symbol_pool) · 只买涨 | 架构：PHP页面 + PHP接口(api.php 转发) → C++组件(apihub 接口 / tradehub 交易中枢 / datahub 数据中枢 / sigcore 信号算法) · 前端 AJAX+JS+CSS · 引擎无PHP | 数据：全数据库存储(无json落盘)</span></div>
</div>
<div id="fltMenu"></div>
