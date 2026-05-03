<?php

namespace App\Actions;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Cache;

class VerifyTwoFactorAction
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function execute(User $user, string $code, string $sessionToken): bool
    {
        if (! Cache::has(self::pendingCacheKey($user, $sessionToken))) {
            return false;
        }

        if (! $this->twoFactor->verify($user, $code)) {
            return false;
        }

        $user->forceFill([
            'two_factor_confirmed_at' => $user->two_factor_confirmed_at ?? now(),
        ])->save();

        Cache::forget(self::pendingCacheKey($user, $sessionToken));
        Cache::put(self::verifiedCacheKey($user, $sessionToken), true, now()->addMinutes(10));

        return true;
    }

    public static function pendingCacheKey(User $user, string $sessionToken): string
    {
        return self::cacheKey('pending', $user, $sessionToken);
    }

    public static function verifiedCacheKey(User $user, string $sessionToken): string
    {
        return self::cacheKey('verified', $user, $sessionToken);
    }

    private static function cacheKey(string $state, User $user, string $sessionToken): string
    {
        return "two-factor:{$state}:user:{$user->id}:".hash('sha256', $sessionToken);
    }
}
