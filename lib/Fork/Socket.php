<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork;


/**
 * Provides a simple wrapper for working with UNIX socket pairs
 *
 * @internal
 *
 * This class encapsulates a native PHP `Socket` resource and provides non-blocking
 * read and write operations, as well as helper methods to create socket pairs
 * for inter-process communication.
 */
class Socket {
    /** The underlying native PHP socket resource */
    protected \Socket $socket;

    /**
     * Initializes the Socket instance and sets it to non-blocking mode
     *
     * @param \Socket $socket The socket resource to wrap
     */
    protected function __construct(\Socket $socket) {
        socket_set_nonblock($socket);
        $this->socket = $socket;
    }

    /**
     * Closes the socket connection
     *
     * @return void
     */
    public function close(): void {
        socket_close($this->socket);
    }

    /**
     * Creates a pair of connected socket instances
     *
     * @return self[] An array containing two connected Socket instances
     */
    public static function createPair(): array {
        socket_create_pair(\AF_UNIX, \SOCK_STREAM, 0, $sockets);

        return [
            new self($sockets[0]),
            new self($sockets[1])
        ];
    }

    /**
     * Reads data from the socket in a non-blocking fashion using a generator
     *
     * @return \Generator<string> A generator yielding data chunks as strings
     */
    public function read(): \Generator {
        socket_set_nonblock($this->socket);

        while (true) {
            $read = [ $this->socket ];
            $write = null;
            $except = null;

            try {
                $result = socket_select($read, $write, $except, 0, 10) ?: -1;
            }
            // Not sure how to test coverage of this
            // @codeCoverageIgnoreStart
            catch (\ErrorException $e) {
                if (socket_last_error() === 4) {
                    continue;
                }
                throw $e;
            }
            // @codeCoverageIgnoreEnd

            if ($result <= 0) {
                break;
            }

            $output = socket_read($this->socket, 1024) ?: '';

            if ($output === '') {
                break;
            }

            yield $output;
        }
    }

    /**
     * Writes a string to the socket in a non-blocking manner
     *
     * @param string $string The data to write to the socket
     * @return void
     *
     * @codeCoverageIgnore
     */
    public function write(string $string): void {
        socket_set_nonblock($this->socket);

        while ($string !== '') {
            $read = null;
            $write = [ $this->socket ];
            $except = null;

            try {
                $result = socket_select($read, $write, $except, 0, 10) ?: -1;
            } catch (\ErrorException $e) {
                if (socket_last_error() === 4) {
                    continue;
                }
                throw $e;
            }

            if ($result <= 0) {
                break;
            }

            $length = strlen($string);
            $bytes = socket_write($this->socket, $string, $length) ?: $length;
            if ($bytes === $length) {
                break;
            }

            $string = substr($string, $bytes);
        }
    }
}