const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const context=vm.createContext({React:{createElement:(type,props,...children)=>({type,props,children})},DCLogic:class{},window:{}});
vm.runInContext(fs.readFileSync('public/app.js','utf8')+';globalThis.app=new Component();globalThis.measurements=PRODUCT_MEASUREMENT_UNITS;',context);
const app=context.app;app.state.data={PRODUCTS:[],CATEGORIES:[],SUPPLIERS:[],BRANDS:[],SUBCATEGORIES:[]};
const nodes=n=>!n||typeof n!=='object'?[]:Array.isArray(n)?n.flatMap(nodes):[n,...nodes(n.children||n.props?.children)];
const tree=app.buildMgrRegistration(),all=nodes(tree),find=id=>all.find(n=>n.props?.id===id);
const measurement=find('registration-regSizeUnit');assert(measurement);
assert.equal(nodes(measurement).filter(n=>n.type==='optgroup').length,5);
const options=nodes(measurement).filter(n=>n.type==='option').map(n=>n.props.value).filter(Boolean);
const php=fs.readFileSync('app/Services/ProductMeasurementUnits.php','utf8');
assert.equal(options.length,36);for(const value of options)assert(php.includes("'"+value+"'"),value);
assert(nodes(find('registration-regStockUnit')).filter(n=>n.type==='option').length>35);
assert(!find('registration-regPurchaseUnit'));assert(!find('registration-regConversionFactor'));
assert(all.some(n=>n.type==='button'&&JSON.stringify(n.children).includes('Add Category')));
assert(JSON.stringify(tree).includes('Minimum Stock'));
assert(all.indexOf(find('registration-feedback'))<all.indexOf(find('registration-regProductName')));
console.log('Registration unit choices, backend parity, simplified fields, category access and feedback placement passed.');

for(const node of all.filter(n=>['input','select'].includes(n.type)&&!n.props?.readOnly&&n.props?.id?.startsWith('registration-'))){assert.equal(node.props.required,true,node.props.id);}
let message='';app.toast=text=>{message=text;};app.saveRegistration();assert(message.includes('Complete all required fields'));assert(message.includes('Manufacturer'));assert(message.includes('Subcategory'));
console.log('Registration requires editable fields and blocks incomplete submission.');
