<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CancelGatewayInterface;
use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CaptureGatewayInterface;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\ConfirmGatewayInterface;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProviderRequestInfo;
use Rasuvaeff\Payments\RefundAttempt;
use Rasuvaeff\Payments\RefundGatewayInterface;
use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\Payments\RefundState;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\Payments\RetrieveRefundRequest;

final class FixtureGateway implements
    PaymentGatewayInterface,
    CaptureGatewayInterface,
    ConfirmGatewayInterface,
    CancelGatewayInterface,
    RefundGatewayInterface
{
    /**
     * Every create request the double received, as `[idempotency key, amount]`.
     *
     * Lets a test pin exactly which probes the contract assertion issues.
     *
     * @var list<array{string|null, int}>
     */
    public array $received = [];

    /** @var array<string, string> */
    private array $idempotency = [];

    /**
     * Every refund request the double received, as `[idempotency key, amount]`.
     *
     * @var list<array{string|null, int}>
     */
    public array $refundsReceived = [];

    /** @var array<string, string> */
    private array $refundIdempotency = [];

    public function __construct(private readonly ?CapabilitySet $capabilities = null) {}

    #[\Override]
    public function provider(): PaymentProvider
    {
        return new PaymentProvider('fake');
    }

    #[\Override]
    public function capabilities(): CapabilitySet
    {
        return $this->capabilities ?? CapabilitySet::of();
    }

    #[\Override]
    public function createPayment(CreatePaymentRequest $request): PaymentAttempt
    {
        $key = $request->idempotencyKey;
        $this->received[] = [$key, $request->amount->minorUnits];

        if ($key !== null) {
            $fingerprint = $request->amount->minorUnits . '|' . $request->amount->currency;
            $seen = $this->idempotency[$key] ?? null;

            if ($seen !== null && $seen !== $fingerprint) {
                throw new \InvalidArgumentException('Idempotency key was reused with a different request');
            }

            $this->idempotency[$key] = $fingerprint;
        }

        return $this->paymentAttempt(
            operationId: $request->operationId,
            reference: new PaymentReference($this->provider(), 'pay_' . ($key ?? $request->operationId->value)),
            amount: $request->amount,
        );
    }

    #[\Override]
    public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
    {
        return $this->paymentAttempt($request->operationId, $request->payment, new Money(100, 'EUR'));
    }

    #[\Override]
    public function capturePayment(CapturePaymentRequest $request): PaymentAttempt
    {
        return $this->paymentAttempt(
            $request->operationId,
            new PaymentReference($this->provider(), 'capture_' . $request->operationId->value, 'capture'),
            $request->amount ?? new Money(100, 'EUR'),
        );
    }

    #[\Override]
    public function confirmPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        return $this->paymentAttempt($request->operationId, $request->payment, new Money(100, 'EUR'));
    }

    #[\Override]
    public function cancelPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        return $this->paymentAttempt($request->operationId, $request->payment, new Money(100, 'EUR'), PaymentState::Canceled);
    }

    #[\Override]
    public function createRefund(CreateRefundRequest $request): RefundAttempt
    {
        $amount = $request->amount ?? new Money(100, 'EUR');
        $key = $request->idempotencyKey;
        $this->refundsReceived[] = [$key, $amount->minorUnits];

        if ($key !== null) {
            $fingerprint = $amount->minorUnits . '|' . $amount->currency . '|' . $request->reason?->value;
            $seen = $this->refundIdempotency[$key] ?? null;

            if ($seen !== null && $seen !== $fingerprint) {
                throw new \InvalidArgumentException('Refund idempotency key was reused with a different request');
            }

            $this->refundIdempotency[$key] = $fingerprint;
        }

        return $this->refundAttempt(
            operationId: $request->operationId,
            refund: new RefundReference($this->provider(), 'ref_' . ($key ?? $request->operationId->value)),
            payment: $request->payment,
            amount: $amount,
        );
    }

    #[\Override]
    public function retrieveRefund(RetrieveRefundRequest $request): RefundAttempt
    {
        return $this->refundAttempt(
            operationId: $request->operationId,
            refund: $request->refund,
            payment: new PaymentReference($this->provider(), 'pay_1'),
            amount: new Money(100, 'EUR'),
        );
    }

    private function paymentAttempt(
        OperationId $operationId,
        PaymentReference $reference,
        Money $amount,
        PaymentState $state = PaymentState::Succeeded,
    ): PaymentAttempt {
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        return new PaymentAttempt(
            operationId: $operationId,
            provider: $this->provider(),
            payment: $reference,
            amount: $amount,
            state: $state,
            rawStatus: $state->value,
            createdAt: $now,
            updatedAt: $now,
            requestInfo: new ProviderRequestInfo($now),
        );
    }

    private function refundAttempt(
        OperationId $operationId,
        RefundReference $refund,
        PaymentReference $payment,
        Money $amount,
    ): RefundAttempt {
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        return new RefundAttempt(
            operationId: $operationId,
            provider: $this->provider(),
            refund: $refund,
            payment: $payment,
            requestedAmount: $amount,
            actualAmount: $amount,
            state: RefundState::Succeeded,
            rawStatus: 'succeeded',
            createdAt: $now,
            updatedAt: $now,
            requestInfo: new ProviderRequestInfo($now),
        );
    }
}
