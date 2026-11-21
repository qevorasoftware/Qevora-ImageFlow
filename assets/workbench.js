(() => {
    const byId=id=>document.getElementById(id);
    const preview=byId('qif-sample');
    const urls=[];
    function node(tag,text) {
        const item=document.createElement(tag);
        item.textContent=text;
        return item;
    }
    async function post(action,fields) {
        return fetch(window.ajaxurl,{method:'POST',body:new URLSearchParams({action,_ajax_nonce:qifAdmin.nonce,...fields})});
    }
    function figure(url,label) {
        const box=document.createElement('figure'),link=document.createElement('a'),img=document.createElement('img');
        link.href=url;link.target='_blank';link.rel='noopener';
        img.src=url;img.alt=label;
        link.append(img);box.append(link,node('figcaption',label));
        return box;
    }
    preview?.addEventListener('click',async()=>{
        const ids=[...document.querySelectorAll('.qif-id:checked')].map(i=>i.value);
        const quality=[...document.querySelectorAll('.qif-quality:not(:disabled)')];
        const status=byId('qif-sample-status'),results=byId('qif-samples');
        if (!ids.length || ids.length>3 || !quality.length || quality.some(input=>!input.reportValidity())) {
            status.textContent='Check one to three images and choose valid quality values for an available encoder.';return;
        }
        const snapshot=quality.map(input=>({format:input.dataset.format,quality:input.value}));
        preview.disabled=true;results.replaceChildren();urls.splice(0).forEach(url=>URL.revokeObjectURL(url));
        try {
            for(const id of ids) {
                const group=document.createElement('div');group.className='qif-comparison';results.append(node('h3','Attachment #'+id),group);
                let originalShown=false;
                for(const entry of snapshot) {
                    const {format,quality}=entry;status.textContent='Testing #'+id+' · '+format.toUpperCase();
                    const r=await post('qif_sample',{id,format,quality});
                    if(!r.ok || !r.headers.get('Content-Type')?.startsWith('image/')) {
                        const json=await r.json().catch(()=>null);group.append(node('p',format.toUpperCase()+' · '+(json?.data || 'Sample unavailable')));continue;
                    }
                    const original=Number(r.headers.get('X-QIF-Original-Bytes'));
                    if(!originalShown) {group.append(figure(decodeURIComponent(r.headers.get('X-QIF-Original')),'Original · '+original.toLocaleString()+' B'));originalShown=true;}
                    const blob=await r.blob(),url=URL.createObjectURL(blob);urls.push(url);
                    const box=figure(url,format.toUpperCase()+' · quality '+quality+' · '+blob.size.toLocaleString()+' B · '+Math.round((1-blob.size/original)*100)+'% saved · '+r.headers.get('X-QIF-Milliseconds')+' ms');
                    const use=node('button','Use these settings');use.className='button';use.type='button';
                    use.onclick=()=>{
                        const targets=byId('qif-targets');
                        if(targets.disabled){status.textContent='Finish the active batch first.';return;}
                        for(const input of targets.querySelectorAll('input')) input.checked=input.value===format;
                        targets.dataset.sampleFormat=format;targets.dataset.sampleQuality=quality;
                        targets.dispatchEvent(new Event('change',{bubbles:true}));
                        status.textContent='Batch set to '+format.toUpperCase()+' at quality '+quality+'. Review the scope and start conversion. Size-specific rules still apply.';
                    };
                    box.append(use);group.append(box);
                }
            }
            status.textContent='Sample tests finished. Choose a result to apply its settings to this batch.';
        } catch(e){status.textContent=e.message;} finally{preview.disabled=false;}
    });
    const trial=byId('qif-trial');
    trial?.addEventListener('click',async()=>{
        const result=byId('qif-trial-results'),status=byId('qif-trial-status');
        result.replaceChildren();trial.disabled=true;
        try {
            const response=await post('qif_trial',{template:byId('qif-trial-template').value,posts:byId('qif-trial-posts').value});
            const json=await response.json();
            if(!json.success || !json.data.length)throw Error(json.data || 'Enter at least one sample post ID.');
            status.textContent='Choose a sample and viewport.';
            const frame=document.createElement('iframe');frame.title='Elementor template trial';frame.className='qif-trial-frame';
            for(const page of json.data){
                const row=node('p',page.title+' ');
                for(const [label,width] of [['Mobile',390],['Tablet',768],['Desktop',1280]]) {
                    const btn=node('button',label);btn.type='button';btn.className='button';
                    btn.onclick=()=>{frame.width=width;frame.src=page.url;};row.append(btn);
                }
                const a=node('a',' Open full page');a.href=page.url;a.target='_blank';a.rel='noopener';row.append(a);result.append(row);
            }
            const wrap=document.createElement('div');wrap.className='qif-trial-scroll';wrap.append(frame);result.append(wrap);result.querySelector('button').click();
        } catch(e){status.textContent=e.message;}finally{trial.disabled=false;}
    });
    const review=byId('qif-rule-review');
    if(review){
        const form=review.closest('form');
        const update=()=>{const rules=[...form.querySelectorAll('select')].filter(s=>s.value).map(s=>s.closest('tr').cells[0].textContent+' → '+s.value.toUpperCase());review.textContent=rules.length?'Rules to apply: '+rules.join(', '):'All sizes follow the batch settings.';};
        form.addEventListener('change',update);update();
    }
})();
