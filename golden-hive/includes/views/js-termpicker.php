// ═══ TERM PICKER — multi-select ricercabile (stile Shopify) ══════════════════
//
// Componente riusabile per selezionare termini (brand, categorie, tag) da
// liste lunghe: control compatto con chips + dropdown con ricerca testuale,
// navigazione da tastiera e "Pulisci". Sostituisce i <select multiple>
// nativi, scomodi oltre la decina di voci.
//
// Uso:
//   GH.termPicker(containerEl, {
//       items: [{id, name, parent}],     // parent > 0 → riga indentata
//       selected: [12, 34],              // pre-selezione (int[])
//       placeholder: 'Cerca brand...',
//       onChange: ids => { ... },        // chiamata ad ogni modifica
//   });
//
// La selezione corrente e sempre leggibile da containerEl._ghTpSelected
// (int[]): i caller che raccolgono parametri via getElementById possono
// leggerla senza tenere un riferimento al picker.

(function(){

    function norm(s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    GH.termPicker = function(container, opts) {
        opts = opts || {};
        const items    = Array.isArray(opts.items) ? opts.items : [];
        const onChange = typeof opts.onChange === 'function' ? opts.onChange : null;
        const esc      = GH.esc;

        const byId = {};
        items.forEach(t => { byId[t.id] = t; });

        let selected = new Set((Array.isArray(opts.selected) ? opts.selected : []).map(Number).filter(Boolean));
        let filtered = items;
        let hi = -1; // indice highlight tastiera dentro `filtered`

        container.classList.add('gh-tp');
        container.innerHTML = '';

        const control = document.createElement('div');
        control.className = 'gh-tp-control';
        control.tabIndex = 0;

        const drop = document.createElement('div');
        drop.className = 'gh-tp-drop';
        drop.innerHTML =
            '<input type="text" class="gh-tp-search filter-select" placeholder="' + esc(opts.placeholder || 'Cerca...') + '" />' +
            '<div class="gh-tp-list"></div>' +
            '<div class="gh-tp-foot"><span class="gh-tp-count"></span>' +
            '<button type="button" class="btn btn-ghost btn-sm gh-tp-clear">Pulisci</button></div>';

        container.appendChild(control);
        container.appendChild(drop);

        const search  = drop.querySelector('.gh-tp-search');
        const listEl  = drop.querySelector('.gh-tp-list');
        const countEl = drop.querySelector('.gh-tp-count');

        function publish() {
            container._ghTpSelected = Array.from(selected);
            if (onChange) onChange(container._ghTpSelected);
        }

        function renderControl() {
            const ids = Array.from(selected);
            let h = '';
            if (!ids.length) {
                h = '<span class="gh-tp-placeholder">' + esc(opts.placeholder || 'Seleziona...') + '</span>';
            } else {
                const shown = ids.slice(0, 3);
                h = shown.map(id => {
                    const t = byId[id];
                    return '<span class="gh-tp-chip" data-id="' + id + '">' +
                           '<span class="gh-tp-chip-name">' + esc(t ? t.name : '#' + id) + '</span>' +
                           '<span class="gh-tp-chip-x" title="Rimuovi">&times;</span></span>';
                }).join('');
                if (ids.length > shown.length) h += '<span class="gh-tp-more">+' + (ids.length - shown.length) + '</span>';
            }
            control.innerHTML = h + '<span class="gh-tp-caret">&#9662;</span>';
        }

        function renderList() {
            countEl.textContent = selected.size + ' selezionati';
            if (!filtered.length) {
                listEl.innerHTML = '<div class="gh-tp-empty">Nessun risultato</div>';
                return;
            }
            listEl.innerHTML = filtered.map((t, i) => {
                const sel = selected.has(Number(t.id));
                return '<div class="gh-tp-opt' + (sel ? ' sel' : '') + (i === hi ? ' hi' : '') + '" data-id="' + t.id + '">' +
                       (t.parent ? '<span class="gh-tp-ind"></span>' : '') +
                       '<span class="gh-tp-box">&#10003;</span>' +
                       '<span class="gh-tp-name">' + esc(t.name) + '</span>' +
                       '</div>';
            }).join('');
            const hiEl = listEl.querySelector('.gh-tp-opt.hi');
            if (hiEl) hiEl.scrollIntoView({ block: 'nearest' });
        }

        function toggle(id) {
            id = Number(id);
            if (selected.has(id)) selected.delete(id); else selected.add(id);
            publish();
            renderControl();
            renderList();
        }

        function applyFilter() {
            const q = norm(search.value.trim());
            filtered = q ? items.filter(t => norm(t.name).includes(q)) : items;
            hi = q && filtered.length ? 0 : -1;
            renderList();
        }

        function openDrop() {
            if (container.classList.contains('open')) return;
            // Drop-up se non c'e spazio sotto (es. bulk action bar a fondo pagina).
            const rect = control.getBoundingClientRect();
            container.classList.toggle('up', window.innerHeight - rect.bottom < 340 && rect.top > 340);
            container.classList.add('open');
            search.value = '';
            applyFilter();
            search.focus();
        }

        function closeDrop() {
            container.classList.remove('open');
            hi = -1;
        }

        control.addEventListener('click', function(e) {
            const x = e.target.closest('.gh-tp-chip-x');
            if (x) { toggle(x.closest('.gh-tp-chip').dataset.id); e.stopPropagation(); return; }
            container.classList.contains('open') ? closeDrop() : openDrop();
        });
        control.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') { e.preventDefault(); openDrop(); }
        });

        search.addEventListener('input', applyFilter);
        search.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { e.stopPropagation(); closeDrop(); control.focus(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); if (hi < filtered.length - 1) { hi++; renderList(); } return; }
            if (e.key === 'ArrowUp')   { e.preventDefault(); if (hi > 0) { hi--; renderList(); } return; }
            if (e.key === 'Enter')     { e.preventDefault(); if (hi >= 0 && filtered[hi]) toggle(filtered[hi].id); }
        });

        listEl.addEventListener('click', function(e) {
            const opt = e.target.closest('.gh-tp-opt');
            if (opt) { toggle(opt.dataset.id); search.focus(); }
        });

        drop.querySelector('.gh-tp-clear').addEventListener('click', function() {
            selected.clear();
            publish();
            renderControl();
            renderList();
            search.focus();
        });

        // Chiudi su click fuori. Il listener si auto-rimuove quando il
        // container esce dal DOM (le view re-renderizzano via innerHTML).
        const outside = function(e) {
            if (!document.body.contains(container)) { document.removeEventListener('mousedown', outside); return; }
            if (!container.contains(e.target)) closeDrop();
        };
        document.addEventListener('mousedown', outside);

        publish();
        renderControl();
        renderList();

        return {
            getSelected: () => Array.from(selected),
            setSelected: ids => { selected = new Set((ids || []).map(Number).filter(Boolean)); publish(); renderControl(); renderList(); },
        };
    };
})();
