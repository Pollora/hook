<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\Drivers;

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\Exceptions\AsyncException;

/**
 * Queues through WP-Cron.
 *
 * WordPress keeps the arguments of every scheduled event in the 'cron' option,
 * which is loaded on every request. The payload is therefore stored apart, in
 * an option of its own that is not autoloaded, and the event carries only its
 * identifier. Every payload has a unique identifier, so WP-Cron never discards
 * an execution as a duplicate of another scheduled within ten minutes.
 */
final class WpCronDriver implements AsyncDriver
{
    public const string OPTION_PREFIX = 'pollora_async_';

    private const string ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function available(): bool
    {
        return function_exists('wp_schedule_single_event') && function_exists('add_option');
    }

    /**
     * @throws AsyncException When the payload cannot be stored or the event scheduled
     */
    public function dispatch(AsyncPayload $payload, int $delay = 0): void
    {
        $option = self::OPTION_PREFIX.$payload->id;

        if (! add_option($option, $payload->toJson(), '', false)) {
            throw new AsyncException(sprintf("WP-Cron: the payload of '%s' could not be stored.", $payload->hook));
        }

        $scheduled = wp_schedule_single_event(time() + max(0, $delay), Async::HOOK, [$payload->id], true);

        if ($scheduled !== true) {
            delete_option($option);

            $reason = is_object($scheduled) && method_exists($scheduled, 'get_error_message') ? ': '.$scheduled->get_error_message() : '.';

            throw new AsyncException(sprintf("WP-Cron: the execution of '%s' could not be scheduled%s", $payload->hook, $reason));
        }
    }

    /**
     * Whether a driver message is the identifier of a payload stored by this driver.
     */
    public static function handles(string $message): bool
    {
        return preg_match(self::ID_PATTERN, $message) === 1;
    }

    /**
     * Take a stored payload out of the database, to run it.
     *
     * Only the process that deletes the option gets the payload, so two cron
     * runs that pick the same event do not both execute it.
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
