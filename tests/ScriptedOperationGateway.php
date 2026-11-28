<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CancelGatewayInterface;
use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CaptureGatewayInterface;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\ConfirmGatewayInterface;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProviderRequestInfo;
use Rasuvaeff\Payments\RetrievePaymentRequest;

/**
 * Answers capture, confirm and cancel from a script, so a test can build the
 * exact broken behaviour an idempotency assertion must catch.
 *
 * The script receives the operation name, the 1-based call number of that
 * operation and the request.
 */
final class ScriptedOperationGateway implements
    PaymentGatewayInterface,
    CaptureGatewayInterface,
    ConfirmGatewayInterface,
    CancelGatewayInterface
{
    /** @var array<string, int> */
    private array $calls = [];

    /**
     * @param \Closure(string, int, CapturePaymentRequest|PaymentOperationRequest): PaymentAttempt $script
     */
    public function __construct(private readonly \Closure $script) {}

    public static function attempt(
        OperationId $operationId,
        string $id,
        int $amount = 100,
        PaymentState $state = PaymentState::Succeeded,
        ?Money $captured = null,
        bool $reportsCaptured = true,
    ): PaymentAttempt {
        $provider = new PaymentProvider('fake');
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        return new PaymentAttempt(
            operationId: $operationId,
            provider: $provider,
            payment: new PaymentReference($provider, $id),
            amount: new Money($amount, 'EUR'),
            state: $state,
            rawStatus: $state->value,
            createdAt: $now,
            updatedAt: $now,
            requestInfo: new ProviderRequestInfo($now),
            capturedAmount: $reportsCaptured ? $captured ?? new Money($amount, 'EUR') : null,
        );
    }

    #[\Override]
    public function provider(): PaymentProvider
    {
        return new PaymentProvider('fake');
    }

    #[\Override]
    public function capabilities(): CapabilitySet
    {
        return CapabilitySet::of();
    }

    #[\Override]
    public function createPayment(CreatePaymentRequest $request): PaymentAttempt
    {
        throw new \LogicException('Not scripted');
    }

    #[\Override]
    public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
    {
        throw new \LogicException('Not scripted');
    }

    #[\Override]
    public function capturePayment(CapturePaymentRequest $request): PaymentAttempt
    {
        return $this->answer('capture', $request);
    }

    #[\Override]
    public function confirmPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        return $this->answer('confirm', $request);
    }

    #[\Override]
    public function cancelPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        return $this->answer('cancel', $request);
    }

    private function answer(string $operation, CapturePaymentRequest|PaymentOperationRequest $request): PaymentAttempt
    {
        $this->calls[$operation] = ($this->calls[$operation] ?? 0) + 1;

        return ($this->script)($operation, $this->calls[$operation], $request);
    }
}
