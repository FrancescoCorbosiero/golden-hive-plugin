<?php
/**
 * UI helpers — snippet HTML riutilizzabili per l'admin.
 *
 * Eliminano ~20 copy-paste nei view file. Tutti output escaped (text via
 * esc_html, class via esc_attr). L'icona viene lasciata letterale per
 * supportare HTML entity (es. '&#9881;') e SVG inline.
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'gh_empty_state' ) ) return;

/**
 * Render di un empty-state standard.
 *
 * @param string $icon       HTML entity o SVG inline (NON escapato).
 * @param string $text       Testo principale. Escapato.
 * @param string $extraClass Classe CSS aggiuntiva. Escapata.
 * @return string
 */
function gh_empty_state( string $icon, string $text, string $extraClass = '' ): string {
    $cls = trim( 'empty-state ' . $extraClass );
    return sprintf(
        '<div class="%s"><div class="empty-icon">%s</div><div class="empty-text">%s</div></div>',
        esc_attr( $cls ),
        $icon,
        esc_html( $text )
    );
}

/**
 * Render di un chip di stato unificato (sostituisce em-st-*, st-*, gh-job-chip-*).
 *
 * @param string $label
 * @param string $variant 'ok' | 'err' | 'warn' | 'info' | 'dim'
 * @return string
 */
function gh_status_chip( string $label, string $variant = 'dim' ): string {
    $allowed = [ 'ok', 'err', 'warn', 'info', 'dim' ];
    if ( ! in_array( $variant, $allowed, true ) ) $variant = 'dim';
    return sprintf(
        '<span class="gh-status gh-status--%s">%s</span>',
        esc_attr( $variant ),
        esc_html( $label )
    );
}
