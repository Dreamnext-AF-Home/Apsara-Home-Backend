<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequestAbuseGuard
{
    private function blockedIps(): array
    {
        $raw = (string) env('SECURITY_BLOCKED_IPS', '');
        if ($raw === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function blockedUaPatterns(): array
    {
        $raw = (string) env('SECURITY_BLOCKED_UA_PATTERNS', '');
        if ($raw === '') return [];
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();
        if ($ip !== '' && in_array($ip, $this->blockedIps(), true)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $ua = mb_strtolower((string) $request->userAgent());
        if ($ua !== '') {
            foreach ($this->blockedUaPatterns() as $pattern) {
                if ($pattern !== '' && str_contains($ua, mb_strtolower($pattern))) {
                    return response()->json(['message' => 'Access denied.'], 403);
                }
            }
        }

        return $next($request);
    }
}

