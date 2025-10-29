<?php

namespace Examine\Sip2\Transport;

/**
 * PHP SIP2 Plus - TLS-enabled Stream Transport
 *
 * TlsStreamTransport uses PHP's stream_socket_client with an SSL context to
 * connect to a SIP2 backend over TLS. It implements the minimal I/O API used
 * by the core Sip2 class.
 *
 * @link      https://github.com/examinecom/php-sip2-plus
 * @link      https://github.com/cap60552/php-sip2
 *
 * @license   GPL-3.0  See the LICENSE file distributed with this source code.
 */
class TlsStreamTransport implements TransportInterface
{
    /** @var resource|null */
    private $stream;

    /** @var array SSL context options */
    private array $sslOptions;

    public function __construct(array $sslOptions = [])
    {
        // Sensible defaults: do not verify by default to preserve legacy behavior unless explicitly enabled
        $this->sslOptions = array_replace([
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => false,
            // 'cafile' => null, // optional
            // 'ciphers' => null, // optional
        ], $sslOptions);
    }

    public function connect(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';

        $contextOptions = [
            'ssl' => array_filter([
                'verify_peer' => (bool) ($this->sslOptions['verify_peer'] ?? false),
                'verify_peer_name' => (bool) ($this->sslOptions['verify_peer_name'] ?? false),
                'allow_self_signed' => (bool) ($this->sslOptions['allow_self_signed'] ?? false),
                'cafile' => $this->sslOptions['cafile'] ?? null,
                'ciphers' => $this->sslOptions['ciphers'] ?? null,
                'SNI_enabled' => true,
                'SNI_server_name' => $host,
            ], fn ($v): bool => $v !== null && $v !== ''),
        ];
        $context = stream_context_create($contextOptions);

        // Use stream_socket_client for TLS
        $remote = sprintf('tls://%s:%d', $host, $port);
        $this->stream = @stream_socket_client($remote, $errno, $errstr, 10.0, STREAM_CLIENT_CONNECT, $context);
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
