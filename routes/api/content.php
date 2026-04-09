<?php

use App\Http\Controllers\Api\WebPageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'admin.role:super_admin,admin,web_content'])->group(function () {
    Route::get('/admin/web-pages/{type}', [WebPageController::class, 'adminIndex']);
    Route::post('/admin/web-pages/{type}', [WebPageController::class, 'adminStore']);
    Route::put('/admin/web-pages/{type}/{id}', [WebPageController::class, 'adminUpdate']);
    Route::delete('/admin/web-pages/{type}/{id}', [WebPageController::class, 'adminDestroy']);
});
