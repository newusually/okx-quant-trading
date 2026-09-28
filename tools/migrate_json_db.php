<?php
/**
 * tools/migrate_json_db.php — 一次性迁移: 所有JSON落盘 → 数据库表 (2026-09-28 用户指令: 所有json不保存)
 *   api.json             → 表 okx_cred (OKX凭证)
 *   E:\datas\symbollist.json → 表 symbol_pool (权威合约池)
 *   strategy.json        → app_settings skey='strategy_json'
 *   E:\datas\phpfill_holes.json → app_settings skey='fill_holes'
 * 运行: E:\xampp\php\php.exe tools/migrate_json_db.php
 */
require_once dirname(__DIR__) . '\\web\\inc\\bootstrap.php';

echo "== JSON → DB 迁移开始 ==\n";

// ① okx_cred 表
Db::ex("CREATE TABLE IF NOT EXISTS okx_cred (
    id TINYINT PRIMARY KEY,
    api_key VARCHAR(128) NOT NULL DEFAULT '',
    secret_key VARCHAR(128) NOT NULL DEFAULT '',
    passphrase VARCHAR(128) NOT NULL DEFAULT '',
    updated_at DATETIME
) ENGINE=InnoDB");
$credFile = APP_ROOT . '\\api.json';
if (is_file($credFile)) {
    $c = json_decode(file_get_contents($credFile), true) ?: [];
    if (!empty($c['api_key'])) {
        Db::ex("REPLACE INTO okx_cred (id,api_key,secret_key,passphrase,updated_at) VALUES (1,?,?,?,NOW())",
            [$c['api_key'], $c['secret_key'] ?? '', $c['passphrase'] ?? '']);
        echo "[OK] api.json → okx_cred (key=" . substr($c['api_key'], 0, 6) . "***)\n";
    }
} else {
    echo "[SKIP] api.json 不存在\n";
}

// ② symbol_pool 表
Db::ex("CREATE TABLE IF NOT EXISTS symbol_pool (
    inst_id VARCHAR(32) PRIMARY KEY,
    updated_at DATETIME
) ENGINE=InnoDB");
$slFile = 'E:\\datas\\symbollist.json';
if (is_file($slFile)) {
    $j = json_decode(file_get_contents($slFile), true) ?: [];
    $insts = $j['insts'] ?? (is_array($j) && isset($j[0]) ? $j : []);
    $n = 0;
    foreach ($insts as $inst) {
        if (!preg_match('/^[A-Z0-9\-]{1,32}$/', (string)$inst)) continue;
        Db::ex("REPLACE INTO symbol_pool (inst_id,updated_at) VALUES (?,NOW())", [$inst]);
        $n++;
    }
    echo "[OK] symbollist.json → symbol_pool ($n 个合约)\n";
} else {
    echo "[SKIP] symbollist.json 不存在\n";
}

// ③ strategy.json → app_settings
$sjFile = APP_ROOT . '\\strategy.json';
if (is_file($sjFile) && !Settings::get('strategy_json')) {
    Settings::set('strategy_json', (string)file_get_contents($sjFile));
    echo "[OK] strategy.json → app_settings.strategy_json\n";
} else {
    echo "[SKIP] strategy.json (不存在或已迁移)\n";
}

// ④ phpfill_holes.json → app_settings
$hfFile = 'E:\\datas\\phpfill_holes.json';
if (is_file($hfFile) && !Settings::get('fill_holes')) {
    Settings::set('fill_holes', (string)file_get_contents($hfFile));
    echo "[OK] phpfill_holes.json → app_settings.fill_holes\n";
} else {
    echo "[SKIP] phpfill_holes.json (不存在或已迁移)\n";
}

// 验证
echo "okx_cred行数=" . (int)Db::scalar("SELECT COUNT(*) FROM okx_cred") . "\n";
echo "symbol_pool行数=" . (int)Db::scalar("SELECT COUNT(*) FROM symbol_pool") . "\n";
echo "== 迁移完成 ==\n";
