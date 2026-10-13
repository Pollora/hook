<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\Drivers;

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\Exceptions\AsyncException;
use Pollora\Hook\Async\PayloadStore;
use Pollora\Hook\Async\Recovery;
use Pollora\Schedule\Contracts\DefersUntilReady;
use Pollora\Schedule\Contracts\Enqueues;
use Pollora\Schedule\Contracts\ScheduleDriver;
use Pollora\Schedule\Drivers\ActionSchedulerDriver;
use Pollora\Schedule\Drivers\WpCronDriver;

/**
 * Queues through a driver of pollora/schedule: WP-Cron or Action Scheduler.
 *
 * pollora/schedule keeps and runs the event; this class keeps what is the
 * hook's own: the message the event carries, and where the payload lives.
 *
 * WP-Cron keeps the arguments of every event in the autoloaded 'cron' option,
 * so its payload is stored apart, in an option that is not autoloaded, and the
 * event carries only its identifier; every payload has one of its own, so
 * WP-Cron never discards an execution as a duplicate of another within ten
 * minutes. Action Scheduler carries the payload whole as the action argument,
 * and the queue name is the group; a payload too long for its arguments (8,000
 * characters as JSON) is kept apart like WP-Cron's. Action Scheduler's actions
 * live in their own groups, which the 'cron' index of pollora/schedule never follows.
 */
final class ScheduledDriver implements AsyncDriver
{
    public const string DEFAULT_GROUP = 'pollora';

    /**
     * Longest JSON-encoded argument list carried whole, under Action Scheduler's 8,000.
     */
    public const int MAX_ARGUMENTS_LENGTH = 7900;

    /**
     * @param  \Closure(?string): ScheduleDriver  $driverFor  The driver of pollora/schedule for a queue name
     */
    private function __construct(
        private readonly string $label,
        private readonly \Closure $driverFor,
        private readonly bool $alwaysStoredApart,
    ) {}

    public static function wpCron(): self
    {
        $driver = new WpCronDriver;

        return new self('WP-Cron', static fn (?string $queue): ScheduleDriver => $driver, true);
    }

    public static function actionScheduler(): self
    {
        $drivers = [];

        return new self('Action Scheduler', static function (?string $queue) use (&$drivers): ScheduleDriver {
            $group = $queue ?? self::DEFAULT_GROUP;

            return $drivers[$group] ??= new ActionSchedulerDriver($group);
        }, false);
    }

    /**
     * Whether the driver can store now: Action Scheduler, only once it is initialised.
     */
    public function available(): bool
    {
        $driver = ($this->driverFor)(null);

        return $driver->available() && (! $driver instanceof DefersUntilReady || $driver->ready());
    }

    /**
     * @throws AsyncException When the payload cannot be stored or the event queued
     */
    public function dispatch(AsyncPayload $payload, int $delay = 0): void
    {
        $arguments = [$payload->toJson()];
        $storedApart = $this->alwaysStoredApart || strlen((string) json_encode($arguments)) > self::MAX_ARGUMENTS_LENGTH;

        if ($storedApart) {
            try {
                PayloadStore::store($payload);
            } catch (AsyncException $asyncException) {
                throw new AsyncException($this->label.': '.lcfirst($asyncException->getMessage()), 0, $asyncException);
            }

            $arguments = [$payload->id];
        }

        $driver = ($this->driverFor)($payload->queue);
        $timestamp = time() + max(0, $delay);

        try {
            if ($driver instanceof Enqueues) {
                $driver->enqueue(Async::HOOK, $timestamp, $arguments);
                $queued = true;
            } else {
                $queued = $driver->once(Async::HOOK, $timestamp, $arguments);
            }
        } catch (\Throwable $throwable) {
            $queued = false;
            $reason = $throwable->getMessage();
        }

        if (! $queued) {
            if ($storedApart) {
                PayloadStore::forget($payload->id);
            }

            throw new AsyncException(sprintf("%s: the execution of '%s' could not be queued%s", $this->label, $payload->hook, isset($reason) ? ': '.$reason : '.'));
        }

        if ($storedApart) {
            Recovery::schedule();
        }
    }
}
