(() => {
    const button=document.getElementById('qif-origin-scan');
    if(!button)return;
    const status=document.getElementById('qif-origin-status');
    button.addEventListener('click',async()=>{
        button.disabled=true;
        let total=0,cursor=0,limit=0;
        status.textContent='Checking embedded metadata…';
        try {
            do {
                const body=new URLSearchParams({action:'qif_origin_scan',_ajax_nonce:qifAdmin.nonce,cursor,limit});
                const response=await fetch(window.ajaxurl,{method:'POST',body});
                const result=await response.json();
                if(!result.success)throw Error(typeof result.data==='string'?result.data:'Unable to check files.');
                total+=result.data.count;
                status.textContent=total.toLocaleString()+' attachments checked.';
                if(result.data.finished){status.textContent+=' Scan complete.';break;}
                if(result.data.cursor<=cursor)throw Error('Scan could not advance.');
                cursor=result.data.cursor;limit=result.data.limit;
            } while(true);
        } catch(e){status.textContent='Scan interrupted. '+e.message+' Saved labels remain available.';}
        finally{button.disabled=false;}
    });
})();
