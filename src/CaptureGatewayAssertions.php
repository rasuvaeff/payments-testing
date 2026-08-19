<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\CaptureGatewayInterface;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\PaymentsTesting\Internal\AttemptAssertions;

/**
 * @api
 */
final class CaptureGatewayAssertions
{
    public static function assertCapturePayment(
        PaymentGatewayInterface&CaptureGatewayInterface $gateway,
        CapturePaymentRequest $request,
    ): PaymentAttempt {
        $attempt = $gateway->capturePayment($request);
        AttemptAssertions::payment(
            attempt: $attempt,
            provider: $gateway->provider(),
            operationId: $request->operationId,
            amount: $request->amount,
        );

        return $attempt;
    }

    private function __construct() {}
}
