<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit\Hub\Server;

use Ratchet\RFC6455\Messaging\Frame;
use React\Socket\ConnectionInterface;

/** A browser of the tests that writes frames byte by byte: what pawl would never send. */
final class RawClient
{
    /** Bytes received after the response headers. */
    public string $received = '';

    /** The response headers. */
    public string $headers = '';

    public bool $closed = false;

    public function __construct(
        public readonly ConnectionInterface $socket,
    ) {
        $socket->on('data', function (string $data): void {
            if (!str_contains($this->headers, "\r\n\r\n")) {
                $data = $this->headers . $data;
                $end = strpos($data, "\r\n\r\n");
                if (false === $end) {
                    $this->headers = $data;

                    return;
                }
                $this->headers = substr($data, 0, $end + 4);
                $data = substr($data, $end + 4);
            }
            $this->received .= $data;
        });
        $socket->on('close', function (): void {
            $this->closed = true;
        });
    }

    /** A frame as a browser sends it: masked. */
    public static function masked(string $payload, int $opcode = Frame::OP_TEXT, bool $final = true): string
    {
        $frame = new Frame($payload, $final, $opcode);
        $frame->maskPayload();

        return $frame->getContents();
    }

    public function send(string $bytes): void
    {
        $this->socket->write($bytes);
    }

    public function subscribe(int $id, #[\SensitiveParameter] string $token): void
    {
        $this->send(self::masked(json_encode(
            ['hub' => 'subscribe', 'id' => $id, 'token' => $token],
            \JSON_THROW_ON_ERROR,
        )));
    }

    /**
     * The close frame of a code, as the server sends it.
     *
     * @return non-empty-string
     */
    public static function close(int $code): string
    {
        return "\x88\x02" . pack('n', $code);
    }
}
