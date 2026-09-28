<?php
/**
 * okx_live.php — 薄委托 → api.php (PHP→C++ 组件转发层), 老页面零改动兼容
 */
$_SERVER['PATH_INFO'] = '/live';
require __DIR__ . '/api.php';
