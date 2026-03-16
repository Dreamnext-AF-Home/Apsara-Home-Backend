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
use App\Http\Controllers\Api\WishlistController;
use App\Http\Controllers\Api\WebPageController;
use App\Http\Controllers\Api\XdeShippingController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierAuthController;
use App\Http\Controllers\Api\SupplierUserController;
use App\Http\Controllers\Api\CustomerAddressController;


// Public auth routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/register/verify-otp', [AuthController::class, 'verifyRegistrationOtp']);
    Route::post('/register/resend-otp', [AuthController::class, 'resendRegistrationOtp']);
    Route::post('/login',    [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetToken']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::post('/payments/checkout-session', [PaymentController::class, 'createCheckoutSession']);
Route::get('/payments/checkout-session/{checkoutId}', [PaymentController::class, 'verifyCheckoutSession']);
Route::post('/payments/webhooks/paymongo', [PaymentController::class, 'handlePaymongoWebhook']);
Route::post('/payments/webhooks/test-paid', [PaymentController::class, 'handleTestPaidWebhook']);
Route::get('/orders/track', [PaymentController::class, 'trackGuestOrder']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products/slug/{slug}', [ProductController::class, 'showBySlug']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/web-pages/home', [WebPageController::class, 'home']);
Route::get('/web-pages/{type}', [WebPageController::class, 'publicIndex']);
Route::get('/address/regions', [AddressController::class, 'regions']);
Route::get('/address/provinces', [AddressController::class, 'provinces']);
Route::get('/address/cities', [AddressController::class, 'cities']);
Route::get('/address/barangays', [AddressController::class, 'barangays']);


// Protected routes (requires Sanctum token)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);
    Route::get('/auth/referral-tree', [AuthController::class, 'referralTree']);
    Route::put('/auth/me',      [AuthController::class, 'updateMe']);
    Route::get('/auth/addresses', [CustomerAddressController::class, 'index']);
    Route::post('/auth/addresses', [CustomerAddressController::class, 'store']);
    Route::patch('/auth/addresses/{id}/default', [CustomerAddressController::class, 'setDefault']);
    Route::get('/admin/members', [MemberController::class, 'index']);
    Route::get('/admin/members/stats', [MemberController::class, 'stats']);
    Route::get('/admin/products', [ProductController::class, 'index']);
    Route::post('/admin/products', [ProductController::class, 'store']);
    Route::put('/admin/products/{id}', [ProductController::class, 'update']);
    Route::delete('/admin/products/{id}', [ProductController::class, 'destroy']);
    Route::get('/admin/categories', [CategoryController::class, 'index']);
    Route::get('/admin/suppliers', [SupplierController::class, 'index']);
    Route::post('/admin/suppliers', [SupplierController::class, 'store']);
    Route::put('/admin/suppliers/{id}', [SupplierController::class, 'update']);
    Route::delete('/admin/suppliers/{id}', [SupplierController::class, 'destroy']);
    Route::post('/admin/supplier-users', [SupplierUserController::class, 'store']);
    Route::post('/admin/categories', [CategoryController::class, 'store']);
    Route::put('/admin/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/admin/categories/{id}', [CategoryController::class, 'destroy']);
    Route::get('/orders/history', [PaymentController::class, 'checkoutHistory']);
    Route::post('/encashment/requests', [EncashmentController::class, 'store']);
    Route::get('/encashment/requests', [EncashmentController::class, 'myRequests']);
    Route::post('/encashment/payout-methods', [EncashmentController::class, 'storePayoutMethod']);
    Route::delete('/encashment/payout-methods/{id}', [EncashmentController::class, 'destroyPayoutMethod']);
    Route::get('/encashment/wallet', [EncashmentController::class, 'walletOverview']);
    Route::post('/encashment/vouchers', [EncashmentController::class, 'createAffiliateVoucher']);
    Route::post('/encashment/verification-request', [EncashmentController::class, 'submitVerificationRequest']);
    Route::get('/notifications/customer', [CustomerNotificationController::class, 'index']);
    Route::get('/admin/orders', [AdminOrderController::class, 'index']);
    Route::get('/admin/orders/notifications', [AdminOrderController::class, 'notifications']);
    Route::post('/admin/orders/notifications/read-all', [AdminOrderController::class, 'markAllNotificationsRead']);
    Route::post('/admin/orders/notifications/{id}/read', [AdminOrderController::class, 'markNotificationRead']);
    Route::post('/admin/realtime/pusher/auth', [AdminOrderController::class, 'pusherAuth']);
    Route::patch('/admin/orders/{id}/approve', [AdminOrderController::class, 'approve']);
    Route::patch('/admin/orders/{id}/reject', [AdminOrderController::class, 'reject']);
    Route::patch('/admin/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
    Route::patch('/admin/orders/{id}/shipment-status', [AdminOrderController::class, 'updateShipmentStatus']);
    Route::post('/admin/orders/{id}/shipping/xde/book', [XdeShippingController::class, 'bookForOrder']);
    Route::get('/admin/orders/{id}/shipping/xde/track', [XdeShippingController::class, 'trackByOrder']);
    Route::get('/admin/shipping/xde/track/{trackingNo}', [XdeShippingController::class, 'trackByTrackingNo']);
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
    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{productId}', [WishlistController::class, 'destroy']);
    Route::get('/admin/web-pages/{type}', [WebPageController::class, 'adminIndex']);
    Route::post('/admin/web-pages/{type}', [WebPageController::class, 'adminStore']);
    Route::put('/admin/web-pages/{type}/{id}', [WebPageController::class, 'adminUpdate']);
    Route::delete('/admin/web-pages/{type}/{id}', [WebPageController::class, 'adminDestroy']);
});

Route::prefix('admin/auth')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);
});

Route::prefix('supplier/auth')->group(function () {
    Route::post('/login', [SupplierAuthController::class, 'login']);
    Route::post('/forgot-password', [SupplierAuthController::class, 'forgotPassword']);
    Route::get('/reset-password/{token}', [SupplierAuthController::class, 'showResetToken']);
    Route::post('/reset-password', [SupplierAuthController::class, 'resetPassword']);
});

Route::prefix('admin/invites')->group(function () {
    Route::get('/{token}', [AdminUserController::class, 'showInvite']);
    Route::post('/accept', [AdminUserController::class, 'acceptInvite']);
});

Route::prefix('supplier/invites')->group(function () {
    Route::get('/{token}', [SupplierUserController::class, 'showInvite']);
    Route::post('/accept', [SupplierUserController::class, 'acceptInvite']);
});

Route::middleware('auth:sanctum')->prefix('admin/auth')->group(function () {
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::get('/me', [AdminAuthController::class, 'me']);
});

Route::middleware('auth:sanctum')->prefix('supplier/auth')->group(function () {
    Route::post('/logout', [SupplierAuthController::class, 'logout']);
    Route::get('/me', [SupplierAuthController::class, 'me']);
});
