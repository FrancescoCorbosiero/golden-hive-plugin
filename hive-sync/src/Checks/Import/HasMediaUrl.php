<?php
declare(strict_types=1);

namespace HiveSync\Checks\Import;

use HiveSync\Checks\Support\Severity;
use HiveSync\Core\Check\CheckResult;
use HiveSync\Core\Check\CheckSeverity;
use HiveSync\Core\Check\ImportCheck;
use HiveSync\Core\Source\FeedItem;

/**
 * Pre-import gate: at least one media URL is present in the FeedItem.
 * Doesn't HEAD-check the URL (latency too high for an import gate);
 * the user's brief says "missing media", not "unreachable media".
 *
 * Default severity is warn — a missing image isn't necessarily fatal
 * (some catalogs ship images later in a separate sync). Set to block
 * if you want to refuse import for unimaged rows.
 *
 * params:
 *   fields     string  csv           default 'featured_image,image,image_full_url,gallery'
 *   severity   enum    warn|block    default warn
 */
final class HasMediaUrl implements ImportCheck
{
    public const ID = 'import.has_media_url';

    public function id(): string { return self::ID; }
    public function label(): string { return 'URL media presente'; }

    public function paramsSchema(): array
    {
        return [
            'fields'   => [
                'type'    => 'text',
                'label'   => 'Campi candidati (csv)',
                'default' => 'featured_image,image,image_full_url,gallery,images',
            ],
            'severity' => Severity::paramSpec( 'warn' ),
        ];
    }

    public function defaultSeverity(): CheckSeverity
    {
        return CheckSeverity::Warn;
    }

    public function evaluate( FeedItem $item, array $params ): CheckResult
    {
        $sev    = Severity::fromParams( $params, $this->defaultSeverity() );
        $fields = (string) ( $params['fields'] ?? 'featured_image,image,image_full_url,gallery,images' );
        $candidates = array_values( array_filter( array_map( 'trim', explode( ',', $fields ) ) ) );

        foreach ( $candidates as $f ) {
            $v = $item->data[ $f ] ?? null;
            if ( is_string( $v ) && $v !== '' ) return CheckResult::pass();
            if ( is_array( $v ) ) {
                foreach ( $v as $u ) {
                    if ( is_string( $u ) && $u !== '' ) return CheckResult::pass();
                }
            }
        }

        return CheckResult::fail(
            "Nessun URL media in {$fields}",
            $sev,
            [ 'sku' => $item->sku ],
        );
    }
}
