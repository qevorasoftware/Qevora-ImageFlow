const {execFileSync}=require('node:child_process');
const {mkdtempSync,readFileSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const {join}=require('node:path');
const assert=require('node:assert/strict');
const dir=mkdtempSync(join(tmpdir(),'qif-inspect-'));
try {
    const file=join(dir,'page.html');
    writeFileSync(file,`<!doctype html><body>
    <div data-elementor-id="42"><div data-id="hero" data-widget_type="image.default"><img width="80" height="40" class="wp-image-7"></div></div>
    <aside id="qif-audit"><span role="status"></span><button data-task="scan"></button><button data-task="pick"></button><button data-task="baseline"></button><button data-task="compare"></button><div id="qif-audit-results"></div></aside>
    <script>
    const base='https://example.test/uploads/',src=base+'hero.jpg',alt=base+'qevora-imageflow/7/hero.webp';
    const img=document.querySelector('img');
    img.dataset.qifOriginal=src;
    let repaired=false;
    Object.defineProperty(img,'currentSrc',{get:()=>repaired?alt:src});
    window.qifInspector={base,ajax:'mock',preferred:'webp',delivery:true,user:1,budgets:[{template:42,widget:"hero",width:0,kib:1}]};
    performance.getEntriesByType=()=>[src,alt].map(name=>({name}));
    window.fetch=async(url,{body})=>{
        const action=body.get('action');
        let data;
        if(action==='qif_inspect_paths') data={'hero.jpg':{id:7,size:'full',width:1000,height:500,bytes:4000,originalBytes:8000,sizes:[{name:'thumbnail',width:200,height:100}],url:repaired?alt:'',repair:!repaired,reason:'Missing'}};
        if(action==='qif_inspect_fix'){if(body.get('id')!=='7'||body.get('path')!=='hero.jpg')throw Error('Wrong repair target');repaired=true;data='Verified preferred copy';}
        if(action==='qif_inspect_record'){const [r]=JSON.parse(body.get('items'));if(r.id!==7||!r.where.includes('Template 42'))throw Error('Wrong usage record');data='Saved';}
        return {json:async()=>({success:true,data})};
    };
    </script><script>${readFileSync('assets/inspector.js','utf8')}</script><script>
    (async()=>{
       const click=async selector=>{document.querySelector(selector).click();await new Promise(r=>setTimeout(r,20));};
       const check=t=>{if(!document.body.innerText.includes(t))throw Error(t);};
       await click('[data-task=baseline]');check('Baseline saved');
       document.querySelector('details').open=true;
       await click('details button');check('Verified preferred copy');
       await click('[data-task=compare]');check('1 changes');check('Preferred');
       await click('[data-task=scan]');check('1 with paired requests');check('1 over budget');document.querySelector('details').open=true;check('Try thumbnail');check('Over budget');
       document.body.dataset.verified='yes';
    })().catch(e=>document.body.dataset.error=e.message);
    </script>`);
    const html=execFileSync(process.env.CHROME_BIN || 'google-chrome',['--headless','--no-sandbox','--disable-gpu','--virtual-time-budget=4000','--dump-dom','file://'+file],{encoding:'utf8',stdio:['ignore','pipe','pipe']});
    assert.match(html,/<body data-verified="yes">/);
} finally {rmSync(dir,{recursive:true,force:true});}
