# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
