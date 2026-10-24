<?php

declare(strict_types=1);

namespace Pollora\Hook\Domain\Exceptions;

/**
 * Thrown when a class is registered as a hook callback but has no method named after the hook.
 */
final class HookMethodNotFound extends HookException
{
    /**
     * @param  string  $className  The class registered as the callback
     * @param  string  $method  The method derived from the hook name
     */
    public static function forMethod(string $className, string $method): self
    {
        return new self(sprintf(
            "Method '%s' not found in class '%s'. A class registered without a method is called through the method named after the hook in camelCase: "
            ."add a public method '%s()' to the class, or register a [class, method] pair.",
            $method,
            $className,
            $method,
        ));
    }
}
