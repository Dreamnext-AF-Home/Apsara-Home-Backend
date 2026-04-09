<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminEncashmentController;
use App\Http\Controllers\Api\AdminInquiryController;
use App\Http\Controllers\Api\AdminMemberKycController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\InteriorRequestController;
use App\Http\Controllers\Api\JntShippingController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\XdeShippingController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/auth')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);
});

Route::prefix('admin/invites')->group(function () {
    Route::get('/{token}', [AdminUserController::class, 'showInvite']);
    Route::post('/accept', [AdminUserController::class, 'acceptInvite']);
});

Route::middleware(['auth:sanctum', 'admin.actor'])->prefix('admin/auth')->group(function () {
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::get('/me', [AdminAuthController::class, 'me']);
    Route::put('/me', [AdminAuthController::class, 'updateMe']);
});

Route::middleware(['auth:sanctum', 'admin.role:super_admin,admin,csr'])->group(function () {
    Route::get('/admin/members', [MemberController::class, 'index']);
    Route::get('/admin/members/stats', [MemberController::class, 'stats']);
    Route::get('/admin/members/referrals', [MemberController::class, 'referralTree']);
    Route::patch('/admin/members/{id}', [MemberController::class, 'update']);
    Route::delete('/admin/members/{id}', [MemberController::class, 'destroy']);

    Route::get('/admin/members/kyc', [AdminMemberKycController::class, 'index']);
    Route::patch('/admin/members/kyc/{id}/approve', [AdminMemberKycController::class, 'approve']);
    Route::patch('/admin/members/kyc/{id}/reject', [AdminMemberKycController::class, 'reject']);

    Route::get('/admin/inquiries/username-changes', [AdminInquiryController::class, 'usernameChangeRequests']);
    Route::patch('/admin/inquiries/username-changes/{id}/approve', [AdminInquiryController::class, 'approveUsernameChange']);
    Route::patch('/admin/inquiries/username-changes/{id}/reject', [AdminInquiryController::class, 'rejectUsernameChange']);
});

Route::middleware(['auth:sanctum', 'admin.role:super_admin,admin,csr,merchant_admin'])->group(function () {
    Route::get('/admin/interior-requests', [InteriorRequestController::class, 'adminIndex']);
    Route::patch('/admin/interior-requests/{id}', [InteriorRequestController::class, 'adminUpdate']);
    Route::post('/admin/interior-requests/{id}/updates', [InteriorRequestController::class, 'adminStoreUpdate']);

    Route::get('/admin/orders', [AdminOrderController::class, 'index']);
    Route::get('/admin/orders/notifications', [AdminOrderController::class, 'notifications']);
    Route::post('/admin/orders/notifications/read-all', [AdminOrderController::class, 'markAllNotificationsRead']);
    Route::post('/admin/orders/notifications/{id}/read', [AdminOrderController::class, 'markNotificationRead']);
    Route::post('/admin/realtime/pusher/auth', [AdminOrderController::class, 'pusherAuth']);
    Route::patch('/admin/orders/{id}/approve', [AdminOrderController::class, 'approve']);
    Route::patch('/admin/orders/{id}/reject', [AdminOrderController::class, 'reject']);
    Route::patch('/admin/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
    Route::patch('/admin/orders/{id}/shipment-status', [AdminOrderController::class, 'updateShipmentStatus']);
    Route::post('/admin/orders/{id}/zq/push', [AdminOrderController::class, 'pushToZq']);
    Route::get('/admin/orders/{id}/zq/detail', [AdminOrderController::class, 'fetchZqDetail']);
    Route::get('/admin/orders/{id}/zq/tracking', [AdminOrderController::class, 'syncZqTracking']);

    Route::post('/admin/orders/{id}/shipping/xde/book', [XdeShippingController::class, 'bookForOrder']);
    Route::get('/admin/orders/{id}/shipping/xde/track', [XdeShippingController::class, 'trackByOrder']);
    Route::get('/admin/orders/{id}/shipping/xde/waybill', [XdeShippingController::class, 'waybillByOrder']);
    Route::post('/admin/orders/{id}/shipping/xde/cancel', [XdeShippingController::class, 'cancelByOrder']);
    Route::get('/admin/orders/{id}/shipping/xde/epod', [XdeShippingController::class, 'epodByOrder']);
    Route::get('/admin/shipping/xde/track/{trackingNo}', [XdeShippingController::class, 'trackByTrackingNo']);

    Route::post('/admin/orders/{id}/shipping/jnt/book', [JntShippingController::class, 'bookForOrder']);
    Route::get('/admin/orders/{id}/shipping/jnt/track', [JntShippingController::class, 'trackByOrder']);
    Route::get('/admin/shipping/jnt/track/{trackingNo}', [JntShippingController::class, 'trackByTrackingNo']);
});

Route::middleware(['auth:sanctum', 'admin.role:super_admin,accounting,finance_officer'])->group(function () {
    Route::get('/admin/encashment', [AdminEncashmentController::class, 'index']);
    Route::patch('/admin/encashment/{id}/approve', [AdminEncashmentController::class, 'approve']);
    Route::patch('/admin/encashment/{id}/reject', [AdminEncashmentController::class, 'reject']);
    Route::patch('/admin/encashment/{id}/release', [AdminEncashmentController::class, 'release']);
    Route::post('/admin/encashment/yearly-global-bonus/award', [AdminEncashmentController::class, 'awardYearlyGlobalBonus']);
});

Route::middleware(['auth:sanctum', 'admin.role:super_admin,admin'])->group(function () {
    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::get('/admin/users/{id}/activity', [AdminUserController::class, 'activity']);
    Route::post('/admin/users/presence/heartbeat', [AdminUserController::class, 'heartbeat']);
    Route::post('/admin/users', [AdminUserController::class, 'store']);
    Route::put('/admin/users/{id}', [AdminUserController::class, 'update']);
    Route::delete('/admin/users/{id}', [AdminUserController::class, 'destroy']);
    Route::put('/admin/users/{id}/ban', [AdminUserController::class, 'ban']);
    Route::put('/admin/users/{id}/unban', [AdminUserController::class, 'unban']);
});
