<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork;


/** @internal */
class Task {
    protected \Closure $closure;
    protected bool $exited = false;
    protected ?array $output = null;
    protected int $pid = -1;
    protected ?Socket $socket = null;
    protected bool $successfullyExited = false;




    public function __construct(callable $callable) {
        $this->closure = ($callable instanceof \Closure) ? $callable : \Closure::fromCallable($callable);
    }




    public function __invoke(): mixed {
        return ($this->closure)();
    }


    public function getOutput(): array {
        if ($this->output !== null) {
            return $this->output;
        }

        $output = '';
        foreach ($this->socket->read() as $buffer) {
            $output .= $buffer;
        }

        $this->socket->close();

        if (str_starts_with($output, "\0\0serialized\0\0:")) {
            $output = unserialize(substr($output, 15));
        }

        return [
            'success' => $this->successfullyExited,
            'data' => $output
        ];
    }

    public function getPid(): int {
        return $this->pid;
    }

    public function hasExited(): bool {
        if ($this->exited) {
            return true;
        }
        //$this->output .= $this->socket->read()->current();

        $pid = pcntl_waitpid($this->pid, $status, \WNOHANG | \WUNTRACED);
        if ($pid === $this->pid) {
            $this->exited = true;
            $this->successfullyExited = true;

            if (pcntl_wifexited($status)) {
                $this->successfullyExited = (pcntl_wexitstatus($status) === 0);
            } elseif (pcntl_wifsignaled($status)) {
                $this->successfullyExited = false;
            }

            return true;
        }
        if ($status !== 0) {
            throw new ForkException("Process id {$this->pid} was unsuccessfully handled");
        }

        return false;
    }

    public function hasSuccessfullyExited(): bool {
        return $this->successfullyExited;
    }

    public function setPid(int $pid): self {
        $this->pid = $pid;
        return $this;
    }

    public function setSocket(Socket $socket): self {
        $this->socket = $socket;
        return $this;
    }
}
