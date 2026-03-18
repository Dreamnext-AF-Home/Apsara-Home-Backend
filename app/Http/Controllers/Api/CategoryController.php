<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $supplierId = (int) $request->query('supplier_id', 0);
        $usedOnly = $request->boolean('used_only', false);

        $productCounts = DB::table('tbl_product')
            ->selectRaw('pd_catid as category_id, COUNT(*) as total')
            ->whereIn('pd_status', [1, 2])
            ->when($supplierId > 0, function ($query) use ($supplierId) {
                $query->where('pd_supplier', $supplierId);
            })
            ->groupBy('pd_catid')
            ->pluck('total', 'category_id');

        $categories = Category::select([
                'cat_id', 'cat_name', 'cat_description',
                'cat_url', 'cat_image', 'cat_order',
            ])
            ->when($usedOnly && $supplierId > 0, function ($query) use ($productCounts) {
                $categoryIds = collect($productCounts)->keys()->map(fn ($id) => (int) $id)->all();
                $query->whereIn('cat_id', !empty($categoryIds) ? $categoryIds : [-1]);
            })
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($inner) use ($like) {
                    $inner->where('cat_name', 'ilike', $like)
                          ->orWhere('cat_description', 'ilike', $like)
                          ->orWhere('cat_url', 'ilike', $like);
                });
            })
            ->orderBy('cat_order')
            ->orderByDesc('cat_id')
            ->get()
            ->map(fn (Category $c) => [
                'id'          => (int)    $c->cat_id,
                'name'        => (string) ($c->cat_name ?? ''),
                'description' => (string) ($c->cat_description ?? ''),
                'url'         => (string) ($c->cat_url ?? ''),
                'image'       => $this->normalizeCategoryImage($c->cat_image),
                'order'       => (int)    $c->cat_order,
                'product_count' => (int) ($productCounts[(int) $c->cat_id] ?? 0),
            ])
            ->values();

        return response()->json([
            'categories' => $categories,
            'total'      => $categories->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cat_name'        => 'required|string|max:50',
            'cat_description' => 'nullable|string|max:200',
            'cat_url'         => 'nullable|string|max:40',
            'cat_order'       => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $slug = $request->cat_url
            ? Str::slug($request->cat_url)
            : Str::slug($request->cat_name);

        $category = Category::create([
            'cat_name'        => trim($request->cat_name),
            'cat_description' => trim($request->cat_description ?? ''),
            'cat_url'         => $slug,
            'cat_image'       => '0',
            'cat_order'       => (int) ($request->cat_order ?? 0),
        ]);

        return response()->json([
            'message'  => 'Category created successfully.',
            'category' => [
                'id'   => $category->cat_id,
                'name' => $category->cat_name,
                'url'  => $category->cat_url,
            ],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::find($id);
        if (! $category) {
            return response()->json(['message' => 'Category not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'cat_name'        => 'sometimes|required|string|max:50',
            'cat_description' => 'nullable|string|max:200',
            'cat_url'         => 'nullable|string|max:40',
            'cat_order'       => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('cat_name')) {
            $category->cat_name = trim($request->cat_name);
        }

        if ($request->has('cat_description')) {
            $category->cat_description = trim($request->cat_description ?? '');
        }

        if ($request->has('cat_url') && $request->cat_url) {
            $category->cat_url = Str::slug($request->cat_url);
        } elseif ($request->has('cat_name') && ! $request->has('cat_url')) {
            $category->cat_url = Str::slug($request->cat_name);
        }

        if ($request->has('cat_order')) {
            $category->cat_order = (int) $request->cat_order;
        }

        $category->save();

        return response()->json(['message' => 'Category updated successfully.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $category = Category::find($id);
        if (! $category) {
            return response()->json(['message' => 'Category not found.'], 404);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted successfully.']);
    }

    private function normalizeCategoryImage(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $image = trim($value);
        if ($image === '' || $image === '0') {
            return null;
        }

        if (Str::startsWith($image, ['http://', 'https://', '//', 'data:'])) {
            return $image;
        }

        $base = rtrim((string) config('app.url'), '/');
        return $base !== '' ? $base . '/' . ltrim($image, '/') : $image;
    }
}
