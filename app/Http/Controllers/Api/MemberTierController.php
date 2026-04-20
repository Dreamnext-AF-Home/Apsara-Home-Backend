<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberTier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MemberTierController extends Controller
{
    /**
     * Get all member tiers
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 50);
        $activeOnly = $request->boolean('active_only', false);

        $query = MemberTier::query();

        if ($activeOnly) {
            $query->where('mt_is_active', true);
        }

        $tiers = $query->orderBy('mt_rank')->paginate($perPage);

        return response()->json([
            'data' => $tiers->map(fn (MemberTier $tier) => $this->formatTier($tier))->values(),
            'meta' => [
                'current_page' => $tiers->currentPage(),
                'last_page' => $tiers->lastPage(),
                'per_page' => $tiers->perPage(),
                'total' => $tiers->total(),
            ],
        ]);
    }

    /**
     * Create a new member tier
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:100|unique:tbl_member_tiers,mt_name',
            'rank' => 'required|integer|min:1|unique:tbl_member_tiers,mt_rank',
            'description' => 'nullable|string|max:500',
            'min_pv' => 'required|numeric|min:0',
            'min_direct_referrals' => 'required|integer|min:0',
            'min_group_volume' => 'required|integer|min:0',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $tier = MemberTier::create([
            'mt_name' => $validated['name'],
            'mt_rank' => $validated['rank'],
            'mt_description' => $validated['description'] ?? null,
            'mt_min_pv' => (float) $validated['min_pv'],
            'mt_min_direct_referrals' => (int) $validated['min_direct_referrals'],
            'mt_min_group_volume' => (int) $validated['min_group_volume'],
            'mt_is_active' => (bool) ($validated['is_active'] ?? true),
            'mt_sort_order' => (int) ($validated['sort_order'] ?? $validated['rank']),
        ]);

        return response()->json([
            'message' => 'Member tier created successfully.',
            'data' => $this->formatTier($tier),
        ], 201);
    }

    /**
     * Get a specific member tier
     */
    public function show(int $id): JsonResponse
    {
        $tier = MemberTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'Member tier not found.'], 404);
        }

        return response()->json([
            'data' => $this->formatTier($tier),
        ]);
    }

    /**
     * Update a member tier
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tier = MemberTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'Member tier not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|min:2|max:100|unique:tbl_member_tiers,mt_name,' . $id . ',mt_id',
            'rank' => 'sometimes|integer|min:1|unique:tbl_member_tiers,mt_rank,' . $id . ',mt_id',
            'description' => 'nullable|string|max:500',
            'min_pv' => 'sometimes|numeric|min:0',
            'min_direct_referrals' => 'sometimes|integer|min:0',
            'min_group_volume' => 'sometimes|integer|min:0',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if (isset($validated['name'])) {
            $tier->mt_name = $validated['name'];
        }
        if (isset($validated['rank'])) {
            $tier->mt_rank = $validated['rank'];
        }
        if (isset($validated['description'])) {
            $tier->mt_description = $validated['description'];
        }
        if (isset($validated['min_pv'])) {
            $tier->mt_min_pv = (float) $validated['min_pv'];
        }
        if (isset($validated['min_direct_referrals'])) {
            $tier->mt_min_direct_referrals = (int) $validated['min_direct_referrals'];
        }
        if (isset($validated['min_group_volume'])) {
            $tier->mt_min_group_volume = (int) $validated['min_group_volume'];
        }
        if (isset($validated['is_active'])) {
            $tier->mt_is_active = (bool) $validated['is_active'];
        }
        if (isset($validated['sort_order'])) {
            $tier->mt_sort_order = (int) $validated['sort_order'];
        }

        $tier->save();

        return response()->json([
            'message' => 'Member tier updated successfully.',
            'data' => $this->formatTier($tier),
        ]);
    }

    /**
     * Delete a member tier
     */
    public function destroy(int $id): JsonResponse
    {
        $tier = MemberTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'Member tier not found.'], 404);
        }

        $tier->delete();

        return response()->json(['message' => 'Member tier deleted successfully.']);
    }

    /**
     * Format tier for response
     */
    private function formatTier(MemberTier $tier): array
    {
        return [
            'id' => (int) $tier->mt_id,
            'name' => (string) $tier->mt_name,
            'rank' => (int) $tier->mt_rank,
            'description' => $tier->mt_description,
            'min_pv' => (float) $tier->mt_min_pv,
            'min_direct_referrals' => (int) $tier->mt_min_direct_referrals,
            'min_group_volume' => (int) $tier->mt_min_group_volume,
            'is_active' => (bool) $tier->mt_is_active,
            'sort_order' => (int) $tier->mt_sort_order,
            'created_at' => $tier->created_at?->toDateTimeString(),
            'updated_at' => $tier->updated_at?->toDateTimeString(),
        ];
    }
}
