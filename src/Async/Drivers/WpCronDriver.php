<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\Drivers;

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\Exceptions\AsyncException;
use Pollora\Hook\Async\UniqueLock;

/**
 * Queues through WP-Cron.
 *
 * WordPress keeps the arguments of every scheduled event in the 'cron' option,
 * which is loaded on every request. The payload is therefore stored apart, in
 * an option of its own that is not autoloaded, and the event carries only its
 * identifier. Every payload has a unique identifier, so WP-Cron never discards
 * an execution as a duplicate of another scheduled within ten minutes.
 *
 * A payload whose event was lost (the 'cron' option reset, an event deleted by
 * a plugin) is found by a daily recovery task and scheduled again; the same
 * task deletes expired unique locks. Only the
 * process that deletes a payload runs it, so scheduling it again never runs it
 * twice.
 */
final class WpCronDriver implements AsyncDriver
{
    public const string OPTION_PREFIX = 'pollora_async_';

    /**
     * Daily task that schedules again the payloads whose event was lost.
     */
    public const string RECOVERY_HOOK = 'pollora/async/recover';

    /**
     * Age, in seconds, from which a payload without an event counts as lost.
     */
    public const int RECOVERY_GRACE = 3600;

    /**
     * Payloads examined per recovery run.
     */
    public const int RECOVERY_BATCH = 500;

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

        self::scheduleRecovery();
    }

    /**
     * Schedule the daily recovery task, unless it already is.
     */
    public static function scheduleRecovery(): void
    {
        if (! function_exists('wp_next_scheduled') || wp_next_scheduled(self::RECOVERY_HOOK) !== false) {
            return;
        }

        wp_schedule_event(time() + self::RECOVERY_GRACE, 'daily', self::RECOVERY_HOOK);
    }

    /**
     * Schedule again the stored payloads whose event was lost.
     *
     * A payload counts as lost when no event carries its identifier and it was
     * dispatched more than RECOVERY_GRACE seconds ago. A stored value that is
     * not a readable payload is deleted.
     *
     * @return int Number of executions scheduled again
     */
    public static function recover(): int
    {
        global $wpdb;

        if (! is_object($wpdb)) {
            return 0;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
            $wpdb->esc_like(self::OPTION_PREFIX).'%',
            self::RECOVERY_BATCH,
        ), 'ARRAY_A');

        $threshold = time() - self::RECOVERY_GRACE;
        $recovered = 0;

        foreach ((array) $rows as $row) {
            $id = substr((string) $row['option_name'], strlen(self::OPTION_PREFIX));

            if (! self::handles($id) || wp_next_scheduled(Async::HOOK, [$id]) !== false) {
                continue;
            }

            try {
                $payload = AsyncPayload::fromJson((string) $row['option_value']);
                $dispatchedAt = (new \DateTimeImmutable($payload->origin['dispatchedAt']))->getTimestamp();
            } catch (\Throwable $throwable) {
                delete_option((string) $row['option_name']);
                Async::report(sprintf('WP-Cron: unreadable payload %s deleted: %s', $id, $throwable->getMessage()));

                continue;
            }

            if ($dispatchedAt > $threshold) {
                continue;
            }

            if (wp_schedule_single_event(time(), Async::HOOK, [$id], true) === true) {
                $recovered++;
            }
        }

        UniqueLock::prune();

        if ($recovered > 0) {
            Async::report(sprintf('WP-Cron: %d asynchronous execution(s) whose event was lost scheduled again.', $recovered));
        }

        return $recovered;
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
