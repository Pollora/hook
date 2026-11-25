<?php

declare(strict_types=1);

namespace Pollora\Hook\Domain\Contract;

/**
 * Base interface for WordPress hooks.
 *
 * Defines the common contract for both action and filter hooks,
 * including registration, removal, existence check, and callback listing.
 *
 * @phpstan-type HookCallback callable|string|array<array-key, mixed>
 * @phpstan-type HookRegistration array{hook: string, callback: HookCallback, priority: int, args: int, handler?: HookCallback}
 */
interface HookInterface
{
    /**
     * Add a hook callback.
     *
     * @param  string|list<string>  $hooks  Hook name or array of hook names.
     * @param  HookCallback  $callback  The callback function, class name, or [class, method] pair.
     * @param  int  $priority  Optional priority (default 10).
     * @param  int|null  $acceptedArgs  Optional argument count (auto-detected if null).
     */
    public function add(string|array $hooks, callable|string|array $callback, int $priority = 10, ?int $acceptedArgs = null): self;

    /**
     * Remove a hook callback.
     *
     * @param  string  $hook  Hook name.
     * @param  HookCallback|null  $callback  Optional callback to remove (removes first if null).
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
     * @return list<HookRegistration>|null Callbacks array or null if hook doesn't exist.
     */
    public function callbacks(string $hook): ?array;
}
