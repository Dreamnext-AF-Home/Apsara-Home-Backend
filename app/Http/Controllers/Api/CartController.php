<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SystemSetting;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    public function addToCart(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_id' => 'required|integer',
                'variant_id' => 'nullable|integer',
                'quantity' => 'required|integer|min:1',
                'selected_color' => 'nullable|string|max:100',
                'selected_size' => 'nullable|string|max:100',
                'selected_type' => 'nullable|string|max:100',
            ]);

            $customer = $request->user();

            if (!$customer instanceof Customer) {
                Log::error('Cart: User is not a Customer instance', ['user_type' => get_class($request->user())]);
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            Log::info('Cart: Adding item', [
                'customer_id' => $customer->c_userid,
                'product_id' => $validated['product_id']
            ]);

            // Get product details to calculate price
            $product = DB::table('tbl_product')->where('pd_id', $validated['product_id'])->first();
            
            if (!$product) {
                Log::error('Cart: Product not found', ['product_id' => $validated['product_id']]);
                return response()->json(['message' => 'Product not found'], 404);
            }

            $manualCheckoutModeEnabled = (bool) (SystemSetting::query()->value('enable_manual_checkout_mode') ?? false);
            if ($manualCheckoutModeEnabled && ! (bool) ($product->pd_manual_checkout_enabled ?? false)) {
                return response()->json([
                    'message' => 'This product is not available for checkout at the moment.',
                ], 422);
            }

            Log::info('Cart: Product data', [
                'product_id' => $validated['product_id'],
                'product' => $product,
                'price_srp' => $product->pd_price_srp,
                'price_dp' => $product->pd_price_dp,    
                'price_member' => $product->pd_price_member,
            ]);

            $variant = null;
            if (!empty($validated['variant_id'])) {
                $variant = ProductVariant::query()
                    ->where('pv_id', $validated['variant_id'])
                    ->where('pv_pdid', $validated['product_id'])
                    ->where('pv_status', 1)
                    ->first();

                if (!$variant) {
                    return response()->json(['message' => 'Selected variant is not available'], 422);
                }
            }

            // Determine price (use first non-null and non-zero price)
            $unitPrice = 0;
            if ($variant && $variant->pv_price_member && $variant->pv_price_member > 0) {
                $unitPrice = $variant->pv_price_member;
            } elseif ($variant && $variant->pv_price_dp && $variant->pv_price_dp > 0) {
                $unitPrice = $variant->pv_price_dp;
            } elseif ($variant && $variant->pv_price_srp && $variant->pv_price_srp > 0) {
                $unitPrice = $variant->pv_price_srp;
            } elseif ($product->pd_price_member && $product->pd_price_member > 0) {
                $unitPrice = $product->pd_price_member;
            } elseif ($product->pd_price_dp && $product->pd_price_dp > 0) {
                $unitPrice = $product->pd_price_dp;
            } elseif ($product->pd_price_srp && $product->pd_price_srp > 0) {
                $unitPrice = $product->pd_price_srp;
            }
            
            if ($unitPrice <= 0) {
                Log::error('Cart: Invalid product price', [
                    'product_id' => $validated['product_id'],
                    'prices' => [
                        'member' => $product->pd_price_member,
                        'dp' => $product->pd_price_dp,
                        'srp' => $product->pd_price_srp
                    ]
                ]);
                return response()->json(['message' => 'Product price not available'], 400);
            }
            
            $totalPrice = $unitPrice * $validated['quantity'];

            // Check if item already exists in cart
            $existingCartItem = DB::table('tbl_add_to_cart')
                ->where('crt_customer_id', $customer->c_userid)
                ->where('crt_product_id', $validated['product_id'])
                ->where('crt_variant_id', $validated['variant_id'] ?? null)
                ->where('crt_selected_color', $validated['selected_color'] ?? null)
                ->where('crt_selected_size', $validated['selected_size'] ?? null)
                ->where('crt_selected_type', $validated['selected_type'] ?? null)
                ->where('crt_status', 'active')
                ->first();

            if ($existingCartItem) {
                // Update quantity if item exists
                $newQuantity = $existingCartItem->crt_quantity + $validated['quantity'];
                $newTotalPrice = $unitPrice * $newQuantity;

                DB::table('tbl_add_to_cart')
                    ->where('crt_id', $existingCartItem->crt_id)
                    ->update([
                        'crt_quantity' => $newQuantity,
                        'crt_total_price' => $newTotalPrice,
                        'crt_updated_at' => now(),
                    ]);

                $cartItem = DB::table('tbl_add_to_cart')->where('crt_id', $existingCartItem->crt_id)->first();
            } else {
                // Add new item to cart
                $cartId = DB::table('tbl_add_to_cart')->insertGetId([
                    'crt_customer_id' => $customer->c_userid,
                    'crt_product_id' => $validated['product_id'],
                    'crt_variant_id' => $validated['variant_id'] ?? null,
                    'crt_quantity' => $validated['quantity'],
                    'crt_selected_color' => $validated['selected_color'] ?? null,
                    'crt_selected_size' => $validated['selected_size'] ?? null,
                    'crt_selected_type' => $validated['selected_type'] ?? null,
                    'crt_unit_price' => $unitPrice,
                    'crt_total_price' => $totalPrice,
                    'crt_status' => 'active',
                    'crt_created_at' => now(),
                    'crt_updated_at' => now(),
                ], 'crt_id');

                $cartItem = DB::table('tbl_add_to_cart')->where('crt_id', $cartId)->first();
            }

            return response()->json([
                'message' => 'Item added to cart successfully',
                'cart_item' => $cartItem,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Cart: Error adding item', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Error adding item to cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getCart(Request $request)
    {
        $customer = $request->user();

        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $cartItems = DB::table('tbl_add_to_cart')
            ->leftJoin('tbl_product', 'tbl_add_to_cart.crt_product_id', '=', 'tbl_product.pd_id')
            ->leftJoin('tbl_product_brand', 'tbl_product.pd_brand_type', '=', 'tbl_product_brand.pb_id')
            ->where('tbl_add_to_cart.crt_customer_id', $customer->c_userid)
            ->where('tbl_add_to_cart.crt_status', 'active')
            ->select(
                'tbl_add_to_cart.*',
                'tbl_product.pd_name as product_name',
                'tbl_product.pd_image as product_image',
                'tbl_product.pd_price_srp as product_price_srp',
                'tbl_product.pd_price_dp as product_price_dp',
                'tbl_product.pd_price_member as product_price_member',
                'tbl_product.pd_prodpv as product_prodpv',
                'tbl_product_brand.pb_name as brand_name'
            )
            ->get();

        $totalAmount = $cartItems->sum('crt_total_price');
        $totalItems = $cartItems->sum('crt_quantity');

        return response()->json([
            'cart_items' => $cartItems,
            'total_amount' => $totalAmount,
            'total_items' => $totalItems,
        ]);
    }

    public function updateCartItem(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $customer = $request->user();

        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $cartItem = DB::table('tbl_add_to_cart')
            ->where('crt_id', $id)
            ->where('crt_customer_id', $customer->c_userid)
            ->where('crt_status', 'active')
            ->first();

        if (!$cartItem) {
            return response()->json(['message' => 'Cart item not found'], 404);
        }

        $newTotalPrice = $cartItem->crt_unit_price * $validated['quantity'];

        DB::table('tbl_add_to_cart')
            ->where('crt_id', $id)
            ->update([
                'crt_quantity' => $validated['quantity'],
                'crt_total_price' => $newTotalPrice,
                'crt_updated_at' => now(),
            ]);

        $updatedItem = DB::table('tbl_add_to_cart')->where('crt_id', $id)->first();

        return response()->json([
            'message' => 'Cart item updated successfully',
            'cart_item' => $updatedItem,
        ]);
    }

    public function removeCartItem(Request $request, $id)
    {
        $customer = $request->user();

        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $cartItem = DB::table('tbl_add_to_cart')
            ->where('crt_id', $id)
            ->where('crt_customer_id', $customer->c_userid)
            ->where('crt_status', 'active')
            ->first();

        if (!$cartItem) {
            return response()->json(['message' => 'Cart item not found'], 404);
        }

        DB::table('tbl_add_to_cart')
            ->where('crt_id', $id)
            ->update([
                'crt_status' => 'completed',
                'crt_updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Item removed from cart successfully',
        ]);
    }

    public function clearCart(Request $request)
    {
        $customer = $request->user();

        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        DB::table('tbl_add_to_cart')
            ->where('crt_customer_id', $customer->c_userid)
            ->where('crt_status', 'active')
            ->update([
                'crt_status' => 'completed',
                'crt_updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Cart cleared successfully',
        ]);
    }
}
