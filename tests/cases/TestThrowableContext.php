<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork\Test;

use MensBeam\Fork;
use MensBeam\Fork\ThrowableContext;
use PHPUnit\Framework\{
    TestCase,
    Attributes\CoversClass
};

#[CoversClass('MensBeam\Fork\ThrowableContext')]
class TestThrowableContext extends TestCase {
    public function testBasicProperties(): void {
        $exception = new \RuntimeException('Ook!', 2112);
        $context = new ThrowableContext($exception);

        $this->assertSame(2112, $context->getCode());
        $this->assertSame('Ook!', $context->getMessage());
        $this->assertSame(\RuntimeException::class, $context->getType());
        $this->assertSame($exception->getFile(), $context->getFile());
        $this->assertSame($exception->getLine(), $context->getLine());
        $this->assertNull($context->getPrevious());
        $this->assertSame([], $context->getTrace());
        $this->assertSame('', $context->getTraceAsString());
    }

    public function testTraceEnabled(): void {
        Fork::$tracesInThrowableContexts = true;

        $context = new ThrowableContext(new \Exception('FAIL'));
        $this->assertIsArray($context->getTrace());
        $this->assertIsString($context->getTraceAsString());
        $this->assertNotEmpty($context->getTraceAsString());

        Fork::$tracesInThrowableContexts = false;
    }

    public function testPreviousThrowable(): void {
        $prev = new \InvalidArgumentException('Prev', 42);
        $main = new \RuntimeException('Main', 2112, $prev);

        $context = new ThrowableContext($main);
        $prevContext = $context->getPrevious();

        $this->assertInstanceOf(ThrowableContext::class, $prevContext);
        $this->assertSame(42, $prevContext->getCode());
        $this->assertSame('Prev', $prevContext->getMessage());
    }

    public function testGetThrowableRebuild(): void {
        $exception = new \RuntimeException('Ook!', 69);
        $context = new ThrowableContext($exception);

        $rebuilt = $context->getThrowable();

        $this->assertInstanceOf(\RuntimeException::class, $rebuilt);
        $this->assertSame('Ook!', $rebuilt->getMessage());
        $this->assertSame(69, $rebuilt->getCode());
    }

    public function testSanitizeTraceArgs(): void {
        $context = new \ReflectionClass(ThrowableContext::class);
        $method = $context->getMethod('sanitizeTraceArgs');
        $method->setAccessible(true);

        $tc = new ThrowableContext(new \Exception());

        $resource = fopen(__FILE__, 'r');
        $obj = new class() {
            public function __toString() { return 'ook'; }
        };
        fclose($resource);

        $args = [
            'string' => 'eek',
            'int' => 2112,
            'object' => $obj,
            'array' => ['nested' => $obj],
        ];

        $sanitized = $method->invoke($tc, $args);

        $this->assertSame('eek', $sanitized['string']);
        $this->assertSame(2112, $sanitized['int']);
        $this->assertSame('[' . $obj::class . ']', $sanitized['object']);
        $this->assertIsArray($sanitized['array']);
    }

    public function testSanitizeTraceContinue(): void {
        $tc = new ThrowableContext(new \Exception());

        $ref = new \ReflectionClass(ThrowableContext::class);
        $method = $ref->getMethod('sanitizeTrace');
        $method->setAccessible(true);

        // Provide trace with one frame missing 'args'
        $trace = [
            [ 'file' => 'ook.php', 'line' => 42 ],
            [ 'file' => 'eek.php', 'line' => 2112, 'args' => [ 'ack' ] ]
        ];

        $sanitized = $method->invoke($tc, $trace);

        $this->assertCount(2, $sanitized);
        $this->assertSame($trace[0]['file'], $sanitized[0]['file']);
        $this->assertSame($trace[0]['line'], $sanitized[0]['line']);

        // Check second frame args was sanitized (just simple check to confirm it was processed)
        $this->assertSame($trace[1]['file'], $sanitized[1]['file']);
        $this->assertSame($trace[1]['line'], $sanitized[1]['line']);
        $this->assertIsArray($sanitized[1]['args']);
    }
}