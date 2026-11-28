<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Internal;

/**
 * Psalm type aliases shared across the fake gateway internals.
 *
 * @internal
 *
 * @psalm-type IdempotencyEntry = array{
 *     operation: non-empty-string,
 *     fingerprint: non-empty-string,
 *     response: \Rasuvaeff\Payments\PaymentAttempt|\Rasuvaeff\Payments\RefundAttempt
 * }
 */
final class Types
{
    private function __construct() {}
}
