<?php
/**
 * okx_api.php — 薄委托 → api.php (PHP→C++ 组件转发层), 老页面零改动兼容
 * 职责: 伪装成独立 K线接口入口, 实际把 PATH_INFO 预置为 /kline 后转发给 api.php
 * 被谁调用: 老前端 JS 直接请求 okx_api.php(URL 兼容层), 参数原样跟在查询串里
 */
$_SERVER['PATH_INFO'] = '/kline';                    // 预置 PATH_INFO 为 /kline(api.php 将按此动作透传)
require __DIR__ . '/api.php';                        // 引入 api.php 完成全部转发逻辑
