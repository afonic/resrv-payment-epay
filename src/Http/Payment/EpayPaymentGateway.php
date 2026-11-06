<?php

namespace Reach\ResrvPaymentEpay\Http\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Reach\ResrvPaymentEpay\EpayHash;
use Reach\ResrvPaymentEpay\EpayTicketingClient;
use Reach\ResrvPaymentEpay\Models\EpayTransaction;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Facades\Site;

class EpayPaymentGateway implements PaymentInterface
{
    use HandlesStatamicQueries;

    public function __construct(protected EpayTicketingClient $client) {}

    public function name(): string
    {
        return 'epay';
    }

    public function label(): string
    {
        return 'Credit / Debit Card';
    }

    public function paymentView(): string
    {
        return 'resrv-payment-epay::livewire.checkout-payment-epay';
    }

    public function supportsManualConfirmation(): bool
    {
        return false;
    }

    /**
     * Issue an epay ticket and return the form the customer posts to the epay payment page.
     *
     * When the ticket cannot be issued the client secret carries an error instead,
     * so the payment view can tell the customer without breaking the checkout.
     */
    public function paymentIntent($payment, Reservation $reservation, $data)
    {
        $reference = $reservation->id.'-'.Str::random(16);
        $amount = $payment->format();
        $requestType = (string) config('resrv-payment-epay.request_type');

        $transaction = EpayTransaction::create([
            'reservation_id' => $reservation->id,
            'merchant_reference' => $reference,
            'amount' => $amount,
            'return_path' => $this->getCheckoutCompleteEntry()->url(),
        ]);

        $paymentIntent = new \stdClass;
        $paymentIntent->id = $reference;

        try {
            $ticket = $this->client->issueTicket(array_filter([
                'MerchantReference' => $reference,
                'RequestType' => $requestType,
                'ExpirePreauth' => $requestType === '00' ? (int) config('resrv-payment-epay.expire_preauth') : 0,
                'Amount' => $amount,
                'CurrencyCode' => config('resrv-payment-epay.currency_code'),
                'Installments' => 0,
                'Bnpl' => 0,
                'Parameters' => (string) $reservation->reference,
                'CardholderName' => $this->cardholderName($data->get('first_name').' '.$data->get('last_name')),
                'Email' => $data->get('email'),
            ], fn ($value) => $value !== null && $value !== ''));
        } catch (\RuntimeException $e) {
            Log::error('epay ticket could not be issued', [
                'reservation_id' => $reservation->id,
                'merchant_reference' => $reference,
                'error' => $e->getMessage(),
            ]);
            $transaction->update(['status' => 'failed']);
            $paymentIntent->client_secret = json_encode(['error' => true]);

            return $paymentIntent;
        }

        $transaction->update(['transaction_ticket' => $ticket]);

        $paymentIntent->client_secret = json_encode([
            'redirectUrl' => config('resrv-payment-epay.payment_url'),
            'postData' => [
                'AcquirerId' => config('resrv-payment-epay.acquirer_id'),
                'MerchantId' => config('resrv-payment-epay.merchant_id'),
                'PosId' => config('resrv-payment-epay.pos_id'),
                'User' => config('resrv-payment-epay.username'),
                'LanguageCode' => $this->languageCode(),
                'MerchantReference' => $reference,
                'ParamBackLink' => 'epay_reference='.$reference,
            ],
        ]);

        return $paymentIntent;
    }

    /**
     * epay accepts 2 to 45 Latin characters without "';<>; Greek names are transliterated.
     */
    protected function cardholderName(string $name): ?string
    {
        $name = Str::limit(Str::squish(preg_replace('/["\';<>]/', '', Str::ascii($name))), 45, '');

        return strlen($name) >= 2 ? $name : null;
    }

    protected function languageCode(): string
    {
        if ($language = config('resrv-payment-epay.language')) {
            return $language;
        }

        return match (Site::current()->lang()) {
            'el', 'gr' => 'el-GR',
            'ru' => 'ru-RU',
            'de' => 'de-DE',
            default => 'en-US',
        };
    }

    public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
    {
        // A ticket expires on its own after 30 minutes and cannot be revoked.
    }

    public function refund($reservation)
    {
        throw new RefundFailedException('Refunds must be issued from the epay merchant back office.');
    }

    public function supportsAutomaticRefunds(): bool
    {
        return false;
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function redirectsForPayment(): bool
    {
        return false;
    }

    public function getPublicKey(Reservation $reservation)
    {
        return '';
    }

    public function getSecretKey(Reservation $reservation) {}

    public function getWebhookSecret(Reservation $reservation) {}

    /**
     * Process the response epay sends to the success or failure URL.
     *
     * Only an approved response with a valid HashKey for the reservation's current payment
     * confirms it. An issuer decline (ResultCode 0, StatusFlag Failure) releases the hold, since
     * epay needs a new ticket to retry; other failures, such as ResultCode 1045 (the reference is
     * still being processed) or communication errors, leave the reservation pending because a
     * signed approval can still follow. Responses for one transaction are processed one at a
     * time, and once it succeeded later responses are ignored.
     */
    public function verifyPayment($request): ?EpayTransaction
    {
        $response = $request->except('resrv_gateway');
        $transaction = EpayTransaction::where('merchant_reference', (string) $request->input('MerchantReference'))->first();

        if (! $transaction) {
            Log::warning('epay response for unknown merchant reference', $response);

            return null;
        }

        return DB::transaction(fn () => $this->processResponse(
            EpayTransaction::lockForUpdate()->find($transaction->id),
            $request,
            $response,
        ));
    }

    protected function processResponse(EpayTransaction $transaction, Request $request, array $response): EpayTransaction
    {
        $approved = $request->input('ResultCode') === '0' && $request->input('StatusFlag') === 'Success';

        if ($transaction->status === 'success' || ($transaction->status !== 'pending' && ! $approved)) {
            return $transaction;
        }

        if ($approved && ! $this->hashIsValid($transaction, $request)) {
            Log::error('epay HashKey verification failed', $response);
            $transaction->update(['status' => 'invalid', 'response' => $response]);

            return $transaction;
        }

        $reservation = Reservation::lockForUpdate()->find($transaction->reservation_id);
        $isCurrentPayment = $reservation && $reservation->payment_id === $transaction->merchant_reference;

        if ($approved && ! $isCurrentPayment) {
            $transaction->update(['status' => 'stale', 'response' => $response]);
            $this->logUnconfirmedPayment('epay payment approved for a replaced or abandoned payment attempt', $transaction);

            return $transaction;
        }

        $transaction->update(['status' => $approved ? 'success' : 'failed', 'response' => $response]);

        Log::info('epay response received', [
            'merchant_reference' => $transaction->merchant_reference,
            'status' => $transaction->status,
            'result_code' => $request->input('ResultCode'),
            'response_code' => $request->input('ResponseCode'),
            'support_reference_id' => $request->input('SupportReferenceID'),
        ]);

        $issuerDeclined = $request->input('ResultCode') === '0' && $request->input('StatusFlag') === 'Failure';

        if ($approved) {
            $this->confirm($reservation, $transaction);
        } elseif ($issuerDeclined && $isCurrentPayment) {
            $this->releaseHold($reservation);
        }

        return $transaction;
    }

    /**
     * The customer pressed «Cancel» on the epay payment page.
     */
    public function cancel(EpayTransaction $transaction): void
    {
        if ($transaction->status !== 'pending') {
            return;
        }

        $transaction->update(['status' => 'cancelled']);

        if ($transaction->reservation?->payment_id === $transaction->merchant_reference) {
            $this->releaseHold($transaction->reservation);
        }
    }

    /**
     * Expire a pending reservation so its availability is released.
     *
     * The customer cannot resume the checkout after the checkout completed page, so the hold
     * would otherwise block the dates until minutes_to_hold passes.
     */
    protected function releaseHold(Reservation $reservation): void
    {
        // Older resrv versions take the reservation id, newer ones expire the instance.
        $reservation->expire($reservation->id);
    }

    protected function confirm(Reservation $reservation, EpayTransaction $transaction): void
    {
        // Resrv versions without state transitions confirm through the ConfirmReservation listener,
        // which sets the status whatever it was, so only a pending reservation may be confirmed.
        $confirmed = method_exists($reservation, 'transitionTo')
            ? $reservation->transitionTo(ReservationStatus::CONFIRMED, tolerant: true)
            : $reservation->status === ReservationStatus::PENDING->value;

        if ($confirmed) {
            ReservationConfirmed::dispatch($reservation);

            return;
        }

        if ($reservation->refresh()->status !== ReservationStatus::CONFIRMED->value) {
            $this->logUnconfirmedPayment('epay payment approved for a reservation that can no longer be confirmed', $transaction);
        }
    }

    protected function logUnconfirmedPayment(string $message, EpayTransaction $transaction): void
    {
        Log::warning($message.', refund it from the epay back office.', [
            'reservation_id' => $transaction->reservation_id,
            'reservation_status' => $transaction->reservation?->status,
            'merchant_reference' => $transaction->merchant_reference,
            'amount' => $transaction->amount,
        ]);
    }

    protected function hashIsValid(EpayTransaction $transaction, Request $request): bool
    {
        if (! $transaction->transaction_ticket || ! is_string($request->input('HashKey'))) {
            return false;
        }

        $expected = EpayHash::calculate(
            $transaction->transaction_ticket,
            (string) config('resrv-payment-epay.pos_id'),
            (string) config('resrv-payment-epay.acquirer_id'),
            $request->all(),
        );

        return hash_equals($expected, strtoupper($request->input('HashKey')));
    }

    public function handleRedirectBack(): array
    {
        if ($pending = $this->handlePaymentPending()) {
            return $pending;
        }

        $transaction = EpayTransaction::where('merchant_reference', (string) request()->input('epay_reference'))->first();
        $reservation = $transaction?->reservation?->load('customer');

        return [
            'status' => $transaction?->status === 'success' && $reservation?->status === ReservationStatus::CONFIRMED->value,
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
    }

    public function handlePaymentPending(): bool|array
    {
        return false;
    }

    public function verifyWebhook()
    {
        return false;
    }
}
