<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Follower;
use Illuminate\Http\Request;

class FollowerController extends Controller
{
    public function follow(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'brand_id' => 'required|integer',
        ]);

        $existing = Follower::where('user_id', $user->c_userid)
            ->where('brand_id', $validated['brand_id'])
            ->first();

        if ($existing) {
            if (!$existing->is_active) {
                $existing->update(['is_active' => true, 'updated_at' => now()]);
            }
            return response()->json(['message' => 'Already following'], 200);
        }

        Follower::create([
            'user_id' => $user->c_userid,
            'brand_id' => $validated['brand_id'],
            'is_active' => true,
            'followed_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully followed'], 201);
    }

    public function unfollow(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'brand_id' => 'required|integer',
        ]);

        $follower = Follower::where('user_id', $user->c_userid)
            ->where('brand_id', $validated['brand_id'])
            ->first();

        if (!$follower) {
            return response()->json(['message' => 'Not following this brand'], 404);
        }

        $follower->update(['is_active' => false]);

        return response()->json(['message' => 'Successfully unfollowed'], 200);
    }

    public function getFollowing(Request $request)
    {
        $user = $request->user();

        $following = Follower::where('user_id', $user->c_userid)
            ->where('is_active', true)
            ->pluck('brand_id')
            ->toArray();

        return response()->json([
            'user_id' => $user->c_userid,
            'following' => $following,
        ], 200);
    }

    public function isFollowing(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'brand_id' => 'required|integer',
        ]);

        $isFollowing = Follower::where('user_id', $user->c_userid)
            ->where('brand_id', $validated['brand_id'])
            ->where('is_active', true)
            ->exists();

        return response()->json([
            'is_following' => $isFollowing,
        ], 200);
    }
}
