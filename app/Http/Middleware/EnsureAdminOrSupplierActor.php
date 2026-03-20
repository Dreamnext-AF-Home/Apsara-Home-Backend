<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\SupplierUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminOrSupplierActor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user instanceof Admin && ! $user instanceof SupplierUser) {
            return response()->json(['message' => 'Forbidden: admin or supplier access required.'], 403);
        }

        return $next($request);
    }
}
