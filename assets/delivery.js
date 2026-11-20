(() => {
    'use strict';
    const config = window.qifDelivery;
    const base = new URL(config.base, location.href);
    if (base.origin !== location.origin) return;
    const cache = new Map(), pending = new Map();
    let timer;
    function lookup(value) {
        let url, path;
        try {
            url = new URL(value, document.baseURI);
            if (url.origin !== base.origin || !url.pathname.startsWith(base.pathname) || url.search || url.hash) return null;
            path = decodeURIComponent(url.pathname.slice(base.pathname.length));
        } catch { return null; }
        if (!path || path.startsWith('qevora-imageflow/')) return null;
        if (!cache.has(path)) {
            cache.set(path, new Promise(resolve => pending.set(path, resolve)));
            if (!timer) timer = setTimeout(flush, 15);
        }
        return cache.get(path);
    }
    async function flush() {
        timer = null;
        const batch = Array.from(pending.keys()).slice(0, 40);
        const done = batch.map(key => pending.get(key));
        batch.forEach(key => pending.delete(key));
        let data = {};
        try {
            const response = await fetch(config.api, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({paths:batch})});
            if (response.ok) data = await response.json();
        } catch { /* Original asset is already in place. */ }
        batch.forEach((key,i) => done[i](data[key] || null));
        if (pending.size && !timer) timer = setTimeout(flush, 15);
    }
    async function image(img) {
        if (img.closest('picture') || img.dataset.qifOriginal) return;
        const src = img.getAttribute('src'), set = img.getAttribute('srcset');
        if (!src) return;
        const main = await lookup(src);
        if (!main) return;
        const candidates = set ? set.split(',').map(x => x.trim().split(/\s+/)) : [];
        const mapped = await Promise.all(candidates.map(c => lookup(c[0])));
        if (mapped.some(v => !v || v.mime !== main.mime) || img.getAttribute('src') !== src || img.getAttribute('srcset') !== set || img.dataset.qifOriginal) return;
        img.dataset.qifOriginal = src;
        const restore = () => {
            img.removeEventListener('error', restore);
            if (set) img.setAttribute('srcset', set);
            img.setAttribute('src',src);
        };
        img.addEventListener('error', restore, {once:true});
        if (set) img.srcset = mapped.map((v,i) => [v.url,...candidates[i].slice(1)].join(' ')).join(', ');
        img.src = main.url;
    }
    async function background(style, baseURL) {
        if (!style) return;
        const original = style.getPropertyValue('background-image');
        const match = /^url\(["']?([^"'()]+)["']?\)$/.exec(original);
        if (!match) return;
        let source;
        try { source = new URL(match[1],baseURL).href; } catch { return; }
        const item = await lookup(source);
        if (!item || style.getPropertyValue('background-image') !== original) return;
        const value = 'image-set(url('+JSON.stringify(item.url)+') type('+JSON.stringify(item.mime)+'), url('+JSON.stringify(source)+'))';
        if (CSS.supports('background-image',value)) style.setProperty('background-image',value,style.getPropertyPriority('background-image'));
    }
    const sheets = new WeakSet();
    function sheet(s) {
        if (!config.css || !s || sheets.has(s)) return;
        try {
            const visit = rules => { for (const r of rules) {
                if (r.style) background(r.style,s.href || document.baseURI);
                if (r.cssRules) visit(r.cssRules);
            }};
            visit(s.cssRules);
            sheets.add(s);
        } catch { /* Cross-origin stylesheet retains its original rules. */ }
    }
    function scan(node) {
        if (!(node instanceof Element)) return;
        if (node.matches('img')) image(node);
        node.querySelectorAll('img').forEach(image);
        if (!config.css) return;
        if (node.hasAttribute('style')) background(node.style,document.baseURI);
        node.querySelectorAll('[style]').forEach(e => background(e.style,document.baseURI));
        if (node.sheet) sheet(node.sheet);
    }
    scan(document.documentElement);
    Array.from(document.styleSheets).forEach(sheet);
    document.addEventListener('load', e => { if (e.target.sheet) sheet(e.target.sheet); },true);
    new MutationObserver(records => records.forEach(r => {
        if (r.type === 'childList') r.addedNodes.forEach(scan);
        else if (r.target.matches('img')) image(r.target);
        else if (config.css) background(r.target.style,document.baseURI);
    })).observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:['src','srcset','style']});
})();
