<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Internal;

use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProviderRequestInfo;
use Rasuvaeff\Payments\RefundAttempt;
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
    /** @var array<string, RefundAttempt> */
    private array $refunds = [];
    /** @var array<string, IdempotencyEntry> */
    private array $paymentIdempotency = [];
    /** @var array<string, IdempotencyEntry> */
    private array $refundIdempotency = [];
    /** @var array<string, non-empty-string> */
    private array $operationIdempotency = [];
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
        $id = $this->idempotentReference(
            key: $request->idempotencyKey,
            fingerprint: $fingerprint,
            prefix: 'pay',
            operationId: $request->operationId,
            index: ++$this->paymentSequence,
            entries: $this->paymentIdempotency,
        );
        $reference = new PaymentReference(provider: $this->config->provider, id: $id, kind: 'payment');
        $attempt = $this->paymentAttempt(
            operationId: $request->operationId,
            reference: $reference,
            amount: $request->amount,
            state: $this->config->createState,
            createdAt: $this->config->now,
        );
        $this->payments[$id] = $attempt;

        return $attempt;
    }

    public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
    {
        $stored = $this->payment($request->payment);

        return $this->paymentAttempt(
            operationId: $request->operationId,
            reference: $stored->payment,
            amount: $stored->amount,
            state: $stored->state,
            createdAt: $stored->createdAt,
        );
    }

    public function capturePayment(CapturePaymentRequest $request): PaymentAttempt
    {
        $stored = $this->payment($request->payment);
        $amount = $request->amount ?? $stored->amount;
        $this->assertOperationIdempotency(
            operation: 'capture',
            operationId: $request->operationId,
            key: $request->idempotencyKey,
            fingerprint: hash('sha256', $stored->payment->id . '|' . $amount->minorUnits . '|' . $amount->currency),
        );

        return $this->replacePayment($stored, $request->operationId, $amount, PaymentState::Succeeded);
    }

    public function confirmPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        $stored = $this->payment($request->payment);
        $this->assertOperationIdempotency(
            operation: 'confirm',
            operationId: $request->operationId,
            key: $request->idempotencyKey,
            fingerprint: hash('sha256', $stored->payment->id),
        );

        return $this->replacePayment($stored, $request->operationId, $stored->amount, PaymentState::Processing);
    }

    public function cancelPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        $stored = $this->payment($request->payment);
        $this->assertOperationIdempotency(
            operation: 'cancel',
            operationId: $request->operationId,
            key: $request->idempotencyKey,
            fingerprint: hash('sha256', $stored->payment->id),
        );

        return $this->replacePayment($stored, $request->operationId, $stored->amount, PaymentState::Canceled);
    }

    public function createRefund(CreateRefundRequest $request): RefundAttempt
    {
        $payment = $this->payment($request->payment);
        $amount = $request->amount ?? $payment->amount;
        $fingerprint = hash('sha256', serialize([
            $payment->payment->id,
            $amount->minorUnits,
            $amount->currency,
            $request->reason?->value,
        ]));
        $id = $this->idempotentReference(
            key: $request->idempotencyKey,
            fingerprint: $fingerprint,
            prefix: 'ref',
            operationId: $request->operationId,
            index: ++$this->refundSequence,
            entries: $this->refundIdempotency,
        );
        $reference = new RefundReference(provider: $this->config->provider, id: $id, kind: 'refund');
        $attempt = $this->refundAttempt($request->operationId, $reference, $payment->payment, $amount, $this->config->now);
        $this->refunds[$id] = $attempt;

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
     * @param non-empty-string|null $key
     * @param non-empty-string $fingerprint
     * @param non-empty-string $prefix
     * @param positive-int $index
     * @param array<string, IdempotencyEntry> $entries
     * @return non-empty-string
     */
    private function idempotentReference(
        ?string $key,
        string $fingerprint,
        string $prefix,
        OperationId $operationId,
        int $index,
        array &$entries,
    ): string {
        $scope = $key === null ? null : $operationId->value . "\0" . $key;

        if ($scope !== null && isset($entries[$scope])) {
            if ($entries[$scope]['fingerprint'] !== $fingerprint) {
                throw new \InvalidArgumentException('Idempotency key was reused with a different fake request');
            }

            return $entries[$scope]['reference'];
        }

        $reference = $prefix . '_' . substr(hash('sha256', $operationId->value . '|' . $index), 0, 16);

        if ($scope !== null) {
            $entries[$scope] = ['fingerprint' => $fingerprint, 'reference' => $reference];
        }

        return $reference;
    }

    /**
     * @param non-empty-string $operation
     * @param non-empty-string|null $key
     * @param non-empty-string $fingerprint
     */
    private function assertOperationIdempotency(
        string $operation,
        OperationId $operationId,
        ?string $key,
        string $fingerprint,
    ): void {
        if ($key === null) {
            return;
        }

        $scope = $operation . "\0" . $operationId->value . "\0" . $key;

        if (isset($this->operationIdempotency[$scope]) && $this->operationIdempotency[$scope] !== $fingerprint) {
            throw new \InvalidArgumentException('Idempotency key was reused with a different fake request');
        }

        $this->operationIdempotency[$scope] = $fingerprint;
    }
}
