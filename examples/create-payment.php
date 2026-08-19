<?php

declare(strict_types=1);

use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentMethodReference;
use Rasuvaeff\PaymentsTesting\FakePaymentGateway;

require dirname(__DIR__) . '/vendor/autoload.php';

$gateway = new FakePaymentGateway();
$attempt = $gateway->createPayment(new CreatePaymentRequest(
    operationId: new OperationId(value: 'checkout-1'),
    amount: new Money(minorUnits: 1_200, currency: 'EUR'),
    paymentMethod: new PaymentMethodReference(id: 'pm_test', kind: 'card'),
    idempotencyKey: 'checkout-1-attempt-1',
));

echo $attempt->payment->id . ' ' . $attempt->state->value . PHP_EOL;
