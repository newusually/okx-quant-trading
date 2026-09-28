<?php
/**
 * inc/bootstrap.php — 全系统引导 (2026-09-27 PHP+C++ 架构)
 * 职责: 常量配置 + 类自动加载(inc\lib 与 api 两个命名空间目录) + 全局函数
 * 架构: 页面(PHP显示) → api/接口类 → inc/lib 核心库 → C++ DLL(sigcore.dll, PHP FFI)
 * 函数清单:
 *   json_out()  — 输出 JSON 响应(所有接口出口统一走这里)
 *   eng_log()   — 引擎日志双写: MySQL logs 表 + E:\datas\log\phpengine.txt 文件
 *   file_log()  — 纯文件日志(排障用, 不依赖 DB)
 *   api_get()   — 页面向 C++ apihub(127.0.0.1:8090) 发 HTTP GET 取数, 失败返回 null
 *   hb()        — 心跳文件覆盖写, 供 guard.exe 探测进程是否卡死
 * 被谁调用: web/ 下所有 .php 页面与接口入口(require/require_once 引入本文件)
 * 注意: DB_PASS 为空是正常现象 — 本机 MariaDB root 账户未设置密码, 不是配置遗漏
 */
define('APP_ROOT', dirname(__DIR__, 2));            // 项目根目录常量: E:\finally-main(向上两级)
define('WEB_ROOT', dirname(__DIR__));               // web 目录常量: E:\finally-main\web(向上一级)
define('DLL_PATH', APP_ROOT . '\\cpp\\bin\\sigcore.dll'); // C++ 计算库 DLL 完整路径(供 SigCore FFI 加载)
define('LOG_DIR', 'E:\\datas\\log');                // 全部日志文件的存放目录

// apihub (C++ 接口服务) 基址 — 页面只从这里取数, PHP 自身零 SQL/零计算/零 OKX 调用
define('API_BASE', 'http://127.0.0.1:8090');        // C++ 接口服务的本地监听地址(仅本机可达)

// 时区统一为北京时间(必须, 否则回填CUTOFF/流水时间与MySQL NOW()错8小时)
date_default_timezone_set('Asia/Shanghai');         // 设置 PHP 默认时区为东八区

// —— 数据库 ——
define('DB_HOST', '127.0.0.1');                     // 数据库主机: 本机回环地址
define('DB_USER', 'root');                          // 数据库用户名: root
define('DB_PASS', '');                              // 数据库密码: 空(本机 MariaDB root 无密码, 属正常)
define('DB_NAME', 'trading');                       // 数据库名: trading(全部业务表在此库)

// —— 交易铁律(代码级硬锁, 与 Go 版 build 0927A 一致) ——
define('LOCK_LEVER', 20);            // 20x 全仓(杠杆铁律, 全系统统一 20 倍)
define('LOCK_ENTRY_USD', 1.0);       // 每笔 1U 保证金(单笔开仓保证金固定 1 USDT)
define('LOCK_ADD_USD', 1.0 / 3.0);   // 加仓每轮 1U/3(每轮加仓保证金 = 1/3 USDT)
define('LOCK_TP_ROI', 0.40);         // 止盈: 收益率 40% = 价格 +2% @20x(20倍杠杆下 2% 价格波动即 40% 收益)
define('LOCK_FIXED_SL_PCT', 0.0);    // 永不止损(系统铁律: 不设固定止损, 只靠加仓摊平+止盈)
define('LOCK_MAX_POSITIONS', 12);    // 并发持仓上限(同时最多持有 12 个合约仓位)
define('LOCK_MAX_BUYS_HOUR', 3);     // 每小时买入上限(每小时最多执行 3 笔买入)
define('LOCK_MAX_NEW_SCAN', 1);      // 每轮扫描最多开仓(每轮扫描最多新开 1 个仓)
define('LOCK_COOLDOWN_MIN', 60);     // 同合约买入冷却(分钟)(同一合约两次买入间隔至少 60 分钟)

// psr-4 式自动加载: 类名 → inc/lib/类名.php 或 api/类名.php
spl_autoload_register(function ($cls) {              // 注册 SPL 自动加载器, 未定义类时自动触发
    foreach (['inc/lib/', 'api/'] as $dir) {         // 依次尝试 inc/lib 和 api 两个候选目录
        $f = WEB_ROOT . '\\' . str_replace('/', '\\', $dir) . $cls . '.php'; // 拼出候选文件完整路径(Windows 反斜杠)
        if (is_file($f)) { require_once $f; return; } // 文件存在则引入一次并结束查找
    }
});

// —— 全局小助手 ——
function json_out($arr, int $code = 200): void {     // 全局函数: 以 JSON 格式输出响应并结束
    http_response_code($code);                       // 设置 HTTP 状态码(默认 200)
    header('Content-Type: application/json; charset=utf-8'); // 响应头: JSON + UTF-8
    header('Cache-Control: no-store');               // 响应头: 禁止任何缓存(行情数据必须实时)
    echo json_encode($arr, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE); // 输出 JSON(非法UTF8替换输出, 中文不转义)
}

function eng_log(string $level, string $module, string $msg): void { // 全局函数: 引擎级日志(级别/模块/消息)
    // 双写: 数据库 logs 表(网页可见) + 文件(排障)
    try {                                            // DB 写入可能因表缺失/连接断开而失败
        Db::ex("INSERT INTO logs (log_time,level,module,message) VALUES (NOW(),?,?,?)", // 写入 logs 表(时间取数据库 NOW)
               [$level, $module, mb_substr($msg, 0, 2000)]); // 参数绑定: 级别/模块/消息(截断至2000字符防超长)
    } catch (\Throwable $e) { /* DB不可用时只写文件 */ } // 静默吞掉 DB 异常, 保证文件日志一定执行
    @file_put_contents(LOG_DIR . '\\phpengine.txt',  // 追加写入 phpengine.txt 文件日志(@抑制错误)
        date('Y-m-d H:i:s') . " [{$level}] {$module} {$msg}\n", FILE_APPEND); // 行格式: 时间 [级别] 模块 消息
}

function file_log(string $file, string $msg): void { // 全局函数: 纯文件日志(不写数据库)
    @file_put_contents(LOG_DIR . '\\' . $file, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND); // 追加写指定日志文件, 前缀时间戳
}

/**
 * api_get — 页面向 apihub(C++) 取数 (PHP 只做显示, 不做任何 SQL/计算)
 * 铁律: 数据一律来自 C++ 接口; 超时/失败返回 null, 页面降级但不报错
 */
function api_get(string $path, int $timeoutMs = 2500): ?array { // 全局函数: HTTP GET 调 C++ apihub, 返回解码数组或 null
    static $ctx = null;                              // 静态缓存 stream 上下文, 避免每次调用重建
    if ($ctx === null) $ctx = stream_context_create(['http' => ['timeout' => $timeoutMs / 1000.0, // 创建 HTTP 流上下文, 超时由毫秒换算为秒
                                                                'header' => "Connection: close\r\n"]]); // 请求头: 短连接(取完即断, 不占用 apihub 连接)
    $t0 = microtime(true);                           // 记录起始时间戳(用于统计耗时)
    $raw = @file_get_contents(API_BASE . $path, false, $ctx); // 发起 HTTP GET(@抑制连接失败的警告)
    if ($raw === false) { file_log('php_apiget.log', "FAIL {$path} (" . round((microtime(true) - $t0) * 1000) . 'ms)'); return null; } // 网络失败: 记日志(含耗时ms)并返回 null
    $j = json_decode($raw, true);                    // 解码 JSON 响应为关联数组
    if (!is_array($j) || (isset($j['ok']) && !$j['ok'])) { file_log('php_apiget.log', "BAD {$path} (" . round((microtime(true) - $t0) * 1000) . 'ms)'); return null; } // 响应非数组或 ok=false: 记日志并返回 null
    return $j;                                       // 返回解码成功的数组
}

/** 心跳文件(覆盖写, 供 guard.exe 探测"进程是否卡死"; 日志追加不适合判活) */
function hb(string $name): void {                    // 全局函数: 写心跳文件(按名称区分进程)
    static $n = 0;                                   // 静态计数器: 记录本轮进程循环次数
    @file_put_contents(LOG_DIR . '\\hb_' . $name . '.txt', // 覆盖写心跳文件(不用 FILE_APPEND)
        date('Y-m-d H:i:s') . ' loop=' . (++$n) . ' pid=' . getmypid() . "\n", LOCK_EX); // 内容: 时间+循环次数+进程号, 加排他锁防并发写坏
}
