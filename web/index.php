<?php
/**
 * index.php — 旧版面板入口薄壳
 * 职责: 0928v2 起主页入口直接 302 跳转到新版 Vue3 终端 /v2/index.html
 * 旧 PHP 壳页面已归档至 _archived_20260928/, 页面拆分逻辑(pages/ 分块 include)随之退役
 * 被谁调用: 浏览器直接访问站点根路径 / 时由 Apache/Nginx 默认文档命中本文件
 */
// 0928v2: 主页入口跳转到新版 Vue3 终端 /v2/ (旧 PHP 壳已归档 _archived_20260928/)
header("Location: /v2/index.html");                  // 发送 302 Location 头, 重定向到新版终端首页
exit;                                                // 立即终止脚本(跳转后不执行任何后续逻辑)
