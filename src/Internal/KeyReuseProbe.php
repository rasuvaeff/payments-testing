<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Internal;

use Rasuvaeff\Payments\ForbiddenException;
use Rasuvaeff\Payments\RateLimitedException;
use Rasuvaeff\Payments\ServerException;
use Rasuvaeff\Payments\TransportException;
use Rasuvaeff\Payments\UnauthorizedException;

/**
 * @internal
 */
final class KeyReuseProbe
{
    /**
     * Whether the gateway refused a call that reuses an idempotency key with
     * a changed request.
     *
     * Only an answer counts as a refusal. A network failure, a 5xx, a rate
     * limit or rejected credentials say nothing about the key — counting them
     * would let a sandbox timeout pass for idempotency enforcement — so they
     * propagate and the assertion is inconclusive. An `\Error` (a `TypeError`
     * from a broken adapter) is not caught at all.
     *
     * @param \Closure(): mixed $call
     */
    public static function refused(\Closure $call): bool
    {
        try {
            $call();
        } catch (TransportException|ServerException|RateLimitedException|UnauthorizedException|ForbiddenException $inconclusive) {
            throw $inconclusive;
        } catch (\Exception) {
            return true;
        }

        return false;
    }

    private function __construct() {}
}
