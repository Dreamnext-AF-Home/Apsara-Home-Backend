<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPasskey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasskeyAuthController extends Controller
{
    private const LOGIN_CHALLENGE_TTL_SECONDS = 300;
    private const REGISTER_CHALLENGE_TTL_SECONDS = 300;

    public function loginOptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => 'required|string|max:255',
        ]);

        $identifier = trim((string) $validated['identifier']);
        $customer = Customer::query()
            ->where(function ($query) use ($identifier) {
                $query
                    ->whereRaw('LOWER(c_email) = ?', [mb_strtolower($identifier, 'UTF-8')])
                    ->orWhereRaw('LOWER(c_username) = ?', [mb_strtolower($identifier, 'UTF-8')]);
            })
            ->first();

        if (! $customer) {
            throw ValidationException::withMessages([
                'identifier' => ['No account found for that email/username.'],
            ]);
        }

        $passkeys = CustomerPasskey::query()
            ->where('cp_customer_id', (int) $customer->c_userid)
            ->get();

        if ($passkeys->isEmpty()) {
            throw ValidationException::withMessages([
                'identifier' => ['No passkey is registered for this account yet.'],
            ]);
        }

        $challenge = random_bytes(32);
        $challengeToken = (string) Str::uuid();
        $rpId = $this->resolveRpId($request);
        $allowedOrigins = $this->resolveAllowedOrigins($request);

        Cache::put($this->loginChallengeCacheKey($challengeToken), [
            'customer_id' => (int) $customer->c_userid,
            'challenge' => $this->base64UrlEncode($challenge),
            'rp_id' => $rpId,
            'allowed_origins' => $allowedOrigins,
        ], now()->addSeconds(self::LOGIN_CHALLENGE_TTL_SECONDS));

        return response()->json([
            'challenge_token' => $challengeToken,
            'public_key' => [
                'challenge' => $this->base64UrlEncode($challenge),
                'rpId' => $rpId,
                'timeout' => 60000,
                'userVerification' => 'preferred',
                'allowCredentials' => $passkeys->map(function (CustomerPasskey $item) {
                    return [
                        'type' => 'public-key',
                        'id' => (string) $item->cp_credential_id,
                        'transports' => is_array($item->cp_transports) ? array_values($item->cp_transports) : [],
                    ];
                })->values(),
            ],
        ]);
    }

    public function loginVerify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => 'required|string|max:255',
            'challenge_token' => 'required|string',
            'credential.id' => 'required|string|max:255',
            'credential.type' => 'required|string|in:public-key',
            'credential.response.clientDataJSON' => 'required|string',
            'credential.response.authenticatorData' => 'required|string',
            'credential.response.signature' => 'required|string',
            'credential.response.userHandle' => 'nullable|string',
        ]);

        $challengeToken = trim((string) $validated['challenge_token']);
        $cached = Cache::get($this->loginChallengeCacheKey($challengeToken));
        if (! is_array($cached) || empty($cached['customer_id']) || empty($cached['challenge'])) {
            throw ValidationException::withMessages([
                'challenge_token' => ['Passkey sign-in session expired. Please try again.'],
            ]);
        }

        $identifier = trim((string) $validated['identifier']);
        $customer = Customer::query()
            ->where(function ($query) use ($identifier) {
                $query
                    ->whereRaw('LOWER(c_email) = ?', [mb_strtolower($identifier, 'UTF-8')])
                    ->orWhereRaw('LOWER(c_username) = ?', [mb_strtolower($identifier, 'UTF-8')]);
            })
            ->first();

        if (! $customer || (int) $customer->c_userid !== (int) ($cached['customer_id'] ?? 0)) {
            throw ValidationException::withMessages([
                'identifier' => ['Passkey sign-in session mismatch. Please try again.'],
            ]);
        }

        if ((int) ($customer->c_lockstatus ?? 0) === 1) {
            return response()->json([
                'message' => 'Your account has been banned. Please contact support for assistance.',
                'reason' => 'banned',
            ], 403);
        }

        $credentialId = (string) data_get($validated, 'credential.id');
        $passkey = CustomerPasskey::query()
            ->where('cp_customer_id', (int) $customer->c_userid)
            ->where('cp_credential_id', $credentialId)
            ->first();

        if (! $passkey) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey not recognized for this account.'],
            ]);
        }

        $clientDataJson = $this->base64UrlDecode((string) data_get($validated, 'credential.response.clientDataJSON'));
        $authenticatorData = $this->base64UrlDecode((string) data_get($validated, 'credential.response.authenticatorData'));
        $signature = $this->base64UrlDecode((string) data_get($validated, 'credential.response.signature'));
        if ($clientDataJson === null || $authenticatorData === null || $signature === null) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid passkey payload encoding.'],
            ]);
        }

        $clientData = json_decode($clientDataJson, true);
        if (! is_array($clientData)) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid clientDataJSON format.'],
            ]);
        }

        if ((string) ($clientData['type'] ?? '') !== 'webauthn.get') {
            throw ValidationException::withMessages([
                'credential' => ['Invalid passkey assertion type.'],
            ]);
        }

        if (! hash_equals((string) ($cached['challenge'] ?? ''), (string) ($clientData['challenge'] ?? ''))) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey challenge mismatch.'],
            ]);
        }

        $origin = (string) ($clientData['origin'] ?? '');
        $allowedOrigins = is_array($cached['allowed_origins'] ?? null) ? $cached['allowed_origins'] : [];
        if ($origin === '' || ! in_array($origin, $allowedOrigins, true)) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey origin is not allowed.'],
            ]);
        }

        if (strlen($authenticatorData) < 37) {
            throw ValidationException::withMessages([
                'credential' => ['Authenticator data is too short.'],
            ]);
        }

        $rpIdHash = substr($authenticatorData, 0, 32);
        $expectedRpIdHash = hash('sha256', (string) ($cached['rp_id'] ?? ''), true);
        if (! hash_equals($expectedRpIdHash, $rpIdHash)) {
            throw ValidationException::withMessages([
                'credential' => ['RP ID hash mismatch.'],
            ]);
        }

        $flags = ord($authenticatorData[32]);
        $userPresent = ($flags & 0x01) !== 0;
        if (! $userPresent) {
            throw ValidationException::withMessages([
                'credential' => ['User presence was not verified by authenticator.'],
            ]);
        }

        $signCountBinary = substr($authenticatorData, 33, 4);
        $signCountData = unpack('Ncount', $signCountBinary);
        $signCount = (int) ($signCountData['count'] ?? 0);
        $storedSignCount = (int) ($passkey->cp_sign_count ?? 0);
        if ($storedSignCount > 0 && $signCount > 0 && $signCount <= $storedSignCount) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey sign counter check failed.'],
            ]);
        }

        $verificationData = $authenticatorData . hash('sha256', $clientDataJson, true);
        $isValidSignature = openssl_verify(
            $verificationData,
            $signature,
            (string) $passkey->cp_public_key_pem,
            OPENSSL_ALGO_SHA256
        ) === 1;

        if (! $isValidSignature) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey signature verification failed.'],
            ]);
        }

        Cache::forget($this->loginChallengeCacheKey($challengeToken));

        $passkey->cp_sign_count = max($storedSignCount, $signCount);
        $passkey->cp_last_used_at = now();
        $passkey->save();

        $token = $customer->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $this->transformCustomerForLogin($customer),
            'token' => $token,
            'message' => null,
        ]);
    }

    public function registerOptions(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (! $customer instanceof Customer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:120',
        ]);

        $challenge = random_bytes(32);
        $challengeToken = (string) Str::uuid();
        $rpId = $this->resolveRpId($request);
        $rpName = trim((string) env('WEBAUTHN_RP_NAME', config('app.name', 'AF Home')));
        $allowedOrigins = $this->resolveAllowedOrigins($request);

        Cache::put($this->registerChallengeCacheKey($challengeToken), [
            'customer_id' => (int) $customer->c_userid,
            'challenge' => $this->base64UrlEncode($challenge),
            'rp_id' => $rpId,
            'allowed_origins' => $allowedOrigins,
            'name' => trim((string) ($validated['name'] ?? '')),
        ], now()->addSeconds(self::REGISTER_CHALLENGE_TTL_SECONDS));

        $userIdBytes = (string) $customer->c_userid;
        $excludeCredentials = CustomerPasskey::query()
            ->where('cp_customer_id', (int) $customer->c_userid)
            ->get()
            ->map(function (CustomerPasskey $item) {
                return [
                    'type' => 'public-key',
                    'id' => (string) $item->cp_credential_id,
                    'transports' => is_array($item->cp_transports) ? array_values($item->cp_transports) : [],
                ];
            })
            ->values();

        return response()->json([
            'challenge_token' => $challengeToken,
            'public_key' => [
                'challenge' => $this->base64UrlEncode($challenge),
                'rp' => [
                    'name' => $rpName !== '' ? $rpName : 'AF Home',
                    'id' => $rpId,
                ],
                'user' => [
                    'id' => $this->base64UrlEncode($userIdBytes),
                    'name' => (string) ($customer->c_email ?? $customer->c_username ?? 'member'),
                    'displayName' => $this->fullName($customer),
                ],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],
                    ['type' => 'public-key', 'alg' => -257],
                ],
                'timeout' => 60000,
                'attestation' => 'none',
                'excludeCredentials' => $excludeCredentials,
                'authenticatorSelection' => [
                    'residentKey' => 'preferred',
                    'userVerification' => 'preferred',
                ],
            ],
        ]);
    }

    public function registerVerify(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (! $customer instanceof Customer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'challenge_token' => 'required|string',
            'name' => 'nullable|string|max:120',
            'credential.id' => 'required|string|max:255',
            'credential.type' => 'required|string|in:public-key',
            'credential.response.clientDataJSON' => 'required|string',
            'credential.response.attestationObject' => 'required|string',
            'credential.response.publicKey' => 'required|string',
            'credential.response.publicKeyAlgorithm' => 'nullable|integer',
            'credential.response.transports' => 'nullable|array',
            'credential.response.transports.*' => 'string|max:20',
        ]);

        $challengeToken = trim((string) $validated['challenge_token']);
        $cached = Cache::get($this->registerChallengeCacheKey($challengeToken));
        if (! is_array($cached) || empty($cached['customer_id']) || empty($cached['challenge'])) {
            throw ValidationException::withMessages([
                'challenge_token' => ['Passkey registration session expired. Please try again.'],
            ]);
        }

        if ((int) ($cached['customer_id'] ?? 0) !== (int) $customer->c_userid) {
            throw ValidationException::withMessages([
                'challenge_token' => ['Passkey registration session mismatch.'],
            ]);
        }

        $clientDataJson = $this->base64UrlDecode((string) data_get($validated, 'credential.response.clientDataJSON'));
        $publicKeySpki = $this->base64UrlDecode((string) data_get($validated, 'credential.response.publicKey'));
        if ($clientDataJson === null || $publicKeySpki === null) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid passkey registration payload encoding.'],
            ]);
        }

        $clientData = json_decode($clientDataJson, true);
        if (! is_array($clientData)) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid clientDataJSON format.'],
            ]);
        }

        if ((string) ($clientData['type'] ?? '') !== 'webauthn.create') {
            throw ValidationException::withMessages([
                'credential' => ['Invalid passkey attestation type.'],
            ]);
        }

        if (! hash_equals((string) ($cached['challenge'] ?? ''), (string) ($clientData['challenge'] ?? ''))) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey challenge mismatch.'],
            ]);
        }

        $origin = (string) ($clientData['origin'] ?? '');
        $allowedOrigins = is_array($cached['allowed_origins'] ?? null) ? $cached['allowed_origins'] : [];
        if ($origin === '' || ! in_array($origin, $allowedOrigins, true)) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey origin is not allowed.'],
            ]);
        }

        $publicKeyPem = $this->spkiToPem($publicKeySpki);
        if (! openssl_pkey_get_public($publicKeyPem)) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey public key format is invalid.'],
            ]);
        }

        $credentialId = (string) data_get($validated, 'credential.id');
        if (CustomerPasskey::query()->where('cp_credential_id', $credentialId)->exists()) {
            throw ValidationException::withMessages([
                'credential' => ['This passkey is already registered.'],
            ]);
        }

        $passkeyName = trim((string) ($validated['name'] ?? ''));
        if ($passkeyName === '') {
            $passkeyName = trim((string) ($cached['name'] ?? ''));
        }

        $passkey = CustomerPasskey::query()->create([
            'cp_customer_id' => (int) $customer->c_userid,
            'cp_credential_id' => $credentialId,
            'cp_public_key_pem' => $publicKeyPem,
            'cp_name' => $passkeyName !== '' ? $passkeyName : 'My Passkey',
            'cp_transports' => data_get($validated, 'credential.response.transports', []),
            'cp_sign_count' => 0,
        ]);

        Cache::forget($this->registerChallengeCacheKey($challengeToken));

        return response()->json([
            'message' => 'Passkey registered successfully.',
            'passkey' => $this->transformPasskey($passkey),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (! $customer instanceof Customer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $rows = CustomerPasskey::query()
            ->where('cp_customer_id', (int) $customer->c_userid)
            ->orderByDesc('cp_last_used_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'passkeys' => $rows->map(fn (CustomerPasskey $row) => $this->transformPasskey($row))->values(),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        if (! $customer instanceof Customer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $row = CustomerPasskey::query()
            ->where('id', $id)
            ->where('cp_customer_id', (int) $customer->c_userid)
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Passkey not found.'], 404);
        }

        $row->delete();

        return response()->json([
            'message' => 'Passkey removed successfully.',
        ]);
    }

    private function transformPasskey(CustomerPasskey $passkey): array
    {
        return [
            'id' => (int) $passkey->id,
            'name' => (string) ($passkey->cp_name ?? 'My Passkey'),
            'credential_id' => (string) $passkey->cp_credential_id,
            'sign_count' => (int) ($passkey->cp_sign_count ?? 0),
            'last_used_at' => optional($passkey->cp_last_used_at)->toDateTimeString(),
            'created_at' => optional($passkey->created_at)->toDateTimeString(),
        ];
    }

    private function transformCustomerForLogin(Customer $customer): array
    {
        return [
            'id' => (int) $customer->c_userid,
            'name' => $this->fullName($customer),
            'email' => (string) ($customer->c_email ?? ''),
            'username' => (string) ($customer->c_username ?? ''),
            'password_change_required' => (bool) ($customer->c_password_change_required ?? false),
        ];
    }

    private function fullName(Customer $customer): string
    {
        $parts = array_values(array_filter([
            trim((string) ($customer->c_fname ?? '')),
            trim((string) ($customer->c_mname ?? '')),
            trim((string) ($customer->c_lname ?? '')),
        ], static fn (string $value): bool => $value !== ''));

        if (! empty($parts)) {
            return trim(implode(' ', $parts));
        }

        return trim((string) ($customer->name ?? 'Member'));
    }

    private function loginChallengeCacheKey(string $token): string
    {
        return 'passkey:login:' . $token;
    }

    private function registerChallengeCacheKey(string $token): string
    {
        return 'passkey:register:' . $token;
    }

    private function resolveAllowedOrigins(Request $request): array
    {
        $raw = trim((string) env('WEBAUTHN_ALLOWED_ORIGINS', ''));
        $origins = [];

        if ($raw !== '') {
            $origins = array_values(array_filter(array_map(static function (string $origin): string {
                return trim($origin);
            }, explode(',', $raw)), static fn (string $origin): bool => $origin !== ''));
        }

        $fallbackCandidates = [
            trim((string) env('NEXT_PUBLIC_APP_URL', '')),
            trim((string) env('FRONTEND_URL', '')),
            trim((string) config('app.url', '')),
            $request->getSchemeAndHttpHost(),
            'http://localhost:3000',
        ];

        foreach ($fallbackCandidates as $candidate) {
            if ($candidate !== '') {
                $origins[] = rtrim($candidate, '/');
            }
        }

        return array_values(array_unique(array_map(static fn (string $value): string => rtrim($value, '/'), $origins)));
    }

    private function resolveRpId(Request $request): string
    {
        $configured = trim((string) env('WEBAUTHN_RP_ID', ''));
        $originHeader = trim((string) $request->headers->get('Origin', ''));
        $originHost = is_string(parse_url($originHeader, PHP_URL_HOST))
            ? trim((string) parse_url($originHeader, PHP_URL_HOST))
            : '';

        if ($configured !== '') {
            // In local/dev, the configured RP ID can point to production domain.
            // Use it only when it is valid for the requesting origin host.
            if ($originHost === '' || $this->isRpIdUsableForHost($configured, $originHost)) {
                return $configured;
            }
        }

        $origins = $this->resolveAllowedOrigins($request);
        foreach ($origins as $origin) {
            $host = parse_url($origin, PHP_URL_HOST);
            if (is_string($host) && trim($host) !== '') {
                $candidateHost = trim($host);
                if ($originHost === '' || $this->isRpIdUsableForHost($candidateHost, $originHost)) {
                    return $candidateHost;
                }
            }
        }

        if ($originHost !== '') {
            return $originHost;
        }

        return $request->getHost();
    }

    private function isRpIdUsableForHost(string $rpId, string $originHost): bool
    {
        $rp = ltrim(strtolower(trim($rpId)), '.');
        $host = strtolower(trim($originHost));
        if ($rp === '' || $host === '') {
            return false;
        }

        if ($rp === $host) {
            return true;
        }

        return str_ends_with($host, '.' . $rp);
    }

    private function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $input = trim($value);
        if ($input === '') {
            return null;
        }

        $remainder = strlen($input) % 4;
        if ($remainder > 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    private function spkiToPem(string $spkiBinary): string
    {
        $base64 = chunk_split(base64_encode($spkiBinary), 64, "\n");
        return "-----BEGIN PUBLIC KEY-----\n{$base64}-----END PUBLIC KEY-----\n";
    }
}
