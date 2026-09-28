<?php
/**
 * inc/lib/Settings.php — 系统设置(app_settings 表 KV)
 */
class Settings {
    public static function get(string $key, string $def = ''): string {
        $v = Db::scalar("SELECT sval FROM app_settings WHERE skey=?", [$key]);
        return $v !== null ? (string)$v : $def;
    }

    public static function set(string $key, string $val): void {
        Db::ex("REPLACE INTO app_settings (skey,sval) VALUES (?,?)", [$key, $val]);
    }
}
