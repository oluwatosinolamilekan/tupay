<?php

namespace App\Http\Middleware;

use App\Actions\VerifyTwoFactorAction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $sessionToken = $request->header('X-Two-Factor-Session');

        if (
            $user === null ||
            ! is_string($sessionToken) ||
            ! Cache::has(VerifyTwoFactorAction::verifiedCacheKey($user, $sessionToken))
        ) {
            return response()->json(['message' => 'Two-factor verification required.'], 403);
        }

        return $next($request);
    }
}
