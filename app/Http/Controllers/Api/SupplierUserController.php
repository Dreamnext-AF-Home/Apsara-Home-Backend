<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\Supplier\SupplierInviteMail;
use App\Models\Admin;
use App\Models\Supplier;
use App\Models\SupplierUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierUserController extends Controller
{
    private const INVITE_TTL_MINUTES = 1440;
    private const INVITE_CACHE_STORE = 'database';

    public function index(Request $request)
    {
        $actor = $this->resolveActor($request);
        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $supplier = $this->resolveTargetSupplier($actor, [
            'supplier_id' => $request->query('supplier_id'),
        ]);

        $users = SupplierUser::query()
            ->where('su_supplier', (int) $supplier->s_id)
            ->orderByDesc('su_id')
            ->get()
            ->map(fn (SupplierUser $user) => [
                'id' => (int) $user->su_id,
                'supplier_id' => (int) $user->su_supplier,
                'fullname' => (string) ($user->su_fullname ?: ''),
                'username' => (string) ($user->su_username ?: ''),
                'email' => (string) ($user->su_email ?? ''),
                'level_type' => (int) ($user->su_level_type ?? 0),
                'is_main_supplier' => (int) ($user->su_level_type ?? 0) === 1,
                'role_label' => (int) ($user->su_level_type ?? 0) === 1 ? 'Main Supplier' : 'Sub Supplier',
            ])
            ->values();

        return response()->json([
            'supplier_id' => (int) $supplier->s_id,
            'users' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $actor = $this->resolveActor($request);
        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'supplier_id' => 'nullable|integer|exists:tbl_supplier,s_id',
            'fullname' => 'required|string|max:85',
            'username' => 'required|string|max:45',
            'email' => 'nullable|email|max:255',
            'level_type' => 'nullable|integer|in:0,1',
        ]);

        if ($actor instanceof SupplierUser && (int) ($actor->su_level_type ?? 0) !== 1) {
            return response()->json([
                'message' => 'Only the main supplier account can invite sub-supplier users.',
            ], 403);
        }

        $supplier = $this->resolveTargetSupplier($actor, $validated);

        $normalizedEmail = trim((string) ($validated['email'] ?? ''));
        $this->ensureUniqueSupplierCredentials(
            username: (string) $validated['username'],
            email: $normalizedEmail !== '' ? $normalizedEmail : null,
        );

        $response = $this->createInviteResponse($validated, $supplier, $actor);

        return response()->json($response, 201);
    }

    public function showInvite(string $token)
    {
        $payload = $this->getInvitePayload($token);
        if (! $payload) {
            return response()->json(['message' => 'Invite link is invalid or expired.'], 404);
        }

        return response()->json([
            'invite' => [
                'fullname' => (string) $payload['fullname'],
                'username' => (string) $payload['username'],
                'email' => (string) ($payload['email'] ?? ''),
                'supplier_id' => (int) $payload['supplier_id'],
                'supplier_name' => (string) $payload['supplier_name'],
                'expires_at' => (string) $payload['expires_at'],
            ],
        ]);
    }

    public function acceptInvite(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $payload = $this->getInvitePayload((string) $validated['token']);
        if (! $payload) {
            throw ValidationException::withMessages([
                'token' => ['Invite link is invalid or expired.'],
            ]);
        }

        $payloadEmail = trim((string) ($payload['email'] ?? ''));
        $duplicateField = $this->findDuplicateField(
            username: (string) $payload['username'],
            email: $payloadEmail !== '' ? $payloadEmail : null,
        );

        if ($duplicateField) {
            $this->inviteCacheStore()->forget($this->inviteCacheKey((string) $validated['token']));

            return response()->json([
                'message' => $duplicateField === 'username'
                    ? 'This supplier username is already in use.'
                    : 'This supplier email is already in use.',
            ], 409);
        }

        $supplierUser = SupplierUser::query()->create([
            'su_level_type' => (int) ($payload['level_type'] ?? 0),
            'su_supplier' => (int) $payload['supplier_id'],
            'su_fullname' => trim((string) $payload['fullname']),
            'su_username' => trim((string) $payload['username']),
            'su_password' => Hash::make((string) $validated['password']),
            'su_email' => $payloadEmail,
            'su_date_created' => now(),
            'su_PIN' => 'N/A',
            'su_ASESSION_STAT' => '0',
            'su_last_ipadd' => '0',
            'su_last_loginloc' => '0',
        ]);

        $this->inviteCacheStore()->forget($this->inviteCacheKey((string) $validated['token']));

        return response()->json([
            'message' => 'Your supplier account is now active. You may sign in.',
            'user' => [
                'id' => (int) $supplierUser->su_id,
                'supplier_id' => (int) $supplierUser->su_supplier,
                'username' => (string) $supplierUser->su_username,
                'email' => (string) ($supplierUser->su_email ?? ''),
            ],
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $actor = $this->resolveActor($request);
        if (! $actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = SupplierUser::query()->where('su_id', $id);

        if ($actor instanceof SupplierUser) {
            if ((int) ($actor->su_level_type ?? 0) !== 1) {
                return response()->json([
                    'message' => 'Only the main supplier account can manage supplier users.',
                ], 403);
            }
            $query->where('su_supplier', (int) $actor->su_supplier);
        } elseif ($actor instanceof Admin && isset($actor->supplier_id) && (int) ($actor->supplier_id ?? 0) > 0) {
            $query->where('su_supplier', (int) $actor->supplier_id);
        }

        $supplierUser = $query->first();
        if (! $supplierUser) {
            return response()->json(['message' => 'Supplier user not found.'], 404);
        }

        if ($actor instanceof SupplierUser && (int) $actor->su_id === (int) $supplierUser->su_id) {
            return response()->json(['message' => 'You cannot delete your own supplier portal account.'], 422);
        }

        $supplierUser->delete();

        return response()->json([
            'message' => 'Supplier user removed successfully.',
        ]);
    }

    private function createInviteResponse(array $validated, Supplier $supplier, Admin|SupplierUser $actor): array
    {
        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::INVITE_TTL_MINUTES);
        $email = trim((string) ($validated['email'] ?? ''));
        $payload = [
            'supplier_id' => (int) $supplier->s_id,
            'supplier_name' => (string) ($supplier->s_company ?: $supplier->s_name),
            'fullname' => trim((string) $validated['fullname']),
            'username' => trim((string) $validated['username']),
            'email' => $email,
            'level_type' => $actor instanceof Admin
                ? (int) ($validated['level_type'] ?? 1)
                : 0,
            'created_by' => $actor instanceof Admin ? (int) $actor->id : null,
            'created_by_supplier_user' => $actor instanceof SupplierUser ? (int) $actor->su_id : null,
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $this->inviteCacheStore()->forever($this->inviteCacheKey($token), $payload);

        $setupUrl = sprintf(
            '%s/supplier-setup?token=%s',
            rtrim((string) env('FRONTEND_URL', config('app.url')), '/'),
            urlencode($token)
        );

        if ($email !== '') {
            Mail::to($email)->send(new SupplierInviteMail(
                name: $payload['fullname'],
                email: $email,
                supplierName: $payload['supplier_name'],
                setupUrl: $setupUrl,
                expiresAt: $expiresAt->toDayDateTimeString(),
            ));
        }

        return [
            'message' => $email !== ''
                ? 'Supplier invite sent successfully.'
                : 'Supplier invite link created successfully.',
            'setup_url' => $setupUrl,
            'delivery' => $email !== '' ? 'email_and_link' : 'link_only',
            'invite' => [
                'supplier_id' => (int) $supplier->s_id,
                'supplier_name' => (string) ($supplier->s_company ?: $supplier->s_name),
                'fullname' => $payload['fullname'],
                'username' => $payload['username'],
                'email' => $email !== '' ? $email : null,
                'level_type' => (int) $payload['level_type'],
                'expires_at' => $payload['expires_at'],
            ],
        ];
    }

    private function resolveActor(Request $request): Admin|SupplierUser|null
    {
        $user = $request->user();
        return $user instanceof Admin || $user instanceof SupplierUser ? $user : null;
    }

    private function resolveTargetSupplier(Admin|SupplierUser $actor, array $validated): Supplier
    {
        if ($actor instanceof SupplierUser) {
            return Supplier::query()->where('s_id', (int) $actor->su_supplier)->firstOrFail();
        }

        if (! isset($validated['supplier_id'])) {
            throw ValidationException::withMessages([
                'supplier_id' => ['Supplier company is required.'],
            ]);
        }

        return Supplier::query()->where('s_id', (int) $validated['supplier_id'])->firstOrFail();
    }

    private function getInvitePayload(string $token): ?array
    {
        $payload = $this->inviteCacheStore()->get($this->inviteCacheKey($token));
        if (! is_array($payload)) {
            return null;
        }

        $expiresAt = isset($payload['expires_at']) ? Carbon::parse((string) $payload['expires_at']) : null;
        if (! $expiresAt || $expiresAt->isPast()) {
            $this->inviteCacheStore()->forget($this->inviteCacheKey($token));
            return null;
        }

        return $payload;
    }

    private function inviteCacheKey(string $token): string
    {
        return 'supplier:invite:' . $token;
    }

    private function inviteCacheStore()
    {
        return Cache::store(self::INVITE_CACHE_STORE);
    }

    private function ensureUniqueSupplierCredentials(string $username, ?string $email = null): void
    {
        $duplicateField = $this->findDuplicateField($username, $email);
        if (! $duplicateField) {
            return;
        }

        throw ValidationException::withMessages([
            $duplicateField => [
                $duplicateField === 'username'
                    ? 'Supplier username already exists.'
                    : 'Supplier email already exists.',
            ],
        ]);
    }

    private function findDuplicateField(string $username, ?string $email = null): ?string
    {
        $normalizedUsername = trim($username);
        if ($normalizedUsername !== '' && SupplierUser::query()->where('su_username', $normalizedUsername)->exists()) {
            return 'username';
        }

        $normalizedEmail = trim((string) $email);
        if ($normalizedEmail !== '' && SupplierUser::query()->where('su_email', $normalizedEmail)->exists()) {
            return 'email';
        }

        return null;
    }
}
