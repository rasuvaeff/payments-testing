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

- PHP 8.3 – 8.6
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

Fakes ведут себя как провайдер, детерминированно.

**Create отвечает в том состоянии, в каком ответит провайдер.** Если
`FakeGatewayConfig::$createState` его не фиксирует, состояние следует из
запроса:

| Запрос | Состояние | `capturedAmount` |
|---|---|---|
| без `paymentMethod` (отложенный сценарий) | `RequiresPaymentMethod` | ноль |
| `confirmationMethod: Manual` | `Pending` | ноль |
| `captureMethod: Manual` | `RequiresCapture` | ноль |
| иначе | `Succeeded` | вся сумма |

Зафиксированный `createState` действует на каждый create; платёж тогда
сообщает всю сумму захваченной, если он `Succeeded`, и ноль в остальных
случаях.

**Операции разрешены по состоянию**, как у Stripe и PayPal:

| Операция | Разрешена из | Переводит в | Отказ |
|---|---|---|---|
| `capturePayment()` | `RequiresCapture`; не больше `amount`, та же валюта | `Succeeded`, `capturedAmount` = захваченная сумма (`amount`, если суммы нет) | `PaymentException` |
| `confirmPayment()` | `Pending`, `RequiresAction` | `RequiresCapture` для платежа с ручным capture, иначе `Succeeded` | `PaymentException` |
| `cancelPayment()` | любое состояние, кроме `Succeeded`, `Failed`, `Canceled` | `Canceled` | `PaymentException` |
| `createRefund()` | `Succeeded`; та же валюта; не больше `capturedAmount` за вычетом прошлых возвратов (без суммы — весь остаток); без `PartialRefundCapability` — только весь остаток; не больше `maximumRefunds` возвратов | возврат `Succeeded` | `RefundFailedException` |

Нулевая сумма capture или возврата отклоняется простым `PaymentException` ещё
до того, как смотрится ключ, поэтому ключ остаётся свободным: это проверка
параметров, для которой провайдер ничего не сохраняет. Сумма возврата должна
быть «a positive integer» (https://docs.stripe.com/api/refunds/create); минимума
для capture Stripe не называет, и отказ в нулевом — собственный выбор этого
fake.

Capture никогда не меняет `amount`: это остаётся созданная (авторизованная)
сумма, а `capturedAmount` сообщает, сколько списано, так что после частичного
capture 700 из 1 200 вернуть можно 700.

**То, что происходит без API-вызова** — покупатель проходит 3-D Secure или
оплачивает отложенный платёж, перевод зачисляется, авторизация истекает, —
делает `simulateTransition()`, доступный у каждого fake:

```php
$attempt = $gateway->createPayment($deferredRequest);              // RequiresPaymentMethod
$gateway->simulateTransition($attempt->payment, PaymentState::Succeeded); // покупатель оплатил
```

Он принимает только переход, который разрешает `PaymentState::canTransitionTo()`
(иначе `InvalidArgumentException`); переход в `Succeeded` без захваченной суммы
захватывает всю сумму.

**У ключей идемпотентности одно пространство**, как у Stripe, где ключ привязан
к эндпоинту и параметрам первого запроса:

- тот же ключ с тем же запросом — та же операция, платёж и данные — получает
  сохранённый ответ: операция не выполняется заново, проверка состояния не
  применяется, повторный возврат не списывается дважды, а повторный
  `createPayment()` возвращает платёж в том виде, в каком он был создан (с
  `OperationId` повторного вызова), не трогая хранимый;
- тот же ключ с чем-то другим — другими данными, другим платежом, другой
  операцией, ключом от create для refund — бросает
  `IdempotencyConflictException`, то же исключение, что декодер ядра бросает на
  `idempotency_error` провайдера. Данные — это каждое поле, которое получает
  провайдер: у create сумма, валюта, способ оплаты (id и kind), покупатель (id
  и id у провайдера), способы capture и подтверждения, описание и метаданные; у
  capture и refund — собственная сумма запроса, так что опущенная сумма и та же
  полная сумма, названная явно, различаются;
- отклонённый запрос расходует свой ключ, как у Stripe, который «saves the
  status code and body of the first request made with a given idempotency
  key» после начала исполнения эндпоинта, ошибки включительно, и отвечает на
  повторы из сохранённого (https://docs.stripe.com/api/idempotent_requests).
  Отказ по состоянию платежа или балансу, а также capture сверх авторизации
  сохраняется: тот же запрос с тем же ключом снова получает тот же экземпляр
  исключения (даже если платёж с тех пор сдвинулся), а исправленный запрос с
  этим ключом — `IdempotencyConflictException`; исправленный запрос повторяйте
  с новым ключом. То, что Stripe отвергает до запуска эндпоинта, ничего не
  сохраняет и оставляет ключ свободным: проверка параметров (здесь — нулевая
  сумма) и конфликт ключа;
- вызовы без ключа различны. Ключ — вся область видимости: провайдер никогда
  не узнаёт, какой `OperationId` выдал вызов.

Также:

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
| `FakeGatewayConfig` | Provider, capabilities, фиксированное время и необязательный фиксированный create state |

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
`FakeRefundGateway` соблюдает набор так же, как провайдер: без
`PartialRefundCapability` (по умолчанию, `CapabilitySet::of()`) он возвращает
только весь остаток — один полный возврат на платёж — а меньшая сумма бросает
`RefundFailedException`; с `maximumRefunds` он отказывает в возврате после
последнего разрешённого. Чтобы возвращать по частям, настройте частичные
возвраты, как выше.

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
| `CaptureGatewayAssertions` | `assertCapturePayment()` | Capture сохраняет operation id, провайдер консистентен, а `capturedAmount` равен запрошенной сумме (`amount` платежа, если суммы нет); `amount` остаётся авторизацией и не сравнивается, как и payment reference (PayPal отвечает reference самого capture) |
| `CaptureGatewayAssertions` | `assertCapturePaymentIdempotency()` | Повтор ключа возвращает ту же reference, сумму, захваченную сумму и состояние, а переиспользование ключа с изменённой суммой (на единицу от захваченной) отвергается |
| `ConfirmGatewayAssertions` | `assertConfirmPayment()` | Confirm сохраняет operation id и payment reference |
| `ConfirmGatewayAssertions` | `assertConfirmPaymentIdempotency()` | Повтор ключа возвращает то же состояние |
| `CancelGatewayAssertions` | `assertCancelPayment()` | Cancel сохраняет operation id и payment reference |
| `CancelGatewayAssertions` | `assertCancelPaymentIdempotency()` | Повтор ключа возвращает то же состояние |
| `RefundGatewayAssertions` | `assertCreateRefund()` | Консистентность provider/payment/refund, сохранение requested amount |
| `RefundGatewayAssertions` | `assertCreateRefundIdempotency()` | Повтор ключа возвращает тот же возврат, **другой** ключ начинает новый, а переиспользование ключа с изменённой суммой отвергается |
| `RefundGatewayAssertions` | `assertRetrieveRefund()` | Консистентность провайдера, сохранение operation id и refund reference |

Capability-специфичные классы требуют intersection-тип
(например `PaymentGatewayInterface&CaptureGatewayInterface`), поэтому вызов на
адаптере без optional-интерфейса — ошибка типов, а не сюрприз в рантайме.
Каждый assertion-метод возвращает полученный attempt для дальнейших
framework-специфичных проверок.

Idempotency-assertions для capture, confirm, cancel и refund требуют
`idempotencyKey` в запросе и бросают `InvalidArgumentException`, если он
отсутствует. У `CreatePaymentRequest` ключ есть всегда — переданный или
сгенерированный им UUID, — и `assertCreatePaymentIdempotency()` реплеит его.

Проба переиспользования ключа считает отказом только ответ: любое `\Exception`,
которое шлюз бросил на изменённый запрос, кроме `TransportException`,
`ServerException`, `RateLimitedException`, `UnauthorizedException` и
`ForbiddenException`. Они ничего не говорят о ключе, поэтому пробрасываются, и
assertion не пройден, а не засчитан; `\Error` вроде `TypeError` тоже
пробрасывается. Шлюз, отвергающий изменённый запрос по своим причинам (адаптер
без частичного capture), эту половину всё равно проходит.

Не проверяется: состояние, которое сообщает операция (capture законно может
вернуть `Processing`, `Pending` или `Failed`), и ключ confirm или cancel,
переиспользованный для другого платежа.

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
