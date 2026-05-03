<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class TwoFactorService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function ensureSecret(User $user): string
    {
        if ($user->two_factor_secret !== null) {
            return $user->two_factor_secret;
        }

        $secret = $this->base32Secret();
        $user->forceFill([
            'two_factor_secret' => $secret,
        ])->save();

        return $secret;
    }

    public function verify(User $user, string $code, int $window = 1): bool
    {
        if ($user->two_factor_secret === null || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timeSlice = (int) floor(time() / 30);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->totp($user->two_factor_secret, $timeSlice + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function totp(string $secret, ?int $timeSlice = null): string
    {
        $counter = $timeSlice ?? (int) floor(time() / 30);
        $binaryCounter = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0xF;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1_000_000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    public function provisioningUri(User $user, string $issuer = 'Tupay'): string
    {
        $secret = $this->ensureSecret($user);
        $label = rawurlencode($issuer.':'.$user->email);

        return "otpauth://totp/{$label}?secret={$secret}&issuer=".rawurlencode($issuer).'&digits=6&period=30';
    }

    private function base32Secret(int $length = 32): string
    {
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $secret;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(Str::replace('=', '', $secret));
        $buffer = 0;
        $bitsLeft = 0;
        $decoded = '';

        foreach (str_split($secret) as $character) {
            $value = strpos(self::ALPHABET, $character);

            if ($value === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $decoded .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $decoded;
    }
}
