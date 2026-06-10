<style>
#gh{all:initial}#gh *,#gh *::before,#gh *::after{box-sizing:border-box;margin:0;padding:0;font-family:'DM Sans',system-ui,sans-serif}
/* Root: calc the exact remaining space after WP admin bar (32px desktop, 46px mobile).
   Negative margins cancel the padding WP Admin wraps around .wrap / #wpbody-content. */
#gh{--bg:#0c0d10;--s1:#111317;--s2:#16181d;--s3:#1c1f26;--b1:#232630;--b2:#2e3240;--acc:#3d7fff;--grn:#22c78b;--red:#e85d5d;--amb:#e8a824;--pur:#9b72f5;--txt:#d8dce8;--dim:#5f6480;--mut:#2a2d3a;--acc-10:rgba(61,127,255,.1);--acc-15:rgba(61,127,255,.15);--acc-30:rgba(61,127,255,.3);--grn-10:rgba(34,199,139,.1);--grn-15:rgba(34,199,139,.15);--grn-30:rgba(34,199,139,.3);--red-10:rgba(232,93,93,.1);--red-15:rgba(232,93,93,.15);--red-30:rgba(232,93,93,.3);--amb-15:rgba(232,168,36,.15);--amb-30:rgba(232,168,36,.3);--pur-15:rgba(155,114,245,.15);--pur-30:rgba(155,114,245,.3);--mono:'JetBrains Mono','Courier New',monospace;--sans:'DM Sans',system-ui,sans-serif;display:flex;flex-direction:column;height:calc(100vh - 32px);background:var(--bg);color:var(--txt);font-size:13px;margin:-10px -20px -20px -20px;overflow:hidden;box-sizing:border-box}
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
#gh .content{flex:1;min-width:0;display:flex;flex-direction:column;overflow:hidden}
#gh .panel{display:none;flex-direction:column;flex:1;width:100%;min-width:0;min-height:0;overflow:hidden}
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
#gh .ie-var-cbcol{width:28px;padding:4px 6px;text-align:center}
#gh .ie-var-cbcol input[type="checkbox"]{accent-color:var(--acc);cursor:pointer;margin:0}
#gh .ie-var-row-sel{background:var(--acc-10)}
#gh .ie-var-row-sel:hover{background:var(--acc-15)}
#gh .ie-var-bulk{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 10px;margin-bottom:8px;background:var(--s2);border:1px solid var(--acc-30);border-radius:4px}
#gh .ie-var-bulk-count{font-family:var(--mono);font-size:10px;color:var(--acc);text-transform:uppercase;letter-spacing:.08em;flex-shrink:0}

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

/* ── Workflow v2 panel — scroll, spacing, structure ─────────────
   .panel parent has overflow:hidden so the long workflow content
   (config + preview + pipeline + run) was clipped below the fold.
   Make this panel scroll its own body and add some padding so it
   doesn't sit flush against the sidebar edge. */
#gh #panel-workflow{overflow-y:auto;padding:16px 20px;gap:0}
#gh #panel-workflow .panel-header{flex-shrink:0}
#gh #panel-workflow .panel-section{flex-shrink:0}
#gh #panel-workflow .panel-section + .panel-section{margin-top:16px}
/* The preview table can blow horizontally on narrow widths — let it
   scroll inside its wrapper instead of pushing the page. */
#gh #panel-workflow #wf-preview-table-wrap{overflow-x:auto}

/* ── Global ajax-in-flight indicator ────────────────────────────
   2px indeterminate bar at the top of #gh, toggled by body.gh-loading
   from the GH.ajax wrapper. Visible whenever at least one GH.ajax()
   call is in flight; hidden the moment the counter returns to zero. */
#gh{position:relative}
#gh #gh-progress{position:absolute;top:0;left:0;right:0;height:2px;pointer-events:none;z-index:60;opacity:0;transition:opacity .15s;overflow:hidden}
body.gh-loading #gh #gh-progress{opacity:1}
#gh #gh-progress::before{content:'';display:block;height:100%;width:30%;background:linear-gradient(90deg,transparent,var(--acc) 50%,transparent);animation:gh-prog 1.1s linear infinite;will-change:transform}
@keyframes gh-prog{0%{transform:translateX(-100%)}100%{transform:translateX(360%)}}

@media(max-width:768px){
    #gh .em-tpl-ctx-pickers{flex-direction:column;align-items:stretch}
    #gh .em-tpl-picker{width:100%}
    #gh .em-tpl-picker input{flex:1;width:auto}
    #gh .em-tpl-res-row{grid-template-columns:1fr;gap:2px}
    #gh .em-tpl-res-meta{display:none}
}

/* Template editor container — explicit full-width flex column so the
   preview pane has a dependable stretch context. JS toggles the inline
   `display` style between 'none' and 'flex' to switch list/editor views. */
#gh .em-tpl-editor-view{flex:1 1 auto;width:100%;min-width:0;flex-direction:column;overflow:hidden}

/* Two-column editor + live preview */
#gh .em-tpl-editor-body{flex:1 1 auto;width:100%;min-width:0;display:flex;min-height:0;position:relative}
#gh .em-tpl-editor-left{flex:1 1 0;min-width:0;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px}
#gh .em-tpl-editor-preview{width:480px;max-width:50%;flex-shrink:0;border-left:1px solid var(--b1);background:var(--s1);display:flex;flex-direction:column;min-height:0;transition:width .2s}
#gh .em-tpl-editor-preview.is-hidden{width:0;border-left:0;overflow:hidden}
#gh .em-tpl-preview-head{display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid var(--b1);background:var(--s2);flex-shrink:0}
#gh .em-tpl-preview-title{font-family:var(--mono);font-size:11px;color:var(--acc);text-transform:uppercase;letter-spacing:.08em;font-weight:600}
#gh .em-tpl-preview-state{font-family:var(--mono);font-size:10px;color:var(--dim);padding:2px 8px;border-radius:10px;background:var(--s1)}
#gh .em-tpl-preview-state-ok{color:var(--grn);background:rgba(34,199,139,.1)}
#gh .em-tpl-preview-state-pending{color:var(--amb);background:rgba(232,168,36,.1)}
#gh .em-tpl-preview-state-err{color:var(--red);background:rgba(232,93,93,.1)}
#gh .em-tpl-preview-state-idle{color:var(--dim)}
#gh .em-tpl-preview-modes{display:inline-flex;border:1px solid var(--b1);border-radius:4px;overflow:hidden;margin-left:auto}
#gh .em-tpl-preview-mode{appearance:none;background:transparent;border:0;padding:3px 8px;color:var(--dim);font-size:12px;cursor:pointer;border-right:1px solid var(--b1)}
#gh .em-tpl-preview-mode:last-child{border-right:0}
#gh .em-tpl-preview-mode:hover{color:var(--txt);background:var(--s1)}
#gh .em-tpl-preview-mode.is-active{color:var(--acc);background:var(--s1)}
#gh .em-tpl-preview-collapse{appearance:none;background:transparent;border:0;color:var(--dim);font-size:16px;line-height:1;cursor:pointer;padding:2px 6px;border-radius:4px}
#gh .em-tpl-preview-collapse:hover{color:var(--red);background:var(--s1)}
#gh .em-tpl-preview-subjectbar{display:flex;align-items:baseline;gap:10px;padding:8px 14px;border-bottom:1px solid var(--b1);background:var(--s2);flex-shrink:0}
#gh .em-tpl-preview-subj-label{font-family:var(--mono);font-size:9px;color:var(--dim);text-transform:uppercase;letter-spacing:.08em}
#gh .em-tpl-preview-subj{color:var(--txt);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0}
#gh .em-tpl-preview-frame-wrap{flex:1;min-height:0;overflow:auto;display:flex;justify-content:center;padding:14px;background:var(--s1)}
#gh .em-tpl-preview-frame-wrap.is-mobile{padding:20px 14px}
#gh .em-tpl-preview-frame{width:100%;height:100%;min-height:400px;border:0;background:#fff;border-radius:4px;box-shadow:0 1px 4px rgba(0,0,0,.35)}
#gh .em-tpl-preview-frame-wrap.is-mobile .em-tpl-preview-frame{width:375px;max-width:100%;height:640px;min-height:0;flex-shrink:0}
#gh .em-tpl-preview-show{position:absolute;top:14px;right:0;background:var(--s2);border:1px solid var(--b1);border-right:0;border-radius:6px 0 0 6px;color:var(--acc);padding:8px 6px;cursor:pointer;font-size:14px;writing-mode:vertical-rl;text-orientation:mixed}
#gh .em-tpl-preview-show:hover{background:var(--s3);color:var(--txt)}

@media(max-width:960px){
    #gh .em-tpl-editor-body{flex-direction:column}
    #gh .em-tpl-editor-preview{width:100%!important;max-width:100%;max-height:50%;border-left:0;border-top:1px solid var(--b1)}
    #gh .em-tpl-preview-frame-wrap.is-mobile .em-tpl-preview-frame{width:100%;max-width:375px}
    #gh .em-tpl-preview-show{top:auto;bottom:14px;border-radius:6px 6px 0 0;border-right:1px solid var(--b1);border-bottom:0;writing-mode:horizontal-tb}
}

/* ═══ JOBS — card UX ═════════════════════════════════════════ */

/* View switch */
#gh .gh-job-viewswitch{display:inline-flex;border:1px solid var(--b1);border-radius:6px;overflow:hidden}
#gh .gh-job-viewswitch-btn{appearance:none;background:transparent;border:0;padding:6px 14px;font-family:var(--mono);font-size:11px;color:var(--dim);cursor:pointer;border-right:1px solid var(--b1)}
#gh .gh-job-viewswitch-btn:last-child{border-right:0}
#gh .gh-job-viewswitch-btn:hover{background:var(--s2);color:var(--txt)}
#gh .gh-job-viewswitch-btn.is-active{background:var(--s2);color:var(--acc);font-weight:600}

/* Filter chips */
#gh .gh-job-chips{display:flex;flex-wrap:wrap;gap:8px;padding:12px 18px 0}
#gh .gh-job-chip{display:inline-flex;align-items:center;gap:8px;padding:4px 10px;background:var(--s2);border:1px solid var(--b1);border-radius:20px;font-family:var(--mono);font-size:11px;color:var(--dim);cursor:pointer}
#gh .gh-job-chip:hover{border-color:var(--b2);color:var(--txt)}
#gh .gh-job-chip.is-active{background:var(--s3);border-color:var(--acc);color:var(--acc)}
#gh .gh-job-chip-count{background:var(--s1);border-radius:10px;padding:1px 8px;font-size:10px;min-width:18px;text-align:center}
#gh .gh-job-chip.is-active .gh-job-chip-count{background:var(--acc);color:var(--bg);font-weight:700}
#gh .gh-job-chip-warn:not(.is-active){color:var(--red);border-color:rgba(232,93,93,.3)}
#gh .gh-job-chip-warn .gh-job-chip-count{color:var(--red)}
#gh .gh-job-chip-warn.is-active{border-color:var(--red);color:var(--red);background:rgba(232,93,93,.08)}
#gh .gh-job-chip-warn.is-active .gh-job-chip-count{background:var(--red);color:#fff}

/* List / log view wrappers — fill remaining panel height so the inner
   list scrolls independently and #jobs-editor (when open) never pushes
   content off-screen. */
#gh #jobs-list-view,#gh #jobs-log-view{flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden}

/* Editor opens inline under the list. Cap its height so the list above
   stays visible; the editor itself scrolls internally if content is tall. */
#gh #jobs-editor{flex-shrink:0;max-height:50%;overflow-y:auto}

/* List wrapper — the actual scroll container for job cards. */
#gh .gh-job-list{flex:1;min-height:0;padding:14px 18px 24px;display:flex;flex-direction:column;gap:16px;overflow-y:auto}

/* Group */
#gh .gh-job-group{display:flex;flex-direction:column;gap:8px}
#gh .gh-job-group-head{display:flex;align-items:center;gap:8px;padding:4px 2px;border-bottom:1px dashed var(--b1)}
#gh .gh-job-group-name{font-family:var(--mono);font-size:10px;color:var(--dim);text-transform:uppercase;letter-spacing:.12em}
#gh .gh-job-group-count{font-family:var(--mono);font-size:10px;color:var(--dim);background:var(--s2);padding:1px 6px;border-radius:10px}
#gh .gh-job-group-body{display:flex;flex-direction:column;gap:8px}

/* Card */
#gh .gh-job-card{display:flex;align-items:stretch;background:var(--s2);border:1px solid var(--b1);border-radius:8px;overflow:hidden;transition:border-color .15s,transform .15s}
#gh .gh-job-card:hover{border-color:var(--acc)}
#gh .gh-job-card-stripe{width:4px;flex-shrink:0;background:var(--b2)}
#gh .gh-job-color-grn .gh-job-card-stripe{background:var(--grn)}
#gh .gh-job-color-blu .gh-job-card-stripe{background:var(--blu,#4aa3ff)}
#gh .gh-job-color-amb .gh-job-card-stripe{background:var(--amb)}
#gh .gh-job-color-pur .gh-job-card-stripe{background:var(--pur)}
#gh .gh-job-color-cya .gh-job-card-stripe{background:var(--cya,#3dd1d7)}
#gh .gh-job-color-red .gh-job-card-stripe{background:var(--red)}
#gh .gh-job-color-dim .gh-job-card-stripe{background:var(--b2)}

#gh .gh-job-card-body{flex:1;padding:12px 14px;display:flex;flex-direction:column;gap:6px;min-width:0}
#gh .gh-job-card-row1{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
#gh .gh-job-title{font-size:14px;font-weight:600;color:var(--txt);overflow:hidden;text-overflow:ellipsis}
#gh .gh-job-kind-tag{font-family:var(--mono);font-size:9px;padding:2px 6px;border-radius:3px;background:var(--s1);border:1px solid var(--b1);color:var(--dim);text-transform:uppercase;letter-spacing:.05em;flex-shrink:0}
#gh .gh-job-color-grn .gh-job-kind-tag{color:var(--grn);border-color:rgba(34,199,139,.3)}
#gh .gh-job-color-blu .gh-job-kind-tag{color:var(--blu,#4aa3ff);border-color:rgba(74,163,255,.3)}
#gh .gh-job-color-amb .gh-job-kind-tag{color:var(--amb);border-color:rgba(232,168,36,.3)}
#gh .gh-job-color-pur .gh-job-kind-tag{color:var(--pur);border-color:rgba(155,114,245,.3)}
#gh .gh-job-color-cya .gh-job-kind-tag{color:var(--cya,#3dd1d7);border-color:rgba(61,209,215,.3)}
#gh .gh-job-color-red .gh-job-kind-tag{color:var(--red);border-color:rgba(232,93,93,.3)}

#gh .gh-job-state-pill{font-family:var(--mono);font-size:9px;padding:2px 8px;border-radius:12px;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin-left:auto}
#gh .gh-job-state-on{background:rgba(34,199,139,.12);color:var(--grn)}
#gh .gh-job-state-off{background:rgba(95,100,128,.15);color:var(--dim)}

#gh .gh-job-card-row2{display:flex;flex-wrap:wrap;gap:14px 18px;font-family:var(--mono);font-size:10px}
#gh .gh-job-meta{display:flex;flex-direction:column;gap:2px;min-width:0}
#gh .gh-job-meta-k{font-size:9px;color:var(--dim);text-transform:uppercase;letter-spacing:.08em}
#gh .gh-job-meta-v{color:var(--txt);display:flex;align-items:center;gap:6px;white-space:nowrap}
#gh .gh-job-abs{color:var(--dim);font-size:9px}

#gh .gh-dot{display:inline-block;width:7px;height:7px;border-radius:50%;flex-shrink:0}
#gh .gh-dot-ok{background:var(--grn)}
#gh .gh-dot-err{background:var(--red)}
#gh .gh-dot-warn{background:var(--amb)}
#gh .gh-dot-idle{background:var(--b2)}

/* Card actions */
#gh .gh-job-card-actions{display:flex;flex-direction:column;gap:4px;padding:10px;border-left:1px solid var(--b1);background:var(--s1);flex-shrink:0;min-width:90px}
#gh .gh-job-act{appearance:none;background:transparent;border:1px solid transparent;color:var(--txt);font-family:var(--mono);font-size:10px;padding:4px 10px;border-radius:4px;cursor:pointer;text-align:left}
#gh .gh-job-act:hover{background:var(--s2);border-color:var(--b1)}
#gh .gh-job-act-run{color:var(--acc);font-weight:600}
#gh .gh-job-act-run:hover{background:rgba(34,199,139,.08);border-color:var(--acc)}
#gh .gh-job-act-danger{color:var(--red)}
#gh .gh-job-act-danger:hover{background:rgba(232,93,93,.08);border-color:var(--red)}

/* ═══ JOBS — run log timeline ════════════════════════════════ */
#gh .gh-joblog{flex:1;min-height:0;padding:14px 18px 24px;overflow-y:auto}
#gh .gh-joblog-list{display:flex;flex-direction:column;gap:2px}
#gh .gh-joblog-row{background:var(--s2);border:1px solid var(--b1);border-radius:5px;overflow:hidden}
#gh .gh-joblog-row[data-status="error"],#gh .gh-joblog-row[data-status="crashed"]{border-color:rgba(232,93,93,.3)}
#gh .gh-joblog-row-expandable>summary{cursor:pointer;list-style:none}
#gh .gh-joblog-row-expandable>summary::-webkit-details-marker{display:none}
#gh .gh-joblog-row-expandable>summary::before{content:"\25B8";color:var(--dim);margin-right:4px;transition:transform .15s;display:inline-block;font-size:9px}
#gh .gh-joblog-row-expandable[open]>summary::before{transform:rotate(90deg)}
#gh .gh-joblog-head{display:grid;grid-template-columns:10px 70px 1fr auto auto auto auto;align-items:center;gap:10px;padding:8px 12px;font-family:var(--mono);font-size:10px}
#gh .gh-joblog-row-expandable:hover>.gh-joblog-head,#gh .gh-joblog-row-expandable:hover>summary{background:var(--s1)}
#gh .gh-joblog-time{color:var(--dim)}
#gh .gh-joblog-label{color:var(--txt);font-family:var(--sans,inherit);font-size:11px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
#gh .gh-joblog-kind{font-size:9px;padding:1px 6px;border-radius:3px;background:var(--s1);border:1px solid var(--b1);color:var(--dim);text-transform:lowercase}
#gh .gh-joblog-status{font-size:9px;padding:1px 7px;border-radius:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:600}
#gh .gh-joblog-status-ok{background:rgba(34,199,139,.12);color:var(--grn)}
#gh .gh-joblog-status-err{background:rgba(232,93,93,.12);color:var(--red)}
#gh .gh-joblog-status-warn{background:rgba(232,168,36,.12);color:var(--amb)}
#gh .gh-joblog-status-idle{background:var(--s1);color:var(--dim)}
#gh .gh-joblog-dur{color:var(--dim);min-width:42px;text-align:right}
#gh .gh-joblog-trigger{color:var(--dim);font-size:9px}
#gh .gh-joblog-body{padding:0 12px 12px 22px;border-top:1px solid var(--b1);background:var(--s1)}
#gh .gh-joblog-err,#gh .gh-joblog-sum{margin:10px 0 0;font-family:var(--mono);font-size:10px;white-space:pre-wrap;word-break:break-word;padding:8px 10px;border-radius:4px;background:var(--s3,var(--s2))}
#gh .gh-joblog-err{color:var(--red);border-left:2px solid var(--red)}
#gh .gh-joblog-sum{color:var(--dim);border-left:2px solid var(--b2)}

@media(max-width:768px){
    #gh .gh-job-card{flex-direction:column}
    #gh .gh-job-card-stripe{width:100%;height:4px}
    #gh .gh-job-card-actions{flex-direction:row;flex-wrap:wrap;border-left:0;border-top:1px solid var(--b1);min-width:0}
    #gh .gh-job-act{flex:1;text-align:center;padding:6px 4px}
    #gh .gh-joblog-head{grid-template-columns:10px 1fr auto;gap:8px}
    #gh .gh-joblog-kind,#gh .gh-joblog-dur,#gh .gh-joblog-trigger,#gh .gh-joblog-time{display:none}
}

/* Bulk bar: "Esegui come job" toggle highlights when ticked. */
#gh #bulk-as-job-wrap:has(input:checked){border-color:var(--acc);color:var(--acc);background:rgba(61,127,255,.06)}

/* Force re-import accordion inside feed panels */
#gh .gh-reimport-box{margin:10px 20px;border:1px solid var(--b1);border-radius:6px;background:var(--s2);overflow:hidden}
#gh .gh-reimport-head{display:flex;align-items:center;gap:8px;padding:8px 12px;cursor:pointer;list-style:none;user-select:none}
#gh .gh-reimport-head::-webkit-details-marker{display:none}
#gh .gh-reimport-head:hover{background:var(--s1)}
#gh .gh-reimport-caret{display:inline-block;color:var(--acc);font-size:10px;transition:transform .15s}
#gh .gh-reimport-box[open] .gh-reimport-caret{transform:rotate(90deg)}
#gh .gh-reimport-title{font-family:var(--mono);font-size:11px;color:var(--amb);text-transform:uppercase;letter-spacing:.08em;font-weight:600}
#gh .gh-reimport-hint{font-family:var(--mono);font-size:10px;color:var(--dim)}
#gh .gh-reimport-body{padding:10px 12px 12px;border-top:1px solid var(--b1);display:flex;flex-direction:column;gap:8px;background:var(--s1)}
#gh .gh-reimport-skus{min-height:60px;font-family:var(--mono);font-size:11px;resize:vertical}
#gh .gh-reimport-opts{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
#gh .gh-reimport-opts label{font-family:var(--mono);font-size:10px;color:var(--dim);display:inline-flex;align-items:center;gap:4px}
#gh .gh-reimport-status{font-family:var(--mono);font-size:10px;color:var(--dim);min-height:12px}
#gh .gh-reimport-status a{color:var(--acc);text-decoration:none}
#gh .gh-reimport-status a:hover{text-decoration:underline}

/* ═══ EMAIL MULTI-LAYER (rpem-*) ══════════════════════════════════════════ */
#gh .rpem-brand-form{padding:12px 16px;overflow-y:auto;flex:1}
#gh .rpem-brand-section{margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--b1)}
#gh .rpem-brand-section h3{font-family:var(--mono);font-size:11px;letter-spacing:1px;text-transform:uppercase;color:var(--dim);margin:0 0 12px}
#gh .rpem-brand-key{font-family:var(--mono);font-size:10px;color:var(--dim);background:var(--s3);padding:2px 6px;border-radius:3px;margin-left:8px}
#gh .rpem-req{color:var(--amb);margin-left:4px}
#gh .rpem-color{width:60px;flex:0 0 60px;padding:2px;height:30px;cursor:pointer}
#gh .rpem-color-hex{flex:0 0 110px;font-family:var(--mono);font-size:12px}
#gh .rpem-tpl-list{padding:12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;overflow-y:auto}
#gh .rpem-tpl-card{background:var(--s2);border:1px solid var(--b1);border-radius:6px;padding:12px;cursor:pointer;transition:border-color .15s}
#gh .rpem-tpl-card:hover{border-color:var(--acc)}
#gh .rpem-tpl-card-name{font-family:var(--sans);font-size:14px;font-weight:600;color:var(--txt);margin-bottom:4px}
#gh .rpem-tpl-card-desc{font-family:var(--sans);font-size:12px;color:var(--dim);margin-bottom:8px;min-height:16px}
#gh .rpem-tpl-card-meta{display:flex;gap:8px;font-family:var(--mono);font-size:10px;color:var(--dim)}
#gh .rpem-tpl-editor{display:flex;flex-direction:column;flex:1;overflow:hidden}
#gh .rpem-tpl-editor-body{display:flex;flex:1;overflow:hidden}
#gh .rpem-tpl-editor-left{flex:1;display:flex;flex-direction:column;padding:12px;gap:8px;overflow:hidden}
#gh .rpem-code{font-family:var(--mono);font-size:12px;min-height:250px;flex:1;white-space:pre;line-height:1.5}
#gh .rpem-tpl-ph-panel{width:320px;flex:0 0 320px;border-left:1px solid var(--b1);background:var(--s1);display:flex;flex-direction:column;overflow:hidden}
#gh .rpem-tpl-ph-head{display:flex;gap:0;border-bottom:1px solid var(--b1);background:var(--s2);flex-shrink:0}
#gh .rpem-tpl-tab{flex:1;padding:10px 12px;background:transparent;border:0;border-bottom:2px solid transparent;color:var(--dim);font-family:var(--mono);font-size:10px;letter-spacing:1px;text-transform:uppercase;cursor:pointer;transition:color .15s,border-color .15s}
#gh .rpem-tpl-tab:hover{color:var(--txt)}
#gh .rpem-tpl-tab.is-active{color:var(--acc);border-bottom-color:var(--acc)}
#gh .rpem-tpl-ph-body{flex:1;overflow-y:auto}
#gh .rpem-tpl-preview-iframe{flex:1;width:100%;border:0;background:#fff;min-height:0}
#gh .rpem-tpl-ph-body,#gh .rpem-ph-group{padding:8px 12px}
#gh .rpem-ph-head{font-family:var(--mono);font-size:10px;font-weight:600;letter-spacing:1px;margin-bottom:6px;display:flex;align-items:center;gap:6px}
#gh .rpem-ph-head span{background:var(--s3);padding:1px 5px;border-radius:3px;font-size:9px}
#gh .rpem-ph-list{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:12px}
#gh .rpem-ph-list code{font-family:var(--mono);font-size:10px;background:var(--s3);color:var(--txt);padding:2px 5px;border-radius:3px}
#gh .rpem-ns-brand .rpem-ph-head{color:var(--pur)}
#gh .rpem-ns-campaign .rpem-ph-head{color:var(--acc)}
#gh .rpem-ns-product .rpem-ph-head{color:var(--grn)}
#gh .rpem-ns-recipient .rpem-ph-head{color:var(--amb)}
#gh .rpem-ns-order .rpem-ph-head{color:var(--amb)}
#gh .rpem-ns-meta .rpem-ph-head{color:var(--dim)}
#gh .rpem-ns-unknown .rpem-ph-head{color:var(--red)}
#gh .rpem-ns-unknown code{border:1px solid var(--red)}
#gh .rpem-trx-list{padding:12px;display:flex;flex-direction:column;gap:12px;overflow-y:auto}
#gh .rpem-trx-card{background:var(--s2);border:1px solid var(--b1);border-radius:6px;padding:12px;display:flex;flex-direction:column;gap:10px}
#gh .rpem-trx-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
#gh .rpem-trx-title{font-family:var(--sans);font-size:14px;font-weight:600;color:var(--txt)}
#gh .rpem-trx-desc{font-family:var(--sans);font-size:12px;color:var(--dim);margin-top:4px}
#gh .rpem-trx-hook{font-family:var(--mono);font-size:10px;color:var(--dim);margin-top:4px}
#gh .rpem-trx-hook code{background:var(--s3);padding:1px 5px;border-radius:3px;color:var(--txt)}
#gh .rpem-trx-toggle{display:flex;align-items:center;gap:6px;font-family:var(--mono);font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--dim);cursor:pointer;white-space:nowrap}
#gh .rpem-trx-toggle input{margin:0}
#gh .rpem-camp-list{padding:12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px;overflow-y:auto}
#gh .rpem-camp-card{background:var(--s2);border:1px solid var(--b1);border-radius:6px;padding:12px;cursor:pointer;transition:border-color .15s}
#gh .rpem-camp-card:hover{border-color:var(--acc)}
#gh .rpem-camp-card-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
#gh .rpem-camp-name{font-family:var(--sans);font-size:14px;font-weight:600;color:var(--txt)}
#gh .rpem-camp-card-subj{font-family:var(--sans);font-size:12px;color:var(--dim);margin-bottom:8px}
#gh .rpem-camp-card-meta{display:flex;flex-wrap:wrap;gap:8px;font-family:var(--mono);font-size:10px;color:var(--dim)}
/* Status chip legacy — refitted per matchare .gh-status (stesso visual
   language: bg muted + text colorato + border muted, invece dei fondi
   solidi originali che urlavano troppo). Non tocca il markup. */
#gh .em-st{font-family:var(--mono);font-size:10px;padding:2px 7px;border-radius:3px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;border:1px solid var(--b1);background:var(--s3);color:var(--dim)}
#gh .em-st-sent{background:var(--grn-15);color:var(--grn);border-color:var(--grn-30)}
#gh .em-st-failed{background:var(--red-15);color:var(--red);border-color:var(--red-30)}
#gh .em-st-scheduled{background:var(--acc-15);color:var(--acc);border-color:var(--acc-30)}
#gh .em-st-sending{background:var(--amb-15);color:var(--amb);border-color:var(--amb-30)}
#gh .em-st-draft{background:var(--s3);color:var(--dim);border-color:var(--b1)}
#gh .rpem-wizard{display:flex;flex-direction:column;flex:1;overflow:hidden}
#gh .rpem-wizard-body{display:flex;flex:1;overflow:hidden}
#gh .rpem-wizard-left{flex:1;padding:12px;overflow-y:auto;display:flex;flex-direction:column;gap:12px}
#gh .rpem-step{background:var(--s2);border:1px solid var(--b1);border-radius:6px;padding:12px}
#gh .rpem-step-head{font-family:var(--mono);font-size:11px;letter-spacing:1px;text-transform:uppercase;color:var(--dim);margin-bottom:10px;display:flex;align-items:center;gap:8px}
#gh .rpem-step-num{background:var(--acc);color:#fff;width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-family:var(--mono);font-size:11px;font-weight:600}
#gh .rpem-payload-form{display:flex;flex-direction:column;gap:6px}
#gh .rpem-product-slots{display:flex;flex-direction:column;gap:6px;margin-bottom:8px}
#gh .rpem-product-slot{display:flex;align-items:center;gap:8px;background:var(--s3);border:1px solid var(--b1);border-radius:4px;padding:6px 8px}
#gh .rpem-slot-num{font-family:var(--mono);font-size:10px;color:var(--grn);background:var(--s1);padding:2px 6px;border-radius:3px;flex:0 0 auto}
#gh .rpem-product-thumb{width:40px;height:40px;flex:0 0 40px;object-fit:cover;background:var(--s1);border-radius:3px}
#gh .rpem-product-meta{flex:1;min-width:0}
#gh .rpem-product-name{font-family:var(--sans);font-size:12px;color:var(--txt);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#gh .rpem-product-sub{font-family:var(--mono);font-size:10px;color:var(--dim)}
#gh .rpem-slot-actions{display:flex;gap:4px}
#gh .rpem-product-search{display:flex;gap:6px;margin-bottom:8px}
#gh .rpem-product-search .cfg-input{flex:1}
#gh .rpem-product-results{max-height:240px;overflow-y:auto;display:flex;flex-direction:column;gap:4px}
#gh .rpem-product-row{display:flex;align-items:center;gap:8px;padding:6px 8px;background:var(--s3);border:1px solid var(--b1);border-radius:4px;cursor:pointer;transition:border-color .1s}
#gh .rpem-product-row:hover{border-color:var(--acc)}
#gh .rpem-validation{display:flex;flex-direction:column;gap:8px;margin-bottom:8px}
#gh .rpem-v-errors,#gh .rpem-v-warns{background:var(--s3);border:1px solid var(--b1);border-radius:4px;padding:8px}
#gh .rpem-v-errors{border-color:var(--red)}
#gh .rpem-v-warns{border-color:var(--amb)}
#gh .rpem-v-head{font-family:var(--mono);font-size:10px;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px}
#gh .rpem-v-errors .rpem-v-head{color:var(--red)}
#gh .rpem-v-warns .rpem-v-head{color:var(--amb)}
#gh .rpem-v-row{display:flex;gap:8px;font-family:var(--mono);font-size:11px;padding:3px 0;align-items:flex-start}
#gh .rpem-v-row code{background:var(--s1);padding:1px 5px;border-radius:3px;color:var(--txt);flex:0 0 auto;white-space:nowrap}
#gh .rpem-v-row span{color:var(--dim);line-height:1.4}
#gh .rpem-v-ok{color:var(--grn);font-family:var(--mono);font-size:12px;padding:8px;background:var(--s3);border:1px solid var(--grn);border-radius:4px}
#gh .rpem-wizard-preview{width:380px;flex:0 0 380px;border-left:1px solid var(--b1);background:var(--s1);display:flex;flex-direction:column}
#gh .rpem-preview-head{display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid var(--b1);font-family:var(--mono);font-size:10px;color:var(--dim);text-transform:uppercase;letter-spacing:1px}
#gh .rpem-preview-subject{font-family:var(--sans);font-size:11px;color:var(--txt);text-transform:none;letter-spacing:0;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#gh .rpem-preview-frame{flex:1;border:none;background:#fff}
#gh .rpem-seed-result{padding:12px 16px;font-family:var(--mono);font-size:12px;color:var(--txt)}
#gh .rpem-seed-ok ul{margin:8px 0 0;padding-left:20px;font-size:11px;color:var(--dim);line-height:1.6}
#gh .rpem-ct-row,#gh .rpem-h-row{display:grid;grid-template-columns:80px 1fr 2fr 70px 110px;gap:8px;padding:6px 12px;border-bottom:1px solid var(--b1);font-family:var(--mono);font-size:11px;align-items:center}
#gh .rpem-ct-row{grid-template-columns:2fr 1fr 80px}
#gh .rpem-ct-email,#gh .rpem-h-to,#gh .rpem-h-subject{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#gh .rpem-h-status{text-transform:uppercase;font-size:9px;letter-spacing:1px}
#gh .rpem-h-sent .rpem-h-status{color:var(--grn)}
#gh .rpem-h-failed .rpem-h-status{color:var(--red)}
#gh .rpem-h-type,#gh .rpem-h-date,#gh .rpem-ct-src{color:var(--dim)}

/* ═══ UNIFIED COMPONENTS ══════════════════════════════════════════════════
   Base classes per nuovi componenti (card, status chip). Le classi legacy
   (.rpem-tpl-card, .gh-job-card, .em-st-*) possono migrare progressivamente. */

/* Card base — sostituisce .rpem-tpl-card / .rpem-camp-card / .rpem-trx-card /
   .gh-job-card che duplicavano tutti la stessa struttura. */
#gh .gh-card{background:var(--s2);border:1px solid var(--b1);border-radius:6px;padding:12px;transition:border-color .15s,transform .15s}
#gh .gh-card:hover{border-color:var(--b2)}
#gh .gh-card--clickable{cursor:pointer}
#gh .gh-card--clickable:hover{border-color:var(--acc)}
#gh .gh-card--compact{padding:8px 10px;border-radius:4px}

/* Status chip — sostituisce .em-st-* / .st-* / .gh-job-chip-*. 5 varianti. */
#gh .gh-status{display:inline-flex;align-items:center;gap:4px;font-family:var(--mono);font-size:10px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;padding:2px 7px;border-radius:3px;border:1px solid transparent;white-space:nowrap}
#gh .gh-status--ok{background:var(--grn-15);color:var(--grn);border-color:var(--grn-30)}
#gh .gh-status--err{background:var(--red-15);color:var(--red);border-color:var(--red-30)}
#gh .gh-status--warn{background:var(--amb-15);color:var(--amb);border-color:var(--amb-30)}
#gh .gh-status--info{background:var(--acc-15);color:var(--acc);border-color:var(--acc-30)}
#gh .gh-status--dim{background:var(--s3);color:var(--dim);border-color:var(--b1)}

/* Toast sticky — errore persistente con bottone di dismiss. Usato da
   GH.toast(msg, 'err', 0) per messaggi di lunga durata (import falliti ecc). */
#gh .toast.toast-sticky{padding-right:36px;position:relative;animation:none;border-width:1px}
#gh .toast-x{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:transparent;border:0;color:inherit;font-size:18px;line-height:1;cursor:pointer;padding:2px 6px;opacity:.7}
#gh .toast-x:hover{opacity:1}

/* Modal confirm — sostituisce confirm() nativo. Usato da GH.confirm(msg, opts). */
#gh .gh-modal-overlay{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.65);display:flex;align-items:center;justify-content:center;padding:20px;animation:gh-mfade .12s ease}
#gh .gh-modal{background:var(--s1);border:1px solid var(--b1);border-radius:8px;max-width:440px;width:100%;padding:20px;display:flex;flex-direction:column;gap:12px;box-shadow:0 20px 60px rgba(0,0,0,.5);animation:gh-mslide .15s ease}
#gh .gh-modal-title{font-family:var(--mono);font-size:10px;letter-spacing:.15em;color:var(--dim);text-transform:uppercase}
#gh .gh-modal-body{font-family:var(--sans);font-size:13px;color:var(--txt);line-height:1.55}
#gh .gh-modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:4px}
@keyframes gh-mfade{from{opacity:0}to{opacity:1}}
@keyframes gh-mslide{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* ── Term Picker — multi-select ricercabile (GH.termPicker) ─────────────────
   Sostituisce i <select multiple> nativi per brand/categorie/tag.
   Control compatto con chips + dropdown con search; .up = drop-up quando
   manca spazio sotto (es. bulk action bar in fondo al pannello). */
#gh .gh-tp{position:relative;min-width:240px;max-width:380px}
#gh .gh-tp-control{display:flex;flex-wrap:wrap;gap:4px;align-items:center;min-height:30px;padding:3px 24px 3px 6px;background:var(--s3);border:1px solid var(--b1);border-radius:4px;cursor:pointer;position:relative}
#gh .gh-tp-control:hover,#gh .gh-tp.open .gh-tp-control,#gh .gh-tp-control:focus{border-color:var(--acc);outline:none}
#gh .gh-tp-caret{position:absolute;right:8px;top:50%;transform:translateY(-50%);color:var(--dim);font-size:9px;pointer-events:none}
#gh .gh-tp-placeholder{color:var(--dim);font-size:11px;padding:2px 2px}
#gh .gh-tp-chip{display:inline-flex;align-items:center;gap:3px;background:var(--acc-15);border:1px solid var(--acc-30);color:var(--txt);font-size:11px;border-radius:3px;padding:1px 2px 1px 6px;max-width:140px}
#gh .gh-tp-chip-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#gh .gh-tp-chip-x{cursor:pointer;color:var(--dim);font-weight:600;padding:0 4px;border-radius:2px}
#gh .gh-tp-chip-x:hover{color:var(--red);background:var(--red-10)}
#gh .gh-tp-more{font-size:10px;color:var(--dim);font-family:var(--mono);padding:0 2px}
#gh .gh-tp-drop{display:none;position:absolute;top:calc(100% + 4px);left:0;z-index:90;width:100%;min-width:280px;background:var(--s2);border:1px solid var(--b2);border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.45);padding:8px}
#gh .gh-tp.up .gh-tp-drop{top:auto;bottom:calc(100% + 4px)}
#gh .gh-tp.open .gh-tp-drop{display:block}
#gh .gh-tp-search{width:100%;margin-bottom:6px;box-sizing:border-box}
#gh .gh-tp-list{max-height:260px;overflow-y:auto}
#gh .gh-tp-opt{display:flex;align-items:center;gap:8px;padding:5px 6px;border-radius:4px;cursor:pointer;font-size:12px;color:var(--txt)}
#gh .gh-tp-opt:hover,#gh .gh-tp-opt.hi{background:var(--s3)}
#gh .gh-tp-box{width:14px;height:14px;flex-shrink:0;border:1px solid var(--b2);border-radius:3px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;line-height:1;color:transparent}
#gh .gh-tp-opt.sel .gh-tp-box{background:var(--acc);border-color:var(--acc);color:#fff}
#gh .gh-tp-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#gh .gh-tp-ind{width:12px;flex-shrink:0}
#gh .gh-tp-foot{display:flex;align-items:center;justify-content:space-between;padding-top:6px;border-top:1px solid var(--b1);margin-top:6px}
#gh .gh-tp-count{font-size:10px;color:var(--dim);font-family:var(--mono)}
#gh .gh-tp-empty{padding:10px;color:var(--dim);font-size:11px;text-align:center}

@media(max-width:768px){
    /* ── Sidebar tabs: shrink to icon-only ─────────────────────────── */
    #gh .tabs-col{width:48px}
    #gh .tab-label,#gh .tab-section{display:none}
    #gh .tab-item{justify-content:center;padding:10px 8px}

    /* ── Toolbars: compact padding, always wrap ───────────────────── */
    #gh .toolbar{padding:8px 12px;gap:8px}
    #gh .filter-sep{display:none}

    /* ── Tap targets: bump .btn a ~36px su mobile. Non tocca la dimensione
          del font (text piu grande non serve, serve l'area clickabile). */
    #gh .btn{padding:10px 14px;font-size:11px;min-height:34px}

    /* ── cfg-row: stack label above input, full width ─────────────── */
    /* Usato in: brand form, templates, campaigns, transactional,
       test email, mapper rules. Su mobile tiene tutto in colonna. */
    #gh .cfg-row{flex-direction:column;align-items:stretch;gap:6px}
    #gh .cfg-row .cfg-label{min-width:0;padding:0}
    #gh .cfg-row .cfg-input,#gh .cfg-row .cfg-select{width:100%}
    /* Eccezione: se il cfg-row contiene un datetime-local o input piccolo
       accoppiato a un pulsante, lasciamo wrappare senza forzare column */
    #gh .rpem-product-search{flex-wrap:wrap}

    /* ── Transazionali: head in colonna ───────────────────────────── */
    #gh .rpem-trx-head{flex-direction:column;align-items:stretch;gap:8px}
    #gh .rpem-trx-toggle{align-self:flex-start}

    /* ── Brand form: stack code key sotto l'input ─────────────────── */
    #gh .rpem-brand-key{margin-left:0;margin-top:4px;align-self:flex-start}

    /* ── Template editor: stack HTML sopra e placeholder panel
          sotto. La regola legacy nascondeva il panel; lo manteniamo
          visibile ma inline, cosi l'utente vede cosa sta usando. */
    #gh .rpem-tpl-editor-body{flex-direction:column}
    #gh .rpem-tpl-ph-panel{width:100%;flex:0 0 auto;max-height:40vh;border-left:none;border-top:1px solid var(--b1)}
    #gh .rpem-tpl-editor-left{padding:10px 12px}
    #gh .rpem-code{min-height:180px}
    #gh .rpem-tpl-preview-iframe{min-height:200px}

    /* ── Campaign wizard: nasconde preview (gia fatto) + stack body ── */
    #gh .rpem-wizard-body{flex-direction:column}
    #gh .rpem-wizard-left{flex:0 0 auto;padding:10px 12px}
    #gh .rpem-wizard-preview{display:none}

    /* ── Mapper: column layout ────────────────────────────────────── */
    #gh .mp-mapper-layout{flex-direction:column}
    #gh .mp-col-source,#gh .mp-col-target{width:100%;max-height:150px;border-right:none;border-bottom:1px solid var(--b1)}
    #gh .mp-col-target{border-left:none;border-top:1px solid var(--b1);border-bottom:none}

    /* ── Inline Editor / altre tab con summary-grid ───────────────── */
    #gh .summary-grid{grid-template-columns:repeat(2,1fr)}
    #gh .tax-detail{display:none!important}

    /* ── Email history / contacts row compacted ───────────────────── */
    #gh .em-row{grid-template-columns:1fr 70px;gap:6px}
    #gh .em-row .em-time,#gh .em-row .em-type{display:none}
    #gh .em-hint{padding-left:0}

    /* ── em-list e rpem-h-row: consenti wrap invece di forzare tutto
          su una sola riga (molti pannelli lo usavano: history, contacts). */
    #gh .rpem-h-row,#gh .rpem-ct-row{flex-wrap:wrap;gap:6px}
    #gh .em-list{overflow-x:auto}

    /* ── Test email unresolved box: spezza word-break ──────────────── */
    #gh #em-test-unresolved{word-break:break-all}

    /* ── Stats bars: wrap invece di scorrere off-screen ───────────── */
    #gh .stats-bar{flex-wrap:wrap}
}
</style>
