<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\RefundAttempt;
use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\PaymentsTesting\ContractViolationException;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;
use Rasuvaeff\PaymentsTesting\Internal\AttemptAssertions;
use Rasuvaeff\PaymentsTesting\Internal\FakeGatewayCore;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(AttemptAssertions::class)]
final class AttemptAssertionsTest
{
    #[ExpectNoAssertions]
    public function acceptsAPaymentAttemptThatMatchesEveryExpectation(): void
    {
        $attempt = $this->paymentAttempt();

        AttemptAssertions::payment(
            attempt: $attempt,
            provider: $attempt->provider,
            operationId: new OperationId(value: 'operation-1'),
            payment: $attempt->payment,
            amount: $attempt->amount,
        );
    }

    #[ExpectNoAssertions]
    public function skipsOptionalPaymentExpectationsWhenOmitted(): void
    {
        $attempt = $this->paymentAttempt();

        AttemptAssertions::payment(
            attempt: $attempt,
            provider: $attempt->provider,
            operationId: new OperationId(value: 'operation-1'),
        );
    }

    #[DataProvider('paymentViolationProvider')]
    public function reportsEachPaymentViolation(string $message, \Closure $call): void
    {
        Expect::exception(ContractViolationException::class)->withMessage($message);
        $call($this->paymentAttempt());
    }

    public static function paymentViolationProvider(): iterable
    {
        $operationId = new OperationId(value: 'operation-1');

        yield 'provider' => [
            'Payment attempt provider differs from gateway provider',
            static fn(PaymentAttempt $a) => AttemptAssertions::payment($a, new PaymentProvider(value: 'other'), $operationId),
        ];
        yield 'operation id' => [
            'Payment attempt did not preserve operation id',
            static fn(PaymentAttempt $a) => AttemptAssertions::payment($a, $a->provider, new OperationId(value: 'other')),
        ];
        yield 'payment reference' => [
            'Payment attempt did not preserve payment reference',
            static fn(PaymentAttempt $a) => AttemptAssertions::payment(
                $a,
                $a->provider,
                $operationId,
                payment: new PaymentReference(provider: $a->provider, id: 'other'),
            ),
        ];
        yield 'amount' => [
            'Payment attempt did not preserve requested amount',
            static fn(PaymentAttempt $a) => AttemptAssertions::payment(
                $a,
                $a->provider,
                $operationId,
                amount: new Money(minorUnits: 1, currency: 'EUR'),
            ),
        ];
    }

    #[ExpectNoAssertions]
    public function acceptsARefundAttemptThatMatchesEveryExpectation(): void
    {
        $attempt = $this->refundAttempt();

        AttemptAssertions::refund(
            attempt: $attempt,
            provider: $attempt->provider,
            operationId: new OperationId(value: 'refund-1'),
            payment: $attempt->payment,
            refund: $attempt->refund,
            amount: $attempt->requestedAmount,
        );
    }

    #[ExpectNoAssertions]
    public function skipsOptionalRefundExpectationsWhenOmitted(): void
    {
        $attempt = $this->refundAttempt();

        AttemptAssertions::refund(
            attempt: $attempt,
            provider: $attempt->provider,
            operationId: new OperationId(value: 'refund-1'),
        );
    }

    #[DataProvider('refundViolationProvider')]
    public function reportsEachRefundViolation(string $message, \Closure $call): void
    {
        Expect::exception(ContractViolationException::class)->withMessage($message);
        $call($this->refundAttempt());
    }

    public static function refundViolationProvider(): iterable
    {
        $operationId = new OperationId(value: 'refund-1');

        yield 'provider' => [
            'Refund attempt provider differs from gateway provider',
            static fn(RefundAttempt $a) => AttemptAssertions::refund($a, new PaymentProvider(value: 'other'), $operationId),
        ];
        yield 'operation id' => [
            'Refund attempt did not preserve operation id',
            static fn(RefundAttempt $a) => AttemptAssertions::refund($a, $a->provider, new OperationId(value: 'other')),
        ];
        yield 'payment reference' => [
            'Refund attempt did not preserve payment reference',
            static fn(RefundAttempt $a) => AttemptAssertions::refund(
                $a,
                $a->provider,
                $operationId,
                payment: new PaymentReference(provider: $a->provider, id: 'other'),
            ),
        ];
        yield 'refund reference' => [
            'Refund attempt did not preserve refund reference',
            static fn(RefundAttempt $a) => AttemptAssertions::refund(
                $a,
                $a->provider,
                $operationId,
                refund: new RefundReference(provider: $a->provider, id: 'other'),
            ),
        ];
        yield 'amount' => [
            'Refund attempt did not preserve requested amount',
            static fn(RefundAttempt $a) => AttemptAssertions::refund(
                $a,
                $a->provider,
                $operationId,
                amount: new Money(minorUnits: 1, currency: 'EUR'),
            ),
        ];
    }

    public function comparesByValueNotByIdentity(): void
    {
        $attempt = $this->paymentAttempt();

        AttemptAssertions::same(
            actual: $attempt->amount,
            expected: new Money(minorUnits: 1_200, currency: 'EUR'),
            message: 'unused',
        );
        Expect::exception(ContractViolationException::class)->withMessage('differs');
        AttemptAssertions::same(
            actual: $attempt->amount,
            expected: new Money(minorUnits: 1_201, currency: 'EUR'),
            message: 'differs',
        );
    }

    private function paymentAttempt(): PaymentAttempt
    {
        return (new FakeGatewayCore(new FakeGatewayConfig()))->createPayment(Fixtures::createPayment());
    }

    private function refundAttempt(): RefundAttempt
    {
        $core = new FakeGatewayCore(new FakeGatewayConfig());
        $payment = $core->createPayment(Fixtures::createPayment())->payment;

        return $core->createRefund(new CreateRefundRequest(
            operationId: new OperationId(value: 'refund-1'),
            payment: $payment,
        ));
    }
}
