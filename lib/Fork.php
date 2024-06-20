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
 * creating child processes.
 */
class Fork {
    protected ?int $concurrent = null;
    protected static ?int $mainPID = null;
    protected ?\Closure $onChildAfter = null;
    protected ?\Closure $onChildBefore = null;
    protected ?\Closure $onParentAfter = null;
    protected ?\Closure $onParentBefore = null;
    /** @var \Iterator<int|string, Task> */
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

        $this->queue = new \ArrayIterator();
    }




    /**
     * Registers callables to be run after each task has completed, either within the
     * child or parent process.
     *
     * This method allows you to specify actions that should be executed after each
     * task has completed. The provided callables will be invoked in their respective
     * processes (parent or child) after each task.
     *
     * @param callable|null $parent A callable to run in the parent process after the task has completed
     * @param callable|null $child A callable to run in the child process after the task has completed
     *
     * @return MensBeam\Fork Returns the instance of the Fork class for method chaining.
     */
    public function after(callable $parent = null, callable $child = null): self {
        if ($child !== null) {
            $this->onChildAfter = ($child instanceof \Closure) ? $child : \Closure::fromCallable($child);
        }
        if ($parent !== null) {
            $this->onParentAfter = ($parent instanceof \Closure) ? $parent : \Closure::fromCallable($parent);
        }
        return $this;
    }

    /**
     * Registers callables to be run before each task has started, either within the
     * child or parent process.
     *
     * This method allows you to specify actions that should be executed before each
     * task has started. The provided callables will be invoked in their respective
     * processes (parent or child) before each task.
     *
     * @param callable|null $parent A callable to run in the parent process before the task has started
     * @param callable|null $child A callable to run in the child process before the task has started
     *
     * @return MensBeam\Fork Returns the instance of the Fork class for method chaining.
     */
    public function before(callable $parent = null, callable $child = null): self {
        if ($child !== null) {
            $this->onChildBefore = ($child instanceof \Closure) ? $child : \Closure::fromCallable($child);
        }
        if ($parent !== null) {
            $this->onParentBefore = ($parent instanceof \Closure) ? $parent : \Closure::fromCallable($parent);
        }
        return $this;
    }

    /**
     * Sets the maximum number of tasks to run concurrently.
     *
     * This method allows you to specify the maximum number of forked tasks that can
     * be executed at the same time. Once the limit is reached, additional tasks will
     * be queued until a running task completes.
     *
     * @param int $limit The maximum number of concurrent tasks
     *
     * @throws \InvalidArgumentException If the $seconds argument is less than 0.
     * @return MensBeam\Fork Returns the instance of the Fork class for method chaining.
     */
    public function concurrent(int $limit): self {
        if ($limit < 0) {
            throw new \InvalidArgumentException('The $limit argument must be greater than or equal to 0');
        }
        $this->concurrent = $limit;
        return $this;
    }

    /**
     * Runs the provided callables concurrently by forking a process for each callable.
     *
     * The provided callables will be executed in their own child processes
     * concurrently. The parent process will wait for all children to complete before
     * continuing.
     *
     * @param callable[]|\Iterator<int|string, callable> $callables The callables to run in forked process
     */
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

        // Clean up
        self::$shutdownHandler->disable();
        pcntl_signal(\SIGINT, \SIG_DFL);
        pcntl_signal(\SIGQUIT, \SIG_DFL);
        pcntl_signal(\SIGTERM, \SIG_DFL);
        pcntl_signal(\SIGALRM, \SIG_DFL);
    }

    /**
     * Stops all forked tasks immediately.
     *
     * This method clears the task queue and sends a SIGKILL signal to all currently
     * running tasks, effectively terminating them.
     */
    public function stop(): void {
        $this->queue = new \ArrayIterator();
        foreach ($this->runningTasks as $task) {
            posix_kill($task->getPid(), \SIGKILL);
        }
    }

    /**
     * Sets the timeout for forked processes.
     *
     * This method configures a timeout duration in seconds for the forked processes.
     * If the specified time is reached, a SIGALRM signal will be sent to the process
     * to trigger a timeout.
     *
     * @param int $seconds The number of seconds before timing out the process. Must be greater than or equal to 0.
     *
     * @throws \InvalidArgumentException If the $seconds argument is less than 0.
     * @return MensBeam\Fork Returns the instance of the Fork class for method chaining.
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
            throw new ForkException('Could not create fork'); // @codeCoverageIgnore
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
