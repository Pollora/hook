<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

/**
 * The callback WordPress calls in place of an asynchronous handler: it queues the handler.
 *
 * @internal
 */
final class QueuedHandler
{
    /**
     * @param  string  $hook  Hook the handler is registered on
     * @param  int  $priority  Priority of the registration
     * @param  string|null  $descriptor  Handler descriptor, see CallableDescriptor; null for a closure, signed when its hook first fires
     * @param  callable|string|array  $handler  The handler as registered, run in place when queuing fails
     * @param  int  $registeredArgs  Argument count of the original registration
     * @param  int  $acceptedArgs  Hook arguments the handler takes, AsyncContext left out
     * @param  PendingAsync  $options  Options, read when the hook fires
     * @param  callable|null  $defaultCapture  The handler class's public capture() method, used when capture() is not called
     */
    public function __construct(
        public readonly string $hook,
        public readonly int $priority,
        private ?string $descriptor,
        public readonly mixed $handler,
        public readonly int $registeredArgs,
        public readonly int $acceptedArgs,
        public readonly PendingAsync $options,
        public readonly mixed $defaultCapture = null,
    ) {}

    public function __invoke(mixed ...$arguments): void
    {
        Async::dispatcher()->dispatch($this, $arguments);
    }

    /**
     * The handler descriptor, computed the first time it is needed.
     *
     * @throws Exceptions\UnresolvableHandler When a closure cannot be signed
     */
    public function descriptor(): string
    {
        return $this->descriptor ??= (new CallableDescriptor)->describe($this->handler);
    }

    /**
     * Names the handler in reports, without computing its descriptor.
     */
    public function label(): string
    {
        return $this->descriptor ?? 'closure';
    }

    /**
     * Identifies the registration, for the loop guard.
     */
    public function key(): string
    {
        return self::keyFor($this->hook, $this->descriptor(), $this->priority);
    }

    public static function keyFor(string $hook, string $descriptor, int $priority): string
    {
        return $hook.'|'.$descriptor.'|'.$priority;
    }
}
