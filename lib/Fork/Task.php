<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork;

/**
 * Represents a task to be executed in a forked process
 *
 * @internal
 *
 * This class wraps a callable (converted to a Closure), manages process state,
 * and handles socket-based communication for retrieving results.
 */
class Task {
    /** The closure to be executed in the forked process */
    protected \Closure $closure;

    /** Indicates whether the task has exited */
    protected bool $exited = false;

    /** The output data retrieved from the socket */
    protected ?array $output = null;

    /** The process ID associated with this task */
    protected int $pid = -1;

    /** The socket used for reading task output */
    protected ?Socket $socket = null;

    /** Indicates whether the task exited successfully */
    protected bool $successfullyExited = false;


    /**
     * Constructs a Task instance with a given callable
     *
     * @param callable $callable The callable to execute in the forked process
     */
    public function __construct(callable $callable) {
        $this->closure = ($callable instanceof \Closure) ? $callable : \Closure::fromCallable($callable);
    }

    /**
     * Invokes the task's closure
     *
     * @return mixed The result of the closure execution
     */
    public function __invoke(): mixed {
        return ($this->closure)();
    }

    /**
     * Retrieves the output from the task after it has run
     *
     * @return array{
     *     success: bool,
     *     data: mixed
     * }
     *     An associative array containing:
     *     - 'success': whether the task completed successfully
     *     - 'data': the task output data
     */
    public function getOutput(): array {
        if ($this->output !== null) {
            return $this->output;
        }

        $output = null;
        foreach ($this->socket->read() as $buffer) {
            (string)$output .= $buffer;
        }

        $this->socket->close();

        if (is_string($output) && str_starts_with($output, "\0\0serialized\0\0:")) {
            $output = unserialize(substr($output, 15));
        }

        return [
            'success' => (bool)min(!$output instanceof ThrowableContext, $this->successfullyExited),
            'data' => $output
        ];
    }

    /**
     * Gets the process ID (PID) of the task
     *
     * @return int The PID
     */
    public function getPid(): int {
        return $this->pid;
    }

    /**
     * Checks whether the task has exited
     *
     * @return bool True if the task has exited, false otherwise
     *
     * @throws ForkException If the process was not handled successfully
     */
    public function hasExited(): bool {
        if ($this->exited) {
            return true;
        }

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
        if ($pid === -1) {
            throw new ForkException("Process id {$this->pid} was unsuccessfully handled");
        }

        return false;
    }

    /**
     * Sets the process ID (PID) for the task
     *
     * @param int $pid The process ID
     * @return self
     */
    public function setPid(int $pid): self {
        $this->pid = $pid;
        return $this;
    }

    /**
     * Sets the socket used for reading task output
     *
     * @param Socket $socket The socket instance
     * @return self
     */
    public function setSocket(Socket $socket): self {
        $this->socket = $socket;
        return $this;
    }
}