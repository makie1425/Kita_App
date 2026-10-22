const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = vm.createContext({
  React: {createElement: (type, props, ...children) => ({type, props: {...props, children}})},
  DCLogic: class {}, URLSearchParams,
});
vm.runInContext(fs.readFileSync('public/app.js','utf8')+';globalThis.app=new Component();',context);
const app=context.app;
app.state.data={PRODUCTS:[],CATEGORIES:[],SUPPLIERS:[],SUBCATEGORIES:[]};
const nodes=(node)=>!node||typeof node!=='object'?[]:Array.isArray(node)?node.flatMap(nodes):[node,...nodes(node.props?.children)];
const text=node=>!node?'':typeof node!=='object'?String(node):Array.isArray(node)?node.map(text).join(' '):text(node.props?.children);
for(const type of ['sales','inventory','receipts','movements','expiry']) {
  app.state.reportFilters={type};
  for(const state of [
    {reportResult:null,reportLoading:true,reportError:'',message:'Loading report records'},
    {reportResult:null,reportLoading:false,reportError:'Request failed',message:'Unable to update'},
    {reportResult:{title:'Test',columns:{Product:'product'},rows:[],labels:{},summary:{}},reportLoading:false,reportError:'',message:'No results found'},
    {reportResult:{title:'Test',columns:{Product:'product'},rows:[{product:'Visible product'}],labels:{},summary:{}},reportLoading:true,reportError:'',message:'Visible product'},
  ]) {
    Object.assign(app.state,state);
    const tree=app.buildAdmReports();
    assert.equal(nodes(tree).filter(n=>n.type==='table').length,1);
    assert.ok(nodes(tree).some(n=>n.type==='th'));
    assert.ok(text(tree).includes(state.message));
    assert.equal(nodes(tree).filter(n=>n.type==='a').length,state.reportResult?3:0,'Exports require loaded results');
  }
}
console.log('Permanent report table: all four report types passed loading, error, empty and retained-row checks.');
