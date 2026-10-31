<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PortalWriteLock
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) return $next($request);
        // No expiry: release in finally, including failed requests. File locks are
        // released by the OS if a worker dies; long Shopify imports stay protected.
        $handle = fopen(storage_path('framework/portal-write.lock'), 'c');
        abort_if($handle === false, 503, 'Portal write lock unavailable.');
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            abort(409, 'Another portal update is running. Wait for it to finish and try again.');
        }
        try { return $next($request); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
