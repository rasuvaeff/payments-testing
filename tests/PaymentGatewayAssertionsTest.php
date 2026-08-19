<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PartialRefundCapability;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentMethodReference;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\PaymentsTesting\ContractViolationException;
use Rasuvaeff\PaymentsTesting\PaymentGatewayAssertions;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PaymentGatewayAssertions::class)]
#[Covers(ContractViolationException::class)]
final class PaymentGatewayAssertionsTest
{
    public function acceptsValidCreateRetrieveAndIdempotency(): void
    {
        $gateway = new FixtureGateway(CapabilitySet::of(new PartialRefundCapability()));
        $request = $this->createRequest('operation-1', 'key-1');
        $attempt = PaymentGatewayAssertions::assertCreatePaymentIdempotency($gateway, $request);

        Assert::same($attempt->payment->id, 'pay_key-1');
        PaymentGatewayAssertions::assertCapabilities($gateway);
        PaymentGatewayAssertions::assertRetrievePayment(
            $gateway,
            new RetrievePaymentRequest(new OperationId('retrieve-1'), $attempt->payment),
        );
    }

    public function detectsRetrievePaymentOperationIdViolation(): void
    {
        $gateway = new BrokenFixtureGateway();

        Expect::exception(ContractViolationException::class)
            ->withMessage('Payment attempt did not preserve operation id');
        PaymentGatewayAssertions::assertRetrievePayment(
            $gateway,
            new RetrievePaymentRequest(
                new OperationId('retrieve-1'),
                new PaymentReference($gateway->provider(), 'pay_1'),
            ),
        );
    }

    public function detectsCreatePaymentIdempotencyViolation(): void
    {
        $gateway = new BrokenFixtureGateway(breaksIdempotency: true);

        Expect::exception(ContractViolationException::class)
            ->withMessage('Payment idempotency key produced another reference');
        PaymentGatewayAssertions::assertCreatePaymentIdempotency(
            $gateway,
            $this->createRequest('operation-1', 'key-1'),
        );
    }

    public function rejectsCapabilityWithoutOptionalInterface(): void
    {
        $inner = new FixtureGateway();
        $gateway = new readonly class ($inner) implements PaymentGatewayInterface {
            public function __construct(private FixtureGateway $inner) {}

            #[\Override]
            public function provider(): PaymentProvider
            {
                return $this->inner->provider();
            }

            #[\Override]
            public function capabilities(): CapabilitySet
            {
                return CapabilitySet::of(new PartialRefundCapability());
            }

            #[\Override]
            public function createPayment(CreatePaymentRequest $request): PaymentAttempt
            {
                return $this->inner->createPayment($request);
            }

            #[\Override]
            public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
            {
                return $this->inner->retrievePayment($request);
            }
        };

        Expect::exception(ContractViolationException::class)->withMessageContaining('RefundGatewayInterface');
        PaymentGatewayAssertions::assertCapabilities($gateway);
    }

    public function rejectsGatewayProviderMismatch(): void
    {
        $inner = new FixtureGateway();
        $gateway = new readonly class ($inner) implements PaymentGatewayInterface {
            public function __construct(private FixtureGateway $inner) {}

            #[\Override]
            public function provider(): PaymentProvider
            {
                return new PaymentProvider('other');
            }

            #[\Override]
            public function capabilities(): CapabilitySet
            {
                return CapabilitySet::of();
            }

            #[\Override]
            public function createPayment(CreatePaymentRequest $request): PaymentAttempt
            {
                return $this->inner->createPayment($request);
            }

            #[\Override]
            public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
            {
                return $this->inner->retrievePayment($request);
            }
        };

        Expect::exception(ContractViolationException::class)->withMessageContaining('provider');
        PaymentGatewayAssertions::assertCreatePayment($gateway, $this->createRequest('operation-2'));
    }

    /**
     * Replaying one key agrees with a gateway that ignores the key completely,
     * so the assertion also demands that a different key start a different
     * payment. Without that check this double passes.
     */
    public function detectsAGatewayThatIgnoresTheIdempotencyKey(): void
    {
        Expect::exception(ContractViolationException::class)
            ->withMessage('A different idempotency key returned the original payment reference');
        PaymentGatewayAssertions::assertCreatePaymentIdempotency(
            new ConstantReferenceGateway(),
            $this->createRequest('operation-ignored', 'key-ignored'),
        );
    }

    /**
     * Answering a changed request from the original would charge one amount
     * and report another, so silently accepting key reuse is a violation too.
     */
    public function detectsAGatewayThatAcceptsKeyReuseWithADifferentRequest(): void
    {
        Expect::exception(ContractViolationException::class)
            ->withMessage('Reusing an idempotency key with a different request was accepted');
        PaymentGatewayAssertions::assertCreatePaymentIdempotency(
            new ConstantReferenceGateway(acceptsKeyReuse: true),
            $this->createRequest('operation-reuse', 'key-reuse'),
        );
    }

    /**
     * Pins the probes the assertion issues: replay the same key twice, then a
     * distinct key, then the original key with a changed request. The exact
     * derived values matter — a probe that reused the original key, or an
     * amount equal to the original, would silently stop testing anything.
     */
    public function probesIdempotencyWithADistinctKeyAndAChangedRequest(): void
    {
        $gateway = new FixtureGateway();

        try {
            PaymentGatewayAssertions::assertCreatePaymentIdempotency(
                $gateway,
                $this->createRequest('operation-probe', 'key-probe'),
            );
        } catch (\InvalidArgumentException) {
            // The double refuses the reuse probe, which is the expected contract.
        }

        Assert::same($gateway->received, [
            ['key-probe', 100],
            ['key-probe', 100],
            ['key-probe-contract-other', 100],
            ['key-probe', 101],
        ]);
    }

    /**
     * A request at `Money`'s ceiling is legal input. The reuse probe must stay
     * inside the range instead of overflowing, which would report a validation
     * error against a gateway that did nothing wrong.
     */
    public function probesTheCeilingAmountBySteppingDown(): void
    {
        $gateway = new FixtureGateway();
        $request = new CreatePaymentRequest(
            operationId: new OperationId('operation-ceiling'),
            amount: new Money(PHP_INT_MAX, 'EUR'),
            paymentMethod: new PaymentMethodReference('pm_1'),
            idempotencyKey: 'key-ceiling',
        );

        try {
            PaymentGatewayAssertions::assertCreatePaymentIdempotency($gateway, $request);
        } catch (\InvalidArgumentException) {
            // The double refuses the reuse probe, which is the expected contract.
        }

        Assert::same($gateway->received, [
            ['key-ceiling', PHP_INT_MAX],
            ['key-ceiling', PHP_INT_MAX],
            ['key-ceiling-contract-other', PHP_INT_MAX],
            ['key-ceiling', PHP_INT_MAX - 1],
        ]);
    }

    public function idempotencyAssertionRequiresKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        PaymentGatewayAssertions::assertCreatePaymentIdempotency(new FixtureGateway(), $this->createRequest('operation-3'));
    }

    private function createRequest(string $operationId, ?string $key = null): CreatePaymentRequest
    {
        return new CreatePaymentRequest(
            operationId: new OperationId($operationId),
            amount: new Money(100, 'EUR'),
            paymentMethod: new PaymentMethodReference('pm_1'),
            idempotencyKey: $key,
        );
    }
}
