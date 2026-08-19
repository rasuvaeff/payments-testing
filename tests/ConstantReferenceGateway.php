<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProviderRequestInfo;
use Rasuvaeff\Payments\RetrievePaymentRequest;

/**
 * A gateway that ignores the idempotency key entirely and always answers with
 * the same payment.
 *
 * This is the failure the idempotency assertion exists to catch, and it is
 * invisible to a check that only replays one key: replaying agrees, because
 * everything agrees.
 *
 * @param bool $acceptsKeyReuse when true the key is honoured for distinct keys
 *                              but a changed request under a known key is
 *                              silently answered from the original
 */
final readonly class ConstantReferenceGateway implements PaymentGatewayInterface
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
        $id = $this->acceptsKeyReuse
            ? 'pay_' . ($request->idempotencyKey ?? 'none')
            : 'pay_constant';

        return new PaymentAttempt(
            operationId: $request->operationId,
            provider: $this->provider(),
            payment: new PaymentReference($this->provider(), $id),
            amount: $request->amount,
            state: PaymentState::Succeeded,
            rawStatus: 'succeeded',
            createdAt: new \DateTimeImmutable('@1785686400'),
            updatedAt: new \DateTimeImmutable('@1785686400'),
            requestInfo: new ProviderRequestInfo(receivedAt: new \DateTimeImmutable('@1785686400')),
        );
    }

    #[\Override]
    public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
    {
        return $this->createPayment(new CreatePaymentRequest(
            operationId: $request->operationId,
            amount: new \Rasuvaeff\Payments\Money(100, 'EUR'),
        ));
    }
}
