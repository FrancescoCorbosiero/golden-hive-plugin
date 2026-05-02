<?php
declare(strict_types=1);

namespace HiveSync\Checks\Import;

use HiveSync\Checks\Support\Severity;
use HiveSync\Core\Check\CheckResult;
use HiveSync\Core\Check\CheckSeverity;
use HiveSync\Core\Check\ImportCheck;
use HiveSync\Core\Source\FeedItem;

/**
 * Pre-import gate: every FeedItem must carry the listed required
 * fields (default: sku + name). Empty / missing → fail.
 *
 * Severity=block is the typical config — there's no point
 * materializing a row that's missing a SKU. Severity=warn lets the
 * row through and just records the failure for the run report.
 *
 * params:
 *   fields    string  comma-separated  default 'sku,name'
 *   severity  enum    warn|block       default block
 */
final class HasRequiredFields implements ImportCheck
{
    public const ID = 'import.has_required_fields';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Campi richiesti presenti'; }

    public function paramsSchema(): array
    {
        return [
            'fields'   => [
                'type'    => 'text',
                'label'   => 'Campi richiesti (csv)',
                'default' => 'sku,name',
            ],
            'severity' => Severity::paramSpec( 'block' ),
        ];
    }

    public function defaultSeverity(): CheckSeverity
    {
        return CheckSeverity::Block;
    }

    public function evaluate( FeedItem $item, array $params ): CheckResult
    {
        $sev    = Severity::fromParams( $params, $this->defaultSeverity() );
        $fields = (string) ( $params['fields'] ?? 'sku,name' );
        $required = array_values( array_filter( array_map( 'trim', explode( ',', $fields ) ) ) );
        if ( ! $required ) return CheckResult::pass();

        $missing = [];
        foreach ( $required as $f ) {
            $v = $item->data[ $f ] ?? null;
            if ( $v === null || $v === '' || $v === [] ) $missing[] = $f;
        }

        if ( ! $missing ) return CheckResult::pass();
        return CheckResult::fail(
            'Campi mancanti: ' . implode( ', ', $missing ),
            $sev,
            [ 'sku' => $item->sku, 'missing' => $missing ],
        );
    }
}
