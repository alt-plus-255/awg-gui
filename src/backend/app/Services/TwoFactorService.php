<?php

namespace App\Services;

use App\Models\User;
use App\Services\AmneziaWg\QrCodeService;
use Illuminate\Support\Facades\Crypt;

class TwoFactorService
{
    public function __construct(
        private QrCodeService $qr,
    ) {}

    public function isEnabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null
            && filled($user->two_factor_secret);
    }

    /**
     * @return array{secret: string, otpauth_uri: string, qr: string}
     */
    public function beginSetup(User $user): array
    {
        $secret = $this->generateSecret();
        $user->two_factor_secret = Crypt::encryptString($secret);
        $user->two_factor_confirmed_at = null;
        $user->save();

        $uri = $this->otpauthUri($user, $secret);
        $png = $this->qr->buildPng($uri);

        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr' => 'data:image/png;base64,'.base64_encode($png),
        ];
    }

    public function confirm(User $user, string $code): bool
    {
        $secret = $this->plainSecret($user);
        if ($secret === null) {
            return false;
        }

        if (! $this->verifyCode($secret, $code)) {
            return false;
        }

        $user->two_factor_confirmed_at = now();
        $user->save();

        return true;
    }

    public function verify(User $user, ?string $code): bool
    {
        if (! $this->isEnabled($user)) {
            return true;
        }

        $secret = $this->plainSecret($user);
        if ($secret === null || $code === null || $code === '') {
            return false;
        }

        return $this->verifyCode($secret, $code);
    }

    public function disable(User $user): void
    {
        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->save();
    }

    public function plainSecret(User $user): ?string
    {
        if (! filled($user->two_factor_secret)) {
            return null;
        }

        try {
            return Crypt::decryptString($user->two_factor_secret);
        } catch (\Throwable) {
            return null;
        }
    }

    public function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $normalized = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $normalized)) {
            return false;
        }

        $timeSlice = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->totp($secret, $timeSlice + $i), $normalized)) {
                return true;
            }
        }

        return false;
    }

    protected function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    protected function otpauthUri(User $user, string $secret): string
    {
        $issuer = rawurlencode('AWG-GUI');
        $label = rawurlencode('AWG-GUI:'.($user->username ?: $user->email));

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    protected function totp(string $secret, int $timeSlice): string
    {
        $key = $this->base32Decode($secret);
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    protected function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    protected function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $char) {
            $val = strpos($alphabet, $char);
            if ($val === false) {
                continue;
            }
            $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
