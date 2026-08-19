<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
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
 * A refund gateway that ignores the idempotency key entirely and always
 * answers with the same refund.
 *
 * This is the failure the refund idempotency assertion exists to catch, and it
 * is invisible to a check that only replays one key: replaying agrees, because
 * everything agrees.
 *
 * @param bool $acceptsKeyReuse when true distinct keys yield distinct refunds,
 *                              but a changed request under a known key is
 *                              silently answered from the original
 */
final readonly class ConstantRefundGateway implements PaymentGatewayInterface, RefundGatewayInterface
{
    public function __construct(private bool $acceptsKeyReuse = false) {}

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
        return $this->paymentAttempt($request->operationId->value, $request->amount);
    }

    #[\Override]
    public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
    {
        return $this->paymentAttempt($request->payment->id, new Money(100, 'EUR'));
    }

    #[\Override]
    public function createRefund(CreateRefundRequest $request): RefundAttempt
    {
        $id = $this->acceptsKeyReuse
            ? 'ref_' . ($request->idempotencyKey ?? 'none')
            : 'ref_constant';
        $amount = $request->amount ?? new Money(100, 'EUR');
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        return new RefundAttempt(
            operationId: $request->operationId,
            provider: $this->provider(),
            refund: new RefundReference($this->provider(), $id),
            payment: $request->payment,
            requestedAmount: $amount,
            actualAmount: $amount,
            state: RefundState::Succeeded,
            rawStatus: 'succeeded',
            createdAt: $now,
            updatedAt: $now,
            requestInfo: new ProviderRequestInfo($now),
        );
    }

    #[\Override]
    public function retrieveRefund(RetrieveRefundRequest $request): RefundAttempt
    {
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        return new RefundAttempt(
            operationId: $request->operationId,
            provider: $this->provider(),
            refund: $request->refund,
            payment: new PaymentReference($this->provider(), 'pay_1'),
            requestedAmount: new Money(100, 'EUR'),
            actualAmount: new Money(100, 'EUR'),
            state: RefundState::Succeeded,
            rawStatus: 'succeeded',
            createdAt: $now,
            updatedAt: $now,
            requestInfo: new ProviderRequestInfo($now),
        );
    }

    private function paymentAttempt(string $id, Money $amount): PaymentAttempt
    {
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        return new PaymentAttempt(
            operationId: new OperationId($id),
            provider: $this->provider(),
            payment: new PaymentReference($this->provider(), 'pay_' . $id),
            amount: $amount,
            state: PaymentState::Succeeded,
            rawStatus: 'succeeded',
            createdAt: $now,
            updatedAt: $now,
            requestInfo: new ProviderRequestInfo($now),
        );
    }
}
