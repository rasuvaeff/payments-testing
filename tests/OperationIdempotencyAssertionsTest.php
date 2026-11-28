<?php

declare(strict_types=1);

namespace Rasuvaeff\PaymentsTesting\Tests;

use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\TransportException;
use Rasuvaeff\PaymentsTesting\CancelGatewayAssertions;
use Rasuvaeff\PaymentsTesting\CaptureGatewayAssertions;
use Rasuvaeff\PaymentsTesting\ConfirmGatewayAssertions;
use Rasuvaeff\PaymentsTesting\ContractViolationException;
use Rasuvaeff\PaymentsTesting\FakeCancelGateway;
use Rasuvaeff\PaymentsTesting\FakeCaptureGateway;
use Rasuvaeff\PaymentsTesting\FakeConfirmGateway;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;
use Rasuvaeff\PaymentsTesting\Internal\KeyReuseProbe;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CaptureGatewayAssertions::class)]
#[Covers(ConfirmGatewayAssertions::class)]
#[Covers(CancelGatewayAssertions::class)]
#[Covers(KeyReuseProbe::class)]
final class OperationIdempotencyAssertionsTest
{
    public function theFakesSatisfyEveryOperationIdempotencyContract(): void
    {
        $capture = new FakeCaptureGateway(new FakeGatewayConfig(createState: PaymentState::RequiresCapture));
        $captured = CaptureGatewayAssertions::assertCapturePaymentIdempotency($capture, new CapturePaymentRequest(
            operationId: new OperationId('capture-1'),
            payment: $capture->createPayment(Fixtures::createPayment())->payment,
            amount: new Money(600, 'EUR'),
            idempotencyKey: 'capture-key',
        ));
        $confirm = new FakeConfirmGateway(new FakeGatewayConfig(createState: PaymentState::RequiresAction));
        $confirmed = ConfirmGatewayAssertions::assertConfirmPaymentIdempotency(
            $confirm,
            $this->operation($confirm->createPayment(Fixtures::createPayment())->payment, 'confirm-key'),
        );
        $cancel = new FakeCancelGateway(new FakeGatewayConfig(createState: PaymentState::RequiresAction));
        $canceled = CancelGatewayAssertions::assertCancelPaymentIdempotency(
            $cancel,
            $this->operation($cancel->createPayment(Fixtures::createPayment())->payment, 'cancel-key'),
        );

        Assert::same($captured->amount->minorUnits, 600);
        Assert::same($captured->state, PaymentState::Succeeded);
        Assert::same($confirmed->state, PaymentState::Processing);
        Assert::same($canceled->state, PaymentState::Canceled);
    }

    public function captureIdempotencyAcceptsAFullCaptureWithoutAnAmount(): void
    {
        $capture = new FakeCaptureGateway(new FakeGatewayConfig(createState: PaymentState::RequiresCapture));
        $captured = CaptureGatewayAssertions::assertCapturePaymentIdempotency($capture, new CapturePaymentRequest(
            operationId: new OperationId('capture-1'),
            payment: $capture->createPayment(Fixtures::createPayment())->payment,
            idempotencyKey: 'capture-key',
        ));

        Assert::same($captured->amount->minorUnits, 1_200);
    }

    /**
     * @param \Closure(string, int, CapturePaymentRequest|PaymentOperationRequest): PaymentAttempt $script
     * @param non-empty-string $message
     */
    #[DataProvider('brokenCaptureProvider')]
    public function detectsABrokenCaptureReplay(\Closure $script, string $message): void
    {
        Expect::exception(ContractViolationException::class)->withMessage($message);
        CaptureGatewayAssertions::assertCapturePaymentIdempotency(
            new ScriptedOperationGateway($script),
            // No amount: otherwise each call's own amount check fires first.
            $this->capture(null),
        );
    }

    /** @return iterable<string, array{\Closure, non-empty-string}> */
    public static function brokenCaptureProvider(): iterable
    {
        yield 'another reference' => [
            static fn(string $operation, int $call, CapturePaymentRequest|PaymentOperationRequest $request): PaymentAttempt => ScriptedOperationGateway::attempt($request->operationId, 'pay_' . $call, 50),
            'Capture idempotency key produced another payment reference',
        ];
        yield 'another amount' => [
            static fn(string $operation, int $call, CapturePaymentRequest|PaymentOperationRequest $request): PaymentAttempt => $call === 1
                ? ScriptedOperationGateway::attempt($request->operationId, 'pay_1', 50)
                : ScriptedOperationGateway::attempt($request->operationId, 'pay_1', 49),
            'A replayed capture reported another amount',
        ];
        yield 'another state' => [
            static fn(string $operation, int $call, CapturePaymentRequest|PaymentOperationRequest $request): PaymentAttempt => ScriptedOperationGateway::attempt(
                $request->operationId,
                'pay_1',
                50,
                $call === 1 ? PaymentState::Succeeded : PaymentState::Processing,
            ),
            'A replayed capture reported another state',
        ];
        yield 'key reuse accepted' => [
            static fn(string $operation, int $call, CapturePaymentRequest|PaymentOperationRequest $request): PaymentAttempt => ScriptedOperationGateway::attempt($request->operationId, 'pay_1', 50),
            'Reusing a capture idempotency key with a different request was accepted',
        ];
    }

    /**
     * The key-reuse probe asks for one unit less than the first capture, or
     * one more when that would reach zero.
     *
     * @param int<0, max> $captured
     * @param int<0, max> $probe
     */
    #[DataProvider('captureProbeProvider')]
    public function probesCaptureKeyReuseWithAChangedAmount(int $captured, int $probe): void
    {
        $amounts = [];
        $gateway = new ScriptedOperationGateway(static function (string $operation, int $call, CapturePaymentRequest|PaymentOperationRequest $request) use (&$amounts, $captured): PaymentAttempt {
            \assert($request instanceof CapturePaymentRequest);
            $amounts[] = $request->amount?->minorUnits;

            if ($call === 3) {
                throw new \InvalidArgumentException('key reused');
            }

            return ScriptedOperationGateway::attempt($request->operationId, 'pay_1', $captured);
        });

        CaptureGatewayAssertions::assertCapturePaymentIdempotency($gateway, $this->capture(null));

        Assert::same($amounts, [null, null, $probe]);
    }

    /** @return iterable<string, array{int, int}> */
    public static function captureProbeProvider(): iterable
    {
        yield 'steps down' => [50, 49];
        yield 'steps up from one' => [1, 2];
        yield 'steps up from zero' => [0, 1];
    }

    /**
     * A timeout or an outage says nothing about the key; counting it as a
     * refusal would let a flaky sandbox pass the assertion.
     */
    public function anInconclusiveAnswerToTheKeyReuseProbePropagates(): void
    {
        $gateway = new ScriptedOperationGateway(static function (string $operation, int $call, CapturePaymentRequest|PaymentOperationRequest $request): PaymentAttempt {
            if ($call === 3) {
                throw new TransportException('timeout');
            }

            return ScriptedOperationGateway::attempt($request->operationId, 'pay_1', 50);
        });

        Expect::exception(TransportException::class)->withMessage('timeout');
        CaptureGatewayAssertions::assertCapturePaymentIdempotency($gateway, $this->capture(new Money(50, 'EUR')));
    }

    public function detectsAReplayedConfirmInAnotherState(): void
    {
        Expect::exception(ContractViolationException::class)->withMessage('A replayed confirm reported another state');
        ConfirmGatewayAssertions::assertConfirmPaymentIdempotency(
            $this->stateFlipping(),
            $this->operation(new PaymentReference(new PaymentProvider('fake'), 'pay_1'), 'confirm-key'),
        );
    }

    public function detectsAReplayedCancelInAnotherState(): void
    {
        Expect::exception(ContractViolationException::class)->withMessage('A replayed cancel reported another state');
        CancelGatewayAssertions::assertCancelPaymentIdempotency(
            $this->stateFlipping(),
            $this->operation(new PaymentReference(new PaymentProvider('fake'), 'pay_1'), 'cancel-key'),
        );
    }

    public function captureIdempotencyRequiresAKey(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Idempotency assertion requires an idempotency key');
        CaptureGatewayAssertions::assertCapturePaymentIdempotency(
            new FakeCaptureGateway(),
            new CapturePaymentRequest(new OperationId('capture-1'), new PaymentReference(new PaymentProvider('fake'), 'pay_1')),
        );
    }

    public function confirmIdempotencyRequiresAKey(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Idempotency assertion requires an idempotency key');
        ConfirmGatewayAssertions::assertConfirmPaymentIdempotency(
            new FakeConfirmGateway(),
            new PaymentOperationRequest(new OperationId('confirm-1'), new PaymentReference(new PaymentProvider('fake'), 'pay_1')),
        );
    }

    public function cancelIdempotencyRequiresAKey(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Idempotency assertion requires an idempotency key');
        CancelGatewayAssertions::assertCancelPaymentIdempotency(
            new FakeCancelGateway(),
            new PaymentOperationRequest(new OperationId('cancel-1'), new PaymentReference(new PaymentProvider('fake'), 'pay_1')),
        );
    }

    public function keyReuseProbeCountsAnAnswerAsARefusal(): void
    {
        Assert::true(KeyReuseProbe::refused(static fn(): never => throw new \InvalidArgumentException('reused')));
        Assert::false(KeyReuseProbe::refused(static fn(): null => null));
    }

    /**
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('inconclusiveProvider')]
    public function keyReuseProbeLetsAnInconclusiveFailureThrough(\Throwable $failure, string $class): void
    {
        Expect::exception($class);
        KeyReuseProbe::refused(static fn(): never => throw $failure);
    }

    /** @return iterable<string, array{\Throwable, class-string<\Throwable>}> */
    public static function inconclusiveProvider(): iterable
    {
        yield 'transport' => [new TransportException('timeout'), TransportException::class];
        yield 'server' => [new \Rasuvaeff\Payments\ServerException('down'), \Rasuvaeff\Payments\ServerException::class];
        yield 'rate limit' => [new \Rasuvaeff\Payments\RateLimitedException('slow down'), \Rasuvaeff\Payments\RateLimitedException::class];
        yield 'unauthorized' => [new \Rasuvaeff\Payments\UnauthorizedException('bad key'), \Rasuvaeff\Payments\UnauthorizedException::class];
        yield 'forbidden' => [new \Rasuvaeff\Payments\ForbiddenException('no access'), \Rasuvaeff\Payments\ForbiddenException::class];
        yield 'type error' => [new \TypeError('broken adapter'), \TypeError::class];
    }

    private function stateFlipping(): ScriptedOperationGateway
    {
        return new ScriptedOperationGateway(
            static fn(string $operation, int $call, CapturePaymentRequest|PaymentOperationRequest $request): PaymentAttempt => ScriptedOperationGateway::attempt(
                $request->operationId,
                'pay_1',
                100,
                $call === 1 ? PaymentState::Canceled : PaymentState::Processing,
            ),
        );
    }

    private function capture(?Money $amount): CapturePaymentRequest
    {
        return new CapturePaymentRequest(
            operationId: new OperationId('capture-1'),
            payment: new PaymentReference(new PaymentProvider('fake'), 'pay_1'),
            amount: $amount,
            idempotencyKey: 'capture-key',
        );
    }

    private function operation(PaymentReference $payment, string $key): PaymentOperationRequest
    {
        return new PaymentOperationRequest(
            operationId: new OperationId('operation-1'),
            payment: $payment,
            idempotencyKey: $key,
        );
    }
}
