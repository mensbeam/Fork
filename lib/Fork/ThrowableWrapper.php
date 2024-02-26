<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork;


class ThrowableWrapper {
    protected int $code;
    protected string $file = '';
    protected int $line;
    protected string $message = '';
    protected ?self $previous = null;




    public function __construct(\Throwable $throwable) {
        $this->code = $throwable->getCode();
        $this->file = $throwable->getFile();
        $this->message = $throwable->getMessage();

        $previous = $throwable->getPrevious();
        $this->previous = ($previous !== null) ? new self($previous) : null;
    }




    public function getCode(): int {
        return $this->code;
    }

    public function getFile(): string {
        return $this->file;
    }

    public function getLine(): int {
        return $this->line;
    }

    public function getMessage(): string {
        return $this->message;
    }

    public function getPrevious(): ?self {
        return $this->previous;
    }
}
