<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\AddsContentController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductBrandController;
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
use App\Http\Controllers\Api\JntShippingController;
use App\Http\Controllers\Api\XdeShippingController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierAuthController;
use App\Http\Controllers\Api\SupplierUserController;
use App\Http\Controllers\Api\SupplierOrderController;
use App\Http\Controllers\Api\CustomerAddressController;
use App\Http\Controllers\Api\InteriorRequestController;
use App\Http\Controllers\Api\JntWebhookController;
use App\Http\Controllers\Api\AdminInquiryController;
use App\Http\Controllers\Api\PartnerUserController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AdminPaymentController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\CustomerConversationController;
use App\Http\Controllers\Api\AdminConversationController;
use App\Http\Controllers\Api\MemberTierController;
use App\Http\Controllers\Api\MemberActivityLogController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ShippingRateController;
use App\Http\Controllers\Api\PasskeyAuthController;
use App\Http\Controllers\Api\ProductViewerController;
use App\Http\Controllers\Api\TotpController;


// Public auth routes
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:member-login')->post('/login', [AuthController::class, 'login']);

    // Brute-force targets: 10 requests/min per IP
    Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/mobile/login', [AuthController::class, 'mobileLogin']);
    Route::post('/passkeys/login/options', [PasskeyAuthController::class, 'loginOptions']);
    Route::post('/passkeys/login/verify', [PasskeyAuthController::class, 'loginVerify']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/login/mfa/respond', [AuthController::class, 'respondLoginMfa']);
});

// OTP resend: 5 requests/min per IP to prevent flooding
Route::middleware('throttle:otp')->group(function () {
    Route::post('/register/resend-otp', [AuthController::class, 'resendRegistrationOtp']);
    Route::post('/login/2fa/resend', [AuthController::class, 'resendLoginOtp']);
    Route::post('/login/mfa/resend', [AuthController::class, 'resendLoginOtp']);
});
    // Low-risk read/check endpoints
    Route::get('/register/check-email', [AuthController::class, 'checkEmailAvailability']);
    Route::get('/register/check-username', [AuthController::class, 'checkUsernameAvailability']);
    Route::get('/register/check-referral', [AuthController::class, 'checkReferralAvailability']);
    Route::post('/register/verify-otp', [AuthController::class, 'verifyRegistrationOtp']);
    Route::post('/login/mfa/status', [AuthController::class, 'loginMfaStatus']);
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetToken']);

    // Social auth - OAuth redirect and callback
    Route::get('/{provider}', [AuthController::class, 'redirectToProvider'])->where('provider', 'google|facebook');
    Route::get('/{provider}/callback', [AuthController::class, 'handleProviderCallback'])->where('provider', 'google|facebook');
    
    // Simplified Google login endpoints
    Route::post('/google/login', [AuthController::class, 'googleLogin']);
    Route::post('/callback/google', [AuthController::class, 'googleCallback']);
    Route::post('/callback/facebook', [AuthController::class, 'facebookCallback']);

    // Facebook data deletion callback (required by Facebook Platform Policy)
    Route::post('/facebook/data-deletion', [AuthController::class, 'facebookDataDeletion']);
    Route::get('/facebook/data-deletion/status', [AuthController::class, 'facebookDataDeletionStatus']);

    // Authenticated routes for social account management
    Route::middleware(['auth:sanctum', 'throttle:auth'])->group(function () {
        Route::post('/link/{provider}', [AuthController::class, 'linkSocialAccount'])->where('provider', 'google|facebook');
        Route::post('/unlink/{provider}', [AuthController::class, 'unlinkSocialAccount'])->where('provider', 'google|facebook');
        Route::get('/linked-accounts', [AuthController::class, 'getLinkedAccounts']);
    });
});

// Checkout and payment initiation: 20 requests/min per IP
Route::middleware('throttle:checkout')->group(function () {
    Route::post('/payments/checkout-session', [PaymentController::class, 'createCheckoutSession']);
    Route::post('/payments/validate-voucher', [PaymentController::class, 'validateVoucher']);
});
Route::get('/payments/checkout-session/{checkoutId}', [PaymentController::class, 'verifyCheckoutSession']);
Route::get('/orders/track', [PaymentController::class, 'trackGuestOrder']);

// AI support is expensive — same strict limit as auth
Route::middleware('throttle:auth')->post('/ai-support', [\App\Http\Controllers\Api\AiSupportController::class, 'handle']);

// Inbound webhooks: 30 requests/min per IP; POST-only
Route::middleware('throttle:webhooks')->group(function () {
    Route::post('/payments/webhooks/paymongo', [PaymentController::class, 'handlePaymongoWebhook']);
    Route::post('/payments/webhooks/test-paid', [PaymentController::class, 'handleTestPaidWebhook']);
    Route::post('/jnt/sandbox/logistics-trackback', [JntWebhookController::class, 'sandboxLogisticsTrackback']);
    Route::post('/jnt/sandbox/order-status', [JntWebhookController::class, 'sandboxOrderStatus']);
    Route::post('/jnt/webhook/logistics-trackback', [JntWebhookController::class, 'productionLogisticsTrackback']);
    Route::post('/jnt/webhook/order-status', [JntWebhookController::class, 'productionOrderStatus']);
});

// Public read endpoints: 120 requests/min per IP
Route::middleware('throttle:public')->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/products/slug/{slug}', [ProductController::class, 'showBySlug']);
    Route::get('/products/{id}/reviews', [ProductController::class, 'reviews']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::get('/products/{id}/brand', [ProductController::class, 'brand']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/product-brands', [ProductBrandController::class, 'publicIndex']);
    Route::get('/shipping-rates', [ShippingRateController::class, 'publicIndex']);
    Route::get('/web-pages/home', [WebPageController::class, 'home']);
    Route::get('/web-pages/adds-content', [AddsContentController::class, 'publicIndex']);
    Route::get('/web-pages/{type}', [WebPageController::class, 'publicIndex']);
    Route::get('/settings/general', [AdminSettingsController::class, 'publicGeneral']);
    Route::get('/address/regions', [AddressController::class, 'regions']);
    Route::get('/address/provinces', [AddressController::class, 'provinces']);
    Route::get('/address/cities', [AddressController::class, 'cities']);
    Route::get('/address/barangays', [AddressController::class, 'barangays']);
    Route::post('/products/{id}/viewers/heartbeat', [ProductViewerController::class, 'heartbeat']);
});


// Protected routes (requires Sanctum token)
Route::middleware(['auth:sanctum', 'customer.actor'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);
    Route::get('/auth/activity', [AuthController::class, 'activity']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'updateMe']);
    Route::get('/referral-tree', [AuthController::class, 'referralTree']);
    Route::get('/sessions', [AuthController::class, 'sessions']);
    Route::delete('/sessions/{tokenId}', [AuthController::class, 'revokeSession']);
    Route::get('/username-change/latest', [AuthController::class, 'latestUsernameChangeRequest']);
    Route::post('/username-change/send-otp', [AuthController::class, 'sendUsernameChangeOtp']);
    Route::post('/username-change/submit', [AuthController::class, 'submitUsernameChangeRequest']);
    Route::get('/account/snapshot', [AuthController::class, 'accountSnapshot']);
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
    Route::post('/cart/add', [CartController::class, 'addToCart']);
    Route::get('/cart', [CartController::class, 'getCart']);
    Route::put('/cart/{id}', [CartController::class, 'updateCartItem']);
    Route::delete('/cart/{id}', [CartController::class, 'removeCartItem']);
    Route::delete('/cart', [CartController::class, 'clearCart']);
    Route::post('/search/history', [ProductController::class, 'saveSearchHistory']);
    Route::get('/search/history', [ProductController::class, 'getSearchHistory']);
    Route::delete('/search/history', [ProductController::class, 'clearSearchHistory']);
    Route::delete('/search/history/{id}', [ProductController::class, 'deleteSearchHistory']);
    Route::get('/auth/passkeys', [PasskeyAuthController::class, 'index']);
    Route::post('/auth/passkeys/register/options', [PasskeyAuthController::class, 'registerOptions']);
    Route::post('/auth/passkeys/register/verify', [PasskeyAuthController::class, 'registerVerify']);
    Route::delete('/auth/passkeys/{id}', [PasskeyAuthController::class, 'destroy']);

    Route::post('/auth/totp/setup', [TotpController::class, 'setup']);
    Route::post('/auth/totp/enable', [TotpController::class, 'enable']);
    Route::post('/auth/totp/disable', [TotpController::class, 'disable']);

    // Customer Service / Conversations
    Route::get('/conversations', [CustomerConversationController::class, 'index']);
    Route::post('/conversations', [CustomerConversationController::class, 'store']);
    Route::get('/conversations/{id}', [CustomerConversationController::class, 'show']);
    Route::post('/conversations/{id}/messages', [CustomerConversationController::class, 'sendMessage']);
    Route::get('/conversations/{id}/messages', [CustomerConversationController::class, 'getMessages']);
    Route::post('/conversations/{id}/close', [CustomerConversationController::class, 'closeConversation']);
    Route::post('/conversations/{id}/reopen', [CustomerConversationController::class, 'reopenConversation']);
    Route::get('/conversations/unread/count', [CustomerConversationController::class, 'unreadCount']);
    Route::post('/conversations/pusher/auth', [CustomerConversationController::class, 'pusherAuth']);
    Route::post('/customer/pusher/auth', [MemberController::class, 'pusherAuth']);

    // Activity Logs
    Route::get('/activity-logs', [MemberActivityLogController::class, 'myLogs']);
    Route::post('/activity-logs', [MemberActivityLogController::class, 'createLog']);
    Route::get('/activity-logs/{id}', [MemberActivityLogController::class, 'show']);
    Route::get('/activity-logs/logins/history', [MemberActivityLogController::class, 'loginHistory']);
    Route::get('/activity-logs/purchases/history', [MemberActivityLogController::class, 'purchaseHistory']);
    Route::get('/activity-logs/wallet/history', [MemberActivityLogController::class, 'walletHistory']);
});

Route::middleware(['auth:sanctum', 'admin.token.validation', 'admin.role:super_admin,admin,csr'])->group(function () {
    Route::get('/admin/members', [MemberController::class, 'index']);
    Route::get('/admin/members/top-earners', [MemberController::class, 'topEarners']);
    Route::get('/admin/members/stats', [MemberController::class, 'stats']);
    Route::get('/admin/members/stats/{stat}', [MemberController::class, 'statDetails']);
    Route::get('/admin/members/referrals', [MemberController::class, 'referralTree']);
    Route::patch('/admin/members/{id}', [MemberController::class, 'update']);
    Route::delete('/admin/members/{id}', [MemberController::class, 'destroy']);
    Route::get('/admin/members/orphaned', [MemberController::class, 'orphanedMembers']);
    Route::patch('/admin/members/{id}/assign-sponsor', [MemberController::class, 'assignSponsor']);
    Route::get('/admin/members/kyc', [AdminMemberKycController::class, 'index']);
    Route::patch('/admin/members/kyc/{id}/approve', [AdminMemberKycController::class, 'approve']);
    Route::patch('/admin/members/kyc/{id}/reject', [AdminMemberKycController::class, 'reject']);
    Route::get('/admin/inquiries/username-changes', [AdminInquiryController::class, 'usernameChangeRequests']);
    Route::patch('/admin/inquiries/username-changes/{id}/approve', [AdminInquiryController::class, 'approveUsernameChange']);
    Route::patch('/admin/inquiries/username-changes/{id}/reject', [AdminInquiryController::class, 'rejectUsernameChange']);

    // Admin: Customer Service / Conversations
    Route::get('/admin/conversations', [AdminConversationController::class, 'index']);
    Route::get('/admin/conversations/open', [AdminConversationController::class, 'openConversations']);
    Route::get('/admin/conversations/statistics', [AdminConversationController::class, 'statistics']);
    Route::get('/admin/conversations/{id}', [AdminConversationController::class, 'show']);
    Route::post('/admin/conversations/{id}/assign-agent', [AdminConversationController::class, 'assignAgent']);
    Route::post('/admin/conversations/{id}/unassign-agent', [AdminConversationController::class, 'unassignAgent']);
    Route::post('/admin/conversations/{id}/messages', [AdminConversationController::class, 'sendMessage']);
    Route::get('/admin/conversations/{id}/messages', [AdminConversationController::class, 'getMessages']);
    Route::patch('/admin/conversations/{id}/status', [AdminConversationController::class, 'updateStatus']);
    Route::post('/admin/conversations/pusher/auth', [AdminConversationController::class, 'pusherAuth']);

    // Admin: Member Activity Logs
    Route::get('/admin/activity-logs', [MemberActivityLogController::class, 'allLogs']);
    Route::get('/admin/members/{id}/activity-logs', [MemberActivityLogController::class, 'memberLogs']);
});

Route::middleware(['auth:sanctum', 'admin.token.validation', 'admin.or_supplier'])->group(function () {
    Route::get('/admin/products', [ProductController::class, 'index']);
    Route::get('/admin/products/export', [ProductController::class, 'exportCsv']);
    Route::get('/admin/products/activity-logs', [ProductController::class, 'activityLogs']);
    Route::post('/admin/products', [ProductController::class, 'store']);
    Route::post('/admin/products/import', [ProductController::class, 'import']);
    Route::post('/admin/products/zq/fetch-preview', [ProductController::class, 'fetchZqImportPreview']);
    Route::get('/admin/products/zq/detail/{id}', [ProductController::class, 'fetchZqImportDetail']);
    Route::post('/admin/products/zq/sync', [ProductController::class, 'syncZqProducts']);
    Route::get('/admin/products/zq/summary', [ProductController::class, 'zqProductsSummary']);
    Route::get('/admin/products/zq/cached', [ProductController::class, 'listCachedZqProducts']);
    Route::post('/admin/products/zq/import-to-local/{id}', [ProductController::class, 'importZqProductToLocal']);
    Route::post('/admin/products/bulk-price/preview', [ProductController::class, 'bulkPricePreview']);
    Route::post('/admin/products/bulk-price/apply', [ProductController::class, 'bulkPriceApply']);
    Route::post('/admin/products/bulk-update/preview', [ProductController::class, 'bulkUpdatePreview']);
    Route::post('/admin/products/bulk-update/apply', [ProductController::class, 'bulkUpdateApply']);
    Route::post('/admin/products/manual-checkout/apply', [ProductController::class, 'manualCheckoutApply']);
Route::get('/admin/webpages/adds-content', [AddsContentController::class, 'index']);
    Route::post('/admin/webpages/adds-content', [AddsContentController::class, 'store']);
    Route::patch('/admin/webpages/adds-content/{id}', [AddsContentController::class, 'update']);
    Route::patch('/admin/webpages/adds-content/{id}/status', [AddsContentController::class, 'updateStatus']);
    Route::delete('/admin/webpages/adds-content/{id}', [AddsContentController::class, 'destroy']);
    Route::put('/admin/products/{id}', [ProductController::class, 'update']);
    Route::delete('/admin/products/{id}', [ProductController::class, 'destroy']);
    Route::get('/admin/product-brands', [ProductBrandController::class, 'index']);
    Route::get('/admin/suppliers/stats', [SupplierController::class, 'stats']);
    Route::get('/admin/suppliers', [SupplierController::class, 'index']);
    Route::get('/admin/suppliers/{id}/categories', [SupplierController::class, 'categories']);
    Route::get('/admin/supplier-users', [SupplierUserController::class, 'index']);
    Route::post('/admin/supplier-users', [SupplierUserController::class, 'store']);
    Route::put('/admin/supplier-users/{id}', [SupplierUserController::class, 'update']);
    Route::delete('/admin/supplier-users/{id}', [SupplierUserController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'admin.token.validation', 'admin.role:super_admin,admin,merchant_admin,web_content'])->group(function () {
    Route::get('/admin/categories', [CategoryController::class, 'index']);
    Route::post('/admin/categories', [CategoryController::class, 'store']);
    Route::put('/admin/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/admin/categories/{id}', [CategoryController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'admin.token.validation', 'admin.role:super_admin,admin'])->group(function () {
    Route::get('/admin/shipping/rates', [ShippingRateController::class, 'adminIndex']);
    Route::post('/admin/shipping/rates', [ShippingRateController::class, 'store']);
    Route::delete('/admin/shipping/rates', [ShippingRateController::class, 'bulkDestroy']);
    Route::put('/admin/shipping/rates/{id}', [ShippingRateController::class, 'update']);
    Route::delete('/admin/shipping/rates/{id}', [ShippingRateController::class, 'destroy']);
    Route::get('/admin/settings/general', [\App\Http\Controllers\Api\AdminSettingsController::class, 'showGeneral']);
    Route::post('/admin/settings/general', [\App\Http\Controllers\Api\AdminSettingsController::class, 'updateGeneral']);
    Route::get('/admin/settings/security', [\App\Http\Controllers\Api\AdminSettingsController::class, 'showSecurity']);
    Route::post('/admin/settings/security', [\App\Http\Controllers\Api\AdminSettingsController::class, 'updateSecurity']);
    Route::get('/admin/settings/notifications', [\App\Http\Controllers\Api\AdminSettingsController::class, 'showNotifications']);
    Route::post('/admin/settings/notifications', [\App\Http\Controllers\Api\AdminSettingsController::class, 'updateNotifications']);
    Route::post('/admin/members/{id}/temporary-password', [MemberController::class, 'generateTemporaryPassword']);
    Route::post('/admin/suppliers', [SupplierController::class, 'store']);
    Route::put('/admin/suppliers/{id}', [SupplierController::class, 'update']);
    Route::delete('/admin/suppliers/{id}', [SupplierController::class, 'destroy']);
    Route::put('/admin/suppliers/{id}/categories', [SupplierController::class, 'syncCategories']);
    Route::post('/admin/product-brands', [ProductBrandController::class, 'store']);
    Route::put('/admin/product-brands/{id}', [ProductBrandController::class, 'update']);
    Route::delete('/admin/product-brands/{id}', [ProductBrandController::class, 'destroy']);

    // Member Tiers
    Route::get('/admin/member-tiers', [MemberTierController::class, 'index']);
    Route::post('/admin/member-tiers', [MemberTierController::class, 'store']);
    Route::get('/admin/member-tiers/{id}', [MemberTierController::class, 'show']);
    Route::patch('/admin/member-tiers/{id}', [MemberTierController::class, 'update']);
    Route::delete('/admin/member-tiers/{id}', [MemberTierController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'admin.token.validation', 'admin.role:super_admin,admin,csr,merchant_admin'])->group(function () {
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
    Route::patch('/admin/orders/{id}/fulfillment-mode', [AdminOrderController::class, 'updateFulfillmentMode']);
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

Route::middleware(['auth:sanctum', 'admin.token.validation', 'admin.role:super_admin,admin,accounting,finance_officer'])->group(function () {
    Route::get('/admin/payments/overview', [AdminPaymentController::class, 'overview']);
    Route::get('/admin/encashment', [AdminEncashmentController::class, 'index']);
    Route::get('/admin/encashment/vouchers/all', [AdminEncashmentController::class, 'allAffiliateVouchers']);
    Route::patch('/admin/encashment/{id}/approve', [AdminEncashmentController::class, 'approve']);
    Route::patch('/admin/encashment/{id}/reject', [AdminEncashmentController::class, 'reject']);
    Route::patch('/admin/encashment/{id}/release', [AdminEncashmentController::class, 'release']);
    Route::post('/admin/encashment/yearly-global-bonus/award', [AdminEncashmentController::class, 'awardYearlyGlobalBonus']);
    Route::get('/admin/expenses/categories', [ExpenseCategoryController::class, 'index']);
    Route::post('/admin/expenses/categories', [ExpenseCategoryController::class, 'store']);
    Route::put('/admin/expenses/categories/{id}', [ExpenseCategoryController::class, 'update']);
    Route::delete('/admin/expenses/categories/{id}', [ExpenseCategoryController::class, 'destroy']);
    Route::get('/admin/expenses', [ExpenseController::class, 'index']);
    Route::get('/admin/expenses/summary', [ExpenseController::class, 'summary']);
    Route::post('/admin/expenses', [ExpenseController::class, 'store']);
    Route::put('/admin/expenses/{id}', [ExpenseController::class, 'update']);
    Route::delete('/admin/expenses/{id}', [ExpenseController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'admin.token.validation', 'admin.role:super_admin,admin'])->group(function () {
    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::get('/admin/users/{id}/activity', [AdminUserController::class, 'activity']);
    Route::post('/admin/users/presence/heartbeat', [AdminUserController::class, 'heartbeat']);
    Route::post('/admin/users', [AdminUserController::class, 'store']);
    Route::put('/admin/users/{id}', [AdminUserController::class, 'update']);
    Route::delete('/admin/users/{id}', [AdminUserController::class, 'destroy']);
    Route::put('/admin/users/{id}/ban', [AdminUserController::class, 'ban']);
    Route::put('/admin/users/{id}/unban', [AdminUserController::class, 'unban']);
});

Route::middleware(['auth:sanctum', 'admin.token.validation', 'admin.role:super_admin'])->group(function () {
    Route::get('/admin/web-pages/database/exports', [WebPageController::class, 'listDatabaseExports']);
    Route::post('/admin/web-pages/database/exports', [WebPageController::class, 'exportDatabase']);
    Route::post('/admin/web-pages/database/exports/download', [WebPageController::class, 'downloadDatabaseExport']);
    Route::delete('/admin/web-pages/database/exports', [WebPageController::class, 'deleteDatabaseExport']);
});

Route::middleware(['auth:sanctum', 'admin.token.validation', 'admin.role:super_admin,admin,web_content'])->group(function () {
    Route::get('/admin/web-pages/{type}', [WebPageController::class, 'adminIndex']);
    Route::post('/admin/web-pages/{type}', [WebPageController::class, 'adminStore'])->middleware('throttle:admin-write');
    Route::put('/admin/web-pages/{type}/{id}', [WebPageController::class, 'adminUpdate'])->middleware('throttle:admin-write');
    Route::delete('/admin/web-pages/{type}/{id}', [WebPageController::class, 'adminDestroy'])->middleware('throttle:admin-write');
});

Route::middleware(['auth:sanctum', 'admin.token.validation', 'admin.role:super_admin,admin,web_content'])->group(function () {
    Route::get('/admin/partner-users', [PartnerUserController::class, 'index']);
    Route::post('/admin/partner-users', [PartnerUserController::class, 'store']);
    Route::put('/admin/partner-users/{id}', [PartnerUserController::class, 'update']);
    Route::delete('/admin/partner-users/{id}', [PartnerUserController::class, 'destroy']);
});

Route::prefix('admin/auth')->group(function () {
    Route::middleware('throttle:admin-login')->post('/login', [AdminAuthController::class, 'login']);
    Route::middleware('throttle:otp')->post('/login/2fa/resend', [AdminAuthController::class, 'resendLoginOtp']);
});

Route::prefix('supplier/auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/login', [SupplierAuthController::class, 'login']);
        Route::post('/forgot-password', [SupplierAuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [SupplierAuthController::class, 'resetPassword']);
    });
    Route::middleware('throttle:otp')->post('/login/2fa/resend', [SupplierAuthController::class, 'resendLoginOtp']);
    Route::get('/reset-password/{token}', [SupplierAuthController::class, 'showResetToken']);
});

Route::prefix('admin/invites')->group(function () {
    Route::get('/{token}', [AdminUserController::class, 'showInvite']);
    Route::post('/accept', [AdminUserController::class, 'acceptInvite']);
});

Route::prefix('supplier/invites')->group(function () {
    Route::get('/{token}', [SupplierUserController::class, 'showInvite']);
    Route::post('/accept', [SupplierUserController::class, 'acceptInvite']);
});

Route::middleware(['auth:sanctum', 'admin.actor', 'admin.token.validation'])->prefix('admin/auth')->group(function () {
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::get('/me', [AdminAuthController::class, 'me']);
    Route::put('/me', [AdminAuthController::class, 'updateMe']);
});

Route::middleware(['auth:sanctum', 'supplier.actor'])->prefix('supplier/auth')->group(function () {
    Route::post('/logout', [SupplierAuthController::class, 'logout']);
    Route::get('/me', [SupplierAuthController::class, 'me']);
});

Route::middleware(['auth:sanctum', 'supplier.actor'])->group(function () {
    Route::get('/supplier/orders/notifications', [SupplierOrderController::class, 'notifications']);
    Route::get('/supplier/orders', [SupplierOrderController::class, 'index']);
    Route::patch('/supplier/orders/{id}/fulfillment', [SupplierOrderController::class, 'updateFulfillment']);
    Route::patch('/supplier/orders/{id}/tracking', [SupplierOrderController::class, 'updateTracking']);
});

// Leads: same strict limit as auth to prevent spam submissions
Route::prefix('leads')->middleware('throttle:auth')->group(function () {
    Route::post('/', [LeadController::class, 'store']);
    Route::post('/batch', [LeadController::class, 'storeBatch']);
});
