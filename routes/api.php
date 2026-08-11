<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\DeviceController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Catalog\BundleController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\DeliveryZoneController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\FavoriteController;
use App\Http\Controllers\Shop\HotelController;
use App\Http\Controllers\Shop\OrderController;
use App\Http\Controllers\Shop\PaymentController;
use App\Http\Controllers\Shop\VetController;
use App\Http\Controllers\Shop\WalletController;
use App\Http\Controllers\Support\SupportController;
use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/verify-registration', [AuthController::class, 'verifyRegistration']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    // بدون auth:sanctum — ERPNext مش مستخدم موبايل، الحماية عن طريق توقيع HMAC بس
    Route::post('/webhooks/erp', [WebhookController::class, 'handleErp']);

    // بدون auth:sanctum — TLYNC برضو مش مستخدم موبايل. ⚠️ بدون توقيع (TLYNC ما يوفروش
    // HMAC)، فبنتحقق من الدفع فعليًا عبر استدعاء TLYNC نفسه جوّه الكنترولر، مش من محتوى الطلب هنا.
    Route::post('/webhooks/tlync', [PaymentController::class, 'handleTlyncWebhook']);

    // بدون auth:sanctum — مهمة يومية من ERPNext (send_appointment_reminders)، محمية بنفس توقيع HMAC
    Route::post('/webhooks/appointment-reminder', [WebhookController::class, 'handleAppointmentReminder']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/register-device', [DeviceController::class, 'register']);

        Route::get('/user', function (Request $request) {
            return $request->user();
        });
        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'changePassword']);

        Route::get('/categories', [CategoryController::class, 'index']);
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/featured', [ProductController::class, 'featured']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::get('/bundles', [BundleController::class, 'index']);
        Route::get('/bundles/{bundle}', [BundleController::class, 'show']);
        Route::get('/delivery-zones', [DeliveryZoneController::class, 'index']);

        Route::get('/favorites', [FavoriteController::class, 'index']);
        Route::post('/favorites', [FavoriteController::class, 'store']);
        Route::delete('/favorites/{product}', [FavoriteController::class, 'destroy']);

        Route::get('/cart', [CartController::class, 'index']);
        Route::post('/cart/items', [CartController::class, 'store']);
        Route::patch('/cart/items/{itemCode}', [CartController::class, 'update']);
        Route::delete('/cart/items/{itemCode}', [CartController::class, 'destroy']);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);

        Route::post('/wallet/topup', [PaymentController::class, 'topUp']);
        Route::get('/wallet/balance', [WalletController::class, 'balance']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);

        Route::get('/hotel/bookings', [HotelController::class, 'index']);
        Route::post('/hotel/bookings', [HotelController::class, 'store']);

        Route::get('/vet/appointments', [VetController::class, 'index']);
        Route::post('/vet/appointments', [VetController::class, 'store']);

        Route::get('/support/tickets', [SupportController::class, 'index']);
        Route::post('/support/tickets', [SupportController::class, 'store']);
        Route::get('/support/tickets/{ticket}', [SupportController::class, 'show']);
        Route::get('/support/contact-phone', [SupportController::class, 'contactPhone']);
    });
});
