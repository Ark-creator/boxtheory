<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApproval
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
   public function handle(Request $request, Closure $next)
{
    if (auth()->check() && !auth()->user()->is_approved) {
        // Only allow them to see the 'pending' page or logout
        if (!$request->routeIs('pending.status') && !$request->routeIs('logout')) {
            return redirect()->route('pending.status');
        }
    }

    return $next($request);
}

}
