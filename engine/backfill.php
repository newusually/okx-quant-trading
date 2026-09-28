<?php
/**
 * engine/backfill.php — 数据回填守护 (php-win 隐藏运行, 替代 Go cmd_gapfill + backfill)
 * 职责:
 *   ① 每分钟: OKX 成交明细(fills-history) + 历史仓位(position-history) 回填 trade_flow
 *      —— 盈亏唯一真源(remark='OKX pos-history%'), 时间截断: 只收 CUTOFF 之后的数据(老数据全部不要)
 *   ② 持续: 全量合约(476) × 周期(1m/3m/5m/15m/1h/4h) K线正向补洞(自动建表/追最新/扫描缺口)
 * 限频: 请求间 120ms ≈ 8 req/s
 */
require_once dirname(__DIR__) . '\\web\\inc\\bootstrap.php';

set_time_limit(0);
ini_set('memory_limit', '512M');
/* 单核机器: 回填降为低优先级, 让Apache网页响应永远优先 (2026-09-28) */
pclose(popen('powershell -NoProfile -Command "Get-Process -Id ' . getmypid() . ' | ForEach-Object { $_.PriorityClass = \'BelowNormal\' }"', 'r'));
// 时间截断(与 Go build 0927A 一致): 2026-09-27 22:40 之前的OKX历史一律不收
define('CUTOFF_MS', (strtotime('2026-09-27 22:40:00')) * 1000);
define('FILLS_BACKFILL_MIN', 180); // 每轮回看180分钟成交

const TFS = ['1m', '3m', '5m', '15m', '1h', '4h'];

file_log('phpfill.txt', '===== PHP 回填守护启动 (cutoff=' . date('Y-m-d H:i:s', CUTOFF_MS / 1000) . ') =====');

    /** 拉OKX成交明细回填(带截断+去重, 最多5页防游标异常) */
    function backfillFills(): int {
        $begin = (time() - FILLS_BACKFILL_MIN * 60) * 1000;
        $after = null;
        $n = 0;
        try {
            for ($page = 0; $page < 5; $page++) {
            $path = '/api/v5/trade/fills-history?instType=SWAP&begin=' . $begin . '&limit=100' . ($after ? '&after=' . $after : '');
            $j = OkxClient::private('GET', $path);
            if (($j['code'] ?? '') !== '0' || empty($j['data'])) break;
            $oldest = PHP_INT_MAX;
            foreach ($j['data'] as $f) {
                $ts = (int)$f['ts'];
                if ($ts < $oldest) $oldest = $ts;
                if ($ts < CUTOFF_MS) continue; // 老数据一律不收
                $t = date('Y-m-d H:i:s', (int)($ts / 1000));
                $action = ($f['side'] ?? '') === 'buy' ? 'buy' : 'close';
                $remark = 'OKX fills backfill';
                if ($action === 'close') {
                    $remark = 'OKX pos-history (fills ' . ($f['subType'] ?? '') . ')';
                }
                $dedup = Db::scalar("SELECT COUNT(*) FROM trade_flow WHERE inst_id=? AND trade_time=? AND action=? AND price=? AND sz=?",
                    [$f['instId'], $t, $action, (float)$f['fillPx'], (float)$f['fillSz']]);
                if ($dedup > 0) continue;
                Db::ex("INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,sz,notional_usd,profit,fee,ord_id,remark)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                    [$t, $f['instId'], $action, ($f['posSide'] ?? 'long'),
                     (float)$f['fillPx'], (float)$f['fillSz'], (float)($f['fillNotional'] ?? ($f['fillPx'] * $f['fillSz'])),
                     $action === 'close' ? (float)($f['fillPnl'] ?? 0) : null,
                     abs((float)($f['fee'] ?? 0)), (string)($f['ordId'] ?? ''), $remark]);
                $n++;
            }
            if ($oldest < CUTOFF_MS) break; // 整页已老于截断 → 完成
            $after = $j['data'][count($j['data']) - 1]['billId'] ?? null;
            if (!$after || count($j['data']) < 100) break;
            usleep(250000); // fills-history 限频 10次/2秒
        }
    } catch (Throwable $e) {
        file_log('phpfill.txt', 'fills回填异常: ' . $e->getMessage());
    }
    return $n;
}

    /** 历史仓位回填(单页100条/分钟, 有realizedPnl才入) */
    function backfillPosHistory(): int {
        $n = 0;
        try {
            $j = OkxClient::private('GET', '/api/v5/account/positions-history?instType=SWAP&limit=100');
            if (($j['code'] ?? '') === '0' && !empty($j['data'])) {
                foreach ($j['data'] as $f) {
                    $ts = (int)($f['uTime'] ?? 0);
                    if ($ts < CUTOFF_MS) continue;
                    if ((float)($f['realizedPnl'] ?? 0) == 0) continue;
                    $t = date('Y-m-d H:i:s', (int)($ts / 1000));
                    $dedup = Db::scalar("SELECT COUNT(*) FROM trade_flow WHERE inst_id=? AND action='close' AND trade_time BETWEEN ? AND ? AND remark LIKE 'OKX pos-history%'",
                        [$f['instId'], date('Y-m-d H:i:s', strtotime($t) - 60), $t]);
                    if ($dedup > 0) {
                        // 已有fills口径盈利行: 仅把仓位级资金费补到最近一行(费用不重复计)
                        Db::ex("UPDATE trade_flow SET funding=? WHERE id=(SELECT id FROM (
                                SELECT id FROM trade_flow WHERE inst_id=? AND action='close' AND trade_time BETWEEN ? AND ? AND remark LIKE 'OKX pos-history%'
                                ORDER BY id DESC LIMIT 1) x)",
                            [(float)($f['fundingFee'] ?? 0), $f['instId'], date('Y-m-d H:i:s', strtotime($t) - 60), $t]);
                        continue;
                    }
                    Db::ex("INSERT INTO trade_flow (trade_time,inst_id,action,pos_side,price,profit,fee,funding,remark)
                            VALUES (?,?,'close','long',?,?,?,?,?)",
                        [$t, $f['instId'], (float)($f['closeAvgPx'] ?? 0), (float)$f['realizedPnl'],
                         abs((float)($f['totalFee'] ?? 0)), (float)($f['fundingFee'] ?? 0),
                         'OKX pos-history type=' . ($f['type'] ?? '')]);
                    Db::ex("UPDATE position_detail SET status='CLOSED', close_time=?, profit=? WHERE inst_id=? AND status='OPEN'",
                        [$t, (float)$f['realizedPnl'], $f['instId']]);
                    $n++;
                }
            }
        } catch (Throwable $e) {
            file_log('phpfill.txt', 'pos-history回填异常: ' . $e->getMessage());
        }
        return $n;
    }

/** K线缺口扫描(单合约单周期: 找空洞逐段补) — 每合约每天跑一次 */
function holeScan(string $inst, string $tf): int {
    $t = Db::ktable($inst, $tf);
    try {
        $iv = (Klines::IVS[$tf] ?? 300) * 1000;
        $min = Db::scalar("SELECT MIN(candle_time) FROM {$t}");
        $max = Db::scalar("SELECT MAX(candle_time) FROM {$t}");
        if (!$min || !$max) return 0;
        // 采样找第一个洞: 行数 vs 时间槽
        $slots = (int)(($max - $min) / $iv) + 1;
        $cnt = (int)Db::scalar("SELECT COUNT(*) FROM {$t}");
        if ($cnt >= $slots) return 0; // 无洞
        // 有洞: 分页扫描找相邻时间对(内存安全, 每页2万行)
        $prev = null;
        $pageSize = 20000;
        $offset = 0;
        while (true) {
            $rows = Db::q("SELECT candle_time FROM {$t} ORDER BY candle_time ASC LIMIT {$pageSize} OFFSET {$offset}");
            if (!$rows) break;
            foreach ($rows as $r) {
                $ct = (int)$r['candle_time'];
                if ($prev !== null && $ct - $prev > $iv) {
                    // 洞 [prev+iv, ct-iv]: 逐段补(before=取比prev更新的, 300根/次)
                    $after = $prev;
                    while ($after < $ct - $iv) {
                        Klines::fetchStore($inst, $tf, 300, $after, true, 'before');
                        usleep(150000);
                        $newMax = Db::scalar("SELECT MAX(candle_time) FROM {$t} WHERE candle_time<{$ct}");
                        if ($newMax === null || (int)$newMax <= $after) break;
                        $after = (int)$newMax;
                    }
                }
                $prev = $ct;
            }
            $offset += $pageSize;
            if (count($rows) < $pageSize) break;
        }
        return 1;
    } catch (Throwable $e) {
        file_log('phpfill.txt', "holeScan $inst $tf: " . $e->getMessage());
        return 0;
    }
}
// ---------- 主循环 ----------
$fillLast = 0;          // 上次成交回填
$scanIdx = 0;           // 全量表轮扫游标
$holeDone = [];         // 洞扫描状态: 数据库 app_settings.fill_holes (2026-09-28 起所有json不保存)
try { $holeDone = json_decode(Settings::get('fill_holes'), true) ?: []; } catch (Throwable $e) {}

/** 洞扫描状态落库(替代json文件) */
function saveHoleState(array $h): void {
    Settings::set('fill_holes', json_encode(array_slice($h, -1000, null, true)));
}

while (true) {
    hb('backfill');          // 心跳(供 guard.exe 判活/判卡死)
    try {
        $now = time();

        // ① 每分钟: 成交+仓位历史回填
        if ($now - $fillLast >= 60) {
            $fillLast = $now;
            $nf = backfillFills();
            $np = backfillPosHistory();
            file_log('phpfill.txt', "成交回填 $nf 笔 / 仓位回填 $np 笔");
        }

        // ② K线正向追平已移交 C++ datahub.exe (finally_fill1m任务, 2026-09-28 08:45起)
        //    本守护只保留: 成交回填 + 仓位历史回填 + 洞扫描(补历史缺口)
        $insts = SymbolPool::allInsts();
        if ($insts) {
            // 洞扫描: 每轮只扫1个合约(状态落库), 全池约40分钟一轮
            $inst = $insts[$scanIdx % count($insts)];
            $scanIdx++;
            $k = $inst . '_' . date('Y-m-d');
            if (empty($holeDone[$k])) {
                foreach (TFS as $tf) holeScan($inst, $tf);
                $holeDone[$k] = 1;
                if (count($holeDone) > 2000) $holeDone = array_slice($holeDone, -1000, null, true);
                saveHoleState($holeDone);
            }
            file_log('phpfill.txt', '洞扫描游标=' . $scanIdx . '/' . count($insts) . '(K线追平已移交datahub)');
        }
    } catch (Throwable $e) {
        file_log('phpfill.txt', '主循环异常: ' . $e->getMessage() . ' @ ' . $e->getLine());
    }
    sleep(3);
}
