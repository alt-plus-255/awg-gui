<?php

namespace App\Services\Resolver;

use RuntimeException;

class PingSessionBusyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('resolver.ping_already_running'));
    }
}
