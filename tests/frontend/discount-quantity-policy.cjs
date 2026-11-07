const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('public/app.js','utf8');
const nodes=n=>!n||typeof n!=='object'?[]:Array.isArray(n)?n.flatMap(nodes):[n,...nodes(n.children)];
let calls=[];
const product={id:1,name:'Hotdog Buns',category:'Food',status:'Active',price:83.75,stock:10,sellableStock:10,promotionExpiry:'2026-12-07'};
const promotion={id:1,type:'Near-Expiry',productIds:[1],discountPct:20,startDate:'2026-11-07',endDate:'2026-12-31'};
const context={React:{createElement:(type,props,...children)=>({type,props,children})},DCLogic:class{setState(p,cb){Object.assign(this.state,typeof p==='function'?p(this.state):p);cb?.();}},URLSearchParams,AbortController,setTimeout,clearTimeout,window:{},crypto:{randomUUID:()=> 'key'},fetch:async url=>{
 calls.push(url);const qty=Number(new URL(url,'http://localhost').searchParams.get('quantity'));
 return {ok:qty<=10,status:qty<=10?200:422,json:async()=>qty<=10?{products:[{...product}],activePromotions:[promotion],businessDate:'2026-11-07',checkedAt:'now'}:{message:'Only 10 sellable units are available.'}};
}};
vm.createContext(context);vm.runInContext(source+';globalThis.App=Component;',context);const app=new context.App();app.toast=()=>{};
app.state.data={PRODUCTS:[product],CATEGORIES:[{name:'Food'}],BRANDS:[],SUBCATEGORIES:[],USERS:{manager:[]},BUSINESS_DATE:'2026-11-07'};app.state.productsLocal=[product];app.state.promotionsLocal=[promotion];app.state.role='manager';app.state.authenticatedUser={id:1,role:'manager'};
app.state.cart=[{lineId:11,productId:1,name:product.name,qty:1,unitPrice:83.75,originalPrice:83.75,promo:promotion}];
(async()=>{
 const quantity=()=>nodes(app.buildPos()).find(n=>n.type==='input'&&n.props['aria-label']==='Quantity for Hotdog Buns');
 assert.equal(quantity().props.inputMode,'numeric');assert.equal(quantity().props.type,'text');
 quantity().props.onChange({target:{value:'3'}});assert.equal(app.state.cart[0].qty,1);assert.equal(calls.length,0);
 await quantity().props.onBlur({target:{value:'3'}});assert.equal(app.state.cart[0].qty,3);assert.equal(calls.length,1);
 for(const value of ['','0','-1','1.5','1e2','abc','1000001']){const before=calls.length;await app.updateCartQuantity(11,value);assert.equal(app.state.cart[0].qty,3);assert.equal(calls.length,before);assert.ok(app.state.quantityErrors[11]);}
 await app.updateCartQuantity(11,'11');assert.equal(app.state.cart[0].qty,3);assert.match(app.state.quantityErrors[11],/Only 10/);
 await app.updateCartQuantity(11,'1');assert.equal(app.state.cart[0].qty,1);assert.equal(app.state.quantityErrors[11],undefined);
 await app.stepQty(11,1)();assert.equal(app.state.cart[0].qty,2);await app.stepQty(11,-1)();assert.equal(app.state.cart[0].qty,1);
 let completed=0;app.finalizeTransaction=()=>completed++;
 app.setCartQuantityDraft(11)({target:{value:'0'}});await app.startPayment();assert.equal(completed,0);assert.equal(app.state.cart[0].qty,1);assert.ok(app.state.quantityErrors[11]);
 await app.updateCartQuantity(11,'1');app.setCartQuantityDraft(11)({target:{value:'2'}});await app.startPayment();assert.equal(completed,0);assert.equal(app.state.cart[0].qty,2);await app.updateCartQuantity(11,'1');
 app.state.seniorForm={name:'Customer',idNumber:'SC-1',type:'senior'};app.applySeniorPwd();let totals=app.computeTotals();assert.equal(totals.promotionDiscount,16.75);assert.equal(totals.customerDiscount,6.7);assert.equal(totals.grandTotal,60.3);assert.equal(totals.discount,23.45);assert.equal(app.discountApprovalPayload().discountType,'senior');
 app.state.seniorForm.type='pwd';app.applySeniorPwd();assert.equal(app.discountApprovalPayload().discountType,'pwd');assert.equal(app.computeTotals().grandTotal,60.3);
 app.state.cart[0].qty=3;totals=app.computeTotals();assert.equal(totals.grandTotal,180.9);assert.equal(totals.promotionDiscount,50.25);assert.equal(totals.customerDiscount,20.1);
 app.state.cart=[{productId:1,qty:3,unitPrice:.05,originalPrice:.05,promo:{discountPct:10}}];totals=app.computeTotals();assert.equal(totals.grandTotal,.13);assert.equal(totals.promotionDiscount,.01);assert.equal(totals.customerDiscount,.01);
 app.state.cart=[{productId:1,qty:1,unitPrice:83.75,originalPrice:83.75,promo:promotion}];app.toggleEmployeeDiscount();assert.equal(app.state.seniorApplied,false);assert.equal(app.computeTotals().grandTotal,75.38);
 for(const [expiry,allowed] of [['2026-11-06',false],['2026-11-07',false],['2026-11-08',true],['2026-11-09',true],['2026-11-14',true],['2026-12-07',true],['2026-12-08',true],['2027-01-06',true]])assert.equal(!app.promotionReason({...product,promotionExpiry:expiry},'Near-Expiry'),allowed,expiry);
 assert.equal(app.promotionReason({...product,promotionExpiry:'2026-11-08'},'Seasonal'),'');
 assert.equal(app.promotionReason({...product,promotionExpiry:'2026-12-08'},'Seasonal'),'');
 app.state.promoType='Near-Expiry';app.state.promoPickerSearch='';app.state.promoPickerChecked={};app.state.data.PRODUCTS=[{...product,promotionExpiry:'2026-11-07'}];
 const picker=app.buildPromoPickerModal();assert.ok(!nodes(picker).some(n=>n.type==='input'&&n.props.type==='checkbox'));assert.ok(JSON.stringify(picker).includes('No near-expiry products available.'));
 for(const expiry of ['2026-11-08','2026-11-09','2026-11-14','2026-12-07','2026-12-08','2027-01-06']){
  const selected={...product,promotionExpiry:expiry};app.state.data.PRODUCTS=[selected];app.state.productsLocal=[selected];app.state.promotionsLocal=[];app.state.promoPickerChecked={};
  app.state.promoType=expiry<='2026-12-07'?'Near-Expiry':'Seasonal';
  assert.equal(nodes(app.buildPromoPickerModal()).find(n=>n.type==='input'&&n.props.type==='checkbox').props.disabled,false);
  app.togglePromoPickerCheck(1)();app.confirmPromoPicker();assert.deepEqual(Array.from(app.state.promoSelectedItems),[1]);
  Object.assign(app.state,{promoStartDate:'2026-11-07',promoEndDate:'2026-11-07',promoDiscountPct:'20'});let saved=0;app.authPost=async(path,body)=>{assert.equal(path,'/api/promotions');assert.equal(body.type,expiry<='2026-12-07'?'Near-Expiry':'Seasonal');assert.deepEqual(Array.from(body.productIds),[1]);saved++;};app.reloadCatalog=async()=>{};await app.savePromotion();assert.equal(saved,1);
  app.state.promotionsLocal=[promotion];assert.equal(app.activePromo(1)?.id,1);
 }
 app.state.data.PRODUCTS=[{...product,promotionExpiry:'2026-11-08'}];app.state.data.EXPIRING_BATCHES=[{id:10,productId:1,name:'Hotdog Buns',expiryDate:'2026-11-08',quantityRemaining:10}];app.state.data.SALES_LOG=[];app.state.productsLocal=app.state.data.PRODUCTS;app.state.promotionsLocal=[];
 const useItem=nodes(app.buildMgrPromotions()).find(n=>n.type==='button'&&JSON.stringify(n.children).includes('Use This Item'));assert.ok(useItem);useItem.props.onClick();assert.deepEqual(Array.from(app.state.promoSelectedItems),[1]);
 const quickCreate=nodes(app.buildNearExpiredWidget(true)).find(n=>n.type==='button'&&JSON.stringify(n.children).includes('Create promotion'));assert.ok(quickCreate);quickCreate.props.onClick();assert.deepEqual(Array.from(app.state.promoSelectedItems),[1]);
 app.state.data.BUSINESS_DATE='2026-11-08';assert.equal(app.activePromo(1),null);assert.match(app.promotionReason(app.state.data.PRODUCTS[0],'Near-Expiry'),/expired/);assert.ok(!nodes(app.buildNearExpiredWidget(true)).some(n=>n.type==='button'&&JSON.stringify(n.children).includes('Create promotion')));app.state.data.BUSINESS_DATE='2026-11-07';
 assert.match(app.promotionReason({...product,promotionExpiry:null},'Near-Expiry'),/recorded expiration date/);
 app.state.productsLocal=[product];app.state.promotionsLocal=[promotion];
 app.state.role='manager';app.state.accountsLocal=[];app.state.acctModalOpen=true;app.state.acctModalMode='create';app.state.acctForm={role:'cashier',status:'Active'};
 const account=app.buildMgrCashiers();assert.ok(!nodes(account).some(n=>n.type==='select'&&JSON.stringify(n.children).includes('All roles')));assert.ok(!nodes(account).some(n=>n.props?.key==='role'));
 app.state.screen='mgrSupplier';app.state.supMgrScreen='detail';app.state.supMgrDetailId=1;await app.goScreen('mgrSupplier')();assert.equal(app.state.supMgrScreen,'list');assert.equal(app.state.supMgrDetailId,null);
 // Simulate the custom runtime's immutable state updates and lifecycle hook.
 app.state.cart=[{lineId:11,productId:1,name:product.name,qty:1,unitPrice:83.75,originalPrice:83.75,promo:promotion}];app.state.data.PRODUCTS=[product];app.state.productsLocal=[product];app.state.seniorApplied=true;app.state.seniorInfo={name:'Customer',idNumber:'SC-1',type:'senior'};app.state.employeeDiscount=false;app.state.discountManagerId=1;app.state.discountManagerPin='1234';app.state.paymentMethod='cash';app.state.tendered='100';app.state.quantityErrors={};app.state.quantityDrafts={};app.previousAppState=app.state;
 app.setState=(patch,callback)=>{app.state={...app.state,...(typeof patch==='function'?patch(app.state):patch)};app.componentDidUpdate();callback?.();};
 let approvedSales=0;app.finalizeTransaction=()=>approvedSales++;await app.startPayment();assert.equal(approvedSales,1);assert.equal(app.state.discountManagerPin,'1234');
 app.setState({cart:app.state.cart.map(l=>({...l,qty:2}))});assert.equal(app.state.discountManagerPin,'');
 console.log('Policy UI passed: numeric quantity with +/- and stock limits, sequential 20% then 10%, multi-quantity and centavo rounding, mutually exclusive benefits, 1/2/7/30/31/60-day expiry boundaries, disabled promotion selection, fixed Cashier role, and sidebar list navigation.');
})().catch(e=>{console.error(e);process.exitCode=1;});
