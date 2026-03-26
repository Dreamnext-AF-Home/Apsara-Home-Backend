<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminActor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user instanceof Admin) {
            return response()->json(['message' => 'Forbidden: admin access required.'], 403);
        }

        if ($user->is_banned) {
            return response()->json([
                'message' => 'Your account has been suspended. Contact a Super Admin.',
                'reason' => 'banned',
            ], 401);
        }

        return $next($request);
    }
}
