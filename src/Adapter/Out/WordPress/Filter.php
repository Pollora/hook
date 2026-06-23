<?php

declare(strict_types=1);

namespace Pollora\Hook\Adapter\Out\WordPress;

use Pollora\Hook\Domain\Contract\Filter as FilterContract;
use Pollora\Hook\Domain\Service\AbstractHook;

/**
 * WordPress adapter for Filter hooks.
 *
 * Implements the Filter contract by delegating to WordPress
 * `add_filter()`, `remove_filter()`, and `apply_filters()` functions.
 */
class Filter extends AbstractHook implements FilterContract
{
    /**
     * Apply a WordPress filter hook.
     *
     * @param  string  $hook  The filter hook name to apply.
     * @param  mixed  $value  The value to filter.
     * @param  mixed  ...$args  Additional arguments to pass to the filter.
     * @return mixed The filtered value.
     */
    public function apply(string $hook, mixed $value, ...$args): mixed
    {
        return apply_filters($hook, $value, ...$args);
    }

    /**
     * Register a hook event with WordPress.
     */
    protected function addHookEvent(string $hook, callable|string|array $callback, int $priority, int $acceptedArgs): void
    {
        parent::addHookEvent($hook, $callback, $priority, $acceptedArgs);
        add_filter($hook, $callback, $priority, $acceptedArgs);
    }

    /**
     * Unregister a hook event from WordPress.
     */
    protected function removeHookEvent(string $hook, callable|string|array $callback, int $priority): void
    {
        remove_filter($hook, $callback, $priority);
    }
}
