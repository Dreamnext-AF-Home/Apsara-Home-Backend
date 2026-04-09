<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Services\WishlistService;

class AddToWishlistAction
{
    public function __construct(
        private readonly WishlistService $wishlistService,
    ) {
    }

    public function execute(int $customerId, ?int $productId = null, ?string $productName = null): void
    {
        $this->wishlistService->addForCustomer($customerId, $productId, $productName);
    }
}
