<?php

namespace Reach\ResrvPaymentEpay;

class EpayHash
{
    /**
     * Calculate the HashKey epay sends with an approved transaction (manual section 5, Annex 4).
     *
     * @param  array<string, mixed>  $response  The parameters epay posted to the success URL.
     */
    public static function calculate(string $ticket, string $posId, string $acquirerId, array $response): string
    {
        $data = implode(';', [
            $ticket,
            $posId,
            $acquirerId,
            $response['MerchantReference'] ?? '',
            $response['ApprovalCode'] ?? '',
            $response['Parameters'] ?? '',
            $response['ResponseCode'] ?? '',
            $response['SupportReferenceID'] ?? '',
            $response['AuthStatus'] ?? '',
            $response['PackageNo'] ?? '',
            $response['StatusFlag'] ?? '',
        ]);

        return strtoupper(hash_hmac('sha256', $data, $ticket));
    }
}
