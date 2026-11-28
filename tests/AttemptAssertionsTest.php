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
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
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

    /**
     * Loose `!=` compares numeric strings as numbers, so it called
     * `OperationId('100')` equal to `OperationId('1e2')`.
     */
    #[DataProvider('numericLookalikeProvider')]
    public function comparesScalarFieldsStrictly(object $actual, object $expected): void
    {
        Expect::exception(ContractViolationException::class)->withMessage('differs');
        AttemptAssertions::same(actual: $actual, expected: $expected, message: 'differs');
    }

    /** @return iterable<string, array{object, object}> */
    public static function numericLookalikeProvider(): iterable
    {
        $provider = new PaymentProvider(value: 'fake');

        yield 'operation id in exponent form' => [new OperationId(value: '100'), new OperationId(value: '1e2')];
        yield 'payment id with a leading zero' => [
            new PaymentReference(provider: $provider, id: '10'),
            new PaymentReference(provider: $provider, id: '010'),
        ];
        yield 'payment kind with a leading zero' => [
            new PaymentReference(provider: $provider, id: 'p', kind: '1'),
            new PaymentReference(provider: $provider, id: 'p', kind: '01'),
        ];
        yield 'refund id in exponent form' => [
            new RefundReference(provider: $provider, id: '100'),
            new RefundReference(provider: $provider, id: '1e2'),
        ];
        yield 'refund kind' => [
            new RefundReference(provider: $provider, id: 'r', kind: 'refund'),
            new RefundReference(provider: $provider, id: 'r'),
        ];
        yield 'payment provider' => [new PaymentProvider(value: 'fake'), new PaymentProvider(value: 'other')];
        yield 'reference provider' => [
            new PaymentReference(provider: $provider, id: 'p'),
            new PaymentReference(provider: new PaymentProvider(value: 'other'), id: 'p'),
        ];
        yield 'refund reference provider' => [
            new RefundReference(provider: $provider, id: 'r'),
            new RefundReference(provider: new PaymentProvider(value: 'other'), id: 'r'),
        ];
        yield 'payment and refund reference with the same fields' => [
            new PaymentReference(provider: $provider, id: 'x', kind: 'k'),
            new RefundReference(provider: $provider, id: 'x', kind: 'k'),
        ];
        yield 'money currency' => [new Money(minorUnits: 1, currency: 'EUR'), new Money(minorUnits: 1, currency: 'USD')];
        yield 'different value types' => [new OperationId(value: 'fake'), new PaymentProvider(value: 'fake')];
    }

    #[DataProvider('equalValueProvider')]
    #[ExpectNoAssertions]
    public function acceptsEqualValuesBuiltSeparately(object $actual, object $expected): void
    {
        AttemptAssertions::same(actual: $actual, expected: $expected, message: 'differs');
    }

    /** @return iterable<string, array{object, object}> */
    public static function equalValueProvider(): iterable
    {
        $provider = static fn(): PaymentProvider => new PaymentProvider(value: 'fake');

        yield 'operation id' => [new OperationId(value: '100'), new OperationId(value: '100')];
        yield 'provider' => [$provider(), $provider()];
        yield 'payment reference' => [
            new PaymentReference(provider: $provider(), id: 'p', kind: 'payment'),
            new PaymentReference(provider: $provider(), id: 'p', kind: 'payment'),
        ];
        yield 'refund reference' => [
            new RefundReference(provider: $provider(), id: 'r', kind: 'refund'),
            new RefundReference(provider: $provider(), id: 'r', kind: 'refund'),
        ];
        yield 'money' => [new Money(minorUnits: 5, currency: 'EUR'), new Money(minorUnits: 5, currency: 'EUR')];
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

    /**
     * Two values are the same exactly when every field is identical as a
     * string or int. Numeric lookalikes (`100`/`1e2`/`0100`, `10`/`010`)
     * are drawn often, because loose comparison called them equal.
     */
    #[Property(runs: 300)]
    public function treatsValuesAsTheSameExactlyWhenEveryFieldIsIdentical(string $left, string $right, int $units, int $otherUnits): void
    {
        Classify::cover($left === $right, 'identical strings', 5);
        // Same number, other spelling: what loose `==` called equal.
        Classify::cover($left !== $right && (float) $left === (float) $right, 'numeric lookalikes', 10);

        $pairs = [
            [new OperationId($left), new OperationId($right), $left === $right],
            [new PaymentReference(new PaymentProvider('fake'), $left), new PaymentReference(new PaymentProvider('fake'), $right), $left === $right],
            [new Money($units, 'EUR'), new Money($otherUnits, 'EUR'), $units === $otherUnits],
        ];

        foreach ($pairs as [$actual, $expected, $same]) {
            try {
                AttemptAssertions::same($actual, $expected, 'differs');
                Assert::true($same);
            } catch (ContractViolationException) {
                Assert::false($same);
            }
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function treatsValuesAsTheSameExactlyWhenEveryFieldIsIdenticalGenerators(): array
    {
        $numeric = Gen::elements(['100', '1e2', '0100', '100.0', '10', '010', '1']);

        return [
            'left' => $numeric,
            'right' => $numeric,
            'units' => Gen::intBetween(0, 3),
            'otherUnits' => Gen::intBetween(0, 3),
        ];
    }
}
