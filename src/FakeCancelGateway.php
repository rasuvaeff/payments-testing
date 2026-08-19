<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\CancelGatewayInterface;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\PaymentsTesting\Internal\AbstractFakeGateway;

/**
 * @api
 */
final class FakeCancelGateway extends AbstractFakeGateway implements CancelGatewayInterface
{
    public function __construct(?FakeGatewayConfig $config = null)
    {
        parent::__construct(config: $config, supportsRefunds: false);
    }

    #[\Override]
    public function cancelPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        return $this->core->cancelPayment(request: $request);
    }
}
