<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function showAllWithProducts(): JsonResponse
    {
        $hasBrandImageColumn = $this->hasBrandImageColumn();
        
        // Get all brands (remove status filter to include all brands for debugging)
        $brands = ProductBrand::query()
            ->select(['pb_id', 'pb_name', 'pb_status'])
            ->when($hasBrandImageColumn, function ($query) {
                $query->addSelect('pb_image');
            })
            ->orderBy('pb_name')
            ->get();

        // Get product counts for all brands (include all product statuses for debugging)
        $brandProductCounts = Product::query()
            ->select('pd_brand_type', Product::raw('COUNT(*) as total_products'))
            ->whereNotNull('pd_brand_type')
            ->groupBy('pd_brand_type')
            ->pluck('total_products', 'pd_brand_type')
            ->toArray();

        // Get sample images for each brand (up to 6 per brand)
        $brandsWithImages = $brands->map(function ($brand) use ($brandProductCounts, $hasBrandImageColumn) {
            $brandId = (int) $brand->pb_id;
            
            // Keep getting products until we have 6 images or run out of products
            $brandImages = [];
            $debugInfo = [
                'products_checked' => 0,
                'products_with_main_image' => 0,
                'products_with_photos' => 0,
                'total_photos_found' => 0,
                'target_images' => 6,
                'images_collected' => 0,
            ];

            $offset = 0;
            $batchSize = 10; // Check 10 products at a time for efficiency
            
            while (count($brandImages) < 6 && $offset < 100) { // Safety limit of 100 products checked
                $products = Product::query()
                    ->select(['pd_id', 'pd_image', 'pd_status'])
                    ->with(['photos:pp_id,pp_pdid,pp_filename'])
                    ->where('pd_brand_type', $brandId)
                    ->orderBy('pd_date', 'desc')
                    ->offset($offset)
                    ->limit($batchSize)
                    ->get();

                if ($products->isEmpty()) {
                    break; // No more products to check
                }

                $debugInfo['products_checked'] += $products->count();

                foreach ($products as $product) {
                    // Stop if we already have 6 images
                    if (count($brandImages) >= 6) {
                        break;
                    }

                    // Add main product image if exists
                    if ($product->pd_image && !empty(trim($product->pd_image))) {
                        if (!in_array($product->pd_image, $brandImages)) {
                            $brandImages[] = $product->pd_image;
                            $debugInfo['products_with_main_image']++;
                            
                            // Stop if we reached 6 images
                            if (count($brandImages) >= 6) {
                                break;
                            }
                        }
                    }
                    
                    // Add additional photos (only if we still need more images)
                    if ($product->photos && $product->photos->isNotEmpty()) {
                        $debugInfo['products_with_photos']++;
                        foreach ($product->photos as $photo) {
                            if ($photo->pp_filename && !empty(trim($photo->pp_filename))) {
                                if (!in_array($photo->pp_filename, $brandImages)) {
                                    $brandImages[] = $photo->pp_filename;
                                    $debugInfo['total_photos_found']++;
                                    
                                    // Stop if we reached 6 images
                                    if (count($brandImages) >= 6) {
                                        break 2; // Break out of both loops
                                    }
                                }
                            }
                        }
                    }
                }

                $offset += $batchSize;
            }

            // Limit to exactly 6 images
            $brandImages = array_slice($brandImages, 0, 6);
            $debugInfo['images_collected'] = count($brandImages);

            $brandData = [
                'id' => $brandId,
                'name' => (string) $brand->pb_name,
                'status' => (int) ($brand->pb_status ?? 0),
                'total_products' => $brandProductCounts[$brandId] ?? 0,
                'images' => $brandImages,
                'debug' => $debugInfo, // Include debug info for troubleshooting
            ];

            if ($hasBrandImageColumn) {
                $brandData['brand_image'] = $brand->pb_image ? (string) $brand->pb_image : null;
            }

            return $brandData;
        });

        return response()->json([
            'brands' => $brandsWithImages,
            'total_brands' => $brandsWithImages->count(),
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
}
