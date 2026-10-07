<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

use Pollora\Hook\Async\Exceptions\UnresolvableHandler;
use Pollora\Hook\Domain\Contract\CallbackResolverInterface;

/**
 * Turns a handler into a string that travels in the payload, and back.
 *
 * The handler is carried by name, like a queued Laravel listener: the code that
 * runs is that of the current deployment, and no instance state is carried.
 *
 * Descriptors:
 *     'acme_sync_event'          a function
 *     'App\Hooks\Sync@handle'    a class method, static or not
 */
final readonly class CallableDescriptor
{
    private const string NAME_PATTERN = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/';

    public function __construct(
        private ?CallbackResolverInterface $resolver = null,
    ) {}

    /**
     * Describe a handler as a string.
     *
     * @param  callable|string|array  $callback  A function name, a [class or object, method] pair, a 'Class::method' string or an invokable object
     *
     * @throws UnresolvableHandler When the handler has no name to be resolved by later
     */
    public function describe(callable|string|array $callback): string
    {
        if ($callback instanceof \Closure) {
            throw UnresolvableHandler::closure();
        }

        if (is_string($callback)) {
            return $this->describeString($callback);
        }

        if (is_object($callback) && method_exists($callback, '__invoke')) {
            return $this->describeMethod($callback, '__invoke');
        }

        if (is_array($callback) && array_is_list($callback) && count($callback) === 2 && is_string($callback[1])
            && (is_object($callback[0]) || is_string($callback[0]))) {
            return $this->describeMethod($callback[0], $callback[1]);
        }

        throw UnresolvableHandler::unsupported($callback);
    }

    /**
     * Resolve a descriptor back to a callable.
     *
     * An instance method is called on a new instance, built by the callback
     * resolver when one is set.
     *
     * @throws UnresolvableHandler When the function, class or method no longer exists
     */
    public function resolve(string $descriptor): callable
    {
        if (! str_contains($descriptor, '@')) {
            if (! preg_match(self::NAME_PATTERN, $descriptor)) {
                throw UnresolvableHandler::invalidDescriptor($descriptor);
            }

            if (! function_exists($descriptor)) {
                throw UnresolvableHandler::missingFunction($descriptor);
            }

            return $descriptor;
        }

        [$className, $method] = explode('@', $descriptor, 2);

        if (! preg_match(self::NAME_PATTERN, $className) || ! preg_match(self::NAME_PATTERN, $method) || str_contains($method, '\\')) {
            throw UnresolvableHandler::invalidDescriptor($descriptor);
        }

        if (! class_exists($className)) {
            throw UnresolvableHandler::missingClass($className);
        }

        if (! method_exists($className, $method)) {
            throw UnresolvableHandler::missingMethod($className, $method);
        }

        if ((new \ReflectionMethod($className, $method))->isStatic()) {
            return [$className, $method];
        }

        try {
            $instance = $this->resolver instanceof CallbackResolverInterface
                ? $this->resolver->resolve($className)
                : new $className;
        } catch (\Throwable $throwable) {
            throw UnresolvableHandler::notInstantiable($className, $throwable);
        }

        /** @var callable */
        return [$instance, $method];
    }

    private function describeString(string $callback): string
    {
        if (str_contains($callback, '::')) {
            [$className, $method] = explode('::', $callback, 2);

            return $this->describeMethod($className, $method);
        }

        if (! preg_match(self::NAME_PATTERN, $callback)) {
            throw UnresolvableHandler::unsupported($callback);
        }

        return ltrim($callback, '\\');
    }

    private function describeMethod(object|string $target, string $method): string
    {
        if (is_object($target) && (new \ReflectionClass($target))->isAnonymous()) {
            throw UnresolvableHandler::anonymousClass();
        }

        $className = is_object($target) ? $target::class : ltrim($target, '\\');

        if (! preg_match(self::NAME_PATTERN, $className) || ! preg_match(self::NAME_PATTERN, $method) || str_contains($method, '\\')) {
            throw UnresolvableHandler::unsupported([$className, $method]);
        }

        return $className.'@'.$method;
    }
}
