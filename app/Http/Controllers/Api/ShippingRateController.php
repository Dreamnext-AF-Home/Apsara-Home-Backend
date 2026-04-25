<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShippingRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShippingRateController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        $rates = ShippingRate::query()
            ->where('sr_status', true)
            ->orderBy('sr_province')
            ->orderBy('sr_city')
            ->get();

        return response()->json([
            'rates' => $rates->map(fn (ShippingRate $rate) => $this->formatRate($rate))->values(),
        ]);
    }

    public function adminIndex(): JsonResponse
    {
        $rates = ShippingRate::query()
            ->orderBy('sr_province')
            ->orderBy('sr_city')
            ->get();

        return response()->json([
            'rates' => $rates->map(fn (ShippingRate $rate) => $this->formatRate($rate))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'province' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:160'],
            'fee' => ['required', 'numeric', 'min:0', 'max:999999'],
            'status' => ['nullable', 'boolean'],
        ]);

        $provinceKey = $this->normalizeKey((string) $validated['province']);
        $cityKey = $this->normalizeKey((string) $validated['city']);

        $rate = ShippingRate::query()->updateOrCreate(
            [
                'sr_province_key' => $provinceKey,
                'sr_city_key' => $cityKey,
            ],
            [
                'sr_province' => trim((string) $validated['province']),
                'sr_city' => trim((string) $validated['city']),
                'sr_fee' => (float) $validated['fee'],
                'sr_status' => (bool) ($validated['status'] ?? true),
            ]
        );

        return response()->json([
            'message' => 'Shipping rate saved successfully.',
            'rate' => $this->formatRate($rate),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rate = ShippingRate::query()->findOrFail($id);

        $validated = $request->validate([
            'province' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:160'],
            'fee' => ['required', 'numeric', 'min:0', 'max:999999'],
            'status' => ['required', 'boolean'],
        ]);

        $provinceKey = $this->normalizeKey((string) $validated['province']);
        $cityKey = $this->normalizeKey((string) $validated['city']);

        $duplicate = ShippingRate::query()
            ->where('sr_province_key', $provinceKey)
            ->where('sr_city_key', $cityKey)
            ->where('sr_id', '!=', $rate->sr_id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'A shipping rate for that province and city already exists.',
                'errors' => [
                    'city' => ['A shipping rate for that province and city already exists.'],
                ],
            ], 422);
        }

        $rate->fill([
            'sr_province' => trim((string) $validated['province']),
            'sr_city' => trim((string) $validated['city']),
            'sr_province_key' => $provinceKey,
            'sr_city_key' => $cityKey,
            'sr_fee' => (float) $validated['fee'],
            'sr_status' => (bool) $validated['status'],
        ]);
        $rate->save();

        return response()->json([
            'message' => 'Shipping rate updated successfully.',
            'rate' => $this->formatRate($rate),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $rate = ShippingRate::query()->findOrFail($id);
        $rate->delete();

        return response()->json([
            'message' => 'Shipping rate deleted successfully.',
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $ids = collect($validated['ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $existingCount = ShippingRate::query()
            ->whereIn('sr_id', $ids)
            ->count();

        if ($existingCount !== $ids->count()) {
            return response()->json([
                'message' => 'One or more selected shipping rates could not be found.',
            ], 404);
        }

        ShippingRate::query()
            ->whereIn('sr_id', $ids)
            ->delete();

        return response()->json([
            'message' => sprintf('%d shipping rate(s) deleted successfully.', $ids->count()),
            'deleted_count' => $ids->count(),
        ]);
    }

    private function formatRate(ShippingRate $rate): array
    {
        return [
            'id' => (int) $rate->sr_id,
            'province' => (string) $rate->sr_province,
            'city' => (string) $rate->sr_city,
            'provinceKey' => (string) $rate->sr_province_key,
            'cityKey' => (string) $rate->sr_city_key,
            'fee' => (float) $rate->sr_fee,
            'status' => (bool) $rate->sr_status,
            'updatedAt' => optional($rate->updated_at)->toDateTimeString(),
        ];
    }

    private function normalizeKey(string $value): string
    {
        $normalized = Str::ascii(Str::lower($value));
        $normalized = preg_replace('/\([^)]*\)/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bcity of\b/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(city|municipality|province)\b/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
