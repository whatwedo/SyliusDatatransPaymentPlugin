# Sylius Datatrans Payment Plugin

[Datatrans](https://www.datatrans.ch/) payment gateway for [Sylius](https://sylius.com/), based on the
Datatrans [JSON API v1](https://api-reference.datatrans.ch/) and the Redirect payment page.

## Requirements

- PHP 8.0 or newer
- Sylius 1.11 or newer, including Sylius 2.x (winzou state machine and the Sylius state machine abstraction are both supported)

## Installation

```bash
composer require whatwedo/sylius-datatrans-payment-plugin
```

Register the bundle in `config/bundles.php` if Symfony Flex did not do it:

```php
return [
    // ...
    Whatwedo\SyliusDatatransPaymentPlugin\WhatwedoSyliusDatatransPaymentPlugin::class => ['all' => true],
];
```

Import the routes, e.g. in `config/routes/whatwedo_sylius_datatrans_payment.yaml`:

```yaml
whatwedo_sylius_datatrans_payment:
    resource: '@WhatwedoSyliusDatatransPaymentPlugin/Resources/config/routing.yml'
```

## Gateway configuration

Create a payment method in the Sylius admin and choose the gateway **Datatrans Payment**.

| Field | Description |
|---|---|
| Merchant ID | Your Datatrans merchant id. |
| Password | Server-to-server API password. In the Datatrans dashboard go to *UPP Administration > Security*. The stored password is never rendered back; leave the field empty to keep it. |
| Endpoint | `https://api.sandbox.datatrans.com` for testing, `https://api.datatrans.com` for production. The payment page (`pay.sandbox.datatrans.com` / `pay.datatrans.com`) is derived from it. |
| Sign / HMAC SHA256 | Legacy settings of the old XML/redirect API. They are optional and unused: JSON API v1 requests are authenticated with merchant id and password. |
| Generate link | Do not redirect the customer during checkout, only create the transaction. Used for payment links created in the admin (see below). |
| Payment methods | Payment methods offered on the Datatrans payment page (Visa, Mastercard, TWINT, PostFinance Pay, ...). |

## Payment flow

1. On checkout the capture action creates a transaction (`POST /v1/transactions`) with the payment amount and currency,
   the order number as `refno` and the order locale as payment page language (falls back to `de` if Datatrans does
   not support the locale). The customer is redirected to the Datatrans payment page.
2. Datatrans redirects back to Sylius, which asks the gateway for the payment status (`GET /v1/transactions/{id}`).
   The response is stored in the payment details under `datatrans`.
3. If a payment already has a paid or authorized transaction, retrying the payment does not create a second
   transaction. A new transaction is only created if Datatrans reports a definite status for the existing ones that is
   neither paid nor authorized. If their status cannot be determined (Datatrans error response, network error), no new
   transaction is created and the customer can retry later.
4. When a transaction is replaced, its id is moved to `previousTransactionIds` in the payment details, so the id of a
   transaction that is paid after it was replaced (e.g. in a second browser tab) is kept. If the current transaction is
   not paid, the status check also looks at these previous transactions and completes the payment if one of them is
   paid. If the status of a previous transaction cannot be determined, the payment status is unknown and the payment
   is left unchanged until a later check succeeds.

| Datatrans status | Sylius payment |
|---|---|
| `settled`, `transmitted` | completed |
| `authorized` | authorized |
| `canceled` | cancelled |
| `failed` | failed |
| `initialized` | new |
| `challenge_required`, `challenge_ongoing`, `authenticated`, anything else | unchanged (unknown) |

A paid or authorized status is only accepted if currency and amount reported by Datatrans match the Sylius payment;
otherwise the status is treated as unknown and the payment is left untouched for manual review: it is neither
completed nor cancelled automatically. If the transaction
could not be created, the Datatrans error is stored in the payment details under `error` and the payment fails.

## Status check command

Customers sometimes close the browser before they are redirected back to the shop. The following command fetches
the status of all Datatrans payments in state `new` or `authorized` that have a transaction and applies the
transitions from the table above.

A payment in state `new` is cancelled after two weeks if Datatrans still reports a pending status (`initialized`,
`challenge_required`, `challenge_ongoing`, `authenticated`) for it. The two weeks are counted from the later of the
payment creation date and the transaction expiry (`detail.init.expires`). The timeout never cancels payments in state
`authorized`, payments with a paid current or previous transaction, or payments for which the status of the current or
a previous transaction could not be fetched (Datatrans error or network error). Payments without a current transaction
(e.g. the replacement could not be created) are checked through their previous transactions only. If a payment cannot be checked, its changes are discarded and the next payment is checked; if the
entity manager was closed by a database error, the command stops and exits with a non-zero code.

```bash
bin/console whatwedo:sylius-datatrans-payment:check-payment-status
```

Run it regularly, e.g. every 10 minutes:

```cron
*/10 * * * * cd /path/to/project && bin/console whatwedo:sylius-datatrans-payment:check-payment-status --env=prod
```

## Payment links from the admin

With *Generate link* enabled, a payment whose details contain a `payment-link` entry (the URL the customer returns to
after paying, e.g. the shop order page) can be paid through `/pay-from-link/{payment}?token={order token}`.
The route only works for payments in state `new` and returns a 404 if the `token` query parameter does not match the
order's token value.

Show the link with a copy button in an admin template that has a `payment` variable:

```twig
{% include '@WhatwedoSyliusDatatransPaymentPlugin/admin-link-payment.html.twig' with { payment: payment } %}
```

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The tests are plain unit tests (`tests/Unit`) and need no database or Sylius application.
