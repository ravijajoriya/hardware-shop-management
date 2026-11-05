<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safety headers on everything Laravel answers, whichever host runs the app.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        // Browsers must not guess another file type, show these pages inside other sites, pass
        // these addresses on to other sites, or list anything in search results (bill links
        // carry customers' names and phone numbers).
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');

        // Once reached over HTTPS, browsers stay on HTTPS for a year.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        // Don't announce the PHP version.
        header_remove('X-Powered-By');

        return $response;
    }
}
