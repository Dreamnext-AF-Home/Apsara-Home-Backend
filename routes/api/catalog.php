<?php

use App\Http\Controllers\Api\AddsContentController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductBrandController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'admin.or_supplier'])->group(function () {
    Route::get('/admin/products', [ProductController::class, 'index']);
    Route::get('/admin/products/activity-logs', [ProductController::class, 'activityLogs']);
    Route::post('/admin/products', [ProductController::class, 'store']);
    Route::post('/admin/products/import', [ProductController::class, 'import']);
    Route::post('/admin/products/bulk-price/preview', [ProductController::class, 'bulkPricePreview']);
    Route::post('/admin/products/bulk-price/apply', [ProductController::class, 'bulkPriceApply']);
    Route::put('/admin/products/{id}', [ProductController::class, 'update']);
    Route::delete('/admin/products/{id}', [ProductController::class, 'destroy']);

    Route::get('/admin/webpages/adds-content', [AddsContentController::class, 'index']);
    Route::post('/admin/webpages/adds-content', [AddsContentController::class, 'store']);
    Route::patch('/admin/webpages/adds-content/{id}', [AddsContentController::class, 'update']);
    Route::patch('/admin/webpages/adds-content/{id}/status', [AddsContentController::class, 'updateStatus']);

    Route::get('/admin/product-brands', [ProductBrandController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'admin.role:super_admin,admin,merchant_admin,web_content'])->group(function () {
    Route::get('/admin/categories', [CategoryController::class, 'index']);
    Route::post('/admin/categories', [CategoryController::class, 'store']);
    Route::put('/admin/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/admin/categories/{id}', [CategoryController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'admin.role:super_admin,admin'])->group(function () {
    Route::post('/admin/product-brands', [ProductBrandController::class, 'store']);
    Route::put('/admin/product-brands/{id}', [ProductBrandController::class, 'update']);
    Route::delete('/admin/product-brands/{id}', [ProductBrandController::class, 'destroy']);
});
