<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests\Support;

use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentException;
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
 * Model of one provider payment the fake must agree with: an authorization
 * of 1000 EUR that can be captured, refunded and canceled in any order, each
 * call with or without an idempotency key from a small shared pool, so
 * replays and key conflicts are ordinary.
 *
 * The model is the money the provider would allow — the replay of a key
 * answers from its first call, a refund never takes more than the balance
 * left — not a restatement of the fake's code.
 */
final class FakeLedgerMachine
{
    private const int AUTHORIZED = 1_000;
    private const string CURRENCY = 'EUR';

    /**
     * Which branches the sequence took; the property gates its coverage on them.
     *
     * @var array<non-empty-string, true>
     */
    public array $seen = [];

    private readonly FakeGatewayCore $core;
    private readonly PaymentReference $payment;
    private PaymentState $state = PaymentState::RequiresCapture;
    private int $amount = self::AUTHORIZED;
    private int $refunded = 0;
    private int $operation = 0;
    /** @var array<string, array{request: ?int, state: PaymentState, amount: int}> */
    private array $captureKeys = [];
    /** @var array<string, array{request: ?int, id: string, amount: int}> */
    private array $refundKeys = [];
    /** @var array<string, PaymentState> */
    private array $cancelKeys = [];

    public function __construct()
    {
        $this->core = new FakeGatewayCore(new FakeGatewayConfig(createState: PaymentState::RequiresCapture));
        $this->payment = $this->core->createPayment(new CreatePaymentRequest(
            operationId: $this->operationId(),
            amount: new Money(minorUnits: self::AUTHORIZED, currency: self::CURRENCY),
            idempotencyKey: 'create',
        ))->payment;
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
    public static function cancelGenerators(): array
    {
        return ['key' => self::key()];
    }

    #[Rule]
    public function capture(?int $amount, ?string $key): void
    {
        $resolved = $amount ?? $this->amount;
        $call = fn(): PaymentAttempt => $this->core->capturePayment(new CapturePaymentRequest(
            operationId: $this->operationId(),
            payment: $this->payment,
            amount: $amount === null ? null : new Money(minorUnits: $amount, currency: self::CURRENCY),
            idempotencyKey: $key,
        ));

        if ($key !== null && isset($this->captureKeys[$key])) {
            $saved = $this->captureKeys[$key];

            if ($saved['amount'] !== $resolved) {
                $this->expectFailure($call, \InvalidArgumentException::class, 'key conflict');

                return;
            }

            $replayed = $call();
            Assert::same($replayed->state, $saved['state']);
            Assert::same($replayed->amount->minorUnits, $saved['amount']);
            $this->seen['capture replayed'] = true;

            return;
        }

        if ($resolved > self::AUTHORIZED) {
            $this->expectFailure($call, PaymentException::class, 'capture refused');

            return;
        }

        $call();
        $this->state = PaymentState::Succeeded;
        $this->amount = $resolved;

        if ($key !== null) {
            $this->captureKeys[$key] = ['request' => $amount, 'state' => $this->state, 'amount' => $resolved];
        }
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

        if ($key !== null && isset($this->refundKeys[$key])) {
            $saved = $this->refundKeys[$key];

            if ($saved['request'] !== $amount) {
                $this->expectFailure($call, \InvalidArgumentException::class, 'key conflict');

                return;
            }

            $replayed = $call();
            Assert::same($replayed->refund->id, $saved['id']);
            Assert::same($replayed->requestedAmount->minorUnits, $saved['amount']);
            $this->seen['refund replayed'] = true;

            return;
        }

        $balance = max(0, $this->amount - $this->refunded);

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
            $this->refundKeys[$key] = ['request' => $amount, 'id' => $refund->refund->id, 'amount' => $refunded];
        }
    }

    /**
     * Repeats the last keyed refund the provider accepted, so replays are an
     * ordinary step rather than a lucky draw of the same key and amount.
     */
    #[Rule]
    #[Precondition('hasKeyedRefund')]
    public function replayLastRefund(): void
    {
        $key = array_key_last($this->refundKeys);
        \assert($key !== null);

        $this->refund(amount: $this->refundKeys[$key]['request'], key: $key);
    }

    public function hasKeyedRefund(): bool
    {
        return $this->refundKeys !== [];
    }

    #[Rule]
    public function cancel(?string $key): void
    {
        $canceled = $this->core->cancelPayment(new PaymentOperationRequest(
            operationId: $this->operationId(),
            payment: $this->payment,
            idempotencyKey: $key,
        ));

        if ($key !== null && isset($this->cancelKeys[$key])) {
            Assert::same($canceled->state, $this->cancelKeys[$key]);

            return;
        }

        Assert::same($canceled->state, PaymentState::Canceled);
        $this->state = PaymentState::Canceled;

        if ($key !== null) {
            $this->cancelKeys[$key] = PaymentState::Canceled;
        }
    }

    #[Invariant]
    public function paymentMatchesTheModel(): void
    {
        $current = $this->core->retrievePayment(new RetrievePaymentRequest(
            operationId: $this->operationId(),
            payment: $this->payment,
        ));

        Assert::same($current->state, $this->state);
        Assert::same($current->amount->minorUnits, $this->amount);
    }

    #[Invariant]
    public function refundsNeverExceedTheAuthorization(): void
    {
        Assert::true($this->refunded <= self::AUTHORIZED);
    }

    /**
     * @param \Closure(): mixed $call
     * @param class-string<\Throwable> $expected
     * @param non-empty-string $label
     */
    private function expectFailure(\Closure $call, string $expected, string $label): void
    {
        try {
            $call();
        } catch (\Throwable $failure) {
            Assert::true($failure instanceof $expected);
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
        return Gen::elements([null, 'k1', 'k2']);
    }
}
