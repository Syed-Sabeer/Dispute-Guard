<?php
declare(strict_types=1);
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class EmbeddedCsrf
{
    public function handle(Request $request, Closure $next)
    {
        // Embedded requests use a verified short-lived bearer token, not ambient session cookies.
        // Require fetch + JSON for mutations so cross-origin HTML forms cannot trigger them.
        if (!$request->isMethodSafe()) {
            abort_unless($request->bearerToken() && $request->isJson() && $request->header('X-Requested-With') === 'XMLHttpRequest', 419);
            $origin = $request->header('Origin');
            $expected = parse_url(config('app.url'), PHP_URL_SCHEME).'://'.parse_url(config('app.url'), PHP_URL_HOST);
            if ($port = parse_url(config('app.url'), PHP_URL_PORT)) { $expected .= ':'.$port; }
            abort_if($origin && !hash_equals($expected, $origin), 419);
        }
        return $next($request);
    }
}
