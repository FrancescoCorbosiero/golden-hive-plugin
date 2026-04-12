<?php
/**
 * Jobs Registry — pluggable handler registry for schedulable operations.
 *
 * A "kind" is a type of schedulable work. Each kind registers a handler
 * callable that accepts a context array and returns a result envelope.
 *
 * Handler contract:
 *   callable( array $job, array $context ): array
 *
 * Context keys:
 *   - run_id     string   Current run identifier.
 *   - job_id     string   Parent job identifier.
 *   - cursor     mixed    Previous cursor if this is a continuation, else null.
 *   - started_at int      Timestamp when this tick started.
 *   - deadline   int      Hard deadline for this tick (unix ts). Handlers
 *                         should yield when gh_jobs_should_yield() is true.
 *   - trigger    string   'cron' | 'manual' | 'continuation'
 *
 * Result envelope (one of):
 *   [ 'status' => 'done',     'summary'  => array ]
 *   [ 'status' => 'continue', 'cursor'   => mixed, 'progress' => array ]
 *   [ 'status' => 'error',    'error'    => string ]
 *
 * Kind definition (passed to gh_jobs_register_kind):
 *   - label       string    Human label for the UI.
 *   - description string    Optional blurb.
 *   - handler     callable  Executes the work (contract above).
 *   - params      array     Schema-like spec: [ field_name => ['type'=>..., 'label'=>..., 'required'=>bool, 'default'=>...] ]
 *                           Used by the generic edit form to render inputs.
 *                           Devs editing the raw JSON "Code" tab bypass this entirely.
 *
 * Registering a new kind from outside golden-hive:
 *   add_action( 'gh_jobs_register', function () {
 *       gh_jobs_register_kind( 'my_kind', [
 *           'label'   => 'Do a thing',
 *           'handler' => 'my_handler_fn',
 *           'params'  => [ 'target_id' => [ 'type' => 'string', 'required' => true ] ],
 *       ] );
 *   } );
 */

defined( 'ABSPATH' ) || exit;

/** @var array<string, array> Registered job kinds (slug => definition). */
$GLOBALS['gh_jobs_kinds'] = $GLOBALS['gh_jobs_kinds'] ?? [];

/**
 * Registers a job kind.
 *
 * @param string $slug Unique slug (snake_case).
 * @param array  $def  Kind definition — see file header.
 */
function gh_jobs_register_kind( string $slug, array $def ): void {
    if ( ! isset( $def['handler'] ) || ! is_callable( $def['handler'] ) ) {
        // Bail silently in production, log in debug.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "gh_jobs_register_kind: handler missing or not callable for '{$slug}'" );
        }
        return;
    }

    $GLOBALS['gh_jobs_kinds'][ $slug ] = array_merge( [
        'label'       => $slug,
        'description' => '',
        'params'      => [],
    ], $def );
}

/**
 * Returns all registered job kinds.
 *
 * @return array<string, array>
 */
function gh_jobs_get_kinds(): array {
    // Lazy-trigger registration hook the first time this is called in a request.
    static $fired = false;
    if ( ! $fired ) {
        $fired = true;
        do_action( 'gh_jobs_register' );
    }
    return $GLOBALS['gh_jobs_kinds'] ?? [];
}

/**
 * Returns a single registered kind, or null.
 */
function gh_jobs_get_kind( string $slug ): ?array {
    $kinds = gh_jobs_get_kinds();
    return $kinds[ $slug ] ?? null;
}
