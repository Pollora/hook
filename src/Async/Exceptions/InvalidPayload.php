<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\Exceptions;

/**
 * Thrown when a stored payload cannot be read back.
 */
final class InvalidPayload extends AsyncException
{
    public static function notJson(): self
    {
        return new self('The asynchronous payload is not valid JSON.');
    }

    public static function unsupportedVersion(mixed $version): self
    {
        return new self(sprintf('The asynchronous payload version %s is not supported.', json_encode($version)));
    }

    public static function unreadableArgument(string $reason): self
    {
        return new self('An argument of the asynchronous payload cannot be rebuilt: '.$reason);
    }

    public static function invalidField(string $field): self
    {
        return new self(sprintf("The asynchronous payload field '%s' is missing or invalid.", $field));
    }
}
