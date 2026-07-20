<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\System\HostMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class SystemController extends Controller
{
    public function __construct(
        private AmneziaWgService $awg,
        private HostMetricsService $hostMetrics,
    ) {}

    public function status()
    {
        $awgRunning = $this->awg->isContainerRunning();
        $statsAvailable = $awgRunning ? $this->awg->probeStatsAvailable() : false;

        $messages = [];
        if (! $awgRunning) {
            $messages[] = 'Контейнер AmneziaWG не запущен';
        } elseif (! $statsAvailable) {
            $messages[] = 'Статистика AWG недоступна (docker exec)';
        } else {
            $messages[] = 'AmneziaWG работает';
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
                'message' => 'Перезапуск AWG уже выполняется',
                'details' => $result,
            ], 409);
        }

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => 'Не удалось перезапустить контейнер AmneziaWG',
                'details' => $result,
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Контейнер AmneziaWG перезапущен, конфиги применены',
            'details' => $result,
        ]);
    }

    public function restartAll()
    {
        Process::start([
            'docker', 'restart',
            $this->awg->containerName(),
            'awggui-caddy',
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Перезапуск служб запущен (AWG, Caddy). Панель может быть недоступна несколько секунд.',
        ]);
    }
}
