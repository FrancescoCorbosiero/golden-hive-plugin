<?php
declare(strict_types=1);

namespace HiveSync\Core\Check;

final class CheckResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly CheckSeverity $severity,
        public readonly string $message = '',
        public readonly array $details = [],
    ) {}

    public static function pass(): self
    {
        return new self(passed: true, severity: CheckSeverity::Warn);
    }

    public static function fail(
        string $message,
        CheckSeverity $severity = CheckSeverity::Warn,
        array $details = [],
    ): self {
        return new self(
            passed: false,
            severity: $severity,
            message: $message,
            details: $details,
        );
    }

    /**
     * True when this is a fail AND it should halt the pipeline.
     */
    public function isBlocking(): bool
    {
        return ! $this->passed && $this->severity === CheckSeverity::Block;
    }
}
