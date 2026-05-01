<?php
declare(strict_types=1);

namespace HiveSync\Core\Operation;

final class OperationRegistry
{
    /** @var array<string, Operation> */
    private array $ops = [];

    public function register(Operation $op): void
    {
        $this->ops[$op->id()] = $op;
    }

    public function get(string $id): ?Operation
    {
        return $this->ops[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->ops[$id]);
    }

    /** @return Operation[] */
    public function all(): array
    {
        return array_values($this->ops);
    }

    /** @return ImportRule[] */
    public function importRules(): array
    {
        return array_values(array_filter(
            $this->ops,
            static fn(Operation $o): bool => $o instanceof ImportRule,
        ));
    }
}
