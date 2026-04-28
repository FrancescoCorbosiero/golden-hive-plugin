<?php
declare(strict_types=1);

namespace GH\Core\Source;

/**
 * Threaded through every Source / Operation / Check call. Carries:
 *  - runId        : ties everything to a Job run (audit, log correlation)
 *  - dryRun       : when true, mutations are skipped but reported
 *  - deadline     : unix timestamp; respected by long loops to enable
 *                   cooperative cursoring (job runner resumes next tick)
 *  - meta         : free-form per-run data (trigger='adhoc'|'scheduled', user_id, …)
 */
final class Context
{
    public function __construct(
        public readonly string $runId,
        public readonly bool $dryRun = false,
        public readonly ?int $deadline = null,
        public readonly array $meta = [],
    ) {}

    public function isOverDeadline(): bool
    {
        return $this->deadline !== null && time() >= $this->deadline;
    }

    public function withMeta(array $extra): self
    {
        return new self(
            runId: $this->runId,
            dryRun: $this->dryRun,
            deadline: $this->deadline,
            meta: array_merge($this->meta, $extra),
        );
    }
}
