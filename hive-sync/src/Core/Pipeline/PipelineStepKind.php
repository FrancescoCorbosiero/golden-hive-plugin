<?php
declare(strict_types=1);

namespace HiveSync\Core\Pipeline;

enum PipelineStepKind: string
{
    case Operation = 'operation';
    case ImportRule = 'import_rule';
    case Check = 'check';
}
