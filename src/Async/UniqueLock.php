<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

use Pollora\Hook\Async\Drivers\WpCronDriver;
use Pollora\Hook\Async\Exceptions\AsyncException;

/**
 * Time-limited lock that merges identical triggers of a unique registration.
 *
 * The lock is an option that is not autoloaded, created with add_option(): the
 * unique key on option_name makes acquiring it atomic. Its value is the time it
 * expires. It is released when the first attempt starts, and expired locks are
 * deleted by the daily maintenance task.
 *
 * @internal
 */
final class UniqueLock
{
    public const string OPTION_PREFIX = 'pollora_unique_';

    /**
     * Identify a trigger: same hook, same handler, same arguments.
     *
     * @param  array<int|string, mixed>  $arguments  Normalized arguments
     */
    public static function key(string $hook, string $handler, array $arguments): string
    {
        return sha1((string) json_encode([$hook, $handler, $arguments]));
    }

    /**
     * Take the lock, unless an identical trigger holds it.
     *
     * @param  int  $seconds  How long the lock lasts when it is never released
     * @return bool False when an identical trigger already holds it
     *
     * @throws AsyncException When the lock cannot be stored
     */
    public static function acquire(string $key, int $seconds): bool
    {
        $option = self::OPTION_PREFIX.$key;
        $expiresAt = time() + $seconds;

        WpCronDriver::scheduleRecovery();

        if (add_option($option, $expiresAt, '', false)) {
            return true;
        }

        wp_cache_delete($option, 'options');
        $current = get_option($option, null);

        if ($current === null) {
            throw new AsyncException('The unique lock of an asynchronous action could not be stored.');
        }

        if ((int) $current >= time()) {
            return false;
        }

        // Expired: take it over. Of two processes doing so, add_option() lets one win.
        delete_option($option);

        return add_option($option, $expiresAt, '', false);
    }

    public static function release(string $key): void
    {
        delete_option(self::OPTION_PREFIX.$key);
    }

    /**
     * Delete expired locks.
     *
     * @return int Number of locks deleted
     */
    public static function prune(int $limit = 500): int
    {
        global $wpdb;

        if (! is_object($wpdb)) {
            return 0;
        }

        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d",
            $wpdb->esc_like(self::OPTION_PREFIX).'%',
            time(),
            $limit,
        ));

        $deleted = 0;
        foreach ((array) $names as $name) {
            $deleted += delete_option((string) $name) ? 1 : 0;
        }

        return $deleted;
    }
}
