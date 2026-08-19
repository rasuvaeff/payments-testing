<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentMethodReference;

final class Fixtures
{
    public static function createPayment(string $operation = 'operation-1', ?string $idempotencyKey = 'key-1'): CreatePaymentRequest
    {
        return new CreatePaymentRequest(
            operationId: new OperationId(value: $operation),
            amount: new Money(minorUnits: 1_200, currency: 'EUR'),
            paymentMethod: new PaymentMethodReference(id: 'pm_1', kind: 'card'),
            idempotencyKey: $idempotencyKey,
        );
    }

    private function __construct() {}
}
