[a]: https://www.php.net/manual/en/book.pcntl.php
[b]: https://www.php.net/manual/en/book.sockets.php
[c]: https://github.com/spatie/fork
[d]: https://code.mensbeam.com/MensBeam/SelfSealingCallable

# Fork #

_Fork_ is a library for running jobs concurrently in PHP. It works by forking the main process into separate tasks using php's [`pcntl`][a] and [`sockets`][b] extensions. So, it should go without saying that this library will not work in Windows.

There is an existing library for forking processes, [spatie/fork][c]. This library on its surface is very similar, but internally it's quite a bit different. It also doesn't return an array of returned values from the task. When attempting to use `spatie/fork` ourselves we ran into many situations where if there were lots of jobs out of memory errors would occur because the output array would be enormous. It's better to handle output as tasks end in the main process instead of everything at the end anyway.

## Requirements ##

* PHP >= 8.1
* [ext-pcntl][a]
* [ext-sockets][b]
* [mensbeam/self-sealing-callable][d] ^1.0

## Installation ##

_Fork_ may be installed using Composer:

```bash
composer require mensbeam/fork
```

## Usage ##

Here is a simple example. `Fork->run` can be fed any number of tasks to run concurrently and will execute them.

```php
use MensBeam\Fork;

function taskFactory(int $delayInSeconds): \Closure {
    return function () use ($delayInSeconds) {
        sleep($delayInSeconds);
        return $delayInSeconds;
    };
}

(new Fork())->after(function(array $output) {
    echo "{$output['data']}\n";
})->run(
    taskFactory(5),
    taskFactory(3),
    taskFactory(1),
    taskFactory(2),
    taskFactory(4)
);
```

Output:

```
1
2
3
4
5
```

This README is a WIP.
