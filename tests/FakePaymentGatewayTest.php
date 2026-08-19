<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CaptureGatewayInterface;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PartialRefundCapability;
use Rasuvaeff\Payments\PaymentMethodReference;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\RefundGatewayInterface;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;
use Rasuvaeff\PaymentsTesting\FakePaymentGateway;
use Rasuvaeff\PaymentsTesting\PaymentGatewayAssertions;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FakePaymentGateway::class)]
#[Covers(FakeGatewayConfig::class)]
final class FakePaymentGatewayTest
{
    public function implementsOnlyBaseGatewayByDefault(): void
    {
        $gateway = new FakePaymentGateway();

        Assert::false($gateway instanceof CaptureGatewayInterface);
        Assert::false($gateway instanceof RefundGatewayInterface);
        Assert::same($gateway->provider()->value, 'fake');
    }

    public function satisfiesCreateRetrieveAndIdempotencyContracts(): void
    {
        $gateway = new FakePaymentGateway();
        $created = PaymentGatewayAssertions::assertCreatePaymentIdempotency($gateway, Fixtures::createPayment());
        $retrieved = PaymentGatewayAssertions::assertRetrievePayment(
            gateway: $gateway,
            request: new RetrievePaymentRequest(
                operationId: new OperationId(value: 'retrieve-1'),
                payment: $created->payment,
            ),
        );

        Assert::same($retrieved->payment, $created->payment);
        Assert::same($retrieved->createdAt->format(DATE_ATOM), '2025-01-01T00:00:00+00:00');
    }

    public function createsDistinctReferencesWithoutIdempotencyKey(): void
    {
        $gateway = new FakePaymentGateway();
        $first = $gateway->createPayment(Fixtures::createPayment(operation: 'operation-1', idempotencyKey: null));
        $second = $gateway->createPayment(Fixtures::createPayment(operation: 'operation-1', idempotencyKey: null));

        Assert::true($first->payment->id !== $second->payment->id);
    }

    public function rejectsIdempotencyKeyWithDifferentFingerprint(): void
    {
        $gateway = new FakePaymentGateway();
        $gateway->createPayment(Fixtures::createPayment());

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Idempotency key');
        $gateway->createPayment(new CreatePaymentRequest(
            operationId: new OperationId(value: 'operation-1'),
            amount: new Money(minorUnits: 1_201, currency: 'EUR'),
            paymentMethod: new PaymentMethodReference(id: 'pm_1'),
            idempotencyKey: 'key-1',
        ));
    }

    public function replaysIdempotencyKeyAcrossOperations(): void
    {
        $gateway = new FakePaymentGateway();
        $first = $gateway->createPayment(Fixtures::createPayment(operation: 'operation-1', idempotencyKey: 'shared-key'));
        $second = $gateway->createPayment(Fixtures::createPayment(operation: 'operation-2', idempotencyKey: 'shared-key'));

        // The provider keys the replay on the header value alone; it never
        // learns which application operation issued the call. Two operations
        // sharing one key is an application bug, and the fake must expose it
        // the way the real gateway will — by answering the second call from
        // the first payment rather than quietly opening a second one.
        Assert::same($second->payment->id, $first->payment->id);
    }

    public function refusesSharedKeyAcrossOperationsWhenTheRequestDiffers(): void
    {
        $gateway = new FakePaymentGateway();
        $gateway->createPayment(Fixtures::createPayment(operation: 'operation-1', idempotencyKey: 'shared-key'));

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Idempotency key');
        $gateway->createPayment(new CreatePaymentRequest(
            operationId: new OperationId(value: 'operation-2'),
            amount: new Money(minorUnits: 9_999, currency: 'EUR'),
            paymentMethod: new PaymentMethodReference(id: 'pm_1', kind: 'card'),
            idempotencyKey: 'shared-key',
        ));
    }

    public function rejectsUnknownAndForeignReferences(): void
    {
        $gateway = new FakePaymentGateway();

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('provider');
        $gateway->retrievePayment(new RetrievePaymentRequest(
            operationId: new OperationId(value: 'retrieve-1'),
            payment: new PaymentReference(provider: new PaymentProvider(value: 'other'), id: 'pay_1'),
        ));
    }

    public function rejectsUnknownSameProviderReference(): void
    {
        $gateway = new FakePaymentGateway();

        Expect::exception(\OutOfBoundsException::class);
        $gateway->retrievePayment(new RetrievePaymentRequest(
            operationId: new OperationId(value: 'retrieve-1'),
            payment: new PaymentReference(provider: $gateway->provider(), id: 'missing'),
        ));
    }

    public function supportsDeterministicConfiguration(): void
    {
        $gateway = new FakePaymentGateway(new FakeGatewayConfig(
            provider: new PaymentProvider(value: 'sandbox'),
            now: new \DateTimeImmutable('2030-05-01T10:00:00+00:00'),
            createState: PaymentState::RequiresAction,
        ));
        $attempt = $gateway->createPayment(Fixtures::createPayment());

        Assert::same($attempt->provider->value, 'sandbox');
        Assert::same($attempt->state, PaymentState::RequiresAction);
        Assert::same($attempt->createdAt->format(DATE_ATOM), '2030-05-01T10:00:00+00:00');
    }

    public function forbidsRefundCapabilityOnBaseFake(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('FakeRefundGateway');
        new FakePaymentGateway(new FakeGatewayConfig(
            capabilities: CapabilitySet::of(new PartialRefundCapability()),
        ));
    }
}
