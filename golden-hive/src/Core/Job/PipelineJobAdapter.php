<?php
declare(strict_types=1);

namespace GH\Core\Job;

/**
 * Bridge between the new Pipeline executor and the existing job runner
 * (includes/jobs/). Registers two job kinds via gh_jobs_register_kind():
 *
 *   - pipeline.run    : { selection, pipeline_id, options }
 *                       Run a saved pipeline against a Selection.
 *
 *   - source.import   : { source_id, fetch_args, pipeline_id?, options }
 *                       Fetch from a Source, optionally apply a pre-import
 *                       pipeline, materialize into the catalog.
 *
 * Implementation deferred to Batch 2. This class is the seam: the rest of
 * the codebase only ever talks to "the Jobs system" via these two kinds,
 * so adding a new feed or a new pipeline never adds a new job kind.
 */
final class PipelineJobAdapter
{
    public function register(): void
    {
        // Batch 2:
        //   gh_jobs_register_kind('pipeline.run',   [ 'handler' => [$this, 'runPipeline'] ]);
        //   gh_jobs_register_kind('source.import',  [ 'handler' => [$this, 'runSourceImport'] ]);
    }
}
