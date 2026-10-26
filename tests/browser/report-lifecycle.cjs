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

// Background polling must not redraw the Reports screen between manual refreshes.
const timers=[];
const pollingContext=vm.createContext({
  DCLogic:class {}, React:{}, window:{addEventListener(){}},
  setInterval:(callback)=>{timers.push(callback);return timers.length;},
});
vm.runInContext(source+';globalThis.app=new Component();',pollingContext);
const pollingApp=pollingContext.app;
let stockCalls=0,notificationCalls=0;
pollingApp.syncNavigationViewport=()=>{};
pollingApp.refreshStockAvailability=()=>{stockCalls++;return Promise.resolve([]);};
pollingApp.reloadNotifications=()=>{notificationCalls++;};
Object.assign(pollingApp.state,{role:'admin',screen:'admReports'});
pollingApp.componentDidMount();
timers.forEach(callback=>callback());
assert.equal(stockCalls,0,'Stock polling is paused on Reports');
assert.equal(notificationCalls,0,'Notification polling is paused on Reports');
pollingApp.state.screen='admDashboard';
timers.forEach(callback=>callback());
assert.equal(stockCalls,1,'Stock polling resumes outside Reports');
assert.equal(notificationCalls,1,'Notification polling resumes outside Reports');
console.log('Report background polling checks passed.');
