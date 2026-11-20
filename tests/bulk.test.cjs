const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

test('checked image and chosen output govern the batch request', async () => {
    const nodes = new Map();
    const field = key => {
        if (!nodes.has(key)) nodes.set(key, {});
        return nodes.get(key);
    };
    const listeners = {}, requests = [];
    let ids = [], formats = ['webp'];
    const panel = {
        querySelector: field,
        querySelectorAll: key => (key.includes('qif-id') ? ids : formats).map(value => ({value})),
        addEventListener: (name,fn) => { listeners[name]=fn; }
    };
    field('#qif-scope').value='selected';
    vm.runInNewContext(fs.readFileSync(require.resolve('../assets/admin.js'),'utf8'), {
        document:{querySelector:()=>panel}, ajaxurl:'/ajax',qifAdmin:{nonce:'test'},URLSearchParams,
        clearTimeout(){}, setTimeout(){},
        fetch: async (_,opts) => {
            requests.push(opts.body);
            return {json:async()=>({success:true,data:{state:'idle',done:0,total:0}})};
        }
    });
    const start=field('[data-qif="launch"]');
    assert.equal(start.disabled,true);
    ids=['42']; formats=[]; listeners.change();
    assert.equal(start.disabled,true);
    formats=['avif','webp']; listeners.change();
    assert.equal(start.disabled,false);
    assert.match(field('#qif-review').textContent,/1 checked images → AVIF \+ WEBP/);
    listeners.click({target:{closest:()=>({dataset:{qif:'launch'}})}});
    assert.equal(requests.at(-1).get('cmd'),'selected');
    assert.equal(requests.at(-1).get('ids'),'42');
    assert.equal(requests.at(-1).get('targets'),'avif,webp');
});

test('live progress updates a row and refreshes the filtered list at completion', async () => {
    const nodes=new Map(), field=key=>{
        if (!nodes.has(key)) nodes.set(key,{});
        return nodes.get(key);
    };
    const panel={querySelector:field,querySelectorAll:()=>[],addEventListener(){}};
    field('#qif-scope').value='selected';
    field('#qif-results').dataset={view:'bulk'};
    field('[data-attachment="42"]').querySelector=field;
    const timers=[],calls=[];
    const job=(state,done)=>({state,done,total:2,converted:done});
    const replies=[job('running',0),{...job('running',1),row:{id:42,status:'converted',formats:'WEBP',detail:'saved'}},job('complete',2),{html:'<div id="qif-results">Updated</div>'}];
    vm.runInNewContext(fs.readFileSync(require.resolve('../assets/admin.js'),'utf8'),{
        document:{querySelector:()=>panel},ajaxurl:'/admin-ajax.php',location:{href:'https://example.test/admin.php',search:'?fmt=jpeg&paged=2'},qifAdmin:{nonce:'test'}, URL,URLSearchParams,
        clearTimeout(){},setTimeout(fn){timers.push(fn);},
        fetch:async(url,{body})=>{calls.push([String(url),body.get('cmd')]);return {json:async()=>({success:true,data:replies.shift()})};}
    });
    await new Promise(setImmediate);
    await timers.shift()();
    assert.equal(field('#qif-meter').value,50);
    assert.equal(field('[data-result="formats"]').textContent,'WEBP');
    await timers.shift()();
    assert.equal(field('#qif-meter').value,100);
    assert.match(field('#qif-results').outerHTML,/Updated/);
    assert.match(calls.at(-1)[0],/fmt=jpeg&paged=2/);
    assert.deepEqual(calls.map(c=>c[1]),['status','tick','tick','results']);
});
