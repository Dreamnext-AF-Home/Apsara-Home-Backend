<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private function normalizeLoginIdentifier(Request $req): string
    {
        $raw = (string) ($req->input('login')
            ?? $req->input('email')
            ?? $req->input('username')
            ?? '');

        $normalized = mb_strtolower(trim($raw));
        return $normalized !== '' ? $normalized : 'unknown';
    }

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
        $firebaseCredentialsJson = env('FIREBASE_CREDENTIALS_JSON');
        $firebaseCredentialsPath = config('services.firebase.credentials', 'storage/firebase-credentials.json');
        if (is_string($firebaseCredentialsJson) && trim($firebaseCredentialsJson) !== '') {
            $resolvedPath = str_starts_with($firebaseCredentialsPath, DIRECTORY_SEPARATOR)
                ? $firebaseCredentialsPath
                : base_path($firebaseCredentialsPath);

            if (!file_exists($resolvedPath)) {
                $credentialsDir = dirname($resolvedPath);
                if (!is_dir($credentialsDir)) {
                    @mkdir($credentialsDir, 0755, true);
                }
                @file_put_contents($resolvedPath, $firebaseCredentialsJson);
                @chmod($resolvedPath, 0600);
            }
        }

        // Member login lockout: 3 attempts per 60 seconds per IP.
        RateLimiter::for('member-login', fn (Request $req) =>
            Limit::perMinute(3)
                ->by('member-login|ip:' . $req->ip() . '|id:' . $this->normalizeLoginIdentifier($req))
                ->response(fn () => response()->json([
                    'message' => 'LOCKOUT|60|Too many login attempts from this IP. Please wait 60 seconds before trying again.',
                ], 429))
        );

        // Admin/partner login lockout: 3 attempts per 60 seconds per IP.
        RateLimiter::for('admin-login', fn (Request $req) =>
            Limit::perMinute(3)
                ->by('admin-login|ip:' . $req->ip() . '|id:' . $this->normalizeLoginIdentifier($req))
                ->response(fn () => response()->json([
                    'message' => 'LOCKOUT|60|Too many login attempts from this IP. Please wait 60 seconds before trying again.',
                ], 429))
        );

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

        // Partner storefront/public web-page content is read frequently by multiple
        // components during navigation, so keep it on a dedicated, higher bucket.
        RateLimiter::for('storefront-read', fn (Request $req) =>
            Limit::perMinute(600)->by($req->ip())
        );

        // Admin or authenticated write-heavy endpoints
        RateLimiter::for('admin-write', fn (Request $req) =>
            Limit::perMinute(60)->by(($req->user()?->id ? 'u:' . $req->user()->id . '|' : '') . 'ip:' . $req->ip())
        );

        // File upload endpoints (larger body / higher abuse risk)
        RateLimiter::for('uploads', fn (Request $req) =>
            Limit::perMinute(20)->by(($req->user()?->id ? 'u:' . $req->user()->id . '|' : '') . 'ip:' . $req->ip())
        );
    }
}
