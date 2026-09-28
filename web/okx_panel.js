/* okx_panel.js — 原版OKX面板功能(交易流水/止盈加仓监测/策略体检/Excel筛选)
 * [2026-09-27 用户指令] AI策略/AI详情/AI日报/保底持仓 已整体删除; 策略=金▲动能全池版
 * 从 okx_panel_backup_20260927.php 提取; 仅K线图改为 nqall 同款新组件(okx_api.php数据)
 * 合约切换: switchInst() → window.OKX_INST + okxReload() (nqall_data.js钩子)
 *
 * 【文件职责】旧版 OKX 永续合约交易面板的全部页面逻辑(前端控制器):
 *   定时轮询 PHP 接口(api.php / window.AH)拿数据, 渲染 DOM, 联动 K 线标注。
 *
 * 【功能清单】
 *   1. fmtNum/noYear      : 数字/时间格式化小工具
 *   2. switchInst()       : 合约切换(联动K线图+清空旧标注)
 *   3. loadLive()         : 顶栏实时统计(总盈亏/资金费率/胜率, 3秒轮询)
 *   4. loadTrades()       : 交易流水拉取+排序+同订单聚合渲染
 *   5. renderFlow()       : 流水表格+底部悬浮窗最近成交渲染
 *   6. loadGrid()         : 加仓信号实时监测(持仓状态/触发次数/止盈价)
 *   7. showBackcheck()    : 策略体检报告弹窗
 *   8. applyDomFilters()等: Excel式列筛选(表头▼按钮勾选过滤)
 *   9. loadMarks()        : K线买卖平仓标注(🚀买入/▲加仓/🍃平仓带盈利)
 *  10. 悬浮窗拖动         : 底部监测窗折叠/展开+标题栏鼠标/触摸拖动
 */
function fmtNum(v){return v>=100?(+v).toFixed(1):(v>=1?(+v).toFixed(3):(+v).toFixed(6));}  // 数值格式化: ≥100保留1位小数, ≥1保留3位, 其余6位
function noYear(t){return (t||'').length>10?t.slice(5,19):t;}  // 时间字符串去掉年份前缀, 只显示"月-日 时:分:秒"

// ---- 合约切换(联动K线图) ----
function switchInst(i){  // 切换当前合约: i 形如 "BTC-USDT-SWAP"
  if(!i||!i.includes('-')||i===window.OKX_INST)return;  // 非法参数(空/不含'-'/与当前相同)直接忽略
  window.OKX_INST=i;                                    // 更新全局当前合约标识
  const sel=document.getElementById('symbol'); if(sel)sel.value=i;  // 同步顶部下拉框选中项
  const n=document.getElementById('lvn'); if(n)n.textContent=i;     // 同步K线图左上角合约名标签
  document.getElementById('status').textContent='切换 '+i+' …';      // 状态栏显示切换中提示
  window.OKX_MARKS=null;             // 清空旧标注, 拉新合约
  loadMarks();                       // 立即拉取新合约的买卖标注
  if(window.okxReload)window.okxReload();  // 通知K线组件(nqall_data.js钩子)重新加载该合约K线
}

// ---- 顶栏实时统计: 总盈亏/未实现/资金费率/交易笔数 (3秒轮询) ----
async function loadLive(){  // 拉取实时盈亏汇总并渲染到顶栏 #liveStats
  try{
    const inst=encodeURIComponent(window.OKX_INST||'ETH-USDT-SWAP');  // 当前合约URL编码, 默认ETH
    const j=await(await fetch(window.AH?window.AH+'/livestats?inst='+inst:'api.php?action=livestats&inst='+inst)).json();  // 经PHP转发到C++组件取实时统计
    if(!j.ok||!j.data)return;                            // 接口失败或无数据则静默返回
    const d=j.data, el=document.getElementById('liveStats'); if(!el)return;  // 取数据对象与顶栏容器
    const net=+d.net_pnl||0, upl=+d.upl||0, all=net+upl;  // 已实现盈亏/浮动盈亏/总盈亏(两者之和)
    const nc=v=>`<b class="${v>0?'up':(v<0?'down':'')}">${v>=0?'+':''}${v.toFixed(2)} U</b>`;  // 盈亏数字着色片段(正红负绿, 带+号)
    const wr=d.closed?((d.wins/d.closed*100).toFixed(1)+'%'):'--';  // 胜率=盈利笔数/已平仓笔数, 无平仓显示--
    let fr='--';                                         // 资金费率默认--
    if(d.funding){const r=d.funding.rate*100;fr=`<b class="${r>=0?'up':'dn'}" style="color:${r>=0?'#e03131':'#2f9e44'}">${r.toFixed(4)}%</b>`;}  // 资金费率转百分数并按正负着色
    const spt=d.snap_time?(' · 快照'+String(d.snap_time).slice(11,16)):'';  // 快照时间取"时:分"段(若接口返回快照时间)
    el.innerHTML=                                        // 拼接顶栏统计HTML(以下每行一个统计项)
      `<span>总盈亏(含浮动) ${nc(all)}</span>`+           // 总盈亏=已实现+浮动
      `<span>已实现 ${nc(net)}</span>`+                   // 已实现盈亏
      `<span>浮动 ${nc(upl)}</span>`+                     // 未实现浮动盈亏
      `<span>资金费率(${d.inst}) ${fr}</span>`+           // 当前合约资金费率
      `<span>交易 <b>${d.trades}</b>笔</span>`+           // 总交易笔数
      `<span>已平仓 <b>${d.closed}</b> · 胜率 <b>${wr}</b></span>`+  // 已平仓笔数与胜率
      `<span>持仓 <b class="${d.open_pos>0?'up':''}">${d.open_pos}</b>个 · 保证金 <b>${(+d.pos_margin||0).toFixed(2)}U</b></span>`+  // 当前持仓数与占用保证金
      `<span class="muted">${spt} · 每3秒刷新</span>`;    // 快照时间+刷新频率说明(灰色小字)
  }catch(e){
    // 2026-09-28: 一直没数据时把错误显示出来, 不静默
    const el=document.getElementById('liveStats');       // 出错时也定位顶栏容器
    if(el&&el.textContent.indexOf('总盈亏')<0)           // 若容器里还没有正常数据
      el.innerHTML='<span style="color:#e03131">⚠ 盈利监控接口异常: '+((e&&e.message)||e)+' — 请截图此提示</span>';  // 红字显示错误, 防静默失败
  }
}

// ---- 交易流水: 排序 + 同订单聚合 ----
let flowRows=[], sortK='trade_time', sortAsc=false;      // 流水原始行数组/当前排序字段/是否升序
async function loadTrades(){  // 拉取交易流水+统计摘要并渲染
  try{
    const j=await(await fetch(window.AH?window.AH+'/trades':'api.php?action=trades')).json();  // 拉全部交易流水
    flowRows=(j.data||[]);                               // 存入全局数组
    renderFlow();                                        // 立即渲染表格与悬浮窗
    flowChangedRefreshMarks();   // 新成交 → 立即刷新K线买卖标注
    const s=await(await fetch(window.AH?window.AH+'/stats':'api.php?action=stats')).json();  // 再拉账户统计摘要
    if(s.data&&s.data.summary){                          // 有摘要才更新顶栏汇总条
      const su=s.data.summary;                           // 摘要数据对象
      const net=+su.net_pnl||0;                          // 净盈亏
      const feeSum=+su.fee_sum||0, fundSum=+su.fund_sum||0;  // 手续费合计/资金费合计
      const wr=su.total?(su.wins/su.total*100).toFixed(1):'0.0';  // 胜率百分比
      const snap=s.data.last_snap?(s.data.last_snap.snap_time||'').slice(11,16):'--:--';  // 最近快照时间(时:分)
      const posM=+(s.data.last_snap&&s.data.last_snap.pos_margin)||0;  // 最近快照的持仓保证金
      document.getElementById('slineTop').innerHTML=     // 渲染流水卡顶部汇总条(各行均为一个统计片段)
        `总盈亏 <b class="${net>=0?'up':'down'}">${net>=0?'+':''}${net.toFixed(2)} USDT</b>`+  // 净盈亏着色
        ` &nbsp;|&nbsp; <b>仓位 <span class="${posM>0?'up':''}">${posM.toFixed(2)} USDT</span></b>`+  // 当前仓位保证金
        ` &nbsp;|&nbsp; 手续费 ${feeSum.toFixed(2)} &nbsp;|&nbsp; 资金费 ${fundSum.toFixed(2)}`+      // 手续费与资金费合计
        ` &nbsp;|&nbsp; 交易 ${su.total}笔 &nbsp;|&nbsp; 胜率 ${wr}%`+                                // 交易笔数与胜率
        ` &nbsp;|&nbsp; 引擎: 金▲动能全池版 | 买入=15m+5m金▲共振 | 20X·每笔1U | 价格+2%止盈(ROI40%) | 永不止损 | 加仓=跌时金▲·每轮+⅓U`;  // 当前策略参数说明
    }
  }catch(e){
    // 2026-09-28: 流水/悬浮窗取数失败必须显示, 不允许静默"加载中"
    const rE=document.getElementById('recentRows');      // 定位悬浮窗最近成交容器
    if(rE&&/加载中/.test(rE.textContent))rE.innerHTML='<tr><td colspan="5" style="color:#e03131">⚠ 流水接口异常: '+((e&&e.message)||e)+'</td></tr>';  // 仍在"加载中"则替换为红色错误提示
    if(window.showJsErr)window.showJsErr('交易流水 /trades 失败: '+((e&&e.message)||e));  // 同时弹到K线图上的JS错误框
  }
}
function renderFlow(){  // 渲染流水表格(排序+聚合)与悬浮窗最近成交
  let rows=flowRows.slice();                           // 复制一份避免改动原数组
  const merged=[];const seen={};                       // 聚合结果数组/已见订单号映射
  for(const t of rows){                                // 遍历每条流水
    const k=t.ord_id&&t.action==='buy'?('b'+t.ord_id):('r'+merged.length+'_'+Math.random());  // 买入按订单号聚合, 其余每条独立
    if(t.ord_id&&t.action==='buy'&&seen[k]){           // 同订单的买入成交已出现过 → 合并
      const p=seen[k];p._n=(p._n||1)+1;                // 成交笔数+1
      p.sz=(+p.sz||0)+(+t.sz||0);                      // 数量累加
      p.price=t.price||p.price;                        // 价格取最新一笔
      continue;                                        // 不单独成行
    }
    if(t.ord_id&&t.action==='buy'){seen[k]=t;t._n=1;}  // 首次出现的买入订单登记
    merged.push(t);                                    // 加入聚合结果
  }
  rows=merged;                                         // 用聚合后的数组继续
  rows.sort((a,b)=>{                                   // 按当前排序字段排序
    let x=a[sortK],y=b[sortK];                         // 取两行的排序键
    if(sortK==='price'||sortK==='pnl'){x=+x||0;y=+y||0;return sortAsc?x-y:y-x;}  // 价格/盈亏按数值比较
    return sortAsc?String(x).localeCompare(String(y)):String(y).localeCompare(String(x));  // 其余按字符串比较(默认降序)
  });
  const actName={buy:'买入',add:'加仓',close:'平仓'};   // 动作代码→中文映射
  document.getElementById('trades').innerHTML=rows.slice(0,300).map(t=>{  // 最多渲染300行流水
    const openAct=(t.action==='buy'||t.action==='add');  // 买入/加仓属于持仓中的动作
    const pnl=+t.pnl;                                  // 该笔平仓盈亏
    const fee=+t.fee||0, fund=+t.funding||0;           // 手续费/资金费
    const feeCell=`<td class="muted">${fee?fee.toFixed(4):'-'}</td><td class="muted">${fund?fund.toFixed(4):'-'}</td>`;  // 手续费/资金费两列(0显示-)
    const pnlCell=openAct?'<td class="muted">持仓中</td>':  // 未平仓动作显示"持仓中"
      `<td class="${pnl>0?'up':(pnl<0?'down':'')}" style="font-weight:600">${isNaN(pnl)?'-':(pnl>0?'+':'')+pnl.toFixed(2)}</td>`;  // 平仓盈亏着色显示
    return `<tr class="clickable" data-inst="${t.inst_id||''}">`+  // 行可点击, 携带合约ID
      `<td>${noYear(t.trade_time)}</td><td style="color:#1c7ed6;text-decoration:underline">${t.inst_id||''}</td>`+  // 时间列与合约列(蓝色下划线)
      `<td>${actName[t.action]||t.action}${t._n>1?`<span class="muted">(${t._n}笔成交)</span>`:''}</td><td>${t.price||''}</td>${pnlCell}${feeCell}</tr>`;  // 动作(含聚合笔数)/价格/盈亏/费用列
  }).join('')||'<tr><td colspan="7">暂无匹配流水</td></tr>';  // 无数据时显示占位行
  document.querySelectorAll('#trades tr.clickable').forEach(tr=>{  // 给每行绑定点击事件
    tr.onclick=()=>switchInst(tr.dataset.inst);          // 点击行 → 切换到该合约
  });
  // ---- 悬浮窗: 全合约最近成交(所有交易的合约 买入/加仓/平仓+盈利 一屏看全) ----
  const rEl=document.getElementById('recentRows');       // 悬浮窗最近成交表体
  if(rEl){                                               // 容器存在才渲染
    rEl.innerHTML=rows.slice(0,12).map(t=>{              // 最近12条成交
      const openAct=(t.action==='buy'||t.action==='add');  // 是否持仓中动作
      const pnl=+t.pnl;                                  // 平仓盈亏
      const aCol=t.action==='buy'?'#e03131':t.action==='add'?'#b07708':'#2f9e44';  // 动作颜色: 买红/加仓橙/平仓绿
      const icon=t.action==='buy'?'🚀':t.action==='add'?'▲':'🍃';  // 动作图标: 火箭/三角/叶子
      const pnlCell=openAct?'<td class="muted">--</td>':  // 持仓中无盈亏
        `<td class="${pnl>0?'up':(pnl<0?'down':'')}" style="font-weight:700">${isNaN(pnl)?'-':(pnl>0?'+':'')+pnl.toFixed(2)}</td>`;  // 盈亏粗体着色
      return `<tr class="clickable" data-inst2="${t.inst_id||''}">`+  // 行可点击携带合约ID
        `<td>${noYear(t.trade_time)}</td><td style="color:#1c7ed6;text-decoration:underline">${t.inst_id||''}</td>`+  // 时间/合约列
        `<td style="color:${aCol};font-weight:600">${icon}${actName[t.action]||t.action}</td><td>${t.price||''}</td>${pnlCell}</tr>`;  // 动作/价格/盈亏列
    }).join('')||'<tr><td colspan="5" class="muted">暂无成交</td></tr>';  // 无数据占位
    rEl.querySelectorAll('tr.clickable').forEach(tr=>{   // 绑定悬浮窗行点击
      tr.onclick=()=>switchInst(tr.dataset.inst2);       // 点击 → 切换合约
    });
  }
}
document.querySelectorAll('#flowT thead th').forEach(th=>{  // 流水表头每个单元格绑定排序点击
  th.onclick=()=>{const k=th.dataset.k;if(!k)return;   // 无data-k的列不排序
    if(sortK===k)sortAsc=!sortAsc;else{sortK=k;sortAsc=false;}renderFlow();};  // 同列点击反转方向, 新列默认降序
});

// ---- [2026-09-27 用户指令] AI策略/AI详情/AI日报 已删除(原 AI 记录滚动/详情弹窗整体移除) ----

// ---- 加仓信号 实时监测 ----
let gridLogs=[];                                         // 加仓信号历史日志缓存
async function loadGrid(){  // 拉取加仓监测数据并渲染监测表格/提示条/统计条
  try{
    const j=await(await fetch(window.AH?window.AH+'/gridmon':'api.php?action=gridmon')).json();  // 拉加仓监测数据
    if(!j.ok)return;                                     // 接口失败静默返回
    const d=j.data||{}, rows=d.rows||[];                 // 数据对象与持仓监测行
    gridLogs=d.logs||[];                                 // 缓存信号历史日志
    let armed=0,wait=0,block=0,closed=0;                 // 已触发/等待/风控拦截/已平仓计数
    document.getElementById('gridRows').innerHTML=rows.map(r=>{  // 逐行渲染监测表
      const st=r.state||'';                              // 该持仓当前状态文本
      if(st.includes('已加仓')||st.includes('已触发'))armed++;  // 统计已触发加仓
      else if(st.includes('风控'))block++;               // 统计风控拦截
      else if(st.includes('平仓'))closed++;              // 统计已平仓
      else wait++;                                       // 其余为等待触发
      const col=st.includes('已加仓')?'var(--red)':st.includes('风控')?'#e87c00':st.includes('平仓')?'#999':'var(--blue)';  // 按状态着色
      const note=(r.note||'').replace(/"/g,'');          // 备注去掉双引号防HTML属性注入
      return `<tr title="${note}">`+                     // 行悬浮显示备注
        `<td class="sym">${r.inst_id}</td>`+             // 合约列(可点击切换)
        `<td><b style="color:#e03131">${+r.ladder_adds||0}</b> 次</td>`+  // 已加仓次数(红字粗体)
        `<td>${fmtNum(+r.last_px)}</td>`+                // 现价
        `<td style="color:var(--red)">${fmtNum(+r.tp_px)} <b>+${(+r.tp_pct||0).toFixed(2)}%</b></td>`+  // 止盈价与止盈幅度
        `<td style="color:${col};font-weight:600">${st}</td></tr>`;  // 状态列着色
    }).join('')||'<tr><td colspan="5" class="muted">当前无持仓在监测</td></tr>';  // 无持仓占位行
    document.querySelectorAll('#gridRows tr td.sym').forEach(td=>{  // 合约单元格绑定点击
      td.style.cssText='color:#1c7ed6;text-decoration:underline;cursor:pointer';  // 样式改为可点击链接样式
      td.onclick=()=>switchInst((td.textContent||'').trim());  // 点击合约名 → 切换合约
    });
    document.getElementById('gridTip').innerHTML=        // 加仓策略说明条(各行均为文案片段)
      `<b>加仓策略</b>：跌的时候(现价&lt;均价)出现 5m 金▲信号 即加仓 不限轮数(永远可加) · <b>每轮固定+1U/3</b> · <b>加仓无仓位上限</b> | 亏损由交易所爆仓线兜底 | 永不止损 | 止盈不挂单：每10秒检测 价格+2%(ROI40%@20X) 达标立即全平`+
      ` &nbsp;|&nbsp; <span class="muted">更新 ${new Date().toLocaleTimeString()}</span>`;  // 附加更新时间
    document.getElementById('gridEntry').innerHTML=      // 买入入场策略说明条
      `买入=15m+5m 金▲共振（底部动能前25%, 与K线金色箭头同口径）· 每笔保证金1U · 20X · 全池扫描只买 symbollist 权威池合约 &nbsp;|&nbsp; <span class="muted">更新 ${new Date().toLocaleTimeString()}</span>`;  // 策略参数+更新时间
    document.getElementById('gline').innerHTML=          // 监测统计条(计数+更新时间)
      `监测 ${rows.length} 个持仓 &nbsp;|&nbsp; 等待触发 ${wait} &nbsp;|&nbsp; <b>已触发加仓 ${armed}</b> &nbsp;|&nbsp; 风控拦截 ${block} &nbsp;|&nbsp; 已平仓 ${closed}`+
      ` &nbsp;|&nbsp; <span class="muted">监测更新 ${new Date().toLocaleTimeString()}</span>`;
    applyDomFilters('gridT');                            // 渲染后重新套用列筛选条件
  }catch(e){
    // 2026-09-28: 接口异常必须显示, 禁止静默卡在"加载中"
    const gl=document.getElementById('gline');           // 统计条容器
    if(gl)gl.innerHTML='<b style="color:#e03131">⚠ 加仓监控接口异常: '+((e&&e.message)||e)+'</b>';  // 红字显示错误
    const gr=document.getElementById('gridRows');        // 监测表体容器
    if(gr&&/加载中/.test(gr.textContent))gr.innerHTML='<tr><td colspan="5" style="color:#e03131">接口数据解析失败, 请截图此提示</td></tr>';  // 仍在加载中则显示错误行
    if(window.showJsErr)window.showJsErr('加仓监控 /gridmon 失败: '+((e&&e.message)||e));  // 弹到K线图JS错误框
  }
}
if(document.getElementById('gridLogBtn'))document.getElementById('gridLogBtn').onclick=()=>{  // "信号历史"按钮点击(按钮存在才绑定)
  const txt=gridLogs.map(r=>`${noYear(r.log_time)}  ${r.inst_id}  ${r.kind}  L${r.level}  触发价${fmtNum(+r.trigger_px)}  ${(+r.add_usd).toFixed(2)}$  ${r.state}  ${r.note||''}`).join('\n');  // 拼接历史日志为多行文本
  document.getElementById('repTxt').textContent=txt||'暂无信号历史(还没有触发过加仓信号)';  // 弹窗正文填入日志(空则提示)
  document.getElementById('maskTitle').textContent='加仓信号历史(跌时金▲ 触发)';  // 弹窗标题
  document.getElementById('mask').style.display='flex';  // 显示遮罩弹窗
};

// ---- 策略体检 ----
async function showBackcheck(){  // 打开"全策略回测体检报告"弹窗并拉取报告
  document.getElementById('maskTitle').textContent='全策略回测体检报告';  // 设置弹窗标题
  document.getElementById('repTxt').textContent='加载中…';  // 正文先显示加载中
  document.getElementById('mask').style.display='flex';  // 显示遮罩弹窗
  try{
    const j=await(await fetch(window.AH?window.AH+'/backcheck':'api.php?action=backcheck')).json();  // 拉取体检报告文本
    document.getElementById('repTxt').textContent=j.data||'(空)';  // 正文显示报告(空则占位)
  }catch(e){document.getElementById('repTxt').textContent='读取失败: '+e;}  // 失败显示错误
}
if(document.getElementById('chkBtn2'))document.getElementById('chkBtn2').onclick=showBackcheck;   // 策略体检卡已删(用户指令), 判空防中断

// ================= Excel式列筛选 =================
const domFilters={};                                     // 筛选状态表: 键="表ID|列号", 值=允许值Set
function applyDomFilters(tid){  // 对指定表格应用全部列筛选条件(逐行显隐)
  const t=document.getElementById(tid); if(!t)return;    // 表格不存在直接返回
  t.querySelectorAll('tbody tr').forEach(tr=>{           // 遍历每条数据行
    let ok=true;                                         // 默认本行可见
    for(const k in domFilters){                          // 遍历每个筛选条件
      if(!k.startsWith(tid+'|'))continue;                // 只处理属于本表的筛选
      const allow=domFilters[k];                         // 该列允许显示的值集合
      if(allow&&allow.size){                             // 有非空筛选集才过滤
        const td=tr.children[+k.split('|')[1]];          // 取本行对应列单元格
        if(!td||!allow.has(td.textContent.trim())){ok=false;break;}  // 值不在集合内 → 本行隐藏
      }
    }
    tr.style.display=ok?'':'none';                       // 按判定结果显示/隐藏行
  });
}
function openFltMenu(tid,ci,btn){  // 打开某表某列的筛选勾选菜单
  const t=document.getElementById(tid);                  // 目标表格
  const vals=new Set();                                  // 收集该列全部去重值
  t.querySelectorAll('tbody tr').forEach(tr=>{const td=tr.children[ci];if(td)vals.add(td.textContent.trim());});  // 扫描各行取列值
  const key=tid+'|'+ci, cur=domFilters[key];             // 筛选键与当前已选集合
  const m=document.getElementById('fltMenu'); m.innerHTML='';  // 清空并复用全局筛选菜单
  const lab=document.createElement('label'); lab.className='fmA';  // "(全选)"行
  const all=document.createElement('input'); all.type='checkbox'; all.checked=!cur||!cur.size;  // 全选框: 无筛选时默认勾上
  lab.appendChild(all); lab.appendChild(document.createTextNode(' (全选)')); m.appendChild(lab);  // 挂入菜单
  const boxes=[];                                        // 各值复选框数组[checkbox, 值]
  [...vals].sort().forEach(v=>{                          // 按值排序生成选项
    const l=document.createElement('label'); const c=document.createElement('input'); c.type='checkbox';  // 每值一个label+checkbox
    c.checked=!cur||!cur.size||cur.has(v); boxes.push([c,v]);  // 无筛选/全选态时默认全勾
    l.appendChild(c); l.appendChild(document.createTextNode(' '+(v||'(空)'))); m.appendChild(l);  // 空值显示"(空)"
  });
  const sync=()=>{                                       // 勾选变化后同步筛选状态并刷新
    if(all.checked||boxes.every(([c])=>c.checked)) delete domFilters[key];  // 全勾=不过滤
    else { const s=new Set(); boxes.forEach(([c,v])=>{if(c.checked)s.add(v);}); domFilters[key]=s; }  // 否则记录勾选集合
    applyDomFilters(tid);                                // 立即应用筛选
  };
  all.onchange=()=>{boxes.forEach(([c])=>c.checked=all.checked);sync();};  // 全选框联动所有值框
  boxes.forEach(([c])=>c.onchange=()=>{all.checked=boxes.every(([c2])=>c2.checked);sync();});  // 值框变化回写全选框
  m.style.display='block';                               // 显示菜单
  const r=btn.getBoundingClientRect();                   // 按钮屏幕位置用于定位
  m.style.left=Math.min(r.left,innerWidth-200)+'px';     // 水平定位(防溢出右边界)
  m.style.top=Math.min(r.bottom+2,innerHeight-300)+'px'; // 垂直定位在按钮下方(防溢出底边)
}
function buildFilters(tid){  // 给指定表格每列表头加"▼"筛选按钮
  const t=document.getElementById(tid); if(!t||t.dataset.flt)return; t.dataset.flt=1;  // 不存在或已建过则跳过
  t.querySelectorAll('thead th').forEach((th,ci)=>{      // 遍历每个表头列
    const b=document.createElement('span'); b.className='fbtn'; b.textContent='▼';  // 创建▼按钮
    b.title='Excel式筛选: 勾选要显示的值';                // 悬浮提示
    b.onclick=e=>{e.stopPropagation();openFltMenu(tid,ci,b);};  // 点击打开菜单(阻止冒泡防全局关闭)
    th.appendChild(b);                                   // 挂到表头
  });
}
buildFilters('gridT');buildFilters('flowT');             // 页面加载即给监测表/流水表建筛选
document.addEventListener('click',()=>{document.getElementById('fltMenu').style.display='none';});  // 点击页面任意处关闭筛选菜单

// ---- [2026-09-27 用户指令] 保底持仓(ETH九7旧功能)已随旧策略删除 ----

// ---- K线买卖平仓标注(买入🚀/加仓金▲/平仓🍃带盈利) ----
async function loadMarks(){  // 拉取近7天买卖标注, 存入 window.OKX_MARKS 供 nqall_chart.js 绘制
  try{
    const inst=encodeURIComponent(window.OKX_INST||'ETH-USDT-SWAP');  // 当前合约编码
    const j=await(await fetch((window.AH?window.AH+'/marks?days=7&inst=':'api.php?action=marks&days=7&inst=')+inst)).json();  // 拉7天标注数据
    window.OKX_MARKS=(j.ok&&j.data)||[];                 // 成功则存全局, 失败置空数组
    if(window.okxRedraw)window.okxRedraw();      // 立即重画, 不等下一次交互
  }catch(e){window.OKX_MARKS=[];}                        // 异常时清空标注不中断页面
}

// ---- 流水行数变化 → 立即刷新标注(新买入/平仓第一时间上图) ----
let _flowCnt=0;                                          // 上次流水行数缓存
function flowChangedRefreshMarks(){  // 检测流水变化触发标注刷新
  if(flowRows.length!==_flowCnt){_flowCnt=flowRows.length;loadMarks();}  // 行数变了才重新拉标注
}

// ---- 合约下拉(原api.php?action=symbols) ----
fetch(window.AH?window.AH+'/symbols':'api.php?action=symbols').then(r=>r.json()).then(j=>{  // 拉权威合约池填充下拉框
  const sel=document.getElementById('symbol');           // 下拉框元素
  sel.innerHTML=(j.data||['ETH-USDT-SWAP']).map(s=>`<option ${s===window.OKX_INST?'selected':''}>${s}</option>`).join('');  // 逐合约生成option(当前合约选中)
  sel.onchange=()=>switchInst(sel.value);                // 选择变化 → 切换合约
});
// ---- 最近交易合约快捷按钮(⚡排, 一键切换带标注的K线) ----
document.querySelectorAll('#recentInsts .tf[data-inst]').forEach(el=>{  // 最近合约按钮排
  el.onclick=()=>switchInst(el.dataset.inst);            // 点击按钮 → 切换到对应合约
});
document.getElementById('lvn').textContent=window.OKX_INST;  // K线左上角合约名初始化
// 顶部状态栏: 同步K线组件缓冲状态
setInterval(()=>{                                        // 每10秒同步一次状态栏文案
  const s=document.getElementById('stat');               // K线组件的状态栏(缓冲根数等)
  document.getElementById('status').textContent='✓ 实时同步 '+new Date().toLocaleTimeString('zh-CN',{hour12:false})+(s?' · '+s.textContent:'');  // 状态栏=同步时间+K线缓冲信息
},10000);

loadLive();loadTrades();loadGrid();loadMarks();          // 页面加载后立即执行四项数据加载
setInterval(loadLive,3000);                              // 实时统计每3秒轮询
setInterval(loadGrid,3000);                              // 加仓监测每3秒轮询
setInterval(loadTrades,10000);                           // 交易流水每10秒轮询
setInterval(loadMarks,15000);                            // K线标注每15秒轮询

// ---- 底部居中实时监测悬浮窗: 折叠/展开 + 标题栏拖动 ----
(function(){                                             // IIFE: 不污染全局
  const mwin=document.getElementById('monWin'); if(!mwin)return;  // 悬浮窗不存在直接退出
  const gmb=document.getElementById('gminBtn');          // 折叠/展开按钮
  const head=mwin.querySelector('h3');                   // 标题栏(拖动手柄)
  if(gmb)gmb.onclick=(e)=>{                              // 折叠按钮点击
    e.stopPropagation();                                 // 阻止冒泡(防触发拖动/关闭菜单)
    mwin.classList.toggle('min');                        // 切换折叠样式类
    gmb.textContent=mwin.classList.contains('min')?'□':'─';  // 按钮图标随状态切换
  };
  // 首次拖动前把 translateX(-50%) 居中转成显式 left/top
  function toAbsolute(){                                 // 把CSS居中定位转成显式坐标
    const r=mwin.getBoundingClientRect();                // 当前实际位置尺寸
    mwin.style.left=Math.max(8,(window.innerWidth-r.width)/2)+'px';  // 水平居中(至少留8px)
    mwin.style.top=Math.max(8,window.innerHeight-r.height-12)+'px';  // 底部上移12px(至少留8px)
    mwin.style.bottom='auto';mwin.style.right='auto';mwin.style.transform='none';  // 清掉原定位方式
  }
  let drag=false,sx=0,sy=0,ox=0,oy=0;                    // 拖动状态: 进行中/起点/原始位置
  function startDrag(x,y){                               // 开始拖动(鼠标或触摸统一入口)
    if(getComputedStyle(mwin).transform!=='none'&&mwin.style.left==='')toAbsolute();  // 仍有transform居中 → 先转绝对定位
    if(mwin.style.left==='')toAbsolute();                // 无显式left → 同样转换
    drag=true;sx=x;sy=y;ox=mwin.offsetLeft;oy=mwin.offsetTop;  // 记录拖动起点与窗体原始位置
  }
  function moveDrag(x,y){                                // 拖动中更新位置
    if(!drag)return;                                     // 未在拖动则忽略
    mwin.style.left=Math.min(Math.max(8,ox+x-sx),window.innerWidth-100)+'px';  // 水平跟随(限制在视口内)
    mwin.style.top=Math.min(Math.max(8,oy+y-sy),window.innerHeight-36)+'px';   // 垂直跟随(限制在视口内)
  }
  head.addEventListener('mousedown',e=>{                 // 鼠标按下标题栏 → 开始拖动
    if(e.target===gmb)return;                            // 点在折叠按钮上不拖动
    e.preventDefault();startDrag(e.clientX,e.clientY);   // 防选中文本, 记录起点
  });
  window.addEventListener('mousemove',e=>moveDrag(e.clientX,e.clientY));  // 鼠标移动 → 跟随
  window.addEventListener('mouseup',()=>drag=false);     // 松开 → 结束拖动
  head.addEventListener('touchstart',e=>{                // 触摸开始(移动端)
    if(e.target===gmb)return;                            // 触在按钮上不拖动
    const t=e.touches[0];startDrag(t.clientX,t.clientY); // 取第一个触点开始拖动
  },{passive:true});
  head.addEventListener('touchmove',e=>{                 // 触摸移动
    const t=e.touches[0];moveDrag(t.clientX,t.clientY);e.preventDefault();  // 跟随并阻止页面滚动
  },{passive:false});
  head.addEventListener('touchend',()=>drag=false);      // 触摸结束 → 停止拖动
})();
