<?php
/**
 * pages/tail.php — 收尾: 关闭 wrap 容器 + 弹窗容器(#mask, 加仓信号历史用)
 * (策略体检报告卡已按用户指令删除; #mask 保留给「加仓信号历史」弹窗)
 */
?>
    </div>
  </div>
</div>
<div id="mask"><div class="box">
  <header><span id="maskTitle">加仓信号历史</span><button class="btn" onclick="document.getElementById('mask').style.display='none'">关闭</button></header>
  <pre id="repTxt">加载中…</pre>
</div></div>
