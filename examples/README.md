# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `create-payment.php` | Deterministic create and retrieve flow | No |
| `authorize-and-capture.php` | Generated create key and replay, created vs captured amount, state-gated capture/cancel, `simulateTransition()` and a refund bounded by the captured amount | No |

Run after `composer install`:

```bash
php examples/create-payment.php
php examples/authorize-and-capture.php
```
