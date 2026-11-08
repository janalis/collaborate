<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Server;

use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;

/** A pawl browser of the tests: what it received, and how it was closed. */
final class Client
{
    /** @var list<string> */
    public array $messages = [];

    public ?int $code = null;

    public bool $closed = false;

    public function __construct(
        public readonly WebSocket $socket,
    ) {
        $socket->on('message', function (MessageInterface $message): void {
            $this->messages[] = $message->getPayload();
        });
        $socket->on('close', function (?int $code = null): void {
            $this->closed = true;
            $this->code = $code;
        });
    }

    public function subscribe(int $id, #[\SensitiveParameter] string $token): void
    {
        $this->socket->send(json_encode(['hub' => 'subscribe', 'id' => $id, 'token' => $token], \JSON_THROW_ON_ERROR));
    }
}
