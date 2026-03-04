<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\EncashmentController;
use App\Http\Controllers\Api\AdminEncashmentController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminMemberKycController;
use App\Http\Controllers\Api\CustomerNotificationController;


// Public auth routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
});

Route::post('/payments/checkout-session', [PaymentController::class, 'createCheckoutSession']);
Route::get('/payments/checkout-session/{checkoutId}', [PaymentController::class, 'verifyCheckoutSession']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products/slug/{slug}', [ProductController::class, 'showBySlug']);
Route::get('/products', [ProductController::class, 'index']);


// Protected routes (requires Sanctum token)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);
    Route::put('/auth/me',      [AuthController::class, 'updateMe']);
    Route::get('/admin/members', [MemberController::class, 'index']);
    Route::get('/admin/members/stats', [MemberController::class, 'stats']);
    Route::get('/admin/products', [ProductController::class, 'index']);
    Route::post('/admin/products', [ProductController::class, 'store']);
    Route::put('/admin/products/{id}', [ProductController::class, 'update']);
    Route::delete('/admin/products/{id}', [ProductController::class, 'destroy']);
    Route::get('/admin/categories', [CategoryController::class, 'index']);
    Route::post('/admin/categories', [CategoryController::class, 'store']);
    Route::put('/admin/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/admin/categories/{id}', [CategoryController::class, 'destroy']);
    Route::get('/orders/history', [PaymentController::class, 'checkoutHistory']);
    Route::post('/encashment/requests', [EncashmentController::class, 'store']);
    Route::get('/encashment/requests', [EncashmentController::class, 'myRequests']);
    Route::post('/encashment/payout-methods', [EncashmentController::class, 'storePayoutMethod']);
    Route::delete('/encashment/payout-methods/{id}', [EncashmentController::class, 'destroyPayoutMethod']);
    Route::get('/encashment/wallet', [EncashmentController::class, 'walletOverview']);
    Route::post('/encashment/verification-request', [EncashmentController::class, 'submitVerificationRequest']);
    Route::get('/notifications/customer', [CustomerNotificationController::class, 'index']);
    Route::get('/admin/orders', [AdminOrderController::class, 'index']);
    Route::get('/admin/orders/notifications', [AdminOrderController::class, 'notifications']);
    Route::patch('/admin/orders/{id}/approve', [AdminOrderController::class, 'approve']);
    Route::patch('/admin/orders/{id}/reject', [AdminOrderController::class, 'reject']);
    Route::patch('/admin/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
    Route::get('/admin/encashment', [AdminEncashmentController::class, 'index']);
    Route::patch('/admin/encashment/{id}/approve', [AdminEncashmentController::class, 'approve']);
    Route::patch('/admin/encashment/{id}/reject', [AdminEncashmentController::class, 'reject']);
    Route::patch('/admin/encashment/{id}/release', [AdminEncashmentController::class, 'release']);
    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::post('/admin/users', [AdminUserController::class, 'store']);
    Route::put('/admin/users/{id}', [AdminUserController::class, 'update']);
    Route::delete('/admin/users/{id}', [AdminUserController::class, 'destroy']);
    Route::get('/admin/members/kyc', [AdminMemberKycController::class, 'index']);
    Route::patch('/admin/members/kyc/{id}/approve', [AdminMemberKycController::class, 'approve']);
    Route::patch('/admin/members/kyc/{id}/reject', [AdminMemberKycController::class, 'reject']);
});

Route::prefix('admin/auth')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->prefix('admin/auth')->group(function () {
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::get('/me', [AdminAuthController::class, 'me']);
});
