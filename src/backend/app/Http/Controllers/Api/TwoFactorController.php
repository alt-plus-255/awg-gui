<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(
        private TwoFactorService $twoFactor,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => $this->twoFactor->isEnabled($user),
            'pending' => filled($user->two_factor_secret) && $user->two_factor_confirmed_at === null,
        ]);
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $payload = $this->twoFactor->beginSetup($user);

        return response()->json([
            'secret' => $payload['secret'],
            'otpauth_uri' => $payload['otpauth_uri'],
            'qr' => $payload['qr'],
            'enabled' => false,
            'pending' => true,
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();
        if (! $this->twoFactor->confirm($user, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['Неверный код подтверждения.'],
            ]);
        }

        return response()->json([
            'ok' => true,
            'enabled' => true,
            'pending' => false,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Неверный пароль.'],
            ]);
        }

        if (! $this->twoFactor->verify($user, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['Неверный код двухфакторной аутентификации.'],
            ]);
        }

        $this->twoFactor->disable($user);

        return response()->json([
            'ok' => true,
            'enabled' => false,
            'pending' => false,
        ]);
    }
}
