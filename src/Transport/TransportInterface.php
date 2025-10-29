<?php

namespace Examine\Sip2\Transport;

/**
 * PHP SIP2 Plus - Transport Interface
 *
 * Abstraction for low-level I/O to a SIP2 backend. Implementations may use
 * PHP streams, sockets, or TLS-enabled streams while exposing a minimal API
 * required by the core Sip2 class.
 *
 * @link      https://github.com/examinecom/php-sip2-plus
 * @link      https://github.com/cap60552/php-sip2
 *
 * @license   GPL-3.0  See the LICENSE file distributed with this source code.
 */
interface TransportInterface
{
    /**
     * Open a connection to the SIP2 backend.
     */
    public function connect(string $host, int $port): bool;

    /**
     * Write a message to the transport.
     *
     * @return int|false number of bytes written or false on failure
     */
    public function write(string $message);

    /**
     * Read a single byte from the transport.
     *
     * @return string|false a single-character string or false on failure/EOF
     */
    public function readByte();

    /**
     * Close the transport connection.
     */
    public function close(): void;
}
