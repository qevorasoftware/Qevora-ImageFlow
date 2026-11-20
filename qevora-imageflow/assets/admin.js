(() => {
    const panel=document.querySelector('.qif');
    if (!panel) return;
    const status=panel.querySelector('#qif-status');
    let timer;
    async function action(cmd) {
        clearTimeout(timer);
        const ids=[...panel.querySelectorAll('.qif-id:checked')].map(x=>x.value);
        try {
            const res=await fetch(ajaxurl,{method:'POST',body:new URLSearchParams({action:'qif_action',_ajax_nonce:qifAdmin.nonce,cmd,ids:ids.join(',')})});
            const {success,data}=await res.json();
            if (!success) throw Error(typeof data==='string'?data:'Request failed');
            if (cmd==='export') {
                const url=URL.createObjectURL(new Blob([JSON.stringify(data,null,2)],{type:'application/json'}));
                const a=document.createElement('a'); a.href=url; a.download='imageflow-settings.json'; a.click(); setTimeout(()=>URL.revokeObjectURL(url),1000); return;
            }
            if (data==='busy') { timer=setTimeout(()=>action(cmd),1200); return; }
            if (typeof data==='string') { status.textContent=data; return; }
            status.textContent='Queue '+data.state+' · '+data.done+' / '+data.total+' processed · '+(data.converted||0)+' converted · '+(data.skipped||0)+' skipped · '+(data.failed||0)+' failed · '+(data.saved||0)+' bytes saved. '+(data.last||'');
            if (data.state==='running') timer=setTimeout(()=>action('tick'),400);
        } catch (err) { status.textContent=err.message+' · Reload to reconnect.'; }
    }
    panel.addEventListener('click',e=>{
        const cmd=e.target.closest('[data-qif]')?.dataset.qif;
        if(cmd) action(cmd);
        if(e.target.id==='qif-all') panel.querySelectorAll('.qif-id').forEach(x=>x.checked=e.target.checked);
    });
    if(panel.querySelector('[data-qif="start"]')) action('status');
})();
