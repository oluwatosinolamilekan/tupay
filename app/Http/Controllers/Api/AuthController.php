<?php

namespace App\Http\Controllers\Api;

use App\Actions\AuthenticateUserAction;
use App\Actions\VerifyTwoFactorAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\TwoFactorVerifyRequest;
use App\Http\Resources\LoginResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthenticateUserAction $authenticate): JsonResponse
    {
        $issued = $authenticate->execute(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            deviceName: $request->string('device_name', 'api')->toString(),
        );

        if ($issued === null) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        return response()->json((new LoginResource($issued))->resolve($request));
    }

    public function verify(TwoFactorVerifyRequest $request, VerifyTwoFactorAction $verifyTwoFactor): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $verifyTwoFactor->execute(
            $user,
            $request->string('code')->toString(),
            $request->string('session_token')->toString(),
        )) {
            return response()->json(['message' => 'Invalid two-factor code.'], 422);
        }

        return response()->json([
            'message' => 'Two-factor verification accepted.',
            'expires_at' => now()->addMinutes(10)->toISOString(),
        ]);
    }
}
