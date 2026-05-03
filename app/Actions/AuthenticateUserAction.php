<?php

namespace App\Actions;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthenticateUserAction
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
    ) {}

    /**
     * @return array{email: string, two_factor_session_token: string, two_factor_setup_required: bool, two_factor_secret: string|null, provisioning_uri: string|null}|null
     */
    public function execute(string $email, string $password, string $deviceName = 'api'): ?array
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            return null;
        }

        $isNewSecret = $user->two_factor_secret === null;
        $secret = $this->twoFactor->ensureSecret($user);
        $sessionToken = Str::random(64);

        Cache::put(
            VerifyTwoFactorAction::pendingCacheKey($user, $sessionToken),
            true,
            now()->addMinutes(10),
        );

        return [
            'email' => $user->email,
            'two_factor_session_token' => $sessionToken,
            'two_factor_setup_required' => $isNewSecret,
            'two_factor_secret' => $isNewSecret ? $secret : null,
            'provisioning_uri' => $isNewSecret ? $this->twoFactor->provisioningUri($user) : null,
        ];
    }
}
