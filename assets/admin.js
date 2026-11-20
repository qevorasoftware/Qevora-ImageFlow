(() => {
    const panel=document.querySelector('.qif');
    if (!panel) return;
    const status=panel.querySelector('#qif-status');
    const scope = panel.querySelector('#qif-scope');
    const launch = panel.querySelector('[data-qif="launch"]');
    const selected = selector => [...panel.querySelectorAll(selector)].map(input => input.value);
    const targets = () => selected('#qif-targets input:checked:not(:disabled)');
    let state = 'idle', timer;
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
        const ids=[...panel.querySelectorAll('.qif-id:checked')].map(x=>x.value);
        try {
            const body=new URLSearchParams({action:'qif_action',_ajax_nonce:qifAdmin.nonce,cmd,ids:ids.join(',')});
            if (scope && ['start','selected','retry','force'].includes(cmd)) body.set('targets',targets().join(','));
            const res=await fetch(ajaxurl,{method:'POST',body});
            const {success,data}=await res.json();
            if (!success) throw Error(typeof data==='string'?data:'Request failed');
            if (cmd==='export') {
                const url=URL.createObjectURL(new Blob([JSON.stringify(data,null,2)],{type:'application/json'}));
                const a=document.createElement('a'); a.href=url; a.download='imageflow-settings.json'; a.click(); setTimeout(()=>URL.revokeObjectURL(url),1000); return;
            }
            if (data==='busy') { timer=setTimeout(()=>action(cmd),1200); return; }
            if (typeof data==='string') { status.textContent=data; return; }
            state=data.state;
            review();
            if (cmd==='tick' && state==='complete' && scope) { location.reload(); return; }
            status.textContent='Queue '+data.state+(data.targets?.length?' · Output '+data.targets.join(' + ').toUpperCase():'')+' · '+data.done+' / '+data.total+' processed · '+(data.converted||0)+' converted · '+(data.skipped||0)+' skipped · '+(data.failed||0)+' failed · '+(data.saved||0)+' bytes saved. '+(data.last||'');
            if (data.state==='running') timer=setTimeout(()=>action('tick'),400);
        } catch (err) { status.textContent=err.message+' · Reload to reconnect.'; }
    }
    panel.addEventListener('click',e=>{
        const cmd=e.target.closest('[data-qif]')?.dataset.qif;
        if(cmd) action(cmd==='launch' ? scope.value : cmd);
        if(e.target.id==='qif-all') panel.querySelectorAll('.qif-id').forEach(x=>x.checked=e.target.checked);
    });
    if(scope) action('status');
})();
