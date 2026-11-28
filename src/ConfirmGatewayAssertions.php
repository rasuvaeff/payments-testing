<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\ConfirmGatewayInterface;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\PaymentsTesting\Internal\AttemptAssertions;

/**
 * @api
 */
final class ConfirmGatewayAssertions
{
    public static function assertConfirmPayment(
        PaymentGatewayInterface&ConfirmGatewayInterface $gateway,
        PaymentOperationRequest $request,
    ): PaymentAttempt {
        $attempt = $gateway->confirmPayment($request);
        AttemptAssertions::payment(
            attempt: $attempt,
            provider: $gateway->provider(),
            operationId: $request->operationId,
            payment: $request->payment,
        );

        return $attempt;
    }

    /**
     * A replayed confirm must be answered from the first one: the same
     * state, without running the operation again (each call is also checked
     * by assertConfirmPayment(), so both carry the requested payment
     * reference). The key reused for another payment is not probed — that
     * needs a second payment.
     */
    public static function assertConfirmPaymentIdempotency(
        PaymentGatewayInterface&ConfirmGatewayInterface $gateway,
        PaymentOperationRequest $request,
    ): PaymentAttempt {
        if ($request->idempotencyKey === null) {
            throw new \InvalidArgumentException('Idempotency assertion requires an idempotency key');
        }

        $first = self::assertConfirmPayment($gateway, $request);
        $second = self::assertConfirmPayment($gateway, $request);

        if ($second->state !== $first->state) {
            throw new ContractViolationException('A replayed confirm reported another state');
        }

        return $second;
    }

    private function __construct() {}
}
