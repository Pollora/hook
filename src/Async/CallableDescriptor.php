<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

use Laravel\SerializableClosure\SerializableClosure;
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
 *     'closure:{hmac}:{base64}'  a closure, when laravel/serializable-closure is installed
 *
 * A closure is serialized with laravel/serializable-closure and signed with an
 * HMAC (Async::closureKey()); the signature is checked before anything is
 * unserialized. A closure made from a named function or method (strlen(...),
 * $object->method(...)) is described by that name instead.
 */
final readonly class CallableDescriptor
{
    private const string CLOSURE_PREFIX = 'closure:';

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
            return $this->describeClosure($callback);
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
     * Describe a handler now, or check that a closure can be described later.
     *
     * A closure is signed with a key derived from the WordPress salts, which a
     * plugin registering its hooks while it loads does not have yet: wp_salt()
     * is pluggable. Its serialization is checked at once; it is signed when its
     * hook first fires.
     *
     * @return string|null The descriptor, or null for a closure to describe later
     *
     * @throws UnresolvableHandler When the handler can never be described
     */
    public function describeOrDefer(callable|string|array $callback): ?string
    {
        if ($callback instanceof \Closure && $this->isClosureLiteral($callback)) {
            $this->serializeClosure($callback);

            return null;
        }

        return $this->describe($callback);
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
        if (str_starts_with($descriptor, self::CLOSURE_PREFIX)) {
            return $this->resolveClosure($descriptor);
        }

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

    private function describeClosure(\Closure $closure): string
    {
        $reflection = new \ReflectionFunction($closure);

        // A first-class callable of a named function or method travels by that name
        if (! $this->isClosureLiteral($closure)) {
            $object = $reflection->getClosureThis();
            $scope = $reflection->getClosureScopeClass();

            return match (true) {
                $object !== null => $this->describeMethod($object, $reflection->getName()),
                $scope !== null => $this->describeMethod($scope->getName(), $reflection->getName()),
                default => $this->describeString($reflection->getName()),
            };
        }

        $encoded = $this->serializeClosure($closure);

        return self::CLOSURE_PREFIX.hash_hmac('sha256', $encoded, Async::closureKey()).':'.$encoded;
    }

    /**
     * @return string The serialized closure, base64-encoded
     */
    private function serializeClosure(\Closure $closure): string
    {
        if (! class_exists(SerializableClosure::class)) {
            throw UnresolvableHandler::closure();
        }

        try {
            return base64_encode(serialize(new SerializableClosure($closure)));
        } catch (\Throwable $throwable) {
            throw UnresolvableHandler::unserializableClosure($throwable->getMessage());
        }
    }

    /**
     * Whether a closure is written as one, rather than made from a named function or method.
     */
    private function isClosureLiteral(\Closure $closure): bool
    {
        return str_starts_with((new \ReflectionFunction($closure))->getName(), '{closure');
    }

    private function resolveClosure(string $descriptor): \Closure
    {
        $parts = explode(':', $descriptor, 3);

        if (count($parts) !== 3 || ! preg_match('/^[0-9a-f]{64}$/', $parts[1])) {
            throw UnresolvableHandler::invalidDescriptor(substr($descriptor, 0, 80));
        }

        [, $signature, $encoded] = $parts;

        if (! hash_equals(hash_hmac('sha256', $encoded, Async::closureKey()), $signature)) {
            throw UnresolvableHandler::invalidClosureSignature();
        }

        if (! class_exists(SerializableClosure::class)) {
            throw UnresolvableHandler::unreadableClosure('laravel/serializable-closure is no longer installed.');
        }

        $serialized = base64_decode($encoded, true);

        try {
            // Signed by us above: the payload is the one this site queued
            $closure = $serialized === false ? null : unserialize($serialized);
        } catch (\Throwable $throwable) {
            throw UnresolvableHandler::unreadableClosure($throwable->getMessage());
        }

        if (! $closure instanceof SerializableClosure) {
            throw UnresolvableHandler::unreadableClosure('the payload does not hold a serialized closure.');
        }

        return $closure->getClosure();
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
