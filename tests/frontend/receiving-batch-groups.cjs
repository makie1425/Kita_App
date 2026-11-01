const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const ctx=vm.createContext({React:{createElement:(type,props,...children)=>({type,props,children})},DCLogic:class{setState(p){Object.assign(this.state,typeof p==='function'?p(this.state):p);}},crypto:{randomUUID:()=> 'key'},window:{}});
vm.runInContext(fs.readFileSync('public/app.js','utf8')+';globalThis.app=new Component();',ctx);
const app=ctx.app;
app.state.data={BUSINESS_DATE:'2026-10-26',CATEGORIES:[],PRODUCTS:[]};
app.inspectionLine=()=>({unitCost:10});app.inspectionProduct=()=>({category:'Supplies'});app.inspectionValueLimit=()=>1000;
const valid={primaryPo:'PO1',deliveryDate:'2026-10-26',supplierReference:'INV1',notes:'No remarks',entries:[{key:'1',poId:'PO1',productId:1,type:'current',delivered:2,damaged:0,unitCost:10,damageReason:'',lots:[{quantity:2,supplierLot:'SUP1',manufacturedDate:'2026-10-01',expiryDate:'',noExpiry:true}]}]};
const reset=()=>{app.state.inspection=JSON.parse(JSON.stringify(valid));app.state.inspectionReview=false;};
reset();assert.equal(app.reviewInspection(),true);
for(const field of ['delivered','damaged','unitCost']){reset();app.state.inspection.entries[0][field]='';assert.equal(app.reviewInspection(),false,field);}
for(const field of ['supplierReference','notes','deliveryDate']){reset();app.state.inspection[field]='';assert.equal(app.reviewInspection(),false,field);}
for(const field of ['supplierLot','manufacturedDate']){reset();app.state.inspection.entries[0].lots[0][field]='';assert.equal(app.reviewInspection(),false,field);}
reset();app.state.inspection.entries[0].lots[0].noExpiry=false;assert.equal(app.reviewInspection(),false);
reset();app.state.inspection.entries.push({...valid.entries[0],key:'2',productId:2,delivered:0,damaged:0,lots:[]});assert.equal(app.reviewInspection(),true);assert.equal(app.inspectionPayload().entries.length,2);
const rows=[{id:1,productId:1,batchNumber:'B1',name:'A'},{id:2,productId:2,batchNumber:'B1',name:'B'},{id:3,productId:1,batchNumber:'B2',name:'A'}];
assert.equal(app.inventoryBatchGroups(rows).length,2);assert.equal(app.inventoryBatchGroups(rows)[0].items.length,2);
const tree=app.groupedBatchTables('test',rows,['Product'],r=>({type:'tr',children:[r.name]}));const nodes=n=>!n||typeof n!=='object'?[]:Array.isArray(n)?n.flatMap(nodes):[n,...nodes(n.children||n.props?.children)];assert.equal(nodes(tree).filter(n=>n.type==='div'&&n.props?.className==='inventory-batch-group').length,2);
console.log('Required receiving fields, explicit zero items, and batch grouping passed.');

assert.equal(nodes(tree).filter(n=>n.type==='a'&&n.props?.className==='kita-button batch-print-button').length,0);
nodes(tree).find(n=>n.props?.['aria-label']==='View B1').props.onClick();
const popup=app.groupedBatchTables('test',rows,['Product'],r=>({type:'tr',children:[r.name]}));
assert.equal(nodes(popup).filter(n=>n.type==='dialog').length,1);
assert.equal(nodes(popup).find(n=>n.props?.className==='kita-button batch-print-button').props.href,'/inventory/batches/1/print');
const dialog=nodes(popup).find(n=>n.type==='dialog');dialog.props.onCancel({preventDefault(){}});assert.equal(app.state.viewingBatch,null);
app.state.screen='mgrPO';app.state.purchaseData={orders:[],requests:[]};app.purchasingState=()=>null;app.loadInventoryHistory=()=>{};
app.buildInventoryHistory=embedded=>({type:'section',props:{embedded},children:['batch history']});
let receiving=app.buildMgrPO();
nodes(receiving).find(n=>n.type==='button'&&n.children.flat(Infinity).includes('Batch Records')).props.onClick();
receiving=app.buildMgrPO();assert.equal(app.state.screen,'mgrPO');assert(nodes(receiving).some(n=>n.props?.embedded===true));
assert(nodes(receiving).some(n=>n.props?.['aria-label']==='Receiving views'));
nodes(receiving).find(n=>n.type==='button'&&n.children.flat(Infinity).includes('To Receive')).props.onClick();assert.equal(app.state.receivingTab,'active');
console.log('Batch cards, popup print/close, and embedded receiving tab navigation passed.');
