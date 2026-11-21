(() => {
    const root=document.querySelector('#qif-media-audit');
    if (!root) return;
    const cfg=window.qifMediaAudit;
    const full=Number(cfg.full)===1;
    const byId=id=>root.querySelector('#qif-'+id);
    const chart=byId('audit-chart'), message=byId('audit-message');
    const types=['image','video'], bands=['high','medium','low','unknown'];
    const labels={image:'Images',video:'Videos',high:'High',medium:'Medium',low:'Low',unknown:'Unknown size'};
    const integer=new Intl.NumberFormat();
    function percent(num,total) {
        if (!total || !num) return '0%';
        const value=100*num/total;
        return value<0.1?'<0.1%':Number(value.toFixed(1))+'%';
    }
    function bytes(value) {
        if (value===null) return 'Unknown';
        const units=['B','KiB','MiB','GiB','TiB'];
        const power=value?Math.min(units.length-1,Math.floor(Math.log(value)/Math.log(1024))):0;
        return new Intl.NumberFormat(undefined,{maximumFractionDigits:1}).format(value/1024**power)+' '+units[power];
    }
    function make(tag,text,cls) {
        const el=document.createElement(tag);
        if (text!==undefined) el.textContent=text;
        if (cls) el.className=cls;
        return el;
    }
    let report=cfg.report;
    function buckets(type) {return bands.map(b=>report.groups[type][b]);}
    function sum(items,key) {return items.reduce((n,x)=>n+x[key],0);}
    function totalBytes() {return sum(types.flatMap(buckets),'bytes');}
    function renderRows() {
        if (!full || !report.groups) return;
        const rows=byId('audit-rows'),total=totalBytes(),items=[];
        for(const type of types) for(const band of bands) {
            if(!['all',type].includes(byId('audit-type').value) || !['all',band].includes(byId('audit-band').value))continue;
            for(const file of report.groups[type][band].largest) items.push({type,band,file});
        }
        items.sort((a,b)=>(b.file.bytes??-1)-(a.file.bytes??-1) || a.file.id-b.file.id);
        rows.replaceChildren();
        for(const {type,band,file} of items) {
            const row=make('tr'),title=make('td'),link=make('a',file.title),url=new URL(cfg.edit);
            url.searchParams.set('post',file.id);url.searchParams.set('action','edit');link.href=url.href;
            title.append(link,make('small','#'+file.id+' · '+file.source));
            const priority=make('td');priority.append(make('span',labels[band],'qif-priority qif-tone-'+band));
            row.append(title,make('td',type==='image'?'Image':'Video'),priority,make('td',bytes(file.bytes)),make('td',file.bytes===null?'—':percent(file.bytes,total)),make('td',file.status));
            rows.append(row);
        }
        if (!items.length) {
            const cell=make('td','No files match these filters.');cell.colSpan=6;
            const row=make('tr');row.append(cell);rows.append(row);
        }
    }
    function meter(parts) {
        const bar=make('div',undefined,'qif-audit-bar');
        bar.setAttribute('aria-hidden','true');
        const total=parts.reduce((n,v)=>n+v.count,0);
        for(const {key,count} of parts) if(count){
            const part=make('span',undefined,'qif-fill-'+key);
            part.style.width=100*count/total+'%';bar.append(part);
        }
        return bar;
    }
    function render() {
        chart.replaceChildren();
        if (!report.groups) {
            chart.append(make('p',full?'Scan your library to see file sizes and review priorities.':'Open the media report and run your first library scan.','qif-audit-empty'));
            return;
        }
        const total=totalBytes(),count=sum(types.flatMap(buckets),'count');
        const summary=make('div',undefined,'qif-audit-stats');
        const unknown=types.reduce((n,t)=>n+report.groups[t].unknown.count,0);
        const high=types.reduce((n,t)=>n+report.groups[t].high.count,0);
        for(const [name,val,detail] of [['Library files',integer.format(count),'Images and videos'],['Measured storage',bytes(total),integer.format(unknown)+' unknown sizes'],['High priority',integer.format(high),percent(high,count)+' of all files']]) {
            const card=make('div');card.append(make('span',name),make('strong',val),make('small',detail));summary.append(card);
        }
        chart.append(summary);
        const storage=types.map(key=>({key,count:sum(buckets(key),'bytes')}));
        const storageLegend=make('div',undefined,'qif-audit-legend');
        for(const entry of storage){
            const key=make('span',labels[entry.key]+' · '+bytes(entry.count)+' ('+percent(entry.count,total)+')','qif-storage-key');
            key.prepend(make('i',undefined,'qif-fill-'+entry.key));
            storageLegend.append(key);
        }
        chart.append(make('h3','Storage by media type'),meter(storage),storageLegend);
        chart.append(make('h3','Optimization priority'),make('p','Each percentage is the share of files within that media type.'));
        for(const type of types) {
            const group=make('div',undefined,'qif-audit-group'),parts=bands.map(key=>({key,count:report.groups[type][key].count}));
            const num=sum(parts,'count'),legend=make('div',undefined,'qif-audit-legend');
            group.append(make('h4',labels[type]+' · '+integer.format(num)),meter(parts));
            for(const {key,count:n} of parts) {
                const tag=make(full?'button':'span',labels[key]+' '+integer.format(n)+' ('+percent(n,num)+')','qif-priority qif-tone-'+key);
                if(full){tag.type='button';tag.setAttribute('aria-label','Show '+labels[type]+' · '+tag.textContent);tag.onclick=()=>{
                    byId('audit-type').value=type;byId('audit-band').value=key;renderRows();byId('audit-type').focus();
                };}
                legend.append(tag);
            }
            group.append(legend);chart.append(group);
        }
        chart.append(make('p','Last completed scan · '+new Date(report.finished).toLocaleString(),'qif-audit-stamp'));
        if(full){byId('audit-list').hidden=false;renderRows();}
    }
    render();
    if(!full)return;
    byId('audit-type').onchange=renderRows;byId('audit-band').onchange=renderRows;
    const run=byId('run-audit'),progress=byId('audit-progress');
    run.onclick=async()=>{
        const formData={};
        for(const type of types){
            for(const band of ['medium','high']){
                const input=byId(type+'-'+band);
                if(!input.reportValidity())return;
                formData[type+'_'+band]=Number(input.value);
            }
            if(formData[type+'_high']<=formData[type+'_medium']){message.textContent=labels[type]+': High must be larger than Medium.';return;}
        }
        run.disabled=true;progress.hidden=false;progress.removeAttribute('value');message.textContent='Scanning images and videos…';
        const request=new URLSearchParams({action:'qif_audit',_ajax_nonce:qifAdmin.nonce,cmd:'start',...formData});
        try {
            while(true){
                const response=await fetch(window.ajaxurl,{method:'POST',body:request});
                const json=await response.json();
                if(!json.success)throw Error(typeof json.data==='string'?json.data:'Scan failed.');
                const data=json.data;
                if(data==='busy'){await new Promise(r=>setTimeout(r,800));continue;}
                if(data.finished){report=data;render();message.textContent='Scan complete. '+integer.format(data.done)+' files reviewed.';break;}
                progress.max=Math.max(data.total,data.done,1);progress.value=data.done;
                message.textContent='Scanned '+integer.format(data.done)+' of '+integer.format(progress.max)+' files…';
                request.set('cmd','tick');request.set('token',data.token);
            }
        }catch(e){message.textContent='Scan interrupted. '+e.message+' You can start another scan.';}
        finally{progress.hidden=true;run.disabled=false;}
    };
})();
