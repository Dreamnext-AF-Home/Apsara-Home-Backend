<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebPageContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebPageController extends Controller
{
    public function home(Request $request): JsonResponse
    {
        return response()->json([
            'home' => $this->buildPublicItems('home'),
            'banners' => $this->buildPublicItems('banner'),
            'announcements' => $this->buildPublicItems('announcement'),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    public function publicIndex(Request $request, string $type): JsonResponse
    {
        $resolvedType = $this->resolveType($type);
        if (!$resolvedType) {
            return response()->json(['message' => 'Invalid web page content type.'], 422);
        }

        return response()->json([
            'items' => $this->buildPublicItems($resolvedType),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    public function adminIndex(Request $request, string $type): JsonResponse
    {
        $resolvedType = $this->resolveType($type);
        if (!$resolvedType) {
            return response()->json(['message' => 'Invalid web page content type.'], 422);
        }

        $validated = $request->validate([
            'q' => 'nullable|string|max:120',
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $search = trim((string) ($validated['q'] ?? ''));
        $status = (string) ($validated['status'] ?? 'all');
        $perPage = (int) ($validated['per_page'] ?? 20);

        $rows = WebPageContent::query()
            ->where('wpc_type', $resolvedType)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $like = '%' . $search . '%';
                    $inner->where('wpc_title', 'ilike', $like)
                        ->orWhere('wpc_subtitle', 'ilike', $like)
                        ->orWhere('wpc_body', 'ilike', $like)
                        ->orWhere('wpc_key', 'ilike', $like);
                });
            })
            ->when($status !== 'all', function ($query) use ($status) {
                $query->where('wpc_status', $status === 'active');
            })
            ->orderBy('wpc_sort')
            ->orderByDesc('wpc_id')
            ->paginate($perPage);

        return response()->json([
            'items' => collect($rows->items())->map(fn (WebPageContent $item) => $this->transform($item))->values(),
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

    public function adminStore(Request $request, string $type): JsonResponse
    {
        $resolvedType = $this->resolveType($type);
        if (!$resolvedType) {
            return response()->json(['message' => 'Invalid web page content type.'], 422);
        }

        $validated = $this->validatePayload($request);

        $item = WebPageContent::query()->create([
            'wpc_type' => $resolvedType,
            'wpc_key' => $validated['key'] ?? null,
            'wpc_title' => $validated['title'] ?? null,
            'wpc_subtitle' => $validated['subtitle'] ?? null,
            'wpc_body' => $validated['body'] ?? null,
            'wpc_image_url' => $validated['image_url'] ?? null,
            'wpc_link_url' => $validated['link_url'] ?? null,
            'wpc_button_text' => $validated['button_text'] ?? null,
            'wpc_payload' => $validated['payload'] ?? null,
            'wpc_sort' => (int) ($validated['sort_order'] ?? 0),
            'wpc_status' => (bool) ($validated['is_active'] ?? true),
            'wpc_start_at' => $validated['start_at'] ?? null,
            'wpc_end_at' => $validated['end_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Web content item created successfully.',
            'item' => $this->transform($item),
        ], 201);
    }

    public function adminUpdate(Request $request, string $type, int $id): JsonResponse
    {
        $resolvedType = $this->resolveType($type);
        if (!$resolvedType) {
            return response()->json(['message' => 'Invalid web page content type.'], 422);
        }

        $item = WebPageContent::query()
            ->where('wpc_type', $resolvedType)
            ->where('wpc_id', $id)
            ->first();
        if (!$item) {
            return response()->json(['message' => 'Web content item not found.'], 404);
        }

        $validated = $this->validatePayload($request, true);

        $map = [
            'key' => 'wpc_key',
            'title' => 'wpc_title',
            'subtitle' => 'wpc_subtitle',
            'body' => 'wpc_body',
            'image_url' => 'wpc_image_url',
            'link_url' => 'wpc_link_url',
            'button_text' => 'wpc_button_text',
            'payload' => 'wpc_payload',
            'sort_order' => 'wpc_sort',
            'is_active' => 'wpc_status',
            'start_at' => 'wpc_start_at',
            'end_at' => 'wpc_end_at',
        ];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $item->{$column} = $validated[$input];
            }
        }

        $item->save();

        return response()->json([
            'message' => 'Web content item updated successfully.',
            'item' => $this->transform($item),
        ]);
    }

    public function adminDestroy(Request $request, string $type, int $id): JsonResponse
    {
        $resolvedType = $this->resolveType($type);
        if (!$resolvedType) {
            return response()->json(['message' => 'Invalid web page content type.'], 422);
        }

        $item = WebPageContent::query()
            ->where('wpc_type', $resolvedType)
            ->where('wpc_id', $id)
            ->first();
        if (!$item) {
            return response()->json(['message' => 'Web content item not found.'], 404);
        }

        $item->delete();

        return response()->json(['message' => 'Web content item deleted successfully.']);
    }

    private function resolveType(string $type): ?string
    {
        return match (strtolower(trim($type))) {
            'home', 'homepage', 'home_page' => 'home',
            'banner', 'banners' => 'banner',
            'announcement', 'announcements' => 'announcement',
            'assembly', 'assembly-guide', 'assembly-guides', 'assembly_guides' => 'assembly-guides',
            'shop-builder', 'shop_builder', 'shop', 'shop-page', 'shop_page' => 'shop-builder',
            'partner-storefront', 'partner-storefronts', 'partner_storefront', 'partner_storefronts', 'storefront', 'storefronts' => 'partner-storefront',
            default => null,
        };
    }

    private function buildPublicItems(string $type)
    {
        $now = now();

        return WebPageContent::query()
            ->where('wpc_type', $type)
            ->where('wpc_status', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('wpc_start_at')
                    ->orWhere('wpc_start_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('wpc_end_at')
                    ->orWhere('wpc_end_at', '>=', $now);
            })
            ->orderBy('wpc_sort')
            ->orderByDesc('wpc_id')
            ->get()
            ->map(fn (WebPageContent $item) => $this->transform($item))
            ->values();
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes|' : '';
        return $request->validate([
            'key' => $prefix . 'nullable|string|max:120',
            'title' => $prefix . 'nullable|string|max:255',
            'subtitle' => $prefix . 'nullable|string|max:255',
            'body' => $prefix . 'nullable|string',
            'image_url' => $prefix . 'nullable|string|max:1200',
            'link_url' => $prefix . 'nullable|string|max:1200',
            'button_text' => $prefix . 'nullable|string|max:120',
            'payload' => $prefix . 'nullable|array',
            'sort_order' => $prefix . 'nullable|integer|min:0|max:999999',
            'is_active' => $prefix . 'nullable|boolean',
            'start_at' => $prefix . 'nullable|date',
            'end_at' => $prefix . 'nullable|date|after_or_equal:start_at',
        ]);
    }

    private function transform(WebPageContent $item): array
    {
        return [
            'id' => (int) $item->wpc_id,
            'type' => (string) $item->wpc_type,
            'key' => $item->wpc_key,
            'title' => $item->wpc_title,
            'subtitle' => $item->wpc_subtitle,
            'body' => $item->wpc_body,
            'image_url' => $item->wpc_image_url,
            'link_url' => $item->wpc_link_url,
            'button_text' => $item->wpc_button_text,
            'payload' => $item->wpc_payload,
            'sort_order' => (int) ($item->wpc_sort ?? 0),
            'is_active' => (bool) $item->wpc_status,
            'start_at' => optional($item->wpc_start_at)->toDateTimeString(),
            'end_at' => optional($item->wpc_end_at)->toDateTimeString(),
            'created_at' => optional($item->created_at)->toDateTimeString(),
            'updated_at' => optional($item->updated_at)->toDateTimeString(),
        ];
    }
}
