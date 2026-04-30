<?php
/**
 * Build/version tag — readable at runtime from admin UI so the operator
 * can verify which commit the container is actually serving.
 *
 * Resolution order:
 *   1. .git/HEAD relative to the repo root (one level above GH_DIR) →
 *      short SHA + branch. Works in dev (mounted source).
 *   2. mtime of golden-hive.php → "build@YYYY-MM-DD HH:mm". Works on
 *      packaged/zip deploys without .git.
 *
 * Cached in a static so repeated calls within a request are free.
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'gh_get_build_tag' ) ) return;

function gh_get_build_tag(): array {
    static $cached = null;
    if ( $cached !== null ) return $cached;

    $repo_root = rtrim( dirname( rtrim( GH_DIR, '/\\' ) ), '/\\' );
    $git_dir   = $repo_root . '/.git';

    if ( is_dir( $git_dir ) && is_readable( $git_dir . '/HEAD' ) ) {
        $head = trim( (string) @file_get_contents( $git_dir . '/HEAD' ) );
        $sha  = '';
        $branch = '';

        if ( str_starts_with( $head, 'ref: ' ) ) {
            $ref    = substr( $head, 5 );
            $branch = preg_replace( '#^refs/heads/#', '', $ref );
            $ref_path = $git_dir . '/' . $ref;
            if ( is_readable( $ref_path ) ) {
                $sha = trim( (string) @file_get_contents( $ref_path ) );
            } else {
                // Packed refs fallback.
                $packed = $git_dir . '/packed-refs';
                if ( is_readable( $packed ) ) {
                    foreach ( @file( $packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [] as $line ) {
                        if ( $line === '' || $line[0] === '#' || $line[0] === '^' ) continue;
                        if ( str_ends_with( $line, ' ' . $ref ) ) {
                            $sha = trim( strtok( $line, ' ' ) );
                            break;
                        }
                    }
                }
            }
        } else {
            // Detached HEAD: HEAD itself is the SHA.
            $sha    = $head;
            $branch = 'detached';
        }

        if ( $sha !== '' ) {
            return $cached = [
                'source' => 'git',
                'sha'    => $sha,
                'short'  => substr( $sha, 0, 7 ),
                'branch' => $branch,
                'label'  => 'git@' . substr( $sha, 0, 7 ),
            ];
        }
    }

    // Fallback: mtime of the entrypoint file.
    $mtime = @filemtime( GH_DIR . 'golden-hive.php' ) ?: time();
    return $cached = [
        'source' => 'mtime',
        'sha'    => '',
        'short'  => '',
        'branch' => '',
        'label'  => 'build@' . wp_date( 'Y-m-d H:i', $mtime ),
    ];
}
