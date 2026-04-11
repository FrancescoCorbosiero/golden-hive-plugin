    // ── MEDIA MAPPING
    async function loadMapping(){const btn=document.getElementById('btn-map'),sp=document.getElementById('map-spin');btn.disabled=true;sp.style.display='';try{const r=await ajax('rp_mm_ajax_mapping');if(!r.success){toast('Errore','err');return}const d=r.data,a=document.getElementById('map-area');if(!d.length){a.innerHTML='<div class="empty-state"><div class="empty-text">Nessun prodotto</div></div>';return}let h='<table class="maptable"><thead><tr><th>Featured</th><th>Prodotto</th><th>Gallery</th><th>Tot</th></tr></thead><tbody>';for(const p of d){const ft=p.featured_image?'<img class="map-thumb" src="'+esc(p.featured_image.thumbnail_url)+'" />':'<span class="map-none">nessuna</span>';const gl=p.gallery_images.length?'<div class="map-gallery">'+p.gallery_images.slice(0,5).map(g=>'<img src="'+esc(g.thumbnail_url)+'" />').join('')+(p.gallery_images.length>5?'<span class="map-none">+'+(p.gallery_images.length-5)+'</span>':'')+'</div>':'<span class="map-none">\u2013</span>';h+='<tr><td>'+ft+'</td><td><div class="map-name">'+esc(p.name)+'</div><div class="map-sku">'+esc(p.sku||'')+' \u00b7 #'+p.product_id+'</div></td><td>'+gl+'</td><td>'+p.total_images+'</td></tr>'}h+='</tbody></table>';a.innerHTML=h;toast(d.length+' prodotti','ok')}catch(e){toast('Errore','err')}finally{btn.disabled=false;sp.style.display='none'}}

    // ── MEDIA BROWSE
    let browseTimer;
    function debounceBrowse(){clearTimeout(browseTimer);browseTimer=setTimeout(browseMedia,300)}
    async function browseMedia(){const q=document.getElementById('browse-search').value.trim();const r=await ajax('rp_mm_ajax_browse',{query:q,limit:60});if(!r.success)return;const g=document.getElementById('browse-grid');if(!r.data.length){g.innerHTML='<div class="empty-state" style="grid-column:1/-1"><div class="empty-text">Nessun risultato</div></div>';return}g.innerHTML=r.data.map(a=>'<div class="media-card" onclick="GH.showUsage('+a.id+')"><img class="media-thumb" src="'+esc(a.thumbnail_url)+'" loading="lazy" /><div class="media-info"><div class="media-filename">'+esc(a.filename)+'</div><div class="media-size">'+a.filesize_human+' \u00b7 #'+a.id+'</div></div></div>').join('')}
    async function showUsage(id){const r=await ajax('rp_mm_ajax_usage',{attachment_id:id});if(!r.success)return;if(!r.data.length){toast('#'+id+': non usata','inf');return}toast('#'+id+': '+r.data.map(u=>u.name+' ('+u.usage+')').join(', '),'inf',5000)}

    // ── ORPHANS
    async function scanOrphans(){const ov=document.getElementById('scan-overlay'),btn=document.getElementById('btn-scan'),sp=document.getElementById('scan-spin');ov.classList.add('visible');btn.disabled=true;sp.style.display='';try{const r=await ajax('rp_mm_ajax_scan');if(!r.success){toast('Errore','err');return}state.orphans=r.data.orphans;state.selected=new Set();document.getElementById('orphan-stats').style.display='flex';document.getElementById('st-orphans').textContent=r.data.orphan_count;document.getElementById('st-used').textContent=r.data.used_count;document.getElementById('st-size').textContent=r.data.estimated_size.total_human;renderOrphanGrid();toast(r.data.orphan_count+' orfani',r.data.orphan_count?'err':'ok')}catch(e){toast('Errore','err')}finally{ov.classList.remove('visible');btn.disabled=false;sp.style.display='none'}}
    function renderOrphanGrid(){const g=document.getElementById('orphan-grid'),o=state.orphans;if(!o.length){g.innerHTML='<div class="empty-state" style="grid-column:1/-1"><div class="empty-icon">\u2713</div><div class="empty-text">Nessun orfano</div></div>';return}g.innerHTML=o.map(a=>{const wl=a.is_whitelisted;return'<div class="media-card'+(wl?' whitelisted':'')+(state.selected.has(a.id)?' selected':'')+'">'+(wl?'<span class="media-badge badge-wl">WL</span>':'<input type="checkbox" class="media-check" '+(state.selected.has(a.id)?'checked':'')+' onclick="event.stopPropagation();GH.toggleOrphan('+a.id+')" />')+'<img class="media-thumb" src="'+esc(a.thumbnail_url)+'" loading="lazy" onclick="GH.orphanAction('+a.id+','+wl+')" /><div class="media-info"><div class="media-filename">'+esc(a.filename)+'</div><div class="media-size">'+a.filesize_human+'</div></div></div>'}).join('');updSel()}
    function toggleOrphan(id){state.selected.has(id)?state.selected.delete(id):state.selected.add(id);renderOrphanGrid()}
    function updSel(){const n=state.selected.size;document.getElementById('btn-bulk-del').style.display=n?'':'none';document.getElementById('sel-stat').style.display=n?'':'none';document.getElementById('sel-n').textContent=n}
    function orphanAction(id,wl){if(wl){if(confirm('Rimuovere #'+id+' dalla whitelist?'))removeWL(id)}else{const r=prompt('Aggiungere #'+id+' alla whitelist? Motivo:');if(r!==null)addWL(id,r)}}
    async function bulkDeleteOrphans(){const ids=Array.from(state.selected);if(!ids.length||!confirm('Eliminare '+ids.length+' immagini?'))return;const r=await ajax('rp_mm_ajax_bulk_delete',{ids:JSON.stringify(ids)});if(!r.success){toast('Errore','err');return}toast('Eliminati: '+r.data.deleted.length+', Spazio: '+r.data.freed_human,'ok',5000);state.orphans=state.orphans.filter(a=>!r.data.deleted.includes(a.id));state.selected=new Set();renderOrphanGrid()}

    // ── WHITELIST
    async function loadWhitelist(){const r=await ajax('rp_mm_ajax_get_whitelist');if(!r.success)return;const a=document.getElementById('wl-area');if(!r.data.length){a.innerHTML='<div class="empty-state"><div class="empty-text">Whitelist vuota</div></div>';return}a.innerHTML=r.data.map(e=>'<div class="wl-row"><img class="wl-thumb" src="'+esc(e.url||'')+'" /><div class="wl-info"><div class="wl-name">'+esc(e.reason||'Nessun motivo')+'</div><div class="wl-reason">'+esc(e.url||'')+'</div></div><span class="wl-id">#'+(e.id||'?')+'</span><button class="btn btn-ghost" onclick="GH.removeWL('+e.id+')">Rimuovi</button></div>').join('')}
    async function addWL(id,reason){await ajax('rp_mm_ajax_add_whitelist',{attachment_id:id,reason:reason});toast('#'+id+' protetto','ok');scanOrphans()}
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
    async function gsApply(){if(!gsProducts||!gsSelected.size)return;const sel=gsProducts.filter(p=>gsSelected.has(p.sku));const ov=document.getElementById('gs-overlay'),ot=document.getElementById('gs-overlay-text'),btn=document.getElementById('btn-gs-apply'),sp=document.getElementById('gs-apply-spin');ot.textContent='Importazione '+sel.length+' prodott'+(sel.length===1?'o':'i')+'...';ov.classList.add('visible');btn.disabled=true;sp.style.display='';try{const r=await ajax('rp_rc_ajax_gs_apply',{products:JSON.stringify(sel),options:JSON.stringify({create_new:true,update_existing:true,sideload_images:document.getElementById('gs-opt-images').checked})});if(!r.success){toast('Errore','err');return}const s=r.data.summary;let h='<table class="ptable"><thead><tr><th>Risultato</th><th>ID</th><th>SKU</th><th>Nome</th></tr></thead><tbody>';for(const d of r.data.details){const c=d.action==='created'?'st-created':d.action==='updated'?'st-updated':'st-error';const l=d.action==='created'?'+ Creato':d.action==='updated'?'\u2713 Agg.':'\u2717 Err';h+='<tr><td class="'+c+'">'+l+'</td><td>'+(d.id||'\u2013')+'</td><td>'+esc(d.sku||'')+'</td><td>'+esc(d.name||'')+'</td></tr>'}h+='</tbody></table>';document.getElementById('gs-preview').innerHTML=h;document.getElementById('gs-confirm').style.display='none';document.getElementById('gs-sel-bar').style.display='none';toast(s.created+' creati, '+s.updated+' aggiornati','ok',5000)}catch(e){toast('Errore','err')}finally{ov.classList.remove('visible');btn.disabled=false;sp.style.display='none'}}
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

    // ── COPY / DOWNLOAD JSON
    function copyJSON(mode){const d=mode==='catalog'?state.catalogData:state.roundtripData;if(!d){toast('Nessun dato','err');return}navigator.clipboard.writeText(JSON.stringify(d,null,2)).then(()=>toast('Copiato','ok'),()=>toast('Errore','err'))}
    function downloadJSON(mode){const d=mode==='catalog'?state.catalogData:state.roundtripData;if(!d)return;const j=JSON.stringify(d,null,2),b=new Blob([j],{type:'application/json'}),u=URL.createObjectURL(b),a=document.createElement('a'),dt=new Date().toISOString().slice(0,10);a.href=u;a.download=(mode==='catalog'?'rp-catalog-':'rp-roundtrip-')+dt+'.json';a.click();URL.revokeObjectURL(u);toast('Download avviato','inf')}

    // ── HTTP CLIENT
    async function hcExecute(){const btn=document.querySelector('#panel-httpclient .btn-primary'),sp=document.getElementById('hc-spin');btn.disabled=true;sp.style.display='';try{const hdrs=document.getElementById('hc-headers').value;const cfg={url:document.getElementById('hc-url').value,method:document.getElementById('hc-method').value,headers:hdrs?JSON.parse(hdrs):{},body:document.getElementById('hc-body').value};const r=await ajax('rp_rc_ajax_execute',{config:JSON.stringify(cfg)});const out=document.getElementById('hc-response');if(!r.success){out.textContent='Errore: '+r.data;return}let h='<div style="margin-bottom:12px;color:var(--dim)">HTTP '+r.data.status+' \u00b7 '+r.data.duration_ms+'ms</div>';h+=r.data.parsed?hl(JSON.stringify(r.data.parsed,null,2)):esc(r.data.body_raw||'');out.innerHTML=h}catch(e){toast('Errore','err')}finally{btn.disabled=false;sp.style.display='none'}}

    // ── STOCKFIRMATI FEED ──────────────────────────────────
    let sfProducts = null, sfSelected = new Set(), sfDiffData = null;

    function sfToggleSource() {
        const type = document.getElementById('sf-source-type').value;
        document.getElementById('sf-source-url-row').style.display = type === 'url' ? '' : 'none';
        document.getElementById('sf-source-file-row').style.display = type === 'file' ? '' : 'none';
    }

    function initSfUpload() {
        const drop = document.getElementById('sf-drop');
        const inp = document.getElementById('sf-file-input');
        if (!drop || !inp) return;
        inp.addEventListener('change', () => { if (inp.files.length) sfUploadFile(inp.files[0]); });
        drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('dragover'); });
        drop.addEventListener('dragleave', () => drop.classList.remove('dragover'));
        drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('dragover'); if (e.dataTransfer.files.length) sfUploadFile(e.dataTransfer.files[0]); });
    }

    async function sfFetch() {
        const ov = document.getElementById('sf-overlay'), ot = document.getElementById('sf-overlay-text');
        const btn = document.getElementById('btn-sf-fetch'), sp = document.getElementById('sf-fetch-spin');
        ot.textContent = 'Fetch CSV StockFirmati...';
        ov.classList.add('visible'); btn.disabled = true; sp.style.display = '';
        try {
            const url = document.getElementById('sf-url').value;
            const r = await ajax('gh_ajax_sf_fetch', { url: url });
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            sfProducts = r.data.products;
            document.getElementById('sf-csv-rows').textContent = r.data.csv_rows;
            toast(r.data.product_count + ' prodotti normalizzati', 'ok');
            ot.textContent = 'Confronto WooCommerce...';
            const dr = await ajax('gh_ajax_sf_preview', { products: JSON.stringify(sfProducts) });
            if (!dr.success) { toast('Errore diff', 'err'); return; }
            sfRenderPreview(dr.data, r.data.csv_rows);
        } catch (e) { toast('Errore', 'err'); }
        finally { ov.classList.remove('visible'); btn.disabled = false; sp.style.display = 'none'; }
    }

    async function sfUploadFile(file) {
        const ov = document.getElementById('sf-overlay'), ot = document.getElementById('sf-overlay-text');
        ot.textContent = 'Upload e parsing...';
        ov.classList.add('visible');
        try {
            const fd = new FormData();
            fd.append('action', 'gh_ajax_sf_upload');
            fd.append('nonce', NONCE);
            fd.append('csv_file', file);
            const resp = await fetch(AJAX, { method: 'POST', body: fd });
            const r = await resp.json();
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            document.getElementById('sf-file-name').textContent = file.name + ' \u00b7 ' + r.data.csv_rows + ' righe \u00b7 ' + r.data.product_count + ' prodotti';
            sfProducts = r.data.products;
            document.getElementById('sf-csv-rows').textContent = r.data.csv_rows;
            toast(r.data.product_count + ' prodotti', 'ok');
            ot.textContent = 'Confronto WooCommerce...';
            const dr = await ajax('gh_ajax_sf_preview', { products: JSON.stringify(sfProducts) });
            if (!dr.success) { toast('Errore diff', 'err'); return; }
            sfRenderPreview(dr.data, r.data.csv_rows);
        } catch (e) { toast('Errore', 'err'); }
        finally { ov.classList.remove('visible'); }
    }

    function sfRenderPreview(d, csvRows) {
        const s = d.summary;
        sfDiffData = d;
        document.getElementById('sf-stats').style.display = 'flex';
        document.getElementById('sf-total').textContent = s.total;
        document.getElementById('sf-new').textContent = s.new;
        document.getElementById('sf-update').textContent = s.update;
        document.getElementById('sf-unchanged').textContent = s.unchanged;
        const all = [...d.new.map(p => ({...p, _a: 'new'})), ...d.update.map(p => ({...p, _a: 'update'})), ...d.unchanged.map(p => ({...p, _a: 'unchanged'}))];
        sfSelected = new Set(all.filter(p => p._a !== 'unchanged').map(p => p.sku));
        let h = '<table class="ptable"><thead><tr><th style="width:28px"><input type="checkbox" id="sf-check-all" onchange="GH.sfToggleAll(this.checked)" /></th><th>Azione</th><th>SKU</th><th>Nome</th><th>Brand</th><th>Cat</th><th>Taglie</th><th>Qty</th><th>Prezzo</th></tr></thead><tbody>';
        for (const p of all) {
            const cls = p._a === 'new' ? 'st-new' : p._a === 'update' ? 'st-update' : 'st-unchanged';
            const lb = p._a === 'new' ? '+ Nuovo' : p._a === 'update' ? '\u21bb Agg.' : '\u2713';
            const sz = p.variations ? p.variations.length : 0;
            const brand = p._sf_brand || '';
            const cat = (p._sf_category || '') + (p._sf_subcategory ? ' > ' + p._sf_subcategory : '');
            const ck = sfSelected.has(p.sku) ? 'checked' : '';
            h += '<tr><td><input type="checkbox" class="sf-check" data-sku="' + esc(p.sku) + '" data-type="' + p._a + '" ' + ck + ' onchange="GH.sfToggle(this)" /></td>';
            h += '<td class="' + cls + '">' + lb + '</td>';
            h += '<td>' + esc(p.sku || '') + '</td>';
            h += '<td>' + esc(p.name || '') + '</td>';
            h += '<td>' + esc(brand) + '</td>';
            h += '<td style="font-size:10px">' + esc(cat) + '</td>';
            h += '<td>' + sz + '</td>';
            h += '<td>' + (p.stock_quantity ?? (p.variations ? p.variations.reduce((a, v) => a + (v.stock_quantity || 0), 0) : '?')) + '</td>';
            h += '<td>' + (p.regular_price || (p.variations?.[0]?.regular_price || '?')) + '\u20ac</td></tr>';
        }
        h += '</tbody></table>';
        document.getElementById('sf-preview').innerHTML = h;
        document.getElementById('sf-sel-bar').style.display = 'flex';
        sfUpdateSelCount(); sfUpdateConfirm();
    }

    function sfToggle(cb) { if (cb.checked) sfSelected.add(cb.dataset.sku); else sfSelected.delete(cb.dataset.sku); sfUpdateSelCount(); sfUpdateConfirm(); }
    function sfToggleAll(on) { document.querySelectorAll('#sf-preview .sf-check').forEach(c => { c.checked = on; if (on) sfSelected.add(c.dataset.sku); else sfSelected.delete(c.dataset.sku); }); sfUpdateSelCount(); sfUpdateConfirm(); }
    function sfSelectAll() { document.querySelectorAll('#sf-preview .sf-check').forEach(c => { c.checked = true; sfSelected.add(c.dataset.sku); }); sfUpdateSelCount(); sfUpdateConfirm(); }
    function sfSelectNone() { document.querySelectorAll('#sf-preview .sf-check').forEach(c => { c.checked = false; }); sfSelected.clear(); sfUpdateSelCount(); sfUpdateConfirm(); }
    function sfSelectByType(type) { document.querySelectorAll('#sf-preview .sf-check').forEach(c => { const on = c.dataset.type === type; c.checked = on; if (on) sfSelected.add(c.dataset.sku); else sfSelected.delete(c.dataset.sku); }); sfUpdateSelCount(); sfUpdateConfirm(); }
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

    async function sfApply() {
        if (!sfProducts || !sfSelected.size) return;
        const sel = sfProducts.filter(p => sfSelected.has(p.sku));
        const ov = document.getElementById('sf-overlay'), ot = document.getElementById('sf-overlay-text');
        const btn = document.getElementById('btn-sf-apply'), sp = document.getElementById('sf-apply-spin');
        ot.textContent = 'Importazione ' + sel.length + ' prodott' + (sel.length === 1 ? 'o' : 'i') + '...';
        ov.classList.add('visible'); btn.disabled = true; sp.style.display = '';
        try {
            const r = await ajax('gh_ajax_sf_apply', {
                products: JSON.stringify(sel),
                options: JSON.stringify({ create_new: true, update_existing: true, sideload_images: document.getElementById('sf-opt-images').checked })
            });
            if (!r.success) { toast('Errore', 'err'); return; }
            const s = r.data.summary;
            let h = '<table class="ptable"><thead><tr><th>Risultato</th><th>ID</th><th>SKU</th><th>Nome</th></tr></thead><tbody>';
            for (const d of r.data.details) {
                const c = d.action === 'created' ? 'st-created' : d.action === 'updated' ? 'st-updated' : 'st-error';
                const l = d.action === 'created' ? '+ Creato' : d.action === 'updated' ? '\u2713 Agg.' : '\u2717 Err';
                h += '<tr><td class="' + c + '">' + l + '</td><td>' + (d.id || '\u2013') + '</td><td>' + esc(d.sku || '') + '</td><td>' + esc(d.name || '') + '</td></tr>';
            }
            h += '</tbody></table>';
            document.getElementById('sf-preview').innerHTML = h;
            document.getElementById('sf-confirm').style.display = 'none';
            document.getElementById('sf-sel-bar').style.display = 'none';
            toast(s.created + ' creati, ' + s.updated + ' aggiornati', 'ok', 5000);
        } catch (e) { toast('Errore', 'err'); }
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

    // ── INIT
    (async function(){const r=await ajax('rp_cm_ajax_get_tree_paths');if(r.success){(r.data.brands||[]).forEach(b=>{['cat-filter-brand','rt-filter-brand'].forEach(id=>{const s=document.getElementById(id);if(s){const o=document.createElement('option');o.value=b;o.textContent=b;s.appendChild(o)}})})}})();
    initBulkImport();
    initRtImport();
    initSfUpload();
    initCsvUpload();

    return{ajax,toast,esc,switchTab,loadSummary,generateCatalog,loadTaxonomy,taxSelect,taxToggle,taxCreateRoot,taxAdd,taxRename,taxDelete,loadMapping,browseMedia,debounceBrowse,showUsage,scanOrphans,toggleOrphan,orphanAction,bulkDeleteOrphans,loadWhitelist,removeWL,gsFetch,gsApply,gsCancel,gsToggle,gsToggleAll,gsSelectAll,gsSelectNone,gsSelectByType,sfFetch,sfApply,sfCancel,sfToggle,sfToggleAll,sfSelectAll,sfSelectNone,sfSelectByType,sfToggleSource,bulkPreview,bulkApply,bulkCancel,generateRoundtrip,importPreview,importApply,importCancel,copyJSON,downloadJSON,hcExecute,csvLoadFeeds,csvNewFeed,csvEditFeed,csvBackToList,csvToggleSource,csvToggleMapping,csvTestUrl,csvSaveFeed,csvDeleteFeed,csvPreview,csvRunFeed,csvRunFeedFromList,csvOnPresetChange};
})();
