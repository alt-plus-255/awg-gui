<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\LoginProtectionService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private LoginProtectionService $protection,
        private CaptchaService $captcha,
        private TwoFactorService $twoFactor,
    ) {}

    public function loginStatus(Request $request): JsonResponse
    {
        return response()->json($this->protection->status($request->ip() ?? '0.0.0.0'));
    }

    public function captcha(): JsonResponse
    {
        return response()->json($this->captcha->generate());
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'captcha_token' => ['nullable', 'string'],
            'captcha_answer' => ['nullable', 'string'],
            'totp' => ['nullable', 'string'],
        ]);

        $ip = $request->ip() ?? '0.0.0.0';
        $status = $this->protection->status($ip);

        if ($status['locked']) {
            return $this->authError(
                'locked',
                'Вход временно заблокирован из‑за слишком большого числа неверных попыток.',
                429,
                $status
            );
        }

        if ($status['captcha_required']) {
            if (! $this->captcha->verify($credentials['captcha_token'] ?? null, $credentials['captcha_answer'] ?? null)) {
                return $this->authError(
                    'captcha_invalid',
                    'Неверная капча. Введите цифры с картинки.',
                    422,
                    array_merge($status, ['captcha_required' => true])
                );
            }
        }

        $user = User::query()
            ->where('username', $credentials['username'])
            ->orWhere('email', $credentials['username'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $status = $this->protection->recordFailedPassword($ip);

            if ($status['locked']) {
                return $this->authError(
                    'locked',
                    'Вход временно заблокирован из‑за слишком большого числа неверных попыток.',
                    429,
                    $status
                );
            }

            $code = $status['captcha_required'] ? 'captcha_required' : 'invalid_credentials';
            $message = $code === 'captcha_required'
                ? 'Неверные учётные данные. Для продолжения введите капчу.'
                : 'Неверные учётные данные.';

            return $this->authError($code, $message, 422, $status);
        }

        if ($this->twoFactor->isEnabled($user)) {
            $totp = $credentials['totp'] ?? null;
            if ($totp === null || $totp === '') {
                return $this->authError(
                    'totp_required',
                    'Введите код из приложения двухфакторной аутентификации.',
                    422,
                    $status
                );
            }

            if (! $this->twoFactor->verify($user, $totp)) {
                return $this->authError(
                    'totp_invalid',
                    'Неверный код двухфакторной аутентификации.',
                    422,
                    $status
                );
            }
        }

        Auth::login($user, true);
        $this->protection->clear($ip);
        $request->session()->regenerate();

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user ? $this->userPayload($user) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    protected function authError(string $code, string $message, int $http, array $status = []): JsonResponse
    {
        return response()->json(array_merge([
            'message' => $message,
            'code' => $code,
            'errors' => [
                'username' => [$message],
            ],
        ], $status), $http);
    }

    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'two_factor_enabled' => $this->twoFactor->isEnabled($user),
        ];
    }
}
