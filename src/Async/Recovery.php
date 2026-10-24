<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

/**
 * The daily task that keeps stored payloads from being lost: it schedules again
 * the payloads whose event disappeared, and deletes expired unique locks.
 *
 * A payload kept apart (WP-Cron's always, Action Scheduler's when too long for
 * its arguments) is only reachable through its event. An event can vanish: the
 * 'cron' option reset, an event deleted by a plugin. Only the process that
 * deletes a payload runs it, so scheduling it again never runs it twice.
 */
final class Recovery
{
    /**
     * Daily task that schedules again the payloads whose event was lost.
     */
    public const string HOOK = 'pollora/async/recover';

    /**
     * Age, in seconds, from which a payload without an event counts as lost.
     */
    public const int GRACE = 3600;

    /**
     * Payloads examined per run.
     */
    public const int BATCH = 500;

    /**
     * Schedule the daily task, unless it already is.
     */
    public static function schedule(): void
    {
        if (! function_exists('wp_next_scheduled') || wp_next_scheduled(self::HOOK) !== false) {
            return;
        }

        wp_schedule_event(time() + self::GRACE, 'daily', self::HOOK);
    }

    /**
     * The action callback of the daily task: an action returns nothing, recover() returns its count.
     *
     * @internal
     */
    public static function run(): void
    {
        self::recover();
    }

    /**
     * Schedule again the stored payloads whose event was lost.
     *
     * A payload counts as lost when no event carries its identifier and it was
     * dispatched more than GRACE seconds ago. A stored value that is not a
     * readable payload is deleted.
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
            $wpdb->esc_like(PayloadStore::OPTION_PREFIX).'%',
            self::BATCH,
        ), 'ARRAY_A');

        $threshold = time() - self::GRACE;
        $recovered = 0;

        foreach ((array) $rows as $row) {
            $id = substr((string) $row['option_name'], strlen(PayloadStore::OPTION_PREFIX));

            if (! PayloadStore::handles($id) || self::isPending($id)) {
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
     * Whether an event still carries the identifier: a WP-Cron event, or an
     * Action Scheduler action, in any group, for a payload too long for its arguments.
     */
    private static function isPending(string $id): bool
    {
        if (wp_next_scheduled(Async::HOOK, [$id]) !== false) {
            return true;
        }

        return function_exists('as_has_scheduled_action') && as_has_scheduled_action(Async::HOOK, [$id]);
    }
}
