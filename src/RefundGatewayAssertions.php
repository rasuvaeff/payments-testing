<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\RefundAttempt;
use Rasuvaeff\Payments\RefundGatewayInterface;
use Rasuvaeff\Payments\RetrieveRefundRequest;
use Rasuvaeff\PaymentsTesting\Internal\AttemptAssertions;

/**
 * @api
 */
final class RefundGatewayAssertions
{
    public static function assertCreateRefund(
        PaymentGatewayInterface&RefundGatewayInterface $gateway,
        CreateRefundRequest $request,
    ): RefundAttempt {
        $attempt = $gateway->createRefund($request);
        AttemptAssertions::refund(
            attempt: $attempt,
            provider: $gateway->provider(),
            operationId: $request->operationId,
            payment: $request->payment,
            amount: $request->amount,
        );

        return $attempt;
    }

    public static function assertCreateRefundIdempotency(
        PaymentGatewayInterface&RefundGatewayInterface $gateway,
        CreateRefundRequest $request,
    ): RefundAttempt {
        if ($request->idempotencyKey === null) {
            throw new \InvalidArgumentException('Idempotency assertion requires an idempotency key');
        }

        $first = self::assertCreateRefund($gateway, $request);
        $second = self::assertCreateRefund($gateway, $request);
        AttemptAssertions::same($second->refund, $first->refund, 'Refund idempotency key produced another reference');

        return $second;
    }

    public static function assertRetrieveRefund(
        PaymentGatewayInterface&RefundGatewayInterface $gateway,
        RetrieveRefundRequest $request,
    ): RefundAttempt {
        $attempt = $gateway->retrieveRefund($request);
        AttemptAssertions::refund(
            attempt: $attempt,
            provider: $gateway->provider(),
            operationId: $request->operationId,
            refund: $request->refund,
        );

        return $attempt;
    }

    private function __construct() {}
}
