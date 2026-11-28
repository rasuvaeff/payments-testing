<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\CaptureGatewayInterface;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentState;
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
     * `capturedAmount` is the gross amount taken, so what a capture must
     * report depends on how it ended. A `Succeeded` capture reports exactly
     * the requested amount, or the payment's `amount` for a capture without
     * one. A `Failed` or `Pending` capture has taken nothing yet: zero, or
     * `null` when the response does not say. Any other outcome
     * (`Processing`) reports `null` or no more than was requested. `amount`
     * itself stays the authorized amount and is not compared — after a
     * partial capture it is larger than what was taken. Run it against a
     * payment with no earlier capture.
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

        $requested = $request->amount ?? $attempt->amount;

        if ($attempt->state === PaymentState::Succeeded) {
            $captured = $attempt->capturedAmount
                ?? throw new ContractViolationException('Capture response did not report the captured amount');
            AttemptAssertions::same($captured, $requested, 'Capture response reported another captured amount than requested');

            return $attempt;
        }

        $captured = $attempt->capturedAmount;

        if (!$captured instanceof Money) {
            return $attempt;
        }

        if ($captured->currency !== $requested->currency) {
            throw new ContractViolationException('Capture response reported the captured amount in another currency');
        }

        if (\in_array($attempt->state, [PaymentState::Failed, PaymentState::Pending], strict: true) && !$captured->isZero()) {
            throw new ContractViolationException(\sprintf('A %s capture reported money as captured', $attempt->state->value));
        }

        if ($captured->isGreaterThan($requested)) {
            throw new ContractViolationException('Capture response reported more captured than requested');
        }

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
        // The probe changes what was asked for; what was taken depends on how
        // the capture ended and is checked by assertCapturePayment().
        $captured = $request->amount ?? $first->amount;

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
