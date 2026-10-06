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
  return originalCreateElement(type, cleanProps, ...children.map(cleanMojibake));
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

function peso(n){ n=Number(n)||0; return "â‚±"+n.toLocaleString("en-PH",{minimumFractionDigits:2,maximumFractionDigits:2}); }
function badge(status){ const c=statusStyle(status); return React.createElement("span",{className:"status-badge",style:{background:c.bg,color:c.fg,fontSize:11,fontWeight:700,padding:"3px 10px",borderRadius:20,display:"inline-block",whiteSpace:"nowrap"}},status); }
function card(children, style){ return React.createElement("div",{className:"kita-card",style:Object.assign({background:COLORS.surface,border:"1px solid "+COLORS.border,borderRadius:12,padding:18},style||{})},children); }
function sectionTitle(t, sub){ return React.createElement("div",{className:"section-heading",style:{marginBottom:16}},[React.createElement("h1",{key:"t",style:{fontSize:24,fontWeight:800,margin:0,letterSpacing:-0.7}},t), sub?React.createElement("div",{key:"s",style:{fontSize:13,color:COLORS.textSoft,marginTop:2}},sub):null]); }
function table(headers, rows){ return React.createElement("div",{className:"table-scroll",tabIndex:0,role:"region","aria-label":headers.filter(h=>typeof h==="string"&&h).join(", "),style:{"--table-min-width":Math.max(540,headers.length*110)+"px",overflowX:"auto",border:"1px solid "+COLORS.border,borderRadius:10}}, React.createElement("table",{style:{width:"100%",borderCollapse:"collapse",fontSize:13}},[
  React.createElement("thead",{key:"h"},React.createElement("tr",null, headers.map((hd,i)=>React.createElement("th",{key:i,style:{position:"sticky",top:0,background:"#f8faff",textAlign:"left",padding:"10px 14px",fontSize:11,fontWeight:700,color:COLORS.textMuted,letterSpacing:0.4,borderBottom:"1px solid "+COLORS.border}},hd)))),
  React.createElement("tbody",{key:"b"},rows.length?rows:React.createElement("tr",null,React.createElement("td",{colSpan:headers.length,style:{padding:20,textAlign:"center",color:COLORS.textMuted,fontSize:13}},"No records yet."))),
])); }
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
function recordText(node){
  if(node==null||typeof node==="boolean")return "";
  if(typeof node==="string"||typeof node==="number")return String(node);
  if(Array.isArray(node))return node.map(recordText).join(" ");
  if(["button","input","select","svg"].includes(node.type))return "";
  return recordText(node.props?.children??node.children);
}
function rankedSales(rows){
  const maximum=Math.max(1,...rows.map(row=>row.rev));
  return rows.length?React.createElement("div",{className:"sales-ranking"},rows.map((row,index)=>React.createElement("div",{key:row.p.id,className:"sales-rank"},[
    React.createElement("div",{key:"label",className:"sales-rank-label"},[React.createElement("span",{key:"name"},`${index+1}. ${row.p.name}`),React.createElement("strong",{key:"value"},peso(row.rev))]),
    React.createElement("div",{key:"bar",className:"sales-bar","aria-hidden":true},React.createElement("span",{style:{width:(row.rev/maximum*100)+"%"}})),
  ]))):React.createElement("div",{className:"record-empty"},"No sales recorded for these products yet.");
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
    authStep:"login", loginPending:false, loginUsername:"", loginPassword:"", otpCode:"", otpEmail:"", loginError:"", loginTries:0, loginLocked:false, pendingRole:null,
    role:null, screen:null, toasts:[], sidebarOpen:false,tableViews:{},
    barcodeInput:"", cart:[], scannerConnected:true, manualLookupOpen:false, manualSearch:"",
    seniorPwdOpen:false, seniorForm:{name:"",idNumber:""}, seniorApplied:false, seniorInfo:null, employeeDiscount:false,
    overrideLine:null, overrideReason:"", overridePin:"",
    paymentMethod:"cash", ewalletProvider:"GCash", tendered:"", paymentState:"idle", reservationSeconds:45,
    commitOpen:false, lateWebhook:false, transactionResult:null,
    refundLookup:"", refundTxn:null, refundError:"", refundClass:"", refundSelectedLines:[],
    exchangeMode:false, exchangeReplacement:"", voidMode:false, voidPin:"", voidError:"",
    invTab:"adjustments", supplierDetailId:null,
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
  recordTable(key,headers,rows){
    const h=React.createElement,view=this.state.tableViews[key]||{},query=view.query||"",size=Number(view.size)||20;
    const filtered=query.trim()?rows.filter(row=>recordText(row).toLowerCase().includes(query.trim().toLowerCase())):rows;
    const pages=Math.max(1,Math.ceil(filtered.length/size)),page=Math.min(Math.max(1,view.page||1),pages),start=(page-1)*size;
    const searchId="records-"+key;
    return h("section",{className:"record-list","aria-label":"Records"},[
      h("div",{key:"toolbar",className:"record-toolbar"},[
        h("label",{key:"search",className:"record-search",htmlFor:searchId},[uiIcon("search"),h("span",{className:"sr-only"},"Search records"),h("input",{id:searchId,type:"search",value:query,placeholder:"Search these records...",onChange:e=>this.setTableView(key,{query:e.target.value,page:1})})]),
        query?h("button",{key:"clear",type:"button",className:"kita-button",onClick:()=>this.setTableView(key,{query:"",page:1})},"Clear search"):null,
        h("label",{key:"size",className:"record-page-size"},["Rows per page",h("select",{value:size,onChange:e=>this.setTableView(key,{size:Number(e.target.value),page:1})},[10,20,50,100].map(n=>h("option",{key:n,value:n},n)))])
      ]),
      table(headers,filtered.slice(start,start+size)),
      !filtered.length?h("p",{key:"empty",className:"record-empty",role:"status"},query?"No matching records. Try another search or clear your filters.":"Records will appear here when available."):null,
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
  componentDidUpdate(prevProps, prevState) {
    if(this.state.discountManagerPin && (prevState.cart!==this.state.cart || prevState.seniorApplied!==this.state.seniorApplied || prevState.employeeDiscount!==this.state.employeeDiscount || prevState.role!==this.state.role)){
      this.setState({discountManagerPin:""});
    }
  }
  syncNavigationViewport=()=>{
    const compact=window.matchMedia("(max-width: 1023px)").matches;
    this.setState({compactNavigation:compact,...(!compact?{sidebarOpen:false}:{})});
  };
  componentDidMount() {
    this.syncNavigationViewport();
    window.addEventListener("resize",this.syncNavigationViewport);
    if(window.KITA_AUTH?.loginError)this.setState({loginError:window.KITA_AUTH.loginError});
    const sessionUser=window.KITA_AUTH?.user;
    if(sessionUser) this.enterApp(sessionUser.role,null,sessionUser);
    this.notificationTimer=setInterval(()=>{if(this.state.role)this.reloadNotifications();},15000);
    this.purchaseRefreshTimer=setInterval(()=>{
      if(["manager","admin","superadmin"].includes(this.state.role)&&["mgrRequestView","mgrPurchaseHistory","mgrPO","admPurchasedOrders","admForwarded","admDisapproved","admReceivingApprovals"].includes(this.state.screen)&&!this.state.purchaseLoading&&!this.reviewBusy&&!this.state.purchaseReviewId&&!this.state.purchaseReceivingId){
        this.reloadPurchasing().catch(()=>{});
      }
    },30000);
  }
  componentWillUnmount(){ window.removeEventListener("resize",this.syncNavigationViewport); clearInterval(this.purchaseRefreshTimer); clearInterval(this.notificationTimer); }
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
    fieldVersionHistoryLocal:m.FIELD_VERSION_HISTORY,suppliersLocal:m.SUPPLIERS,promotionsLocal:m.PROMOTIONS,
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
  authPost=(url,payload,method="POST",signal,retried=false)=>fetch(url,{method,signal,headers:{"Content-Type":"application/json","Accept":"application/json","X-CSRF-TOKEN":(window.KITA_AUTH&&window.KITA_AUTH.csrfToken)||document.querySelector('meta[name="csrf-token"]')?.content||""},credentials:"same-origin",body:JSON.stringify(payload)}).then(async r=>{
    if(r.status===419&&!retried&&url===((window.KITA_AUTH&&window.KITA_AUTH.loginUrl)||'/login')){
      const refresh=await fetch('/auth/csrf-token',{signal,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      const token=await refresh.json();
      if(!refresh.ok||!token.csrf_token)throw new Error('Your session expired. Refresh this page and sign in again.');
      window.KITA_AUTH=window.KITA_AUTH||{};window.KITA_AUTH.csrfToken=token.csrf_token;
      return this.authPost(url,payload,method,signal,true);
    }
    const body=await r.json().catch(()=>{ throw new Error("Your session changed. Refresh the page to continue."); }); this.handleSessionResponse(r,body); if(!r.ok){ const msg=(body.errors && Object.values(body.errors)[0] && Object.values(body.errors)[0][0]) || body.message || "Request failed."; const err=new Error(msg); err.status=r.status; err.body=body; throw err; } if(this.state.authStep==='in'&&!url.startsWith('/api/notifications'))this.reloadNotifications(); return body; });
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
  goScreen=key=>()=>{ this.setState({screen:key,sidebarOpen:false}); if(["mgrRequest","mgrRequestView","mgrPurchaseHistory","mgrPO","admPurchasedOrders","admForwarded","admDisapproved","admReceivingApprovals"].includes(key))this.reloadPurchasing().catch(()=>{}); if(key==="saDashboard")this.reloadSaDashboard(); if(key==="saBackup")this.reloadBackups(); if(["mgrCashiers","admManagers","saAdmins"].includes(key))this.reloadAccounts().catch(error=>this.toast(error.message,"error")); if(key==="shift") this.reloadCatalog().catch(()=>this.toast("Could not refresh shift transactions.","error")); };

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
  promoStatus=(p)=>{ const today=this.state.data?.BUSINESS_DATE||new Date().toISOString().slice(0,10); if(today<p.startDate) return "Scheduled"; if(today>p.endDate) return "Expired"; return "Active"; };
  activePromo=(productId)=>{ const list=this.state.promotionsLocal; if(!list) return null; return list.find(pr=>pr.productIds.includes(productId) && this.promoStatus(pr)==="Active") || null; };
  addProductToCart=(p,manual)=>{
    if(p.status==="Quarantine"){ this.toast(`"${p.name}" is quarantined â€” blocked from sale.`,"error"); return; }
    if(p.stock<=0){ this.toast(`"${p.name}" is out of stock.`,"error"); return; }
    const existing=this.state.cart.find(l=>l.productId===p.id);
    if(existing && existing.qty>=p.stock){ this.toast(`Only ${p.stock} units of ${p.name} are available.`,"error"); return; }
    if(existing){ this.setState(s=>({cart:s.cart.map(l=>l.productId===p.id?{...l,qty:l.qty+1,pulse:true}:l)})); this.toast(`Added 1 more "${p.name}".`);
      setTimeout(()=>this.setState(s=>({cart:s.cart.map(l=>l.productId===p.id?{...l,pulse:false}:l)})),900); return; }
    const promo=this.activePromo(p.id);
    this.setState(s=>({cart:[...s.cart,{lineId:Date.now()+Math.random(),productId:p.id,name:this.productLabel(p),qty:1,unitPrice:p.price,originalPrice:p.price,vatClass:p.vatClass,manual:!!manual,
      overridden:false,overrideReason:"",overrideApprover:"",promo,pulse:false}]}));
    this.toast(`Added "${p.name}"${manual?" (Manual Entry)":""}${promo?" â€” promo "+promo.name+" applied":""}.`);
  };
  stepQty=(lineId,delta)=>()=>{
    const line=this.state.cart.find(l=>l.lineId===lineId);
    if(!line) return;
    const product=(this.state.productsLocal||this.state.data?.PRODUCTS||[]).find(p=>p.id===line.productId);
    if(delta>0 && (!product || line.qty+delta>product.stock)){ this.toast(`Only ${product?.stock||0} units of ${line.name} are available.`,"error"); return; }
    this.setState(s=>({cart:s.cart.map(l=>l.lineId!==lineId?l:{...l,qty:Math.max(1,l.qty+delta)})}));
  };
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
  confirmOverride=()=>{ const {overrideLine,overrideReason,overridePin}=this.state;
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
  startPayment=()=>{
    if(this.checkoutInFlight || this.state.paymentState==="processing") return;
    if(this.state.cart.length===0){ this.toast("Cart is empty.","error"); return; }
    const catalog=this.state.productsLocal||this.state.data?.PRODUCTS||[];
    for(const line of this.state.cart){
      const product=catalog.find(p=>p.id===line.productId);
      if(!product || product.status!=="Active" || line.qty>product.stock){
        this.toast(product?`Only ${product.stock} sellable units of ${line.name} are available. Please update the cart.`:"A cart product is unavailable.","error");
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
    const refCodes={GCash:"GC-",Maya:"MY-",GrabPay:"GP-"};
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
        return sold?{...p,stock:Math.max(0,p.stock-sold.qty)}:p;
      })}));
      this.toast("Sale saved and inventory updated.");
      this.reloadCatalog().catch(()=>this.toast("Sale saved. Refresh the page to reload inventory.","warn"));
    }).catch(error=>{
      this.setState({paymentState:"idle",transactionResult:null});
      this.toast(error.message,"error");
      this.reloadCatalog().catch(()=>{});
    }).finally(()=>{ this.checkoutInFlight=false; this.setState({discountManagerPin:""}); });
  };
  newSale=()=>{this.checkoutUuid=null;this.setState({cart:[],seniorApplied:false,seniorInfo:null,employeeDiscount:false,paymentState:"idle",tendered:"",transactionResult:null,lateWebhook:false,discountManagerId:"",discountManagerPin:""});};
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

    ]);
  }

  // ---- Refunds / Exchanges / Voids ----
  setRefundLookup=e=>this.setState({refundLookup:e.target.value});
  doRefundLookup=()=>{ const {data,refundLookup}=this.state; if(!data) return;
    const t=data.TRANSACTIONS.find(tt=>tt.uuid.toLowerCase()===refundLookup.trim().toLowerCase());
    if(!t){ this.setState({refundError:"No transaction found matching that number.",refundTxn:null}); return; }
    if(!["Unused","Paid","Partially Refunded"].includes(t.status)){ this.setState({refundError:`Cannot refund â€” this transaction is already ${t.status}${t.refundDate?" ("+t.refundDate+")":""}.`,refundTxn:null}); return; }
    this.setState({refundTxn:t,refundAction:"",refundQuantities:{},refundRestock:true,providerRefundId:"",returnRequestKey:null,approvalManagerId:"",refundError:"",refundClass:"",refundSelectedLines:[],approvalPin:"",approvalResult:"",exchangeReplacement:""});
  };
  setRefundClass=cls=>()=>this.setState({refundClass:cls});
  processRefund=()=>this.submitManagerAction("refund");
  chooseRefundAction=action=>{ if(this.approvalBusy) return; this.setState({refundAction:action,returnRequestKey:null,refundQuantities:{},refundClass:"",approvalManagerId:"",approvalPin:"",exchangeReplacement:"",refundError:""}); };
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
      reason:this.state.refundClass||"",replacement_product_id:action==="exchange"?this.state.exchangeReplacement:null,
    },"PATCH").then(res=>{
      this.setState(s=>({refundTxn:{...s.refundTxn,status:res.status,refundDate:res.refundDate,lines:res.lines,refundedAmount:res.refundedAmount},refundQuantities:{},returnRequestKey:null,approvalPin:"",refundError:"",approvalResult:res.message}));
      this.toast(res.message);
      this.reloadCatalog().catch(()=>{});
    }).catch(error=>this.setState({refundError:error.message,approvalPin:""})).finally(()=>{this.approvalBusy=false;});
  };
  setExchangeReplacement=e=>this.setState({exchangeReplacement:e.target.value});
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
        if(n.screen==='mgrInventory')this.setState({invTab:'adjustments'});
        if(['mgrInventory','admInventoryApprovals','saAudit'].includes(n.screen))await this.reloadCatalog();
      }
      await this.reloadNotifications();
    }catch(error){this.setState({notificationError:error.message});}
  };

  // ---- Manager/Admin generic ----
  setInvTab=tab=>()=>this.setState({invTab:tab});
  openSupplierDetail=id=>()=>this.setState({supplierDetailId:id});
  closeSupplierDetail=()=>this.setState({supplierDetailId:null});
  approveAdjustment=id=>()=>{ this.authPost(`/api/inventory/adjustments/${encodeURIComponent(id)}`,{status:"Approved"},"PATCH").then(()=>{ this.setState(s=>({adjustmentsLocal:s.adjustmentsLocal.map(a=>a.id===id?{...a,status:"Approved"}:a)})); this.reloadCatalog().catch(()=>{}); this.pushMgrNotification("Adjustment Approved",`Inventory Adjustment ${id} was Approved by Admin`,"Standard",null); }).catch(error=>this.toast("Adjustment approval could not be saved: "+error.message,"error")); };
  rejectAdjustment=id=>()=>{ this.authPost(`/api/inventory/adjustments/${encodeURIComponent(id)}`,{status:"Rejected"},"PATCH").then(()=>{ this.setState(s=>({adjustmentsLocal:s.adjustmentsLocal.map(a=>a.id===id?{...a,status:"Rejected"}:a)})); this.pushMgrNotification("Adjustment Rejected",`Inventory Adjustment ${id} was Rejected by Admin`,"Critical",null); }).catch(error=>this.toast("Adjustment rejection could not be saved: "+error.message,"error")); };
  setNewAdjField=field=>e=>this.setState(s=>({newAdjForm:{...s.newAdjForm,[field]:e.target.value}}));
  submitAdjustment=()=>{
    const f=this.state.newAdjForm,q=Number(f.qtyChange);
    if(!f.productId||!Number.isInteger(q)||q<=0||!f.comment.trim()||!f.reason.trim()){this.toast("Select an item, positive whole quantity, reason, and comment.","error");return;}
    this.authPost("/api/inventory/adjustments",{productId:Number(f.productId),qtyChange:-q,reason:f.reason,comment:f.comment,photo:false})
      .then(res=>{this.setState({newAdjForm:{productId:"",qtyChange:"",reason:"Shrinkage",comment:""}});this.toast(res.message);return this.reloadCatalog();}).catch(error=>this.toast(error.message,"error"));
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
  openCategoryModal=()=>this.setState({categoryModalOpen:true,categoryEditing:false,categoryStep:'category',categorySetupName:'',categorySetupBrandId:'',categoryForm:{name:"",status:"Active",classification:""},categoryError:""});
  editCategory=c=>()=>this.setState({categoryModalOpen:true,categoryEditing:true,categoryForm:{...c},categoryOriginalName:c.name,categoryError:""});
  closeCategoryModal=()=>{if(!this.categoryBusy)this.setState({categoryModalOpen:false});};
  setCategoryField=field=>e=>this.setState(s=>({categoryForm:{...s.categoryForm,[field]:e.target.value},categoryError:""}));
  saveCategory=async()=>{
    if(this.categoryBusy)return;
    if(!this.state.categoryEditing&&['subcategory','brand'].includes(this.state.categoryStep))return this.saveCategorySetup();
    const f=this.state.categoryForm;
    if(!f.name.trim()||!["Perishable","Non-Perishable"].includes(f.classification)){this.setState({categoryError:"Enter a category name and classification."});return;}
    this.categoryBusy=true;
    try{
      const response=await this.authPost(this.state.categoryEditing?`/api/categories/${encodeURIComponent(this.state.categoryOriginalName)}`:"/api/categories",f,this.state.categoryEditing?"PATCH":"POST");
      const category=response.category;
      this.setState(s=>({categoryModalOpen:!s.categoryEditing,categoryStep:'subcategory',categorySetupCategory:category.name,categorySetupName:'',categoryError:'',
        categoriesLocal:[...(s.categoriesLocal||[]).filter(c=>c.name!==category.name),category],
        data:{...s.data,CATEGORIES:[...(s.data.CATEGORIES||[]).filter(c=>c.name!==category.name),category]},
        ...(!s.categoryEditing?{regCategory:category.name,regSubcategoryId:"",regBrandId:""}:{})}));
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
      const record=existing||(await this.authPost('/api/product-master/'+kind,{name,status:'Active',...(isSub?{category:s.categorySetupCategory}:{})})).record;
      this.setState(current=>({data:{...current.data,[kind.toUpperCase()]:[...(current.data[kind.toUpperCase()]||[]).filter(r=>String(r.id)!==String(record.id)),record]},categoryError:'',categorySetupName:'',
        ...(isSub?{categoryStep:'brand',regSubcategoryId:record.id}:{categoryModalOpen:false,regBrandId:record.id})}));
      this.toast(isSub?'Subcategory saved. Add the brand next.':'Category setup complete.');
      await this.reloadCatalog().catch(()=>this.toast('Saved, but the catalog could not refresh. Reload the page.','warn'));
    }catch(error){this.setState({categoryError:error.message});}finally{this.categoryBusy=false;}
  };
  setArchiveTab=tab=>()=>this.setState({archiveTab:tab,archiveChecked:{}});
  toggleArchiveCheck=key=>()=>this.setState(s=>({archiveChecked:{...s.archiveChecked,[key]:!s.archiveChecked[key]}}));
  restoreArchived=()=>{ const {archiveTab,archiveChecked}=this.state; const ids=Object.keys(archiveChecked).filter(k=>archiveChecked[k]);
    if(ids.length===0){ this.toast("Select at least one row.","error"); return; }
    if(archiveTab==="items") this.setState(s=>({productsLocal:s.productsLocal.map(p=>ids.includes(String(p.id))?{...p,status:"Active",archivedAt:null,archivedBy:null}:p)}));
    if(archiveTab==="categories") this.setState(s=>({categoriesLocal:s.categoriesLocal.map(c=>ids.includes(c.name)?{...c,status:"Active",archivedAt:null,archivedBy:null}:c)}));
    if(archiveTab==="suppliers") this.setState(s=>({suppliersLocal:s.suppliersLocal.map(sp=>ids.includes(String(sp.id))?{...sp,status:"Active",archivedAt:null,archivedBy:null}:sp)}));
    this.setState({archiveChecked:{}}); this.toast(`${ids.length} restored to Active.`);
  };
  openArchiveConfirm=()=>{ const count=Object.values(this.state.archiveChecked).filter(Boolean).length; if(count===0){ this.toast("Select at least one row.","error"); return; } this.setState({archiveConfirmOpen:true}); };
  closeArchiveConfirm=()=>this.setState({archiveConfirmOpen:false});
  deletePermanently=()=>{ const {archiveTab,archiveChecked}=this.state; const ids=Object.keys(archiveChecked).filter(k=>archiveChecked[k]);
    if(archiveTab==="items") this.setState(s=>({productsLocal:s.productsLocal.filter(p=>!ids.includes(String(p.id)))}));
    if(archiveTab==="categories") this.setState(s=>({categoriesLocal:s.categoriesLocal.filter(c=>!ids.includes(c.name))}));
    if(archiveTab==="suppliers") this.setState(s=>({suppliersLocal:s.suppliersLocal.filter(sp=>!ids.includes(String(sp.id)))}));
    this.setState({archiveChecked:{},archiveConfirmOpen:false}); this.toast(`${ids.length} permanently deleted.`,"warn");
  };
  toggleInvSelect=id=>()=>this.setState(s=>({invSelected:{...s.invSelected,[id]:!s.invSelected[id]}}));
  moveInvSelectedToArchive=()=>{ const ids=Object.keys(this.state.invSelected).filter(k=>this.state.invSelected[k]);
    if(!ids.length){ this.toast("Select at least one item.","error"); return; }
    this.setState(s=>({productsLocal:s.productsLocal.map(p=>ids.includes(String(p.id))?{...p,status:"Inactive",archivedAt:"2026-07-29",archivedBy:this.currentUser().name}:p),invSelected:{}}));
    this.toast(`${ids.length} item(s) moved to Archive.`);
  };
  toggleCatSelect=name=>()=>this.setState(s=>({catSelected:{...s.catSelected,[name]:!s.catSelected[name]}}));
  moveCatSelectedToArchive=()=>{ const names=Object.keys(this.state.catSelected).filter(k=>this.state.catSelected[k]);
    if(!names.length){ this.toast("Select at least one category.","error"); return; }
    this.setState(s=>({categoriesLocal:s.categoriesLocal.map(c=>names.includes(c.name)?{...c,status:"Inactive",archivedAt:"2026-07-29",archivedBy:this.currentUser().name}:c),catSelected:{}}));
    this.toast(`${names.length} categor${names.length===1?"y":"ies"} moved to Archive.`);
  };
  // ---- Manager Supplier module ----
  goSupMgrList=()=>this.setState({supMgrScreen:"list",supMgrDetailId:null});
  setSupMgrSearch=e=>this.setState({supMgrSearch:e.target.value});
  toggleSupMgrSelect=id=>()=>this.setState(s=>({supMgrSelected:{...s.supMgrSelected,[id]:!s.supMgrSelected[id]}}));
  moveSupMgrSelectedToArchive=()=>{ const ids=Object.keys(this.state.supMgrSelected).filter(k=>this.state.supMgrSelected[k]);
    if(!ids.length){ this.toast("Select at least one supplier.","error"); return; }
    this.setState(s=>({suppliersLocal:s.suppliersLocal.map(sp=>ids.includes(String(sp.id))?{...sp,status:"Inactive",archivedAt:"2026-07-29",archivedBy:this.currentUser().name}:sp),supMgrSelected:{}}));
    this.toast(`${ids.length} supplier(s) moved to Archive.`);
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
      return {[field]:value,...(field==="regCategory"?{regSubcategoryId:""}:{}),...(["regUnitPrice","regQuantity"].includes(field)?{regCostPrice:this.registrationCost(next.regUnitPrice,next.regQuantity)}:{})};
    });
  };
  saveRegistration=()=>{
    if(this.registrationBusy)return;
    const {data,productsLocal,regProductName,regCategory,regSupplier,regScannedBarcode,regQuantity,regCostPrice,regUnitPrice,regRetailPrice,regBatch,regLot,regExpiry,regPurchaseUnit,regStockUnit,regConversionFactor,regStatus,regVatClass}=this.state;
    if(this.state.regStockId && (!Number.isInteger(Number(this.state.regStockId))||Number(this.state.regStockId)<1)){this.toast("Stock ID must be a positive whole number.","error");return;}
    const name = regProductName.trim();
    if(!name||!regCategory){ this.toast("Product name and category are required.","error"); return; }
    if(!regScannedBarcode.trim()){ this.toast("Scan the item barcode before saving registration.","error"); return; }
    const qty=this.state.regEditing?(productsLocal||data.PRODUCTS).find(p=>p.id===this.state.regEditing).stock:0;
    const price=parseFloat(regRetailPrice||regUnitPrice);
    if(!this.validAmount(regRetailPrice||regUnitPrice)){ this.toast("Enter a valid retail price or unit price.","error"); return; }
    if(!this.validAmount(regUnitPrice)){this.toast("Enter a valid unit price with at most two decimal places.","error");return;}
    const calculatedCost="0";
    if(calculatedCost===""){ this.toast("Enter a valid unit price to calculate cost price.","error"); return; }
    const cost=Number(calculatedCost);
    if(!this.validAmount(regConversionFactor)||Number(regConversionFactor)<=0){ this.toast("Enter a positive conversion factor with at most two decimal places.","error"); return; }
    const conversionFactor=Number(regConversionFactor);
    const catalog=productsLocal||data.PRODUCTS;
    if(catalog.some(p=>p.id!==this.state.regEditing&&p.barcode===regScannedBarcode.trim())){ this.toast("This barcode is already registered.","error"); return; }
    const sku="";
    const barcode=regScannedBarcode.trim();
    const nextId=Math.max(0,...catalog.map(p=>Number(p.id)||0))+1;
    const supplier=(data.SUPPLIERS||[]).find(s=>s.name===regSupplier);
    const product={id:nextId,name,category:regCategory,vatClass:"VAT-Exempt",price,cost,stock:qty,minStock:0,unit:regStockUnit||"Piece",status:regStatus||"Active",batch:regBatch||"",lot:regLot||"",expiry:regExpiry||"",barcode,supplierId:supplier?supplier.id:regSupplier,parentId:null,variantLabel:"",purchaseUnit:regPurchaseUnit||"Piece",stockUnit:regStockUnit||"Piece",conversionFactor,barcodeStatus:"Scanned",archivedAt:null,archivedBy:null,unitPrice:Number(regUnitPrice)};
    this.registrationBusy=true;this.setState({registrationSaving:true});
    this.authPost("/api/products"+(this.state.regEditing?"/"+this.state.regEditing:""),{reason:this.state.regEditReason||null,id:this.state.regStockId?Number(this.state.regStockId):undefined,unitPrice:Number(regUnitPrice),brandId:this.state.regBrandId||null,subcategoryId:this.state.regSubcategoryId||null,size:this.state.regSize||null,sizeUnit:this.state.regSizeUnit||null,minStock:Number(this.state.regReorderLevel||0),name,category:regCategory,vatClass:"VAT-Exempt",price,cost,stock:qty,barcode,supplierId:supplier?supplier.id:null,batch:regBatch,lot:regLot,expiry:regExpiry,purchaseUnit:regPurchaseUnit,stockUnit:regStockUnit,conversionFactor,status:regStatus},this.state.regEditing?"PATCH":"POST").then(response=>{
      const savedProduct={...product,...response.product};
      this.setState({productsLocal:[...catalog.filter(p=>p.id!==savedProduct.id),savedProduct],regSaved:true,regSku:String(response.product.id),regBarcode:barcode,regRegisteredQty:qty});
      this.reloadCatalog().then(()=>this.toast(`"${name}" registered and saved to the product catalog.`)).catch(()=>this.toast(`"${name}" saved, but the catalog could not refresh. Reload the page.` ,"warn"));
    }).catch(error=>this.toast("Product could not be registered: "+error.message,"error")).finally(()=>{this.registrationBusy=false;this.setState({registrationSaving:false});});
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
  quickPromoFromNearExpiry=productId=>()=>{ this.setState({screen:"mgrPromotions",promoSelectedItems:[productId],promoType:"Near-Expiry",promoStartDate:"2026-07-24",promoEndDate:"2026-07-27",promoDiscountPct:""}); };
  quickPromoFromLeastSales=productId=>()=>{ this.setState({screen:"mgrPromotions",promoSelectedItems:[productId],promoType:"Slow-Moving",promoStartDate:"2026-07-24",promoEndDate:"2026-08-07",promoDiscountPct:""}); };
  copyPromotion=promo=>()=>{ this.setState({promoSelectedItems:[...promo.productIds],promoType:promo.type,promoOccasionName:promo.occasionName||"",promoCategory:promo.category||"",promoDiscountPct:String(promo.discountPct),promoStartDate:"",promoEndDate:""}); this.toast("Promotion copied â€” set new dates and save."); };
  savePromotion=()=>{
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
    const id=Date.now();
    this.setState(s=>({promotionsLocal:[{id,name,productIds,type:promoType,discountPct:pct,startDate:promoStartDate,endDate:promoEndDate,occasionName:promoOccasionName||null,category:promoType==="Category-Wide"?promoCategory:null},...s.promotionsLocal]}));
    this.resetPromoForm();
    this.toast(`Promotion "${name}" saved.`);
  };
  setRepDateMode=e=>this.setState({repDateMode:e.target.value});
  setRepField=field=>e=>this.setState({[field]:e.target.value});
  openItemPicker=()=>this.setState({itemPickerOpen:true,itemPickerSearch:"",itemPickerFilterCategory:"",itemPickerSubcategoryId:"",itemPickerFilterSupplier:"",itemPickerChecked:{},itemPickerLowStockOnly:false});
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
    const existingIds=new Set(this.state.newReqCart.map(l=>l.productId));
    const added=ids.filter(id=>data.PRODUCTS.some(p=>String(p.id)===String(id)&&p.status==="Active")).map(id=>{ const p=data.PRODUCTS.find(pp=>String(pp.id)===String(id));
      return {category:p.category,supplierId:itemPickerFilterSupplier||p.supplierId,productId:p.id,name:this.productLabel(p),qty:1}; }).filter(l=>!existingIds.has(l.productId));
    this.setState(s=>({newReqCart:[...s.newReqCart.map(l=>ids.includes(String(l.productId))?{...l,qty:Number(l.qty)+1}:l),...added],itemPickerOpen:false}));
    this.toast(`${added.length} item(s) added to the request.`);
  };
  setReqCartQty=i=>e=>{ const val=e.target.value; this.setState(s=>({newReqCart:s.newReqCart.map((l,idx)=>idx===i?{...l,qty:val}:l)})); };
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
          React.createElement("div",{key:"tog",style:{display:"flex",gap:6,marginBottom:10}},["cash","ewallet"].map(m=>React.createElement("button",{key:m,onClick:this.setPaymentMethod(m),style:{flex:1,padding:"9px 0",borderRadius:8,fontWeight:700,fontSize:12,cursor:"pointer",background:paymentMethod===m?COLORS.brand:"#fff",color:paymentMethod===m?"#fff":COLORS.text,border:"1px solid "+(paymentMethod===m?COLORS.brand:COLORS.border)}},m==="cash"?"Cash":"E-Wallet"))),
          paymentMethod==="cash"?React.createElement("div",{key:"cash"},[
            React.createElement("input",{key:"i",value:tendered,onChange:this.setTendered,placeholder:"Tendered amount",type:"number",style:{width:"100%",padding:"9px 10px",border:"1px solid "+COLORS.border,borderRadius:8,fontSize:13,marginBottom:6}}),
            tendered?React.createElement("div",{key:"c",style:{fontSize:12,color:COLORS.textSoft}},`Change: ${peso(Math.max(0,(parseFloat(tendered)||0)-totals.grandTotal))}`):null,
          ]):React.createElement("select",{key:"ew",value:ewalletProvider,onChange:this.setEwalletProvider,style:{width:"100%",padding:"9px 10px",border:"1px solid "+COLORS.border,borderRadius:8,marginBottom:6}},["GCash","Maya","GrabPay","QR Ph"].map(p=>React.createElement("option",{key:p,value:p},p))),
          paymentMethod==="ewallet" && ewalletProvider==="QR Ph"?React.createElement("div",{key:"qr-help",style:{fontSize:12,color:COLORS.textSoft,marginBottom:8}},"Open checkout to display a QR for "+peso(totals.grandTotal)+". Scan with GCash or a QR Ph-compatible banking app. Payment is confirmed automatically."):null,
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
    const results=catalog.filter(p=>(!manualCategory||categoryOf(p)===manualCategory)&&(!this.state.manualSubcategoryId||String(p.subcategoryId)===String(this.state.manualSubcategoryId))&&([p.name,(data.BRANDS||[]).find(b=>String(b.id)===String(p.brandId))?.name,p.size,p.sizeUnit,p.barcode,categoryOf(p)].join(" ").toLowerCase().includes(q)));
    return h("div",{key:"mlm",style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50,padding:16}},
      h("div",{role:"dialog","aria-modal":true,"aria-label":"Manual Product Lookup",style:{width:520,maxWidth:"100%",maxHeight:"80vh",background:"#fff",borderRadius:14,display:"flex",flexDirection:"column",overflow:"hidden"}},[
        h("div",{key:"body",style:{minHeight:0,overflowY:"auto",padding:20}},[
        h("div",{key:"title",style:{fontWeight:800,marginBottom:14}},"Manual Product Lookup"),
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
            const available=p.status==="Active"&&p.stock>0;
            return h("button",{key:p.id,type:"button",disabled:!available,onClick:this.addManualProduct(p),style:{width:"100%",background:"transparent",border:0,borderBottom:"1px solid #eef1f6",display:"flex",justifyContent:"space-between",gap:12,textAlign:"left",padding:"12px 8px",cursor:available?"pointer":"not-allowed",opacity:available?1:0.5}},[
              h("span",{key:"details"},[h("span",{key:"name",style:{display:"block",fontWeight:600,fontSize:13}},[p.name,(data.BRANDS||[]).find(b=>String(b.id)===String(p.brandId))?.name,p.size,p.sizeUnit].filter(Boolean).join(" ")),h("span",{key:"stock",style:{display:"block",fontSize:11,color:COLORS.textMuted}},p.status!=="Active"?p.status:p.stock>0?p.stock+" in stock":"Out of stock")]),
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
  buildTransactionView(t){
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
          row("Payment method",t.paymentMode),row("Amount tendered",amount(t.tendered)),
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
      h("button",{key:"new",onClick:this.newSale,className:"primary-button receipt-new-sale"},"Start New Sale"),
    ]);
  }

  buildRefunds(){
    const {data,refundTxn,refundLookup,refundError}=this.state;
    if(!data) return null;
    const field=(key,label,type="text")=>React.createElement("input",{key,type,placeholder:label,"aria-label":label,value:this.state[key]||"",onChange:e=>this.setState({[key]:e.target.value}),maxLength:type==="password"?4:255,inputMode:type==="password"?"numeric":undefined,autoComplete:"off",style:{padding:10,marginBottom:10,width:"100%",boxSizing:"border-box"}});
    return React.createElement("div",null,[
      sectionTitle("Refunds, Exchanges & Voids","Manager approval is required for every action."),
      card([field("refundLookup","Transaction number"),btn("Look Up",this.doRefundLookup,"primary")],{marginBottom:16}),
      refundError?React.createElement("p",{key:"error",role:"alert",style:{color:COLORS.red}},refundError):null,
      refundTxn?card([
        React.createElement("h3",{key:"transaction"},refundTxn.uuid+" ? "+peso(refundTxn.total)+" ? "+refundTxn.status),
        this.state.approvalResult?React.createElement("p",{key:"result"},this.state.approvalResult):null,
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
            React.createElement("label",{key:"restock"},[React.createElement("input",{type:"checkbox",checked:this.state.refundRestock!==false,onChange:e=>this.setState({refundRestock:e.target.checked})}),"Return sellable items to stock"]),
            refundTxn.paymentMode!=="Cash"?field("providerRefundId","Successful PayMongo refund reference"):null,
          ]):null,
          React.createElement("p",{key:"instructions"},"The manager on duty must select their name and personally enter their approval PIN at this counter."),
          React.createElement("select",{key:"manager","aria-label":"Approving manager",value:this.state.approvalManagerId||"",onChange:e=>this.setState({approvalManagerId:e.target.value,approvalPin:""}),style:{padding:10,marginBottom:10,width:"100%"}},[
            React.createElement("option",{key:"empty",value:""},"Select approving manager"),
            ...(data.USERS?.manager||[]).filter(m=>m.status==="Active").map(manager=>React.createElement("option",{key:manager.id,value:manager.id},manager.name)),
          ]),
          field("approvalPin","Manager 4-digit PIN","password"),
          this.state.refundAction==="exchange"?React.createElement("select",{key:"replacement","aria-label":"Exchange replacement",value:this.state.exchangeReplacement||"",onChange:this.setExchangeReplacement,style:{padding:10,marginBottom:16,width:"100%"}},[
            React.createElement("option",{key:"empty",value:""},"Select exchange replacement"),
            ...data.PRODUCTS.filter(p=>p.status==="Active"&&p.stock>0).map(p=>React.createElement("option",{key:p.id,value:p.id},p.name+" - "+peso(p.price))),
          ]):null,
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
      sectionTitle("My Shift Transactions",`${me.name} ? Today, ${today||""}`),
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
  buildMgrDashboard(){
    const {data,itemRequestsLocal}=this.state; if(!data) return null;
    const pending=itemRequestsLocal.filter(r=>["Pending","Pending Approval"].includes(r.status)).length;
    const forwarded=itemRequestsLocal.filter(r=>["Approved","Approved / Waiting for Delivery"].includes(r.status)).length;
    const proceedPurchase=itemRequestsLocal.filter(r=>r.status==="Proceed to Purchase").length;
    const disapproved=itemRequestsLocal.filter(r=>["Disapproved","Declined"].includes(r.status));
    const byProduct={}; data.SALES_LOG.forEach(t=>{ byProduct[t.productId]=(byProduct[t.productId]||0)+t.qty*t.amount; });
    const ranked=Object.entries(byProduct).map(([id,rev])=>({p:data.PRODUCTS.find(p=>p.id===Number(id)),rev})).filter(x=>x.p).sort((a,b)=>b.rev-a.rev);
    const lowStock=this.managerLowStock();
    return React.createElement("div",null,[
      sectionTitle("Manager Dashboard","Item requests, sales & inventory overview"),
      this.buildManagerPin(),
      React.createElement("div",{style:{display:"flex",gap:14,marginBottom:16,flexWrap:"wrap"}},[
        kpi("Item Requests (Pending)",pending,null,COLORS.amber), kpi("Forwarded Purchase Requests",forwarded,null,COLORS.purple),
        kpi("For Purchased Request",proceedPurchase,null,COLORS.amber), kpi("Disapproved Requests",disapproved.length,null,COLORS.red),
        kpi("Low Stock Items",lowStock.length,null,COLORS.amber),
      ]),
      card([
        React.createElement('h2',{key:'title',className:'panel-title'},'Low Stock Products'),
        React.createElement('p',{key:'description'},'Products at or below minimum stock. Purchase opens a request for review and approval.'),
        lowStock.length?this.recordTable('manager-low-stock',['Product','Category','Stock on hand','Minimum stock','Unit','Action'],lowStock.map(p=>tr([
          td(p.name),td(p.category),td(p.stock),td(p.minStock),td(p.stockUnit||p.unit||'Not recorded'),
          td(btn('Purchase',this.purchaseLowStock(p.id),'primary'))
        ],p.id))):React.createElement('p',{key:'empty',role:'status'},'No low-stock products.')
      ],{marginBottom:16}),
      disapproved.length?card([React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:8,color:COLORS.red}},"Disapproved â€” reason visible"),
        ...disapproved.map(r=>React.createElement("div",{key:r.id,style:{fontSize:13,padding:"6px 0",borderBottom:"1px solid #eef1f6"}},[React.createElement("b",{key:"i"},r.id+": "),r.lines.map(l=>l.name).join(", ")+" â€” "+r.disapprovalReason]))],{marginBottom:16}):null,
      React.createElement("div",{style:{display:"grid",gridTemplateColumns:"repeat(3,minmax(0,1fr))",gap:14}},[
        card([React.createElement("div",{key:"t",className:"panel-title"},"Best Sellers"),rankedSales(ranked.slice(0,5))]),
        card([React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"Least Sales"),...ranked.slice(-5).reverse().map((x,i)=>React.createElement("div",{key:i,style:{display:"flex",justifyContent:"space-between",alignItems:"center",fontSize:13,padding:"6px 0",borderBottom:"1px solid #eef1f6"}},[React.createElement("span",null,x.p.name),React.createElement("div",{style:{display:"flex",alignItems:"center",gap:8}},[React.createElement("span",{style:{fontWeight:700,color:COLORS.textMuted}},peso(x.rev)),React.createElement("a",{onClick:this.quickPromoFromLeastSales(x.p.id),style:{fontSize:11,fontWeight:700,cursor:"pointer"}},"Promo â†’")])]))]),
        this.buildNearExpiredWidget(true),
      ]),
    ]);
  }
  buildNearExpiredWidget(withLink){
    const {data}=this.state;
    const soon=data.PRODUCTS.filter(p=>p.expiry).map(p=>({p,days:Math.round((new Date(p.expiry)-new Date("2026-07-24"))/86400000)})).sort((a,b)=>a.days-b.days).slice(0,5);
    return card([
      React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"Near-Expired Products"),
      soon.length===0?React.createElement("div",{key:"e",style:{fontSize:13,color:COLORS.textMuted}},"Nothing near expiry."):
      soon.map((x,i)=>React.createElement("div",{key:i,style:{padding:"6px 0",borderBottom:"1px solid #eef1f6",fontSize:13}},[
        React.createElement("div",{key:"row",style:{display:"flex",justifyContent:"space-between",alignItems:"center"}},[
          React.createElement("div",{key:"n",style:{fontWeight:600}},x.p.name),
          this.state.role==="manager"?React.createElement("a",{key:"cp",onClick:this.quickPromoFromNearExpiry(x.p.id),style:{fontSize:11,fontWeight:700,cursor:"pointer"}},"Create Promo â†’"):null,
        ]),
        React.createElement("div",{key:"m",style:{fontSize:11,color:COLORS.textMuted}},`Batch ${x.p.batch} Â· Lot ${x.p.lot} Â· Qty ${x.p.stock}`),
        React.createElement("span",{key:"d",style:{fontSize:11,fontWeight:700,color:x.days<=1?COLORS.red:x.days<=7?COLORS.amber:COLORS.textSoft}},x.days+" day(s) left"),
      ])),
      withLink?React.createElement("a",{key:"link",onClick:()=>{ this.setState({screen:"mgrInventory",invTab:"expiry"}); },style:{display:"block",marginTop:10,fontSize:12,fontWeight:700,cursor:"pointer"}},"View Near-Expiry Monitoring â†’"):null,
    ]);
  }
  reloadPurchasing=async()=>{
    this.setState({purchaseLoading:true,purchaseError:""});
    try{
      const response=await fetch('/api/purchasing',{headers:{Accept:'application/json'},credentials:'same-origin'});
      const body=await response.json();this.handleSessionResponse(response,body);
      if(!response.ok)throw new Error(body.message||'Unable to load purchasing records.');
      this.setState({purchaseData:body});return body;
    }catch(error){this.setState({purchaseError:error.message});throw error;}
    finally{this.setState({purchaseLoading:false});}
  };
  purchasingState(){
    const h=React.createElement;
    if(this.state.purchaseError)return h('div',{role:'alert',className:'alert'},[this.state.purchaseError,btn('Retry',()=>this.reloadPurchasing().catch(()=>{}))]);
    if(!this.state.purchaseData)return h('p',{role:'status'},[loadingStatus('Loading purchase records...'),btn('Load records',()=>this.reloadPurchasing().catch(()=>{}))]);
    return null;
  }
  purchaseReportLink=id=>React.createElement('a',{className:'kita-button',href:`/purchase-orders/${encodeURIComponent(id)}/report`,target:'_blank',rel:'noopener'},'View / Print Report');
  purchaseSupplier=id=>(this.state.data?.SUPPLIERS||[]).find(s=>String(s.id)===String(id))?.name||'Not recorded';
  purchaseUnit=line=>line.unit||(this.state.data?.PRODUCTS||[]).find(p=>p.id===line.productId)?.stockUnit||(this.state.data?.PRODUCTS||[]).find(p=>p.id===line.productId)?.unit||'Not recorded';
  purchaseFilters(){
    const h=React.createElement;
    return h('div',{className:'purchase-filters'},[
      h('label',null,['Supplier',h('select',{value:this.state.purchaseSupplierFilter||'',onChange:e=>this.setState({purchaseSupplierFilter:e.target.value})},[h('option',{value:''},'All suppliers'),...(this.state.data?.SUPPLIERS||[]).map(s=>h('option',{key:s.id,value:s.id},s.name))])]),
      h('label',null,['Status',h('select',{value:this.state.purchaseStatusFilter||'',onChange:e=>this.setState({purchaseStatusFilter:e.target.value})},['','Pending Approval','Approved / Waiting for Delivery','Declined','Partially Received','Fully Received','Received with Discrepancy','Pending','Approved','Draft','Closed'].map(s=>h('option',{key:s,value:s},s||'All statuses')))]),
      h('label',null,['Request date from',h('input',{type:'date',value:this.state.purchaseFrom||'',onChange:e=>this.setState({purchaseFrom:e.target.value})})]),
      h('label',null,['Request date to',h('input',{type:'date',value:this.state.purchaseTo||'',onChange:e=>this.setState({purchaseTo:e.target.value})})]),
      btn('Clear filters',()=>this.setState({purchaseSupplierFilter:'',purchaseStatusFilter:'',purchaseFrom:'',purchaseTo:''})),
      btn(this.state.purchaseLoading?'Refreshing...':'Refresh',()=>this.reloadPurchasing().catch(()=>{}))
    ]);
  }
  filterPurchases=records=>records.filter(r=>{
    const supplier=r.supplierId??r.lines?.[0]?.supplierId,date=r.request?.dateRequested||r.dateRequested||r.created||'';
    return (!this.state.purchaseSupplierFilter||String(supplier)===this.state.purchaseSupplierFilter)&&(!this.state.purchaseStatusFilter||r.status===this.state.purchaseStatusFilter)&&(!this.state.purchaseFrom||date>=this.state.purchaseFrom)&&(!this.state.purchaseTo||date<=this.state.purchaseTo);
  });
  submitPurchase=async()=>{
    if(this.purchaseBusy)return;
    const lines=this.state.newReqCart.map(l=>({productId:l.productId,qty:Number(l.qty),supplierId:Number(this.state.newReqSupplier||l.supplierId)}));
    if(!lines.length||lines.some(l=>!l.supplierId||!Number.isInteger(l.qty)||l.qty<=0)||new Set(lines.map(l=>l.supplierId)).size!==1){this.toast('Add positive whole quantities and select one supplier for this PO.','error');return;}
    this.purchaseBusy=true;
    // The database assigns the PO number; retain a separate retry key after network errors.
    this.purchaseSubmissionKey=this.purchaseSubmissionKey||crypto.randomUUID();
    try{
      const result=await this.authPost('/api/purchase-requests',{submissionKey:this.purchaseSubmissionKey,category:this.state.newReqCart[0].category,notes:this.state.purchaseNotes||'',lines});
      this.purchaseSubmissionKey=null;this.setState({newReqCart:[],purchaseNotes:'',screen:'mgrRequestView'});this.toast(result.message+' '+result.id);
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
      return h('div',null,[sectionTitle('View Purchase Requests','One PO ID follows each request through approval and receiving.'),this.purchaseFilters(),
        this.recordTable('purchase-requests',['PO ID','Requested','Supplier','Items','Status','Admin notes / decline reason','Actions'],this.filterPurchases(s.purchaseData.requests).map(r=>tr([td(r.id),td(r.requested_at||r.dateRequested),td(r.supplierName||this.purchaseSupplier(r.lines[0]?.supplierId)),td(r.lines.length),td(badge(r.status)),td(r.disapprovalReason||r.adminNote||''),td(btn('View',()=>this.setState({purchaseRequestDetail:r.id})))],r.id))),
        this.purchaseRequestDetails()
      ]);
    }
    if(!s.data)return h('p',null,loadingStatus('Loading inventory and suppliers...'));
    return h('div',null,[sectionTitle('Create Purchase Request','Choose one supplier per PO. Quantities are in inventory units; stock changes only after receiving.'),
      h('div',{className:'purchase-filters'},[h('label',null,['Supplier',h('select',{value:s.newReqSupplier||'',onChange:e=>this.setState({newReqSupplier:e.target.value})},[h('option',{value:''},'Select supplier'),...s.data.SUPPLIERS.filter(sp=>sp.status==='Active'&&!sp.archivedAt).map(sp=>h('option',{key:sp.id,value:sp.id},sp.name))])]),btn('+ Add Items',this.openItemPicker,'primary')]),

      this.recordTable('purchase-cart',['Item','Category','Quantity','Unit','Action'],s.newReqCart.map((l,i)=>tr([td(l.name),td(l.category),td(h('input',{'aria-label':'Quantity for '+l.name,type:'number',min:1,step:1,value:l.qty,onChange:this.setReqCartQty(i)})),td(this.purchaseUnit(l)),td(btn('Remove',this.removeReqLine(i)))],i))),
      h('label',{className:'purchase-notes'},['Purchase notes',h('textarea',{value:s.purchaseNotes||'',maxLength:2000,onChange:e=>this.setState({purchaseNotes:e.target.value})})]),
      btn('Submit Purchase Request',this.submitPurchase,'primary'),
      s.itemPickerOpen?this.buildItemPickerModal():null,s.categoryModalOpen?this.buildCategoryModal():null
    ]);
  }
  purchaseRequestDetails(){
    const h=React.createElement,r=this.state.purchaseData?.requests.find(r=>r.id===this.state.purchaseRequestDetail);if(!r)return null;
    return h('section',{className:'sa-panel'},[h('h2',null,r.id),h('p',null,`${r.supplierName||this.purchaseSupplier(r.lines[0]?.supplierId)} | ${r.status} | Requested by ${r.requestedBy}`),
      table(['Item','Category','Requested','Reviewed quantity','Unit'],r.lines.map(l=>tr([td(l.name),td(l.category),td(l.qty),td(l.confirmedQty??l.qty),td(this.purchaseUnit(l)),...['receiveCosts','receiveBatches','receiveExpiries'].map((field,i)=>td(h('input',{'aria-label':['Actual unit cost','Batch number','Expiry date'][i]+' for '+l.name,type:i===0?'number':i===2?'date':'text',min:0,step:'0.01',value:this.state[field]?.[l.productId]??(i===0?l.unitCost:''),onChange:e=>this.setState(s=>({[field]:{...s[field],[l.productId]:e.target.value}}))})))],l.id))),h('p',null,r.notes||''),h('p',null,r.disapprovalReason||r.adminNote||''),btn('Close details',()=>this.setState({purchaseRequestDetail:null})),r.poId?this.purchaseReportLink(r.poId):null]);
  }
  reviewPurchase=async(record,action)=>{
    if(this.reviewBusy)return;
    const note=this.state.approvalNoteDraft[record.id]||'';
    if(action==='disapproved'&&!note.trim()){this.toast('Enter a decline reason.','error');return;}
    const lines=(this.state.purchaseReviewId===record.id?this.state.purchaseReviewLines:record.lines).map(l=>({productId:l.productId,qty:Number(l.confirmedQty??l.qty)}));
    const edited=lines.length!==record.lines.length||lines.some(l=>{const original=record.lines.find(p=>p.productId===l.productId);return !original||Number(original.confirmedQty??original.qty)!==l.qty;});
    if(action!=='disapproved'&&edited&&!note.trim()){this.toast('Enter a reason for changing purchase quantities or removing items.','error');return;}
    if(action!=='disapproved'&&(!lines.length||lines.some(l=>!Number.isInteger(l.qty)||l.qty<=0))){this.toast('Keep at least one item with positive whole quantities.','error');return;}
    this.reviewBusy=true;
    try{const result=await this.authPost(`/api/purchase-requests/${encodeURIComponent(record.id)}`,{action,note,revision:record.revision,...(action==='disapproved'?{}:{lines})},'PATCH');this.setState({purchaseReviewId:null});this.toast(result.message);await Promise.all([this.reloadPurchasing(),this.reloadCatalog()]);}
    catch(error){this.toast(error.message,'error');}finally{this.reviewBusy=false;}
  };
  buildPurchaseReview(){
    const h=React.createElement,state=this.purchasingState();if(state)return state;
    const requests=this.filterPurchases(this.state.purchaseData.requests).filter(r=>['Pending','Pending Approval','Proceed to Purchase'].includes(r.status));
    return h('div',null,[sectionTitle('Purchase Request Review','Review, edit, approve or decline the original request. Approval does not add stock.'),this.purchaseFilters(),
      ...requests.map(r=>h('section',{key:r.id,className:'sa-panel purchase-review'},[
        h('h2',null,r.id),h('p',null,`${r.supplierName||this.purchaseSupplier(r.lines[0]?.supplierId)} | ${r.requestedBy} | ${r.requested_at||r.dateRequested}`),h('p',null,r.notes||''),
        table(['Item','Category','Requested','Review quantity','Unit','Actions'],(this.state.purchaseReviewId===r.id?this.state.purchaseReviewLines:r.lines).map((l,i)=>tr([td(l.name),td(l.category),td(l.qty),td(this.state.purchaseReviewId===r.id?h('input',{'aria-label':'Review quantity for '+l.name,type:'number',min:1,step:1,value:l.confirmedQty??l.qty,onChange:e=>this.setState(s=>({purchaseReviewLines:s.purchaseReviewLines.map((line,n)=>n===i?{...line,confirmedQty:e.target.value}:line)}))}):l.confirmedQty??l.qty),td(this.purchaseUnit(l)),td(this.state.purchaseReviewId===r.id?btn('Remove',()=>this.setState(s=>({purchaseReviewLines:s.purchaseReviewLines.filter((_,n)=>n!==i)}))):'')],l.id))),
        h('label',{className:'purchase-notes'},['Review reason (required when changing quantities, removing items, or declining)',h('textarea',{maxLength:2000,placeholder:'Explain why this request needs to change',value:this.state.approvalNoteDraft[r.id]||'',onChange:this.setApprovalNote(r.id)})]),
        h('div',{className:'purchase-actions'},[btn('Edit items',()=>this.setState({purchaseReviewId:r.id,purchaseReviewLines:r.lines.map(l=>({...l}))})),this.state.purchaseReviewId===r.id?btn('Save edits',()=>this.reviewPurchase(r,'modify')):null,btn('Approve',()=>this.reviewPurchase(r,'approved'),'primary'),btn('Decline',()=>this.reviewPurchase(r,'disapproved'),'danger')])
      ])),!requests.length?h('p',{className:'record-empty'},'No pending requests match these filters.'):null,this.purchaseRequestDetails()
    ]);
  }
  openPurchaseReceiving=order=>this.setState({purchaseReceivingId:order.id,receiveQty:{},receiveCosts:{},receiveBatches:{},receiveExpiries:{},receiveNotes:'',receiveReference:'',receiveDate:this.state.data?.BUSINESS_DATE||new Date().toISOString().slice(0,10),receiveKey:crypto.randomUUID(),receiveVersion:order.receivingVersion,receiveError:''});
  confirmPurchaseReceiving=async()=>{
    if(this.receivingBusy)return;
    const order=this.state.purchaseData.orders.find(o=>o.id===this.state.purchaseReceivingId);
    const entered=Object.entries(this.state.receiveQty||{}).filter(([,qty])=>String(qty).trim()!=='');
    if(!entered.length||entered.some(([,qty])=>!Number.isInteger(Number(qty))||Number(qty)<=0)){this.setState({receiveError:'Enter positive whole quantities for delivered items. Leave undelivered items blank.'});return;}
    if(!window.confirm('Confirm actual delivered quantities for '+order.id+'? Inventory will be updated immediately.'))return;
    this.receivingBusy=true;this.setState({receivingSaving:true,receiveError:''});
    try{
      const result=await this.authPost(`/api/purchase-orders/${encodeURIComponent(order.id)}/receive`,{idempotencyKey:this.state.receiveKey,version:this.state.receiveVersion,receivedDate:this.state.receiveDate,notes:this.state.receiveNotes,lines:entered.map(([productId,qty])=>({productId:Number(productId),qty:Number(qty),unitCost:this.state.receiveCosts?.[productId]??order.lines.find(l=>String(l.productId)===productId).unitCost,batchNumber:this.state.receiveBatches?.[productId]||null,expiryDate:this.state.receiveExpiries?.[productId]||null}))});
      this.setState({purchaseReceivingId:null,lastReceivedPo:order.id});this.toast(result.message);await Promise.all([this.reloadPurchasing(),this.reloadCatalog()]);
    }catch(error){this.setState({receiveError:error.message});}finally{this.receivingBusy=false;this.setState({receivingSaving:false});}
  };
  buildPurchaseReceiving(){
    const h=React.createElement,state=this.purchasingState();if(state)return state;
    const order=this.state.purchaseData.orders.find(o=>o.id===this.state.purchaseReceivingId);
    if(order)return h('div',null,[sectionTitle('Receive '+order.id,`${order.supplierName||this.purchaseSupplier(order.supplierId)} | ${order.status}`),
      h('p',null,'Inspect and enter actual delivered quantities. Received items will be added to inventory immediately. Missing items are saved in a shortage report. Leave undelivered items blank.'),
      table(['Item','Category','Ordered','Previously received','Received order','Missing','Excess','Unit','Actual unit cost','Batch','Expiry'],order.lines.map(l=>tr([td(l.name),td(l.category),td(l.orderedQty),td(l.deliveredQty||0),td(h('input',{'aria-label':'Receive '+l.name,type:'number',min:1,step:1,value:this.state.receiveQty[l.productId]??'',onChange:e=>this.setState(s=>({receiveQty:{...s.receiveQty,[l.productId]:e.target.value}}))})),td(Math.max(0,Number(l.orderedQty)-Number(l.deliveredQty||0)-Number(this.state.receiveQty[l.productId]||0))),td(Math.max(0,Number(l.deliveredQty||0)+Number(this.state.receiveQty[l.productId]||0)-Number(l.orderedQty))),td(this.purchaseUnit(l))],l.id))),
      h('div',{className:'purchase-filters'},[h('label',null,['Delivery reference',h('input',{readOnly:true,value:'Assigned automatically from '+order.id,'aria-label':'Automatic delivery reference'})]),h('label',null,['Date received',h('input',{type:'date',max:this.state.data?.BUSINESS_DATE,value:this.state.receiveDate,onChange:e=>this.setState({receiveDate:e.target.value})})])]),
      h('label',{className:'purchase-notes'},['Receiving notes (required for excess delivery)',h('textarea',{maxLength:2000,value:this.state.receiveNotes,onChange:e=>this.setState({receiveNotes:e.target.value})})]),
      this.state.receiveError?h('p',{role:'alert',className:'alert'},this.state.receiveError):null,
      h('button',{className:'primary-button',disabled:!!this.state.receivingSaving,"aria-busy":!!this.state.receivingSaving,onClick:this.confirmPurchaseReceiving},this.state.receivingSaving?'Posting delivery...':'Confirm Receipt'),btn('Back',()=>this.setState({purchaseReceivingId:null})),this.purchaseReportLink(order.id)
    ]);
    return h('div',null,[sectionTitle('Stock Receiving','Open an approved PO after delivery. Inventory posts once for each confirmed delivery.'),this.state.lastReceivedPo?h('div',{className:'sa-panel',role:'status'},['Delivery saved. View the receiving inspection and any missing-item report: ',this.purchaseReportLink(this.state.lastReceivedPo)]):null,this.purchaseFilters(),
      this.recordTable('purchase-receiving',['PO ID','Supplier','Items','Status','Actions'],this.filterPurchases(this.state.purchaseData.orders).map(o=>tr([td(o.id),td(o.supplierName||this.purchaseSupplier(o.supplierId)),td(o.lines.map(l=>`${l.name}: ${l.deliveredQty||0} / ${l.orderedQty} ${this.purchaseUnit(l)}`).join(', ')),td(badge(o.status)),td([(['Approved / Waiting for Delivery','Partially Received','Approved','Ordered'].includes(o.status)||(o.status==='Draft'&&o.request?.status==='Approved'))?btn('Receive Stock',()=>this.openPurchaseReceiving(o),'primary'):null,this.purchaseReportLink(o.id)])],o.id)))
    ]);
  }
  buildPurchaseHistory(){
    const h=React.createElement,state=this.purchasingState();if(state)return state;
    const orders=this.filterPurchases(this.state.purchaseData.orders).filter(o=>o.receipts.length>0);
    return h('div',null,[sectionTitle('Purchase Transaction History','Persisted receiving transactions, including partial deliveries. Open the report for delivery details and signatures.'),this.purchaseFilters(),
      this.recordTable('purchase-history',['PO ID','Supplier','Items / ordered / received / unit','Request date','Approval date','Received date','Status','Requested by','Received by','Approving Admin','Report'],orders.map(o=>tr([td(o.id),td(o.supplierName||this.purchaseSupplier(o.supplierId)),td(o.lines.map(l=>`${l.name}: ${l.orderedQty} / ${l.deliveredQty||0} ${this.purchaseUnit(l)}`).join('; ')),td(o.request?.requested_at||o.request?.dateRequested||'Not recorded'),td(o.request?.approved_at||'Not recorded'),td(o.receipts.map(r=>r.date).join(', ')),td(badge(o.status)),td(o.request?.requestedBy||'Not recorded'),td([...new Set(o.receipts.map(r=>r.receivedBy||'Not recorded'))].join(', ')),td(o.request?.approvedBy||'Not recorded'),td(this.purchaseReportLink(o.id))],o.id)))
    ]);
  }
  buildPurchaseStatusList(declined=false){
    const h=React.createElement,state=this.purchasingState();if(state)return state;
    const records=this.filterPurchases(this.state.purchaseData.requests).filter(r=>declined?['Declined','Disapproved'].includes(r.status):!!r.poId);
    return h('div',null,[sectionTitle(declined?'Declined Requests':'Approved Purchase Orders'),this.purchaseFilters(),this.recordTable('purchase-status',['PO ID','Supplier','Status','Admin / reason','Actions'],records.map(r=>tr([td(r.poId||r.id),td(r.supplierName||this.purchaseSupplier(r.lines[0]?.supplierId)),td(badge(r.status)),td(r.disapprovalReason||r.adminNote||r.approvedBy),td([btn('View',()=>this.setState({purchaseRequestDetail:r.id})),r.poId?this.purchaseReportLink(r.poId):null])],r.id))),this.purchaseRequestDetails()]);
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
      (!itemPickerFilterSupplier||String(p.supplierId)===String(itemPickerFilterSupplier)) &&
      (!itemPickerLowStockOnly||isLow(p)) &&
      (!q||(this.productLabel(p)+" "+p.barcode).toLowerCase().includes(q)));
    items = [...items].sort((a,b)=>(isLow(b)?1:0)-(isLow(a)?1:0));
    const count=Object.values(itemPickerChecked).filter(Boolean).length;
    return React.createElement("div",{style:{position:"fixed",inset:0,background:"rgba(15,31,74,0.38)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:50,padding:20}},
      React.createElement("div",{style:{width:560,maxWidth:"92vw",maxHeight:"82vh",background:"#fff",borderRadius:14,padding:20,display:"flex",flexDirection:"column",boxSizing:"border-box",boxShadow:"0 20px 60px rgba(6,25,20,0.25)"}},[
        React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"Add Items"),
        React.createElement("div",{key:"new-product",style:{padding:12,marginBottom:12,background:COLORS.brandBg,borderRadius:8}},[
          React.createElement("p",{style:{margin:"0 0 8px",fontSize:13,color:COLORS.textSoft}},"Purchasing a product that is not in inventory yet?"),
          btn("Register Product First",()=>{this.setState({itemPickerOpen:false,regEditing:null,regStockId:""});this.goScreen("mgrRegistration")();},"primary")
        ]),
        lowStockCount>0?React.createElement("div",{key:"banner",style:{background:COLORS.redBg,color:COLORS.red,padding:"8px 12px",borderRadius:8,fontSize:12,fontWeight:700,marginBottom:10}},`âš  ${lowStockCount} item(s) are currently low on stock â€” consider adding them to this request.`):null,
        React.createElement("div",{key:"filters",style:{display:"grid",gridTemplateColumns:"repeat(3,minmax(0,1fr))",gap:8,marginBottom:8,minWidth:0}},[
          React.createElement("input",{key:"s",value:itemPickerSearch,onChange:this.setItemPickerSearch,placeholder:"Searchâ€¦",style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7}}),
          React.createElement("select",{key:"c",value:itemPickerFilterCategory,onChange:this.setItemPickerFilterCategory,style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7}},[React.createElement("option",{key:"-",value:""},"All Categories"),...data.CATEGORIES.filter(c=>c.status==="Active").map(c=>React.createElement("option",{key:c.name,value:c.name},c.name))]),
          this.buildSubcategorySelect(itemPickerFilterCategory,this.state.itemPickerSubcategoryId,e=>this.setState({itemPickerSubcategoryId:e.target.value})),
          React.createElement("select",{key:"sp",value:itemPickerFilterSupplier,onChange:this.setItemPickerFilterSupplier,style:{padding:8,border:"1px solid "+COLORS.border,borderRadius:7,minWidth:0,width:"100%"}},[React.createElement("option",{key:"-",value:""},"All Suppliers"),...data.SUPPLIERS.filter(sp=>sp.status==="Active").map(sp=>React.createElement("option",{key:sp.id,value:sp.id},sp.name))]),
        ]),
        React.createElement("label",{key:"lowtoggle",style:{display:"flex",alignItems:"center",gap:6,fontSize:12,fontWeight:700,color:COLORS.red,marginBottom:10,cursor:"pointer"}},[React.createElement("input",{key:"cb",type:"checkbox",checked:itemPickerLowStockOnly,onChange:this.toggleItemPickerLowStockOnly}),"Low Stock Only"]),
        React.createElement("div",{key:"list",style:{flex:1,overflowY:"auto",border:"1px solid "+COLORS.border,borderRadius:8}},
          items.length===0?React.createElement("div",{style:{padding:20,textAlign:"center",color:COLORS.textMuted,fontSize:13}},"No existing items match. Register the product first above to add a new item to your request."):
          items.map(p=>{ const low=isLow(p);
            return React.createElement("label",{key:p.id,style:{display:"flex",alignItems:"center",gap:10,padding:"9px 12px",borderBottom:"1px solid #eef1f6",cursor:"pointer",background:low?COLORS.redBg:"transparent"}},[
              React.createElement("input",{key:"cb",type:"checkbox",checked:!!itemPickerChecked[p.id],onChange:this.toggleItemPickerCheck(p.id)}),
              React.createElement("div",{key:"n",style:{flex:1}},[
                React.createElement("div",{key:"nm",style:{fontSize:13,fontWeight:600,color:low?COLORS.red:COLORS.text}},[low?"âš  ":"",p.name," â€” ",React.createElement("span",{key:"s",style:{color:low?COLORS.red:COLORS.textSoft,fontWeight:700}},p.stock+" left"),` (min: ${p.minStock})`]),
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
  buildMgrInventory(){
    if(this.state.inventoryHistoryOpen)return this.buildInventoryHistory();
    const {data,invTab,adjustmentsLocal}=this.state; if(!data) return null;
    const tabs=[["adjustments","Adjustments"],["writeoffs","Write-Offs / Disposal / Transfers"],["recall","Batch Recall"],["reconciliation","Reconciliation & Counts"],["expiry","Near-Expiry Monitoring"],["table","Inventory Table"],["categories","Categories"]];
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
            btn("Submit Adjustment",this.submitAdjustment,"primary"),
          ]),
        ],{marginBottom:16}),
        this.recordTable("adjustments",["Ref","Product","Qty Î”","Reason","Remarks","Evidence","Status"],adjustmentsLocal.map((a,i)=>{ const p=data.PRODUCTS.find(pp=>pp.id===a.productId);
          return tr([td(a.id,{fontFamily:"'JetBrains Mono',monospace"}),td(p?p.name:"â€”"),td(a.qtyChange,{color:COLORS.red,fontWeight:700}),td(a.reason),td(a.remarks,{fontSize:12,color:COLORS.textSoft}),td(a.photo?"ðŸ“· attached":"â€”"),td(badge(a.status))],i); })),
      ]);
    } else if(invTab==="writeoffs"){
      body=table(["Product","Type","Qty","Date"],[
        tr([td("Frozen Siomai 500g"),td(badge("Damaged")),td(-38),td("2026-07-18")],1),
        tr([td("Frozen Siomai 500g"),td("Transfer: Quarantine"),td(-38),td("2026-07-16")],2),
      ]);
    } else if(invTab==="recall"){
      body=card([React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:8}},`Batch ${data.BATCH_RECALL.batch} Â· Lot ${data.BATCH_RECALL.lot} Â· ${data.BATCH_RECALL.product}`),
        table(["Location","Qty Affected"],data.BATCH_RECALL.affected.map((a,i)=>tr([td(a.location+(a.note?" â€” "+a.note:"")),td(a.qty)],i))),
        React.createElement("button",{key:"b",style:{marginTop:12,padding:"10px 16px",background:COLORS.red,color:"#fff",border:"none",borderRadius:8,fontWeight:700,cursor:"pointer"}},"Flag All as Non-Sellable")]);
    } else if(invTab==="reconciliation"){
      body=React.createElement("div",null,[React.createElement("div",{style:{display:"flex",gap:10,marginBottom:12}},["Cycle","Blind","Full"].map(t=>React.createElement("span",{key:t,style:{padding:"6px 12px",border:"1px solid "+COLORS.border,borderRadius:20,fontSize:12,fontWeight:700}},t))),
        card([React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:8}},"Count in Progress: Personal Care Aisle (locked)"),
          React.createElement("div",{key:"v",style:{background:COLORS.amberBg,color:COLORS.amber,padding:"10px 12px",borderRadius:8,fontSize:13,fontWeight:700}},"Variance of 8 units above threshold â€” flagged for recount/investigation."),
          React.createElement("div",{key:"n",style:{marginTop:10,fontSize:12,color:COLORS.textSoft}},"Sales during active count are logged separately and auto-reconciled at close.")])]);
    } else if(invTab==="expiry"){
      const soon=data.PRODUCTS.filter(p=>p.expiry);
      body=this.recordTable("expiry",["Product","Expiry Date","Days Left"],soon.map((p,i)=>{ const days=Math.round((new Date(p.expiry)-new Date("2026-07-24"))/86400000); return tr([td(p.name),td(p.expiry),td(days<=1?React.createElement("span",{style:{color:COLORS.red,fontWeight:700}},days+" day(s)"):days<=7?React.createElement("span",{style:{color:COLORS.amber,fontWeight:700}},days+" days"):days+" days")],i); }));
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
      const products = this.state.productsLocal || data.PRODUCTS;
      const {invSelected}=this.state; const invCount=Object.values(invSelected).filter(Boolean).length;
      const activeProducts = products.filter(p=>p.status!=="Inactive");
      const visibleProducts=activeProducts.filter(p=>(!this.state.inventoryCategory||p.category===this.state.inventoryCategory)&&(!this.state.inventorySubcategoryId||String(p.subcategoryId)===String(this.state.inventorySubcategoryId)));
      const unlinked = activeProducts.filter(p=>!this.state.suppliersLocal.some(sp=>sp.products.some(pr=>pr.productId===p.id)));
      body=React.createElement("div",null,[
        unlinked.length?React.createElement("div",{key:"warn",style:{marginBottom:12,background:COLORS.amberBg,color:COLORS.amber,padding:"10px 12px",borderRadius:8,fontSize:12,fontWeight:600}},`âš  ${unlinked.length} product(s) not yet linked to any supplier â€” un-orderable via Create Request: ${unlinked.map(p=>p.name).join(", ")}`):null,
        invCount>0?React.createElement("div",{key:"bar",style:{marginBottom:10}},btn(`Move to Archive (${invCount})`,this.moveInvSelectedToArchive,"danger")):null,
        React.createElement('div',{className:'purchase-filters'},[
          React.createElement('label',null,['Category',React.createElement('select',{value:this.state.inventoryCategory||'',onChange:e=>this.setState({inventoryCategory:e.target.value,inventorySubcategoryId:''})},[React.createElement('option',{value:''},'All categories'),...data.CATEGORIES.map(c=>React.createElement('option',{key:c.name,value:c.name},c.name))])]),
          this.buildSubcategorySelect(this.state.inventoryCategory,this.state.inventorySubcategoryId,e=>this.setState({inventorySubcategoryId:e.target.value}),{includeInactive:true})
        ]),
        this.recordTable("inventory",["","Product","Stock","Min Stock","Brand","Subcategory","Size / Unit","Estimated Cost","Retail Price","Status","Category","Actions"],visibleProducts.map((p,i)=>{ const low=p.stock<=p.minStock;
        return tr([td(React.createElement("input",{type:"checkbox",checked:!!invSelected[p.id],onChange:this.toggleInvSelect(p.id)})),
          td(p.name,{color:low?COLORS.red:undefined,fontWeight:low?700:400}),td(p.stock,{color:low?COLORS.red:undefined,fontWeight:low?700:400}),td(p.minStock),
          td((data.BRANDS||[]).find(b=>String(b.id)===String(p.brandId))?.name||"-"),td((data.SUBCATEGORIES||[]).find(b=>String(b.id)===String(p.subcategoryId))?.name||"-"),td((p.size||"")+" "+(p.sizeUnit||"")+" / "+(p.stockUnit||p.unit)),
          td(React.createElement("span",{onClick:this.setPriceEditTarget(p.id,"unitPrice"),style:{cursor:"pointer",borderBottom:"1px dashed "+COLORS.textMuted}},peso(p.unitPrice))),
          td(React.createElement("span",{onClick:this.setPriceEditTarget(p.id,"price"),style:{cursor:"pointer",borderBottom:"1px dashed "+COLORS.textMuted}},peso(p.price))),
          td(badge(p.status)),td(p.category),
          td(btn("Edit Product",()=>this.editProductMaster(p)))],i); })),
      ]);
    }
    return React.createElement("div",null,[
      React.createElement("div",{style:{display:"flex",justifyContent:"space-between",alignItems:"center",marginBottom:16,flexWrap:"wrap",gap:10}},[
        sectionTitle("Inventory","Adjustments, disposal, recall, reconciliation, near-expiry, and stock table"),
        React.createElement("div",{key:"actions",style:{display:"flex",gap:8}},[btn("Inventory / Cost History",()=>{this.setState({inventoryHistoryOpen:true});this.loadInventoryHistory();}),btn("+ Add Item",this.goScreen("mgrRegistration"),"primary"),btn("+ Add Category",this.openCategoryModal),btn("âš  Add Damage",this.openAddDamage,"danger")]),
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
    const filtered=eligible.filter(p=>p.name.toLowerCase().includes(search)||p.category.toLowerCase().includes(search));
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
      sectionTitle("Categories, Subcategories and Brands","Create a category for new products, then register the product before requesting a purchase."),
      React.createElement("div",{key:"actions",style:{display:"flex",gap:8,flexWrap:"wrap",marginBottom:16}},[
        btn("+ Add Category",this.openCategoryModal,"primary"),
        btn("Register New Product",this.goScreen("mgrRegistration")),
        btn("Request Purchase",this.goScreen("mgrRequest")),
      ]),
      this.recordTable("categories",["Category","Classification","Products","Action"],categories.map(c=>tr([
        td(c.name),td(c.classification||"Not classified"),
        td((this.state.productsLocal||[]).filter(p=>p.category===c.name).length),
        td([btn("Edit",this.editCategory(c)),btn(c.status==="Active"?"Deactivate":"Activate",()=>this.authPost("/api/categories/"+encodeURIComponent(c.name),{status:c.status==="Active"?"Inactive":"Active"},"PATCH").then(()=>this.reloadCatalog()).catch(e=>this.toast(e.message,"error"))),badge(c.status)]),
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
        h('p',null,'Load a broad starter list for groceries, personal care, household supplies, appliances and electronics. Existing brands are preserved. Use Add brand for any others you carry.'),
        this.state.brandImportMessage?h('p',{role:'status'},this.state.brandImportMessage):null
      ]):null,
      kind==='subcategories'?h('div',{style:{margin:'12px 0'}},[
        btn('Add standard subcategories',this.populateSubcategories,'primary'),
        h('p',null,'Add common retail subcategories across the supported categories, including Appliances. Existing entries are preserved. Use Add subcategory for custom categories.'),
        this.state.subcategoryImportMessage?h('p',{role:'status'},this.state.subcategoryImportMessage):null
      ]):null,
      this.recordTable(kind,['Name',...(kind==='subcategories'?['Category']:[]),'Status','Actions'],(data[kind.toUpperCase()]||[]).map(r=>tr([td(r.name),...(kind==='subcategories'?[td(r.category)]:[]),td(badge(r.status)),td([btn('Edit',()=>this.setState({masterForm:{...r,kind}})),btn(r.status==='Active'?'Deactivate':'Activate',async()=>{try{await this.authPost('/api/product-master/'+kind+'/'+r.id,{...r,status:r.status==='Active'?'Inactive':'Active'},'PATCH');await this.reloadCatalog();}catch(e){this.toast(e.message,'error');}})])],r.id)))
    ])),f?card([
      h('h3',null,(f.id?'Edit ':'Add ')+f.kind),h('label',null,['Name ',h('input',{value:f.name,maxLength:100,onChange:e=>this.setState({masterForm:{...f,name:e.target.value}})})]),
      f.kind==='subcategories'?h('label',null,['Category ',h('select',{value:f.category,onChange:e=>this.setState({masterForm:{...f,category:e.target.value}})},[h('option',{value:''},'Select category'),...data.CATEGORIES.filter(c=>c.status==='Active').map(c=>h('option',{value:c.name,key:c.name},c.name))])]):null,
      btn('Save',this.saveMaster),btn('Cancel',()=>this.setState({masterForm:null}))
    ]):null]);
  }
  loadInventoryHistory=async()=>{
    this.setState({inventoryHistoryLoading:true,inventoryHistoryError:''});
    try{const query=new URLSearchParams(Object.entries(this.state.inventoryFilters||{}).filter(([,v])=>v));const r=await fetch('/api/inventory-history?'+query,{headers:{Accept:'application/json'}});const body=await r.json();if(!r.ok)throw new Error(body.message||'Could not load history.');this.setState({inventoryHistory:body});}
    catch(e){this.setState({inventoryHistoryError:e.message});}finally{this.setState({inventoryHistoryLoading:false});}
  };
  exportInventoryHistory=kind=>()=>{
    const history=this.state.inventoryHistory;if(!history)return;
    const columns={products:['id','name','category','brandId','subcategoryId','size','sizeUnit','stockUnit','stock','minStock'],batches:['id','productId','name','category','subcategory','brand','size','sizeUnit','stockUnit','receivedDate','supplier','receiptId','source','batchNumber','expiryDate','quantityReceived','quantityRemaining','unitCost'],movements:['created_at','productId','name','referenceType','referenceId','quantityChange','quantityBefore','quantityAfter']}[kind];
    const cell=value=>{let text=String(value??'');if(/^[=+@\-\t\r]/.test(text))text="'"+text;return '"'+text.replace(/"/g,'""')+'"';};
    const csv=[columns,...history[kind].map(row=>columns.map(key=>row[key]))].map(row=>row.map(cell).join(',')).join('\r\n');
    const url=URL.createObjectURL(new Blob(['\ufeff'+csv],{type:'text/csv;charset=utf-8;'}));
    const link=document.createElement('a');link.href=url;link.download='kita-'+kind+'.csv';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  };
  buildInventoryHistory(){
    const h=React.createElement,d=this.state.data,f=this.state.inventoryFilters||{},history=this.state.inventoryHistory;
    const field=(key,label,options)=>h('label',{key},[label,options?h('select',{value:f[key]||'',onChange:e=>this.setState({inventoryFilters:{...f,[key]:e.target.value,...(key==='category'?{subcategoryId:'',productId:''}:key==='subcategoryId'?{productId:''}:{})}})},[h('option',{value:''},'All'),...options.map(([value,text])=>h('option',{key:value,value},text))]):h('input',{type:'date',value:f[key]||'',onChange:e=>this.setState({inventoryFilters:{...f,[key]:e.target.value}})})]);
    return h('div',null,[sectionTitle('Inventory and Purchase Cost History','Each receipt retains its cost. Opening balances have estimated legacy costs, not reconstructed delivery history.'),
      btn('Back to Inventory',()=>this.setState({inventoryHistoryOpen:false})),
      h('div',{className:'purchase-filters'},[field('category','Category',d.CATEGORIES.map(c=>[c.name,c.name])),this.buildSubcategorySelect(f.category,f.subcategoryId,e=>this.setState({inventoryFilters:{...f,subcategoryId:e.target.value,productId:''}}),{includeInactive:true}),field('brandId','Brand',(d.BRANDS||[]).map(c=>[c.id,c.name])),field('productId','Product',d.PRODUCTS.filter(p=>(!f.category||p.category===f.category)&&(!f.subcategoryId||String(p.subcategoryId)===String(f.subcategoryId))).map(c=>[c.id,c.name+' #'+c.id])),field('supplierId','Supplier',d.SUPPLIERS.map(c=>[c.id,c.name])),field('from','Received from'),field('to','Received to'),field('lowStock','Current stock',[['1','Low stock only']])]),
      btn('Apply filters',this.loadInventoryHistory),btn('Print report',()=>window.print()),...['products','batches','movements'].map(kind=>btn('Export '+kind,this.exportInventoryHistory(kind))),
      this.state.inventoryHistoryLoading?loadingStatus('Loading inventory history...'):null,this.state.inventoryHistoryError?h('p',{role:'alert'},this.state.inventoryHistoryError):null,
      history?this.recordTable('inventory-current',['Product / Brand / Size','Category','Subcategory','Barcode','Stock unit','Available','Reorder level','Stock status'],history.products.map(p=>tr([td(this.productLabel(p)),td(p.category),td((d.SUBCATEGORIES||[]).find(c=>String(c.id)===String(p.subcategoryId))?.name||'-'),td(p.barcode),td(p.stockUnit),td(p.stock),td(p.minStock),td(badge(p.stock<=p.minStock?'Low stock':'Available'))],p.id))):null,
      history?this.recordTable('inventory-batches',['Product','Category / Subcategory','Brand','Size / Unit','Received','Supplier','Reference','Source','Batch / Expiry','Qty received','Remaining','Unit cost','Remaining value'],history.batches.map(b=>tr([td(b.name),td(b.category+' / '+(b.subcategory||'-')),td(b.brand||'-'),td((b.size||'')+' '+(b.sizeUnit||'')+' / '+b.stockUnit),td(b.source==='legacy_opening'?'Opening balance (date unknown)':b.receivedDate),td(b.supplier||'-'),td(b.receiptId||'Opening balance'),td(b.source),td((b.batchNumber||'-')+' / '+(b.expiryDate||'-')),td(b.quantityReceived),td(b.quantityRemaining),td(peso(b.unitCost)),td(peso(b.quantityRemaining*b.unitCost))],b.id))):null,
      history?card([h('h3',null,'Inventory movements (latest 500)'),this.recordTable('inventory-movements',['Date','Product','Type','Reference','Change','Before','After'],history.movements.map(m=>tr([td(m.created_at),td(m.name),td(m.referenceType),td(m.referenceId),td(m.quantityChange),td(m.quantityBefore),td(m.quantityAfter)],m.id)))]):null
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
            h('input',{key:step,id:'category-setup-name',autoFocus:true,maxLength:100,value:s.categorySetupName||'',placeholder:isSub?'e.g. Refrigerators':'e.g. Samsung',onChange:e=>this.setState({categorySetupName:e.target.value,categorySetupBrandId:'',categoryError:''})})
          ]),
          !isSub?h('div',{className:'field-group'},[h('label',{htmlFor:'category-setup-brand'},'Or choose an existing brand'),h('select',{id:'category-setup-brand',value:s.categorySetupBrandId||'',onChange:e=>this.setState({categorySetupBrandId:e.target.value,categorySetupName:'',categoryError:''})},[h('option',{value:''},'Select brand'),...(s.data.BRANDS||[]).filter(b=>b.status==='Active').map(b=>h('option',{key:b.id,value:b.id},b.name))])]):null,
          categoryError?h('div',{role:'alert',className:'alert'},categoryError):null,
          h('p',{className:'form-section-description'},'Completed steps are saved. You can finish later from Categories, Subcategories and Brands.'),
          h('div',{className:'dialog-actions'},[btn('Finish later',this.closeCategoryModal),btn(isSub?'Next: Brand':'Finish setup',this.saveCategory,'primary')])
        ]);
    }
    return dialog([
        h("p",{key:"help",className:"form-section-description"},"Group related products and choose how they are classified."),
        h("div",{key:"name",className:"field-group"},[
          h("label",{htmlFor:"category-name"},"Category name *"),h("input",{id:"category-name",autoFocus:true,required:true,value:f.name,onChange:this.setCategoryField("name"),maxLength:100,placeholder:"e.g. Household supplies","aria-invalid":!!categoryError,"aria-describedby":categoryError?"category-error":undefined}),
        ]),
        h("div",{key:"classification",className:"field-group"},[
          h("label",{htmlFor:"category-classification"},"Classification *"),h("select",{id:"category-classification",required:true,value:f.classification||"",onChange:this.setCategoryField("classification"),"aria-invalid":!!categoryError,"aria-describedby":categoryError?"category-error":undefined},[h("option",{key:"empty",value:""},"Select classification"),...["Perishable","Non-Perishable"].map(value=>h("option",{key:value,value},value))]),
        ]),
        categoryError?h("div",{key:"error",id:"category-error",role:"alert",className:"alert"},categoryError):null,
        h("div",{key:"actions",className:"dialog-actions"},[btn("Cancel",this.closeCategoryModal),btn(categoryEditing?"Save changes":"Next: Subcategory",this.saveCategory,"primary")]),
      ]);
  }
  subcategoriesFor=(category,includeInactive=false)=>(this.state.data.SUBCATEGORIES||[]).filter(c=>c.category===category&&(includeInactive||c.status==='Active'));
  buildSubcategorySelect(category,value,onChange,{registration=false,includeInactive=false}={}){
    const h=React.createElement,options=this.subcategoriesFor(category,includeInactive);
    const placeholder=!category?'Select category first':!options.length?'No subcategories':registration?'Select subcategory':'All subcategories';
    return h('label',{key:'subcategory',className:'field-group'},['Subcategory',h('select',{'aria-label':'Subcategory',value:value||'',onChange,disabled:!category||!options.length,required:registration&&options.length>0},[
      h('option',{key:'empty',value:''},placeholder),...options.map(c=>h('option',{key:c.id,value:c.id},c.name))
    ])]);
  }
  productLabel=p=>[p.name,(this.state.data?.BRANDS||[]).find(b=>String(b.id)===String(p.brandId))?.name,p.size?Number(p.size):null,p.sizeUnit].filter(Boolean).join(' ');
  editProductMaster=p=>this.setState({screen:'mgrRegistration',regEditing:p.id,regStockId:String(p.id),regProductName:p.name,regCategory:p.category,regBrandId:p.brandId||'',regSubcategoryId:p.subcategoryId||'',regSize:p.size||'',regSizeUnit:p.sizeUnit||'',regReorderLevel:p.minStock||0,regScannedBarcode:p.barcode||'',regSupplier:(this.state.data.SUPPLIERS.find(s=>s.id===p.supplierId)||{}).name||'',regStockUnit:p.stockUnit||p.unit,regPurchaseUnit:p.purchaseUnit||p.unit,regConversionFactor:p.conversionFactor||1,regUnitPrice:String(p.unitPrice??0),regRetailPrice:String(p.price??0),regStatus:p.status,regSaved:false,regEditReason:''});
  buildMgrRegistration(){
    const h=React.createElement,s=this.state,data=s.data;
    const products=s.productsLocal||data.PRODUCTS;
    const units=["Piece","Pack","Box","Case","Sack","Tray","Bottle","Can","Tub","Kg","Liter"];
    const field=(key,label,options={})=>{
      const id="registration-"+key,helpId=id+"-help";
      return h("div",{key,className:"field-group"},[
        h("label",{key:"label",htmlFor:id},[label,options.required?h("span",{className:"required-marker","aria-hidden":true}," *"):null]),
        h("input",{key:"input",id,type:options.type||"text",value:s[key]??"",onChange:options.readOnly?undefined:this.setRegField(key),readOnly:!!options.readOnly,required:!!options.required,"aria-required":!!options.required,"aria-describedby":options.help?helpId:undefined,placeholder:options.placeholder||label,...(options.type==="number"?{min:0,step:options.step||"0.01"}:{}),...(key==="regProductName"?{list:"reg-product-options"}:{})}),
        options.help?h("small",{key:"help",id:helpId},options.help):null,
      ]);
    };
    const select=(key,label,values,required=false)=>h("div",{key,className:"field-group"},[
      h("label",{key:"label",htmlFor:"registration-"+key},[label,required?h("span",{className:"required-marker","aria-hidden":true}," *"):null]),
      h("select",{key:"input",id:"registration-"+key,value:s[key],onChange:this.setRegField(key),required,"aria-required":required},[h("option",{key:"empty",value:""},"Select "+label.toLowerCase()),...values.map(value=>h("option",{key:value,value},value))]),
    ]);
    const group=(number,title,description,fields)=>h("fieldset",{className:"form-section",key:title},[
      h("legend",null,[h("span",{className:"section-number"},number),title]),h("p",{className:"form-section-description"},description),h("div",{className:"form-grid"},fields),
    ]);
    return h("div",{className:"registration-page"},[
      sectionTitle(s.regEditing?"Edit Product":"Product Registration","Product information is separate from stock. New products start at zero; receiving adds stock."),
      h("div",{key:"actions",className:"page-toolbar"},[btn("New Product",()=>this.setState({regEditing:null,regStockId:"",regProductName:"",regScannedBarcode:"",regSaved:false,regBrandId:"",regSubcategoryId:"",regSize:"",regSizeUnit:"",regEditReason:""})),btn("+ Add Category",this.openCategoryModal),s.regSaved?h("span",{role:"status",className:"saved-indicator"},"Product saved. Stock ID: "+s.regSku):null]),
      h("datalist",{key:"names",id:"reg-product-options"},[...new Set(products.map(p=>p.name))].sort().map(name=>h("option",{key:name,value:name}))),
      group("01","Product details","Identify the item and choose its category and supplier.",[
        field("regProductName","Product name",{required:true}),field("regStockId","Stock ID",{type:"number",step:1,readOnly:!!s.regEditing,placeholder:"Created automatically",help:"Optional. Leave blank to generate a unique ID."}),
        field("regScannedBarcode","Scanned barcode",{required:true,placeholder:"Scan or enter the item barcode"}),
        select("regCategory","Category",data.CATEGORIES.filter(c=>c.status==="Active").map(c=>c.name),true),
        h("label",{className:"field-group"},["Brand",h("select",{value:s.regBrandId||"",onChange:this.setRegField("regBrandId")},[h("option",{value:""},"Unbranded / not applicable"),...(data.BRANDS||[]).filter(b=>b.status==="Active").map(b=>h("option",{value:b.id,key:b.id},b.name))])]),
        this.buildSubcategorySelect(s.regCategory,s.regSubcategoryId,this.setRegField("regSubcategoryId"),{registration:true}),
        field("regSize","Size / measurement",{type:"number",step:"0.001"}),select("regSizeUnit","Measurement unit",["mL","Liter","g","kg","cm","m","Piece"]),
        select("regSupplier","Supplier",data.SUPPLIERS.filter(sp=>sp.status==="Active").map(sp=>sp.name)),
        select("regStatus","Status",["Active","Inactive"]),
      ]),
      group("02","Units and reorder level","New products start with zero stock. Receive a purchase to add inventory.",[
        field("regReorderLevel","Reorder level",{type:"number",step:1}),
        select("regPurchaseUnit","Purchase unit",units,true),select("regStockUnit","Stock unit",units,true),
        field("regConversionFactor","Conversion factor",{type:"number",required:true,help:"Number of stock units in one purchase unit."}),
      ]),
      group("03","Pricing","Purchase cost and selling price are recorded separately.",[
        field("regUnitPrice","Estimated purchase cost (\u20B1)",{type:"number",required:true}),

        field("regRetailPrice","Retail price (\u20B1)",{type:"number",help:"If blank, the unit price is used."}),
      ]),
      s.regEditing?field("regEditReason","Reason for price change"):null,
      h("div",{key:"save",className:"form-actions"},[
        h("span",{className:"form-action-note"},"Choose an active supplier before requesting a purchase."),
        h("button",{type:"button",className:"primary-button",disabled:!!s.registrationSaving,"aria-busy":!!s.registrationSaving,onClick:this.saveRegistration},s.registrationSaving?"Saving product...":s.regSaved?"Product registered":"Save product registration"),
      ]),
      s.regSaved?h("div",{key:"print",className:"kita-card registration-receipt"},[
        h("h2",{className:"panel-title"},"Registration complete"),h("p",null,`Stock ID: ${s.regSku} | Barcode: ${s.regBarcode} | Available stock: ${s.regRegisteredQty}`),
        h("label",{className:"record-page-size"},["Label quantity",h("input",{type:"number",min:1,value:s.regPrintQty,onChange:this.setRegPrintQty})]),btn("Print labels",this.printLabels),
      ]):null,
      card([h('h2',null,'Product Master'),this.recordTable('product-master',['Product / Brand / Size','Category','Barcode','Unit','Available','Status','Action'],products.map(p=>tr([td(this.productLabel(p)),td(p.category),td(p.barcode),td(p.stockUnit||p.unit),td(p.stock),td(badge(p.status)),td(btn('Edit Product',()=>this.editProductMaster(p)))],p.id)))]),
      s.categoryModalOpen?this.buildCategoryModal():null,
    ]);
  }
  buildMgrCashiers(){ return this.buildAdmManagers(); }
  buildPromoPickerModal(){
    const {data,promoPickerSearch,promoPickerChecked}=this.state; const q=promoPickerSearch.toLowerCase();
    const items=data.PRODUCTS.filter(p=>p.name.toLowerCase().includes(q));
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
    const items=(productsLocal||[]).filter(p=>p.status!=="Inactive"&&p.name.toLowerCase().includes(q));
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
      const tabs=[["products","Products"],["history","Order History"],["callback","Callback Log"]];
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
      } else if(supMgrDetailTab==="history"){
        const pos=(purchaseOrdersLocal||[]).filter(po=>po.supplierId===sp.id);
        body = pos.length===0 ? card(React.createElement("div",{style:{padding:16,textAlign:"center",color:COLORS.textMuted,fontSize:13}},"No purchase orders yet.")) :
          React.createElement("div",null,pos.map(po=>card([
            React.createElement("div",{key:"h",style:{display:"flex",justifyContent:"space-between",marginBottom:8}},[React.createElement("div",{style:{fontWeight:700,fontFamily:"'JetBrains Mono',monospace"}},po.id),badge(po.status)]),
            React.createElement("div",{key:"g",style:{display:"grid",gridTemplateColumns:"repeat(3,minmax(0,1fr))",gap:10,fontSize:12}},[
              ["Ordered",po.orderedValue],["Delivered",po.deliveredValue],["Invoiced",po.invoicedValue],["Paid",po.paidValue],["Outstanding",po.outstandingValue],["Cancelled",po.cancelledValue],
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
    const list=suppliersLocal.filter(sp=>sp.status==="Active"&&sp.name.toLowerCase().includes(q));
    const selCount=Object.values(supMgrSelected).filter(Boolean).length;
    return React.createElement("div",null,[
      React.createElement("div",{style:{display:"flex",justifyContent:"space-between",alignItems:"center",marginBottom:16}},[sectionTitle("Supplier","Manager-facing supplier directory used when building requests"),btn("+ Add Supplier",this.openSupMgrModal(null),"primary")]),
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
    const nearExpiry=data.PRODUCTS.filter(p=>p.expiry).map(p=>({p,days:Math.round((new Date(p.expiry)-new Date(today))/86400000)})).sort((a,b)=>a.days-b.days).slice(0,5);
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
              React.createElement("div",null,[React.createElement("div",{style:{fontSize:13,fontWeight:600}},x.p.name),React.createElement("div",{style:{fontSize:11,color:x.days<=1?COLORS.red:COLORS.amber,fontWeight:700}},x.days+" day(s) left")]),
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
  buildAdmDashboard(){
    const {data}=this.state; if(!data) return null;
    const byProduct={}; data.SALES_LOG.forEach(t=>{ byProduct[t.productId]=(byProduct[t.productId]||0)+t.qty*t.amount; });
    const ranked=Object.entries(byProduct).map(([id,rev])=>({p:data.PRODUCTS.find(p=>p.id===Number(id)),rev})).filter(x=>x.p).sort((a,b)=>b.rev-a.rev);
    const byHour={}; data.SALES_LOG.forEach(t=>{ byHour[t.hour]=(byHour[t.hour]||0)+t.qty; }); const maxHour=Math.max(...Object.values(byHour));
    return React.createElement("div",null,[sectionTitle("Dashboard / Analytics"),
      React.createElement("div",{style:{display:"flex",gap:14,marginBottom:16,flexWrap:"wrap"}},[
        kpi("Active Products",data.PRODUCTS.filter(p=>p.status==="Active"&&!p.archivedAt).length,"From the current catalog"),
        kpi("Low Stock Items",data.PRODUCTS.filter(p=>p.status==="Active"&&!p.archivedAt&&p.stock<=p.minStock).length,"At or below minimum stock",COLORS.amber),
        kpi("Recorded Sales",peso(data.SALES_LOG.reduce((sum,t)=>sum+t.amount*t.qty,0)),"All records in the sales log",COLORS.brand),
        kpi("Business Day Sales",peso(data.SALES_LOG.filter(t=>t.date===data.BUSINESS_DATE).reduce((sum,t)=>sum+t.amount*t.qty,0)),data.BUSINESS_DATE||"Business date unavailable",COLORS.brand),
      ]),
      React.createElement("details",{className:"reference-metrics"},[
        React.createElement("summary",null,"Existing reference metrics"),
        React.createElement("p",null,"Receiving accuracy: 91% | On-time delivery: 86% | Damage rate: 3.2%. These existing reference values are not calculated from live records."),
      ]),
      React.createElement("div",{style:{display:"grid",gridTemplateColumns:"repeat(4,minmax(0,1fr))",gap:14}},[
        card([React.createElement("div",{key:"t",className:"panel-title"},"Best Sellers"),rankedSales(ranked.slice(0,5))]),
        card([React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"Worst Sellers"),...ranked.slice(-5).reverse().map((x,i)=>React.createElement("div",{key:i,style:{display:"flex",justifyContent:"space-between",fontSize:13,padding:"6px 0",borderBottom:"1px solid #eef1f6"}},[React.createElement("span",null,x.p.name),React.createElement("span",{style:{fontWeight:700,color:COLORS.textMuted}},peso(x.rev))]))]),
        card([React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:10}},"Peak-Hour Heatmap"),...Object.keys(byHour).sort((a,b)=>a-b).map(hr=>React.createElement("div",{key:hr,style:{display:"flex",alignItems:"center",gap:8,marginBottom:4}},[React.createElement("span",{style:{fontSize:11,width:36,color:COLORS.textMuted}},hr+":00"),React.createElement("div",{style:{flex:1,height:10,background:"#eef0f4",borderRadius:5}},React.createElement("div",{style:{width:(byHour[hr]/maxHour*100)+"%",height:10,background:COLORS.brand,borderRadius:5}}))]))]),
        this.buildNearExpiredWidget(false),
      ]),
    ]);
  }
  buildAdmPurchasedOrders(){ return this.buildPurchaseReview(); }
  buildAdmForwarded(){ return this.buildPurchaseStatusList(); }
  buildAdmDisapproved(){ return this.buildPurchaseStatusList(true); }
  buildArchive(){
    const {archiveTab,archiveChecked,productsLocal,categoriesLocal,suppliersLocal,archiveConfirmOpen}=this.state;
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
      sectionTitle("Archive","Soft-deleted / Inactive Items, Categories, and Suppliers â€” recoverable via Restore"),
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
    return React.createElement("div",null,[sectionTitle("Inventory Adjustment Approvals","Evidence shown inline"),
      ...adjustmentsLocal.map(a=>{ const p=data.PRODUCTS.find(pp=>pp.id===a.productId); return card([
        React.createElement("div",{key:"h",style:{display:"flex",justifyContent:"space-between"}},[React.createElement("div",null,[React.createElement("div",{style:{fontWeight:700}},a.id+" Â· "+(p?p.name:"â€”")),React.createElement("div",{style:{fontSize:12,color:COLORS.textSoft}},a.reason+" Â· Î”"+a.qtyChange+" Â· "+a.remarks)]),badge(a.status)]),
        a.photo?React.createElement("div",{key:"ph",style:{fontSize:12,color:COLORS.blue,marginTop:6}},"ðŸ“· Photo evidence attached"):null,
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
        sectionTitle(s.name,s.category),
        card(infoRows,{marginBottom:14}),
        card([React.createElement("div",{key:"t",style:{fontWeight:800,marginBottom:8}},"Supplier Callback Log")].concat(callbackRows)),
      ]);
    }
    return React.createElement("div",null,[sectionTitle("Supplier Records","Contact info, address, category, status (Active/Inactive only)"),
      this.recordTable("supplier-records",["Supplier","Category","Status","Details"],data.SUPPLIERS.map(s=>tr([td(s.name,{fontWeight:600}),td(s.category),td(badge(s.status)),td(React.createElement("button",{onClick:this.openSupplierDetail(s.id),style:{color:COLORS.brand,background:"none",border:"none",fontWeight:700,cursor:"pointer"}},"View â†’"))],s.id)))]);
  }
  setReportFilter=(key,value)=>this.setState(s=>({reportFilters:{...(s.reportFilters||{type:'sales'}),[key]:value,...(key==='category'?{subcategoryId:'',productId:''}:['brandId','subcategoryId','supplierId'].includes(key)?{productId:''}:{}),...(key==='type'&&value==='inventory'?{from:'',to:''}:{})}}));
  generateReport=async()=>{
    const filters={...(this.state.reportFilters||{type:'sales'})};
    const requestId=(this.reportRequestId||0)+1;this.reportRequestId=requestId;
    this.setState({reportLoading:true,reportError:''});
    try{
      const response=await fetch('/reports/data?'+new URLSearchParams(Object.entries(filters).filter(([,v])=>v)),{headers:{Accept:'application/json'}});
      const body=await response.json();if(!response.ok)throw new Error(body.message||'Could not generate report.');
      if(this.reportRequestId===requestId)this.setState({reportResult:body,reportAppliedFilters:filters});
    }catch(error){if(this.reportRequestId===requestId)this.setState({reportError:error.message,reportResult:null});}
    finally{if(this.reportRequestId===requestId)this.setState({reportLoading:false});}
  };
  buildAdmReports(){
    const h=React.createElement,s=this.state,d=s.data,f=s.reportFilters||{type:'sales'},r=s.reportResult;
    const field=(key,label,options)=>h('label',{key,className:'field-group'},[label,h(options?'select':'input',{value:f[key]||'',type:options?undefined:'date',disabled:!options&&f.type==='inventory',onChange:e=>this.setReportFilter(key,e.target.value)},options?[...(key==='type'?[]:[h('option',{key:'all',value:''},'All')]),...options.map(([value,text])=>h('option',{key:value,value},text))]:null)]);
    const products=d.PRODUCTS.filter(p=>(!f.category||p.category===f.category)&&(!f.subcategoryId||String(p.subcategoryId)===String(f.subcategoryId))&&(!f.brandId||String(p.brandId)===String(f.brandId))&&(f.type==='receipts'||!f.supplierId||String(p.supplierId)===String(f.supplierId)));
    const url=format=>'/reports/data?'+new URLSearchParams({...Object.fromEntries(Object.entries(s.reportAppliedFilters||{}).filter(([,v])=>v)),format});
    return h('div',null,[sectionTitle('Reports','Filter and generate a report, then print, save as PDF or export all matching rows to CSV.'),
      card([h('div',{className:'form-grid'},[
        field('type','Report type',[['sales','Sales lines'],['inventory','Current inventory'],['receipts','Received stock'],['movements','Stock movements']]),
        field('from','From date'),field('to','To date'),field('category','Category',d.CATEGORIES.map(c=>[c.name,c.name])),
        this.buildSubcategorySelect(f.category,f.subcategoryId,e=>this.setReportFilter('subcategoryId',e.target.value),{includeInactive:true}),
        field('brandId','Brand',(d.BRANDS||[]).map(b=>[b.id,b.name])),field('supplierId','Supplier',d.SUPPLIERS.map(p=>[p.id,p.name])),
        field('productId','Product',products.map(p=>[p.id,this.productLabel(p)+' #'+p.id])),field('lowStock','Stock filter',[['1','Currently low stock']])
      ]),f.type==='inventory'?h('p',null,'Current stock snapshot: dates do not apply. Use Stock movements for a date range.'):null,
      btn(s.reportLoading?'Generating...':'Generate report',this.generateReport,'primary'),btn('Reset filters',()=>{this.reportRequestId=(this.reportRequestId||0)+1;this.setState({reportFilters:{type:'sales'},reportResult:null,reportError:'',reportLoading:false});})]),
      s.reportError?h('p',{role:'alert'},s.reportError):null,
      r?h('section',null,[h('h2',null,r.title),h('p',null,'Generated: '+r.generated+' | '+r.rows.length+' records'),h('p',null,Object.entries(r.labels).map(([key,value])=>key+': '+value).join(' | ')),h('p',null,r.note),
        h('div',{className:'page-toolbar'},[h('a',{className:'kita-button',href:url('print'),target:'_blank',rel:'noopener'},'Print / Save PDF'),h('a',{className:'kita-button',href:url('csv')},'Export CSV')]),
        h('p',null,'Exports use the displayed report filters. After changing filters, generate the report again.'),
        r.rows.length?this.recordTable('generated-report',Object.keys(r.columns),r.rows.map((row,i)=>tr(Object.values(r.columns).map(key=>td(row[key]??'-')),i))):h('p',null,'No records match these filters.')
      ]):null
    ]);
  }
  buildAdmManagers(){
    const h=React.createElement,{accountsLocal,acctModalOpen,acctModalMode,acctForm:f}=this.state;
    const roles=this.state.role==="manager"?["cashier"]:this.state.role==="superadmin"?["admin","manager","cashier","superadmin"]:["cashier","manager"];
    const title=this.state.role==="superadmin"?"User Management":this.state.role==="manager"?"Cashier Accounts":"Manage Accounts";
    const input=(key,label,type="text")=>h("label",{key,style:{display:"block",fontSize:12,fontWeight:700,marginBottom:10}},[label,h("input",{type,value:f[key]||"",onChange:this.setAcctField(key),autoComplete:type==="password"?"new-password":"off",style:{width:"100%",boxSizing:"border-box",padding:10,marginTop:4,border:"1px solid "+COLORS.border,borderRadius:8}})]);
    return h("div",null,[sectionTitle(title,"Accounts are retained for audit history. Inactive accounts cannot sign in. Last OTP login reflects retained verification records; password-only cashier logins are not recorded."),btn("Create Account",this.openAcctModal("create"),"primary"),
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
    return React.createElement('div',null,[sectionTitle('Your Notifications'),btn('Refresh',this.retryNotifications),
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
      sectionTitle("System Overview","Manage accounts and review measured system activity."),
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
    return React.createElement("div",null,[sectionTitle("Roles & Permissions","Toggle access per module"),
      React.createElement("div",{className:"table-scroll",tabIndex:0,role:"region","aria-label":"Role permissions",style:{overflowX:"auto",border:"1px solid "+COLORS.border,borderRadius:10}},React.createElement("table",{style:{width:"100%",borderCollapse:"collapse",fontSize:13}},[
        React.createElement("thead",{key:"h"},React.createElement("tr",null,[React.createElement("th",{key:"m",style:{textAlign:"left",padding:"10px 14px",background:"#f8faff",fontSize:11,color:COLORS.textMuted}},"Module"),...rolesMatrix.roles.map(r=>React.createElement("th",{key:r,style:{padding:"10px 14px",background:"#f8faff",fontSize:11,color:COLORS.textMuted}},r))])),
        React.createElement("tbody",{key:"b"},rolesMatrix.grid.map((row,mi)=>tr([td(row.module,{fontWeight:600}),...row.cells.map((c,ri)=>td(React.createElement("div",{onClick:this.toggleRolePerm(mi,ri),style:{width:36,height:20,borderRadius:10,background:c?COLORS.green:"#e3e6ec",position:"relative",cursor:"pointer",margin:"0 auto"}},React.createElement("div",{style:{width:16,height:16,borderRadius:"50%",background:"#fff",position:"absolute",top:2,left:c?18:2}})),{textAlign:"center"}))],mi)))
      ]))]);
  }
  buildSaBackup(){
    const h=React.createElement,d=this.state.backupData,items=d?.backups||[],id=this.state.backupRestoreId;
    return h("div",null,[sectionTitle("Backup Configuration","Encrypted database snapshots"),
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
    return React.createElement("div",null,[sectionTitle("Audit Logs & Activity","Append-only â€” export or flag for review, no edit/delete"),
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
        {label:"PURCHASE REQUEST",items:[{key:"mgrRequest",label:"Create Request"},{key:"mgrRequestView",label:"View Requests"},{key:"mgrPurchaseHistory",label:"Transaction History"},{key:"mgrPO",label:"Stock Receiving"}]},
        {label:"INVENTORY",items:[{key:"mgrInventory",label:"Inventory"},{key:"mgrCategories",label:"Categories / Brands"},{key:"mgrRegistration",label:"Barcode/Item Registration"},{key:"archive",label:"Archive"}]},
        {label:"REPORTS",items:[{key:"admReports",label:"Reports"}]},
        {label:"TEAM & MERCH",items:[{key:"mgrCashiers",label:"Cashier Accounts"},{key:"mgrPromotions",label:"Promotions"},{key:"mgrSupplier",label:"Supplier"}]}],
      admin:[{label:null,items:[{key:"admDashboard",label:"Dashboard/Analytics"}]},
        {label:"PURCHASING",items:[{key:"admPurchasedOrders",label:"Purchase Requests"},{key:"admForwarded",label:"Forwarded Purchase Requests"},{key:"admDisapproved",label:"Disapproved Purchase Requests"},{key:"admReceivingApprovals",label:"Receiving History"}]},
        {label:"CONTROLS",items:[{key:"mgrCategories",label:"Categories / Brands"},{key:"mgrRegistration",label:"Product Registration"},{key:"mgrInventory",label:"Inventory"},{key:"admInventoryApprovals",label:"Inventory Adjustment Approvals"},{key:"admSuppliers",label:"Supplier Records"},{key:"admReports",label:"Reports"},{key:"archive",label:"Archive"}]},
        {label:"ADMIN",items:[{key:"admManagers",label:"Manage Accounts"},{key:"admNotifications",label:"Notifications"}]}],
      superadmin:[{label:null,items:[{key:"saDashboard",label:"Dashboard"},{key:"saAdmins",label:"User Management"},{key:"saRoles",label:"Roles & Permissions"},{key:"saBackup",label:"Backup Configuration"},{key:"saAudit",label:"Audit Logs"}]}],
    };
    const navGroups=(navMap[s.role]||[]).map(g=>({label:g.label,items:g.items.map(it=>({key:it.key,label:it.label,icon:navigationIcon(it.key),go:this.goScreen(it.key),className:s.screen===it.key?"nav-item nav-item--active":"nav-item",current:s.screen===it.key?"page":undefined,
      style:{padding:"9px 12px",borderRadius:8,fontSize:13,fontWeight:600,cursor:"pointer",marginBottom:2,background:s.screen===it.key?"rgba(255,255,255,0.14)":"transparent"}}))}));
    const titles={pos:"Checkout",refunds:"Refunds / Exchanges / Voids",shift:"My Shift Transactions",mgrDashboard:"Manager Dashboard",mgrRequest:"Create Purchase Request",mgrRequestView:"View Requests",mgrPurchaseHistory:"Transaction History",mgrPO:"Stock Receiving",
      mgrInventory:"Inventory",mgrCategories:"Categories",mgrRegistration:"Barcode/Item Registration",mgrCashiers:"Cashier Accounts",mgrPromotions:"Promotions",mgrSupplier:"Supplier",archive:"Archive",
      admDashboard:"Dashboard/Analytics",admPurchasedOrders:"Purchase Requests",admForwarded:"Forwarded Purchase Requests",admDisapproved:"Disapproved Purchase Requests",
      admReceivingApprovals:"Receiving History",admInventoryApprovals:"Inventory Adjustment Approvals",admSuppliers:"Supplier Records",admReports:"Reports",admManagers:"Manage Accounts",admNotifications:"Notifications",
      saDashboard:"Dashboard",saAdmins:"User Management",saRoles:"Roles & Permissions",saBackup:"Backup Configuration",saAudit:"Audit Logs & Activity"};
    let activeScreen=null;
    if(data || s.role==="superadmin"){
      const map={pos:()=>this.buildPos(), refunds:()=>this.buildRefunds(), shift:()=>this.buildShift(),
        mgrDashboard:()=>this.buildMgrDashboard(), mgrRequest:()=>this.buildMgrRequest(), mgrRequestView:()=>this.buildPurchaseRequests(), mgrPurchaseHistory:()=>this.buildPurchaseHistory(), mgrPO:()=>this.buildMgrPO(),
        mgrInventory:()=>this.buildMgrInventory(), mgrCategories:()=>this.buildMgrCategories(), mgrRegistration:()=>this.buildMgrRegistration(), mgrCashiers:()=>this.buildMgrCashiers(), mgrPromotions:()=>this.buildMgrPromotions(), mgrSupplier:()=>this.buildMgrSupplier(), archive:()=>this.buildArchive(),
        admDashboard:()=>this.buildAdmDashboard(), admPurchasedOrders:()=>this.buildAdmPurchasedOrders(), admForwarded:()=>this.buildAdmForwarded(), admDisapproved:()=>this.buildAdmDisapproved(),
        admReceivingApprovals:()=>this.buildAdmReceivingApprovals(), admInventoryApprovals:()=>this.buildAdmInventoryApprovals(), admSuppliers:()=>this.buildAdmSuppliers(), admReports:()=>this.buildAdmReports(),
        admManagers:()=>this.buildAdmManagers(), admNotifications:()=>this.buildAdmNotifications(),
        saDashboard:()=>this.buildSaDashboard(), saAdmins:()=>this.buildSaAdmins(), saRoles:()=>this.buildSaRoles(), saBackup:()=>this.buildSaBackup(), saAudit:()=>this.buildSaAudit()};
      activeScreen = map[s.screen] ? map[s.screen]() : null;
    }

    return {
      sidebarClass:s.sidebarOpen?"sidebar sidebar--open":"sidebar",sidebarOpen:s.sidebarOpen,toggleSidebar:this.toggleSidebar,closeSidebar:this.closeSidebar,onShellKeyDown:this.onShellKeyDown,
      dataLoading:(s.pendingLoads||0)>0||!!s.purchaseLoading||!!s.backupLoading||!!s.backupBusy,
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


