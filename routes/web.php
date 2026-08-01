<?php

use App\Http\Controllers\Shop\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/payment/complete/{reference}', [PaymentController::class, 'complete'])->name('payment.complete');
