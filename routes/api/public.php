<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AiSupportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\JntWebhookController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductBrandController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\WebPageController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/register/verify-otp', [AuthController::class, 'verifyRegistrationOtp']);
    Route::post('/register/resend-otp', [AuthController::class, 'resendRegistrationOtp']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetToken']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::post('/payments/checkout-session', [PaymentController::class, 'createCheckoutSession']);
Route::get('/payments/checkout-session/{checkoutId}', [PaymentController::class, 'verifyCheckoutSession']);
Route::post('/payments/validate-voucher', [PaymentController::class, 'validateVoucher']);
Route::post('/payments/webhooks/paymongo', [PaymentController::class, 'handlePaymongoWebhook']);
Route::post('/payments/webhooks/test-paid', [PaymentController::class, 'handleTestPaidWebhook']);
Route::get('/orders/track', [PaymentController::class, 'trackGuestOrder']);
Route::post('/ai-support', [AiSupportController::class, 'handle']);

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products/slug/{slug}', [ProductController::class, 'showBySlug']);
Route::get('/products/{id}/reviews', [ProductController::class, 'reviews']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/product-brands', [ProductBrandController::class, 'publicIndex']);

Route::get('/web-pages/home', [WebPageController::class, 'home']);
Route::get('/web-pages/{type}', [WebPageController::class, 'publicIndex']);

Route::get('/address/regions', [AddressController::class, 'regions']);
Route::get('/address/provinces', [AddressController::class, 'provinces']);
Route::get('/address/cities', [AddressController::class, 'cities']);
Route::get('/address/barangays', [AddressController::class, 'barangays']);

Route::match(['GET', 'POST'], '/jnt/sandbox/logistics-trackback', [JntWebhookController::class, 'sandboxLogisticsTrackback']);
Route::match(['GET', 'POST'], '/jnt/sandbox/order-status', [JntWebhookController::class, 'sandboxOrderStatus']);
Route::match(['GET', 'POST'], '/jnt/webhook/logistics-trackback', [JntWebhookController::class, 'productionLogisticsTrackback']);
Route::match(['GET', 'POST'], '/jnt/webhook/order-status', [JntWebhookController::class, 'productionOrderStatus']);
