<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class ProductBrandController extends Controller
{
    private function hasBrandImageColumn(): bool
    {
        return Schema::hasColumn('tbl_product_brand', 'pb_image');
    }

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $hasBrandImageColumn = $this->hasBrandImageColumn();
        $columns = ['pb_id', 'pb_name', 'pb_status'];
        if ($hasBrandImageColumn) {
            $columns[] = 'pb_image';
        }

        $brands = ProductBrand::query()
            ->select($columns)
            ->when($search !== '', function ($query) use ($search) {
                $query->where('pb_name', 'ilike', '%' . $search . '%');
            })
            ->orderBy('pb_name')
            ->get()
            ->map(function (ProductBrand $brand) {
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
}
