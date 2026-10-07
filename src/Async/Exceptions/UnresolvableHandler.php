<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\Exceptions;

/**
 * Thrown when a handler cannot be described for the queue, or resolved back from its descriptor.
 */
final class UnresolvableHandler extends AsyncException
{
    public static function closure(): self
    {
        return new self('A closure cannot be made asynchronous: pass a named function or a class method.');
    }

    public static function anonymousClass(): self
    {
        return new self('A method of an anonymous class cannot be made asynchronous: it has no name to be resolved by later.');
    }

    public static function unsupported(mixed $callback): self
    {
        return new self(sprintf('A %s callback cannot be made asynchronous: pass a named function or a class method.', get_debug_type($callback)));
    }

    public static function invalidDescriptor(string $descriptor): self
    {
        return new self(sprintf("'%s' is not a valid handler descriptor.", $descriptor));
    }

    public static function missingFunction(string $function): self
    {
        return new self(sprintf("The asynchronous handler '%s' no longer exists: function not found.", $function));
    }

    public static function missingClass(string $className): self
    {
        return new self(sprintf("The asynchronous handler class '%s' no longer exists.", $className));
    }

    public static function missingMethod(string $className, string $method): self
    {
        return new self(sprintf("The asynchronous handler method '%s::%s()' no longer exists.", $className, $method));
    }

    public static function notInstantiable(string $className, \Throwable $previous): self
    {
        return new self(sprintf("The asynchronous handler class '%s' could not be instantiated: %s", $className, $previous->getMessage()), 0, $previous);
    }
}
