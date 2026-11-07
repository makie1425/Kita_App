const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/app.js','utf8');
function setup(realElements=false){
 const context={React:{createElement:(tag,props,...children)=>({tag,...(realElements?{type:tag}:{}),props,children})},DCLogic:class{},window:{},document:{hidden:false},console,AbortController,URLSearchParams,setTimeout,clearTimeout,setInterval,clearInterval};
 vm.createContext(context);vm.runInContext(source+'\nglobalThis.App=Component;',context);
 const app=new context.App();app.setState=function(value){Object.assign(this.state,typeof value==='function'?value(this.state):value);};
 return {app,context};
}
function uiNodes(v){return !v||typeof v!=='object'?[]:Array.isArray(v)?v.flatMap(uiNodes):[v,...uiNodes(v.children)];}
function purchaseOptions(app){app.setState({productPickerOpen:true});return uiNodes(app.buildItemPickerModal()).filter(n=>n.props?.role==='option');}
function choosePurchaseProduct(app,id){const option=purchaseOptions(app).find(n=>String(n.props['data-product-id'])===String(id));assert.ok(option,'Product must be present in the filtered list');option.props.onClick();}
test('category popup accepts a name and classification and advances to subcategory',async()=>{
 const {app}=setup();app.openCategoryModal();
 app.state.data={CATEGORIES:[],SUBCATEGORIES:[]};
 app.setCategoryField('name')({target:{value:'beverages'}});
 app.setCategoryField('classification')({target:{value:'Non-Perishable'}});
 let calls=0;app.authPost=async(url,body,method)=>{calls++;assert.equal(url,'/api/categories');assert.equal(method,'POST');assert.equal(body.name,'beverages');return {category:{...body}};};
 app.toast=()=>{};app.reloadCatalog=async()=>{};
 await app.saveCategory();assert.equal(calls,1);assert.equal(app.state.categoryStep,'subcategory');assert.equal(app.state.categoryError,'');
});
test('category popup rejects missing name or classification before submission',async()=>{
 for(const form of [{name:' ',classification:'Non-Perishable'},{name:'beverages',classification:''}]){
  const {app}=setup();app.openCategoryModal();app.state.categoryForm=form;
  app.authPost=async()=>assert.fail('Invalid category must not be submitted');
  await app.saveCategory();assert.equal(app.state.categoryError,'Enter a category name and classification.');
 }
});
test('monitoring layout formats storage in GB and opens native error dialog',()=>{
 const {app}=setup();app.state.saDashboard={storage:{used_bytes:1500000000,limit_bytes:3000000000,scope:'Filesystem'},memory:null,rpm:[],availability:null,errors:[],measured_at:'2026-11-03T00:00:00Z'};
 app.state.monitoringError={id:1,message:'Operation failed',component:'Application',severity:'Error',occurred_at:'2026-11-03 00:00:00',status:'New'};
 const tree=JSON.stringify(app.buildSaDashboard());assert.ok(tree.includes('1.50 GB'));assert.ok(tree.includes('"tag":"dialog"'));assert.ok(!tree.includes('Monitoring Environment'));
});
test('dashboard renders only operational cards, chart gaps and sanitized error details',()=>{
 const {app}=setup();app.state.saDashboard={storage:null,memory:null,rpm:Array.from({length:30},(_,i)=>({requests:i===15?null:i})),availability:null,errors:[],measured_at:'2026-11-03T00:00:00Z'};
 const tree=JSON.stringify(app.buildSaDashboard());
 for(const label of ['Storage Consumption','RAM Consumption','Requests Per Minute','System Uptime','System Downtime','System Status','Recent Error Logs','Unavailable'])assert.ok(tree.includes(label),label);
 for(const label of ['Total Users','Audit Events','Recorded User Activity','Database query','Dashboard calculation'])assert.ok(!tree.includes(label),label);
 assert.equal((tree.match(/"tag":"polyline"/g)||[]).length,2);
});
test('provider availability uses the reported percentage and period, including unavailable summaries',()=>{
 const {app}=setup();app.state.saDashboard={storage:null,memory:null,rpm:[],errors:[],availability:{source:'Better Stack',uptime_percentage:99.5,downtime_seconds:120,status:'Operational',window:'Better Stack: since monitor creation',observed_at:'2026-11-03T00:00:00Z'}};
 let tree=JSON.stringify(app.buildSaDashboard());
 assert.ok(tree.includes('99.50%'));assert.ok(tree.includes('since monitor creation'));assert.ok(!tree.includes('last 24 hours'));
 app.state.saDashboard.availability.uptime_percentage=null;
 tree=JSON.stringify(app.buildSaDashboard());assert.ok(!tree.includes('0.00%'));assert.ok(tree.includes('Unavailable'));
});
test('authenticated Admin shell renders dashboard without the shared workspace selector',()=>{
 const {app}=setup();
 Object.assign(app.state,{role:'admin',authStep:'in',screen:'admDashboard',data:{},currentWorkspace:12,assignedWorkspaces:[{id:12,name:'Assigned workspace'}]});
 app.currentUser=()=>({name:'Admin'});
 app.buildAdmDashboard=()=>({tag:'section',props:{},children:['Admin dashboard content']});
 const result=app.renderVals();
 assert.equal(result.showApp,true);
 const tree=JSON.stringify(result.activeScreen);
 assert.ok(!tree.includes('Select workspace'));
 assert.ok(!tree.includes('"aria-label":"Workspace"'));
 assert.ok(tree.includes('Admin dashboard content'));
 assert.equal(app.state.currentWorkspace,12);
});
test('business matrix excludes privileged role and Super Admin account form excludes Employee ID',()=>{
 const {app}=setup();assert.deepEqual(Array.from(app.defaultRolesMatrix().roles),['Admin','Manager','Cashier']);
 app.state.role='superadmin';app.state.accountsLocal=[];app.state.acctModalOpen=true;app.state.acctModalMode='create';app.state.acctForm={role:'admin',status:'Active'};
 const tree=JSON.stringify(app.buildAdmManagers());assert.ok(!tree.includes('Employee ID'));assert.ok(!tree.includes('Schedule (optional)'));assert.ok(!tree.includes('Email / Username'));
});
test('refresh is protected from overlap and stops on unmount',async()=>{
 const {app,context}=setup();let calls=0,resolve;context.fetch=()=>{calls++;return new Promise(r=>resolve=r);};app.state.role='superadmin';
 const first=app.reloadSaDashboard();await app.reloadSaDashboard();assert.equal(calls,1);
 resolve({ok:true,status:200,json:async()=>({errors:[]})});await first;assert.equal(app.monitoringBusy,false);
 let cleared=false;context.clearInterval=()=>cleared=true;context.window.removeEventListener=()=>{};app.componentWillUnmount();assert.ok(cleared);
});

test('product comparison uses paired unit bars and handles empty sales',()=>{
 const {app}=setup();app.state.data={PRODUCTS:[{id:1,name:'Rice'},{id:2,name:'Milk'}]};
 const tree=JSON.stringify(app.buildProductComparison({rows:[{productId:1,qty:8},{productId:1,qty:2},{productId:2,qty:3}]}));
 assert.ok(tree.includes('Rice: 10 units'));assert.ok(tree.includes('Milk: 3 units'));
 assert.ok(tree.includes('comparison-vertical-bar is-top'));assert.ok(tree.includes('comparison-vertical-bar is-least'));
 assert.ok(!tree.includes('comparison-track'));
 assert.ok(JSON.stringify(app.buildProductComparison({rows:[]})).includes('No sales recorded for this period.'));
});

test('purchase tracking has three steps and replaces approved with rejected',()=>{
 const {app}=setup();
 for(const [record,status] of [[{status:'Pending Approval'},'Pending'],[{status:'Pending Approval',revision:1},'Review'],[{status:'Approved / Waiting for Delivery',poId:'PO1'},'Approved'],[{status:'Declined',disapprovalReason:'Budget exceeded'},'Rejected']]){
  const tree=JSON.stringify(app.buildPurchaseTracking(record));
  assert.equal((tree.match(/"tag":"li"/g)||[]).length,3);
  assert.ok(tree.includes('"'+status+'"'));
  if(status==='Rejected'){assert.ok(!tree.includes('"Approved"'));assert.ok(tree.includes('Budget exceeded'));}
 }
});

test('cancelled order replaces the approved endpoint even with an approved request',()=>{
 const {app}=setup();const tree=JSON.stringify(app.buildPurchaseTracking({status:'Cancelled',supplierCallback:{reason:'Supplier cancelled'}},{status:'Approved',poId:'PO1'}));
 assert.equal((tree.match(/"tag":"li"/g)||[]).length,3);assert.ok(tree.includes('"Cancel"'));assert.ok(!tree.includes('"Approved"'));assert.ok(tree.includes('Supplier cancelled'));
});

test('purchase form removes expected delivery and adds a validated packaged catalog item',()=>{
 const {app}=setup();
 app.state.screen='mgrRequest';app.state.newReqCart=[];
 app.state.data={PRODUCTS:[{id:1,name:'Rice',category:'Grocery',stockUnit:'Piece',unitPrice:10,supplierId:7,status:'Active'}],SUPPLIERS:[{id:7,name:'Supply Co',status:'Active'}]};
 const initial=JSON.stringify(app.buildPurchaseRequests());
 assert.ok(!initial.includes('Expected delivery'));assert.ok(initial.includes('Subtotal (PHP)'));assert.ok(initial.includes('Add First Item'));
 const nodes=(v)=>!v||typeof v!=='object'?[]:Array.isArray(v)?v.flatMap(nodes):[v,...nodes(v.children)];
 app.state.purchaseItemDraft={supplierId:'7',category:'Grocery',subcategoryId:'none',productId:'1',qty:2,orderUnit:'Box',unitCost:10};
 let modal=app.buildItemPickerModal();assert.equal(modal.tag,'dialog');
 nodes(modal).find(n=>n.tag==='button'&&JSON.stringify(n.children).includes('Add Product')).props.onClick();
 assert.equal(app.state.newReqCart.length,0);assert.ok(app.state.purchaseItemError);
 app.state.purchaseItemDraft.stockUnitsPerOrder=12;
 nodes(app.buildItemPickerModal()).find(n=>n.tag==='button'&&JSON.stringify(n.children).includes('Add Product')).props.onClick();
 assert.equal(app.state.newReqCart.length,1);assert.equal(app.state.newReqCart[0].stockUnitsPerOrder,12);assert.equal(app.state.newReqSupplier,'7');
 const populated=JSON.stringify(app.buildPurchaseRequests());assert.ok(populated.includes('240.00'));
});

test('product-first PO selects category and eligible suppliers',()=>{
 const {app}=setup();app.state.newReqCart=[];
 app.state.data={PRODUCTS:[{id:1,name:'Rice',category:'Food',subcategoryId:10,supplierId:7,status:'Active',sellableStock:2,minStock:10,unitPrice:30},{id:2,name:'Soap',category:'Care',supplierId:8,status:'Active',sellableStock:20,minStock:5}],SUPPLIERS:[{id:7,name:'Supply',status:'Active',products:[{productId:1,costPrice:25}]},{id:8,name:'Other',status:'Active',products:[]}],SUBCATEGORIES:[{id:10,name:'Grains'}]};
 const nodes=v=>!v||typeof v!=='object'?[]:Array.isArray(v)?v.flatMap(nodes):[v,...nodes(v.children)];
 const field=label=>nodes(app.buildItemPickerModal()).find(n=>n.tag==='label'&&JSON.stringify(n.children[0]).includes('"'+label+'"')).children.flat(Infinity).find(n=>['select','input'].includes(n?.tag));
 assert.ok(JSON.stringify(purchaseOptions(app)).includes('Low Stock'));
 choosePurchaseProduct(app,1);
 assert.equal(field('Category').props.value,'Food');assert.equal(field('Category').props.readOnly,true);
 assert.equal(field('Subcategory').props.value,'Grains');assert.equal(field('Supplier').props.value,'7');
 assert.equal(app.state.purchaseItemDraft.qty,8);assert.equal(app.state.purchaseItemDraft.unitCost,25);
 app.state.data.SUPPLIERS[1].products=[{productId:1,costPrice:28}];
 field('Supplier').props.onChange({target:{value:''}});
 choosePurchaseProduct(app,1);assert.equal(field('Supplier').props.value,'');
 field('Supplier').props.onChange({target:{value:'8'}});assert.equal(app.state.purchaseItemDraft.unitCost,28);
 app.state.newReqCart=[{productId:1}];app.state.newReqSupplier='7';assert.ok(!purchaseOptions(app).some(n=>String(n.props['data-product-id'])==='2'));app.state.purchaseItemDraft={productId:2,supplierId:'7',qty:1,unitCost:10};
 nodes(app.buildItemPickerModal()).find(n=>n.tag==='button'&&JSON.stringify(n.children).includes('Add Product')).props.onClick();
 assert.equal(app.state.newReqCart.length,1);assert.ok(app.state.purchaseItemError.includes('separate PO'));
});

test('cashier accounts hide role selector and suppliers use labeled boxed navigation',()=>{
 const {app}=setup();app.state.role='manager';app.state.accountsLocal=[];app.state.acctModalOpen=true;app.state.acctModalMode='create';app.state.acctForm={role:'cashier',status:'Active'};
 let tree=JSON.stringify(app.buildMgrCashiers());assert.ok(!tree.includes('All roles'));assert.ok(!tree.includes('"key":"role"'));assert.ok(tree.includes('Create Account'));
 app.state.supMgrForm={name:'Supplier',paymentTerms:'30 Days Credit'};
 tree=JSON.stringify(app.buildMgrSupplierModal());assert.ok(!tree.includes('Payment Terms'));assert.ok(!tree.includes('30 Days Credit'));
 app.state.data={SUPPLIERS:[{id:1,name:'Supplier',status:'Active',callbackLog:[]}]};
 tree=JSON.stringify(app.buildAdmSuppliers());assert.ok(tree.includes('View'));assert.ok(tree.includes('kita-button'));assert.ok(!tree.includes('View ?'));
 app.state.supplierDetailId=1;app.buildSupplierPerformance=()=>null;
 tree=JSON.stringify(app.buildAdmSuppliers());assert.ok(!tree.includes('Back to Suppliers'));
 app.state.supplierAccountsData={receipts:[],settlements:[]};app.state.settlementForm=null;
 tree=JSON.stringify(app.buildSupplierAccounts());assert.ok(!tree.includes('Unpaid Balance'));assert.ok(tree.includes('Supplier Balance'));assert.ok(tree.includes('Historical payment and credit entries'));
});

test('General report renders separate original-column tables including empty sections',()=>{
 const {app}=setup();const calls=[];
 app.recordTable=(key,headers,rows,options)=>{calls.push({key,headers,rows,options});return null;};
 const sections=[{type:'sales',title:'Sales Transaction Detail',columns:{'Transaction No.':'reference','Line total (PHP)':'amount'},rows:[{reference:'TXN-1',amount:1234.5}],moneyKeys:['amount'],numericKeys:['amount'],summary:{}},{type:'inventory',title:'Current Inventory',columns:{Product:'product'},rows:[],summary:{}}];
 const result=app.generalReportSections(sections);
 assert.equal(result.length,2);assert.deepEqual(Array.from(calls[0].headers),['Transaction No.','Line total (PHP)']);
 assert.equal(calls[0].key,'general-sales');assert.equal(calls[1].key,'general-inventory');assert.equal(calls[1].options.emptyMessage,'No records for the selected filters.');
 assert.ok(JSON.stringify(calls[0].rows).includes('1,234.50'));
});

test('report downloads use applied dates and cannot run with stale filters',()=>{
 const {app}=setup();app.state.data={PRODUCTS:[],CATEGORIES:[],SUPPLIERS:[],BRANDS:[],SUBCATEGORIES:[]};
 app.state.reportFilters={type:'sales',from:'2026-10-06',to:'2026-10-06'};app.state.reportAppliedFilters={...app.state.reportFilters};
 app.state.reportResult={title:'Sales',columns:{Amount:'amount'},rows:[],summary:{},labels:{},moneyKeys:['amount'],numericKeys:['amount']};
 const nodes=value=>!value?[]:Array.isArray(value)?value.flatMap(nodes):typeof value==='object'?[value,...nodes(value.children)]:[];
 let links=nodes(app.buildAdmReports()).filter(n=>n.tag==='a'&&n.props?.download);
 assert.equal(links.length,3);assert.deepEqual(links.map(n=>n.children.flat().join('')),['Download PDF','Download Excel','Download CSV']);
 for(const link of links){assert.ok(link.props.href.includes('type=sales'));assert.ok(link.props.href.includes('from=2026-10-06'));assert.ok(link.props.href.includes('to=2026-10-06'));}
 app.state.reportFilters.from='2026-10-07';links=nodes(app.buildAdmReports()).filter(n=>n.tag==='a'&&n.props?.download);
 for(const link of links){assert.equal(link.props.href,undefined);assert.equal(link.props['aria-disabled'],true);}
});

test('shared confirmation requires a reason and resolves only once',async()=>{
 const {app}=setup();
 const pending=app.requestConfirmation('Cancel PO',{reason:true,title:'Cancel Remaining Quantities'});
 assert.equal(app.buildConfirmation().tag,'dialog');
 assert.equal(await app.requestConfirmation('Duplicate'),false);
 app.finishConfirmation(true);assert.ok(app.state.confirmationError);assert.ok(app.state.confirmation);
 app.state.confirmationReason='  Supplier cannot deliver  ';app.finishConfirmation(true);
 assert.equal(await pending,'Supplier cannot deliver');assert.equal(app.state.confirmation,null);
 app.finishConfirmation(true);
 const cancel=app.requestConfirmation('Receive stock');app.finishConfirmation(false);assert.equal(await cancel,false);
});
test('purchase review validation preserves entered lines and displays inline errors',async()=>{
 const {app}=setup();const record={id:'PO-1',lines:[{productId:1,qty:2}]};
 app.state.approvalNoteDraft={};app.state.purchaseReviewLines=[{productId:1,qty:2}];
 app.authPost=()=>assert.fail('Rejected requests require a reason');
 await app.reviewPurchase(record,'disapproved');assert.equal(app.state.purchaseReviewError,'Enter a rejection reason.');
 assert.equal(app.state.purchaseReviewLines.length,1);
});

test('Admin and Manager navigation distinguish requests, orders and supplier balances',()=>{
 for(const role of ['admin','manager']){
  const {app}=setup();Object.assign(app.state,{role,authStep:'in',screen:role==='admin'?'admDashboard':'mgrDashboard',data:{}});
  app.currentUser=()=>({name:'Test'});app.buildAdmDashboard=app.buildMgrDashboard=()=>null;
  const result=app.renderVals();const items=result.navGroups.flatMap(g=>g.items);
  assert.equal(items.filter(i=>i.label==='Purchase Orders').length,1);
  assert.equal(items.filter(i=>i.label==='Purchase Requests').length,1);
  assert.ok(items.some(i=>i.label==='Supplier Balances'));assert.ok(items.some(i=>i.label==='Suppliers'));
  if(role==='manager')assert.ok(items.some(i=>i.label==='Create Purchase Request'));
 }
});

test('session failure clears cart and secrets but ordinary permission errors do not log out',()=>{
 const {app,context}=setup();context.window.KITA_AUTH={user:{name:'Cashier'}};
 Object.assign(app.state,{authStep:'in',role:'cashier',cart:[{productId:1}],discountManagerPin:'1234',purchaseData:{secret:true},receivingSuccess:{id:'Private receipt'},registrationFromPurchase:true,productPickerOpen:true});
 app.handleSessionResponse({status:403},{message:'Forbidden'});assert.equal(app.state.authStep,'in');
 app.handleSessionResponse({status:401},{deactivated:true,message:'Account deactivated',csrf_token:'new'});
 assert.equal(app.state.authStep,'login');assert.equal(app.state.cart.length,0);assert.equal(app.state.discountManagerPin,'');assert.equal(app.state.purchaseData,null);assert.equal(app.state.receivingSuccess,null);assert.equal(app.state.registrationFromPurchase,false);assert.equal(app.state.productPickerOpen,false);assert.equal(context.window.KITA_AUTH.user,null);
});
test('late responses cannot restore data after session revocation',async()=>{
 const {app,context}=setup();app.state.authStep='in';let resolve;
 context.fetch=()=>new Promise(r=>resolve=r);
 const request=app.sessionFetch('/api/kita-data');
 app.endClientSession('Signed out');resolve({status:200,json:async()=>({secret:true})});
 await assert.rejects(request,/Session changed/);
});
test('session check ignores network outages and detects unauthorized reconnect',async()=>{
 const {app,context}=setup();app.state.authStep='in';
 context.fetch=async()=>{throw Error('offline');};await app.checkSession();assert.equal(app.state.authStep,'in');
 context.fetch=async()=>({status:401,json:async()=>({deactivated:true,message:'Account deactivated'})});
 await app.checkSession();assert.equal(app.state.authStep,'login');assert.equal(app.state.loginError,'Account deactivated');
});

test('creation roles are fixed and sidebar navigation preserves inventory filters',async()=>{
 for(const [role,expected] of [['superadmin','admin'],['admin','manager'],['manager','cashier']]){
  const {app}=setup();app.state.role=role;app.openAcctModal('create')();assert.equal(app.state.acctForm.role,expected);
 }
 const {app}=setup();app.state.screen='mgrInventory';app.state.inventorySupplierId='7';
 app.state.purchaseData={};await app.goScreen('mgrCategories')();assert.equal(app.state.screen,'mgrCategories');
 await app.goScreen('mgrInventory')();assert.equal(app.state.screen,'mgrInventory');assert.equal(app.state.inventorySupplierId,'7');
});

test('inventory combines supplier and category filters and exposes compact Edit and Archive actions',()=>{
 const {app}=setup();Object.assign(app.state,{role:'manager',inventorySupplierId:'2',inventoryCategory:'Food',data:{PRODUCTS:[{id:1,name:'Rice',category:'Food',supplierId:1,status:'Active',stock:0,minStock:2},{id:2,name:'Soap',category:'Household',supplierId:2,status:'Active',stock:5}],SUPPLIERS:[{id:2,name:'Linked supplier',status:'Active',products:[{productId:1}]}],CATEGORIES:[{name:'Food'}],SUBCATEGORIES:[],BRANDS:[]}});
 const captured=[];app.recordTable=(key,headers,rows)=>{captured.push({key,rows});return {tag:'table',children:rows};};
 const tree=JSON.stringify(app.buildStockBalance());assert.equal(captured[0].rows.length,1);assert.ok(tree.includes('Rice'));assert.ok(tree.includes('stock-condition-out'));assert.ok(tree.includes('Archive'));assert.ok(tree.includes('Edit'));assert.ok(!tree.includes('Edit Item'));
});

test('supplier-first filters prioritized products and resets incompatible item',()=>{
 const {app}=setup();app.state.newReqCart=[];app.state.purchaseItemDraft={};
 app.state.data={PRODUCTS:[{id:1,name:'Rice',category:'Food',supplierId:7,status:'Active',sellableStock:0,minStock:10,stockUnit:'Piece',unitPrice:30},{id:2,name:'Soap',category:'Care',supplierId:8,status:'Active',sellableStock:2,minStock:5,stockUnit:'Piece'}],SUPPLIERS:[{id:7,name:'Food Supply',status:'Active',products:[{productId:1,costPrice:25}]},{id:8,name:'Care Supply',status:'Active',products:[]}],SUBCATEGORIES:[]};
 const nodes=v=>!v||typeof v!=='object'?[]:Array.isArray(v)?v.flatMap(nodes):[v,...nodes(v.children)];
 const field=label=>nodes(app.buildItemPickerModal()).find(n=>n.tag==='label'&&JSON.stringify(n.children[0]).includes('"'+label+'"')).children.flat(Infinity).find(n=>['select','input'].includes(n?.tag));
 assert.equal(field('Supplier').props.disabled,false);
 field('Supplier').props.onChange({target:{value:'7'}});
 assert.ok(!JSON.stringify(purchaseOptions(app)).includes('Soap'));
 assert.ok(!JSON.stringify(app.buildItemPickerModal()).includes('Low Stock Urgent Suggestions'));
 choosePurchaseProduct(app,1);
 assert.equal(app.state.purchaseItemDraft.supplierId,'7');assert.equal(app.state.purchaseItemDraft.unitCost,25);
 assert.ok(JSON.stringify(app.buildItemPickerModal()).includes('250.00'));
 field('Supplier').props.onChange({target:{value:'8'}});
 assert.equal(app.state.purchaseItemDraft.productId,undefined);assert.equal(field('Category').props.value,'');
 assert.ok(!JSON.stringify(purchaseOptions(app)).includes('Rice'));
 field('Supplier').props.onChange({target:{value:''}});
 assert.ok(JSON.stringify(purchaseOptions(app)).includes('Rice'));
 app.state.newReqCart=[{productId:2}];app.state.newReqSupplier='8';
 assert.equal(field('Supplier').props.disabled,true);assert.ok(!JSON.stringify(purchaseOptions(app)).includes('Rice'));
});

test('purchase options prioritize out-of-stock and low-stock products and support keyboard selection',()=>{
 const {app}=setup(true);app.state.newReqCart=[];app.state.purchaseItemDraft={};
 app.state.data={PRODUCTS:[{id:1,name:'Apples',category:'Food',supplierId:7,status:'Active',sellableStock:20,minStock:5},{id:2,name:'Beans',category:'Food',supplierId:7,status:'Active',sellableStock:2,minStock:5},{id:3,name:'Rice',category:'Food',supplierId:7,status:'Active',sellableStock:0,minStock:5}],SUPPLIERS:[{id:7,name:'Supply',status:'Active'}],SUBCATEGORIES:[]};
 const options=purchaseOptions(app);assert.deepEqual(options.map(n=>n.props['data-product-id']),[3,2,1]);
 assert.ok(JSON.stringify(options[0]).includes('Out of Stock'));assert.ok(JSON.stringify(options[1]).includes('Low Stock'));assert.ok(!JSON.stringify(app.buildItemPickerModal()).includes('Low Stock Urgent Suggestions'));
 const trigger=uiNodes(app.buildItemPickerModal()).find(n=>n.props?.role==='combobox');
 trigger.props.onKeyDown({key:'ArrowDown',preventDefault(){}});const next=uiNodes(app.buildItemPickerModal()).find(n=>n.props?.role==='combobox');next.props.onKeyDown({key:'Enter',preventDefault(){}});
 assert.equal(app.state.purchaseItemDraft.productId,2);assert.equal(app.state.productPickerOpen,false);
});

test('tracker leaves unreached connector gray and marks final decision colors',()=>{
 const {app}=setup();const nodes=v=>!v||typeof v!=='object'?[]:Array.isArray(v)?v.flatMap(nodes):[v,...nodes(v.children)];
 let steps=nodes(app.buildPurchaseTracking({status:'Review'})).filter(n=>n.tag==='li');
 assert.ok(steps[1].props.className.includes('is-connected'));assert.ok(!steps[2].props.className.includes('is-connected'));
 for(const [status,cls] of [['Approved','is-approved'],['Rejected','is-rejected'],['Cancelled','is-cancelled']]){
  steps=nodes(app.buildPurchaseTracking({status})).filter(n=>n.tag==='li');assert.ok(steps[2].props.className.includes(cls));assert.ok(steps[2].props.className.includes('is-connected'));
 }
});
test('receipt transaction opens in a dialog with a Print Receipt action',()=>{
 const {app}=setup();app.purchasingState=()=>null;app.state.purchaseData={orders:[{id:'PO1',supplierId:7,supplierName:'Supplier',lines:[],receipts:[{id:'RCV1',date:'2026-11-06',receivedBy:'Manager',lines:[{productId:1,name:'Rice',unit:'Piece',deliveredQty:2}]}]}]};
 const nodes=v=>!v||typeof v!=='object'?[]:Array.isArray(v)?v.flatMap(nodes):[v,...nodes(v.children)];
 let tree=app.buildPurchaseHistory();assert.ok(!nodes(tree).some(n=>n.tag==='details'));
 nodes(tree).find(n=>n.tag==='button'&&n.children.flat(Infinity).includes('View Transaction')).props.onClick();
 tree=app.buildPurchaseHistory();assert.equal(nodes(tree).filter(n=>n.tag==='dialog').length,1);assert.ok(!JSON.stringify(tree).includes('View / Print Receipt'));
 assert.ok(nodes(tree).some(n=>n.tag==='a'&&n.props.href==='/receipts/RCV1/report'&&n.children.includes('Print Receipt')));
 nodes(tree).find(n=>n.tag==='dialog').props.onCancel({preventDefault(){}});assert.equal(app.state.receiptTransactionId,null);
});
test('receiving requires only actual missing fields and assigns lot metadata automatically',()=>{
 const {app}=setup();app.state.data={BUSINESS_DATE:'2026-11-06',CATEGORIES:[{name:'Food',classification:'Perishable'}]};
 app.inspectionProduct=()=>({name:'Fresh Milk',category:'Food'});app.inspectionLine=()=>({name:'Fresh Milk'});app.inspectionDue=()=>10;app.inspectionValueLimit=()=>1000;
 app.state.inspection={requestKey:'key',deliveryDate:'2026-11-06',notes:'No remarks',entries:[{key:'entry',poId:'PO1',productId:1,type:'current',delivered:2,damaged:0,unitCost:10,lots:[{quantity:2,expiryDate:''}]}]};
 assert.equal(app.reviewInspection(),false);assert.equal(Object.keys(app.state.inspectionErrors).length,1);assert.equal(app.state.receiveError,'Enter the expiration date for Fresh Milk.');
 app.state.inspection.entries[0].lots[0].expiryDate='2026-11-20';assert.equal(app.reviewInspection(),true);
 const payload=app.inspectionPayload();assert.equal(payload.automaticLots,true);assert.equal(payload.supplierReference,undefined);assert.equal(payload.entries[0].lots[0].supplierLot,null);
 app.state.inspection.entries[0].damaged=3;assert.equal(app.reviewInspection(),false);assert.equal(app.state.inspectionErrors['entries.0.damaged'],'Damaged quantity cannot exceed delivered quantity for Fresh Milk.');
});

test('unchanged purchase edit stays disabled and cannot submit a no-op request',async()=>{
 const {app}=setup();app.state.role='admin';app.state.data={PRODUCTS:[]};
 const record={id:'REQ-1',status:'Pending Approval',revision:0,supplierName:'Supply',requestedBy:'Manager',lines:[{productId:1,name:'Rice',qty:5,unit:'Piece',unitCost:10}]};
 app.state.purchaseData={requests:[record]};app.openPurchaseReview(record,'edit');
 const save=()=>uiNodes(app.purchaseReviewDetails()).find(n=>n.tag==='button'&&n.children.flat(Infinity).includes('Save edits'));
 assert.equal(save().props.disabled,true);let calls=0;app.authPost=async()=>{calls++;return {message:'Saved'};};app.toast=()=>{};app.reloadPurchasing=async()=>{};app.reloadCatalog=async()=>{};
 app.state.approvalNoteDraft={'REQ-1':'Only a reason changed'};await app.reviewPurchase(record,'modify');assert.equal(calls,0);assert.equal(save().props.disabled,true);
 app.state.purchaseReviewLines=[{...record.lines[0],confirmedQty:'4'}];assert.equal(save().props.disabled,false);
 app.state.purchaseReviewLines=[{...record.lines[0],confirmedQty:'05'}];assert.equal(save().props.disabled,true);
 app.state.purchaseReviewLines=[{...record.lines[0],confirmedQty:'4'}];await app.reviewPurchase(record,'modify');assert.equal(calls,1);
});
test('Register Product First is a plain link and preserves the purchase draft',()=>{
 const {app}=setup();app.state.data={PRODUCTS:[],SUPPLIERS:[]};app.state.newReqCart=[{productId:1,qty:3,supplierId:7}];app.state.newReqSupplier='7';app.state.purchaseNotes='Keep this draft';app.state.screen='mgrRequest';
 const original=JSON.stringify(app.state.newReqCart),link=uiNodes(app.buildItemPickerModal()).find(n=>n.tag==='a'&&n.children.includes('Register Product First'));
 assert.ok(link);assert.equal(link.props.className,'po-registration-link');link.props.onClick({preventDefault(){}});
 assert.equal(app.state.screen,'mgrRegistration');assert.equal(JSON.stringify(app.state.newReqCart),original);assert.equal(app.state.newReqSupplier,'7');
 app.continuePurchaseRegistration();assert.equal(app.state.screen,'mgrRequest');assert.equal(app.state.itemPickerOpen,true);assert.equal(app.state.purchaseNotes,'Keep this draft');
});
test('receiving defaults damage to zero and moves completed receipts to history',async()=>{
 const {app,context}=setup();context.crypto={randomUUID:()=> 'receipt-key'};app.state.data={BUSINESS_DATE:'2026-11-07',PRODUCTS:[{id:1,name:'Rice',category:'Food',stockUnit:'Piece'}],CATEGORIES:[{name:'Food',classification:'Non-Perishable'}],SUPPLIERS:[]};
 const order={id:'PO1',supplierId:7,supplierName:'Supply',created:'2026-11-01',status:'Approved',receivingVersion:0,lines:[{productId:1,name:'Rice',orderedQty:4,deliveredQty:0,unit:'Piece',unitCost:5}],receipts:[]};
 app.state.purchaseData={orders:[order],carryovers:[],stockReceipts:[]};app.purchasingState=()=>null;app.openPurchaseReceiving(order);
 assert.equal(app.state.inspection.entries[0].damaged,0);Object.assign(app.state.inspection.entries[0],{delivered:4,damaged:'',lots:[{quantity:4,noExpiry:true}]});app.state.inspection.notes='';app.state.inspectionReview=true;
 app.authPost=async(path,body)=>{assert.equal(body.entries[0].damaged,0);assert.equal(body.notes,'');return {id:'SR1',status:'Posted',message:'Stock receipt posted.'};};app.toast=()=>{};
 app.reloadCatalog=async()=>{};app.reloadPurchasing=async()=>{order.status='Fully Received';order.lines[0].deliveredQty=4;app.state.purchaseData.stockReceipts=[{id:'SR1',status:'Posted',deliveryDate:'2026-11-07',created_at:'2026-11-07 12:00:00',summary:[]}];};
 await app.submitInspection();assert.equal(app.state.purchaseReceivingId,null);assert.equal(app.state.inspection,null);assert.equal(app.state.receivingTab,'history');assert.equal(app.state.receivingSuccess.id,'SR1');
 assert.ok(JSON.stringify(app.buildPurchaseReceiving()).includes('SR1'));app.state.receivingTab='active';assert.ok(!uiNodes(app.buildPurchaseReceiving()).some(n=>n.tag==='button'&&n.children.flat(Infinity).includes('Receive delivery')));
});
test('receiving sorts latest activity first and preserves failed form entries',async()=>{
 const {app}=setup();app.purchasingState=()=>null;app.state.data={BUSINESS_DATE:'2026-11-07',PRODUCTS:[],CATEGORIES:[],SUPPLIERS:[]};
 const make=(id,created,received_at)=>({id,created,received_at,status:'Partially Received',supplierName:'Supply',lines:[{productId:1,name:'Rice',orderedQty:5,deliveredQty:2,unit:'Piece',unitCost:5}],receipts:[]});
 app.state.purchaseData={orders:[make('PO-OLDER','2026-10-01','2026-11-07 12:00:00'),make('PO-NEWER','2026-11-06',null)],carryovers:[],requests:[],stockReceipts:[]};
 const labels=uiNodes(app.buildPurchaseReceiving()).filter(n=>n.tag==='article'&&n.props?.className==='receiving-record-card').map(n=>JSON.stringify(n));assert.ok(labels[0].includes('PO-OLDER'));
 const history=JSON.stringify(app.buildDeliveryHistory([{id:'PO1',receipts:[{id:'R-OLD',deliveryBatch:'BATCH-OLD',date:'2026-11-07',received_at:'2026-11-06 10:00:00',lines:[]},{id:'R-NEW',deliveryBatch:'BATCH-NEW',date:'2026-11-05',received_at:'2026-11-07 10:00:00',lines:[]}]}]));assert.ok(history.indexOf('BATCH-NEW')<history.indexOf('BATCH-OLD'));
 const draft={primaryPo:'PO-OLDER',notes:'Keep my input',entries:[]};app.state.inspection=draft;app.state.purchaseReceivingId='PO-OLDER';app.state.inspectionReview=true;app.reviewInspection=()=>true;app.inspectionPayload=()=>({});app.authPost=async()=>{throw new Error('Delivery could not be saved');};
 await app.submitInspection();assert.equal(app.state.inspection,draft);assert.equal(app.state.purchaseReceivingId,'PO-OLDER');assert.equal(app.state.receivingSuccess,undefined);assert.match(app.state.receiveError,/could not be saved/);
});
