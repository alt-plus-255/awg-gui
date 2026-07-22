<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;
use App\Services\System\HostMetricsService;
use Illuminate\Http\Request;

class SystemController extends Controller
{
    public function __construct(
        private AmneziaWgService $awg,
        private HostMetricsService $hostMetrics,
        private DockerRuntime $docker,
    ) {}

    public function status()
    {
        $awgRunning = $this->awg->isContainerRunning();
        $statsAvailable = $awgRunning ? $this->awg->probeStatsAvailable() : false;

        $messages = [];
        if (! $awgRunning) {
            $messages[] = __('system.awg_not_running');
        } elseif (! $statsAvailable) {
            $messages[] = __('system.awg_stats_unavailable');
        } else {
            $messages[] = __('system.awg_ok');
        }

        return response()->json([
            'ok' => $awgRunning,
            'awg_restarting' => $this->awg->isAwgRestarting(),
            'services' => [
                'app' => ['ok' => true],
                'db' => ['ok' => true],
                'awg' => ['ok' => $awgRunning, 'running' => $awgRunning],
                'stats' => ['ok' => $statsAvailable, 'available' => $statsAvailable],
            ],
            'messages' => $messages,
        ]);
    }

    public function processes(Request $request)
    {
        $sort = $request->query('sort', 'cpu') === 'mem' ? 'mem' : 'cpu';
        $limit = (int) $request->query('limit', 40);

        $data = $this->hostMetrics->processMonitor($sort, $limit);

        return response()->json([
            'ok' => true,
            'sort' => $sort,
            'processes' => $data['processes'],
            'containers' => $data['containers'],
        ]);
    }

    public function restartAwg()
    {
        $result = $this->awg->restartAwg();

        if (! empty($result['already_restarting'])) {
            return response()->json([
                'ok' => false,
                'already_restarting' => true,
                'message' => __('api.awg_restart_already_running'),
                'details' => $result,
            ], 409);
        }

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => __('api.awg_restart_failed'),
                'details' => $result,
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'message' => __('api.awg_restart_ok'),
            'details' => $result,
        ]);
    }

    public function restartAll()
    {
        $this->docker->start([
            'restart',
            $this->awg->containerName(),
            'awggui-caddy',
        ]);

        return response()->json([
            'ok' => true,
            'message' => __('system.restart_all_started'),
        ]);
    }
}
