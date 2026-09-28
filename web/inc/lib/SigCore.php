<?php
/**
 * inc/lib/SigCore.php — C++ DLL 计算库包装 (PHP FFI → sigcore.dll)
 * 职责: 通过 PHP FFI 调用 sigcore.dll 中的信号计算函数(金▲信号/MACD/转折点/内存K线库)
 * 前提: php.ini 必须 ffi.enable=true, 且 DLL 存在于 DLL_PATH
 * 两种用法:
 *   ① 常驻内存库(引擎用): feed() 喂增量K线 → 数据滞留 C++ 进程内存 → storeGold() 直算
 *   ② 无状态计算(网页用): goldSignal() / macd() 每次传数组
 * 信号口径与网页 ov_pivot.js/ov_physics.js 及 Go goldsig.go 逐字一致
 * 方法清单:
 *   ffi()        — 私有: 懒加载 DLL 并声明全部 C 函数原型
 *   version()    — DLL 版本号(启动自检用)
 *   toC()        — 私有: PHP 二维数组 → C double 数组(o,h,l,c,v 交错排列)
 *   goldSignal() — 无状态金▲信号
 *   macd()       — 无状态 MACD(12,26,9)
 *   pivotsCalc() — 无状态转折点+力学标注
 *   feed()       — 内存库喂增量K线(key="INST|bar")
 *   storeGold()  — 内存库金▲信号(数据版本缓存, 重复调用零开销)
 *   storePit()   — 内存库黄金坑信号(六维)
 *   storeCount()/storeStats() — 内存库条数/统计
 * 被谁调用: 交易引擎(常驻)与各网页信号接口(无状态)
 */
class SigCore {                                      // sigcore.dll 的 FFI 包装类(纯静态)
    private static ?FFI $ffi = null;                 // FFI 实例单例(懒加载)

    private static function ffi(): FFI {             // 私有: 获取(或首次创建) FFI 实例
        if (self::$ffi === null) {                   // 尚未加载过 DLL
            if (!is_file(DLL_PATH)) throw new RuntimeException('sigcore.dll 不存在: ' . DLL_PATH); // DLL 缺失直接抛异常
            self::$ffi = FFI::cdef("                 // 用 C 声明式定义 DLL 导出函数原型
                int dll_version();                   // DLL 版本号查询
                int gold_signal(const double* rows, int n, const char* bar, double* ke, double* th, int* dist, char* info, int infoLen); // 无状态金▲信号计算
                int macd_calc(const double* close, int n, int fast, int slow, int sig, double* dif, double* dea, double* hist); // 无状态 MACD 计算
                int pivots_calc(const double* rows, int n, const char* bar, int* idx, int* type, double* ke, double* g, double* f, int maxP); // 无状态转折点+力学计算
                int store_upsert(const char* key, const long long* ts, const double* bars, int n); // 内存库: 按 key 插入/更新K线
                int store_gold(const char* key, const char* bar, double* ke, double* th, int* dist, char* info, int len); // 内存库: 金▲信号直算
                int store_pit(const char* key, const char* bar, double* score, char* info, int len); // 内存库: 黄金坑信号直算
                int store_count(const char* key);    // 内存库: 查询 key 下K线条数
                int store_stats();                   // 内存库: 全局统计
            ", DLL_PATH);                            // 从 DLL_PATH 加载实现
        }
        return self::$ffi;                           // 返回 FFI 实例
    }

    public static function version(): int { return self::ffi()->dll_version(); } // 转发: 查询 DLL 版本号

    /** rows: [[o,h,l,c,v]...] 升序 → C 内存数组 */
    private static function toC(array $rows): array { // 私有: PHP 行数组转 C 连续内存
        $n = count($rows);                           // K线根数
        $arr = FFI::new("double[" . max(1, $n * 5) . "]"); // 分配 C 数组(每根5个double, 至少1个元素)
        $i = 0;                                      // 写入游标
        foreach ($rows as $r) {                      // 逐根K线摊平写入
            $arr[$i++] = (float)$r[0]; $arr[$i++] = (float)$r[1]; $arr[$i++] = (float)$r[2]; // 依次写 o h l
            $arr[$i++] = (float)$r[3]; $arr[$i++] = (float)($r[4] ?? 0);                     // 写 c v(缺 vol 补0)
        }
        return [$arr, $n];                           // 返回 [C数组, 根数]
    }

    /** 无状态金▲信号: rows=[[o,h,l,c,v]...] → [fired, ke, th, dist, info] */
    public static function goldSignal(array $rows, string $bar): array { // 无状态金▲信号入口
        [$arr, $n] = self::toC($rows);               // 转换数据为 C 内存
        $ke = FFI::new('double'); $th = FFI::new('double'); $dist = FFI::new('int'); // 分配出参: 动能/阈值/距离
        $info = FFI::new('char[256]');               // 分配信息字符串缓冲区(256字节)
        $r = self::ffi()->gold_signal($arr, $n, $bar, FFI::addr($ke), FFI::addr($th), FFI::addr($dist), $info, 256); // 调 DLL 计算(出参传地址)
        return [$r === 1, (float)$ke->cdata, (float)$th->cdata, (int)$dist->cdata, FFI::string($info)]; // 返回 [是否触发, 动能, 阈值, 距离, 信息串]
    }

    /** MACD: closes[] → [dif[], dea[], hist[]] */
    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $sig = 9): array { // 无状态 MACD(默认12/26/9)
        $n = count($closes);                         // 收盘价个数
        if (!$n) return [[], [], []];                // 空输入直接返回三个空数组
        $c = FFI::new("double[$n]"); $dif = FFI::new("double[$n]");   // 分配输入收盘价数组与 DIF 出参数组
        $dea = FFI::new("double[$n]"); $hist = FFI::new("double[$n]"); // 分配 DEA 与 柱状(HIST) 出参数组
        $i = 0; foreach ($closes as $v) $c[$i++] = (float)$v;          // 收盘价写入 C 数组
        self::ffi()->macd_calc($c, $n, $fast, $slow, $sig, $dif, $dea, $hist); // 调 DLL 计算 MACD 三线
        $od = []; $oa = []; $oh = [];                // PHP 侧输出数组
        for ($k = 0; $k < $n; $k++) { $od[] = $dif[$k]; $oa[] = $dea[$k]; $oh[] = $hist[$k]; } // 从 C 数组逐元素拷回 PHP
        return [$od, $oa, $oh];                      // 返回 [dif[], dea[], hist[]]
    }

    /** 全部转折点+力学标注: rows=[[o,h,l,c,v]...] → [[t(占位0),type,ke,g,f]...] */
    public static function pivotsCalc(array $rows, string $bar): array { // 无状态转折点计算(缠论分型+力学)
        [$arr, $n] = self::toC($rows);               // 数据转 C 内存
        $maxP = min(64, max(4, $n));                 // 转折点容量上限(夹在 4~64 之间)
        $idx = FFI::new("int[$maxP]"); $type = FFI::new("int[$maxP]");               // 出参: 索引数组 / 类型(顶底分型)数组
        $ke = FFI::new("double[$maxP]"); $g = FFI::new("double[$maxP]"); $f = FFI::new("double[$maxP]"); // 出参: 动能/重力/摩擦 数组
        $cnt = self::ffi()->pivots_calc($arr, $n, $bar, $idx, $type, $ke, $g, $f, $maxP); // 调 DLL, 返回实际转折点个数
        $out = [];                                   // PHP 输出
        for ($k = 0; $k < $cnt; $k++) {              // 遍历每个转折点
            $out[] = ['idx' => $idx[$k], 'type' => $type[$k], 'ke' => $ke[$k], 'g' => $g[$k], 'f' => $f[$k]]; // 组装关联数组: 索引/类型/动能/重力/摩擦
        }
        return $out;                                 // 返回转折点列表
    }

    // ============ 常驻内存库(引擎专用) ============

    /** 喂增量K线: key="INST|bar", rows=[[ts_ms,o,h,l,c,vol]...] → 滞留根数 */
    public static function feed(string $inst, string $bar, array $rows): int { // 向内存库喂增量K线
        $n = count($rows);                           // 本次喂入根数
        if (!$n) return self::ffi()->store_count($inst . '|' . $bar); // 空喂入: 仅返回当前滞留条数
        $ts = FFI::new("long long[$n]");             // 分配时间戳数组(毫秒, 64位整型)
        $bars = FFI::new("double[" . ($n * 5) . "]"); // 分配K线体数组(每根 o,h,l,c,v 五个 double)
        $i = 0;                                      // 行游标
        foreach ($rows as $r) {                      // 逐根摊平写入
            $ts[$i] = (int)$r[0];                    // 时间戳
            $bars[$i * 5]     = (float)$r[1];        // 开盘价 o
            $bars[$i * 5 + 1] = (float)$r[2];        // 最高价 h
            $bars[$i * 5 + 2] = (float)$r[3];        // 最低价 l
            $bars[$i * 5 + 3] = (float)$r[4];        // 收盘价 c
            $bars[$i * 5 + 4] = (float)($r[5] ?? 0); // 成交量 v(缺省补0)
            $i++;                                    // 前进游标
        }
        return self::ffi()->store_upsert($inst . '|' . $bar, $ts, $bars, $n); // 调 DLL 按 key 插入/更新, 返回滞留根数
    }

    /** 内存库金▲信号 → [fired, ke, th, dist, info](数据版本缓存, 重复调用零开销) */
    public static function storeGold(string $inst, string $bar): array { // 内存库直算金▲信号
        $ke = FFI::new('double'); $th = FFI::new('double'); $dist = FFI::new('int'); // 出参: 动能/阈值/距离
        $info = FFI::new('char[256]');               // 信息串缓冲区
        $r = self::ffi()->store_gold($inst . '|' . $bar, $bar, FFI::addr($ke), FFI::addr($th), FFI::addr($dist), $info, 256); // 调 DLL(数据已在DLL内存, 无需传数组)
        return [$r === 1, (float)$ke->cdata, (float)$th->cdata, (int)$dist->cdata, FFI::string($info)]; // 返回 [是否触发, 动能, 阈值, 距离, 信息串]
    }

    /** 内存库黄金坑信号(六维: 金▲动能+缠论分型+CCI+ADX+MACD+KDJ) → [fired, score, info] */
    public static function storePit(string $inst, string $bar): array { // 内存库直算黄金坑信号
        $sc = FFI::new('double');                    // 出参: 六维综合得分
        $info = FFI::new('char[256]');               // 信息串缓冲区
        $r = self::ffi()->store_pit($inst . '|' . $bar, $bar, FFI::addr($sc), $info, 256); // 调 DLL 计算
        return [$r === 1, (float)$sc->cdata, FFI::string($info)]; // 返回 [是否触发, 得分, 信息串]
    }

    public static function storeCount(string $inst, string $bar): int { // 查询内存库某 key 的K线条数
        return self::ffi()->store_count($inst . '|' . $bar);      // 转发 DLL 调用
    }

    public static function storeStats(): int { return self::ffi()->store_stats(); } // 查询内存库全局统计
}
