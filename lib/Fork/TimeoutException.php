<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork;


/**
 * TimeoutException is thrown when a forked process times out.
 *
 * This exception is used to indicate that a forked process has exceeded its
 * allotted execution time.
 */
class TimeoutException extends ForkException {}
