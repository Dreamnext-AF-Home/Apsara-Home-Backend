<?php

namespace App\Domain\Customer\Services;

use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class WishlistService
{
    public function listForCustomer(int $customerId): Collection
    {
        return Wishlist::with('product')
            ->where('cw_customer_id', $customerId)
            ->orderByDesc('cw_id')
            ->get();
    }

    public function addForCustomer(int $customerId, ?int $productId = null, ?string $productName = null): void
    {
        $resolvedProductId = $this->resolveProductId($productId, $productName);

        Wishlist::firstOrCreate([
            'cw_customer_id' => $customerId,
            'cw_product_id' => $resolvedProductId,
        ], [
            'cw_date' => now(),
        ]);
    }

    public function removeForCustomer(int $customerId, int $productId): void
    {
        Wishlist::query()
            ->where('cw_customer_id', $customerId)
            ->where('cw_product_id', $productId)
            ->delete();
    }

    private function resolveProductId(?int $productId, ?string $productName): int
    {
        if ($productId) {
            return $productId;
        }

        $name = trim((string) $productName);
        if ($name !== '') {
            $resolvedId = Product::query()
                ->where('pd_name', $name)
                ->value('pd_id');

            if ($resolvedId) {
                return (int) $resolvedId;
            }
        }

        throw ValidationException::withMessages([
            'product_id' => ['Unable to resolve product. Provide a valid product_id or product_name.'],
        ]);
    }
}
