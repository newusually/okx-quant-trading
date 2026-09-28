<?php
/**
 * eth_fill_1d.php — 全市场 1D 收盘价补一年 (断点续传 + 失败日志)
 */
ini_set('memory_limit', '512M');
set_time_limit(0);
$START = (int)((time() - 365 * 86400) * 1000);
$LOG = fopen('E:/datas/log/fill1d.log', 'w');

function logp($s) { global $LOG; fwrite($LOG, date('H:i:s ') . $s . "\n"); fflush($LOG); echo $s . "\n"; flush(); }
function db() { $m = new mysqli('127.0.0.1', 'root', '', 'trading'); $m->set_charset('utf8mb4'); return $m; }
$DB = db();
function rows($sql) { global $DB; $r = $DB->query($sql); $out = []; while ($x = $r->fetch_row()) $out[] = $x; $r->free(); return $out; }

function okx($url) {
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
    for ($try = 0; $try < 3; $try++) {
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) { usleep(400000); continue; }
        $j = json_decode($raw, true);
        if ($j && ($j['code'] ?? '1') === '0') return $j['data'];
        logp("  ! api code=" . ($j['code'] ?? '?') . " " . substr($url, 0, 90));
        usleep(600000);
    }
    return [];
}

$tabs = rows("SELECT table_name FROM information_schema.tables WHERE table_schema='trading' AND table_name LIKE 'kline%_1h'");
$insts = [];
foreach ($tabs as $t) if (preg_match('/^kline_(.+)_usdt_swap_1h$/', $t[0], $m)) $insts[] = strtoupper($m[1]) . '-USDT-SWAP';
logp("insts=" . count($insts));

$done = 0; $skip = 0; $fail = 0;
$i = 0;
foreach ($insts as $inst) {
    // 断点续传: 已有足够日线且够老则跳过
    $have = rows("SELECT COUNT(*), IFNULL(MIN(candle_time),0) FROM kline1d WHERE inst_id='" . $DB->real_escape_string($inst) . "'");
    if ((int)$have[0][0] >= 300 && (int)$have[0][1] <= $START + 3 * 86400000) { $skip++; continue; }
    $after = ''; $total = 0; $reqs = 0;
    while (true) {
        $url = "https://www.okx.com/api/v5/market/history-candles?instId=$inst&bar=1D&limit=100$after";
        $data = okx($url);
        $reqs++;
        if (!$data) { $fail++; break; }
        $oldest = PHP_INT_MAX;
        $vals = [];
        foreach ($data as $r) {
            $ts = (int)$r[0]; $oldest = min($oldest, $ts);
            if ($ts < $START) continue;
            $vals[] = "('" . $DB->real_escape_string($inst) . "',$ts," . (float)$r[4] . ")";
        }
        if ($vals) {
            $sql = "INSERT INTO kline1d (inst_id,candle_time,c) VALUES " . implode(',', $vals) . " ON DUPLICATE KEY UPDATE c=VALUES(c)";
            if (!$DB->query($sql)) logp("  ! SQL: " . $DB->error);
            else $total += count($vals);
        }
        if ($oldest <= $START || count($data) < 100 || $reqs > 15) break;
        $after = '&after=' . $oldest;
        usleep(300000);
    }
    $done++;
    if ($done % 25 == 0) logp("progress $done/" . count($insts) . " (skip=$skip fail=$fail) last=$inst ins=$total");
    usleep(200000);
}
logp("FILL1D OK done=$done skip=$skip fail=$fail");
