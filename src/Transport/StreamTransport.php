<?php

namespace Examine\Sip2\Transport;

/**
 * PHP SIP2 Plus - Stream-based Transport
 *
 * StreamTransport uses PHP's stream wrappers (fsockopen) to connect to a SIP2
 * backend over plain TCP. It implements the minimal I/O API required by the
 * core Sip2 class.
 *
 *
 * @link      https://github.com/examinecom/php-sip2-plus
 * @link      https://github.com/cap60552/php-sip2
 *
 * @license   GPL-3.0  See the LICENSE file distributed with this source code.
 */
class StreamTransport implements TransportInterface
{
    /** @var resource|null */
    private $stream;

    public function connect(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $this->stream = @fsockopen($host, $port, $errno, $errstr, 10.0);
        if ($this->stream === false) {
            $this->stream = null;

            return false;
        }
        stream_set_timeout($this->stream, 30);
        stream_set_blocking($this->stream, true);

        return true;
    }

    public function write(string $message): false|int
    {
        if (! $this->stream) {
            return false;
        }

        return fwrite($this->stream, $message);
    }

    public function readByte(): false|string
    {
        if (! $this->stream) {
            return false;
        }
        $data = fread($this->stream, 1);
        if ($data === '' || $data === false) {
            return false;
        }

        return $data;
    }

    public function close(): void
    {
        if ($this->stream) {
            fclose($this->stream);
            $this->stream = null;
        }
    }
}
