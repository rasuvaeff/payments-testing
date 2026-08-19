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

/**
 * Deliberately contract-violating gateway. It exists so the assertion helpers
 * can be proven to detect a violation, not merely to pass on a correct gateway.
 */
final class BrokenFixtureGateway implements
    PaymentGatewayInterface,
    CaptureGatewayInterface,
    ConfirmGatewayInterface,
    CancelGatewayInterface,
    RefundGatewayInterface
{
    private int $sequence = 0;

    /**
     * @param bool $breaksIdempotency when true the operation id is preserved and
     *                                every call invents a new reference instead
     */
    public function __construct(private readonly bool $breaksIdempotency = false) {}

    #[\Override]
    public function provider(): PaymentProvider
    {
        return new PaymentProvider('fake');
    }

    #[\Override]
    public function capabilities(): CapabilitySet
    {
        return CapabilitySet::of();
    }

    #[\Override]
    public function createPayment(CreatePaymentRequest $request): PaymentAttempt
    {
        return $this->paymentAttempt(
            operationId: $request->operationId,
            reference: new PaymentReference($this->provider(), 'pay_' . ++$this->sequence),
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
            new PaymentReference($this->provider(), 'capture_1', 'capture'),
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
        return $this->refundAttempt(
            operationId: $request->operationId,
            refund: new RefundReference($this->provider(), 'ref_' . ++$this->sequence),
            payment: $request->payment,
            amount: $request->amount ?? new Money(100, 'EUR'),
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
            operationId: $this->reportedOperationId(requested: $operationId),
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
            operationId: $this->reportedOperationId(requested: $operationId),
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

    private function reportedOperationId(OperationId $requested): OperationId
    {
        return $this->breaksIdempotency ? $requested : new OperationId('foreign-operation');
    }
}
