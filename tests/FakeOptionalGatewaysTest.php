<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\IdempotencyConflictException;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PartialRefundCapability;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\RetrieveRefundRequest;
use Rasuvaeff\PaymentsTesting\CancelGatewayAssertions;
use Rasuvaeff\PaymentsTesting\CaptureGatewayAssertions;
use Rasuvaeff\PaymentsTesting\ConfirmGatewayAssertions;
use Rasuvaeff\PaymentsTesting\FakeCancelGateway;
use Rasuvaeff\PaymentsTesting\FakeCaptureGateway;
use Rasuvaeff\PaymentsTesting\FakeConfirmGateway;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;
use Rasuvaeff\PaymentsTesting\FakeRefundGateway;
use Rasuvaeff\PaymentsTesting\Internal\AbstractFakeGateway;
use Rasuvaeff\PaymentsTesting\Internal\AttemptAssertions;
use Rasuvaeff\PaymentsTesting\Internal\FakeGatewayCore;
use Rasuvaeff\PaymentsTesting\RefundGatewayAssertions;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FakeCaptureGateway::class)]
#[Covers(FakeConfirmGateway::class)]
#[Covers(FakeCancelGateway::class)]
#[Covers(FakeRefundGateway::class)]
#[Covers(AbstractFakeGateway::class)]
#[Covers(AttemptAssertions::class)]
#[Covers(FakeGatewayCore::class)]
final class FakeOptionalGatewaysTest
{
    public function captureFakeSatisfiesContract(): void
    {
        $gateway = new FakeCaptureGateway();
        $payment = $gateway->createPayment(Fixtures::authorizePayment())->payment;
        $attempt = CaptureGatewayAssertions::assertCapturePayment(
            gateway: $gateway,
            request: new CapturePaymentRequest(
                operationId: new OperationId(value: 'capture-1'),
                payment: $payment,
                amount: new Money(minorUnits: 600, currency: 'EUR'),
                idempotencyKey: 'capture-key',
            ),
        );

        Assert::same($attempt->amount->minorUnits, 1_200);
        Assert::same($attempt->capturedAmount?->minorUnits, 600);
        Assert::same($attempt->state, PaymentState::Succeeded);
    }

    public function captureRejectsChangedFingerprintForSameOperationKey(): void
    {
        $gateway = new FakeCaptureGateway();
        $payment = $gateway->createPayment(Fixtures::authorizePayment())->payment;
        $operationId = new OperationId(value: 'capture-1');
        $gateway->capturePayment(new CapturePaymentRequest(
            operationId: $operationId,
            payment: $payment,
            amount: new Money(minorUnits: 600, currency: 'EUR'),
            idempotencyKey: 'capture-key',
        ));

        Expect::exception(IdempotencyConflictException::class)->withMessageContaining('Idempotency key');
        $gateway->capturePayment(new CapturePaymentRequest(
            operationId: $operationId,
            payment: $payment,
            amount: new Money(minorUnits: 601, currency: 'EUR'),
            idempotencyKey: 'capture-key',
        ));
    }

    public function confirmFakeSatisfiesContract(): void
    {
        $gateway = new FakeConfirmGateway();
        $payment = $gateway->createPayment(Fixtures::pendingPayment())->payment;
        $attempt = ConfirmGatewayAssertions::assertConfirmPayment(
            gateway: $gateway,
            request: new PaymentOperationRequest(
                operationId: new OperationId(value: 'confirm-1'),
                payment: $payment,
                idempotencyKey: 'confirm-key',
            ),
        );

        Assert::same($attempt->state, PaymentState::Succeeded);
        Assert::same($attempt->capturedAmount?->minorUnits, 1_200);
    }

    public function cancelFakeSatisfiesContract(): void
    {
        $gateway = new FakeCancelGateway();
        $payment = $gateway->createPayment(Fixtures::authorizePayment())->payment;
        $attempt = CancelGatewayAssertions::assertCancelPayment(
            gateway: $gateway,
            request: new PaymentOperationRequest(
                operationId: new OperationId(value: 'cancel-1'),
                payment: $payment,
                idempotencyKey: 'cancel-key',
            ),
        );

        Assert::same($attempt->state, PaymentState::Canceled);
    }

    public function refundFakeSatisfiesCreateRetrieveAndIdempotencyContracts(): void
    {
        $gateway = new FakeRefundGateway(new FakeGatewayConfig(
            capabilities: CapabilitySet::of(new PartialRefundCapability()),
        ));
        $payment = $gateway->createPayment(Fixtures::createPayment())->payment;
        $created = RefundGatewayAssertions::assertCreateRefundIdempotency(
            gateway: $gateway,
            request: new CreateRefundRequest(
                operationId: new OperationId(value: 'refund-1'),
                payment: $payment,
                amount: new Money(minorUnits: 300, currency: 'EUR'),
                idempotencyKey: 'refund-key',
            ),
        );
        $retrieved = RefundGatewayAssertions::assertRetrieveRefund(
            gateway: $gateway,
            request: new RetrieveRefundRequest(
                operationId: new OperationId(value: 'retrieve-refund-1'),
                refund: $created->refund,
            ),
        );

        Assert::same($retrieved->refund, $created->refund);
        Assert::same($retrieved->actualAmount?->minorUnits, 300);
    }

    /**
     * A deferred payment is paid by the customer outside the API; the fake
     * reaches the state the webhook reports through simulateTransition().
     */
    public function simulatesWhatHappensWithoutAnApiCall(): void
    {
        $gateway = new FakeRefundGateway();
        $payment = $gateway->createPayment(new CreatePaymentRequest(
            operationId: new OperationId(value: 'deferred-1'),
            amount: new Money(minorUnits: 1_200, currency: 'EUR'),
        ))->payment;

        Assert::same($gateway->simulateTransition($payment, PaymentState::RequiresAction)->state, PaymentState::RequiresAction);
        $paid = $gateway->simulateTransition($payment, PaymentState::Succeeded, new OperationId(value: 'webhook-1'));
        $default = $gateway->simulateTransition($payment, PaymentState::Succeeded);

        Assert::same($paid->operationId->value, 'webhook-1');
        Assert::same($paid->capturedAmount?->minorUnits, 1_200);
        Assert::same($default->operationId->value, 'fake-transition');
        Assert::same($gateway->createRefund(new CreateRefundRequest(
            operationId: new OperationId(value: 'refund-1'),
            payment: $payment,
        ))->requestedAmount->minorUnits, 1_200);
    }

    public function captureFakeRejectsPartialRefundCapability(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Partial refund capability requires FakeRefundGateway');
        new FakeCaptureGateway(new FakeGatewayConfig(
            capabilities: CapabilitySet::of(new PartialRefundCapability()),
        ));
    }

    public function confirmFakeRejectsPartialRefundCapability(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Partial refund capability requires FakeRefundGateway');
        new FakeConfirmGateway(new FakeGatewayConfig(
            capabilities: CapabilitySet::of(new PartialRefundCapability()),
        ));
    }

    public function cancelFakeRejectsPartialRefundCapability(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Partial refund capability requires FakeRefundGateway');
        new FakeCancelGateway(new FakeGatewayConfig(
            capabilities: CapabilitySet::of(new PartialRefundCapability()),
        ));
    }
}
