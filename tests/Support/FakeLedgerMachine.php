<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests\Support;

use Rasuvaeff\Payments\CaptureMethod;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\ConfirmationMethod;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\IdempotencyConflictException;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentException;
use Rasuvaeff\Payments\PaymentMethodReference;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\RefundAttempt;
use Rasuvaeff\Payments\RefundFailedException;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;
use Rasuvaeff\PaymentsTesting\Internal\FakeGatewayCore;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\StateMachine\Invariant;
use Rasuvaeff\PropertyTesting\StateMachine\Precondition;
use Rasuvaeff\PropertyTesting\StateMachine\Rule;
use Testo\Assert;

/**
 * Model of one provider payment the fake must agree with: a payment of
 * 1000 EUR created in one of the usual ways, then captured, confirmed,
 * refunded, canceled and replayed in any order, each call with or without
 * an idempotency key from a small pool shared by every operation — the
 * create's own key among them — so replays and key conflicts are ordinary.
 *
 * The model is written from the provider's rules, not from the fake's code:
 * capture only an authorization and never above it, confirm only what
 * awaits confirmation, cancel only what has not finished, refund only what
 * was captured and not yet refunded; a key answers from its first call and
 * refuses any other request.
 */
final class FakeLedgerMachine
{
    public const array SCENARIOS = ['paid', 'authorized', 'confirm', 'confirm and capture', 'requires action', 'deferred'];

    private const int AUTHORIZED = 1_000;
    private const string CURRENCY = 'EUR';

    /**
     * The moves a provider makes on an API call, by operation.
     */
    private const array EDGES = [
        'requires_capture>succeeded',
        'pending>succeeded',
        'pending>requires_capture',
        'requires_action>succeeded',
        'requires_action>requires_capture',
        'pending>canceled',
        'requires_payment_method>canceled',
        'requires_action>canceled',
        'requires_capture>canceled',
        'processing>canceled',
    ];

    /**
     * Which branches the sequence took; the property gates its coverage on them.
     *
     * @var array<non-empty-string, true>
     */
    public array $seen = [];

    private readonly FakeGatewayCore $core;
    private readonly PaymentReference $payment;
    private readonly CreatePaymentRequest $create;
    private readonly bool $manualCapture;
    private PaymentState $state;
    private PaymentState $observed;
    private int $captured;
    private int $refunded = 0;
    private int $operation = 0;

    /**
     * What each key was first used for and what it answered.
     *
     * @var array<string, array{operation: string, request: int|null, response: list<int|string|PaymentState|null>}>
     */
    private array $keys = [];
    private ?string $lastKey = null;

    /**
     * @param value-of<self::SCENARIOS> $scenario
     */
    public function __construct(string $scenario)
    {
        $this->manualCapture = \in_array($scenario, ['authorized', 'confirm and capture'], strict: true);
        $this->state = match ($scenario) {
            'paid' => PaymentState::Succeeded,
            'authorized' => PaymentState::RequiresCapture,
            'confirm', 'confirm and capture' => PaymentState::Pending,
            'requires action' => PaymentState::RequiresAction,
            'deferred' => PaymentState::RequiresPaymentMethod,
        };
        $this->observed = $this->state;
        $this->captured = $this->state === PaymentState::Succeeded ? self::AUTHORIZED : 0;
        $this->core = new FakeGatewayCore(new FakeGatewayConfig(
            createState: $scenario === 'requires action' ? PaymentState::RequiresAction : null,
        ));
        $this->create = new CreatePaymentRequest(
            operationId: $this->operationId(),
            amount: new Money(minorUnits: self::AUTHORIZED, currency: self::CURRENCY),
            paymentMethod: $scenario === 'deferred' ? null : new PaymentMethodReference(id: 'pm_1'),
            captureMethod: $this->manualCapture ? CaptureMethod::Manual : CaptureMethod::Automatic,
            confirmationMethod: str_starts_with($scenario, 'confirm') ? ConfirmationMethod::Manual : ConfirmationMethod::Automatic,
            idempotencyKey: 'create',
        );
        $created = $this->core->createPayment($this->create);
        $this->payment = $created->payment;
        $this->keys['create'] = ['operation' => 'create', 'request' => null, 'response' => $this->answer($created)];
    }

    /** @return array<string, ArbitraryInterface> */
    public static function captureGenerators(): array
    {
        return [
            'amount' => self::amount(),
            'key' => self::key(),
        ];
    }

    /** @return array<string, ArbitraryInterface> */
    public static function refundGenerators(): array
    {
        return [
            'amount' => self::amount(),
            'key' => self::key(),
        ];
    }

    /** @return array<string, ArbitraryInterface> */
    public static function confirmGenerators(): array
    {
        return ['key' => self::key()];
    }

    /** @return array<string, ArbitraryInterface> */
    public static function cancelGenerators(): array
    {
        return ['key' => self::key()];
    }

    #[Rule]
    public function capture(?int $amount, ?string $key): void
    {
        $resolved = $amount ?? self::AUTHORIZED;
        $call = fn(): PaymentAttempt => $this->core->capturePayment(new CapturePaymentRequest(
            operationId: $this->operationId(),
            payment: $this->payment,
            amount: $amount === null ? null : new Money(minorUnits: $amount, currency: self::CURRENCY),
            idempotencyKey: $key,
        ));

        if ($this->answeredByKey(operation: 'capture', request: $resolved, key: $key, call: $call)) {
            return;
        }

        if ($this->state !== PaymentState::RequiresCapture) {
            $this->expectFailure($call, PaymentException::class, 'capture refused by state');

            return;
        }

        if ($resolved > self::AUTHORIZED) {
            $this->expectFailure($call, PaymentException::class, 'capture refused');

            return;
        }

        $captured = $call();
        $this->state = PaymentState::Succeeded;
        $this->captured = $resolved;
        Assert::same($captured->amount->minorUnits, self::AUTHORIZED);
        $this->accepted(operation: 'capture', request: $resolved, key: $key, attempt: $captured);
    }

    #[Rule]
    public function confirm(?string $key): void
    {
        $call = fn(): PaymentAttempt => $this->core->confirmPayment(new PaymentOperationRequest(
            operationId: $this->operationId(),
            payment: $this->payment,
            idempotencyKey: $key,
        ));

        if ($this->answeredByKey(operation: 'confirm', request: null, key: $key, call: $call)) {
            return;
        }

        if (!\in_array($this->state, [PaymentState::Pending, PaymentState::RequiresAction], strict: true)) {
            $this->expectFailure($call, PaymentException::class, 'confirm refused');

            return;
        }

        $confirmed = $call();
        $this->state = $this->manualCapture ? PaymentState::RequiresCapture : PaymentState::Succeeded;
        $this->captured = $this->manualCapture ? 0 : self::AUTHORIZED;
        $this->accepted(operation: 'confirm', request: null, key: $key, attempt: $confirmed);
    }

    #[Rule]
    public function cancel(?string $key): void
    {
        $call = fn(): PaymentAttempt => $this->core->cancelPayment(new PaymentOperationRequest(
            operationId: $this->operationId(),
            payment: $this->payment,
            idempotencyKey: $key,
        ));

        if ($this->answeredByKey(operation: 'cancel', request: null, key: $key, call: $call)) {
            return;
        }

        if (\in_array($this->state, [PaymentState::Succeeded, PaymentState::Failed, PaymentState::Canceled], strict: true)) {
            $this->expectFailure($call, PaymentException::class, 'cancel refused');

            return;
        }

        $canceled = $call();
        $this->state = PaymentState::Canceled;
        $this->accepted(operation: 'cancel', request: null, key: $key, attempt: $canceled);
    }

    #[Rule]
    public function refund(?int $amount, ?string $key): void
    {
        $call = fn(): RefundAttempt => $this->core->createRefund(new CreateRefundRequest(
            operationId: $this->operationId(),
            payment: $this->payment,
            amount: $amount === null ? null : new Money(minorUnits: $amount, currency: self::CURRENCY),
            idempotencyKey: $key,
        ));

        if ($this->answeredByKey(operation: 'refund', request: $amount, key: $key, call: $call)) {
            return;
        }

        $balance = $this->captured - $this->refunded;

        if ($this->state !== PaymentState::Succeeded || $balance === 0 || ($amount ?? 0) > $balance) {
            $this->expectFailure($call, RefundFailedException::class, 'refund refused');

            return;
        }

        $refund = $call();
        $refunded = $amount ?? $balance;
        Assert::same($refund->requestedAmount->minorUnits, $refunded);
        $this->refunded += $refunded;
        $this->seen['refund accepted'] = true;

        if ($key !== null) {
            $this->keys[$key] = ['operation' => 'refund', 'request' => $amount, 'response' => $this->answer($refund)];
            $this->lastKey = $key;
        }
    }

    /**
     * Repeats the last keyed call the provider accepted, so replays are an
     * ordinary step rather than a lucky draw of the same key and request.
     */
    #[Rule]
    #[Precondition('hasKeyedCall')]
    public function replayLastKeyedCall(): void
    {
        $key = $this->lastKey;
        \assert($key !== null);
        $saved = $this->keys[$key];

        match ($saved['operation']) {
            'capture' => $this->capture(amount: $saved['request'], key: $key),
            'confirm' => $this->confirm(key: $key),
            'cancel' => $this->cancel(key: $key),
            default => $this->refund(amount: $saved['request'], key: $key),
        };
    }

    public function hasKeyedCall(): bool
    {
        return $this->lastKey !== null;
    }

    /**
     * A capture of more than was authorized, the request most often refused.
     */
    #[Rule]
    public function overCapture(?string $key): void
    {
        $this->capture(amount: self::AUTHORIZED + 1, key: $key);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function overCaptureGenerators(): array
    {
        return ['key' => self::key()];
    }

    /**
     * The create sent again — the resubmitted checkout form. It is answered
     * with the payment as it was created and moves nothing.
     */
    #[Rule]
    public function replayCreate(): void
    {
        $replayed = $this->core->createPayment($this->create);

        Assert::same($this->answer($replayed), $this->keys['create']['response']);
        $this->seen['create replayed'] = true;
    }

    #[Invariant]
    public function paymentMatchesTheModel(): void
    {
        $current = $this->core->retrievePayment(new RetrievePaymentRequest(
            operationId: $this->operationId(),
            payment: $this->payment,
        ));

        Assert::same($current->state, $this->state);
        Assert::same($current->amount->minorUnits, self::AUTHORIZED);
        Assert::same($current->capturedAmount?->minorUnits, $this->captured);
    }

    #[Invariant]
    public function moneyStaysWithinWhatWasTaken(): void
    {
        Assert::true($this->captured <= self::AUTHORIZED);
        Assert::true($this->refunded <= $this->captured);
    }

    /**
     * The fake moved the payment only along an edge of the documented state
     * machine, whatever the calls were.
     */
    #[Invariant]
    public function stateMovesOnlyAlongTheDocumentedMachine(): void
    {
        $current = $this->core->retrievePayment(new RetrievePaymentRequest(
            operationId: $this->operationId(),
            payment: $this->payment,
        ))->state;

        if ($current !== $this->observed) {
            Assert::true(\in_array($this->observed->value . '>' . $current->value, self::EDGES, strict: true));
            $this->observed = $current;
        }
    }

    /**
     * @return list<int|string|PaymentState|null>
     */
    private function answer(PaymentAttempt|RefundAttempt $attempt): array
    {
        return $attempt instanceof PaymentAttempt
            ? [$attempt->payment->id, $attempt->state, $attempt->amount->minorUnits, $attempt->capturedAmount?->minorUnits]
            : [$attempt->refund->id, $attempt->requestedAmount->minorUnits];
    }

    /**
     * A key seen before answers the call: the saved response for the same
     * request, a conflict for anything else.
     *
     * @param non-empty-string $operation
     * @param \Closure(): (PaymentAttempt|RefundAttempt) $call
     */
    private function answeredByKey(string $operation, ?int $request, ?string $key, \Closure $call): bool
    {
        if ($key === null || !isset($this->keys[$key])) {
            return false;
        }

        $saved = $this->keys[$key];

        if ($saved['operation'] !== $operation || $saved['request'] !== $request) {
            $this->expectFailure($call, IdempotencyConflictException::class, 'key conflict');

            return true;
        }

        Assert::same($this->answer($call()), $saved['response']);
        $this->seen[$operation . ' replayed'] = true;

        return true;
    }

    /**
     * @param non-empty-string $operation
     */
    private function accepted(string $operation, ?int $request, ?string $key, PaymentAttempt $attempt): void
    {
        Assert::same($attempt->state, $this->state);
        Assert::same($attempt->capturedAmount?->minorUnits, $this->captured);
        $this->seen[$operation . ' accepted'] = true;

        if ($key !== null) {
            $this->keys[$key] = ['operation' => $operation, 'request' => $request, 'response' => $this->answer($attempt)];
            $this->lastKey = $key;
        }
    }

    /**
     * The exact class is checked: `IdempotencyConflictException` and
     * `RefundFailedException` are both `PaymentException`s, and a conflict
     * must not pass for a state refusal.
     *
     * @param \Closure(): mixed $call
     * @param class-string<\Throwable> $expected
     * @param non-empty-string $label
     */
    private function expectFailure(\Closure $call, string $expected, string $label): void
    {
        try {
            $call();
        } catch (\Throwable $failure) {
            Assert::same($failure::class, $expected);
            $this->seen[$label] = true;

            return;
        }

        Assert::fail('Expected ' . $expected . ' (' . $label . ')');
    }

    private function operationId(): OperationId
    {
        return new OperationId(value: 'ledger-' . ++$this->operation);
    }

    /**
     * Amounts around the boundaries, drawn from a short list so that a key
     * is replayed with the same request often enough to matter.
     *
     * @return ArbitraryInterface<int|null>
     */
    private static function amount(): ArbitraryInterface
    {
        return Gen::elements([null, 0, 1, 300, 700, self::AUTHORIZED - 1, self::AUTHORIZED, self::AUTHORIZED + 1]);
    }

    /** @return ArbitraryInterface<string|null> */
    private static function key(): ArbitraryInterface
    {
        return Gen::elements([null, null, 'k1', 'k2', 'create']);
    }
}
