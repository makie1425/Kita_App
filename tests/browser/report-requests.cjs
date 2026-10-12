const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/app.js', 'utf8');
const pending = [];
const timers = new Set();
const context = vm.createContext({
  AbortController, URLSearchParams,
  setTimeout(callback) { timers.add(callback); return callback; },
  clearTimeout(callback) { timers.delete(callback); },
  fetch(url, options) {
    return new Promise((resolve, reject) => {
      options.signal.addEventListener('abort', () => reject(Object.assign(new Error('Aborted'), {name: 'AbortError'})));
      pending.push({url, resolve});
    });
  },
});
vm.runInContext(`globalThis.Harness = class {
  state = {tableViews: {'generated-report': {query:'old search', page:3}}, reportFilters: {type:'inventory'}};
  setState(patch) { this.state={...this.state,...(typeof patch==='function'?patch(this.state):patch)}; }
  ${source.slice(source.indexOf('  generateReport=async()=>{'), source.indexOf('  buildAdmReports(){'))}
}`, context);
async function main() {
  const app = new context.Harness();
  const report = {columns:{Product:'product'},rows:[{product:'Sample'}]};
  const first = app.generateReport();
  pending[0].resolve({ok:true,json:async()=>report});
  await first;
  assert.equal(app.state.reportLoading,false);
  assert.equal(app.state.tableViews['generated-report'].query,'');
  const stalled = app.generateReport();
  for(const timeout of timers) timeout();
  await stalled;
  assert.equal(app.state.reportLoading,false);
  assert.match(app.state.reportError,/timed out/);
  assert.equal(app.state.reportResult,report,'Timeout must retain the last report');
  const superseded=app.generateReport();
  const latest=app.generateReport();
  await superseded;
  assert.equal(app.state.reportLoading,true,'Cancelled older request must not end the latest loading state');
  pending.at(-1).resolve({ok:true,json:async()=>({...report,rows:[]})});
  await latest;
  assert.equal(app.state.reportResult.rows.length,0,'A real empty result must not show stale rows');
  assert.equal(app.state.reportLoading,false);
  assert.equal(app.state.reportError,'');
  assert.equal(timers.size,0);
  console.log('Report timeout, cancellation, empty results and search reset checks passed.');
}
main().catch(error=>{ console.error(error); process.exitCode=1; });
