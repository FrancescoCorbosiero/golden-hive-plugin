<?php
declare(strict_types=1);

namespace HiveSync\Core\Source;

/**
 * Parameters for Source::fetch(). `config` carries source-specific fields
 * (url/token/file path/api_key/etc.) — its shape is described by
 * Source::configSchema() and validated by AbstractSource before fetch().
 *
 * `options` carries cross-cutting fetch-time flags (force_refresh, limit,
 * since_date, …).
 */
final class FetchRequest
{
    public function __construct(
        public readonly array $config = [],
        public readonly array $options = [],
    ) {}
}
