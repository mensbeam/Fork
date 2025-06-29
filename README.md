
[a]: https://www.php.net/manual/en/book.pcntl.php
[b]: https://www.php.net/manual/en/book.sockets.php
[c]: https://github.com/spatie/fork
[d]: https://code.mensbeam.com/MensBeam/SelfSealingCallable

# Fork

_Fork_ is a library for running jobs concurrently in PHP. It works by forking the main process into separate tasks using PHP's [`pcntl`][a] and [`sockets`][b] extensions. So, it should go without saying that this library will not work on Windows.

There is an existing library for forking processes, [spatie/fork][c]. This library on its surface is very similar, but internally it's quite a bit different. Unlike `spatie/fork`, `mensbeam/fork` does not return an array of returned values from all tasks after all of them have finished. Instead, it uses callbacks to handle output as each task completes. This design prevents potential memory exhaustion when running a large number of tasks, as we encountered when using `spatie/fork`. Handling output immediately as tasks finish is more scalable and efficient.

## Requirements

- PHP >= 8.1
- [ext-pcntl][a]
- [ext-sockets][b]
- [mensbeam/self-sealing-callable][d] ^1.0

## Installation

Install using Composer:

```bash
composer require mensbeam/fork
```

## Usage

Here is a simple example. `Fork->run()` can accept an array or an `\Iterator` of callables to run concurrently and will execute them. This means it can also accept a generator to continuously run tasks concurrently.

```php
use MensBeam\Fork;

function gen(): \Generator {
    foreach (range(1, 5) as $n) {
        yield function () use ($n) {
            $delay = rand(1, 5);
            sleep($delay);
            return [ $n, $delay ];
        };
    }
}

(new Fork())->after(function(array $output) {
    echo "{$output['data'][0]}: {$output['data'][1]}\n";
})->run(gen());
```

Example output:

```
4: 2
3: 2
2: 3
5: 3
1: 5
```

---

## Callbacks

You can use `before()` and `after()` to register callbacks to run **before or after each task**. You can register different callbacks for the parent and child processes.

---

## Concurrency

You can limit how many tasks run concurrently using `concurrent()`.

```php
use MensBeam\Fork;

(new Fork())->concurrent(2)->run([
    fn() => sleep(1),
    fn() => sleep(1),
    fn() => sleep(1)
]);
```

---

## Timeouts

You can set a timeout (in seconds) for each child process:

```php
use MensBeam\Fork;

(new Fork())->timeout(5)->run([
    fn() => sleep(10), // This will timeout
    fn() => sleep(2)
]);
```

When a task times out, a `TimeoutException` is thrown inside the child process, and a `ThrowableContext` object is sent back to the parent.

---

## Stopping tasks

You can stop all currently running and queued tasks from within an `after()` callback:

```php
use MensBeam\Fork;

$f = new Fork();
$f->after(function(array $output) use ($f) {
    if ($output['data'] === 'stop') {
        $f->stop();
    }
})->run([
    fn() => 'continue',
    fn() => 'stop',
    fn() => 'never runs'
]);
```

---

## ThrowableContext

When a task throws an exception or error in a child process, a `ThrowableContext` instance is returned to the parent.

### What it includes

- Error code
- File and line where the throwable was thrown
- Message
- Class type
- Optional stack trace (enabled via `Fork::$tracesInThrowableContexts`)
- Any previous throwable chain

---

### Example

```php
use MensBeam\Fork;

(new Fork())->after(function(array $output) {
    if ($output['data'] instanceof MensBeam\Fork\ThrowableContext) {
        echo "Child failed with: " . $output['data']->getMessage() . "\n";
    } else {
        echo "Child succeeded with: " . $output['data'] . "\n";
    }
})->run([
    fn() => throw new \RuntimeException("Something went wrong!"),
    fn() => "All good"
]);
```

---

## Handling traces

You can enable including stack traces in `ThrowableContext` objects:

```php
use MensBeam\Fork;
Fork::$tracesInThrowableContexts = true;
```

---

## Throwing exceptions inside the fork

By default, exceptions inside child processes are caught and sent as `ThrowableContext` objects. If you'd like them to be **thrown, crash the child process, and therefore be printed** (for debugging or crash reporting), set:

```php
use MensBeam\Fork;
Fork::$throwInFork = true;

(new Fork())->after(function(array $output) {
    echo "Ook!\n";
})->run([
    fn() => throw new \RuntimeException('Eek!'))
]);
```

Example output:

```
PHP Fatal error:  Uncaught RuntimeException: Eek! in /path/to/test.php:42
Ook!
```

---

## License

MIT License. See <LICENSE.md> and <AUTHORS.md> for details.