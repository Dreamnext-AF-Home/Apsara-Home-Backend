<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class PartnerUserController extends Controller
{
    private function canManagePartnerUsers(mixed $actor): bool
    {
        if (! ($actor instanceof Admin)) {
            return false;
        }

        $level = (int) ($actor->user_level_id ?? 0);
        return in_array($level, [1, 2, 4], true);
    }

    private function isSuperAdmin(mixed $actor): bool
    {
        return $actor instanceof Admin && (int) ($actor->user_level_id ?? 0) === 1;
    }

    private function actorStorefrontScope(mixed $actor): ?array
    {
        if (! ($actor instanceof Admin)) {
            return [];
        }

        // Super admins and admins can see/manage all storefront users.
        if (in_array((int) ($actor->user_level_id ?? 0), [1, 2], true)) {
            return null;
        }

        // Partner/web-content accounts are scoped to their storefront IDs.
        return $this->normalizeStorefrontIds($actor->admin_permissions ?? []);
    }

    public function index(Request $request)
    {
        $actor = $request->user();
        if (! $this->canManagePartnerUsers($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $allowedStorefrontIds = $this->actorStorefrontScope($actor);
        $validated = $request->validate([
            'q' => 'nullable|string|max:120',
            'storefront_id' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if (is_array($allowedStorefrontIds) && empty($allowedStorefrontIds)) {
            return response()->json([
                'users' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => (int) ($validated['per_page'] ?? 20),
                    'total' => 0,
                    'from' => null,
                    'to' => null,
                ],
            ]);
        }

        $search = trim((string) ($validated['q'] ?? ''));
        $storefrontId = (int) ($validated['storefront_id'] ?? 0);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = Admin::query()
            ->where('user_level_id', 4)
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($q) use ($search) {
                    $q->where('fname', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('user_email', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id');

        $filtered = $query->get()->filter(function (Admin $admin) use ($allowedStorefrontIds, $storefrontId) {
            $storefrontIds = $this->normalizeStorefrontIds($admin->admin_permissions ?? []);

            if ($storefrontId > 0 && ! in_array($storefrontId, $storefrontIds, true)) {
                return false;
            }

            if ($allowedStorefrontIds === null) {
                return true;
            }

            return ! empty(array_intersect($allowedStorefrontIds, $storefrontIds));
        })->values();

        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;
        $items = $filtered->slice($offset, $perPage)->values();

        return response()->json([
            'users' => $items->map(fn (Admin $admin) => $this->transform($admin))->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? null : $offset + 1,
                'to' => $total === 0 ? null : min($offset + $perPage, $total),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        if (! $this->canManagePartnerUsers($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $allowedStorefrontIds = $this->actorStorefrontScope($actor);

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
            'password' => 'required|string|min:8',
            'storefront_ids' => 'nullable|array',
            'storefront_ids.*' => 'integer|min:1',
        ]);

        $requestedStorefrontIds = $this->normalizeStorefrontIds($validated['storefront_ids'] ?? []);
        if ($allowedStorefrontIds === null) {
            $finalStorefrontIds = $requestedStorefrontIds;
        } elseif (! empty($requestedStorefrontIds)) {
            $finalStorefrontIds = array_values(array_intersect($allowedStorefrontIds, $requestedStorefrontIds));
        } else {
            $finalStorefrontIds = [];
        }

        $admin = Admin::query()->create([
            'fname' => trim((string) $validated['name']),
            'username' => trim((string) $validated['username']),
            'user_email' => trim((string) ($validated['email'] ?? '')),
            'passworde' => Hash::make((string) $validated['password']),
            'user_level_id' => 4,
            'admin_permissions' => $finalStorefrontIds,
            'partner_disabled_storefront_ids' => [],
        ]);

        return response()->json([
            'message' => 'Partner user created successfully.',
            'user' => $this->transform($admin),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $actor = $request->user();
        if (! $this->canManagePartnerUsers($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $allowedStorefrontIds = $this->actorStorefrontScope($actor);
        $target = Admin::query()->where('id', $id)->firstOrFail();
        if ((int) $target->user_level_id !== 4) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $targetStorefrontIds = $this->normalizeStorefrontIds($target->admin_permissions ?? []);
        if (is_array($allowedStorefrontIds) && empty(array_intersect($allowedStorefrontIds, $targetStorefrontIds))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'username' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('tbl_admin', 'username')->ignore($target->id, 'id'),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('tbl_admin', 'user_email')->ignore($target->id, 'id')->where(function ($query) {
                    $query->whereRaw("COALESCE(NULLIF(TRIM(user_email), ''), '') <> ''");
                }),
            ],
            'password' => 'nullable|string|min:8',
            'storefront_ids' => 'nullable|array',
            'storefront_ids.*' => 'integer|min:1',
            'disabled_storefront_ids' => 'nullable|array',
            'disabled_storefront_ids.*' => 'integer|min:1',
        ]);

        if (array_key_exists('name', $validated)) {
            $target->fname = trim((string) $validated['name']);
        }
        if (array_key_exists('username', $validated)) {
            $target->username = trim((string) $validated['username']);
        }
        if (array_key_exists('email', $validated)) {
            $target->user_email = trim((string) $validated['email']);
        }
        if (! empty($validated['password'])) {
            $target->passworde = Hash::make((string) $validated['password']);
        }
        if (array_key_exists('storefront_ids', $validated)) {
            $requestedStorefrontIds = $this->normalizeStorefrontIds($validated['storefront_ids'] ?? []);
            if ($allowedStorefrontIds === null) {
                $finalStorefrontIds = $requestedStorefrontIds;
            } elseif (! empty($requestedStorefrontIds)) {
                $finalStorefrontIds = array_values(array_intersect($allowedStorefrontIds, $requestedStorefrontIds));
            } else {
                $finalStorefrontIds = [];
            }
            $target->admin_permissions = $finalStorefrontIds;

            // Keep disabled storefront IDs valid after assignment changes.
            $currentDisabled = $this->normalizeStorefrontIds($target->partner_disabled_storefront_ids ?? []);
            $target->partner_disabled_storefront_ids = array_values(array_intersect($currentDisabled, $finalStorefrontIds));
        }

        if (array_key_exists('disabled_storefront_ids', $validated)) {
            $requestedDisabledIds = $this->normalizeStorefrontIds($validated['disabled_storefront_ids'] ?? []);
            $assignedStorefrontIds = $this->normalizeStorefrontIds($target->admin_permissions ?? []);
            // Disabled IDs must be a subset of assigned storefront IDs.
            $target->partner_disabled_storefront_ids = array_values(array_intersect($requestedDisabledIds, $assignedStorefrontIds));
        }

        $target->save();

        // Force re-login so new storefront access takes effect immediately in JWT/session guards.
        if (array_key_exists('storefront_ids', $validated)) {
            PersonalAccessToken::query()
                ->where('tokenable_type', Admin::class)
                ->where('tokenable_id', (int) $target->id)
                ->delete();
        }

        return response()->json([
            'message' => 'Partner user updated successfully.',
            'user' => $this->transform($target),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $actor = $request->user();
        if (! $this->canManagePartnerUsers($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $actor->id === $id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $allowedStorefrontIds = $this->actorStorefrontScope($actor);
        $target = Admin::query()->where('id', $id)->firstOrFail();
        if ((int) $target->user_level_id !== 4) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $targetStorefrontIds = $this->normalizeStorefrontIds($target->admin_permissions ?? []);
        if (is_array($allowedStorefrontIds) && empty(array_intersect($allowedStorefrontIds, $targetStorefrontIds))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $target->delete();

        return response()->json(['message' => 'Partner user deleted successfully.']);
    }

    private function transform(Admin $admin): array
    {
        return [
            'id' => (int) $admin->id,
            'name' => (string) ($admin->fname ?: $admin->username),
            'username' => (string) $admin->username,
            'email' => (string) $admin->user_email,
            'user_level_id' => (int) $admin->user_level_id,
            'storefront_ids' => $this->normalizeStorefrontIds($admin->admin_permissions ?? []),
            'disabled_storefront_ids' => $this->normalizeStorefrontIds($admin->partner_disabled_storefront_ids ?? []),
            'is_banned' => (bool) $admin->is_banned,
        ];
    }

    private function normalizeStorefrontIds(mixed $storefrontIds): array
    {
        if (! is_array($storefrontIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id) => is_numeric($id) ? (int) $id : null,
            $storefrontIds,
        ), static fn ($id) => is_int($id) && $id > 0)));
    }
}
