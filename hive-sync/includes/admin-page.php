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
            <button class="hsync-tab is-active" data-tab="sources" role="tab" aria-selected="true" title="Sorgenti registrate (GS, CSV, …)">1. Sources</button>
            <button class="hsync-tab" data-tab="mappings" role="tab" aria-selected="false" title="Trasformazioni colonna→campo Woo">2. Mappings</button>
            <button class="hsync-tab" data-tab="pipelines" role="tab" aria-selected="false" title="Pre-check + import-rule + post-check">3. Pipelines</button>
            <button class="hsync-tab" data-tab="rules" role="tab" aria-selected="false" title="Operazioni su prodotti già esistenti">4. Rules</button>
            <button class="hsync-tab" data-tab="run" role="tab" aria-selected="false" title="Esegui un import ad-hoc combinando tutto">5. Run</button>
            <button class="hsync-tab" data-tab="jobs" role="tab" aria-selected="false" title="Schedulazione cron di sources/rules">6. Jobs</button>
            <button class="hsync-tab" data-tab="media" role="tab" aria-selected="false" title="Browser media + safe cleanup orfani">Media</button>
            <button class="hsync-tab" data-tab="exports" role="tab" aria-selected="false" title="CSV/JSON dell'inventario locale">Exports</button>
            <button class="hsync-tab" data-tab="runs" role="tab" aria-selected="false" title="Storico esecuzioni">Runs</button>
            <button class="hsync-tab" data-tab="migrate" role="tab" aria-selected="false" title="Importa pipelines/mappings/jobs legacy">Migra da Golden Hive</button>
            <button class="hsync-tab" data-tab="tools" role="tab" aria-selected="false" title="Operazioni distruttive (cleanup)">Tools</button>
        </nav>

        <section class="hsync-panel is-active" data-panel="sources">
            <div class="hsync-tab-intro">
                <strong>Step 1 — Sources.</strong> Sorgenti registrate dal codice (GS feed, CSV, …)
                con le loro <strong>config salvate</strong> (URL + token + cookie). Per ogni sorgente
                puoi creare N config: <em>"GS produzione"</em>, <em>"GS staging"</em>, ecc. La config
                salvata qui è quella che il tab <strong>Run</strong> e i <strong>Jobs</strong>
                riusano — i secret restano in DB cleartext (autoload=false), redatti in UI.
                Il bottone <em>Test fetch</em> verifica che le credenziali raggiungano l'endpoint
                prima di salvare.
            </div>
            <div class="hsync-sources-list" data-region="sources-list">
                <p class="hsync-loading">Caricamento…</p>
            </div>
        </section>

        <section class="hsync-panel" data-panel="mappings">
            <div class="hsync-tab-intro">
                <strong>Step 2 — Mappings.</strong> Trasformano la riga grezza della sorgente in
                campi Woo (<code>sku</code>, <code>name</code>, <code>regular_price</code>, …).
                Servono <em>solo</em> per CSV / API non normalizzate. La sorgente Golden Sneakers
                arriva già normalizzata, quindi una mapping è opzionale.
                <br>
                Premi <strong>Installa default</strong> per la mapping <code>gs-default</code>.
            </div>
            <div class="hsync-toolbar">
                <select data-control="mappings-filter">
                    <option value="">Tutte le sorgenti</option>
                </select>
                <button class="button button-primary" data-action="mapping-new">+ Nuova mapping</button>
                <button class="button" data-action="install-defaults">Installa default</button>
            </div>
            <div class="hsync-mappings-list" data-region="mappings-list">
                <p class="hsync-loading">Caricamento…</p>
            </div>
            <div class="hsync-mapping-editor is-hidden" data-region="mapping-editor">
                <h2>Mapping</h2>
                <div class="hsync-mapping-grid">
                    <label>Nome <input type="text" data-field="map-name" placeholder="GS → Woo standard"></label>
                    <label>Sorgente
                        <select data-field="map-source"></select>
                    </label>
                </div>

                <div class="hsync-mapping-help">
                    <strong>Come funziona.</strong> A sinistra c'è la <em>schema Woo standard</em>
                    (campi fissi), a destra scegli quale campo della sorgente esterna ci finisce —
                    via <em>path</em> (es. <code>SKU</code>, <code>sizes.size_eu</code>) o
                    <em>template</em> (es. <code>&lt;p&gt;{brand_name} {name}&lt;/p&gt;</code>).
                    I campi obbligatori sono marcati con <span class="hsync-required-marker">*</span>.
                    Sezione <em>Avanzati</em> per SEO / meta / gallery; <em>Custom</em> per chiavi
                    fuori schema.
                </div>

                <div class="hsync-mapping-toolbar">
                    <button class="button" data-action="mapping-probe">Sonda sorgente</button>
                    <label class="hsync-toggle">
                        <input type="checkbox" data-action="mapping-toggle-json"> JSON view
                    </label>
                </div>

                <div data-region="mapping-rows"></div>
                <div data-region="mapping-probe-output" class="hsync-mapping-probe is-hidden"></div>

                <div class="hsync-mapping-json is-hidden" data-region="mapping-json-view">
                    <p class="hsync-muted">Modalità sviluppatore: incolla/esporta il payload JSON completo.</p>
                    <textarea data-field="map-config" rows="10" placeholder='{"sku":"SKU","name":"Title","regular_price":"Price"}'></textarea>
                    <div class="hsync-actions">
                        <button class="button" data-action="mapping-json-apply">Applica JSON al builder</button>
                    </div>
                </div>

                <div class="hsync-actions">
                    <button class="button button-primary" data-action="mapping-save">Salva mapping</button>
                    <button class="button" data-action="mapping-cancel">Annulla</button>
                </div>
            </div>
        </section>

        <section class="hsync-panel" data-panel="pipelines">
            <div class="hsync-tab-intro">
                <strong>Step 3 — Pipelines.</strong> Compongono il <em>lifecycle</em> di import:
                <code>pre-check → import-rule → materialize → post-check</code>.
                Ogni step è scelto da un registry (<em>Rules</em> tab le elenca per riferimento).
                Senza pipeline il Run fa solo <code>fetch → diff → materialize</code> — niente
                checks né regole. Premi <em>Installa default</em> nel tab Mappings per ottenere
                <code>import-default</code>.
            </div>
            <div class="hsync-toolbar">
                <button class="button button-primary" data-action="pipeline-new">+ Nuova pipeline</button>
            </div>
            <div data-region="pipelines-list"><p class="hsync-loading">Caricamento…</p></div>
            <div class="hsync-pipeline-editor is-hidden" data-region="pipeline-editor"></div>
        </section>

        <section class="hsync-panel" data-panel="rules">
            <div class="hsync-tab-intro">
                <strong>Step 4 — Rules.</strong> Pacchetti scoped (selezione + stack di operazioni)
                eseguibili indipendentemente dall'import — ad esempio <em>"applica margine X% a tutti
                i prodotti del brand Y"</em>. Diverse dalle import-rule che fanno parte del lifecycle:
                queste girano <em>post-import</em> su prodotti già esistenti, possono essere
                scheduled come Job.
            </div>
            <div class="hsync-toolbar">
                <button class="button button-primary" data-action="rule-new">+ Nuova rule</button>
            </div>
            <div data-region="rules-list"><p class="hsync-loading">Caricamento…</p></div>
            <div class="hsync-rule-editor is-hidden" data-region="rule-editor"></div>
        </section>

        <section class="hsync-panel" data-panel="jobs">
            <div class="hsync-tab-intro">
                <strong>Step 6 — Jobs.</strong> Schedula sources / rules con cron expression
                (5 campi standard). Tick ogni 5 minuti via WP-Cron + Action Scheduler.
                <em>Tick now</em> esegue manualmente il dispatcher; <em>Action Scheduler health</em>
                mostra pending/past-due/failed per diagnosticare blocchi.
            </div>
            <div class="hsync-toolbar">
                <button class="button button-primary" data-action="job-new">+ Nuovo job</button>
                <button class="button" data-action="jobs-tick-now">Tick now</button>
                <button class="button" data-action="as-health">Action Scheduler health</button>
            </div>
            <div data-region="as-health-output"></div>
            <div data-region="jobs-list"><p class="hsync-loading">Caricamento…</p></div>
            <div class="hsync-job-editor is-hidden" data-region="job-editor"></div>
        </section>

        <section class="hsync-panel" data-panel="media">
            <div class="hsync-tab-intro">
                <strong>Media management.</strong> Browser unificato della <em>Media Library</em>
                con filtri usage (mapped / orphan), whitelist (protezione dall'eliminazione) e
                <em>Safe Cleanup</em> degli orfani. Ogni cancellazione è loggata.
                L'indice <em>attachment → utilizzi</em> è cached 10 minuti e si invalida
                automaticamente sugli hook media/prodotto.
            </div>
            <div class="hsync-media-toolbar">
                <input type="search" data-field="media-filename" placeholder="Cerca filename…">
                <select data-field="media-usage">
                    <option value="all">Tutti gli utilizzi</option>
                    <option value="mapped">Solo mappati (in uso)</option>
                    <option value="unmapped">Solo orfani (non in uso)</option>
                </select>
                <select data-field="media-whitelist">
                    <option value="all">Whitelist: tutti</option>
                    <option value="yes">Solo whitelist</option>
                    <option value="no">Esclusi whitelist</option>
                </select>
                <button class="button" data-action="media-search">Cerca</button>
                <button class="button" data-action="media-rebuild-index">Ricostruisci indice</button>
                <button class="button button-primary" data-action="media-cleanup-preview">Safe Cleanup…</button>
            </div>
            <div data-region="media-cleanup-output"></div>
            <div data-region="media-list"><p class="hsync-loading">Caricamento…</p></div>
            <div class="hsync-media-pager" data-region="media-pager"></div>
        </section>

        <section class="hsync-panel" data-panel="exports">
            <p class="hsync-muted">Export del catalogo Woo locale. CSV/JSON per inventario completo, oppure JSON gerarchico per snapshot per tassonomia.</p>
            <div data-region="exports-output"></div>
            <div class="hsync-export-cards">
                <div class="hsync-source-card">
                    <h3>Inventario completo</h3>
                    <p class="hsync-muted">Tutti i prodotti pubblicati: id, sku, name, status, prezzi, stock, brand, categorie.</p>
                    <div class="hsync-actions">
                        <button class="button" data-action="export-inventory" data-format="csv">CSV</button>
                        <button class="button" data-action="export-inventory" data-format="json">JSON</button>
                    </div>
                </div>
                <div class="hsync-source-card">
                    <h3>Catalogo per tassonomia</h3>
                    <p class="hsync-muted">JSON raggruppato per <code>product_cat</code> + <code>product_brand</code>, solo dati essenziali.</p>
                    <div class="hsync-actions">
                        <button class="button" data-action="export-catalog">JSON</button>
                    </div>
                </div>
            </div>
        </section>

        <section class="hsync-panel" data-panel="run">
            <div class="hsync-tab-intro">
                <strong>Step 5 — Run.</strong> L'unico tab che <em>esegue</em> un import combinando
                Source + (opzionale) Mapping + (opzionale) Pipeline.
                <strong>Dry run</strong> (default) non scrive nulla — usalo per verificare il diff.
                Da qui salvi anche la config con secrets reali (<em>Salva config</em>) e la
                pianifichi come Job (<em>Salva come Job…</em>).
                <br>
                Se la run è troppo lunga, il server fa <em>tick</em> ogni ~25s e il client
                continua automaticamente (cursor-based resume).
            </div>
            <div class="hsync-run-form">
                <label>Sorgente
                    <select data-field="run-source"></select>
                </label>
                <label>Config salvata
                    <select data-field="run-config-slug">
                        <option value="">— nessuna (config inline qui sotto) —</option>
                    </select>
                    <small class="hsync-muted">Crea le config nel tab <strong>Sources</strong>; appariranno qui filtrate per sorgente.</small>
                </label>
                <label>Mapping (opzionale)
                    <select data-field="run-mapping"><option value="">— nessuna —</option></select>
                </label>
                <label>Pipeline (pre-checks + import-rules + post-checks)
                    <select data-field="run-pipeline"><option value="">— nessuna (fetch → diff → materialize) —</option></select>
                </label>
                <div data-region="run-config-fields"></div>
                <div class="hsync-actions">
                    <button class="button" data-action="run-test-fetch">Test fetch</button>
                    <button class="button" data-action="run-save-config">Salva config…</button>
                    <button class="button" data-action="run-save-job">Salva come Job…</button>
                    <label class="hsync-dryrun">
                        <input type="checkbox" data-field="run-dry-run" checked> Dry run
                    </label>
                    <button class="button button-primary" data-action="run-now">Run now</button>
                </div>
            </div>
            <div class="hsync-run-output" data-region="run-output"></div>
        </section>

        <section class="hsync-panel" data-panel="runs">
            <div class="hsync-tab-intro">
                <strong>Storico.</strong> Lista delle ultime esecuzioni (ad-hoc da <em>Run</em> o
                triggered da <em>Jobs</em>) con summary, durata e cursor di resume.
                Le run <code>continue</code> sono in pausa fra un tick e l'altro — vengono
                riprese automaticamente al prossimo cron tick.
            </div>
            <div class="hsync-toolbar">
                <button class="button" data-action="runs-refresh">Aggiorna</button>
            </div>
            <div data-region="runs-list">
                <p class="hsync-loading">Caricamento…</p>
            </div>
        </section>

        <section class="hsync-panel" data-panel="migrate">
            <p class="hsync-muted">
                Importa pipelines / mapping / jobs salvati in Golden Hive (<code>wp_options</code>)
                nelle tabelle dedicate di Hive Sync. Operazione idempotente:
                rilanciarla salta record già importati.
            </p>
            <p class="hsync-muted">
                I jobs vengono importati <strong>disabilitati</strong> di default —
                riabilitali manualmente dopo verifica. Le mapping rule legacy hanno
                shape diversa: il payload originale è preservato come JSON dentro
                <code>config.legacy_payload</code> per ricostruzione manuale.
            </p>
            <div class="hsync-actions">
                <button class="button" data-action="legacy-audit">Audit (anteprima)</button>
                <button class="button button-primary" data-action="legacy-import">Importa ora</button>
            </div>
            <div data-region="legacy-output"></div>
        </section>

        <section class="hsync-panel" data-panel="tools">
            <div class="hsync-tab-intro">
                <strong>⚠ Tools — Nuclear Cleanup.</strong> Operazioni <em>distruttive</em>:
                cancellano dati a livello SQL diretto (TRUNCATE/DELETE) per andare veloci su
                store grandi (2k+ prodotti, 17k+ media). Richiede capability
                <code>manage_options</code> (più ristretta del resto del plugin).
                Ogni esecuzione passa per un <em>preview</em> con i conteggi reali; senza
                conferma esplicita non si parte. Le immagini in
                <strong>Whitelist</strong> sono sempre protette.
            </div>

            <h2>Cleanup selettivo</h2>
            <p class="hsync-muted">Spunta i target, premi Preview per vedere i conteggi, poi <em>Esegui</em>.</p>

            <div class="hsync-tools-targets">
                <label><input type="checkbox" data-tools-target="products"> <strong>Prodotti</strong> + varianti — DELETE su <code>posts</code> + <code>postmeta</code> + <code>term_relationships</code>, TRUNCATE <code>wc_product_meta_lookup</code></label>
                <label><input type="checkbox" data-tools-target="media"> <strong>Media</strong> (immagini) — wp_delete_attachment loop con disk-removal. Whitelist protetta.</label>
                <label><input type="checkbox" data-tools-target="taxonomy"> <strong>Tassonomie</strong> (cat / brand / tag) — DELETE diretto su <code>terms</code> + <code>term_taxonomy</code> + <code>termmeta</code></label>
                <label><input type="checkbox" data-tools-target="transients"> <strong>Transients</strong> WP + WC — solo cache</label>
                <label><input type="checkbox" data-tools-target="orphan_meta"> <strong>Orfani</strong> postmeta + sessioni WC scadute</label>
            </div>

            <div class="hsync-actions">
                <button class="button" data-action="tools-preview">Preview</button>
                <button class="button button-primary is-danger" data-action="tools-execute" disabled>Esegui cleanup</button>
            </div>
            <div data-region="tools-output"></div>

            <hr>

            <h2>Cleanup per sorgente</h2>
            <p class="hsync-muted">
                Cancella tutti i prodotti importati da una specifica sorgente
                (legge <code>_gh_import_source</code> / <code>_feed_source</code> meta).
                Utile per re-importare da zero senza toccare il resto del catalogo.
            </p>
            <div class="hsync-tools-bysource">
                <select data-field="tools-source">
                    <option value="">— scegli sorgente —</option>
                    <option value="goldensneakers">Golden Sneakers</option>
                    <option value="stockfirmati">StockFirmati</option>
                    <option value="csv">CSV</option>
                    <option value="kicksdb">KicksDB</option>
                    <option value="manual">Manual</option>
                </select>
                <button class="button" data-action="tools-source-count">Conta prodotti</button>
                <button class="button is-danger" data-action="tools-source-delete">Elimina tutti</button>
            </div>
            <div data-region="tools-source-output"></div>
        </section>
    </div>
    <?php
}
