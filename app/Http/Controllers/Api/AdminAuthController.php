<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\Auth\PortalLoginOtpMail;
use App\Mail\Admin\AdminPasswordResetMail;
use App\Models\Admin;
use App\Models\SystemSetting;
use App\Support\AdminAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class AdminAuthController extends Controller
{
    private const RESET_TTL_MINUTES = 60;
    private const LOGIN_OTP_TTL_MINUTES = 10;

    public function login(Request $request)
    {
        $turnstileToken = trim((string) $request->input('cf_turnstile_response', ''));
        if (!(new \App\Services\TurnstileService())->verifyAdminLogin($turnstileToken, (string) $request->ip())) {
            return response()->json(['message' => 'Bot verification failed.'], 422);
        }

        $otpValue = trim((string) $request->input('otp', ''));
        $challengeTokenValue = trim((string) $request->input('otp_challenge_token', ''));
        $otpLower = strtolower($otpValue);
        $challengeLower = strtolower($challengeTokenValue);
        $request->merge([
            'otp' => (!in_array($otpLower, ['', 'undefined', 'null'], true)) ? $otpValue : null,
            'otp_challenge_token' => (!in_array($challengeLower, ['', 'undefined', 'null'], true)) ? $challengeTokenValue : null,
        ]);

        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
            'otp' => 'nullable|string|size:6',
            'otp_challenge_token' => 'nullable|string',
        ]);

        $login = trim((string) $request->login);
        $password = (string) $request->password;
        $attemptIdentifier = mb_strtolower($login, 'UTF-8') . '|' . (string) $request->ip();
        $this->assertLoginNotLocked($attemptIdentifier);

        $admin = Admin::query()
            ->where('user_email', $login)
            ->orWhere('username', $login)
            ->first();

        if (! $admin) {
            $this->registerFailedLoginAttempt($attemptIdentifier);
            throw ValidationException::withMessages([
                'login' => ['Invalid email/username or password.'],
            ]);
        }

        $stored = (string) $admin->passworde;
        $hashMatch = false;
        if ($stored !== '' && password_get_info($stored)['algo'] !== null) {
            $hashMatch = Hash::check($password, $stored);
        }
        $legacyDirectMatch = hash_equals($stored, $password);

        if (! $hashMatch && ! $legacyDirectMatch) {
            $this->registerFailedLoginAttempt($attemptIdentifier);
            throw ValidationException::withMessages([
                'login' => ['Invalid email/username or password.'],
            ]);
        }

        $this->clearFailedLoginAttempts($attemptIdentifier);

        if ($this->isLoginTwoFactorEnabled()) {
            $otp = trim((string) $request->input('otp', ''));
            $challengeToken = trim((string) $request->input('otp_challenge_token', ''));

            if ($otp === '' || $challengeToken === '') {
                $challengeToken = (string) Str::uuid();
                $this->issueLoginOtpChallenge(
                    challengeToken: $challengeToken,
                    admin: $admin,
                );

                return response()->json([
                    'requires_otp' => true,
                    'otp_challenge_token' => $challengeToken,
                    'message' => 'A 6-digit OTP has been sent to your email.',
                ], 202);
            }

            $this->validateLoginOtpChallenge(
                challengeToken: $challengeToken,
                admin: $admin,
                otp: $otp,
            );
        }

        $token = $admin->createToken('admin_auth_token')->plainTextToken;
        $role = AdminAccess::roleFromLevel((int) $admin->user_level_id);
        $storefrontIds = $this->resolveStorefrontIds($admin);

        return response()->json([
            'user' => [
                'id' => (int) $admin->id,
                'name' => (string) ($admin->fname ?: $admin->username),
                'email' => (string) $admin->user_email,
                'role' => $role,
                'user_level_id' => (int) $admin->user_level_id,
                'supplier_id' => $admin->supplier_id ? (int) $admin->supplier_id : null,
                'admin_permissions' => AdminAccess::permissionsForAdmin($admin),
                'storefront_ids' => $storefrontIds,
                'avatar_url' => (string) ($admin->avatar_url ?? ''),
                'is_banned' => (bool) $admin->is_banned,
                'session_timeout_minutes' => $this->getSessionTimeoutMinutes(),
            ],
            'token' => $token,
        ]);
    }

    public function resendLoginOtp(Request $request)
    {
        $request->validate([
            'otp_challenge_token' => 'required|string',
        ]);

        $challengeToken = trim((string) $request->input('otp_challenge_token'));
        $cached = Cache::get($this->loginOtpCacheKey($challengeToken));
        if (! is_array($cached) || empty($cached['admin_id'])) {
            throw ValidationException::withMessages([
                'otp_challenge_token' => ['The OTP session has expired. Please sign in again.'],
            ]);
        }

        $admin = Admin::query()->where('id', (int) $cached['admin_id'])->first();
        if (! $admin) {
            Cache::forget($this->loginOtpCacheKey($challengeToken));
            throw ValidationException::withMessages([
                'otp_challenge_token' => ['Admin account not found. Please sign in again.'],
            ]);
        }

        $attempts = (int) ($cached['attempts'] ?? 0);
        $this->issueLoginOtpChallenge(
            challengeToken: $challengeToken,
            admin: $admin,
            attempts: $attempts,
        );

        return response()->json([
            'requires_otp' => true,
            'otp_challenge_token' => $challengeToken,
            'message' => 'A new OTP has been sent to your email.',
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $admin = Admin::query()
            ->where('user_email', trim((string) $validated['email']))
            ->first();

        if ($admin) {
            $token = Str::random(64);
            $expiresAt = now()->addMinutes(self::RESET_TTL_MINUTES);
            $payload = [
                'admin_id' => (int) $admin->id,
                'email' => (string) $admin->user_email,
                'name' => (string) ($admin->fname ?: $admin->username),
                'expires_at' => $expiresAt->toIso8601String(),
            ];

            Cache::put($this->resetCacheKey($token), $payload, $expiresAt);

            $resetUrl = sprintf(
                '%s/admin/reset-password?token=%s',
                rtrim((string) env('FRONTEND_URL', config('app.url')), '/'),
                urlencode($token)
            );

            Mail::to($payload['email'])->send(new AdminPasswordResetMail(
                $payload['name'],
                $payload['email'],
                $resetUrl,
                $expiresAt->toDayDateTimeString(),
            ));
        }

        return response()->json([
            'message' => 'If that email exists in our admin records, a reset link has been sent.',
        ]);
    }

    public function showResetToken(string $token)
    {
        $payload = $this->getResetPayload($token);
        if (! $payload) {
            return response()->json(['message' => 'Reset link is invalid or expired.'], 404);
        }

        return response()->json([
            'reset' => [
                'email' => (string) $payload['email'],
                'name' => (string) $payload['name'],
                'expires_at' => (string) $payload['expires_at'],
            ],
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $payload = $this->getResetPayload((string) $validated['token']);
        if (! $payload) {
            throw ValidationException::withMessages([
                'token' => ['Reset link is invalid or expired.'],
            ]);
        }

        $admin = Admin::query()->where('id', (int) $payload['admin_id'])->first();
        if (! $admin) {
            Cache::forget($this->resetCacheKey((string) $validated['token']));

            throw ValidationException::withMessages([
                'token' => ['Admin account could not be found.'],
            ]);
        }

        $admin->forceFill([
            'passworde' => Hash::make((string) $validated['password']),
        ])->save();

        Cache::forget($this->resetCacheKey((string) $validated['token']));

        return response()->json([
            'message' => 'Your admin password has been reset. You may now sign in.',
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Admin logged out successfully.']);
    }

    public function me(Request $request)
    {
        /** @var Admin|null $admin */
        $admin = $request->user();

        if (! $admin) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'id' => (int) $admin->id,
            'name' => (string) ($admin->fname ?: $admin->username),
            'email' => (string) $admin->user_email,
            'username' => (string) $admin->username,
            'role' => AdminAccess::roleFromLevel((int) $admin->user_level_id),
            'user_level_id' => (int) $admin->user_level_id,
            'supplier_id' => $admin->supplier_id ? (int) $admin->supplier_id : null,
            'admin_permissions' => AdminAccess::permissionsForAdmin($admin),
            'storefront_ids' => $this->resolveStorefrontIds($admin),
            'avatar_url' => (string) ($admin->avatar_url ?? ''),
        ]);
    }

    public function updateMe(Request $request)
    {
        /** @var Admin|null $admin */
        $admin = $request->user();

        if (! $admin) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'avatar_url' => 'sometimes|nullable|url|max:1200',
        ]);

        if (array_key_exists('name', $validated)) {
            $admin->fname = trim((string) $validated['name']) !== '' ? trim((string) $validated['name']) : $admin->fname;
        }

        if (array_key_exists('avatar_url', $validated)) {
            $admin->avatar_url = $validated['avatar_url'] ?: null;
        }

        try {
            $admin->save();
        } catch (QueryException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'avatar_url')) {
                return response()->json([
                    'message' => 'Admin profile picture support is not ready on the backend yet. Please run php artisan migrate first.',
                ], 500);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'Admin profile updated successfully.',
            'profile' => [
                'id' => (int) $admin->id,
                'name' => (string) ($admin->fname ?: $admin->username),
                'email' => (string) $admin->user_email,
                'role' => AdminAccess::roleFromLevel((int) $admin->user_level_id),
                'user_level_id' => (int) $admin->user_level_id,
                'supplier_id' => $admin->supplier_id ? (int) $admin->supplier_id : null,
                'admin_permissions' => AdminAccess::permissionsForAdmin($admin),
                'storefront_ids' => $this->resolveStorefrontIds($admin),
                'avatar_url' => (string) ($admin->avatar_url ?? ''),
            ],
        ]);
    }

    private function resolveStorefrontIds(?Admin $admin): array
    {
        if (! $admin || (int) $admin->user_level_id !== 4) {
            return [];
        }

        $raw = $admin->admin_permissions ?? [];
        if (! is_array($raw)) {
            if (is_string($admin->admin_permissions) && trim($admin->admin_permissions) !== '') {
                $decoded = json_decode($admin->admin_permissions, true);
                if (is_array($decoded)) {
                    $raw = $decoded;
                } else {
                    return [];
                }
            } else {
                return [];
            }
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id) => is_numeric($id) ? (int) $id : null,
            $raw,
        ), static fn ($id) => is_int($id) && $id > 0)));

        return $ids;
    }

    private function isLoginTwoFactorEnabled(): bool
    {
        return (bool) ($this->getSecuritySettings()->enable_2fa ?? false);
    }

    private function getMaxLoginAttempts(): int
    {
        $value = (int) ($this->getSecuritySettings()->max_login_attempts ?? 5);
        return max(1, min($value, 20));
    }

    private function getSessionTimeoutMinutes(): int
    {
        $value = (int) ($this->getSecuritySettings()->session_timeout_minutes ?? 60);
        return max(5, min($value, 1440));
    }

    private function getSecuritySettings(): SystemSetting
    {
        $settings = SystemSetting::query()->first();
        return $settings ?? new SystemSetting();
    }

    private function assertLoginNotLocked(string $attemptIdentifier): void
    {
        $lockRaw = Cache::get($this->loginLockKey($attemptIdentifier));
        if (! is_string($lockRaw) || trim($lockRaw) === '') {
            return;
        }

        try {
            $lockUntil = Carbon::parse($lockRaw);
        } catch (\Throwable $e) {
            report($e);
            $this->clearFailedLoginAttempts($attemptIdentifier);
            return;
        }

        if (! $lockUntil->isFuture()) {
            $this->clearFailedLoginAttempts($attemptIdentifier);
            return;
        }

        $remainingSeconds = max(1, $lockUntil->timestamp - now()->timestamp);
        throw ValidationException::withMessages([
            'login' => ["LOCKOUT|{$remainingSeconds}|Too many login attempts. Try again in {$remainingSeconds} seconds."],
        ]);
    }

    private function registerFailedLoginAttempt(string $attemptIdentifier): void
    {
        $timeoutSeconds = $this->getSessionTimeoutMinutes();
        $maxAttempts = $this->getMaxLoginAttempts();
        $attemptsKey = $this->loginAttemptsKey($attemptIdentifier);
        $now = now();
        $attemptState = Cache::get($attemptsKey);
        $attempts = 1;
        $attemptWindowUntil = $now->copy()->addSeconds($timeoutSeconds);

        if (is_array($attemptState) && isset($attemptState['count'], $attemptState['expires_at'])) {
            try {
                $existingWindowUntil = Carbon::parse((string) $attemptState['expires_at']);
                if ($existingWindowUntil->isFuture()) {
                    $attempts = ((int) $attemptState['count']) + 1;
                    $attemptWindowUntil = $existingWindowUntil;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        Cache::put($attemptsKey, [
            'count' => $attempts,
            'expires_at' => $attemptWindowUntil->toIso8601String(),
        ], $attemptWindowUntil);

        if ($attempts >= $maxAttempts) {
            $lockUntil = now()->addSeconds($timeoutSeconds);
            Cache::put($this->loginLockKey($attemptIdentifier), $lockUntil->toIso8601String(), $lockUntil);
        }
    }

    private function clearFailedLoginAttempts(string $attemptIdentifier): void
    {
        Cache::forget($this->loginAttemptsKey($attemptIdentifier));
        Cache::forget($this->loginLockKey($attemptIdentifier));
    }

    private function issueLoginOtpChallenge(string $challengeToken, Admin $admin, int $attempts = 0): void
    {
        $email = trim((string) $admin->user_email);
        if ($email === '') {
            throw ValidationException::withMessages([
                'login' => ['This account has no email configured for OTP verification.'],
            ]);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(self::LOGIN_OTP_TTL_MINUTES);

        Cache::put($this->loginOtpCacheKey($challengeToken), [
            'admin_id' => (int) $admin->id,
            'otp_hash' => Hash::make($otp),
            'attempts' => $attempts,
        ], $expiresAt);

        try {
            Mail::mailer('resend')->to($email)->send(new PortalLoginOtpMail(
                otp: $otp,
                email: $email,
                portalLabel: 'Admin Portal',
                expiresInMinutes: (string) self::LOGIN_OTP_TTL_MINUTES,
            ));
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'login' => ['Unable to send OTP email right now. Please try again shortly.'],
            ]);
        }
    }

    private function validateLoginOtpChallenge(string $challengeToken, Admin $admin, string $otp): void
    {
        $cached = Cache::get($this->loginOtpCacheKey($challengeToken));
        if (! is_array($cached) || empty($cached['otp_hash']) || empty($cached['admin_id'])) {
            throw ValidationException::withMessages([
                'otp' => ['OTP session expired. Please sign in again.'],
            ]);
        }

        if ((int) $cached['admin_id'] !== (int) $admin->id) {
            throw ValidationException::withMessages([
                'otp' => ['OTP session mismatch. Please sign in again.'],
            ]);
        }

        $attempts = (int) ($cached['attempts'] ?? 0);
        if (! Hash::check($otp, (string) $cached['otp_hash'])) {
            $attempts++;
            if ($attempts >= $this->getMaxLoginAttempts()) {
                Cache::forget($this->loginOtpCacheKey($challengeToken));
                throw ValidationException::withMessages([
                    'otp' => ['Too many invalid OTP attempts. Please sign in again.'],
                ]);
            }

            $cached['attempts'] = $attempts;
            Cache::put(
                $this->loginOtpCacheKey($challengeToken),
                $cached,
                now()->addMinutes(self::LOGIN_OTP_TTL_MINUTES),
            );

            throw ValidationException::withMessages([
                'otp' => ['Invalid OTP code.'],
            ]);
        }

        Cache::forget($this->loginOtpCacheKey($challengeToken));
    }

    private function loginOtpCacheKey(string $challengeToken): string
    {
        return 'admin:login-otp:' . $challengeToken;
    }

    private function loginAttemptsKey(string $attemptIdentifier): string
    {
        return 'admin:login-attempts:' . sha1($attemptIdentifier);
    }

    private function loginLockKey(string $attemptIdentifier): string
    {
        return 'admin:login-lock:' . sha1($attemptIdentifier);
    }

    private function getResetPayload(string $token): ?array
    {
        $payload = Cache::get($this->resetCacheKey($token));
        return is_array($payload) ? $payload : null;
    }

    private function resetCacheKey(string $token): string
    {
        return 'admin:password-reset:' . $token;
    }
}
