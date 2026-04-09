<?php

use App\Http\Controllers\Api\SupplierAuthController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierOrderController;
use App\Http\Controllers\Api\SupplierUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('supplier/auth')->group(function () {
    Route::post('/login', [SupplierAuthController::class, 'login']);
    Route::post('/forgot-password', [SupplierAuthController::class, 'forgotPassword']);
    Route::get('/reset-password/{token}', [SupplierAuthController::class, 'showResetToken']);
    Route::post('/reset-password', [SupplierAuthController::class, 'resetPassword']);
});

Route::prefix('supplier/invites')->group(function () {
    Route::get('/{token}', [SupplierUserController::class, 'showInvite']);
    Route::post('/accept', [SupplierUserController::class, 'acceptInvite']);
});

Route::middleware(['auth:sanctum', 'supplier.actor'])->prefix('supplier/auth')->group(function () {
    Route::post('/logout', [SupplierAuthController::class, 'logout']);
    Route::get('/me', [SupplierAuthController::class, 'me']);
});

Route::middleware(['auth:sanctum', 'supplier.actor'])->group(function () {
    Route::get('/supplier/orders', [SupplierOrderController::class, 'index']);
    Route::patch('/supplier/orders/{id}/fulfillment', [SupplierOrderController::class, 'updateFulfillment']);
    Route::patch('/supplier/orders/{id}/tracking', [SupplierOrderController::class, 'updateTracking']);
});

Route::middleware(['auth:sanctum', 'admin.or_supplier'])->group(function () {
    Route::get('/admin/suppliers', [SupplierController::class, 'index']);
    Route::get('/admin/suppliers/{id}/categories', [SupplierController::class, 'categories']);
    Route::get('/admin/supplier-users', [SupplierUserController::class, 'index']);
    Route::post('/admin/supplier-users', [SupplierUserController::class, 'store']);
    Route::delete('/admin/supplier-users/{id}', [SupplierUserController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'admin.role:super_admin,admin'])->group(function () {
    Route::post('/admin/suppliers', [SupplierController::class, 'store']);
    Route::put('/admin/suppliers/{id}', [SupplierController::class, 'update']);
    Route::delete('/admin/suppliers/{id}', [SupplierController::class, 'destroy']);
    Route::put('/admin/suppliers/{id}/categories', [SupplierController::class, 'syncCategories']);
});
