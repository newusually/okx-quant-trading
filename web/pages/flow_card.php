<?php
/**
 * pages/flow_card.php — 交易流水卡片
 */
?>
    <div class="card c-flow" style="margin-top:12px">
      <h3 class="hc-flow">交易流水（最近500条 · 买入/加仓/平仓 · 平仓自动匹配OKX真实盈利 · 点表头排序 · 点合约名切K线）</h3>
      <div class="tw"><table id="flowT"><thead><tr>
        <th data-k="trade_time">时间</th><th data-k="inst_id">合约</th><th data-k="action">方向</th>
        <th data-k="price">价格</th><th data-k="pnl">净盈亏$</th><th data-k="fee">手续费$</th><th data-k="funding">资金费$</th>
      </tr></thead><tbody id="trades"><tr><td colspan="7">加载中…</td></tr></tbody></table></div>
    </div>
  </div>
  <div>
