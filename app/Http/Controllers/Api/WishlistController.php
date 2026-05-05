<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Events\WishlistAdded;
use App\Services\QueryOptimizerService;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Wishlist;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class WishlistController extends Controller
{
    public function index()
    {
        $wishlistItems = Wishlist::query()
            ->with(['product' => function ($query) {
                $query->select([
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
                    'variants:pv_id,pv_pdid,pv_sku,pv_name,pv_color,pv_color_hex,pv_size,pv_style,pv_width,pv_dimension,pv_height,pv_price_srp,pv_price_dp,pv_price_member,pv_prodpv,pv_qty,pv_status,pv_date',
                    'variants.photos:pvp_id,pvp_pvid,pvp_filename,pvp_sort,pvp_date',
                ])
                ->whereIn('pd_status', [1, 2]); // Apply public visibility
            }])
            ->where('cw_customer_id', Auth::id())
            ->orderByDesc('cw_id')
            ->get()
            ->filter(function ($wishlistItem) {
                return $wishlistItem->product !== null;
            });

        // Pre-load sold counts for all products in single query (N+1 fix)
        $productIds = $wishlistItems->pluck('product.pd_id')->filter()->unique();
        $soldCounts = [];
        $avgRatings = [];
        
        if ($productIds->isNotEmpty()) {
            // Single query for all sold counts
            $soldCountsData = DB::table('tbl_checkout_history')
                ->whereIn('ch_product_id', $productIds)
                ->whereIn('ch_status', ['paid', 'completed', 'shipped'])
                ->selectRaw('ch_product_id, SUM(ch_quantity) as total_sold')
                ->groupBy('ch_product_id')
                ->pluck('total_sold', 'ch_product_id');
            
            $soldCounts = $soldCountsData->toArray();
            
            // Single query for all average ratings
            $ratingsData = DB::table('tbl_product_reviews')
                ->whereIn('pr_product_id', $productIds)
                ->selectRaw('pr_product_id, AVG(pr_rating) as avg_rating')
                ->groupBy('pr_product_id')
                ->pluck('avg_rating', 'pr_product_id');
            
            $avgRatings = $ratingsData->map(fn($rating) => (float) $rating)->toArray();
        }

        return response()->json([
            'data' => $wishlistItems->map(function ($wishlistItem) use ($soldCounts, $avgRatings) {
                $product = $wishlistItem->product;
                
                // Use pre-loaded data instead of individual queries
                $soldCount = $soldCounts[$product->pd_id] ?? 0;
                $avgRating = $avgRatings[$product->pd_id] ?? 0.0;
                
                $mappedProduct = $this->mapProduct($product, $soldCount, $avgRating);
                
                return [
                    'wishlist_id' => (int) $wishlistItem->cw_id,
                    'customer_id' => (int) $wishlistItem->cw_customer_id,
                    'product_id' => (int) $wishlistItem->cw_product_id,
                    'date_added' => $wishlistItem->cw_date ? (is_string($wishlistItem->cw_date) ? $wishlistItem->cw_date : $wishlistItem->cw_date->format('Y-m-d H:i:s')) : null,
                    'product' => $mappedProduct,
                ];
            }),
        ]);
    }

    private function mapProduct($p, int $soldCount = 0, float $avgRating = 0.0): array
    {
        $images = $p->photos
            ->map(fn ($photo) => (string) $photo->pp_filename)
            ->filter(fn ($url) => trim($url) !== '')
            ->values()
            ->all();

        $primaryImage = $images[0] ?? ($p->pd_image ?? null);
        $effectiveQty = $p->pd_qty ?? 0;

        return [
            'id'          => (int)   $p->pd_id,
            'soldCount'    => (int)   $soldCount,
            'avgRating'    => (float) $avgRating,
            'supplierId'  => (int)   ($p->pd_supplier ?? 0),
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
            'status'      => (int)   $p->pd_status,
            'sku'         => (string) ($p->pd_parent_sku ?? ''),
            'image'       => $primaryImage,
            'images'      => $images,
            'variants'    => $this->mapVariants($p),
            'createdAt'   => $p->pd_date ? $p->pd_date->format('Y-m-d') : null,
            'updatedAt'   => $p->pd_last_update ? $p->pd_last_update->format('Y-m-d') : null,
        ];
    }

    private function mapVariants($product): array
    {
        return $product->variants->map(function ($variant) {
            $images = $variant->photos
                ->map(fn ($photo) => (string) $photo->pvp_filename)
                ->filter(fn ($url) => trim($url) !== '')
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
                'qty'      => (int) ($variant->pv_qty ?? 0),
                'status'   => (int) ($variant->pv_status ?? 0),
                'images'   => $images,
                'createdAt'=> $variant->pv_date ? $variant->pv_date->format('Y-m-d') : null,
            ];
        })->all();
    }

    private function toNumber($value): float
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

    private function toOptionalNumber($value): ?float
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

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:tbl_product,pd_id'],
            'product_name' => ['nullable', 'string', 'max:255'],
        ]);

        $productId = $request->integer('product_id');

        if (!$productId && $request->filled('product_name')) {
            $name = trim((string) $request->string('product_name'));
            $productId = Product::query()
                ->where('pd_name', $name)
                ->value('pd_id');
        }

        if (!$productId) {
            throw ValidationException::withMessages([
                'product_id' => ['Unable to resolve product. Provide a valid product_id or product_name.'],
            ]);
        }

        $wishlistItem = Wishlist::firstOrCreate([
            'cw_customer_id' => Auth::id(),
            'cw_product_id' => (int) $productId,
        ], [
            'cw_date' => now(),
        ]);

        // Get product details for broadcasting
        $product = Product::find($productId);

        // Broadcast real-time event
        try {
            WishlistAdded::dispatch(Auth::id(), $product, $wishlistItem, 'added');
        } catch (\Exception $e) {
            Log::warning('Failed to broadcast wishlist addition event', [
                'customer_id' => Auth::id(),
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
        }

        // Invalidate customer-specific caches
        QueryOptimizerService::invalidateCustomerCaches(Auth::id());

        return response()->json(['message' => 'Added to wishlist']);
    }

    public function destroy(int $productId)
    {
        // Get product details before deletion for broadcasting
        $product = Product::find($productId);
        $wishlistItem = Wishlist::where('cw_customer_id', Auth::id())
            ->where('cw_product_id', $productId)
            ->first();

        Wishlist::where('cw_customer_id', Auth::id())
            ->where('cw_product_id', $productId)
            ->delete();

        // Broadcast real-time event
        try {
            WishlistAdded::dispatch(Auth::id(), $product, $wishlistItem, 'removed');
        } catch (\Exception $e) {
            Log::warning('Failed to broadcast wishlist removal event', [
                'customer_id' => Auth::id(),
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
        }

        // Invalidate customer-specific caches
        QueryOptimizerService::invalidateCustomerCaches(Auth::id());

        return response()->json(['message' => 'Removed from wishlist']);
    }
}
