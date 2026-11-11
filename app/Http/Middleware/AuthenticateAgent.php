<?php

namespace App\Http\Middleware;

use App\Models\ServerCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgent
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || strlen($token) !== 64) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $credential = ServerCredential::query()
            ->with('server')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $credential || ! $credential->server) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($credential->server->status === 'disabled') {
            return response()->json(['message' => 'This server has been disabled.'], 403);
        }

        $request->attributes->set('agent_server', $credential->server);

        if (! $credential->last_used_at || $credential->last_used_at->lt(now()->subMinutes(5))) {
            $credential->forceFill(['last_used_at' => now()])->save();
        }

        return $next($request);
    }
}
