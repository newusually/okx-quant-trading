<?php
/**
 * engine/pool_sync.php — 权威合约池每日自动同步 (计划任务 finally_poolsync, 每天00:30)
 *
 * 用户铁律(2026-09-28):
 *   ① 只买加密币: 剔除股票/美股/ETF代币化永续 (OKX官方 instCategory=3 全部剔除)
 *   ② 价格区间: 最新价 0.001 < close < 100
 *   ③ 剔除公告宣布下线/作废的合约 (OKX帮助中心 delistings 公告, 地区封锁API改用网页抓取)
 *   ④ 不新增合约: 在现有池基础上过滤 (新合约仍需人工/原口径进池)
 *
 * 变更后自动重启 finally_phpengine (SymbolPool 30分钟进程内缓存立即失效)
 * 日志: E:/datas/log/poolsync.log
 */
require_once __DIR__ . '\\..\\web\\inc\\bootstrap.php';

function plog(string $msg): void {
    @file_put_contents('E:/datas/log/poolsync.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
    echo $msg, "\n";
}
function http_get(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return (string)$r;
}

set_time_limit(300);
$pool = array_column(Db::q("SELECT inst_id FROM symbol_pool"), 'inst_id');
plog('==== pool_sync 启动 | 当前池 ' . count($pool) . ' 个 ====');

// ---------- ① OKX官方分类 + 交易状态 ----------
$j = OkxClient::public('/api/v5/public/instruments?instType=SWAP');
if (($j['code'] ?? '') !== '0') { plog('ERROR instruments拉取失败 code=' . ($j['code'] ?? '?')); exit(1); }
$meta = [];
foreach ($j['data'] as $d) $meta[$d['instId']] = ['cat' => $d['instCategory'] ?? '1', 'state' => $d['state'] ?? 'live'];

// ---------- ② 最新价 ----------
$tk = OkxClient::public('/api/v5/market/tickers?instType=SWAP');
$px = [];
foreach (($tk['data'] ?? []) as $t) $px[$t['instId']] = (float)$t['last'];

// ---------- ③ 下架/作废公告解析 (帮助中心网页, 公告API地区封锁) ----------
$delisted = [];   // base => 来源slug
$hosts = ['https://www.okx.com/zh-hans', 'https://www.okx.ac/en-ar', 'https://www.okx.pro'];
$slugs = [];
foreach ($hosts as $host) {
    $html = @http_get($host . '/help/section/announcements-delistings');
    if (strlen($html) < 5000) continue;
    if (preg_match_all('#/help/((?:okx-)?[a-z0-9-]*(?:delist|下线)[a-z0-9-]*)#i', $html, $m)) {
        $slugs = array_unique($m[1]);
        if ($slugs) break;
    }
}
$slugs = array_slice(array_values($slugs), 0, 14);   // 最近14篇
plog('下架公告候选文章: ' . count($slugs) . ' 篇');
foreach ($slugs as $slug) {
    // 只看与"永续合约"下线相关的文章 (spot下架不影响SWAP池)
    if (!preg_match('/perpetual|永续/i', $slug)) continue;
    foreach ($hosts as $host) {
        $art = @http_get($host . '/help/' . $slug);
        if (strlen($art) < 3000) continue;
        $txt = html_entity_decode(strip_tags($art));
        if (preg_match_all('/\b([A-Z0-9]{2,15})USDT\b/', $txt, $mm)) {
            foreach (array_unique($mm[1]) as $b) {
                // 基础名必须是OKX真实存在的SWAP合约(防止USD/USDC等现货文本误提取)
                if (strlen($b) >= 2 && isset($meta[$b . '-USDT-SWAP'])) $delisted[$b] = $slug;
            }
        }
        break;
    }
}
plog('公告涉及下线合约基础名: ' . (implode(',', array_keys($delisted)) ?: '(无)'));

// ---------- ④ 过滤 ----------
$keep = []; $drop = [];
foreach ($pool as $inst) {
    $base = explode('-', $inst)[0];
    $cat = $meta[$inst]['cat'] ?? '9';
    $state = $meta[$inst]['state'] ?? 'offline';
    $p = $px[$inst] ?? 0;
    $why = '';
    if ($cat != '1')                    $why = "股票/ETF类(cat=$cat)";
    elseif ($state !== 'live')          $why = "非live状态($state)";
    elseif (!($p > 0.001 && $p < 100))  $why = "价格越界($p)";
    elseif (isset($delisted[$base]))    $why = '公告下线(' . $delisted[$base] . ')';
    if ($why) $drop[] = "$inst [$why]";
    else      $keep[] = $inst;
}

// ---------- ⑤ 写库(有变化时) + 重启引擎刷新缓存 ----------
if (count($keep) !== count($pool)) {
    $c = Db::conn();
    $c->begin_transaction();
    $c->query("DELETE FROM symbol_pool");
    $st = $c->prepare("INSERT INTO symbol_pool (inst_id, updated_at) VALUES (?, NOW())");
    foreach ($keep as $inst) { $st->bind_param('s', $inst); $st->execute(); }
    $st->close();
    $c->commit();
    plog('池已更新: ' . count($pool) . ' → ' . count($keep) . ' 剔除明细: ' . implode(' | ', $drop));
    // 引擎进程内缓存30分钟, 直接重启立即生效 (分步执行, /End与/Run不能同行&连接-会静默失败)
    @exec('schtasks /End /TN finally_phpengine');
    @exec('ping -n 3 127.0.0.1 > nul');   // sleep 2s (php exec下sleep不可用)
    @exec('schtasks /Run /TN finally_phpengine');
    plog('已重启 finally_phpengine (缓存刷新)');
} else {
    plog('无变化: ' . count($keep) . ' 个 (公告/分类/价格均达标)');
}
plog('==== pool_sync 完成 | 池 ' . count($keep) . " 个 ====\n");
