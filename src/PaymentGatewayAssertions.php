<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\PartialRefundCapability;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\RefundGatewayInterface;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\PaymentsTesting\Internal\AttemptAssertions;

/**
 * @api
 */
final class PaymentGatewayAssertions
{
    public static function assertCapabilities(PaymentGatewayInterface $gateway): CapabilitySet
    {
        $capabilities = $gateway->capabilities();

        if ($capabilities->has(PartialRefundCapability::class) && !$gateway instanceof RefundGatewayInterface) {
            throw new ContractViolationException('Partial refund capability requires RefundGatewayInterface');
        }

        return $capabilities;
    }

    public static function assertCreatePayment(PaymentGatewayInterface $gateway, CreatePaymentRequest $request): PaymentAttempt
    {
        $attempt = $gateway->createPayment($request);
        AttemptAssertions::payment(
            attempt: $attempt,
            provider: $gateway->provider(),
            operationId: $request->operationId,
            amount: $request->amount,
        );

        return $attempt;
    }

    /**
     * Verifies all three halves of the idempotency contract.
     *
     * Replaying one key must return the original payment; a different key must
     * start a new one; and reusing a key with a changed request must be
     * refused rather than silently answered with the original. Checking only
     * the replay would pass a gateway that ignores the key altogether and
     * always returns the same reference — the exact defect the assertion is
     * meant to catch.
     *
     * The gateway is exercised for real, so this creates payments: run it
     * against fakes or a sandbox, never against a live account.
     */
    public static function assertCreatePaymentIdempotency(PaymentGatewayInterface $gateway, CreatePaymentRequest $request): PaymentAttempt
    {
        $key = $request->idempotencyKey;

        if ($key === null) {
            throw new \InvalidArgumentException('Idempotency assertion requires an idempotency key');
        }

        $first = self::assertCreatePayment($gateway, $request);
        $second = self::assertCreatePayment($gateway, $request);
        AttemptAssertions::same($second->payment, $first->payment, 'Payment idempotency key produced another reference');

        $otherKey = self::assertCreatePayment($gateway, self::withKey(request: $request, key: $key . '-contract-other'));

        if ($otherKey->payment->id === $first->payment->id) {
            throw new ContractViolationException('A different idempotency key returned the original payment reference');
        }

        self::assertKeyReuseRejected($gateway, $request, $key);

        return $second;
    }

    /**
     * Reusing a key with a different request must not be answered from the
     * original: that would charge the customer for one amount and report
     * another.
     */
    private static function assertKeyReuseRejected(
        PaymentGatewayInterface $gateway,
        CreatePaymentRequest $request,
        string $key,
    ): void {
        $conflicting = new CreatePaymentRequest(
            operationId: $request->operationId,
            amount: new Money(
                // Any amount that differs from the original will do. Stepping
                // down at the ceiling keeps the probe inside `Money`'s range:
                // overflowing here would surface as a validation error blamed
                // on the gateway under test.
                minorUnits: $request->amount->minorUnits < PHP_INT_MAX
                    ? $request->amount->minorUnits + 1
                    : $request->amount->minorUnits - 1,
                currency: $request->amount->currency,
            ),
            paymentMethod: $request->paymentMethod,
            customer: $request->customer,
            captureMethod: $request->captureMethod,
            confirmationMethod: $request->confirmationMethod,
            description: $request->description,
            metadata: $request->metadata,
            idempotencyKey: $key,
        );

        try {
            $gateway->createPayment($conflicting);
        } catch (\Throwable) {
            return;
        }

        throw new ContractViolationException('Reusing an idempotency key with a different request was accepted');
    }

    private static function withKey(CreatePaymentRequest $request, string $key): CreatePaymentRequest
    {
        return new CreatePaymentRequest(
            operationId: $request->operationId,
            amount: $request->amount,
            paymentMethod: $request->paymentMethod,
            customer: $request->customer,
            captureMethod: $request->captureMethod,
            confirmationMethod: $request->confirmationMethod,
            description: $request->description,
            metadata: $request->metadata,
            idempotencyKey: $key,
        );
    }

    public static function assertRetrievePayment(PaymentGatewayInterface $gateway, RetrievePaymentRequest $request): PaymentAttempt
    {
        $attempt = $gateway->retrievePayment($request);
        AttemptAssertions::payment(
            attempt: $attempt,
            provider: $gateway->provider(),
            operationId: $request->operationId,
            payment: $request->payment,
        );

        return $attempt;
    }

    private function __construct() {}
}
