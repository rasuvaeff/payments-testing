<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\RefundReason;
use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\Payments\RetrieveRefundRequest;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;
use Rasuvaeff\PaymentsTesting\Internal\FakeGatewayCore;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(FakeGatewayCore::class)]
final class FakeGatewayCoreTest
{
    private FakeGatewayCore $core;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->core = new FakeGatewayCore(new FakeGatewayConfig());
    }

    public function derivesPaymentReferencesFromOperationAndSequence(): void
    {
        $first = $this->core->createPayment(Fixtures::createPayment(idempotencyKey: null));
        $second = $this->core->createPayment(Fixtures::createPayment(idempotencyKey: null));

        Assert::same($first->payment->id, 'pay_5c5f82b6c923448c');
        Assert::same($second->payment->id, 'pay_811f7ffabb1615be');
        Assert::same($first->payment->kind, 'payment');
    }

    public function derivesRefundReferencesFromOperationAndSequence(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;
        $refund = $this->core->createRefund(new CreateRefundRequest(
            operationId: new OperationId(value: 'refund-1'),
            payment: $payment,
        ));

        Assert::same($refund->refund->id, 'ref_abdd6650f0131c35');
        Assert::same($refund->refund->kind, 'refund');
    }

    public function reportsFakeRawStatuses(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment());
        $refund = $this->core->createRefund(new CreateRefundRequest(
            operationId: new OperationId(value: 'refund-1'),
            payment: $payment->payment,
        ));

        Assert::same($payment->rawStatus, 'fake_succeeded');
        Assert::same($refund->rawStatus, 'fake_succeeded');
        Assert::same($payment->requestInfo->requestId, 'fake-request');
    }

    public function createsAPaymentWithoutAPaymentMethod(): void
    {
        $attempt = $this->withoutPhpDiagnostics(fn() => $this->core->createPayment(new CreatePaymentRequest(
            operationId: new OperationId(value: 'operation-1'),
            amount: new Money(minorUnits: 100, currency: 'EUR'),
        )));

        Assert::same($attempt->payment->id, 'pay_5c5f82b6c923448c');
    }

    public function createsARefundWithAndWithoutAReason(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;
        $plain = $this->withoutPhpDiagnostics(fn() => $this->core->createRefund(new CreateRefundRequest(
            operationId: new OperationId(value: 'refund-1'),
            payment: $payment,
        )));
        $reasoned = $this->core->createRefund(new CreateRefundRequest(
            operationId: new OperationId(value: 'refund-2'),
            payment: $payment,
            reason: new RefundReason(value: 'duplicate'),
        ));

        Assert::same($plain->refund->id, 'ref_abdd6650f0131c35');
        Assert::true($reasoned->refund->id !== $plain->refund->id);
    }

    public function refusesCaptureKeyReusedForAnotherPayment(): void
    {
        $first = $this->core->createPayment(Fixtures::createPayment(operation: 'operation-1', idempotencyKey: 'a'))->payment;
        $second = $this->core->createPayment(Fixtures::createPayment(operation: 'operation-2', idempotencyKey: 'b'))->payment;
        $this->core->capturePayment($this->capture($first, 'capture-key'));

        Expect::exception(\InvalidArgumentException::class)->withMessage('Idempotency key was reused with a different fake request');
        $this->core->capturePayment($this->capture($second, 'capture-key'));
    }

    public function refusesCaptureKeyReusedWithAnotherCurrency(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;
        $this->core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 100, currency: 'EUR')));

        Expect::exception(\InvalidArgumentException::class)->withMessage('Idempotency key was reused with a different fake request');
        $this->core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 100, currency: 'USD')));
    }

    public function replaysCaptureWithTheSameKeyAndRequest(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;
        $first = $this->core->capturePayment($this->capture($payment, 'capture-key'));
        $second = $this->core->capturePayment($this->capture($payment, 'capture-key'));

        Assert::same($second->payment, $first->payment);
    }

    #[ExpectNoAssertions]
    public function doesNotTrackCapturesWithoutAKey(): void
    {
        $first = $this->core->createPayment(Fixtures::createPayment(operation: 'operation-1', idempotencyKey: 'a'))->payment;
        $second = $this->core->createPayment(Fixtures::createPayment(operation: 'operation-2', idempotencyKey: 'b'))->payment;

        $this->core->capturePayment($this->capture($first, null, new Money(minorUnits: 100, currency: 'EUR')));
        $this->core->capturePayment($this->capture($second, null, new Money(minorUnits: 200, currency: 'EUR')));
    }

    #[ExpectNoAssertions]
    public function scopesOperationKeysByOperation(): void
    {
        $first = $this->core->createPayment(Fixtures::createPayment(operation: 'operation-1', idempotencyKey: 'a'))->payment;
        $second = $this->core->createPayment(Fixtures::createPayment(operation: 'operation-2', idempotencyKey: 'b'))->payment;

        $this->core->capturePayment($this->capture($first, 'shared-key'));
        $this->core->confirmPayment($this->operation($second, 'shared-key'));
        $this->core->cancelPayment($this->operation($second, 'shared-key'));
    }

    public function refusesConfirmKeyReusedForAnotherPayment(): void
    {
        $first = $this->core->createPayment(Fixtures::createPayment(operation: 'operation-1', idempotencyKey: 'a'))->payment;
        $second = $this->core->createPayment(Fixtures::createPayment(operation: 'operation-2', idempotencyKey: 'b'))->payment;
        $this->core->confirmPayment($this->operation($first, 'confirm-key'));

        Expect::exception(\InvalidArgumentException::class)->withMessage('Idempotency key was reused with a different fake request');
        $this->core->confirmPayment($this->operation($second, 'confirm-key'));
    }

    public function refusesCancelKeyReusedForAnotherPayment(): void
    {
        $first = $this->core->createPayment(Fixtures::createPayment(operation: 'operation-1', idempotencyKey: 'a'))->payment;
        $second = $this->core->createPayment(Fixtures::createPayment(operation: 'operation-2', idempotencyKey: 'b'))->payment;
        $this->core->cancelPayment($this->operation($first, 'cancel-key'));

        Expect::exception(\InvalidArgumentException::class)->withMessage('Idempotency key was reused with a different fake request');
        $this->core->cancelPayment($this->operation($second, 'cancel-key'));
    }

    public function refusesRefundKeyReusedForAnotherPayment(): void
    {
        $first = $this->core->createPayment(Fixtures::createPayment(operation: 'operation-1', idempotencyKey: 'a'))->payment;
        $second = $this->core->createPayment(Fixtures::createPayment(operation: 'operation-2', idempotencyKey: 'b'))->payment;
        $this->core->createRefund(new CreateRefundRequest(
            operationId: new OperationId(value: 'refund-1'),
            payment: $first,
            idempotencyKey: 'refund-key',
        ));

        Expect::exception(\InvalidArgumentException::class)->withMessage('Idempotency key was reused with a different fake request');
        $this->core->createRefund(new CreateRefundRequest(
            operationId: new OperationId(value: 'refund-2'),
            payment: $second,
            idempotencyKey: 'refund-key',
        ));
    }

    public function rejectsRefundReferenceOfAnotherProvider(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Reference provider does not match fake gateway provider');
        $this->core->retrieveRefund(new RetrieveRefundRequest(
            operationId: new OperationId(value: 'retrieve-1'),
            refund: new RefundReference(provider: new PaymentProvider(value: 'other'), id: 'ref_1'),
        ));
    }

    public function rejectsUnknownRefundReference(): void
    {
        Expect::exception(\OutOfBoundsException::class)->withMessage('Unknown fake refund reference');
        $this->core->retrieveRefund(new RetrieveRefundRequest(
            operationId: new OperationId(value: 'retrieve-1'),
            refund: new RefundReference(provider: $this->core->config->provider, id: 'missing'),
        ));
    }

    /**
     * Reading a property of null is a warning, not an exception: turn any PHP diagnostic
     * into a failure so a missing null-safe operator cannot pass unnoticed.
     *
     * @template T
     * @param \Closure(): T $call
     * @return T
     */
    private function withoutPhpDiagnostics(\Closure $call): mixed
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    private function capture(PaymentReference $payment, ?string $key, ?Money $amount = null): CapturePaymentRequest
    {
        return new CapturePaymentRequest(
            operationId: new OperationId(value: 'capture-1'),
            payment: $payment,
            amount: $amount,
            idempotencyKey: $key,
        );
    }

    private function operation(PaymentReference $payment, string $key): PaymentOperationRequest
    {
        return new PaymentOperationRequest(
            operationId: new OperationId(value: 'operation-x'),
            payment: $payment,
            idempotencyKey: $key,
        );
    }
}
