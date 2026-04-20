<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureAdminActor;
use App\Http\Middleware\EnsureAdminOrSupplierActor;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureCustomerActor;
use App\Http\Middleware\EnsureSupplierActor;
use App\Http\Middleware\JsonAuthentication;
use App\Http\Middleware\AdminTokenValidation;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.actor' => EnsureAdminActor::class,
            'admin.or_supplier' => EnsureAdminOrSupplierActor::class,
            'admin.role' => EnsureAdminRole::class,
            'customer.actor' => EnsureCustomerActor::class,
            'supplier.actor' => EnsureSupplierActor::class,
            'auth.json' => JsonAuthentication::class,
            'admin.token.validation' => AdminTokenValidation::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })->create();
