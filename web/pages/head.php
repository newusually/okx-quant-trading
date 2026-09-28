<?php
/**
 * pages/head.php — HTML 头部(样式表)
 */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OKX 量化交易面板 (PHP+C++)</title>
<script>
/* 全局错误陷阱(内联, 不依赖任何外部JS): 脚本加载失败/运行时错误直接画到页面 */
window.__jserrs = [];
function __showErr(m) {
  window.__jserrs.push(m);
  try {
    var b = document.getElementById('jserr');
    if (!b) { b = document.createElement('div'); b.id = 'jserr';
      b.style.cssText = 'position:fixed;right:12px;top:12px;z-index:9999;max-width:46vw;background:#fff3f3;border:2px solid #e03131;border-radius:8px;padding:8px 12px;font:12px/1.6 Consolas,monospace;color:#c92a2a;white-space:pre-wrap;box-shadow:0 4px 14px rgba(160,30,30,.25)';
      document.body.appendChild(b); }
    b.textContent = window.__jserrs.join('\n').slice(-900);
  } catch (e) {}
}
window.onerror = function(m, s, l, c) { __showErr('⚠ ' + m + ' @ ' + (s || '?').split('/').pop() + ':' + l + ':' + c); };
window.addEventListener('unhandledrejection', function(e) { __showErr('⚠ 请求失败: ' + (e.reason && e.reason.message || e.reason)); });
window.addEventListener('error', function(e) {
  if (e.target && e.target.tagName === 'SCRIPT') __showErr('⚠ 脚本加载失败: ' + (e.target.src || '').split('/').pop() + ' (服务器忙或超时, 请刷新)');
  else if (e.target && e.target.tagName === 'LINK') __showErr('⚠ 样式加载失败: ' + (e.target.href || '').split('/').pop());
}, true);
</script>
<!-- ▼▼ 力学覆盖层(纯前端"画图", 计算全部在 C++ sigcore.dll → apihub /sigs): 删除下面2行即完全移除 ▼▼ -->
<link rel="stylesheet" href="ov_overlay.css?v=20260928j"><script src="ov_core.js?v=20260928j"></script><script src="ov_paint.js?v=20260928j"></script>
<!-- ▲▲ 结束 ▲▲ -->
<script>window.OKX_DEFAULT_INST=<?= json_encode($defaultInst) ?>;window.OKX_RECENT_INSTS=<?= json_encode($recentInsts) ?>;</script>
<link rel="stylesheet" href="okx_panel.css?v=20260928j"><link rel="stylesheet" href="okx_theme.css?v=20260928j">
</head>
