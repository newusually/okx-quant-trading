/* okx_panel.js — 原版OKX面板功能(交易流水/止盈加仓监测/策略体检/Excel筛选)
 * [2026-09-27 用户指令] AI策略/AI详情/AI日报/保底持仓 已整体删除; 策略=金▲动能全池版
 * 从 okx_panel_backup_20260927.php 提取; 仅K线图改为 nqall 同款新组件(okx_api.php数据)
 * 合约切换: switchInst() → window.OKX_INST + okxReload() (nqall_data.js钩子) */
function fmtNum(v){return v>=100?(+v).toFixed(1):(v>=1?(+v).toFixed(3):(+v).toFixed(6));}
function noYear(t){return (t||'').length>10?t.slice(5,19):t;}

// ---- 合约切换(联动K线图) ----
function switchInst(i){
  if(!i||!i.includes('-')||i===window.OKX_INST)return;
  window.OKX_INST=i;
  const sel=document.getElementById('symbol'); if(sel)sel.value=i;
  const n=document.getElementById('lvn'); if(n)n.textContent=i;
  document.getElementById('status').textContent='切换 '+i+' …';
  window.OKX_MARKS=null;             // 清空旧标注, 拉新合约
  loadMarks();
  if(window.okxReload)window.okxReload();
}

// ---- 顶栏实时统计: 总盈亏/未实现/资金费率/交易笔数 (3秒轮询) ----
async function loadLive(){
  try{
    const inst=encodeURIComponent(window.OKX_INST||'ETH-USDT-SWAP');
    const j=await(await fetch(window.AH?window.AH+'/livestats?inst='+inst:'api.php?action=livestats&inst='+inst)).json();
    if(!j.ok||!j.data)return;
    const d=j.data, el=document.getElementById('liveStats'); if(!el)return;
    const net=+d.net_pnl||0, upl=+d.upl||0, all=net+upl;
    const nc=v=>`<b class="${v>0?'up':(v<0?'down':'')}">${v>=0?'+':''}${v.toFixed(2)} U</b>`;
    const wr=d.closed?((d.wins/d.closed*100).toFixed(1)+'%'):'--';
    let fr='--';
    if(d.funding){const r=d.funding.rate*100;fr=`<b class="${r>=0?'up':'dn'}" style="color:${r>=0?'#e03131':'#2f9e44'}">${r.toFixed(4)}%</b>`;}
    const spt=d.snap_time?(' · 快照'+String(d.snap_time).slice(11,16)):'';
    el.innerHTML=
      `<span>总盈亏(含浮动) ${nc(all)}</span>`+
      `<span>已实现 ${nc(net)}</span>`+
      `<span>浮动 ${nc(upl)}</span>`+
      `<span>资金费率(${d.inst}) ${fr}</span>`+
      `<span>交易 <b>${d.trades}</b>笔</span>`+
      `<span>已平仓 <b>${d.closed}</b> · 胜率 <b>${wr}</b></span>`+
      `<span>持仓 <b class="${d.open_pos>0?'up':''}">${d.open_pos}</b>个 · 保证金 <b>${(+d.pos_margin||0).toFixed(2)}U</b></span>`+
      `<span class="muted">${spt} · 每3秒刷新</span>`;
  }catch(e){
    // 2026-09-28: 一直没数据时把错误显示出来, 不静默
    const el=document.getElementById('liveStats');
    if(el&&el.textContent.indexOf('总盈亏')<0)
      el.innerHTML='<span style="color:#e03131">⚠ 盈利监控接口异常: '+((e&&e.message)||e)+' — 请截图此提示</span>';
  }
}

// ---- 交易流水: 排序 + 同订单聚合 ----
let flowRows=[], sortK='trade_time', sortAsc=false;
async function loadTrades(){
  try{
    const j=await(await fetch(window.AH?window.AH+'/trades':'api.php?action=trades')).json();
    flowRows=(j.data||[]);
    renderFlow();
    flowChangedRefreshMarks();   // 新成交 → 立即刷新K线买卖标注
    const s=await(await fetch(window.AH?window.AH+'/stats':'api.php?action=stats')).json();
    if(s.data&&s.data.summary){
      const su=s.data.summary;
      const net=+su.net_pnl||0;
      const feeSum=+su.fee_sum||0, fundSum=+su.fund_sum||0;
      const wr=su.total?(su.wins/su.total*100).toFixed(1):'0.0';
      const snap=s.data.last_snap?(s.data.last_snap.snap_time||'').slice(11,16):'--:--';
      const posM=+(s.data.last_snap&&s.data.last_snap.pos_margin)||0;
      document.getElementById('slineTop').innerHTML=
        `总盈亏 <b class="${net>=0?'up':'down'}">${net>=0?'+':''}${net.toFixed(2)} USDT</b>`+
        ` &nbsp;|&nbsp; <b>仓位 <span class="${posM>0?'up':''}">${posM.toFixed(2)} USDT</span></b>`+
        ` &nbsp;|&nbsp; 手续费 ${feeSum.toFixed(2)} &nbsp;|&nbsp; 资金费 ${fundSum.toFixed(2)}`+
        ` &nbsp;|&nbsp; 交易 ${su.total}笔 &nbsp;|&nbsp; 胜率 ${wr}%`+
        ` &nbsp;|&nbsp; 引擎: 金▲动能全池版 | 买入=15m+5m金▲共振 | 20X·每笔1U | 价格+2%止盈(ROI40%) | 永不止损 | 加仓=跌时金▲·每轮+⅓U`;
    }
  }catch(e){
    // 2026-09-28: 流水/悬浮窗取数失败必须显示, 不允许静默"加载中"
    const rE=document.getElementById('recentRows');
    if(rE&&/加载中/.test(rE.textContent))rE.innerHTML='<tr><td colspan="5" style="color:#e03131">⚠ 流水接口异常: '+((e&&e.message)||e)+'</td></tr>';
    if(window.showJsErr)window.showJsErr('交易流水 /trades 失败: '+((e&&e.message)||e));
  }
}
function renderFlow(){
  let rows=flowRows.slice();
  const merged=[];const seen={};
  for(const t of rows){
    const k=t.ord_id&&t.action==='buy'?('b'+t.ord_id):('r'+merged.length+'_'+Math.random());
    if(t.ord_id&&t.action==='buy'&&seen[k]){
      const p=seen[k];p._n=(p._n||1)+1;
      p.sz=(+p.sz||0)+(+t.sz||0);
      p.price=t.price||p.price;
      continue;
    }
    if(t.ord_id&&t.action==='buy'){seen[k]=t;t._n=1;}
    merged.push(t);
  }
  rows=merged;
  rows.sort((a,b)=>{
    let x=a[sortK],y=b[sortK];
    if(sortK==='price'||sortK==='pnl'){x=+x||0;y=+y||0;return sortAsc?x-y:y-x;}
    return sortAsc?String(x).localeCompare(String(y)):String(y).localeCompare(String(x));
  });
  const actName={buy:'买入',add:'加仓',close:'平仓'};
  document.getElementById('trades').innerHTML=rows.slice(0,300).map(t=>{
    const openAct=(t.action==='buy'||t.action==='add');
    const pnl=+t.pnl;
    const fee=+t.fee||0, fund=+t.funding||0;
    const feeCell=`<td class="muted">${fee?fee.toFixed(4):'-'}</td><td class="muted">${fund?fund.toFixed(4):'-'}</td>`;
    const pnlCell=openAct?'<td class="muted">持仓中</td>':
      `<td class="${pnl>0?'up':(pnl<0?'down':'')}" style="font-weight:600">${isNaN(pnl)?'-':(pnl>0?'+':'')+pnl.toFixed(2)}</td>`;
    return `<tr class="clickable" data-inst="${t.inst_id||''}">
      <td>${noYear(t.trade_time)}</td><td style="color:#1c7ed6;text-decoration:underline">${t.inst_id||''}</td>
      <td>${actName[t.action]||t.action}${t._n>1?`<span class="muted">(${t._n}笔成交)</span>`:''}</td><td>${t.price||''}</td>${pnlCell}${feeCell}</tr>`;
  }).join('')||'<tr><td colspan="7">暂无匹配流水</td></tr>';
  document.querySelectorAll('#trades tr.clickable').forEach(tr=>{
    tr.onclick=()=>switchInst(tr.dataset.inst);
  });
  // ---- 悬浮窗: 全合约最近成交(所有交易的合约 买入/加仓/平仓+盈利 一屏看全) ----
  const rEl=document.getElementById('recentRows');
  if(rEl){
    rEl.innerHTML=rows.slice(0,12).map(t=>{
      const openAct=(t.action==='buy'||t.action==='add');
      const pnl=+t.pnl;
      const aCol=t.action==='buy'?'#e03131':t.action==='add'?'#b07708':'#2f9e44';
      const icon=t.action==='buy'?'🚀':t.action==='add'?'▲':'🍃';
      const pnlCell=openAct?'<td class="muted">--</td>':
        `<td class="${pnl>0?'up':(pnl<0?'down':'')}" style="font-weight:700">${isNaN(pnl)?'-':(pnl>0?'+':'')+pnl.toFixed(2)}</td>`;
      return `<tr class="clickable" data-inst2="${t.inst_id||''}">
        <td>${noYear(t.trade_time)}</td><td style="color:#1c7ed6;text-decoration:underline">${t.inst_id||''}</td>
        <td style="color:${aCol};font-weight:600">${icon}${actName[t.action]||t.action}</td><td>${t.price||''}</td>${pnlCell}</tr>`;
    }).join('')||'<tr><td colspan="5" class="muted">暂无成交</td></tr>';
    rEl.querySelectorAll('tr.clickable').forEach(tr=>{
      tr.onclick=()=>switchInst(tr.dataset.inst2);
    });
  }
}
document.querySelectorAll('#flowT thead th').forEach(th=>{
  th.onclick=()=>{const k=th.dataset.k;if(!k)return;
    if(sortK===k)sortAsc=!sortAsc;else{sortK=k;sortAsc=false;}renderFlow();};
});

// ---- [2026-09-27 用户指令] AI策略/AI详情/AI日报 已删除(原 AI 记录滚动/详情弹窗整体移除) ----

// ---- 加仓信号 实时监测 ----
let gridLogs=[];
async function loadGrid(){
  try{
    const j=await(await fetch(window.AH?window.AH+'/gridmon':'api.php?action=gridmon')).json();
    if(!j.ok)return;
    const d=j.data||{}, rows=d.rows||[];
    gridLogs=d.logs||[];
    let armed=0,wait=0,block=0,closed=0;
    document.getElementById('gridRows').innerHTML=rows.map(r=>{
      const st=r.state||'';
      if(st.includes('已加仓')||st.includes('已触发'))armed++;
      else if(st.includes('风控'))block++;
      else if(st.includes('平仓'))closed++;
      else wait++;
      const col=st.includes('已加仓')?'var(--red)':st.includes('风控')?'#e87c00':st.includes('平仓')?'#999':'var(--blue)';
      const note=(r.note||'').replace(/"/g,'');
      return `<tr title="${note}">
        <td class="sym">${r.inst_id}</td>
        <td><b style="color:#e03131">${+r.ladder_adds||0}</b> 次</td>
        <td>${fmtNum(+r.last_px)}</td>
        <td style="color:var(--red)">${fmtNum(+r.tp_px)} <b>+${(+r.tp_pct||0).toFixed(2)}%</b></td>
        <td style="color:${col};font-weight:600">${st}</td></tr>`;
    }).join('')||'<tr><td colspan="5" class="muted">当前无持仓在监测</td></tr>';
    document.querySelectorAll('#gridRows tr td.sym').forEach(td=>{
      td.style.cssText='color:#1c7ed6;text-decoration:underline;cursor:pointer';
      td.onclick=()=>switchInst((td.textContent||'').trim());
    });
    document.getElementById('gridTip').innerHTML=
      `<b>加仓策略</b>：跌的时候(现价&lt;均价)出现 5m 金▲信号 即加仓 不限轮数(永远可加) · <b>每轮固定+1U/3</b> · <b>加仓无仓位上限</b> | 亏损由交易所爆仓线兜底 | 永不止损 | 止盈不挂单：每10秒检测 价格+2%(ROI40%@20X) 达标立即全平`+
      ` &nbsp;|&nbsp; <span class="muted">更新 ${new Date().toLocaleTimeString()}</span>`;
    document.getElementById('gridEntry').innerHTML=
      `买入=15m+5m 金▲共振（底部动能前25%, 与K线金色箭头同口径）· 每笔保证金1U · 20X · 全池扫描只买 symbollist 权威池合约 &nbsp;|&nbsp; <span class="muted">更新 ${new Date().toLocaleTimeString()}</span>`;
    document.getElementById('gline').innerHTML=
      `监测 ${rows.length} 个持仓 &nbsp;|&nbsp; 等待触发 ${wait} &nbsp;|&nbsp; <b>已触发加仓 ${armed}</b> &nbsp;|&nbsp; 风控拦截 ${block} &nbsp;|&nbsp; 已平仓 ${closed}`+
      ` &nbsp;|&nbsp; <span class="muted">监测更新 ${new Date().toLocaleTimeString()}</span>`;
    applyDomFilters('gridT');
  }catch(e){
    // 2026-09-28: 接口异常必须显示, 禁止静默卡在"加载中"
    const gl=document.getElementById('gline');
    if(gl)gl.innerHTML='<b style="color:#e03131">⚠ 加仓监控接口异常: '+((e&&e.message)||e)+'</b>';
    const gr=document.getElementById('gridRows');
    if(gr&&/加载中/.test(gr.textContent))gr.innerHTML='<tr><td colspan="5" style="color:#e03131">接口数据解析失败, 请截图此提示</td></tr>';
    if(window.showJsErr)window.showJsErr('加仓监控 /gridmon 失败: '+((e&&e.message)||e));
  }
}
if(document.getElementById('gridLogBtn'))document.getElementById('gridLogBtn').onclick=()=>{
  const txt=gridLogs.map(r=>`${noYear(r.log_time)}  ${r.inst_id}  ${r.kind}  L${r.level}  触发价${fmtNum(+r.trigger_px)}  ${(+r.add_usd).toFixed(2)}$  ${r.state}  ${r.note||''}`).join('\n');
  document.getElementById('repTxt').textContent=txt||'暂无信号历史(还没有触发过加仓信号)';
  document.getElementById('maskTitle').textContent='加仓信号历史(跌时金▲ 触发)';
  document.getElementById('mask').style.display='flex';
};

// ---- 策略体检 ----
async function showBackcheck(){
  document.getElementById('maskTitle').textContent='全策略回测体检报告';
  document.getElementById('repTxt').textContent='加载中…';
  document.getElementById('mask').style.display='flex';
  try{
    const j=await(await fetch(window.AH?window.AH+'/backcheck':'api.php?action=backcheck')).json();
    document.getElementById('repTxt').textContent=j.data||'(空)';
  }catch(e){document.getElementById('repTxt').textContent='读取失败: '+e;}
}
if(document.getElementById('chkBtn2'))document.getElementById('chkBtn2').onclick=showBackcheck;   // 策略体检卡已删(用户指令), 判空防中断

// ================= Excel式列筛选 =================
const domFilters={};
function applyDomFilters(tid){
  const t=document.getElementById(tid); if(!t)return;
  t.querySelectorAll('tbody tr').forEach(tr=>{
    let ok=true;
    for(const k in domFilters){
      if(!k.startsWith(tid+'|'))continue;
      const allow=domFilters[k];
      if(allow&&allow.size){
        const td=tr.children[+k.split('|')[1]];
        if(!td||!allow.has(td.textContent.trim())){ok=false;break;}
      }
    }
    tr.style.display=ok?'':'none';
  });
}
function openFltMenu(tid,ci,btn){
  const t=document.getElementById(tid);
  const vals=new Set();
  t.querySelectorAll('tbody tr').forEach(tr=>{const td=tr.children[ci];if(td)vals.add(td.textContent.trim());});
  const key=tid+'|'+ci, cur=domFilters[key];
  const m=document.getElementById('fltMenu'); m.innerHTML='';
  const lab=document.createElement('label'); lab.className='fmA';
  const all=document.createElement('input'); all.type='checkbox'; all.checked=!cur||!cur.size;
  lab.appendChild(all); lab.appendChild(document.createTextNode(' (全选)')); m.appendChild(lab);
  const boxes=[];
  [...vals].sort().forEach(v=>{
    const l=document.createElement('label'); const c=document.createElement('input'); c.type='checkbox';
    c.checked=!cur||!cur.size||cur.has(v); boxes.push([c,v]);
    l.appendChild(c); l.appendChild(document.createTextNode(' '+(v||'(空)'))); m.appendChild(l);
  });
  const sync=()=>{
    if(all.checked||boxes.every(([c])=>c.checked)) delete domFilters[key];
    else { const s=new Set(); boxes.forEach(([c,v])=>{if(c.checked)s.add(v);}); domFilters[key]=s; }
    applyDomFilters(tid);
  };
  all.onchange=()=>{boxes.forEach(([c])=>c.checked=all.checked);sync();};
  boxes.forEach(([c])=>c.onchange=()=>{all.checked=boxes.every(([c2])=>c2.checked);sync();});
  m.style.display='block';
  const r=btn.getBoundingClientRect();
  m.style.left=Math.min(r.left,innerWidth-200)+'px';
  m.style.top=Math.min(r.bottom+2,innerHeight-300)+'px';
}
function buildFilters(tid){
  const t=document.getElementById(tid); if(!t||t.dataset.flt)return; t.dataset.flt=1;
  t.querySelectorAll('thead th').forEach((th,ci)=>{
    const b=document.createElement('span'); b.className='fbtn'; b.textContent='▼';
    b.title='Excel式筛选: 勾选要显示的值';
    b.onclick=e=>{e.stopPropagation();openFltMenu(tid,ci,b);};
    th.appendChild(b);
  });
}
buildFilters('gridT');buildFilters('flowT');
document.addEventListener('click',()=>{document.getElementById('fltMenu').style.display='none';});

// ---- [2026-09-27 用户指令] 保底持仓(ETH九7旧功能)已随旧策略删除 ----

// ---- K线买卖平仓标注(买入🚀/加仓金▲/平仓🍃带盈利) ----
async function loadMarks(){
  try{
    const inst=encodeURIComponent(window.OKX_INST||'ETH-USDT-SWAP');
    const j=await(await fetch((window.AH?window.AH+'/marks?days=7&inst=':'api.php?action=marks&days=7&inst=')+inst)).json();
    window.OKX_MARKS=(j.ok&&j.data)||[];
    if(window.okxRedraw)window.okxRedraw();      // 立即重画, 不等下一次交互
  }catch(e){window.OKX_MARKS=[];}
}

// ---- 流水行数变化 → 立即刷新标注(新买入/平仓第一时间上图) ----
let _flowCnt=0;
function flowChangedRefreshMarks(){
  if(flowRows.length!==_flowCnt){_flowCnt=flowRows.length;loadMarks();}
}

// ---- 合约下拉(原api.php?action=symbols) ----
fetch(window.AH?window.AH+'/symbols':'api.php?action=symbols').then(r=>r.json()).then(j=>{
  const sel=document.getElementById('symbol');
  sel.innerHTML=(j.data||['ETH-USDT-SWAP']).map(s=>`<option ${s===window.OKX_INST?'selected':''}>${s}</option>`).join('');
  sel.onchange=()=>switchInst(sel.value);
});
// ---- 最近交易合约快捷按钮(⚡排, 一键切换带标注的K线) ----
document.querySelectorAll('#recentInsts .tf[data-inst]').forEach(el=>{
  el.onclick=()=>switchInst(el.dataset.inst);
});
document.getElementById('lvn').textContent=window.OKX_INST;
// 顶部状态栏: 同步K线组件缓冲状态
setInterval(()=>{
  const s=document.getElementById('stat');
  document.getElementById('status').textContent='✓ 实时同步 '+new Date().toLocaleTimeString('zh-CN',{hour12:false})+(s?' · '+s.textContent:'');
},10000);

loadLive();loadTrades();loadGrid();loadMarks();
setInterval(loadLive,3000);
setInterval(loadGrid,3000);
setInterval(loadTrades,10000);
setInterval(loadMarks,15000);

// ---- 底部居中实时监测悬浮窗: 折叠/展开 + 标题栏拖动 ----
(function(){
  const mwin=document.getElementById('monWin'); if(!mwin)return;
  const gmb=document.getElementById('gminBtn');
  const head=mwin.querySelector('h3');
  if(gmb)gmb.onclick=(e)=>{
    e.stopPropagation();
    mwin.classList.toggle('min');
    gmb.textContent=mwin.classList.contains('min')?'□':'─';
  };
  // 首次拖动前把 translateX(-50%) 居中转成显式 left/top
  function toAbsolute(){
    const r=mwin.getBoundingClientRect();
    mwin.style.left=Math.max(8,(window.innerWidth-r.width)/2)+'px';
    mwin.style.top=Math.max(8,window.innerHeight-r.height-12)+'px';
    mwin.style.bottom='auto';mwin.style.right='auto';mwin.style.transform='none';
  }
  let drag=false,sx=0,sy=0,ox=0,oy=0;
  function startDrag(x,y){
    if(getComputedStyle(mwin).transform!=='none'&&mwin.style.left==='')toAbsolute();
    if(mwin.style.left==='')toAbsolute();
    drag=true;sx=x;sy=y;ox=mwin.offsetLeft;oy=mwin.offsetTop;
  }
  function moveDrag(x,y){
    if(!drag)return;
    mwin.style.left=Math.min(Math.max(8,ox+x-sx),window.innerWidth-100)+'px';
    mwin.style.top=Math.min(Math.max(8,oy+y-sy),window.innerHeight-36)+'px';
  }
  head.addEventListener('mousedown',e=>{
    if(e.target===gmb)return;
    e.preventDefault();startDrag(e.clientX,e.clientY);
  });
  window.addEventListener('mousemove',e=>moveDrag(e.clientX,e.clientY));
  window.addEventListener('mouseup',()=>drag=false);
  head.addEventListener('touchstart',e=>{
    if(e.target===gmb)return;
    const t=e.touches[0];startDrag(t.clientX,t.clientY);
  },{passive:true});
  head.addEventListener('touchmove',e=>{
    const t=e.touches[0];moveDrag(t.clientX,t.clientY);e.preventDefault();
  },{passive:false});
  head.addEventListener('touchend',()=>drag=false);
})();
