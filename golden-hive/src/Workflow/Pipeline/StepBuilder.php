<?php
declare(strict_types=1);

namespace GH\Workflow\Pipeline;

use GH\Core\Pipeline\PipelineStep;
use GH\Core\Pipeline\PipelineStepKind;

/**
 * Validates and constructs PipelineStep value objects from raw input
 * arrays (e.g. JSON arriving via AJAX). Pure PHP — does not consult
 * any registry. The caller (the AJAX handler) is responsible for
 * checking that refId exists in the matching registry before save.
 *
 * Why a separate class: the AJAX layer needs to produce structured
 * errors (per-step + per-field) so the UI can highlight the offending
 * step. Doing that inline in a handler clutters the AJAX glue and
 * makes the validation logic untestable without WordPress.
 */
final class StepBuilder
{
    /**
     * @param array $raw Single step shape: { kind, ref_id, params?, note? }
     * @return array{ok: bool, step?: PipelineStep, errors?: array<string,string>}
     */
    public static function fromArray(array $raw): array
    {
        $errors = [];

        $kindStr = (string) ($raw['kind'] ?? '');
        $kind = PipelineStepKind::tryFrom($kindStr);
        if ($kind === null) {
            $errors['kind'] = $kindStr === '' ? 'required' : 'invalid';
        }

        $refId = (string) ($raw['ref_id'] ?? '');
        if ($refId === '') {
            $errors['ref_id'] = 'required';
        }

        $paramsRaw = $raw['params'] ?? [];
        if (! is_array($paramsRaw)) {
            $errors['params'] = 'must_be_array';
            $paramsRaw = [];
        }

        $note = $raw['note'] ?? null;
        if ($note !== null && ! is_string($note)) {
            $errors['note'] = 'must_be_string_or_null';
            $note = null;
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        return [
            'ok' => true,
            'step' => new PipelineStep(
                kind: $kind,
                refId: $refId,
                params: $paramsRaw,
                note: $note === '' ? null : $note,
            ),
        ];
    }

    /**
     * Build many steps. Returns either a list of PipelineStep, or a
     * map of per-index errors so the UI can highlight rows.
     *
     * @param array<int, array> $rows
     * @return array{ok: bool, steps?: PipelineStep[], errors?: array<int, array<string,string>>}
     */
    public static function manyFromArray(array $rows): array
    {
        $steps = [];
        $errors = [];
        foreach ($rows as $idx => $row) {
            if (! is_array($row)) {
                $errors[$idx] = ['_root' => 'must_be_array'];
                continue;
            }
            $r = self::fromArray($row);
            if (! $r['ok']) {
                $errors[$idx] = $r['errors'];
                continue;
            }
            $steps[] = $r['step'];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        return ['ok' => true, 'steps' => $steps];
    }
}
