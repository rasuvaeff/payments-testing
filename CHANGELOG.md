# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- **Breaking:** requires `rasuvaeff/payments` `^0.3` (was `^0.1 || ^0.2`). `assertCreatePaymentIdempotency()` no longer throws `InvalidArgumentException` for a request without a key: a 0.3 `CreatePaymentRequest` always carries one, generated when not given, and the assertion replays it.
- **Tests:** a model-based property test (`Gen::rules` over `tests/Support/FakeLedgerMachine.php`) drives captures, refunds, cancels and replays — keys from a small shared pool — against a model of the money a provider allows, with `Classify::cover` gates on refusals, replays and key conflicts. Run against the 0.1.2 fake it fails at once. `rasuvaeff/property-testing-testo` joins `require-dev`; CI installs `mbstring` and keeps the property regression corpus between runs.
- **Added:** `CaptureGatewayAssertions::assertCapturePaymentIdempotency()`, `ConfirmGatewayAssertions::assertConfirmPaymentIdempotency()` and `CancelGatewayAssertions::assertCancelPaymentIdempotency()`. Only create and refund had idempotency assertions. A replayed capture must return the same reference, amount and state, and the key reused with a changed amount must be refused; a replayed confirm or cancel must return the same state.
- **Fixed:** a key-reuse probe no longer counts every `\Throwable` as the gateway refusing the key. A sandbox timeout, a 5xx, a rate limit, rejected credentials (`TransportException`, `ServerException`, `RateLimitedException`, `UnauthorizedException`, `ForbiddenException`) and an `\Error` such as a `TypeError` now propagate, so the assertion is inconclusive instead of passing. **Behaviour change** for an adapter whose refusal surfaces as one of those.
- **Fixed:** the fakes refuse what a provider refuses with money. A refund of a payment that is not `Succeeded`, a refund in another currency or above the balance left after earlier refunds now throws `RefundFailedException` (a refund without an amount takes the remaining balance, so a second full refund is refused); a capture above the created amount or in another currency throws `PaymentException`. All of these were accepted — a refund of 10 000 on a 1 000 payment succeeded, and a USD refund on an EUR payment reported a USD `actualAmount`. A replayed capture, confirm, cancel or refund is answered from its saved response instead of running again, so a replay after a later operation no longer rewrites the payment and a replayed refund is not counted twice. A refund's idempotency fingerprint is now the request's own amount (`null` stays `null`) rather than the amount it resolves to. Still not modelled, and documented: state gating of capture/confirm/cancel, one key space across operations. **Behaviour change** for tests that relied on the permissive fake.
- **Docs:** `llms.txt` described the refund key-reuse probe as `amount + 1`; it asks for one minor unit less, as the README and the 0.1.0 notes say.
- **Fixed:** the contract assertions compare operation ids, providers, references and amounts field by field with `===`. They used loose `!=`, which compares numeric strings as numbers, so an adapter returning `OperationId('1e2')` for `OperationId('100')`, or payment id `'010'` for `'10'`, passed.
- **Fixed:** a replayed `createPayment()` (same idempotency key, same request) no longer rewrites the stored payment. The fake rebuilt the attempt from `FakeGatewayConfig::$createState` and the request amount and saved it, so a payment created as `RequiresCapture` and captured for 5000 read back as `RequiresCapture`/1000 after the replay. A replay now returns the response saved for the key — the payment as it was created, with the replay's `OperationId` — which is what a provider does (https://docs.stripe.com/api/idempotent_requests), and the stored payment is left as it is. **Behaviour change** for tests that relied on the rollback.

## 0.1.2 — 2026-11-22

- `composer mutation` now runs Infection with `--with-uncovered`, so code the tests execute without naming it in `#[Covers]` is mutated and counted (overall MSI was 41% when measured this way, now 100% against `minMsi` 100). `Internal\AbstractFakeGateway`, `Internal\AttemptAssertions` and `Internal\FakeGatewayCore` were named in no `#[Covers]` and caused all 94 Not Covered mutants. Added `FakeGatewayCoreTest` (pinned fake references, idempotency scoping per operation and payment, null-safe reads, unknown/foreign refund references) and `AttemptAssertionsTest` (every contract violation message). `infection.json5` gained the template's signature-default ignore regex, `testFrameworkOptions` (Unit suite only) and disabled `PublicVisibility`/`ProtectedVisibility`; three equivalent `MethodCallRemoval` mutants in `AttemptAssertions` are ignored with a comment. The capture fingerprint and the confirm/cancel/capture idempotency scope inside `FakeGatewayCore` no longer use string concatenation (`serialize([...])` and a nested array); both are internal and not observable, so behaviour is unchanged.
- **Changed:** PHP 8.6 is supported: the `php` constraint is widened to
  `8.3 - 8.6` (#6). CI runs PHP 8.6 as a regular matrix entry; the
  backward-compatibility job runs on PHP 8.5.

## 0.1.1 — 2026-10-16

### Changed

- Accept `rasuvaeff/payments` `^0.2` alongside `^0.1`. The 0.2.0 break —
  claim-fenced `WebhookEventStoreInterface` — is in an API this package does
  not touch; the fakes and the contract assertions build unchanged against
  0.2.0, and the lowest-dependency job keeps covering 0.1.

## 0.1.0 — 2026-08-19

Initial release.

### Added

- Deterministic in-memory fakes for the `rasuvaeff/payments` gateway contracts:
  `FakePaymentGateway` plus `FakeCaptureGateway`, `FakeConfirmGateway`,
  `FakeCancelGateway` and `FakeRefundGateway` for the optional interfaces, all
  configured through one `FakeGatewayConfig` so a test states the outcome it
  needs instead of scripting a provider.
- Framework-agnostic contract assertions — `PaymentGatewayAssertions`,
  `CaptureGatewayAssertions`, `ConfirmGatewayAssertions`,
  `CancelGatewayAssertions` and `RefundGatewayAssertions` — that hold a real
  adapter to the same contracts the fakes implement, and fail with
  `ContractViolationException` naming the clause that broke.
- Idempotency keys scope by the key alone. Scoping them by `OperationId` made
  the fakes accept a key collision across two operations that every real
  provider refuses — the one direction a testing double must not be wrong in,
  because the application bug then passes its tests and surfaces in production.
- `assertCreateRefundIdempotency()` holds refunds to the same three-part
  contract as payments: replaying a key returns the original refund, a
  different key starts a new one, and reusing a key with a changed amount is
  refused. The probe steps the amount *down* wherever it can, so a gateway that
  refuses it can only be refusing the key reuse rather than a balance it could
  not cover.
- `assertCreatePaymentIdempotency()` checks all three halves of the contract:
  replaying a key returns the original payment, a different key starts a new
  one, and reusing a key with a changed request is refused. Checking only the
  replay passes a gateway that ignores the key entirely and always answers with
  the same reference.
