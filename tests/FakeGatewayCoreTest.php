<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CaptureMethod;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\ConfirmationMethod;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\CustomerReference;
use Rasuvaeff\Payments\IdempotencyConflictException;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PartialRefundCapability;
use Rasuvaeff\Payments\PaymentException;
use Rasuvaeff\Payments\PaymentMethodReference;
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
use Rasuvaeff\PaymentsTesting\Tests\Support\FakeLedgerMachine;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\RuleSequence;
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
    /**
     * Minimum share of sequences (in percent) that must take each branch of
     * the ledger model, set at roughly half of the share measured over
     * repeated samples (2026-11-28). Keyed capture, confirm and refund
     * replays land in 4-7% of sequences; at 500 runs a 1% gate expects 20+
     * hits, so it fails only when a branch is truly lost.
     */
    private const array LEDGER_BRANCHES = [
        'capture accepted' => 10,
        'capture refused' => 5,
        'capture refused by state' => 40,
        'capture replayed' => 1,
        'confirm accepted' => 8,
        'confirm refused' => 30,
        'confirm replayed' => 1,
        'cancel accepted' => 20,
        'cancel refused' => 25,
        'cancel replayed' => 5,
        'refund accepted' => 8,
        'refund refused' => 30,
        'refund replayed' => 1,
        'create replayed' => 40,
        'key conflict' => 40,
    ];

    private FakeGatewayCore $core;

    #[BeforeTest]
    public function setUp(): void
    {
        // Most cases refund in parts; the gateway must say it can.
        $this->core = new FakeGatewayCore(new FakeGatewayConfig(capabilities: CapabilitySet::of(new PartialRefundCapability())));
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
        $first = $this->core->createPayment(Fixtures::authorizePayment(operation: 'operation-1', idempotencyKey: 'a'))->payment;
        $second = $this->core->createPayment(Fixtures::authorizePayment(operation: 'operation-2', idempotencyKey: 'b'))->payment;
        $this->core->capturePayment($this->capture($first, 'capture-key'));

        Expect::exception(IdempotencyConflictException::class)->withMessage('Idempotency key was reused with a different fake request');
        $this->core->capturePayment($this->capture($second, 'capture-key'));
    }

    public function refusesCaptureKeyReusedWithAnotherCurrency(): void
    {
        $payment = $this->core->createPayment(Fixtures::authorizePayment())->payment;
        $this->core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 100, currency: 'EUR')));

        Expect::exception(IdempotencyConflictException::class)->withMessage('Idempotency key was reused with a different fake request');
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
        Assert::same($created->capturedAmount?->minorUnits, 0);
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
        Assert::same($replayed->capturedAmount?->minorUnits, 0);
        Assert::same($replayed->createdAt, $created->createdAt);
        Assert::same($current->state, PaymentState::Succeeded);
        Assert::same($current->amount->minorUnits, 1_200);
        Assert::same($current->capturedAmount?->minorUnits, 500);
    }

    public function replaysCaptureWithTheSameKeyAndRequest(): void
    {
        $payment = $this->core->createPayment(Fixtures::authorizePayment())->payment;
        $first = $this->core->capturePayment($this->capture($payment, 'capture-key'));
        $second = $this->core->capturePayment($this->capture($payment, 'capture-key'));

        Assert::same($second->payment, $first->payment);
    }

    #[ExpectNoAssertions]
    public function doesNotTrackCapturesWithoutAKey(): void
    {
        $first = $this->core->createPayment(Fixtures::authorizePayment(operation: 'operation-1', idempotencyKey: 'a'))->payment;
        $second = $this->core->createPayment(Fixtures::authorizePayment(operation: 'operation-2', idempotencyKey: 'b'))->payment;

        $this->core->capturePayment($this->capture($first, null, new Money(minorUnits: 100, currency: 'EUR')));
        $this->core->capturePayment($this->capture($second, null, new Money(minorUnits: 200, currency: 'EUR')));
    }

    /**
     * One key space across operations, as at Stripe, where a key is bound
     * to the endpoint and parameters of its first request: a key used for
     * one operation is refused for another, whatever the payment.
     */
    #[DataProvider('keyAcrossOperationsProvider')]
    public function refusesOneKeyAcrossOperations(string $first, string $second): void
    {
        $payment = $this->core->createPayment(Fixtures::authorizePayment(idempotencyKey: 'create-key'))->payment;
        $calls = [
            'create' => fn() => $this->core->createPayment(Fixtures::authorizePayment(operation: 'operation-2', idempotencyKey: 'shared-key')),
            'capture' => fn() => $this->core->capturePayment($this->capture($payment, 'shared-key')),
            'confirm' => fn() => $this->core->confirmPayment($this->operation($payment, 'shared-key')),
            'cancel' => fn() => $this->core->cancelPayment($this->operation($payment, 'shared-key')),
            'refund' => fn() => $this->core->createRefund($this->refund($payment, null, 'refund-1', 'shared-key')),
        ];
        $calls[$first]();

        Expect::exception(IdempotencyConflictException::class)->withMessage('Idempotency key was reused with a different fake request');
        $calls[$second]();
    }

    /** @return iterable<string, array{string, string}> */
    public static function keyAcrossOperationsProvider(): iterable
    {
        yield 'create then capture' => ['create', 'capture'];
        yield 'capture then cancel' => ['capture', 'cancel'];
        yield 'capture then refund' => ['capture', 'refund'];
        yield 'cancel then confirm' => ['cancel', 'confirm'];
        yield 'cancel then create' => ['cancel', 'create'];
    }

    /**
     * A provider compares every parameter of the first request with the
     * replay's ("compares incoming parameters to those of the original
     * request and errors if they're not the same",
     * https://docs.stripe.com/api/idempotent_requests). Each case changes one
     * field of the create and nothing else.
     *
     * @param array<string, mixed> $changed
     */
    #[DataProvider('createFieldProvider')]
    public function refusesTheCreateKeyWithAnyFieldChanged(array $changed): void
    {
        $this->core->createPayment($this->createWith([]));

        Expect::exception(IdempotencyConflictException::class)->withMessage('Idempotency key was reused with a different fake request');
        $this->core->createPayment($this->createWith($changed));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function createFieldProvider(): iterable
    {
        yield 'amount' => [['amount' => new Money(minorUnits: 1_201, currency: 'EUR')]];
        yield 'currency' => [['amount' => new Money(minorUnits: 1_200, currency: 'USD')]];
        yield 'payment method' => [['paymentMethod' => new PaymentMethodReference(id: 'pm_2', kind: 'card')]];
        yield 'payment method kind' => [['paymentMethod' => new PaymentMethodReference(id: 'pm_1', kind: 'sepa_debit')]];
        yield 'no payment method' => [['paymentMethod' => null]];
        yield 'customer' => [['customer' => new CustomerReference(id: 'cus_B', providerId: 'cus_provider')]];
        yield 'provider customer' => [['customer' => new CustomerReference(id: 'cus_A', providerId: 'cus_other')]];
        yield 'no customer' => [['customer' => null]];
        yield 'capture method' => [['captureMethod' => CaptureMethod::Manual]];
        yield 'confirmation method' => [['confirmationMethod' => ConfirmationMethod::Manual]];
        yield 'description' => [['description' => 'Order 2']];
        yield 'metadata' => [['metadata' => ['order' => '2']]];
    }

    public function replaysTheCreateKeyWithEveryFieldTheSame(): void
    {
        $first = $this->core->createPayment($this->createWith([]));
        $replayed = $this->core->createPayment($this->createWith(['operationId' => new OperationId(value: 'operation-2')]));

        Assert::same($replayed->payment, $first->payment);
    }

    /**
     * Omitting the capture amount and naming the full amount are two
     * different requests to a provider (`amount_to_capture` absent or set),
     * as they are for a refund.
     */
    public function refusesTheCaptureKeyWithAnExplicitFullAmount(): void
    {
        $payment = $this->core->createPayment(Fixtures::authorizePayment())->payment;
        $this->core->capturePayment($this->capture($payment, 'capture-key'));

        Expect::exception(IdempotencyConflictException::class)->withMessage('Idempotency key was reused with a different fake request');
        $this->core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 1_200, currency: 'EUR')));
    }

    /**
     * A refund amount must be "a positive integer"
     * (https://docs.stripe.com/api/refunds/create). Stripe documents no
     * minimum for `amount_to_capture`; refusing a zero capture is the fake's
     * own choice, since it captures nothing. Both are parameter validation,
     * which a provider rejects before the idempotency layer saves anything,
     * so the key stays free.
     */
    public function refusesAZeroCaptureBeforeTheKey(): void
    {
        $payment = $this->core->createPayment(Fixtures::authorizePayment())->payment;

        try {
            $this->core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 0, currency: 'EUR')));
            Assert::fail('A zero capture must be refused');
        } catch (PaymentException $refused) {
            Assert::same($refused::class, PaymentException::class);
            Assert::same($refused->getMessage(), 'Fake capture amount must be positive');
        }

        $captured = $this->core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 700, currency: 'EUR')));
        Assert::same($captured->capturedAmount?->minorUnits, 700);
    }

    public function refusesAZeroRefundBeforeTheKey(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;

        try {
            $this->core->createRefund($this->refund($payment, new Money(minorUnits: 0, currency: 'EUR'), 'refund-1', 'refund-key'));
            Assert::fail('A zero refund must be refused');
        } catch (PaymentException $refused) {
            Assert::same($refused::class, PaymentException::class);
            Assert::same($refused->getMessage(), 'Fake refund amount must be positive');
        }

        $refund = $this->core->createRefund($this->refund($payment, null, 'refund-2', 'refund-key'));
        Assert::same($refund->requestedAmount->minorUnits, 1_200);
    }

    /**
     * Without `PartialRefundCapability` the gateway says it refunds only in
     * full, and the fake holds it to that: a refund of less than the whole
     * captured amount is refused, a full one goes through once.
     */
    public function refusesAPartialRefundWithoutTheCapability(): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig());
        $payment = $core->createPayment(Fixtures::createPayment())->payment;

        try {
            $core->createRefund($this->refund($payment, new Money(minorUnits: 300, currency: 'EUR')));
            Assert::fail('A partial refund must be refused without the capability');
        } catch (RefundFailedException $refused) {
            Assert::same($refused->getMessage(), 'Fake gateway refunds only the whole remaining amount without PartialRefundCapability');
        }

        $full = $core->createRefund($this->refund($payment, new Money(minorUnits: 1_200, currency: 'EUR'), 'refund-2'));
        Assert::same($full->requestedAmount->minorUnits, 1_200);

        Expect::exception(RefundFailedException::class)->withMessage('Fake payment has no refundable balance left');
        $core->createRefund($this->refund($payment, null, 'refund-3'));
    }

    /**
     * `maximumRefunds` caps how many refunds one payment takes.
     */
    public function refusesARefundBeyondTheMaximumCount(): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(capabilities: CapabilitySet::of(new PartialRefundCapability(maximumRefunds: 2))));
        $payment = $core->createPayment(Fixtures::createPayment())->payment;
        $core->createRefund($this->refund($payment, new Money(minorUnits: 100, currency: 'EUR'), 'refund-1'));
        $core->createRefund($this->refund($payment, new Money(minorUnits: 100, currency: 'EUR'), 'refund-2'));

        Expect::exception(RefundFailedException::class)->withMessage('Fake payment reached the maximum of 2 refunds');
        $core->createRefund($this->refund($payment, new Money(minorUnits: 100, currency: 'EUR'), 'refund-3'));
    }

    /**
     * A replayed refund is not a new one: it does not count against
     * `maximumRefunds`.
     */
    public function aReplayedRefundDoesNotCountTowardsTheMaximum(): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(capabilities: CapabilitySet::of(new PartialRefundCapability(maximumRefunds: 2))));
        $payment = $core->createPayment(Fixtures::createPayment())->payment;
        $core->createRefund($this->refund($payment, new Money(minorUnits: 100, currency: 'EUR'), 'refund-1', 'refund-key'));
        $core->createRefund($this->refund($payment, new Money(minorUnits: 100, currency: 'EUR'), 'refund-2', 'refund-key'));

        $second = $core->createRefund($this->refund($payment, new Money(minorUnits: 100, currency: 'EUR'), 'refund-3'));
        Assert::same($second->requestedAmount->minorUnits, 100);
    }

    public function refusesTheCreateKeyForARefund(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment(idempotencyKey: 'create-key'))->payment;

        Expect::exception(IdempotencyConflictException::class)->withMessage('Idempotency key was reused with a different fake request');
        $this->core->createRefund($this->refund($payment, null, 'refund-1', 'create-key'));
    }

    public function refusesConfirmKeyReusedForAnotherPayment(): void
    {
        $first = $this->core->createPayment(Fixtures::pendingPayment(operation: 'operation-1', idempotencyKey: 'a'))->payment;
        $second = $this->core->createPayment(Fixtures::pendingPayment(operation: 'operation-2', idempotencyKey: 'b'))->payment;
        $this->core->confirmPayment($this->operation($first, 'confirm-key'));

        Expect::exception(IdempotencyConflictException::class)->withMessage('Idempotency key was reused with a different fake request');
        $this->core->confirmPayment($this->operation($second, 'confirm-key'));
    }

    public function refusesCancelKeyReusedForAnotherPayment(): void
    {
        $first = $this->core->createPayment(Fixtures::authorizePayment(operation: 'operation-1', idempotencyKey: 'a'))->payment;
        $second = $this->core->createPayment(Fixtures::authorizePayment(operation: 'operation-2', idempotencyKey: 'b'))->payment;
        $this->core->cancelPayment($this->operation($first, 'cancel-key'));

        Expect::exception(IdempotencyConflictException::class)->withMessage('Idempotency key was reused with a different fake request');
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

        Expect::exception(IdempotencyConflictException::class)->withMessage('Idempotency key was reused with a different fake request');
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
     * A partial capture takes less than the authorization; the amount stays
     * the authorized one, and only what was captured can be refunded.
     */
    public function refundsNoMoreThanAPartialCaptureTook(): void
    {
        $payment = $this->core->createPayment(Fixtures::authorizePayment())->payment;
        $captured = $this->core->capturePayment($this->capture($payment, null, new Money(minorUnits: 700, currency: 'EUR')));

        Assert::same($captured->amount->minorUnits, 1_200);
        Assert::same($captured->capturedAmount?->minorUnits, 700);

        $refund = $this->core->createRefund($this->refund($payment, null, 'refund-1'));
        Assert::same($refund->requestedAmount->minorUnits, 700);

        Expect::exception(RefundFailedException::class)->withMessage('Fake payment has no refundable balance left');
        $this->core->createRefund($this->refund($payment, new Money(minorUnits: 1, currency: 'EUR'), 'refund-2'));
    }

    public function refusesASecondCapture(): void
    {
        $payment = $this->core->createPayment(Fixtures::authorizePayment())->payment;
        $this->core->capturePayment($this->capture($payment, null, new Money(minorUnits: 100, currency: 'EUR')));

        Expect::exception(PaymentException::class)->withMessage('Fake payment in state succeeded cannot be captured');
        $this->core->capturePayment($this->capture($payment, null, new Money(minorUnits: 100, currency: 'EUR')));
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
        $full = $this->core->capturePayment($this->capture($this->core->createPayment(Fixtures::authorizePayment())->payment, null));

        Assert::same($captured->amount->minorUnits, 1_200);
        Assert::same($captured->capturedAmount?->minorUnits, 1_200);
        Assert::same($captured->state, PaymentState::Succeeded);
        Assert::same($full->capturedAmount?->minorUnits, 1_200);
    }

    /**
     * A replay is answered from the saved response; it does not execute the
     * operation a second time over whatever happened since, and the state
     * gate does not refuse it.
     */
    public function replayedOperationsReturnTheSavedResponse(): void
    {
        $payment = $this->core->createPayment(new CreatePaymentRequest(
            operationId: new OperationId(value: 'operation-1'),
            amount: new Money(minorUnits: 1_200, currency: 'EUR'),
            paymentMethod: new PaymentMethodReference(id: 'pm_1'),
            captureMethod: CaptureMethod::Manual,
            confirmationMethod: ConfirmationMethod::Manual,
        ))->payment;
        $confirmed = $this->core->confirmPayment($this->operation($payment, 'confirm-key'));
        $this->core->cancelPayment($this->operation($payment, 'cancel-key'));

        $replayed = $this->core->confirmPayment(new PaymentOperationRequest(
            operationId: new OperationId(value: 'operation-replay'),
            payment: $payment,
            idempotencyKey: 'confirm-key',
        ));

        Assert::same($confirmed->state, PaymentState::RequiresCapture);
        Assert::same($replayed->state, PaymentState::RequiresCapture);
        Assert::same($replayed->operationId->value, 'operation-replay');
        Assert::same($this->stateOf($this->core, $payment), PaymentState::Canceled);
    }

    public function replayedCaptureReturnsTheSavedResponse(): void
    {
        $payment = $this->core->createPayment(Fixtures::authorizePayment())->payment;
        $this->core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 500, currency: 'EUR')));
        $this->core->createRefund($this->refund($payment, null));

        $replayed = $this->core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 500, currency: 'EUR')));

        Assert::same($replayed->state, PaymentState::Succeeded);
        Assert::same($replayed->capturedAmount?->minorUnits, 500);
        Assert::same($replayed->amount->minorUnits, 1_200);
    }

    public function replayedCancelReturnsTheSavedResponse(): void
    {
        $payment = $this->core->createPayment(Fixtures::pendingPayment())->payment;
        $this->core->cancelPayment($this->operation($payment, 'cancel-key'));

        $replayed = $this->core->cancelPayment($this->operation($payment, 'cancel-key'));

        Assert::same($replayed->state, PaymentState::Canceled);

        Expect::exception(PaymentException::class)->withMessage('Fake payment in state canceled cannot be canceled');
        $this->core->cancelPayment($this->operation($payment, 'cancel-key-2'));
    }

    /**
     * Stripe "saves the status code and body of the first request made with
     * a given idempotency key" once the endpoint has begun executing, errors
     * included: "Retries with the same key return the saved response"
     * (https://docs.stripe.com/api/idempotent_requests). A refusal for the
     * payment's balance comes from the executing endpoint, so the key is
     * spent: the same request gets the same refusal, a corrected one a
     * conflict.
     */
    public function aRefusedRefundSpendsTheKey(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;
        $refused = $this->refusal(fn() => $this->core->createRefund($this->refund($payment, new Money(minorUnits: 5_000, currency: 'EUR'), 'refund-1', 'refund-key')));

        Assert::same($refused::class, RefundFailedException::class);
        Assert::same(
            $this->refusal(fn() => $this->core->createRefund($this->refund($payment, new Money(minorUnits: 5_000, currency: 'EUR'), 'refund-2', 'refund-key'))),
            $refused,
        );

        Expect::exception(IdempotencyConflictException::class)->withMessage('Idempotency key was reused with a different fake request');
        $this->core->createRefund($this->refund($payment, new Money(minorUnits: 500, currency: 'EUR'), 'refund-3', 'refund-key'));
    }

    /**
     * The saved refusal is replayed before the state gate, as a saved
     * success is: the payment has since become capturable, and the same
     * request with the same key still gets the first answer.
     */
    public function aSavedRefusalOutlivesAStateChange(): void
    {
        $payment = $this->core->createPayment(Fixtures::pendingPayment())->payment;
        $refused = $this->refusal(fn() => $this->core->capturePayment($this->capture($payment, 'capture-key')));
        $this->core->confirmPayment($this->operation($payment, 'confirm-key'));

        Assert::same($refused->getMessage(), 'Fake payment in state pending cannot be captured');
        Assert::same($this->refusal(fn() => $this->core->capturePayment($this->capture($payment, 'capture-key'))), $refused);
        Assert::same($this->stateOf($this->core, $payment), PaymentState::Succeeded);
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function refusal(\Closure $call): PaymentException
    {
        try {
            $call();
        } catch (PaymentException $refused) {
            return $refused;
        }

        Assert::fail('Expected a refusal');
    }

    /**
     * Without a pinned state the fake answers a create as a provider does.
     */
    #[DataProvider('createdStateProvider')]
    public function derivesTheCreatedStateFromTheRequest(
        bool $withMethod,
        CaptureMethod $capture,
        ConfirmationMethod $confirmation,
        PaymentState $state,
        int $captured,
    ): void {
        $attempt = $this->core->createPayment(new CreatePaymentRequest(
            operationId: new OperationId(value: 'operation-1'),
            amount: new Money(minorUnits: 1_200, currency: 'EUR'),
            paymentMethod: $withMethod ? new PaymentMethodReference(id: 'pm_1') : null,
            captureMethod: $capture,
            confirmationMethod: $confirmation,
        ));

        Assert::same($attempt->state, $state);
        Assert::same($attempt->amount->minorUnits, 1_200);
        Assert::same($attempt->capturedAmount?->minorUnits, $captured);
        Assert::same($attempt->capturedAmount?->currency, 'EUR');
    }

    /** @return iterable<string, array{bool, CaptureMethod, ConfirmationMethod, PaymentState, int}> */
    public static function createdStateProvider(): iterable
    {
        yield 'paid' => [true, CaptureMethod::Automatic, ConfirmationMethod::Automatic, PaymentState::Succeeded, 1_200];
        yield 'authorized' => [true, CaptureMethod::Manual, ConfirmationMethod::Automatic, PaymentState::RequiresCapture, 0];
        yield 'awaiting confirmation' => [true, CaptureMethod::Automatic, ConfirmationMethod::Manual, PaymentState::Pending, 0];
        yield 'awaiting confirmation, manual capture' => [true, CaptureMethod::Manual, ConfirmationMethod::Manual, PaymentState::Pending, 0];
        yield 'deferred' => [false, CaptureMethod::Automatic, ConfirmationMethod::Automatic, PaymentState::RequiresPaymentMethod, 0];
        yield 'deferred, manual everything' => [false, CaptureMethod::Manual, ConfirmationMethod::Manual, PaymentState::RequiresPaymentMethod, 0];
    }

    public function aPinnedCreateStateWins(): void
    {
        $paid = new FakeGatewayCore(new FakeGatewayConfig(createState: PaymentState::Succeeded));
        $processing = new FakeGatewayCore(new FakeGatewayConfig(createState: PaymentState::Processing));

        $succeeded = $paid->createPayment(Fixtures::authorizePayment());
        $pending = $processing->createPayment(Fixtures::createPayment());

        Assert::same($succeeded->state, PaymentState::Succeeded);
        Assert::same($succeeded->capturedAmount?->minorUnits, 1_200);
        Assert::same($pending->state, PaymentState::Processing);
        Assert::same($pending->capturedAmount?->minorUnits, 0);
    }

    #[DataProvider('notCapturableProvider')]
    public function capturesOnlyAnAuthorization(PaymentState $state): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(createState: $state));
        $payment = $core->createPayment(Fixtures::authorizePayment())->payment;

        Expect::exception(PaymentException::class)->withMessage('Fake payment in state ' . $state->value . ' cannot be captured');
        $core->capturePayment($this->capture($payment, 'capture-key'));
    }

    /** @return iterable<string, array{PaymentState}> */
    public static function notCapturableProvider(): iterable
    {
        foreach (PaymentState::cases() as $state) {
            if ($state !== PaymentState::RequiresCapture) {
                yield $state->value => [$state];
            }
        }
    }

    /**
     * A refused capture spends its key like a refused refund.
     */
    public function aRefusedCaptureSpendsTheKey(): void
    {
        $payment = $this->core->createPayment(Fixtures::authorizePayment())->payment;
        $refused = $this->refusal(fn() => $this->core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 5_000, currency: 'EUR'))));

        Assert::same($refused->getMessage(), 'Capture amount exceeds the authorized amount of the fake payment');
        Assert::same($this->refusal(fn() => $this->core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 5_000, currency: 'EUR')))), $refused);

        Expect::exception(IdempotencyConflictException::class)->withMessage('Idempotency key was reused with a different fake request');
        $this->core->capturePayment($this->capture($payment, 'capture-key', new Money(minorUnits: 500, currency: 'EUR')));
    }

    /**
     * Confirm and cancel refusals are saved the same way.
     */
    public function aRefusedConfirmOrCancelSpendsTheKey(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;
        $confirm = $this->refusal(fn() => $this->core->confirmPayment($this->operation($payment, 'op-key')));

        Assert::same($this->refusal(fn() => $this->core->confirmPayment($this->operation($payment, 'op-key'))), $confirm);

        $cancel = $this->refusal(fn() => $this->core->cancelPayment($this->operation($payment, 'cancel-key')));
        Assert::same($this->refusal(fn() => $this->core->cancelPayment($this->operation($payment, 'cancel-key'))), $cancel);
        Assert::same($this->refusal(fn() => $this->core->confirmPayment($this->operation($payment, 'cancel-key')))::class, IdempotencyConflictException::class);
    }

    #[DataProvider('cancelProvider')]
    public function cancelsOnlyAnUnfinishedPayment(PaymentState $state, bool $cancelable): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(createState: $state));
        $payment = $core->createPayment(Fixtures::createPayment())->payment;

        if (!$cancelable) {
            Expect::exception(PaymentException::class)->withMessage('Fake payment in state ' . $state->value . ' cannot be canceled');
        }

        $canceled = $core->cancelPayment($this->operation($payment, 'cancel-key'));

        Assert::same($canceled->state, PaymentState::Canceled);
        Assert::same($canceled->amount->minorUnits, 1_200);
        Assert::same($canceled->capturedAmount?->minorUnits, 0);
        Assert::same($this->stateOf($core, $payment), PaymentState::Canceled);
    }

    /** @return iterable<string, array{PaymentState, bool}> */
    public static function cancelProvider(): iterable
    {
        yield 'pending' => [PaymentState::Pending, true];
        yield 'requires payment method' => [PaymentState::RequiresPaymentMethod, true];
        yield 'requires action' => [PaymentState::RequiresAction, true];
        yield 'requires capture' => [PaymentState::RequiresCapture, true];
        yield 'processing' => [PaymentState::Processing, true];
        yield 'succeeded' => [PaymentState::Succeeded, false];
        yield 'failed' => [PaymentState::Failed, false];
        yield 'canceled' => [PaymentState::Canceled, false];
    }

    #[DataProvider('confirmProvider')]
    public function confirmsOnlyAPaymentAwaitingConfirmation(PaymentState $state, CaptureMethod $capture, ?PaymentState $confirmed, int $captured): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(createState: $state));
        $payment = $core->createPayment(new CreatePaymentRequest(
            operationId: new OperationId(value: 'operation-1'),
            amount: new Money(minorUnits: 1_200, currency: 'EUR'),
            paymentMethod: new PaymentMethodReference(id: 'pm_1'),
            captureMethod: $capture,
        ))->payment;

        if (!$confirmed instanceof \Rasuvaeff\Payments\PaymentState) {
            Expect::exception(PaymentException::class)->withMessage('Fake payment in state ' . $state->value . ' cannot be confirmed');
        }

        $attempt = $core->confirmPayment($this->operation($payment, 'confirm-key'));

        Assert::same($attempt->state, $confirmed);
        Assert::same($attempt->capturedAmount?->minorUnits, $captured);
        Assert::same($this->stateOf($core, $payment), $confirmed);
    }

    /** @return iterable<string, array{PaymentState, CaptureMethod, ?PaymentState, int}> */
    public static function confirmProvider(): iterable
    {
        yield 'pending, automatic capture' => [PaymentState::Pending, CaptureMethod::Automatic, PaymentState::Succeeded, 1_200];
        yield 'pending, manual capture' => [PaymentState::Pending, CaptureMethod::Manual, PaymentState::RequiresCapture, 0];
        yield 'requires action, automatic capture' => [PaymentState::RequiresAction, CaptureMethod::Automatic, PaymentState::Succeeded, 1_200];
        yield 'requires action, manual capture' => [PaymentState::RequiresAction, CaptureMethod::Manual, PaymentState::RequiresCapture, 0];

        foreach ([PaymentState::RequiresPaymentMethod, PaymentState::RequiresCapture, PaymentState::Processing, PaymentState::Succeeded, PaymentState::Failed, PaymentState::Canceled] as $state) {
            yield $state->value => [$state, CaptureMethod::Automatic, null, 0];
        }
    }

    public function aSimulatedTransitionMovesThePayment(): void
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig(createState: PaymentState::RequiresAction));
        $action = $core->createPayment(Fixtures::createPayment())->payment;

        $moved = $core->transition(payment: $action, state: PaymentState::Succeeded, operationId: new OperationId(value: 'webhook-1'));

        Assert::same($moved->state, PaymentState::Succeeded);
        Assert::same($moved->operationId->value, 'webhook-1');
        Assert::same($moved->capturedAmount?->minorUnits, 1_200);
        Assert::same($this->stateOf($core, $action), PaymentState::Succeeded);
    }

    /**
     * A partial capture already took money: settling it as succeeded again
     * keeps what was captured instead of claiming the whole authorization.
     */
    public function aSimulatedTransitionKeepsACapturedAmount(): void
    {
        $payment = $this->core->createPayment(Fixtures::authorizePayment())->payment;
        $this->core->capturePayment($this->capture($payment, null, new Money(minorUnits: 300, currency: 'EUR')));

        $moved = $this->core->transition(payment: $payment, state: PaymentState::Succeeded, operationId: new OperationId(value: 'webhook-1'));

        Assert::same($moved->capturedAmount?->minorUnits, 300);
    }

    public function aSimulatedTransitionToAnotherStateKeepsNothingCaptured(): void
    {
        $payment = $this->core->createPayment(Fixtures::authorizePayment())->payment;

        $moved = $this->core->transition(payment: $payment, state: PaymentState::Processing, operationId: new OperationId(value: 'webhook-1'));

        Assert::same($moved->state, PaymentState::Processing);
        Assert::same($moved->capturedAmount?->minorUnits, 0);
    }

    public function refusesASimulatedTransitionTheStateMapForbids(): void
    {
        $payment = $this->core->createPayment(Fixtures::createPayment())->payment;

        Expect::exception(\InvalidArgumentException::class)->withMessage('Fake payment cannot move from succeeded to pending');
        $this->core->transition(payment: $payment, state: PaymentState::Pending, operationId: new OperationId(value: 'webhook-1'));
    }

    /**
     * Any sequence of captures, confirms, cancels, refunds and replays on a
     * payment created in any of the usual ways, keyed from a small pool
     * shared by every operation, keeps the fake in step with a model of what
     * a provider allows. The replay and over-refund defects of 0.1.2 and the
     * ungated capture and cancel of 0.1.x each fail this within a few steps.
     *
     * @param value-of<FakeLedgerMachine::SCENARIOS> $scenario
     * @param value-of<FakeLedgerMachine::REFUND_POLICIES> $refunds
     */
    #[Property(runs: 500)]
    public function ledgerAgreesWithAProviderModel(string $scenario, string $refunds, RuleSequence $sequence): void
    {
        $machine = null;
        $sequence->run(static function () use (&$machine, $scenario, $refunds): FakeLedgerMachine {
            return $machine = new FakeLedgerMachine($scenario, $refunds);
        });
        \assert($machine instanceof FakeLedgerMachine);

        foreach (self::LEDGER_BRANCHES as $label => $minimum) {
            Classify::cover(isset($machine->seen[$label]), $label, $minimum);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function ledgerAgreesWithAProviderModelGenerators(): array
    {
        return [
            // Captures need an authorization; weight the scenarios that
            // hold one so capture replays are not a rare event.
            'scenario' => Gen::frequency([
                [4, Gen::constant('authorized')],
                [2, Gen::constant('confirm and capture')],
                [1, Gen::constant('paid')],
                [1, Gen::constant('confirm')],
                [1, Gen::constant('requires action')],
                [1, Gen::constant('deferred')],
            ]),
            'refunds' => Gen::elements(FakeLedgerMachine::REFUND_POLICIES),
            'sequence' => Gen::rules(FakeLedgerMachine::class, minLength: 0, maxLength: 25),
        ];
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

    /**
     * @param array<string, mixed> $changed
     */
    private function createWith(array $changed): CreatePaymentRequest
    {
        $fields = [
            'operationId' => new OperationId(value: 'operation-1'),
            'amount' => new Money(minorUnits: 1_200, currency: 'EUR'),
            'paymentMethod' => new PaymentMethodReference(id: 'pm_1', kind: 'card'),
            'customer' => new CustomerReference(id: 'cus_A', providerId: 'cus_provider'),
            'captureMethod' => CaptureMethod::Automatic,
            'confirmationMethod' => ConfirmationMethod::Automatic,
            'description' => 'Order 1',
            'metadata' => ['order' => '1'],
            'idempotencyKey' => 'create-key',
        ];

        return new CreatePaymentRequest(...array_replace($fields, $changed));
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
