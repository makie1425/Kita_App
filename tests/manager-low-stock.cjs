const fs = require('fs');
const assert = require('assert/strict');
const React = {createElement: (type, props, ...children) => ({type, props, children})};
class DCLogic {
  setState(update) { this.state = {...this.state, ...(typeof update === 'function' ? update(this.state) : update)}; }
}
const Component = new Function('DCLogic', 'React', 'window', fs.readFileSync('public/app.js', 'utf8') + '\nreturn Component;')(DCLogic, React, {KITA_AUTH: {}});
const app = new Component();
const low = {id: 1, name: 'Gloves', category: 'Supplies', stock: 2, minStock: 5, unit: 'Box', supplierId: 1, status: 'Active'};
app.state = {...app.state, role: 'manager', itemRequestsLocal: [], data: {
  PRODUCTS: [low, {...low, id: 2, name: 'Masks', stock: 0}, {...low, id: 3, stock: 10},
    {...low, id: 4, status: 'Inactive'}, {...low, id: 5, archivedAt: '2026-01-01'}],
  SUPPLIERS: [{id: 1, name: 'Supplier', status: 'Active'}], SALES_LOG: []
}};
const messages = [];
app.toast = message => messages.push(message);
app.reloadPurchasing = async () => {};
assert.deepEqual(app.managerLowStock().map(p => p.id), [2, 1]);
const view = JSON.stringify(app.buildMgrDashboard());
assert(view.includes('Low Stock Products'));
assert(view.includes('Purchase'));
app.purchaseLowStock(1)();
assert.equal(app.state.screen, 'mgrRequest');
assert.equal(app.state.newReqSupplier, '1');
assert.equal(app.state.newReqCart[0].qty, 4);
assert.equal(app.state.newReqCart[0].unit, 'Box');
app.purchaseLowStock(1)();
assert.equal(app.state.newReqCart.length, 1);
app.purchaseLowStock(2)();
assert.equal(app.state.newReqCart.length, 2);
app.state.data.SUPPLIERS.push({id: 2, status: 'Active'});
app.state.data.PRODUCTS.push({...low, id: 6, supplierId: 2});
app.purchaseLowStock(6)();
assert.equal(app.state.newReqCart.length, 2);
assert.equal(app.state.newReqSupplier, '1');
assert(messages.at(-1).includes('another supplier'));
app.state.newReqCart = []; app.state.newReqSupplier = '';
app.state.data.PRODUCTS.push({...low, id: 7, supplierId: null});
app.purchaseLowStock(7)();
assert.equal(app.state.newReqSupplier, '');
assert(messages.at(-1).includes('Select a supplier'));
app.purchaseLowStock(4)();
assert.equal(app.state.newReqCart.length, 1);
app.state.data.PRODUCTS = [];
assert(JSON.stringify(app.buildMgrDashboard()).includes('No low-stock products.'));
console.log('Manager low-stock checks passed: filtering, rendering, draft quantities, deduplication, supplier conflicts, missing supplier and empty state.');
