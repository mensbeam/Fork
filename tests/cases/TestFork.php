<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork\Test;
use MensBeam\Fork;
use MensBeam\Fork\{
    Socket,
    Task,
    ThrowableContext
};
use PHPUnit\Framework\{
    TestCase,
    Attributes\CoversClass,
    Attributes\DataProvider
};


#[CoversClass('MensBeam\Fork')]
#[CoversClass('MensBeam\Fork\Socket')]
#[CoversClass('MensBeam\Fork\Task')]
class TestFork extends TestCase {
    #[DataProvider('provideTestConcurrent')]
    public function testConcurrent(iterable $iterable): void {
        $results = [];
        (new Fork())->after(function(array $output) use(&$results): void {
            $results[] = $output['data'];
        })->concurrent(2)->run($iterable);

        $this->assertEquals(3, count($results));
        $this->assertEquals($results[0], $results[1]);
        $this->assertNotEquals($results[1], $results[2]);
    }

    public static function provideTestConcurrent(): \Generator {
        $gen = self::createGenerator([
            function () {
                sleep(1);
                return floor(microtime(true));
            },
            function () {
                sleep(1);
                return floor(microtime(true));
            },
            function () {
                sleep(1);
                return floor(microtime(true));
            }
        ]);

        foreach ($gen as $g) {
            yield $g;
        }
    }


    #[DataProvider('provideTestRun')]
    public function testRun(iterable $iterable): void {
        $range = range(1, 12);
        $start = microtime(true);
        (new Fork())->after(function (array $output) use (&$range): void {
            $this->assertTrue(in_array($output['data'], $range, true));
        })->run($iterable);
        // Tests that tasks actually run concurrently
        $this->assertTrue((microtime(true) - $start) < 1);
    }

    public static function provideTestRun(): \Generator {
        $tasks = [];
        foreach (range(1, 12) as $count) {
            $tasks[] = function () use ($count): int {
                usleep(100000);
                return $count;
            };
        }

        $gen = self::createGenerator($tasks);

        foreach ($gen as $g) {
            yield $g;
        }
    }

    #[DataProvider('provideTestRunNested')]
    public function testRunNested(iterable $iterable): void {
        $o = 'FAIL';
        (new Fork())->after(function(array $output) use(&$o) {
            $o = $output['data'];
        })->run($iterable);

        $this->assertSame('ook', $o);
    }

    public static function provideTestRunNested(): \Generator {
        $genInner = self::createGenerator([function (): string {
            return 'ook';
        }]);

        foreach ($genInner as $g) {
            $genOuter = self::createGenerator([
                function () use($g) {
                    $o = '';
                    (new Fork())->after(function(array $output) use(&$o) {
                        $o = $output['data'];
                    })->run($g[0]);

                    return $o;
                }
            ]);
            foreach ($genOuter as $g2) {
                yield $g2;
            }
        }
    }

    #[DataProvider('provideTestStopNested')]
    public function testStopNested(iterable $iterable): void {
        $o = 'FAIL';
        (new Fork())->after(function(array $output) use(&$o) {
            $o = $output['data'];
        })->run($iterable);

        $this->assertSame('eek', $o);
    }

    public static function provideTestStopNested(): \Generator {
        $genInner = self::createGenerator([
            function (): string {
                return 'ook';
            },
            function (): string {
                return 'eek';
            }
        ]);

        foreach ($genInner as $g) {
            $genOuter = self::createGenerator([
                function () use ($g) {
                    $f = new Fork();
                    $o = '';
                    $f->after(function(array $output) use($f, &$o) {
                        if ($output['data'] === 'ook') {
                            $f->stop();
                            return;
                        }

                        $o = $output['data'];
                    })->run($g[0]);

                    return $o;
                },
                function () {
                    return 'ack';
                }
            ]);
            foreach ($genOuter as $g2) {
                yield $g2;
            }
        }
    }


    protected static function createGenerator(array $iterable): \Generator {
        // Yield a plain array
        yield [ $iterable ];

        // Convert the array to an associative array by hashing the keys, then yield
        $array = [];
        foreach ($iterable as $key => $value) {
            $array[md5((string)$key)] = $value;
        }
        yield [ $array ];

        // Yield an ArrayIterator object
        yield [ new \ArrayIterator($iterable) ];

        // Yield a generator
        yield [ (function () use ($iterable): \Generator {
            foreach ($iterable as $i) {
                yield $i;
            }
        })() ];
    }
}