<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\RefundAttempt;
use Rasuvaeff\Payments\RefundGatewayInterface;
use Rasuvaeff\Payments\RetrieveRefundRequest;
use Rasuvaeff\PaymentsTesting\Internal\AbstractFakeGateway;

/**
 * @api
 */
final class FakeRefundGateway extends AbstractFakeGateway implements RefundGatewayInterface
{
    public function __construct(?FakeGatewayConfig $config = null)
    {
        parent::__construct(config: $config, supportsRefunds: true);
    }

    #[\Override]
    public function createRefund(CreateRefundRequest $request): RefundAttempt
    {
        return $this->core->createRefund(request: $request);
    }

    #[\Override]
    public function retrieveRefund(RetrieveRefundRequest $request): RefundAttempt
    {
        return $this->core->retrieveRefund(request: $request);
    }
}
