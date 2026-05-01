<?php
declare(strict_types=1);

namespace HiveSync\Core;

final class RunResult {

    public function __construct(
        public readonly bool $ok,
        public readonly int $itemsTotal = 0,
        public readonly int $itemsDone = 0,
        public readonly int $itemsFailed = 0,
        public readonly array $report = [],
        public readonly ?string $error = null,
    ) {}

    public static function ok( int $total = 0, int $done = 0, int $failed = 0, array $report = [] ): self {
        return new self( true, $total, $done, $failed, $report );
    }

    public static function fail( string $error, array $report = [] ): self {
        return new self( false, 0, 0, 0, $report, $error );
    }
}
