const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {mkdtempSync,readFileSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const {join}=require('node:path');
const fixture=mkdtempSync(join(tmpdir(),'qif-media-'));
try {
    for(const full of [false,true]) {
        const markup=execFileSync('php',{encoding:'utf8',input:`<?php
        namespace Qevora\\ImageFlow;
        define('ABSPATH',__DIR__);
        function add_action(...$args){} function get_option($k,$v){return $v;}
        function esc_attr($s){return htmlspecialchars($s);} function esc_html($s){return htmlspecialchars($s);}
        function absint($v){return abs((int)$v);} function esc_url($s){return $s;} function page_url($k){return '/'.$k;}
        require 'includes/media-audit.php';audit_panel(${full?'true':'false'});`});
        const groups={};
        for(const type of ['image','video']) {
            groups[type]={};
            for(const band of ['high','medium','low','unknown'])groups[type][band]={count:0,bytes:0,largest:[]};
        }
        groups.image.high={count:1,bytes:1048576,largest:[{id:1,title:'Hero',bytes:1048576,status:'converted',source:'Local file'}]};
        groups.video.unknown={count:1,bytes:0,largest:[{id:2,title:'Movie',bytes:null,status:'Check source file',source:'Size unavailable'}]};
        const report={finished:'2026-01-01T12:00:00Z',done:2,groups};
        const file=join(fixture,'audit.html');
        writeFileSync(file,`<!doctype html><meta name="viewport" content="width=device-width"><style>${readFileSync('assets/admin.css','utf8')}${readFileSync('assets/media-audit.css','utf8')}</style><div class="qif">${markup}</div><script>
        window.qifMediaAudit={report:${JSON.stringify(report)},full:'${Number(full)}',edit:'https://example.test/wp-admin/post.php'};
        </script><script>${readFileSync('assets/media-audit.js','utf8')}</script><script>
        const check=(b,t)=>{if(!b)throw Error(t);};
        check(document.querySelector('.qif-audit-stats').innerText.includes('50%'),'overall percentage');
        check(document.querySelector('.qif-storage-key').textContent.includes('100%'),'storage share');
        if(${full}){
            document.querySelector('[aria-label^="Show Videos · Unknown"]').click();
            const rows=document.querySelector('#qif-audit-rows');
            check(rows.children.length===1 && rows.innerText.includes('Movie') && rows.innerText.includes('Unknown'),'filter');
            check(document.querySelector('#qif-run-audit').onclick,'scan button');
        }
        check(!document.querySelector('#qif-media-audit').parentElement.querySelector('table') || document.querySelector('.qif-audit-scroll').scrollWidth>=document.querySelector('.qif-audit-scroll').clientWidth,'table scroll');
        document.body.dataset.ok='yes';
        </script>`);
        const dom=execFileSync(process.env.CHROME_BIN || 'google-chrome',['--headless','--no-sandbox','--disable-gpu','--window-size=430,780','--dump-dom','file://'+file],{encoding:'utf8',stdio:['ignore','pipe','pipe']});
        assert.match(dom,/<body data-ok="yes">/);
    }
} finally {rmSync(fixture,{recursive:true,force:true});}
