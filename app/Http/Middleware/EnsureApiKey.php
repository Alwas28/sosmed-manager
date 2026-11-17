<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiKey
{
    /**
     * Validasi API key statis dari .env pada setiap request /api/*.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('api.key');
        $header = config('api.header', 'X-API-Key');

        if (! is_string($expected) || $expected === '') {
            return response()->json([
                'success' => false,
                'message' => 'API key belum dikonfigurasi di server.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $provided = $request->header($header);

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'API key tidak valid.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
