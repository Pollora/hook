<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\Exceptions;

/**
 * Thrown when the requested driver is unknown or cannot be used in this request.
 */
final class DriverUnavailable extends AsyncException
{
    /**
     * @param  list<string>  $known
     */
    public static function unknown(string $driver, array $known): self
    {
        return new self(sprintf("The asynchronous driver '%s' is not registered. Registered drivers: %s.", $driver, implode(', ', $known)));
    }

    public static function unavailable(string $driver): self
    {
        return new self(sprintf("The asynchronous driver '%s' is not available in this request.", $driver));
    }
}
