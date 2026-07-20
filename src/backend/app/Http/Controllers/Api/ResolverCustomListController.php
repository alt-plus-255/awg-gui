<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResolverCustomList;
use App\Services\Resolver\ResolverListsService;
use Illuminate\Http\Request;

class ResolverCustomListController extends Controller
{
    public function __construct(
        private ResolverListsService $lists,
    ) {}

    public function index()
    {
        return response()->json([
            'lists' => $this->lists->customListCatalog(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'source_url' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'domains' => ['sometimes', 'array'],
            'domains.*' => ['string', 'max:255'],
            'cidrs' => ['sometimes', 'array'],
            'cidrs.*' => ['string', 'max:64'],
        ]);

        $list = $this->lists->createCustomList(
            $data['name'],
            $data['domains'] ?? [],
            $data['cidrs'] ?? [],
            $data['source_url'] ?? null,
        );

        return response()->json([
            'ok' => true,
            'list' => $this->listPayload($list),
            'settings' => $this->lists->settingsPayload(),
        ], 201);
    }

    public function update(Request $request, ResolverCustomList $customList)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'source_url' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'domains' => ['sometimes', 'array'],
            'domains.*' => ['string', 'max:255'],
            'cidrs' => ['sometimes', 'array'],
            'cidrs.*' => ['string', 'max:64'],
        ]);

        $list = $this->lists->updateCustomList(
            $customList,
            $data['name'],
            $data['domains'] ?? [],
            $data['cidrs'] ?? [],
            array_key_exists('source_url', $data) ? $data['source_url'] : $customList->source_url,
        );

        return response()->json([
            'ok' => true,
            'list' => $this->listPayload($list),
            'settings' => $this->lists->settingsPayload(),
        ]);
    }

    public function destroy(ResolverCustomList $customList)
    {
        $this->lists->deleteCustomList($customList);

        return response()->json([
            'ok' => true,
            'settings' => $this->lists->settingsPayload(),
        ]);
    }

    /** @return array<string, mixed> */
    private function listPayload(ResolverCustomList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'slug' => $list->slug,
            'tag' => $list->slug,
            'source_url' => $list->source_url,
            'domains' => array_values($list->domains ?? []),
            'cidrs' => array_values($list->cidrs ?? []),
            'updated_at' => optional($list->updated_at)?->toIso8601String(),
        ];
    }
}
