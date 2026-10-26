const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/app.js', 'utf8');
const lifecycle = source.slice(source.indexOf('  componentDidUpdate('), source.indexOf('  syncNavigationViewport='));
const context = vm.createContext({});
vm.runInContext(`globalThis.Harness = class {
  state = {screen: 'admDashboard'};
  requests = 0;
  generateReport() { this.requests++; this.setState({reportLoading: true}); }
  setState(patch) { this.state = {...this.state, ...patch}; this.componentDidUpdate({}); }
  ${lifecycle}
}`, context);

const app = new context.Harness();
// Match support.js: componentDidUpdate receives props only, never prevState.
assert.doesNotThrow(() => app.componentDidUpdate({}));
app.setState({screen: 'admReports'});
assert.equal(app.requests, 0, 'Opening Reports waits for Refresh');
app.setState({reportLoading: false, reportResult: {rows: []}});
app.setState({reportFilterOpen: 'category'});
assert.equal(app.requests, 0, 'Rendering results and opening filters must not reload');
app.setState({screen: 'admDashboard'});
app.setState({screen: 'admReports'});
assert.equal(app.requests, 0, 'Returning to Reports waits for Refresh');
app.generateReport();
assert.equal(app.requests, 1, 'Explicit Refresh requests data');
app.setState({discountManagerPin: '1234', cart: []});
assert.equal(app.state.discountManagerPin, '', 'Cart changes still clear the discount authorization');
console.log('Report lifecycle regression checks passed.');

// Mounting the app must not schedule automatic refreshes on any module.
const timers=[];
const pollingContext=vm.createContext({
  DCLogic:class {}, React:{}, window:{addEventListener(){}},
  setInterval:(callback)=>{timers.push(callback);return timers.length;},
});
vm.runInContext(source+';globalThis.app=new Component();',pollingContext);
const pollingApp=pollingContext.app;
pollingApp.syncNavigationViewport=()=>{};
for(const screen of ['mgrInventory','admReports','admDashboard','mgrPO','admNotifications']) {
  Object.assign(pollingApp.state,{role:'admin',screen});
  pollingApp.componentDidMount();
  assert.equal(timers.length,0,screen+' must not start background polling');
}
console.log('All modules remain free of scheduled background refreshes.');
