<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\Auth\PortalLoginOtpMail;
use App\Models\SystemSetting;
use App\Models\SupplierUser;
use Illuminate\Http\Request;
use App\Mail\Supplier\SupplierPasswordResetMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class SupplierAuthController extends Controller
{
    private const RESET_TTL_MINUTES = 60;
    private const LOGIN_OTP_TTL_MINUTES = 10;

    public function login(Request $request)
    {
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

        $normalizedLogin = mb_strtolower($login, 'UTF-8');

        $supplierUser = SupplierUser::query()
            ->with('supplier')
            ->where(function ($query) use ($normalizedLogin) {
                $query
                    ->whereRaw('LOWER(COALESCE(su_username, \'\')) = ?', [$normalizedLogin])
                    ->orWhereRaw('LOWER(COALESCE(su_email, \'\')) = ?', [$normalizedLogin]);
            })
            ->first();

        if (! $supplierUser) {
            $this->registerFailedLoginAttempt($attemptIdentifier);
            throw ValidationException::withMessages([
                'login' => ['Invalid username/email or password.'],
            ]);
        }

        $stored = (string) $supplierUser->su_password;
        $hashMatch = false;
        if ($stored !== '' && password_get_info($stored)['algo'] !== null) {
            $hashMatch = Hash::check($password, $stored);
        }
        $legacyDirectMatch = hash_equals($stored, $password);

        if (! $hashMatch && ! $legacyDirectMatch) {
            $this->registerFailedLoginAttempt($attemptIdentifier);
            throw ValidationException::withMessages([
                'login' => ['Invalid username/email or password.'],
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
                    supplierUser: $supplierUser,
                );

                return response()->json([
                    'requires_otp' => true,
                    'otp_challenge_token' => $challengeToken,
                    'message' => 'A 6-digit OTP has been sent to your email.',
                ], 202);
            }

            $this->validateLoginOtpChallenge(
                challengeToken: $challengeToken,
                supplierUser: $supplierUser,
                otp: $otp,
            );
        }

        // Some DB snapshots have nullable su_id (no default/sequence). Sanctum
        // requires a non-null tokenable_id, so we auto-assign an ID on login.
        if (! $supplierUser->getKey()) {
            $this->ensureSupplierUserHasId($supplierUser);
            $supplierUser = SupplierUser::query()
                ->with('supplier')
                ->where('su_username', $supplierUser->su_username)
                ->orWhere('su_email', $supplierUser->su_email)
                ->first();
        }
        if (! $supplierUser || ! $supplierUser->getKey()) {
            throw ValidationException::withMessages([
                'login' => ['Supplier account record is missing a valid ID. Please contact support.'],
            ]);
        }

        $token = $supplierUser->createToken('supplier_auth_token')->plainTextToken;

        return response()->json([
            'user' => array_merge($this->transform($supplierUser), [
                'session_timeout_minutes' => $this->getSessionTimeoutMinutes(),
            ]),
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
        if (! is_array($cached) || empty($cached['supplier_user_id'])) {
            throw ValidationException::withMessages([
                'otp_challenge_token' => ['The OTP session has expired. Please sign in again.'],
            ]);
        }

        $supplierUser = SupplierUser::query()
            ->where('su_id', (int) $cached['supplier_user_id'])
            ->first();
        if (! $supplierUser) {
            Cache::forget($this->loginOtpCacheKey($challengeToken));
            throw ValidationException::withMessages([
                'otp_challenge_token' => ['Supplier account not found. Please sign in again.'],
            ]);
        }

        $attempts = (int) ($cached['attempts'] ?? 0);
        $this->issueLoginOtpChallenge(
            challengeToken: $challengeToken,
            supplierUser: $supplierUser,
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

        $supplierUser = SupplierUser::query()
            ->with('supplier')
            ->where('su_email', trim((string) $validated['email']))
            ->first();

        if ($supplierUser) {
            $token = Str::random(64);
            $expiresAt = now()->addMinutes(self::RESET_TTL_MINUTES);
            $payload = [
                'supplier_user_id' => (int) $supplierUser->su_id,
                'email' => (string) $supplierUser->su_email,
                'name' => (string) ($supplierUser->su_fullname ?: $supplierUser->su_username),
                'supplier_name' => (string) ($supplierUser->supplier?->s_company ?: $supplierUser->supplier?->s_name ?: 'your supplier account'),
                'expires_at' => $expiresAt->toIso8601String(),
            ];

            Cache::put($this->resetCacheKey($token), $payload, $expiresAt);

            $resetUrl = sprintf(
                '%s/supplier/reset-password?token=%s',
                rtrim((string) env('FRONTEND_URL', config('app.url')), '/'),
                urlencode($token)
            );

            Mail::to($payload['email'])->send(new SupplierPasswordResetMail(
                name: $payload['name'],
                supplierName: $payload['supplier_name'],
                resetUrl: $resetUrl,
                expiresAt: $expiresAt->toDayDateTimeString(),
            ));
        }

        return response()->json([
            'message' => 'If that email exists in our supplier records, a reset link has been sent.',
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
                'supplier_name' => (string) $payload['supplier_name'],
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

        $supplierUser = SupplierUser::query()->where('su_id', (int) $payload['supplier_user_id'])->first();
        if (! $supplierUser) {
            Cache::forget($this->resetCacheKey((string) $validated['token']));

            throw ValidationException::withMessages([
                'token' => ['Supplier account could not be found.'],
            ]);
        }

        $supplierUser->forceFill([
            'su_password' => Hash::make((string) $validated['password']),
        ])->save();

        Cache::forget($this->resetCacheKey((string) $validated['token']));

        return response()->json([
            'message' => 'Your supplier password has been reset. You may now sign in.',
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Supplier logged out successfully.']);
    }

    public function me(Request $request)
    {
        $supplierUser = $request->user();
        if (! $supplierUser instanceof SupplierUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $supplierUser->loadMissing('supplier');

        return response()->json($this->transform($supplierUser));
    }

    private function transform(SupplierUser $supplierUser): array
    {
        return [
            'id' => (int) $supplierUser->su_id,
            'name' => (string) ($supplierUser->su_fullname ?: $supplierUser->su_username),
            'email' => (string) ($supplierUser->su_email ?? ''),
            'role' => 'supplier',
            'supplier_id' => (int) $supplierUser->su_supplier,
            'supplier_name' => $supplierUser->supplier?->s_company ?: $supplierUser->supplier?->s_name,
            'username' => (string) $supplierUser->su_username,
            'level_type' => (int) ($supplierUser->su_level_type ?? 0),
            'is_main_supplier' => (int) ($supplierUser->su_level_type ?? 0) === 1,
        ];
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

    private function issueLoginOtpChallenge(string $challengeToken, SupplierUser $supplierUser, int $attempts = 0): void
    {
        $email = trim((string) $supplierUser->su_email);
        if ($email === '') {
            throw ValidationException::withMessages([
                'login' => ['This account has no email configured for OTP verification.'],
            ]);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(self::LOGIN_OTP_TTL_MINUTES);

        Cache::put($this->loginOtpCacheKey($challengeToken), [
            'supplier_user_id' => (int) $supplierUser->su_id,
            'otp_hash' => Hash::make($otp),
            'attempts' => $attempts,
        ], $expiresAt);

        try {
            Mail::mailer('resend')->to($email)->send(new PortalLoginOtpMail(
                otp: $otp,
                email: $email,
                portalLabel: 'Supplier Portal',
                expiresInMinutes: (string) self::LOGIN_OTP_TTL_MINUTES,
            ));
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'login' => ['Unable to send OTP email right now. Please try again shortly.'],
            ]);
        }
    }

    private function ensureSupplierUserHasId(SupplierUser $supplierUser): void
    {
        if ($supplierUser->getKey()) {
            return;
        }

        DB::transaction(function () use ($supplierUser) {
            // Lock the current "max id" row to reduce race conditions.
            $lastId = DB::table('tbl_supplier_user')
                ->whereNotNull('su_id')
                ->orderByDesc('su_id')
                ->lockForUpdate()
                ->value('su_id');

            $nextId = ((int) ($lastId ?? 0)) + 1;

            DB::table('tbl_supplier_user')
                ->where('su_username', (string) $supplierUser->su_username)
                ->whereNull('su_id')
                ->update(['su_id' => $nextId]);
        });
    }

    private function validateLoginOtpChallenge(string $challengeToken, SupplierUser $supplierUser, string $otp): void
    {
        $cached = Cache::get($this->loginOtpCacheKey($challengeToken));
        if (! is_array($cached) || empty($cached['otp_hash']) || empty($cached['supplier_user_id'])) {
            throw ValidationException::withMessages([
                'otp' => ['OTP session expired. Please sign in again.'],
            ]);
        }

        if ((int) $cached['supplier_user_id'] !== (int) $supplierUser->su_id) {
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

    private function getResetPayload(string $token): ?array
    {
        $payload = Cache::get($this->resetCacheKey($token));
        return is_array($payload) ? $payload : null;
    }

    private function resetCacheKey(string $token): string
    {
        return 'supplier:password-reset:' . $token;
    }

    private function loginOtpCacheKey(string $challengeToken): string
    {
        return 'supplier:login-otp:' . $challengeToken;
    }

    private function loginAttemptsKey(string $attemptIdentifier): string
    {
        return 'supplier:login-attempts:' . sha1($attemptIdentifier);
    }

    private function loginLockKey(string $attemptIdentifier): string
    {
        return 'supplier:login-lock:' . sha1($attemptIdentifier);
    }

}
