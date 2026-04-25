<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Override PostgreSQL connector to inject Neon endpoint into DSN
        $this->app->bind('db.connector.pgsql', function () {
            return new class extends PostgresConnector {
                protected function getDsn(array $config): string
                {
                    $dsn = parent::getDsn($config);

                    // Neon SNI workaround: inject endpoint ID into DSN options
                    if (!empty($config['neon_endpoint'])) {
                        $dsn .= ";options='" . $config['neon_endpoint'] . "'";
                    }

                    return $dsn;
                }
            };
        });
    }

    public function boot(): void
    {
        // Login, register, password reset — strict to block brute-force and spam
        RateLimiter::for('auth', fn (Request $req) =>
            Limit::perMinute(10)->by($req->ip())
        );

        // OTP resend — tighter to prevent OTP flooding
        RateLimiter::for('otp', fn (Request $req) =>
            Limit::perMinute(5)->by($req->ip())
        );

        // Checkout and payment initiation
        RateLimiter::for('checkout', fn (Request $req) =>
            Limit::perMinute(20)->by($req->ip())
        );

        // Inbound webhooks from external services
        RateLimiter::for('webhooks', fn (Request $req) =>
            Limit::perMinute(30)->by($req->ip())
        );

        // General public read endpoints (products, categories, etc.)
        RateLimiter::for('public', fn (Request $req) =>
            Limit::perMinute(120)->by($req->ip())
        );
    }
}
