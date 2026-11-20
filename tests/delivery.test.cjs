const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const code = fs.readFileSync(require.resolve('../assets/delivery.js'),'utf8');
async function run(src, variants, set = '') {
    class Element {}
    const base='https://example.test/uploads/';
    const attrs = {src, srcset:set || null};
    const img = {dataset:{},closest:()=>null,getAttribute:k=>attrs[k],addEventListener(){},set src(v){attrs.src=v},set srcset(v){attrs.srcset=v}};
    const root = Object.assign(new Element(), {matches:()=>false,querySelectorAll:()=>[img]});
    const calls=[];
    vm.runInNewContext(code,{
        Element, URL, setTimeout, location:new URL(base),window:{qifDelivery:{base,api:'/resolve',css:false}},
        document:{documentElement:root,baseURI:base,styleSheets:[],addEventListener(){}},MutationObserver:class{observe(){}},
        fetch:async(_,options)=>{calls.push(JSON.parse(options.body).paths);return {ok:true,json:async()=>variants}}
    });
    await new Promise(resolve=>setTimeout(resolve,100));
    return {attrs,calls};
}
test('maps all responsive widths without changing descriptors', async()=>{
    const urls={'photo.jpg':{url:'/full.webp',mime:'image/webp'},'thumb.jpg':{url:'/thumb.webp',mime:'image/webp'}};
    const {attrs}=await run('photo.jpg',urls,'thumb.jpg 300w, photo.jpg 900w');
    assert.equal(attrs.src,'/full.webp');assert.equal(attrs.srcset,'/thumb.webp 300w, /full.webp 900w');
});
test('incomplete srcset retains the original',async()=>{
    const {attrs}=await run('photo.jpg',{'photo.jpg':{url:'/full.webp',mime:'image/webp'}},'missing.jpg 300w');
    assert.equal(attrs.src,'photo.jpg');assert.equal(attrs.srcset,'missing.jpg 300w');
});
test('external media bypasses resolution',async()=>{
    const {calls}=await run('https://cdn.test/a.jpg',{});assert.equal(calls.length,0);
});
