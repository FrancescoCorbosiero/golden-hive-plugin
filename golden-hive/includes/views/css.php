<style>
#gh{all:initial}#gh *,#gh *::before,#gh *::after{box-sizing:border-box;margin:0;padding:0;font-family:'DM Sans',system-ui,sans-serif}
/* Root: calc the exact remaining space after WP admin bar (32px desktop, 46px mobile).
   Negative margins cancel the padding WP Admin wraps around .wrap / #wpbody-content. */
#gh{--bg:#0c0d10;--s1:#111317;--s2:#16181d;--s3:#1c1f26;--b1:#232630;--b2:#2e3240;--acc:#3d7fff;--grn:#22c78b;--red:#e85d5d;--amb:#e8a824;--pur:#9b72f5;--txt:#d8dce8;--dim:#5f6480;--mut:#2a2d3a;--mono:'JetBrains Mono','Courier New',monospace;--sans:'DM Sans',system-ui,sans-serif;display:flex;flex-direction:column;height:calc(100vh - 32px);background:var(--bg);color:var(--txt);font-size:13px;margin:-10px -20px -20px -20px;overflow:hidden;box-sizing:border-box}
#gh *,#gh *::before,#gh *::after{box-sizing:inherit}
@media screen and (max-width:782px){#gh{height:calc(100vh - 46px)}}

/* Thin dark scrollbars everywhere inside the plugin */
#gh ::-webkit-scrollbar{width:6px;height:6px}
#gh ::-webkit-scrollbar-track{background:transparent}
#gh ::-webkit-scrollbar-thumb{background:var(--b2);border-radius:3px}
#gh ::-webkit-scrollbar-thumb:hover{background:var(--dim)}
#gh{scrollbar-width:thin;scrollbar-color:var(--b2) transparent}

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

/* Media Library (unified browser) */
#gh #panel-media-library{flex-direction:column;overflow:hidden}
#gh #ml-results{padding:0 16px}
#gh table.ml-table{width:100%;border-collapse:collapse;font-size:12px}
#gh .ml-table thead th{background:var(--s1);border-bottom:2px solid var(--b1);padding:8px 10px;font-family:var(--mono);font-size:9px;letter-spacing:.08em;text-transform:uppercase;color:var(--dim);text-align:left;font-weight:600;position:sticky;top:0;z-index:10}
#gh .ml-table tbody tr{border-bottom:1px solid var(--b1);transition:background .1s}
#gh .ml-table tbody tr:hover{background:rgba(255,255,255,.02)}
#gh .ml-table td{padding:6px 10px;vertical-align:middle}
#gh .ml-row-sel{background:rgba(61,127,255,.07) !important}
#gh .ml-row-wl{border-left:2px solid var(--grn)}
#gh .ml-thumb{width:44px;height:44px;object-fit:cover;border-radius:3px;background:var(--s3);display:block}
#gh .ml-name{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:var(--txt)}
#gh .ml-usages{display:flex;flex-wrap:wrap;gap:4px;max-width:420px}
#gh .ml-unmapped{font-family:var(--mono);font-size:10px;color:var(--amb);font-style:italic}
#gh .ml-usage{display:inline-flex;align-items:center;gap:4px;padding:2px 6px;border-radius:3px;background:var(--s3);border:1px solid var(--b1);font-family:var(--mono);font-size:10px;color:var(--txt);white-space:nowrap}
#gh .ml-usage .ml-role{font-size:8px;text-transform:uppercase;letter-spacing:.05em;color:var(--dim);font-weight:600}
#gh .ml-usage.role-featured .ml-role{color:var(--acc)}
#gh .ml-usage.role-gallery .ml-role{color:var(--pur)}
#gh .ml-usage.role-variation .ml-role{color:var(--amb)}
#gh .ml-usage.role-post_featured .ml-role{color:var(--grn)}
#gh .ml-usage.role-content .ml-role{color:var(--dim)}
#gh .ml-usage .ml-pid{color:var(--txt)}
#gh .ml-usage .ml-eye{color:var(--acc);text-decoration:none;font-size:11px;cursor:pointer}
#gh .ml-usage .ml-eye:hover{color:var(--txt)}
#gh .ml-wl-badge{display:inline-block;padding:2px 6px;background:var(--grn);color:#000;font-family:var(--mono);font-size:9px;font-weight:700;border-radius:3px;letter-spacing:.05em}
#gh .ml-row-actions{text-align:right}
#gh .ml-row-actions .btn-sm{padding:3px 6px;font-size:9px}
#gh #ml-safe-preview{font-family:var(--sans);color:var(--txt)}
#gh #ml-pagination button{min-width:32px}

/* Mapping table */
#gh .map-wrap{flex:1;overflow-y:auto}
#gh table.maptable{width:100%;border-collapse:collapse}
#gh .maptable thead th{background:var(--s2);border-bottom:2px solid var(--b1);padding:8px 12px;font-family:var(--mono);font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:var(--dim);text-align:left;font-weight:600;position:sticky;top:0;z-index:10}
#gh .maptable tbody tr{border-bottom:1px solid var(--b1)}
#gh .maptable tbody tr:hover{background:rgba(255,255,255,.02)}
#gh .maptable td{padding:8px 12px;vertical-align:middle}
#gh .map-thumb{width:40px;height:40px;object-fit:cover;border-radius:4px;background:var(--s3)}
#gh .map-gallery{display:flex;gap:4px;flex-wrap:wrap}
#gh .map-gthumb{position:relative;display:inline-block;line-height:0}
#gh .map-gthumb img{width:32px;height:32px;object-fit:cover;border-radius:3px;background:var(--s3)}
#gh .map-gbtn{position:absolute;top:-4px;right:-4px;width:16px;height:16px;border-radius:50%;border:1px solid var(--b2);background:var(--s1);color:var(--txt);font-family:var(--mono);font-size:10px;line-height:1;padding:0;cursor:pointer;display:none;align-items:center;justify-content:center}
#gh .map-gthumb:hover .map-gbtn{display:flex}
#gh .map-gbtn.map-grm:hover{background:var(--red);border-color:var(--red);color:#fff}
#gh .map-name{font-size:12px;font-weight:500}
#gh .map-sku{font-family:var(--mono);font-size:10px;color:var(--dim)}
#gh .map-none{font-family:var(--mono);font-size:10px;color:var(--dim);font-style:italic}

/* Smart Rules (taxonomy) */
#gh .smart-rule-section{padding:12px;border-top:1px solid var(--b1);margin-top:8px}
#gh .smart-rule-head{display:flex;align-items:center;gap:8px;margin-bottom:8px}
#gh .smart-rule-label{font-family:var(--mono);font-size:11px;font-weight:600;color:var(--amb);text-transform:uppercase;letter-spacing:.05em}
#gh .smart-rule-status{font-family:var(--mono);font-size:10px}
#gh .sr-info{font-size:12px}
#gh .sr-conditions-summary{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px}
#gh .sr-cond-badge{display:inline-block;padding:2px 8px;background:var(--s3);border:1px solid var(--b1);border-radius:3px;font-family:var(--mono);font-size:10px;color:var(--txt)}
#gh .sr-editor{padding:4px 0}
#gh .sr-cond-row .filter-select{font-size:11px}

/* Inline Editor */
#gh #panel-inline-editor{flex-direction:column;overflow:hidden}
#gh .ie-search-drop{position:absolute;left:0;right:0;top:100%;z-index:30;background:var(--s2);border:1px solid var(--b2);border-radius:4px;max-height:280px;overflow-y:auto;display:none}
#gh .ie-search-drop.open{display:block}
#gh .ie-sr{display:flex;align-items:center;gap:8px;padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--b1);font-size:12px}
#gh .ie-sr:hover,#gh .ie-sr-focus{background:var(--s3)}
#gh .ie-sr-id{font-family:var(--mono);font-size:10px;color:var(--dim);min-width:50px}
#gh .ie-sr-name{flex:1;color:var(--txt)}
#gh .ie-sr-sku{font-family:var(--mono);font-size:10px;color:var(--acc)}
#gh .ie-sr-empty{padding:12px;text-align:center;color:var(--dim);font-size:11px}
#gh .ie-subtab{padding:8px 16px;background:none;border:none;border-bottom:2px solid transparent;color:var(--dim);font-family:var(--mono);font-size:11px;cursor:pointer;transition:all .15s}
#gh .ie-subtab:hover{color:var(--txt)}
#gh .ie-subtab.active{color:var(--acc);border-bottom-color:var(--acc)}
#gh .ie-form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:16px}
#gh .ie-form-wide{grid-template-columns:1fr}
#gh .ie-field{display:flex;flex-direction:column;gap:4px}
#gh .ie-label{font-family:var(--mono);font-size:10px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em}
#gh .ie-input{background:var(--s3);border:1px solid var(--b1);border-radius:4px;padding:6px 10px;font-family:var(--mono);font-size:12px;color:var(--txt);outline:none;transition:border-color .15s}
#gh .ie-input:focus{border-color:var(--acc)}
#gh .ie-textarea{min-height:60px;resize:vertical;font-family:var(--sans);font-size:12px}
#gh .ie-json-editor{width:100%;min-height:400px;background:var(--bg);border:1px solid var(--b1);border-radius:4px;padding:14px;font-family:var(--mono);font-size:11.5px;line-height:1.7;color:var(--txt);outline:none;resize:vertical;tab-size:2}
#gh .ie-dirty-badge{font-family:var(--mono);font-size:10px;color:var(--amb);font-weight:600}
#gh .ie-var-table{width:100%;border-collapse:collapse;font-size:12px}
#gh .ie-var-table thead th{background:var(--s1);padding:8px 10px;font-family:var(--mono);font-size:9px;color:var(--dim);text-transform:uppercase;letter-spacing:.08em;text-align:left;border-bottom:2px solid var(--b1);position:sticky;top:0;z-index:5}
#gh .ie-var-table tbody tr{border-bottom:1px solid var(--b1)}
#gh .ie-var-table tbody tr:hover{background:rgba(255,255,255,.02)}
#gh .ie-var-table td{padding:4px 6px;vertical-align:middle}
#gh .ie-var-size{font-family:var(--mono);font-weight:600;font-size:12px;min-width:50px}
#gh .ie-var-input{background:var(--s3);border:1px solid var(--b1);border-radius:3px;padding:4px 6px;font-family:var(--mono);font-size:11px;color:var(--txt);outline:none;width:100%;min-width:70px}
#gh .ie-var-input:focus{border-color:var(--acc)}
#gh .ie-var-sale{color:var(--grn)}

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

/* ═══ UI MAPPER ══════════════════════════════════════════════ */
/* Rules list */
#gh .mp-rules-list{flex:1;overflow-y:auto;padding:16px 20px}
#gh .mp-rules-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
#gh .mp-rule-card{background:var(--s2);border:1px solid var(--b1);border-radius:8px;padding:16px;transition:border-color .15s}
#gh .mp-rule-card:hover{border-color:var(--b2)}
#gh .mp-rule-card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
#gh .mp-rule-card-name{font-size:13px;font-weight:600;color:var(--txt)}
#gh .mp-rule-card-count{font-family:var(--mono);font-size:9px;padding:2px 6px;border-radius:3px;background:rgba(61,127,255,.12);color:var(--acc)}
#gh .mp-rule-card-desc{font-size:11px;color:var(--dim);margin-bottom:4px}
#gh .mp-rule-card-path{font-family:var(--mono);font-size:9px;color:var(--pur);margin-bottom:4px}
#gh .mp-rule-card-meta{font-family:var(--mono);font-size:9px;color:var(--dim);margin-bottom:8px}
#gh .mp-rule-card-actions{display:flex;gap:6px}

/* Steps bar */
#gh .mp-steps{display:flex;background:var(--s2);border-bottom:1px solid var(--b1);flex-shrink:0}
#gh .mp-step{flex:1;padding:10px 16px;font-family:var(--mono);font-size:10px;color:var(--dim);text-align:center;border-bottom:2px solid transparent;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:6px}
#gh .mp-step.active{color:var(--txt);border-bottom-color:var(--acc)}
#gh .mp-step-n{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:var(--b1);font-size:9px;font-weight:600}
#gh .mp-step.active .mp-step-n{background:var(--acc);color:#fff}

/* Stages */
#gh .mp-stage{display:none;flex-direction:column;flex:1;overflow:hidden}
#gh .mp-stage.active{display:flex}
#gh .mp-form-row{display:flex;align-items:center;gap:10px;padding:8px 20px;flex-shrink:0}
#gh .mp-source-area{display:flex;flex-direction:column;flex:1;overflow:hidden;padding:0 20px 12px}
#gh .mp-or-label{font-family:var(--mono);font-size:9px;color:var(--dim);text-align:center;padding:6px 0;letter-spacing:.1em;text-transform:uppercase}
#gh .mp-source-textarea{flex:1;min-height:120px;background:var(--s3);border:1px solid var(--b1);border-radius:6px;padding:10px;font-family:var(--mono);font-size:11px;color:var(--txt);resize:none;outline:none}
#gh .mp-source-textarea:focus{border-color:var(--acc)}
#gh .mp-source-textarea::placeholder{color:var(--dim)}
#gh .mp-stage-actions{background:var(--s1);border-top:1px solid var(--b1);padding:10px 20px;display:flex;align-items:center;gap:12px;justify-content:flex-end;flex-shrink:0}

/* Three-column mapper layout */
#gh .mp-mapper-layout{display:flex;flex:1;overflow:hidden}
#gh .mp-col{display:flex;flex-direction:column;overflow:hidden}
#gh .mp-col-source,#gh .mp-col-target{width:220px;flex-shrink:0;border-right:1px solid var(--b1)}
#gh .mp-col-target{border-right:none;border-left:1px solid var(--b1)}
#gh .mp-col-mappings{flex:1;overflow:hidden;display:flex;flex-direction:column}
#gh .mp-col-head{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--s2);border-bottom:1px solid var(--b1);flex-shrink:0}
#gh .mp-col-title{font-family:var(--mono);font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:var(--dim);font-weight:600}
#gh .mp-col-count{font-family:var(--mono);font-size:9px;color:var(--dim)}

/* Field list items */
#gh .mp-field-list{flex:1;overflow-y:auto;padding:4px 0}
#gh .mp-field-item{display:flex;align-items:center;gap:6px;padding:5px 12px;border-bottom:1px solid var(--b1);font-family:var(--mono);font-size:10px;color:var(--txt);transition:background .1s}
#gh .mp-field-item:hover{background:var(--s3)}
#gh .mp-field-item.mp-connected{background:rgba(61,127,255,.06)}
#gh .mp-field-item.mp-connected .mp-field-dot{background:var(--acc);box-shadow:0 0 0 2px rgba(61,127,255,.3)}
#gh .mp-field-dot{width:8px;height:8px;border-radius:50%;background:var(--b2);flex-shrink:0;transition:all .15s}
#gh .mp-field-path{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#gh .mp-field-type{font-size:8px;padding:1px 4px;border-radius:2px;background:var(--mut);color:var(--dim);flex-shrink:0}
#gh .mp-type-string{background:rgba(34,199,139,.1);color:var(--grn)}
#gh .mp-type-number,#gh .mp-type-integer{background:rgba(232,168,36,.1);color:var(--amb)}
#gh .mp-type-boolean{background:rgba(61,127,255,.1);color:var(--acc)}
#gh .mp-type-array{background:rgba(155,114,245,.1);color:var(--pur)}
#gh .mp-type-select{background:rgba(61,127,255,.1);color:var(--acc)}
#gh .mp-field-sample{font-size:9px;color:var(--dim);max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#gh .mp-field-group{font-family:var(--mono);font-size:8px;letter-spacing:.12em;text-transform:uppercase;color:var(--dim);padding:8px 12px 3px;opacity:.6}

/* Mapping rows */
#gh .mp-mapping-rows{flex:1;overflow-y:auto;padding:8px 12px;display:flex;flex-direction:column;gap:8px}
#gh .mp-map-row{background:var(--s2);border:1px solid var(--b1);border-radius:6px;padding:10px;transition:border-color .15s}
#gh .mp-map-row:hover{border-color:var(--b2)}
#gh .mp-map-row-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
#gh .mp-map-row-num{font-family:var(--mono);font-size:9px;color:var(--dim)}
#gh .mp-map-row-body{display:flex;align-items:center;gap:8px;margin-bottom:6px}
#gh .mp-map-select{flex:1;background:var(--s3);border:1px solid var(--b1);border-radius:4px;padding:5px 6px;font-family:var(--mono);font-size:10px;color:var(--txt);outline:none}
#gh .mp-map-select:focus{border-color:var(--acc)}
#gh .mp-map-arrow{color:var(--acc);font-size:14px;flex-shrink:0}
#gh .mp-map-transforms{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
#gh .mp-transform-pill{font-family:var(--mono);font-size:9px;padding:2px 6px;border-radius:3px;background:rgba(155,114,245,.12);color:var(--pur);white-space:nowrap}
#gh .mp-transform-btn{font-family:var(--mono);font-size:9px;padding:2px 6px;border-radius:3px;border:1px dashed var(--b2);background:transparent;color:var(--dim);cursor:pointer;transition:all .15s}
#gh .mp-transform-btn:hover{color:var(--txt);border-color:var(--acc)}

/* Preview toolbar */
#gh .mp-preview-toolbar{background:var(--s2);border-bottom:1px solid var(--b1);padding:10px 20px;display:flex;align-items:center;gap:12px;flex-shrink:0}
#gh .mp-preview-summary{font-family:var(--mono);font-size:11px;color:var(--txt);flex:1}
#gh .mp-apply-form{display:flex;align-items:center;gap:8px;flex:1}
#gh .mp-json-mini{font-size:9px;color:var(--pur);max-width:160px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}

/* Transform modal */
#gh .mp-modal-overlay{position:fixed;inset:0;background:rgba(12,13,16,.7);z-index:100;display:flex;align-items:center;justify-content:center}
#gh .mp-modal{background:var(--s1);border:1px solid var(--b1);border-radius:10px;width:480px;max-width:90vw;max-height:70vh;display:flex;flex-direction:column;overflow:hidden}
#gh .mp-modal-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--b1)}
#gh .mp-modal-title{font-family:var(--mono);font-size:11px;font-weight:600;letter-spacing:.06em}
#gh .mp-modal-body{flex:1;overflow-y:auto;padding:12px 16px;display:flex;flex-direction:column;gap:8px}
#gh .mp-modal-foot{display:flex;align-items:center;gap:8px;padding:10px 16px;border-top:1px solid var(--b1)}
#gh .mp-modal-foot select{flex:1}
#gh .mp-transform-row{display:flex;align-items:center;gap:8px;padding:6px 8px;background:var(--s2);border-radius:4px}
#gh .mp-transform-label{font-family:var(--mono);font-size:10px;color:var(--pur);min-width:80px;flex-shrink:0}

/* ═══ EMAIL ══════════════════════════════════════════════════ */
#gh .em-form{padding:14px 20px;display:flex;flex-direction:column;gap:10px;flex-shrink:0;border-bottom:1px solid var(--b1)}
#gh .em-row-stretch{align-items:flex-start}
#gh .em-textarea{min-height:200px;resize:vertical;font-family:var(--mono);line-height:1.5;padding:10px}
#gh .em-textarea-sm{min-height:90px;resize:vertical;font-family:var(--mono);line-height:1.5;padding:8px}
#gh .em-hint{font-family:var(--mono);font-size:10px;color:var(--dim);padding:4px 0 0 70px}
#gh .em-hint-inline{font-family:var(--mono);font-size:9px;color:var(--dim);margin-left:6px}
#gh .em-csv-upload{padding:10px 20px;border-bottom:1px solid var(--b1);display:flex;align-items:center;gap:10px;flex-shrink:0}
#gh .em-csv-upload input[type=file]{font-family:var(--mono);font-size:11px;color:var(--txt)}
#gh .em-list{flex:1;overflow-y:auto;padding:0}
#gh .em-row{display:grid;grid-template-columns:140px 1fr 200px 80px 90px;gap:12px;align-items:center;padding:8px 20px;border-bottom:1px solid var(--b1);font-family:var(--mono);font-size:11px}
#gh .em-row:hover{background:var(--s2)}
#gh .em-row .em-time{color:var(--dim);font-size:10px;white-space:nowrap}
#gh .em-row .em-to{color:var(--txt);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#gh .em-row .em-subj{color:var(--dim);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:10px}
#gh .em-row .em-type{font-size:9px;text-transform:uppercase;letter-spacing:.06em;color:var(--dim)}
#gh .em-row .em-status{font-size:9px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;text-align:right}
#gh .em-row .em-status.ok{color:var(--grn)}
#gh .em-row .em-status.err{color:var(--red)}
#gh .em-row .em-err-detail{grid-column:2/-1;font-size:9px;color:var(--red);padding-top:2px;white-space:normal}
#gh .em-camp-list{flex:1;overflow-y:auto;padding:14px 20px;display:flex;flex-direction:column;gap:8px}
#gh .em-camp-card{background:var(--s2);border:1px solid var(--b1);border-radius:6px;padding:12px 14px;cursor:pointer;transition:border-color .15s}
#gh .em-camp-card:hover{border-color:var(--b2)}
#gh .em-camp-card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:4px}
#gh .em-camp-card-name{font-size:13px;font-weight:600;color:var(--txt)}
#gh .em-camp-card-subj{font-size:11px;color:var(--dim);margin-bottom:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#gh .em-camp-card-meta{font-family:var(--mono);font-size:9px;color:var(--dim);display:flex;gap:14px}
#gh .em-camp-editor{flex:1;display:flex;flex-direction:column;overflow:hidden}
#gh .em-camp-editor .em-form{flex:1;overflow-y:auto;border-bottom:none}
#gh .em-st{font-family:var(--mono);font-size:8px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding:2px 6px;border-radius:3px}
#gh .em-st-draft{background:rgba(95,100,128,.15);color:var(--dim)}
#gh .em-st-scheduled{background:rgba(232,168,36,.12);color:var(--amb)}
#gh .em-st-sending{background:rgba(155,114,245,.15);color:var(--pur)}
#gh .em-st-sent{background:rgba(34,199,139,.12);color:var(--grn)}
#gh .em-st-failed{background:rgba(232,93,93,.12);color:var(--red)}

/* ── TEMPLATE EDITOR (new UX) ─── */
#gh .em-tpl-box{border:1px solid var(--b1);border-radius:6px;background:var(--s2);overflow:hidden}
#gh .em-tpl-ph-box{}
#gh .em-tpl-box-head{display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;background:transparent;border:0;color:var(--txt);cursor:pointer;text-align:left;font-family:inherit}
#gh .em-tpl-box-head:hover{background:var(--s1)}
#gh .em-tpl-caret{display:inline-block;width:10px;color:var(--acc);font-size:10px;transition:transform .15s}
#gh .em-tpl-box-title{font-family:var(--mono);font-size:11px;color:var(--acc)}
#gh .em-tpl-box-title-strong{font-family:var(--mono);font-size:11px;color:var(--acc);text-transform:uppercase;letter-spacing:.08em;padding:10px 12px 6px;border-bottom:1px solid var(--b1);background:var(--s1)}
#gh .em-tpl-box-hint{font-family:var(--mono);font-size:10px;color:var(--dim);margin-left:auto}
#gh .em-tpl-ph-body{display:flex;flex-wrap:wrap;gap:4px;padding:10px 12px;border-top:1px solid var(--b1);font-family:var(--mono);font-size:10px}
#gh .em-tpl-ph-group{width:100%;margin-top:4px;color:var(--dim);font-size:9px;text-transform:uppercase;letter-spacing:.1em}
#gh .em-tpl-ph-group:first-child{margin-top:0}
#gh .em-tpl-ph-tag{font-family:var(--mono);font-size:10px;padding:2px 6px;border:1px solid var(--b1);border-radius:3px;background:var(--s1);color:var(--txt);cursor:pointer}
#gh .em-tpl-ph-tag:hover{border-color:var(--acc);color:var(--acc)}

/* Send section */
#gh .em-tpl-send-box{display:flex;flex-direction:column}
#gh .em-tpl-step{padding:12px 14px;border-bottom:1px solid var(--b1)}
#gh .em-tpl-step:last-of-type{border-bottom:0}
#gh .em-tpl-step-label{display:flex;align-items:center;gap:8px;font-family:var(--mono);font-size:11px;color:var(--txt);margin-bottom:8px}
#gh .em-tpl-step-num{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:var(--acc);color:var(--bg);font-size:10px;font-weight:700;font-family:var(--mono)}
#gh .em-tpl-step-hint{font-size:10px;color:var(--dim);font-weight:400}
#gh .em-tpl-step-hint code{font-family:var(--mono);font-size:10px;color:var(--pur);background:var(--s1);padding:1px 4px;border-radius:2px}
#gh .em-tpl-hint-inline{font-size:10px;color:var(--dim);font-family:var(--mono)}

/* Context pickers + chips */
#gh .em-tpl-ctx-pickers{display:flex;flex-wrap:wrap;gap:12px;align-items:center}
#gh .em-tpl-picker{display:flex;align-items:center;gap:6px}
#gh .em-tpl-picker-label{font-family:var(--mono);font-size:10px;color:var(--dim);min-width:50px}
#gh .em-tpl-picker input{width:170px}
#gh .em-tpl-picker-btn{font-size:10px}
#gh .em-tpl-chips{display:none;flex-wrap:wrap;gap:6px;margin-bottom:8px}
#gh .em-tpl-chip{display:inline-flex;align-items:center;gap:8px;padding:4px 4px 4px 8px;background:var(--s1);border:1px solid var(--acc);border-radius:20px;font-family:var(--mono);font-size:10px}
#gh .em-tpl-chip-icon{color:var(--acc);font-weight:700}
#gh .em-tpl-chip-main{color:var(--txt);font-weight:600}
#gh .em-tpl-chip-sub{color:var(--dim)}
#gh .em-tpl-chip-sub::before{content:"·";margin-right:6px;color:var(--b2)}
#gh .em-tpl-chip-x{background:transparent;border:0;color:var(--dim);cursor:pointer;font-size:14px;line-height:1;padding:0 6px;border-radius:50%}
#gh .em-tpl-chip-x:hover{background:var(--red);color:#fff}

/* Search results */
#gh .em-tpl-search-results{padding:6px 0 0;display:flex;flex-direction:column;gap:2px}
#gh .em-tpl-search-results:empty{display:none}
#gh .em-tpl-res-title{font-family:var(--mono);font-size:9px;color:var(--dim);text-transform:uppercase;letter-spacing:.1em;padding:4px 0 2px}
#gh .em-tpl-res-row{display:grid;grid-template-columns:110px 1fr auto auto auto;gap:10px;align-items:center;padding:6px 8px;border-radius:4px;text-decoration:none;font-family:var(--mono);font-size:10px;border:1px solid transparent}
#gh .em-tpl-res-row:hover{background:var(--s1);border-color:var(--b1)}
#gh .em-tpl-res-key{color:var(--acc);font-weight:600}
#gh .em-tpl-res-val{color:var(--txt);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#gh .em-tpl-res-meta{color:var(--dim);font-size:9px;white-space:nowrap}
#gh .em-tpl-res-empty{color:var(--red);font-family:var(--mono);font-size:10px;padding:4px 0}

/* Recipient modes */
#gh .em-tpl-rmode{padding:8px 10px;border:1px solid var(--b1);border-radius:4px;background:var(--s1);margin-bottom:6px;transition:border-color .15s,opacity .15s}
#gh .em-tpl-rmode:last-of-type{margin-bottom:0}
#gh .em-tpl-rmode:has(input[type=radio]:checked),#gh .em-tpl-rmode.is-active{border-color:var(--acc);background:rgba(34,199,139,.04)}
#gh .em-tpl-rmode-disabled{opacity:.55}
#gh .em-tpl-rmode-head{display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:2px}
#gh .em-tpl-rmode-disabled .em-tpl-rmode-head{cursor:not-allowed}
#gh .em-tpl-rmode-title{font-family:var(--mono);font-size:11px;color:var(--txt);font-weight:600}
#gh .em-tpl-rmode-desc{font-family:var(--mono);font-size:10px;color:var(--dim)}
#gh .em-tpl-rmode-input{margin:6px 0 2px 22px;max-width:320px}
#gh .em-tpl-rmode-resolved{font-family:var(--mono);font-size:11px;color:var(--txt);padding:4px 0 2px 22px}
#gh .em-tpl-rmode-resolved strong{color:var(--grn)}

/* Actions */
#gh .em-tpl-actions{display:flex;gap:8px;padding:12px 14px;background:var(--s1);border-top:1px solid var(--b1)}
#gh .em-tpl-send-btn{min-width:160px}

@media(max-width:768px){
    #gh .em-tpl-ctx-pickers{flex-direction:column;align-items:stretch}
    #gh .em-tpl-picker{width:100%}
    #gh .em-tpl-picker input{flex:1;width:auto}
    #gh .em-tpl-res-row{grid-template-columns:1fr;gap:2px}
    #gh .em-tpl-res-meta{display:none}
}

@media(max-width:768px){#gh .tabs-col{width:48px}#gh .tab-label,#gh .tab-section{display:none}#gh .tab-item{justify-content:center;padding:10px 8px}#gh .summary-grid{grid-template-columns:repeat(2,1fr)}#gh .tax-detail{display:none!important}#gh .mp-mapper-layout{flex-direction:column}#gh .mp-col-source,#gh .mp-col-target{width:100%;max-height:150px;border-right:none;border-bottom:1px solid var(--b1)}#gh .mp-col-target{border-left:none;border-top:1px solid var(--b1);border-bottom:none}#gh .em-row{grid-template-columns:1fr 70px;gap:6px}#gh .em-row .em-time,#gh .em-row .em-type{display:none}#gh .em-hint{padding-left:0}}
</style>
