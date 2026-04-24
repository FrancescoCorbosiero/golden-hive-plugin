<?php
/**
 * Campaign Lite — dev-first email campaign tool.
 *
 * Self-contained WP admin page under Tools: pesca la lista iscritti da
 * Hustle (un modulo o tutti), compila subject + body (HTML), preview sul
 * primo contatto, invia via wp_mail() → WP Mail SMTP → AWS SES con rate
 * limit SES. Zero dipendenze dal resto del plugin (nessun template system,
 * nessun brand layer, nessun validator). Se qualcosa si rompe altrove,
 * questa pagina continua a funzionare.
 *
 * Menu: Tools → Campaign Email.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'RP_Campaign_Tool' ) ) return;

class RP_Campaign_Tool {

    public function __construct() {
        add_action( 'admin_menu',                  [ $this, 'add_menu' ] );
        add_action( 'admin_post_rp_send_campaign', [ $this, 'handle_send' ] );
        add_action( 'admin_post_rp_export_csv',    [ $this, 'handle_export' ] );
        add_action( 'admin_notices',               [ $this, 'show_notices' ] );
    }

    // ── MENU ──────────────────────────────────────────────────────────────────

    public function add_menu() {
        add_submenu_page(
            'tools.php',
            'Campaign Email — ResellPiacenza',
            '📧 Campaign Email',
            'manage_options',
            'rp-campaign-tool',
            [ $this, 'render_page' ]
        );
    }

    // ── DB HELPERS ────────────────────────────────────────────────────────────

    /**
     * Restituisce i moduli Hustle di tipo optin.
     */
    private function get_hustle_modules() {
        global $wpdb;
        $table = $wpdb->prefix . 'hustle_modules';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return [];
        }
        return $wpdb->get_results(
            "SELECT module_id, module_name, module_type
             FROM {$table}
             WHERE module_mode = 'optin'
             ORDER BY module_name ASC"
        );
    }

    /**
     * Restituisce gli iscritti. Se $module_id = 0 → tutti i moduli.
     *
     * @param  int $module_id
     * @return array  Oggetti con: entry_id, email, display_name, module_id, date_created
     */
    private function get_subscribers( int $module_id = 0 ): array {
        global $wpdb;
        $et = $wpdb->prefix . 'hustle_entries';
        $mt = $wpdb->prefix . 'hustle_entries_meta';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$et}'" ) !== $et ) {
            return [];
        }

        $where = $module_id
            ? $wpdb->prepare( 'WHERE e.module_id = %d', $module_id )
            : '';

        $results = $wpdb->get_results( "
            SELECT
                e.entry_id,
                MAX( CASE WHEN em.meta_key = 'email'      THEN em.meta_value END ) AS email,
                COALESCE(
                    MAX( CASE WHEN em.meta_key = 'last_name'  THEN em.meta_value END ),
                    MAX( CASE WHEN em.meta_key = 'first_name' THEN em.meta_value END )
                ) AS display_name,
                e.module_id,
                e.date_created
            FROM {$et} e
            INNER JOIN {$mt} em ON e.entry_id = em.entry_id
            {$where}
            GROUP BY e.entry_id
            HAVING email IS NOT NULL AND email != ''
            ORDER BY e.date_created ASC
        " );

        if ( ! $results ) return [];

        // Dedupe per email (stessa email su moduli diversi → tieni la prima).
        $seen  = [];
        $clean = [];
        foreach ( $results as $row ) {
            $key = strtolower( trim( $row->email ) );
            if ( ! isset( $seen[ $key ] ) ) {
                $seen[ $key ] = true;
                $clean[]      = $row;
            }
        }
        return $clean;
    }

    // ── RENDER PAGE ───────────────────────────────────────────────────────────

    public function render_page() {
        $modules      = $this->get_hustle_modules();
        $selected_mod = isset( $_GET['module_id'] ) ? intval( $_GET['module_id'] ) : 0;
        $subscribers  = $this->get_subscribers( $selected_mod );
        $count        = count( $subscribers );

        $preview_html = null;
        if ( isset( $_GET['rp_preview'] ) ) {
            $preview_html = get_transient( 'rp_preview_html' );
        }

        $last_subject = get_transient( 'rp_last_subject' ) ?: '';
        $last_body    = get_transient( 'rp_last_body' )    ?: '';
        ?>
        <div class="wrap">
            <h1>📧 Email Campaign Tool
                <span style="font-size:13px;font-weight:400;color:#666;margin-left:12px;">
                    via wp_mail() → WP Mail SMTP → AWS SES
                </span>
            </h1>

            <?php if ( $preview_html ): ?>
            <div class="notice notice-info" style="padding:16px;">
                <strong>👁 Anteprima — prima email della lista</strong>
                <p class="description" style="margin-top:4px;">
                    Rendering isolato in iframe (sandbox). Il body puo contenere DOCTYPE,
                    &lt;html&gt;, &lt;style&gt; — non rompe la pagina admin.
                </p>
                <iframe
                    srcdoc="<?php echo esc_attr( $preview_html ); ?>"
                    sandbox="allow-same-origin"
                    style="width:100%;height:600px;margin-top:12px;border:1px solid #ddd;background:#fff;border-radius:4px;"
                    title="Anteprima email"></iframe>
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:24px;margin-top:20px;align-items:flex-start;">

                <!-- ── LEFT: Composer ── -->
                <div style="flex:2;min-width:0;">
                    <div class="card" style="padding:20px;max-width:720px;">

                        <!-- Module selector (GET, no nonce needed — read-only) -->
                        <form method="get" action="" style="margin-bottom:16px;">
                            <input type="hidden" name="page" value="rp-campaign-tool">
                            <label style="font-weight:600;">Lista Hustle:&nbsp;</label>
                            <select name="module_id" onchange="this.form.submit()" style="min-width:240px;">
                                <option value="0">— Tutti i moduli —</option>
                                <?php foreach ( $modules as $m ): ?>
                                    <option value="<?php echo esc_attr( $m->module_id ); ?>"
                                        <?php selected( $selected_mod, $m->module_id ); ?>>
                                        <?php echo esc_html( $m->module_name ); ?>
                                        (<?php echo esc_html( $m->module_type ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span style="margin-left:10px;color:#555;">
                                <strong><?php echo $count; ?></strong> iscritti trovati
                            </span>
                        </form>

                        <!-- Campaign form (POST) -->
                        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                            <?php wp_nonce_field( 'rp_send_campaign', 'rp_nonce' ); ?>
                            <input type="hidden" name="action"    value="rp_send_campaign">
                            <input type="hidden" name="module_id" value="<?php echo esc_attr( $selected_mod ); ?>">

                            <table class="form-table" style="margin-top:0;">
                                <tr>
                                    <th style="width:120px;"><label for="rp_subject">Oggetto *</label></th>
                                    <td>
                                        <input type="text" id="rp_subject" name="subject"
                                               class="large-text" required
                                               value="<?php echo esc_attr( $last_subject ); ?>"
                                               placeholder="Es: 🔥 Nuovi arrivi — Jordan 4 Travis Scott">
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="rp_body">Corpo * (HTML raw)</label></th>
                                    <td>
                                        <textarea id="rp_body" name="body"
                                                  rows="20"
                                                  style="width:100%;font-family:'JetBrains Mono',Menlo,Consolas,monospace;font-size:12px;line-height:1.5;white-space:pre;"
                                                  spellcheck="false"
                                                  placeholder="<!DOCTYPE html>&#10;<html>&#10;  <head><meta charset='UTF-8'></head>&#10;  <body>&#10;    <h1>Ciao {{first_name}}</h1>&#10;    <p>Il tuo coupon: DEMO20</p>&#10;  </body>&#10;</html>"><?php echo esc_textarea( $last_body ); ?></textarea>
                                        <p class="description" style="margin-top:6px;">
                                            HTML completo accettato (DOCTYPE, &lt;style&gt;, table-based layouts, tutto).
                                            Usa <code>{{first_name}}</code> per personalizzare il nome del destinatario.
                                            Puoi incollare l'HTML scaricato da Golden Hive → Templates → "Scarica demo".
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>Rate limit</label></th>
                                    <td>
                                        <select name="rate_limit">
                                            <option value="50000">Veloce — ~20/sec (SES produzione, alto volume)</option>
                                            <option value="200000" selected>Normale — ~5/sec (consigliato)</option>
                                            <option value="1000000">Lento — 1/sec (debug o sandbox SES)</option>
                                        </select>
                                        <p class="description">
                                            SES in produzione supporta tipicamente 14 msg/sec.
                                            "Normale" e il compromesso sicuro.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin-top:16px;">
                                <button type="submit" name="action_type" value="preview"
                                        class="button button-secondary" style="margin-right:8px;">
                                    👁 Anteprima (prima email)
                                </button>
                                <button type="submit" name="action_type" value="send"
                                        class="button button-primary"
                                        <?php echo $count === 0 ? 'disabled' : ''; ?>
                                        onclick="return confirm('Inviare la campagna a <?php echo $count; ?> iscritti?\n\nQuesta azione non e reversibile.')">
                                    🚀 Invia Campagna (<?php echo $count; ?> destinatari)
                                </button>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- ── RIGHT: Subscriber list ── -->
                <div style="flex:1;min-width:220px;">
                    <div class="card" style="padding:20px;">
                        <h3 style="margin-top:0;">
                            Iscritti
                            <span style="font-size:13px;font-weight:400;color:#888;">(<?php echo $count; ?>)</span>
                        </h3>

                        <?php if ( $subscribers ): ?>
                            <div style="max-height:380px;overflow-y:auto;font-size:12px;line-height:1.7;">
                                <ul style="margin:0;padding-left:14px;">
                                    <?php foreach ( array_slice( $subscribers, 0, 60 ) as $s ): ?>
                                        <li><?php echo esc_html( $s->email ); ?></li>
                                    <?php endforeach; ?>
                                    <?php if ( $count > 60 ): ?>
                                        <li style="color:#888;list-style:none;margin-top:4px;">
                                            … e altri <strong><?php echo $count - 60; ?></strong>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>

                            <!-- Export CSV -->
                            <div style="margin-top:12px;border-top:1px solid #eee;padding-top:12px;">
                                <a href="<?php echo wp_nonce_url(
                                    admin_url( 'admin-post.php?action=rp_export_csv&module_id=' . $selected_mod ),
                                    'rp_export_csv'
                                ); ?>" class="button button-small">
                                    ⬇ Esporta CSV
                                </a>
                            </div>
                        <?php else: ?>
                            <p style="color:#888;font-size:13px;">Nessun iscritto trovato per questo modulo.</p>
                        <?php endif; ?>
                    </div>
                </div>

            </div><!-- /flex -->
        </div><!-- /wrap -->
        <?php
    }

    // ── HANDLE SEND ───────────────────────────────────────────────────────────

    public function handle_send() {
        if ( ! current_user_can( 'manage_options' ) ||
             ! check_admin_referer( 'rp_send_campaign', 'rp_nonce' ) ) {
            wp_die( 'Accesso non autorizzato.' );
        }

        $action_type = sanitize_key( $_POST['action_type'] ?? 'send' );
        $module_id   = intval( $_POST['module_id'] ?? 0 );
        $subject     = sanitize_text_field( $_POST['subject'] ?? '' );
        // Raw HTML: niente wp_kses_post qui. Il body puo contenere DOCTYPE,
        // <html>, <head>, <style>, <table> inline CSS — tutto cio che serve
        // per un email template reale. La pagina e gated da manage_options
        // + nonce: solo admin autenticati possono POSTare qui. Il body non
        // viene mai renderizzato nel contesto del sito (va dritto a wp_mail).
        $body        = wp_unslash( (string) ( $_POST['body'] ?? '' ) );
        $rate_limit  = intval( $_POST['rate_limit'] ?? 200000 );

        if ( empty( $subject ) || empty( $body ) ) {
            wp_redirect( add_query_arg(
                [ 'page' => 'rp-campaign-tool', 'rp_error' => 'empty_fields', 'module_id' => $module_id ],
                admin_url( 'tools.php' )
            ) );
            exit;
        }

        // Salva per ripopolare il form.
        set_transient( 'rp_last_subject', $subject, HOUR_IN_SECONDS );
        set_transient( 'rp_last_body',    $body,    HOUR_IN_SECONDS );

        $subscribers = $this->get_subscribers( $module_id );

        // ── PREVIEW ──
        if ( $action_type === 'preview' ) {
            $first        = $subscribers[0] ?? null;
            $preview_body = $first ? $this->personalize( $body, $first ) : $body;
            set_transient( 'rp_preview_html', $preview_body, 5 * MINUTE_IN_SECONDS );
            wp_redirect( add_query_arg(
                [ 'page' => 'rp-campaign-tool', 'rp_preview' => '1', 'module_id' => $module_id ],
                admin_url( 'tools.php' )
            ) );
            exit;
        }

        // ── SEND ──
        $sent    = 0;
        $failed  = 0;
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        @set_time_limit( 0 );
        @ignore_user_abort( true );
        if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

        foreach ( $subscribers as $subscriber ) {
            $personalized = $this->personalize( $body, $subscriber );
            $ok = wp_mail( $subscriber->email, $subject, $personalized, $headers );
            $ok ? $sent++ : $failed++;
            if ( $rate_limit > 0 ) usleep( $rate_limit );
        }

        wp_redirect( add_query_arg(
            [
                'page'      => 'rp-campaign-tool',
                'rp_sent'   => $sent,
                'rp_failed' => $failed,
                'module_id' => $module_id,
            ],
            admin_url( 'tools.php' )
        ) );
        exit;
    }

    // ── HANDLE EXPORT CSV ─────────────────────────────────────────────────────

    public function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ||
             ! check_admin_referer( 'rp_export_csv' ) ) {
            wp_die( 'Accesso non autorizzato.' );
        }

        $module_id   = intval( $_GET['module_id'] ?? 0 );
        $subscribers = $this->get_subscribers( $module_id );
        $filename    = 'hustle-subscribers-' . gmdate( 'Ymd-Hi' ) . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );

        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // BOM UTF-8 per Excel
        fputcsv( $out, [ 'entry_id', 'email', 'nome', 'modulo', 'data_iscrizione' ] );

        foreach ( $subscribers as $s ) {
            fputcsv( $out, [
                $s->entry_id,
                $s->email,
                $s->display_name ?? '',
                $s->module_id,
                $s->date_created,
            ] );
        }

        fclose( $out );
        exit;
    }

    // ── NOTICES ───────────────────────────────────────────────────────────────

    public function show_notices() {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'rp-campaign-tool' ) === false ) return;

        if ( isset( $_GET['rp_sent'] ) ) {
            $sent   = intval( $_GET['rp_sent'] );
            $failed = intval( $_GET['rp_failed'] ?? 0 );
            $msg    = "✅ Campagna inviata: <strong>{$sent}</strong> email consegnate a SES";
            if ( $failed ) {
                $msg .= ", <strong>{$failed}</strong> fallite (controlla il log WP Mail SMTP)";
            }
            echo "<div class='notice notice-success is-dismissible'><p>{$msg}.</p></div>";
        }

        if ( isset( $_GET['rp_error'] ) && $_GET['rp_error'] === 'empty_fields' ) {
            echo "<div class='notice notice-error is-dismissible'><p>❌ Oggetto o corpo email mancante.</p></div>";
        }
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    /**
     * Sostituisce i placeholder nel corpo email.
     */
    private function personalize( string $body, object $subscriber ): string {
        $name = ! empty( $subscriber->display_name )
            ? esc_html( $subscriber->display_name )
            : 'Amico';
        return str_replace( '{{first_name}}', $name, $body );
    }
}

new RP_Campaign_Tool();
