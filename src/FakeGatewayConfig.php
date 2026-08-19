<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentState;

/**
 * @api
 */
final readonly class FakeGatewayConfig
{
    public PaymentProvider $provider;
    public CapabilitySet $capabilities;
    public \DateTimeImmutable $now;

    public function __construct(
        ?PaymentProvider $provider = null,
        ?CapabilitySet $capabilities = null,
        ?\DateTimeImmutable $now = null,
        public PaymentState $createState = PaymentState::Succeeded,
    ) {
        $this->provider = $provider ?? new PaymentProvider(value: 'fake');
        $this->capabilities = $capabilities ?? CapabilitySet::of();
        $this->now = $now ?? new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
    }
}
