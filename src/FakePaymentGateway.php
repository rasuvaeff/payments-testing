<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\PaymentsTesting\Internal\AbstractFakeGateway;

/**
 * Base fake implementing only PaymentGatewayInterface.
 *
 * @api
 */
final class FakePaymentGateway extends AbstractFakeGateway
{
    public function __construct(?FakeGatewayConfig $config = null)
    {
        parent::__construct(config: $config, supportsRefunds: false);
    }
}
