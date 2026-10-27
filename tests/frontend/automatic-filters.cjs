const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');let pending;
const ctx=vm.createContext({React:{createElement:(type,props,...children)=>({type,props,children})},DCLogic:class{setState(p,cb){Object.assign(this.state,typeof p==='function'?p(this.state):p);cb?.();}},window:{},AbortController,URLSearchParams,setTimeout:fn=>{pending=fn;return 1},clearTimeout:()=>{pending=null}});
vm.runInContext(fs.readFileSync(process.env.KITA_TEST_APP||'public/app.js','utf8')+';globalThis.app=new Component();',ctx);
const app=ctx.app;let loads=0;app.generateReport=()=>loads++;app.state.screen='admReports';app.state.reportFilters={type:'sales',productId:'1',subcategoryId:'2'};
app.setReportFilter('category','Drinks');assert.equal(app.state.reportFilters.productId,'');assert.equal(app.state.reportFilters.subcategoryId,'');assert.equal(loads,0);pending();assert.equal(loads,1);
app.resetReportFilters();pending();assert.equal(loads,2);app.clearReportDates();pending();assert.equal(loads,3);
let filters;app.loadInventoryHistory=f=>{filters=f};app.state.inventoryFilters={productId:'1'};app.setInventoryHistoryFilter('brandId','3');assert.equal(filters.brandId,'3');assert.equal(filters.productId,'');
app.openInventoryView('batches');assert.equal(app.state.screen,'mgrPO');assert.equal(app.state.inventoryView,'batches');app.buildInventoryHistory=()=> 'batch-view';assert.equal(app.buildMgrPO(),'batch-view');
console.log('PASS: filters automatically reload, dependent selections reset, and batch records open under Stock Receiving.');
