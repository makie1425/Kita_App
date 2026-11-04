const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/app.js','utf8');
function setup(){
 const context={React:{createElement:(tag,props,...children)=>({tag,props,children})},DCLogic:class{},window:{},document:{hidden:false},console,setTimeout,clearTimeout,setInterval,clearInterval};
 vm.createContext(context);vm.runInContext(source+'\nglobalThis.App=Component;',context);
 const app=new context.App();app.setState=function(value){Object.assign(this.state,typeof value==='function'?value(this.state):value);};
 return {app,context};
}
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
 app.state.purchaseItemDraft={supplierId:'7',productId:'1',qty:2,orderUnit:'Box',unitCost:10};
 let modal=app.buildItemPickerModal();assert.equal(modal.tag,'dialog');
 nodes(modal).find(n=>n.tag==='button'&&JSON.stringify(n.children).includes('Add Item')).props.onClick();
 assert.equal(app.state.newReqCart.length,0);assert.ok(app.state.purchaseItemError);
 app.state.purchaseItemDraft.stockUnitsPerOrder=12;
 nodes(app.buildItemPickerModal()).find(n=>n.tag==='button'&&JSON.stringify(n.children).includes('Add Item')).props.onClick();
 assert.equal(app.state.newReqCart.length,1);assert.equal(app.state.newReqCart[0].stockUnitsPerOrder,12);assert.equal(app.state.newReqSupplier,'7');
 const populated=JSON.stringify(app.buildPurchaseRequests());assert.ok(populated.includes('240.00'));
});
