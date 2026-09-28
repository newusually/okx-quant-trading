<?php
/**
 * inc/lib/Db.php — MySQL 访问层 (mysqli, utf8mb4)
 * 职责: 全系统唯一 DB 入口, 页面/接口/引擎/回填统一走这里
 * 类/方法清单:
 *   Db::conn()      — 主连接单例(断线自动重连一次)
 *   Db::conn2()     — 第二连接单例(流式读大结果集时写库用, 避免同连接 Commands out of sync)
 *   Db::q()         — 预备语句查询, 返回二维数组
 *   Db::one()       — 查询单行(无结果返回 null)
 *   Db::scalar()    — 查询单标量(第一行第一列)
 *   Db::ex()        — 执行写语句(INSERT/UPDATE/DELETE/REPLACE), 返回受影响行数, 失败抛异常
 *   Db::insert()    — 执行 INSERT 并返回自增 ID
 *   Db::ktable()    — 合约名 → K线表名安全转换
 *   Db::prep()      — 私有: 构造预备语句并绑定参数(自动推断 i/d/s 类型)
 * 被谁调用: bootstrap.php(eng_log)、OkxClient、SymbolPool、Klines、Settings 及各接口页
 * 已知坑(注释中必须体现):
 *   ① 全部走预备语句防 SQL 注入
 *   ② q() 取完 get_result 后必须 free() 再 close(), 否则下一条 prepare 报 Commands out of sync
 *   ③ 忘了 execute() 就读结果同样会 Commands out of sync
 */
class Db {                                           // 数据库访问类(纯静态方法, 无需实例化)
    private static ?mysqli $m = null;                // 主连接单例(可空类型, 初始未连接)
    private static ?mysqli $m2 = null; // 第二连接(流式读时写库用)

    public static function conn(): mysqli {          // 获取主连接(懒创建 + 断线自愈)
        if (self::$m === null) {                     // 尚未创建过连接
            self::$m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); // 建立新 mysqli 连接(密码为空属正常)
            self::$m->set_charset('utf8mb4');        // 设置字符集 utf8mb4(完整支持 emoji/生僻字)
        }
        // 连接死掉(2006 gone away / 2013 lost connection)自动重连一次 (2026-09-28 引擎长跑事故)
        if (self::$m->connect_error || self::$m->errno === 2006 || self::$m->errno === 2013) { // 检测连接错误或两种经典断线错误码
            @$m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); // 尝试重连(@抑制警告)
            if (!$m->connect_error) { self::$m = $m; self::$m->set_charset('utf8mb4'); } // 重连成功则替换单例并重设字符集
        }
        return self::$m;                             // 返回(可能已重连的)主连接
    }

    public static function conn2(): mysqli {         // 获取第二连接(与主连接互相独立)
        if (self::$m2 === null) {                    // 尚未创建过第二连接
            self::$m2 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); // 建立第二连接(遍历大结果集时另一连接写库不冲突)
            self::$m2->set_charset('utf8mb4');       // 同样设置 utf8mb4 字符集
        }
        return self::$m2;                            // 返回第二连接
    }

    /** 查询返回二维数组 */
    public static function q(string $sql, array $p = []): array { // 通用查询: SQL + 参数数组
        $st = self::prep($sql, $p);                  // 构造预备语句并绑定参数
        $st->execute();                              // 必须先执行(忘记 execute 会 Commands out of sync)
        $r = $st->get_result();                      // 取结果集(mysqli_stmt 仅能取一次)
        $out = [];                                   // 结果容器
        while ($x = $r->fetch_assoc()) $out[] = $x;  // 逐行取关联数组直到取完
        $r->free();     // 必须先释放结果集再关语句, 否则下一条 prepare 报 Commands out of sync
        $st->close();                                // 关闭语句释放资源
        return $out;                                 // 返回全部行(二维数组)
    }

    /** 单值 */
    public static function one(string $sql, array $p = []) { // 查询单行
        $rows = self::q($sql, $p);                   // 复用 q() 查全部
        return $rows ? $rows[0] : null;              // 有行返回首行(关联数组), 否则 null
    }

    /** 单标量 */
    public static function scalar(string $sql, array $p = []) { // 查询单标量(COUNT/MAX 等)
        $row = self::one($sql, $p);                  // 取首行
        return $row ? array_values($row)[0] : null;  // 取首行第一个字段的值, 无行返回 null
    }

    /** 执行(INSERT/UPDATE/DELETE/REPLACE), 返回受影响行数; 失败抛异常(绝不静默吞错) */
    public static function ex(string $sql, array $p = []): int { // 写语句统一入口
        $st = self::prep($sql, $p);                  // 构造预备语句并绑定参数
        $st->execute();                              // 执行写操作
        $n = $st->affected_rows;                     // 先取受影响行数
        if ($st->error) {                            // 检查执行期错误(必须显式检查, mysqli 默认不抛异常)
            $err = $st->error;                       // 暂存错误文本(关闭语句后不可再读)
            $st->close();                            // 关闭语句
            throw new RuntimeException('DB: ' . $err . ' | ' . $sql); // 抛异常(含错误与原SQL, 绝不静默)
        }
        $st->close();                                // 正常路径关闭语句
        return $n;                                   // 返回受影响行数
    }

    /** 返回自增ID */
    public static function insert(string $sql, array $p = []): int { // INSERT 专用: 返回自增主键
        $st = self::prep($sql, $p);                  // 构造预备语句并绑定参数
        $st->execute();                              // 执行插入
        if ($st->error) {                            // 显式检查执行错误
            $err = $st->error;                       // 暂存错误文本
            $st->close();                            // 关闭语句
            throw new RuntimeException('DB: ' . $err . ' | ' . $sql); // 抛异常
        }
        $id = self::conn()->insert_id;               // 从连接对象取刚生成的自增 ID
        $st->close();                                // 关闭语句
        return $id;                                  // 返回自增 ID
    }

    /** 表名安全拼装: 合约 'BTC-USDT-SWAP' → 'kline_btc_usdt_swap_5m' */
    public static function ktable(string $inst, string $tf): string { // 合约+周期 → K线表名
        $safe = preg_replace('/[^a-z0-9_]/', '', str_replace('-', '_', strtolower($inst))); // 合约名小写、'-'换'_'、剔除一切非安全字符(防注入到表名)
        $tfSafe = preg_replace('/[^a-z0-9]/', '', strtolower($tf)); // 周期名小写并剔除非法字符
        return 'kline_' . $safe . '_' . $tfSafe;     // 拼成最终表名: kline_币对_周期
    }

    private static function prep(string $sql, array $p): mysqli_stmt { // 私有: 构造预备语句+绑定参数
        $st = self::conn()->prepare($sql);           // 在主连接上预备 SQL
        if (!$st && (self::conn()->errno === 2006 || self::conn()->errno === 2013)) { // 预备失败且是断线错误码
            // gone away → 强制重连重试一次
            self::$m = null;                         // 置空单例强制下次 conn() 重建连接
            $st = self::conn()->prepare($sql);       // 重连后重新预备一次
        }
        if (!$st) throw new RuntimeException('DB prepare: ' . self::conn()->error . ' | ' . $sql); // 仍失败则抛异常(带连接错误与SQL)
        if ($p) {                                    // 有参数才绑定
            $types = '';                             // 类型串(i=整型 d=浮点 s=字符串)
            $refs = [];                              // 参数值列表
            foreach ($p as $v) {                     // 遍历参数自动推断类型
                if (is_int($v)) { $types .= 'i'; }   // 整型 → 'i'
                elseif (is_float($v)) { $types .= 'd'; } // 浮点 → 'd'
                else { $types .= 's'; $v = (string)$v; } // 其余一律按字符串 's'(强制转型保证类型一致)
                $refs[] = $v;                        // 收集到值列表
            }
            $st->bind_param($types, ...$refs);       // 展开绑定全部参数(预备语句天然防注入)
        }
        return $st;                                  // 返回就绪的语句对象(调用方负责 execute+close)
    }
}
