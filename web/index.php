<?php
/**
 * index.php — NQ 纳指期货终端 (首页入口 · 0929 起取代旧 OKX 面板跳转)
 * 职责: 输出 NQ 纳指 1m/5m/15m K线终端页面 + NQ 模拟买卖 + AI 全市场模拟回测报告面板
 * 样式体系: 完全沿用 web/v2 数字货币页(tw.css/okx.js/lwc5.js/chart.js 同一套资产, 视觉 1:1)
 * 数据链路: kline_nq_* (nqhub.exe 实时/回填/聚合/指标) → nq_api.php 窗口化输出
 *           nq_sim_*  (nqhub.exe 模拟交易引擎)          → apihub /nqsim
 * 铁律: 本文件只做展示(纯 HTML+Vue 绑定), 所有计算在 C++ 组件; 模板绑定里禁止出现 ">" 裸比较
 */
header('Content-Type: text/html; charset=utf-8');   // 响应头: HTML + UTF-8
$V = '0929nq1';                                     // 资源缓存戳(改 JS/CSS 时必须 +1)
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NQ 纳指期货终端 · C++ 内核</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ctext y='26' font-size='26'%3E%F0%9F%93%88%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="/v2/assets/tw.css?v=<?php echo $V; ?>">
</head>
<body class="bg-ink-900 font-sans text-txt-1 antialiased">
<div id="app" v-cloak>

  <!-- ═══════════ 顶栏 ═══════════ -->
  <header class="sticky top-0 z-40 border-b border-ink-600 bg-ink-900/90 backdrop-blur-md">
    <div class="flex h-14 items-center gap-3 px-3">
      <div class="flex shrink-0 items-center gap-2">
        <div class="grid h-8 w-8 place-items-center rounded-lg bg-gradient-to-br from-gold/90 to-goldsoft/70 text-[15px] shadow-glow">📈</div>
        <div class="hidden leading-tight sm:block">
          <div class="text-[13px] font-bold tracking-wide">NQ 纳指期货终端</div>
          <div class="text-3xs text-txt-4">C++ 内核 · Dukascopy 实时 · 1m/5m/15m</div>
        </div>
      </div>

      <!-- 周期切换 -->
      <div class="flex items-center gap-1 rounded-lg border border-ink-600 bg-ink-800 p-0.5">
        <button v-for="t in tfs" :key="t" @click="pickTf(t)" class="chip" :class="tfOn(t)?'btn-on':''">{{ t }}</button>
      </div>

      <!-- 最新价 -->
      <div class="flex items-baseline gap-2 rounded-lg border border-ink-600 bg-ink-800 px-2.5 py-1">
        <span class="num text-[15px] font-bold" :class="sgnCls(sym.chg24)">{{ fmtPx(sym.px) }}</span>
        <span class="num text-2xs" :class="sgnCls(sym.chg24)">{{ pct(sym.chg24) }}%</span>
      </div>

      <span class="flex-1"></span>

      <span class="num text-2xs text-txt-3">{{ clock }}</span>
      <label class="chip" :class="auto?'btn-on':''"><input type="checkbox" class="mr-1 h-3 w-3 accent-[#f0b90b]" v-model="auto">自动</label>
      <button class="btn" @click="loadKline()">⟳ 刷新</button>
      <span class="chip" :class="guard.ok?'btn-on':''">🛡 {{ guard.name || 'guard' }}</span>
    </div>

    <!-- 指标条 -->
    <div class="flex flex-wrap items-center gap-x-5 gap-y-1 border-t border-ink-700 px-3 py-1.5 text-2xs">
      <span class="text-txt-4">最新价 <b class="num" :class="sgnCls(sym.chg24)">{{ fmtPx(sym.px) }}</b></span>
      <span class="text-txt-4">24h 涨跌 <b class="num" :class="sgnCls(sym.chg24)">{{ pct(sym.chg24) }}%</b></span>
      <span class="text-txt-4">24h 区间 <b class="num text-txt-2">{{ fmtPx(sym.lo24) }} ~ {{ fmtPx(sym.hi24) }}</b></span>
      <span class="text-txt-4">7日 涨跌 <b class="num" :class="sgnCls(sym.chg7)">{{ pct(sym.chg7) }}%</b></span>
      <span class="text-txt-4">持仓 <b class="num" :class="hasPos?'text-gold':'text-txt-3'">{{ num(pos.qty,6) }}</b></span>
      <span class="text-txt-4">浮盈 <b class="num" :class="profitCls(pos.upl)">{{ num(pos.upl,2) }}U</b></span>
      <span class="text-txt-4">已实现 <b class="num" :class="profitCls(stat.pnl)">{{ num(stat.pnl,2) }}U</b></span>
      <span class="text-txt-4">胜率 <b class="num text-info">{{ num(winRate,0) }}%</b></span>
      <span class="text-txt-4">平仓 <b class="num text-txt-2">{{ stat.ncl }} 笔</b></span>
      <span class="text-txt-4">K线 <b class="num text-txt-2">{{ rows.length }} 根</b></span>
    </div>
  </header>

  <main class="mx-auto flex max-w-[1780px] items-start gap-3 px-3 pb-10 pt-3">

    <!-- ═══════════ 左列 ═══════════ -->
    <div class="flex min-w-0 flex-1 flex-col gap-3">

      <!-- AI 全市场模拟回测报告面板(金框醒目) -->
      <div class="card" style="border:1px solid rgba(240,185,60,.45);box-shadow:0 0 14px rgba(240,185,60,.18)">
        <div class="flex items-center gap-2 px-3 py-2">
          <span style="font-size:13px;font-weight:700;color:#f0b93c">🧠 AI 全市场模拟回测报告</span>
          <span class="text-3xs text-txt-4">每小时一场 · 100 交易员 × 全市场合约 · 报告存数据库</span>
          <span class="flex-1"></span>
          <span class="text-3xs text-txt-4">{{ btList.length ? '已更新 ' + btList.length + ' 场' : '等待首轮报告…' }}</span>
          <span @click="btExpand=!btExpand" :title="btExpand?'收起为3行':'展开滚动查看全部场次'" style="cursor:pointer;font-size:14px;user-select:none;flex:none;margin-left:8px;padding:2px 6px;border-radius:6px" :style="btExpand?'color:#f0b93c;background:rgba(240,185,60,.15)':'color:#8b93a7'">⚙</span>
        </div>
        <div :style="btExpand?'max-height:520px;overflow-y:auto':'max-height:99px;overflow-y:auto'" style="border-top:1px solid rgba(240,185,60,.18)">
          <a v-for="b in btList" :key="b.id" :href="'/v2/bt_report.html?id=' + b.id" target="_blank" class="row mx-1 my-0.5 block" style="text-decoration:none">
            <span class="num shrink-0 text-3xs text-txt-4">#{{ b.id }}</span>
            <span class="num shrink-0 text-3xs text-info">{{ btTime(b.ts) }}</span>
            <span class="flex-1 truncate text-2xs">{{ b.brief }}</span>
          </a>
          <div v-if="!btList.length" class="px-3 py-2 text-2xs text-txt-4">暂无报告 — bt_timer.exe 每小时整点+5 分自动生成。</div>
        </div>
      </div>

      <!-- K线卡片 -->
      <div class="card overflow-hidden">
        <div class="card-h flex flex-wrap items-center gap-2 px-3 py-2">
          <span class="text-2xs font-bold">NQ 纳指100 期货 · {{ tf }}</span>
          <span class="num text-2xs" :class="sgnCls(sym.chg24)">{{ fmtPx(sym.px) }}</span>
          <span v-if="hover" class="num text-3xs text-txt-3">
            开 <b class="text-txt-1">{{ fmtPx(hover[1]) }}</b>
            高 <b class="text-up">{{ fmtPx(hover[2]) }}</b>
            低 <b class="text-dn">{{ fmtPx(hover[3]) }}</b>
            收 <b class="text-txt-1">{{ fmtPx(hover[4]) }}</b>
            量 <b class="text-txt-2">{{ num(hover[11],2) }}</b>
          </span>
          <span class="flex-1"></span>
          <span class="text-3xs text-txt-4">{{ badgeTxt() }} · {{ atLatestTxt }}</span>
          <button class="btn" @click="toLatest()" :class="atLatest?'btn-on':''">● 最新</button>
        </div>

        <!-- 工具栏 -->
        <div class="flex flex-wrap items-center gap-1.5 border-t border-ink-700 px-3 py-1.5">
          <select v-model="ctype" @change="setCtype()" class="rounded-md border border-ink-600 bg-ink-800 px-1.5 py-1 text-2xs outline-none">
            <option v-for="t in types" :key="t[0]" :value="t[0]">{{ t[1] }}</option>
          </select>
          <button class="btn" @click="cycleMa()">MA {{ maTxt() }}</button>
          <button class="btn" @click="toggleBoll()" :class="bollOn()?'btn-on':''">BOLL</button>
          <button v-for="p in panes" :key="p[0]" class="btn" @click="togglePane(p[0])" :class="cfgOnMap(p[0])?'btn-on':''">{{ p[1] }}</button>
          <span class="mx-1 h-4 w-px bg-ink-600"></span>
          <button v-for="t in tools" :key="t[0]" class="btn" @click="setTool(t[0])" :class="drawTool===t[0]?'btn-on':''" :title="t[1]">{{ t[2] }}</button>
          <span class="flex items-center gap-1">
            <span v-for="c in colors" :key="c" @click="setColor(c)" style="cursor:pointer;width:12px;height:12px;border-radius:3px" :style="colorBox(c)"></span>
          </span>
          <button class="btn" @click="undoDraw()">↶ 撤销</button>
          <button class="btn" @click="clearDraw()">清空</button>
          <button class="btn" @click="fitAll()">⤢ 适应</button>
          <button class="btn" @click="takeShot()">⤓ 截图</button>
          <span class="mx-1 h-4 w-px bg-ink-600"></span>
          <button class="btn" @click="toggleMarks()" :class="marksOn()?'btn-on':''">买卖标记</button>
          <button class="btn" @click="toggleAvg()" :class="avgOn()?'btn-on':''">均价线</button>
        </div>

        <!-- 图表 -->
        <div id="nqchart" style="height:640px"></div>

        <!-- 图例说明 -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-ink-700 px-3 py-1.5 text-3xs text-txt-4">
          <span><b class="text-up">🚀 买</b> 自动: 5m 三多头(EMA7 在 EMA25 在 EMA99 上方) + 1m 动能放大(振幅≥1.2×ATR14 或 量≥2×均量) · 2U</span>
          <span><b class="text-gold">▲ 加仓</b> 跌够 {{ addDipP }}% 且 1m 动能放大 · +1U(冷却 {{ coolP }} 分 · 最多 {{ maxAdds }} 次)</span>
          <span><b class="text-info">🍃 平</b> +{{ tpP }}% 止盈({{ levP }}x 杠杆) · 不止损</span>
          <span>数据源 Dukascopy jetta(USATECH.IDX-USD BID) · 引擎 nqhub.exe</span>
        </div>
      </div>

      <!-- 模拟交易卡片 -->
      <div class="card">
        <div class="card-h px-3 py-2">
          <span class="card-t">💹 NQ 模拟买卖 <span class="text-3xs text-txt-4">(C++ 引擎成交 · 15 秒内按最新 1m 收盘价)</span></span>
        </div>

        <div class="grid gap-3 px-3 py-2 sm:grid-cols-4">
          <div class="rounded-lg border border-ink-600 bg-ink-850 px-3 py-2">
            <div class="text-3xs text-txt-4">持仓点数</div>
            <div class="num text-[16px] font-bold" :class="hasPos?'text-gold':'text-txt-3'">{{ num(pos.qty,6) }}</div>
            <div class="text-3xs text-txt-4">已加仓 {{ pos.adds }} / {{ maxAdds }} 次</div>
          </div>
          <div class="rounded-lg border border-ink-600 bg-ink-850 px-3 py-2">
            <div class="text-3xs text-txt-4">持仓均价</div>
            <div class="num text-[16px] font-bold">{{ pos.avg ? fmtPx(pos.avg) : '—' }}</div>
            <div class="text-3xs text-txt-4">上次加仓 {{ pos.addpx ? fmtPx(pos.addpx) : '—' }}</div>
          </div>
          <div class="rounded-lg border border-ink-600 bg-ink-850 px-3 py-2">
            <div class="text-3xs text-txt-4">浮盈 ({{ pct(uplPct) }}%)</div>
            <div class="num text-[16px] font-bold" :class="profitCls(pos.upl)">{{ num(pos.upl,2) }} U</div>
            <div class="text-3xs text-txt-4">止盈价 {{ tpPx ? fmtPx(tpPx) : '—' }}</div>
          </div>
          <div class="rounded-lg border border-ink-600 bg-ink-850 px-3 py-2">
            <div class="text-3xs text-txt-4">已实现 / 胜率</div>
            <div class="num text-[16px] font-bold" :class="profitCls(stat.pnl)">{{ num(stat.pnl,2) }} U</div>
            <div class="text-3xs text-txt-4">{{ stat.ncl }} 笔 · 胜 {{ stat.nwin }} · {{ num(winRate,0) }}%</div>
          </div>
        </div>

        <!-- 止盈进度条 -->
        <div class="px-3 pb-2">
          <div class="mb-1 flex items-center justify-between text-3xs text-txt-4">
            <span>距止盈进度</span><span class="num">{{ num(tpProg,0) }}%</span>
          </div>
          <div style="height:6px;border-radius:9999px;background:#111927;overflow:hidden">
            <div :style="barStyle()"></div>
          </div>
        </div>

        <!-- 手动下单 -->
        <div class="flex flex-wrap items-center gap-2 border-t border-ink-700 px-3 py-2">
          <button class="btn" @click="order('buy')" :disabled="pending">{{ hasPos ? '🚀 加买 2U' : '🚀 买入 2U' }}</button>
          <button class="btn" @click="order('add')" :disabled="pending">▲ 加仓 1U</button>
          <button class="btn" @click="order('sell')" :disabled="pending">🍃 全部平仓</button>
          <span class="text-3xs text-txt-4">手动单进队列, 由 nqhub.exe 成交 · 自动策略同时运行</span>
          <span class="flex-1"></span>
          <span class="text-3xs" :class="orders.length?'text-gold':'text-txt-4'">挂单 {{ orders.length }}</span>
        </div>
        <div v-if="orders.length" class="flex flex-wrap gap-1 border-t border-ink-700 px-3 py-1.5">
          <span v-for="o in orders" :key="o.id" class="chip">#{{ o.id }} {{ o.act }} · {{ o.t }}</span>
        </div>

        <!-- 台账 -->
        <div class="border-t border-ink-700">
          <table class="w-full text-2xs">
            <thead>
              <tr class="text-3xs text-txt-4">
                <th class="px-3 py-1 text-left font-normal">时间</th>
                <th class="px-2 py-1 text-left font-normal">动作</th>
                <th class="px-2 py-1 text-right font-normal">点数</th>
                <th class="px-2 py-1 text-right font-normal">开价</th>
                <th class="px-2 py-1 text-right font-normal">平价</th>
                <th class="px-2 py-1 text-right font-normal">盈亏 U</th>
                <th class="px-2 py-1 text-left font-normal">原因</th>
                <th class="px-3 py-1 text-right font-normal">状态</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in trades" :key="t.id" class="border-t border-ink-750">
                <td class="num px-3 py-1 text-txt-3">{{ t.t }}</td>
                <td class="px-2 py-1" :class="kindCls(t.kind)">{{ kindName(t.kind) }}</td>
                <td class="num px-2 py-1 text-right">{{ num(t.qty,6) }}</td>
                <td class="num px-2 py-1 text-right">{{ fmtPx(t.opx) }}</td>
                <td class="num px-2 py-1 text-right">{{ t.cpx ? fmtPx(t.cpx) : '—' }}</td>
                <td class="num px-2 py-1 text-right" :class="profitCls(t.pf)">{{ t.pf ? num(t.pf,2) : '—' }}</td>
                <td class="px-2 py-1 text-txt-3">{{ t.rs }}</td>
                <td class="px-3 py-1 text-right text-3xs" :class="closedCls(t.st)">{{ t.st }}</td>
              </tr>
              <tr v-if="!trades.length"><td colspan="8" class="px-3 py-3 text-center text-3xs text-txt-4">暂无成交 — 引擎等待 5m 三多头 + 1m 放量信号, 或点上方按钮模拟买卖</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ═══════════ 右栏 ═══════════ -->
    <aside class="flex w-[300px] shrink-0 flex-col gap-3">
      <div class="card">
        <div class="card-h px-3 py-2"><span class="card-t">📐 模拟规则 (C++ 引擎常量)</span></div>
        <div class="flex flex-col gap-1 px-3 py-2 text-2xs">
          <div class="flex justify-between"><span class="text-txt-4">杠杆</span><b class="num">{{ levP }}x</b></div>
          <div class="flex justify-between"><span class="text-txt-4">首买 / 加仓</span><b class="num">2U / 1U</b></div>
          <div class="flex justify-between"><span class="text-txt-4">止盈</span><b class="num text-info">+{{ tpP }}%</b></div>
          <div class="flex justify-between"><span class="text-txt-4">加仓前提</span><b class="num text-gold">跌 {{ addDipP }}%</b></div>
          <div class="flex justify-between"><span class="text-txt-4">加仓冷却</span><b class="num">{{ coolP }} 分</b></div>
          <div class="flex justify-between"><span class="text-txt-4">最多加仓</span><b class="num">{{ maxAdds }} 次</b></div>
          <div class="flex justify-between"><span class="text-txt-4">止损</span><b class="num text-dn">不止损</b></div>
          <div class="border-t border-ink-700 pt-1 text-3xs text-txt-4">买入信号: 5m EMA7 在 EMA25 在 EMA99 上方 且 1m 阳线且动能放大(振幅≥1.2×ATR14 或 量≥2×均量) · 全 C++ 计算, 页面零计算</div>
        </div>
      </div>

      <div class="card">
        <div class="card-h px-3 py-2"><span class="card-t">📊 行情分析 (近 200 小时)</span></div>
        <div class="flex flex-col gap-1 px-3 py-2 text-2xs">
          <div class="flex justify-between"><span class="text-txt-4">最新价</span><b class="num">{{ fmtPx(sym.px) }}</b></div>
          <div class="flex justify-between"><span class="text-txt-4">24h 涨跌</span><b class="num" :class="sgnCls(sym.chg24)">{{ pct(sym.chg24) }}%</b></div>
          <div class="flex justify-between"><span class="text-txt-4">24h 振幅</span><b class="num">{{ num(amp24,2) }}%</b></div>
          <div class="flex justify-between"><span class="text-txt-4">7 日涨跌</span><b class="num" :class="sgnCls(sym.chg7)">{{ pct(sym.chg7) }}%</b></div>
          <div class="flex justify-between"><span class="text-txt-4">24h 高 / 低</span><b class="num">{{ fmtPx(sym.hi24) }} / {{ fmtPx(sym.lo24) }}</b></div>
          <div class="border-t border-ink-700 pt-1 text-3xs text-txt-4">1m 回填一年 + 实时 15 秒入库; 5m/15m/1h 与 MACD 由 C++ 本地聚合计算</div>
        </div>
      </div>

      <div class="card">
        <div class="card-h px-3 py-2"><span class="card-t">🛡 守护 / 组件</span></div>
        <div class="flex flex-col gap-1 px-3 py-2 text-2xs">
          <div class="flex justify-between"><span class="text-txt-4">guard 服务</span><b :class="guard.ok?'text-up':'text-dn'">{{ guard.ok ? '正常' : '异常' }}</b></div>
          <div class="flex justify-between"><span class="text-txt-4">nqhub.exe</span><b class="text-up">运行中</b></div>
          <div class="flex justify-between"><span class="text-txt-4">bt_timer.exe</span><b class="text-up">运行中</b></div>
          <div class="flex justify-between"><span class="text-txt-4">数据源</span><b class="text-txt-2">Dukascopy</b></div>
          <div class="border-t border-ink-700 pt-1 text-3xs text-txt-4">K线/聚合/指标/模拟成交全部由 C++ 组件计算写库, 前端只读展示</div>
        </div>
      </div>
    </aside>
  </main>

  <div class="border-t border-ink-700 px-3 py-3 text-center text-3xs text-txt-4">
    NQ 纳指期货终端 · 组件 nqhub.exe / apihub.exe / bt_timer.exe / guard.exe · 数据源 Dukascopy jetta · 资源 <?php echo $V; ?>
  </div>

  <!-- 下单提示 -->
  <div v-if="toast" class="animate-slidein" style="position:fixed;right:16px;bottom:16px;z-index:60;border:1px solid rgba(240,185,60,.45);background:#0e1420;border-radius:10px;padding:10px 14px;font-size:12px;color:#f0b93c;box-shadow:0 8px 24px -12px rgba(0,0,0,.7)">{{ toast }}</div>
</div>

<script src="/v2/assets/vue.global.prod.js?v=<?php echo $V; ?>"></script>
<script src="/v2/assets/lwc5.js?v=<?php echo $V; ?>"></script>
<script src="/v2/assets/okx.js?v=<?php echo $V; ?>"></script>
<script src="/v2/assets/chart.js?v=<?php echo $V; ?>"></script>
<script src="nq_app.js?v=<?php echo $V; ?>"></script>
</body>
</html>
