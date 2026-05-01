<?php
declare(strict_types=1);

namespace HiveSync\Core;

/**
 * Per-execution data passed to every Runnable. Filled in by the runner.
 * Phase 1: shape only — fields populated as features land.
 */
final class RunContext {

    public function __construct(
        public readonly int $runId,
        public readonly bool $dryRun = false,
        public readonly array $params = [],
    ) {}
}
