<?php
/**
 * okx_live.php — 薄委托 → api.php (PHP→C++ 组件转发层), 老页面零改动兼容
 * 职责: 伪装成独立实时行情入口, 实际把 PATH_INFO 预置为 /live 后转发给 api.php
 * 被谁调用: 老前端 JS 轮询请求 okx_live.php(URL 兼容层)
 */
$_SERVER['PATH_INFO'] = '/live';                     // 预置 PATH_INFO 为 /live(api.php 将按此动作透传)
require __DIR__ . '/api.php';                        // 引入 api.php 完成全部转发逻辑
