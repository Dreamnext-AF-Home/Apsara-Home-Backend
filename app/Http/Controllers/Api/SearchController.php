<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPhoto;
use App\Models\SearchHistory;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    /**
     * Live search endpoint for real-time typing results
     */
    public function liveSearch(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:2|max:255',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = trim($request->input('q'));
        $limit = min((int) $request->input('limit', 10), 20);
        $customerId = auth('sanctum')->id();

        try {
            // Get live search results
            $products = $this->getLiveSearchProducts($query, $limit, $customerId);

            return response()->json([
                'success' => true,
                'data' => $products,
                'count' => count($products),
                'query' => $query,
            ]);
        } catch (\Exception $e) {
            Log::error('Live search error: ' . $e->getMessage(), [
                'query' => $query,
                'customer_id' => $customerId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Search failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Get search recommendations
     */
    public function recommendations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = trim($request->input('q', ''));
        $customerId = auth('sanctum')->id();

        try {
            $recommendations = $this->getSearchRecommendations($query, $customerId);

            return response()->json([
                'success' => true,
                'data' => $recommendations,
                'count' => count($recommendations),
                'query' => $query,
            ]);
        } catch (\Exception $e) {
            Log::error('Search recommendations error: ' . $e->getMessage(), [
                'query' => $query,
                'customer_id' => $customerId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get recommendations.',
            ], 500);
        }
    }

    /**
     * Full search endpoint
     */
    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:1|max:255',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:50',
            'category' => 'nullable|integer',
            'brand' => 'nullable|integer',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'sort' => 'nullable|in:relevance,name_asc,name_desc,price_asc,price_desc,newest',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = trim($request->input('q'));
        $page = max((int) $request->input('page', 1), 1);
        $limit = min((int) $request->input('limit', 20), 50);
        $customerId = auth('sanctum')->id();

        try {
            // Save search history
            if ($customerId) {
                $this->saveSearchHistory($customerId, $query);
            }

            $results = $this->getFullSearchResults($query, $page, $limit, $request->all(), $customerId);

            return response()->json([
                'success' => true,
                'data' => $results['data'],
                'pagination' => $results['pagination'],
                'filters' => $results['filters'],
                'query' => $query,
            ]);
        } catch (\Exception $e) {
            Log::error('Full search error: ' . $e->getMessage(), [
                'query' => $query,
                'customer_id' => $customerId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Search failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Get products for live search
     */
    private function getLiveSearchProducts(string $query, int $limit, ?int $customerId): array
    {
        $isMember = $this->isMember($customerId);
        
        $products = DB::table('tbl_product as p')
            ->select([
                'p.pd_id as id',
                'p.pd_name as name',
                'p.pd_price_srp as original_price',
                $isMember ? 'p.pd_price_member as discounted_price' : 'p.pd_price_dp as discounted_price',
                'p.pd_prodpv as pv',
                'p.pd_image as image',
                'p.pd_status as status'
            ])
            ->where('p.pd_status', 1) // Only active products
            ->whereNotNull('p.pd_image') // Only products with photos
            ->where('p.pd_image', '!=', '') // Only products with non-empty photos
            ->where(function ($builder) use ($query) {
                $builder->where('p.pd_name', 'LIKE', '%' . $query . '%')
                      ->orWhere('p.pd_description', 'LIKE', '%' . $query . '%');
            })
            ->orderBy('p.pd_bestseller', 'desc')
            ->orderBy('p.pd_musthave', 'desc')
            ->orderBy('p.pd_name')
            ->limit($limit)
            ->get();

        return $products->map(function ($product) {
            return [
                'id' => (int) $product->id,
                'name' => $product->name,
                'original_price' => (float) $product->original_price,
                'discounted_price' => (float) $product->discounted_price,
                'pv' => (float) $product->pv,
                'image' => $this->formatImageUrl($product->image),
                'has_discount' => $product->discounted_price < $product->original_price,
                'discount_percentage' => $this->calculateDiscountPercentage($product->original_price, $product->discounted_price),
            ];
        })->toArray();
    }

    /**
     * Get search recommendations
     */
    private function getSearchRecommendations(string $query, ?int $customerId): array
    {
        $recommendations = [];
        $isMember = $this->isMember($customerId);

        if ($customerId) {
            // Get user's search history to find their preferred categories
            $userCategories = $this->getUserPreferredCategories($customerId, 5);
            
            if (!empty($userCategories)) {
                // Get 6 products per category from user's preferred categories
                foreach ($userCategories as $category) {
                    $products = $this->getProductsByCategory($category['id'], 6, $isMember);
                    $recommendations = array_merge($recommendations, $products);
                }
            }
        }

        // If we don't have enough recommendations or no user history, get popular products
        if (count($recommendations) < 20) {
            $remainingCount = 20 - count($recommendations);
            $popularProducts = $this->getPopularProducts($remainingCount, $isMember);
            $recommendations = array_merge($recommendations, $popularProducts);
        }

        // Always return exactly 20 items
        return array_slice($recommendations, 0, 20);
    }

    /**
     * Get user's preferred categories based on search history
     */
    private function getUserPreferredCategories(int $customerId, int $limit): array
    {
        return DB::table('tbl_search_history as sh')
            ->select([
                'p.pd_catid as category_id',
                'c.cat_name as category_name',
                DB::raw('COUNT(DISTINCT sh.sh_id) as search_count'),
                DB::raw('COUNT(DISTINCT p.pd_id) as product_count')
            ])
            ->join('tbl_product as p', function ($join) use ($customerId) {
                $join->whereRaw('LOWER(p.pd_name) LIKE CONCAT(\'%\', LOWER(sh.sh_query), \'%\')')
                     ->orWhereRaw('LOWER(p.pd_description) LIKE CONCAT(\'%\', LOWER(sh.sh_query), \'%\')');
            })
            ->leftJoin('tbl_category as c', 'p.pd_catid', '=', 'c.cat_id')
            ->where('sh.sh_customer_id', $customerId)
            ->where('p.pd_catid', '>', 0)
            ->groupBy('p.pd_catid', 'c.cat_name')
            ->orderBy('search_count', 'desc')
            ->orderBy('product_count', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($category) {
                return [
                    'id' => (int) $category->category_id,
                    'name' => $category->category_name,
                ];
            })
            ->toArray();
    }

    /**
     * Get products by category
     */
    private function getProductsByCategory(int $categoryId, int $limit, bool $isMember): array
    {
        $products = DB::table('tbl_product as p')
            ->select([
                'p.pd_id as id',
                'p.pd_name as name',
                'p.pd_price_srp as original_price',
                $isMember ? 'p.pd_price_member as discounted_price' : 'p.pd_price_dp as discounted_price',
                'p.pd_prodpv as pv',
                'p.pd_image as image',
                'c.cat_name as category_name'
            ])
            ->leftJoin('tbl_category as c', 'p.pd_catid', '=', 'c.cat_id')
            ->where('p.pd_catid', $categoryId)
            ->where('p.pd_status', 1)
            ->whereNotNull('p.pd_image') // Only products with photos
            ->where('p.pd_image', '!=', '') // Only products with non-empty photos
            ->orderBy('p.pd_bestseller', 'desc')
            ->orderBy('p.pd_musthave', 'desc')
            ->orderBy('p.pd_name')
            ->limit($limit)
            ->get();

        return $products->map(function ($product) {
            return [
                'id' => (int) $product->id,
                'name' => $product->name,
                'image' => $this->formatImageUrl($product->image),
                'category_name' => $product->category_name,
                'type' => 'product'
            ];
        })->toArray();
    }

    /**
     * Get popular products when no user history available
     */
    private function getPopularProducts(int $limit, bool $isMember): array
    {
        $products = DB::table('tbl_product as p')
            ->select([
                'p.pd_id as id',
                'p.pd_name as name',
                'p.pd_price_srp as original_price',
                $isMember ? 'p.pd_price_member as discounted_price' : 'p.pd_price_dp as discounted_price',
                'p.pd_prodpv as pv',
                'p.pd_image as image',
                'c.cat_name as category_name'
            ])
            ->leftJoin('tbl_category as c', 'p.pd_catid', '=', 'c.cat_id')
            ->where('p.pd_status', 1)
            ->whereNotNull('p.pd_image') // Only products with photos
            ->where('p.pd_image', '!=', '') // Only products with non-empty photos
            ->orderBy('p.pd_bestseller', 'desc')
            ->orderBy('p.pd_musthave', 'desc')
            ->orderBy('p.pd_date', 'desc')
            ->limit($limit)
            ->get();

        return $products->map(function ($product) {
            return [
                'id' => (int) $product->id,
                'name' => $product->name,
                'image' => $this->formatImageUrl($product->image),
                'category_name' => $product->category_name,
                'type' => 'product'
            ];
        })->toArray();
    }

    /**
     * Get full search results with pagination
     */
    private function getFullSearchResults(string $query, int $page, int $limit, array $filters, ?int $customerId): array
    {
        $isMember = $this->isMember($customerId);
        $offset = ($page - 1) * $limit;

        $baseQuery = DB::table('tbl_product as p')
            ->select([
                'p.pd_id as id',
                'p.pd_name as name',
                'p.pd_description as description',
                'p.pd_price_srp as original_price',
                $isMember ? 'p.pd_price_member as discounted_price' : 'p.pd_price_dp as discounted_price',
                'p.pd_prodpv as pv',
                'p.pd_image as image',
                'p.pd_status as status',
                'p.pd_qty as stock',
                'pb.pb_name as brand_name',
                'c.cat_name as category_name'
            ])
            ->leftJoin('tbl_product_brand as pb', 'p.pd_brand_type', '=', 'pb.pb_id')
            ->leftJoin('tbl_category as c', 'p.pd_catid', '=', 'c.cat_id')
            ->where('p.pd_status', 1)
            ->whereNotNull('p.pd_image') // Only products with photos
            ->where('p.pd_image', '!=', '') // Only products with non-empty photos
            ->where(function ($builder) use ($query) {
                $builder->where('p.pd_name', 'LIKE', '%' . $query . '%')
                      ->orWhere('p.pd_description', 'LIKE', '%' . $query . '%');
            });

        // Apply filters
        if (!empty($filters['category'])) {
            $baseQuery->where('p.pd_catid', (int) $filters['category']);
        }
        if (!empty($filters['brand'])) {
            $baseQuery->where('p.pd_brand_type', (int) $filters['brand']);
        }
        if (!empty($filters['min_price'])) {
            $priceColumn = $isMember ? 'p.pd_price_member' : 'p.pd_price_dp';
            $baseQuery->where($priceColumn, '>=', (float) $filters['min_price']);
        }
        if (!empty($filters['max_price'])) {
            $priceColumn = $isMember ? 'p.pd_price_member' : 'p.pd_price_dp';
            $baseQuery->where($priceColumn, '<=', (float) $filters['max_price']);
        }

        // Apply sorting
        $sort = $filters['sort'] ?? 'relevance';
        switch ($sort) {
            case 'name_asc':
                $baseQuery->orderBy('p.pd_name', 'asc');
                break;
            case 'name_desc':
                $baseQuery->orderBy('p.pd_name', 'desc');
                break;
            case 'price_asc':
                $priceColumn = $isMember ? 'p.pd_price_member' : 'p.pd_price_dp';
                $baseQuery->orderBy($priceColumn, 'asc');
                break;
            case 'price_desc':
                $priceColumn = $isMember ? 'p.pd_price_member' : 'p.pd_price_dp';
                $baseQuery->orderBy($priceColumn, 'desc');
                break;
            case 'newest':
                $baseQuery->orderBy('p.pd_date', 'desc');
                break;
            default: // relevance
                $baseQuery->orderBy('p.pd_bestseller', 'desc')
                          ->orderBy('p.pd_musthave', 'desc')
                          ->orderBy('p.pd_name');
                break;
        }

        // Get total count for pagination
        $total = $baseQuery->count();

        // Get paginated results
        $products = $baseQuery->offset($offset)->limit($limit)->get();

        $formattedProducts = $products->map(function ($product) {
            return [
                'id' => (int) $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'original_price' => (float) $product->original_price,
                'discounted_price' => (float) $product->discounted_price,
                'pv' => (float) $product->pv,
                'image' => $this->formatImageUrl($product->image),
                'stock' => (float) $product->stock,
                'brand_name' => $product->brand_name,
                'category_name' => $product->category_name,
                'has_discount' => $product->discounted_price < $product->original_price,
                'discount_percentage' => $this->calculateDiscountPercentage($product->original_price, $product->discounted_price),
                'in_stock' => $product->stock > 0,
            ];
        })->toArray();

        return [
            'data' => $formattedProducts,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit),
                'has_more' => $total > ($offset + $limit),
            ],
            'filters' => [
                'applied' => $filters,
                'available' => $this->getAvailableFilters($query, $customerId),
            ],
        ];
    }

    /**
     * Check if user is a member
     */
    private function isMember(?int $customerId): bool
    {
        if (!$customerId) {
            return false;
        }

        $customer = Customer::find($customerId);
        return $customer && (int) $customer->c_accnt_status === 1;
    }

    /**
     * Save search history
     */
    private function saveSearchHistory(int $customerId, string $query): void
    {
        try {
            SearchHistory::create([
                'sh_customer_id' => $customerId,
                'sh_query' => $query,
                'sh_date_created' => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to save search history', [
                'customer_id' => $customerId,
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Format image URL
     */
    private function formatImageUrl(?string $image): string
    {
        if (!$image) {
            return asset('images/placeholder-product.jpg');
        }

        // If it's already a full URL, return as is
        if (str_starts_with($image, 'http')) {
            return $image;
        }

        // If it starts with /, it's relative to domain
        if (str_starts_with($image, '/')) {
            return url($image);
        }

        // Otherwise, assume it's in the uploads folder
        return url('uploads/products/' . $image);
    }

    /**
     * Calculate discount percentage
     */
    private function calculateDiscountPercentage(float $originalPrice, float $discountedPrice): float
    {
        if ($originalPrice <= 0 || $discountedPrice >= $originalPrice) {
            return 0;
        }

        return round((($originalPrice - $discountedPrice) / $originalPrice) * 100, 1);
    }

    /**
     * Get available filters for search
     */
    private function getAvailableFilters(string $query, ?int $customerId): array
    {
        // This can be expanded to return dynamic filter options
        return [
            'categories' => $this->getAvailableCategories($query),
            'brands' => $this->getAvailableBrands($query),
            'price_ranges' => [
                ['min' => 0, 'max' => 1000, 'label' => 'Under ₱1,000'],
                ['min' => 1000, 'max' => 5000, 'label' => '₱1,000 - ₱5,000'],
                ['min' => 5000, 'max' => 10000, 'label' => '₱5,000 - ₱10,000'],
                ['min' => 10000, 'max' => 50000, 'label' => '₱10,000 - ₱50,000'],
                ['min' => 50000, 'max' => null, 'label' => 'Above ₱50,000'],
            ],
        ];
    }

    /**
     * Get available categories for search
     */
    private function getAvailableCategories(string $query): array
    {
        return DB::table('tbl_category as c')
            ->select(['c.cat_id as id', 'c.cat_name as name', DB::raw('COUNT(*) as count')])
            ->join('tbl_product as p', 'c.cat_id', '=', 'p.pd_catid')
            ->where('p.pd_status', 1)
            ->where(function ($builder) use ($query) {
                $builder->where('p.pd_name', 'LIKE', '%' . $query . '%')
                      ->orWhere('p.pd_description', 'LIKE', '%' . $query . '%');
            })
            ->groupBy('c.cat_id', 'c.cat_name')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Get available brands for search
     */
    private function getAvailableBrands(string $query): array
    {
        return DB::table('tbl_product_brand as pb')
            ->select(['pb.pb_id as id', 'pb.pb_name as name', DB::raw('COUNT(*) as count')])
            ->join('tbl_product as p', 'pb.pb_id', '=', 'p.pd_brand_type')
            ->where('p.pd_status', 1)
            ->where(function ($builder) use ($query) {
                $builder->where('p.pd_name', 'LIKE', '%' . $query . '%')
                      ->orWhere('p.pd_description', 'LIKE', '%' . $query . '%');
            })
            ->groupBy('pb.pb_id', 'pb.pb_name')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }
}
