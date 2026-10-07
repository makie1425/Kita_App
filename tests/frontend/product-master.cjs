const fs=require('fs'),vm=require('vm'),assert=require('assert');
const ctx=vm.createContext({URLSearchParams,React:{createElement:(type,props,...children)=>({type,props,children})},DCLogic:class{setState(p){Object.assign(this.state,typeof p==='function'?p(this.state):p)}},window:{},crypto:{randomUUID:()=> 'test'}});
vm.runInContext(fs.readFileSync('public/app.js','utf8')+';globalThis.App=Component;',ctx);
const app=new ctx.App();
const product={id:1,name:'Coke',category:'Beverages',stock:0,minStock:10,price:80,unitPrice:55,unit:'Bottle',stockUnit:'Bottle',purchaseUnit:'Bottle',conversionFactor:1,status:'Active',barcode:'COKE',size:1,sizeUnit:'Liter',brandId:1,subcategoryId:1};
app.state.data={PRODUCTS:[product],CATEGORIES:[{name:'Beverages',status:'Active',classification:'Non-Perishable'}],BRANDS:[{id:1,name:'Coca-Cola',status:'Active'}],SUBCATEGORIES:[{id:1,name:'Soft Drinks',category:'Beverages',status:'Active'}],SUPPLIERS:[],ADJUSTMENTS:[],PURCHASE_ORDERS:[],RECEIVING_RECORDS:[],BATCH_RECALL:[]};
Object.assign(app.state,{suppliersLocal:[],productsLocal:[product],categoriesLocal:app.state.data.CATEGORIES,adjustmentsLocal:[],purchaseData:{requests:[],orders:[]},inventoryHistory:{products:[product],batches:[],movements:[]},screen:'mgrRequest'});
const text=node=>node==null?'':typeof node==='string'?node:Array.isArray(node)?node.map(text).join(' '):text(node.children);
const inputs=node=>node==null||typeof node!=='object'?[]:Array.isArray(node)?node.flatMap(inputs):[...(node.type==='input'?[node.props.id]:[]),...inputs(node.children)];
const reg=app.buildMgrRegistration();assert(!inputs(reg).includes('registration-regQuantity'));assert(inputs(reg).includes('registration-regSize'));assert(inputs(reg).includes('registration-regReorderLevel'));
assert(!inputs(reg).includes('registration-regStockId'),'Stock ID is generated automatically when adding');
assert(!text(app.buildPurchaseRequests()).includes('Add New Product'));
for(const method of ['buildMgrCategories','buildInventoryHistory','buildManualLookupModal','buildItemPickerModal'])assert(app[method](),method);
app.state.invTab='stock';assert(app.buildMgrInventory());
app.editProductMaster(product);assert(text(app.buildMgrRegistration()).includes('Edit Product'));
console.log('Product master, purchasing, inventory, history and lookup screens rendered; no initial stock control, size/reorder fields and editing verified.');

// Exercise the rendered controls across modules with unrelated and inactive subcategories.
app.state.data.CATEGORIES.push({name:'Snacks',status:'Active'}, {name:'Other',status:'Active'});
app.state.data.SUBCATEGORIES.push({id:2,name:'Chips',category:'Snacks',status:'Active'}, {id:3,name:'Old soda',category:'Beverages',status:'Inactive'});
app.state.data.PRODUCTS.push({...product,id:2,name:'Crisps',category:'Snacks',subcategoryId:2});
app.state.productsLocal=app.state.data.PRODUCTS;
app.state.suppliersLocal=[{products:[{productId:1},{productId:2}]}];
const nodes=node=>node==null||typeof node!=='object'?[]:Array.isArray(node)?node.flatMap(nodes):[node,...nodes(node.children)];
const subSelect=tree=>nodes(tree).find(n=>n.type==='select'&&n.props['aria-label']==='Subcategory');
const categorySelect=tree=>nodes(tree).find(n=>n.type==='select'&&nodes(n.children).some(o=>o.type==='option'&&o.props.value==='Beverages'));
const change=(control,value)=>control.props.onChange({target:{value}});
for(const [method,categoryKey,subKey] of [
  ['buildMgrRegistration','regCategory','regSubcategoryId'],
  ['buildManualLookupModal','manualCategory','manualSubcategoryId'],
  ['buildItemPickerModal','itemPickerFilterCategory','itemPickerSubcategoryId'],
  ['buildMgrInventory','inventoryCategory','inventorySubcategoryId'],
]){
  Object.assign(app.state,{[categoryKey]:'Beverages',[subKey]:'1'});
  let tree=app[method]();
  assert(text(subSelect(tree)).includes('Soft Drinks'),method);
  assert(!text(subSelect(tree)).includes('Chips'),method);
  if(method!=='buildMgrInventory')assert(!text(subSelect(tree)).includes('Old soda'),method);
  change(categorySelect(tree),'Snacks');
  assert.strictEqual(app.state[subKey],'',method+' clears old selection');
  tree=app[method]();
  assert(text(subSelect(tree)).includes('Chips'),method);
  assert(!text(subSelect(tree)).includes('Soft Drinks'),method);
  change(subSelect(tree),'2');
  if(method!=='buildMgrRegistration'){
    tree=app[method]();assert(text(tree).includes('Crisps'),method);assert(!text(tree).includes('Coke'),method);
  }
  change(categorySelect(tree),'');
  assert(subSelect(app[method]()).props.disabled,method+' requires category first');
}
app.state.inventoryFilters={category:'Beverages',subcategoryId:'1',productId:'1'};
let historyTree=app.buildInventoryHistory();
assert(text(subSelect(historyTree)).includes('Old soda'),'History retains inactive subcategories');
assert(!text(subSelect(historyTree)).includes('Chips'));
change(categorySelect(historyTree),'Snacks');
assert.strictEqual(app.state.inventoryFilters.subcategoryId,'');
assert.strictEqual(app.state.inventoryFilters.productId,'');
assert(text(subSelect(app.buildInventoryHistory())).includes('Chips'));
app.state.regCategory='Other';
assert(subSelect(app.buildMgrRegistration()).props.disabled);
assert(text(subSelect(app.buildMgrRegistration())).includes('No subcategories'));
console.log('Dependent subcategory options, resets, empty states and product filtering verified across all five modules.');

app.state.reportFilters={type:'sales',category:'Beverages',brandId:'1',subcategoryId:'1',productId:'1'};
let reportTree=app.buildAdmReports();
assert(text(reportTree).includes('Generate report'));
assert(text(reportTree).includes('From date'));
app.setReportFilter('category','Snacks');
assert.strictEqual(app.state.reportFilters.subcategoryId,'');
assert.strictEqual(app.state.reportFilters.productId,'');
app.setReportFilter('from','2026-10-01');
app.setReportFilter('type','inventory');
assert.strictEqual(app.state.reportFilters.from,'');
assert(nodes(app.buildAdmReports()).filter(n=>n.type==='input'&&n.props.type==='date').every(n=>n.props.disabled));
app.state.reportResult={title:'Sales lines',generated:'2026-10-06',rows:[],columns:{Product:'product'},labels:{Brand:'Coca-Cola'},note:'Test report'};
app.state.reportAppliedFilters={type:'sales',brandId:'1',from:'2026-10-01'};
app.state.reportFilters={type:'inventory',brandId:'2'};
reportTree=app.buildAdmReports();
const links=nodes(reportTree).filter(n=>n.type==='a');
assert(links.some(n=>n.props.href.includes('format=print')));
assert(links.some(n=>n.props.href.includes('format=csv')));
assert(links.some(n=>n.props.href.includes('format=pdf')&&n.props.download===true));
assert(links.every(n=>n.props.href.includes('brandId=1')&&n.props.href.includes('type=sales')),'Exports retain generated filters');
assert(text(reportTree).includes('No records match'));
console.log('Report filters, date semantics, empty state and export filter consistency verified.');
const actionGroups=nodes(app.buildAdmReports()).filter(n=>n.props?.className?.includes('action-group'));
assert(actionGroups.length>=2,'Report form and exports have separate action rows');
const inline=ctx.React.createElement('td',null,[ctx.React.createElement('button',{onClick:()=>{}},'Edit'),ctx.React.createElement('button',{onClick:()=>{}},'Deactivate')]);
assert(nodes(inline).some(n=>n.props?.className==='action-group'),'Legacy table actions share a spaced wrapper');
const existingFlex=ctx.React.createElement('div',{style:{display:'flex',gap:4}},[ctx.React.createElement('button',null,'One'),ctx.React.createElement('button',null,'Two')]);
assert(!nodes(existingFlex).some(n=>n.props?.className==='action-group'),'Existing flex layouts are not nested in extra action rows');
console.log('Shared action grouping and direct PDF download controls verified.');
