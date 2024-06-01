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
    RuntimeException
};
use MensBeam\SelfSealingCallable;


class Fork {
    protected ?int $concurrent = null;
    protected static ?int $mainPID = null;
    protected ?\Closure $onChildAfter = null;
    protected ?\Closure $onChildBefore = null;
    protected ?\Closure $onParentAfter = null;
    protected ?\Closure $onParentBefore = null;
    /** @var \Iterator<int, Task> */
    protected \Iterator $queue;
    /** @var Task[] */
    protected array $runningTasks = [];
    protected static ?SelfSealingCallable $shutdownHandler = null;
    protected int $timeout = 3600;



    public function __construct() {
        if (self::$mainPID === null) {
            self::$mainPID = getmypid();
        }

        if (self::$shutdownHandler === null) {
            self::$shutdownHandler = new SelfSealingCallable(fn() => $this->stop());
            register_shutdown_function(self::$shutdownHandler);
        }
    }



    public function after(callable $parent = null, callable $child = null): self {
        if ($child !== null) {
            $this->onChildAfter = ($child instanceof \Closure) ? $child : \Closure::fromCallable($child);
        }
        if ($parent !== null) {
            $this->onParentAfter = ($parent instanceof \Closure) ? $parent : \Closure::fromCallable($parent);
        }
        return $this;
    }

    public function before(callable $parent = null, callable $child = null): self {
        if ($child !== null) {
            $this->onChildBefore = ($child instanceof \Closure) ? $child : \Closure::fromCallable($child);
        }
        if ($parent !== null) {
            $this->onParentBefore = ($parent instanceof \Closure) ? $parent : \Closure::fromCallable($parent);
        }
        return $this;
    }

    public function concurrent(int $concurrent): self {
        $this->concurrent = $concurrent;
        return $this;
    }

    public function run(array|\Iterator $callables): void {
        $this->queue = (is_array($callables)) ? new \ArrayIterator($callables) : $callables;

        pcntl_async_signals(true);
        pcntl_signal(\SIGINT, fn() => $this->exit());
        pcntl_signal(\SIGQUIT, fn() => $this->exit());
        pcntl_signal(\SIGTERM, fn() => $this->exit());
        pcntl_signal(\SIGALRM, fn() => $this->onChildTimeout());

        if (self::$mainPID === getmypid()) {
            self::$shutdownHandler->enable();
        } else {
            // Can't test coverage on this because it'd happen in a fork
            self::$shutdownHandler->disable(); // @codeCoverageIgnore
        }

        foreach ($this->queue as $key => $callable) {
            $task = new Task($callable, $key);
            $this->runningTasks[$key] = $this->runTask($task);

            // If the concurrency limit has been reached then break out of the queue
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
                if ($this->queue instanceof \ArrayAccess) {
                    unset($this->queue[$key]);
                }

                $this->queue->next();
                if (!$this->queue->valid()) {
                    continue;
                }

                $this->runningTasks[] = $this->runTask(new Task($this->queue->current(), $this->queue->key()));
            }
        }

        // Clean up
        self::$shutdownHandler->disable();
        pcntl_signal(\SIGINT, \SIG_DFL);
        pcntl_signal(\SIGQUIT, \SIG_DFL);
        pcntl_signal(\SIGTERM, \SIG_DFL);
        pcntl_signal(\SIGALRM, \SIG_DFL);
    }

    public function stop(): void {
        $this->queue = new \ArrayIterator();
        foreach ($this->runningTasks as $task) {
            posix_kill($task->getPid(), \SIGKILL);
        }
    }

    public function timeout(int $seconds): self {
        $this->timeout = $seconds;
        return $this;
    }


    protected function exit(): void {
        $this->stop();
        self::$shutdownHandler->disable();
        posix_kill(self::$mainPID, \SIGKILL);
    }

    protected function onChildTimeout(): void {
        throw new TimeoutException('Task timed out');
    }

    /** @codeCoverageIgnore */
    protected function prepareOutput(mixed $output): string {
        if (!is_string($output)) {
            $output = "\0\0serialized\0\0:" . serialize($output);
        }
        return $output;
    }

    protected function runTask(Task $task) {
        if ($this->onParentBefore) {
            ($this->onParentBefore)();
        }

        [$socketChildToParent, $socketParentToChild] = Socket::createPair();

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not create fork'); // @codeCoverageIgnore
        } elseif ($pid === 0) {
            // $pid is 0 if in child process
            // @codeCoverageIgnoreStart
            self::$shutdownHandler->disable();
            $socketParentToChild->close();
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

            $socketChildToParent->write($this->prepareOutput($output));
            $socketChildToParent->close();
            if ($throwable !== null) {
                throw $throwable;
            }
            exit();
            // @codeCoverageIgnoreEnd
        }

        $socketChildToParent->close();
        return $task->setPid($pid)->setSocket($socketParentToChild);
    }
}
