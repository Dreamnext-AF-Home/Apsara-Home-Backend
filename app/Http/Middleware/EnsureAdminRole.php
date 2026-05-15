<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        \Log::info('EnsureAdminRole - User check', ['user' => $user ? get_class($user) : 'null', 'path' => $request->path()]);

        if (! $user) {
            \Log::error('EnsureAdminRole - No user');
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Check by class name instead of instanceof due to Sanctum deserialization
        $userClass = get_class($user);
        \Log::info('EnsureAdminRole - User class', ['class' => $userClass, 'is_admin' => $userClass === Admin::class]);

        if ($userClass !== Admin::class) {
            \Log::error('EnsureAdminRole - Not Admin', ['class' => $userClass]);
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! empty($roles) && ! in_array(AdminAccess::roleFromLevel((int) $user->user_level_id), $roles, true)) {
            return response()->json(['message' => 'Forbidden: insufficient admin privileges.'], 403);
        }

        $requiredPermission = AdminAccess::permissionForPath($request->path());
        if (
            $requiredPermission !== null
            && (int) $user->user_level_id === 2
            && ! AdminAccess::hasPermission($user, $requiredPermission)
        ) {
            return response()->json(['message' => 'Forbidden: this admin account does not have access to this section.'], 403);
        }

        return $next($request);
    }
}
