<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierUser;
use Illuminate\Http\Request;
use App\Mail\Supplier\SupplierPasswordResetMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierAuthController extends Controller
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

        $supplierUser = SupplierUser::query()
            ->with('supplier')
            ->where('su_username', $login)
            ->orWhere('su_email', $login)
            ->first();

        if (! $supplierUser) {
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
            throw ValidationException::withMessages([
                'login' => ['Invalid username/email or password.'],
            ]);
        }

        $token = $supplierUser->createToken('supplier_auth_token')->plainTextToken;

        return response()->json([
            'user' => $this->transform($supplierUser),
            'token' => $token,
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

    private function getResetPayload(string $token): ?array
    {
        $payload = Cache::get($this->resetCacheKey($token));
        return is_array($payload) ? $payload : null;
    }

    private function resetCacheKey(string $token): string
    {
        return 'supplier:password-reset:' . $token;
    }
}
