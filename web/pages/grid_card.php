<?php
/**
 * pages/grid_card.php — 止盈+加仓信号监测卡片(2026-09-28c: 右下角悬浮窗, 可折叠)
 */
?>
    <div class="card c-grid float-monitor" id="monWin">
      <h3 class="hc-grid"><span>实时监测（金▲全池 · 20X · 每笔1U · +2%止盈 · 不止损 · 只买涨）</span><button class="btn gmin" id="gminBtn" title="折叠/展开">─</button></h3>
      <div class="gw-body">
      <div class="sline" id="gline">加载中…</div>
      <div class="tw" id="gridTW"><table id="gridT"><thead><tr>
        <th>合约</th><th>档位</th><th>现价</th><th>止盈</th><th>状态</th>
      </tr></thead><tbody id="gridRows"><tr><td colspan="5">加载中…</td></tr></tbody></table></div>
      <div class="sline" id="gridTip" style="margin-top:6px"><b>加仓策略</b>：跌的时候(现价&lt;均价)出现 5m 金▲信号 即加仓 · <b>每轮固定+1U/3</b> · <b>不限轮数(永远可加)</b> · 加仓无仓位上限 | 亏损由交易所爆仓线兜底 | 永不止损 | 止盈不挂单：每10秒检测 价格+2%(ROI40%) 达标立即市价全平</div>
      <div class="sline" id="gridEntry" style="margin-top:4px;color:#8a6d3b">买入=15m+5m 金▲共振（底部动能前25%, 与K线金色箭头同口径）· 每笔保证金1U · 20X · 全池扫描只买 symbollist 权威池合约</div>
      <div style="margin-top:8px"><button class="btn ghost" id="gridLogBtn" style="width:100%">加仓信号历史（跌时金▲ 触发 · 加仓 · 风控拦截 都留痕）</button></div>
      <div class="sline" style="margin-top:8px;font-weight:700">⚡ 全合约最近成交（买入🚀 / 加仓▲ / 平仓🍃+盈利 · 每10秒刷新 · 点合约名切K线）</div>
      <div class="tw" id="recentTW" style="max-height:210px;min-height:120px"><table id="recentT"><thead><tr>
        <th>时间</th><th>合约</th><th>动作</th><th>价格</th><th>盈亏U</th>
      </tr></thead><tbody id="recentRows"><tr><td colspan="5">加载中…</td></tr></tbody></table></div>
      </div>
    </div>
