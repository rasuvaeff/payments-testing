<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\ConfirmGatewayInterface;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\PaymentsTesting\Internal\AbstractFakeGateway;

/**
 * @api
 */
final class FakeConfirmGateway extends AbstractFakeGateway implements ConfirmGatewayInterface
{
    public function __construct(?FakeGatewayConfig $config = null)
    {
        parent::__construct(config: $config, supportsRefunds: false);
    }

    #[\Override]
    public function confirmPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        return $this->core->confirmPayment(request: $request);
    }
}
