<?php
declare(strict_types=1);

namespace HiveSync\Core\Source;

/**
 * Thrown by a Source when an upstream fetch fails in a way the caller
 * should treat as recoverable — transient HTTP / connection issues
 * that the JS tick loop can retry without operator intervention.
 *
 * Surfacing it as a distinct type lets ImportRunner re-throw transient
 * failures (so the AJAX handler returns recoverable:true and JS
 * retries) while still absorbing non-transient errors as a regular
 * failed envelope.
 *
 * The message should be operator-readable: include the HTTP code or
 * cURL error so the run log explains *why* it failed, not just *that*
 * it failed. The exception class itself carries the recoverability
 * signal — no extra flag needed.
 */
final class TransientSourceException extends \RuntimeException {}
