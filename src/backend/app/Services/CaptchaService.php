<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CaptchaService
{
    public const TTL_SECONDS = 300;

    public const LENGTH = 5;

    /**
     * @return array{token: string, image: string}
     */
    public function generate(): array
    {
        $answer = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $answer .= (string) random_int(0, 9);
        }

        $token = Str::random(40);
        Cache::put($this->cacheKey($token), hash('sha256', $answer), self::TTL_SECONDS);

        return [
            'token' => $token,
            'image' => 'data:image/png;base64,'.base64_encode($this->renderPng($answer)),
        ];
    }

    public function verify(?string $token, ?string $answer): bool
    {
        if (! $token || $answer === null || $answer === '') {
            return false;
        }

        $key = $this->cacheKey($token);
        $expected = Cache::pull($key);
        if (! is_string($expected) || $expected === '') {
            return false;
        }

        $normalized = preg_replace('/\D+/', '', (string) $answer) ?? '';

        return hash_equals($expected, hash('sha256', $normalized));
    }

    protected function cacheKey(string $token): string
    {
        return 'captcha:'.$token;
    }

    protected function renderPng(string $digits): string
    {
        $width = 180;
        $height = 56;
        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            throw new \RuntimeException('GD failed to create captcha image');
        }

        $bg = imagecolorallocate($img, 28, 32, 40);
        $fg = imagecolorallocate($img, 220, 230, 240);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);

        for ($i = 0; $i < 60; $i++) {
            $noise = imagecolorallocate(
                $img,
                random_int(40, 120),
                random_int(40, 120),
                random_int(40, 140)
            );
            imagesetpixel($img, random_int(0, $width - 1), random_int(0, $height - 1), $noise);
        }

        for ($i = 0; $i < 5; $i++) {
            $line = imagecolorallocate(
                $img,
                random_int(60, 140),
                random_int(60, 140),
                random_int(80, 160)
            );
            imageline(
                $img,
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                $line
            );
        }

        $len = strlen($digits);
        $slot = (int) floor(($width - 16) / max(1, $len));
        for ($i = 0; $i < $len; $i++) {
            $x = 10 + $i * $slot + random_int(-2, 2);
            $y = random_int(12, 28);
            $angle = random_int(-18, 18);
            $color = imagecolorallocate(
                $img,
                random_int(180, 255),
                random_int(180, 255),
                random_int(180, 255)
            ) ?: $fg;

            // Built-in font (no TTF dependency): draw with slight offset for distortion.
            imagestring($img, 5, $x, $y, $digits[$i], $color);
            if ($angle % 2 === 0) {
                imagestring($img, 5, $x + 1, $y + random_int(-1, 1), $digits[$i], $color);
            }
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }
}
