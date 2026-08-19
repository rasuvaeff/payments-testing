<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Internal;

/**
 * Psalm type aliases shared across the fake gateway internals.
 *
 * @internal
 *
 * @psalm-type IdempotencyEntry = array{
 *     fingerprint: non-empty-string,
 *     reference: non-empty-string
 * }
 */
final class Types
{
    private function __construct() {}
}
