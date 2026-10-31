<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && !$request->user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson()) return response()->json(['message' => 'Your account is inactive. Contact an admin.'], 403);
            return redirect()->route('login')->withErrors(['email' => 'Your account is inactive. Contact an admin.']);
        }
        return $next($request);
    }
}
