<?php

namespace Reach\ResrvPaymentEpay\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Reach\ResrvPaymentEpay\EpayHash;
use Reach\ResrvPaymentEpay\Http\Payment\EpayPaymentGateway;
use Reach\ResrvPaymentEpay\Models\EpayTransaction;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Models\Reservation;

class EpayPaymentGatewayTest extends TestCase
{
    protected const TICKET = '4236ece6142b4639925eb6f80217122f';

    protected function ticketResponse(string $resultCode = '0', string $description = 'No Error', string $ticket = self::TICKET): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">'
            .'<soap:Body><IssueNewTicketResponse xmlns="http://piraeusbank.gr/paycenter/redirection"><IssueNewTicketResult>'
            ."<ResultCode>{$resultCode}</ResultCode><ResultDescription>{$description}</ResultDescription><TranTicket>{$ticket}</TranTicket>"
            .'<Timestamp>2026-11-06T12:00:00+02:00</Timestamp><MinutesToExpiration>30</MinutesToExpiration>'
            .'</IssueNewTicketResult></IssueNewTicketResponse></soap:Body></soap:Envelope>';
    }

    protected function makeReservation(array $attributes = []): Reservation
    {
        return Reservation::factory()->create(array_merge([
            'reference' => 'R7XK2P',
            'payment' => 1234.5,
        ], $attributes));
    }

    protected function startPayment(Reservation $reservation): object
    {
        $intent = app(EpayPaymentGateway::class)->paymentIntent($reservation->payment, $reservation, collect([
            'first_name' => 'Maria',
            'last_name' => 'Papadopoulou',
            'email' => 'maria@example.com',
        ]));

        $reservation->update(['payment_id' => $intent->id, 'payment_gateway' => 'epay']);

        return $intent;
    }

    /**
     * The parameters epay posts to the success URL for an approved transaction.
     */
    protected function approvedResponse(string $reference, string $ticket = self::TICKET): array
    {
        $response = [
            'SupportReferenceID' => '364629',
            'ResultCode' => '0',
            'ResultDescription' => 'No Error',
            'StatusFlag' => 'Success',
            'ResponseCode' => '00',
            'ResponseDescription' => 'APPROVED OR COMPLETED SUCCESSFULLY',
            'LanguageCode' => 'en-US',
            'MerchantReference' => $reference,
            'TransactionDateTime' => '2026-11-06T12:01:00',
            'TransactionId' => '981245',
            'CardType' => '1',
            'PackageNo' => '12',
            'ApprovalCode' => '389700',
            'RetrievalRef' => '389700389700',
            'AuthStatus' => '01',
            'Parameters' => 'R7XK2P',
            'PaymentMethod' => 'Card',
            'TraceID' => 'TRACE-1',
        ];

        return $response + ['HashKey' => EpayHash::calculate($ticket, '99999999', '14', $response)];
    }

    public function test_payment_intent_issues_a_ticket_and_returns_the_epay_form(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();

        $intent = $this->startPayment($reservation);

        Http::assertSent(function (Request $request) use ($intent) {
            $body = $request->body();

            return $request->url() === 'https://epay.test/services/tickets/issuer.asmx'
                && $request->header('SOAPAction')[0] === '"http://piraeusbank.gr/paycenter/redirection/IssueNewTicket"'
                && str_contains($body, '<Username>MERCHANT01</Username><Password>'.md5('secret-password').'</Password>')
                && str_contains($body, '<MerchantId>2137000001</MerchantId><PosId>99999999</PosId><AcquirerId>14</AcquirerId>')
                && str_contains($body, "<MerchantReference>{$intent->id}</MerchantReference><RequestType>02</RequestType><ExpirePreauth>0</ExpirePreauth><Amount>1234.50</Amount><CurrencyCode>978</CurrencyCode><Installments>0</Installments><Bnpl>0</Bnpl><Parameters>R7XK2P</Parameters>")
                && str_contains($body, '<CardholderName>Maria Papadopoulou</CardholderName><Email>maria@example.com</Email><RecurringInd xsi:nil="true" />');
        });

        $this->assertMatchesRegularExpression('/^'.$reservation->id.'-[A-Za-z0-9]{16}$/', $intent->id);

        $form = json_decode($intent->client_secret, true);
        $this->assertSame('https://epay.test/redirection/pay.aspx', $form['redirectUrl']);
        $this->assertSame([
            'AcquirerId' => '14',
            'MerchantId' => '2137000001',
            'PosId' => '99999999',
            'User' => 'MERCHANT01',
            'LanguageCode' => 'en-US',
            'MerchantReference' => $intent->id,
            'ParamBackLink' => 'epay_reference='.$intent->id,
        ], $form['postData']);

        $transaction = EpayTransaction::where('merchant_reference', $intent->id)->first();
        $this->assertSame(self::TICKET, $transaction->transaction_ticket);
        $this->assertSame('pending', $transaction->status);
        $this->assertSame('1234.50', $transaction->amount);
        $this->assertSame('/checkout-completed', $transaction->return_path);
    }

    public function test_preauthorization_sends_the_configured_expiry(): void
    {
        config(['resrv-payment-epay.request_type' => '00', 'resrv-payment-epay.expire_preauth' => 14]);
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);

        $this->startPayment($this->makeReservation());

        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<RequestType>00</RequestType><ExpirePreauth>14</ExpirePreauth>'));
    }

    public function test_a_rejected_ticket_shows_an_error_instead_of_redirecting(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse('1', 'Invalid credentials', ''))]);

        $intent = $this->startPayment($this->makeReservation());

        $this->assertSame(['error' => true], json_decode($intent->client_secret, true));
        $this->assertSame('failed', EpayTransaction::where('merchant_reference', $intent->id)->value('status'));

        $html = view('resrv-payment-epay::livewire.checkout-payment-epay', ['clientSecret' => $intent->client_secret])->render();
        $this->assertStringContainsString('We could not connect to the payment provider', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_the_payment_view_posts_the_form_to_epay(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $intent = $this->startPayment($this->makeReservation());

        $html = view('resrv-payment-epay::livewire.checkout-payment-epay', ['clientSecret' => $intent->client_secret])->render();

        $this->assertStringContainsString('action="https://epay.test/redirection/pay.aspx"', $html);
        $this->assertStringContainsString('name="MerchantReference" value="'.$intent->id.'"', $html);
    }

    public function test_an_approved_response_confirms_the_reservation(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();
        $intent = $this->startPayment($reservation);

        $response = $this->post('/resrv/epay/success', $this->approvedResponse($intent->id));

        $response->assertRedirect('http://localhost/checkout-completed?resrv_gateway=epay&epay_reference='.$intent->id);
        $this->assertSame('confirmed', $reservation->fresh()->status);

        $transaction = EpayTransaction::where('merchant_reference', $intent->id)->first();
        $this->assertSame('success', $transaction->status);
        $this->assertSame('981245', $transaction->response['TransactionId']);

        $this->get($response->headers->get('Location'))->assertOk()->assertSee('status:success');
    }

    public function test_the_redirect_back_includes_the_reservation_customer(): void
    {
        Mail::fake();
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = Reservation::factory()->withCustomer()->create(['payment' => 100]);
        $intent = $this->startPayment($reservation);
        $this->post('/resrv/epay/success', $this->approvedResponse($intent->id));

        request()->merge(['epay_reference' => $intent->id]);
        $result = app(EpayPaymentGateway::class)->handleRedirectBack();

        $this->assertTrue($result['status']);
        $this->assertSame($reservation->customer->email, $result['reservation']['customer']['email']);
    }

    public function test_an_approved_response_with_a_wrong_hash_is_rejected(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();
        $intent = $this->startPayment($reservation);

        $response = $this->post('/resrv/epay/success', $this->approvedResponse($intent->id, 'some-other-ticket'));

        $this->assertSame('pending', $reservation->fresh()->status);
        $this->assertSame('invalid', EpayTransaction::where('merchant_reference', $intent->id)->value('status'));
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('status:failed');
    }

    public function test_a_declined_response_releases_the_hold(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();
        $intent = $this->startPayment($reservation);

        $response = $this->post('/resrv/epay/failure', [
            'SupportReferenceID' => '364630',
            'ResultCode' => '0',
            'StatusFlag' => 'Failure',
            'ResponseCode' => '05',
            'ResponseDescription' => 'DO NOT HONOR',
            'MerchantReference' => $intent->id,
            'HashKey' => '',
        ]);

        $this->assertSame('expired', $reservation->fresh()->status);
        $this->assertSame('failed', EpayTransaction::where('merchant_reference', $intent->id)->value('status'));
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('status:failed');
    }

    public function test_responses_after_the_first_are_ignored(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();
        $intent = $this->startPayment($reservation);

        $this->post('/resrv/epay/success', $this->approvedResponse($intent->id));
        Event::fake([ReservationConfirmed::class]);
        $this->post('/resrv/epay/success', $this->approvedResponse($intent->id));
        $this->post('/resrv/epay/failure', ['ResultCode' => '1', 'MerchantReference' => $intent->id]);

        Event::assertNotDispatched(ReservationConfirmed::class);
        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertSame('success', EpayTransaction::where('merchant_reference', $intent->id)->value('status'));
    }

    public function test_the_response_can_arrive_with_the_get_method(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();
        $intent = $this->startPayment($reservation);

        $this->get('/resrv/epay/success?'.http_build_query($this->approvedResponse($intent->id)))->assertRedirect();

        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    public function test_cancelling_on_the_payment_page_releases_the_hold(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();
        $intent = $this->startPayment($reservation);

        $response = $this->get('/resrv/epay/cancel?epay_reference='.$intent->id);

        $response->assertRedirect('http://localhost/checkout-completed?resrv_gateway=epay&epay_reference='.$intent->id);
        $this->assertSame('expired', $reservation->fresh()->status);
        $this->assertSame('cancelled', EpayTransaction::where('merchant_reference', $intent->id)->value('status'));
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('status:failed');
    }

    public function test_an_approved_replaced_ticket_does_not_confirm_the_reservation(): void
    {
        Log::spy();
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();
        $abandoned = $this->startPayment($reservation);
        $reservation->update(['payment' => 900]);
        $current = $this->startPayment($reservation->fresh());

        $this->post('/resrv/epay/success', $this->approvedResponse($abandoned->id));

        $this->assertSame('pending', $reservation->fresh()->status);
        $this->assertSame($current->id, $reservation->fresh()->payment_id);
        $this->assertSame('stale', EpayTransaction::where('merchant_reference', $abandoned->id)->value('status'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'refund it from the epay back office'))->once();
    }

    public function test_an_approval_for_an_expired_reservation_does_not_confirm_it(): void
    {
        Log::spy();
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();
        $intent = $this->startPayment($reservation);
        Reservation::where('id', $reservation->id)->update(['status' => 'expired']);

        $response = $this->post('/resrv/epay/success', $this->approvedResponse($intent->id));

        $this->assertSame('expired', $reservation->fresh()->status);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'refund it from the epay back office'))->once();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('status:failed');
    }

    public function test_a_forged_response_does_not_block_the_signed_approval(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();
        $intent = $this->startPayment($reservation);

        $this->post('/resrv/epay/success', $this->approvedResponse($intent->id, 'some-other-ticket'));
        $this->post('/resrv/epay/success', $this->approvedResponse($intent->id));

        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertSame('success', EpayTransaction::where('merchant_reference', $intent->id)->value('status'));
    }

    public function test_the_return_redirect_ignores_a_forged_request_host(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();
        request()->headers->set('host', 'evil.example');
        $intent = $this->startPayment($reservation);

        $this->get('/resrv/epay/cancel?epay_reference='.$intent->id)
            ->assertRedirect('http://localhost/checkout-completed?resrv_gateway=epay&epay_reference='.$intent->id);
    }

    public function test_an_unfinished_failure_keeps_the_hold_for_a_later_approval(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();
        $intent = $this->startPayment($reservation);

        $this->post('/resrv/epay/failure', ['ResultCode' => '1045', 'ResultDescription' => 'Duplicate transaction - currently processed', 'MerchantReference' => $intent->id]);
        $this->assertSame('pending', $reservation->fresh()->status);

        $this->post('/resrv/epay/success', $this->approvedResponse($intent->id));

        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertSame('success', EpayTransaction::where('merchant_reference', $intent->id)->value('status'));
    }

    public function test_the_cardholder_name_is_sent_in_the_characters_epay_accepts(): void
    {
        Http::fake(['epay.test/*' => Http::response($this->ticketResponse())]);
        $reservation = $this->makeReservation();

        app(EpayPaymentGateway::class)->paymentIntent($reservation->payment, $reservation, collect([
            'first_name' => ' Γιάννης ',
            'last_name' => "O'Connor <b>",
            'email' => 'giannis@example.com',
        ]));

        Http::assertSent(fn (Request $request) => str_contains($request->body(), '<CardholderName>Giannis OConnor b</CardholderName>'));
    }

    public function test_a_response_for_an_unknown_reference_shows_a_failed_payment(): void
    {
        $response = $this->post('/resrv/epay/success', ['ResultCode' => '0', 'StatusFlag' => 'Success', 'MerchantReference' => 'nope']);

        $response->assertRedirect('http://localhost/checkout-completed?resrv_gateway=epay&epay_reference=nope');
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('status:failed');
    }
}
