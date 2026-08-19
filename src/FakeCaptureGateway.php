<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\CaptureGatewayInterface;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\PaymentsTesting\Internal\AbstractFakeGateway;

/**
 * @api
 */
final class FakeCaptureGateway extends AbstractFakeGateway implements CaptureGatewayInterface
{
    public function __construct(?FakeGatewayConfig $config = null)
    {
        parent::__construct(config: $config, supportsRefunds: false);
    }

    #[\Override]
    public function capturePayment(CapturePaymentRequest $request): PaymentAttempt
    {
        return $this->core->capturePayment(request: $request);
    }
}
