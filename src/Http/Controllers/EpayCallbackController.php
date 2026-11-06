<?php

namespace Reach\ResrvPaymentEpay\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\ResrvPaymentEpay\Http\Payment\EpayPaymentGateway;
use Reach\ResrvPaymentEpay\Models\EpayTransaction;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;

class EpayCallbackController extends Controller
{
    use HandlesStatamicQueries;

    /**
     * epay posts the result of the payment to the success and failure URLs.
     */
    public function response(Request $request, EpayPaymentGateway $gateway): RedirectResponse
    {
        $transaction = $gateway->verifyPayment($request);

        return $this->redirectToCheckoutCompleted($transaction, $request->input('MerchantReference'));
    }

    /**
     * The customer pressed «Cancel» on the epay payment page.
     */
    public function cancel(Request $request, EpayPaymentGateway $gateway): RedirectResponse
    {
        $transaction = EpayTransaction::where('merchant_reference', (string) $request->input('epay_reference'))->first();

        if ($transaction) {
            $gateway->cancel($transaction);
        }

        return $this->redirectToCheckoutCompleted($transaction, $request->input('epay_reference'));
    }

    protected function redirectToCheckoutCompleted(?EpayTransaction $transaction, mixed $reference): RedirectResponse
    {
        // Build on APP_URL rather than the request host, which a client can set through proxy headers.
        $url = rtrim(config('app.url'), '/').'/'.ltrim($transaction?->return_path ?? $this->getCheckoutCompleteEntry()->url(), '/');
        $separator = str_contains($url, '?') ? '&' : '?';

        return redirect()->away($url.$separator.http_build_query([
            'resrv_gateway' => 'epay',
            'epay_reference' => is_string($reference) ? $reference : '',
        ]), 303);
    }
}
