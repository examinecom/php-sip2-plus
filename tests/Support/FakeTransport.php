<?php

namespace Tests\Support;

use Examine\Sip2\Transport\TransportInterface;

class FakeTransport implements TransportInterface
{
    private array $responses;

    private int $writeCount = 0;

    private int $readOffset = 0;

    private int $activeIndex = -1;

    public array $written = [];

    public bool $connected = false;

    public function __construct(array $responses = [])
    {
        $this->responses = array_values($responses);
    }

    public function queueResponse(string $response): void
    {
        $this->responses[] = $response;
    }

    public function connect(string $host, int $port): bool
    {
        $this->connected = true;

        return true;
    }

    public function write(string $message): int
    {
        $this->written[] = $message;
        $this->activeIndex = $this->writeCount;
        $this->readOffset = 0;
        $this->writeCount++;

        return strlen($message);
    }

    public function readByte()
    {
        if ($this->activeIndex < 0 || ! isset($this->responses[$this->activeIndex])) {
            return false;
        }
        $resp = $this->responses[$this->activeIndex];
        if ($this->readOffset >= strlen($resp)) {
            return false;
        }
        $ch = $resp[$this->readOffset];
        $this->readOffset++;

        return $ch;
    }

    public function close(): void
    {
        $this->connected = false;
    }
}
