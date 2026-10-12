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
assert.equal(app.requests, 1, 'Opening Reports automatically requests its data');
app.setState({reportLoading: false, reportResult: {rows: []}});
app.setState({reportFilterOpen: 'category'});
assert.equal(app.requests, 1, 'Rendering results and opening filters must not reload');
app.setState({screen: 'admDashboard'});
app.setState({screen: 'admReports'});
assert.equal(app.requests, 2, 'Returning to Reports refreshes the data');
app.setState({discountManagerPin: '1234', cart: []});
assert.equal(app.state.discountManagerPin, '', 'Cart changes still clear the discount authorization');
console.log('Report lifecycle regression checks passed.');
