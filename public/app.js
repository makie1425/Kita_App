const COLORS = {

  brand:"#1747e8", brandDark:"#1236b8", brandBg:"#eaf0ff",
  bg:"#f7f9fc", surface:"#ffffff", border:"#e4e7ec",
  text:"#172033", textSoft:"#667085", textMuted:"#667085",
  green:"#16a34a", greenBg:"#e8f8ee",
  amber:"#92620a", amberBg:"#fdf3dc",
  red:"#dc2626", redBg:"#fdeaea",
  blue:"#2563eb", blueBg:"#eaf1ff",
  purple:"#1236b8", purpleBg:"#eaf0ff",
};

const mojibakeReplacements = [
  ["â‚±", "\u20B1"], ["â€”", " - "], ["â€¦", "..."], ["âˆ’", "-"], ["âš ", "[!]"],
  ["ðŸ”", "[Search]"], ["âœ•", "x"], ["ðŸ”’", "[Lock]"], ["âŸ³", "..."],
  ["â–²", "^"], ["â–¼", "v"], ["Ã—", "x"], ["Â·", " - "], ["ðŸ“·", "[Photo]"],
  ["â†’", "->"], ["â†", "<-"], ["â–¾", "v"], ["ðŸ–¨", "[Print]"], ["âœ“", "[OK]"],
  ["â³", "[Time]"], ["ðŸŽ‰", "[Event]"], ["ðŸ“‰", "[Chart]"], ["ðŸ—‚", "[Category]"],
  ["â˜…", "*"], ["â˜†", "-"], ["â›”", "[Block]"], ["Î”", "Delta"], ["â“˜", "?"]
];
function cleanMojibake(value) {
  if (typeof value !== "string") return value;
  return mojibakeReplacements.reduce((text, [broken, clean]) => text.split(broken).join(clean), value);
}
const originalCreateElement = React.createElement;
function actionTone(label) {
  const text=String(label||"").trim().replace(/^[+\s]+/,"");
  if (/^(cancel|close|deactivate|inactive|delete|remove|reject|decline|void|log out|flag|move to archive)\b/i.test(text)) return "red";
  if (/^(activate|active|approve|confirm|save|saving|create|add|submit|receive|restore|apply|mark all|posting)\b/i.test(text)) return "green";
  if (/^(edit|view|print|download|export|refresh|refreshing|retry|load|loading|look up|search|back|next|previous|clear|reset|run backup|creating|start|charge|purchase|register|request|copy|switch|sign in|signing|verify|verifying)\b/i.test(text)) return "blue";
  return null;
}
React.createElement = (type, props, ...children) => {
  let cleanProps = props && typeof props === "object" ? Object.fromEntries(
    Object.entries(props).map(([key, value]) => [key, cleanMojibake(value)])
  ) : props;
  if (type === "button") {
    const label=children.flat(Infinity).filter(value=>typeof value==="string"||typeof value==="number").map(cleanMojibake).join(" ");
    const classes=cleanProps?.className||"";
    const explicitDanger=classes.includes("kita-button--danger")||cleanProps?.style?.color===COLORS.red||cleanProps?.style?.background===COLORS.red;
    const tone=cleanProps?.['data-action-tone']||(explicitDanger?"red":actionTone(label))||(classes.includes("kita-button--green")?"green":classes.includes("kita-button")?"blue":null);
    if(tone)cleanProps={...cleanProps,'data-action-tone':tone};
  }
  // Shared presentation hooks for the existing screen builders; event props stay intact.
  if (typeof type === "string" && cleanProps?.style) {
    const style=cleanProps.style, classes=[cleanProps.className||""];
    if(style.display==="grid") classes.push("kita-grid");
    if(style.flex===1 && style.overflowY==="auto") classes.push("dialog-scroll");
    if(style.display==="flex" && style.flexDirection!=="column") classes.push("kita-flex-row");
    if(style.position==="fixed" && style.inset===0 && style.background) classes.push("kita-modal-overlay");
    cleanProps={...cleanProps,className:classes.filter(Boolean).join(" ")};
  }
  // Give legacy screens a shared action row without changing their handlers.
  let presentationChildren=children.map(cleanMojibake);
  if(['div','section','form','td'].includes(type)&&!['flex','grid'].includes(cleanProps?.style?.display)&&!/(action|toolbar|pagination|grid)/.test(cleanProps?.className||'')){
    const items=presentationChildren.flat(Infinity),grouped=[];let actions=[];
    const flush=()=>{if(actions.length>1)grouped.push(originalCreateElement('div',{key:'actions-'+grouped.length,className:'action-group'},...actions));else grouped.push(...actions);actions=[];};
    for(const item of items){if(item&&(item.type==='button'||(item.type==='a'&&item.props?.className?.includes('kita-button'))))actions.push(item);else{flush();grouped.push(item);}}
    flush();presentationChildren=grouped;
  }
  return originalCreateElement(type, cleanProps, ...presentationChildren);
};
function auditFields(value, products = []) {
  const fields = new Map();
  const hidden = /^(password.*|.*token|.*hash|submissionKey|idempotencyKey|revision|receivingVersion|requestedById|requestedByRole|approvedById|approvedByRole)$/i;
  const labels = {id:"ID",poId:"PO ID",receiptId:"Receipt ID",productId:"Product",qty:"Quantity",confirmedQty:"Approved quantity",editedQty:"Edited quantity",refundLimit:"Refund limit",unitCost:"Unit cost",dateCreated:"Date created",requested_at:"Requested at",approved_at:"Approved at"};
  const label = key => labels[key] || (key.replace(/([a-z0-9])([A-Z])/g,"$1 $2").replace(/_/g," ").replace(/^./,c=>c.toUpperCase()));
  const visit = (raw, path = [], titles = []) => {
    let item = raw;
    if(typeof item === "string" && /^[\[{]/.test(item.trim())) {
      try { item = JSON.parse(item); } catch { item = "Details unavailable"; }
    }
    if(Array.isArray(item)) {
      if(!item.length) fields.set(path.join("."),{label:titles.join(" / "),value:"No items"});
      item.forEach((entry,index)=>visit(entry,[...path,String(index)],[...titles,"Item "+(index+1)]));
    } else if(item && typeof item === "object") {
      Object.entries(item).forEach(([key,entry])=>{
        if(!hidden.test(key)) visit(entry,[...path,key],[...titles,label(key)]);
      });
    } else {
      let text = item == null || item === "" ? "Not set" : typeof item === "boolean" ? (item?"Yes":"No") : String(item);
      if(path[path.length-1] === "productId" && item != null) {
        const product=products.find(p=>String(p.id)===String(item));
        text=product?product.name+" (#"+item+")":"Product #"+item;
      }
      fields.set(path.join("."),{label:titles.join(" / ") || "Status / value",value:text});
    }
  };
  visit(value);
  return fields;
}
function auditChanges(before, after, products) {
  const previous=auditFields(before,products), next=auditFields(after,products);
  const changes=[...new Set([...previous.keys(),...next.keys()])].map(key=>({
    key,label:(next.get(key)||previous.get(key)).label,
    before:previous.get(key)?.value||"Not set",after:next.get(key)?.value||"Not set",
  })).filter(change=>change.before!==change.after);
  if(!changes.length) return React.createElement("span",{className:"helper-text"},"No displayable changes recorded.");
  return React.createElement("dl",{className:"audit-changes"},changes.map(change=>React.createElement("div",{key:change.key,className:"audit-change"},[
    React.createElement("dt",{key:"field"},change.label),
    React.createElement("dd",{key:"values"},[
      React.createElement("div",{key:"before"},[React.createElement("span",{className:"audit-value-label"},"Before"),statusValue(change.before)]),
      React.createElement("div",{key:"after"},[React.createElement("span",{className:"audit-value-label"},"After"),statusValue(change.after)]),
    ]),
  ])));
}
function statusStyle(status) {
  const s = String(status||"").toLowerCase().replace(/[_-]+/g," ");
  if (/\b(inactive|rejected|disapproved|failed|cancelled|canceled|blocked|quarantine|discrepancy|damaged|declined|void|voided|expired|timeout)\b|timed out/.test(s)) return {bg:COLORS.redBg,fg:"#b91c1c"};
  if (/\b(released|release|refunded|refund|reversed)\b/.test(s)) return {bg:COLORS.blueBg,fg:"#1d4ed8"};
  if (/low stock|\b(pending|unpaid|draft|scheduled|partial|partially|processing|awaiting|waiting|reserved|reservation|outstanding)\b|proceed to purchase|subject to reorder|not paid/.test(s)) return {bg:COLORS.amberBg,fg:COLORS.amber};
  if (/\b(active|approved|paid|delivered|resolved|closed|full|completed|confirmed|checkout)\b|fully received|inventory updated/.test(s)) return {bg:COLORS.greenBg,fg:"#15803d"};
  if (/\b(unused|informational|system|auto|forwarded)\b/.test(s)) return {bg:COLORS.blueBg,fg:"#1d4ed8"};
  return {bg:"#eef0f4",fg:COLORS.textSoft};
}
function statusValue(value) {
  return /^(pending payment|paid|unpaid|cancelled|canceled|active|inactive|partially refunded|refunded|voided|failed|expired|payment reserved|payment reservation|payment reservation released|payment confirmed)$/i.test(String(value))?badge(value):value;
}

const PRODUCT_MEASUREMENT_UNITS={"Weight": {"mg": "mg (Milligram)", "g": "g (Gram)", "kg": "kg (Kilogram)", "tonne": "t (Metric tonne)", "oz": "oz (Ounce ? weight)", "lb": "lb (Pound)"}, "Volume": {"mL": "mL (Milliliter)", "cL": "cL (Centiliter)", "dL": "dL (Deciliter)", "Liter": "L (Liter)", "kL": "kL (Kiloliter)", "US fl oz": "fl oz (US fluid ounce)", "US pint": "pt (US pint)", "US quart": "qt (US quart)", "US gallon": "gal (US gallon)", "Imp gallon": "gal (Imperial gallon)", "cm3": "cm? (Cubic centimeter)", "m3": "m? (Cubic meter)"}, "Length": {"mm": "mm (Millimeter)", "cm": "cm (Centimeter)", "m": "m (Meter)", "km": "km (Kilometer)", "in": "in (Inch)", "ft": "ft (Foot)", "yd": "yd (Yard)"}, "Area": {"cm2": "cm? (Square centimeter)", "m2": "m? (Square meter)", "in2": "in? (Square inch)", "ft2": "ft? (Square foot)"}, "Count": {"Piece": "Piece", "Pair": "Pair", "Dozen": "Dozen", "Sheet": "Sheet", "Serving": "Serving", "Tablet": "Tablet", "Capsule": "Capsule"}};
function peso(n){ n=Number(n)||0; return "â‚±"+n.toLocaleString("en-PH",{minimumFractionDigits:2,maximumFractionDigits:2}); }
function badge(status){ const c=statusStyle(status); return React.createElement("span",{className:"status-badge",style:{background:c.bg,color:c.fg,fontSize:11,fontWeight:700,padding:"3px 10px",borderRadius:20,display:"inline-block",whiteSpace:"nowrap"}},status); }
function card(children, style){ return React.createElement("div",{className:"kita-card",style:Object.assign({background:COLORS.surface,border:"1px solid "+COLORS.border,borderRadius:12,padding:18},style||{})},children); }
function sectionTitle(t, sub){ return React.createElement("div",{className:"section-heading",style:{marginBottom:16}},[React.createElement("h1",{key:"t",style:{fontSize:24,fontWeight:800,margin:0,letterSpacing:-0.7}},t), sub?React.createElement("div",{key:"s",style:{fontSize:13,color:COLORS.textSoft,marginTop:2}},sub):null]); }
function moduleTitle(title, subtitle){
  return React.createElement('div',{className:'module-page-heading'+(subtitle?' has-subtitle':'')},[
    React.createElement('h1',null,title),subtitle?React.createElement('div',null,subtitle):null
  ]);
}
// A synchronized native scrollbar stays within its table while sticking to the viewport.
function stickyTable(content){
  let cleanup;
  const ref=frame=>{
    if(cleanup){cleanup();cleanup=null;}
    if(!frame)return;
    const viewport=frame.firstElementChild,rail=frame.lastElementChild,spacer=rail.firstElementChild;
    const syncFromTable=()=>{if(rail.scrollLeft!==viewport.scrollLeft)rail.scrollLeft=viewport.scrollLeft;};
    const syncFromRail=()=>{if(viewport.scrollLeft!==rail.scrollLeft)viewport.scrollLeft=rail.scrollLeft;};
    const measure=()=>{
      const overflow=viewport.scrollWidth>viewport.clientWidth+1;
      rail.hidden=!overflow;
      spacer.style.width=(viewport.scrollWidth+rail.clientWidth-viewport.clientWidth)+'px';
      syncFromTable();
    };
    viewport.addEventListener('scroll',syncFromTable,{passive:true});
    rail.addEventListener('scroll',syncFromRail,{passive:true});
    const observer=typeof ResizeObserver!=='undefined'?new ResizeObserver(measure):null;
    observer?.observe(viewport);
    if(viewport.firstElementChild)observer?.observe(viewport.firstElementChild);
    window.addEventListener('resize',measure);
    measure();
    cleanup=()=>{observer?.disconnect();window.removeEventListener('resize',measure);viewport.removeEventListener('scroll',syncFromTable);rail.removeEventListener('scroll',syncFromRail);};
  };
  return React.createElement('div',{className:'sticky-table-frame',ref},[
    content,
    React.createElement('div',{className:'sticky-table-scrollbar',hidden:true,tabIndex:0,role:'region','aria-label':'Scroll table horizontally'},React.createElement('div',{className:'sticky-table-scrollbar-width','aria-hidden':true})),
  ]);
}
function table(headers, rows, emptyMessage="No records yet."){ return stickyTable(React.createElement("div",{className:"table-scroll",tabIndex:0,role:"region","aria-label":headers.filter(h=>typeof h==="string"&&h).join(", "),style:{"--table-min-width":Math.max(540,headers.length*110)+"px",overflowX:"auto",border:"1px solid "+COLORS.border,borderRadius:10}}, React.createElement("table",{style:{width:"100%",borderCollapse:"collapse",fontSize:13}},[
  React.createElement("thead",{key:"h"},React.createElement("tr",null, headers.map((hd,i)=>React.createElement("th",{key:i,style:{position:"sticky",top:0,background:"#f8faff",textAlign:"left",padding:"10px 14px",fontSize:11,fontWeight:700,color:COLORS.textMuted,letterSpacing:0.4,borderBottom:"1px solid "+COLORS.border}},hd)))),
  React.createElement("tbody",{key:"b"},rows.length?rows:React.createElement("tr",null,React.createElement("td",{colSpan:headers.length,style:{padding:20,textAlign:"center",color:COLORS.textMuted,fontSize:13}},emptyMessage))),
]))); }
function td(children, style){ return React.createElement("td",{style:Object.assign({padding:"10px 14px",borderBottom:"1px solid #eef1f6"},style||{})},children); }
function tr(children,key){ return React.createElement("tr",{key},children); }
function uiIcon(name){
  const paths={grid:"M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z",box:"M3 7l9-4 9 4v10l-9 4-9-4z M3 7l9 4 9-4 M12 11v10",cart:"M3 3h2l3 12h10l3-8H6 M9 20h.01 M18 20h.01",users:"M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M17 4a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-4",file:"M14 2H5v20h14V7z M14 2v6h5 M8 12h8 M8 16h6",chart:"M4 3v17h17 M8 15v-4 M13 15V7 M18 15V4",shield:"M12 3l8 3v6c0 5-8 9-8 9s-8-4-8-9V6z M8 12l3 3 5-6",tag:"M3 3h8l10 10-8 8L3 11z M7 7h.01",search:"M21 21l-5-5 M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14",clock:"M12 8v5l3 2 M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18"};
  return React.createElement("svg",{className:"ui-icon",width:20,height:20,viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:1.7,strokeLinecap:"round",strokeLinejoin:"round","aria-hidden":true,focusable:false},React.createElement("path",{d:paths[name]||paths.file}));
}
function navigationIcon(key){
  return uiIcon(/Dashboard|Reports/.test(key)?"chart":/Account|Cashiers|Managers|Admins|Supplier/.test(key)?"users":/Security|Roles/.test(key)?"shield":/Categories|Promotions/.test(key)?"tag":/Inventory|Registration|archive|PO|Receiving/.test(key)?"box":/pos|Request|Purchased/.test(key)?"cart":/shift|Backup/.test(key)?"clock":"file");
}
function kpi(label, value, sub, color, tooltip){
  return React.createElement("div",{className:"kita-card kpi-card"},[
    React.createElement("div",{key:"head",className:"kpi-heading"},[
      React.createElement("span",{key:"label"},label),
      React.createElement("span",{key:"icon",className:"kpi-icon"},uiIcon(/stock|inventory|product/i.test(label)?"box":/sales|value|revenue/i.test(label)?"chart":"file")),
    ]),
    React.createElement("div",{key:"value",className:"kpi-value",style:{color:color||COLORS.text}},value),
    sub?React.createElement("div",{key:"sub",className:"kpi-note"},sub):null,
    tooltip?React.createElement("div",{key:"tip",className:"kpi-note"},tooltip):null,
  ]);
}
// Match word beginnings: "b" finds "Bread" and "Whole Bread", never "Cabbage".
function searchMatches(value,query){
  const words=String(value??'').toLocaleLowerCase().match(/[\p{L}\p{N}]+/gu)||[];
  const terms=String(query??'').trim().toLocaleLowerCase().match(/[\p{L}\p{N}]+/gu)||[];
  return terms.every(term=>words.some(word=>word.startsWith(term)));
}
function recordText(node){
  if(node==null||typeof node==="boolean")return "";
  if(typeof node==="string"||typeof node==="number")return String(node);
  if(Array.isArray(node))return node.map(recordText).join(" ");
  if(["button","input","select","svg"].includes(node.type))return "";
  return recordText(node.props?.children??node.children);
}
function rankedSales(rows, onPromo){
  const maximum=Math.max(1,...rows.map(row=>row.rev));
  return rows.length?React.createElement("div",{className:"sales-ranking"},rows.map((row,index)=>React.createElement("div",{key:row.p.id,className:"sales-rank"},[
    React.createElement("div",{key:"label",className:"sales-rank-label"},[React.createElement("span",{key:"name"},`${index+1}. ${row.p.name}`),React.createElement("strong",{key:"value"},peso(row.rev))]),
    React.createElement("div",{key:"bar",className:"sales-bar","aria-hidden":true},React.createElement("span",{style:{width:(row.rev/maximum*100)+"%"}})),
    onPromo?btn("Create promotion",onPromo(row.p.id)):null,
  ]))):React.createElement("div",{className:"record-empty"},"No sales recorded for these products yet.");
}
function dashboardSales(data, period, selectedDate){
  const anchor=selectedDate||data.BUSINESS_DATE;
  const start=new Date(anchor+"T00:00:00Z");
  if(!Number.isFinite(start.getTime())) return {rows:[],series:[],total:0,units:0,range:"Business date unavailable"};
  if(period==="weekly") start.setUTCDate(start.getUTCDate()-(start.getUTCDay()+6)%7);
  if(period==="monthly") start.setUTCDate(1);
  if(period==="yearly") start.setUTCMonth(0,1);
  const end=new Date(start);
  if(period==="yearly") end.setUTCFullYear(end.getUTCFullYear()+1);
  else if(period==="monthly") end.setUTCMonth(end.getUTCMonth()+1);
  else end.setUTCDate(end.getUTCDate()+(period==="weekly"?7:1));
  const from=start.toISOString().slice(0,10), until=end.toISOString().slice(0,10);
  const rows=(data.SALES_LOG||[]).filter(row=>row.date>=from&&row.date<until);
  const series=[];
  const cursor=new Date(start);
  while(cursor<end){
    const date=cursor.toISOString().slice(0,10);
    const key=period==="daily"?String(cursor.getUTCHours()):period==="yearly"?date.slice(0,7):date;
    const label=period==="daily"?key.padStart(2,"0")+":00":period==="yearly"?cursor.toLocaleString("en-PH",{month:"short",timeZone:"UTC"}):date.slice(5);
    series.push({key,label,value:0});
    if(period==="daily") cursor.setUTCHours(cursor.getUTCHours()+1);
    else if(period==="yearly") cursor.setUTCMonth(cursor.getUTCMonth()+1);
    else cursor.setUTCDate(cursor.getUTCDate()+1);
  }
  const buckets=new Map(series.map(point=>[point.key,point]));
  rows.forEach(row=>{
    const key=period==="daily"?String(Number(row.hour)):period==="yearly"?row.date.slice(0,7):row.date;
    if(buckets.has(key)) buckets.get(key).value+=row.qty*row.amount;
  });
  end.setUTCDate(end.getUTCDate()-1);
  const to=end.toISOString().slice(0,10);
  return {rows,series,total:rows.reduce((sum,row)=>sum+row.qty*row.amount,0),units:rows.reduce((sum,row)=>sum+Number(row.qty),0),range:from===to?from:from+" to "+to};
}
function salesHistoryChart(sales,period){
  const h=React.createElement, points=sales.series;
  const maximum=Math.max(1,...points.map(point=>point.value));
  const minimum=Math.min(0,...points.map(point=>point.value));
  const x=i=>110+i*650/Math.max(1,points.length-1), y=value=>235-(value-minimum)/(maximum-minimum)*200;
  const interval=Math.max(1,Math.ceil(points.length/8));
  const unit=period==="daily"?"Hour":period==="yearly"?"Month":"Date (MM-DD)";
  return card([
    h("div",{className:"dashboard-panel-heading"},[h("h2",{key:"title",className:"panel-title"},"Sales History"),h("span",{className:"dashboard-period-badge"},period+" breakdown")]),
    h("p",{key:"range",className:"helper-text"},"Sales (PHP) • "+sales.range),
    !sales.rows.length?h("p",{key:"empty",role:"status"},"No sales recorded for this period."):null,
    points.length?h("div",{key:"chart",className:"sales-history-scroll"},h("svg",{viewBox:"0 0 800 295",className:"sales-history-chart",role:"img","aria-label":"Sales history line graph, "+sales.range},[
      h("title",{key:"title"},"Sales in PHP by "+unit.toLowerCase()),
      ...Array.from({length:5},(_,i)=>{const value=minimum+(maximum-minimum)*i/4;return h("g",{key:"grid"+i},[
        h("line",{x1:110,x2:760,y1:y(value),y2:y(value),stroke:"#e4e7ec"}),
        h("text",{x:100,y:y(value)+4,textAnchor:"end",fontSize:11,fill:COLORS.textSoft},value.toLocaleString("en-PH",{maximumFractionDigits:2})),
      ]);}),
      h("polygon",{key:"area",points:x(0)+","+y(0)+" "+points.map((point,i)=>x(i)+","+y(point.value)).join(" ")+" "+x(points.length-1)+","+y(0),fill:"#2864f514"}),
      h("polyline",{key:"line",points:points.map((point,i)=>x(i)+","+y(point.value)).join(" "),fill:"none",stroke:COLORS.brand,strokeWidth:3,strokeLinejoin:"round"}),
      ...points.map((point,i)=>h("circle",{key:point.key,cx:x(i),cy:y(point.value),r:4,fill:COLORS.brand},h("title",null,point.label+": PHP "+point.value.toFixed(2)))),
      ...points.filter((point,i)=>i%interval===0||i===points.length-1).map(point=>h("text",{key:"label"+point.key,x:x(points.indexOf(point)),y:255,textAnchor:"middle",fontSize:11,fill:COLORS.textSoft},point.label)),
      h("text",{key:"axis",x:435,y:283,textAnchor:"middle",fontSize:12,fill:COLORS.textSoft},unit),
    ])):null,
    h("div",{className:"sales-peak"},"Peak: "+peso(Math.max(0,...points.map(p=>p.value)))),
    points.length?h("details",{key:"values"},[h("summary",null,"View sales history values"),table([unit,"Sales (PHP)"],points.map(point=>tr([td(point.label),td(peso(point.value))],point.key)))]):null,
  ],{marginBottom:16});
}
function btn(label,onClick,variant){ const styles={primary:{background:COLORS.brand,color:"#fff",border:"none"},ghost:{background:"#fff",color:COLORS.text,border:"1px solid "+COLORS.border},danger:{background:COLORS.redBg,color:COLORS.red,border:"1px solid #f5c6c6"},green:{background:COLORS.greenBg,color:COLORS.green,border:"1px solid #bfe8d3"},amber:{background:COLORS.amberBg,color:COLORS.amber,border:"1px solid #f3e0ac"}};
  return React.createElement("button",{type:"button",className:"kita-button kita-button--"+(variant||"ghost"),onClick,style:Object.assign({padding:"8px 14px",borderRadius:8,fontSize:12,fontWeight:700,cursor:"pointer"},styles[variant||"ghost"])},label); }
function loadingStatus(label){
  return React.createElement("span",{className:"loading-status",role:"status"},[
    React.createElement("span",{key:"spinner",className:"loading-spinner","aria-hidden":true}),
    React.createElement("span",{key:"label"},label)
  ]);
}
class Component extends DCLogic {
  state = {
    data: null,
    dashboardPeriod:"daily", dashboardDate:"",
    authStep:"login", loginPending:false, loginUsername:"", loginPassword:"", otpCode:"", otpEmail:"", loginError:"", loginTries:0, loginLocked:false, pendingRole:null,
    role:null, screen:null, toasts:[], sidebarOpen:false,tableViews:{},
    barcodeInput:"", cart:[], scannerConnected:true, manualLookupOpen:false, manualSearch:"",
    seniorPwdOpen:false, seniorForm:{name:"",idNumber:""}, seniorApplied:false, seniorInfo:null, employeeDiscount:false,
    overrideLine:null, overrideReason:"", overridePin:"",
    paymentMethod:"cash", ewalletProvider:"QR Ph", tendered:"", paymentState:"idle", reservationSeconds:45,
    commitOpen:false, lateWebhook:false, transactionResult:null,
    refundLookup:"", refundTxn:null, refundError:"", refundClass:"", refundSelectedLines:[],
    exchangeMode:false, exchangeReplacement:"",exchangeTendered:"", voidMode:false, voidPin:"", voidError:"",
    invTab:"table", supplierDetailId:null,
    newReqSupplier:"", newReqCart:[],
    repDateMode:"day", repDay:"", repMonth:"", repMonthFrom:"", repMonthTo:"", repDayFrom:"10", repDayTo:"27", repPaymentType:"all",
    itemPickerOpen:false, itemPickerSearch:"", itemPickerFilterCategory:"", itemPickerFilterSupplier:"", itemPickerChecked:{},
    itemRequestsLocal:null, purchaseOrdersLocal:null, receivingLocal:null, adjustmentsLocal:null,
    approvalNoteDraft:{}, rolesMatrix:null, notificationsLocal:null,
    newAdjForm:{productId:"",qtyChange:"",reason:"Shrinkage",comment:""}, evidenceFilename:null, evidenceSent:false,
    cashierModalOpen:false, cashierModalMode:"add", cashierForm:{id:null,name:"",username:"",schedule:"",status:"Active"}, cashiersLocal:null,
    productsLocal:null, fieldVersionHistoryLocal:null, categoriesLocal:null, suppliersLocal:null,
    priceEditOpen:false, priceEditTarget:null, priceEditValue:"", priceEditReason:"",
    addDamageOpen:false, addDamageForm:{productId:"",qty:""},
    categoryModalOpen:false, categoryForm:{name:"",status:"Active",classification:""}, categoryError:"",
    archiveTab:"items", archiveChecked:{}, archiveConfirmOpen:false,
    regProductMode:"select", regProductName:"", regOtherProductName:"", regCategory:"", regSupplier:"", regScannedBarcode:"", regQuantity:"",
    regCostPrice:"", regUnitPrice:"", regRetailPrice:"", regBatch:"", regLot:"", regExpiry:"", regPurchaseUnit:"Piece", regStockUnit:"Piece", regConversionFactor:"1", regStatus:"Active", regVatClass:"VAT-Exempt",
    regSaved:false, regSku:"", regBarcode:"", regRegisteredQty:0, regPrintQty:1,
    promoPickerOpen:false, promoPickerSearch:"", promoPickerChecked:{},
    promoSelectedItems:[], promoStartDate:"", promoEndDate:"", promoType:"Near-Expiry", promoOccasionName:"", promoCategory:"", promoDiscountPct:"",
    invSelected:{}, catSelected:{},
    supMgrScreen:"list", supMgrSearch:"", supMgrSelected:{}, supMgrDetailId:null, supMgrModalOpen:false,
    supMgrForm:{id:null,name:"",address:"",contact:"",phone:"",email:"",category:"",status:"Active",paymentTerms:"Cash on Delivery",notes:""},
    supMgrProductPickerOpen:false, supMgrProductSearch:"", supMgrProductChecked:{}, supMgrDetailTab:"products",
    accountsLocal:null, acctModalOpen:false, acctModalMode:"create", acctForm:{id:null,employeeId:"",name:"",role:"Cashier",password:"",status:"Active",dateCreated:""},
    saDashboard:null, saLoading:false, saError:"", acctRoleFilter:"", acctStatusFilter:"", acctCreatedNote:"",
    mgrNotifOpen:false, mgrNotificationsLocal:null,
  };

  setTableView=(key,patch)=>this.setState(s=>({tableViews:{...s.tableViews,[key]:{...s.tableViews[key],...patch}}}));
  recordTable(key,headers,rows,{emptyMessage,externalSearch=false,columnPicker=false,defaultHidden=[]}={}){
    const h=React.createElement,view=this.state.tableViews[key]||{},query=view.query||"",size=Number(view.size)||20;
    const hidden=columnPicker?(view.hiddenColumns||defaultHidden):[],visible=headers.map((_,i)=>i).filter(i=>!hidden.includes(headers[i]));
    const displayed=row=>columnPicker?h('tr',{key:row.key},(Array.isArray(row.props.children)?row.props.children.flat():[row.props.children]).filter((_,i)=>visible.includes(i))):row;
    const filtered=query.trim()?rows.filter(row=>searchMatches(recordText(row),query)):rows;
    const pages=Math.max(1,Math.ceil(filtered.length/size)),page=Math.min(Math.max(1,view.page||1),pages),start=(page-1)*size;
    const searchId="records-"+key;
    return h("section",{className:"record-list","aria-label":"Records"},[
      !externalSearch?h("div",{key:"toolbar",className:"record-toolbar"},[
        h("label",{key:"search",className:"record-search",htmlFor:searchId},[uiIcon("search"),h("span",{className:"sr-only"},"Search records"),h("input",{id:searchId,type:"search",value:query,placeholder:"Search these records...",onChange:e=>this.setTableView(key,{query:e.target.value,page:1})})]),
        query?h("button",{key:"clear",type:"button",className:"kita-button",onClick:()=>this.setTableView(key,{query:"",page:1})},"Clear search"):null,
        h("label",{key:"size",className:"record-page-size"},["Rows per page",h("select",{value:size,onChange:e=>this.setTableView(key,{size:Number(e.target.value),page:1})},[10,20,50,100].map(n=>h("option",{key:n,value:n},n)))])
      ]):null,
      columnPicker?h('details',{className:'inventory-columns'},[h('summary',null,'Choose columns'),h('div',null,headers.map(label=>label&&label!=='Actions'?h('label',{key:label},[h('input',{type:'checkbox',checked:!hidden.includes(label),disabled:!hidden.includes(label)&&visible.length===1,onChange:e=>this.setTableView(key,{hiddenColumns:e.target.checked?hidden.filter(v=>v!==label):[...hidden,label]})}),label]):null)),h('button',{type:'button',className:'kita-button',onClick:()=>this.setTableView(key,{hiddenColumns:defaultHidden})},'Reset columns')]):null,
      table(visible.map(i=>headers[i]),filtered.slice(start,start+size).map(displayed),query?"No records match your table search.":emptyMessage),
      !filtered.length&&!emptyMessage?h("p",{key:"empty",className:"record-empty",role:"status"},query?"No matching records. Try another search or clear your filters.":"Records will appear here when available."):null,
      h("div",{key:"pagination",className:"record-pagination"},[
        h("span",{key:"count",role:"status","aria-live":"polite"},`${filtered.length?start+1:0}-${Math.min(start+size,filtered.length)} of ${filtered.length} records${query?" ("+rows.length+" total)":""}`),
        h("div",{key:"buttons",className:"pagination-actions"},[
          h("button",{type:"button",disabled:page===1,onClick:()=>this.setTableView(key,{page:page-1})},"Previous"),
          h("span",null,`${page} / ${pages}`),
          h("button",{type:"button",disabled:page===pages,onClick:()=>this.setTableView(key,{page:page+1})},"Next"),
        ]),
      ]),
    ]);
  }
  componentDidUpdate() {
    // The custom logic runtime supplies no previous state. Keep our own snapshot
    // before triggering updates so nested state changes use the correct snapshot.
    const prevState=this.previousAppState||{};
    this.previousAppState=this.state;
    if(this.state.discountManagerPin && (prevState.cart!==this.state.cart || prevState.seniorApplied!==this.state.seniorApplied || prevState.employeeDiscount!==this.state.employeeDiscount || prevState.role!==this.state.role)){
      this.setState({discountManagerPin:""});
    }
  }
  syncNavigationViewport=()=>{
    const compact=window.matchMedia("(max-width: 1023px)").matches;
    this.setState({compactNavigation:compact,...(!compact?{sidebarOpen:false}:{})});
  };
  componentDidMount() {
    this.previousAppState=this.state;
    this.syncNavigationViewport();
    window.addEventListener("resize",this.syncNavigationViewport);
    if(window.KITA_AUTH?.loginError)this.setState({loginError:window.KITA_AUTH.loginError});
    const sessionUser=window.KITA_AUTH?.user;
    if(sessionUser) this.enterApp(sessionUser.role,null,sessionUser);

  }
  componentWillUnmount(){ window.removeEventListener("resize",this.syncNavigationViewport); }
  trackLoad=async work=>{
    this.setState(s=>({pendingLoads:(s.pendingLoads||0)+1}));
    try{return await work();}finally{this.setState(s=>({pendingLoads:Math.max(0,(s.pendingLoads||1)-1)}));}
  };
  reloadCatalog=()=>this.trackLoad(()=>fetch(window.KITA_DATA_URL||'/api/kita-data',{headers:{Accept:"application/json"},credentials:"same-origin"}).then(async r=>{
    const m=await r.json(); this.handleSessionResponse(r,m); if(!r.ok||m.error) throw new Error(m.message||"Could not load data."); return m;
  }).then(m=>new Promise(resolve=>this.setState({
    data:m,dataError:"",productsLocal:m.PRODUCTS.map(p=>({...p})),categoriesLocal:m.CATEGORIES,
    itemRequestsLocal:m.ITEM_REQUESTS,purchaseOrdersLocal:m.PURCHASE_ORDERS,receivingLocal:m.RECEIVING_RECORDS,
    adjustmentsLocal:m.ADJUSTMENTS,notificationsLocal:m.NOTIFICATIONS,cashiersLocal:m.USERS.cashier,
    fieldVersionHistoryLocal:m.FIELD_VERSION_HISTORY,suppliersLocal:m.SUPPLIERS,promotionsLocal:m.PROMOTIONS,checkoutPromotions:m.PROMOTIONS,
    rolesMatrix:this.state.rolesMatrix||this.defaultRolesMatrix(),mgrNotificationsLocal:this.state.mgrNotificationsLocal||[],
  },()=>resolve(m)))));
  reloadAccounts=()=>this.trackLoad(()=>fetch("/api/accounts",{headers:{Accept:"application/json"},credentials:"same-origin"}).then(async r=>{
    const body=await r.json(); this.handleSessionResponse(r,body); if(!r.ok) throw new Error(body.message||"Could not load accounts.");
    this.setState({accountsLocal:body.accounts}); return body.accounts;
  }));
  defaultRolesMatrix(){
    const modules=["Checkout","Refunds","Item Requests","Purchase Orders","Receiving","Inventory Adjustments","Promotions","Analytics","Approvals","Manager Accounts","Admin Accounts","Roles & Permissions","Backup Configuration","Audit Logs"];
    const roles=["Cashier","Manager","Admin","Super Admin"];
    const d={Checkout:[1,1,1,1],Refunds:[1,1,1,1],"Item Requests":[0,1,1,1],"Purchase Orders":[0,0,1,1],Receiving:[0,1,1,1],"Inventory Adjustments":[0,1,1,1],Promotions:[0,1,1,1],Analytics:[0,0,1,1],Approvals:[0,0,1,1],"Manager Accounts":[0,0,1,1],"Admin Accounts":[0,0,0,1],"Roles & Permissions":[0,0,0,1],"Backup Configuration":[0,0,0,1],"Audit Logs":[0,0,0,1]};
    return {modules,roles,grid:modules.map(m=>({module:m,cells:d[m].slice()}))};
  }
  toast=(message,tone)=>{ const id=Date.now()+Math.random(); const c=tone==="error"?{bg:"#fdeaea",fg:"#dc2626",bd:"#f5c6c6"}:tone==="warn"?{bg:"#fdf3dc",fg:"#92620a",bd:"#f3e0ac"}:{bg:"#e8f8ee",fg:"#16a34a",bd:"#bfe8d3"};
    if(this.state.screen==='mgrRegistration'){this.setState({registrationNotice:{message,tone:tone||'success'}},()=>{if(!this.state.categoryModalOpen&&typeof document!=='undefined')document.getElementById('registration-feedback')?.scrollIntoView({block:'nearest',behavior:'smooth'});});return;}
    this.setState(s=>({toasts:[...s.toasts,{id,message,style:{background:c.bg,color:c.fg,border:"1px solid "+c.bd,padding:"12px 16px",borderRadius:10,fontSize:13,fontWeight:600,marginBottom:8,boxShadow:"0 8px 24px rgba(10,40,30,0.12)",animation:"toastin .2s ease",minWidth:260}}]}));
    setTimeout(()=>this.setState(s=>({toasts:s.toasts.filter(t=>t.id!==id)})),3400);
  };
  currentUser=()=>{ const {data,role,authenticatedUser}=this.state; const account=((data&&data.USERS&&data.USERS[role])||[]).find(u=>String(u.id)===String(authenticatedUser?.id)); return {...(account||{}),name:authenticatedUser?.name||"",email:authenticatedUser?.email}; };

  onUsernameChange=e=>this.setState({loginUsername:e.target.value});
  onPasswordChange=e=>this.setState({loginPassword:e.target.value});
  onLoginKeyDown=e=>{
    if(e.key!=="Enter" || e.nativeEvent?.isComposing || e.isComposing) return;
    e.preventDefault();
    if(!e.repeat) this.doLogin();
  };
  backToPortalSelect=()=>this.setState({authStep:"login",pendingRole:null,loginUsername:"",loginPassword:"",loginError:""});
  authPost=(url,payload,method="POST",signal,retried=false)=>this.trackLoad(()=>fetch(url,{method,signal,headers:{"Content-Type":"application/json","Accept":"application/json","X-CSRF-TOKEN":(window.KITA_AUTH&&window.KITA_AUTH.csrfToken)||document.querySelector('meta[name="csrf-token"]')?.content||""},credentials:"same-origin",body:JSON.stringify(payload)}).then(async r=>{
    if(r.status===419&&!retried&&url===((window.KITA_AUTH&&window.KITA_AUTH.loginUrl)||'/login')){
      const refresh=await fetch('/auth/csrf-token',{signal,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      const token=await refresh.json();
      if(!refresh.ok||!token.csrf_token)throw new Error('Your session expired. Refresh this page and sign in again.');
      window.KITA_AUTH=window.KITA_AUTH||{};window.KITA_AUTH.csrfToken=token.csrf_token;
      return this.authPost(url,payload,method,signal,true);
    }
    const body=await r.json().catch(()=>{ throw new Error("Your session changed. Refresh the page to continue."); }); this.handleSessionResponse(r,body); if(!r.ok){ const msg=(body.errors && Object.values(body.errors)[0] && Object.values(body.errors)[0][0]) || body.message || "Request failed."; const err=new Error(msg); err.status=r.status; err.body=body; throw err; } if(this.state.authStep==='in'&&!url.startsWith('/api/notifications'))this.reloadNotifications(); return body; }));
  loginPost=async (url,payload)=>{
    const controller=new AbortController();
    const timer=setTimeout(()=>controller.abort(),30000);
    try { return await this.authPost(url,payload,"POST",controller.signal); }
    catch(error){
      if(error.name==="AbortError") throw new Error("Sign-in timed out. Please try again. If this continues, contact your administrator to check email delivery and server connectivity.");
      if(error instanceof TypeError) throw new Error("Cannot reach the server. Check your connection and refresh the page before trying again.");
      throw error;
    } finally { clearTimeout(timer); }
  };
  doLogin=e=>{
    e?.preventDefault();
    if(this.loginBusy) return;
    const emailInput=document.querySelector('.auth-card--login input[type="email"]');
    const passwordInput=document.querySelector('.auth-card--login input[type="password"]');
    const email=String(this.state.loginUsername||emailInput?.value||"").trim();
    const password=String(this.state.loginPassword||passwordInput?.value||"");
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)||!password){ this.setState({loginError:"Enter a valid email address and password."}); return; }
    this.loginBusy=true;
    this.setState({loginPending:true,loginError:""});
    this.loginPost(window.KITA_AUTH?.loginUrl||"/login",{email,password}).then(res=>{
      if(res.csrf_token) window.KITA_AUTH.csrfToken=res.csrf_token;
      if(res.otp_required){ this.setState({authStep:"otp",otpEmail:res.email||email,loginPassword:"",otpCode:"",loginError:""}); return; }
      this.setState({loginPassword:"",loginError:""}); this.enterApp(res.role,res.dashboard,res);
    }).catch(error=>this.setState({loginError:error.message,loginPassword:""})).finally(()=>{this.loginBusy=false;this.setState({loginPending:false});});
  };
  onOtpChange=e=>this.setState({otpCode:String(e.target.value||"").replace(/\D/g,"").slice(0,6)});
  verifyOtp=e=>{
    e?.preventDefault();
    if(this.loginBusy) return;
    if(!/^\d{6}$/.test(this.state.otpCode)){ this.setState({loginError:"Enter the 6-digit OTP sent to your email."}); return; }
    this.loginBusy=true;
    this.setState({loginPending:true,loginError:""});
    this.loginPost("/otp/verify",{email:this.state.otpEmail,otp:this.state.otpCode}).then(res=>{
      if(res.csrf_token) window.KITA_AUTH.csrfToken=res.csrf_token;
      this.setState({otpCode:"",loginError:""}); this.enterApp(res.role,res.dashboard,res);
    }).catch(error=>this.setState({loginError:error.message,otpCode:"",...(error.message==="Your account has been deactivated. Please contact your operator."?{authStep:"login",loginPassword:"",otpEmail:""}:{})})).finally(()=>{this.loginBusy=false;this.setState({loginPending:false});});
  };
  backToPassword=()=>this.setState({authStep:"login",otpCode:"",otpEmail:"",loginError:""});
  enterApp=(role,dashboard,user)=>{
    role=String(role||"").toLowerCase().replace(/[ _-]/g,"");
    const d={cashier:"pos",manager:"mgrDashboard",admin:"admDashboard",superadmin:"saDashboard"}[role];
    if(!d || !user?.name){ this.setState({authStep:"login",role:null,screen:null,loginError:"Your session could not be loaded. Refresh the page and sign in again."}); return; }
    this.setState({role,authStep:"in",screen:d,authenticatedUser:user,mgrNotificationsLocal:[],notificationUnread:0,notificationNext:null,mgrNotifOpen:false},()=>{this.reloadNotifications();this.reloadCatalog().catch(error=>this.setState({dataError:error.message}));if(role==="superadmin")this.reloadSaDashboard();if(["manager","admin","superadmin"].includes(role))this.reloadPurchasing().catch(()=>{});if(role!=="cashier") this.reloadAccounts().catch(error=>this.toast(error.message,"error"));});
  };
  logout=()=>{ this.authPost((window.KITA_AUTH&&window.KITA_AUTH.logoutUrl)||"/logout",{}).then(res=>{ if(window.KITA_AUTH) window.KITA_AUTH.csrfToken=res.csrf_token; this.newSale(); this.setState({role:null,authenticatedUser:null,mgrNotificationsLocal:[],notificationUnread:0,mgrNotifOpen:false,data:null,accountsLocal:null,purchaseData:null,purchaseReceivingId:null,purchaseRequestDetail:null,newReqCart:[],cart:[],discountManagerPin:"",approvalPin:"",approvalManagerId:"",newApprovalPin:"",confirmApprovalPin:"",currentApprovalPin:"",refundTxn:null,authStep:"login",screen:null,pendingRole:null,loginUsername:"",loginPassword:"",otpEmail:"",loginError:""}); }).catch(error=>this.toast(error.message,"error")); };
  toggleSidebar=()=>this.setState(s=>({sidebarOpen:!s.sidebarOpen}),()=>{
    if(this.state.sidebarOpen && typeof document!=="undefined")document.querySelector("#app-sidebar .drawer-close")?.focus();
  });
  closeSidebar=()=>this.setState({sidebarOpen:false},()=>document.getElementById("sidebar-toggle")?.focus());
  onShellKeyDown=e=>{
    if(!this.state.sidebarOpen || !this.state.compactNavigation)return;
    if(e.key==="Escape"){e.preventDefault();this.closeSidebar();}
    if(e.key==="Tab"){
      const nodes=[...document.querySelectorAll("#app-sidebar button:not([disabled])")].filter(el=>el.getClientRects().length);
      const first=nodes[0],last=nodes[nodes.length-1];
      if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}
      else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}
    }
  };
  goScreen=key=>()=>{ this.setState({screen:key,sidebarOpen:false}); if(["mgrRequest","mgrRequestView","mgrPurchaseHistory","mgrPO","mgrSupplier","admSuppliers","admPurchasedOrders","admForwarded","admDisapproved","admReceivingApprovals"].includes(key)&&!this.state.purchaseData&&!this.state.purchaseLoading)this.reloadPurchasing().catch(()=>{}); if(key==="supplierAccounts")this.loadSupplierAccounts(); if(key==="purchaseOrders"&&!this.state.purchaseData&&!this.state.purchaseLoading)this.reloadPurchasing().catch(()=>{}); if(key==="saDashboard")this.reloadSaDashboard(); if(key==="saBackup")this.reloadBackups(); if(["mgrCashiers","admManagers","saAdmins"].includes(key)&&!this.state.accountsLocal)this.reloadAccounts().catch(error=>this.toast(error.message,"error")); if(key==="shift") this.reloadCatalog().catch(()=>this.toast("Could not refresh shift transactions.","error")); };

  simScannerDisconnect=()=>{ this.setState({scannerConnected:false}); this.toast("Scanner disconnected â€” use manual entry.","warn"); };
  simPaymentTimeout=()=>{ if(this.state.paymentState==="processing"||this.state.paymentState==="pending"){ this.setState({paymentState:"timeout"}); this.toast("Payment timed out.","error"); } else this.toast("Start an e-wallet payment first.","warn"); };
  simDamagedBarcode=()=>{ this.setState({screen:"mgrRegistration"}); this.toast("Damaged barcode auto-regenerated for Nissin Cup Noodles Beef.","warn"); };
  simDuplicateRefund=()=>{ this.setState({screen:"refunds",refundLookup:"TXN-88213"}); setTimeout(()=>this.doRefundLookup(),50); };

  // ---- Checkout / cart ----
  setBarcodeInput=e=>this.setState({barcodeInput:e.target.value});
  onBarcodeKey=e=>{ if(e.key==="Enter") this.scanBarcode(); };
  scanBarcode=()=>{ const {data,barcodeInput,productsLocal}=this.state; if(!data||!barcodeInput.trim()) return;
    if(!this.state.scannerConnected){ this.toast("Scanner disconnected. Use Manual Product Lookup.","error"); this.setState({barcodeInput:""}); return; }
    const code=barcodeInput.trim();
    const normalizedCode=code.replace(/\D/g,"");
    const findProduct=catalog=>catalog.find(p=>String(p.barcode||"").trim()===code)||(/^\d+$/.test(code)?catalog.find(p=>String(p.id)===code):null);
    const productCatalog=productsLocal||data.PRODUCTS;
    const p=findProduct(productCatalog);
    if(p){ this.addProductToCart(p); this.setState({barcodeInput:""}); return; }
    this.reloadCatalog().then(()=>{
      const refreshed=this.state.productsLocal||[];
      const refreshedProduct=findProduct(refreshed);
      if(refreshedProduct){ this.addProductToCart(refreshedProduct); this.setState({barcodeInput:""}); return; }
      this.toast("Unrecognized barcode - sent to manual lookup.","error"); this.setState({barcodeInput:"",manualLookupOpen:true});
    }).catch(()=>{ this.toast("Could not refresh the product catalog.","error"); this.setState({barcodeInput:"",manualLookupOpen:true}); });
  };
  sellableStock=p=>Number(p?.sellableStock??0);
  refreshStockAvailability=async(productId,quantity)=>{
    const actor=this.state.authenticatedUser,requestId=(this.stockRequestId||0)+1;this.stockRequestId=requestId;
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),10000);
    try{
      const suffix=productId?'?'+new URLSearchParams({productId,quantity}):'';
      const response=await fetch('/api/inventory/availability'+suffix,{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store',signal:controller.signal});
      const body=await response.json();this.handleSessionResponse(response,body);
      if(!response.ok)throw new Error(Object.values(body.errors||{})[0]?.[0]||body.message||'Cannot verify sellable stock. Try again.');
      if(actor!==this.state.authenticatedUser)throw new Error('Session changed. Select the product again.');
      if(requestId>=(this.stockAppliedId||0)){
        this.stockAppliedId=requestId;
        const fresh=new Map(body.products.map(p=>[String(p.id),p]));
        const merge=items=>(items||[]).map(p=>fresh.has(String(p.id))?{...p,...fresh.get(String(p.id))}:p);
        this.setState(s=>({productsLocal:merge(s.productsLocal||s.data?.PRODUCTS),data:s.data?{...s.data,BUSINESS_DATE:body.businessDate,PRODUCTS:merge(s.data.PRODUCTS)}:s.data,stockAvailabilityError:'',stockCheckedAt:body.checkedAt,...(Array.isArray(body.activePromotions)?{checkoutPromotions:body.activePromotions,cart:s.cart.map(l=>({...l,promo:body.activePromotions.find(p=>(p.productIds||[]).includes(l.productId)||(p.type==='Category-Wide'&&p.category===(fresh.get(String(l.productId))||(s.productsLocal||[]).find(x=>x.id===l.productId))?.category))||null}))}:{})}));
      }
      return body.products;
    }catch(error){throw new Error(error.name==='AbortError'?'Stock check timed out. Try again before selling.':error.message);}finally{clearTimeout(timer);}
  };
  queueStockSelection=work=>{
    if(this.checkoutInFlight||this.stockCheckoutChecking||this.state.paymentState==='processing')return Promise.resolve();
    const generation=this.cartGeneration||0,actor=this.state.authenticatedUser;
    this.stockSelections=(this.stockSelections||0)+1;
    const queued=(this.stockSelectionQueue||Promise.resolve()).then(async()=>{
      if(generation!==(this.cartGeneration||0)||actor!==this.state.authenticatedUser)return;
      await work(()=>generation===(this.cartGeneration||0)&&actor===this.state.authenticatedUser);
    }).catch(error=>this.toast(error.message,'error')).finally(()=>{this.stockSelections--;});
    this.stockSelectionQueue=queued;return queued;
  };
  promoStatus=(p)=>{ const today=this.state.data?.BUSINESS_DATE||new Date().toISOString().slice(0,10); if(today<p.startDate) return "Scheduled"; if(today>p.endDate) return "Expired"; return "Active"; };
  activePromo=(productId)=>{ const list=this.state.checkoutPromotions??this.state.promotionsLocal; if(!list) return null; const product=(this.state.productsLocal||this.state.data?.PRODUCTS||[]).find(p=>p.id===productId);return list.find(pr=>((pr.productIds||[]).includes(productId)||(pr.type==='Category-Wide'&&pr.category===product?.category)) && this.promoStatus(pr)==="Active") || null; };
  addProductToCart=(p,manual)=>this.queueStockSelection(async current=>{
    const quantity=(this.state.cart.find(l=>l.productId===p.id)?.qty||0)+1;
    const fresh=(await this.refreshStockAvailability(p.id,quantity))[0];
    if(current())this.addVerifiedProductToCart(fresh,manual);
  });
  addVerifiedProductToCart=(p,manual)=>{
    if(p.status==="Quarantine"){ this.toast(`"${p.name}" is quarantined â€” blocked from sale.`,"error"); return; }
    if(p.status!=="Active"||this.sellableStock(p)<=0){ this.toast(`"${p.name}" has no sellable stock. ${(p.exclusionReasons||[]).join('; ')}`,"error"); return; }
    const existing=this.state.cart.find(l=>l.productId===p.id);
    if(existing && existing.qty>=this.sellableStock(p)){ this.toast(`Only ${this.sellableStock(p)} units of ${p.name} are available.`,"error"); return; }
    if(existing){ this.setState(s=>({cart:s.cart.map(l=>l.productId===p.id?{...l,qty:l.qty+1,pulse:true}:l)})); this.toast(`Added 1 more "${p.name}".`);
      setTimeout(()=>this.setState(s=>({cart:s.cart.map(l=>l.productId===p.id?{...l,pulse:false}:l)})),900); return; }
    const promo=this.activePromo(p.id);
    this.setState(s=>({cart:[...s.cart,{lineId:Date.now()+Math.random(),productId:p.id,name:this.productLabel(p),qty:1,unitPrice:p.price,originalPrice:p.price,vatClass:p.vatClass,manual:!!manual,
      overridden:false,overrideReason:"",overrideApprover:"",promo,pulse:false}]}));
    this.toast(`Added "${p.name}"${manual?" (Manual Entry)":""}${promo?" â€” promo "+promo.name+" applied":""}.`);
  };
  stepQty=(lineId,delta)=>()=>this.queueStockSelection(async current=>{
    const line=this.state.cart.find(l=>l.lineId===lineId);
    if(!line) return;
    const product=delta>0?(await this.refreshStockAvailability(line.productId,line.qty+delta))[0]:(this.state.productsLocal||this.state.data?.PRODUCTS||[]).find(p=>p.id===line.productId);
    if(!current())return;
    if(delta>0 && (!product || line.qty+delta>this.sellableStock(product))){ this.toast(`Only ${this.sellableStock(product)} units of ${line.name} are available.`,"error"); return; }
    this.setState(s=>({cart:s.cart.map(l=>l.lineId!==lineId?l:{...l,qty:Math.max(1,l.qty+delta)})}));
  });
  removeLine=lineId=>()=>this.setState(s=>({cart:s.cart.filter(l=>l.lineId!==lineId)}));
  openManualLookup=()=>this.setState({manualLookupOpen:true,manualSearch:"",manualCategory:"",manualSubcategoryId:""});
  closeManualLookup=()=>this.setState({manualLookupOpen:false});
  setManualSearch=e=>this.setState({manualSearch:e.target.value});
  addManualProduct=p=>()=>{ this.addProductToCart(p,true); this.setState({manualLookupOpen:false}); };

  openSeniorPwd=()=>this.setState({seniorPwdOpen:true});
  closeSeniorPwd=()=>this.setState({seniorPwdOpen:false});
  setSeniorField=field=>e=>this.setState(s=>({seniorForm:{...s.seniorForm,[field]:e.target.value}}));
  applySeniorPwd=()=>{ const {name,idNumber}=this.state.seniorForm; if(!name||!idNumber){ this.toast("Name and ID number are required.","error"); return; }
    this.setState({seniorApplied:true,seniorInfo:{name,idNumber},seniorPwdOpen:false}); this.toast("Senior/PWD 20% discount + VAT exemption applied."); };
  removeSenior=()=>this.setState({seniorApplied:false,seniorInfo:null});
  toggleEmployeeDiscount=()=>this.setState(s=>({employeeDiscount:!s.employeeDiscount}));

  openOverride=lineId=>()=>this.setState({overrideLine:lineId,overrideReason:"",overridePin:""});
  closeOverride=()=>this.setState({overrideLine:null});
  setOverrideReason=e=>this.setState({overrideReason:e.target.value});
  setOverridePin=e=>this.setState({overridePin:e.target.value});
  confirmOverride=()=>{ if(this.overrideDeclining)return;const {overrideLine,overrideReason,overridePin}=this.state;
    if(!overrideReason.trim()){ this.toast("A reason is required for price overrides.","error"); return; }
    this.setState(s=>({cart:s.cart.map(l=>l.lineId===overrideLine?{...l,overridden:true,overrideReason,overrideApprover:"Pending manager approval at checkout",unitPrice:Math.round(l.originalPrice*0.85*100)/100}:l),overrideLine:null}));
    this.toast("Price override prepared. Manager approval is required before payment.");
  };

  setPaymentMethod=m=>()=>this.setState({paymentMethod:m,paymentState:"idle"});
  setEwalletProvider=e=>this.setState({ewalletProvider:e.target.value});
  setTendered=e=>this.setState({tendered:e.target.value});
  cashTendered=total=>{
    if(!this.validAmount(this.state.tendered)||Number(this.state.tendered)>99999999.99){this.toast("Enter a valid tendered amount with at most two decimal places.","error");return null;}
    const tendered=Number(this.state.tendered);
    if(Math.round(tendered*100)<Math.round(total*100)){this.toast("Tendered amount is less than the total due.","error");return null;}
    return tendered;
  };
  computeTotals=()=>{
    const {cart,seniorApplied,employeeDiscount}=this.state;
    let subtotal=0,discount=0,vatExempt=0;
    cart.forEach(l=>{
      const base=Math.round(Number(l.originalPrice??l.unitPrice)*100)*l.qty;
      const unit=Math.round(Number(l.unitPrice)*100);
      const rate=employeeDiscount?10:seniorApplied?20:Number(l.promo?.discountPct||0);
      const net=Math.round(unit*l.qty*(100-rate)/100);
      subtotal+=base;discount+=base-net;
      vatExempt+=net;
    });
    return {subtotal:subtotal/100,vatable:0,vatExempt:vatExempt/100,vat:0,discount:discount/100,grandTotal:(subtotal-discount)/100};
  };
  startPayment=async()=>{
    if(this.overrideDeclining){this.toast('Wait for the manager decision to finish.','error');return;}
    if(this.stockSelections||this.stockCheckoutChecking){this.toast('Wait for the stock check to finish.','error');return;}
    if(this.checkoutInFlight || this.state.paymentState==="processing") return;
    if(this.state.cart.length===0){ this.toast("Cart is empty.","error"); return; }
    const generation=this.cartGeneration||0,actor=this.state.authenticatedUser;
    this.stockCheckoutChecking=true;
    try{await this.refreshStockAvailability();}catch(error){this.toast(error.message,'error');return;}finally{this.stockCheckoutChecking=false;}
    if(generation!==(this.cartGeneration||0)||actor!==this.state.authenticatedUser||!this.state.cart.length)return;
    const catalog=this.state.productsLocal||this.state.data?.PRODUCTS||[];
    for(const line of this.state.cart){
      const product=catalog.find(p=>p.id===line.productId);
      if(!product || product.status!=="Active" || line.qty>this.sellableStock(product)){
        this.toast(product?`Only ${this.sellableStock(product)} sellable units of ${line.name} are available. Please update the cart.`:"A cart product is unavailable.","error");
        return;
      }
    }
    const total=Number(this.computeTotals().grandTotal.toFixed(2));
    if(this.discountNeedsApproval() && (!this.state.discountManagerId || !/^\d{4}$/.test(this.state.discountManagerPin||""))){
      this.toast("Discounts require a manager and their 4-digit PIN before payment.","error"); return;
    }
    if(this.state.paymentMethod==="cash"){
      if(this.cashTendered(total)===null)return;
      this.finalizeTransaction(); return;
    }
    const uuid=this.checkoutUuid||(this.checkoutUuid="TXN-"+crypto.randomUUID().replace(/-/g,"").slice(0,26));
    this.checkoutInFlight=true;
    this.setState({paymentState:"processing"});
    this.authPost((window.KITA_AUTH&&window.KITA_AUTH.paymongoUrl)||"/api/payments/paymongo/checkout",{
      uuid,...this.discountApprovalPayload(),
      cashier:this.currentUser().name,
      total,
      provider:this.state.ewalletProvider,
      items:[{name:"KITA purchase",qty:1,amount:total}],
      stockItems:this.state.cart.map(l=>({productId:l.productId,qty:l.qty,overridden:!!l.overridden})),
    }).then(response=>{
      if(!response.checkoutUrl) throw new Error("PayMongo did not return a checkout URL.");
      window.location.assign(response.checkoutUrl);
    }).catch(error=>{
      if(error.body?.retryable)this.checkoutUuid=null;
      this.setState({paymentState:"idle",discountManagerPin:""});
      this.toast(error.message,"error");
    }).finally(()=>{this.checkoutInFlight=false;});
  };
  tickReservation=()=>{ if(this.state.paymentState!=="pending") return; if(this.state.reservationSeconds<=0){ this.setState({paymentState:"timeout"}); this.toast("Reservation expired â€” stock released.","error"); return; }
    setTimeout(()=>{ this.setState(s=>({reservationSeconds:s.reservationSeconds-1})); this.tickReservation(); },1000); };
  retryPayment=()=>{ this.setState({paymentState:"idle"}); setTimeout(this.startPayment,50); };
  switchToCash=()=>this.setState({paymentMethod:"cash",paymentState:"idle"});
  cancelPayment=()=>this.setState({paymentState:"cancelled"});
  toggleCommit=()=>this.setState(s=>({commitOpen:!s.commitOpen}));
  finalizeTransaction=()=>{
    if(this.checkoutInFlight) return;
    const totals=this.computeTotals();
    const tendered=this.cashTendered(totals.grandTotal);
    if(tendered===null)return;
    this.checkoutInFlight=true;
    this.setState({paymentState:"processing"});
    const uuid=this.checkoutUuid||(this.checkoutUuid="TXN-"+crypto.randomUUID().replace(/-/g,"").slice(0,26));
    const refCodes={"QR Ph":"QR-"};
    const result={ uuid,date:new Date().toISOString().slice(0,10),cashier:this.currentUser().name,cart:[...this.state.cart],totals,
      paymentMode: this.state.paymentMethod==="cash"?"Cash":this.state.ewalletProvider,
      tendered, paid:totals.grandTotal, change:Math.max(0,tendered-totals.grandTotal),
      referenceNo: this.state.paymentMethod==="cash"?null:(refCodes[this.state.ewalletProvider]||"RF-")+Math.floor(70000000+Math.random()*9999999),
      status:"Unused", seniorApplied:this.state.seniorApplied, employeeDiscount:this.state.employeeDiscount };

    this.authPost((window.KITA_AUTH&&window.KITA_AUTH.transactionUrl)||"/api/transactions",{
      ...this.discountApprovalPayload(),
      items:result.cart.map(l=>({productId:l.productId,qty:l.qty,overridden:!!l.overridden})),
      uuid:result.uuid,status:result.status,cashier:result.cashier,total:Number(result.totals.grandTotal.toFixed(2)),
      paymentMode:result.paymentMode,tendered:result.tendered,paid:result.paid,change:result.change,referenceNo:result.referenceNo,
    }).then(response=>{
      const saved=response.transaction;
      Object.assign(result,{date:saved.date,cashier:saved.cashier,total:saved.total,status:saved.status,paid:saved.paid,tendered:saved.tendered,change:saved.change,referenceNo:saved.referenceNo});
      result.totals={...result.totals,subtotal:saved.subtotal,discount:saved.discountAmount,grandTotal:saved.total};
      this.setState(s=>({transactionResult:result,paymentState:"paid",productsLocal:(s.productsLocal||s.data?.PRODUCTS||[]).map(p=>{
        const sold=result.cart.find(l=>l.productId===p.id);
        return sold?{...p,stock:Math.max(0,p.stock-sold.qty),sellableStock:Math.max(0,this.sellableStock(p)-sold.qty)}:p;
      })}));
      this.toast("Sale saved and inventory updated.");
      this.reloadCatalog().catch(()=>this.toast("Sale saved. Refresh the page to reload inventory.","warn"));
    }).catch(error=>{
      this.setState({paymentState:"idle",transactionResult:null});
      this.toast(error.message,"error");
      this.reloadCatalog().catch(()=>{});
    }).finally(()=>{ this.checkoutInFlight=false; this.setState({discountManagerPin:""}); });
  };
  newSale=()=>{this.cartGeneration=(this.cartGeneration||0)+1;this.checkoutUuid=null;this.setState({cart:[],seniorApplied:false,seniorInfo:null,employeeDiscount:false,paymentState:"idle",tendered:"",transactionResult:null,lateWebhook:false,discountManagerId:"",discountManagerPin:""});};
  discountNeedsApproval=()=>this.computeTotals().discount>0 || this.state.cart.some(l=>l.overridden);
  discountApprovalPayload=()=>({discountType:this.state.employeeDiscount?"employee":this.state.seniorApplied?"senior":"none",discount_manager_id:this.state.discountManagerId||null,discount_manager_pin:this.state.discountManagerPin||null});
  buildDiscountApproval(){
    const h=React.createElement;
    return h("div",{key:"discount-approval",style:{marginTop:12}},[
      h("p",{key:"note",style:{fontSize:12}},"Manager approval is required before payment. The manager must personally enter their PIN."),
      h("select",{key:"manager","aria-label":"Discount approving manager",value:this.state.discountManagerId||"",onChange:e=>this.setState({discountManagerId:e.target.value,discountManagerPin:""}),style:{width:"100%",padding:10,marginBottom:8}},[
        h("option",{key:"empty",value:""},"Select approving manager"),
        ...(this.state.data.USERS?.manager||[]).filter(m=>m.status==="Active").map(m=>h("option",{key:m.id,value:m.id},m.name)),
      ]),
      h("input",{key:"pin",type:"password",inputMode:"numeric",maxLength:4,autoComplete:"off","aria-label":"Discount manager PIN",placeholder:"Manager 4-digit PIN",value:this.state.discountManagerPin||"",onChange:e=>this.setState({discountManagerPin:e.target.value}),style:{width:"100%",padding:10,marginBottom:8}}),
      this.state.cart.some(l=>l.overridden)?h('div',{key:'decline'},[
        h('input',{'aria-label':'Override decline reason',placeholder:'Reason for declining override',value:this.state.overrideDeclineReason||'',onChange:e=>this.setState({overrideDeclineReason:e.target.value})}),
        h('button',{type:'button',disabled:!!this.state.overrideDeclining,onClick:this.declinePriceOverride},'Decline price override')
      ]):null,

    ]);
  }

  declinePriceOverride=async()=>{
    if(this.overrideDeclining||this.checkoutInFlight||this.state.paymentState==='processing')return;
    const items=this.state.cart.filter(l=>l.overridden).map(l=>({productId:l.productId,qty:l.qty}));
    const generation=this.cartGeneration||0,actor=this.state.authenticatedUser;
    if(!items.length||!this.state.overrideDeclineReason?.trim()){this.toast('Enter a reason for declining the override.','error');return;}
    this.overrideDeclining=true;this.setState({overrideDeclining:true});
    try{
      const result=await this.authPost('/api/price-overrides/decline',{requestKey:crypto.randomUUID(),manager_id:this.state.discountManagerId,manager_pin:this.state.discountManagerPin,reason:this.state.overrideDeclineReason,items});
      if(generation===(this.cartGeneration||0)&&actor===this.state.authenticatedUser){this.setState(s=>({cart:s.cart.map(l=>l.overridden?{...l,unitPrice:l.originalPrice,overridden:false,overrideReason:'',overrideApprover:''}:l),discountManagerPin:'',overrideDeclineReason:''}));this.toast(result.message);}
    }catch(e){this.toast(e.message,'error');}finally{this.overrideDeclining=false;this.setState({overrideDeclining:false});}
  };

  chooseAdjustmentEvidence=async e=>{
    const file=e.target.files?.[0];
    this.setState({adjustmentEvidence:null});
    if(!file)return;
    if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>2*1024*1024){this.toast('Choose a PNG, JPEG or WebP image up to 2 MB.','error');e.target.value='';return;}
    this.setState({evidenceReading:true});
    try{const value=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result);reader.onerror=()=>reject(new Error('Could not read image.'));reader.readAsDataURL(file);});this.setState({adjustmentEvidence:value});}
    catch(error){this.toast(error.message,'error');}finally{this.setState({evidenceReading:false});}
  };
  evidenceLink=id=>React.createElement('a',{href:'/api/inventory/adjustments/'+encodeURIComponent(id)+'/evidence',target:'_blank',rel:'noopener'},'View photo');

  saveExpiryWindow=async()=>{
    const days=Number(this.state.expiryAlertDays??this.state.data?.EXPIRY_ALERT_DAYS??7);
    try{const r=await this.authPost('/api/inventory/expiry-settings',{days},'PATCH');await this.reloadCatalog();this.setState({expiryDays:days});this.toast(r.message);}catch(e){this.toast(e.message,'error');}
  };
  buildExpirySettings(){
    const h=React.createElement;
    return h('div',{className:'purchase-filters'},[h('label',null,['Notification window (days)',h('input',{type:'number',min:0,max:365,value:this.state.expiryAlertDays??this.state.data?.EXPIRY_ALERT_DAYS??7,onChange:e=>this.setState({expiryAlertDays:e.target.value})})]),btn('Save alert window',this.saveExpiryWindow)]);
  }
  loadRecallTrace=async(batchId)=>{
    this.setState({recallTraceError:'',recallTrace:null});
    try{
      const query=batchId?'batchId='+batchId:'search='+encodeURIComponent(this.state.recallSearch||'');
      const r=await fetch('/api/inventory/recall-trace?'+query,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      const data=await r.json();this.handleSessionResponse(r,data);if(!r.ok)throw new Error(data.message||'Trace could not be loaded.');this.setState({recallTrace:data});
    }catch(e){this.setState({recallTraceError:e.message});}
  };
  buildRecallTrace(){
    const h=React.createElement,t=this.state.recallTrace;
    return h('div',{className:'sa-panel'},[h('h3',null,'Trace affected inventory and sales'),
      h('input',{'aria-label':'Batch or lot number',placeholder:'Exact batch or lot number',value:this.state.recallSearch||'',onChange:e=>this.setState({recallSearch:e.target.value})}),btn('Find batch',()=>this.loadRecallTrace()),
      this.state.workflowForm?.batchId?btn('Trace selected batch',()=>this.loadRecallTrace(this.state.workflowForm.batchId)):null,
      this.state.recallTraceError?h('p',{role:'alert'},this.state.recallTraceError):null,
      t?this.recordTable('recall-trace-batches',['Product','Batch','Lot','On hand','Sellable','Status','Action'],t.batches.map(b=>tr([td(b.name),td(b.batchNumber||b.id),td(b.lotNumber||'-'),td(b.stockOnHand),td(b.sellableQuantity),td(b.eligibility),td(btn(b.recalled?'Review recall':'Select for recall',()=>this.setState({workflowForm:{batchId:b.id,recallAction:b.recalled?'Release recall':'Recall',reason:'Recall review'},workflowKey:null})))],b.id))):null,
      t?this.recordTable('recall-trace-sales',['Transaction','Date','Batch','Allocated','Returned','Outstanding','Status'],t.sales.map((s,i)=>tr([td(s.uuid),td(s.date),td(s.batchId),td(s.quantity),td(s.returnedQuantity),td(Math.max(0,s.quantity-s.returnedQuantity)),td(s.status)],i))):null,
      t&&!t.sales.length?h('p',null,'No recorded sales or payment reservations for these batches.'):null,
      null]);
  }

  saveSupplierContact=async()=>{
    if(this.contactSaving)return;
    const f=this.state.supplierContactForm;if(!f)return;
    this.contactSaving=true;
    try{const r=await this.authPost('/api/receipts/'+encodeURIComponent(f.receiptId)+'/supplier-contacts',f);this.setState({supplierContactForm:null});await this.reloadPurchasing();this.toast(r.message);}catch(e){this.toast(e.message,'error');}finally{this.contactSaving=false;}
  };
  buildSupplierContacts(orders){
    const h=React.createElement,f=this.state.supplierContactForm;
    const receipts=orders.flatMap(o=>(o.receipts||[]).filter(r=>r.discrepancy).map(r=>({...r,poId:o.id})));
    const field=(key,label,type='text')=>h('label',{className:'field-group'},[label,h('input',{type,value:f[key]||'',max:type==='date'?this.state.data?.BUSINESS_DATE:undefined,onChange:e=>this.setState({supplierContactForm:{...f,[key]:e.target.value,requestKey:crypto.randomUUID()}})})]);
    return h('div',{className:'sa-panel'},[h('h3',null,'Supplier follow-up for delivery discrepancies'),
      this.recordTable('supplier-followups',['PO','Receipt','Discrepancy','Contact history','Action'],receipts.map(r=>tr([td(r.poId),td(r.deliveryReference||r.id),td(r.discrepancyType),td((r.contacts||[]).map(c=>h('p',{key:c.id},`${c.contactDate} | ${c.channel} | ${c.person}: ${c.notes} / Outcome: ${c.outcome} (recorded by ${c.actor})`))),td(btn('Record contact',()=>this.setState({supplierContactForm:{receiptId:r.id,requestKey:crypto.randomUUID(),contactDate:this.state.data.BUSINESS_DATE,channel:'Phone',person:'',notes:'',outcome:''}})))],r.id))),
      f?h('div',{className:'purchase-filters'},[field('contactDate','Contact date','date'),field('person','Supplier contact person'),h('label',null,['Channel',h('select',{value:f.channel,onChange:e=>this.setState({supplierContactForm:{...f,channel:e.target.value,requestKey:crypto.randomUUID()}})},['Phone','Email','In person','Message'].map(v=>h('option',{key:v,value:v},v)))]),field('notes','Discussion / missing items'),field('outcome','Agreed action / outcome'),btn('Save contact',this.saveSupplierContact,'primary'),btn('Cancel',()=>this.setState({supplierContactForm:null}))]):null]);
  }

  // ---- Refunds / Exchanges / Voids ----
  setRefundLookup=e=>this.setState({refundLookup:e.target.value});
  doRefundLookup=()=>{ const {data,refundLookup}=this.state; if(!data) return;
    const t=data.TRANSACTIONS.find(tt=>tt.uuid.toLowerCase()===refundLookup.trim().toLowerCase());
    if(!t){ this.setState({refundError:"No transaction found matching that number.",refundTxn:null}); return; }
    if(!["Unused","Paid","Partially Refunded"].includes(t.status)){ this.setState({refundError:`Cannot refund â€” this transaction is already ${t.status}${t.refundDate?" ("+t.refundDate+")":""}.`,refundTxn:null}); return; }
    this.setState({refundTxn:t,refundAction:"",refundQuantities:{},refundRestock:true,providerRefundId:"",returnRequestKey:null,approvalManagerId:"",refundError:"",refundClass:"",refundSelectedLines:[],approvalPin:"",approvalResult:"",exchangeTransaction:null,exchangeReceiptOpen:false,exchangeReplacement:"",exchangeTendered:""});
  };
  setRefundClass=cls=>()=>this.setState({refundClass:cls});
  processRefund=()=>this.submitManagerAction("refund");
  chooseRefundAction=action=>{ if(this.approvalBusy) return; this.setState({refundAction:action,returnRequestKey:null,refundQuantities:{},refundClass:"",approvalManagerId:"",approvalPin:"",exchangeReplacement:"",exchangeTendered:"",refundRestock:true,refundError:""}); };
  submitManagerAction=action=>{
    if(!action || action!==this.state.refundAction) return;
    if(this.approvalBusy || !this.state.refundTxn) return;
    if(action==="refund"&&Object.values(this.state.refundQuantities||{}).some(qty=>String(qty).trim()!==""&&(!/^\d+$/.test(String(qty))||!Number.isSafeInteger(Number(qty))))){this.setState({refundError:"Refund quantities must be whole numbers and cannot be negative."});return;}
    const returnItems=action==="refund"?Object.entries(this.state.refundQuantities||{}).filter(([id,qty])=>Number(qty)>0).map(([id,qty])=>({productId:Number(id),qty:Number(qty)})):null;
    if(action==="refund"&&(!returnItems.length||returnItems.some(l=>!Number.isInteger(l.qty)))){this.setState({refundError:"Enter a positive whole refund quantity for at least one item."});return;}
    if(returnItems?.some(item=>{const line=(this.state.refundTxn.lines||[]).find(l=>Number(l.productId)===item.productId);return !line||item.qty>line.qty-line.refundedQty;})){this.setState({refundError:"Refund quantity exceeds the remaining purchased quantity."});return;}
    const requestKey=this.state.returnRequestKey||crypto.randomUUID();this.setState({returnRequestKey:requestKey});
    this.approvalBusy=true;
    this.authPost(`/api/transactions/${encodeURIComponent(this.state.refundTxn.uuid)}`,{
      action,request_key:requestKey,...(returnItems?{items:returnItems}:{}),restock:this.state.refundRestock!==false,provider_refund_id:this.state.providerRefundId||null,manager_id:this.state.approvalManagerId||"",manager_pin:this.state.approvalPin||"",
      ...(action==="exchange"?{exchange_tendered:this.state.exchangeTendered||0}:{}),reason:this.state.refundClass||"",replacement_product_id:action==="exchange"?this.state.exchangeReplacement:null,
    },"PATCH").then(res=>{
      this.setState(s=>({refundTxn:{...s.refundTxn,status:res.status,refundDate:res.refundDate,lines:res.lines,refundedAmount:res.refundedAmount},refundQuantities:{},returnRequestKey:null,approvalPin:"",refundError:"",approvalResult:res.message,exchangeTransaction:res.replacementTransaction||null,exchangeReceiptOpen:false}));
      this.toast(res.message);
      this.reloadCatalog().catch(()=>{});
    }).catch(error=>this.setState({refundError:error.message,approvalPin:""})).finally(()=>{this.approvalBusy=false;});
  };
  setExchangeReplacement=e=>this.setState({exchangeReplacement:e.target.value,exchangeTendered:"",returnRequestKey:null});
  saveManagerPin=()=>{
    this.authPost("/manager/approval-pin",{pin:this.state.newApprovalPin||"",pin_confirmation:this.state.confirmApprovalPin||"",current_pin:this.state.currentApprovalPin||null})
      .then(res=>{this.setState({newApprovalPin:"",confirmApprovalPin:"",currentApprovalPin:""});this.toast(res.message);})
      .catch(error=>this.toast(error.message,"error"));
  };
  buildManagerPin(){
    return card([
      React.createElement("h3",{key:"title"},"Manager approval PIN"),
      React.createElement("p",{key:"help"},"Set your 4-digit PIN for approving discounts, refunds, voids, and exchanges. Enter your current PIN when changing it."),
      React.createElement("div",{className:"control-grid"},[["currentApprovalPin","Current PIN (leave blank for first setup)"],["newApprovalPin","New 4-digit PIN"],["confirmApprovalPin","Confirm new PIN"]].map(([field,label])=>React.createElement("label",{key:field,className:"field-group"},[React.createElement("span",{className:"form-label"},label),React.createElement("input",{type:"password",inputMode:"numeric",maxLength:4,"aria-label":label,value:this.state[field]||"",onChange:e=>this.setState({[field]:e.target.value.replace(/\D/g,"")}),style:{padding:10,width:"100%"}})]))),
      btn("Save approval PIN",this.saveManagerPin,"primary"),
    ],{marginBottom:16});
  }

  // ---- Manager: Request Purchase (3 tabs) ----
  removeReqLine=i=>()=>this.setState(s=>({newReqCart:s.newReqCart.filter((_,idx)=>idx!==i)}));
  setApprovalNote=(reqId)=>e=>this.setState(s=>({approvalNoteDraft:{...s.approvalNoteDraft,[reqId]:e.target.value}}));
  reloadNotifications=async(append=false)=>{
    if(!this.state.role||this.notificationsBusy)return;
    const actor=this.state.authenticatedUser;
    this.notificationsBusy=true;this.setState({notificationLoading:true,notificationError:''});
    try{
      const response=await fetch('/api/notifications'+(append&&this.state.notificationNext?'?before='+this.state.notificationNext:''),{headers:{Accept:'application/json'},credentials:'same-origin'});
      const body=await response.json();this.handleSessionResponse(response,body);
      if(!response.ok)throw new Error(body.message||'Unable to load notifications.');
      if(this.state.authenticatedUser!==actor||!this.state.role)return;
      this.setState(s=>({mgrNotificationsLocal:append?[...(s.mgrNotificationsLocal||[]),...body.notifications]:body.notifications,notificationUnread:body.unread_count,notificationNext:body.next_before}));
    }catch(error){if(this.state.authenticatedUser===actor)this.setState({notificationError:error.message});}
    finally{this.notificationsBusy=false;this.setState({notificationLoading:false});}
  };
  pushMgrNotification=()=>this.reloadNotifications();
  toggleMgrNotif=()=>this.setState(s=>({mgrNotifOpen:!s.mgrNotifOpen}),()=>{if(this.state.mgrNotifOpen)this.reloadNotifications();});
  markAllNotifications=async()=>{
    try{await this.authPost('/api/notifications/read-all',{},'PATCH');await this.reloadNotifications();}
    catch(error){this.setState({notificationError:error.message});}
  };
  loadMoreNotifications=()=>this.reloadNotifications(true);
  retryNotifications=()=>this.reloadNotifications();
  closeNotifications=()=>this.setState({mgrNotifOpen:false});
  openMgrNotification=n=>async()=>{
    try{
      await this.authPost(`/api/notifications/${n.id}/read`,{},'PATCH');
      this.setState({mgrNotifOpen:false});
      if(n.screen){
        this.goScreen(n.screen)();
        if(['mgrRequestView','admPurchasedOrders'].includes(n.screen))this.setState({purchaseRequestDetail:n.recordId});
        if(n.screen==='mgrInventory'){const expiry=['Expired stock','Stock expiring soon'].includes(n.type);this.setState({invTab:expiry?'expiry':'adjustments'});if(expiry)await this.loadInventoryWorkflows();}
        if(['mgrInventory','admInventoryApprovals','saAudit'].includes(n.screen))await this.reloadCatalog();
      }
      await this.reloadNotifications();
    }catch(error){this.setState({notificationError:error.message});}
  };

  // ---- Manager/Admin generic ----
  setInvTab=tab=>()=>{this.setState({invTab:tab});if(['writeoffs','recall','reconciliation','expiry'].includes(tab))this.loadInventoryWorkflows();};
  openSupplierDetail=id=>()=>this.setState({supplierDetailId:id});
  closeSupplierDetail=()=>this.setState({supplierDetailId:null});
  approveAdjustment=id=>()=>{ this.authPost(`/api/inventory/adjustments/${encodeURIComponent(id)}`,{status:"Approved"},"PATCH").then(()=>{ this.setState(s=>({adjustmentsLocal:s.adjustmentsLocal.map(a=>a.id===id?{...a,status:"Approved"}:a)})); this.reloadCatalog().catch(()=>{}); this.pushMgrNotification("Adjustment Approved",`Inventory Adjustment ${id} was Approved by Admin`,"Standard",null); }).catch(error=>this.toast("Adjustment approval could not be saved: "+error.message,"error")); };
  rejectAdjustment=id=>()=>{ this.authPost(`/api/inventory/adjustments/${encodeURIComponent(id)}`,{status:"Rejected"},"PATCH").then(()=>{ this.setState(s=>({adjustmentsLocal:s.adjustmentsLocal.map(a=>a.id===id?{...a,status:"Rejected"}:a)})); this.pushMgrNotification("Adjustment Rejected",`Inventory Adjustment ${id} was Rejected by Admin`,"Critical",null); }).catch(error=>this.toast("Adjustment rejection could not be saved: "+error.message,"error")); };
  setNewAdjField=field=>e=>this.setState(s=>({newAdjForm:{...s.newAdjForm,[field]:e.target.value}}));
  submitAdjustment=()=>{
    if(this.state.evidenceReading){this.toast("Wait for the photo to finish loading.","error");return;}
    const f=this.state.newAdjForm,q=Number(f.qtyChange);
    if(!f.productId||!Number.isInteger(q)||q<=0||!f.comment.trim()||!f.reason.trim()){this.toast("Select an item, positive whole quantity, reason, and comment.","error");return;}
    this.authPost("/api/inventory/adjustments",{productId:Number(f.productId),qtyChange:-q,reason:f.reason,comment:f.comment,evidence:this.state.adjustmentEvidence||null})
      .then(res=>{this.setState({adjustmentEvidence:null,newAdjForm:{productId:"",qtyChange:"",reason:"Shrinkage",comment:""}});this.toast(res.message);return this.reloadCatalog();}).catch(error=>this.toast(error.message,"error"));
  };
  openCashierModal=(mode,cashier)=>this.openAcctModal(mode==="add"?"create":"edit",cashier);
  closeCashierModal=this.closeAcctModal;

  setPriceEditTarget=(productId,field)=>()=>{ const p=this.state.productsLocal.find(pp=>pp.id===productId); this.setState({priceEditOpen:true,priceEditTarget:{productId,field},priceEditValue:String(p[field]),priceEditReason:""}); };
  closePriceEdit=()=>this.setState({priceEditOpen:false,priceEditTarget:null});
  setPriceEditValue=e=>this.setState({priceEditValue:e.target.value});
  setPriceEditReason=e=>this.setState({priceEditReason:e.target.value});
  savePriceEdit=()=>{
    const {priceEditTarget:t,priceEditValue,priceEditReason}=this.state;
    if(!priceEditReason.trim()||!this.validAmount(priceEditValue)){this.toast("Enter a valid amount and reason.","error");return;}
    const p=this.state.productsLocal.find(p=>p.id===t.productId);
    this.authPost(`/api/products/${p.id}`,{...p,[t.field]:Number(priceEditValue),reason:priceEditReason},"PATCH")
      .then(()=>{this.setState({priceEditOpen:false});this.toast("Price saved.");return this.reloadCatalog();}).catch(error=>this.toast(error.message,"error"));
  };
  openAddDamage=()=>this.setState({addDamageOpen:true,addDamageForm:{productId:"",qty:1},damagePickerOpen:false,damagePickerSearch:""});
  closeAddDamage=()=>this.setState({addDamageOpen:false});
  setAddDamageField=field=>e=>this.setState(s=>({addDamageForm:{...s.addDamageForm,[field]:e.target.value}}));
  toggleDamagePicker=()=>this.setState(s=>({damagePickerOpen:!s.damagePickerOpen,damagePickerSearch:""}));
  setDamagePickerSearch=e=>this.setState({damagePickerSearch:e.target.value});
  selectDamageProduct=id=>()=>this.setState(s=>({addDamageForm:{...s.addDamageForm,productId:id},damagePickerOpen:false}));
  stepDamageQty=delta=>()=>this.setState(s=>({addDamageForm:{...s.addDamageForm,qty:Math.max(1,(parseInt(s.addDamageForm.qty,10)||1)+delta)}}));
  confirmAddDamage=()=>{
    const {productId,qty}=this.state.addDamageForm,q=Number(qty);
    if(!productId||!Number.isInteger(q)||q<=0){this.toast("Select a product and enter a positive whole quantity.","error");return;}
    this.authPost("/api/inventory/adjustments",{productId:Number(productId),qtyChange:-q,reason:"Damaged",comment:"Damaged item reported from inventory",photo:false})
      .then(()=>{this.setState({addDamageOpen:false});this.toast("Damage adjustment submitted for Admin approval.");return this.reloadCatalog();}).catch(error=>this.toast(error.message,"error"));
  };
  printApprovedPO=productId=>()=>{
    const order=this.state.purchaseData?.orders.find(o=>o.receipts.length&&o.lines.some(l=>l.productId===productId));
    if(order){window.open(`/purchase-orders/${encodeURIComponent(order.id)}/report`,'_blank','noopener');return;}
    this.setState({screen:'mgrPurchaseHistory'});this.reloadPurchasing().catch(()=>{});this.toast('Open a recorded delivery from Transaction History to print its report.');
  };
  openCategoryModal=()=>this.setState({categoryModalOpen:true,categoryEditing:false,categoryStep:'category',categorySetupCategory:'',categorySetupSubcategory:null,categorySetupSubcategoryDraft:'',categorySetupBrandDraft:'',categorySetupName:'',categorySetupBrandId:'',categoryForm:{name:"",status:"Active",classification:""},categoryError:""});
  editCategory=c=>()=>this.setState({categoryModalOpen:true,categoryEditing:true,categoryForm:{...c},categoryOriginalName:c.name,categoryError:""});
  closeCategoryModal=()=>{if(!this.categoryBusy)this.setState({categoryModalOpen:false});};
  previousCategoryStep=()=>{
    if(this.categoryBusy)return;
    this.setState(s=>s.categoryStep==='brand'?{categoryStep:'subcategory',categorySetupBrandDraft:s.categorySetupName,categorySetupName:s.categorySetupSubcategoryDraft||s.categorySetupSubcategory?.name||'',categoryError:''}:s.categoryStep==='subcategory'?{categoryStep:'category',categorySetupSubcategoryDraft:s.categorySetupName,categoryError:''}:{});
  };
  setCategoryField=field=>e=>this.setState(s=>({categoryForm:{...s.categoryForm,[field]:e.target.value},categoryError:""}));
  saveCategory=async()=>{
    if(this.categoryBusy)return;
    if(!this.state.categoryEditing&&['subcategory','brand'].includes(this.state.categoryStep))return this.saveCategorySetup();
    const f=this.state.categoryForm;
    if(!f.name.trim()||!["Perishable","Non-Perishable"].includes(f.classification)){this.setState({categoryError:"Enter a category name and classification."});return;}
    this.categoryBusy=true;
    try{
      const original=this.state.categoryEditing?this.state.categoryOriginalName:this.state.categorySetupCategory;
      const response=await this.authPost(original?`/api/categories/${encodeURIComponent(original)}`:"/api/categories",f,original?"PATCH":"POST");
      const category=response.category;
      this.setState(s=>({categoryModalOpen:!s.categoryEditing,categoryStep:'subcategory',categorySetupCategory:category.name,categoryForm:{...s.categoryForm,...category},categorySetupName:s.categorySetupSubcategoryDraft||s.categorySetupSubcategory?.name||'',categoryError:'',
        categoriesLocal:[...(s.categoriesLocal||[]).filter(c=>c.name!==category.name&&c.name!==original),category],
        data:{...s.data,CATEGORIES:[...(s.data.CATEGORIES||[]).filter(c=>c.name!==category.name&&c.name!==original),category],SUBCATEGORIES:(s.data.SUBCATEGORIES||[]).map(c=>c.category===original?{...c,category:category.name}:c)},
        ...(!s.categoryEditing?{regCategory:category.name,...(!original?{regSubcategoryId:"",regBrandId:""}:{})}:{})}));
      this.toast("Category saved.");
      await this.reloadCatalog().catch(()=>this.toast("Category saved, but the catalog could not refresh. Reload the page.","warn"));
    }catch(error){this.setState({categoryError:error.message});}finally{this.categoryBusy=false;}
  };
  saveCategorySetup=async()=>{
    if(this.categoryBusy)return;
    const s=this.state,isSub=s.categoryStep==='subcategory',kind=isSub?'subcategories':'brands',name=(s.categorySetupName||'').trim();
    const existing=!isSub&&(s.data.BRANDS||[]).find(b=>String(b.id)===String(s.categorySetupBrandId)&&b.status==='Active');
    if(!name&&!existing){this.setState({categoryError:isSub?'Enter a subcategory name.':'Enter a brand name or choose an existing brand.'});return;}
    this.categoryBusy=true;
    try{
      const savedId=isSub?s.categorySetupSubcategory?.id:null;
      const record=existing||(await this.authPost('/api/product-master/'+kind+(savedId?'/'+savedId:''),{name,status:'Active',...(isSub?{category:s.categorySetupCategory}:{})},savedId?'PATCH':'POST')).record;
      this.setState(current=>({data:{...current.data,[kind.toUpperCase()]:[...(current.data[kind.toUpperCase()]||[]).filter(r=>String(r.id)!==String(record.id)),record]},categoryError:'',categorySetupName:isSub?(s.categorySetupBrandDraft||''):'',
        ...(isSub?{categoryStep:'brand',categorySetupSubcategory:record,categorySetupSubcategoryDraft:record.name,regSubcategoryId:record.id}:{categoryModalOpen:false,regBrandId:record.id})}));
      this.toast(isSub?'Subcategory saved. Add the brand next.':'Category setup complete.');
      await this.reloadCatalog().catch(()=>this.toast('Saved, but the catalog could not refresh. Reload the page.','warn'));
    }catch(error){this.setState({categoryError:error.message});}finally{this.categoryBusy=false;}
  };
  setArchiveTab=tab=>()=>this.setState({archiveTab:tab,archiveChecked:{}});
  toggleArchiveCheck=key=>()=>this.setState(s=>({archiveChecked:{...s.archiveChecked,[key]:!s.archiveChecked[key]}}));
  archiveAction=async(action,kind,ids)=>{
    if(this.archiveBusy)return;
    if(!ids.length){this.toast('Select at least one row.','error');return;}
    this.archiveBusy=true;this.setState({archiveSaving:true});
    try{const result=await this.authPost('/api/inventory/workflows/archive',{action,kind,ids});
      await this.reloadCatalog();this.setState({archiveChecked:{},archiveConfirmOpen:false,supMgrSelected:{}});this.toast(result.message);
    }catch(error){this.toast(error.message,'error');}finally{this.archiveBusy=false;this.setState({archiveSaving:false});}
  };
  restoreArchived=()=>this.archiveAction('restore',this.state.archiveTab==='items'?'products':this.state.archiveTab,Object.keys(this.state.archiveChecked).filter(k=>this.state.archiveChecked[k]));
  openArchiveConfirm=()=>{ const count=Object.values(this.state.archiveChecked).filter(Boolean).length; if(count===0){ this.toast("Select at least one row.","error"); return; } this.setState({archiveConfirmOpen:true}); };
  closeArchiveConfirm=()=>this.setState({archiveConfirmOpen:false});
  deletePermanently=()=>this.archiveAction('delete',this.state.archiveTab==='items'?'products':this.state.archiveTab,Object.keys(this.state.archiveChecked).filter(k=>this.state.archiveChecked[k]));
  toggleInvSelect=id=>()=>this.setState(s=>({invSelected:{...s.invSelected,[id]:!s.invSelected[id]}}));
  moveInvSelectedToArchive=()=>{ const ids=Object.keys(this.state.invSelected).filter(k=>this.state.invSelected[k]);
    if(!ids.length){ this.toast("Select at least one item.","error"); return; }
    this.authPost('/api/inventory/workflows/archive',{kind:'products',ids}).then(r=>{this.setState({invSelected:{}});this.toast(r.message);return this.reloadCatalog();}).catch(e=>this.toast(e.message,'error'));
  };
  toggleCatSelect=name=>()=>this.setState(s=>({catSelected:{...s.catSelected,[name]:!s.catSelected[name]}}));
  moveCatSelectedToArchive=()=>{ const names=Object.keys(this.state.catSelected).filter(k=>this.state.catSelected[k]);
    if(!names.length){ this.toast("Select at least one category.","error"); return; }
    this.authPost('/api/inventory/workflows/archive',{kind:'categories',ids:names}).then(r=>{this.setState({catSelected:{}});this.toast(r.message);return this.reloadCatalog();}).catch(e=>this.toast(e.message,'error'));
  };
  // ---- Manager Supplier module ----
  goSupMgrList=()=>this.setState({supMgrScreen:"list",supMgrDetailId:null});
  setSupMgrSearch=e=>this.setState({supMgrSearch:e.target.value});
  toggleSupMgrSelect=id=>()=>this.setState(s=>({supMgrSelected:{...s.supMgrSelected,[id]:!s.supMgrSelected[id]}}));
  moveSupMgrSelectedToArchive=()=>{ const ids=Object.keys(this.state.supMgrSelected).filter(k=>this.state.supMgrSelected[k]);
    if(!ids.length){ this.toast("Select at least one supplier.","error"); return; }
    return this.archiveAction('archive','suppliers',ids);
  };
  openSupMgrModal=(sup)=>()=>this.setState({supMgrModalOpen:true,supMgrForm:sup?{...sup}:{id:null,name:"",address:"",contact:"",phone:"",email:"",category:"",status:"Active",paymentTerms:"Cash on Delivery",notes:""}});
  closeSupMgrModal=()=>this.setState({supMgrModalOpen:false});
  setSupMgrFormField=field=>e=>this.setState(s=>({supMgrForm:{...s.supMgrForm,[field]:e.target.value}}));
  saveSupMgrForm=()=>{ const {supMgrForm}=this.state;
    if(!supMgrForm.name.trim()){ this.toast("Supplier name is required.","error"); return; }
    if(supMgrForm.id==null){ const id=Date.now(); this.setState(s=>({suppliersLocal:[...s.suppliersLocal,{...supMgrForm,id,products:[],callbackLog:[]}],supMgrModalOpen:false,supMgrScreen:"detail",supMgrDetailId:id})); this.toast("Supplier added."); }
    else { this.setState(s=>({suppliersLocal:s.suppliersLocal.map(sp=>sp.id===supMgrForm.id?{...sp,...supMgrForm}:sp),supMgrModalOpen:false})); this.toast("Supplier updated."); }
  };
  openSupMgrDetail=id=>()=>this.setState({supMgrScreen:"detail",supMgrDetailId:id,supMgrDetailTab:"products"});
  setSupMgrDetailTab=tab=>()=>this.setState({supMgrDetailTab:tab});
  openSupMgrProductPicker=()=>this.setState({supMgrProductPickerOpen:true,supMgrProductSearch:"",supMgrProductChecked:{}});
  closeSupMgrProductPicker=()=>this.setState({supMgrProductPickerOpen:false});
  setSupMgrProductSearch=e=>this.setState({supMgrProductSearch:e.target.value});
  toggleSupMgrProductCheck=id=>()=>this.setState(s=>({supMgrProductChecked:{...s.supMgrProductChecked,[id]:!s.supMgrProductChecked[id]}}));
  addSupMgrSelectedProducts=()=>{ const {supMgrDetailId,supMgrProductChecked,productsLocal}=this.state;
    const ids=Object.keys(supMgrProductChecked).filter(k=>supMgrProductChecked[k]).map(Number);
    if(!ids.length){ this.toast("Select at least one product.","error"); return; }
    this.setState(s=>({suppliersLocal:s.suppliersLocal.map(sp=>{ if(sp.id!==supMgrDetailId) return sp;
      const existing=new Set(sp.products.map(pr=>pr.productId));
      const added=ids.filter(id=>!existing.has(id)).map(id=>{ const p=productsLocal.find(pp=>pp.id===id); return {productId:id,costPrice:p?Number(p.unitPrice??p.cost):0,preferred:false}; });
      return {...sp,products:[...sp.products,...added]}; }),supMgrProductPickerOpen:false}));
    this.toast(`${ids.length} product(s) linked to this supplier.`);
  };
  removeSupMgrProduct=(supplierId,productId)=>()=>this.setState(s=>({suppliersLocal:s.suppliersLocal.map(sp=>sp.id!==supplierId?sp:{...sp,products:sp.products.filter(pr=>pr.productId!==productId)})}));
  setSupMgrProductCost=(supplierId,productId)=>e=>{ const val=parseFloat(e.target.value)||0;
    this.setState(s=>({suppliersLocal:s.suppliersLocal.map(sp=>sp.id!==supplierId?sp:{...sp,products:sp.products.map(pr=>pr.productId!==productId?pr:{...pr,costPrice:val})})})); };
  toggleSupMgrPreferred=(supplierId,productId)=>()=>this.setState(s=>({suppliersLocal:s.suppliersLocal.map(sp=>sp.id!==supplierId?sp:{...sp,products:sp.products.map(pr=>pr.productId!==productId?pr:{...pr,preferred:!pr.preferred})})}));
  // ---- Admin: Manage Accounts ----
  openAcctModal=(mode,acct)=>()=>this.setState({acctModalOpen:true,acctModalMode:mode,acctError:"",acctForm:acct?{...acct,password:""}:{id:null,username:"",email:"",name:"",role:this.state.role==="superadmin"?"admin":"cashier",password:"",status:"Active"}});
  closeAcctModal=()=>this.setState({acctModalOpen:false,acctError:""});
  setAcctField=field=>e=>this.setState(s=>({acctForm:{...s.acctForm,[field]:e.target.value},acctError:""}));
  saveAccount=()=>{
    if(this.accountBusy) return;
    const f=this.state.acctForm,creating=this.state.acctModalMode==="create";
    if(!f.name.trim()||!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email||"")||(creating&&!f.password)||(f.password&&f.password.length<8)){
      this.setState({acctError:"Enter a full name, valid email, and a password of at least 8 characters for new accounts."});return;
    }
    const original=(this.state.accountsLocal||[]).find(a=>a.id===f.id&&a.role===f.role);
    if(!creating&&original&&original.status!==f.status&&!this.confirmAccountStatus(f))return;
    this.accountBusy=true;
    const payload={name:f.name,email:f.email,username:f.username||null,role:f.role,status:f.status,schedule:f.schedule||null};
    if(f.password) payload.password=f.password;
    this.authPost(creating?"/api/accounts":`/api/accounts/${encodeURIComponent(f.role)}/${f.id}`,payload,creating?"POST":"PATCH")
      .then(res=>{this.setState({acctModalOpen:false,acctError:""});this.toast(res.message);if(this.state.role==="superadmin")this.reloadSaDashboard();return Promise.all([this.reloadAccounts(),this.reloadCatalog()]);})
      .catch(error=>this.setState({acctError:error.message})).finally(()=>{this.accountBusy=false;});
  };
  // Registration + Promotions v7
  setRegProductMode=e=>{ const v=e.target.value; if(v==="__other__") this.setState({regProductMode:"other",regProductName:""}); else this.setState({regProductMode:"select",regProductName:v}); };
  setRegOtherProductName=e=>this.setState({regOtherProductName:e.target.value});
  validAmount=value=>/^\d+(?:\.\d{1,2})?$/.test(String(value).trim()) && Number.isFinite(Number(value));
  registrationCost=(unitPrice,quantity)=>{
    const unit=Number(unitPrice),qty=Number(quantity);
    return String(unitPrice).trim()!=="" && String(quantity).trim()!=="" && Number.isFinite(unit) && unit>=0 && Number.isInteger(qty) && qty>=0 ? (Math.round(unit*qty*100)/100).toFixed(2) : "";
  };
  setRegField=field=>e=>{
    const value=e.target.value;
    this.setState(s=>{
      const next={...s,[field]:value};
      return {[field]:value,registrationError:"",registrationNotice:null,regSaved:false,...(field==="regCategory"?{regSubcategoryId:"",regParentId:"",regVariantLabel:""}:{}),...(field==='regParentId'&&!value?{regVariantLabel:''}:{}),...(["regUnitPrice","regQuantity"].includes(field)?{regCostPrice:this.registrationCost(next.regUnitPrice,next.regQuantity)}:{})};
    });
  };
  registrationDuplicate=()=>{
    const s=this.state,normalize=value=>String(value??'').trim().replace(/\s+/g,' ').toLocaleLowerCase();
    if(s.regEditing||!normalize(s.regProductName))return null;
    return (s.productsLocal||s.data?.PRODUCTS||[]).find(p=>String(p.parentId||'')===String(s.regParentId||'')&&normalize(p.variantLabel)===normalize(s.regVariantLabel)&&normalize(p.name)===normalize(s.regProductName)&&String(p.brandId||'')===String(s.regBrandId||'')&&Number(p.size||0)===Number(s.regSize||0)&&normalize(p.sizeUnit)===normalize(s.regSizeUnit)&&normalize(p.stockUnit||p.unit)===normalize(s.regStockUnit));
  };
  saveRegistration=()=>{
    if(this.registrationBusy)return;
    const requiredFields={regProductName:'Product name',regManufacturer:'Manufacturer',regScannedBarcode:'Barcode',regCategory:'Category',regBrandId:'Brand',regSubcategoryId:'Subcategory',regSize:'Size',regSizeUnit:'Measurement Unit',regSupplier:'Supplier',regStatus:'Status',regStockUnit:'Stock Unit',regReorderLevel:'Minimum Stock',...(this.state.regParentId?{regVariantLabel:'Variant label'}:{}),...(this.state.regEditing?{regUnitPrice:'Purchase cost',regRetailPrice:'Selling price',regEditReason:'Reason for changes'}:{})};
    const missing=Object.entries(requiredFields).filter(([key])=>this.state[key]==null||String(this.state[key]).trim()==='').map(([,label])=>label);
    if(missing.length){this.toast('Complete all required fields: '+missing.join(', ')+'.','error');return;}

    const {data,productsLocal,regProductName,regCategory,regSupplier,regScannedBarcode,regQuantity,regCostPrice,regUnitPrice,regRetailPrice,regBatch,regLot,regExpiry,regStockUnit,regStatus,regVatClass}=this.state;
    const duplicate=this.registrationDuplicate();
    if(duplicate){this.setState({registrationError:'This product already exists in inventory (Item Code '+duplicate.id+'). Use the existing product to receive stock or edit its details.'});return;}
    const name = regProductName.trim();
    if(!name||!regCategory){ this.toast("Product name and category are required.","error"); return; }
    if(!regScannedBarcode.trim()){ this.toast("Scan the item barcode before saving registration.","error"); return; }
    const qty=this.state.regEditing?(productsLocal||data.PRODUCTS).find(p=>p.id===this.state.regEditing).stock:0;
    const unitPrice=this.state.regEditing?regUnitPrice:"0";
    const retailPrice=this.state.regEditing?(regRetailPrice||regUnitPrice):"0";
    const price=Number(retailPrice);
    if(!this.validAmount(retailPrice)){ this.toast("Enter a valid retail price or unit price.","error"); return; }
    if(!this.validAmount(unitPrice)){this.toast("Enter a valid unit price with at most two decimal places.","error");return;}
    const calculatedCost="0";
    if(calculatedCost===""){ this.toast("Enter a valid unit price to calculate cost price.","error"); return; }
    const cost=Number(calculatedCost);
    if(!regStockUnit){this.toast('Select a Stock Unit.','error');return;}
    const size=String(this.state.regSize??'').trim(),sizeUnit=this.state.regSizeUnit;
    if((size||sizeUnit)&&(!/^\d+(?:\.\d{1,3})?$/.test(size)||Number(size)<=0||!sizeUnit)){this.toast('Enter a positive size and select its measurement unit, or leave both blank.','error');return;}
    if(!/^\d+$/.test(String(this.state.regReorderLevel??0))){this.toast('Minimum Stock must be a whole number of zero or more.','error');return;}
    const existing=(productsLocal||data.PRODUCTS).find(p=>p.id===this.state.regEditing);
    const purchaseUnit=existing?.purchaseUnit||regStockUnit;
    const conversionFactor=existing?.conversionFactor??1;
    const catalog=productsLocal||data.PRODUCTS;
    if(catalog.some(p=>p.id!==this.state.regEditing&&p.barcode===regScannedBarcode.trim())){ this.toast("This barcode is already registered.","error"); return; }
    const sku="";
    const barcode=regScannedBarcode.trim();
    const nextId=Math.max(0,...catalog.map(p=>Number(p.id)||0))+1;
    const supplier=(data.SUPPLIERS||[]).find(s=>s.name===regSupplier);
    const product={id:nextId,name,category:regCategory,vatClass:"VAT-Exempt",price,cost,stock:qty,minStock:0,unit:regStockUnit||"Piece",status:regStatus||"Active",batch:regBatch||"",lot:regLot||"",expiry:regExpiry||"",barcode,supplierId:supplier?supplier.id:regSupplier,parentId:null,variantLabel:"",purchaseUnit,stockUnit:regStockUnit||"Piece",conversionFactor,barcodeStatus:"Scanned",archivedAt:null,archivedBy:null,unitPrice:Number(unitPrice)};
    this.registrationBusy=true;this.setState({registrationSaving:true,registrationError:"",registrationNotice:null});
    this.authPost("/api/products"+(this.state.regEditing?"/"+this.state.regEditing:""),{parentId:this.state.regParentId?Number(this.state.regParentId):null,variantLabel:this.state.regVariantLabel||'',reason:this.state.regEditReason||null,manufacturer:this.state.regManufacturer||null,unitPrice:Number(unitPrice),brandId:this.state.regBrandId||null,subcategoryId:this.state.regSubcategoryId||null,size:this.state.regSize||null,sizeUnit:this.state.regSizeUnit||null,minStock:Number(this.state.regReorderLevel||0),name,category:regCategory,vatClass:"VAT-Exempt",price,cost,stock:qty,barcode,supplierId:supplier?supplier.id:null,purchaseUnit,stockUnit:regStockUnit,conversionFactor,status:regStatus},this.state.regEditing?"PATCH":"POST").then(response=>{
      const savedProduct={...product,...response.product};
      this.setState({productsLocal:[...catalog.filter(p=>p.id!==savedProduct.id),savedProduct],regSaved:true,regSku:String(response.product.id),regBarcode:barcode,regRegisteredQty:qty});
      this.reloadCatalog().then(()=>this.toast(`"${name}" registered and saved to the product catalog.`)).catch(()=>this.toast(`"${name}" saved, but the catalog could not refresh. Reload the page.` ,"warn"));
    }).catch(error=>this.setState({registrationError:"Product could not be registered: "+error.message})).finally(()=>{this.registrationBusy=false;this.setState({registrationSaving:false});});
  };
  setRegPrintQty=e=>this.setState({regPrintQty:Math.max(1,parseInt(e.target.value,10)||1)});
  printLabels=()=>this.toast(`Opening print-preview for ${this.state.regPrintQty} label(s).`);
  openPromoPicker=()=>this.setState({promoPickerOpen:true,promoPickerSearch:"",promoPickerChecked:Object.fromEntries(this.state.promoSelectedItems.map(id=>[id,true]))});
  closePromoPicker=()=>this.setState({promoPickerOpen:false});
  setPromoPickerSearch=e=>this.setState({promoPickerSearch:e.target.value});
  togglePromoPickerCheck=id=>()=>this.setState(s=>({promoPickerChecked:{...s.promoPickerChecked,[id]:!s.promoPickerChecked[id]}}));
  confirmPromoPicker=()=>{ const ids=Object.keys(this.state.promoPickerChecked).filter(k=>this.state.promoPickerChecked[k]).map(Number); this.setState({promoSelectedItems:ids,promoPickerOpen:false}); };
  removePromoSelectedItem=id=>()=>this.setState(s=>({promoSelectedItems:s.promoSelectedItems.filter(x=>x!==id)}));
  setPromoStartDate=e=>this.setState({promoStartDate:e.target.value});
  setPromoEndDate=e=>this.setState({promoEndDate:e.target.value});
  setPromoType=type=>()=>this.setState({promoType:type});
  setPromoOccasionName=e=>this.setState({promoOccasionName:e.target.value});
  setPromoCategory=e=>this.setState({promoCategory:e.target.value});
  setPromoDiscountPct=e=>this.setState({promoDiscountPct:e.target.value});
  resetPromoForm=()=>this.setState({promoSelectedItems:[],promoStartDate:"",promoEndDate:"",promoType:"Near-Expiry",promoOccasionName:"",promoCategory:"",promoDiscountPct:""});
  quickPromoFromNearExpiry=productId=>()=>{ this.setState({screen:"mgrPromotions",promoSelectedItems:[productId],promoType:"Near-Expiry",promoStartDate:this.state.data.BUSINESS_DATE,promoEndDate:this.state.data.BUSINESS_DATE,promoDiscountPct:""}); };
  quickPromoFromLeastSales=productId=>()=>{ this.setState({screen:"mgrPromotions",promoSelectedItems:[productId],promoType:"Slow-Moving",promoStartDate:this.state.data.BUSINESS_DATE,promoEndDate:this.state.data.BUSINESS_DATE,promoDiscountPct:""}); };
  copyPromotion=promo=>()=>{ this.setState({promoSelectedItems:[...promo.productIds],promoType:promo.type,promoOccasionName:promo.occasionName||"",promoCategory:promo.category||"",promoDiscountPct:String(promo.discountPct),promoStartDate:"",promoEndDate:""}); this.toast("Promotion copied â€” set new dates and save."); };
  savePromotion=async()=>{
    if(this.promotionSaving)return;
    const {data,promoType,promoSelectedItems,promoCategory,promoStartDate,promoEndDate,promoDiscountPct,promoOccasionName,promotionsLocal}=this.state;
    if(!promoStartDate||!promoEndDate||promoStartDate>promoEndDate){ this.toast("Pick a valid start/end date range.","error"); return; }
    const pct=parseFloat(promoDiscountPct); if(isNaN(pct)||pct<=0||pct>=100){ this.toast("Enter a valid discount percentage.","error"); return; }
    let productIds = promoType==="Category-Wide" ? data.PRODUCTS.filter(p=>p.category===promoCategory).map(p=>p.id) : promoSelectedItems;
    if(!productIds.length){ this.toast(promoType==="Category-Wide"?"Select a category.":"Select at least one item.","error"); return; }
    for(const pid of productIds){ const p=data.PRODUCTS.find(pp=>pp.id===pid); const promoPrice=p.price*(1-pct/100);
      if(promoPrice<Number(p.unitPrice??p.cost)){ this.toast(`"${p.name}": this discount would sell below cost â€” ${peso(p.unitPrice??p.cost)} minimum.`,"error"); return; } }
    for(const pid of productIds){ const conflict=promotionsLocal.find(pr=>pr.productIds.includes(pid) && !(promoEndDate<pr.startDate||promoStartDate>pr.endDate));
      if(conflict){ const p=data.PRODUCTS.find(pp=>pp.id===pid); this.toast(`Conflict: "${p.name}" already has an overlapping promotion "${conflict.name}".`,"error"); return; } }
    const name = promoType==="Category-Wide" ? promoCategory+" Category Promo" : promoType==="Seasonal" ? (promoOccasionName||"Seasonal Promo") : promoType+" Promo";
    this.promotionSaving=true;
    try{
      await this.authPost('/api/promotions',{name,productIds,type:promoType,discountPct:pct,startDate:promoStartDate,endDate:promoEndDate,occasionName:promoOccasionName||null,category:promoType==='Category-Wide'?promoCategory:null});
      await this.reloadCatalog();this.resetPromoForm();this.toast(`Promotion "${name}" saved.`);
    }catch(e){this.toast(e.message,'error');}finally{this.promotionSaving=false;}
  };
  setRepDateMode=e=>this.setState({repDateMode:e.target.value});
  setRepField=field=>e=>this.setState({[field]:e.target.value});
  openItemPicker=()=>this.setState({itemPickerOpen:true,itemPickerSearch:"",itemPickerFilterCategory:"",itemPickerSubcategoryId:"",itemPickerFilterSupplier:this.state.newReqCart.length?this.state.newReqSupplier||"":"",itemPickerChecked:{},itemPickerLowStockOnly:false});
  closeItemPicker=()=>this.setState({itemPickerOpen:false});
  setItemPickerSearch=e=>this.setState({itemPickerSearch:e.target.value});
  setItemPickerFilterCategory=e=>this.setState({itemPickerFilterCategory:e.target.value,itemPickerSubcategoryId:""});
  setItemPickerFilterSupplier=e=>this.setState({itemPickerFilterSupplier:e.target.value});
  toggleItemPickerLowStockOnly=()=>this.setState(s=>({itemPickerLowStockOnly:!s.itemPickerLowStockOnly}));
  toggleItemPickerCheck=productId=>()=>this.setState(s=>({itemPickerChecked:{...s.itemPickerChecked,[productId]:!s.itemPickerChecked[productId]}}));
  addSelectedToRequest=()=>{
    const {data,itemPickerChecked,itemPickerFilterSupplier}=this.state;
    const ids=Object.keys(itemPickerChecked).filter(id=>itemPickerChecked[id]);
    if(ids.length===0){ this.toast("Select at least one item.","error"); return; }
    const selected=data.PRODUCTS.filter(p=>ids.includes(String(p.id))&&p.status==='Active'&&!p.archivedAt);
    const supplierIds=[...new Set(selected.map(p=>String(p.supplierId||'')).filter(Boolean))];
    const supplierId=String(itemPickerFilterSupplier||this.state.newReqCart[0]?.supplierId||(supplierIds.length===1?supplierIds[0]:''));
    if(!supplierId||!data.SUPPLIERS.some(sp=>String(sp.id)===supplierId&&sp.status==='Active'&&!sp.archivedAt)){this.toast('Choose a supplier in Add Items. Each request uses one supplier.','error');return;}
    if(!itemPickerFilterSupplier&&selected.some(p=>String(p.supplierId||'')!==supplierId)){this.toast('Choose one supplier in Add Items before combining these products.','error');return;}
    if(this.state.newReqCart.length&&String(this.state.newReqSupplier||this.state.newReqCart[0].supplierId)!==supplierId){this.toast('This request already has a different supplier. Remove its items before choosing another supplier.','error');return;}
    const existingIds=new Set(this.state.newReqCart.map(l=>l.productId));
    const added=selected.filter(p=>!existingIds.has(p.id)).map(p=>({category:p.category,supplierId,productId:p.id,name:this.productLabel(p),qty:1}));
    this.purchaseSubmissionKey=null;
    this.setState(s=>({newReqSupplier:supplierId,newReqCart:[...s.newReqCart.map(l=>ids.includes(String(l.productId))?{...l,qty:Number(l.qty)+1}:l),...added],itemPickerOpen:false}));
    this.toast(`${added.length} item(s) added to the request.`);
  };
  setReqCartQty=i=>e=>{ const val=e.target.value; this.setState(s=>({newReqCart:s.newReqCart.map((l,idx)=>idx===i?{...l,qty:val}:l)})); };
  setRequestUnitField=(i,field,value)=>{this.purchaseSubmissionKey=null;this.setState(s=>({newReqCart:s.newReqCart.map((l,n)=>n===i?{...l,[field]:value,...(field==='orderUnit'?{piecesPerBox:'',stockUnitsPerOrder:''}:{})}:l)}));};
  buildRequestUnit(line,index){
    const h=React.createElement,stockUnit=this.purchaseUnit(line),unit=line.orderUnit||stockUnit;
    const product=(this.state.data?.PRODUCTS||[]).find(p=>String(p.id)===String(line.productId));
    const options=[...new Set([stockUnit,product?.purchaseUnit,'Box','Case','Pack'].filter(Boolean))];
    const packaged=this.requestUsesPackage(line),factor=line.stockUnitsPerOrder??line.piecesPerBox??'';
    return h('div',{className:'request-unit-control'},[
      h('select',{'aria-label':'Purchase Unit for '+line.name,value:unit,onChange:e=>this.setRequestUnitField(index,'orderUnit',e.target.value)},options.map(value=>h('option',{key:value,value},value))),
      packaged?h('label',null,[stockUnit+' per '+unit,h('input',{'aria-label':stockUnit+' per '+unit+' for '+line.name,type:'number',min:1,max:1000000,step:1,required:true,value:factor,onChange:e=>this.setRequestUnitField(index,'stockUnitsPerOrder',e.target.value)})]):null,
      packaged&&Number(factor)>0?h('small',null,Number(line.qty)*Number(factor)+' '+stockUnit+' total'):null
    ]);
  }
  toggleRolePerm=(mi,ri)=>()=>this.setState(s=>{ const grid=s.rolesMatrix.grid.map((row,i)=>i===mi?{...row,cells:row.cells.map((c,j)=>j===ri?(c?0:1):c)}:row); return {rolesMatrix:{...s.rolesMatrix,grid}}; });
  reloadBackups=async()=>{
    this.setState({backupLoading:true,backupError:""});
    try {
      const response=await fetch('/api/backups',{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'});
      const body=await response.json(); this.handleSessionResponse(response,body);
      if(!response.ok)throw new Error(body.message||'Unable to load backups.');
      this.setState({backupData:body});
    }catch(error){this.setState({backupError:error.message});}
    finally{this.setState({backupLoading:false});}
  };
  runBackupNow=async()=>{
    if(this.backupBusy)return;
    this.backupBusy=true;this.setState({backupBusy:true});
    try{await this.authPost('/api/backups',{});this.toast('Backup completed.');await this.reloadBackups();}
    catch(error){this.toast(error.message,'error');}
    finally{this.backupBusy=false;this.setState({backupBusy:false});}
  };
  restoreBackup=id=>()=>this.setState({backupRestoreId:id});

  // ===================== SCREEN BUILDERS =====================
  buildPos(){
    const {data,cart,barcodeInput,scannerConnected,manualLookupOpen,manualSearch,seniorPwdOpen,seniorApplied,seniorInfo,seniorForm,employeeDiscount,
      overrideLine,overrideReason,overridePin,paymentMethod,ewalletProvider,tendered,paymentState,reservationSeconds,commitOpen,transactionResult}=this.state;
    if(!data) return card(loadingStatus("Loading catalog..."));
    if(transactionResult) return this.buildTransactionView(transactionResult);
    const totals=this.computeTotals();
    const priorityNote = employeeDiscount ? "Applied: Employee Discount (10%) â€” Senior/PWD & Promotions not stacked" : seniorApplied ? "Applied: Senior/PWD Discount (20% + VAT exemption) â€” Promotions not stacked" : null;
    const cartRows=cart.map(l=>tr([
      td(React.createElement("div",null,[
        React.createElement("div",{key:"n",style:{fontWeight:700}},l.name),
        l.qty>this.sellableStock((this.state.productsLocal||data.PRODUCTS).find(p=>p.id===l.productId))?React.createElement("div",{key:"stock-warning",role:"alert",style:{color:COLORS.red}},"Requested quantity exceeds current sellable stock. Update or remove this item."):null,
        l.manual?React.createElement("span",{key:"m",style:{fontSize:10,fontWeight:700,color:COLORS.blue,background:COLORS.blueBg,padding:"2px 6px",borderRadius:6,marginRight:6}},"Manual Entry"):null,
        l.overridden?React.createElement("span",{key:"o",title:`Approved by ${l.overrideApprover} â€” ${l.overrideReason}`,style:{fontSize:10,fontWeight:700,color:COLORS.amber,background:COLORS.amberBg,padding:"2px 6px",borderRadius:6,marginRight:6}},"Overridden"):null,
        (l.promo && !employeeDiscount && !seniorApplied)?React.createElement("span",{key:"p",style:{fontSize:10,fontWeight:700,color:COLORS.purple,background:COLORS.purpleBg,padding:"2px 6px",borderRadius:6}},"Promo: "+l.promo.name):null,
      ])),
      td(React.createElement("div",{style:{display:"flex",alignItems:"center",gap:6,animation:l.pulse?"pulse .3s ease 2":"none"}},[
        React.createElement("button",{key:"m",onClick:this.stepQty(l.lineId,-1),style:{width:24,height:24,borderRadius:6,border:"1px solid "+COLORS.border,background:"#fff",cursor:"pointer"}},"âˆ’"),
        React.createElement("span",{key:"q",style:{minWidth:20,textAlign:"center",fontWeight:700}},l.qty),
        React.createElement("button",{key:"p",onClick:this.stepQty(l.lineId,1),style:{width:24,height:24,borderRadius:6,border:"1px solid "+COLORS.border,background:"#fff",cursor:"pointer"}},"+"),
      ])),
      td(l.overridden?React.createElement("div",null,[React.createElement("span",{key:"s",style:{textDecoration:"line-through",color:COLORS.textMuted,marginRight:6}},peso(l.originalPrice)),React.createElement("span",{key:"n",style:{color:COLORS.green}},peso(l.unitPrice))]):peso(l.unitPrice)),
      td(peso(l.unitPrice*l.qty),{fontWeight:700}),
      td(React.createElement("div",{style:{display:"flex",gap:6}},[
        React.createElement("button",{key:"ov",onClick:this.openOverride(l.lineId),style:{fontSize:11,fontWeight:700,color:COLORS.brand,background:"none",border:"none",cursor:"pointer"}},"Override"),
        React.createElement("button",{key:"rm",onClick:this.removeLine(l.lineId),style:{fontSize:11,fontWeight:700,color:COLORS.red,background:"none",border:"none",cursor:"pointer"}},"Remove"),
      ])),
    ],l.lineId));
    const commitSteps=["Insert Sale","Update Inventory","Insert Payment","COMMIT"];
    return React.createElement("div",{className:"pos-layout",style:{display:"flex",flexDirection:"column",gap:16,maxWidth:1180,margin:"0 auto"}},[
      this.state.stockAvailabilityError?React.createElement('p',{role:'alert'},'Live stock update failed. Each selection and checkout must pass a new stock check.'):null,
      React.createElement("div",{key:"left"},[
        card([
          React.createElement("div",{key:"t",style:{fontWeight:800,fontSize:16,marginBottom:4}},"1. Scan a product"),
          React.createElement("div",{key:"h",style:{fontSize:12,color:COLORS.textSoft,marginBottom:10}},"Scan the barcode or type it below, then press Enter or Add."),
          !scannerConnected?React.createElement("div",{key:"w",style:{background:COLORS.redBg,color:COLORS.red,padding:"8px 12px",borderRadius:8,fontSize:12,fontWeight:600,marginBottom:10}},"âš  Scanner disconnected â€” use manual entry below."):null,
          React.createElement("div",{key:"row",style:{display:"flex",gap:8}},[
            React.createElement("input",{key:"i",autoFocus:true,value:barcodeInput,onChange:this.setBarcodeInput,onKeyDown:this.onBarcodeKey,placeholder:"Scan or type barcode, then Enter...",style:{flex:1,padding:"12px 14px",border:"1px solid "+COLORS.border,borderRadius:8,fontFamily:"'JetBrains Mono',monospace",fontSize:14}}),
            React.createElement("button",{key:"add",onClick:this.scanBarcode,style:{padding:"0 16px",background:COLORS.brand,color:"#fff",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Add"),
          ]),
          React.createElement("button",{key:"ml",onClick:this.openManualLookup,style:{marginTop:10,fontSize:12,fontWeight:700,color:COLORS.brand,background:"none",border:"none",cursor:"pointer"}},"Search product manually"),
        ],{marginBottom:16}),
        card([React.createElement("div",{key:"t",style:{fontWeight:800,fontSize:16,marginBottom:10}},`2. Cart (${cart.length} item${cart.length!==1?"s":""})`),
          cart.length===0?React.createElement("div",{key:"e",style:{padding:"30px 0",textAlign:"center",color:COLORS.textMuted,fontSize:13}},"Scan an item to begin."):table(["Item","Qty","Unit Price","Line Total","Actions"],cartRows)]),
      ]),
      React.createElement("div",{key:"right",className:"pos-payment-panel",style:{display:"grid",gridTemplateColumns:"repeat(auto-fit,minmax(min(100%,280px),1fr))",gap:16}},[
        card([React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"3. Discounts"),
          React.createElement("div",{key:"row",style:{display:"flex",flexDirection:"column",gap:8}},[
            React.createElement("label",{key:"emp",style:{display:"flex",alignItems:"center",gap:8,fontSize:13,fontWeight:600}},[React.createElement("input",{key:"cb",type:"checkbox",checked:employeeDiscount,onChange:this.toggleEmployeeDiscount}),"Employee Discount (10%)"]),
            seniorApplied?React.createElement("div",{key:"chip",style:{background:COLORS.greenBg,color:COLORS.green,padding:"8px 10px",borderRadius:8,fontSize:12,fontWeight:700,display:"flex",justifyContent:"space-between"}},[React.createElement("span",{key:"n"},`Senior/PWD: ${seniorInfo.name}`),React.createElement("span",{key:"x",onClick:this.removeSenior,style:{cursor:"pointer"}},"âœ•")])
              :React.createElement("button",{key:"btn",onClick:this.openSeniorPwd,style:{padding:"9px 10px",background:"#fff",border:"1px solid "+COLORS.border,borderRadius:8,fontWeight:700,fontSize:12,cursor:"pointer"}},"Apply Senior/PWD Discount"),
          ]),
          priorityNote?React.createElement("div",{key:"pn",style:{marginTop:8,fontSize:11,color:COLORS.textSoft,fontStyle:"italic"}},priorityNote):null,
          this.discountNeedsApproval()?this.buildDiscountApproval():null,
        ]),
        card([React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"4. Summary"),
          ...[["Subtotal",totals.subtotal],["VAT-Exempt Sales",totals.vatExempt],["Discount",-totals.discount]].map(([l,v],i)=>
            React.createElement("div",{key:i,style:{display:"flex",justifyContent:"space-between",fontSize:13,padding:"4px 0",color:l==="Discount"&&v<0?COLORS.red:COLORS.textSoft}},[React.createElement("span",{key:"l"},l),React.createElement("span",{key:"v",style:{fontFamily:"'JetBrains Mono',monospace"}},peso(v))])),
          React.createElement("div",{key:"tot",style:{display:"flex",justifyContent:"space-between",fontSize:17,fontWeight:800,marginTop:10,paddingTop:10,borderTop:"1px solid "+COLORS.border}},[React.createElement("span",{key:"l"},"Total"),React.createElement("span",{key:"v",style:{fontFamily:"'JetBrains Mono',monospace"}},peso(totals.grandTotal))]),
        ]),
        card([React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"5. Payment"),
          React.createElement("div",{key:"tog",style:{display:"flex",gap:6,marginBottom:10}},["cash","ewallet"].map(m=>React.createElement("button",{key:m,onClick:this.setPaymentMethod(m),style:{flex:1,padding:"9px 0",borderRadius:8,fontWeight:700,fontSize:12,cursor:"pointer",background:paymentMethod===m?COLORS.brand:"#fff",color:paymentMethod===m?"#fff":COLORS.text,border:"1px solid "+(paymentMethod===m?COLORS.brand:COLORS.border)}},m==="cash"?"Cash":"QR Ph"))),
          paymentMethod==="cash"?React.createElement("div",{key:"cash"},[
            React.createElement("input",{key:"i",value:tendered,onChange:this.setTendered,placeholder:"Tendered amount",type:"number",style:{width:"100%",padding:"9px 10px",border:"1px solid "+COLORS.border,borderRadius:8,fontSize:13,marginBottom:6}}),
            tendered?React.createElement("div",{key:"c",style:{fontSize:12,color:COLORS.textSoft}},`Change: ${peso(Math.max(0,(parseFloat(tendered)||0)-totals.grandTotal))}`):null,
          ]):React.createElement("div",{key:"ew",style:{fontSize:13,fontWeight:700,marginBottom:6}},"QR Ph payment"),
          paymentMethod==="ewallet" && ewalletProvider==="QR Ph"?React.createElement("div",{key:"qr-help",style:{fontSize:12,color:COLORS.textSoft,marginBottom:8}},"Open checkout to display a QR for "+peso(totals.grandTotal)+". Scan with a QR Ph-compatible wallet or banking app. Payment is confirmed automatically."):null,
          paymentState==="pending"?React.createElement("div",{key:"pend",style:{background:COLORS.amberBg,color:COLORS.amber,padding:"10px",borderRadius:8,fontSize:12,fontWeight:700,marginBottom:8,display:"flex",justifyContent:"space-between"}},[React.createElement("span",{key:"l"},"ðŸ”’ Stock reserved â€” Pending"),React.createElement("span",{key:"t",style:{fontFamily:"'JetBrains Mono',monospace"}},reservationSeconds+"s")]):null,
          paymentState==="processing"?React.createElement("div",{key:"proc",style:{background:COLORS.blueBg,color:COLORS.blue,padding:"10px",borderRadius:8,fontSize:12,fontWeight:700,marginBottom:8}},loadingStatus("Processing payment...")):null,
          paymentState==="authorized"?React.createElement("div",{key:"auth",style:{background:COLORS.blueBg,color:COLORS.blue,padding:"10px",borderRadius:8,fontSize:12,fontWeight:700,marginBottom:8}},"Authorized â€” finalizingâ€¦"):null,
          (paymentState==="failed"||paymentState==="timeout")?React.createElement("div",{key:"fail",style:{background:COLORS.redBg,color:COLORS.red,padding:"10px",borderRadius:8,fontSize:12,fontWeight:700,marginBottom:8}},[React.createElement("div",{key:"m"},paymentState==="timeout"?"Timed out â€” reservation released.":"Payment failed."),React.createElement("div",{key:"a",style:{display:"flex",gap:8,marginTop:6}},[btn("Retry",this.retryPayment),btn("Switch to Cash",this.switchToCash)])]):null,
          paymentState==="cancelled"?React.createElement("div",{key:"c",style:{background:COLORS.redBg,color:COLORS.red,padding:"10px",borderRadius:8,fontSize:12,fontWeight:700,marginBottom:8}},"Payment cancelled."):null,
          React.createElement("div",{key:"actions",style:{display:"flex",gap:8}},[
            (paymentState==="idle"||paymentState==="failed"||paymentState==="timeout"||paymentState==="cancelled")?React.createElement("button",{key:"pay",onClick:this.startPayment,style:{flex:1,padding:"11px",background:COLORS.brand,color:"#fff",border:"none",borderRadius:8,fontWeight:800,cursor:"pointer"}},"Charge "+peso(totals.grandTotal)):null,
            (paymentState==="pending"||paymentState==="processing")?React.createElement("button",{key:"cancel",onClick:this.cancelPayment,style:{flex:1,padding:"11px",background:"#fff",border:"1px solid "+COLORS.border,borderRadius:8,fontWeight:700,cursor:"pointer"}},"Cancel"):null,
          ]),
        ]),
        card([React.createElement("div",{key:"t",onClick:this.toggleCommit,style:{fontWeight:800,cursor:"pointer",display:"flex",justifyContent:"space-between"}},[React.createElement("span",{key:"l"},"Transaction steps"),React.createElement("span",{key:"c"},commitOpen?"â–²":"â–¼")]),
          commitOpen?React.createElement("div",{key:"body",style:{marginTop:10}},[
            ...commitSteps.map((s)=>React.createElement("div",{key:s,style:{display:"flex",alignItems:"center",gap:8,fontSize:12,padding:"4px 0",color:COLORS.text}},[React.createElement("span",{key:"d",style:{width:8,height:8,borderRadius:"50%",background:COLORS.green}}),React.createElement("span",{key:"l"},s)])),
          ]):null,
        ]),
      ]),
      manualLookupOpen?this.buildManualLookupModal():null,
      seniorPwdOpen?this.buildSeniorModal():null,
      overrideLine?this.buildOverrideModal():null,
    ]);
  }
  buildManualLookupModal(){
    const h=React.createElement;
    const {data,manualSearch,manualCategory=""}=this.state;
    const catalog=this.state.productsLocal||data.PRODUCTS;
    const categoryOf=p=>String(p.category||"").trim()||"Uncategorized";
    const categories=[...new Set(catalog.map(categoryOf))].sort((a,b)=>a.localeCompare(b));
    const q=manualSearch.trim().toLowerCase();
    const results=catalog.filter(p=>(!manualCategory||categoryOf(p)===manualCategory)&&(!this.state.manualSubcategoryId||String(p.subcategoryId)===String(this.state.manualSubcategoryId))&&(searchMatches([p.name,(data.BRANDS||[]).find(b=>String(b.id)===String(p.brandId))?.name,p.size,p.sizeUnit,p.barcode,categoryOf(p)].join(" "),q)));
    return h("div",{key:"mlm",style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50,padding:16}},
      h("div",{className:"manual-lookup-dialog",role:"dialog","aria-modal":true,"aria-label":"Manual Product Lookup",onKeyDown:e=>{if(e.key==="Escape"){e.stopPropagation();this.closeManualLookup();}},style:{width:520,maxWidth:"100%",maxHeight:"80vh",background:"#fff",borderRadius:14,display:"flex",flexDirection:"column",overflow:"hidden"}},[
        h("div",{key:"header",className:"manual-lookup-header"},[
          h("h2",{style:{fontSize:18,margin:0}},"Manual Product Lookup")
        ]),
        h("div",{key:"body",className:"dialog-scroll manual-lookup-body",tabIndex:0,"aria-label":"Product lookup results and filters",style:{minHeight:0,overflowY:"auto",padding:20}},[
        h("label",{key:"label",htmlFor:"manual-category",style:{display:"block",fontSize:12,fontWeight:700,marginBottom:6}},"Category"),
        h("select",{key:"category",id:"manual-category",value:manualCategory,onChange:e=>this.setState({manualCategory:e.target.value,manualSubcategoryId:""}),style:{width:"100%",padding:10,border:"1px solid "+COLORS.border,borderRadius:8,marginBottom:10}},[
          h("option",{key:"all",value:""},"All categories"),
          ...categories.map(category=>h("option",{key:category,value:category},category)),
        ]),
        this.buildSubcategorySelect(manualCategory,this.state.manualSubcategoryId,e=>this.setState({manualSubcategoryId:e.target.value})),
        h("input",{key:"search",value:manualSearch,onChange:this.setManualSearch,"aria-label":"Search products",placeholder:"Search products in selected category",style:{width:"100%",boxSizing:"border-box",padding:10,border:"1px solid "+COLORS.border,borderRadius:8,marginBottom:10}}),
        h("div",{key:"count",style:{fontSize:12,color:COLORS.textSoft,marginBottom:12}},results.length+" product(s) found"),
        ...categories.filter(category=>results.some(p=>categoryOf(p)===category)).map(category=>h("section",{key:category,style:{marginBottom:16}},[
          h("h3",{key:"heading",style:{fontSize:13,margin:"0 0 6px",padding:"8px 10px",background:COLORS.brandBg,color:COLORS.brandDark,borderRadius:6}},category),
          ...results.filter(p=>categoryOf(p)===category).map(p=>{
            const available=p.status==="Active"&&this.sellableStock(p)>0;
            return h("button",{key:p.id,type:"button",disabled:!available,onClick:this.addManualProduct(p),style:{width:"100%",background:"transparent",border:0,borderBottom:"1px solid #eef1f6",display:"flex",justifyContent:"space-between",gap:12,textAlign:"left",padding:"12px 8px",cursor:available?"pointer":"not-allowed",opacity:available?1:0.5}},[
              h("span",{key:"details"},[h("span",{key:"name",style:{display:"block",fontWeight:600,fontSize:13}},[p.name,(data.BRANDS||[]).find(b=>String(b.id)===String(p.brandId))?.name,p.size,p.sizeUnit].filter(Boolean).join(" ")),h("span",{key:"stock",style:{display:"block",fontSize:11,color:COLORS.textMuted}},p.status!=="Active"?p.status:this.sellableStock(p)>0?this.sellableStock(p)+" sellable":"Unavailable: "+((p.exclusionReasons||[]).join("; ")||"No eligible stock"))]),
              h("span",{key:"price",style:{fontWeight:700,whiteSpace:"nowrap"}},peso(p.price)),
            ]);
          }),
        ])),
        !results.length?h("p",{key:"empty",style:{textAlign:"center",padding:16,color:COLORS.textSoft}},"No products match. Try another category or search."):null,
        ]),
        h("div",{key:"footer",style:{flexShrink:0,padding:"12px 20px",background:"#fff",borderTop:"1px solid "+COLORS.border}},
          h("button",{key:"close",type:"button",onClick:this.closeManualLookup,style:{width:"100%",minHeight:44,padding:10,background:COLORS.red,color:"#fff",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Close")),
      ]));
  }
  buildSeniorModal(){ const {seniorForm}=this.state; return React.createElement("div",{key:"sm",style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50}},
    React.createElement("div",{style:{width:360,background:"#fff",borderRadius:14,padding:20}},[
      React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"Senior Citizen / PWD Discount"),
      React.createElement("input",{key:"n",value:seniorForm.name,onChange:this.setSeniorField("name"),placeholder:"Full name",style:{width:"100%",padding:"9px 10px",border:"1px solid "+COLORS.border,borderRadius:8,marginBottom:8}}),
      React.createElement("input",{key:"id",value:seniorForm.idNumber,onChange:this.setSeniorField("idNumber"),placeholder:"Senior/PWD ID number",style:{width:"100%",padding:"9px 10px",border:"1px solid "+COLORS.border,borderRadius:8,marginBottom:12}}),
      React.createElement("div",{key:"a",style:{display:"flex",gap:8}},[React.createElement("button",{key:"c",onClick:this.closeSeniorPwd,style:{flex:1,padding:10,background:"#f7f9fc",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Cancel"),React.createElement("button",{key:"a",onClick:this.applySeniorPwd,style:{flex:1,padding:10,background:COLORS.brand,color:"#fff",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Apply Discount")]),
    ])); }
  buildOverrideModal(){ const {overrideReason,overridePin}=this.state; return React.createElement("div",{key:"om",style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50}},
    React.createElement("div",{style:{width:360,background:"#fff",borderRadius:14,padding:20}},[
      React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"Price Override - Approval Required at Checkout"),
      React.createElement("textarea",{key:"r",value:overrideReason,onChange:this.setOverrideReason,placeholder:"Reason for override (required)",style:{width:"100%",padding:"9px 10px",border:"1px solid "+COLORS.border,borderRadius:8,marginBottom:12,minHeight:60}}),
      React.createElement("div",{key:"a",style:{display:"flex",gap:8}},[React.createElement("button",{key:"c",onClick:this.closeOverride,style:{flex:1,padding:10,background:"#f7f9fc",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Cancel"),React.createElement("button",{key:"a",onClick:this.confirmOverride,style:{flex:1,padding:10,background:COLORS.brand,color:"#fff",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Confirm Override")]),
    ])); }
  buildTransactionView(t,exchange=false){
    const h=React.createElement;
    const amount=value=>Number(value||0).toLocaleString("en-PH",{minimumFractionDigits:2,maximumFractionDigits:2});
    const row=(label,value,extra="")=>h("div",{key:label,className:"receipt-row "+extra},[h("span",{key:"label"},label),h("span",{key:"value"},value)]);
    return h("div",{className:"receipt-wrap"},[
      h("article",{key:"paper",className:"grocery-receipt","aria-label":"Sales receipt"},[
        h("header",{key:"header",className:"receipt-header"},[
          h("h2",{key:"brand"},"KITA"),h("div",{key:"title"},"SALES RECEIPT"),
        ]),
        h("div",{key:"meta",className:"receipt-section"},[
          row("Receipt no.",t.uuid),row("Date",t.date),row("Cashier",t.cashier),
        ]),
        h("div",{key:"items",className:"receipt-section"},[
          row("ITEM / QTY x PRICE","AMOUNT","receipt-column-head"),
          ...t.cart.map((line,i)=>h("div",{key:i,className:"receipt-item"},[
            h("div",{key:"name",className:"receipt-item-name"},line.name),
            row(line.qty+" x "+amount(line.unitPrice),amount(line.qty*line.unitPrice)),
          ])),
        ]),
        h("div",{key:"totals",className:"receipt-section"},[
          row("Subtotal",amount(t.totals.subtotal)),row("Discount","-"+amount(t.totals.discount)),
          row("TOTAL (\u20B1)",amount(t.totals.grandTotal),"receipt-total"),
          row("Items purchased",String(t.cart.reduce((sum,line)=>sum+Number(line.qty),0))),
        ]),
        h("div",{key:"payment",className:"receipt-section"},[
          row("Payment method",t.paymentMode),
          t.exchangeOriginalUuid?row("Original purchase",t.exchangeOriginalUuid):null,
          t.exchangeOriginalUuid?row("Exchange credit",amount(t.exchangeCredit)):null,
          row(t.exchangeOriginalUuid?"Cash received":"Amount tendered",amount(Number(t.tendered)-Number(t.exchangeCredit||0))),
          row("Amount paid",amount(t.paid)),row("CHANGE (\u20B1)",amount(t.change),"receipt-emphasis"),
          t.referenceNo?row("Reference",t.referenceNo):null,
        ]),
        h("div",{key:"tax",className:"receipt-section receipt-tax"},[
          row("VAT-exempt sales",amount(t.totals.vatExempt)),
        ]),
        h("footer",{key:"footer",className:"receipt-footer"},[
          h("strong",{key:"thanks"},"THANK YOU FOR SHOPPING!"),
          h("div",{key:"keep"},"Please keep this receipt."),h("div",{key:"visit"},"We look forward to your next visit."),
        ]),
      ]),
      btn('Print receipt',()=>window.print()),
      h("button",{key:"new",onClick:exchange?()=>this.setState({exchangeReceiptOpen:false}):this.newSale,className:"primary-button receipt-new-sale"},exchange?"Back to returns":"Start New Sale"),
    ]);
  }

  buildRefunds(){
    const {data,refundTxn,refundLookup,refundError}=this.state;
    if(!data) return null;
    if(this.state.exchangeReceiptOpen&&this.state.exchangeTransaction){const t=this.state.exchangeTransaction;return this.buildTransactionView({...t,cart:t.lines,totals:{subtotal:t.subtotal,discount:t.discountAmount,grandTotal:t.total,vatExempt:t.total}},true);}
    const field=(key,label,type="text")=>React.createElement("input",{key,type,placeholder:label,"aria-label":label,value:this.state[key]||"",onChange:e=>this.setState({[key]:e.target.value}),maxLength:type==="password"?4:255,...(type==="number"?{min:0,step:"0.01"}:{}),inputMode:type==="password"?"numeric":undefined,autoComplete:"off",style:{padding:10,marginBottom:10,width:"100%",boxSizing:"border-box"}});
    return React.createElement("div",null,[
      moduleTitle("Refunds, Exchanges & Voids"),
      card([field("refundLookup","Transaction number"),btn("Look Up",this.doRefundLookup,"primary")],{marginBottom:16}),
      refundError?React.createElement("p",{key:"error",role:"alert",style:{color:COLORS.red}},refundError):null,
      refundTxn?card([
        React.createElement("h3",{key:"transaction"},refundTxn.uuid+" ? "+peso(refundTxn.total)+" ? "+refundTxn.status),
        this.state.approvalResult?React.createElement("p",{key:"result"},this.state.approvalResult):null,
        this.state.exchangeTransaction?btn('View replacement receipt',()=>this.setState({exchangeReceiptOpen:true})):null,
        ["Unused","Paid","Partially Refunded"].includes(refundTxn.status)?React.createElement("div",{key:"actions"},[
          React.createElement("p",{key:"choose"},"Choose what you want to do with this transaction:"),
          React.createElement("div",{key:"choices",style:{display:"flex",gap:8,marginBottom:16}},
            (refundTxn.status==="Partially Refunded"||refundTxn.paymentMode!=="Cash"?["refund"]:["refund","exchange","void"]).map(action=>btn(action.charAt(0).toUpperCase()+action.slice(1),()=>this.chooseRefundAction(action),this.state.refundAction===action?"primary":undefined))),
          this.state.refundAction?React.createElement("div",{key:"selected-action"},[
          React.createElement("h4",{key:"action-title"},this.state.refundAction.charAt(0).toUpperCase()+this.state.refundAction.slice(1)+" details"),
          this.state.refundAction==="void"?React.createElement("p",{key:"void-note"},"Only same-day transactions can be voided."):null,
          field("refundClass","Reason for "+this.state.refundAction),
          this.state.refundAction==="refund"?React.createElement("div",{key:"return-lines"},[
            ...(refundTxn.lines||[]).map(line=>React.createElement("label",{key:line.productId,style:{display:"block",marginBottom:10}},[line.name+" - "+(line.qty-line.refundedQty)+" refundable",React.createElement("input",{type:"number",min:0,max:line.qty-line.refundedQty,step:1,"aria-label":"Refund quantity for "+line.name,value:this.state.refundQuantities?.[line.productId]||"",onChange:e=>{const v=e.target.value;this.setState(s=>({refundQuantities:{...s.refundQuantities,[line.productId]:v},returnRequestKey:null}));},style:{padding:8,marginLeft:10,width:80}})])),
            !(refundTxn.lines||[]).length?React.createElement("p",{key:"legacy"},"This legacy transaction has no saved item details. Manual reconciliation is required."):null,
            React.createElement("label",{key:"restock"},[React.createElement("input",{type:"checkbox",checked:this.state.refundRestock!==false,onChange:e=>this.setState({refundRestock:e.target.checked})}),"Receive returned items into stock; expired/recalled batches remain blocked"]),
            refundTxn.paymentMode!=="Cash"?field("providerRefundId","Successful PayMongo refund reference"):null,
          ]):null,
          React.createElement("p",{key:"instructions"},"The manager on duty must select their name and personally enter their approval PIN at this counter."),
          React.createElement("select",{key:"manager","aria-label":"Approving manager",value:this.state.approvalManagerId||"",onChange:e=>this.setState({approvalManagerId:e.target.value,approvalPin:""}),style:{padding:10,marginBottom:10,width:"100%"}},[
            React.createElement("option",{key:"empty",value:""},"Select approving manager"),
            ...(data.USERS?.manager||[]).filter(m=>m.status==="Active").map(manager=>React.createElement("option",{key:manager.id,value:manager.id},manager.name)),
          ]),
          field("approvalPin","Manager 4-digit PIN","password"),
          this.state.refundAction==="exchange"?React.createElement("div",{key:"exchange-details"},[React.createElement("p",null,"Exchange the full cash purchase for one replacement of equal or higher value. Do not restock damaged goods. Expired or recalled goods remain blocked if physically returned."),React.createElement("label",null,[React.createElement("input",{type:"checkbox",checked:this.state.refundRestock!==false,onChange:e=>this.setState({refundRestock:e.target.checked})}),"Receive returned items into physical stock"]),React.createElement("select",{key:"replacement","aria-label":"Exchange replacement",value:this.state.exchangeReplacement||"",onChange:this.setExchangeReplacement,style:{padding:10,marginBottom:16,width:"100%"}},[
            React.createElement("option",{key:"empty",value:""},"Select exchange replacement"),
            ...data.PRODUCTS.filter(p=>p.status==="Active"&&!p.archivedAt&&this.sellableStock(p)>0&&Number(p.price)>=Number(refundTxn.total)).map(p=>React.createElement("option",{key:p.id,value:p.id},p.name+" - "+peso(p.price))),
          ]),React.createElement("p",null,"Purchase credit: "+peso(refundTxn.total)+" | Cash difference: "+peso(Math.max(0,Number(data.PRODUCTS.find(p=>String(p.id)===String(this.state.exchangeReplacement))?.price||0)-Number(refundTxn.total)))),field("exchangeTendered","Cash received for price difference","number")]):null,
          btn("Approve "+this.state.refundAction.charAt(0).toUpperCase()+this.state.refundAction.slice(1),()=>this.submitManagerAction(this.state.refundAction),this.state.refundAction==="void"?"danger":"primary"),
          ]):null,
        ]):null,
      ]):null,
    ]);
  }
  buildShift(){
    const {data}=this.state;
    if(!data) return card(loadingStatus("Loading shift transactions..."));
    const me=this.currentUser();
    const today=data.BUSINESS_DATE;
    const transactions=(data.TRANSACTIONS||[]).filter(t=>t.date===today && (t.cashierId!=null?String(t.cashierId)===String(this.state.authenticatedUser?.id)&&String(t.cashierRole).toLowerCase().replace(/[ _-]/g,"")===this.state.role:t.cashierEmail?t.cashierEmail===me.email:t.cashier===me.name));
    const sales=transactions.filter(t=>["Unused","Paid"].includes(t.status));
    const total=sales.reduce((sum,t)=>sum+Number(t.total),0);
    const rows=transactions.map(t=>tr([td(t.uuid),td(t.date),td(t.paymentMode),td(badge(t.status)),td(peso(t.total)),td(t.status==="Pending Payment"?React.createElement("a",{href:"/payment/cancelled?uuid="+encodeURIComponent(t.uuid),style:{color:COLORS.brand,fontWeight:700}},"Review payment"):null)],t.uuid));
    return React.createElement("div",null,[
      moduleTitle("My Shift Transactions",`${me.name} ? Today, ${today||""}`),
      btn("Refresh transactions",()=>this.reloadCatalog().catch(()=>this.toast("Could not refresh shift transactions.","error"))),
      React.createElement("div",{style:{display:"flex",gap:14,marginTop:16,marginBottom:16}},[
        kpi("Transactions Today",transactions.length),kpi("Completed Sales",sales.length),kpi("Total Sales",peso(total),null,COLORS.green),
      ]),
      transactions.length?this.recordTable("shift",["Receipt","Date","Payment","Status","Amount","Action"],rows):card("No transactions recorded for you today."),
    ]);
  }

  // ---- Manager screens ----
  managerLowStock=()=> (this.state.data?.PRODUCTS||[]).filter(p=>p.status==='Active'&&!p.archivedAt&&Number(p.stock)<=Number(p.minStock))
    .sort((a,b)=>Number(a.stock)-Number(b.stock)||a.name.localeCompare(b.name));
  purchaseLowStock=productId=>()=>{
    const product=this.managerLowStock().find(p=>String(p.id)===String(productId));
    if(!product){this.toast('This product is no longer available in the low-stock list.','warn');return;}
    const supplier=(this.state.data.SUPPLIERS||[]).find(s=>String(s.id)===String(product.supplierId)&&s.status==='Active'&&!s.archivedAt);
    const cart=this.state.newReqCart||[];
    const selected=this.state.newReqSupplier||cart[0]?.supplierId||'';
    if(cart.length&&supplier&&selected&&String(selected)!==String(supplier.id)){
      this.toast('Your draft contains items for another supplier. Submit or clear that request first.','warn');
      this.goScreen('mgrRequest')();return;
    }
    const supplierId=cart.length?selected:(supplier?.id||'');
    const exists=cart.some(line=>String(line.productId)===String(product.id));
    const qty=Math.min(1000000,Math.max(1,Math.ceil(Number(product.minStock)-Number(product.stock)+1)));
    this.setState({newReqSupplier:String(supplierId),newReqCart:exists?cart:[...cart,{
      productId:product.id,name:product.name,category:product.category,unit:product.stockUnit||product.unit,qty,supplierId
    }],purchaseProductOpen:false,itemPickerOpen:false});
    this.goScreen('mgrRequest')();
    this.toast(exists?'This product is already in your purchase request.':supplierId?'Product added. Review the quantity before submitting.':'Product added. Select a supplier before submitting.');
  };
  buildDashboardSalesFilter(sales){
    const h=React.createElement;
    return h("div",{className:"dashboard-sales-filter"},[
      h("div",{key:"buttons",className:"dashboard-period-tabs",role:"group","aria-label":"Sales period"},["daily","weekly","monthly","yearly"].map(value=>h("button",{key:value,type:"button","aria-pressed":this.state.dashboardPeriod===value,onClick:()=>this.setState({dashboardPeriod:value})},value[0].toUpperCase()+value.slice(1)))),
      h("label",{key:"period",className:"dashboard-period-select"},["Sales period ",h("select",{value:this.state.dashboardPeriod,onChange:e=>this.setState({dashboardPeriod:e.target.value})},
        ["daily","weekly","monthly","yearly"].map(value=>h("option",{key:value,value},value[0].toUpperCase()+value.slice(1))))]),
      h("label",{key:"date"},["Date in period ",h("input",{type:"date",value:this.state.dashboardDate||this.state.data.BUSINESS_DATE||"",onChange:e=>this.setState({dashboardDate:e.target.value})})]),
      h("span",{key:"range",role:"status"},sales.range+(this.state.dashboardPeriod==="weekly"?" (Monday–Sunday)":"")),
    ]);
  }
  buildProductComparison(sales){
    const h=React.createElement, quantities=new Map();
    sales.rows.forEach(row=>quantities.set(String(row.productId),(quantities.get(String(row.productId))||0)+Number(row.qty)));
    const ranked=[...quantities].map(([id,qty])=>({id,qty,name:this.state.data.PRODUCTS.find(p=>String(p.id)===id)?.name||'Product #'+id})).filter(p=>p.qty>0).sort((a,b)=>b.qty-a.qty||a.name.localeCompare(b.name));
    const top=ranked.slice(0,Math.min(3,Math.ceil(ranked.length/2))),topIds=new Set(top.map(p=>p.id));
    const least=ranked.filter(p=>!topIds.has(p.id)).sort((a,b)=>a.qty-b.qty||a.name.localeCompare(b.name)).slice(0,3);
    return card([
      h('div',{className:'dashboard-panel-heading'},[h('div',null,[h('h2',{className:'panel-title'},'Product Comparison'),null]),h('div',{className:'comparison-legend'},[h('span',{className:'comparison-top'},'Top selling'),h('span',{className:'comparison-least'},'Least selling')])]),
      h('div',{className:'product-comparison-bars'},top.length?top.map((a,i)=>{const b=least[i],share=a.qty/(a.qty+(b?.qty||0))*100;return h('div',{key:a.id,className:'comparison-pair'},[
        h('div',{className:'comparison-labels'},[h('span',null,a.name+' ('+a.qty+')'),h('span',null,b?b.name+' ('+b.qty+')':'No comparison product')]),
        h('div',{className:'comparison-track',role:'img','aria-label':a.name+': '+a.qty+' units'+(b?'; '+b.name+': '+b.qty+' units':'' )},[h('span',{className:'comparison-top-bar',style:{width:share+'%'}},Math.round(share)+'%'),b?h('span',{className:'comparison-least-bar',style:{width:(100-share)+'%'}}):null])
      ]);}):h('p',{className:'record-empty'},'No sales recorded for this period.')),
      h('div',{className:'comparison-footer'},[h('span',null,'Units sold in selected period'),h('span',null,least.length+' pairs compared')])
    ]);
  }
  buildMgrDashboard(){ return this.buildOperationsDashboard(true); }
  buildOperationsDashboard(manager){
    const {data}=this.state; if(!data)return null;
    const h=React.createElement;
    const sales=dashboardSales(data,this.state.dashboardPeriod,this.state.dashboardDate);
    const lowStock=this.managerLowStock();
    const heading=(title,description,extra)=>h('div',{className:'dashboard-panel-heading'},[h('div',null,[h('h2',{className:'panel-title'},title),null]),extra]);
    return h('div',{className:'operations-dashboard'},[
      h('header',{key:'header',className:'dashboard-header'},[
        moduleTitle('Analytics Overview'),
        this.buildDashboardSalesFilter(sales),
        h('button',{type:'button',className:'kita-button',disabled:!!this.state.pendingLoads,onClick:()=>this.reloadCatalog().catch(error=>this.toast(error.message,'error'))},this.state.pendingLoads?'Refreshing...':'Refresh dashboard'),
      ]),
      h('div',{key:'metrics',className:'dashboard-metrics'},[
        kpi('Recorded Sales',peso(sales.total),sales.range,COLORS.brand),
        kpi('Units Sold',sales.units,'For the selected sales period'),
        kpi('Low Stock Items',lowStock.length,lowStock.length?'At or below minimum stock':'Stock levels above minimum',lowStock.length?COLORS.red:COLORS.green),
      ]),
      h('div',{key:'charts',className:'dashboard-chart-grid'},[
        salesHistoryChart(sales,this.state.dashboardPeriod),
        this.buildProductComparison(sales),
      ]),
      h('div',{key:'operations',className:'dashboard-operations-grid'+(manager?' dashboard-operations-manager':'')},[
        card([heading('Low Stock Products','Current inventory at or below the reorder threshold',badge(lowStock.length+' items')),
          h('div',{className:'dashboard-records',tabIndex:0,'aria-label':'Low stock products'},lowStock.length?lowStock.map(p=>h('div',{key:p.id,className:'dashboard-record'},[
            h('div',{className:'dashboard-record-main'},[h('strong',null,p.name),h('p',null,['Stock: ',h('b',{className:'dashboard-stock-alert'},String(p.stock)),' / Minimum: '+p.minStock+' '+(p.stockUnit||p.unit||'')]),h('small',null,(data.SUPPLIERS||[]).find(s=>String(s.id)===String(p.supplierId))?.name||'Supplier not assigned')]),
            manager?btn('Purchase',this.purchaseLowStock(p.id),'primary'):badge('Low stock'),
          ])):h('p',{role:'status',className:'record-empty'},'No low-stock products.')),
        ]),
        this.buildNearExpiredWidget(manager),
        manager?h('section',{className:'dashboard-pin-panel'},[h('div',{className:'dashboard-pin-caption'},'Manager approval PIN settings'),this.buildManagerPin()]):null,
      ]),
    ]);
  }
  expiringBatches=()=> (this.state.data?.EXPIRING_BATCHES||[]).map(b=>({p:{...b,id:b.productId,batch:b.batchNumber||b.id,lot:b.lotNumber||'-',stock:b.quantityRemaining},days:Math.round((new Date(b.expiryDate)-new Date(this.state.data.BUSINESS_DATE))/86400000)}));
  buildNearExpiredWidget(manager){
    const h=React.createElement, soon=this.expiringBatches();
    return card([
      h('div',{className:'dashboard-panel-heading'},[
        h('div',null,[h('h2',{className:'panel-title'},'Near-Expired Products'),null]),
        badge(soon.length+' batches'),
      ]),
      h('div',{className:'dashboard-records',tabIndex:0,'aria-label':'Near-expired products'},soon.length?soon.map((x,i)=>h('div',{key:x.p.batch+'-'+i,className:'dashboard-record'},[
        h('div',{className:'dashboard-record-main'},[
          h('strong',null,x.p.name),
          h('p',null,'Stock: '+x.p.stock+' / Expiry: '+x.p.expiryDate),
          h('small',null,'Batch: '+x.p.batch),
          h('p',{className:x.days<=0?'dashboard-stock-alert':null},x.days<=0?'Expired - blocked from sale':x.days+' day(s) left'),
        ]),
        manager&&x.days>0?btn('Create promotion',this.quickPromoFromNearExpiry(x.p.id),'primary'):badge(x.days<=0?'Expired':'Near expiry'),
      ])):h('p',{role:'status',className:'record-empty'},'Nothing near expiry.')),
      manager?btn('View Near-Expiry Monitoring',()=>{this.setState({screen:'mgrInventory',invTab:'expiry'});this.loadInventoryWorkflows();}):null,
    ]);
  }
  reloadPurchasing=async()=>{
    const requestId=(this.purchaseLoadId||0)+1;this.purchaseLoadId=requestId;
    this.setState({purchaseLoading:true,purchaseError:""});
    try{
      const response=await fetch('/api/purchasing',{headers:{Accept:'application/json'},credentials:'same-origin'});
      if(!(response.headers.get('content-type')||'').includes('application/json')){
        if([502,503,504].includes(response.status))throw new Error('The server is taking too long to load purchase records. Please retry in a moment.');
        throw new Error('Unable to load purchase records (HTTP '+response.status+'). Please refresh and try again.');
      }
      const body=await response.json();this.handleSessionResponse(response,body);
      if(!response.ok)throw new Error(body.message||'Unable to load purchasing records.');
      if(this.purchaseLoadId===requestId)this.setState({purchaseData:body});return body;
    }catch(error){if(this.purchaseLoadId===requestId)this.setState({purchaseError:error.message});throw error;}
    finally{if(this.purchaseLoadId===requestId)this.setState({purchaseLoading:false});}
  };
  purchasingState(){
    const h=React.createElement;
    if(this.state.purchaseError)return h('div',{role:'alert',className:'alert'},[this.state.purchaseError,btn('Retry',()=>this.reloadPurchasing().catch(()=>{}))]);
    if(!this.state.purchaseData)return h('p',{role:'status'},[loadingStatus('Loading purchase records...'),btn('Load records',()=>this.reloadPurchasing().catch(()=>{}))]);
    return null;
  }
  purchaseBatchSummary=id=>{const order=this.state.purchaseData?.orders.find(o=>o.id===id);return [...new Set((order?.receipts||[]).flatMap(r=>r.lines||[]).map(l=>l.batchNumber||l.lotNumber).filter(Boolean))].join(', ')||'Assigned when received';};
  purchaseReportLink=id=>React.createElement('a',{className:'kita-button',href:`/purchase-orders/${encodeURIComponent(id)}/report`,target:'_blank',rel:'noopener'},'View / Print Report');
  purchaseSupplier=id=>(this.state.data?.SUPPLIERS||[]).find(s=>String(s.id)===String(id))?.name||'Not recorded';
  purchaseUnit=line=>line.unit||(this.state.data?.PRODUCTS||[]).find(p=>p.id===line.productId)?.stockUnit||(this.state.data?.PRODUCTS||[]).find(p=>p.id===line.productId)?.unit||'Not recorded';
  purchaseFilters(receiving=false){
    const h=React.createElement;
    return h('div',{className:'purchase-filters'},[
      h('label',null,['Supplier',h('select',{value:this.state.purchaseSupplierFilter||'',onChange:e=>this.setState({purchaseSupplierFilter:e.target.value})},[h('option',{value:''},'All suppliers'),...(this.state.data?.SUPPLIERS||[]).map(s=>h('option',{key:s.id,value:s.id},s.name))])]),
      !receiving?h('label',null,['Status',h('select',{value:this.state.purchaseStatusFilter||'',onChange:e=>this.setState({purchaseStatusFilter:e.target.value})},['','Pending Approval','Approved / Waiting for Delivery','Declined','Completed','Partially Received','Fully Received','Received with Discrepancy','Pending','Approved','Draft','Closed'].map(s=>h('option',{key:s,value:s},s||'All PO Statuses')))]):null,
      h('label',null,['PO date from',h('input',{type:'date',value:this.state.purchaseFrom||'',onChange:e=>this.setState({purchaseFrom:e.target.value})})]),
      h('label',null,['PO date to',h('input',{type:'date',value:this.state.purchaseTo||'',onChange:e=>this.setState({purchaseTo:e.target.value})})]),
      receiving?this.buildReceivingAuditFilter():null,
      btn('Clear filters',()=>this.setState({purchaseSupplierFilter:'',purchaseStatusFilter:'',purchaseFrom:'',purchaseTo:'',receivingAudit:''})),
      h('button',{className:'kita-button',disabled:!!this.state.purchaseLoading,'aria-busy':!!this.state.purchaseLoading,onClick:()=>this.reloadPurchasing().catch(()=>{})},this.state.purchaseLoading?'Refreshing...':'Refresh')
    ]);
  }
  filterPurchases=(records,ignoreStatus=false)=>records.filter(r=>{
    const supplier=r.supplierId??r.lines?.[0]?.supplierId,date=String(r.request?.dateRequested||r.dateRequested||r.created||'').slice(0,10);
    return (!this.state.purchaseSupplierFilter||String(supplier)===this.state.purchaseSupplierFilter)&&(ignoreStatus||!this.state.purchaseStatusFilter||r.status===this.state.purchaseStatusFilter)&&(!this.state.purchaseFrom||date>=this.state.purchaseFrom)&&(!this.state.purchaseTo||date<=this.state.purchaseTo);
  });
  submitPurchase=async()=>{
    if(this.purchaseBusy)return;
    const supplierId=Number(this.state.newReqSupplier);
    if(!Number.isInteger(supplierId)||supplierId<=0){this.toast('Select a supplier before submitting the purchase request.','error');return;}
    const lines=this.state.newReqCart.map(l=>({productId:l.productId,qty:Number(l.qty),unitCost:l.unitCost??(this.state.data.PRODUCTS.find(p=>p.id===l.productId)?.unitPrice||0),supplierId,orderUnit:l.orderUnit||this.purchaseUnit(l),...(this.requestUsesPackage(l)?{stockUnitsPerOrder:Number(l.stockUnitsPerOrder??l.piecesPerBox)}:{})}));
    if(lines.some(l=>Object.hasOwn(l,'stockUnitsPerOrder')&&(!Number.isInteger(l.stockUnitsPerOrder)||l.stockUnitsPerOrder<1||l.qty*l.stockUnitsPerOrder>1000000))){this.toast('Enter a positive whole number of stock units per package. Total stock units cannot exceed 1,000,000.','error');return;}
    if(!lines.length||lines.some(l=>!l.supplierId||!Number.isInteger(l.qty)||l.qty<=0)||new Set(lines.map(l=>l.supplierId)).size!==1){this.toast('Add positive whole quantities and select one supplier for this PO.','error');return;}
    this.purchaseBusy=true;
    // The database assigns the PO number; retain a separate retry key after network errors.
    this.purchaseSubmissionKey=this.purchaseSubmissionKey||crypto.randomUUID();
    try{
      const result=await this.authPost('/api/purchase-requests',{submissionKey:this.purchaseSubmissionKey,category:this.state.newReqCart[0].category,notes:this.state.purchaseNotes||'',expectedDeliveryDate:this.state.purchaseExpectedDate||null,lines});
      this.purchaseSubmissionKey=null;this.setState({newReqCart:[],purchaseNotes:'',purchaseExpectedDate:'',screen:'mgrRequestView'});this.toast(result.message+' '+result.id);
      await this.reloadPurchasing();
    }catch(error){this.toast(error.message,'error');}finally{this.purchaseBusy=false;}
  };
  savePurchaseSupplier=async()=>{
    if(this.supplierSaving)return;this.supplierSaving=true;
    try{const result=await this.authPost('/api/suppliers',this.state.purchaseSupplierDraft||{});this.setState({newReqSupplier:String(result.supplier.id),purchaseSupplierOpen:false,purchaseSupplierDraft:{}});await this.reloadCatalog();this.toast(result.message);}
    catch(error){this.toast(error.message,'error');}finally{this.supplierSaving=false;}
  };
  purchaseQuickForm(kind){
    const h=React.createElement,f=this.state.purchaseSupplierDraft||{};
    return card([sectionTitle('Add Supplier'),...['name','contact','phone','email','address'].map(name=>h('label',{key:name},[name,h('input',{value:f[name]||'',onChange:e=>this.setState({purchaseSupplierDraft:{...f,[name]:e.target.value}})})])),btn('Save Supplier',this.savePurchaseSupplier),btn('Cancel',()=>this.setState({purchaseSupplierOpen:false}))]);
  }
  buildPurchaseRequests(){
    const h=React.createElement,s=this.state,tab=s.screen==='mgrRequestView'?'view':s.screen==='mgrPurchaseHistory'?'history':'create';
    if(tab==='history')return this.buildPurchaseHistory();
    if(tab==='view'){
      const state=this.purchasingState();if(state)return state;
      return h('div',null,[moduleTitle('Purchase Orders'),this.purchaseFilters(),
        this.recordTable('purchase-requests',['Purchase ID','PO Date','Supplier','Items','Received batches','Status','Admin notes / decline reason','Actions'],this.filterPurchases(s.purchaseData.requests).map(r=>tr([td(r.id),td(r.requested_at||r.dateRequested),td(r.supplierName||this.purchaseSupplier(r.lines[0]?.supplierId)),td(r.lines.length),td(this.purchaseBatchSummary(r.poId||r.id)),td(badge(r.status)),td(r.disapprovalReason||r.adminNote||''),td(this.purchaseRequestActions(r))],r.id))),
        this.purchaseRequestDetails(),this.purchaseReviewDetails()
      ]);
    }
    if(!s.data)return h('p',null,loadingStatus('Loading inventory and suppliers...'));
    return h('div',{className:'purchase-create-page'},[
      h('header',{className:'purchase-create-header'},[moduleTitle('Create Purchase Order'),btn('+ Add Items',this.openItemPicker,'primary')]),
      h('div',{className:'purchase-create-summary'},[h('div',null,[h('small',null,'SUPPLIER'),h('strong',null,s.newReqCart.length&&s.newReqSupplier?this.purchaseSupplier(s.newReqSupplier):'Choose a supplier when adding items')]),h('div',null,[h('small',null,'REQUEST ITEMS'),h('strong',null,s.newReqCart.length+' item'+(s.newReqCart.length===1?'':'s'))])]),
      this.recordTable('purchase-cart',['Item','Category','Quantity','Purchase Unit','Cost per Stock Unit (PHP)','Action'],s.newReqCart.map((l,i)=>tr([td(l.name),td(l.category),td(h('input',{'aria-label':'Quantity for '+l.name,type:'number',min:1,step:1,value:l.qty,onChange:this.setReqCartQty(i)})),td(this.buildRequestUnit(l,i)),td(h('input',{'aria-label':'Cost per stock unit for '+l.name,type:'number',min:0,step:'0.01',value:l.unitCost??(s.data.PRODUCTS.find(p=>p.id===l.productId)?.unitPrice||0),onChange:e=>this.setState({newReqCart:s.newReqCart.map((line,n)=>n===i?{...line,unitCost:e.target.value}:line)})})),td(btn('Remove',this.removeReqLine(i)))],i))),
      h('label',{className:'purchase-notes'},['Expected delivery date (optional)',h('input',{type:'date',value:s.purchaseExpectedDate||'',min:s.data?.BUSINESS_DATE,onChange:e=>this.setState({purchaseExpectedDate:e.target.value})})]),
      h('label',{className:'purchase-notes'},['Purchase notes',h('textarea',{value:s.purchaseNotes||'',maxLength:2000,onChange:e=>this.setState({purchaseNotes:e.target.value})})]),
      h('footer',{className:'purchase-create-footer'},[h('span',null,'Stock is updated only after the approved order is received.'),btn('Submit Purchase Order',this.submitPurchase,'primary')]),
      s.itemPickerOpen?this.buildItemPickerModal():null,s.categoryModalOpen?this.buildCategoryModal():null
    ]);
  }
  purchaseRequestDetails(){
    const h=React.createElement,r=this.state.purchaseData?.requests.find(r=>r.id===this.state.purchaseRequestDetail);if(!r)return null;
    const close=()=>this.setState({purchaseRequestDetail:null});
    const units=[...new Set(r.lines.map(l=>this.requestUnitLabel(l)))],mixed=units.length>1;
    const unitHeading=units.join(' / ')||'Pieces';
    return h('dialog',{className:'purchase-details-dialog','aria-labelledby':'purchase-details-title',
      ref:node=>{if(node&&!node.open)node.showModal();},
      onCancel:e=>{e.preventDefault();close();},
      onClick:e=>{if(e.target===e.currentTarget){const rect=e.currentTarget.getBoundingClientRect();if(e.clientX<rect.left||e.clientX>rect.right||e.clientY<rect.top||e.clientY>rect.bottom)close();}}
    },[h('h2',{id:'purchase-details-title'},r.id),h('p',null,`${r.supplierName||this.purchaseSupplier(r.lines[0]?.supplierId)} | ${r.status} | PO Date: ${r.requested_at||r.dateRequested} | Requested by ${r.requestedBy}`),
      h('div',{className:'purchase-details-table'},table(['Item','Category','Reviewed quantity',unitHeading],r.lines.map(l=>tr([td(l.name),td(l.category),td(l.confirmedQty??l.qty),td(this.requestUnitSummary(l,mixed))],l.id)))),h('p',null,r.notes||''),h('p',null,r.disapprovalReason||r.adminNote||''),h('div',{className:'dialog-actions'},[h('button',{type:'button',className:'kita-button kita-button--primary',autoFocus:true,onClick:close},'Close details'),r.poId?this.purchaseReportLink(r.poId):null])]);
  }
  requestUnitLabel=line=>{const unit=line.orderUnit||this.purchaseUnit(line);return ['piece','pieces'].includes(unit.toLowerCase())?'Pieces':unit;};
  requestUnitSummary=(line,mixed=false)=>{
    const h=React.createElement,quantity=Number(line.confirmedQty??line.qty),unit=line.orderUnit||this.purchaseUnit(line),factor=Number(line.piecesPerBox);
    if(this.requestUsesPackage(line)&&factor>0){
      const packages=Math.floor(quantity/factor),remainder=quantity%factor;
      return h('div',null,[h('div',null,packages+' '+unit+(remainder?' + '+remainder+' '+this.purchaseUnit(line):'')),h('small',null,factor+' '+this.purchaseUnit(line)+' per '+unit)]);
    }
    const label=['piece','pieces'].includes(unit.toLowerCase())?'Pieces':unit;
    return h('div',null,[h('div',null,mixed?quantity+' '+label.toLowerCase():quantity)]);
  };
  reviewPurchase=async(record,action)=>{
    if(this.reviewBusy)return;
    const note=this.state.approvalNoteDraft[record.id]||'';
    if(action==='disapproved'&&!note.trim()){this.toast('Enter a decline reason.','error');return;}
    const lines=(this.state.purchaseReviewId===record.id?this.state.purchaseReviewLines:record.lines).map(l=>({productId:l.productId,qty:Number(l.confirmedQty??l.qty)}));
    const edited=lines.length!==record.lines.length||lines.some(l=>{const original=record.lines.find(p=>p.productId===l.productId);return !original||Number(original.confirmedQty??original.qty)!==l.qty;});
    if(action!=='disapproved'&&edited&&!note.trim()){this.toast('Enter a reason for changing purchase quantities or removing items.','error');return;}
    if(action!=='disapproved'&&(!lines.length||lines.some(l=>!Number.isInteger(l.qty)||l.qty<=0))){this.toast('Keep at least one item with positive whole quantities.','error');return;}
    this.reviewBusy=true;this.setState({purchaseReviewSaving:record.id,purchaseReviewAction:action});
    try{const result=await this.authPost(`/api/purchase-requests/${encodeURIComponent(record.id)}`,{action,note,revision:record.revision,...(action==='disapproved'?{}:{lines})},'PATCH');this.setState({purchaseReviewId:null,purchaseReviewRecord:null,purchaseReviewLines:[]});this.toast(result.message);await Promise.all([this.reloadPurchasing(),this.reloadCatalog()]);}
    catch(error){this.toast(error.message,'error');}finally{this.reviewBusy=false;this.setState({purchaseReviewSaving:null,purchaseReviewAction:null});}
  };
  requestReceivingBudget=async(order)=>{
    if(this.budgetBusy)return;
    const draft=this.state.receivingBudgetDraft||{};
    if(!this.validAmount(draft.amount)||Number(draft.amount)<=0||!String(draft.reason||'').trim()){this.setState({receiveError:'Enter the additional amount and explain the price increase.'});return;}
    this.budgetBusy=true;this.setState({budgetSaving:true,receiveError:''});
    try{const result=await this.authPost(`/api/purchase-orders/${encodeURIComponent(order.id)}/budget-requests`,{requestKey:draft.key||crypto.randomUUID(),version:this.state.receiveVersion,amount:Number(draft.amount),reason:draft.reason});this.toast(result.message);await this.reloadPurchasing();}
    catch(error){this.setState({receiveError:error.message});}finally{this.budgetBusy=false;this.setState({budgetSaving:false});}
  };
  reviewReceivingBudget=async(entry,action)=>{
    if(this.budgetBusy)return;
    this.budgetBusy=true;this.setState({budgetSaving:true});
    try{const result=await this.authPost(`/api/purchase-budget-requests/${entry.id}`,{action},'PATCH');this.toast(result.message);await this.reloadPurchasing();}
    catch(error){this.toast(error.message,'error');}finally{this.budgetBusy=false;this.setState({budgetSaving:false});}
  };
  buildReceivingBudgetReviews(){
    const h=React.createElement,entries=(this.state.purchaseData?.orders||[]).flatMap(o=>(o.budgetRequests||[]).filter(r=>r.status==='Pending').map(r=>({...r,order:o})));
    if(!entries.length)return null;
    return h('section',{className:'sa-panel'},[h('h2',null,'Additional receiving amounts'),null,...entries.map(r=>h('div',{key:r.id,className:'purchase-budget-review'},[h('strong',null,r.poId+' · '+peso(r.amount)),h('p',null,r.reason),h('small',null,'Remaining approved balance: '+peso(r.order.receivingBalance)),h('div',{className:'purchase-actions'},['Declined'].map(action=>h('button',{type:'button',className:'kita-button',disabled:!!this.state.budgetSaving,onClick:()=>this.reviewReceivingBudget(r,action)},action==='Approved'?'Approve additional amount':'Decline')))]))]);
  }
  requestUsesPackage=line=>{const stock=this.purchaseUnit(line),unit=line.orderUnit||stock;return unit!==stock&&!(['piece','pieces'].includes(stock.toLowerCase())&&unit==='Piece');};
  canReviewPurchase=r=>['admin','superadmin','super_admin'].includes(this.state.role)&&!r.poId&&['Pending','Pending Approval','Proceed to Purchase'].includes(r.status);
  openPurchaseReview=(r,mode)=>this.setState({purchaseRequestDetail:null,purchaseReviewRecord:r.id,purchaseReviewMode:mode,purchaseReviewId:mode==='edit'?r.id:null,purchaseReviewLines:mode==='edit'?r.lines.map(l=>({...l})):[]});
  purchaseRequestActions(r){
    const h=React.createElement;
    return [btn('View',()=>this.setState({purchaseRequestDetail:r.id})),...(this.canReviewPurchase(r)?[['edit','Edit'],['approved','Approve'],['disapproved','Decline']].map(([mode,label])=>h('button',{type:'button',className:'kita-button',disabled:!!this.state.purchaseReviewSaving,onClick:()=>this.openPurchaseReview(r,mode)},label)):[])];
  }
  purchaseReviewDetails(){
    const h=React.createElement,r=this.state.purchaseData?.requests.find(r=>r.id===this.state.purchaseReviewRecord);
    if(!r||!this.canReviewPurchase(r))return null;
    const mode=this.state.purchaseReviewMode,editing=mode==='edit';
    const close=()=>{if(!this.state.purchaseReviewSaving)this.setState({purchaseReviewRecord:null,purchaseReviewId:null,purchaseReviewLines:[]});};
    return h('dialog',{className:'purchase-details-dialog','aria-labelledby':'purchase-review-title',ref:node=>{if(node&&!node.open)node.showModal();},onCancel:e=>{e.preventDefault();close();}},[
      h('h2',{id:'purchase-review-title'},(editing?'Edit':mode==='approved'?'Approve':'Decline')+' Purchase Order'),
      h('fieldset',{className:'sa-panel purchase-review',disabled:!!this.state.purchaseReviewSaving},[
        h('h2',null,r.id),h('p',null,`${r.supplierName||this.purchaseSupplier(r.lines[0]?.supplierId)} | ${r.requestedBy} | ${r.requested_at||r.dateRequested}`),h('p',null,r.notes||''),
        table(['Item','Category','Requested','Review quantity','Stock Unit','Unit Cost (PHP)','Actions'],(this.state.purchaseReviewId===r.id?this.state.purchaseReviewLines:r.lines).map((l,i)=>tr([td(l.name),td(l.category),td(l.qty),td(this.state.purchaseReviewId===r.id?h('input',{'aria-label':'Review quantity for '+l.name,type:'number',min:1,step:1,value:l.confirmedQty??l.qty,onChange:e=>this.setState(s=>({purchaseReviewLines:s.purchaseReviewLines.map((line,n)=>n===i?{...line,confirmedQty:e.target.value}:line)}))}):l.confirmedQty??l.qty),td(this.purchaseUnit(l)),td(peso(l.unitCost)),td(this.state.purchaseReviewId===r.id?btn('Remove',()=>this.setState(s=>({purchaseReviewLines:s.purchaseReviewLines.filter((_,n)=>n!==i)}))):'')],l.id))),
        h('label',{className:'purchase-notes'},['Review reason (required when changing quantities, removing items, or declining)',h('textarea',{maxLength:2000,placeholder:'Explain why this request needs to change',value:this.state.approvalNoteDraft[r.id]||'',onChange:this.setApprovalNote(r.id)})]),
        h('div',{className:'purchase-actions','aria-busy':!!this.state.purchaseReviewSaving},[
          h('button',{type:'button',className:'kita-button',disabled:!!this.state.purchaseReviewSaving,onClick:close},'Cancel'),
          h('button',{type:'button',className:'kita-button kita-button--primary',disabled:!!this.state.purchaseReviewSaving,onClick:()=>this.reviewPurchase(r,editing?'modify':mode)},this.state.purchaseReviewSaving?'Saving...':editing?'Save edits':mode==='approved'?'Confirm approval':'Confirm decline'),
          this.state.purchaseReviewSaving===r.id?h('span',{role:'status'},'Updating purchase request. Please wait.'):null
        ])
      ])
    ]);
  }
  buildPurchaseReview(){
    const h=React.createElement,state=this.purchasingState();if(state)return state;
    const requests=this.filterPurchases(this.state.purchaseData.requests);
    return h('div',null,[moduleTitle('Purchase Orders'),this.purchaseFilters(),this.buildReceivingBudgetReviews(),this.buildStockReceiptHistory(true),
      this.recordTable('purchase-review',['Purchase ID','PO Date','Supplier','Requested By','Items','Total Cost','Status','Actions'],requests.map(r=>tr([td(r.id),td(r.requested_at||r.dateRequested),td(r.supplierName||this.purchaseSupplier(r.lines[0]?.supplierId)),td(r.requestedBy),td(r.lines.length),td(peso(r.lines.reduce((sum,l)=>sum+Number(l.confirmedQty??l.qty)*Number(l.unitCost||0),0))),td(badge(r.status)),td(this.purchaseRequestActions(r))],r.id))),this.purchaseRequestDetails(),this.purchaseReviewDetails()
    ]);
  }
  openPurchaseReceiving=order=>this.setState({purchaseReceivingId:order.id,receiveError:'',inspectionReview:false,inspection:{requestKey:crypto.randomUUID(),primaryPo:order.id,supplierId:Number(order.supplierId),deliveryDate:this.state.data?.BUSINESS_DATE||new Date().toISOString().slice(0,10),supplierReference:'',notes:'',entries:order.lines.filter(l=>this.carryoverAvailable(l)>0).map(l=>this.newInspectionEntry(order,l,'current'))}});

  receivingDraft=()=>Object.fromEntries(['receiveVersion','receiveQty','receiveDamaged','receiveDamageReasons','receiveSubstitutes','receiveReasons','receivePrices','receiveCosts','receiveExpiries','receiveManufactured','receiveNotes','receivePaymentDueDate','receiveSupplierDocument'].map(k=>[k,this.state[k]]));
  emptyReceivingDraft=order=>({receiveVersion:order.receivingVersion,receiveQty:{},receiveDamaged:{},receiveDamageReasons:{},receiveSubstitutes:{},receiveReasons:{},receivePrices:{},receiveCosts:{},receiveExpiries:{},receiveManufactured:{},receiveNotes:'',receivePaymentDueDate:'',receiveSupplierDocument:''});
  switchReceivingPO=id=>{
    const order=this.state.purchaseData.orders.find(o=>o.id===id);if(!order||this.receivingBusy)return;
    const drafts={...this.state.receivingDrafts,[this.state.purchaseReceivingId]:this.receivingDraft()};
    this.setState({...this.emptyReceivingDraft(order),...(drafts[id]||{}),receivingDrafts:drafts,purchaseReceivingId:id,receiveItemId:null,receiveError:'',receivingBudgetDraft:{key:crypto.randomUUID(),amount:'',reason:''}});
  };
  receivingDeliveryOrders=()=>{
    const drafts={...this.state.receivingDrafts,[this.state.purchaseReceivingId]:this.receivingDraft()},products=this.state.productsLocal||this.state.data?.PRODUCTS||[];
    return Object.entries(drafts).map(([poId,d])=>{
      const order=this.state.purchaseData.orders.find(o=>o.id===poId);if(!order)return null;
      const lines=order.lines.filter(l=>String(d.receiveQty?.[l.productId]??'').trim()!==''||String(d.receiveDamaged?.[l.productId]??'').trim()!=='').map(l=>{
        const id=l.productId,product=products.find(p=>String(p.id)===String(d.receiveSubstitutes?.[id]||id));
        return {productId:Number(id),substituteProductId:Number(d.receiveSubstitutes?.[id])||null,substitutionReason:d.receiveReasons?.[id]||null,qty:Number(d.receiveQty?.[id]||0),damagedQty:Number(d.receiveDamaged?.[id]||0),damageReason:d.receiveDamageReasons?.[id]||null,unitCost:d.receiveCosts?.[id]??l.unitCost,sellingPrice:d.receivePrices?.[id]??product?.price??'',expiryDate:d.receiveExpiries?.[id]||null,manufacturedDate:d.receiveManufactured?.[id]||null};
      }).filter(l=>l.qty!==0||l.damagedQty!==0);
      return {poId,version:d.receiveVersion,notes:d.receiveNotes||'',paymentDueDate:d.receivePaymentDueDate||null,supplierDocument:d.receiveSupplierDocument||null,lines};
    }).filter(o=>o&&o.lines.length);
  };
  confirmPurchaseReceiving=async()=>{
    if(this.receivingBusy)return;
    const order=this.state.purchaseData.orders.find(o=>o.id===this.state.purchaseReceivingId),orders=this.receivingDeliveryOrders(),lines=orders.flatMap(o=>o.lines);
    if(!lines.length){this.setState({receiveError:'Enter accepted or damaged quantities for at least one product.'});return;}
    if(lines.some(l=>!Number.isInteger(l.qty)||l.qty<0||!Number.isInteger(l.damagedQty)||l.damagedQty<0)){this.setState({receiveError:'Quantities must be non-negative whole numbers.'});return;}
    if(lines.some(l=>!this.validAmount(l.unitCost)||!this.validAmount(l.sellingPrice))){this.setState({receiveError:'Enter valid cost and selling prices for all included items.'});return;}
    if(!window.confirm('Confirm this delivery for '+orders.length+' PO(s)? All entered items share one receiving Batch. Each item stays under its original PO. Updated selling prices apply to remaining stock; previous batch costs stay unchanged.'))return;
    this.receivingBusy=true;this.setState({receivingSaving:true,receiveError:''});
    try{
      const result=await this.authPost('/api/receiving-deliveries',{requestKey:this.state.receiveDeliveryKey,supplierId:Number(order.supplierId),receivedDate:this.state.receiveDate,orders});
      this.setState({purchaseReceivingId:null,receivingDrafts:{},lastReceivedPo:order.id,lastDeliveryBatch:result.batchNumber});this.toast(result.message);await Promise.all([this.reloadPurchasing(),this.reloadCatalog()]);
    }catch(error){this.setState({receiveError:error.message});}finally{this.receivingBusy=false;this.setState({receivingSaving:false});}
  };
  receivingTarget=id=>this.state.receiveSubstitutes?.[id]||id;
  receivingStock=id=>Number((this.state.productsLocal||this.state.data?.PRODUCTS||[]).find(p=>String(p.id)===String(this.receivingTarget(id)))?.stock||0);
  receivingCost=id=>this.state.receiveCosts?.[id]??this.state.purchaseData?.orders.find(o=>o.id===this.state.purchaseReceivingId)?.lines.find(l=>String(l.productId)===String(id))?.unitCost??'';
  receivingPrice=id=>this.state.receivePrices?.[id]??((this.state.productsLocal||this.state.data?.PRODUCTS||[]).find(p=>String(p.id)===String(this.receivingTarget(id)))?.price??'');
  buildReceivingSubstitution(line){
    const h=React.createElement,id=line.productId,selected=this.state.receiveSubstitutes?.[id]||'';
    const candidates=(this.state.productsLocal||this.state.data?.PRODUCTS||[]).filter(p=>String(p.id)!==String(id)&&p.status==='Active'&&!p.archivedAt&&Number(p.unitPrice)<=Number(line.unitCost)&&(p.stockUnit||p.unit)===line.unit);
    return h('div',{className:'receiving-substitution'},[
      h('select',{'aria-label':'Substitute for '+line.name,value:selected,disabled:Number(line.deliveredQty||0)>=Number(line.orderedQty),onChange:e=>{const value=e.target.value,p=candidates.find(p=>String(p.id)===value);this.setState(s=>({receiveSubstitutes:{...s.receiveSubstitutes,[id]:value},receiveCosts:{...s.receiveCosts,[id]:p?p.unitPrice:line.unitCost},receivePrices:{...s.receivePrices,[id]:p?p.price:undefined},receiveReasons:{...s.receiveReasons,[id]:value?'missing':''}}));}},[h('option',{value:''},'Receive original product'),...candidates.map(p=>h('option',{key:p.id,value:p.id},p.name+' — '+peso(p.unitPrice)))]),
      selected?h('select',{'aria-label':'Replacement reason for '+line.name,value:this.state.receiveReasons?.[id]||'missing',onChange:e=>this.setState(s=>({receiveReasons:{...s.receiveReasons,[id]:e.target.value}}))},[h('option',{value:'missing'},'Missing item replacement'),h('option',{value:'damaged'},'Damaged item replacement')]):null,
      h('small',null,'Maximum unit cost: '+peso(line.unitCost))
    ]);
  }
  buildReceivingFields(line){
    const h=React.createElement,id=line.productId;
    return [['receiveCosts','Cost price','number'],['receivePrices','Retail price','number'],['receiveExpiries','Expiry date','date'],['receiveManufactured','Manufacturing date (optional)','date'],['receiveLots','Lot number (optional)','text']].map(([field,label,type])=>td(h('input',{
      'aria-label':label+' for '+line.name,type,style:{minWidth:type==='date'?145:120},
      ...(type==='number'?{min:0,step:'0.01'}:{}),...(field==='receiveManufactured'?{max:this.state.receiveDate}:field==='receiveExpiries'?{min:this.state.receiveDate}:{}),
      value:field==='receiveCosts'?this.receivingCost(id):field==='receivePrices'?this.receivingPrice(id):(this.state[field]?.[id]??''),
      onChange:e=>this.setState(s=>({[field]:{...s[field],[id]:e.target.value}}))
    })));
  }
  matchesReceivingAudit(order,filter){
    const receipts=order.receipts||[];
    const missing=receipts.some(r=>{const checks=(this.state.purchaseData?.inspections||[]).filter(i=>i.receiptId===r.id);return checks.length?checks.some(i=>Number(i.missing)>0):/missing/i.test(r.discrepancyType||'')||(!r.discrepancyType&&(order.lines||[]).some(l=>this.receivingOutstanding(l)>0));});
    const damaged=receipts.some(r=>/damag/i.test(r.discrepancyType||'')||(r.lines||[]).some(l=>/damag/i.test(l.conditionText||'')));
    const flagged=missing||damaged||receipts.some(r=>r.discrepancy)||order.status==='Received with Discrepancy';
    return !filter||(filter==='missing'?missing:filter==='damaged'?damaged:filter==='none'?!flagged:flagged);
  }
  receivingOutstanding=line=>Math.max(0,Number(line.orderedQty)-Number(line.deliveredQty||0)-Number(line.resolvedQty||0));
  originalShortage=line=>Math.max(0,this.receivingOutstanding(line)-(this.state.purchaseData?.carryovers||[]).filter(a=>Number(a.targetLine)===Number(line.id)).reduce((sum,a)=>sum+Number(a.quantity)-Number(a.receivedQty)-Number(a.releasedQty),0));
  carryoverAvailable=line=>Math.max(0,this.receivingOutstanding(line)-(this.state.purchaseData?.carryovers||[]).filter(a=>Number(a.sourceLine)===Number(line.id)).reduce((sum,a)=>sum+Number(a.quantity)-Number(a.receivedQty)-Number(a.releasedQty),0));
  saveCarryover=async()=>{
    if(this.carryoverBusy)return;
    const f=this.state.carryoverForm;if(!f)return;
    this.carryoverBusy=true;this.setState({carryoverSaving:true});
    try{const result=await this.authPost('/api/purchase-orders/'+encodeURIComponent(f.sourcePo)+'/carryovers',f);this.setState({carryoverForm:null});await this.reloadPurchasing();this.toast(result.message);}catch(e){this.toast(e.message,'error');}finally{this.carryoverBusy=false;this.setState({carryoverSaving:false});}
  };
  releaseCarryover=async id=>{
    if(!window.confirm('Release only the unreceived units back to the original shortage?'))return;
    try{const result=await this.authPost('/api/receiving-carryovers/'+id+'/release',{});await this.reloadPurchasing();this.toast(result.message);}catch(e){this.toast(e.message,'error');}
  };
  buildCarryoverPanel(){
    const h=React.createElement,entries=this.state.purchaseData?.carryovers||[];
    if(!entries.length)return null;
    return h('details',{className:'carryover-panel'},[h('summary',null,'Earlier shortage links'),null,this.recordTable('carryover-history',['Original PO','Linked PO','Received','Still allocated','Action'],entries.map(a=>tr([td(a.sourcePo),td(a.targetPo),td(a.receivedQty),td(Number(a.quantity)-Number(a.receivedQty)-Number(a.releasedQty)),td(Number(a.quantity)>Number(a.receivedQty)+Number(a.releasedQty)?btn('Release unreceived',()=>this.releaseCarryover(a.id)):null)],a.id)))]);
  }
  buildSupplierPerformance(supplierId){
    const h=React.createElement,data=this.state.purchaseData;if(!data)return h('p',null,['Loading supplier performance...',btn('Refresh',()=>this.reloadPurchasing().catch(()=>{}))]);
    const orders=data.orders.filter(o=>String(o.supplierId)===String(supplierId)),ids=new Set(orders.map(o=>o.id));
    const allocations=(data.carryovers||[]).filter(a=>ids.has(a.sourcePo));
    const inspections=(data.inspections||[]).filter(i=>ids.has(i.poId));
    const lines=orders.flatMap(o=>o.lines),ordered=lines.reduce((n,l)=>n+Number(l.orderedQty)-Number(l.carryoverQty||0),0);
    const accepted=lines.reduce((n,l)=>{const carried=allocations.filter(a=>Number(a.targetLine)===Number(l.id)).reduce((v,a)=>v+Number(a.receivedQty),0);return n+Math.min(Number(l.orderedQty)-Number(l.carryoverQty||0),Math.max(0,Number(l.deliveredQty)-carried)+Number(l.resolvedQty||0));},0);
    const first=new Map();inspections.forEach(i=>{if(!first.has(i.orderLine))first.set(i.orderLine,i);});
    const missing=lines.reduce((n,l)=>n+Number(first.get(l.id)?.newMissing||0),0),damaged=inspections.reduce((n,i)=>n+Number(i.damaged),0);
    const history=orders.flatMap(o=>(o.receipts||[]).flatMap(r=>(r.lines||[]).map(l=>({o,r,l}))));
    const resolutions=(data.resolutions||[]).filter(r=>allocations.some(a=>a.id===r.carryoverId));
    const days=(a,r)=>{const source=orders.find(o=>o.id===a.sourcePo),start=source?.receipts?.map(x=>x.received_at||x.date).sort()[0];return start?Math.max(0,Math.floor((new Date(r.created_at)-new Date(start))/86400000)):null;};
    return h('section',{className:'supplier-performance'},[h('h3',null,'Supplier Performance'),h('div',{className:'supplier-performance-metrics'},[kpi('Ordered units',ordered),kpi('Accepted units',accepted),kpi('Fulfillment rate',ordered?(100*accepted/ordered).toFixed(1)+'%':'No orders'),kpi('Outstanding units',Math.max(0,ordered-accepted)),kpi('Initially missing (recorded)',missing),kpi('Damaged (recorded)',damaged)]),null,
      h('h4',null,'Purchase orders'),this.recordTable('supplier-orders-'+supplierId,['Purchase ID','PO Date','Status','Actions'],orders.map(o=>tr([td(o.id),td(String(o.created||'').slice(0,10)),td(badge(o.status)),td(btn('View',()=>this.setState({purchaseOrderDetail:o.id})))],o.id))),this.purchaseOrderDetails(),this.purchaseReviewDetails(),
      h('h4',null,'Shortage resolution history'),this.recordTable('supplier-resolutions-'+supplierId,['Original PO','Received under PO','Quantity','Received date','Days to resolve'],resolutions.map(r=>{const a=allocations.find(a=>a.id===r.carryoverId);return tr([td(a.sourcePo),td(a.targetPo),td(r.quantity),td(String(r.created_at).slice(0,10)),td(days(a,r)??'Not recorded')],r.id);})),
      h('h4',null,'Outstanding shortages'),this.recordTable('supplier-open-'+supplierId,['Original PO','Product','Outstanding','First receipt'],orders.flatMap(o=>o.receipts?.length?o.lines.filter(l=>this.receivingOutstanding(l)>0).map(l=>tr([td(o.id),td(l.name),td(this.receivingOutstanding(l)),td(o.receipts.map(r=>r.date).sort()[0])],l.id)):[])),
      h('h4',null,'Delivery and replacement history'),this.recordTable('supplier-deliveries-'+supplierId,['PO','Receipt','Received','Product','Accepted','Batch','Condition / replacement'],history.map(({o,r,l})=>tr([td(o.id),td(r.deliveryReference||r.id),td(r.date),td(l.name),td(l.deliveredQty),td(l.internalBatch||l.batchNumber||l.lotNumber||'Not recorded'),td([l.conditionText,l.followupNote].filter(Boolean).join(' · ')||'Not recorded')],l.id)))
    ]);
  }
  buildReceivingAuditFilter(){
    const h=React.createElement;
    return h('label',{className:'receiving-audit-filter'},['Discrepancy audit',h('select',{'aria-label':'Discrepancy audit',value:this.state.receivingAudit||'',onChange:e=>{this.setState({receivingAudit:e.target.value});this.setTableView('purchase-receiving',{page:1});}},[['','All discrepancies'],['missing','Missing items only'],['damaged','Damaged stock only'],['flagged','Discrepancy flagged'],['none','No discrepancy']].map(([value,label])=>h('option',{key:value,value},label)))]);
  }
  buildDeliveryHistory(orders){
    const h=React.createElement,groups=new Map();
    orders.forEach(o=>(o.receipts||[]).forEach(r=>{const key=r.deliveryBatch||r.id;if(!groups.has(key))groups.set(key,{batch:r.deliveryBatch||'Earlier receipt: '+r.id,date:r.date,entries:[]});groups.get(key).entries.push({order:o,receipt:r});}));
    if(!groups.size)return null;
    return h('details',{className:'delivery-history'},[h('summary',null,'Delivery batch history'),this.recordTable('delivery-batches',['Batch','Received','Purchase orders','Products received'],[...groups.values()].sort((a,b)=>String(b.date).localeCompare(String(a.date))).map(g=>tr([td(g.batch),td(g.date),td([...new Set(g.entries.map(e=>e.order.id))].join(', ')),td(h('details',null,[h('summary',null,g.entries.reduce((n,e)=>n+(e.receipt.lines||[]).length,0)+' items'),...g.entries.flatMap(e=>(e.receipt.lines||[]).map((l,i)=>h('p',{key:e.receipt.id+'-'+i},[h('strong',null,l.name+' · '+l.deliveredQty+' '+(l.unit||'units')),h('small',{style:{display:'block'}},e.order.id),l.followupNote?h('small',{style:{display:'block'}},l.followupNote):null])))]))],g.batch)))]);
  }
  buildReceivingExample(){
    const h=React.createElement;
    return h('details',{className:'receiving-example'},[
      h('summary',null,'Example: 35 received = 5 previously missing + 30 newly ordered'),
      h('div',{className:'receiving-example-content'},[
        h('p',null,'The same supplier delivers 35 units of one product: 5 missing from your original PO and 30 ordered on a new approved PO.'),
        h('div',{className:'receiving-example-split'},[
          h('div',null,[h('span',null,'Original PO'),h('strong',null,'5 units'),h('small',null,'Fulfill the previous shortage')]),
          h('div',null,[h('span',null,'New PO'),h('strong',null,'30 units'),h('small',null,'Receive the new order')])
        ]),
        h('ol',null,[
          h('li',null,'Open Receive delivery on the new PO and enter 30 delivered units.'),
          h('li',null,'Choose Add Outstanding Item, select the original PO and its product, then enter the 5 previously missing units.'),
          h('li',null,'Choose Review & Confirm, check both original PO allocations, then confirm the stock receipt once.')
        ]),
        h('p',{className:'receiving-example-result'},'Result: 35 units added to stock, one shared batch and Stock Receipt, separate product entries, and each quantity recorded against its own PO. The original PO keeps a follow-up note for the earlier shortage.'),
        h('p',{className:'receiving-example-note'},'Do not enter all 35 under the new PO. This is an example only; no stock is added by opening this guide.')
      ])
    ]);
  }
  newInspectionEntry=(order,line,type)=>({key:crypto.randomUUID(),poId:order?.id||'',version:Number(order?.receivingVersion||0),productId:Number(line?.productId||0),type,delivered:'',damaged:'',damageReason:'',disposition:'return',substituteProductId:null,substitutionReason:'',unitCost:line?.unitCost??0,lots:[{quantity:0,supplierLot:'',expiryDate:'',manufacturedDate:''}]});
  inspectionLine=entry=>this.state.purchaseData?.orders.find(o=>o.id===entry.poId)?.lines.find(l=>Number(l.productId)===Number(entry.productId));
  inspectionProduct=entry=>(this.state.productsLocal||this.state.data?.PRODUCTS||[]).find(p=>Number(p.id)===Number(entry.substituteProductId||entry.productId));
  inspectionValueLimit=entry=>{
    const l=this.inspectionLine(entry),o=this.state.purchaseData?.orders.find(o=>o.id===entry.poId);if(!l)return 0;
    const spent=(o?.receipts||[]).flatMap(r=>r.lines||[]).filter(r=>Number(r.originalProductId||r.productId)===Number(l.productId)).reduce((n,r)=>n+Math.round(Number(r.deliveredQty)*Number(r.unitCost)*100),0);
    return Math.max(0,Math.round(Number(l.orderedQty)*Number(l.unitCost)*100)-spent)/100;
  };
  inspectionAccepted=entry=>Math.max(0,Number(entry.delivered||0)-Number(entry.damaged||0));
  inspectionDue=entry=>{const l=this.inspectionLine(entry);if(!l)return 0;const b=l.obligation;return entry.type==='damaged'?Number(b?.damaged||0):entry.type==='missing'?Number(b?.missing??this.carryoverAvailable(l)):this.carryoverAvailable(l);};
  updateInspection=(key,changes)=>this.setState(s=>({inspectionReview:false,inspection:{...s.inspection,entries:s.inspection.entries.map(e=>{if(e.key!==key)return e;const next={...e,...changes};if(('delivered' in changes||'damaged' in changes)&&next.lots.length===1)next.lots=[{...next.lots[0],quantity:this.inspectionAccepted(next)}];return next;})},receiveError:''}));
  updateInspectionLot=(entry,index,changes)=>this.updateInspection(entry.key,{lots:entry.lots.map((l,i)=>i===index?{...l,...changes}:l)});
  inspectionPayload=()=>{const d=this.state.inspection;return {...d,entries:d.entries.map(({key,...e})=>({...e,delivered:Number(e.delivered||0),damaged:Number(e.damaged||0),unitCost:Number(e.unitCost),lots:this.inspectionAccepted(e)>0?e.lots.map(l=>({...l,quantity:Number(l.quantity),expiryDate:l.expiryDate||null,manufacturedDate:l.manufacturedDate||null,supplierLot:l.supplierLot.trim()||null})):[]}))};};
  reviewInspection=()=>{
    const d=this.state.inspection,entered=d.entries;
    let error='';
    if(!entered.some(e=>Number(e.delivered)>0))error='Enter delivered quantities for at least one product.';
    for(const e of entered){
      if(!e.poId||!e.productId)error='Choose the original PO and product for each additional entry.';
      if(!Number.isInteger(Number(e.delivered))||Number(e.delivered)<0||!Number.isInteger(Number(e.damaged||0))||Number(e.damaged||0)<0||Number(e.damaged)>Number(e.delivered))error='Delivered and damaged quantities must be whole numbers. Damage cannot exceed delivery.';
      if(Number(e.damaged)>0&&!e.damageReason.trim())error='Enter a reason for each damaged quantity.';
      if(!this.validAmount(e.unitCost))error='Enter a valid purchase cost.';
      const groupValue=entered.filter(x=>x.poId===e.poId&&Number(x.productId)===Number(e.productId)).reduce((n,x)=>n+Math.round(this.inspectionAccepted(x)*Number(x.unitCost)*100),0);
      if(groupValue>Math.round(this.inspectionValueLimit(e)*100))error='Cannot receive: total cost exceeds the remaining approved value of '+peso(this.inspectionValueLimit(e))+'. Only equal-or-lower-value replacements are allowed.';
      if(e.substituteProductId&&!e.substitutionReason.trim())error='Explain each substitution.';
      const accepted=this.inspectionAccepted(e),perishable=this.state.data?.CATEGORIES?.find(c=>c.name===this.inspectionProduct(e)?.category)?.classification==='Perishable';
      if(accepted>0&&(e.lots.some(l=>!Number.isInteger(Number(l.quantity))||Number(l.quantity)<=0)||e.lots.reduce((n,l)=>n+Number(l.quantity||0),0)!==accepted))error='Lot quantities must add up to the accepted quantity.';
      if(accepted>0&&e.lots.some(l=>(perishable&&!l.expiryDate)||(l.expiryDate&&l.expiryDate<=(this.state.data?.BUSINESS_DATE||new Date().toISOString().slice(0,10)))))error='Provide future expiration dates for perishable products; expired goods cannot be accepted.';
    }
    if(!d.deliveryDate||!String(d.supplierReference||'').trim()||!String(d.notes||'').trim())error='Complete delivery date, supplier reference, and receiving notes. Enter No remarks when there are none.';
    for(const e of entered){
      if([e.delivered,e.damaged,e.unitCost].some(v=>v==null||String(v).trim()===''))error='Complete delivered quantity, damaged quantity, and purchase cost for every item. Enter 0 when none.';
      if(this.inspectionAccepted(e)>0&&e.lots.some(l=>!String(l.supplierLot||'').trim()||!l.manufacturedDate||(!l.expiryDate&&!l.noExpiry)))error='Complete every lot: quantity, supplier lot, manufacturing date, and expiration date (or select No expiration date).';
      if(Number(e.damaged)>0&&!e.disposition)error='Choose a disposition for damaged items.';
      if(e.type!=='current'&&Number(e.delivered)<=0)error='Enter a delivered quantity for each outstanding item, or remove that entry.';
    }
    this.setState({receiveError:error,inspectionReview:!error});
    return !error;
  };
  submitInspection=async()=>{
    if(this.receivingBusy||!this.state.inspectionReview)return;
    if(!this.reviewInspection())return;
    this.receivingBusy=true;this.setState({receivingSaving:true,receiveError:''});
    try{const result=await this.authPost('/api/stock-receipts',this.inspectionPayload());this.setState({purchaseReceivingId:null,inspection:null,lastReceivedPo:null,lastStockReceipt:result.id});this.toast(result.message);await Promise.all([this.reloadPurchasing(),this.reloadCatalog()]);}
    catch(error){this.setState({receiveError:error.message,inspectionReview:false});}finally{this.receivingBusy=false;this.setState({receivingSaving:false});}
  };
  stockReceiptAction=async(record,action)=>{
    if(this.receivingBusy)return;
    const note=this.state.stockReceiptReviewNotes?.[record.id]||'';
    if(action!=='post'&&!note.trim()){this.toast('Enter a review note.','error');return;}
    if(action==='post'&&!window.confirm('Post accepted inventory for '+record.id+'?'))return;
    this.receivingBusy=true;this.setState({receivingSaving:true});
    try{const result=await this.authPost('/api/stock-receipts/'+encodeURIComponent(record.id)+(action==='post'?'/post':'/review'),action==='post'?{}:{action,note},action==='post'?'POST':'PATCH');this.toast(result.message);await Promise.all([this.reloadPurchasing(),this.reloadCatalog()]);}
    catch(error){this.toast(error.message,'error');}finally{this.receivingBusy=false;this.setState({receivingSaving:false});}
  };
  buildStockReceiptHistory(review=false){
    const h=React.createElement,s=this.state,records=(s.purchaseData?.stockReceipts||[]).filter(r=>!review||r.status==='Pending Approval');
    if(!records.length)return null;
    const selected=records.find(r=>r.id===s.receiptHistoryId&&s.receiptHistoryReview===review);
    const close=()=>{if(!this.receivingBusy)this.setState({receiptHistoryId:null});};
    const status=r=>h('span',{className:'receipt-history-status'},r.status);
    return h('section',{className:'stock-receipt-history'},[
      h('div',{className:'receipt-history-heading'},[h('h3',null,review?'Stock receipts awaiting approval':'Stock receipts'),h('span',null,records.length+' receipts')]),
      h('div',{className:'receipt-history-list'},records.map(r=>{
        const lines=r.summary||[],names=[...new Set(lines.map(e=>e.name).filter(Boolean))],pos=[...new Set(lines.map(e=>e.poId).filter(Boolean))];
        return h('article',{key:r.id,className:'receipt-history-card'},[
          h('div',{className:'receipt-history-info'},[h('div',{className:'receipt-history-title'},[h('strong',null,r.id),status(r)]),
            h('p',null,[r.deliveryDate||'Date not recorded',' · '+pos.join(', ')]),h('p',{className:'receipt-history-products'},names.slice(0,3).join(', ')+(names.length>3?' +'+(names.length-3)+' more':''))]),
          h('button',{type:'button',className:'kita-button kita-button--primary','aria-haspopup':'dialog','aria-label':'View details for '+r.id,onClick:()=>this.setState({receiptHistoryId:r.id,receiptHistoryReview:review})},'View Details')
        ]);
      })),
      selected?h('dialog',{className:'receipt-history-dialog','aria-label':'Stock receipt '+selected.id,ref:node=>{if(node&&!node.open)node.showModal();},onCancel:e=>{e.preventDefault();close();}},[
        h('header',{className:'receipt-history-dialog-header'},[h('div',null,[h('span',{className:'receipt-history-eyebrow'},'STOCK RECEIPT'),h('h2',null,selected.id),h('p',null,'Recorded on '+(selected.deliveryDate||'Not recorded'))]),h('button',{type:'button',className:'receipt-history-close','aria-label':'Close receipt details',disabled:!!s.receivingSaving,onClick:close},'×')]),
        h('div',{className:'receipt-history-dialog-body',tabIndex:0,'aria-label':'Receipt items, scroll for more'},[
          h('div',{className:'receipt-history-audit'},[status(selected),h('span',null,(selected.exceptions||[]).join('; ')||'No exceptions')]),
          h('div',{className:'inspection-table-wrap'},table(['Original PO','Product','Type','Delivered','Damaged','Accepted','Outstanding'],(selected.summary||[]).map((e,i)=>tr([td(e.poId),td(e.name),td({current:'Current order',missing:'Missing items',damaged:'Replacement',outstanding:'Outstanding items'}[e.type]||e.type),td(e.delivered),td(e.damaged),td(e.accepted),td(e.remaining)],i)))),
          review?h('div',{className:'inspection-approval'},[h('label',null,['Review note',h('textarea',{value:s.stockReceiptReviewNotes?.[selected.id]||'',disabled:!!s.receivingSaving,onChange:e=>this.setState(s=>({stockReceiptReviewNotes:{...s.stockReceiptReviewNotes,[selected.id]:e.target.value}}))})]),...['Approved','Declined'].map(action=>h('button',{type:'button',className:'kita-button',disabled:!!s.receivingSaving,onClick:()=>this.stockReceiptAction(selected,action)},action==='Approved'?'Approve receipt':'Decline receipt'))]):null
        ]),
        h('footer',{className:'receipt-history-dialog-footer'},[h('button',{type:'button',className:'kita-button',autoFocus:true,disabled:!!s.receivingSaving,onClick:close},'Close'),h('div',{className:'receipt-history-footer-actions'},[!review&&selected.status==='Approved'?h('button',{type:'button',className:'kita-button',disabled:!!s.receivingSaving,onClick:()=>this.stockReceiptAction(selected,'post')},'Post approved receipt'):null,h('a',{className:'kita-button kita-button--primary',href:'/stock-receipts/'+encodeURIComponent(selected.id)+'/report',target:'_blank',rel:'noopener'},'View / Print stock receipt')])])
      ]):null
    ]);
  }
  buildInspectionPopup(order,close){
    const h=React.createElement,d=this.state.inspection;if(!d)return null;
    const primary=d.entries.filter(e=>e.type==='current'),extra=d.entries.filter(e=>e.type!=='current');
    const sourceOrders=this.state.purchaseData.orders.filter(o=>o.id!==order.id&&String(o.supplierId)===String(order.supplierId)&&o.receipts?.length&&o.created<=order.created&&!['Cancelled','Closed','Fully Received'].includes(o.status)&&o.lines.some(l=>this.carryoverAvailable(l)>0));
    const accepted=d.entries.reduce((n,e)=>n+this.inspectionAccepted(e),0),units=[...new Set(d.entries.filter(e=>this.inspectionAccepted(e)>0).map(e=>this.purchaseUnit(this.inspectionLine(e)||{})))];
    const quantityLabel=units.length===1?units[0]:units.length?'units (mixed units)':'units';
    const field=(label,value,onChange,props={})=>h('label',null,[label,h('input',{required:!props.readOnly,value:value??'',onChange,...props})]);
    const edit=(e,k,type='number')=>h('input',{required:true,'aria-label':k+' for '+e.poId+' '+e.productId,type,min:0,step:1,value:e[k],onChange:ev=>this.updateInspection(e.key,{[k]:ev.target.value})});
    const details=e=>{
      const l=this.inspectionLine(e),product=this.inspectionProduct(e),accepted=this.inspectionAccepted(e);if(!l)return null;
      const candidates=(this.state.productsLocal||this.state.data?.PRODUCTS||[]).filter(p=>p.status==='Active'&&!p.archivedAt&&Number(p.id)!==Number(e.productId)&&String(p.stockUnit||p.unit).toLowerCase()===String(l.unit).toLowerCase());
      return h('div',{className:'inspection-entry-details'},[
        h('p',{className:'inventory-help'},'Outstanding value limit: '+peso(this.inspectionValueLimit(e))+' | Receiving value: '+peso(accepted*Number(e.unitCost))),
        Number(e.damaged)>0?h('div',{className:'inspection-damage'},[h('strong',null,'Damaged goods — excluded from saleable stock'),field('Damage reason *',e.damageReason,ev=>this.updateInspection(e.key,{damageReason:ev.target.value}),{maxLength:500}),h('label',null,['Disposition',h('select',{value:e.disposition,onChange:ev=>this.updateInspection(e.key,{disposition:ev.target.value})},[['return','Return to supplier'],['reject','Reject at delivery'],['quarantine','Quarantine (not saleable)']].map(([v,t])=>h('option',{value:v,key:v},t)))]),h('small',null,'Damaged quantities remain outstanding until replaced or resolved through an approved adjustment.')]):null,
        (e.type!=='current'||Number(l.deliveredQty)>0||Number(l.obligation?.damaged)>0)?h('details',{className:'inspection-options',open:true},[h('summary',null,'Same product or replacement'),h('label',null,['Product received',h('select',{'aria-label':'Replacement product for '+e.poId+' '+e.productId,value:e.substituteProductId||'',onChange:ev=>{const p=candidates.find(p=>String(p.id)===ev.target.value);this.updateInspection(e.key,{substituteProductId:p?Number(p.id):null,unitCost:p?p.unitPrice:l.unitCost});}},[h('option',{value:''},'Original product — '+l.name),...candidates.map(p=>h('option',{key:p.id,value:p.id},p.name+' · '+peso(p.unitPrice)))])]),e.substituteProductId?field('Substitution reason *',e.substitutionReason,ev=>this.updateInspection(e.key,{substitutionReason:ev.target.value}),{maxLength:500}):null,null]):null,
        h('details',{className:'inspection-options',open:true},[h('summary',null,'Purchase cost: '+peso(e.unitCost)),field('Actual unit cost',e.unitCost,ev=>this.updateInspection(e.key,{unitCost:ev.target.value}),{type:'number',min:0,step:'0.01'}),null]),
        accepted>0?h('details',{className:'inspection-options',open:true},[h('summary',null,'Lot details ('+e.lots.length+') — '+product?.name),null,...e.lots.map((lot,i)=>h('div',{className:'inspection-lot-grid',key:i},[
          field('Lot quantity',lot.quantity,ev=>this.updateInspectionLot(e,i,{quantity:ev.target.value}),{type:'number',min:1,step:1,'aria-label':'Lot '+(i+1)+' quantity for '+e.poId+' '+e.productId}),
          field('Manufacturer / supplier lot',lot.supplierLot,ev=>this.updateInspectionLot(e,i,{supplierLot:ev.target.value}),{maxLength:40}),
          field('Expiration date',lot.expiryDate,ev=>this.updateInspectionLot(e,i,{expiryDate:ev.target.value}),{type:'date',required:!lot.noExpiry,disabled:!!lot.noExpiry}),
          this.state.data?.CATEGORIES?.find(c=>c.name===product?.category)?.classification!=='Perishable'?h('label',null,[h('input',{type:'checkbox',checked:!!lot.noExpiry,onChange:ev=>this.updateInspectionLot(e,i,{noExpiry:ev.target.checked,expiryDate:''})}),'No expiration date']):null,
          field('Manufacturing date',lot.manufacturedDate,ev=>this.updateInspectionLot(e,i,{manufacturedDate:ev.target.value}),{type:'date',max:d.deliveryDate}),
          e.lots.length>1?h('button',{type:'button',className:'inspection-text-button',onClick:()=>this.updateInspection(e.key,{lots:e.lots.filter((_,n)=>n!==i)})},'Remove lot'):null
        ])),h('button',{type:'button',className:'inspection-text-button',onClick:()=>this.updateInspection(e.key,{lots:[...e.lots,{quantity:0,supplierLot:'',expiryDate:'',manufacturedDate:''}]})},'+ Add another lot'),h('strong',null,'Allocated '+e.lots.reduce((n,l)=>n+Number(l.quantity||0),0)+' / '+accepted)]):null
      ]);
    };
    const row=e=>{const l=this.inspectionLine(e),p=this.inspectionProduct(e),due=this.inspectionDue(e);return h(React.Fragment,{key:e.key},[
      h('tr',null,[td(l?.name||''),td(p?.sku||p?.barcode||p?.id||''),td(l?.unit||''),td(l?.orderedQty||0),td(due),td(edit(e,'delivered')),td(edit(e,'damaged')),td(h('output',null,this.inspectionAccepted(e))),td(h('output',null,Math.max(0,due-Number(e.delivered||0)))),td(h('input',{'aria-label':'Expiration for '+e.poId+' '+e.productId,type:'date',required:this.inspectionAccepted(e)>0&&!e.lots[0]?.noExpiry,disabled:e.lots.length!==1||!!e.lots[0]?.noExpiry||this.inspectionAccepted(e)===0,value:e.lots[0]?.expiryDate||'',onChange:ev=>this.updateInspectionLot(e,0,{expiryDate:ev.target.value})}))]),
      h('tr',{className:'inspection-detail-row'},[h('td',{colSpan:10},details(e))])
    ]);};
    return h('dialog',{className:'receiving-dialog inspection-dialog','aria-label':'Receive delivery inspection',ref:node=>{if(node&&!node.open)node.showModal();},onCancel:ev=>{ev.preventDefault();close();}},h('div',{className:'inspection-shell'},[
      h('header',{className:'inspection-header'},[h('div',null,[h('small',null,'STOCK RECEIVING'),h('h2',null,'Receive Delivery'),null]),h('button',{type:'button','aria-label':'Close receiving',disabled:!!this.state.receivingSaving,onClick:close},'×')]),
      h('div',{className:'inspection-scroll'},h('fieldset',{className:'inspection-body',disabled:!!this.state.receivingSaving},[
        h('div',{className:'inspection-po-details'},[field('Purchase Order No.',order.id,null,{readOnly:true}),field('Supplier',order.supplierName||this.purchaseSupplier(order.supplierId),null,{readOnly:true}),field('PO Date',String(order.created||'').slice(0,10),null,{readOnly:true}),h('div',null,[h('small',null,'PO Status'),badge(order.status)])]),
        this.state.inspectionReview?h('section',{className:'inspection-review'},[h('h3',null,'Review stock receipt'),null,h('div',{className:'inspection-table-wrap'},table(['PO','Product','Receiving type','Delivered','Damaged','Accepted','Remaining'],d.entries.filter(e=>Number(e.delivered)>0).map(e=>tr([td(e.poId),td(this.inspectionProduct(e)?.name),td(e.type==='current'?'Current PO':e.type==='damaged'?'Damaged replacement':e.type==='missing'?'Previously missing':'Previous outstanding'),td(e.delivered),td(e.damaged||0),td(this.inspectionAccepted(e)),td(Math.max(0,this.carryoverAvailable(this.inspectionLine(e)||{})-this.inspectionAccepted(e)))],e.key)))),...d.entries.filter(e=>Number(e.delivered)>0).map(e=>h('p',{key:e.key},[h('strong',null,(this.inspectionProduct(e)?.name||'')+' — '+e.poId),': '+e.lots.map(l=>l.quantity+' units / lot '+(l.supplierLot||'not supplied')+' / expiry '+(l.expiryDate||'not applicable')).join('; ')]))]):h(React.Fragment,null,[
          h('details',{className:'inspection-delivery-info',open:true},[h('summary',null,'Delivery information · '+d.deliveryDate),h('div',{className:'inspection-delivery-grid'},[field('Delivery date',d.deliveryDate,ev=>this.setState({inspection:{...d,deliveryDate:ev.target.value}}),{type:'date',max:this.state.data?.BUSINESS_DATE}),field('Supplier delivery / invoice reference *',d.supplierReference,ev=>this.setState({inspection:{...d,supplierReference:ev.target.value}}),{maxLength:100}),field('Received by',this.state.authenticatedUser?.name||'',null,{readOnly:true})])]),
          h('section',null,[h('div',{className:'inspection-section-title'},[h('h3',null,'1. Products for this Purchase Order'),h('span',null,primary.length+' products')]),null,stickyTable(h('div',{className:'inspection-table-wrap'},h('table',null,[h('thead',null,h('tr',null,['Product','SKU / Code','Unit','Ordered','Outstanding','Delivered','Damaged','Accepted','Undelivered','Expiration'].map(t=>h('th',{key:t},t)))),h('tbody',null,primary.map(row))])))]),
          h('section',{className:'inspection-additional'},[h('div',{className:'inspection-section-title'},[h('div',null,[h('h3',null,'2. Add Outstanding Item (Optional)'),null]),h('button',{type:'button',className:'inspection-add-button',disabled:!sourceOrders.length,onClick:()=>this.setState({inspection:{...d,entries:[...d.entries,this.newInspectionEntry(null,null,'outstanding')]}})},'+ Add Outstanding Item')]),!sourceOrders.length?h('small',null,'No other eligible previous POs for this supplier.'):null,...extra.map(e=>{
            const source=sourceOrders.find(o=>o.id===e.poId),l=this.inspectionLine(e),b=l?.obligation;
            return h('div',{className:'inspection-previous-card',key:e.key},[h('div',{className:'inspection-section-title'},[h('strong',null,'Previous Purchase Order Item'),h('button',{type:'button',className:'inspection-text-button',onClick:()=>this.setState({inspection:{...d,entries:d.entries.filter(x=>x.key!==e.key)}})},'Remove')]),
              h('div',{className:'inspection-previous-selects'},[
                h('label',null,['Original Purchase Order No.',h('select',{'aria-label':'Original PO '+e.key,value:e.poId,onChange:ev=>{const o=sourceOrders.find(o=>o.id===ev.target.value);this.updateInspection(e.key,{...this.newInspectionEntry(o,null,'outstanding'),key:e.key});}},[h('option',{value:''},'Select previous PO'),...sourceOrders.map(o=>h('option',{value:o.id,key:o.id},o.id))])]),
                h('label',null,['Original product',h('select',{'aria-label':'Outstanding product '+e.key,value:e.productId||'',onChange:ev=>{const l=source?.lines.find(l=>Number(l.productId)===Number(ev.target.value));this.updateInspection(e.key,{...this.newInspectionEntry(source,l,(!l?.obligation||l.obligation.unclassified)?'outstanding':l?.obligation?.missing>0?'missing':'damaged'),key:e.key});}},[h('option',{value:''},'Select product'),...(source?.lines||[]).filter(l=>this.carryoverAvailable(l)>0).map(l=>h('option',{value:l.productId,key:l.productId},l.name+' · '+this.carryoverAvailable(l)+' outstanding'))])]),
                h('label',null,['Receiving type',h('select',{'aria-label':'Receiving type '+e.key,value:e.type,onChange:ev=>this.updateInspection(e.key,{type:ev.target.value,delivered:'',damaged:'',lots:[{quantity:0,supplierLot:'',expiryDate:'',manufacturedDate:''}]})},b?.unclassified||!b||!l?[h('option',{value:'outstanding'},'Previous outstanding item')]:[...(Number(b?.missing??this.carryoverAvailable(l))>0?[h('option',{value:'missing'},'Previously missing item')]:[]),...(Number(b?.damaged)>0?[h('option',{value:'damaged'},'Damaged replacement')]:[])])])
              ]),b?.unclassified?null:null,
              l?stickyTable(h('div',{className:'inspection-table-wrap'},h('table',null,[h('thead',null,h('tr',null,['Product','SKU / Code','Unit','Ordered','Outstanding','Delivered','Damaged','Accepted','Undelivered','Expiration'].map(t=>h('th',{key:t},t)))),h('tbody',null,row(e))]))):null
            ]);
          })]),
          h('section',null,[h('h3',null,'3. Receiving Notes'),h('textarea',{'aria-label':'Receiving notes',rows:2,maxLength:2000,required:true,placeholder:'Required: enter No remarks, or explain any receiving issues.',value:d.notes,onChange:ev=>this.setState({inspection:{...d,notes:ev.target.value}})})])
        ]),
        this.state.receiveError?h('p',{role:'alert',className:'inspection-error'},this.state.receiveError):null
      ])),
      h('footer',{className:'inspection-footer'},[h('div',{className:'inspection-totals'},[h('div',null,[h('small',null,'Current PO accepted'),h('strong',null,primary.reduce((n,e)=>n+this.inspectionAccepted(e),0)+' units')]),h('div',null,[h('small',null,'Previous PO accepted'),h('strong',null,extra.reduce((n,e)=>n+this.inspectionAccepted(e),0)+' units')]),h('div',null,[h('small',null,'Total accepted'),h('strong',null,accepted+' '+quantityLabel)])]),h('div',{className:'inspection-footer-actions'},[h('button',{type:'button',className:'receive-cancel',disabled:!!this.state.receivingSaving,onClick:this.state.inspectionReview?()=>this.setState({inspectionReview:false}):close},this.state.inspectionReview?'Back':'Cancel'),h('button',{type:'button',className:'primary-button',disabled:!!this.state.receivingSaving,onClick:this.state.inspectionReview?this.submitInspection:this.reviewInspection},this.state.receivingSaving?'Saving...':this.state.inspectionReview?'Confirm Stock Receipt':'Review & Confirm')])])
    ]));
  }

  receivingQuantityLabel(lines,quantity){
    const totals=new Map();
    lines.forEach(l=>{const unit=this.purchaseUnit(l)||'units';totals.set(unit,(totals.get(unit)||0)+Number(quantity(l)||0));});
    return [...totals].map(([unit,n])=>n+' '+unit).join(' · ')||'0 units';
  }
  buildPurchaseReceiving(){
    const h=React.createElement,state=this.purchasingState();if(state)return state;
    const orders=this.state.purchaseData.orders,tab=this.state.receivingTab||'active';
    const canReceive=o=>o.lines.some(l=>this.carryoverAvailable(l)>0)&&(['Approved / Waiting for Delivery','Partially Received','Approved','Ordered','Completed','Received with Discrepancy'].includes(o.status)||(o.status==='Draft'&&o.request?.status==='Approved'));
    const pending=orders.filter(canReceive),receipts=orders.flatMap(o=>o.receipts||[]);
    const query=this.state.receivingCardSearch||'';
    const selected=this.filterPurchases(pending,true).filter(o=>this.matchesReceivingAudit(o,this.state.receivingAudit||'')).filter(o=>tab!=='missing'||o.receipts?.length).filter(o=>searchMatches([o.id,o.supplierName,this.purchaseSupplier(o.supplierId),...o.lines.map(l=>l.name)].join(' '),query)).sort((a,b)=>String(a.created).localeCompare(String(b.created))||a.id.localeCompare(b.id));
    const order=orders.find(o=>o.id===this.state.purchaseReceivingId);
    const close=()=>{if(!this.receivingBusy)this.setState({purchaseReceivingId:null});};
    const receiptIds=new Set(receipts.filter(r=>r.date===this.state.data?.BUSINESS_DATE).map(r=>r.deliveryBatch||r.id));
    return h('div',{className:'receiving-workspace'},[
      moduleTitle('Stock Receiving'),
      h('div',{className:'receiving-card-metrics'},[kpi('Purchase Orders Awaiting Receipt',pending.length+' POs'),kpi('Stock Receipts Today',receiptIds.size),kpi('Outstanding Items',pending.reduce((n,o)=>n+o.lines.filter(l=>this.carryoverAvailable(l)>0).length,0)+' lines'),kpi('Fully Received Purchase Orders',orders.filter(o=>o.fulfillmentStatus==='Fully Received'||o.status==='Fully Received').length+' POs')]),
      h('section',{className:'receiving-card-panel'},[
        h('div',{className:'receiving-card-tabs','aria-label':'Receiving views'},[
          ...[['active','To Receive'],['history','Stock Receipt History'],['missing','Outstanding Items']].map(([value,label])=>h('button',{type:'button',key:value,'aria-pressed':tab===value,onClick:()=>this.setState({receivingTab:value})},label)),
          h('button',{type:'button','aria-pressed':tab==='batches',onClick:()=>this.openInventoryView('batches')},'Batch Records')
        ]),
        tab==='batches'?this.buildInventoryHistory(true):tab==='history'?h(React.Fragment,null,[this.buildStockReceiptHistory(),this.buildDeliveryHistory(orders),!receipts.length?h('p',{className:'record-empty'},'No stock receipts have been recorded.'):null]):h(React.Fragment,null,[
          h('h3',null,tab==='missing'?'Outstanding Items':'Purchase Orders Awaiting Receipt'),
          null,
          h('input',{className:'receiving-card-search',type:'search','aria-label':'Search purchase orders',placeholder:'Search purchase order, supplier, or product...',value:query,onChange:e=>this.setState({receivingCardSearch:e.target.value})}),
          h('details',{className:'receiving-card-filters'},[h('summary',null,'Filter purchase orders'),this.purchaseFilters(true)]),
          h('div',{className:'receiving-card-list'},selected.length?selected.map(o=>{
            const lines=o.lines.filter(l=>this.carryoverAvailable(l)>0);
            return h('article',{className:'receiving-record-card',key:o.id},[
              h('div',{className:'receiving-card-row'},[h('strong',null,o.id),badge(o.receipts?.length?'Partially Received':'Awaiting Delivery')]),
              h('p',{className:'receiving-card-meta'},(o.supplierName||this.purchaseSupplier(o.supplierId))+' · '+String(o.created||'').slice(0,10)),
              h('div',{className:'receiving-card-row'},[h('span',null,lines.map(l=>l.name).join(', ')+' · '+lines.length+' item'+(lines.length===1?'':'s')),h('strong',null,this.receivingQuantityLabel(lines,l=>this.carryoverAvailable(l)))]),
              h('div',{className:'receiving-card-actions'},[h('button',{type:'button',className:'primary-button',onClick:()=>this.openPurchaseReceiving(o)},'Receive Delivery')])
            ]);
          }):h('p',{className:'record-empty',role:'status'},'No purchase orders match these filters.'))
        ])
      ]),order?this.buildInspectionPopup(order,close):null
    ]);
  }

  buildPurchaseHistory(){
    const h=React.createElement,state=this.purchasingState();if(state)return state;
    const orders=this.state.purchaseData.orders;
    const all=orders.flatMap(o=>(o.receipts||[]).map(r=>({o,r}))).sort((a,b)=>String(b.r.received_at||b.r.date).localeCompare(String(a.r.received_at||a.r.date))||String(b.r.id).localeCompare(String(a.r.id)));
    const query=this.state.receiptHistorySearch||'',type=this.state.receiptHistoryType||'',from=this.state.receiptHistoryFrom||'',to=this.state.receiptHistoryTo||'';
    const isReplacement=r=>(r.lines||[]).some(l=>/replacement|substitut/i.test(l.conditionText||''));
    const filtered=all.filter(({o,r})=>(!type||(type==='replacement'?isReplacement(r):!isReplacement(r)))&&(!from||r.date>=from)&&(!to||r.date<=to)&&searchMatches([r.id,o.id,o.supplierName,r.receivedBy,r.remarks,...(r.lines||[]).map(l=>l.name)].join(' '),query));
    return h('div',{className:'receiving-workspace'},[
      moduleTitle('Transaction History'),
      h('div',{className:'receiving-card-metrics'},[kpi('Posted PO Receipts',all.length),kpi('Purchase Orders Received',orders.filter(o=>o.receipts?.length).length),kpi('Replacement / Substitution Receipts',all.filter(({r})=>isReplacement(r)).length),kpi('Latest Receipt Date',all[0]?.r.date||'No receipts')]),
      h('section',{className:'receiving-card-panel'},[
        h('div',{className:'receiving-card-row'},[h('h3',null,'Receipt Transactions'),null]),
        h('input',{className:'receiving-card-search',type:'search','aria-label':'Search receipt transactions',placeholder:'Search receipt, purchase order, supplier, or user...',value:query,onChange:e=>this.setState({receiptHistorySearch:e.target.value})}),
        h('div',{className:'receiving-history-filters'},[
          h('label',null,['Transaction Type',h('select',{value:type,onChange:e=>this.setState({receiptHistoryType:e.target.value})},[['','All Types'],['receipt','Stock Receipt'],['replacement','Replacement / Substitution Receipt']].map(([value,label])=>h('option',{value,key:value},label)))]),
          ...[['receiptHistoryFrom','Receipt Date From',from],['receiptHistoryTo','Receipt Date To',to]].map(([key,label,value])=>h('label',{key},[label,h('input',{type:'date',value,onChange:e=>this.setState({[key]:e.target.value})})]))
        ]),
        h('p',{className:'receiving-card-help',role:'status'},filtered.length+' matching receipts'),
        h('div',{className:'receiving-card-list'},filtered.length?filtered.map(({o,r})=>h('article',{className:'receiving-record-card',key:r.id},[
          h('div',{className:'receiving-card-row'},[h('strong',null,r.id),badge(isReplacement(r)?'Replacement / Substitution Receipt':'Stock Receipt')]),
          h('p',{className:'receiving-card-meta'},r.received_at||r.date),
          h('div',{className:'receiving-card-row'},[h('span',null,r.remarks||'Stock received and posted'),h('strong',{className:'receiving-card-quantity'},'+'+this.receivingQuantityLabel(r.lines||[],l=>l.deliveredQty))]),
          h('p',{className:'receiving-card-meta'},'Purchase Order: '+o.id),
          h('p',{className:'receiving-card-meta'},'Received by: '+(r.receivedBy||'Not recorded')),
          h('details',{className:'receiving-transaction-details'},[
            h('summary',null,'View Transaction'),
            h('dl',null,[...[[ 'Receipt No.',r.id],['Purchase Order No.',o.id],['Supplier',o.supplierName||this.purchaseSupplier(o.supplierId)],['Status','Posted']].map(([label,value])=>h('div',{key:label},[h('dt',null,label),h('dd',null,value)])),...(r.lines||[]).map((l,i)=>h('div',{key:i},[h('dt',null,l.name||o.lines.find(p=>Number(p.productId)===Number(l.productId))?.name||l.productId),h('dd',null,l.deliveredQty+' '+this.purchaseUnit(l))]))]),
            h('a',{className:'kita-button',href:'/receipts/'+encodeURIComponent(r.id)+'/report',target:'_blank',rel:'noopener'},'View / Print Receipt')
          ])
        ])):h('p',{className:'record-empty'},'No receipt transactions match these filters.'))
      ])
    ]);
  }
  cancelPurchaseRemainder=async id=>{
    const reason=window.prompt('Cancel all outstanding quantities on '+id+'? Enter the reason. Accepted stock and payable will remain.');if(!reason?.trim())return;
    try{const r=await this.authPost('/api/purchase-orders/'+encodeURIComponent(id)+'/cancel-remainder',{reason});await this.reloadPurchasing();this.toast(r.message);}catch(e){this.toast(e.message,'error');}
  };
  saveDeliveryPlan=async()=>{
    if(this.state.deliveryPlanSaving)return;
    const f=this.state.deliveryPlan;this.setState({deliveryPlanSaving:true});
    try{const result=await this.authPost('/api/purchase-orders/'+encodeURIComponent(f.id)+'/plan',{expectedDeliveryDate:f.expectedDeliveryDate||null,supplierSentDate:f.supplierSentDate||null,supplierSentVia:f.supplierSentVia||null,supplierSentNote:f.supplierSentNote||''},'PATCH');this.setState({deliveryPlan:null});await this.reloadPurchasing();this.toast(result.message);}catch(e){this.toast(e.message,'error');}finally{this.setState({deliveryPlanSaving:false});}
  };
  purchaseOrderDetails(){
    const h=React.createElement,data=this.state.purchaseData,id=this.state.purchaseOrderDetail;
    if(!id||!data)return null;
    const order=data.orders.find(o=>o.id===id),request=data.requests.find(r=>r.id===id||r.poId===id),o=order||request;if(!o)return null;
    const close=()=>this.setState({purchaseOrderDetail:null});
    return h('dialog',{className:'purchase-details-dialog po-order-dialog','aria-labelledby':'po-order-title',ref:node=>{if(node&&!node.open)node.showModal();},onCancel:e=>{e.preventDefault();close();}},[
      h('header',{className:'po-dialog-header'},[h('h2',{id:'po-order-title'},o.id),badge(o.status),h('button',{className:'kita-button','aria-label':'Close purchase order',onClick:close},'Close')]),
      h('div',{className:'po-dialog-body'},[h('p',null,(o.supplierName||this.purchaseSupplier(o.supplierId??o.lines[0]?.supplierId))+' | PO Date: '+(request?.dateRequested||o.created||'')),
        table(['Item','Category','Order Qty','Received Qty','Unit','Unit Cost','Subtotal'],o.lines.map(l=>tr([td(l.name),td(l.category||'-'),td(l.orderedQty??l.confirmedQty??l.qty),td(l.deliveredQty||0),td(l.unit||this.purchaseUnit(l)),td(peso(l.unitCost||0)),td(peso(Number(l.orderedQty??l.confirmedQty??l.qty)*Number(l.unitCost||0)))],l.id))),
        request?.notes?h('p',null,request.notes):null,request?.disapprovalReason||request?.adminNote?h('p',null,request.disapprovalReason||request.adminNote):null]),
      h('footer',{className:'dialog-actions'},[...(request?this.purchaseRequestActions(request).slice(1).map(action=>h(action.type,{...action.props,onClick:()=>{close();action.props.onClick();}})):[]),order?this.purchaseReportLink(order.id):null,order?h('a',{className:'kita-button',href:'/purchase-orders/'+encodeURIComponent(order.id)+'/order',target:'_blank',rel:'noopener'},'Print Supplier PO'):null,order&&this.state.role==='admin'&&!['Cancelled','Closed'].includes(order.status)&&order.lines.some(l=>this.receivingOutstanding(l)>0)?btn('Cancel Remaining',()=>{close();this.cancelPurchaseRemainder(order.id);}):null])]);
  }
  buildPurchaseOrderWorkspace(){
    const h=React.createElement,state=this.purchasingState();if(state)return state;
    const requests=this.state.purchaseData.requests||[],posted=this.state.purchaseData.orders;
    const orders=this.filterPurchases([...posted.map(o=>({...o,sourceRequest:requests.find(r=>r.id===o.id||r.poId===o.id),posted:true})),...requests.filter(r=>!posted.some(o=>o.id===(r.poId||r.id))).map(r=>({...r,created:r.dateRequested,sourceRequest:r,posted:false}))]);
    const field=(key,label,options)=>h('label',null,[label,options?h('select',{value:this.state[key]||'',onChange:e=>this.setState({[key]:e.target.value})},options.map(([value,title])=>h('option',{key:value,value},title))):h('input',{type:'date',value:this.state[key]||'',onChange:e=>this.setState({[key]:e.target.value})})]);
    return h('div',{className:'inventory-workspace purchase-order-workspace'},[moduleTitle('Purchase Orders'),
      h('div',{className:'inventory-view-tabs'},[btn('Create Purchase Order',this.goScreen('mgrRequest')),btn('Stock Receiving',this.goScreen(this.state.role==='manager'?'mgrPO':'admReceivingApprovals')),btn('Supplier Accounts',this.goScreen('supplierAccounts'))]),
      h('div',{className:'po-filter-panel'},[h('div',{className:'po-filter-fields'},[
        field('purchaseSupplierFilter','Supplier',[['','All Suppliers'],...(this.state.data?.SUPPLIERS||[]).map(s=>[s.id,s.name])]),
        field('purchaseStatusFilter','Status',[['','All PO Statuses'],...['Pending Approval','Approved / Waiting for Delivery','Declined','Completed','Partially Received','Fully Received','Received with Discrepancy','Pending','Approved','Draft','Closed','Cancelled'].map(s=>[s,s])]),field('purchaseFrom','PO Date From'),field('purchaseTo','PO Date To')]),
        h('div',{className:'po-filter-bottom'},[h('input',{type:'search','aria-label':'Search purchase records',placeholder:'Search purchase records...',value:this.state.tableViews['purchase-order-workspace']?.query||'',onChange:e=>this.setTableView('purchase-order-workspace',{query:e.target.value,page:1})}),h('div',{className:'po-filter-actions'},[btn('Clear Filters',()=>{this.setState({purchaseSupplierFilter:'',purchaseStatusFilter:'',purchaseFrom:'',purchaseTo:''});this.setTableView('purchase-order-workspace',{query:'',page:1});}),btn('Refresh',()=>this.reloadPurchasing())])])]),
      this.recordTable('purchase-order-workspace',['Purchase ID','PO Date','Supplier','Items','Expected Delivery','Status','Actions'],orders.map(o=>tr([td(o.id),td(String(o.sourceRequest?.dateRequested||o.created||'').slice(0,10)),td(o.supplierName||this.purchaseSupplier(o.supplierId??o.lines[0]?.supplierId)),td(o.lines.length),td(o.expectedDeliveryDate||'Not scheduled'),td(badge(o.status)),td(h('div',{className:'po-row-actions'},[btn('View',()=>this.setState({purchaseOrderDetail:o.id})),o.posted?h('a',{className:'kita-button',href:'/purchase-orders/'+encodeURIComponent(o.id)+'/order',target:'_blank',rel:'noopener'},'Print Supplier PO'):null]))],o.id)),{externalSearch:true}),this.purchaseOrderDetails(),this.purchaseReviewDetails()]);
  }
  loadSupplierAccounts=async()=>{
    this.setState({supplierAccountsLoading:true,supplierAccountsError:''});
    try{const r=await fetch('/api/supplier-accounts',{headers:{Accept:'application/json'},cache:'no-store'});const body=await r.json();if(!r.ok)throw Error(body.message||'Unable to load supplier accounts.');this.setState({supplierAccountsData:body});}catch(e){this.setState({supplierAccountsError:e.message});}finally{this.setState({supplierAccountsLoading:false});}
  };
  saveSupplierSettlement=async()=>{
    if(this.state.settlementSaving)return;
    const f=this.state.settlementForm;this.setState({settlementSaving:true});
    try{const r=await this.authPost('/api/receipts/'+encodeURIComponent(f.receiptId)+'/settlements',{requestKey:f.requestKey,kind:f.kind,date:f.date,amount:f.amount,reference:f.reference,notes:f.notes||''});this.setState({settlementForm:null});this.toast(r.message);await this.loadSupplierAccounts();}catch(e){this.toast(e.message,'error');}finally{this.setState({settlementSaving:false});}
  };
  voidSupplierSettlement=async id=>{
    const reason=window.prompt('Why should this recorded entry be voided?');if(!reason?.trim())return;
    try{const r=await this.authPost('/api/supplier-settlements/'+id+'/void',{reason});this.toast(r.message);await this.loadSupplierAccounts();}catch(e){this.toast(e.message,'error');}
  };
  buildSupplierAccounts(){
    const h=React.createElement,s=this.state,d=s.supplierAccountsData,f=s.settlementForm,admin=s.role==='admin';
    const receipts=(d?.receipts||[]).filter(r=>!s.accountSupplier||String(r.supplierId)===s.accountSupplier),ids=new Set(receipts.map(r=>r.id));
    const entries=(d?.settlements||[]).filter(e=>ids.has(e.receiptId));
    const edit=(key,value)=>this.setState({settlementForm:{...f,[key]:value,requestKey:crypto.randomUUID()}});
    return h('div',{className:'inventory-workspace'},[moduleTitle('Supplier Accounts'),
      h('div',{className:'purchase-filters'},[h('label',null,['Supplier',h('select',{value:s.accountSupplier||'',onChange:e=>this.setState({accountSupplier:e.target.value})},[h('option',{value:''},'All suppliers'),...(s.data?.SUPPLIERS||[]).map(v=>h('option',{value:v.id},v.name))])]),h('button',{className:'kita-button',disabled:s.supplierAccountsLoading,onClick:this.loadSupplierAccounts},s.supplierAccountsLoading?'Refreshing...':'Refresh'),btn('Reports / Export',()=>{this.setState({screen:'admReports',reportFilters:{type:'payables',supplierId:s.accountSupplier||''}});})]),
      s.supplierAccountsError?h('p',{role:'alert'},s.supplierAccountsError):null,
      h('div',{className:'inventory-summary'},[kpi('Accepted Purchases',peso(receipts.reduce((n,r)=>n+Number(r.receivedValue),0))),kpi('Recorded Payments',peso(receipts.reduce((n,r)=>n+Number(r.payments),0))),kpi('Supplier Credits',peso(receipts.reduce((n,r)=>n+Number(r.credits),0))),kpi('Unpaid Balance',peso(receipts.reduce((n,r)=>n+Number(r.balance),0)))]),
      null,
      this.recordTable('supplier-balances',['Supplier','PO Number','Receipt ID','Receipt Date','Due Date','Accepted Value','Payments','Credits','Unpaid Balance','Account Remark','Actions'],receipts.map(r=>tr([td(r.supplierName),td(r.poId),td(r.id),td(r.date),td(r.paymentDueDate||'Not recorded'),td(peso(r.receivedValue)),td(peso(r.payments)),td(peso(r.credits)),td(r.balance==null?'Review required':peso(r.balance)),td(r.accountRemark),td([h('a',{href:'/receipts/'+encodeURIComponent(r.id)+'/report',target:'_blank',rel:'noopener'},'Receipt slip'),admin&&Number(r.balance)>0&&!r.unknownCosts&&Number(r.legacyPaidValue||0)===0?btn('Record Payment / Credit',()=>this.setState({settlementForm:{receiptId:r.id,kind:'Payment',date:s.data?.BUSINESS_DATE||new Date().toISOString().slice(0,10),amount:'',reference:'',notes:'',requestKey:crypto.randomUUID()}})):null])],r.id))),
      f?card([h('h3',null,'Record entry for '+f.receiptId),h('div',{className:'purchase-filters'},[h('label',null,['Entry type',h('select',{value:f.kind,onChange:e=>edit('kind',e.target.value)},['Payment','Credit note'].map(v=>h('option',{value:v},v)))]),...[['date','Date','date'],['amount','Amount (PHP)','number'],['reference','Payment / credit note reference','text'],['notes','Notes','text']].map(([key,label,type])=>h('label',{key},[label,h('input',{type,value:f[key],...(type==='number'?{min:0.01,step:0.01}:{}),onChange:e=>edit(key,e.target.value)})]))]),h('button',{className:'kita-button',disabled:s.settlementSaving,onClick:this.saveSupplierSettlement},s.settlementSaving?'Saving...':'Record entry'),btn('Cancel',()=>this.setState({settlementForm:null}))]):null,
      h('h3',null,'Payment and credit history'),this.recordTable('supplier-settlements',['Date','Receipt ID','Type','Amount','Reference','Recorded By','Remarks','Action'],entries.map(e=>tr([td(e.date),td(e.receiptId),td(e.kind),td(peso(e.amount)),td(e.reference),td(e.actor),td(e.voidedAt?'Voided: '+e.voidReason:e.notes),td(admin&&!e.voidedAt?btn('Void entry',()=>this.voidSupplierSettlement(e.id)):null)],e.id)))]);
  }
  buildPurchaseStatusList(declined=false){
    const h=React.createElement,state=this.purchasingState();if(state)return state;
    const records=this.filterPurchases(this.state.purchaseData.requests).filter(r=>declined?['Declined','Disapproved'].includes(r.status):!!r.poId);
    return h('div',null,[moduleTitle(declined?'Declined Requests':'Approved Purchase Orders'),this.purchaseFilters(),this.recordTable('purchase-status',['PO ID','Supplier','Received batches','Status','Admin / reason','Actions'],records.map(r=>tr([td(r.poId||r.id),td(r.supplierName||this.purchaseSupplier(r.lines[0]?.supplierId)),td(this.purchaseBatchSummary(r.poId||r.id)),td(badge(r.status)),td(r.disapprovalReason||r.adminNote||r.approvedBy),td([btn('View',()=>this.setState({purchaseRequestDetail:r.id})),r.poId?this.purchaseReportLink(r.poId):null])],r.id))),this.purchaseRequestDetails()]);
  }

  buildMgrRequest(){ return this.buildPurchaseRequests(); }
  buildItemPickerModal(){
    const {data,itemPickerSearch,itemPickerFilterCategory,itemPickerFilterSupplier,itemPickerChecked,itemPickerLowStockOnly}=this.state;
    const q=itemPickerSearch.toLowerCase();
    const isLow=p=>p.stock<=p.minStock;
    const lowStockCount=data.PRODUCTS.filter(isLow).length;
    let items=data.PRODUCTS.filter(p=>p.status==="Active"&&!p.archivedAt&&
      (!itemPickerFilterCategory||p.category===itemPickerFilterCategory) &&
      (!this.state.itemPickerSubcategoryId||String(p.subcategoryId)===String(this.state.itemPickerSubcategoryId)) &&
      (!itemPickerFilterSupplier||!p.supplierId||String(p.supplierId)===String(itemPickerFilterSupplier)) &&
      (!itemPickerLowStockOnly||isLow(p)) &&
      (!q||searchMatches(this.productLabel(p)+" "+p.barcode,q)));
    items = [...items].sort((a,b)=>(isLow(b)?1:0)-(isLow(a)?1:0));
    const count=Object.values(itemPickerChecked).filter(Boolean).length;
    return React.createElement("div",{style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50,padding:20}},
      React.createElement("div",{style:{width:560,maxWidth:"92vw",maxHeight:"82vh",background:"#fff",borderRadius:14,padding:20,display:"flex",flexDirection:"column",boxSizing:"border-box",boxShadow:"0 20px 60px rgba(6,25,20,0.25)"}},[
        React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"Add Items"),
        React.createElement("div",{key:"new-product",style:{padding:12,marginBottom:12,background:COLORS.brandBg,borderRadius:8}},[
          React.createElement("p",{style:{margin:"0 0 8px",fontSize:13,color:COLORS.textSoft}},"Purchasing a product that is not in inventory yet?"),
          btn("Register Product First",()=>{this.setState({itemPickerOpen:false,regEditing:null,regStockId:""});this.goScreen("mgrRegistration")();},"primary")
        ]),
        lowStockCount>0?React.createElement("div",{key:"banner",style:{background:COLORS.redBg,color:COLORS.red,padding:"8px 12px",borderRadius:8,fontSize:12,fontWeight:700,marginBottom:10}},`${lowStockCount} item(s) are currently low on stock - consider adding them to this request.`):null,
        React.createElement("div",{key:"filters",style:{display:"grid",gridTemplateColumns:"repeat(3,minmax(0,1fr))",gap:8,marginBottom:8,minWidth:0}},[
          React.createElement("input",{key:"s",value:itemPickerSearch,onChange:this.setItemPickerSearch,placeholder:"Searchâ€¦",style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7}}),
          React.createElement("select",{key:"c",value:itemPickerFilterCategory,onChange:this.setItemPickerFilterCategory,style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7}},[React.createElement("option",{key:"-",value:""},"All Categories"),...data.CATEGORIES.filter(c=>c.status==="Active").map(c=>React.createElement("option",{key:c.name,value:c.name},c.name))]),
          this.buildSubcategorySelect(itemPickerFilterCategory,this.state.itemPickerSubcategoryId,e=>this.setState({itemPickerSubcategoryId:e.target.value})),
          React.createElement("select",{key:"sp","aria-label":"Supplier for purchase request",value:itemPickerFilterSupplier,onChange:this.setItemPickerFilterSupplier,style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7,minWidth:0,width:"100%"}},[React.createElement("option",{key:"-",value:""},"Choose supplier"),...data.SUPPLIERS.filter(sp=>sp.status==="Active"&&!sp.archivedAt).map(sp=>React.createElement("option",{key:sp.id,value:sp.id},sp.name))]),
        ]),
        React.createElement("label",{key:"lowtoggle",style:{display:"flex",alignItems:"center",gap:6,fontSize:12,fontWeight:700,color:COLORS.red,marginBottom:10,cursor:"pointer"}},[React.createElement("input",{key:"cb",type:"checkbox",checked:itemPickerLowStockOnly,onChange:this.toggleItemPickerLowStockOnly}),"Low Stock Only"]),
        React.createElement("div",{key:"list",style:{flex:1,overflowY:"auto",border:"1px solid "+COLORS.border,borderRadius:8}},
          items.length===0?React.createElement("div",{style:{padding:20,textAlign:"center",color:COLORS.textMuted,fontSize:13}},"No existing items match. Register the product first above to add a new item to your request."):
          items.map(p=>{ const low=isLow(p);
            return React.createElement("label",{key:p.id,style:{display:"flex",alignItems:"center",gap:10,padding:"9px 12px",borderBottom:"1px solid #eef1f6",cursor:"pointer",background:low?COLORS.redBg:"transparent"}},[
              React.createElement("input",{key:"cb",type:"checkbox",checked:!!itemPickerChecked[p.id],onChange:this.toggleItemPickerCheck(p.id)}),
              React.createElement("div",{key:"n",style:{flex:1}},[
                React.createElement("div",{key:"nm",style:{fontSize:13,fontWeight:600,color:low?COLORS.red:COLORS.text}},[p.name," - ",React.createElement("span",{key:"s",style:{color:low?COLORS.red:COLORS.textSoft,fontWeight:700}},p.stock+" left"),` (min: ${p.minStock})`]),
                React.createElement("div",{key:"c",style:{fontSize:11,color:low?COLORS.red:COLORS.textMuted}},p.category),
              ]),
              React.createElement("div",{key:"p",style:{fontSize:12,fontWeight:700,color:COLORS.textSoft}},peso(p.price)),
            ]);
          })),
        React.createElement("div",{key:"footer",style:{display:"flex",justifyContent:"space-between",alignItems:"center",marginTop:14}},[
          React.createElement("span",{key:"count",style:{fontSize:13,fontWeight:700,color:COLORS.brandDark}},count+" item(s) selected"),
          React.createElement("div",{key:"a",style:{display:"flex",gap:8}},[React.createElement("button",{key:"c",onClick:this.closeItemPicker,style:{padding:"9px 14px",background:"#f7f9fc",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Cancel"),React.createElement("button",{key:"a",onClick:this.addSelectedToRequest,style:{padding:"9px 14px",background:COLORS.brand,color:"#fff",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Add Selected to Request")]),
        ]),
      ]));
  }
  buildMgrPO(){ return this.buildPurchaseReceiving(); }
  loadInventoryWorkflows=async()=>{
    this.setState({workflowLoading:true,workflowError:''});
    try{const r=await fetch('/api/inventory/workflows',{headers:{Accept:'application/json'}});const body=await r.json();if(!r.ok)throw new Error(body.message||'Could not load inventory workflows');this.setState({inventoryWorkflows:body});}
    catch(e){this.setState({workflowError:e.message});}finally{this.setState({workflowLoading:false});}
  };
  saveInventoryWorkflow=async(path,payload)=>{
    if(this.workflowBusy)return;
    this.workflowBusy=true;this.setState({workflowSaving:true,workflowError:''});
    try{const r=await this.authPost('/api/inventory/workflows/'+path,payload);this.toast(r.message);this.setState({workflowForm:{},workflowKey:null,countValues:{},countReason:''});await Promise.all([this.loadInventoryWorkflows(),this.reloadCatalog()]);}
    catch(e){this.setState({workflowError:e.message});}finally{this.workflowBusy=false;this.setState({workflowSaving:false});}
  };
  inventoryBatchGroups=rows=>{
    const groups=new Map();
    rows.forEach(row=>{const batch=row.batchNumber||'Unnumbered batch #'+row.id;if(!groups.has(batch))groups.set(batch,[]);groups.get(batch).push(row);});
    return [...groups].map(([batch,items])=>({batch,items}));
  };
  groupedBatchTables=(key,rows,headers,renderRow,options={})=>{
    const h=React.createElement,groups=this.inventoryBatchGroups(rows);
    const close=()=>this.setState({viewingBatch:null});
    return groups.length?h('div',{className:'inventory-batch-groups'},groups.map(({batch,items})=>{
      const id=key+'-'+batch,summary=new Set(items.map(r=>r.productId)).size+' products / '+items.length+' stock entries';
      const print=()=>h('a',{className:'kita-button batch-print-button',href:'/inventory/batches/'+encodeURIComponent(items[0].id)+'/print',target:'_blank',rel:'noopener'},'Print batch');
      return h('div',{key:batch,className:'inventory-batch-group'},[
        h('div',{className:'inventory-batch-summary'},[
          h('div',{className:'inventory-batch-info'},[h('strong',null,batch),h('span',null,summary),h('small',null,items[0].receivedAt||items[0].receivedDate||'')]),
          h('div',{className:'inventory-batch-actions'},[h('button',{type:'button',className:'kita-button batch-view-button','aria-haspopup':'dialog','aria-label':'View '+batch,onClick:()=>this.setState({viewingBatch:id})},'View Details')]),
        ]),
        this.state.viewingBatch===id?h('dialog',{className:'inventory-batch-dialog','aria-label':'Batch '+batch,ref:node=>{if(node&&!node.open)node.showModal();},onCancel:e=>{e.preventDefault();close();}},[
          h('div',{className:'inventory-batch-dialog-header'},[h('div',null,[h('h2',null,batch),h('p',null,summary)]),h('div',{className:'inventory-batch-actions'},[h('button',{type:'button',className:'kita-button',autoFocus:true,onClick:close},'Close')])]),
          h('div',{className:'inventory-batch-dialog-body',tabIndex:0,'aria-label':'Batch items, scroll for more'},this.recordTable(id,headers,items.map(renderRow),options)),
          h('footer',{className:'inventory-batch-dialog-footer'},[print()]),
        ]):null,
      ]);
    })):h('p',{className:'record-empty'},'No batches match these filters.');
  };
  buildInventoryWorkflows(tab){
    const h=React.createElement,s=this.state,d=s.inventoryWorkflows,f=s.workflowForm||{};
    const set=(key,value)=>this.setState({workflowForm:{...f,[key]:value},workflowKey:null});
    const field=(key,label,type='text')=>h('label',{className:'field-group'},[label,h('input',{type,value:f[key]||'',min:1,onChange:e=>set(key,e.target.value)})]);
    const select=(key,label,options)=>h('label',{className:'field-group'},[label,h('select',{value:f[key]||'',onChange:e=>set(key,e.target.value)},[h('option',{value:''},'Select'),...options.map(([value,text])=>h('option',{value,key:value},text))])]);
    const action=(label,fn)=>h('button',{className:'kita-button',disabled:!!s.workflowSaving,onClick:fn},label);
    const common=[h('div',{className:'page-toolbar'},[btn('Refresh records',this.loadInventoryWorkflows)]),s.workflowError?h('p',{role:'alert'},s.workflowError):null];
    if(s.workflowLoading&&!d)return h('div',null,[...common,loadingStatus('Loading inventory records...')]);
    if(!d)return h('div',null,[...common,h('p',null,'Load inventory records to continue.')]);
    if(tab==='expiry'){
      const days=Number(s.expiryDays??s.data?.EXPIRY_ALERT_DAYS??7),batches=d.batches.filter(b=>b.expiryDate&&Math.ceil((new Date(b.expiryDate)-new Date(d.today))/86400000)<=days).sort((a,b)=>a.expiryDate.localeCompare(b.expiryDate));
      return h('div',null,[...common,h('a',{className:'kita-button',href:'/reports/data?type=expiry&format=print&to='+new Date(new Date(d.today+'T00:00:00Z').getTime()+days*86400000).toISOString().slice(0,10),target:'_blank',rel:'noopener'},'Print Expiration Report'),this.buildExpirySettings(),h('label',null,['Show expiry within days (including expired stock)',h('input',{type:'number',min:0,max:3650,value:days,onChange:e=>this.setState({expiryDays:Math.max(0,Number(e.target.value)||0)})})]),this.recordTable('live-expiry',['Product ID','Product','Category','Manufacturer','Batch','Expiry','Days left','Remaining','Unit','Status','Action'],batches.map(b=>tr([td(b.productId),td(b.name),td(b.category||'Not recorded'),td(b.manufacturer||'Not recorded'),td(b.batchNumber||'No batch label'),td(b.expiryDate),td(Math.ceil((new Date(b.expiryDate)-new Date(d.today))/86400000)),td(b.quantityRemaining),td(b.stockUnit),td(b.expiryDate<=d.today?'Expired - blocked':'Expiring soon'),td(btn('Pull out',()=>this.setState({invTab:'writeoffs',workflowKey:null,workflowForm:{type:'Disposal',batchId:b.id,quantity:b.quantityRemaining,reason:b.expiryDate<=d.today?'Expired stock':'Short shelf life - manual pull-out'}})))],b.id)))]);
    }
    if(tab==='reconciliation'){
      return h('div',null,[...common,null,
        h('div',{className:'purchase-filters'},[select('mode','Count type',[['Cycle','Cycle'],['Blind','Blind (expected counts hidden)'],['Full','Full inventory']]),select('category','Category (blank = all)',(s.data.CATEGORIES||[]).map(c=>[c.name,c.name])),action('Start count',()=>this.saveInventoryWorkflow('counts',{mode:f.mode||'Cycle',category:f.category||null}))]),
        ...d.counts.map(c=>card([h('h3',null,'Count #'+c.id+' ? '+c.mode+' ? '+c.status),h('p',null,c.created_at+' | '+c.actor),this.recordTable('count-'+c.id,['Product',...(c.mode==='Blind'&&c.status==='Open'?[]:['Expected']),'Physical count',...(c.status==='Completed'?['Variance']:[])],c.lines.map(l=>tr([td(l.name),...(c.mode==='Blind'&&c.status==='Open'?[]:[td(l.expected)]),td(c.status==='Open'?h('input',{'aria-label':'Count '+l.name,type:'number',min:0,step:1,value:s.countValues?.[l.id]??'',onChange:e=>this.setState({countValues:{...this.state.countValues,[l.id]:e.target.value}})}):l.counted??'-'),...(c.status==='Completed'?[td(l.counted-l.expected)]:[])],l.id))),c.status==='Open'?h('div',null,[h('label',null,['Reason / investigation notes',h('textarea',{value:s.countReason||'',onChange:e=>this.setState({countReason:e.target.value})})]),action('Complete and reconcile',()=>{if(c.lines.some(l=>String(s.countValues?.[l.id]??'').trim()==='')){this.setState({workflowError:'Count every item, including zero quantities.'});return;}if(window.confirm('Apply this physical count to inventory?'))this.saveInventoryWorkflow('counts/'+c.id,{action:'Complete',reason:s.countReason||'',lines:c.lines.map(l=>({productId:l.productId,counted:Number(s.countValues[l.id])}))});}),action('Cancel count',()=>this.saveInventoryWorkflow('counts/'+c.id,{action:'Cancel',reason:s.countReason||'Count cancelled'}))]):h('p',null,c.reason)]))]);
    }
    const recall=tab==='recall';
    const groups=this.inventoryBatchGroups(d.batches);
    return h('div',null,[...common,recall?this.buildRecallTrace():null,null,
      h('div',{className:'purchase-filters'},[recall?select('recallAction','Action',[['Recall','Recall batch'],['Release recall','Release recall']]):select('type','Action',[['Write-off','Write-off'],['Disposal','Disposal'],['Supplier return','Return to original supplier'],['Transfer','Transfer out']]),h('label',{className:'field-group'},['Batch',h('select',{value:f.batchId||'',onChange:e=>set('batchId',e.target.value)},[h('option',{value:''},'Select a product entry'),...groups.map(({batch,items})=>h('optgroup',{key:batch,label:batch},items.map(b=>h('option',{key:b.id,value:b.id},b.name+' / '+('Entry #'+b.id)+' / on hand '+(b.stockOnHand??b.quantityRemaining)+' / '+(b.eligibility||'Eligible')))))])]),recall?null:field('quantity','Quantity','number'),!recall?field('destination','Destination (required for transfer)'):null,field('reason','Reason'),action(recall?'Save recall status':'Record stock action',()=>{if(!window.confirm(recall?'Change batch recall status? Physical stock is unchanged.':'Record this physical stock removal?'))return;const key=this.state.workflowKey||crypto.randomUUID();this.setState({workflowKey:key});this.saveInventoryWorkflow('events',{requestKey:key,type:recall?(f.recallAction||'Recall'):f.type,batchId:Number(f.batchId),quantity:recall?Number(d.batches.find(b=>String(b.id)===String(f.batchId))?.quantityRemaining||0):Number(f.quantity),reason:f.reason||'',destination:f.destination||null});})]),
      recall?this.groupedBatchTables('recall-stock',d.batches,['Product','Supplier reference','On hand','Unit','Expiry','Status','Action'],b=>tr([td(b.name),td(b.lotNumber||'Not recorded'),td(b.stockOnHand??b.quantityRemaining),td(b.stockUnit),td(b.expiryDate||'No expiration'),td(b.recalled?'Recalled':b.eligibility||'Available'),td(btn('Select this lot',()=>this.setState({workflowForm:{...f,batchId:String(b.id)},workflowKey:null})))],b.id)):null,
      this.recordTable('inventory-events-'+tab,['Reference','Date','Product','Batch','Type','Quantity affected','Destination','Reason','Recorded by'],d.events.filter(e=>recall?['Recall','Release recall'].includes(e.type):!['Recall','Release recall'].includes(e.type)).map(e=>tr([td('INV-'+e.id),td(e.created_at),td(e.name),td(e.batchNumber||'Batch #'+e.batchId),td(e.type),td(e.quantity),td(e.destination||'-'),td(e.reason),td(e.actor)],e.id)))]);
  }
  inventoryCondition=p=>p.stockCondition||(p.status!=='Active'?'Inactive':Number(p.stockOnHand??p.stock)===0?'Out of stock':this.sellableStock(p)===0?'Unavailable':this.sellableStock(p)<=Number(p.minStock||0)?'Reorder level reached':'In stock');
  openInventoryView=(view='products',productId)=>{
    const filters=productId?{productId:String(productId)}:{};
    this.setState({...(view==='batches'?{receivingTab:'batches',viewingBatch:null}:{}),inventoryHistoryOpen:true,inventoryView:view,inventoryFilters:filters,inventoryAppliedFilters:filters,inventoryHistory:null});
    this.loadInventoryHistory(filters);
  };
  draftInventoryRequest=p=>{
    if(this.state.newReqCart?.length){this.toast('Finish or clear the current purchase request before starting another.','error');return;}
    const supplier=(this.state.data?.SUPPLIERS||[]).find(s=>String(s.id)===String(p.supplierId)&&s.status==='Active'&&!s.archivedAt);
    this.purchaseSubmissionKey=null;
    this.setState({screen:'mgrRequest',newReqSupplier:supplier?String(supplier.id):'',newReqCart:[{productId:p.id,name:this.productLabel(p),category:p.category,unit:p.stockUnit||p.unit,orderUnit:p.stockUnit||p.unit,qty:Math.max(1,Number(p.minStock||0)-this.sellableStock(p)),supplierId:supplier?.id}],purchaseNotes:'Replenishment request. Review quantity and supplier before submitting.'});
  };
  buildStockBalance(){
    const h=React.createElement,s=this.state,d=s.data,products=(s.productsLocal||d.PRODUCTS).filter(p=>p.status!=='Inactive'&&!p.archivedAt);
    const list=products.filter(p=>(!s.inventoryCategory||p.category===s.inventoryCategory)&&(!s.inventorySubcategoryId||String(p.subcategoryId)===String(s.inventorySubcategoryId))&&(!s.inventoryStockFilter||(s.inventoryStockFilter==='reorder'?p.needsReorder??this.sellableStock(p)<=Number(p.minStock||0):s.inventoryStockFilter==='unavailable'?Number(p.unavailableStock||0)>0:this.inventoryCondition(p)==='Out of stock')));
    const selected=Object.values(s.invSelected||{}).filter(Boolean).length;
    const headers=['','Item Code','Item Name','Specification','Stock Unit','Qty on Hand','Reserved Qty','Available Qty','Minimum Stock','Stock Condition','Inventory Value','Category','Brand','Barcode','Supplier','Expired Qty','Recalled Qty','Unavailable Qty','Availability Remarks','Purchase Cost','Selling Price','Actions'];
    return h('div',{className:'inventory-workspace'},[
      h('div',{className:'inventory-summary'},[kpi('Items',list.length,'Matching item records'),kpi('Reorder Alerts',list.filter(p=>p.needsReorder??this.sellableStock(p)<=Number(p.minStock||0)).length,'Based on available quantity'),kpi('Unavailable Items',list.filter(p=>Number(p.unavailableStock||0)>0).length,'Expired, recalled, reserved or blocked'),kpi('Inventory Value',peso(list.reduce((n,p)=>n+Number(p.inventoryValue||0),0)),'On-hand quantities at recorded batch costs; opening costs may be estimated')]),
      h('div',{className:'purchase-filters'},[
        h('label',null,['Category',h('select',{value:s.inventoryCategory||'',onChange:e=>this.setState({inventoryCategory:e.target.value,inventorySubcategoryId:''})},[h('option',{value:''},'All categories'),...d.CATEGORIES.map(c=>h('option',{key:c.name,value:c.name},c.name))])]),
        this.buildSubcategorySelect(s.inventoryCategory,s.inventorySubcategoryId,e=>this.setState({inventorySubcategoryId:e.target.value}),{includeInactive:true}),
        h('label',null,['Stock filter',h('select',{value:s.inventoryStockFilter||'',onChange:e=>this.setState({inventoryStockFilter:e.target.value})},[['','All items'],['reorder','Reorder level reached'],['out','Out of stock'],['unavailable','Has unavailable stock']].map(([value,label])=>h('option',{value,key:value},label)))]),
        btn('Refresh balances',()=>this.refreshStockAvailability().catch(e=>this.toast(e.message,'error'))),
        btn('Export / Print',()=>this.openInventoryView('products')),
        selected?btn('Archive selected ('+selected+')',this.moveInvSelectedToArchive,'danger'):null,
      ]),
      null,
      this.recordTable('inventory-balance',headers,list.map(p=>tr([
        td(h('input',{type:'checkbox','aria-label':'Select '+p.name,checked:!!s.invSelected?.[p.id],onChange:this.toggleInvSelect(p.id)})),td(p.id),td(h('button',{className:'inventory-item-link',onClick:()=>this.openInventoryView('movements',p.id)},this.productLabel(p))),td([p.size,p.sizeUnit].filter(Boolean).join(' ')||'—'),td(p.stockUnit||p.unit||'Not set'),td(p.stockOnHand??p.stock),td(p.reservedStock||0),td(this.sellableStock(p)),td(p.minStock||0),td(badge(this.inventoryCondition(p))),td(peso(p.inventoryValue||0)),td(p.category),td((d.BRANDS||[]).find(b=>String(b.id)===String(p.brandId))?.name||'—'),td(p.barcode||'—'),td(this.purchaseSupplier(p.supplierId)),td(p.expiredStock||0),td(p.recalledStock||0),td(p.unavailableStock||0),td((p.exclusionReasons||[]).join('; ')||'Available for sale'),td(peso(p.unitPrice)),td(peso(p.price)),
        td(h('div',{className:'inventory-row-actions'},[btn('Batches',()=>this.openInventoryView('batches',p.id)),btn('Edit Item',()=>this.editProductMaster(p)),p.needsReorder?btn('Request Stock',()=>this.draftInventoryRequest(p)):null]))
      ],p.id)),{columnPicker:true,defaultHidden:['Category','Brand','Barcode','Supplier','Expired Qty','Recalled Qty','Unavailable Qty','Availability Remarks','Purchase Cost','Selling Price']}),
      h('p',{className:'inventory-help'},s.stockCheckedAt?'Balances checked '+new Date(s.stockCheckedAt).toLocaleString()+'. Click Refresh balances to update.':'Click Refresh balances to load current stock.')
    ]);
  }
  buildMgrInventory(){
    if(this.state.inventoryHistoryOpen)return this.buildInventoryHistory();
    const {data,invTab,adjustmentsLocal}=this.state; if(!data) return null;
    const tabs=[["table","Stock Balance"],["adjustments","Stock Adjustments"],["writeoffs","Stock Issues / Returns"],["recall","Batch Recall"],["reconciliation","Stock Count"],["expiry","Expiry Monitoring"],["categories","Item Groups"]];
    let body;
    if(invTab==="adjustments"){
      const {newAdjForm,evidenceFilename,evidenceSent}=this.state;
      const formReady=true;
      body=React.createElement("div",null,[
        card([
          React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"New Inventory Adjustment"),
          React.createElement("div",{key:"step2",style:{opacity:1,pointerEvents:"auto"}},[
            React.createElement("div",{key:"l",style:{fontSize:12,fontWeight:700,color:COLORS.textSoft,marginBottom:8}},"â€” Adjustment Details"),
            React.createElement("div",{key:"grid",style:{display:"grid",gridTemplateColumns:"repeat(3,minmax(0,1fr))",gap:10,marginBottom:10}},[
              React.createElement("select",{key:"p",value:newAdjForm.productId,onChange:this.setNewAdjField("productId"),style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7}},[React.createElement("option",{key:"-",value:""},"Productâ€¦"),...data.PRODUCTS.map(p=>React.createElement("option",{key:p.id,value:p.id},p.name))]),
              React.createElement("select",{key:"direction",value:this.state.adjustmentType||"decrease",onChange:e=>this.setState({adjustmentType:e.target.value}),"aria-label":"Adjustment type",style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7}},[React.createElement("option",{key:"decrease",value:"decrease"},"Stock decrease")]),
              React.createElement("input",{key:"q",value:newAdjForm.qtyChange,onChange:this.setNewAdjField("qtyChange"),type:"number",min:1,step:1,placeholder:"Adjustment quantity",style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7}}),
              React.createElement("select",{key:"r",value:newAdjForm.reason,onChange:this.setNewAdjField("reason"),style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7}},["Shrinkage","Damaged","Lost","Expired","Count correction"].map(o=>React.createElement("option",{key:o},o))),
            ]),
            React.createElement("textarea",{key:"c",value:newAdjForm.comment,onChange:this.setNewAdjField("comment"),placeholder:"Comment (required)",style:{width:"100%",padding:8,border:"1px solid "+COLORS.border,borderRadius:7,minHeight:50,marginBottom:10}}),
            React.createElement("div",{key:"evidence",className:"adjustment-evidence"},[
              React.createElement("label",{htmlFor:"adjustment-evidence-file"},"Photo evidence"),
              React.createElement("input",{id:"adjustment-evidence-file",type:"file",accept:"image/png,image/jpeg,image/webp","aria-describedby":"adjustment-evidence-help",onChange:this.chooseAdjustmentEvidence}),
              React.createElement("small",{id:"adjustment-evidence-help"},"Optional. PNG, JPEG or WebP, up to 2 MB."),
              this.state.adjustmentEvidence?React.createElement("img",{src:this.state.adjustmentEvidence,alt:"Adjustment evidence preview"}):null,
            ]),
            btn("Submit Adjustment",this.submitAdjustment,"primary"),
          ]),
        ],{marginBottom:16}),
        this.recordTable("adjustments",["Ref","Product","Qty Î”","Reason","Remarks","Evidence","Status"],adjustmentsLocal.map((a,i)=>{ const p=data.PRODUCTS.find(pp=>pp.id===a.productId);
          return tr([td(a.id,{fontFamily:"'JetBrains Mono',monospace"}),td(p?p.name:"â€”"),td(a.qtyChange,{color:COLORS.red,fontWeight:700}),td(a.reason),td(a.remarks,{fontSize:12,color:COLORS.textSoft}),td(a.photo?this.evidenceLink(a.id):'-'),td(badge(a.status))],i); })),
      ]);
    } else if(['writeoffs','recall','reconciliation','expiry'].includes(invTab)){
      body=this.buildInventoryWorkflows(invTab);
    } else if(invTab==="categories"){
      const {catSelected,categoriesLocal}=this.state; const catCount=Object.values(catSelected).filter(Boolean).length;
      const list = categoriesLocal.filter(c=>c.status==="Active");
      body=React.createElement("div",null,[
        catCount>0?React.createElement("div",{key:"bar",style:{marginBottom:10}},btn(`Move to Archive (${catCount})`,this.moveCatSelectedToArchive,"danger")):null,
        this.recordTable("inventory-categories",["","Category","Classification","Item Count","Action"],list.map((c,i)=>tr([
          td(React.createElement("input",{type:"checkbox",checked:!!catSelected[c.name],onChange:this.toggleCatSelect(c.name)})),
          td(c.name,{fontWeight:600}),td(c.classification||"Not classified"),td(data.PRODUCTS.filter(p=>p.category===c.name).length),
          td(React.createElement("button",{onClick:this.editCategory(c),style:{color:COLORS.brand,background:"none",border:"none",fontWeight:700,cursor:"pointer"}},"Edit"))],i))),
      ]);
    } else {
      body=this.buildStockBalance();
    }
    return React.createElement("div",null,[
      React.createElement("div",{style:{display:"flex",justifyContent:"space-between",alignItems:"center",marginBottom:16,flexWrap:"wrap",gap:10}},[
        this.state.stockAvailabilityError?React.createElement("p",{role:"alert"},"Live stock update failed: "+this.state.stockAvailabilityError):null,
        moduleTitle("Inventory"),
        React.createElement("div",{key:"actions",style:{display:"flex",gap:8}},[btn("+ Add Item",this.goScreen("mgrRegistration"),"primary"),btn("+ Add Category",this.openCategoryModal),btn("âš  Add Damage",this.openAddDamage,"danger")]),
      ]),
      React.createElement("div",{style:{display:"flex",gap:6,marginBottom:14,flexWrap:"wrap"}},tabs.map(([k,l])=>React.createElement("button",{key:k,onClick:this.setInvTab(k),style:{padding:"8px 14px",borderRadius:8,fontSize:12,fontWeight:700,cursor:"pointer",background:invTab===k?COLORS.brand:"#fff",color:invTab===k?"#fff":COLORS.text,border:"1px solid "+(invTab===k?COLORS.brand:COLORS.border)}},l))),
      body, React.createElement("div",{style:{marginTop:14,fontSize:12,color:COLORS.red,fontWeight:600}},"â›” Negative-inventory guard: any action dropping stock below 0 is hard-blocked."),
      this.state.priceEditOpen?this.buildPriceEditModal():null,
      this.state.addDamageOpen?this.buildAddDamageModal():null,
      this.state.categoryModalOpen?this.buildCategoryModal():null,
    ]);
  }
  buildPriceEditModal(){
    const {priceEditTarget,priceEditValue,priceEditReason,productsLocal}=this.state; const p=productsLocal.find(pp=>pp.id===priceEditTarget.productId);
    const fieldLabel={cost:"Cost Price",unitPrice:"Unit Price",price:"Retail Price"}[priceEditTarget.field];
    return React.createElement("div",{style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50}},
      React.createElement("div",{style:{width:360,background:"#fff",borderRadius:14,padding:20}},[
        React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:4}},"Edit "+fieldLabel),
        React.createElement("div",{key:"s",style:{fontSize:12,color:COLORS.textSoft,marginBottom:12}},p.name),
        React.createElement("input",{key:"v",type:"number",value:priceEditValue,onChange:this.setPriceEditValue,style:{width:"100%",padding:9,border:"1px solid "+COLORS.border,borderRadius:8,marginBottom:8}}),
        React.createElement("textarea",{key:"r",value:priceEditReason,onChange:this.setPriceEditReason,placeholder:"Reason for change (required)",style:{width:"100%",padding:9,border:"1px solid "+COLORS.border,borderRadius:8,marginBottom:12,minHeight:50}}),
        React.createElement("div",{key:"a",style:{display:"flex",gap:8}},[React.createElement("button",{key:"c",onClick:this.closePriceEdit,style:{flex:1,padding:10,background:"#f7f9fc",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Cancel"),React.createElement("button",{key:"s",onClick:this.savePriceEdit,style:{flex:1,padding:10,background:COLORS.brand,color:"#fff",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Save")]),
      ]));
  }
  buildAddDamageModal(){
    const {addDamageForm,productsLocal,damagePickerOpen,damagePickerSearch}=this.state;
    const eligible=productsLocal.filter(p=>p.status==="Active"||p.status==="Sellable");
    const selected=eligible.find(p=>String(p.id)===String(addDamageForm.productId));
    const q=Math.max(1,parseInt(addDamageForm.qty,10)||1);
    const exceeds = selected && q>selected.stock;
    const search=damagePickerSearch.toLowerCase();
    const filtered=eligible.filter(p=>searchMatches(p.name+" "+p.category,search));
    return React.createElement("div",{style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50,padding:20}},
      React.createElement("div",{style:{width:480,maxWidth:"92vw",background:"#fff",borderRadius:16,padding:28,boxShadow:"0 20px 60px rgba(6,25,20,0.25)"}},[
        React.createElement("div",{key:"h",style:{display:"flex",alignItems:"center",gap:12,marginBottom:6}},[
          React.createElement("div",{key:"i",style:{width:40,height:40,borderRadius:10,background:COLORS.redBg,color:COLORS.red,display:"flex",alignItems:"center",justifyContent:"center",fontSize:19,flexShrink:0}},"âš "),
          React.createElement("div",{key:"t",style:{fontSize:17,fontWeight:800}},"Log Damaged Stock"),
        ]),
        React.createElement("div",{key:"d",style:{fontSize:12,color:COLORS.textSoft,marginBottom:20,paddingLeft:52}},"Select the item and quantity affected â€” this will immediately deduct from sellable inventory and log to the Write-Off trail."),
        React.createElement("div",{key:"pickerWrap",style:{position:"relative",marginBottom:16}},[
          React.createElement("label",{key:"l",style:{fontSize:12,fontWeight:700,color:COLORS.textSoft,display:"block",marginBottom:6}},"Item"),
          React.createElement("div",{key:"btn",onClick:this.toggleDamagePicker,style:{padding:"11px 14px",border:"1px solid "+COLORS.border,borderRadius:9,cursor:"pointer",display:"flex",justifyContent:"space-between",alignItems:"center",background:"#fff"}},[
            React.createElement("span",{key:"v",style:{fontSize:14,color:selected?COLORS.text:COLORS.textMuted,fontWeight:selected?600:400}},selected?selected.name:"Select itemâ€¦"),
            React.createElement("span",{key:"c",style:{color:COLORS.textMuted}},"â–¾"),
          ]),
          damagePickerOpen?React.createElement("div",{key:"panel",style:{position:"absolute",top:"100%",left:0,right:0,marginTop:6,background:"#fff",border:"1px solid "+COLORS.border,borderRadius:10,boxShadow:"0 12px 30px rgba(6,25,20,0.16)",zIndex:30,maxHeight:320,display:"flex",flexDirection:"column"}},[
            React.createElement("input",{key:"s",autoFocus:true,value:damagePickerSearch,onChange:this.setDamagePickerSearch,placeholder:"Search item or categoryâ€¦",style:{margin:10,padding:9,border:"1px solid "+COLORS.border,borderRadius:7,fontSize:13}}),
            React.createElement("div",{key:"rows",style:{overflowY:"auto",flex:1}},
              filtered.length===0?React.createElement("div",{style:{padding:16,textAlign:"center",fontSize:13,color:COLORS.textMuted}},"No items match."):
              filtered.map(p=>{ const low=p.stock<=p.minStock;
                return React.createElement("div",{key:p.id,onClick:this.selectDamageProduct(p.id),style:{display:"grid",gridTemplateColumns:"minmax(0,1fr) auto auto",gap:10,alignItems:"center",padding:"9px 12px",cursor:"pointer",background:low?COLORS.redBg:"#fff",borderBottom:"1px solid #eef1f6"}},[
                  React.createElement("span",{key:"n",style:{fontSize:13,fontWeight:600,color:low?COLORS.red:COLORS.text}},(low?"âš  ":"")+p.name),
                  React.createElement("span",{key:"c",style:{fontSize:11,fontWeight:700,color:COLORS.textMuted,background:"#f0f3f9",padding:"2px 8px",borderRadius:6}},p.category),
                  React.createElement("span",{key:"s",style:{fontSize:12,fontWeight:700,color:low?COLORS.red:COLORS.textSoft}},p.stock+" in stock"),
                ]);
              })),
          ]):null,
        ]),
        selected?React.createElement("div",{key:"summary",style:{display:"grid",gridTemplateColumns:"repeat(4,minmax(0,1fr))",gap:10,padding:14,background:"#f8faff",border:"1px solid "+COLORS.border,borderRadius:10,marginBottom:16}},[
          ["Item",selected.name],["Category",selected.category],["Current Stock",selected.stock],["Unit",selected.unit],
        ].map(([l,v],i)=>React.createElement("div",{key:i},[React.createElement("div",{key:"l",style:{fontSize:10,fontWeight:700,color:COLORS.textMuted,marginBottom:2}},l),React.createElement("div",{key:"v",style:{fontSize:13,fontWeight:700}},v)]))):null,
        React.createElement("div",{key:"qtyWrap",style:{marginBottom:8}},[
          React.createElement("label",{key:"l",style:{fontSize:12,fontWeight:700,color:COLORS.textSoft,display:"block",marginBottom:6}},"Quantity Damaged"),
          React.createElement("div",{key:"stepper",style:{display:"flex",alignItems:"center",gap:10}},[
            React.createElement("button",{key:"m",onClick:this.stepDamageQty(-1),style:{width:36,height:36,borderRadius:8,border:"1px solid "+COLORS.border,background:"#fff",fontWeight:800,fontSize:16,cursor:"pointer"}},"âˆ’"),
            React.createElement("input",{key:"i",type:"number",min:1,value:addDamageForm.qty,onChange:this.setAddDamageField("qty"),style:{width:80,textAlign:"center",padding:9,border:"1px solid "+COLORS.border,borderRadius:8,fontSize:15,fontWeight:700}}),
            React.createElement("button",{key:"p",onClick:this.stepDamageQty(1),style:{width:36,height:36,borderRadius:8,border:"1px solid "+COLORS.border,background:"#fff",fontWeight:800,fontSize:16,cursor:"pointer"}},"+"),
          ]),
        ]),
        selected?React.createElement("div",{key:"preview",style:{fontSize:13,fontWeight:700,color:exceeds?COLORS.red:COLORS.textSoft,marginBottom:8}},`Current: ${selected.stock} â†’ After Damage: ${exceeds?"â€”":selected.stock-q}`):null,
        exceeds?React.createElement("div",{key:"err",style:{fontSize:12,color:COLORS.red,background:COLORS.redBg,padding:"8px 10px",borderRadius:8,fontWeight:600,marginBottom:16}},`Cannot exceed current stock (${selected.stock}) â€” negative inventory is blocked.`):React.createElement("div",{key:"sp",style:{marginBottom:16}}),
        React.createElement("div",{key:"a",style:{display:"flex",gap:10}},[
          React.createElement("button",{key:"c",onClick:this.closeAddDamage,style:{flex:1,padding:12,background:"#f7f9fc",border:"none",borderRadius:9,fontWeight:700,cursor:"pointer",fontSize:14}},"Cancel"),
          React.createElement("button",{key:"s",onClick:this.confirmAddDamage,disabled:!selected||exceeds,style:{flex:1,padding:12,background:(!selected||exceeds)?"#f3c9c9":COLORS.red,color:"#fff",border:"none",borderRadius:9,fontWeight:800,cursor:(!selected||exceeds)?"not-allowed":"pointer",fontSize:14}},"Confirm"),
        ]),
      ]));
  }
  buildMgrCategories(){
    const categories=(this.state.categoriesLocal||[]).filter(c=>!c.archivedAt);
    return React.createElement("div",null,[
      moduleTitle("Categories, Subcategories and Brands"),
      React.createElement("div",{key:"actions",style:{display:"flex",gap:8,flexWrap:"wrap",marginBottom:16}},[
        btn("+ Add Category",this.openCategoryModal,"primary"),
        btn("Register New Product",this.goScreen("mgrRegistration")),
        btn("Request Purchase",this.goScreen("mgrRequest")),
      ]),
      this.recordTable("categories",["Category","Classification","Products","Status","Actions"],categories.map(c=>tr([
        td(c.name),td(c.classification||"Not classified"),
        td((this.state.productsLocal||[]).filter(p=>p.category===c.name).length),
        td(badge(c.status)),
        td([btn("Edit",this.editCategory(c)),btn(c.status==="Active"?"Deactivate":"Activate",()=>this.authPost("/api/categories/"+encodeURIComponent(c.name),{status:c.status==="Active"?"Inactive":"Active"},"PATCH").then(()=>this.reloadCatalog()).catch(e=>this.toast(e.message,"error")))]),
      ],c.name))),
      this.buildMasterLists(),
      this.state.categoryModalOpen?this.buildCategoryModal():null,
    ]);
  }
  saveMaster=async()=>{
    if(this.masterBusy)return;
    const f=this.state.masterForm;if(!f)return;
    this.masterBusy=true;
    try{await this.authPost('/api/product-master/'+f.kind+(f.id?'/'+f.id:''),{name:f.name,category:f.category,status:f.status},f.id?'PATCH':'POST');await this.reloadCatalog();this.setState({masterForm:null});this.toast('Saved.');}
    catch(e){this.toast(e.message,'error');}finally{this.masterBusy=false;}
  };
  populateSubcategories=async()=>{
    if(this.subcategoryImportBusy)return;
    this.subcategoryImportBusy=true;
    this.setState({subcategoryImportMessage:'Adding standard subcategories...'});
    try{
      const result=await this.authPost('/api/product-master/subcategories/populate',{});
      this.setState({subcategoryImportMessage:result.added?result.message:'Standard subcategories are already present for matching categories.'});
      await this.reloadCatalog();
    }catch(error){this.setState({subcategoryImportMessage:error.message});}
    finally{this.subcategoryImportBusy=false;}
  };
  populateBrands=async()=>{
    if(this.brandImportBusy)return;
    this.brandImportBusy=true;
    this.setState({brandImportMessage:'Adding standard brands...'});
    try{
      const result=await this.authPost('/api/product-master/brands/populate',{});
      this.setState({brandImportMessage:result.added?result.message:'Standard brands are already present.'});
      await this.reloadCatalog();
    }catch(error){this.setState({brandImportMessage:error.message});}
    finally{this.brandImportBusy=false;}
  };
  buildMasterLists(){
    const h=React.createElement,f=this.state.masterForm,data=this.state.data;
    return h('section',null,[...['brands','subcategories'].map(kind=>card([
      sectionTitle(kind==='brands'?'Brands':'Subcategories'),btn('Add '+(kind==='brands'?'brand':'subcategory'),()=>this.setState({masterForm:{kind,name:'',category:'',status:'Active'}})),
      kind==='brands'?h('div',{style:{margin:'12px 0'}},[
        btn('Add standard brands',this.populateBrands,'primary'),
        null,
        this.state.brandImportMessage?h('p',{role:'status'},this.state.brandImportMessage):null
      ]):null,
      kind==='subcategories'?h('div',{style:{margin:'12px 0'}},[
        btn('Add standard subcategories',this.populateSubcategories,'primary'),
        null,
        this.state.subcategoryImportMessage?h('p',{role:'status'},this.state.subcategoryImportMessage):null
      ]):null,
      this.recordTable(kind,['Name',...(kind==='subcategories'?['Category']:[]),'Status','Actions'],(data[kind.toUpperCase()]||[]).map(r=>tr([td(r.name),...(kind==='subcategories'?[td(r.category)]:[]),td(badge(r.status)),td([btn('Edit',()=>this.setState({masterForm:{...r,kind}})),btn(r.status==='Active'?'Deactivate':'Activate',async()=>{try{await this.authPost('/api/product-master/'+kind+'/'+r.id,{...r,status:r.status==='Active'?'Inactive':'Active'},'PATCH');await this.reloadCatalog();}catch(e){this.toast(e.message,'error');}})])],r.id)))
    ])),f?card([
      h('h3',null,(f.id?'Edit ':'Add ')+f.kind),h('label',null,['Name ',h('input',{value:f.name,maxLength:100,onChange:e=>this.setState({masterForm:{...f,name:e.target.value}})})]),
      f.kind==='subcategories'?h('label',null,['Category ',h('select',{value:f.category,onChange:e=>this.setState({masterForm:{...f,category:e.target.value}})},[h('option',{value:''},'Select category'),...data.CATEGORIES.filter(c=>c.status==='Active').map(c=>h('option',{value:c.name,key:c.name},c.name))])]):null,
      btn('Save',this.saveMaster),btn('Cancel',()=>this.setState({masterForm:null}))
    ]):null]);
  }
  loadInventoryHistory=async(filters=this.state.inventoryAppliedFilters||{})=>{
    const requestId=(this.inventoryLoadId||0)+1;this.inventoryLoadId=requestId;
    this.inventoryController?.abort();const controller=new AbortController();this.inventoryController=controller;
    this.setState({inventoryHistoryLoading:true,inventoryHistoryError:''});
    try{const query=new URLSearchParams(Object.entries(filters).filter(([,v])=>v));const r=await fetch('/api/inventory-history?'+query,{headers:{Accept:'application/json'},signal:controller.signal});const body=await r.json();if(!r.ok)throw new Error(body.message||'Could not load inventory records.');if(this.inventoryLoadId===requestId)this.setState({inventoryHistory:body,inventoryLoadedFilters:{...filters}});}
    catch(e){if(this.inventoryLoadId===requestId)this.setState({inventoryHistoryError:e.message});}finally{if(this.inventoryLoadId===requestId)this.setState({inventoryHistoryLoading:false});}
  };
  setInventoryHistoryFilter=(key,value)=>{
    const filters={...(this.state.inventoryFilters||{}),[key]:value,...(key==='category'?{subcategoryId:'',productId:''}:['brandId','subcategoryId','supplierId'].includes(key)?{productId:''}:{})};
    this.setState({inventoryFilters:filters,inventoryAppliedFilters:filters});this.loadInventoryHistory(filters);
  };
  applyInventoryFilters=()=>{const filters={...(this.state.inventoryFilters||{})};this.setState({inventoryAppliedFilters:filters});this.loadInventoryHistory(filters);};
  inventorySchema=kind=>({
    products:[['Item Code','id'],['Item Name','name'],['Specification','specification'],['Barcode','barcode'],['Category','category'],['Brand','brand'],['Stock Unit','stockUnit'],['Qty on Hand','stockOnHand'],['Reserved Qty','reservedStock'],['Available Qty','sellableStock'],['Minimum Stock','minStock'],['Stock Condition','stockCondition'],['Inventory Value','inventoryValue'],['Valuation Basis','valuationBasis'],['Unavailable Qty','unavailableStock'],['Availability Remarks','exclusionReasons']],
    batches:[['Item Code','productId'],['Item Name','name'],['Batch No.','batchNumber'],['Delivery Date','receivedDate'],['Requested Date / Time','requestedAt'],['Received Date / Time','receivedAt'],['PO Number','poId'],['Receipt ID','receiptId'],['Supplier','supplier'],['Stock Unit','stockUnit'],['Qty Received','quantityReceived'],['Qty on Hand','stockOnHand'],['Available Qty','sellableQuantity'],['Unit Cost','unitCost'],['Inventory Value','inventoryValue'],['Expiry Date','expiryDate'],['Manufactured Date','manufacturedDate'],['Manufacturer','manufacturer'],['Availability Remarks','exclusionReasons'],['Recorded By','receivedBy'],['Source','sourceLabel']],
    movements:[['Posted At','created_at'],['Item Code','productId'],['Item Name','name'],['Transaction Type','transactionType'],['Reference No.','referenceId'],['PO Number','poId'],['Batch References','batchNumbers'],['Stock Unit','stockUnit'],['Qty Increase','quantityIn'],['Qty Decrease','quantityOut'],['Balance Before','quantityBefore'],['Balance After','quantityAfter'],['Recorded By','recordedBy'],['Remarks','remarks']]
  }[kind]||[]);
  inventoryRows=kind=>{
    const d=this.state.data||{},history=this.state.inventoryHistory;if(!history)return [];
    return (history[kind]||[]).map(row=>({...row,...(kind==='products'?{name:this.productLabel(row),brand:(d.BRANDS||[]).find(b=>String(b.id)===String(row.brandId))?.name||'',specification:[row.size,row.sizeUnit].filter(Boolean).join(' '),stockCondition:this.inventoryCondition(row)}:{}),...(kind==='batches'?{receivedDate:row.source==='legacy_opening'?'Opening balance (date unknown)':row.receivedDate,sourceLabel:({legacy_opening:'Opening balance (estimated cost)',purchase_receiving:'Purchase receipt',legacy_return:'Legacy customer return',count_surplus:'Stock count surplus'})[row.source]||String(row.source||'').replace(/_/g,' ')}:{}),exclusionReasons:(row.exclusionReasons||[]).join('; ')}));
  };
  inventoryExportData=kind=>{
    const schema=this.inventorySchema(kind),query=this.state.tableViews['inventory-'+kind]?.query||'';
    const rows=this.inventoryRows(kind).filter(row=>!query.trim()||searchMatches(schema.map(([,key])=>String(row[key]??'')).join(' '),query));
    return {schema,rows};
  };
  exportInventoryHistory=kind=>()=>{
    if(!this.state.inventoryHistory||this.state.inventoryHistoryLoading||this.state.inventoryHistoryError)return;
    const {schema,rows}=this.inventoryExportData(kind);
    const cell=value=>{let text=String(value??'');if(/^[=+@\-\t\r]/.test(text))text="'"+text;return '"'+text.replace(/"/g,'""')+'"';};
    const csv=[schema.map(([label])=>label),...rows.map(row=>schema.map(([,key])=>row[key]))].map(row=>row.map(cell).join(',')).join('\r\n');
    const url=URL.createObjectURL(new Blob(['\ufeff'+csv],{type:'text/csv;charset=utf-8;'}));const link=document.createElement('a');link.href=url;link.download='kita-'+kind+'.csv';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  };
  printInventoryHistory=kind=>()=>{
    if(!this.state.inventoryHistory||this.state.inventoryHistoryLoading||this.state.inventoryHistoryError)return;
    const report=window.open('','_blank');if(!report){this.toast('Allow pop-ups to print this report.','error');return;}
    const {schema,rows}=this.inventoryExportData(kind),esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const title={products:'Stock Balance',batches:'Batch Records',movements:'Inventory Ledger'}[kind];
    const note=kind==='movements'?'Balances are unreserved book quantities. Payment reservations and releases change this balance without a physical receipt or issue.':'Current quantities and values reflect the latest loaded balances. Receipt-date filters do not reconstruct historical stock.';
    report.document.write('<!doctype html><html><head><title>KITA '+esc(title)+'</title><style>@page{size:landscape;margin:10mm}body{font:11px Arial;color:#172033}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccd4df;padding:5px;text-align:left;overflow-wrap:anywhere}thead{display:table-header-group}th{background:#eef3fa}h1{font-size:20px}@media print{button{display:none}}</style></head><body><button onclick="window.print()">Print / Save PDF</button><h1>KITA — '+esc(title)+'</h1><p>Checked '+esc(this.state.inventoryHistory.checkedAt)+' · '+rows.length+' records</p><p>'+esc(note)+'</p><p>Applied filters: '+esc(Object.entries(this.state.inventoryLoadedFilters||{}).filter(([,v])=>v).map(([key,value])=>(({from:'From',to:'To',productId:'Item Code',supplierId:'Supplier ID',brandId:'Brand ID',category:'Category',subcategoryId:'Item Group ID',lowStock:'Reorder items',movementType:'Transaction Type'})[key]||key)+': '+value).join('; ')||'All records')+'</p><table><thead><tr>'+schema.map(([label])=>'<th>'+esc(label)+'</th>').join('')+'</tr></thead><tbody>'+rows.map(row=>'<tr>'+schema.map(([,key])=>'<td>'+esc(row[key])+'</td>').join('')+'</tr>').join('')+'</tbody></table></body></html>');report.document.close();report.opener=null;
  };
  buildInventoryHistory(embedded=false){
    const h=React.createElement,d=this.state.data,f=this.state.inventoryFilters||{},history=this.state.inventoryHistory,kind=this.state.inventoryView||'products';
    const titles={products:'Stock Balance',batches:'Batch Records',movements:'Inventory Ledger'};
    const field=(key,label,options)=>h('label',{key},[label,options?h('select',{value:f[key]||'',onChange:e=>this.setInventoryHistoryFilter(key,e.target.value)},[h('option',{value:''},'All'),...options.map(([value,text])=>h('option',{key:value,value},text))]):h('input',{type:'date',value:f[key]||'',onChange:e=>this.setInventoryHistoryFilter(key,e.target.value)})]);
    const schema=this.inventorySchema(kind),rows=this.inventoryRows(kind),busy=this.state.inventoryHistoryLoading,blocked=!history||busy||!!this.state.inventoryHistoryError;
    return h('div',{className:'inventory-workspace'},[sectionTitle(titles[kind]),
      !embedded?h('div',{className:'inventory-view-tabs'},[btn(kind==='batches'?'Back to Stock Receiving':'Back to Inventory',()=>this.setState({inventoryHistoryOpen:false})),...Object.entries(titles).filter(([view])=>view===kind).map(([view,label])=>h('button',{type:'button',className:'kita-button','aria-pressed':kind===view,onClick:()=>{const filters={...f};delete filters.from;delete filters.to;delete filters.lowStock;delete filters.movementType;this.setState({inventoryView:view,inventoryFilters:filters,inventoryAppliedFilters:filters});this.loadInventoryHistory(filters);}},label))]):null,
      h('div',{className:'purchase-filters'},[field('category','Category',d.CATEGORIES.map(c=>[c.name,c.name])),this.buildSubcategorySelect(f.category,f.subcategoryId,e=>this.setInventoryHistoryFilter('subcategoryId',e.target.value),{includeInactive:true}),field('brandId','Brand',(d.BRANDS||[]).map(c=>[c.id,c.name])),field('productId','Item',d.PRODUCTS.filter(p=>(!f.category||p.category===f.category)&&(!f.subcategoryId||String(p.subcategoryId)===String(f.subcategoryId))&&(!f.brandId||String(p.brandId)===String(f.brandId))&&(kind==='batches'||!f.supplierId||String(p.supplierId)===String(f.supplierId))).map(c=>[c.id,c.name+' #'+c.id])),field('supplierId',kind==='batches'?'Receipt supplier':'Default item supplier',d.SUPPLIERS.map(c=>[c.id,c.name])),...(kind==='products'?[field('lowStock','Stock filter',[['1','Reorder level reached']])]:[field('from',kind==='movements'?'Posted from':'Receipt date from'),field('to',kind==='movements'?'Posted to':'Receipt date to')]),kind==='movements'?field('movementType','Transaction type',Object.entries(history?.movementTypes||{})):null]),
      h('div',{className:'inventory-view-tabs'},[h('button',{className:'kita-button',disabled:busy,onClick:this.applyInventoryFilters},busy?'Refreshing...':'Refresh'),btn('Clear filters',()=>{this.setState({inventoryFilters:{},inventoryAppliedFilters:{}});this.loadInventoryHistory({});}),h('button',{className:'kita-button',disabled:blocked,onClick:this.exportInventoryHistory(kind)},'Export CSV (Excel)'),h('button',{className:'kita-button',disabled:blocked,onClick:this.printInventoryHistory(kind)},'Print / Save PDF')]),
      null,
      kind==='movements'&&history?h('p',{className:'inventory-help'},'Showing '+history.movements.length+' of '+history.movementCount+' matching entries'+(history.movementCount>history.movementLimit?'. Limited to the latest '+history.movementLimit+'; narrow the date or item filters.':'.')):null,
      this.state.inventoryHistoryError?h('p',{role:'alert'},'Could not refresh inventory: '+this.state.inventoryHistoryError):null,
      history?(kind==='batches'?this.groupedBatchTables('inventory-batches',rows,schema.map(([label])=>label),row=>tr(schema.map(([,key])=>td(['unitCost','inventoryValue'].includes(key)?peso(row[key]):key==='poId'&&row[key]?h('a',{href:'/purchase-orders/'+encodeURIComponent(row[key])+'/report',target:'_blank',rel:'noopener'},row[key]):(['requestedAt','receivedAt'].includes(key)?(row[key]||'Not recorded'):row[key]??'—'))),row.id),{columnPicker:true,defaultHidden:kind==='products'?['Barcode','Category','Brand','Valuation Basis','Unavailable Qty','Availability Remarks']:kind==='batches'?['Manufactured Date','Manufacturer','Recorded By','Source']:['PO Number','Batch References','Remarks']}):this.recordTable('inventory-'+kind,schema.map(([label])=>label),rows.map(row=>tr(schema.map(([,key])=>td(['unitCost','inventoryValue'].includes(key)?peso(row[key]):key==='poId'&&row[key]?h('a',{href:'/purchase-orders/'+encodeURIComponent(row[key])+'/report',target:'_blank',rel:'noopener'},row[key]):(['requestedAt','receivedAt'].includes(key)?(row[key]||'Not recorded'):row[key]??'—'))),row.id)),{columnPicker:true,defaultHidden:kind==='products'?['Barcode','Category','Brand','Valuation Basis','Unavailable Qty','Availability Remarks']:kind==='batches'?['Manufactured Date','Manufacturer','Recorded By','Source']:['PO Number','Batch References','Remarks']})):loadingStatus('Loading inventory records...'),
      history?h('p',{className:'inventory-help'},'Checked '+new Date(history.checkedAt).toLocaleString()):null
    ]);
  }
  buildCategoryModal(){
    const h=React.createElement,{categoryForm:f,categoryError,categoryEditing}=this.state;
    const step=categoryEditing?'category':this.state.categoryStep||'category';
    const steps=['category','subcategory','brand'],stepIndex=steps.indexOf(step);
    const dialog=children=>h('div',{key:'category-setup-overlay',style:{position:'fixed',inset:0,background:'rgba(15,31,74,0.38)',display:'flex',alignItems:'center',justifyContent:'center',zIndex:50,padding:16},onKeyDown:e=>{if(e.key==='Escape')this.closeCategoryModal();}},
      h('div',{key:'category-setup-dialog',role:'dialog','aria-modal':true,'aria-labelledby':'category-dialog-title',className:'category-dialog',style:{width:460,maxWidth:'100%',maxHeight:'90vh',overflowY:'auto',background:'#fff',padding:24}},[
        h('h2',{key:'title',id:'category-dialog-title',className:'panel-title'},categoryEditing?'Edit category':'Add category'),
        !categoryEditing?h('ol',{key:'steps','aria-label':'Category setup steps',style:{display:'flex',gap:8,listStyle:'none',padding:0,margin:'16px 0'}},steps.map((name,index)=>h('li',{key:name,'aria-current':step===name?'step':undefined,style:{flex:1,padding:'10px 6px',borderRadius:8,fontSize:13,fontWeight:700,textAlign:'center',background:index===stepIndex?COLORS.brand:COLORS.brandBg,color:index===stepIndex?'#fff':COLORS.brandDark}},`${index+1}. ${name[0].toUpperCase()+name.slice(1)}`))):null,
        !categoryEditing?h('p',{key:'progress',role:'status'},`Step ${stepIndex+1} of 3`):null,
        h('div',{key:step},children)
      ]));
    if(step!=='category'){
      const isSub=step==='subcategory',s=this.state;
      return dialog([
          h('h3',null,isSub?'Add subcategory':'Add brand'),
          h('p',null,'Category: '+s.categorySetupCategory),
          !isSub?h('p',null,'Subcategory: '+((s.data.SUBCATEGORIES||[]).find(r=>String(r.id)===String(s.regSubcategoryId))?.name||'')):null,
          h('div',{className:'field-group'},[
            h('label',{htmlFor:'category-setup-name'},isSub?'Subcategory name *':'New brand name'),
            h('input',{key:step,id:'category-setup-name',autoFocus:true,maxLength:100,value:s.categorySetupName||'',placeholder:isSub?'e.g. Refrigerators':'e.g. Samsung',onChange:e=>this.setState({categorySetupName:e.target.value,...(!isSub?{categorySetupBrandId:''}:{}),categoryError:''})})
          ]),
          !isSub?h('div',{className:'field-group'},[h('label',{htmlFor:'category-setup-brand'},'Or choose an existing brand'),h('select',{id:'category-setup-brand',value:s.categorySetupBrandId||'',onChange:e=>this.setState({categorySetupBrandId:e.target.value,categorySetupName:'',categoryError:''})},[h('option',{value:''},'Select brand'),...(s.data.BRANDS||[]).filter(b=>b.status==='Active').map(b=>h('option',{key:b.id,value:b.id},b.name))])]):null,
          categoryError?h('div',{role:'alert',className:'alert'},categoryError):null,
          null,
          h('div',{className:'dialog-actions'},[btn('Cancel',this.closeCategoryModal),btn('Previous',this.previousCategoryStep),btn(isSub?'Next: Brand':'Finish setup',this.saveCategory,'primary')])
        ]);
    }
    return dialog([
        null,
        h("div",{key:"name",className:"field-group"},[
          h("label",{htmlFor:"category-name"},"Category name *"),h("input",{id:"category-name",autoFocus:true,required:true,value:f.name,onChange:this.setCategoryField("name"),maxLength:100,placeholder:"e.g. Household supplies","aria-invalid":!!categoryError,"aria-describedby":categoryError?"category-error":undefined}),
        ]),
        h("div",{key:"classification",className:"field-group"},[
          h("label",{htmlFor:"category-classification"},"Classification *"),h("select",{id:"category-classification",required:true,value:f.classification||"",onChange:this.setCategoryField("classification"),"aria-invalid":!!categoryError,"aria-describedby":categoryError?"category-error":undefined},[h("option",{key:"empty",value:""},"Select classification"),...["Perishable","Non-Perishable"].map(value=>h("option",{key:value,value},value))]),
        ]),
        categoryError?h("div",{key:"error",id:"category-error",role:"alert",className:"alert"},categoryError):null,
        !categoryEditing&&this.state.categorySetupCategory?null:null,
        h("div",{key:"actions",className:"dialog-actions"},[btn("Cancel",this.closeCategoryModal),btn(categoryEditing?"Save changes":"Next: Subcategory",this.saveCategory,"primary")]),
      ]);
  }
  subcategoriesFor=(category,includeInactive=false)=>(this.state.data.SUBCATEGORIES||[]).filter(c=>c.category===category&&(includeInactive||c.status==='Active'));
  buildSubcategorySelect(category,value,onChange,{registration=false,includeInactive=false}={}){
    const h=React.createElement,options=this.subcategoriesFor(category,includeInactive);
    const placeholder=!category?'Select category first':!options.length?'No subcategories':registration?'Select subcategory':'All subcategories';
    return h('label',{key:'subcategory',className:'field-group'},['Subcategory',h('select',{'aria-label':'Subcategory',value:value||'',onChange,disabled:!category||!options.length,required:registration},[
      h('option',{key:'empty',value:''},placeholder),...options.map(c=>h('option',{key:c.id,value:c.id},c.name))
    ])]);
  }
  productLabel=p=>[p.name,p.variantLabel,(this.state.data?.BRANDS||[]).find(b=>String(b.id)===String(p.brandId))?.name,p.size?Number(p.size):null,p.sizeUnit].filter(Boolean).join(' ');
  editProductMaster=p=>this.setState({screen:'mgrRegistration',regEditing:p.id,registrationNotice:null,registrationError:"",regParentId:p.parentId||'',regVariantLabel:p.variantLabel||'',regStockId:String(p.id),regProductName:p.name,regManufacturer:p.manufacturer||'',regCategory:p.category,regBrandId:p.brandId||'',regSubcategoryId:p.subcategoryId||'',regSize:p.size||'',regSizeUnit:p.sizeUnit||'',regReorderLevel:p.minStock||0,regScannedBarcode:p.barcode||'',regSupplier:(this.state.data.SUPPLIERS.find(s=>s.id===p.supplierId)||{}).name||'',regStockUnit:p.stockUnit||p.unit,regPurchaseUnit:p.purchaseUnit||p.unit,regConversionFactor:p.conversionFactor||1,regUnitPrice:String(p.unitPrice??0),regRetailPrice:String(p.price??0),regStatus:p.status,regSaved:false,regBatch:'',regLot:'',regExpiry:'',regEditReason:''});
  buildMgrRegistration(){
    const h=React.createElement,s=this.state,data=s.data;
    const products=s.productsLocal||data.PRODUCTS;
    const units=[...new Set(["Piece","Pair","Set","Dozen","Pack","Packet","Pouch","Bag","Box","Case","Carton","Sack","Tray","Bottle","Can","Jar","Tub","Cup","Tube","Roll","Sheet","Bundle","Bunch","Bar","Stick","Sachet","Strip","Blister pack","Tablet","Capsule","Vial","Ampoule","Drum","Bucket","Pail","Crate","Pallet","Kg","g","Liter","mL","m",s.regStockUnit].filter(Boolean))];
    const measurement=h('div',{className:'field-group'},[
      h('label',{htmlFor:'registration-regSizeUnit'},'Measurement Unit'),
      h('select',{id:'registration-regSizeUnit',required:true,'aria-required':true,value:s.regSizeUnit||'',onChange:this.setRegField('regSizeUnit')},[
        h('option',{value:''},'Select measurement unit'),
        ...Object.entries(PRODUCT_MEASUREMENT_UNITS).map(([group,values])=>h('optgroup',{key:group,label:group},Object.entries(values).map(([value,label])=>h('option',{key:value,value},label)))),
      ]),
    ]);
    const field=(key,label,options={})=>{
      options={...options,required:!options.readOnly};
      const id="registration-"+key,helpId=id+"-help";
      return h("div",{key,className:"field-group"},[
        h("label",{key:"label",htmlFor:id},[label,options.required?h("span",{className:"required-marker","aria-hidden":true}," *"):null]),
        h("input",{key:"input",id,type:options.type||"text",value:s[key]??"",onChange:options.readOnly?undefined:this.setRegField(key),readOnly:!!options.readOnly,required:!!options.required,"aria-required":!!options.required,"aria-describedby":options.help?helpId:undefined,placeholder:options.placeholder||label,...(options.type==="number"?{min:0,step:options.step||"0.01"}:{}),...(key==="regProductName"?{list:"reg-product-options"}:{})}),
        options.help?h("small",{key:"help",id:helpId},options.help):null,
      ]);
    };
    const select=(key,label,values,required=true)=>h("div",{key,className:"field-group"},[
      h("label",{key:"label",htmlFor:"registration-"+key},[label,required?h("span",{className:"required-marker","aria-hidden":true}," *"):null]),
      h("select",{key:"input",id:"registration-"+key,value:s[key],onChange:this.setRegField(key),required,"aria-required":required},[h("option",{key:"empty",value:""},"Select "+label.toLowerCase()),...values.map(value=>h("option",{key:value,value},["regSizeUnit","regStockUnit"].includes(key)?({mL:"mL (Milliliter)",Liter:"L (Liter)",g:"g (Gram)",kg:"kg (Kilogram)",Kg:"kg (Kilogram)",cm:"cm (Centimeter)",m:"m (Meter)"}[value]||value):value))]),
    ]);
    const group=(number,title,description,fields)=>h("fieldset",{className:"form-section",key:title},[
      h("legend",null,[h("span",{className:"section-number"},number),title]),null,h("div",{className:"form-grid"},fields),
    ]);
    return h("div",{className:"registration-page"},[
      s.regEditing?sectionTitle("Edit Product"):moduleTitle("Product Registration"),
      h("div",{key:"actions",className:"page-toolbar"},[btn("New Product",()=>this.setState({regEditing:null,regParentId:"",regVariantLabel:"",registrationNotice:null,registrationError:"",regStockId:"",regProductName:"",regManufacturer:"",regScannedBarcode:"",regSaved:false,regBatch:"",regLot:"",regExpiry:"",regUnitPrice:"",regRetailPrice:"",regCategory:"",regSupplier:"",regReorderLevel:0,regPurchaseUnit:"Piece",regStockUnit:"Piece",regConversionFactor:"1",regStatus:"Active",regBrandId:"",regSubcategoryId:"",regSize:"",regSizeUnit:"",regEditReason:""}))]),
      h('div',{id:'registration-feedback',className:'registration-feedback'},[s.registrationNotice?h('p',{role:s.registrationNotice.tone==='error'?'alert':'status',className:'registration-notice registration-notice--'+s.registrationNotice.tone},s.registrationNotice.message):null,
      s.regSaved?h("div",{key:"print",className:"kita-card registration-receipt"},[
        h("h2",{className:"panel-title"},"Registration complete"),h("p",null,`Item Code: ${s.regSku} | Barcode: ${s.regBarcode} | Available stock: ${s.regRegisteredQty}`),
        h("label",{className:"record-page-size"},["Label quantity",h("input",{type:"number",min:1,value:s.regPrintQty,onChange:this.setRegPrintQty})]),btn("Print labels",this.printLabels),
      ]):null,
      ]),
      h("datalist",{key:"names",id:"reg-product-options"},[...new Set(products.map(p=>p.name))].filter(name=>searchMatches(name,s.regProductName)).map(name=>h("option",{key:name,value:name}))),
      (s.registrationError||(!s.regSaved&&this.registrationDuplicate()))?h('p',{role:'alert',className:'alert'},s.registrationError||'This product already exists in inventory (Item Code '+this.registrationDuplicate().id+'). Use the existing product or choose a different brand or size.'):null,
      group("01","Product details","Identify the item and choose its category and supplier.",[
        field("regProductName","Product name",{required:true}),field("regManufacturer","Manufacturer",{help:"Company that makes the product; separate from brand and supplier."}),s.regEditing?field("regStockId","Item Code",{type:"number",step:1,readOnly:true}):null,
        h('label',{className:'field-group'},['Product type *',h('select',{required:true,value:s.regParentId||'main',onChange:e=>this.setRegField('regParentId')({target:{value:e.target.value==='main'?'':e.target.value}})},[h('option',{value:'main'},'Main product / no parent'),...products.filter(p=>!p.parentId&&p.id!==s.regEditing&&p.status==='Active'&&p.category===s.regCategory).map(p=>h('option',{key:p.id,value:p.id},p.name+' (#'+p.id+')'))])]),
        s.regParentId?field('regVariantLabel','Variant label',{required:true,help:'For example: Red, Vanilla or Family pack. Each variant has its own barcode and stock.'}):null,
        field("regScannedBarcode","Barcode",{required:true,placeholder:"Scan or enter the item barcode"}),
        h('div',{className:'registration-category'},[select("regCategory","Category",data.CATEGORIES.filter(c=>c.status==="Active").map(c=>c.name),true),btn('+ Add Category',this.openCategoryModal)]),
        h("label",{className:"field-group"},["Brand *",h("select",{required:true,"aria-required":true,value:s.regBrandId||"",onChange:this.setRegField("regBrandId")},[h("option",{value:""},"Select brand"),...(data.BRANDS||[]).filter(b=>b.status==="Active").map(b=>h("option",{value:b.id,key:b.id},b.name))])]),
        this.buildSubcategorySelect(s.regCategory,s.regSubcategoryId,this.setRegField("regSubcategoryId"),{registration:true}),
        h('div',{className:'registration-size'},[h('div',{className:'registration-size-fields'},[field("regSize","Size",{type:"number",step:"0.001",placeholder:'e.g. 500'}),measurement]),null]),
        select("regSupplier","Supplier",data.SUPPLIERS.filter(sp=>sp.status==="Active").map(sp=>sp.name)),
        select("regStatus","Status",["Active","Inactive"]),
      ]),
      group("02","Stock settings","Stock Unit is one inventory unit, such as Bottle, Pack, or Piece. Quantities are whole units; use Size and Measurement Unit for the contents (for example, 500 mL per Bottle). New products start with zero stock.",[
        select("regStockUnit","Stock Unit",units,true),
        field("regReorderLevel","Minimum Stock",{type:"number",step:1,help:'Low-stock threshold, counted in the selected Stock Unit.'}),
      ]),
      s.regEditing?group("03","Pricing","Purchase cost and selling price are recorded separately.",[
        field("regUnitPrice","Purchase cost per stock unit (\u20B1)",{type:"number",required:true}),

        field("regRetailPrice","Selling price per stock unit (\u20B1)",{type:"number",help:"Enter the selling price for one stock unit."}),
      ]):null,
      s.regEditing?field("regEditReason","Reason for changes"):null,
      h("div",{key:"save",className:"form-actions"},[
        h("span",{className:"form-action-note"},"All editable fields are required. Enter 0 for Minimum Stock if no low-stock threshold is needed."),
        h("button",{type:"button",className:"primary-button",disabled:!!s.registrationSaving,"aria-busy":!!s.registrationSaving,onClick:this.saveRegistration},s.registrationSaving?"Saving product...":s.regSaved?"Product registered":"Save product registration"),
      ]),
      s.categoryModalOpen?this.buildCategoryModal():null,
    ]);
  }
  buildMgrCashiers(){ return this.buildAdmManagers(); }
  buildPromoPickerModal(){
    const {data,promoPickerSearch,promoPickerChecked}=this.state; const q=promoPickerSearch.toLowerCase();
    const items=data.PRODUCTS.filter(p=>searchMatches(p.name,q));
    const count=Object.values(promoPickerChecked).filter(Boolean).length;
    return React.createElement("div",{style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50,padding:20}},
      React.createElement("div",{style:{width:520,maxWidth:"92vw",maxHeight:"78vh",background:"#fff",borderRadius:14,padding:20,display:"flex",flexDirection:"column",boxSizing:"border-box"}},[
        React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"Select Item(s) for Promotion"),
        React.createElement("input",{key:"s",value:promoPickerSearch,onChange:this.setPromoPickerSearch,placeholder:"Searchâ€¦",style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7,marginBottom:10}}),
        React.createElement("div",{key:"list",style:{flex:1,overflowY:"auto",border:"1px solid "+COLORS.border,borderRadius:8}},
          items.map(p=>React.createElement("label",{key:p.id,style:{display:"flex",alignItems:"center",gap:10,padding:"9px 12px",borderBottom:"1px solid #eef1f6",cursor:"pointer"}},[
            React.createElement("input",{key:"cb",type:"checkbox",checked:!!promoPickerChecked[p.id],onChange:this.togglePromoPickerCheck(p.id)}),
            React.createElement("div",{key:"n",style:{flex:1,fontSize:13,fontWeight:600}},p.name),
            React.createElement("div",{key:"p",style:{fontSize:12,color:COLORS.textSoft}},peso(p.price)),
          ]))),
        React.createElement("div",{key:"footer",style:{display:"flex",justifyContent:"space-between",alignItems:"center",marginTop:14}},[
          React.createElement("span",{key:"c",style:{fontSize:13,fontWeight:700,color:COLORS.brandDark}},count+" item(s) selected"),
          React.createElement("div",{key:"a",style:{display:"flex",gap:8}},[React.createElement("button",{key:"c",onClick:this.closePromoPicker,style:{padding:"9px 14px",background:"#f7f9fc",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Cancel"),React.createElement("button",{key:"a",onClick:this.confirmPromoPicker,style:{padding:"9px 14px",background:COLORS.brand,color:"#fff",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Confirm Selection")]),
        ]),
      ]));
  }
  buildMgrSupplierProductPicker(){
    const {productsLocal,supMgrProductSearch,supMgrProductChecked}=this.state; const q=supMgrProductSearch.toLowerCase();
    const items=(productsLocal||[]).filter(p=>p.status!=="Inactive"&&searchMatches(p.name,q));
    const count=Object.values(supMgrProductChecked).filter(Boolean).length;
    return React.createElement("div",{style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50,padding:20}},
      React.createElement("div",{style:{width:480,maxWidth:"92vw",maxHeight:"78vh",background:"#fff",borderRadius:14,padding:20,display:"flex",flexDirection:"column",boxSizing:"border-box"}},[
        React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"Add Products"),
        React.createElement("input",{key:"s",value:supMgrProductSearch,onChange:this.setSupMgrProductSearch,placeholder:"Searchâ€¦",style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7,marginBottom:10}}),
        React.createElement("div",{key:"list",style:{flex:1,overflowY:"auto",border:"1px solid "+COLORS.border,borderRadius:8}},items.map(p=>React.createElement("label",{key:p.id,style:{display:"flex",alignItems:"center",gap:10,padding:"9px 12px",borderBottom:"1px solid #eef1f6",cursor:"pointer"}},[
          React.createElement("input",{key:"cb",type:"checkbox",checked:!!supMgrProductChecked[p.id],onChange:this.toggleSupMgrProductCheck(p.id)}),
          React.createElement("div",{key:"n",style:{flex:1,fontSize:13,fontWeight:600}},p.name),React.createElement("div",{key:"c",style:{fontSize:11,color:COLORS.textMuted}},p.category),
        ]))),
        React.createElement("div",{key:"footer",style:{display:"flex",justifyContent:"space-between",alignItems:"center",marginTop:14}},[
          React.createElement("span",{key:"c",style:{fontSize:13,fontWeight:700,color:COLORS.brandDark}},count+" selected"),
          React.createElement("div",{key:"a",style:{display:"flex",gap:8}},[React.createElement("button",{key:"c",onClick:this.closeSupMgrProductPicker,style:{padding:"9px 14px",background:"#f7f9fc",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Cancel"),React.createElement("button",{key:"a",onClick:this.addSupMgrSelectedProducts,style:{padding:"9px 14px",background:COLORS.brand,color:"#fff",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Add Selected")]),
        ]),
      ]));
  }
  buildMgrSupplierModal(){
    const {supMgrForm}=this.state;
    const f=(label,field,type)=>React.createElement("div",{key:field},[React.createElement("label",{style:{fontSize:12,fontWeight:700,color:COLORS.textSoft}},label),React.createElement("input",{type:type||"text",value:supMgrForm[field],onChange:this.setSupMgrFormField(field),style:{width:"100%",padding:8,border:"1px solid "+COLORS.border,borderRadius:7,marginTop:4}})]);
    return React.createElement("div",{style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50,padding:20}},
      React.createElement("div",{style:{width:460,maxWidth:"92vw",background:"#fff",borderRadius:14,padding:20}},[
        React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:12}},supMgrForm.id==null?"Add Supplier":"Edit Supplier"),
        React.createElement("div",{key:"grid",style:{display:"grid",gridTemplateColumns:"repeat(2,minmax(0,1fr))",gap:10,marginBottom:12}},[
          f("Supplier Name","name"),f("Contact Person","contact"),f("Contact Number","phone"),f("Email (optional)","email"),f("Supply Category","category"),
          React.createElement("div",{key:"pt"},[React.createElement("label",{style:{fontSize:12,fontWeight:700,color:COLORS.textSoft}},"Payment Terms"),
            React.createElement("select",{value:supMgrForm.paymentTerms,onChange:this.setSupMgrFormField("paymentTerms"),style:{width:"100%",padding:8,border:"1px solid "+COLORS.border,borderRadius:7,marginTop:4}},["Cash on Delivery","7 Days Credit","30 Days Credit"].map(o=>React.createElement("option",{key:o},o)))]),
          React.createElement("div",{key:"st"},[React.createElement("label",{style:{fontSize:12,fontWeight:700,color:COLORS.textSoft}},"Status"),
            React.createElement("select",{value:supMgrForm.status,onChange:this.setSupMgrFormField("status"),style:{width:"100%",padding:8,border:"1px solid "+COLORS.border,borderRadius:7,marginTop:4}},["Active","Inactive"].map(o=>React.createElement("option",{key:o},o)))]),
        ]),
        f("Address","address"),
        React.createElement("div",{key:"notes",style:{marginTop:10}},[React.createElement("label",{style:{fontSize:12,fontWeight:700,color:COLORS.textSoft}},"Notes/Remarks"),React.createElement("textarea",{value:supMgrForm.notes,onChange:this.setSupMgrFormField("notes"),placeholder:"e.g. delivers only on Tuesdays/Fridays",style:{width:"100%",padding:8,border:"1px solid "+COLORS.border,borderRadius:7,marginTop:4,minHeight:50}})]),
        React.createElement("div",{key:"a",style:{display:"flex",gap:8,marginTop:14}},[React.createElement("button",{key:"c",onClick:this.closeSupMgrModal,style:{flex:1,padding:10,background:"#f7f9fc",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Cancel"),React.createElement("button",{key:"s",onClick:this.saveSupMgrForm,style:{flex:1,padding:10,background:COLORS.brand,color:"#fff",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},supMgrForm.id==null?"Add Supplier":"Save Changes")]),
      ]));
  }
  buildMgrSupplier(){
    const {data,suppliersLocal,supMgrScreen,supMgrSearch,supMgrSelected,supMgrDetailId,supMgrDetailTab,supMgrModalOpen,supMgrProductPickerOpen,poLocal,purchaseOrdersLocal}=this.state;
    if(!suppliersLocal) return null;
    if(supMgrScreen==="detail"){
      const sp=suppliersLocal.find(s=>s.id===supMgrDetailId); if(!sp) return null;
      const tabs=[["products","Products"],["performance","Supplier Performance"],["history","Order History"],["callback","Callback Log"]];
      let body;
      if(supMgrDetailTab==="products"){
        body=React.createElement("div",null,[
          btn("+ Add Product",this.openSupMgrProductPicker,"primary"),
          React.createElement("div",{style:{height:10}}),
          sp.products.length===0?card(React.createElement("div",{style:{padding:16,textAlign:"center",color:COLORS.textMuted,fontSize:13}},"No products linked yet.")):
          table(["Product Name","Category","Supplier Cost Price","Preferred","Remove"],sp.products.map((pr,i)=>{ const p=(data.PRODUCTS||[]).find(pp=>pp.id===pr.productId);
            return tr([td(p?p.name:"â€”"),td(p?p.category:"â€”"),
              td(React.createElement("input",{type:"number",value:pr.costPrice,onChange:this.setSupMgrProductCost(sp.id,pr.productId),style:{width:90,padding:6,border:"1px solid "+COLORS.border,borderRadius:6}})),
              td(React.createElement("span",{onClick:this.toggleSupMgrPreferred(sp.id,pr.productId),style:{cursor:"pointer",fontSize:16,color:pr.preferred?COLORS.amber:"#ccc"}},pr.preferred?"â˜…":"â˜†")),
              td(React.createElement("button",{onClick:this.removeSupMgrProduct(sp.id,pr.productId),style:{color:COLORS.red,background:"none",border:"none",fontWeight:700,cursor:"pointer"}},"âœ•"))],i); })),
        ]);
      } else if(supMgrDetailTab==="performance"){
        body=this.buildSupplierPerformance(sp.id);
      } else if(supMgrDetailTab==="history"){
        const pos=(purchaseOrdersLocal||[]).filter(po=>po.supplierId===sp.id);
        body = pos.length===0 ? card(React.createElement("div",{style:{padding:16,textAlign:"center",color:COLORS.textMuted,fontSize:13}},"No purchase orders yet.")) :
          React.createElement("div",null,pos.map(po=>card([
            React.createElement("div",{key:"h",style:{display:"flex",justifyContent:"space-between",marginBottom:8}},[React.createElement("div",{style:{fontWeight:700,fontFamily:"'JetBrains Mono',monospace"}},po.id),badge(po.status)]),
            React.createElement("div",{key:"g",style:{display:"grid",gridTemplateColumns:"repeat(3,minmax(0,1fr))",gap:10,fontSize:12}},[
              ["Ordered",po.orderedValue],["Delivered",po.deliveredValue],["Invoiced",po.invoicedValue],["Paid",po.paidValue],["Undelivered Order Value",po.outstandingValue],["Cancelled",po.cancelledValue],
            ].map(([l,v],i)=>React.createElement("div",{key:i},[React.createElement("div",{style:{color:COLORS.textMuted}},l),React.createElement("div",{style:{fontWeight:700}},peso(v))]))),
          ],{marginBottom:10})));
      } else {
        body = sp.callbackLog.length===0 ? card(React.createElement("div",{style:{padding:16,textAlign:"center",color:COLORS.textMuted,fontSize:13}},"No callbacks logged.")) :
          card(sp.callbackLog.map((c,i)=>React.createElement("div",{key:i,style:{fontSize:13,padding:"6px 0",borderBottom:"1px solid #eef1f6"}},c.date+" â€” "+c.note)));
      }
      return React.createElement("div",null,[
        React.createElement("button",{onClick:this.goSupMgrList,style:{marginBottom:12,background:"none",border:"none",color:COLORS.brand,fontWeight:700,cursor:"pointer"}},"â† Back to Supplier"),
        card([
          React.createElement("div",{key:"h",style:{display:"flex",justifyContent:"space-between",alignItems:"flex-start",marginBottom:10}},[
            React.createElement("div",null,[React.createElement("div",{style:{fontWeight:800,fontSize:18}},sp.name),React.createElement("div",{style:{fontSize:12,color:COLORS.textSoft,marginTop:2}},sp.category)]),
            React.createElement("div",{style:{display:"flex",gap:8}},[badge(sp.status),btn("Edit",this.openSupMgrModal(sp))]),
          ]),
          React.createElement("div",{key:"grid",style:{display:"grid",gridTemplateColumns:"repeat(3,minmax(0,1fr))",gap:12,fontSize:13}},[
            ["Contact",sp.contact],["Phone",sp.phone],["Email",sp.email||"â€”"],["Address",sp.address],["Payment Terms",sp.paymentTerms],["Notes",sp.notes||"â€”"],
          ].map(([l,v],i)=>React.createElement("div",{key:i},[React.createElement("div",{style:{fontSize:11,fontWeight:700,color:COLORS.textMuted}},l),React.createElement("div",{style:{marginTop:2}},v)]))),
        ],{marginBottom:16}),
        React.createElement("div",{style:{display:"flex",gap:6,marginBottom:14}},tabs.map(([k,l])=>React.createElement("button",{key:k,onClick:this.setSupMgrDetailTab(k),style:{padding:"8px 14px",borderRadius:8,fontSize:12,fontWeight:700,cursor:"pointer",background:supMgrDetailTab===k?COLORS.brand:"#fff",color:supMgrDetailTab===k?"#fff":COLORS.text,border:"1px solid "+(supMgrDetailTab===k?COLORS.brand:COLORS.border)}},l))),
        body,
        supMgrProductPickerOpen?this.buildMgrSupplierProductPicker():null,
        supMgrModalOpen?this.buildMgrSupplierModal():null,
      ]);
    }
    const q=supMgrSearch.toLowerCase();
    const list=suppliersLocal.filter(sp=>sp.status==="Active"&&searchMatches(sp.name,q));
    const selCount=Object.values(supMgrSelected).filter(Boolean).length;
    return React.createElement("div",null,[
      React.createElement("div",{style:{display:"flex",justifyContent:"space-between",alignItems:"center",marginBottom:16}},[moduleTitle("Supplier"),btn("+ Add Supplier",this.openSupMgrModal(null),"primary")]),
      React.createElement("div",{style:{display:"flex",gap:10,alignItems:"center",marginBottom:12}},[
        React.createElement("input",{value:supMgrSearch,onChange:this.setSupMgrSearch,placeholder:"Search suppliersâ€¦",style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7,width:260}}),
        selCount>0?btn(`Move to Archive (${selCount})`,this.moveSupMgrSelectedToArchive,"danger"):null,
      ]),
      this.recordTable("suppliers",["","Supplier Name","Contact Person","Contact Number","Category","Status","Product Count","Action"],list.map(sp=>tr([
        td(React.createElement("input",{type:"checkbox",checked:!!supMgrSelected[sp.id],onChange:this.toggleSupMgrSelect(sp.id)})),
        td(sp.name,{fontWeight:600}),td(sp.contact),td(sp.phone),td(sp.category),td(badge(sp.status)),td(sp.products.length),
        td(React.createElement("button",{onClick:this.openSupMgrDetail(sp.id),style:{color:COLORS.brand,background:"none",border:"none",fontWeight:700,cursor:"pointer"}},"View â†’"))],sp.id))),
      supMgrModalOpen?this.buildMgrSupplierModal():null,
    ]);
  }
  buildMgrPromotions(){
    const {data,promoSelectedItems,promoStartDate,promoEndDate,promoType,promoOccasionName,promoCategory,promoDiscountPct,promotionsLocal,promoPickerOpen}=this.state;
    if(!data||!promotionsLocal) return null;
    const pct=parseFloat(promoDiscountPct)||0;
    const selectedProducts = promoType==="Category-Wide" ? data.PRODUCTS.filter(p=>p.category===promoCategory) : promoSelectedItems.map(id=>data.PRODUCTS.find(p=>p.id===id)).filter(Boolean);
    const types=[
      ["Near-Expiry","Near-Expiry Clearance","â³","For stock nearing its expiration date"],
      ["Seasonal","Seasonal/Event Promotion","ðŸŽ‰","For planned occasions like Christmas or Back-to-School"],
      ["Slow-Moving","Slow-Moving Clearance","ðŸ“‰","For items that aren't selling well"],
      ["Category-Wide","Category-Wide Promotion","ðŸ—‚","Applies one discount across a whole category"],
    ];
    const today=this.state.data?.BUSINESS_DATE||new Date().toISOString().slice(0,10);
    const activeCount=promotionsLocal.filter(p=>this.promoStatus(p)==="Active").length;
    const itemsOnPromo=new Set(promotionsLocal.filter(p=>this.promoStatus(p)==="Active").flatMap(p=>p.productIds)).size;
    const avgDiscount=promotionsLocal.length?Math.round(promotionsLocal.reduce((s,p)=>s+p.discountPct,0)/promotionsLocal.length):0;
    const endingThisWeek=promotionsLocal.filter(p=>{ const d=Math.round((new Date(p.endDate)-new Date(today))/86400000); return this.promoStatus(p)==="Active" && d>=0 && d<=7; }).length;
    const durationDays = (promoStartDate&&promoEndDate) ? Math.round((new Date(promoEndDate)-new Date(promoStartDate))/86400000) : null;
    const nearExpiry=this.expiringBatches().filter(x=>x.days>0).slice(0,5);
    const byProduct={}; data.SALES_LOG.forEach(t=>{ byProduct[t.productId]=(byProduct[t.productId]||0)+t.qty*t.amount; });
    const leastSales=Object.entries(byProduct).map(([id,rev])=>({p:data.PRODUCTS.find(p=>p.id===Number(id)),rev})).filter(x=>x.p).sort((a,b)=>a.rev-b.rev).slice(0,5);
    const stepHeader=(n,label)=>React.createElement("div",{style:{display:"flex",alignItems:"center",gap:12,marginBottom:14}},[
      React.createElement("div",{key:"n",style:{width:32,height:32,borderRadius:"50%",background:COLORS.brand,color:"#fff",display:"flex",alignItems:"center",justifyContent:"center",fontWeight:800,fontSize:15,flexShrink:0}},n),
      React.createElement("div",{key:"l",style:{fontSize:18,fontWeight:800}},label),
    ]);
    return React.createElement("div",null,[
      React.createElement("div",{style:{display:"flex",justifyContent:"space-between",alignItems:"flex-end",flexWrap:"wrap",gap:16,marginBottom:20}},[
        React.createElement("div",{key:"t"},[
          React.createElement("div",{key:"h",style:{fontSize:28,fontWeight:800}},"Promotions"),
          React.createElement("div",{key:"s",style:{fontSize:15,color:COLORS.textSoft,marginTop:4}},"Manager-configured, time-bound product discounts â€” applied automatically at checkout when active"),
        ]),
        React.createElement("div",{key:"kpis",style:{display:"flex",gap:12,flexWrap:"wrap"}},[
          kpi("Active Promotions",activeCount,null,COLORS.green),
          kpi("Items Currently on Promo",itemsOnPromo,null,COLORS.brand),
          kpi("Avg. Discount",avgDiscount+"%"),
          kpi("Ending This Week",endingThisWeek,null,endingThisWeek>0?COLORS.amber:undefined),
        ]),
      ]),
      React.createElement("div",{style:{display:"grid",gridTemplateColumns:"minmax(0,1.85fr) minmax(0,1fr)",gap:20,alignItems:"start"}},[
        card([
          React.createElement("div",{key:"t",style:{fontSize:20,fontWeight:800,marginBottom:20}},"Create Promotion"),
          stepHeader(1,"Select Item(s)"),
          promoType!=="Category-Wide"?React.createElement("div",{key:"s1",style:{marginBottom:24,paddingLeft:44}},[
            React.createElement("button",{key:"b",onClick:this.openPromoPicker,style:{padding:"14px 22px",background:COLORS.brand,color:"#fff",border:"none",borderRadius:10,fontWeight:800,fontSize:15,cursor:"pointer"}},"+ Select Items"),
            React.createElement("div",{key:"chips",style:{display:"flex",gap:10,flexWrap:"wrap",marginTop:14}},promoSelectedItems.map(id=>{ const p=data.PRODUCTS.find(pp=>pp.id===id); if(!p) return null;
              return React.createElement("div",{key:id,style:{width:150,border:"1px solid "+COLORS.border,borderRadius:10,padding:12,background:"#f8faff",position:"relative"}},[
                React.createElement("span",{key:"x",onClick:this.removePromoSelectedItem(id),style:{position:"absolute",top:6,right:8,cursor:"pointer",color:COLORS.textMuted,fontWeight:700}},"âœ•"),
                React.createElement("div",{key:"n",style:{fontSize:13,fontWeight:700,marginBottom:6,paddingRight:14}},p.name),
                React.createElement("div",{key:"p",style:{fontSize:13,color:COLORS.textSoft}},peso(p.price)),
              ]);
            })),
          ]):React.createElement("div",{key:"s1",style:{marginBottom:24,paddingLeft:44}},React.createElement("select",{value:promoCategory,onChange:this.setPromoCategory,style:{width:320,padding:12,border:"1px solid "+COLORS.border,borderRadius:8,fontSize:14}},[React.createElement("option",{key:"-",value:""},"Select categoryâ€¦"),...data.CATEGORIES.filter(c=>c.status==="Active").map(c=>React.createElement("option",{key:c.name,value:c.name},c.name))])),
          stepHeader(2,"Select Date Range"),
          React.createElement("div",{key:"s2",style:{marginBottom:24,paddingLeft:44,display:"flex",alignItems:"center",gap:16,flexWrap:"wrap"}},[
            React.createElement("div",{key:"picker",style:{display:"flex",alignItems:"center",gap:10,border:"1px solid "+COLORS.border,borderRadius:10,padding:"10px 16px",background:"#fff"}},[
              React.createElement("input",{key:"f",type:"date",min:"2026-07-24",value:promoStartDate,onChange:this.setPromoStartDate,style:{border:"none",fontSize:15,fontWeight:600,outline:"none"}}),
              React.createElement("span",{key:"dash",style:{color:COLORS.textMuted,fontWeight:700}},"â€”"),
              React.createElement("input",{key:"t",type:"date",value:promoEndDate,onChange:this.setPromoEndDate,style:{border:"none",fontSize:15,fontWeight:600,outline:"none"}}),
            ]),
            durationDays!=null?React.createElement("span",{key:"dur",style:{fontSize:13,fontWeight:700,color:COLORS.brandDark,background:COLORS.brandBg,padding:"6px 12px",borderRadius:20}},durationDays+" day"+(durationDays===1?"":"s")):null,
          ]),
          stepHeader(3,"Choose Promotion Type & Discount"),
          React.createElement("div",{key:"s3",style:{paddingLeft:44}},[
            React.createElement("div",{key:"types",style:{display:"grid",gridTemplateColumns:"repeat(2,minmax(0,1fr))",gap:12,marginBottom:16}},types.map(([k,l,icon,desc])=>
              React.createElement("div",{key:k,onClick:this.setPromoType(k),style:{padding:16,borderRadius:12,cursor:"pointer",border:"2px solid "+(promoType===k?COLORS.brand:COLORS.border),background:promoType===k?COLORS.brandBg:"#fff"}},[
                React.createElement("div",{key:"i",style:{fontSize:22,marginBottom:6}},icon),
                React.createElement("div",{key:"l",style:{fontSize:15,fontWeight:800,color:promoType===k?COLORS.brandDark:COLORS.text}},l),
                React.createElement("div",{key:"d",style:{fontSize:12,color:COLORS.textSoft,marginTop:4}},desc),
              ])
            )),
            promoType==="Seasonal"?React.createElement("input",{key:"occ",value:promoOccasionName,onChange:this.setPromoOccasionName,placeholder:"Occasion Name (e.g. Christmas)",style:{width:320,padding:12,border:"1px solid "+COLORS.border,borderRadius:8,marginBottom:16,fontSize:14}}):null,
            React.createElement("div",{key:"pctrow",style:{marginBottom:16}},[
              React.createElement("label",{key:"l",style:{fontSize:13,fontWeight:700,color:COLORS.textSoft,display:"block",marginBottom:6}},"Discount Percentage"),
              React.createElement("input",{key:"pct",type:"number",value:promoDiscountPct,onChange:this.setPromoDiscountPct,placeholder:"e.g. 20",style:{width:180,padding:"14px 16px",border:"1px solid "+COLORS.border,borderRadius:10,fontSize:22,fontWeight:800}}),
            ]),
            selectedProducts.length?React.createElement("div",{key:"preview",style:{marginBottom:20,border:"1px solid "+COLORS.border,borderRadius:10,padding:14,background:"#f8faff"}},selectedProducts.map(p=>{ const promoPrice=p.price*(1-pct/100); const belowCost=pct>0 && promoPrice<Number(p.unitPrice??p.cost);
              return React.createElement("div",{key:p.id,style:{display:"flex",justifyContent:"space-between",alignItems:"center",padding:"8px 0",borderBottom:"1px solid #e6ebe9",fontSize:14}},[
                React.createElement("span",{key:"n",style:{fontWeight:600}},p.name),
                React.createElement("div",{key:"p",style:{display:"flex",alignItems:"center",gap:12}},[
                  React.createElement("span",{key:"o",style:{textDecoration:"line-through",color:COLORS.textMuted}},peso(p.price)),
                  React.createElement("span",{key:"arrow",style:{color:COLORS.textMuted}},"â†’"),
                  React.createElement("span",{key:"n2",style:{fontWeight:800,fontSize:16,color:belowCost?COLORS.red:COLORS.green}},pct>0?peso(promoPrice):"â€”"),
                  belowCost?React.createElement("span",{key:"w",style:{fontSize:12,color:COLORS.red,fontWeight:700}},`Below cost â€” ${peso(p.unitPrice??p.cost)} min`):null,
                ]),
              ]);
            })):null,
            React.createElement("button",{key:"save",onClick:this.savePromotion,style:{width:"100%",padding:16,background:COLORS.brand,color:"#fff",border:"none",borderRadius:10,fontWeight:800,fontSize:16,cursor:"pointer"}},"Save Promotion"),
          ]),
        ],{padding:28}),
        React.createElement("div",{key:"side",style:{display:"flex",flexDirection:"column",gap:16,position:"sticky",top:0}},[
          card([
            React.createElement("div",{key:"t",style:{fontSize:16,fontWeight:800,marginBottom:12}},"Near-Expiry Items"),
            ...nearExpiry.map(x=>React.createElement("div",{key:x.p.id,style:{display:"flex",justifyContent:"space-between",alignItems:"center",padding:"9px 0",borderBottom:"1px solid #eef1f6"}},[
              React.createElement("div",null,[React.createElement("div",{style:{fontSize:13,fontWeight:600}},x.p.name),React.createElement("div",{style:{fontSize:11,color:x.days<=1?COLORS.red:COLORS.amber,fontWeight:700}},x.days<=0?"Expired - blocked from sale":x.days+" day(s) left")]),
              React.createElement("button",{onClick:this.quickPromoFromNearExpiry(x.p.id),style:{fontSize:11,fontWeight:700,color:COLORS.brand,background:"none",border:"none",cursor:"pointer"}},"Use This Item â†’"),
            ])),
          ],{padding:18}),
          card([
            React.createElement("div",{key:"t",style:{fontSize:16,fontWeight:800,marginBottom:12}},"Least Sales Items"),
            ...leastSales.map(x=>React.createElement("div",{key:x.p.id,style:{display:"flex",justifyContent:"space-between",alignItems:"center",padding:"9px 0",borderBottom:"1px solid #eef1f6"}},[
              React.createElement("div",null,[React.createElement("div",{style:{fontSize:13,fontWeight:600}},x.p.name),React.createElement("div",{style:{fontSize:11,color:COLORS.textMuted}},peso(x.rev)+" sold")]),
              React.createElement("button",{onClick:this.quickPromoFromLeastSales(x.p.id),style:{fontSize:11,fontWeight:700,color:COLORS.brand,background:"none",border:"none",cursor:"pointer"}},"Use This Item â†’"),
            ])),
          ],{padding:18}),
        ]),
      ]),
      React.createElement("div",{style:{height:20}}),
      card([React.createElement("div",{key:"t",style:{fontSize:18,fontWeight:800,marginBottom:14}},"All Promotions"),
        this.recordTable("promotions",["Item(s)","Type","Discount %","Promo Price","Date Range","Countdown","Status","Performance","Actions"],promotionsLocal.map((p,i)=>{
          const prods=p.productIds.map(id=>data.PRODUCTS.find(pp=>pp.id===id)).filter(Boolean);
          const status=this.promoStatus(p); const daysLeft=Math.round((new Date(p.endDate)-new Date(today))/86400000);
          const prod=prods[0];
          let perf="â€”";
          if(prod){ const inWindow=data.SALES_LOG.filter(t=>t.productId===prod.id && t.date>=p.startDate && t.date<=p.endDate).reduce((s,t)=>s+t.qty,0);
            const outsideLogs=data.SALES_LOG.filter(t=>t.productId===prod.id && !(t.date>=p.startDate && t.date<=p.endDate));
            const outsideDays=new Set(outsideLogs.map(t=>t.date)).size||1;
            const avgOutside=Math.round((outsideLogs.reduce((s,t)=>s+t.qty,0)/outsideDays)*10)/10;
            perf=`${inWindow} in-promo vs ${avgOutside}/day avg`;
          }
          return tr([td(prods.map(pp=>pp.name).join(", ")||p.category),td(p.type),td(p.discountPct+"%"),
            td(prods.length?prods.map(pp=>peso(pp.price*(1-p.discountPct/100))).join(", "):"â€”"),
            td(p.startDate+" â†’ "+p.endDate),
            td(status==="Active"?React.createElement("span",{style:{fontSize:11,fontWeight:700,color:COLORS.purple,background:COLORS.purpleBg,padding:"3px 8px",borderRadius:6}},daysLeft+"d left"):"â€”"),
            td(badge(status)),
            td(perf,{fontSize:12,color:COLORS.textSoft}),
            td(status==="Expired"?React.createElement("button",{onClick:this.copyPromotion(p),style:{color:COLORS.brand,background:"none",border:"none",fontWeight:700,cursor:"pointer"}},"Copy"):"â€”")
          ],p.id);
        })),
      ],{padding:22}),
      promoPickerOpen?this.buildPromoPickerModal():null,
    ]);
  }

  // ---- Admin screens ----
  buildAdmDashboard(){ return this.buildOperationsDashboard(false); }
  buildAdmPurchasedOrders(){ return this.buildPurchaseReview(); }
  buildAdmForwarded(){ return this.buildPurchaseStatusList(); }
  buildAdmDisapproved(){ return this.buildPurchaseStatusList(true); }
  buildArchive(){
    const {archiveTab,archiveChecked,productsLocal=[],categoriesLocal=[],suppliersLocal=[],archiveConfirmOpen}=this.state;
    const tabs=[["items","Items"],["categories","Categories"],["suppliers","Suppliers"]];
    const checkedCount=Object.values(archiveChecked).filter(Boolean).length;
    let rows, headers, keyOf;
    if(archiveTab==="items"){ const list=productsLocal.filter(p=>p.status==="Inactive"); headers=["","Product","Category","Archived On","Archived By"]; keyOf=p=>String(p.id);
      rows=list.map(p=>tr([td(React.createElement("input",{type:"checkbox",checked:!!archiveChecked[keyOf(p)],onChange:this.toggleArchiveCheck(keyOf(p))})),td(p.name),td(p.category),td(p.archivedAt||"â€”"),td(p.archivedBy||"â€”")],p.id)); }
    else if(archiveTab==="categories"){ const list=categoriesLocal.filter(c=>c.status==="Inactive"); headers=["","Category","Archived On","Archived By"]; keyOf=c=>c.name;
      rows=list.map(c=>tr([td(React.createElement("input",{type:"checkbox",checked:!!archiveChecked[keyOf(c)],onChange:this.toggleArchiveCheck(keyOf(c))})),td(c.name),td(c.archivedAt||"â€”"),td(c.archivedBy||"â€”")],c.name)); }
    else { const list=suppliersLocal.filter(s=>s.status==="Inactive"); headers=["","Supplier","Category","Archived On","Archived By"]; keyOf=s=>String(s.id);
      rows=list.map(s=>tr([td(React.createElement("input",{type:"checkbox",checked:!!archiveChecked[keyOf(s)],onChange:this.toggleArchiveCheck(keyOf(s))})),td(s.name),td(s.category),td(s.archivedAt||"â€”"),td(s.archivedBy||"â€”")],s.id)); }
    return React.createElement("div",null,[
      moduleTitle("Archive"),btn("Refresh",()=>this.reloadCatalog().catch(e=>this.toast(e.message,"error"))),
      React.createElement("div",{style:{display:"flex",gap:6,marginBottom:14}},tabs.map(([k,l])=>React.createElement("button",{key:k,onClick:this.setArchiveTab(k),style:{padding:"8px 14px",borderRadius:8,fontSize:12,fontWeight:700,cursor:"pointer",background:archiveTab===k?COLORS.brand:"#fff",color:archiveTab===k?"#fff":COLORS.text,border:"1px solid "+(archiveTab===k?COLORS.brand:COLORS.border)}},l))),
      checkedCount?React.createElement("div",{style:{display:"flex",gap:8,alignItems:"center",marginBottom:10}},[React.createElement("span",{style:{fontSize:12,fontWeight:700,color:COLORS.textSoft}},checkedCount+" selected"),btn("Restore",this.restoreArchived,"green"),btn("Delete Permanently",this.openArchiveConfirm,"danger")]):null,
      rows.length===0?card(React.createElement("div",{style:{padding:20,textAlign:"center",color:COLORS.textMuted,fontSize:13}},"Nothing archived in this category.")):this.recordTable("archive-"+archiveTab,headers,rows),
      archiveConfirmOpen?React.createElement("div",{style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50}},
        React.createElement("div",{style:{width:380,background:"#fff",borderRadius:14,padding:20}},[
          React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:8,color:COLORS.red}},"Delete Permanently?"),
          React.createElement("div",{key:"m",style:{fontSize:13,color:COLORS.textSoft,marginBottom:16}},"This is a hard, irreversible removal â€” unlike Archive, it cannot be undone via Restore."),
          React.createElement("div",{key:"a",style:{display:"flex",gap:8}},[React.createElement("button",{key:"c",onClick:this.closeArchiveConfirm,style:{flex:1,padding:10,background:"#f7f9fc",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Cancel"),React.createElement("button",{key:"d",onClick:this.deletePermanently,style:{flex:1,padding:10,background:COLORS.red,color:"#fff",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Delete Permanently")]),
        ])):null,
    ]);
  }
  buildAdmReceivingApprovals(){ return this.buildPurchaseHistory(); }
  buildAdmInventoryApprovals(){ const {adjustmentsLocal,data}=this.state; if(!adjustmentsLocal) return null;
    return React.createElement("div",null,[moduleTitle("Inventory Adjustment Approvals"),
      ...adjustmentsLocal.map(a=>{ const p=data.PRODUCTS.find(pp=>pp.id===a.productId); return card([
        React.createElement("div",{key:"h",style:{display:"flex",justifyContent:"space-between"}},[React.createElement("div",null,[React.createElement("div",{style:{fontWeight:700}},a.id+" Â· "+(p?p.name:"â€”")),React.createElement("div",{style:{fontSize:12,color:COLORS.textSoft}},a.reason+" Â· Î”"+a.qtyChange+" Â· "+a.remarks)]),badge(a.status)]),
        a.photo?this.evidenceLink(a.id):null,
        a.status==="Pending Admin Approval"?React.createElement("div",{key:"btns",style:{display:"flex",gap:8,marginTop:10}},[btn("Approve",this.approveAdjustment(a.id),"green"),btn("Reject",this.rejectAdjustment(a.id),"danger")]):null,
      ],{marginBottom:10}); }),
    ]);
  }
  buildAdmSuppliers(){ const {data,supplierDetailId}=this.state; if(!data) return null;
    if(supplierDetailId){ const s=data.SUPPLIERS.find(x=>x.id===supplierDetailId);
      const infoRows=[["Contact",s.contact],["Phone",s.phone],["Email",s.email],["Address",s.address]].map(function(pair,i){ const l=pair[0],v=pair[1];
        return React.createElement("div",{key:i,style:{display:"flex",justifyContent:"space-between",padding:"6px 0",borderBottom:"1px solid #eef1f6"}},[React.createElement("span",{key:"l",style:{color:COLORS.textSoft}},l),React.createElement("span",{key:"v",style:{fontWeight:600}},v)]); });
      infoRows.push(React.createElement("div",{key:"status",style:{display:"flex",justifyContent:"space-between",padding:"6px 0"}},[React.createElement("span",{key:"l",style:{color:COLORS.textSoft}},"Status"),badge(s.status)]));
      const callbackRows = s.callbackLog.length===0 ? [React.createElement("div",{key:"e",style:{fontSize:13,color:COLORS.textMuted}},"No callbacks logged.")]
        : s.callbackLog.map((c,i)=>React.createElement("div",{key:i,style:{fontSize:13,padding:"6px 0",borderBottom:"1px solid #eef1f6"}},c.date+" â€” "+c.note));
      return React.createElement("div",null,[
        React.createElement("button",{key:"back",onClick:this.closeSupplierDetail,style:{marginBottom:12,background:"none",border:"none",color:COLORS.brand,fontWeight:700,cursor:"pointer"}},"â† Back"),
        sectionTitle(s.name,s.category),this.buildSupplierPerformance(s.id),
        card(infoRows,{marginBottom:14}),
        card([React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:8}},"Supplier Callback Log")].concat(callbackRows)),
      ]);
    }
    return React.createElement("div",null,[moduleTitle("Supplier Records"),
      this.recordTable("supplier-records",["Supplier","Category","Status","Details"],data.SUPPLIERS.map(s=>tr([td(s.name,{fontWeight:600}),td(s.category),td(badge(s.status)),td(React.createElement("button",{onClick:this.openSupplierDetail(s.id),style:{color:COLORS.brand,background:"none",border:"none",fontWeight:700,cursor:"pointer"}},"View â†’"))],s.id)))]);
  }
  scheduleReport=()=>{
    clearTimeout(this.reportFilterTimer);this.reportRequestId=(this.reportRequestId||0)+1;this.reportController?.abort();
    this.reportFilterTimer=setTimeout(()=>{if(this.state.screen==='admReports')this.generateReport();},300);
  };
  clearReportDates=()=>this.setState(s=>({reportFilters:{...s.reportFilters,from:'',to:''},reportFilterOpen:null}),this.scheduleReport);
  setReportFilter=(key,value)=>{if(key==='type'){this.reportRequestId=(this.reportRequestId||0)+1;this.reportController?.abort();}return this.setState(s=>({reportFilters:{...(s.reportFilters||{type:'sales'}),[key]:value,...(key==='category'?{subcategoryId:'',productId:''}:['brandId','subcategoryId','supplierId','type'].includes(key)?{productId:''}:{}),...(key==='type'?{batch:'',supplierId:'',status:'',audit:'',expiryStatus:'',lowStock:'',manager:'',category:'',brandId:'',subcategoryId:'',...(value==='inventory'?{from:'',to:''}:{})}:{})},reportFilterOpen:['from','to'].includes(key)?s.reportFilterOpen:null,...(key==='type'?{reportResult:null,reportAppliedFilters:null,reportError:'',reportLoading:false}:{})}),this.scheduleReport);};
  resetReportFilters=()=>this.setState({reportFilters:{type:this.state.reportFilters?.type||'sales'},reportFilterOpen:null},this.scheduleReport);
  generateReport=async()=>{
    clearTimeout(this.reportFilterTimer);
    const filters={...(this.state.reportFilters||{type:'sales'})};
    const requestId=(this.reportRequestId||0)+1;this.reportRequestId=requestId;
    this.reportController?.abort();
    if(filters.from&&filters.to&&filters.from>filters.to){this.setState({reportLoading:false,reportError:'Choose an end date on or after the start date.'});return;}
    const controller=new AbortController();this.reportController=controller;
    const timeout=setTimeout(()=>controller.abort(),90000);
    this.setState({reportLoading:true,reportError:''});
    try{
      const response=await fetch('/reports/data?'+new URLSearchParams(Object.entries(filters).filter(([,v])=>v)),{headers:{Accept:'application/json'},signal:controller.signal,cache:'no-store'});
      const body=await response.json();if(!response.ok)throw new Error(body.message||'Could not load report.');
      if(!Array.isArray(body.rows)||!body.columns||typeof body.columns!=='object')throw new Error('The report response was incomplete. Please retry.');
      if(this.reportRequestId===requestId)this.setState(s=>({reportLoading:false,reportResult:body,reportAppliedFilters:filters,tableViews:{...s.tableViews,'generated-report':{...s.tableViews?.['generated-report'],page:1,query:''}}}));
    }catch(error){if(this.reportRequestId===requestId)this.setState({reportLoading:false,reportError:error.name==='AbortError'?'The report request timed out. Retry to load the latest records.':error.message});}
    finally{clearTimeout(timeout);if(this.reportRequestId===requestId)this.reportController=null;}
  };
  buildReportCharts(rows){
    const h=React.createElement, hours=Array.from({length:24},()=>new Set()),products=new Map();
    rows.forEach(row=>{
      const match=/^(\d{1,2}):/.exec(row.time||'');
      if(match&&Number(match[1])<24)hours[Number(match[1])].add(row.transactionId||row.reference);
      const name=row.product||'Unnamed product';
      products.set(name,(products.get(name)||0)+Number(row.amount||0));
    });
    const peak=Math.max(0,...hours.map(set=>set.size));
    const step=Math.max(1,Math.ceil(peak/4)), ceiling=step*4;
    const hourLabel=hour=>String(hour%12||12).padStart(2,'0')+':00 '+(hour<12?'AM':'PM');
    const recorded=hours.map((set,hour)=>({hour,count:set.size})).filter(item=>item.count);
    const first=Math.min(8,...recorded.map(item=>item.hour)), last=Math.max(21,...recorded.map(item=>item.hour));
    const visible=Array.from({length:last-first+1},(_,i)=>({hour:first+i,count:hours[first+i].size}));
    const leaders=recorded.slice().sort((a,b)=>b.count-a.count||a.hour-b.hour).slice(0,2).sort((a,b)=>a.hour-b.hour);
    const peakLabel=leaders.length?'Peak: '+leaders.map(item=>(item.hour%12||12)+' '+(item.hour<12?'AM':'PM')).join(' & '):'No peak data';
    const slot=980/visible.length, baseline=300, plotHeight=250;

    const ranked=[...products].sort((a,b)=>b[1]-a[1]).slice(0,6).map(([name,rev],i)=>({p:{id:i,name},rev}));
    return h('div',{className:'report-chart-grid'},[
      h('section',{className:'kita-card report-peak-panel','aria-label':'Peak sales hours'},[
        h('div',{className:'report-peak-heading'},[
          h('div',null,[h('h3',{className:'panel-title'},'Peak Sales Hours & Shift Load'),null]),
          h('span',{className:'report-peak-badge',title:'Two busiest recorded hours; ties use the earlier hour.'},peakLabel),
        ]),
        h('div',{className:'report-peak-scroll'},h('svg',{viewBox:'0 0 1080 340',className:'report-peak-svg',role:'img','aria-label':'Hourly transaction volume. '+peakLabel},[
          h('title',null,'Distinct transactions per hour. All listed transaction statuses included.'),
          ...Array.from({length:5},(_,i)=>{const value=i*step,y=baseline-i*plotHeight/4;return h('g',{key:'tick'+i},[
            h('line',{x1:55,x2:1035,y1:y,y2:y,stroke:i?'#e8eef8':'#7c8798',strokeDasharray:i?'4 4':undefined}),
            h('text',{x:45,y:y+4,textAnchor:'end',fontSize:12,fill:'#667c9c'},value),
          ]);}),
          ...visible.map(({hour,count},i)=>{const x=55+i*slot+slot*.1,width=slot*.8,height=count/ceiling*plotHeight,y=baseline-height,r=Math.min(8,height);return h('g',{key:hour,className:'report-hour-column'},[
            h('path',{d:count?`M ${x} ${baseline} V ${y+r} Q ${x} ${y} ${x+r} ${y} H ${x+width-r} Q ${x+width} ${y} ${x+width} ${y+r} V ${baseline} Z`:'',fill:'#2864ef'},h('title',null,hourLabel(hour)+': '+count+' transactions')),
            h('text',{x:x+width/2,y:baseline+20,textAnchor:'middle',fontSize:11,fill:'#667c9c'},hourLabel(hour)),
          ]);}),
        ])),
        !peak?h('p',{className:'record-empty'},'No recorded transaction times for this selection.'):null,
      ]),
      card([h('h3',{className:'panel-title'},'Top Product Performance'),null,
        ranked.length?h('svg',{viewBox:'0 0 480 270',className:'report-product-svg',role:'img','aria-label':'Product sales comparison in pesos'},[
          ...Array.from({length:5},(_,i)=>h('g',{key:'axis'+i},[
            h('line',{x1:125+i*82,x2:125+i*82,y1:12,y2:242,stroke:'#edf1f8',strokeDasharray:'3 4'}),
            h('text',{x:125+i*82,y:260,textAnchor:'middle',fontSize:10,fill:'#667c9c'},(Math.max(1,...ranked.map(row=>row.rev))*i/4).toLocaleString('en-PH',{maximumFractionDigits:0})),
          ])),
          ...ranked.map((row,i)=>h('g',{key:row.p.id},[
            h('text',{x:116,y:32+i*37,textAnchor:'end',fontSize:11,fill:'#526579'},[h('title',null,row.p.name),row.p.name.length>19?row.p.name.slice(0,18)+'...':row.p.name]),
            h('rect',{x:125,y:17+i*37,width:Math.max(0,row.rev)/Math.max(1,...ranked.map(item=>item.rev))*328,height:25,rx:4,fill:'#3b82f6'},h('title',null,row.p.name+': PHP '+row.rev.toFixed(2))),
          ])),
        ]):h('p',{className:'record-empty'},'No sales recorded for these products yet.'),
      ]),
    ]);
  }
  reportReceiptDetails(){
    const h=React.createElement,row=this.state.reportReceiptDetail;if(!row)return null;
    const close=()=>this.setState({reportReceiptDetail:null});
    return h('dialog',{className:'purchase-details-dialog','aria-labelledby':'report-receipt-title',ref:node=>{if(node&&!node.open)node.showModal();},onCancel:e=>{e.preventDefault();close();}},[
      h('h2',{id:'report-receipt-title'},row.receivingId),
      h('p',null,[row.reportPo,row.batch,row.supplier,row.manager,row.date].filter(Boolean).join(' | ')),
      table(['Item Code','Item','Stock Unit','Received Qty','Unit Cost (PHP)','Total Cost (PHP)'],[tr([td(row.productId),td(row.name),td(row.unit),td(row.deliveredQty),td(peso(row.unitCost||0)),td(peso(row.amount||0))],row.id)]),h('p',null,row.remarks),
      h('div',{className:'dialog-actions'},[btn('Close',close),h('a',{className:'kita-button',href:'/receipts/'+encodeURIComponent(row.receivingId)+'/report',target:'_blank',rel:'noopener'},'Print Receipt')])]);
  }
  buildAdmReports(){
    const h=React.createElement,s=this.state,d=s.data,f=s.reportFilters||{type:'sales'},loaded=s.reportResult;
    const resultType=s.reportAppliedFilters?.type||f.type;
    const normalizeFilters=filters=>JSON.stringify(Object.entries(filters||{type:'sales'}).filter(([,value])=>value).sort(([a],[b])=>a.localeCompare(b)));
    const pendingFilters=!!loaded&&normalizeFilters(f)!==normalizeFilters(s.reportAppliedFilters);
    const common={'Product':'product','Category':'category','Brand':'brand','Subcategory':'subcategory'};
    const schemas={
      receiving:{'PO Number':'reportPo','Receipt ID':'reference','Supplier':'supplier','Product':'product','Remarks':'remarks','Discrepancy':'discrepancyType','Condition':'condition','Received':'quantity'},
      expiry:{'Product ID':'code','Batch':'batch','Product name':'product','Category':'category','Manufacturer':'manufacturer','Expiration date':'expiryDate','Stock remaining':'remaining','Unit':'unit','Expiration status':'status'},
      sales:{'Transaction No.':'reference','Sale date':'date',...common,'Status':'status','Quantity':'quantity','Line total (PHP)':'amount','Refunded (PHP)':'refunded'},
      inventory:{...common,'Item Code':'id','Barcode':'barcode','Stock Unit':'unit','Qty on Hand':'stockOnHand','Available Qty':'sellableStock','Expired Qty':'expiredStock','Recalled Qty':'recalledStock','Reserved Qty':'reservedStock','Availability Remarks':'exclusionReason','Minimum Stock':'minimum','Stock Condition':'stockCondition','Inventory Value (PHP)':'inventoryValue','Valuation Basis':'valuationBasis','Selling Price (PHP)':'price','Item Status':'status'},
      receipts:{...common,'Product ID':'productId','Manufacturer':'manufacturer','PO Number':'poId','Received date':'date','Receipt':'reference','Supplier':'supplier','Quantity received':'quantity','Remaining now':'remaining','Unit cost (PHP)':'cost','Batch':'batch','Manufactured':'manufacturedDate','Expiry':'expiryDate'},
      movements:{...common,'Recorded at':'date','Type':'movement','Reference':'reference','Change':'change','Before':'before','After':'after'}
    };
    schemas.batches=schemas.receipts;
    schemas.purchase_orders={'PO Number':'reportPo','Order Date':'date','Supplier':'supplier','Requested By':'manager','Item':'name','Ordered Qty':'orderedQty','Accepted Here':'deliveredQty','Outstanding Qty':'outstandingQty'};
    schemas.outstanding_orders=schemas.purchase_orders;
    schemas.purchase_orders={'PO Number':'reportPo','Batch':'batch','Receipt ID':'receivingId','Item Code':'productId','Item':'name','Stock Unit':'unit','Received Qty':'deliveredQty','Unit Cost (PHP)':'unitCost','Total Cost (PHP)':'amount','Supplier':'supplier','Received By':'manager','Date Received':'date','Inspection Remarks':'remarks'};
    schemas.purchases={'Receipt Date':'date','PO Number':'reportPo','Receipt ID':'receivingId','Supplier':'supplier','Item':'name','Accepted Qty':'deliveredQty','Batch':'batchNumber'};
    schemas.supplier_book={'Entry Date':'date','Supplier':'supplier','Type':'kind','PO Number':'reportPo','Document / Reference':'reference','Purchase Debit (PHP)':'debit','Payment / Credit (PHP)':'credit'};
    schemas.payables={'Receipt Date':'date','PO Number':'reportPo','Receipt ID':'id','Supplier':'supplier','Unpaid Balance (PHP)':'balance'};
    const r=loaded||{title:'Report results',columns:schemas[f.type]||{'PO Number':'reportPo','Supplier':'supplier','Item':'name','Ordered Qty':'orderedQty','Outstanding Qty':'outstandingQty'},rows:[],labels:{},summary:{}};
    const appliedLabels=Object.entries(r?.labels||{}).filter(([key])=>key!=='Type').map(([key,value])=>key+': '+value).join(', ');
    const emptyMessage=h('div',{className:'report-table-message',role:s.reportError?'alert':'status','aria-live':'polite'},s.reportLoading?'Loading report records. Please wait...':s.reportError?'Unable to update the report. '+s.reportError:!loaded?'Choose your filters, then click Refresh to load the report.':'No results found'+(appliedLabels?' for '+appliedLabels:'')+'. Change or clear the filters and try again.');
    const reportGroups=[
      ['Sales',[['sales','Sales details','View sold items, sales amounts, and transaction status.']]],
      ['Inventory',[['batches','Batches',''],['inventory','Stock on hand','Check current quantities and stock availability.'],['receipts','Stock received','See products received, with supplier and batch details.'],['movements','Stock changes','Track stock added, removed, or adjusted.'],['expiry','Expiring products','Check expiration dates and print a monitoring report.']]],
      ['Purchasing',[['purchase_orders','Purchase orders','Review orders placed with suppliers and their status.'],['outstanding_orders','Pending deliveries','Find ordered items that still need to be received.'],['receiving','Delivery discrepancies','Review missing, damaged, and other flagged deliveries.'],['purchases','Purchase history','Review recorded purchases and their costs.']]],
      ['Suppliers & payments',[['supplier_book','Supplier records','Review purchasing records by supplier.'],['payables','Supplier balances','Check amounts owed to suppliers.']]],
    ];
    const products=d.PRODUCTS.filter(p=>(!f.category||p.category===f.category)&&(!f.subcategoryId||String(p.subcategoryId)===String(f.subcategoryId))&&(!f.brandId||String(p.brandId)===String(f.brandId))&&(['receipts','batches','purchases','receiving','expiry'].includes(f.type)||!f.supplierId||String(p.supplierId)===String(f.supplierId)));
    const facets=[['category','Category',d.CATEGORIES.map(c=>[c.name,c.name])],['subcategoryId','Subcategory',this.subcategoriesFor(f.category,true).map(c=>[c.id,c.name])],['brandId','Brand',(d.BRANDS||[]).map(b=>[b.id,b.name])],['supplierId','Supplier',d.SUPPLIERS.map(p=>[p.id,p.name])],['productId','Product',products.map(p=>[p.id,this.productLabel(p)+' #'+p.id])]].filter(([key])=>!['supplier_book','payables'].includes(f.type)||key==='supplierId');
    const selected=facets.filter(([key])=>f[key]);
    const activeCount=selected.length+(f.status?1:0)+(f.audit?1:0)+(f.expiryStatus?1:0)+(f.lowStock?1:0)+(f.from||f.to?1:0)+(f.batch?1:0)+(f.manager?1:0);
    const open=s.reportFilterOpen,facet=facets.find(([key])=>key===open);
    const labelFor=(options,value)=>options.find(([id])=>String(id)===String(value))?.[1]||value;
    const url=format=>'/reports/data?'+new URLSearchParams({...Object.fromEntries(Object.entries(s.reportAppliedFilters||{}).filter(([,v])=>v)),format});
    const filterButton=(key,label,active,disabled=false)=>h('button',{key,type:'button',className:'report-filter-button'+(active?' is-selected':''),'aria-expanded':open===key,'aria-controls':'report-filter-options',disabled,onClick:()=>this.setState({reportFilterOpen:open===key?null:key}),title:disabled?'Choose a category first':undefined},[label,h('span',{'aria-hidden':true},open===key?'\u25b4':'\u25be')]);
    return h('div',{className:'reports-page'},[moduleTitle('Reports'),this.reportReceiptDetails(),
      ['purchase_orders','outstanding_orders','purchases','supplier_book','payables'].includes(f.type)?h('label',{className:'purchase-notes'},[f.type==='purchase_orders'?'Received by (exact name)':'Requested / Recorded by (exact name)',h('input',{value:f.manager||'',onChange:e=>this.setReportFilter('manager',e.target.value)})]):null,
      f.type==='expiry'?h('div',{className:'expiry-quick-filters',role:'group','aria-label':'Expiration status filters'},[
        ...[['','All'],['Nearly expired','Nearly expired'],['Expired','Expired']].map(([value,label])=>h('button',{key:label,type:'button',className:'report-type-button'+((f.expiryStatus||'')===value?' is-selected':''),'aria-pressed':(f.expiryStatus||'')===value,onClick:()=>this.setReportFilter('expiryStatus',value)},label)),
        null,
      ]):null,
      h('section',{className:'report-filter-bar','aria-label':'Report filters'},[
        h('div',{className:'report-filter-actions'},[h('strong',null,'Filters'+(activeCount?' ('+activeCount+')':'')),
          h('label',{className:'report-status-filter report-type-filter'},['Report type',h('select',{'aria-label':'Report type',value:f.type,onChange:e=>this.setReportFilter('type',e.target.value)},reportGroups.map(([group,reports])=>h('optgroup',{key:group,label:group},reports.map(([value,label])=>h('option',{key:value,value},label)))))]),
          f.type!=='inventory'?filterButton('date','Date range: '+(f.from||f.to?((f.from||'Any')+' to '+(f.to||'Any')):'All time'),f.from||f.to):null,
          ...facets.filter(([key])=>['category','subcategoryId'].includes(key)).map(([key,label])=>filterButton(key,label+': '+(f[key]?labelFor(facets.find(item=>item[0]===key)[2],f[key]):'All'),!!f[key],key==='subcategoryId'&&!f.category)),
          ['sales','inventory'].includes(f.type)?h('label',{className:'report-status-filter'},['Status',h('select',{'aria-label':'Report status',value:f.status||'',onChange:e=>this.setReportFilter('status',e.target.value)},['',...(f.type==='sales'?['Completed','Paid','Pending Payment','Partially Refunded','Refunded','Voided','Cancelled','Payment Failed']:f.type==='inventory'?['Active','Inactive']:['Fully Received','Partially Received','Received with Discrepancy'])].map(value=>h('option',{key:value,value},value||'All statuses')))]):null,
          f.type==='receiving'?h('label',{className:'report-status-filter'},['Discrepancy audit',h('select',{'aria-label':'Report discrepancy',value:f.audit||'',onChange:e=>this.setReportFilter('audit',e.target.value)},[['','All'],['missing','Missing'],['damaged','Damaged'],['flagged','Other discrepancies'],['none','No discrepancy']].map(([value,label])=>h('option',{key:value,value},label)))]):null,
          filterButton('more','More filters',!!(f.brandId||f.supplierId||f.productId||f.lowStock||f.expiryStatus)),
          activeCount?h('button',{type:'button',className:'report-clear',onClick:this.resetReportFilters},'Clear all'):null,
          h('button',{type:'button',className:'kita-button kita-button--primary',disabled:!!s.reportLoading,onClick:this.generateReport},s.reportLoading?'Refreshing...':'Refresh'),
          h('label',{className:'report-search'},[h('span',{className:'sr-only'},'Search report records'),h('input',{type:'search',placeholder:'Search transaction, product, category...',value:s.tableViews['generated-report']?.query||'',onChange:e=>this.setTableView('generated-report',{query:e.target.value,page:1})})])
        ]),
        open?h('div',{id:'report-filter-options',className:'report-filter-options',onKeyDown:e=>{if(e.key==='Escape'){this.setState({reportFilterOpen:null});e.currentTarget.parentElement.querySelector('[aria-expanded="true"]')?.focus();}}},[
          open==='more'?h('div',{className:'report-date-options'},[['receipts','batches','purchase_orders','purchases'].includes(f.type)?h('label',null,['Batch number',h('input',{'aria-label':'Batch number',value:f.batch||'',onChange:e=>this.setReportFilter('batch',e.target.value)})]):null,f.type==='expiry'?h('label',null,['Expiration status',h('select',{'aria-label':'Expiration status',value:f.expiryStatus||'',onChange:e=>this.setReportFilter('expiryStatus',e.target.value)},['','Nearly expired','Expired','Critical','Warning','Good','Unknown'].map(value=>h('option',{key:value,value},value||'All statuses')))]):null,...facets.filter(([key])=>!['category','subcategoryId'].includes(key)).map(([key,label,options])=>h('label',{key},[label,h('select',{value:f[key]||'',onChange:e=>this.setReportFilter(key,e.target.value)},[['','All '+label.toLowerCase()+' options'],...options].map(([value,name])=>h('option',{key:value,value},name)))])),f.type==='inventory'?h('label',null,[h('input',{type:'checkbox',checked:!!f.lowStock,onChange:e=>this.setReportFilter('lowStock',e.target.checked?'1':'')}),'Low stock only']):null]):open==='date'?h('div',{className:'report-date-options'},[
            h('label',null,['From',h('input',{type:'date',value:f.from||'',max:f.to||undefined,onChange:e=>this.setReportFilter('from',e.target.value)})]),
            h('label',null,['To',h('input',{type:'date',value:f.to||'',min:f.from||undefined,onChange:e=>this.setReportFilter('to',e.target.value)})]),
            h('button',{type:'button',className:'report-clear',onClick:this.clearReportDates},'All dates')
          ]):facet?h('fieldset',{className:'report-facet'},[h('legend',null,facet[1]),h('div',{className:'report-facet-options'},[['','All '+facet[1].toLowerCase()+' options'],...facet[2]].map(([value,label])=>h('label',{key:value,className:'report-facet-choice'},[h('input',{type:'radio',name:'report-'+open,value,checked:String(f[open]||'')===String(value),onChange:()=>this.setReportFilter(open,String(value))}),label])))]):null
        ]):null,
        activeCount?h('div',{className:'report-active-actions','aria-label':'Selected filters'},[
          ...selected.map(([key,label,options])=>h('button',{key,type:'button',className:'report-filter-chip','aria-label':'Remove '+label+' filter',onClick:()=>this.setReportFilter(key,'')},label+': '+labelFor(options,f[key])+' \u00d7')),
          f.from||f.to?h('button',{type:'button',className:'report-filter-chip',onClick:this.clearReportDates},'Dates: '+(f.from||'Any')+' to '+(f.to||'Any')+' \u00d7'):null,
          f.batch?h('button',{type:'button',className:'report-filter-chip',onClick:()=>this.setReportFilter('batch','')},'Batch: '+f.batch+' ×'):null,
          f.manager?h('button',{type:'button',className:'report-filter-chip',onClick:()=>this.setReportFilter('manager','')},'Recorded by: '+f.manager+' ×'):null,
          f.lowStock?h('button',{type:'button',className:'report-filter-chip',onClick:()=>this.setReportFilter('lowStock','')},'Low stock only \u00d7'):null
        ]):null,
        f.type==='inventory'?null:null
      ]),
      h('p',{role:'status','aria-live':'polite',className:'report-filter-hint'},s.reportLoading?'Updating results below...':s.reportError?'Results could not be updated.':pendingFilters?'Updating filtered results...':loaded?r.rows.length+(resultType==='expiry'?' tracked batch items':' matching records'):'Click Refresh to load your report.'),
      s.reportError?h('div',{role:'alert'},[s.reportError,btn('Retry',this.generateReport)]):null,
      h('section',{key:'report-results',className:'report-results','aria-label':'Report results','aria-busy':!!s.reportLoading},[
        h('div',{className:'report-results-header'},[h('h2',null,r.title),loaded?h('div',{className:'action-group'},[h('a',{className:'kita-button',href:url('pdf'),download:true},'Download PDF Summary'),h('a',{className:'kita-button',href:url('csv'),download:true},'Export CSV'),h('a',{className:'kita-button',href:url('print'),target:'_blank',rel:'noopener'},resultType==='expiry'?'Print Expiration Report':'Print report')]):null]),
        loaded?h('p',{className:'report-filter-hint'},(s.reportLoading||s.reportError||pendingFilters?'Showing the last successfully loaded results. ':'')+'Generated: '+r.generated):null,
        h('p',{className:'report-filter-hint'},Object.entries(r.labels||{}).map(([key,value])=>key+': '+value).join(' | ')),
        h('div',{className:'report-summary'},Object.entries(r.summary||{}).map(([label,value])=>h('div',{key:label,className:'report-metric'},[h('span',null,label),h('strong',null,value)]))),
        loaded&&(s.reportAppliedFilters?.type||f.type)==='sales'?this.buildReportCharts(r.rows):null,
        this.recordTable('generated-report',[...Object.keys(r.columns),...(resultType==='purchase_orders'?['Actions']:[])],r.rows.map((row,i)=>tr([...Object.values(r.columns).map(key=>td(row[key]==null?'-':['reportPo','poId'].includes(key)?(resultType==='purchase_orders'?row[key]:this.purchaseReportLink(row[key])):key==='status'?((s.reportAppliedFilters?.type||f.type)==='expiry'?h('span',{className:'expiry-status expiry-status--'+String(row[key]).toLowerCase()},row[key]):badge(row[key])):(r.moneyKeys||[]).includes(key)?Number(row[key]).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}):row[key],(r.numericKeys||[]).includes(key)?{textAlign:'right',fontVariantNumeric:'tabular-nums'}:key==='reference'?{fontFamily:'monospace'}:{})),...(resultType==='purchase_orders'?[td(btn('View',()=>this.setState({reportReceiptDetail:row})))]:[])],i)),{emptyMessage,externalSearch:true}),
        !r.rows.length&&activeCount?h('div',{className:'action-group'},[h('span',null,'Try removing a filter to see more records.'),btn('Clear all filters',this.resetReportFilters)]):null,

        h('p',{className:'report-filter-hint'},r.note)
      ])
    ]);
  }
  buildAdmManagers(){
    const h=React.createElement,{accountsLocal,acctModalOpen,acctModalMode,acctForm:f}=this.state;
    const roles=this.state.role==="manager"?["cashier"]:this.state.role==="superadmin"?["admin","manager","cashier","superadmin"]:["cashier","manager"];
    const title=this.state.role==="superadmin"?"User Management":this.state.role==="manager"?"Cashier Accounts":"Manage Accounts";
    const input=(key,label,type="text")=>h("label",{key,style:{display:"block",fontSize:12,fontWeight:700,marginBottom:10}},[label,h("input",{type,value:f[key]||"",onChange:this.setAcctField(key),autoComplete:type==="password"?"new-password":"off",style:{width:"100%",boxSizing:"border-box",padding:10,marginTop:4,border:"1px solid "+COLORS.border,borderRadius:8}})]);
    return h("div",null,[moduleTitle(title),btn("Create Account",this.openAcctModal("create"),"primary"),
      h("div",{className:"sa-filters"},[
        h("label",null,["Role ",h("select",{value:this.state.acctRoleFilter,onChange:e=>{this.setState({acctRoleFilter:e.target.value});this.setTableView("accounts",{page:1});}},[h("option",{value:""},"All roles"),...roles.map(r=>h("option",{value:r,key:r},r))])]),
        h("label",null,["Status ",h("select",{value:this.state.acctStatusFilter,onChange:e=>{this.setState({acctStatusFilter:e.target.value});this.setTableView("accounts",{page:1});}},[h("option",{value:""},"All statuses"),...['Active','Inactive'].map(v=>h("option",{value:v,key:v},v))])])
      ]),
      this.recordTable("accounts",["Name","Email / Username","Role","Status","Created","Last OTP login","Actions"],(accountsLocal||[]).filter(a=>(!this.state.acctRoleFilter||a.role===this.state.acctRoleFilter)&&(!this.state.acctStatusFilter||a.status===this.state.acctStatusFilter)).map(a=>tr([td(a.name),td([h("div",null,a.email),h("small",null,a.username||"")]),td(a.role),td(h("span",{className:"sa-status "+(a.status==="Active"?"sa-status--healthy":"sa-status--inactive")},a.status.toUpperCase())),td(a.dateCreated||"Not recorded"),td(a.lastOtpLogin||"Not recorded"),td([btn("Edit",this.openAcctModal("edit",a)),btn(a.status==="Active"?"Deactivate":"Activate",()=>this.changeAccountStatus(a))])],a.role+"-"+a.id))),
      acctModalOpen?h("div",{key:"modal",style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50,padding:16}},
        h("div",{role:"dialog","aria-modal":true,"aria-label":acctModalMode==="create"?"Create Account":"Edit Account",style:{width:400,maxHeight:"85vh",overflowY:"auto",background:"#fff",borderRadius:14,padding:20}},[
          h("h3",{key:"title"},acctModalMode==="create"?"Create Account":"Edit Account"),input("name","Full Name"),input("email","Email","email"),input("username","Employee ID (optional)"),
          h("label",{key:"role"},["Role",h("select",{value:f.role,disabled:acctModalMode==="edit",onChange:this.setAcctField("role"),style:{width:"100%",padding:10,marginBottom:10}},roles.map(role=>h("option",{key:role,value:role},role)))]),
          input("password",acctModalMode==="create"?"Password (at least 8 characters)":"New password (leave blank to keep current)","password"),input("schedule","Schedule (optional)"),
          h("label",{key:"status"},["Status",h("select",{value:f.status,onChange:this.setAcctField("status"),style:{width:"100%",padding:10,marginBottom:10}},(acctModalMode==="create"?["Active"]:["Active","Inactive"]).map(status=>h("option",{key:status},status)))]),
          this.state.acctError?h("p",{key:"error",role:"alert",style:{color:COLORS.red}},this.state.acctError):null,
          h("div",{key:"buttons",style:{display:"flex",gap:8}},[btn("Cancel",this.closeAcctModal),btn("Save",this.saveAccount,"primary")]),
        ])):null,
    ]);
  }

  buildAdmNotifications(){
    return React.createElement('div',null,[moduleTitle('Your Notifications'),btn('Refresh',this.retryNotifications),
      this.state.notificationError?React.createElement('p',{role:'alert'},this.state.notificationError):null,
      this.recordTable('notifications',['Time','Event','Message','Status','Action'],(this.state.mgrNotificationsLocal||[]).map(n=>tr([td(n.created_at),td(n.type),td(n.message),td(n.read_at?'Read':'Unread'),td(btn('Open',this.openMgrNotification(n)))],n.id))),
      this.state.notificationNext?btn('Load older notifications',this.loadMoreNotifications):null]);
  }

  // ---- Super Admin screens ----
  handleSessionResponse=(response,body)=>{
    if(response.status===401 && body.deactivated){
      if(window.KITA_AUTH){window.KITA_AUTH.user=null;window.KITA_AUTH.csrfToken=body.csrf_token;}
      this.setState({authStep:"login",role:null,screen:null,authenticatedUser:null,mgrNotificationsLocal:[],notificationUnread:0,mgrNotifOpen:false,data:null,accountsLocal:null,purchaseData:null,purchaseReceivingId:null,newReqCart:[],saDashboard:null,loginPassword:"",otpCode:"",loginError:body.message});
    }
  };
  confirmAccountStatus=account=>window.confirm(`${account.status==="Inactive"?"Deactivate":"Activate"} Account?\n\nAre you sure you want to ${account.status==="Inactive"?"deactivate":"reactivate"} ${account.name}?${account.status==="Inactive"?"\n\nThis user will no longer be able to log in to KITA until their account is reactivated.":""}`);
  changeAccountStatus=account=>{
    const updated={...account,status:account.status==="Active"?"Inactive":"Active"};
    if(this.accountBusy||!this.confirmAccountStatus(updated))return;
    this.accountBusy=true;
    this.authPost(`/api/accounts/${encodeURIComponent(account.role)}/${account.id}`,{status:updated.status},"PATCH")
      .then(async result=>{this.toast(result.message);await this.reloadAccounts();if(this.state.role==="superadmin")await this.reloadSaDashboard();})
      .catch(error=>this.toast(error.message,"error")).finally(()=>{this.accountBusy=false;});
  };
  reloadSaDashboard=async()=>{
    this.setState({saLoading:true,saError:""});
    try{
      const response=await fetch("/api/super-admin/dashboard",{headers:{Accept:"application/json"},credentials:"same-origin"});
      const body=await response.json();this.handleSessionResponse(response,body);
      if(!response.ok)throw new Error(body.message||"Unable to retrieve system performance information.");
      if(this.state.role==="superadmin")this.setState({saDashboard:body});
    }catch(error){this.setState({saError:"Unable to retrieve system performance information.",saDashboard:null});}
    finally{this.setState({saLoading:false});}
  };
  buildSaDashboard(){
    const h=React.createElement,d=this.state.saDashboard;
    if(this.state.saLoading)return h("div",{className:"sa-skeleton",role:"status","aria-busy":true},loadingStatus("Loading system performance and user activity..."));
    if(this.state.saError)return h("div",{className:"sa-error",role:"alert"},[h("p",null,this.state.saError),btn("Retry",this.reloadSaDashboard,"primary")]);
    if(!d)return h("p",{role:"status"},"System information has not been loaded yet.");
    const ms=value=>value<0.01?"< 0.01 ms":value.toFixed(2)+" ms";
    const metrics=[["Total Users",d.total_users],["Active Users",d.active_users],["Inactive Users",d.inactive_users],["Average audit events / day",d.average_daily_events]];
    const max=Math.max(1,...d.activity_days.map(day=>day.events));
    return h("div",{className:"sa-dashboard"},[
      moduleTitle("System Overview"),
      h("div",{className:"sa-kpis"},metrics.map(([label,value])=>h("article",{className:"sa-kpi",key:label},[h("div",null,label),h("strong",null,value)]))),
      h("div",{className:"sa-panels"},[
        h("section",{className:"sa-panel"},[h("h2",null,"System Performance"),...[["Application",d.application_status],["Database",d.database_status],["Database query",ms(d.database_response_ms)],["Dashboard calculation",ms(d.generation_ms)]].map(([label,value],i)=>h("div",{className:"sa-performance-row",key:label},[h("span",null,label),h("strong",{className:i<2?"sa-status sa-status--healthy":""},value)])),h("p",{className:"sa-note"},"Measured for this request: SELECT 1 database round trip and dashboard calculation time. These do not measure browser latency or server uptime."),btn("Refresh measurements",this.reloadSaDashboard)]),
        h("section",{className:"sa-panel"},[h("h2",null,"Recorded User Activity"),h("p",{className:"sa-note"},"Audit events per day across the last 7 completed calendar days (application timezone). Average = total recorded events / 7, including days with no events. This is not a login count."),
          d.activity_days.some(day=>day.events)?h("div",{className:"sa-chart"},d.activity_days.map(day=>h("div",{className:"sa-chart-row",key:day.date},[h("span",null,day.date),h("div",{className:"sa-chart-track"},h("div",{className:"sa-chart-bar",style:{width:(day.events/max*100)+"%"}})),h("strong",null,day.events)]))):h("p",{className:"record-empty"},"No user activity data is available yet.")])
      ]),
      h("section",{className:"sa-panel"},[h("h2",null,"Recent System Activity"),d.recent_activity.length?this.recordTable("sa-recent",["Time","User","Action","Record"],d.recent_activity.map((event,i)=>tr([td(event.ts),td(event.user),td(badge(event.action)),td(event.record)],i))):h("p",null,"No user activity data is available yet.")]),
      h("p",{className:"sa-note"},"Measured at "+new Date(d.measured_at).toLocaleString()+". Login history and reliable current-session counts are not recorded by this dashboard.")
    ]);
  }
  buildSaAdmins(){ return this.buildAdmManagers(); }
  buildSaRoles(){ const {rolesMatrix}=this.state; if(!rolesMatrix) return null;
    return React.createElement("div",null,[moduleTitle("Roles & Permissions"),
      stickyTable(React.createElement("div",{className:"table-scroll",tabIndex:0,role:"region","aria-label":"Role permissions",style:{overflowX:"auto",border:"1px solid "+COLORS.border,borderRadius:10}},React.createElement("table",{style:{width:"100%",borderCollapse:"collapse",fontSize:13}},[
        React.createElement("thead",{key:"h"},React.createElement("tr",null,[React.createElement("th",{key:"m",style:{textAlign:"left",padding:"10px 14px",background:"#f8faff",fontSize:11,color:COLORS.textMuted}},"Module"),...rolesMatrix.roles.map(r=>React.createElement("th",{key:r,style:{padding:"10px 14px",background:"#f8faff",fontSize:11,color:COLORS.textMuted}},r))])),
        React.createElement("tbody",{key:"b"},rolesMatrix.grid.map((row,mi)=>tr([td(row.module,{fontWeight:600}),...row.cells.map((c,ri)=>td(React.createElement("div",{onClick:this.toggleRolePerm(mi,ri),style:{width:36,height:20,borderRadius:10,background:c?COLORS.green:"#e3e6ec",position:"relative",cursor:"pointer",margin:"0 auto"}},React.createElement("div",{style:{width:16,height:16,borderRadius:"50%",background:"#fff",position:"absolute",top:2,left:c?18:2}})),{textAlign:"center"}))],mi)))
      ])))]);
  }
  buildSaBackup(){
    const h=React.createElement,d=this.state.backupData,items=d?.backups||[],id=this.state.backupRestoreId;
    return h("div",null,[moduleTitle("Backup Configuration"),
      this.state.backupError&&h("p",{role:"alert",style:{color:COLORS.red}},this.state.backupError),
      card([
        h("p",null,d?`Daily schedule: ${d.schedule} (${d.timezone}). Retention: ${d.retention_days} days.`:"Loading backup configuration..."),
        h("p",null,"Automatic backups require the server scheduler to be running. Download copies to separate storage to protect against server loss. Includes database records; uploaded files require a separate backup."),
        h("p",null,`Last completed backup: ${items[0]?new Date(items[0].created_at).toLocaleString():"No backups recorded"}`),
        h("div",{style:{display:"flex",gap:10}},[
          h("button",{style:{padding:"9px 16px",borderRadius:8,border:"none",background:COLORS.brand,color:"#fff",fontWeight:700,cursor:this.state.backupBusy?"wait":"pointer",opacity:this.state.backupBusy?0.6:1},disabled:!!this.state.backupBusy,"aria-busy":!!this.state.backupBusy,onClick:this.runBackupNow},this.state.backupBusy?"Creating backup...":"Run Backup Now"),
          btn(this.state.backupLoading?"Loading...":"Refresh",this.reloadBackups)
        ])
      ]),
      this.recordTable("backups",["Created","Size","Actions"],items.map(item=>tr([
        td(new Date(item.created_at).toLocaleString()),td(`${(item.bytes/1024).toFixed(1)} KB`),
        td(h("div",{style:{display:"flex",gap:12}},[h("a",{className:"kita-button",href:`/api/backups/${encodeURIComponent(item.id)}/download`},"Download"),btn("Restore instructions",this.restoreBackup(item.id))]) )
      ],item.id))),
      id&&card([h("strong",null,"Restore replaces current database records"),
        h("p",null,"Ask your server administrator to stop queue workers and other writers, enable maintenance mode, and run these commands. A safety backup is created first. Keep the original application encryption key to decrypt backups."),
        h("pre",{style:{whiteSpace:"pre-wrap",overflowWrap:"anywhere"}},`php artisan down\nphp artisan backup:restore ${id} --confirm=${id}`),
        h("p",null,"Verify restored data, reconcile payments made since the snapshot, then run php artisan up and restart workers. See docs/BACKUPS.md for recovery instructions."),btn("Close",()=>this.setState({backupRestoreId:null}))])
    ]);
  }
  buildSaAudit(){ const {data}=this.state; if(!data) return null;
    return React.createElement("div",null,[moduleTitle("Audit Logs & Activity"),
      this.recordTable("audit",["Timestamp","User","Action","Record","Changes"],data.AUDIT_LOGS.map((a,i)=>tr([td(a.ts,{fontFamily:"'JetBrains Mono',monospace",fontSize:12}),td(a.user),td(badge(a.action)),td(a.record),td(auditChanges(a.before,a.after,data.PRODUCTS))],i))),
      React.createElement("div",{style:{height:14}}),
      card([React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"Field Version History"),
        this.recordTable("field-history",["Field","Record","Old Value","New Value","User","Timestamp","Reason"],data.FIELD_VERSION_HISTORY.map((f,i)=>tr([td(f.field),td(f.record),td(f.oldValue),td(f.newValue),td(f.user),td(f.ts,{fontSize:12}),td(f.reason)],i)))]),
      React.createElement("div",{style:{marginTop:12,display:"flex",gap:10}},[btn("Export")])]);
  }

  renderVals(){
    const s=this.state; const data=s.data;
    const showPortalSelect=s.authStep==="portal", showLogin=s.authStep==="login", showOtp=s.authStep==="otp", showApp=s.authStep==="in";
    const portalMeta={
      cashier:{label:"Cashier",desc:"Checkout & Sales",icon:"C",bg:"#eaf0ff",fg:"#1747e8"},
      manager:{label:"Manager",desc:"Inventory & Purchasing",icon:"M",bg:"#eaf1ff",fg:"#2563eb"},
      admin:{label:"Admin",desc:"Store Oversight & Approvals",icon:"A",bg:"#f2eafe",fg:"#7c3aed"},
      superadmin:{label:"Super Admin",desc:"System Administration",icon:"S",bg:"#fdf3dc",fg:"#92620a"},
    };
    const activePortal=portalMeta[s.pendingRole]||portalMeta.cashier;
    const navMap={
      cashier:[{label:null,items:[{key:"pos",label:"Checkout"},{key:"refunds",label:"Refunds / Exchanges / Voids"},{key:"shift",label:"My Shift Transactions"}]}],
      manager:[{label:"OPERATIONS",items:[{key:"mgrDashboard",label:"Dashboard"},{key:"pos",label:"Checkout"}]},
        {label:"PURCHASE ORDERS",items:[{key:"mgrRequest",label:"Create Purchase Order"},{key:"mgrRequestView",label:"View Purchase Orders"},{key:"purchaseOrders",label:"Purchase Orders"},{key:"supplierAccounts",label:"Supplier Accounts"},{key:"mgrPurchaseHistory",label:"Transaction History"},{key:"mgrPO",label:"Stock Receiving"}]},
        {label:"INVENTORY",items:[{key:"mgrInventory",label:"Inventory"},{key:"mgrCategories",label:"Categories / Brands"},{key:"mgrRegistration",label:"Barcode/Item Registration"},{key:"archive",label:"Archive"}]},
        {label:"REPORTS",items:[{key:"admReports",label:"Reports"}]},
        {label:"TEAM & MERCH",items:[{key:"mgrCashiers",label:"Cashier Accounts"},{key:"mgrPromotions",label:"Promotions"},{key:"mgrSupplier",label:"Supplier"}]}],
      admin:[{label:null,items:[{key:"admDashboard",label:"Dashboard/Analytics"}]},
        {label:"PURCHASING",items:[{key:"admPurchasedOrders",label:"Purchase Orders"},{key:"admForwarded",label:"Forwarded Purchase Requests"},{key:"admDisapproved",label:"Disapproved Purchase Requests"},{key:"admReceivingApprovals",label:"Receiving History"}]},
        {label:"CONTROLS",items:[{key:"mgrCategories",label:"Categories / Brands"},{key:"mgrRegistration",label:"Product Registration"},{key:"mgrInventory",label:"Inventory"},{key:"admInventoryApprovals",label:"Inventory Adjustment Approvals"},{key:"admSuppliers",label:"Supplier Records"},{key:"purchaseOrders",label:"Purchase Orders"},{key:"supplierAccounts",label:"Supplier Accounts"},{key:"admReports",label:"Reports"},{key:"archive",label:"Archive"}]},
        {label:"ADMIN",items:[{key:"admManagers",label:"Manage Accounts"},{key:"admNotifications",label:"Notifications"}]}],
      superadmin:[{label:null,items:[{key:"saDashboard",label:"Dashboard"},{key:"saAdmins",label:"User Management"},{key:"saRoles",label:"Roles & Permissions"},{key:"saBackup",label:"Backup Configuration"},{key:"saAudit",label:"Audit Logs"}]}],
    };
    const navGroups=(navMap[s.role]||[]).map(g=>({label:g.label,items:g.items.map(it=>({key:it.key,label:it.label,icon:navigationIcon(it.key),go:this.goScreen(it.key),className:s.screen===it.key?"nav-item nav-item--active":"nav-item",current:s.screen===it.key?"page":undefined,
      style:{padding:"9px 12px",borderRadius:8,fontSize:13,fontWeight:600,cursor:"pointer",marginBottom:2,background:s.screen===it.key?"rgba(255,255,255,0.14)":"transparent"}}))}));
    const titles={purchaseOrders:"Purchase Orders",supplierAccounts:"Supplier Accounts",pos:"Checkout",refunds:"Refunds / Exchanges / Voids",shift:"My Shift Transactions",mgrDashboard:"Manager Dashboard",mgrRequest:"Create Purchase Order",mgrRequestView:"View Purchase Orders",mgrPurchaseHistory:"Transaction History",mgrPO:"Stock Receiving",
      mgrInventory:"Inventory",mgrCategories:"Categories",mgrRegistration:"Barcode/Item Registration",mgrCashiers:"Cashier Accounts",mgrPromotions:"Promotions",mgrSupplier:"Supplier",archive:"Archive",
      admDashboard:"Dashboard/Analytics",admPurchasedOrders:"Purchase Orders",admForwarded:"Forwarded Purchase Requests",admDisapproved:"Disapproved Purchase Requests",
      admReceivingApprovals:"Receiving History",admInventoryApprovals:"Inventory Adjustment Approvals",admSuppliers:"Supplier Records",admReports:"Reports",admManagers:"Manage Accounts",admNotifications:"Notifications",
      saDashboard:"Dashboard",saAdmins:"User Management",saRoles:"Roles & Permissions",saBackup:"Backup Configuration",saAudit:"Audit Logs & Activity"};
    let activeScreen=null;
    if(data || s.role==="superadmin"){
      const map={pos:()=>this.buildPos(), refunds:()=>this.buildRefunds(), shift:()=>this.buildShift(),
        purchaseOrders:()=>this.buildPurchaseOrderWorkspace(), supplierAccounts:()=>this.buildSupplierAccounts(), mgrDashboard:()=>this.buildMgrDashboard(), mgrRequest:()=>this.buildMgrRequest(), mgrRequestView:()=>this.buildPurchaseRequests(), mgrPurchaseHistory:()=>this.buildPurchaseHistory(), mgrPO:()=>this.buildMgrPO(),
        mgrInventory:()=>this.buildMgrInventory(), mgrCategories:()=>this.buildMgrCategories(), mgrRegistration:()=>this.buildMgrRegistration(), mgrCashiers:()=>this.buildMgrCashiers(), mgrPromotions:()=>this.buildMgrPromotions(), mgrSupplier:()=>this.buildMgrSupplier(), archive:()=>this.buildArchive(),
        admDashboard:()=>this.buildAdmDashboard(), admPurchasedOrders:()=>this.buildAdmPurchasedOrders(), admForwarded:()=>this.buildAdmForwarded(), admDisapproved:()=>this.buildAdmDisapproved(),
        admReceivingApprovals:()=>this.buildAdmReceivingApprovals(), admInventoryApprovals:()=>this.buildAdmInventoryApprovals(), admSuppliers:()=>this.buildAdmSuppliers(), admReports:()=>this.buildAdmReports(),
        admManagers:()=>this.buildAdmManagers(), admNotifications:()=>this.buildAdmNotifications(),
        saDashboard:()=>this.buildSaDashboard(), saAdmins:()=>this.buildSaAdmins(), saRoles:()=>this.buildSaRoles(), saBackup:()=>this.buildSaBackup(), saAudit:()=>this.buildSaAudit()};
      activeScreen = map[s.screen] ? map[s.screen]() : null;
    }

    return {
      sidebarClass:s.sidebarOpen?"sidebar sidebar--open":"sidebar",sidebarOpen:s.sidebarOpen,toggleSidebar:this.toggleSidebar,closeSidebar:this.closeSidebar,onShellKeyDown:this.onShellKeyDown,
      dataLoading:(s.pendingLoads||0)>0||(!!s.purchaseLoading&&["purchaseOrders","mgrRequest","mgrRequestView","mgrPurchaseHistory","mgrPO","mgrSupplier","admSuppliers","admPurchasedOrders","admForwarded","admDisapproved","admReceivingApprovals"].includes(s.screen))||!!s.backupLoading||!!s.backupBusy||!!s.supplierAccountsLoading||!!s.workflowLoading||!!s.inventoryHistoryLoading||!!s.reportLoading||!!s.saLoading||!!s.loginPending||!!s.registrationSaving||s.paymentState==="processing"||(!!s.mgrNotifOpen&&!!s.notificationLoading),
      dataLoadingLabel:s.backupBusy?"Creating your backup...":s.backupLoading?"Loading backups...":s.purchaseLoading?"Loading purchase records...":"Loading your workspace data...",dataError:s.dataError,activePortalColor:activePortal.fg,activePortalBg:activePortal.bg,activePortalIcon:activePortal.icon,activePortalLabel:activePortal.label,activePortalDesc:activePortal.desc,backToPortalSelect:this.backToPortalSelect,
      showLogin,showOtp,showApp,loginUsername:s.loginUsername,onUsernameChange:this.onUsernameChange,loginPassword:s.loginPassword,onPasswordChange:this.onPasswordChange,
      otpEmail:s.otpEmail,otpCode:s.otpCode,onOtpChange:this.onOtpChange,verifyOtp:this.verifyOtp,backToPassword:this.backToPassword,
      loginIdentifierLabel:"Email",loginIdentifierPlaceholder:"name@example.com",showPasswordInput:true,
      loginPending:s.loginPending,loginButtonLabel:s.loginPending?"Signing in...":"Sign In",otpButtonLabel:s.loginPending?"Verifying...":"Verify OTP",loginError:s.loginError,loginLocked:s.loginLocked,doLogin:this.doLogin,onLoginKeyDown:this.onLoginKeyDown,
      appShellClass:s.sidebarOpen&&s.compactNavigation?"app-shell app-shell--drawer-open":"app-shell",
      navigationInert:s.sidebarOpen&&s.compactNavigation?"":undefined,
      navigationHidden:s.sidebarOpen&&s.compactNavigation?true:undefined,
      role:s.role,currentUserName:this.currentUser().name,roleIcon:(portalMeta[s.role]||portalMeta.cashier).icon,roleLabel:(portalMeta[s.role]||portalMeta.cashier).label,logout:this.logout,navGroups,screenTitle:titles[s.screen]||"",screenGroup:(navGroups.find(g=>g.items.some(item=>item.key===s.screen))?.label)||"Workspace",
      showMgrBell:!!s.role, toggleMgrNotif:this.toggleMgrNotif, mgrNotifOpen:s.mgrNotifOpen,
      mgrNotifUnreadCount: s.notificationUnread || null,
      mgrNotifEmpty: !s.notificationLoading&&!s.notificationError&&(s.mgrNotificationsLocal||[]).length===0,
      notificationLoading:!!s.notificationLoading,notificationError:s.notificationError,notificationHasMore:!!s.notificationNext,
      markAllNotifications:this.markAllNotifications,loadMoreNotifications:this.loadMoreNotifications,retryNotifications:this.retryNotifications,closeNotifications:this.closeNotifications,
      mgrNotifItems: (s.mgrNotificationsLocal||[]).map(n=>({
        type:n.type, message:n.message, onClick:this.openMgrNotification(n),time:n.created_at||'',className:n.read_at?'notif-item':'notif-item notif-item--unread',
        priorityColor: n.priority==="Critical"?"#dc2626":n.priority==="Standard"?"#92620a":"#2563eb",
        style:{padding:"10px 14px",borderBottom:"1px solid #eef1f6",cursor:"pointer",background:n.read_at?"#fff":"#f8faff"},
      })),
      toastWrapStyle:{position:"fixed",top:16,right:16,zIndex:100,display:"flex",flexDirection:"column"},toasts:s.toasts,
      activeScreen,
    };
  }
}
