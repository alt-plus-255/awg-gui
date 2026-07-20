<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WsTokenController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $token = Str::random(48);
        Cache::put('ws_token:'.$token, $user->id, now()->addMinutes(30));

        return response()->json(['token' => $token]);
    }
}
