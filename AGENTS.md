# AGENTS.md — payments-testing

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/payments-testing` is the testing toolkit for the `rasuvaeff/payments`
contracts, namespace `Rasuvaeff\PaymentsTesting`. It has two parts:
deterministic in-memory fake gateways (`FakePaymentGateway`,
`FakeCaptureGateway`, `FakeConfirmGateway`, `FakeCancelGateway`,
`FakeRefundGateway`, configured via `FakeGatewayConfig`) and framework-agnostic
contract assertions (`PaymentGatewayAssertions`, `CaptureGatewayAssertions`,
`ConfirmGatewayAssertions`, `CancelGatewayAssertions`,
`RefundGatewayAssertions`) that throw `ContractViolationException` when an
adapter breaks the contract.

## Golden rules

1. **Verification is mandatory.** Never claim done without a fresh green `composer build`.
2. **No suppressions.** Fix Psalm findings at their source.
3. **Optional interfaces stay truthful.** The base fake must never implement capture, confirm, cancel or refund implicitly, and the assertions must catch a gateway that advertises a capability it does not implement.
4. **Preserve the public contract.** Update both README files, llms.txt and tests with API changes.

## Commands

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

## Invariants & gotchas

- **A contract assertion must fail the implementation it is named after.**
  `assertCreatePaymentIdempotency()` checks three things — replay returns the
  original, a different key starts a new payment, and a key reused with a
  changed request is refused. Checking only the replay passed a gateway that
  ignored the key entirely, which is exactly the defect the assertion exists to
  catch. When adding an assertion, write the broken double first and confirm it
  fails.

- Same idempotency key plus same fingerprint returns the same reference. The
  key alone is the scope — never the `OperationId`, which no provider sees.
  Scoping by operation makes the fake accept a key collision that the real
  gateway refuses, and a permissive double is the one failure mode a testing
  package cannot afford.
- Same key plus different fingerprint throws; calls without a key remain distinct.
- References from another provider and unknown references are rejected.
- Time is fixed through `FakeGatewayConfig`; tests must not use the wall clock.
- Capability-specific assertions take intersection types
  (`PaymentGatewayInterface&CaptureGatewayInterface` etc.) — missing optional
  interfaces are a type error, not a runtime check.
- Assertions are framework-agnostic: they signal failure only by throwing
  `ContractViolationException`; never couple them to a test framework API.
- CI workflows remain SHA-pinned with read-only permissions and disabled checkout credentials.

## When you finish

- Update `README.md` and `README.ru.md` together, plus examples and llms.txt.
- Run `composer build` and `bin/package-audit payments-testing`.
