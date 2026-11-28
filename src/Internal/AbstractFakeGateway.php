<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Internal;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PartialRefundCapability;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;

/**
 * @internal
 */
abstract class AbstractFakeGateway implements PaymentGatewayInterface
{
    protected readonly FakeGatewayCore $core;

    protected function __construct(?FakeGatewayConfig $config, bool $supportsRefunds)
    {
        $resolved = $config ?? new FakeGatewayConfig();

        if (!$supportsRefunds && $resolved->capabilities->has(PartialRefundCapability::class)) {
            throw new \InvalidArgumentException('Partial refund capability requires FakeRefundGateway');
        }

        $this->core = new FakeGatewayCore(config: $resolved);
    }

    #[\Override]
    final public function provider(): PaymentProvider
    {
        return $this->core->config->provider;
    }

    #[\Override]
    final public function capabilities(): CapabilitySet
    {
        return $this->core->config->capabilities;
    }

    #[\Override]
    final public function createPayment(CreatePaymentRequest $request): PaymentAttempt
    {
        return $this->core->createPayment(request: $request);
    }

    #[\Override]
    final public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
    {
        return $this->core->retrievePayment(request: $request);
    }

    /**
     * Moves a payment the way the provider moves it without an API call of
     * the application — the customer completes 3-D Secure or pays a deferred
     * payment, a transfer settles, an authorization expires — so a test can
     * reach the state a webhook would report. Only a move
     * `PaymentState::canTransitionTo()` allows is accepted
     * (`\InvalidArgumentException` otherwise); reaching `Succeeded` with
     * nothing captured captures the whole amount. A manual-capture payment
     * reaches `Succeeded` that way only from `RequiresCapture` or
     * `Processing`; from an earlier state the customer's completion moves it
     * to `RequiresCapture`, so simulate that and capture it.
     */
    final public function simulateTransition(
        PaymentReference $payment,
        PaymentState $state,
        ?OperationId $operationId = null,
    ): PaymentAttempt {
        return $this->core->transition(
            payment: $payment,
            state: $state,
            operationId: $operationId ?? new OperationId(value: 'fake-transition'),
        );
    }
}
