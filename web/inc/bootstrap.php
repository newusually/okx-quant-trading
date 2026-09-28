<?php
/**
 * inc/bootstrap.php — 全系统引导 (2026-09-27 PHP+C++ 架构)
 * 职责: 常量配置 + 类自动加载(inc\lib 与 api 两个命名空间目录) + 全局函数
 * 架构: 页面(PHP显示) → api/接口类 → inc/lib 核心库 → C++ DLL(sigcore.dll, PHP FFI)
 */
define('APP_ROOT', dirname(__DIR__, 2));            // E:\finally-main
define('WEB_ROOT', dirname(__DIR__));               // E:\finally-main\web
define('DLL_PATH', APP_ROOT . '\\cpp\\bin\\sigcore.dll');
define('LOG_DIR', 'E:\\datas\\log');

// apihub (C++ 接口服务) 基址 — 页面只从这里取数, PHP 自身零 SQL/零计算/零 OKX 调用
define('API_BASE', 'http://127.0.0.1:8090');

// 时区统一为北京时间(必须, 否则回填CUTOFF/流水时间与MySQL NOW()错8小时)
date_default_timezone_set('Asia/Shanghai');

// —— 数据库 ——
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'trading');

// —— 交易铁律(代码级硬锁, 与 Go 版 build 0927A 一致) ——
define('LOCK_LEVER', 20);            // 20x 全仓
define('LOCK_ENTRY_USD', 1.0);       // 每笔 1U 保证金
define('LOCK_ADD_USD', 1.0 / 3.0);   // 加仓每轮 1U/3
define('LOCK_TP_ROI', 0.40);         // 止盈: 收益率 40% = 价格 +2% @20x
define('LOCK_FIXED_SL_PCT', 0.0);    // 永不止损
define('LOCK_MAX_POSITIONS', 12);    // 并发持仓上限
define('LOCK_MAX_BUYS_HOUR', 3);     // 每小时买入上限
define('LOCK_MAX_NEW_SCAN', 1);      // 每轮扫描最多开仓
define('LOCK_COOLDOWN_MIN', 60);     // 同合约买入冷却(分钟)

// psr-4 式自动加载: 类名 → inc/lib/类名.php 或 api/类名.php
spl_autoload_register(function ($cls) {
    foreach (['inc/lib/', 'api/'] as $dir) {
        $f = WEB_ROOT . '\\' . str_replace('/', '\\', $dir) . $cls . '.php';
        if (is_file($f)) { require_once $f; return; }
    }
});

// —— 全局小助手 ——
function json_out($arr, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($arr, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
}

function eng_log(string $level, string $module, string $msg): void {
    // 双写: 数据库 logs 表(网页可见) + 文件(排障)
    try {
        Db::ex("INSERT INTO logs (log_time,level,module,message) VALUES (NOW(),?,?,?)",
               [$level, $module, mb_substr($msg, 0, 2000)]);
    } catch (\Throwable $e) { /* DB不可用时只写文件 */ }
    @file_put_contents(LOG_DIR . '\\phpengine.txt',
        date('Y-m-d H:i:s') . " [{$level}] {$module} {$msg}\n", FILE_APPEND);
}

function file_log(string $file, string $msg): void {
    @file_put_contents(LOG_DIR . '\\' . $file, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

/**
 * api_get — 页面向 apihub(C++) 取数 (PHP 只做显示, 不做任何 SQL/计算)
 * 铁律: 数据一律来自 C++ 接口; 超时/失败返回 null, 页面降级但不报错
 */
function api_get(string $path, int $timeoutMs = 2500): ?array {
    static $ctx = null;
    if ($ctx === null) $ctx = stream_context_create(['http' => ['timeout' => $timeoutMs / 1000.0,
                                                                'header' => "Connection: close\r\n"]]);
    $t0 = microtime(true);
    $raw = @file_get_contents(API_BASE . $path, false, $ctx);
    if ($raw === false) { file_log('php_apiget.log', "FAIL {$path} (" . round((microtime(true) - $t0) * 1000) . 'ms)'); return null; }
    $j = json_decode($raw, true);
    if (!is_array($j) || (isset($j['ok']) && !$j['ok'])) { file_log('php_apiget.log', "BAD {$path} (" . round((microtime(true) - $t0) * 1000) . 'ms)'); return null; }
    return $j;
}

/** 心跳文件(覆盖写, 供 guard.exe 探测"进程是否卡死"; 日志追加不适合判活) */
function hb(string $name): void {
    static $n = 0;
    @file_put_contents(LOG_DIR . '\\hb_' . $name . '.txt',
        date('Y-m-d H:i:s') . ' loop=' . (++$n) . ' pid=' . getmypid() . "\n", LOCK_EX);
}
