<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentException;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\RefundFailedException;
use Rasuvaeff\Payments\RefundReason;
use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\Payments\RetrieveRefundRequest;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;
use Rasuvaeff\PaymentsTesting\Internal\FakeGatewayCore;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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
            amount: new Money(minorUnits: 500, currency: 'EUR'),
        )));
        // No amount: the null-safe reads of the amount run as well.
        $reasoned = $this->withoutPhpDiagnostics(fn() => $this->core->createRefund(new CreateRefundRequest(
            operationId: new OperationId(value: 'refund-2'),
            payment: $payment,
            reason: new RefundReason(value: 'duplicate'),
        )));

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

    /**
     * A provider answers a replayed create from the response it saved for
     * the key and leaves the payment itself alone (Stripe "saves the status
     * code and body of the first request made with a given idempotency key
     * ... Retries with the same key return the saved response",
     * https://docs.stripe.com/api/idempotent_requests). Rebuilding the
     * payment on replay rolled a captured payment back to its creation state,
     * so an application test "a resubmitted form keeps the order paid" went
     * red without an application bug.
     */
    public function replayedCreateLeavesTheStoredPaymentAlone(): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(createState: PaymentState::RequiresCapture));
        $created = $core->createPayment(Fixtures::createPayment(operation: 'operation-1', idempotencyKey: 'create-key'));
        $core->capturePayment($this->capture($created->payment, null, new Money(minorUnits: 500, currency: 'EUR')));

        $replayed = $core->createPayment(Fixtures::createPayment(operation: 'operation-2', idempotencyKey: 'create-key'));
        $current = $core->retrievePayment(new RetrievePaymentRequest(
            operationId: new OperationId(value: 'operation-3'),
            payment: $created->payment,
        ));

        Assert::same($replayed->payment, $created->payment);
        Assert::same($replayed->operationId->value, 'operation-2');
        Assert::same($replayed->state, PaymentState::RequiresCapture);
        Assert::same($replayed->amount->minorUnits, 1_200);
        Assert::same($replayed->createdAt, $created->createdAt);
        Assert::same($current->state, PaymentState::Succeeded);
        Assert::same($current->amount->minorUnits, 500);
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

    /**
     * A provider refuses a refund it cannot honour. A double that accepts it
     * lets an application test pass a refund path that fails in production.
     */
    public function refusesARefundAboveThePaymentAmount(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;

        Expect::exception(RefundFailedException::class)->withMessage('Refund amount exceeds the refundable balance of the fake payment');
        $this->core->createRefund($this->refund($payment, new Money(minorUnits: 1_201, currency: 'EUR')));
    }

    public function refusesARefundInAnotherCurrency(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;

        Expect::exception(RefundFailedException::class)->withMessage('Refund currency differs from the fake payment currency');
        $this->core->createRefund($this->refund($payment, new Money(minorUnits: 500, currency: 'USD')));
    }

    public function refusesASecondFullRefund(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;
        $this->core->createRefund($this->refund($payment, null, 'refund-1'));

        Expect::exception(RefundFailedException::class)->withMessage('Fake payment has no refundable balance left');
        $this->core->createRefund($this->refund($payment, null, 'refund-2'));
    }

    public function partialRefundsShareTheBalance(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;
        $first = $this->core->createRefund($this->refund($payment, new Money(minorUnits: 500, currency: 'EUR'), 'refund-1'));
        $rest = $this->core->createRefund($this->refund($payment, null, 'refund-2'));

        Assert::same($first->requestedAmount->minorUnits, 500);
        Assert::same($rest->requestedAmount->minorUnits, 700);
        Assert::same($rest->actualAmount?->minorUnits, 700);

        Expect::exception(RefundFailedException::class)->withMessage('Fake payment has no refundable balance left');
        $this->core->createRefund($this->refund($payment, new Money(minorUnits: 1, currency: 'EUR'), 'refund-3'));
    }

    public function refusesAPartialRefundAboveTheRemainingBalance(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;
        $this->core->createRefund($this->refund($payment, new Money(minorUnits: 1_000, currency: 'EUR'), 'refund-1'));

        Expect::exception(RefundFailedException::class)->withMessage('Refund amount exceeds the refundable balance of the fake payment');
        $this->core->createRefund($this->refund($payment, new Money(minorUnits: 201, currency: 'EUR'), 'refund-2'));
    }

    /**
     * @param non-empty-string $state
     */
    #[DataProvider('unpaidStateProvider')]
    public function refusesARefundOfAPaymentThatIsNotPaid(PaymentState $state): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(createState: $state));
        $payment = $core->createPayment(Fixtures::createPayment())->payment;

        Expect::exception(RefundFailedException::class)->withMessage('Only a succeeded fake payment can be refunded');
        $core->createRefund($this->refund($payment, null));
    }

    /** @return iterable<string, array{PaymentState}> */
    public static function unpaidStateProvider(): iterable
    {
        foreach (PaymentState::cases() as $state) {
            if ($state !== PaymentState::Succeeded) {
                yield $state->value => [$state];
            }
        }
    }

    public function replayedRefundIsNotCountedTwice(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;
        $first = $this->core->createRefund($this->refund($payment, null, 'refund-1', 'refund-key'));
        $replayed = $this->core->createRefund($this->refund($payment, null, 'refund-2', 'refund-key'));

        Assert::same($replayed->refund->id, $first->refund->id);
        Assert::same($replayed->operationId->value, 'refund-2');
        Assert::same($replayed->requestedAmount->minorUnits, 1_200);

        Expect::exception(RefundFailedException::class)->withMessage('Fake payment has no refundable balance left');
        $this->core->createRefund($this->refund($payment, null, 'refund-3'));
    }

    /**
     * Capture is not state-gated, so a payment can be captured again for less
     * than was already refunded; the balance is then empty, not negative.
     */
    public function aRecaptureBelowTheRefundedAmountLeavesNoBalance(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;
        $this->core->createRefund($this->refund($payment, new Money(minorUnits: 800, currency: 'EUR'), 'refund-1'));
        $this->core->capturePayment($this->capture($payment, null, new Money(minorUnits: 100, currency: 'EUR')));

        Expect::exception(RefundFailedException::class)->withMessage('Fake payment has no refundable balance left');
        $this->core->createRefund($this->refund($payment, new Money(minorUnits: 1, currency: 'EUR'), 'refund-2'));
    }

    public function refusesACaptureAboveTheAuthorizedAmount(): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(createState: PaymentState::RequiresCapture));
        $payment = $core->createPayment(Fixtures::createPayment())->payment;

        Expect::exception(PaymentException::class)->withMessage('Capture amount exceeds the authorized amount of the fake payment');
        $core->capturePayment($this->capture($payment, null, new Money(minorUnits: 1_201, currency: 'EUR')));
    }

    public function refusesACaptureInAnotherCurrency(): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(createState: PaymentState::RequiresCapture));
        $payment = $core->createPayment(Fixtures::createPayment())->payment;

        Expect::exception(PaymentException::class)->withMessage('Capture currency differs from the fake payment currency');
        $core->capturePayment($this->capture($payment, null, new Money(minorUnits: 100, currency: 'USD')));
    }

    public function capturesUpToTheAuthorizedAmount(): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(createState: PaymentState::RequiresCapture));
        $payment = $core->createPayment(Fixtures::createPayment())->payment;

        $captured = $core->capturePayment($this->capture($payment, null, new Money(minorUnits: 1_200, currency: 'EUR')));

        Assert::same($captured->amount->minorUnits, 1_200);
        Assert::same($captured->state, PaymentState::Succeeded);
    }

    /**
     * A replay is answered from the saved response; it does not execute the
     * operation a second time over whatever happened since.
     */
    public function replayedOperationsReturnTheSavedResponse(): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(createState: PaymentState::RequiresAction));
        $payment = $core->createPayment(Fixtures::createPayment())->payment;
        $confirmed = $core->confirmPayment($this->operation($payment, 'confirm-key'));
        $core->cancelPayment($this->operation($payment, 'cancel-key'));

        $replayed = $core->confirmPayment(new PaymentOperationRequest(
            operationId: new OperationId(value: 'operation-replay'),
            payment: $payment,
            idempotencyKey: 'confirm-key',
        ));

        Assert::same($confirmed->state, PaymentState::Processing);
        Assert::same($replayed->state, PaymentState::Processing);
        Assert::same($replayed->operationId->value, 'operation-replay');
        Assert::same($this->stateOf($core, $payment), PaymentState::Canceled);
    }

    public function replayedCaptureReturnsTheSavedResponse(): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(createState: PaymentState::RequiresCapture));
        $payment = $core->createPayment(Fixtures::createPayment())->payment;
        $core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 500, currency: 'EUR')));
        $core->cancelPayment($this->operation($payment, 'cancel-key'));

        $replayed = $core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 500, currency: 'EUR')));

        Assert::same($replayed->state, PaymentState::Succeeded);
        Assert::same($replayed->amount->minorUnits, 500);
        Assert::same($this->stateOf($core, $payment), PaymentState::Canceled);
    }

    public function replayedCancelReturnsTheSavedResponse(): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(createState: PaymentState::RequiresAction));
        $payment = $core->createPayment(Fixtures::createPayment())->payment;
        $core->cancelPayment($this->operation($payment, 'cancel-key'));
        $core->confirmPayment($this->operation($payment, 'confirm-key'));

        $replayed = $core->cancelPayment($this->operation($payment, 'cancel-key'));

        Assert::same($replayed->state, PaymentState::Canceled);
        Assert::same($this->stateOf($core, $payment), PaymentState::Processing);
    }

    public function aRefusedRefundLeavesTheKeyFree(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;

        try {
            $this->core->createRefund($this->refund($payment, new Money(minorUnits: 5_000, currency: 'EUR'), 'refund-1', 'refund-key'));
        } catch (RefundFailedException) {
        }

        $refund = $this->core->createRefund($this->refund($payment, new Money(minorUnits: 500, currency: 'EUR'), 'refund-2', 'refund-key'));

        Assert::same($refund->requestedAmount->minorUnits, 500);
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

    private function stateOf(FakeGatewayCore $core, PaymentReference $payment): PaymentState
    {
        return $core->retrievePayment(new RetrievePaymentRequest(
            operationId: new OperationId(value: 'operation-read'),
            payment: $payment,
        ))->state;
    }

    /**
     * @param non-empty-string $operation
     * @param non-empty-string|null $key
     */
    private function refund(PaymentReference $payment, ?Money $amount, string $operation = 'refund-1', ?string $key = null): CreateRefundRequest
    {
        return new CreateRefundRequest(
            operationId: new OperationId(value: $operation),
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
