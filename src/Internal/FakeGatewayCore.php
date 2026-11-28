<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Internal;

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
use Rasuvaeff\Payments\ProviderRequestInfo;
use Rasuvaeff\Payments\RefundAttempt;
use Rasuvaeff\Payments\RefundFailedException;
use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\Payments\RefundState;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\Payments\RetrieveRefundRequest;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;

/**
 * @internal
 *
 * @psalm-import-type IdempotencyEntry from Types
 */
final class FakeGatewayCore
{
    /** @var array<string, PaymentAttempt> */
    private array $payments = [];
    /** @var array<string, PaymentAttempt> */
    private array $createResponses = [];
    /** @var array<string, Money> */
    private array $authorized = [];
    /** @var array<string, int<0, max>> */
    private array $refunded = [];
    /** @var array<string, RefundAttempt> */
    private array $refunds = [];
    /** @var array<string, IdempotencyEntry> */
    private array $paymentIdempotency = [];
    /** @var array<string, IdempotencyEntry> */
    private array $refundIdempotency = [];
    /** @var array<string, array<string, non-empty-string>> */
    private array $operationIdempotency = [];
    /** @var array<string, array<string, PaymentAttempt>> */
    private array $operationResponses = [];
    /** @var int<0, max> */
    private int $paymentSequence = 0;
    /** @var int<0, max> */
    private int $refundSequence = 0;

    public function __construct(public readonly FakeGatewayConfig $config) {}

    public function createPayment(CreatePaymentRequest $request): PaymentAttempt
    {
        $fingerprint = hash('sha256', serialize([
            $request->amount->minorUnits,
            $request->amount->currency,
            $request->paymentMethod?->id,
            $request->paymentMethod?->kind,
            $request->captureMethod->value,
            $request->confirmationMethod->value,
            $request->description,
            $request->metadata,
        ]));
        $index = ++$this->paymentSequence;
        $replayed = $this->replayedReference(
            key: $request->idempotencyKey,
            fingerprint: $fingerprint,
            entries: $this->paymentIdempotency,
        );

        // A replay is answered from the response saved for the key, the way
        // a provider answers it; the payment may have moved on since and is
        // left exactly as it is.
        if ($replayed !== null) {
            $original = $this->createResponses[$replayed];

            return $this->paymentAttempt(
                operationId: $request->operationId,
                reference: $original->payment,
                amount: $original->amount,
                state: $original->state,
                createdAt: $original->createdAt,
            );
        }

        $id = $this->newReference(prefix: 'pay', operationId: $request->operationId, index: $index);
        $this->remember(key: $request->idempotencyKey, fingerprint: $fingerprint, reference: $id, entries: $this->paymentIdempotency);
        $reference = new PaymentReference(provider: $this->config->provider, id: $id, kind: 'payment');
        $attempt = $this->paymentAttempt(
            operationId: $request->operationId,
            reference: $reference,
            amount: $request->amount,
            state: $this->config->createState,
            createdAt: $this->config->now,
        );
        $this->payments[$id] = $attempt;
        $this->createResponses[$id] = $attempt;
        $this->authorized[$id] = $request->amount;

        return $attempt;
    }

    public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
    {
        $stored = $this->payment($request->payment);

        return $this->restamp(attempt: $stored, operationId: $request->operationId);
    }

    public function capturePayment(CapturePaymentRequest $request): PaymentAttempt
    {
        $stored = $this->payment($request->payment);
        $amount = $request->amount ?? $stored->amount;
        $fingerprint = hash('sha256', serialize([$stored->payment->id, $amount->minorUnits, $amount->currency]));
        $saved = $this->savedOperation(operation: 'capture', key: $request->idempotencyKey, fingerprint: $fingerprint);

        if ($saved instanceof PaymentAttempt) {
            return $this->restamp(attempt: $saved, operationId: $request->operationId);
        }

        if ($amount->currency !== $stored->amount->currency) {
            throw new PaymentException(message: 'Capture currency differs from the fake payment currency');
        }

        if ($amount->minorUnits > $this->authorized[$stored->payment->id]->minorUnits) {
            throw new PaymentException(message: 'Capture amount exceeds the authorized amount of the fake payment');
        }

        $attempt = $this->replacePayment($stored, $request->operationId, $amount, PaymentState::Succeeded);
        $this->saveOperation(operation: 'capture', key: $request->idempotencyKey, fingerprint: $fingerprint, attempt: $attempt);

        return $attempt;
    }

    public function confirmPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        return $this->operate(request: $request, operation: 'confirm', state: PaymentState::Processing);
    }

    public function cancelPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        return $this->operate(request: $request, operation: 'cancel', state: PaymentState::Canceled);
    }

    public function createRefund(CreateRefundRequest $request): RefundAttempt
    {
        $payment = $this->payment($request->payment);
        $fingerprint = hash('sha256', serialize([
            $payment->payment->id,
            $request->amount?->minorUnits,
            $request->amount?->currency,
            $request->reason?->value,
        ]));
        $index = ++$this->refundSequence;
        $replayed = $this->replayedReference(
            key: $request->idempotencyKey,
            fingerprint: $fingerprint,
            entries: $this->refundIdempotency,
        );

        // Answered from the saved refund: a replay neither moves money again
        // nor counts against the balance a second time.
        if ($replayed !== null) {
            $stored = $this->refunds[$replayed];

            return $this->refundAttempt(
                operationId: $request->operationId,
                refund: $stored->refund,
                payment: $stored->payment,
                amount: $stored->requestedAmount,
                createdAt: $stored->createdAt,
            );
        }

        $amount = $this->refundableAmount(payment: $payment, requested: $request->amount);
        $id = $this->newReference(prefix: 'ref', operationId: $request->operationId, index: $index);
        $this->remember(key: $request->idempotencyKey, fingerprint: $fingerprint, reference: $id, entries: $this->refundIdempotency);
        $reference = new RefundReference(provider: $this->config->provider, id: $id, kind: 'refund');
        $attempt = $this->refundAttempt($request->operationId, $reference, $payment->payment, $amount, $this->config->now);
        $this->refunds[$id] = $attempt;
        $this->refunded[$payment->payment->id] = ($this->refunded[$payment->payment->id] ?? 0) + $amount->minorUnits;

        return $attempt;
    }

    public function retrieveRefund(RetrieveRefundRequest $request): RefundAttempt
    {
        $stored = $this->refund($request->refund);

        return $this->refundAttempt(
            operationId: $request->operationId,
            refund: $stored->refund,
            payment: $stored->payment,
            amount: $stored->requestedAmount,
            createdAt: $stored->createdAt,
        );
    }

    /**
     * A provider refuses a refund it cannot honour: of a payment that was
     * never paid, in another currency, or above what is left after earlier
     * refunds. Without an amount it refunds the remaining balance.
     */
    private function refundableAmount(PaymentAttempt $payment, ?Money $requested): Money
    {
        if ($payment->state !== PaymentState::Succeeded) {
            throw new RefundFailedException(message: 'Only a succeeded fake payment can be refunded');
        }

        if ($requested instanceof Money && $requested->currency !== $payment->amount->currency) {
            throw new RefundFailedException(message: 'Refund currency differs from the fake payment currency');
        }

        // Capture is not state-gated: a payment captured again for less than
        // was already refunded has nothing left, not a negative balance.
        $balance = new Money(
            minorUnits: max(0, $payment->amount->minorUnits - ($this->refunded[$payment->payment->id] ?? 0)),
            currency: $payment->amount->currency,
        );

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

    /**
     * @param non-empty-string $operation
     */
    private function operate(PaymentOperationRequest $request, string $operation, PaymentState $state): PaymentAttempt
    {
        $stored = $this->payment($request->payment);
        $fingerprint = hash('sha256', $stored->payment->id);
        $saved = $this->savedOperation(operation: $operation, key: $request->idempotencyKey, fingerprint: $fingerprint);

        if ($saved instanceof PaymentAttempt) {
            return $this->restamp(attempt: $saved, operationId: $request->operationId);
        }

        $attempt = $this->replacePayment($stored, $request->operationId, $stored->amount, $state);
        $this->saveOperation(operation: $operation, key: $request->idempotencyKey, fingerprint: $fingerprint, attempt: $attempt);

        return $attempt;
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

    private function replacePayment(
        PaymentAttempt $stored,
        OperationId $operationId,
        Money $amount,
        PaymentState $state,
    ): PaymentAttempt {
        $attempt = $this->paymentAttempt($operationId, $stored->payment, $amount, $state, $stored->createdAt);
        $this->payments[$stored->payment->id] = $attempt;

        return $attempt;
    }

    private function restamp(PaymentAttempt $attempt, OperationId $operationId): PaymentAttempt
    {
        return $this->paymentAttempt(
            operationId: $operationId,
            reference: $attempt->payment,
            amount: $attempt->amount,
            state: $attempt->state,
            createdAt: $attempt->createdAt,
        );
    }

    private function paymentAttempt(
        OperationId $operationId,
        PaymentReference $reference,
        Money $amount,
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
     * The reference saved for a replayed key, or null for a new request.
     *
     * A provider never sees the application's OperationId: it keys the
     * replay on the header value alone. Scoping by operation here would make
     * the fake accept a key collision that the real gateway refuses.
     *
     * @param non-empty-string|null $key
     * @param non-empty-string $fingerprint
     * @param array<string, IdempotencyEntry> $entries
     * @return non-empty-string|null
     */
    private function replayedReference(?string $key, string $fingerprint, array $entries): ?string
    {
        if ($key === null || !isset($entries[$key])) {
            return null;
        }

        if ($entries[$key]['fingerprint'] !== $fingerprint) {
            throw new \InvalidArgumentException('Idempotency key was reused with a different fake request');
        }

        return $entries[$key]['reference'];
    }

    /**
     * Recorded only once the request succeeded, so a refused request leaves
     * its key free for a corrected one.
     *
     * @param non-empty-string|null $key
     * @param non-empty-string $fingerprint
     * @param non-empty-string $reference
     * @param array<string, IdempotencyEntry> $entries
     */
    private function remember(?string $key, string $fingerprint, string $reference, array &$entries): void
    {
        if ($key !== null) {
            $entries[$key] = ['fingerprint' => $fingerprint, 'reference' => $reference];
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

    /**
     * @param non-empty-string $operation
     * @param non-empty-string|null $key
     * @param non-empty-string $fingerprint
     */
    private function savedOperation(string $operation, ?string $key, string $fingerprint): ?PaymentAttempt
    {
        if ($key === null || !isset($this->operationIdempotency[$operation][$key])) {
            return null;
        }

        if ($this->operationIdempotency[$operation][$key] !== $fingerprint) {
            throw new \InvalidArgumentException('Idempotency key was reused with a different fake request');
        }

        return $this->operationResponses[$operation][$key];
    }

    /**
     * @param non-empty-string $operation
     * @param non-empty-string|null $key
     * @param non-empty-string $fingerprint
     */
    private function saveOperation(string $operation, ?string $key, string $fingerprint, PaymentAttempt $attempt): void
    {
        if ($key !== null) {
            $this->operationIdempotency[$operation][$key] = $fingerprint;
            $this->operationResponses[$operation][$key] = $attempt;
        }
    }
}
