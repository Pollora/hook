<?php

declare(strict_types=1);

namespace Pollora\Hook;

use Pollora\Hook\Adapter\Out\WordPress\Action as WordPressAction;

/**
 * Static facade for WordPress Action hooks (standalone usage).
 *
 * Provides a clean, container-free entry point for action management.
 * When the Pollora framework is available, prefer the Laravel facade
 * `Pollora\Support\Facades\Action` for DI container support.
 *
 * Usage:
 *     Action::add('init', fn () => doSomething());
 *     Action::do('my_custom_action', $arg1);
 *     Action::remove('init', $callback);
 *
 * @see WordPressAction The underlying WordPress adapter.
 */
class Action
{
    private static ?WordPressAction $instance = null;

    /**
     * Add an action hook.
     *
     * @param  string|array  $hooks  Hook name(s).
     * @param  callable|string|array  $callback  Callback.
     * @param  int  $priority  Priority (default 10).
     * @param  int|null  $acceptedArgs  Argument count (auto-detected if null).
     */
    public static function add(string|array $hooks, callable|string|array $callback, int $priority = 10, ?int $acceptedArgs = null): void
    {
        self::getInstance()->add($hooks, $callback, $priority, $acceptedArgs);
    }

    /**
     * Execute a WordPress action hook.
     *
     * @param  string  $hook  The action hook name.
     * @param  mixed  ...$args  Arguments to pass to callbacks.
     */
    public static function do(string $hook, mixed ...$args): void
    {
        self::getInstance()->do($hook, ...$args);
    }

    /**
     * Remove an action hook.
     *
     * @param  string  $hook  Hook name.
     * @param  callable|string|array|null  $callback  Callback to remove.
     * @param  int  $priority  Priority (default 10).
     */
    public static function remove(string $hook, callable|string|array|null $callback = null, int $priority = 10): bool
    {
        return self::getInstance()->remove($hook, $callback, $priority) !== false;
    }

    /**
     * Check if an action hook exists.
     *
     * @param  string  $hook  Hook name.
     */
    public static function exists(string $hook): bool
    {
        return self::getInstance()->exists($hook);
    }

    /**
     * Get registered callbacks for an action hook.
     *
     * @param  string  $hook  Hook name.
     * @return array|null Callbacks or null.
     */
    public static function callbacks(string $hook): ?array
    {
        return self::getInstance()->callbacks($hook);
    }

    private static function getInstance(): WordPressAction
    {
        if (! self::$instance instanceof WordPressAction) {
            if (class_exists(\Pollora\Support\Facades\Action::class)) {
                trigger_error(
                    'Using Pollora\Hook\Action directly is not recommended when the Pollora framework is available. '
                    .'Use the Pollora\Support\Facades\Action facade instead for DI container support.',
                    E_USER_NOTICE
                );
            }

            self::$instance = new WordPressAction;
        }

        return self::$instance;
    }
}
