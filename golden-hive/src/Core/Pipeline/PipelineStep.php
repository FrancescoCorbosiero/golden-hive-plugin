<?php
declare(strict_types=1);

namespace GH\Core\Pipeline;

/**
 * One step in a Pipeline: a reference to an Operation/ImportRule/Check
 * by id, plus the params chosen by the user when the step was added.
 *
 * `kind` selects which registry the executor consults. The same Operation
 * id can appear as both Operation and ImportRule when the class implements
 * ImportRule (interface inheritance) — `kind` disambiguates which entry
 * point to call.
 */
final class PipelineStep
{
    public function __construct(
        public readonly PipelineStepKind $kind,
        public readonly string $refId,
        public readonly array $params = [],
        public readonly ?string $note = null,
    ) {}
}
