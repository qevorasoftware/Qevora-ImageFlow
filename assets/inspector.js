(() => {
    'use strict';
    const cfg=window.qifInspector, panel=document.getElementById('qif-audit');
    if (!cfg || !panel) return;
    const base=new URL(cfg.base), out=panel.querySelector('#qif-audit-results'), status=panel.querySelector('[role=status]');
    const pageURL=new URL(location.href);
    pageURL.searchParams.delete('qif_inspect');
    pageURL.hash='';
    const page=pageURL.pathname+['p','page_id','preview_id'].filter(k=>pageURL.searchParams.has(k)).map(k=>' '+k+'='+pageURL.searchParams.get(k)).join('');
    let picking=false, working=false, records=[], scannedWidth=0;
    function el(tag,text) { const n=document.createElement(tag); n.textContent=text; return n; }
    function abs(src) { try { return src?new URL(src,document.baseURI).href:''; } catch { return ''; } }
    function relative(src) {
        if (!src) return '';
        const url=new URL(src);
        if (url.origin!==base.origin || url.search || !url.pathname.startsWith(base.pathname)) return '';
        try {
            const path=decodeURIComponent(url.pathname.slice(base.pathname.length));
            return path.startsWith('qevora-imageflow/')?'':path;
        } catch { return ''; }
    }
    async function api(name,fields) {
        const body=new URLSearchParams({action:'qif_inspect_'+name,_ajax_nonce:cfg.nonce,...fields});
        const r=await fetch(cfg.ajax,{method:'POST',body}); const json=await r.json();
        if (!json.success) throw Error(typeof json.data==='string'?json.data:'Inspector request failed');
        return json.data;
    }
    function context(node) {
        const widget=node.closest('[data-element_type="widget"],[data-widget_type]') || node.closest('[data-id]');
        const template=node.closest('[data-elementor-id]');
        const label=widget?.dataset.widget_type || widget?.dataset.element_type || node.tagName.toLowerCase();
        return [template?'Template '+template.dataset.elementorId:'Page',label,widget?.dataset.id].filter(Boolean).join(' / ');
    }
    function collect(nodes) {
        const items=[];
        for (const node of nodes) {
            if (panel.contains(node) || node.closest('#wpadminbar')) continue;
            const box=node.getBoundingClientRect();
            if (!box.width || !box.height) continue;
            const image=node.matches('img');
            const css=image?'':getComputedStyle(node).backgroundImage;
            const urls=image?[]:[...css.matchAll(/url\(["']?([^"'()]+)["']?\)/g)].map(m=>abs(m[1]));
            if (!image && !urls.length) continue;
            const current=image?abs(node.currentSrc || node.src):'';
            const source=image?abs(node.dataset.qifOriginal || node.src):urls.find(u=>relative(u)) || urls[0];
            const srcset=image?(node.dataset.qifOriginalSet || node.getAttribute('srcset') || ''):'';
            const candidates=[...new Set([source,...srcset.split(',').map(v=>abs(v.trim().split(/\s+/)[0])),current].filter(u=>u && relative(u)))];
            const id=Number((image && node.className.match?.(/\bwp-image-(\d+)\b/) || [])[1] || 0);
            items.push({node,image,source,current,candidates,urls,id,where:context(node),box:Math.round(box.width)+' × '+Math.round(box.height)});
            if (items.length>=120) break;
        }
        return items;
    }
    function classify(item,details,requests) {
        const sources=item.candidates.map(url=>({url,info:details[relative(url)] || {}}));
        const chosen=sources.find(s=>s.info.url===item.current || s.url===item.current) || sources[0];
        const info=chosen?.info || {};
        const pairs=sources.filter(s=>s.info.url && requests.has(s.url) && requests.has(s.info.url));
        let delivered=item.current;
        if (!item.image) {
            const observed=item.urls.filter(url=>requests.has(url));
            delivered=observed.length===1?observed[0]:'';
        }
        const optimized=sources.some(s=>s.info.url && s.info.url===delivered);
        const missing=sources.filter(s=>s.info.repair);
        let reason=optimized?'Preferred copy observed':info.reason || 'No verified Media Library match';
        if (!cfg.delivery) reason='Frontend delivery is disabled in Settings';
        else if (item.image && item.node.closest('picture')) reason='Existing picture element controls delivery';
        else if (!optimized && missing.length) reason=missing[0].info.reason;
        else if (!item.image && !delivered) reason='CSS candidates visible; selected request is unconfirmed';
        else if (!optimized && info.url) reason='Preferred copy exists; browser selection has not been verified';
        return {where:item.where,box:item.box,source:chosen?.url || item.source,delivered,id:info.id || sources.find(s=>s.info.id)?.info.id || 0,
            reason,missing,pairs,kind:item.image?'Image':'Background',state:optimized?'Preferred':delivered?'Original or other':'Unconfirmed'};
    }
    function breakpoint() {
        const points=window.elementorFrontend?.config?.responsive?.activeBreakpoints || {};
        const match=Object.entries(points).filter(([,v])=>v.direction==='max' && innerWidth<=Number(v.value)).sort((a,b)=>a[1].value-b[1].value)[0];
        const wide=Object.entries(points).filter(([,v])=>v.direction==='min' && innerWidth>=Number(v.value)).sort((a,b)=>b[1].value-a[1].value)[0];
        const label=match?.[0] || wide?.[0] || (Object.keys(points).length?'desktop':'viewport');
        return label+' · '+innerWidth+'px';
    }
    function show(record) {
        const d=document.createElement('details'), title=el('summary',record.where+' · '+record.state);
        d.append(title);
        const dl=document.createElement('dl');
        for(const [k,v] of Object.entries({'Element':record.kind,'Viewport':breakpoint(),'Displayed size':record.box,'Original candidate':record.source,'Observed URL':record.delivered || 'Unconfirmed','Finding':record.reason,
            'Original + alternative requests':record.pairs.length?record.pairs.map(p=>p.url+' → '+p.info.url).join('\n'):'No matching pair observed'})) dl.append(el('dt',k),el('dd',v));
        d.append(dl);
        for(const m of record.missing) {
            const btn=el('button','Generate missing '+m.info.size+' · '+cfg.preferred.toUpperCase());
            btn.addEventListener('click',async()=>{
                btn.disabled=true;
                try { const message=await api('fix',{id:m.info.id,path:relative(m.url)}); status.textContent=message; }
                catch(e){status.textContent=e.message;}
                finally{btn.disabled=false;}
            });
            d.append(btn);
        }
        const verify=el('button','Verify again on fresh load');
        verify.addEventListener('click',()=>{sessionStorage.setItem('qif-verify',page);location.reload();});
        d.append(verify);out.append(d);return d;
    }
    async function scan(node=null) {
        const width=innerWidth;
        const items=collect(node?[node]:document.querySelectorAll('body *'));
        const paths=new Map();
        for(const item of items) for(const u of item.candidates) paths.set(relative(u),item.id || paths.get(relative(u)) || 0);
        const pending=[...paths].map(([path,id])=>({path,id})), details={};
        for(let i=0;i<pending.length;i+=24) Object.assign(details,await api('paths',{items:JSON.stringify(pending.slice(i,i+24))}));
        const requests=new Set(performance.getEntriesByType('resource').map(r=>r.name));
        const rows=items.map(i=>classify(i,details,requests));
        out.replaceChildren();
        rows.forEach(r=>{show(r).open=!!node;});
        if (!node) {
            records=rows;scannedWidth=width;
            const saved=await api('record',{page,width,items:JSON.stringify(rows.filter(r=>r.id).map(({id,where})=>({id,where})))});
            if(saved==='busy') throw Error('Scan displayed. Usage history is busy; scan again to save.');
        }
        status.textContent=rows.length+' image or background observations · '+breakpoint()+' · '+rows.filter(r=>r.pairs.length).length+' with paired requests';
    }
    function key(){return ['qif-baseline',cfg.user,page,innerWidth,devicePixelRatio].join('|');}
    function snapshot(){
        const counts=new Map();
        return records.map(r=>{
            const group=r.where+' / '+r.kind;
            const index=(counts.get(group)||0)+1;counts.set(group,index);
            return [group+' #'+index,[r.state,r.delivered || 'unconfirmed',r.box,r.pairs.length].join(' · ')];
        });
    }
    async function task(cmd,node) {
        if (working) return;
        working=true;
        try {
            if (cmd==='scan') {status.textContent='Inspecting rendered images…';await scan(node);return;}
            if (cmd==='baseline' || cmd==='compare') {
                await scan();
                if(scannedWidth!==innerWidth) throw Error('Viewport changed; scan again.');
                if(cmd==='baseline') {localStorage.setItem(key(),JSON.stringify({time:new Date().toISOString(),rows:snapshot()}));status.textContent='Baseline saved for '+breakpoint();return;}
                const baseline=JSON.parse(localStorage.getItem(key()));
                if(!baseline) throw Error('Save a baseline first at this viewport width and device pixel ratio.');
                const before=new Map(baseline.rows),after=new Map(snapshot());
                out.replaceChildren();let changes=0;
                for(const id of new Set([...before.keys(),...after.keys()])) if(before.get(id)!==after.get(id)) {
                    changes++;out.append(el('h3',id),el('p','Before · '+(before.get(id)||'Absent')),el('p','Now · '+(after.get(id)||'Absent')));
                }
                status.textContent=changes+' changes since '+baseline.time;
            }
        } catch(e){status.textContent=e.message;} finally {working=false;}
    }
    const pick=panel.querySelector('[data-task=pick]');
    function pickMode(on){picking=on;pick.setAttribute('aria-pressed',String(on));pick.textContent=on?'Cancel pick':'Pick image';}
    panel.addEventListener('click',e=>{const cmd=e.target.closest('[data-task]')?.dataset.task;if(cmd==='pick')pickMode(!picking);else if(cmd)task(cmd);});
    document.addEventListener('click',e=>{
        if(!picking || panel.contains(e.target))return;
        e.preventDefault();e.stopImmediatePropagation();pickMode(false);
        let n=e.target;
        while(n && !n.matches('img') && !getComputedStyle(n).backgroundImage.includes('url(')) n=n.parentElement;
        if(n)task('scan',n);else status.textContent='This element has no inspectable image or background.';
    },true);
    document.addEventListener('keydown',e=>{if(e.key==='Escape')pickMode(false);});
    if(sessionStorage.getItem('qif-verify')===page){sessionStorage.removeItem('qif-verify');setTimeout(()=>task('scan'),800);}
})();
