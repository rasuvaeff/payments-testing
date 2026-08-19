<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\Payments\RetrieveRefundRequest;
use Rasuvaeff\PaymentsTesting\CancelGatewayAssertions;
use Rasuvaeff\PaymentsTesting\CaptureGatewayAssertions;
use Rasuvaeff\PaymentsTesting\ConfirmGatewayAssertions;
use Rasuvaeff\PaymentsTesting\ContractViolationException;
use Rasuvaeff\PaymentsTesting\RefundGatewayAssertions;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CaptureGatewayAssertions::class)]
#[Covers(ConfirmGatewayAssertions::class)]
#[Covers(CancelGatewayAssertions::class)]
#[Covers(RefundGatewayAssertions::class)]
final class OptionalGatewayAssertionsTest
{
    public function acceptsOptionalPaymentOperations(): void
    {
        $gateway = new FixtureGateway();
        $payment = new PaymentReference($gateway->provider(), 'pay_1');

        $captured = CaptureGatewayAssertions::assertCapturePayment(
            $gateway,
            new CapturePaymentRequest(new OperationId('capture-1'), $payment, new Money(50, 'EUR')),
        );
        $confirmed = ConfirmGatewayAssertions::assertConfirmPayment(
            $gateway,
            new PaymentOperationRequest(new OperationId('confirm-1'), $payment),
        );
        $canceled = CancelGatewayAssertions::assertCancelPayment(
            $gateway,
            new PaymentOperationRequest(new OperationId('cancel-1'), $payment),
        );

        Assert::same($captured->amount->minorUnits, 50);
        Assert::same($captured->payment->id, 'capture_capture-1');
        Assert::same($captured->payment->kind, 'capture');
        Assert::same($confirmed->payment, $payment);
        Assert::same($canceled->payment, $payment);
    }

    public function acceptsRefundCreateRetrieveAndIdempotency(): void
    {
        $gateway = new FixtureGateway();
        $payment = new PaymentReference($gateway->provider(), 'pay_1');
        $request = new CreateRefundRequest(
            operationId: new OperationId('refund-1'),
            payment: $payment,
            amount: new Money(25, 'EUR'),
            idempotencyKey: 'refund-key',
        );
        $refund = RefundGatewayAssertions::assertCreateRefundIdempotency($gateway, $request);
        $retrieved = RefundGatewayAssertions::assertRetrieveRefund(
            $gateway,
            new RetrieveRefundRequest(new OperationId('retrieve-refund-1'), $refund->refund),
        );

        Assert::same($retrieved->refund->id, 'ref_refund-key');
    }

    public function detectsRefundGatewayIgnoringTheIdempotencyKey(): void
    {
        $gateway = new ConstantRefundGateway();

        Expect::exception(ContractViolationException::class)
            ->withMessage('A different idempotency key returned the original refund reference');
        RefundGatewayAssertions::assertCreateRefundIdempotency($gateway, self::keyedRefund());
    }

    public function detectsRefundGatewayAcceptingKeyReuse(): void
    {
        $gateway = new ConstantRefundGateway(acceptsKeyReuse: true);

        Expect::exception(ContractViolationException::class)
            ->withMessage('Reusing a refund idempotency key with a different request was accepted');
        RefundGatewayAssertions::assertCreateRefundIdempotency($gateway, self::keyedRefund());
    }

    public function refusesRefundIdempotencyAssertionWithoutAKey(): void
    {
        $gateway = new FixtureGateway();

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('idempotency key');
        RefundGatewayAssertions::assertCreateRefundIdempotency($gateway, new CreateRefundRequest(
            operationId: new OperationId('refund-1'),
            payment: new PaymentReference($gateway->provider(), 'pay_1'),
            amount: new Money(25, 'EUR'),
        ));
    }

    public function issuesTheDocumentedRefundIdempotencyProbes(): void
    {
        $gateway = new FixtureGateway();
        RefundGatewayAssertions::assertCreateRefundIdempotency($gateway, new CreateRefundRequest(
            operationId: new OperationId('refund-1'),
            payment: new PaymentReference($gateway->provider(), 'pay_1'),
            amount: new Money(25, 'EUR'),
            idempotencyKey: 'refund-key',
        ));

        Assert::same($gateway->refundsReceived, [
            ['refund-key', 25],
            ['refund-key', 25],
            ['refund-key-contract-other', 25],
            ['refund-key', 24],
        ]);
    }

    /**
     * A one-unit refund is legal input, and stepping down from it would ask
     * for zero — an amount a gateway may refuse on its own grounds, which
     * would let an ordinary validation error pass for idempotency
     * enforcement. The probe steps up instead.
     */
    public function stepsTheRefundReuseProbeUpFromTheSmallestAmount(): void
    {
        $gateway = new FixtureGateway();
        RefundGatewayAssertions::assertCreateRefundIdempotency($gateway, new CreateRefundRequest(
            operationId: new OperationId('refund-1'),
            payment: new PaymentReference($gateway->provider(), 'pay_1'),
            amount: new Money(1, 'EUR'),
            idempotencyKey: 'refund-key',
        ));

        Assert::same($gateway->refundsReceived, [
            ['refund-key', 1],
            ['refund-key', 1],
            ['refund-key-contract-other', 1],
            ['refund-key', 2],
        ]);
    }

    private static function keyedRefund(): CreateRefundRequest
    {
        return new CreateRefundRequest(
            operationId: new OperationId('refund-1'),
            payment: new PaymentReference(new PaymentProvider('fake'), 'pay_1'),
            amount: new Money(25, 'EUR'),
            idempotencyKey: 'refund-key',
        );
    }

    public function detectsCaptureOperationIdViolation(): void
    {
        $gateway = new BrokenFixtureGateway();

        Expect::exception(ContractViolationException::class)
            ->withMessage('Payment attempt did not preserve operation id');
        CaptureGatewayAssertions::assertCapturePayment(
            $gateway,
            new CapturePaymentRequest(
                new OperationId('capture-1'),
                new PaymentReference($gateway->provider(), 'pay_1'),
                new Money(50, 'EUR'),
            ),
        );
    }

    public function detectsConfirmOperationIdViolation(): void
    {
        $gateway = new BrokenFixtureGateway();

        Expect::exception(ContractViolationException::class)
            ->withMessage('Payment attempt did not preserve operation id');
        ConfirmGatewayAssertions::assertConfirmPayment(
            $gateway,
            new PaymentOperationRequest(
                new OperationId('confirm-1'),
                new PaymentReference($gateway->provider(), 'pay_1'),
            ),
        );
    }

    public function detectsCancelOperationIdViolation(): void
    {
        $gateway = new BrokenFixtureGateway();

        Expect::exception(ContractViolationException::class)
            ->withMessage('Payment attempt did not preserve operation id');
        CancelGatewayAssertions::assertCancelPayment(
            $gateway,
            new PaymentOperationRequest(
                new OperationId('cancel-1'),
                new PaymentReference($gateway->provider(), 'pay_1'),
            ),
        );
    }

    public function detectsCreateRefundOperationIdViolation(): void
    {
        $gateway = new BrokenFixtureGateway();

        Expect::exception(ContractViolationException::class)
            ->withMessage('Refund attempt did not preserve operation id');
        RefundGatewayAssertions::assertCreateRefund(
            $gateway,
            new CreateRefundRequest(
                operationId: new OperationId('refund-1'),
                payment: new PaymentReference($gateway->provider(), 'pay_1'),
                amount: new Money(25, 'EUR'),
            ),
        );
    }

    public function detectsRetrieveRefundOperationIdViolation(): void
    {
        $gateway = new BrokenFixtureGateway();

        Expect::exception(ContractViolationException::class)
            ->withMessage('Refund attempt did not preserve operation id');
        RefundGatewayAssertions::assertRetrieveRefund(
            $gateway,
            new RetrieveRefundRequest(
                new OperationId('retrieve-refund-1'),
                new RefundReference($gateway->provider(), 'ref_1'),
            ),
        );
    }

    public function detectsRefundIdempotencyViolation(): void
    {
        $gateway = new BrokenFixtureGateway(breaksIdempotency: true);

        Expect::exception(ContractViolationException::class)
            ->withMessage('Refund idempotency key produced another reference');
        RefundGatewayAssertions::assertCreateRefundIdempotency(
            $gateway,
            new CreateRefundRequest(
                operationId: new OperationId('refund-1'),
                payment: new PaymentReference($gateway->provider(), 'pay_1'),
                amount: new Money(25, 'EUR'),
                idempotencyKey: 'refund-key',
            ),
        );
    }

    public function refundIdempotencyAssertionRequiresKey(): void
    {
        $gateway = new FixtureGateway();

        Expect::exception(\InvalidArgumentException::class);
        RefundGatewayAssertions::assertCreateRefundIdempotency(
            $gateway,
            new CreateRefundRequest(
                new OperationId('refund-2'),
                new PaymentReference($gateway->provider(), 'pay_1'),
            ),
        );
    }
}
