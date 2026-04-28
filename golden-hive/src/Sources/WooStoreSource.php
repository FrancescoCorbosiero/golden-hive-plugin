<?php
declare(strict_types=1);

namespace GH\Sources;

use GH\Core\Source\AbstractSource;
use GH\Core\Source\Context;
use GH\Core\Source\Diff;
use GH\Core\Source\FeedItem;
use GH\Core\Source\FetchRequest;
use GH\Core\Source\FetchResult;
use GH\Core\Source\MaterializeResult;
use GH\Core\Source\SourceCapabilities;

/**
 * The local WooCommerce catalog exposed as a Source.
 *
 * Purpose: when the user wants to run an Operation/Check pipeline against
 * existing products (the v2 replacement for Filter & Bulk), the Selection
 * is sourced from here. fetch/diff/materialize are not meaningful — the
 * catalog is already local. Capabilities make that explicit so the UI
 * can hide non-applicable controls (no fetch button, no import options).
 *
 * id='woostore' is reserved for this source — concrete feeds (gs, sf,
 * csv:N, kicksdb) will not collide with it.
 */
final class WooStoreSource extends AbstractSource
{
    public const ID = 'woostore';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Catalogo locale';
    }

    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities(
            canFetch: false,            // catalog is already in WC
            canDiff: false,
            canMaterialize: false,
            canSelectLocal: true,       // the whole point of this source
            supportsQuickPatch: false,
            supportsImageSideload: false,
        );
    }

    public function configSchema(): array
    {
        return []; // no per-source configuration — local catalog has no credentials
    }

    public function fetch(FetchRequest $request, Context $ctx): FetchResult
    {
        // Honest "this isn't supported" rather than silent empty result —
        // the executor never calls fetch() on a Source whose capabilities
        // say canFetch=false, so reaching here is a programming error.
        throw new \LogicException(
            'WooStoreSource::fetch() — local catalog does not fetch. '
            . 'Use Selection::fromIds() or Selection::fromFilter() against this source.'
        );
    }

    public function diff(array $items, Context $ctx): Diff
    {
        return new Diff(); // not applicable — there is no remote to diff against
    }

    public function materialize(FeedItem $item, Context $ctx): MaterializeResult
    {
        return MaterializeResult::skipped(null, 'woostore-no-materialize');
    }
}
