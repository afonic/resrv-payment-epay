<?php

namespace Reach\ResrvPaymentEpay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Calls the epay ticketing web service (IssueNewTicket).
 *
 * The SOAP envelope is posted over plain HTTP so the addon does not depend on ext-soap.
 */
class EpayTicketingClient
{
    public const SOAP_ACTION = 'http://piraeusbank.gr/paycenter/redirection/IssueNewTicket';

    /**
     * TicketRequest elements in the order the WSDL sequence defines them.
     * Nillable elements with minOccurs=1 are sent as xsi:nil when empty.
     */
    protected const FIELDS = [
        'Username', 'Password', 'MerchantId', 'PosId', 'AcquirerId', 'MerchantReference', 'RequestType',
        'ExpirePreauth', 'Amount', 'CurrencyCode', 'Installments', 'Bnpl', 'Parameters',
        'BillAddrCity', 'BillAddrCountry', 'BillAddrLine1', 'BillAddrLine2', 'BillAddrLine3', 'BillAddrPostCode', 'BillAddrState',
        'ShipAddrCity', 'ShipAddrCountry', 'ShipAddrLine1', 'ShipAddrLine2', 'ShipAddrLine3', 'ShipAddrPostCode', 'ShipAddrState',
        'CardholderName', 'Email', 'HomePhone', 'MobilePhone', 'WorkPhone',
        'RecurringInd', 'RecurPurchaseDate', 'RecurFreq', 'RecurEnd', 'AddressMatch', 'DeliveryTimeframe',
        'DeliveryEmailAddress', 'ReorderItemsInd', 'PreOrderPurchaseInd', 'AuthMethod', 'AccountAgeInd', 'AccountDate',
        'AccountChangeInd', 'AccountChange', 'AccountPwdChangeInd', 'AccountPwdChange', 'ShipAddressUsageInd', 'SuspiciousAccActivity',
    ];

    protected const NILLABLE = [
        'RecurringInd', 'RecurFreq', 'AddressMatch', 'DeliveryTimeframe', 'ReorderItemsInd', 'PreOrderPurchaseInd',
        'AuthMethod', 'AccountAgeInd', 'AccountChangeInd', 'AccountPwdChangeInd', 'ShipAddressUsageInd', 'SuspiciousAccActivity',
    ];

    /**
     * Issue a ticket for a transaction and return the TranTicket value.
     *
     * @param  array<string, string|int|float|null>  $transaction  TicketRequest fields other than the credentials.
     *
     * @throws RuntimeException when the web service is unreachable or rejects the request.
     */
    public function issueTicket(array $transaction): string
    {
        $request = array_merge($transaction, [
            'Username' => config('resrv-payment-epay.username'),
            'Password' => md5((string) config('resrv-payment-epay.password')),
            'MerchantId' => config('resrv-payment-epay.merchant_id'),
            'PosId' => config('resrv-payment-epay.pos_id'),
            'AcquirerId' => config('resrv-payment-epay.acquirer_id'),
        ]);

        try {
            $response = Http::withHeaders(['SOAPAction' => '"'.self::SOAP_ACTION.'"'])
                ->withBody($this->envelope($request), 'text/xml; charset=utf-8')
                ->timeout(30)
                ->post(config('resrv-payment-epay.ticketing_url'));
        } catch (ConnectionException $e) {
            throw new RuntimeException('epay ticketing service unreachable: '.$e->getMessage(), previous: $e);
        }

        $result = $this->parseResult($response->body());

        if ($result === null) {
            throw new RuntimeException('epay ticketing service returned an unexpected response (HTTP '.$response->status().'): '.mb_substr($response->body(), 0, 500));
        }

        Log::info('epay ticket response', [
            'merchant_reference' => $request['MerchantReference'] ?? null,
            'result_code' => $result['ResultCode'],
            'result_description' => $result['ResultDescription'],
        ]);

        if ($result['ResultCode'] !== '0' || $result['TranTicket'] === '') {
            throw new RuntimeException("epay ticket rejected ({$result['ResultCode']}): {$result['ResultDescription']}");
        }

        return $result['TranTicket'];
    }

    protected function envelope(array $request): string
    {
        $elements = '';

        foreach (self::FIELDS as $field) {
            $value = $request[$field] ?? null;

            if ($value === null || $value === '') {
                if (in_array($field, self::NILLABLE, true)) {
                    $elements .= "<{$field} xsi:nil=\"true\" />";
                }

                continue;
            }

            $elements .= "<{$field}>".htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8')."</{$field}>";
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<soap:Body><IssueNewTicket xmlns="http://piraeusbank.gr/paycenter/redirection"><Request>'
            .$elements
            .'</Request></IssueNewTicket></soap:Body></soap:Envelope>';
    }

    /**
     * @return array{ResultCode: string, ResultDescription: string, TranTicket: string}|null
     */
    protected function parseResult(string $body): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return null;
        }

        $xml->registerXPathNamespace('epay', 'http://piraeusbank.gr/paycenter/redirection');
        $result = $xml->xpath('//epay:IssueNewTicketResult')[0] ?? null;

        if ($result === null) {
            return null;
        }

        $result = $result->children('http://piraeusbank.gr/paycenter/redirection');

        return [
            'ResultCode' => trim((string) $result->ResultCode),
            'ResultDescription' => trim((string) $result->ResultDescription),
            'TranTicket' => trim((string) $result->TranTicket),
        ];
    }
}
