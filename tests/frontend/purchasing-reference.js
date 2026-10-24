// Run with the actual Component and a React createElement adapter in a browser or JS runner.
function runPurchasingReferenceChecks(Component) {
  const app=new Component();
  const assert=(ok,message)=>{if(!ok)throw Error(message);};
  const children=n=>n?.props?.children??n?.children;
  const text=n=>n==null?'':typeof n==='string'||typeof n==='number'?String(n):Array.isArray(n)?n.map(text).join(' '):text(children(n));
  const nodes=n=>!n||typeof n!=='object'?[]:Array.isArray(n)?n.flatMap(nodes):[n,...nodes(children(n))];
  const product={id:1,name:'Water',category:'Supplies',status:'Active',stock:60,stockOnHand:60,sellableStock:60,unitPrice:20,price:30,stockUnit:'Piece'};
  const receipt={id:'RCV-1',poId:'PO-1',date:'2026-10-23',remarks:'Water: 40 missing',lines:[{productId:1,name:'Water',deliveredQty:60,unitCost:20,unit:'Piece'}]};
  const order={id:'PO-1',created:'2026-10-23',status:'Partially Received',fulfillmentStatus:'Partially Received',supplierId:1,supplierName:'Water Supplier',receivingVersion:1,remarks:'Water: 40 missing',receipts:[receipt],request:{status:'Partially Received'},lines:[{id:1,productId:1,name:'Water',orderedQty:100,deliveredQty:60,resolvedQty:0,unit:'Piece',unitCost:20}]};
  app.state={...app.state,role:'manager',data:{BUSINESS_DATE:'2026-10-23',PRODUCTS:[product],SUPPLIERS:[{id:1,name:'Water Supplier'}],CATEGORIES:[{name:'Supplies'}],BRANDS:[],SUBCATEGORIES:[]},purchaseData:{orders:[order],requests:[],carryovers:[],inspections:[]},tableViews:{},supplierAccountsData:{receipts:[{...receipt,supplierName:'Water Supplier',supplierId:1,receivedValue:1200,payments:200,credits:100,balance:900,accountRemark:'Unpaid',unknownCosts:0,legacyPaidValue:0}],settlements:[]}};
  let view=app.buildPurchaseReceiving();
  assert(text(view).includes('Partial'),'Receiving status must be visible');
  assert(!text(view).includes('Carry to next delivery'),'New deliveries must not create another PO');
  const receive=nodes(view).find(n=>n.type==='button'&&text(n)==='Receive');
  assert(receive,'A partially received PO must remain receivable');receive.props.onClick();
  assert(app.state.purchaseReceivingId==='PO-1'&&app.state.receiveVersion===1,'Receive must retain PO and current version');
  app.buildPurchaseReceiving();
  app.state.purchaseReceivingId=null;
  order.status='Fully Received';order.fulfillmentStatus='Fully Received';order.lines[0].deliveredQty=100;
  assert(!nodes(app.buildPurchaseReceiving()).some(n=>n.type==='button'&&text(n)==='Receive'),'Completed quantities must not accept another receipt');
  app.state.role='manager';assert(!text(app.buildSupplierAccounts()).includes('Record Payment / Credit'),'Manager must not post supplier payments');
  app.state.role='admin';view=app.buildSupplierAccounts();
  assert(text(view).includes('900.00'),'Unpaid balance must be visible');
  assert(text(view).includes('Record Payment / Credit'),'Admin needs a settlement action');
  view=app.buildPurchaseOrderWorkspace();assert(text(view).includes('Print Supplier PO'),'Supplier PO must be printable');
  app.state.deliveryPlan={...order};app.buildPurchaseOrderWorkspace();
  for(const type of ['purchase_orders','outstanding_orders','purchases','supplier_book','payables']){app.state.reportFilters={type};app.buildAdmReports();}
  app.state.screen='mgrRequest';app.state.newReqCart=[{productId:1,name:'Water',category:'Supplies',qty:2,unit:'Piece',orderUnit:'Piece'}];
  const cost=nodes(app.buildPurchaseRequests()).find(n=>n.props?.['aria-label']==='Cost per stock unit for Water');assert(cost,'Purchase request needs unit cost input');cost.props.onChange({target:{value:'19.50'}});assert(app.state.newReqCart[0].unitCost==='19.50','Cost edit must update draft');
  return 'PASS: partial/full receiving, same PO selection, supplier account roles, delivery plans and all purchasing report screens';
}
