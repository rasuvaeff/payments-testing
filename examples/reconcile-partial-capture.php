<?php

declare(strict_types=1);

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CaptureMethod;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PartialRefundCapability;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentMethodReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\PaymentsTesting\FakeCaptureGateway;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;
use Rasuvaeff\PaymentsTesting\FakeRefundGateway;

require dirname(__DIR__) . '/vendor/autoload.php';

// The cookbook's reconciliation recipe: compare what was collected
// (`capturedAmount`, gross) with the order, never the authorized `amount`,
// and never mark an order paid when the adapter could not say.
$reconcile = static function (PaymentAttempt $attempt, Money $orderTotal): string {
    $collected = $attempt->capturedAmount;

    if ($collected === null) {
        return 'review: the provider response did not say what was captured';
    }

    if ($attempt->state === PaymentState::Succeeded && $collected->equals($orderTotal)) {
        return 'paid';
    }

    return sprintf('not paid: collected %d of %d', $collected->minorUnits, $orderTotal->minorUnits);
};

$orderTotal = new Money(minorUnits: 1_200, currency: 'EUR');
$authorize = static fn(string $order): CreatePaymentRequest => new CreatePaymentRequest(
    operationId: new OperationId(value: $order . '-authorize'),
    amount: $orderTotal,
    paymentMethod: new PaymentMethodReference(id: 'pm_test', kind: 'card'),
    captureMethod: CaptureMethod::Manual,
);

// A partial capture: the payment is Succeeded and its amount is still the
// whole authorization, so comparing `amount` would mark the order paid.
$gateway = new FakeCaptureGateway();
$partial = $gateway->createPayment($authorize('order-1'));
$gateway->capturePayment(new CapturePaymentRequest(
    operationId: new OperationId(value: 'order-1-capture'),
    payment: $partial->payment,
    amount: new Money(minorUnits: 700, currency: 'EUR'),
));
$attempt = $gateway->retrievePayment(new RetrievePaymentRequest(operationId: new OperationId(value: 'order-1-reconcile'), payment: $partial->payment));
echo sprintf('order-1: %s, amount %d, captured %d → %s', $attempt->state->value, $attempt->amount->minorUnits, $attempt->capturedAmount?->minorUnits, $reconcile($attempt, $orderTotal)) . PHP_EOL;

// A full capture collects the order total.
$full = $gateway->createPayment($authorize('order-2'));
$gateway->capturePayment(new CapturePaymentRequest(operationId: new OperationId(value: 'order-2-capture'), payment: $full->payment));
$attempt = $gateway->retrievePayment(new RetrievePaymentRequest(operationId: new OperationId(value: 'order-2-reconcile'), payment: $full->payment));
echo 'order-2: ' . $reconcile($attempt, $orderTotal) . PHP_EOL;

// `capturedAmount` is gross: a later refund does not lower it. Refunds
// reconcile on their own, through RefundAttempt.
$refunds = new FakeRefundGateway(new FakeGatewayConfig(capabilities: CapabilitySet::of(new PartialRefundCapability())));
$paid = $refunds->createPayment(new CreatePaymentRequest(
    operationId: new OperationId(value: 'order-3-create'),
    amount: $orderTotal,
    paymentMethod: new PaymentMethodReference(id: 'pm_test', kind: 'card'),
));
$refund = $refunds->createRefund(new CreateRefundRequest(
    operationId: new OperationId(value: 'order-3-refund'),
    payment: $paid->payment,
    amount: new Money(minorUnits: 300, currency: 'EUR'),
));
$attempt = $refunds->retrievePayment(new RetrievePaymentRequest(operationId: new OperationId(value: 'order-3-reconcile'), payment: $paid->payment));
echo sprintf('order-3: refunded %d, captured still %d', $refund->requestedAmount->minorUnits, $attempt->capturedAmount?->minorUnits) . PHP_EOL;
