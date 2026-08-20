<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyScrapeApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-KEY');
        $expected = env('SCRAPE_API_KEY');

        if (! $expected || $apiKey !== $expected) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Invalid API key.',
            ], 401);
        }

        return $next($request);
    }
}
