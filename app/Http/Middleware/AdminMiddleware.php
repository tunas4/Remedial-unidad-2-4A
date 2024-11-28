<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token)
        {
            return response()->json([
                'message' => 'No se proporciono token'
            ], 400);
        }

        if (!PersonalAccessToken::findToken($token))
        {
            return response()->json([
                'message' => 'Token invalido'
            ], 400);
        }

        $user = PersonalAccessToken::findToken($token)->tokenable;

        if (!$user)
        {
            return response()->json([
                'message' => 'Token invalido'
            ], 400);
        }

        if ($user->role != 'admin')
        {
            return response()->json([
                'message' => 'Acceso denegado'
            ], 403);
        }

        return $next($request);
    }
}
