<?php
/**
 * inc/lib/Settings.php — 系统设置(app_settings 表 KV)
 * 职责: 封装 app_settings 键值表的读写(键=skey 列, 值=sval 列 — 注意列名是 sval 不是 val)
 * 方法清单:
 *   Settings::get() — 按 key 读值, 无值返回默认值
 *   Settings::set() — 按 key 写值(REPLACE 幂等)
 * 被谁调用: 各接口页(开关/阈值等配置项的存取)
 */
class Settings {                                     // 设置表包装类(纯静态)
    public static function get(string $key, string $def = ''): string { // 读设置项
        $v = Db::scalar("SELECT sval FROM app_settings WHERE skey=?", [$key]); // 预备语句按 key 查 sval 列
        return $v !== null ? (string)$v : $def;      // 查到返回值(转字符串), 否则返回默认值
    }

    public static function set(string $key, string $val): void { // 写设置项
        Db::ex("REPLACE INTO app_settings (skey,sval) VALUES (?,?)", [$key, $val]); // REPLACE 语义: 存在则整行替换, 不存在则插入
    }
}
