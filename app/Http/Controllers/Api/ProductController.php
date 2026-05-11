<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductActivityLog;
use App\Models\ProductPhoto;
use App\Models\ProductVariant;
use App\Models\ProductVariantPhoto;
use App\Models\ProductBrand;
use App\Models\Supplier;
use App\Models\SupplierCategoryAccess;
use App\Models\SupplierUser;
use App\Models\SearchHistory;
use App\Services\Zq\ZqApiService;
use App\Services\Zq\ZqProductSyncService;
use App\Models\ZqProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function __construct(
        private readonly ZqApiService $zqApiService,
        private readonly ZqProductSyncService $zqProductSyncService
    ) {
    }

    private function validationErrorResponse($validator): JsonResponse
    {
        return response()->json([
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }

    private function applyPublicVisibility($query)
    {
        return $query->whereIn('pd_status', [1, 2]);
    }

    public function rooms(): JsonResponse
    {
        return response()->json([
            'rooms' => [
                ['id' => 1, 'name' => 'Bedroom'],
                ['id' => 2, 'name' => 'Kitchen'],
                ['id' => 3, 'name' => 'Living Room'],
                ['id' => 4, 'name' => 'Outdoor'],
                ['id' => 5, 'name' => 'Study & Office Room'],
                ['id' => 6, 'name' => 'Dining Room'],
                ['id' => 7, 'name' => 'Laundry Room'],
                ['id' => 8, 'name' => 'Bath Room'],
            ],
            'total' => 8,
        ]);
    }

    /**
     * Invalidate search history cache for a customer
     */
    private function invalidateSearchHistoryCache(int $customerId): void
    {
        // Clear all possible search history cache keys for this customer
        for ($limit = 10; $limit <= 50; $limit += 10) {
            Cache::forget("search_history_{$customerId}_{$limit}");
        }
    }

    public function saveSearchHistory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $customerId = auth('sanctum')->id();

            SearchHistory::create([
                'sh_customer_id' => $customerId,
                'sh_query' => $request->input('query'),
            ]);

            // Invalidate search history cache for this customer
            $this->invalidateSearchHistoryCache($customerId);

            return response()->json(['message' => 'Search history saved successfully.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to save search history.'], 500);
        }
    }

    public function getSearchHistory(Request $request): JsonResponse
    {
        $customerId = auth('sanctum')->id();
        $limit = $request->input('limit', 10);
        $limit = min((int) $limit, 50);

        $cacheKey = "search_history_{$customerId}_{$limit}";
        
        $searchHistory = Cache::remember($cacheKey, 180, function () use ($customerId, $limit) {
            return SearchHistory::where('sh_customer_id', $customerId)
                ->orderBy('sh_date_created', 'desc') // Uses idx_search_customer_date
                ->limit($limit)
                ->get();
        });

        return response()->json([
            'data' => $searchHistory->map(fn ($history) => [
                'id' => (int) $history->sh_id,
                'query' => (string) $history->sh_query,
                'date_created' => $history->sh_date_created->format('Y-m-d H:i:s'),
            ]),
        ]);
    }

    public function clearSearchHistory(): JsonResponse
    {
        $customerId = auth('sanctum')->id();

        $deletedCount = SearchHistory::where('sh_customer_id', $customerId)->delete();

        return response()->json([
            'message' => 'Search history cleared successfully.',
            'deleted_count' => $deletedCount,
        ]);
    }

    public function deleteSearchHistory(int $id): JsonResponse
    {
        $customerId = auth('sanctum')->id();

        $searchHistory = SearchHistory::where('sh_id', $id)
            ->where('sh_customer_id', $customerId)
            ->first();

        if (!$searchHistory) {
            return response()->json(['message' => 'Search history not found.'], 404);
        }

        $searchHistory->delete();

        return response()->json([
            'message' => 'Search history deleted successfully.',
            'deleted_id' => (int) $id,
        ]);
    }

    public function reviews(int $id): JsonResponse
    {
        $exists = Product::query()->where('pd_id', $id)->exists();
        if (!$exists) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $baseQuery = DB::table('tbl_product_reviews as r')
            ->leftJoin('tbl_customer as c', 'c.c_userid', '=', 'r.pr_customer_id')
            ->where('r.pr_product_id', $id);

        $summaryRow = (clone $baseQuery)
            ->selectRaw('COALESCE(SUM(r.pr_rating), 0) as total_stars, COUNT(*) as review_count')
            ->first();

        $count = (int) ($summaryRow->review_count ?? 0);
        $totalStars = (float) ($summaryRow->total_stars ?? 0);
        $average = $count > 0
            ? round($totalStars / $count, 2)
            : 0.0;

        $breakdown = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $breakdownRows = (clone $baseQuery)
            ->select('r.pr_rating', DB::raw('COUNT(*) as total'))
            ->groupBy('r.pr_rating')
            ->get();

        foreach ($breakdownRows as $row) {
            $rating = (int) ($row->pr_rating ?? 0);
            if (isset($breakdown[$rating])) {
                $breakdown[$rating] = (int) ($row->total ?? 0);
            }
        }

        $reviews = (clone $baseQuery)
            ->orderByDesc('r.created_at')
            ->get([
                'r.pr_id',
                'r.pr_rating',
                'r.pr_review',
                'r.pr_image_url',
                'r.pr_video_url',
                'r.pr_image_urls',
                'r.pr_video_urls',
                'r.created_at',
                'c.c_username',
                'c.c_fname',
                'c.c_lname',
                'c.c_avatar_url',
            ])
            ->map(function ($row) {
                $first = trim((string) ($row->c_fname ?? ''));
                $last = trim((string) ($row->c_lname ?? ''));
                $username = trim((string) ($row->c_username ?? ''));
                $displayName = trim($first . ' ' . $last);
                if ($displayName === '') {
                    $displayName = $username !== '' ? $username : 'Customer';
                }
                $createdAt = $row->created_at ?? null;
                if ($createdAt instanceof \DateTimeInterface) {
                    $createdAt = $createdAt->format('Y-m-d H:i:s');
                }
                $imageUrls = [];
                if (is_string($row->pr_image_urls) && trim($row->pr_image_urls) !== '') {
                    $decoded = json_decode($row->pr_image_urls, true);
                    if (is_array($decoded)) {
                        $imageUrls = array_values(array_filter(array_map(static fn ($item) => is_string($item) ? trim($item) : '', $decoded)));
                    }
                }
                if (empty($imageUrls) && ! empty($row->pr_image_url)) {
                    $imageUrls = [(string) $row->pr_image_url];
                }

                $videoUrls = [];
                if (is_string($row->pr_video_urls) && trim($row->pr_video_urls) !== '') {
                    $decoded = json_decode($row->pr_video_urls, true);
                    if (is_array($decoded)) {
                        $videoUrls = array_values(array_filter(array_map(static fn ($item) => is_string($item) ? trim($item) : '', $decoded)));
                    }
                }
                if (empty($videoUrls) && ! empty($row->pr_video_url)) {
                    $videoUrls = [(string) $row->pr_video_url];
                }

                return [
                    'id' => (int) $row->pr_id,
                    'rating' => (int) $row->pr_rating,
                    'review' => (string) $row->pr_review,
                    'review_image' => $imageUrls[0] ?? null,
                    'review_video' => $videoUrls[0] ?? null,
                    'review_images' => $imageUrls,
                    'review_videos' => $videoUrls,
                    'customer_name' => $displayName,
                    'customer_avatar' => $row->c_avatar_url ?: null,
                    'created_at' => $createdAt ? (string) $createdAt : null,
                ];
            })
            ->values();

        return response()->json([
            'summary' => [
                'average' => $average,
                'count' => $count,
                'breakdown' => $breakdown,
            ],
            'reviews' => $reviews,
        ]);
    }

    private function resolveAdmin(Request $request): ?Admin
    {
        $user = $request->user();
        return $user instanceof Admin ? $user : null;
    }

    private function resolveSupplierBrandType(int $supplierId): int
    {
        if ($supplierId <= 0) {
            return 0;
        }

        $supplier = Supplier::query()->find($supplierId);
        if (! $supplier) {
            return 0;
        }

        $candidates = [
            (string) ($supplier->s_company ?? ''),
            (string) ($supplier->s_name ?? ''),
        ];
        $normalizedCandidates = collect($candidates)
            ->map(fn ($value) => strtolower(preg_replace('/[^a-z0-9]/i', '', trim($value)) ?? ''))
            ->filter(fn ($value) => $value !== '')
            ->values();

        if ($normalizedCandidates->isEmpty()) {
            return 0;
        }

        $brands = ProductBrand::query()->select(['pb_id', 'pb_name'])->get();
        foreach ($brands as $brand) {
            $brandKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($brand->pb_name ?? '')) ?? '');
            if ($brandKey === '') {
                continue;
            }
            foreach ($normalizedCandidates as $candidate) {
                if ($candidate !== '' && $candidate === $brandKey) {
                    return (int) $brand->pb_id;
                }
            }
        }

        $bestId = 0;
        $bestScore = 0;
        $bestLen = 0;
        foreach ($brands as $brand) {
            $brandKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($brand->pb_name ?? '')) ?? '');
            if ($brandKey === '' || strlen($brandKey) < 2) {
                continue;
            }

            foreach ($normalizedCandidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                $score = 0;
                if ($candidate === $brandKey) {
                    $score = 3;
                } elseif (str_contains($candidate, $brandKey)) {
                    $score = 2;
                } elseif (str_contains($brandKey, $candidate)) {
                    $score = 1;
                }

                if ($score > 0) {
                    $len = strlen($brandKey);
                    if ($score > $bestScore || ($score === $bestScore && $len > $bestLen)) {
                        $bestScore = $score;
                        $bestLen = $len;
                        $bestId = (int) $brand->pb_id;
                    }
                }
            }
        }

        return $bestId;
    }

    private function resolveSupplierUser(Request $request): ?SupplierUser
    {
        $user = $request->user();
        return $user instanceof SupplierUser ? $user : null;
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

    private function scopeQueryToActor($query, ?Admin $admin, ?SupplierUser $supplierUser)
    {
        if ($supplierUser) {
            $supplierId = (int) $supplierUser->su_supplier;
            $brandTypeValue = $supplierId > 0 ? $this->resolveSupplierBrandType($supplierId) : 0;
            if ($brandTypeValue > 0) {
                $query->where(function ($inner) use ($supplierId, $brandTypeValue) {
                    $inner->where('pd_supplier', $supplierId)
                        ->orWhere('pd_brand_type', $brandTypeValue);
                });
            } else {
                $query->where('pd_supplier', $supplierId);
            }
            return $query;
        }

        if ($admin && $this->roleFromLevel((int) $admin->user_level_id) === 'supplier_admin') {
            $supplierId = (int) ($admin->supplier_id ?? 0);
            $brandTypeValue = $supplierId > 0 ? $this->resolveSupplierBrandType($supplierId) : 0;
            if ($brandTypeValue > 0) {
                $query->where(function ($inner) use ($supplierId, $brandTypeValue) {
                    $inner->where('pd_supplier', $supplierId > 0 ? $supplierId : -1)
                        ->orWhere('pd_brand_type', $brandTypeValue);
                });
            } else {
                $query->where('pd_supplier', $supplierId > 0 ? $supplierId : -1);
            }
        }

        return $query;
    }

    private function normalizeSlug(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
        return trim($normalized, '-');
    }

    private function toNumber(mixed $value): float
    {
        if (is_null($value)) {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = preg_replace('/[^0-9.\-]/', '', $value) ?? '';
            if ($normalized === '' || $normalized === '-' || $normalized === '.') {
                return 0.0;
            }
            return is_numeric($normalized) ? (float) $normalized : 0.0;
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function toOptionalNumber(mixed $value): ?float
    {
        if (is_null($value)) {
            return null;
        }

        if (is_string($value)) {
            $normalized = preg_replace('/[^0-9.\-]/', '', $value) ?? '';
            if ($normalized === '' || $normalized === '-' || $normalized === '.') {
                return null;
            }

            return is_numeric($normalized) ? (float) $normalized : null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function applyKeywordSearch($query, string $search): void
    {
        $like = '%' . $search . '%';

        $query->where(function ($inner) use ($like) {
            $inner->where('pd_name', 'ilike', $like)
                ->orWhere('pd_parent_sku', 'ilike', $like)
                ->orWhereHas('variants', function ($variantQuery) use ($like) {
                    $variantQuery->where('pv_sku', 'ilike', $like);
                });
        });
    }

    private function inferRoomTypeFromCategory(?Category $category): int
    {
        if (! $category) {
            return 0;
        }

        $haystacks = array_filter([
            strtolower(trim((string) ($category->cat_name ?? ''))),
            strtolower(trim((string) ($category->cat_url ?? ''))),
        ]);

        $rules = [
            1 => ['bedroom', 'bed', 'mattress', 'pillow', 'dresser', 'night-table', 'wardrobe', 'cabinet'],
            2 => ['kitchen', 'rice-cooker', 'coffee-maker', 'oven', 'toaster', 'pressure-cooker', 'grill', 'kettle', 'pots', 'pans', 'utensil'],
            3 => ['living', 'sofa', 'leisure-chair', 'lounge-chair', 'ottoman', 'coffee-table', 'center-table', 'tv-rack', 'shelf'],
            4 => ['outdoor', 'garden', 'patio'],
            5 => ['study', 'office', 'desk', 'workstation', 'computer-table', 'office-chair'],
            6 => ['dining', 'dining-room', 'dining-table', 'dining-chair', 'buffet'],
            7 => ['laundry', 'laundry-room', 'washer', 'dryer', 'hamper'],
            8 => ['bathroom', 'bath', 'toilet', 'shower', 'sink', 'vanity'],
        ];

        foreach ($rules as $roomType => $keywords) {
            foreach ($haystacks as $haystack) {
                foreach ($keywords as $keyword) {
                    if (str_contains($haystack, $keyword)) {
                        return $roomType;
                    }
                }
            }
        }

        return 0;
    }

    private function resolveRoomType(Request $request): int
    {
        if ($request->exists('pd_room_type')) {
            return max(0, (int) $request->input('pd_room_type', 0));
        }

        $categoryId = (int) $request->input('pd_catid', 0);
        if ($categoryId <= 0) {
            return 0;
        }

        $category = Category::query()->select(['cat_id', 'cat_name', 'cat_url'])->find($categoryId);
        return $this->inferRoomTypeFromCategory($category);
    }

    private function resolveRoomTypeByName(?string $value): int
    {
        $name = strtolower(trim((string) ($value ?? '')));
        if ($name === '') {
            return 0;
        }

        $map = [
            1 => ['bedroom', 'bed', 'mattress', 'pillow', 'dresser', 'night-table', 'wardrobe', 'cabinet'],
            2 => ['kitchen', 'rice-cooker', 'coffee-maker', 'oven', 'toaster', 'pressure-cooker', 'grill', 'kettle', 'pots', 'pans', 'utensil'],
            3 => ['living', 'sofa', 'leisure-chair', 'lounge-chair', 'ottoman', 'coffee-table', 'center-table', 'tv-rack', 'shelf'],
            4 => ['outdoor', 'garden', 'patio'],
            5 => ['study', 'office', 'desk', 'workstation', 'computer-table', 'office-chair'],
            6 => ['dining', 'dining-room', 'dining-table', 'dining-chair', 'buffet'],
            7 => ['laundry', 'laundry-room', 'washer', 'dryer', 'hamper'],
            8 => ['bathroom', 'bath', 'toilet', 'shower', 'sink', 'vanity'],
        ];

        foreach ($map as $roomType => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    return $roomType;
                }
            }
        }

        return 0;
    }

    private function resolveCategoryIdByName(?string $value): int
    {
        $name = trim((string) ($value ?? ''));
        if ($name === '') {
            return 0;
        }

        $category = Category::query()
            ->where('cat_name', 'like', $name)
            ->first();

        if (!$category) {
            $category = Category::query()
                ->where('cat_name', 'like', '%' . $name . '%')
                ->first();
        }

        return $category ? (int) $category->cat_id : 0;
    }

    private function resolveBrandIdByName(?string $value): int
    {
        $name = trim((string) ($value ?? ''));
        if ($name === '') {
            return 0;
        }

        $brand = ProductBrand::query()
            ->where('pb_name', 'like', $name)
            ->first();

        if (!$brand) {
            $brand = ProductBrand::query()
                ->where('pb_name', 'like', '%' . $name . '%')
                ->first();
        }

        return $brand ? (int) $brand->pb_id : 0;
    }

    private function roomTypeLabel(int $value): string
    {
        return match ($value) {
            1 => 'Bedroom',
            2 => 'Kitchen',
            3 => 'Living Room',
            4 => 'Outdoor',
            5 => 'Study / Office',
            6 => 'Dining',
            7 => 'Laundry',
            8 => 'Bathroom',
            default => (string) $value,
        };
    }

    private function categoryNameById(int $value): string
    {
        if ($value <= 0) {
            return '';
        }
        $category = Category::query()->select(['cat_id', 'cat_name'])->find($value);
        return $category ? (string) $category->cat_name : (string) $value;
    }

    private function actorSupplierId(?Admin $admin, ?SupplierUser $supplierUser): int
    {
        if ($supplierUser) {
            return (int) $supplierUser->su_supplier;
        }

        if ($admin && $this->roleFromLevel((int) $admin->user_level_id) === 'supplier_admin') {
            return (int) ($admin->supplier_id ?? 0);
        }

        return 0;
    }

    private function actorDisplayName(?Admin $admin, ?SupplierUser $supplierUser): ?string
    {
        if ($supplierUser) {
            $name = trim((string) ($supplierUser->su_fullname ?? ''));
            if ($name !== '') {
                return $name;
            }

            $username = trim((string) ($supplierUser->su_username ?? ''));
            return $username !== '' ? $username : null;
        }

        if ($admin) {
            $name = trim((string) ($admin->fname ?? ''));
            if ($name !== '') {
                return $name;
            }

            $username = trim((string) ($admin->username ?? ''));
            return $username !== '' ? $username : null;
        }

        return null;
    }

    private function actorEmail(?Admin $admin, ?SupplierUser $supplierUser): ?string
    {
        if ($supplierUser) {
            $email = trim((string) ($supplierUser->su_email ?? ''));
            return $email !== '' ? $email : null;
        }

        if ($admin) {
            $email = trim((string) ($admin->user_email ?? ''));
            return $email !== '' ? $email : null;
        }

        return null;
    }

    private function actorRoleLabel(?Admin $admin, ?SupplierUser $supplierUser): ?string
    {
        if ($supplierUser) {
            return 'supplier_user';
        }

        if ($admin) {
            return $this->roleFromLevel((int) $admin->user_level_id);
        }

        return null;
    }

    private function mapProductActivityLog(ProductActivityLog $log): array
    {
        return [
            'id' => (int) $log->pal_id,
            'productId' => $log->pal_product_id ? (int) $log->pal_product_id : null,
            'supplierId' => $log->pal_supplier_id ? (int) $log->pal_supplier_id : null,
            'action' => (string) $log->pal_action,
            'status' => (string) $log->pal_status,
            'productName' => (string) $log->pal_product_name,
            'productSku' => $log->pal_product_sku ? (string) $log->pal_product_sku : null,
            'actorName' => $log->pal_actor_name ? (string) $log->pal_actor_name : null,
            'actorEmail' => $log->pal_actor_email ? (string) $log->pal_actor_email : null,
            'actorRole' => $log->pal_actor_role ? (string) $log->pal_actor_role : null,
            'changes' => is_array($log->pal_changes) ? array_values($log->pal_changes) : [],
            'createdAt' => optional($log->pal_created_at)->toIso8601String(),
        ];
    }

    private function createProductActivity(
        string $action,
        string $status,
        ?Admin $admin,
        ?SupplierUser $supplierUser,
        ?Product $product = null,
        ?string $productName = null,
        ?string $productSku = null,
        ?array $changes = null
    ): void {
        $resolvedProductName = trim((string) ($productName ?? ($product?->pd_name ?? '')));
        $resolvedProductSku = trim((string) ($productSku ?? ($product?->pd_parent_sku ?? '')));

        try {
            ProductActivityLog::create([
                'pal_product_id' => $product ? (int) $product->pd_id : null,
                'pal_supplier_id' => $this->actorSupplierId($admin, $supplierUser) ?: null,
                'pal_admin_id' => $admin ? (int) $admin->id : null,
                'pal_supplier_user_id' => $supplierUser ? (int) $supplierUser->su_id : null,
                'pal_action' => $action,
                'pal_status' => $status,
                'pal_product_name' => $resolvedProductName !== '' ? $resolvedProductName : 'Unknown product',
                'pal_product_sku' => $resolvedProductSku !== '' ? $resolvedProductSku : null,
                'pal_actor_name' => $this->actorDisplayName($admin, $supplierUser),
                'pal_actor_email' => $this->actorEmail($admin, $supplierUser),
                'pal_actor_role' => $this->actorRoleLabel($admin, $supplierUser),
                'pal_changes' => $changes ?: null,
                'pal_created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Product activity log write failed', [
                'action' => $action,
                'status' => $status,
                'product_id' => $product?->pd_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function recordFailedProductActivity(
        string $action,
        ?Admin $admin,
        ?SupplierUser $supplierUser,
        ?Product $product = null,
        ?string $productName = null,
        ?string $productSku = null,
        ?array $changes = null
    ): void {
        $this->createProductActivity($action, 'failed', $admin, $supplierUser, $product, $productName, $productSku, $changes);
    }

    private function recordProductActivity(
        string $action,
        Product $product,
        ?Admin $admin,
        ?SupplierUser $supplierUser,
        ?string $productName = null,
        ?string $productSku = null,
        ?array $changes = null
    ): void {
        $this->createProductActivity('' !== $action ? $action : 'updated', 'success', $admin, $supplierUser, $product, $productName, $productSku, $changes);
    }

    private function groupImportRows(array $rawRows): array
    {
        $grouped = [];
        $lastProductKey  = null;
        $lastProductName = '';
        $lastProductSku  = '';

        foreach ($rawRows as $raw) {
            $row = $this->normalizeImportRow($raw);

            $name = trim((string) ($row['pd_name'] ?? ''));
            $sku  = trim((string) ($row['pd_parent_sku'] ?? ''));

            // Blank row — inherit name and SKU from the last known product
            if ($name === '' && $sku === '' && $lastProductKey !== null) {
                $row['pd_name']       = $lastProductName;
                $row['pd_parent_sku'] = $lastProductSku;
                $name = $lastProductName;
                $sku  = $lastProductSku;
            }

            // Collect all variants from this row (JSON format may have multiple)
            $rowVariants = !empty($row['pd_variants']) && is_array($row['pd_variants'])
                ? array_values(array_filter($row['pd_variants'], fn ($v) => $v !== null))
                : [];
            // Flat-spreadsheet format: a single variant squeezed into a non-array scalar
            if (empty($rowVariants) && isset($row['pd_variants']) && !is_array($row['pd_variants'])) {
                $rowVariants = [$row['pd_variants']];
            }

            if ($name === '' && $sku === '') {
                continue;
            }

            $key = $sku !== '' ? $sku : $name;

            if (!isset($grouped[$key])) {
                $grouped[$key] = $row;
                $grouped[$key]['pd_variants'] = [];
                $lastProductKey  = $key;
                $lastProductName = $name;
                $lastProductSku  = $sku;
            }

            foreach ($rowVariants as $v) {
                $grouped[$key]['pd_variants'][] = $v;
            }
        }

        return array_values($grouped);
    }

    private function normalizeImportRow(array $row): array
    {
        $map = [
            'Main Product Name'             => 'pd_name',
            'Product Name'                  => 'pd_name',
            'Parent SKU'                    => 'pd_parent_sku',
            'Product SKU'                   => 'pd_parent_sku',
            'Category'                      => 'pd_catid',
            'Room Type'                     => 'pd_room_type',
            'Brand'                         => 'pd_brand_type',
            'Price SRP'                     => 'pd_price_srp',
            'Price DP'                      => 'pd_price_dp',
            'Price Member'                  => 'pd_price_member',
            'Product PV (AUTO)'             => 'pd_prodpv',
            'Quantity'                      => 'pd_qty',
            'Weight'                        => 'pd_weight',
            'Package Weight'                => 'pd_psweight',
            'Package Width'                 => 'pd_pswidth',
            'Pacakge Width'                 => 'pd_pswidth',
            'Package Length'                => 'pd_pslenght',
            'Package Height'                => 'pd_psheight',
            'Description'                   => 'pd_description',
            'Specifications'                => 'pd_specifications',
            'Material'                      => 'pd_material',
            'Warranty'                      => 'pd_warranty',
            'Images'                        => 'pd_images',
            'Product Type'                  => 'pd_type',
            'Status'                        => 'pd_status',
            'Must Have'                     => 'pd_musthave',
            'Best Seller'                   => 'pd_bestseller',
            'Sales Promo'                   => 'pd_salespromo',
            'Assembly Required'             => 'pd_assembly_required',
        ];

        $variantMap = [
            'Variant SKU'                   => 'pv_sku',
            'Variant Name'                  => 'pv_name',
            'Color Name'                    => 'pv_color',
            'Color Hex'                     => 'pv_color_hex',
            'Variant Size'                  => 'pv_size',
            'Variant Style'                 => 'pv_style',
            'Variant Width'                 => 'pv_width',
            'Variant Dimension'             => 'pv_dimension',
            'Variant Height'                => 'pv_height',
            'Variant Price SRP'             => 'pv_price_srp',
            'Variant Price DP'              => 'pv_price_dp',
            'Variant Price Member'          => 'pv_price_member',
            'Variant PV (AUTO)'             => 'pv_prodpv',
            'Variant Qty'                   => 'pv_qty',
            'Variant Status'                => 'pv_status',
            'Variant Images'                => 'pv_images',
        ];

        $normalized = [];
        $variantData = [];

        foreach ($row as $key => $value) {
            if (isset($map[$key])) {
                $normalized[$map[$key]] = $value;
            } elseif (isset($variantMap[$key])) {
                $variantData[$variantMap[$key]] = $value;
            } else {
                $normalized[$key] = $value;
            }
        }

        // If flat variant fields exist and no pd_variants array, wrap them
        if (!empty($variantData) && empty($normalized['pd_variants'])) {
            $normalized['pd_variants'] = [$variantData];
        }

        // Normalize pd_type: text values → 0 or 1
        if (isset($normalized['pd_type'])) {
            $typeVal = strtolower(trim((string) $normalized['pd_type']));
            $normalized['pd_type'] = in_array($typeVal, ['1', 'yes', 'true', 'with variants', 'variable', 'has variants', 'variant'], true) ? 1 : 0;
        }

        // Auto-set pd_type to 1 if variants are present
        if (!empty($normalized['pd_variants'])) {
            $normalized['pd_type'] = 1;
        }

        return $normalized;
    }

    private function normalizeImportBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private function normalizeImportImageList(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)
                ->filter(fn ($item) => is_string($item) && trim($item) !== '')
                ->map(fn ($item) => trim((string) $item))
                ->unique(fn (string $item) => mb_strtolower($item, 'UTF-8'))
                ->values()
                ->all();
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\r\n|,]+/', $raw) ?: [];

        return collect($parts)
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->unique(fn (string $item) => mb_strtolower($item, 'UTF-8'))
            ->values()
            ->all();
    }

    private function fillProductFromImportRow(Product $product, array $row, int $supplierId, $now, bool $partialUpdate = false): Product
    {
        $images       = $this->normalizeImportImageList($row['pd_images'] ?? []);
        $primaryImage = trim((string) ($row['pd_image'] ?? ''));

        if ($primaryImage !== '' && empty($images)) {
            $images = [$primaryImage];
        }

        // Only treat images as "provided" when there are actual URLs — not when the key is null/empty
        $hasImages = !empty($images);

        // When doing a partial update, only include fields that are present and non-null in the row.
        // For a fresh create, always fill every field with its default.
        $has = fn (string $key): bool => array_key_exists($key, $row) && $row[$key] !== null;

        $fields = [];

        if (!$partialUpdate || $has('pd_name')) {
            $fields['pd_name'] = trim((string) ($row['pd_name'] ?? ''));
        }
        if (!$partialUpdate || $has('pd_catid')) {
            $fields['pd_catid'] = (int) ($row['pd_catid'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_room_type')) {
            $fields['pd_room_type'] = (int) ($row['pd_room_type'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_brand_type')) {
            $fields['pd_brand_type'] = (int) ($row['pd_brand_type'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_catsubid')) {
            $fields['pd_catsubid'] = (int) ($row['pd_catsubid'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_description')) {
            $fields['pd_description'] = isset($row['pd_description']) ? (string) $row['pd_description'] : null;
        }
        if (!$partialUpdate || $has('pd_specifications')) {
            $fields['pd_specifications'] = ($row['pd_specifications'] ?? null) !== null ? (string) $row['pd_specifications'] : null;
        }
        if (!$partialUpdate || $has('pd_material')) {
            $fields['pd_material'] = trim((string) ($row['pd_material'] ?? ''));
        }
        if (!$partialUpdate || $has('pd_warranty')) {
            $fields['pd_warranty'] = trim((string) ($row['pd_warranty'] ?? ''));
        }
        if (!$partialUpdate || $has('pd_price_srp')) {
            $fields['pd_price_srp'] = $this->toNumber($row['pd_price_srp'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_price_dp')) {
            $fields['pd_price_dp'] = $this->toNumber($row['pd_price_dp'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_price_member')) {
            $fields['pd_price_member'] = $has('pd_price_member') ? $this->toOptionalNumber($row['pd_price_member']) : null;
        }
        if (!$partialUpdate || $has('pd_prodpv')) {
            $fields['pd_prodpv'] = $this->toNumber($row['pd_prodpv'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_qty')) {
            $fields['pd_qty'] = $this->toNumber($row['pd_qty'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_weight')) {
            $fields['pd_weight'] = $this->toNumber($row['pd_weight'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_psweight')) {
            $fields['pd_psweight'] = $this->toNumber($row['pd_psweight'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_pswidth')) {
            $fields['pd_pswidth'] = $this->toNumber($row['pd_pswidth'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_pslenght')) {
            $fields['pd_pslenght'] = $this->toNumber($row['pd_pslenght'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_psheight')) {
            $fields['pd_psheight'] = $this->toNumber($row['pd_psheight'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_assembly_required')) {
            $fields['pd_assembly_required'] = $this->normalizeImportBoolean($row['pd_assembly_required'] ?? false) ? 1 : 0;
        }
        if (!$partialUpdate || $has('pd_parent_sku')) {
            $fields['pd_parent_sku'] = trim((string) ($row['pd_parent_sku'] ?? ''));
        }
        if (!$partialUpdate || $has('pd_type')) {
            $fields['pd_type'] = (int) ($row['pd_type'] ?? 0);
        }
        if (!$partialUpdate || $has('pd_musthave')) {
            $fields['pd_musthave'] = $this->normalizeImportBoolean($row['pd_musthave'] ?? false) ? 1 : 0;
        }
        if (!$partialUpdate || $has('pd_bestseller')) {
            $fields['pd_bestseller'] = $this->normalizeImportBoolean($row['pd_bestseller'] ?? false) ? 1 : 0;
        }
        if (!$partialUpdate || $has('pd_salespromo')) {
            $fields['pd_salespromo'] = $this->normalizeImportBoolean($row['pd_salespromo'] ?? false) ? 1 : 0;
        }
        if (!$partialUpdate || $has('pd_status')) {
            $fields['pd_status'] = (int) ($row['pd_status'] ?? 1);
        }
        if (!$partialUpdate || $hasImages) {
            $fields['pd_image'] = $images[0] ?? ($primaryImage !== '' ? $primaryImage : null);
        }

        $fields['pd_catsubid2']    = 0;
        $fields['pd_shopid']       = 0;
        $fields['pd_supplier']     = $supplierId;
        $fields['pd_preorder']     = '';
        $fields['pd_preorder_value'] = 0;
        $fields['pd_shoptype']     = 0;
        $fields['pd_user']         = 0;
        $fields['pd_usertype']     = 0;
        $fields['pd_last_update']  = $now;

        $product->fill($fields);

        if (!$product->exists) {
            $product->pd_date = $now;
        }

        $product->save();

        // Only replace photos when image data is explicitly provided
        if (!$partialUpdate || $hasImages) {
            ProductPhoto::query()->where('pp_pdid', $product->pd_id)->delete();
            foreach ($images as $url) {
                ProductPhoto::create([
                    'pp_pdid'    => $product->pd_id,
                    'pp_filename' => $url,
                    'pp_varone'  => null,
                    'pp_date'    => $now,
                ]);
            }
        }

        return $product->fresh(['photos']) ?? $product;
    }

    private function productChangeValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_float($value) || is_int($value) || is_numeric($value)) {
            $number = (float) $value;
            return fmod($number, 1.0) === 0.0
                ? (string) (int) $number
                : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    private function buildProductChangeLog(Product $before, Product $after): array
    {
        $fields = [
            'pd_name' => 'Name',
            'pd_parent_sku' => 'SKU',
            'pd_catid' => 'Category',
            'pd_brand_type' => 'Brand',
            'pd_price_srp' => 'SRP',
            'pd_price_dp' => 'Dealer Price',
            'pd_price_member' => 'Member Price',
            'pd_prodpv' => 'PV',
            'pd_qty' => 'Quantity',
            'pd_status' => 'Status',
            'pd_material' => 'Material',
            'pd_warranty' => 'Warranty',
            'pd_image' => 'Primary Image',
            'pd_manual_checkout_enabled' => 'Manual Checkout',
        ];

        $changes = [];
        foreach ($fields as $field => $label) {
            $beforeValue = $this->productChangeValue($before->{$field} ?? null);
            $afterValue = $this->productChangeValue($after->{$field} ?? null);

            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[] = [
                'field' => $label,
                'before' => $beforeValue !== '' ? $beforeValue : null,
                'after' => $afterValue !== '' ? $afterValue : null,
            ];
        }

        $beforeImages = $this->productImageUrls($before);
        $afterImages = $this->productImageUrls($after);

        if ($beforeImages !== $afterImages) {
            $changes[] = [
                'field' => 'Image Gallery',
                'before' => !empty($beforeImages) ? implode("\n", $beforeImages) : null,
                'after' => !empty($afterImages) ? implode("\n", $afterImages) : null,
            ];
        }

        return $changes;
    }

    private function productImageUrls(Product $product): array
    {
        $product->loadMissing('photos:pp_id,pp_pdid,pp_filename,pp_varone,pp_date');

        return $product->photos
            ->map(fn (ProductPhoto $photo) => trim((string) $photo->pp_filename))
            ->filter(fn (string $url) => $url !== '')
            ->unique(fn (string $url) => mb_strtolower($url, 'UTF-8'))
            ->values()
            ->all();
    }

    private function supplierCanUseCategory(int $supplierId, int $categoryId): bool
    {
        if ($supplierId <= 0 || $categoryId <= 0) {
            return false;
        }

        return SupplierCategoryAccess::query()
            ->where('supplier_id', $supplierId)
            ->where('category_id', $categoryId)
            ->exists();
    }

    private function mapVariants(Product $product): array
    {
        return $product->variants->map(function (ProductVariant $variant) {
            $images = $variant->photos
                ->map(fn (ProductVariantPhoto $photo) => trim((string) $photo->pvp_filename))
                ->filter(fn (string $url) => $url !== '')
                ->unique(fn (string $url) => mb_strtolower($url, 'UTF-8'))
                ->values()
                ->all();

            return [
                'id'       => (int) $variant->pv_id,
                'sku'      => (string) ($variant->pv_sku ?? ''),
                'name'     => (string) ($variant->pv_name ?? ''),
                'color'    => (string) ($variant->pv_color ?? ''),
                'colorHex' => (string) ($variant->pv_color_hex ?? ''),
                'size'     => (string) ($variant->pv_size ?? ''),
                'style'    => (string) ($variant->pv_style ?? ''),
                'width'    => $this->toOptionalNumber($variant->pv_width),
                'dimension' => $this->toOptionalNumber($variant->pv_dimension),
                'height'   => $this->toOptionalNumber($variant->pv_height),
                'priceSrp' => $this->toOptionalNumber($variant->pv_price_srp),
                'priceDp'  => $this->toOptionalNumber($variant->pv_price_dp),
                'priceMember' => $this->toOptionalNumber($variant->pv_price_member),
                'prodpv'   => $this->toOptionalNumber($variant->pv_prodpv),
                'qty'      => $this->toOptionalNumber($variant->pv_qty),
                'status'   => (int) ($variant->pv_status ?? 1),
                'images'   => $images,
            ];
        })->values()->all();
    }

    private function getEffectiveProductQty(Product $product): float
    {
        if (!$product->relationLoaded('variants')) {
            $product->load('variants');
        }

        $activeVariants = $product->variants
            ->filter(fn (ProductVariant $variant) => (int) ($variant->pv_status ?? 1) === 1);

        if ($activeVariants->isNotEmpty()) {
            return (float) $activeVariants->sum(fn (ProductVariant $variant) => $this->toNumber($variant->pv_qty));
        }

        return $this->toNumber($product->pd_qty);
    }

    private function syncVariants(Product $product, array $variants, \DateTimeInterface $now): void
    {
        // Pre-load existing variants keyed by pv_sku for fast lookup
        $existingVariants = ProductVariant::query()
            ->where('pv_pdid', $product->pd_id)
            ->whereNotNull('pv_sku')
            ->get();
        $existingBySku = $existingVariants
            ->keyBy('pv_sku');
        $keptVariantIds = [];

        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $sku       = isset($variant['pv_sku']) ? trim((string) $variant['pv_sku']) : '';
            $name      = isset($variant['pv_name']) ? trim((string) $variant['pv_name']) : '';
            $color     = isset($variant['pv_color']) ? trim((string) $variant['pv_color']) : '';
            $size      = isset($variant['pv_size']) ? trim((string) $variant['pv_size']) : '';
            $style     = isset($variant['pv_style']) ? trim((string) $variant['pv_style']) : '';
            $width     = isset($variant['pv_width']) && $variant['pv_width'] !== '' ? $variant['pv_width'] : null;
            $dimension = isset($variant['pv_dimension']) && $variant['pv_dimension'] !== '' ? $variant['pv_dimension'] : null;
            $height    = isset($variant['pv_height']) && $variant['pv_height'] !== '' ? $variant['pv_height'] : null;
            $hasImageList = array_key_exists('pv_images', $variant);
            $images    = collect($variant['pv_images'] ?? [])
                ->filter(fn ($url) => is_string($url) && trim($url) !== '')
                ->map(fn ($url) => trim((string) $url))
                ->unique(fn (string $url) => mb_strtolower($url, 'UTF-8'))
                ->values()
                ->all();

            if ($sku === '' && $name === '' && $color === '' && $size === '' && $style === '' && $width === null && $dimension === null && $height === null && empty($images)) {
                continue;
            }

            $fields = [
                'pv_name'         => $name !== '' ? $name : null,
                'pv_color'        => $color !== '' ? $color : null,
                'pv_color_hex'    => isset($variant['pv_color_hex']) ? trim((string) $variant['pv_color_hex']) : null,
                'pv_size'         => $size !== '' ? $size : null,
                'pv_style'        => $style !== '' ? $style : null,
                'pv_width'        => $width,
                'pv_dimension'    => $dimension,
                'pv_height'       => $height,
                'pv_price_srp'    => isset($variant['pv_price_srp']) && $variant['pv_price_srp'] !== '' ? $variant['pv_price_srp'] : null,
                'pv_price_dp'     => isset($variant['pv_price_dp']) && $variant['pv_price_dp'] !== '' ? $variant['pv_price_dp'] : null,
                'pv_price_member' => isset($variant['pv_price_member']) && $variant['pv_price_member'] !== '' ? $variant['pv_price_member'] : null,
                'pv_prodpv'       => isset($variant['pv_prodpv']) && $variant['pv_prodpv'] !== '' ? $variant['pv_prodpv'] : null,
                'pv_qty'          => isset($variant['pv_qty']) && $variant['pv_qty'] !== '' ? $variant['pv_qty'] : null,
                'pv_status'       => isset($variant['pv_status']) ? (int) $variant['pv_status'] : 1,
            ];

            $existing = $sku !== '' ? ($existingBySku[$sku] ?? null) : null;

            if ($existing) {
                // Update existing variant matched by SKU
                $existing->update($fields);
                $variantRow = $existing;
            } else {
                // Insert new variant
                $variantRow = ProductVariant::create(array_merge($fields, [
                    'pv_pdid' => $product->pd_id,
                    'pv_sku'  => $sku !== '' ? $sku : null,
                    'pv_date' => $now,
                ]));
            }

            $keptVariantIds[] = (int) $variantRow->pv_id;

            // Replace photos when the image list is explicitly provided, even when empty.
            if ($hasImageList) {
                ProductVariantPhoto::query()->where('pvp_pvid', $variantRow->pv_id)->delete();
                foreach ($images as $imgIndex => $url) {
                    ProductVariantPhoto::create([
                        'pvp_pvid'     => $variantRow->pv_id,
                        'pvp_filename' => $url,
                        'pvp_sort'     => $imgIndex,
                        'pvp_date'     => $now,
                    ]);
                }
            }
        }

        $staleVariantQuery = ProductVariant::query()->where('pv_pdid', $product->pd_id);
        if (!empty($keptVariantIds)) {
            $staleVariantQuery->whereNotIn('pv_id', array_values(array_unique($keptVariantIds)));
        }
        $staleVariantIds = $staleVariantQuery->pluck('pv_id')->all();

        if (!empty($staleVariantIds)) {
            ProductVariantPhoto::query()->whereIn('pvp_pvid', $staleVariantIds)->delete();
            ProductVariant::query()->whereIn('pv_id', $staleVariantIds)->delete();
        }
    }

    private function mapProduct(Product $p, int $soldCount = 0, float $avgRating = 0.0): array
    {
        $images = $p->photos
            ->map(fn (ProductPhoto $photo) => trim((string) $photo->pp_filename))
            ->filter(fn (string $url) => $url !== '')
            ->unique(fn (string $url) => mb_strtolower($url, 'UTF-8'))
            ->values()
            ->all();

        $primaryImage = $images[0] ?? ($p->pd_image ?? null);
        $effectiveQty = $this->getEffectiveProductQty($p);

        return [
            'id'          => (int)   $p->pd_id,
            'soldCount'    => (int)   $soldCount,
            'avgRating'    => round((float) $avgRating, 2),
            'supplierId'  => (int)   ($p->pd_supplier ?? 0),
            'supplierName' => $p->supplier
                ? (trim((string) ($p->supplier->s_company ?? '')) !== ''
                    ? trim((string) $p->supplier->s_company)
                    : (trim((string) ($p->supplier->s_name ?? '')) !== '' ? trim((string) $p->supplier->s_name) : null))
                : null,
            'name'        => (string) ($p->pd_name ?? ''),
            'description'       => $p->pd_description ?? null,
            'specifications'    => $p->pd_specifications ?? null,
            'material'          => $p->pd_material ?? null,
            'warranty'          => $p->pd_warranty ?? null,
            'catid'             => (int)   $p->pd_catid,
            'catsubid'          => (int)   $p->pd_catsubid,
            'roomType'          => (int)   ($p->pd_room_type ?? 0),
            'brandType'         => (int)   ($p->pd_brand_type ?? 0),
            'brand'             => $p->brand?->pb_name ? (string) $p->brand->pb_name : null,
            'priceSrp'          => $this->toNumber($p->pd_price_srp),
            'priceDp'           => $this->toNumber($p->pd_price_dp),
            'priceMember'       => $this->toNumber($p->pd_price_member),
            'prodpv'            => $this->toNumber($p->pd_prodpv),
            'qty'               => $effectiveQty,
            'weight'            => (int)   $p->pd_weight,
            'psweight'          => $this->toNumber($p->pd_psweight),
            'pswidth'           => $this->toNumber($p->pd_pswidth),
            'pslenght'          => $this->toNumber($p->pd_pslenght),
            'psheight'          => $this->toNumber($p->pd_psheight),
            'assemblyRequired'  => (bool) $p->pd_assembly_required,
            'type'        => (int)   $p->pd_type,
            'musthave'    => (bool)  $p->pd_musthave,
            'bestseller'  => (bool)  $p->pd_bestseller,
            'salespromo'  => (bool)  $p->pd_salespromo,
            'manualCheckoutEnabled' => (bool) ($p->pd_manual_checkout_enabled ?? false),
            'status'      => (int)   $p->pd_status,
            'sku'         => (string) ($p->pd_parent_sku ?? ''),
            'uploaderName' => $p->creationActivity?->pal_actor_name ? (string) $p->creationActivity->pal_actor_name : null,
            'uploaderEmail' => $p->creationActivity?->pal_actor_email ? (string) $p->creationActivity->pal_actor_email : null,
            'uploaderRole' => $p->creationActivity?->pal_actor_role ? (string) $p->creationActivity->pal_actor_role : null,
            'image'       => $primaryImage,
            'images'      => $images,
            'variants'    => $this->mapVariants($p),
            'createdAt'   => $p->pd_date ? $p->pd_date->format('Y-m-d H:i:s') : null,
            'updatedAt'   => $p->pd_last_update ? $p->pd_last_update->format('Y-m-d H:i:s') : null,
        ];
    }

    public function showBySlug(string $slug): JsonResponse
    {
        $normalizedSlug = $this->normalizeSlug($slug);
        if ($normalizedSlug === '') {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $slugExpr = "trim(both '-' from regexp_replace(lower(coalesce(pd_name, '')), '[^a-z0-9]+', '-', 'g'))";

        $product = Product::query()
            ->select([
                'pd_id', 'pd_name', 'pd_description', 'pd_specifications', 'pd_material', 'pd_warranty',
                'pd_catid', 'pd_catsubid', 'pd_room_type', 'pd_brand_type', 'pd_supplier',
                'pd_price_srp', 'pd_price_dp', 'pd_price_member', 'pd_qty',
                'pd_prodpv',
                'pd_weight', 'pd_psweight', 'pd_pswidth', 'pd_pslenght', 'pd_psheight',
                'pd_assembly_required', 'pd_type', 'pd_musthave',
                'pd_bestseller', 'pd_salespromo', 'pd_manual_checkout_enabled', 'pd_status', 'pd_date',
                'pd_last_update', 'pd_parent_sku', 'pd_image',
            ])
            ->with([
                'photos:pp_id,pp_pdid,pp_filename,pp_varone,pp_date',
                'brand:pb_id,pb_name,pb_status',
                'variants:pv_id,pv_pdid,pv_sku,pv_name,pv_color,pv_color_hex,pv_size,pv_style,pv_width,pv_dimension,pv_height,pv_price_srp,pv_price_dp,pv_price_member,pv_prodpv,pv_qty,pv_status,pv_date',
                'variants.photos:pvp_id,pvp_pvid,pvp_filename,pvp_sort,pvp_date',
            ])
            ->tap(fn ($query) => $this->applyPublicVisibility($query))
            ->whereRaw("{$slugExpr} = ?", [$normalizedSlug])
            ->orderByDesc('pd_id')
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json([
            'product' => $this->mapProduct($product),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $product = Product::query()
            ->select([
                'pd_id', 'pd_name', 'pd_description', 'pd_specifications', 'pd_material', 'pd_warranty',
                'pd_catid', 'pd_catsubid', 'pd_room_type', 'pd_brand_type', 'pd_supplier',
                'pd_price_srp', 'pd_price_dp', 'pd_price_member', 'pd_qty',
                'pd_prodpv',
                'pd_weight', 'pd_psweight', 'pd_pswidth', 'pd_pslenght', 'pd_psheight',
                'pd_assembly_required', 'pd_type', 'pd_musthave',
                'pd_bestseller', 'pd_salespromo', 'pd_manual_checkout_enabled', 'pd_status', 'pd_date',
                'pd_last_update', 'pd_parent_sku', 'pd_image',
            ])
            ->with([
                'photos:pp_id,pp_pdid,pp_filename,pp_varone,pp_date',
                'brand:pb_id,pb_name,pb_status',
                'variants:pv_id,pv_pdid,pv_sku,pv_name,pv_color,pv_color_hex,pv_size,pv_style,pv_width,pv_dimension,pv_height,pv_price_srp,pv_price_dp,pv_price_member,pv_prodpv,pv_qty,pv_status,pv_date',
                'variants.photos:pvp_id,pvp_pvid,pvp_filename,pvp_sort,pvp_date',
            ])
            ->tap(fn ($query) => $this->applyPublicVisibility($query))
            ->where('pd_id', $id)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        // Calculate sold count from checkout history
        $soldCount = DB::table('tbl_checkout_history')
            ->where('ch_product_id', $id)
            ->whereIn('ch_status', ['paid', 'completed', 'shipped'])
            ->sum('ch_quantity');

        return response()->json([
            'product' => $this->mapProduct($product, $soldCount),
        ]);
    }

    public function showSummary(int $id): JsonResponse
    {
        $product = Product::query()
            ->select([
                'pd_id', 'pd_name', 'pd_image',
                'pd_price_srp', 'pd_price_dp',
                'pd_prodpv', 'pd_brand_type',
                'pd_musthave', 'pd_bestseller', 'pd_salespromo',
                'pd_qty',
            ])
            ->with([
                'photos:pp_id,pp_pdid,pp_filename',
                'brand:pb_id,pb_name',
            ])
            ->tap(fn ($query) => $this->applyPublicVisibility($query))
            ->where('pd_id', $id)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $soldCount = (int) DB::table('tbl_checkout_history')
            ->where('ch_product_id', $id)
            ->whereIn('ch_status', ['paid', 'completed', 'shipped'])
            ->sum('ch_quantity');

        return response()->json([
            'product' => $this->mapProductSummary($product, $soldCount),
        ]);
    }

    private function mapProductSummary(Product $p, int $soldCount = 0): array
    {
        $primaryImage = $p->photos->first()?->pp_filename ?? $p->pd_image ?? null;

        
        return [
            'id'             => (int)    $p->pd_id,
            'name'           => (string) ($p->pd_name ?? ''),
            'image'          => $primaryImage ? (string) $primaryImage : null,
            'soldCount'      => $soldCount,
            'originalPrice'  => $originalPrice,
            'discountedPrice'=> $memberPrice,
            'pv'             => $this->toNumber($p->pd_prodpv),
            'brandName'      => $p->brand?->pb_name ? (string) $p->brand->pb_name : null,
            'variantCount'   => (int) ($p->variants_count ?? 0),
            'badges'         => [
                'musthave'   => (bool) $p->pd_musthave,
                'bestseller' => (bool) $p->pd_bestseller,
                'salespromo' => (bool) $p->pd_salespromo,
            ],
        ];
    }

    public function indexCards(Request $request): JsonResponse
    {
        try {
            \Log::info('indexCards: Starting request', [
                'query_params' => $request->all(),
                'timestamp' => now()
            ]);

            $perPageParam = $request->query('per_page', 25);
            $perPage = strtolower(trim((string) $perPageParam)) === 'all'
                ? PHP_INT_MAX
                : max(1, (int) $perPageParam);

            $search    = trim((string) $request->query('q', ''));
            $catId     = $request->query('cat_id', '');
            $roomType  = $request->query('room_type', '');
            $brandType = $request->query('brand_type', '');

            \Log::info('indexCards: Building query', [
                'perPage' => $perPage,
                'search' => $search,
                'catId' => $catId,
                'roomType' => $roomType,
                'brandType' => $brandType
            ]);

            $query = Product::query()
                ->select([
                    'pd_id', 'pd_name', 'pd_image',
                    'pd_price_srp', 'pd_price_member',
                    'pd_prodpv', 'pd_brand_type',
                    'pd_musthave', 'pd_bestseller', 'pd_salespromo',
                ])
                ->with([
                    'photos:pp_id,pp_pdid,pp_filename',
                    'brand:pb_id,pb_name',
                ])
                ->withCount('variants')
                ->tap(fn ($q) => $this->applyPublicVisibility($q))
                ->when($search !== '', fn ($q) => $this->applyKeywordSearch($q, $search))
                ->when($catId !== '', fn ($q) => $q->where('pd_catid', (int) $catId))
                ->when($roomType !== '', fn ($q) => $q->where('pd_room_type', (int) $roomType))
                ->when($brandType !== '', fn ($q) => $q->where('pd_brand_type', (int) $brandType))
                ->orderByDesc('pd_id');

            \Log::info('indexCards: Executing query');
            $paginator = $query->paginate($perPage);
            \Log::info('indexCards: Query executed', [
                'total_results' => $paginator->total(),
                'current_page' => $paginator->currentPage()
            ]);

            $productIds = collect($paginator->items())->pluck('pd_id')->toArray();
            \Log::info('indexCards: Got product IDs', ['count' => count($productIds)]);

            $soldCounts = DB::table('tbl_checkout_history')
                ->whereIn('ch_product_id', $productIds)
                ->whereIn('ch_status', ['paid', 'completed', 'shipped'])
                ->groupBy('ch_product_id')
                ->selectRaw('ch_product_id as product_id, SUM(ch_quantity) as sold_count')
                ->pluck('sold_count', 'product_id');

            \Log::info('indexCards: Got sold counts', ['sold_counts' => $soldCounts]);

            $products = collect($paginator->items())
                ->map(function (Product $p) use ($soldCounts) {
                    $soldCount = (int) ($soldCounts->get($p->pd_id, 0));
                    return $this->mapProductSummary($p, $soldCount);
                })
                ->values();

            \Log::info('indexCards: Products mapped successfully', ['product_count' => $products->count()]);

            return response()->json([
                'products' => $products,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('indexCards: Exception occurred', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Failed to fetch products.',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    public function brand(int $id): JsonResponse
    {
        $product = Product::query()
            ->select(['pd_id', 'pd_brand_type', 'pd_supplier'])
            ->with('brand')
            ->tap(fn ($query) => $this->applyPublicVisibility($query))
            ->where('pd_id', $id)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        if (! $product->brand) {
            return response()->json(['message' => 'Brand not found for this product.'], 404);
        }

        $joinedDate = null;
        if ($product->pd_supplier) {
            $supplierUser = DB::table('tbl_supplier_user')
                ->where('su_supplier', $product->pd_supplier)
                ->select('su_date_created')
                ->first();

            if ($supplierUser && $supplierUser->su_date_created) {
                $joinedDate = (string) $supplierUser->su_date_created;
            }
        }

        $ratingRow = DB::table('tbl_product_reviews')
            ->where('pr_product_id', $id)
            ->selectRaw('COALESCE(SUM(pr_rating), 0) as total_stars, COUNT(*) as review_count')
            ->first();

        $overallRating = $ratingRow && (int) ($ratingRow->review_count ?? 0) > 0
            ? round(((float) ($ratingRow->total_stars ?? 0)) / (int) $ratingRow->review_count, 2)
            : null;

        $totalReviews = $ratingRow ? (int) $ratingRow->review_count : 0;

        $totalProducts = DB::table('tbl_product')
            ->where('pd_brand_type', $product->pd_brand_type)
            ->whereIn('pd_status', [1, 2])
            ->count();

        return response()->json([
            'brand' => [
                'id' => (int) $product->brand->pb_id,
                'name' => (string) ($product->brand->pb_name ?? ''),
                'image' => $product->brand->pb_image ?? null,
                'status' => (int) ($product->brand->pb_status ?? 0),
            ],
            'supplier_user' => [
                'joined_date' => $joinedDate,
            ],
            'overall_rating' => $overallRating,
            'total_reviews' => $totalReviews,
            'total_products' => $totalProducts,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $admin = $this->resolveAdmin($request);
            $supplierUser = $this->resolveSupplierUser($request);
            $perPageParam = $request->query('per_page', 25);
            if (strtolower(trim((string) $perPageParam)) === 'all') {
                $perPage = PHP_INT_MAX;
            } else {
                $perPage = max(1, (int) $perPageParam);
            }
            $search  = trim((string) $request->query('q', ''));
            $status  = $request->query('status', '');
            $catId   = $request->query('cat_id', '');
            $roomType = $request->query('room_type', '');
            $brandType = $request->query('brand_type', '');
            $requestedSupplierId = (int) $request->query('supplier_id', 0);

            $query = Product::query()
                ->select([
                    'pd_id', 'pd_name', 'pd_description', 'pd_specifications', 'pd_material', 'pd_warranty',
                    'pd_catid', 'pd_catsubid', 'pd_room_type', 'pd_brand_type', 'pd_supplier',
                    'pd_price_srp', 'pd_price_dp', 'pd_price_member', 'pd_qty',
                    'pd_prodpv',
                    'pd_weight', 'pd_psweight', 'pd_pswidth', 'pd_pslenght', 'pd_psheight',
                    'pd_assembly_required', 'pd_type', 'pd_musthave',
                    'pd_bestseller', 'pd_salespromo', 'pd_manual_checkout_enabled', 'pd_status', 'pd_date',
                    'pd_last_update', 'pd_parent_sku', 'pd_image',
                ])
                ->with([
                    'photos:pp_id,pp_pdid,pp_filename,pp_varone,pp_date',
                    'brand:pb_id,pb_name,pb_status',
                    'supplier:s_id,s_company,s_name',
                    'creationActivity:pal_id,pal_product_id,pal_actor_name,pal_actor_email,pal_actor_role,pal_created_at',
                    'variants:pv_id,pv_pdid,pv_sku,pv_name,pv_color,pv_color_hex,pv_size,pv_style,pv_width,pv_dimension,pv_height,pv_price_srp,pv_price_dp,pv_price_member,pv_prodpv,pv_qty,pv_status,pv_date',
                    'variants.photos:pvp_id,pvp_pvid,pvp_filename,pvp_sort,pvp_date',
                ])
                ->when(! $admin && ! $supplierUser, function ($q) {
                    // Public catalog access should only expose active products.
                    $this->applyPublicVisibility($q);
                })
                ->when($search !== '', function ($q) use ($search) {
                    $this->applyKeywordSearch($q, $search);
                })
                ->when($status !== '', function ($q) use ($status) {
                    $normalizedStatus = (int) $status;
                    if ($normalizedStatus === 1) {
                        $q->whereIn('pd_status', [1, 2]);
                        return;
                    }

                    $q->where('pd_status', $normalizedStatus);
                })
                ->when($catId !== '', function ($q) use ($catId) {
                    $q->where('pd_catid', (int) $catId);
                })
                ->when($roomType !== '', function ($q) use ($roomType) {
                    $q->where('pd_room_type', (int) $roomType);
                })
                ->when($brandType !== '', function ($q) use ($brandType) {
                    $q->where('pd_brand_type', (int) $brandType);
                })
                ->orderByDesc('pd_id');

            if ($supplierUser) {
                $supplierId = (int) $supplierUser->su_supplier;
                $brandTypeValue = $brandType !== '' ? (int) $brandType : 0;
                if ($brandTypeValue <= 0 && $supplierId > 0) {
                    $brandTypeValue = $this->resolveSupplierBrandType($supplierId);
                }
                if ($brandTypeValue > 0) {
                    $query->where(function ($q) use ($supplierId, $brandTypeValue) {
                        $q->where('pd_supplier', $supplierId)
                          ->orWhere('pd_brand_type', $brandTypeValue);
                    });
                } else {
                    $query->where('pd_supplier', $supplierId);
                }
            } elseif ($admin && $this->roleFromLevel((int) $admin->user_level_id) === 'supplier_admin') {
                $supplierId = (int) ($admin->supplier_id ?? 0);
                $brandTypeValue = $brandType !== '' ? (int) $brandType : 0;
                if ($brandTypeValue <= 0 && $supplierId > 0) {
                    $brandTypeValue = $this->resolveSupplierBrandType($supplierId);
                }
                if ($brandTypeValue > 0) {
                    $query->where(function ($q) use ($supplierId, $brandTypeValue) {
                        $q->where('pd_supplier', $supplierId > 0 ? $supplierId : -1)
                          ->orWhere('pd_brand_type', $brandTypeValue);
                    });
                } else {
                    $query->where('pd_supplier', $supplierId > 0 ? $supplierId : -1);
                }
            } elseif ($requestedSupplierId > 0 && $admin) {
                $query->where('pd_supplier', $requestedSupplierId);
            }

            $paginator = $query->paginate($perPage);

            // Get all product IDs for batch sold count and rating calculation
            $productIds = collect($paginator->items())->pluck('pd_id')->toArray();
            
            // Calculate sold counts for all products in one query
            $soldCounts = DB::table('tbl_checkout_history')
                ->whereIn('ch_product_id', $productIds)
                ->whereIn('ch_status', ['paid', 'completed', 'shipped'])
                ->groupBy('ch_product_id')
                ->selectRaw('SUM(ch_quantity) as sold_count, ch_product_id as product_id')
                ->pluck('sold_count', 'product_id');

            // Calculate average ratings for all products in one query
            $ratings = DB::table('tbl_product_reviews')
                ->whereIn('pr_product_id', $productIds)
                ->groupBy('pr_product_id')
                ->selectRaw('pr_product_id, AVG(pr_rating) as avg_rating, COUNT(*) as review_count')
                ->pluck('avg_rating', 'pr_product_id');

            $products = collect($paginator->items())
                ->map(fn (Product $p) => $this->mapProduct($p, $soldCounts->get($p->pd_id, 0), $ratings->get($p->pd_id, 0)))
                ->values();

            return response()->json([
                'products' => $products,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Product index failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'sql' => method_exists($e, 'getSql') ? $e->getSql() : null,
                'bindings' => method_exists($e, 'getBindings') ? $e->getBindings() : null,
                'query' => $request->query(),
                'actor_id' => $request->user()?->getAuthIdentifier(),
                'actor_type' => $request->user() ? $request->user()::class : null,
            ]);

            return response()->json([
                'message' => config('app.debug')
                    ? 'Failed to load products: ' . $e->getMessage()
                    : 'Failed to load products.',
            ], 500);
        }
    }

    public function activityLogs(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        $supplierUser = $this->resolveSupplierUser($request);
        $scope = strtolower(trim((string) $request->query('scope', 'my')));
        $search = trim((string) $request->query('search', ''));
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        $query = ProductActivityLog::query()
            ->orderByDesc('pal_created_at')
            ->orderByDesc('pal_id');

        if ($supplierUser) {
            $query->where('pal_supplier_user_id', (int) $supplierUser->su_id);
        } elseif ($admin) {
            $role = $this->roleFromLevel((int) $admin->user_level_id);
            $isSuperAdmin = $role === 'super_admin';

            if (!($isSuperAdmin && $scope === 'all')) {
                $query->where('pal_admin_id', (int) $admin->id);
            }
        }

        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $like = '%' . $search . '%';
                $inner->where('pal_product_name', 'ilike', $like)
                    ->orWhere('pal_product_sku', 'ilike', $like)
                    ->orWhere('pal_actor_name', 'ilike', $like)
                    ->orWhere('pal_actor_email', 'ilike', $like);
            });
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'logs' => collect($paginator->items())
                ->map(fn (ProductActivityLog $log) => $this->mapProductActivityLog($log))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        $supplierUser = $this->resolveSupplierUser($request);
        $actorSupplierId = $this->actorSupplierId($admin, $supplierUser);

        if ($admin && $this->roleFromLevel((int) $admin->user_level_id) === 'supplier_admin' && !$admin->supplier_id) {
            return response()->json([
                'message' => 'Supplier Admin account is not linked to a supplier company.',
            ], 422);
        }

        $validated = $request->validate([
            'mode'                               => 'nullable|in:create_only,create_or_update',
            'rows'                               => 'required|array|min:1|max:500',
            'rows.*'                             => 'required|array',
            'rows.*.pd_name'                     => 'nullable|string|max:255',
            'rows.*.pd_parent_sku'               => 'nullable|string|max:80',
            'rows.*.pd_catid'                    => 'nullable|integer|min:1',
            'rows.*.pd_room_type'                => 'nullable|integer|min:0',
            'rows.*.pd_brand_type'               => 'nullable|integer|min:0',
            'rows.*.pd_price_srp'                => 'nullable|numeric|min:0',
            'rows.*.pd_price_dp'                 => 'nullable|numeric|min:0',
            'rows.*.pd_price_member'             => 'nullable|numeric|min:0',
            'rows.*.pd_prodpv'                   => 'nullable|numeric|min:0',
            'rows.*.pd_pricing_tier'             => 'nullable|string|max:50',
            'rows.*.pd_reversed_pv_multiplier'   => 'nullable|string|max:20',
            'rows.*.pd_qty'                      => 'nullable|numeric|min:0',
            'rows.*.pd_weight'                   => 'nullable|numeric|min:0',
            'rows.*.pd_psweight'                 => 'nullable|numeric|min:0',
            'rows.*.pd_pswidth'                  => 'nullable|numeric|min:0',
            'rows.*.pd_pslenght'                 => 'nullable|numeric|min:0',
            'rows.*.pd_psheight'                 => 'nullable|numeric|min:0',
            'rows.*.pd_description'              => 'nullable|string',
            'rows.*.pd_specifications'           => 'nullable|string',
            'rows.*.pd_material'                 => 'nullable|string|max:255',
            'rows.*.pd_warranty'                 => 'nullable|string|max:255',
            'rows.*.pd_image'                    => 'nullable|string|max:500',
            'rows.*.pd_images'                   => 'nullable|array',
            'rows.*.pd_images.*'                 => 'nullable|string|max:1000',
            'rows.*.pd_type'                     => 'nullable|integer|in:0,1',
            'rows.*.pd_status'                   => 'nullable|integer|in:0,1,2',
            'rows.*.pd_musthave'                 => 'nullable|integer|in:0,1',
            'rows.*.pd_bestseller'               => 'nullable|integer|in:0,1',
            'rows.*.pd_salespromo'               => 'nullable|integer|in:0,1',
            'rows.*.pd_assembly_required'        => 'nullable|integer|in:0,1',
            'rows.*.pd_verified'                 => 'nullable|integer|in:0,1',
            'rows.*.pd_variants'                 => 'nullable|array',
            'rows.*.pd_variants.*.pv_sku'        => 'nullable|string|max:80',
            'rows.*.pd_variants.*.pv_name'       => 'nullable|string|max:120',
            'rows.*.pd_variants.*.pv_color'      => 'nullable|string|max:80',
            'rows.*.pd_variants.*.pv_color_hex'  => 'nullable|string|max:16',
            'rows.*.pd_variants.*.pv_size'       => 'nullable|string|max:40',
            'rows.*.pd_variants.*.pv_style'      => 'nullable|string|max:80',
            'rows.*.pd_variants.*.pv_width'      => 'nullable|numeric|min:0',
            'rows.*.pd_variants.*.pv_dimension'  => 'nullable|numeric|min:0',
            'rows.*.pd_variants.*.pv_height'     => 'nullable|numeric|min:0',
            'rows.*.pd_variants.*.pv_price_srp'  => 'nullable|numeric|min:0',
            'rows.*.pd_variants.*.pv_price_dp'   => 'nullable|numeric|min:0',
            'rows.*.pd_variants.*.pv_price_member' => 'nullable|numeric|min:0',
            'rows.*.pd_variants.*.pv_prodpv'     => 'nullable|numeric|min:0',
            'rows.*.pd_variants.*.pv_qty'        => 'nullable|numeric|min:0',
            'rows.*.pd_variants.*.pv_status'     => 'nullable|integer|in:0,1',
            'rows.*.pd_variants.*.pv_images'     => 'nullable|array',
            'rows.*.pd_variants.*.pv_images.*'   => 'nullable|string|max:1000',
        ]);

        $mode = (string) ($validated['mode'] ?? 'create_or_update');
        $rawRows = $validated['rows'];

        if (!is_array($rawRows) || count($rawRows) === 0) {
            return response()->json([
                'message' => 'No rows were processed.',
                'errors' => [
                    'rows' => ['The import payload did not contain any usable rows.'],
                ],
                'debug' => [
                    'received_keys' => array_keys($validated),
                    'rows_type' => gettype($rawRows),
                    'rows_count' => is_array($rawRows) ? count($rawRows) : null,
                ],
            ], 422);
        }

        $now = now();
        $results = [];
        $created = 0;
        $updated = 0;
        $failed = 0;

        // Normalize and group flat spreadsheet rows into products with variants
        $rows = $this->groupImportRows($rawRows);

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $name = trim((string) ($row['pd_name'] ?? ''));
            $sku = trim((string) ($row['pd_parent_sku'] ?? ''));

            try {
                if ($name === '') {
                    throw new \RuntimeException('Product name is required.');
                }

                // Resolve whether the product already exists before running create-only checks
                $product = null;
                $beforeProduct = null;
                $status = 'created';
                $message = 'Product imported successfully.';

                if ($sku !== '') {
                    $productQuery = Product::query()->where('pd_parent_sku', $sku);
                    $this->scopeQueryToActor($productQuery, $admin, $supplierUser);
                    $product = $productQuery->first();
                }

                if ($product) {
                    if ($mode !== 'create_or_update') {
                        throw new \RuntimeException('Duplicate SKU found. Use create or update mode to update existing products.');
                    }

                    $beforeProduct = clone $product;
                    $status = 'updated';
                    $message = 'Existing product updated.';
                } else {
                    $product = new Product();
                }

                $isNew = $status === 'created';

                // These checks are only required when creating a new product
                if ($isNew) {
                    $categoryId = (int) ($row['pd_catid'] ?? 0);
                    if ($categoryId <= 0) {
                        throw new \RuntimeException('Category ID is required.');
                    }

                    if ($actorSupplierId > 0 && !$this->supplierCanUseCategory($actorSupplierId, $categoryId)) {
                        throw new \RuntimeException('This supplier is not allowed to use the selected category.');
                    }

                    $priceSrp = $this->toNumber($row['pd_price_srp'] ?? null);
                    if ($priceSrp < 0.01) {
                        throw new \RuntimeException('SRP must be greater than zero.');
                    }
                }

                $brandType = (int) ($row['pd_brand_type'] ?? 0);
                if ($brandType > 0 && !ProductBrand::query()->where('pb_id', $brandType)->exists()) {
                    throw new \RuntimeException('The selected brand does not exist.');
                }

                $product = DB::transaction(function () use ($product, $row, $actorSupplierId, $now, $isNew) {
                    return $this->fillProductFromImportRow($product, $row, $actorSupplierId, $now, !$isNew);
                });

                if (!empty($row['pd_variants'])) {
                    $this->syncVariants($product, $row['pd_variants'], $now);
                }

                if ($status === 'updated') {
                    $updated++;
                } else {
                    $created++;
                }

                $changes = $status === 'updated' && $beforeProduct instanceof Product
                    ? $this->buildProductChangeLog($beforeProduct, $product)
                    : null;

                $this->recordProductActivity($status === 'updated' ? 'updated' : 'created', $product, $admin, $supplierUser, $name, $sku, $changes);

                $results[] = [
                    'row' => $rowNumber,
                    'status' => $status,
                    'product_id' => (int) $product->pd_id,
                    'name' => $product->pd_name,
                    'sku' => $product->pd_parent_sku ?: null,
                    'message' => $message,
                ];
            } catch (\Throwable $e) {
                $failed++;
                $this->recordFailedProductActivity('created', $admin, $supplierUser, null, $name, $sku);

                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'name' => $name !== '' ? $name : null,
                    'sku' => $sku !== '' ? $sku : null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => $failed > 0
                ? 'Bulk import finished with some row errors.'
                : 'Bulk import completed successfully.',
            'summary' => [
                'total' => count($rows),
                'created' => $created,
                'updated' => $updated,
                'failed' => $failed,
            ],
            'results' => $results,
        ]);
    }

    public function bulkPricePreview(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        $supplierUser = $this->resolveSupplierUser($request);

        if ($admin && $this->roleFromLevel((int) $admin->user_level_id) === 'supplier_admin' && !$admin->supplier_id) {
            return response()->json([
                'message' => 'Supplier Admin account is not linked to a supplier company.',
            ], 422);
        }

        $validated = $request->validate([
            'rows' => 'required|array|min:1|max:500',
            'rows.*' => 'required|array',
        ]);

        $rows = $validated['rows'];
        $results = [];
        $failed = 0;

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $sku = trim((string) ($row['sku'] ?? $row['pd_parent_sku'] ?? ''));
            $id = (int) ($row['id'] ?? $row['pd_id'] ?? 0);
            $priceSrp = array_key_exists('price_srp', $row) ? $this->toOptionalNumber($row['price_srp']) : (array_key_exists('pd_price_srp', $row) ? $this->toOptionalNumber($row['pd_price_srp']) : null);
            $priceDp = array_key_exists('price_dp', $row) ? $this->toOptionalNumber($row['price_dp']) : (array_key_exists('pd_price_dp', $row) ? $this->toOptionalNumber($row['pd_price_dp']) : null);
            $priceMember = array_key_exists('price_member', $row) ? $this->toOptionalNumber($row['price_member']) : (array_key_exists('pd_price_member', $row) ? $this->toOptionalNumber($row['pd_price_member']) : null);

            if ($sku === '' && $id <= 0) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => null,
                    'message' => 'SKU or Product ID is required.',
                ];
                continue;
            }

            if ($priceSrp === null && $priceDp === null && $priceMember === null) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => null,
                    'message' => 'At least one price field is required.',
                ];
                continue;
            }

            $productQuery = Product::query();
            if ($id > 0) {
                $productQuery->where('pd_id', $id);
            } else {
                $productQuery->where('pd_parent_sku', $sku);
            }
            $this->scopeQueryToActor($productQuery, $admin, $supplierUser);
            $product = $productQuery->first();

            if (!$product) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => null,
                    'message' => 'Product not found or not accessible.',
                ];
                continue;
            }

            $results[] = [
                'row' => $rowNumber,
                'status' => 'ready',
                'product_id' => (int) $product->pd_id,
                'sku' => $product->pd_parent_sku ?: null,
                'name' => $product->pd_name,
                'current' => [
                    'price_srp' => (float) $product->pd_price_srp,
                    'price_dp' => (float) $product->pd_price_dp,
                    'price_member' => $product->pd_price_member !== null ? (float) $product->pd_price_member : null,
                ],
                'next' => [
                    'price_srp' => $priceSrp,
                    'price_dp' => $priceDp,
                    'price_member' => $priceMember,
                ],
                'message' => 'Ready for update.',
            ];
        }

        return response()->json([
            'message' => $failed > 0
                ? 'Preview generated with some row errors.'
                : 'Preview generated successfully.',
            'summary' => [
                'total' => count($rows),
                'ready' => count($rows) - $failed,
                'failed' => $failed,
            ],
            'results' => $results,
        ]);
    }

    public function bulkPriceApply(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        $supplierUser = $this->resolveSupplierUser($request);

        if ($admin && $this->roleFromLevel((int) $admin->user_level_id) === 'supplier_admin' && !$admin->supplier_id) {
            return response()->json([
                'message' => 'Supplier Admin account is not linked to a supplier company.',
            ], 422);
        }

        $validated = $request->validate([
            'rows' => 'required|array|min:1|max:500',
            'rows.*' => 'required|array',
        ]);

        $rows = $validated['rows'];
        $results = [];
        $updated = 0;
        $failed = 0;
        $now = now();

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $sku = trim((string) ($row['sku'] ?? $row['pd_parent_sku'] ?? ''));
            $id = (int) ($row['id'] ?? $row['pd_id'] ?? 0);
            $priceSrp = array_key_exists('price_srp', $row) ? $this->toOptionalNumber($row['price_srp']) : (array_key_exists('pd_price_srp', $row) ? $this->toOptionalNumber($row['pd_price_srp']) : null);
            $priceDp = array_key_exists('price_dp', $row) ? $this->toOptionalNumber($row['price_dp']) : (array_key_exists('pd_price_dp', $row) ? $this->toOptionalNumber($row['pd_price_dp']) : null);
            $priceMember = array_key_exists('price_member', $row) ? $this->toOptionalNumber($row['price_member']) : (array_key_exists('pd_price_member', $row) ? $this->toOptionalNumber($row['pd_price_member']) : null);

            if ($sku === '' && $id <= 0) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => null,
                    'message' => 'SKU or Product ID is required.',
                ];
                continue;
            }

            if ($priceSrp === null && $priceDp === null && $priceMember === null) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => null,
                    'message' => 'At least one price field is required.',
                ];
                continue;
            }

            $productQuery = Product::query();
            if ($id > 0) {
                $productQuery->where('pd_id', $id);
            } else {
                $productQuery->where('pd_parent_sku', $sku);
            }
            $this->scopeQueryToActor($productQuery, $admin, $supplierUser);
            $product = $productQuery->first();

            if (!$product) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => null,
                    'message' => 'Product not found or not accessible.',
                ];
                continue;
            }

            if (($priceSrp !== null && $priceSrp < 0) || ($priceDp !== null && $priceDp < 0) || ($priceMember !== null && $priceMember < 0)) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => (int) $product->pd_id,
                    'sku' => $product->pd_parent_sku ?: null,
                    'name' => $product->pd_name,
                    'message' => 'Price values must be zero or greater.',
                ];
                continue;
            }

            try {
                $beforeProduct = clone $product;

                if ($priceSrp !== null) {
                    $product->pd_price_srp = $priceSrp;
                }
                if ($priceDp !== null) {
                    $product->pd_price_dp = $priceDp;
                }
                if ($priceMember !== null) {
                    $product->pd_price_member = $priceMember;
                }
                $product->pd_last_update = $now;
                $product->save();

                $changes = $this->buildProductChangeLog($beforeProduct, $product);
                $this->recordProductActivity('updated', $product, $admin, $supplierUser, $product->pd_name, $product->pd_parent_sku, $changes);

                $updated++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'updated',
                    'product_id' => (int) $product->pd_id,
                    'sku' => $product->pd_parent_sku ?: null,
                    'name' => $product->pd_name,
                    'message' => 'Price updated.',
                ];
            } catch (\Throwable $e) {
                $failed++;
                $this->recordFailedProductActivity('updated', $admin, $supplierUser, $product, $product->pd_name, $product->pd_parent_sku);

                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => (int) $product->pd_id,
                    'sku' => $product->pd_parent_sku ?: null,
                    'name' => $product->pd_name,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => $failed > 0
                ? 'Bulk price update finished with some row errors.'
                : 'Bulk price update completed successfully.',
            'summary' => [
                'total' => count($rows),
                'updated' => $updated,
                'failed' => $failed,
            ],
            'results' => $results,
        ]);
    }

    public function bulkUpdatePreview(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        $supplierUser = $this->resolveSupplierUser($request);

        if ($admin && $this->roleFromLevel((int) $admin->user_level_id) === 'supplier_admin' && !$admin->supplier_id) {
            return response()->json([
                'message' => 'Supplier Admin account is not linked to a supplier company.',
            ], 422);
        }

        $validated = $request->validate([
            'rows' => 'required|array|min:1|max:500',
            'rows.*' => 'required|array',
        ]);

        $rows = $validated['rows'];
        $results = [];
        $failed = 0;

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $sku = trim((string) ($row['sku'] ?? $row['pd_parent_sku'] ?? ''));
            $id = (int) ($row['id'] ?? $row['pd_id'] ?? 0);

            if ($sku === '' && $id <= 0) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => null,
                    'message' => 'SKU or Product ID is required.',
                ];
                continue;
            }

            $updates = [];
            if (array_key_exists('pd_name', $row)) {
                $updates['pd_name'] = trim((string) $row['pd_name']);
            }
            if (array_key_exists('pd_catid', $row)) {
                $updates['pd_catid'] = is_numeric($row['pd_catid'] ?? null)
                    ? (int) ($row['pd_catid'] ?? 0)
                    : $this->resolveCategoryIdByName((string) ($row['pd_catid'] ?? ''));
            }
            if (array_key_exists('pd_room_type', $row)) {
                $updates['pd_room_type'] = is_numeric($row['pd_room_type'] ?? null)
                    ? (int) ($row['pd_room_type'] ?? 0)
                    : $this->resolveRoomTypeByName((string) ($row['pd_room_type'] ?? ''));
            }
            $rawCategoryInput = array_key_exists('pd_catid', $row) ? $row['pd_catid'] : null;
            $rawRoomInput = array_key_exists('pd_room_type', $row) ? $row['pd_room_type'] : null;
            if (array_key_exists('pd_material', $row)) {
                $updates['pd_material'] = trim((string) $row['pd_material']);
            }
            if (array_key_exists('pd_price_srp', $row)) {
                $updates['pd_price_srp'] = $this->toOptionalNumber($row['pd_price_srp']);
            }
            if (array_key_exists('pd_price_member', $row)) {
                $updates['pd_price_member'] = $this->toOptionalNumber($row['pd_price_member']);
            }
            if (array_key_exists('pd_price_dp', $row)) {
                $updates['pd_price_dp'] = $this->toOptionalNumber($row['pd_price_dp']);
            }
            if (array_key_exists('pd_qty', $row)) {
                $updates['pd_qty'] = $this->toOptionalNumber($row['pd_qty']);
            }
            if (array_key_exists('pd_weight', $row)) {
                $updates['pd_weight'] = $this->toOptionalNumber($row['pd_weight']);
            }
            if (array_key_exists('pd_pswidth', $row)) {
                $updates['pd_pswidth'] = $this->toOptionalNumber($row['pd_pswidth']);
            }
            if (array_key_exists('pd_pslenght', $row)) {
                $updates['pd_pslenght'] = $this->toOptionalNumber($row['pd_pslenght']);
            }
            if (array_key_exists('pd_psheight', $row)) {
                $updates['pd_psheight'] = $this->toOptionalNumber($row['pd_psheight']);
            }
            if (array_key_exists('pd_psweight', $row)) {
                $updates['pd_psweight'] = $this->toOptionalNumber($row['pd_psweight']);
            }

            if (count($updates) === 0) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => null,
                    'message' => 'No editable fields provided.',
                ];
                continue;
            }

            $productQuery = Product::query();
            if ($id > 0) {
                $productQuery->where('pd_id', $id);
            } else {
                $productQuery->where('pd_parent_sku', $sku);
            }
            $this->scopeQueryToActor($productQuery, $admin, $supplierUser);
            $product = $productQuery->first();

            if (!$product) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => null,
                    'message' => 'Product not found or not accessible.',
                ];
                continue;
            }

            if (array_key_exists('pd_catid', $updates)) {
                $categoryId = (int) ($updates['pd_catid'] ?? 0);
                if ($categoryId <= 0 || !Category::query()->where('cat_id', $categoryId)->exists()) {
                    $failed++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'failed',
                        'product_id' => (int) $product->pd_id,
                        'sku' => $product->pd_parent_sku ?: null,
                        'name' => $product->pd_name,
                        'message' => 'Category not found.',
                    ];
                    continue;
                }

                $actorSupplierId = $this->actorSupplierId($admin, $supplierUser);
                if ($actorSupplierId > 0 && !$this->supplierCanUseCategory($actorSupplierId, $categoryId)) {
                    $failed++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'failed',
                        'product_id' => (int) $product->pd_id,
                        'sku' => $product->pd_parent_sku ?: null,
                        'name' => $product->pd_name,
                        'message' => 'This supplier is not allowed to use the selected category.',
                    ];
                    continue;
                }
            }

            $numericKeys = [
                'pd_price_srp',
                'pd_price_member',
                'pd_price_dp',
                'pd_qty',
                'pd_weight',
                'pd_pswidth',
                'pd_pslenght',
                'pd_psheight',
                'pd_psweight',
            ];
            foreach ($numericKeys as $key) {
                if (array_key_exists($key, $updates) && $updates[$key] !== null && $updates[$key] < 0) {
                    $failed++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'failed',
                        'product_id' => (int) $product->pd_id,
                        'sku' => $product->pd_parent_sku ?: null,
                        'name' => $product->pd_name,
                        'message' => 'Numeric values must be zero or greater.',
                    ];
                    continue 2;
                }
            }

            $current = [
                'pd_name' => $product->pd_name,
                'pd_catid' => $this->categoryNameById((int) $product->pd_catid),
                'pd_room_type' => $this->roomTypeLabel((int) ($product->pd_room_type ?? 0)),
                'pd_material' => $product->pd_material,
                'pd_price_srp' => (float) $product->pd_price_srp,
                'pd_price_member' => $product->pd_price_member !== null ? (float) $product->pd_price_member : null,
                'pd_price_dp' => (float) $product->pd_price_dp,
                'pd_qty' => (float) $product->pd_qty,
                'pd_weight' => (float) $product->pd_weight,
                'pd_pswidth' => $product->pd_pswidth !== null ? (float) $product->pd_pswidth : null,
                'pd_pslenght' => $product->pd_pslenght !== null ? (float) $product->pd_pslenght : null,
                'pd_psheight' => $product->pd_psheight !== null ? (float) $product->pd_psheight : null,
                'pd_psweight' => $product->pd_psweight !== null ? (float) $product->pd_psweight : null,
            ];

            $next = $updates;
            if (array_key_exists('pd_catid', $next)) {
                $next['pd_catid'] = is_numeric($rawCategoryInput ?? null)
                    ? $this->categoryNameById((int) ($next['pd_catid'] ?? 0))
                    : (string) ($rawCategoryInput ?? '');
            }
            if (array_key_exists('pd_room_type', $next)) {
                $next['pd_room_type'] = is_numeric($rawRoomInput ?? null)
                    ? $this->roomTypeLabel((int) ($next['pd_room_type'] ?? 0))
                    : (string) ($rawRoomInput ?? '');
            }

            $results[] = [
                'row' => $rowNumber,
                'status' => 'ready',
                'product_id' => (int) $product->pd_id,
                'sku' => $product->pd_parent_sku ?: null,
                'name' => $product->pd_name,
                'current' => $current,
                'next' => $next,
                'message' => 'Ready for update.',
            ];
        }

        return response()->json([
            'message' => $failed > 0
                ? 'Preview generated with some row errors.'
                : 'Preview generated successfully.',
            'summary' => [
                'total' => count($rows),
                'ready' => count($rows) - $failed,
                'failed' => $failed,
            ],
            'results' => $results,
        ]);
    }

    public function bulkUpdateApply(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        $supplierUser = $this->resolveSupplierUser($request);

        if ($admin && $this->roleFromLevel((int) $admin->user_level_id) === 'supplier_admin' && !$admin->supplier_id) {
            return response()->json([
                'message' => 'Supplier Admin account is not linked to a supplier company.',
            ], 422);
        }

        $validated = $request->validate([
            'rows' => 'required|array|min:1|max:500',
            'rows.*' => 'required|array',
        ]);

        $rows = $validated['rows'];
        $results = [];
        $updated = 0;
        $failed = 0;
        $now = now();

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $sku = trim((string) ($row['sku'] ?? $row['pd_parent_sku'] ?? ''));
            $id = (int) ($row['id'] ?? $row['pd_id'] ?? 0);

            if ($sku === '' && $id <= 0) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => null,
                    'message' => 'SKU or Product ID is required.',
                ];
                continue;
            }

            $updates = [];
            if (array_key_exists('pd_name', $row)) {
                $updates['pd_name'] = trim((string) $row['pd_name']);
            }
            if (array_key_exists('pd_catid', $row)) {
                $updates['pd_catid'] = is_numeric($row['pd_catid'] ?? null)
                    ? (int) ($row['pd_catid'] ?? 0)
                    : $this->resolveCategoryIdByName((string) ($row['pd_catid'] ?? ''));
            }
            if (array_key_exists('pd_room_type', $row)) {
                $updates['pd_room_type'] = is_numeric($row['pd_room_type'] ?? null)
                    ? (int) ($row['pd_room_type'] ?? 0)
                    : $this->resolveRoomTypeByName((string) ($row['pd_room_type'] ?? ''));
            }
            if (array_key_exists('pd_material', $row)) {
                $updates['pd_material'] = trim((string) $row['pd_material']);
            }
            if (array_key_exists('pd_price_srp', $row)) {
                $updates['pd_price_srp'] = $this->toOptionalNumber($row['pd_price_srp']);
            }
            if (array_key_exists('pd_price_member', $row)) {
                $updates['pd_price_member'] = $this->toOptionalNumber($row['pd_price_member']);
            }
            if (array_key_exists('pd_price_dp', $row)) {
                $updates['pd_price_dp'] = $this->toOptionalNumber($row['pd_price_dp']);
            }
            if (array_key_exists('pd_qty', $row)) {
                $updates['pd_qty'] = $this->toOptionalNumber($row['pd_qty']);
            }
            if (array_key_exists('pd_weight', $row)) {
                $updates['pd_weight'] = $this->toOptionalNumber($row['pd_weight']);
            }
            if (array_key_exists('pd_pswidth', $row)) {
                $updates['pd_pswidth'] = $this->toOptionalNumber($row['pd_pswidth']);
            }
            if (array_key_exists('pd_pslenght', $row)) {
                $updates['pd_pslenght'] = $this->toOptionalNumber($row['pd_pslenght']);
            }
            if (array_key_exists('pd_psheight', $row)) {
                $updates['pd_psheight'] = $this->toOptionalNumber($row['pd_psheight']);
            }
            if (array_key_exists('pd_psweight', $row)) {
                $updates['pd_psweight'] = $this->toOptionalNumber($row['pd_psweight']);
            }

            if (count($updates) === 0) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => null,
                    'message' => 'No editable fields provided.',
                ];
                continue;
            }

            $productQuery = Product::query();
            if ($id > 0) {
                $productQuery->where('pd_id', $id);
            } else {
                $productQuery->where('pd_parent_sku', $sku);
            }
            $this->scopeQueryToActor($productQuery, $admin, $supplierUser);
            $product = $productQuery->first();

            if (!$product) {
                $failed++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => null,
                    'message' => 'Product not found or not accessible.',
                ];
                continue;
            }

            if (array_key_exists('pd_catid', $updates)) {
                $categoryId = (int) ($updates['pd_catid'] ?? 0);
                if ($categoryId <= 0 || !Category::query()->where('cat_id', $categoryId)->exists()) {
                    $failed++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'failed',
                        'product_id' => (int) $product->pd_id,
                        'sku' => $product->pd_parent_sku ?: null,
                        'name' => $product->pd_name,
                        'message' => 'Category not found.',
                    ];
                    continue;
                }

                $actorSupplierId = $this->actorSupplierId($admin, $supplierUser);
                if ($actorSupplierId > 0 && !$this->supplierCanUseCategory($actorSupplierId, $categoryId)) {
                    $failed++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'failed',
                        'product_id' => (int) $product->pd_id,
                        'sku' => $product->pd_parent_sku ?: null,
                        'name' => $product->pd_name,
                        'message' => 'This supplier is not allowed to use the selected category.',
                    ];
                    continue;
                }
            }

            if (array_key_exists('pd_brand_type', $updates)) {
                $brandType = (int) ($updates['pd_brand_type'] ?? 0);
                if ($brandType > 0 && !ProductBrand::query()->where('pb_id', $brandType)->exists()) {
                    $failed++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'failed',
                        'product_id' => (int) $product->pd_id,
                        'sku' => $product->pd_parent_sku ?: null,
                        'name' => $product->pd_name,
                        'message' => 'The selected brand does not exist.',
                    ];
                    continue;
                }
            }

            $numericKeys = [
                'pd_price_srp',
                'pd_price_member',
                'pd_price_dp',
                'pd_qty',
                'pd_weight',
                'pd_pswidth',
                'pd_pslenght',
                'pd_psheight',
                'pd_psweight',
            ];
            foreach ($numericKeys as $key) {
                if (array_key_exists($key, $updates) && $updates[$key] !== null && $updates[$key] < 0) {
                    $failed++;
                    $results[] = [
                        'row' => $rowNumber,
                        'status' => 'failed',
                        'product_id' => (int) $product->pd_id,
                        'sku' => $product->pd_parent_sku ?: null,
                        'name' => $product->pd_name,
                        'message' => 'Numeric values must be zero or greater.',
                    ];
                    continue 2;
                }
            }

            try {
                $beforeProduct = clone $product;

                foreach ($updates as $key => $value) {
                    $product->{$key} = $value;
                }
                $product->pd_last_update = $now;
                $product->save();

                $changes = $this->buildProductChangeLog($beforeProduct, $product);
                $this->recordProductActivity('updated', $product, $admin, $supplierUser, $product->pd_name, $product->pd_parent_sku, $changes);

                $updated++;
                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'updated',
                    'product_id' => (int) $product->pd_id,
                    'sku' => $product->pd_parent_sku ?: null,
                    'name' => $product->pd_name,
                    'message' => 'Product updated.',
                ];
            } catch (\Throwable $e) {
                $failed++;
                $this->recordFailedProductActivity('updated', $admin, $supplierUser, $product, $product->pd_name, $product->pd_parent_sku);

                $results[] = [
                    'row' => $rowNumber,
                    'status' => 'failed',
                    'product_id' => (int) $product->pd_id,
                    'sku' => $product->pd_parent_sku ?: null,
                    'name' => $product->pd_name,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => $failed > 0
                ? 'Bulk update finished with some row errors.'
                : 'Bulk update completed successfully.',
            'summary' => [
                'total' => count($rows),
                'updated' => $updated,
                'failed' => $failed,
            ],
            'results' => $results,
        ]);
    }

    public function manualCheckoutApply(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        $supplierUser = $this->resolveSupplierUser($request);

        if ($admin && $this->roleFromLevel((int) $admin->user_level_id) === 'supplier_admin' && !$admin->supplier_id) {
            return response()->json([
                'message' => 'Supplier Admin account is not linked to a supplier company.',
            ], 422);
        }

        $validated = $request->validate([
            'product_ids' => 'required|array|min:1|max:5000',
            'product_ids.*' => 'required|integer|min:1',
            'enabled' => 'nullable|boolean',
        ]);

        $productIds = collect($validated['product_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return response()->json([
                'message' => 'No valid products were selected.',
            ], 422);
        }

        $enabled = (bool) ($validated['enabled'] ?? true);
        $results = [];
        $updated = 0;
        $failed = 0;
        $now = now();

        $query = Product::query()
            ->whereIn('pd_id', $productIds->all())
            ->with('brand:pb_id,pb_name,pb_status');
        $this->scopeQueryToActor($query, $admin, $supplierUser);

        $products = $query->get()->keyBy('pd_id');

        foreach ($productIds as $productId) {
            /** @var Product|null $product */
            $product = $products->get($productId);

            if (! $product) {
                $failed++;
                $results[] = [
                    'product_id' => $productId,
                    'status' => 'failed',
                    'name' => null,
                    'message' => 'Product not found or not accessible.',
                ];
                continue;
            }

            try {
                $beforeProduct = clone $product;
                $product->pd_manual_checkout_enabled = $enabled ? 1 : 0;
                $product->pd_last_update = $now;
                $product->save();

                $changes = $this->buildProductChangeLog($beforeProduct, $product);
                $this->recordProductActivity('updated', $product, $admin, $supplierUser, $product->pd_name, $product->pd_parent_sku, $changes);

                $updated++;
                $results[] = [
                    'product_id' => (int) $product->pd_id,
                    'status' => 'updated',
                    'name' => $product->pd_name,
                    'message' => $enabled
                        ? 'Added to manual checkout.'
                        : 'Removed from manual checkout.',
                ];
            } catch (\Throwable $e) {
                $failed++;
                $this->recordFailedProductActivity('updated', $admin, $supplierUser, $product, $product->pd_name, $product->pd_parent_sku);
                $results[] = [
                    'product_id' => (int) $product->pd_id,
                    'status' => 'failed',
                    'name' => $product->pd_name,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => $failed > 0
                ? 'Manual checkout assignment finished with some row errors.'
                : 'Selected products were added to manual checkout.',
            'summary' => [
                'total' => $productIds->count(),
                'updated' => $updated,
                'failed' => $failed,
            ],
            'results' => $results,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        $supplierUser = $this->resolveSupplierUser($request);
        $actorSupplierId = $this->actorSupplierId($admin, $supplierUser);
        if ($admin && $this->roleFromLevel((int) $admin->user_level_id) === 'supplier_admin' && ! $admin->supplier_id) {
            $this->recordFailedProductActivity('created', $admin, $supplierUser, null, (string) $request->input('pd_name', ''), (string) $request->input('pd_parent_sku', ''));
            return response()->json([
                'message' => 'Supplier Admin account is not linked to a supplier company.',
            ], 422);
        }
        $validator = Validator::make($request->all(), [
            'pd_name'      => 'required|string|max:255',
            'pd_catid'     => 'required|integer',
            'pd_room_type' => 'nullable|integer|min:0|max:8',
            'pd_brand_type' => 'nullable|integer|min:0',
            'pd_catsubid'  => 'nullable|integer',
            'pd_price_srp' => 'required|numeric|min:0',
            'pd_price_dp'  => 'nullable|numeric|min:0',
            'pd_price_member' => 'nullable|numeric|min:0',
            'pd_prodpv'    => 'nullable|numeric|min:0',
            'pd_qty'       => 'nullable|numeric|min:0',
            'pd_weight'    => 'nullable|numeric|min:0',
            'pd_psweight'  => 'nullable|numeric|min:0',
            'pd_pslenght'  => 'nullable|numeric|min:0',
            'pd_psheight'  => 'nullable|numeric|min:0',
            'pd_description'       => 'nullable|string',
            'pd_specifications'    => 'nullable|string',
            'pd_material'          => 'nullable|string|max:255',
            'pd_warranty'          => 'nullable|string|max:255',
            'pd_pswidth'           => 'nullable|numeric|min:0',
            'pd_assembly_required' => 'nullable|boolean',
            'pd_parent_sku'  => 'nullable|string|max:50',
            'pd_type'      => 'nullable|integer',
            'pd_musthave'    => 'nullable|boolean',
            'pd_bestseller'  => 'nullable|boolean',
            'pd_salespromo'  => 'nullable|boolean',
            'pd_manual_checkout_enabled' => 'nullable|boolean',
            'pd_status'      => 'nullable|integer|in:0,1,2',
            'pd_image'       => 'nullable|string|max:500',
            'pd_images'      => 'nullable|array',
            'pd_images.*'    => 'nullable|string|max:1000',
            'pd_variants'                => 'nullable|array',
            'pd_variants.*.pv_sku'       => 'nullable|string|max:80',
            'pd_variants.*.pv_name'      => 'nullable|string|max:120',
            'pd_variants.*.pv_color'     => 'nullable|string|max:80',
            'pd_variants.*.pv_color_hex' => 'nullable|string|max:16',
            'pd_variants.*.pv_size'      => 'nullable|string|max:40',
            'pd_variants.*.pv_style'     => 'nullable|string|max:80',
            'pd_variants.*.pv_width'     => 'nullable|numeric|min:0',
            'pd_variants.*.pv_dimension' => 'nullable|numeric|min:0',
            'pd_variants.*.pv_height'    => 'nullable|numeric|min:0',
            'pd_variants.*.pv_price_srp' => 'nullable|numeric|min:0',
            'pd_variants.*.pv_price_dp'  => 'nullable|numeric|min:0',
            'pd_variants.*.pv_price_member' => 'nullable|numeric|min:0',
            'pd_variants.*.pv_prodpv'   => 'nullable|numeric|min:0',
            'pd_variants.*.pv_qty'       => 'nullable|numeric|min:0',
            'pd_variants.*.pv_status'    => 'nullable|integer|in:0,1',
            'pd_variants.*.pv_images'    => 'nullable|array',
            'pd_variants.*.pv_images.*'  => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            $this->recordFailedProductActivity('created', $admin, $supplierUser, null, (string) $request->input('pd_name', ''), (string) $request->input('pd_parent_sku', ''));
            return $this->validationErrorResponse($validator);
        }

        $categoryId = (int) $request->input('pd_catid', 0);
        if ($actorSupplierId > 0 && ! $this->supplierCanUseCategory($actorSupplierId, $categoryId)) {
            $this->recordFailedProductActivity('created', $admin, $supplierUser, null, (string) $request->input('pd_name', ''), (string) $request->input('pd_parent_sku', ''));
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'pd_catid' => ['This supplier is not allowed to use the selected category.'],
                ],
            ], 422);
        }

        $brandType = (int) $request->input('pd_brand_type', 0);
        if ($brandType > 0 && ! ProductBrand::query()->where('pb_id', $brandType)->exists()) {
            $this->recordFailedProductActivity('created', $admin, $supplierUser, null, (string) $request->input('pd_name', ''), (string) $request->input('pd_parent_sku', ''));
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'pd_brand_type' => ['The selected brand does not exist.'],
                ],
            ], 422);
        }

        $now = now();

        $images = collect($request->input('pd_images', []))
            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
            ->map(fn ($url) => trim((string) $url))
            ->unique(fn (string $url) => mb_strtolower($url, 'UTF-8'))
            ->values()
            ->all();

        if (empty($images) && is_string($request->pd_image) && trim($request->pd_image) !== '') {
            $images = [trim($request->pd_image)];
        }

        try {
        $product = DB::transaction(function () use ($request, $now, $images, $admin, $supplierUser, $brandType) {
            try {
                $supplierId = $this->actorSupplierId($admin, $supplierUser);
                $product = Product::create([
                    'pd_name'        => $request->pd_name,
                    'pd_catid'       => $request->pd_catid ?? 0,
                    'pd_room_type'   => $this->resolveRoomType($request),
                    'pd_brand_type'  => $brandType ?: 0,
                    'pd_catsubid'    => $request->pd_catsubid ?? 0,
                    'pd_catsubid2'   => 0,
                    'pd_shopid'      => 0,
                    'pd_description'       => $request->pd_description ?? '',
                    'pd_specifications'    => $request->pd_specifications ?? null,
                    'pd_material'          => $request->filled('pd_material') ? (string) $request->pd_material : '',
                    'pd_warranty'          => $request->filled('pd_warranty') ? (string) $request->pd_warranty : '',
                    'pd_supplier'    => $supplierId,
                    'pd_price_srp'   => $request->pd_price_srp ?? 0,
                    'pd_price_dp'    => $request->pd_price_dp ?? 0,
                    'pd_price_member' => $request->pd_price_member,
                    'pd_prodpv'      => $request->pd_prodpv ?? 0,
                    'pd_qty'         => $request->pd_qty ?? 0,
                    'pd_weight'      => $request->pd_weight ?? 0,
                    'pd_psweight'    => $request->pd_psweight ?? 0,
                    'pd_pswidth'     => $request->pd_pswidth ?? 0,
                    'pd_pslenght'    => $request->pd_pslenght ?? 0,
                    'pd_psheight'    => $request->pd_psheight ?? 0,
                    'pd_assembly_required' => $request->boolean('pd_assembly_required') ? 1 : 0,
                    'pd_preorder'    => '',
                    'pd_preorder_value' => 0,
                    'pd_parent_sku'  => $request->pd_parent_sku ?? '',
                    'pd_type'        => $request->pd_type ?? 0,
                    'pd_shoptype'    => 0,
                    'pd_musthave'    => $request->boolean('pd_musthave') ? 1 : 0,
                    'pd_bestseller'  => $request->boolean('pd_bestseller') ? 1 : 0,
                    'pd_salespromo'  => $request->boolean('pd_salespromo') ? 1 : 0,
                    'pd_manual_checkout_enabled' => $request->boolean('pd_manual_checkout_enabled') ? 1 : 0,
                    'pd_user'        => 0,
                    'pd_usertype'    => 0,
                    'pd_date'        => $now,
                    'pd_last_update' => $now,
                    // Public storefront visibility expects active products to be status 1/2.
                    // Default new products to active when status is omitted by a client.
                    'pd_status'      => (int) $request->input('pd_status', 1),
                    'pd_image'       => $images[0] ?? ($request->pd_image ?? null),
                ]);
            } catch (\Throwable $e) {
                Log::error('Product store stage failed | stage=product_create | exception=' . $e::class . ' | message=' . $e->getMessage());
                throw $e;
            }

            if (count($images) >= 1) {
                foreach ($images as $url) {
                    try {
                        ProductPhoto::create([
                            'pp_pdid'     => $product->pd_id,
                            'pp_filename' => $url,
                            'pp_varone'   => null,
                            'pp_date'     => $now,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('Product store stage failed | stage=photo_insert | product_id=' . $product->pd_id . ' | image_url=' . $url . ' | exception=' . $e::class . ' | message=' . $e->getMessage());
                        throw $e;
                    }
                }
            }

            $shouldSyncVariants = (int) $request->input('pd_type', 0) === 1
                || !empty($request->input('pd_variants', []));

            if ($shouldSyncVariants) {
                try {
                    $this->syncVariants($product, $request->input('pd_variants', []), $now);
                } catch (\Throwable $e) {
                    Log::error('Product store stage failed | stage=variant_sync | product_id=' . $product->pd_id . ' | exception=' . $e::class . ' | message=' . $e->getMessage());
                    throw $e;
                }
            }

            return $product;
        });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Product store failed: ' . $e->getMessage(), [
                'sql'  => method_exists($e, 'getSql') ? $e->getSql() : null,
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            try {
                $this->recordFailedProductActivity('created', $admin, $supplierUser, null, (string) $request->input('pd_name', ''), (string) $request->input('pd_parent_sku', ''));
            } catch (\Throwable $loggingError) {
                Log::warning('Product activity log failed after create error', [
                    'exception' => $loggingError::class,
                    'message' => $loggingError->getMessage(),
                ]);
            }
            return response()->json(['message' => 'Server error: ' . $e->getMessage()], 500);
        }

        try {
            $this->recordProductActivity('created', $product, $admin, $supplierUser);
        } catch (\Throwable $e) {
            Log::warning('Product activity log failed after create', [
                'product_id' => $product->pd_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        $product = Product::query()
            ->select([
                'pd_id', 'pd_name', 'pd_description', 'pd_specifications', 'pd_material', 'pd_warranty',
                'pd_catid', 'pd_catsubid', 'pd_room_type', 'pd_brand_type', 'pd_supplier',
                'pd_price_srp', 'pd_price_dp', 'pd_price_member', 'pd_qty',
                'pd_prodpv',
                'pd_weight', 'pd_psweight', 'pd_pswidth', 'pd_pslenght', 'pd_psheight',
                'pd_assembly_required', 'pd_type', 'pd_musthave',
                'pd_bestseller', 'pd_salespromo', 'pd_status', 'pd_date',
                'pd_last_update', 'pd_parent_sku', 'pd_image',
            ])
            ->with([
                'photos:pp_id,pp_pdid,pp_filename,pp_varone,pp_date',
                'brand:pb_id,pb_name,pb_status',
                'supplier:s_id,s_company,s_name',
                'creationActivity:pal_id,pal_product_id,pal_actor_name,pal_actor_email,pal_actor_role,pal_created_at',
                'variants:pv_id,pv_pdid,pv_sku,pv_name,pv_color,pv_color_hex,pv_size,pv_style,pv_width,pv_dimension,pv_height,pv_price_srp,pv_price_dp,pv_price_member,pv_prodpv,pv_qty,pv_status,pv_date',
                'variants.photos:pvp_id,pvp_pvid,pvp_filename,pvp_sort,pvp_date',
            ])
            ->findOrFail($product->pd_id);

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => $this->mapProduct($product),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        $supplierUser = $this->resolveSupplierUser($request);
        $actorSupplierId = $this->actorSupplierId($admin, $supplierUser);
        $productQuery = Product::query()->where('pd_id', $id);
        $this->scopeQueryToActor($productQuery, $admin, $supplierUser);
        $product = $productQuery->first();
        if (! $product) {
            $this->recordFailedProductActivity('updated', $admin, $supplierUser, null, (string) $request->input('pd_name', "Product #{$id}"), (string) $request->input('pd_parent_sku', ''));
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'pd_name'        => 'sometimes|required|string|max:255',
            'pd_catid'       => 'sometimes|required|integer',
            'pd_room_type'   => 'nullable|integer|min:0|max:8',
            'pd_brand_type'  => 'nullable|integer|min:0',
            'pd_catsubid'    => 'nullable|integer',
            'pd_price_srp'   => 'sometimes|required|numeric|min:0',
            'pd_price_dp'    => 'nullable|numeric|min:0',
            'pd_price_member'=> 'nullable|numeric|min:0',
            'pd_prodpv'      => 'nullable|numeric|min:0',
            'pd_qty'         => 'nullable|numeric|min:0',
            'pd_weight'      => 'nullable|numeric|min:0',
            'pd_psweight'    => 'nullable|numeric|min:0',
            'pd_pslenght'    => 'nullable|numeric|min:0',
            'pd_psheight'    => 'nullable|numeric|min:0',
            'pd_description'       => 'nullable|string',
            'pd_specifications'    => 'nullable|string',
            'pd_material'          => 'nullable|string|max:255',
            'pd_warranty'          => 'nullable|string|max:255',
            'pd_pswidth'           => 'nullable|numeric|min:0',
            'pd_assembly_required' => 'nullable|boolean',
            'pd_parent_sku'  => 'nullable|string|max:50',
            'pd_type'        => 'nullable|integer',
            'pd_musthave'    => 'nullable|boolean',
            'pd_bestseller'  => 'nullable|boolean',
            'pd_salespromo'  => 'nullable|boolean',
            'pd_manual_checkout_enabled' => 'nullable|boolean',
            'pd_status'      => 'nullable|integer|in:0,1,2',
            'pd_image'       => 'nullable|string|max:500',
            'pd_images'      => 'nullable|array',
            'pd_images.*'    => 'nullable|string|max:1000',
            'pd_variants'                => 'nullable|array',
            'pd_variants.*.pv_sku'       => 'nullable|string|max:80',
            'pd_variants.*.pv_name'      => 'nullable|string|max:120',
            'pd_variants.*.pv_color'     => 'nullable|string|max:80',
            'pd_variants.*.pv_color_hex' => 'nullable|string|max:16',
            'pd_variants.*.pv_size'      => 'nullable|string|max:40',
            'pd_variants.*.pv_style'     => 'nullable|string|max:80',
            'pd_variants.*.pv_width'     => 'nullable|numeric|min:0',
            'pd_variants.*.pv_dimension' => 'nullable|numeric|min:0',
            'pd_variants.*.pv_height'    => 'nullable|numeric|min:0',
            'pd_variants.*.pv_price_srp' => 'nullable|numeric|min:0',
            'pd_variants.*.pv_price_dp'  => 'nullable|numeric|min:0',
            'pd_variants.*.pv_price_member' => 'nullable|numeric|min:0',
            'pd_variants.*.pv_prodpv'   => 'nullable|numeric|min:0',
            'pd_variants.*.pv_qty'       => 'nullable|numeric|min:0',
            'pd_variants.*.pv_status'    => 'nullable|integer|in:0,1',
            'pd_variants.*.pv_images'    => 'nullable|array',
            'pd_variants.*.pv_images.*'  => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            $this->recordFailedProductActivity('updated', $admin, $supplierUser, $product, (string) ($request->input('pd_name', $product->pd_name ?? '') ?: ($product->pd_name ?? '')), (string) ($request->input('pd_parent_sku', $product->pd_parent_sku ?? '') ?: ($product->pd_parent_sku ?? '')));
            return $this->validationErrorResponse($validator);
        }

        if ($request->has('pd_catid') && $actorSupplierId > 0) {
            $categoryId = (int) $request->input('pd_catid', 0);
            if (! $this->supplierCanUseCategory($actorSupplierId, $categoryId)) {
                $this->recordFailedProductActivity('updated', $admin, $supplierUser, $product, (string) ($request->input('pd_name', $product->pd_name ?? '') ?: ($product->pd_name ?? '')), (string) ($request->input('pd_parent_sku', $product->pd_parent_sku ?? '') ?: ($product->pd_parent_sku ?? '')));
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'pd_catid' => ['This supplier is not allowed to use the selected category.'],
                    ],
                ], 422);
            }
        }

        if ($request->exists('pd_brand_type')) {
            $brandType = (int) $request->input('pd_brand_type', 0);
            if ($brandType > 0 && ! ProductBrand::query()->where('pb_id', $brandType)->exists()) {
                $this->recordFailedProductActivity('updated', $admin, $supplierUser, $product, (string) ($request->input('pd_name', $product->pd_name ?? '') ?: ($product->pd_name ?? '')), (string) ($request->input('pd_parent_sku', $product->pd_parent_sku ?? '') ?: ($product->pd_parent_sku ?? '')));
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'pd_brand_type' => ['The selected brand does not exist.'],
                    ],
                ], 422);
            }
        }

        $fields = [
            'pd_name', 'pd_catid', 'pd_room_type', 'pd_brand_type', 'pd_catsubid', 'pd_description', 'pd_specifications',
            'pd_material', 'pd_warranty',
            'pd_price_srp', 'pd_price_dp', 'pd_price_member', 'pd_prodpv', 'pd_qty', 'pd_weight',
            'pd_psweight', 'pd_pswidth', 'pd_pslenght', 'pd_psheight',
            'pd_parent_sku', 'pd_type', 'pd_status',
            'pd_manual_checkout_enabled',
        ];
        $product->loadMissing('photos:pp_id,pp_pdid,pp_filename,pp_varone,pp_date');
        $beforeProduct = clone $product;

        try {
            DB::transaction(function () use ($request, $product, $fields) {
                foreach ($fields as $field) {
                    if ($request->has($field)) {
                        if (in_array($field, ['pd_material', 'pd_warranty'], true)) {
                            $product->$field = $request->filled($field) ? (string) $request->$field : '';
                        } elseif ($field === 'pd_room_type') {
                            $rawRoomType = $request->input('pd_room_type');
                            $product->pd_room_type = ($rawRoomType === null || $rawRoomType === '')
                                ? $this->resolveRoomType($request)
                                : max(0, (int) $rawRoomType);
                        } elseif ($field === 'pd_brand_type') {
                            $rawBrandType = $request->input('pd_brand_type');
                            $product->pd_brand_type = ($rawBrandType === null || $rawBrandType === '')
                                ? 0
                                : max(0, (int) $rawBrandType);
                        } else {
                            $product->$field = $request->$field;
                        }
                    }
                }

                if ($request->has('pd_catid') && ! $request->exists('pd_room_type')) {
                    $product->pd_room_type = $this->resolveRoomType($request);
                }

                if ($request->has('pd_musthave')) {
                    $product->pd_musthave = $request->boolean('pd_musthave') ? 1 : 0;
                }
                if ($request->has('pd_bestseller')) {
                    $product->pd_bestseller = $request->boolean('pd_bestseller') ? 1 : 0;
                }
                if ($request->has('pd_salespromo')) {
                    $product->pd_salespromo = $request->boolean('pd_salespromo') ? 1 : 0;
                }
                if ($request->has('pd_assembly_required')) {
                    $product->pd_assembly_required = $request->boolean('pd_assembly_required') ? 1 : 0;
                }
                if ($request->has('pd_manual_checkout_enabled')) {
                    $product->pd_manual_checkout_enabled = $request->boolean('pd_manual_checkout_enabled') ? 1 : 0;
                }

                try {
                    $product->pd_last_update = now();
                    $product->save();
                } catch (\Throwable $e) {
                    Log::error('Product update stage failed | stage=product_save | product_id=' . $product->pd_id . ' | exception=' . $e::class . ' | message=' . $e->getMessage());
                    throw $e;
                }

                if ($request->has('pd_images')) {
                    $images = collect($request->input('pd_images', []))
                        ->filter(fn ($url) => is_string($url) && trim($url) !== '')
                        ->map(fn ($url) => trim((string) $url))
                        ->unique(fn (string $url) => mb_strtolower($url, 'UTF-8'))
                        ->values()
                        ->all();

                    try {
                        $existingImages = ProductPhoto::query()
                            ->where('pp_pdid', $product->pd_id)
                            ->orderBy('pp_id')
                            ->pluck('pp_filename')
                            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
                            ->values()
                            ->all();
                    } catch (\Throwable $e) {
                        Log::error('Product update stage failed | stage=photo_select | product_id=' . $product->pd_id . ' | exception=' . $e::class . ' | message=' . $e->getMessage());
                        throw $e;
                    }

                    $imagesChanged = $existingImages !== $images;

                    if ($imagesChanged) {
                        try {
                            ProductPhoto::query()->where('pp_pdid', $product->pd_id)->delete();
                        } catch (\Throwable $e) {
                            Log::error('Product update stage failed | stage=photo_delete | product_id=' . $product->pd_id . ' | exception=' . $e::class . ' | message=' . $e->getMessage());
                            throw $e;
                        }

                        foreach ($images as $url) {
                            try {
                                ProductPhoto::create([
                                    'pp_pdid'     => $product->pd_id,
                                    'pp_filename' => $url,
                                    'pp_varone'   => null,
                                    'pp_date'     => now(),
                                ]);
                            } catch (\Throwable $e) {
                                Log::error('Product update stage failed | stage=photo_insert | product_id=' . $product->pd_id . ' | image_url=' . $url . ' | exception=' . $e::class . ' | message=' . $e->getMessage());
                                throw $e;
                            }
                        }
                    }

                    $product->pd_image = $images[0] ?? null;
                } elseif ($request->has('pd_image')) {
                    $product->pd_image = $request->pd_image;
                }

                $shouldSyncVariants = $request->exists('pd_variants')
                    && (
                        $request->input('pd_type', $product->pd_type) == 1
                        || !empty($request->input('pd_variants', []))
                        || $request->input('pd_type', $product->pd_type) == 0
                    );

                if ($shouldSyncVariants) {
                    try {
                        $this->syncVariants($product, $request->input('pd_variants', []), now());
                    } catch (\Throwable $e) {
                        Log::error('Product update stage failed | stage=variant_sync | product_id=' . $product->pd_id . ' | exception=' . $e::class . ' | message=' . $e->getMessage());
                        throw $e;
                    }
                }

                try {
                    $product->pd_last_update = now();
                    $product->save();
                } catch (\Throwable $e) {
                    Log::error('Product update stage failed | stage=final_product_save | product_id=' . $product->pd_id . ' | exception=' . $e::class . ' | message=' . $e->getMessage());
                    throw $e;
                }
            });
        } catch (\Throwable $e) {
            $debugDetails = [
                'product_id' => $id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'sql' => method_exists($e, 'getSql') ? $e->getSql() : null,
                'bindings' => method_exists($e, 'getBindings') ? json_encode($e->getBindings(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'payload' => json_encode($request->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];

            $flatLog = collect($debugDetails)
                ->map(fn ($value, $key) => $key . '=' . ($value === null ? 'null' : $value))
                ->implode(' | ');

            Log::error('Product update failed | ' . $flatLog);

            try {
                $this->recordFailedProductActivity('updated', $admin, $supplierUser, $product, (string) ($request->input('pd_name', $product->pd_name ?? '') ?: ($product->pd_name ?? '')), (string) ($request->input('pd_parent_sku', $product->pd_parent_sku ?? '') ?: ($product->pd_parent_sku ?? '')));
            } catch (\Throwable $loggingError) {
                Log::warning('Product activity log failed after update error', [
                    'product_id' => $id,
                    'exception' => $loggingError::class,
                    'message' => $loggingError->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Server error: ' . $e->getMessage(),
            ], 500);
        }

        try {
            $changes = $this->buildProductChangeLog($beforeProduct, $product);
            $this->recordProductActivity('updated', $product, $admin, $supplierUser, null, null, $changes);
        } catch (\Throwable $e) {
            Log::warning('Product activity log failed after update', [
                'product_id' => $product->pd_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        $product->load([
            'photos:pp_id,pp_pdid,pp_filename,pp_varone,pp_date',
            'brand:pb_id,pb_name,pb_status',
            'variants:pv_id,pv_pdid,pv_sku,pv_name,pv_color,pv_color_hex,pv_size,pv_style,pv_width,pv_dimension,pv_height,pv_price_srp,pv_price_dp,pv_price_member,pv_prodpv,pv_qty,pv_status,pv_date',
            'variants.photos:pvp_id,pvp_pvid,pvp_filename,pvp_sort,pvp_date',
        ]);

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $this->mapProduct($product),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $admin = $this->resolveAdmin($request);
        $supplierUser = $this->resolveSupplierUser($request);
        $actor = auth('sanctum')->user();
        $productQuery = Product::query()->where('pd_id', $id);
        if ($actor instanceof Admin) {
            $this->scopeQueryToActor($productQuery, $actor, null);
        }
        if ($actor instanceof SupplierUser) {
            $this->scopeQueryToActor($productQuery, null, $actor);
        }
        $product = $productQuery->first();
        if (! $product) {
            $this->recordFailedProductActivity('deleted', $admin, $supplierUser, null, "Product #{$id}");
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $deletedProductName = (string) $product->pd_name;
        $deletedProductSku = (string) ($product->pd_parent_sku ?? '');
        try {
            $product->delete();
        } catch (\Throwable $e) {
            try {
                $this->recordFailedProductActivity('deleted', $admin, $supplierUser, $product, $deletedProductName, $deletedProductSku);
            } catch (\Throwable $loggingError) {
                Log::warning('Product activity log failed after delete error', [
                    'product_id' => $id,
                    'exception' => $loggingError::class,
                    'message' => $loggingError->getMessage(),
                ]);
            }

            return response()->json(['message' => 'Failed to delete product.'], 500);
        }

        try {
            $this->recordProductActivity('deleted', $product, $admin, $supplierUser, $deletedProductName, $deletedProductSku);
        } catch (\Throwable $e) {
            Log::warning('Product activity log failed after delete', [
                'product_id' => $id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'Product deleted successfully.']);
    }

    public function fetchZqImportPreview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cursor' => 'nullable',
            'size' => 'nullable|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:50',
            'resume_from_saved' => 'nullable|boolean',
            'reset_cursor' => 'nullable|boolean',
            'sourceType' => 'nullable|array',
            'sourceType.*' => 'string|max:50',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        if (! $this->zqApiService->isConfigured()) {
            return response()->json([
                'message' => 'ZQ API configuration is incomplete.',
            ], 422);
        }

        $payload = array_filter([
            'cursor' => $request->input('cursor'),
            'size' => $request->input('size', 20),
            'keyword' => $request->input('keyword'),
            'status' => $request->input('status'),
            'sourceType' => $request->input('sourceType'),
            'ids' => $request->input('ids'),
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
        $resumeFromSaved = $request->boolean('resume_from_saved', true);
        $resetCursor = $request->boolean('reset_cursor', false);
        $resolvedCursor = $this->zqProductSyncService->resolveCursor($payload, $resumeFromSaved, $resetCursor);

        if ($resolvedCursor !== null) {
            $payload['cursor'] = $resolvedCursor;
        } else {
            unset($payload['cursor']);
        }

        try {
            $response = $this->zqApiService->getImportProductList($payload);

            return response()->json([
                'message' => 'ZQ import product list fetched successfully.',
                'request' => $payload,
                'cursor' => [
                    'used' => $resolvedCursor,
                    'saved' => $this->zqProductSyncService->getSavedCursor(),
                    'resumed' => $resolvedCursor !== null && ! $resetCursor && ! $request->filled('cursor'),
                ],
                'zq' => $response,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ZQ import product preview failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function fetchZqImportDetail(int|string $id): JsonResponse
    {
        if (! $this->zqApiService->isConfigured()) {
            return response()->json([
                'message' => 'ZQ API configuration is incomplete.',
            ], 422);
        }

        try {
            $response = $this->zqApiService->getImportProductDetail($id);

            return response()->json([
                'message' => 'ZQ import product detail fetched successfully.',
                'zq' => $response,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ZQ import product detail failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'product_id' => $id,
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncZqProducts(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cursor' => 'nullable',
            'size' => 'nullable|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:50',
            'resume_from_saved' => 'nullable|boolean',
            'reset_cursor' => 'nullable|boolean',
            'sourceType' => 'nullable|array',
            'sourceType.*' => 'string|max:50',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        if (! $this->zqApiService->isConfigured()) {
            return response()->json([
                'message' => 'ZQ API configuration is incomplete.',
            ], 422);
        }

        $payload = array_filter([
            'cursor' => $request->input('cursor'),
            'size' => $request->input('size', 20),
            'keyword' => $request->input('keyword'),
            'status' => $request->input('status'),
            'sourceType' => $request->input('sourceType'),
            'ids' => $request->input('ids'),
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
        $resumeFromSaved = $request->boolean('resume_from_saved', true);
        $resetCursor = $request->boolean('reset_cursor', false);

        try {
            $result = $this->zqProductSyncService->syncImportProducts($payload, $resumeFromSaved, $resetCursor);

            return response()->json([
                'message' => 'ZQ products synced successfully.',
                'summary' => $result['summary'],
                'hasMore' => $result['hasMore'],
                'nextCursor' => $result['nextCursor'],
                'usedCursor' => $result['usedCursor'] ?? null,
                'savedCursor' => $result['savedCursor'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ZQ product sync failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function listCachedZqProducts(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:255',
            'brand_type' => 'nullable|integer|min:1',
            'source_type' => 'nullable|string|max:80',
            'status' => 'nullable|string|max:80',
            'import_status' => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $search = trim((string) $request->input('search', ''));

        $query = ZqProduct::query();

        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $like = '%' . $search . '%';
                $inner->where('zqp_subject', 'ilike', $like)
                    ->orWhere('zqp_external_id', 'ilike', $like)
                    ->orWhere('zqp_category_name', 'ilike', $like);
            });
        }

        if ($request->filled('brand_type')) {
            $query->where('zqp_brand_type', (int) $request->input('brand_type'));
        }

        if ($request->filled('source_type')) {
            $query->where('zqp_source_type', $request->input('source_type'));
        }

        if ($request->filled('status')) {
            $query->where('zqp_status', $request->input('status'));
        }

        if ($request->filled('import_status')) {
            $query->where('zqp_import_status', $request->input('import_status'));
        }

        $paginator = $query
            ->orderByDesc('zqp_published_at')
            ->orderByDesc('updated_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'products' => collect($paginator->items())->map(function (ZqProduct $product) {
                return [
                    'id' => (int) $product->zqp_id,
                    'externalId' => (string) $product->zqp_external_id,
                    'offerId' => $product->zqp_offer_id,
                    'brandType' => $product->zqp_brand_type ? (int) $product->zqp_brand_type : null,
                    'subject' => (string) $product->zqp_subject,
                    'subjectCn' => $product->zqp_subject_cn,
                    'categoryName' => $product->zqp_category_name,
                    'primaryImage' => $product->zqp_primary_image,
                    'images' => $product->zqp_images ?? [],
                    'sourceType' => $product->zqp_source_type,
                    'status' => $product->zqp_status,
                    'importStatus' => $product->zqp_import_status,
                    'productUrl' => $product->zqp_product_url,
                    'targetCurrency' => $product->zqp_target_currency,
                    'shippingTo' => $product->zqp_shipping_to,
                    'priceMinCents' => $product->zqp_price_min_cents,
                    'priceMaxCents' => $product->zqp_price_max_cents,
                    'costMinCents' => $product->zqp_cost_min_cents,
                    'costMaxCents' => $product->zqp_cost_max_cents,
                    'totalStock' => (int) ($product->zqp_total_stock ?? 0),
                    'variantCount' => (int) ($product->zqp_variant_count ?? 0),
                    'publishedAt' => optional($product->zqp_published_at)?->toIso8601String(),
                    'sourceCreatedAt' => optional($product->zqp_source_created_at)?->toIso8601String(),
                    'sourceUpdatedAt' => optional($product->zqp_source_updated_at)?->toIso8601String(),
                    'syncedAt' => optional($product->updated_at)?->toIso8601String(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function zqProductsSummary(): JsonResponse
    {
        $baseQuery = ZqProduct::query();

        $total = (clone $baseQuery)->count();
        $active = (clone $baseQuery)
            ->whereRaw("upper(coalesce(zqp_status, '')) = ?", ['PUBLISHED'])
            ->count();
        $inactive = max(0, $total - $active);
        $lowStock = (clone $baseQuery)
            ->where('zqp_total_stock', '>', 0)
            ->where('zqp_total_stock', '<=', 5)
            ->count();

        return response()->json([
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'low_stock' => $lowStock,
            'saved_cursor' => $this->zqProductSyncService->getSavedCursor(),
            'has_saved_cursor' => $this->zqProductSyncService->getSavedCursor() !== null,
        ]);
    }

    public function importZqProductToLocal(Request $request, string $id): JsonResponse
    {
        if (! $this->zqApiService->isConfigured()) {
            return response()->json(['message' => 'ZQ API configuration is incomplete.'], 422);
        }

        $admin = $this->resolveAdmin($request);
        $supplierUser = $this->resolveSupplierUser($request);
        $supplierId = $this->actorSupplierId($admin, $supplierUser);

        try {
            $detailResponse = $this->zqApiService->getImportProductDetail($id);
            $detail = is_array($detailResponse['data'] ?? null) ? $detailResponse['data'] : [];

            if (empty($detail)) {
                return response()->json(['message' => 'ZQ product detail not found.'], 404);
            }

            // Also sync to ZQ cache table
            try {
                $zqSupplierBrandId = $this->resolveZqSupplierBrandId();
                $cachePayload = $this->zqProductSyncService->mapDetailToColumnsPublic($detail, [], $zqSupplierBrandId);
                ZqProduct::query()->updateOrCreate(['zqp_external_id' => $id], $cachePayload);
            } catch (\Throwable $cacheErr) {
                Log::warning('ZQ cache sync failed during import', ['external_id' => $id, 'message' => $cacheErr->getMessage()]);
            }

            $specs = is_array($detail['specs'] ?? null) ? $detail['specs'] : [];
            $images = $this->extractZqImageUrls($detail['images'] ?? []);

            $salePrices = array_values(array_filter(array_map(
                fn($s) => is_numeric($s['salesPrice'] ?? null) ? round((float) $s['salesPrice'] / 100, 2) : null,
                $specs
            ), fn($v) => $v !== null));

            $costPrices = array_values(array_filter(array_map(
                fn($s) => is_numeric($s['cost'] ?? null) ? round((float) $s['cost'] / 100, 2) : null,
                $specs
            ), fn($v) => $v !== null));

            $totalStock = (int) array_sum(array_filter(array_map(
                fn($s) => is_numeric($s['amountOnSale'] ?? null) ? (int) $s['amountOnSale'] : null,
                $specs
            ), fn($v) => $v !== null));

            $minSrp  = count($salePrices) > 0 ? min($salePrices) : 0;
            $minCost = count($costPrices) > 0 ? min($costPrices) : 0;

            $brandId = $this->resolveZqSupplierBrandId() ?? 0;

            $mappedVariants = array_map(function (array $spec) {
                $variantImages = [];
                $attributes = is_array($spec['attributes'] ?? null) ? $spec['attributes'] : [];
                foreach ($attributes as $attr) {
                    $attrRow = is_array($attr) ? $attr : [];
                    if (isset($attrRow['skuImageUrl']) && is_string($attrRow['skuImageUrl']) && trim($attrRow['skuImageUrl']) !== '') {
                        $variantImages[] = trim($attrRow['skuImageUrl']);
                    }
                }
                if (isset($spec['image']) && is_string($spec['image']) && trim($spec['image']) !== '' && ! in_array(trim($spec['image']), $variantImages, true)) {
                    array_unshift($variantImages, trim($spec['image']));
                }

                return [
                    'pv_sku'       => isset($spec['skuId']) ? (string) $spec['skuId'] : '',
                    'pv_name'      => isset($spec['spec']) ? (string) $spec['spec'] : '',
                    'pv_color'     => '',
                    'pv_size'      => '',
                    'pv_style'     => '',
                    'pv_price_srp' => is_numeric($spec['salesPrice'] ?? null) ? round((float) $spec['salesPrice'] / 100, 2) : null,
                    'pv_price_dp'  => is_numeric($spec['cost'] ?? null) ? round((float) $spec['cost'] / 100, 2) : null,
                    'pv_qty'       => is_numeric($spec['amountOnSale'] ?? null) ? (int) $spec['amountOnSale'] : 0,
                    'pv_status'    => 1,
                    'pv_images'    => $variantImages,
                ];
            }, $specs);

            $now = now();

            $product = DB::transaction(function () use ($detail, $images, $minSrp, $minCost, $totalStock, $mappedVariants, $brandId, $supplierId, $now) {
                $product = Product::create([
                    'pd_name'        => (string) ($detail['subject'] ?? 'ZQ Product'),
                    'pd_catid'       => 0,
                    'pd_room_type'   => 0,
                    'pd_brand_type'  => $brandId,
                    'pd_catsubid'    => 0,
                    'pd_catsubid2'   => 0,
                    'pd_shopid'      => 0,
                    'pd_description' => isset($detail['description']) ? (string) $detail['description'] : '',
                    'pd_specifications' => null,
                    'pd_material'    => '',
                    'pd_warranty'    => '',
                    'pd_supplier'    => $supplierId,
                    'pd_price_srp'   => $minSrp,
                    'pd_price_dp'    => $minCost,
                    'pd_price_member' => null,
                    'pd_prodpv'      => 0,
                    'pd_qty'         => $totalStock,
                    'pd_weight'      => 0,
                    'pd_psweight'    => 0,
                    'pd_pswidth'     => 0,
                    'pd_pslenght'    => 0,
                    'pd_psheight'    => 0,
                    'pd_assembly_required' => 0,
                    'pd_preorder'    => '',
                    'pd_preorder_value' => 0,
                    'pd_parent_sku'  => '',
                    'pd_type'        => count($mappedVariants) > 0 ? 1 : 0,
                    'pd_shoptype'    => 0,
                    'pd_musthave'    => 0,
                    'pd_bestseller'  => 0,
                    'pd_salespromo'  => 0,
                    'pd_manual_checkout_enabled' => 0,
                    'pd_user'        => 0,
                    'pd_usertype'    => 0,
                    'pd_date'        => $now,
                    'pd_last_update' => $now,
                    'pd_status'      => 0,
                    'pd_image'       => $images[0] ?? null,
                ]);

                foreach ($images as $imageUrl) {
                    ProductPhoto::create([
                        'pp_pdid'     => $product->pd_id,
                        'pp_filename' => $imageUrl,
                        'pp_varone'   => null,
                        'pp_date'     => $now,
                    ]);
                }

                if (! empty($mappedVariants)) {
                    $this->syncVariants($product, $mappedVariants, $now);
                }

                return $product;
            });

            try {
                $this->recordProductActivity('created', $product, $admin, $supplierUser);
            } catch (\Throwable $logErr) {
                Log::warning('Activity log failed after ZQ import', ['product_id' => $product->pd_id, 'message' => $logErr->getMessage()]);
            }

            return response()->json([
                'message' => 'ZQ product imported to local catalog successfully.',
                'product' => [
                    'id'     => (int) $product->pd_id,
                    'name'   => $product->pd_name,
                    'status' => (int) $product->pd_status,
                    'sku'    => (string) ($product->pd_parent_sku ?? ''),
                ],
            ], 201);

        } catch (\Throwable $e) {
            Log::error('ZQ import to local failed', [
                'external_id' => $id,
                'exception'   => $e::class,
                'message'     => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to import ZQ product to local catalog.'], 500);
        }
    }

    private function resolveZqSupplierBrandId(): ?int
    {
        $brand = ProductBrand::query()
            ->select(['pb_id'])
            ->whereRaw('LOWER(pb_name) = ?', ['zq supplier'])
            ->first();

        if ($brand) {
            return (int) $brand->pb_id;
        }

        $brand = ProductBrand::query()
            ->select(['pb_id'])
            ->where('pb_name', 'ilike', '%zq supplier%')
            ->first();

        return $brand ? (int) $brand->pb_id : null;
    }

    /**
     * @param mixed $images
     * @return array<int, string>
     */
    private function extractZqImageUrls(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }

        return collect($images)
            ->map(function ($image) {
                if (is_array($image) && isset($image['image']) && is_string($image['image'])) {
                    return trim($image['image']);
                }
                if (is_string($image)) {
                    return trim($image);
                }
                return null;
            })
            ->filter(fn ($image) => is_string($image) && $image !== '')
            ->values()
            ->all();
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $admin        = $this->resolveAdmin($request);
        $supplierUser = $this->resolveSupplierUser($request);

        $search              = trim((string) $request->query('q', ''));
        $status              = $request->query('status', '');
        $catId               = $request->query('cat_id', '');
        $brandType           = $request->query('brand_type', '');
        $requestedSupplierId = (int) $request->query('supplier_id', 0);

        $query = Product::query()
            ->select([
                'pd_id', 'pd_name', 'pd_parent_sku', 'pd_catid', 'pd_room_type', 'pd_brand_type', 'pd_supplier',
                'pd_price_srp', 'pd_price_dp', 'pd_price_member', 'pd_prodpv',
                'pd_pricing_tier', 'pd_reversed_pv_multiplier',
                'pd_qty', 'pd_weight', 'pd_psweight', 'pd_pswidth', 'pd_pslenght', 'pd_psheight',
                'pd_description', 'pd_specifications', 'pd_material', 'pd_warranty', 'pd_image',
                'pd_type', 'pd_status', 'pd_musthave', 'pd_bestseller', 'pd_salespromo',
                'pd_assembly_required', 'pd_verified',
            ])
            ->with([
                'variants:pv_id,pv_pdid,pv_sku,pv_name,pv_color,pv_color_hex,pv_size,pv_style,' .
                         'pv_width,pv_dimension,pv_height,pv_price_srp,pv_price_dp,pv_price_member,' .
                         'pv_prodpv,pv_qty,pv_status,pv_images',
            ])
            ->when($search !== '', fn ($q) => $this->applyKeywordSearch($q, $search))
            ->when($status !== '', function ($q) use ($status) {
                $normalizedStatus = (int) $status;
                if ($normalizedStatus === 1) {
                    $q->whereIn('pd_status', [1, 2]);
                    return;
                }
                $q->where('pd_status', $normalizedStatus);
            })
            ->when($catId !== '', fn ($q) => $q->where('pd_catid', (int) $catId))
            ->when($brandType !== '', fn ($q) => $q->where('pd_brand_type', (int) $brandType))
            ->orderByDesc('pd_id');

        if ($supplierUser) {
            $supplierId     = (int) $supplierUser->su_supplier;
            $brandTypeValue = $brandType !== '' ? (int) $brandType : 0;
            if ($brandTypeValue <= 0 && $supplierId > 0) {
                $brandTypeValue = $this->resolveSupplierBrandType($supplierId);
            }
            if ($brandTypeValue > 0) {
                $query->where(fn ($q) => $q->where('pd_supplier', $supplierId)->orWhere('pd_brand_type', $brandTypeValue));
            } else {
                $query->where('pd_supplier', $supplierId);
            }
        } elseif ($admin && $this->roleFromLevel((int) $admin->user_level_id) === 'supplier_admin') {
            $supplierId     = (int) ($admin->supplier_id ?? 0);
            $brandTypeValue = $brandType !== '' ? (int) $brandType : 0;
            if ($brandTypeValue <= 0 && $supplierId > 0) {
                $brandTypeValue = $this->resolveSupplierBrandType($supplierId);
            }
            if ($brandTypeValue > 0) {
                $query->where(fn ($q) => $q->where('pd_supplier', $supplierId > 0 ? $supplierId : -1)->orWhere('pd_brand_type', $brandTypeValue));
            } else {
                $query->where('pd_supplier', $supplierId > 0 ? $supplierId : -1);
            }
        } elseif ($requestedSupplierId > 0 && $admin) {
            $query->where('pd_supplier', $requestedSupplierId);
        }

        $filename = 'products-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

            fputcsv($handle, [
                // Product columns (29)
                'Main Product Name', 'Parent SKU', 'Category', 'Room Type', 'Brand',
                'Price SRP', 'Price DP', 'Price Member', 'Product PV (AUTO)',
                'PV Pricing Tier', 'Reversed PV Multiplier (AUTO)',
                'Quantity', 'Weight', 'Package Weight', 'Package Width', 'Package Length', 'Package Height',
                'Description', 'Specifications', 'Material', 'Warranty', 'Images',
                'Product Type', 'Status', 'Must Have', 'Best Seller', 'Sales Promo', 'Assembly Required',
                'Verified',
                // Variant columns (17)
                'Variant SKU', 'Variant Name', 'Color Name', 'Color Hex',
                'Variant Size', 'Variant Style', 'Variant Width', 'Variant Dimension', 'Variant Height',
                'Variant Price SRP', 'Variant Price DP', 'Variant Price Member',
                'Reversed PV Multiplier (AUTO)', 'Variant PV (AUTO)',
                'Variant Qty', 'Variant Status', 'Variant Images',
            ]);

            // 17 variant columns, 26 = 29 product cols - 3 repeated (name, sku, category)
            $emptyVariantCols = array_fill(0, 17, '');

            $query->chunk(500, function ($products) use ($handle, $emptyVariantCols) {
                foreach ($products as $product) {
                    $images = $product->pd_image ? $product->pd_image : '';

                    $productCols = [
                        $product->pd_name,
                        $product->pd_parent_sku,
                        $product->pd_catid,
                        $product->pd_room_type,
                        $product->pd_brand_type,
                        $product->pd_price_srp,
                        $product->pd_price_dp,
                        $product->pd_price_member,
                        $product->pd_prodpv,
                        $product->pd_pricing_tier,
                        $product->pd_reversed_pv_multiplier,
                        $product->pd_qty,
                        $product->pd_weight,
                        $product->pd_psweight,
                        $product->pd_pswidth,
                        $product->pd_pslenght,
                        $product->pd_psheight,
                        $product->pd_description,
                        $product->pd_specifications,
                        $product->pd_material,
                        $product->pd_warranty,
                        $images,
                        $product->pd_type,
                        $product->pd_status,
                        $product->pd_musthave ? 1 : 0,
                        $product->pd_bestseller ? 1 : 0,
                        $product->pd_salespromo ? 1 : 0,
                        $product->pd_assembly_required ? 1 : 0,
                        $product->pd_verified ? 1 : 0,
                    ];

                    $variants = $product->variants ?? collect();

                    if ($variants->isEmpty()) {
                        fputcsv($handle, array_merge($productCols, $emptyVariantCols));
                        continue;
                    }

                    $isFirst = true;
                    foreach ($variants as $variant) {
                        $variantImages = is_array($variant->pv_images)
                            ? implode('|', $variant->pv_images)
                            : (string) ($variant->pv_images ?? '');

                        $variantCols = [
                            $variant->pv_sku,
                            $variant->pv_name,
                            $variant->pv_color,
                            $variant->pv_color_hex,
                            $variant->pv_size,
                            $variant->pv_style,
                            $variant->pv_width,
                            $variant->pv_dimension,
                            $variant->pv_height,
                            $variant->pv_price_srp,
                            $variant->pv_price_dp,
                            $variant->pv_price_member,
                            '', // Reversed PV Multiplier — variant level not stored separately
                            $variant->pv_prodpv,
                            $variant->pv_qty,
                            $variant->pv_status,
                            $variantImages,
                        ];

                        if ($isFirst) {
                            fputcsv($handle, array_merge($productCols, $variantCols));
                            $isFirst = false;
                        } else {
                            // Repeat name, sku, category; leave remaining 26 product cols blank
                            $continuationCols = array_merge(
                                [$product->pd_name, $product->pd_parent_sku, $product->pd_catid],
                                array_fill(0, 26, '')
                            );
                            fputcsv($handle, array_merge($continuationCols, $variantCols));
                        }
                    }
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
