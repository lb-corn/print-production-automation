<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ReadOnlyDemo
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->role === 'demo' && !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && !$request->routeIs('logout')) {
            abort(403, 'Demo accounts have view-only access.');
        }
        return $next($request);
    }
}
