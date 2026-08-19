# rasuvaeff/payments-testing

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/payments-testing/v)](https://packagist.org/packages/rasuvaeff/payments-testing)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/payments-testing/downloads)](https://packagist.org/packages/rasuvaeff/payments-testing)
[![Build](https://github.com/rasuvaeff/payments-testing/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/payments-testing/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/payments-testing/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/payments-testing/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/payments-testing/actions/workflows/static-analysis.yml)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Testing-инструментарий для контрактов `rasuvaeff/payments`: детерминированные
in-memory fake-гейтвеи для unit-тестов и локальной разработки плюс
framework-agnostic contract assertions, проверяющие, что любой реальный адаптер
gateway соблюдает те же контракты, что и fakes.

> Используете AI-ассистента? [llms.txt](llms.txt) содержит компактный API-справочник.

## Требования

- PHP 8.3+
- `rasuvaeff/payments`

## Установка

```bash
composer require --dev rasuvaeff/payments-testing
```

## Использование

### Fake-гейтвеи

```php
use Rasuvaeff\PaymentsTesting\FakePaymentGateway;

$gateway = new FakePaymentGateway();
$attempt = $gateway->createPayment($request);
$sameAttempt = $gateway->retrievePayment(new RetrievePaymentRequest(
    operationId: new OperationId(value: 'reconcile-1'),
    payment: $attempt->payment,
));
```

Fakes полностью детерминированы:

- Вызовы с одинаковым idempotency key и одинаковым fingerprint запроса
  возвращают одну provider reference. Ключ — вся область видимости: провайдер
  никогда не узнаёт, какой `OperationId` выдал вызов, поэтому две операции с
  одним ключом воспроизводят друг друга ровно так, как это сделает настоящий
  шлюз.
- Повторное использование ключа с другими данными бросает исключение; вызовы
  без ключа получают разные детерминированные references.
- References другого провайдера и неизвестные references отклоняются.
- Время фиксируется через `FakeGatewayConfig` (`now`, по умолчанию
  `2025-01-01T00:00:00+00:00`) — wall clock не используется.

Каждый fake реализует только optional-операцию, названную в его классе;
базовый fake никогда не реализует capture, confirm, cancel или refund неявно.

| Тип | Optional-интерфейс |
|---|---|
| `FakePaymentGateway` | Только базовый gateway |
| `FakeCaptureGateway` | `CaptureGatewayInterface` |
| `FakeConfirmGateway` | `ConfirmGatewayInterface` |
| `FakeCancelGateway` | `CancelGatewayInterface` |
| `FakeRefundGateway` | `RefundGatewayInterface` |
| `FakeGatewayConfig` | Provider, capabilities, фиксированное время и create state |

Конфигурация:

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

`PartialRefundCapability` в capability set у fake без refund бросает
`InvalidArgumentException` — capabilities обязаны оставаться правдивыми.

### Contract assertions

Статические assertion-классы прогоняют реальный (или fake) адаптер gateway
через контракт и бросают `ContractViolationException` при любом нарушении.
Они framework-agnostic — неперехваченное исключение проваливает тест в любом
фреймворке (Testo, PHPUnit, Pest, обычный PHP-скрипт).

```php
use Rasuvaeff\PaymentsTesting\PaymentGatewayAssertions;

$capabilities = PaymentGatewayAssertions::assertCapabilities($gateway);
$attempt = PaymentGatewayAssertions::assertCreatePayment($gateway, $createRequest);
// Делает четыре вызова: ключ дважды, затем другой ключ, затем тот же ключ с
// изменённой суммой. Проверка одного лишь повтора пропустила бы шлюз, который
// игнорирует ключ целиком и всегда отдаёт одну и ту же ссылку.
PaymentGatewayAssertions::assertCreatePaymentIdempotency($gateway, $keyedRequest);
PaymentGatewayAssertions::assertRetrievePayment($gateway, new RetrievePaymentRequest(
    operationId: new OperationId(value: 'reconcile-1'),
    payment: $attempt->payment,
));
```

| Класс | Метод | Проверяет |
|---|---|---|
| `PaymentGatewayAssertions` | `assertCapabilities()` | `PartialRefundCapability` требует `RefundGatewayInterface` |
| `PaymentGatewayAssertions` | `assertCreatePayment()` | Консистентность провайдера, сохранение operation id и amount |
| `PaymentGatewayAssertions` | `assertCreatePaymentIdempotency()` | Повтор ключа возвращает тот же платёж, **другой** ключ начинает новый, а переиспользование ключа с изменённым запросом отвергается |
| `PaymentGatewayAssertions` | `assertRetrievePayment()` | Консистентность провайдера, сохранение operation id и payment reference |
| `CaptureGatewayAssertions` | `assertCapturePayment()` | Capture сохраняет operation id и amount, провайдер консистентен |
| `ConfirmGatewayAssertions` | `assertConfirmPayment()` | Confirm сохраняет operation id и payment reference |
| `CancelGatewayAssertions` | `assertCancelPayment()` | Cancel сохраняет operation id и payment reference |
| `RefundGatewayAssertions` | `assertCreateRefund()` | Консистентность provider/payment/refund, сохранение requested amount |
| `RefundGatewayAssertions` | `assertCreateRefundIdempotency()` | Повтор ключа возвращает тот же возврат, **другой** ключ начинает новый, а переиспользование ключа с изменённой суммой отвергается |
| `RefundGatewayAssertions` | `assertRetrieveRefund()` | Консистентность провайдера, сохранение operation id и refund reference |

Capability-специфичные классы требуют intersection-тип
(например `PaymentGatewayInterface&CaptureGatewayInterface`), поэтому вызов на
адаптере без optional-интерфейса — ошибка типов, а не сюрприз в рантайме.
Каждый assertion-метод возвращает полученный attempt для дальнейших
framework-специфичных проверок.

Idempotency-assertions требуют `idempotencyKey` в запросе и бросают
`InvalidArgumentException`, если он отсутствует.

## Безопасность

Пакет предназначен только для тестов. Не помещайте production credentials,
PAN/CVC или исходные webhook body в fake-запросы. Idempotency fingerprints
хранятся только в памяти и содержат нормализованные значения запроса.

## Примеры

См. [examples/](examples/).

| Скрипт | Что показывает | Нужен сервер? |
|---|---|---|
| `create-payment.php` | Детерминированные create и retrieve | Нет |

## Разработка

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

## Лицензия

BSD-3-Clause
