<?php
declare(strict_types=1);

namespace HiveSync\Core\Check;

/**
 * Per-check severity. `Block` halts the pipeline (e.g. import refuses
 * to proceed when a critical check fails). `Warn` records the failure
 * and continues. Each Check declares a defaultSeverity() and the
 * pipeline step can override it via params.
 */
enum CheckSeverity: string
{
    case Warn = 'warn';
    case Block = 'block';
}
