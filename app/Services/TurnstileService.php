<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TurnstileService
{
    public function verifyLogin(string $token, string $ip = ''): bool
    {
        return $this->verify($token, config('services.turnstile.login_secret'), $ip);
    }

    public function verifySignup(string $token, string $ip = ''): bool
    {
        return $this->verify($token, config('services.turnstile.signup_secret'), $ip);
    }

    public function verifyAdminLogin(string $token, string $ip = ''): bool
    {
        return $this->verify($token, config('services.turnstile.admin_login_secret'), $ip);
    }

    public function verifyForgotPassword(string $token, string $ip = ''): bool
    {
        return $this->verify($token, config('services.turnstile.forgot_password_secret'), $ip);
    }

    private function verify(string $token, ?string $secret, string $ip): bool
    {
        if (app()->environment('local', 'development', 'dev')) {
            return true;
        }

        if ($token === '' || empty($secret)) {
            return false;
        }

        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        return (bool) ($response->json('success') ?? false);
    }
}
