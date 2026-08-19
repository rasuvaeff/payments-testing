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

- PHP 8.3+
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

The fakes are fully deterministic:

- Calls with the same idempotency key and the same request fingerprint return
  the same provider reference.
- Reusing a key with different request data throws; calls without a key
  receive distinct deterministic references.
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
| `FakeGatewayConfig` | Provider, capabilities, fixed time and create state |

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
| `CaptureGatewayAssertions` | `assertCapturePayment()` | Capture preserves operation id and amount, provider consistent |
| `ConfirmGatewayAssertions` | `assertConfirmPayment()` | Confirm preserves operation id and payment reference |
| `CancelGatewayAssertions` | `assertCancelPayment()` | Cancel preserves operation id and payment reference |
| `RefundGatewayAssertions` | `assertCreateRefund()` | Refund provider/payment/refund consistency, requested amount preserved |
| `RefundGatewayAssertions` | `assertCreateRefundIdempotency()` | Same keyed refund twice returns the same refund reference |
| `RefundGatewayAssertions` | `assertRetrieveRefund()` | Provider consistency, operation id and refund reference preserved |

Capability-specific classes require the intersection type
(e.g. `PaymentGatewayInterface&CaptureGatewayInterface`), so calling them on an
adapter that does not implement the optional interface is a type error, not a
runtime surprise. Every assertion method returns the resulting attempt for
further framework-specific checks.

Idempotency assertions require `idempotencyKey` on the request and throw
`InvalidArgumentException` when it is missing.

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
