(() => {
    const panel=document.querySelector('.qif');
    if (!panel) return;
    const status=panel.querySelector('#qif-status');
    const scope = panel.querySelector('#qif-scope');
    const launch = panel.querySelector('[data-qif="launch"]');
    const selected = selector => [...panel.querySelectorAll(selector)].map(input => input.value);
    const targets = () => selected('#qif-targets input:checked:not(:disabled)');
    let state = 'idle', timer, generation = 0;
    const results = () => panel.querySelector('#qif-results');
    function progress(data) {
        const box = panel.querySelector('#qif-progress');
        if (!box) return;
        box.hidden = data.state === 'idle';
        const percent = data.state === 'complete' ? 100 : Math.min(100, Math.floor(100 * data.done / (data.total || 1)));
        panel.querySelector('#qif-meter').value = percent;
        panel.querySelector('#qif-percent').textContent = percent + '%';
    }
    async function refreshResults(token) {
        const list = results();
        if (!list) return;
        const url = new URL(ajaxurl, location.href);
        for (const [key,value] of new URLSearchParams(location.search)) {
            if (['fmt','state','month','paged'].includes(key)) url.searchParams.set(key,value);
        }
        const body = new URLSearchParams({action:'qif_action',cmd:'results',_ajax_nonce:qifAdmin.nonce,view:list.dataset.view});
        const response = await fetch(url,{method:'POST',body});
        const result = await response.json();
        if (!result.success || !result.data.html) throw Error('Unable to update image list');
        if (token !== generation) return;
        const checked = new Set(selected('.qif-id:checked'));
        list.outerHTML = result.data.html;
        const boxes = [...panel.querySelectorAll('.qif-id')];
        boxes.forEach(box => { box.checked = checked.has(box.value); });
        const all = panel.querySelector('#qif-all');
        if (all) all.checked = boxes.length > 0 && boxes.every(box => box.checked);
        review();
    }
    function review() {
        if (!scope) return;
        const active = ['running','paused'].includes(state);
        const checked = selected('.qif-id:checked').length;
        const countNeeded = ['selected','force'].includes(scope.value);
        const formats = targets();
        panel.querySelector('#qif-review').textContent = (countNeeded ? checked+' checked images' : scope.selectedOptions[0].textContent)+' → '+(formats.join(' + ').toUpperCase() || 'Choose an output format');
        launch.disabled = active || !formats.length || (countNeeded && !checked);
        panel.querySelector('[data-qif="pause"]').hidden = state!=='running';
        panel.querySelector('[data-qif="resume"]').hidden = state!=='paused';
        panel.querySelector('[data-qif="stop"]').hidden = !active;
        scope.disabled = active;
        panel.querySelector('#qif-targets').disabled = active;
    }
    panel.addEventListener('change',review);
    review();
    async function action(cmd) {
        clearTimeout(timer);
        const token = ++generation;
        const ids=[...panel.querySelectorAll('.qif-id:checked')].map(x=>x.value);
        try {
            const body=new URLSearchParams({action:'qif_action',_ajax_nonce:qifAdmin.nonce,cmd,ids:ids.join(',')});
            if (scope && ['start','selected','retry','force'].includes(cmd)) body.set('targets',targets().join(','));
            if(cmd==='restore'){
                const preview=new URLSearchParams(body); preview.set('cmd','review_restore');
                const response=await fetch(ajaxurl,{method:'POST',body:preview});
                const review=await response.json();
                if(!review.success) throw Error(review.data);
                if(!window.confirm('Remove derivatives for '+ids.length+' attachment(s)?\n\nRecent scan matches\n'+review.data.usage)) return;
                body.set('confirm',review.data.confirm);
            }
            const res=await fetch(ajaxurl,{method:'POST',body});
            const {success,data}=await res.json();
            if (token !== generation) return;
            if (!success) throw Error(typeof data==='string'?data:'Request failed');
            if (cmd==='export') {
                const url=URL.createObjectURL(new Blob([JSON.stringify(data,null,2)],{type:'application/json'}));
                const a=document.createElement('a'); a.href=url; a.download='imageflow-settings.json'; a.click(); setTimeout(()=>URL.revokeObjectURL(url),1000); return;
            }
            if (data==='busy') { timer=setTimeout(()=>action(cmd),1200); return; }
            if (typeof data==='string') { status.textContent=data; if (['restore','clear'].includes(cmd)) await refreshResults(token); return; }
            state=data.state;
            review();
            progress(data);
            if (data.row) {
                const row = panel.querySelector('[data-attachment="'+Number(data.row.id)+'"]');
                if (row) for (const key of ['formats','status','detail']) {
                    const cell = row.querySelector('[data-result="'+key+'"]');
                    cell.textContent = key === 'detail' ? data.row[key].replace(/\s*·\s*$/, '') : data.row[key];
                    if (key === 'status') cell.setAttribute('data-state', data.row.status);
                }
            }
            status.textContent='Queue '+data.state+(data.targets?.length?' · Output '+data.targets.join(' + ').toUpperCase():'')+' · '+data.done+' / '+data.total+' processed · '+(data.converted||0)+' converted · '+(data.skipped||0)+' skipped · '+(data.failed||0)+' failed · '+(data.saved||0)+' bytes saved. '+(data.last||'');
            if (['complete','stopped','paused'].includes(state)) await refreshResults(token);
            if (data.state==='running') timer=setTimeout(()=>action('tick'),400);
        } catch (err) {
            if (token !== generation) return;
            status.textContent=err.message;
            if ((scope || results()?.dataset.view === 'media') && (cmd==='tick' || cmd==='status' || state==='complete')) {
                status.textContent+=' · Reconnecting…';
                timer=setTimeout(()=>action('status'),3000);
            }
        }
    }
    panel.addEventListener('click',e=>{
        const help=e.target.closest('.qif-format-help');
        if (help && help.classList?.contains('qif-format-help')) {
            const expand=help.getAttribute('aria-expanded')==='false';
            for (const control of panel.querySelectorAll('.qif-format-help')) {
                const open=expand && control===help;
                control.setAttribute('aria-expanded',String(open));
                document.getElementById(control.getAttribute('aria-controls')).hidden=!open;
            }
            return;
        }
        const cmd=e.target.closest('[data-qif]')?.dataset.qif;
        if(cmd) action(cmd==='launch' ? scope.value : cmd);
        if(e.target.id==='qif-all') panel.querySelectorAll('.qif-id').forEach(x=>x.checked=e.target.checked);
    });
    if(scope || results()?.dataset.view === 'media') action('status');
})();
