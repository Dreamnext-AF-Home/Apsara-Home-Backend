<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheService
{
    /**
     * Cache duration constants (in seconds)
     */
    const SHORT_TERM = 180;   // 3 minutes
    const MEDIUM_TERM = 300;  // 5 minutes
    const LONG_TERM = 600;    // 10 minutes
    const VERY_LONG_TERM = 1800; // 30 minutes

    /**
     * Remember data with automatic cache key generation
     */
    public static function remember(string $keyPrefix, array $keyParams, int $duration, callable $callback)
    {
        $key = $keyPrefix . '_' . md5(serialize($keyParams));
        return Cache::remember($key, $duration, $callback);
    }

    /**
     * Forget cache keys matching a pattern
     */
    public static function forgetPattern(string $pattern): void
    {
        // This is a simplified implementation
        // In production, you might want to use Redis tags or a more sophisticated approach
        $cacheKey = str_replace('*', '', $pattern);
        Cache::forget($cacheKey);
    }

    /**
     * Invalidate customer-specific caches
     */
    public static function invalidateCustomerCaches(int $customerId): void
    {
        $patterns = [
            "search_history_{$customerId}_*",
            "user_preferred_categories_{$customerId}_*",
            "cart_{$customerId}_*",
            "wishlist_{$customerId}_*",
        ];

        foreach ($patterns as $pattern) {
            self::forgetPattern($pattern);
        }
    }

    /**
     * Invalidate product-related caches
     */
    public static function invalidateProductCaches(?int $productId = null): void
    {
        $patterns = [
            "products_category_*",
            "popular_products_*",
            "variant_counts_*",
            "available_categories_*",
            "available_brands_*",
        ];

        foreach ($patterns as $pattern) {
            self::forgetPattern($pattern);
        }

        if ($productId) {
            Cache::forget("product_{$productId}");
        }
    }

    /**
     * Invalidate search-related caches
     */
    public static function invalidateSearchCaches(): void
    {
        $patterns = [
            "available_categories_*",
            "available_brands_*",
            "live_search_*",
            "full_search_*",
        ];

        foreach ($patterns as $pattern) {
            self::forgetPattern($pattern);
        }
    }

    /**
     * Invalidate cart-related caches
     */
    public static function invalidateCartCaches(int $customerId): void
    {
        Cache::forget("cart_total_{$customerId}");
        Cache::forget("cart_items_{$customerId}");
    }

    /**
     * Invalidate wishlist-related caches
     */
    public static function invalidateWishlistCaches(int $customerId): void
    {
        Cache::forget("wishlist_items_{$customerId}");
        Cache::forget("wishlist_count_{$customerId}");
    }

    /**
     * Get cached data or return null
     */
    public static function get(string $key): mixed
    {
        return Cache::get($key);
    }

    /**
     * Set cache data with expiration
     */
    public static function put(string $key, mixed $value, int $duration): void
    {
        Cache::put($key, $value, $duration);
    }

    /**
     * Check if cache key exists
     */
    public static function has(string $key): bool
    {
        return Cache::has($key);
    }
}
