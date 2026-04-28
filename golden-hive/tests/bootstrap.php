<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap. Loads composer autoload only — WordPress is NOT
 * loaded. Tests covering WP-dependent code (PipelineRepository, conflict
 * integration, taxonomy pre-cache) live elsewhere as integration tests.
 *
 * The functions guarded by function_exists() in src/ stay invisible
 * here, which is the intended unit-test posture: pure-PHP behavior is
 * verified in isolation.
 */

require_once __DIR__ . '/../vendor/autoload.php';
