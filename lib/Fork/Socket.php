<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork;


class Socket {
    protected \Socket $socket;



    protected function __construct(\Socket $socket) {
        socket_set_nonblock($socket);
        $this->socket = $socket;
    }


    public function close(): void {
        socket_close($this->socket);
    }

    /** @return self[] */
    public static function createPair(): array {
        socket_create_pair(\AF_UNIX, \SOCK_STREAM, 0, $sockets);

        return [
            new self($sockets[0]),
            new self($sockets[1])
        ];
    }

    public function read(): \Generator {
        socket_set_nonblock($this->socket);

        while (true) {
            $read = [ $this->socket ];
            $write = null;
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

            $output = socket_read($this->socket, 1024) ?: '';

            if ($output === '') {
                break;
            }

            yield $output;
        }
    }

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
