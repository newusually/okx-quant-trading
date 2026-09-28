<?php
/**
 * inc/lib/Db.php — MySQL 访问层 (mysqli, utf8mb4)
 * 全系统唯一 DB 入口: 页面/接口/引擎/回填统一走这里
 */
class Db {
    private static ?mysqli $m = null;
    private static ?mysqli $m2 = null; // 第二连接(流式读时写库用)

    public static function conn(): mysqli {
        if (self::$m === null) {
            self::$m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            self::$m->set_charset('utf8mb4');
        }
        // 连接死掉(2006 gone away / 2013 lost connection)自动重连一次 (2026-09-28 引擎长跑事故)
        if (self::$m->connect_error || self::$m->errno === 2006 || self::$m->errno === 2013) {
            @$m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if (!$m->connect_error) { self::$m = $m; self::$m->set_charset('utf8mb4'); }
        }
        return self::$m;
    }

    public static function conn2(): mysqli {
        if (self::$m2 === null) {
            self::$m2 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            self::$m2->set_charset('utf8mb4');
        }
        return self::$m2;
    }

    /** 查询返回二维数组 */
    public static function q(string $sql, array $p = []): array {
        $st = self::prep($sql, $p);
        $st->execute();
        $r = $st->get_result();
        $out = [];
        while ($x = $r->fetch_assoc()) $out[] = $x;
        $r->free();     // 必须先释放结果集再关语句, 否则下一条 prepare 报 Commands out of sync
        $st->close();
        return $out;
    }

    /** 单值 */
    public static function one(string $sql, array $p = []) {
        $rows = self::q($sql, $p);
        return $rows ? $rows[0] : null;
    }

    /** 单标量 */
    public static function scalar(string $sql, array $p = []) {
        $row = self::one($sql, $p);
        return $row ? array_values($row)[0] : null;
    }

    /** 执行(INSERT/UPDATE/DELETE/REPLACE), 返回受影响行数; 失败抛异常(绝不静默吞错) */
    public static function ex(string $sql, array $p = []): int {
        $st = self::prep($sql, $p);
        $st->execute();
        $n = $st->affected_rows;
        if ($st->error) {
            $err = $st->error;
            $st->close();
            throw new RuntimeException('DB: ' . $err . ' | ' . $sql);
        }
        $st->close();
        return $n;
    }

    /** 返回自增ID */
    public static function insert(string $sql, array $p = []): int {
        $st = self::prep($sql, $p);
        $st->execute();
        if ($st->error) {
            $err = $st->error;
            $st->close();
            throw new RuntimeException('DB: ' . $err . ' | ' . $sql);
        }
        $id = self::conn()->insert_id;
        $st->close();
        return $id;
    }

    /** 表名安全拼装: 合约 'BTC-USDT-SWAP' → 'kline_btc_usdt_swap_5m' */
    public static function ktable(string $inst, string $tf): string {
        $safe = preg_replace('/[^a-z0-9_]/', '', str_replace('-', '_', strtolower($inst)));
        $tfSafe = preg_replace('/[^a-z0-9]/', '', strtolower($tf));
        return 'kline_' . $safe . '_' . $tfSafe;
    }

    private static function prep(string $sql, array $p): mysqli_stmt {
        $st = self::conn()->prepare($sql);
        if (!$st && (self::conn()->errno === 2006 || self::conn()->errno === 2013)) {
            // gone away → 强制重连重试一次
            self::$m = null;
            $st = self::conn()->prepare($sql);
        }
        if (!$st) throw new RuntimeException('DB prepare: ' . self::conn()->error . ' | ' . $sql);
        if ($p) {
            $types = '';
            $refs = [];
            foreach ($p as $v) {
                if (is_int($v)) { $types .= 'i'; }
                elseif (is_float($v)) { $types .= 'd'; }
                else { $types .= 's'; $v = (string)$v; }
                $refs[] = $v;
            }
            $st->bind_param($types, ...$refs);
        }
        return $st;
    }
}
