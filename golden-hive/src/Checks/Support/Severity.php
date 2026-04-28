<?php
declare(strict_types=1);

namespace GH\Checks\Support;

use GH\Core\Check\CheckSeverity;

/**
 * Per-check severity is declared as `defaultSeverity()` on the class
 * AND exposed as a step param so the user can override per-instance
 * (e.g. ship the same `media.has_images` check as 'warn' in the
 * post-import audit pipeline and 'block' in the import gate).
 *
 * This helper does the param→enum coercion in one place so every
 * Check resolves severity the same way.
 */
final class Severity
{
    public static function fromParams(array $params, CheckSeverity $default): CheckSeverity
    {
        $raw = strtolower(trim((string) ($params['severity'] ?? '')));
        return match ($raw) {
            'block' => CheckSeverity::Block,
            'warn'  => CheckSeverity::Warn,
            default => $default,
        };
    }

    /**
     * Standard `severity` paramsSchema entry, embedded into each check's
     * paramsSchema() so the UI's per-step editor renders the radio
     * automatically.
     */
    public static function paramSpec(string $defaultValue = 'warn'): array
    {
        return [
            'type'    => 'enum',
            'label'   => 'Severita',
            'options' => ['warn', 'block'],
            'default' => $defaultValue,
        ];
    }
}
