    // ── WHITELIST
    let wlCache = []; // cached list for copy/export

    async function loadWhitelist(){
        const r=await ajax('rp_mm_ajax_get_whitelist');
        if(!r.success){toast('Errore','err');return}
        wlCache=r.data||[];
        const a=document.getElementById('wl-area');
        if(!wlCache.length){a.innerHTML='<div class="empty-state"><div class="empty-icon">&#9737;</div><div class="empty-text">Whitelist vuota. Aggiungi URL dalla toolbar o incolla in bulk.</div></div>';return}
        a.innerHTML='<div style="padding:0 4px 8px;font-family:var(--mono);font-size:10px;color:var(--dim)">'+wlCache.length+' elementi protetti</div>'+wlCache.map(e=>{
            const u=e.url||'';const short=u.replace(/^https?:\/\/[^/]+/,'');
            return'<div class="wl-row"><img class="wl-thumb" src="'+esc(u)+'" onerror="this.style.visibility=\'hidden\'" /><div class="wl-info"><div class="wl-reason mono" style="font-size:10px;color:var(--acc);word-break:break-all" title="'+esc(u)+'">'+esc(short||'#'+(e.id||'?'))+'</div>'+(e.reason?'<div class="wl-name" style="font-size:10px;color:var(--dim)">'+esc(e.reason)+'</div>':'')+'</div><span class="wl-id">#'+(e.id||'?')+'</span><button class="btn btn-ghost" style="font-size:10px" onclick="GH.removeWL('+(e.id||0)+')">Rimuovi</button></div>'
        }).join('');
    }

    // Single add: ID or URL, reason optional
    async function whitelistAdd(){
        const id=parseInt(document.getElementById('wl-add-id').value||'0');
        const url=(document.getElementById('wl-add-url').value||'').trim();
        const reason=(document.getElementById('wl-add-reason').value||'').trim();
        if(!id&&!url){toast('Serve un ID o URL','err');return}
        const sp=document.getElementById('wl-add-spin');
        if(sp)sp.style.display='';
        try{
            const body={};
            if(id)body.attachment_id=id;
            if(url)body.url=url;
            if(reason)body.reason=reason;
            const r=await ajax('rp_mm_ajax_add_whitelist',body);
            if(!r.success){toast(r.data||'Errore','err');return}
            document.getElementById('wl-add-id').value='';
            document.getElementById('wl-add-url').value='';
            document.getElementById('wl-add-reason').value='';
            toast('Aggiunto','ok');loadWhitelist();
        }catch(e){toast('Errore','err')}
        finally{if(sp)sp.style.display='none'}
    }

    // Copy all whitelisted URLs to clipboard
    function wlCopyAll(){
        if(!wlCache.length){toast('Whitelist vuota','inf');return}
        const text=wlCache.map(e=>e.url||'').filter(Boolean).join('\n');
        navigator.clipboard.writeText(text).then(()=>toast(wlCache.length+' URL copiati','ok'),()=>toast('Errore clipboard','err'));
    }

    // Toggle bulk textarea
    function wlToggleBulk(){
        const area=document.getElementById('wl-bulk-area');
        area.style.display=area.style.display==='none'?'':'none';
    }

    // Export current whitelist into the textarea
    function wlBulkExport(){
        const text=wlCache.map(e=>e.url||'').filter(Boolean).join('\n');
        document.getElementById('wl-bulk-text').value=text;
        document.getElementById('wl-bulk-status').textContent=wlCache.length+' URL esportati';
    }

    // Import URLs from textarea (one per line)
    async function wlBulkImport(){
        const raw=document.getElementById('wl-bulk-text').value;
        const urls=raw.split('\n').map(s=>s.trim()).filter(s=>s&&(s.startsWith('http')||s.startsWith('/')));
        if(!urls.length){toast('Nessun URL valido trovato','err');return}
        const sp=document.getElementById('wl-bulk-spin');
        const statusEl=document.getElementById('wl-bulk-status');
        if(sp)sp.style.display='';
        let added=0,skipped=0;
        try{
            // Send in batches of 20
            for(let i=0;i<urls.length;i+=20){
                const batch=urls.slice(i,i+20);
                for(const url of batch){
                    // Check if already in cache
                    if(wlCache.some(e=>e.url===url)){skipped++;continue}
                    await ajax('rp_mm_ajax_add_whitelist',{url:url,reason:'bulk import'});
                    added++;
                }
                if(statusEl)statusEl.textContent='Importazione '+(i+batch.length)+'/'+urls.length+'...';
            }
            toast(added+' aggiunti'+(skipped?' ('+skipped+' duplicati)':''),'ok');
            document.getElementById('wl-bulk-text').value='';
            if(statusEl)statusEl.textContent=added+' importati';
            loadWhitelist();
        }catch(e){toast('Errore: '+e.message,'err')}
        finally{if(sp)sp.style.display='none'}
    }

    async function addWL(id,reason){await ajax('rp_mm_ajax_add_whitelist',{attachment_id:id,reason:reason||''});toast('#'+id+' protetto','ok');loadWhitelist()}
    async function removeWL(id){await ajax('rp_mm_ajax_remove_whitelist',{attachment_id:id});toast('#'+id+' rimosso','ok');loadWhitelist()}

    // ── GS FEED
    async function gsFetch(){const ov=document.getElementById('gs-overlay'),ot=document.getElementById('gs-overlay-text'),btn=document.getElementById('btn-gs-fetch'),sp=document.getElementById('gs-fetch-spin');ot.textContent='Fetch feed...';ov.classList.add('visible');btn.disabled=true;sp.style.display='';try{const cfg={url:document.getElementById('gs-url').value,token:document.getElementById('gs-token').value,cookie:document.getElementById('gs-cookie').value,format:document.getElementById('gs-format').value};const r=await ajax('rp_rc_ajax_gs_fetch',{config:JSON.stringify(cfg)});if(!r.success){toast('Errore: '+r.data,'err');return}gsProducts=r.data.products;toast(r.data.product_count+' prodotti','ok');ot.textContent='Confronto WooCommerce...';const dr=await ajax('rp_rc_ajax_gs_preview',{products:JSON.stringify(gsProducts)});if(!dr.success){toast('Errore diff','err');return}renderGsPreview(dr.data)}catch(e){toast('Errore','err')}finally{ov.classList.remove('visible');btn.disabled=false;sp.style.display='none'}}
    function renderGsPreview(d){const s=d.summary;gsDiffData=d;document.getElementById('gs-stats').style.display='flex';document.getElementById('gs-total').textContent=s.total;document.getElementById('gs-new').textContent=s.new;document.getElementById('gs-update').textContent=s.update;document.getElementById('gs-unchanged').textContent=s.unchanged;const all=[...d.new.map(p=>({...p,_a:'new'})),...d.update.map(p=>({...p,_a:'update'})),...d.unchanged.map(p=>({...p,_a:'unchanged'}))];gsSelected=new Set(all.filter(p=>p._a!=='unchanged').map(p=>p.sku));let h='<table class="ptable"><thead><tr><th style="width:28px"><input type="checkbox" class="gs-check" id="gs-check-all" onchange="GH.gsToggleAll(this.checked)" /></th><th>Azione</th><th>SKU</th><th>Nome</th><th>Brand</th><th>Modello</th><th>Taglie</th><th>Disp.</th></tr></thead><tbody>';for(const p of all){const cls=p._a==='new'?'st-new':p._a==='update'?'st-update':'st-unchanged';const lb=p._a==='new'?'+ Nuovo':p._a==='update'?'\u21bb Agg.':'\u2713';const sz=p.sizes?p.sizes.length:(p.variations?p.variations.length:0);const ck=gsSelected.has(p.sku)?'checked':'';h+='<tr><td><input type="checkbox" class="gs-check" data-sku="'+esc(p.sku)+'" data-type="'+p._a+'" '+ck+' onchange="GH.gsToggle(this)" /></td><td class="'+cls+'">'+lb+'</td><td>'+esc(p.sku||'')+'</td><td>'+esc(p.name||'')+'</td><td>'+esc(p.brand||p._gs_brand||'')+'</td><td>'+esc(p.model||p._gs_model||'')+'</td><td>'+sz+'</td><td>'+(p.total_available??'?')+'</td></tr>'}h+='</tbody></table>';document.getElementById('gs-preview').innerHTML=h;document.getElementById('gs-sel-bar').style.display='flex';gsUpdateSelCount();gsUpdateConfirm()}
    function gsToggle(cb){if(cb.checked)gsSelected.add(cb.dataset.sku);else gsSelected.delete(cb.dataset.sku);document.getElementById('gs-check-all').checked=gsSelected.size===document.querySelectorAll('#gs-preview .gs-check[data-sku]').length;gsUpdateSelCount();gsUpdateConfirm()}
    function gsToggleAll(on){document.querySelectorAll('#gs-preview .gs-check[data-sku]').forEach(c=>{c.checked=on;if(on)gsSelected.add(c.dataset.sku);else gsSelected.delete(c.dataset.sku)});gsUpdateSelCount();gsUpdateConfirm()}
    function gsSelectAll(){document.querySelectorAll('#gs-preview .gs-check[data-sku]').forEach(c=>{c.checked=true;gsSelected.add(c.dataset.sku)});document.getElementById('gs-check-all').checked=true;gsUpdateSelCount();gsUpdateConfirm()}
    function gsSelectNone(){document.querySelectorAll('#gs-preview .gs-check[data-sku]').forEach(c=>{c.checked=false});gsSelected.clear();document.getElementById('gs-check-all').checked=false;gsUpdateSelCount();gsUpdateConfirm()}
    function gsSelectByType(type){document.querySelectorAll('#gs-preview .gs-check[data-sku]').forEach(c=>{const on=c.dataset.type===type;c.checked=on;if(on)gsSelected.add(c.dataset.sku);else gsSelected.delete(c.dataset.sku)});document.getElementById('gs-check-all').checked=false;gsUpdateSelCount();gsUpdateConfirm()}
    function gsUpdateSelCount(){const n=gsSelected.size;document.getElementById('gs-sel-count').textContent=n+' selezionat'+(n===1?'o':'i')}
    function gsUpdateConfirm(){const bar=document.getElementById('gs-confirm');if(!gsSelected.size){bar.style.display='none';return}let nn=0,nu=0;gsSelected.forEach(sku=>{if(gsDiffData.new.some(p=>p.sku===sku))nn++;else if(gsDiffData.update.some(p=>p.sku===sku))nu++});let msg='';if(nn)msg+='<span>'+nn+'</span> nuov'+(nn===1?'o':'i');if(nn&&nu)msg+=', ';if(nu)msg+='<span>'+nu+'</span> da aggiornare';if(!nn&&!nu){bar.style.display='none';return}document.getElementById('gs-confirm-text').innerHTML=msg;bar.style.display='flex'}
    async function gsApply(){if(!gsProducts||!gsSelected.size)return;const sel=gsProducts.filter(p=>gsSelected.has(p.sku));const ov=document.getElementById('gs-overlay'),ot=document.getElementById('gs-overlay-text'),btn=document.getElementById('btn-gs-apply'),sp=document.getElementById('gs-apply-spin');ot.textContent='Importazione '+sel.length+' prodott'+(sel.length===1?'o':'i')+'...';ov.classList.add('visible');btn.disabled=true;sp.style.display='';await acquireWakeLock();try{const asDraft=document.getElementById('gs-opt-draft')?.checked||false;const r=await ajax('rp_rc_ajax_gs_apply',{products:JSON.stringify(sel),options:JSON.stringify({create_new:true,update_existing:true,sideload_images:document.getElementById('gs-opt-images').checked,status:asDraft?'draft':'publish'})});if(!r.success){toast('Errore','err');return}const s=r.data.summary;let h='<table class="ptable"><thead><tr><th>Risultato</th><th>ID</th><th>SKU</th><th>Nome</th></tr></thead><tbody>';for(const d of r.data.details){const c=d.action==='created'?'st-created':d.action==='updated'?'st-updated':'st-error';const l=d.action==='created'?'+ Creato':d.action==='updated'?'\u2713 Agg.':'\u2717 Err';h+='<tr><td class="'+c+'">'+l+'</td><td>'+(d.id||'\u2013')+'</td><td>'+esc(d.sku||'')+'</td><td>'+esc(d.name||'')+'</td></tr>'}h+='</tbody></table>';document.getElementById('gs-preview').innerHTML=h;document.getElementById('gs-confirm').style.display='none';document.getElementById('gs-sel-bar').style.display='none';toast(s.created+' creati, '+s.updated+' aggiornati','ok',5000)}catch(e){toast('Errore','err')}finally{releaseWakeLock();ov.classList.remove('visible');btn.disabled=false;sp.style.display='none'}}
    async function gsQuickPatch(){if(!gsProducts||!gsSelected.size)return;const sel=gsProducts.filter(p=>gsSelected.has(p.sku));const ov=document.getElementById('gs-overlay'),ot=document.getElementById('gs-overlay-text'),btn=document.getElementById('btn-gs-quickpatch'),sp=document.getElementById('gs-quickpatch-spin');ot.textContent='Quick patch '+sel.length+' prodott'+(sel.length===1?'o':'i')+'...';ov.classList.add('visible');btn.disabled=true;sp.style.display='';try{const r=await ajax('rp_rc_ajax_gs_quick_patch',{products:JSON.stringify(sel)});if(!r.success){toast('Errore','err');return}const s=r.data.summary;let h='<table class="ptable"><thead><tr><th>Risultato</th><th>ID</th><th>SKU</th><th>Nome</th></tr></thead><tbody>';for(const d of r.data.details){const c=d.action==='patched'?'st-updated':'st-error';h+='<tr><td class="'+c+'">'+(d.action==='patched'?'\u2713 Patch':'\u2717 Err')+'</td><td>'+(d.id||'\u2013')+'</td><td>'+esc(d.sku||'')+'</td><td>'+esc(d.name||'')+'</td></tr>'}h+='</tbody></table>';document.getElementById('gs-preview').innerHTML=h;document.getElementById('gs-confirm').style.display='none';toast(s.patched+' aggiornati, '+s.skipped+' invariati','ok',5000)}catch(e){toast('Errore','err')}finally{ov.classList.remove('visible');btn.disabled=false;sp.style.display='none'}}
    function gsCancel(){document.getElementById('gs-confirm').style.display='none';document.getElementById('gs-sel-bar').style.display='none'}

    // ── BULK IMPORT
    function initBulkImport(){const drop=document.getElementById('imp-drop'),inp=document.getElementById('imp-file-input');inp.addEventListener('change',()=>{if(inp.files.length)handleBulkFile(inp.files[0])});drop.addEventListener('dragover',e=>{e.preventDefault();drop.classList.add('dragover')});drop.addEventListener('dragleave',()=>drop.classList.remove('dragover'));drop.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('dragover');if(e.dataTransfer.files.length)handleBulkFile(e.dataTransfer.files[0])})}
    function handleBulkFile(f){if(!f.name.endsWith('.json')){toast('Solo .json','err');return}const r=new FileReader();r.onload=()=>{try{let d=JSON.parse(r.result);if(Array.isArray(d))d={products:d};if(!d.products?.length){toast('Nessun prodotto','err');return}state.bulkJSON=d;document.getElementById('imp-file-name').textContent=f.name+' \u00b7 '+d.products.length+' prodotti';document.getElementById('imp-mode-row').style.display='flex';document.getElementById('imp-preview-area').innerHTML='';document.getElementById('imp-confirm-bar').style.display='none';toast(d.products.length+' prodotti','inf')}catch(e){toast('JSON non valido','err')}};r.readAsText(f)}
    async function bulkPreview(){if(!state.bulkJSON)return;const btn=document.getElementById('btn-imp-preview'),sp=document.getElementById('imp-preview-spin');btn.disabled=true;sp.style.display='';try{const m=document.querySelector('input[name="bulk-mode"]:checked').value;const r=await ajax('rp_cm_ajax_bulk_preview',{json_payload:JSON.stringify(state.bulkJSON),mode:m});if(!r.success){toast('Errore: '+r.data,'err');return}const s=r.data.summary,a=document.getElementById('imp-preview-area');let h='<table class="ptable"><thead><tr><th>Azione</th><th>Nome</th><th>SKU</th><th>Tipo</th><th>Varianti</th></tr></thead><tbody>';for(const d of r.data.details){const ic=d.action==='create'?'st-create':'st-matched';h+='<tr><td class="'+ic+'">'+(d.action==='create'?'+ Nuovo':'\u21bb #'+d.existing_id)+'</td><td>'+esc(d.name)+'</td><td>'+esc(d.sku||'\u2013')+'</td><td>'+d.type+'</td><td>'+(d.variation_count||'\u2013')+'</td></tr>'}h+='</tbody></table>';a.innerHTML=h;document.getElementById('imp-confirm-text').innerHTML='<span>'+s.to_create+'</span> da creare'+(s.to_update?', <span>'+s.to_update+'</span> da aggiornare':'');document.getElementById('imp-confirm-bar').style.display='flex'}catch(e){toast('Errore','err')}finally{btn.disabled=false;sp.style.display='none'}}
    async function bulkApply(){if(!state.bulkJSON)return;const ov=document.getElementById('imp-overlay'),ot=document.getElementById('imp-overlay-text'),btn=document.getElementById('btn-imp-apply'),sp=document.getElementById('imp-apply-spin');ot.textContent='Creazione prodotti...';ov.classList.add('visible');btn.disabled=true;sp.style.display='';try{const m=document.querySelector('input[name="bulk-mode"]:checked').value;const r=await ajax('rp_cm_ajax_bulk_apply',{json_payload:JSON.stringify(state.bulkJSON),mode:m});if(!r.success){toast('Errore','err');return}const s=r.data.summary,a=document.getElementById('imp-preview-area');let h='<table class="ptable"><thead><tr><th>Risultato</th><th>ID</th><th>Nome</th><th>SKU</th><th>Varianti</th></tr></thead><tbody>';for(const d of r.data.details){const c=d.status==='created'?'st-created':d.status==='updated'?'st-updated':'st-error';h+='<tr><td class="'+c+'">'+(d.status==='created'?'+ Creato':d.status==='updated'?'\u2713 Agg.':'\u2717 Err')+'</td><td>'+(d.id||'\u2013')+'</td><td>'+esc(d.name||'')+'</td><td>'+esc(d.sku||'')+'</td><td>'+(d.variation_count||'\u2013')+'</td></tr>'}h+='</tbody></table>';a.innerHTML=h;document.getElementById('imp-confirm-bar').style.display='none';toast(s.created+' creati, '+s.errors+' errori',s.errors?'err':'ok',5000)}catch(e){toast('Errore','err')}finally{ov.classList.remove('visible');btn.disabled=false;sp.style.display='none'}}
    function bulkCancel(){document.getElementById('imp-confirm-bar').style.display='none';document.getElementById('imp-preview-area').innerHTML=''}

    // ── ROUNDTRIP EXPORT
    async function generateRoundtrip(){const ov=document.getElementById('rt-overlay'),ot=document.getElementById('rt-overlay-text'),btn=document.getElementById('btn-rt-export'),sp=document.getElementById('rt-spin');ot.textContent='Generazione export...';ov.classList.add('visible');btn.disabled=true;sp.style.display='';try{const r=await ajax('rp_cm_ajax_export_roundtrip',{filters:JSON.stringify(getFilters('rt'))});if(!r.success){toast('Errore','err');return}state.roundtripData=r.data;const j=JSON.stringify(r.data,null,2);document.getElementById('btn-rt-copy').style.display='';document.getElementById('btn-rt-download').style.display='';document.getElementById('rt-size').textContent=fileSize(new Blob([j]).size)+' \u00b7 '+r.data.product_count+' prodotti';toast('Export: '+r.data.product_count+' prodotti','ok')}catch(e){toast('Errore','err')}finally{ov.classList.remove('visible');btn.disabled=false;sp.style.display='none'}}

    // ── ROUNDTRIP IMPORT
    function initRtImport(){const drop=document.getElementById('rt-drop'),inp=document.getElementById('rt-file-input');inp.addEventListener('change',()=>{if(inp.files.length)handleRtFile(inp.files[0])});drop.addEventListener('dragover',e=>{e.preventDefault();drop.classList.add('dragover')});drop.addEventListener('dragleave',()=>drop.classList.remove('dragover'));drop.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('dragover');if(e.dataTransfer.files.length)handleRtFile(e.dataTransfer.files[0])})}
    function handleRtFile(f){if(!f.name.endsWith('.json')){toast('Solo .json','err');return}const r=new FileReader();r.onload=()=>{try{const d=JSON.parse(r.result);if(d.format!=='rp_cm_roundtrip'){toast('Formato non valido','err');return}state.importJSON=d;document.getElementById('rt-file-name').textContent=f.name+' \u00b7 '+(d.product_count||d.products?.length||0)+' prodotti';document.getElementById('rt-mode-row').style.display='flex';document.getElementById('rt-preview-area').innerHTML='';document.getElementById('rt-confirm-bar').style.display='none';toast('File caricato','inf')}catch(e){toast('JSON non valido','err')}};r.readAsText(f)}
    async function importPreview(){if(!state.importJSON)return;const btn=document.getElementById('btn-rt-preview'),sp=document.getElementById('preview-spin');btn.disabled=true;sp.style.display='';try{const m=document.querySelector('input[name="import-mode"]:checked').value;const r=await ajax('rp_cm_ajax_import_preview',{json_payload:JSON.stringify(state.importJSON),mode:m});if(!r.success){toast('Errore: '+r.data,'err');return}const s=r.data.summary,a=document.getElementById('rt-preview-area');let h='<table class="ptable"><thead><tr><th>Stato</th><th>ID</th><th>SKU</th><th>Nome</th><th>Modifiche</th></tr></thead><tbody>';for(const d of r.data.details){const c=d.status==='matched'?'st-matched':d.status==='would_create'?'st-create':'st-skipped';const l=d.status==='matched'?(d.changes.length?'\u2713 mod':'\u2713 ok'):d.status==='would_create'?'+ nuovo':'\u2013 skip';const ch=d.changes?.map(c=>c.field).join(', ')||(d.reason||'');h+='<tr><td class="'+c+'">'+l+'</td><td>'+(d.id||'\u2013')+'</td><td>'+esc(d.sku||'')+'</td><td>'+esc(d.name||'')+'</td><td><span class="changes-list">'+esc(ch)+'</span></td></tr>'}h+='</tbody></table>';a.innerHTML=h;if(s.with_changes>0||s.would_create>0){let msg='<span>'+s.with_changes+'</span> da aggiornare';if(s.would_create)msg+=', <span>'+s.would_create+'</span> nuovi';document.getElementById('rt-confirm-text').innerHTML=msg;document.getElementById('rt-confirm-bar').style.display='flex'}else{toast('Nessuna modifica','inf')}}catch(e){toast('Errore','err')}finally{btn.disabled=false;sp.style.display='none'}}
    async function importApply(){if(!state.importJSON)return;const ov=document.getElementById('rt-overlay'),ot=document.getElementById('rt-overlay-text'),btn=document.getElementById('btn-rt-apply'),sp=document.getElementById('apply-spin');ot.textContent='Applicazione...';ov.classList.add('visible');btn.disabled=true;sp.style.display='';try{const m=document.querySelector('input[name="import-mode"]:checked').value;const r=await ajax('rp_cm_ajax_import_apply',{json_payload:JSON.stringify(state.importJSON),mode:m});if(!r.success){toast('Errore','err');return}const s=r.data.summary,a=document.getElementById('rt-preview-area');let h='<table class="ptable"><thead><tr><th>Risultato</th><th>ID</th><th>SKU</th><th>Nome</th></tr></thead><tbody>';for(const d of r.data.details){const c=d.status==='updated'?'st-updated':d.status==='created'?'st-created':d.status==='error'?'st-error':'st-skipped';h+='<tr><td class="'+c+'">'+esc(d.status)+'</td><td>'+(d.id||'\u2013')+'</td><td>'+esc(d.sku||'')+'</td><td>'+esc(d.name||'')+'</td></tr>'}h+='</tbody></table>';a.innerHTML=h;document.getElementById('rt-confirm-bar').style.display='none';toast(s.updated+' aggiornati','ok',5000)}catch(e){toast('Errore','err')}finally{ov.classList.remove('visible');btn.disabled=false;sp.style.display='none'}}
    function importCancel(){document.getElementById('rt-confirm-bar').style.display='none';document.getElementById('rt-preview-area').innerHTML=''}

    // ── COPY / DOWNLOAD JSON (solo roundtrip — il catalog export e stato rimosso)
    function copyJSON(mode){const d=state.roundtripData;if(!d){toast('Nessun dato','err');return}navigator.clipboard.writeText(JSON.stringify(d,null,2)).then(()=>toast('Copiato','ok'),()=>toast('Errore','err'))}
    function downloadJSON(mode){const d=state.roundtripData;if(!d)return;const j=JSON.stringify(d,null,2),b=new Blob([j],{type:'application/json'}),u=URL.createObjectURL(b),a=document.createElement('a'),dt=new Date().toISOString().slice(0,10);a.href=u;a.download='rp-roundtrip-'+dt+'.json';a.click();URL.revokeObjectURL(u);toast('Download avviato','inf')}

    // ── HTTP CLIENT
    async function hcExecute(){const btn=document.querySelector('#panel-httpclient .btn-primary'),sp=document.getElementById('hc-spin');btn.disabled=true;sp.style.display='';try{const hdrs=document.getElementById('hc-headers').value;const cfg={url:document.getElementById('hc-url').value,method:document.getElementById('hc-method').value,headers:hdrs?JSON.parse(hdrs):{},body:document.getElementById('hc-body').value};const r=await ajax('rp_rc_ajax_execute',{config:JSON.stringify(cfg)});const out=document.getElementById('hc-response');if(!r.success){out.textContent='Errore: '+r.data;return}let h='<div style="margin-bottom:12px;color:var(--dim)">HTTP '+r.data.status+' \u00b7 '+r.data.duration_ms+'ms</div>';h+=r.data.parsed?hl(JSON.stringify(r.data.parsed,null,2)):esc(r.data.body_raw||'');out.innerHTML=h}catch(e){toast('Errore','err')}finally{btn.disabled=false;sp.style.display='none'}}

    // ── STOCKFIRMATI FEED ──────────────────────────────────
    let sfProducts = null, sfSelected = new Set(), sfDiffData = null, sfAllItems = [];
    let sfPreimportAbort = false;

    function sfGetMarkup() { return parseFloat(document.getElementById('sf-markup').value) || 3.5; }

    async function sfLoadSettings() {
        const r = await ajax('gh_ajax_feed_load_settings', { feed_key: 'stockfirmati' });
        if (r.success && r.data) {
            if (r.data.url) document.getElementById('sf-url').value = r.data.url;
            if (r.data.markup) document.getElementById('sf-markup').value = r.data.markup;
        }
    }

    async function sfSaveSettings() {
        const s = { url: document.getElementById('sf-url').value, markup: sfGetMarkup() };
        await ajax('gh_ajax_feed_save_settings', { feed_key: 'stockfirmati', settings: JSON.stringify(s) });
        toast('Impostazioni salvate', 'ok');
    }

    function sfToggleSource() {
        const type = document.getElementById('sf-source-type').value;
        document.getElementById('sf-source-url-row').style.display = type === 'url' ? '' : 'none';
        document.getElementById('sf-source-file-row').style.display = type === 'file' ? '' : 'none';
    }

    function initSfFeed() {
        const drop = document.getElementById('sf-drop');
        const inp = document.getElementById('sf-file-input');
        if (drop && inp) {
            inp.addEventListener('change', () => { if (inp.files.length) sfUploadFile(inp.files[0]); });
            drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('dragover'); });
            drop.addEventListener('dragleave', () => drop.classList.remove('dragover'));
            drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('dragover'); if (e.dataTransfer.files.length) sfUploadFile(e.dataTransfer.files[0]); });
        }
        sfLoadSettings();
        sfLoadCached();
    }

    async function sfLoadCached() {
        try {
            const r = await ajax('gh_ajax_fc_load_cached', { config_id: 'stockfirmati' });
            if (!r.success || !r.data || !r.data.products?.length) return;
            sfProducts = r.data.products;
            document.getElementById('sf-csv-rows').textContent = r.data.csv_rows || '?';
            const age = r.data.fetched_at || '';
            toast(r.data.products.length + ' prodotti (cache' + (age ? ' ' + age : '') + ')', 'inf', 3000);
            const dr = await ajax('gh_ajax_fc_preview', { products: JSON.stringify(sfProducts), config_id: 'stockfirmati', markup: 1 });
            if (dr.success) sfRenderPreview(dr.data);
        } catch (e) { /* silent — no cached data is fine */ }
    }

    async function sfFetch() {
        const ov = document.getElementById('sf-overlay'), ot = document.getElementById('sf-overlay-text');
        const btn = document.getElementById('btn-sf-fetch'), sp = document.getElementById('sf-fetch-spin');
        ot.textContent = 'Fetch CSV...';
        ov.classList.add('visible'); btn.disabled = true; sp.style.display = '';
        try {
            const url = document.getElementById('sf-url').value;
            const r = await ajax('gh_ajax_fc_fetch', { url, config_id: 'stockfirmati' });
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            sfProducts = r.data.products;
            document.getElementById('sf-csv-rows').textContent = r.data.csv_rows;
            toast(r.data.product_count + ' prodotti', 'ok');
            ot.textContent = 'Confronto WooCommerce...';
            const dr = await ajax('gh_ajax_fc_preview', { products: JSON.stringify(sfProducts), config_id: 'stockfirmati', markup: 1 });
            if (!dr.success) { toast('Errore diff', 'err'); return; }
            sfRenderPreview(dr.data);
        } catch (e) { toast('Errore', 'err'); }
        finally { ov.classList.remove('visible'); btn.disabled = false; sp.style.display = 'none'; }
    }

    async function sfUploadFile(file) {
        const ov = document.getElementById('sf-overlay'), ot = document.getElementById('sf-overlay-text');
        ot.textContent = 'Upload e parsing...'; ov.classList.add('visible');
        try {
            const fd = new FormData();
            fd.append('action', 'gh_ajax_fc_upload'); fd.append('config_id', 'stockfirmati');
            fd.append('nonce', NONCE); fd.append('csv_file', file);
            const resp = await fetch(AJAX, { method: 'POST', body: fd });
            const r = await resp.json();
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            document.getElementById('sf-file-name').textContent = file.name + ' \u00b7 ' + r.data.csv_rows + ' righe \u00b7 ' + r.data.product_count + ' prodotti';
            sfProducts = r.data.products;
            document.getElementById('sf-csv-rows').textContent = r.data.csv_rows;
            toast(r.data.product_count + ' prodotti', 'ok');
            ot.textContent = 'Confronto WooCommerce...';
            const dr = await ajax('gh_ajax_fc_preview', { products: JSON.stringify(sfProducts), config_id: 'stockfirmati', markup: 1 });
            if (!dr.success) { toast('Errore diff', 'err'); return; }
            sfRenderPreview(dr.data);
        } catch (e) { toast('Errore', 'err'); }
        finally { ov.classList.remove('visible'); }
    }

    function sfRenderPreview(d) {
        const s = d.summary; sfDiffData = d;
        document.getElementById('sf-stats').style.display = 'flex';
        document.getElementById('sf-total').textContent = s.total;
        document.getElementById('sf-new').textContent = s.new;
        document.getElementById('sf-update').textContent = s.update;
        document.getElementById('sf-unchanged').textContent = s.unchanged;
        sfAllItems = [...d.new.map(p => ({...p, _a: 'new'})), ...d.update.map(p => ({...p, _a: 'update'})), ...d.unchanged.map(p => ({...p, _a: 'unchanged'}))];
        sfSelected = new Set(sfAllItems.filter(p => p._a !== 'unchanged').map(p => p.sku));
        document.getElementById('sf-search').value = '';
        document.getElementById('sf-sel-bar').style.display = 'flex';
        sfRenderTable(sfAllItems);
        sfUpdateSelCount(); sfUpdateConfirm();
    }

    function sfRenderTable(items) {
        let h = '<table class="ptable"><thead><tr><th style="width:28px"><input type="checkbox" id="sf-check-all" onchange="GH.sfToggleAll(this.checked)" /></th><th>Azione</th><th>SKU</th><th>Nome</th><th>Brand</th><th>Cat</th><th>Taglie</th><th>Qty</th><th>Costo</th><th>Listino</th></tr></thead><tbody>';
        for (const p of items) {
            const cls = p._a === 'new' ? 'st-new' : p._a === 'update' ? 'st-update' : 'st-unchanged';
            const lb = p._a === 'new' ? '+ Nuovo' : p._a === 'update' ? '\u21bb Agg.' : '\u2713';
            const sz = p.variations ? p.variations.length : 0;
            const brand = p._fc_brand || p._sf_brand || '';
            const cat = (p._fc_category || p._sf_category || '') + ((p._fc_subcategory || p._sf_subcategory) ? ' > ' + (p._fc_subcategory || p._sf_subcategory) : '');
            const ck = sfSelected.has(p.sku) ? 'checked' : '';
            const qty = p.stock_quantity ?? (p.variations ? p.variations.reduce((a, v) => a + (v.stock_quantity || 0), 0) : '?');
            const sale = p.sale_price || (p.variations?.[0]?.sale_price || '');
            const reg = p.regular_price || (p.variations?.[0]?.regular_price || '');
            h += '<tr><td><input type="checkbox" class="sf-check" data-sku="' + esc(p.sku) + '" data-type="' + p._a + '" ' + ck + ' onchange="GH.sfToggle(this)" /></td>';
            h += '<td class="' + cls + '">' + lb + '</td><td style="font-size:10px">' + esc(p.sku || '') + '</td>';
            h += '<td>' + esc(p.name || '') + '</td><td>' + esc(brand) + '</td>';
            h += '<td style="font-size:10px">' + esc(cat) + '</td><td>' + sz + '</td><td>' + qty + '</td>';
            h += '<td>' + (sale ? sale + '\u20ac' : '\u2013') + '</td><td style="text-decoration:line-through;color:var(--dim)">' + (reg ? reg + '\u20ac' : '') + '</td></tr>';
        }
        if (!items.length) h += '<tr><td colspan="10" style="text-align:center;color:var(--dim);padding:20px">Nessun risultato</td></tr>';
        h += '</tbody></table>';
        document.getElementById('sf-preview').innerHTML = h;
    }

    function sfFilterList() {
        const q = (document.getElementById('sf-search').value || '').toLowerCase().trim();
        if (!q) { sfRenderTable(sfAllItems); return; }
        const words = q.split(/\s+/);
        const filtered = sfAllItems.filter(p => {
            const haystack = [p.name, p.sku, p._fc_brand || p._sf_brand || '', p._fc_category || p._sf_category || '', p._fc_subcategory || p._sf_subcategory || ''].join(' ').toLowerCase();
            return words.every(w => haystack.includes(w));
        });
        sfRenderTable(filtered);
    }

    function sfToggle(cb) { if (cb.checked) sfSelected.add(cb.dataset.sku); else sfSelected.delete(cb.dataset.sku); sfUpdateSelCount(); sfUpdateConfirm(); }
    function sfToggleAll(on) { document.querySelectorAll('#sf-preview .sf-check').forEach(c => { c.checked = on; if (on) sfSelected.add(c.dataset.sku); else sfSelected.delete(c.dataset.sku); }); sfUpdateSelCount(); sfUpdateConfirm(); }
    function sfSelectAll() { sfAllItems.forEach(p => sfSelected.add(p.sku)); sfRenderTable(sfAllItems); sfUpdateSelCount(); sfUpdateConfirm(); }
    function sfSelectNone() { sfSelected.clear(); sfRenderTable(sfAllItems); sfUpdateSelCount(); sfUpdateConfirm(); }
    function sfSelectByType(type) { sfSelected.clear(); sfAllItems.filter(p => p._a === type).forEach(p => sfSelected.add(p.sku)); sfRenderTable(sfAllItems); sfUpdateSelCount(); sfUpdateConfirm(); }
    function sfUpdateSelCount() { const n = sfSelected.size; document.getElementById('sf-sel-count').textContent = n + ' selezionat' + (n === 1 ? 'o' : 'i'); }
    function sfUpdateConfirm() {
        const bar = document.getElementById('sf-confirm');
        if (!sfSelected.size) { bar.style.display = 'none'; return; }
        let nn = 0, nu = 0;
        sfSelected.forEach(sku => { if (sfDiffData.new.some(p => p.sku === sku)) nn++; else if (sfDiffData.update.some(p => p.sku === sku)) nu++; });
        let msg = '';
        if (nn) msg += '<span>' + nn + '</span> nuov' + (nn === 1 ? 'o' : 'i');
        if (nn && nu) msg += ', ';
        if (nu) msg += '<span>' + nu + '</span> da aggiornare';
        if (!nn && !nu) { bar.style.display = 'none'; return; }
        document.getElementById('sf-confirm-text').innerHTML = msg;
        bar.style.display = 'flex';
    }

    // SF Pre-Import Media: scarica tutte le immagini dei prodotti selezionati
    // nella WP media library PRIMA dell'import. Costruisce una mappa
    // source_url → attachment_id che il product create usa per assegnare
    // featured/gallery senza download.
    async function sfPreimportMedia() {
        if (!sfProducts || !sfSelected.size) { toast('Nessun prodotto selezionato', 'err'); return; }
        const sel = sfProducts.filter(p => sfSelected.has(p.sku));

        // Raccogli tutti gli URL immagine unici.
        // sfProducts ha shape { sku, row: {PICTURE_1, PICTURE_2, ...}, sizes }
        // Le colonne immagine sono PICTURE_1, PICTURE_2, PICTURE_3 (da config SF).
        // Fallback: scansiona tutte le colonne di row che contengono URL http.
        const urlMap = new Map(); // url → sku
        sel.forEach(p => {
            const sku = p.sku || '';
            const row = p.row || {};
            // Try known image columns first
            ['PICTURE_1','PICTURE_2','PICTURE_3','PICTURE_4','PICTURE_5','image_url','image'].forEach(col => {
                const url = (row[col] || '').trim();
                if (url && url.startsWith('http') && !urlMap.has(url)) urlMap.set(url, sku);
            });
            // Also check p.images / p._sf_images (legacy direct SF path)
            (p.images || p._sf_images || []).forEach(url => {
                if (url && !urlMap.has(url)) urlMap.set(url, sku);
            });
        });

        const allUrls = Array.from(urlMap.entries()).map(([url, sku]) => ({ url, sku }));
        if (!allUrls.length) { toast('Nessuna immagine trovata nei prodotti selezionati', 'inf'); return; }

        const total = allUrls.length;
        const batchSize = 50;
        const ov = document.getElementById('sf-overlay'), ot = document.getElementById('sf-overlay-text');
        const btn = document.getElementById('btn-sf-preimport'), sp = document.getElementById('sf-preimport-spin');
        const statusEl = document.getElementById('sf-preimport-status');
        ov.classList.add('visible'); btn.disabled = true; if (sp) sp.style.display = '';
        sfPreimportAbort = false;
        await acquireWakeLock();

        let downloaded = 0, skipped = 0, errors = 0;

        try {
            for (let offset = 0; offset < total; offset += batchSize) {
                if (sfPreimportAbort) { toast('Interrotto. ' + downloaded + ' scaricate finora.', 'inf'); break; }
                const batch = allUrls.slice(offset, offset + batchSize);
                const done = Math.min(offset + batchSize, total);
                ot.textContent = 'Scaricamento immagini ' + done + '/' + total + '... (click Stop per interrompere)';

                const r = await ajax('gh_ajax_preimport_download', {
                    urls: JSON.stringify(batch),
                });

                if (!r.success) {
                    toast('Errore batch immagini: ' + (r.data || ''), 'err');
                    break;
                }

                downloaded += r.data.downloaded || 0;
                skipped    += r.data.skipped || 0;
                errors     += r.data.errors || 0;
            }

            const msg = downloaded + ' scaricate, ' + skipped + ' gia presenti' + (errors ? ', ' + errors + ' errori' : '');
            toast(msg, errors ? 'err' : 'ok', 5000);
            if (statusEl) statusEl.textContent = msg;
        } catch (e) {
            toast('Errore pre-import: ' + (e.message || e), 'err');
        } finally {
            releaseWakeLock(); ov.classList.remove('visible'); btn.disabled = false; if (sp) sp.style.display = 'none';
        }
    }

    function sfPreimportStop() { sfPreimportAbort = true; }

    async function sfValidateMap() {
        const btn = document.getElementById('btn-sf-validate-map'), sp = document.getElementById('sf-validate-spin');
        const statusEl = document.getElementById('sf-preimport-status');
        btn.disabled = true; sp.style.display = '';
        try {
            const r = await ajax('gh_ajax_preimport_validate');
            if (!r.success) { toast('Errore: ' + (r.data || ''), 'err'); return; }
            const d = r.data;
            const msg = d.valid + ' valide, ' + d.pruned + ' rimosse';
            if (statusEl) statusEl.textContent = msg;
            toast(msg, d.pruned ? 'inf' : 'ok', 5000);
        } catch (e) { toast('Errore validazione', 'err'); }
        finally { btn.disabled = false; sp.style.display = 'none'; }
    }

    // SF Apply: chunked per evitare timeout su import grandi (2000+ prodotti).
    // Invia batch da 25 prodotti per request. Il PHP processa ogni batch e
    // ritorna i risultati parziali. Il JS accumula e mostra il progresso.
    async function sfApply() {
        if (!sfProducts || !sfSelected.size) return;
        const sel = sfProducts.filter(p => sfSelected.has(p.sku));
        const total = sel.length;
        const chunkSize = 25;
        const ov = document.getElementById('sf-overlay'), ot = document.getElementById('sf-overlay-text');
        const btn = document.getElementById('btn-sf-apply'), sp = document.getElementById('sf-apply-spin');
        ov.classList.add('visible'); btn.disabled = true; sp.style.display = '';
        await acquireWakeLock();

        const sideload = document.getElementById('sf-opt-images')?.checked || false;
        const asDraft = document.getElementById('sf-opt-draft')?.checked || false;
        const opts = {
            create_new: true,
            update_existing: true,
            sideload_images: sideload,
            status: asDraft ? 'draft' : 'publish',
        };

        let allDetails = [];
        let totCreated = 0, totUpdated = 0, totErrors = 0;

        try {
            for (let offset = 0; offset < total; offset += chunkSize) {
                const chunk = sel.slice(offset, offset + chunkSize);
                const done = Math.min(offset + chunkSize, total);
                ot.textContent = 'Importazione ' + done + '/' + total + (sideload ? ' (con immagini)' : '') + '...';

                const r = await ajax('gh_ajax_fc_apply', {
                    config_id: 'stockfirmati',
                    markup: sfGetMarkup(),
                    products: JSON.stringify(chunk),
                    options: JSON.stringify(opts),
                });

                if (!r.success) {
                    toast('Errore al batch ' + done + ': ' + (r.data || 'timeout'), 'err');
                    totErrors++;
                    continue;
                }

                allDetails = allDetails.concat(r.data.details || []);
                totCreated += r.data.summary?.created || 0;
                totUpdated += r.data.summary?.updated || 0;
                totErrors  += r.data.summary?.errors || 0;
                if (r.data.partial) toast('Batch ' + done + ': completamento parziale', 'inf');
            }

            // Render combined results
            let h = '<table class="ptable"><thead><tr><th>Risultato</th><th>ID</th><th>SKU</th><th>Nome</th></tr></thead><tbody>';
            for (const d of allDetails) {
                const c = d.action === 'created' ? 'st-created' : d.action === 'updated' ? 'st-updated' : 'st-error';
                const l = d.action === 'created' ? '+ Creato' : d.action === 'updated' ? '\u2713 Agg.' : '\u2717 Err';
                h += '<tr><td class="' + c + '">' + l + '</td><td>' + (d.id || '\u2013') + '</td><td>' + esc(d.sku || '') + '</td><td>' + esc(d.name || '') + '</td></tr>';
            }
            h += '</tbody></table>';
            document.getElementById('sf-preview').innerHTML = h;
            document.getElementById('sf-confirm').style.display = 'none';
            document.getElementById('sf-sel-bar').style.display = 'none';
            toast(totCreated + ' creati, ' + totUpdated + ' aggiornati' + (totErrors ? ', ' + totErrors + ' errori' : '') + (asDraft ? ' (come bozze)' : ''), totErrors ? 'err' : 'ok', 5000);
        } catch (e) { toast('Errore / timeout: ' + (e.message || e), 'err'); }
        finally { releaseWakeLock(); ov.classList.remove('visible'); btn.disabled = false; sp.style.display = 'none'; }
    }

    async function sfQuickPatch() {
        if (!sfProducts || !sfSelected.size) return;
        const sel = sfProducts.filter(p => sfSelected.has(p.sku));
        const total = sel.length;
        const chunkSize = 50;
        const ov = document.getElementById('sf-overlay'), ot = document.getElementById('sf-overlay-text');
        const btn = document.getElementById('btn-sf-quickpatch'), sp = document.getElementById('sf-quickpatch-spin');
        ov.classList.add('visible'); btn.disabled = true; sp.style.display = '';

        let totPatched = 0, totSkipped = 0, totErrors = 0, allDetails = [];
        try {
            for (let offset = 0; offset < total; offset += chunkSize) {
                const chunk = sel.slice(offset, offset + chunkSize);
                const done = Math.min(offset + chunkSize, total);
                ot.textContent = 'Quick patch ' + done + '/' + total + '...';
                const r = await ajax('gh_ajax_fc_quick_patch', {
                    config_id: 'stockfirmati', markup: sfGetMarkup(),
                    products: JSON.stringify(chunk),
                });
                if (!r.success) { toast('Errore: ' + (r.data || ''), 'err'); continue; }
                totPatched += r.data.summary?.patched || 0;
                totSkipped += r.data.summary?.skipped || 0;
                totErrors  += r.data.summary?.errors || 0;
                allDetails = allDetails.concat(r.data.details || []);
            }
            let h = '<table class="ptable"><thead><tr><th>Risultato</th><th>ID</th><th>SKU</th><th>Nome</th><th>Variazioni</th></tr></thead><tbody>';
            for (const d of allDetails) {
                const c = d.action === 'patched' ? 'st-updated' : 'st-error';
                const l = d.action === 'patched' ? '\u2713 Patch' : '\u2717 Err';
                h += '<tr><td class="' + c + '">' + l + '</td><td>' + (d.id || '\u2013') + '</td><td>' + esc(d.sku || '') + '</td><td>' + esc(d.name || '') + '</td><td>' + (d.changes || '') + '</td></tr>';
            }
            h += '</tbody></table>';
            document.getElementById('sf-preview').innerHTML = h;
            document.getElementById('sf-confirm').style.display = 'none';
            toast(totPatched + ' aggiornati, ' + totSkipped + ' invariati' + (totErrors ? ', ' + totErrors + ' errori' : ''), totErrors ? 'err' : 'ok', 5000);
        } catch (e) { toast('Errore: ' + (e.message || e), 'err'); }
        finally { ov.classList.remove('visible'); btn.disabled = false; sp.style.display = 'none'; }
    }

    function sfCancel() { document.getElementById('sf-confirm').style.display = 'none'; document.getElementById('sf-sel-bar').style.display = 'none'; }

    // ── CSV FEED ────────────────────────────────────────────
    let csvCurrentFeed = null;   // feed being edited (null = new)
    let csvFeeds = [];           // cached feed list

    async function csvLoadFeeds() {
        const r = await ajax('gh_ajax_csv_list_feeds');
        if (!r.success) { toast('Errore caricamento feed', 'err'); return; }
        csvFeeds = r.data;
        csvRenderList();
    }

    function csvRenderList() {
        const area = document.getElementById('csv-feed-list');
        if (!csvFeeds.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9783;</div><div class="empty-text">Nessun feed CSV configurato.<br>Crea un nuovo feed per importare prodotti da CSV.</div></div>';
            return;
        }
        let h = '<table class="ptable"><thead><tr><th>Nome</th><th>Sorgente</th><th>Mapper</th><th>Frequenza</th><th>Ultimo run</th><th>Stato</th><th></th></tr></thead><tbody>';
        const schedLabels = { manual: 'Manuale', hourly: 'Ogni ora', twicedaily: '2x/giorno', daily: 'Giornaliero' };
        for (const f of csvFeeds) {
            const src = f.source_type === 'url' ? esc(f.source_url || '').substring(0, 40) + '...' : esc(f.source_path || '');
            const lastRun = f.last_run ? new Date(f.last_run).toLocaleString('it-IT') : '\u2013';
            const lastStatus = f.last_result?.status || '\u2013';
            const statusCls = lastStatus === 'completed' ? 'green' : lastStatus === 'error' ? 'red' : '';
            h += '<tr style="cursor:pointer" onclick="GH.csvEditFeed(\'' + f.id + '\')">';
            h += '<td><strong>' + esc(f.name) + '</strong></td>';
            h += '<td style="font-size:10px;color:var(--dim)">' + f.source_type.toUpperCase() + ': ' + src + '</td>';
            h += '<td style="font-size:10px">' + esc(f.mapping_rule_id || '\u2013') + '</td>';
            h += '<td>' + (schedLabels[f.schedule] || f.schedule) + '</td>';
            h += '<td style="font-size:10px">' + lastRun + '</td>';
            h += '<td class="' + statusCls + '">' + lastStatus + '</td>';
            h += '<td><button class="btn btn-ghost" onclick="event.stopPropagation();GH.csvRunFeedFromList(\'' + f.id + '\',this)">&#9654; Run</button></td>';
            h += '</tr>';
        }
        h += '</tbody></table>';
        area.innerHTML = h;
    }

    function csvNewFeed() {
        csvCurrentFeed = null;
        csvShowEditor(null);
    }

    async function csvEditFeed(id) {
        const r = await ajax('gh_ajax_csv_get_feed', { feed_id: id });
        if (!r.success) { toast('Feed non trovato', 'err'); return; }
        csvCurrentFeed = r.data;
        csvShowEditor(r.data);
    }

    let csvDetectedColumns = [];  // columns from last test/upload

    async function csvShowEditor(feed) {
        document.getElementById('csv-list-view').style.display = 'none';
        document.getElementById('csv-edit-view').style.display = '';
        csvDetectedColumns = [];

        // Load mapper rules + presets in parallel
        const [rr, pr] = await Promise.all([
            ajax('gh_ajax_mapper_list_rules'),
            ajax('gh_ajax_csv_list_presets'),
        ]);

        const ruleSel = document.getElementById('csv-mapping-rule');
        ruleSel.innerHTML = '<option value="">-- Seleziona regola --</option>';
        if (rr.success) {
            for (const rule of rr.data) {
                const o = document.createElement('option');
                o.value = rule.id;
                o.textContent = rule.name + ' (' + rule.mapping_count + ' campi)';
                ruleSel.appendChild(o);
            }
        }

        const presetSel = document.getElementById('csv-preset');
        presetSel.innerHTML = '<option value="">-- Seleziona preset --</option>';
        if (pr.success) {
            for (const p of pr.data) {
                const o = document.createElement('option');
                o.value = p.id;
                o.textContent = p.name + ' (' + p.fields + ' campi)';
                o.dataset.desc = p.description;
                presetSel.appendChild(o);
            }
        }

        // Populate form
        if (feed) {
            document.getElementById('csv-edit-title').textContent = 'Modifica: ' + feed.name;
            document.getElementById('csv-name').value = feed.name || '';
            document.getElementById('csv-source-type').value = feed.source_type || 'url';
            document.getElementById('csv-source-url').value = feed.source_url || '';
            document.getElementById('csv-file-name').textContent = feed.source_path || '';
            document.getElementById('csv-mapping-mode').value = feed.mapping_mode || 'auto';
            document.getElementById('csv-preset').value = feed.preset_id || '';
            document.getElementById('csv-mapping-rule').value = feed.mapping_rule_id || '';
            document.getElementById('csv-schedule').value = feed.schedule || 'manual';
            document.getElementById('csv-opt-create').checked = feed.options?.create_new !== false;
            document.getElementById('csv-opt-update').checked = feed.options?.update_existing !== false;
            document.getElementById('btn-csv-preview').style.display = '';
            document.getElementById('btn-csv-run').style.display = '';
            document.getElementById('btn-csv-delete').style.display = '';
        } else {
            document.getElementById('csv-edit-title').textContent = 'Nuovo Feed CSV';
            document.getElementById('csv-name').value = '';
            document.getElementById('csv-source-type').value = 'url';
            document.getElementById('csv-source-url').value = '';
            document.getElementById('csv-file-name').textContent = '';
            document.getElementById('csv-mapping-mode').value = 'auto';
            document.getElementById('csv-preset').value = '';
            document.getElementById('csv-mapping-rule').value = '';
            document.getElementById('csv-schedule').value = 'manual';
            document.getElementById('csv-opt-create').checked = true;
            document.getElementById('csv-opt-update').checked = true;
            document.getElementById('btn-csv-preview').style.display = 'none';
            document.getElementById('btn-csv-run').style.display = 'none';
            document.getElementById('btn-csv-delete').style.display = 'none';
        }
        csvToggleSource();
        csvToggleMapping();
        document.getElementById('csv-source-preview').style.display = 'none';
        document.getElementById('csv-mapping-preview').style.display = 'none';
        document.getElementById('csv-results-area').innerHTML = '';
    }

    function csvBackToList() {
        document.getElementById('csv-edit-view').style.display = 'none';
        document.getElementById('csv-list-view').style.display = '';
        csvLoadFeeds();
    }

    function csvToggleSource() {
        const type = document.getElementById('csv-source-type').value;
        document.getElementById('csv-source-url-row').style.display = type === 'url' ? '' : 'none';
        document.getElementById('csv-source-file-row').style.display = type === 'file' ? '' : 'none';
    }

    function csvToggleMapping() {
        const mode = document.getElementById('csv-mapping-mode').value;
        document.getElementById('csv-preset-row').style.display = mode === 'preset' ? '' : 'none';
        document.getElementById('csv-rule-row').style.display = mode === 'rule' ? '' : 'none';
        // Auto-show mapping preview if we have columns
        if (csvDetectedColumns.length) csvShowMappingPreview();
    }

    async function csvTestUrl() {
        const url = document.getElementById('csv-source-url').value;
        if (!url) { toast('Inserisci URL', 'err'); return; }
        const sp = document.getElementById('csv-test-spin');
        sp.style.display = '';
        try {
            const r = await ajax('gh_ajax_csv_parse_url', { url: url, headers: '{}' });
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            csvDetectedColumns = r.data.columns || [];
            csvShowSourcePreview(r.data);
            csvShowMappingPreview();
            toast(r.data.rows + ' righe, ' + r.data.columns.length + ' colonne', 'ok');
        } catch (e) { toast('Errore connessione', 'err'); }
        finally { sp.style.display = 'none'; }
    }

    function csvShowSourcePreview(data) {
        document.getElementById('csv-source-preview').style.display = '';
        document.getElementById('csv-columns-list').textContent = data.columns.join(', ');
        if (data.sample && data.sample.length) {
            let h = '<table class="ptable" style="font-size:10px"><thead><tr>';
            for (const col of data.columns) h += '<th>' + esc(col) + '</th>';
            h += '</tr></thead><tbody>';
            for (const row of data.sample) {
                h += '<tr>';
                for (const col of data.columns) h += '<td>' + esc(String(row[col] ?? '')) + '</td>';
                h += '</tr>';
            }
            h += '</tbody></table>';
            document.getElementById('csv-sample-table').innerHTML = h;
        }
    }

    async function csvShowMappingPreview() {
        if (!csvDetectedColumns.length) { document.getElementById('csv-mapping-preview').style.display = 'none'; return; }
        const mode = document.getElementById('csv-mapping-mode').value;
        const previewEl = document.getElementById('csv-mapping-preview');
        const listEl = document.getElementById('csv-mapping-preview-list');

        if (mode === 'auto') {
            const r = await ajax('gh_ajax_csv_auto_map', { columns: JSON.stringify(csvDetectedColumns) });
            if (!r.success) { previewEl.style.display = 'none'; return; }
            let h = '<table class="ptable" style="font-size:10px"><thead><tr><th>Colonna CSV</th><th>&rarr;</th><th>Campo WooCommerce</th><th>Trasformazioni</th></tr></thead><tbody>';
            for (const m of r.data.mappings) {
                if (!m.source) continue;
                const trs = m.transforms?.map(t => t.type).join(', ') || '\u2013';
                h += '<tr><td><strong>' + esc(m.source) + '</strong></td><td>&rarr;</td><td>' + esc(m.target_label || m.target) + '</td><td style="color:var(--dim)">' + esc(trs) + '</td></tr>';
            }
            if (r.data.unmatched_columns?.length) {
                h += '<tr><td colspan="4" style="color:var(--amb);padding-top:8px">Non mappate: ' + esc(r.data.unmatched_columns.join(', ')) + '</td></tr>';
            }
            h += '</tbody></table>';
            listEl.innerHTML = h;
            previewEl.style.display = '';
        } else if (mode === 'preset') {
            const presetId = document.getElementById('csv-preset').value;
            if (!presetId) { previewEl.style.display = 'none'; return; }
            const r = await ajax('gh_ajax_csv_resolve_preset', { preset_id: presetId, columns: JSON.stringify(csvDetectedColumns) });
            if (!r.success) { previewEl.style.display = 'none'; return; }
            let h = '<div style="margin-bottom:6px;color:var(--acc)">' + esc(r.data.preset_name) + ' \u2014 ' + r.data.resolved + '/' + r.data.total_in_preset + ' campi risolti</div>';
            h += '<table class="ptable" style="font-size:10px"><thead><tr><th>Colonna CSV</th><th>&rarr;</th><th>Campo WooCommerce</th><th>Trasformazioni</th></tr></thead><tbody>';
            for (const m of r.data.mappings) {
                if (!m.source) continue;
                const trs = m.transforms?.map(t => t.type + (t.value ? '(' + t.value + ')' : '')).join(', ') || '\u2013';
                h += '<tr><td><strong>' + esc(m.source) + '</strong></td><td>&rarr;</td><td>' + esc(m.target_label || m.target) + '</td><td style="color:var(--dim)">' + esc(trs) + '</td></tr>';
            }
            h += '</tbody></table>';
            listEl.innerHTML = h;
            previewEl.style.display = '';
        } else {
            previewEl.style.display = 'none';
        }
    }

    function initCsvUpload() {
        const drop = document.getElementById('csv-drop');
        const inp = document.getElementById('csv-file-input');
        if (!drop || !inp) return;
        inp.addEventListener('change', () => { if (inp.files.length) csvUploadFile(inp.files[0]); });
        drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('dragover'); });
        drop.addEventListener('dragleave', () => drop.classList.remove('dragover'));
        drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('dragover'); if (e.dataTransfer.files.length) csvUploadFile(e.dataTransfer.files[0]); });
    }

    async function csvUploadFile(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['csv', 'tsv', 'txt'].includes(ext)) { toast('Solo .csv, .tsv, .txt', 'err'); return; }
        const fd = new FormData();
        fd.append('action', 'gh_ajax_csv_upload');
        fd.append('nonce', NONCE);
        fd.append('csv_file', file);
        try {
            const resp = await fetch(AJAX, { method: 'POST', body: fd });
            const r = await resp.json();
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            document.getElementById('csv-file-name').textContent = r.data.filename + ' \u00b7 ' + r.data.rows + ' righe';
            document.getElementById('csv-file-input').dataset.path = r.data.path;
            csvDetectedColumns = r.data.columns || [];
            csvShowSourcePreview(r.data);
            csvShowMappingPreview();
            toast(r.data.rows + ' righe caricate', 'ok');
        } catch (e) { toast('Errore upload', 'err'); }
    }

    function csvBuildFeedData() {
        const sourceType = document.getElementById('csv-source-type').value;
        const mappingMode = document.getElementById('csv-mapping-mode').value;
        return {
            id: csvCurrentFeed?.id || '',
            name: document.getElementById('csv-name').value || 'Feed CSV',
            source_type: sourceType,
            source_url: sourceType === 'url' ? document.getElementById('csv-source-url').value : '',
            source_path: sourceType === 'file' ? (document.getElementById('csv-file-input').dataset.path || csvCurrentFeed?.source_path || '') : '',
            source_headers: {},
            mapping_mode: mappingMode,
            preset_id: mappingMode === 'preset' ? document.getElementById('csv-preset').value : '',
            mapping_rule_id: mappingMode === 'rule' ? document.getElementById('csv-mapping-rule').value : '',
            schedule: document.getElementById('csv-schedule').value,
            status: 'active',
            options: {
                create_new: document.getElementById('csv-opt-create').checked,
                update_existing: document.getElementById('csv-opt-update').checked,
            },
        };
    }

    async function csvSaveFeed() {
        const data = csvBuildFeedData();
        if (!data.name) { toast('Nome obbligatorio', 'err'); return; }
        if (data.source_type === 'url' && !data.source_url) { toast('URL obbligatorio', 'err'); return; }
        if (data.source_type === 'file' && !data.source_path) { toast('Carica un file CSV', 'err'); return; }
        if (data.mapping_mode === 'preset' && !data.preset_id) { toast('Seleziona un preset', 'err'); return; }
        if (data.mapping_mode === 'rule' && !data.mapping_rule_id) { toast('Seleziona una regola mapper', 'err'); return; }

        const sp = document.getElementById('csv-save-spin');
        sp.style.display = '';
        try {
            const r = await ajax('gh_ajax_csv_save_feed', { feed: JSON.stringify(data) });
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            csvCurrentFeed = r.data;
            toast('Feed salvato', 'ok');
            // Show action buttons after save
            document.getElementById('btn-csv-preview').style.display = '';
            document.getElementById('btn-csv-run').style.display = '';
            document.getElementById('btn-csv-delete').style.display = '';
            document.getElementById('csv-edit-title').textContent = 'Modifica: ' + r.data.name;
        } catch (e) { toast('Errore', 'err'); }
        finally { sp.style.display = 'none'; }
    }

    async function csvDeleteFeed() {
        if (!csvCurrentFeed?.id) return;
        if (!confirm('Eliminare il feed "' + csvCurrentFeed.name + '"?')) return;
        const r = await ajax('gh_ajax_csv_delete_feed', { feed_id: csvCurrentFeed.id });
        if (!r.success) { toast('Errore', 'err'); return; }
        toast('Feed eliminato', 'ok');
        csvBackToList();
    }

    async function csvPreview() {
        if (!csvCurrentFeed?.id) { toast('Salva il feed prima di fare preview', 'err'); return; }
        const ov = document.getElementById('csv-overlay'), ot = document.getElementById('csv-overlay-text');
        const sp = document.getElementById('csv-preview-spin');
        ot.textContent = 'Lettura CSV e mapping...';
        ov.classList.add('visible');
        sp.style.display = '';
        try {
            const r = await ajax('gh_ajax_csv_preview', { feed_id: csvCurrentFeed.id });
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            csvRenderDiff(r.data.diff, r.data.rows_read);
        } catch (e) { toast('Errore', 'err'); }
        finally { ov.classList.remove('visible'); sp.style.display = 'none'; }
    }

    function csvRenderDiff(diff, rowsRead) {
        const s = diff.summary;
        const area = document.getElementById('csv-results-area');
        let h = '<div class="stats-bar" style="display:flex;margin:12px 0">';
        h += '<div class="stat">Righe CSV: <span class="blue">' + rowsRead + '</span></div>';
        h += '<div class="stat">Nuovi: <span class="blue">' + s.new + '</span></div>';
        h += '<div class="stat">Da aggiornare: <span class="amber">' + s.update + '</span></div>';
        h += '<div class="stat">Invariati: <span class="green">' + s.unchanged + '</span></div>';
        h += '</div>';

        const all = [
            ...diff.new.map(p => ({ ...p, _a: 'new' })),
            ...diff.update.map(p => ({ ...p, _a: 'update' })),
            ...diff.unchanged.map(p => ({ ...p, _a: 'unchanged' })),
        ];

        if (all.length) {
            h += '<table class="ptable"><thead><tr><th>Azione</th><th>SKU</th><th>Nome</th><th>Prezzo</th><th>Stock</th><th>Modifiche</th></tr></thead><tbody>';
            for (const p of all.slice(0, 100)) {
                const cls = p._a === 'new' ? 'st-new' : p._a === 'update' ? 'st-update' : 'st-unchanged';
                const lb = p._a === 'new' ? '+ Nuovo' : p._a === 'update' ? '\u21bb Agg.' : '\u2713';
                const changes = p._changes ? p._changes.join(', ') : '';
                h += '<tr>';
                h += '<td class="' + cls + '">' + lb + '</td>';
                h += '<td>' + esc(p.sku || '\u2013') + '</td>';
                h += '<td>' + esc(p.name || '\u2013') + '</td>';
                h += '<td>' + esc(String(p.regular_price || p.sale_price || '\u2013')) + '</td>';
                h += '<td>' + (p.stock_quantity != null ? p.stock_quantity : '\u2013') + '</td>';
                h += '<td style="font-size:10px;color:var(--dim)">' + esc(changes) + '</td>';
                h += '</tr>';
            }
            if (all.length > 100) h += '<tr><td colspan="6" style="text-align:center;color:var(--dim)">... e altri ' + (all.length - 100) + '</td></tr>';
            h += '</tbody></table>';
        }

        area.innerHTML = h;
    }

    async function csvRunFeed() {
        if (!csvCurrentFeed?.id) { toast('Salva il feed prima', 'err'); return; }
        if (!confirm('Eseguire l\'importazione?')) return;
        const ov = document.getElementById('csv-overlay'), ot = document.getElementById('csv-overlay-text');
        const sp = document.getElementById('csv-run-spin');
        ot.textContent = 'Importazione in corso...';
        ov.classList.add('visible');
        sp.style.display = '';
        try {
            const r = await ajax('gh_ajax_csv_run', { feed_id: csvCurrentFeed.id, options: '{}' });
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            const s = r.data.summary;
            const area = document.getElementById('csv-results-area');
            let h = '<div class="stats-bar" style="display:flex;margin:12px 0">';
            h += '<div class="stat">Righe CSV: <span class="blue">' + r.data.rows_read + '</span></div>';
            h += '<div class="stat">Creati: <span class="green">' + s.created + '</span></div>';
            h += '<div class="stat">Aggiornati: <span class="amber">' + s.updated + '</span></div>';
            h += '<div class="stat">Errori: <span class="red">' + s.errors + '</span></div>';
            h += '</div>';

            if (r.data.details?.length) {
                h += '<table class="ptable"><thead><tr><th>Risultato</th><th>ID</th><th>SKU</th><th>Nome</th></tr></thead><tbody>';
                for (const d of r.data.details) {
                    const c = d.action === 'created' ? 'st-created' : d.action === 'updated' ? 'st-updated' : 'st-error';
                    const l = d.action === 'created' ? '+ Creato' : d.action === 'updated' ? '\u2713 Agg.' : '\u2717 Err';
                    h += '<tr><td class="' + c + '">' + l + '</td><td>' + (d.id || '\u2013') + '</td><td>' + esc(d.sku || '') + '</td><td>' + esc(d.name || '') + '</td></tr>';
                }
                h += '</tbody></table>';
            }

            area.innerHTML = h;
            toast(s.created + ' creati, ' + s.updated + ' aggiornati', s.errors ? 'err' : 'ok', 5000);
        } catch (e) { toast('Errore', 'err'); }
        finally { ov.classList.remove('visible'); sp.style.display = 'none'; }
    }

    async function csvRunFeedFromList(feedId, btn) {
        if (!confirm('Eseguire importazione per questo feed?')) return;
        btn.disabled = true;
        btn.textContent = '...';
        try {
            const r = await ajax('gh_ajax_csv_run', { feed_id: feedId, options: '{}' });
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            const s = r.data.summary;
            toast(s.created + ' creati, ' + s.updated + ' aggiornati, ' + s.errors + ' errori', s.errors ? 'err' : 'ok', 5000);
            csvLoadFeeds();
        } catch (e) { toast('Errore', 'err'); }
        finally { btn.disabled = false; btn.textContent = '\u25b6 Run'; }
    }

    function csvOnPresetChange() {
        const sel = document.getElementById('csv-preset');
        const opt = sel.options[sel.selectedIndex];
        document.getElementById('csv-preset-desc').textContent = opt?.dataset?.desc || '';
        if (csvDetectedColumns.length) csvShowMappingPreview();
    }

    // ── SCHEDULER ───────────────────────────────────────────
    let schedEditingId = null;

    async function schedLoad() {
        const r = await ajax('gh_ajax_sched_list');
        if (!r.success) { toast('Errore', 'err'); return; }
        schedRenderList(r.data);
    }

    function schedRenderList(tasks) {
        const area = document.getElementById('sched-task-list');
        if (!tasks.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9202;</div><div class="empty-text">Nessun task schedulato</div></div>';
            return;
        }
        const schedLabels = { manual: 'Manuale', hourly: 'Ogni ora', twicedaily: '2x/giorno', daily: 'Giornaliero' };
        let h = '<table class="ptable"><thead><tr><th>Stato</th><th>Nome</th><th>Tipo</th><th>Frequenza</th><th>Ultimo run</th><th>Risultato</th><th>Prossimo</th><th>Run</th><th></th></tr></thead><tbody>';
        for (const t of tasks) {
            const active = t.status === 'active';
            const statusCls = active ? 'green' : 'amber';
            const statusLbl = active ? '\u25cf Attivo' : '\u25cb Pausa';
            const feedLbl = t.feed_type === 'config' ? (t.config_id || '?') : 'CSV #' + (t.csv_feed_id || '?');
            const lastRun = t.last_run ? new Date(t.last_run).toLocaleString('it-IT') : '\u2013';
            const lastOk = t.last_result?.status === 'completed';
            const lastRes = t.last_result ? (lastOk ? '<span class="green">' + (t.last_result.created||0) + 'C ' + (t.last_result.updated||0) + 'U</span>' : '<span class="red">' + (t.last_result.error || 'errore') + '</span>') : '\u2013';
            const nextRun = t.next_run ? new Date(t.next_run).toLocaleString('it-IT') : '\u2013';
            const runN = t.run_count || 0;

            h += '<tr>';
            h += '<td class="' + statusCls + '" style="cursor:pointer" onclick="GH.schedToggle(\'' + t.id + '\')">' + statusLbl + '</td>';
            h += '<td><strong>' + esc(t.name) + '</strong></td>';
            h += '<td style="font-size:10px">' + esc(feedLbl) + '</td>';
            h += '<td>' + (schedLabels[t.schedule] || t.schedule) + '</td>';
            h += '<td style="font-size:10px">' + lastRun + '</td>';
            h += '<td style="font-size:10px">' + lastRes + '</td>';
            h += '<td style="font-size:10px">' + nextRun + '</td>';
            h += '<td><button class="btn btn-ghost" onclick="GH.schedRunNow(\'' + t.id + '\',this)">&#9654;</button></td>';
            h += '<td><button class="btn btn-ghost" onclick="GH.schedEditTask(\'' + t.id + '\')">&#9998;</button> <button class="btn btn-ghost" style="color:var(--red)" onclick="GH.schedDeleteTask(\'' + t.id + '\')">&#10005;</button></td>';
            h += '</tr>';
        }
        h += '</tbody></table>';
        area.innerHTML = h;
    }

    async function schedNewTask() {
        schedEditingId = null;
        document.getElementById('sched-editor-title').textContent = 'Nuovo task';
        document.getElementById('sched-name').value = '';
        document.getElementById('sched-feed-type').value = 'config';
        document.getElementById('sched-source-url').value = '';
        document.getElementById('sched-schedule').value = 'daily';
        document.getElementById('sched-opt-create').checked = true;
        document.getElementById('sched-opt-update').checked = true;
        document.getElementById('sched-opt-images').checked = false;
        await schedLoadDropdowns();
        schedToggleFeedType();
        document.getElementById('sched-editor').style.display = '';
    }

    async function schedEditTask(id) {
        const r = await ajax('gh_ajax_sched_list');
        if (!r.success) return;
        const t = r.data.find(x => x.id === id);
        if (!t) { toast('Non trovato', 'err'); return; }
        schedEditingId = id;
        document.getElementById('sched-editor-title').textContent = 'Modifica: ' + t.name;
        document.getElementById('sched-name').value = t.name || '';
        document.getElementById('sched-feed-type').value = t.feed_type || 'config';
        document.getElementById('sched-source-url').value = t.source_url || '';
        document.getElementById('sched-schedule').value = t.schedule || 'daily';
        document.getElementById('sched-opt-create').checked = t.options?.create_new !== false;
        document.getElementById('sched-opt-update').checked = t.options?.update_existing !== false;
        document.getElementById('sched-opt-images').checked = !!t.options?.sideload_images;
        await schedLoadDropdowns();
        document.getElementById('sched-config-id').value = t.config_id || '';
        document.getElementById('sched-csv-feed-id').value = t.csv_feed_id || '';
        schedToggleFeedType();
        document.getElementById('sched-editor').style.display = '';
    }

    async function schedLoadDropdowns() {
        const [cr, fr] = await Promise.all([ajax('gh_ajax_fc_list_configs'), ajax('gh_ajax_csv_list_feeds')]);
        const cs = document.getElementById('sched-config-id');
        cs.innerHTML = '<option value="">-- Seleziona config --</option>';
        if (cr.success) cr.data.forEach(c => { const o = document.createElement('option'); o.value = c.id; o.textContent = c.name; cs.appendChild(o); });
        const fs = document.getElementById('sched-csv-feed-id');
        fs.innerHTML = '<option value="">-- Seleziona CSV feed --</option>';
        if (fr.success) fr.data.forEach(f => { const o = document.createElement('option'); o.value = f.id; o.textContent = f.name; fs.appendChild(o); });
    }

    function schedToggleFeedType() {
        const ft = document.getElementById('sched-feed-type').value;
        document.getElementById('sched-config-row').style.display = ft === 'config' ? '' : 'none';
        document.getElementById('sched-csv-row').style.display = ft === 'csv_feed' ? '' : 'none';
        document.getElementById('sched-source-row').style.display = ft === 'config' ? '' : 'none';
    }

    function schedCancelEdit() { document.getElementById('sched-editor').style.display = 'none'; }

    async function schedSaveTask() {
        const ft = document.getElementById('sched-feed-type').value;
        const data = {
            id: schedEditingId || '',
            name: document.getElementById('sched-name').value,
            feed_type: ft,
            config_id: ft === 'config' ? document.getElementById('sched-config-id').value : '',
            csv_feed_id: ft === 'csv_feed' ? document.getElementById('sched-csv-feed-id').value : '',
            source_type: 'url',
            source_url: document.getElementById('sched-source-url').value,
            schedule: document.getElementById('sched-schedule').value,
            status: 'active',
            options: {
                create_new: document.getElementById('sched-opt-create').checked,
                update_existing: document.getElementById('sched-opt-update').checked,
                sideload_images: document.getElementById('sched-opt-images').checked,
            },
        };
        if (!data.name) { toast('Nome obbligatorio', 'err'); return; }
        const sp = document.getElementById('sched-save-spin');
        sp.style.display = '';
        try {
            const r = await ajax('gh_ajax_sched_save', { task: JSON.stringify(data) });
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            toast('Task salvato', 'ok');
            document.getElementById('sched-editor').style.display = 'none';
            schedLoad();
        } catch (e) { toast('Errore', 'err'); }
        finally { sp.style.display = 'none'; }
    }

    async function schedDeleteTask(id) {
        if (!confirm('Eliminare questo task?')) return;
        const r = await ajax('gh_ajax_sched_delete', { task_id: id });
        if (!r.success) { toast('Errore', 'err'); return; }
        toast('Eliminato', 'ok');
        schedLoad();
    }

    async function schedToggle(id) {
        const r = await ajax('gh_ajax_sched_toggle', { task_id: id });
        if (!r.success) { toast('Errore', 'err'); return; }
        toast(r.data.status === 'active' ? 'Attivato' : 'In pausa', 'ok');
        schedLoad();
    }

    async function schedRunNow(id, btn) {
        btn.disabled = true; btn.textContent = '...';
        try {
            const r = await ajax('gh_ajax_sched_run', { task_id: id });
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            const s = r.data.summary || {};
            toast((s.created||0) + ' creati, ' + (s.updated||0) + ' aggiornati, ' + (s.errors||0) + ' errori', s.errors ? 'err' : 'ok', 5000);
            schedLoad();
        } catch (e) { toast('Errore', 'err'); }
        finally { btn.disabled = false; btn.textContent = '\u25b6'; }
    }

    async function schedLoadLog() {
        const r = await ajax('gh_ajax_sched_log', { limit: '50' });
        if (!r.success) { toast('Errore', 'err'); return; }
        const area = document.getElementById('sched-log-area');
        if (!r.data.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9776;</div><div class="empty-text">Nessun run registrato</div></div>';
            return;
        }
        let h = '<table class="ptable"><thead><tr><th>Quando</th><th>Task</th><th>Stato</th><th>Creati</th><th>Aggiornati</th><th>Errori</th><th>Durata</th></tr></thead><tbody>';
        for (const e of r.data) {
            const cls = e.status === 'completed' ? '' : 'st-error';
            h += '<tr class="' + cls + '">';
            h += '<td style="font-size:10px">' + new Date(e.ran_at).toLocaleString('it-IT') + '</td>';
            h += '<td>' + esc(e.task_name) + '</td>';
            h += '<td class="' + (e.status === 'completed' ? 'green' : 'red') + '">' + esc(e.status) + '</td>';
            h += '<td class="green">' + (e.created || 0) + '</td>';
            h += '<td class="amber">' + (e.updated || 0) + '</td>';
            h += '<td class="red">' + (e.errors || 0) + (e.error_msg ? ' \u2014 ' + esc(e.error_msg) : '') + '</td>';
            h += '<td style="font-size:10px">' + (e.duration ? (e.duration / 1000).toFixed(1) + 's' : '\u2013') + '</td>';
            h += '</tr>';
        }
        h += '</tbody></table>';
        area.innerHTML = h;
    }

    async function schedClearLog() {
        if (!confirm('Svuotare il log?')) return;
        await ajax('gh_ajax_sched_clear_log');
        toast('Log svuotato', 'ok');
        schedLoadLog();
    }

    // ── FEED-SCOPED CLEANUP ─────────────────────────
    async function feedCleanup(source) {
        const cr = await ajax('gh_ajax_feed_count', { source });
        if (!cr.success) { toast('Errore conteggio', 'err'); return; }
        const count = cr.data.count;
        if (!count) { toast('Nessun prodotto ' + source + ' da eliminare', 'inf'); return; }

        const answer = prompt(
            '\u26a0 ATTENZIONE\n\n' +
            'Stai per eliminare TUTTI i ' + count + ' prodotti importati da "' + source + '" (+ varianti).\n' +
            'L\'operazione e irreversibile.\n\n' +
            'Digita "' + source + '" per confermare:'
        );
        if (answer !== source) { toast('Annullato', 'inf'); return; }

        toast('Eliminazione in corso...', 'inf', 2000);
        await acquireWakeLock();
        try {
            const r = await ajax('gh_ajax_feed_cleanup', { source, confirm: source });
            if (!r.success) { toast('Errore: ' + (r.data || ''), 'err'); return; }
            const d = r.data;
            toast(d.deleted + ' prodotti + ' + d.variations + ' varianti eliminati', 'ok', 5000);
        } catch (e) { toast('Errore: ' + (e.message || e), 'err'); }
        finally { releaseWakeLock(); }
    }

    // ── NUCLEAR CLEANUP ─────────────────────────────
    function nucGetTargets() {
        return {
            products:    document.getElementById('nuc-products')?.checked || false,
            media:       document.getElementById('nuc-media')?.checked || false,
            transients:  document.getElementById('nuc-transients')?.checked || false,
            taxonomy:    document.getElementById('nuc-taxonomy')?.checked || false,
            orphan_meta: document.getElementById('nuc-orphans')?.checked || false,
        };
    }

    async function nucPreview() {
        const targets = nucGetTargets();
        if (!Object.values(targets).some(Boolean)) { toast('Seleziona almeno una categoria', 'err'); return; }
        const btn = document.getElementById('btn-nuc-preview'), sp = document.getElementById('nuc-preview-spin');
        btn.disabled = true; sp.style.display = '';
        try {
            const r = await ajax('gh_ajax_nuclear_preview', { targets: JSON.stringify(targets) });
            if (!r.success) { toast('Errore: ' + (r.data || ''), 'err'); return; }
            const d = r.data;
            const area = document.getElementById('nuc-preview-area');
            let h = '<table class="ptable" style="margin:8px 0"><thead><tr><th>Categoria</th><th>Elementi</th><th>Dettaglio</th></tr></thead><tbody>';
            let totalItems = 0;
            for (const [key, info] of Object.entries(d)) {
                const count = typeof info.count === 'number' ? info.count : (info.deleted ?? 0);
                totalItems += count;
                h += '<tr><td style="font-weight:500">' + esc(key) + '</td>';
                h += '<td class="' + (count > 0 ? 'red' : 'green') + '" style="font-family:var(--mono)">' + count + '</td>';
                h += '<td style="font-size:10px;color:var(--dim)">' + esc(info.label || '') + '</td></tr>';
            }
            h += '</tbody></table>';
            if (totalItems > 0) {
                h += '<div style="font-family:var(--mono);font-size:11px;color:var(--red);margin:8px 0">Totale: ' + totalItems + ' elementi da eliminare</div>';
            } else {
                h += '<div style="font-family:var(--mono);font-size:11px;color:var(--grn);margin:8px 0">Niente da eliminare</div>';
            }
            area.innerHTML = h;
            if (totalItems > 0) {
                document.getElementById('nuc-confirm').style.display = 'flex';
                document.getElementById('nuc-confirm-input').value = '';
            }
        } catch (e) { toast('Errore', 'err'); }
        finally { btn.disabled = false; sp.style.display = 'none'; }
    }

    async function nucExecute() {
        const confirmVal = (document.getElementById('nuc-confirm-input').value || '').trim().toUpperCase();
        if (confirmVal !== 'NUCLEAR') { toast('Digita NUCLEAR per confermare', 'err'); return; }
        const targets = nucGetTargets();
        const ov = document.getElementById('nuc-overlay'), ot = document.getElementById('nuc-overlay-text');
        const btn = document.getElementById('btn-nuc-execute'), sp = document.getElementById('nuc-exec-spin');
        const area = document.getElementById('nuc-preview-area');
        ov.classList.add('visible'); btn.disabled = true; sp.style.display = '';
        await acquireWakeLock();

        const steps = [];
        if (targets.products)    steps.push({ key: 'products',    label: 'Prodotti' });
        if (targets.media)       steps.push({ key: 'media',       label: 'Media' });
        if (targets.transients)  steps.push({ key: 'transients',  label: 'Transients' });
        if (targets.taxonomy)    steps.push({ key: 'taxonomy',    label: 'Tassonomie' });
        if (targets.orphan_meta) steps.push({ key: 'orphan_meta', label: 'Dati orfani' });

        const results = {};
        let errors = 0;

        for (let i = 0; i < steps.length; i++) {
            const step = steps[i];
            ot.textContent = '(' + (i + 1) + '/' + steps.length + ') ' + step.label + '...';

            try {
                if (step.key === 'media') {
                    let totalDeleted = 0;
                    let round = 0;
                    while (true) {
                        round++;
                        ot.textContent = '(' + (i + 1) + '/' + steps.length + ') Media — batch ' + round + ' (' + totalDeleted + ' eliminati)...';
                        const r = await ajax('gh_ajax_nuclear_media_chunk', { confirm: 'NUCLEAR' });
                        if (!r.success) { toast('Errore media batch ' + round + ': ' + (r.data || ''), 'err'); errors++; break; }
                        totalDeleted += r.data.deleted || 0;
                        if (r.data.done) break;
                    }
                    results.media = totalDeleted;
                } else {
                    const r = await ajax('gh_ajax_nuclear_step', { step: step.key, confirm: 'NUCLEAR' });
                    if (!r.success) {
                        toast('Errore ' + step.label + ': ' + (r.data || ''), 'err');
                        results[step.key] = 'ERRORE';
                        errors++;
                    } else {
                        results[step.key] = r.data.result;
                    }
                }
            } catch (e) {
                toast('Errore ' + step.label + ': ' + (e.message || 'timeout'), 'err');
                results[step.key] = 'ERRORE';
                errors++;
            }
        }

        let h = '<div style="font-family:var(--mono);font-size:12px;color:' + (errors ? 'var(--amb)' : 'var(--grn)') + ';margin:12px 0">&#10003; Cleanup ' + (errors ? 'completato con ' + errors + ' errori' : 'completato') + '</div>';
        h += '<table class="ptable"><thead><tr><th>Categoria</th><th>Eliminati</th></tr></thead><tbody>';
        for (const [key, val] of Object.entries(results)) {
            const isErr = val === 'ERRORE';
            const n = typeof val === 'number' ? val : (typeof val === 'object' ? (val.deleted ?? val.postmeta ?? JSON.stringify(val)) : val);
            h += '<tr><td>' + esc(key) + '</td><td class="' + (isErr ? 'amber' : 'red') + '" style="font-family:var(--mono)">' + n + '</td></tr>';
        }
        h += '</tbody></table>';
        area.innerHTML = h;
        document.getElementById('nuc-confirm').style.display = 'none';
        releaseWakeLock(); ov.classList.remove('visible'); btn.disabled = false; sp.style.display = 'none';
        toast('Cleanup ' + (errors ? 'parziale' : 'completato'), errors ? 'err' : 'ok', 5000);
    }

    // ── INIT
    // Popola il dropdown "Brand" della Roundtrip export con i brand estratti
    // da product_cat (legacy: brand = categoria di profondita 1). TODO: migrare
    // Roundtrip a product_brand quando verra rifatto il modulo Import.
    (async function(){const r=await ajax('rp_cm_ajax_get_tree_paths');if(!r.success)return;const sel=document.getElementById('rt-filter-brand');if(!sel)return;(r.data.brands||[]).forEach(b=>{const o=document.createElement('option');o.value=b;o.textContent=b;sel.appendChild(o)})})();
    initBulkImport();
    initRtImport();
    initSfFeed();
    initCsvUpload();

    return{ajax,toast,esc,switchTab,loadTaxonomy,taxSelect,taxToggle,taxCreateRoot,taxAdd,taxRename,taxDelete,loadWhitelist,whitelistAdd,wlCopyAll,wlToggleBulk,wlBulkExport,wlBulkImport,removeWL,addWL,gsFetch,gsApply,gsQuickPatch,gsCancel,gsToggle,gsToggleAll,gsSelectAll,gsSelectNone,gsSelectByType,sfFetch,sfPreimportMedia,sfPreimportStop,sfValidateMap,sfApply,sfQuickPatch,sfCancel,sfToggle,sfToggleAll,sfSelectAll,sfSelectNone,sfSelectByType,sfToggleSource,sfFilterList,sfSaveSettings,bulkPreview,bulkApply,bulkCancel,generateRoundtrip,importPreview,importApply,importCancel,copyJSON,downloadJSON,hcExecute,csvLoadFeeds,csvNewFeed,csvEditFeed,csvBackToList,csvToggleSource,csvToggleMapping,csvTestUrl,csvSaveFeed,csvDeleteFeed,csvPreview,csvRunFeed,csvRunFeedFromList,csvOnPresetChange,schedLoad,schedNewTask,schedEditTask,schedSaveTask,schedDeleteTask,schedToggle,schedRunNow,schedToggleFeedType,schedCancelEdit,schedLoadLog,schedClearLog,nucPreview,nucExecute,feedCleanup};
})();
