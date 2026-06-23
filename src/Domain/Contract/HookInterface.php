<?php

declare(strict_types=1);

namespace Pollora\Hook\Domain\Contract;

/**
 * Base interface for WordPress hooks.
 *
 * Defines the common contract for both action and filter hooks,
 * including registration, removal, existence check, and callback listing.
 */
interface HookInterface
{
    /**
     * Add a hook callback.
     *
     * @param  string|array  $hooks  Hook name or array of hook names.
     * @param  callable|string|array  $callback  The callback function, class name, or [class, method] pair.
     * @param  int  $priority  Optional priority (default 10).
     * @param  int|null  $acceptedArgs  Optional argument count (auto-detected if null).
     */
    public function add(string|array $hooks, callable|string|array $callback, int $priority = 10, ?int $acceptedArgs = null): self;

    /**
     * Remove a hook callback.
     *
     * @param  string  $hook  Hook name.
     * @param  callable|string|array|null  $callback  Optional callback to remove (removes first if null).
     * @param  int  $priority  Optional priority (default 10).
     */
    public function remove(string $hook, callable|string|array|null $callback = null, int $priority = 10): self|false;

    /**
     * Check if a hook exists.
     *
     * @param  string  $hook  Hook name.
     */
    public function exists(string $hook): bool;

    /**
     * Get the registered callbacks for a hook.
     *
     * @param  string  $hook  Hook name.
     * @return array|null Callbacks array or null if hook doesn't exist.
     */
    public function callbacks(string $hook): ?array;
}
