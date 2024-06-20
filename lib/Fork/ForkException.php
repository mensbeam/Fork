<?php
/**
 * @license MIT
 * Copyright 2024 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\Fork;


/**
 * ForkException is the base exception class for all fork-related exceptions.
 *
 * This exception serves as a parent class for more specific exceptions that
 * occur during the forking process.
 */
class ForkException extends \RuntimeException {}
