<?php

namespace App\Console\Commands;

use App\Services\Resolver\SpeedTestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResolverSpeedTestJobCommand extends Command
{
    protected $signature = 'resolver:speed-test-job {jobId : Speed-test job UUID}';

    protected $description = 'Run a queued resolver speed-test job in the background';

    public function handle(SpeedTestService $speedTest): int
    {
        $jobId = (string) $this->argument('jobId');
        ignore_user_abort(true);
        set_time_limit(0);

        try {
            $speedTest->processQueuedJob($jobId);
            $this->info('Speed-test job finished: '.$jobId);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::warning('resolver:speed-test-job failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
