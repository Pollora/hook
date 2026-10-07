<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

/**
 * The callback WordPress calls in place of an asynchronous handler: it queues the handler.
 *
 * @internal
 */
final readonly class QueuedHandler
{
    /**
     * @param  string  $hook  Hook the handler is registered on
     * @param  int  $priority  Priority of the registration
     * @param  string  $descriptor  Handler descriptor, see CallableDescriptor
     * @param  callable|string|array  $handler  The handler as registered, run in place when queuing fails
     * @param  int  $registeredArgs  Argument count of the original registration
     * @param  int  $acceptedArgs  Hook arguments the handler takes, AsyncContext left out
     * @param  PendingAsync  $options  Options, read when the hook fires
     * @param  callable|null  $defaultCapture  The handler class's public capture() method, used when capture() is not called
     */
    public function __construct(
        public string $hook,
        public int $priority,
        public string $descriptor,
        public mixed $handler,
        public int $registeredArgs,
        public int $acceptedArgs,
        public PendingAsync $options,
        public mixed $defaultCapture = null,
    ) {}

    public function __invoke(mixed ...$arguments): void
    {
        Async::dispatcher()->dispatch($this, $arguments);
    }

    /**
     * Identifies the registration, for the loop guard.
     */
    public function key(): string
    {
        return self::keyFor($this->hook, $this->descriptor, $this->priority);
    }

    public static function keyFor(string $hook, string $descriptor, int $priority): string
    {
        return $hook.'|'.$descriptor.'|'.$priority;
    }
}
