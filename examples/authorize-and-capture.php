<?php

declare(strict_types=1);

use Rasuvaeff\Payments\CaptureMethod;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentException;
use Rasuvaeff\Payments\PaymentMethodReference;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\PaymentsTesting\FakeCancelGateway;
use Rasuvaeff\PaymentsTesting\FakeCaptureGateway;
use Rasuvaeff\PaymentsTesting\FakeRefundGateway;

require dirname(__DIR__) . '/vendor/autoload.php';

// No idempotency key is given: the request generates one, and resending the
// same object is a replay, not a second payment.
$request = new CreatePaymentRequest(
    operationId: new OperationId(value: 'order-7-authorize'),
    amount: new Money(minorUnits: 1_200, currency: 'EUR'),
    paymentMethod: new PaymentMethodReference(id: 'pm_test', kind: 'card'),
    captureMethod: CaptureMethod::Manual,
);

$capture = new FakeCaptureGateway();
$authorized = $capture->createPayment($request);
$replayed = $capture->createPayment($request);
echo 'key ' . $request->idempotencyKey . ': replay returns ' . ($replayed->payment->id === $authorized->payment->id ? 'the same payment' : 'another payment') . PHP_EOL;
echo 'created: ' . $authorized->state->value . ', captured ' . $authorized->capturedAmount?->minorUnits . PHP_EOL;

// A partial capture keeps `amount` at the authorization and reports what was taken.
$partial = $capture->capturePayment(new CapturePaymentRequest(
    operationId: new OperationId(value: 'order-7-capture'),
    payment: $authorized->payment,
    amount: new Money(minorUnits: 700, currency: 'EUR'),
));
echo 'captured: ' . $partial->state->value . ', amount ' . $partial->amount->minorUnits . ', captured ' . $partial->capturedAmount?->minorUnits . PHP_EOL;

// A second capture is refused, as at the provider.
try {
    $capture->capturePayment(new CapturePaymentRequest(operationId: new OperationId(value: 'order-7-capture-2'), payment: $authorized->payment));
} catch (PaymentException $refused) {
    echo 'second capture refused: ' . $refused->getMessage() . PHP_EOL;
}

// What happens without an API call — here the customer pays a deferred
// payment — is simulated; only what was captured can be refunded.
$refunds = new FakeRefundGateway();
$deferred = $refunds->createPayment(new CreatePaymentRequest(
    operationId: new OperationId(value: 'order-8-create'),
    amount: new Money(minorUnits: 900, currency: 'EUR'),
));
echo 'deferred: ' . $deferred->state->value . PHP_EOL;
$paid = $refunds->simulateTransition($deferred->payment, PaymentState::Succeeded);
$refund = $refunds->createRefund(new CreateRefundRequest(
    operationId: new OperationId(value: 'order-8-refund'),
    payment: $paid->payment,
    idempotencyKey: 'order-8-refund-1',
));
echo 'refunded ' . $refund->requestedAmount->minorUnits . ' of ' . $paid->capturedAmount?->minorUnits . PHP_EOL;

// A paid payment cannot be canceled.
$cancel = new FakeCancelGateway();
$done = $cancel->createPayment(new CreatePaymentRequest(
    operationId: new OperationId(value: 'order-9-create'),
    amount: new Money(minorUnits: 300, currency: 'EUR'),
    paymentMethod: new PaymentMethodReference(id: 'pm_test'),
));

try {
    $cancel->cancelPayment(new PaymentOperationRequest(operationId: new OperationId(value: 'order-9-cancel'), payment: $done->payment));
} catch (PaymentException $refused) {
    echo 'cancel refused: ' . $refused->getMessage() . PHP_EOL;
}
