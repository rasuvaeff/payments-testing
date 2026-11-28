<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\CancelGatewayInterface;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\PaymentsTesting\Internal\AttemptAssertions;

/**
 * @api
 */
final class CancelGatewayAssertions
{
    public static function assertCancelPayment(
        PaymentGatewayInterface&CancelGatewayInterface $gateway,
        PaymentOperationRequest $request,
    ): PaymentAttempt {
        $attempt = $gateway->cancelPayment($request);
        AttemptAssertions::payment(
            attempt: $attempt,
            provider: $gateway->provider(),
            operationId: $request->operationId,
            payment: $request->payment,
        );

        return $attempt;
    }

    /**
     * A replayed cancel must be answered from the first one: the same
     * state, without running the operation again (each call is also checked
     * by assertCancelPayment(), so both carry the requested payment
     * reference). The key reused for another payment is not probed — that
     * needs a second payment.
     */
    public static function assertCancelPaymentIdempotency(
        PaymentGatewayInterface&CancelGatewayInterface $gateway,
        PaymentOperationRequest $request,
    ): PaymentAttempt {
        if ($request->idempotencyKey === null) {
            throw new \InvalidArgumentException('Idempotency assertion requires an idempotency key');
        }

        $first = self::assertCancelPayment($gateway, $request);
        $second = self::assertCancelPayment($gateway, $request);

        if ($second->state !== $first->state) {
            throw new ContractViolationException('A replayed cancel reported another state');
        }

        return $second;
    }

    private function __construct() {}
}
