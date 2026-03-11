<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPhoto;
use App\Models\ProductVariant;
use App\Models\ProductVariantPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
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

    private function mapVariants(Product $product): array
    {
        return $product->variants->map(function (ProductVariant $variant) {
            $images = $variant->photos
                ->map(fn (ProductVariantPhoto $photo) => (string) $photo->pvp_filename)
                ->filter(fn (string $url) => trim($url) !== '')
                ->values()
                ->all();

            return [
                'id'       => (int) $variant->pv_id,
                'sku'      => (string) ($variant->pv_sku ?? ''),
                'color'    => (string) ($variant->pv_color ?? ''),
                'colorHex' => (string) ($variant->pv_color_hex ?? ''),
                'size'     => (string) ($variant->pv_size ?? ''),
                'priceSrp' => $this->toNumber($variant->pv_price_srp),
                'priceDp'  => $this->toNumber($variant->pv_price_dp),
                'qty'      => $this->toNumber($variant->pv_qty),
                'status'   => (int) ($variant->pv_status ?? 1),
                'images'   => $images,
            ];
        })->values()->all();
    }

    private function syncVariants(Product $product, array $variants, \DateTimeInterface $now): void
    {
        ProductVariant::query()->where('pv_pdid', $product->pd_id)->delete();

        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $sku = isset($variant['pv_sku']) ? trim((string) $variant['pv_sku']) : '';
            $color = isset($variant['pv_color']) ? trim((string) $variant['pv_color']) : '';
            $size = isset($variant['pv_size']) ? trim((string) $variant['pv_size']) : '';
            $images = collect($variant['pv_images'] ?? [])
                ->filter(fn ($url) => is_string($url) && trim($url) !== '')
                ->values()
                ->all();

            if ($sku === '' && $color === '' && $size === '' && empty($images)) {
                continue;
            }

            $row = ProductVariant::create([
                'pv_pdid'      => $product->pd_id,
                'pv_sku'       => $sku !== '' ? $sku : null,
                'pv_color'     => $color !== '' ? $color : null,
                'pv_color_hex' => isset($variant['pv_color_hex']) ? trim((string) $variant['pv_color_hex']) : null,
                'pv_size'      => $size !== '' ? $size : null,
                'pv_price_srp' => isset($variant['pv_price_srp']) && $variant['pv_price_srp'] !== '' ? $variant['pv_price_srp'] : null,
                'pv_price_dp'  => isset($variant['pv_price_dp']) && $variant['pv_price_dp'] !== '' ? $variant['pv_price_dp'] : null,
                'pv_qty'       => isset($variant['pv_qty']) && $variant['pv_qty'] !== '' ? $variant['pv_qty'] : null,
                'pv_status'    => isset($variant['pv_status']) ? (int) $variant['pv_status'] : 1,
                'pv_date'      => $now,
            ]);

            foreach ($images as $imgIndex => $url) {
                ProductVariantPhoto::create([
                    'pvp_pvid'     => $row->pv_id,
                    'pvp_filename' => $url,
                    'pvp_sort'     => $imgIndex,
                    'pvp_date'     => $now,
                ]);
            }
        }
    }

    private function mapProduct(Product $p): array
    {
        $images = $p->photos
            ->map(fn (ProductPhoto $photo) => (string) $photo->pp_filename)
            ->filter(fn (string $url) => trim($url) !== '')
            ->values()
            ->all();

        $primaryImage = $images[0] ?? ($p->pd_image ?? null);

        return [
            'id'          => (int)   $p->pd_id,
            'name'        => (string) ($p->pd_name ?? ''),
            'description'       => $p->pd_description ?? null,
            'specifications'    => $p->pd_specifications ?? null,
            'material'          => $p->pd_material ?? null,
            'warranty'          => $p->pd_warranty ?? null,
            'catid'             => (int)   $p->pd_catid,
            'catsubid'          => (int)   $p->pd_catsubid,
            'priceSrp'          => $this->toNumber($p->pd_price_srp),
            'priceDp'           => $this->toNumber($p->pd_price_dp),
            'prodpv'            => $this->toNumber($p->pd_prodpv),
            'qty'               => $this->toNumber($p->pd_qty),
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
            'status'      => (int)   $p->pd_status,
            'sku'         => (string) ($p->pd_parent_sku ?? ''),
            'image'       => $primaryImage,
            'images'      => $images,
            'variants'    => $this->mapVariants($p),
            'createdAt'   => $p->pd_date ? $p->pd_date->format('Y-m-d') : null,
            'updatedAt'   => $p->pd_last_update ? $p->pd_last_update->format('Y-m-d') : null,
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
                'pd_catid', 'pd_catsubid',
                'pd_price_srp', 'pd_price_dp', 'pd_qty',
                'pd_prodpv',
                'pd_weight', 'pd_psweight', 'pd_pswidth', 'pd_pslenght', 'pd_psheight',
                'pd_assembly_required', 'pd_type', 'pd_musthave',
                'pd_bestseller', 'pd_salespromo', 'pd_status', 'pd_date',
                'pd_last_update', 'pd_parent_sku', 'pd_image',
            ])
            ->with([
                'photos:pp_id,pp_pdid,pp_filename,pp_varone,pp_date',
                'variants:pv_id,pv_pdid,pv_sku,pv_color,pv_color_hex,pv_size,pv_price_srp,pv_price_dp,pv_qty,pv_status,pv_date',
                'variants.photos:pvp_id,pvp_pvid,pvp_filename,pvp_sort,pvp_date',
            ])
            ->where('pd_status', 1)
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
                'pd_catid', 'pd_catsubid',
                'pd_price_srp', 'pd_price_dp', 'pd_qty',
                'pd_prodpv',
                'pd_weight', 'pd_psweight', 'pd_pswidth', 'pd_pslenght', 'pd_psheight',
                'pd_assembly_required', 'pd_type', 'pd_musthave',
                'pd_bestseller', 'pd_salespromo', 'pd_status', 'pd_date',
                'pd_last_update', 'pd_parent_sku', 'pd_image',
            ])
            ->with([
                'photos:pp_id,pp_pdid,pp_filename,pp_varone,pp_date',
                'variants:pv_id,pv_pdid,pv_sku,pv_color,pv_color_hex,pv_size,pv_price_srp,pv_price_dp,pv_qty,pv_status,pv_date',
                'variants.photos:pvp_id,pvp_pvid,pvp_filename,pvp_sort,pvp_date',
            ])
            ->where('pd_status', 1)
            ->where('pd_id', $id)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        return response()->json([
            'product' => $this->mapProduct($product),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));
        $search  = trim((string) $request->query('q', ''));
        $status  = $request->query('status', '');
        $catId   = $request->query('cat_id', '');

        $query = Product::query()
            ->select([
                'pd_id', 'pd_name', 'pd_description', 'pd_specifications', 'pd_material', 'pd_warranty',
                'pd_catid', 'pd_catsubid',
                'pd_price_srp', 'pd_price_dp', 'pd_qty',
                'pd_prodpv',
                'pd_weight', 'pd_psweight', 'pd_pswidth', 'pd_pslenght', 'pd_psheight',
                'pd_assembly_required', 'pd_type', 'pd_musthave',
                'pd_bestseller', 'pd_salespromo', 'pd_status', 'pd_date',
                'pd_last_update', 'pd_parent_sku', 'pd_image',
            ])
            ->with([
                'photos:pp_id,pp_pdid,pp_filename,pp_varone,pp_date',
                'variants:pv_id,pv_pdid,pv_sku,pv_color,pv_color_hex,pv_size,pv_price_srp,pv_price_dp,pv_qty,pv_status,pv_date',
                'variants.photos:pvp_id,pvp_pvid,pvp_filename,pvp_sort,pvp_date',
            ])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($inner) use ($like) {
                    $inner->where('pd_name', 'ilike', $like)
                          ->orWhere('pd_parent_sku', 'ilike', $like);
                });
            })
            ->when($status !== '', function ($q) use ($status) {
                $q->where('pd_status', (int) $status);
            })
            ->when($catId !== '', function ($q) use ($catId) {
                $q->where('pd_catid', (int) $catId);
            })
            ->orderByDesc('pd_id');

        $paginator = $query->paginate($perPage);

        $products = collect($paginator->items())
            ->map(fn (Product $p) => $this->mapProduct($p))
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
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pd_name'      => 'required|string|max:255',
            'pd_catid'     => 'required|integer',
            'pd_catsubid'  => 'nullable|integer',
            'pd_price_srp' => 'required|numeric|min:0',
            'pd_price_dp'  => 'nullable|numeric|min:0',
            'pd_prodpv'    => 'nullable|numeric|min:0',
            'pd_qty'       => 'nullable|numeric|min:0',
            'pd_weight'    => 'nullable|integer|min:0',
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
            'pd_status'      => 'nullable|integer|in:0,1',
            'pd_image'       => 'nullable|string|max:500',
            'pd_images'      => 'nullable|array',
            'pd_images.*'    => 'nullable|string|max:1000',
            'pd_variants'                => 'nullable|array',
            'pd_variants.*.pv_sku'       => 'nullable|string|max:80',
            'pd_variants.*.pv_color'     => 'nullable|string|max:80',
            'pd_variants.*.pv_color_hex' => 'nullable|string|max:16',
            'pd_variants.*.pv_size'      => 'nullable|string|max:40',
            'pd_variants.*.pv_price_srp' => 'nullable|numeric|min:0',
            'pd_variants.*.pv_price_dp'  => 'nullable|numeric|min:0',
            'pd_variants.*.pv_qty'       => 'nullable|numeric|min:0',
            'pd_variants.*.pv_status'    => 'nullable|integer|in:0,1',
            'pd_variants.*.pv_images'    => 'nullable|array',
            'pd_variants.*.pv_images.*'  => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $now = now();

        $images = collect($request->input('pd_images', []))
            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
            ->values()
            ->all();

        if (empty($images) && is_string($request->pd_image) && trim($request->pd_image) !== '') {
            $images = [trim($request->pd_image)];
        }

        $product = DB::transaction(function () use ($request, $now, $images) {
            $product = Product::create([
                'pd_name'        => $request->pd_name,
                'pd_catid'       => $request->pd_catid ?? 0,
                'pd_catsubid'    => $request->pd_catsubid ?? 0,
                'pd_catsubid2'   => 0,
                'pd_shopid'      => 0,
                'pd_description'       => $request->pd_description ?? '',
                'pd_specifications'    => $request->pd_specifications ?? null,
                'pd_material'          => $request->pd_material ?? null,
                'pd_warranty'          => $request->pd_warranty ?? null,
                'pd_supplier'    => 0,
                'pd_price_srp'   => $request->pd_price_srp ?? 0,
                'pd_price_dp'    => $request->pd_price_dp ?? 0,
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
                'pd_user'        => 0,
                'pd_usertype'    => 0,
                'pd_date'        => $now,
                'pd_last_update' => $now,
                'pd_status'      => $request->pd_status ?? 0,
                'pd_image'       => $images[0] ?? ($request->pd_image ?? null),
            ]);

            foreach ($images as $url) {
                ProductPhoto::create([
                    'pp_pdid'     => $product->pd_id,
                    'pp_filename' => $url,
                    'pp_varone'   => null,
                    'pp_date'     => $now,
                ]);
            }

            $this->syncVariants($product, $request->input('pd_variants', []), $now);

            return $product;
        });

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => [
                'id'       => $product->pd_id,
                'name'     => $product->pd_name,
                'priceSrp' => (float) $product->pd_price_srp,
                'prodpv'   => (float) ($product->pd_prodpv ?? 0),
                'status'   => (int) $product->pd_status,
            ],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'pd_name'        => 'sometimes|required|string|max:255',
            'pd_catid'       => 'sometimes|required|integer',
            'pd_catsubid'    => 'nullable|integer',
            'pd_price_srp'   => 'sometimes|required|numeric|min:0',
            'pd_price_dp'    => 'nullable|numeric|min:0',
            'pd_prodpv'      => 'nullable|numeric|min:0',
            'pd_qty'         => 'nullable|numeric|min:0',
            'pd_weight'      => 'nullable|integer|min:0',
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
            'pd_status'      => 'nullable|integer|in:0,1',
            'pd_image'       => 'nullable|string|max:500',
            'pd_images'      => 'nullable|array',
            'pd_images.*'    => 'nullable|string|max:1000',
            'pd_variants'                => 'nullable|array',
            'pd_variants.*.pv_sku'       => 'nullable|string|max:80',
            'pd_variants.*.pv_color'     => 'nullable|string|max:80',
            'pd_variants.*.pv_color_hex' => 'nullable|string|max:16',
            'pd_variants.*.pv_size'      => 'nullable|string|max:40',
            'pd_variants.*.pv_price_srp' => 'nullable|numeric|min:0',
            'pd_variants.*.pv_price_dp'  => 'nullable|numeric|min:0',
            'pd_variants.*.pv_qty'       => 'nullable|numeric|min:0',
            'pd_variants.*.pv_status'    => 'nullable|integer|in:0,1',
            'pd_variants.*.pv_images'    => 'nullable|array',
            'pd_variants.*.pv_images.*'  => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $fields = [
            'pd_name', 'pd_catid', 'pd_catsubid', 'pd_description', 'pd_specifications',
            'pd_material', 'pd_warranty',
            'pd_price_srp', 'pd_price_dp', 'pd_prodpv', 'pd_qty', 'pd_weight',
            'pd_psweight', 'pd_pswidth', 'pd_pslenght', 'pd_psheight',
            'pd_parent_sku', 'pd_type', 'pd_status', 'pd_image',
        ];

        DB::transaction(function () use ($request, $product, $fields) {
            foreach ($fields as $field) {
                if ($request->has($field)) {
                    $product->$field = $request->$field;
                }
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

            if ($request->has('pd_images')) {
                $images = collect($request->input('pd_images', []))
                    ->filter(fn ($url) => is_string($url) && trim($url) !== '')
                    ->values()
                    ->all();

                ProductPhoto::query()->where('pp_pdid', $product->pd_id)->delete();

                foreach ($images as $url) {
                    ProductPhoto::create([
                        'pp_pdid'     => $product->pd_id,
                        'pp_filename' => $url,
                        'pp_varone'   => null,
                        'pp_date'     => now(),
                    ]);
                }

                $product->pd_image = $images[0] ?? null;
            } elseif ($request->has('pd_image')) {
                $product->pd_image = $request->pd_image;
            }

            if ($request->has('pd_variants')) {
                $this->syncVariants($product, $request->input('pd_variants', []), now());
            }

            $product->pd_last_update = now();
            $product->save();
        });

        return response()->json(['message' => 'Product updated successfully.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $product = Product::find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted successfully.']);
    }
}
