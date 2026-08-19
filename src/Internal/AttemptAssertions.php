<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Internal;

use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\RefundAttempt;
use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\PaymentsTesting\ContractViolationException;

/**
 * @psalm-type ContractValue = Money|OperationId|PaymentProvider|PaymentReference|RefundReference
 *
 * @internal
 */
final class AttemptAssertions
{
    public static function payment(
        PaymentAttempt $attempt,
        PaymentProvider $provider,
        OperationId $operationId,
        ?PaymentReference $payment = null,
        ?Money $amount = null,
    ): void {
        self::same($attempt->provider, $provider, 'Payment attempt provider differs from gateway provider');
        self::same($attempt->payment->provider, $provider, 'Payment reference provider differs from gateway provider');
        self::same($attempt->operationId, $operationId, 'Payment attempt did not preserve operation id');

        if ($payment instanceof PaymentReference) {
            self::same($attempt->payment, $payment, 'Payment attempt did not preserve payment reference');
        }

        if ($amount instanceof Money) {
            self::same($attempt->amount, $amount, 'Payment attempt did not preserve requested amount');
        }
    }

    public static function refund(
        RefundAttempt $attempt,
        PaymentProvider $provider,
        OperationId $operationId,
        ?PaymentReference $payment = null,
        ?RefundReference $refund = null,
        ?Money $amount = null,
    ): void {
        self::same($attempt->provider, $provider, 'Refund attempt provider differs from gateway provider');
        self::same($attempt->payment->provider, $provider, 'Refund payment provider differs from gateway provider');
        self::same($attempt->refund->provider, $provider, 'Refund reference provider differs from gateway provider');
        self::same($attempt->operationId, $operationId, 'Refund attempt did not preserve operation id');

        if ($payment instanceof PaymentReference) {
            self::same($attempt->payment, $payment, 'Refund attempt did not preserve payment reference');
        }

        if ($refund instanceof RefundReference) {
            self::same($attempt->refund, $refund, 'Refund attempt did not preserve refund reference');
        }

        if ($amount instanceof Money) {
            self::same($attempt->requestedAmount, $amount, 'Refund attempt did not preserve requested amount');
        }
    }

    /**
     * @param ContractValue $actual
     * @param ContractValue $expected
     */
    public static function same(
        object $actual,
        object $expected,
        string $message,
    ): void {
        if ($actual != $expected) {
            throw new ContractViolationException($message);
        }
    }

    private function __construct() {}
}
