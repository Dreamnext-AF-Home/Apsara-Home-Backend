<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\Admin\AdminInviteMail;
use App\Models\Admin;
use App\Models\Supplier;
use App\Support\AdminAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    private const INVITE_TTL_MINUTES = 1440;

    public function index(Request $request)
    {
        $actor = $this->resolveAdmin($request);
        if (!$actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canManageAdminUsers($actor)) {
            return response()->json(['message' => 'Forbidden: only admin managers can access admin user management.'], 403);
        }

        $validated = $request->validate([
            'q' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $search = trim((string) ($validated['q'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $rows = Admin::query()
            ->with('supplier')
            ->when(!$this->isSuperAdmin($actor), function ($builder) use ($actor) {
                $builder->whereIn('user_level_id', $this->allowedInviteLevels($actor));
            })
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($q) use ($search) {
                    $q->where('fname', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('user_email', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'users' => collect($rows->items())->map(fn (Admin $admin) => $this->transform($admin))->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $actor = $this->resolveAdmin($request);
        if (!$actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canManageAdminUsers($actor)) {
            return response()->json(['message' => 'Forbidden: only admin managers can create sub-admin accounts.'], 403);
        }

        $allowedLevels = $this->allowedInviteLevels($actor);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:120|unique:tbl_admin,username',
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('tbl_admin', 'user_email')->where(function ($query) {
                    $query->whereRaw("COALESCE(NULLIF(TRIM(user_email), ''), '') <> ''");
                }),
            ],
            'user_level_id' => ['required', 'integer', Rule::in($allowedLevels)],
            'supplier_id' => 'nullable|integer|exists:tbl_supplier,s_id',
            'admin_permissions' => 'nullable|array',
            'admin_permissions.*' => ['string', Rule::in(AdminAccess::availablePermissions())],
        ]);

        $validated['admin_permissions'] = AdminAccess::sanitizePermissionsForLevel(
            (int) $validated['user_level_id'],
            $validated['admin_permissions'] ?? [],
        );

        $this->ensureSupplierSelection($validated);

        return response()->json($this->createInviteResponse($validated, (int) $actor->id), 201);
    }

    public function showInvite(string $token)
    {
        $payload = $this->getInvitePayload($token);
        if (!$payload) {
            return response()->json(['message' => 'Invite link is invalid or expired.'], 404);
        }

        return response()->json([
            'invite' => [
                'name' => (string) $payload['name'],
                'username' => (string) $payload['username'],
                'email' => (string) ($payload['email'] ?? ''),
                'role' => $this->roleFromLevel((int) $payload['user_level_id']),
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
        if (!$payload) {
            throw ValidationException::withMessages([
                'token' => ['Invite link is invalid or expired.'],
            ]);
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $username = trim((string) $payload['username']);

        $alreadyExists = Admin::query()
            ->where('username', $username)
            ->when($email !== '', function ($query) use ($email) {
                $query->orWhere('user_email', $email);
            })
            ->exists();

        if ($alreadyExists) {
            Cache::forget($this->inviteCacheKey((string) $validated['token']));

            return response()->json([
                'message' => 'This admin account has already been created or is no longer available.',
            ], 409);
        }

        $admin = Admin::query()->create([
            'fname' => trim((string) $payload['name']),
            'username' => $username,
            'user_email' => $email,
            'passworde' => Hash::make((string) $validated['password']),
            'user_level_id' => (int) $payload['user_level_id'],
            'supplier_id' => isset($payload['supplier_id']) ? (int) $payload['supplier_id'] : null,
            'admin_permissions' => AdminAccess::sanitizePermissionsForLevel(
                (int) $payload['user_level_id'],
                $payload['admin_permissions'] ?? [],
            ),
        ]);

        Cache::forget($this->inviteCacheKey((string) $validated['token']));

        return response()->json([
            'message' => 'Your admin account is now active. You may sign in.',
            'user' => $this->transform($admin),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $actor = $this->resolveAdmin($request);
        if (!$actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canManageAdminUsers($actor)) {
            return response()->json(['message' => 'Forbidden: only admin managers can update admin accounts.'], 403);
        }

        $admin = Admin::query()->where('id', $id)->firstOrFail();
        $this->ensureCanManageTarget($actor, $admin);

        $allowedLevels = $this->allowedInviteLevels($actor);
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'username' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('tbl_admin', 'username')->ignore($admin->id, 'id'),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('tbl_admin', 'user_email')->ignore($admin->id, 'id')->where(function ($query) {
                    $query->whereRaw("COALESCE(NULLIF(TRIM(user_email), ''), '') <> ''");
                }),
            ],
            'password' => 'nullable|string|min:8',
            'user_level_id' => ['nullable', 'integer', Rule::in($allowedLevels)],
            'supplier_id' => 'nullable|integer|exists:tbl_supplier,s_id',
            'admin_permissions' => 'nullable|array',
            'admin_permissions.*' => ['string', Rule::in(AdminAccess::availablePermissions())],
        ]);

        $nextLevel = array_key_exists('user_level_id', $validated)
            ? (int) $validated['user_level_id']
            : (int) $admin->user_level_id;
        $this->ensureSupplierSelection([
            'user_level_id' => $nextLevel,
            'supplier_id' => $validated['supplier_id'] ?? $admin->supplier_id,
        ]);

        if (array_key_exists('name', $validated)) {
            $admin->fname = trim((string) $validated['name']);
        }
        if (array_key_exists('username', $validated)) {
            $admin->username = trim((string) $validated['username']);
        }
        if (array_key_exists('email', $validated)) {
            $admin->user_email = trim((string) $validated['email']);
        }
        if (array_key_exists('user_level_id', $validated)) {
            $admin->user_level_id = (int) $validated['user_level_id'];
        }
        if (array_key_exists('admin_permissions', $validated) || (int) $admin->user_level_id !== 2) {
            $admin->admin_permissions = AdminAccess::sanitizePermissionsForLevel(
                (int) $admin->user_level_id,
                $validated['admin_permissions'] ?? $admin->admin_permissions ?? [],
            );
        }
        if (array_key_exists('supplier_id', $validated) || (int) $admin->user_level_id !== 8) {
            $admin->supplier_id = (int) $admin->user_level_id === 8
                ? (isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : $admin->supplier_id)
                : null;
        }
        if (!empty($validated['password'])) {
            $admin->passworde = Hash::make((string) $validated['password']);
        }

        $admin->save();

        return response()->json([
            'message' => 'Admin account updated successfully.',
            'user' => $this->transform($admin),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $actor = $this->resolveAdmin($request);
        if (!$actor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canManageAdminUsers($actor)) {
            return response()->json(['message' => 'Forbidden: only admin managers can delete admin accounts.'], 403);
        }

        if ((int) $actor->id === $id) {
            return response()->json(['message' => 'You cannot delete your own admin account.'], 422);
        }

        $admin = Admin::query()->where('id', $id)->firstOrFail();
        $this->ensureCanManageTarget($actor, $admin);
        $admin->delete();

        return response()->json([
            'message' => 'Admin account deleted successfully.',
        ]);
    }

    private function resolveAdmin(Request $request): ?Admin
    {
        $user = $request->user();
        return $user instanceof Admin ? $user : null;
    }

    private function isSuperAdmin(Admin $admin): bool
    {
        return (int) $admin->user_level_id === 1;
    }

    private function isAdmin(Admin $admin): bool
    {
        return (int) $admin->user_level_id === 2;
    }

    private function canManageAdminUsers(Admin $admin): bool
    {
        return $this->isSuperAdmin($admin) || $this->isAdmin($admin);
    }

    private function roleFromLevel(int $level): string
    {
        return match ($level) {
            1 => 'super_admin',
            2 => 'admin',
            3 => 'csr',
            4 => 'web_content',
            5 => 'accounting',
            6 => 'finance_officer',
            7 => 'merchant_admin',
            8 => 'supplier_admin',
            default => 'staff',
        };
    }

    private function transform(Admin $admin): array
    {
        return [
            'id' => (int) $admin->id,
            'name' => (string) ($admin->fname ?: $admin->username),
            'username' => (string) $admin->username,
            'email' => (string) $admin->user_email,
            'user_level_id' => (int) $admin->user_level_id,
            'role' => AdminAccess::roleFromLevel((int) $admin->user_level_id),
            'supplier_id' => $admin->supplier_id ? (int) $admin->supplier_id : null,
            'supplier_name' => $admin->supplier?->s_company ?: $admin->supplier?->s_name,
            'admin_permissions' => AdminAccess::permissionsForAdmin($admin),
        ];
    }

    private function createInviteResponse(array $validated, int $actorId): array
    {
        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::INVITE_TTL_MINUTES);
        $email = trim((string) ($validated['email'] ?? ''));
        $payload = [
            'name' => trim((string) $validated['name']),
            'username' => trim((string) $validated['username']),
            'email' => $email,
            'user_level_id' => (int) $validated['user_level_id'],
            'supplier_id' => isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null,
            'admin_permissions' => AdminAccess::sanitizePermissionsForLevel(
                (int) $validated['user_level_id'],
                $validated['admin_permissions'] ?? [],
            ),
            'created_by' => $actorId,
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        Cache::put($this->inviteCacheKey($token), $payload, $expiresAt);

        $setupUrl = sprintf(
            '%s/admin-setup?token=%s',
            rtrim((string) env('FRONTEND_URL', config('app.url')), '/'),
            urlencode($token)
        );

        $delivery = 'link_only';
        if ($email !== '') {
            Mail::to($email)->send(new AdminInviteMail(
                name: $payload['name'],
                email: $email,
                roleLabel: $this->roleLabel((int) $payload['user_level_id']),
                setupUrl: $setupUrl,
                expiresAt: $expiresAt->toDayDateTimeString(),
            ));
            $delivery = 'email_and_link';
        }

        return [
            'message' => $email !== ''
                ? 'Sub-admin invite created and emailed successfully.'
                : 'Sub-admin invite link created successfully.',
            'setup_url' => $setupUrl,
            'delivery' => $delivery,
            'invite' => [
                'name' => $payload['name'],
                'username' => $payload['username'],
                'email' => $email,
                'role' => AdminAccess::roleFromLevel((int) $payload['user_level_id']),
                'expires_at' => $expiresAt->toIso8601String(),
                'admin_permissions' => $payload['admin_permissions'],
            ],
        ];
    }

    private function getInvitePayload(string $token): ?array
    {
        $payload = Cache::get($this->inviteCacheKey($token));
        return is_array($payload) ? $payload : null;
    }

    private function inviteCacheKey(string $token): string
    {
        return 'admin:invite:' . $token;
    }

    private function roleLabel(int $level): string
    {
        return str_replace('_', ' ', Str::title(AdminAccess::roleFromLevel($level)));
    }

    private function allowedInviteLevels(Admin $actor): array
    {
        if ($this->isSuperAdmin($actor)) {
            return [1, 2, 3, 4, 5, 6, 7, 8];
        }

        if ($this->isAdmin($actor)) {
            return [3, 4, 5, 6, 7];
        }

        return [];
    }

    private function ensureCanManageTarget(Admin $actor, Admin $target): void
    {
        if ($this->isSuperAdmin($actor)) {
            return;
        }

        if (! in_array((int) $target->user_level_id, $this->allowedInviteLevels($actor), true)) {
            throw ValidationException::withMessages([
                'user_level_id' => ['You can only manage lower sub-admin roles.'],
            ]);
        }
    }

    private function ensureSupplierSelection(array $payload): void
    {
        $level = (int) ($payload['user_level_id'] ?? 0);
        $supplierId = $payload['supplier_id'] ?? null;

        if ($level === 8) {
            if (! $supplierId) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['Supplier is required for Supplier Admin accounts.'],
                ]);
            }

            if (! Supplier::query()->where('s_id', (int) $supplierId)->exists()) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['Selected supplier could not be found.'],
                ]);
            }
        }
    }
}
