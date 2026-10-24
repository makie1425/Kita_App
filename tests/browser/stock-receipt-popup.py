import sys,re,json
from pathlib import Path
root=Path.cwd()
sys.path.insert(0,str(root/'.tmp/responsive-tools'))
sys.path.insert(0,str(root/'.tmp/dashboard-tools'))
from playwright.sync_api import sync_playwright
html=(root/'resources/views/kita.blade.php').read_text(encoding='utf-8')
html=html.replace('@verbatim','').replace('@endverbatim','')
html=re.sub(r'\{\{ asset\(\x27([^\x27]+)\x27\) \}\}',r'/\1',html)
html=re.sub(r'\?v=\{\{ filemtime[^}]+\}\}','',html)
html=re.sub(r'<script>window.KITA_DATA_URL.*?</script>','<script>window.KITA_DATA_URL="/api/kita-data";</script>',html,flags=re.S)
html=re.sub(r'<script>\s*window.KITA_AUTH.*?</script>','<script>window.KITA_AUTH={};</script>',html,flags=re.S)
with sync_playwright() as p:
    browser=p.chromium.launch(channel='chrome',headless=True)
    page=browser.new_page(viewport={'width':1440,'height':1050})
    errors=[]
    page.on('pageerror',lambda e: errors.append(str(e)))
    def route(r):
        path=r.request.url.split('kita.test')[-1].split('?')[0]
        if path in ['/app.js','/styles.css','/support.js']:
            content=(root/'public'/path[1:]).read_text(encoding='utf-8')
            if path=='/app.js': content=content.replace('componentDidMount() {','componentDidMount() { window.__app=this;')
            r.fulfill(body=content,content_type='text/css' if path.endswith('.css') else 'application/javascript')
        elif path.startswith('/api/'):
            r.fulfill(json={'data':[],'notifications':[],'unread_count':0})
        else:r.fulfill(body=html,content_type='text/html')
    page.route('http://kita.test/**',route)
    page.goto('http://kita.test/')
    page.wait_for_function('!!window.__app')
    page.evaluate((root/'tests/browser/responsive-fixture.js').read_text(encoding='utf-8'))

    page.evaluate("crypto.randomUUID=()=> String(Math.random()).slice(2)")
    for width in [1440,375]:
        page.set_viewport_size({'width':width,'height':950})
        page.evaluate("()=>__showScreen('mgrPO','manager')")
        page.evaluate("()=>__app.openPurchaseReceiving(__app.state.purchaseData.orders[0])")
        dialog=page.get_by_role('dialog',name='Receive delivery inspection')
        dialog.wait_for()
        delivered=dialog.locator('input[aria-label^="delivered for"]').first
        delivered.fill('5')
        dialog.locator('input[aria-label^="damaged for"]').first.fill('1')
        dialog.get_by_label('Damage reason *').fill('Crushed packaging')
        assert dialog.locator('output').first.inner_text()=='4'
        page.screenshot(path=str(root/f'.tmp/responsive-results/inspection-{width}.png'),full_page=True)
        assert dialog.evaluate('(el)=>el.getBoundingClientRect().width <= innerWidth')
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        dialog.get_by_role('button',name='Review & Confirm',exact=True).click()
        assert dialog.get_by_role('heading',name='Review stock receipt').count()==1,dialog.inner_text()
        dialog.get_by_role('button',name='Back',exact=True).click()
        assert delivered.input_value()=='5'
        dialog.get_by_role('button',name='Close receiving',exact=True).click()
    page.set_viewport_size({'width':1440,'height':950})
    page.evaluate("""()=>{
      __showScreen('mgrPO','manager');
      const old=__app.state.purchaseData.orders[0];
      old.lines.forEach(l=>l.obligation={missing:7,damaged:0,total:7,unclassified:false});
      const fresh={...old,id:'PO-NEW-30',created:'2026-09-25',receipts:[],lines:[{...old.lines[0],orderedQty:30,deliveredQty:0,resolvedQty:0,obligation:{missing:30,damaged:0,total:30,unclassified:false}}]};
      __app.setState({purchaseData:{...__app.state.purchaseData,orders:[old,fresh]}},()=>__app.openPurchaseReceiving(fresh));
    }""")
    dialog=page.get_by_role('dialog',name='Receive delivery inspection')
    dialog.locator('input[aria-label^="delivered for"]').first.fill('30')
    dialog.get_by_role('button',name='+ Add Outstanding Item',exact=True).click()
    source=dialog.locator('select[aria-label^="Original PO"]')
    source.select_option(index=1)
    dialog.locator('select[aria-label^="Outstanding product"]').select_option(index=1)
    dialog.locator('.inspection-previous-card input[aria-label^="delivered for"]').fill('5')
    assert '35 Piece' in dialog.locator('.inspection-totals').inner_text()
    dialog.get_by_role('button',name='Review & Confirm',exact=True).click()
    assert dialog.get_by_role('heading',name='Review stock receipt').count()==1
    payload=page.evaluate('()=>__app.inspectionPayload()')
    assert len(payload['entries'])==2
    assert [e['delivered'] for e in payload['entries']]==[30,5]
    assert payload['entries'][1]['type']=='missing'
    assert payload['entries'][0]['poId']!=payload['entries'][1]['poId']
    assert not errors,errors
    browser.close()
print('Inspection popup desktop/mobile calculations, review and back navigation passed. No live writes.')
