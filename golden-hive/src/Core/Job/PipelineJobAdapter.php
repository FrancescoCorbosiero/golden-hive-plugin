<?php
declare(strict_types=1);

namespace GH\Core\Job;

use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationRegistry;
use GH\Core\Pipeline\Pipeline;
use GH\Core\Pipeline\PipelineExecutor;
use GH\Core\Pipeline\PipelineRepository;
use GH\Core\Selection\Selection;
use GH\Core\Selection\SelectionMode;
use GH\Core\Source\Context as RunContext;
use GH\Core\Source\SourceRegistry;
use GH\Workflow\Import\SourceImportRunner;

/**
 * Bridges the new Pipeline executor with the legacy job runner. Registers
 * exactly two universal job kinds:
 *
 *   - pipeline.run   : { selection, pipeline_id, options }
 *                      Run a saved pipeline against a Selection. Use case:
 *                      "apply markup pipeline to all Nike products."
 *
 *   - source.import  : { source_id, fetch_args, pipeline_id?, options }
 *                      [Implemented in Batch 4 once a concrete Source ships.]
 *                      Fetch from a Source, optionally apply a pre-import
 *                      pipeline, materialize.
 *
 * Adding a new feed or pipeline never adds a new job kind — params alone
 * differ. That's the whole point of the abstraction.
 */
final class PipelineJobAdapter
{
    public function __construct(
        private readonly PipelineRepository $pipelines,
        private readonly PipelineExecutor $executor,
        private readonly SourceRegistry $sources,
        private readonly OperationRegistry $operations,
    ) {}

    public function register(): void
    {
        if (! function_exists('gh_jobs_register_kind')) {
            return; // legacy job system not loaded — unit-test safe
        }

        \gh_jobs_register_kind('pipeline.run', [
            'label'       => 'Run pipeline',
            'description' => 'Esegue una pipeline salvata su una Selection di prodotti.',
            'handler'     => [$this, 'runPipeline'],
            'params'      => [
                'pipeline_id' => ['type' => 'string', 'required' => true,  'label' => 'Pipeline ID'],
                'selection'   => ['type' => 'json',   'required' => true,  'label' => 'Selection (mode + ids|filter)'],
                'options'     => ['type' => 'json',   'required' => false, 'label' => 'Options'],
            ],
        ]);

        \gh_jobs_register_kind('source.import', [
            'label'       => 'Import from source',
            'description' => 'Fetch + diff + materialize da una Source, applicando una pipeline opzionale di ImportRule.',
            'handler'     => [$this, 'runSourceImport'],
            'params'      => [
                'source_id'   => ['type' => 'string', 'required' => true,  'label' => 'Source ID'],
                'config'      => ['type' => 'json',   'required' => false, 'label' => 'Source config (url/token/...)'],
                'pipeline_id' => ['type' => 'string', 'required' => false, 'label' => 'Pre-import pipeline ID'],
                'options'     => ['type' => 'json',   'required' => false, 'label' => 'Options (dry_run)'],
            ],
        ]);
    }

    /**
     * Job handler for 'pipeline.run'. Contract: see includes/jobs/registry.php
     *
     * @param array $job     full job row from storage
     * @param array $context run_id, job_id, cursor, started_at, deadline, trigger
     * @return array{status: string, summary?: array, cursor?: array, progress?: array, error?: string}
     */
    public function runPipeline(array $job, array $context): array
    {
        $params = (array) ($job['params'] ?? []);
        $pipelineId = (string) ($params['pipeline_id'] ?? '');
        if ($pipelineId === '') {
            return ['status' => 'error', 'error' => 'pipeline_id mancante'];
        }

        $pipeline = $this->pipelines->find($pipelineId);
        if (! $pipeline instanceof Pipeline) {
            return ['status' => 'error', 'error' => "Pipeline non trovata: {$pipelineId}"];
        }

        $selection = self::buildSelection((array) ($params['selection'] ?? []));
        if (! $selection instanceof Selection) {
            return ['status' => 'error', 'error' => 'selection malformata'];
        }

        $deadline = isset($context['deadline']) ? (int) $context['deadline'] : null;
        $base = new RunContext(
            runId: (string) ($context['run_id'] ?? uniqid('run_', true)),
            dryRun: (bool) ($params['options']['dry_run'] ?? false),
            deadline: $deadline,
            meta: [
                'job_id'  => (string) ($context['job_id'] ?? ''),
                'trigger' => (string) ($context['trigger'] ?? ''),
            ],
        );
        $opCtx = new OperationContext(
            base: $base,
            sourceId: $selection->sourceId,
        );

        $cursor = is_array($context['cursor'] ?? null) ? $context['cursor'] : null;

        try {
            $result = $this->executor->execute($pipeline, $selection, $opCtx, $cursor);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        return $result->toJobEnvelope();
    }

    /**
     * Job handler for 'source.import'. Fetches + diffs + materializes
     * via SourceImportRunner; pre-import ImportRule steps from the
     * referenced pipeline are applied to each draft before materialize.
     *
     * Cooperative cursoring: the runner returns a continue cursor when
     * the deadline is hit; the adapter passes it through verbatim so
     * the next tick resumes mid-import.
     */
    public function runSourceImport(array $job, array $context): array
    {
        $params = (array) ($job['params'] ?? []);
        $source_id = (string) ($params['source_id'] ?? '');
        if ($source_id === '') {
            return ['status' => 'error', 'error' => 'source_id mancante'];
        }
        $source = $this->sources->get($source_id);
        if ($source === null) {
            return ['status' => 'error', 'error' => "Sorgente non registrata: {$source_id}"];
        }

        // Optional pipeline (just import rules will be consulted by the
        // runner; post-ops are NOT run in this loop — see class docblock).
        $pipeline_id = (string) ($params['pipeline_id'] ?? '');
        $pipeline    = $pipeline_id !== '' ? $this->pipelines->find($pipeline_id) : null;

        $config = is_array($params['config'] ?? null) ? $params['config'] : [];

        // Hydrate redacted/empty secrets from the credential store.
        // The function exists only when WP is loaded; in tests the helper
        // is absent and config flows through unchanged.
        if (function_exists('gh_v2_hydrate_credentials')) {
            $config = \gh_v2_hydrate_credentials($source_id, $config);
        }

        $deadline = isset($context['deadline']) ? (int) $context['deadline'] : null;
        $base = new RunContext(
            runId: (string) ($context['run_id'] ?? uniqid('run_', true)),
            dryRun: (bool) ($params['options']['dry_run'] ?? false),
            deadline: $deadline,
            meta: [
                'job_id'  => (string) ($context['job_id'] ?? ''),
                'trigger' => (string) ($context['trigger'] ?? ''),
            ],
        );
        $opCtx = new OperationContext(
            base: $base,
            sourceId: $source_id,
        );

        $cursor = is_array($context['cursor'] ?? null) ? $context['cursor'] : null;

        try {
            $runner = new SourceImportRunner($this->operations);
            $result = $runner->run(
                source: $source,
                config: $config,
                pipeline: $pipeline,
                opCtx: $opCtx,
                cursor: $cursor,
            );
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        return $result->toJobEnvelope();
    }

    private static function buildSelection(array $raw): ?Selection
    {
        $sourceId = (string) ($raw['source_id'] ?? '');
        $mode = SelectionMode::tryFrom((string) ($raw['mode'] ?? ''));
        if ($sourceId === '' || $mode === null) {
            return null;
        }
        return new Selection(
            sourceId: $sourceId,
            mode: $mode,
            ids: (array) ($raw['ids'] ?? []),
            filter: (array) ($raw['filter'] ?? []),
        );
    }
}
