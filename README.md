# rasuvaeff/payments-testing

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/payments-testing/v)](https://packagist.org/packages/rasuvaeff/payments-testing)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/payments-testing/downloads)](https://packagist.org/packages/rasuvaeff/payments-testing)
[![Build](https://github.com/rasuvaeff/payments-testing/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/payments-testing/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/payments-testing/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/payments-testing/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/payments-testing/actions/workflows/static-analysis.yml)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Testing toolkit for the `rasuvaeff/payments` contracts: deterministic in-memory
fake gateways for unit tests and local development, plus framework-agnostic
contract assertions that verify any real gateway adapter honours the same
contracts the fakes do.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference.

## Requirements

- PHP 8.3 – 8.6
- `rasuvaeff/payments`

## Installation

```bash
composer require --dev rasuvaeff/payments-testing
```

## Usage

### Fake gateways

```php
use Rasuvaeff\PaymentsTesting\FakePaymentGateway;

$gateway = new FakePaymentGateway();
$attempt = $gateway->createPayment($request);
$sameAttempt = $gateway->retrievePayment(new RetrievePaymentRequest(
    operationId: new OperationId(value: 'reconcile-1'),
    payment: $attempt->payment,
));
```

The fakes behave like a provider, deterministically.

**A create answers in the state a provider answers it.** Unless
`FakeGatewayConfig::$createState` pins one, the state follows the request:

| Request | State | `capturedAmount` |
|---|---|---|
| no `paymentMethod` (deferred flow) | `RequiresPaymentMethod` | zero |
| `confirmationMethod: Manual` | `Pending` | zero |
| `captureMethod: Manual` | `RequiresCapture` | zero |
| otherwise | `Succeeded` | the full amount |

A pinned `createState` applies to every create; the payment then reports the
full amount captured when it is `Succeeded`, zero otherwise.

**Operations are gated by state**, as at Stripe and PayPal:

| Operation | Allowed from | Moves to | Refused with |
|---|---|---|---|
| `capturePayment()` | `RequiresCapture`; up to `amount`, same currency | `Succeeded`, `capturedAmount` = the captured amount (`amount` without one) | `PaymentException` |
| `confirmPayment()` | `Pending`, `RequiresAction` | `RequiresCapture` for a manual-capture payment, `Succeeded` otherwise | `PaymentException` |
| `cancelPayment()` | any state but `Succeeded`, `Failed`, `Canceled` | `Canceled` | `PaymentException` |
| `createRefund()` | `Succeeded`; same currency; up to `capturedAmount` less earlier refunds (without an amount: the rest) | refund `Succeeded` | `RefundFailedException` |

A capture never changes `amount`: it stays the created (authorized) amount,
and `capturedAmount` reports what was taken, so a partial capture of 700 on
1 200 leaves 700 refundable.

**What happens without an API call** — the customer completes 3-D Secure or
pays a deferred payment, a transfer settles, an authorization expires — is
`simulateTransition()`, available on every fake:

```php
$attempt = $gateway->createPayment($deferredRequest);              // RequiresPaymentMethod
$gateway->simulateTransition($attempt->payment, PaymentState::Succeeded); // the customer paid
```

It accepts only a move `PaymentState::canTransitionTo()` allows
(`InvalidArgumentException` otherwise); reaching `Succeeded` with nothing
captured captures the whole amount.

**Idempotency keys share one space**, as at Stripe, where a key is bound to the
endpoint and parameters of its first request:

- the same key with the same request — the same operation, payment and
  payload — is answered from the saved response: the operation does not run
  again, the state gate does not apply, a replayed refund is not counted twice,
  and a replayed `createPayment()` returns the payment as it was created
  (stamped with the replay's `OperationId`) without touching the stored one;
- the same key with anything else — another payload, another payment, another
  operation, a create key reused for a refund — throws
  `IdempotencyConflictException`, the exception the core decoder raises for a
  provider's `idempotency_error`. The payload is every field a provider
  receives: for a create the amount, currency, payment method (id and kind),
  customer (id and provider id), capture and confirmation method, description
  and metadata; for a capture or refund the request's own amount, so an
  omitted amount and the full amount named explicitly differ;
- a refused request leaves its key unused; calls without a key are distinct.
  The key is the whole scope: a provider never learns which `OperationId`
  issued the call.

Also:

- References from another provider and unknown references are rejected.
- Time is fixed through `FakeGatewayConfig` (`now`, default
  `2025-01-01T00:00:00+00:00`) — no wall clock involved.

Each fake implements only the optional operation its class names; the base
fake never implements capture, confirm, cancel or refund implicitly.

| Type | Optional interface |
|---|---|
| `FakePaymentGateway` | None; base gateway only |
| `FakeCaptureGateway` | `CaptureGatewayInterface` |
| `FakeConfirmGateway` | `ConfirmGatewayInterface` |
| `FakeCancelGateway` | `CancelGatewayInterface` |
| `FakeRefundGateway` | `RefundGatewayInterface` |
| `FakeGatewayConfig` | Provider, capabilities, fixed time and an optional pinned create state |

Configuration:

```php
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;
use Rasuvaeff\PaymentsTesting\FakeRefundGateway;

$gateway = new FakeRefundGateway(config: new FakeGatewayConfig(
    provider: new PaymentProvider(value: 'fake'),
    capabilities: CapabilitySet::of(new PartialRefundCapability()),
    now: new \DateTimeImmutable('2025-06-01T12:00:00+00:00'),
    createState: PaymentState::Pending,
));
```

`PartialRefundCapability` in the capability set of a non-refund fake throws
`InvalidArgumentException` — capabilities must stay truthful.

### Contract assertions

Static assertion classes run a real (or fake) gateway adapter through the
contract and throw `ContractViolationException` on any violation. They are
framework-agnostic — an uncaught exception fails the test in any framework
(Testo, PHPUnit, Pest, plain PHP script).

```php
use Rasuvaeff\PaymentsTesting\PaymentGatewayAssertions;

$capabilities = PaymentGatewayAssertions::assertCapabilities($gateway);
$attempt = PaymentGatewayAssertions::assertCreatePayment($gateway, $createRequest);
// Issues four calls: the key twice, then a distinct key, then the key with a
// changed amount. Checking only the replay would pass a gateway that ignores
// the key entirely and always answers with the same reference.
PaymentGatewayAssertions::assertCreatePaymentIdempotency($gateway, $keyedRequest);
PaymentGatewayAssertions::assertRetrievePayment($gateway, new RetrievePaymentRequest(
    operationId: new OperationId(value: 'reconcile-1'),
    payment: $attempt->payment,
));
```

| Class | Method | Verifies |
|---|---|---|
| `PaymentGatewayAssertions` | `assertCapabilities()` | `PartialRefundCapability` implies `RefundGatewayInterface` |
| `PaymentGatewayAssertions` | `assertCreatePayment()` | Provider consistency, operation id and amount preserved |
| `PaymentGatewayAssertions` | `assertCreatePaymentIdempotency()` | Replaying a key returns the same payment, a **different** key starts a new one, and reusing a key with a changed request is refused |
| `PaymentGatewayAssertions` | `assertRetrievePayment()` | Provider consistency, operation id and payment reference preserved |
| `CaptureGatewayAssertions` | `assertCapturePayment()` | Capture preserves operation id, provider consistent, and reports `capturedAmount` equal to the requested amount (the payment's `amount` without one); `amount` stays the authorization and is not compared, nor is the payment reference (PayPal answers with the capture's) |
| `CaptureGatewayAssertions` | `assertCapturePaymentIdempotency()` | Replaying a key returns the same reference, amount, captured amount and state, and reusing the key with a changed amount (one unit off the captured amount) is refused |
| `ConfirmGatewayAssertions` | `assertConfirmPayment()` | Confirm preserves operation id and payment reference |
| `ConfirmGatewayAssertions` | `assertConfirmPaymentIdempotency()` | Replaying a key returns the same state |
| `CancelGatewayAssertions` | `assertCancelPayment()` | Cancel preserves operation id and payment reference |
| `CancelGatewayAssertions` | `assertCancelPaymentIdempotency()` | Replaying a key returns the same state |
| `RefundGatewayAssertions` | `assertCreateRefund()` | Refund provider/payment/refund consistency, requested amount preserved |
| `RefundGatewayAssertions` | `assertCreateRefundIdempotency()` | Replaying a key returns the same refund, a **different** key starts a new one, and reusing a key with a changed amount is refused |
| `RefundGatewayAssertions` | `assertRetrieveRefund()` | Provider consistency, operation id and refund reference preserved |

Capability-specific classes require the intersection type
(e.g. `PaymentGatewayInterface&CaptureGatewayInterface`), so calling them on an
adapter that does not implement the optional interface is a type error, not a
runtime surprise. Every assertion method returns the resulting attempt for
further framework-specific checks.

The capture, confirm, cancel and refund idempotency assertions require
`idempotencyKey` on the request and throw `InvalidArgumentException` when it
is missing. A `CreatePaymentRequest` always has a key — the one you passed or
the UUID it generated — so `assertCreatePaymentIdempotency()` replays that.

The key-reuse probe counts only an answer as a refusal: any `\Exception` the
gateway throws for the changed request, except `TransportException`,
`ServerException`, `RateLimitedException`, `UnauthorizedException` and
`ForbiddenException`. Those say nothing about the key, so they propagate and
the assertion is inconclusive rather than passed; an `\Error` such as a
`TypeError` propagates too. A gateway that refuses the changed request on its
own grounds (an adapter without partial capture) still passes that half.

Not checked: the state an operation reports (a capture may legitimately come
back `Processing`, `Pending` or `Failed`), and a confirm or cancel key reused
for another payment.

## Security

The package is test-only. Never place production credentials, PAN/CVC or raw
webhook bodies in fake requests. Idempotency fingerprints are kept in memory
and contain only normalized request values.

## Examples

See [examples/](examples/).

| Script | Shows | Needs server? |
|---|---|---|
| `create-payment.php` | Deterministic create and retrieve flow | No |

## Development

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

## License

BSD-3-Clause
