<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\Exceptions;

/**
 * Thrown when a hook argument cannot be carried to the deferred execution.
 */
final class UnsupportedArgument extends AsyncException
{
    /**
     * @param  string  $path  Position of the value, e.g. "argument #2" or "argument #2[items][0]"
     */
    public static function at(string $path, mixed $value): self
    {
        return new self(sprintf(
            '%s cannot be carried to an asynchronous action: %s is not supported. '
            .'Pass an ID, a scalar, an array, an enum, a date, a WordPress object or a JsonSerializable object.',
            ucfirst($path),
            get_debug_type($value),
        ));
    }
}
