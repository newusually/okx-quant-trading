<?php
/**
 * okx_api.php — 薄委托 → api.php (PHP→C++ 组件转发层), 老页面零改动兼容
 * 2026-09-28 组件化: PHP 零计算零SQL, 全部转发 C++ 组件 apihub
 */
$_SERVER['PATH_INFO'] = '/kline';
require __DIR__ . '/api.php';
