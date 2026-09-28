<?php
/**
 * tools/migrate_json_db.php — 一次性迁移: 所有JSON落盘 → 数据库表 (2026-09-28 用户指令: 所有json不保存)
 *   api.json             → 表 okx_cred (OKX凭证)
 *   E:\datas\symbollist.json → 表 symbol_pool (权威合约池)
 *   strategy.json        → app_settings skey='strategy_json'
 *   E:\datas\phpfill_holes.json → app_settings skey='fill_holes'
 * 运行: E:\xampp\php\php.exe tools/migrate_json_db.php
 *
 * 【文件职责】JSON→数据库一次性迁移CLI工具: 把4个JSON落盘文件导入MySQL对应表, 迁移后打印行数验证。
 * 【功能清单】①建表+迁移 api.json→okx_cred(表结构: id主键/api_key/secret_key/passphrase/updated_at);
 *             ②建表+迁移 symbollist.json→symbol_pool(inst_id主键/updated_at);
 *             ③迁移 strategy.json→app_settings(strategy_json); ④迁移 phpfill_holes.json→app_settings(fill_holes);
 *             ⑤最后COUNT(*)验证各表行数。幂等: 已迁移过的键会显示[SKIP]。
 */
require_once dirname(__DIR__) . '\\web\\inc\\bootstrap.php';  // 引导文件: 载入Db/Settings等基础设施(定义APP_ROOT等常量)

echo "== JSON → DB 迁移开始 ==\n";                       // 打印迁移开始横幅

// ① okx_cred 表: 建表(结构: id主键/api_key/secret_key/passphrase/updated_at, InnoDB)
Db::ex("CREATE TABLE IF NOT EXISTS okx_cred (
    id TINYINT PRIMARY KEY,
    api_key VARCHAR(128) NOT NULL DEFAULT '',
    secret_key VARCHAR(128) NOT NULL DEFAULT '',
    passphrase VARCHAR(128) NOT NULL DEFAULT '',
    updated_at DATETIME
) ENGINE=InnoDB");                                       // 建OKX凭证表(不存在才建; id固定1单凭证, 各密钥字段默认空串)
$credFile = APP_ROOT . '\\api.json';                     // 凭证JSON文件路径
if (is_file($credFile)) {                                // 文件存在才迁移
    $c = json_decode(file_get_contents($credFile), true) ?: [];  // 解析JSON(失败置空数组)
    if (!empty($c['api_key'])) {                         // 有api_key才写入
        Db::ex("REPLACE INTO okx_cred (id,api_key,secret_key,passphrase,updated_at) VALUES (1,?,?,?,NOW())",  // 整行替换写入id=1
            [$c['api_key'], $c['secret_key'] ?? '', $c['passphrase'] ?? '']);  // 参数绑定防注入, 缺失字段置空串
        echo "[OK] api.json → okx_cred (key=" . substr($c['api_key'], 0, 6) . "***)\n";  // 打印成功(key只露前6位脱敏)
    }
} else {                                                 // 文件不存在
    echo "[SKIP] api.json 不存在\n";                     // 打印跳过
}

// ② symbol_pool 表: 建表(结构: inst_id主键/updated_at, InnoDB)
Db::ex("CREATE TABLE IF NOT EXISTS symbol_pool (
    inst_id VARCHAR(32) PRIMARY KEY,
    updated_at DATETIME
) ENGINE=InnoDB");                                       // 建权威合约池表(不存在才建; inst_id=合约名主键)
$slFile = 'E:\\datas\\symbollist.json';                  // 合约池JSON文件路径
if (is_file($slFile)) {                                  // 文件存在才迁移
    $j = json_decode(file_get_contents($slFile), true) ?: [];  // 解析JSON(失败置空数组)
    $insts = $j['insts'] ?? (is_array($j) && isset($j[0]) ? $j : []);  // 兼容两种格式: {insts:[...]}对象 或 纯数组
    $n = 0;                                              // 成功写入计数器
    foreach ($insts as $inst) {                          // 遍历每个合约名
        if (!preg_match('/^[A-Z0-9\-]{1,32}$/', (string)$inst)) continue;  // 合约名格式校验(大写字母数字连字符, ≤32位), 非法跳过
        Db::ex("REPLACE INTO symbol_pool (inst_id,updated_at) VALUES (?,NOW())", [$inst]);  // 预处理写入(REPLACE幂等, 更新时间=now)
        $n++;                                            // 计数+1
    }
    echo "[OK] symbollist.json → symbol_pool ($n 个合约)\n";  // 打印成功与入库数量
} else {                                                 // 文件不存在
    echo "[SKIP] symbollist.json 不存在\n";              // 打印跳过
}

// ③ strategy.json → app_settings (skey='strategy_json')
$sjFile = APP_ROOT . '\\strategy.json';                  // 策略JSON文件路径
if (is_file($sjFile) && !Settings::get('strategy_json')) {  // 文件存在且尚未迁移过才执行(幂等)
    Settings::set('strategy_json', (string)file_get_contents($sjFile));  // 整个JSON原文存入app_settings
    echo "[OK] strategy.json → app_settings.strategy_json\n";  // 打印成功
} else {                                                 // 文件不存在或已迁移
    echo "[SKIP] strategy.json (不存在或已迁移)\n";      // 打印跳过
}

// ④ phpfill_holes.json → app_settings (skey='fill_holes')
$hfFile = 'E:\\datas\\phpfill_holes.json';               // 补洞配置JSON文件路径
if (is_file($hfFile) && !Settings::get('fill_holes')) {  // 文件存在且尚未迁移过才执行(幂等)
    Settings::set('fill_holes', (string)file_get_contents($hfFile));  // JSON原文存入app_settings
    echo "[OK] phpfill_holes.json → app_settings.fill_holes\n";  // 打印成功
} else {                                                 // 文件不存在或已迁移
    echo "[SKIP] phpfill_holes.json (不存在或已迁移)\n"; // 打印跳过
}

// 验证: 各表行数统计
echo "okx_cred行数=" . (int)Db::scalar("SELECT COUNT(*) FROM okx_cred") . "\n";   // 查凭证表行数验证迁移结果
echo "symbol_pool行数=" . (int)Db::scalar("SELECT COUNT(*) FROM symbol_pool") . "\n";  // 查合约池表行数验证迁移结果
echo "== 迁移完成 ==\n";                                 // 打印完成横幅
