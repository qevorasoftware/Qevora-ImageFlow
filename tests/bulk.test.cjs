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
