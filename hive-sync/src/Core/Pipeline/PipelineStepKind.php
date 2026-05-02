<?php
declare(strict_types=1);

namespace HiveSync\Core\Pipeline;

enum PipelineStepKind: string
{
    case Operation  = 'operation';      // post-import: mutates an existing product (productId)
    case ImportRule = 'import_rule';    // during import: mutates the in-flight FeedItem draft
    case PreCheck   = 'pre_check';      // pre-import: validates a FeedItem before materialize
    case Check      = 'check';          // post-import: validates an existing product (productId)
}
