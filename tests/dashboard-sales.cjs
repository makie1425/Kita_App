const fs = require('fs');
const assert = require('assert/strict');
const React = {createElement: (type, props, ...children) => ({type, props, children})};
class DCLogic { setState(update) { Object.assign(this.state, update); } }
const {Component, dashboardSales} = new Function('DCLogic', 'React', fs.readFileSync('public/app.js', 'utf8') + '\nreturn {Component, dashboardSales};')(DCLogic, React);
const dates = ['2025-12-31', '2026-01-01', '2026-10-18', '2026-10-19', '2026-10-22', '2026-10-25', '2026-10-26', '2026-10-31', '2026-11-01', '2027-01-01'];
const data = {BUSINESS_DATE:'2026-10-22', SALES_LOG:dates.map((date,i)=>({date, hour:10, productId:1, qty:2, amount:i+1})), PRODUCTS:[{id:1,name:'Milk',status:'Active',stock:10,minStock:1}], EXPIRING_BATCHES:[]};
for(const [period, expected] of [['daily',[4]],['weekly',[3,4,5]],['monthly',[2,3,4,5,6,7]],['yearly',[1,2,3,4,5,6,7,8]]]) {
  const result = dashboardSales(data, period, '');
  assert.deepEqual(result.rows.map(row=>row.date), expected.map(i=>dates[i]));
  assert.equal(result.total, expected.reduce((sum,i)=>sum+2*(i+1),0));
  assert.equal(result.units, expected.length*2);
  assert.equal(result.series.reduce((sum,point)=>sum+point.value,0), result.total);
  assert.equal(result.series.length, {daily:24,weekly:7,monthly:31,yearly:12}[period]);
}
assert.equal(dashboardSales(data,'daily','').series[10].value,10);
assert.equal(dashboardSales(data,'daily','').series[9].value,0);
assert.equal(dashboardSales(data,'monthly','2024-02-29').series.length,29);
assert.deepEqual(dashboardSales(data,'weekly','2026-01-01').series.map(point=>point.key),['2025-12-29','2025-12-30','2025-12-31','2026-01-01','2026-01-02','2026-01-03','2026-01-04']);
assert.equal(dashboardSales(data,'weekly','2026-01-01').range,'2025-12-29 to 2026-01-04');
assert.equal(dashboardSales(data,'weekly','2026-10-25').range,'2026-10-19 to 2026-10-25');
assert.equal(dashboardSales(data,'monthly','2024-02-29').range,'2024-02-01 to 2024-02-29');
assert.equal(dashboardSales(data,'daily','2024-02-29').total,0);
assert.equal(dashboardSales(data,'yearly','2025-12-31').total,2);
assert.equal(dashboardSales({},'daily','').total,0);
const app = new Component();
Object.assign(app.state,{data,itemRequestsLocal:[]});
app.buildManagerPin=()=>null;
function flatten(node) { return !node||typeof node!=='object'?[]:[node,...(node.children||[]).flat(Infinity).flatMap(flatten)]; }
for(const build of ['buildMgrDashboard','buildAdmDashboard']) {
  app.setState({dashboardPeriod:'daily',dashboardDate:''});
  const initial = app[build]();
  assert.equal(flatten(initial).filter(node=>node.type==='polyline').length,1);
  assert.equal(flatten(initial).filter(node=>node.props?.className==='sales-ranking').length,2);
  assert.equal(flatten(initial).filter(node=>node.props?.className==='dashboard-metrics').length,1);
  assert(JSON.stringify(initial).includes('Purchase Orders Tracking'));
  const periodButton=flatten(initial).find(node=>node.type==='button'&&node.children.includes('Monthly'));
  periodButton.props.onClick();
  assert.equal(app.state.dashboardPeriod,'monthly');
  assert(!JSON.stringify(initial).includes('NaN'));
  if(build==='buildMgrDashboard') {
    const promo=flatten(initial).find(node=>node.type==='button'&&node.children.includes('Create promotion'));
    promo.props.onClick();
    assert.deepEqual(app.state.promoSelectedItems,[1]);
  }
  const select = flatten(initial).find(node=>node.type==='select');
  assert.deepEqual(select.children.flat().map(node=>node.props.value),['daily','weekly','monthly','yearly']);
  select.props.onChange({target:{value:'weekly'}});
  const weekly = app[build]();
  assert.notEqual(flatten(initial).find(node=>node.type==='polyline').props.points,flatten(weekly).find(node=>node.type==='polyline').props.points);
  assert(JSON.stringify(weekly).includes('2026-10-19 to 2026-10-25'));
  assert.notEqual(JSON.stringify(initial),JSON.stringify(weekly));
  const date = flatten(weekly).find(node=>node.type==='input'&&node.props.type==='date');
  date.props.onChange({target:{value:'2024-02-29'}});
  assert(JSON.stringify(app[build]()).includes('No sales recorded'));
}
console.log('Dashboard sales checks passed: all periods, boundaries, leap year, empty data, and both dashboard controls.');
