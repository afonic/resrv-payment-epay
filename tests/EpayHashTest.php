<?php

namespace Reach\ResrvPaymentEpay\Tests;

use PHPUnit\Framework\TestCase;
use Reach\ResrvPaymentEpay\EpayHash;

class EpayHashTest extends TestCase
{
    public function test_matches_the_example_in_annex_4_of_the_epay_manual(): void
    {
        $hash = EpayHash::calculate('4236ece6142b4639925eb6f80217122f', '99999999', '14', [
            'MerchantReference' => 'Test',
            'ApprovalCode' => '389700',
            'Parameters' => 'MyParam',
            'ResponseCode' => '00',
            'SupportReferenceID' => '364629',
            'AuthStatus' => '02',
            'PackageNo' => '1',
            'StatusFlag' => 'Success',
        ]);

        $this->assertSame('551F158E669965F30BCFA65E558FD4AABB191D394DE39BE2ADFAB416575102D7', $hash);
    }
}
