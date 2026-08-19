<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
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
        $key = $request->idempotencyKey;

        if ($key === null) {
            throw new \InvalidArgumentException('Idempotency assertion requires an idempotency key');
        }

        $first = self::assertCreateRefund($gateway, $request);
        $second = self::assertCreateRefund($gateway, $request);
        AttemptAssertions::same($second->refund, $first->refund, 'Refund idempotency key produced another reference');

        $otherKey = self::assertCreateRefund($gateway, self::withKey(request: $request, key: $key . '-contract-other'));

        if ($otherKey->refund->id === $first->refund->id) {
            throw new ContractViolationException('A different idempotency key returned the original refund reference');
        }

        self::assertKeyReuseRejected($gateway, $request, $key, $first->requestedAmount);

        return $second;
    }

    /**
     * Reusing a key with a different request must not be answered from the
     * original: that would refund one amount and report another.
     *
     * The probe asks for one minor unit *less* than the original wherever it
     * can. A smaller partial refund is always within what was captured, so a
     * gateway that refuses it can only be refusing the key reuse — an amount
     * above the original would let an ordinary balance check masquerade as
     * idempotency enforcement.
     */
    private static function assertKeyReuseRejected(
        PaymentGatewayInterface&RefundGatewayInterface $gateway,
        CreateRefundRequest $request,
        string $key,
        Money $original,
    ): void {
        $conflicting = new CreateRefundRequest(
            operationId: $request->operationId,
            payment: $request->payment,
            amount: new Money(
                minorUnits: $original->minorUnits > 1 ? $original->minorUnits - 1 : $original->minorUnits + 1,
                currency: $original->currency,
            ),
            reason: $request->reason,
            idempotencyKey: $key,
        );

        try {
            $gateway->createRefund($conflicting);
        } catch (\Throwable) {
            return;
        }

        throw new ContractViolationException('Reusing a refund idempotency key with a different request was accepted');
    }

    private static function withKey(CreateRefundRequest $request, string $key): CreateRefundRequest
    {
        return new CreateRefundRequest(
            operationId: $request->operationId,
            payment: $request->payment,
            amount: $request->amount,
            reason: $request->reason,
            idempotencyKey: $key,
        );
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
