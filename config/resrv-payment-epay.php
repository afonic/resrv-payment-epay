<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Merchant credentials
    |--------------------------------------------------------------------------
    |
    | Provided by Euronet Merchant Services when the account is created.
    |
    */
    'acquirer_id' => env('EPAY_ACQUIRER_ID'),
    'merchant_id' => env('EPAY_MERCHANT_ID'),
    'pos_id' => env('EPAY_POS_ID'),
    'username' => env('EPAY_USERNAME'),
    'password' => env('EPAY_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    |
    | The ticketing web service (SOAP) and the payment page the customer is
    | redirected to. epay uses the same URLs for test and live accounts.
    |
    */
    'ticketing_url' => env('EPAY_TICKETING_URL', 'https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx'),
    'payment_url' => env('EPAY_PAYMENT_URL', 'https://paycenter.piraeusbank.gr/redirection/pay.aspx'),

    /*
    |--------------------------------------------------------------------------
    | Transaction settings
    |--------------------------------------------------------------------------
    |
    | request_type: 02 = Sale, 00 = Preauthorization.
    | expire_preauth: days a preauthorization can be settled (2-30, only used for 00).
    | currency_code: ISO 4217 numeric code, 978 = EUR.
    | language: el-GR, en-US, ru-RU or de-DE. Leave empty to follow the current site.
    |
    */
    'request_type' => env('EPAY_REQUEST_TYPE', '02'),
    'expire_preauth' => env('EPAY_EXPIRE_PREAUTH', 30),
    'currency_code' => env('EPAY_CURRENCY_CODE', 978),
    'language' => env('EPAY_LANGUAGE_CODE'),
];
