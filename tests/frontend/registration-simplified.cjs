const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const ctx=vm.createContext({React:{createElement:(type,props,...children)=>({type,props,children})},DCLogic:class{setState(p){Object.assign(this.state,typeof p==='function'?p(this.state):p)}},window:{}});
vm.runInContext(fs.readFileSync('public/app.js','utf8')+';globalThis.App=Component;',ctx);
const app=new ctx.App();app.state.data={PRODUCTS:[],SUPPLIERS:[],CATEGORIES:[],BRANDS:[],SUBCATEGORIES:[]};
const nodes=n=>!n||typeof n!=='object'?[]:Array.isArray(n)?n.flatMap(nodes):[n,...nodes(n.children)];
const text=n=>n==null?'':typeof n==='string'?n:Array.isArray(n)?n.map(text).join(' '):text(n.children);
let tree=app.buildMgrRegistration();
for(const id of ['regSupplier','regReorderLevel','regNewStockUnit','regScannedBarcode','regStatus'])assert(!nodes(tree).some(n=>n.props?.id==='registration-'+id));
assert(!nodes(tree).some(n=>n.type==='button'&&['New Product','Clear Form','+ Add Category'].includes(text(n))));
assert.equal(nodes(tree).find(n=>n.props?.id==='registration-regManufacturer').props.required,false);
for(const [id,kind,unit] of [['regSizeUnit','size','Gross'],['regStockUnit','stock','Ream']]){
 nodes(app.buildMgrRegistration()).find(n=>n.props?.id==='registration-'+id).props.onChange({target:{value:'__add__'}});
 assert.equal(app.state.regUnitDialog,kind);
 let dialog=app.buildRegistrationUnitDialog();
 nodes(dialog).find(n=>n.type==='input').props.onChange({target:{value:unit}});
 dialog=app.buildRegistrationUnitDialog();nodes(dialog).find(n=>n.type==='button'&&text(n).startsWith('Use ')).props.onClick();
 assert.equal(app.state[id],unit);assert.equal(app.state.regUnitDialog,null);
}
nodes(app.buildMgrRegistration()).find(n=>n.type==='button'&&text(n)==='Clear Form').props.onClick();
assert.equal(app.state.regStockUnit,'Piece');assert.equal(app.state.regSizeUnit,'');
Object.assign(app.state,{regProductName:'Paper',regCategory:'Supplies',regBrandId:1,regSize:'500',regSizeUnit:'Sheet'});
app.toast=()=>{};app.reloadCatalog=async()=>{};let payload;
app.authPost=async(url,data)=>{payload=data;return {product:{...data,id:1}};};app.saveRegistration();
assert(payload);assert(!Object.hasOwn(payload,'supplierId'));assert(!Object.hasOwn(payload,'minStock'));
console.log('Simplified registration, optional manufacturer, unit popup, clear form and deferred inventory settings passed.');
