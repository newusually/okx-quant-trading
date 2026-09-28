<?php
/**
 * inc/lib/SigCore.php — C++ DLL 计算库包装 (PHP FFI → sigcore.dll)
 * 两种用法:
 *   ① 常驻内存库(引擎用): feed() 喂增量K线 → 数据滞留 C++ 进程内存 → storeGold() 直算
 *   ② 无状态计算(网页用): goldSignal() / macd() 每次传数组
 * 信号口径与网页 ov_pivot.js/ov_physics.js 及 Go goldsig.go 逐字一致
 */
class SigCore {
    private static ?FFI $ffi = null;

    private static function ffi(): FFI {
        if (self::$ffi === null) {
            if (!is_file(DLL_PATH)) throw new RuntimeException('sigcore.dll 不存在: ' . DLL_PATH);
            self::$ffi = FFI::cdef("
                int dll_version();
                int gold_signal(const double* rows, int n, const char* bar, double* ke, double* th, int* dist, char* info, int infoLen);
                int macd_calc(const double* close, int n, int fast, int slow, int sig, double* dif, double* dea, double* hist);
                int pivots_calc(const double* rows, int n, const char* bar, int* idx, int* type, double* ke, double* g, double* f, int maxP);
                int store_upsert(const char* key, const long long* ts, const double* bars, int n);
                int store_gold(const char* key, const char* bar, double* ke, double* th, int* dist, char* info, int len);
                int store_pit(const char* key, const char* bar, double* score, char* info, int len);
                int store_count(const char* key);
                int store_stats();
            ", DLL_PATH);
        }
        return self::$ffi;
    }

    public static function version(): int { return self::ffi()->dll_version(); }

    /** rows: [[o,h,l,c,v]...] 升序 → C 内存数组 */
    private static function toC(array $rows): array {
        $n = count($rows);
        $arr = FFI::new("double[" . max(1, $n * 5) . "]");
        $i = 0;
        foreach ($rows as $r) {
            $arr[$i++] = (float)$r[0]; $arr[$i++] = (float)$r[1]; $arr[$i++] = (float)$r[2];
            $arr[$i++] = (float)$r[3]; $arr[$i++] = (float)($r[4] ?? 0);
        }
        return [$arr, $n];
    }

    /** 无状态金▲信号: rows=[[o,h,l,c,v]...] → [fired, ke, th, dist, info] */
    public static function goldSignal(array $rows, string $bar): array {
        [$arr, $n] = self::toC($rows);
        $ke = FFI::new('double'); $th = FFI::new('double'); $dist = FFI::new('int');
        $info = FFI::new('char[256]');
        $r = self::ffi()->gold_signal($arr, $n, $bar, FFI::addr($ke), FFI::addr($th), FFI::addr($dist), $info, 256);
        return [$r === 1, (float)$ke->cdata, (float)$th->cdata, (int)$dist->cdata, FFI::string($info)];
    }

    /** MACD: closes[] → [dif[], dea[], hist[]] */
    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $sig = 9): array {
        $n = count($closes);
        if (!$n) return [[], [], []];
        $c = FFI::new("double[$n]"); $dif = FFI::new("double[$n]");
        $dea = FFI::new("double[$n]"); $hist = FFI::new("double[$n]");
        $i = 0; foreach ($closes as $v) $c[$i++] = (float)$v;
        self::ffi()->macd_calc($c, $n, $fast, $slow, $sig, $dif, $dea, $hist);
        $od = []; $oa = []; $oh = [];
        for ($k = 0; $k < $n; $k++) { $od[] = $dif[$k]; $oa[] = $dea[$k]; $oh[] = $hist[$k]; }
        return [$od, $oa, $oh];
    }

    /** 全部转折点+力学标注: rows=[[o,h,l,c,v]...] → [[t(占位0),type,ke,g,f]...] */
    public static function pivotsCalc(array $rows, string $bar): array {
        [$arr, $n] = self::toC($rows);
        $maxP = min(64, max(4, $n));
        $idx = FFI::new("int[$maxP]"); $type = FFI::new("int[$maxP]");
        $ke = FFI::new("double[$maxP]"); $g = FFI::new("double[$maxP]"); $f = FFI::new("double[$maxP]");
        $cnt = self::ffi()->pivots_calc($arr, $n, $bar, $idx, $type, $ke, $g, $f, $maxP);
        $out = [];
        for ($k = 0; $k < $cnt; $k++) {
            $out[] = ['idx' => $idx[$k], 'type' => $type[$k], 'ke' => $ke[$k], 'g' => $g[$k], 'f' => $f[$k]];
        }
        return $out;
    }

    // ============ 常驻内存库(引擎专用) ============

    /** 喂增量K线: key="INST|bar", rows=[[ts_ms,o,h,l,c,vol]...] → 滞留根数 */
    public static function feed(string $inst, string $bar, array $rows): int {
        $n = count($rows);
        if (!$n) return self::ffi()->store_count($inst . '|' . $bar);
        $ts = FFI::new("long long[$n]");
        $bars = FFI::new("double[" . ($n * 5) . "]");
        $i = 0;
        foreach ($rows as $r) {
            $ts[$i] = (int)$r[0];
            $bars[$i * 5]     = (float)$r[1];
            $bars[$i * 5 + 1] = (float)$r[2];
            $bars[$i * 5 + 2] = (float)$r[3];
            $bars[$i * 5 + 3] = (float)$r[4];
            $bars[$i * 5 + 4] = (float)($r[5] ?? 0);
            $i++;
        }
        return self::ffi()->store_upsert($inst . '|' . $bar, $ts, $bars, $n);
    }

    /** 内存库金▲信号 → [fired, ke, th, dist, info](数据版本缓存, 重复调用零开销) */
    public static function storeGold(string $inst, string $bar): array {
        $ke = FFI::new('double'); $th = FFI::new('double'); $dist = FFI::new('int');
        $info = FFI::new('char[256]');
        $r = self::ffi()->store_gold($inst . '|' . $bar, $bar, FFI::addr($ke), FFI::addr($th), FFI::addr($dist), $info, 256);
        return [$r === 1, (float)$ke->cdata, (float)$th->cdata, (int)$dist->cdata, FFI::string($info)];
    }

    /** 内存库黄金坑信号(六维: 金▲动能+缠论分型+CCI+ADX+MACD+KDJ) → [fired, score, info] */
    public static function storePit(string $inst, string $bar): array {
        $sc = FFI::new('double');
        $info = FFI::new('char[256]');
        $r = self::ffi()->store_pit($inst . '|' . $bar, $bar, FFI::addr($sc), $info, 256);
        return [$r === 1, (float)$sc->cdata, FFI::string($info)];
    }

    public static function storeCount(string $inst, string $bar): int {
        return self::ffi()->store_count($inst . '|' . $bar);
    }

    public static function storeStats(): int { return self::ffi()->store_stats(); }
}
