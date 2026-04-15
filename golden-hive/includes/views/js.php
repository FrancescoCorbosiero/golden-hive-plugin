const GH = (function() {
    const AJAX  = '<?php echo esc_js( $ajax ); ?>';
    const NONCE = '<?php echo esc_js( $nonce ); ?>';
    let state = { roundtripData:null, importJSON:null, orphans:[], selected:new Set(), bulkJSON:null };
    let taxTree=[], taxSelected=null, taxCollapsed={}, gsProducts=null, gsSelected=new Set(), gsDiffData=null;

    async function ajax(action, body={}) {
        const fd=new FormData(); fd.append('action',action); fd.append('nonce',NONCE);
        Object.entries(body).forEach(([k,v])=>fd.append(k,v));
        return (await fetch(AJAX,{method:'POST',body:fd})).json();
    }
    function toast(msg,type='ok',ms=3000) {
        const t=document.createElement('div'); t.className='toast '+type; t.textContent=msg;
        document.getElementById('gh-toasts').appendChild(t); setTimeout(()=>t.remove(),ms);
    }
    function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML}
    function hl(j){return String(j).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,m=>{let c='jn';if(/^"/.test(m))c=/:$/.test(m)?'jk':'js';else if(/true|false/.test(m))c='jb';else if(/null/.test(m))c='jx';return'<span class="'+c+'">'+m+'</span>'})}
    function fileSize(b){if(b<1024)return b+' B';if(b<1048576)return(b/1024).toFixed(1)+' KB';return(b/1048576).toFixed(1)+' MB'}
    function switchTab(name,el){document.querySelectorAll('#gh .tab-item').forEach(t=>t.classList.remove('active'));document.querySelectorAll('#gh .panel').forEach(p=>p.classList.remove('active'));el.classList.add('active');document.getElementById('panel-'+name).classList.add('active')}
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
