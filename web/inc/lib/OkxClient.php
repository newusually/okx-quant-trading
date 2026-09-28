<?php
/**
 * inc/lib/OkxClient.php — OKX V5 REST 客户端 (PHP 版, 替代 Go okx 包)
 * 签名: sign = base64(hmac_sha256(secret, ts+METHOD+path+body)), ts = ISO8601 UTC 毫秒
 * 凭证: 数据库表 okx_cred (2026-09-28 起所有json不保存, 全部入库)
 * tdMode=cross 全仓 / posSide=long 只做多(系统铁律)
 */
class OkxClient {
    const BASE = 'https://www.okx.com';
    private static ?array $cred = null;
    private static array $ctValCache = [];   // instId → ctVal

    public static function cred(): array {
        if (self::$cred === null) {
            $r = Db::one("SELECT api_key, secret_key, passphrase FROM okx_cred WHERE id=1");
            self::$cred = $r ? ['api_key' => (string)$r['api_key'],
                                'secret_key' => (string)$r['secret_key'],
                                'passphrase' => (string)$r['passphrase']] : [];
        }
        return self::$cred;
    }

    private static function sign(string $secret, string $ts, string $method, string $path, string $body): string {
        return base64_encode(hash_hmac('sha256', $ts . $method . $path . $body, $secret, true));
    }

    /** 私有接口(带签名) */
    public static function private(string $method, string $path, array $body = []): array {
        $c = self::cred();
        $ts = gmdate('Y-m-d\TH:i:s.v\Z');
        $jb = $body ? json_encode($body) : '';
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'OK-ACCESS-KEY: ' . ($c['api_key'] ?? ''),
                'OK-ACCESS-TIMESTAMP: ' . $ts,
                'OK-ACCESS-SIGN: ' . self::sign($c['secret_key'] ?? '', $ts, $method, $path, $jb),
                'OK-ACCESS-PASSPHRASE: ' . ($c['passphrase'] ?? ''),
            ],
        ]);
        if ($jb) curl_setopt($ch, CURLOPT_POSTFIELDS, $jb);
        $r = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($r === false) throw new RuntimeException('OKX网络错误: ' . $err);
        return json_decode($r, true) ?: ['code' => '-1', 'msg' => 'bad json', 'data' => []];
    }

    /** 公共接口 */
    public static function public(string $path): array {
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64)', 'accept: application/json'],
        ]);
        $r = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($r === false) throw new RuntimeException('OKX网络错误: ' . $err);
        return json_decode($r, true) ?: ['code' => '-1', 'data' => []];
    }

    // ---------- 行情 ----------
    /** 全 SWAP tickers */
    public static function tickers(): array {
        $j = self::public('/api/v5/market/tickers?instType=SWAP');
        return $j['code'] === '0' ? ($j['data'] ?? []) : [];
    }

    public static function ticker(string $inst): array {
        $j = self::public('/api/v5/market/ticker?instId=' . urlencode($inst));
        return ($j['code'] === '0' && !empty($j['data'])) ? $j['data'][0] : [];
    }

    public static function lastPrice(string $inst): float {
        $t = self::ticker($inst);
        return (float)($t['last'] ?? 0);
    }

    /** K线: 返回升序 [[ts_ms,o,h,l,c,vol,confirm]...]; dir=after取refMs更旧 / before取refMs更新 */
    public static function candles(string $inst, string $bar, int $limit = 300, ?int $refMs = null, bool $history = false, string $dir = 'after'): array {
        $ep = $history ? '/api/v5/market/history-candles' : '/api/v5/market/candles';
        $url = $ep . '?instId=' . urlencode($inst) . '&bar=' . $bar . '&limit=' . min(300, $limit);
        if ($refMs !== null) $url .= '&' . $dir . '=' . $refMs;
        $j = self::public($url);
        $out = [];
        if ($j['code'] === '0' && !empty($j['data'])) {
            foreach ($j['data'] as $r) {          // OKX 新→旧
                $out[] = [(int)$r[0], (float)$r[1], (float)$r[2], (float)$r[3], (float)$r[4], (float)$r[5], (int)($r[8] ?? 1)];
            }
            $out = array_reverse($out);           // → 旧→新
        }
        return $out;
    }

    // ---------- 合约信息 ----------
    /** ctVal(每张合约面值), 带进程内缓存 */
    public static function ctVal(string $inst): float {
        if (isset(self::$ctValCache[$inst])) return self::$ctValCache[$inst];
        $j = self::public('/api/v5/public/instruments?instType=SWAP&instId=' . urlencode($inst));
        $v = 0.0;
        if ($j['code'] === '0' && !empty($j['data'])) $v = (float)($j['data'][0]['ctVal'] ?? 0);
        return self::$ctValCache[$inst] = $v;
    }

    // ---------- 账户/交易 ----------
    /** 全部持仓(升序原样) */
    public static function positions(): array {
        $j = self::private('GET', '/api/v5/account/positions?instType=SWAP');
        return $j['code'] === '0' ? ($j['data'] ?? []) : [];
    }

    /** 单合约持仓: null=无仓 */
    public static function position(string $inst): ?array {
        $j = self::private('GET', '/api/v5/account/positions?instId=' . urlencode($inst));
        return ($j['code'] === '0' && !empty($j['data'])) ? $j['data'][0] : null;
    }

    /** 自适应设杠杆: 从 want 开始逐级降档直到成功(LUNA等上限低于20x的合约) */
    public static function setLeverageAdaptive(string $inst, int $want = LOCK_LEVER): int {
        $lv = $want;
        while ($lv >= 1) {
            $j = self::private('POST', '/api/v5/account/set-leverage',
                ['instId' => $inst, 'lever' => (string)$lv, 'mgnMode' => 'cross']);
            if ($j['code'] === '0') return $lv;
            $msg = $j['data'][0]['sMsg'] ?? ($j['msg'] ?? '');
            if (strpos($msg, '51169') !== false || strpos($msg, 'leverage') !== false || stripos($msg, '杠杆') !== false) {
                $lv = $lv >= 10 ? $lv - 10 : ($lv >= 5 ? $lv - 3 : $lv - 1);
                continue;
            }
            // 其他错误(如限频)也降档重试一次后放弃
            $lv = $lv >= 10 ? $lv - 10 : $lv - 1;
        }
        return 1;
    }

    /** 市价买入开多 sz 张 */
    public static function marketBuy(string $inst, int $sz): array {
        return self::private('POST', '/api/v5/trade/order', [
            'instId' => $inst, 'tdMode' => 'cross', 'side' => 'buy',
            'posSide' => 'long', 'ordType' => 'market', 'sz' => (string)$sz]);
    }

    /** 市价平掉全部多头 */
    public static function closePositionMarket(string $inst): array {
        return self::private('POST', '/api/v5/trade/close-position', [
            'instId' => $inst, 'mgnMode' => 'cross', 'posSide' => 'long', 'cldOrdPx' => '']);
    }

    /** 撤销该合约全部未触发条件单(系统不挂止损止盈单, 开仓后清理旧单) */
    public static function cancelAllAlgos(string $inst): void {
        $j = self::private('GET', '/api/v5/trade/orders-algo-pending?ordType=conditional&instId=' . urlencode($inst));
        if ($j['code'] !== '0' || empty($j['data'])) return;
        $list = [];
        foreach ($j['data'] as $a) $list[] = ['algoId' => $a['algoId'], 'instId' => $inst];
        if ($list) self::private('POST', '/api/v5/trade/cancel-algos', $list);
    }

    /** 最近 minutes 分钟的平仓成交 → [pnl合计, 出场均价] */
    public static function fillsPnl(string $inst, int $minutes = 40): array {
        $begin = (time() - $minutes * 60) * 1000;
        $j = self::private('GET', '/api/v5/trade/fills-history?instType=SWAP&instId=' . urlencode($inst) . '&begin=' . $begin . '&limit=100');
        $pnl = 0.0; $pxv = 0.0; $szSum = 0.0;
        if ($j['code'] === '0' && !empty($j['data'])) {
            foreach ($j['data'] as $f) {
                if (($f['side'] ?? '') !== 'sell') continue;
                $pnl += (float)($f['fillPnl'] ?? 0);
                $sz = (float)($f['fillSz'] ?? 0);
                $pxv += $sz * (float)($f['fillPx'] ?? 0);
                $szSum += $sz;
            }
        }
        return [$pnl, $szSum > 0 ? $pxv / $szSum : 0.0];
    }
}
