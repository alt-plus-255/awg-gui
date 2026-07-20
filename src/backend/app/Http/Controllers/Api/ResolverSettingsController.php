<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Resolver\ResolverListsService;
use Illuminate\Http\Request;
use RuntimeException;

class ResolverSettingsController extends Controller
{
    public function __construct(
        private ResolverListsService $lists,
    ) {}

    public function show()
    {
        return response()->json($this->lists->settingsPayload());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'sync_interval_minutes' => ['required', 'integer', 'min:5', 'max:10080'],
        ]);

        $this->lists->setSyncIntervalMinutes((int) $data['sync_interval_minutes']);

        return response()->json($this->lists->settingsPayload());
    }

    public function syncAll()
    {
        $errors = [];
        try {
            $this->lists->syncCommunity(null, true);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
        try {
            $this->lists->syncAllRemoteCustoms(true);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        if ($errors !== []) {
            return response()->json([
                'ok' => false,
                'message' => implode('; ', $errors),
                ...$this->lists->settingsPayload(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            ...$this->lists->settingsPayload(),
        ]);
    }

    public function syncOne(string $tag)
    {
        $tag = trim($tag);
        if ($tag === '') {
            return response()->json(['message' => 'Пустой tag'], 422);
        }

        try {
            $this->lists->syncOneTag($tag, true);
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                ...$this->lists->settingsPayload(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            ...$this->lists->settingsPayload(),
        ]);
    }
}
