<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerAddressController;
use App\Http\Controllers\Api\CustomerNotificationController;
use App\Http\Controllers\Api\EncashmentController;
use App\Http\Controllers\Api\InteriorRequestController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'customer.actor'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/referral-tree', [AuthController::class, 'referralTree']);
    Route::put('/auth/me', [AuthController::class, 'updateMe']);
    Route::patch('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::post('/auth/username-change/send-otp', [AuthController::class, 'sendUsernameChangeOtp']);
    Route::post('/auth/username-change/submit', [AuthController::class, 'submitUsernameChangeRequest']);
    Route::get('/auth/username-change/latest', [AuthController::class, 'latestUsernameChangeRequest']);

    Route::get('/auth/addresses', [CustomerAddressController::class, 'index']);
    Route::post('/auth/addresses', [CustomerAddressController::class, 'store']);
    Route::patch('/auth/addresses/{id}/default', [CustomerAddressController::class, 'setDefault']);

    Route::get('/orders/history', [PaymentController::class, 'checkoutHistory']);
    Route::post('/orders/{id}/confirm', [PaymentController::class, 'confirmOrder']);

    Route::post('/encashment/requests', [EncashmentController::class, 'store']);
    Route::get('/encashment/requests', [EncashmentController::class, 'myRequests']);
    Route::post('/encashment/payout-methods', [EncashmentController::class, 'storePayoutMethod']);
    Route::delete('/encashment/payout-methods/{id}', [EncashmentController::class, 'destroyPayoutMethod']);
    Route::get('/encashment/wallet', [EncashmentController::class, 'walletOverview']);
    Route::post('/encashment/vouchers', [EncashmentController::class, 'createAffiliateVoucher']);
    Route::post('/encashment/verification-request', [EncashmentController::class, 'submitVerificationRequest']);

    Route::get('/notifications/customer', [CustomerNotificationController::class, 'index']);

    Route::post('/interior-requests', [InteriorRequestController::class, 'store']);
    Route::get('/interior-requests', [InteriorRequestController::class, 'myRequests']);
    Route::get('/interior-requests/{id}', [InteriorRequestController::class, 'show']);

    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{productId}', [WishlistController::class, 'destroy']);
});
