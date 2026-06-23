<?php

declare(strict_types=1);

namespace Pollora\Hook\Adapter\Out\WordPress;

use Pollora\Hook\Domain\Contract\Action as ActionContract;
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
}
