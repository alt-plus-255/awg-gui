<?php

namespace App\WebSocket;

use App\Services\AmneziaWg\StatsBroadcaster;

class StatsWebSocketHandler
{
    public function __construct(private StatsBroadcaster $broadcaster) {}

    public function onMessage(WsConnection $from, string $msg): void
    {
        $data = json_decode($msg, true);
        if (! is_array($data)) {
            return;
        }

        $action = $data['action'] ?? '';

        if ($action === 'subscribe') {
            $ids = array_values(array_map('intval', $data['config_ids'] ?? []));
            $this->broadcaster->subscribe($from, $ids);

            return;
        }

        if ($action === 'unsubscribe') {
            $ids = array_values(array_map('intval', $data['config_ids'] ?? []));
            $this->broadcaster->unsubscribe($from, $ids);

            return;
        }

        if ($action === 'ping') {
            $from->send(json_encode(['type' => 'pong']));
        }
    }

    public function onClose(WsConnection $conn): void
    {
        $this->broadcaster->detach($conn);
    }
}
