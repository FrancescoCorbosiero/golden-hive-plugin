<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap. Loads composer autoload only — WordPress is NOT
 * loaded. Pure-PHP behavior (e.g. CsvSource::sfProductNeedsUpdate, the
 * StockFirmati diff comparison core) is verified in isolation; anything
 * guarded by function_exists() stays invisible here by design.
 */

require_once __DIR__ . '/../vendor/autoload.php';
