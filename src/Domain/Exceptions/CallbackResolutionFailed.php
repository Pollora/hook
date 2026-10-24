<?php

declare(strict_types=1);

namespace Pollora\Hook\Domain\Exceptions;

/**
 * Thrown when the class of a hook callback cannot be instantiated or its method cannot be bound.
 */
final class CallbackResolutionFailed extends HookException
{
    /**
     * @param  string  $className  The class of the callback
     * @param  \Throwable  $previous  Why the class could not be resolved; its code is kept
     */
    public static function forClass(string $className, \Throwable $previous): self
    {
        // A hook error already says how to fix it; any other error comes from building the class
        $hint = $previous instanceof HookException
            ? ''
            : ' Check that the class exists and can be built without arguments, or set a callback resolver (a container) that knows how to build it.';

        return new self(
            sprintf("Failed to resolve '%s': %s%s", $className, self::sentence($previous->getMessage()), $hint),
            (int) $previous->getCode(),
            $previous,
        );
    }

    private static function sentence(string $message): string
    {
        $message = rtrim($message);

        return $message === '' || str_ends_with($message, '.') ? $message : $message.'.';
    }
}
