<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class ProductBrandController extends Controller
{
    private function buildBrandsResponse(string $search = '', bool $activeOnly = false): JsonResponse
    {
        $hasBrandImageColumn = $this->hasBrandImageColumn();
        $columns = ['pb_id', 'pb_name', 'pb_status'];
        if ($hasBrandImageColumn) {
            $columns[] = 'pb_image';
        }

        $brands = ProductBrand::query()
            ->select($columns)
            ->when($activeOnly, function ($query) {
                $query->where('pb_status', 0);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where('pb_name', 'ilike', '%' . $search . '%');
            })
            ->orderBy('pb_name')
            ->get()
            ->map(function (ProductBrand $brand) use ($hasBrandImageColumn) {
                return [
                    'id' => (int) $brand->pb_id,
                    'name' => (string) ($brand->pb_name ?? ''),
                    'image' => $hasBrandImageColumn && $brand->pb_image ? (string) $brand->pb_image : null,
                    'status' => (int) ($brand->pb_status ?? 0),
                ];
            })
            ->values();

        return response()->json([
            'brands' => $brands,
            'total' => $brands->count(),
        ]);
    }

    private function hasBrandImageColumn(): bool
    {
        return Schema::hasColumn('tbl_product_brand', 'pb_image');
    }

    public function publicIndex(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        return $this->buildBrandsResponse($search, true);
    }

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        return $this->buildBrandsResponse($search);
    }

    public function store(Request $request): JsonResponse
    {
        $hasBrandImageColumn = $this->hasBrandImageColumn();
        $validator = Validator::make($request->all(), [
            'pb_name' => 'required|string|max:105',
            'pb_image' => 'nullable|string|max:1000',
            'pb_status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $name = trim((string) $request->input('pb_name'));

        $exists = ProductBrand::query()
            ->whereRaw('LOWER(pb_name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'pb_name' => ['A brand with this name already exists.'],
                ],
            ], 422);
        }

        $payload = [
            'pb_name' => $name,
            'pb_status' => (int) $request->input('pb_status', 0),
        ];

        if ($hasBrandImageColumn) {
            $payload['pb_image'] = $request->filled('pb_image') ? (string) $request->input('pb_image') : null;
        }

        $brand = ProductBrand::create($payload);

        return response()->json([
            'message' => 'Brand created successfully.',
            'brand' => [
                'id' => (int) $brand->pb_id,
                'name' => (string) $brand->pb_name,
                'image' => $hasBrandImageColumn && $brand->pb_image ? (string) $brand->pb_image : null,
                'status' => (int) $brand->pb_status,
            ],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $brand = ProductBrand::query()->find($id);
        if (! $brand) {
            return response()->json(['message' => 'Brand not found.'], 404);
        }

        $hasBrandImageColumn = $this->hasBrandImageColumn();

        $validator = Validator::make($request->all(), [
            'pb_name' => 'sometimes|required|string|max:105',
            'pb_image' => 'nullable|string|max:1000',
            'pb_status' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->has('pb_name')) {
            $name = trim((string) $request->input('pb_name'));
            $exists = ProductBrand::query()
                ->where('pb_id', '!=', $brand->pb_id)
                ->whereRaw('LOWER(pb_name) = ?', [mb_strtolower($name)])
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'pb_name' => ['A brand with this name already exists.'],
                    ],
                ], 422);
            }

            $brand->pb_name = $name;
        }

        if ($hasBrandImageColumn && $request->exists('pb_image')) {
            $brand->pb_image = $request->filled('pb_image') ? (string) $request->input('pb_image') : null;
        }

        if ($request->has('pb_status')) {
            $brand->pb_status = (int) $request->input('pb_status', 0);
        }

        $brand->save();

        return response()->json([
            'message' => 'Brand updated successfully.',
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $brand = ProductBrand::query()->find($id);
        if (! $brand) {
            return response()->json(['message' => 'Brand not found.'], 404);
        }

        $inUse = Product::query()->where('pd_brand_type', $brand->pb_id)->exists();
        if ($inUse) {
            return response()->json([
                'message' => 'This brand is still assigned to one or more products.',
            ], 422);
        }

        $brand->delete();

        return response()->json([
            'message' => 'Brand deleted successfully.',
        ]);
    }

    public function showAllWithProducts(Request $request): JsonResponse
    {
        $hasBrandImageColumn = $this->hasBrandImageColumn();
        
        // Get all active brands
        $brands = ProductBrand::query()
            ->select(['pb_id', 'pb_name', 'pb_status'])
            ->when($hasBrandImageColumn, function ($query) {
                $query->addSelect('pb_image');
            })
            ->where('pb_status', 0) // Only active brands
            ->orderBy('pb_name')
            ->get();

        // Get product counts for all brands (only active products)
        $brandProductCounts = Product::query()
            ->select('pd_brand_type', Product::raw('COUNT(*) as total_products'))
            ->whereIn('pd_status', [1, 2]) // Only active products
            ->whereNotNull('pd_brand_type')
            ->groupBy('pd_brand_type')
            ->pluck('total_products', 'pd_brand_type')
            ->toArray();

        // Simplified brand data with only logo, brand name, and total products
        $brandsData = $brands->map(function ($brand) use ($brandProductCounts, $hasBrandImageColumn) {
            $brandData = [
                'id' => (int) $brand->pb_id,
                'name' => (string) $brand->pb_name,
                'total_products' => $brandProductCounts[$brand->pb_id] ?? 0,
            ];

            // Add brand logo if the column exists
            if ($hasBrandImageColumn) {
                $brandData['logo'] = $brand->pb_image ? (string) $brand->pb_image : null;
            }

            return $brandData;
        });

        return response()->json([
            'brands' => $brandsData,
            'total_brands' => $brandsData->count(),
        ]);
    }

    public function debugBrandImages(int $id): JsonResponse
    {
        $brand = ProductBrand::query()->find($id);
        if (! $brand) {
            return response()->json(['message' => 'Brand not found.'], 404);
        }

        // Get all products for this brand
        $allProducts = Product::query()
            ->select(['pd_id', 'pd_name', 'pd_image', 'pd_status'])
            ->with(['photos:pp_id,pp_pdid,pp_filename'])
            ->where('pd_brand_type', $id)
            ->orderBy('pd_date', 'desc')
            ->get();

        $debugData = [
            'brand_id' => $id,
            'brand_name' => $brand->pb_name,
            'brand_status' => $brand->pb_status,
            'total_products_found' => $allProducts->count(),
            'products' => []
        ];

        foreach ($allProducts as $product) {
            $productData = [
                'id' => $product->pd_id,
                'name' => $product->pd_name,
                'status' => $product->pd_status,
                'main_image' => $product->pd_image,
                'main_image_exists' => !empty(trim($product->pd_image ?? '')),
                'photos_count' => $product->photos->count(),
                'photos' => []
            ];

            foreach ($product->photos as $photo) {
                $productData['photos'][] = [
                    'id' => $photo->pp_id,
                    'filename' => $photo->pp_filename,
                    'is_empty' => empty(trim($photo->pp_filename ?? ''))
                ];
            }

            $debugData['products'][] = $productData;
        }

        return response()->json($debugData);
    }

    public function profile(int $id): JsonResponse
    {
        $brand = ProductBrand::query()->find($id);
        if (! $brand) {
            return response()->json(['message' => 'Brand not found.'], 404);
        }

        $hasBrandImageColumn = $this->hasBrandImageColumn();

        // Get total products for this brand
        $totalProducts = Product::query()
            ->where('pd_brand_type', $id)
            ->whereIn('pd_status', [1, 2]) // Only active products
            ->count();

        // Get brand rating from all products of this brand
        $ratingData = DB::table('tbl_product_reviews as r')
            ->join('tbl_product as p', 'p.pd_id', '=', 'r.pr_product_id')
            ->where('p.pd_brand_type', $id)
            ->where('p.pd_status', [1, 2]) // Only active products
            ->selectRaw('COALESCE(SUM(r.pr_rating), 0) as total_stars, COUNT(*) as review_count')
            ->first();

        $overallRating = $ratingData && (int) ($ratingData->review_count ?? 0) > 0
            ? round(((float) ($ratingData->total_stars ?? 0)) / (int) $ratingData->review_count, 2)
            : null;

        $totalReviews = $ratingData ? (int) $ratingData->review_count : 0;

        // Get supplier information and joined date
        $supplierInfo = DB::table('tbl_product as p')
            ->join('tbl_supplier as s', 's.s_id', '=', 'p.pd_supplier')
            ->leftJoin('tbl_supplier_user as su', 'su.su_supplier', '=', 's.s_id')
            ->where('p.pd_brand_type', $id)
            ->select('s.s_name as supplier_name', 'su.su_date_created as joined_date')
            ->first();

        // Calculate chat performance (mock calculation - you may need to adjust based on your business logic)
        $chatPerformance = 95; // Default value, you can calculate this based on actual chat metrics

        $brandData = [
            'id' => (int) $brand->pb_id,
            'name' => (string) $brand->pb_name,
            'profile_picture' => $hasBrandImageColumn && $brand->pb_image ? (string) $brand->pb_image : null,
            'status' => (int) ($brand->pb_status ?? 0),
            'is_online' => true, // You may want to implement actual online status logic
            'chat_performance' => $chatPerformance,
            'overall_rating' => $overallRating,
            'total_reviews' => $totalReviews,
            'total_products' => $totalProducts,
            'joined_date' => $supplierInfo?->joined_date ? $supplierInfo->joined_date : null,
            'supplier_name' => $supplierInfo?->supplier_name ? (string) $supplierInfo->supplier_name : null,
        ];

        return response()->json([
            'brand' => $brandData,
        ]);
    }
}
