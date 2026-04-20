<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminTokenValidation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // If 401 response, invalidate the current token
        if ($response->getStatusCode() === 401) {
            $admin = $request->user();
            if ($admin) {
                $admin->currentAccessToken()?->delete();
            }
        }

        return $response;
    }
}
