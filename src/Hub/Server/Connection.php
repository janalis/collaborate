<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Hub\Server;

use Janalis\Collaborate\Hub\Transport\WebSocket\TokenClaims;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;

/**
 * One browser's WebSocket: what it was granted (by {@see Router}), when it was last heard of, and how far behind
 * it is. Frames go out through `$out`, the 101 response's body; the socket under it is only closed to drop a
 * client that stopped reading, since a close frame would queue behind what it does not read.
 */
final class Connection
{
    /** Unique among the open connections. */
    public readonly int $id;

    /** Until when its token grants its topics; 0 until it subscribed. */
    public int $exp = 0;

    /** The user its first token named, once subscribed. */
    public int $user = 0;

    public bool $subscribed = false;

    /** Closed, or closing: nothing more is read nor sent. */
    public bool $closing = false;

    public float $lastSeen;

    /** Bytes written since the socket's buffer filled up. */
    public int $backlog = 0;

    public ?float $congestedSince = null;

    /** What its token grants, the last one it subscribed with; null until it subscribed. */
    public ?TokenClaims $claims = null;

    /** Cursor events it may still send at once: a token bucket, full at first. */
    private ?float $cursorTokens = null;

    private float $cursorAt = 0.0;

    /** Since when its close frame waits to be written. */
    public ?float $closingSince = null;

    private MessageBuffer $buffer;

    private int $subscribes = 0;

    private float $window;

    /**
     * @param \Closure(self, MessageInterface): void $onMessage
     * @param \Closure(self, FrameInterface): void $onControl
     */
    public function __construct(
        public readonly ConnectionInterface $socket,
        private readonly WritableStreamInterface $out,
        public readonly string $origin,
        public readonly ?string $ip,
        public readonly float $opened,
        private readonly int $maxBuffer,
        int $maxMessageSize,
        \Closure $onMessage,
        \Closure $onControl,
    ) {
        $this->id = spl_object_id($this);
        $this->lastSeen = $opened;
        $this->window = $opened;
        $this->buffer = new MessageBuffer(
            new CloseFrameChecker(),
            fn(MessageInterface $message) => $onMessage($this, $message),
            fn(FrameInterface $frame) => $onControl($this, $frame),
            true,
            null,
            $maxMessageSize,
            $maxMessageSize,
            $out->write(...),
        );
    }

    /** The close frame of a code, as sent. */
    public static function closeFrame(int $code): string
    {
        return new Frame(pack('n', $code), true, Frame::OP_CLOSE)->getContents();
    }

    public function receive(string $data, float $now): void
    {
        if ($this->closing) {
            return;
        }
        $this->lastSeen = $now;
        $this->buffer->onData($data);
    }

    /** Writes a frame, dropping the client once it is more than `max_buffer` bytes behind. */
    public function send(string $frame, float $now): void
    {
        if ($this->closing) {
            return;
        }
        if (null !== $this->congestedSince) {
            $this->backlog += \strlen($frame);
            if ($this->backlog > $this->maxBuffer) {
                $this->drop();

                return;
            }
        }
        if (!$this->out->write($frame)) {
            $this->congestedSince ??= $now;
        }
    }

    /** The socket drained what it buffered. */
    public function drained(): void
    {
        $this->congestedSince = null;
        $this->backlog = 0;
    }

    /** Whether one more subscribe message is within the rate limit. */
    public function allowSubscribe(float $now, int $max, float $window): bool
    {
        if (($now - $this->window) >= $window) {
            $this->window = $now;
            $this->subscribes = 0;
        }

        return ++$this->subscribes <= $max;
    }

    /** Whether one more cursor event is within `$rate` per second, in bursts of as many. */
    public function allowCursor(float $now, float $rate): bool
    {
        $tokens = null === $this->cursorTokens
            ? $rate
            : min($rate, $this->cursorTokens + (($now - $this->cursorAt) * $rate));
        $this->cursorAt = $now;
        $this->cursorTokens = $tokens >= 1 ? $tokens - 1 : $tokens;

        return $tokens >= 1;
    }

    /**
     * Sends a close frame, then closes once it is written; the stream ignores a second one. A client that does
     * not read it is dropped by the timer ({@see Timings::$closeTimeout}).
     */
    public function close(int $code, float $now): void
    {
        $this->closingSince ??= $now;
        $this->closing = true;
        $this->out->end(self::closeFrame($code));
    }

    /** Closes the socket now, whatever it still buffers. */
    public function drop(): void
    {
        $this->closing = true;
        $this->socket->close();
    }
}
