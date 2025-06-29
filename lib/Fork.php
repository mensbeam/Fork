<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam;

use MensBeam\Fork\{
    Socket,
    Task,
    ThrowableContext,
    TimeoutException,
    ForkException
};
use MensBeam\SelfSealingCallable;

/**
 * Runs tasks by forking processes. It allows for parallel execution of tasks by
 * creating child processes and managing them, including concurrency control,
 * timeouts, and optional callbacks.
 */
class Fork {
    /** If true, throws exceptions in the forked child process */
    public static bool $throwInFork = false;

    /** If true, includes stack traces in ThrowableContext serialization */
    public static bool $tracesInThrowableContexts = false;

    /** Maximum number of concurrent tasks allowed */
    protected ?int $concurrent = null;

    /** Process ID of the main (parent) process */
    protected static ?int $mainPID = null;

    /** Callable to run in child after task execution */
    protected ?\Closure $onChildAfter = null;

    /** Callable to run in child before task execution */
    protected ?\Closure $onChildBefore = null;

    /** Callable to run in parent after task execution */
    protected ?\Closure $onParentAfter = null;

    /** Callable to run in parent before task execution */
    protected ?\Closure $onParentBefore = null;

    /**
     * The task queue to be executed
     *
     * @var \Iterator<int|string, Task>
     */
    protected \Iterator $queue;

    /**
     * Currently running tasks mapped by key
     *
     * @var Task[]
     */
    protected array $runningTasks = [];

    /** Shutdown handler for cleaning up on script exit */
    protected static ?SelfSealingCallable $shutdownHandler = null;

    /** Timeout in seconds for each child task */
    protected int $timeout = 3600;




    /** Constructs a new Fork instance and initializes state */
    public function __construct() {
        if (self::$mainPID === null) {
            self::$mainPID = getmypid();
        }

        if (self::$shutdownHandler === null) {
            self::$shutdownHandler = new SelfSealingCallable(fn() => $this->stop());
            register_shutdown_function(self::$shutdownHandler);
        }

        $this->queue = new \ArrayIterator();
    }

    /**
     * Registers callables to be run after each task completes, in parent or child
     *
     * @param callable|null $parent A callable to run in the parent process after the task
     * @param callable|null $child A callable to run in the child process after the task
     *
     * @return self Returns this Fork instance for method chaining
     */
    public function after(?callable $parent = null, ?callable $child = null): self {
        if ($child !== null) {
            $this->onChildAfter = ($child instanceof \Closure) ? $child : \Closure::fromCallable($child);
        }
        if ($parent !== null) {
            $this->onParentAfter = ($parent instanceof \Closure) ? $parent : \Closure::fromCallable($parent);
        }
        return $this;
    }

    /**
     * Registers callables to be run before each task starts, in parent or child
     *
     * @param callable|null $parent A callable to run in the parent process before the task
     * @param callable|null $child A callable to run in the child process before the task
     *
     * @return self Returns this Fork instance for method chaining
     */
    public function before(?callable $parent = null, ?callable $child = null): self {
        if ($child !== null) {
            $this->onChildBefore = ($child instanceof \Closure) ? $child : \Closure::fromCallable($child);
        }
        if ($parent !== null) {
            $this->onParentBefore = ($parent instanceof \Closure) ? $parent : \Closure::fromCallable($parent);
        }
        return $this;
    }

    /**
     * Sets the maximum number of concurrent forked tasks
     *
     * @param int $limit The maximum number of concurrent tasks (>= 0)
     *
     * @throws \InvalidArgumentException If $limit is less than 0
     * @return self Returns this Fork instance for method chaining
     */
    public function concurrent(int $limit): self {
        if ($limit < 0) {
            throw new \InvalidArgumentException('The $limit argument must be greater than or equal to 0');
        }
        $this->concurrent = $limit;
        return $this;
    }

    /**
     * Runs the provided callables as forked tasks with optional concurrency control
     *
     * @param callable[]|\Iterator<int|string, callable> $callables The callables to run
     *
     * @return void
     */
    public function run(array|\Iterator $callables): void {
        $this->queue = (is_array($callables)) ? new \ArrayIterator($callables) : $callables;

        pcntl_async_signals(true);
        pcntl_signal(\SIGINT, fn() => $this->exit());
        pcntl_signal(\SIGQUIT, fn() => $this->exit());
        pcntl_signal(\SIGTERM, fn() => $this->exit());

        if (self::$mainPID === getmypid()) {
            self::$shutdownHandler->enable();
        } else {
            self::$shutdownHandler->disable(); // @codeCoverageIgnore
        }

        foreach ($this->queue as $key => $callable) {
            $task = new Task($callable, $key);
            $this->runningTasks[$key] = $this->runTask($task);

            if ($this->concurrent && count($this->runningTasks) >= $this->concurrent) {
                break;
            }
        }

        while (count($this->runningTasks) > 0) {
            foreach ($this->runningTasks as $key => $task) {
                if (!$task->hasExited()) {
                    continue;
                }

                if ($this->onParentAfter) {
                    ($this->onParentAfter)($task->getOutput());
                }

                unset($this->runningTasks[$key]);

                $qKey = $this->queue->key();
                $this->queue->next();
                if ($this->queue instanceof \ArrayAccess) {
                    unset($this->queue[$qKey]);
                }

                if (!$this->queue->valid()) {
                    continue;
                }

                $this->runningTasks[] = $this->runTask(new Task($this->queue->current(), $this->queue->key()));
            }
        }

        self::$shutdownHandler->disable();
        pcntl_signal(\SIGINT, \SIG_DFL);
        pcntl_signal(\SIGQUIT, \SIG_DFL);
        pcntl_signal(\SIGTERM, \SIG_DFL);
    }

    /**
     * Stops all running forked tasks by clearing the queue and sending SIGKILL
     *
     * @return void
     */
    public function stop(): void {
        $this->queue = new \ArrayIterator();
        foreach ($this->runningTasks as $task) {
            posix_kill($task->getPid(), \SIGKILL);
        }
    }

    /**
     * Sets the timeout in seconds for child processes
     *
     * @param int $seconds Timeout in seconds (>= 0)
     *
     * @throws \InvalidArgumentException If $seconds is less than 0
     * @return self Returns this Fork instance for method chaining
     */
    public function timeout(int $seconds): self {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('The $seconds argument must be greater than or equal to 0');
        }
        $this->timeout = $seconds;
        return $this;
    }

    /** @codeCoverageIgnore */
    protected function exit(): void {
        $this->stop();
        self::$shutdownHandler->disable();
        posix_kill(self::$mainPID, \SIGKILL);
    }

    /** @codeCoverageIgnore */
    protected function onChildTimeout(): void {
        throw new TimeoutException('Task timed out');
    }

    /**
     * Prepares output for sending through the socket, serializing if needed
     *
     * @param mixed $output The output to prepare
     *
     * @return string The prepared (possibly serialized) string output
     *
     * @codeCoverageIgnore
     */
    protected function prepareOutput(mixed $output): string {
        if (!is_string($output)) {
            $output = "\0\0serialized\0\0:" . serialize($output);
        }
        return $output;
    }

    /**
     * Runs a task in a forked child process and sets up communication sockets
     *
     * @param Task $task The task instance to run
     *
     * @return Task The configured task with PID and socket
     *
     * @throws ForkException If the fork could not be created
     */
    protected function runTask(Task $task): Task {
        if ($this->onParentBefore) {
            ($this->onParentBefore)();
        }

        [$socketChildToParent, $socketParentToChild] = Socket::createPair();

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new ForkException('Could not create fork'); // @codeCoverageIgnore
        } elseif ($pid === 0) {
            // @codeCoverageIgnoreStart
            self::$shutdownHandler->disable();
            $socketParentToChild->close();
            pcntl_signal(\SIGALRM, fn() => $this->onChildTimeout());
            pcntl_alarm($this->timeout);

            $throwable = null;
            try {
                if ($this->onChildBefore) {
                    ($this->onChildBefore)();
                }

                $output = $task();

                if ($this->onChildAfter) {
                    ($this->onChildAfter)($output);
                }
            } catch (\Throwable $t) {
                $output = new ThrowableContext($t);
                $throwable = $t;
            }

            pcntl_signal(\SIGALRM, \SIG_DFL);

            $socketChildToParent->write($this->prepareOutput($output));
            $socketChildToParent->close();
            if (self::$throwInFork && $throwable !== null) {
                throw $throwable;
            }
            exit();
            // @codeCoverageIgnoreEnd
        }

        $socketChildToParent->close();
        return $task->setPid($pid)->setSocket($socketParentToChild);
    }
}