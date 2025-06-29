<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork\Test;

use MensBeam\Fork\{
    ForkException,
    Socket,
    Task
};
use PHPUnit\Framework\{
    TestCase,
    Attributes\CoversClass
};

#[CoversClass('MensBeam\Fork\Task')]
class TestTask extends TestCase {
    public function testInvoke(): void {
        $t = new Task(function (): string {
            return 'ook';
        });

        $this->assertSame('ook', $t());
    }

    public function testGetOutputReturnsEarly(): void {
        $expected = [ 'success' => true, 'data' => 'result' ];

        $t = new Task(function () {});
        $ref = new \ReflectionProperty(Task::class, 'output');
        $ref->setAccessible(true);
        $ref->setValue($t, $expected);

        $output = $t->getOutput();
        $this->assertSame($expected, $output);
    }

    public function testHasExitedReturnsEarly(): void {
        $t = new Task(function () {});

        $ref = new \ReflectionProperty(Task::class, 'exited');
        $ref->setAccessible(true);
        $ref->setValue($t, true);

        $this->assertTrue($t->hasExited());
    }

    public function testHasExitedThrowsForkException(): void {
        $t = new Task(function () {});

        // Set an invalid PID to simulate waitpid error
        $t->setPid(-999);

        $this->expectException(ForkException::class);
        $t->hasExited();
    }

    public function testSetPidAndSetSocket(): void {
        $t = new Task(function () {});
        $socket = $this->createMock(Socket::class);

        $this->assertSame($t, $t->setPid(1234));
        $this->assertSame(1234, $t->getPid());

        $this->assertSame($t, $t->setSocket($socket));
        // We cannot directly get the socket because it's protected, but we can at least assert no error and chain.
    }
}