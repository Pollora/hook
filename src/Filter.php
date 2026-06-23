<?php

declare(strict_types=1);

namespace Pollora\Hook;

use Pollora\Hook\Adapter\Out\WordPress\Filter as WordPressFilter;

/**
 * Static facade for WordPress Filter hooks (standalone usage).
 *
 * Provides a clean, container-free entry point for filter management.
 * When the Pollora framework is available, prefer the Laravel facade
 * `Pollora\Support\Facades\Filter` for DI container support.
 *
 * Usage:
 *     Filter::add('the_content', fn (string $c): string => $c . '<p>!</p>');
 *     $value = Filter::apply('my_filter', $input);
 *     Filter::remove('the_content', $callback);
 *
 * @see WordPressFilter The underlying WordPress adapter.
 */
class Filter
{
    private static ?WordPressFilter $instance = null;

    /**
     * Add a filter hook.
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
     * Apply a WordPress filter hook.
     *
     * @param  string  $hook  The filter hook name.
     * @param  mixed  $value  The value to filter.
     * @param  mixed  ...$args  Additional arguments.
     * @return mixed The filtered value.
     */
    public static function apply(string $hook, mixed $value, mixed ...$args): mixed
    {
        return self::getInstance()->apply($hook, $value, ...$args);
    }

    /**
     * Remove a filter hook.
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
     * Check if a filter hook exists.
     *
     * @param  string  $hook  Hook name.
     */
    public static function exists(string $hook): bool
    {
        return self::getInstance()->exists($hook);
    }

    /**
     * Get registered callbacks for a filter hook.
     *
     * @param  string  $hook  Hook name.
     * @return array|null Callbacks or null.
     */
    public static function callbacks(string $hook): ?array
    {
        return self::getInstance()->callbacks($hook);
    }

    private static function getInstance(): WordPressFilter
    {
        if (! self::$instance instanceof WordPressFilter) {
            if (class_exists(\Pollora\Support\Facades\Filter::class)) {
                trigger_error(
                    'Using Pollora\Hook\Filter directly is not recommended when the Pollora framework is available. '
                    .'Use the Pollora\Support\Facades\Filter facade instead for DI container support.',
                    E_USER_NOTICE
                );
            }

            self::$instance = new WordPressFilter;
        }

        return self::$instance;
    }
}
