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
        return new self('A closure can be made asynchronous only when laravel/serializable-closure is installed: install it, or pass a named function or a class method.');
    }

    public static function closureKey(): self
    {
        return new self('A closure cannot be made asynchronous without a signing key: WordPress salts (wp_salt()) are not available and no key was set with Async::useClosureKey().');
    }

    public static function unserializableClosure(string $reason): self
    {
        return new self(sprintf(
            'This closure cannot be made asynchronous: %s. A closure carries the object it is bound to and the variables it uses; '
            .'declare it static (static fn …) and pass IDs rather than objects, or use a class method.',
            rtrim($reason, '.'),
        ));
    }

    public static function invalidClosureSignature(): self
    {
        return new self('The signature of a queued closure is invalid: the payload was altered, or the signing key (WordPress salts) changed since it was queued.');
    }

    public static function unreadableClosure(string $reason): self
    {
        return new self('A queued closure cannot be read back: '.$reason);
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
