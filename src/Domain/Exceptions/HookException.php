<?php

declare(strict_types=1);

namespace Pollora\Hook\Domain\Exceptions;

/**
 * Base exception for hook registration.
 *
 * It extends \RuntimeException, which the hook threw before named exceptions
 * existed, so code catching \RuntimeException keeps working.
 */
class HookException extends \RuntimeException {}
