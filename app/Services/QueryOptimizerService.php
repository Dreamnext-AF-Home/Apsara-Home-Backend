<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class QueryOptimizerService
{
    /**
     * Optimized customer lookup with caching
     */
    public static function getCustomerByUsername(string $username): ?\App\Models\Customer
    {
        return Cache::remember(
            "customer_username_" . strtolower($username),
            CacheService::MEDIUM_TERM,
            function () use ($username) {
                return \App\Models\Customer::query()
                    ->whereRaw('LOWER(c_username) = ?', [strtolower($username)])
                    ->where('c_lockstatus', 0)
                    ->first();
            }
        );
    }

    /**
     * Optimized customer lookup by email with caching
     */
    public static function getCustomerByEmail(string $email): ?\App\Models\Customer
    {
        return Cache::remember(
            "customer_email_" . strtolower($email),
            CacheService::MEDIUM_TERM,
            function () use ($email) {
                return \App\Models\Customer::query()
                    ->whereRaw('LOWER(c_email) = ?', [strtolower($email)])
                    ->first();
            }
        );
    }

    /**
     * Optimized product lookup with caching
     */
    public static function getProduct(int $productId): ?\App\Models\Product
    {
        return Cache::remember(
            "product_{$productId}",
            CacheService::LONG_TERM,
            function () use ($productId) {
                return \App\Models\Product::find($productId);
            }
        );
    }

    /**
     * Optimized category lookup with caching
     */
    public static function getCategories(): array
    {
        return Cache::remember(
            'categories_all',
            CacheService::VERY_LONG_TERM,
            function () {
                return DB::table('tbl_category')
                    ->where('cat_status', 1)
                    ->orderBy('cat_name')
                    ->get()
                    ->toArray();
            }
        );
    }

    /**
     * Optimized brand lookup with caching
     */
    public static function getBrands(): array
    {
        return Cache::remember(
            'brands_all',
            CacheService::VERY_LONG_TERM,
            function () {
                return DB::table('tbl_product_brand')
                    ->where('pb_status', 1)
                    ->orderBy('pb_name')
                    ->get()
                    ->toArray();
            }
        );
    }

    /**
     * Optimized room types with caching
     */
    public static function getRoomTypes(): array
    {
        return Cache::remember(
            'room_types_all',
            CacheService::VERY_LONG_TERM,
            function () {
                return DB::table('tbl_room_type')
                    ->where('rt_status', 1)
                    ->orderBy('rt_name')
                    ->get()
                    ->toArray();
            }
        );
    }

    /**
     * Optimized supplier lookup with caching
     */
    public static function getSuppliers(): array
    {
        return Cache::remember(
            'suppliers_all',
            CacheService::VERY_LONG_TERM,
            function () {
                return DB::table('tbl_supplier')
                    ->where('su_status', 1)
                    ->orderBy('su_name')
                    ->get()
                    ->toArray();
            }
        );
    }

    /**
     * Optimized system settings with caching
     */
    public static function getSystemSetting(string $key): mixed
    {
        return Cache::remember(
            "system_setting_{$key}",
            CacheService::VERY_LONG_TERM,
            function () use ($key) {
                return DB::table('tbl_system_settings')
                    ->value($key);
            }
        );
    }

    /**
     * Optimized member tier lookup with caching
     */
    public static function getMemberTiers(): array
    {
        return Cache::remember(
            'member_tiers_all',
            CacheService::VERY_LONG_TERM,
            function () {
                return DB::table('tbl_member_tier')
                    ->orderBy('tier_min_points')
                    ->get()
                    ->toArray();
            }
        );
    }

    /**
     * Optimized cart items count with caching
     */
    public static function getCartItemsCount(int $customerId): int
    {
        return Cache::remember(
            "cart_items_count_{$customerId}",
            CacheService::SHORT_TERM,
            function () use ($customerId) {
                return DB::table('tbl_add_to_cart')
                    ->where('crt_customer_id', $customerId)
                    ->where('crt_status', 'active')
                    ->sum('crt_quantity');
            }
        );
    }

    /**
     * Optimized wishlist items count with caching
     */
    public static function getWishlistItemsCount(int $customerId): int
    {
        return Cache::remember(
            "wishlist_items_count_{$customerId}",
            CacheService::SHORT_TERM,
            function () use ($customerId) {
                return DB::table('tbl_customer_wishlist')
                    ->where('cw_customer_id', $customerId)
                    ->count();
            }
        );
    }

    /**
     * Optimized notification count with caching
     */
    public static function getUnreadNotificationCount(int $customerId): int
    {
        return Cache::remember(
            "unread_notifications_{$customerId}",
            CacheService::SHORT_TERM,
            function () use ($customerId) {
                return DB::table('tbl_customer_notifications')
                    ->where('cn_customer_id', $customerId)
                    ->where('cn_read', 0)
                    ->count();
            }
        );
    }

    /**
     * Optimized product variants with caching
     */
    public static function getProductVariants(int $productId): array
    {
        return Cache::remember(
            "product_variants_{$productId}",
            CacheService::MEDIUM_TERM,
            function () use ($productId) {
                return DB::table('tbl_product_variant')
                    ->where('pv_pdid', $productId)
                    ->where('pv_status', 1)
                    ->orderBy('pv_name')
                    ->get()
                    ->toArray();
            }
        );
    }

    /**
     * Optimized product photos with caching
     */
    public static function getProductPhotos(int $productId): array
    {
        return Cache::remember(
            "product_photos_{$productId}",
            CacheService::MEDIUM_TERM,
            function () use ($productId) {
                return DB::table('tbl_product_photo')
                    ->where('pp_pdid', $productId)
                    ->orderBy('pp_sort')
                    ->get()
                    ->toArray();
            }
        );
    }

    /**
     * Batch invalidate caches for a customer
     */
    public static function invalidateCustomerCaches(int $customerId): void
    {
        CacheService::invalidateCustomerCaches($customerId);
        
        // Additional specific cache invalidations
        Cache::forget("cart_items_count_{$customerId}");
        Cache::forget("wishlist_items_count_{$customerId}");
        Cache::forget("unread_notifications_{$customerId}");
    }

    /**
     * Batch invalidate caches for a product
     */
    public static function invalidateProductCaches(int $productId): void
    {
        CacheService::invalidateProductCaches($productId);
        
        // Additional specific cache invalidations
        Cache::forget("product_{$productId}");
        Cache::forget("product_variants_{$productId}");
        Cache::forget("product_photos_{$productId}");
    }
}
