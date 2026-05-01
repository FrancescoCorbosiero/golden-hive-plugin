<?php
declare(strict_types=1);

namespace HiveSync\Core\Check;

final class CheckRegistry
{
    /** @var array<string, Check> */
    private array $checks = [];

    public function register(Check $check): void
    {
        $this->checks[$check->id()] = $check;
    }

    public function get(string $id): ?Check
    {
        return $this->checks[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->checks[$id]);
    }

    /** @return Check[] */
    public function all(): array
    {
        return array_values($this->checks);
    }
}
