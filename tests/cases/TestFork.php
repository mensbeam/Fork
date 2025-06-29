<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork\Test;
use MensBeam\Fork,
    Phake;
use MensBeam\Fork\{
    Socket,
    Task,
    ThrowableContext,
    TimeoutException
};
use PHPUnit\Framework\{
    TestCase,
    Attributes\CoversClass,
    Attributes\DataProvider
};


#[CoversClass('MensBeam\Fork')]
#[CoversClass('MensBeam\Fork\Socket')]
#[CoversClass('MensBeam\Fork\Task')]
#[CoversClass('MensBeam\Fork\ThrowableContext')]
class TestFork extends TestCase {
    #[DataProvider('provideAfterBefore')]
    public function testAfter(iterable $iterable): void {
        $parentResults = [];
        [$socketChildToParent, $socketParentToChild] = Socket::createPair();
        (new Fork())->after(parent: function(array $output) use(&$parentResults): void {
            $parentResults[] = $output['data'];
        }, child: function(mixed $output) use($socketChildToParent): void {
            $socketChildToParent->write(serialize($output) . "\0");
        })->run($iterable);

        $this->assertEquals(4, count($parentResults));
        sort($parentResults);
        $this->assertSame([ false, 42, 2112.12, 'ook' ], $parentResults);

        $childResults = '';
        foreach ($socketParentToChild->read() as $buffer) {
            $childResults .= $buffer;
        }
        $childResults = explode("\0", rtrim($childResults, "\0"));
        foreach ($childResults as $k => $v) {
            $childResults[$k] = unserialize($v);
        }

        $socketChildToParent->close();
        $socketParentToChild->close();

        $this->assertEquals(4, count($childResults));
        sort($childResults);
        $this->assertSame([ false, 42, 2112.12, 'ook' ], $childResults);
    }

    public static function provideAfterBefore(): \Generator {
        $gen = self::createGenerator([
            function () {
                return 'ook';
            },
            function () {
                return 42;
            },
            function () {
                return 2112.12;
            },
            function () {
                return false;
            }
        ]);

        foreach ($gen as $g) {
            yield $g;
        }
    }

    #[DataProvider('provideAfterBefore')]
    public function testBefore(iterable $iterable): void {
        $parentCount = 0;
        [$socketChildToParent, $socketParentToChild] = Socket::createPair();
        (new Fork())->before(parent: function() use(&$parentCount): void {
            $parentCount++;
        }, child: function() use($socketChildToParent): void {
            $socketChildToParent->write('.');
        })->run($iterable);

        $this->assertEquals(4, $parentCount);

        $childCount = '';
        foreach ($socketParentToChild->read() as $buffer) {
            $childCount .= $buffer;
        }
        $childCount = strlen($childCount);

        $socketChildToParent->close();
        $socketParentToChild->close();

        $this->assertEquals(4, $childCount);
    }

    public function testChildBeforeAndAfterCallbacks(): void {
        [$socketChildToParent, $socketParentToChild] = Socket::createPair();
        (new Fork())
            ->before(child: function() use ($socketChildToParent): void {
                $socketChildToParent->write('before|');
            })
            ->after(child: function($output) use ($socketChildToParent): void {
                $socketChildToParent->write('after');
            })
            ->run([
                function (): string {
                    return 'child task';
                }
            ]);

        $signal = '';
        foreach ($socketParentToChild->read() as $buf) {
            $signal .= $buf;
        }

        $socketChildToParent->close();
        $socketParentToChild->close();

        $this->assertSame('before|after', $signal);
    }

    public function testChildThrowableSerialization(): void {
        $outputs = [];
        (new Fork())->after(function(array $output) use (&$outputs): void {
            $outputs[] = $output['data'];
        })->run([
            function (): never {
                throw new \Exception('FAIL!');
            },
            function (): never {
                throw new \Error('FAIL!');
            }
        ]);

        $this->assertCount(2, $outputs);
        $this->assertInstanceOf(ThrowableContext::class, $outputs[0]);
        $this->assertInstanceOf(ThrowableContext::class, $outputs[1]);
        $this->assertSame('FAIL!', $outputs[0]->getMessage());
        $this->assertSame('FAIL!', $outputs[1]->getMessage());
    }

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

    #[DataProvider('provideTestFatalErrors')]
    public function testFatalErrors(string $throwableClassName, string $message, \Closure $closure): void {
        $this->expectException($throwableClassName);
        $this->expectExceptionMessage($message);
        $closure();
    }

    public static function provideTestFatalErrors(): iterable {
        $iterable = [
            [
                \InvalidArgumentException::class,
                'The $limit argument must be greater than or equal to 0',
                function (): void {
                    (new Fork())->concurrent(-1)->run([
                        function() {
                            echo "FAIL";
                        }
                    ]);
                }
            ],
            [
                \InvalidArgumentException::class,
                'The $seconds argument must be greater than or equal to 0',
                function (): void {
                    (new Fork())->timeout(-1)->run([
                        function() {
                            echo "FAIL";
                        }
                    ]);
                }
            ]
        ];

        foreach ($iterable as $i) {
            yield $i;
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

    public function testStop(): void {
        $successCount = $failCount = 0;
        $f = new Fork();
        $f->after(function(array $output) use(&$f, &$successCount, &$failCount) {
            $this->assertNull($output['data']);
            if ($output['success']) {
                if (++$successCount === 3) {
                    $f->stop();
                }
            } else {
                $failCount++;
            }
        })->run([
            function() { sleep(1); },
            function() { sleep(2); },
            function() { sleep(3); },
            function() { sleep(4); },
            function() { sleep(5); },
            function() { sleep(6); },
            function() { sleep(7); }
        ]);

        $this->assertEquals(3, $successCount);
        $this->assertEquals(4, $failCount);
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

    public function testTimeout(): void {
        $f = Phake::partialMock(Fork::class);
        Phake::when($f)->onChildTimeout->thenReturnCallback(function () {
            exit();
        });

        $start = microtime(true);
        $f->timeout(1)->run([
            function() { sleep(2); }
        ]);

        $this->assertTrue((microtime(true) - $start) < 2);
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