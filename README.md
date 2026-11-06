# Resrv Payment Gateway for Piraeus Bank epay

This add-on adds a [Piraeus Bank epay](https://www.piraeusbank.gr) (Euronet Merchant Services "Redirection" solution) payment gateway to [Statamic Resrv](https://github.com/reachweb/statamic-resrv).

## How it works

1. When the customer reaches the payment step, the gateway calls the epay ticketing web service (`IssueNewTicket`) with the amount and a unique `MerchantReference`, and stores the returned ticket in the `resrv_epay_transactions` table.
2. The customer's browser posts the epay form to the epay payment page, where they enter their card details and complete 3D Secure.
3. epay posts the result to `/resrv/epay/success` or `/resrv/epay/failure`. The add-on verifies the `HashKey` (HMAC-SHA256 keyed with the ticket) and confirms the reservation only when the payment was approved, the hash matches and the ticket is the reservation's current payment attempt. A payment declined by the card issuer, or «Cancel» on the epay page, expires the reservation so its dates are released straight away. Other failures (for example ResultCode 1045, "currently processed", or a communication error) leave it pending, because a signed approval can still follow; the normal hold expiry applies.
4. The customer is redirected to the Resrv checkout completed page, where `{{ resrv_checkout_redirect }}` shows the result.

An approved payment that cannot confirm its reservation (a replaced payment attempt, or a reservation that expired meanwhile) is logged as a warning: refund it from the epay back office.

The ticketing call is a plain HTTP SOAP request, so `ext-soap` is not required.

## Installation

```bash
composer require reachweb/resrv-payment-epay
php artisan migrate
```

Register the gateway in `config/resrv-config.php`. The key must be `epay`:

```php
'payment_gateways' => [
    'epay' => [
        'class' => \Reach\ResrvPaymentEpay\Http\Payment\EpayPaymentGateway::class,
    ],
],
```

## Configuration

Set the credentials Euronet Merchant Services provides:

```env
EPAY_ACQUIRER_ID=
EPAY_MERCHANT_ID=
EPAY_POS_ID=
EPAY_USERNAME=
EPAY_PASSWORD=
```

Optional settings (defaults shown):

```env
EPAY_TICKETING_URL=https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx
EPAY_PAYMENT_URL=https://paycenter.piraeusbank.gr/redirection/pay.aspx
EPAY_REQUEST_TYPE=02          # 02 = Sale, 00 = Preauthorization
EPAY_EXPIRE_PREAUTH=30        # days, only for preauthorizations
EPAY_CURRENCY_CODE=978        # EUR
EPAY_LANGUAGE_CODE=           # el-GR, en-US, ru-RU, de-DE; empty follows the current site
```

Publish the config file with `php artisan vendor:publish --tag=resrv-payment-epay-config` if you prefer to edit it directly.

### Account setup with Euronet Merchant Services

When requesting the account, give them these URLs (replace the domain):

| Setting | Value |
| --- | --- |
| Success URL | `https://example.com/resrv/epay/success` |
| Failure URL | `https://example.com/resrv/epay/failure` |
| Backlink URL | `https://example.com/resrv/epay/cancel` |
| Response method | `POST` (`GET` also works) |
| Referrer URL | Your checkout page |
| IP address | The server IP that calls the ticketing web service |

These routes are excluded from CSRF verification by the add-on, so no changes to `VerifyCsrfToken` are needed.

## Customizing the payment view

```bash
php artisan vendor:publish --tag=resrv-checkout-views-epay
```

## Refunds

Refunds are not supported through the API; issue them from the epay merchant back office.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The epay test cases (manual, section 7) use card `4908455555555557` (sale) with expiry `01/<future year>` for an approved transaction and `02/<future year>` for a declined one, CVV `123`.
