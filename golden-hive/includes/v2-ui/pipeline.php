<?php
/**
 * v2 Workflow tab — pipeline AJAX bridge.
 *
 * Three endpoints, all read+write the wp_options['gh_pipelines'] list
 * via PipelineRepository (which sits on top of gh_option_list_*):
 *
 *   - gh_v2_pipeline_save   : create-or-update a pipeline by id
 *   - gh_v2_pipeline_list   : list saved pipelines (id + name + step_count)
 *   - gh_v2_pipeline_load   : full pipeline by id
 *
 * Save validates each step via Workflow\Pipeline\StepBuilder (pure PHP)
 * AND additionally checks that every step's refId resolves in the
 * matching registry (operation/check). Saving an unknown refId would
 * produce a pipeline that fails at run time — better to reject early.
 *
 * Run-as-job lands in Batch 5d.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_gh_v2_pipeline_save', function (): void {
    if ( function_exists( 'gh_ajax_guard' ) ) {
        gh_ajax_guard();
    } else {
        check_ajax_referer( 'gh_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    if ( ! class_exists( '\\GH\\Core\\Bootstrap' ) || ! \GH\Core\Bootstrap::isBooted() ) {
        wp_send_json_error( [ 'message' => 'v2 core not booted' ], 500 );
    }

    $id    = function_exists( 'gh_ajax_text' ) ? gh_ajax_text( 'id' )    : '';
    $name  = function_exists( 'gh_ajax_text' ) ? gh_ajax_text( 'name' )  : '';
    $steps = function_exists( 'gh_ajax_json' ) ? gh_ajax_json( 'steps' ) : [];

    if ( $name === '' ) {
        wp_send_json_error( [ 'message' => 'Nome pipeline mancante' ], 400 );
    }
    if ( ! is_array( $steps ) || count( $steps ) === 0 ) {
        wp_send_json_error( [ 'message' => 'Pipeline senza step' ], 400 );
    }

    $built = \GH\Workflow\Pipeline\StepBuilder::manyFromArray( $steps );
    if ( ! $built['ok'] ) {
        wp_send_json_error( [
            'message'     => 'Step non validi',
            'step_errors' => $built['errors'],
        ], 422 );
    }

    // refId resolution against the live registries.
    $resolveErrors = [];
    foreach ( $built['steps'] as $idx => $step ) {
        $kind = $step->kind->value;
        $ok   = match ( $kind ) {
            'operation', 'import_rule' => \GH\Core\Bootstrap::$operations->has( $step->refId ),
            'check'                    => \GH\Core\Bootstrap::$checks->has( $step->refId ),
            default                    => false,
        };
        if ( ! $ok ) {
            $resolveErrors[ $idx ] = [ 'ref_id' => 'unknown_in_registry' ];
        }
    }
    if ( $resolveErrors ) {
        wp_send_json_error( [
            'message'     => 'Step con refId non registrati',
            'step_errors' => $resolveErrors,
        ], 422 );
    }

    $pipeline = new \GH\Core\Pipeline\Pipeline(
        id: $id,
        name: $name,
        steps: $built['steps'],
    );
    $stored_id = \GH\Core\Bootstrap::$pipelines->save( $pipeline );

    wp_send_json_success( [
        'id'   => $stored_id,
        'name' => $name,
    ] );
} );

add_action( 'wp_ajax_gh_v2_pipeline_list', function (): void {
    if ( function_exists( 'gh_ajax_guard' ) ) {
        gh_ajax_guard();
    } else {
        check_ajax_referer( 'gh_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    if ( ! class_exists( '\\GH\\Core\\Bootstrap' ) || ! \GH\Core\Bootstrap::isBooted() ) {
        wp_send_json_error( [ 'message' => 'v2 core not booted' ], 500 );
    }

    $items = array_map( static function ( \GH\Core\Pipeline\Pipeline $p ): array {
        return [
            'id'         => $p->id,
            'name'       => $p->name,
            'step_count' => count( $p->steps ),
        ];
    }, \GH\Core\Bootstrap::$pipelines->all() );

    wp_send_json_success( [ 'pipelines' => array_values( $items ) ] );
} );

add_action( 'wp_ajax_gh_v2_pipeline_load', function (): void {
    if ( function_exists( 'gh_ajax_guard' ) ) {
        gh_ajax_guard();
    } else {
        check_ajax_referer( 'gh_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    if ( ! class_exists( '\\GH\\Core\\Bootstrap' ) || ! \GH\Core\Bootstrap::isBooted() ) {
        wp_send_json_error( [ 'message' => 'v2 core not booted' ], 500 );
    }

    $id = function_exists( 'gh_ajax_text' ) ? gh_ajax_text( 'id' ) : '';
    if ( $id === '' ) {
        wp_send_json_error( [ 'message' => 'id mancante' ], 400 );
    }
    $p = \GH\Core\Bootstrap::$pipelines->find( $id );
    if ( ! $p ) {
        wp_send_json_error( [ 'message' => 'Pipeline non trovata' ], 404 );
    }

    $steps = array_map( static fn( $s ): array => [
        'kind'   => $s->kind->value,
        'ref_id' => $s->refId,
        'params' => $s->params,
        'note'   => $s->note,
    ], $p->steps );

    wp_send_json_success( [
        'pipeline' => [
            'id'    => $p->id,
            'name'  => $p->name,
            'steps' => $steps,
            'meta'  => $p->meta,
        ],
    ] );
} );
