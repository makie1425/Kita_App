const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
let printed='';
const ctx=vm.createContext({React:{createElement:(type,props,...children)=>({type,props,children})},DCLogic:class{setState(p){Object.assign(this.state,typeof p==='function'?p(this.state):p)}},window:{open:()=>({document:{write:html=>printed=html,close(){}},opener:null})}});
vm.runInContext(fs.readFileSync('public/app.js','utf8')+';globalThis.App=Component;',ctx);
const app=new ctx.App();
const nodes=n=>!n||typeof n!=='object'?[]:Array.isArray(n)?n.flatMap(nodes):[n,...nodes(n.children)];
const text=n=>n==null?'':typeof n==='string'?n:Array.isArray(n)?n.map(text).join(' '):text(n.children);
const paper={id:1,name:'Bond paper',category:'Supplies',supplierId:1,status:'Active',size:1,sizeUnit:'Gross',stockUnit:'Ream',unitPrice:20,stock:0};
app.state.data={PRODUCTS:[paper,{id:2,name:'Juice',category:'Drinks',supplierId:3,status:'Active'}],CATEGORIES:[{name:'Supplies',status:'Active'},{name:'Drinks',status:'Active'}],BRANDS:[],SUBCATEGORIES:[],SUPPLIERS:[
 {id:1,name:'Paper seller',category:'Supplies',status:'Active'},
 {id:2,name:'Another paper seller',status:'Active',products:[{productId:1,costPrice:18}]},
 {id:3,name:'Drink seller',category:'Drinks',status:'Active'},
 {id:4,name:'Pen seller',category:'Supplies',status:'Active'},
 {id:5,name:'Inactive seller',category:'Supplies',status:'Inactive',products:[{productId:1}]}
]};
app.state.purchaseItemDraft={productId:1,supplierId:1};
let picker=app.buildItemPickerModal();
const supplierSelect=tree=>nodes(tree).find(n=>n.type==='label'&&nodes(n).some(c=>c.type==='span'&&text(c)==='Supplier'));
assert(text(supplierSelect(picker)).includes('Paper seller'));
assert(text(supplierSelect(picker)).includes('Another paper seller'));
for(const excluded of ['Drink seller','Pen seller','Inactive seller'])assert(!text(supplierSelect(picker)).includes(excluded));
nodes(picker).find(n=>n.props?.['aria-label']==='Supply Category').props.onChange({target:{value:'Drinks'}});
assert.equal(app.state.purchaseItemDraft.productId,undefined,'Changing category clears a stale product');
picker=app.buildItemPickerModal();assert(text(supplierSelect(picker)).includes('Drink seller'));assert(!text(supplierSelect(picker)).includes('Paper seller'));
app.state.regCategory='Supplies';
let registration=app.buildMgrRegistration();
for(const [id,unit] of [['registration-regStockUnit','Ream'],['registration-regSizeUnit','Gross']])assert(nodes(registration).some(n=>n.props?.id===id&&nodes(n).some(option=>option.type==='option'&&option.props.value===unit)),'Saved custom units are reusable');
app.state.regSupplier='Paper seller';app.setRegField('regCategory')({target:{value:'Drinks'}});assert.equal(app.state.regSupplier,'');
app.state.authenticatedUser={name:'Manager <One>'};app.state.inventoryHistory={products:[],checkedAt:'2026-11-23'};
app.printInventoryHistory('products')();assert(printed.includes('Prepared by: Manager &lt;One&gt;'));assert(!printed.includes('Manager <One>'));
console.log('Supplier product/category filtering, stale selection clearing, reusable units and escaped manager print name passed.');
