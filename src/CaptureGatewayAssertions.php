<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\CaptureGatewayInterface;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\PaymentsTesting\Internal\AttemptAssertions;
use Rasuvaeff\PaymentsTesting\Internal\KeyReuseProbe;

/**
 * @api
 */
final class CaptureGatewayAssertions
{
    /**
     * The payment reference is not compared with the request's: a provider
     * may answer a capture with a reference of its own (PayPal returns the
     * capture, not the order).
     *
     * A capture response must report `capturedAmount`: the requested amount,
     * or the payment's `amount` for a capture without one. `amount` itself
     * stays the authorized amount and is not compared — after a partial
     * capture it is larger than what was taken. Run it against a payment
     * with no earlier capture.
     */
    public static function assertCapturePayment(
        PaymentGatewayInterface&CaptureGatewayInterface $gateway,
        CapturePaymentRequest $request,
    ): PaymentAttempt {
        $attempt = $gateway->capturePayment($request);
        AttemptAssertions::payment(
            attempt: $attempt,
            provider: $gateway->provider(),
            operationId: $request->operationId,
        );

        $captured = $attempt->capturedAmount
            ?? throw new ContractViolationException('Capture response did not report the captured amount');
        AttemptAssertions::same($captured, $request->amount ?? $attempt->amount, 'Capture response reported another captured amount than requested');

        return $attempt;
    }

    /**
     * A replayed capture must be answered from the first one — same
     * reference, state and amount, no second capture — and the key reused
     * with a changed amount must be refused.
     *
     * The changed amount is one minor unit less than the first capture
     * (more when that is 0 or 1). A gateway that refuses partial captures on
     * its own grounds passes this half without enforcing the key.
     *
     * It captures for real: run it against fakes or a sandbox.
     */
    public static function assertCapturePaymentIdempotency(
        PaymentGatewayInterface&CaptureGatewayInterface $gateway,
        CapturePaymentRequest $request,
    ): PaymentAttempt {
        $key = $request->idempotencyKey;

        if ($key === null) {
            throw new \InvalidArgumentException('Idempotency assertion requires an idempotency key');
        }

        $first = self::assertCapturePayment($gateway, $request);
        $second = self::assertCapturePayment($gateway, $request);
        AttemptAssertions::same($second->payment, $first->payment, 'Capture idempotency key produced another payment reference');
        AttemptAssertions::same($second->amount, $first->amount, 'A replayed capture reported another amount');
        $captured = $first->capturedAmount ?? throw new ContractViolationException('Capture response did not report the captured amount');

        if ($second->state !== $first->state) {
            throw new ContractViolationException('A replayed capture reported another state');
        }

        $conflicting = new CapturePaymentRequest(
            operationId: $request->operationId,
            payment: $request->payment,
            amount: new Money(
                minorUnits: $captured->minorUnits > 1 ? $captured->minorUnits - 1 : $captured->minorUnits + 1,
                currency: $captured->currency,
            ),
            idempotencyKey: $key,
        );

        if (!KeyReuseProbe::refused(static fn(): PaymentAttempt => $gateway->capturePayment($conflicting))) {
            throw new ContractViolationException('Reusing a capture idempotency key with a different request was accepted');
        }

        return $second;
    }

    private function __construct() {}
}
