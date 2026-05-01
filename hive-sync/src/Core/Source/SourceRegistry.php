<?php
declare(strict_types=1);

namespace HiveSync\Core\Source;

/**
 * Holds the set of registered Sources for the running request. Sources
 * register themselves at boot via the 'hive_sync/core_booted' action.
 * The UI / job layer looks them up by id (e.g. 'gs', 'csv:42').
 */
final class SourceRegistry
{
    /** @var array<string, Source> */
    private array $sources = [];

    public function register(Source $source): void
    {
        $this->sources[$source->id()] = $source;
    }

    public function get(string $id): ?Source
    {
        return $this->sources[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->sources[$id]);
    }

    /** @return Source[] */
    public function all(): array
    {
        return array_values($this->sources);
    }
}
