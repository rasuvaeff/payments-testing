<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Internal;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\PartialRefundCapability;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentProvider;
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
}
