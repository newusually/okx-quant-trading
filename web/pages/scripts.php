<?php
/**
 * pages/scripts.php — 脚本加载(nqall组件 + 面板逻辑 + 主题特效)
 * 2026-09-28: 全部文件加 ?v= 版本号 → 浏览器改了就拿新的, 杜绝旧缓存导致"改了没生效"
 * 2026-09-28i: 力学覆盖层计算下沉 C++(ov_pivot/ov_physics 已删), 只留 ov_core(取数)+ov_paint(画)
 */
$JSV = '20260928j';
?>
<script src="okx_cfg.js?v=<?= $JSV ?>"></script><script src="nqall_core.js?v=<?= $JSV ?>"></script><script src="nqall_data.js?v=<?= $JSV ?>"></script><script src="nqall_chart.js?v=<?= $JSV ?>"></script><script src="nqall_input.js?v=<?= $JSV ?>"></script><script src="okx_panel.js?v=<?= $JSV ?>"></script><script src="okx_theme.js?v=<?= $JSV ?>"></script>
</body>
</html>
