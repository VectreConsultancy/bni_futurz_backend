<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->is_active) {
            // Revoke current token (Logout)
            if ($user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Your account is inactive. Please contact your administrator.'
            ], 403);
        }

        return $next($request);
    }
}
