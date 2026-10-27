<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('public.cache:30,60')   (browser seconds, shared-cache/CDN seconds)
 *
 * Adds Cache-Control to successful, anonymous GET responses of PUBLIC catalogue endpoints only, so a CDN/proxy in
 * front of the API (and the Next.js server data cache) can reuse them. Requests that carry an Authorization header
 * are never marked public, and error responses are never cached.
 */
final class PublicCacheHeaders
{
    public function handle(Request $request, Closure $next, string $browser = '30', string $shared = '60'): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->isSuccessful() && ! $request->headers->has('Authorization') && ! $response->headers->has('Set-Cookie')) {
            $response->headers->set(
                'Cache-Control',
                sprintf('public, max-age=%d, s-maxage=%d, stale-while-revalidate=300', (int) $browser, (int) $shared),
            );
            $response->headers->set('Vary', trim(($response->headers->get('Vary') ? $response->headers->get('Vary') . ', ' : '') . 'Origin', ', '));
        }

        return $response;
    }
}
