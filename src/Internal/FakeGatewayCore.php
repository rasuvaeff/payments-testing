<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Internal;

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
use Rasuvaeff\Payments\ProviderRequestInfo;
use Rasuvaeff\Payments\RefundAttempt;
use Rasuvaeff\Payments\RefundFailedException;
use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\Payments\RefundState;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\Payments\RetrieveRefundRequest;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;

/**
 * The provider model behind every fake gateway.
 *
 * Operations are gated by payment state the way Stripe and PayPal gate them:
 *
 * | Operation | Allowed from | Result |
 * |---|---|---|
 * | capture | `RequiresCapture` | `Succeeded`, `capturedAmount` = the captured amount |
 * | confirm | `Pending`, `RequiresAction` | `RequiresCapture` (manual capture) or `Succeeded` |
 * | cancel | every state but `Succeeded`, `Failed`, `Canceled` | `Canceled` |
 * | refund | `Succeeded`, up to `capturedAmount` minus earlier refunds | refund `Succeeded` |
 *
 * Idempotency keys share one space across operations: a key is answered
 * from its first response when the request is the same, and refused with
 * `IdempotencyConflictException` when anything differs — another payload,
 * another payment or another operation. A replay is checked before the
 * state gate, as a provider does.
 *
 * @internal
 *
 * @psalm-import-type IdempotencyEntry from Types
 */
final class FakeGatewayCore
{
    /** @var array<string, PaymentAttempt> */
    private array $payments = [];
    /** @var array<string, CaptureMethod> */
    private array $captureMethods = [];
    /** @var array<string, int<0, max>> */
    private array $refunded = [];
    /** @var array<string, RefundAttempt> */
    private array $refunds = [];
    /** @var array<string, IdempotencyEntry> */
    private array $idempotency = [];
    /** @var int<0, max> */
    private int $paymentSequence = 0;
    /** @var int<0, max> */
    private int $refundSequence = 0;

    public function __construct(public readonly FakeGatewayConfig $config) {}

    public function createPayment(CreatePaymentRequest $request): PaymentAttempt
    {
        $fingerprint = $this->fingerprint([
            'create',
            $request->amount->minorUnits,
            $request->amount->currency,
            $request->paymentMethod?->id,
            $request->paymentMethod?->kind,
            $request->captureMethod->value,
            $request->confirmationMethod->value,
            $request->description,
            $request->metadata,
        ]);
        $index = ++$this->paymentSequence;
        $saved = $this->saved(key: $request->idempotencyKey, fingerprint: $fingerprint);

        // A replay is answered from the response saved for the key, the way
        // a provider answers it; the payment may have moved on since and is
        // left exactly as it is.
        if ($saved instanceof PaymentAttempt) {
            return $this->restamp(attempt: $saved, operationId: $request->operationId);
        }

        $id = $this->newReference(prefix: 'pay', operationId: $request->operationId, index: $index);
        $reference = new PaymentReference(provider: $this->config->provider, id: $id, kind: 'payment');
        $state = $this->config->createState ?? $this->createdState($request);
        $attempt = $this->paymentAttempt(
            operationId: $request->operationId,
            reference: $reference,
            amount: $request->amount,
            capturedAmount: $state === PaymentState::Succeeded ? $request->amount : $this->nothing($request->amount),
            state: $state,
            createdAt: $this->config->now,
        );
        $this->payments[$id] = $attempt;
        $this->captureMethods[$id] = $request->captureMethod;
        $this->save(key: $request->idempotencyKey, fingerprint: $fingerprint, response: $attempt);

        return $attempt;
    }

    public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
    {
        return $this->restamp(attempt: $this->payment($request->payment), operationId: $request->operationId);
    }

    public function capturePayment(CapturePaymentRequest $request): PaymentAttempt
    {
        $stored = $this->payment($request->payment);
        $amount = $request->amount ?? $stored->amount;
        $fingerprint = $this->fingerprint(['capture', $stored->payment->id, $amount->minorUnits, $amount->currency]);
        $saved = $this->saved(key: $request->idempotencyKey, fingerprint: $fingerprint);

        if ($saved instanceof PaymentAttempt) {
            return $this->restamp(attempt: $saved, operationId: $request->operationId);
        }

        $this->assertState(payment: $stored, allowed: [PaymentState::RequiresCapture], operation: 'captured');

        if ($amount->currency !== $stored->amount->currency) {
            throw new PaymentException(message: 'Capture currency differs from the fake payment currency');
        }

        if ($amount->isGreaterThan($stored->amount)) {
            throw new PaymentException(message: 'Capture amount exceeds the authorized amount of the fake payment');
        }

        $attempt = $this->move(stored: $stored, operationId: $request->operationId, state: PaymentState::Succeeded, capturedAmount: $amount);
        $this->save(key: $request->idempotencyKey, fingerprint: $fingerprint, response: $attempt);

        return $attempt;
    }

    public function confirmPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        $stored = $this->payment($request->payment);
        $fingerprint = $this->fingerprint(['confirm', $stored->payment->id]);
        $saved = $this->saved(key: $request->idempotencyKey, fingerprint: $fingerprint);

        if ($saved instanceof PaymentAttempt) {
            return $this->restamp(attempt: $saved, operationId: $request->operationId);
        }

        $this->assertState(payment: $stored, allowed: [PaymentState::Pending, PaymentState::RequiresAction], operation: 'confirmed');
        $attempt = $this->captureMethods[$stored->payment->id] === CaptureMethod::Manual
            ? $this->move(stored: $stored, operationId: $request->operationId, state: PaymentState::RequiresCapture, capturedAmount: $this->nothing($stored->amount))
            : $this->move(stored: $stored, operationId: $request->operationId, state: PaymentState::Succeeded, capturedAmount: $stored->amount);
        $this->save(key: $request->idempotencyKey, fingerprint: $fingerprint, response: $attempt);

        return $attempt;
    }

    public function cancelPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        $stored = $this->payment($request->payment);
        $fingerprint = $this->fingerprint(['cancel', $stored->payment->id]);
        $saved = $this->saved(key: $request->idempotencyKey, fingerprint: $fingerprint);

        if ($saved instanceof PaymentAttempt) {
            return $this->restamp(attempt: $saved, operationId: $request->operationId);
        }

        $this->assertState(
            payment: $stored,
            allowed: [
                PaymentState::Pending,
                PaymentState::RequiresPaymentMethod,
                PaymentState::RequiresAction,
                PaymentState::RequiresCapture,
                PaymentState::Processing,
            ],
            operation: 'canceled',
        );
        $attempt = $this->move(stored: $stored, operationId: $request->operationId, state: PaymentState::Canceled, capturedAmount: $stored->capturedAmount);
        $this->save(key: $request->idempotencyKey, fingerprint: $fingerprint, response: $attempt);

        return $attempt;
    }

    /**
     * What happens at the provider without an API call of the application:
     * the customer completes 3-D Secure or pays a deferred payment, a bank
     * transfer settles, an authorization expires. Only a move the advisory
     * state map allows is accepted; reaching `Succeeded` without a capture
     * captures the whole amount.
     */
    public function transition(PaymentReference $payment, PaymentState $state, OperationId $operationId): PaymentAttempt
    {
        $stored = $this->payment($payment);

        if (!$stored->state->canTransitionTo($state)) {
            throw new \InvalidArgumentException(\sprintf('Fake payment cannot move from %s to %s', $stored->state->value, $state->value));
        }

        $captured = $stored->capturedAmount ?? $this->nothing($stored->amount);

        return $this->move(
            stored: $stored,
            operationId: $operationId,
            state: $state,
            capturedAmount: $state === PaymentState::Succeeded && $captured->isZero() ? $stored->amount : $captured,
        );
    }

    public function createRefund(CreateRefundRequest $request): RefundAttempt
    {
        $payment = $this->payment($request->payment);
        $fingerprint = $this->fingerprint([
            'refund',
            $payment->payment->id,
            $request->amount?->minorUnits,
            $request->amount?->currency,
            $request->reason?->value,
        ]);
        $index = ++$this->refundSequence;
        $saved = $this->saved(key: $request->idempotencyKey, fingerprint: $fingerprint);

        // Answered from the saved refund: a replay neither moves money again
        // nor counts against the balance a second time.
        if ($saved instanceof RefundAttempt) {
            return $this->restampRefund(attempt: $saved, operationId: $request->operationId);
        }

        $amount = $this->refundableAmount(payment: $payment, requested: $request->amount);
        $id = $this->newReference(prefix: 'ref', operationId: $request->operationId, index: $index);
        $reference = new RefundReference(provider: $this->config->provider, id: $id, kind: 'refund');
        $attempt = $this->refundAttempt($request->operationId, $reference, $payment->payment, $amount, $this->config->now);
        $this->refunds[$id] = $attempt;
        $this->refunded[$payment->payment->id] = ($this->refunded[$payment->payment->id] ?? 0) + $amount->minorUnits;
        $this->save(key: $request->idempotencyKey, fingerprint: $fingerprint, response: $attempt);

        return $attempt;
    }

    public function retrieveRefund(RetrieveRefundRequest $request): RefundAttempt
    {
        return $this->restampRefund(attempt: $this->refund($request->refund), operationId: $request->operationId);
    }

    /**
     * The state a provider answers a create with when the test does not pin
     * one: no payment method yet, a confirmation left to the server, an
     * authorization held for capture, or a payment that went through.
     */
    private function createdState(CreatePaymentRequest $request): PaymentState
    {
        return match (true) {
            !$request->paymentMethod instanceof PaymentMethodReference => PaymentState::RequiresPaymentMethod,
            $request->confirmationMethod === ConfirmationMethod::Manual => PaymentState::Pending,
            $request->captureMethod === CaptureMethod::Manual => PaymentState::RequiresCapture,
            default => PaymentState::Succeeded,
        };
    }

    /**
     * @param list<PaymentState> $allowed
     * @param non-empty-string $operation
     */
    private function assertState(PaymentAttempt $payment, array $allowed, string $operation): void
    {
        if (!\in_array($payment->state, $allowed, strict: true)) {
            throw new PaymentException(message: \sprintf('Fake payment in state %s cannot be %s', $payment->state->value, $operation));
        }
    }

    /**
     * A provider refuses a refund it cannot honour: of a payment that was
     * never paid, in another currency, or above what was captured less
     * earlier refunds. Without an amount it refunds the remaining balance.
     */
    private function refundableAmount(PaymentAttempt $payment, ?Money $requested): Money
    {
        if ($payment->state !== PaymentState::Succeeded) {
            throw new RefundFailedException(message: 'Only a succeeded fake payment can be refunded');
        }

        if ($requested instanceof Money && $requested->currency !== $payment->amount->currency) {
            throw new RefundFailedException(message: 'Refund currency differs from the fake payment currency');
        }

        // Every succeeded fake payment carries a captured amount, and
        // refunds never exceed it.
        $captured = $payment->capturedAmount ?? $payment->amount;
        $balance = $captured->subtract(new Money(minorUnits: $this->refunded[$payment->payment->id] ?? 0, currency: $captured->currency));

        if ($balance->isZero()) {
            throw new RefundFailedException(message: 'Fake payment has no refundable balance left');
        }

        if (!$requested instanceof Money) {
            return $balance;
        }

        if ($requested->isGreaterThan($balance)) {
            throw new RefundFailedException(message: 'Refund amount exceeds the refundable balance of the fake payment');
        }

        return $requested;
    }

    private function payment(PaymentReference $reference): PaymentAttempt
    {
        $this->assertProvider($reference->provider->value);

        return $this->payments[$reference->id] ?? throw new \OutOfBoundsException('Unknown fake payment reference');
    }

    private function refund(RefundReference $reference): RefundAttempt
    {
        $this->assertProvider($reference->provider->value);

        return $this->refunds[$reference->id] ?? throw new \OutOfBoundsException('Unknown fake refund reference');
    }

    private function assertProvider(string $provider): void
    {
        if ($provider !== $this->config->provider->value) {
            throw new \InvalidArgumentException('Reference provider does not match fake gateway provider');
        }
    }

    private function move(
        PaymentAttempt $stored,
        OperationId $operationId,
        PaymentState $state,
        ?Money $capturedAmount,
    ): PaymentAttempt {
        $attempt = $this->paymentAttempt($operationId, $stored->payment, $stored->amount, $capturedAmount, $state, $stored->createdAt);
        $this->payments[$stored->payment->id] = $attempt;

        return $attempt;
    }

    private function nothing(Money $amount): Money
    {
        return new Money(minorUnits: 0, currency: $amount->currency);
    }

    private function restamp(PaymentAttempt $attempt, OperationId $operationId): PaymentAttempt
    {
        return $this->paymentAttempt(
            operationId: $operationId,
            reference: $attempt->payment,
            amount: $attempt->amount,
            capturedAmount: $attempt->capturedAmount,
            state: $attempt->state,
            createdAt: $attempt->createdAt,
        );
    }

    private function restampRefund(RefundAttempt $attempt, OperationId $operationId): RefundAttempt
    {
        return $this->refundAttempt(
            operationId: $operationId,
            refund: $attempt->refund,
            payment: $attempt->payment,
            amount: $attempt->requestedAmount,
            createdAt: $attempt->createdAt,
        );
    }

    private function paymentAttempt(
        OperationId $operationId,
        PaymentReference $reference,
        Money $amount,
        ?Money $capturedAmount,
        PaymentState $state,
        \DateTimeImmutable $createdAt,
    ): PaymentAttempt {
        return new PaymentAttempt(
            operationId: $operationId,
            provider: $this->config->provider,
            payment: $reference,
            amount: $amount,
            state: $state,
            rawStatus: 'fake_' . $state->value,
            createdAt: $createdAt,
            updatedAt: $this->config->now,
            requestInfo: new ProviderRequestInfo(receivedAt: $this->config->now, requestId: 'fake-request'),
            capturedAmount: $capturedAmount,
        );
    }

    private function refundAttempt(
        OperationId $operationId,
        RefundReference $refund,
        PaymentReference $payment,
        Money $amount,
        \DateTimeImmutable $createdAt,
    ): RefundAttempt {
        return new RefundAttempt(
            operationId: $operationId,
            provider: $this->config->provider,
            refund: $refund,
            payment: $payment,
            requestedAmount: $amount,
            actualAmount: $amount,
            state: RefundState::Succeeded,
            rawStatus: 'fake_succeeded',
            createdAt: $createdAt,
            updatedAt: $this->config->now,
            requestInfo: new ProviderRequestInfo(receivedAt: $this->config->now, requestId: 'fake-request'),
        );
    }

    /**
     * @param list<mixed> $request
     * @return non-empty-string
     */
    private function fingerprint(array $request): string
    {
        return hash('sha256', serialize($request));
    }

    /**
     * The response saved for a replayed key, or null for a new request.
     *
     * A provider never sees the application's OperationId: it keys the
     * replay on the header value alone, across every endpoint. Scoping by
     * operation here would make the fake accept a key collision that the
     * real gateway refuses.
     *
     * @param non-empty-string|null $key
     * @param non-empty-string $fingerprint
     */
    private function saved(?string $key, string $fingerprint): PaymentAttempt|RefundAttempt|null
    {
        if ($key === null || !isset($this->idempotency[$key])) {
            return null;
        }

        if ($this->idempotency[$key]['fingerprint'] !== $fingerprint) {
            throw new IdempotencyConflictException(message: 'Idempotency key was reused with a different fake request');
        }

        return $this->idempotency[$key]['response'];
    }

    /**
     * Recorded only once the request succeeded, so a refused request leaves
     * its key free for a corrected one.
     *
     * @param non-empty-string|null $key
     * @param non-empty-string $fingerprint
     */
    private function save(?string $key, string $fingerprint, PaymentAttempt|RefundAttempt $response): void
    {
        if ($key !== null) {
            $this->idempotency[$key] = ['fingerprint' => $fingerprint, 'response' => $response];
        }
    }

    /**
     * @param non-empty-string $prefix
     * @param positive-int $index
     * @return non-empty-string
     */
    private function newReference(string $prefix, OperationId $operationId, int $index): string
    {
        return $prefix . '_' . substr(hash('sha256', $operationId->value . '|' . $index), 0, 16);
    }
}
