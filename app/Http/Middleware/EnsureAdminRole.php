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

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user instanceof Admin) {
            return response()->json(['message' => 'Forbidden: admin access required.'], 403);
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
