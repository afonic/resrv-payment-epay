@php($payment = json_decode($clientSecret))
<div>
    @if ($payment->error ?? false)
        <div class="my-6 xl:my-8 text-center">
            <div class="text-lg xl:text-xl font-medium mb-2">
                {{ __('Payment failed') }}
            </div>
            <div class="text-gray-700">
                {{ __('We could not connect to the payment provider. Please try again in a few minutes or contact us.') }}
            </div>
        </div>
    @else
        <div x-data="{ submitted: false }" x-init="!submitted && $refs.form.submit(); submitted = true">
            <div class="my-6 xl:my-8 text-center">
                <div class="text-lg xl:text-xl font-medium mb-2">
                    {{ trans('statamic-resrv::frontend.redirectingToPayment') }}
                </div>
                <div class="text-gray-700">
                    {{ trans('statamic-resrv::frontend.pleaseWait') }}
                </div>
            </div>
            <form x-ref="form" method="POST" action="{{ $payment->redirectUrl }}">
                @foreach ($payment->postData as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
            </form>
        </div>
    @endif
</div>
