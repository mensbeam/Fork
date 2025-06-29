<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork;
use MensBeam\Fork;


/**
 * Provides detailed information about a Throwable instance
 *
 * This class encapsulates the context of a Throwable, including its code, file,
 * line, message, type, and previous throwable if any. It exists because
 * sometimes Throwables are too complex to be serialized and sent through a
 * socket.
 */
class ThrowableContext {
    /** The error code of the Throwable */
    protected int $code;

    /** The file in which the Throwable was thrown */
    protected string $file = '';

    /** The line number at which the Throwable was thrown */
    protected int $line;

    /** The error message of the Throwable */
    protected string $message = '';

    /** The previous ThrowableContext, if any */
    protected ?self $previous = null;

    /** The sanitized stack trace as an array */
    protected array $trace = [];

    /** The stack trace as a string */
    protected string $traceAsString = '';

    /** The class name of the Throwable */
    protected string $type = '';


    /**
     * Constructs a ThrowableContext instance from a Throwable
     *
     * @param \Throwable $throwable The Throwable instance to extract context from
     */
    public function __construct(\Throwable $throwable) {
        $this->code = $throwable->getCode();
        $this->file = $throwable->getFile();
        $this->line = $throwable->getLine();
        $this->message = $throwable->getMessage();
        $this->type = $throwable::class;

        $previous = $throwable->getPrevious();
        $this->previous = ($previous !== null) ? new self($previous) : null;

        if (Fork::$tracesInThrowableContexts) {
            $this->trace = $this->sanitizeTrace($throwable->getTrace());
            $this->traceAsString = $throwable->getTraceAsString();
        }
    }

    /**
     * Gets the error code associated with the Throwable
     *
     * @return int The error code
     */
    public function getCode(): int {
        return $this->code;
    }

    /**
     * Gets the file where the Throwable was thrown
     *
     * @return string The file name
     */
    public function getFile(): string {
        return $this->file;
    }

    /**
     * Gets the line number in the file where the Throwable was thrown
     *
     * @return int The line number
     */
    public function getLine(): int {
        return $this->line;
    }

    /**
     * Gets the message associated with the Throwable
     *
     * @return string The message
     */
    public function getMessage(): string {
        return $this->message;
    }

    /**
     * Gets the previous throwable context, if any
     *
     * @return ?self The previous throwable context or null
     */
    public function getPrevious(): ?self {
        return $this->previous;
    }

    /**
     * Gets the sanitized stack trace as an array
     *
     * @return array The sanitized stack trace
     */
    public function getTrace(): array {
        return $this->trace;
    }

    /**
     * Gets the stack trace as a string
     *
     * @return string The stack trace as a string
     */
    public function getTraceAsString(): string {
        return $this->traceAsString;
    }

    /**
     * Reconstructs and returns a new Throwable instance from the stored context
     *
     * @return \Throwable The reconstructed throwable
     */
    public function getThrowable(): \Throwable {
        $throwable = new ($this->type)($this->message, $this->code);

        $baseClass = new \ReflectionClass($this->type);
        while (!$baseClass->hasProperty('previous') && $baseClass = $baseClass->getParentClass()) {}

        foreach ([ 'file', 'line', 'previous', 'trace' ] as $prop) {
            $reflection = new \ReflectionProperty($baseClass->getName(), $prop);
            $reflection->setAccessible(true);
            $reflection->setValue($throwable, $this->$prop);
        }
        return $throwable;
    }

    /**
     * Gets the class name of the Throwable
     *
     * @return string The type (class name) of the throwable
     */
    public function getType(): string {
        return $this->type;
    }

    /**
     * Sanitizes a stack trace array by replacing unserializable arguments
     *
     * @param array $trace The original stack trace
     * @return array The sanitized stack trace
     */
    protected function sanitizeTrace(array $trace): array {
        foreach ($trace as &$frame) {
            if (!isset($frame['args'])) {
                continue;
            }
            $frame['args'] = $this->sanitizeTraceArgs($frame['args']);
        }

        return $trace;
    }

    /**
     * Recursively sanitizes trace arguments, replacing unserializable objects
     *
     * @param mixed $arg The argument to sanitize
     * @return mixed The sanitized argument
     */
    protected function sanitizeTraceArgs($arg) {
        if (is_object($arg)) {
            try {
                serialize($arg);
            } catch (\Throwable $t) {
                $arg = '[' . $arg::class . ']';
            }

            return $arg;
        } elseif (is_array($arg)) {
            foreach ($arg as $k => $v) {
                $arg[$k] = $this->sanitizeTraceArgs($v);
            }
            return $arg;
        }

        return $arg;
    }
}