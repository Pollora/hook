<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

use Pollora\Hook\Async\Exceptions\AsyncException;

/**
 * Keeps payloads in options that are not autoloaded, for drivers that should
 * only carry an identifier: WP-Cron always (its events live in the autoloaded
 * 'cron' option), Action Scheduler for payloads too long for its arguments.
 *
 * Async::receive() takes an identifier back out with claim(): only the process
 * that deletes the option gets the payload, so it runs once.
 */
final class PayloadStore
{
    public const string OPTION_PREFIX = 'pollora_async_';

    private const string ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * @throws AsyncException When the payload cannot be stored
     */
    public static function store(AsyncPayload $payload): void
    {
        if (! add_option(self::OPTION_PREFIX.$payload->id, $payload->toJson(), '', false)) {
            throw new AsyncException(sprintf("The payload of '%s' could not be stored.", $payload->hook));
        }
    }

    /**
     * Delete a stored payload that will not be queued after all.
     */
    public static function forget(string $id): void
    {
        delete_option(self::OPTION_PREFIX.$id);
    }

    /**
     * Whether a driver message is the identifier of a stored payload.
     */
    public static function handles(string $message): bool
    {
        return preg_match(self::ID_PATTERN, $message) === 1;
    }

    /**
     * Take a stored payload out of the database, to run it.
     *
     * @return string|null The payload as JSON, or null when it was already taken or never existed
     */
    public static function claim(string $id): ?string
    {
        $option = self::OPTION_PREFIX.$id;
        $payload = get_option($option, null);

        if (! is_string($payload) || ! delete_option($option)) {
            return null;
        }

        return $payload;
    }
}
