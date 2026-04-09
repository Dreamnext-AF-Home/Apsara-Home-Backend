<?php

namespace App\Http\Controllers\Api;

use App\Domain\Customer\Actions\AddToWishlistAction;
use App\Domain\Customer\Services\WishlistService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WishlistController extends Controller
{
    public function __construct(
        private readonly WishlistService $wishlistService,
        private readonly AddToWishlistAction $addToWishlistAction,
    ) {
    }

    public function index()
    {
        return response()->json([
            'data' => $this->wishlistService->listForCustomer((int) Auth::id()),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:tbl_product,pd_id'],
            'product_name' => ['nullable', 'string', 'max:255'],
        ]);

        $this->addToWishlistAction->execute(
            (int) Auth::id(),
            $request->integer('product_id'),
            $request->filled('product_name') ? (string) $request->string('product_name') : null,
        );

        return response()->json(['message' => 'Added to wishlist']);
    }

    public function destroy(int $productId)
    {
        $this->wishlistService->removeForCustomer((int) Auth::id(), $productId);

        return response()->json(['message' => 'Removed from wishlist']);
    }
}
