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
test('dashboard renders only operational cards, chart gaps and sanitized error details',()=>{
 const {app}=setup();app.state.saDashboard={storage:null,memory:null,rpm:Array.from({length:30},(_,i)=>({requests:i===15?null:i})),availability:null,errors:[],measured_at:'2026-11-03T00:00:00Z'};
 const tree=JSON.stringify(app.buildSaDashboard());
 for(const label of ['Storage Consumption','RAM Consumption','Requests Per Minute','System Uptime','System Downtime','System Status','Recent Error Logs','Unavailable'])assert.ok(tree.includes(label),label);
 for(const label of ['Total Users','Audit Events','Recorded User Activity','Database query','Dashboard calculation'])assert.ok(!tree.includes(label),label);
 assert.equal((tree.match(/"tag":"polyline"/g)||[]).length,2);
});
test('business matrix excludes privileged role and Super Admin account form excludes Employee ID',()=>{
 const {app}=setup();assert.deepEqual(Array.from(app.defaultRolesMatrix().roles),['Admin','Manager','Cashier']);
 app.state.role='superadmin';app.state.accountsLocal=[];app.state.acctModalOpen=true;app.state.acctModalMode='create';app.state.acctForm={role:'admin',status:'Active'};
 const tree=JSON.stringify(app.buildAdmManagers());assert.ok(!tree.includes('Employee ID'));assert.ok(!tree.includes('Email / Username'));
});
test('refresh is protected from overlap and stops on unmount',async()=>{
 const {app,context}=setup();let calls=0,resolve;context.fetch=()=>{calls++;return new Promise(r=>resolve=r);};app.state.role='superadmin';
 const first=app.reloadSaDashboard();await app.reloadSaDashboard();assert.equal(calls,1);
 resolve({ok:true,status:200,json:async()=>({errors:[]})});await first;assert.equal(app.monitoringBusy,false);
 let cleared=false;context.clearInterval=()=>cleared=true;context.window.removeEventListener=()=>{};app.componentWillUnmount();assert.ok(cleared);
});
