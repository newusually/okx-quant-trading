<?php
/**
 * inc/lib/OkxClient.php — OKX V5 REST 客户端 (PHP 版, 替代 Go okx 包)
 * 职责: 封装 OKX V5 公共/私有 REST 接口, 供 PHP 侧行情查询与账户交易
 * 签名: sign = base64(hmac_sha256(secret, ts+METHOD+path+body)), ts = ISO8601 UTC 毫秒
 * 凭证: 数据库表 okx_cred (2026-09-28 起所有json不保存, 全部入库)
 * tdMode=cross 全仓 / posSide=long 只做多(系统铁律)
 * 方法清单:
 *   cred()                — 从 DB 读 API 凭证(api_key/secret_key/passphrase, 带缓存)
 *   sign()                — 私有: HMAC-SHA256 请求签名
 *   private()/public()    — 私有(带签名)/公共(匿名) HTTP 请求
 *   tickers()/ticker()/lastPrice() — 行情: 全 SWAP ticker / 单合约 ticker / 最新价
 *   candles()             — K线拉取(支持 before/after 翻页与历史接口)
 *   ctVal()               — 每张合约面值(进程内缓存)
 *   positions()/position()— 全部/单合约持仓
 *   setLeverageAdaptive() — 自适应降档设杠杆(LUNA 等合约上限不足 20x)
 *   marketBuy()           — 市价买入开多
 *   closePositionMarket() — 市价全平多头
 *   cancelAllAlgos()      — 撤销合约全部未触发条件单
 *   fillsPnl()            — 近 N 分钟平仓成交的 PnL 合计与出场均价
 * 被谁调用: Klines(K线拉取)、SymbolPool(池刷新)及各交易相关接口页
 */
class OkxClient {                                    // OKX REST 客户端类(纯静态方法)
    const BASE = 'https://www.okx.com';              // OKX API 基地址常量
    private static ?array $cred = null;              // 凭证缓存(首次从 DB 读后进程内复用)
    private static array $ctValCache = [];   // instId → ctVal

    public static function cred(): array {           // 读取 API 凭证(懒加载+缓存)
        if (self::$cred === null) {                  // 尚未读取过凭证
            $r = Db::one("SELECT api_key, secret_key, passphrase FROM okx_cred WHERE id=1"); // 从 okx_cred 表取 id=1 那行凭证
            self::$cred = $r ? ['api_key' => (string)$r['api_key'],                       // 有记录: 组装字符串键值对
                                'secret_key' => (string)$r['secret_key'],
                                'passphrase' => (string)$r['passphrase']] : [];           // 无记录: 空数组
        }
        return self::$cred;                          // 返回(可能为空的)凭证数组
    }

    private static function sign(string $secret, string $ts, string $method, string $path, string $body): string { // 私有: 计算 OKX 请求签名
        return base64_encode(hash_hmac('sha256', $ts . $method . $path . $body, $secret, true)); // sign = base64(hmac_sha256(secret, 时间戳+方法+路径+请求体)), 原始二进制输出
    }

    /** 私有接口(带签名) */
    public static function private(string $method, string $path, array $body = []): array { // 私有接口统一入口
        $c = self::cred();                           // 取凭证
        $ts = gmdate('Y-m-d\TH:i:s.v\Z');            // UTC ISO8601 毫秒时间戳(OKX 签名要求格式)
        $jb = $body ? json_encode($body) : '';       // 请求体 JSON(空体传空串, 签名与发送需一致)
        $ch = curl_init(self::BASE . $path);         // 初始化 cURL 会话
        curl_setopt_array($ch, [                     // 批量设置 cURL 选项
            CURLOPT_CUSTOMREQUEST => $method,        // HTTP 方法(GET/POST 等)
            CURLOPT_RETURNTRANSFER => true,          // 响应体以字符串返回而非直接输出
            CURLOPT_TIMEOUT => 15,                   // 总超时 15 秒
            CURLOPT_HTTPHEADER => [                  // 请求头数组
                'Content-Type: application/json',    // 内容类型 JSON
                'OK-ACCESS-KEY: ' . ($c['api_key'] ?? ''),       // OKX API Key 头
                'OK-ACCESS-TIMESTAMP: ' . $ts,                   // OKX 时间戳头(与签名一致)
                'OK-ACCESS-SIGN: ' . self::sign($c['secret_key'] ?? '', $ts, $method, $path, $jb), // OKX 签名头
                'OK-ACCESS-PASSPHRASE: ' . ($c['passphrase'] ?? ''), // OKX 口令头
            ],
        ]);
        if ($jb) curl_setopt($ch, CURLOPT_POSTFIELDS, $jb); // 有请求体才设置 POST 数据
        $r = curl_exec($ch);                         // 执行请求
        $err = curl_error($ch);                      // 记录可能的错误文本
        curl_close($ch);                             // 关闭 cURL 会话
        if ($r === false) throw new RuntimeException('OKX网络错误: ' . $err); // 请求彻底失败: 抛网络异常
        return json_decode($r, true) ?: ['code' => '-1', 'msg' => 'bad json', 'data' => []]; // 解码 JSON; 解不出则返回伪失败结构
    }

    /** 公共接口 */
    public static function public(string $path): array { // 公共接口统一入口(无需签名)
        $ch = curl_init(self::BASE . $path);         // 初始化 cURL 会话
        curl_setopt_array($ch, [                     // 批量设置 cURL 选项
            CURLOPT_RETURNTRANSFER => true,          // 响应以字符串返回
            CURLOPT_TIMEOUT => 12,                   // 总超时 12 秒
            CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64)', 'accept: application/json'], // 伪装浏览器 UA + 接受 JSON
        ]);
        $r = curl_exec($ch);                         // 执行请求
        $err = curl_error($ch);                      // 记录错误文本
        curl_close($ch);                             // 关闭会话
        if ($r === false) throw new RuntimeException('OKX网络错误: ' . $err); // 请求失败: 抛异常
        return json_decode($r, true) ?: ['code' => '-1', 'data' => []]; // 解码; 失败返回伪结构
    }

    // ---------- 行情 ----------
    /** 全 SWAP tickers */
    public static function tickers(): array {        // 拉取全市场永续合约 ticker 列表
        $j = self::public('/api/v5/market/tickers?instType=SWAP'); // 调公共接口
        return $j['code'] === '0' ? ($j['data'] ?? []) : [];       // 成功(code=0)返回 data, 否则空数组
    }

    public static function ticker(string $inst): array { // 拉取单合约 ticker
        $j = self::public('/api/v5/market/ticker?instId=' . urlencode($inst)); // 调公共接口(instId URL 编码)
        return ($j['code'] === '0' && !empty($j['data'])) ? $j['data'][0] : []; // 成功且有数据返回首条, 否则空数组
    }

    public static function lastPrice(string $inst): float { // 取合约最新成交价
        $t = self::ticker($inst);                    // 先取单合约 ticker
        return (float)($t['last'] ?? 0);             // 取 last 字段转浮点(缺省 0)
    }

    /** K线: 返回升序 [[ts_ms,o,h,l,c,vol,confirm]...]; dir=after取refMs更旧 / before取refMs更新 */
    public static function candles(string $inst, string $bar, int $limit = 300, ?int $refMs = null, bool $history = false, string $dir = 'after'): array { // 拉取 K 线(核心翻页参数见注释)
        $ep = $history ? '/api/v5/market/history-candles' : '/api/v5/market/candles'; // 历史接口与近期接口二选一
        $url = $ep . '?instId=' . urlencode($inst) . '&bar=' . $bar . '&limit=' . min(300, $limit); // 拼 URL: 合约+周期+根数(上限300)
        if ($refMs !== null) $url .= '&' . $dir . '=' . $refMs; // 给定参考时间戳则追加 before/after 参数(方向别用反, 否则回填永远不动)
        $j = self::public($url);                     // 发起公共请求
        $out = [];                                   // 结果容器
        if ($j['code'] === '0' && !empty($j['data'])) { // 请求成功且有数据
            foreach ($j['data'] as $r) {          // OKX 新→旧
                $out[] = [(int)$r[0], (float)$r[1], (float)$r[2], (float)$r[3], (float)$r[4], (float)$r[5], (int)($r[8] ?? 1)]; // 每行裁剪为 [ts_ms,o,h,l,c,vol,confirm] 七元组
            }
            $out = array_reverse($out);           // → 旧→新
        }
        return $out;                                 // 返回升序 K 线数组
    }

    // ---------- 合约信息 ----------
    /** ctVal(每张合约面值), 带进程内缓存 */
    public static function ctVal(string $inst): float { // 查询每张合约面值(用于保证金计算)
        if (isset(self::$ctValCache[$inst])) return self::$ctValCache[$inst]; // 命中缓存直接返回
        $j = self::public('/api/v5/public/instruments?instType=SWAP&instId=' . urlencode($inst)); // 调合约信息公共接口
        $v = 0.0;                                    // 默认面值 0
        if ($j['code'] === '0' && !empty($j['data'])) $v = (float)($j['data'][0]['ctVal'] ?? 0); // 成功则取 ctVal 字段
        return self::$ctValCache[$inst] = $v;        // 写入缓存并返回
    }

    // ---------- 账户/交易 ----------
    /** 全部持仓(升序原样) */
    public static function positions(): array {      // 拉取全部永续持仓
        $j = self::private('GET', '/api/v5/account/positions?instType=SWAP'); // 私有接口 GET
        return $j['code'] === '0' ? ($j['data'] ?? []) : [];  // 成功返回 data, 否则空数组
    }

    /** 单合约持仓: null=无仓 */
    public static function position(string $inst): ?array { // 查询单合约持仓
        $j = self::private('GET', '/api/v5/account/positions?instId=' . urlencode($inst)); // 私有接口按 instId 查
        return ($j['code'] === '0' && !empty($j['data'])) ? $j['data'][0] : null; // 有仓返回持仓对象, 无仓返回 null
    }

    /** 自适应设杠杆: 从 want 开始逐级降档直到成功(LUNA等上限低于20x的合约) */
    public static function setLeverageAdaptive(string $inst, int $want = LOCK_LEVER): int { // 设置杠杆(失败自动降档重试)
        $lv = $want;                                 // 从期望杠杆(默认铁律 20x)开始
        while ($lv >= 1) {                           // 逐档尝试直到 1x
            $j = self::private('POST', '/api/v5/account/set-leverage',   // 调设杠杆私有接口(全仓模式)
                ['instId' => $inst, 'lever' => (string)$lv, 'mgnMode' => 'cross']);
            if ($j['code'] === '0') return $lv;      // 设置成功: 返回实际生效杠杆
            $msg = $j['data'][0]['sMsg'] ?? ($j['msg'] ?? ''); // 取 OKX 错误消息(优先 data 内的 sMsg)
            if (strpos($msg, '51169') !== false || strpos($msg, 'leverage') !== false || stripos($msg, '杠杆') !== false) { // 判定是"杠杆超上限"类错误(错误码51169/英文leverage/中文杠杆)
                $lv = $lv >= 10 ? $lv - 10 : ($lv >= 5 ? $lv - 3 : $lv - 1); // 杠杆类错误: 大幅降档(≥10减10, ≥5减3, 否则减1)
                continue;                            // 立即重试下一档
            }
            // 其他错误(如限频)也降档重试一次后放弃
            $lv = $lv >= 10 ? $lv - 10 : $lv - 1;    // 非杠杆错误: 同样降档(≥10减10, 否则减1)再试
        }
        return 1;                                    // 全部失败: 返回最低档 1x
    }

    /** 市价买入开多 sz 张 */
    public static function marketBuy(string $inst, int $sz): array { // 市价开多单
        return self::private('POST', '/api/v5/trade/order', [             // 调下单私有接口
            'instId' => $inst, 'tdMode' => 'cross', 'side' => 'buy',      // 合约 + 全仓 + 买入方向
            'posSide' => 'long', 'ordType' => 'market', 'sz' => (string)$sz]); // 只做多(铁律) + 市价单 + 张数
    }

    /** 市价平掉全部多头 */
    public static function closePositionMarket(string $inst): array { // 市价全平多头仓位
        return self::private('POST', '/api/v5/trade/close-position', [    // 调一键平仓私有接口
            'instId' => $inst, 'mgnMode' => 'cross', 'posSide' => 'long', 'cldOrdPx' => '']); // 全仓 + 多头方向 + 市价(空价即市价)
    }

    /** 撤销该合约全部未触发条件单(系统不挂止损止盈单, 开仓后清理旧单) */
    public static function cancelAllAlgos(string $inst): void { // 撤销全部未触发条件单
        $j = self::private('GET', '/api/v5/trade/orders-algo-pending?ordType=conditional&instId=' . urlencode($inst)); // 先查该合约全部挂着的条件单
        if ($j['code'] !== '0' || empty($j['data'])) return;   // 无单或查询失败: 直接返回
        $list = [];                                            // 待撤单列表
        foreach ($j['data'] as $a) $list[] = ['algoId' => $a['algoId'], 'instId' => $inst]; // 逐单收集 algoId+instId
        if ($list) self::private('POST', '/api/v5/trade/cancel-algos', $list); // 批量撤销(POST 列表)
    }

    /** 最近 minutes 分钟的平仓成交 → [pnl合计, 出场均价] */
    public static function fillsPnl(string $inst, int $minutes = 40): array { // 统计近期平仓盈亏
        $begin = (time() - $minutes * 60) * 1000;              // 起始毫秒时间戳(当前时间往前推 minutes 分钟)
        $j = self::private('GET', '/api/v5/trade/fills-history?instType=SWAP&instId=' . urlencode($inst) . '&begin=' . $begin . '&limit=100'); // 拉最近100条成交历史
        $pnl = 0.0; $pxv = 0.0; $szSum = 0.0;                  // PnL合计 / 价格×张数累计(算加权均价) / 张数合计
        if ($j['code'] === '0' && !empty($j['data'])) {        // 查询成功且有成交
            foreach ($j['data'] as $f) {                       // 遍历每笔成交
                if (($f['side'] ?? '') !== 'sell') continue;   // 只统计卖出(平多)方向
                $pnl += (float)($f['fillPnl'] ?? 0);           // 累加该笔已实现盈亏
                $sz = (float)($f['fillSz'] ?? 0);              // 该笔成交张数
                $pxv += $sz * (float)($f['fillPx'] ?? 0);      // 累加 价格×张数(加权均价分子)
                $szSum += $sz;                                 // 累加张数(加权均价分母)
            }
        }
        return [$pnl, $szSum > 0 ? $pxv / $szSum : 0.0];       // 返回 [PnL合计, 加权出场均价(无卖出则为0)]
    }
}
