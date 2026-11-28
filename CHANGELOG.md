# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- **Dependencies:** development constraints raised to the latest releases, one major each: `rasuvaeff/property-testing-testo` ^1.0 → ^1.4.1, `rasuvaeff/rector-named-literals` ^1.0 → ^1.0.2, `testo/bridge-infection` ^0.1.6 → ^0.1.9, `testo/testo` ^0.10.25 → ^0.10.62, `vimeo/psalm` ^7.0 → ^7.23. The new versions flag nothing. `.github/dependabot.yml` is added from the monorepo template (weekly grouped updates, `rasuvaeff/*` moved by hand). The Makefile mounts a persistent `COMPOSER_HOME` (template `DOCKER :=` line). The CI backward-compatibility step accepts breaks under a higher pre-1.0 minor heading (`## 0.2.0`), as the template does; it accepted them only under a higher major, so this release PR would have stayed red.
- **Breaking:** the fakes gate operations by payment state, as Stripe and PayPal do. `capturePayment()` runs only from `RequiresCapture` (a second capture is refused); `confirmPayment()` only from `Pending` or `RequiresAction`, and moves to `RequiresCapture` for a manual-capture payment or `Succeeded` otherwise (it used to answer `Processing` from any state); `cancelPayment()` is refused for `Succeeded`, `Failed` and `Canceled`. A refused operation throws `PaymentException`. All of these ran from any state: a captured payment could be canceled, a canceled one captured.
- **Breaking:** `FakeGatewayConfig::$createState` is `?PaymentState` and defaults to `null`: the created state then follows the request the way a provider answers it — `RequiresPaymentMethod` without a payment method, `Pending` for manual confirmation, `RequiresCapture` for manual capture, `Succeeded` otherwise. The default used to be `Succeeded` for every create, so a manual-capture create that a test then captured, or a deferred create, now starts where a provider starts it; pin `createState` to keep the old answer. An automatic-capture create with a payment method is `Succeeded` as before.
- **Breaking:** captured amounts follow `rasuvaeff/payments` 0.3 `PaymentAttempt::$capturedAmount`. A capture no longer rewrites `amount` (the authorized amount); it sets `capturedAmount`, which is the full amount for a `Succeeded` create and zero before a capture. Refunds are bounded by `capturedAmount` less earlier refunds, so a partial capture of 700 on 1 200 leaves 700 refundable. `CaptureGatewayAssertions::assertCapturePayment()` checks `capturedAmount` (required, equal to the requested amount or to `amount` for a capture without one) instead of `amount`, and `assertCapturePaymentIdempotency()` derives its probe from it: an adapter that does not map the captured amount from a capture response now fails with `Capture response did not report the captured amount`.
- **Fixed:** the create fingerprint includes the customer (`id` and `providerId`), so the same key with another customer is a conflict, as at Stripe, instead of a replay of the first customer's payment; every other field a provider receives was already in it. The capture fingerprint uses the request's own amount, as the refund one does: a capture without an amount and one naming the full amount are different requests (`amount_to_capture` absent or set). **Behaviour change** for a test that reused a capture key with the amount spelled out.
- **Breaking:** a zero capture or refund amount is refused with a plain `PaymentException` (`Fake capture amount must be positive` / `Fake refund amount must be positive`) before the idempotency key is looked at, so the key stays free. Both were accepted: a capture of 0 moved the payment to `Succeeded` with nothing captured, and a refund of 0 succeeded. A refund amount must be "a positive integer" (https://docs.stripe.com/api/refunds/create); Stripe names no minimum for a capture, so refusing a zero one is the fake's own choice. The ledger model refuses both too.
- **Breaking:** idempotency keys share one space across operations. A key used for one operation is refused for another (a create key reused for a refund, a capture key for a cancel), and every key conflict throws `IdempotencyConflictException` — what `JsonResponseDecoder` raises for a provider's `idempotency_error` — instead of `\InvalidArgumentException`. A replay is checked before the state gate, so a replayed capture of a payment that has since succeeded is still answered from its saved response.
- **Added:** `simulateTransition(PaymentReference, PaymentState, ?OperationId)` on every fake moves a payment the way a provider moves it without an API call — 3-D Secure completed, a deferred payment paid, an authorization expired — so a test reaches the state a webhook reports. Only a move `PaymentState::canTransitionTo()` allows is accepted; `Succeeded` with nothing captured captures the whole amount.
- **Tests:** the model-based ledger test covers the state machine: six create scenarios (paid, authorized, manual confirmation with and without manual capture, requires action, deferred), capture/over-capture/confirm/cancel/refund rules, replays of the last keyed call and of the create, keys from one pool shared by every operation, invariants that the stored payment matches the model, captured ≤ authorized, refunded ≤ captured and that the state moves only along a documented edge, and `Classify::cover` gates on 15 branches (thresholds at about half the measured share). Run against the previous fake, 64 tests fail. New example `examples/authorize-and-capture.php`. A property test holds `AttemptAssertions::same()` to strict field identity over numeric lookalikes (`100`/`1e2`/`0100`).
- **Breaking:** requires `rasuvaeff/payments` `^0.3` (was `^0.1 || ^0.2`). `assertCreatePaymentIdempotency()` no longer throws `InvalidArgumentException` for a request without a key: a 0.3 `CreatePaymentRequest` always carries one, generated when not given, and the assertion replays it.
- **Tests:** a model-based property test (`Gen::rules` over `tests/Support/FakeLedgerMachine.php`) drives captures, refunds, cancels and replays — keys from a small shared pool — against a model of the money a provider allows, with `Classify::cover` gates on refusals, replays and key conflicts. Run against the 0.1.2 fake it fails at once. `rasuvaeff/property-testing-testo` joins `require-dev`; CI installs `mbstring` and keeps the property regression corpus between runs.
- **Added:** `CaptureGatewayAssertions::assertCapturePaymentIdempotency()`, `ConfirmGatewayAssertions::assertConfirmPaymentIdempotency()` and `CancelGatewayAssertions::assertCancelPaymentIdempotency()`. Only create and refund had idempotency assertions. A replayed capture must return the same reference, amount and state, and the key reused with a changed amount must be refused; a replayed confirm or cancel must return the same state.
- **Fixed:** a key-reuse probe no longer counts every `\Throwable` as the gateway refusing the key. A sandbox timeout, a 5xx, a rate limit, rejected credentials (`TransportException`, `ServerException`, `RateLimitedException`, `UnauthorizedException`, `ForbiddenException`) and an `\Error` such as a `TypeError` now propagate, so the assertion is inconclusive instead of passing. **Behaviour change** for an adapter whose refusal surfaces as one of those.
- **Fixed:** the fakes refuse what a provider refuses with money. A refund of a payment that is not `Succeeded`, a refund in another currency or above the balance left after earlier refunds now throws `RefundFailedException` (a refund without an amount takes the remaining balance, so a second full refund is refused); a capture above the created amount or in another currency throws `PaymentException`. All of these were accepted — a refund of 10 000 on a 1 000 payment succeeded, and a USD refund on an EUR payment reported a USD `actualAmount`. A replayed capture, confirm, cancel or refund is answered from its saved response instead of running again, so a replay after a later operation no longer rewrites the payment and a replayed refund is not counted twice. A refund's idempotency fingerprint is now the request's own amount (`null` stays `null`) rather than the amount it resolves to. State gating and one key space followed in this release (see above). **Behaviour change** for tests that relied on the permissive fake.
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
