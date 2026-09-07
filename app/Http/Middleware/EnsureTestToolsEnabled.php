<?php

namespace App\Http\Middleware;

use App\Services\DeploymentMode;
use Closure;

class EnsureTestToolsEnabled
{
    public function handle($request, Closure $next)
    {
        abort_unless(DeploymentMode::testTools(), 404);

        return $next($request);
    }
}
