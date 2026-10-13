const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
let traceUrl;
const ctx=vm.createContext({React:{createElement:(type,props,...children)=>({type,props,children})},DCLogic:class{setState(p,cb){Object.assign(this.state,typeof p==='function'?p(this.state):p);cb?.();}},
  setTimeout:()=>0,clearTimeout,window:{confirm:()=>true},crypto:{randomUUID:()=> 'submission-key'},
  fetch:async url=>{traceUrl=url;return {ok:true,json:async()=>({batches:[],sales:[]})};}});
vm.runInContext(fs.readFileSync('public/app.js','utf8')+';globalThis.App=Component;',ctx);
const app=new ctx.App(),messages=[],calls=[];
app.toast=m=>messages.push(m);app.handleSessionResponse=()=>{};app.reloadPurchasing=async()=>{};
app.state.data={BUSINESS_DATE:'2026-10-13',EXPIRY_ALERT_DAYS:14,PRODUCTS:[{id:1,name:'Milk',category:'Food',price:20,unitPrice:5,status:'Active'}],CATEGORIES:[{name:'Food',status:'Active'}],SUPPLIERS:[],BRANDS:[],SUBCATEGORIES:[],USERS:{manager:[]}};
app.state.productsLocal=app.state.data.PRODUCTS;app.state.role='manager';
const nodes=n=>!n||typeof n!=='object'?[]:Array.isArray(n)?n.flatMap(nodes):[n,...nodes(n.children)];
const text=n=>n==null?'':typeof n==='string'||typeof n==='number'?String(n):Array.isArray(n)?n.map(text).join(' '):text(n.children);
let fail=false;
app.authPost=async(url,payload,method)=>{calls.push({url,payload,method});if(fail)throw new Error('Save failed');return {message:'Saved'};};
app.reloadCatalog=async()=>{app.state.promotionsLocal=[{id:42,name:'Saved promotion'}];};
(async()=>{
  Object.assign(app.state,{promoType:'Near-Expiry',promoSelectedItems:[1],promoStartDate:'2026-10-13',promoEndDate:'2026-10-20',promoDiscountPct:'10',promotionsLocal:[]});
  fail=true;await app.savePromotion();assert.equal(app.state.promotionsLocal.length,0);assert.equal(app.state.promoDiscountPct,'10');
  fail=false;await app.savePromotion();assert.equal(calls.at(-1).url,'/api/promotions');assert.equal(app.state.promotionsLocal[0].id,42);
  Object.assign(app.state,{cart:[{productId:1,qty:2,unitPrice:17,originalPrice:20,overridden:true}],discountManagerId:1,discountManagerPin:'1234',overrideDeclineReason:'Incorrect request'});
  fail=true;await app.declinePriceOverride();assert.equal(app.state.cart[0].overridden,true);
  fail=false;await app.declinePriceOverride();assert.equal(app.state.cart[0].overridden,false);assert.equal(app.state.cart[0].unitPrice,20);assert.equal(calls.at(-1).url,'/api/price-overrides/decline');
  app.state.expiryAlertDays='30';await app.saveExpiryWindow();assert.equal(calls.at(-1).payload.days,30);assert.equal(calls.at(-1).method,'PATCH');
  const settings=app.buildExpirySettings();assert(text(settings).includes('Save alert window'));
  app.state.recallSearch='LOT 1';await app.loadRecallTrace();assert(traceUrl.endsWith('search=LOT%201'));assert(text(app.buildRecallTrace()).includes('No recorded sales'));
  const order={id:'PO1',receipts:[{id:'R1',discrepancy:true,discrepancyType:'Missing items',contacts:[{id:1,contactDate:'2026-10-13',channel:'Phone',person:'Supplier',notes:'Missing',outcome:'Replacement',actor:'Manager'}]}]};
  let tree=app.buildSupplierContacts([order]);assert(text(tree).includes('Replacement'));
  nodes(tree).find(n=>n.type==='button'&&text(n)==='Record contact').props.onClick();
  Object.assign(app.state.supplierContactForm,{person:'Supplier',notes:'Missing units',outcome:'Replacement'});await app.saveSupplierContact();assert.equal(calls.at(-1).url,'/api/receipts/R1/supplier-contacts');assert.equal(app.state.supplierContactForm,null);
  app.state.regParentId=1;app.state.regVariantLabel='Vanilla';app.setRegField('regParentId')({target:{value:''}});assert.equal(app.state.regVariantLabel,'');
  assert.equal(app.evidenceLink('ADJ-1').props.href,'/api/inventory/adjustments/ADJ-1/evidence');
  assert(app.buildReceivingFields({productId:1,name:'Milk',unitCost:5}).some(n=>nodes(n).some(i=>i.props?.['aria-label']==='Lot number (optional) for Milk')));
  console.log('Manager checklist UI: persisted promotions, failure handling, override decline, expiry settings, recall lookup, supplier contact and lot fields passed.');
})().catch(e=>{console.error(e);process.exitCode=1;});
