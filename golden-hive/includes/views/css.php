<style>
#gh{all:initial}#gh *,#gh *::before,#gh *::after{box-sizing:border-box;margin:0;padding:0;font-family:'DM Sans',system-ui,sans-serif}
#gh{--bg:#0c0d10;--s1:#111317;--s2:#16181d;--s3:#1c1f26;--b1:#232630;--b2:#2e3240;--acc:#3d7fff;--grn:#22c78b;--red:#e85d5d;--amb:#e8a824;--pur:#9b72f5;--txt:#d8dce8;--dim:#5f6480;--mut:#2a2d3a;--mono:'JetBrains Mono','Courier New',monospace;display:flex;flex-direction:column;height:100vh;background:var(--bg);color:var(--txt);font-size:13px;margin:-10px -20px -20px -20px;overflow:hidden}

/* Header */
#gh .header{background:var(--s1);border-bottom:1px solid var(--b1);padding:10px 20px;display:flex;align-items:center;gap:16px;flex-shrink:0}
#gh .header-logo{font-family:var(--mono);font-size:11px;font-weight:600;letter-spacing:.2em;color:var(--acc);text-transform:uppercase}
#gh .header-desc{font-size:11px;color:var(--dim);font-family:var(--mono)}

/* Layout */
#gh .main{flex:1;display:flex;overflow:hidden}
#gh .tabs-col{width:160px;background:var(--s1);border-right:1px solid var(--b1);display:flex;flex-direction:column;flex-shrink:0;overflow-y:auto}
#gh .tab-section{font-family:var(--mono);font-size:8px;letter-spacing:.15em;text-transform:uppercase;color:var(--dim);padding:12px 16px 4px;opacity:.6}
#gh .tab-item{padding:10px 16px;cursor:pointer;border-left:2px solid transparent;border-bottom:1px solid var(--b1);transition:all .15s;display:flex;align-items:center;gap:10px}
#gh .tab-item:hover{background:var(--s2)}
#gh .tab-item.active{background:var(--s3);border-left-color:var(--acc)}
#gh .tab-icon{font-size:13px;width:16px;text-align:center}
#gh .tab-label{font-size:11px;font-weight:500;color:var(--dim)}
#gh .tab-item.active .tab-label{color:var(--txt)}
#gh .content{flex:1;display:flex;flex-direction:column;overflow:hidden}
#gh .panel{display:none;flex-direction:column;flex:1;overflow:hidden}
#gh .panel.active{display:flex}

/* Buttons */
#gh .btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border:1px solid transparent;border-radius:4px;font-family:var(--mono);font-size:10px;font-weight:600;letter-spacing:.06em;cursor:pointer;transition:all .15s;white-space:nowrap}
#gh .btn:disabled{opacity:.3;cursor:not-allowed}
#gh .btn-primary{background:var(--acc);color:#fff;border-color:var(--acc)}
#gh .btn-primary:hover:not(:disabled){filter:brightness(1.15)}
#gh .btn-ghost{background:transparent;color:var(--dim);border-color:var(--b2)}
#gh .btn-ghost:hover:not(:disabled){color:var(--txt);background:var(--s3)}
#gh .btn-danger{background:rgba(232,93,93,.1);color:var(--red);border-color:rgba(232,93,93,.3)}
#gh .btn-danger:hover:not(:disabled){background:rgba(232,93,93,.2)}
#gh .btn-warn{background:rgba(232,168,36,.15);color:var(--amb);border-color:rgba(232,168,36,.4)}
#gh .btn-warn:hover:not(:disabled){background:rgba(232,168,36,.25)}

/* Toolbar */
#gh .toolbar{background:var(--s2);border-bottom:1px solid var(--b1);padding:10px 20px;display:flex;align-items:center;gap:12px;flex-shrink:0;flex-wrap:wrap}
#gh .filter-sep{width:1px;height:20px;background:var(--b1);flex-shrink:0}
#gh .filter-label{font-family:var(--mono);font-size:9px;letter-spacing:.1em;color:var(--dim);text-transform:uppercase;white-space:nowrap}
#gh .filter-select{background:var(--s3);border:1px solid var(--b1);border-radius:4px;padding:5px 8px;font-family:var(--mono);font-size:11px;color:var(--txt);outline:none;cursor:pointer}
#gh .filter-select:focus{border-color:var(--acc)}
#gh .filter-toggle{display:flex;align-items:center;gap:4px}
#gh .filter-toggle input{accent-color:var(--acc);cursor:pointer}

/* Config form (feeds) */
#gh .config-form{padding:16px 20px;display:flex;flex-direction:column;gap:10px;flex-shrink:0;border-bottom:1px solid var(--b1)}
#gh .cfg-row{display:flex;align-items:center;gap:10px}
#gh .cfg-label{font-family:var(--mono);font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:var(--dim);min-width:60px}
#gh .cfg-input{flex:1;background:var(--s3);border:1px solid var(--b1);border-radius:4px;padding:6px 10px;font-family:var(--mono);font-size:11px;color:var(--txt);outline:none}
#gh .cfg-input:focus{border-color:var(--acc)}
#gh .cfg-input::placeholder{color:var(--dim)}
#gh .cfg-select{background:var(--s3);border:1px solid var(--b1);border-radius:4px;padding:6px 8px;font-family:var(--mono);font-size:11px;color:var(--txt);outline:none}

/* Summary cards */
#gh .summary-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;padding:20px}
#gh .summary-card{background:var(--s2);border:1px solid var(--b1);border-radius:8px;padding:16px;display:flex;flex-direction:column;gap:6px}
#gh .sc-label{font-family:var(--mono);font-size:9px;letter-spacing:.12em;text-transform:uppercase;color:var(--dim)}
#gh .sc-value{font-family:var(--mono);font-size:22px;font-weight:600;color:var(--txt)}
#gh .sc-value.green{color:var(--grn)}#gh .sc-value.blue{color:var(--acc)}#gh .sc-value.amber{color:var(--amb)}#gh .sc-value.purple{color:var(--pur)}

/* Preview / data tables */
#gh .preview-wrap{flex:1;overflow-y:auto}
#gh table.ptable{width:100%;border-collapse:collapse}
#gh .ptable thead th{background:var(--s2);border-bottom:2px solid var(--b1);padding:8px 10px;font-family:var(--mono);font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:var(--dim);text-align:left;font-weight:600;position:sticky;top:0;z-index:10}
#gh .ptable tbody tr{border-bottom:1px solid var(--b1)}
#gh .ptable tbody tr:hover{background:rgba(255,255,255,.02)}
#gh .ptable td{padding:6px 10px;font-family:var(--mono);font-size:11px;vertical-align:middle}
#gh .st-new,.st-create{color:var(--acc)}#gh .st-update,.st-matched{color:var(--amb)}#gh .st-unchanged,.st-skipped{color:var(--dim)}
#gh .st-created,.st-updated{color:var(--grn)}#gh .st-error{color:var(--red)}
#gh .ptable .gs-check{width:14px;height:14px;accent-color:var(--acc);cursor:pointer;vertical-align:middle}
#gh .gs-sel-bar{background:var(--s2);border-bottom:1px solid var(--b1);padding:6px 20px;display:flex;align-items:center;gap:10px;flex-shrink:0}
#gh .gs-sel-bar .gs-sel-count{font-family:var(--mono);font-size:10px;color:var(--dim);margin-left:auto}
#gh .changes-list{font-size:10px;color:var(--amb)}
#gh .old-val{color:var(--red);text-decoration:line-through}
#gh .new-val{color:var(--grn)}

/* JSON viewer */
#gh .json-area{flex:1;overflow-y:auto;padding:16px 20px;font-family:var(--mono);font-size:11px;line-height:1.7;white-space:pre-wrap;word-break:break-all}
#gh .jk{color:#a78bfa}#gh .js{color:var(--grn)}#gh .jn{color:var(--amb)}#gh .jb{color:var(--acc)}#gh .jx{color:var(--red)}
#gh .json-toolbar{background:var(--s1);border-top:1px solid var(--b1);padding:8px 20px;display:flex;align-items:center;gap:10px;flex-shrink:0}
#gh .file-size{font-family:var(--mono);font-size:10px;color:var(--dim);margin-left:auto}

/* Confirm bar */
#gh .confirm-bar{background:var(--s1);border-top:1px solid var(--b1);padding:10px 20px;display:flex;align-items:center;gap:12px;flex-shrink:0}
#gh .confirm-bar .summary-text{font-family:var(--mono);font-size:11px;color:var(--txt);flex:1}
#gh .confirm-bar .summary-text span{font-weight:600}

/* Section title */
#gh .section-title{font-family:var(--mono);font-size:9px;letter-spacing:.15em;text-transform:uppercase;color:var(--dim);padding:12px 20px 6px;border-bottom:1px solid var(--b1);flex-shrink:0}

/* Drop area */
#gh .drop-area{border:2px dashed var(--b2);border-radius:8px;padding:24px;text-align:center;cursor:pointer;transition:all .15s;margin:16px 20px;flex-shrink:0}
#gh .drop-area:hover,#gh .drop-area.dragover{border-color:var(--acc);background:rgba(61,127,255,.05)}
#gh .drop-area-text{font-family:var(--mono);font-size:11px;color:var(--dim)}
#gh .drop-area-file{font-family:var(--mono);font-size:12px;color:var(--grn);margin-top:6px}

/* Mode row */
#gh .mode-row{display:flex;align-items:center;gap:16px;padding:8px 20px;flex-shrink:0}
#gh .mode-row label{font-family:var(--mono);font-size:11px;color:var(--txt);display:flex;align-items:center;gap:4px;cursor:pointer}
#gh .mode-row input[type="radio"]{accent-color:var(--acc)}

/* Media grid */
#gh .media-grid{flex:1;overflow-y:auto;padding:16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;align-content:start}
#gh .media-card{background:var(--s2);border:1px solid var(--b1);border-radius:6px;overflow:hidden;cursor:pointer;transition:all .15s;position:relative}
#gh .media-card:hover{border-color:var(--b2)}
#gh .media-card.selected{border-color:var(--acc);box-shadow:0 0 0 1px var(--acc)}
#gh .media-card.whitelisted{border-color:rgba(232,168,36,.3)}
#gh .media-thumb{width:100%;aspect-ratio:1;object-fit:cover;display:block;background:var(--s3)}
#gh .media-info{padding:6px 8px}
#gh .media-filename{font-family:var(--mono);font-size:9px;color:var(--txt);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#gh .media-size{font-family:var(--mono);font-size:9px;color:var(--dim)}
#gh .media-badge{position:absolute;top:6px;right:6px;font-family:var(--mono);font-size:8px;font-weight:600;padding:2px 5px;border-radius:3px}
#gh .badge-wl{background:rgba(232,168,36,.2);color:var(--amb)}
#gh .media-check{position:absolute;top:6px;left:6px;accent-color:var(--acc);width:14px;height:14px;cursor:pointer}

/* Mapping table */
#gh .map-wrap{flex:1;overflow-y:auto}
#gh table.maptable{width:100%;border-collapse:collapse}
#gh .maptable thead th{background:var(--s2);border-bottom:2px solid var(--b1);padding:8px 12px;font-family:var(--mono);font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:var(--dim);text-align:left;font-weight:600;position:sticky;top:0;z-index:10}
#gh .maptable tbody tr{border-bottom:1px solid var(--b1)}
#gh .maptable tbody tr:hover{background:rgba(255,255,255,.02)}
#gh .maptable td{padding:8px 12px;vertical-align:middle}
#gh .map-thumb{width:40px;height:40px;object-fit:cover;border-radius:4px;background:var(--s3)}
#gh .map-gallery{display:flex;gap:4px}
#gh .map-gallery img{width:32px;height:32px;object-fit:cover;border-radius:3px;background:var(--s3)}
#gh .map-name{font-size:12px;font-weight:500}
#gh .map-sku{font-family:var(--mono);font-size:10px;color:var(--dim)}
#gh .map-none{font-family:var(--mono);font-size:10px;color:var(--dim);font-style:italic}

/* Taxonomy tree */
#gh .tax-wrap{flex:1;overflow-y:auto;padding:16px 20px}
#gh .tax-node{margin-left:0}#gh .tax-node .tax-node{margin-left:20px}
#gh .tax-row{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:4px;transition:background .1s;cursor:pointer}
#gh .tax-row:hover{background:var(--s3)}
#gh .tax-row.selected{background:rgba(61,127,255,.08);border-left:2px solid var(--acc)}
#gh .tax-toggle{width:16px;font-size:10px;color:var(--dim);cursor:pointer;text-align:center;flex-shrink:0}
#gh .tax-name{font-size:13px;font-weight:500;color:var(--txt);flex:1}
#gh .tax-name.depth-0{font-weight:600}
#gh .tax-count{font-family:var(--mono);font-size:9px;color:var(--dim);background:var(--mut);padding:1px 5px;border-radius:3px}
#gh .tax-id{font-family:var(--mono);font-size:9px;color:var(--dim)}
#gh .tax-actions{display:none;gap:4px}
#gh .tax-row:hover .tax-actions{display:flex}
#gh .tax-btn{font-family:var(--mono);font-size:9px;padding:2px 6px;border-radius:3px;border:1px solid var(--b1);background:transparent;color:var(--dim);cursor:pointer;transition:all .15s}
#gh .tax-btn:hover{color:var(--txt);background:var(--s2);border-color:var(--b2)}
#gh .tax-btn.del:hover{color:var(--red);border-color:rgba(232,93,93,.3)}
#gh .tax-detail{background:var(--s2);border-left:1px solid var(--b1);width:340px;display:flex;flex-direction:column;flex-shrink:0;overflow:hidden}
#gh .tax-detail-head{padding:12px 16px;border-bottom:1px solid var(--b1);display:flex;align-items:center;gap:8px;flex-shrink:0}
#gh .tax-detail-title{font-size:13px;font-weight:600;flex:1}
#gh .tax-detail-id{font-family:var(--mono);font-size:10px;color:var(--dim)}
#gh .tax-products{flex:1;overflow-y:auto;padding:8px 0}
#gh .tax-product-row{display:flex;align-items:center;gap:8px;padding:5px 16px;font-size:12px;border-bottom:1px solid var(--b1)}
#gh .tax-product-row:hover{background:var(--s3)}
#gh .tax-product-id{font-family:var(--mono);font-size:9px;color:var(--dim);min-width:32px}
#gh .tax-product-name{flex:1;color:var(--txt)}
#gh .tax-product-type{font-family:var(--mono);font-size:9px;padding:1px 5px;border-radius:3px}
#gh .type-variable{background:rgba(155,114,245,.15);color:var(--pur)}
#gh .type-simple{background:rgba(61,127,255,.15);color:var(--acc)}

/* Whitelist */
#gh .wl-wrap{flex:1;overflow-y:auto;padding:16px}
#gh .wl-row{display:flex;align-items:center;gap:12px;padding:8px 12px;border-bottom:1px solid var(--b1)}
#gh .wl-row:hover{background:var(--s3)}
#gh .wl-thumb{width:36px;height:36px;object-fit:cover;border-radius:4px;background:var(--s3)}
#gh .wl-info{flex:1}
#gh .wl-name{font-size:12px;font-weight:500}
#gh .wl-reason{font-family:var(--mono);font-size:10px;color:var(--dim)}
#gh .wl-id{font-family:var(--mono);font-size:9px;color:var(--dim)}

/* Stats bar */
#gh .stats-bar{background:var(--s1);border-bottom:1px solid var(--b1);padding:8px 20px;display:flex;gap:20px;flex-shrink:0}
#gh .stat{font-family:var(--mono);font-size:10px;color:var(--dim)}
#gh .stat span{font-weight:600}
#gh .stat .blue{color:var(--acc)}#gh .stat .green{color:var(--grn)}#gh .stat .red{color:var(--red)}#gh .stat .amber{color:var(--amb)}

/* Search input */
#gh .search-input{background:var(--s3);border:1px solid var(--b1);border-radius:6px;padding:6px 12px;font-family:var(--mono);font-size:12px;color:var(--txt);outline:none;width:240px}
#gh .search-input:focus{border-color:var(--acc)}
#gh .search-input::placeholder{color:var(--dim)}

/* Empty state */
#gh .empty-state{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:var(--dim)}
#gh .empty-icon{font-size:32px}
#gh .empty-text{font-family:var(--mono);font-size:12px;letter-spacing:.08em;text-align:center}

/* Toast + Spinner + Overlay */
#gh .toast-wrap{position:fixed;bottom:20px;right:20px;display:flex;flex-direction:column;gap:6px;z-index:9999;pointer-events:none}
#gh .toast{font-family:var(--mono);font-size:11px;padding:9px 14px;border-radius:5px;border:1px solid;pointer-events:none;max-width:360px;animation:gh-tin .18s ease}
@keyframes gh-tin{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
#gh .toast.ok{background:rgba(34,199,139,.15);border-color:rgba(34,199,139,.4);color:var(--grn)}
#gh .toast.err{background:rgba(232,93,93,.15);border-color:rgba(232,93,93,.4);color:var(--red)}
#gh .toast.inf{background:rgba(61,127,255,.15);border-color:rgba(61,127,255,.4);color:var(--acc)}
#gh .spin{display:inline-block;width:9px;height:9px;border:1.5px solid var(--b2);border-top-color:var(--acc);border-radius:50%;animation:gh-sp .5s linear infinite}
@keyframes gh-sp{to{transform:rotate(360deg)}}
#gh .gen-overlay{display:none;position:absolute;inset:0;background:rgba(12,13,16,.85);z-index:50;align-items:center;justify-content:center;flex-direction:column;gap:12px}
#gh .gen-overlay.visible{display:flex}
#gh .gen-text{font-family:var(--mono);font-size:12px;color:var(--dim)}
#gh .gen-spinner{width:24px;height:24px;border:2px solid var(--b2);border-top-color:var(--acc);border-radius:50%;animation:gh-sp .6s linear infinite}
#gh *::-webkit-scrollbar{width:4px;height:4px}
#gh *::-webkit-scrollbar-thumb{background:var(--b2);border-radius:2px}
#gh *{scrollbar-width:thin;scrollbar-color:var(--b2) transparent}
/* Filter & Bulk — table */
#gh .tbl-th{padding:6px 10px;text-align:left;font-family:var(--mono);font-size:9px;color:var(--dim);text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--b2);white-space:nowrap}
#gh .tbl-td{padding:5px 10px;color:var(--txt);white-space:nowrap}
#gh .mono{font-family:var(--mono);font-size:11px}
#gh .dim{color:var(--dim)}
/* Badges */
#gh .badge{display:inline-block;padding:1px 6px;border-radius:3px;font-family:var(--mono);font-size:8px;font-weight:600;letter-spacing:.06em;text-transform:uppercase}
#gh .badge-publish{background:rgba(34,199,139,.12);color:var(--grn)}
#gh .badge-draft{background:rgba(95,100,128,.15);color:var(--dim)}
#gh .badge-private{background:rgba(232,168,36,.12);color:var(--amb)}
#gh .badge-variable{background:rgba(155,114,245,.12);color:var(--pur)}
#gh .badge-simple{background:rgba(61,127,255,.12);color:var(--acc)}
#gh .badge-instock{background:rgba(34,199,139,.12);color:var(--grn)}
#gh .badge-outofstock{background:rgba(232,93,93,.12);color:var(--red)}
#gh .badge-onbackorder{background:rgba(232,168,36,.12);color:var(--amb)}
/* Condition rows */
#gh .cond-row select,#gh .cond-row input{font-size:11px}
@media(max-width:768px){#gh .tabs-col{width:48px}#gh .tab-label,#gh .tab-section{display:none}#gh .tab-item{justify-content:center;padding:10px 8px}#gh .summary-grid{grid-template-columns:repeat(2,1fr)}#gh .tax-detail{display:none!important}}
</style>
