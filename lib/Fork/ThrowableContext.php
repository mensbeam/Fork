<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork;


/**
 * Provides detailed information about a Throwable instance.
 *
 * This class encapsulates the context of a Throwable, including its code, file,
 * line, message, type, and previous throwable if any. It exists because
 * sometimes Throwables are too complex to be serialized and sent through a
 * socket.
 */
class ThrowableContext {
    protected int $code;
    protected string $file = '';
    protected int $line;
    protected string $message = '';
    protected ?self $previous = null;
    protected string $type = '';




    /**
     * Constructs a ThrowableContext instance from a Throwable.
     *
     * @param \Throwable $throwable The Throwable instance to extract context from.
     */
    public function __construct(\Throwable $throwable) {
        $this->code = $throwable->getCode();
        $this->file = $throwable->getFile();
        $this->message = $throwable->getMessage();
        $this->type = $throwable::class;

        $previous = $throwable->getPrevious();
        $this->previous = ($previous !== null) ? new self($previous) : null;
    }



    /**
     * Gets the error code associated with the Throwable.
     *
     * @return int The error code.
     */
    public function getCode(): int {
        return $this->code;
    }

    /**
     * Gets the file where the Throwable was thrown.
     *
     * @return string The file name.
     */
    public function getFile(): string {
        return $this->file;
    }

    /**
     * Gets the line number in the file where the Throwable was thrown.
     *
     * @return int The line number.
     */
    public function getLine(): int {
        return $this->line;
    }

    /**
     * Gets the message associated with the Throwable.
     *
     * @return string The message.
     */
    public function getMessage(): string {
        return $this->message;
    }

    /**
     * Gets the previous throwable if any.
     *
     * @return ?self The previous throwable context, or null if none exists.
     */
    public function getPrevious(): ?self {
        return $this->previous;
    }
}
