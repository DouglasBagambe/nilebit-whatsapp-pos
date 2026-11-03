<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectBusinessLink
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment(['local', 'testing'])) {
            $url = (string) config('app.url');
            abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https' && is_string(parse_url($url, PHP_URL_HOST)), 503, 'Secure linking is not configured.');
            abort_unless(hash_equals(mb_strtolower((string) parse_url($url, PHP_URL_HOST)), mb_strtolower($request->getHost())), 400, 'Unrecognized linking host.');
            abort_unless($request->isSecure(), 400, 'Open the secure HTTPS link from WhatsApp.');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
