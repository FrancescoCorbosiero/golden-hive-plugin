<?php
declare(strict_types=1);

namespace GH\Core\Source;

/**
 * Holds the set of registered Sources for the running request. Sources
 * register themselves at boot (via a hook in Batch 2). The UI/Job layer
 * looks them up by id (e.g. 'gs', 'sf', 'csv:42', 'kicksdb', 'woostore').
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
