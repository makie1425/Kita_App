const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('public/app.js','utf8');
const nodes=n=>!n||typeof n!=='object'?[]:Array.isArray(n)?n.flatMap(nodes):[n,...nodes(n.children)];
const text=n=>n==null?'':typeof n!=='object'?String(n):Array.isArray(n)?n.map(text).join(' '):text(n.children);
const defer=()=>{let resolve;return {promise:new Promise(r=>resolve=r),resolve:v=>resolve(v)};};
function setup(){
 const products=[{id:1,name:'Rice',barcode:'RICE',category:'Food',status:'Active',price:20,stock:20,sellableStock:20},{id:2,name:'Soap',category:'Care',status:'Active',price:15,stock:10,sellableStock:10}];
 const server={calls:[],gate:null,fail:false};
 const fetch=async(url,options)=>{const query=new URL(url,'http://localhost').searchParams;const ids=query.getAll('productIds[]');server.calls.push({ids,full:!ids.length&&!query.has('productId')});if(server.gate)await server.gate.promise;if(server.fail)throw new Error('Network unavailable');return {ok:true,status:200,json:async()=>({products:products.filter(p=>ids.includes(String(p.id))),activePromotions:[],businessDate:'2026-11-07',checkedAt:'now'})};};
 const context={React:{createElement:(type,props,...children)=>({type,props,children})},DCLogic:class{setState(p,cb){Object.assign(this.state,typeof p==='function'?p(this.state):p);cb?.();}},fetch,URLSearchParams,AbortController,setTimeout,clearTimeout,window:{},crypto:{randomUUID:()=> '12345678-1234-1234-1234-123456789012'}};
 vm.createContext(context);vm.runInContext(source+';globalThis.App=Component;',context);const app=new context.App(),messages=[];
 app.toast=m=>messages.push(m);app.state.data={PRODUCTS:products.map(p=>({...p})),CATEGORIES:[{name:'Food'},{name:'Care'}],BRANDS:[],SUBCATEGORIES:[],USERS:{manager:[]}};app.state.productsLocal=app.state.data.PRODUCTS;app.state.authenticatedUser={id:1,role:'cashier',name:'Cashier'};app.state.role='cashier';app.state.promotionsLocal=[];app.state.paymentMethod='cash';app.state.tendered='100';app.reloadCatalog=async()=>{};
 return {app,server,products,messages};
}
(async()=>{
 let {app,server,products}=setup();
 await Promise.all([app.addProductToCart(products[0]),app.addProductToCart(products[0]),app.addProductToCart(products[0])]);
 assert.equal(server.calls.length,1);assert.deepEqual(server.calls[0].ids,['1']);assert.equal(app.state.cart[0].qty,3);assert.equal(app.state.stockSelectionPending,0);
 // Scans arriving during a slow request collect into one following batch.
 let slow=setup();const slowGate=defer();slow.server.gate=slowGate;
 const initial=slow.app.addProductToCart(slow.products[0]);await Promise.resolve();
 const following=Array.from({length:5},()=>slow.app.addProductToCart(slow.products[0]));slowGate.resolve();await Promise.all([initial,...following]);
 assert.equal(slow.server.calls.length,2);assert.equal(slow.app.state.cart[0].qty,6);
 let s=setup();({app,server,products}=s);const gate=defer();server.gate=gate;
 const first=app.addProductToCart(products[0],true,'button');const second=app.addProductToCart(products[0],true,'button');await Promise.resolve();
 const adding=nodes(app.buildPos()).find(n=>n.type==='button'&&text(n)==='Adding...');assert.equal(adding.props.disabled,true);
 gate.resolve();await Promise.all([first,second]);assert.equal(app.state.cart[0].qty,1);assert.equal(server.calls.length,1);
 server.gate=null;server.fail=true;const saved=JSON.stringify(app.state.cart);await app.addProductToCart(products[1]);
 assert.equal(JSON.stringify(app.state.cart),saved);assert.equal(app.state.tendered,'100');assert.equal(app.state.stockSelectionPending,0);assert.match(app.state.checkoutStockError,/Network/);
 server.fail=false;let completed=0;app.finalizeTransaction=()=>completed++;await app.retryStockCheck();assert.equal(completed,0);assert.equal(app.state.checkoutStockError,'');
 const paymentGate=defer();server.gate=paymentGate;const before=server.calls.length;const paying=app.startPayment();await Promise.resolve();
 const pay=nodes(app.buildPos()).find(n=>n.type==='button'&&text(n)==='Checking Stock...');assert.equal(pay.props.disabled,true);await app.startPayment();assert.equal(server.calls.length,before+1);assert.deepEqual(server.calls.at(-1).ids,['1']);
 paymentGate.resolve();await paying;assert.equal(completed,1);assert.equal(app.state.checkoutStockChecking,false);assert.ok(!s.messages.some(m=>/Wait for the stock/.test(m)));
 // A failed final check cannot complete a sale and can be retried without losing tender.
 server.gate=null;server.fail=true;await app.startPayment();assert.equal(completed,1);assert.equal(app.state.checkoutStockChecking,false);assert.equal(app.state.tendered,'100');
 server.fail=false;await app.retryStockCheck();assert.equal(completed,1);assert.equal(app.state.checkoutStockError,'');
 // Late work after clearing the sale cannot add items or corrupt pending counts.
 s=setup();({app,server,products}=s);server.gate=defer();const pending=app.addProductToCart(products[0]);await Promise.resolve();app.newSale();server.gate.resolve();await pending;assert.equal(app.state.cart.length,0);assert.equal(app.state.stockSelectionPending,0);assert.equal(app.stockSelections,0);
 // React can apply state asynchronously: scans must still produce one line and correct quantity.
 s=setup();({app,server,products}=s);const applyState=app.setState.bind(app);app.setState=(patch,callback)=>setImmediate(()=>applyState(patch,callback));
 await Promise.all([app.addProductToCart(products[0]),app.addProductToCart(products[0]),app.addProductToCart(products[0])]);
 assert.equal(app.state.cart.length,1);assert.equal(app.state.cart[0].qty,3);
 // A new server balance must block payment even if the displayed catalog previously had stock.
 let blocked=0;app.finalizeTransaction=()=>blocked++;products[0].sellableStock=0;await app.startPayment();assert.equal(blocked,0);
 // Search remains local and only creates twenty product rows, even for a large catalog.
 s=setup();({app,server}=s);app.state.productsLocal=Array.from({length:2000},(_,i)=>({id:i+1,name:'Item '+String(i).padStart(4,'0'),category:'Food',status:'Active',sellableStock:10,price:20}));app.state.manualSearch='';
 let tree=app.buildManualLookupModal();const productButtons=t=>nodes(t).filter(n=>n.type==='button'&&n.props.onClick&&n.props.style?.width==='100%'&&n.props.style?.borderBottom);
 assert.equal(productButtons(tree).length,20);assert.equal(server.calls.length,0);
 nodes(tree).find(n=>n.type==='button'&&text(n)==='Next').props.onClick();assert.equal(app.state.manualLookupPage,1);tree=app.buildManualLookupModal();assert.equal(productButtons(tree).length,20);assert.ok(text(tree).includes('Item 0020'));
 app.setManualSearch({target:{value:'Item 1999'}});tree=app.buildManualLookupModal();assert.equal(app.state.manualLookupPage,0);assert.equal(productButtons(tree).length,1);assert.equal(server.calls.length,0);
 console.log('Checkout performance verified: 3 rapid scans use 1 scoped request; duplicate Add is ignored; payment checks only the cart; failures/retries preserve it; stale responses stay out; lookup renders 20 of 2,000 products without a request.');
})().catch(e=>{console.error(e);process.exitCode=1;});
