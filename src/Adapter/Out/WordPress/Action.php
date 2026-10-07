<?php

declare(strict_types=1);

namespace Pollora\Hook\Adapter\Out\WordPress;

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\CallableDescriptor;
use Pollora\Hook\Async\Exceptions\UnresolvableHandler;
use Pollora\Hook\Async\HandlerArguments;
use Pollora\Hook\Async\PendingAsync;
use Pollora\Hook\Async\QueuedHandler;
use Pollora\Hook\Domain\Contract\Action as ActionContract;
use Pollora\Hook\Domain\Contract\CallbackResolverInterface;
use Pollora\Hook\Domain\Service\AbstractHook;

/**
 * WordPress adapter for Action hooks.
 *
 * Implements the Action contract by delegating to WordPress
 * `add_action()`, `remove_action()`, and `do_action()` functions.
 */
class Action extends AbstractHook implements ActionContract
{
    /**
     * Listen to the internal hook of asynchronous actions as soon as the service
     * exists, so a WP-Cron request runs queued handlers even when the code that
     * made them asynchronous does not run in that request.
     */
    public function __construct()
    {
        Async::listen();
    }

    /**
     * Set the callback resolver, also used to build asynchronous handlers at execution time.
     */
    public function setCallbackResolver(CallbackResolverInterface $resolver): void
    {
        parent::setCallbackResolver($resolver);
        Async::useCallbackResolver($resolver);
    }

    /**
     * Execute a WordPress action hook.
     *
     * @param  string  $hook  The action hook name to execute.
     * @param  mixed  ...$args  Arguments to pass to the hook callbacks.
     */
    public function do(string $hook, ...$args): self
    {
        do_action($hook, ...$args);

        return $this;
    }

    /**
     * Make the hooks of the last add() call asynchronous.
     *
     * Each callback is replaced by one that queues it, at the same priority.
     * Every handler is described before anything is replaced, so a handler that
     * cannot be queued leaves the registration untouched.
     *
     * @param  string|list<string>  $except  Hooks that stay synchronous
     *
     * @throws \LogicException When no add() call precedes
     * @throws UnresolvableHandler When a handler cannot be queued (a closure, an anonymous class)
     */
    public function async(string|array $except = []): PendingAsync
    {
        if ($this->lastRegistrations === []) {
            throw new \LogicException('async() must directly follow add(): there is no registration to make asynchronous.');
        }

        Async::listen();

        $registrations = $this->lastRegistrations;
        $this->lastRegistrations = [];

        $descriptor = new CallableDescriptor;
        $descriptors = array_map(fn (array $registration): ?string => $descriptor->describeOrDefer($registration['callback']), $registrations);

        $pending = new PendingAsync($this->makeSynchronous(...));

        foreach ($registrations as $index => $registration) {
            $queued = new QueuedHandler(
                hook: $registration['hook'],
                priority: $registration['priority'],
                descriptor: $descriptors[$index],
                handler: $registration['callback'],
                registeredArgs: $registration['args'],
                acceptedArgs: HandlerArguments::hookArgumentCount($registration['callback'], $registration['args']),
                options: $pending,
                defaultCapture: $this->defaultCapture($registration['callback']),
            );

            $this->swapCallback($queued->hook, $queued->priority, $queued->handler, $queued, $queued->acceptedArgs, $queued->handler);
            $pending->track($queued);
        }

        return $except === [] ? $pending : $pending->except($except);
    }

    /**
     * Register a hook event with WordPress.
     */
    protected function addHookEvent(string $hook, callable|string|array $callback, int $priority, int $acceptedArgs): void
    {
        parent::addHookEvent($hook, $callback, $priority, $acceptedArgs);
        add_action($hook, $callback, $priority, $acceptedArgs);
    }

    /**
     * Unregister a hook event from WordPress.
     */
    protected function removeHookEvent(string $hook, callable|string|array $callback, int $priority): void
    {
        remove_action($hook, $callback, $priority);
    }

    /**
     * The public capture() method of a handler class, used when capture() is not called.
     *
     * @return array{0: object, 1: 'capture'}|null
     */
    private function defaultCapture(callable|string|array $callback): ?array
    {
        if (! is_array($callback) || ! is_object($callback[0] ?? null) || ($callback[1] ?? null) === 'capture') {
            return null;
        }

        if (! method_exists($callback[0], 'capture') || ! (new \ReflectionMethod($callback[0], 'capture'))->isPublic()) {
            return null;
        }

        return [$callback[0], 'capture'];
    }

    /**
     * Put an asynchronous handler back to synchronous execution.
     */
    private function makeSynchronous(QueuedHandler $queued): void
    {
        $this->swapCallback($queued->hook, $queued->priority, $queued, $queued->handler, $queued->registeredArgs, null);
    }

    /**
     * Replace a callback, in WordPress and in the tracked registrations.
     */
    private function swapCallback(string $hook, int $priority, callable|string|array $current, callable|string|array $replacement, int $acceptedArgs, callable|string|array|null $handler): void
    {
        remove_action($hook, $current, $priority);
        add_action($hook, $replacement, $priority, $acceptedArgs);
        $this->replaceTrackedCallback($hook, $priority, $current, $replacement, $acceptedArgs, $handler);
    }
}
