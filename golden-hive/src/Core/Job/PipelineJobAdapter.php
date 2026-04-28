<?php
declare(strict_types=1);

namespace GH\Core\Job;

use GH\Core\Operation\OperationContext;
use GH\Core\Pipeline\Pipeline;
use GH\Core\Pipeline\PipelineExecutor;
use GH\Core\Pipeline\PipelineRepository;
use GH\Core\Selection\Selection;
use GH\Core\Selection\SelectionMode;
use GH\Core\Source\Context as RunContext;

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

        // source.import lands in Batch 4; placeholder registration kept
        // out so the kinds list doesn't advertise an unimplemented kind.
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
