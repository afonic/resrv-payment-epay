<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Reach\ResrvPaymentEpay\Http\Controllers\EpayCallbackController;

Route::name('resrv-epay.')
    ->prefix('resrv/epay')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->group(function () {
        Route::match(['get', 'post'], 'success', [EpayCallbackController::class, 'response'])->name('success');
        Route::match(['get', 'post'], 'failure', [EpayCallbackController::class, 'response'])->name('failure');
        Route::get('cancel', [EpayCallbackController::class, 'cancel'])->name('cancel');
    });
