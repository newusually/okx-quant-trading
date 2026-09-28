<?php
/**
 * pages/chart_card.php — K线卡片(nqall组件 + 力学覆盖层)
 */
?>
<div class="wrap"><div>
    <div class="card c-chart">
      <h3 class="hc-chart">K线图（滚轮缩放 · 拖动平移 · 贴边滚动 · 悬停OHLC · 红涨绿跌）</h3>
      <?php if ($recentInsts): ?>
      <div id="recentInsts" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:0 2px 6px">
        <span style="font-size:12px;color:#8795a8;font-weight:700">⚡最近交易:</span>
        <?php foreach ($recentInsts as $i => $ri): ?>
        <span class="tf<?= $i === 0 ? ' on' : '' ?>" data-inst="<?= htmlspecialchars($ri) ?>" style="cursor:pointer"><?= htmlspecialchars(preg_replace('/-USDT-SWAP$/', '', $ri)) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div id="tfs"><span class="tf" data-tf="1m">1分</span><span class="tf" data-tf="3m">3分</span><span class="tf on" data-tf="5m">5分</span><span class="tf" data-tf="15m">15分</span><span class="tf" data-tf="1h">1小时</span></div>
      <div id="chart"><canvas id="cv"></canvas><div id="tip"></div><div id="load">加载K线数据中…</div><div id="fetch">加载中…</div><div id="live"><span id="lvn"><?= htmlspecialchars($defaultInst) ?></span> <span id="lvp">--</span> <span id="lvc"></span><small id="lvt"></small></div></div>
      <div class="legend"><b id="stat"></b><br>
      <span style="color:#e6a817;font-weight:bold">▲金箭头</span>=买入点(动能前25%) · <b>🚀</b>=买入开仓 · <b style="color:#e6a817">▲</b>=跌时金▲加仓 · <b>🍃</b>=平仓(旁标盈利额, 盈红亏绿) · <b style="color:#b07708">KE=½mv²</b> 动能 · <b style="color:#b07708">g=v²/2h</b> 重力加速度 · <b style="color:#b07708">F=mg</b>(牛顿二) · <span style="color:#e6a817">┄0.618黄金分割</span> · MACD(12,26,60)　弱信号灰色已隐藏</div>
    </div>
