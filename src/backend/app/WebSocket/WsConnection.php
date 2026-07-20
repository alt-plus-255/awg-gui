<?php

namespace App\WebSocket;

use Ratchet\RFC6455\Messaging\Frame;
use React\Socket\ConnectionInterface;

class WsConnection
{
    public function __construct(private ConnectionInterface $connection) {}

    public function send(string $payload): void
    {
        $frame = new Frame($payload, true, Frame::OP_TEXT);
        $this->connection->write($frame->getContents());
    }

    public function close(): void
    {
        $this->connection->end();
    }

    public function getId(): string
    {
        return spl_object_hash($this->connection);
    }
}
