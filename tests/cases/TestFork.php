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
    ThrowableWrapper
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
    public function testConcurrent(): void {
        $results = [];
        (new Fork())->after(function(array $output) use(&$results): void {
            $results[] = $output['data'];
        })->concurrent(2)->run(
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
        );

        $this->assertEquals($results[0], $results[1]);
        $this->assertNotEquals($results[1], $results[2]);
    }

    public function testRun(): void {
        $range = range(1, 12);
        $tasks = [];
        foreach ($range as $count) {
            $tasks[] = function () use ($count): int {
                usleep(100000);
                return $count;
            };
        }

        $start = microtime(true);
        (new Fork())->after(function (array $output) use (&$range): void {
            $this->assertTrue(in_array($output['data'], $range, true));
            unset($range[$output['data'] - 1]);
        })->run(...$tasks);
        // Tests that tasks actually run concurrently
        $this->assertTrue((microtime(true) - $start) < 1);
    }

    public function testRunNested(): void {
        $o = 'FAIL';
        (new Fork())->after(function(array $output) use(&$o) {
            $o = $output['data'];
        })->run(
            function () {
                $o = '';
                (new Fork())->after(function(array $output) use(&$o) {
                    $o = $output['data'];
                })->run(function (): string {
                    return 'ook';
                });

                return $o;
            }
        );

        $this->assertSame('ook', $o);
    }

    public function testStopNested(): void {
        $o = 'FAIL';
        (new Fork())->after(function(array $output) use(&$o) {
            $o = $output['data'];
        })->run(
            function () {
                $f = new Fork();
                $o = '';
                $f->after(function(array $output) use($f, &$o) {
                    if ($output['data'] === 'ook') {
                        $f->stop();
                        return;
                    }

                    $o = $output['data'];
                })->run(
                    function (): string {
                        return 'ook';
                    },
                    function (): string {
                        return 'eek';
                    }
                );

                return $o;
            },
            function () {
                return 'ack';
            }
        );

        $this->assertSame('eek', $o);
    }
}