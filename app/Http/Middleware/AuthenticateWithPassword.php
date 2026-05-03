<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWithPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = $request->getUser() ?: $request->header('X-User-Email');
        $password = $request->getPassword() ?: $request->header('X-User-Password');

        if (! is_string($email) || ! is_string($password)) {
            return response()->json(['message' => 'Password authentication required.'], 401);
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        Auth::setUser($user);

        return $next($request);
    }
}
