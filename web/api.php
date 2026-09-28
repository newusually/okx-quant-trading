<?php
/**
 * api.php — PHP 接口层（零计算 / 零 SQL）: 全部转发给 C++ 组件 apihub(127.0.0.1:8090)
 * 架构: 浏览器(AJAX + JS + CSS) → PHP(本文件) → C++ 组件
 *  2026-09-28 用户指令: 以后全部改成组件, 必须用 PHP 调用组件 + AJAX + JS + CSS
 * 兼容两种调用方式:
 *   api.php/<action>?...   (PATH_INFO, 前端 window.AH='api.php')
 *   api.php?action=<action>&...
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');

$action = '';
if (!empty($_SERVER['PATH_INFO']))       $action = trim($_SERVER['PATH_INFO'], '/');
elseif (isset($_GET['action']))          $action = trim((string)$_GET['action'], '/');
if ($action === '') {
    echo '{"ok":false,"error":"no action, use api.php/<action> or api.php?action="}';
    exit;
}

/* C++ 组件(apihub) 暴露的全部接口白名单 */
$ALLOW = ['kline','live','ticker','trades','stats','livestats','gridmon','backcheck',
          'marks','sigs','sigscan','boot','symbols','settings','account','health','guard','frag'];
if (!in_array($action, $ALLOW, true)) {
    http_response_code(404);
    echo '{"ok":false,"error":"unknown action"}';
    exit;
}

/* 透传查询串(?action= 方式时剔除 action 自身) */
$qs = $_SERVER['QUERY_STRING'] ?? '';
if (isset($_GET['action'])) {
    parse_str($qs, $p);
    unset($p['action']);
    $qs = http_build_query($p);
}
$url = 'http://127.0.0.1:8090/' . rawurlencode($action) . ($qs !== '' ? '?' . $qs : '');

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 2,
]);
$body = curl_exec($ch);
$err  = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $code === 0) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'C++ 组件(apihub) 不可达: ' . $err], JSON_UNESCAPED_UNICODE);
    exit;
}
http_response_code($code === 200 ? 200 : max(200, min(599, $code)));
echo $body;
