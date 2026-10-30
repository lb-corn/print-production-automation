<?php
namespace App\Http\Middleware;
use Closure;
class FactoryKey {
    public function handle($request, Closure $next) {
        $expected = (string) config('services.factory.api_key');
        $received = (string) $request->bearerToken();
        abort_if(strlen($expected) < 32 || preg_match('/^(change|replace)/i', $expected), 503, 'Factory API is not securely configured.');
        abort_unless($received !== '' && hash_equals($expected, $received), 401);
        return $next($request);
    }
}
