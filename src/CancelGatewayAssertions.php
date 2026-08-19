<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting;

use Rasuvaeff\Payments\CancelGatewayInterface;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\PaymentsTesting\Internal\AttemptAssertions;

/**
 * @api
 */
final class CancelGatewayAssertions
{
    public static function assertCancelPayment(
        PaymentGatewayInterface&CancelGatewayInterface $gateway,
        PaymentOperationRequest $request,
    ): PaymentAttempt {
        $attempt = $gateway->cancelPayment($request);
        AttemptAssertions::payment(
            attempt: $attempt,
            provider: $gateway->provider(),
            operationId: $request->operationId,
            payment: $request->payment,
        );

        return $attempt;
    }

    private function __construct() {}
}
