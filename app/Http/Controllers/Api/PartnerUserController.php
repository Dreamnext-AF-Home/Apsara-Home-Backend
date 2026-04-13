<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class PartnerUserController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        if (! ($actor instanceof Admin) || (int) $actor->user_level_id !== 4) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $allowedStorefrontIds = $this->normalizeStorefrontIds($actor->admin_permissions ?? []);
        $validated = $request->validate([
            'q' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if (empty($allowedStorefrontIds)) {
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

        $filtered = $query->get()->filter(function (Admin $admin) use ($allowedStorefrontIds) {
            $storefrontIds = $this->normalizeStorefrontIds($admin->admin_permissions ?? []);
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
        if (! ($actor instanceof Admin) || (int) $actor->user_level_id !== 4) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $allowedStorefrontIds = $this->normalizeStorefrontIds($actor->admin_permissions ?? []);
        if (empty($allowedStorefrontIds)) {
            return response()->json(['message' => 'No storefront assigned to this account.'], 422);
        }

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
        ]);

        $admin = Admin::query()->create([
            'fname' => trim((string) $validated['name']),
            'username' => trim((string) $validated['username']),
            'user_email' => trim((string) ($validated['email'] ?? '')),
            'passworde' => Hash::make((string) $validated['password']),
            'user_level_id' => 4,
            'admin_permissions' => $allowedStorefrontIds,
        ]);

        return response()->json([
            'message' => 'Partner user created successfully.',
            'user' => $this->transform($admin),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $actor = $request->user();
        if (! ($actor instanceof Admin) || (int) $actor->user_level_id !== 4) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $allowedStorefrontIds = $this->normalizeStorefrontIds($actor->admin_permissions ?? []);
        $target = Admin::query()->where('id', $id)->firstOrFail();
        if ((int) $target->user_level_id !== 4) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $targetStorefrontIds = $this->normalizeStorefrontIds($target->admin_permissions ?? []);
        if (empty(array_intersect($allowedStorefrontIds, $targetStorefrontIds))) {
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

        $target->save();

        return response()->json([
            'message' => 'Partner user updated successfully.',
            'user' => $this->transform($target),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $actor = $request->user();
        if (! ($actor instanceof Admin) || (int) $actor->user_level_id !== 4) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $actor->id === $id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $allowedStorefrontIds = $this->normalizeStorefrontIds($actor->admin_permissions ?? []);
        $target = Admin::query()->where('id', $id)->firstOrFail();
        if ((int) $target->user_level_id !== 4) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $targetStorefrontIds = $this->normalizeStorefrontIds($target->admin_permissions ?? []);
        if (empty(array_intersect($allowedStorefrontIds, $targetStorefrontIds))) {
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
