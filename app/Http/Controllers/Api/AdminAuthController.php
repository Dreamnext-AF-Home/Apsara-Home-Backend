<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\Admin\AdminPasswordResetMail;
use App\Models\Admin;
use App\Support\AdminAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    private const RESET_TTL_MINUTES = 60;

    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $login = trim((string) $request->login);
        $password = (string) $request->password;

        $admin = Admin::query()
            ->where('user_email', $login)
            ->orWhere('username', $login)
            ->first();

        if (! $admin) {
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
            throw ValidationException::withMessages([
                'login' => ['Invalid email/username or password.'],
            ]);
        }

        $token = $admin->createToken('admin_auth_token')->plainTextToken;
        $role = AdminAccess::roleFromLevel((int) $admin->user_level_id);

        return response()->json([
            'user' => [
                'id' => (int) $admin->id,
                'name' => (string) ($admin->fname ?: $admin->username),
                'email' => (string) $admin->user_email,
                'role' => $role,
                'user_level_id' => (int) $admin->user_level_id,
                'supplier_id' => $admin->supplier_id ? (int) $admin->supplier_id : null,
                'admin_permissions' => AdminAccess::permissionsForAdmin($admin),
            ],
            'token' => $token,
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
                name: $payload['name'],
                email: $payload['email'],
                resetUrl: $resetUrl,
                expiresAt: $expiresAt->toDayDateTimeString(),
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
            'role' => AdminAccess::roleFromLevel((int) $admin->user_level_id),
            'user_level_id' => (int) $admin->user_level_id,
            'supplier_id' => $admin->supplier_id ? (int) $admin->supplier_id : null,
            'admin_permissions' => AdminAccess::permissionsForAdmin($admin),
        ]);
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
