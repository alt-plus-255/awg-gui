<?php

namespace App\WebSocket;

use App\Services\AmneziaWg\StatsBroadcaster;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Factory as LoopFactory;
use React\Socket\ConnectionInterface;
use React\Socket\Server as SocketServer;

class ReactStatsWsServer
{
    private readonly ServerNegotiator $negotiator;

    private readonly CloseFrameChecker $closeFrameChecker;

    public function __construct(
        private StatsBroadcaster $broadcaster,
        private StatsWebSocketHandler $handler,
    ) {
        $this->closeFrameChecker = new CloseFrameChecker;
        $this->negotiator = new ServerNegotiator(new RequestVerifier, new HttpFactory);
    }

    public function run(string $host, int $port): void
    {
        $loop = LoopFactory::create();
        $socket = new SocketServer($loop);
        $socket->listen($port, $host);

        $socket->on('connection', function (ConnectionInterface $connection) {
            $this->handleConnection($connection);
        });

        $loop->addPeriodicTimer($this->broadcaster->intervalSeconds(), function () {
            try {
                $this->broadcaster->tick();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('ws stats tick failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        });

        $loop->run();
    }

    private function handleConnection(ConnectionInterface $connection): void
    {
        $headerComplete = false;
        $buffer = '';
        $messageBuffer = null;
        $wsConnection = null;
        $underflow = new \UnderflowException;

        $connection->on('data', function (string $data) use (
            $connection,
            &$headerComplete,
            &$buffer,
            &$messageBuffer,
            &$wsConnection,
            $underflow
        ) {
            if ($headerComplete) {
                $messageBuffer?->onData($data);

                return;
            }

            $buffer .= $data;
            $parts = explode("\r\n\r\n", $buffer, 2);
            if (count($parts) < 2) {
                return;
            }

            $headerComplete = true;
            $request = Message::parseRequest($parts[0]."\r\n\r\n");
            $path = $request->getUri()->getPath();
            if ($path !== '/ws' && $path !== '/ws/') {
                $connection->write("HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n");
                $connection->end();

                return;
            }

            $query = $request->getUri()->getQuery();
            parse_str($query, $queryParams);
            $token = $queryParams['token'] ?? null;
            if (! is_string($token) || $token === '') {
                $connection->write("HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\n\r\n");
                $connection->end();

                return;
            }

            $negotiatorResponse = $this->negotiator->handshake($request)
                ->withHeader('Content-Length', '0');

            $connection->write(Message::toString($negotiatorResponse));

            if ($negotiatorResponse->getStatusCode() !== 101) {
                $connection->end();

                return;
            }

            $wsConnection = new WsConnection($connection);
            if (! $this->broadcaster->authenticate($wsConnection, $token)) {
                $wsConnection->close();

                return;
            }

            $messageBuffer = new MessageBuffer(
                $this->closeFrameChecker,
                function (MessageInterface $message) use ($wsConnection) {
                    $this->handler->onMessage($wsConnection, $message->getPayload());
                },
                function (FrameInterface $frame) use ($connection, &$messageBuffer, $wsConnection) {
                    if ($frame->getOpCode() === Frame::OP_CLOSE) {
                        $this->handler->onClose($wsConnection);
                        $connection->end($frame->getContents());

                        return;
                    }
                    if ($frame->getOpCode() === Frame::OP_PING && $messageBuffer) {
                        $connection->write(
                            $messageBuffer->newFrame($frame->getPayload(), true, Frame::OP_PONG)->getContents()
                        );
                    }
                },
                true,
                fn (): \Exception => $underflow,
                null,
                null,
                [$connection, 'write']
            );

            if (isset($parts[1]) && $parts[1] !== '') {
                $messageBuffer->onData($parts[1]);
            }
        });

        $connection->on('close', function () use (&$wsConnection) {
            if ($wsConnection) {
                $this->handler->onClose($wsConnection);
            }
        });
    }
}
