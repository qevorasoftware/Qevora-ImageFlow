// Run on CI with PHP CLI and Chrome. Render the same choices and help used by the bulk screen.
const {execFileSync}=require('node:child_process');
const {mkdtempSync,readFileSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const {join}=require('node:path');
const assert=require('node:assert/strict');
const markup=execFileSync('php',{encoding:'utf8',input:`<?php
namespace Qevora\\ImageFlow;
define('ABSPATH',__DIR__);
function add_filter(...$args) {} function add_action(...$args) {}
function __($s,$domain){return $s;} function translate($s,$domain){return $s;}
function esc_html($s){return htmlspecialchars($s);} function esc_attr($s){return htmlspecialchars($s);}
function checked($a,$b,$echo){return $a==$b?'checked':'';}
function disabled($a,$b,$echo){return $a==$b?'disabled':'';}
function outputs(){return ['webp','avif','jpeg','png'];} function encoder($s){return $s!=='png';}
require 'includes/admin.php';
bulk_format_choices(['targets'=>['webp']]);`});
const dir=mkdtempSync(join(tmpdir(),'qif-layout-'));
try {
    for (const width of [320,960]) {
        const file=join(dir,'test.html');
        writeFileSync(file,`<!doctype html><style>${readFileSync('assets/admin.css','utf8')}
            .screen-reader-text{position:absolute;clip-path:inset(50%)} main{width:${width}px}</style>
            <main class="qif">${markup}</main><script>${readFileSync('assets/admin.js','utf8')}</script><script>
            const buttons=[...document.querySelectorAll('.qif-format-help')];
            const boxes=[...document.querySelectorAll('input')];
            const coords=()=>JSON.stringify(boxes.map(b=>[b.offsetLeft,b.offsetTop,b.checked,b.disabled]));
            const before=coords();
            for (const button of [...buttons,buttons.at(-1)]) {
                const opening=button.getAttribute('aria-expanded')==='false';
                button.click();
                const visible=[...document.querySelectorAll('.qif-format-note:not([hidden])')];
                if (coords()!==before || visible.length!==Number(opening)) throw Error('format moved or wrong disclosure count');
                if(opening && (visible[0].id!==button.getAttribute('aria-controls') || visible[0].getBoundingClientRect().top<document.querySelector('fieldset').getBoundingClientRect().bottom)) throw Error('misplaced note');
            }
            document.body.dataset.verified='yes';</script>`);
        const dom=execFileSync(process.env.CHROME_BIN || 'google-chrome',['--headless','--no-sandbox','--disable-gpu','--dump-dom','file://'+file],{encoding:'utf8',stdio:['ignore','pipe','pipe']});
        assert.match(dom,/<body data-verified="yes">/);
    }
} finally { rmSync(dir,{recursive:true,force:true}); }
