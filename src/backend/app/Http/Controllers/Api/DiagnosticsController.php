<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Diagnostics\DiagnosticsService;
use Illuminate\Http\Request;

class DiagnosticsController extends Controller
{
    public function __construct(private DiagnosticsService $diagnostics) {}

    public function status()
    {
        return response()->json($this->diagnostics->status());
    }

    public function run(Request $request)
    {
        $data = $request->validate([
            'config_ids' => ['sometimes', 'array'],
            'config_ids.*' => ['integer', 'min:1'],
        ]);

        $configIds = isset($data['config_ids']) ? array_values(array_map('intval', $data['config_ids'])) : null;

        return response()->json($this->diagnostics->run($configIds));
    }

    public function singBoxConfig()
    {
        return response()->json($this->diagnostics->singBoxConfig());
    }

    public function awgConfigs()
    {
        return response()->json($this->diagnostics->awgConfigs());
    }
}
