const GH = (function() {
    const AJAX  = '<?php echo esc_js( $ajax ); ?>';
    const NONCE = '<?php echo esc_js( $nonce ); ?>';
    let state = { roundtripData:null, importJSON:null, bulkJSON:null };
    let taxTree=[], taxSelected=null, taxCollapsed={}, gsProducts=null, gsSelected=new Set(), gsDiffData=null;

    async function ajax(action, body={}) {
        const fd=new FormData(); fd.append('action',action); fd.append('nonce',NONCE);
        Object.entries(body).forEach(([k,v])=>fd.append(k,v));
        return (await fetch(AJAX,{method:'POST',body:fd})).json();
    }
    function toast(msg,type='ok',ms=3000) {
        const t=document.createElement('div'); t.className='toast '+type; t.textContent=msg;
        // Errori persistenti con bottone di dismiss se ms<=0.
        if (ms<=0) {
            t.classList.add('toast-sticky');
            const x=document.createElement('button'); x.className='toast-x'; x.textContent='×';
            x.onclick=()=>t.remove(); t.appendChild(x);
            document.getElementById('gh-toasts').appendChild(t); return t;
        }
        document.getElementById('gh-toasts').appendChild(t); setTimeout(()=>t.remove(),ms);
        return t;
    }
    function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML}

    // ajaxWithToast: wrapper che collassa ~100 pattern "if !r.success toast err".
    // opts: { okMsg?: string, errPrefix?: string, stickyErr?: bool }
    // Ritorna il response originale {success, data}.
    async function ajaxWithToast(action, body={}, opts={}) {
        const { okMsg='', errPrefix='Errore', stickyErr=false } = opts;
        try {
            const r = await ajax(action, body);
            if (!r || !r.success) {
                const msg = errPrefix + (r && r.data ? ': ' + r.data : '');
                toast(msg, 'err', stickyErr ? 0 : 3000);
                return r || { success:false, data:'no response' };
            }
            if (okMsg) toast(okMsg, 'ok');
            return r;
        } catch (e) {
            toast(errPrefix + ': ' + (e.message || 'network'), 'err', stickyErr ? 0 : 3000);
            return { success:false, data:e.message || 'network' };
        }
    }

    // emptyState: genera markup standard per liste vuote. Icona letterale
    // (HTML entity), testo escapato.
    function emptyState(icon, text, extraClass='') {
        const cls = ('empty-state ' + (extraClass||'')).trim();
        return '<div class="'+esc(cls)+'"><div class="empty-icon">'+(icon||'&#9898;')+'</div><div class="empty-text">'+esc(text||'')+'</div></div>';
    }

    // statusChip: <span class="gh-status gh-status--{variant}">{label}</span>
    function statusChip(label, variant='dim') {
        const allowed = ['ok','err','warn','info','dim'];
        const v = allowed.indexOf(variant) >= 0 ? variant : 'dim';
        return '<span class="gh-status gh-status--'+v+'">'+esc(label||'')+'</span>';
    }

    let _wakeLock = null;
    async function acquireWakeLock() {
        try { if ('wakeLock' in navigator) _wakeLock = await navigator.wakeLock.request('screen'); } catch(e) {}
    }
    function releaseWakeLock() {
        if (_wakeLock) { _wakeLock.release().catch(()=>{}); _wakeLock = null; }
    }
    function hl(j){return String(j).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,m=>{let c='jn';if(/^"/.test(m))c=/:$/.test(m)?'jk':'js';else if(/true|false/.test(m))c='jb';else if(/null/.test(m))c='jx';return'<span class="'+c+'">'+m+'</span>'})}
    function fileSize(b){if(b<1024)return b+' B';if(b<1048576)return(b/1024).toFixed(1)+' KB';return(b/1048576).toFixed(1)+' MB'}
    // ── Dirty tracking (global) ────────────────────────────────────
    // Editor registra markDirty() on change, clearDirty() on save. switchTab
    // chiede conferma prima di cambiare pannello. beforeunload warna per
    // refresh/chiusura scheda.
    let _dirty = false;
    function markDirty(){ _dirty = true; }
    function clearDirty(){ _dirty = false; }
    function isDirty(){ return _dirty; }
    window.addEventListener('beforeunload', (e) => { if (_dirty) { e.preventDefault(); e.returnValue = ''; } });

    // ── Shortcut map (global, per-panel) ───────────────────────────
    // Un editor puo registrare un handler Esc / Save. switchTab azzera.
    let _shortcuts = { close: null, save: null };
    function registerShortcuts(map){ _shortcuts = Object.assign({close:null,save:null}, map||{}); }
    function clearShortcuts(){ _shortcuts = { close:null, save:null }; }

    // Handler globale tastiera: '/', Esc, Cmd/Ctrl+S
    document.addEventListener('keydown', (e) => {
        const tag = e.target && e.target.tagName;
        const inField = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (e.target && e.target.isContentEditable);
        // Cmd/Ctrl+S: save sempre (anche dentro i field)
        if ((e.metaKey || e.ctrlKey) && (e.key === 's' || e.key === 'S')) {
            if (_shortcuts.save) { e.preventDefault(); _shortcuts.save(); }
            return;
        }
        if (inField) return; // '/' e Esc solo fuori dai field
        if (e.key === '/') {
            const panel = document.querySelector('#gh .panel.active');
            if (!panel) return;
            const search = panel.querySelector('input[type="search"], .search-input, input[id*="search"], input[placeholder*="erca"]');
            if (search) { e.preventDefault(); search.focus(); search.select && search.select(); }
        } else if (e.key === 'Escape' && _shortcuts.close) {
            _shortcuts.close();
        }
    });

    // ── Hash routing ───────────────────────────────────────────────
    // Formato: #/<tab-name>  oppure #/<tab-name>/<entity-id>
    // Al load: se c'e un hash, switcha alla tab. Le editor open-by-id sono
    // opzionali: un editor puo registrare un opener con registerDeepOpener.
    const _openers = {};
    function registerDeepOpener(tabName, fn){ _openers[tabName] = fn; }

    function applyHashRoute() {
        const hash = (location.hash || '').replace(/^#\/?/, '');
        if (!hash) return;
        const [tab, ...rest] = hash.split('/');
        if (!tab) return;
        const panel = document.getElementById('panel-'+tab);
        if (!panel) return;
        const btn = document.querySelector('#gh .tab-item[onclick*="\''+tab+'\'"]');
        if (btn) btn.click(); else {
            document.querySelectorAll('#gh .panel').forEach(p => p.classList.remove('active'));
            panel.classList.add('active');
        }
        const entityId = rest.join('/');
        if (entityId && typeof _openers[tab] === 'function') {
            // defer: lascia al tab il tempo di caricare la lista
            setTimeout(() => _openers[tab](entityId), 150);
        }
    }
    window.addEventListener('DOMContentLoaded', applyHashRoute);
    window.addEventListener('hashchange', applyHashRoute);

    function updateHash(tab, entityId){
        const h = '#/' + tab + (entityId ? '/' + entityId : '');
        if (location.hash !== h) history.replaceState(null, '', h);
    }

    function switchTab(name,el){
        if (_dirty && !confirm('Hai modifiche non salvate. Cambiare tab senza salvare?')) return;
        _dirty = false;
        clearShortcuts();
        document.querySelectorAll('#gh .tab-item').forEach(t=>t.classList.remove('active'));
        document.querySelectorAll('#gh .panel').forEach(p=>p.classList.remove('active'));
        if (el) el.classList.add('active');
        const p = document.getElementById('panel-'+name);
        if (p) p.classList.add('active');
        updateHash(name);
    }

    // ── Copy JSON utility ──────────────────────────────────────────
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise((resolve) => {
            const ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch(e){}
            ta.remove(); resolve();
        });
    }
    function copyJSON(data, label='JSON'){
        const json = JSON.stringify(data, null, 2);
        copyToClipboard(json).then(() => toast(label + ' copiato negli appunti', 'ok'));
    }

    // wireDirtyInputs(containerId): su ogni input/textarea/select dentro il
    // container, aggancia markDirty() su input/change (idempotente, safe da
    // chiamare piu volte sullo stesso container).
    function wireDirtyInputs(containerId){
        const c = document.getElementById(containerId);
        if (!c) return;
        c.querySelectorAll('input, textarea, select').forEach(el => {
            if (el.__ghDirtyBound) return;
            el.__ghDirtyBound = true;
            el.addEventListener('input',  () => markDirty());
            el.addEventListener('change', () => markDirty());
        });
    }
    function getFilters(pfx){const f={};const s=document.getElementById(pfx+'-filter-status');if(s)f.status=s.value;const b=document.getElementById(pfx+'-filter-brand');if(b&&b.value)f.brand=b.value;const c=document.getElementById(pfx+'-filter-stock');if(c&&c.checked)f.in_stock=true;return f}

    // ── TAXONOMY (product_cat | product_brand) ─────────────────
    function taxSource(){const s=document.getElementById('tax-source');return s?s.value:'product_cat'}
    function countNodes(n){let c=n.length;for(const nd of n)c+=countNodes(nd.children||[]);return c}
    async function loadTaxonomy(){const btn=document.getElementById('btn-tax-load'),sp=document.getElementById('tax-spin'),tax=taxSource();btn.disabled=true;sp.style.display='';taxSelected=null;document.getElementById('tax-detail').style.display='none';try{const r=await ajax('rp_cm_ajax_taxonomy_tree',{taxonomy:tax});if(!r.success){toast('Errore','err');return}taxTree=r.data;renderTaxTree();toast(countNodes(taxTree)+' termini in '+tax,'ok')}catch(e){toast('Errore','err')}finally{btn.disabled=false;sp.style.display='none'}}
    function renderTaxTree(){const a=document.getElementById('tax-tree-area');if(!taxTree.length){a.innerHTML='<div class="empty-state"><div class="empty-text">Nessun termine</div></div>';return}a.innerHTML=renderNodes(taxTree,0)}
    function renderNodes(nodes,depth){let h='';for(const nd of nodes){const k=nd.children&&nd.children.length,col=taxCollapsed[nd.id],sel=taxSelected===nd.id?' selected':'',dc=depth<=2?' depth-'+depth:'';h+='<div class="tax-node"><div class="tax-row'+sel+'" onclick="GH.taxSelect('+nd.id+',this)"><span class="tax-toggle" onclick="event.stopPropagation();GH.taxToggle('+nd.id+')">'+(k?(col?'\u25B6':'\u25BC'):'')+'</span><span class="tax-name'+dc+'">'+esc(nd.name)+'</span><span class="tax-count">'+nd.count+'</span><span class="tax-id">#'+nd.id+'</span><span class="tax-actions"><button class="tax-btn" onclick="event.stopPropagation();GH.taxAdd('+nd.id+')">+ figlio</button><button class="tax-btn" onclick="event.stopPropagation();GH.taxRename('+nd.id+')">rinomina</button><button class="tax-btn del" onclick="event.stopPropagation();GH.taxDelete('+nd.id+',\''+esc(nd.name).replace(/'/g,"\\'")+'\')">elimina</button></span></div>';if(k&&!col)h+=renderNodes(nd.children,depth+1);h+='</div>'}return h}
    function taxToggle(id){taxCollapsed[id]=!taxCollapsed[id];renderTaxTree()}
    async function taxSelect(id){taxSelected=id;renderTaxTree();const det=document.getElementById('tax-detail');document.getElementById('tax-detail-title').textContent=findNode(taxTree,id)?.name||'#'+id;document.getElementById('tax-detail-id').textContent='#'+id;const list=document.getElementById('tax-products-list');list.innerHTML='<div style="padding:16px;color:var(--dim);font-family:var(--mono);font-size:11px"><span class="spin"></span></div>';det.style.display='flex';const r=await ajax('rp_cm_ajax_taxonomy_products',{term_id:id,taxonomy:taxSource()});if(!r.success||!r.data.length){list.innerHTML='<div style="padding:16px;color:var(--dim);font-family:var(--mono);font-size:11px">'+(r.success?'Nessun prodotto':'Errore')+'</div>';return}list.innerHTML=r.data.map(p=>'<div class="tax-product-row"><span class="tax-product-id">#'+p.id+'</span><span class="tax-product-name">'+esc(p.name)+'</span><span class="tax-product-type type-'+p.type+'">'+p.type+'</span></div>').join('')}
    function findNode(nodes,id){for(const n of nodes){if(n.id===id)return n;if(n.children){const f=findNode(n.children,id);if(f)return f}}return null}
    async function taxCreateRoot(){const label=taxSource()==='product_brand'?'nuovo brand':'nuova sezione';const n=prompt('Nome '+label+':');if(!n?.trim())return;const r=await ajax('rp_cm_ajax_taxonomy_create',{name:n.trim(),parent_id:0,taxonomy:taxSource()});if(!r.success){toast('Errore: '+r.data,'err');return}toast('Creato "'+n.trim()+'"','ok');loadTaxonomy()}
    async function taxAdd(pid){const nd=findNode(taxTree,pid);const n=prompt('Figlio di "'+(nd?nd.name:'')+'":');if(!n?.trim())return;const r=await ajax('rp_cm_ajax_taxonomy_create',{name:n.trim(),parent_id:pid,taxonomy:taxSource()});if(!r.success){toast('Errore: '+r.data,'err');return}taxCollapsed[pid]=false;toast('Creato','ok');loadTaxonomy()}
    async function taxRename(id){const nd=findNode(taxTree,id);const n=prompt('Nuovo nome:',nd?nd.name:'');if(!n?.trim())return;const r=await ajax('rp_cm_ajax_taxonomy_rename',{term_id:id,name:n.trim(),taxonomy:taxSource()});if(!r.success){toast('Errore: '+r.data,'err');return}toast('Rinominato','ok');loadTaxonomy()}
    async function taxDelete(id,name){if(!confirm('Eliminare "'+name+'"?'))return;const r=await ajax('rp_cm_ajax_taxonomy_delete',{term_id:id,taxonomy:taxSource()});if(!r.success){toast('Errore: '+r.data,'err');return}if(taxSelected===id){taxSelected=null;document.getElementById('tax-detail').style.display='none'}toast('Eliminato','ok');loadTaxonomy()}
