<?php
/**
 * api.php — PHP 接口层（零计算 / 零 SQL）: 全部转发给 C++ 组件 apihub(127.0.0.1:8090)
 * 职责: 前端 AJAX 的唯一 PHP 入口, 白名单校验 action 后把请求原样透传给 C++ apihub
 * 架构: 浏览器(AJAX + JS + CSS) → PHP(本文件) → C++ 组件
 *  2026-09-28 用户指令: 以后全部改成组件, 必须用 PHP 调用组件 + AJAX + JS + CSS
 * 兼容两种调用方式:
 *   api.php/<action>?...   (PATH_INFO, 前端 window.AH='api.php')
 *   api.php?action=<action>&...
 * 被谁调用: 前端各页面 JS(okx_panel.js 等) 以及薄委托层(okx_api.php/okx_live.php require 本文件)
 */
header('Content-Type: application/json; charset=utf-8');   // 响应头: JSON + UTF-8
header('Cache-Control: no-store, no-cache');               // 响应头: 双重禁缓存(行情/状态必须实时)
header('Access-Control-Allow-Origin: *');                  // CORS: 允许任意来源跨域调用

$action = '';                                              // 动作名变量初始化
if (!empty($_SERVER['PATH_INFO']))       $action = trim($_SERVER['PATH_INFO'], '/');  // 方式一: PATH_INFO 路径段(去掉首尾斜杠)
elseif (isset($_GET['action']))          $action = trim((string)$_GET['action'], '/'); // 方式二: ?action= 查询参数
if ($action === '') {                                      // 两种方式都没给出动作
    echo '{"ok":false,"error":"no action, use api.php/<action> or api.php?action="}'; // 输出缺参错误 JSON
    exit;                                                  // 终止
}

/* C++ 组件(apihub) 暴露的全部接口白名单 */
$ALLOW = ['kline','live','ticker','trades','stats','livestats','gridmon','backcheck',
          'marks','sigs','sigscan','boot','symbols','settings','account','health','guard','frag']; // 允许透传的 action 白名单(其余一律404)
if (!in_array($action, $ALLOW, true)) {                    // 动作不在白名单内(严格比较防类型混淆)
    http_response_code(404);                               // 返回 404
    echo '{"ok":false,"error":"unknown action"}';          // 输出未知动作错误
    exit;                                                  // 终止
}

/* 透传查询串(?action= 方式时剔除 action 自身) */
$qs = $_SERVER['QUERY_STRING'] ?? '';                      // 取原始查询串
if (isset($_GET['action'])) {                              // 若 action 来自查询参数
    parse_str($qs, $p);                                    // 解析查询串为关联数组
    unset($p['action']);                                   // 剔除 action 自身(它不是 apihub 的参数)
    $qs = http_build_query($p);                            // 重新编码剩余参数
}
$url = 'http://127.0.0.1:8090/' . rawurlencode($action) . ($qs !== '' ? '?' . $qs : ''); // 组装 apihub 目标 URL(action 编码 + 透传参数)

$ch = curl_init($url);                                     // 初始化 cURL 会话
curl_setopt_array($ch, [                                   // 批量设置选项
    CURLOPT_RETURNTRANSFER => true,                        // 响应以字符串返回
    CURLOPT_TIMEOUT        => 10,                          // 总超时 10 秒
    CURLOPT_CONNECTTIMEOUT => 2,                           // 连接超时 2 秒(apihub 本地, 应秒连)
]);
$body = curl_exec($ch);                                    // 执行请求
$err  = curl_error($ch);                                   // 记录错误文本
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);        // 取 apihub 返回的 HTTP 状态码
curl_close($ch);                                           // 关闭会话

if ($body === false || $code === 0) {                      // 请求失败或未拿到状态码(连接不上)
    http_response_code(502);                               // 返回 502 Bad Gateway
    echo json_encode(['ok' => false, 'error' => 'C++ 组件(apihub) 不可达: ' . $err], JSON_UNESCAPED_UNICODE); // 输出组件不可达错误
    exit;                                                  // 终止
}
http_response_code($code === 200 ? 200 : max(200, min(599, $code))); // 透传 apihub 状态码(夹在 200~599 合法区间)
echo $body;                                                // 原样输出 apihub 响应体(不解析不加工, 保持零计算)
