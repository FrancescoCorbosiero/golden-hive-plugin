<?php
/**
 * Hive Sync admin page — Shopify-Importer-style tabs:
 *   Sources / Mappings / Run / Runs
 *
 * The page is a thin shell. All state, fetches, and rendering live in
 * assets/js/admin.js — this PHP just paints the structural HTML so
 * the JS bootstrap has stable hooks.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'Hive Sync',
        'Hive Sync',
        'manage_woocommerce',
        'hive-sync',
        'hsync_render_admin_page',
        'dashicons-update',
        56,
    );
} );

function hsync_render_admin_page(): void {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );
    ?>
    <div class="wrap hsync-wrap" id="hsync-app">
        <h1 class="hsync-title">
            Hive Sync
            <span class="hsync-version">v<?php echo esc_html( HSYNC_VERSION ); ?></span>
        </h1>

        <nav class="hsync-tabs" role="tablist">
            <button class="hsync-tab is-active" data-tab="sources" role="tab" aria-selected="true" title="I tuoi feed e API">1. Connetti</button>
            <button class="hsync-tab" data-tab="mappings" role="tab" aria-selected="false" title="Allinea i campi del feed con WooCommerce">2. Mappa</button>
            <button class="hsync-tab" data-tab="pipelines" role="tab" aria-selected="false" title="Cosa fare durante l'import">3. Componi</button>
            <button class="hsync-tab" data-tab="rules" role="tab" aria-selected="false" title="Azioni sui prodotti esistenti">4. Regole</button>
            <button class="hsync-tab" data-tab="run" role="tab" aria-selected="false" title="Lancia un import quando vuoi">5. Importa</button>
            <button class="hsync-tab" data-tab="jobs" role="tab" aria-selected="false" title="Imposta automazioni">6. Automatizza</button>
            <button class="hsync-tab" data-tab="media" role="tab" aria-selected="false" title="Pulizia foto e libreria">Media</button>
            <button class="hsync-tab" data-tab="exports" role="tab" aria-selected="false" title="Scarica il catalogo">Esporta</button>
            <button class="hsync-tab" data-tab="runs" role="tab" aria-selected="false" title="Storico import">Storico</button>
            <button class="hsync-tab" data-tab="migrate" role="tab" aria-selected="false" title="Vieni da Golden Hive?">Migrazione</button>
            <button class="hsync-tab" data-tab="tools" role="tab" aria-selected="false" title="Pulizia avanzata">Strumenti</button>
        </nav>

        <section class="hsync-panel is-active" data-panel="sources">
            <div class="hsync-tab-intro">
                <strong>Connetti i tuoi feed.</strong>
                Aggiungi qui le credenziali dei tuoi fornitori (URL, token, API key) e dai
                un nome a ogni configurazione — es. <em>"GS produzione"</em> o
                <em>"GS staging"</em>. Le ritrovi pronte all'uso negli altri tab.
                Premi <strong>Test fetch</strong> per verificare che funzionino
                <em>prima</em> di salvarle.
            </div>
            <div class="hsync-sources-list" data-region="sources-list">
                <p class="hsync-loading">Caricamento…</p>
            </div>
        </section>

        <section class="hsync-panel" data-panel="mappings">
            <div class="hsync-tab-intro">
                <strong>Allinea i campi.</strong>
                Per ogni campo di WooCommerce (a sinistra), scegli quale dato della tua
                sorgente metterci dentro (a destra). Funziona per CSV, API, qualsiasi
                feed strutturato.
                <br>
                Non sai da dove iniziare? Premi <strong>Installa default</strong> —
                ti prepariamo una mappatura standard pronta da rifinire.
            </div>
            <div class="hsync-toolbar">
                <select data-control="mappings-filter">
                    <option value="">Tutte le sorgenti</option>
                </select>
                <button class="button button-primary" data-action="mapping-new">+ Nuova mappatura</button>
                <button class="button" data-action="install-defaults">Installa default</button>
                <button class="button" data-action="install-defaults-force" title="Sovrascrive le mappature/flussi default per tirare dentro le ultime modifiche del codice (es. la categorizzazione automatica). I tuoi dati rimangono.">Aggiorna default</button>
            </div>
            <div class="hsync-mappings-list" data-region="mappings-list">
                <p class="hsync-loading">Caricamento…</p>
            </div>
            <div class="hsync-mapping-editor is-hidden" data-region="mapping-editor">
                <h2>Mappatura</h2>
                <div class="hsync-mapping-grid">
                    <label>Nome <input type="text" data-field="map-name" placeholder="GS → Woo standard"></label>
                    <label>Sorgente
                        <select data-field="map-source"></select>
                    </label>
                </div>

                <div class="hsync-mapping-help">
                    <strong>Come funziona.</strong>
                    A sinistra ci sono i campi standard di WooCommerce — sono fissi.
                    A destra scegli quale campo del <strong>feed esterno</strong>
                    ci finisce (es. <code>presented_price</code>, <code>brand_name</code>),
                    oppure un <em>template</em> per costruire contenuti dinamici
                    (es. <code>{brand_name} originali — {name}</code>).
                    I campi con <span class="hsync-required-marker">*</span> sono obbligatori.
                    <br>
                    <strong>Varianti?</strong> Resta tutto piatto: per le taglie/colori basta
                    puntare a un campo che restituisce una lista
                    (es. <code>pa_taglia</code> ← <code>sizes.size_eu</code>) — il sistema
                    crea le varianti automaticamente al momento dell'import.
                </div>

                <div class="hsync-mapping-toolbar">
                    <button class="button" data-action="mapping-probe">Anteprima sorgente</button>
                    <label class="hsync-toggle">
                        <input type="checkbox" data-action="mapping-toggle-json"> Modalità JSON
                    </label>
                </div>

                <div data-region="mapping-rows"></div>
                <div data-region="mapping-probe-output" class="hsync-mapping-probe is-hidden"></div>

                <div class="hsync-mapping-json is-hidden" data-region="mapping-json-view">
                    <p class="hsync-muted">Per chi sa cosa sta facendo: incolla o esporta la mappatura come JSON.</p>
                    <textarea data-field="map-config" rows="10" placeholder='{"sku":"SKU","name":"Title","regular_price":"Price"}'></textarea>
                    <div class="hsync-actions">
                        <button class="button" data-action="mapping-json-apply">Applica JSON al builder</button>
                    </div>
                </div>

                <div class="hsync-actions">
                    <button class="button button-primary" data-action="mapping-save">Salva mappatura</button>
                    <button class="button" data-action="mapping-cancel">Annulla</button>
                </div>
            </div>
        </section>

        <section class="hsync-panel" data-panel="pipelines">
            <div class="hsync-tab-intro">
                <strong>Componi il tuo flusso d'import.</strong>
                Decidi <em>cosa controllare prima</em> di importare un prodotto,
                <em>cosa modificare durante</em>, e <em>cosa verificare dopo</em>.
                Es: scarica le foto, salta i prodotti senza prezzo, controlla che
                la categoria esista.
                <br>
                Senza pipeline l'import è semplice: scarica e salva. Con una pipeline
                aggiungi qualità ai dati.
            </div>
            <div class="hsync-toolbar">
                <button class="button button-primary" data-action="pipeline-new">+ Nuovo flusso</button>
            </div>
            <div data-region="pipelines-list"><p class="hsync-loading">Caricamento…</p></div>
            <div class="hsync-pipeline-editor is-hidden" data-region="pipeline-editor"></div>
        </section>

        <section class="hsync-panel" data-panel="rules">
            <div class="hsync-tab-intro">
                <strong>Azioni a colpo singolo sui prodotti esistenti.</strong>
                Es: <em>"applica un margine del 20% su tutto il brand X"</em>,
                <em>"metti in bozza i prodotti senza foto"</em>,
                <em>"aggiorna lo stock dei sneaker"</em>.
                Le regole girano sui prodotti già nel catalogo, quando vuoi tu
                — manualmente o programmate.
            </div>
            <div class="hsync-toolbar">
                <button class="button button-primary" data-action="rule-new">+ Nuova regola</button>
            </div>
            <div data-region="rules-list"><p class="hsync-loading">Caricamento…</p></div>
            <div class="hsync-rule-editor is-hidden" data-region="rule-editor"></div>
        </section>

        <section class="hsync-panel" data-panel="jobs">
            <div class="hsync-tab-intro">
                <strong>Metti tutto in automatico.</strong>
                Programma quando un import o una regola devono partire — ogni notte,
                ogni ora, due volte al giorno. Tu non devi più pensarci.
                <br>
                Usa <em>Esegui ora</em> se vuoi forzare un giro adesso, e
                <em>Stato del motore</em> se sospetti che qualcosa sia bloccato.
            </div>
            <div class="hsync-toolbar">
                <button class="button button-primary" data-action="job-new">+ Nuova automazione</button>
                <button class="button" data-action="jobs-tick-now">Esegui ora</button>
                <button class="button" data-action="as-health">Stato del motore</button>
            </div>
            <div data-region="as-health-output"></div>
            <div data-region="jobs-list"><p class="hsync-loading">Caricamento…</p></div>
            <div class="hsync-job-editor is-hidden" data-region="job-editor"></div>
        </section>

        <section class="hsync-panel" data-panel="media">
            <div class="hsync-tab-intro">
                <strong>Tieni in ordine la libreria immagini.</strong>
                Cerca per nome, filtra le foto orfane (quelle che non sono in nessun
                prodotto) e cancellale in sicurezza con <em>Pulizia sicura</em>.
                <br>
                Vuoi proteggere un'immagine? Aggiungila alla
                <strong>whitelist</strong> e non verrà mai toccata.
            </div>
            <div class="hsync-media-toolbar">
                <input type="search" data-field="media-filename" placeholder="Cerca per nome file…">
                <select data-field="media-usage">
                    <option value="all">Tutte le immagini</option>
                    <option value="mapped">Solo in uso</option>
                    <option value="unmapped">Solo orfane</option>
                </select>
                <select data-field="media-whitelist">
                    <option value="all">Whitelist: indifferente</option>
                    <option value="yes">Solo protette</option>
                    <option value="no">Solo non protette</option>
                </select>
                <button class="button" data-action="media-search">Cerca</button>
                <button class="button" data-action="media-rebuild-index">Aggiorna indice</button>
                <button class="button button-primary" data-action="media-cleanup-preview">Pulizia sicura…</button>
            </div>
            <div data-region="media-cleanup-output"></div>
            <div data-region="media-list"><p class="hsync-loading">Caricamento…</p></div>
            <div class="hsync-media-pager" data-region="media-pager"></div>
        </section>

        <section class="hsync-panel" data-panel="exports">
            <div class="hsync-tab-intro">
                <strong>Scarica il tuo catalogo.</strong>
                Una copia istantanea dei tuoi prodotti — CSV per Excel,
                JSON per analisi e backup.
            </div>
            <div data-region="exports-output"></div>
            <div class="hsync-export-cards">
                <div class="hsync-source-card">
                    <h3>Inventario completo</h3>
                    <p class="hsync-muted">Tutti i prodotti pubblicati con prezzi, stock, brand e categorie.</p>
                    <div class="hsync-actions">
                        <button class="button" data-action="export-inventory" data-format="csv">CSV</button>
                        <button class="button" data-action="export-inventory" data-format="json">JSON</button>
                    </div>
                </div>
                <div class="hsync-source-card">
                    <h3>Catalogo per categoria</h3>
                    <p class="hsync-muted">Vista gerarchica: categoria → brand → prodotti. Solo i dati essenziali.</p>
                    <div class="hsync-actions">
                        <button class="button" data-action="export-catalog">JSON</button>
                    </div>
                </div>
            </div>
        </section>

        <section class="hsync-panel" data-panel="run">
            <div class="hsync-tab-intro">
                <strong>Lancia un import quando vuoi.</strong>
                Scegli la sorgente, una mappatura, un flusso (se ne hai), e parti.
                <br>
                <strong>Prova in sicurezza:</strong> tieni acceso <em>Solo prova</em>
                per vedere cosa succederebbe senza toccare il catalogo. Soddisfatto?
                Toglilo e lancia per davvero.
            </div>
            <div class="hsync-run-form">
                <label>Sorgente
                    <select data-field="run-source"></select>
                </label>
                <label>Configurazione salvata
                    <select data-field="run-config-slug">
                        <option value="">— compila al volo qui sotto —</option>
                    </select>
                    <small class="hsync-muted">Le configurazioni si gestiscono nel tab <strong>Connetti</strong>.</small>
                </label>
                <label>Mappatura (opzionale)
                    <select data-field="run-mapping"><option value="">— nessuna —</option></select>
                </label>
                <label>Flusso d'import (opzionale)
                    <select data-field="run-pipeline"><option value="">— solo scarica e salva —</option></select>
                </label>
                <div data-region="run-config-fields"></div>
                <div class="hsync-actions">
                    <button class="button" data-action="run-test-fetch">Test connessione</button>
                    <button class="button" data-action="run-save-config">Salva configurazione…</button>
                    <button class="button" data-action="run-save-job">Programma…</button>
                    <label class="hsync-dryrun">
                        <input type="checkbox" data-field="run-dry-run" checked> Solo prova
                    </label>
                    <button class="button button-primary" data-action="run-now">Importa adesso</button>
                </div>
            </div>
            <div class="hsync-run-output" data-region="run-output"></div>
        </section>

        <section class="hsync-panel" data-panel="runs">
            <div class="hsync-tab-intro">
                <strong>Lo storico dei tuoi import.</strong>
                Verifica cos'è andato bene e cosa no. Gli import lunghi vengono
                divisi in più passate — qui li vedi proseguire automaticamente.
            </div>
            <div class="hsync-toolbar">
                <button class="button" data-action="runs-refresh">Aggiorna</button>
            </div>
            <div data-region="runs-list">
                <p class="hsync-loading">Caricamento…</p>
            </div>
        </section>

        <section class="hsync-panel" data-panel="migrate">
            <div class="hsync-tab-intro">
                <strong>Vieni da Golden Hive?</strong>
                Importa qui le tue mappature, flussi e automazioni esistenti con
                un click. Puoi rilanciarlo quante volte vuoi: i record già importati
                vengono saltati.
                <br>
                <em>Nota:</em> le automazioni vengono importate <strong>spente</strong>
                — verifica che siano corrette prima di riaccenderle.
            </div>
            <div class="hsync-actions">
                <button class="button" data-action="legacy-audit">Anteprima</button>
                <button class="button button-primary" data-action="legacy-import">Importa adesso</button>
            </div>
            <div data-region="legacy-output"></div>
        </section>

        <section class="hsync-panel" data-panel="tools">
            <div class="hsync-tab-intro">
                <strong>⚠ Strumenti di pulizia.</strong>
                Operazioni distruttive — quello che cancellano <em>non torna indietro</em>.
                Riservato all'admin del sito. Ogni azione mostra prima un conteggio,
                poi chiede conferma esplicita. Le immagini in
                <strong>whitelist</strong> sono sempre intoccabili.
            </div>

            <h2>Pulizia selettiva</h2>
            <p class="hsync-muted">Spunta cosa eliminare, premi <em>Anteprima</em> per vedere quanti elementi sono coinvolti, poi <em>Esegui</em>.</p>

            <div class="hsync-tools-targets">
                <label><input type="checkbox" data-tools-target="products"> <strong>Prodotti</strong> e varianti — cancella tutto il catalogo</label>
                <label><input type="checkbox" data-tools-target="media"> <strong>Immagini</strong> dalla libreria — esclude la whitelist</label>
                <label><input type="checkbox" data-tools-target="taxonomy"> <strong>Tassonomie</strong> — categorie, brand, tag</label>
                <label><input type="checkbox" data-tools-target="transients"> <strong>Cache</strong> — solo dati temporanei, sempre sicuro</label>
                <label><input type="checkbox" data-tools-target="orphan_meta"> <strong>Residui</strong> — meta orfani e sessioni scadute</label>
            </div>

            <div class="hsync-actions">
                <button class="button" data-action="tools-preview">Anteprima</button>
                <button class="button button-primary is-danger" data-action="tools-execute" disabled>Esegui pulizia</button>
            </div>
            <div data-region="tools-output"></div>

            <hr>

            <h2>Pulizia per fornitore</h2>
            <p class="hsync-muted">
                Cancella solo i prodotti che hai importato da una specifica sorgente.
                Utile quando vuoi rifare l'import da zero senza toccare il resto del catalogo.
            </p>
            <div class="hsync-tools-bysource">
                <select data-field="tools-source">
                    <option value="">— scegli un fornitore —</option>
                    <option value="goldensneakers">Golden Sneakers</option>
                    <option value="stockfirmati">StockFirmati</option>
                    <option value="csv">CSV</option>
                    <option value="kicksdb">KicksDB</option>
                    <option value="manual">Inseriti a mano</option>
                </select>
                <button class="button" data-action="tools-source-count">Conta prodotti</button>
                <button class="button is-danger" data-action="tools-source-delete">Elimina tutti</button>
            </div>
            <div data-region="tools-source-output"></div>
        </section>
    </div>
    <?php
}
