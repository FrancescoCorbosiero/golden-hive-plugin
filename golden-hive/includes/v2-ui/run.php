<?php
/**
 * v2 Workflow tab — Run AJAX bridge.
 *
 * One endpoint, gh_v2_workflow_run, supporting three modes:
 *
 *   - mode='dry_run'    : create a disabled job + tick now with
 *                         options.dry_run=true. Operations don't apply;
 *                         checks still run (read-only). Result envelope
 *                         shows what WOULD have changed.
 *
 *   - mode='now'        : create a disabled job + tick now. Real run.
 *                         The job stays in storage as a history record
 *                         the user can re-run / inspect / delete from
 *                         the Jobs tab.
 *
 *   - mode='schedule'   : create an enabled job with a real cron
 *                         expression. Don't tick — the WP-Cron / runner
 *                         picks it up at next_run_at.
 *
 * Workflow:
 *   1. Receive {selection, pipeline, mode, schedule_preset, custom_cron}
 *   2. If pipeline.id empty → save it first (PipelineRepository) so the
 *      job can reference it stably. Auto-name with timestamp if needed.
 *   3. Resolve cron via CronPresets ('never' for one-shot modes).
 *   4. gh_jobs_save() with kind='pipeline.run', params, cron, enabled.
 *   5. For dry_run / now: gh_jobs_run_tick($id, 'manual') immediately.
 *   6. Return {job_id, pipeline_id, ran: bool, summary}.
 *
 * The user-visible hand-off (switch to Jobs tab + scroll to job_id) is
 * the JS layer's responsibility.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_gh_v2_workflow_run', function (): void {
    if ( function_exists( 'gh_ajax_guard' ) ) {
        gh_ajax_guard();
    } else {
        check_ajax_referer( 'gh_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    if ( ! class_exists( '\\GH\\Core\\Bootstrap' ) || ! \GH\Core\Bootstrap::isBooted() ) {
        wp_send_json_error( [ 'message' => 'v2 core not booted' ], 500 );
    }
    if ( ! function_exists( 'gh_jobs_save' ) || ! function_exists( 'gh_jobs_run_tick' ) ) {
        wp_send_json_error( [ 'message' => 'Sistema Jobs non disponibile' ], 500 );
    }

    $mode      = function_exists( 'gh_ajax_text' ) ? gh_ajax_text( 'mode' ) : '';
    $selection = function_exists( 'gh_ajax_json' ) ? gh_ajax_json( 'selection' ) : [];
    $pipeline  = function_exists( 'gh_ajax_json' ) ? gh_ajax_json( 'pipeline' )  : [];
    $preset    = function_exists( 'gh_ajax_text' ) ? gh_ajax_text( 'schedule_preset' ) : '';
    $custom    = function_exists( 'gh_ajax_text' ) ? gh_ajax_text( 'custom_cron' ) : '';

    if ( ! in_array( $mode, [ 'dry_run', 'now', 'schedule' ], true ) ) {
        wp_send_json_error( [ 'message' => "mode invalido: {$mode}" ], 400 );
    }

    // ── Selection ──
    $source_id    = (string) ( $selection['source_id'] ?? '' );
    $selection_mode = (string) ( $selection['mode'] ?? 'ids' );
    $ids          = (array)  ( $selection['ids']  ?? [] );
    if ( $source_id === '' ) {
        wp_send_json_error( [ 'message' => 'selection.source_id mancante' ], 400 );
    }
    if ( $selection_mode === 'ids' && empty( $ids ) ) {
        wp_send_json_error( [ 'message' => 'Nessun prodotto selezionato' ], 400 );
    }

    // ── Pipeline ──
    $pipeline_id   = (string) ( $pipeline['id']   ?? '' );
    $pipeline_name = (string) ( $pipeline['name'] ?? '' );
    $steps_raw     = (array)  ( $pipeline['steps'] ?? [] );
    if ( empty( $steps_raw ) ) {
        wp_send_json_error( [ 'message' => 'Pipeline vuota' ], 400 );
    }

    // Build + validate steps. Same path the pipeline_save endpoint uses,
    // so save+run produce identical behavior for the same input.
    $built = \GH\Workflow\Pipeline\StepBuilder::manyFromArray( $steps_raw );
    if ( ! $built['ok'] ) {
        wp_send_json_error( [
            'message'     => 'Step non validi',
            'step_errors' => $built['errors'],
        ], 422 );
    }
    foreach ( $built['steps'] as $idx => $step ) {
        $kind = $step->kind->value;
        $known = match ( $kind ) {
            'operation', 'import_rule' => \GH\Core\Bootstrap::$operations->has( $step->refId ),
            'check'                    => \GH\Core\Bootstrap::$checks->has( $step->refId ),
            default                    => false,
        };
        if ( ! $known ) {
            wp_send_json_error( [
                'message'     => 'Step con refId non registrati',
                'step_errors' => [ $idx => [ 'ref_id' => 'unknown_in_registry' ] ],
            ], 422 );
        }
    }

    // Persist the pipeline so the job can reference it by id. Auto-name
    // if the user clicked Run without saving first.
    if ( $pipeline_name === '' ) {
        $pipeline_name = 'Run ' . wp_date( 'Y-m-d H:i:s' );
    }
    $pipeline_obj = new \GH\Core\Pipeline\Pipeline(
        id: $pipeline_id,
        name: $pipeline_name,
        steps: $built['steps'],
    );
    $stored_pipeline_id = \GH\Core\Bootstrap::$pipelines->save( $pipeline_obj );

    // ── Cron resolution ──
    $is_schedule = ( $mode === 'schedule' );
    $preset_for_cron = $is_schedule ? ( $preset !== '' ? $preset : 'daily' ) : \GH\Workflow\Run\CronPresets::NEVER;
    $cron = \GH\Workflow\Run\CronPresets::toCron( $preset_for_cron, $custom );
    if ( $cron === null ) {
        wp_send_json_error( [ 'message' => 'Espressione cron non valida' ], 400 );
    }

    // ── Job params ──
    $job_params = [
        'pipeline_id' => $stored_pipeline_id,
        'selection'   => [
            'source_id' => $source_id,
            'mode'      => in_array( $selection_mode, [ 'ids', 'filter', 'all' ], true ) ? $selection_mode : 'ids',
            'ids'       => array_values( array_map( 'intval', $ids ) ),
            'filter'    => (array) ( $selection['filter'] ?? [] ),
        ],
        'options' => [
            'dry_run' => ( $mode === 'dry_run' ),
        ],
    ];

    $label = sprintf(
        '[v2] %s — %s (%s)',
        $mode === 'schedule' ? 'Schedule' : ( $mode === 'dry_run' ? 'Dry-run' : 'Run' ),
        $pipeline_name,
        $source_id
    );

    $saved = gh_jobs_save( [
        'kind'    => 'pipeline.run',
        'label'   => $label,
        'params'  => $job_params,
        'cron'    => $cron,
        'enabled' => $is_schedule, // disabled for dry_run / now → no auto-fire
    ] );
    if ( is_wp_error( $saved ) ) {
        wp_send_json_error( [ 'message' => $saved->get_error_message() ], 500 );
    }
    $job_id = (string) $saved['id'];

    // For one-shot modes, fire immediately. The job record stays as
    // history (disabled) so the user can re-run / inspect / delete.
    $tick_result = null;
    if ( ! $is_schedule ) {
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 0 );
        $r = gh_jobs_run_tick( $job_id, 'manual' );
        $tick_result = is_wp_error( $r ) ? [ 'error' => $r->get_error_message() ] : $r;
    }

    wp_send_json_success( [
        'job_id'      => $job_id,
        'pipeline_id' => $stored_pipeline_id,
        'mode'        => $mode,
        'ran'         => ! $is_schedule,
        'tick'        => $tick_result,
        'label'       => $label,
    ] );
} );
