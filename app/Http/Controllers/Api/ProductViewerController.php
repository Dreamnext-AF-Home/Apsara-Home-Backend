<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProductViewerController extends Controller
{
    private const VIEWER_TTL_SECONDS = 90;

    public function heartbeat(Request $request, int $id): JsonResponse
    {
        $productExists = Product::query()->where('pd_id', $id)->exists();
        if (! $productExists) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $viewerId = trim((string) $request->input('viewer_id'));
        if ($viewerId === '') {
            $viewerId = (string) Str::uuid();
        }

        $key = $this->presenceKey($id);
        $now = now()->getTimestamp();
        $cutoff = $now - self::VIEWER_TTL_SECONDS;

        $raw = Cache::get($key, []);
        $presence = is_array($raw) ? $raw : [];
        $active = [];

        foreach ($presence as $existingViewerId => $seenAt) {
            $lastSeen = (int) $seenAt;
            if ($lastSeen >= $cutoff) {
                $active[(string) $existingViewerId] = $lastSeen;
            }
        }

        $active[$viewerId] = $now;

        Cache::put($key, $active, now()->addSeconds(self::VIEWER_TTL_SECONDS * 2));

        return response()->json([
            'product_id' => $id,
            'viewer_id' => $viewerId,
            'active_viewers' => count($active),
            'ttl_seconds' => self::VIEWER_TTL_SECONDS,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    private function presenceKey(int $productId): string
    {
        return "product:{$productId}:viewer_presence";
    }
}

