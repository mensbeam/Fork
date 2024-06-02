[a]: https://www.php.net/manual/en/book.pcntl.php
[b]: https://www.php.net/manual/en/book.sockets.php
[c]: https://github.com/spatie/fork
[d]: https://code.mensbeam.com/MensBeam/SelfSealingCallable

# Fork #

_Fork_ is a library for running jobs concurrently in PHP. It works by forking the main process into separate tasks using php's [`pcntl`][a] and [`sockets`][b] extensions. So, it should go without saying that this library will not work in Windows.

There is an existing library for forking processes, [spatie/fork][c]. This library on its surface is very similar, but internally it's quite a bit different. _Fork_ also doesn't return an array of returned values from the task. When attempting to use `spatie/fork` ourselves we ran into many situations where if there were lots of jobs out of memory errors would occur because the output array would be enormous. It's better to handle output as tasks end in the main process instead of everything at the end.

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

Here is a simple example. `Fork->run` can be fed an array or an `\Iterator` of callables to run concurrently and will execute them. This means it can be fed a generator to continuously run tasks concurrently.

```php
use MensBeam\Fork;

function taskGenerator(): \Generator {
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
})->run(taskGenerator());
```

Output:

```
4: 2
3: 2
2: 3
5: 3
1: 5
```

This README is a WIP.
