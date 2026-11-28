# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `create-payment.php` | Deterministic create and retrieve flow | No |
| `authorize-and-capture.php` | Generated create key and replay, created vs captured amount, state-gated capture/cancel, `simulateTransition()` and a refund bounded by the captured amount | No |
| `reconcile-partial-capture.php` | The cookbook's reconciliation recipe: a partial capture leaves the order unpaid, a full one pays it, a `null` captured amount goes to review, and a refund does not lower the gross `capturedAmount` | No |

Run after `composer install`:

```bash
php examples/create-payment.php
php examples/authorize-and-capture.php
php examples/reconcile-partial-capture.php
```
