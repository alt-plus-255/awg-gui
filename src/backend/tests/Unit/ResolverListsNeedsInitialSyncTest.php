<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\Resolver\ResolverListsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolverListsNeedsInitialSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_needs_initial_sync_when_never_synced(): void
    {
        $lists = app(ResolverListsService::class);

        $this->assertTrue($lists->needsInitialSync());
        $this->assertTrue($lists->settingsPayload()['needs_initial_sync']);
    }

    public function test_needs_initial_sync_when_last_sync_set_but_files_missing(): void
    {
        $lists = app(ResolverListsService::class);
        Setting::setValue(ResolverListsService::SETTING_LAST_SYNC, now()->toIso8601String());

        $this->assertTrue($lists->needsInitialSync());
    }
}
