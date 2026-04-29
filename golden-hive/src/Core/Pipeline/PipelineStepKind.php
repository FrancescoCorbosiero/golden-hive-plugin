<?php
declare(strict_types=1);

namespace GH\Core\Pipeline;

enum PipelineStepKind: string
{
    case Operation = 'operation';
    case ImportRule = 'import_rule';
    case Check = 'check';
}
